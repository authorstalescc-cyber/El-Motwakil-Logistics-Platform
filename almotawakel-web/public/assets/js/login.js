/*
 * شاشة الدخول: تركيز الخطأ · منع تكرار الإرسال · إظهار كلمة السر · وضع العرض.
 *
 * ⚠️ الشاشة دي **مستقلة عن التخطيط العام فما بتحمّلش `app.js`** — فأي سلوك
 *    مشترك (زي تبديل السمة) لازم يتكتب هنا كمان. نفس مصيدة سكربت «إظهار
 *    الأرصدة» اللي كان محلّيًّا في شاشة واحدة فالشاشات التانية فضلت مقنّعة.
 */
(function () {
  'use strict';

  const summary = document.querySelector('[data-form-error-summary]');
  if (summary) summary.focus();

  /* ── إظهار/إخفاء كلمة السر ─────────────────────────────────────────────
     مهمة على الموبايل تحديدًا: الرقم السري بقى ٦ خانات (v48) والكيبورد
     بيخبّي الحروف، فأغلب محاولات الدخول الفاشلة غلطة كتابة مش نسيان. */
  const pwBtn = document.getElementById('pwToggle');
  const pwInput = document.getElementById('login-password');
  if (pwBtn && pwInput) {
    pwBtn.addEventListener('click', function () {
      const show = pwBtn.getAttribute('aria-pressed') !== 'true';
      pwBtn.setAttribute('aria-pressed', show ? 'true' : 'false');
      pwInput.type = show ? 'text' : 'password';
      const label = show ? 'إخفاء كلمة السر' : 'إظهار كلمة السر';
      pwBtn.title = label;
      pwBtn.setAttribute('aria-label', label);
      /* رجّع التركيز للخانة وسيب المؤشّر في آخرها — من غير كده الضغط على
         الزرار بيطلّع المستخدم برّه الخانة وسط الكتابة. */
      const at = pwInput.value.length;
      pwInput.focus();
      try { pwInput.setSelectionRange(at, at); } catch (e) {}
    });
  }

  /* ── وضع العرض: تلقائي ← فاتح ← غامق ──────────────────────────────────
     ⚠️ **نفس مفتاح `localStorage` ونفس دورة الحالات بتاعة `app.js`** — لو
     اختلفوا، المستخدم يختار غامق هنا ويلاقي النظام فاتح جوّه (أو العكس). */
  const themeBtn = document.getElementById('themeBtn');
  if (themeBtn) {
    const KEY = 'almotawakel:theme';
    const root = document.documentElement;
    const LABEL = { auto: 'تلقائي (زي الجهاز)', light: 'فاتح', dark: 'غامق' };
    const NEXT = { auto: 'light', light: 'dark', dark: 'auto' };

    const current = function () {
      const t = root.getAttribute('data-theme');
      return (t === 'light' || t === 'dark') ? t : 'auto';
    };
    const paint = function () {
      themeBtn.title = 'وضع العرض: ' + LABEL[current()];
      themeBtn.setAttribute('aria-label', themeBtn.title + ' — اضغط للتبديل');
    };
    themeBtn.addEventListener('click', function () {
      const mode = NEXT[current()];
      if (mode === 'auto') { root.removeAttribute('data-theme'); }
      else { root.setAttribute('data-theme', mode); }
      try {
        mode === 'auto' ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, mode);
      } catch (e) {}
      paint();
    });
    paint();
  }

  const form = document.getElementById('loginForm');
  if (!form) return;

  form.addEventListener('submit', function (event) {
    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');
    setTimeout(function () {
      const button = form.querySelector('button[type="submit"]');
      if (!button) return;
      button.disabled = true;
      button.textContent = 'جارٍ الدخول…';
    }, 0);
  });
})();
