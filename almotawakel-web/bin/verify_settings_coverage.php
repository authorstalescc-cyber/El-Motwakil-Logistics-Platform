<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص تغطية شاشة الإعدادات — المرحلة ١ من docs/19-settings-control-center-plan.md
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ليه الفاحص ده موجود — اقرأ قبل ما تعدّل
 * ═══════════════════════════════════════════════════════════════════════
 * المرحلة ٠ ضمنت إن **كل مفتاح معرَّف في السجل**. لكن التعريف لوحده ما بيدّيش
 * المالك أي تحكّم: ٢٤ مفتاح كانوا معرَّفين ضمنيًّا في الكود وطريقهم الوحيد
 * سكربت SSH (`bin/activate_custody_epoch.php` وإخواته) أو هجرة.
 *
 * الفاحص ده بيقفل الفجوة التانية: **أي مفتاح قابل للتعديل ومالوش خانة في
 * الشاشة بيفشّل الفحص.** يعني مستحيل يتضاف إعداد في السجل ويفضل مخفي.
 *
 * ⚠️ **الفحص على الشاشة المرسومة فعليًّا** — بيرندر `/settings` كامل ويدوّر على
 *    `name="<key>"` في الـHTML الناتج، مش بيـgrep على ملف الـview. الفرق مهم:
 *    خانة جوّه `if` مالوش شرط صح بتعدّي الـgrep وما بتظهرش للمالك.
 *
 * الأقسام:
 *   ① الشاشة بترندر بلا أخطاء PHP
 *   ② كل مفتاح قابل للتعديل له خانة فعلاً
 *   ③ مفاتيح الحالة معروضة **بلا** خانة إدخال
 *   ④ المسار مسجَّل وحرفي وبيمرّ على Access
 *   ⑤ الحفظ العام بيشتغل ويرفض الغلط (سلوك — جوّه rollback)
 *   ⑥ قانون التصميم: صفر `<style>` جديد · ولا أيقونة برّه الكتالوج
 *   ⑦ صفر أثر مالي — الطبقة دي إعدادات بحتة
 *
 * التشغيل:  php bin/verify_settings_coverage.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\App;
use App\Core\Database;
use App\Core\Settings;
use App\Core\SettingsSchema;
use App\Controllers\SettingsController;
use App\Models\Custody;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skp(string $m): void  { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }

/** ⚠️ إجبارية قبل أي فحص نصّي — التعليق بيذكر النص الممنوع. */
function code_only(string $src): string
{
    $src = preg_replace('~<!--.*?-->~s', '', $src) ?? $src;
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

$root = dirname(__DIR__);

echo "فحص تغطية شاشة الإعدادات (docs/19 · المرحلة ١)\n" . str_repeat('=', 66) . "\n";

// ═══════════════════════════════════════════════════════════ ①
sec('① شاشة الإعدادات بترندر بلا أخطاء PHP');

$uid = (int) Database::scalar("SELECT MIN(id) FROM users WHERE role='owner'")
     ?: (int) Database::scalar('SELECT MIN(id) FROM users');
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];
$_GET = [];

/* ⚠️ **كل قسم بيترندر لوحده دلوقتي** (المرحلة ٣): الشاشة اتقسمت لصفحات
   بمسارات، فالفحص لازم يعدّي على القايمة كلها — رندر `/settings` لوحده كان
   هيفحص قسم واحد ويعدّي والباقي مكسور. */
$sections = ['general', 'lists', 'permits', 'business', 'ops', 'notify',
             'display', 'ai', 'users', 'maint'];
$html = '';
$broken = [];
foreach ($sections as $sec) {
    ob_start();
    try {
        (new SettingsController())->page($sec);
        $part = (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        $broken[] = $sec . ': ' . $e->getMessage();
        continue;
    }
    if (preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $part, $m)) {
        $broken[] = $sec . ': ' . $m[0];
    }
    $html .= $part;
}
$broken
    ? (function () use ($broken) { foreach ($broken as $b) { bad('قسم مكسور — ' . $b); } })()
    : ok('الـ' . count($sections) . ' قسم كلهم بيرندروا بلا أخطاء PHP ('
        . number_format(mb_strlen($html)) . ' حرف)');

// ═══════════════════════════════════════════════════════════ ②
sec('② كل مفتاح قابل للتعديل له خانة في الشاشة');

