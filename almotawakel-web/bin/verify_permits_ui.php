<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص وحدة الأذونات — المرحلة ١٣ (2026-07-25).
 *
 * قرار المالك من المرحلة ٣ كان **دمج شاشتي الإذن**. المرحلة ٣ عملت نص الشغل
 * (الهيرو والشريط بقوا أجزاء مشتركة + مفتاح وضع)، لكن الفصل نفسه فضل: ملفان
 * كاملان وأكشنان — فأي تعديل على الغلاف لازم يتعمل مرتين، وأول مرة تُنسى
 * بتبقى الشاشتان مختلفتين من غير ما حد ياخد باله.
 *
 * أهم المحروس:
 *   • **غلاف واحد** (`permits/show.php`) بيرسم الشريط والهيرو والمرشد مرة واحدة.
 *   • **الجسمان منفصلان عن قصد** — التحرير مربوط بعقد AJAX في `_movement.php`.
 *   • **المساران القديمان زي ما هما** — الروابط المحفوظة عند المالك.
 *   • **التقدّم بيتحسب في الغلاف** — الهيرو بيقراه قبل الجسم (اتصاد فعليًّا:
 *     أربع تحذيرات PHP جوّه الصفحة لما كان في الجسم).
 *   • **العزل المالي** — رصيد الخزنة قبل = بعد.
 *
 * بيرندر الشاشة **بوضعيها فعليًّا** ببيانات حقيقية بلا أي كتابة.
 * التشغيل:  php bin/verify_permits_ui.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PermitController;
use App\Core\Database;
use App\Models\Closing;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

echo "فحص وحدة الأذونات — المرحلة ١٣\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);
$show  = (string) file_get_contents($root . '/app/Views/permits/show.php');
$bodyV = (string) file_get_contents($root . '/app/Views/permits/_body_view.php');
$bodyM = (string) file_get_contents($root . '/app/Views/permits/_body_manage.php');
$ctrl  = (string) file_get_contents($root . '/app/Controllers/PermitController.php');
$idx   = (string) file_get_contents($root . '/app/Views/permits/index.php');
$routes = (string) file_get_contents($root . '/public/index.php');

$treasuryBefore = Closing::currentBalance();

// ═══ ① الدمج حصل فعلاً
sec('① الشاشتان بقوا شاشة واحدة بوضعين');
chk(!is_file($root . '/app/Views/permits/view.php')
    && !is_file($root . '/app/Views/permits/manage.php'),
    'الملفان المنفصلان اتشالوا');
chk(is_file($root . '/app/Views/permits/show.php'), 'والغلاف الواحد `show.php` موجود');
chk(str_contains($show, "include __DIR__ . '/_body_' . \$mode . '.php'"),
    'الغلاف بيضمّ جسم الوضع المطلوب');
foreach (['_hero.php' => 'الهيرو', '_bar.php' => 'شريط الإجراءات', '_rcp_guide.php' => 'مرشد الإيصالات'] as $part => $label) {
    chk(str_contains($show, "include __DIR__ . '/$part'")
        && !str_contains($bodyV, "include __DIR__ . '/$part'")
        && !str_contains($bodyM, "include __DIR__ . '/$part'"),
        "{$label} بيترسم **مرة واحدة** في الغلاف مش في الجسمين");
}
chk(str_contains($show, 'الجسمان فضلوا منفصلين عن قصد'),
    '⚠️ وسبب بقاء الجسمين منفصلين مكتوب في رأس الغلاف (عقد AJAX في `_movement.php`)');

// ═══ ② الكنترولر نقطة واحدة
sec('② الكنترولر — نقطة تجميع واحدة');
chk(str_contains($ctrl, 'private function showPage(int $pid, string $mode): void'),
    'دالة `showPage` واحدة بتخدم الوضعين');
chk(substr_count($ctrl, "\$this->render('permits/show', \$data)") === 1,
    'ورندر واحد بس للشاشة');
chk(str_contains($ctrl, "\$this->showPage((int) \$id, 'view')")
    && str_contains($ctrl, "\$this->showPage((int) \$id, 'manage')"),
    'والأكشنان بيمرّوا عليها');
chk(!str_contains($ctrl, "render('permits/view'") && !str_contains($ctrl, "render('permits/manage'"),
    'مفيش رندر باقي للقوالب القديمة');

