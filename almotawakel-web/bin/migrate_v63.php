<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v63 — تثبيت الأذونات على شاشة المندوب.
 *
 * ═══ اللي الجدول ده بيحلّه ═══
 * من v43 كل الأذونات المفتوحة بتظهر لكل المناديب في قائمة واحدة مرتّبة
 * بالتاريخ (٣٧ إذن على الإنتاج). المندوب بيشتغل على تلاتة أو أربعة منهم في
 * اليوم، وبيدوّر عليهم في القايمة كل مرة. الجدول ده بيخلّيه **يثبّت** اللي
 * شغّال عليه فيطلعوا فوق.
 *
 * ═══ ⚠️⚠️ العزل بالبناء — ولا عمود مالي واحد ═══
 * نفس منطق `dashboard_notes` (v19) و`shipment_tracks` (v33) و`car_expenses`
 * (v54) و`permit_track_events` (v62): جدول خاص **كل كود الخزنة عمره ما سمع
 * عنه**، فمفيش «استثناء» لازم نفتكره في ٨ أماكن ونسيان واحد يبوّظ الرصيد.
 * التثبيت **تفضيل عرض بحت** — ما بيغيّرش مين يشوف إيه ولا مين يكتب على إيه
 * (الحدّ الوحيد لسه `permits.status='open'` في `assertAssigned`).
 *
 * ═══ ⚠️ قرارات في بنية الجدول ═══
 *  • **المفتاح الأساسي `(rep_id, permit_id)`** — التثبيت علاقة واحدة لا أكتر،
 *    والمفتاح المركّب بيمنع التكرار من غير عمود `id` ولا فهرس زيادة.
 *  • **بلا FK** (تحذير ٣): حذف إذن أو مندوب ما يكسرش السجل، والصف اليتيم
 *    بيتخطّى تلقائيًّا لأن القراءة بتعمل JOIN على الأذونات المفتوحة.
 *  • `pinned_at` بـ`CURRENT_TIMESTAMP` (ساعة القاعدة — قاعدة v48) عشان
 *    الترتيب يبقى «آخر واحد ثبّته فوق».
 *
 * ⚠️ `verify()` بتثبت **حالة بنيوية دائمة** (الجدول موجود · الأعمدة صح ·
 *    المفتاح المركّب شغّال · ولا عمود مالي) — **مش لقطة بيانات لحظية**.
 *    الدرس ده جاي من v61: `verify()` بتاعتها كانت بتشترط «كل التنزيلات لسه
 *    bank» فبقت مستحيلة تعدّي بعد أول استخدام حقيقي، والترحيل فضل غير قابل
 *    لإعادة التحقّق للأبد.
 *
 * التشغيل:  php bin/migrate_v63.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\RepPin;

$v63Dry    = in_array('--dry-run', $argv ?? [], true);
$v63Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v63Say = static function (string $m) use ($v63Direct): void { if ($v63Direct) { echo $m . "\n"; } };

$v63HasTable = static function (string $t): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
};

$v63Up = static function () use ($v63Dry, $v63Say, $v63HasTable): void {
    /* لقطة «ممنوع يتغيّر»: رصيد الخزنة + كل صفوف الإقفال. الوحدة وصفية بحتة
       فأي فرق هنا معناه إن حاجة اتلمست بالغلط. */
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];

    if ($v63HasTable('rep_permit_pins')) {
        $v63Say('  • rep_permit_pins موجود — اتخطّى (idempotent).');
    } elseif ($v63Dry) {
        $v63Say('  • (تجربة) هيتعمل جدول rep_permit_pins.');
    } else {
        Database::exec(
            "CREATE TABLE rep_permit_pins (
                rep_id     INT UNSIGNED NOT NULL,
                permit_id  INT UNSIGNED NOT NULL,
                pinned_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (rep_id, permit_id),
                KEY idx_pin_rep (rep_id, pinned_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $v63Say('  ✓ اتعمل جدول rep_permit_pins');
    }

    $after = [
        'safe'     => round((float) Custody::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];
    if ($before !== $after) {
        throw new RuntimeException('⚠️ الترحيل غيّر رصيدًا أو صف إقفال — ده ترحيل وصفي، راجع فورًا.');
    }
    $v63Say('  ✓ رصيد الخزنة وصفوف الإقفال زي ما هُمّ');

    // ⚠️ مصيدة كاش المخطط (v57): `RepPin::ready()` بتكاش نتيجتها لكل الطلب،
    //    وممكن تكون اتقرت قبل الـCREATE. لازم تتصفّر هنا وإلا التحقّق يفشل
    //    والترحيل ما يتسجّلش رغم إنه اتطبّق فعلاً.
    if (!$v63Dry) { RepPin::refreshReady(); }
};

$v63Verify = static function () use ($v63Say): bool {
    $cols = [];
    foreach (Database::all(
        "SELECT column_name cn, is_nullable nl FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'rep_permit_pins'") as $r) {
        $cols[(string) $r['cn']] = (string) $r['nl'];
    }
    if (!$cols) { $v63Say('✗ جدول rep_permit_pins مش موجود.'); return false; }

    foreach (['rep_id', 'permit_id', 'pinned_at'] as $need) {
        if (!isset($cols[$need])) { $v63Say("✗ العمود {$need} ناقص."); return false; }
    }
    $v63Say('  ✓ الأعمدة التلاتة موجودة');

    /* المفتاح المركّب هو اللي بيمنع تكرار نفس التثبيت — من غيره كل ضغطة
       بتدخل صفًّا جديد والترتيب يبوظ. */
    $pk = array_column(Database::all(
        "SELECT column_name cn FROM information_schema.key_column_usage
          WHERE table_schema = DATABASE() AND table_name = 'rep_permit_pins'
            AND constraint_name = 'PRIMARY' ORDER BY ordinal_position"), 'cn');
    if ($pk !== ['rep_id', 'permit_id']) {
        $v63Say('✗ المفتاح الأساسي مش (rep_id, permit_id) — التكرار مش ممنوع.');
        return false;
    }
    $v63Say('  ✓ المفتاح الأساسي (rep_id, permit_id) — التكرار ممنوع بالبناء');

    /* ⚠️ حارس العزل: أول ما يظهر عمود مالي هنا تبقى الوحدة اتحوّلت لحاجة
       تانية خالص، والفحص لازم يوقف ده قبل ما حد يبني حساب فوقها. */
    foreach (array_keys($cols) as $c) {
        foreach (['amount','custody','freight','debt','paid','price','total','balance','visa'] as $bad) {
            if (str_contains($c, $bad)) { $v63Say("✗ عمود مالي ({$c}) في جدول وصفي."); return false; }
        }
    }
    $v63Say('  ✓ ولا عمود مالي في الجدول (العزل بالبناء)');

    if (!RepPin::refreshReady()) {
        $v63Say('✗ RepPin::ready لسه بترجّع false بعد الترحيل (كاش ساكن؟).');
        return false;
    }
    $v63Say('  ✓ RepPin::ready فعّالة');
    return true;
};

if ($v63Direct) {
    echo 'v63 — تثبيت الأذونات على شاشة المندوب' . ($v63Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v63Up();
    if ($v63Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v63Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v63.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v63Up, 'verify' => $v63Verify];
