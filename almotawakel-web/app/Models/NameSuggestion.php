<?php
namespace App\Models;

use App\Core\Database;

class NameSuggestion
{
    public static function search(string $type, string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') { return []; }
        $names = self::names($type); $q = Maintenance::normkey($query); $ranked=[];
        foreach ($names as $name) {
            $n = Maintenance::normkey($name); if ($n === '') { continue; }
            $kind='similar'; $score=0;
            if ($n === $q) { $kind='exact'; $score=1000; }
            elseif (str_contains($n,$q) || str_contains($q,$n)) { $kind='match'; $score=800-min(abs(mb_strlen($n)-mb_strlen($q)),50); }
            else {
                $distance=self::distance($q,$n); $max=max(mb_strlen($q),mb_strlen($n),1);
                $ratio=1-($distance/$max);
                $tokensQ=array_filter(explode(' ',$q)); $tokensN=array_filter(explode(' ',$n));
                $common=count(array_intersect($tokensQ,$tokensN));
                $score=(int)round($ratio*500)+$common*80;
                if ($ratio < .48 && $common === 0) { continue; }
            }
            $ranked[]=['name'=>$name,'kind'=>$kind,'score'=>$score];
        }
        usort($ranked, fn($a,$b)=>$b['score']<=>$a['score'] ?: strcmp($a['name'],$b['name']));
        return array_slice($ranked,0,max(1,min(20,$limit)));
    }

    /**
     * كلمات «الوصف» التي تسبق الاسم الحقيقي فتصنع تهجئتين لنفس الكيان
     * («ميناء الإسكندرية» ≡ «الإسكندرية»). تُقارَن بعد `normkey` — لذلك تُطبَّع هنا
     * برمجيًّا بدل كتابتها بشكلها الموحَّد يدويًا (ة→ه، أ→ا …).
     */
    private const NOISE = [
        'port'        => ['ميناء', 'مينا', 'مرسى'],
        'destination' => ['مدينة', 'منطقة', 'محافظة', 'مركز', 'طريق', 'ط'],
        'company'     => ['شركة', 'مؤسسة', 'مصنع', 'ش'],
        'agency'      => ['توكيل', 'شركة', 'وكالة'],
        'shipping_co' => ['شركة', 'خط', 'خطوط'],
    ];

    /**
     * الأنواع التي يصحّ فيها اعتبار «احتواء الرموز» تكرارًا
     * («اكتوبر» ⊂ «6 اكتوبر» · «40» ⊂ «40 قدم» · «التجمع» ⊂ «التجمع 5»).
     * ⚠️ أسماء الأشخاص (سائق/صاحب سيارة/صاحب بيان) **مستثناة عن قصد**: الاسم
     *    الثنائي والثلاثي لنفس العائلة شائع ومختلف فعلاً («محمد السيد» ≠ «محمد السيد احمد»).
     */
    private const SUBSET_TYPES = ['port', 'destination', 'container_type', 'classification', 'agency', 'shipping_co', 'company'];

    /**
     * أنواع «الوحدة»: مفرداتها قليلة ومقفولة تقريبًا («40 قدم» · «20 مبرّد»)، فالكلمة
     * الزيادة فيها وحدة قياس مش اسم مكان. عشان كده بس بيتسمح فيها بفحصين أوسع:
     * الاحتواء بكلمة غير رقمية، والغلط المطبعي جوّه كلمة واحدة.
     */
    private const UNIT_TYPES = ['container_type', 'classification'];

    /**
     * أسماء الأشخاص — فحص الإملاء فيها **أضيق** (فرق حرف واحد بالظبط).
     * ⚠️ متقاس على بيانات حقيقية: بفرق حرفين الحارس كان بيوقف **٣٠٪** من أسماء
     *    السائقين الجديدة («احمد عادل» ↔ «احمد جاد» · «محمد صالح» ↔ «محمد صلاح» —
     *    أشخاص مختلفون فعلاً). حارس بيزعّق ٣ مرات من ١٠ الموظف بيتعلّم يتجاهله،
     *    وساعتها ما بيحميش حاجة. **ما توسّعهاش تاني.**
     */
    private const PERSON_TYPES = ['driver', 'car_owner', 'owner', 'client'];

