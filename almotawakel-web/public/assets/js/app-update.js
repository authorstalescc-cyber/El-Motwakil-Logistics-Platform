/*
 * التحديث الإجباري — نظام المكتب **وبوابة المناديب** مع بعض.
 *
 * المشكلة اللي بيحلّها: التخزين المؤقّت في النظام سليم (كل ملف تصميم/جافاسكريبت
 * بيتحمّل بـ`?v=` بيتغيّر مع كل تعديل، والصفحات عمرها ما بتتكاش)، فأي **ريفرش**
 * بياخد آخر نسخة فورًا. اللي بيفضل قديم هو **الشاشة اللي سايبها مفتوحة**:
 * المندوب مقفلش التطبيق من أسبوع، أو الموظف سايب التبويب من الصبح — الكود
 * القديم لسه شغّال في الذاكرة، وممكن ينادي مسارًا اتشال أو يبعت حقلًا اتغيّر.
 *
 * ⚠️ **الأساس بيتقرا من الصفحة نفسها لا من أول نداء** (`window.__almBuild`،
 *    بيرسمه `_pwa_head.php` وقت الرندر). لو الأساس اتاخد من أول رد، اللي فتح
 *    الصفحة **قبل** الرفع وأول نداء له جه **بعده** كان هياخد النسخة الجديدة
 *    أساسًا ويفضل شغّال على صفحة قديمة للأبد.
 *
 * ⚠️ **إجباري بس ما بيضيّعش إدخال** (قرار المالك): لو الشاشة مفيهاش كتابة نص
 *    طريق → تحديث فوري بلا ما حد يدوس حاجة. لو فيه → لافتة ثابتة ما تتقفلش،
 *    والتحديث بيتنفّذ أول ما المستخدم يحفظ أو يفتح أي صفحة تانية (وساعتها
 *    بياخد النسخة الجديدة من الشبكة أصلاً). النظام مليان فورمات طويلة —
 *    لصق ٢٠٠ بوليصة من الإكسل، فورم إذن، إدخال سريع — وإعادة تحميل غادرة
 *    وسطهم بتضيّع شغل نص ساعة.
 *
 * ⚠️ **الملف ده بيتحمّل من `_pwa_head.php`** عشان يوصل التلات مداخل مع بعض
 *    (تخطيط المكتب · شاشة الدخول · بوابة المناديب) بسطر واحد. بوابة المناديب
 *    **ما بتحمّلش `app.js`**، فلو المنطق عاش هناك كان هيخدم نص النظام بس —
 *    نفس مصيدة `resume-scroll.js` الموثَّقة.
 */
(function () {
  'use strict';

  var BASE = String(window.__almBuild || '');
  if (!BASE) { return; }                       // الصفحة ما رسمتش بصمة — بلاش تخمين

  var POLL_MS = 60000;
  var ARM_MS = 120000;   // لو مفيش أي نداء بيغذّينا في المدة دي، بنسأل بنفسنا
  var RELOAD_KEY = 'alm:updated-to';
  var lastSeenAt = 0, dirty = false, pending = false, timer = null, armTimer = null;

  /* ── هل فيه شغل نص طريق على الشاشة؟ ──────────────────────────────────────
     أي كتابة في أي خانة بتخلّي الصفحة «متّسخة» لحد ما تتبعت. بنسمع في مرحلة
     الالتقاط عشان أي معالج تاني بيوقف الحدث ما يخفيهوش عننا (نفس سبب
     الالتقاط في `resume-scroll.js`). */
  document.addEventListener('input', function () { dirty = true; }, true);
  document.addEventListener('change', function () { dirty = true; }, true);
  /* الإرسال معناه انتقال جاي — الصفحة الجديدة هتيجي بأحدث كود لوحدها */
  document.addEventListener('submit', function () { dirty = false; }, true);

  function banner() {
    if (document.getElementById('almUpdBar')) { return; }
    var bar = document.createElement('div');
    bar.id = 'almUpdBar';
    bar.className = 'upd-bar';
    bar.setAttribute('role', 'alert');

    var txt = document.createElement('span');
    txt.className = 'upd-txt';
    txt.textContent = 'فيه تحديث للنظام. احفظ اللي انت فيه وهيتحدّث لوحده — أو حدّث دلوقتي.';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'upd-btn';
    btn.textContent = 'حدّث دلوقتي';
    /* ⚠️ الزرار ده بيتجاهل «فيه إدخال» عن قصد — المستخدم هو اللي طلبه، فبنأكّد
       بس عشان ما يضيّعش شغله بضغطة غلط. */
    btn.addEventListener('click', function () {
      if (!dirty || window.confirm('فيه بيانات مكتوبة ولسه ما اتحفظتش، والتحديث هيمسحها. تكمّل؟')) {
        go();
      }
    });

    bar.appendChild(txt);
    bar.appendChild(btn);
    (document.body || document.documentElement).appendChild(bar);
    document.documentElement.classList.add('has-upd-bar');
  }

  function go() {
    /* ⚠️ حزام أمان ضد حلقة إعادة تحميل: لو النسخة اللي بنروح ليها هي نفسها
       اللي رجعنا منها للتو، يبقى فيه حاجة غلط (كاش عنيد على السيرفر مثلًا)
       — بنوقف عند اللافتة بدل ما نفضل نعيد التحميل في وش المستخدم. */
    try {
      if (sessionStorage.getItem(RELOAD_KEY) === pending) { banner(); return; }
      sessionStorage.setItem(RELOAD_KEY, pending || '');
    } catch (e) {}
    /* نخلّي الـService Worker يجيب نسخته الجديدة قبل ما نعيد التحميل، وإلا
       أول تحميل بعد التحديث ممكن يتخدم من عامل قديم. فشله ما يوقفش حاجة. */
    try {
      if (navigator.serviceWorker && navigator.serviceWorker.getRegistration) {
        navigator.serviceWorker.getRegistration().then(function (r) { if (r) { r.update(); } })
          .catch(function () {});
      }
    } catch (e) {}
    setTimeout(function () { location.reload(); }, 120);
  }

  function onBuild(build) {
    lastSeenAt = Date.now();
    if (!build || build === BASE || pending === build) { return; }
    pending = build;
    if (dirty) { banner(); } else { go(); }
  }

  /* لو المستخدم كان بيكتب ورجع للتبويب وهو مش بيكتب دلوقتي، بنجرّب تاني —
     أغلب الحالات بيكون حفظ خلاص أو ساب الشاشة. */
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && pending && !dirty) { go(); }
  });

  /* ── التغذية ─────────────────────────────────────────────────────────────
     الأصل إن نداءات الـping القايمة (كل ٦٠ ثانية في المكتب وفي شاشة المندوب
     الرئيسية) هي اللي بتغذّينا، فالتكلفة **صفر طلب زيادة**. الشاشات اللي
     مالهاش نداء بتسأل بنفسها على `/build` — رد صغير بلا أي لمسة للقاعدة. */
  window.AppUpdate = {
    base: BASE,
    seen: function (build) { onBuild(String(build || '')); },
    isDirty: function () { return dirty; }
  };

  function selfPoll() {
    if (document.hidden) { return; }
    fetch((window.__almBuildUrl || '/build'), {
      credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.build) { onBuild(String(d.build)); } })
      .catch(function () {});
  }

  armTimer = setTimeout(function () {
    if (lastSeenAt) { return; }               // فيه نداء بيغذّينا خلاص — بلاش طلب زيادة
    selfPoll();
    timer = setInterval(function () { if (!document.hidden) { selfPoll(); } }, POLL_MS);
  }, ARM_MS);
})();
