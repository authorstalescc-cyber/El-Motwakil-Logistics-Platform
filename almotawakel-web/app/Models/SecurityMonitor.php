<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\RepAuth;

/**
 * لوحة المراقبة الأمنية — تجميع قراءة فقط لحالة الدخول والحسابات.
 *
 * ⚠️ **قراءة فقط بالكامل — ما فيهاش ولا INSERT/UPDATE/DELETE واحدة.**
 *    `bin/verify_security_panel.php` بيفشل لو أي أمر كتابة اتكتب هنا. الغرض
 *    إن اللوحة تتفرّج على الوضع بس؛ أي إجراء (فك قفل، إيقاف حساب، تجديد رقم
 *    سري) بيتعمل من مكانه الأصلي في `/settings` و`/rep-custody`.
 *
 * ⚠️ **مفيش أي عمود مالي هنا** — لا خزنة ولا عهدة ولا تقفيل. وحدة معزولة زي
 *    `dashboard_notes` و`legacy_bawalis`.
 *
 * ملحوظة عن الخصوصية: `auth_login_attempts` بتخزّن الاسم والـIP **متشفّرين**
 * عشان السجل ما يبقاش قايمة أهداف جاهزة. اللوحة بتقلب الاتجاه بأمان: بتحسب
 * بصمة كل حساب **معروف** (عددهم قليل جدًا) وتقارن — فبتعرف مين المستهدَف بلا
 * ما نخزّن أي اسم صريح. الـIP يفضل مجهول (بنعرضه كـ«مصدر #1») لأننا مش قادرين
 * نرجّعه — وده مقصود.
 */
class SecurityMonitor
{
    /** نافذة الرصد الافتراضية بالساعات. */
    public const WINDOW_HOURS = 24;

    /** عدد المحاولات الفاشلة من مصدر واحد اللي بعده نعتبره مشبوه. */
    private const SUSPECT_FAILURES = 10;

    public static function available(): bool
    {
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'auth_login_attempts'"
            ) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** كل اللي اللوحة بتعرضه في نداء واحد. */
    public static function overview(int $hours = self::WINDOW_HOURS): array
    {
        if (!self::available()) {
            return ['ready' => false];
        }
        $hours = max(1, min(720, $hours));

        return [
            'ready'        => true,
            'hours'        => $hours,
            'totals'       => self::totals($hours),
            'targeted'     => self::targetedAccounts($hours),
            'sources'      => self::sources($hours),
            'timeline'     => self::hourly($hours),
            'locked'       => self::lockedAccounts(),
            'weakPins'     => self::weakPins(),
            'logins'       => self::recentLogins(12),
            'staleAdmins'  => self::staleAccounts(),
            'clockSkew'    => Clock::isSkewed() ? Clock::skewLabel() : '',
        ];
    }

    // ── الأرقام العامة ──────────────────────────────────────────────────────
    private static function totals(int $hours): array
    {
        $row = Database::row(
            'SELECT
               SUM(was_success = 0) AS failed,
               SUM(was_success = 1) AS ok,
               COUNT(DISTINCT ip_hash) AS sources,
               COUNT(DISTINCT CASE WHEN was_success = 0 THEN ip_hash END) AS bad_sources
             FROM auth_login_attempts
             WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)',
            [$hours]
        ) ?: [];