    /**
     * مفتاح «المفهوم» — أوسع من `normkey`: يشيل كلمة الوصف السابقة («ميناء») وأداة
     * التعريف، فيلمّ التهجئات اللي بتختلف في **أسلوب التسمية** لا في الإملاء.
     * ⚠️ يُستخدَم للتنبيه فقط (stop-and-choose) — **ولا يُوحَّد به تلقائيًا أبدًا**،
     *    لأنه متساهل بطبعه وممكن يجمع كيانين مختلفين فعلاً.
     */
    public static function conceptkey(string $type, string $s): string
    {
        $n = Maintenance::normkey($s);
        if ($n === '') { return ''; }
        $noise = [];
        foreach (self::NOISE[$type] ?? [] as $w) { $noise[Maintenance::normkey($w)] = true; }

        $tokens = array_values(array_filter(explode(' ', $n), static fn($t) => $t !== ''));
        // إزالة كلمات الوصف من **البداية** فقط (فـ«طريق» في «طريق التحدي» تُزال، وفي اسم آخر وسطًا تبقى)
        while ($tokens && isset($noise[$tokens[0]]) && count($tokens) > 1) { array_shift($tokens); }
        foreach ($tokens as $i => $t) {
            if (mb_substr($t, 0, 2, 'UTF-8') === 'ال' && mb_strlen($t, 'UTF-8') >= 5) {
                $tokens[$i] = mb_substr($t, 2, null, 'UTF-8');
            }
        }
        return implode(' ', $tokens);
    }

    /**
     * فلتر أخير على «القرب الإملائي» في أسماء الأشخاص.
     *
     * ⚠️ متقاس على بيانات حقيقية: حتى بفرق حرف واحد، الأسماء المصرية بتتشابه
     * بطبيعتها («احمد محمود» ↔ «احمد محمد» · «هشام محمد» ↔ «هشام احمد» ·
     * «احمد جمال» ↔ «احمد كمال») — كلهم أشخاص مختلفون، والحارس كان بيوقف **١٥٪**
     * من السائقين الجدد بسببهم.
     *
     * الفرق الحقيقي: في الغلط المطبعي الكلمة المختلفة **كلمة شاذّة** («الخجري»)،
     * أما في الاسمين المختلفين فهي **اسم شائع** بيتكرر في أسماء تانية كتير
     * («محمود» · «احمد»). فبنحسب شيوع الكلمة في كل الأسماء المعروفة: الشائعة
     * معناها اسم حقيقي مش غلطة.
     *
     * الأنواع غير الشخصية ما بتمرّش من هنا — الاسم فيها مش مركَّب من أسماء شائعة.
     */
    private static function looksLikeTypo(string $type, string $typed, string $stored): bool
    {
        if (!in_array($type, self::PERSON_TYPES, true)) { return true; }

        $a = array_values(array_filter(explode(' ', $typed)));
        $b = array_values(array_filter(explode(' ', $stored)));
        if (count($a) !== count($b)) { return true; }        // كلمة زايدة/ناقصة = غالبًا غلط

        $diff = [];
        foreach ($a as $i => $tok) { if (($b[$i] ?? null) !== $tok) { $diff[] = $tok; } }
        if (count($diff) !== 1) { return true; }

        // الكلمة المختلفة شائعة في أسماء تانية ⇒ اسم حقيقي مش غلطة مطبعية
        // ⚠️ الأقواس إجبارية: `??` أولويتها أقل من `<=` فمن غيرها الشرط بيتقرا
        //    `$x ?? (0 <= 1)` يعني بيرجّع true دايمًا والفلتر يبقى بلا أثر.
        return (self::tokenFrequency($type)[$diff[0]] ?? 0) <= 1;
    }

    /** @var array<string,array<string,int>> شيوع كل كلمة داخل أسماء النوع — كاش للطلب. */
    private static array $tokenFreq = [];

