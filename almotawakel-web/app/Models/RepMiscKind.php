<?php
namespace App\Models;

/**
 * وسم النثرية (v71) — **نوعها** مش بيانها.
 *
 * ═══ اللزمة (طلب المالك 2026-08-09) ═══
 * النثرية كانت سطرًا واحدًا بلا نوع: «مواصلات» و«سلفة» و«شاي» كلهم بيقعوا في
 * كومة واحدة اسمها «نثريات»، وساعة ما نيجي نحاسب المندوب مفيش طريقة نعرف بيها
 * السلفة اللي أخدها من العهدة (تتخصم من راتبه) من المواصلات الداخلية (تتخصم من
 * مصروف المناديب الأسبوعي) من المواصلات الخارجية. الوسم بيتسجّل **وقت الإدخال**
 * من المندوب نفسه، لأنه هو الوحيد اللي يعرف الفلوس راحت فين.
 *
 * ⚠️⚠️ **الوسم وسم — مش تغيير في المعاملة المالية.**
 *   النثرية بكل أنواعها لسه بتتصرّف زي ما كانت بالحرف: تتخصم من عهدة المندوب،
 *   وتتخصم من الخزنة يوم صرفها (لو كاش)، وتظهر في `/expenses`. **ولا مليم
 *   اتحرّك بسبب الترحيل ده** (`migrate_v71` بيثبت ده بلقطة قبل/بعد).
 *   السلفة تحديدًا **لسه مصروف شركة** في النظام — تحويلها لـ«مستحق على
 *   المندوب» يتخصم من راتبه هو **قرار مالي جديد** بتاع وحدة الرواتب الجاية،
 *   والوسم ده هو اللي هيخلّيها ممكنة أصلاً.
 *
 * ═══ المصدر الوحيد ═══
 * كل سؤال عن نوع النثرية بيتجاوب من هنا: الاسم · الأيقونة · التصنيف اللي
 * بيتخزّن في `rep_misc_expenses.category` · الاقتراحات · هل ينفع بالفيزا.
 * **ممنوع** أي ملف يكتب `'advance'` أو «سلفة مندوب» بإيده — `verify_v71`
 * بيعدّ نقاط الاستدعاء وبيفشل لو اتكتب حرفيًّا برّه الملف ده.
 *
 * ⚠️ المفاتيح بتتخزّن في القاعدة (`rep_submissions.misc_kind` ·
 *    `rep_misc_expenses.misc_kind`) — **تغيير مفتاح = بيانات يتيمة**. زوّد
 *    نوعًا جديدًا في آخر القايمة ولا تعيد تسمية القديم.
 */
final class RepMiscKind
{
    /** النوع الافتراضي — كل نثرية قديمة (قبل v71) وأي قيمة مجهولة بتقع عليه. */
    public const DEFAULT = 'general';

    /**
     * الكتالوج. الترتيب هنا **هو ترتيب العرض** في سلايدر شاشة المندوب.
     *   label     الاسم المعروض (سطر واحد قصير)
     *   sub       التوضيح اللي جوّه الكارت (٣ كلمات — مش شرح)
     *   category  اللي بيتخزّن في `rep_misc_expenses.category` فيتعرض في
     *             `/expenses` وكشف حساب المندوب بلا أي تعديل في الشاشتين
     *   icon      اسم من `icon()` — ⚠️ الاسم الغلط بيرسم دايرة فاضية بلا خطأ
     *   notes     اقتراحات البيان لما النوع ده يتختار
     *   cash_only النوع ده ما ينفعش يتدفع بكارت فيزا المكتب
     */
    private const CATALOG = [
        'advance' => [
            'label'     => 'مسحوب شخصي',
            'sub'       => 'سلفة',
            'category'  => 'سلفة مندوب',
            'icon'      => 'wallet',
            'notes'     => ['سلفة', 'مسحوب شخصي'],
            // السلفة فلوس المندوب أخدها كاش من عهدته لنفسه — كارت المكتب
            // ما بينزّلش كاش، فالوسم ده متنافي مع الفيزا بنيويًّا مش ذوقًا.
            'cash_only' => true,
        ],
        'transport_in' => [
            'label'     => 'مواصلات داخلية',
            'sub'       => 'جوّه الميناء',
            'category'  => 'مواصلات داخلية',
            'icon'      => 'crane',
            'notes'     => ['مواصلات داخل الميناء', 'توكتوك', 'عربية جوّه الميناء'],
            'cash_only' => false,
        ],
        'transport_out' => [
            'label'     => 'مواصلات خارجية',
            'sub'       => 'برّه الميناء',
            'category'  => 'مواصلات خارجية',
            'icon'      => 'map-pin',
            'notes'     => ['مواصلات خارج الميناء', 'تاكسي', 'أتوبيس'],
            'cash_only' => false,
        ],
        'general' => [
            'label'     => 'نثرية عامة',
            'sub'       => 'حاجة تانية',
            'category'  => 'نثريات',   // = RepMiscExpense::CATEGORY (متحقَّق في verify_v71)
            'icon'      => 'coins',
            'notes'     => ['شاي وقهوة', 'تصوير مستندات', 'إكرامية'],
            'cash_only' => false,
        ],
    ];

