<?php
namespace App\Core;

/**
 * المصادقة عبر الجلسة (حساب موحّد).
 */
class Auth
{
    public const MIN_PASSWORD_LENGTH = 8;
    private const MAX_TRIES = 5;
    private const LOCK_MINUTES = 10;
    private const IP_MAX_FAILURES = 30;
    private const IP_WINDOW_MINUTES = 10;
    private const IDLE_MINUTES = 120;
    private const DUMMY_HASH = '$2y$12$FcwBkiR2DgsCP/uU1pYHueh5yYl1wD5yFiHDW8aTADVATj1NFIHfi';

    /** كل قد إيه نتأكد إن المستخدم لسه موجود ودوره ما اتغيّرش (بالثواني). */
    private const REVALIDATE_SECONDS = 120;

    private static string $lastFailure = 'invalid';
    private static ?bool $securitySchemaReady = null;
    private static ?bool $revalidated = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name(App::config('session_name') ?? 'almotawakel_session');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => SecurityHeaders::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $username = trim($username);
        self::$lastFailure = 'invalid';

        $ipThrottled = self::securityReady() && self::ipFailureCount() >= self::IP_MAX_FAILURES;
        // ⚠️ `is_locked` بتتحسب **جوّه SQL**. متحقَّق على الإنتاج: MySQL بـUTC
        //    وPHP بـAfrica/Cairo (فرق 3 ساعات)، فقفل الحساب اللي بتكتبه MySQL
        //    كان PHP يقراه على إنه عدّى — **قفل حساب المكتب عمره ما اشتغل على
        //    الإنتاج**. المقارنة جوّه SQL بتخلّي الطرفين على نفس الساعة.
        $user = Database::row(
            'SELECT *, (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
             FROM users WHERE username = ?', [$username]);
        if ($user && self::securityReady() && (int) ($user['is_locked'] ?? 0) === 1) {
            self::$lastFailure = 'throttled';
            self::recordAttempt($username, false);
            return false;
        }

        // الهاش الوهمي يقارب زمن المستخدم غير الموجود ويصعّب كشف أسماء الحسابات بالتوقيت.
        $passwordOk = $user
            ? password_verify($password, $user['password_hash'])
            : (password_verify($password, self::DUMMY_HASH) && false);
        if ($user && $passwordOk) {
            if (self::securityReady()) {
                Database::exec(
                    'UPDATE users SET failed_tries=0, locked_until=NULL, last_failed_at=NULL, last_login_at=NOW() WHERE id=?',
                    [(int) $user['id']]
                );
                self::recordAttempt($username, true);
            }
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['uid']  = $user['id'];
            $_SESSION['uname'] = $user['display_name'] ?: $user['username'];
            $_SESSION['can_free_edit'] = (int)($user['can_free_edit'] ?? 0);
            $_SESSION['role'] = Access::normalizeRole($user['role'] ?? 'owner');
            $_SESSION['last_activity'] = time();
            $_SESSION['uid_checked'] = time();
            self::$revalidated = true;

            try {
                \App\Models\Audit::log('login', 'users', (int) $user['id'], null, null, 'تسجيل دخول ناجح');
            } catch (\Throwable $e) {
                error_log('تعذّر تسجيل نجاح الدخول في audit: ' . $e->getMessage());
            }
            return true;
        }

        // لا نمنع كلمة سر صحيحة لمستخدم آخر على شبكة مكتب مشتركة. بعد بلوغ حد IP
        // نبطئ الفشل ونوقف زيادة قفل الحساب، بينما يظل قفل الحساب نفسه فعالًا.
        if ($ipThrottled) {
            self::$lastFailure = 'throttled';
            self::recordAttempt($username, false);
            usleep(750000);
            return false;
        }

        if ($user && self::securityReady()) {
            Database::exec(
                'UPDATE users
                 SET last_failed_at=NOW(),
                     locked_until=CASE WHEN failed_tries+1>=? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE) ELSE locked_until END,
                     failed_tries=CASE WHEN failed_tries+1>=? THEN 0 ELSE failed_tries+1 END
                 WHERE id=?',
                [self::MAX_TRIES, self::LOCK_MINUTES, self::MAX_TRIES, (int) $user['id']]
            );
        }
        if (self::securityReady()) { self::recordAttempt($username, false); }
        return false;
    }

    /** رسالة موحدة لا تكشف هل اسم المستخدم موجود أم مقفول. */
    public static function failureMessage(): string
    {
        return self::$lastFailure === 'throttled'
            ? 'تعذّر تسجيل الدخول مؤقتًا بسبب كثرة المحاولات. انتظر قليلًا ثم حاول من جديد.'
            : 'اسم المستخدم أو كلمة السر غير صحيحة.';
    }

    public static function check(): bool
    {
        if (empty($_SESSION['uid'])) { return false; }
        $last = (int) ($_SESSION['last_activity'] ?? time());
        if (time() - $last > self::IDLE_MINUTES * 60) {
            self::logout();
            return false;
        }
        if (!self::revalidate()) { self::logout(); return false; }
        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * إعادة التأكد إن المستخدم لسه موجود، وتحديث دوره وصلاحياته من القاعدة.
     * بنسأل مرة كل REVALIDATE_SECONDS بحد أقصى (مش كل طلب) عشان ما نحمّلش
     * القاعدة — `check()` بتتنادى كذا مرة في الطلب الواحد.
     *
     * ⚠️ ليه: قبل كده حذف مستخدم أو تنزيل دوره **ما كانش بيأثّر على جلسته
     *    المفتوحة** — كان يفضل شغّال بصلاحياته القديمة لحد ساعتين خمول.
     *    مفيش عمود `status` في `users`، فالحذف هو وسيلة الإيقاف — وده بيمسكه.
     */
    private static function revalidate(): bool
    {
        if (self::$revalidated !== null) { return self::$revalidated; }
        if (time() - (int) ($_SESSION['uid_checked'] ?? 0) < self::REVALIDATE_SECONDS) {
            return self::$revalidated = true;
        }
        try {
            $row = Database::row(
                'SELECT role, display_name, username, can_free_edit FROM users WHERE id=?',
                [(int) $_SESSION['uid']]
            );
        } catch (\Throwable $e) {
            // عطل قاعدة مؤقت ما يطردش الناس — النظام كله واقف أصلاً وقتها.
            error_log('تعذّر التأكد من حساب المستخدم: ' . $e->getMessage());
            return self::$revalidated = true;
        }
        if (!$row) { return self::$revalidated = false; }

        $_SESSION['role']          = Access::normalizeRole($row['role'] ?? 'read_only');
        $_SESSION['uname']         = $row['display_name'] ?: $row['username'];
        $_SESSION['can_free_edit'] = (int) ($row['can_free_edit'] ?? 0);
        // لو المالك سحب صلاحية «الدخول الحر»، وضع الجلسة يقع معاها فورًا.
        if ((int) $_SESSION['can_free_edit'] !== 1) { $_SESSION['free_edit_mode'] = 0; }
        $_SESSION['uid_checked'] = time();
        return self::$revalidated = true;
    }

    public static function user(): ?string
    {
        return $_SESSION['uname'] ?? null;
    }

    public static function role(): string
    {
        // ترقية الجلسات المفتوحة قبل v40 بلا إجبار المستخدم على تسجيل خروج فوري.
        if (!isset($_SESSION['role']) && !empty($_SESSION['uid'])) {
            try {
                $row = Database::row('SELECT role,display_name,username,can_free_edit FROM users WHERE id=?', [(int) $_SESSION['uid']]);
                if ($row) {
                    $_SESSION['role'] = Access::normalizeRole($row['role'] ?? 'read_only');
                    $_SESSION['uname'] = $row['display_name'] ?: $row['username'];
                    $_SESSION['can_free_edit'] = (int) ($row['can_free_edit'] ?? 0);
                }
            } catch (\Throwable $e) {
                $_SESSION['role'] = 'read_only';
            }
        }
        return Access::normalizeRole($_SESSION['role'] ?? 'read_only');
    }

    public static function roleLabel(): string
    {
        return Access::label(self::role());
    }

    public static function isOwner(): bool
    {
        return self::check() && self::role() === 'owner';
    }

    /** وضع جلسة اختياري يسمح بالكتابة على الأيام المقفولة حتى تسجيل الخروج. */
    public static function setFreeEditMode(bool $enabled): void
    {
        $_SESSION['free_edit_mode'] = ($enabled && self::canFreeEdit()) ? 1 : 0;
    }

    public static function canFreeEdit(): bool
    {
        return self::check()
            && in_array(self::role(), ['owner', 'manager', 'accountant'], true)
            && (int)($_SESSION['can_free_edit'] ?? 0) === 1;
    }

    public static function freeEditMode(): bool
    {
        return self::check() && (int)($_SESSION['free_edit_mode'] ?? 0) === 1;
    }

    public static function logout(): void
    {
        self::$revalidated = null;
        $_SESSION = [];
        // `headers_sent()` مهمة: الخروج ممكن يتنادى بعد ما بدأ الرد يتبعت
        // (مثلاً `RepAuth::check()` بيطرد جلسة منتهية جوّه صفحة بترندر)،
        // ووقتها `setcookie` بترمي تحذير. مسح الجلسة نفسه بيكون اتم فعلاً.
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'] ?: '/',
                'domain' => $p['domain'] ?? '',
                'secure' => (bool) ($p['secure'] ?? false),
                'httponly' => (bool) ($p['httponly'] ?? true),
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
    }

    // ───────────────────────────── تاريخ العمل (Working Date)
    // التاريخ الذي تُنسب إليه المدخلات الجديدة افتراضيًا (مصروف/إيصال/بوليصة…).
    // يُحدَّد عند الدخول ويظل ثابتًا طوال الجلسة حتى يغيّره المستخدم.
    //
    // ⚠️ **اختيار يوم قديم صالح ليوم واحد بس — اليوم اللي اتّخذ فيه** (إصلاح
    //    2026-07-26). قبل كده الاختيار كان **بيتجمّد للأبد**: التبويب بيفضل
    //    مفتوح، النهار بيتغيّر، والنظام يفضل يكتب على يوم عدّى بلا أي تنبيه.
    //    ده مش احتمال نظري — متحقَّق في بيانات الإنتاج: تنزيل عهدة
    //    `created_at = 2026-07-26 11:00` مخزَّن بـ`dep_date = 2026-07-25`،
    //    ونفس الحالة في 2026-07-22←21 و2026-07-21←20. النتيجة إن عهدة المندوب
    //    بتتحسب على يوم غلط وشاشته تعرض «عهدة اليوم» وهي عهدة يوم فات.
    //    الحل: نخزّن **اليوم الحقيقي اللي الاختيار اتّخذ فيه** (`work_date_set`)،
    //    وأول ما التاريخ الفعلي يتغيّر الاختيار يبطل ويرجع لليوم الحالي. اللي
    //    عايز يشتغل على يوم قديم تاني يختاره من جديد — قرار واعٍ مرة كل يوم.

    /** تاريخ العمل الحالي (Y-m-d). يرجع لتاريخ اليوم لو لم يُحدَّد أو لو الاختيار قديم. */
    public static function workDate(): string
    {
        $today = date('Y-m-d');
        $d = $_SESSION['work_date'] ?? null;
        if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { return $today; }
        if ($d === $today) { return $today; }

        // اختيار من يوم فات ⇒ باطل. بنمسحه كمان عشان الواجهة ما تفضلش تعرضه.
        $setOn = (string) ($_SESSION['work_date_set'] ?? '');
        if ($setOn !== $today) {
            unset($_SESSION['work_date'], $_SESSION['work_date_set']);
            return $today;
        }
        return $d;
    }

    /** يضبط تاريخ العمل (لا يقبل إلا صيغة Y-m-d صحيحة). */
    public static function setWorkDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
        $_SESSION['work_date'] = $date;
        // ختم اليوم الحقيقي اللي الاختيار اتّخذ فيه — بيه بنعرف إن الاختيار قديم.
        $_SESSION['work_date_set'] = date('Y-m-d');
        return true;
    }

    /** هل تاريخ العمل هو تاريخ اليوم فعلاً؟ (لإظهار تحذير البانر عند الاختلاف) */
    public static function isWorkDateToday(): bool
    {
        return self::workDate() === date('Y-m-d');
    }

    /** توكن CSRF */
    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return !empty($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    private static function securityReady(): bool
    {
        if (self::$securitySchemaReady !== null) { return self::$securitySchemaReady; }
        try {
            $cols = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE() AND table_name='users'
                   AND column_name IN ('role','failed_tries','locked_until','last_failed_at','last_login_at')"
            );
            $table = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='auth_login_attempts'"
            );
            self::$securitySchemaReady = $cols === 5 && $table === 1;
        } catch (\Throwable $e) {
            self::$securitySchemaReady = false;
        }
        return self::$securitySchemaReady;
    }

    private static function recordAttempt(string $username, bool $success): void
    {
        try {
            Database::exec(
                'INSERT INTO auth_login_attempts (username_hash,ip_hash,was_success) VALUES (?,?,?)',
                [self::hashValue(mb_strtolower(trim($username), 'UTF-8')), self::hashValue(self::clientIp()), $success ? 1 : 0]
            );
            if (random_int(1, 100) === 1) {
                Database::exec('DELETE FROM auth_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
            }
        } catch (\Throwable $e) {
            error_log('تعذّر تسجيل محاولة دخول: ' . $e->getMessage());
        }
    }

    private static function ipFailureCount(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM auth_login_attempts
             WHERE ip_hash=? AND was_success=0 AND attempted_at>=DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [self::hashValue(self::clientIp()), self::IP_WINDOW_MINUTES]
        );
    }

    private static function clientIp(): string
    {
        // لا نثق في X-Forwarded-For إلا من cloudflared المحلي.
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (in_array($remote, ['127.0.0.1', '::1'], true)) {
            $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '');
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) { return $forwarded; }
        }
        return $remote;
    }

    private static function hashValue(string $value): string
    {
        return hash('sha256', (string) (App::config('session_name') ?? 'almotawakel') . '|' . $value);
    }

    /**
     * بصمة اسم الدخول زي ما هي متخزّنة في `auth_login_attempts`.
     *
     * الأسماء والـIP بتتخزّن متشفّرة عشان السجل نفسه ما يبقاش قايمة أهداف
     * جاهزة لو حد وصل للقاعدة. لوحة المراقبة بتقلب الاتجاه بطريقة آمنة:
     * بتحسب بصمة كل حساب **معروف** (عددهم قليل) وتقارن — فبتعرف الحساب
     * المستهدَف بلا ما نخزّن أي اسم صريح.
     */
    public static function usernameHash(string $username): string
    {
        return self::hashValue(mb_strtolower(trim($username), 'UTF-8'));
    }

    /** بصمة الـIP بنفس الطريقة (بتُستخدم لتجميع المحاولات حسب المصدر). */
    public static function ipHash(string $ip): string
    {
        return self::hashValue($ip);
    }
}
