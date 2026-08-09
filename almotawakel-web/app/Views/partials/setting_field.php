<?php
/**
 * خانة إعداد — **بتترسم من `SettingsSchema` مش بالإيد** (docs/19 · المرحلة ١).
 *
 * ليه موجود: كل خانة إعداد في الشاشة القديمة كانت HTML مكتوب بالإيد، فالنوع
 * والنطاق والشرح والدور كانوا متكرّرين في مكانين (السجل + الماركب) ويقدروا
 * يختلفوا بالسكوت. هنا **السجل هو المصدر**: النوع بيحدّد شكل الخانة، والنطاق
 * بيتحوّل `min`/`max`، والشرح بيبقى تلميح، ودرجة الخطورة بتحطّ علامتها.
 *
 * ⚠️ بيبني على `partials/field.php` — **ما اخترعناش كلاس جديد** (قاعدة ⑤:
 *    صفر `<style>` محلي، وأي كلاس في الماركب لازم يكون له CSS موجود).
 *
 * ⚠️ **المفتاح السرّي عمره ما بيتطبع في `value`.** بيتعرض مقنّعًا في التلميح،
 *    والخانة بتفضل فاضية — سيبها فاضية = خلّي المحفوظ زي ما هو (نفس سلوك
 *    مفاتيح المساعد الذكي القايم بالحرف).
 *
 * المتغيّرات:
 *   key     string   مفتاح الإعداد (لازم يكون معرَّف في SettingsSchema)
 *   value   string   القيمة الحالية
 *   errors  ?array   أخطاء النموذج
 *   class   ?string  كلاسات إضافية على `.field`
 */

use App\Core\SettingsSchema;

$def = SettingsSchema::get((string) ($key ?? ''));
if ($def === null) {
    // الفشل ظاهر في مصدر الصفحة بدل ما يختفي بالسكوت (نفس نمط `partial()`)
    echo '<!-- إعداد غير معرَّف: ' . e((string) ($key ?? '')) . ' -->';
    return;
}

$val    = (string) ($value ?? '');
$errors = $errors ?? [];
$class  = (string) ($class ?? '');

$type    = 'text';
$attrs   = '';
$options = [];
$rows    = 3;

switch ($def['type']) {
    case SettingsSchema::T_INT:
        $type  = 'number';
        $attrs = ' step="1" inputmode="numeric"';
        break;

    case SettingsSchema::T_FLOAT:
    case SettingsSchema::T_RATE:
        $type  = 'number';
        $attrs = ' step="0.01"';
        break;

    case SettingsSchema::T_MONEY:
        // ⚠️ `data-money` إجبارية: الخانة بتتحوّل بجافاسكريبت لـ`type=text` عشان
        //    `type=number` بيفضّي أي قيمة فيها فاصلة (المصيدة الموثَّقة).
        $type  = 'number';
        $attrs = ' step="0.01" data-money';
        break;

    case SettingsSchema::T_DATE:
        $type = 'date';
        break;

    case SettingsSchema::T_LONGTEXT:
    case SettingsSchema::T_LIST:
        $type = 'textarea';
        $rows = 4;
        break;

    case SettingsSchema::T_JSON:
        $type = 'textarea';
        $rows = 6;
        break;

    case SettingsSchema::T_ENUM:
        $type    = 'select';
        $options = array_combine($def['options'], $def['options']) ?: [];
        break;

    case SettingsSchema::T_SECRET:
        $type  = 'password';
        $attrs = ' autocomplete="new-password"';
        $val   = '';        // عمره ما يترجع للشاشة
        break;

    case SettingsSchema::T_BOOL:
        $type = '';         // بيترسم تحت كـcheckbox — `field.php` مالهاش نوع له
        break;
}

// النطاق من السجل → حدود الخانة، فالمتصفح بيقول قبل ما الخادم يرفض
if (in_array($def['type'], [SettingsSchema::T_INT, SettingsSchema::T_FLOAT,
                           SettingsSchema::T_RATE, SettingsSchema::T_MONEY], true)) {
    if ($def['min'] !== null) { $attrs .= ' min="' . e((string) $def['min']) . '"'; }
    if ($def['max'] !== null) { $attrs .= ' max="' . e((string) $def['max']) . '"'; }
}

// الشرح بالعامية + تقنيع المفتاح السرّي المحفوظ
$hint = (string) $def['help'];
if ($def['type'] === SettingsSchema::T_SECRET && trim((string) ($value ?? '')) !== '') {
    $hint = 'محفوظ: ••••' . mb_substr((string) $value, -4) . ' — سيبها فاضية للإبقاء عليه. ' . $hint;
}

$label = (string) $def['label'];
if ($def['tier'] === SettingsSchema::TIER_DANGER) { $label = '⚠️ ' . $label; }

if ($def['type'] === SettingsSchema::T_BOOL):
    $on = $val === '1';
    ?>
    <label class="free-login-option<?= $class !== '' ? ' ' . e($class) : '' ?>">
      <input type="hidden" name="<?= e($def['key']) ?>" value="0">
      <input type="checkbox" name="<?= e($def['key']) ?>" value="1"<?= $on ? ' checked' : '' ?>>
      <span><b><?= e($label) ?></b><small><?= e($def['help']) ?></small></span>
    </label>
    <?php
else:
    partial('field', [
        'name'    => $def['key'],
        'label'   => $label,
        'type'    => $type,
        'value'   => $val,
        'options' => $options,
        'hint'    => $hint,
        'errors'  => $errors,
        'attrs'   => $attrs,
        'rows'    => $rows,
        'class'   => $class,
    ]);
endif;
