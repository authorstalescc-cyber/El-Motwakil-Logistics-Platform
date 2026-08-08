<?php
namespace App;

/**
 * تعريف كيانات النظام وحقولها.
 * تُغذّي هذه التعريفات: النماذج، الجداول، التحقق، الإجماليات، والتقارير — تلقائياً.
 *
 * أنواع الحقول: text | int | money | date | select | pct
 * المجموعات (تحدد اللون): identity | vehicle | revenue | expense | result
 */
class Schema
{
    public static function entities(): array
    {
        return [
            'bawalis'    => self::bawalis(),
            'takhlis'    => self::takhlis(),
            'generators' => self::generators(),
        ];
    }

    public static function get(string $key): ?array
    {
        return self::entities()[$key] ?? null;
    }

    /** الحقول القابلة للإدخال فقط (غير المحسوبة) */
    public static function inputFields(array $entity): array
    {
        return array_filter($entity['fields'], fn($f) => empty($f['calc']));
    }

    // ---------------------------------------------------------------- البوالص
    private static function bawalis(): array
    {
        return [
            'table'    => 'bawalis',
            'route'    => 'bawalis',
            'title'    => 'البوالص',
            'subtitle' => 'سجل بوالص الشحن — ديون العملاء وحالة السداد',
            'icon'     => '🚛',
            'calc'     => 'bawalis',
            'date_field' => 'bill_date',
            'has_legacy' => true,   // فلتر «الكل/الحية/قديمة من الأرشيف» (v26) + شارة 📦 في القائمة
            'detail'   => true,   // الضغط على الصف يفتح صفحة التفاصيل الكاملة
            // ⚠️ `car_owner` و`classification` كانوا ناقصين — أكتر صاحب سيارة عنده 138
            //    بوليصة و116 منها ما كانتش تظهر لو دوّرت باسمه. أي عمود بيتعرض في
            //    الجدول أو صفحة التفاصيل المفروض يبقى هنا.
            'search'   => ['bill_number','cert_number','permit_no','declaration_owner','company','container_no','container_type','car_no','driver','car_owner','destination','classification'],
            'sum_fields' => ['freight','custody','transfer','discount','overnight_collection',
                             'debt','paid','remaining'],
            // الأعمدة المعروضة في الجدول فقط (الباقي يظهر في صفحة التفاصيل)
            'list_fields' => ['bill_type','bill_date','bill_number','company','destination',
                              'container_type','debt','paid','remaining','pay_status'],
            'fields'   => [
                ['key'=>'bill_type','label'=>'نوع البوليصة','type'=>'billtype','group'=>'identity'],
                ['key'=>'bill_date','label'=>'التاريخ','type'=>'date','group'=>'identity'],
                ['key'=>'bill_number','label'=>'رقم البوليصة','type'=>'text','group'=>'identity'],
                ['key'=>'cert_number','label'=>'رقم الشهادة','type'=>'text','group'=>'identity','dep'=>'warid'],
                ['key'=>'permit_no','label'=>'رقم الإذن','type'=>'text','group'=>'identity','dep'=>'sader'],
                // `smart` = نوع اقتراح لحقل نصّي حرّ (بلا قائمة منسدلة) — بيشغّل حارس
                // الأسماء في الواجهة زي حقول `select` بالظبط. الخادم بيحرسه من
                // `CrudController::nameGuardMap`.
                ['key'=>'declaration_owner','label'=>'صاحب البيان','type'=>'text','group'=>'identity','smart'=>'owner'],
                ['key'=>'classification','label'=>'التصنيف','type'=>'select','lookup'=>'classification','group'=>'identity'],
                ['key'=>'company','label'=>'العميل / الشركة','type'=>'select','lookup'=>'company','group'=>'identity'],
                ['key'=>'destination','label'=>'الجهة','type'=>'select','lookup'=>'destination','group'=>'identity'],
                // ⚠️ «عدد الحاويات» قبل «رقم الحاوية» عن قصد: العدد بيحدّد عدد خانات
                // الأرقام (نوع `containers` → المكوّن `partials/container_nos.php`).
                ['key'=>'container_count','label'=>'عدد الحاويات','type'=>'int','group'=>'identity'],
                ['key'=>'container_type','label'=>'تصنيف الحاويات','type'=>'select','lookup'=>'container_type','group'=>'identity'],
                ['key'=>'container_no','label'=>'رقم الحاوية','type'=>'containers','group'=>'identity'],
                ['key'=>'car_no','label'=>'رقم السيارة','type'=>'text','group'=>'vehicle'],
                ['key'=>'driver','label'=>'اسم السائق','type'=>'select','lookup'=>'driver','group'=>'vehicle'],
                ['key'=>'car_owner','label'=>'صاحب السيارة','type'=>'select','lookup'=>'car_owner','group'=>'vehicle'],
                ['key'=>'freight','label'=>'نولون','type'=>'money','group'=>'revenue'],
                ['key'=>'custody','label'=>'عهدة','type'=>'money','group'=>'revenue'],
                /* ⚠️ **مين سلّم السائق العهدة؟** — وسم مش رقم. الافتراضي NULL = خزنة
                   المكتب. اختيار مندوب بيستثني البوليصة من الخزنة **و**يضيف مبلغها
                   لصرف المندوب (الاتنين مع بعض في `RepBillCustody`)، والمخصوم
                   `العهدة − 100` في الحالتين. المكوّن بيختفي قبل migrate_v60. */
                ['key'=>'custody_rep_id','label'=>'مين سلّم السائق؟','type'=>'rep_payer','group'=>'revenue'],
                ['key'=>'transfer','label'=>'تحويلة','type'=>'money','group'=>'revenue'],
                ['key'=>'discount','label'=>'خصم','type'=>'money','group'=>'revenue','default'=>200],
                ['key'=>'overnight_collection','label'=>'بياتة','type'=>'money','group'=>'revenue'],
                /* ═══ مصروفات السيارة — سيارات المكتب بس ═══
                   ⚠️ `office` => true معناها الخانة **ما تظهرش** إلا لو رقم السيارة
                      لوحة مسجَّلة في «سيارات المكتب». السبب إن البنود دي (سولار،
                      كاوتش، دور سواق…) بتخصّ عربية إحنا اللي بندفع تشغيلها؛ العربية
                      المستأجرة بندفع لها نولون مقطوع وخلاص.
                   ⚠️ **مالهاش أي أثر على دين البوليصة** — `Calc::bawala` بتحسب الدين
                      من (نولون − عهدة − تحويلة − خصم + بياتة) وبس، والأعمدة دي
                      خارج المعادلة تمامًا فحساب العميل ما بيتغيّرش بيها. */
                ['key'=>'driver_share','label'=>'دور سواق','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'assistant_share','label'=>'دور تبّاع','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'gas','label'=>'سولار (جاز)','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'kawtsh','label'=>'كاوتش','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'z_kawtsh','label'=>'ظ كاوتش','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'tahia_masr','label'=>'تحيا مصر','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'thabtan','label'=>'ثبتان','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'permits','label'=>'تصاريح','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'cards','label'=>'كرتات','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'overnight_expense','label'=>'بياتة (مصروف)','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'expense','label'=>'مصروف','type'=>'money','group'=>'expense','office'=>true],
                ['key'=>'other_expense','label'=>'مصاريف أخرى','type'=>'money','group'=>'expense','office'=>true],
                /* ⚠️ التلات دول **معادلاتهم مستخرجة من بيانات نظام الإكسل القديم**
                      ومتحقَّق منها على ٧٣ صف في الأرشيف بالمليم — مش تخمين:
                        إجمال المصروف   = مجموع الـ١٢
                        الباقى من العهدة = عهدة − إجمال المصروف   (بيطلع سالب عادي)
                        صافى السيارة    = نولون − إجمال المصروف */
                ['key'=>'total_expense','label'=>'إجمال المصروف','type'=>'money','group'=>'result','calc'=>true,'office'=>true],
                ['key'=>'custody_remaining','label'=>'الباقى من العهدة','type'=>'money','group'=>'result','calc'=>true,'office'=>true],
                ['key'=>'car_net','label'=>'صافى السيارة','type'=>'money','group'=>'result','calc'=>true,'office'=>true],
                ['key'=>'debt','label'=>'دين البوليصة','type'=>'money','group'=>'result','calc'=>true],
                ['key'=>'paid','label'=>'المدفوع','type'=>'money','group'=>'result','calc'=>true],
                ['key'=>'remaining','label'=>'المتبقي','type'=>'money','group'=>'result','calc'=>true],
                ['key'=>'pay_status','label'=>'الحالة','type'=>'status','group'=>'result','calc'=>true],
            ],
        ];
    }

