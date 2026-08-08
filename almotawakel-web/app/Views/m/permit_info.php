<?php
/**
 * تفاصيل الإذن على موبايل المندوب — **قراءة فقط**.
 *
 * الشاشة دي هي اللي بتفتح لما المندوب يضغط على كارت الإذن (قبل كده الضغط
 * مكانش بيعمل حاجة). فيها اللي كان مدفونًا في القايمة المطويّة + السواقين
 * والعربيات بالتفصيل + البنود اللي اتسجّلت على الإذن ومين سجّلها.
 *
 * ⚠️⚠️ **ولا رقم بيخصّ العميل** (قاعدة v35):
 *   • `RepPortal::permitBills` بتختار الأعمدة **بالاسم** فالنولون والعهدة
 *     والخصم مش بيوصلوا الشاشة أصلاً.
 *   • `RepPortal::permitEntries` بتخفي **مبلغ بنود الزملاء** — الغرض إن
 *     المندوب يعرف «إيه اتسجّل» فما يدفعش إيصال مدفوع، مش «هُمّ دفعوا كام».
 *
 * ⚠️ **الفورم الوحيد فيها هو رفع ورقة** — باقي الإجراءات روابط لشاشاتها.
 * ⚠️ كل الكلاسات في `m.css` (البوابة ما بتحمّلش `app.css`).
 *
 * @var array $permit @var array $bills @var array $entries
 */
$num  = fn($v) => number_format((float) $v, 0);
$pid  = (int) $permit['id'];
$cold = false;
foreach (($permit['container_parts'] ?? []) as $cp) {
    if (str_contains(\App\Models\Maintenance::normkey((string) $cp), 'مبرد')) { $cold = true; break; }
}
$facts = array_filter([
    'الميناء'         => $permit['port'] ?? '',
    'التوكيل الملاحي' => $permit['agency'] ?? '',
    'شركة الشحن'      => $permit['shipping_co'] ?? '',
    'الوجهة'          => $permit['destination'] ?? '',
], fn($v) => trim((string) $v) !== '');

$kindIcon = ['receipt' => 'receipt', 'expense' => 'banknote', 'misc' => 'anchor'];
$stLabel  = ['pending' => 'مستني', 'approved' => 'اتقبل', 'rejected' => 'اترفض'];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title><?= e($permit['type_label'] . ' ' . $permit['no']) ?> — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body pi-body">

<div class="hm-top">
  <header class="hm-bar">
    <?php $backTo = 'm'; require __DIR__ . '/_back.php'; ?>
    <span class="hm-hi">
      <small><?= e($permit['type_label']) ?></small>
      <b><bdi dir="ltr"><?= e($permit['no']) ?></bdi></b>
    </span>
  </header>

  <section class="pi-hero <?= ($permit['type'] ?? 'sader') === 'warid' ? 'is-warid' : 'is-sader' ?>">
    <h1><?= e($permit['company'] ?: $permit['client'] ?: 'بدون شركة') ?></h1>
    <?php if (trim((string) ($permit['client'] ?? '')) !== '' && $permit['client'] !== $permit['company']): ?>
      <p><?= icon('user', 13) ?> <?= e($permit['client']) ?></p>
    <?php endif; ?>
    <div class="pi-hero-tags">
      <?php if (!empty($permit['date'])): ?>
        <span><?= icon('calendar', 13) ?> <?= e(dfmt($permit['date'])) ?></span>
      <?php endif; ?>
      <?php if ($cold): ?><span class="is-cold"><?= icon('snowflake', 13) ?> مبرّد</span><?php endif; ?>
    </div>

    <?php /* ⚠️ الإجراءان الأساسيان في الهيرو (طلب المالك) — كانوا في آخر
             الصفحة، والمندوب لازم يمرّر كل التفاصيل عشان يوصلهم. */ ?>
    <div class="pi-hero-acts">
      <a class="is-primary" href="<?= url('m/permit/' . $pid) ?>">
        <?= icon('plus', 15) ?> سجّل بند</a>
      <a href="<?= url('m/permit/' . $pid . '/track') ?>">
        <?= icon('map-pin', 15) ?> حالة الرحلة</a>
      <?php /* ⚠️ البوليصة اتنقلت من الرئيسية لهنا (طلب المالك): لازمها إذن،
               وفتحها من الرئيسية كانت بتبدأ بسؤال «أنهي إذن؟» على ٣٧ إذن.
               `?permit=` بتختار الإذن مقدّمًا — والخادم **بيعيد التحقّق** منه
               فالرابط مش مصدر ثقة. */ ?>
      <a href="<?= url('m/bill/new?permit=' . $pid) ?>">
        <?= icon('truck', 15) ?> بوليصة جديدة</a>
    </div>
  </section>
