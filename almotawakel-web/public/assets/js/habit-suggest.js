/* ═══════════════════════════════════════════════════════════════════════════
   اقتراح من عادات العميل — الجهة والتوكيل والميناء وتصنيف الحاويات (v97)

   طلب المالك: «عاوز النظام يتوقع من المعاملات السابقة للشركات أو الوكلاء جهة
   الإذن، لأن بنسبة ٩٩٪ الإذن بيكون لنفس الجهة كل مرة». المقيس فعليًّا:
   **٨٥.٧٪** ثبات الجهة حسب الشركة · **٦٨.٢٪** حسب العميل.

   الاستعمال: `data-habit="destination"` على أي `<input>` أو `<select>`، مع وجود
   `[name=client]` و/أو `[name=company]` في نفس الفورم.

   ⚠️ **بيملا الشاشة بس — ما بيخترعش قيمة على الخادم.** لو الملف ده ما اتحمّلش،
      الفورم بيفضل فاضي زي الأول بالحرف ومفيش قيمة بتتخزّن من غير ما المالك
      يشوفها. نفس قرار `lookup-autofill.js` — وده هو اللي بيخلّي الميزة آمنة:
      أسوأ حاجة ممكن تحصل إنها ما تشتغلش، مش إنها تكتب غلط.

   ⚠️ **ما بيدهسش اختيار المستخدم أبدًا.** بيملا بس لو الخانة فاضية، أو لو
      اللي فيها هو اللي احنا حطّيناه (`data-habit-auto="1"`). أول ما المستخدم
      يلمسها بإيده الوسم بيتشال وما بنلمسهاش تاني — وساعتها الاقتراح بيتحوّل
      من تعبئة لزرار «استخدم» يقرّر هو يدوسه ولا لأ.

   ⚠️ **الاستماع في مرحلة الالتقاط (capture)** — الشاشات دي فيها معالجات تانية
      على `input`/`change` (حارس الأسماء · حفظ المسودة) وبعضها بيوقف الحدث.
      نفس درس `resume-scroll.js` و`app-update.js` و`lookup-autofill.js`.

   ⚠️ **ولا كلاس CSS جديد.** السطر اللي بيظهر تحت الخانة بيستعمل كلاسات حارس
      الأسماء الموجودة أصلاً (`.smart-name-note` · `.snn-hint` · `.snn-use` ·
      `.snn-new`) — فالميزة بتدخل الشاشة **مألوفة** وبصفر مخاطرة على الوضع
      الليلي أو المتجاوب، والمصيدة الموثَّقة «كلاس في الماركب بلا CSS بيقع على
      الشكل الافتراضي بالسكوت» متلغية من أصلها.

   ⚠️ **مش متحمّل في بوابة المندوب `/m`** — البوابة ما بتحمّلش `app.css` فكلاسات
      السطر مالهاش تعريف هناك. الملف مستقل عن `app.js` عن قصد عشان يكون جاهز
      للتحميل هناك أول ما `m.css` تاخد نفس الكلاسات، مش قبلها.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var API = window.__HABIT_SUGGEST_API || '';
  if (!API) { return; }

  /* نسخة خفيفة من `Maintenance::normkey` في PHP — **لازم تفضل متطابقة معاها**.
     لو اتفرّقت، الاقتراح ييجي من الخادم وما يلاقيش خياره في القائمة فيسكت
     بلا سبب ظاهر. نفس النسخة الموجودة في `lookup-autofill.js` بالحرف. */
  function normkey(v) {
    return String(v == null ? '' : v)
      .replace(/[ـً-ْٰ]/g, '')
      .replace(/[أإآٱ]/g, 'ا')
      .replace(/ى/g, 'ي').replace(/ة/g, 'ه')
      .replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
      .replace(/\s+/g, ' ')
      .trim().toLowerCase();
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* السطر بيتحط **جوّه** `.field` أو `<label>` مش بعدهم: الحاويتين دول
     `display:flex;flex-direction:column` فالسطر بينزل تحت الخانة صح، بينما
     الحاوية الأب (`.form-grid` · `.tl-fields`) **grid** — فسطر بعدها كان
     هيتحوّل لخانة زيادة في الشبكة ويكسر الصف. */
  function noteFor(el) {
    var host = el.closest('.field') || el.closest('label') || el.parentNode;
    if (!host) { return null; }
    var note = host.querySelector(':scope > .smart-name-note[data-habit-note]');
    if (!note) {
      note = document.createElement('div');
      note.className = 'smart-name-note';
      note.setAttribute('data-habit-note', '1');
      note.setAttribute('aria-live', 'polite');
      host.appendChild(note);
    }
    return note;
  }

  /** بيحط القيمة لو الخانة فاضية أو لسه على قيمتنا. بيرجّع true لو اتملت. */
  function applyValue(el, value) {
    var mine = el.dataset.habitAuto === '1';
    if (el.value !== '' && !mine) { return false; }
    if (el.tagName === 'SELECT') {
      var key = normkey(value);
      for (var i = 0; i < el.options.length; i++) {
        if (el.options[i].value !== '' && normkey(el.options[i].value) === key) {
          el.value = el.options[i].value;
          el.dataset.habitAuto = '1';
          return true;
        }
      }
      // القيمة مش في القائمة المقفولة — نسيب الخانة فاضية بدل ما نحط غلط
      return false;
    }
    el.value = value;
    el.dataset.habitAuto = '1';
    return true;
  }

  function ctxOf(el) {
    var scope = el.form || document;
    var c = scope.querySelector('[name="client"]');
    var m = scope.querySelector('[name="company"]');
    return {
      client: c ? String(c.value || '').trim() : '',
      company: m ? String(m.value || '').trim() : ''
    };
  }

  function drillUrl(field, ctx) {
    return API + '/' + encodeURIComponent(field)
      + '?client=' + encodeURIComponent(ctx.client)
      + '&company=' + encodeURIComponent(ctx.company);
  }

  /* نص السطر — **تحذير وظيفي في ≤٦ كلمات**، مش شرح.
     الفرق اللي `verify_density` قايم عليه: «اضغط هنا عشان…» شرح يتشال،
     و«المعتاد سفاجا · ٩ من ١٠» معلومة غيابها بيخلّي المالك ياخد قرار غلط. */
  function paint(el, field, hit, ctx, filled) {
    var note = noteFor(el);
    if (!note) { return; }
    var count = esc(hit.hits + ' من ' + hit.total);
    var why = '<button type="button" class="snn-new" data-drill="' + esc(drillUrl(field, ctx))
            + '" data-drill-title="' + esc('المعتاد — ' + hit.value) + '">' + count + '</button>';
    if (filled) {
      note.innerHTML = '<span class="snn-hint">' + esc('المعتاد — اتملت') + '</span>'
                     + '<span class="snn-btns">' + why + '</span>';
      return;
    }
    // الخانة فيها قيمة المستخدم ومختلفة — نعرض ما نفرضش
    note.innerHTML = '<span class="snn-hint">' + esc('المعتاد ' + hit.value) + '</span>'
                   + '<span class="snn-btns"><button type="button" class="snn-use">استخدم</button>'
                   + why + '</span>';
    var use = note.querySelector('.snn-use');
    if (use) {
      use.addEventListener('click', function (e) {
        // ⚠️ الزرار ممكن يبقى **جوّه `<label>`** في شاشة الإدارة، والضغط على
        //    label بينقل التفعيل للخانة — بنوقف الانتشار عشان ما يحصلش الاتنين.
        e.preventDefault(); e.stopPropagation();
        el.value = hit.value;
        el.dataset.habitAuto = '1';
        note.innerHTML = '<span class="snn-ok">✓ اتحطّ المعتاد</span>';
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  }

  function clear(el) {
    var note = noteFor(el);
    if (note) { note.innerHTML = ''; }
  }

  var seq = 0, timer = 0;

  function run() {
    var targets = document.querySelectorAll('[data-habit]');
    if (!targets.length) { return; }
    var ctx = ctxOf(targets[0]);
    if (ctx.client === '' && ctx.company === '') {
      for (var k = 0; k < targets.length; k++) { clear(targets[k]); }
      return;
    }
    var mine = ++seq;
    fetch(API + '?client=' + encodeURIComponent(ctx.client) + '&company=' + encodeURIComponent(ctx.company),
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (mine !== seq) { return; }              // رد قديم وصل متأخر
        var items = (d && d.items) || {};
        for (var i = 0; i < targets.length; i++) {
          var el = targets[i];
          var field = el.getAttribute('data-habit');
          var hit = items[field];
          if (!hit || !hit.value) { clear(el); continue; }
          paint(el, field, hit, ctx, applyValue(el, hit.value));
        }
      })
      .catch(function () { /* الشبكة وقعت — الفورم بيفضل شغّال بلا اقتراح */ });
  }

  function schedule() { clearTimeout(timer); timer = setTimeout(run, 240); }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.name) { return; }
    if (t.hasAttribute && t.hasAttribute('data-habit')) {
      // المستخدم بيكتب في الخانة الهدف = قرار منه — نبطّل نلمسها
      delete t.dataset.habitAuto;
      return;
    }
    if (t.name === 'client' || t.name === 'company') { schedule(); }
  }, true);

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.name) { return; }
    if (t.hasAttribute && t.hasAttribute('data-habit')) {
      if (t.dataset.habitAuto !== '1') { clear(t); }   // اختيار يدوي ⇒ السطر يسكت
      return;
    }
    if (t.name === 'client' || t.name === 'company') { schedule(); }
  }, true);

  // أول تحميل: الفورم ممكن يفتح وفيه عميل مكتوب (تعديل، أو رجوع بعد خطأ)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }

  /* ═════════════════════════════════════════════════════════════════════════
     سعر السيارة المعتاد + تنبيه الشذوذ + الهامش (v98)

     المصدر: `Habit::carPrice()` — بيقرا **سعر الوحدة اللي المالك كتبه بإيده**
     في فواتير سابقة (`items_json` ← بند النولون)، مش ناتج قسمة.

     ⚠️ **الفلوس اقتراح يتداس — عمره ما يتكتب لوحده.** ده الفرق الحاكم بين
        القسم ده والقسم اللي فوق: الجهة والميناء بيتملوا تلقائيًّا لأن غلطهم
        بيتشاف ويتصلّح، والمبلغ غلطه بيتحوّل لفاتورة. زرار «استخدم» هو الوحيد
        اللي بيكتب.

     ⚠️ **السطر بيقول حاجة واحدة في كل لحظة**: قبل ما تكتب سعرًا بيعرض المعتاد،
        وبعد ما تكتب بيتحوّل للهامش أو لتنبيه الشذوذ. تلات معلومات في سطر
        واحد بتبقى ضجيج (قانون التصميم: سطر واحد + meta واحد).
     ═════════════════════════════════════════════════════════════════════════ */
  var PRICE_DEV = 0.20;   // أبعد من ٢٠٪ عن المعتاد = يستاهل تنبيه

  /* المصدر الوحيد لفكّ فواصل الآلاف (`window.Money.raw` من `money-input.js`).
     ⚠️ خانة المبلغ بتتحوّل لـ`type=text` بفواصل، فـ`parseFloat("27,500")`
        بترجّع **27** — نسخة تانية من المنطق هنا كانت هتغلط بنفس الشكل. */
  function num(v) {
    if (window.Money && typeof window.Money.raw === 'function') {
      return parseFloat(window.Money.raw(String(v == null ? '' : v))) || 0;
    }
    return parseFloat(String(v == null ? '' : v).replace(/,/g, '')) || 0;
  }

  function fmtMoney(n) {
    return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
  }

  (function priceModule() {
    var host = document.querySelector('[data-habit-price]');
    if (!host) { return; }
    var row = document.querySelector('.freight-row');
    if (!row) { return; }
    var qtyEl = row.querySelector('.i-qty');
    var priceEl = row.querySelector('.i-price');
    if (!priceEl) { return; }

    var api = host.getAttribute('data-habit-price');
    var actual = num(host.getAttribute('data-actual-freight'));
    var note = document.createElement('div');
    note.className = 'smart-name-note';
    note.setAttribute('aria-live', 'polite');
    host.insertAdjacentElement('afterend', note);

    var usual = null, cars = 0;

    function suggestion() {
      if (usual === null) { note.innerHTML = ''; return; }
      note.innerHTML =
        '<span class="snn-hint">' + esc('سعر السيارة المعتاد ' + fmtMoney(usual.value)) + '</span>'
        + '<span class="snn-btns">'
        + '<button type="button" class="snn-use">استخدم</button>'
        + '<button type="button" class="snn-new" data-drill="' + esc(api + '/drill')
        + '" data-drill-title="سعر السيارة المعتاد">'
        + esc(usual.hits + ' من ' + usual.total) + '</button>'
        + '</span>';
      note.querySelector('.snn-use').addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        if (qtyEl && cars > 0) {
          qtyEl.value = String(cars);
          qtyEl.dispatchEvent(new Event('input', { bubbles: true }));
        }
        priceEl.value = String(usual.value);
        // بيشغّل `recalc()` بتاع فورم الفوترة — مفيش نسخة تانية من الحساب هنا
        priceEl.dispatchEvent(new Event('input', { bubbles: true }));
        verdict();
      });
    }

    /* بعد ما المالك يكتب سعرًا: الهامش، أو تنبيه لو بعيد عن المعتاد. */
    function verdict() {
      var price = num(priceEl.value);
      if (price <= 0) { suggestion(); return; }

      if (usual !== null && usual.value > 0) {
        var dev = (price - usual.value) / usual.value;
        if (Math.abs(dev) > PRICE_DEV) {
          note.innerHTML = '<span class="snn-warn">'
            + esc((dev > 0 ? '⚠ أعلى من المعتاد ' : '⚠ أقل من المعتاد ')
                  + Math.round(Math.abs(dev) * 100) + '٪') + '</span>';
          return;
        }
      }
      /* الهامش = اللي هتحاسب بيه العميل ناقص اللي اتسجّل في البوالص.
         ⚠️ **عرض بس**: الهامش مقيس متذبذب ٧٪→٣٣٪ فممنوع يتحسب منه سعر. */
      var qty = qtyEl ? (num(qtyEl.value) || 1) : 1;
      if (actual > 0) {
        var pct = Math.round(((qty * price) - actual) / actual * 100);
        note.innerHTML = '<span class="snn-hint">' + esc('الهامش ' + pct + '٪') + '</span>';
        return;
      }
      note.innerHTML = '';
    }

    fetch(api, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { return; }
        cars = parseInt(d.cars, 10) || 0;
        usual = (d.price && d.price.value) ? d.price : null;
        verdict();
      })
      .catch(function () { /* الشبكة وقعت — الفورم شغّال بلا اقتراح */ });

    priceEl.addEventListener('input', verdict);
    if (qtyEl) { qtyEl.addEventListener('input', verdict); }
  })();
})();
