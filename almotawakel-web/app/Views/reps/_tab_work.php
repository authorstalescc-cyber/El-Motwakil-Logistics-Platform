<?php
/**
 * تبويب «الشغل» — **أرقام أداء المندوب** + تفاصيل شغله.
 *
 * ⚠️⚠️ **«اشتغل عليه» = سجّل عليه بند فعلاً** مش «اتنزّلله» — الفرق مقصود
 *    وموثَّق في `RepProfile::stats()`. الأذونات المنزّلة رقم إداري بيفضل سنة،
 *    والبند بتاريخ = شغل حصل يومه.
 * ⚠️ **عرض بحت**: ولا فورم كتابة. الاعتماد والرفض مكانهم صندوق المراجعة في
 *    `/rep-custody` — نسختان من نفس الإجراء بتفترقا أول تعديل.
 *
 * @var array $work @var array $stats @var array $allStats @var int $rid @var array $period
 */
$LIST = 12;   // ⚠️ سقف الصفوف — الجدول بـ٢٣ صف كان بيبلع الشاشة (اتصاد بلقطة)
?>

<?php /* ═══ أرقام الفترة — «اشتغل على كام» ═══ */ ?>
<?php partial('stat_grid', ['items' => 5, 'body' => function () use ($stats, $dr) { ?>
  <?php partial('stat', [
    'label' => 'حاويات الفترة', 'value' => intf($stats['containers']),
    'icon' => 'containers', 'tone' => 'navy', 'drill' => $dr('permits'),
    'title' => 'الأذونات اللي اشتغل عليها',
  ]); ?>
  <?php partial('stat', [
    'label' => 'أذونات اشتغل عليها', 'value' => intf($stats['permits']),
    'icon' => 'folder', 'tone' => 'teal', 'drill' => $dr('permits'),
    'title' => 'الأذونات اللي اشتغل عليها',
  ]); ?>
  <?php partial('stat', [
    'label' => 'بنود سجّلها', 'value' => intf($stats['entries']),
    'icon' => 'clipboard', 'tone' => 'gold', 'drill' => $dr('entries'),
    'title' => 'بنوده في الفترة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'صرف من محفظته', 'value' => money0($stats['amount'], false),
    'icon' => 'wallet', 'tone' => 'red', 'unit' => 'ج', 'drill' => $dr('custody'),
    'title' => 'اللي صرفه من محفظته',
  ]); ?>
  <?php partial('stat', [
    'label' => 'أيام فيها شغل', 'value' => intf($stats['days']),
    'icon' => 'calendar', 'tone' => 'purple', 'drill' => $dr('entries'),
    'title' => 'بنوده في الفترة',
  ]); ?>
<?php }]); ?>

<?php /* ═══ الإجمالي من أول يوم + جودة الشغل ═══ */ ?>
<div class="two-col">
  <div class="card oc-pad">
    <div class="section-head oc-sec"><?= icon('bar-chart', 17) ?> من أول يوم شغل</div>
    <div class="rpf-calc">
      <div class="rpf-line"><span class="rpf-k">حاويات</span>
        <span class="rpf-v"><?= intf($allStats['containers']) ?></span></div>
      <div class="rpf-line"><span class="rpf-k">أذونات</span>
        <span class="rpf-v"><?= intf($allStats['permits']) ?></span></div>
      <div class="rpf-line"><span class="rpf-k">شركات اشتغل معاها</span>
        <span class="rpf-v"><?= intf($allStats['companies']) ?></span></div>
      <div class="rpf-line"><span class="rpf-k">بنود سجّلها</span>
        <span class="rpf-v"><?= intf($allStats['entries']) ?></span></div>
      <div class="rpf-line"><span class="rpf-k">أيام فيها شغل</span>
        <span class="rpf-v"><?= intf($allStats['days']) ?></span></div>
      <div class="rpf-line total"><span class="rpf-k">صرف من محفظته</span>
        <span class="rpf-v"><?= money($allStats['amount'], false) ?></span></div>
    </div>
  </div>

  <div class="card oc-pad">
    <div class="section-head oc-sec"><?= icon('check-circle', 17) ?> جودة بنوده</div>
    <div class="rpf-calc">
      <div class="rpf-line"><span class="rpf-k">اتعتمد</span>
        <span class="rpf-v pos"><?= intf($allStats['approved']) ?></span></div>
      <div class="rpf-line"><span class="rpf-k">اترفض</span>
        <span class="rpf-v neg"><?= intf($allStats['rejected']) ?></span></div>
      <div class="rpf-line"><span class="rpf-k">لسه تحت المراجعة</span>
        <span class="rpf-v"><?= intf($allStats['pending']) ?></span></div>
      <div class="rpf-line total">
        <span class="rpf-k">نسبة الاعتماد</span>
        <?php /* ⚠️ من **المحسوم** بس (معتمد + مرفوض): حساب المستنّي في المقام
                 كان بينزّل النسبة كل ما يسجّل بند جديد — عقاب على الشغل. */ ?>
        <span class="rpf-v <?= $allStats['accuracy'] === null ? '' : ($allStats['accuracy'] >= 90 ? 'pos' : 'neg') ?>">
          <?= $allStats['accuracy'] === null ? '—' : intf($allStats['accuracy']) . '٪' ?>
        </span>
      </div>
    </div>
    <?php /* تحذير وظيفي (≤٦ كلمات) — بيظهر لما يبقى له لازمة بس */ ?>
    <?php if ($allStats['accuracy'] !== null && $allStats['accuracy'] < 80): ?>
      <div class="card mb p5-pad-sm p5-edge-gold-r">
        <?= icon('alert', 15) ?> نسبة رفض عالية — راجع بنوده.
      </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ═══ الأذونات المنزّلة له ═══ */ ?>
