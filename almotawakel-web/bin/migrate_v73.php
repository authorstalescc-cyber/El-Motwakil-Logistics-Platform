<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v73 — سجل الإعدادات: كل تغيير في أي إعداد بيسيب أثرًا وبيرجع بضغطة.
 *       (docs/19-settings-control-center-plan.md · المرحلة ٠)
 *
 * ⚠️⚠️ **الملف ده كان `migrate_v70.php` واترقّم لـv73 — والسبب مش تنظيم.**
 *   جلستان اشتغلتا في نفس الوقت: التانية طبّقت **v71** على قاعدة الإنتاج
 *   قبل ما v70 توصلها. وسجل الهجرات **صارم في الترتيب**: `MigrationRunner`
 *   بيرمي «لا يمكن إدخال v70 قبل آخر إصدار مطبّق v71» — والأداة `reconcile`
 *   مخصّصة لحادثة v2…v41 المثبتة وبس.
 *   **والأخطر إن الحالة دي بتقفل كل رفع جاي**: أي `deploy` بيمرّ على الهجرات
 *   وبيلاقي v70 معلّقة تحت آخر مطبَّق فبيفشل — مش بس الرفع ده.
 *   الحل اللي رسالة الحارس نفسها بتقوله (`MigrationRunner` سطر ٣٨٦):
 *   **«أنشئ إصدارًا جديدًا»**. والترحيل idempotent (بيتخطّى لو الجدول موجود)
 *   فتطبيقه على الإنتاج بيسجّله في السجل بلا ما يغيّر حاجة.
 *
 * ═══ اللزمة ═══
 * `App::setSetting()` كانت بتكتب في القاعدة **بلا أي أثر**: مفيش صف في
 * `audit_logs` ولا سجل يقول مين غيّر إيه وإمتى ولا طريقة ترجع بيها. يعني لو حد
 * كتب `vat_rate = 14` بدل `0.14`، الفواتير كلها تغلط ومفيش دليل واحد على
 * اللي حصل. والوحيدة اللي كانت بتسجّل بنفسها هي `RepMaintenance`.
 *
 * ═══ ⚠️⚠️ العزل بالبناء (قاعدة ①) ═══
 * `settings_history` **جدول مستقل تمامًا**: ولا كود خزنة ولا تقفيل ولا عهدة
 * ولا مصروفات بيعرف إنه موجود. هو سجل **قراءة** للمالك وبس — مالوش أي أثر
 * مالي، ومفيش leg جديد في `Expense::unionSql()` ولا في `Closing`.
 * و`verify_settings_registry` بيفشل لو أي ملف خزنة ذكر الجدول.
 *
 * ═══ ⚠️ ليه جدول مستقل مش `audit_logs`؟ ═══
 * `audit_logs` سجل عام بيستقبل كل حاجة والقيم فيه JSON. السؤال «القيمة السابقة
 * للمفتاح ده كانت إيه؟» عليه كان هيبقى مسح للجدول كله وفكّ JSON. الجدول ده
 * **سلسلة لكل مفتاح** فالسؤال بقى استعلامًا واحدًا مفهرسًا.
 * **والاتنين بيتكتبوا مع بعض** — `Audit::log` لسه بياخد نسخته من `Settings::set`.
 *
 * ═══ ⚠️ الترحيل ده ما بيغيّرش أي سلوك ═══
 * ولا إعداد قيمته بتتغيّر، ولا مستدعي واحد من الـ٢٠ بتاعين `App::setSetting`
 * بيتكسر. الفرق الوحيد إن الكتابة بقت بتسيب أثرًا. و`$up` بياخد لقطة قبل/بعد
 * لرصيد الخزنة وخزنة الشركة وصفوف الإقفال **وكل قيم جدول `settings`** وبيرمي
 * استثناء لو أي رقم أو أي إعداد اتحرّك.
 *
 * ⚠️ `verify()` بتثبت **حالة بنيوية دائمة** (جدول · أعمدة · فهارس · حارس
 *    التقنيع) — مش لقطة بيانات لحظية (درس v61 الموثَّق).
 *
 * التشغيل:  php bin/migrate_v73.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Settings;
