<?php
namespace App\Models;

use App\Core\App;
use App\Core\Auth;
use App\Core\Database;
use App\Core\PrivateFile;

/**
 * خزنة الشركة (v66) — الخزنة الأكبر اللي بتغذّي خزنة المكتب.
 *
 * ═══ الفكرة ═══
 * النظام كان بيعرف خزنة واحدة: «خزنة المكتب» (`Closing::currentBalance`) — الدرج
 * اللي بيتصرف منه اليوم بيومه. خزنة الشركة مستوى فوقها: الكاش الكبير اللي بيدخل
 * من البنك ومن تحصيل العملاء، وبينزل للمكتب على دفعات.
 *
 *   رصيد الشركة = Σ(إيداع + تحصيل عميل + سحب من المكتب) − Σ(تحويل للمكتب)
 *
 * ═══ ⚠️⚠️ العزل بالبناء — أهم قاعدة في الوحدة، لا تعكسها ═══
 * جدول `company_safe_entries` **كل كود الخزنة عمره ما سمع عنه** (نفس منطق
 * `rep_misc_expenses` v39 و`car_expenses` v54). فمفيش «استثناء» لازم نفتكره في
 * `Closing`/`DailyReview`/`Metrics`/`Expense`/`backfill_closings` — ونسيان واحد
 * كان بيبوّظ الرصيد بلا أي خطأ ظاهر.
 *
 * وإزاي بتغذّي المكتب من غير ما تمسّه؟ **بصف `manual_custody`** (نمط
 * `ClientPayment::route='cash'` الموثَّق في v23 بالحرف):
 *   • `to_office`   ⇒ `manual_custody.amount = +amount`  (كاش داخل الدرج)
 *   • `from_office` ⇒ `manual_custody.amount = −amount`  (كاش خارج من الدرج)
 * فـ`Closing` و`DailyReview` بيشوفوا **صف عهدة عادي** زي أي إضافة يدوية،
 * والحركة بتبان في شاشة التقفيل تلقائيًّا في قسم «العهد المضافة».
 *
 * ═══ ⚠️ قرارات المالك المحسومة (سؤال مباشر 2026-08-08) ═══
 *  • **رصيد جاري مستمر بلا تقفيل يومي** — زي كروت الفيزا (v22): مفيش ترحيل
 *    ولا رصيد مثبَّت ولا `closing_balance`. الرصيد تراكمي دايمًا.
 *
 * ═══ v67 — التوسّع (قرار مالك جديد، نفس اليوم) ═══
 * v66 كانت «تغذّي المكتب وبس». المالك طلب حزمة كاملة، فاتضافت أربع حركات:
 *   • `expense`    مصروف بيتصرف من الخزنة مباشرة (بفئة وجهة صرف ومرفق).
 *   • `to_visa`    شحن كارت فيزا من خزنة الشركة.
 *   • `from_visa`  سحب كاش من كارت فيزا لخزنة الشركة (شحنة بالسالب).
 *   • `to_rep`     تنزيل عهدة لمندوب من خزنة الشركة على طول.
 *
 * ⚠️⚠️ **العزل بالبناء لسه هو القاعدة.** الأنواع الجديدة إما بلا أي أثر برّه
 *    الجدول (`expense`)، أو أثرها بيمرّ على **API الوحدة التانية** بالظبط زي ما
 *    التحويل للمكتب بيمرّ على `Custody::createTopup`:
 *      `to_visa`/`from_visa` → `VisaCard::createDeposit`
 *      `to_rep`              → `RepCustody::createDeposit(..., 'company')`
 *    فمفيش سطر واحد اتضاف لـ`Closing`/`DailyReview`/`Metrics`/`Expense`.
 *
 * ⚠️⚠️ **قرار المالك: المصروف معزول عن `/expenses`.** مش بيظهر في سجل
 *    المصروفات العام ولا التقارير ولا كارت «مصروفات اليوم» في الداشبورد —
 *    ومفيش leg في `Expense::unionSql()`. الأثر المعروف والمقبول: إجمالي
 *    مصروفات النظام أقل من الحقيقة بمقدار دول، والشاشة بتقول ده بالنص.
 *
 * ⚠️⚠️ **أي نوع بيولّد صفًّا في وحدة تانية لازم الاتجاهين يمشوا مع بعض** —
 *    خصم من هنا **و** إضافة هناك، في نفس المعاملة، والحذف يعكس الاتنين. نقص
 *    واحد منهم = فلوس بتتبخّر أو بتتكرّر (نفس تحذير الفيزا في v45).
 *
 * ═══ ⚠️ نقاط لازم تفضل صح ═══
 *  • **التحويلات مع المكتب بس هي اللي بتمسّ الدرج** ⇒ هي وحدها اللي بتمرّ على
 *    `Closing::guard` (يوم متقفل = ممنوع). الباقي كله ما بيمسّش رصيدًا مثبَّتًا
 *    فمالوش guard — نفس منطق `ClientPayment` مع `route='visa'` بالظبط.
 *  • **الحذف بيشيل كل الصفوف المُولَّدة مع الصف الأصلي** جوّه معاملة واحدة.
 *  • **تجاوز رصيد المكتب في `from_office` تحذير مش منع** (فلسفة v49: الرصيد
 *    السالب مسموح لأنه بيسجّل واقعة حصلت). لكن الرسالة بتقول للمالك صراحةً إن
 *    الدرج بقى سالب.
 *
 * ═══ ⚠️ نقاط لازم تفضل صح ═══
 *  • **التحويلات بس هي اللي بتمسّ المكتب** ⇒ هي وحدها اللي بتمرّ على
 *    `Closing::guard` (يوم متقفل = ممنوع). الإيداع والتحصيل ما بيمسّوش الدرج
 *    فمالهمش guard — نفس منطق `ClientPayment` مع `route='visa'` بالظبط.
 *  • **الحذف بيشيل الصفّين مع بعض** (صف الشركة + صف `manual_custody`) جوّه
 *    معاملة واحدة. لو اتشال واحد بس، الفلوس بتتبخّر أو تتكرّر.
 *  • **تجاوز رصيد المكتب في `from_office` تحذير مش منع** (فلسفة v49: الرصيد
 *    السالب مسموح لأنه بيسجّل واقعة حصلت). لكن الرسالة بتقول للمالك صراحةً إن
 *    الدرج بقى سالب.
 */
class CompanySafe
{
    /** أنواع الحركة المعتمدة — القايمة البيضا الوحيدة (يقراها الترحيل والفاحص). */
    public const KINDS = [
        'deposit', 'from_client', 'from_office', 'to_office',        // v66
        'expense', 'to_visa', 'from_visa', 'to_rep',                 // v67
    ];

    /** الأنواع اللي **بتزوّد** رصيد الشركة. الباقي بينقّصه. */
    private const IN_KINDS = ['deposit', 'from_client', 'from_office', 'from_visa'];

