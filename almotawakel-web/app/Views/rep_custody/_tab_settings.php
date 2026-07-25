<?php
/**
 * تبويب «الدخول والصلاحيات» — حساب موبايل المندوب وصلاحياته.
 *
 * ⚠️ الصلاحيات هنا **وصفية بحتة** — المنع الحقيقي بيتفرض في الموديل
 *    (`RepPortal::createPermit` و`RepWatch::assertAllowed`) مش في إخفاء الزرار.
 *
 * متغيّرات جاية من `card.php`: $repId $rep $account $cred $accountState
 *                              $resetState $canAddPermit $canWatchTeam…
 */
use App\Core\FormState;
$back = 'rep-custody/' . $repId . '?tab=settings';
?>

<?php if ($cred): ?>
  <div class="card mb" style="border:2px solid var(--green);background:var(--green-lt)">
    <div style="padding:16px 18px">
      <div class="section-head" style="margin:0 0 10px"><?= icon('check-circle', 17) ?> بيانات الدخول جاهزة — ابعتهاله دلوقتي</div>

      <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <div><div style="font-size:var(--fs-1);color:var(--grey);font-weight:700">اسم الدخول</div>
          <code style="font-size:var(--fs-4);font-weight:800"><?= e($cred['username']) ?></code></div>
        <div><div style="font-size:var(--fs-1);color:var(--grey);font-weight:700">الرقم السري</div>
          <code style="font-size:var(--fs-6);font-weight:900;letter-spacing:.15em"><?= e($cred['pin']) ?></code></div>
        <div><div style="font-size:var(--fs-1);color:var(--grey);font-weight:700">الرابط</div>
          <code style="font-size:var(--fs-2)"><?= e($cred['link']) ?></code></div>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php if ($cred['wa'] !== ''): ?>
          <a class="btn" style="background:#25D366;color:var(--white)" target="_blank" rel="noopener"
             href="<?= e($cred['wa']) ?>">📱 ابعتهاله على واتساب</a>
        <?php else: ?>
          <span class="pill st-late">مفيش رقم موبايل صح للمندوب —
            <a href="<?= url('reps/' . $repId . '/edit') ?>">ضيفه الأول</a></span>
        <?php endif; ?>
        <button type="button" class="btn ghost" id="copyCred">📋 انسخ الرسالة</button>
      </div>

      <details style="margin-top:12px">
        <summary style="cursor:pointer;font-size:var(--fs-2);font-weight:700">شوف نص الرسالة</summary>
        <pre id="credMsg" style="white-space:pre-wrap;background:var(--card);border:1px solid var(--line);
             border-radius:var(--radius-2);padding:12px;margin-top:8px;font-family:inherit;font-size:var(--fs-2);line-height:1.7"><?= e($cred['message']) ?></pre>
      </details>

      <div style="margin-top:12px;font-size:var(--fs-2);color:var(--red);font-weight:700">
        ⚠️ الرقم السري ده مش هتقدر تشوفه تاني بعد ما تسيب الصفحة — متشفّر في القاعدة.
        لو ضاع منه، دوس «رقم سري جديد» واعمل واحد تاني.
      </div>
      <?php if (!$cred['public']): ?>
        <div style="margin-top:8px;font-size:var(--fs-2);color:var(--sader);font-weight:700">
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

