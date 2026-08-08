/* ═══════════════════════════════════════════════════════════════════════════
   تعبئة تلقائية لقائمة مقفولة من حقل تاني — «شركة الشحن = العميل» (2026-08-02)

   بلاغ المالك: «شركة الشحن بتكون هي نفس الوكيل لكنه بيحجمني الآن في اختيارها —
   المفروض تلقائي تكون الوكيل». مقيس على الإنتاج: **٥٦ من ٨٧ إذن** شركة الشحن
   فيهم = العميل بالحرف.

   الاستعمال: `data-autofill-from="client"` على أي `<select>`.

   ⚠️ **بيملا الشاشة بس — ما بيخترعش قيمة على الخادم.** لو الجافاسكريبت ما
      اشتغلش، السلوك القديم (تختار بإيدك) بيفضل زي ما هو، ومفيش أي قيمة بتتخزّن
      من غير ما المالك يشوفها في الفورم قبل الحفظ. عشان كده **مفيش تعبئة تلقائية
      في الكنترولر** — كتابة قيمة المستخدم مشافهاش في حقل بيدخل بيانات الإذن
      أسوأ من إنه يختارها بنفسه.

   ⚠️ **ما بيدهسش اختيار المستخدم أبدًا.** بيملا بس لو الخانة فاضية، أو لو
      القيمة اللي فيها هي اللي احنا حطّيناها (`data-auto="1"`) فبتتحدّث مع تغيير
      العميل. أول ما المستخدم يغيّرها بإيده، الوسم بيتشال وما بنلمسهاش تاني.

   ⚠️ **الاستماع في مرحلة الالتقاط (capture)** — الشاشات دي فيها معالجات تانية
      على `input`/`change` (حارس الأسماء · حفظ المسودة) وبعضها بيوقف الحدث.
      نفس درس `resume-scroll.js` و`app-update.js`.

   ⚠️ **ملف مشترك عن قصد**: بوابة المندوب `/m` **ما بتحمّلش `app.js`**، فلو
      المنطق عاش هناك كان هيخدم شاشة المكتب بس والمندوب يفضل بلا تعبئة —
      نفس مصيدة `.table-mobile-scroll`.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* نسخة خفيفة من `Maintenance::normkey` في PHP — لازم تفضل متطابقة معاها:
     توحيد الألف والتاء المربوطة والياء والهمزات + شيل التشكيل والتطويل.
     لو اتفرّقت، الشاشة تلاقي الوكيل في القائمة والخادم ما يلاقيهوش (أو العكس). */
  function normkey(v) {
    return String(v == null ? '' : v)
      .replace(/[ـً-ْٰ]/g, '')
      .replace(/[أإآٱ]/g, 'ا')
      .replace(/ى/g, 'ي').replace(/ة/g, 'ه')
      .replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
      .replace(/\s+/g, ' ')
      .trim().toLowerCase();
  }

  function fill(target, wanted) {
    if (!target || target.tagName !== 'SELECT') { return; }
    // المستخدم اختار بإيده؟ سيبها.
    if (target.value !== '' && target.dataset.auto !== '1') { return; }

    var key = normkey(wanted);
    if (key === '') {
      if (target.dataset.auto === '1') { target.value = ''; delete target.dataset.auto; }
      return;
    }
    for (var i = 0; i < target.options.length; i++) {
      var opt = target.options[i];
      if (opt.value !== '' && normkey(opt.value) === key) {
        if (target.value !== opt.value) {
          target.value = opt.value;
          target.dataset.auto = '1';
        }
        return;
      }
    }
    // الوكيل مش في الخيارات (اسم جديد لسه ما اتسجّلش) — نسيب الخانة فاضية
    // بدل ما نحط حاجة غلط. الخادم بيقبل الفاضي عادي.
    if (target.dataset.auto === '1') { target.value = ''; delete target.dataset.auto; }
  }

  function sync(srcName) {
    var targets = document.querySelectorAll('select[data-autofill-from]');
    for (var i = 0; i < targets.length; i++) {
      var t = targets[i];
      var from = t.getAttribute('data-autofill-from');
      if (srcName !== null && from !== srcName) { continue; }
      // المصدر جوّه نفس الفورم لو موجود، وإلا في الصفحة كلها (شاشات AJAX)
      var scope = t.form || document;
      var src = scope.querySelector('[name="' + from + '"]');
      if (src) { fill(t, src.value); }
    }
  }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.name) { return; }
    if (t.tagName === 'SELECT' && t.hasAttribute('data-autofill-from')) { return; }
    sync(t.name);
  }, true);

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.name) { return; }
    // تغيير يدوي على الهدف نفسه = المستخدم قرّر — نبطّل نلمسها
    if (t.tagName === 'SELECT' && t.hasAttribute('data-autofill-from')) {
      delete t.dataset.auto;
      return;
    }
    sync(t.name);
  }, true);

  // أول تحميل: الفورم ممكن يرجع معبّى بعد خطأ من الخادم
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { sync(null); });
  } else {
    sync(null);
  }
})();