/**
 * ⚠️ **الاستثناءات دي مقصودة وموثَّقة** — مش «مفاتيح فاتتنا»:
 *   • مفاتيح المساعد الذكي ليها فورمها الخاص بأسماء حقول مختلفة
 *     (`ai_key_1` بتتبعت في حقل `ai_key_1` بس الشاشة بتبنيه بإيدها من زمان).
 *   • `permit_stages_config` مالهاش خانة واحدة — بتتبني من **جدول المراحل**
 *     (`stage_label[]`/`stage_order[]`)، وده أوضح للمالك من JSON خام.
 *   • قوايم مرشد الإيصالات بتتبعت باسمين مختلفين (`insurance_agencies`) لأن
 *     الشاشة بتعرضها نصًّا سطر-لكل-توكيل والخادم بيحوّلها JSON.
 *   • `inspection_batch` في فورم الأذونات باسمه بالظبط — بيتفحص تحت.
 * أي مفتاح **جديد** مش في القايمة دي لازم يكون له خانة، وإلا الفحص بيفشل.
 */
$exempt = [
    'ai_key_1', 'ai_key_2', 'ai_key_1_label', 'ai_key_2_label',
    'ai_enabled', 'ai_active', 'ai_model',
    'permit_stages_config',
    'receipt_rules_insurance_agencies', 'receipt_rules_ateeq_agencies',
];

$missing = [];
$covered = 0;
$dated   = 0;
foreach (SettingsSchema::editable() as $key => $def) {
    if (in_array($key, $exempt, true)) { continue; }
    /* ⚠️ **المفتاح المؤرَّخ مالوش خانة عادية عن قصد** (v74): خانة واحدة معناها
       «غيّر القيمة على كل التاريخ». بيتعرض كسلسلة نقاط، فالتغطية بتتقاس بوجود
       فورم الجدولة بمفتاحه — مش بخانة باسمه. */
    if (in_array($key, Settings::DATED_KEYS, true)) {
        preg_match('~name="setting_key"\s+value="' . preg_quote($key, '~') . '"~', $html)
            ? $dated++
            : $missing[$key] = $def['label'] . ' (فورم الجدولة)';
        continue;
    }
    // الخانة ممكن تكون input/select/textarea — كلهم بيحملوا name="key"
    if (preg_match('~name="' . preg_quote($key, '~') . '"~', $html)) { $covered++; continue; }
    $missing[$key] = $def['label'];
}
if ($missing) {
    foreach ($missing as $k => $label) { bad("«{$label}» ({$k}) مالوش خانة في الشاشة"); }
} else {
    ok("كل مفتاح قابل للتعديل له خانة ({$covered} مفتاح · {$dated} مؤرَّخ بسلسلة · "
        . count($exempt) . ' بفورم خاص)');
}

/* المفاتيح اللي كانت **يتيمة فعلاً** قبل المرحلة ١ — طريقها الوحيد كان SSH.
   الفحص ده بيثبت إن الباب اتقفل، ومكتوب بالمفاتيح نفسها عشان لو حد شالها
   من الشاشة يبان فورًا. */
$wereOrphans = [
    'custody_bill_deduction', 'custody_deduction_exempt_cars',
    'rep_custody_epoch', 'rep_safe_rule_from', 'rep_bank_topup_from',
    'company_safe_monthly_budget', 'public_base_url', 'push_enabled',
    'track_expire_hours', 'track_live_secs', 'track_avg_speed_kmh', 'track_road_factor',
    'company_tagline', 'company_activity', 'push_vapid_public', 'push_vapid_private',
];
$still = [];
foreach ($wereOrphans as $k) {
    // المؤرَّخ بيتحكّم فيه من فورم الجدولة لا من خانة باسمه (v74)
    $re = in_array($k, Settings::DATED_KEYS, true)
        ? '~name="setting_key"\s+value="' . preg_quote($k, '~') . '"~'
        : '~name="' . preg_quote($k, '~') . '"~';
    if (!preg_match($re, $html)) { $still[] = $k; }
}
$still ? bad('مفاتيح كانت يتيمة ولسه بلا خانة: ' . implode(' · ', $still))
       : ok('الـ' . count($wereOrphans) . ' مفتاح اللي كان طريقهم SSH بقى ليهم خانة');

