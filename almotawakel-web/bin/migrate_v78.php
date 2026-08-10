<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v78 — استثناءات الصلاحيات: مصفوفة مرئية واستثناء لكل مستخدم.
 *       (docs/19-settings-control-center-plan.md · المرحلة ٥)
 *
 * ═══ اللزمة ═══
 * سياسة الصلاحيات كانت **٤ قوايم ثابتة في `Access.php`**، و**مفيش شاشة تفرجي
 * المالك مين يقدر يعمل إيه** أصلاً — ولا تعديل جزئي («المحاسب ده أيوه
 * للمصروفات، لأ للحذف»).
 *
 * ═══ ⚠️⚠️ طبقة فوق — مش بديل ═══
 * `Access::basePolicy()` **ما اتغيّرش فيها سطر**، والاستثناء بيتطبّق بعدها.
 * الجدول بيتولد فاضي ⇒ **نفس الإجابة في الـ١٦١ حالة** اللي
 * `verify_access_matrix` بيقيسها. ده اللي بيخلّي التغيير ده آمنًا على سياسة
 * اتّبنت على سنين.
 *
 * ⚠️ الحُرّاس ضد قفل المالك برّه (كلها في `AccessOverride`):
 *   ① دور `owner` ما بيتقيّدش أبدًا.
 *   ② `SettingsController` و`AuthController` محميّان — رفضهما بيشيل طريق الرجوع.
 *   ③ الاستثناء على مستوى **الكنترولر** مش الإجراء — مصفوفة مفهومة أهم من دقّة
 *      بتخلّي المالك يقفل حاجة وهو مش فاهم أثرها.
 *
 * ⚠️ صفر أثر مالي: الترحيل بيعمل جدولًا فاضيًا وبس.
 *
 * التشغيل:  php bin/migrate_v78.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;

use App\Models\AccessOverride;
use App\Models\CompanySafe;
use App\Models\Custody;

$v78Dry    = in_array('--dry-run', $argv ?? [], true);
$v78Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v78Say = static function (string $m) use ($v78Direct): void { if ($v78Direct) { echo $m . "\n"; } };

$v78HasTbl = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$v78HasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$v78HasIdx = static fn (string $t, string $i): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);

$v78Cols = ['id', 'role', 'user_id', 'controller', 'effect', 'updated_by', 'updated_at'];

$v78Up = static function () use ($v78Dry, $v78Say, $v78HasTbl): void {
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        /* ⚠️ **لقطة السياسة نفسها**: بصمة إجابات كل دور على كل وحدة قبل الترحيل.
           أي فرق بعده معناه إن الطبقة الجديدة غيّرت سياسة قايمة — وهو بالظبط
           اللي التصميم اتعمل يمنعه. */
        'policy'   => (static function (): string {
            $out = [];
            foreach (AccessOverride::controllers() as $ctrl => $m) {
                foreach (array_keys(Access::ROLES) as $role) {
                    $out[] = $role . ':' . $ctrl . ':'
                        . (Access::allows($role, $m['handler'], 'GET') ? '1' : '0')
                        . (Access::allows($role, $ctrl . '@store', 'POST') ? '1' : '0');
                }
            }
            return implode('|', $out);
        })(),
    ];

    if ($v78HasTbl('access_overrides')) {
        $v78Say('  = جدول access_overrides (موجود)');
    } elseif ($v78Dry) {
        $v78Say('  • (تجربة) هيتعمل جدول access_overrides');
    } else {
        Database::exec(
            "CREATE TABLE access_overrides (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               role       VARCHAR(20)  NULL COMMENT 'استثناء لدور — NULL لو الاستثناء لمستخدم',
               user_id    INT UNSIGNED NULL COMMENT 'users.id — بلا FK (تحذير ٣)',
               controller VARCHAR(60)  NOT NULL COMMENT 'اسم الكنترولر بلا نطاق',
               effect     VARCHAR(8)   NOT NULL COMMENT 'allow | deny',
               updated_by VARCHAR(80)  NULL,
               updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               UNIQUE KEY uq_role_ctrl (role, controller),
               UNIQUE KEY uq_user_ctrl (user_id, controller),
               INDEX idx_ctrl (controller)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
               COMMENT='استثناءات فوق سياسة الكود — الجدول الفاضي = السياسة الأصلية (v78)'"
        );
        $v78Say('  + جدول access_overrides (فاضي — صفر تغيير في السياسة)');
    }

    if (!$v78Dry && $v78HasTbl('access_overrides')) {
        AccessOverride::refreshReady();
        $v78Say('  ✓ AccessOverride::ready اتصفّرت واتقرت من جديد');
    }

    $after = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'policy'   => (static function (): string {
            $out = [];
            foreach (AccessOverride::controllers() as $ctrl => $m) {
                foreach (array_keys(Access::ROLES) as $role) {
                    $out[] = $role . ':' . $ctrl . ':'
                        . (Access::allows($role, $m['handler'], 'GET') ? '1' : '0')
                        . (Access::allows($role, $ctrl . '@store', 'POST') ? '1' : '0');
                }
            }
            return implode('|', $out);
        })(),
    ];
    foreach ($before as $k => $v) {
        if ($after[$k] !== $v) {
            // ⚠️ `{$k}` مش `$k` — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة v38).
            throw new \RuntimeException("v78: «{$k}» اتغيّر أثناء الترحيل — الطبقة دي ما تلمسش السياسة القائمة.");
        }
    }
    $v78Say('  ✓ الأرصدة **وبصمة الصلاحيات كلها** ما اتغيّروش');
};