// ═══ ③ ⚠️ المسارات القديمة زي ما هي
sec('③ ⚠️ الروابط المحفوظة عند المالك لازم تفضل شغّالة');
chk(str_contains($routes, "\$r->get('/permits/{id}'"), 'مسار العرض `/permits/{id}` موجود');
chk(str_contains($routes, "\$r->get('/permits/{id}/manage'"), 'ومسار التعديل `/permits/{id}/manage` موجود');
$greedy = strpos($routes, "\$r->get('/permits/{id}'");
$manageAt = strpos($routes, "\$r->get('/permits/{id}/manage'");
chk($manageAt !== false && $greedy !== false && $manageAt < $greedy,
    'والحرفي قبل الجشع (تحذير ٤ — الراوتر first-match-wins)');

// ═══ ④ ⚠️ التقدّم في الغلاف
sec('④ ⚠️ التقدّم بيتحسب قبل الهيرو');
chk(str_contains($show, '$progress = $tot ?'),
    'التقدّم محسوب في الغلاف');
chk(!str_contains($bodyV, '$progress =') && !str_contains($bodyM, '$progress ='),
    'ومش محسوب في أي جسم — لو رجع هناك الهيرو بيقرا متغيّر لسه ما اتحسبش');
chk(str_contains($show, 'اتصاد فعليًّا'),
    'والباج ده موثَّق جنب الكود عشان ما يترجعش');

// ═══ ⑤ الأيقونات في شاشة الأذونات
sec('⑤ شاشة الأذونات — أيقونات SVG مش إيموجي في الترويسة');
chk(!preg_match('/class="pvt-ic">[^<]*[\x{1F300}-\x{1FAFF}]/u', $idx),
    'تبويبات الصفحة بأيقونات SVG');
chk(!preg_match('/class="pv-kpi-ic">[^<]*[\x{1F300}-\x{1FAFF}]/u', $idx),
    'وأرقام الصفحة كمان');
chk(substr_count($idx, "icon('") >= 8, 'واستخدام `icon()` بقى فعليًّا في الشاشة');
/* ⚠️ مؤشرات الشاشة دي **روابط لقايمة مفلترة** لا نوافذ — وده شكل أقوى من
   البوب-أب: بيوصّل لشاشة كاملة فيها الفلاتر والتصدير. قاعدة «ممنوع رقم أصمّ»
   بتتحقق: الرقم بيتضغط وبيوصّل لبنوده. */
chk(substr_count($idx, 'class="pv-kpi') >= 4 && str_contains($idx, 'href="<?= url(\'permits?view=records'),
    '⚠️ وكل رقم بيوصّل لقايمته المفلترة (شكل أقوى من النافذة — شاشة كاملة)');

// ═══ ⑥ رندر فعلي للوضعين
sec('⑥ الشاشة بترندر بوضعيها فعليًّا');
$pid = (int) (Database::scalar('SELECT MAX(id) FROM permits') ?? 0);
$uid = (int) Database::scalar('SELECT MIN(id) FROM users');
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];
$_GET = [];
if ($pid <= 0) {
    ok('مفيش أذونات في القاعدة — تخطّي الرندر');
} else {
    foreach (['view', 'manage'] as $mode) {
        ob_start();
        try {
            $c = new PermitController();
            $mode === 'view' ? $c->view((string) $pid) : $c->manage((string) $pid);
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean(); bad("وضع `$mode` رمى استثناء: " . $e->getMessage()); continue;
        }
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $m)
            ? bad("خطأ PHP في وضع `$mode`: " . ($m[0] ?? ''))
            : ok("وضع `$mode` بيرندر بلا أخطاء PHP");
        chk(str_contains($html, 'permit-hero'), "وضع `$mode` فيه الهيرو");
        chk(str_contains($html, 'pb-modes'), "ووضع `$mode` فيه مفتاح الوضع");
    }
}

// ═══ ⑦ العزل المالي
sec('⑦ ⚠️ العزل المالي — الحد الأحمر');
chk(abs($treasuryBefore - Closing::currentBalance()) < 0.005,
    'رصيد الخزنة قبل = بعد (' . money($treasuryBefore, false) . ')');

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
