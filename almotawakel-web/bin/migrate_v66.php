<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v66 — خزنة الشركة (الخزنة الأكبر اللي بتغذّي خزنة المكتب).
 *
 * ═══ اللزمة (طلب المالك 2026-08-08) ═══
 * كان النظام كله بيعرف خزنة واحدة: «خزنة المكتب» (`Closing::currentBalance`).
 * المالك عايز مستوى أعلى منها — **خزنة الشركة**: رصيدها منفصل، بتغذّي خزنة
 * المكتب، وبتسحب منها كمان لما يفيض فيها كاش.
 *
 * ═══ ⚠️⚠️ العزل بالبناء — أهم قرار في الوحدة، لا تعكسه ═══
 * الجدول ده **كل كود الخزنة عمره ما سمع عنه** (نفس منطق `rep_misc_expenses`
 * v39 و`rep_driver_handovers` v45 و`car_expenses` v54 و`permit_track_events`
 * v62). يعني **مفيش ولا نقطة مزامنة جديدة** لازم نفتكرها في ٨ أماكن — ونسيان
 * واحدة كان بيبوّظ الرصيد.
 *
 * وإزاي بقى بيغذّي خزنة المكتب من غير ما يمسّها؟ **بصف `manual_custody`**:
 *   • تحويل للمكتب  (`to_office`)   ⇒ صف `manual_custody` بمبلغ **موجب**.
 *   • سحب من المكتب (`from_office`) ⇒ صف `manual_custody` بمبلغ **سالب**.
 * وده بالظبط نمط `ClientPayment::route='cash'` الموثَّق في v23 (بتنشئ عهدة
 * يدوية والعمود `custody_id` بيربطها بالدفعة). النتيجة إن `Closing` و
 * `DailyReview` و`Metrics` و`backfill_closings` كلهم بيشوفوا **صف عهدة عادي**
 * زي أي إضافة يدوية، بلا سطر واحد جديد فيهم.
 *
 * ═══ ⚠️ قرارات المالك المحسومة (سؤال مباشر 2026-08-08) ═══
 *  • **«تغذّي المكتب وبس»** — مفيش مصروفات بتتصرف من خزنة الشركة مباشرة.
 *    عشان كده مفيش نوع `expense` هنا؛ لو اتضاف بعدين لازم يبقى قرار صريح.
 *  • **مصادر الدخل**: سحب من البنك/إيداع كاش · تحصيل من العملاء · سحب من
 *    خزنة المكتب.
 *  • **رصيد جاري مستمر بلا تقفيل يومي** — زي كروت الفيزا (v22) بالظبط.
 *    عشان كده مفيش جدول إقفال ولا ترحيل ولا عمود `closing_balance`.
 *
 * ═══ ⚠️ قرارات في بنية الجدول ═══
 *  • **كل المبالغ مخزَّنة موجبة** والاتجاه من `kind` — السالب في عمود المبلغ
 *    كان بيخلّي أي `SUM(amount)` تدّي رقمًا مالوش معنى.
 *  • `custody_id` بيربط الصف بصف `manual_custody` المُولَّد (للتحويلات بس)
 *    عشان الحذف يشيل الاتنين مع بعض ومايسيبش صفًّا يتيم في الخزنة.
 *  • **بلا FK** (تحذير ٣) — حذف صف عهدة من مكان تاني ما يكسرش السجل.
 *  • `kind` VARCHAR مش ENUM: إضافة نوع بعدين ما تحتاجش `ALTER` على جدول حيّ.
 *
 * ⚠️ `verify()` بتثبت **حالة بنيوية دائمة** (الجدول والأعمدة والعمود الرابط
 *    والفهارس) — **مش لقطة بيانات لحظية**. الدرس من v61: `verify()` بتاعتها
 *    اشترطت «كل التنزيلات لسه bank» فبقت مستحيلة تعدّي بعد أول استخدام حقيقي.
 *
 * التشغيل:  php bin/migrate_v66.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\CompanySafe;
use App\Models\Custody;

$v66Dry    = in_array('--dry-run', $argv ?? [], true);
$v66Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v66Say = static function (string $m) use ($v66Direct): void { if ($v66Direct) { echo $m . "\n"; } };

$v66HasTable = static function (string $t): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
};
$v66HasCol = static function (string $t, string $c): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
};

