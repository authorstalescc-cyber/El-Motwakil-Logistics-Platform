<?php
/**
 * خزنة الشركة (v66 · موسّعة في v67) — الخزنة الأكبر اللي بتغذّي خزنة المكتب.
 *
 * ترتيب الشاشة بيتبع سؤال المدير بالترتيب:
 *   ① الفلوس فين دلوقتي؟   → الهيرو + شريط توزيع الكاش
 *   ② محتاج أعمل إيه؟       → شريط التصرّف (بيظهر بس لما يلزم)
 *   ③ أرقام الفترة          → داخل · خارج · اليوم · المكتب
 *   ④ المصروفات             → الميزانية + راح فين
 *   ⑤ سجّل الحركة           → أزرار نوع + حقول مشروطة
 *   ⑥ الاتجاه               → آخر ١٤ يوم
 *   ⑦ اللي حصل              → السجل مجمّع بالأيام
 *
 * ⚠️ **ممنوع رقم أصمّ**: كل رقم بيفتح بنوده — التفكيك جاهز في الصفحة
 *    (`#csBreak`) لأنه أرقام محسوبة أصلاً، والباقي بيتجاب عند الضغط.
 * ⚠️ **صفر جملة تعليمية** (قانون التصميم بند ١) — اللي فاضل تحذيرات وظيفية
 *    في ≤٦ كلمات بس.
 * ⚠️ **صفر `<style>` محلي** — كل الكلاسات `.cs-*` في `app.css` عشان النافذة
 *    تستخدم نفس `.cs-seg`/`.cs-dot`.
 * ⚠️ **أيقونات النوع من `CompanySafe::icon()`** — خريطة محلية هنا كانت هتتفرّع
 *    عن النافذة وعن كشف الطباعة مع أول نوع جديد.
 */
use App\Core\FormState;
use App\Models\CompanySafe;

$st      = $entryState ?? ['token' => FormState::token(), 'old' => [], 'errors' => [], 'back' => 'company-safe'];
$old     = $st['old'];
$errors  = $st['errors'];
$retPath = current_app_path() ?: 'company-safe';
$cd      = static fn (string $what): string => url('company-safe/drill/' . $what);
$labels  = CompanySafe::labels();
$editing = !empty($editRow);

/* في وضع التعديل الحقول بتتعبّى من الصف المحفوظ، ولو الحفظ فشل من `old`
   (اللي بترجع من `FormState`) — فالمالك ما يفقدش اللي كتبه. */
$val = static function (string $k, $fallback = '') use ($old, $editRow) {
    if (array_key_exists($k, $old)) { return $old[$k]; }
    return $editRow[$k] ?? $fallback;
};
?>
<?php if (!$ready): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('info', 15) ?> خزنة الشركة مش مفعّلة — شغّل <code>php bin/migrate_v66.php</code>.
  </div>
<?php else: ?>

<?php /* ═══ ① الهيرو — الرصيد وتوزيع كل الكاش في نظرة ═══
         ⚠️ كل جزء في الشريط **زرار** بيفتح تفاصيله، فالرسم نقطة دخول مش صورة. */
