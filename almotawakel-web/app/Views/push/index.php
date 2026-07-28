<?php
/**
 * شاشة الإشعارات — تفعيل الجهاز + إرسال إشعار مكتوب + سجل المرسَل.
 *
 * ⚠️ زرار «فعّل الإشعارات» **لازم يشتغل بضغطة من المستخدم** — المتصفحات
 *    بترفض طلب الإذن من غير تفاعل، وبتحرق النطاق لو اتطلب وقت التحميل.
 */
$errors = $errors ?? [];
$old    = $old ?? [];
?>
<div class="toolbar">
  <div class="section-head" style="margin:0"><?= icon('bell', 17) ?> الإشعارات</div>
  <div class="spacer"></div>
  <span class="count-badge"><?= (int) $counts['office'] ?> جهاز مكتب · <?= (int) $counts['rep'] ?> جهاز مندوب</span>
</div>

<?php if (!$ready): ?>
  <div class="card mb" style="border-inline-start:4px solid var(--red)">
    <div style="padding:16px 18px">
      <b>الإشعارات مش جاهزة على السيرفر.</b>
      <p style="margin:6px 0 0;color:var(--grey)">شغّل <code>php bin/migrate_v51.php</code> عشان تتولّد مفاتيح الإشعارات.</p>
    </div>
  </div>
<?php endif; ?>

<?= formErrorSummary($errors) ?>

<?php /* ⚠️ أكتر لخبطة متوقّعة: نفس الموبايل ممكن يكون مفعّل الإشعارات في
         **تطبيق المندوب** بس، فإشعارات المكتب ما توصلوش — الاتنين اشتراكان
         منفصلان لأنهما تطبيقان مختلفان على الجهاز. */ ?>
<?php if ((int) $counts['office'] === 0): ?>
  <div class="card mb" style="border-inline-start:4px solid var(--gold)">
    <div style="padding:16px 18px">
      <b><?= icon('alert', 14) ?> مفيش ولا جهاز مكتب مفعّل الإشعارات.</b>
      <p style="margin:6px 0 0;color:var(--grey)">
        يعني إشعارات <b>الإذن الجديد والتقفيل والبند المستنّي مراجعة والبوليصة الجديدة</b> مش هتوصل لحد.
        <?php if ((int) $counts['rep'] > 0): ?>
          فيه <?= (int) $counts['rep'] ?> جهاز مفعّل من <b>تطبيق المندوب</b> — وده اشتراك تاني منفصل
          (نفس الموبايل ممكن يكون عليه التطبيقين). عشان تاخد إشعارات المكتب على موبايلك:
          افتح النظام على الموبايل، ثبّته من «⬇ ثبّت التطبيق»، وبعدين افتح الصفحة دي من جوّه التطبيق ودوس «فعّل الإشعارات».
        <?php else: ?>
          دوس «<?= icon('bell', 14) ?> فعّل الإشعارات على الجهاز ده» تحت.
        <?php endif; ?>
      </p>
    </div>
  </div>
<?php endif; ?>

<!-- ① الجهاز الحالي ------------------------------------------------------ -->
<div class="card mb">
  <div class="section-head" style="padding:16px 18px 0;margin:0"><?= icon('send', 17) ?> الجهاز اللي انت عليه دلوقتي</div>
  <div style="padding:14px 18px 18px">
    <p id="pushState" style="margin:0 0 12px;color:var(--grey)">بنشوف حالة الإشعارات على الجهاز ده…</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button type="button" class="btn gold" id="pushEnable" hidden><?= icon('bell', 14) ?> فعّل الإشعارات على الجهاز ده</button>
      <button type="button" class="btn ghost" id="pushDisable" hidden>🔕 اقفل الإشعارات على الجهاز ده</button>
      <form method="post" action="<?= url('push/test') ?>" class="no-confirm" style="margin:0">
        <?= csrfField() ?>
        <button type="submit" class="btn ghost"><?= icon('inbox', 14) ?> ابعت إشعار تجربة لكل الأجهزة</button>
      </form>
    </div>
    <p style="margin:12px 0 0;font-size:var(--fs-2);color:var(--grey)">
      كل واحد لازم يفعّلها من موبايله هو — مفيش طريقة نفعّلها له من هنا (ده شرط المتصفح مش نقص في النظام).
      وعلى الآيفون لازم يكون <b>ثبّت التطبيق على الشاشة الرئيسية</b> الأول.
    </p>
  </div>
</div>

