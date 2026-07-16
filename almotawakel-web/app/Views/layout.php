<?php use App\Core\Auth; use App\Core\App;
$cur = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = App::baseUrl();
if ($base && str_starts_with($cur, $base)) { $cur = substr($cur, strlen($base)); }
$cur = '/' . trim($cur, '/');
$isActive = function(string $seg) use ($cur): string {
    if ($seg === '' ) return $cur === '/' ? 'active' : '';
    return str_starts_with($cur, '/' . $seg) ? 'active' : '';
};
$nav = [
    ['__sec_home', 'الرئيسية', null],
    ['', 'لوحة التحكم', 'dashboard'],
    ['assistant', 'المساعد الذكي', 'sparkles'],
    ['__sec_ops', 'العمليات', null],
    ['permits', 'الإصدارات (أذونات/شهادات)', 'folder'],
    ['bawalis', 'البوالص', 'truck'],
    ['tracking', 'تتبع الشحنات', 'map-pin'],
    ['settlements', 'سداد البوالص', 'banknote'],
    ['takhlis', 'التخليص', 'clipboard'],
    ['generators', 'المولدات', 'cpu'],
    ['closing', 'التقفيل اليومي', 'calculator'],
    ['__sec_billing', 'الفوترة والكشوف', null],
    ['invoices', 'إصدار الفواتير', 'receipt'],
    ['statements', 'كشوف أصحاب البيان', 'file-text'],
    ['__sec_finance', 'المالية', null],
    ['debts', 'المديونيات', 'credit-card'],
    ['payments', 'تحصيلات العملاء', 'wallet'],
    ['custody', 'رصيد العهدة', 'briefcase'],
    ['office', 'رصيد المكتب (الفيزا)', 'credit-card'],
    ['reps', 'المناديب', 'user'],
    ['expenses', 'المصروفات', 'trending-down'],
    ['__sec_records', 'السجلات', null],
    ['directory', 'دليل الأطراف', 'users'],
    ['legacy', 'أرشيف البوالص القديمة', 'inbox'],
    ['__sec_admin', 'الإدارة', null],
    ['reports', 'التقارير', 'bar-chart'],
    ['audit', 'سجل العمليات والمحذوفات', 'clipboard'],
    ['settings', 'الإعدادات', 'settings'],
];
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '') ?> — <?= e(App::setting('company_name','شركة المتوكل')) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%2315355B'/><text x='50' y='68' font-size='54' text-anchor='middle' fill='%23C9A227' font-family='Arial' font-weight='bold'>م</text></svg>">
</head>
<body>
<a class="skip-link" href="#main-content">تخطَّ إلى المحتوى الرئيسي</a>
<script>try{if(localStorage.getItem('nav-collapsed')==='1'&&window.innerWidth>820)document.documentElement.classList.add('nav-collapsed');}catch(e){}</script>
<div class="app">
  <aside class="sidebar" id="primary-sidebar" aria-label="التنقل الرئيسي">
    <div class="brand">
      <a href="<?= url('') ?>" class="brand-logo" aria-label="<?= e(App::setting('company_name','شركة المتوكل')) ?>">
        <img src="<?= url('assets/img/logo.png') ?>" alt="<?= e(App::setting('company_name','شركة المتوكل')) ?>" class="logo-full">
        <span class="logo-mark">م</span>
      </a>
      <button type="button" class="nav-toggle" aria-label="طي القائمة" aria-controls="primary-sidebar" aria-expanded="true" title="طي / فتح القائمة">‹</button>
    </div>
    <nav class="nav" aria-label="أقسام النظام">
      <?php foreach ($nav as [$seg, $label, $ic]):
        if ($ic === null): ?>
          <div class="section"><?= e($label) ?></div>
      <?php else: ?>
          <a href="<?= url($seg) ?>" class="<?= $isActive($seg) ?>" title="<?= e($label) ?>"<?= $isActive($seg) ? ' aria-current="page"' : '' ?>>
            <span class="ic"><?= icon($ic, 20) ?></span><span class="lbl"><?= e($label) ?></span>
          </a>
      <?php endif; endforeach; ?>
    </nav>
    <div class="foot">
      <span class="foot-full">الإصدار 1.0 — <?= date('Y') ?></span>
      <span class="foot-mark"><?= date('Y') ?></span>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-btn" type="button" aria-label="فتح القائمة" aria-controls="primary-sidebar" aria-expanded="false"><?= icon('menu', 22) ?></button>
      <div class="page-title"><?= e($title ?? '') ?></div>
      <div class="spacer"></div>
      <button type="button" class="search-trigger" id="cmdk-open" aria-label="بحث سريع (Ctrl+K)">
        <span class="ico"><?= icon('search', 18) ?></span>
        <span class="search-trigger-txt">ابحث عن أي شيء…</span>
        <kbd class="search-kbd">Ctrl K</kbd>
      </button>
      <button type="button" class="btn ghost sm icon-btn" id="shortcut-help" title="اختصارات الإدخال" aria-label="اختصارات الإدخال">⌨</button>
      <div class="user" title="<?= e(Auth::user() ?? '') ?>">
        <span class="av"><?= e(mb_substr(Auth::user() ?? 'م', 0, 1)) ?></span>
        <span class="user-name"><?= e(Auth::user() ?? '') ?></span>
      </div>
      <form action="<?= url('logout') ?>" method="post" class="no-confirm" style="margin:0">
        <?= csrfField() ?>
        <button class="btn ghost sm icon-btn" type="submit" title="تسجيل الخروج"><?= icon('log-out', 16) ?><span class="lbl-hide">خروج</span></button>
      </form>
    </header>

    <main class="content" id="main-content" tabindex="-1">
      <?php $wd = Auth::workDate(); $wdToday = Auth::isWorkDateToday(); ?>
      <?php if (Auth::freeEditMode()): ?>
      <div class="free-edit-banner">
        <?= icon('unlock',17) ?> <b>الدخول الحر مفعّل</b>
        <span>يمكنك التعديل على الأيام المقفولة، وسيُعاد حساب الإقفالات المتأثرة تلقائيًا. ينتهي الوضع عند تسجيل الخروج.</span>
      </div>
      <?php endif; ?>
      <div class="workbar<?= $wdToday ? '' : ' warn' ?>">
        <span class="workbar-lbl"><?= icon('calendar',16) ?> تعمل بتاريخ:</span>
        <strong class="workbar-date"><?= e(dfmt($wd)) ?></strong>
        <?php if (!$wdToday): ?><span class="workbar-note">— مش تاريخ النهاردة، خلّي بالك!</span><?php endif; ?>
        <form action="<?= url('work-date') ?>" method="post" class="workbar-form no-confirm">
          <?= csrfField() ?>
          <input type="hidden" name="return" value="<?= e($cur) ?>">
          <input type="date" name="work_date" value="<?= e($wd) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="تغيير تاريخ العمل">
          <button class="btn sm" type="submit">تغيير</button>
        </form>
        <?php if (!$wdToday): ?>
        <form action="<?= url('work-date') ?>" method="post" class="no-confirm" style="margin:0">
          <?= csrfField() ?>
          <input type="hidden" name="return" value="<?= e($cur) ?>">
          <input type="hidden" name="work_date" value="<?= e(date('Y-m-d')) ?>">
          <button class="btn sm ghost" type="submit">ارجع لليوم</button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($m = flash('success')): $lastUndo=(int)($_SESSION['_last_undo_audit']??0);unset($_SESSION['_last_undo_audit']); ?><div class="flash success" role="status" aria-live="polite"><?= icon('check',18) ?> <?= e($m) ?><?php if($lastUndo): ?><form method="post" action="<?= url('audit/'.$lastUndo.'/undo') ?>" data-confirm="التراجع عن آخر تعديل واستعادة القيم السابقة؟" style="margin-inline-start:auto"><?= csrfField() ?><button class="btn ghost sm" type="submit">↶ تراجع</button></form><?php endif; ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="flash error" role="alert" aria-live="assertive"><?= icon('alert',18) ?> <?= e($m) ?></div><?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<style>