    /** @return array<string,int> */
    private static function tokenFrequency(string $type): array
    {
        if (isset(self::$tokenFreq[$type])) { return self::$tokenFreq[$type]; }
        $freq = [];
        foreach (self::names($type) as $name) {
            foreach (array_unique(array_filter(explode(' ', Maintenance::normkey($name)))) as $tok) {
                $freq[$tok] = ($freq[$tok] ?? 0) + 1;
            }
        }
        return self::$tokenFreq[$type] = $freq;
    }

    /** رموز الاسم بعد التطبيع — لفحص الاحتواء. */
    private static function tokens(string $type, string $s): array
    {
        $k = self::conceptkey($type, $s);
        return $k === '' ? [] : array_values(array_unique(array_filter(explode(' ', $k), static fn($t) => $t !== '')));
    }

    /**
     * حوكمة الاسم عند الحفظ — مصدر الحقيقة الوحيد للعميل والخادم معًا.
     * يُرجع:
     *   value    => التهجئة النهائية (المعتمدة لو تطابق normkey مع اسم موجود، وإلا كما أُدخِل)
     *   snapped  => هل استُبدل بالتهجئة المخزّنة (اختلاف همزة/ألف/ة/مسافات)؟
     *   conflict => اسم مخزّن قريب جدًا لكن بمفتاح مختلف، أو null
     *   reason   => سبب التعارض: concept | subset | spelling (أو null)
     *
     * ثلاث طبقات بترتيب الثقة:
     *   1) تطابق `normkey` ⇒ نفس الكيان قطعًا ⇒ يُوحَّد تلقائيًا بلا إزعاج.
     *   2) تطابق `conceptkey` («ميناء الإسكندرية» ≡ «الإسكندرية») ⇒ **يوقف ويخيّر**.
     *   3) احتواء الرموز («40» ⊂ «40 قدم») ⇒ يوقف ويخيّر — للأنواع غير الشخصية فقط.
     *   4) قرب إملائي: نفس أول حرفين + مسافة تحرير صغيرة ⇒ يوقف ويخيّر.
     * شرط أول حرفين في (4) يمنع الخلط بين أسماء قصيرة مختلفة (احمد ≠ محمد).
     */
    public static function resolve(string $type, string $raw): array
    {
        $raw = trim($raw);
        $out = ['value' => $raw, 'snapped' => false, 'conflict' => null, 'reason' => null];
        if ($raw === '') { return $out; }
        $nk = Maintenance::normkey($raw);
        if ($nk === '') { return $out; }

        // ⚠️ الجهات ليها **سجل مرجعي** (`destination_points`) فيه الاسم المعتمد
        //    وأسماء بديلة. التطابق التام أو البديل بيوحّد فورًا — ده اللي بيخلّي
        //    «ط التحدى» تتحفظ «طريق التحدي» **للأبد** بدل ما تتدمج بالإيد كل شوية.
        //    ⚠️ **التطابق الجزئي والإملائي متجاهَل هنا عن قصد** — `Track::resolveDestination`
        //    بترجّعهم كمان، لكن التوحيد التلقائي بيهم ممكن يحوّل «بدرشين» لـ«بدر».
        //    اللي مش تطابق تام/بديل بيكمّل على الحارس العادي (يوقف ويخيّر).
        if ($type === 'destination' && ($canon = self::registryName($raw)) !== null) {
            return ['value' => $canon, 'snapped' => ($canon !== $raw), 'conflict' => null, 'reason' => null];
        }

        return self::against($type, $raw, self::names($type));
    }

    /**
     * الاسم المعتمد للجهة من السجل المرجعي — بالتطابق التام أو الاسم البديل بس.
     * بيرجّع null لو الجهة مش معروفة أو التطابق ضعيف (جزئي/إملائي).
     */
    private static function registryName(string $raw): ?string
    {
        try {
            $hit = Track::resolveDestination($raw);
        } catch (\Throwable $e) {
            return null;   // وحدة التتبع مش مترحّلة — الحارس بيكمّل عادي
        }
        if (!$hit || !in_array($hit['match'] ?? '', ['exact', 'alias'], true)) { return null; }
        $name = trim((string) ($hit['point']['name'] ?? ''));
        return $name !== '' ? $name : null;
    }

