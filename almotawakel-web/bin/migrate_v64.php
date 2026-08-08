<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v64 — ملفات الإذن + أزمنة الطرق.
 *
 * ═══ ① `permit_files` — ملفات الإذن المهمة ═══
 * المندوب في الميناء ماسك ورق الإذن، والمكتب عايز نسخته. الجدول ده بيخلّي أي
 * ورقة تخصّ الإذن (صورة أو PDF) تتصوّر من الموبايل وتتربط بالإذن.
 * **مش زي `party_documents` (v56)**: دي أوراق **الطرف** (بطاقة ضريبية · سجل
 * تجاري) بتتحطّ مرّة وتظهر على كل أذوناته؛ ودي أوراق **الإذن ده بعينه**.
 *
 * ═══ ② `route_times` — أزمنة الطرق الحقيقية ═══
 * «من ميناء الإسكندرية لمدينة بدر كام؟» — الرد لازم يبقى من **مسافة طريق
 * حقيقية** لا من خط مستقيم. الجدول مبذور بالمسافات المعروفة لأكتر الطرق
 * استخدامًا، والباقي بيرجع لتقدير `Track::eta` (خط مستقيم × معامل طريق).
 *
 * ⚠️⚠️ **الوقت تقدير مش وعد** — نفس قاعدة v34 بالحرف. ما بيمنعش ولا بيغيّر
 *    أي حاجة، وبيتعرض بصيغة «~٥ ساعات» لا وقت مضبوط.
 *
 * ═══ ⚠️⚠️ العزل بالبناء — ولا عمود مالي في الاتنين ═══
 * نفس منطق `permit_track_events` (v62) و`rep_permit_pins` (v63): جداول خاصة
 * **كل كود الخزنة عمره ما سمع عنها**. `bin/verify_v64.php` بيفشل لو أي ملف
 * خزنة ذكرها، والترحيل بيتحقق إن رصيد الخزنة وصفوف الإقفال ما اتغيّروش.
 *
 * ⚠️ `verify()` بتثبت **حالة بنيوية دائمة** (الجداول والأعمدة والمفاتيح) لا
 *    لقطة بيانات لحظية — الدرس الموثَّق من v61.
 *
 * التشغيل:  php bin/migrate_v64.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\PermitFile;
use App\Models\RouteTime;

$v64Dry    = in_array('--dry-run', $argv ?? [], true);
$v64Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v64Say = static function (string $m) use ($v64Direct): void { if ($v64Direct) { echo $m . "\n"; } };

$v64HasTable = static function (string $t): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
};

/**
 * مسافات الطرق بالكيلومتر — **مسافة طريق فعلية** لا خط مستقيم.
 *
 * ⚠️ الأرقام دي طرق مصرية معروفة (الصحراوي · الزراعي · الدائري الإقليمي)،
 *    ومقرَّبة لأقرب ٥ كم. الدقّة المطلوبة هنا «٤ ساعات ولا ٧» مش الكيلومتر.
 * ⚠️ المفتاح بيتخزّن بـ`Maintenance::normkey` فالتهجئة ما تفرقش.
 */
const V64_ROUTES = [
    // من ميناء الإسكندرية / الدخيلة (نفس المنطقة تقريبًا — فرق ٢٠ كم)
    'الإسكندرية' => [
        'العامرية' => 30,  'برج العرب' => 55,  'كفر الدوار' => 35,  'دمنهور' => 60,
        'كوم حمادة' => 85,  'طنطا' => 120, 'المحلة الكبرى' => 130, 'المنصورة' => 150,
        'كفر الشيخ' => 110, 'دسوق' => 90,  'قويسنا' => 155, 'بنها' => 175,
        'السادات' => 130, 'مدينة السادات' => 130,
        'القاهرة' => 220, 'الجيزة' => 225, '6 أكتوبر' => 200, 'أكتوبر' => 200,
        'مدينة بدر' => 250, 'بدر' => 250, 'العبور' => 230, 'الشروق' => 240,
        'العاشر من رمضان' => 250, 'العاشر' => 250,
        'الزقازيق' => 200, 'بلبيس' => 235, 'أنشاص' => 230, 'مشتول السوق' => 215,
        'الإسماعيلية' => 290, 'السويس' => 310, 'العين السخنة' => 350,
        'بورسعيد' => 270, 'دمياط' => 200, 'جمصة' => 180, 'رأس البر' => 205,
        'الصف' => 250, 'أطفيح' => 255, 'حلوان' => 245,
        'بني سويف' => 330, 'الفيوم' => 290, 'المنيا' => 420, 'أسيوط' => 500,
        'سوهاج' => 600, 'قنا' => 720, 'الأقصر' => 780, 'أسوان' => 990,
        'مرسى مطروح' => 290, 'العلمين' => 105, 'الضبعة' => 155,
    ],
];