.free-edit-banner{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;padding:10px 14px;border:1px solid #df9b76;border-radius:11px;background:#fff0e8;color:#873b25}
.free-edit-banner b{font-weight:900}.free-edit-banner span{font-size:.82rem;font-weight:700}
</style>

<!-- لوحة البحث السريع (Command Palette) — تُفتح بـ Ctrl+K أو / -->
<div class="cmdk" id="cmdk" hidden aria-hidden="true">
  <div class="cmdk-backdrop" data-cmdk-close></div>
  <div class="cmdk-panel" role="dialog" aria-modal="true" aria-label="بحث سريع">
    <div class="cmdk-head">
      <span class="cmdk-ico"><?= icon('search', 20) ?></span>
      <input type="search" class="cmdk-input" id="cmdk-input" placeholder="ابحث أو انتقل… (بوليصة، إذن، حاوية، سائق، عميل) — أو اكتب اسم صفحة" autocomplete="off" spellcheck="false" aria-label="بحث">
      <kbd class="cmdk-esc">Esc</kbd>
    </div>
    <div class="cmdk-filter" id="cmdk-filter">
      <div class="cmdk-frow">
        <span class="cmdk-filter-lbl"><?= icon('calendar', 14) ?> التاريخ:</span>
        <div class="cmdk-chips" id="cmdk-chips">
          <button type="button" class="cmdk-chip active" data-range="all">الكل</button>
          <button type="button" class="cmdk-chip" data-range="today">اليوم</button>
          <button type="button" class="cmdk-chip" data-range="7d">آخر ٧ أيام</button>
          <button type="button" class="cmdk-chip" data-range="30d">آخر ٣٠ يوم</button>
          <button type="button" class="cmdk-chip" data-range="month">هذا الشهر</button>
          <button type="button" class="cmdk-chip" data-range="year">هذا العام</button>
          <button type="button" class="cmdk-chip" data-range="custom">مخصّص…</button>
        </div>
      </div>
      <div class="cmdk-custom" id="cmdk-custom" hidden>
        <label>من <input type="date" id="cmdk-from"></label>
        <label>إلى <input type="date" id="cmdk-to"></label>
      </div>
      <div class="cmdk-frow">
        <span class="cmdk-filter-lbl"><?= icon('folder', 14) ?> النوع:</span>
        <details class="cmdk-typedrop" id="cmdk-typedrop">
          <summary class="cmdk-type-btn"><b id="cmdk-cats-label">الكل</b><?= icon('chevron', 13, 'cmdk-type-caret') ?></summary>
          <div class="cmdk-type-menu cmdk-cats" id="cmdk-cats">
            <button type="button" class="cmdk-chip active" data-type="all"><?= icon('layers',13) ?> كل الأنواع</button>
            <?php foreach (\App\Models\Search::categories() as $ck => $cv): ?>
              <button type="button" class="cmdk-chip" data-type="<?= e($ck) ?>"><?= icon($cv['icon'], 13) ?> <?= e($cv['title']) ?></button>
            <?php endforeach; ?>
          </div>
        </details>
      </div>
    </div>
    <div class="cmdk-body" id="cmdk-body">
      <div class="cmdk-hint">اكتب اسم صفحة للتنقّل، أو حرفين للبحث في كل النظام…</div>
    </div>
    <div class="cmdk-foot">
      <span><kbd>↑</kbd><kbd>↓</kbd> تنقّل</span>
      <span><kbd>Enter</kbd> فتح</span>
      <span><kbd>Esc</kbd> إغلاق</span>
      <a class="cmdk-all" id="cmdk-all" href="<?= url('search') ?>">عرض كل النتائج ←</a>
    </div>
  </div>
</div>
<?php
/**
 * أوامر التنقّل السريع للوحة البحث (Ctrl+K): يكتب المستخدم اسم الصفحة أو الإجراء
 * فينتقل فورًا بلا استعلام قاعدة بيانات. مبنيّة من $nav نفسها (تبقى الروابط والمسار
 * الأساسي متزامنة) + إجراءات «إضافة جديد». الكلمات المرادفة تحسّن المطابقة العربية.
 */
$cmdSyn = [
    ''        => 'الرئيسية الهوم main home',
    'permits' => 'اذن اذونات شهادة شهادات اصدار اصدارات تصاريح',
    'bawalis' => 'بوليصة بوالص شحنة شحنات نولون',
    'tracking' => 'تتبع لوكيشن موقع مواقع خريطة سائق سواق سيارة رابط واتساب gps',
    'settlements' => 'سداد تسديد دفع بوالص',
    'takhlis' => 'تخليص جمرك جمركي',
    'closing' => 'تقفيل يومية قفل اليوم',
    'invoices'=> 'فاتورة فواتير اصدار',
    'statements' => 'كشف كشوف اصحاب البيان حساب',
    'debts'   => 'مديونية مديونيات دين ديون كشف ضمان',
    'payments'=> 'تحصيلات العملاء سداد دفعات قبض',
    'custody' => 'عهدة رصيد خزنة محفظة',
    'office'  => 'فيزا كارت كروت رصيد المكتب بنك',
    'reps'    => 'مندوب مناديب',
    'expenses'=> 'مصروف مصروفات صرف',
    'directory' => 'وكلاء عملاء شركات جهات دليل',
    'legacy'  => 'ارشيف بوالص قديمة',
    'reports' => 'تقرير تقارير احصائيات',
    'audit'   => 'سجل عمليات محذوفات حذف تدقيق',
    'settings'=> 'اعدادات ضبط تهيئة',
    'assistant' => 'مساعد ذكي شات ai',
];
$cmdNav = [];
foreach ($nav as [$seg, $label, $ic]) {
    if (str_starts_with((string)$seg, '__sec')) { continue; } // فاصل قسم
    $cmdNav[] = ['label' => $label, 'url' => url($seg), 'icon' => $ic ?: 'search',
                 'kw' => $cmdSyn[$seg] ?? '', 'kind' => 'nav'];
}
// إجراءات «إضافة جديد» (صفحات إنشاء موجودة فعلًا)
$cmdCreate = [
    ['➕ إذن / شهادة جديدة',  'permits/new',    'folder',        'اضافة انشاء جديد اذن شهادة'],
    ['➕ مصروف جديد',        'expenses/new',   'trending-down',  'اضافة انشاء جديد صرف'],
    ['➕ فاتورة جديدة',       'invoices/new',   'receipt',        'اضافة انشاء جديد فوترة'],
    ['➕ كشف حساب جديد',     'statements/new',  'file-text',      'اضافة انشاء جديد صاحب بيان'],
    ['➕ مندوب جديد',        'reps/new',        'user',           'اضافة انشاء جديد'],
];
foreach ($cmdCreate as [$label, $seg, $ic, $kw]) {
    $cmdNav[] = ['label' => $label, 'url' => url($seg), 'icon' => $ic, 'kw' => $kw, 'kind' => 'create'];
}
?>
<script>window.__SEARCH_API = <?= json_encode(url('search/api'), JSON_UNESCAPED_SLASHES) ?>; window.__SEARCH_PAGE = <?= json_encode(url('search'), JSON_UNESCAPED_SLASHES) ?>; window.__CMD_NAV = <?= json_encode($cmdNav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>; window.__NAME_SUGGEST_API = <?= json_encode(url('suggest/names'), JSON_UNESCAPED_SLASHES) ?>; window.__BILL_NUMBER_CHECK_API = <?= json_encode(url('bawalis/check-number'), JSON_UNESCAPED_SLASHES) ?>;</script>
<?php $appJs = \App\Core\App::basePath('public/assets/js/app.js'); $appJsV = is_file($appJs) ? filemtime($appJs) : 1; ?>
<script src="<?= url('assets/js/app.js') ?>?v=<?= $appJsV ?>"></script>
<?= $scripts ?? '' ?>
</body>
</html>
