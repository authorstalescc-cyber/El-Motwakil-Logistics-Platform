<?php
/**
 * شريط فلترة `GET` — المكوّن الموحّد.
 *
 * النظام فيه **٢٢ فورم فلترة مكتوب بالإيد**، كل واحد بترتيب وأسماء مختلفة،
 * وكتير منهم بـ`style=` inline على الفورم نفسه. المكوّن ده بيبنيه من مواصفة.
 *
 * المتغيّرات:
 *   action  string   مسار الفلترة (بعد `url()`)
 *   fields  array    كل عنصر:
 *                    ['type'=>'search|text|date|select|hidden',
 *                     'name'=>, 'value'=>, 'label'=>?, 'placeholder'=>?,
 *                     'options'=>[value=>label]  (للـselect)]
 *   submit  ?string  نص زر التصفية (الافتراضي «تصفية»)
 *   reset   ?string  رابط إلغاء الفلترة — بيظهر لو `active` = true
 *   active  ?bool    فيه فلتر مفعّل دلوقتي؟
 *
 * ⚠️ القيم كلها بتتهرّب بـ`e()`. أي حقل جديد يتضاف هنا مش في الشاشات.
 */
$action = $action ?? '';
$fields = $fields ?? [];
$submit = $submit ?? 'تصفية';
$reset  = $reset  ?? null;
$active = (bool) ($active ?? false);
?>
<form method="get" action="<?= e($action) ?>" class="filter-form">
  <?php foreach ($fields as $f):
    $type  = $f['type']  ?? 'text';
    $name  = $f['name']  ?? '';
    $val   = (string) ($f['value'] ?? '');
    $label = $f['label'] ?? null;
    if ($name === '') { continue; }

    if ($type === 'hidden'): ?>
      <input type="hidden" name="<?= e($name) ?>" value="<?= e($val) ?>">
    <?php continue; endif; ?>

    <?php if ($label !== null): ?><label class="filter-lbl" for="flt-<?= e($name) ?>"><?= e($label) ?></label><?php endif; ?>

    <?php if ($type === 'select'): ?>
      <select id="flt-<?= e($name) ?>" name="<?= e($name) ?>"<?= $label === null ? ' aria-label="' . e($f['placeholder'] ?? $name) . '"' : '' ?>>
        <?php foreach (($f['options'] ?? []) as $ov => $ol): ?>
          <option value="<?= e($ov) ?>"<?= ((string) $ov === $val) ? ' selected' : '' ?>><?= e($ol) ?></option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <input id="flt-<?= e($name) ?>" type="<?= e($type) ?>" name="<?= e($name) ?>" value="<?= e($val) ?>"
             <?= isset($f['placeholder']) ? 'placeholder="' . e($f['placeholder']) . '"' : '' ?>
             <?= $label === null && isset($f['placeholder']) ? 'aria-label="' . e($f['placeholder']) . '"' : '' ?>>
    <?php endif; ?>
  <?php endforeach; ?>
  <button class="btn sm" type="submit"><?= e($submit) ?></button>
  <?php if ($active && $reset): ?><a class="btn ghost sm" href="<?= e($reset) ?>">إلغاء</a><?php endif; ?>
</form>
