<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v77 — الوحدات والقائمة الجانبية من القاعدة مش من الكود.
 *       (docs/19-settings-control-center-plan.md · المرحلة ٤)
 *
 * ═══ اللزمة ═══
 * القائمة كانت **مصفوفة PHP مكتوبة بالإيد في `layout.php`**: ترتيب الـ٢٩ وحدة
 * وأسماؤها وأيقوناتها ما تتغيّرش إلا بتعديل كود، و**مفيش أي طريقة تطفي وحدة**
 * مش مستعملة — لو الشركة بطّلت «المولدات» تفضل في القائمة للأبد.
 *
 * ═══ الافتراضي في الكود · الفرق في القاعدة ═══
 * `NavModule::DEFAULTS` هي المصدر الأصلي، والجدول ده بيحمل **الفروق وبس**.
 * يعني **الجدول بيتولد فاضي والنظام زي ما هو بالحرف**، ووحدة جديدة في الكود
 * بتظهر لوحدها بلا أي صف.
 *
 * ⚠️⚠️ **الإطفاء بيتفرض في `Access` مش في إخفاء اللينك** (قاعدة ③) — الوحدة
 *    المطفية بترد ٤٠٤ حتى لو المستخدم كتب المسار بإيده.
 * ⚠️ **صفر مساس بالبيانات**: الإطفاء بيخفي وحدة، وبياناتها بتفضل مكانها،
 *    والرجوع بضغطة. ولا صف اتشال ولا اتعدّل في أي جدول تاني.
 * ⚠️ **`settings` و«لوحة التحكم» مقفولين** — إطفاؤهم بيقفل المالك برّه النظام.
 *
 * ⚠️ `verify()` بتثبت **حالة بنيوية دائمة** (جدول · أعمدة · مفتاح فريد · ولا
 *    صف لوحدة مجهولة) مش لقطة بيانات (درس v61).
 *
 * التشغيل:  php bin/migrate_v77.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

use App\Models\CompanySafe;
use App\Models\Custody;
use App\Models\NavModule;

$v77Dry    = in_array('--dry-run', $argv ?? [], true);
$v77Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v77Say = static function (string $m) use ($v77Direct): void { if ($v77Direct) { echo $m . "\n"; } };

$v77HasTbl = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$v77HasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$v77HasIdx = static fn (string $t, string $i): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);

$v77Cols = ['id', 'slug', 'label', 'icon', 'sort_order', 'is_hidden', 'is_disabled', 'updated_by', 'updated_at'];

$v77Up = static function () use ($v77Dry, $v77Say, $v77HasTbl): void {
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];

    if ($v77HasTbl('module_settings')) {
        $v77Say('  = جدول module_settings (موجود)');
    } elseif ($v77Dry) {
        $v77Say('  • (تجربة) هيتعمل جدول module_settings');
    } else {
        Database::exec(
            "CREATE TABLE module_settings (
               id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               slug        VARCHAR(40)  NOT NULL COMMENT 'مسار الوحدة زي ما هو في NavModule::DEFAULTS',
               label       VARCHAR(80)  NULL     COMMENT 'اسم بديل — NULL يعني الافتراضي',
               icon        VARCHAR(40)  NULL     COMMENT 'أيقونة بديلة من كتالوج icon()',
               sort_order  INT          NULL     COMMENT 'ترتيب مخصَّص — NULL يعني ترتيب الكود',
               is_hidden   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'يتشال من القائمة والمسار شغّال',
               is_disabled TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'يترفض من Access كمان',
               updated_by  VARCHAR(80)  NULL,
               updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               UNIQUE KEY uq_module (slug)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
               COMMENT='فروق القائمة عن الافتراضي — الجدول الفاضي = النظام زي ما هو (v77)'"
        );
        $v77Say('  + جدول module_settings (بيتولد فاضي — صفر تغيير في السلوك)');
    }

    if (!$v77Dry && $v77HasTbl('module_settings')) {
        NavModule::refreshReady();
        $v77Say('  ✓ NavModule::ready اتصفّرت واتقرت من جديد');
    }

    $after = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];
    foreach ($before as $k => $v) {
        if ($after[$k] !== $v) {
            // ⚠️ `{$k}` مش `$k` — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة v38).
            throw new \RuntimeException("v77: «{$k}» اتغيّر أثناء الترحيل — الترحيل ده قائمة بس.");
        }
    }
    $v77Say('  ✓ الأرصدة وصفوف الإقفال ما اتغيّروش');
};

$v77Verify = static function () use ($v77Say, $v77HasTbl, $v77HasCol, $v77HasIdx, $v77Cols): bool {
    if (!$v77HasTbl('module_settings')) { $v77Say('✗ جدول module_settings ناقص.'); return false; }
    $v77Say('  ✓ جدول module_settings موجود');

    foreach ($v77Cols as $c) {
        if (!$v77HasCol('module_settings', $c)) { $v77Say("✗ العمود module_settings.$c ناقص."); return false; }
    }
    $v77Say('  ✓ كل أعمدة v77 موجودة (' . count($v77Cols) . ')');

    if (!$v77HasIdx('module_settings', 'uq_module')) { $v77Say('✗ الفهرس الفريد uq_module ناقص.'); return false; }
    $v77Say('  ✓ فهرس فريد على المسار — مستحيل صفّان لوحدة واحدة');

    /* شرط بنيوي دائم: ولا صف لوحدة مش في `DEFAULTS`. الصف اليتيم معناه إعداد
       بيتخزّن ومحدّش بيقراه — ولو رجعت الوحدة بعدين بيتطبّق عليها فجأة. */
    $known = [];
    foreach (NavModule::DEFAULTS as $item) { if ($item[2] !== null) { $known[] = (string) $item[0]; } }
    $in  = implode(',', array_fill(0, count($known), '?'));
    $bad = Database::all("SELECT slug FROM module_settings WHERE slug NOT IN ($in)", $known);
    if ($bad) { $v77Say('✗ صف لوحدة مجهولة: ' . implode(', ', array_column($bad, 'slug'))); return false; }
    $v77Say('  ✓ ولا صف لوحدة مجهولة (' . count($known) . ' وحدة معروفة)');

    /* ⚠️ **شرط سلامة**: الوحدة المقفولة ممنوع تكون مطفية أو مخفية — ده اللي
       بيمنع قفل المالك برّه النظام بلا طريق رجوع غير قاعدة البيانات. */
    $lockedIn = implode(',', array_fill(0, count(NavModule::LOCKED), '?'));
    $lockedBad = (int) Database::scalar(
        "SELECT COUNT(*) FROM module_settings
          WHERE slug IN ($lockedIn) AND (is_hidden = 1 OR is_disabled = 1)", NavModule::LOCKED);
    if ($lockedBad > 0) { $v77Say('✗ وحدة مقفولة متعلَّمة مطفية — المالك ممكن يتقفل برّه.'); return false; }
    $v77Say('  ✓ ولا وحدة مقفولة مطفية (' . count(NavModule::LOCKED) . ' محروسة)');

    if (!NavModule::refreshReady()) { $v77Say('✗ NavModule::ready بترجّع false.'); return false; }
    $v77Say('  ✓ NavModule::ready فعّالة');
    return true;
};

if ($v77Direct) {
    echo 'v77 — الوحدات والقائمة من القاعدة' . ($v77Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v77Up();
    if ($v77Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v77Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v77.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v77Up, 'verify' => $v77Verify];
