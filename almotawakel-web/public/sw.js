/*
 * Service Worker — بوابة المندوب (PWA).
 *
 * ⚠️ تطبيق ورا تسجيل دخول، فالقاعدة الذهبية: **ممنوع كاش أي صفحة HTML**.
 *    كل تنقّل بيروح للشبكة الأول (network-first)، ولو مفيش نت بنعرض صفحة
 *    «مفيش نت» الثابتة — عمرنا ما بنرجّع صفحة محفوظة فيها بيانات مندوب
 *    (وإلا ممكن تتعرض بيانات قديمة أو بتاعة مندوب تاني على نفس الجهاز).
 *
 *    الكاش للأصول الثابتة بس (CSS/أيقونات/صور) — مالهاش أي بيانات حسّاسة.
 */
const VERSION = 'alm-m-v1';
const STATIC_CACHE = 'alm-static-' + VERSION;
const OFFLINE_URL = '/assets/m-offline.html';
const PRECACHE = [OFFLINE_URL, '/assets/img/app-icon.svg', '/assets/img/logo.png'];

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
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL, { ignoreSearch: true }))
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
