<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Access;
use App\Core\Database;
use RuntimeException;

/**
 * **استثناءات الصلاحيات — طبقة فوق سياسة الكود** (docs/19 · المرحلة ٥ · v78).
 *
 * ═══ اللزمة ═══
 * سياسة الصلاحيات كانت **٤ قوايم ثابتة في `Access.php`** (`OWNER_ONLY` ·
 * `OWNER_MANAGER_ONLY` · `FINANCIAL_CONTROLLERS` · `OPERATIONAL_CONTROLLERS`)،
 * و**مفيش شاشة تفرجي المالك مين يقدر يعمل إيه** أصلاً — ولا تعديل جزئي
 * («المحاسب ده أيوه للمصروفات، لأ للحذف»).
 *
 * ═══ ⚠️⚠️ طبقة فوق — مش بديل ═══
 * سياسة الكود **بتفضل هي الأساس بالحرف**: الجدول بيتولد فاضي والإجابة زي ما
 * هي في الـ١٦١ حالة اللي `verify_access_matrix` بيقيسها. الاستثناء بيتطبّق
 * **بعد** حساب الإجابة الأصلية، فمفيش إعادة كتابة لسياسة اتّبنت على سنين.
 *
 * ═══ ⚠️ الحُرّاس اللي بتمنع قفل المالك برّه ═══
 *   ① **المالك ما يترفضش أبدًا** — الاستثناء ما بيتطبّقش على دور `owner`.
 *   ② **`SettingsController` و`AuthController` محميّان** — رفضهما بيشيل طريق
 *      الرجوع نفسه (تشيل الاستثناء منين لو مقدرش تفتح الإعدادات؟).
 *   ③ الاستثناء **على مستوى الكنترولر** مش الإجراء — مصفوفة مفهومة أهم من
 *      دقّة بتخلّي المالك يقفل حاجة وهو مش فاهم أثرها.
 */
class AccessOverride
{
    /** كنترولرات ما ينفعش استثناء يرفضها — طريق الرجوع. */
    public const PROTECTED_CTRL = ['SettingsController', 'AuthController'];

    public const ALLOW = 'allow';
    public const DENY  = 'deny';

