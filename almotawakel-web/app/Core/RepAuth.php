<?php
namespace App\Core;

/**
 * مصادقة المناديب (v35) — بوابة الموبايل `/m` وبس.
 *
 * ⚠️ الفكرة الأمنية الأساسية — اقرأها قبل أي تعديل:
 *
 *   جلسة المندوب **عمرها ما بتحط `$_SESSION['uid']`**. و`Auth::check()` بيدوّر
 *   على `uid` بالظبط. يعني كل صفحات المكتب (الديون/التقفيل/العهدة/المصروفات/
 *   الأرباح…) بترفض المندوب **تلقائيًا** وترميه على `/login` — من غير ما نلمس
 *   أي كنترولر من الـ30. ده بيحترم قاعدة «لا middleware» (تحذير 5): مفيش مكان
 *   واحد ممكن ننساه، لأن الرفض هو السلوك الافتراضي مش استثناء بنضيفه.
 *
 *   والعكس صحيح: `requireRep()` بيدوّر على `rep_id` بس، فالمالك ما بيدخلش
 *   بوابة المندوب بالغلط.
 *
 *   الدخول بيمسح الطرف التاني (uid ↔ rep_id) عشان لو حد فتح النظام على نفس
 *   الموبايل ما تفضلش صلاحية قديمة عالقة.
 *
 * الرقم السري متشفّر بـpassword_hash + قفل **متصاعد** + حد على مستوى الـIP.
 *
 * ⚠️ تقوية v48 (المرحلة 2 من خطة الأمان) — اقرأ قبل ما تخفّف أي رقم تحت:
 *    الفحص الأمني لقى إن البوابة دي كانت أضعف بكتير من دخول المكتب. القفل
 *    القديم كان **بيصفّر عدّاد المحاولات** وقت ما يقفل، فما كانش بيتصاعد
 *    أبدًا: 5 محاولات كل 10 دقايق **للأبد** = 720 محاولة يوميًّا على رقم سري
 *    من 4 خانات (10,000 احتمال) → الحساب بيتكسر في أسبوعين، **وبلا أي أثر
 *    في أي سجل** لأن محاولات المناديب ما كانتش بتتسجّل خالص.
 */
class RepAuth
{
    /** كل ما العدّاد يوصل مضاعف الرقم ده، الحساب يتقفل مدة أطول. */
    private const MAX_TRIES = 5;

    /**
     * مدد القفل المتصاعدة بالدقائق: أول قفل 10 دقايق، وبعدين نص ساعة،
     * وبعدين ساعتين، وبعدين 12 ساعة وتفضل كده.
     * ⚠️ العدّاد `failed_tries` **ما بيتصفّرش عند القفل** — ده بالظبط اللي
     *    بيخلّي التصعيد يشتغل. ما تصفّرهوش إلا عند دخول ناجح.
     */
    private const LOCK_STEPS = [10, 30, 120, 720];

    /** حد المحاولات الفاشلة من نفس الـIP (نفس أرقام دخول المكتب). */
    private const IP_MAX_FAILURES  = 30;
    private const IP_WINDOW_MINUTES = 10;

    /** انتهاء الجلسة بالخمول — 8 ساعات = وردية شغل. */
    private const IDLE_MINUTES = 480;

    /** كل قد إيه نتأكد إن الحساب لسه مفعّل (بالثواني). */
    private const REVALIDATE_SECONDS = 120;

    /** حدود الرقم السري. 4 خانات كانت ضعيفة جدًّا (10,000 احتمال بس). */
    public const PIN_MIN = 6;
    public const PIN_MAX = 12;

    /** هاش وهمي بيقارب زمن الحساب غير الموجود فما ينفعش نكشف الحسابات بالتوقيت. */
    private const DUMMY_HASH = '$2y$12$FcwBkiR2DgsCP/uU1pYHueh5yYl1wD5yFiHDW8aTADVATj1NFIHfi';

    private static ?bool $statusOk = null;