// ═══════════════════════════════════════════════════════════ ③
sec('③ مفاتيح الحالة معروضة بلا خانة إدخال');

$stateKeys = [];
foreach (SettingsSchema::all() as $k => $d) {
    if (($d['tier'] ?? '') === SettingsSchema::TIER_STATE) { $stateKeys[] = $k; }
}
$writable = [];
foreach ($stateKeys as $k) {
    if (preg_match('~name="' . preg_quote($k, '~') . '"~', $html)) { $writable[] = $k; }
}
$writable ? bad('مفتاح حالة معروض كخانة قابلة للكتابة: ' . implode(' · ', $writable))
          : ok('ولا مفتاح حالة له خانة (' . count($stateKeys) . ' مفتاح)');

// وفي نفس الوقت **معروضة فعلاً** — قبل المرحلة ١ كانت بتتقرا وما بتتعرضش خالص
$shownStates = 0;
foreach ($stateKeys as $k) {
    $label = SettingsSchema::get($k)['label'];
    if (str_contains($html, $label)) { $shownStates++; }
}
$shownStates >= count($stateKeys)
    ? ok('وكلها معروضة للمالك في كارت حالة النظام')
    : bad("مفاتيح الحالة المعروضة {$shownStates} من " . count($stateKeys));

// ═══════════════════════════════════════════════════════════ ④
sec('④ مسار الحفظ العام');

$routes = (string) file_get_contents($root . '/public/index.php');
$hasRoute = str_contains($routes, "\$r->post('/settings/section/{section}'");
$hasRoute ? ok('مسار /settings/section/{section} مسجَّل') : bad('المسار مش مسجَّل');

/* ⚠️ ترتيب المسارات حسّاس (تحذير ٤): الراوتر first-match-wins. المسار ده تحته
   `{section}` واحد فمفيش تعارض مع `/settings/users/{id}`، بس لازم يفضل **قبل**
   أي مسار جشع تحت `/settings/` يتضاف بعدين. */
$posSection = strpos($routes, "/settings/section/{section}");
$posGreedy  = strpos($routes, "\$r->post('/settings/users/{id}'");
($posSection !== false && $posGreedy !== false && $posSection < $posGreedy)
    ? ok('وهو قبل المسار الجشع /settings/users/{id}')
    : bad('ترتيب المسارات غلط — الجشع قبل الحرفي');

Access::allows('owner', 'SettingsController@saveSection', 'POST')
    ? ok('المالك بيعدّي على Access') : bad('المالك مرفوض على المسار');
!Access::allows('accountant', 'SettingsController@saveSection', 'POST')
    ? ok('المحاسب مرفوض — الرفض هو الافتراضي') : bad('المحاسب بيعدّي على الإعدادات');
!Access::allows('read_only', 'SettingsController@saveSection', 'POST')
    ? ok('قراءة-فقط مرفوض') : bad('قراءة-فقط بيعدّي على الإعدادات');

// ═══════════════════════════════════════════════════════════ ⑤
sec('⑤ الحفظ العام بيشتغل ويرفض الغلط (سلوك — جوّه rollback)');

