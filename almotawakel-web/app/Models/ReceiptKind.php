<?php
namespace App\Models;

use App\Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * قاموس أنواع الإيصالات — **المصدر الوحيد** لتحويل نص الإيصال لنوع (v101)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * المالك بيكتب اسم الإيصال بإيده في خانة نصّية حرّة، فنفس النوع بيتكتب بعشرات
 * الصيغ. المقيس على بيانات الإنتاج: **٢٠٦ صيغة مختلفة** لـ**٢١ نوع** فعليّ —
 * «إيصال هيئة» و«ايصال هييه» و«هيئة صادر» و«٢ ايصال هيئة» كلهم نوع واحد.
 *
 * ⚠️ **بلا التوحيد ده الموديل بيتعلّم هُبَاء.** كل صيغة بتتحسب نوعًا مستقلًّا،
 *    فبدل «الهيئة بتظهر في ٨٥٪ من الأذون» بيطلع «هييه ٤٠٪ · هيئة ٣٠٪ · هيئة
 *    صادر ٣٪» — وكلهم تحت حد الدليل فالنظام بيسكت خالص. نفس منطق تحذير ٣ في
 *    CLAUDE.md (الربط بأسماء نصية بلا FK) بس على مستوى البند مش الطرف.
 *
 * ⚠️ **الترتيب في `SEED` حاكم — الأخصّ قبل الأعمّ.** «هيئة فوارغ» لازم تتصنّف
 *    قبل «هيئة» وإلا الاتنين يبقوا نوعًا واحدًا ويضيع الفرق اللي المالك
 *    بيفرّقه فعلاً. و`exc` هي اللي بتمنع العكس («هيئة» ما تبلعش «هيئة فوارغ»).
 *    الترتيب ده هو نفس منطق `Permit::expectedReceiptDefs` (v32) — القرار
 *    ما اتغيّرش، بس بقى في مكان واحد الاتنين بيقروا منه.
 *
 * ⚠️ **الأنواع المكتشَفة بتتسجّل في `receipt_kinds` مش في الكود.** أي صيغة
 *    مالهاش نوع في البذرة وظهرت `MIN_DISCOVER` مرة أو أكتر بتبقى نوعًا جديدًا
 *    تلقائيًّا. اللي أقل من كده بيتسمّى «أخرى» وما بيتوقّعش — بند اتكتب مرة
 *    واحدة مش نوع، وتوقّعه بيحرق مصداقية الشاشة كلها.
 */
final class ReceiptKind
{
    /** الصيغة لازم تتكرّر كام مرة عشان تبقى نوعًا معترفًا بيه. */
    public const MIN_DISCOVER = 3;

    /** اسم النوع المرتجَع للبنود اللي مالهاش نوع معروف. */
    public const OTHER = 'أخرى';

    /**
     * أنواع البذرة — `[الاسم, كلمات تُطابِق (أي واحدة), كلمات تمنع]`.
     *
     * الكلمات **مكتوبة بالصيغة المُوحَّدة** (بعد `normalize`) عن قصد: «هيه»
     * مش «هيئة». الهمزات بتتشال والتاء المربوطة بتبقى هاء، فالمطابقة بتمسك
     * كل تهجئات المالك بلا ما نعدّدها.
     */
    public const SEED = [
        ['هيئة فوارغ',   ['فوارغ'],                                     []],
        ['تعتيق صينية',  ['صينيه'],                                     []],
        ['تعتيق حاويات', ['تعتيق حاويه', 'تعتيق حاويات', 'اركاس'],       []],
        /* «هييه» تهجئة حقيقية بتحصل لما حد يكتب الهمزة ياء بدل ما يشيلها —
           والتوحيد بيشيل الهمزة فبتفضل «هييه» مالهاش مطابق. سطر تاني أرخص
           من أنها تقع في «أخرى» وتضيع من التدريب. */
        ['هيئة',         ['هيه', 'هييه'],                               ['فوارغ']],
        ['تعتيق',        ['تعتيق'],                                     ['صينيه', 'حاويه', 'حاويات', 'اركاس']],
        ['جيش',          ['جيش'],                                       []],
        ['تخصيص',        ['تخصيص'],                                     []],
        ['حمولة',        ['حموله', 'تحميل'],                            []],
        ['محافظة',       ['محافظه'],                                    []],
        ['نبطشية',       ['نبطشيه', 'نوباتحيه', 'نوبتشيه'],             []],
        ['إشعاع',        ['اشعاع'],                                     []],
        ['طرق وكباري',   ['طرق', 'كباري'],                              []],
        ['مستودعات',     ['مستودعات'],                                  []],
        ['ميزان',        ['ميزان'],                                     []],
        ['بوصلة',        ['بوصله'],                                     []],
        ['زيادة وزن',    ['زياده وزن'],                                 []],
        ['لا مانع',      ['لا مانع', 'لامانع'],                         []],
        ['غسيل',         ['غسيل'],                                      []],
        ['تحويلة',       ['تحويل'],                                     []],
        ['تأمين',        ['تامين'],                                     []],
        ['سحب إذن',      ['سحب اذن'],                                   []],
    ];