    /**
     * نفس منطق `resolve()` بالظبط لكن على **قائمة أسماء مُمرَّرة** بدل كل المعروف.
     *
     * ⚠️ ليه موجودة: `resolve()` بتقرا من البيانات الحيّة كمان، فالقيمة الملوَّثة
     *    **المتسجّلة بالفعل** بيطابقها المستوى ١ وبتعدّي. لما نسأل «هل القيمة دي
     *    تتضاف للقائمة المعتمدة؟» لازم نقارنها **بالقائمة وحدها** — وإلا كل قيمة
     *    موجودة في البيانات بتعدّي، وده بالظبط اللي فشّل أول نسخة من ترحيل v58
     *    (زرعت «الإسكندرية» و«20 قرش» في القوائم المقفولة).
     *
     * @param list<string> $names القائمة اللي بنقارن بيها
     */
    public static function against(string $type, string $raw, array $names): array
    {
        $raw = trim($raw);
        $out = ['value' => $raw, 'snapped' => false, 'conflict' => null, 'reason' => null];
        if ($raw === '') { return $out; }
        $nk = Maintenance::normkey($raw);
        if ($nk === '') { return $out; }

        // 1) تطابق المفتاح المُوحَّد ⇒ استخدم التهجئة المخزّنة (توحيد تلقائي آمن)
        foreach ($names as $name) {
            if (Maintenance::normkey($name) === $nk) {
                $out['value']   = $name;
                $out['snapped'] = ($name !== $raw);
                return $out;
            }
        }

        // 2) تطابق مفتاح المفهوم ⇒ نفس المكان/الصنف باسمين مختلفين
        $ck = self::conceptkey($type, $raw);
        if ($ck !== '') {
            foreach ($names as $name) {
                if (Maintenance::normkey($name) === $nk) { continue; }
                if (self::conceptkey($type, $name) === $ck) {
                    $out['conflict'] = $name;
                    $out['reason']   = 'concept';
                    return $out;
                }
            }
        }

        // 3) احتواء الرموز — «40» جوّه «40 قدم»، «اكتوبر» جوّه «6 اكتوبر»
        if (in_array($type, self::SUBSET_TYPES, true)) {
            $mine = self::tokens($type, $raw);
            if ($mine) {
                $best = null; $bestGap = PHP_INT_MAX;
                foreach ($names as $name) {
                    $other = self::tokens($type, $name);
                    if (!$other || $other === $mine) { continue; }
                    $gap = abs(count($other) - count($mine));
                    if ($gap === 0 || $gap > 2) { continue; }
                    $small = count($mine) < count($other) ? $mine : $other;
                    $big   = count($mine) < count($other) ? $other : $mine;
                    if (array_diff($small, $big)) { continue; }   // ليس احتواءً كاملاً
                    // ⚠️ الكلمة الزيادة لازم تكون **رقمًا** («التجمع» ⊂ «التجمع 5» ·
                    //    «اكتوبر» ⊂ «6 اكتوبر») أو النوع نوع وحدة («40» ⊂ «40 قدم»).
                    //    من غير الشرط ده الاحتواء بيلمّ أماكن مختلفة فعلاً:
                    //    «برج» ⊂ «برج العرب» · «منيا» ⊂ «منيا القمح» · «سويس» ⊂ «جسر السويس».
                    if (!in_array($type, self::UNIT_TYPES, true)) {
                        $extra = array_diff($big, $small);
                        foreach ($extra as $tok) {
                            if (!preg_match('/^\d+$/', ar2en_digits($tok))) { continue 2; }
                        }
                    }
                    if ($gap < $bestGap) { $bestGap = $gap; $best = $name; }
                }
                if ($best !== null) {
                    $out['conflict'] = $best;
                    $out['reason']   = 'subset';
                    return $out;
                }

                // ٣ب) كلمة واحدة بس مختلفة، وهي غلط مطبعي واضح فيها («20 قرش» ↔ «20 قدم»).
                // ⚠️ **لأنواع الوحدة بس** — متقاس على البيانات: على الجهات كان بيلمّ
                //    «العبور» مع «العاشر» (نفس الطول ونفس أول حرف وفرق حرفين) وهُمّ
                //    مدينتان مختلفتان. مفردات الوحدة قليلة ومعروفة فالخطر فيها ضئيل.
                if (!in_array($type, self::UNIT_TYPES, true)) { return $out; }
                foreach ($names as $name) {
                    $other = self::tokens($type, $name);
                    if (count($other) !== count($mine)) { continue; }
                    $diff = array_keys(array_udiff_assoc($mine, $other, static fn($a, $b) => strcmp($a, $b)));
                    if (count($diff) !== 1) { continue; }
                    $i = $diff[0];
                    $x = $mine[$i]; $y = $other[$i];
                    if (mb_strlen($x, 'UTF-8') < 3 || mb_strlen($x, 'UTF-8') !== mb_strlen($y, 'UTF-8')) { continue; }
                    if (mb_substr($x, 0, 1, 'UTF-8') !== mb_substr($y, 0, 1, 'UTF-8')) { continue; }
                    if (self::distance($x, $y) > 2) { continue; }
                    $out['conflict'] = $name;
                    $out['reason']   = 'spelling';
                    return $out;
                }
            }
        }

        // 4) قرب إملائي محافظ: نفس أول حرفين + مسافة تحرير صغيرة، لأسماء طولها ≥ 4
        $len = mb_strlen($nk, 'UTF-8');
        if ($len >= 4) {
            // أسماء الأشخاص: حرف واحد بالظبط — الشرح في PERSON_TYPES
            $maxDist = in_array($type, self::PERSON_TYPES, true) ? 1 : ($len <= 8 ? 1 : 2);
            $pfx = mb_substr($nk, 0, 2, 'UTF-8');
            $bestDist = PHP_INT_MAX;
            foreach ($names as $name) {
                $n = Maintenance::normkey($name);
                if ($n === '' || $n === $nk) { continue; }
                if (mb_substr($n, 0, 2, 'UTF-8') !== $pfx) { continue; }
                $d = self::distance($nk, $n);
                if ($d > $maxDist || $d >= $bestDist) { continue; }
                if (!self::looksLikeTypo($type, $nk, $n)) { continue; }
                $bestDist = $d; $out['conflict'] = $name; $out['reason'] = 'spelling';
            }
        }
        return $out;
    }