use App\Core\SettingsSchema;

use App\Models\CompanySafe;
use App\Models\Custody;

$v73Dry    = in_array('--dry-run', $argv ?? [], true);
$v73Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v73Say = static function (string $m) use ($v73Direct): void { if ($v73Direct) { echo $m . "\n"; } };

$v73HasTbl = static function (string $t): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
};
$v73HasCol = static function (string $t, string $c): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
};
$v73HasIdx = static function (string $t, string $i): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);
};

/** أعمدة `settings_history` — المصدر الوحيد للترحيل والتحقّق. */
$v73Cols = [
    'id', 'setting_key', 'old_value', 'new_value',
    'changed_by', 'changed_by_id', 'reason', 'source', 'created_at',
];
$v73Idx = ['idx_sh_key' => 'setting_key', 'idx_sh_time' => 'created_at'];

$v73Up = static function () use ($v73Dry, $v73Say, $v73HasTbl, $v73HasIdx): void {
    /* ⚠️ لقطة «ممنوع يتغيّر». بنقيس **قبل** أي DDL لأن `CREATE TABLE` بيعمل
       commit ضمني ومستحيل يترجع (المصيدة الموثَّقة في v59). */
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
        // كل الإعدادات بقيمها — إثبات إن الترحيل ما لمسش ولا مفتاح
        'settings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('=',setting_key,setting_value)
                    ORDER BY setting_key SEPARATOR '\n'),'') FROM settings"),
    ];

    // ①  جدول السجل
    if ($v73HasTbl('settings_history')) {
        $v73Say('  = جدول settings_history (موجود)');
    } elseif ($v73Dry) {
        $v73Say('  • (تجربة) هيتعمل جدول settings_history');
    } else {
        Database::exec(
            "CREATE TABLE settings_history (
               id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               setting_key   VARCHAR(64)  NOT NULL COMMENT 'مفتاح الإعداد',
               old_value     TEXT         NULL     COMMENT 'القيمة قبل — مقنّعة لو المفتاح سرّي',
               new_value     TEXT         NULL     COMMENT 'القيمة بعد — مقنّعة لو المفتاح سرّي',
               changed_by    VARCHAR(80)  NULL     COMMENT 'اسم المنفّذ',
               changed_by_id INT UNSIGNED NULL     COMMENT 'users.id — بلا FK (تحذير ٣)',
               reason        VARCHAR(255) NULL     COMMENT 'سبب التغيير لو اتكتب',
               source        VARCHAR(16)  NOT NULL DEFAULT 'ui' COMMENT 'ui | cli | system',
               created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_sh_key (setting_key, id),
               INDEX idx_sh_time (created_at)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
               COMMENT='سجل تغييرات الإعدادات — معزول بالبناء، صفر أثر مالي (v70)'"
        );
        $v73Say('  + جدول settings_history');
    }

    // ②  الفهارس (لو الجدول كان موجودًا من نسخة أقدم بلا فهرس)
    if (!$v73Dry && $v73HasTbl('settings_history')) {
        foreach (['idx_sh_key' => '(setting_key, id)', 'idx_sh_time' => '(created_at)'] as $name => $spec) {
            if (!$v73HasIdx('settings_history', $name)) {
                Database::exec("ALTER TABLE settings_history ADD INDEX $name $spec");
                $v73Say("  + فهرس $name");
            }
        }
    }

    /* ③ ⚠️ تصفير الكاش الساكن **إلزامي**: `Settings::historyReady()` اتقرت
          قبل الـDDL (وقت اللقطة) فبترجّع false للأبد جوّه نفس العملية —
          والتحقّق بعد الترحيل كان هيفشل بإنذار كاذب (مصيدة v57 الموثَّقة). */
    if (!$v73Dry) {
        Settings::refreshHistoryReady();
        $v73Say('  ✓ Settings::historyReady اتصفّرت واتقرت من جديد');
    }

    // ④  إثبات إن ولا مليم ولا إعداد اتحرّك
    $after = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
        'settings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('=',setting_key,setting_value)
                    ORDER BY setting_key SEPARATOR '\n'),'') FROM settings"),
    ];
    foreach ($before as $k => $v) {
        if ($after[$k] !== $v) {
            // ⚠️ `{$k}` مش `$k` — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة v38).
            throw new \RuntimeException("v73: «{$k}» اتغيّر أثناء الترحيل — الترحيل ده سجل بس.");
        }
    }
    $v73Say('  ✓ الأرصدة وصفوف الإقفال وكل قيم الإعدادات ما اتغيّروش');
};

