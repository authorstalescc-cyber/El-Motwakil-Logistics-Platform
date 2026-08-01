<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v62 — رحلة الشحنة: تايم لاين حالة الإذن التشغيلية.
 *
 * ═══ اللي الجدول ده بيحلّه ═══
 * الإذن فيه تايم لاين مالي (المراحل والإيصالات والبوالص)، لكن **مفيش أي مكان
 * يقول الشحنة واصلة فين دلوقتي**. المالك بيسأل «العربية وصلت العميل؟» فيتصل
 * بالمندوب. الجدول ده بيسجّل المرحلة التشغيلية بوقتها ومين سجّلها.
 *
 * ═══ ⚠️⚠️ العزل بالبناء — ولا عمود مالي واحد ═══
 * نفس منطق `dashboard_notes` (v19) و`shipment_tracks` (v33) و`car_expenses`
 * (v54): جدول خاص **كل كود الخزنة عمره ما سمع عنه**، فمفيش «استثناء» لازم
 * نفتكره في ٨ أماكن ونسيان واحد يبوّظ الرصيد. الترحيل بيتحقق إن رصيد الخزنة
 * ما اتغيّرش، و`bin/verify_v62.php` بيفشل لو أي ملف خزنة ذكر الجدول.
 *
 * ═══ ⚠️ قرارات في بنية الجدول ═══
 *  • **`bill_id NOT NULL DEFAULT 0`** لا `NULL`: القيمة 0 معناها «الإذن كله».
 *    لو كانت NULL، المفتاح الفريد `(permit_id, bill_id, stage_key)` **ما كانش
 *    هيشتغل** — MySQL بتعتبر NULL ≠ NULL فتكرار نفس المرحلة على الإذن كان
 *    هيدخل مرات لا نهائية بلا أي خطأ.
 *  • **بلا FK** (تحذير 3): حذف بوليصة أو إذن ما يكسرش السجل، والتنضيف بيحصل
 *    من `DeletionPolicy` لو اتقرّر لاحقًا.
 *  • `happened_at DATETIME` بتتكتب بـ`NOW()` (ساعة القاعدة — قاعدة v48)،
 *    و`work_date DATE` هو **يوم حصول المرحلة** وقابل للتعديل (قاعدة v29).
 *
 * التشغيل:  php bin/migrate_v62.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\PermitTrack;

$v62Dry    = in_array('--dry-run', $argv ?? [], true);
$v62Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v62Say = static function (string $m) use ($v62Direct): void { if ($v62Direct) { echo $m . "\n"; } };

$v62HasTable = static function (string $t): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
};

