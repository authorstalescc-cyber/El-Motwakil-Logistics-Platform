/* نظام المتوكل — سكربت الواجهة */
(function () {
  'use strict';

  /* ══════════════════════════════════════════════════════════════════════════
     تطبيع نص البحث — **نظير مطابق لـ`search_norm()` في helpers.php**
     ⚠️ لازم الاتنين يفضلوا متطابقين: الخادم بيطبّع `data-search` والواجهة
        بتطبّع اللي المستخدم بيكتبه، فلو اختلفوا البحث بيرجّع صفر نتائج لنص
        موجود فعلاً. اتصادف في شاشة التقفيل: نثرية محفوظة «اكراميه أمن cma»
        والبحث بـ«إكرامية» ما لقاهاش، فبان إن البند مش موجود وهو موجود ومحسوب.
     ══════════════════════════════════════════════════════════════════════════ */
  const AR_SEARCH_MAP = {
    'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا',
    'ة': 'ه', 'ى': 'ي', 'ئ': 'ي', 'ؤ': 'و',
    'ـ': '',
    'ً': '', 'ٌ': '', 'ٍ': '', 'َ': '', 'ُ': '',
    'ِ': '', 'ّ': '', 'ْ': '', 'ٓ': '', 'ٔ': '', 'ٕ': '',
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
    '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
    '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9'
  };
  function searchNorm(value) {
    let out = '';
    const s = String(value == null ? '' : value).toLocaleLowerCase('ar');
    for (const ch of s) { out += (ch in AR_SEARCH_MAP) ? AR_SEARCH_MAP[ch] : ch; }
    return out.replace(/\s+/g, ' ').trim();
  }
  window.almSearchNorm = searchNorm;

  const focusableSelector = 'a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  function activateDialog(container, preferred) {
    container.__returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    container.__focusTrap = (e) => {
      if (e.key !== 'Tab') return;
      const nodes = Array.from(container.querySelectorAll(focusableSelector)).filter((el) => !el.hidden && el.offsetParent !== null);
      if (!nodes.length) { e.preventDefault(); return; }
      const first = nodes[0], last = nodes[nodes.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    container.addEventListener('keydown', container.__focusTrap);
    setTimeout(() => { if (preferred) preferred.focus(); }, 20);
  }
  function deactivateDialog(container) {
    if (!container) return;
    if (container.__focusTrap) container.removeEventListener('keydown', container.__focusTrap);
    const back = container.__returnFocus;
    container.__focusTrap = null; container.__returnFocus = null;
    if (back && back.isConnected) setTimeout(() => back.focus(), 0);
  }

  /* ══════════════════════════════════════════════════════════════════════════
     التنبيهات الطايرة (Toasts) — المرحلة ١
     نظام **واحد** بيخدم رسائل الخادم (flash_all عبر window.__TOASTS) ورسائل
     الـAJAX بنفس الشكل بالظبط، فنفس الإجراء يدّي نفس التغذية الراجعة سواء
     اتنفّذ بتحميل كامل ولا بـfetch.
     اللي اتصلّح عن النظام القديم:
       • كان عنصر **مفرد** — تاني رسالة بتمسح الأولى. دلوقتي كومة بطابور.
       • كان بلا aria-live — قارئ الشاشة ما بيسمعهوش. دلوقتي منطقة معلَنة.
       • كان بـpointer-events:none — مستحيل تقفله. دلوقتي زر ✕ و Esc.
       • **الخطأ ما بيختفيش لوحده أبدًا** (كان بيروح بعد 2.6 ثانية زي النجاح).
       • الوقوف بالماوس/التركيز بالكيبورد بيوقف العدّاد.
     ══════════════════════════════════════════════════════════════════════════ */
  const TOAST_MAX = 3;                                   // الظاهر في المرة الواحدة
  const TOAST_LIFE = { ok: 5000, info: 5000, warn: 8000, err: 0 };  // 0 = ما يختفيش لوحده
  const TOAST_ICON = {
    ok:   '<path d="M20 6 9 17l-5-5"/>',
    err:  '<circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/>',
    warn: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>'
  };
  const toastQueue = [];
  let toastStack = null;

  function toastRoot() {
    if (toastStack && toastStack.isConnected) return toastStack;
    toastStack = document.getElementById('toastStack');
    if (!toastStack) {
      toastStack = document.createElement('div');
      toastStack.id = 'toastStack';
      toastStack.className = 'toast-stack';
      toastStack.setAttribute('role', 'region');
      toastStack.setAttribute('aria-label', 'تنبيهات');
      // ⚠️ من غير قصد ما نحطّش aria-live هنا — شوف التعليق في layout.php:
      //    سياسة الأب بتغلب على role="alert" بتاع التنبيه، فالخطأ كان بيتعلن
      //    «بأدب» بدل ما يقاطع. الإعلان بقى من المنطقتين المخصّصتين تحت.
      document.body.appendChild(toastStack);
    }
    return toastStack;
  }

  /**
   * يعلن نص التنبيه لقارئ الشاشة في المنطقة اللي تخصّه:
   * الخطأ في منطقة assertive (يقاطع فورًا)، والباقي في polite (يستنى دوره).
   * المنطقتان في layout.php؛ ولو الصفحة مالهاش layout بنعملهم عند الحاجة.
   */
  function toastAnnounce(msg, kind) {
    const id = kind === 'err' ? 'toastLiveAssertive' : 'toastLivePolite';
    let region = document.getElementById(id);
    if (!region) {
      region = document.createElement('div');
      region.id = id;
      region.className = 'sr-only';
      region.setAttribute('role', kind === 'err' ? 'alert' : 'status');
      region.setAttribute('aria-live', kind === 'err' ? 'assertive' : 'polite');
      document.body.appendChild(region);
    }
    // التفريغ ثم الكتابة في إطار تاني: نفس النص مرتين ورا بعض ما بيتعلنش
    // من غير التغيير ده (المتصفح بيشوفه «ما اتغيّرش»).
    region.textContent = '';
    requestAnimationFrame(() => { region.textContent = msg; });
  }

  function toastClose(el) {
    if (!el || el.__closing) return;
    el.__closing = true;
    clearTimeout(el.__timer);
    el.classList.add('out');
    const done = () => {
      el.remove();
      const next = toastQueue.shift();
      if (next) toastShow(next);
    };
    // ما نعتمدش على animationend لوحده: لو الحركة متعطّلة (reduced motion) ما بتحصلش
    el.addEventListener('animationend', done, { once: true });
    setTimeout(() => { if (el.isConnected) done(); }, 400);
  }

  /**
   * toast(msg, kind, opts)
   *   kind: 'ok' | 'err' | 'warn' | 'info'   (الافتراضي 'ok')
   *   opts.action = { label, url, method:'post', csrf, confirm } → زر إجراء جوّه الرسالة
   *   opts.life  = مهلة مخصّصة بالملّي، أو 0 عشان ما يختفيش لوحده
   */
  function toast(msg, kind, opts) {
    if (!msg) return null;
    kind = TOAST_ICON[kind] ? kind : 'ok';
    opts = opts || {};
    const root = toastRoot();
    if (root.children.length >= TOAST_MAX) {          // الزيادة تستنى في الطابور
      toastQueue.push([msg, kind, opts]);
      return null;
    }
    return toastShow([msg, kind, opts]);
  }

  function toastShow(entry) {
    const [msg, kind, opts] = entry;
    const root = toastRoot();
    const el = document.createElement('div');
    el.className = 'toast-item ' + kind;
    // الإعلان بيحصل في المنطقة المخصّصة (toastAnnounce) مش على العنصر ده، عشان
    // ما يتقريش مرتين. العنصر نفسه يفضل موجود للماوس والكيبورد (زرار الإغلاق).
    el.setAttribute('role', 'group');
    toastAnnounce(msg, kind);

    const ic = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    ic.setAttribute('viewBox', '0 0 24 24'); ic.setAttribute('width', '19'); ic.setAttribute('height', '19');
    ic.setAttribute('fill', 'none'); ic.setAttribute('stroke', 'currentColor');
    ic.setAttribute('stroke-width', '2'); ic.setAttribute('stroke-linecap', 'round');
    ic.setAttribute('stroke-linejoin', 'round'); ic.setAttribute('aria-hidden', 'true');
    ic.innerHTML = TOAST_ICON[kind];
    const icWrap = document.createElement('span');
    icWrap.className = 'toast-ic'; icWrap.appendChild(ic);

    const body = document.createElement('div');
    body.className = 'toast-body';
    const text = document.createElement('span');
    text.className = 'toast-msg';
    text.textContent = msg;                            // textContent — مفيش أي حقن HTML
    body.appendChild(text);

    if (opts.action && opts.action.label && opts.action.url) {
      const act = document.createElement('button');
      act.type = 'button'; act.className = 'toast-act';
      act.textContent = opts.action.label;
      act.addEventListener('click', () => runToastAction(opts.action, el));
      body.appendChild(act);
    }

    const x = document.createElement('button');
    x.type = 'button'; x.className = 'toast-x';
    x.setAttribute('aria-label', 'إغلاق التنبيه');
    x.textContent = '✕';
    x.addEventListener('click', () => toastClose(el));

    el.appendChild(icWrap); el.appendChild(body); el.appendChild(x);

    const life = typeof opts.life === 'number' ? opts.life : TOAST_LIFE[kind];
    if (life > 0) {
      const bar = document.createElement('i');
      bar.className = 'toast-bar';
      bar.style.animationDuration = life + 'ms';
      el.appendChild(bar);
      el.__timer = setTimeout(() => toastClose(el), life);
      // الوقوف بالماوس/التركيز بيوقف العدّاد فعليًا مش الشريط بس
      const hold = () => clearTimeout(el.__timer);
      const resume = () => { el.__timer = setTimeout(() => toastClose(el), life); };
      el.addEventListener('mouseenter', hold);  el.addEventListener('focusin', hold);
      el.addEventListener('mouseleave', resume); el.addEventListener('focusout', resume);
    }

    root.appendChild(el);
    return el;
  }

  /** إجراء داخل التنبيه (زي «تراجع») — بيتبعت POST بنموذج مؤقّت ومعاه CSRF. */
  function runToastAction(action, el) {
    const go = () => {
      const f = document.createElement('form');
      f.method = 'post'; f.action = action.url; f.className = 'no-confirm';
      f.style.display = 'none';
      if (action.csrf) {
        const t = document.createElement('input');
        t.type = 'hidden'; t.name = '_csrf'; t.value = action.csrf;
        f.appendChild(t);
      }
      document.body.appendChild(f);
      f.submit();
    };
    if (action.confirm && window.AppUI && typeof window.AppUI.confirm === 'function') {
      window.AppUI.confirm(action.confirm).then((yes) => { if (yes) { toastClose(el); go(); } });
      return;
    }
    toastClose(el); go();
  }

  // Esc يقفل أحدث تنبيه مفتوح
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || !toastStack) return;
    const last = toastStack.lastElementChild;
    if (last) { e.stopPropagation(); toastClose(last); }
  });

  // رسائل الخادم (flash_all) — تتعرض بنفس المكوّن بالظبط
  document.addEventListener('DOMContentLoaded', () => {
    const seed = Array.isArray(window.__TOASTS) ? window.__TOASTS : [];
    seed.forEach((t) => { if (t && t.msg) toast(t.msg, t.kind, { action: t.action }); });
  });

  /* ⚠️ التنبيه متاح للكود اللي برّه الـIIFE دي (تبديل السمة مثلاً). من غير
     السطر ده أي `window.AppUI.toast(...)` بيبقى **خطّاف بلا تنفيذ** فيفشل
     بالسكوت — نفس عائلة مصيدة `.table-mobile-scroll` الموثَّقة. */
  window.AppUI = Object.assign(window.AppUI || {}, { toast });

  /* ───────── توحيد الأرقام: أي رقم عربي/فارسي يتحوّل فورًا لإنجليزي في أي خانة ─────────
     نعترض على مستوى beforeinput لأن <input type="number"> يرفض الأرقام العربية أصلًا
     (فلا تصل لـ value)، فنحوّلها قبل أن يمسحها المتصفح. مع طبقة input احتياطية للّصق
     والإكمال التلقائي في الخانات النصية. الخوادم تكرّر التحويل كأمان (ar2en_normalize_request). */
  const AR_DIGITS = /[٠-٩۰-۹]/;
  function toLatinDigits(str) {
    return String(str)
      .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
      .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0));
  }
  function isEditable(el) {
    if (!el || (el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA')) return false;
    const t = (el.type || 'text').toLowerCase();
    return t !== 'password' && t !== 'file';
  }
  // beforeinput: يلتقط الكتابة واللصق قبل التعقيم — يشمل خانات الأرقام
  document.addEventListener('beforeinput', (e) => {
    const el = e.target;
    if (!isEditable(el)) return;
    let data = e.data;
    if (data == null && e.dataTransfer) { try { data = e.dataTransfer.getData('text'); } catch (_) {} }
    if (typeof data !== 'string' || !AR_DIGITS.test(data)) return;
    const latin = toLatinDigits(data);
    if (latin === data) return;
    e.preventDefault();
    let start = null, end = null;
    try { start = el.selectionStart; end = el.selectionEnd; } catch (_) {}
    if (start !== null && end !== null) {
      const v = el.value;
      el.value = v.slice(0, start) + latin + v.slice(end);
      const pos = start + latin.length;
      try { el.setSelectionRange(pos, pos); } catch (_) {}
    } else {
      // خانات لا تدعم تحديد المؤشّر (type=number/date…): نضيف في مكان النهاية
      el.value = (el.value || '') + latin;
    }
    // نُطلق input لتشتغل الحسابات الحيّة (recompute…)
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, true);
  // input: شبكة أمان للخانات النصية (نفس الطول ⇒ المؤشّر لا يتزحزح)
  document.addEventListener('input', (e) => {
    const el = e.target;
    if (!isEditable(el) || !AR_DIGITS.test(el.value)) return;
    let start = null, end = null;
    try { start = el.selectionStart; end = el.selectionEnd; } catch (_) {}
    el.value = toLatinDigits(el.value);
    if (start !== null) { try { el.setSelectionRange(start, end); } catch (_) {} }
  }, true);

  /* ══════════════════════════════════════════════════════════════════════════
     درج القائمة على الموبايل — المرحلة ٣
     كان: display:none/flex بلا أنيميشن، بلا خلفية معتّمة، الصفحة ورا بتفضل
     بتتمرّر، بلا focus trap، و**Escape ما بيقفلهوش**.
     ══════════════════════════════════════════════════════════════════════════ */
  const MOBILE_BP = 820;                 // متوافق مع --bp-mobile في app.css
  const menuBtn = document.querySelector('.menu-btn');
  const sidebar = document.querySelector('.sidebar');
  if (menuBtn && sidebar) {
    let scrim = null;
    const isMobile = () => window.innerWidth <= MOBILE_BP;

    const setMenu = (open) => {
      const wasOpen = sidebar.classList.contains('open');
      if (open === wasOpen) { return; }
      sidebar.classList.toggle('open', open);
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.classList.toggle('nav-open', open && isMobile());

      if (open && isMobile()) {
        if (!scrim) {
          scrim = document.createElement('div');
          scrim.className = 'nav-scrim';
          scrim.addEventListener('click', () => setMenu(false));
          document.body.appendChild(scrim);
        }
        requestAnimationFrame(() => scrim.classList.add('on'));
        activateDialog(sidebar, sidebar.querySelector('.nav a'));
      } else {
        if (scrim) { scrim.classList.remove('on'); }
        if (wasOpen) { deactivateDialog(sidebar); }
      }
    };

    menuBtn.addEventListener('click', () => setMenu(!sidebar.classList.contains('open')));
    document.addEventListener('click', (e) => {
      if (isMobile() && sidebar.classList.contains('open') &&
          !sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
        setMenu(false);
      }
    });
    // Escape يقفل الدرج (ما كانش بيعمل حاجة قبل كده)
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && sidebar.classList.contains('open') && isMobile()) {
        e.stopPropagation();
        setMenu(false);
        menuBtn.focus();
      }
    });
    // لو الشاشة كبرت والدرج مفتوح، نرجّع الصفحة لطبيعتها
    window.addEventListener('resize', () => {
      if (!isMobile() && sidebar.classList.contains('open')) { setMenu(false); }
    });
  }

  /* البند النشط ممكن يكون برّه الشاشة مع ٢٤ بند — نجيبه للعين عند التحميل.
     `block:'nearest'` عشان ما نلفّش القائمة من غير داعي لو هو ظاهر أصلاً. */
  (function scrollNavToActive() {
    const nav = document.getElementById('primary-nav');
    const active = nav && nav.querySelector('a.active');
    if (!active) return;
    const navBox = nav.getBoundingClientRect();
    const itemBox = active.getBoundingClientRect();
    if (itemBox.top < navBox.top || itemBox.bottom > navBox.bottom) {
      active.scrollIntoView({ block: 'nearest' });
    }
  })();

  /* ===== لوحة القسم في القائمة الجانبية (شريط أيقونات دائم) =====
     ⚠️ حلّ محل زرار «طي/فتح» القديم: الشريط بقى مطويًّا دايمًا والقسم بيفتح
        شاشاته في لوحة جنبه. `.nav-collapsed` وlocalStorage اتشالوا — مبقاش
        ليهم معنى ووجودهم كان هيبقى حالة تانية للقائمة بلا داعٍ.
     ⚠️ **على الموبايل ما بيشتغلش**: اللوحات بتبقى مفتوحة جوّه الدرج (CSS)،
        فأي فتح/قفل هنا كان هيخفي أقسامًا في الدرج. الحدّ ٨٢٠ زي الـCSS. */
  (function () {
    const nav = document.getElementById('primary-nav');
    if (!nav) { return; }
    const isRail = () => window.matchMedia('(min-width:821px)').matches;
    let openBtn = null;

    function closeFly() {
      if (!openBtn) { return; }
      const fly = document.getElementById(openBtn.getAttribute('data-fly'));
      if (fly) { fly.classList.remove('open'); }
      openBtn.setAttribute('aria-expanded', 'false');
      openBtn = null;
    }
    function openFly(btn) {
      closeFly();
      const fly = document.getElementById(btn.getAttribute('data-fly'));
      if (!fly) { return; }
      fly.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      openBtn = btn;
      /* ⚠️ **الموضع بيتحسب هنا لأن اللوحة `position:fixed`** — وهي كده عشان
         تهرب من قص `.nav` (اللي `overflow-y:auto` بيخلّيه يقصّ الأفقي كمان،
         فاللوحة كانت بتفتح وهي مقصوصة = «مش شغال»). `fixed` معناها الإحداثيات
         بالنسبة للشاشة، فلازم نجيبها من مكان الزرار الحالي.
         ولو طلعت برّه الشاشة من تحت بنرفعها — الأقسام الأخيرة قريبة من أسفلها. */
      const b = btn.getBoundingClientRect();
      fly.style.top = Math.round(b.top - 6) + 'px';
      const r = fly.getBoundingClientRect();
      if (r.bottom > window.innerHeight - 10) {
        fly.style.top = Math.max(10, window.innerHeight - 10 - r.height) + 'px';
      }
    }

    nav.addEventListener('click', (e) => {
      const btn = e.target.closest && e.target.closest('.nav-sec');
      if (!btn || !isRail()) { return; }
      e.preventDefault();
      if (openBtn === btn) { closeFly(); return; }
      openFly(btn);
    });
    document.addEventListener('click', (e) => {
      if (openBtn && !e.target.closest('.nav-sec-wrap')) { closeFly(); }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && openBtn) { const b = openBtn; closeFly(); b.focus(); }
    });
    window.addEventListener('resize', closeFly);
    /* ⚠️ اللوحة `fixed` والزرار جوّه قائمة بتتلفّ — فلو المستخدم لفّ القائمة
       واللوحة مفتوحة، هتفضل واقفة مكانها بعيد عن زرارها. القفل أوضح من
       إعادة حساب مستمرة. */
    nav.addEventListener('scroll', closeFly, { passive: true });
  })();

  // ===== مودال تأكيد أنيق (للحفظ والحذف في كل النماذج) =====
  const modal = (function () {
    let overlay, titleEl, msgEl, okBtn, cancelBtn, pending = null, inputEl = null;
    function build() {
      overlay = document.createElement('div');
      overlay.className = 'confirm-overlay';
      overlay.innerHTML =
        '<div class="confirm-box" role="dialog" aria-modal="true" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-message">' +
        '  <div class="confirm-ic"></div>' +
        '  <div class="confirm-title" id="app-confirm-title"></div>' +
        '  <div class="confirm-msg" id="app-confirm-message"></div>' +
        '  <div class="confirm-actions">' +
        '    <button type="button" class="btn ghost confirm-cancel">إلغاء</button>' +
        '    <button type="button" class="btn confirm-ok">تأكيد</button>' +
        '  </div>' +
        '</div>';
      document.body.appendChild(overlay);
      titleEl = overlay.querySelector('.confirm-title');
      msgEl = overlay.querySelector('.confirm-msg');
      okBtn = overlay.querySelector('.confirm-ok');
      cancelBtn = overlay.querySelector('.confirm-cancel');
      okBtn.addEventListener('click', () => {
        const cb = pending, value = inputEl ? inputEl.value : undefined;
        if (inputEl && !inputEl.readOnly && inputEl.required && !value.trim()) { inputEl.focus(); return; }
        close(); if (cb) cb(value);
      });
      cancelBtn.addEventListener('click', close);
      overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && overlay.classList.contains('open')) close(); });
    }
    function close() { pending = null; inputEl = null; if (overlay) { overlay.classList.remove('open'); deactivateDialog(overlay); } }
    function show(opts, onOk) {
      if (!overlay) build();
      const del = !!opts.danger, info = !!opts.info;
      overlay.querySelector('.confirm-box').classList.toggle('danger', del);
      overlay.querySelector('.confirm-ic').textContent = info ? 'ℹ️' : (del ? '⚠️' : '✓');
      titleEl.textContent = opts.title || (info ? 'تنبيه' : (del ? 'تأكيد الإجراء' : 'تأكيد الحفظ'));
      msgEl.textContent = opts.message || '';
      inputEl = null;
      if (opts.input) {
        inputEl = document.createElement(opts.multiline ? 'textarea' : 'input');
        inputEl.className = 'confirm-input';
        inputEl.value = opts.defaultValue || '';
        inputEl.placeholder = opts.placeholder || '';
        inputEl.required = opts.required !== false;
        inputEl.readOnly = !!opts.readOnly;
        if (!opts.multiline) inputEl.type = opts.inputType || 'text';
        msgEl.appendChild(document.createElement('br'));
        msgEl.appendChild(inputEl);
        inputEl.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !opts.multiline) { e.preventDefault(); okBtn.click(); }
        });
      }
      okBtn.textContent = opts.okText || (info ? 'حسناً' : (del ? 'نعم، نفّذ' : 'نعم، احفظ'));
      okBtn.className = 'btn confirm-ok ' + (del ? 'danger' : 'gold');
      cancelBtn.hidden = info;
      pending = onOk;
      overlay.classList.add('open');
      activateDialog(overlay, inputEl || (info ? okBtn : cancelBtn));
    }
    function notice(message, title) { show({ info: true, message, title }, null); }
    function confirm(message, onOk, opts) {
      opts = opts || {};
      show({
        danger: opts.danger !== false,
        message: message,
        title: opts.title || 'تأكيد الإجراء',
        okText: opts.okText || 'نعم، تابع'
      }, onOk);
    }
    function ask(message, onOk, opts) {
      opts = opts || {};
      show({ input: true, multiline: !!opts.multiline, inputType: opts.inputType,
        defaultValue: opts.defaultValue, placeholder: opts.placeholder, required: opts.required,
        readOnly: opts.readOnly, message: message, title: opts.title || 'أدخل القيمة',
        okText: opts.okText || 'متابعة' }, onOk);
      if (inputEl && inputEl.select) setTimeout(() => inputEl.select(), 30);
    }
    return { show, notice, confirm, ask };
  })();
  window.AppUI = Object.assign(window.AppUI || {}, { notice: modal.notice, confirm: modal.confirm, ask: modal.ask });

  // ===== نافذة منبثقة للنماذج (إضافة/تعديل إيصال/بوليصة/بند في مكانها) =====
  // تنقل نموذجًا موجودًا (مخفيًّا) إلى نافذة مركزية، وتُعيده لمكانه عند الإلغاء،
  // أو تتخلّص منه عند نجاح الحفظ (لأن الحركة تُعاد رسمها بالكامل).
  const formModal = (function () {
    let overlay, titleEl, bodyEl, origin = null;   // origin: {parent, next, node, wasHidden}
    function build() {
      overlay = document.createElement('div');
      overlay.className = 'form-modal-overlay';
      overlay.innerHTML =
        '<div class="form-modal-box" role="dialog" aria-modal="true" aria-labelledby="app-form-modal-title">' +
        '  <div class="form-modal-head"><span class="fm-title" id="app-form-modal-title"></span>' +
        '    <button type="button" class="fm-close" aria-label="إغلاق">✕</button></div>' +
        '  <div class="form-modal-body"></div>' +
        '</div>';
      document.body.appendChild(overlay);
      titleEl = overlay.querySelector('.fm-title');
      bodyEl = overlay.querySelector('.form-modal-body');
      overlay.querySelector('.fm-close').addEventListener('click', () => close(true));
      overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(true); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && overlay.classList.contains('open')) close(true); });
    }
    function open(node, title) {
      if (!node) return;
      if (!overlay) build();
      if (origin) close(true);   // أرجِع أي نموذج مفتوح سابقًا
      origin = { parent: node.parentNode, next: node.nextSibling, node: node, wasHidden: node.hidden };
      node.hidden = false;
      const more = node.querySelector && node.querySelector('.ms-more');
      if (more) more.hidden = false;               // وسّع «المزيد» تلقائيًا داخل النافذة
      bodyEl.appendChild(node);
      titleEl.textContent = title || 'تعديل';
      overlay.classList.add('open');
      document.body.classList.add('cmdk-lock');
      activateDialog(overlay, null);
      setTimeout(() => {
        const f = node.querySelector('input:not([type=hidden]):not([type=file]),select,textarea');
        if (f) { f.focus(); if (f.select) try { f.select(); } catch (e) {} }
      }, 40);
    }
    // doRestore=true: أعِد النموذج لمكانه (إلغاء). false: تخلّص منه (بعد نجاح الحفظ).
    function close(doRestore) {
      if (origin) {
        const o = origin; origin = null;
        o.node.hidden = o.wasHidden;
        const more = o.node.querySelector && o.node.querySelector('.ms-more');
        if (more) more.hidden = true;
        if (doRestore && o.parent && o.parent.isConnected) {
          o.parent.insertBefore(o.node, o.next);
        } else if (o.node.parentNode) {
          o.node.parentNode.removeChild(o.node);
        }
      }
      if (overlay) overlay.classList.remove('open');
      if (overlay) deactivateDialog(overlay);
      document.body.classList.remove('cmdk-lock');
    }
    function contains(el) { return !!(overlay && overlay.contains(el)); }
    return { open, close, contains };
  })();

  // التأكيد للعمليات الحساسة فقط؛ الحفظ العادي فوري لتسريع مدخل البيانات.
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.method || '').toLowerCase() !== 'post') return;   // نماذج GET (بحث/فلترة) بدون تأكيد

    // نماذج AJAX (شاشة الإذن): حفظ فوري بدون إعادة تحميل
    if (form.classList.contains('js-ajax')) {
      const isDel = form.classList.contains('confirm-delete');
      e.preventDefault();
      if (isDel) {
        const msg = form.getAttribute('data-confirm') || 'هل أنت متأكد من الحذف؟ لا يمكن التراجع.';
        modal.show({ danger: true, message: msg }, () => ajaxSubmit(form));
      } else {
        ajaxSubmit(form);
      }
      return;
    }

    if (form.dataset.confirmed === '1') { form.dataset.confirmed = ''; return; }  // مُؤكَّد بالفعل → مرِّر
    if (form.classList.contains('no-confirm')) return;
    if (form.hasAttribute('onsubmit')) return;                  // له تأكيد خاص (مثل إغلاق الإذن)
    const needsConfirm = form.classList.contains('confirm-delete') || form.hasAttribute('data-confirm') || form.classList.contains('confirm-financial');
    if (!needsConfirm) return;

    e.preventDefault();
    const isDelete = form.classList.contains('confirm-delete') || form.dataset.danger === '1';
    const msg = form.getAttribute('data-confirm') ||
      (isDelete ? 'هل أنت متأكد من الحذف؟ لا يمكن التراجع.' : 'هل تريد حفظ هذه العملية؟');
    modal.show({ danger: isDelete, message: msg }, () => {
      form.dataset.confirmed = '1';
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
  });

  // ================= إدخال سريع + حفظ القيم + تحقق حي =================
  (function () {
    const help=document.getElementById('shortcut-help');
    if(help)help.addEventListener('click',()=>{
      const box=document.createElement('div');box.className='confirm-overlay open shortcut-overlay';box.innerHTML='<div class="confirm-box"><div class="confirm-title">اختصارات الإدخال السريع</div><div class="shortcut-list"><span><kbd>Ctrl</kbd> + <kbd>Enter</kbd></span><b>حفظ النموذج</b><span><kbd>Alt</kbd> + <kbd>N</kbd></span><b>إضافة سجل جديد</b><span><kbd>Ctrl</kbd> + <kbd>K</kbd></span><b>البحث في النظام</b><span><kbd>Esc</kbd></span><b>إغلاق النافذة</b></div><button type="button" class="btn gold">فهمت</button></div>';document.body.appendChild(box);const close=()=>box.remove();box.querySelector('button').addEventListener('click',close);box.addEventListener('click',e=>{if(e.target===box)close()});
    });
    // Ctrl/Cmd+Enter يحفظ النموذج الحالي، Alt+N يفتح إضافة جديدة.
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        const form = e.target && e.target.closest ? e.target.closest('form') : null;
        if (form) { e.preventDefault(); if (form.requestSubmit) form.requestSubmit(); else form.submit(); }
      }
      if ((e.key === 'n' || e.key === 'N') && e.altKey && !e.ctrlKey && !e.metaKey) {
        const add = document.querySelector('a[href$="/new"],a[href*="/new?"],[data-shortcut-new]');
        if (add) { e.preventDefault(); window.location.href = add.href; }
      }
    });

    // تحذير مغادرة نموذج تغيّر ولم يُحفظ.
    document.querySelectorAll('form.js-dirty-form').forEach((form) => {
      const initial = new URLSearchParams(new FormData(form)).toString();
      let submitted = false;
      form.addEventListener('submit', () => { submitted = true; });
      window.addEventListener('beforeunload', (e) => {
        if (!submitted && new URLSearchParams(new FormData(form)).toString() !== initial) { e.preventDefault(); e.returnValue = ''; }
      });
    });

    // تذكّر القيم الوصفية المتكررة في إدخال البوالص الجديدة.
    const billForm = document.querySelector('form[data-calc="bawalis"][data-form-mode="create"]');
    if (billForm) {
      const fields = ['company','destination','classification','container_type','driver','car_owner'];
      let saved = {}; try { saved = JSON.parse(localStorage.getItem('almotawakel-last-bill-fields') || '{}'); } catch (x) {}
      fields.forEach((name) => { const input=billForm.elements[name]; if(input && !input.value && saved[name]) input.value=saved[name]; });
      billForm.addEventListener('submit', () => {
        const data={}; fields.forEach((name)=>{const input=billForm.elements[name];if(input&&input.value)data[name]=input.value;});
        try { localStorage.setItem('almotawakel-last-bill-fields',JSON.stringify(data)); } catch (x) {}
      });
      billForm.addEventListener('keydown',(e)=>{
        if(e.key!=='Enter'||e.ctrlKey||e.metaKey||e.altKey||e.target.tagName==='TEXTAREA'||e.target.tagName==='BUTTON')return;
        const controls=Array.from(billForm.querySelectorAll('input:not([type=hidden]):not([readonly]),select,textarea,button[type=submit]')).filter(x=>!x.disabled&&x.offsetParent!==null);const at=controls.indexOf(e.target);if(at>=0&&at<controls.length-1){e.preventDefault();controls[at+1].focus();if(controls[at+1].select)try{controls[at+1].select()}catch(x){}}
      });
    }

    // فحص رقم البوليصة أثناء الكتابة.
    document.querySelectorAll('[data-bill-number-check]').forEach((input) => {
      const message = input.closest('.field') && input.closest('.field').querySelector('[data-bill-number-message]');
      let timer=0,seq=0;
      const check=()=>{
        const number=input.value.trim(); if(!number){if(message)message.textContent='';input.setCustomValidity('');return;}
        const mine=++seq; const url=(window.__BILL_NUMBER_CHECK_API||'')+'?number='+encodeURIComponent(number)+'&except='+encodeURIComponent(input.dataset.except||'0');
        fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(d=>{
          if(mine!==seq)return; input.setCustomValidity(d.exists ? d.message : '');
          input.classList.toggle('live-invalid',!!d.exists); input.classList.toggle('live-valid',!d.exists);
          if(message){message.textContent=d.message||'';message.className='field-live-message '+(d.exists?'error':'ok');}
        }).catch(()=>{});
      };
      input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(check,260);}); input.addEventListener('blur',check);
    });

    // حوكمة أسماء ذكية: اقتراحات + توحيد تلقائي للتهجئة + إيقاف الأسماء القريبة (اختَر الموجود / أكّد الجديد).
    // خريطة معرّف القائمة ⇒ نوع الاقتراح (تصحّح الأخطاء القديمة مثل dl_dest/dl_owner).
    const LIST_TYPE={dl_owner_decl:'owner',dl_driver:'driver',dl_owner:'car_owner',dl_ctype:'container_type',
      dl_company:'company',dl_client:'client',dl_dest:'destination',dl_shipping:'shipping_co',dl_agency:'agency',dl_port:'port'};
    const esc2=(s)=>String(s==null?'':s).replace(/[&<>"']/g,(c)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    document.querySelectorAll('input[data-smart-name],input[list^="dl_"]').forEach((input, index) => {
      let listId=input.getAttribute('list');
      if(!listId){listId='smart-name-list-'+index;const dl=document.createElement('datalist');dl.id=listId;document.body.appendChild(dl);input.setAttribute('list',listId);}
      const list=document.getElementById(listId); if(!list)return;
      const smartType=input.dataset.smartName || LIST_TYPE[listId] || listId.replace(/^dl_/,'');
      const original=Array.from(list.options).map(o=>o.value); let timer=0,seq=0;
      const note=document.createElement('div');note.className='smart-name-note';note.setAttribute('aria-live','polite');
      input.insertAdjacentElement('afterend',note);

      // حقل مخفي «اسم جديد مؤكَّد» يُرسَل للخادم ليقبل التهجئة الجديدة.
      const flagName='__newname_'+(input.name||('f'+index));
      const setNewFlag=(on)=>{ const f=input.form; if(!f)return; let h=f.querySelector('input[name="'+flagName+'"]');
        if(on){ if(!h){h=document.createElement('input');h.type='hidden';h.name=flagName;f.appendChild(h);} h.value='1'; }
        else if(h){ h.remove(); } };
      const clearState=()=>{ delete input.dataset.conflict; delete input.dataset.snapTo; input.classList.remove('name-warn'); note.innerHTML=''; };
      const confirmNew=()=>{ input.dataset.newConfirmed='1'; input.dataset.newValue=input.value.trim(); setNewFlag(true);
        delete input.dataset.conflict; input.classList.remove('name-warn');
        note.innerHTML='<span class="snn-ok">✓ تم تأكيده كاسم جديد</span>'; };
      const useExisting=(name)=>{ input.value=name; input.dataset.newConfirmed=''; setNewFlag(false); clearState();
        note.innerHTML='<span class="snn-ok">✓ تم اختيار الاسم المخزّن</span>'; input.dispatchEvent(new Event('change',{bubbles:true})); };

      const run=()=>{
        const q=input.value.trim();
        if(q.length<2){ clearState(); if(input.dataset.newConfirmed!=='1') setNewFlag(false); return; }
        // لو المستخدم غيّر القيمة بعد تأكيد «جديد» → ألغِ التأكيد
        if(input.dataset.newConfirmed==='1' && q!==input.dataset.newValue){ input.dataset.newConfirmed=''; setNewFlag(false); }
        const mine=++seq;
        fetch((window.__NAME_SUGGEST_API||'')+'?type='+encodeURIComponent(smartType)+'&q='+encodeURIComponent(q),{headers:{'X-Requested-With':'XMLHttpRequest'}})
          .then(r=>r.json()).then(d=>{
            if(mine!==seq)return;
            const items=d.items||[]; const g=d.guard||{};
            // حدّث قائمة الاقتراحات
            list.innerHTML='';[...new Set(original.concat(items.map(x=>x.name)))].slice(0,80).forEach(name=>{const o=document.createElement('option');o.value=name;list.appendChild(o);});
            if(input.dataset.newConfirmed==='1'){ return; }               // أكّد المستخدم أنه جديد
            delete input.dataset.conflict; delete input.dataset.snapTo; input.classList.remove('name-warn');
            if(g.conflict){                                              // اسم قريب مختلف ⇒ أوقِف وخيِّر
              input.dataset.conflict=g.conflict; input.classList.add('name-warn');
              note.innerHTML='<span class="snn-warn">⚠ قريب من «'+esc2(g.conflict)+'» المخزّن</span>'+
                '<span class="snn-btns"><button type="button" class="snn-use">استخدم «'+esc2(g.conflict)+'»</button>'+
                '<button type="button" class="snn-new">ده اسم جديد</button></span>';
              note.querySelector('.snn-use').onclick=()=>useExisting(g.conflict);
              note.querySelector('.snn-new').onclick=confirmNew;
            } else if(g.snapped && g.value){                            // تهجئة موحّدة ⇒ لمِّح وطبّق عند المغادرة
              input.dataset.snapTo=g.value;
              note.innerHTML='<span class="snn-hint">هيتحفظ باسم: «'+esc2(g.value)+'»</span>';
            } else { note.innerHTML=''; }
          }).catch(()=>{});
      };
      input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(run,220);});
      input.addEventListener('change',()=>{ if(input.dataset.snapTo){ input.value=input.dataset.snapTo; delete input.dataset.snapTo; note.innerHTML='<span class="snn-ok">✓ تم توحيد التهجئة</span>'; } });
    });

    // إيقاف الحفظ لو فيه اسم قريب غير محسوم (stop-and-choose) — قبل أي معالج آخر.
    document.addEventListener('submit',(e)=>{
      const form=e.target; if(!(form instanceof HTMLFormElement)) return;
      const unresolved=Array.from(form.querySelectorAll('input[data-conflict]')).filter(i=>{
        const v=i.value.trim(); return v && i.dataset.newConfirmed!=='1' && v!==i.dataset.conflict;
      });
      if(unresolved.length){
        e.preventDefault(); e.stopPropagation();
        const i=unresolved[0]; i.classList.add('name-warn','live-invalid'); i.focus();
        if(i.scrollIntoView) i.scrollIntoView({block:'center'});
        toast('فيه اسم قريب من اسم مخزّن — اختَر الموجود أو أكّد أنه جديد.', 'warn');
      }
    },true);

    // مناظر محفوظة للفلاتر الحالية لكل صفحة قائمة.
    const toolbar=document.querySelector('.toolbar'); const filter=toolbar&&toolbar.querySelector('form[method=get]');
    if(toolbar&&filter){
      const key='almotawakel-saved-views:'+location.pathname;let views=[];try{views=JSON.parse(localStorage.getItem(key)||'[]')}catch(x){}
      const box=document.createElement('span');box.className='saved-views';const select=document.createElement('select');select.innerHTML='<option value="">منظر محفوظ…</option>';
      views.forEach((v,i)=>{const o=document.createElement('option');o.value=String(i);o.textContent=v.name;select.appendChild(o)});
      select.addEventListener('change',()=>{if(select.value!=='')location.href=views[+select.value].url;});
      const save=document.createElement('button');save.type='button';save.className='btn ghost sm';save.textContent='☆ حفظ المنظر';save.addEventListener('click',()=>{window.AppUI.ask('اكتب اسمًا واضحًا للمنظر الحالي.',(name)=>{name=(name||'').trim();if(!name)return;views.push({name:name,url:location.pathname+location.search});views=views.slice(-12);localStorage.setItem(key,JSON.stringify(views));location.reload();},{title:'حفظ منظر الفلاتر',okText:'حفظ'});});
      const del=document.createElement('button');del.type='button';del.className='btn ghost sm';del.textContent='×';del.title='حذف المنظر المختار';del.addEventListener('click',()=>{if(select.value==='')return;views.splice(+select.value,1);localStorage.setItem(key,JSON.stringify(views));location.reload();});
      box.append(select,save,del);toolbar.appendChild(box);
    }
  })();

  // معاينة حيّة للحسابات في نماذج الإدخال
  const form = document.querySelector('form[data-calc]');
  if (form) {
    const calc = form.getAttribute('data-calc');
    const val = (n) => {
      const el = form.querySelector('[name="' + n + '"]');
      const v = el ? parseFloat(el.value) : 0;
      return isNaN(v) ? 0 : v;
    };
    const set = (n, v, isPct) => {
      const el = form.querySelector('[name="' + n + '"]');
      if (!el) return;
      el.value = isPct ? (v * 100).toFixed(1) + '%' : v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    const vatRate = parseFloat(form.getAttribute('data-vat') || '0.14');

    function recompute() {
      if (calc === 'bawalis') {
        // دين البوليصة = النولون − العهدة − التحويلة − الخصم + البياتة
        // بلا نولون مُدخَل ⇒ ليست ديناً بعد (بانتظار النولون)، والدين لا يكون سالباً أبداً
        // (لو الخصومات تجاوزت النولون يُثبَّت عند صفر) — مطابقةً لـ Calc::bawala.
        const debt = val('freight') <= 0 ? 0
          : Math.max(0, val('freight') - val('custody') - val('transfer') - val('discount') + val('overnight_collection'));
        set('debt', debt);
        /* مصروفات السيارة (سيارات المكتب) — مطابقة لـ Calc::bawala بالحرف.
           ⚠️ برّه معادلة الدين تمامًا: الدين فوق اتحسب خلاص وما بيتأثرش بيها.
           ⚠️ «الباقى من العهدة» بيطلع سالب عادي — ما تحطّش Math.max(0,…). */
        const EXP = ['driver_share','assistant_share','gas','kawtsh','z_kawtsh','tahia_masr',
                     'thabtan','permits','cards','overnight_expense','expense','other_expense'];
        const totalExpense = EXP.reduce((s, c) => s + val(c), 0);
        set('total_expense', totalExpense);
        set('custody_remaining', val('custody') - totalExpense);
        set('car_net', val('freight') - totalExpense);
      } else if (calc === 'takhlis') {
        const tot = val('fees') + val('expenses');
        set('total_statement', tot);
        set('remaining', tot - val('payments'));
      } else if (calc === 'generators') {
        set('remaining', val('freight') - val('payments'));
      } else if (calc === 'shamil') {
        const vat = Math.round((val('freight') + val('data_idle')) * vatRate * 100) / 100;
        set('vat', vat);
        const inv = val('freight') + val('data_idle') + vat - val('discount_ats') + val('receipts');
        set('invoice_total', inv);
        set('account_total', inv - val('payments'));
        set('profit_total', val('freight') - val('actual_freight') - val('expense'));
      }
    }
    form.querySelectorAll('input,select').forEach((el) => {
      el.addEventListener('input', recompute);
    });
    recompute();
  }

  // تفعيل/إخفاء رقم الشهادة (وارد) ورقم الإذن (صادر) حسب نوع البوليصة
  const btSelect = document.querySelector('[data-billtype]');
  if (btSelect) {
    const deps = document.querySelectorAll('[data-dep]');
    const applyType = () => {
      const t = btSelect.value; // sader | warid
      deps.forEach((d) => {
        const need = d.getAttribute('data-dep');
        const on = need === t;
        d.style.display = on ? '' : 'none';
        const inp = d.querySelector('input,select');
        if (inp) { inp.disabled = !on; if (!on) inp.value = ''; }
      });
    };
    btSelect.addEventListener('change', applyType);
    applyType();
  }

  /* ═══════════════════════════════════════════════════════════════════════
     خانات مصروفات السيارة — تظهر لسيارات المكتب بس
     ═══════════════════════════════════════════════════════════════════════
     ⚠️ **الخادم هو اللي بيقرّر** عن طريق `/cars/is-office` — ممنوع نسخة تانية
        من `OfficeCar::plateKey()` هنا (توحيد حروف عربية + فصل أرقام عن حروف)،
        لأنها هتفترق مع أول تعديل فالخانات تختفي عن سيارة مكتب **بلا أي خطأ**.
     ⚠️ الحالة الابتدائية مرسومة من الخادم، فلو الجافاسكريبت ما اشتغلش الفورم
        يفضل صح على البوليصة المحفوظة (بس مش هيتحدّث وانت بتغيّر رقم السيارة).
     ⚠️ الإخفاء **ما بيمسحش** أي قيمة متكتبة (بعكس data-dep) — رقم سيارة اتغيّر
        بالغلط كان هيضيّع الأرقام بالسكوت. */
  const carNoInput = document.querySelector('form[data-calc="bawalis"] [name="car_no"]');
  const officeEls  = document.querySelectorAll('[data-dep-office]');
  if (carNoInput && officeEls.length && window.__OFFICE_CAR_CHECK_API) {
    let seq = 0, timer = 0, lastAsked = null;
    const paint = (on, name) => {
      officeEls.forEach((el) => {
        el.hidden = !on;
        const note = el.querySelector('.fg-note');
        if (note) note.textContent = 'سيارات المكتب بس' + (on && name ? ' — ' + name : '');
      });
    };
    const ask = () => {
      const plate = carNoInput.value.trim();
      if (plate === lastAsked) return;
      lastAsked = plate;
      if (plate === '') { paint(false, ''); return; }
      const mine = ++seq;
      fetch(window.__OFFICE_CAR_CHECK_API + '?plate=' + encodeURIComponent(plate),
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((d) => { if (mine === seq) paint(!!d.office, d.name || ''); })
        // ⚠️ الفشل ما يخفيش الخانات: لو الشبكة وقعت والبوليصة لسيارة مكتب،
        //    الإخفاء كان هيمنع المالك من الإدخال بلا ما يعرف السبب.
        .catch(() => {});
    };
    carNoInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(ask, 350); });
    carNoInput.addEventListener('change', ask);
  }

  // ================= شاشة إدارة الإذن: حفظ فوري (AJAX) + اختصارات =================
  const manageRoot = document.querySelector('.permit-manage');

  /* دالة toast() المفردة القديمة اتشالت — بقى فيه تطبيق واحد بس في أول الملف
     (كومة بطابور + إغلاق + aria-live). النداءات تحت زي ما هي. */

  function ajaxSubmit(form) {
    if (form.dataset.submitting === '1') return;
    clearAjaxErrors(form);
    ensureAttemptToken(form);
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');
    const btn = form.querySelector('[type=submit]');
    if (btn) { btn.classList.add('js-busy'); btn.disabled = true; }
    const done = () => {
      form.dataset.submitting = '';
      form.removeAttribute('aria-busy');
      if (btn) { btn.classList.remove('js-busy'); btn.disabled = false; }
    };
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then((r) => {
        const ct = r.headers.get('content-type') || '';
        if (!ct.includes('application/json')) throw new Error('notjson');
        return r.json();
      })
      .then((data) => {
        done();
        if (!data.ok) {
          showAjaxErrors(form, data.errors || {}, data.flash || 'تعذّر الحفظ');
          toast(data.flash || 'تعذّر الحفظ', 'err');
          return;
        }  // النافذة تبقى مفتوحة لتصحيح الخطأ
        if (data.reload) { location.reload(); return; }
        applyMovement(data);
        if (formModal.contains(form)) formModal.close(false);   // أغلِق النافذة بعد نجاح الحفظ
        toast(data.flash || 'تم الحفظ', 'ok');
      })
      .catch(() => {
        // لا نعيد POST تلقائيًا: قد يكون الخادم نفّذ الكتابة وانقطع الرد، وإعادته تضاعفها.
        done();
        toast('تعذّر تأكيد الحفظ. راجع السجل ثم أعد المحاولة يدويًا.', 'err');
      });
  }

  function ensureAttemptToken(form) {
    let input = form.querySelector('input[name="_form_token"]');
    if (input && /^[a-f0-9]{32}$/.test(input.value)) return input.value;
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden'; input.name = '_form_token';
      form.appendChild(input);
    }
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    input.value = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return input.value;
  }

  function clearAjaxErrors(form) {
    form.querySelectorAll('[data-ajax-error-summary], .ajax-field-error').forEach((el) => el.remove());
    form.querySelectorAll('[aria-invalid="true"]').forEach((el) => {
      el.removeAttribute('aria-invalid');
      const described = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && !id.startsWith('ajax-err-'));
      if (described.length) el.setAttribute('aria-describedby', described.join(' '));
      else el.removeAttribute('aria-describedby');
    });
  }

  function showAjaxErrors(form, errors, fallback) {
    const entries = Object.entries(errors || {});
    const summary = document.createElement('div');
    summary.className = 'form-error-summary';
    summary.dataset.ajaxErrorSummary = '1';
    summary.setAttribute('role', 'alert');
    summary.tabIndex = -1;
    summary.textContent = fallback;
    form.prepend(summary);
    let first = null;
    entries.forEach(([name, message], index) => {
      if (name === '_form') return;
      const field = form.elements.namedItem(name);
      const input = field && typeof field.length === 'number' && !field.tagName ? field[0] : field;
      if (!input || !input.setAttribute) return;
      const id = 'ajax-err-' + Date.now() + '-' + index;
      input.setAttribute('aria-invalid', 'true');
      const described = (input.getAttribute('aria-describedby') || '').trim();
      input.setAttribute('aria-describedby', (described ? described + ' ' : '') + id);
      const error = document.createElement('small');
      error.id = id; error.className = 'field-error ajax-field-error'; error.setAttribute('role', 'alert');
      error.textContent = String(message);
      input.insertAdjacentElement('afterend', error);
      if (!first) first = input;
    });
    (first || summary).focus();
  }

  function applyMovement(data) {
    const wrap = document.getElementById('movementsWrap');
    if (!wrap) return;
    if (data.removed) {
      const el = document.getElementById('movement-' + data.removed);
      if (el) el.remove();
      bumpCount(); ensureEmpty(); return;
    }
    if (typeof data.html !== 'string' || !data.movementId) return;
    const tmp = document.createElement('div');
    tmp.innerHTML = data.html.trim();
    const fresh = tmp.firstElementChild;
    if (!fresh) return;
    const existing = document.getElementById('movement-' + data.movementId);
    if (existing) {
      existing.replaceWith(fresh);
    } else {
      const empty = wrap.querySelector('.empty-mv');
      if (empty) empty.remove();
      wrap.appendChild(fresh);
      bumpCount();
    }
    rcInitAll(fresh);   // إعادة حساب إجمالي الإيصال بعد إعادة رسم الحركة
    fresh.classList.add('mv-flash');
    setTimeout(() => fresh.classList.remove('mv-flash'), 1000);
  }

  function bumpCount() {
    const wrap = document.getElementById('movementsWrap');
    const c = document.getElementById('mvCount');
    if (wrap && c) c.textContent = wrap.querySelectorAll('.movement').length;
  }
  function ensureEmpty() {
    const wrap = document.getElementById('movementsWrap');
    if (wrap && !wrap.querySelector('.movement') && !wrap.querySelector('.empty-mv')) {
      /* ⚠️ لازم يفضل **مطابقًا** للنسخة اللي بيرندرها الخادم في
         `permits/_body_manage.php` — الخادم بيرسم أيقونة SVG وده كان بيرسم
         إيموجي (🔀/➕)، فحالة الفراغ كانت بتبان بشكلين: واحد بعد حذف آخر
         حركة وواحد بعد إعادة تحميل الصفحة.
         الـSVG مكتوب هنا حرفيًّا (زي `icoAlert` في محرّك النوافذ) لأن خريطة
         `ICONS` عايشة جوّه IIFE لوحة الأوامر ومش متاحة هنا. */
      const icoShuffle = '<svg class="ico" width="14" height="14" viewBox="0 0 24 24" fill="none" '
        + 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
        + 'aria-hidden="true" focusable="false"><path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 '
        + '2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/>'
        + '<path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/></svg>';
      wrap.innerHTML = '<div class="card empty-mv"><div class="empty empty-mv-in">'
        + '<div class="ic">' + icoShuffle + '</div>لا حركات بعد — اضغط «حركة جديدة» للبدء.</div></div>';
    }
  }

  // طيّ/فتح أقسام الإدخال + تمرير الستيبر
  document.addEventListener('click', (e) => {
    const moreBtn = e.target.closest && e.target.closest('.ms-more-btn');
    if (moreBtn) { const m = moreBtn.closest('.ms-form').querySelector('.ms-more'); if (m) m.hidden = !m.hidden; return; }
    // نقل إيصال إلى إذن/حركة أخرى مع الإبقاء على سجل الإيصال نفسه.
    const transferBtn = e.target.closest && e.target.closest('.receipt-transfer-btn');
    if (transferBtn) {
      const wrap = document.getElementById('receiptTransferFormWrap');
      const form = document.getElementById('receiptTransferForm');
      const summary = document.getElementById('receiptTransferSummary');
      const submit = document.getElementById('receiptTransferSubmit');
      if (!wrap || !form || !transferBtn.dataset.transferAction) return;
      form.reset();
      form.setAttribute('action', transferBtn.dataset.transferAction);
      if (summary) {
        const parts = [transferBtn.dataset.receiptLabel, transferBtn.dataset.receiptDate, transferBtn.dataset.receiptAmount].filter(Boolean);
        summary.textContent = parts.join(' · ');
      }
      if (submit) submit.disabled = false;
      formModal.open(wrap, 'نقل الإيصال إلى إذن آخر');
      return;
    }
    // تعديل بند (إيصال/مخصّص) → نافذة منبثقة
    const editBtn = e.target.closest && e.target.closest('.ms-edit-btn');
    if (editBtn) {
      const scope = editBtn.closest('.ms-entry-wrap') || editBtn.closest('.mstage');
      const w = scope && scope.querySelector('.ms-edit-wrap');
      const nm = (editBtn.closest('.mstage') || {}).querySelector ? editBtn.closest('.mstage').querySelector('.ms-name') : null;
      if (w) formModal.open(w, 'تعديل ' + (nm ? nm.textContent.trim() : ''));
      return;
    }
    // تعديل بوليصة → نافذة منبثقة (بدل الانتقال لصفحة البوليصة)
    const billEditBtn = e.target.closest && e.target.closest('.ms-bill-edit-btn');
    if (billEditBtn) {
      const w = document.getElementById(billEditBtn.getAttribute('data-billedit') || '');
      if (w) formModal.open(w, 'تعديل البوليصة');
      return;
    }
    // إضافة (إيصال/بوليصة/بند) → نافذة منبثقة؛ المولدات (عرض فقط) تبقى طيًّا داخليًّا
    const addBtn = e.target.closest && e.target.closest('.ms-add-btn');
    if (addBtn) {
      const st = addBtn.closest('.mstage');
      const nm = st.querySelector('.ms-name');
      const name = nm ? nm.textContent.trim() : '';
      const billAdd = st.querySelector('.bill-add');
      const addForm = st.querySelector('.ms-add-form');
      if (billAdd)      { formModal.open(billAdd, 'إضافة بوليصة'); return; }
      if (addForm)      { formModal.open(addForm, 'إضافة ' + name); return; }
      const body = st.querySelector('.ms-body');   // مولدات: عرض فقط
      if (body) body.hidden = !body.hidden;
      return;
    }
    /* ⚠️ **مفتاح عام لفتح أي نموذج في نافذة** (2026-08-09) — بلاغ المالك:
       «إضافة العهدة بتاخد وقت كبير: أدخل الصفحة وأعمل سكرول لآخر حاجة».
       النموذج كان في آخر التبويب فكل تنزيل = تحميل + سكرول + رجوع.
       الاستعمال: `<button data-open-form="#id" data-open-form-title="…">`
       والنموذج نفسه `hidden` في مكانه — `formModal` بينقله للنافذة وبيرجّعه
       لو اتقفلت. **مفتاح عام مش خاص بالشاشة دي** عشان أي فورم في النظام
       يقدر يبقى نافذة بسطر واحد بدل نسخة سلوك جديدة كل مرة. */
    const openFormBtn = e.target.closest && e.target.closest('[data-open-form]');
    if (openFormBtn) {
      const node = document.querySelector(openFormBtn.getAttribute('data-open-form') || '');
      if (node) {
        e.preventDefault();
        formModal.open(node, openFormBtn.getAttribute('data-open-form-title') || '');
        return;
      }
    }
    const step = e.target.closest && e.target.closest('.step-chip[data-scroll]');
    if (step) {
      const target = document.querySelector(step.getAttribute('href') || '');
      if (target) { e.preventDefault(); target.scrollIntoView({ block: 'center', behavior: 'smooth' }); target.classList.add('mv-flash'); setTimeout(() => target.classList.remove('mv-flash'), 1000); }
    }
  });

  // حساب إجمالي الإيصال حيًّا:  الإجمالي = سعر الوحدة × الكمية
  function rcTotal(form) {
    if (!form) return;
    const q = parseFloat((form.querySelector('.js-rc-qty') || {}).value) || 0;
    const u = parseFloat((form.querySelector('.js-rc-unit') || {}).value) || 0;
    const t = form.querySelector('.js-rc-total');
    if (t) t.textContent = (q * u).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function rcInitAll(root) {
    (root || document).querySelectorAll('.ms-form .js-rc-unit').forEach((u) => rcTotal(u.closest('.ms-form')));
  }
  document.addEventListener('input', (e) => {
    const el = e.target;
    if (el && el.classList && (el.classList.contains('js-rc-qty') || el.classList.contains('js-rc-unit'))) {
      rcTotal(el.closest('.ms-form'));
    }
  });
  rcInitAll();

  // الربط التلقائي: الوكيل↔الشركة + صاحب السيارة→رقمها
  let MAPS = { nameMap: { agentCompanies: {}, companyAgents: {} }, carMap: {}, companies: [] };
  try { const el = document.getElementById('permitMaps'); if (el) MAPS = JSON.parse(el.textContent); } catch (e) {}

  function splitCarNo(no) {
    const m = String(no || '').match(/^(.*?)[\s-]*(\d+)\s*$/);
    if (m) return { letters: (m[1] || '').trim(), digits: m[2] };
    return { letters: String(no || '').trim(), digits: '' };
  }

  document.addEventListener('change', (e) => {
    const el = e.target;
    if (!el || !el.classList) return;
    if (el.classList.contains('js-car-owner')) {
      const no = MAPS.carMap[el.value.trim()];
      if (no) {
        const row = el.closest('.bill-form') || document;
        const l = row.querySelector('.js-car-letters'), d = row.querySelector('.js-car-digits');
        const parts = splitCarNo(no);
        if (l && !l.value) l.value = parts.letters;
        if (d && !d.value) d.value = parts.digits;
      }
      return;
    }
    if (el.id === 'permit_client') {
      const comps = MAPS.nameMap.agentCompanies[el.value.trim()];
      const dl = document.getElementById('dl_company');
      if (dl) { dl.innerHTML = ''; (comps && comps.length ? comps : MAPS.companies).forEach((n) => { const o = document.createElement('option'); o.value = n; dl.appendChild(o); }); }
      const companyEl = document.getElementById('permit_company');
      if (comps && comps.length === 1 && companyEl && !companyEl.value.trim()) companyEl.value = comps[0];
      return;
    }
    if (el.id === 'permit_company') {
      const ags = MAPS.nameMap.companyAgents[el.value.trim()];
      const clientEl = document.getElementById('permit_client');
      if (ags && ags.length === 1 && clientEl) clientEl.value = ags[0];
    }
  });

  // لوحة البحث السريع (Command Palette) — Ctrl/⌘+K أو «/» من أي صفحة
  (function () {
    const root  = document.getElementById('cmdk');
    const input = document.getElementById('cmdk-input');
    const body  = document.getElementById('cmdk-body');
    const allLink = document.getElementById('cmdk-all');
    const openBtn = document.getElementById('cmdk-open');
    if (!root || !input || !body) return;

    const API  = window.__SEARCH_API || '/search/api';
    const PAGE = window.__SEARCH_PAGE || '/search';

    // أيقونات المجموعات (نفس مجموعة الأيقونات في الخادم)
    const ICONS = {
      'truck': '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
      'folder': '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
      'clipboard': '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
      'cpu': '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2M15 20v2M2 15h2M2 9h2M20 15h2M20 9h2M9 2v2M9 20v2"/>',
      'layers': '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
      'receipt': '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
      'file-text': '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8M16 17H8M10 9H8"/>',
      'credit-card': '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
      'shuffle': '<path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/>',
      'trending-down': '<polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/>',
      'user': '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
      'building': '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>',
      'search': '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
      'command': '<path d="M15 6v12a3 3 0 1 0 3-3H6a3 3 0 1 0 3 3V6a3 3 0 1 0-3 3h12a3 3 0 1 0-3-3"/>',
      'dashboard': '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
      'sparkles': '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/>',
      'wallet': '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/><path d="M18 12a1 1 0 0 0 0 2h3v-2Z"/>',
      'briefcase': '<rect width="20" height="14" x="2" y="7" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
      'users': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
      'bar-chart': '<line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/>',
      'settings': '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
      'calculator': '<rect width="16" height="20" x="4" y="2" rx="2"/><line x1="8" x2="16" y1="6" y2="6"/><line x1="8" x2="8" y1="14" y2="14"/><line x1="12" x2="12" y1="14" y2="14"/><line x1="16" x2="16" y1="14" y2="14"/><line x1="8" x2="8" y1="18" y2="18"/><line x1="12" x2="12" y1="18" y2="18"/><line x1="16" x2="16" y1="18" y2="18"/>',
      'banknote': '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
      'inbox': '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>'
    };
    const svg = (name) => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || ICONS.search) + '</svg>';
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    let items = [];    // روابط النتائج المسطّحة للتنقّل بالكيبورد
    let active = -1;
    let timer = null;
    let seq = 0;

    // ---- أوامر التنقّل السريع (نفس تطبيع الخادم: ألف/همزات/ة/ى + أرقام عربية) ----
    function normJs(s) {
      s = String(s == null ? '' : s);
      s = s.replace(/[ـً-ْٰ]/g, '');                 // تطويل/تشكيل/ألف خنجرية
      s = s.replace(/[أإآٱ]/g, 'ا')                  // توحيد الألف
           .replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
           .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))            // أرقام عربية
           .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));          // أرقام فارسية
      return s.toLowerCase().replace(/\s+/g, ' ').trim();
    }
    const NAV_CMDS = Array.isArray(window.__CMD_NAV) ? window.__CMD_NAV : [];
    const cmdIndex = NAV_CMDS.map((c) => ({
      c: c, lab: normJs(c.label || ''), hay: normJs((c.label || '') + ' ' + (c.kw || ''))
    }));

    // يطابق أوامر التنقّل بالاستعلام: كل الكلمات لازم تظهر، والترتيب بالأهمية
    function matchCommands(q) {
      const nq = normJs(q);
      if (!nq) return [];
      const toks = nq.split(' ').filter(Boolean);
      const out = [];
      cmdIndex.forEach((x) => {
        if (!toks.every((t) => x.hay.indexOf(t) !== -1)) return;
        let score;
        if (x.lab === nq) score = 1000;
        else if (x.lab.indexOf(nq) === 0) score = 600;
        else if (x.lab.indexOf(nq) !== -1) score = 400;
        else score = 200;                                    // طابق عبر الكلمات المرادفة فقط
        if (x.c.kind === 'create') score -= 15;              // التنقّل أعلى قليلًا من الإنشاء عند التعادل
        out.push({ c: x.c, score: score });
      });
      out.sort((a, b) => b.score - a.score);
      return out.slice(0, 6).map((o) => o.c);
    }
    function cmdGroupHtml(cmds) {
      if (!cmds.length) return '';
      let h = '<div class="cmdk-group cmdk-group-cmd"><div class="cmdk-group-head">' + svg('command') +
              '<span>أوامر وتنقّل</span><span class="cmdk-group-count">' + cmds.length + '</span></div>';
      cmds.forEach((c) => {
        h += '<a class="cmdk-item cmdk-cmd" href="' + esc(c.url) + '">' +
               '<span class="cmdk-item-ic">' + svg(c.icon) + '</span>' +
               '<span class="cmdk-item-main">' + esc(c.label) + '</span>' +
               '<span class="cmdk-item-tag cmdk-cmd-tag">' + (c.kind === 'create' ? 'إنشاء' : 'انتقال') + '</span>' +
             '</a>';
      });
      return h + '</div>';
    }

    // ---- فلتر التاريخ ----
    const chipsBox = document.getElementById('cmdk-chips');
    const customBox = document.getElementById('cmdk-custom');
    const fromInp = document.getElementById('cmdk-from');
    const toInp = document.getElementById('cmdk-to');
    let range = 'all';       // المفتاح النشط
    let fFrom = '', fTo = ''; // النطاق المحسوب المُرسَل للخادم

    const pad = (n) => String(n).padStart(2, '0');
    const fmtDate = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());

    function computeRange(key) {
      const now = new Date();
      const today = fmtDate(now);
      if (key === 'today') return [today, today];
      if (key === '7d')  { const d = new Date(now); d.setDate(d.getDate() - 6);  return [fmtDate(d), today]; }
      if (key === '30d') { const d = new Date(now); d.setDate(d.getDate() - 29); return [fmtDate(d), today]; }
      if (key === 'month') return [fmtDate(new Date(now.getFullYear(), now.getMonth(), 1)), today];
      if (key === 'year')  return [fmtDate(new Date(now.getFullYear(), 0, 1)), today];
      return ['', '']; // all
    }

    function applyRange(key) {
      range = key;
      if (chipsBox) chipsBox.querySelectorAll('.cmdk-chip').forEach((b) => b.classList.toggle('active', b.dataset.range === key));
      if (customBox) customBox.hidden = (key !== 'custom');
      if (key === 'custom') {
        fFrom = fromInp && fromInp.value ? fromInp.value : '';
        fTo   = toInp && toInp.value ? toInp.value : '';
        if (fromInp) setTimeout(() => fromInp.focus(), 10);
      } else {
        [fFrom, fTo] = computeRange(key);
        if (fromInp) fromInp.value = fFrom;
        if (toInp)   toInp.value = fTo;
      }
    }

    function qs() {
      let s = '?q=' + encodeURIComponent(input.value.trim());
      if (fFrom) s += '&from=' + encodeURIComponent(fFrom);
      if (fTo)   s += '&to=' + encodeURIComponent(fTo);
      if (selTypes.length) s += '&type=' + encodeURIComponent(selTypes.join(','));
      return s;
    }

    if (chipsBox) {
      chipsBox.addEventListener('click', (e) => {
        const b = e.target.closest('.cmdk-chip');
        if (!b) return;
        applyRange(b.dataset.range);
        if (input.value.trim().length >= 2) run();
        else if (range !== 'custom') input.focus();
      });
    }
    [fromInp, toInp].forEach((el) => { if (el) el.addEventListener('change', () => { applyRange('custom'); if (input.value.trim().length >= 2) run(); }); });

    // ---- فلتر التصنيف (متعدّد الاختيار) ----
    const catsBox = document.getElementById('cmdk-cats');
    let selTypes = []; // مفاتيح التصنيفات المختارة (فارغ = الكل)

    const catsLabel = document.getElementById('cmdk-cats-label');
    const typeDrop  = document.getElementById('cmdk-typedrop');
    function refreshCatChips() {
      if (!catsBox) return;
      catsBox.querySelectorAll('.cmdk-chip').forEach((b) => {
        const t = b.dataset.type;
        b.classList.toggle('active', t === 'all' ? selTypes.length === 0 : selTypes.indexOf(t) !== -1);
      });
      // تحديث زر النوع المنسدل بعدد/اسم المختار
      if (catsLabel) {
        if (selTypes.length === 0) { catsLabel.textContent = 'الكل'; }
        else if (selTypes.length === 1) {
          const chip = catsBox.querySelector('.cmdk-chip[data-type="' + selTypes[0] + '"]');
          catsLabel.textContent = chip ? chip.textContent.trim() : '١ نوع';
        } else { catsLabel.textContent = selTypes.length + ' أنواع'; }
      }
      const btn = typeDrop && typeDrop.querySelector('.cmdk-type-btn');
      if (btn) btn.classList.toggle('on', selTypes.length > 0);
    }
    function applyCats(list) { selTypes = Array.isArray(list) ? list.slice() : []; refreshCatChips(); }

    if (catsBox) {
      catsBox.addEventListener('click', (e) => {
        const b = e.target.closest('.cmdk-chip');
        if (!b) return;
        const t = b.dataset.type;
        if (t === 'all') { selTypes = []; }
        else {
          const i = selTypes.indexOf(t);
          if (i === -1) selTypes.push(t); else selTypes.splice(i, 1);
        }
        refreshCatChips();
        if (input.value.trim().length >= 2) run();
      });
    }
    // إغلاق قائمة النوع المنسدلة عند النقر بالخارج
    if (typeDrop) {
      document.addEventListener('click', (e) => {
        if (typeDrop.open && !typeDrop.contains(e.target)) typeDrop.open = false;
      });
    }

    // ---- عمليات البحث الأخيرة (localStorage) ----
    const RECENT_KEY = 'almotawakel_recent';
    const RANGE_LABELS = { today: 'اليوم', '7d': 'آخر ٧ أيام', '30d': 'آخر ٣٠ يوم', month: 'هذا الشهر', year: 'هذا العام', custom: 'تاريخ مخصّص' };

    function recentGet() {
      try { const a = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); return Array.isArray(a) ? a : []; }
      catch (e) { return []; }
    }
    function recentSave() {
      const q = input.value.trim();
      if (q.length < 2) return;
      const entry = { q: q, range: range, from: fFrom, to: fTo, types: selTypes.slice() };
      let list = recentGet().filter((r) => (r.q || '').toLowerCase() !== q.toLowerCase());
      list.unshift(entry);
      list = list.slice(0, 8);
      try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch (e) {}
    }
    function recentClear() { try { localStorage.removeItem(RECENT_KEY); } catch (e) {} renderEmpty(); }

    function entryTags(r) {
      const t = [];
      if (r.range && r.range !== 'all') t.push(RANGE_LABELS[r.range] || (((r.from || '…') + '→' + (r.to || '…'))));
      if (r.types && r.types.length) t.push(r.types.length + ' تصنيف');
      return t;
    }
    function renderEmpty() {
      const cmds = matchCommands(input.value.trim());
      const list = recentGet();
      if (!cmds.length && !list.length) {
        setHint('اكتب اسم صفحة للتنقّل، أو حرفين للبحث في كل النظام…'); items = []; active = -1; return;
      }
      let html = cmdGroupHtml(cmds);
      if (list.length) {
        html += '<div class="cmdk-recent"><div class="cmdk-recent-head"><span>عمليات بحث أخيرة</span>' +
                   '<button type="button" class="cmdk-recent-clear" id="cmdk-recent-clear">مسح</button></div>';
        list.forEach((r) => {
          const tags = entryTags(r).map((x) => '<span class="cmdk-recent-tag">' + esc(x) + '</span>').join('');
          html += '<button type="button" class="cmdk-recent-item" data-recent=\'' + esc(JSON.stringify(r)) + '\'>' +
                    '<span class="cmdk-recent-ic">' + svg('search') + '</span>' +
                    '<span class="cmdk-recent-q">' + esc(r.q) + '</span>' + tags + '</button>';
        });
        html += '</div>';
      }
      body.innerHTML = html;
      items = Array.prototype.slice.call(body.querySelectorAll('.cmdk-item'));
      active = -1;
      if (items.length) setActive(0);
    }
    function restoreRecent(r) {
      input.value = r.q || '';
      if (r.range === 'custom') {
        if (fromInp) fromInp.value = r.from || '';
        if (toInp) toInp.value = r.to || '';
        applyRange('custom');
      } else {
        applyRange(r.range || 'all');
      }
      applyCats(r.types || []);
      run();
      input.focus();
    }

    function isOpen() { return !root.hidden; }

    function open() {
      if (isOpen()) { input.focus(); input.select(); return; }
      root.hidden = false;
      root.setAttribute('aria-hidden', 'false');
      document.body.classList.add('cmdk-lock');
      activateDialog(root, input);
      input.value = '';
      applyRange('all');
      applyCats([]);
      renderEmpty();
      allLink.href = PAGE;
      setTimeout(() => input.focus(), 20);
    }
    function close() {
      root.hidden = true;
      root.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('cmdk-lock');
      deactivateDialog(root);
      items = []; active = -1;
    }

    function setHint(txt) {
      body.innerHTML = '<div class="cmdk-hint">' + esc(txt) + '</div>';
    }

    function setActive(i) {
      if (!items.length) return;
      if (i < 0) i = items.length - 1;
      if (i >= items.length) i = 0;
      if (active >= 0 && items[active]) items[active].classList.remove('active');
      active = i;
      items[active].classList.add('active');
      items[active].scrollIntoView({ block: 'nearest' });
    }

    function render(data) {
      const q = input.value.trim();
      allLink.href = PAGE + qs();
      const cmdHtml = cmdGroupHtml(matchCommands(q));
      if (!data.groups || !data.groups.length) {
        if (cmdHtml) {
          body.innerHTML = cmdHtml + '<div class="cmdk-hint cmdk-hint-sm">لا نتائج بيانات مطابقة لـ «' + esc(q) + '».</div>';
          items = Array.prototype.slice.call(body.querySelectorAll('.cmdk-item'));
          active = -1; if (items.length) setActive(0);
          return;
        }
        setHint('لا توجد نتائج مطابقة لـ «' + q + '».');
        items = []; active = -1;
        return;
      }
      let html = cmdHtml;
      // تلميح التسامح الإملائي: النتائج أقرب تهجئةً وليست مطابقة دقيقة
      if (data.fuzzy) {
        html += '<div class="cmdk-fuzzy-note">' + svg('search') +
                '<span>مفيش مطابقة تامة لـ «' + esc(q) + '» — دي أقرب النتائج تهجئةً</span></div>';
      }
      data.groups.forEach((g) => {
        html += '<div class="cmdk-group' + (g.fuzzy ? ' cmdk-group-fuzzy' : '') + '"><div class="cmdk-group-head">' + svg(g.icon) +
                '<span>' + esc(g.title) + '</span>' +
                (g.fuzzy ? '<span class="cmdk-fuzzy-badge">تهجئة قريبة</span>' : '') +
                '<span class="cmdk-group-count">' + g.rows.length + '</span></div>';
        g.rows.forEach((r) => {
          html += '<a class="cmdk-item" href="' + esc(r.url) + '">' +
                    '<span class="cmdk-item-main">' + esc(r.label) +
                      (r.sub ? '<span class="cmdk-item-sub">' + esc(r.sub) + '</span>' : '') +
                    '</span>' +
                    (r.field ? '<span class="cmdk-item-tag">' + esc(r.field) + '</span>' : '') +
                  '</a>';
        });
        html += '</div>';
      });
      html += '<div class="cmdk-more"><a href="' + esc(allLink.href) + '">عرض كل النتائج (' + data.total + ') ←</a></div>';
      body.innerHTML = html;
      items = Array.prototype.slice.call(body.querySelectorAll('.cmdk-item'));
      active = -1;
      if (items.length) setActive(0);
    }

    function run() {
      const q = input.value.trim();
      allLink.href = PAGE + qs();
      if (q.length < 2) { renderEmpty(); return; }
      // أظهر أوامر التنقّل فورًا أثناء تحميل نتائج البيانات
      const cmdHtml = cmdGroupHtml(matchCommands(q));
      body.innerHTML = cmdHtml + '<div class="cmdk-hint cmdk-hint-sm">جارٍ البحث…</div>';
      items = Array.prototype.slice.call(body.querySelectorAll('.cmdk-item'));
      active = -1; if (items.length) setActive(0);
      const mine = ++seq;
      fetch(API + qs(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((res) => res.json())
        .then((data) => { if (mine === seq) render(data); })
        .catch(() => { if (mine === seq) setHint('تعذّر البحث. حاول مرة أخرى.'); });
    }

    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 180); });

    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        recentSave();
        if (active >= 0 && items[active]) { window.location.href = items[active].getAttribute('href'); }
        else { window.location.href = PAGE + qs(); }
      } else if (e.key === 'Escape') { e.preventDefault(); close(); }
    });

    // فتح/غلق
    if (openBtn) openBtn.addEventListener('click', open);
    root.addEventListener('click', (e) => {
      if (e.target.closest('[data-cmdk-close]')) { close(); return; }
      const rec = e.target.closest('.cmdk-recent-item');
      if (rec) { e.preventDefault(); try { restoreRecent(JSON.parse(rec.getAttribute('data-recent'))); } catch (x) {} return; }
      if (e.target.closest('#cmdk-recent-clear')) { e.preventDefault(); recentClear(); input.focus(); return; }
      // نتيجة تُفتح ⇒ احفظ البحث في السجل قبل الانتقال
      if (e.target.closest('.cmdk-item') || e.target.closest('.cmdk-more a') || e.target.closest('#cmdk-all')) { recentSave(); }
    });

    document.addEventListener('keydown', (e) => {
      const t = e.target;
      const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
      if ((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) { e.preventDefault(); isOpen() ? close() : open(); return; }
      if (e.key === '/' && !typing && !e.metaKey && !e.ctrlKey && !e.altKey && !isOpen()) { e.preventDefault(); open(); }
    });
  })();

  // ================= أرقام الحاويات: خانة لكل حاوية =================
  // «عدد الحاويات» = 2 ⇒ خانتين لرقم الحاوية، وهكذا. الرسم الابتدائي من الخادم
  // (المكوّن `partials/container_nos.php`) وده بيزوّد/يقلّل حيًّا وقت الكتابة.
  // ⚠️ الاستماع بالتفويض على `document` عن قصد: فورم البوليصة جوّه الإذن بيتحقن
  //    بالـAJAX، فأي ربط مباشر وقت التحميل مش هيلاقيه.
  (function () {
    const MAX = 30;   // سقف دفاعي ضد رقم مكتوب بالغلط (999 حاوية)

    function rowHtml(name, index, multi, label) {
      const row = document.createElement('div');
      row.className = 'cno-row';
      const badge = document.createElement('span');
      badge.className = 'cno-idx';
      badge.setAttribute('aria-hidden', 'true');
      badge.textContent = String(index + 1);
      const input = document.createElement('input');
      input.name = name + '[]';
      input.autocomplete = 'off';
      input.setAttribute('aria-label', label + ' ' + (index + 1));
      input.placeholder = multi ? label + ' ' + (index + 1) : label;
      row.appendChild(badge);
      row.appendChild(input);
      return row;
    }

    function sync(scope, want) {
      const box = scope && scope.querySelector('[data-container-nos]');
      if (!box) return;
      const list = box.querySelector('.cno-list');
      if (!list) return;
      const name  = box.dataset.cnoName || 'container_no';
      const label = (box.querySelector('label') ? box.querySelector('label').childNodes[0].textContent : '').trim() || 'رقم الحاوية';
      const target = Math.max(1, Math.min(MAX, want || 1));

      // التقليل بيشيل الخانات **الفاضية** من الآخر بس — رقم متكتب عمره ما يضيع
      let rows = Array.from(list.querySelectorAll('.cno-row'));
      while (rows.length > target) {
        const last = rows[rows.length - 1];
        const input = last.querySelector('input');
        if (input && input.value.trim()) break;
        last.remove();
        rows.pop();
      }
      while (rows.length < target) {
        const row = rowHtml(name, rows.length, true, label);
        list.appendChild(row);
        rows.push(row);
      }

      const multi = rows.length > 1;
      list.classList.toggle('multi', multi);
      box.classList.toggle('multi', multi);
      rows.forEach((row, i) => {
        const badge = row.querySelector('.cno-idx');
        const input = row.querySelector('input');
        if (badge) badge.textContent = String(i + 1);
        if (input) {
          input.placeholder = multi ? label + ' ' + (i + 1) : label;
          input.setAttribute('aria-label', label + ' ' + (i + 1));
        }
      });
      const counter = box.querySelector('label .cno-n');
      if (multi && !counter) {
        const span = document.createElement('span');
        span.className = 'cno-n';
        span.textContent = String(rows.length);
        box.querySelector('label').appendChild(document.createTextNode(' '));
        box.querySelector('label').appendChild(span);
      } else if (counter) {
        if (multi) counter.textContent = String(rows.length); else counter.remove();
      }
    }

    function onCount(e) {
      const el = e.target;
      if (!el || el.name !== 'container_count') return;
      const scope = (el.closest && (el.closest('form') || el.closest('.bill-form'))) || document;
      sync(scope, parseInt(el.value, 10) || 0);
    }
    document.addEventListener('input', onCount);
    document.addEventListener('change', onCount);
  })();

  // ================= أدوات كل الجداول: ترتيب + إخفاء/إظهار أعمدة =================
  // تعمل تلقائيًا على الجداول الحالية وعلى أي جدول يُضاف لاحقًا عبر AJAX/Popup.
  (function () {
    const selector = 'table.data, table.pv-tbl';
    let autoId = 0;

    function arDigits(value) {
      return String(value == null ? '' : value)
        .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
        .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)));
    }

    function cellValue(cell) {
      if (!cell) return '';
      if (cell.dataset.sortValue != null) return cell.dataset.sortValue.trim();
      const input = cell.querySelector('input:not([type=checkbox]):not([type=radio]),select,textarea');
      if (input) {
        if (input.tagName === 'SELECT') {
          const opt = input.options[input.selectedIndex];
          return opt ? opt.textContent.trim() : input.value.trim();
        }
        return String(input.value || '').trim();
      }
      /* ⚠️ زر «المزيد» بتاع كروت الموبايل (المرحلة ٨) بيتحقن **جوّه أول خانة**،
         و`textContent` بتاعها بيشمله — يعني كان هيتسرّب لـ**تصدير CSV**
         ولـ**ترتيب العمود الأول** ولكشف نوع العمود، فيبوّظ التلاتة بالسكوت.
         بنشيله من نسخة مؤقتة عشان ما نلمسش الـDOM الأصلي. */
      if (cell.querySelector('.tm-more')) {
        const clone = cell.cloneNode(true);
        clone.querySelectorAll('.tm-more').forEach((n) => n.remove());
        return clone.textContent.replace(/\s+/g, ' ').trim();
      }
      return cell.textContent.replace(/\s+/g, ' ').trim();
    }

    function numberValue(value) {
      let s = arDigits(value).trim();
      if (!s || s === '—' || s === '-') return null;
      let negative = false;
      if (/^\(.*\)$/.test(s)) { negative = true; s = s.slice(1, -1); }
      s = s.replace(/(?:ج\s*\.?\s*م\.?|EGP|%)/gi, '').trim();
      s = s.replace(/[٬,]/g, '').replace(/٫/g, '.').replace(/\s+/g, '');
      if (!/^[+-]?\d+(?:\.\d+)?$/.test(s)) return null;
      const n = Number(s);
      return Number.isFinite(n) ? (negative ? -n : n) : null;
    }

    function dateValue(value) {
      const s = arDigits(value).trim();
      let m = s.match(/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/);
      let y, mo, d;
      if (m) { y = +m[1]; mo = +m[2]; d = +m[3]; }
      else {
        m = s.match(/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/);
        if (!m) return null;
        d = +m[1]; mo = +m[2]; y = +m[3];
      }
      if (y < 1900 || mo < 1 || mo > 12 || d < 1 || d > 31) return null;
      const stamp = Date.UTC(y, mo - 1, d);
      const check = new Date(stamp);
      return check.getUTCFullYear() === y && check.getUTCMonth() === mo - 1 && check.getUTCDate() === d
        ? stamp : null;
    }

    function originalSpan(cell) {
      if (!cell.dataset.tblOriginalSpan) cell.dataset.tblOriginalSpan = String(cell.colSpan || 1);
      return Math.max(1, parseInt(cell.dataset.tblOriginalSpan, 10) || 1);
    }

    function cellAt(row, column) {
      let cursor = 0;
      for (const cell of Array.from(row.cells)) {
        const span = originalSpan(cell);
        if (column >= cursor && column < cursor + span) return span === 1 ? cell : null;
        cursor += span;
      }
      return null;
    }

    function storageHash(text) {
      let h = 2166136261;
      for (let i = 0; i < text.length; i++) { h ^= text.charCodeAt(i); h = Math.imul(h, 16777619); }
      return (h >>> 0).toString(36);
    }

    function setupClientPager(table, anchor, prefs, savePrefs) {
      if (table.hasAttribute('data-pagination-off')) return;
      const body = table.tBodies[0];
      const dataRows = () => Array.from(body.rows).filter((row) => !row.querySelector('.empty') && !row.hasAttribute('data-no-page'));
      const mobile = window.matchMedia('(max-width:640px)').matches;
      if (dataRows().length <= (mobile ? 10 : 40)) return;
      const host = table.closest('.card') || anchor.parentElement;
      if (host && host.querySelector('.pager:not(.client-pager)')) return; // الصفحة مرقّمة من الخادم بالفعل

      const pager = document.createElement('nav');
      pager.className = 'client-pager';
      pager.setAttribute('aria-label', 'التنقل بين صفحات الجدول');
      pager.innerHTML = '<button type="button" class="btn ghost sm cp-prev">السابق</button>' +
        '<span class="cp-status" aria-live="polite"></span>' +
        '<button type="button" class="btn ghost sm cp-next">التالي</button>' +
        '<label class="cp-size">صفوف الصفحة <select><option>10</option><option>25</option><option>50</option><option>100</option></select></label>';
      anchor.parentNode.insertBefore(pager, anchor.nextSibling);
      let page = 1, query = '';
      let size = [10, 25, 50, 100].includes(+prefs.pageSize) ? +prefs.pageSize : (mobile ? 10 : 25);
      const select = pager.querySelector('select'); select.value = String(size);
      const status = pager.querySelector('.cp-status');
      const prev = pager.querySelector('.cp-prev'), next = pager.querySelector('.cp-next');

      function render() {
        const rows = dataRows();
        const matched = rows.filter((row) => !query || row.textContent.toLocaleLowerCase('ar').includes(query));
        const pages = Math.max(1, Math.ceil(matched.length / size)); page = Math.min(page, pages);
        const start = (page - 1) * size, visible = new Set(matched.slice(start, start + size));
        rows.forEach((row) => { row.hidden = !visible.has(row); });
        status.textContent = matched.length ? `صفحة ${page} من ${pages} — ${matched.length} سجل` : 'لا نتائج مطابقة';
        prev.disabled = page <= 1; next.disabled = page >= pages;
        pager.hidden = rows.length <= size && !query;
      }
      prev.addEventListener('click', () => { if (page > 1) { page--; render(); table.scrollIntoView({block:'start',behavior:'smooth'}); } });
      next.addEventListener('click', () => { page++; render(); table.scrollIntoView({block:'start',behavior:'smooth'}); });
      select.addEventListener('change', () => { size = +select.value; page = 1; prefs.pageSize = size; savePrefs(); render(); });
      table.addEventListener('table:changed', () => { page = 1; render(); });
      const search = document.querySelector('.table-search[data-target="' + CSS.escape(table.id) + '"]');
      if (search) search.addEventListener('input', () => { query = search.value.trim().toLocaleLowerCase('ar'); page = 1; render(); });
      table.__renderPage = render;
      render();
    }

    function enhance(table) {
      if (!table || table.dataset.tableEnhanced === '1' || !table.tHead || !table.tBodies.length) return;
      if (table.closest('template,[hidden]') || table.hasAttribute('data-table-tools-off')) return;

      const headerRow = table.tHead.rows[table.tHead.rows.length - 1];
      if (!headerRow || !headerRow.cells.length) return;
      table.dataset.tableEnhanced = '1';
      if (!table.id) table.id = 'auto-data-table-' + (++autoId);

      const columns = [];
      let cursor = 0;
      Array.from(headerRow.cells).forEach((th) => {
        th.setAttribute('scope', 'col');
        const span = originalSpan(th);
        const index = cursor;
        cursor += span;
        if (span !== 1) return;
        const rawLabel = th.textContent.replace(/\s+/g, ' ').trim();
        const sampleRow = table.tBodies[0] && table.tBodies[0].rows[0];
        const sampleCell = sampleRow ? cellAt(sampleRow, index) : null;
        const looksLikeActions = !!(sampleCell && sampleCell.querySelector('.actions,form,button,a'));
        const label = rawLabel || (looksLikeActions ? 'إجراءات' : ('عمود ' + (index + 1)));
        const interactive = !!th.querySelector('input,select,textarea,a,button');
        const action = /^(إجراءات|تعديل|حذف|اختيار)$/u.test(label);
        const sortable = !interactive && !action && !th.hasAttribute('data-no-sort');
        // عمود الإجراءات لا يُرتَّب، لكنه قابل للإخفاء مثل باقي الأعمدة؛
        // عمود التحديد الذي يحتوي checkbox يظل ظاهرًا حتى لا تتعطل إجراءات المجموعات.
        const hideable = !th.querySelector('input[type=checkbox],input[type=radio]') && !th.hasAttribute('data-no-hide');
        columns.push({ index, th, label, sortable, hideable });

        if (!sortable) return;
        th.classList.add('tbl-sortable');
        th.setAttribute('aria-sort', 'none');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tbl-sort-btn';
        btn.title = 'ترتيب الصفوف المعروضة حسب ' + label;
        btn.setAttribute('aria-label', 'ترتيب حسب ' + label);
        btn.innerHTML = '<span aria-hidden="true">⇅</span>';
        btn.addEventListener('click', (e) => {
          e.preventDefault(); e.stopPropagation();
          const direction = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
          sortTable(table, columns, index, direction);
        });
        th.appendChild(btn);
      });

      markNumericColumns(table, columns);

      const hideable = columns.filter((c) => c.hideable);
      if (!hideable.length && !columns.some((c) => c.sortable)) return;

      const signature = columns.map((c) => c.label).join('|');
      table.dataset.tableSignature = signature;
      const siblings = Array.from(document.querySelectorAll(selector)).filter((t) => {
        if (t.dataset.tableSignature) return t.dataset.tableSignature === signature;
        if (!t.tHead) return false;
        const row = t.tHead.rows[t.tHead.rows.length - 1];
        return row && Array.from(row.cells).map((c) => {
          const copy = c.cloneNode(true);
          copy.querySelectorAll('.tbl-sort-btn').forEach((b) => b.remove());
          return copy.textContent.replace(/\s+/g, ' ').trim();
        }).join('|') === signature;
      });
      const ordinal = Math.max(0, siblings.indexOf(table));
      const storeKey = 'almotawakel-table-columns-v1:' + location.pathname + ':' + storageHash(signature) + ':' + ordinal;
      const prefKey = 'almotawakel-table-prefs-v1:' + location.pathname + ':' + storageHash(signature) + ':' + ordinal;
      let prefs = {}; try { prefs = JSON.parse(localStorage.getItem(prefKey) || '{}'); } catch (e) { prefs = {}; }
      if (prefs.density === 'compact') table.classList.add('table-compact');
      if (prefs.sticky) table.classList.add('table-sticky-first');
      const savedWidths = prefs.widths || {};
      columns.forEach((col) => { if (savedWidths[col.index]) col.th.style.width = savedWidths[col.index] + 'px'; });
      let hidden = [];
      try {
        const saved = JSON.parse(localStorage.getItem(storeKey) || '[]');
        if (Array.isArray(saved)) hidden = saved.map(Number).filter(Number.isInteger);
      } catch (e) { hidden = []; }

      const tools = document.createElement('div');
      tools.className = 'table-tools';
      tools.setAttribute('data-for-table', table.id);
      const hint = document.createElement('span');
      hint.className = 'table-sort-hint';
      hint.textContent = '⇅ اضغط بجوار عنوان العمود للترتيب';
      tools.appendChild(hint);

      function savePrefs() { try { localStorage.setItem(prefKey, JSON.stringify(prefs)); } catch (e) {} }
      const densityBtn = document.createElement('button'); densityBtn.type='button'; densityBtn.className='btn ghost sm table-density-btn';
      const densityLabel=()=>{densityBtn.textContent=table.classList.contains('table-compact')?'↕ عرض مريح':'↕ عرض مضغوط';}; densityLabel();
      densityBtn.addEventListener('click',()=>{table.classList.toggle('table-compact');prefs.density=table.classList.contains('table-compact')?'compact':'normal';densityLabel();savePrefs();});
      tools.appendChild(densityBtn);

      const stickyBtn=document.createElement('button');stickyBtn.type='button';stickyBtn.className='btn ghost sm table-sticky-btn';
      const stickyLabel=()=>{stickyBtn.textContent=table.classList.contains('table-sticky-first')?'📌 إلغاء التثبيت':'📌 تثبيت أول عمود';};stickyLabel();
      stickyBtn.addEventListener('click',()=>{table.classList.toggle('table-sticky-first');prefs.sticky=table.classList.contains('table-sticky-first');stickyLabel();savePrefs();});
      tools.appendChild(stickyBtn);

      const exportBtn=document.createElement('button');exportBtn.type='button';exportBtn.className='btn ghost sm table-export-btn';exportBtn.textContent='⬇ CSV الظاهر';
      exportBtn.addEventListener('click',()=>{
        const rows=[];const collect=(row)=>Array.from(row.cells).filter(c=>!c.classList.contains('tbl-col-hidden')).map(c=>'"'+cellValue(c).replace(/"/g,'""')+'"').join(',');
        if(table.tHead)Array.from(table.tHead.rows).forEach(r=>rows.push(collect(r)));
        Array.from(table.tBodies).forEach(b=>Array.from(b.rows).filter(r=>!r.hidden).forEach(r=>rows.push(collect(r))));
        const blob=new Blob(['\ufeff'+rows.join('\r\n')],{type:'text/csv;charset=utf-8'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=(document.title||'table').replace(/[^\p{L}\p{N}_-]+/gu,'-')+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),500);
      });tools.appendChild(exportBtn);

      const selection=document.createElement('span');selection.className='table-selection-count';
      const refreshSelection=()=>{const boxes=Array.from(table.querySelectorAll('tbody input[type=checkbox]'));const n=boxes.filter(x=>x.checked).length;selection.textContent=n?'المحدّد: '+n:'';selection.hidden=!n;};
      table.addEventListener('change',(e)=>{if(e.target.matches('input[type=checkbox]'))refreshSelection();});tools.appendChild(selection);refreshSelection();

      // سحب حافة عنوان العمود لتغيير عرضه، مع حفظه للزيارة التالية.
      columns.forEach((col)=>{
        const handle=document.createElement('span');handle.className='tbl-resize-handle';handle.title='اسحب لتغيير عرض العمود';
        handle.addEventListener('pointerdown',(e)=>{e.preventDefault();e.stopPropagation();const start=e.clientX,startWidth=col.th.getBoundingClientRect().width;handle.setPointerCapture(e.pointerId);
          const move=(ev)=>{const rtl=getComputedStyle(table).direction==='rtl';const delta=(ev.clientX-start)*(rtl?-1:1);col.th.style.width=Math.max(65,startWidth+delta)+'px';};
          const up=(ev)=>{handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',up);prefs.widths=prefs.widths||{};prefs.widths[col.index]=Math.round(col.th.getBoundingClientRect().width);savePrefs();};
          handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',up);
        });col.th.appendChild(handle);
      });

      const chooser = document.createElement('div');
      chooser.className = 'table-columns-chooser';
      const openBtn = document.createElement('button');
      openBtn.type = 'button';
      openBtn.className = 'btn ghost sm table-columns-btn';
      openBtn.setAttribute('aria-expanded', 'false');
      const panel = document.createElement('div');
      panel.className = 'table-columns-panel';
      panel.hidden = true;
      panel.innerHTML = '<div class="table-columns-title">الأعمدة الظاهرة</div>';

      hideable.forEach((col) => {
        const label = document.createElement('label');
        label.className = 'table-column-option';
        const input = document.createElement('input');
        input.type = 'checkbox'; input.checked = !hidden.includes(col.index); input.value = String(col.index);
        input.addEventListener('change', () => {
          hidden = Array.from(panel.querySelectorAll('input[type=checkbox]:not(:checked)')).map((x) => +x.value);
          applyHidden(table, columns, hidden);
          saveAndLabel();
        });
        label.appendChild(input);
        label.appendChild(document.createTextNode(col.label));
        panel.appendChild(label);
      });

      const showAll = document.createElement('button');
      showAll.type = 'button'; showAll.className = 'table-columns-reset'; showAll.textContent = 'إظهار كل الأعمدة';
      showAll.addEventListener('click', () => {
        panel.querySelectorAll('input[type=checkbox]').forEach((x) => { x.checked = true; });
        hidden = []; applyHidden(table, columns, hidden); saveAndLabel();
      });
      panel.appendChild(showAll);
      chooser.appendChild(openBtn); chooser.appendChild(panel); tools.appendChild(chooser);

      function saveAndLabel() {
        try { localStorage.setItem(storeKey, JSON.stringify(hidden)); } catch (e) {}
        const shown = hideable.length - hidden.filter((i) => hideable.some((c) => c.index === i)).length;
        openBtn.textContent = '☷ الأعمدة ' + shown + '/' + hideable.length;
      }
      function closePanel() { panel.hidden = true; openBtn.setAttribute('aria-expanded', 'false'); }
      openBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const opening = panel.hidden;
        document.querySelectorAll('.table-columns-panel:not([hidden])').forEach((p) => { p.hidden = true; });
        document.querySelectorAll('.table-columns-btn[aria-expanded=true]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
        panel.hidden = !opening; openBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');
      });
      panel.addEventListener('click', (e) => e.stopPropagation());
      tools._closeColumns = closePanel;

      const wrap = table.closest('.table-wrap');
      const anchor = wrap || table;
      if (wrap) {
        wrap.classList.add('table-scroll-region');
        wrap.tabIndex = 0;
        const heading = wrap.closest('.card,section')?.querySelector('.section-head,h2,h3');
        wrap.setAttribute('aria-label', 'جدول ' + ((heading && heading.textContent.trim()) || document.title.split('—')[0].trim()));
      }
      Array.from(table.tBodies).forEach((body) => Array.from(body.rows).forEach((row) => {
        columns.forEach((col) => { const cell = cellAt(row, col.index); if (cell) cell.dataset.label = col.label; });
      }));
      /* ══════════════════════════════════════════════════════════════════
         وضع الكروت على الموبايل — المرحلة ٨
         ─────────────────────────────────────────────────────────────────
         قبل كده: الجداول بـ٧ أعمدة أو أكتر (٢٤ جدول، أعرضهم ٢٩ عمود) كانت
         بتاخد **تمرير أفقي** — يعني على الموبايل بتقرا عمودين وتسحب، ومستحيل
         تقارن صفّين. دلوقتي **كل الجداول بقت كروت**، والأعمدة الزيادة بتتطوي
         خلف زر «المزيد» جوّه كل كارت عشان الكارت ما يطولش.

         ⚠️ الزرار بيتحطّ **جوّه أول خانة** مش كخانة جديدة — لأن أدوات الجداول
            (الترتيب/الإخفاء/تغيير العرض) بتشتغل بفهرس الخانة، وخانة زيادة
            كانت هتزحلق الفهارس وتبوّظهم.
         الاستثناء: `data-mobile-scroll="1"` على الجدول بيرجّع التمرير الأفقي.
         ══════════════════════════════════════════════════════════════════ */
      const CARD_LEAD = 5;   // كام خانة تفضل ظاهرة قبل «المزيد»
      if (table.dataset.mobileScroll === '1') {
        table.classList.add('table-mobile-scroll');
      } else {
        table.classList.add('table-mobile-cards');
        if (columns.length > CARD_LEAD + 1) {
          Array.from(table.tBodies).forEach((body) => Array.from(body.rows).forEach((row) => {
            if (row.querySelector('td[colspan]')) { return; }   // صف حالة الفراغ
            let shown = 0, hiddenCount = 0, first = null;
            columns.forEach((col) => {
              const cell = cellAt(row, col.index);
              if (!cell) { return; }
              if (first === null) { first = cell; }
              if (shown++ >= CARD_LEAD) { cell.classList.add('tm-extra'); hiddenCount++; }
            });
            if (!first || hiddenCount === 0) { return; }
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'tm-more';
            more.setAttribute('aria-expanded', 'false');
            more.textContent = 'المزيد (' + hiddenCount + ') ▾';
            more.addEventListener('click', (e) => {
              e.stopPropagation();
              const open = row.classList.toggle('tm-open');
              more.setAttribute('aria-expanded', open ? 'true' : 'false');
              more.textContent = open ? 'أقل ▴' : 'المزيد (' + hiddenCount + ') ▾';
            });
            first.appendChild(more);
          }));
        }
      }
      if (anchor.parentNode) {
        anchor.parentNode.insertBefore(tools, anchor);
        if (anchor.parentNode.classList && anchor.parentNode.classList.contains('card')) {
          anchor.parentNode.classList.add('table-tools-host');
        }
      }
      applyHidden(table, columns, hidden);
      saveAndLabel();
      setupClientPager(table, anchor, prefs, savePrefs);
    }

    /**
     * محاذاة أعمدة الأرقام لليمين بأرقام جدولية.
     *
     * ⚠️ كل خانات `table.data` كانت **متوسّطة**، بما فيها المبالغ — يعني
     * «12,000» و«9,200» و«0.00» تحت بعض بلا أي تراص، فمستحيل تقارنهم بالعين
     * (وده الاستخدام الأساسي لشاشة فلوس). المحاذاة لليمين + `tabular-nums`
     * بتخلّي خانة الآحاد تحت خانة الآحاد بالظبط.
     *
     * الكشف بيستخدم **نفس** `numberValue`/`dateValue` بتوع الترتيب — مفيش
     * تعريف تاني لـ«ده رقم ولا لأ» ينفع يختلف عن ترتيب نفس العمود. والتواريخ
     * **مستثناة عن قصد** (16/07/2026 بتتقرا كوحدة، ومحاذاتها للنص أوضح).
     */
    function markNumericColumns(table, columns) {
      const bodyRows = [];
      Array.from(table.tBodies).forEach((body) => Array.from(body.rows).forEach((r) => bodyRows.push(r)));
      if (bodyRows.length < 2) return;
      columns.forEach((col) => {
        const cells = [];
        let seen = 0;
        for (const row of bodyRows) {
          const cell = cellAt(row, col.index);
          if (!cell || cell.hasAttribute('colspan')) continue;
          cells.push(cell);
          const v = cellValue(cell);
          if (!v || v === '—' || v === '-') continue;
          if (dateValue(v) !== null || numberValue(v) === null) return;  // تاريخ أو نص → سيبه
          seen++;
        }
        if (seen < 2) return;
        col.th.classList.add('tbl-num');
        cells.forEach((c) => c.classList.add('tbl-num'));
      });
    }

    function sortTable(table, columns, column, direction) {
      const values = [];
      Array.from(table.tBodies).forEach((body) => Array.from(body.rows).forEach((row) => {
        const cell = cellAt(row, column);
        if (cell) { const value = cellValue(cell); if (value && value !== '—') values.push(value); }
      }));
      const allDates = values.length && values.every((v) => dateValue(v) !== null);
      const allNumbers = values.length && values.every((v) => numberValue(v) !== null);
      const type = allDates ? 'date' : (allNumbers ? 'number' : 'text');
      const dir = direction === 'descending' ? -1 : 1;
      const compare = (a, b) => {
        let av = cellValue(a.cell), bv = cellValue(b.cell), result;
        if (type === 'date') result = dateValue(av) - dateValue(bv);
        else if (type === 'number') result = numberValue(av) - numberValue(bv);
        else result = av.localeCompare(bv, 'ar', { numeric: true, sensitivity: 'base' });
        return result === 0 ? a.order - b.order : result * dir;
      };

      Array.from(table.tBodies).forEach((body) => {
        const rows = Array.from(body.rows);
        const output = [], run = [];
        const flush = () => { if (run.length) { run.sort(compare); output.push(...run.map((x) => x.row)); run.length = 0; } };
        rows.forEach((row, order) => {
          const cell = cellAt(row, column);
          if (!cell || row.hasAttribute('data-no-sort')) { flush(); output.push(row); }
          else run.push({ row, cell, order });
        });
        flush(); output.forEach((row) => body.appendChild(row));
      });

      columns.forEach((col) => {
        col.th.setAttribute('aria-sort', col.index === column ? direction : 'none');
        const icon = col.th.querySelector('.tbl-sort-btn span');
        if (icon) icon.textContent = col.index === column ? (direction === 'ascending' ? '↑' : '↓') : '⇅';
      });
      table.dispatchEvent(new CustomEvent('table:changed'));
    }

    function applyHidden(table, columns, hidden) {
      const hiddenSet = new Set(hidden);
      const rows = [];
      if (table.tHead) rows.push(...Array.from(table.tHead.rows));
      Array.from(table.tBodies).forEach((body) => rows.push(...Array.from(body.rows)));
      if (table.tFoot) rows.push(...Array.from(table.tFoot.rows));
      rows.forEach((row) => {
        let cursor = 0;
        Array.from(row.cells).forEach((cell) => {
          const span = originalSpan(cell);
          let visible = 0;
          for (let i = cursor; i < cursor + span; i++) if (!hiddenSet.has(i)) visible++;
          cell.classList.toggle('tbl-col-hidden', visible === 0);
          if (span > 1) cell.colSpan = Math.max(1, visible);
          cursor += span;
        });
      });
      columns.forEach((col) => col.th.classList.toggle('tbl-col-hidden', hiddenSet.has(col.index)));
    }

    function scan(root) {
      if (!root || root.nodeType !== 1) return;
      if (root.matches && root.matches(selector)) enhance(root);
      if (root.querySelectorAll) root.querySelectorAll(selector).forEach(enhance);
    }

    document.querySelectorAll(selector).forEach(enhance);
    document.addEventListener('click', () => {
      document.querySelectorAll('.table-columns-panel:not([hidden])').forEach((p) => { p.hidden = true; });
      document.querySelectorAll('.table-columns-btn[aria-expanded=true]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        document.querySelectorAll('.table-columns-panel:not([hidden])').forEach((p) => { p.hidden = true; });
        document.querySelectorAll('.table-columns-btn[aria-expanded=true]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
      }
    });
    if (document.body && window.MutationObserver) {
      new MutationObserver((changes) => changes.forEach((change) => {
        if (change.type === 'childList') change.addedNodes.forEach(scan);
        else if (change.type === 'attributes' && !change.target.hidden) scan(change.target);
      })).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
    }
  })();

  // ================= معيار الوصول للنماذج والتبويبات والنوافذ =================
  (function () {
    let controlId = 0;
    const controls = 'input:not([type=hidden]),select,textarea';
    function accessibleName(el) {
      if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.closest('label')) return true;
      return !!(el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]'));
    }
    function labelControls(root) {
      (root.matches && root.matches(controls) ? [root] : Array.from(root.querySelectorAll ? root.querySelectorAll(controls) : [])).forEach((el) => {
        if (!el.id) el.id = 'form-control-' + (++controlId);
        const box = el.closest('.field,.fld,.ms-fld,.bf-fld,.inline-field');
        let label = box?.querySelector('label');
        /* ⚠️ نمط شائع في المشروع: الحقل **جوّه** الـlabel نفسه
             <label class="fld"><span>الاسم</span><input required></label>
           وقتها `box` هو الـlabel، و`box.querySelector('label')` بيرجّع null،
           فعلامة «مطلوب» ما كانتش بتتحط على الحقول دي خالص. */
        if (!label && box && box.tagName === 'LABEL') { label = box; }
        if (!label) { label = el.closest('label'); }
        if (label && !label.contains(el)) label.setAttribute('for', el.id);
        if (!accessibleName(el)) {
          const fallback = el.title || el.placeholder || (el.tagName === 'SELECT' ? el.options[0]?.textContent : '') || el.name || 'حقل إدخال';
          el.setAttribute('aria-label', String(fallback).replace(/^[🔍📅📌]+\s*/u, '').replace(/…$/, '').trim());
        }
        if (el.required) {
          el.setAttribute('aria-required', 'true');
          /* لو الحقل جوّه الـlabel، النجمة لازم تتحط على النص (أول <span>)
             مش على الـlabel نفسه — وإلا هتظهر **بعد** خانة الإدخال. */
          const mark = (label && label.contains(el) && label.querySelector('span')) || label;
          if (mark) mark.classList.add('required-label');
        }
      });
      (root.querySelectorAll ? root.querySelectorAll('button,a') : []).forEach((el) => {
        if (!el.getAttribute('aria-label') && !(el.textContent || '').trim() && el.title) el.setAttribute('aria-label', el.title);
      });
    }
    labelControls(document);

    document.addEventListener('invalid', (e) => {
      const el = e.target; if (!el.matches || !el.matches(controls)) return;
      el.setAttribute('aria-invalid', 'true');
      let msg = el.parentElement && el.parentElement.querySelector('.auto-field-error');
      if (!msg) {
        msg = document.createElement('small'); msg.className = 'auto-field-error'; msg.id = el.id + '-error';
        el.insertAdjacentElement('afterend', msg);
      }
      msg.textContent = el.validity.valueMissing ? 'هذا الحقل مطلوب.' : 'راجع القيمة المدخلة في هذا الحقل.';
      el.setAttribute('aria-describedby', msg.id);
    }, true);
    document.addEventListener('input', (e) => {
      const el = e.target; if (!el.matches || !el.matches(controls) || !el.validity.valid) return;
      el.removeAttribute('aria-invalid'); const msg = el.parentElement && el.parentElement.querySelector('.auto-field-error'); if (msg) msg.remove();
    });

    document.querySelectorAll('[role=tablist]').forEach((list) => {
      const tabs = Array.from(list.querySelectorAll('[role=tab],.tab'));
      const sync = () => tabs.forEach((tab, index) => {
        const key = tab.dataset.tab;
        if (!tab.id) tab.id = 'app-tab-' + (key || index) + '-' + controlId;
        const panel = key ? document.getElementById('tab-' + key) || document.querySelector('[data-panel="' + CSS.escape(key) + '"]') : null;
        if (panel) {
          if (!panel.id) panel.id = 'app-panel-' + key + '-' + controlId;
          tab.setAttribute('aria-controls', panel.id); panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', tab.id);
          panel.hidden = !tab.classList.contains('active');
        }
        const on = tab.classList.contains('active'); tab.setAttribute('aria-selected', on ? 'true' : 'false'); tab.tabIndex = on ? 0 : -1;
      });
      tabs.forEach((tab) => {
        tab.setAttribute('role', 'tab');
        tab.addEventListener('click', () => setTimeout(sync, 0));
        tab.addEventListener('keydown', (e) => {
          if (!['ArrowRight','ArrowLeft','Home','End'].includes(e.key)) return;
          e.preventDefault(); let i = tabs.indexOf(tab);
          if (e.key === 'Home') i = 0; else if (e.key === 'End') i = tabs.length - 1;
          else i = (i + (e.key === 'ArrowLeft' ? 1 : -1) + tabs.length) % tabs.length;
          tabs[i].click(); tabs[i].focus();
        });
      });
      sync();
    });

    const dialogObserver = new MutationObserver((changes) => changes.forEach((change) => {
      const host = change.target;
      const dialog = host.matches?.('[role=dialog]') ? host : host.querySelector?.('[role=dialog]');
      /* ⚠️ `.nc` (لوحة الإشعارات) مستثناة — منسدلة **غير مشروطة** فممنوع
         حبس التركيز فيها؛ فتحها/قفلها مُدار في وحدة الإشعارات فوق. */
      if (!dialog || dialog.closest('.confirm-overlay,.form-modal-overlay,.cmdk,.nc')) return;
      const box = dialog.closest('[hidden]') || dialog;
      const open = !box.hidden && box.getAttribute('aria-hidden') !== 'true';
      if (open && !box.__focusTrap) { box.setAttribute('aria-hidden', 'false'); activateDialog(box, dialog.querySelector(focusableSelector)); }
      else if (!open && box.__focusTrap) { box.setAttribute('aria-hidden', 'true'); deactivateDialog(box); }
    }));
    document.querySelectorAll('[hidden]:has([role=dialog])').forEach((host) => dialogObserver.observe(host, {attributes:true,attributeFilter:['hidden','aria-hidden']}));

    if (window.MutationObserver) new MutationObserver((changes) => changes.forEach((change) => change.addedNodes.forEach((node) => {
      if (node.nodeType === 1) labelControls(node);
    }))).observe(document.body, {childList:true,subtree:true});
  })();

  // فتح القسم المستهدف من رابط التمرير عند أول تحميل
  if (manageRoot && location.hash) {
    const t = document.querySelector(location.hash);
    if (t) { if (t.tagName === 'DETAILS') t.open = true; const d = t.closest('details'); if (d) d.open = true; setTimeout(() => t.scrollIntoView({ block: 'center' }), 80); }
  }

  // منع النقر المزدوج على POST العادي، مع إبقاء زر الإرسال ضمن البيانات المرسلة.
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || e.defaultPrevented || form.classList.contains('js-ajax')) return;
    if ((form.method || '').toLowerCase() !== 'post') return;
    if (form.dataset.submitting === '1') { e.preventDefault(); return; }
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');
    const buttons = Array.from(form.querySelectorAll('button[type=submit],input[type=submit]'));
    setTimeout(() => buttons.forEach((button) => {
      button.dataset.wasDisabled = button.disabled ? '1' : '0';
      button.disabled = true;
      if (button.dataset.primarySubmit !== undefined) {
        button.dataset.originalText = button.textContent;
        button.textContent = 'جارٍ الحفظ…';
      }
    }), 0);
  });
  window.addEventListener('pageshow', () => document.querySelectorAll('form[data-submitting="1"]').forEach((form) => {
    form.dataset.submitting = '';
    form.removeAttribute('aria-busy');
    form.querySelectorAll('button[type=submit],input[type=submit]').forEach((button) => {
      button.disabled = button.dataset.wasDisabled === '1';
      if (button.dataset.originalText) { button.textContent = button.dataset.originalText; delete button.dataset.originalText; }
      delete button.dataset.wasDisabled;
    });
  }));

  const errorSummary = document.querySelector('[data-form-error-summary]');
  if (errorSummary) {
    setTimeout(() => {
      errorSummary.focus();
      const first = document.querySelector('[aria-invalid="true"]');
      if (first && first.scrollIntoView) first.scrollIntoView({ block: 'center' });
    }, 30);
  }

  // زر «دوّر في النظام» في صفحة 404 — بيفتح نفس لوحة البحث السريع (Ctrl+K)
  document.addEventListener('click', (e) => {
    const b = e.target.closest && e.target.closest('[data-open-search]');
    if (!b) return;
    const opener = document.getElementById('cmdk-open');
    if (opener) opener.click();
  });

  /* ══════════════════════════════════════════════════════════════════════════
     مركز الإشعارات — المرحلة ٢ (docs/15)
     ─────────────────────────────────────────────────────────────────────────
     ⚠️ **حاجتان مختلفتان في لوحة واحدة، ومفصولتان بصريًّا عن قصد:**
       ① «محتاج منك» = **حالات مستمرة** (٣ أيام مش متقفلة، أرقام سرية ضعيفة).
          أرقام مجمَّعة محسوبة لحظيًّا، **بلا وقت وبلا سجل وما بترنّش**.
       ② «آخر الأحداث» = **أحداث** من جدول v52. بند لكل واحد، بوقته وصاحبه،
          و«مقروء» بتاعته **في القاعدة** فبتتزامن على كل أجهزة الشخص.
     الخلط بينهم هو أصل «الإشعارات المضلِّلة» — **ممنوع تدمج القسمين**.

     ⚠️ **حالة «مقروء» في `localStorage` اتشالت.** كانت تخصّ **المتصفح** لا
        الشخص: تفتح من موبايل تلاقي كل حاجة جديدة، وتمسح بيانات المتصفح
        تبتدي من الأول. المفتاح القديم بيتمسح مرّة واحدة تحت.

     الأداء زي ما هو: الجرس بيترسم فاضي من الخادم (صفر تكلفة على رندر أي
     صفحة)، والنداء بعد التحميل وكل ٦٠ ثانية، وبيقف لما التبويب يتخفي.
     ══════════════════════════════════════════════════════════════════════════ */

  /** «من ٣ دقايق» — بتتحسب في المتصفح من ختم زمني بالثواني، بلا أي طلب للخادم. */
  function notifRelTime(ts) {
    const d = Math.floor(Date.now() / 1000) - ts;
    if (d < 60)     return 'دلوقتي';
    if (d < 3600)   return 'من ' + Math.floor(d / 60) + ' دقيقة';
    if (d < 86400)  return 'من ' + Math.floor(d / 3600) + ' ساعة';
    if (d < 604800) return 'من ' + Math.floor(d / 86400) + ' يوم';
    const x = new Date(ts * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return p(x.getDate()) + '/' + p(x.getMonth() + 1) + '/' + x.getFullYear();
  }

  /**
   * تحديث كل الأوقات النسبية الظاهرة — **بيخدم الجرس وصفحة السجل مع بعض**.
   * حالتان مقصودتان:
   *   • العنصر نفسه `.js-rel` (بند الجرس) → بيتكتب فيه مباشرةً.
   *   • العنصر غلاف بـ`data-ts` (صف الصفحة، `row_item` بيرسم جوّاه) →
   *     بنكتب في `.d-feed-side b`. الغلاف هو المالك للختم لأن `row_item`
   *     مكوّن مشترك وما بيقبلش سمات إضافية.
   */
  function paintNotifTimes(root) {
    (root || document).querySelectorAll('[data-ts]').forEach((el) => {
      const ts = parseInt(el.getAttribute('data-ts'), 10);
      if (!ts) return;
      const target = el.classList.contains('js-rel') ? el : el.querySelector('.d-feed-side b');
      if (target) target.textContent = notifRelTime(ts);
    });
  }

  /** تعليم إشعار كمقروء — الهوية من الجلسة على الخادم، مش من الطلب. */
  function markNotifRead(id) {
    if (!window.__NOTIF_READ || !id) return Promise.resolve();
    const fd = new FormData();
    fd.append('_csrf', window.__CSRF || '');
    // `keepalive` عشان النداء يكمّل حتى لو الصفحة بدأت تتنقّل بعد الضغط
    return fetch(window.__NOTIF_READ.replace('__ID__', String(id)), {
      method: 'POST', body: fd, keepalive: true,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).catch(() => {});
  }

  (function notifCenter() {
    const root  = document.getElementById('notifCenter');
    const btn   = document.getElementById('notifBtn');
    const panel = document.getElementById('notifPanel');
    const list  = document.getElementById('notifList');
    const badge = document.getElementById('notifBadge');
    const count = document.getElementById('notifCount');
    const mark  = document.getElementById('notifMark');
    if (!root || !btn || !panel || !list || !window.__NOTIF_PING) return;

    // مفتاح «مقروء» القديم (كان يخصّ المتصفح) — يتنسي بهدوء مرّة واحدة
    try { localStorage.removeItem('almotawakel:notif-seen'); } catch (e) {}

    const POLL_MS = 60000;
    let events = [];      // أحداث (جدول v52)
    let states = [];      // حالات مستمرة (محسوبة لحظيًّا)
    let unread = 0;
    let timer = 0;
    let tick  = 0;

    /* شارات القائمة الجانبية — من **نفس** نداء الـping، فمستحيل الرقم اللي
       جنب «عهدة المناديب» يختلف عن اللي في الجرس. صفر تكلفة زيادة. */
    function paintNavBadges() {
      const byKey = {};
      states.forEach((i) => { byKey[i.key] = i; });
      document.querySelectorAll('#primary-nav a[data-badge]').forEach((a) => {
        const cnt = a.querySelector('.nav-cnt');
        if (!cnt) return;
        const it = byKey[a.dataset.badge];
        if (!it || !it.count) { cnt.hidden = true; return; }
        cnt.hidden = false;
        cnt.textContent = it.count > 99 ? '99+' : String(it.count);
        // الأحمر للي محتاج تصرّف فورًا، والذهبي للمعلومة
        cnt.classList.toggle('soft', it.kind === 'info');
        a.setAttribute('title', it.title);
      });
    }

    function paintBadge() {
      paintNavBadges();
      badge.textContent = unread > 9 ? '+9' : String(unread);
      const was = badge.classList.contains('on');
      badge.classList.toggle('on', unread > 0);
      if (unread > 0 && !was) { badge.classList.add('pulse'); setTimeout(() => badge.classList.remove('pulse'), 1200); }
      btn.setAttribute('aria-label', unread > 0 ? ('الإشعارات — ' + unread + ' غير مقروء') : 'الإشعارات');
      if (mark) mark.disabled = unread === 0;
    }

    function svg(name) {
      const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', '17'); s.setAttribute('height', '17');
      s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor');
      s.setAttribute('stroke-width', '2'); s.setAttribute('stroke-linecap', 'round');
      s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
      /* ⚠️ الخريطة دي لازم تغطّي **كل** أيقونة في `Notify::KINDS` وفي مصادر
         `Notification`. الاسم الناقص بيقع على شكل افتراضي **بالسكوت** —
         `bin/verify_notifications_ui.php` بيفشل لو اسم ناقص من هنا. */
      const paths = {
        inbox:      '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        calculator: '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M8 6h8M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/>',
        receipt:    '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8M8 11h8M8 15h5"/>',
        clipboard:  '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
        'map-pin':  '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        alert:      '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4M12 17h.01"/>',
        folder:     '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        truck:      '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        banknote:   '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
        wallet:     '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2V5"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        coins:      '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
        shuffle:    '<path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/>',
        'check-circle': '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
        x:          '<path d="M18 6 6 18M6 6l12 12"/>',
        trash:      '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
        send:       '<path d="M14.54 21.69a.5.5 0 0 0 .94-.02l6.5-19a.5.5 0 0 0-.64-.64l-19 6.5a.5.5 0 0 0-.02.94l7.93 3.18a2 2 0 0 1 1.11 1.11z"/><path d="m21.85 2.15-10.94 10.94"/>',
        bell:       '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
        // أيقونات أنواع المرحلة ٧ (التذكيرات والملخّص اليومي)
        clock:      '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        hourglass:  '<path d="M5 22h14M5 2h14"/><path d="M17 22v-4.17a2 2 0 0 0-.59-1.41L12 12l-4.41 4.42A2 2 0 0 0 7 17.83V22"/><path d="M7 2v4.17a2 2 0 0 0 .59 1.41L12 12l4.41-4.42A2 2 0 0 0 17 6.17V2"/>',
        'bar-chart':'<line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/>',
        // قفل بوابة المندوب للصيانة (v68) — لازم مطابقة لـ`icon('wrench')` في helpers.php
        wrench:     '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'
      };
      s.innerHTML = paths[name] || paths.alert;
      return s;
    }

    function sectionHead(text, n) {
      const h = document.createElement('div');
      h.className = 'nc-sec';
      const t = document.createElement('span'); t.textContent = text;
      h.appendChild(t);
      if (n) { const c = document.createElement('span'); c.className = 'nc-sec-n'; c.textContent = String(n); h.appendChild(c); }
      return h;
    }

    /** النهاردة / إمبارح / الأسبوع ده / أقدم — تجميع **بصري بالتاريخ بس**. */
    function dayBucket(ts) {
      const d = new Date(ts * 1000);
      const today = new Date(); today.setHours(0, 0, 0, 0);
      const start = new Date(d); start.setHours(0, 0, 0, 0);
      const diff = Math.round((today - start) / 86400000);
      if (diff <= 0) return 'النهاردة';
      if (diff === 1) return 'إمبارح';
      if (diff < 7)  return 'الأسبوع ده';
      return 'أقدم';
    }

    function stateNode(i) {
      const a = document.createElement('a');
      a.className = 'nc-item ' + (i.kind || 'info');
      a.href = i.url;
      const ic = document.createElement('span'); ic.className = 'nc-ic'; ic.appendChild(svg(i.icon));
      const tx = document.createElement('div'); tx.className = 'nc-tx';
      const b = document.createElement('b'); b.textContent = i.title;   // textContent — بلا حقن
      const s = document.createElement('span'); s.textContent = i.body;
      tx.appendChild(b); tx.appendChild(s);
      const go = document.createElement('span'); go.className = 'nc-go'; go.textContent = '‹';
      a.appendChild(ic); a.appendChild(tx); a.appendChild(go);
      return a;
    }

    function eventNode(i) {
      const hasUrl = !!i.url;
      const a = document.createElement(hasUrl ? 'a' : 'div');
      a.className = 'nc-item ev ' + (i.severity === 'urgent' ? 'err' : (i.severity === 'warn' ? 'warn' : 'info'))
                  + (i.read ? '' : ' unread');
      if (hasUrl) a.href = i.url;
      a.title = i.kind_label + ' · ' + i.at_label;   // الوقت الكامل بساعة المستخدم
      const ic = document.createElement('span'); ic.className = 'nc-ic'; ic.appendChild(svg(i.icon));
      const tx = document.createElement('div'); tx.className = 'nc-tx';
      const b = document.createElement('b'); b.textContent = i.title;   // textContent — بلا حقن
      const s = document.createElement('span'); s.textContent = i.body;
      const w = document.createElement('span');
      w.className = 'nc-when js-rel'; w.setAttribute('data-ts', String(i.ts));
      w.textContent = i.rel || notifRelTime(i.ts);
      tx.appendChild(b); tx.appendChild(s); tx.appendChild(w);
      a.appendChild(ic); a.appendChild(tx);
      if (!i.read) { const dot = document.createElement('span'); dot.className = 'nc-dot'; a.appendChild(dot); }
      // فتح البند = قراءته، والقراءة بتتخزّن في القاعدة فبتتزامن على كل الأجهزة
      a.addEventListener('click', () => {
        if (i.read) return;
        i.read = true; unread = Math.max(0, unread - 1);
        a.classList.remove('unread');
        const d = a.querySelector('.nc-dot'); if (d) d.remove();
        paintBadge();
        markNotifRead(i.id);
      });
      return a;
    }

    function paintList() {
      list.textContent = '';
      if (count) count.textContent = unread > 0 ? intfJs(unread) + ' غير مقروء' : '';

      if (states.length) {
        list.appendChild(sectionHead('محتاج منك', states.length));
        states.forEach((i) => list.appendChild(stateNode(i)));
      }

      if (events.length) {
        list.appendChild(sectionHead('آخر الأحداث'));
        let bucket = null;
        events.forEach((i) => {
          const b = dayBucket(i.ts);
          if (b !== bucket) {
            bucket = b;
            const h = document.createElement('div'); h.className = 'nc-day'; h.textContent = b;
            list.appendChild(h);
          }
          list.appendChild(eventNode(i));
        });
      }

      if (!states.length && !events.length) {
        const box = document.createElement('div');
        box.className = 'nc-empty';
        const b = document.createElement('b'); b.textContent = 'كله تمام ✓';
        const s = document.createElement('span'); s.textContent = 'مفيش حاجة مستنياك دلوقتي.';
        box.appendChild(b); box.appendChild(s);
        list.appendChild(box);
      }
    }

    function intfJs(n) { return new Intl.NumberFormat('en').format(n); }

    function refresh() {
      fetch(window.__NOTIF_PING, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error('http'))))
        .then((d) => {
          if (!d || !d.ok) return;
          // التحديث الإجباري بيركب على نفس النداء ده — **صفر طلب زيادة**.
          // (لو اتشال، وحدة التحديث بتسلّح نداءها الخاص بعد دقيقتين فما بتقفش،
          //  بس بتبقى طلبًا زيادة كل دقيقة بلا داعي.)
          if (window.AppUpdate && d.build) window.AppUpdate.seen(d.build);
          events = (d.events && Array.isArray(d.events.items)) ? d.events.items : [];
          unread = (d.events && d.events.unread) ? d.events.unread : 0;
          states = (d.states && Array.isArray(d.states.items)) ? d.states.items : [];
          paintBadge();
          if (!panel.hidden) paintList();
        })
        .catch(() => { /* الشبكة وقعت — الجرس يفضل بآخر حالة معروفة بلا إزعاج */ });
    }

    function openPanel() {
      panel.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      paintList();
      /* ⚠️ اللوحة **منسدلة غير مشروطة** (`aria-modal="false"`) — فممنوع
         `activateDialog` هنا: حبس التركيز في لوحة غير مشروطة بيمنع مستخدم
         الكيبورد من إكمال الصفحة بـTab (فخّ WCAG 2.1.2). التركيز بيتحرّك
         لأول بند **بلا حبس**، وEscape بيقفل ويرجّع التركيز للجرس (تحت). */
      const first = panel.querySelector('a,button');
      if (first) setTimeout(() => first.focus(), 20);
    }
    function closePanel() {
      if (panel.hidden) return;
      deactivateDialog(panel);
      panel.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', () => { panel.hidden ? openPanel() : closePanel(); });
    document.addEventListener('click', (e) => { if (!root.contains(e.target)) closePanel(); });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !panel.hidden) { e.stopPropagation(); closePanel(); btn.focus(); }
    });
    if (mark) {
      mark.addEventListener('click', () => {
        if (!window.__NOTIF_READ_ALL) return;
        const fd = new FormData(); fd.append('_csrf', window.__CSRF || '');
        fetch(window.__NOTIF_READ_ALL, {
          method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then((r) => {
          /* ⚠️ كانت بتصفّر الواجهة من غير ما تتأكد إن الطلب نجح — لو الشبكة
             وقعت كان العدّاد بيقول «صفر غير مقروء» **كدب** لحد الـping الجاي. */
          if (!r.ok) throw new Error('http');
          events.forEach((i) => { i.read = true; });
          unread = 0; paintBadge(); paintList();
          document.querySelectorAll('.nl-item.unread').forEach((el) => el.classList.remove('unread'));
        }).catch(() => {});
      });
    }

    // أول نداء بعد التحميل، وبعدين كل دقيقة — وبنوقف لما التبويب يتخفي
    refresh();
    const start = () => {
      clearInterval(timer);
      timer = setInterval(refresh, POLL_MS);
      clearInterval(tick);
      // الوقت النسبي بيتحدّث لوحده **بلا أي طلب للخادم**
      tick = setInterval(() => paintNotifTimes(), 60000);
    };
    start();
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) { clearInterval(timer); clearInterval(tick); }
      else { refresh(); paintNotifTimes(); start(); }
    });
  })();

  /* صفحة السجل `/notifications` — نفس أدوات الجرس بالظبط:
     الوقت النسبي بيتحدّث لوحده، والضغط على أي بند بيعلّمه مقروء في القاعدة. */
  (function notifPage() {
    if (!document.querySelector('.nl-item')) return;
    paintNotifTimes();
    /* ⚠️ بلا طلب خادم (تحديث نصوص محلي) لكنه كان بيفضل يشتغل والتبويب
       مخفي — نفس حارس «التبويب المخفي» اللي على مؤقّتات الجرس فوق. */
    setInterval(() => { if (!document.hidden) paintNotifTimes(); }, 60000);

    document.addEventListener('click', (e) => {
      if (!e.target.closest) return;

      /* ── تأجيل (المرحلة ٦) ──────────────────────────────────────────────
         ⚠️ POST بتوكن حماية — مفيش أي إجراء بيغيّر حالة عبر GET، والمدّة
            بتتفحص على الخادم من قائمة بيضا فالفورم مش مصدر ثقة. */
      const sn = e.target.closest('.js-nl-snooze');
      if (sn) {
        e.preventDefault();
        const row = sn.closest('.nl-item');
        const fd = new FormData();
        fd.append('_csrf', window.__CSRF || '');
        fd.append('minutes', sn.getAttribute('data-min') || '60');
        fetch((window.__NOTIF_SNOOZE || '').replace('__ID__', sn.getAttribute('data-id')), {
          method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then((r) => (r.ok ? r.json() : Promise.reject(new Error('http'))))
          .then(() => {
            if (row) row.remove();
            if (window.AppUI && AppUI.toast) AppUI.toast('اتأجّل — هيرجع يظهر لوحده.', 'ok');
          })
          // ⚠️ الفشل لازم يبان: لو سكتنا، المستخدم يفتكر إنه أجّل وهو ما أجّلش
          .catch(() => { if (window.AppUI && AppUI.toast) AppUI.toast('مقدرناش نأجّل البند ده.', 'err'); });
        return;
      }

      // ── تعليم بند كمقروء بزرار صريح ──
      const rd = e.target.closest('.js-nl-read');
      if (rd) {
        e.preventDefault();
        const row = rd.closest('.nl-item');
        if (row) row.classList.remove('unread');
        rd.remove();
        markNotifRead(rd.getAttribute('data-id'));
        return;
      }

      // ── الضغط على البند نفسه = قراءته ──
      const row = e.target.closest('.nl-item.unread');
      if (!row) return;
      row.classList.remove('unread');
      markNotifRead(row.getAttribute('data-notif-id'));
    });
  })();

  /* ── نظام التفاصيل (drill-down) ────────────────────────────────────────────
     ⚠️ قاعدة المالك الدائمة: **ممنوع رقم أصمّ.** أي رقم أو كارت معروض لازم
        يُضغط ويفتح التفاصيل اللي اتحسب منها.

     أي عنصر بـ`data-drill` بيفتح النافذة العامة الوحيدة (`#uiDrill` في
     `layout.php`). وضعان:
       • `data-drill="#id"`  → المحتوى **جاهز** في الصفحة (قوايم صغيرة رخيصة).
       • `data-drill="/url"` → المحتوى **يتجاب وقت الضغط بس** — ده الافتراضي،
         وهو اللي بيخلّي القاعدة قابلة للتعميم على مئات الأرقام بلا ما الصفحات
         تتقل. (البوب-أب القديم كان بيرندر تفاصيل كل الأرقام مع كل فتحة للصفحة.)

     ⚠️ الرد HTML مش JSON عن قصد: الخادم بيرندر نفس مكوّنات الصفوف، فالتفاصيل
        شكلها زي القوايم بالظبط بلا مُصيِّر تاني في الجافاسكريبت يفترق عنه.
     ⚠️ ممنوع مسار تفاصيل عام واحد ياخد اسم جدول — كل وحدة بتعرّض مسارها
        وبيمرّ على `Access::enforce` زي أي مسار (الرفض هو الافتراضي). */
  (function drillDown() {
    const modal = document.getElementById('uiDrill');
    if (!modal) return;
    const body = document.getElementById('uiDrillBody');
    const titleEl = document.getElementById('uiDrillTitle');
    const cache = new Map();
    let token = 0;
    const icoAlert = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 9v4M12 17h.01"/>' +
      '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>';

    function close() {
      if (modal.hidden) return;
      modal.hidden = true;
      body.innerHTML = '';
      document.body.style.overflow = '';
      deactivateDialog(modal);
    }
    // أي جدول بيتحقن هنا بياخد أدوات الجداول (ترتيب/إخفاء أعمدة/كروت الموبايل)
    // **تلقائيًّا** من الـMutationObserver القائم — ما تنادّيش حاجة بإيدك.
    function paint(html) { body.innerHTML = html; }
    async function open(source, title) {
      titleEl.textContent = title || 'التفاصيل';
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
      activateDialog(modal, modal.querySelector('.d-modal-x'));
      const mine = ++token;

      if (source.charAt(0) === '#') {                 // جاهز في الصفحة
        const src = document.querySelector(source);
        paint(src ? src.innerHTML : '');
        if (!src) { paint('<div class="drill-err"><b>التفاصيل مش موجودة</b></div>'); }
        return;
      }
      if (cache.has(source)) { paint(cache.get(source)); return; }

      paint('<div class="drill-load"><span class="drill-spin"></span><span>بنجيب التفاصيل…</span></div>');
      try {
        const res = await fetch(source, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error(String(res.status));
        const html = await res.text();
        if (mine !== token) return;                   // المستخدم فتح رقمًا تاني
        cache.set(source, html);
        paint(html);
      } catch (e) {
        if (mine !== token) return;
        // ⚠️ الفشل بيبان — ما ينفعش النافذة تفضل بتلفّ للأبد والمالك مستني
        paint('<div class="drill-err">' + icoAlert +
              '<b>تعذّر جلب التفاصيل</b><span>راجع اتصالك وجرّب تاني.</span></div>');
      }
    }
    document.addEventListener('click', (e) => {
      const el = e.target.closest('[data-drill]');
      if (!el) return;
      // رابط جوّه كارت قابل للفتح بيفضل رابط عادي — مش كل ضغطة تفتح النافذة
      if (e.target.closest('a[href]') && !el.matches('a[href]')) return;
      e.preventDefault();
      open(el.getAttribute('data-drill'), el.getAttribute('data-drill-title') || el.getAttribute('data-title'));
    });
    modal.querySelectorAll('[data-close]').forEach((el) => el.addEventListener('click', close));
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !modal.hidden) { e.stopPropagation(); close(); }
    });
  })();

  /* ═════════════════════════════════════════════════════════════════════
     درج تعديل مستند طرف (v56) — `.js-pdoc-edit` / `.js-pdoc-cancel`
     ─────────────────────────────────────────────────────────────────────
     ⚠️ عايش في `app.js` **العام** مش في <style>/<script> محلي: نفس الماركب
        بيترندر في بروفايل الشركة **و**بروفايل الوكيل. النسخة المحلية كانت
        هتشتغل في شاشة وتفشل بالسكوت في التانية.
     ⚠️ الدرج `hidden` من الخادم فبيشتغل بلا جافاسكريبت كمان: لو الملف ما
        اتحمّلش، الأزرار ما بتعملش حاجة والصفحة تفضل مقروءة.
     ═════════════════════════════════════════════════════════════════════ */
  document.addEventListener('click', (e) => {
    const open = e.target.closest('.js-pdoc-edit');
    const shut = e.target.closest('.js-pdoc-cancel');
    if (!open && !shut) return;
    const id = (open || shut).getAttribute('data-doc');
    const form = document.getElementById('pdoc-edit-' + id);
    if (!form) return;
    form.hidden = !!shut;
    if (open) {
      const first = form.querySelector('select,input');
      if (first) first.focus();
    }
  });
})();

