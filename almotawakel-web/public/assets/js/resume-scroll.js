/**
 * الرجوع لنفس المكان بعد الحفظ — وحدة مشتركة بين نظام المكتب وبوابة المندوب.
 *
 * ⚠️ ملف مستقل عن `app.js` عن قصد: بوابة المندوب `/m` **ما بتحمّلش app.js**
 *    (شاشاتها خارج الـlayout وبتشارك `m.css` بس)، فلو الوحدة فضلت جوّه app.js
 *    كانت هتخدم نص النظام بس. أي شاشة جديدة محتاجة السلوك ده تحمّل الملف ده
 *    بـ`defer` وخلاص — مفيش أي تهيئة ولا اعتماد على متغيّرات برّه.
 *
 * بيتحمّل من: app/Views/layout.php (المكتب) · app/Views/m/_pwa.php (المندوب)
 */
(function () {
  'use strict';
/* ══════════════════════════════════════════════════════════════════
   الرجوع لنفس المكان بعد الحفظ (2026-07-25)

   المشكلة: النظام كله بيشتغل بنمط POST → redirect → GET (وده الصح: بيمنع
   إعادة إرسال النموذج لو المستخدم حدّث الصفحة). لكن الـGET الجديد بيبدأ من
   **أول الصفحة**، فبعد كل حفظ المستخدم بيرجع فوق ويدوّر على البند اللي كان
   شغّال عليه — وفي صفحة فيها ٥٠ بند دي مهمة مرهقة تتكرر مع كل حفظة.

   ليه مش بالمراسي (#anchor) على الخادم؟ لأنها تحتاج تعديل ١٥٠+ استدعاء
   `redirect()` في ٣٠ كنترولر (وأي واحد جديد يتنسي)، وكمان بتحطّ البند في
   **أعلى الشاشة** مش في مكانه اللي كنت شايفه فيه — فلسه بتحس إن الصفحة نطّت.

   الحل هنا **عام وتلقائي**: قبل أي إرسال بنحفظ في `sessionStorage` مكان
   العنصر اللي بتشتغل عليه ومسافته من أول الشاشة، وبعد ما الصفحة ترجع
   بنرجّعك لنفس المنظر بالظبط. بيشتغل على كل صفحة بتحمّل `app.js` — الحالية
   والجاية — بلا سطر واحد في أي كنترولر.

   ⚠️ الربط بعنصر (`id`) مش برقم تمرير مطلق عن قصد: بعد الحفظ المحتوى فوق
      البند بيتغيّر ارتفاعه كتير (رسالة نجاح، بند اتشال، قايمة اتفلترت)،
      فالرقم المطلق كان هيوديك لمكان غلط. باستخدام العنصر بنرجع لنفس
      **المنظر** حتى لو اللي فوقه اتحرّك.
   ══════════════════════════════════════════════════════════════════ */
const SCROLL_KEY = 'almotawakel:resume-scroll';
const SCROLL_TTL = 120000;   // دقيقتان: أطول من أي حفظ، وأقصر من إنك ترجع للصفحة بعدين

function scrollStore(value) {
  try {
    if (value === null) { sessionStorage.removeItem(SCROLL_KEY); return null; }
    if (value === undefined) { return JSON.parse(sessionStorage.getItem(SCROLL_KEY) || 'null'); }
    sessionStorage.setItem(SCROLL_KEY, JSON.stringify(value));
  } catch (err) { /* وضع التصفح الخاص بيرفض التخزين — نكمل عادي بلا الميزة */ }
  return null;
}

/** أقرب عنصر أب له `id` وثابت في تدفّق الصفحة (مش fixed زي شريط الإجراءات). */
function scrollAnchorOf(node) {
  let el = node;
  while (el && el !== document.body) {
    if (el.id && getComputedStyle(el).position !== 'fixed') { return el; }
    el = el.parentElement;
  }
  return null;
}

document.addEventListener('submit', (e) => {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || form.classList.contains('js-ajax')) { return; }
  // فورم بيفتح تبويبة تانية (طباعة/تنزيل) ما بيغيّرش الصفحة الحالية أصلاً
  if (form.target && form.target !== '_self') { return; }

  // ⚠️ `e.submitter` هو الزرار اللي اتداس فعلاً — وده مهم في الشاشات اللي
  //    بتربط الزرار بفورم مخفي بـ`form="..."` (صندوق مراجعة بنود المناديب):
  //    هناك `e.target` بيبقى الفورم المخفي في آخر الصفحة، مش مكان الشغل.
  const origin = e.submitter || form;
  const anchor = scrollAnchorOf(origin);
  scrollStore({
    p: location.pathname + location.search,
    y: window.scrollY,
    id: anchor ? anchor.id : '',
    dy: anchor ? Math.round(anchor.getBoundingClientRect().top) : 0,
    t: Date.now()
  });
}, true);   // capture: يتسجّل حتى لو معالج تاني وقف الحدث

/* ⚠️ بنقرا المحفوظ **مرة واحدة** ونمسحه فورًا، وبنحتفظ بيه في متغيّر عشان
   نعيد تطبيقه بعد `load`. لو سبنا المسح لآخر تطبيق، ريفريش عادي بعدها كان
   هيرجّعك لمكان قديم مش انت طالبه. */
let pendingResume = (function () {
  const saved = scrollStore();
  scrollStore(null);
  if (!saved) { return null; }
  // نفس الصفحة بالظبط (بعد PRG الوجهة هي نفس المسار)، وفي وقت معقول
  if (saved.p !== location.pathname + location.search || (Date.now() - saved.t) > SCROLL_TTL) { return null; }
  return saved;
})();

function applyResume(flash) {
  if (!pendingResume) { return; }
  const el = pendingResume.id ? document.getElementById(pendingResume.id) : null;
  // نفس المنظر: العنصر يرجع على نفس بُعده من أول الشاشة.
  // لو اتشال (اتعتمد/اترفض) بنرجع لأقرب مكان بالرقم المطلق.
  const y = el
    ? el.getBoundingClientRect().top + window.scrollY - pendingResume.dy
    : pendingResume.y;
  const max = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
  window.scrollTo(0, Math.min(Math.max(0, Math.round(y)), max));

  // لمسة توجيه: نلمّع العنصر ثانية واحدة عشان العين تلاقيه فورًا
  if (flash && el && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    el.classList.add('mv-flash');
    setTimeout(() => el.classList.remove('mv-flash'), 1000);
  }
}
// مرتين: دلوقتي (الـDOM جاهز — السكربت في آخر الصفحة)، وبعد تحميل الصور
// لأنها بتغيّر الارتفاعات فتزيح المكان. التانية بتصحّح الأولى وبتغلب أي
// قفزة عملها المتصفح لمرساة في الرابط.
applyResume(true);
window.addEventListener('load', () => { applyResume(false); pendingResume = null; });})();
