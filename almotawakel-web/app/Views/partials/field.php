<?php
/**
 * حقل نموذج — المكوّن الموحّد.
 *
 * ليه موجود: النظام فيه **٦٩ خاصية `required`** مقابل **٤ علامات مرئية** — يعني
 * المستخدم مش عارف إيه الإلزامي غير لما يدوس حفظ ويرجعه الخطأ.
 *
 * ⚠️ بيستخدم `.field` **المكوّن المعرَّف فعلاً في `app.css`** (سطر 321+) بنفس
 *    الماركب اللي الشاشات ماشية عليه: `<div class="field"><label>…</label><input>`.
 *    **ما اخترعناش كلاس جديد** عشان الشاشات المحوَّلة تطلع مطابقة للقديم بالحرف.
 *    (`.fld` كلاس تاني مستخدم في `tracking/places` و`filter-bar` — ما نخلطش بينهم.)
 *
 * بيبني فوق الدوال القائمة: `fieldErrorAttrs()` و`fieldError()` و`.field.has-error`.
 *
 * المتغيّرات:
 *   name     string
 *   label    string
 *   type     ?string   text|number|date|search|tel|email|password|textarea|select
 *   value    ?string
 *   options  ?array    [value=>label] للـselect
 *   required ?bool     بيحطّ `required` + علامة `*` مرئية على نص التسمية
 *   hint     ?string   تلميح تحت الحقل (`.hint` الموجودة)
 *   errors   ?array    مصفوفة أخطاء النموذج
 *   attrs    ?string   سمات جاهزة (step/min/max/form/placeholder/…) — **مش بتتهرّب**
 *   rows     ?int      للـtextarea
 *   class    ?string   كلاسات إضافية على `.field`
 */
$name     = $name     ?? '';
$label    = $label    ?? '';
$type     = $type     ?? 'text';
$value    = (string) ($value ?? '');
$options  = $options  ?? [];
$required = (bool) ($required ?? false);
$hint     = $hint     ?? null;
$errors   = $errors   ?? [];
$attrs    = $attrs    ?? '';
$rows     = (int) ($rows ?? 3);
$class    = $class    ?? '';
$id       = 'f-' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
$errAttrs = fieldErrorAttrs($name, $errors);
$hintId   = ($hint !== null && $hint !== '') ? $id . '-hint' : null;
$cls      = 'field' . (!empty($errors[$name]) ? ' has-error' : '') . ($class !== '' ? ' ' . $class : '');
$common   = ($required ? ' required' : '')
          . ($hintId ? ' aria-describedby="' . e($hintId) . '"' : '')
          . $errAttrs . $attrs;
?>
<div class="<?= e($cls) ?>">
  <label for="<?= e($id) ?>"><?= e($label) ?><?= $required ? '<span class="req" aria-hidden="true">*</span>' : '' ?></label>
  <?php if ($type === 'textarea'): ?>
    <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= $rows ?>"<?= $common ?>><?= e($value) ?></textarea>
  <?php elseif ($type === 'select'): ?>
    <select id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $common ?>>
      <?php foreach ($options as $ov => $ol): ?>
        <option value="<?= e($ov) ?>"<?= ((string) $ov === $value) ? ' selected' : '' ?>><?= e($ol) ?></option>
      <?php endforeach; ?>
    </select>
  <?php else: ?>
    <input id="<?= e($id) ?>" type="<?= e($type) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"<?= $common ?>>
  <?php endif; ?>
  <?php if ($hintId): ?><small class="hint" id="<?= e($hintId) ?>"><?= e($hint) ?></small><?php endif; ?>
  <?= fieldError($name, $errors) ?>
</div>