    /** @return array<string,array> الكتالوج كامل بترتيب العرض. */
    public static function all(): array
    {
        return self::CATALOG;
    }

    /** @return string[] المفاتيح المعتمدة — أساس أي `IN (...)` أو تحقّق. */
    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function has(?string $kind): bool
    {
        return $kind !== null && $kind !== '' && isset(self::CATALOG[$kind]);
    }

    /**
     * ⚠️ **المصدر الوحيد** لتحويل أي قيمة جاية من فورم أو من القاعدة لمفتاح صالح.
     * الفاضي والمجهول والقديم (NULL قبل v71) كلهم بيقعوا على `general` — نفس
     * سلوك النظام قبل الوسم بالظبط، فمفيش صف بيختفي من أي تقرير.
     */
    public static function normalize(?string $kind): string
    {
        $k = trim((string) $kind);
        return isset(self::CATALOG[$k]) ? $k : self::DEFAULT;
    }

    public static function label(?string $kind): string
    {
        return self::CATALOG[self::normalize($kind)]['label'];
    }

    public static function sub(?string $kind): string
    {
        return self::CATALOG[self::normalize($kind)]['sub'];
    }

    public static function icon(?string $kind): string
    {
        return self::CATALOG[self::normalize($kind)]['icon'];
    }

    /** التصنيف اللي بيتخزّن في `rep_misc_expenses.category` وقت الاعتماد. */
    public static function category(?string $kind): string
    {
        return self::CATALOG[self::normalize($kind)]['category'];
    }

    /** @return string[] اقتراحات البيان للنوع ده. */
    public static function notes(?string $kind): array
    {
        return self::CATALOG[self::normalize($kind)]['notes'];
    }

    /** النوع ده ممنوع يتدفع بكارت فيزا المكتب؟ (المصدر الوحيد للشرط). */
    public static function isCashOnly(?string $kind): bool
    {
        return (bool) self::CATALOG[self::normalize($kind)]['cash_only'];
    }

    /**
     * ⚠️ **المصدر الوحيد** لقراءة العمود في SQL: بيلفّ العمود بحيث NULL أو
     * الفاضي (صفوف ما قبل v71) يترجّعوا `general` بدل ما يقعوا برّه أي GROUP BY
     * فيختفي مجموعهم من الكشف بالسكوت.
     *
     * الاستخدام: `RepMiscKind::sqlKey('x')` → `COALESCE(NULLIF(x.misc_kind,''),'general')`
     */
    public static function sqlKey(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        return "COALESCE(NULLIF({$p}misc_kind,''),'" . self::DEFAULT . "')";
    }

    /**
     * المفتاح كنصّ SQL جاهز للمقارنة. **الحقن مستحيل بنيويًّا** لأن
     * `normalize()` عمرها ما بترجّع غير مفتاح من الكتالوج نفسه (حروف صغيرة
     * وشرطة سفلية) — أي قيمة جاية من فورم بتقع على `general` قبل ما توصل هنا.
     */
    public static function sqlLiteral(?string $kind): string
    {
        return "'" . self::normalize($kind) . "'";
    }
}