<div class="grid-2">
  <!-- حساب الدخول من الموبايل -->
  <div class="card">
    <div class="section-head" style="padding:16px 18px 0;margin:0"><?= icon('send', 17) ?> دخول المندوب من موبايله</div>
    <div style="padding:14px 18px 0" id="account-reset-form">
      <?= formErrorSummary($resetState['errors']) ?>
      <form method="post" action="<?= url('rep-custody/' . $repId . '/account/reset') ?>" style="margin:0">
        <?= csrfField() ?>
        <?= formStateFields($resetState['token'], $resetState['back']) ?>
        <button class="btn gold" type="submit" style="width:100%" data-primary-submit>
          🎲 <?= $account ? 'رقم سري جديد وابعتهوله' : 'اعمل الحساب وابعتهوله واتساب' ?>
        </button>
      </form>
      <div style="font-size:var(--fs-1);color:var(--grey);margin-top:6px;text-align:center">
        بضغطة واحدة: بيولّد رقم سري ويجهّزلك رسالة الواتساب بالرابط والتعليمات.
      </div>
    </div>

    <div style="padding:14px 18px 16px" id="account-form">
      <div style="display:flex;align-items:center;gap:10px;margin:6px 0 12px">
        <hr style="flex:1;border:0;border-top:1px solid var(--line)">
        <span style="font-size:var(--fs-1);color:var(--grey);font-weight:700">أو اكتبها بنفسك</span>
        <hr style="flex:1;border:0;border-top:1px solid var(--line)">
      </div>

      <?= formErrorSummary($accountErrors) ?>
      <form method="post" action="<?= url('rep-custody/' . $repId . '/account') ?>" autocomplete="off" class="js-dirty-form">
        <?= csrfField() ?>
        <?= formStateFields($accountState['token'], $accountState['back']) ?>
        <div class="field">
          <label>اسم الدخول</label>
          <input type="text" name="username" required autocapitalize="none" spellcheck="false"
                 value="<?= e($accountOld['username'] ?? $account['username'] ?? \App\Core\RepAuth::suggestUsername($repId)) ?>"
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
            <div style="font-size:var(--fs-1);color:var(--grey);margin-top:5px">
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
      <div style="margin:0 18px 14px;padding:11px 13px;border:1px solid #e5c86e;border-radius:var(--radius-3);background:#fff8dc;font-size:var(--fs-2);line-height:1.6">
        <strong style="color:#8a3b22">⚠️ الرقم السري بتاعه قديم وضعيف (4 أرقام).</strong>
        دوس <strong>«🎲 رقم سري جديد وابعتهوله»</strong> فوق — هيتولّد رقم من <?= \App\Core\RepAuth::PIN_MIN ?> أرقام
        ويتبعتله على الواتساب على طول.
      </div>
    <?php endif; ?>

    <?php if ($account): ?>
      <div style="padding:0 18px 16px;font-size:var(--fs-2);color:var(--grey)">
        الحالة:
        <?= $account['status'] === 'active'
              ? '<span class="pill st-done">شغّال</span>'
              : '<span class="pill st-late">متوقف</span>' ?>
        · آخر دخول: <?= $account['last_login_at'] ? e(dfmt(substr($account['last_login_at'], 0, 10))) : 'عمره ما دخل' ?>
        <form method="post" action="<?= url('rep-custody/' . $repId . '/account/toggle') ?>" style="display:inline">
          <?= csrfField() ?>
          <?= formStateFields(FormState::token(), $back) ?>
          <button class="edit" type="submit"><?= $account['status'] === 'active' ? '⏸ أوقف الدخول' : '▶️ فعّل' ?></button>
        </form>
        <div style="margin-top:8px">
          الرابط اللي يفتحه: <code><?= e(rtrim(public_base_url() ?: url(''), '/')) ?>/m</code>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- الصلاحيات -->
  <div class="card">
    <div class="section-head" style="padding:16px 18px 0;margin:0"><?= icon('lock', 17) ?> صلاحياته على الموبايل</div>

    <?php /* صلاحية فتح إذن من الموبايل (v44) — وصفية بحتة، مالهاش أي أثر مالي.
             المنع الحقيقي في RepPortal::createPermit مش في إخفاء الزرار. */ ?>
    <div style="padding:14px 18px 16px" id="permit-perm">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div style="flex:1;min-width:180px">
          <div style="font-weight:800">📄 يفتح إذن من موبايله؟</div>
          <div style="font-size:var(--fs-1);color:var(--grey);margin-top:3px">
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
          <form method="post" action="<?= url('rep-custody/' . $repId . '/permit-perm') ?>" style="margin:0">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), $back . '#permit-perm') ?>
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
          <div style="font-size:var(--fs-1);color:var(--grey);margin-top:3px">
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
          <form method="post" action="<?= url('rep-custody/' . $repId . '/watch-perm') ?>" style="margin:0">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), $back . '#watch-perm') ?>
            <button class="edit" type="submit"><?= $canWatchTeam ? '⛔ اسحب المتابعة' : '✅ خليه يتابع' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
