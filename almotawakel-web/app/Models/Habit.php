<?php
namespace App\Models;

use App\Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * محرّك العادات — «العميل ده بيعمل إيه عادةً؟» (v97)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * طلب المالك: «عاوز النظام يتوقع من المعاملات السابقة للشركات أو الوكلاء جهة
 * الإذن، لأن بنسبة ٩٩٪ الإذن بيكون لنفس الجهة كل مرة».
 *
 * ═══ المقيس قبل الكتابة ═══
 *   • ثبات الجهة حسب **الشركة**: ٨٥.٧٪ · وحسب **العميل**: ٦٨.٢٪
 *   • فـسلسلة المفاتيح بتبدأ بالأدق: (عميل+شركة) ← شركة ← عميل
 *
 * ⚠️ **الدالة دي بتقترح بس — عمرها ما بتكتب.** ولا كنترولر بينده `top()` عشان
 *    يملا حقل غايب: الاقتراح بيوصل للشاشة عبر `/suggest/habits` والجافاسكريبت
 *    هو اللي بيملا، والمالك شايف القيمة قدامه قبل الحفظ. نفس قرار
 *    `lookup-autofill.js`: «كتابة قيمة المستخدم مشافهاش أسوأ من إنه يختارها
 *    بنفسه». `verify_v97` بيثبت إن الفورم بيترندر فاضي بلا جافاسكريبت.
 *
 * ⚠️ **الصمت أنضف من التخمين.** أقل من `MIN_HITS` ملاحظة أو ثبات أقل من
 *    `MIN_SHARE` ⇒ `null` وخلاص. مصداقية الاقتراح هي رأس ماله — أول اقتراح
 *    غلط بيخلّي المالك يبطّل يبصّ للسطر ده خالص.
 *
 * ⚠️ **الحساب حيّ مش مخزَّن** (قرار v97). كان في الخطة جدول `party_habits`
 *    بيتبني من نبضة `push_worker`، واتأجّل عن قصد: الاستعلام تجميعة واحدة
 *    على جدول مفهرس، والجدول المادّي كان هيضيف طبقة **بتبوظ بالسكوت** (قيمة
 *    قديمة بتتعرض كأنها الحقيقة) مقابل صفر مكسب على الحجم الحالي. لما عدد
 *    الأذونات يعدّي عشرات الآلاف، المادّي يبقى له معنى — والواجهة ساعتها
 *    ما بتتغيّرش لأن `top()` هي المدخل الوحيد.
 *
 * ⚠️ **التوحيد قبل التجميع شرط مش تحسين.** «المصرية الألمانية» و«المصريه
 *    الالمانيه» لو اتحسبوا كيانين، الثبات بينزل النص والاقتراح بيسكت غلط.
 *    عشان كده كل اسم بيتفرد لكل تهجئاته عبر `PartyMaster` قبل ما يدخل
 *    الاستعلام (تحذير ٣ في CLAUDE.md — الربط بأسماء نصية بلا FK).
 */
final class Habit
{
    /**
     * الحقول اللي النظام بيتعلّمها — **قائمة مقفولة**.
     *
     * ⚠️ الأسماء دي بتتحطّ جوّه `GROUP BY` مباشرةً، فالقائمة دي هي الحاجز
     *    الوحيد ضد حقن SQL: أي قيمة برّه المصفوفة بترجّع `null` قبل أي استعلام.
     *
     * ⚠️ **`shipping_co` مستبعدة عن قصد** — عندها قاعدة قايمة ومقيسة
     *    (`data-autofill-from="client"` · ٥٦ من ٨٧ إذن شركة الشحن فيهم = العميل
     *    بالحرف). نظامين بيكتبوا نفس الخانة = واحد بيدهس التاني والمالك ما
     *    يعرفش مين. تتضم لما القاعدة الثابتة تتشال، مش قبلها.
     */
    public const FIELDS = [
        'destination'    => 'الجهة',
        'agency'         => 'التوكيل',
        'port'           => 'الميناء',
        'container_type' => 'تصنيف الحاويات',
    ];

    /** أقدم من كده مستبعد — سعر/جهة من سنتين مش «عادة» دلوقتي. */
    private const WINDOW_DAYS = 365;

    /** آخر ٦ شهور وزنها الضِّعف — العادة الحديثة بتغلب القديمة عند التساوي. */
    private const RECENT_DAYS = 180;

    /** حد الدليل الأدنى: أقل من كده = صمت. */
    private const MIN_HITS  = 2;
    private const MIN_SHARE = 0.60;