$v78Verify = static function () use ($v78Say, $v78HasTbl, $v78HasCol, $v78HasIdx, $v78Cols): bool {
    if (!$v78HasTbl('access_overrides')) { $v78Say('✗ جدول access_overrides ناقص.'); return false; }
    $v78Say('  ✓ جدول access_overrides موجود');

    foreach ($v78Cols as $c) {
        if (!$v78HasCol('access_overrides', $c)) { $v78Say("✗ العمود access_overrides.$c ناقص."); return false; }
    }
    $v78Say('  ✓ كل أعمدة v78 موجودة (' . count($v78Cols) . ')');

    foreach (['uq_role_ctrl', 'uq_user_ctrl'] as $idx) {
        if (!$v78HasIdx('access_overrides', $idx)) { $v78Say("✗ الفهرس الفريد $idx ناقص."); return false; }
    }
    $v78Say('  ✓ فهرسان فريدان — مستحيل استثناءان متعارضان لنفس الدور/المستخدم');

    /* شروط سلامة بنيوية دائمة */
    $bad = (int) Database::scalar("SELECT COUNT(*) FROM access_overrides WHERE effect NOT IN ('allow','deny')");
    if ($bad > 0) { $v78Say("✗ فيه $bad استثناء بنوع مجهول."); return false; }
    $v78Say('  ✓ ولا استثناء بنوع مجهول');

    $bad = (int) Database::scalar("SELECT COUNT(*) FROM access_overrides WHERE role = 'owner'");
    if ($bad > 0) { $v78Say('✗ فيه استثناء على المالك — ممكن يتقفل برّه النظام.'); return false; }
    $v78Say('  ✓ ولا استثناء على المالك');

    $in  = implode(',', array_fill(0, count(AccessOverride::PROTECTED_CTRL), '?'));
    $bad = (int) Database::scalar(
        "SELECT COUNT(*) FROM access_overrides WHERE controller IN ($in)", AccessOverride::PROTECTED_CTRL);
    if ($bad > 0) { $v78Say('✗ فيه استثناء على كنترولر طريق الرجوع.'); return false; }
    $v78Say('  ✓ ولا استثناء على طريق الرجوع (' . count(AccessOverride::PROTECTED_CTRL) . ' محميّ)');

    $bad = (int) Database::scalar('SELECT COUNT(*) FROM access_overrides WHERE role IS NULL AND user_id IS NULL');
    if ($bad > 0) { $v78Say('✗ فيه استثناء بلا دور ولا مستخدم — صف ميت.'); return false; }
    $v78Say('  ✓ كل استثناء له دور أو مستخدم');

    if (!AccessOverride::refreshReady()) { $v78Say('✗ AccessOverride::ready بترجّع false.'); return false; }
    $v78Say('  ✓ AccessOverride::ready فعّالة');
    return true;
};

if ($v78Direct) {
    echo 'v78 — استثناءات الصلاحيات' . ($v78Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v78Up();
    if ($v78Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v78Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v78.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v78Up, 'verify' => $v78Verify];