$grand  = max((float) $wallets['grand'], 0.0001);
$pc     = static fn (float $v): float => round(max($v, 0) / $grand * 100, 2);
?>
<section class="cs-hero">
  <div class="cs-hero-top">
    <div class="cs-hero-main">
      <span class="cs-eyebrow"><?= icon('home', 14) ?> خزنة الشركة</span>
      <div class="cs-amount">
        <b class="<?= $balance < 0 ? 'neg' : '' ?>"><?= money0($balance, false) ?></b>
        <span><?= e(App\Core\App::config('currency', 'ج.م')) ?></span>
      </div>
    </div>
    <div class="cs-hero-acts">
      <button type="button" class="cs-act go" data-cs-kind="to_office">
        <?= icon('landmark', 15) ?> حوّل للمكتب
      </button>
      <button type="button" class="cs-act" data-cs-kind="deposit">
        <?= icon('plus', 15) ?> إيداع
      </button>
      <button type="button" class="cs-act" data-cs-kind="expense">
        <?= icon('trending-down', 15) ?> مصروف
      </button>
      <a class="cs-act" href="<?= url('company-safe/statement') ?>" target="_blank" rel="noopener">
        <?= icon('file-text', 15) ?> كشف حساب
      </a>
    </div>
  </div>

  <div class="cs-split">
    <div class="cs-bar">
      <?php if ($balance > 0): ?>
        <button type="button" class="cs-seg co" style="width:<?= $pc((float) $balance) ?>%"
                data-drill="#csBreak" data-drill-title="رصيد خزنة الشركة"></button>
      <?php endif; ?>
      <?php if ($wallets['drawer'] > 0): ?>
        <button type="button" class="cs-seg dr" style="width:<?= $pc((float) $wallets['drawer']) ?>%"
                data-drill="<?= e($cd('wallets')) ?>" data-drill-title="كل الكاش برّه البنك"></button>
      <?php endif; ?>
      <?php if ($wallets['reps_total'] > 0): ?>
        <button type="button" class="cs-seg rp" style="width:<?= $pc((float) $wallets['reps_total']) ?>%"
                data-drill="<?= e($cd('wallets')) ?>" data-drill-title="كل الكاش برّه البنك"></button>
      <?php endif; ?>
    </div>
    <div class="cs-legend">
      <button type="button" class="cs-leg" data-drill="#csBreak" data-drill-title="رصيد خزنة الشركة">
        <span class="cs-dot co"></span> الشركة <b><?= money0($balance, false) ?></b>
      </button>
      <button type="button" class="cs-leg" data-drill="<?= e($cd('wallets')) ?>" data-drill-title="كل الكاش برّه البنك">
        <span class="cs-dot dr"></span> الدرج <b><?= money0($wallets['drawer'], false) ?></b>
      </button>
      <button type="button" class="cs-leg" data-drill="<?= e($cd('wallets')) ?>" data-drill-title="كل الكاش برّه البنك">
        <span class="cs-dot rp"></span> المناديب <b><?= money0($wallets['reps_total'], false) ?></b>
        <em><?= intf(count($wallets['reps'])) ?></em>
      </button>
      <span class="cs-grand">كل الكاش <b><?= money0($wallets['grand'], false) ?></b></span>
    </div>
  </div>
</section>

<?php /* ═══ ② شريط التصرّف — بيظهر بس لما يلزم ═══
         ⚠️ تحذير وظيفي مش شرح: المدير مايعرفش إن الدرج فاضي غير لما يفتح
            شاشة تانية، والقرار (يحوّل) لازم يبقى في إيده هنا. */ ?>
<?php if ($balance < -0.005): ?>
  <div class="cs-alert bad">
    <?= icon('alert', 17) ?> رصيد خزنة الشركة بالسالب
    <span class="spacer"></span>
    <button type="button" class="btn small" data-cs-kind="deposit"><?= icon('plus', 14) ?> إيداع</button>
  </div>
<?php elseif ($officeBal < 0.005): ?>
  <div class="cs-alert">
    <?= icon('alert', 17) ?> درج المكتب فاضي
    <span class="spacer"></span>
    <button type="button" class="btn small gold" data-cs-kind="to_office"><?= icon('landmark', 14) ?> حوّل للمكتب</button>
  </div>
<?php elseif ($lowOffice): ?>
  <div class="cs-alert">
    <?= icon('alert', 17) ?> درج المكتب قرب يخلص — <?= money($officeBal, false) ?>
    <span class="spacer"></span>
    <button type="button" class="btn small gold" data-cs-kind="to_office"><?= icon('landmark', 14) ?> حوّل للمكتب</button>
  </div>
<?php endif; ?>
<?php if ($budget['over']): ?>
  <div class="cs-alert bad">
    <?= icon('alert', 17) ?> عدّيت ميزانية الشهر بـ<?= money(abs($budget['left']), false) ?>
    <span class="spacer"></span>
    <button type="button" class="btn small" data-drill="<?= e($cd('budget')) ?>"
            data-drill-title="مصروفات الشهر"><?= icon('search', 14) ?> شوف البنود</button>
  </div>
<?php endif; ?>

