// قائمة الموبايل
const burger = document.querySelector('.burger');
const nav = document.querySelector('.nav');
if (burger && nav) {
  burger.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    burger.setAttribute('aria-expanded', String(open));
  });
  nav.addEventListener('click', e => {
    if (e.target.tagName === 'A') { nav.classList.remove('open'); burger.setAttribute('aria-expanded', 'false'); }
  });
}

const REDUCE = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// ظهور تدريجي عند التمرير — يشمل .rv و[data-rv] و.stagger و.sec-head
const items = document.querySelectorAll('.rv, [data-rv], .stagger, .sec-head, .sub-head');
if (items.length && !REDUCE) {
  const io = new IntersectionObserver((entries) => {
    entries.forEach(en => {
      if (!en.isIntersecting) return;
      en.target.classList.add('in');
      io.unobserve(en.target);
    });
  }, { threshold: 0.01, rootMargin: '0px 0px -50px' });
  items.forEach(el => io.observe(el));

  // شبكة أمان: لو المراقب ما رصدش عنصرًا لأي سبب (مساحة صفر، قياس متأخر…)
  // نظهّر أي عنصر دخل مجال الرؤية — عشان مستحيل يفضل محتوى مخفي.
  const sweep = () => {
    let left = 0;
    items.forEach(el => {
      if (el.classList.contains('in')) return;
      left++;
      const r = el.getBoundingClientRect();
      if (r.top < window.innerHeight * 1.15) { el.classList.add('in'); io.unobserve(el); }
    });
    if (!left) window.removeEventListener('scroll', sweep);
  };
  window.addEventListener('scroll', sweep, { passive: true });
  window.addEventListener('load', sweep);
  setTimeout(sweep, 900);
} else {
  items.forEach(el => el.classList.add('in'));
}

// شريط تقدّم القراءة أعلى الصفحة
if (!REDUCE) {
  const bar = document.createElement('div');
  bar.className = 'sprog';
  bar.innerHTML = '<i></i>';
  document.body.appendChild(bar);
  const fill = bar.firstElementChild;
  const onProg = () => {
    const max = document.documentElement.scrollHeight - window.innerHeight;
    fill.style.width = (max > 0 ? (window.scrollY / max) * 100 : 0) + '%';
  };
  onProg();
  window.addEventListener('scroll', onProg, { passive: true });
  window.addEventListener('resize', onProg, { passive: true });
}

// انزياح خفيف (parallax) لخريطة الهيرو ونسيج اللوجو
if (!REDUCE) {
  const mapw = document.querySelector('.hero .mapwrap');
  if (mapw) {
    let ticking = false;
    const onPar = () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        const y = Math.min(window.scrollY, 700);
        mapw.style.transform = 'translateY(' + (y * 0.14) + 'px)';
        ticking = false;
      });
    };
    window.addEventListener('scroll', onPar, { passive: true });
  }
}

// عدّاد متحرك للإحصائيات — يبدأ عند ظهور القسم
const stats = document.querySelectorAll('.stat b');
if (stats.length) {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const run = el => {
    const raw = el.textContent.trim();
    const m = raw.match(/(\D*)(\d[\d,]*)(.*)/s);   // يفصل الرقم عن اللاحقة (+ / %)
    if (!m || reduce) return;
    const [, pre, numStr, post] = m;
    const target = parseInt(numStr.replace(/,/g, ''), 10);
    if (!isFinite(target)) return;
    const dur = 1500, t0 = performance.now();
    el.style.minWidth = el.offsetWidth + 'px';      // يمنع اهتزاز التخطيط أثناء العد
    const tick = now => {
      const p = Math.min((now - t0) / dur, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = pre + Math.round(target * eased).toLocaleString('en-US') + post;
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };
  const so = new IntersectionObserver((es) => {
    es.forEach(e => { if (e.isIntersecting) { run(e.target); so.unobserve(e.target); } });
  }, { threshold: 0.5 });
  stats.forEach(el => so.observe(el));
}

// نموذج طلب عرض سعر — يفتح واتساب برسالة جاهزة (بدون سيرفر)
const form = document.querySelector('#quoteForm');
if (form) {
  form.addEventListener('submit', e => {
    e.preventDefault();
    const v = id => (form.querySelector('#' + id)?.value || '').trim();
    const isEn = document.documentElement.lang === 'en';
    const lines = isEn ? [
      'Price Quotation Request — Al-Mutawakkil Website',
      '—————————————',
      'Name: ' + v('q-name'),
      'Company: ' + v('q-company'),
      'Phone: ' + v('q-phone'),
      'Service: ' + v('q-service'),
      'From: ' + v('q-from') + '  To: ' + v('q-to'),
      'Cargo: ' + v('q-cargo'),
      'Details: ' + v('q-notes'),
    ].filter(l => !/: $/.test(l)) : [
      'طلب عرض سعر — من موقع المتوكل',
      '—————————————',
      'الاسم: ' + v('q-name'),
      'الشركة: ' + v('q-company'),
      'التليفون: ' + v('q-phone'),
      'الخدمة: ' + v('q-service'),
      'من: ' + v('q-from') + '  إلى: ' + v('q-to'),
      'الحمولة: ' + v('q-cargo'),
      'تفاصيل: ' + v('q-notes'),
    ].filter(l => !/: $/.test(l));
    const phone = document.body.dataset.wa;
    window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(lines.join('\n')), '_blank');
  });
}

// ظل الهيدر بعد التمرير
const hd = document.querySelector('.hd');
if (hd) {
  const onScroll = () => hd.classList.toggle('stuck', window.scrollY > 12);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });
}