    // ---------------------------------------------------------------- التخليص
    private static function takhlis(): array
    {
        return [
            'table'    => 'takhlis',
            'route'    => 'takhlis',
            'title'    => 'التخليص',
            'subtitle' => 'سجل شهادات وأعمال التخليص الجمركي',
            'icon'     => '📋',
            'calc'     => 'takhlis',
            'date_field' => 'cert_date',
            'search'   => ['cert_name','cert_number'],
            'sum_fields' => ['fees','expenses','total_statement','payments','remaining'],
            'fields'   => [
                ['key'=>'cert_date','label'=>'التاريخ','type'=>'date','group'=>'identity'],
                ['key'=>'cert_name','label'=>'اسم الشهادة / الشركة','type'=>'text','group'=>'identity'],
                ['key'=>'cert_number','label'=>'رقم الشهادة','type'=>'text','group'=>'identity'],
                ['key'=>'fees','label'=>'الأتعاب','type'=>'money','group'=>'revenue'],
                ['key'=>'expenses','label'=>'المصروفات','type'=>'money','group'=>'expense'],
                ['key'=>'total_statement','label'=>'إجمالي الكشف','type'=>'money','group'=>'result','calc'=>true],
                ['key'=>'payments','label'=>'الدفعات','type'=>'money','group'=>'revenue'],
                ['key'=>'remaining','label'=>'المتبقي','type'=>'money','group'=>'result','calc'=>true],
            ],
        ];
    }