<?php /* تفكيك الرصيد — جاهز في الصفحة (الأرقام محسوبة أصلاً، مفيش داعي لطلب تاني).
         ⚠️ الأقسام بتتولّد من `CompanySafe::KINDS` + `isIn()` — قايمة مكتوبة
            بالإيد كانت هتسيب أي نوع جديد برّه التفكيك بلا أي خطأ ظاهر. */ ?>
<div id="csBreak" hidden>
  <?php partial('drill', [
    'how'   => 'رصيد خزنة الشركة = كل الداخل − كل الخارج. رصيد جارٍ بلا إقفال يومي.',
    'total' => ['label' => 'رصيد خزنة الشركة', 'value' => money($balance, false)],
    'more'  => ['label' => 'كل الحركات', 'url' => url('company-safe')],
    'body'  => function () use ($breakdown, $labels, $cd) {
      foreach (CompanySafe::KINDS as $k) {
        if (abs((float) ($breakdown[$k] ?? 0)) < 0.005) { continue; }
        $in = CompanySafe::isIn($k);
        partial('row_item', [
          'title'  => $labels[$k], 'icon' => CompanySafe::icon($k),
          'drill'  => $cd($k === 'to_office' ? 'to_office' : ($k === 'expense' ? 'expense' : ($in ? 'in' : 'out'))),
          'amount' => ($in ? '+ ' : '- ') . money((float) $breakdown[$k], false),
          'amount_tone' => $in ? 'pos' : 'ex',
        ]);
      }
    },
  ]); ?>
</div>

<?php /* ═══ ③ أرقام الفترة ═══ */ ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($dayTotals, $officeBal, $cd, $monthIn, $monthOut) { ?>
  <?php partial('stat', [
    'label' => 'داخل الشهر', 'value' => intf($monthIn),
    'icon'  => 'wallet', 'tone' => 'green', 'drill' => $cd('in'), 'title' => 'الكاش الداخل',
  ]); ?>
  <?php partial('stat', [
    'label' => 'خارج الشهر', 'value' => intf($monthOut),
    'icon'  => 'trending-down', 'tone' => 'red', 'drill' => $cd('out'), 'title' => 'الكاش الخارج',
  ]); ?>
  <?php partial('stat', [
    'label' => 'حركة اليوم', 'value' => intf($dayTotals['in'] - $dayTotals['out']),
    'unit'  => intf($dayTotals['count']) . ' حركة',
    'icon'  => 'calendar', 'tone' => 'navy', 'drill' => $cd('day'), 'title' => 'حركة اليوم',
  ]); ?>
  <?php partial('stat', [
    'label' => 'خزنة المكتب', 'value' => intf($officeBal),
    'icon'  => 'landmark', 'tone' => $officeBal < 0.005 ? 'red' : 'teal',
    'url'   => url('custody'), 'title' => 'افتح خزنة المكتب',
  ]); ?>
<?php }]); ?>

