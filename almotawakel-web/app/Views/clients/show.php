<?php
/**
 * حساب العميل (v93) — الغلاف: ترويسة + إجراءات سريعة + أرقام + أعمار الدين + تبويبات.
 *
 * ⚠️ **صفر `<style>` محلي وصفر `style=`** — كلاسات `cl-*` في `app.css`.
 * ⚠️ **التبويبات روابط حقيقية** (`?tab=`) فالرجوع بالمتصفح شغّال.
 * ⚠️ **كل رقم بيُضغط** ويفتح بنوده (`/clients/{id}/drill/{what}`).
 * ⚠️ **صفر جملة تعليمية** — اللي فاضل تحذيرات وظيفية في ≤٦ كلمات.
 *
 * @var array $agent @var string $tab @var array $head @var array $aging
 * @var array $billable @var array $payState @var array $visaCards @var array $openInv
 */
use App\Models\ClientAccount;

$id    = (int) $agent['id'];
$money = static fn ($v) => money0((float) $v, false);
$dr    = static fn (string $w): string => url('clients/' . $id . '/drill/' . $w);
$tabUrl= static fn (string $t): string => url('clients/' . $id) . '?tab=' . $t;
$phone = trim((string) ($agent['phone'] ?? ''));
$wa    = preg_replace('/\D+/', '', $phone);
if ($wa !== '' && str_starts_with($wa, '0')) { $wa = '2' . $wa; }
?>

<?php partial('page_head', [
  'title'   => (string) $agent['name'],
  'back'    => ['label' => 'حسابات العملاء', 'url' => url('clients')],
  'primary' => ['label' => 'كشف حساب للطباعة', 'url' => url('clients/' . $id . '/statement'), 'class' => 'btn gold'],
]); ?>

<div class="cl-head">
  <div class="cl-meta">
    <?php if ($phone !== ''): ?>
      <span><?= icon('smartphone', 13) ?> <bdi><?= e($phone) ?></bdi></span>
    <?php endif; ?>
    <?php if (!empty($agent['tax_id'])): ?>
      <span><?= icon('landmark', 13) ?> <bdi><?= e($agent['tax_id']) ?></bdi></span>
    <?php endif; ?>
    <span><?= icon('clock', 13) ?> مهلة <?= intf((int) ($agent['payment_terms_days'] ?? 30)) ?> يوم</span>
    <?php
    /* تفضيل الفوترة (v100) — **ومصدره معاه**: المالك لازم يفرّق بين قراره هو
       («مثبَّت») وتخمين النظام («من التاريخ»). الاتنين بشكل واحد بيخلّي
       التخمين يتقري كقرار — ودي أخطر حاجة في أي ميزة اقتراح.
       التعديل من «بيانات العميل» — مفيش شاشة جديدة. */
    $pref = \App\Models\ClientPref::forAgent($agent);
    $prefTip = $pref['kind_source'] === 'learned'
        ? 'مستنتَج من ' . intf($pref['kind_hits']) . ' من ' . intf($pref['docs']) . ' مستند — ثبّته من «بيانات العميل»'
        : ($pref['kind_source'] === 'pinned' ? 'مثبَّت في بروفايل العميل' : 'الافتراضي العام');
    ?>
    <span title="<?= e($prefTip) ?>"><?= icon('receipt', 13) ?>
      <?= e(\App\Models\PermitInvoice::kindLabel($pref['doc_kind'])) ?><?= $pref['taxable'] ? ' • ضريبية' : '' ?>
      <?php if ($pref['kind_source'] !== 'default'): ?><span class="pill <?= $pref['kind_source'] === 'pinned' ? 'ok' : 'off' ?>"><?= e(\App\Models\ClientPref::sourceLabel($pref['kind_source'])) ?></span><?php endif; ?>
    </span>
    <span><?= icon('folder', 13) ?> <?= intf($head['permits']['cnt'] ?? 0) ?> إذن</span>
    <span><?= icon('receipt', 13) ?> <?= intf($head['inv_cnt']) ?> مستند</span>
    <?php if (!empty($agent['address'])): ?>
      <span><?= icon('map-pin', 13) ?> <?= e($agent['address']) ?></span>
    <?php endif; ?>
  </div>
  <div class="cl-quick">
    <?php if ($wa !== ''): ?>
      <a class="btn ghost sm" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('send', 14) ?> واتساب</a>
    <?php endif; ?>
    <a class="btn ghost sm" href="<?= e($tabUrl('payments')) ?>#pay"><?= icon('banknote', 14) ?> تسجيل تحصيل</a>
    <a class="btn ghost sm" href="<?= url('clients/' . $id . '/edit') ?>"><?= icon('edit', 14) ?> تعديل البيانات</a>
  </div>