/* ═══════════════════════════════════════════════════════════════════════
   وضع العرض (فاتح / غامق / تلقائي) — المرحلة ١٥
   ═══════════════════════════════════════════════════════════════════════
   ⚠️ السمة نفسها بتتحط في `<head>` **قبل أول رسمة** (سكربت صغير في
      `layout.php`) — الكود ده بيدير **التبديل** بس. لو نقلته لهنا لوحده
      الصفحة هتلمع أبيض ثم تغمق في كل تحميل.
   ⚠️ ثلاث حالات مقصودة: `auto` (مفيش سمة على `<html>`، فبيتبع الجهاز) ثم
      `light` ثم `dark`. «تلقائي» مهم لأن أغلب الناس بيسيبوا الجهاز يقرّر.
   ═══════════════════════════════════════════════════════════════════════ */
(function themeToggle() {
  var KEY = 'almotawakel:theme';
  var btn = document.getElementById('themeBtn');
  if (!btn) return;
  var root = document.documentElement;
  var LABEL = { auto: 'تلقائي (زي الجهاز)', light: 'فاتح', dark: 'غامق' };

  function current() {
    var t = root.getAttribute('data-theme');
    return (t === 'light' || t === 'dark') ? t : 'auto';
  }
  function paint() {
    btn.title = 'وضع العرض: ' + LABEL[current()];
    btn.setAttribute('aria-label', btn.title + ' — اضغط للتبديل');
  }
  function apply(mode) {
    if (mode === 'auto') { root.removeAttribute('data-theme'); }
    else { root.setAttribute('data-theme', mode); }
    try { mode === 'auto' ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, mode); } catch (e) {}
    paint();
  }
  btn.addEventListener('click', function () {
    var next = { auto: 'light', light: 'dark', dark: 'auto' }[current()];
    apply(next);
    if (window.AppUI && window.AppUI.toast) {
      window.AppUI.toast('وضع العرض: ' + LABEL[next], 'info');
    }
  });
  paint();
})();

