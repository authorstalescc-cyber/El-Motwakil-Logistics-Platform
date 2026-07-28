<?php
use App\Core\App;
use App\Core\FormState;
$errors = $errors ?? [];
$formToken = $formToken ?? FormState::token();
$back = $back ?? $ent['route'];
$groups = [
  'identity'=>'بيانات التعريف','vehicle'=>'السيارة / الكمية',
  'revenue'=>'الإيرادات والتحصيل','expense'=>'المصروفات','result'=>'النتائج (محسوبة تلقائياً)'
];
// تجميع الحقول حسب المجموعة مع الحفاظ على الترتيب
$byGroup = [];
foreach ($ent['fields'] as $f) { $byGroup[$f['group']][] = $f; }
$val = fn($k) => $row[$k] ?? '';
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url($back) ?>">‹ رجوع للقائمة</a>
  <?php if (!$isEdit && ($ent['table'] ?? '') === 'bawalis' && empty($legacyCreate)): ?><a class="btn ghost" href="<?= url('bawalis/new?copy=last') ?>">⧉ نسخ آخر بوليصة</a><?php endif; ?>
</div>

<?php if (!empty($errors['_form'])): ?><div class="form-error-summary" role="alert"><?= icon('alert', 14) ?> <?= e($errors['_form']) ?></div><?php endif; ?>

<?php if (!empty($legacyCreate)): ?>
<div class="card" style="margin-bottom:14px;padding:13px 16px;border-inline-start:5px solid #c9a227;background:#fff9e8;color:#6d5510">
  <b>إضافة إلى البوالص القديمة.</b>
  لن يُنشأ لهذه البوليصة إذن أو حركة، ولن تدخل عهدتها أو أرقامها في التقفيل أو رصيد العهدة أو التقارير التشغيلية؛ وستظهر في السداد وكشوف أصحاب البيان.
</div>
<?php endif; ?>

<?php if ($isEdit && !empty($ent['has_legacy']) && (int)($row['is_legacy'] ?? 0) === 1): ?>
<div class="card" style="margin-bottom:14px;padding:13px 16px;border-inline-start:5px solid #c9a227;background:#fff9e8;color:#6d5510">
  <b>بوليصة قديمة مُرحَّلة من الأرشيف.</b>
  يمكنك تعديل بياناتها وصاحب البيان، لكنها تظل معزولة عن العهدة والتقفيل والتقارير، ولن يُنشأ لها إذن أو حركة.
</div>
<?php endif; ?>