</div>

<?php /* تحذير **وظيفي**: فيه فلوس عدّى ميعادها — أهم سطر في الشاشة. */ ?>
<?php if ($head['overdue'] > 0.005): ?>
  <div class="cl-alert">
    <?= icon('alert', 16) ?>
    <span><b><?= $money($head['overdue']) ?></b> متأخرة من <?= intf($head['late_days']) ?> يوم</span>
    <a class="cl-alert-go" href="<?= e($tabUrl('invoices')) ?>">الفواتير <span aria-hidden="true">←</span></a>
  </div>
<?php endif; ?>

<?php partial('stat_grid', ['items' => 5, 'body' => function () use ($head, $money, $dr) { ?>
  <?php partial('stat', [
    'label' => 'المطلوب منه', 'value' => $money($head['charges']),
    'icon' => 'receipt', 'tone' => 'navy', 'unit' => 'ج',
    'drill' => $dr('charges'), 'title' => 'كل مستنداته',
  ]); ?>
  <?php partial('stat', [
    'label' => 'المحصّل منه', 'value' => $money($head['paid']),
    'icon' => 'banknote', 'tone' => 'green', 'unit' => 'ج',
    'drill' => $dr('paid'), 'title' => 'كل تحصيلاته',
  ]); ?>
  <?php partial('stat', [
    'label' => 'المتبقّي عليه', 'value' => $money($head['remaining']),
    'icon' => 'handshake', 'tone' => 'gold', 'unit' => 'ج',
    'drill' => $dr('remaining'), 'title' => 'الرصيد اتكوّن إزاي',
  ]); ?>
  <?php partial('stat', [
    'label' => 'متأخر عن ميعاده', 'value' => $money($head['overdue']),
    'icon' => 'alert', 'tone' => 'red', 'unit' => 'ج',
    'drill' => $dr('overdue'), 'title' => 'الفواتير المتأخرة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'فواتير مفتوحة', 'value' => intf($head['open_cnt']),
    'icon' => 'layers', 'tone' => 'teal',
    'drill' => $dr('open'), 'title' => 'اللي لسه ليه باقي',
  ]); ?>
<?php }]); ?>

<?php /* أعمار الدين — الشريحة نفسها بتُضغط وتفتح فواتيرها. */ ?>
<?php if ($head['open_balance'] > 0.005): ?>
  <div class="cl-aging">
    <?php foreach ($aging as $key => $b): ?>
      <a class="cl-age <?= e($b['tone']) ?><?= $b['value'] > 0.005 ? ' has' : '' ?>"
         href="<?= e($dr('aging')) ?>" data-drill="<?= e($dr('aging')) ?>" data-drill-title="أعمار دين <?= e($agent['name']) ?>">
        <i><?= e($b['label']) ?></i>
        <b><?= $money($b['value']) ?></b>
        <span><?= intf($b['count']) ?> فاتورة</span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
$tabItems = [];
foreach (ClientAccount::TABS as $key => $meta) {
    $tabItems[] = [
        'label'  => $meta['label'],
        'icon'   => $meta['icon'],
        'url'    => $tabUrl($key),
        'active' => $tab === $key,
    ];
}
partial('tabs', ['items' => $tabItems, 'label' => 'أقسام حساب العميل']);
?>

<?php require __DIR__ . '/_tab_' . $tab . '.php'; ?>
