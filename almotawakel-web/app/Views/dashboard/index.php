<?php
/**
 * لوحة التحكم — أُعيد بناؤها بالكامل (2026-08-17) على أسئلة المالكين.
 *
 * **ليه اتعادت تاني؟** بناء المرحلة ١١ كان صحيحًا تشغيليًّا لكن المالكين
 * (أيمن وأحمد) قالوا حرفيًّا: «معلومات كتير ومبعثرة — مش عارف أركّز»
 * و«الأرقام نفسها مش مفهومة». التشخيص المقيس:
 *
 *   ① **١٢ لوح ورا بعض** وخمسة منهم بيحكوا نفس اليوم بصيغ مختلفة (اتحرّك
 *      عليها · اتفتحت · عهدة المناديب · فلوس اليوم · آخر ما اتسجّل).
 *   ② **الشاشة كانت بتدّي أرقامًا خام وتسيب المالك يحسب.** صف المندوب كان
 *      بيقول «نزل ٥٠٠٠ · صرف ٤٢٠٠» والمالك لازم يطرح عشان يعرف إن فيه ٨٠٠
 *      لسه برّه. دلوقتي الشاشة بتقول **الحكم**: «لسه معاه ٨٠٠».
 *   ③ **الخزنة كانت رقمًا واحدًا أصمّ.** سؤال المالك «دخلها كام ونزل كام
 *      للمكتب» ما كانش ليه إجابة على الشاشة رغم إن `Closing::dayView()`
 *      بيحسبها من زمان — فبقت **سطر معادلة** كل جزء فيه يتضغط.
 *   ④ **الإدخال السريع اتشال** — أداة موظف إدخال (٢٠٠ سطر JS) كانت قاعدة
 *      في لوحة إدارة.
 *
 * البناء الجديد — كل قسم بيجاوب سؤالًا واحدًا بالترتيب اللي المالك بيسأل بيه:
 *   ① اليوم        — إحنا في أنهي يوم وحالته إيه
 *   ② محتاج منك    — أعمل إيه دلوقتي
 *   ③ الخزنة       — فتحت بكام · دخلها كام · نزل للمكتب كام · قفلت بكام
 *   ④ حركة اليوم   — عربيات خرجت · إيصالات دخلت · أذونات اتفتحت · محتاجة تتقفل
 *   ⑤ المناديب     — مين سوّى ومين لسه ماسك
 *   ⑥ الشغل الجاري — ناقصها إيصالات · جاهز للفوترة · فلوس اليوم · ملاحظات
 *
 * ⚠️ **ممنوع رقم أصمّ** (قاعدة المالك الدائمة): كل رقم ليه `drill` بيجيب
 *    بنوده من `DashboardController::drill` **عند الضغط بس**.
 *
 * ⚠️ **كل الأرقام عن يوم العمل المختار** — ولا رقم تراكمي واحد. التراكمي
 *    مكانه `/rep-custody` و`/company-safe` و`/clients`.
 */
use App\Core\App;
use App\Models\PermitReadiness;

require __DIR__ . '/_rows.php';   // مُصيّرا الصفوف — مشتركان مع نوافذ التفاصيل

$cur = App::setting('currency', 'ج.م');
$drill = static fn (string $what): string => url('dashboard/drill/' . $what);
?>
<div class="dash2">

<?php /* ═══════════ ① اليوم — أول سؤال في دماغ المالك ═══════════ */ ?>
<?php partial('day_bar', [
  'day'    => $date,
  'locked' => $locked,
  'facts'  => [[
    'label' => 'خزنة المكتب',
    'value' => money0($custody),
    'tone'  => $custody < 0 ? 'neg' : '',
  ]],
]); ?>

<?php /* ═══════════ ② محتاج منك — «أعمل إيه دلوقتي؟» ═══════════
   المصدر هو **نفس** مجمّع الإشعارات بتاع الجرس وشارات القائمة
   (App\Models\Notification) فمستحيل الأرقام تختلف بين التلاتة. */ ?>