<?php /* ═══ ④ المصروفات — الميزانية وراح فين ═══ */ ?>
<?php partial('panel', [
  'title' => 'مصروفات الشهر',
  'icon'  => 'trending-down',
  'id'    => 'budget',
  'class' => 'mb',
  'badge' => $budget['spent'] > 0 ? ['text' => money0($budget['spent'], false),
                                     'tone' => $budget['over'] ? 'red' : 'navy'] : null,
  'body'  => function () use ($budget, $catRows, $cd, $st, $retPath) { ?>
  <?php if ($budget['on']): ?>
    <?php $w = min(100, max(0, $budget['pct'])); ?>
    <button type="button" class="cs-budget<?= $budget['over'] ? ' over' : '' ?>"
            data-drill="<?= e($cd('budget')) ?>" data-drill-title="مصروفات الشهر">
      <span class="cs-budget-bar"><span style="width:<?= $w ?>%"></span></span>
      <span class="cs-budget-txt">
        <b><?= money0($budget['spent'], false) ?></b> من <?= money0($budget['budget'], false) ?>
        <em><?= intf($budget['pct']) ?>%</em>
        <?php if ($budget['over']): ?><i class="neg">زيادة <?= money0(abs($budget['left']), false) ?></i>
        <?php else: ?><i>فاضل <?= money0($budget['left'], false) ?></i><?php endif; ?>
      </span>
    </button>
  <?php endif; ?>

  <?php if ($catRows): ?>
    <div class="cs-cats">
      <?php foreach ($catRows as $c): ?>
        <a class="cs-cat" href="<?= url('company-safe') ?>?cat=<?= e($c['key']) ?>"
           title="<?= e($c['label']) ?> — <?= intf($c['count']) ?> بند">
          <span><?= e($c['label']) ?></span>
          <b><?= money0($c['total'], false) ?></b>
          <em><?= $c['pct'] ?>%</em>
        </a>
      <?php endforeach; ?>
      <button type="button" class="cs-cat more" data-drill="<?= e($cd('parties')) ?>"
              data-drill-title="صرفنا لمين الشهر ده"><?= icon('users', 14) ?> لمين</button>
    </div>
  <?php elseif (!$budget['on']): ?>
    <?php partial('empty', ['emoji' => '💸', 'title' => 'مفيش مصروفات الشهر ده']); ?>
  <?php endif; ?>

  <?php /* سقف الشهر — إعداد واحد. الفاضي = مفيش سقف = مفيش تنبيه. */ ?>
  <form method="post" action="<?= url('company-safe/budget') ?>" class="p5-row end cs-budget-form">
    <?= csrfField() ?>
    <?= formStateFields($st['token'], $retPath) ?>
    <div class="field flush w160">
      <label>سقف المصروف الشهري</label>
      <input type="number" step="0.01" min="0" name="budget"
             value="<?= $budget['budget'] > 0 ? e((string) $budget['budget']) : '' ?>" placeholder="بلا سقف">
    </div>
    <button class="btn" type="submit"><?= icon('check', 15) ?> احفظ السقف</button>
  </form>
<?php }]); ?>

<?php /* ═══ ⑤ سجّل حركة ═══
         ⚠️ **أزرار نوع بدل قائمة منسدلة**: التحويل للمكتب هو الإجراء الأكتر
            تكرارًا، والقايمة كانت بتخلّيه ٣ ضغطات. والأزرار `radio` جوهرًا
            فالفورم شغّال بلا جافاسكريبت.
         ⚠️ **في وضع التعديل النوع مقفول** — تغييره معناه نقل فلوس من محفظة
            لمحفظة، وده حذف + إضافة مش تعديل (الموديل بيرفضه صراحةً). */ ?>
