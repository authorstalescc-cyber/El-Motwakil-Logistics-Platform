<?php
/**
 * كارت كيان (مندوب / عميل / صاحب بيان) — بأڤاتار ورقم أساسي وشريط وتاجات.
 *
 * ده أهم بديل للجدول في قوايم الكيانات: الجدول بيحوّل «فلان معاه ٢٤٠٠ وصرف
 * ٦٠٠ ومستنّي مراجعة بند» لستة أعمدة لازم تربطهم بعينك؛ الكارت بيحطهم في
 * وحدة واحدة تُقرأ بنظرة.
 *
 * المتغيّرات:
 *   name    string   اسم الكيان (أول حرف بيبقى الأڤاتار)
 *   url     ?string  صفحة الكيان
 *   drill   ?string  مصدر تفاصيل يفتح في النافذة (قاعدة «ممنوع رقم أصمّ»)
 *   sub     ?string  سطر تحت الاسم (آخر حركة…)
 *   value   ?string  الرقم الأساسي على الجنب (متنسّق مسبقًا)
 *   value_label ?string  تسمية الرقم
 *   value_tone  ?string  pos|neg
 *   meter   ?array   [['pct'=>float,'cls'=>'sp|pd|rt'], …] شريط استهلاك
 *   note    ?array   سطر بارز ['label'=>, 'value'=>, 'sub'=>?, 'tone'=>?]
 *   tags    ?array   [['icon'=>?,'text'=>,'tone'=>'ex|wait|low'], …]
 *   cols    ?array   [['label'=>, 'value'=>, 'tone'=>?], …] **أعمدة أرقام
 *                    متحاذية** — بديل `tags` لما الأرقام بتتقارن بين الكروت.
 *   alert   ?bool    الكارت بلون تنبيه (محتاج تنزيل…)
 *
 * ⚠️ **`cols` مقابل `tags` — الفرق مقصود** (بلاغ المالك 2026-08-09 «تصميمهم
 *    مش عاجبني»): الشارات (`tags`) بتطفو على عرض النص فرقم «نزل» في كارت
 *    بيقع تحت رقم «صرف» في الكارت اللي تحته، فمقارنة تسع مناديب ببصمة العين
 *    مستحيلة. `cols` بتحطهم في **شبكة ثابتة** — كل عمود تحت زميله بالظبط،
 *    وده اللي بيخلّي القايمة تُقرا بنظرة بدل ما تتقري كارت كارت.
 */
$name  = (string) ($name ?? '');
$url   = $url ?? null;
$drill = trim((string) ($drill ?? ''));
$sub   = $sub ?? null;
$value = $value ?? null;
$valueLabel = $value_label ?? null;
$valueTone  = $value_tone ?? '';
$meter = $meter ?? [];
$note  = $note ?? null;
$tags  = $tags ?? [];
$cols  = $cols ?? [];
$alert = !empty($alert);

$tag = $url ? 'a' : 'div';
$attrs = $url ? ' href="' . e($url) . '"' : '';
if ($drill !== '') {
    $attrs .= ' data-drill="' . e($drill) . '" data-drill-title="' . e($name) . '"';
}
$initial = $name !== '' ? mb_substr(trim($name), 0, 1, 'UTF-8') : '؟';
?>
<<?= $tag ?> class="rp-card<?= $alert ? ' low' : '' ?><?= $cols ? ' has-cols' : '' ?>"<?= $attrs ?>>
  <div class="rp-card-top">
    <span class="rp-av"><?= e($initial) ?></span>
    <div class="rp-id">
      <b><?= e($name) ?></b>
      <?php if ($sub !== null && $sub !== ''): ?><span><?= e((string) $sub) ?></span><?php endif; ?>
    </div>
    <?php /* أعمدة الأرقام جوّه الصف نفسه — متحاذية بين كل الكروت */ ?>
    <?php if ($cols): ?>
      <div class="rp-cols">
        <?php foreach ($cols as $c): ?>
          <span class="rp-col">
            <em><?= e((string) ($c['label'] ?? '')) ?></em>
            <b class="<?= e((string) ($c['tone'] ?? '')) ?>"><?= e((string) ($c['value'] ?? '')) ?></b>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($value !== null): ?>
      <div class="rp-bal">
        <b class="<?= e($valueTone) ?>"><?= e((string) $value) ?></b>
        <?php if ($valueLabel !== null && $valueLabel !== ''): ?><span><?= e((string) $valueLabel) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($meter): ?>
    <div class="rp-bar">
      <?php foreach ($meter as $seg): ?>
        <i class="<?= e((string) ($seg['cls'] ?? 'sp')) ?>" style="width:<?= (float) ($seg['pct'] ?? 0) ?>%"></i>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (is_array($note)): ?>
    <div class="rp-day">
      <span><?= e((string) ($note['label'] ?? '')) ?></span>
      <b class="<?= e((string) ($note['tone'] ?? '')) ?>"><?= e((string) ($note['value'] ?? '')) ?></b>
      <?php if (($note['sub'] ?? '') !== ''): ?><small><?= e((string) $note['sub']) ?></small><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tags): ?>
    <div class="rp-card-foot">
      <?php foreach ($tags as $t): ?>
        <span class="rp-tag<?= ($t['tone'] ?? '') !== '' ? ' ' . e((string) $t['tone']) : '' ?>"><?php
          if (!empty($t['icon'])) { echo icon($t['icon'], 11); } ?> <?= e((string) ($t['text'] ?? '')) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</<?= $tag ?>>