    /**
     * أعمدة البيانات الحيّة لكل نوع — بالإضافة إلى القائمة المنسدلة.
     * ⚠️ لازم يفضل فيه مدخل لكل نوع بيتحرس عليه، وإلا الحارس يشوف القائمة
     *    المنسدلة بس ويعدّي أي تهجئة متسجّلة في البيانات ومش في القائمة.
     */
    private const SOURCES = [
        'owner'          => [['bawalis','declaration_owner'], ['declaration_owners','name']],
        'company'        => [['bawalis','company'], ['companies','name'], ['permits','company']],
        'client'         => [['agents','name'], ['permits','client']],
        'driver'         => [['bawalis','driver'], ['movements','driver']],
        'car_owner'      => [['bawalis','car_owner'], ['movements','car_owner']],
        'destination'    => [['bawalis','destination'], ['permits','destination']],
        'classification' => [['bawalis','classification']],
        'container_type' => [['bawalis','container_type'], ['permits','container_type'], ['permit_movements','container_type']],
        'port'           => [['permits','port']],
        'agency'         => [['permits','agency']],
        'shipping_co'    => [['permits','shipping_co']],
    ];

    /**
     * جداول الأسماء القانونية — تهجئتها تغلب أي تهجئة تانية مهما كان عدد استخدامها.
     * (نفس منطق `prefer` في `Maintenance::groups`.)
     */
    private const MASTER = [
        'company' => ['companies', 'name'],
        'client'  => ['agents', 'name'],
        'owner'   => ['declaration_owners', 'name'],
    ];

    /** @var array<string,list<string>> كاش داخل الطلب — `resolve` بتتنادى لكل حقل في نفس الحفظة. */
    private static array $namesCache = [];

    public static function clearNamesCache(): void { self::$namesCache = []; self::$tokenFreq = []; }

