<?php use App\Core\Auth; use App\Core\App; use App\Core\Access;
$cur = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = App::baseUrl();
if ($base && str_starts_with($cur, $base)) { $cur = substr($cur, strlen($base)); }
$cur = '/' . trim($cur, '/');
$isActive = function(string $seg) use ($cur): string {
    if ($seg === '' ) return $cur === '/' ? 'active' : '';
    return str_starts_with($cur, '/' . $seg) ? 'active' : '';
};
/* ══════════════════════════════════════════════════════════════════════════
   القائمة الجانبية — المرحلة ٣
   الأسماء بتوصف **شغل المستخدم** مش أسماء الجداول. أهم اللي اتغيّر:
     • «رصيد العهدة» ← **«خزنة المكتب»**  و«رصيد المكتب (الفيزا)» ← **«كروت الفيزا»**
       (كلمة «عهدة» كان لها 3 معاني: خزنة المكتب / عهدة المندوب / عهدة السائق)
     • «المناديب» ← **«رواتب وحسابات المناديب»** عشان تتفرق عن «عهدة المناديب»
     • «الإصدارات (أذونات/شهادات)» ← **«الأذونات والشهادات»** (القوس كان دليل اسم فاشل)
     • «مراجعة بنود المناديب» اتشالت كسطر مستقل — بقت شارة على «عهدة المناديب»
     • «المراقبة الأمنية» اتضافت (كانت مدفونة في تبويب جوّه سجل العمليات)
     • «التخليص» و«المولدات» نزلوا تحت «السجلات» (قرار المالك: مالهمش استخدام حاليًا)
   الأقسام بقت بـ**الطرف اللي بتتعامل معاه** بدل «مالية/فوترة» المتداخلين.

   كل بند: [المسار, الاسم, الأيقونة, معالِج الصلاحية, مفتاح الشارة]
   • معالِج الصلاحية → `Access::allows()` بتقرّر يظهر ولا لأ (الرفض هو الافتراضي).
   • مفتاح الشارة → بيتملّى من `/notifications/ping` في المتصفح، **صفر تكلفة
     على الخادم**، ونفس مصدر رقم الجرس فمستحيل الاتنين يختلفوا.
   ══════════════════════════════════════════════════════════════════════════ */
$navRaw = [
    ['__sec', 'الرئيسية', null, null, null],
    ['',            'لوحة التحكم',   'dashboard',     'DashboardController@index',   null],
    ['assistant',   'المساعد الذكي', 'sparkles',      'AssistantController@index',   null],

    ['__sec', 'الشغل اليومي', null, null, null],
    ['permits',     'الأذونات والشهادات', 'folder',   'PermitController@index',      null],
    ['bawalis',     'البوالص',       'truck',         'CrudController@index',        null],
    ['cars',        'سيارات المكتب', 'truck',         'OfficeCarController@index',   null],
    ['tracking',    'تتبع الشحنات',  'map-pin',       'TrackController@index',       'track_stale'],
    ['closing',     'التقفيل اليومي','calculator',    'DailyClosingController@index','closing_open'],

    ['__sec', 'حسابات العملاء', null, null, null],
    ['invoices',    'فواتير العملاء','receipt',       'InvoiceController@index',     'ready_invoice'],
    ['payments',    'تحصيلات العملاء','wallet',       'PaymentController@index',     null],
    ['debts',       'المديونيات',    'credit-card',   'DebtController@index',        null],

    ['__sec', 'أصحاب البيان', null, null, null],
    ['statements',  'كشوف الحساب',  'file-text',     'OwnerStatementController@index', null],
    ['settlements', 'السداد',        'banknote',      'SettlementController@index',  null],

    ['__sec', 'الخزنة والمصروفات', null, null, null],
    // ⚠️ خزنة الشركة قبل خزنة المكتب عن قصد — هي الأكبر واللي بتغذّيها (v66).
    ['company-safe', 'خزنة الشركة',  'home',          'CompanySafeController@index', null],
    ['custody',     'خزنة المكتب',   'briefcase',     'CustodyController@index',     null],
    ['office',      'كروت الفيزا',   'credit-card',   'OfficeController@index',      null],
    ['expenses',    'المصروفات',     'trending-down', 'ExpenseController@index',     'receipts_missing'],

    ['__sec', 'المناديب', null, null, null],
    ['rep-custody', 'عهدة المناديب', 'briefcase',     'RepCustodyController@index',  'rep_pending'],
    ['reps',        'رواتب وحسابات المناديب', 'user', 'RepController@index',         null],

    ['__sec', 'السجلات والإدارة', null, null, null],
    ['directory',   'دليل الأطراف',  'users',         'DirectoryController@index',   null],
    ['legacy',      'أرشيف البوالص القديمة', 'inbox', 'LegacyController@index',      null],
    ['takhlis',     'التخليص',       'clipboard',     'TakhlisController@index',     null],
    ['generators',  'المولدات',      'cpu',           'GeneratorsController@index',  null],
    ['ops',         'تقارير التشغيل','package',       'OpsReportController@index',   null],
    ['reports',     'التقارير',      'bar-chart',     'ReportsController@index',     null],
    ['audit',       'سجل العمليات',  'clipboard',     'AuditController@index',       null],
    ['push',        'إشعارات التطبيق','bell',         'PushController@index',        null],
    ['quality',     'تنظيف البيانات','clipboard',     'QualityController@index',     'data_quality'],
    ['security',    'المراقبة الأمنية','alert',       'SecurityController@index',    'failed_logins'],
    ['settings',    'الإعدادات',     'settings',      'SettingsController@index',    'weak_pins'],
];