<?php $todo = \App\Models\Notification::feed(); ?>
<div class="d-todo mb<?= $todo['count'] ? '' : ' clear' ?>">
  <div class="d-todo-head">
    <?= icon('check-circle', 18) ?>
    <b>محتاج منك</b>
    <?php if ($todo['count']): ?><span class="d-badge red"><?= intf($todo['count']) ?></span><?php endif; ?>
  </div>
  <?php if (!$todo['count']): ?>
    <div class="d-todo-empty"><?= icon('check', 22) ?><b>كله تمام النهاردة</b><span>مفيش حاجة مستنية منك.</span></div>
  <?php else: ?>
    <div class="d-todo-list">
      <?php foreach ($todo['items'] as $t): ?>
        <a class="d-todo-row <?= e($t['kind']) ?>" href="<?= e($t['url']) ?>">
          <span class="d-todo-ic"><?= icon($t['icon'], 18) ?></span>
          <span class="d-todo-tx"><b><?= e($t['title']) ?></b><span><?= e($t['body']) ?></span></span>
          <span class="d-todo-go"><?= icon('chevron', 16) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php /* ═══════════ ③ الخزنة — سطر معادلة مقروء ═══════════

   طلب المالك بالحرف: «يهمهم يعرفوا رصيد الخزنة دخلها كام ونزل كام للمكتب».

   ⚠️ **معادلة مقفولة مش أرقام متجاورة:** `فتح + دخل − نزل للمكتب − مصروفات
      − عهدة سواقين = قفل`. الترتيب ده معناه إن أي رقم غلط بيبان فورًا لأن
      الطرفين مش هيتساووا — بعكس أربع كروت متجاورة محدش يعرف علاقتها ببعض.

   ⚠️ **`office` جزء من `expenses` مش رقم زيادة عليها** — `Closing::dayView()`
      بتحطّه جوّه `expenses` (عبر `sumOfficeDeposits`)، فالشاشة بتطرحه من خانة
      المصروفات وتعرضه في خانته. جمعه مرة تانية = **الفلوس تتقري مرتين**.
      (نفس القاعدة الموثَّقة في `Closing::dayOfficeDeposits`.) */ ?>
<?php
  $safeExpenses = round((float) $safeDay['expenses'] - (float) $safeOffice, 2);
  $safeSteps = [
    ['label' => 'فتحنا بـ',      'value' => $safeDay['opening'], 'op' => '',  'tone' => 'navy'],
    ['label' => 'دخلها',         'value' => $safeDay['topups'],  'op' => '+', 'tone' => 'green'],
    ['label' => 'نزل للمكتب',    'value' => $safeOffice,         'op' => '−', 'tone' => 'gold'],
    ['label' => 'مصروفات',       'value' => $safeExpenses,       'op' => '−', 'tone' => 'red'],
    ['label' => 'عهدة سواقين',   'value' => $safeDay['driver'],  'op' => '−', 'tone' => 'purple'],
  ];
?>
<div class="d-sec-head">
  <?= icon('briefcase', 17) ?><span>خزنة المكتب في <?= e(dfmt($date)) ?></span>
</div>
<div class="d-eq mb">
  <?php foreach ($safeSteps as $s):
        /* الصفر بيتشال من المعادلة — «نزل للمكتب ٠» خانة فاضية بتاخد مساحة
           وما بتقولش حاجة. الطرفان بيفضلوا متساويين لأن الصفر ما بيغيّرش. */
        if (abs((float) $s['value']) < 0.005 && $s['op'] !== '') { continue; } ?>
    <?php if ($s['op'] !== ''): ?><span class="d-eq-op" aria-hidden="true"><?= $s['op'] ?></span><?php endif; ?>
    <button type="button" class="d-eq-part <?= e($s['tone']) ?>"
            data-drill="<?= e($drill('safe_day')) ?>"
            data-drill-title="حركة خزنة المكتب في <?= e(dfmt($date)) ?>">
      <b><?= e(money0($s['value'], false)) ?></b>
      <span><?= e($s['label']) ?></span>
    </button>
  <?php endforeach; ?>
  <span class="d-eq-op eq" aria-hidden="true">=</span>
  <button type="button" class="d-eq-part total<?= $safeDay['closing'] < 0 ? ' neg' : '' ?>"
          data-drill="<?= e($drill('custody')) ?>" data-drill-title="رصيد خزنة المكتب">
    <b><?= e(money0($safeDay['closing'], false)) ?></b>
    <span><?= $locked ? 'قفلنا على' : 'الرصيد دلوقتي' ?></span>
  </button>
</div>

<?php /* ═══════════ ④ حركة اليوم — أربع أرقام، كلها طلب مباشر من المالك ═══════════
   «خرج كام عربية اليوم · دخل كام إيصال · إيه الأذونات الجديدة اللي اتفتحت ·
    الأذونات اللي محتاجة تتقفل». */ ?>
<div class="d-sec-head">
  <?= icon('zap', 17) ?><span>حركة يوم <?= e(dfmt($date)) ?></span>
