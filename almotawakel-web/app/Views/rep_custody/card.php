<?php
use App\Core\FormState;
$depositState = $depositState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'rep-custody/'.(int)$rep['id']];
$settleState = $settleState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'rep-custody/'.(int)$rep['id']];
$accountState = $accountState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'rep-custody/'.(int)$rep['id']];
$resetState = $resetState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'rep-custody/'.(int)$rep['id']];
$assignState = $assignState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'rep-custody/'.(int)$rep['id']];
$depositOld = $depositState['old']; $depositErrors = $depositState['errors'];
$settleOld = $settleState['old']; $settleErrors = $settleState['errors'];
$accountOld = $accountState['old']; $accountErrors = $accountState['errors'];
$assignOld = $assignState['old']; $assignErrors = $assignState['errors'];
$selectedPermits = array_map('intval', (array) ($assignOld['permit_ids'] ?? []));
?>
<div class="toolbar">
  <div class="section-head" style="margin:0">🧑‍💼 عهدة: <?= e($rep['name']) ?></div>
  <div class="spacer"></div>
  <a class="btn ghost" href="<?= url('reps/' . $rep['id']) ?>">كشف المرتبات والمكافآت</a>
  <a class="btn ghost" href="<?= url('rep-custody') ?>">‹ كل المناديب</a>
</div>

<div class="kpi-grid mb four">
  <div class="kpi gold">
    <div class="lbl"><?= $b['balance'] < 0 ? 'مستحق للمندوب' : 'الفاضل معاه دلوقتي' ?></div>
    <div class="val" data-secret data-val="<?= e(intf(abs($b['balance']))) ?>">••••••</div>
  </div>
  <div class="kpi"><div class="lbl">المنزَّل له</div><div class="val pos"><?= intf($b['deposits']) ?></div></div>
  <div class="kpi"><div class="lbl">صرفه من عهدته</div><div class="val neg"><?= intf($b['spent']) ?></div></div>
  <div class="kpi"><div class="lbl">رجّعه</div><div class="val"><?= intf($b['returned']) ?></div></div>
</div>

<?php /* تحويلات المناديب (v46) — بتظهر بس لو فيه تحويل، عشان الرصيد يقفل بصريًا */ ?>
<?php if (($b['transfer_in'] ?? 0) > 0 || ($b['transfer_out'] ?? 0) > 0): ?>
  <div class="note mb" style="display:flex;gap:18px;flex-wrap:wrap;font-size:.85rem">
    <?php if (($b['transfer_in'] ?? 0) > 0): ?>
      <span>🔁 استلم من زملائه: <b class="pos"><?= intf($b['transfer_in']) ?></b></span>
    <?php endif; ?>
    <?php if (($b['transfer_out'] ?? 0) > 0): ?>
      <span>🔁 حوّل لزملائه: <b class="neg"><?= intf($b['transfer_out']) ?></b></span>
    <?php endif; ?>
    <span style="color:var(--grey)">التحويل داخلي بين المناديب — الخزنة ما بتتأثرش.</span>
  </div>
<?php endif; ?>

<?php if ($cred): ?>
  <div class="card mb" style="border:2px solid var(--green);background:var(--green-lt)">
    <div style="padding:16px 18px">
      <div class="section-head" style="margin:0 0 10px">✅ بيانات الدخول جاهزة — ابعتهاله دلوقتي</div>

      <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <div><div style="font-size:.75rem;color:var(--grey);font-weight:700">اسم الدخول</div>
          <code style="font-size:1.1rem;font-weight:800"><?= e($cred['username']) ?></code></div>
        <div><div style="font-size:.75rem;color:var(--grey);font-weight:700">الرقم السري</div>
          <code style="font-size:1.4rem;font-weight:900;letter-spacing:.15em"><?= e($cred['pin']) ?></code></div>
        <div><div style="font-size:.75rem;color:var(--grey);font-weight:700">الرابط</div>
          <code style="font-size:.9rem"><?= e($cred['link']) ?></code></div>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php if ($cred['wa'] !== ''): ?>
          <a class="btn" style="background:#25D366;color:#fff" target="_blank" rel="noopener"
             href="<?= e($cred['wa']) ?>">📱 ابعتهاله على واتساب</a>
        <?php else: ?>
          <span class="pill st-late">مفيش رقم موبايل صح للمندوب —
            <a href="<?= url('reps/' . $rep['id'] . '/edit') ?>">ضيفه الأول</a></span>
        <?php endif; ?>
        <button type="button" class="btn ghost" id="copyCred">📋 انسخ الرسالة</button>
      </div>

      <details style="margin-top:12px">
        <summary style="cursor:pointer;font-size:.85rem;font-weight:700">شوف نص الرسالة</summary>
        <pre id="credMsg" style="white-space:pre-wrap;background:#fff;border:1px solid var(--line);
             border-radius:9px;padding:12px;margin-top:8px;font-family:inherit;font-size:.85rem;line-height:1.7"><?= e($cred['message']) ?></pre>
      </details>

      <div style="margin-top:12px;font-size:.82rem;color:var(--red);font-weight:700">
        ⚠️ الرقم السري ده مش هتقدر تشوفه تاني بعد ما تسيب الصفحة — متشفّر في القاعدة.
        لو ضاع منه، دوس «رقم سري جديد» واعمل واحد تاني.
      </div>
      <?php if (!$cred['public']): ?>
        <div style="margin-top:8px;font-size:.82rem;color:var(--sader);font-weight:700">
          ⚠️ الرابط ده محلي — المندوب مش هيقدر يفتحه من برّه المكتب.
          شغّل «تشغيل-عام-على-النت» الأول عشان الرابط يبقى عام، وبعدين ابعت.
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
  document.getElementById('copyCred').addEventListener('click', function () {
    var t = document.getElementById('credMsg').textContent;
    navigator.clipboard.writeText(t).then(function () {
      var b = document.getElementById('copyCred');
      b.textContent = '✓ اتنسخت'; setTimeout(function () { b.textContent = '📋 انسخ الرسالة'; }, 1800);
    });
  });
  </script>
