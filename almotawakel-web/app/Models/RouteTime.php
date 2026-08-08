<?php
namespace App\Models;

use App\Core\Database;

/**
 * وقت الوصول المتوقّع من الميناء للوجهة (v64).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️⚠️ **تقدير مش وعد** — نفس قاعدة v34 بالحرف.
 * ═══════════════════════════════════════════════════════════════════════════
 * ما بيمنعش أي إدخال ولا بيغيّر أي حساب ولا بيمسّ خزنة/عهدة/تقفيل. بيتعرض
 * بصيغة «~٥ ساعات» لا وقت مضبوط، والشاشة بتقول إنه تقدير.
 *
 * ═══ مصدر الرقم — على مستويين بترتيب الثقة ═══
 *   ① **مسافة طريق حقيقية** من `route_times` (مبذور بالطرق المعروفة) → `exact`
 *   ② **خط مستقيم × معامل طريق** من إحداثيات `destination_points` → `approx`
 * ولو الاتنين فشلوا بترجّع `null` — **الشاشة بتقول «مش متحدّدة» بدل ما تعرض
 * رقمًا مخترعًا** (نفس قرار v34: وجهة مجهولة ما بتتخمّنش).
 *
 * ⚠️ **السرعة سرعة شاحنة محمّلة لا سيارة**: ٥٥ كم/س على الطريق + إضافة ثابتة
 *    لبوابة الميناء والكمائن ودخول المدينة. الأرقام في إعدادات فيتعدّلوا بلا كود.
 */
class RouteTime
{
    /** سرعة الشاحنة المحمّلة على الطرق السريعة (كم/ساعة). */
    private const SPEED_KMH = 55.0;

    /** إضافة ثابتة بالدقايق: بوابة الميناء + الكمائن + دخول المدينة. */
    private const OVERHEAD_MIN = 75;

    /** معامل تحويل الخط المستقيم لمسافة طريق (نفس معامل v34). */
    private const ROAD_FACTOR = 1.3;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'route_times'");
        }
        return self::$ready;
    }

    /** تصفير الكاش بعد الترحيل داخل نفس الطلب (مصيدة كاش المخطط — v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null;
        return self::ready();
    }

    public static function available(): bool { return self::ready(); }

    /**
     * مفتاح الميناء — «ميناء الإسكندرية» و«الإسكندرية» و«ميناء الدخيلة» كلهم
     * نفس المنطقة تقريبًا (فرق ٢٠ كم على ٢٥٠)، فبيتجمّعوا على مفتاح واحد.
     *
     * ⚠️ التجميع ده **مقصود**: بذر طريق منفصل لكل ميناء كان هيضاعف الجدول
     *    ويدّي نفس الرقم تقريبًا. أي ميناء بعيد فعلاً (بورسعيد · السخنة) ليه
     *    مفتاحه هو لما يتبذر.
     */
    public static function portKey(string $port): string
    {
        $k = Maintenance::normkey($port);
        if ($k === '') { return ''; }
        foreach (['اسكندر' => 'الإسكندرية', 'دخيل' => 'الإسكندرية',
                  'ابوقير' => 'الإسكندرية', 'ابو قير' => 'الإسكندرية'] as $needle => $canon) {
            if (str_contains($k, $needle)) { return Maintenance::normkey($canon); }
        }
        return $k;
    }

    /**
     * التقدير من ميناء لوجهة.
     *
     * @return array{km:int,minutes:int,source:string}|null
     *         `source`: exact (مسافة طريق) · approx (خط مستقيم × معامل)
     */
    public static function estimate(?string $port, ?string $destination): ?array
    {
        $to = Maintenance::normkey((string) $destination);
        if ($to === '') { return null; }

        // ① مسافة طريق حقيقية
        if (self::ready()) {
            $from = self::portKey((string) $port);
            if ($from !== '') {
                $km = Database::scalar(
                    'SELECT km FROM route_times WHERE from_key = ? AND to_key = ?', [$from, $to]);
                if ($km !== null && $km !== false) {
                    return self::pack((int) $km, 'exact');
                }
            }
        }

        // ② خط مستقيم × معامل طريق — بيحتاج إحداثيات الطرفين
        $a = self::point((string) $port);
        $b = self::point((string) $destination);
        if ($a === null || $b === null) { return null; }
        $km = (int) round(self::haversine($a[0], $a[1], $b[0], $b[1]) * self::ROAD_FACTOR);
        if ($km <= 0) { return null; }
        return self::pack($km, 'approx');
    }

    /** الوقت من المسافة — سرعة شاحنة + إضافة ثابتة، مقرَّب لأقرب ربع ساعة. */
    private static function pack(int $km, string $source): array
    {
        $min = (int) round(($km / self::SPEED_KMH) * 60) + self::OVERHEAD_MIN;
        $min = (int) (round($min / 15) * 15);
        return ['km' => $km, 'minutes' => max(15, $min), 'source' => $source];
    }

    /**
     * إحداثيات مكان من `destination_points` — بتستخدم **نفس مُحلِّل v34**
     * (`Track::resolveDestination`) فمفيش تعريفين لمطابقة الأماكن.
     *
     * @return array{0:float,1:float}|null
     */
    private static function point(string $name): ?array
    {
        if (trim($name) === '') { return null; }
        $row = Track::resolveDestination($name);
        if (!$row || $row['lat'] === null || $row['lng'] === null) { return null; }
        return [(float) $row['lat'], (float) $row['lng']];
    }

    /** المسافة بالخط المستقيم بالكيلومتر. */
    private static function haversine(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $r = 6371.0;
        $dLa = deg2rad($la2 - $la1);
        $dLo = deg2rad($lo2 - $lo1);
        $h = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
        return $r * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    /** «~٤ ساعات ونص» — صيغة عربية مفهومة بلا دقّة كاذبة. */
    public static function humanDuration(int $minutes): string
    {
        if ($minutes < 60) { return 'حوالي ' . $minutes . ' دقيقة'; }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $hw = $h === 1 ? 'ساعة' : ($h === 2 ? 'ساعتين' : ($h <= 10 ? $h . ' ساعات' : $h . ' ساعة'));
        if ($m === 0)  { return 'حوالي ' . $hw; }
        if ($m === 30) { return 'حوالي ' . $hw . ' ونص'; }
        if ($m === 15) { return 'حوالي ' . $hw . ' وربع'; }
        if ($m === 45) { return 'حوالي ' . $hw . ' وتلت أرباع'; }
        return 'حوالي ' . $hw . ' و' . $m . ' دقيقة';
    }
}
