<?php
namespace App\Models;

use App\Core\Database;

/**
 * القوائم المنسدلة (التصنيفات/الشركات/الجهات/السائقين/أصحاب السيارات/العملاء).
 */
class Lookup
{
    public const TYPES = [
        'classification' => 'التصنيفات',
        'company'        => 'الشركات',
        'destination'    => 'الجهات',
        'driver'         => 'السائقون',
        'car_owner'      => 'أصحاب السيارات',
        'client'         => 'العملاء',
        'container_type' => 'تصنيف الحاويات',
        'shipping_co'    => 'شركات الشحن',
        'agency'         => 'التوكيلات',
        'port'           => 'الموانئ',
        'mv_expense'     => 'فئات المصروفات (الحركة)',
        'mv_receipt'     => 'فئات الإيصالات (الحركة)',
        'mv_discount'    => 'فئات الخصومات (الحركة)',
        'expense_cat'    => 'فئات المصروفات اليدوية',
    ];

    /**
     * أنواع «أسماء البنود» (إيصالات/مصروفات الحركة). اسم البند دلوقتي مايصحّش
     * يكون فيه رقم — الكمية ليها خانة مستقلة (`qty`، v17). فبنرفض أي قيمة فيها
     * رقم عشان الاقتراحات اللي بتظهر للمندوب ما تتلوّثش بأخطاء زي «٢ جيش هيئة».
     * ⚠️ القاعدة دي للأنواع دي بس — `container_type` مثلاً فيه أرقام مشروعة
     *    («40 قدم»)، فممنوع نعمّم المنع على كل الأنواع.
     */
    private const NOTE_TYPES = ['mv_receipt', 'mv_expense', 'mv_misc'];

    /**
     * **القوائم المقفولة (قرار المالك):** مفرداتها محدودة ومعروفة — الموظف بيختار
     * منها وبس، والكتابة الحرة ممنوعة. إضافة مفردة جديدة قرار متعمَّد من `/settings`.
     *
     * ⚠️ ليه دي بالذات: مصر فيها ٧ موانئ ومقاسات الحاويات ٨ — مفيش سبب تسيب
     *    الخانة مفتوحة. الحقول المفتوحة (سائق/صاحب سيارة/صاحب بيان/جهة) **لازم
     *    تفضل مفتوحة** لأن مفرداتها بتكبر كل يوم، وحارس الأسماء هو اللي بيحميها.
     *
     * ⚠️ **قيمة قديمة مش في القائمة بتفضل مقبولة على السجل بتاعها** (`$existing`
     *    في `Controller::nameGuardError`) — من غير كده تعديل تاريخ إذن قديم كان
     *    هيغيّر ميناءه بالسكوت، وده أسوأ من الإدخال الرديء نفسه.
     */
    public const STRICT = ['port', 'container_type', 'agency', 'shipping_co'];

    public static function isStrict(string $type): bool
    {
        return in_array($type, self::STRICT, true);
    }

    /** كل القوائم مجمّعة حسب النوع */
    public static function allGrouped(): array
    {
        $out = array_fill_keys(array_keys(self::TYPES), []);
        foreach (Database::all('SELECT list_type, value FROM lookups ORDER BY value') as $r) {
            $out[$r['list_type']][] = $r['value'];
        }
        return $out;
    }

    public static function ofType(string $type): array
    {
        return array_column(
            Database::all('SELECT value FROM lookups WHERE list_type = ? ORDER BY value', [$type]),
            'value'
        );
    }

    /** عدة قوائم مطلوبة في الشاشة نفسها باستعلام واحد. */
    public static function ofTypes(array $types): array
    {
        $types = array_values(array_unique(array_filter(
            $types,
            static fn($type): bool => is_string($type) && isset(self::TYPES[$type])
        )));
        $out = array_fill_keys($types, []);
        if (!$types) { return $out; }
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        foreach (Database::all(
            "SELECT list_type, value FROM lookups WHERE list_type IN ($placeholders) ORDER BY value",
            $types
        ) as $row) {
            $out[$row['list_type']][] = $row['value'];
        }
        return $out;
    }