    /**
     * الأنواع اللي المالك يقدر يسجّلها بإيده من الشاشة.
     * ⚠️ `from_client` **مش منها** عن قصد — بيتولّد من «سداد العملاء» بس،
     *    عشان الدفعة تفضل مصدر الحقيقة الوحيد وما يبقاش فيه صفّان لنفس التحصيل.
     */
    public const MANUAL_KINDS = [
        'deposit', 'from_office', 'to_office',
        'expense', 'to_visa', 'from_visa', 'to_rep',
    ];

    /** الأنواع اللي بتولّد صف `manual_custody` (بتمسّ خزنة المكتب). */
    public const OFFICE_KINDS = ['to_office', 'from_office'];

    /** الأنواع اللي بتولّد شحنة على كارت فيزا (`visa_deposits`). */
    public const VISA_KINDS = ['to_visa', 'from_visa'];

    /**
     * ⚠️ الأنواع اللي **بتوصّل فلوس لمحفظة تانية في النظام** — لازم كل واحد فيهم
     *    يولّد صفه المقابل في نفس المعاملة، وحذفه يشيل الاتنين. القايمة دي
     *    موجودة عشان الفاحص يعدّهم ويتأكد إن مفيش نوع اتضاف بلا عكس.
     */
    public const LINKED_KINDS = ['to_office', 'from_office', 'to_visa', 'from_visa', 'to_rep'];

    /**
     * فئات المصروف — قايمة مقفولة (v58 فلسفة: المفردات محدودة ومعروفة).
     * ⚠️ المفتاح بيتخزَّن في القاعدة فما يتغيّرش؛ التسمية بس هي اللي تتعدّل.
     */
    public const EXPENSE_CATS = [
        'rent'      => 'إيجار',
        'salaries'  => 'مرتبات وأجور',
        'utilities' => 'كهربا ومياه وغاز',
        'telecom'   => 'نت وتليفونات',
        'gov'       => 'ضرايب ورسوم حكومية',
        'maint'     => 'صيانة وتشغيل',
        'supplies'  => 'أدوات ومستلزمات مكتبية',
        'transport' => 'مواصلات وانتقالات',
        'legal'     => 'محاماة ومحاسبة',
        'bank'      => 'مصاريف بنكية',
        'other'     => 'أخرى',
    ];

    public static function catLabel(?string $cat): string
    {
        $cat = (string) $cat;
        return self::EXPENSE_CATS[$cat] ?? ($cat !== '' ? $cat : 'بدون فئة');
    }

    /** ⚠️ الفورم مش مصدر ثقة — أي فئة برّه القايمة بترجع `other`. */
    public static function cleanCat(?string $cat): string
    {
        $cat = trim((string) $cat);
        return isset(self::EXPENSE_CATS[$cat]) ? $cat : 'other';
    }

    public static function labels(): array
    {
        return [
            'deposit'     => 'إيداع كاش / سحب من البنك',
            'from_client' => 'تحصيل من عميل',
            'from_office' => 'سحب من خزنة المكتب',
            'to_office'   => 'تحويل لخزنة المكتب',
            'expense'     => 'مصروف من خزنة الشركة',
            'to_visa'     => 'شحن كارت فيزا',
            'from_visa'   => 'سحب كاش من كارت فيزا',
            'to_rep'      => 'عهدة لمندوب',
        ];
    }

    /** أيقونة كل نوع — ⚠️ كل اسم لازم يكون في خريطة `icon()` وإلا بيرسم دايرة فاضية. */
    public static function icons(): array
    {
        return [
            'deposit'     => 'building',   'from_client' => 'user',
            'from_office' => 'landmark',   'to_office'   => 'landmark',
            'expense'     => 'trending-down', 'to_visa'  => 'credit-card',
            'from_visa'   => 'credit-card',   'to_rep'   => 'truck',
        ];
    }

    public static function label(string $kind): string
    {
        return self::labels()[$kind] ?? $kind;
    }

    public static function icon(string $kind): string
    {
        return self::icons()[$kind] ?? 'file-text';
    }

    /** هل النوع ده بيزوّد رصيد الشركة؟ (المصدر الوحيد لاتجاه أي عرض) */
    public static function isIn(string $kind): bool
    {
        return in_array($kind, self::IN_KINDS, true);
    }

    // ───────────────────────────────────────────── الجاهزية

    private static ?bool $ready = null;

