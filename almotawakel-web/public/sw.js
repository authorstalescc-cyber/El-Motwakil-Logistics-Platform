/*
 * Service Worker — نظام المكتب **وبوابة المندوب** (PWA).
 *
 * ⚠️ تطبيق ورا تسجيل دخول، فالقاعدة الذهبية: **ممنوع كاش أي صفحة HTML**.
 *    كل تنقّل بيروح للشبكة الأول (network-first)، ولو مفيش نت بنعرض صفحة
 *    «مفيش نت» الثابتة — عمرنا ما بنرجّع صفحة محفوظة فيها بيانات مندوب
 *    (وإلا ممكن تتعرض بيانات قديمة أو بتاعة مندوب تاني على نفس الجهاز).
 *
 *    الكاش للأصول الثابتة بس (CSS/أيقونات/صور) — مالهاش أي بيانات حسّاسة.
 *
 * ⚠️ لازم تزوّد VERSION مع أي تعديل هنا أو في صفحات «مفيش نت»، وإلا الأجهزة
 *    المثبَّت عليها التطبيق هتفضل شغّالة بالنسخة القديمة من الكاش.
 */
const VERSION = 'alm-v3';
const STATIC_CACHE = 'alm-static-' + VERSION;
const OFFLINE_M = '/assets/m-offline.html';        // بوابة المندوب (غامقة)
const OFFLINE_OFFICE = '/assets/offline.html';     // نظام المكتب (بلوجو الشركة)
const PRECACHE = [
  OFFLINE_M, OFFLINE_OFFICE,
  '/assets/img/logo.png',
  '/assets/img/pwa/icon-192.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== STATIC_CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') { return; }                 // POST وغيره: عدّي زي ما هو
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }  // نطاق تاني: بلاش نتدخّل

  // تنقّل بين الصفحات: الشبكة أولًا، وصفحة أوفلاين ثابتة كبديل — بلا كاش HTML.
  if (req.mode === 'navigate') {
    const offline = url.pathname.startsWith('/m') ? OFFLINE_M : OFFLINE_OFFICE;
    event.respondWith(
      fetch(req).catch(() => caches.match(offline, { ignoreSearch: true }))
    );
    return;
  }

  // الأصول الثابتة بس: من الكاش فورًا ونحدّثه في الخلفية (stale-while-revalidate).
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.open(STATIC_CACHE).then((cache) =>
        cache.match(req).then((cached) => {
          const network = fetch(req).then((res) => {
            if (res && res.status === 200 && res.type === 'basic') { cache.put(req, res.clone()); }
            return res;
          }).catch(() => cached);
          return cached || network;
        })
      )
    );
  }
  // أي حاجة تانية: عدّي للشبكة عادي.
});

/* ══════════════════════════════════════════════════════════════════════════
   الإشعارات (Web Push)

   ⚠️ **لازم نعرض إشعار مع كل رسالة دفع بلا استثناء.** لو استقبلنا رسالة
      وما عرضناش حاجة، المتصفح بيعرض إشعارًا افتراضيًّا بنفسه بنص زي «هذا
      الموقع تم تحديثه في الخلفية» — أوحش من إننا ما نبعتش أصلاً. عشان كده
      حتى لو الحمولة بايظة بنعرض رسالة عامة.

   ⚠️ الحمولة **مشفّرة من طرف لطرف** (RFC 8291): جوجل/آبل بيوصّلوها وهُمّ
      مش قادرين يقروها — مهم لأن نصّها فيه أسماء عملاء ومبالغ.
   ══════════════════════════════════════════════════════════════════════════ */
self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = {}; }
  const title = d.title || 'المتوكل';
  const options = {
    body: d.body || '',
    icon: '/assets/img/pwa/icon-192.png',
    badge: '/assets/img/pwa/icon-192.png',
    dir: 'rtl',
    lang: 'ar',
    tag: d.tag || 'alm',
    renotify: true,                       // نفس النوع يحدّث الإشعار بدل ما يتكوّم
    data: { url: d.url || '/' },
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

/* الضغط على الإشعار: لو التطبيق مفتوح بالفعل بنوجّهه للصفحة بدل ما نفتح نسخة
   تانية — من غير كده المستخدم بيلاقي عنده تبويبين للنظام. */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if (c.url.indexOf(self.location.origin) === 0 && 'focus' in c) {
          if ('navigate' in c) { return c.navigate(target).then((w) => (w ? w.focus() : null)); }
          return c.focus();
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(target) : null;
    })
  );
});