    /** كاش الأنواع المكتشَفة — `norm_key => name`. */
    private static ?array $learned = null;

    /**
     * توحيد نص الإيصال: همزات وتاء مربوطة وأرقام وكلمة «إيصال» المكرَّرة.
     *
     * ⚠️ **الأرقام بتتشال مش بتتقرا.** «٢ ايصال محافظة» معناها بند واحد بكمية
     *    ٢، والكمية محلّها عمود `qty` — لو قريناها من الاسم كنّا هنحسبها مرتين.
     */
    public static function normalize(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') { return ''; }
        $s = str_replace("\u{0640}", '', $s);                                 // تطويل
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        $s = str_replace(['ء', 'ئ', 'ؤ'], '', $s);                            // كل صور الهمزة تتشال
        $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s) ?? $s;            // تشكيل
        $s = preg_replace('/[0-9\x{0660}-\x{0669}]+/u', ' ', $s) ?? $s;       // أرقام (عربية وإنجليزية)
        $s = preg_replace('/(ايصال|ايصالات|فاتوره|رسوم)/u', ' ', $s) ?? $s;   // كلمات حشو بتتكرّر
        $s = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s]/u', ' ', $s) ?? $s;     // ترقيم
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * نوع البند من نصّه — `null` لو الاسم فاضي، و`OTHER` لو مش معروف.
     *
     * ⚠️ **البذرة قبل المكتشَف** عن قصد: الأنواع المعرَّفة في الكود هي عقد
     *    مع محرّك قواعد v32، ولو صيغة مكتشَفة سبقتها كان ممكن نوع أساسي
     *    يتشقّ لنصّين **بالسكوت** بعد أي تدريب.
     */
    public static function of(string $note): ?string
    {
        $n = self::normalize($note);
        if ($n === '') { return null; }

        foreach (self::SEED as [$name, $inc, $exc]) {
            $hit = false;
            foreach ($inc as $w) { if (mb_strpos($n, $w) !== false) { $hit = true; break; } }
            if (!$hit) { continue; }
            foreach ($exc as $w) { if (mb_strpos($n, $w) !== false) { $hit = false; break; } }
            if ($hit) { return $name; }
        }

        $learned = self::learned();
        return $learned[$n] ?? self::OTHER;
    }

    /** أسماء أنواع البذرة بترتيبها. */
    public static function seedNames(): array
    {
        return array_column(self::SEED, 0);
    }

    /** الأنواع المكتشَفة من الداتا — `norm_key => name` (بكاش لكل طلب). */
    public static function learned(): array
    {
        if (self::$learned !== null) { return self::$learned; }
        self::$learned = [];
        try {
            foreach (Database::all(
                "SELECT norm_key, name FROM receipt_kinds
                  WHERE origin = 'learned' AND active = 1 AND norm_key IS NOT NULL") as $r) {
                self::$learned[(string) $r['norm_key']] = (string) $r['name'];
            }
        } catch (\Throwable) {
            // الجدول لسه ما اتعملش (قبل v101) — البذرة لوحدها شغّالة
            self::$learned = [];
        }
        return self::$learned;
    }

    /**
     * تصفير الكاش — **إجباري في آخر أي تدريب**.
     * (مصيدة موثّقة في CLAUDE.md: كاش ساكن في الموديل بيدّي قيمة قديمة
     *  كأنها الحقيقة بعد ما الجدول اتغيّر.)
     */
    public static function flush(): void
    {
        self::$learned = null;
    }

    /** كل الأنواع النشطة (بذرة + مكتشَفة) بترتيب البذرة الأول. */
    public static function all(): array
    {
        $out = self::seedNames();
        foreach (self::learned() as $name) { $out[] = $name; }
        return array_values(array_unique($out));
    }

    /**
     * مفتاح نطاق مُوحَّد — «MSC» و«msc » و«M.S.C» مفتاح واحد.
     *
     * ⚠️ الأسماء بتمرّ على `PartyMaster` الأول لما يكون ليها نوع طرف معروف،
     *    عشان «المصرية الألمانية» و«المصريه الالمانيه» ما يتفتّتوش (تحذير ٣).
     */
    public static function scopeKey(string $value, ?string $partyType = null): string
    {
        $v = trim($value);
        if ($v === '') { return ''; }

        /* ⚠️ **الكاش هنا مش رفاهية.** `PartyMaster::resolve` بتضرب القاعدة كل
           نداء، والتدريب بينده المفاتيح لكل إذن في كل طيّة تقييم — من غير
           الكاش ده الجولة بتطلع آلاف الاستعلامات على ٢٠٠ إذن. */
        static $cache = [];
        $ck = ($partyType ?? '-') . "\0" . $v;
        if (isset($cache[$ck])) { return $cache[$ck]; }

        if ($partyType !== null) {
            $party = PartyMaster::resolve($partyType, $v);
            if ($party) { $v = (string) $party['name']; }
        }
        return $cache[$ck] = mb_substr(mb_strtolower(self::normalize($v)), 0, 180);
    }

    /**
     * تسجيل الأنواع المكتشَفة بعد جولة تدريب.
     * $counts = `norm_key => ['hits'=>int,'label'=>نص خام يمثّله,'first'=>date,'last'=>date]`
     *
     * ⚠️ **الاسم المعروض بيتاخد من أشهر صيغة خام** مش من المُوحَّدة: المالك
     *    بيعرف «لا مانع» مش «لا مانع» بعد شيل الهمزات — التوحيد للمطابقة
     *    والعرض للبني آدم.
     */
    public static function registerLearned(array $counts): int
    {
        $n = 0;
        $seed = array_map(self::normalize(...), self::seedNames());
        foreach ($counts as $normKey => $info) {
            $normKey = (string) $normKey;
            $hits    = (int) ($info['hits'] ?? 0);
            if ($normKey === '' || $hits < self::MIN_DISCOVER) { continue; }
            // ما نسجّلش اسمًا بيتصادم مع بذرة (بيبقى مصنَّف عليها أصلاً)
            $label = trim((string) ($info['label'] ?? $normKey));
            if ($label === '' || in_array(self::normalize($label), $seed, true)) { continue; }
            if (in_array($label, self::seedNames(), true)) { continue; }
            Database::exec(
                "INSERT INTO receipt_kinds (name, norm_key, origin, hits, first_seen, last_seen)
                      VALUES (?, ?, 'learned', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE hits = VALUES(hits), last_seen = VALUES(last_seen), name = VALUES(name)",
                [$label, $normKey, $hits, $info['first'] ?? null, $info['last'] ?? null]
            );
            $n++;
        }
        self::flush();
        return $n;
    }

    /** صفوف القاموس للعرض في شاشة الموديل. */
    public static function catalog(): array
    {
        $rows = [];
        foreach (self::SEED as $i => [$name, $inc, $exc]) {
            $rows[] = ['name' => $name, 'origin' => 'seed', 'hits' => null,
                       'match' => implode('، ', $inc), 'except' => implode('، ', $exc), 'sort' => $i];
        }
        try {
            foreach (Database::all(
                "SELECT name, hits, norm_key FROM receipt_kinds
                  WHERE origin = 'learned' AND active = 1 ORDER BY hits DESC") as $r) {
                $rows[] = ['name' => (string) $r['name'], 'origin' => 'learned', 'hits' => (int) $r['hits'],
                           'match' => (string) $r['norm_key'], 'except' => '', 'sort' => 900];
            }
        } catch (\Throwable) { /* قبل الترحيل */ }
        return $rows;
    }
}