    private static ?bool $ready = null;
    private static ?array $rows = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) { return self::$ready; }
        try {
            self::$ready = (bool) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?', ['access_overrides']);
        } catch (\Throwable $e) { self::$ready = false; }
        return self::$ready;
    }

    /** ⚠️ إلزامية في آخر أي ترحيل بينشئ الجدول (مصيدة الكاش الساكن v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null; self::$rows = null;
        return self::ready();
    }

    /** كل الاستثناءات: "role|<دور>|<كنترولر>" و"user|<id>|<كنترولر>" => effect. */
    private static function all(): array
    {
        if (self::$rows !== null) { return self::$rows; }
        if (!self::ready()) { return self::$rows = []; }
        $out = [];
        foreach (Database::all('SELECT * FROM access_overrides') as $r) {
            $key = $r['user_id'] !== null
                ? 'user|' . (int) $r['user_id'] . '|' . $r['controller']
                : 'role|' . $r['role'] . '|' . $r['controller'];
            $out[$key] = (string) $r['effect'];
        }
        return self::$rows = $out;
    }

    /**
     * **تطبيق الاستثناء على إجابة سياسة الكود.**
     *
     * ⚠️ **استثناء المستخدم بيغلب استثناء الدور** — «فلان بالذات» أدقّ من
     *    «كل المحاسبين»، ولو الاتنين موجودين المقصود هو الأدقّ.
     *
     * @param bool $base إجابة سياسة الكود
     */
    public static function apply(bool $base, string $role, string $controller, ?int $userId = null): bool
    {
        // ① المالك ما يتلمسش · ② طريق الرجوع محميّ
        if ($role === 'owner') { return $base; }
        if (in_array($controller, self::PROTECTED_CTRL, true)) { return $base; }

        $rows = self::all();
        $uid  = $userId ?? (int) ($_SESSION['uid'] ?? 0);

        if ($uid > 0) {
            $e = $rows['user|' . $uid . '|' . $controller] ?? null;
            if ($e !== null) { return $e === self::ALLOW; }
        }
        $e = $rows['role|' . $role . '|' . $controller] ?? null;
        if ($e !== null) { return $e === self::ALLOW; }

        return $base;
    }

    /**
     * حفظ استثناء — للمالك بس.
     *
     * @param string      $effect allow|deny|'' (الفراغ = شيل الاستثناء)
     * @throws RuntimeException
     */
    public static function set(string $controller, string $effect, ?string $role = null, ?int $userId = null): void
    {
        if (!self::ready()) { return; }
        if (!Auth::isOwner()) { throw new RuntimeException('تعديل الصلاحيات للمالك بس.'); }
        if (!self::knownController($controller)) { throw new RuntimeException('الوحدة دي مش معروفة.'); }
        if (in_array($controller, self::PROTECTED_CTRL, true)) {
            throw new RuntimeException('الوحدة دي طريق الرجوع — ما ينفعش تتقيّد.');
        }
        if ($role !== null && $role === 'owner') {
            throw new RuntimeException('المالك ما بيتقيّدش — وإلا مفيش طريق رجوع.');
        }
        if ($role === null && $userId === null) { throw new RuntimeException('حدّد دورًا أو مستخدمًا.'); }
        if ($role !== null && !isset(Access::ROLES[$role])) { throw new RuntimeException('الدور ده مش معروف.'); }

        if ($userId !== null) {
            $u = Database::row('SELECT role FROM users WHERE id = ?', [$userId]);
            if (!$u) { throw new RuntimeException('المستخدم ده مش موجود.'); }
            if (($u['role'] ?? '') === 'owner') { throw new RuntimeException('المالك ما بيتقيّدش.'); }
        }

        if ($effect === '') {
            Database::exec(
                'DELETE FROM access_overrides WHERE controller = ? AND '
                . ($userId !== null ? 'user_id = ?' : 'role = ? AND user_id IS NULL'),
                [$controller, $userId !== null ? $userId : $role]
            );
        } else {
            if (!in_array($effect, [self::ALLOW, self::DENY], true)) {
                throw new RuntimeException('نوع الاستثناء مش معروف.');
            }
            Database::exec(
                'INSERT INTO access_overrides (role, user_id, controller, effect, updated_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE effect = VALUES(effect), updated_by = VALUES(updated_by)',
                [$role, $userId, $controller, $effect, Auth::user()]
            );
        }
        self::$rows = null;
    }

    /** الكنترولرات اللي المصفوفة بتعرضها — من القائمة نفسها (مصدر واحد). */
    public static function controllers(): array
    {
        $out = [];
        foreach (NavModule::DEFAULTS as $item) {
            if ($item[2] === null || $item[3] === null) { continue; }
            [$ctrl] = array_pad(explode('@', (string) $item[3], 2), 2, '');
            if ($ctrl === '' || isset($out[$ctrl])) { continue; }
            $out[$ctrl] = ['label' => (string) $item[1], 'handler' => (string) $item[3], 'slug' => (string) $item[0]];
        }
        return $out;
    }

    private static function knownController(string $ctrl): bool
    {
        return isset(self::controllers()[$ctrl]);
    }

    /**
     * **المصفوفة المرئية**: وحدة × دور، بالإجابة الفعلية والاستثناء.
     *
     * ⚠️ **الإجابة بتتحسب من `Access::allows()` نفسها** — مش من نسخة تانية من
     *    المنطق. المصفوفة اللي بتحسب بإيدها بتوري حاجة والنظام بيعمل تانية.
     */
    public static function matrix(): array
    {
        $rows = self::all();
        $out  = [];
        foreach (self::controllers() as $ctrl => $meta) {
            $cells = [];
            foreach (array_keys(Access::ROLES) as $role) {
                if ($role === 'owner') { continue; }
                $cells[$role] = [
                    'read'  => Access::allows($role, $meta['handler'], 'GET'),
                    'write' => Access::allows($role, $ctrl . '@store', 'POST'),
                    'ov'    => $rows['role|' . $role . '|' . $ctrl] ?? '',
                ];
            }
            $out[$ctrl] = [
                'label'     => $meta['label'],
                'cells'     => $cells,
                'protected' => in_array($ctrl, self::PROTECTED_CTRL, true),
            ];
        }
        return $out;
    }

    /** استثناءات المستخدمين — للعرض. */
    public static function userRows(): array
    {
        if (!self::ready()) { return []; }
        return Database::all(
            'SELECT o.*, u.username, u.display_name FROM access_overrides o
              JOIN users u ON u.id = o.user_id
              WHERE o.user_id IS NOT NULL ORDER BY u.id, o.controller');
    }
}