    /**
     * سلسلة المفاتيح من الأدق للأعم — **أول مفتاح عنده دليل كافي بيكسب**.
     * الترتيب ده مقيس مش مفترض: الشركة (٨٥.٧٪) أثبت من العميل (٦٨.٢٪).
     */
    private const SCOPES = [
        ['key' => 'both',    'label' => 'العميل والشركة'],
        ['key' => 'company', 'label' => 'الشركة'],
        ['key' => 'client',  'label' => 'العميل'],
    ];

    /**
     * كل التهجئات المعروفة لاسم طرف — الاسم نفسه + كل الـaliases بتاعته.
     *
     * ⚠️ بترجّع الاسم زي ما هو لو مش متسجّل في الدليل: طرف جديد لسه ما اتضافش
     *    لازم يفضل قابل للتجميع بتهجئته، مش يتحوّل لصفر نتايج.
     */
    public static function spellings(string $type, string $name): array
    {
        $name = trim($name);
        if ($name === '') { return []; }
        $out = [$name];
        $party = PartyMaster::resolve($type, $name);
        if ($party) {
            $out[] = (string) $party['name'];
            foreach (PartyMaster::aliases($type, (int) $party['id']) as $a) {
                $out[] = (string) $a['alias'];
            }
        }
        // مفاتيح المصفوفة الرقمية بتتحوّل int — القيم هنا نصوص فـunique آمنة
        return array_values(array_unique(array_filter($out, static fn ($v) => trim($v) !== '')));
    }

    /**
     * أقوى قيمة معتادة لحقل، أو `null` لو الدليل مش كافي.
     *
     * @param array $ctx ['client' => …, 'company' => …]
     * @return array{value:string,hits:int,total:int,share:float,last_seen:?string,scope:string}|null
     */
    public static function top(string $field, array $ctx): ?array
    {
        if (!isset(self::FIELDS[$field])) { return null; }
        $client  = self::spellings('agent',   (string) ($ctx['client']  ?? ''));
        $company = self::spellings('company', (string) ($ctx['company'] ?? ''));

        foreach (self::SCOPES as $scope) {
            $where = self::scopeWhere($scope['key'], $client, $company);
            if ($where === null) { continue; }
            $rows = self::tally($field, $where[0], $where[1]);
            if (!$rows) { continue; }
            $total = array_sum(array_column($rows, 'hits'));
            $best  = $rows[0];
            if ($best['hits'] < self::MIN_HITS) { continue; }
            $share = $total > 0 ? $best['hits'] / $total : 0.0;
            if ($share < self::MIN_SHARE) { continue; }
            return [
                'value'     => (string) $best['value'],
                'hits'      => (int) $best['hits'],
                'total'     => (int) $total,
                'share'     => round($share, 4),
                'last_seen' => $best['last_seen'] ?? null,
                'scope'     => $scope['label'],
            ];
        }
        return null;
    }

