<?php
namespace App\Models;

use App\Core\App;
use RuntimeException;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * قفل بوابة المندوب للصيانة (v68) — **المصدر الوحيد** لسؤال «البوابة مقفولة؟»
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * المكتب بيقفل `/m` وهو بيظبّط حاجة، المناديب بياخدوا رنّة إن الشغل واقف
 * شوية، وأول ما يفتحها بياخدوا رنّة تانية والتطبيق المفتوح في إيدهم بيرجع
 * لوحده بلا ما حد يعمل تحديث.
 *
 * ⚠️ **مفيش هجرة عن قصد** — ده إعداد مش بيانات، فعايش في جدول `settings`
 *    الموجود أصلاً عبر `App::setting()/setSetting()`. جدول جديد لإعداد واحد
 *    كان هيبقى دَينًا بلا مكسب.
 *
 * ⚠️ **الوقت متخزَّن epoch رقمي لا نص تاريخ.** الفرق مش شكلي: قاعدة الإنتاج
 *    بتشتغل UTC وPHP بتوقيت القاهرة (فرق ٣ ساعات)، فأي مقارنة بين نص تاريخ
 *    من القاعدة و`time()` بتطلع غلط ٣ ساعات (نفس الباج اللي عطّل قفل الدخول
 *    في `RepAuth`). الـepoch هنا **اتكتب بـ`time()` وبيتقارن بـ`time()`** —
 *    الطرفين من ساعة PHP نفسها، فمصيدة التوقيت مستحيلة من أصلها.
 *
 * ⚠️ **الصيانة ما بتعملش تسجيل خروج للمندوب.** ولا سطر هنا بيلمس `$_SESSION`.
 *    السبب عملي: المندوب ممكن يكون كاتب بند نص ومسودته متخزّنة في
 *    `localStorage` تحت مفتاح مربوط بجلسته — الطرد كان هيضيّع شغله في الميدان.
 *    القفل **حجب شاشات** بس؛ الجلسة والمسودة بيفضلوا زي ما هُمّ.
 *
 * ⚠️ **صفر أثر مالي.** الوحدة دي ما بتقراش ولا بتكتب أي رصيد ولا خزنة ولا
 *    عهدة — ولا حتى بتلمس جدولهم. `bin/verify_rep_maintenance.php` بيفشل لو
 *    أي استعلام مالي دخل الملف ده.
 */
class RepMaintenance
{
    /** مفاتيح `settings` — ما تقراهاش بإيدك في أي مكان تاني. */
    private const K_ON      = 'rep_portal_maint';
    private const K_MSG     = 'rep_portal_maint_msg';
    private const K_UNTIL   = 'rep_portal_maint_until';    // epoch (0 = بلا وقت محدَّد)
    private const K_STARTED = 'rep_portal_maint_started';  // epoch

    /** أطول مدة صيانة مسموح بيها لمرّة واحدة (١٢ ساعة). */
    public const MAX_MINUTES = 720;

    /** أقصر مدة — أقل من كده الرنّة بتوصل بعد ما الصيانة تكون خلصت. */
    public const MIN_MINUTES = 5;

    /**
     * البوابة مقفولة دلوقتي؟ — **السؤال الوحيد المسموح**.
     *
     * ⚠️ الانتهاء التلقائي بيتحسب هنا مش في العامل الخلفي بس. لو الوقت
     *    المحدَّد عدّى، البوابة **بتفتح فورًا** حتى لو العامل واقف أو
     *    الاستضافة مقفّلة تشغيل العمليات (وهي مقفّلة فعلاً على الإنتاج).
     *    العامل شغله يبعت رنّة «رجعنا» ويصفّر المفتاح، مش يفتح البوابة.
     */
    public static function isOn(): bool
    {
        if ((string) App::setting(self::K_ON, '0') !== '1') { return false; }
        $until = self::untilTs();
        return $until === 0 || time() < $until;
    }

    /** الحالة الكاملة للعرض (شاشة الصيانة · شريط المكتب · فورم الإعدادات). */
    public static function state(): array
    {
        $flag  = (string) App::setting(self::K_ON, '0') === '1';
        $until = self::untilTs();
        $due   = $flag && $until > 0 && time() >= $until;   // اتقفلت ووقتها عدّى
        return [
            'on'      => $flag && !$due,
            'flagged' => $flag,          // المفتاح لسه مرفوع (حتى لو الوقت عدّى)
            'due'     => $due,           // محتاج العامل يصفّره ويبعت «رجعنا»
            'message' => trim((string) App::setting(self::K_MSG, '')),
            'until'   => $until,
            'started' => (int) App::setting(self::K_STARTED, '0'),
        ];
    }