    public static function attempt(string $username, string $pin): array
    {
        $username = trim($username);
        $ipThrottled = self::ipFailureCount() >= self::IP_MAX_FAILURES;

        // ⚠️ `is_locked` بتتحسب **جوّه SQL** مش بمقارنة `strtotime()` مع `time()`.
        //    السبب متحقَّق فعليًا على الإنتاج: MySQL بتشتغل بـUTC وPHP بـAfrica/Cairo
        //    (فرق 3 ساعات)، فالقفل اللي بتكتبه MySQL كـ`NOW()+10 دقايق` كان PHP
        //    تقراه على إنه **عدّى من 170 دقيقة** — يعني **القفل عمره ما اشتغل
        //    على الإنتاج خالص**. المقارنة جوّه SQL الطرفين فيها على نفس الساعة،
        //    فبتفضل صح مهما كان إعداد التوقيت. ما ترجّعش المقارنة لـPHP.
        $row = Database::row(
            "SELECT ru.*, r.name AS rep_name, r.status AS rep_status,
                    (ru.locked_until IS NOT NULL AND ru.locked_until > NOW()) AS is_locked
             FROM rep_users ru
             JOIN reps r ON r.id = ru.rep_id
             WHERE ru.username = ?", [$username]);

        // ⚠️ رسالة واحدة لكل حالات الفشل. الرسايل القديمة («الحساب متوقف»،
        //    «فاضلك 3 محاولات») كانت بتقول للمهاجم إن الحساب موجود وشغّال —
        //    وأسماء الدخول متوقَّعة أصلاً (rep1, rep2…). زي `Auth::failureMessage`.
        $generic  = ['ok' => false, 'msg' => 'اسم الدخول أو الرقم السري غلط.'];
        $tooMany  = ['ok' => false, 'msg' => 'محاولات كتير. استنى شوية وحاول تاني، أو كلّم المكتب.'];

        $locked = $row && (int) ($row['is_locked'] ?? 0) === 1;
        $active = $row && $row['status'] === 'active' && $row['rep_status'] === 'active';

        // بنتحقّق من الرقم السري دايمًا (حتى للحساب المش موجود) عشان الزمن يفضل ثابت.
        $pinOk = $row
            ? password_verify($pin, (string) $row['pin_hash'])
            : (password_verify($pin, self::DUMMY_HASH) && false);

        if ($locked) {
            self::record($username, false);
            return $tooMany;
        }
        if ($ipThrottled) {
            // بنبطّئ ومنزوّدش عدّاد الحساب، عشان مهاجم من برّه ما يعرفش يقفل
            // حسابات المناديب على شبكة المكتب المشتركة (نفس منطق Auth).
            self::record($username, false);
            usleep(750000);
            return $tooMany;
        }
        if (!$row || !$active || !$pinOk) {
            if ($row && $active) { self::registerFailure($row); }
            self::record($username, false);
            return $generic;
        }

        // نجح — نظّف الجلسة القديمة بالكامل قبل ما نبني جلسة المندوب
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['rep_id']    = (int) $row['rep_id'];
        $_SESSION['rep_name']  = $row['rep_name'];
        $_SESSION['rep_user']  = $row['username'];
        $_SESSION['rep_seen']  = time();
        $_SESSION['rep_check'] = time();
        // تاريخ العمل الافتراضي = النهاردة؛ المندوب بيسأله من `/m/work-date` عقب الدخول.
        $_SESSION['rep_work_date'] = date('Y-m-d');
        $_SESSION['rep_work_date_set'] = date('Y-m-d');

        Database::exec(
            "UPDATE rep_users SET failed_tries = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?",
            [$row['id']]);
        self::record($username, true);

        return ['ok' => true, 'msg' => ''];
    }

    /** يزوّد العدّاد، ويقفل مدة أطول كل ما يوصل مضاعف جديد لـMAX_TRIES. */
    private static function registerFailure(array $row): void
    {
        $tries = (int) $row['failed_tries'] + 1;
        if ($tries % self::MAX_TRIES !== 0) {
            Database::exec("UPDATE rep_users SET failed_tries = ? WHERE id = ?", [$tries, $row['id']]);
            return;
        }
        $step    = intdiv($tries, self::MAX_TRIES) - 1;
        $minutes = self::LOCK_STEPS[min($step, count(self::LOCK_STEPS) - 1)];
        Database::exec(
            "UPDATE rep_users SET failed_tries = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
             WHERE id = ?",
            [$tries, $minutes, $row['id']]
        );
    }

    /**
     * هل الجلسة الحالية جلسة مندوب سليمة؟
     *
     * ⚠️ الدالة دي هي نقطة الاختناق الوحيدة: `RepPortalController::requireRep()`
     *    بينادّيها قبل كل صفحة، فأي تشديد هنا بيسري على البوابة كلها تلقائيًّا
     *    بلا لمس أي كنترولر (نفس فلسفة تحذير 5).
     */
    public static function check(): bool
    {
        if (empty($_SESSION['rep_id'])) { return false; }

        $seen = (int) ($_SESSION['rep_seen'] ?? time());
        if (time() - $seen > self::IDLE_MINUTES * 60) { self::logout(); return false; }

        if (!self::stillActive()) { self::logout(); return false; }

        $_SESSION['rep_seen'] = time();
        return true;
    }

    /**
     * الحساب لسه مفعّل؟ بنسأل القاعدة مرة كل REVALIDATE_SECONDS بحد أقصى.
     * ⚠️ قبل كده كان إيقاف حساب المندوب **ما بيقطعش جلسته المفتوحة** — كان
     *    يفضل يسجّل صرف على العهدة عادي لحد ما الجلسة تموت لوحدها.
     */
    private static function stillActive(): bool
    {
        if (self::$statusOk !== null) { return self::$statusOk; }
        if (time() - (int) ($_SESSION['rep_check'] ?? 0) < self::REVALIDATE_SECONDS) {
            return self::$statusOk = true;
        }
        try {
            $ok = (int) Database::scalar(
                "SELECT COUNT(*) FROM rep_users ru JOIN reps r ON r.id = ru.rep_id
                 WHERE ru.rep_id = ? AND ru.status = 'active' AND r.status = 'active'",
                [(int) $_SESSION['rep_id']]
            ) === 1;
        } catch (\Throwable $e) {
            // عطل قاعدة مؤقت ما يطردش المناديب من الشغل — النظام كله واقف أصلاً وقتها.
            error_log('تعذّر التأكد من حالة حساب المندوب: ' . $e->getMessage());
            return self::$statusOk = true;
        }
        if ($ok) { $_SESSION['rep_check'] = time(); }
        return self::$statusOk = $ok;
    }

    // ── تسجيل المحاولات وحد الـIP ──────────────────────────────────────────
    // بنستخدم نفس جدول `auth_login_attempts` بتاع المكتب، مع بادئة نطاق في
    // الهاش عشان محاولات المناديب ما تختلطش بمحاولات المكتب.

    private static function record(string $username, bool $success): void
    {
        try {
            Database::exec(
                'INSERT INTO auth_login_attempts (username_hash,ip_hash,was_success) VALUES (?,?,?)',
                [
                    self::hashValue('rep|' . mb_strtolower(trim($username), 'UTF-8')),
                    self::hashValue(self::clientIp()),
                    $success ? 1 : 0,
                ]
            );
        } catch (\Throwable $e) {
            // الجدول بيتعمل في migrate_v40 — قبل كده الدخول يفضل شغّال بلا تسجيل.
            error_log('تعذّر تسجيل محاولة دخول مندوب: ' . $e->getMessage());
        }
    }

    private static function ipFailureCount(): int
    {
        try {
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM auth_login_attempts
                 WHERE ip_hash=? AND was_success=0 AND attempted_at>=DATE_SUB(NOW(), INTERVAL ? MINUTE)',
                [self::hashValue(self::clientIp()), self::IP_WINDOW_MINUTES]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** لا نثق في X-Forwarded-For إلا من cloudflared المحلي (زي Auth::clientIp). */
    private static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (in_array($remote, ['127.0.0.1', '::1'], true)) {
            $fwd = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '');
            if (filter_var($fwd, FILTER_VALIDATE_IP)) { return $fwd; }
        }
        return $remote;
    }

    private static function hashValue(string $value): string
    {
        return hash('sha256', (string) (App::config('session_name') ?? 'almotawakel') . '|' . $value);
    }

    /**
     * بصمة اسم دخول المندوب زي ما هي متخزّنة في `auth_login_attempts`.
     * ⚠️ لازم تفضل مطابقة للي في `record()` بالظبط (بادئة `rep|` قبل التشفير)
     *    وإلا لوحة المراقبة هتفشل تربط المحاولات بالمندوب.
     */
    public static function usernameHash(string $username): string
    {
        return self::hashValue('rep|' . mb_strtolower(trim($username), 'UTF-8'));
    }

    public static function repId(): int
    {
        return (int) ($_SESSION['rep_id'] ?? 0);
    }

    public static function repName(): string
    {
        return (string) ($_SESSION['rep_name'] ?? '');
    }

    /**
     * «تاريخ العمل» للمندوب — نفس فكرة `Auth::workDate()` بتاعة المكتب، بس
     * على مفتاح جلسة مستقل `rep_work_date`. كل بند (إيصال/مصروف/نثرية) بيتسجّل
     * افتراضيًا بالتاريخ ده. لو مش متضبوط أو صيغته غلط → النهاردة.
     *
     * ⚠️ مفتاح منفصل عن `work_date` بتاع المكتب عن قصد: جلسة المندوب ما فيهاش
     *    `uid`، والخلط بين المفتاحين ممكن يخلط تاريخ عمل الاتنين لو حد فتح
     *    النظامين على نفس الموبايل.
     *
     * ⚠️ **الاختيار صالح ليوم واحد بس — اليوم اللي اتّخذ فيه** (إصلاح 2026-07-26،
     *    نفس منطق `Auth::workDate`). ده أخطر عند المندوب من المكتب: التطبيق
     *    مثبَّت على الموبايل وبيفضل مفتوح أيامًا، والجلسة بتتمدّد مع كل فتحة
     *    (`rep_seen`) — فكان اليوم اللي دخل بيه بيتجمّد، والشاشة تعرض «فاضل من
     *    عهدة اليوم» وهي **عهدة يوم فات**، وأي بند يسجّله يتكتب على اليوم القديم.
     *    ده بالظبط اللي بلّغ عنه المالك: «باقي العهدة لسه بيحسب من يوم سابق».
     */
    public static function workDate(): string
    {
        $today = date('Y-m-d');
        $d = (string) ($_SESSION['rep_work_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { return $today; }
        if ($d === $today) { return $today; }

        $setOn = (string) ($_SESSION['rep_work_date_set'] ?? '');
        if ($setOn !== $today) {
            // اختيار من يوم فات ⇒ باطل، ويُمسح عشان الشاشة ما تفضلش تكدب عليه.
            unset($_SESSION['rep_work_date'], $_SESSION['rep_work_date_set']);
            return $today;
        }
        return $d;
    }

    /** يحفظ تاريخ العمل بعد التحقق من الصيغة ومنع المستقبل (زي max=today في الفورم). */
    public static function setWorkDate(string $date): bool
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
        if ($date > date('Y-m-d')) { return false; }
        $_SESSION['rep_work_date'] = $date;
        // ختم اليوم الحقيقي اللي الاختيار اتّخذ فيه (بيه بنعرف إنه بطل بعد نص الليل)
        $_SESSION['rep_work_date_set'] = date('Y-m-d');
        return true;
    }

    public static function isWorkDateToday(): bool
    {
        return self::workDate() === date('Y-m-d');
    }

    /**
     * خروج كامل. بننادي `Auth::logout()` عن قصد — هي بتمسح الجلسة **وبتنهي
     * الكوكي كمان**، وده اللي كان ناقص هنا (الكوكي القديمة كانت بتفضل في
     * المتصفح). الدالة دي ما بتلمسش `uid` فآمن نعيد استخدامها للمندوب.
     */
    public static function logout(): void
    {
        self::$statusOk = null;
        Auth::logout();
    }

    /**
     * إنشاء/تحديث حساب دخول لمندوب (من صفحة المكتب).
     * الرقم السري: 6 أرقام على الأقل (v48 — كان 4).
     */
    public static function setCredentials(int $repId, string $username, string $pin): array
    {
        $username = trim($username);
        $pin      = trim($pin);
        $exists   = Database::row("SELECT id FROM rep_users WHERE rep_id = ?", [$repId]);

        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
            return ['ok' => false, 'pin_changed' => false,
                    'msg' => 'اسم الدخول لازم يكون حروف إنجليزية وأرقام (3 خانات على الأقل) بلا مسافات.'];
        }
        // الرقم السري إلزامي للحساب الجديد بس — في التعديل، فاضي = سيبه زي ما هو
        $range = self::PIN_MIN . ' لـ' . self::PIN_MAX;
        if ($pin === '' && !$exists) {
            return ['ok' => false, 'pin_changed' => false,
                    'msg' => "اكتب رقم سري للحساب الجديد (من {$range} رقم)."];
        }
        // ⚠️ v48: الحد الأدنى بقى 6 مش 4. أربع خانات = 10,000 احتمال بس، وده
        //    كان بيتكسر في أسبوعين بمحاولات بطيئة. ما ترجّعهاش 4.
        if ($pin !== '' && !preg_match('/^\d{' . self::PIN_MIN . ',' . self::PIN_MAX . '}$/', $pin)) {
            return ['ok' => false, 'pin_changed' => false,
                    'msg' => "الرقم السري لازم يكون أرقام من {$range} خانة."];
        }
        $taken = Database::scalar(
            "SELECT COUNT(*) FROM rep_users WHERE username = ? AND rep_id <> ?", [$username, $repId]);
        if ($taken) {
            return ['ok' => false, 'pin_changed' => false, 'msg' => 'اسم الدخول ده مستخدم لمندوب تاني.'];
        }

        // أعمدة v48 اختيارية: لو الهجرة لسه ما اتشغّلتش النظام يفضل شغّال بلا
        // متابعة قِدم الرقم السري (نفس نمط visaReady/excludeSql في الوحدات التانية).
        $meta = self::pinMetaReady() ? ', weak_pin = 0, pin_set_at = NOW()' : '';

        if (!$exists) {
            Database::exec(
                self::pinMetaReady()
                    ? "INSERT INTO rep_users (rep_id, username, pin_hash, weak_pin, pin_set_at)
                       VALUES (?,?,?,0,NOW())"
                    : "INSERT INTO rep_users (rep_id, username, pin_hash) VALUES (?,?,?)",
                [$repId, $username, password_hash($pin, PASSWORD_DEFAULT)]);
            return ['ok' => true, 'pin_changed' => true, 'msg' => 'اتعمل حساب المندوب.'];
        }

        if ($pin === '') {
            // اسم الدخول بس — الرقم السري ما اتلمسش
            Database::exec("UPDATE rep_users SET username = ? WHERE rep_id = ?", [$username, $repId]);
            return ['ok' => true, 'pin_changed' => false,
                    'msg' => 'اتغيّر اسم الدخول. الرقم السري زي ما هو — ابعتله الاسم الجديد.'];
        }

        Database::exec(
            "UPDATE rep_users SET username = ?, pin_hash = ?, status='active',
                    failed_tries = 0, locked_until = NULL{$meta} WHERE rep_id = ?",
            [$username, password_hash($pin, PASSWORD_DEFAULT), $repId]);
        return ['ok' => true, 'pin_changed' => true, 'msg' => 'اتحفظت بيانات الدخول.'];
    }

    /** هل أعمدة متابعة الرقم السري (v48) موجودة؟ */
    public static function pinMetaReady(): bool
    {
        static $ready = null;
        if ($ready !== null) { return $ready; }
        try {
            $ready = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'rep_users'
                   AND column_name IN ('weak_pin','pin_set_at')"
            ) === 2;
        } catch (\Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    /**
     * رقم سري عشوائي 6 أرقام (بيتجنّب الأنماط السخيفة والمتكرّرة والمتسلسلة).
     * random_int آمن تشفيريًا — مش mt_rand.
     *
     * ⚠️ v48: كان 4 أرقام (10,000 احتمال). 6 أرقام = مليون احتمال، ومع القفل
     *    المتصاعد بقى الكسر بالتخمين غير عملي.
     */
    public static function randomPin(): string
    {
        $max = (10 ** self::PIN_MIN) - 1;
        do {
            $pin = str_pad((string) random_int(0, $max), self::PIN_MIN, '0', STR_PAD_LEFT);
        } while (self::weakPattern($pin));
        return $pin;
    }

    /** أنماط سهلة التخمين: كل الأرقام واحدة، أو متسلسلة طالعة/نازلة. */
    private static function weakPattern(string $pin): bool
    {
        if (preg_match('/^(\d)\1+$/', $pin)) { return true; }
        $up = $down = true;
        for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
            if ((int) $pin[$i] !== (int) $pin[$i - 1] + 1) { $up = false; }
            if ((int) $pin[$i] !== (int) $pin[$i - 1] - 1) { $down = false; }
        }
        return $up || $down;
    }

    /**
     * اسم دخول مقترح. أسماء المناديب عربية واسم الدخول لازم لاتيني، والترجمة
     * الصوتية بتطلع أسامي غريبة — فبنستخدم rep{id}: قصير، فريد، وسهل يتقال في التليفون.
     */
    public static function suggestUsername(int $repId): string
    {
        return 'rep' . $repId;
    }

    /**
     * نص رسالة الواتساب: الرابط + البيانات + التعليمات.
     * ⚠️ الرقم السري بيتبعت هنا **مرة واحدة بس** لحظة إنشائه — لأنه متشفّر في
     *    القاعدة ومستحيل نستخرجه بعدين. لو ضاع منه، اعمل رقم جديد وابعته.
     */
    public static function waSetupMessage(string $repName, string $username, string $pin, string $link): string
    {
        $lines = [];
        $lines[] = 'السلام عليكم' . ($repName !== '' ? ' يا ' . $repName : '') . ' 👋';
        $lines[] = 'دي بيانات دخولك على نظام المتوكل من موبايلك:';
        $lines[] = '';
        $lines[] = '🔗 الرابط: ' . $link;
        $lines[] = '👤 اسم الدخول: ' . $username;
        $lines[] = '🔑 الرقم السري: ' . $pin;
        $lines[] = '';
        $lines[] = 'الطريقة:';
        $lines[] = '1) افتح الرابط من موبايلك.';
        $lines[] = '2) ادخل باسم الدخول والرقم السري.';
        $lines[] = '3) هتلاقي فوق «معايا كام» — دي عهدتك.';
        $lines[] = '4) تحتها أذوناتك — دوس «سجّل بند» على الإذن اللي دفعت عليه.';
        $lines[] = '5) اكتب المبلغ اللي دفعته، وصوّر الإيصال، ودوس «سجّل البند».';
        $lines[] = '';
        $lines[] = '⚠️ الرقم السري ده ليك انت بس — متديهوش لحد.';
        $lines[] = 'شركة المتوكل';
        return implode("\n", $lines);
    }

    /** رابط واتساب جاهز، أو '' لو الرقم غلط/مش موجود. */
    public static function waLink(string $phone, string $message): string
    {
        $wa = wa_phone($phone);
        if ($wa === '') { return ''; }
        return 'https://wa.me/' . $wa . '?text=' . rawurlencode($message);
    }

    /** بيانات حساب مندوب (بلا الهاش) أو null. */
    public static function accountFor(int $repId): ?array
    {
        $extra = self::pinMetaReady() ? ', weak_pin, pin_set_at' : '';
        $row = Database::row(
            "SELECT id, rep_id, username, status, last_login_at, locked_until{$extra}
             FROM rep_users WHERE rep_id = ?", [$repId]);
        if ($row && !self::pinMetaReady()) { $row['weak_pin'] = 0; $row['pin_set_at'] = null; }
        return $row;
    }

    /** عدد حسابات المناديب اللي لسه برقم سري قديم (4 خانات) — لتنبيه المكتب. */
    public static function weakPinCount(): int
    {
        if (!self::pinMetaReady()) { return 0; }
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM rep_users WHERE weak_pin = 1 AND status = 'active'");
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function setStatus(int $repId, bool $active): void
    {
        Database::exec("UPDATE rep_users SET status = ? WHERE rep_id = ?",
            [$active ? 'active' : 'disabled', $repId]);
    }
}