    /**
     * هل الجدول موجود؟ النظام لازم يشتغل عادي **قبل** migrate_v66 — كل قارئ
     * بيرجّع صفر/فاضي بدل ما يرمي، ومفيش شاشة بتقع.
     */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'company_safe_entries'");
        }
        return self::$ready;
    }

    /** ⚠️ لازم تتنادى في آخر الترحيل — كاش المخطط الساكن بيفشّل التحقّق (مصيدة v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null;
        return self::ready();
    }

    /** تعبير SQL للاتجاه: `+` للداخل و`−` للخارج. المصدر الوحيد لحساب الرصيد. */
    private static function signedSql(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $in = "'" . implode("','", self::IN_KINDS) . "'";
        return "CASE WHEN {$p}kind IN ($in) THEN {$p}amount ELSE -{$p}amount END";
    }

    // ───────────────────────────────────────────── الأرصدة

    /** رصيد خزنة الشركة الحالي (تراكمي — مفيش تقفيل). */
    public static function balance(): float
    {
        if (!self::ready()) { return 0.0; }
        return round((float) Database::scalar(
            'SELECT COALESCE(SUM(' . self::signedSql() . '),0) FROM company_safe_entries'), 2);
    }

    /**
     * تفكيك الرصيد على مصادره — لنافذة التفاصيل («ممنوع رقم أصمّ»).
     * @return array{deposit:float,from_client:float,from_office:float,to_office:float,balance:float,count:int}
     */
    public static function breakdown(): array
    {
        $out = array_fill_keys(self::KINDS, 0.0);
        $out['balance'] = 0.0;
        $out['count']   = 0;
        if (!self::ready()) { return $out; }
        $bal = 0.0;
        foreach (Database::all(
            'SELECT kind, COALESCE(SUM(amount),0) t, COUNT(*) c
               FROM company_safe_entries GROUP BY kind') as $r) {
            $k = (string) $r['kind'];
            if (!isset($out[$k])) { continue; }   // نوع مجهول — الفاحص بيمسكه
            $out[$k] = round((float) $r['t'], 2);
            $out['count'] += (int) $r['c'];
            /* ⚠️ الاتجاه من `isIn()` مش مكتوب بالإيد — الصيغة القديمة كانت
               بتعدّ الأنواع الأربعة اسمًا اسمًا، فأي نوع جديد كان هيتحسب صفر
               والتفكيك يخالف `balance()` **بلا أي خطأ ظاهر**. */
            $bal += self::isIn($k) ? $out[$k] : -$out[$k];
        }
        $out['balance'] = round($bal, 2);
        return $out;
    }

    /** إجماليات يوم واحد (لشريط اليوم في الشاشة). */
    public static function dayTotals(string $date): array
    {
        /* ⚠️ **كل نوع له مفتاحه** — كروت النظرة العامة بتقرا منها مباشرةً، وكل
           كارت لازم يعرض **رقمه هو** لا رقمًا مجمّعًا (طلب المالك: «أبعد عن أي
           بيانات مضللة»). الجمع بيحصل في `in`/`out` بس. */
        $out = array_fill_keys(self::KINDS, 0.0);
        $out += ['in' => 0.0, 'out' => 0.0, 'count' => 0];
        if (!self::ready()) { return $out; }
        foreach (Database::all(
            'SELECT kind, COALESCE(SUM(amount),0) t, COUNT(*) c
               FROM company_safe_entries WHERE entry_date = ? GROUP BY kind', [$date]) as $r) {
            $k = (string) $r['kind'];
            $t = round((float) $r['t'], 2);
            $out['count'] += (int) $r['c'];
            // ⚠️ الاتجاه من `isIn()` — أي نوع جديد بيتحسب صح تلقائيًّا.
            $out[self::isIn($k) ? 'in' : 'out'] += $t;
            if (isset($out[$k])) { $out[$k] = $t; }
        }
        $out['in']  = round($out['in'], 2);
        $out['out'] = round($out['out'], 2);
        return $out;
    }

    /**
     * حركة آخر N يوم — لمخطط الاتجاه في الشاشة.
     *
     * ⚠️ **استعلام واحد لكل المدى** — مش استعلام لكل يوم. مقياس الأداء ده
     *    هو نفس درس `OfficeCar::daily()` (v55): رسم ١٢ فترة كان بيعمل ٢٤
     *    استعلام قبل ما يتجمّع.
     * ⚠️ الأيام الفاضية بترجع بأصفار عشان الرسم يفضل متصل زمنيًّا — يوم بلا
     *    حركة **معلومة**، وحذفه بيخلّي الرسم يكدب على المسافات.
     *
     * @return list<array{d:string,in:float,out:float,net:float}>
     */
    public static function daily(string $endDate, int $days = 14): array
    {
        $days = max(2, min(90, $days));
        $start = date('Y-m-d', strtotime($endDate . ' -' . ($days - 1) . ' day'));
        $map = [];
        if (self::ready()) {
            $in = "'" . implode("','", self::IN_KINDS) . "'";
            foreach (Database::all(
                "SELECT entry_date d,
                        COALESCE(SUM(CASE WHEN kind IN ($in) THEN amount ELSE 0 END),0) tin,
                        COALESCE(SUM(CASE WHEN kind IN ($in) THEN 0 ELSE amount END),0) tout
                   FROM company_safe_entries
                  WHERE entry_date BETWEEN ? AND ?
                  GROUP BY entry_date", [$start, $endDate]) as $r) {
                $map[(string) $r['d']] = [
                    'in'  => round((float) $r['tin'], 2),
                    'out' => round((float) $r['tout'], 2),
                ];
            }
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime($endDate . ' -' . $i . ' day'));
            $v = $map[$d] ?? ['in' => 0.0, 'out' => 0.0];
            $out[] = ['d' => $d, 'in' => $v['in'], 'out' => $v['out'],
                      'net' => round($v['in'] - $v['out'], 2)];
        }
        return $out;
    }

    /** إجماليات الشهر اللي فيه $date (تقويمي — زي `OfficeCar::period`). */
    public static function monthTotals(string $date): array
    {
        $out = ['in' => 0.0, 'out' => 0.0];
        if (!self::ready()) { return $out; }
        $from = date('Y-m-01', strtotime($date));
        $to   = date('Y-m-t', strtotime($date));
        $in   = "'" . implode("','", self::IN_KINDS) . "'";
        $r = Database::row(
            "SELECT COALESCE(SUM(CASE WHEN kind IN ($in) THEN amount ELSE 0 END),0) tin,
                    COALESCE(SUM(CASE WHEN kind IN ($in) THEN 0 ELSE amount END),0) tout
               FROM company_safe_entries WHERE entry_date BETWEEN ? AND ?", [$from, $to]);
        return ['in' => round((float) ($r['tin'] ?? 0), 2), 'out' => round((float) ($r['tout'] ?? 0), 2)];
    }

    /**
     * ⚠️ **حدّ «درج المكتب قرب يخلص» — مشتقّ من سلوك الشركة نفسها لا رقم
     *    مكتوب بالإيد.** = **متوسط قيمة التحويل للمكتب** في آخر ٩٠ يوم: لو
     *    المدير بيحوّل ٢٠ ألف في المرة والدرج نزل تحتها، يبقى وقت التحويل.
     *    رقم ثابت كان هيبقى صح لشركة وغلط لغيرها، وهيتقادم مع نمو الشغل.
     *
     * ⚠️ بترجّع 0 لو مفيش تحويلات لسه ⇒ **مفيش تنبيه** (مفيش سلوك نستنتج منه).
     */
    public static function officeLowThreshold(): float
    {
        if (!self::ready()) { return 0.0; }
        $from = date('Y-m-d', strtotime('-90 day'));
        return round((float) Database::scalar(
            "SELECT COALESCE(AVG(amount),0) FROM company_safe_entries
              WHERE kind='to_office' AND entry_date >= ?", [$from]), 2);
    }

    /** أكتر الجهات استخدامًا — لاقتراح `datalist` في الفورم (بلا تعلّم قوائم). */
    public static function recentSources(int $limit = 12): array
    {
        if (!self::ready()) { return []; }
        return array_column(Database::all(
            "SELECT source FROM company_safe_entries
              WHERE source IS NOT NULL AND source <> ''
              GROUP BY source ORDER BY COUNT(*) DESC, MAX(id) DESC
              LIMIT " . max(1, min(50, $limit))), 'source');
    }

    // ───────────────────────────────────────────── السجل

    /**
     * سجل الحركات (**الأحدث أولاً**) مع **رصيد جارٍ** صحيح لكل صف.
     *
     * ⚠️⚠️ **باج اتصاد على الإنتاج 2026-08-09 — الشاشة كانت بتعرض أقدم N لا أحدث N.**
     *    الصيغة القديمة كانت `ORDER BY entry_date, id LIMIT n` (تصاعدي) وبعدين
     *    `array_reverse` — فالـ`LIMIT` بيتطبّق على **الأقدم** والعكس بيرتّبهم بس.
     *    محليًّا كان فيه ١٥ صفًّا فالفرق ما بانش؛ على الإنتاج «آخر الحركات» كانت
     *    بتعرض أول حركات النظام. الصح: **ناخد الأحدث تنازليًّا**، ونحسب الرصيد
     *    الجاري من **رصيد ما قبل أقدم صف في الشريحة** (`$opening`) ونمشي لقدّام —
     *    من غير الافتتاحي ده الرصيد الجاري بيبدأ من صفر ويكدب على كل صف.
     *
     * @param array{kind?:string,cat?:string,from?:string,to?:string,q?:string} $f
     */
    public static function ledger(array $f = [], int $limit = 300): array
    {
        if (!self::ready()) { return []; }
        [$where, $params] = self::filterWhere($f);
        $limit = max(1, min(2000, $limit));

        // الأحدث أولاً ثم بنعكسهم للأقدم عشان نحسب الرصيد الجاري لقدّام.
        $rows = Database::all(
            "SELECT * FROM company_safe_entries WHERE $where
              ORDER BY entry_date DESC, id DESC LIMIT $limit", $params);
        if (!$rows) { return []; }
        $rows = array_reverse($rows);

        /* ⚠️ رصيد **كل اللي قبل** أقدم صف في الشريحة — بيتحسب بنفس تعبير
           `signedSql()` بتاع `balance()`، فآخر `running` في القايمة بيساوي
           `balance()` بالمليم لما تكون الفلترة فاضية. */
        $first   = $rows[0];
        $opening = round((float) Database::scalar(
            'SELECT COALESCE(SUM(' . self::signedSql() . '),0) FROM company_safe_entries
              WHERE entry_date < ? OR (entry_date = ? AND id < ?)',
            [$first['entry_date'], $first['entry_date'], (int) $first['id']]), 2);

        $run = $opening;
        foreach ($rows as &$r) {
            $k = (string) $r['kind'];
            $signed = self::isIn($k) ? (float) $r['amount'] : -(float) $r['amount'];
            $run += $signed;
            $r['signed']    = round($signed, 2);
            $r['running']   = round($run, 2);
            $r['label']     = self::label($k);
            $r['icon']      = self::icon($k);
            $r['is_in']     = self::isIn($k);
            $r['cat_label'] = !empty($r['category']) ? self::catLabel((string) $r['category']) : null;
            /* ⚠️ **الجهة هي العنوان لما تكون موجودة** (قانون التصميم: الأيقونة
               بدل الكلمة المتكررة). قايمة فيها «مصروف من خزنة الشركة» مكتوبة
               ٨ مرات بتخفي المعلومة الوحيدة اللي بتفرّق الصفوف — اسم الجهة.
               والنوع بيفضل ظاهرًا في الأيقونة وفي سطر الميتا. */
            $r['title']     = trim((string) ($r['source'] ?? '')) !== ''
                ? (string) $r['source'] : self::label($k);
            $r['subtitle']  = $r['title'] === self::label($k) ? null : self::label($k);
            /* ⚠️ **اسم الكارت/المندوب لازم يبان** (طلب المالك: «لو من فيزا يتم
               كتابة أي فيزا»). بيتحلّ في استعلامين بعد الحلقة — واحد لكل صف
               كان هيدّي N+1. */
            $r['wallet'] = null;
            // ⚠️ الحركة المولَّدة من وحدة تانية ممنوع تتعدّل/تتمسح من هنا.
            $r['editable']  = $k !== 'from_client';
        }
        unset($r);
        self::attachWallets($rows);
        return array_reverse($rows);
    }

    /**
     * بيحطّ اسم كارت الفيزا / المندوب على الصفوف اللي بتخصّهم.
     *
     * ⚠️ **استعلامان لكل القايمة لا استعلام لكل صف** (N+1). و⚠️ الاسم **بيتقرا
     *    وقت العرض لا بيتخزَّن**: لو المالك غيّر اسم الكارت، السجل القديم لازم
     *    يعرض الاسم الجديد — تخزين نسخة كان هيدّي اسمين لنفس الكارت.
     * ⚠️ الكارت/المندوب الممسوح بيرجّع `null` والشاشة بتعرض النوع بس — ما
     *    بنخترعش اسمًا.
     */
    private static function attachWallets(array &$rows): void
    {
        $cardIds = $repIds = [];
        foreach ($rows as $r) {
            if (!empty($r['visa_card_id'])) { $cardIds[(int) $r['visa_card_id']] = true; }
            if (!empty($r['rep_id']))       { $repIds[(int) $r['rep_id']] = true; }
        }
        $cards = $reps = [];
        if ($cardIds) {
            $in = implode(',', array_fill(0, count($cardIds), '?'));
            foreach (Database::all("SELECT id, name, owner FROM visa_cards WHERE id IN ($in)",
                                   array_keys($cardIds)) as $c) {
                $cards[(int) $c['id']] = trim((string) $c['name']
                    . (($c['owner'] ?? '') !== '' ? ' — ' . $c['owner'] : ''));
            }
        }
        if ($repIds) {
            $in = implode(',', array_fill(0, count($repIds), '?'));
            foreach (Database::all("SELECT id, name FROM reps WHERE id IN ($in)",
                                   array_keys($repIds)) as $x) {
                $reps[(int) $x['id']] = (string) $x['name'];
            }
        }
        foreach ($rows as &$r) {
            if (!empty($r['visa_card_id'])) { $r['wallet'] = $cards[(int) $r['visa_card_id']] ?? null; }
            elseif (!empty($r['rep_id']))   { $r['wallet'] = $reps[(int) $r['rep_id']] ?? null; }
        }
        unset($r);
    }

    // ───────────────────────────────────────────── فترات التقارير

    /**
     * ⚠️⚠️ **المصدر الوحيد لأي فترة في الشاشة** — التقارير والكشف والنوافذ
     *    بيقروا منها، فمستحيل «الشهر» يعني حاجة في تبويب وحاجة تانية في نافذته.
     *
     * @return array{key:string,label:string,from:string,to:string}
     */
    public static function periodRange(?string $key, string $day, ?string $from = null, ?string $to = null): array
    {
        $ok = static fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) === 1;
        $key = (string) $key;
        if ($key === 'custom' && $ok($from) && $ok($to)) {
            // ⚠️ الترتيب المقلوب بيتصلّح بدل ما يرجّع فترة فاضية بلا سبب ظاهر.
            if ($from > $to) { [$from, $to] = [$to, $from]; }
            return ['key' => 'custom', 'label' => dfmt($from) . ' — ' . dfmt($to),
                    'from' => (string) $from, 'to' => (string) $to];
        }
        $prev = date('Y-m-d', strtotime(date('Y-m-01', strtotime($day)) . ' -1 day'));
        return match ($key) {
            'month'      => ['key' => 'month', 'label' => 'الشهر الحالي',
                             'from' => date('Y-m-01', strtotime($day)), 'to' => date('Y-m-t', strtotime($day))],
            'prev_month' => ['key' => 'prev_month', 'label' => 'الشهر اللي فات',
                             'from' => date('Y-m-01', strtotime($prev)), 'to' => date('Y-m-t', strtotime($prev))],
            'year'       => ['key' => 'year', 'label' => 'السنة',
                             'from' => date('Y-01-01', strtotime($day)), 'to' => date('Y-12-31', strtotime($day))],
            // ⚠️ **الافتراضي اليوم** (طلب المالك) — الشهر كان بيخلّي أرقام
            //    التقارير تخالف كروت النظرة العامة اليومية بلا سبب ظاهر.
            default      => ['key' => 'day', 'label' => 'اليوم', 'from' => $day, 'to' => $day],
        };
    }

    /** خيارات شريط الفترة — نفس المفاتيح اللي `periodRange` بتفهمها. */
    public static function periodChoices(): array
    {
        return ['day' => 'اليوم', 'month' => 'الشهر', 'prev_month' => 'الشهر اللي فات',
                'year' => 'السنة', 'custom' => 'فترة محددة'];
    }

    /** إجماليات أي فترة (بديل عام لـ`monthTotals`). */
    public static function rangeTotals(string $from, string $to): array
    {
        $out = ['in' => 0.0, 'out' => 0.0];
        if (!self::ready()) { return $out; }
        $in = "'" . implode("','", self::IN_KINDS) . "'";
        $r  = Database::row(
            "SELECT COALESCE(SUM(CASE WHEN kind IN ($in) THEN amount ELSE 0 END),0) tin,
                    COALESCE(SUM(CASE WHEN kind IN ($in) THEN 0 ELSE amount END),0) tout
               FROM company_safe_entries WHERE entry_date BETWEEN ? AND ?", [$from, $to]);
        return ['in' => round((float) ($r['tin'] ?? 0), 2), 'out' => round((float) ($r['tout'] ?? 0), 2)];
    }

    /** تفكيك الرصيد بالنوع داخل فترة — للتقارير (`breakdown` كلها بلا فترة). */
    public static function breakdownRange(string $from, string $to): array
    {
        $out = array_fill_keys(self::KINDS, 0.0);
        if (!self::ready()) { return $out; }
        foreach (Database::all(
            'SELECT kind, COALESCE(SUM(amount),0) t FROM company_safe_entries
              WHERE entry_date BETWEEN ? AND ? GROUP BY kind', [$from, $to]) as $r) {
            $k = (string) $r['kind'];
            if (isset($out[$k])) { $out[$k] = round((float) $r['t'], 2); }
        }
        return $out;
    }

    /** عدد الصفوف المطابقة للفلترة (عشان «بنعرض N من M» — ممنوع قطع صامت). */
    public static function countLedger(array $f = []): int
    {
        if (!self::ready()) { return 0; }
        [$where, $params] = self::filterWhere($f);
        return (int) Database::scalar("SELECT COUNT(*) FROM company_safe_entries WHERE $where", $params);
    }

    /**
     * ⚠️ **المصدر الوحيد لشرط الفلترة** — لازم `ledger` و`countLedger` يقروا منه
     *    مع بعض، وإلا الشاشة تقول «٥٠ من ١٢» وهي بتعرض غير اللي بتعدّه.
     */
    private static function filterWhere(array $f): array
    {
        $w = ['1']; $p = [];
        $kind = (string) ($f['kind'] ?? '');
        if ($kind !== '' && in_array($kind, self::KINDS, true)) { $w[] = 'kind = ?'; $p[] = $kind; }
        // فلترة بفئة المصروف — القيمة بتتفحص على القايمة المقفولة (الفورم مش مصدر ثقة).
        $cat = (string) ($f['cat'] ?? '');
        if ($cat !== '' && isset(self::EXPENSE_CATS[$cat])) {
            $w[] = "kind = 'expense' AND category = ?"; $p[] = $cat;
        }
        /* اتجاه الحركة (داخل/خارج) — لكارت «إيداع اليوم» اللي بيجمّع أكتر من
           نوع. ⚠️ الشرط من `IN_KINDS` لا مكتوب بالإيد، فأي نوع جديد بيدخل
           الفلترة تلقائيًّا. */
        $dir = (string) ($f['dir'] ?? '');
        if ($dir === 'in' || $dir === 'out') {
            $list = "'" . implode("','", self::IN_KINDS) . "'";
            $w[] = 'kind ' . ($dir === 'in' ? 'IN' : 'NOT IN') . " ($list)";
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $d = (string) ($f[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $w[] = "entry_date $op ?"; $p[] = $d; }
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            // ⚠️ `likeWhere` بترجّع شرطًا **فاضيًا** لو النص كله رموز بلا كلمات —
            //    حقنه كـ`()` بيكسر الاستعلام، فلازم الفحص ده.
            [$qw, $qp] = Search::likeWhere(['source', 'note'], $q);
            if ($qw !== '') { $w[] = "($qw)"; array_push($p, ...$qp); }
        }
        return [implode(' AND ', $w), $p];
    }

    public static function find(int $id): ?array
    {
        if (!self::ready()) { return null; }
        return Database::row('SELECT * FROM company_safe_entries WHERE id = ?', [$id]);
    }

    // ───────────────────────────────────────────── الكتابة

    /**
     * تسجيل حركة. بيولّد صف `manual_custody` تلقائيًّا للتحويلات.
     *
     * ⚠️ **الكنترولر لازم يكون نادى `Closing::guard` قبل كده لو النوع تحويل** —
     *    نفس عقد `ClientPayment::create` بالحرف. الحارس هنا كمان (حزام أمان)
     *    عشان أي مسار جديد ما يعديش من غير فحص.
     *
     * @param array{entry_date:string,kind:string,amount:float,source?:string,note?:string} $d
     * @return int  id الصف الجديد
     */
    public static function create(array $d): int
    {
        if (!self::ready()) {
            throw new \RuntimeException('خزنة الشركة مش مفعّلة. شغّل php bin/migrate_v66.php أولاً.');
        }
        $v = self::normalise($d);
        $kind = $v['kind'];

        if (in_array($kind, self::OFFICE_KINDS, true) && ($err = Closing::guard($v['date'])) !== null) {
            throw new \RuntimeException($err);
        }
        /* ⚠️ رصيد الشركة بيتفحص هنا كمان (مش في الكنترولر بس): أي مسار جديد
           بيسحب من الخزنة لازم يعدّي على نفس الحارس. */
        if (!self::isIn($kind) && $v['amount'] > self::balance() + 0.005) {
            throw new \RuntimeException(
                'المبلغ أكبر من رصيد خزنة الشركة (' . money(self::balance(), false) . ').');
        }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $link = self::writeLinked($kind, $v);
            Database::exec(
                'INSERT INTO company_safe_entries
                    (entry_date, kind, amount, source, note, category, attachment,
                     custody_id, visa_card_id, visa_deposit_id, rep_id, rep_deposit_id, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$v['date'], $kind, $v['amount'], $v['source'], $v['note'],
                 $v['category'], $v['attachment'],
                 $link['custody_id'], $v['visa_card_id'], $link['visa_deposit_id'],
                 $v['rep_id'], $link['rep_deposit_id'], Auth::user()]
            );
            $id = Database::lastId();
            Audit::log('create', 'company_safe_entries', $id, null, self::find($id),
                'حركة خزنة الشركة: ' . self::label($kind), ['record_date' => $v['date']]);
            if ($own) { $pdo->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * ⚠️⚠️ **المصدر الوحيد لتنظيف مدخلات الحركة** — بيقراه `create` و`update`
     *    مع بعض. الفورم مش مصدر ثقة: النوع والفئة والكارت والمندوب كلهم
     *    بيتفحصوا هنا، فأي مسار جديد (أو فورم مزوّر) بيعدّي على نفس القواعد.
     *
     * @return array{kind:string,amount:float,date:string,source:?string,note:?string,
     *               category:?string,attachment:?string,visa_card_id:?int,rep_id:?int}
     */
    private static function normalise(array $d): array
    {
        $kind = (string) ($d['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new \RuntimeException('نوع حركة غير معروف.');
        }
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0) { throw new \RuntimeException('المبلغ لازم يكون أكبر من صفر.'); }

        $date = (string) ($d['entry_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = work_date(); }

        $source = trim((string) ($d['source'] ?? ''));
        $note   = trim((string) ($d['note'] ?? ''));

        /* ⚠️ الفئة **للمصروف بس**. فئة متسجّلة على تحويل بيان مضلِّل بيخلّي
           تقرير «راح فين» يعدّ حركات مش مصروفات — والفاحص بيمنعها. */
        $category = $kind === 'expense' ? self::cleanCat($d['category'] ?? null) : null;

        // ⚠️ كارت الفيزا إلزامي للنوعين وبيتفحص إنه **نشط** فعلاً (نفس قاعدة v45).
        $visaCardId = null;
        if (in_array($kind, self::VISA_KINDS, true)) {
            $visaCardId = (int) ($d['visa_card_id'] ?? 0);
            if ($visaCardId <= 0 || !VisaCard::isActive($visaCardId)) {
                throw new \RuntimeException('اختار كارت الفيزا الأول.');
            }
        }

        // ⚠️ المندوب لازم يكون **نشط** — نفس شرط شاشة تنزيل العهدة بالظبط.
        $repId = null;
        if ($kind === 'to_rep') {
            $repId = (int) ($d['rep_id'] ?? 0);
            if ($repId <= 0 || !isset(RepCustody::activeReps()[$repId])) {
                throw new \RuntimeException('اختار المندوب الأول.');
            }
        }

        $attachment = ($d['attachment'] ?? '') !== '' ? (string) $d['attachment'] : null;

        return ['kind' => $kind, 'amount' => $amount, 'date' => $date,
                'source' => $source !== '' ? $source : null,
                'note' => $note !== '' ? $note : null,
                'category' => $category, 'attachment' => $attachment,
                'visa_card_id' => $visaCardId, 'rep_id' => $repId];
    }

    /**
     * ⚠️⚠️ **الاتجاه التاني**: بيكتب الصف المقابل في الوحدة اللي الفلوس راحت
     *    لها (أو جت منها). **لازم يتنادى جوّه معاملة `create`** — خصم من هنا بلا
     *    إضافة هناك = فلوس بتتبخّر (تحذير v45 عن الفيزا بالحرف).
     *
     * @return array{custody_id:?int,visa_deposit_id:?int,rep_deposit_id:?int}
     */
    private static function writeLinked(string $kind, array $v): array
    {
        $out = ['custody_id' => null, 'visa_deposit_id' => null, 'rep_deposit_id' => null];

        if (in_array($kind, self::OFFICE_KINDS, true)) {
            /* ⚠️ **الإشارة هي كل الحكاية**: التحويل للمكتب كاش داخل الدرج
               (+)، والسحب منه كاش خارج (−). عكسها بيخلّي الفلوس تتكرّر بدل
               ما تتنقل. و`manual_custody` بيقبل السالب فعلاً (اتستخدم في
               تصحيح Q4 يوم 2026-07-31 و`DailyReview` بيعرضه «كاش خرج من
               الدرج»). */
            $out['custody_id'] = Custody::createTopup([
                'cust_date' => $v['date'],
                'driver'    => $v['source'] ?? 'خزنة الشركة',
                'amount'    => $kind === 'to_office' ? $v['amount'] : -$v['amount'],
                'note'      => self::custodyNote($kind, (string) $v['note']),
            ]);
        } elseif (in_array($kind, self::VISA_KINDS, true)) {
            /* ⚠️ `from_visa` بيتسجّل **شحنة بالسالب** — رصيد الكارت =
               الافتتاحي + Σالشحنات − Σالمدفوعات، فالسالب بينقّصه بالظبط.
               و`VisaCard::createDeposit` بتقبل أي قيمة بلا `abs()`. */
            $out['visa_deposit_id'] = VisaCard::createDeposit((int) $v['visa_card_id'], [
                'dep_date' => $v['date'],
                'amount'   => $kind === 'to_visa' ? $v['amount'] : -$v['amount'],
                'note'     => self::visaNote($kind, (string) $v['note']),
            ]);
        } elseif ($kind === 'to_rep') {
            /* ⚠️ المصدر `company` **مش** `bank`: حسابيًّا زي بعض (شوف
               `RepCustody::depositAffectsSafe`) بس الشارة بتقول للمالك الفلوس
               جت منين فعلاً — ودي كل فايدة إن ليه مصدرًا منفصل.
               ⚠️ و`RepCustodyController::deleteDeposit` بيرفض حذف التنزيل ده من
                  كارت المندوب عشان ما يسيبش الصف ده يتيم. */
            $out['rep_deposit_id'] = RepCustody::createDeposit(
                (int) $v['rep_id'], $v['amount'], $v['date'],
                self::repNote((string) $v['note']), 'company');
        }
        return $out;
    }

    private static function visaNote(string $kind, string $note): string
    {
        $base = $kind === 'to_visa' ? 'شحن من خزنة الشركة' : 'سحب كاش لخزنة الشركة';
        return $note !== '' ? $base . ' — ' . $note : $base;
    }

    private static function repNote(string $note): string
    {
        $base = 'عهدة من خزنة الشركة';
        return $note !== '' ? $base . ' — ' . $note : $base;
    }

    /** نصّ ملاحظة صف العهدة المُولَّد — عشان المالك يعرف من شاشة التقفيل الفلوس جت منين. */
    private static function custodyNote(string $kind, string $note): string
    {
        $base = $kind === 'to_office' ? 'تحويل من خزنة الشركة' : 'تحويل لخزنة الشركة';
        return $note !== '' ? $base . ' — ' . $note : $base;
    }

    /**
     * حذف حركة + صف العهدة المرتبط بيها (لو موجود) في معاملة واحدة.
     *
     * ⚠️ **الاتنين مع بعض أو ولا واحد**: لو اتشال صف الشركة وفضل صف العهدة،
     *    خزنة المكتب بتفضل فيها فلوس مالهاش مصدر. والعكس بيخلّي الشركة تقول
     *    إنها حوّلت والمكتب مايعرفش.
     */
    public static function delete(int $id): void
    {
        $row = self::find($id);
        if (!$row) { throw new \RuntimeException('الحركة مش موجودة.'); }
        self::assertEditable($row);
        if (!empty($row['custody_id']) && ($err = Closing::guard((string) $row['entry_date'])) !== null) {
            throw new \RuntimeException($err);
        }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            self::removeLinked($row);
            Database::exec('DELETE FROM company_safe_entries WHERE id = ?', [$id]);
            /* ⚠️ الملف بيتشال **بعد** نجاح الحذف — قبله كان هيضيع لو المعاملة
               رجعت (وحذف الملف مالوش rollback). */
            PrivateFile::delete($row['attachment'] ?? null);
            Audit::log('delete', 'company_safe_entries', $id, $row, null,
                'حذف حركة خزنة الشركة', ['record_date' => (string) $row['entry_date']]);
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * ⚠️⚠️ **عكس `writeLinked` بالحرف** — بيشيل كل صف مُولَّد في وحدة تانية.
     *    لو اتشال صف الشركة وفضل الصف المقابل، الفلوس بتتكرّر (المحفظة التانية
     *    فيها المبلغ وخزنة الشركة رجّعته). ولازم يمشي مع `writeLinked` أي نوع
     *    جديد يتضاف — الفاحص بيقارن القايمتين.
     */
    private static function removeLinked(array $row): void
    {
        if (!empty($row['custody_id']))      { Custody::deleteTopup((int) $row['custody_id']); }
        if (!empty($row['visa_deposit_id'])) { VisaCard::deleteDeposit((int) $row['visa_deposit_id']); }
        if (!empty($row['rep_deposit_id']))  { RepCustody::deleteDeposit((int) $row['rep_deposit_id']); }
    }

    /**
     * ⚠️ الحركات اللي **مصدر حقيقتها برّه الوحدة** ممنوع تتعدّل أو تتمسح من هنا.
     *    `from_client` بيتولّد من «سداد العملاء»؛ تعديله هنا بيخلّي الدفعة تقول
     *    رقمًا والخزنة تقول رقمًا تاني، وحذفه بيسيب الدفعة مشاورة على صف مش موجود.
     */
    private static function assertEditable(array $row): void
    {
        if ((string) $row['kind'] === 'from_client') {
            throw new \RuntimeException(
                'دي حركة اتولّدت من تحصيل عميل. عدّلها أو امسحها من صفحة «سداد العملاء» '
              . 'بعكس الدفعة نفسها — التعديل من هنا كان هيخلّي حساب العميل يخالف الخزنة.');
        }
    }

    /**
     * تعديل حركة قايمة.
     *
     * ⚠️⚠️ **النوع ممنوع يتغيّر** — كل نوع له صف مقابل في وحدة تانية (أو مالوش)،
     *    وتغييره معناه نشيل من محفظة ونحطّ في تانية. ده **حذف + إضافة** مش
     *    تعديل، والمالك لازم يشوفه كده في السجل. الرسالة بتقول ده صراحةً.
     *
     * ⚠️ التنفيذ **بيعكس القديم ويكتب الجديد** (`removeLinked` ثم `writeLinked`)
     *    بدل ما يحدّث الصف المقابل في مكانه: التحديث في المكان كان هيحتاج نسخة
     *    تانية من منطق الإشارة والملاحظة لكل وحدة — ونسخة تانية معناها تفرّع.
     */
    public static function update(int $id, array $d): void
    {
        $row = self::find($id);
        if (!$row) { throw new \RuntimeException('الحركة مش موجودة.'); }
        self::assertEditable($row);

        $old  = (string) $row['kind'];
        $kind = (string) ($d['kind'] ?? $old);
        if ($kind !== $old) {
            throw new \RuntimeException(
                'ما ينفعش تغيّر نوع الحركة من «' . self::label($old) . '» لـ«' . self::label($kind)
              . '». امسحها وسجّلها من جديد عشان تبان في السجل صح.');
        }
        $v = self::normalise($d + ['kind' => $old]);

        // اليوم القديم **والجديد** الاتنين لازم يكونوا مفتوحين لو الحركة بتمسّ الدرج.
        if (in_array($old, self::OFFICE_KINDS, true)) {
            foreach ([(string) $row['entry_date'], $v['date']] as $chk) {
                if (($err = Closing::guard($chk)) !== null) { throw new \RuntimeException($err); }
            }
        }
        /* ⚠️ فحص الرصيد على **الفرق** مش على المبلغ كله (نفس درس سقف عهدة
           البوليصة 2026-07-27): تعديل التاريخ أو الملاحظة على حركة سحب كان
           هيترفض بلا سبب حقيقي لو الرصيد الحالي أقل من مبلغها. */
        if (!self::isIn($old)) {
            $extra = $v['amount'] - round((float) $row['amount'], 2);
            if ($extra > self::balance() + 0.005) {
                throw new \RuntimeException('الزيادة أكبر من رصيد خزنة الشركة ('
                    . money(self::balance(), false) . ').');
            }
        }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            self::removeLinked($row);
            $link = self::writeLinked($old, $v);
            /* المرفق: الجديد بيغلب، ولو مفيش جديد القديم بيفضل. `attachment`
               بترجع NULL من `normalise` لو ما اترفعش ملف — فالـ`??` هنا مقصودة. */
            $att = $v['attachment'] ?? ($row['attachment'] ?: null);
            Database::exec(
                'UPDATE company_safe_entries
                    SET entry_date=?, amount=?, source=?, note=?, category=?, attachment=?,
                        custody_id=?, visa_card_id=?, visa_deposit_id=?,
                        rep_id=?, rep_deposit_id=?, updated_by=?, updated_at=NOW()
                  WHERE id=?',
                [$v['date'], $v['amount'], $v['source'], $v['note'], $v['category'], $att,
                 $link['custody_id'], $v['visa_card_id'], $link['visa_deposit_id'],
                 $v['rep_id'], $link['rep_deposit_id'], Auth::user(), $id]
            );
            if ($v['attachment'] !== null && !empty($row['attachment'])
                && $v['attachment'] !== $row['attachment']) {
                PrivateFile::delete((string) $row['attachment']);
            }
            Audit::log('update', 'company_safe_entries', $id, $row, self::find($id),
                'تعديل حركة خزنة الشركة: ' . self::label($old),
                ['record_date' => $v['date']]);
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    // ───────────────────────────────────────────── المصروفات: راح فين ولمين

    /** شرط نطاق تاريخ اختياري — المصدر الوحيد لكل تقارير الفترة تحت. */
    private static function rangeWhere(?string $from, ?string $to): array
    {
        $w = []; $p = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from)) { $w[] = 'entry_date >= ?'; $p[] = $from; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to))   { $w[] = 'entry_date <= ?'; $p[] = $to; }
        return [$w ? implode(' AND ', $w) : '1', $p];
    }

    /**
     * المصروفات مقسومة بالفئة — لنافذة «راح فين».
     * @return list<array{key:string,label:string,total:float,count:int,pct:float}>
     */
    public static function byCategory(?string $from = null, ?string $to = null): array
    {
        if (!self::ready()) { return []; }
        [$w, $p] = self::rangeWhere($from, $to);
        $rows = Database::all(
            "SELECT category, COALESCE(SUM(amount),0) t, COUNT(*) c
               FROM company_safe_entries WHERE kind='expense' AND $w
              GROUP BY category ORDER BY t DESC", $p);
        $grand = array_sum(array_map(static fn ($r) => (float) $r['t'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $t = round((float) $r['t'], 2);
            $out[] = [
                'key'   => (string) ($r['category'] ?? 'other'),
                'label' => self::catLabel($r['category'] ?? null),
                'total' => $t,
                'count' => (int) $r['c'],
                // ⚠️ المقام صفر ⇒ 0.0 لا قسمة على صفر (والشاشة بتعرض «—»).
                'pct'   => $grand > 0 ? round($t / $grand * 100, 1) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * «صرفنا كام لمين» — المصروفات مجمّعة بجهة الصرف (`source`).
     *
     * ⚠️ التجميع على النص كما هو **بلا أي دمج تلقائي لتهجئتين** (تحذير ٣):
     *    الدمج قرار المالك من «البيانات الأساسية»، والدمج الصامت هنا كان
     *    هيخفي غلط الإدخال بدل ما يوريه.
     */
    public static function byParty(?string $from = null, ?string $to = null, int $limit = 20): array
    {
        if (!self::ready()) { return []; }
        [$w, $p] = self::rangeWhere($from, $to);
        return array_map(static fn ($r) => [
            'party' => (string) $r['party'],
            'total' => round((float) $r['t'], 2),
            'count' => (int) $r['c'],
        ], Database::all(
            "SELECT COALESCE(NULLIF(TRIM(source),''),'(بدون جهة)') party,
                    COALESCE(SUM(amount),0) t, COUNT(*) c
               FROM company_safe_entries WHERE kind='expense' AND $w
              GROUP BY party ORDER BY t DESC LIMIT " . max(1, min(200, $limit)), $p));
    }

    // ───────────────────────────────────────────── الميزانية الشهرية

    public const BUDGET_KEY = 'company_safe_monthly_budget';

    /** سقف المصروف الشهري. **صفر = مفيش سقف = مفيش تنبيه** (زي `officeLowThreshold`). */
    public static function budget(): float
    {
        return max(0.0, round((float) App::setting(self::BUDGET_KEY, 0), 2));
    }

    /** ⚠️ القيمة الفاضية أو ≤0 بتتخزَّن `''` = «مفيش سقف»، مش صفر جنيه. */
    public static function setBudget(?string $raw): void
    {
        $v = round((float) str_replace(',', '', trim((string) $raw)), 2);
        App::setSetting(self::BUDGET_KEY, $v > 0 ? (string) $v : '');
    }

    /**
     * مصروفات الشهر مقابل السقف.
     * @return array{spent:float,budget:float,pct:float,left:float,over:bool,on:bool}
     */
    public static function budgetProgress(string $date): array
    {
        $budget = self::budget();
        $spent  = 0.0;
        if (self::ready()) {
            $spent = round((float) Database::scalar(
                "SELECT COALESCE(SUM(amount),0) FROM company_safe_entries
                  WHERE kind='expense' AND entry_date BETWEEN ? AND ?",
                [date('Y-m-01', strtotime($date)), date('Y-m-t', strtotime($date))]), 2);
        }
        return [
            'spent'  => $spent,
            'budget' => $budget,
            'pct'    => $budget > 0 ? round($spent / $budget * 100, 1) : 0.0,
            'left'   => round($budget - $spent, 2),
            'over'   => $budget > 0 && $spent > $budget,
            'on'     => $budget > 0,
        ];
    }

    // ───────────────────────────────────────────── كشف حساب الفترة (طباعة A4)

    /**
     * كشف حساب: **رصيد افتتاحي + حركات الفترة + رصيد ختامي**.
     *
     * ⚠️ الافتتاحي بيتحسب من **كل** الحركات قبل `$from` بنفس تعبير الاتجاه
     *    `signedSql()` بتاع `balance()` — فمستحيل يطلع رقم مخالف للرصيد
     *    المعروض في الشاشة. (`الافتتاحي + صافي الفترة = الختامي` محروسة بفحص.)
     *
     * @return array{from:string,to:string,opening:float,closing:float,in:float,out:float,
     *               rows:list<array>,by_kind:array<string,array{in:float,out:float,count:int}>}
     */
    public static function statement(string $from, string $to): array
    {
        $empty = ['from' => $from, 'to' => $to, 'opening' => 0.0, 'closing' => 0.0,
                  'in' => 0.0, 'out' => 0.0, 'rows' => [], 'by_kind' => []];
        if (!self::ready()) { return $empty; }

        $opening = round((float) Database::scalar(
            'SELECT COALESCE(SUM(' . self::signedSql() . '),0)
               FROM company_safe_entries WHERE entry_date < ?', [$from]), 2);

        $rows = Database::all(
            'SELECT * FROM company_safe_entries
              WHERE entry_date BETWEEN ? AND ? ORDER BY entry_date, id', [$from, $to]);

        $run = $opening; $in = 0.0; $out = 0.0; $byKind = [];
        foreach ($rows as &$r) {
            $k = (string) $r['kind'];
            $amt = round((float) $r['amount'], 2);
            $isIn = self::isIn($k);
            $run += $isIn ? $amt : -$amt;
            if ($isIn) { $in += $amt; } else { $out += $amt; }
            $r['signed']  = $isIn ? $amt : -$amt;
            $r['running'] = round($run, 2);
            $r['label']   = self::label($k);
            $r['cat_label'] = $r['category'] ? self::catLabel($r['category']) : null;
            $byKind[$k] ??= ['in' => 0.0, 'out' => 0.0, 'count' => 0];
            $byKind[$k][$isIn ? 'in' : 'out'] += $amt;
            $byKind[$k]['count']++;
        }
        unset($r);

        return ['from' => $from, 'to' => $to, 'opening' => $opening,
                'closing' => round($run, 2), 'in' => round($in, 2), 'out' => round($out, 2),
                'rows' => $rows, 'by_kind' => $byKind];
    }

    // ───────────────────────────────────────────── ربط تحصيل العملاء (route='company')

    /**
     * تسجيل تحصيل عميل في خزنة الشركة — تناديها `ClientPayment::create`
     * لما التوجيه يكون `company`. **بلا `Closing::guard`** عن قصد: الفلوس دخلت
     * خزنة الشركة مش الدرج، فمفيش رصيد مثبَّت بيتغيّر.
     */
    public static function createFromClient(string $clientName, ?string $date, float $amount, string $note): int
    {
        return self::create([
            'entry_date' => $date ?: work_date(),
            'kind'       => 'from_client',
            'amount'     => $amount,
            'source'     => $clientName,
            'note'       => $note,
        ]);
    }

    /** حذف صف تحصيل عند عكس الدفعة — بيتنادى من `ClientPayment::reverse` جوّه معاملتها. */
    public static function deleteRaw(int $id): void
    {
        if (!self::ready()) { return; }
        $row = self::find($id);
        if (!$row) { return; }
        Database::exec('DELETE FROM company_safe_entries WHERE id = ?', [$id]);
        Audit::log('delete', 'company_safe_entries', $id, $row, null,
            'حذف حركة خزنة الشركة (عكس تحصيل)', ['record_date' => (string) $row['entry_date']]);
    }
}
