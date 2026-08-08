<?php
/**
 * قائمة مقفولة (`Lookup::STRICT`) — الموظف بيختار من المعتمد وبس.
 *
 * ليه موجود: الموانئ وتصنيف الحاويات والتوكيلات وشركات الشحن مفرداتها محدودة
 * ومعروفة، وسيبها خانة كتابة حرة هو اللي أنتج «ميناء الإسكندرية / الإسكندرية /
 * اسكندرية» و«40 قدم / 40». المكوّن ده بيتنادى من **كل شاشات الإدخال** بدل ما
 * الماركب يتكرر ويتفرّع.
 *
 * ⚠️⚠️ **القيمة القديمة بتتحط كخيار حتى لو مش في القائمة.** `<select>` مبيقدرش
 *      يعرض قيمة مش في خياراته — فمن غير السطر ده، فتح إذن قديم ميناؤه
 *      «الإسكندرية» كان هيوقع على **أول خيار في القائمة**، وأول حفظة (حتى لو
 *      لتعديل حقل تاني خالص) تغيّر الميناء **بالسكوت**. الخادم بيقبل القيمة دي
 *      على سجلها بس (`Controller::strictListError` مع `$existing`).
 *
 * ⚠️ **بلا أي سكربت وبلا أي كلاس جديد** — تايم لاين الأذونات بيُعاد رسمه
 *    بالـAJAX فالسكربت المحلي ما بيشتغلش (نفس قاعدة `visa_card_select()`)،
 *    و`select` متنسّق أصلاً في كل السياقات (`.field select` · `.tl-fields select`
 *    · `.toolbar select` · `.inline-form select`) فمفيش خطّاف بلا شكل.
 *
 * المتغيّرات:
 *   name     string   اسم الحقل
 *   type     string   نوع القائمة (port/container_type/agency/shipping_co)
 *   value    ?string  القيمة الحالية
 *   label    ?string  نص التسمية — لو موجود بيتلفّ في <label>
 *   labelHtml ?string تسمية جاهزة بـHTML (أيقونة مثلاً) — بديل `label`، مش بتتهرّب
 *   options  ?array   القائمة (لو مش متمرّرة بتتجاب من Lookup)
 *   blank    ?string  نص الخيار الفاضي (افتراضي «— اختر —»)
 *   attrs    ?string  سمات إضافية (style/form/title/…) — **مش بتتهرّب**
 */

use App\Models\Lookup;
use App\Models\Maintenance;

$name      = $name      ?? '';
$type      = $type      ?? '';
$value     = trim((string) ($value ?? ''));
$blank     = $blank     ?? '— اختر —';
$attrs     = $attrs     ?? '';
$labelHtml = $labelHtml ?? (!empty($label) ? e((string) $label) : '');
// ⚠️ `allowedFor` مش `ofType`: هي **نفس** المصدر اللي الخادم بيقبل منه
//    (`Controller::strictListError` · `RepPortal::strictValue`). لو اتفرّقوا،
//    الشاشة تعرض خيارًا والخادم يرفضه.
$options   = $options   ?? Lookup::allowedFor($type);

// القيمة الحالية مش في القائمة؟ تتضاف كخيار موسوم عشان ما تضيعش
$key    = Maintenance::normkey($value);
$inList = false;
foreach ($options as $o) { if (Maintenance::normkey((string) $o) === $key) { $inList = true; break; } }
$legacy = ($value !== '' && !$inList);
?>
<?php if ($labelHtml !== ''): ?><label><?= $labelHtml ?><?php endif; ?>
<select name="<?= e($name) ?>" <?= $attrs ?>>
  <option value=""><?= e($blank) ?></option>
  <?php if ($legacy): ?>
    <option value="<?= e($value) ?>" selected><?= e($value) ?> — قيمة قديمة</option>
  <?php endif; ?>
  <?php foreach ($options as $o): $o = (string) $o; ?>
    <option value="<?= e($o) ?>"<?= Maintenance::normkey($o) === $key ? ' selected' : '' ?>><?= e($o) ?></option>
  <?php endforeach; ?>
</select>
<?php if ($legacy): ?><small title="سيبها زي ما هي أو اختر البديل الصح من القائمة">مش في القائمة المعتمدة</small><?php endif; ?>
<?php if (!$options): ?><small>القائمة فاضية — <a href="<?= url('settings') ?>#tab-lists">أضف مفرداتها من الإعدادات</a>.</small><?php endif; ?>
<?php if ($labelHtml !== ''): ?></label><?php endif; ?>
