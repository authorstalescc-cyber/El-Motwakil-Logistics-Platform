<?php
namespace App\Models;

use App\Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * تعريفة النولون — قائمة الأسعار المعتمدة ومطابقتها على الإذن (v102)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * طلب المالك: «القيمة الفعلية للنولون بتتحسب وفقًا للجهة والميناء وتصنيف
 * الحاويات ومبرّد ولا لأ» — فالمطابقة بتمشي على الأربعة دول بالحرف.
 *
 * ⚠️ **الوحدة سيارة لا حاوية** — بتمرّ على `Freight` (المصدر الوحيد لتحويل
 *    «حاويات ← سيارات» من v96). عمود «2×20» في عرض الأسعار معناه **سيارة
 *    شايلة حاويتين ٢٠**، مش حاويتين بسعرين. أي حساب هنا بيقسم على عدد
 *    الحاويات بدل السيارات بيدّي نص السعر بالسكوت.
 *
 * ⚠️ **الصمت أنضف من التخمين — والفلوس أولى الناس بالقاعدة دي.** الجهة اللي
 *    مالهاش سطر في العرض، أو الميناء اللي مش في القوايم (دمياط مثلاً)، بترجّع
 *    `null` وخلاص. المطابقة التقريبية بترجع **موسومة** (`near`) وما بتتحطّش
 *    في الخانة أبدًا — بتتعرض للمالك يقرّر. سعر غلط في فاتورة أسوأ بكتير من
 *    خانة فاضية.
 *
 * ⚠️ **التعريفة بتُقرأ بتاريخ الإذن لا بتاريخ النهارده.** أسعار ٢٠٢٧ لما تتحط
 *    ما يصحّش تغيّر تفسير إذن ٢٠٢٦ — عشان كده `effective_from` جزء من المفتاح
 *    والاختيار بيتم بـ«أحدث تعريفة سارية في تاريخه».
 */
final class FreightTariff
{
    /** مجموعات الموانئ — الاسم اللي بيتعرض. */
    public const PORT_GROUPS = [
        'alex'     => 'موانئ الإسكندرية (الإسكندرية · الدخيلة · أبو قير)',
        'psd_east' => 'ميناء بورسعيد (شرق)',
        'psd_west' => 'ميناء بورسعيد (غرب)',
    ];

    /** الوحدات — الاسم المعروض لكل واحدة. */
    public const UNITS = [
        '20'            => 'حاوية 20 قدم',
        '40'            => 'حاوية 40 قدم',
        '2x20'          => 'سيارة بحاويتين 20 قدم',
        'reefer40'      => 'ثلاجة 40 قدم',
        'genset'        => 'مولّد كهربائي (Genset)',
        'jumbo_closed'  => 'جامبو 4 متر — مقفول',
        'jumbo_open'    => 'جامبو 4 متر — مفتوح',
        'dababa_closed' => 'دبابة — مقفول',
        'dababa_open'   => 'دبابة — مفتوح',
    ];

    /** فواصل أسماء الجهات جوّه سطر العرض الواحد. */
    private const ZONE_SPLIT = '/\s*(?:—|–|-|·|\/|،|,)\s*/u';

    /**
     * أقصى مسافة تحرير مقبولة في المطابقة التقريبية.
     * ⚠️ **التقريبي بيتعرض وما بيتحطّش** — الحد ده لتقليل الضوضاء بس، مش إذن
     *    باستعمال الرقم. «العااشر» (غلطة مطبعية) لازم توصل للمالك كسؤال.
     */
    private const NEAR_MAX = 2;

    // ═══════════════════════════════════════════════════════════════════
    //  التصنيف: ميناء · اتجاه · خدمة · وحدة
    // ═══════════════════════════════════════════════════════════════════

    /**
     * مجموعة الميناء من نصّه — أو `null` لو مش في أي قايمة أسعار.
     *
     * ⚠️ `null` **مش خطأ** — ميناء دمياط شغّال فعلاً ومالوش عرض أسعار، فالنظام
     *    بيسكت عن التعريفة ويسيب التاريخ يتكلّم.
     */
    public static function portGroup(?string $port): ?string
    {
        $k = Maintenance::normkey((string) $port);
        if ($k === '') { return null; }
        if (str_contains($k, 'بورسعيد') || str_contains($k, 'بور سعيد')) {
            if (str_contains($k, 'غرب')) { return 'psd_west'; }
            if (str_contains($k, 'شرق')) { return 'psd_east'; }
            return 'psd_east';   // بورسعيد بلا تحديد = الشرق (القايمة الأشمل)
        }
        foreach (['اسكندريه', 'الدخيله', 'دخيله', 'ابوقير', 'ابو قير'] as $needle) {
            if (str_contains($k, $needle)) { return 'alex'; }
        }
        return null;
    }