<?php endif; ?>

<?php if ($pending): ?>
  <div class="note mb" style="background:var(--gold-light);border-color:var(--gold)">
    ⏳ <strong><?= intf(count($pending)) ?> بند</strong> مستني مراجعتك من المندوب ده
    (<?= money(array_sum(array_column($pending, 'amount')), false) ?>).
    <a href="<?= url('rep-custody/inbox') ?>"><strong>راجعهم دلوقتي ←</strong></a>
  </div>
<?php endif; ?>

<div class="grid-2 mb" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

  <!-- حساب الدخول من الموبايل -->
  <div class="card">
    <div class="section-head" style="padding:16px 18px 0;margin:0">📱 دخول المندوب من موبايله</div>
    <div style="padding:14px 18px 0" id="account-reset-form">
      <?= formErrorSummary($resetState['errors']) ?>
      <form method="post" action="<?= url('rep-custody/' . $rep['id'] . '/account/reset') ?>" style="margin:0">
        <?= csrfField() ?>
        <?= formStateFields($resetState['token'], $resetState['back']) ?>
        <button class="btn gold" type="submit" style="width:100%" data-primary-submit>
          🎲 <?= $account ? 'رقم سري جديد وابعتهوله' : 'اعمل الحساب وابعتهوله واتساب' ?>
        </button>
      </form>
      <div style="font-size:.78rem;color:var(--grey);margin-top:6px;text-align:center">
        بضغطة واحدة: بيولّد رقم سري ويجهّزلك رسالة الواتساب بالرابط والتعليمات.
      </div>
    </div>

    <div style="padding:14px 18px 16px" id="account-form">
      <div style="display:flex;align-items:center;gap:10px;margin:6px 0 12px">
        <hr style="flex:1;border:0;border-top:1px solid var(--line)">
        <span style="font-size:.78rem;color:var(--grey);font-weight:700">أو اكتبها بنفسك</span>
        <hr style="flex:1;border:0;border-top:1px solid var(--line)">
      </div>

      <?= formErrorSummary($accountErrors) ?>
      <form method="post" action="<?= url('rep-custody/' . $rep['id'] . '/account') ?>" autocomplete="off" class="js-dirty-form">
        <?= csrfField() ?>
        <?= formStateFields($accountState['token'], $accountState['back']) ?>
        <div class="field">
          <label>اسم الدخول</label>
          <input type="text" name="username" required autocapitalize="none" spellcheck="false"
                 value="<?= e($accountOld['username'] ?? $account['username'] ?? \App\Core\RepAuth::suggestUsername((int) $rep['id'])) ?>"
                 placeholder="حروف إنجليزية وأرقام"<?= fieldErrorAttrs('username', $accountErrors) ?>>
          <?= fieldError('username', $accountErrors) ?>
        </div>
        <div class="field">
          <?php $pinMin = \App\Core\RepAuth::PIN_MIN; $pinMax = \App\Core\RepAuth::PIN_MAX; ?>
          <label>الرقم السري<?= $account ? ' الجديد' : '' ?> (<?= $pinMin ?>–<?= $pinMax ?> رقم)</label>
          <input type="text" name="pin" inputmode="numeric" pattern="[0-9]{<?= $pinMin ?>,<?= $pinMax ?>}"
                 <?= $account ? '' : 'required' ?>
                 placeholder="<?= $account ? 'سيبه فاضي عشان ما يتغيّرش' : 'مثلاً 482630' ?>"<?= fieldErrorAttrs('pin', $accountErrors) ?>>
          <?= fieldError('pin', $accountErrors) ?>
          <?php if ($account): ?>
            <div style="font-size:.76rem;color:var(--grey);margin-top:5px">
              سيبه فاضي لو عايز تغيّر <strong>اسم الدخول بس</strong> — الرقم السري هيفضل زي ما هو.
            </div>
          <?php endif; ?>
        </div>
        <button class="btn" type="submit" data-primary-submit><?= $account ? '💾 احفظ التعديل' : '➕ اعمل الحساب' ?></button>
      </form>
    </div>
    <?php if ($account && !empty($account['weak_pin'])): ?>
      <?php /* v48: الرقم السري ده اتعمل أيام قاعدة الـ4 أرقام. مش بنقفل الحساب
                عشان الشغل الميداني ما يقفش — بننبّه المالك يجدّده بضغطة. */ ?>
      <div style="margin:0 18px 14px;padding:11px 13px;border:1px solid #e5c86e;border-radius:10px;background:#fff8dc;font-size:.82rem;line-height:1.6">
        <strong style="color:#8a3b22">⚠️ الرقم السري بتاعه قديم وضعيف (4 أرقام).</strong>
        دوس <strong>«🎲 رقم سري جديد وابعتهوله»</strong> فوق — هيتولّد رقم من <?= \App\Core\RepAuth::PIN_MIN ?> أرقام
        ويتبعتله على الواتساب على طول.
      </div>
    <?php endif; ?>
    <?php if ($account): ?>
      <div style="padding:0 18px 16px;font-size:.8rem;color:var(--grey)">
        الحالة:
        <?= $account['status'] === 'active'
              ? '<span class="pill st-done">شغّال</span>'
              : '<span class="pill st-late">متوقف</span>' ?>
        · آخر دخول: <?= $account['last_login_at'] ? e(dfmt(substr($account['last_login_at'], 0, 10))) : 'عمره ما دخل' ?>
        <form method="post" action="<?= url('rep-custody/' . $rep['id'] . '/account/toggle') ?>" style="display:inline">
          <?= csrfField() ?>
          <?= formStateFields(FormState::token(), 'rep-custody/'.(int)$rep['id']) ?>
          <button class="edit" type="submit"><?= $account['status'] === 'active' ? '⏸ أوقف الدخول' : '▶️ فعّل' ?></button>
        </form>
        <div style="margin-top:8px">
          الرابط اللي يفتحه: <code><?= e(rtrim(public_base_url() ?: url(''), '/')) ?>/m</code>
        </div>
      </div>
    <?php endif; ?>

    <?php /* صلاحية فتح إذن من الموبايل (v44) — وصفية بحتة، مالهاش أي أثر مالي.
             المنع الحقيقي في RepPortal::createPermit مش في إخفاء الزرار. */ ?>
    <div style="padding:0 18px 16px" id="permit-perm">
      <hr style="border:0;border-top:1px solid var(--line);margin:0 0 12px">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div style="flex:1;min-width:180px">
          <div style="font-weight:800">📄 يفتح إذن من موبايله؟</div>
          <div style="font-size:.78rem;color:var(--grey);margin-top:3px">
            <?php if (!$permPermReady): ?>
              شغّل <code>php bin/migrate_v44.php</code> الأول عشان تتحكّم في الصلاحية دي.
            <?php elseif ($canAddPermit): ?>
              مسموح له — بيقدر يسجّل إذن أو شهادة جديدة بنفسه (بلا أي أرقام مالية).
            <?php else: ?>
              ممنوع — بيشوف الأذونات المفتوحة ويسجّل عليها بس.
            <?php endif; ?>
          </div>
        </div>
        <?= $permPermReady && $canAddPermit
              ? '<span class="pill st-done">مسموح</span>'
              : '<span class="pill st-late">ممنوع</span>' ?>
        <?php if ($permPermReady): ?>
          <form method="post" action="<?= url('rep-custody/' . (int) $rep['id'] . '/permit-perm') ?>" style="margin:0">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), 'rep-custody/'.(int)$rep['id'].'#permit-perm') ?>
            <button class="edit" type="submit"><?= $canAddPermit ? '⛔ اسحب الصلاحية' : '✅ اسمح له' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php /* v47 — متابعة المناديب: عرض فقط، الاعتماد والتنزيل يفضلوا هنا في المكتب */ ?>
    <div style="padding:0 18px 16px" id="watch-perm">
      <hr style="border:0;border-top:1px solid var(--line);margin:0 0 12px">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div style="flex:1;min-width:180px">
          <div style="font-weight:800">👀 يتابع باقي المناديب من موبايله؟</div>
          <div style="font-size:.78rem;color:var(--grey);margin-top:3px">
            <?php if (!$watchPermReady): ?>
              شغّل <code>php bin/migrate_v47.php</code> الأول عشان تتحكّم في الصلاحية دي.
            <?php elseif ($canWatchTeam): ?>
              مسموح — بيشوف عهدة كل مندوب وصرفه وإدخالاته. <b>عرض فقط</b>: مش بيعتمد ولا بيرفض ولا بينزّل عهدة.
            <?php else: ?>
              ممنوع — بيشوف شغله هو بس.
            <?php endif; ?>
          </div>
        </div>
        <?= $watchPermReady && $canWatchTeam
              ? '<span class="pill st-done">مسموح</span>'
              : '<span class="pill st-late">ممنوع</span>' ?>
        <?php if ($watchPermReady): ?>
          <form method="post" action="<?= url('rep-custody/' . (int) $rep['id'] . '/watch-perm') ?>" style="margin:0">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), 'rep-custody/'.(int)$rep['id'].'#watch-perm') ?>
            <button class="edit" type="submit"><?= $canWatchTeam ? '⛔ اسحب المتابعة' : '✅ خليه يتابع' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- تنزيل الأذونات -->
  <div class="card" id="assign-form">
    <div class="section-head" style="padding:16px 18px 0;margin:0">📋 نزّل أذونات للمندوب</div>
    <?= formErrorSummary($assignErrors) ?>
    <form method="post" action="<?= url('rep-custody/' . $rep['id'] . '/assign') ?>" style="padding:14px 18px" id="asgForm">
      <?= csrfField() ?>
      <?= formStateFields($assignState['token'], $assignState['back']) ?>

      <?php if (!$openPermits): ?>
        <div class="empty" style="padding:22px 10px"><div class="ic">📭</div>
          مفيش أذونات <strong>مفتوحة</strong> متاحة للتنزيل.
          <div style="font-size:.8rem;color:var(--grey);margin-top:6px">
            الإذن لازم يكون مفتوح وليه حركة عشان ينفع تنزّله.
          </div>
        </div>
      <?php else: ?>
        <input type="search" class="asg-q" placeholder="🔍 دوّر برقم الإذن أو الشركة أو العميل أو الوجهة…"
               autocomplete="off" aria-label="بحث في الأذونات المتاحة">

        <div class="asg-list">
          <?php foreach ($openPermits as $p): ?>
            <?php
              // نص البحث: كل حاجة ظاهرة في الكارت عشان البحث يلاقيها
              $hay = mb_strtolower(trim(implode(' ', array_filter([
                  $p['permit_no'], $p['company'], $p['client'], $p['destination'],
                  \App\Models\Permit::noLabel($p['permit_type']),
              ]))));
            ?>
            <label class="asg-item" data-q="<?= e($hay) ?>">
              <input type="checkbox" name="permit_ids[]" value="<?= (int) $p['id'] ?>" class="js-asg"
                     <?= in_array((int) $p['id'], $selectedPermits, true) ? 'checked' : '' ?><?= fieldErrorAttrs('permit_ids', $assignErrors) ?>>
              <span class="asg-body">
                <span class="asg-top">
                  <strong><?= e(\App\Models\Permit::noLabel($p['permit_type'])) ?>
                          <?= e($p['permit_no'] ?: '#' . $p['id']) ?></strong>
                  <?php if ((int) $p['containers']): ?>
                    <span class="pill st-partial">📦 <?= (int) $p['containers'] ?><?=
                      $p['container_type'] ? ' × ' . e(container_norm($p['container_type'])) : '' ?></span>
                  <?php endif; ?>
                  <?php if ((int) $p['other_reps']): ?>
                    <span class="pill st-partial" title="منزّل كمان لمندوب تاني">🧑‍💼 <?= (int) $p['other_reps'] ?></span>
                  <?php endif; ?>
                </span>
                <span class="asg-sub">
                  <?php if (!empty($p['company'])): ?><span>🏢 <?= e($p['company']) ?></span><?php endif; ?>
                  <?php if (!empty($p['client']) && $p['client'] !== $p['company']): ?>
                    <span>· 👤 <?= e($p['client']) ?></span><?php endif; ?>
                  <?php if (!empty($p['destination'])): ?><span>· 📍 <?= e($p['destination']) ?></span><?php endif; ?>
                  <span>· 📅 <?= e(dfmt($p['permit_date'])) ?></span>
                  <span>· <?= (int) $p['movs'] ?> حركة</span>
                </span>
              </span>
            </label>
          <?php endforeach; ?>
          <div class="asg-none" hidden>مفيش إذن بالبحث ده.</div>
        </div>
        <?= fieldError('permit_ids', $assignErrors) ?>

        <div class="field" style="margin-top:10px"><label>ملاحظة للمندوب (اختيارية)</label>
          <input type="text" name="note" maxlength="255" value="<?= e($assignOld['note'] ?? '') ?>"
                 placeholder="مثلاً: خلّص التعتيق الأول"<?= fieldErrorAttrs('note', $assignErrors) ?>>
          <?= fieldError('note', $assignErrors) ?></div>

        <div class="asg-foot">
          <button class="btn" type="submit" id="asgGo" disabled data-primary-submit>➕ نزّلهم له</button>
          <span class="asg-count">ما اخترتش حاجة</span>
        </div>
        <div style="font-size:.78rem;color:var(--grey);margin-top:8px">
          هيلاقيهم على موبايله فورًا. <strong>الأذونات المقفولة مش بتظهر هنا</strong> —
          الإذن لازم يكون مفتوح عشان ينفع تنزّله.
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- الأذونات المنزّلة -->
<div class="card mb">
  <div class="section-head" style="padding:16px 18px 0;margin:0">
    📋 أذوناته (<?= intf(count($assigns)) ?>)
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">الإذن</th>
      <th class="g-identity">العميل / الشركة</th>
      <th class="g-identity">بنوده</th>
      <th class="g-identity">الحالة</th>
      <th class="g-identity" data-no-sort></th>
    </tr></thead>
    <tbody>
      <?php if (!$assigns): ?>
        <tr><td colspan="5"><div class="empty"><div class="ic">📋</div>
          مفيش أذونات منزّلة له — شاشته هتبقى فاضية لحد ما تنزّله.</div></td></tr>
      <?php else: foreach ($assigns as $a): ?>
        <tr<?= $a['status'] === 'done' ? ' style="opacity:.55"' : '' ?>>
          <td style="text-align:right;font-weight:700">
            <a href="<?= url('permits/' . $a['permit_id'] . '/manage') ?>">
              <?= e(\App\Models\Permit::noLabel($a['permit_type'] ?? 'sader')) ?>
              <?= e($a['permit_no'] ?: '#' . $a['permit_id']) ?></a>
          </td>
          <td style="text-align:right">
            <?php if (!empty($a['company'])): ?>
              <div style="font-weight:800">🏢 <?= e($a['company']) ?></div>
            <?php endif; ?>
            <?php if (!empty($a['client']) && $a['client'] !== $a['company']): ?>
              <div style="font-size:.8rem;color:var(--grey)">👤 <?= e($a['client']) ?></div>
            <?php elseif (empty($a['company']) && empty($a['client'])): ?>—<?php endif; ?>
          </td>
          <td><?= intf((int) $a['subs']) ?></td>
          <td>
            <?= $a['status'] === 'active'
                  ? '<span class="pill st-done">نشط</span>'
                  : '<span class="pill st-partial">مقفول</span>' ?>
            <?php /* الإذن ممكن يتقفل بعد ما اتنزّل — نبّه المالك بدل ما التنزيل
                     يفضل «نشط» على إذن مقفول من غير ما حد ياخد باله. */ ?>
            <?php if ($a['status'] === 'active' && ($a['permit_status'] ?? 'open') !== 'open'): ?>
              <span class="pill st-late" title="الإذن نفسه اتقفل — اقفل التنزيل أو افتح الإذن">⚠️ الإذن مقفول</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <?php $act = $a['status'] === 'active' ? 'done' : 'back'; ?>
            <form method="post" action="<?= url('rep-custody/assign/' . $a['id'] . '/' . $act) ?>" style="display:inline">
              <?= csrfField() ?>
              <?= formStateFields(FormState::token(), 'rep-custody/'.(int)$rep['id']) ?>
              <input type="hidden" name="rep_id" value="<?= (int) $rep['id'] ?>">
              <button class="edit" type="submit" title="<?= $a['status'] === 'active' ? 'اقفل التنزيل' : 'رجّعه نشط' ?>">
                <?= $a['status'] === 'active' ? '⏹' : '▶️' ?></button>
            </form>
            <?php if ((int) $a['subs'] === 0): ?>
              <form method="post" action="<?= url('rep-custody/assign/' . $a['id'] . '/remove') ?>"
                    class="confirm-delete" data-confirm="تشيل التنزيل ده؟" style="display:inline">
                <?= csrfField() ?>
                <?= formStateFields(FormState::token(), 'rep-custody/'.(int)$rep['id']) ?>
                <input type="hidden" name="rep_id" value="<?= (int) $rep['id'] ?>">
                <button class="edit" type="submit" title="شيل">🗑</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="grid-2 mb" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

  <!-- تنزيل عهدة -->
  <div class="card" id="deposit-form">
    <div class="section-head" style="padding:16px 18px 0;margin:0">💰 تنزيل عهدة</div>
    <?= formErrorSummary($depositErrors) ?>
    <form class="js-dirty-form" method="post" action="<?= url('rep-custody/' . $rep['id'] . '/deposit') ?>" style="padding:14px 18px">
      <?= csrfField() ?>
      <?= formStateFields($depositState['token'], $depositState['back']) ?>
      <div class="field"><label>المبلغ</label>
        <input type="number" step="0.01" min="0.01" name="amount" value="<?= e($depositOld['amount'] ?? '') ?>" required placeholder="مثلاً 2000"<?= fieldErrorAttrs('amount', $depositErrors) ?>><?= fieldError('amount', $depositErrors) ?></div>
      <div class="field"><label>التاريخ</label>
        <input type="date" name="dep_date" value="<?= e($depositOld['dep_date'] ?? work_date()) ?>"<?= fieldErrorAttrs('dep_date', $depositErrors) ?>><?= fieldError('dep_date', $depositErrors) ?></div>
      <div class="field"><label>ملاحظة (اختياري)</label>
        <input type="text" name="note" value="<?= e($depositOld['note'] ?? '') ?>" maxlength="255" placeholder="عهدة يوم…"></div>
      <button class="btn gold" type="submit" data-primary-submit>➕ نزّل العهدة</button>
      <div style="font-size:.78rem;color:var(--grey);margin-top:8px">
        ما بيخصمش من خزنة المكتب — عهدة المناديب حساب منفصل.
      </div>
    </form>
  </div>

  <!-- تسوية -->
  <div class="card" id="settle-form">
    <div class="section-head" style="padding:16px 18px 0;margin:0">🤝 تسوية الحساب</div>
    <?= formErrorSummary($settleErrors) ?>
    <form class="js-dirty-form" method="post" action="<?= url('rep-custody/' . $rep['id'] . '/settle') ?>" style="padding:14px 18px">
      <?= csrfField() ?>
      <?= formStateFields($settleState['token'], $settleState['back']) ?>
      <?php if ($b['balance'] < 0): ?>
        <div class="note mb">الرصيد سالب: الشركة مستحق عليها للمندوب <?= money(abs($b['balance']), false) ?>. هذا وضع عمل مسموح، ولا يوجد مبلغ يرجعه الآن.</div>
      <?php endif; ?>
      <div class="field"><label>رجّع كام؟ (<?= $b['balance'] < 0 ? 'مستحق له ' . money(abs($b['balance']), false) : 'الباقي معاه ' . money($b['balance'], false) ?>)</label>
        <input type="number" step="0.01" min="0" name="returned" required
               max="<?= e((string) max($b['balance'], 0)) ?>" value="<?= e($settleOld['returned'] ?? (string) max($b['balance'], 0)) ?>"<?= fieldErrorAttrs('returned', $settleErrors) ?>><?= fieldError('returned', $settleErrors) ?></div>
      <div class="field"><label>تاريخ التسوية</label>
        <input type="date" name="settle_date" value="<?= e($settleOld['settle_date'] ?? work_date()) ?>"<?= fieldErrorAttrs('settle_date', $settleErrors) ?>><?= fieldError('settle_date', $settleErrors) ?></div>
      <div class="note mb" style="font-size:.8rem">
        المرتجع بيقفل حساب العهدة مع المندوب بس — <b>مش بيتضاف للخزنة</b>، لأن الفلوس
        دي أصلاً ماخرجتش منها: الخزنة بتنقص وقت ما المندوب يصرف، مش وقت ما تنزّلها له.
      </div>
      <div class="field"><label>ملاحظة (اختياري)</label>
        <input type="text" name="note" value="<?= e($settleOld['note'] ?? '') ?>" maxlength="255"></div>
      <button class="btn" type="submit" data-primary-submit <?= $b['balance'] <= 0 ? 'disabled' : '' ?>>🤝 سجّل التسوية</button>
      <div style="font-size:.78rem;color:var(--grey);margin-top:8px">
        التسوية تقفل عهدة المندوب فقط ولا تغيّر رصيد الخزنة.
      </div>
    </form>
  </div>