$v73Verify = static function () use ($v73Say, $v73HasTbl, $v73HasCol, $v73HasIdx, $v73Cols, $v73Idx): bool {
    if (!$v73HasTbl('settings_history')) { $v73Say('✗ جدول settings_history ناقص.'); return false; }
    $v73Say('  ✓ جدول settings_history موجود');

    foreach ($v73Cols as $col) {
        if (!$v73HasCol('settings_history', $col)) {
            $v73Say("✗ العمود settings_history.$col ناقص."); return false;
        }
    }
    $v73Say('  ✓ كل أعمدة v70 موجودة (' . count($v73Cols) . ')');

    foreach (array_keys($v73Idx) as $name) {
        if (!$v73HasIdx('settings_history', $name)) { $v73Say("✗ الفهرس $name ناقص."); return false; }
    }
    $v73Say('  ✓ فهارس السجل موجودة');

    if (!Settings::refreshHistoryReady()) { $v73Say('✗ Settings::historyReady بترجّع false.'); return false; }
    $v73Say('  ✓ Settings::historyReady فعّالة');

    /* ⚠️ شرط بنيوي دائم: **ولا قيمة مفتاح سرّي متخزّنة صريحة في السجل.**
       التقنيع بيحصل في `Settings::maskIfSecret` — والفحص ده هو اللي بيمنع
       ارتداده بالسكوت (مفتاح Groq في سجل مقروء = تسريب دائم). */
    $secrets = [];
    foreach (SettingsSchema::all() as $key => $d) {
        if (($d['type'] ?? '') === SettingsSchema::T_SECRET) { $secrets[] = $key; }
    }
    if ($secrets) {
        $in  = implode(',', array_fill(0, count($secrets), '?'));
        $bad = (int) Database::scalar(
            "SELECT COUNT(*) FROM settings_history
              WHERE setting_key IN ($in)
                AND ((old_value <> '' AND old_value IS NOT NULL AND old_value NOT LIKE '••••%')
                  OR (new_value <> '' AND new_value IS NOT NULL AND new_value NOT LIKE '••••%'))",
            $secrets);
        if ($bad > 0) { $v73Say("✗ فيه $bad صف بمفتاح سرّي غير مقنّع في السجل."); return false; }
        $v73Say('  ✓ ولا مفتاح سرّي متخزّن صريحًا في السجل (' . count($secrets) . ' مفتاح محروس)');
    }

    // شرط بنيوي دائم: ولا مصدر كتابة خارج القايمة المعتمدة.
    $badSrc = Database::all(
        "SELECT DISTINCT source FROM settings_history WHERE source NOT IN ('ui','cli','system')");
    if ($badSrc) {
        $v73Say('✗ مصدر كتابة مجهول: ' . implode(', ', array_column($badSrc, 'source'))); return false;
    }
    $v73Say('  ✓ ولا مصدر كتابة خارج القايمة');
    return true;
};

if ($v73Direct) {
    echo 'v73 — سجل الإعدادات' . ($v73Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v73Up();
    if ($v73Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v73Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v70.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v73Up, 'verify' => $v73Verify];