<!-- ② إرسال إشعار -------------------------------------------------------- -->
<div class="card mb">
  <div class="section-head" style="padding:16px 18px 0;margin:0">✍️ ابعت إشعار</div>
  <form method="post" action="<?= url('push/send') ?>" style="padding:14px 18px 18px" class="no-confirm">
    <?= csrfField() ?>
    <?= formStateFields($formToken ?? '', $back ?? 'push') ?>
    <div class="form-grid two">
      <label class="field">
        <span>العنوان</span>
        <input type="text" name="title" maxlength="60" required
               value="<?= e($old['title'] ?? '') ?>" placeholder="مثال: اجتماع بكرة">
      </label>
      <label class="field">
        <span>يوصل لمين</span>
        <select name="audience">
          <?php $a = $old['audience'] ?? 'all'; ?>
          <option value="all"    <?= $a === 'all' ? 'selected' : '' ?>>كل الناس (المكتب + المناديب)</option>
          <option value="office" <?= $a === 'office' ? 'selected' : '' ?>>المكتب بس</option>
          <option value="rep"    <?= $a === 'rep' ? 'selected' : '' ?>>كل المناديب</option>
          <?php foreach ($reps as $rep): $v = 'rep:' . (int) $rep['id']; ?>
            <option value="<?= $v ?>" <?= $a === $v ? 'selected' : '' ?>>مندوب: <?= e($rep['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label class="field">
      <span>النص</span>
      <textarea name="body" rows="2" maxlength="160" required
                placeholder="نص الرسالة"><?= e($old['body'] ?? '') ?></textarea>
    </label>
    <label class="field">
      <span>يفتح على صفحة (اختياري)</span>
      <input type="text" name="url" maxlength="120" value="<?= e($old['url'] ?? '') ?>"
             placeholder="مثال: permits  أو  closing — سيبها فاضية عشان يفتح الرئيسية">
    </label>
    <div style="margin-top:12px">
      <button type="submit" class="btn gold"><?= icon('send', 14) ?> ابعت الإشعار</button>
    </div>
  </form>
</div>

<!-- ②ب صحة الإشعارات (المرحلة ٥) + تحليلات القراءة (المرحلة ٧) --------------
     ⚠ **قراءة فقط بالكامل** — مفيش هنا ولا فورم ولا زرار بيكتب.
     ⚠ **«اتقرا فعلاً» أهم رقم في الصفحة:** السجل القديم كان بيقول «وصل ٣ فشل ٠»
        وبس — يعني الرسالة وصلت الجهاز، مش إن حد شافها. -->
<div class="card mb">
  <div class="section-head" style="padding:16px 18px 0;margin:0"><?= icon('bar-chart', 17) ?> صحة الإشعارات — آخر <?= intf($health['days']) ?> أيام</div>
  <?php if (!$health['ready']): ?>
    <?php partial('empty', ['emoji' => '🧾', 'title' => 'سجل الإشعارات لسه ما اتفعّلش',]); ?>
  <?php else: ?>
  <div style="padding:14px 18px 18px">
    <div class="nh-grid">
      <div class="nh-box <?= $health['delivery_rate'] >= 95 ? 'good' : ($health['delivery_rate'] > 0 ? 'bad' : '') ?>">
        <b><?= e((string) $health['delivery_rate']) ?>%</b>
        <span>نسبة الوصول (<?= intf($health['sent']) ?> من <?= intf($health['sent'] + $health['failed']) ?>)</span>
        <div class="nh-bar <?= $health['delivery_rate'] < 95 ? 'low' : '' ?>"><i style="width:<?= (float) $health['delivery_rate'] ?>%"></i></div>
      </div>
      <div class="nh-box">
        <b><?= e((string) $health['read_rate']) ?>%</b>
        <span>اتقرا فعلاً (<?= intf($health['read']) ?> من <?= intf($health['total']) ?>)</span>
        <div class="nh-bar"><i style="width:<?= (float) $health['read_rate'] ?>%"></i></div>
      </div>
      <div class="nh-box"><b><?= intf($health['devices']) ?></b><span>جهاز شغّال<?= $health['dead'] > 0 ? ' · ' . intf($health['dead']) . ' متوقف' : '' ?></span></div>
      <div class="nh-box <?= $health['retrying'] > 0 ? 'bad' : '' ?>"><b><?= intf($health['retrying']) ?></b><span>في طابور إعادة المحاولة</span></div>
      <div class="nh-box <?= $health['gave_up'] > 0 ? 'bad' : '' ?>"><b><?= intf($health['gave_up']) ?></b><span>وقفنا نحاول فيهم (<?= intf(\App\Models\PushNotify::MAX_ATTEMPTS) ?> محاولات)</span></div>
      <div class="nh-box"><b><?= intf($health['burst']) ?></b><span>اتكتموا بحارس الانفجار<br></span></div>
      <div class="nh-box"><b><?= intf($health['muted']) ?></b><span>مقفولين بتفضيل شخصي</span></div>
      <div class="nh-box <?= $health['last_run'] ? 'good' : 'bad' ?>">
        <b style="font-size:var(--fs-3)"><?= $health['last_run'] ? e((string) $health['last_run']) : 'مفيش' ?></b>
        <span>آخر تشغيل للعامل (الكرون)</span>
      </div>
    </div>

    <?php if ($health['errors']): ?>
      <div class="section-head" style="margin:0 0 8px"><?= icon('alert', 15) ?> آخر أسباب الفشل</div>
      <div class="table-wrap"><table class="data">
        <thead><tr><th>السبب</th><th>عدد</th></tr></thead>
        <tbody>
        <?php foreach ($health['errors'] as $er): ?>
          <tr><td style="font-size:var(--fs-2)"><?= e((string) $er['err']) ?></td><td><?= intf((int) $er['c']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>

    <?php if ($readStats): ?>
      <?php /* ⚠️ الفايدة الحقيقية: النوع اللي نسبة قراءته صفر معناه إشعار مالوش
               لازمة — يتقفل من «إعداداتي» بدل ما يفضل يزنّ. */ ?>
      <div class="section-head" style="margin:14px 0 8px"><?= icon('bar-chart', 15) ?> أنهي إشعارات بتتقرا فعلاً (٣٠ يوم)</div>
      <div class="table-wrap"><table class="data">
        <thead><tr><th>النوع</th><th>اتبعت</th><th>اتقرا</th><th>النسبة</th></tr></thead>
        <tbody>
        <?php foreach ($readStats as $s): ?>
          <tr>
            <td><?= e($s['label']) ?></td>
            <td><?= intf($s['total']) ?></td>
            <td><?= intf($s['read']) ?></td>
            <td><?= e((string) $s['rate']) ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ③ الأجهزة المفعّلة ---------------------------------------------------- -->
<div class="card mb">
  <div class="section-head" style="padding:16px 18px 0;margin:0"><?= icon('send', 17) ?> الأجهزة المفعّلة</div>
  <?php if (!$devices): ?>
    <?php partial('empty', ['emoji' => '📭', 'title' => 'مفيش أي جهاز فعّل الإشعارات لسه',]); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">صاحبه</th><th>النوع</th><th>الجهاز</th><th>آخر إشعار وصله</th><th>الحالة</th>
    </tr></thead>
    <tbody>
    <?php foreach ($devices as $d): ?>
      <tr>
        <td><?= e($d['rep_name'] ?? $d['user_name'] ?? '—') ?></td>
        <td><?= $d['audience'] === 'rep' ? 'مندوب' : 'مكتب' ?></td>
        <td style="font-size:var(--fs-2);color:var(--grey)"><?= e(mb_substr((string) $d['user_agent'], 0, 60)) ?></td>
        <td><?= $d['last_ok_at'] ? e((string) $d['last_ok_at']) : '—' ?></td>
        <td><?= (int) $d['is_active'] ? '<span class="pill ok">شغّال</span>' : '<span class="pill off">متوقف</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<!-- ④ سجل الإشعارات ------------------------------------------------------ -->
<div class="card">
  <div class="section-head" style="padding:16px 18px 0;margin:0"><?= icon('file-text', 17) ?> ️ آخر الإشعارات اللي اتبعتت</div>
  <?php if (!$log): ?>
    <?php partial('empty', ['emoji' => '🕊️', 'title' => 'لسه مفيش إشعارات اتبعتت']); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>الوقت</th><th class="g-identity">الإشعار</th><th>لمين</th><th>وصل</th><th>فشل</th></tr></thead>
    <tbody>
    <?php foreach ($log as $m): ?>
      <tr>
        <td style="white-space:nowrap"><?= e(dtfmt($m['created_at'], 'd/m/Y H:i:s')) ?></td>
        <td><b><?= e($m['title']) ?></b><br><span style="color:var(--grey);font-size:var(--fs-2)"><?= e($m['body']) ?></span></td>
        <td><?= e($m['audience']) ?></td>
        <td class="pos"><?= (int) $m['ok_count'] ?></td>
        <td class="<?= (int) $m['fail_count'] ? 'neg' : '' ?>"><?= (int) $m['fail_count'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<script>
(function () {
  'use strict';
  var state = document.getElementById('pushState');
  var on = document.getElementById('pushEnable');
  var off = document.getElementById('pushDisable');
  if (!window.almPush) { state.textContent = 'المتصفح ده ما بيدعمش إشعارات التطبيق.'; return; }

  function paint(s) {
    state.textContent = s.message;
    on.hidden = !s.canEnable;
    off.hidden = !s.subscribed;
  }
  window.almPush.state().then(paint);
  on.addEventListener('click', function () {
    on.disabled = true;
    window.almPush.enable().then(paint).finally(function () { on.disabled = false; });
  });
  off.addEventListener('click', function () {
    off.disabled = true;
    window.almPush.disable().then(paint).finally(function () { off.disabled = false; });
  });
})();
</script>