</div>

<!-- اللي صرفه على الأذونات -->
<div class="card mb">
  <div class="section-head" style="padding:16px 18px 0;margin:0">
    🧾 اللي دفعه من عهدته (<?= intf(count($spending)) ?> بند)
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ</th>
      <th class="g-identity">الإذن</th>
      <th class="g-identity">البيان</th>
      <th class="g-identity">رقم الإيصال</th>
      <th class="g-identity">الكمية</th>
      <th class="g-expense">المدفوع</th>
    </tr></thead>
    <tbody>
      <?php if (!$spending): ?>
        <tr><td colspan="6"><div class="empty"><div class="ic">🧾</div>لسه ما صرفش حاجة من عهدته.</div></td></tr>
      <?php else: foreach ($spending as $s): ?>
        <tr>
          <td><?= e(dfmt($s['d'])) ?></td>
          <td style="text-align:right">
            <?php $src = $s['src'] ?? 'permit'; ?>
            <?php if ($src === 'misc'): ?>
              <?php /* نثرية (v39) — مالهاش إذن أصلاً، فمفيش رابط نبنيه */ ?>
              <span class="pill st-partial">🧷 <?= e($s['category'] ?: 'نثريات') ?></span>
              <div style="font-size:.75rem;color:var(--grey)">مصروف عام بلا إذن</div>
            <?php elseif ($src === 'handover'): ?>
              <?php /* تسليم سائق (v45) — بيتخصم من عهدة المندوب وبس */ ?>
              <span class="pill st-partial">🚚 تسليم سائق</span>
              <div style="font-size:.75rem;color:var(--grey)">
                <?= e($s['driver_name'] ?: '—') ?><?= !empty($s['bill_number']) ? ' · بوليصة ' . e($s['bill_number']) : '' ?>
              </div>
            <?php elseif ($src === 'transfer'): ?>
              <?php /* تحويل لمندوب (v46) — بيتخصم من عهدته ويتزاد لعهدة الزميل */ ?>
              <span class="pill st-partial">🔁 تحويل لمندوب</span>
              <div style="font-size:.75rem;color:var(--grey)">لـ <?= e($s['other_name'] ?? '—') ?></div>
            <?php else: ?>
              <a href="<?= url('permits/' . $s['permit_id']) ?>">
                <?= e(\App\Models\Permit::noLabel($s['permit_type'])) ?>
                <?= e($s['permit_no'] ?: '#' . $s['permit_id']) ?></a>
              <div style="font-size:.75rem;color:var(--grey)"><?= e($s['client'] ?: $s['company'] ?: '—') ?></div>
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <?= e($s['note'] ?: '—') ?>
            <?php if (!empty($s['is_visa'])): ?>
              <span class="pill" title="مدفوع بكارت الفيزا — اتخصم من الكارت مش من عهدته">💳 فيزا</span>
            <?php endif; ?>
          </td>
          <td><?= e($s['ref_no'] ?: '—') ?></td>
          <td><?= intf((int) $s['qty']) ?></td>
          <td class="<?= empty($s['is_visa']) ? 'neg' : '' ?>" style="font-weight:700">
            <?= money($s['amount'], false) ?>
            <?php if (!empty($s['is_visa'])): ?>
              <div style="font-size:.7rem;color:var(--grey);font-weight:400">مش من العهدة</div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
  <?php /* التحويلات الواردة من زملاء (v46) — بتزيد رصيده، فبتتعرض جنب التنزيلات */ ?>
  <?php if (!empty($transfersIn)): ?>
  <div class="card">
    <div class="section-head" style="padding:16px 18px 0;margin:0">🔁 تحويلات وصلته من زملاء</div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-revenue">المبلغ</th>
        <th class="g-identity">من</th><th class="g-identity">ملاحظة</th><th class="g-identity" data-no-sort></th></tr></thead>
      <tbody>
        <?php foreach ($transfersIn as $t): ?>
          <tr>
            <td><?= e(dfmt($t['transfer_date'])) ?></td>
            <td class="pos" style="font-weight:700"><?= money($t['amount'], false) ?></td>
            <td style="text-align:right"><?= e($t['other_name'] ?? '—') ?></td>
            <td style="text-align:right"><?= e($t['note'] ?: '—') ?></td>
            <td>
              <form method="post" action="<?= url('rep-custody/transfer/' . (int) $t['id'] . '/delete') ?>"
                    class="confirm-delete" data-confirm="إلغاء التحويل ده؟ هيرجع لعهدة المُرسِل." style="display:inline">
                <?= csrfField() ?>
                <?= formStateFields(FormState::token(), 'rep-custody/'.(int)$rep['id']) ?>
                <button class="edit" type="submit" title="إلغاء التحويل">🗑</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <!-- التنزيلات -->
  <div class="card">
    <div class="section-head" style="padding:16px 18px 0;margin:0">💰 تنزيلات العهدة</div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-revenue">المبلغ</th>
        <th class="g-identity">ملاحظة</th><th class="g-identity" data-no-sort></th></tr></thead>
      <tbody>
        <?php if (!$deposits): ?>
          <tr><td colspan="4"><div class="empty">لا تنزيلات بعد.</div></td></tr>
        <?php else: foreach ($deposits as $d): ?>
          <tr>
            <td><?= e(dfmt($d['dep_date'])) ?></td>
            <td class="pos" style="font-weight:700"><?= money($d['amount'], false) ?></td>
            <td style="text-align:right"><?= e($d['note'] ?: '—') ?></td>
            <td>
              <form method="post" action="<?= url('rep-custody/deposit/' . $d['id'] . '/delete') ?>"
                    class="confirm-delete" data-confirm="حذف تنزيل العهدة ده؟" style="display:inline">
                <?= csrfField() ?>
                <?= formStateFields(FormState::token(), 'rep-custody/'.(int)$rep['id']) ?>
                <button class="edit" type="submit" title="حذف">🗑</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- التسويات -->
  <div class="card">
    <div class="section-head" style="padding:16px 18px 0;margin:0">🤝 التسويات</div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-expense">رجّع</th>
        <th class="g-identity">أثر خزنة تاريخي</th><th class="g-identity" data-no-sort></th></tr></thead>
      <tbody>
        <?php if (!$settlements): ?>
          <tr><td colspan="4"><div class="empty">لا تسويات بعد.</div></td></tr>
        <?php else: foreach ($settlements as $s): ?>
          <tr>
            <td><?= e(dfmt($s['settle_date'])) ?></td>
            <td style="font-weight:700"><?= money($s['returned'], false) ?></td>
            <td><?= ((int) $s['to_custody'] === 1)
                  ? '<span class="pill st-done">ترحيل قديم</span>'
                  : '<span class="pill st-partial">بدون أثر</span>' ?></td>
            <td>
              <form method="post" action="<?= url('rep-custody/settlement/' . $s['id'] . '/delete') ?>"
                    class="confirm-delete" data-confirm="حذف التسوية دي؟" style="display:inline">
                <?= csrfField() ?>
                <?= formStateFields(FormState::token(), 'rep-custody/'.(int)$rep['id']) ?>
                <button class="edit" type="submit" title="حذف">🗑</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<style>