</div>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($daily, $cars, $opened, $readiness, $cur, $date, $drill, $ctx) { ?>
  <?php partial('stat', [
    'label' => 'عربيات خرجت', 'value' => intf($cars), 'unit' => 'عربية',
    'icon' => 'truck', 'tone' => 'navy',
    'drill' => $drill('cars'), 'title' => 'العربيات اللي خرجت في ' . dfmt($date),
  ]); ?>
  <?php partial('stat', [
    'label' => 'إيصالات دخلت', 'value' => intf($daily['receipts_cnt']), 'unit' => 'إيصال',
    'icon' => 'clipboard', 'tone' => 'gold',
    'drill' => $drill('receipts'), 'title' => 'إيصالات ' . dfmt($date),
    'trend' => $ctx['receipts']['delta'], 'spark' => $ctx['receipts']['spark'],
  ]); ?>
  <?php partial('stat', [
    'label' => 'أذونات اتفتحت', 'value' => intf(count($opened)), 'unit' => 'إذن',
    'icon' => 'plus', 'tone' => 'teal',
    'drill' => $drill('opened'), 'title' => 'أذونات اتفتحت في ' . dfmt($date),
  ]); ?>
  <?php /* ⚠️ الرقم ده **مش عن اليوم** — هو حالة قايمة (كل الأذونات المفتوحة
           اللي خلصت شغلها). موضوعه هنا لأنه تصرّف مطلوب دلوقتي، والتسمية
           بتقول ده بوضوح («محتاجة تتقفل» مش «اتقفلت النهاردة»). */ ?>
  <?php partial('stat', [
    'label' => 'محتاجة تتقفل', 'value' => intf($readiness['counts']['ready']), 'unit' => 'إذن',
    'icon' => 'check-circle', 'tone' => $readiness['counts']['ready'] > 0 ? 'green' : 'navy',
    'drill' => $drill('needs_closing'), 'title' => 'أذونات خلصت شغلها',
  ]); ?>
<?php }]); ?>

<?php /* ═══════════ ⑤ المناديب — الحكم مش الأرقام ═══════════
   «كل مندوب نزله كام عهدة وصرف قد إيه وسوَى ولا مسواش عهدته».
   ⚠️ الكارتان مجموع صفوف الجدول اللي تحتهم بالظبط — محسوبين من **نفس**
      المصفوفة، فمستحيل الكارت يقول رقمًا والقايمة تقول غيره. */ ?>
<?php if ($repsOn): ?>
  <?php
  $rdDep = 0.0; $rdSp = 0.0; $rdOpen = 0;
  foreach ($repsDay as $__r) {
    $rdDep += (float) ($__r['deposits'] ?? 0);
    $rdSp  += (float) ($__r['spent'] ?? 0);
    if (\App\Models\RepCustody::dayVerdict($__r)['key'] !== 'settled') { $rdOpen++; }
  }
  ?>
  <div class="d-sec-head">
    <?= icon('users', 17) ?><span>المناديب في <?= e(dfmt($date)) ?></span>
  </div>
  <?php partial('stat_grid', ['items' => 3, 'class' => 'mb', 'body' => function () use ($rdDep, $rdSp, $rdOpen, $repsDay, $cur, $date, $drill) { ?>
    <?php partial('stat', [
      'label' => 'نزل عهدة', 'value' => money0($rdDep, false), 'unit' => $cur,
      'icon' => 'wallet', 'tone' => 'gold',
      'drill' => $drill('reps_day'), 'title' => 'عهدة كل مندوب في ' . dfmt($date),
    ]); ?>
    <?php partial('stat', [
      'label' => 'اللي اتصرف', 'value' => money0($rdSp, false), 'unit' => $cur,
      'icon' => 'trending-down', 'tone' => 'red',
      'drill' => $drill('reps_day'), 'title' => 'صرف كل مندوب في ' . dfmt($date),
    ]); ?>
    <?php /* ⚠️ **عدّاد «ما سوّاش» هو الرقم اللي المالك بيدوّر عليه فعلاً** —
             مش مبلغ، **عدد ناس** لسه عليهم حاجة. مصدره `dayVerdict()` نفسها
             اللي بترسم شارة كل صف، فالكارت والجدول مستحيل يفترقوا. */ ?>
    <?php partial('stat', [
      'label' => 'لسه ما سوّاش', 'value' => intf($rdOpen), 'unit' => 'مندوب',
      'icon' => 'alert', 'tone' => $rdOpen > 0 ? 'red' : 'green',
      'drill' => $drill('reps_day'), 'title' => 'حالة تسوية المناديب في ' . dfmt($date),
    ]); ?>
  <?php }]); ?>

  <?php partial('panel', [
    'title' => 'عهدة كل مندوب', 'icon' => 'users', 'class' => 'mb', 'scroll' => true,
    'badge' => $repsDay ? ['text' => intf(count($repsDay)), 'tone' => 'navy'] : null,
    'action' => ['label' => 'إدارة العهدة', 'url' => url('rep-custody')],
    'body' => function () use ($repsDay, $repNames) {
      if (!$repsDay) { partial('empty', ['icon' => 'users', 'title' => 'مفيش عهدة نزلت ولا اتصرفت النهاردة',
                                         'action' => ['label' => 'نزّل عهدة', 'url' => url('rep-custody')]]); return; }
      dash_rep_rows($repsDay, $repNames);
    },
  ]); ?>