    /** كل الحقول مرة واحدة — طلب واحد للشاشة لا أربعة. */
    public static function all(array $ctx): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $hit = self::top($field, $ctx);
            if ($hit !== null) { $out[$field] = $hit; }
        }
        return $out;
    }

    /**
     * الأذونات اللي الاقتراح اتعلّم منها — **مادة نافذة التفاصيل**.
     * (قاعدة المالك «ممنوع رقم أصمّ»: «٩ من ١٠» لازم تتفتح وتتشاف.)
     */
    public static function samples(string $field, array $ctx, int $limit = 20): array
    {
        if (!isset(self::FIELDS[$field])) { return []; }
        $client  = self::spellings('agent',   (string) ($ctx['client']  ?? ''));
        $company = self::spellings('company', (string) ($ctx['company'] ?? ''));
        foreach (self::SCOPES as $scope) {
            $where = self::scopeWhere($scope['key'], $client, $company);
            if ($where === null) { continue; }
            $rows = self::tally($field, $where[0], $where[1]);
            if (!$rows || $rows[0]['hits'] < self::MIN_HITS) { continue; }
            $limit = max(1, min(100, $limit));
            return Database::all(
                "SELECT id, permit_no, permit_type, permit_date, client, company, `$field` AS value
                   FROM permits
                  WHERE {$where[0]} AND " . self::windowSql() . " AND COALESCE(`$field`,'') <> ''
                  ORDER BY permit_date DESC, id DESC
                  LIMIT $limit",
                $where[1]
            );
        }
        return [];
    }

    /**
     * شرط النطاق + معاملاته، أو `null` لو النطاق ده مالوش بيانات في السياق.
     *
     * @return array{0:string,1:array}|null
     */
    private static function scopeWhere(string $key, array $client, array $company): ?array
    {
        $parts = []; $params = [];
        if ($key === 'both' || $key === 'client') {
            if (!$client) { return null; }
            $parts[] = 'client IN (' . self::marks($client) . ')';
            $params  = array_merge($params, $client);
        }
        if ($key === 'both' || $key === 'company') {
            if (!$company) { return null; }
            $parts[] = 'company IN (' . self::marks($company) . ')';
            $params  = array_merge($params, $company);
        }
        return $parts ? [implode(' AND ', $parts), $params] : null;
    }

    /**
     * عدّ القيم مرتّبة بالوزن.
     *
     * ⚠️ **الترتيب بالوزن والعرض بالعدد الخام** عن قصد: الوزن بيخلّي العادة
     *    الحديثة تغلب القديمة عند التقارب، لكن «٩ من ١٠» اللي المالك بيقراها
     *    لازم تبقى أذونات حقيقية يقدر يفتحها ويعدّها — رقم موزون معروض
     *    مستحيل يتطابق مع اللي في النافذة.
     */
    private static function tally(string $field, string $where, array $params): array
    {
        return Database::all(
            "SELECT `$field` AS value,
                    COUNT(*) AS hits,
                    SUM(CASE WHEN permit_date >= DATE_SUB(CURDATE(), INTERVAL " . self::RECENT_DAYS . " DAY)
                             THEN 2 ELSE 1 END) AS weight,
                    MAX(permit_date) AS last_seen
               FROM permits
              WHERE $where AND " . self::windowSql() . " AND COALESCE(`$field`,'') <> ''
              GROUP BY `$field`
              ORDER BY weight DESC, hits DESC, last_seen DESC",
            $params
        );
    }

    /**
     * نافذة الزمن — **جوّه SQL لا في PHP**.
     *
     * ⚠️ قاعدة الإنتاج UTC وPHP القاهرة (فرق ٣ ساعات)، فأي مقارنة بين تاريخ
     *    من القاعدة و«دلوقتي» محسوبة في PHP بتغلط بيوم على الحدود. القاعدة ٤
     *    في CLAUDE.md: المقارنة تحصل جوّه SQL.
     *
     * والإذن بلا تاريخ **بيتحسب** — غياب التاريخ مش دليل إنه قديم، وإسقاطه
     *    كان بيضيّع أذونات لسه مفتوحة.
     */
    private static function windowSql(): string
    {
        return '(permit_date IS NULL OR permit_date >= DATE_SUB(CURDATE(), INTERVAL ' . self::WINDOW_DAYS . ' DAY))';
    }

    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /* ═══════════════════════════════════════════════════════════════════════
       سعر السيارة للعميل (v98) — أكبر مكسب في الخطة
       ═══════════════════════════════════════════════════════════════════════

       ⚠️ **بنتعلّم من الرقم اللي المالك كتبه بإيده، مش من اشتقاق.** الفواتير
          بتخزّن بند النولون في `items_json` كـ«كمية × سعر»، والمالك بيكتب
          **الكمية = عدد السيارات والسعر = سعر السيارة**:
            · اكما  ← qty 4 × 27,500  (٨ حاويات ٢٠ قدم = ٤ سيارات — حسبها بنفسه)
            · فيرتكس ← qty 2 × 15,300 · qty 7 × 15,300
            · فوكس  ← qty 2 × 20,500 · qty 1 × 20,500
          فمفيش أي قسمة ولا تخمين هنا: الرقم المعروض هو **نفس** الرقم اللي
          اتكتب قبل كده. ولاحظ إذن فيرتكس اللي فيه **٨ بوالص** والمالك فوتره
          **٧ وحدات** — ولو كنا اشتقّينا السعر من عدد البوالص كنا هنقول
          ١٣٬٣٨٧ بدل ١٥٬٣٠٠ **وهو غلط**.

       ⚠️ **الفلوس اقتراح يتداس لا تعبئة.** الدالة دي بترجّع رقمًا للشاشة
          تعرضه في زرار؛ ولا كنترولر بيكتب مبلغ في فورم.
    */

    /** أقل من كده مش سعر — بيحمي من صف نولون اتساب صفر بالغلط. */
    private const MIN_PRICE = 1.0;

    /**
     * سعر السيارة المعتاد لعميل، أو `null` لو الدليل مش كافي.
     *
     * سلسلة المفاتيح من الأدق للأعم — نفس فلسفة `top()`:
     *   (عميل + جهة + تصنيف) ← (عميل + تصنيف) ← (عميل + جهة) ← (عميل)
     *
     * @param array $ctx ['client'=>…, 'destination'=>…, 'container_type'=>…, 'exclude_permit'=>int]
     * @return array{value:float,hits:int,total:int,share:float,last_seen:?string,scope:string}|null
     */
    public static function carPrice(array $ctx): ?array
    {
        return self::priceFrom(self::priceRows($ctx), $ctx);
    }

    /**
     * نفس الحساب بس بفواتير **الشركة** بدل العميل (v102).
     *
     * ⚠️ **ده إصلاح عطل مقيس مش إضافة.** `priceRows` بترجّع `[]` فورًا لو
     *    العميل فاضي، و**٩ أذون في الإنتاج عميلها فاضي والشركة مليانة** —
     *    منهم إذن «ميتاكيم للتوريد» اللي المالك فتح فاتورته فما لقاش ولا
     *    اقتراح واحد، **بلا أي سبب ظاهر على الشاشة**. الشركة مصدر مشروع
     *    للسابقة: هي الجهة اللي بتتحاسب فعلاً لما الوكيل مش متسجّل.
     *
     * ⚠️ **بتفضل تحت العميل في الترتيب** (`FreightQuote` بينده العميل الأول):
     *    العميل أدقّ لأن نفس الشركة ممكن تتشحن لأكتر من وكيل بأسعار مختلفة.
     */
    public static function carPriceByCompany(array $ctx): ?array
    {
        return self::priceFrom(self::priceRows($ctx + ["by" => "company"]), $ctx + ["by" => "company"]);
    }

    /** الحساب المشترك بين المفتاحين — نقطة واحدة فمستحيل يفترقوا. */
    private static function priceFrom(array $rows, array $ctx): ?array
    {
        if (!$rows) { return null; }
        $party = ($ctx['by'] ?? '') === 'company' ? 'الشركة' : 'العميل';

        $dest = trim((string) ($ctx['destination'] ?? ''));
        $type = trim((string) (container_norm((string) ($ctx['container_type'] ?? '')) ?? ''));

        $levels = [
            /* ⚠️ **اسم الطرف متغيّر مش ثابت** — نفس الحساب بيشتغل بالعميل أو
               بالشركة، والوسم كان مكتوب «العميل» بالحرف فكان **بيكدب** لما
               المطابقة تحصل بالشركة: الشاشة تقول «المعتاد لهذا العميل» والإذن
               أصلاً عميله فاضي. */
            ['label' => $party . ' والجهة والتصنيف', 'dest' => true,  'type' => true],
            ['label' => $party . ' والتصنيف',        'dest' => false, 'type' => true],
            ['label' => $party . ' والجهة',          'dest' => true,  'type' => false],
            ['label' => $party,                      'dest' => false, 'type' => false],
        ];

        foreach ($levels as $lv) {
            if ($lv['dest'] && $dest === '') { continue; }
            if ($lv['type'] && $type === '') { continue; }
            $pool = array_values(array_filter($rows, static function (array $r) use ($lv, $dest, $type) {
                if ($lv['dest'] && $r['destination'] !== $dest) { return false; }
                if ($lv['type'] && $r['container_type'] !== $type) { return false; }
                return true;
            }));
            if (!$pool) { continue; }

            $by = [];
            foreach ($pool as $r) {
                /* ⚠️ المفتاح **نص بمنزلتين عشرية** لا `float`: مفاتيح المصفوفة
                   الرقمية بتتحوّل `int` في PHP فـ«١٥٣٠٠٫٥٠» و«١٥٣٠٠» كانوا
                   هيبقوا مفتاحًا واحدًا (مصيدة موثَّقة في CLAUDE.md). */
                $k = number_format($r['price'], 2, '.', '');
                if (!isset($by[$k])) { $by[$k] = ['price' => $r['price'], 'hits' => 0, 'last' => null]; }
                $by[$k]['hits']++;
                if ($r['date'] !== null && ($by[$k]['last'] === null || $r['date'] > $by[$k]['last'])) {
                    $by[$k]['last'] = $r['date'];
                }
            }
            uasort($by, static fn ($a, $b) => [$b['hits'], $b['last']] <=> [$a['hits'], $a['last']]);
            $best  = reset($by);
            $total = count($pool);
            if ($best['hits'] < self::MIN_HITS) { continue; }
            $share = $best['hits'] / $total;
            if ($share < self::MIN_SHARE) { continue; }
            return [
                'value'     => (float) $best['price'],
                'hits'      => (int) $best['hits'],
                'total'     => $total,
                'share'     => round($share, 4),
                'last_seen' => $best['last'],
                'scope'     => $lv['label'],
            ];
        }
        return null;
    }

    /** الفواتير اللي السعر اتعلّم منها — مادة نافذة التفاصيل. */
    public static function priceSamples(array $ctx, int $limit = 20): array
    {
        $rows = self::priceRows($ctx);
        usort($rows, static fn ($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
        return array_slice($rows, 0, max(1, min(100, $limit)));
    }

    /**
     * بنود النولون المسجَّلة في فواتير العميل — سعر الوحدة وكميتها.
     *
     * ⚠️ **الفاتورة الملغاة مستبعدة** (`status='void'`): سعر اتلغى مش سابقة.
     * ⚠️ **وإذن الفاتورة الحالية مستبعد** — وإلا الاقتراح بيتعلّم من نفسه
     *    ويثبّت أول رقم اتكتب للأبد.
     */
    private static function priceRows(array $ctx): array
    {
        /* المفتاح: العميل (الافتراضي) أو الشركة — والعمود بيتحدّد من **قائمة
           بيضا** لأنه بيتحطّ في `WHERE` مباشرةً. */
        $byCompany = ($ctx['by'] ?? '') === 'company';
        $col       = $byCompany ? 'i.company' : 'i.client';
        $names     = $byCompany
            ? self::spellings('company', (string) ($ctx['company'] ?? ''))
            : self::spellings('agent',   (string) ($ctx['client']  ?? ''));
        if (!$names) { return []; }
        $exclude = (int) ($ctx['exclude_permit'] ?? 0);

        $params = $names;
        $sql = "SELECT i.id, i.invoice_date, i.destination, i.items_json,
                       COALESCE(p.container_type, '') AS container_type,
                       i.permit_no, i.doc_no, i.company
                  FROM permit_invoices i
             LEFT JOIN permits p ON p.id = i.permit_id
                 WHERE $col IN (" . self::marks($names) . ")
                   AND i.freight > 0
                   AND COALESCE(i.status, '') <> 'void'
                   AND (i.invoice_date IS NULL OR i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL " . self::WINDOW_DAYS . " DAY))";
        if ($exclude > 0) { $sql .= ' AND COALESCE(i.permit_id, 0) <> ?'; $params[] = $exclude; }
        $sql .= ' ORDER BY i.invoice_date DESC, i.id DESC';

        $out = [];
        foreach (Database::all($sql, $params) as $r) {
            $line = self::freightLine((string) ($r['items_json'] ?? ''));
            if ($line === null) { continue; }
            $out[] = [
                'id'             => (int) $r['id'],
                'doc_no'         => (string) ($r['doc_no'] ?? ''),
                'permit_no'      => (string) ($r['permit_no'] ?? ''),
                'company'        => (string) ($r['company'] ?? ''),
                'date'           => $r['invoice_date'],
                'destination'    => trim((string) ($r['destination'] ?? '')),
                'container_type' => trim((string) (container_norm((string) $r['container_type']) ?? '')),
                'price'          => $line['price'],
                'qty'            => $line['qty'],
            ];
        }
        return $out;
    }

    /**
     * بند النولون جوّه لقطة الفاتورة — `['price'=>سعر الوحدة, 'qty'=>الكمية]`.
     *
     * ⚠️ **بنقرا `price` مش `total ÷ qty`**: القسمة بتدخّل قرشًا ضايعًا في
     *    الرقم المعروض، والسعر مكتوب صريحًا أصلاً.
     */
    private static function freightLine(string $json): ?array
    {
        if (trim($json) === '') { return null; }
        $items = json_decode($json, true);
        if (!is_array($items)) { return null; }
        foreach ($items as $ln) {
            if (!is_array($ln) || empty($ln['freight'])) { continue; }
            $price = (float) ($ln['price'] ?? 0);
            $qty   = (float) ($ln['qty'] ?? 0);
            if ($price < self::MIN_PRICE || $qty <= 0) { return null; }
            return ['price' => $price, 'qty' => $qty];
        }
        return null;
    }
}