    /** اتجاه الإذن: صادر ⇒ export · وارد ⇒ import. */
    public static function direction(?string $permitType): string
    {
        return ($permitType ?? 'sader') === 'warid' ? 'import' : 'export';
    }

    /**
     * الخدمة والوحدة من تصنيف الحاويات وعدد الحاويات على السيارة.
     *
     * @return array{service:string,unit:string}|null
     */
    public static function unitOf(?string $containerType, int $onCar = 1): ?array
    {
        $norm = trim((string) container_norm((string) $containerType));
        if ($norm === '') { return null; }
        $k = Maintenance::normkey($norm);

        // الثلاجات: العرض مسعّر ٤٠ مبرّد بس
        if (str_contains($k, 'مبرد') || str_contains($k, 'ثلاجه')) {
            return str_starts_with($k, '40') ? ['service' => 'reefer', 'unit' => 'reefer40'] : null;
        }
        // البضائع المجمّعة
        if (str_contains($k, 'جامبو')) { return ['service' => 'lcl', 'unit' => 'jumbo_closed']; }
        if (str_contains($k, 'دبابه')) { return ['service' => 'lcl', 'unit' => 'dababa_closed']; }

        if (str_starts_with($k, '40')) { return ['service' => 'container', 'unit' => '40']; }
        if (str_starts_with($k, '20')) {
            /* ⚠️ **السيارة هي اللي بتتسعّر**: حاويتين ٢٠ على سيارة = سطر
               «2×20»، وحاوية واحدة = سطر «20». `Freight::capacity` هي
               اللي بتقول السعة، مش رقم مكتوب هنا بالإيد. */
            $cap = Freight::capacity($norm) ?? 1;
            return ['service' => 'container', 'unit' => ($onCar >= 2 && $cap >= 2) ? '2x20' : '20'];
        }
        return null;   // أوبن توب · تانك · فلات راك · فرش — مش في العرض
    }

    // ═══════════════════════════════════════════════════════════════════
    //  مطابقة الجهة
    // ═══════════════════════════════════════════════════════════════════

