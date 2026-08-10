<?php
/**
 * مركز التحكّم — الهيكل (docs/19 · المرحلة ٣ · v76).
 *
 * ═══ ليه اتعاد بناؤه ═══
 * الشاشة كانت **٦٧١ سطرًا في ملف واحد** فيها ٣ بلوكات `<style>` محلية،
 * وتبويباتها بـ`#hash` بس: مفيش رابط مباشر لقسم، ولا رجوع للخلف، ولا بحث —
 * وكل الأقسام العشرة بترندر في كل زيارة عشان تبويب واحد هو الظاهر.
 *
 * دلوقتي: **شريط أقسام + صفحة لكل قسم بمسار حقيقي + بحث فوري في كل الأقسام**.
 *
 * ⚠️ **صفر `<style>` محلي** (قانون التصميم بند ٧) — كل الكلاسات في `app.css`
 *    تحت «مركز التحكّم». لو كلاس اتكتب هنا بلا CSS، بيقع على الشكل الافتراضي
 *    **بالسكوت** (درس `.table-mobile-scroll`).
 *
 * @var string $section  القسم المفتوح
 * @var array  $pages    الأقسام اللي الدور ده بيشوفها
 * @var array  $pageMeta تعريف القسم المفتوح
 * @var array  $searchIndex فهرس البحث (كل الأقسام مش المفتوح بس)
 */
$pages       = $pages ?? [];
$section     = (string) ($section ?? 'general');
$pageMeta    = $pageMeta ?? ['label' => '', 'icon' => 'settings'];
$searchIndex = $searchIndex ?? [];
?>
<div class="st-shell">

  <?php /* ══ الشريط الجانبي ═══════════════════════════════════════════════
           ⚠️ `aria-current="page"` مش زخرفة — هو اللي بيدّي القسم النشط شكله
              في CSS **وبيقوله لقارئ الشاشة** بنفس السطر. */ ?>
  <nav class="st-rail" aria-label="أقسام الإعدادات">
    <?php foreach ($pages as $key => $p): ?>
      <a href="<?= url('settings/' . $key) ?>"<?= $key === $section ? ' aria-current="page"' : '' ?>>
        <?= icon($p['icon'], 15) ?>
        <span><?= e($p['label']) ?></span>
        <?php if (!empty($p['badge'])): ?>
          <span class="st-badge<?= !empty($p['badge']['warn']) ? ' warn' : '' ?>"><?= e($p['badge']['text']) ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div>
    <div class="st-head">
      <h2><?= icon($pageMeta['icon'], 19) ?> <?= e($pageMeta['label']) ?></h2>
      <?php if (!empty($pageMeta['owner'])): ?>
        <span class="st-role"><?= icon('lock', 12) ?> للمالك</span>
      <?php endif; ?>
      <?php /* ══ البحث الفوري — بيدوّر في **كل** الأقسام مش المعروض بس ══ */ ?>
      <?php /* ⚠️ **الأيقونة إجبارية هنا**: الخانة بلا أيقونة ولا placeholder
               (قانون التصميم بيمنع الجملة التعليمية) كانت بتبان **صندوقًا فاضيًا
               مجهولًا** — والمالك بلّغ «مش عارف ده إيه». الأيقونة بدل الكلمة. */ ?>
      <div class="st-search">
        <?= icon('search', 15) ?>
        <label class="sr-only" for="stSearch">دوّر في الإعدادات</label>
        <input type="search" id="stSearch" autocomplete="off" aria-controls="stResults" aria-expanded="false">
        <div class="st-results" id="stResults" role="listbox" hidden></div>
      </div>
    </div>

    <?php
      /* ⚠️ الجزء بيتحمّل بالاسم من `PAGES` مش من `$section` مباشرةً — فمستحيل
         مسار مزوَّر يوصل لملف برّه المجلد. */
      $__view = __DIR__ . '/sections/' . basename((string) ($pageMeta['view'] ?? 'general')) . '.php';
      if (is_file($__view)) { include $__view; }
      else { echo '<!-- جزء ناقص: ' . e((string) ($pageMeta['view'] ?? '')) . ' -->'; }
    ?>
  </div>
</div>

<script>
(function () {
  /* بحث الإعدادات — الفهرس بيتحقن من الخادم فالنتيجة بتشمل أقسامًا مش مفتوحة.
     ⚠️ التنقّل بيروح لـ`/settings/<قسم>?f=<مفتاح>` والخانة بتلمع هناك — عشان
        «لقيتها» تبقى واضحة من غير ما المستخدم يدوّر بعينه في الصفحة. */
  var IDX = <?= js_json($searchIndex) ?>;
  var BASE = <?= js_json(url('settings')) ?>;
  var box = document.getElementById('stSearch');
  var out = document.getElementById('stResults');
  if (!box || !out) { return; }

  function norm(s) { return (s || '').toString().toLowerCase()
    .replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/[ىئ]/g, 'ي').replace(/\s+/g, ' ').trim(); }

  function render(list, q) {
    if (!list.length) {
      out.innerHTML = '<div class="st-none">مفيش إعداد بالاسم ده</div>';
      out.hidden = false; box.setAttribute('aria-expanded', 'true'); return;
    }
    out.innerHTML = list.map(function (r) {
      var href = BASE + '/' + r.p + (r.k ? '?f=' + encodeURIComponent(r.k) : '');
      return '<a role="option" href="' + href + '"><b>' + esc(r.t) + '</b><small>' + esc(r.pl) + '</small></a>';
    }).join('');
    out.hidden = false; box.setAttribute('aria-expanded', 'true');
  }
  function esc(s) { var d = document.createElement('span'); d.textContent = s || ''; return d.innerHTML; }
  function close() { out.hidden = true; box.setAttribute('aria-expanded', 'false'); }

  box.addEventListener('input', function () {
    var q = norm(box.value);
    if (q.length < 2) { close(); return; }
    var hits = IDX.filter(function (r) {
      return norm(r.t).indexOf(q) >= 0 || norm(r.h).indexOf(q) >= 0 || norm(r.pl).indexOf(q) >= 0;
    }).slice(0, 12);
    render(hits, q);
  });
  box.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { close(); box.blur(); }
    if (e.key === 'Enter') { var a = out.querySelector('a'); if (a && !out.hidden) { e.preventDefault(); a.click(); } }
  });
  document.addEventListener('click', function (e) { if (!out.contains(e.target) && e.target !== box) { close(); } });

  /* الخانة اللي البحث ودّانا لها: تلمع مرة واحدة وتتمركز في الشاشة. */
  var focusKey = new URLSearchParams(location.search).get('f');
  if (focusKey) {
    var el = document.querySelector('[name="' + focusKey.replace(/"/g, '') + '"]')
          || document.querySelector('[value="' + focusKey.replace(/"/g, '') + '"]');
    if (el) {
      var wrap = el.closest('.field') || el.closest('.card') || el;
      wrap.classList.add('st-flash');
      wrap.scrollIntoView({ block: 'center', behavior: 'smooth' });
      if (el.focus) { try { el.focus({ preventScroll: true }); } catch (x) {} }
    }
  }
})();
</script>
