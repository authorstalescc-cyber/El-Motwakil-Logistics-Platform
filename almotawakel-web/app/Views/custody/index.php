<div class="toolbar">
  <div class="section-head" style="margin:0">💼 رصيد عهدة المكتب</div>
  <div class="spacer"></div>
  <a class="btn ghost" href="<?= url('closing') ?>">التقفيل اليومي ›</a>
</div>

<?php if(!$lastClosed): ?>
<div class="card mb" style="padding:12px 16px;border-right:4px solid var(--gold)">
  💡 لسه مفيش أي يوم متقفل — الرصيد الحالي محسوب من كل الحركة. لتثبيت الأرصدة يومًا بيوم افتح <a href="<?= url('closing') ?>">التقفيل اليومي</a> واضغط «اقفل اليوم».
</div>
<?php endif; ?>

<!-- الرصيد الحالي + تفكيكه المرتبط بالإقفال اليومي -->
<div class="two-col mb">
  <div class="kpi <?= $balance<0?'red':'gold' ?>" style="display:flex;flex-direction:column;justify-content:center;align-items:center;padding:24px">
    <div class="lbl">الرصيد الحالي المتاح</div>
    <div class="val" style="font-size:2rem"><?= intf($balance) ?></div>
    <div style="opacity:.8;font-size:.82rem;margin-top:6px">بعد خصم المصروفات — مطابق للتقفيل اليومي</div>
  </div>
  <div class="card" style="padding:16px 18px">
    <div class="section-head" style="margin:0 0 10px">🧮 من إيه اتكوّن الرصيد</div>
    <table class="data">
      <tr>
        <td style="background:var(--bg);font-weight:700;text-align:right">
          <?php if($breakdown['anchorDate']): ?>رصيد آخر إقفال (<?= e(dfmt($breakdown['anchorDate'])) ?>)<?php else: ?>من البداية<?php endif; ?>
        </td>
        <td><?= money($breakdown['anchor']) ?></td>
      </tr>
      <tr><td style="background:var(--bg);font-weight:700;text-align:right">+ إضافات عهدة<?= $breakdown['anchorDate']?' بعده':'' ?></td><td class="pos"><?= money($breakdown['topups']) ?></td></tr>
      <tr><td style="background:var(--bg);font-weight:700;text-align:right">− عهد السائقين (بعد خصم <?= intf($rate) ?>)</td><td class="neg"><?= money($breakdown['driver']) ?></td></tr>
      <tr><td style="background:var(--bg);font-weight:700;text-align:right">− المصروفات</td><td class="neg"><?= money($breakdown['expenses']) ?></td></tr>
      <tr><td style="background:var(--gold-light);font-weight:800;text-align:right">= الرصيد الحالي</td><td style="font-weight:800;background:var(--gold-light)"><?= money($breakdown['balance']) ?></td></tr>
    </table>
  </div>
</div>

<!-- إضافة عهدة يدوية للرصيد -->
<div class="card mb" style="padding:16px 18px">
  <div class="section-head" style="margin:0 0 10px">➕ إضافة عهدة للرصيد</div>
  <form method="post" action="<?= url('custody/topup') ?>" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
    <?= csrfField() ?>
    <div class="field" style="margin:0;width:160px">
      <label>التاريخ</label>
      <input type="date" name="cust_date" value="<?= e(work_date()) ?>">
    </div>
    <div class="field" style="margin:0;flex:1;min-width:180px">
      <label>البيان / المصدر</label>
      <input type="text" name="note" placeholder="مصدر العهدة (اختياري)">
    </div>
    <div class="field" style="margin:0;width:150px">
      <label>المبلغ</label>
      <input type="number" step="0.01" name="amount" placeholder="0" required>
    </div>
    <button class="btn gold" type="submit">إضافة للرصيد</button>
  </form>
</div>

<!-- السجل التراكمي -->
<div class="card">
  <div class="section-head" style="padding:16px 18px 0;margin:0">📒 سجل حركات العهدة <span style="color:var(--grey);font-weight:400;font-size:.85rem">(الإضافات وعهد السائقين — المصروفات تُخصم في التقفيل والرصيد أعلاه)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ</th><th class="g-identity">الحركة</th>
      <th class="g-identity">الطرف / البيان</th>
      <th class="g-revenue">عهدة السائق</th><th class="g-expense">مخصوم</th>
      <th class="g-revenue">إضافة</th><th class="g-identity">جارٍ (إضافات−عهد)</th><th class="g-identity"></th>
    </tr></thead>
    <tbody>
      <?php if(!$ledger): ?>
        <tr><td colspan="8"><div class="empty"><div class="ic">📭</div>لا حركات عهدة بعد. أضِف عهدة للرصيد من الأعلى.</div></td></tr>
      <?php else: foreach ($ledger as $r): ?>
        <tr>
          <td><?= $r['d'] ? e(dfmt($r['d'])) : '—' ?></td>
          <td>
            <?php if($r['kind']==='topup'): ?>
              <span class="pill st-paid">إضافة يدوية</span>
            <?php else: ?>
              <span class="pill st-partial">عهدة سائق</span> <?= e($r['label']) ?>
            <?php endif; ?>
          </td>
          <td style="text-align:right"><?= e($r['party'] ?: '—') ?>
            <?php if($r['kind']==='driver' && $r['note']): ?><div style="color:var(--grey);font-size:.82rem"><?= e($r['note']) ?></div><?php endif; ?>
          </td>
          <td><?= $r['kind']==='driver' ? money($r['custody'],false) : '—' ?></td>
          <td class="<?= $r['kind']==='driver'?'neg':'' ?>"><?= $r['kind']==='driver' ? money($r['deduction'],false) : '—' ?></td>
          <td class="<?= $r['kind']==='topup'?'pos':'' ?>"><?= $r['kind']==='topup' ? money($r['amount'],false) : '—' ?></td>
          <td style="font-weight:700"><?= money($r['running'],false) ?></td>
          <td style="white-space:nowrap">
            <?php if($r['kind']==='topup'): ?>
              <a class="edit" href="<?= url('custody/topup/'.$r['id'].'/edit') ?>" title="تعديل">✏️</a>
              <form method="post" action="<?= url('custody/topup/'.$r['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف هذه الإضافة؟" style="display:inline">
                <?= csrfField() ?>
                <button class="edit" type="submit" title="حذف" style="background:none;border:0;cursor:pointer">🗑️</button>
              </form>
            <?php else: ?>
              <a class="edit" href="<?= url('bawalis/'.$r['id']) ?>" title="فتح البوليصة">🔎</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