/* ── قائمة تنزيل الأذونات: بحث + كروت قابلة للاختيار ── */
.asg-q{width:100%;padding:9px 12px;border:1px solid var(--line2);border-radius:9px;
  font-family:inherit;font-size:.9rem;background:#fff;margin-bottom:9px}
.asg-q:focus{outline:none;border-color:var(--navy)}

.asg-list{max-height:340px;overflow-y:auto;border:1px solid var(--line);border-radius:10px;background:#fff}
.asg-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;cursor:pointer;
  border-bottom:1px solid var(--line)}
.asg-item:last-of-type{border-bottom:0}
.asg-item:hover{background:var(--zebra)}
.asg-item input{width:17px;height:17px;margin:2px 0 0;flex:none;cursor:pointer}
/* الكارت المختار يبان فورًا — المالك بيختار كذا واحد ولازم يشوف اللي علّمه */
.asg-item:has(input:checked){background:var(--green-lt)}

.asg-body{flex:1;min-width:0}
.asg-top{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:.95rem}
.asg-sub{display:flex;gap:5px;flex-wrap:wrap;font-size:.78rem;color:var(--grey);margin-top:3px}
.asg-none{padding:22px 12px;text-align:center;color:var(--grey);font-size:.88rem}

.asg-foot{display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap}
.asg-count{font-size:.8rem;color:var(--grey);font-weight:700}
.asg-count.on{color:var(--green)}
</style>

<script>
(function () {
  var form = document.getElementById('asgForm');
  if (!form) { return; }
  var q     = form.querySelector('.asg-q');
  var items = Array.prototype.slice.call(form.querySelectorAll('.asg-item'));
  var none  = form.querySelector('.asg-none');
  var go    = document.getElementById('asgGo');
  var lbl   = form.querySelector('.asg-count');
  if (!items.length) { return; }

  // عدّاد المختار + تفعيل الزرار (ما ينفعش يبعت فورم فاضي)
  function sync() {
    var n = form.querySelectorAll('.js-asg:checked').length;
    go.disabled = !n;
    lbl.textContent = n ? ('مختار ' + n + ' إذن') : 'ما اخترتش حاجة';
    lbl.classList.toggle('on', !!n);
  }
  form.querySelectorAll('.js-asg').forEach(function (c) { c.addEventListener('change', sync); });
  sync();

  // بحث حي — المختار بيفضل ظاهر دايمًا عشان ما يضيعش من تحت إيد المالك
  q.addEventListener('input', function () {
    var s = q.value.trim().toLowerCase();
    var shown = 0;
    items.forEach(function (it) {
      var hit = !s || it.dataset.q.indexOf(s) !== -1 || it.querySelector('.js-asg').checked;
      it.hidden = !hit;
      if (hit) { shown++; }
    });
    none.hidden = shown > 0;
  });
})();
</script>
