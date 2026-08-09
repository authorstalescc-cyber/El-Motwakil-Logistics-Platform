/**
 * فواصل الآلاف في كل خانة مبلغ — **المصدر الوحيد** لهذا السلوك في النظام.
 *
 * ليه: صاحب المشروع بيكتب مبالغ من ٤ لـ٧ خانات طول اليوم، و«2000» و«20000»
 * شكلهم واحد تقريبًا في خانة صغيرة — فخطأ صفر زيادة أو ناقصة ما بيتشافش
 * قبل الحفظ. الفاصلة بتخلّي الخطأ **مرئي وانت بتكتب**.
 *
 * ⚠️ ليه ملف مستقل مش جوّه `app.js`: بوابة المندوب `/m` **ما بتحمّلش app.js**
 *    (نفس سبب `resume-scroll.js` و`lookup-autofill.js`)، وخانات المبالغ هناك
 *    هي أكتر خانات بتتكتب في النظام. الملف ده بيتحمّل في الاتنين.
 *
 * القواعد (قرار المالك):
 *   • الفاصلة بتتحطّ **تلقائيًّا** كل ٣ خانات: 1000 ← 1,000 · 100000 ← 100,000
 *   • فاصلة **بيكتبها المستخدم بإيده** = قروش، يعني بتتحوّل لنقطة عشرية:
 *     «1500,25» ← «1,500.25». (لأن الآلاف بقت تلقائية، فالفاصلة اليدوي
 *     مالهاش معنى تاني.)
 *   • نص **ملزوق** (paste) شكله رقم مجمَّع سليم («1,250,000») بيتقرا آلاف —
 *     لأن ده جاي من إكسل أو من كشف، مش كتابة إنسان.
 *   • القروش محدودة بخانتين — أكتر من كده بيتقصّ وانت بتكتب.
 *
 * ⚠️ المصيدة الأساسية: `<input type="number">` **بيرفض** أي قيمة فيها فاصلة
 *    (بيفضّيها بالسكوت)، فالخانة بتتحوّل هنا لـ`type="text"` + `inputmode="decimal"`
 *    (نفس كيبورد الأرقام على الموبايل). والتحقّق اللي كان المتصفح بيعمله
 *    (`min`/`max`) بيتعاد هنا بـ`setCustomValidity` عشان ما يضيعش.
 *
 * ⚠️ ومصيدة تانية أخطر: أي كود قديم بيقرا `el.value` كان هياخد «1,000»
 *    و`parseFloat` بترجّعه **1**. فبنستبدل خاصية `value` على الخانة نفسها:
 *    الشاشة بتوري «1,000» والجافاسكريبت بياخد «1000». يعني ولا سطر حساب
 *    قديم محتاج يتعدّل.
 *
 * والخادم بيكرّر التنظيف كأمان (`money_normalize_request()` في `bootstrap.php`)
 * لأن `FormData(form)` بتقرا القيمة المعروضة مش الخاصية المستبدَلة.
 */
