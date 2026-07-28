<?php
/**
 * تنظيف البيانات — النظرة العامة.
 * ⚠️ بتعرض العدّ الرخيص بس («نفس الشيء بتهجئتين»). المقارنة الكاملة بتجري على
 *    صفحة الحقل الواحد لأنها ثواني — الشرح في `DataQuality::overview()`.
 */
$totalSame = 0;
foreach ($overview as $r) { $totalSame += $r['same']; }
?>

<div class="card" style="padding:18px;margin-bottom:16px">
  <div class="section-head"><?= icon('clipboard', 17) ?> تنظيف المسميات المكرَّرة</div>
  <p style="color:var(--grey);line-height:1.9;margin:0">
    النظام بيمنع الأسماء المكرَّرة الجديدة تلقائيًّا، لكن اللي اتسجّل <b>قبل كده</b> محتاج قرارك.
    كل صف تحت بيقول: الحقل ده فيه كام قيمة، وكام منهم نفس الشيء مكتوب بأكتر من طريقة.
    <br>
    <b>«نفس الشيء»</b> = تهجئتان بنفس المعنى قطعًا (زي «بنى سويف» و«بني سويف») — دمجهم آمن.
    <b>«متشابه»</b> = النظام شاكك إنهم نفس الشيء (زي «ميناء الإسكندرية» و«الإسكندرية») — دول محتاجين عينك.
  </p>
</div>

<?php if ($totalSame === 0): ?>
  <?php partial('empty', ['emoji' => '✨', 'title' => 'مفيش تهجئات مكرَّرة مؤكَّدة —
    ادخل على أي حقل تحت لو عايز تشوف المتشابه كمان']); ?>
<?php endif; ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>الحقل</th>
        <th data-no-sort>عدد القيم</th>
        <th data-no-sort>نفس الشيء بتهجئتين</th>
        <th data-no-sort></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($overview as $key => $r): ?>
        <tr>
          <td><b><?= e($r['label']) ?></b></td>
          <td><?= (int) $r['total'] ?></td>
          <td>
            <?php if ($r['same'] > 0): ?>
              <span class="pill" style="background:var(--red-lt);color:var(--red)"><?= (int) $r['same'] ?></span>
            <?php else: ?>
              <span class="pill ok">✓ نظيف</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['party'])): ?>
              <a class="btn ghost sm" href="<?= url('directory') ?>">دمجها من البيانات الأساسية ‹</a>
            <?php else: ?>
              <a class="btn sm" href="<?= url('quality/' . $key) ?>">راجع وادمج ‹</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p style="color:var(--grey);margin-top:14px;line-height:1.9">
  <b>الشركات والعملاء وأصحاب البيان</b> ليهم سجل قانوني مربوط بالفواتير والكشوف،
  فدمجهم بيتعمل من <a href="<?= url('directory') ?>">البيانات الأساسية</a> عشان
  المستندات المرتبطة تتحرّك معاهم.
</p>