<?php partial('panel', [
  'title' => $editing ? 'عدّل الحركة' : 'سجّل حركة',
  'icon'  => $editing ? 'edit' : 'plus',
  'id'    => 'entry',
  'class' => 'mb' . ($editing ? ' p5-edge-gold-r' : ''),
  'body'  => function () use ($errors, $st, $val, $day, $labels, $sources, $lastClosed,
                              $editing, $editRow, $cats, $reps) { ?>
  <?= formErrorSummary($errors) ?>
  <form class="js-dirty-form cs-form" method="post" enctype="multipart/form-data" id="csForm"
        action="<?= url($editing ? 'company-safe/' . (int) $editRow['id'] . '/update' : 'company-safe') ?>">
    <?= csrfField() ?>
    <?= formStateFields($st['token'], $st['back']) ?>

    <?php if ($editing): ?>
      <div class="cs-editing">
        <?= icon('edit', 15) ?> <?= e($labels[$editRow['kind']] ?? $editRow['kind']) ?>
        <span class="spacer"></span>
        <a class="btn small ghost" href="<?= url('company-safe') ?>"><?= icon('x', 14) ?> إلغاء</a>
      </div>
      <input type="hidden" name="kind" value="<?= e((string) $editRow['kind']) ?>">
    <?php else: ?>
      <div class="cs-kinds">
        <?php
        $sel = $val('kind', 'to_office');
        foreach (CompanySafe::MANUAL_KINDS as $k): ?>
          <?php /* ⚠️ **بلا `required`**: واحد منهم `checked` دايمًا، و`app.js`
                   بيحقن نجمة «مطلوب» على كل حقل `required` — فكانت بتطلع
                   نجوم حمرا على الأزرار وتشوّه الشريط بلا فايدة. */ ?>
          <label class="cs-kind">
            <input type="radio" name="kind" value="<?= e($k) ?>" <?= $sel === $k ? 'checked' : '' ?>>
            <?= icon(CompanySafe::icon($k), 16) ?> <?= e($labels[$k]) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <?= fieldError('kind', $errors) ?>
    <?php endif; ?>

    <?php /* الحقول المشروطة — `data-cs-for` بيقول النوع اللي بتخصّه.
             ⚠️ الإخفاء من الجافاسكريبت بس؛ بدونه كلها ظاهرة والخادم هو الحكم. */ ?>
    <div class="p5-row end cs-cond" data-cs-for="expense"
         <?= $editing && $editRow['kind'] !== 'expense' ? 'hidden' : '' ?>>
      <div class="field flush w160">
        <label>فئة المصروف</label>
        <select name="category">
          <?php $cSel = (string) $val('category', 'other'); ?>
          <?php foreach ($cats as $ck => $cl): ?>
            <option value="<?= e($ck) ?>" <?= $cSel === $ck ? 'selected' : '' ?>><?= e($cl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="p5-row end cs-cond" data-cs-for="to_visa from_visa"
         <?= $editing && !in_array($editRow['kind'], CompanySafe::VISA_KINDS, true) ? 'hidden' : '' ?>>
      <div class="field flush w160">
        <label>كارت الفيزا</label>
        <?php /* ⚠️ `visa_card_select()` المشتركة — المصدر الوحيد لأي قايمة كروت
                 في النظام. نسخة محلية كانت هتتفرّع مع أول تعديل. */ ?>
        <?= visa_card_select((int) $val('visa_card_id', 0)) ?>
        <?= fieldError('visa_card_id', $errors) ?>
      </div>
    </div>

    <div class="p5-row end cs-cond" data-cs-for="to_rep"
         <?= $editing && $editRow['kind'] !== 'to_rep' ? 'hidden' : '' ?>>
      <div class="field flush w160">
        <label>المندوب</label>
        <select name="rep_id">
          <option value="">— اختر المندوب —</option>
          <?php $rSel = (int) $val('rep_id', 0); ?>
          <?php foreach ($reps as $rid => $rname): ?>
            <option value="<?= (int) $rid ?>" <?= $rSel === (int) $rid ? 'selected' : '' ?>><?= e((string) $rname) ?></option>
          <?php endforeach; ?>
        </select>
        <?= fieldError('rep_id', $errors) ?>
      </div>
    </div>

    <div class="p5-row end">
      <div class="field flush w160">
        <label>التاريخ</label>
        <input type="date" name="entry_date" value="<?= e($val('entry_date', $day)) ?>"<?= fieldErrorAttrs('entry_date', $errors) ?>>
        <?= fieldError('entry_date', $errors) ?>
      </div>
      <div class="field flush w160">
        <label>الجهة</label>
        <input type="text" name="source" maxlength="180" value="<?= e((string) $val('source')) ?>" list="csSources">
        <datalist id="csSources">
          <?php foreach ($sources as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
        </datalist>
      </div>
      <div class="field flush grow180">
        <label>ملاحظة</label>
        <input type="text" name="note" maxlength="255" value="<?= e((string) $val('note')) ?>">
      </div>
      <div class="field flush w160">
        <label>المبلغ</label>
        <input type="number" step="0.01" min="0.01" name="amount" id="csAmount"
               value="<?= e((string) $val('amount')) ?>" placeholder="0" required<?= fieldErrorAttrs('amount', $errors) ?>>
        <?= fieldError('amount', $errors) ?>
      </div>
      <button class="btn gold" type="submit" data-primary-submit>
        <?= icon('check', 15) ?> <?= $editing ? 'احفظ' : 'سجّل' ?>
      </button>
    </div>

    <div class="p5-row end">
      <div class="field flush grow180">
        <label>إيصال (صورة أو PDF)</label>
        <input type="file" name="receipt" accept="image/*,application/pdf" capture="environment">
        <?= fieldError('receipt', $errors) ?>
      </div>
      <?php if ($editing && !empty($editRow['attachment'])): ?>
        <a class="btn small ghost" target="_blank" rel="noopener"
           href="<?= url('attachments/company-safe/' . (int) $editRow['id']) ?>">
          <?= icon('eye', 14) ?> الإيصال الحالي
        </a>
      <?php endif; ?>
    </div>

    <?php /* مبالغ سريعة — المدير بيحوّل مبالغ مستديرة. تحسين تدريجي: بدون
             جافاسكريبت الخانة شغّالة زي ما هي. */ ?>
    <div class="cs-quick">
      <?php foreach ([5000, 10000, 20000, 50000, 100000] as $q): ?>
        <button type="button" class="cs-chip" data-cs-amount="<?= $q ?>"><?= intf($q) ?></button>
      <?php endforeach; ?>
      <?php if ($lastClosed): ?>
        <span class="cs-locked"><?= icon('lock', 13) ?> متقفل حتى <?= dfmt($lastClosed) ?></span>
      <?php endif; ?>
    </div>
  </form>
<?php }]); ?>

<?php /* ═══ ⑥ الاتجاه ═══ */ ?>
<?php if ($trend): ?>
  <?php partial('panel', [
    'title' => 'حركة آخر ١٤ يوم',
    'icon'  => 'trending-up',
    'class' => 'mb',
    'body'  => function () use ($trend) {
      partial('mini_bars', ['bars' => $trend, 'tone' => 'gold', 'height' => 110]);
    },
  ]); ?>
<?php endif; ?>

<?php /* ═══ ⑦ السجل ═══ */ ?>
<div class="page-actions">
  <form method="get" action="<?= url('company-safe') ?>" class="p5-row end">
    <div class="field flush w160">
      <label>النوع</label>
      <select name="kind">
        <option value="">الكل</option>
        <?php foreach ($labels as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= ($filters['kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field flush w160">
      <label>فئة المصروف</label>
      <select name="cat">
        <option value="">الكل</option>
        <?php foreach ($cats as $ck => $cl): ?>
          <option value="<?= e($ck) ?>" <?= ($filters['cat'] ?? '') === $ck ? 'selected' : '' ?>><?= e($cl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field flush w150"><label>من</label>
      <input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>"></div>
    <div class="field flush w150"><label>إلى</label>
      <input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>"></div>
    <div class="field flush grow180"><label>بحث</label>
      <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>"></div>
    <button class="btn" type="submit"><?= icon('filter', 15) ?> فلترة</button>
    <a class="btn ghost" target="_blank" rel="noopener"
       href="<?= url('company-safe/statement') ?>?from=<?= e($filters['from'] ?? '') ?>&to=<?= e($filters['to'] ?? '') ?>">
      <?= icon('file-text', 15) ?> اطبع كشف
    </a>
  </form>
</div>

<?php if (array_filter($filters)): ?>
  <div class="flt-chips">
    <?php
    $rm = static function (array $f, string $drop): string {
      unset($f[$drop]);
      $f = array_filter($f);
      return url('company-safe') . ($f ? '?' . http_build_query($f) : '');
    };
    $chipLabel = ['kind' => 'النوع', 'cat' => 'الفئة', 'from' => 'من', 'to' => 'إلى', 'q' => 'بحث'];
    foreach ($filters as $k => $v):
      if ($v === '' || $v === null) { continue; }
      $show = match ($k) {
        'kind'  => $labels[$v] ?? $v,
        'cat'   => CompanySafe::catLabel((string) $v),
        'q'     => $v,
        default => dfmt((string) $v),
      };
    ?>
      <span class="flt-chip"><?= e($chipLabel[$k] ?? $k) ?>: <b><?= e($show) ?></b>
        <a href="<?= e($rm($filters, $k)) ?>" title="شيل">✕</a></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php partial('panel', [
  'title'  => 'سجل الحركات',
  'icon'   => 'layers',
  'badge'  => $rows ? ['text' => intf($total), 'tone' => 'navy'] : null,
  'scroll' => true,
  'body'   => function () use ($rows, $retPath) {
    if (!$rows) {
      partial('empty', ['emoji' => '🏦', 'title' => 'مفيش حركات']);
      return;
    }
    /* ⚠️ **مجمّع بالأيام**: المدير بيقرا «إيه اللي حصل امبارح» مش «الصف رقم ٤٠».
       الترويسة بتحمل صافي اليوم فبيقارن الأيام ببعض بلا ما يفتح حاجة.
       ⚠️ الإجماليات بتتحسب **مرّة واحدة قبل الحلقة** — حسابها جوّه الحلقة
       كان O(n²) (٢٠٠ صف = ٤٠ ألف عملية على كل فتحة). */
    $dayNet = []; $dayCnt = [];
    foreach ($rows as $x) {
      $k = (string) $x['entry_date'];
      $dayNet[$k] = ($dayNet[$k] ?? 0) + (float) $x['signed'];
      $dayCnt[$k] = ($dayCnt[$k] ?? 0) + 1;
    }
    $curDay = null;
    foreach ($rows as $r) {
      $d = (string) $r['entry_date'];
      if ($d !== $curDay) {
        $curDay = $d;
        $net = round($dayNet[$d], 2);
        ?>
        <div class="cs-day">
          <?= icon('calendar', 14) ?> <?= e(dfmt($d)) ?>
          <span class="spacer"></span>
          <em><?= intf($dayCnt[$d]) ?></em>
          <b class="<?= $net < 0 ? 'neg' : 'pos' ?>"><?= ($net >= 0 ? '+ ' : '- ') . money0(abs($net), false) ?></b>
        </div>
        <?php
      }
      $isIn = (bool) $r['is_in'];
      $meta = [];
      if (!empty($r['cat_label'])) { $meta[] = ['icon' => 'tag', 'text' => (string) $r['cat_label']]; }
      if ($r['source']) { $meta[] = ['icon' => 'user', 'text' => (string) $r['source']]; }
      if ($r['note'])   { $meta[] = ['icon' => 'file-text', 'text' => (string) $r['note']]; }
      if (!empty($r['custody_id']))      { $meta[] = ['icon' => 'landmark', 'text' => 'مسّت خزنة المكتب']; }
      if (!empty($r['visa_deposit_id'])) { $meta[] = ['icon' => 'credit-card', 'text' => 'مسّت كارت الفيزا']; }
      if (!empty($r['rep_deposit_id']))  { $meta[] = ['icon' => 'truck', 'text' => 'نزلت لمندوب']; }
      if (!empty($r['attachment']))      { $meta[] = ['icon' => 'camera', 'text' => 'فيه إيصال']; }
      $meta[] = ['icon' => 'home', 'text' => 'الرصيد ' . money((float) $r['running'], false)];
      partial('row_item', [
        'title'  => (string) $r['label'],
        'icon'   => (string) $r['icon'],
        'meta'   => $meta,
        'amount' => ($isIn ? '+ ' : '- ') . money((float) $r['amount'], false),
        'amount_tone' => $isIn ? 'pos' : 'ex',
        'extra'  => empty($r['editable']) ? null : function () use ($r, $retPath) { ?>
          <?php if (!empty($r['attachment'])): ?>
            <a class="edit" target="_blank" rel="noopener" title="الإيصال"
               href="<?= url('attachments/company-safe/' . (int) $r['id']) ?>"><?= icon('eye', 13) ?></a>
          <?php endif; ?>
          <a class="edit" title="تعديل"
             href="<?= url('company-safe') ?>?edit=<?= (int) $r['id'] ?>#entry"><?= icon('edit', 13) ?></a>
          <form method="post" action="<?= url('company-safe/' . (int) $r['id'] . '/delete') ?>"
                class="confirm-delete" data-confirm="حذف الحركة دي؟<?= !empty($r['custody_id']) ? ' هتتشال من خزنة المكتب كمان.' : (!empty($r['visa_deposit_id']) ? ' هتتشال من كارت الفيزا كمان.' : (!empty($r['rep_deposit_id']) ? ' هتتشال من عهدة المندوب كمان.' : '')) ?>">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), $retPath) ?>
            <button class="edit" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
          </form>
        <?php },
      ]);
    }
  },
]); ?>

<?php partial('count_note', ['shown' => count($rows), 'total' => $total, 'unit' => 'حركة']); ?>

<?php endif; ?>