/* ══════════════════════════════════════════════════════════════════════
   خزنة الشركة `/company-safe` (v66) — اختصارات الفورم.

   ⚠️ **تحسين تدريجي بحت**: نوع الحركة `radio` حقيقي والمبلغ خانة عادية، فلو
      السكربت ده وقع الفورم لسه بيشتغل بالكامل. اللي بيضيفه هنا اختصارَين:
        • زرار من الهيرو أو من شريط التصرّف بيختار النوع وينطّ للفورم.
        • شريحة مبلغ بتملا خانة المبلغ.
   ⚠️ **بالتفويض على `document`** زي باقي خطّافات الملف — ومحصورة بالصفحة
      اللي فيها `#csForm` فما بتلمسش أي شاشة تانية.
   ══════════════════════════════════════════════════════════════════════ */
(function companySafeForm() {
  var form = document.getElementById('csForm');
  if (!form) return;

  /* v67 — الحقول المشروطة بالنوع (فئة المصروف · كارت الفيزا · المندوب).
     ⚠️ **الإخفاء من الجافاسكريبت لا من الخادم** (نفس قاعدة خانات سيارات المكتب):
        لو السكربت وقع، المدير لسه شايف كل الخانات ويقدر يسجّل، والخادم هو
        اللي بيفرض القواعد في `CompanySafe::normalise`.
     ⚠️ **الإخفاء ما بيمسحش أي قيمة متكتبة** (بعكس `data-dep`) — تبديل النوع
        بالغلط كان هيضيّع اللي اتكتب. الخادم بيتجاهل الحقل اللي مش بتاع النوع. */
  function syncKindFields() {
    var picked = form.querySelector('input[name="kind"]:checked');
    var kind = picked ? picked.value : '';
    form.querySelectorAll('[data-cs-for]').forEach(function (el) {
      var want = el.getAttribute('data-cs-for').split(' ');
      el.hidden = want.indexOf(kind) === -1;
    });
  }

  function pickKind(kind) {
    var input = form.querySelector('input[name="kind"][value="' + kind + '"]');
    if (!input) return false;
    input.checked = true;
    // ⚠️ `change` لازم يتبعت يدويًّا — التعيين البرمجي ما بيطلقش الحدث،
    //    وفيه معالج «الفورم اتغيّر» (js-dirty-form) بيسمع عليه.
    input.dispatchEvent(new Event('change', { bubbles: true }));
    syncKindFields();
    return true;
  }

  form.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'kind') { syncKindFields(); }
  });
  syncKindFields();

  document.addEventListener('click', function (e) {
    var kindBtn = e.target.closest('[data-cs-kind]');
    if (kindBtn) {
      e.preventDefault();
      if (!pickKind(kindBtn.getAttribute('data-cs-kind'))) return;
      var card = document.getElementById('entry');
      if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      var amount = document.getElementById('csAmount');
      if (amount) { setTimeout(function () { amount.focus(); }, 260); }
      return;
    }
    var chip = e.target.closest('[data-cs-amount]');
    if (chip) {
      e.preventDefault();
      var box = document.getElementById('csAmount');
      if (!box) return;
      box.value = chip.getAttribute('data-cs-amount');
      box.dispatchEvent(new Event('input', { bubbles: true }));
      box.focus();
    }
  });
})();