<form class="form-card js-dirty-form" method="post" action="<?= e($action) ?>"
      data-calc="<?= e($ent['calc']) ?>" data-form-mode="<?= $isEdit?'edit':'create' ?>" data-vat="<?= e(App::setting('vat_rate','0.14')) ?>">
  <?= csrfField() ?>
  <?= formStateFields($formToken, $back) ?>
  <?php if($isEdit && array_key_exists('lock_version',$row)): ?><input type="hidden" name="lock_version" value="<?= (int)$row['lock_version'] ?>"><?php endif; ?>
  <?php foreach ($groups as $gk=>$gLabel): if (empty($byGroup[$gk])) continue; ?>
    <div class="form-group-title gt-<?= $gk ?>"><?= $gLabel ?></div>
    <div class="form-grid">
      <?php foreach ($byGroup[$gk] as $f):
        $isCalc = !empty($f['calc']);
        $type = $f['type']; $k = $f['key'];
        $dep = $f['dep'] ?? '';   // حقل مشروط بنوع البوليصة (warid/sader)
        // أرقام الحاويات: خانة لكل حاوية (المكوّن بيتولّى الرسم والتفكيك)
        if ($type === 'containers') {
          partial('container_nos', [
            'value' => $val($k), 'count' => (int) ($val('container_count') ?: 0), 'label' => $f['label'],
          ]);
          continue;
        }
      ?>
        <div class="field <?= $isCalc?'calc':'' ?> <?= !empty($errors[$k])?'has-error':'' ?>" <?= $dep?'data-dep="'.$dep.'"':'' ?>>
          <label><?= e($f['label']) ?><?= $isCalc?' 🔒':'' ?></label>
          <?php if ($type==='billtype'): ?>
            <select name="<?= $k ?>" data-billtype>
              <option value="sader" <?= $val($k)==='sader'?'selected':'' ?>>صادر</option>
              <option value="warid" <?= $val($k)==='warid'?'selected':'' ?>>وارد</option>
            </select>
          <?php elseif ($type==='source'): ?>
            <select name="<?= $k ?>" <?= $isCalc?'disabled':'' ?>>
              <option value="egycool" <?= $val($k)==='egycool'?'selected':'' ?>>ايجي كول</option>
              <option value="shujaie" <?= $val($k)==='shujaie'?'selected':'' ?>>الشجيع</option>
            </select>
          <?php elseif ($type==='select' && \App\Models\Lookup::isStrict($f['lookup'])): ?>
            <?php partial('lookup_select', ['name'=>$k, 'type'=>$f['lookup'], 'value'=>$val($k)]); ?>
          <?php elseif ($type==='select'): ?>
            <input list="dl_<?= $f['lookup'] ?>" name="<?= $k ?>" value="<?= e($val($k)) ?>" autocomplete="off" data-smart-name="<?= e($f['lookup']) ?>" <?= !empty($errors[$k])?'aria-invalid="true"':'' ?>>
          <?php elseif ($type==='date'): ?>
            <input type="date" name="<?= $k ?>" value="<?= e($val($k)) ?>">
          <?php elseif ($type==='status'): ?>
            <input type="text" value="<?= e(\App\Models\Bill::STATUS_LABELS[$val($k)] ?? 'غير مدفوع') ?>" readonly tabindex="-1">
          <?php elseif ($isCalc): ?>
            <input type="text" name="<?= $k ?>" value="<?= $val($k)!==''?e(money($val($k),false)):'' ?>" readonly tabindex="-1">
          <?php elseif ($type==='money'): ?>
            <input type="number" step="0.01" name="<?= $k ?>" value="<?= e($val($k)) ?>" placeholder="0">
          <?php elseif ($type==='int'): ?>
            <input type="number" step="1" name="<?= $k ?>" value="<?= e($val($k)) ?>" placeholder="0">
          <?php else: ?>
            <input type="text" name="<?= $k ?>" value="<?= e($val($k)) ?>" <?= !empty($f['smart'])?'data-smart-name="'.e($f['smart']).'" autocomplete="off"':'' ?> <?= $k==='bill_number'?'data-bill-number-check data-except="'.(int)($row['id']??0).'"':'' ?> <?= !empty($errors[$k])?'aria-invalid="true"':'' ?>>
          <?php endif; ?>
          <?php if (!empty($errors[$k])): ?><small class="field-error" role="alert"><?= e($errors[$k]) ?></small><?php endif; ?>
          <?php if ($k==='bill_number'): ?><small class="field-live-message" data-bill-number-message aria-live="polite"></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <?php if (!empty($legacyCreate)): ?>
    <div class="form-group-title gt-result">السداد التاريخي (اختياري)</div>
    <div class="form-grid">
      <div class="field">
        <label>إجمالي ما استلمه صاحب البيان لهذه البوليصة</label>
        <input type="number" step="0.01" min="0" name="legacy_paid" value="<?= e($legacyPaid ?? 0) ?>" placeholder="0"<?= fieldErrorAttrs('legacy_paid', $errors) ?>>
        <?= fieldError('legacy_paid', $errors) ?>
        <small style="display:block;margin-top:6px;color:var(--grey)">معزولة — مش بتدخل التقفيل</small>
      </div>
    </div>
  <?php endif; ?>

  <div class="form-actions">
    <button class="btn gold" type="submit" data-primary-submit><?= icon('download', 14) ?> <?= $isEdit?'حفظ التعديلات':(!empty($legacyCreate)?'حفظ كبوليصة قديمة':'حفظ') ?></button>
    <?php if (!$isEdit && empty($legacyCreate)): ?><button class="btn ghost" type="submit" name="save_and_new" value="1">حفظ وإضافة آخر</button><?php endif; ?>
    <a class="btn ghost" href="<?= url($back) ?>">إلغاء</a>
  </div>
</form>

<?php // قوائم الإكمال التلقائي للحقول المنسدلة
$usedLists = [];
foreach ($ent['fields'] as $f) { if (($f['type']??'')==='select' && !empty($f['lookup'])) $usedLists[$f['lookup']]=true; }
foreach (array_keys($usedLists) as $lk): ?>
  <datalist id="dl_<?= $lk ?>">
    <?php foreach (($lookups[$lk] ?? []) as $opt): ?><option value="<?= e($opt) ?>"><?php endforeach; ?>
  </datalist>
<?php endforeach; ?>