    /**
     * كل الأسماء المعروفة للنوع، **مرتَّبة بالأولوية**: الاسم القانوني أولاً، ثم الأكثر
     * استخدامًا، ثم الأطول.
     * ⚠️ الترتيب ده مش تجميل — `resolve` بتاخد **أول** تطابق في `normkey`، فترتيب
     *    الاستخدام هو اللي بيخلّي «بني سويف» (١٠) تتحفظ باسم «بنى سويف» (٢٣٨) بدل
     *    العكس. لو رجع الترتيب عشوائيًا، كل حفظة تشدّ التهجئة في اتجاه مختلف.
     */
    private static function names(string $type): array
    {
        if (isset(self::$namesCache[$type])) { return self::$namesCache[$type]; }

        $weight = [];   // الاسم الخام => الوزن
        $add = static function (string $name, int $w) use (&$weight): void {
            $name = trim($name);
            if ($name === '') { return; }
            $weight[$name] = ($weight[$name] ?? 0) + $w;
        };

        // القائمة المنسدلة: معروفة لكن بلا استخدام — وزن ابتدائي صغير يخلّيها تسبق المجهول
        if (in_array($type, array_keys(Lookup::TYPES), true)) {
            foreach (Lookup::ofType($type) as $v) { $add($v, 1); }
        }
        // البيانات الحيّة: الوزن = عدد مرات الاستخدام الفعلي
        foreach (self::SOURCES[$type] ?? [] as [$table, $column]) {
            try {
                foreach (Database::all(
                    "SELECT TRIM(`$column`) name, COUNT(*) c FROM `$table`
                      WHERE `$column` IS NOT NULL AND TRIM(`$column`) <> ''
                      GROUP BY TRIM(`$column`) ORDER BY c DESC LIMIT 2000"
                ) as $row) { $add((string)$row['name'], (int)$row['c']); }
            } catch (\Throwable $e) { /* جدول غير موجود بعد — يُتخطّى بأمان */ }
        }
        // الاسم القانوني يغلب دائمًا
        if (isset(self::MASTER[$type])) {
            [$t, $c] = self::MASTER[$type];
            try {
                foreach (Database::all("SELECT `$c` name FROM `$t` LIMIT 5000") as $row) {
                    $add((string)$row['name'], 1_000_000);
                }
            } catch (\Throwable $e) { /* لا يوجد جدول أسماء قانونية لهذا النوع */ }
        }

        uksort($weight, static function (string $a, string $b) use ($weight): int {
            return ($weight[$b] <=> $weight[$a])
                ?: (mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'))
                ?: strcmp($a, $b);
        });

        // توحيد بالمفتاح — الأول في الترتيب (الأعلى وزنًا) هو التهجئة المعتمدة
        // ⚠️ `(string)` إجبارية: PHP بتحوّل مفاتيح المصفوفة الرقمية لأعداد صحيحة، فاسم
        //    زي «40» بيرجع `int(40)`، والمقارنة `$name !== $raw` تبقى صح غلط (نوع مختلف)
        //    فالحارس يقول «وحّدت التهجئة» وهو ما غيّر حاجة، ويتخزّن رقم مكان النص.
        $seen = []; $unique = [];
        foreach (array_keys($weight) as $name) {
            $name = (string) $name;
            $key  = Maintenance::normkey($name);
            if ($key !== '' && !isset($seen[$key])) { $seen[$key] = true; $unique[] = $name; }
        }
        return self::$namesCache[$type] = $unique;
    }

    /** Levenshtein Unicode صغير للأسماء العربية. */
    private static function distance(string $a, string $b): int
    {
        $aa=preg_split('//u',$a,-1,PREG_SPLIT_NO_EMPTY); $bb=preg_split('//u',$b,-1,PREG_SPLIT_NO_EMPTY);
        $prev=range(0,count($bb));
        foreach ($aa as $i=>$ca) {
            $cur=[$i+1];
            foreach ($bb as $j=>$cb) { $cur[$j+1]=min($cur[$j]+1,$prev[$j+1]+1,$prev[$j]+($ca===$cb?0:1)); }
            $prev=$cur;
        }
        return $prev[count($bb)];
    }
}