$v66Up = static function () use ($v66Dry, $v66Say, $v66HasTable, $v66HasCol): void {
    /* لقطة «ممنوع يتغيّر»: رصيد خزنة المكتب + كل صفوف الإقفال. الترحيل ده
       بيضيف جدولًا فاضيًا وعمودًا فاضيًا، فأي فرق هنا معناه إن حاجة اتلمست. */
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
        'topups'   => round((float) Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM manual_custody'), 2),
    ];

    if ($v66HasTable('company_safe_entries')) {
        $v66Say('  • company_safe_entries موجود — اتخطّى (idempotent).');
    } elseif ($v66Dry) {
        $v66Say('  • (تجربة) هيتعمل جدول company_safe_entries.');
    } else {
        Database::exec(
            "CREATE TABLE company_safe_entries (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                entry_date  DATE          NOT NULL COMMENT 'يوم الحركة (يوم العمل)',
                kind        VARCHAR(16)   NOT NULL COMMENT 'deposit|from_client|from_office|to_office',
                amount      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'موجب دايمًا — الاتجاه من kind',
                source      VARCHAR(180)  NULL COMMENT 'البنك / اسم العميل / وصف الجهة',
                note        VARCHAR(255)  NULL,
                custody_id  INT UNSIGNED  NULL COMMENT 'صف manual_custody المُولَّد (للتحويلات فقط)',
                created_by  VARCHAR(120)  NULL,
                created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_cse_date (entry_date),
                KEY idx_cse_kind (kind, entry_date),
                KEY idx_cse_custody (custody_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $v66Say('  ✓ اتعمل جدول company_safe_entries');
    }

    /* الربط العكسي على `client_payments` — نفس نمط `custody_id`/`deposit_id`
       بتوع v23 بالحرف، عشان `ClientPayment::reverse` تلاقي الصف وتشيله. */
    if ($v66HasCol('client_payments', 'company_entry_id')) {
        $v66Say('  • client_payments.company_entry_id موجود — اتخطّى.');
    } elseif ($v66Dry) {
        $v66Say('  • (تجربة) هيتضاف عمود client_payments.company_entry_id.');
    } else {
        Database::exec(
            "ALTER TABLE client_payments
               ADD COLUMN company_entry_id INT UNSIGNED NULL
                   COMMENT 'صف company_safe_entries المُولَّد لو route=company'
                   AFTER visa_card_id");
        $v66Say('  ✓ اتضاف عمود client_payments.company_entry_id');
    }

    $after = [
        'safe'     => round((float) Custody::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
        'topups'   => round((float) Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM manual_custody'), 2),
    ];
    if ($before !== $after) {
        throw new RuntimeException('⚠️ الترحيل غيّر رصيدًا أو صف إقفال — ده ترحيل بنيوي فاضي، راجع فورًا.');
    }
    $v66Say('  ✓ رصيد خزنة المكتب وصفوف الإقفال زي ما هُمّ');

    /* ⚠️ مصيدة كاش المخطط (v57): `CompanySafe::ready()` بتكاش نتيجتها لكل
       الطلب، وممكن تكون اتقرت قبل الـCREATE (اللقطة فوق بتنادي `Custody::balance`
       اللي بيمرّ على كود بيسأل عن الجاهزية). لازم تتصفّر هنا وإلا التحقّق يفشل
       والترحيل ما يتسجّلش رغم إنه اتطبّق فعلاً. */
    if (!$v66Dry) { CompanySafe::refreshReady(); }
};

$v66Verify = static function () use ($v66Say): bool {
    $cols = [];
    foreach (Database::all(
        "SELECT column_name cn, is_nullable nl FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'company_safe_entries'") as $r) {
        $cols[(string) $r['cn']] = (string) $r['nl'];
    }
    if (!$cols) { $v66Say('✗ جدول company_safe_entries مش موجود.'); return false; }

    foreach (['id','entry_date','kind','amount','source','note','custody_id','created_by'] as $need) {
        if (!isset($cols[$need])) { $v66Say("✗ العمود {$need} ناقص."); return false; }
    }
    $v66Say('  ✓ كل أعمدة company_safe_entries موجودة');

    /* التاريخ والنوع **إلزاميان**: صف بلا تاريخ مالوش يوم يتحسب عليه، وصف بلا
       نوع مالوش اتجاه — الاتنين بيخلّوا الرصيد يقرا غلط بلا أي خطأ ظاهر. */
    if ($cols['entry_date'] !== 'NO' || $cols['kind'] !== 'NO') {
        $v66Say('✗ entry_date/kind لازم يكونوا NOT NULL.');
        return false;
    }
    $v66Say('  ✓ entry_date و kind إلزاميان');

    if (!Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        ['client_payments', 'company_entry_id'])) {
        $v66Say('✗ العمود client_payments.company_entry_id ناقص.');
        return false;
    }
    $v66Say('  ✓ client_payments.company_entry_id موجود (ربط التحصيل)');

    $idx = array_column(Database::all(
        "SELECT DISTINCT index_name n FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'company_safe_entries'"), 'n');
    foreach (['idx_cse_date', 'idx_cse_kind', 'idx_cse_custody'] as $need) {
        if (!in_array($need, $idx, true)) { $v66Say("✗ الفهرس {$need} ناقص."); return false; }
    }
    $v66Say('  ✓ الفهارس التلاتة موجودة');

    /* ⚠️⚠️ حارس العزل — أهم فحص هنا. أول ما يظهر صف بنوع مش في القايمة، يبقى
       فيه كود بيكتب اتجاهًا الرصيد مش عارف يحسبه، والرصيد يقرا غلط بالسكوت. */
    $bad = Database::all(
        'SELECT DISTINCT kind FROM company_safe_entries WHERE kind NOT IN (?,?,?,?)',
        CompanySafe::KINDS);
    if ($bad) {
        $v66Say('✗ نوع حركة مجهول في الجدول: ' . implode(', ', array_column($bad, 'kind')));
        return false;
    }
    $v66Say('  ✓ ولا نوع حركة خارج القايمة المعتمدة');

    if (!CompanySafe::refreshReady()) {
        $v66Say('✗ CompanySafe::ready لسه بترجّع false بعد الترحيل (كاش ساكن؟).');
        return false;
    }
    $v66Say('  ✓ CompanySafe::ready فعّالة');
    return true;
};

if ($v66Direct) {
    echo 'v66 — خزنة الشركة' . ($v66Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v66Up();
    if ($v66Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v66Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v66.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v66Up, 'verify' => $v66Verify];