<div class="card mb">
  <div class="section-head rts-head">
    <?= icon('folder', 17) ?> أذونات منزّلة له
    <div class="spacer"></div>
    <span class="chip"><?= intf(count($work['permits'])) ?></span>
  </div>
  <?php partial('table', [
    'cols'  => [['label' => 'الإذن'], ['label' => 'العميل'], ['label' => 'الشركة'],
                ['label' => 'حاويات'], ['label' => 'التاريخ'], ['label' => 'بنوده'], ['label' => 'الحالة']],
    'count' => count($work['permits']),
    'wrapClass' => 'rpf-tight', 'empty' => ['title' => 'مفيش أذونات منزّلة له'],
    'rows'  => function () use ($work, $LIST) {
      foreach (array_slice($work['permits'], 0, $LIST) as $a): ?>
        <tr>
          <td><a href="<?= e(url('permits/' . (int) $a['permit_id'])) ?>"><?= e((string) ($a['permit_no'] ?: '#' . (int) $a['permit_id'])) ?></a></td>
          <td class="txt"><?= e((string) ($a['client'] ?? '—')) ?></td>
          <td class="txt"><?= e((string) ($a['company'] ?? '—')) ?></td>
          <td><?= intf((int) ($a['containers'] ?? 0)) ?></td>
          <td><?= $a['permit_date'] ? e(dfmt((string) $a['permit_date'])) : '—' ?></td>
          <td><?= intf((int) ($a['subs'] ?? 0)) ?></td>
          <td><span class="chip"><?= ($a['status'] ?? '') === 'done' ? 'خلص' : 'شغّال' ?></span></td>
        </tr>
      <?php endforeach;
    },
  ]); ?>
  <?php /* تحذير وظيفي: الجدول مقصوص — الرقم فوق هو الحقيقة */ ?>
  <?php if (count($work['permits']) > $LIST): ?>
    <div class="card mb p5-pad-sm">
      <?= icon('info', 15) ?> أول <?= intf($LIST) ?> من <?= intf(count($work['permits'])) ?>.
    </div>
  <?php endif; ?>
</div>

<div class="two-col">
  <div class="card">
    <div class="section-head rts-head"><?= icon('truck', 17) ?> عهد بوالص من محفظته</div>
    <?php partial('table', [
      'cols'  => [['label' => 'البوليصة'], ['label' => 'التاريخ'], ['label' => 'السائق'], ['label' => 'العهدة']],
      'count' => count($work['bills']),
      'wrapClass' => 'rpf-tight', 'empty' => ['title' => 'مفيش عهد بوالص'],
      'rows'  => function () use ($work, $LIST) {
        foreach (array_slice($work['bills'], 0, $LIST) as $b): ?>
          <tr>
            <td><a href="<?= e(url('bawalis/' . (int) $b['id'])) ?>"><?= e((string) ($b['bill_number'] ?: '#' . (int) $b['id'])) ?></a></td>
            <td><?= $b['bill_date'] ? e(dfmt((string) $b['bill_date'])) : '—' ?></td>
            <td class="txt"><?= e((string) ($b['driver'] ?: '—')) ?></td>
            <td class="neg"><?= money((float) $b['custody'], false) ?></td>
          </tr>
        <?php endforeach;
      },
    ]); ?>
  </div>

  <div class="card">
    <div class="section-head rts-head"><?= icon('handshake', 17) ?> تسليمات للسائقين</div>
    <?php partial('table', [
      'cols'  => [['label' => 'التاريخ'], ['label' => 'السائق'], ['label' => 'المبلغ'], ['label' => 'البيان']],
      'count' => count($work['handovers']),
      'wrapClass' => 'rpf-tight', 'empty' => ['title' => 'مفيش تسليمات'],
      'rows'  => function () use ($work, $LIST) {
        foreach (array_slice($work['handovers'], 0, $LIST) as $h): ?>
          <tr>
            <td><?= $h['handover_date'] ? e(dfmt((string) $h['handover_date'])) : '—' ?></td>
            <td class="txt"><?= e((string) ($h['driver'] ?? $h['driver_name'] ?? '—')) ?></td>
            <td class="neg"><?= money((float) $h['amount'], false) ?></td>
            <td class="txt"><?= e((string) ($h['note'] ?? '—')) ?></td>
          </tr>
        <?php endforeach;
      },
    ]); ?>
  </div>
</div>

<div class="card mb">
  <div class="section-head rts-head">
    <?= icon('coins', 17) ?> نثرياته
    <div class="spacer"></div>
    <span class="chip"><?= intf(count($work['misc'])) ?></span>
  </div>
  <?php partial('table', [
    'cols'  => [['label' => 'التاريخ'], ['label' => 'النوع'], ['label' => 'البيان'], ['label' => 'المبلغ']],
    'count' => count($work['misc']),
    'wrapClass' => 'rpf-tight', 'empty' => ['title' => 'مفيش نثريات'],
    'rows'  => function () use ($work, $LIST) {
      foreach (array_slice($work['misc'], 0, $LIST) as $m): ?>
        <tr>
          <td><?= $m['exp_date'] ? e(dfmt((string) $m['exp_date'])) : '—' ?></td>
          <td><span class="chip"><?= icon(\App\Models\RepMiscKind::icon($m['misc_kind'] ?? null), 12) ?>
              <?= e(\App\Models\RepMiscKind::label($m['misc_kind'] ?? null)) ?></span></td>
          <td class="txt"><?= e((string) ($m['note'] ?? '—')) ?></td>
          <td class="neg"><?= money((float) $m['amount'], false) ?></td>
        </tr>
      <?php endforeach;
    },
  ]); ?>
</div>