    /** أسماء الجهة جوّه سطر واحد («العاشر — العبور — مدينة نصر» ⇒ ٣ أسماء). */
    public static function zoneAliases(string $label): array
    {
        $parts = preg_split(self::ZONE_SPLIT, $label) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') { $out[] = $p; }
        }
        return $out ?: [trim($label)];
    }

    /**
     * أقرب جهة في التعريفة لجهة الإذن.
     *
     * الترتيب: **مطابقة تامة** ← **احتواء بكلمة كاملة** ← **تقريبي** (موسوم).
     * لو أكتر من سطر طابق، الأطول (الأخصّ) بيكسب — «العاشر من رمضان» أولى من
     * «العاشر» لو الاتنين موجودين.
     *
     * @return array{zone:string,how:string,alias:string}|null
     */
    public static function matchZone(array $rows, string $destination): ?array
    {
        $dest = Maintenance::normkey($destination);
        if ($dest === '') { return null; }

        $zones = [];
        foreach ($rows as $r) { $zones[(string) $r['zone_label']] = true; }

        $exact = null; $contains = null; $near = null; $nearDist = PHP_INT_MAX;

        foreach (array_keys($zones) as $label) {
            foreach (self::zoneAliases($label) as $alias) {
                $a = Maintenance::normkey($alias);
                if ($a === '') { continue; }

                if ($a === $dest) {
                    if ($exact === null || mb_strlen($alias) > mb_strlen($exact['alias'])) {
                        $exact = ['zone' => $label, 'how' => 'exact', 'alias' => $alias];
                    }
                    continue;
                }
                /* الاحتواء **بكلمة كاملة**: «العاشر» جوّه «العاشر من رمضان» ✓،
                   لكن «بدر» جوّه «مدينة بدر» ✓ و«رشيد» جوّه «مرشيد» ✗. */
                if (self::hasWord($dest, $a) || self::hasWord($a, $dest)) {
                    if ($contains === null || mb_strlen($alias) > mb_strlen($contains['alias'])) {
                        $contains = ['zone' => $label, 'how' => 'contains', 'alias' => $alias];
                    }
                    continue;
                }
                $d = levenshtein($a, $dest);
                if ($d <= self::NEAR_MAX && $d < $nearDist) {
                    $nearDist = $d;
                    $near = ['zone' => $label, 'how' => 'near', 'alias' => $alias];
                }
            }
        }
        return $exact ?? $contains ?? $near;
    }

    /** هل `$needle` كلمة كاملة (أو تسلسل كلمات) جوّه `$hay`؟ */
    private static function hasWord(string $hay, string $needle): bool
    {
        if ($needle === '' || $hay === '') { return false; }
        $h = ' ' . preg_replace('/\s+/u', ' ', $hay) . ' ';
        $n = ' ' . preg_replace('/\s+/u', ' ', $needle) . ' ';
        return str_contains($h, $n);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  القراءة
    // ═══════════════════════════════════════════════════════════════════

    /**
     * صفوف التعريفة السارية في تاريخ معيّن.
     *
     * ⚠️ **أحدث تعريفة سارية في تاريخ الإذن** — لا أحدث صف مطلقًا. الفرق ده
     *    بيبان أول ما تتحط قايمة سنة جديدة والمالك يفتح إذنًا قديمًا.
     */
    public static function rows(string $portGroup, string $direction, string $service, ?string $onDate = null): array
    {
        $date = $onDate ?: date('Y-m-d');
        try {
            $eff = Database::scalar(
                'SELECT MAX(effective_from) FROM freight_tariffs
                  WHERE port_group = ? AND service = ? AND direction IN (?, ?)
                    AND active = 1 AND effective_from <= ?',
                [$portGroup, $service, $direction, 'any', $date]
            );
            if ($eff === null) { return []; }
            return Database::all(
                'SELECT * FROM freight_tariffs
                  WHERE port_group = ? AND service = ? AND direction IN (?, ?)
                    AND active = 1 AND effective_from = ?
                  ORDER BY zone_label, unit',
                [$portGroup, $service, $direction, 'any', $eff]
            );
        } catch (\Throwable) { return []; }
    }

    /** إضافة بكودها (ثلاجة بورسعيد · بياتة · تحويلة فوارغ). */
    public static function surcharge(string $portGroup, string $code, ?string $onDate = null): ?array
    {
        $date = $onDate ?: date('Y-m-d');
        try {
            return Database::row(
                'SELECT * FROM freight_surcharges
                  WHERE port_group IN (?, ?) AND code = ? AND active = 1 AND effective_from <= ?
                  ORDER BY effective_from DESC LIMIT 1',
                [$portGroup, 'any', $code, $date]
            ) ?: null;
        } catch (\Throwable) { return null; }
    }

    /** هل التعريفة متعبّية أصلاً؟ (الشاشات بتسكت خالص لو لأ.) */
    public static function ready(): bool
    {
        try { return (int) Database::scalar('SELECT COUNT(*) FROM freight_tariffs WHERE active = 1') > 0; }
        catch (\Throwable) { return false; }
    }

    /** كل التعريفة مجمّعة للعرض في شاشة `/tariffs`. */
    public static function all(?string $portGroup = null, ?string $service = null): array
    {
        $where = ['active = 1']; $args = [];
        if ($portGroup !== null && isset(self::PORT_GROUPS[$portGroup])) { $where[] = 'port_group = ?'; $args[] = $portGroup; }
        if ($service !== null && $service !== '') { $where[] = 'service = ?'; $args[] = $service; }
        try {
            return Database::all(
                'SELECT * FROM freight_tariffs WHERE ' . implode(' AND ', $where)
                . ' ORDER BY port_group, service, direction, zone_label, unit', $args);
        } catch (\Throwable) { return []; }
    }

    /** تحديث سعر صف واحد — من شاشة التعريفة (المالك/المدير بس). */
    public static function updatePrice(int $id, float $price): bool
    {
        if ($id <= 0 || $price < 0) { return false; }
        return Database::exec('UPDATE freight_tariffs SET price = ? WHERE id = ?', [$price, $id]) > 0;
    }
}