    // --------------------------------------------------------------- المولدات
    private static function generators(): array
    {
        return [
            'table'    => 'generators',
            'route'    => 'generators',
            'title'    => 'المولدات',
            'subtitle' => 'سجل نقل المولدات (ايجي كول / الشجيع)',
            'icon'     => '⚙️',
            'calc'     => 'generators',
            'date_field' => 'gen_date',
            'has_source' => true,   // يميّز ايجي كول عن الشجيع
            'search'   => ['company','address','permit_no'],
            'sum_fields' => ['gen_count','freight','payments','remaining'],
            'fields'   => [
                ['key'=>'source','label'=>'الجهة','type'=>'source','group'=>'identity'],
                ['key'=>'gen_date','label'=>'التاريخ','type'=>'date','group'=>'identity'],
                ['key'=>'company','label'=>'اسم الشركة','type'=>'select','lookup'=>'company','group'=>'identity'],
                ['key'=>'address','label'=>'العنوان','type'=>'text','group'=>'identity'],
                ['key'=>'permit_no','label'=>'رقم الإذن','type'=>'text','group'=>'identity'],
                ['key'=>'gen_count','label'=>'عدد المولدات','type'=>'int','group'=>'vehicle'],
                ['key'=>'freight','label'=>'النولون','type'=>'money','group'=>'revenue'],
                ['key'=>'payments','label'=>'الدفعات','type'=>'money','group'=>'revenue'],
                ['key'=>'remaining','label'=>'المتبقي','type'=>'money','group'=>'result','calc'=>true],
            ],
        ];
    }
}
