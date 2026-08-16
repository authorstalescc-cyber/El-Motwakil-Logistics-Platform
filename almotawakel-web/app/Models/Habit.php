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
}
