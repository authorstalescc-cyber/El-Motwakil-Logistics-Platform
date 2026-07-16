<?php
/** استكمال بيانات الإيصالات — السعر الفعلي للعميل + رقم الإيصال (ليوم العمل المحدَّد فقط) */
use App\Models\Permit;
$count = count($rows);
?>
<style>
.rc-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:16px}
.rc-head .rc-day{background:var(--navy);color:#fff;border-radius:12px;padding:10px 16px;font-weight:700}
.rc-head .rc-day b{color:var(--gold)}
.rc-note{background:#fdf3e0;border:1px solid #f0dca6;color:#7a5e12;border-radius:12px;padding:10px 14px;font-size:.9rem;flex:1 1 320px}
.rc-tbl input{width:100%;max-width:160px}
.rc-tbl .permit-cell b{display:block}
.rc-tbl .permit-cell small{color:var(--grey);display:block;font-size:.78rem}
.rc-cash{font-weight:700;white-space:nowrap}
.rc-visa{font-size:.72rem;color:#5b2f8f;background:#efe7fb;border-radius:999px;padding:1px 7px;margin-inline-start:6px}
.rc-actionbar{position:sticky;bottom:0;background:#fff;border-top:1px solid var(--line);
  padding:12px 4px;display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:4px}
.rc-empty{text-align:center;padding:48px 16px;color:var(--grey)}
.rc-empty .em{font-size:2.4rem;display:block;margin-bottom:8px}
</style>

<div class="rc-head">
  <div class="rc-day">إيصالات يوم <b><?= e($day) ?></b> — <?= intf($count) ?> إيصال ناقص</div>
  <div class="rc-note">
    استكمل هنا <b>السعر الفعلي للعميل</b> و<b>رقم الإيصال</b> للإيصالات اللي اتسجلت ناقصة.
    ده <b>مبيمسّش</b> المبلغ النقدي ولا التقفيل اليومي — بس بيكمّل البيانات المفقودة.
  </div>
  <div class="spacer"></div>
  <a class="btn ghost sm" href="<?= url('expenses') ?>">← رجوع للمصروفات</a>
</div>

<?php if ($count === 0): ?>
  <div class="card">
    <div class="rc-empty">
      <span class="em">✅</span>
      مفيش إيصالات ناقصة في يوم العمل ده (<?= e($day) ?>).<br>
      كل الإيصالات ليها سعر فعلي ورقم — أو غيّر يوم العمل من الأعلى لو عايز يوم تاني.
    </div>
  </div>
<?php else: ?>
  <form method="post" action="<?= url('expenses/receipts') ?>" class="no-confirm">
    <?= csrfField() ?>
    <div class="card">
      <div class="table-wrap">
        <table class="data rc-tbl">
          <thead>
            <tr>
              <th class="g-identity">م</th>
              <th class="g-identity">الإذن / الشهادة</th>
              <th class="g-identity">الحركة</th>
              <th class="g-identity">البيان</th>
              <th class="g-identity">الكمية</th>
              <th class="g-expense">المبلغ النقدي</th>
              <th class="g-revenue">الفعلي للوحدة <small>(للعميل)</small></th>
              <th class="g-identity">رقم الإيصال</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $i => $rrow):
              $qty  = max(1, (int)$rrow['qty']);
              $unit = $rrow['actual_amount'] !== null ? rtrim(rtrim(number_format((float)$rrow['actual_amount'] / $qty, 2, '.', ''), '0'), '.') : '';
              $noLbl = Permit::noLabel($rrow['permit_type']);
            ?>
            <tr>
              <td><?= intf($i + 1) ?><input type="hidden" name="id[]" value="<?= (int)$rrow['id'] ?>"></td>
              <td class="permit-cell">
                <b><?= e($noLbl) ?>: <?= e($rrow['permit_no'] ?: '—') ?></b>
                <small>الشركة: <?= e($rrow['company'] ?: '—') ?></small>
                <small>العميل: <?= e($rrow['client'] ?: '—') ?></small>
              </td>
              <td><?= $rrow['movement_seq'] !== null ? 'حركة '.intf($rrow['movement_seq']) : '—' ?></td>
              <td><?= e($rrow['note'] ?: 'إيصال') ?></td>
              <td><?= intf($qty) ?></td>
              <td class="rc-cash">
                <?= money((float)$rrow['amount'], false) ?>
                <?php if (!empty($rrow['is_visa'])): ?><span class="rc-visa">فيزا</span><?php endif; ?>
              </td>
              <td>
                <input type="number" name="actual_unit[]" value="<?= e($unit) ?>"
                       min="0" step="0.01" placeholder="<?= $rrow['actual_amount'] !== null ? 'موجود' : 'الفعلي…' ?>">
              </td>
              <td>
                <input type="text" name="ref_no[]" value="<?= e($rrow['ref_no'] ?? '') ?>"
                       maxlength="120" placeholder="<?= ($rrow['ref_no'] ?? '') !== '' ? 'موجود' : 'رقم الإيصال…' ?>">
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="rc-actionbar">
        <span style="color:var(--grey);font-size:.85rem">اكتب اللي ناقص بس — الخانات الفاضية هتفضل زي ما هي.</span>
        <button type="submit" class="btn gold">💾 حفظ الاستكمال</button>
      </div>
    </div>
  </form>
<?php endif; ?>
