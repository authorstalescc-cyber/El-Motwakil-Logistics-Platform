<?php
/**
 * اختيار الشحنة (البوليصة) اللي هنولّد لها رابط تتبع.
 * البوالص اللي ليها رحلة نشطة بالفعل مستبعدة (Track::trackableBills).
 * ⚠️ ما نعرضش أي مبلغ هنا — الاختيار بالسائق/السيارة/الوجهة بس.
 */
$hasBase = $base !== '';
?>
<div class="pg-head">
  <div>
    <h2 class="pg-title"><?= icon('map-pin', 22) ?> رابط تتبع جديد</h2>
    <p class="pg-sub">اختار الشحنة، اكتب رقم واتساب السائق، والنظام هيجهّزلك الرسالة كاملة.</p>
  </div>
  <div class="pg-actions">
    <a class="btn ghost" href="<?= url('tracking') ?>">‹ رجوع للتتبع</a>
  </div>
</div>

<?php if (!$hasBase): ?>
  <div class="flash error" style="margin-bottom:12px">
    <?= icon('alert', 18) ?>
    <div>
      <b>مفيش رابط عام دلوقتي.</b> تقدر تولّد الرابط عادي، بس مش هيفتح عند السائق لحد ما تشغّل النظام
      من ملف <b>«تشغيل-عام-على-النت»</b>، وبعدين تضغط «جدّد الرابط» من صفحة الرحلة.
    </div>
  </div>
<?php endif; ?>

<form method="get" action="<?= url('tracking/new') ?>" class="card" style="padding:12px;margin-bottom:12px">
  <div style="display:flex;gap:8px">
    <input type="search" name="q" value="<?= e($q) ?>" style="flex:1"
           placeholder="دوّر بالبوليصة، السائق، السيارة، الحاوية، الوجهة، العميل…" autofocus>
    <button class="btn primary" type="submit"><?= icon('search', 15) ?> بحث</button>
    <?php if ($q !== ''): ?><a class="btn ghost" href="<?= url('tracking/new') ?>">مسح</a><?php endif; ?>
  </div>
</form>

<?php if (!$bills): ?>
  <div class="card empty-state" style="text-align:center;padding:40px 20px">
    <div style="color:var(--grey);opacity:.45;margin-bottom:10px"><?= icon('truck', 40) ?></div>
    <h3 style="color:var(--navy);margin-bottom:6px">مفيش بوالص مطابقة</h3>
    <p style="color:var(--grey);font-size:.87rem">
      <?= $q !== '' ? 'جرّب كلمة بحث تانية.' : 'كل البوالص الحديثة ليها رابط تتبع نشط بالفعل.' ?>
    </p>
  </div>
<?php else: ?>
<div class="card" style="padding:0;overflow-x:auto">
  <table class="data">
    <thead>
      <tr>
        <th>البوليصة</th><th>التاريخ</th><th>السائق</th><th>السيارة</th>
        <th>الحاوية</th><th>الوجهة</th><th>العميل</th>
        <th data-no-sort data-no-hide>رقم الواتساب</th>
        <th data-no-sort data-no-hide></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($bills as $b): ?>
      <tr>
        <td><b><?= e($b['bill_number'] ?: '#' . $b['id']) ?></b></td>
        <td><?= e(dfmt($b['bill_date'])) ?></td>
        <td><?= e($b['driver'] ?: '—') ?></td>
        <td><?= e($b['car_no'] ?: '—') ?></td>
        <td><?= e($b['container_no'] ?: containerLabel($b['container_type'] ?? '', $b['container_count'] ?? 0)) ?></td>
        <td><?= e($b['destination'] ?: '—') ?></td>
        <td><?= e($b['declaration_owner'] ?: ($b['company'] ?: '—')) ?></td>
        <td colspan="2" style="padding:6px">
          <form method="post" action="<?= url('tracking') ?>" class="no-confirm" style="display:flex;gap:6px;margin:0">
            <?= csrfField() ?>
            <input type="hidden" name="bill_id" value="<?= (int) $b['id'] ?>">
            <input type="tel" name="driver_phone" value="<?= e($b['driver_phone'] ?? '') ?>"
                   placeholder="01xxxxxxxxx" style="width:140px" inputmode="tel"
                   title="رقم واتساب السائق — النظام هيفتكره المرة الجاية">
            <button class="btn sm primary" type="submit"><?= icon('send', 14) ?> ولّد الرابط</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p style="font-size:.78rem;color:var(--grey);margin-top:8px">
  الرقم اختياري — من غيره الرابط هيتولّد وتقدر تنسخه وتبعته بإيدك.
  لو كتبته، النظام هيفتكره للسائق ده في المرات الجاية.
</p>
<?php endif; ?>
