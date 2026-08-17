<?php
namespace App\Models;

use App\Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * توقّع النولون عند إصدار الفاتورة — «كام، وبناءً على إيه؟» (v102)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * طلب المالك بالحرف: «المفروض النظام يتوقّع النولون ويقولّي في كارت جانبي على
 * الشمال عند إصدار الفاتورة: النولون المتوقّع وبناءً على إيه».
 *
 * ═══ مصدران لا مصدر واحد ═══
 *  ①  **التعريفة** (`FreightTariff`) — قائمة الأسعار المعتمدة: جهة × ميناء ×
 *     تصنيف × مبرّد. بتشتغل **من أول يوم** حتى لو العميل جديد تمامًا.
 *  ②  **التاريخ** (`Habit::carPrice`) — السعر اللي اتحاسب بيه العميل ده فعلاً.
 *
 * ⚠️ **الاتنين بيتعرضوا مع بعض لما يختلفوا — وممنوع نخفي واحد.** الفرق بينهم
 *    **معلومة مش تناقض**: يا إما العميل عنده سعر متفق عليه غير القايمة، يا إما
 *    القايمة اتحدّثت والفواتير لسه على القديم. اللي بيحصل لما نعرض واحد بس إن
 *    المالك بيفوتر بسعر وهو فاكر إنه السعر التاني.
 *
 * ⚠️ **الترشيح للخانة = التاريخ لو موجود، وإلا التعريفة.** السعر المتفق عليه
 *    مع العميل أولى من القايمة المعلنة — القايمة نقطة بداية تفاوض، والفاتورة
 *    السابقة **التزام قايم**.
 *
 * ⚠️ **ولا مليم بيتكتب من هنا.** الرقم بيوصل الشاشة كاقتراح والمالك بيحطّه
 *    بضغطة ويقدر يعدّله — نفس عقيدة `Habit` (v97/v98): «كتابة قيمة المستخدم
 *    مشافهاش أسوأ من إنه يختارها بنفسه».
 */