/* فلترة بالدور: **الرفض هو الافتراضي** — البند يظهر بس لو السياسة المركزية
   بتسمح لدور المستخدم يفتح صفحته. ده بيخفي «المراقبة الأمنية» عن غير المالك
   و«الإعدادات» عن غير المالك/المدير تلقائيًّا، ومن غير ما نكتب أي شرط بالإيد. */
$role = Auth::role();
$nav = [];
foreach ($navRaw as $item) {
    [$seg, $label, $ic, $handler, $badge] = $item;
    if ($ic === null) { $nav[] = $item; continue; }              // فاصل قسم
    if ($handler !== null && !Access::allows($role, $handler, 'GET')) { continue; }
    $nav[] = $item;
}
/* نشيل أي عنوان قسم فضي بعد الفلترة (مثلاً لو الدور مشافش أي بند تحته) */
$clean = [];
for ($i = 0, $n = count($nav); $i < $n; $i++) {
    $isSection = $nav[$i][2] === null;
    $nextIsLink = isset($nav[$i + 1]) && $nav[$i + 1][2] !== null;
    if ($isSection && !$nextIsLink) { continue; }
    $clean[] = $nav[$i];
}
$nav = $clean;
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '') ?> — <?= e(App::setting('company_name','شركة المتوكل')) ?></title>
<?php $appCss = \App\Core\App::basePath('public/assets/css/app.css'); $appCssV = is_file($appCss) ? filemtime($appCss) : 1; ?>
<?php /* ⚠️ سمة العرض بتتحط على <html> **قبل أول رسمة** — لو اتأخرت لبعد
         تحميل app.js الصفحة بتلمع أبيض ثم تغمق (flash of wrong theme). */ ?>