</div>

<main class="hm-sheet">
  <span class="hm-grab" aria-hidden="true"></span>

  <?php /* ⚠️ الصفحة كانت **مش بتعرض أي رسالة** — فرفع الورقة كان بيتم والمندوب
           ما يعرفش نجح ولا فشل (الـflash بيتسجّل في الجلسة ويضيع). */ ?>
  <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

  <?php /* ⚠️⚠️ **تقدير مش وعد** (نفس قاعدة v34): مبني على مسافة طريق حقيقية
           من `route_times`، والسرعة سرعة **شاحنة محمّلة** + إضافة ثابتة لبوابة
           الميناء والكمائن. الوجهة المجهولة بترجّع null فالقسم **بيختفي** بدل
           ما يعرض رقمًا مخترعًا. */ ?>
  <?php if ($eta !== null): ?>
    <section class="pi-eta">
      <span class="pi-eta-ic"><?= icon('clock', 18) ?></span>
      <div class="pi-eta-mid">
        <small>الوصول المتوقّع من <?= e($permit['port'] ?: 'الميناء') ?></small>
        <b><?= e(\App\Models\RouteTime::humanDuration($eta['minutes'])) ?></b>
        <span class="pi-eta-km">
          <bdi dir="ltr"><?= (int) $eta['km'] ?></bdi> كم
          <?= $eta['source'] === 'exact' ? '· مسافة طريق' : '· تقدير بالخط المستقيم' ?>
        </span>
      </div>
      <span class="pi-eta-to"><?= icon('map-pin', 13) ?> <?= e($permit['destination']) ?></span>
    </section>
  <?php endif; ?>

  <?php /* الحاويات بنفس أشكال الكارت — نفس المصدر فمستحيل يختلفوا */ ?>
  <?php if ($permit['container_parts']): ?>
    <h2 class="pi-h"><?= icon('package', 15) ?> الحاويات</h2>
    <div class="pi-boxes">
      <?php foreach ($permit['container_parts'] as $part): ?>
        <?php $art = container_art((string) $part); ?>
        <?php if ($art !== null): ?>
          <span class="hm-boxwrap">
            <span class="hm-box is-<?= e($art['kind']) ?><?= $art['size'] ? ' sz-' . e($art['size']) : ' sz-x' ?>">
              <svg class="hm-box-art" viewBox="0 0 64 32" preserveAspectRatio="none"
                   aria-hidden="true"><use href="#box-<?= e($art['kind']) ?>"/></svg>
              <?php /* bdi dir=ltr إلزامي: من غيرها «2×40» بتتعرض «40×2» */ ?>
                    <b class="hm-box-lbl"><bdi dir="ltr"><?= e($art['label']) ?></bdi></b>
            </span>
          </span>
          <?php if ($art['sub'] !== '' && $art['kind'] !== 'reefer'): ?>
            <span class="hm-box-sub"><?= e($art['sub']) ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span class="hm-chip"><?= icon('package', 14) ?> <bdi dir="ltr"><?= e($part) ?></bdi></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($facts): ?>
    <h2 class="pi-h"><?= icon('clipboard', 15) ?> بيانات الإذن</h2>
    <dl class="pi-facts">
      <?php foreach ($facts as $k => $v): ?>
        <div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  <?php endif; ?>

  <?php /* السواقين والعربيات — أهم حاجة ميدانية بعد الحاويات */ ?>
  <h2 class="pi-h"><?= icon('truck', 15) ?> العربيات والسواقين
    <?php if ($bills): ?><span class="pi-n"><?= count($bills) ?></span><?php endif; ?></h2>
  <?php if (!$bills): ?>
    <div class="hm-empty"><span class="ic"><?= icon('truck', 14) ?></span>
      <b>لسه مفيش بوالص على الإذن ده</b></div>
  <?php else: ?>
    <?php foreach ($bills as $b): ?>
      <?php
      /* ⚠️ «سيارة المكتب» بتتحدّد من **سجل السيارات** (`OfficeCar::matchPlate`)
         لا من نص مكتوب في البوليصة — المطابقة بمفتاح اللوحة فترتيب الحروف
         والمسافات ما يفرقوش (v54). ولو الوحدة لسه مش مرحّلة بترجّع null بأمان. */
      $isOffice = \App\Models\OfficeCar::matchPlate((string) ($b['car_no'] ?? '')) !== null;
      ?>
      <article class="pi-car<?= $isOffice ? ' is-office' : '' ?>">
        <div class="pi-car-top">
          <svg class="pi-truck" viewBox="0 0 104 40" preserveAspectRatio="xMidYMid meet"
               aria-hidden="true"><use href="#truck-<?= $isOffice ? 'office' : 'hired' ?>"/></svg>
          <span class="pi-car-plate"><bdi dir="ltr"><?= e($b['car_no'] ?: '—') ?></bdi></span>
          <?php if ($isOffice): ?>
            <span class="pi-office-tag"><?= icon('star', 12) ?> سيارة المكتب</span>
          <?php endif; ?>
        </div>
        <div class="pi-car-mid">
          <b><?= e($b['driver'] ?: 'بلا سائق') ?></b>
          <div class="pi-car-meta">
            <?php if (trim((string) $b['bill_number']) !== ''): ?>
              <span>بوليصة <bdi dir="ltr"><?= e($b['bill_number']) ?></bdi></span>
            <?php endif; ?>
            <?php if (trim((string) ($b['car_owner'] ?? '')) !== ''): ?>
              <span>· <?= e($b['car_owner']) ?></span>
            <?php endif; ?>
            <?php if (trim((string) ($b['destination'] ?? '')) !== ''): ?>
              <span>· <?= icon('map-pin', 12) ?> <?= e($b['destination']) ?></span>
            <?php endif; ?>
          </div>
          <?php if (trim((string) ($b['container_no'] ?? '')) !== ''): ?>
            <div class="pi-car-cnt"><?= icon('package', 12) ?>
              <bdi dir="ltr"><?= e($b['container_no']) ?></bdi>
              <?php if (trim((string) ($b['container_type'] ?? '')) !== ''): ?>
                <em><?= e(container_norm($b['container_type'])) ?></em>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php /* ⚠️ البنود من **كل** المناديب — عشان المندوب يعرف إيه اتسجّل قبله
           فما يدفعش إيصال مدفوع. ومبلغ الزميل مخفي (الإخفاء في الموديل). */ ?>
  <h2 class="pi-h"><?= icon('receipt', 15) ?> البنود المسجَّلة
    <?php if ($entries): ?><span class="pi-n"><?= count($entries) ?></span><?php endif; ?></h2>
  <?php if (!$entries): ?>
    <div class="hm-empty"><span class="ic"><?= icon('receipt', 14) ?></span>
      <b>لسه مفيش بنود على الإذن ده</b>
      <p>لو دفعت حاجة، سجّلها من زرار «سجّل بند» تحت.</p></div>
  <?php else: ?>
    <?php foreach ($entries as $s): ?>
      <article class="pi-entry is-<?= e($s['status']) ?><?= !empty($s['mine']) ? ' is-mine' : '' ?>">
        <span class="pi-entry-ic"><?= icon($kindIcon[$s['kind']] ?? 'receipt', 15) ?></span>
        <div class="pi-entry-mid">
          <b><?= e($s['note']) ?></b>
          <div class="pi-entry-meta">
            <span class="pi-who"><?= icon('user', 12) ?> <?= e($s['rep_name'] ?: 'مندوب') ?>
              <?php if (!empty($s['mine'])): ?><em>انت</em><?php endif; ?></span>
            <span>· <?= e(dfmt($s['entry_date'])) ?></span>
            <?php if ((int) $s['qty'] > 1): ?><span>· <?= (int) $s['qty'] ?> عدد</span><?php endif; ?>
            <?php if (!empty($s['is_visa'])): ?><span>· <?= icon('credit-card', 12) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="pi-entry-end">
          <?php /* المبلغ لصاحب البند وبس — بند الزميل بيرجع بـnull من الموديل */ ?>
          <?php if ($s['amount'] !== null): ?>
            <span class="pi-entry-amt"><?= $num($s['amount']) ?></span>
          <?php endif; ?>
          <span class="hm-st is-<?= e($s['status']) ?>"><?= e($stLabel[$s['status']] ?? $s['status']) ?></span>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php /* ملفات الإذن (v64) — المندوب بيصوّر الورقة من الميناء وترتبط بالإذن.
           ⚠️ **مش زي مستندات الأطراف (v56)**: دي أوراق الطرف اللي بتظهر على كل
              أذوناته، ودي أوراق **الإذن ده بعينه**. */ ?>
  <h2 class="pi-h" id="files"><?= icon('folder', 15) ?> ملفات الإذن
    <?php if ($files): ?><span class="pi-n"><?= count($files) ?></span><?php endif; ?></h2>

  <?php if ($files): ?>
    <div class="pi-files">
      <?php foreach ($files as $f): ?>
        <?php $img = \App\Models\PermitFile::isImage($f); ?>
        <figure class="pi-file">
          <a href="<?= url('file/permit-file/' . (int) $f['id']) ?>" target="_blank" rel="noopener">
            <?php if ($img): ?>
              <img src="<?= url('file/permit-file/' . (int) $f['id']) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="pi-file-doc"><?= icon('file-text', 22) ?><em>PDF</em></span>
            <?php endif; ?>
          </a>
          <figcaption>
            <b><?= e($f['title'] ?: ($f['orig_name'] ?: 'ورقة')) ?></b>
            <small><?= e($f['rep_name'] ?: 'المكتب') ?> · <?= e(dfmt(substr((string) $f['created_at'], 0, 10))) ?></small>
          </figcaption>
          <?php /* ⚠️ زرار الشيل بيظهر لصاحب الورقة بس — والحارس الحقيقي في
                   `PermitFile::remove($id, $repId)` مش في الإخفاء ده. */ ?>
          <?php if ((int) ($f['rep_id'] ?? 0) === (int) $repId): ?>
            <form method="post" action="<?= url('m/permit-file/' . (int) $f['id'] . '/delete') ?>"
                  class="confirm-delete" data-confirm="تشيل الورقة دي؟">
              <?= csrfField() ?>
              <button type="submit" aria-label="شيل الورقة"><?= icon('trash', 13) ?></button>
            </form>
          <?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php /* ═══ رفع ورقة: اختيار ← معاينة واسم ← رفع ═══════════════════
           ⚠️ **تحسين تدريجي**: بلا جافاسكريبت الفورم كله ظاهر وشغّال (خانتا
              اختيار + وصف + زرار). الجافاسكريبت بيخفي الخطوات ويوريها واحدة
              واحدة ويضيف المعاينة — فلو السكربت وقع المندوب لسه بيقدر يرفع.
           ⚠️⚠️ **خانتا ملف باسمين مختلفين** (`doc_cam` / `doc_file`): `capture`
              بتفتح الكاميرا على طول وبتمنع اختيار ملف موجود، وبدونها العكس.
              وكانوا بنفس الاسم `doc` فالمتصفح بيبعت **الاتنين** — الفاضية
              والمليانة — والفاضية بتغلب لأنها الأخيرة، فالخادم يقول «اختار
              الملف الأول» رغم إن المندوب اختار (باج متحقَّق). الخادم بياخد
              أول خانة فيها ملف فعلاً عبر `PermitFile::pickUpload`. */ ?>
  <form class="pi-upload" method="post" enctype="multipart/form-data" id="pfForm"
        action="<?= url('m/permit/' . $pid . '/file') ?>">
    <?= csrfField() ?>

    <div class="pi-up-choices" id="pfChoices">
      <label class="pi-up-choice">
        <input type="file" name="doc_cam" accept="image/*" capture="environment" data-pf-pick>
        <span class="ic"><?= icon('camera', 20) ?></span>
        <b>صوّر ورقة</b>
      </label>
      <label class="pi-up-choice">
        <input type="file" name="doc_file" accept="image/*,application/pdf" data-pf-pick>
        <span class="ic"><?= icon('folder', 20) ?></span>
        <b>ارفع ملف</b>
      </label>
    </div>

    <div class="pi-up-stage" id="pfStage">
      <div class="pi-up-prev" id="pfPrev" hidden>
        <img id="pfImg" alt="" hidden>
        <span class="pi-up-doc" id="pfDoc" hidden><?= icon('file-text', 22) ?><em>PDF</em></span>
        <div class="pi-up-info">
          <b id="pfName">—</b>
          <small id="pfSize"></small>
        </div>
        <button type="button" class="pi-up-x" id="pfClear" aria-label="غيّر الملف"><?= icon('x', 14) ?></button>
      </div>
      <input type="text" name="title" maxlength="120" placeholder="اسم المستند (اختياري)">
      <button type="submit" class="pi-up-go" id="pfGo"><?= icon('plus', 15) ?> ارفع</button>
    </div>
  </form>

  <script>
  (function () {
    'use strict';
    var form = document.getElementById('pfForm');
    if (!form) { return; }
    var stage = document.getElementById('pfStage'), prev = document.getElementById('pfPrev'),
        img = document.getElementById('pfImg'), doc = document.getElementById('pfDoc'),
        nameEl = document.getElementById('pfName'), sizeEl = document.getElementById('pfSize'),
        clear = document.getElementById('pfClear'), go = document.getElementById('pfGo'),
        picks = form.querySelectorAll('[data-pf-pick]'), url = null;

    /* الخطوة التانية مخفية لحد ما يختار — والإخفاء بالجافاسكريبت لا من الخادم
       عشان اللي مالوش سكربت يفضل شايف الفورم كامل. */
    stage.hidden = true;

    function human(n) {
      return n > 1048576 ? (n / 1048576).toFixed(1) + ' ميجا' : Math.round(n / 1024) + ' ك';
    }
    function reset() {
      picks.forEach(function (i) { i.value = ''; i.disabled = false; });
      if (url) { URL.revokeObjectURL(url); url = null; }
      img.hidden = true; doc.hidden = true; prev.hidden = true; stage.hidden = true;
      document.getElementById('pfChoices').hidden = false;
    }
    picks.forEach(function (input) {
      input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        if (!f) { return; }
        /* ⚠️ الخانة التانية بتتفضّى **وتتعطّل** — الخانة المعطّلة ما بتتبعتش
           خالص، فمستحيل يوصل الخادم جزء فاضي يغلب اللي المندوب اختاره. */
        picks.forEach(function (o) { if (o !== input) { o.value = ''; o.disabled = true; } });
        if (url) { URL.revokeObjectURL(url); }
        nameEl.textContent = f.name || 'ورقة';
        sizeEl.textContent = human(f.size || 0);
        if (/^image\//.test(f.type)) {
          url = URL.createObjectURL(f);
          img.src = url; img.hidden = false; doc.hidden = true;
        } else {
          img.hidden = true; doc.hidden = false; url = null;
        }
        prev.hidden = false; stage.hidden = false;
        document.getElementById('pfChoices').hidden = true;
      });
    });
    clear.addEventListener('click', reset);
    form.addEventListener('submit', function () {
      go.disabled = true;                      // منع الضغط المزدوج
      go.textContent = 'بيترفع…';
    });
  })();
  </script>

  <?php /* مستندات الأطراف (v56) — بتظهر بس لو الشركة ليها أوراق مسجَّلة */ ?>
  <?php if (!empty($permit['has_docs'])): ?>
    <nav class="pi-acts" aria-label="مستندات الشركة">
      <a href="<?= url('m/permit/' . $pid . '/docs') ?>">
        <?= icon('folder', 15) ?> مستندات الشركة (بطاقة ضريبية · سجل تجاري)</a>
    </nav>
  <?php endif; ?>
</main>

<?php /* مكتبة أشكال الحاويات المستعملة في الصفحة — نفس مبدأ الكارت */ ?>
<?php
$boxesUsed = [];
foreach (($permit['container_parts'] ?? []) as $bpart) {
    $ba = container_art((string) $bpart);
    if ($ba !== null) { $boxesUsed[$ba['kind']] = 1; }
}
?>
<svg class="hm-stamp-lib" aria-hidden="true" focusable="false"><?php
foreach (array_keys($boxesUsed) as $bk) {
    echo '<symbol id="box-' . e($bk) . '" viewBox="0 0 64 32" preserveAspectRatio="none">'
       . container_box_svg($bk) . '</symbol>';
}
/* القاطرة بنوعيها — بترسم مرّة واحدة حتى لو الصفحة فيها ١٠ عربيات. */
foreach (['hired' => false, 'office' => true] as $tk => $isOff) {
    echo '<symbol id="truck-' . $tk . '" viewBox="0 0 104 40">' . truck_svg($isOff) . '</symbol>';
}
?></svg>

</body>
</html>