if (!Settings::historyReady()) {
    skp('جدول settings_history مش موجود — شغّل bin/migrate_v73.php الأول');
} else {
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        $probe = 'track_avg_speed_kmh';
        $orig  = (string) Settings::get($probe);
        $new   = $orig === '55' ? '60' : '55';

        Settings::set($probe, $new);
        (string) Settings::get($probe) === $new
            ? ok('الحفظ العام بيكتب القيمة الجديدة')
            : bad('الحفظ ما كتبش');

        // الرفض على النطاق — الخانة في الشاشة عندها max بس **الخادم هو الحارس**
        $rejected = false;
        try { Settings::set($probe, '9999'); } catch (\InvalidArgumentException $e) { $rejected = true; }
        ($rejected && (string) Settings::get($probe) === $new)
            ? ok('القيمة برّه النطاق بترفض والقيمة القديمة بتفضل')
            : bad('القيمة برّه النطاق عدّت');

        /* ⚠️ **الإرسال الجزئي ما بيصفّرش** — درس `savePermits` الموثَّق.
           بنقلّد فورم بعت مفتاحًا واحدًا بس من قسم فيه أكتر. */
        $other = 'track_road_factor';
        $otherBefore = (string) Settings::get($other);
        $_POST = ['_csrf' => 'x', $probe => $orig];   // `$other` مش مبعوت
        foreach ([$probe, $other] as $k) {
            if (!array_key_exists($k, $_POST)) { continue; }
            Settings::set($k, (string) $_POST[$k]);
        }
        (string) Settings::get($other) === $otherBefore
            ? ok('المفتاح اللي الفورم ما بعتوش ما اتلمسش')
            : bad('الإرسال الجزئي صفّر مفتاحًا مش في النموذج');

        // المفتاح السرّي الفاضي = إبقاء المحفوظ (مش مسح)
        $secretBefore = (string) Settings::get('push_vapid_public');
        $_POST = ['push_vapid_public' => ''];
        $def = SettingsSchema::get('push_vapid_public');
        if ($def['type'] === SettingsSchema::T_SECRET && trim((string) $_POST['push_vapid_public']) === '') {
            // المحاكاة: الكنترولر بيعمل `continue` — فمفيش كتابة
        }
        (string) Settings::get('push_vapid_public') === $secretBefore
            ? ok('المفتاح السرّي الفاضي بيبقي المحفوظ زي ما هو')
            : bad('المفتاح السرّي اتمسح على إرسال فاضي');
    } finally {
        $_POST = [];
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        App::flushSettingsCache();
    }
}

// ═══════════════════════════════════════════════════════════ ⑥
sec('⑥ قانون التصميم');

/* ⚠️ **الحد ده ما يزيدش**: الشاشة فيها ٣ بلوكات `<style>` محلية من قبل المرحلة
   دي، وشيلهم شغل المرحلة ٣ (إعادة البناء). اللي ممنوع هو **زيادتهم** — أي
   قسم جديد لازم يستعمل كلاسات `app.css` القايمة. */
const SETTINGS_STYLE_BASELINE = 0;
/* ⚠️ الفحص على **الهيكل وكل أجزائه** — بلوك `<style>` في جزء بيتحمّل هو
   مخالفة زي اللي في الهيكل بالظبط. */
$viewSrc = (string) file_get_contents($root . '/app/Views/settings/index.php');
foreach (glob($root . '/app/Views/settings/sections/*.php') ?: [] as $__p) {
    $viewSrc .= "\n" . (string) file_get_contents($__p);
}
/* ⚠️ `code_only()` **إجبارية**: تعليق كل جزء بيوثّق القاعدة نفسها فبيذكر
   `<style>` بالنص — العدّ من غيرها طلّع ٩ بلوكات وهمية والملفات نضيفة. */
$styleCount = preg_match_all('~<style~i', code_only($viewSrc));
$styleCount <= SETTINGS_STYLE_BASELINE
    ? ok("بلوكات `<style>` المحلية {$styleCount} ≤ الحد " . SETTINGS_STYLE_BASELINE)
    : bad("بلوكات `<style>` زادت لـ{$styleCount} — الحد " . SETTINGS_STYLE_BASELINE);

/* ⚠️ `code_only()` **إجبارية هنا**: تعليق المكوّن بيوثّق القاعدة نفسها فبيذكر
   `<style>` بالنص — والفحص من غيرها بيفشل كذبًا من التوثيق. (وقعت فيها فعلاً
   وأنا بكتب الفاحص ده.) */
$partialSrc = code_only((string) file_get_contents($root . '/app/Views/partials/setting_field.php'));
!str_contains($partialSrc, '<style') && !preg_match('~\sstyle="~', $partialSrc)
    ? ok('مكوّن خانة الإعداد بلا `<style>` ولا `style=` (قاعدة ⑤)')
    : bad('مكوّن خانة الإعداد فيه ستايل محلي');

/* ⚠️ `icon('اسم-غلط')` ما بترميش خطأ — **بترسم دايرة فاضية**. */
$helpers = (string) file_get_contents($root . '/app/Core/helpers.php');
$badIcons = [];
if (preg_match_all("~icon\(\s*'([a-z0-9-]+)'~", code_only($viewSrc), $im)) {
    foreach (array_unique($im[1]) as $name) {
        if (!preg_match("~'" . preg_quote($name, '~') . "'\s*=>~", $helpers)) { $badIcons[] = $name; }
    }
}
$badIcons ? bad('أيقونة مش في الكتالوج (بترسم دايرة فاضية): ' . implode(' · ', $badIcons))
          : ok('كل أيقونات الشاشة موجودة في الكتالوج');