<script>(function(){try{var t=localStorage.getItem('almotawakel:theme');
  if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=<?= $appCssV ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%2315355B'/><text x='50' y='68' font-size='54' text-anchor='middle' fill='%23C9A227' font-family='Arial' font-weight='bold'>م</text></svg>">
<?php /* تثبيت النظام على الموبايل + شاشة البدء — نفس الملف اللي بتستخدمه بوابة المناديب */ ?>
<?php $pwaApp = 'office'; require __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<a class="skip-link" href="#main-content">تخطَّ إلى المحتوى الرئيسي</a>
<div class="app">
  <?php
  /* ═══ القائمة: شريط أيقونات دائم + لوحة قسم تفتح عند الضغط (2026-08-09)
     ⚠️ **السبب مقيس مش ذوق** (بلاغ المالك «تصميمها كبير وسيئ»): القائمة كانت
        **٢٨ رابط + ٧ عناوين = ٣٥ صف بطول ~١٦٦٦px** في عمود واحد — يعني على
        شاشة كبيرة **١٣ بند مخفي** وعلى لابتوب **٢٠**. قائمة تنقّل بتتطلب
        تمرير عشان تتنقّل بيها هي أسوأ حالة ممكنة. الشريط بيوري **٧ أقسام**
        بس، والقسم بيفتح شاشاته في لوحة جنبه.
     ⚠️ **ماركب واحد للشاشة والموبايل**: نفس الـDOM، والموبايل بيعرض اللوحات
        مفتوحة جوّه الدرج (CSS بس). ماركبان = واحد بيتنسى مع أول تعديل.
     ⚠️ **الإظهار بكلاس مش `hidden`**: `[hidden]{display:none!important}`
        بتغلب أي `display` من الـCSS (مصيدة موثَّقة) فالموبايل مكانش هيقدر
        يعرضها. */
  $secIcon = ['الرئيسية' => 'home', 'الشغل اليومي' => 'truck',
              'حسابات العملاء' => 'receipt', 'أصحاب البيان' => 'file-text',
              'الخزنة والمصروفات' => 'briefcase', 'المناديب' => 'users',
              'السجلات والإدارة' => 'layers'];
  // تجميع القايمة المسطّحة لأقسام — نفس `$nav` بعد فلترة الصلاحيات بالظبط
  $groups = [];
  foreach ($nav as $item) {
      if ($item[2] === null) { $groups[] = ['label' => $item[1], 'items' => []]; continue; }
      if (!$groups) { $groups[] = ['label' => 'الرئيسية', 'items' => []]; }
      $groups[count($groups) - 1]['items'][] = $item;
  }
  $groups = array_values(array_filter($groups, static fn (array $g): bool => $g['items'] !== []));
  ?>
  <aside class="sidebar" id="primary-sidebar" aria-label="التنقل الرئيسي">
    <div class="brand">
      <a href="<?= url('') ?>" class="brand-logo" aria-label="<?= e(App::setting('company_name','شركة المتوكل')) ?>">
        <img src="<?= url('assets/img/logo.png') ?>" alt="<?= e(App::setting('company_name','شركة المتوكل')) ?>" class="logo-full">
        <span class="logo-mark">م</span>
      </a>
    </div>
    <nav class="nav" id="primary-nav" aria-label="أقسام النظام">
      <?php foreach ($groups as $gi => $g):
        $flyId = 'nav-fly-' . $gi;
        // القسم «نشط» لو الصفحة المفتوحة جوّاه — عشان الشريط يقول انت فين
        $secActive = false;
        foreach ($g['items'] as $it) { if ($isActive($it[0]) !== '') { $secActive = true; break; } }
      ?>
        <div class="nav-sec-wrap<?= $secActive ? ' on' : '' ?>">
          <button type="button" class="nav-sec<?= $secActive ? ' active' : '' ?>"
                  aria-expanded="false" aria-controls="<?= e($flyId) ?>"
                  data-fly="<?= e($flyId) ?>">
            <span class="ic"><?= icon($secIcon[$g['label']] ?? 'folder', 21) ?></span>
            <span class="lbl"><?= e($g['label']) ?></span>
            <?php /* نقطة تنبيه للقسم — بتبان لو أي رابط جوّاه عليه رقم.
                     `:has()` بتقراها من الـDOM فمفيش أي منطق JS جديد. */ ?>
            <span class="sec-dot" aria-hidden="true"></span>
            <span class="nav-tip" role="tooltip"><?= e($g['label']) ?></span>
          </button>
          <div class="nav-fly" id="<?= e($flyId) ?>">
            <div class="section"><?= e($g['label']) ?></div>
            <?php foreach ($g['items'] as [$seg, $label, $ic, $handler, $badge]): $act = $isActive($seg); ?>
              <a href="<?= url($seg) ?>" class="<?= $act ?>"<?= $act ? ' aria-current="page"' : '' ?>
                 <?= $badge ? 'data-badge="' . e($badge) . '"' : '' ?>>
                <span class="ic"><?= icon($ic, 18) ?></span>
                <span class="lbl"><?= e($label) ?></span>
                <?php /* الشارة بتتملّى من /notifications/ping — نفس آلية زي ما هي */ ?>
                <?php if ($badge): ?><span class="nav-cnt" hidden aria-hidden="true"></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
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
      <?php /* تبديل السمة: تلقائي (يتبع الجهاز) ← فاتح ← غامق. الاختيار في
               localStorage، والسمة بتتحط في <head> فوق قبل أول رسمة. */ ?>
      <button type="button" class="btn ghost sm icon-btn theme-btn" id="themeBtn"
              title="وضع العرض: تلقائي" aria-label="غيّر وضع العرض">
        <span class="th-ico th-auto"><?= icon('monitor', 17) ?></span>
        <span class="th-ico th-light"><?= icon('sun', 17) ?></span>
        <span class="th-ico th-dark"><?= icon('moon', 17) ?></span>
      </button>
      <?php /* تثبيت النظام كتطبيق — بيفضل مخفي، و`_pwa_head.php` بيوريه بس لو
               التثبيت متاح فعلاً (أندرويد/كروم) أو الجهاز آيفون (خطوات يدوية). */ ?>
      <button type="button" class="btn ghost sm icon-btn pwa-install" id="pwaInstallBtn" hidden
              title="ثبّت النظام كتطبيق على موبايلك" aria-label="ثبّت النظام كتطبيق على موبايلك">
        <?= icon('download', 17) ?><span class="lbl-hide">ثبّت التطبيق</span>
      </button>
      <?php /* مركز الإشعارات — الجرس بيترسم فاضي والعدّاد بيتملّى من /notifications/ping
               بعد التحميل، فما بيزوّدش أي تكلفة على رندر أي صفحة. */ ?>
      <div class="nc" id="notifCenter">
        <button type="button" class="nc-btn" id="notifBtn" aria-haspopup="true" aria-expanded="false"
                aria-controls="notifPanel" title="الإشعارات" aria-label="الإشعارات">
          <?= icon('bell', 20) ?>
          <span class="nc-badge" id="notifBadge" aria-hidden="true"></span>
        </button>
        <div class="nc-panel" id="notifPanel" hidden role="dialog" aria-modal="false" aria-label="الإشعارات">
          <div class="nc-head"><?= icon('bell', 17) ?> <span>الإشعارات</span><span class="nc-count" id="notifCount"></span></div>
          <div class="nc-list" id="notifList">
            <div class="nc-empty"><?= icon('hourglass', 24) ?><b>لحظة…</b><span>بنجيب آخر المستجدات.</span></div>
          </div>
          <div class="nc-foot">
            <button type="button" class="nc-mark" id="notifMark">تعليم الكل كمقروء</button>
            <a class="nc-all" href="<?= url('notifications') ?>">كل الإشعارات <?= icon('chevron', 14) ?></a>
          </div>
        </div>
      </div>
      <div class="user" title="<?= e((Auth::user() ?? '') . ' — ' . Auth::roleLabel()) ?>">
        <span class="av"><?= e(mb_substr(Auth::user() ?? 'م', 0, 1)) ?></span>
        <span class="user-name"><?= e(Auth::user() ?? '') ?> · <?= e(Auth::roleLabel()) ?></span>
      </div>
      <form action="<?= url('logout') ?>" method="post" class="no-confirm" style="margin:0">
        <?= csrfField() ?>
        <button class="btn ghost sm icon-btn" type="submit" title="تسجيل الخروج"><?= icon('log-out', 16) ?><span class="lbl-hide">خروج</span></button>
      </form>
    </header>

    <main class="content" id="main-content" tabindex="-1">
      <?php
      /* ══════════════════════════════════════════════════════════════════════
         Breadcrumb — المرحلة ٥
         النظام كان **بلا أي breadcrumb** في ٨١ شاشة، و١١ شاشة مالهاش ولا
         رابط للأعلى خالص. بدل ما نعدّل كل شاشة، بنشتقّه هنا من **خريطة
         القائمة نفسها** (`$navRaw` فوق) + المسار الحالي: مكان واحد بيغطّي
         كل الشاشات تلقائيًّا ومستحيل يتفرّع عن القائمة.
         ══════════════════════════════════════════════════════════════════════ */
      $crumbs = [];
      if ($cur !== '/') {
          $curTrim = ltrim($cur, '/');
          $bestSeg = ''; $bestLabel = ''; $bestSection = '';
          $section = '';
          foreach ($navRaw as [$seg, $label, $ic, $handler, $badge]) {
              if ($ic === null) { $section = $label; continue; }
              if ($seg === '') { continue; }
              // أطول تطابق بادئة يكسب: «rep-custody/inbox» قبل «rep-custody»
              if (($curTrim === $seg || str_starts_with($curTrim, $seg . '/') || str_starts_with($curTrim, $seg . '?'))
                  && strlen($seg) > strlen($bestSeg)) {
                  $bestSeg = $seg; $bestLabel = $label; $bestSection = $section;
              }
          }
          if ($bestSeg !== '') {
              if ($bestSection !== '' && $bestSection !== 'الرئيسية') { $crumbs[] = [$bestSection, null]; }
              $onIndex = ($curTrim === $bestSeg);
              $crumbs[] = [$bestLabel, $onIndex ? null : url($bestSeg)];
              // آخر فتّة = عنوان الصفحة الحالية لو احنا جوّه صفحة فرعية
              if (!$onIndex && ($title ?? '') !== '' && $title !== $bestLabel) {
                  $crumbs[] = [$title, null];
              }
          } elseif (($title ?? '') !== '') {
              $crumbs[] = [$title, null];
          }
      }
      ?>
      <?php if ($crumbs): ?>
      <nav class="crumbs" aria-label="مسار التنقّل">
        <a href="<?= url('') ?>"><?= icon('dashboard',14) ?> الرئيسية</a>
        <?php foreach ($crumbs as [$cLabel, $cUrl]): ?>
          <span class="crumb-sep" aria-hidden="true">›</span>
          <?php if ($cUrl): ?><a href="<?= e($cUrl) ?>"><?= e($cLabel) ?></a>
          <?php else: ?><span aria-current="page"><?= e($cLabel) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>
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
      <?php
      /* رسائل الخادم بقت **توستات طايرة** بدل بانرات ثابتة كانت بتزقّ المحتوى
         لتحت وما فيهاش إغلاق ولا اختفاء (المرحلة ١). `flash_all()` بتسحب
         الطابور كله فمفيش رسالة بتضيع لما يتبعت أكتر من واحدة في نفس الطلب.
         زرار «تراجع» انتقل جوّه التوست نفسه بدل ما يكون في البانر. */
      $__lastUndo = (int) ($_SESSION['_last_undo_audit'] ?? 0);
      unset($_SESSION['_last_undo_audit']);
      $__kindMap = ['success' => 'ok', 'error' => 'err', 'warning' => 'warn', 'info' => 'info'];
      $__toasts = [];
      foreach (flash_all() as $__f) {
          $__t = ['msg' => $__f['msg'], 'kind' => $__kindMap[$__f['type']] ?? 'info'];
          if ($__t['kind'] === 'ok' && $__lastUndo > 0) {
              $__t['action'] = [
                  'label'   => '↶ تراجع',
                  'url'     => url('audit/' . $__lastUndo . '/undo'),
                  'csrf'    => Auth::csrf(),
                  'confirm' => 'التراجع عن آخر تعديل واستعادة القيم السابقة؟',
              ];
              $__lastUndo = 0;   // زرار التراجع لأول رسالة نجاح بس
          }
          $__toasts[] = $__t;
      }
      ?>
      <?= $content ?>
    </main>
  </div>
</div>
<style>
.free-edit-banner{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;padding:10px 14px;border:1px solid #df9b76;border-radius:var(--radius-2);background:#fff0e8;color:#873b25}
.free-edit-banner b{font-weight:700}.free-edit-banner span{font-size:var(--fs-2);font-weight:500}
</style>

<!-- لوحة البحث السريع (Command Palette) — تُفتح بـ Ctrl+K أو / -->
<div class="cmdk" id="cmdk" hidden aria-hidden="true">
  <div class="cmdk-backdrop" data-cmdk-close></div>
  <div class="cmdk-panel" role="dialog" aria-modal="true" aria-label="بحث سريع">
    <div class="cmdk-head">
      <span class="cmdk-ico"><?= icon('search', 20) ?></span>
      <input type="search" class="cmdk-input" id="cmdk-input" placeholder="ابحث أو انتقل…" autocomplete="off" spellcheck="false" aria-label="بحث">
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
/* ⚠️ الأسماء القديمة **لازم تفضل هنا** بعد إعادة التسمية (المرحلة ٣): المالك
   متعوّد يدوّر بـ«رصيد العهدة» و«رصيد المكتب» و«إصدارات»، ولو شالناها من
   المرادفات هيكتب الاسم القديم في Ctrl+K وما يلاقيش حاجة. */
$cmdSyn = [
    ''        => 'الرئيسية الهوم main home',
    'permits' => 'اذن اذونات شهادة شهادات اصدار اصدارات تصاريح الاصدارات',
    'bawalis' => 'بوليصة بوالص شحنة شحنات نولون',
    'tracking' => 'تتبع لوكيشن موقع مواقع خريطة سائق سواق سيارة رابط واتساب gps',
    'settlements' => 'سداد تسديد دفع بوالص سداد البوالص اصحاب البيان',
    'takhlis' => 'تخليص جمرك جمركي',
    'generators' => 'مولدات مولد',
    'closing' => 'تقفيل يومية قفل اليوم',
    'invoices'=> 'فاتورة فواتير اصدار اصدار الفواتير فواتير العملاء',
    'statements' => 'كشف كشوف اصحاب البيان حساب',
    'debts'   => 'مديونية مديونيات دين ديون كشف ضمان',
    'payments'=> 'تحصيلات العملاء سداد دفعات قبض',
    'custody' => 'عهدة رصيد خزنة محفظة رصيد العهدة خزنه المكتب درج',
    'company-safe' => 'خزنة الشركة الخزنه الكبيرة الرئيسية بنك ايداع تحويل للمكتب تغذية',
    'office'  => 'فيزا كارت كروت رصيد المكتب بنك كروت الفيزا',
    'cars'    => 'سيارة سيارات عربية عربيات لوري تريلا اسطول سولار بنزين اصلاح كاوتش اداء السيارة',
    'reps'    => 'مندوب مناديب مرتب مرتبات رواتب سلفة خصم',
    'rep-custody' => 'عهدة مناديب مندوب سلفة تنزيل تسوية مراجعة بنود صندوق',
    'expenses'=> 'مصروف مصروفات صرف',
    'directory' => 'وكلاء عملاء شركات جهات دليل',
    'legacy'  => 'ارشيف بوالص قديمة',
    'reports' => 'تقرير تقارير احصائيات',
    'ops'     => 'تقرير تشغيل حاويات موانئ ميناء شركات عهد عهدة يومي اسبوعي شهري تداول',
    'audit'   => 'سجل عمليات محذوفات حذف تدقيق',
    'security'=> 'امن امان مراقبة دخول محاولات اختراق',
    'settings'=> 'اعدادات ضبط تهيئة نسخة احتياطية باك اب',
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
<!-- كومة التنبيهات الطايرة — مكوّن واحد بيخدم رسائل الخادم ورسائل الـAJAX -->
<?php /* ⚠️ الكومة نفسها **بلا `aria-live`** عن قصد: لما تكون منطقة معلَنة واحدة
         بسياسة `polite`، سياسة الأب دي بتغلب على `role="alert"` بتاع أي تنبيه
         جوّاها — فرسالة الخطأ كانت بتتعلن «بأدب» بدل ما تقاطع، يعني المستخدم
         ممكن يكمّل شغل على غلطة ما سمعهاش. الإعلان بقى من منطقتين مخصّصتين
         مخفيتين بصريًّا تحت، وكل تنبيه بيتقري في اللي تخصّه. */ ?>
<div class="toast-stack" id="toastStack" role="region" aria-label="تنبيهات"></div>
<div class="sr-only" id="toastLivePolite" role="status" aria-live="polite"></div>
<div class="sr-only" id="toastLiveAssertive" role="alert" aria-live="assertive"></div>

<?php /* ═══ نافذة التفاصيل — **نسخة واحدة عامة لكل الشاشات** (المرحلة 10) ═══
         ⚠️ قاعدة المالك الدائمة: «ممنوع رقم أصمّ» — أي رقم أو كارت معروض لازم
            يُضغط ويفتح التفاصيل اللي اتحسب منها.
         ⚠️ **ممنوع تعمل نافذة محلية في أي شاشة.** الداشبورد كانت عاملة واحدة
            جوّه ملفها، فباقي النظام ما قدرش يستخدمها — نفس مصيدة سكربت «إظهار
            الأرصدة» اللي كان محليًّا في `office/index.php` فالأرصدة فضلت مقنّعة
            للأبد في شاشتين تانيين. `verify_design_system.php` بيفشل لو رجعت.
         بتستخدم `activateDialog()` بتاعة app.js فبتاخد حبس التركيز — البوب-أب
         القديم ما كانش بيعمل كده والكيبورد كان بيهرب وراه. */ ?>
<div class="d-modal" id="uiDrill" hidden role="dialog" aria-modal="true" aria-labelledby="uiDrillTitle">
  <div class="d-modal-back" data-close></div>
  <div class="d-modal-card">
    <div class="d-modal-head">
      <h3 id="uiDrillTitle"></h3>
      <button type="button" class="d-modal-x" data-close aria-label="إغلاق"><?= icon('x', 14) ?></button>
    </div>
    <div class="d-modal-body" id="uiDrillBody"></div>
  </div>
</div>
<script>window.__SEARCH_API = <?= js_json(url('search/api')) ?>; window.__SEARCH_PAGE = <?= js_json(url('search')) ?>; window.__CMD_NAV = <?= js_json($cmdNav) ?>; window.__NAME_SUGGEST_API = <?= js_json(url('suggest/names')) ?>; window.__BILL_NUMBER_CHECK_API = <?= js_json(url('bawalis/check-number')) ?>; window.__OFFICE_CAR_CHECK_API = <?= js_json(url('cars/is-office')) ?>; window.__TOASTS = <?= js_json($__toasts) ?>; window.__NOTIF_PING = <?= js_json(url('notifications/ping')) ?>; window.__NOTIF_READ = <?= js_json(url('notifications/read/__ID__')) ?>; window.__NOTIF_READ_ALL = <?= js_json(url('notifications/read-all')) ?>; window.__NOTIF_SNOOZE = <?= js_json(url('notifications/snooze/__ID__')) ?>; window.__NOTIF_PREFS = <?= js_json(url('notifications/prefs')) ?>; window.__CSRF = <?= js_json(Auth::csrf()) ?>;</script>
<?php $appJs = \App\Core\App::basePath('public/assets/js/app.js'); $appJsV = is_file($appJs) ? filemtime($appJs) : 1; ?>
<?php $rsJs = \App\Core\App::basePath('public/assets/js/resume-scroll.js'); $rsJsV = is_file($rsJs) ? filemtime($rsJs) : 1; ?>
<?php /* الرجوع لنفس المكان بعد الحفظ — ملف مشترك مع بوابة المندوب (مش جوّه app.js
         لأن /m ما بتحمّلوش). defer عشان يشتغل والـDOM جاهز. */ ?>
<script defer src="<?= url('assets/js/resume-scroll.js') ?>?v=<?= $rsJsV ?>"></script>
<?php $laJs = \App\Core\App::basePath('public/assets/js/lookup-autofill.js'); ?>
<?php /* شركة الشحن = العميل تلقائيًا — ملف مشترك بين المكتب وبوابة المندوب
         (`/m` ما بتحمّلش app.js — نفس سبب `resume-scroll.js`). */ ?>
<script defer src="<?= url('assets/js/lookup-autofill.js') ?>?v=<?= is_file($laJs) ? filemtime($laJs) : 1 ?>"></script>
<script defer src="<?= url('assets/js/app.js') ?>?v=<?= $appJsV ?>"></script>
<?= $scripts ?? '' ?>
</body>
</html>