    /** كام دقيقة فاضلة؟ (0 = مفيش وقت محدَّد أو عدّى) */
    public static function minutesLeft(): int
    {
        $until = self::untilTs();
        if ($until === 0) { return 0; }
        return max(0, (int) ceil(($until - time()) / 60));
    }

    /**
     * اقفل البوابة.
     *
     * @param string $msg     رسالة المكتب للمندوب (اختيارية).
     * @param int    $minutes المدة المتوقَّعة بالدقايق (0 = مفتوح لحد ما نقفله بإيدنا).
     * @param string $by      مين قفلها — للسجل والإشعار.
     */
    public static function start(string $msg, int $minutes, string $by): void
    {
        $msg = trim(mb_substr(trim($msg), 0, 200));
        if ($minutes > 0) {
            $minutes = max(self::MIN_MINUTES, min(self::MAX_MINUTES, $minutes));
        }
        $until = $minutes > 0 ? time() + ($minutes * 60) : 0;

        App::setSetting(self::K_ON, '1');
        App::setSetting(self::K_MSG, $msg);
        App::setSetting(self::K_UNTIL, (string) $until);
        App::setSetting(self::K_STARTED, (string) time());

        Audit::log('maintenance_on', 'rep_portal', 0, null, null,
            'قفل بوابة المناديب للصيانة' . ($minutes > 0 ? " ({$minutes} دقيقة)" : ''),
            ['by' => $by]);

        /* ⚠️ الرنّة **بعد** ما المفتاح يترفع فعلاً. العكس (نبعت وبعدين نقفل)
           كان ممكن يبعت خبرًا عن قفل ما حصلش لو `setSetting` وقعت. */
        PushNotify::repMaintenance(true, $msg, $minutes, $by);
    }

    /**
     * افتح البوابة تاني.
     *
     * @param bool $auto جه من العامل الخلفي بعد ما الوقت المحدَّد عدّى؟
     */
    public static function end(string $by, bool $auto = false): void
    {
        if ((string) App::setting(self::K_ON, '0') !== '1') { return; }   // مقفولة أصلاً

        App::setSetting(self::K_ON, '0');
        App::setSetting(self::K_UNTIL, '0');
        App::setSetting(self::K_STARTED, '0');
        $msg = trim((string) App::setting(self::K_MSG, ''));
        App::setSetting(self::K_MSG, '');

        Audit::log('maintenance_off', 'rep_portal', 0, null, null,
            $auto ? 'فتح بوابة المناديب تلقائيًّا (الوقت المحدَّد عدّى)' : 'فتح بوابة المناديب',
            ['by' => $by]);

        PushNotify::repMaintenance(false, $msg, 0, $by);
    }

    /**
     * العامل الخلفي: لو المفتاح لسه مرفوع والوقت عدّى → افتح وابعت «رجعنا».
     *
     * ⚠️ **مش هي اللي بتفتح البوابة** — `isOn()` بتفتحها لحظة ما الوقت يعدّي
     *    بلا انتظار حد. الدالة دي بتنضّف المفتاح وتبعت الرنّة وبس، فتأخّرها
     *    أو وقوعها ما بيسيبش المناديب مقفول عليهم.
     *
     * @return bool اتصفّر فعلاً؟
     */
    public static function expireIfDue(string $by = 'النظام'): bool
    {
        $s = self::state();
        if (!$s['due']) { return false; }
        self::end($by, true);
        return true;
    }

    /**
     * الحارس العميق — **الفورم مش مصدر ثقة** (قاعدة ③).
     *
     * `RepPortalController::requireRep()` بيحجب الشاشات، لكن POST مباشر مزوّر
     * من برّه المتصفح ما بيمرّش على شاشة. الدالة دي بتتنادى في نقطة الكتابة
     * نفسها فالرفض بيحصل قبل أي صف يتكتب.
     */
    public static function assertOpen(): void
    {
        if (self::isOn()) {
            throw new RuntimeException('النظام بيتظبّط دلوقتي — استنى شوية وحاول تاني.');
        }
    }

    /** نص «متوقع يرجع الساعة …» أو '' لو مفيش وقت محدَّد. */
    public static function backAtLabel(): string
    {
        $until = self::untilTs();
        if ($until === 0) { return ''; }
        return date('g:i', $until) . (date('A', $until) === 'AM' ? ' ص' : ' م');
    }

    private static function untilTs(): int
    {
        return (int) App::setting(self::K_UNTIL, '0');
    }
}
