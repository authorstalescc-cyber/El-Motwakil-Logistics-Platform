<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v56 — مستندات الأطراف (البطاقة الضريبية / السجل التجاري / …).
 *
 * المكتب بيرفع أوراق **الشركة** (أو الوكيل أو صاحب البيان) مرّة واحدة في
 * بروفايله، وبعدين بتظهر **تلقائيًّا**:
 *   • في شاشة أي إذن لصالح الشركة دي (لوح «مستندات الأطراف»)،
 *   • وفي موبايل المندوب على نفس الإذن — يفتحها أو يحمّلها أو يطبعها.
 *
 * الجدول: `party_documents` (صف لكل مستند مرفوع).
 *
 * ══════════════════════════════════════════════════════════════════════════
 * ⚠️ وحدة وصفية معزولة تمامًا — **مفيش أي عمود مالي ولا أي أثر على حساب**.
 * ══════════════════════════════════════════════════════════════════════════
 * نفس منطق عزل `dashboard_notes` (v19) و`shipment_tracks` (v33): الجدول ده
 * **كل كود الخزنة والتقفيل والعهدة عمره ما سمع عنه**، فمفيش «استثناء» لازم
 * نفتكره في ٨ أماكن. `bin/verify_v56.php` بيفشل لو أي ملف خزنة ذكر الجدول.
 *
 * ⚠️ **الربط بالإذن نصّي بلا FK (تحذير ٣).** المستند بيتعلّق على سجل الطرف
 *    في `companies`/`agents`/`declaration_owners`، والإذن بيوصل له عن طريق
 *    `PartyMaster::resolve()` على `permits.company` و`permits.client` — يعني
 *    التهجئات المختلفة بتلمّ على نفس السجل، وحذف أي طرف ما بيكسرش الإذن.
 *
 * ⚠️ **`rep_visible` هو الحدّ الوحيد اللي بيمنع مستندًا يوصل موبايل المندوب.**
 *    الافتراضي 1 (ظاهر) لأن ده الغرض من الميزة أصلاً، والمكتب بيقفلها على
 *    المستند الحسّاس. الفحص بيتحقّق إن `FileController` بيحترمها فعليًّا.
 *
 * الملف نفسه بيتخزَّن في `storage/private_uploads/party_docs/` **برّه متناول
 * الإنترنت**، وبيتقدّم عبر `FileController` بعد فحص الجلسة — نفس مسار مرفقات
 * الأذونات وصور إيصالات المناديب.
 *
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v56.php        (أو تلقائيًّا من bin/migration.php)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

$v56BalanceBefore = Closing::currentBalance();

$v56Say = static function (string $line): void { echo $line . PHP_EOL; };

$v56TableExists = static fn(string $t): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?", [$t]);

$v56Up = static function () use ($v56Say, $v56TableExists): void {
    if ($v56TableExists('party_documents')) {
        $v56Say('  • party_documents موجود — تخطّي.');
        return;
    }

    Database::exec(
        "CREATE TABLE party_documents (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            party_type  VARCHAR(20)  NOT NULL,                 -- company | agent | owner
            party_id    INT UNSIGNED NOT NULL,                 -- id في جدول الطرف (بلا FK — تحذير ٣)
            doc_kind    VARCHAR(40)  NOT NULL DEFAULT 'other', -- tax_card | commercial_reg | …
            title       VARCHAR(180) NOT NULL,                 -- اسم المستند كما يظهر
            doc_no      VARCHAR(120) NULL,                     -- رقم البطاقة/السجل
            issue_date  DATE         NULL,
            expires_at  DATE         NULL,                     -- تاريخ الانتهاء (شارة تحذير فقط)
            file_path   VARCHAR(255) NOT NULL,                 -- private/party_docs/{random.ext}
            file_ext    VARCHAR(10)  NOT NULL DEFAULT '',
            rep_visible TINYINT(1)   NOT NULL DEFAULT 1,       -- يظهر لموبايل المندوب؟
            note        VARCHAR(255) NULL,
            uploaded_by VARCHAR(120) NULL,
            created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_party (party_type, party_id),
            KEY idx_expires (expires_at),
            KEY idx_kind (doc_kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $v56Say('  ✓ اتعمل جدول party_documents.');
};

$v56Verify = static function () use ($v56Say, $v56TableExists, $v56BalanceBefore): bool {
    $ok = true;
    $fail = static function (string $m) use (&$ok, $v56Say): void { $ok = false; $v56Say('  ✗ ' . $m); };

    if (!$v56TableExists('party_documents')) {
        $fail('جدول party_documents مش موجود بعد التطبيق.');
        return false;
    }

    // ── الأعمدة المطلوبة موجودة
    $cols = array_column(Database::all(
        "SELECT column_name AS c FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'party_documents'"), 'c');
    $need = ['party_type', 'party_id', 'doc_kind', 'title', 'file_path', 'rep_visible', 'expires_at'];
    foreach ($need as $c) {
        if (!in_array($c, $cols, true)) { $fail("العمود {$c} ناقص في party_documents."); }
    }

    // ── ⚠️ صفر عمود مالي — الوحدة وصفية بحتة
    foreach (['amount', 'balance', 'custody', 'freight', 'total', 'paid', 'price'] as $money) {
        foreach ($cols as $c) {
            if (str_contains(strtolower($c), $money)) {
                $fail("العمود {$c} بيشبه عمودًا ماليًّا — الوحدة دي وصفية بحتة.");
            }
        }
    }

    // ── ⚠️ العزل بالبناء: ولا ملف خزنة يعرف الجدول
    $guarded = [
        'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/DailyReview.php',
        'app/Models/Metrics.php', 'app/Models/Expense.php',
        'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php',
    ];
    foreach ($guarded as $rel) {
        $p = dirname(__DIR__) . '/' . $rel;
        if (is_file($p) && str_contains((string) file_get_contents($p), 'party_documents')) {
            $fail("ملف الخزنة {$rel} بيذكر party_documents — العزل بالبناء اتكسر.");
        }
    }

    // ── ⚠️ صفر أثر مالي
    $after = Closing::currentBalance();
    if (abs($after - $v56BalanceBefore) > 0.005) {
        $fail('رصيد الخزنة اتغيّر (' . number_format($v56BalanceBefore, 2)
            . ' ← ' . number_format($after, 2) . ') — الترحيل ده ممنوع يكون له أثر مالي.');
    }

    if ($ok) {
        $n = (int) Database::scalar("SELECT COUNT(*) FROM party_documents");
        $v56Say('  ✓ التحقق بعد التطبيق عدّى (' . $n . ' مستند في السجل).');
    }
    return $ok;
};

// ⚠️ مصيدة v49 الموثَّقة: الملف بيتنفّذ بطريقتين ولازم يشتغل صح في الاتنين.
$v56Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
if ($v56Direct) {
    echo "v56 — مستندات الأطراف (البطاقة الضريبية / السجل التجاري)\n" . str_repeat('=', 60) . "\n";
    $v56Up();
    if ($v56Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v56.\n");
        exit(1);
    }
    echo "تمام.\n";
}

return ['up' => $v56Up, 'verify' => $v56Verify];
