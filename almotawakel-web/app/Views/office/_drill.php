<?php
$accountRow = static function(array $a,string $show): void {
  $amount = $show==='deposits' ? $a['entry_total'] : ($show==='payments' ? $a['pay_total'] : $a['balance']);
  partial('row_item',[
    'title'=>$a['name'],'icon'=>'landmark','url'=>url('office/accounts/'.(int)$a['id']),
    'meta'=>[['icon'=>'credit-card','text'=>intf($a['cards_count']).' كارت'],
             ['icon'=>'wallet','text'=>'صافي الحركات '.money($a['entry_total'],false)],
             ['icon'=>'trending-down','text'=>'صرف '.money($a['pay_total'],false)]],
    'chips'=>$a['status']==='inactive' ? [['icon'=>'clock','text'=>'متوقف']] : [],
    'amount'=>money($amount,false),'amount_tone'=>$show==='payments'?'ex':($a['balance']<0?'neg':'pos'),
  ]);
};
$cfg = match($what) {
  'deposits'=>['حركات الحساب الموقّعة: الإيداع موجب والسحب سالب.','صافي الإيداع والسحب',$summary['entries']],
  'payments'=>['كل المصروفات المنسوبة لكروت الحساب؛ وتُخصم من رصيده المشترك.','إجمالي المدفوع',$summary['payments']],
  'owners'=>['عرض تنظيمي لأصحاب الكروت فقط؛ الرصيد على الحساب البنكي ولا يتوزع أو يتكرر على أصحاب كروته.','أصحاب الكروت',count($byOwner)],
  default=>['رصيد الحساب = حركاته الموقّعة − كل الصرف بكروته.','إجمالي الرصيد',$summary['balance']],
};
partial('drill',[
  'how'=>$cfg[0],'count'=>$what==='owners'?count($byOwner):count($accounts),
  'total'=>['label'=>$cfg[1],'value'=>$what==='owners'?intf($cfg[2]):money($cfg[2],false)],
  'more'=>['label'=>'الحسابات والكروت','url'=>url('office')],
  'empty'=>['title'=>'مفيش بيانات لسه'],
  'body'=>function() use($what,$accounts,$byOwner,$accountRow) {
    if ($what==='owners') {
      foreach ($byOwner as $o) { partial('row_item',['title'=>$o['owner'],'icon'=>'user',
        'meta'=>[['icon'=>'credit-card','text'=>intf($o['cards']).' كارت']],
        'amount'=>intf($o['accounts']).' حساب']); } return;
    }
    foreach ($accounts as $a) { $accountRow($a,$what); }
  },
]);
