<?php
/** الحسابات هي المحافظ؛ الكروت أدوات صرف تابعة. */
$od = static fn (string $what): string => url('office/drill/' . $what);
$cardsByAccount = [];
foreach ($cards as $card) { $cardsByAccount[(int)($card['bank_account_id'] ?? 0)][] = $card; }
?>
<div class="toolbar">
  <div class="section-head"><?= icon('landmark', 18) ?> الحسابات البنكية وكروت الفيزا</div>
  <div class="spacer"></div>
  <a class="btn ghost" href="<?= url('office/new') ?>"><?= icon('credit-card', 15) ?> إضافة كارت</a>
  <a class="btn gold" href="<?= url('office/accounts/new') ?>"><?= icon('plus', 15) ?> إضافة حساب</a>
</div>

<?php partial('stat_grid', ['items'=>3, 'class'=>'mb', 'body'=>function () use ($summary,$od) { ?>
  <?php partial('stat', ['label'=>'إجمالي الرصيد المتاح','value'=>intf($summary['balance']),
    'unit'=>intf($summary['count']).' حساب · '.intf($summary['cards']).' كارت',
    'icon'=>'landmark','tone'=>'gold','drill'=>$od('balance'),'title'=>'رصيد كل حساب']); ?>
  <?php partial('stat', ['label'=>'صافي الإيداع والسحب','value'=>intf($summary['entries']),
    'icon'=>'wallet','tone'=>'green','drill'=>$od('deposits'),'title'=>'صافي حركات الحسابات الموقعة']); ?>
  <?php partial('stat', ['label'=>'إجمالي المدفوع بالكروت','value'=>intf($summary['payments']),
    'icon'=>'trending-down','tone'=>'red','drill'=>$od('payments'),'title'=>'الصرف من كل الحسابات']); ?>
<?php }]); ?>

<?php partial('panel', [
  'title'=>'الحسابات البنكية','icon'=>'landmark',
  'badge'=>$accounts ? ['text'=>intf(count($accounts)),'tone'=>'navy'] : null,
  'class'=>'mb','body'=>function () use ($accounts,$cardsByAccount) {
    if (!$accounts) {
      partial('empty',['emoji'=>'🏦','title'=>'مفيش حسابات بنكية لسه',
        'action'=>['label'=>'إضافة أول حساب','url'=>url('office/accounts/new')]]); return;
    }
    echo '<div class="rp-cards flat">';
    foreach ($accounts as $a) {
      $tags = [
        ['icon'=>'credit-card','text'=>intf($a['cards_count']).' كارت'],
        ['icon'=>'wallet','text'=>'صافي الحركات '.money($a['entry_total'],false)],
        ['icon'=>'trending-down','text'=>'مدفوع '.money($a['pay_total'],false),'tone'=>'ex'],
      ];
      if ($a['bank_name']) { $tags[]=['icon'=>'home','text'=>$a['bank_name']]; }
      if ($a['status']==='inactive') { $tags[]=['icon'=>'clock','text'=>'متوقف','tone'=>'low']; }
      $names = array_map(static fn($c)=>(string)$c['name'], $cardsByAccount[(int)$a['id']] ?? []);
      if ($names) { $tags[]=['icon'=>'layers','text'=>implode(' · ',$names)]; }
      partial('entity_card',[
        'name'=>$a['name'],'url'=>url('office/accounts/'.(int)$a['id']),
        'value'=>money($a['balance'],false),'value_label'=>'الرصيد الموحّد',
        'value_tone'=>$a['balance'] < 0 ? 'neg' : 'pos','alert'=>$a['balance'] < 0,'tags'=>$tags,
      ]);
    }
    echo '</div>';
  },
]); ?>

<?php partial('panel', [
  'title'=>'كل كروت الفيزا','icon'=>'credit-card',
  'badge'=>$cards ? ['text'=>intf(count($cards)),'tone'=>'navy'] : null,
  'action'=>['label'=>'إضافة كارت','url'=>url('office/new')],
  'body'=>function () use ($cards) {
    if (!$cards) { partial('empty',['emoji'=>'💳','title'=>'مفيش كروت مضافة للحسابات']); return; }
    foreach ($cards as $c) {
      $meta = [['icon'=>'landmark','text'=>$c['account_name'] ?? $c['bank'] ?? 'غير مربوط بحساب بعد'],
               ['icon'=>'trending-down','text'=>'صرف الكارت '.money($c['pay_total'],false)]];
      if ($c['owner']) { $meta[]=['icon'=>'user','text'=>$c['owner']]; }
      partial('row_item',[
        'title'=>$c['name'],'icon'=>'credit-card','url'=>url('office/'.(int)$c['id']),
        'meta'=>$meta,'chips'=>$c['status']==='inactive' ? [['icon'=>'clock','text'=>'متوقف']] : [],
        'amount'=>money($c['balance'],false),'amount_tone'=>$c['balance'] < 0 ? 'neg' : 'pos',
      ]);
    }
  },
]); ?>