$v62Up = static function () use ($v62Dry, $v62Say, $v62HasTable): void {
    /* لقطة «ممنوع يتغيّر»: رصيد الخزنة + كل صفوف الإقفال. الوحدة وصفية بحتة
       فأي فرق هنا معناه إن حاجة اتلمست بالغلط. */
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];

    if ($v62HasTable('permit_track_events')) {
        $v62Say('  • permit_track_events موجود بالفعل — مفيش تغيير (idempotent).');
    } elseif ($v62Dry) {
        $v62Say('  • [تجربة] هيتعمل جدول permit_track_events.');
    } else {
        /* ⚠️ DDL برّه أي transaction — `CREATE TABLE` بيعمل commit ضمني في
           MySQL ومستحيل يترجع (مصيدة v59 الموثَّقة). والتعديل **إضافي بحت**
           (جدول جديد فاضي) فمفيش فقد بيانات نحتاج نرجع منه. */
        Database::exec(
            "CREATE TABLE permit_track_events (
               id          INT AUTO_INCREMENT PRIMARY KEY,
               permit_id   INT          NOT NULL,
               bill_id     INT          NOT NULL DEFAULT 0,
               stage_key   VARCHAR(40)  NOT NULL,
               happened_at DATETIME     NOT NULL,
               work_date   DATE         NULL,
               note        VARCHAR(255) NULL,
               source      VARCHAR(10)  NOT NULL DEFAULT 'office',
               actor_name  VARCHAR(120) NULL,
               rep_id      INT          NULL,
               created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
               UNIQUE KEY uq_track_stage (permit_id, bill_id, stage_key),
               KEY idx_track_permit (permit_id, happened_at),
               KEY idx_track_day (work_date)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $v62Say('  ✓ اتعمل جدول permit_track_events');
    }

    /* ⚠️ تصفير كاش المخطط: `PermitTrack::ready()` بتكاش نتيجتها لكل الطلب،
       والقراءة اللي فوق (رصيد الخزنة) ما بتلمسهاش — لكن أي قارئ في نفس
       العملية ممكن يكون كاش false قبل الـCREATE فيفضل عليه (مصيدة v57). */
    PermitTrack::refreshReady();

    $after = [
        'safe'     => round((float) Custody::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];
    if ($before['safe'] !== $after['safe']) {
        throw new RuntimeException("رصيد الخزنة اتغيّر ({$before['safe']} ← {$after['safe']}) — الترحيل ده وصفي وممنوع يمسّ أي رقم.");
    }
    if ($before['closings'] !== $after['closings']) {
        throw new RuntimeException('صفوف الإقفال اتغيّرت — الترحيل ده وصفي وممنوع يمسّ أي رقم.');
    }
    $v62Say('  ✓ رصيد الخزنة وكل صفوف الإقفال ما اتغيّروش');
};

$v62Verify = static function () use ($v62Say, $v62HasTable): bool {
    if (!$v62HasTable('permit_track_events')) {
        $v62Say('✗ جدول permit_track_events مش موجود.');
        return false;
    }

    // الأعمدة المطلوبة كلها موجودة
    $cols = [];
    foreach (Database::all(
        "SELECT column_name cn, is_nullable nu, column_default df
           FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'permit_track_events'") as $r) {
        $cols[(string) $r['cn']] = $r;
    }
    foreach (['id','permit_id','bill_id','stage_key','happened_at','work_date','note','source','actor_name','rep_id','created_at'] as $c) {
        if (!isset($cols[$c])) { $v62Say("✗ العمود {$c} ناقص."); return false; }
    }
    $v62Say('  ✓ كل الأعمدة موجودة (' . count($cols) . ' عمود)');

    /* ⚠️ أهم فحص في الترحيل: `bill_id` لازم يكون NOT NULL بافتراضي 0 — لو رجع
       NULL، المفتاح الفريد بيبطل يمنع التكرار **بلا أي خطأ ظاهر**. */
    if (strtoupper((string) $cols['bill_id']['nu']) !== 'NO' || (string) $cols['bill_id']['df'] !== '0') {
        $v62Say('✗ bill_id لازم يكون NOT NULL DEFAULT 0 (وإلا المفتاح الفريد بيبطل يمنع التكرار).');
        return false;
    }
    $v62Say('  ✓ bill_id = NOT NULL DEFAULT 0 (المفتاح الفريد شغّال)');

    if (!Database::scalar(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'permit_track_events'
            AND index_name = 'uq_track_stage'")) {
        $v62Say('✗ المفتاح الفريد uq_track_stage مش موجود.');
        return false;
    }
    $v62Say('  ✓ المفتاح الفريد uq_track_stage موجود');

    /* ⚠️ العزل: ولا عمود مالي في الجدول. الوحدة وصفية بحتة، وأي عمود مبلغ
       هنا معناه إن حد بدأ يبني حساب فوقها. */
    foreach (array_keys($cols) as $c) {
        foreach (['amount','custody','freight','debt','paid','price','total','balance','visa'] as $bad) {
            if (str_contains($c, $bad)) { $v62Say("✗ عمود مالي ({$c}) في جدول وصفي."); return false; }
        }
    }
    $v62Say('  ✓ ولا عمود مالي في الجدول (العزل بالبناء)');

    if (!PermitTrack::refreshReady()) {
        $v62Say('✗ PermitTrack::ready لسه بترجّع false بعد الترحيل (كاش ساكن؟).');
        return false;
    }
    $v62Say('  ✓ PermitTrack::ready فعّالة');
    return true;
};

if ($v62Direct) {
    echo 'v62 — رحلة الشحنة (تايم لاين حالة الإذن)' . ($v62Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v62Up();
    if ($v62Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v62.\n"); exit(1); }
    echo ($v62Dry ? "\nشغّل من غير --dry-run عشان يتطبّق.\n" : "\n✓ تمّ.\n");
}

return ['up' => $v62Up, 'verify' => $v62Verify];