    /** إضافة قيمة إن لم تكن موجودة */
    /**
     * إضافة قيمة للقائمة المنسدلة.
     *
     * ⚠️⚠️ **القائمة مش سجلّ لكل ما كُتِب — هي المفردات المعتمدة.** قبل التحصين ده كانت
     * `INSERT IGNORE` على النص الحرفي، يعني أول ما حد يكتب «40» بدل «40 قدم» أو
     * «الإسكندرية» بدل «ميناء الإسكندرية» بتدخل القائمة وتبقى **اختيارًا شرعيًّا لكل
     * الموظفين بعد كده** — فالغلطة ما بتفضلش غلطة، بتتحوّل لخيار. ده كان **مضخّم**
     * التلوّث الأساسي في النظام.
     *
     * القاعدة دلوقتي — لا تُضاف القيمة لو:
     *   (أ) فيه قيمة في نفس القائمة بنفس `normkey` (تهجئة تانية لنفس الشيء)، أو
     *   (ب) حارس الأسماء شايفها متعارضة مع اسم معروف (ميناء X ≡ X · «40» ⊂ «40 قدم» · غلط مطبعي).
     *
     * `$force = true` للإضافة المتعمَّدة من شاشة الإعدادات فقط (قرار المالك يغلب الحارس).
     * ⚠️ الرفض هنا **ما بيمنعش الحفظ** — القيمة بتتخزَّن في السجل عادي، اللي بيتمنع
     *    هو تلويث قائمة الاقتراحات بس. والاقتراحات بتتقرا من البيانات الحيّة كمان
     *    (`NameSuggestion::SOURCES`) فمفيش اسم مشروع بيختفي من الاقتراح.
     */
    public static function add(string $type, string $value, bool $force = false): void
    {
        $value = trim($value);
        if ($value === '' || !isset(self::TYPES[$type])) {
            return;
        }
        // اسم إيصال/مصروف فيه رقم = خطأ إدخال (الكمية ليها خانتها) — ما نلوّثش بيه الاقتراحات.
        if (in_array($type, self::NOTE_TYPES, true) && preg_match('/[0-9]/', ar2en_digits($value))) {
            return;
        }

        if (!$force) {
            // القائمة المقفولة ما بتتغذّاش من الإدخال اليومي إطلاقًا — الإضافة
            // قرار متعمَّد من `/settings` (اللي بينادي بـ`$force = true`).
            if (self::isStrict($type)) { return; }
            // (أ) نفس المفتاح موجود ⇒ القائمة فيها الشيء ده أصلاً بتهجئة تانية
            $key = Maintenance::normkey($value);
            foreach (self::ofType($type) as $existing) {
                if (Maintenance::normkey($existing) === $key) { return; }
            }
            // (ب) الحارس شايف تعارضًا مع اسم معروف
            if (NameSuggestion::resolve($type, $value)['conflict'] !== null) { return; }
        }

        Database::exec(
            'INSERT IGNORE INTO lookups (list_type, value) VALUES (?, ?)',
            [$type, $value]
        );
        NameSuggestion::clearNamesCache();   // القائمة اتغيّرت — كاش الأسماء بقى قديمًا
    }

    public static function delete(int $id): void
    {
        Database::exec('DELETE FROM lookups WHERE id = ?', [$id]);
        NameSuggestion::clearNamesCache();
    }

    public static function withIds(): array
    {
        $out = array_fill_keys(array_keys(self::TYPES), []);
        foreach (Database::all('SELECT id, list_type, value FROM lookups ORDER BY value') as $r) {
            $out[$r['list_type']][] = ['id' => $r['id'], 'value' => $r['value']];
        }
        return $out;
    }
}
