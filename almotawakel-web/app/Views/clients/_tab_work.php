<?php
/**
 * تبويب «الشغل» — أذونات العميل + الشركات اللي بينوب عنها + فواتير شامل القديمة.
 *
 * ⚠️ الإذن اللي **مفيهوش مستند** بيتعلّم بشارة «جاهز للفوترة» — ده أسرع طريق
 *    من حساب العميل لفلوس لسه ما اتطالبتش. والإصدار نفسه مكانه شاشة الإذن
 *    (قرار v20) فالزرار بينقل هناك مش بيفتح مسار فوترة تاني.
 *
 * @var array $agent @var array $work
 */
$money    = static fn ($v) => money((float) $v, false);
$permits  = $work['permits'];
$invoiced = $work['invoiced'];
$cos      = $work['companies'];
$shamil   = $work['shamil'];
?>

<?php if ($cos): ?>
  <div class="card mb">
    <div class="section-head"><?= icon('building', 17) ?> شركات بينوب عنها <span class="cl-mut"><?= intf(count($cos)) ?></span></div>
    <div class="cl-cos">
      <?php foreach ($cos as $c): ?>
        <a class="cl-co" href="<?= url('directory/company/' . (int) $c['id']) ?>">
          <span class="cl-co-ava"><?= e(initials((string) $c['name'])) ?></span>
          <b><?= e($c['name']) ?></b>
          <span aria-hidden="true">←</span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card<?= $shamil ? ' mb' : '' ?>">
  <div class="section-head">
    <?= icon('folder', 17) ?> أذونات العميل
    <span class="cl-mut"><?= intf(count($permits)) ?></span>
  </div>

  <?php if (!$permits): ?>
    <?php partial('empty', ['emoji' => '📁', 'title' => 'مفيش أذونات باسم العميل ده']); ?>
  <?php else: ?>
    <div class="d-list flat">
      <?php foreach ($permits as $p):
        $pid  = (int) $p['id'];
        $done = isset($invoiced[$pid]);
        $open = ($p['status'] ?? '') === 'open';
      ?>
        <?php partial('row_item', [
          'title' => ($p['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ') . ($p['permit_no'] ?: '#' . $pid),
          'url'   => url('permits/' . $pid),
          'icon'  => 'folder',
          'tone'  => $p['permit_type'] === 'warid' ? 'warid' : 'sader',
          'meta'  => array_values(array_filter([
              $p['permit_date'] ? ['icon' => 'calendar', 'text' => dfmt($p['permit_date'])] : null,
              ($p['company'] ?? '') !== '' ? ['icon' => 'building', 'text' => (string) $p['company']] : null,
              ($p['destination'] ?? '') !== '' ? ['icon' => 'map-pin', 'text' => (string) $p['destination']] : null,
          ])),
          'chips' => $done
              ? [['icon' => 'receipt', 'text' => 'مفوتر']]
              : ($open ? [['icon' => 'alert', 'text' => 'جاهز للفوترة']] : []),
          'sub'   => $open ? 'جارٍ' : 'مقفول',
        ]); ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($shamil): ?>
  <div class="card">
    <div class="section-head"><?= icon('receipt', 17) ?> فواتير شامل <span class="cl-mut"><?= intf(count($shamil)) ?></span></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th class="g-identity">التاريخ</th><th class="g-identity">رقم الفاتورة</th><th class="g-identity">لصالح شركة</th>
          <th class="g-revenue">الإجمالي</th><th class="g-revenue">الدفعات</th><th class="g-result">الحساب</th>
        </tr></thead>
        <tbody>
          <?php foreach ($shamil as $r): ?>
            <tr>
              <td class="nowrap"><?= e(dfmt($r['invoice_date'])) ?></td>
              <td class="dbt-tot"><?= e($r['invoice_no'] ?: '#' . $r['id']) ?></td>
              <td class="txt"><?= e($r['company'] ?: '—') ?></td>
              <td class="pos"><?= $money($r['invoice_total']) ?></td>
              <td><?= $money($r['payments']) ?></td>
              <td class="dbt-tot"><?= $money($r['account_total']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
