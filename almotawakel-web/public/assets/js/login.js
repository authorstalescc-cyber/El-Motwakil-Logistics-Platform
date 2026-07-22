/* شاشة الدخول: تركيز الخطأ ومنع تكرار الإرسال. */
(function () {
  'use strict';

  const summary = document.querySelector('[data-form-error-summary]');
  if (summary) summary.focus();

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