$v64Up = static function () use ($v64Dry, $v64Say, $v64HasTable): void {
    /* لقطة «ممنوع يتغيّر»: رصيد الخزنة + كل صفوف الإقفال. */
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];

    // ── ① ملفات الإذن ────────────────────────────────────────────────
    if ($v64HasTable('permit_files')) {
        $v64Say('  • permit_files موجود — اتخطّى (idempotent).');
    } elseif ($v64Dry) {
        $v64Say('  • (تجربة) هيتعمل جدول permit_files.');
    } else {
        Database::exec(
            "CREATE TABLE permit_files (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                permit_id    INT UNSIGNED NOT NULL,
                file_path    VARCHAR(190) NOT NULL,   -- مسار منطقي جوّه storage/private_uploads
                orig_name    VARCHAR(190) NULL,
                mime         VARCHAR(80)  NULL,
                size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
                title        VARCHAR(120) NULL,       -- وصف الورقة («إذن أصلي» · «إيصال هيئة»)
                rep_id       INT UNSIGNED NULL,       -- رفعها مندوب
                user_name    VARCHAR(120) NULL,       -- أو موظف مكتب
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pf_permit (permit_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $v64Say('  ✓ اتعمل جدول permit_files');
    }

    // ── ② أزمنة الطرق ────────────────────────────────────────────────
    if ($v64HasTable('route_times')) {
        $v64Say('  • route_times موجود — اتخطّى (idempotent).');
    } elseif ($v64Dry) {
        $v64Say('  • (تجربة) هيتعمل جدول route_times ويتبذر.');
    } else {
        Database::exec(
            "CREATE TABLE route_times (
                from_key   VARCHAR(120) NOT NULL,   -- مفتاح الميناء (normkey)
                to_key     VARCHAR(120) NOT NULL,   -- مفتاح الوجهة (normkey)
                km         SMALLINT UNSIGNED NOT NULL,
                PRIMARY KEY (from_key, to_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $v64Say('  ✓ اتعمل جدول route_times');
    }

    if (!$v64Dry && $v64HasTable('route_times')) {
        $n = 0;
        foreach (V64_ROUTES as $from => $dests) {
            $fk = \App\Models\Maintenance::normkey($from);
            foreach ($dests as $to => $km) {
                // INSERT IGNORE = ما بيدهسش أي تعديل يدوي على مسافة
                $n += Database::exec(
                    'INSERT IGNORE INTO route_times (from_key, to_key, km) VALUES (?,?,?)',
                    [$fk, \App\Models\Maintenance::normkey($to), (int) $km]);
            }
        }
        $v64Say('  ✓ اتبذر ' . $n . ' طريق جديد (الإجمالي '
              . Database::scalar('SELECT COUNT(*) FROM route_times') . ')');
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
    $v64Say('  ✓ رصيد الخزنة وصفوف الإقفال زي ما هُمّ');

    // ⚠️ مصيدة كاش المخطط (v57) — لازم تتصفّر وإلا التحقّق يفشل بعد ALTER/CREATE
    if (!$v64Dry) { PermitFile::refreshReady(); RouteTime::refreshReady(); }
};

$v64Verify = static function () use ($v64Say): bool {
    foreach (['permit_files' => ['permit_id', 'file_path', 'created_at'],
              'route_times'  => ['from_key', 'to_key', 'km']] as $tbl => $need) {
        $cols = array_column(Database::all(
            "SELECT column_name cn FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?", [$tbl]), 'cn');
        if (!$cols) { $v64Say("✗ جدول {$tbl} مش موجود."); return false; }
        foreach ($need as $c) {
            if (!in_array($c, $cols, true)) { $v64Say("✗ العمود {$c} ناقص من {$tbl}."); return false; }
        }
        /* ⚠️ حارس العزل: أول ما يظهر عمود مالي تبقى الوحدة اتحوّلت لحاجة تانية. */
        foreach ($cols as $c) {
            foreach (['amount','custody','freight','debt','paid','price','total','balance','visa'] as $bad) {
                if (str_contains((string) $c, $bad)) { $v64Say("✗ عمود مالي ({$c}) في {$tbl}."); return false; }
            }
        }
        $v64Say("  ✓ {$tbl}: الأعمدة موجودة وولا عمود مالي");
    }

    $pk = array_column(Database::all(
        "SELECT column_name cn FROM information_schema.key_column_usage
          WHERE table_schema = DATABASE() AND table_name = 'route_times'
            AND constraint_name = 'PRIMARY' ORDER BY ordinal_position"), 'cn');
    if ($pk !== ['from_key', 'to_key']) {
        $v64Say('✗ مفتاح route_times مش (from_key, to_key) — الطريق ممكن يتكرّر.');
        return false;
    }
    $v64Say('  ✓ ومفتاح الطريق مركّب فالتكرار ممنوع بالبناء');

    /* ⚠️ فحص بنيوي مش لقطة: الجدول لازم يكون **مبذورًا** (مش فاضي) وإلا
       التقدير هيرجع للخط المستقيم في كل الحالات ويبقى غير مفيد. */
    if ((int) Database::scalar('SELECT COUNT(*) FROM route_times') < 20) {
        $v64Say('✗ route_times شبه فاضي — البذر ما اشتغلش.');
        return false;
    }
    $v64Say('  ✓ ومبذور بالطرق المعروفة');

    if (!PermitFile::refreshReady() || !RouteTime::refreshReady()) {
        $v64Say('✗ ready() لسه بترجّع false بعد الترحيل (كاش ساكن؟).');
        return false;
    }
    $v64Say('  ✓ PermitFile::ready و RouteTime::ready فعّالتين');
    return true;
};

if ($v64Direct) {
    echo 'v64 — ملفات الإذن + أزمنة الطرق' . ($v64Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v64Up();
    if ($v64Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v64Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v64.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v64Up, 'verify' => $v64Verify];