(function () {
  'use strict';

  var SEL = 'input[data-money]';
  var MAX_DECIMALS = 2;                       // قروش
  var AR_DIGITS = /[٠-٩۰-۹]/;
  var UPGRADED = '__moneyOn';
  var PREV = '__moneyPrev';       // آخر نص عرضناه — مرجع معرفة «إيه اللي اتكتب»

  /* الوصول للقيمة الأصلية للخانة — لازم قبل استبدال الخاصية وبعده */
  var nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  function shown(el) { return nativeValue.get.call(el); }
  function show(el, v) { nativeValue.set.call(el, v); }

  function toLatin(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
      .replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); });
  }

  /**
   * نص معروض ← رقم خام. الفواصل في النص المعروض **بتاعتنا دايمًا** (فواصل
   * آلاف) فبتتشال — الفاصلة اللي بيكتبها المستخدم بتتحوّل لنقطة **قبل** ما
   * توصل هنا (شوف `userDecimalComma()` تحت).
   *
   * وبيحافظ على النقطة الأخيرة وانت بتكتب («12.» تفضل «12.») وبيقصّ القروش
   * عند خانتين.
   */
  function toRaw(display) {
    var s = toLatin(display).replace(/٫/g, '.').replace(/[٬،]/g, ',');
    var neg = s.charAt(0) === '-';
    s = s.replace(/[^0-9.]/g, '');                  // الفواصل بتتشال هنا

    var dot = s.indexOf('.');                       // أول نقطة هي فاصلة القروش
    var int = dot < 0 ? s : s.slice(0, dot);
    var dec = dot < 0 ? null : s.slice(dot + 1).replace(/\./g, '').slice(0, MAX_DECIMALS);

    int = int.replace(/^0+(?=\d)/, '');             // «0500» ← «500»
    if (int === '' && dec === null) { return ''; }
    if (int === '') { int = '0'; }                  // «.5» ← «0.5»
    return (neg ? '-' : '') + int + (dec === null ? '' : '.' + dec);
  }

  /* ───────── قاعدة المالك: الفاصلة اللي بيكتبها المستخدم = قروش ─────────
     ⚠️ **مستحيل نعرفها من شكل النص لوحده.** «1,0000» ممكن تكون المستخدم كتب
        صفرًا زيادة على «1,000» (آلاف)، وممكن تكون كتب فاصلة بإيده (قروش) —
        نفس النص بالظبط. جرّبنا الحكم بشكل النص فطلع «1,500» + صفر = «1.50».
     فالحكم بيتاخد على **اللي اتكتب دلوقتي** لا على الناتج: بنقارن المعروض
     قبل وبعد، ونشوف الجزء المضاف:
        • فيه فاصلة وهو رقم مجمَّع سليم («1,250,000» ملزوق من إكسل) ← آلاف.
        • فيه فاصلة وخلاص (إنسان دقّها) ← قروش، تبقى نقطة.
        • مفيهوش فاصلة ← مالوش دعوة، الفواصل الموجودة كلها بتاعتنا. */
  var GROUPED = /^-?\d{1,3}(,\d{3})+(\.\d+)?$/;
  var COMMA = /[,٬،٫]/;

  /** الجزء اللي اتزوّد بين نصّين — بمقارنة أول وآخر ما هو مشترك */
  function inserted(before, after) {
    var p = 0, maxP = Math.min(before.length, after.length);
    while (p < maxP && before.charAt(p) === after.charAt(p)) { p++; }
    var s = 0, maxS = Math.min(before.length - p, after.length - p);
    while (s < maxS && before.charAt(before.length - 1 - s) === after.charAt(after.length - 1 - s)) { s++; }
    return { at: p, text: after.slice(p, after.length - s), tailLen: s };
  }

  /** لو المستخدم دقّ فاصلة دلوقتي، بيرجّع المعروض وقد بقت نقطة عشرية */
  function userDecimalComma(before, after) {
    var d = inserted(before, after);
    if (d.text === '' || !COMMA.test(d.text)) { return null; }
    if (GROUPED.test(d.text.replace(/[٬،]/g, ','))) { return null; }   // ملزوق مجمَّع ← آلاف
    return after.slice(0, d.at) + d.text.replace(/[,٬،٫]/g, '.') + after.slice(after.length - d.tailLen);
  }

  /** رقم خام ← نص معروض بفواصل الآلاف */
  function toDisplay(raw) {
    var s = toRaw(raw);
    if (s === '') { return ''; }
    var neg = s.charAt(0) === '-';
    if (neg) { s = s.slice(1); }
    var dot = s.indexOf('.');
    var int = dot < 0 ? s : s.slice(0, dot);
    var tail = dot < 0 ? '' : s.slice(dot);
    int = int.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (neg ? '-' : '') + int + tail;
  }

  /** عدد المحارف المعتبرة (كل حاجة ما عدا فواصلنا) قبل موضع المؤشّر */
  function significantBefore(str, pos) {
    var n = 0;
    for (var i = 0; i < pos && i < str.length; i++) { if (str.charAt(i) !== ',') { n++; } }
    return n;
  }
  function caretAfter(str, count) {
    var n = 0, i = 0;
    for (; i < str.length; i++) {
      if (n >= count) { break; }
      if (str.charAt(i) !== ',') { n++; }
    }
    return i;
  }

  /* ───────── القيمة الخام للخانة (بعد الاستبدال `el.value` بترجّعها) ───────── */
  function rawOf(el) { return toRaw(shown(el)); }

  function reformat(el, keepCaret) {
    var before = shown(el);
    var after = toDisplay(before);
    /* ⚠️ لازم يتسجّل حتى لو مفيش تغيير — هو المرجع اللي بنعرف منه المرة الجاية
       إيه اللي المستخدم كتبه بالظبط. من غيره قاعدة «الفاصلة = قروش» بتضيع. */
    el[PREV] = after;
    if (after === before) { validate(el); return; }
    var pos = null;
    if (keepCaret) {
      try { pos = significantBefore(before, el.selectionStart); } catch (_) { pos = null; }
    }
    show(el, after);
    if (pos !== null) {
      try { var c = caretAfter(after, pos); el.setSelectionRange(c, c); } catch (_) { /* خانة ما بتدعمش */ }
    }
    validate(el);
  }

  /* ───────── التحقّق اللي ضاع لما الخانة بقت نصّية ─────────
     `min`/`max` كانوا شغّالين بالمتصفح على `type=number` — بنعيدهم هنا،
     وإلا كان الرفض هيحصل بعد الحفظ من الموديل بس (رحلة أطول للمستخدم). */
  function validate(el) {
    if (typeof el.setCustomValidity !== 'function') { return; }
    var raw = rawOf(el), msg = '';
    if (raw !== '') {
      var n = parseFloat(raw);
      var min = el.getAttribute('min'), max = el.getAttribute('max');
      if (isNaN(n)) { msg = 'اكتب مبلغًا صحيحًا'; }
      else if (min !== null && min !== '' && n < parseFloat(min)) { msg = 'أقل مبلغ مسموح ' + toDisplay(min); }
      else if (max !== null && max !== '' && n > parseFloat(max)) { msg = 'أكبر مبلغ مسموح ' + toDisplay(max); }
    }
    el.setCustomValidity(msg);
  }

  /* ───────── ترقية الخانة ───────── */
  function upgrade(el) {
    if (!el || el[UPGRADED]) { return; }
    el[UPGRADED] = true;
    if ((el.getAttribute('type') || '').toLowerCase() === 'number') {
      var v = shown(el);              // نقراها قبل تغيير النوع (النوع بيفضّيها)
      el.setAttribute('type', 'text');
      if (!el.getAttribute('inputmode')) { el.setAttribute('inputmode', 'decimal'); }
      el.setAttribute('autocomplete', el.getAttribute('autocomplete') || 'off');
      show(el, v);
    }
    /* الشاشة بتوري «1,000» والكود بياخد «1000» — فمفيش سطر حساب قديم بيتكسر */
    Object.defineProperty(el, 'value', {
      configurable: true,
      enumerable: true,
      get: function () { return rawOf(el); },
      set: function (v) { var d = toDisplay(v); show(el, d); el[PREV] = d; validate(el); }
    });
    reformat(el, false);
  }

  function upgradeAll(root) {
    var scope = root && root.querySelectorAll ? root : document;
    if (scope.matches && scope.matches(SEL)) { upgrade(scope); }
    var list = scope.querySelectorAll(SEL);
    for (var i = 0; i < list.length; i++) { upgrade(list[i]); }
  }

  /* ───────── طبقة `beforeinput`: حاجتين بس ─────────
     ⚠️ **قاعدة الفاصلة مش هنا** — هي في `toRaw()` وحدها (مصدر واحد).
     اللي هنا:
       1) الأرقام العربية: لازم نحوّلها إحنا **ونوقف انتشار الحدث**، لأن معالج
          `app.js` بيقرا `el.value` (اللي بقت بترجّع الخام) وبيقصّه بمؤشّر
          **النص المعروض** — الاتنين مع بعض بيدّوا رقمًا مخربطًا.
       2) Backspace واقف على فاصلة: يمسح الرقم اللي قبلها.
     capture عشان نسبق `app.js` (بيتحمّل بعدنا). */
  document.addEventListener('beforeinput', function (e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches(SEL) || !el[UPGRADED]) { return; }

    /* Backspace على فاصلة بتاعتنا: امسح الرقم اللي قبلها — من غير كده
       الضغطة الأولى بتشيل فاصلة بترجع تتحط تاني فتبان إنها ما عملتش حاجة. */
    if (e.inputType === 'deleteContentBackward') {
      var s = el.selectionStart, t = el.selectionEnd;
      if (s === t && s > 0 && shown(el).charAt(s - 1) === ',') {
        e.preventDefault();
        var cur = shown(el);
        show(el, cur.slice(0, s - 2) + cur.slice(s));
        try { el.setSelectionRange(s - 2, s - 2); } catch (_) {}
        el.dispatchEvent(new Event('input', { bubbles: true }));
        return;
      }
      return;
    }

    var data = e.data;
    if (data == null && e.dataTransfer) { try { data = e.dataTransfer.getData('text'); } catch (_) {} }
    if (typeof data !== 'string' || data === '') { return; }

    if (!AR_DIGITS.test(data)) { return; }      // مفيش أرقام عربية ← سيب المتصفح، و`toRaw` بيتصرّف
    var piece = toLatin(data);

    e.preventDefault();
    e.stopImmediatePropagation();             // معالج الأرقام العربية في app.js ما يشتغلش على نفس الحدث
    var start = 0, end = 0, val = shown(el);
    try { start = el.selectionStart; end = el.selectionEnd; } catch (_) { start = end = val.length; }
    show(el, val.slice(0, start) + piece + val.slice(end));
    var pos = start + piece.length;
    try { el.setSelectionRange(pos, pos); } catch (_) {}
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, true);

  /* أي تغيير في القيمة ← إعادة التنسيق. capture عشان نسبق حسابات الشاشات
     (recompute…) فتقرا رقمًا نضيفًا. */
  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches(SEL)) { return; }
    if (!el[UPGRADED]) { upgrade(el); return; }
    var asDecimal = userDecimalComma(el[PREV] == null ? '' : el[PREV], shown(el));
    if (asDecimal !== null) {
      var caret = null;
      try { caret = el.selectionStart; } catch (_) {}
      show(el, asDecimal);                       // الفاصلة اللي اتكتبت بقت نقطة
      if (caret !== null) { try { el.setSelectionRange(caret, caret); } catch (_) {} }
    }
    reformat(el, true);
  }, true);

  /* ───────── الحفظ: القيمة اللي بتتبعت للخادم لازم تكون خامًا ─────────
     `FormData(form)` والإرسال العادي بيقروا القيمة **المعروضة** مش الخاصية
     المستبدَلة، فلازم نشيل الفواصل لحظة الإرسال ونرجّعها بعدها (لو الإرسال
     اتمنع بتأكيد أو بفحص). والخادم بيكرّر التنظيف كطبقة تانية. */
  function stripForSubmit(form) {
    var els = form && form.elements ? form.elements : [];
    var touched = [];
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el && el.matches && el.matches(SEL) && el[UPGRADED]) { show(el, rawOf(el)); touched.push(el); }
    }
    if (touched.length) {
      setTimeout(function () { for (var j = 0; j < touched.length; j++) { reformat(touched[j], false); } }, 0);
    }
  }
  document.addEventListener('submit', function (e) {
    if (e.target && e.target.tagName === 'FORM') { stripForSubmit(e.target); }
  }, true);
  document.addEventListener('reset', function (e) {
    if (e.target && e.target.tagName === 'FORM') {
      setTimeout(function () { upgradeAll(e.target); var l = e.target.querySelectorAll(SEL);
        for (var i = 0; i < l.length; i++) { reformat(l[i], false); } }, 0);
    }
  }, true);

  /* الصفوف اللي بتتولد بجافاسكريبت (بنود الفاتورة · الإدخال السريع · حركة
     الإذن · حساب السائق) بتتضاف بعد التحميل — فلازم مراقب. */
  function watch() {
    upgradeAll(document);
    if (!window.MutationObserver) { return; }
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var added = muts[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          if (added[j].nodeType === 1) { upgradeAll(added[j]); }
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', watch); }
  else { watch(); }

  /* متاح للكود الخارجي — من غير السطر ده أي `window.Money.*` بيبقى خطّاف
     بلا تنفيذ فيفشل بالسكوت (نفس عائلة مصيدة `.table-mobile-scroll`). */
  window.Money = { raw: toRaw, display: toDisplay, upgrade: upgradeAll, typedComma: userDecimalComma };
})();