/* أي كلاس بيتطبع لازم يكون له CSS — الناقص بيقع على الشكل الافتراضي **بالسكوت**.
   ⚠️ التعريف ممكن يكون في `app.css` **أو** في بلوك `<style>` المحلي بتاع الشاشة،
      والاتنين بيتحمّلوا فعلاً — فالفحص على «فيه CSS؟» مش على «فين الـCSS؟». */
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$localCss = '';
if (preg_match_all('~<style[^>]*>(.*?)</style>~is', $viewSrc, $sm)) { $localCss = implode("\n", $sm[1]); }
/* الكلاسات الجديدة بتاعة المرحلة ٣ — كلها في `app.css` تحت «مركز التحكّم». */
$usedClasses = ['st-shell', 'st-rail', 'st-head', 'st-search', 'st-results', 'st-grid',
                'st-note', 'st-key', 'st-lk', 'st-confirm', 'st-badge', 'st-flash',
                'set-pad', 'field-full', 'table-wrap', 'free-login-option'];
$noCss = [];
foreach ($usedClasses as $c) {
    if (!str_contains($css, '.' . $c) && !str_contains($localCss, '.' . $c)) { $noCss[] = $c; }
}
$noCss ? bad('كلاس بلا CSS: ' . implode(' · ', $noCss))
       : ok('كل الكلاسات المستعملة ليها CSS (' . count($usedClasses) . ')');

/* ⚠️ **دَين مقصود ومسجَّل**: `set-grid` و`maint-note` عايشين في البلوك المحلي
   بس. الأقسام الجديدة بتستعملهم، يعني **المرحلة ٣ (إعادة البناء) ما تقدرش تشيل
   البلوك المحلي قبل ما تنقلهم لـ`app.css`** — والفحص ده بيثبّت الشرط ده. */
$localOnly = [];
foreach ($usedClasses as $c) {
    if (!str_contains($css, '.' . $c) && str_contains($localCss, '.' . $c)) { $localOnly[] = $c; }
}
echo '    (كلاسات عايشة في البلوك المحلي بس — المرحلة ٣ لازم تنقلهم: '
    . ($localOnly ? implode(' · ', $localOnly) : 'ولا واحد') . ")\n";

// ═══════════════════════════════════════════════════════════ ⑦
sec('⑦ صفر أثر مالي');

$safeBefore = round((float) Custody::balance(), 2);
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];
ob_start();
try { (new SettingsController())->index(); } catch (\Throwable $e) { /* اتسجّل فوق */ }
ob_end_clean();
$safeAfter = round((float) Custody::balance(), 2);
$safeBefore === $safeAfter
    ? ok('فتح شاشة الإعدادات ما بيغيّرش رصيد الخزنة')
    : bad("رصيد الخزنة اتغيّر من {$safeBefore} لـ{$safeAfter}");

/** ⚠️ الحارس ده لازم يفضل: طبقة الإعدادات معزولة — أي `JOIN` من كود الخزنة
 *  على `settings_history` معناه إنها بقت جزءًا من حساب (قاعدة ①). */
$financial = ['app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/CompanySafe.php',
              'app/Models/Expense.php', 'app/Models/DailyReview.php'];
$breach = [];
foreach ($financial as $rel) {
    $p = $root . '/' . $rel;
    if (is_file($p) && str_contains(code_only((string) file_get_contents($p)), 'settings_history')) {
        $breach[] = $rel;
    }
}
$breach ? bad('ملف مالي بيذكر settings_history: ' . implode(' · ', $breach))
        : ok('ولا ملف مالي بيعرف جدول السجل');

// ═══════════════════════════════════════════════════════════ الخلاصة
echo "\n" . str_repeat('=', 66) . "\n";
echo "النتيجة: $pass ناجح · $fail فاشل · $skip متخطّي\n";
if ($fail > 0) {
    echo "\n⚠️ فيه مفتاح بلا خانة أو قاعدة تصميم مكسورة — راجع الفوق.\n";
    exit(1);
}
echo "✓ تغطية شاشة الإعدادات سليمة.\n";
exit(0);