final class FreightQuote
{
    /**
     * التوقّع الكامل لإذن.
     *
     * @return array{
     *   ready:bool, price:?float, source:?string, cars:?int, total:?float,
     *   tariff:?array, history:?array, gap:?array, basis:array, note:?string
     * }
     */
    public static function forPermit(int $permitId): array
    {
        $p = Permit::find($permitId);
        if (!$p) { return self::blank(); }

        $breakdown = Permit::containerBreakdown('permit_id', [$permitId])[$permitId] ?? [];
        $cars      = Freight::carsFor($breakdown, (int) ($p['containers'] ?? 0), $p['container_type'] ?? null);
        if (!$cars) {
            // مفيش بوالص لسه ولا تصنيف معروف — عدد السيارات من البوالص المسجّلة
            $n = (int) Database::scalar('SELECT COUNT(*) FROM bawalis WHERE permit_id = ?', [$permitId]);
            $cars = $n > 0 ? $n : null;
        }

        $tariff  = self::fromTariff($p, $breakdown, $cars);
        $history = self::fromHistory($p, $permitId);

        /* الترشيح: التاريخ أولى، والتعريفة أرضية. */
        $price  = $history['price'] ?? $tariff['price'] ?? null;
        $source = $history !== null ? 'history' : ($tariff !== null ? 'tariff' : null);

        $gap = null;
        if ($history !== null && $tariff !== null && $tariff['price'] > 0) {
            $diff = $history['price'] - $tariff['price'];
            if (abs($diff) >= 0.01) {
                $gap = [
                    'diff' => round($diff, 2),
                    'pct'  => round($diff / $tariff['price'] * 100, 1),
                ];
            }
        }

        return [
            'ready'   => $price !== null,
            'price'   => $price !== null ? round((float) $price, 2) : null,
            'source'  => $source,
            'cars'    => $cars,
            'total'   => ($price !== null && $cars) ? round((float) $price * $cars, 2) : null,
            'tariff'  => $tariff,
            'history' => $history,
            'gap'     => $gap,
            'basis'   => self::basis($p, $breakdown, $cars, $tariff),
            'note'    => self::note($p, $tariff, $history),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ① التعريفة
    // ═══════════════════════════════════════════════════════════════════

    /**
     * سعر السيارة من قائمة الأسعار المعتمدة.
     *
     * @return array{price:float,unit:string,unit_label:string,zone:string,how:string,
     *               port_group:string,port_label:string,service:string,genset:?float,
     *               surcharge:?array,base:float}|null
     */
    private static function fromTariff(array $p, array $breakdown, ?int $cars): ?array
    {
        $pg = FreightTariff::portGroup($p['port'] ?? null);
        if ($pg === null) { return null; }

        $dir  = FreightTariff::direction($p['permit_type'] ?? 'sader');
        $date = $p['permit_date'] ?: null;

        /* التصنيف الحاكم: أكتر تصنيف في البوالص، وإلا تصنيف الإذن نفسه. */
        $type = self::dominantType($breakdown, $p['container_type'] ?? null);
        if ($type === null) { return null; }

        /* كام حاوية على السيارة الواحدة — ده اللي بيفرّق بين «20» و«2×20». */
        $count  = self::countOf($breakdown, $type, (int) ($p['containers'] ?? 0));
        $onCar  = ($cars && $cars > 0) ? (int) ceil($count / $cars) : 1;
        $u      = FreightTariff::unitOf($type, $onCar);
        if ($u === null) { return null; }

        $rows = FreightTariff::rows($pg, $dir, $u['service'], $date);
        if (!$rows) { return null; }

        $zone = FreightTariff::matchZone($rows, (string) ($p['destination'] ?? ''));
        if ($zone === null) { return null; }

        $hit = null;
        foreach ($rows as $r) {
            if ((string) $r['zone_label'] === $zone['zone'] && (string) $r['unit'] === $u['unit']) { $hit = $r; break; }
        }
        if ($hit === null) { return null; }

        $base   = (float) $hit['price'];
        $price  = $base;
        $genset = null;
        $sur    = null;

        /* الثلاجة: المولّد سطر منفصل في العرض وبيتضاف على النقل. */
        if ($u['service'] === 'reefer') {
            foreach ($rows as $r) {
                if ((string) $r['zone_label'] === $zone['zone'] && (string) $r['unit'] === 'genset') {
                    $genset = (float) $r['price'];
                    $price += $genset;
                    break;
                }
            }
        }
        /* بورسعيد: الثلاجة زيادة مقطوعة على النولون بدل عمود مستقل. */
        if (self::isReefer($type) && in_array($pg, ['psd_east', 'psd_west'], true)) {
            $s = FreightTariff::surcharge($pg, 'reefer_trip', $date);
            if ($s) { $sur = $s; $price += (float) $s['amount']; }
        }

        return [
            'price'      => round($price, 2),
            'base'       => round($base, 2),
            'unit'       => $u['unit'],
            'unit_label' => FreightTariff::UNITS[$u['unit']] ?? $u['unit'],
            'zone'       => $zone['zone'],
            'how'        => $zone['how'],
            'alias'      => $zone['alias'],
            'port_group' => $pg,
            'port_label' => FreightTariff::PORT_GROUPS[$pg] ?? $pg,
            'service'    => $u['service'],
            'genset'     => $genset,
            'surcharge'  => $sur,
            'source_ref' => (string) ($hit['source_ref'] ?? ''),
        ];
    }

    /** التصنيف الحاكم: الأكتر عددًا في البوالص، وإلا تصنيف الإذن. */
    private static function dominantType(array $breakdown, ?string $fallback): ?string
    {
        $best = null; $bestN = 0;
        foreach ($breakdown as $b) {
            $n = (int) ($b['cnt'] ?? 0);
            if ($n > $bestN) { $bestN = $n; $best = (string) $b['type']; }
        }
        if ($best !== null && trim($best) !== '') { return $best; }
        $f = trim((string) $fallback);
        return $f !== '' ? $f : null;
    }

    /** عدد حاويات التصنيف الحاكم. */
    private static function countOf(array $breakdown, string $type, int $fallback): int
    {
        foreach ($breakdown as $b) {
            if ((string) $b['type'] === $type) { return max(1, (int) $b['cnt']); }
        }
        return max(1, $fallback);
    }

    private static function isReefer(?string $type): bool
    {
        $k = Maintenance::normkey((string) container_norm((string) $type));
        return str_contains($k, 'مبرد') || str_contains($k, 'ثلاجه');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ② التاريخ
    // ═══════════════════════════════════════════════════════════════════

    /**
     * السعر المعتاد للعميل من فواتيره السابقة (`Habit::carPrice` — v98).
     *
     * ⚠️ **بيجرّب الشركة لو العميل فاضي.** ده مش تحسين — ده إصلاح عطل: إذن
     *    «ميتاكيم للتوريد» كان `client` فيه NULL و`company` مليانة، و`Habit`
     *    بتشتغل بالعميل بس، فالمالك فتح الفاتورة ولقى **صفر اقتراحات** بلا أي
     *    سبب ظاهر. ٩ أذون في الإنتاج على الحالة دي.
     */
    private static function fromHistory(array $p, int $permitId): ?array
    {
        $ctx = [
            'destination'    => (string) ($p['destination'] ?? ''),
            'container_type' => (string) ($p['container_type'] ?? ''),
            'exclude_permit' => $permitId,
        ];

        $client = trim((string) ($p['client'] ?? ''));
        if ($client !== '') {
            $hit = Habit::carPrice($ctx + ['client' => $client]);
            if ($hit) { return self::historyRow($hit, 'العميل'); }
        }

        $company = trim((string) ($p['company'] ?? ''));
        if ($company !== '') {
            $hit = Habit::carPriceByCompany($ctx + ['company' => $company]);
            if ($hit) { return self::historyRow($hit, 'الشركة'); }
        }
        return null;
    }

    private static function historyRow(array $hit, string $party): array
    {
        return [
            'price' => (float) $hit['value'],
            'hits'  => (int) $hit['hits'],
            'total' => (int) $hit['total'],
            'share' => (float) $hit['share'],
            'last'  => $hit['last_seen'] ?? null,
            'scope' => (string) $hit['scope'],
            'party' => $party,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  الشرح — «بناءً على إيه؟»
    // ═══════════════════════════════════════════════════════════════════

    /** أسطر «بناءً على» — كل سطر حقيقة من الإذن أو من التعريفة. */
    private static function basis(array $p, array $breakdown, ?int $cars, ?array $tariff): array
    {
        $out = [];
        $port = trim((string) ($p['port'] ?? ''));
        $dest = trim((string) ($p['destination'] ?? ''));

        /* ⚠️ **كل سطر بيتوسم بحاله لا بحال النتيجة النهائية.** كان الميناء
           بيتوسم «ناقص» لمجرّد إن التعريفة ما طابقتش — فالمالك يشوف ميناء
           الإسكندرية بالأصفر ويروح يدوّر فيه، والمشكلة أصلاً في التصنيف. */
        $portKnown = $port !== '' && FreightTariff::portGroup($port) !== null;
        $out[] = ['icon' => 'anchor', 'label' => 'الميناء',
                  'value' => $port !== '' ? $port : '— غير محدَّد',
                  'ok' => $portKnown,
                  'note' => ($port !== '' && !$portKnown) ? 'مالوش قائمة أسعار معتمدة' : null];
        $out[] = ['icon' => 'map-pin', 'label' => 'الجهة',
                  'value' => $dest !== '' ? $dest : '— غير محدَّدة',
                  'ok' => $dest !== '',
                  'note' => $tariff !== null && $tariff['how'] !== 'exact'
                      ? ('طابقت سطر: ' . $tariff['zone']) : null];

        $types = [];
        foreach ($breakdown as $b) { $types[] = intf((int) $b['cnt']) . '×' . $b['type']; }
        if (!$types && trim((string) ($p['container_type'] ?? '')) !== '') {
            $types[] = (string) $p['container_type'];
        }
        $rawType = trim((string) ($p['container_type'] ?? ''));
        $priced  = $rawType !== '' && FreightTariff::unitOf($rawType, 1) !== null;
        $out[] = ['icon' => 'package', 'label' => 'التصنيف',
                  'value' => $types ? implode(' · ', $types) : '— غير محدَّد',
                  'ok' => $types && $priced,
                  'note' => ($types && !$priced) ? 'النوع ده مش مسعّر في القوايم' : null];

        $out[] = ['icon' => 'truck', 'label' => 'وحدة التسعير',
                  'value' => $tariff !== null ? $tariff['unit_label']
                           : ($cars ? intf($cars) . ' سيارة' : '— غير محدَّدة'),
                  'ok' => $tariff !== null || (bool) $cars];

        if ($tariff !== null && $tariff['service'] === 'reefer') {
            $out[] = ['icon' => 'snowflake', 'label' => 'مبرّدة',
                      'value' => $tariff['genset'] !== null
                          ? ('نقل ' . money($tariff['base'], false) . ' + مولّد ' . money($tariff['genset'], false))
                          : 'نعم', 'ok' => true];
        } elseif ($tariff !== null && $tariff['surcharge'] !== null) {
            $out[] = ['icon' => 'snowflake', 'label' => 'مبرّدة',
                      'value' => 'زيادة ' . money((float) $tariff['surcharge']['amount'], false), 'ok' => true];
        }
        return $out;
    }

    /** سبب الصمت — لازم يتقال، مش نسكت ونسيب المالك يخمّن. */
    private static function note(array $p, ?array $tariff, ?array $history): ?string
    {
        if ($tariff !== null || $history !== null) {
            return ($tariff !== null && $tariff['how'] === 'near')
                ? 'الجهة مش مكتوبة بالحرف في التعريفة — أقرب سطر معروض، راجعه.'
                : null;
        }
        /* ⚠️ **الترتيب هنا لازم يتبع ترتيب المطابقة الفعلي** — وإلا السبب
           المعروض بيبقى غلط. إذن «ميتاكيم» تصنيفه «فرش» (مش حاوية مسعّرة
           أصلاً)، وكان بيقول «الجهة مش في القايمة» فيبعت المالك يدوّر في
           الجهة وهي مش المشكلة. */
        $type = trim((string) ($p['container_type'] ?? ''));
        if (trim((string) ($p['port'] ?? '')) === '')        { return 'الميناء مش متحدّد في الإذن.'; }
        if (FreightTariff::portGroup($p['port']) === null)   { return 'الميناء ده مالوش قائمة أسعار معتمدة.'; }
        if ($type === '')                                    { return 'تصنيف الحاويات مش متحدّد.'; }
        if (FreightTariff::unitOf($type, 1) === null)        { return "«{$type}» مش نوع مسعّر في قائمة الأسعار."; }
        if (trim((string) ($p['destination'] ?? '')) === '') { return 'الجهة مش متحدّدة في الإذن.'; }
        return 'الجهة دي مش في قائمة أسعار الميناء ده، ومفيش فاتورة سابقة لنفس السياق.';
    }

    private static function blank(): array
    {
        return ['ready' => false, 'price' => null, 'source' => null, 'cars' => null, 'total' => null,
                'tariff' => null, 'history' => null, 'gap' => null, 'basis' => [], 'note' => null];
    }
}