        return [
            'failed'      => (int) ($row['failed'] ?? 0),
            'ok'          => (int) ($row['ok'] ?? 0),
            'sources'     => (int) ($row['sources'] ?? 0),
            'badSources'  => (int) ($row['bad_sources'] ?? 0),
        ];
    }

    /**
     * الحسابات اللي اتحاول عليها — بنرجّع الاسم عن طريق مطابقة البصمات.
     * بصمة مش معروفة = محاولة باسم مستخدم مش موجود أصلاً (تخمين أسماء).
     */
    private static function targetedAccounts(int $hours): array
    {
        $rows = Database::all(
            'SELECT username_hash, SUM(was_success = 0) AS failed, SUM(was_success = 1) AS ok,
                    ' . Clock::displayExpr('MAX(attempted_at)') . ' AS last_at
             FROM auth_login_attempts
             WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY username_hash
             HAVING failed > 0
             ORDER BY failed DESC
             LIMIT 25',
            [$hours]
        );

        $map = self::knownAccountHashes();
        $out = [];
        foreach ($rows as $r) {
            $known = $map[$r['username_hash']] ?? null;
            $out[] = [
                'label'   => $known['label'] ?? 'اسم مستخدم مش موجود',
                'kind'    => $known['kind'] ?? 'unknown',
                'failed'  => (int) $r['failed'],
                'ok'      => (int) $r['ok'],
                'last_at' => (string) $r['last_at'],
            ];
        }
        return $out;
    }

    /** بصمة كل حساب معروف → اسمه المعروض. */
    private static function knownAccountHashes(): array
    {
        $map = [];
        foreach (Database::all('SELECT username, display_name, role FROM users') as $u) {
            $map[Auth::usernameHash((string) $u['username'])] = [
                'label' => ($u['display_name'] ?: $u['username']) . ' (مكتب)',
                'kind'  => 'office',
            ];
        }
        try {
            $reps = Database::all(
                'SELECT ru.username, r.name FROM rep_users ru JOIN reps r ON r.id = ru.rep_id');
            foreach ($reps as $u) {
                $map[RepAuth::usernameHash((string) $u['username'])] = [
                    'label' => ($u['name'] ?: $u['username']) . ' (مندوب)',
                    'kind'  => 'rep',
                ];
            }
        } catch (\Throwable $e) {
            // قبل migrate_v35 مفيش جدول مناديب — اللوحة تفضل شغّالة للمكتب.
        }
        return $map;
    }

    /**
     * المصادر (أجهزة/شبكات) حسب عدد الفشل. الـIP متشفّر فمش قادرين نرجّعه —
     * بنرقّمه للعرض بس عشان المالك يعرف «ده مصدر واحد بيحاول كتير» من
     * «ناس كتير غلطوا مرة».
     */
    private static function sources(int $hours): array
    {
        $rows = Database::all(
            'SELECT ip_hash, SUM(was_success = 0) AS failed, SUM(was_success = 1) AS ok,
                    COUNT(DISTINCT username_hash) AS accounts,
                    ' . Clock::displayExpr('MAX(attempted_at)') . ' AS last_at
             FROM auth_login_attempts
             WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY ip_hash
             HAVING failed > 0
             ORDER BY failed DESC
             LIMIT 15',
            [$hours]
        );

        $out = [];
        foreach ($rows as $i => $r) {
            $failed = (int) $r['failed'];
            $out[] = [
                'name'     => 'مصدر #' . ($i + 1),
                'failed'   => $failed,
                'ok'       => (int) $r['ok'],
                'accounts' => (int) $r['accounts'],
                'last_at'  => (string) $r['last_at'],
                // مصدر بيجرّب أكتر من حساب = تخمين أسماء، مش نسيان كلمة سر.
                'suspect'  => $failed >= self::SUSPECT_FAILURES || (int) $r['accounts'] >= 3,
            ];
        }
        return $out;
    }

    /** توزيع المحاولات على الساعات (لرسم شريطي بسيط). */
    private static function hourly(int $hours): array
    {
        $rows = Database::all(
            'SELECT DATE_FORMAT(' . Clock::displayExpr('attempted_at') . ', "%Y-%m-%d %H:00") AS slot,
                    SUM(was_success = 0) AS failed, SUM(was_success = 1) AS ok
             FROM auth_login_attempts
             WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY slot ORDER BY slot',
            [$hours]
        );
        return array_map(static fn($r) => [
            'slot'   => (string) $r['slot'],
            'failed' => (int) $r['failed'],
            'ok'     => (int) $r['ok'],
        ], $rows);
    }

    /** الحسابات المقفولة دلوقتي — المقارنة جوّه SQL (قاعدة v48). */
    private static function lockedAccounts(): array
    {
        $out = [];
        foreach (Database::all(
            'SELECT display_name, username, failed_tries,
                    ' . Clock::displayExpr('locked_until') . ' AS until_at
             FROM users
             WHERE locked_until IS NOT NULL AND locked_until > NOW()') as $r) {
            $out[] = [
                'label'  => ($r['display_name'] ?: $r['username']) . ' (مكتب)',
                'tries'  => (int) $r['failed_tries'],
                'until'  => (string) $r['until_at'],
            ];
        }
        try {
            foreach (Database::all(
                'SELECT r.name, ru.username, ru.failed_tries,
                        ' . Clock::displayExpr('ru.locked_until') . ' AS until_at
                 FROM rep_users ru JOIN reps r ON r.id = ru.rep_id
                 WHERE ru.locked_until IS NOT NULL AND ru.locked_until > NOW()') as $r) {
                $out[] = [
                    'label' => ($r['name'] ?: $r['username']) . ' (مندوب)',
                    'tries' => (int) $r['failed_tries'],
                    'until' => (string) $r['until_at'],
                ];
            }
        } catch (\Throwable $e) { /* قبل v35 */ }
        return $out;
    }

    /** المناديب اللي لسه برقم سري قديم (4 خانات) — من v48. */
    private static function weakPins(): array
    {
        if (!RepAuth::pinMetaReady()) { return []; }
        try {
            return array_map(
                static fn($r) => ['id' => (int) $r['rep_id'], 'label' => (string) ($r['name'] ?: $r['username'])],
                Database::all(
                    "SELECT ru.rep_id, ru.username, r.name
                     FROM rep_users ru JOIN reps r ON r.id = ru.rep_id
                     WHERE ru.weak_pin = 1 AND ru.status = 'active' AND r.status = 'active'
                     ORDER BY r.name")
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** آخر دخولات ناجحة — «مين دخل وامتى». */
    private static function recentLogins(int $limit): array
    {
        // ⚠️ الحد بيتحقن كعدد مش كـ`?`: الاتصال بـ`EMULATE_PREPARES=false`
        //    (native prepares) وMySQL بترفض معامل مربوط في LIMIT. مضبوط بـint
        //    ومحصور فمفيش أي مدخل مستخدم بيوصل هنا.
        $lim = max(1, min(100, $limit));
        $out = [];
        foreach (Database::all(
            'SELECT display_name, username, role,
                    ' . Clock::displayExpr('last_login_at') . ' AS at
             FROM users WHERE last_login_at IS NOT NULL
             ORDER BY last_login_at DESC LIMIT ' . $lim) as $r) {
            $out[] = [
                'label' => ($r['display_name'] ?: $r['username']) . ' (مكتب)',
                'role'  => (string) ($r['role'] ?? ''),
                'at'    => (string) $r['at'],
            ];
        }
        try {
            foreach (Database::all(
                'SELECT r.name, ru.username, ' . Clock::displayExpr('ru.last_login_at') . ' AS at
                 FROM rep_users ru JOIN reps r ON r.id = ru.rep_id
                 WHERE ru.last_login_at IS NOT NULL
                 ORDER BY ru.last_login_at DESC LIMIT ' . $lim) as $r) {
                $out[] = [
                    'label' => ($r['name'] ?: $r['username']) . ' (مندوب)',
                    'role'  => 'rep',
                    'at'    => (string) $r['at'],
                ];
            }
        } catch (\Throwable $e) { /* قبل v35 */ }

        usort($out, static fn($a, $b) => strcmp($b['at'], $a['at']));
        return array_slice($out, 0, $limit);
    }

    /** حسابات مكتب عمرها ما دخلت أو ساكتة من زمان — مرشّحة للإيقاف. */
    private static function staleAccounts(): array
    {
        return array_map(
            static fn($r) => [
                'label' => ($r['display_name'] ?: $r['username']),
                'role'  => (string) ($r['role'] ?? ''),
                'never' => empty($r['last_login_at']),
                'at'    => (string) ($r['at'] ?? ''),
            ],
            Database::all(
                'SELECT display_name, username, role, last_login_at,
                        ' . Clock::displayExpr('last_login_at') . ' AS at
                 FROM users
                 WHERE last_login_at IS NULL OR last_login_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
                 ORDER BY last_login_at IS NULL DESC, last_login_at ASC')
        );
    }
}
