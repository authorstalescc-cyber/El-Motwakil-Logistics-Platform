/* نموذج المصروف: يبدّل الحقول حسب تخصيص البند لمندوب. */
(function () {
  'use strict';

  const rep = document.getElementById('repSel');
  if (!rep) return;

  const kindWrap = document.getElementById('repKindWrap');
  const kind = document.getElementById('repKind');
  const hint = document.getElementById('repHint');
  const submit = document.getElementById('expSubmit');
  const expenseOnly = document.querySelectorAll('.expense-only');
  const cashKinds = new Set(['advance', 'settlement']);

  function sync() {
    const isRep = rep.value !== '';
    kindWrap.hidden = !isRep;
    expenseOnly.forEach((element) => { element.hidden = isRep; });

    if (!isRep) {
      hint.hidden = true;
      submit.textContent = '💾 حفظ المصروف';
      return;
    }

    hint.hidden = false;
    hint.textContent = cashKinds.has(kind.value)
      ? 'هيتسجّل في حساب المندوب ويظهر في المصروفات العامة ويتخصم من العهدة (كاش خارج من الخزنة).'
      : 'هيتسجّل في حساب المندوب بس، وبيتحاسب عليه وقت تقفيل حساب المندوب (مش بيتخصم من العهدة دلوقتي).';
    submit.textContent = '💾 حفظ في حساب المندوب';
  }

  rep.addEventListener('change', sync);
  kind.addEventListener('change', sync);
  sync();
})();