<?php endif; ?>

<?php /* ═══════════ ⑥ الشغل الجاري ═══════════
   «الأذونات اللي فيها إيصالات محتملة ناقصة» — طلب المالك، ومصدره الموديل
   المدرَّب اللي كان شغّالًا في شاشة الإذن بس ومحدش شايفه على مستوى الشركة. */ ?>
<div class="d-sec-head">
  <?= icon('layers', 17) ?><span>الشغل الجاري</span>
  <button type="button" class="btn ghost sm d-sec-act"
          data-drill="<?= e($drill('permits_open')) ?>" data-drill-title="كل الأذونات المفتوحة">
    <?= icon('folder', 14) ?> <?= intf($permits['open']) ?> إذن مفتوح
  </button>
</div>

<div class="d-grid2 mb">
  <?php partial('panel', [
    'title' => 'ناقصها إيصالات', 'icon' => 'clipboard', 'scroll' => true,
    'badge' => $readiness['counts']['gaps'] > 0 ? ['text' => intf($readiness['counts']['gaps']), 'tone' => 'red'] : null,
    'action' => ['label' => 'عرض التفاصيل', 'drill' => $drill('receipt_gaps'), 'title' => 'أذونات خرجت عربياتها وناقصها إيصالات'],
    'body' => function () use ($readiness) {
      if (!$readiness['gaps']) {
        partial('empty', ['icon' => 'check-circle', 'title' => 'مفيش إذن خرجت عربياته وناقصه إيصالات']);
        return;
      }
      foreach (array_slice($readiness['gaps'], 0, 8) as $g) { dash_readiness_row($g); }
    },
  ]); ?>
  <?php partial('panel', [
    'title' => 'جاهز للفوترة', 'icon' => 'receipt', 'scroll' => true,
    'badge' => $readySum['count'] > 0 ? ['text' => intf($readySum['count']), 'tone' => 'red'] : null,
    'action' => ['label' => 'عرض التفاصيل', 'drill' => $drill('ready'), 'title' => 'الأذونات الجاهزة للفوترة'],
    'body' => function () use ($ready) {
      if (!$ready) { partial('empty', ['emoji' => '✅', 'title' => 'تمام!']); return; }
      foreach (array_slice($ready, 0, 8) as $p) { dash_permit_row($p, true); }
    },
  ]); ?>
</div>

