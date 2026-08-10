/*
 * تأكيد الحذف في بوابة المندوب — **المصدر الوحيد**.
 *
 * ⚠️ ليه ملف مستقل: بوابة `/m` **ما بتحمّلش `app.js`**، واللي فيه معالج
 *    `form.confirm-delete` بتاع المكتب. فالمعالج كان مكتوبًا جوّه `m/home.php`
 *    وبس — يعني أي شاشة تانية بتطبع نفس الكلاس كانت **بتحذف من غير ما تسأل**.
 *    (اتصاد فعليًّا في `m/permit_info.php`: زرار شيل الورقة كان بيمسحها بلمسة
 *    واحدة بلا رجعة، والماركب طالب تأكيد بـ`data-confirm`.)
 *
 * ⚠️ نافذة التأكيد **بتتحقن لو مش موجودة** — فأي شاشة جديدة تكسب التأكيد
 *    بمجرد ما تكتب `class="confirm-delete"`، بلا ماركب إضافي ولا سكربت.
 *    (`m/home.php` عندها النافذة في الماركب أصلاً، والملف ده بيعيد استعمالها.)
 *
 * ⚠️ الربط بـ`document` لا بكل فورم على حدة: الشاشات اللي بتضيف صفوفًا
 *    بالجافاسكريبت بعد التحميل بتاخد التأكيد هي كمان.
 *
 * ⚠️ `<dialog>` مش مدعومة على متصفحات قديمة — البديل `confirm()` الأصلي،
 *    فالحماية موجودة دايمًا حتى لو الشكل أبسط.
 */
(function () {
  'use strict';

  var pending = null;
  var box = null;

  function build() {
    var found = document.getElementById('mConfirm');
    if (found) { return found; }
    if (typeof HTMLDialogElement === 'undefined') { return null; }

    var d = document.createElement('dialog');
    d.className = 'm-confirm';
    d.id = 'mConfirm';
    d.setAttribute('aria-labelledby', 'mConfirmTitle');
    d.innerHTML =
      '<div class="m-confirm-box">' +
        '<b id="mConfirmTitle">تأكيد الحذف</b>' +
        '<p id="mConfirmText"></p>' +
        '<div>' +
          '<button type="button" class="ghost" data-m-cancel>إلغاء</button>' +
          '<button type="button" class="danger" data-m-ok>نعم، احذف</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(d);
    return d;
  }

  function send(form) {
    /* العلامة بتخلّي الإرسال التاني يعدّي من غير ما يسأل تاني — وبتتشال فورًا
       عشان أي ضغطة جاية على نفس الفورم تسأل من الأول. */
    form.dataset.confirmed = '1';
    if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.classList || !form.classList.contains('confirm-delete')) { return; }
    if (form.dataset.confirmed === '1') { form.dataset.confirmed = ''; return; }

    var text = form.dataset.confirm || 'هل أنت متأكد؟';
    e.preventDefault();

    if (box === null) { box = build(); }
    if (!box || !box.showModal) {
      if (window.confirm(text)) { send(form); }
      return;
    }
    pending = form;
    box.querySelector('#mConfirmText').textContent = text;
    box.showModal();
  });

  /* الأزرار بالتفويض كمان — النافذة ممكن تكون اتحقنت بعد تحميل السكربت. */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) { return; }
    if (t.closest('[data-m-cancel]')) {
      pending = null;
      if (box) { box.close(); }
      return;
    }
    if (t.closest('[data-m-ok]')) {
      var form = pending;
      pending = null;
      if (box) { box.close(); }
      if (form) { send(form); }
    }
  });

  /* الإغلاق بزرار الرجوع/Esc بيسيب `pending` معلّقًا — تصفيره إلزامي وإلا
     أول ضغطة «نعم» بعد كده بتبعت فورمًا المندوب كان لغاه. */
  document.addEventListener('close', function (e) {
    if (e.target && e.target.id === 'mConfirm') { pending = null; }
  }, true);
})();