<div class="d-grid-feed mb">
  <?php partial('panel', [
    'title' => 'فلوس ' . dfmt($date), 'icon' => 'trending-down', 'scroll' => true,
    'action' => ['label' => 'سجل المصروفات', 'url' => url('expenses')],
    'body' => function () use ($feed, $date) {
      if (!$feed) {
        partial('empty', ['emoji' => '💸', 'title' => 'مفيش حركة فلوس في ' . dfmt($date)]);
        return;
      }
      foreach ($feed as $f) { dash_feed_row($f); }
    },
  ]); ?>

  <div class="d-card d-notes">
    <?php $pending = 0; foreach ($notes as $n) { if (empty($n['done'])) { $pending++; } } ?>
    <div class="d-card-head"><h3><?= icon('file-text', 18) ?> ملاحظات</h3><span class="chip"><?= intf($pending) ?><?php if (count($notes) > $pending): ?> / <?= intf(count($notes)) ?><?php endif; ?></span></div>
    <form class="d-note-form no-confirm" method="post" action="<?= url('notes') ?>">
      <?= csrfField() ?>
      <textarea name="body" rows="2" maxlength="500" placeholder="ملاحظة…" required></textarea>
      <div class="d-note-tools">
        <div class="d-note-colors">
          <?php foreach (['gold','navy','green','red','teal','purple'] as $ci): ?>
          <label class="d-note-color <?= $ci ?>"><input type="radio" name="color" value="<?= $ci ?>" <?= $ci === 'gold' ? 'checked' : '' ?>><span></span></label>
          <?php endforeach; ?>
        </div>
        <button class="btn sm" type="submit"><?= icon('plus', 15) ?> إضافة</button>
      </div>
    </form>
    <div class="d-note-list">
      <?php if (!$notes): ?>
        <div class="d-empty sm"><?= icon('file-text', 22) ?><span>مفيش ملاحظات. أضف أول واحدة.</span></div>
      <?php else: foreach ($notes as $n): $done = !empty($n['done']); ?>
      <div class="d-note <?= e($n['color'] ?: 'gold') ?><?= $done ? ' done' : '' ?>" id="note-<?= (int) $n['id'] ?>">
        <div class="d-note-main">
          <form method="post" action="<?= url('notes/' . $n['id'] . '/toggle') ?>" class="no-confirm d-note-check-f">
            <?= csrfField() ?>
            <button class="d-note-check" type="submit" title="<?= $done ? 'إلغاء الإكمال' : 'تعليم كمكتمل' ?>"><?= $done ? icon('check-circle', 16) : '' ?></button>
          </form>
          <p><?= nl2br(e($n['body'])) ?></p>
        </div>
        <div class="d-note-foot">
          <span><?= icon('clock', 11) ?> <?php if ($done && $n['done_at']): ?>اكتملت <?= e(dfmt($n['done_at'])) ?><?php else: ?><?= e(dfmt($n['created_at'])) ?><?php endif; ?><?php if ($n['author']): ?> · <?= e($n['author']) ?><?php endif; ?></span>
          <div class="d-note-actions">
            <button type="button" class="d-note-ic" data-edit="<?= (int) $n['id'] ?>" title="تعديل"><?= icon('edit', 13) ?></button>
            <form method="post" action="<?= url('notes/' . $n['id'] . '/delete') ?>" class="confirm-delete" data-confirm="حذف الملاحظة؟" style="margin:0">
              <?= csrfField() ?>
              <button class="d-note-ic del" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
            </form>
          </div>
        </div>
        <form method="post" action="<?= url('notes/' . $n['id']) ?>" class="no-confirm d-note-edit" id="edit-<?= (int) $n['id'] ?>" hidden>
          <?= csrfField() ?>
          <textarea name="body" rows="2" maxlength="500" required><?= e($n['body']) ?></textarea>
          <div class="d-note-tools">
            <div class="d-note-colors">
              <?php foreach (['gold','navy','green','red','teal','purple'] as $ci): ?>
              <label class="d-note-color <?= $ci ?>"><input type="radio" name="color" value="<?= $ci ?>" <?= ($n['color'] ?: 'gold') === $ci ? 'checked' : '' ?>><span></span></label>
              <?php endforeach; ?>
            </div>
            <div class="d-note-edit-btns">
              <button type="button" class="btn ghost sm" data-cancel="<?= (int) $n['id'] ?>">إلغاء</button>
              <button type="submit" class="btn sm"><?= icon('check', 14) ?> حفظ</button>
            </div>
          </div>
        </form>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

</div><!-- /.dash2 -->

<?php /* الإدخال السريع الجماعي اتشال (2026-08-17): أداة **موظف إدخال** كانت
         قاعدة في لوحة إدارة وواخدة ٢٠٠ سطر JS + شاشتَين اختيار من كل فتحة
         للصفحة. مكانها الطبيعي شاشة الأذونات.
         و«أذونات اتحرّك عليها» و«آخر ما اتسجّل» اتشالوا كمان — الأول بيكرّر
         لوح «فلوس اليوم» بالحرف، والتاني موجود كامل في `/audit`. */ ?>
<?php /* Chart.js (200KB) اتشال من الداشبورد مع الرسوم — بيتحمّل في /reports بس */ ?>
<script>
/* ── تعديل الملاحظات (إظهار/إخفاء الفورم) ── */
(function(){
  document.querySelectorAll('[data-edit]').forEach(btn=>btn.addEventListener('click',()=>{
    const id=btn.dataset.edit, f=document.getElementById('edit-'+id), note=document.getElementById('note-'+id);
    if(!f)return; const show=f.hidden; f.hidden=!show; note.classList.toggle('editing',show);
    if(show){ const ta=f.querySelector('textarea'); ta.focus(); ta.setSelectionRange(ta.value.length,ta.value.length); }
  }));
  document.querySelectorAll('[data-cancel]').forEach(btn=>btn.addEventListener('click',()=>{
    const id=btn.dataset.cancel, f=document.getElementById('edit-'+id), note=document.getElementById('note-'+id);
    if(f){ f.hidden=true; note.classList.remove('editing'); }
  }));
})();
</script>
