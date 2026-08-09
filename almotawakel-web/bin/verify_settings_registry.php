<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص سجل الإعدادات المعرَّف — المرحلة ٠ من docs/19-settings-control-center-plan.md
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ليه الفاحص ده موجود — اقرأ الفقرة دي قبل ما تعدّل فيه
 * ═══════════════════════════════════════════════════════════════════════
 * جرد 2026-08-09 لقى **٢٤ مفتاح إعداد يتيم**: الكود بيقراهم والشاشة ما
 * بتعدّلهمش، وطريقهم الوحيد سكربت SSH أو هجرة — ومنهم `push_enabled`
 * (مفتاح إطفاء إشعارات الأجهزة كلها) اللي **مالوش كاتب في المشروع أصلاً**.
 * السبب الجذري إن المفتاح كان بيتعرّف **ضمنيًّا في مكان الاستدعاء**:
 * `(int) App::setting('track_expire_hours', 48)` وخلاص — فمحدّش يعرف إنه موجود.
 *
 * الفاحص ده بيقفل الباب ده للأبد: **أي مفتاح بيتقرا في `app/` ومش معرَّف في
 * `SettingsSchema` بيفشّل الفحص.** يعني إضافة إعداد جديد بقت لازم تمرّ على
 * السجل، والسجل هو اللي بيرسم الشاشة — فمستحيل يتولد يتيم تاني.
 *
 * ⚠️ **الفحص على السلوك مش على النص:** التحقّق من الأنواع بيتعمل بمناداة
 *    `normalize()` فعليًّا بقيم صالحة وفاسدة، والوصل بين `setSetting` والسجل
 *    بيتقاس **بكتابة حقيقية جوّه transaction** — مش بـ`grep` على اسم دالة.
 *
 * الأقسام:
 *   ① كل مفتاح مقروء في `app/` معرَّف في السجل
 *   ② كل مفتاح في القاعدة معروف (معرَّف أو تاريخي)
 *   ③ تعريفات السجل سليمة ومتماسكة
 *   ④ التحقّق من الأنواع بيقبل الصالح ويرفض الفاسد
 *   ⑤ الكتابة بتسيب أثرًا فعليًّا (سلوك، جوّه rollback)
 *   ⑥ الحرّاس: الحالة والمُدار والدور والمفتاح السرّي
 *   ⑦ العزل بالبناء — ولا ملف مالي بيعرف `settings_history`
 *
 * الكتابة كلها جوّه transaction + rollback.
 *
 * التشغيل:  php bin/verify_settings_registry.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\Settings;
use App\Core\SettingsSchema;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skp(string $m): void  { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }

/**
 * ⚠️ **إجبارية قبل أي فحص نصّي**: التعليق اللي بيوثّق القاعدة بيذكر أسماء
 *    مفاتيح وأمثلة، فالفحص كان بينجح أو يفشل كذبًا من التوثيق نفسه.
 */
function code_only(string $src): string
{
    $src = preg_replace('~<!--.*?-->~s', '', $src) ?? $src;
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

$root = dirname(__DIR__);

/** كل ملفات PHP تحت app/ (كود التشغيل — مش الهجرات ولا الفاحصات). */
$appFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') { $appFiles[] = $f->getPathname(); }
}
sort($appFiles);

echo "فحص سجل الإعدادات المعرَّف (docs/19 · المرحلة ٠)\n" . str_repeat('=', 66) . "\n";
echo 'ملفات التشغيل المفحوصة: ' . count($appFiles) . "\n";

// ═══════════════════════════════════════════════════════════ ①
sec('① كل مفتاح إعداد بيتقرا في app/ معرَّف في السجل');

$found = [];          // key => [ملفات]
$dynamic = [];        // استدعاءات بمفتاح متغيّر — بتتفحص يدويًّا

foreach ($appFiles as $file) {
    $rel  = str_replace($root . '/', '', $file);
    $code = code_only((string) file_get_contents($file));

    /* (أ) مفتاح حرفي: App::setting('x' · setSetting('x' · Settings::get('x'
       ⚠️ الـlookahead بيستثني **البادئة المتجمّعة** (`'ai_key_'.$slot`) — من
          غيره الماسح بيقرا `ai_key_` كأنه مفتاح كامل ويفشّل الفحص كذبًا. */
    if (preg_match_all(
        '~(?:App::setting|App::setSetting|Settings::get|Settings::set|Settings::setState|Settings::reset)\s*\(\s*[\'"]([a-z0-9_]+)[\'"](?!\s*\.)~',
        $code, $m)) {
        foreach ($m[1] as $k) { $found[$k][$rel] = true; }
    }

    // (ب) مفتاح من ثابت في نفس الملف: App::setting(self::BADGE_KEY
    if (preg_match_all(
        '~(?:App::setting|App::setSetting|Settings::get|Settings::set|Settings::setState)\s*\(\s*(?:self|static)::([A-Z0-9_]+)~',
        $code, $m2)) {
        foreach ($m2[1] as $constName) {
            if (preg_match('~const\s+' . preg_quote($constName, '~') . '\s*=\s*[\'"]([a-z0-9_]+)[\'"]~', $code, $cm)) {
                $found[$cm[1]][$rel] = true;
            } else {
                $dynamic[] = $rel . ' :: self::' . $constName;
            }
        }
    }

    // (ج) مفتاح متجمّع بالتسلسل: App::setting('ai_key_'.$slot
    if (preg_match_all(
        '~(?:App::setting|App::setSetting)\s*\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\.~', $code, $m3)) {
        foreach ($m3[1] as $prefix) { $dynamic[] = $rel . ' :: ' . $prefix . '…'; }
    }
}

$orphans = [];
foreach ($found as $key => $files) {
    if (!SettingsSchema::has($key) && !isset(SettingsSchema::LEGACY[$key])) {
        $orphans[$key] = implode(' · ', array_keys($files));
    }
}
if ($orphans) {
    foreach ($orphans as $key => $where) {
        bad("المفتاح «{$key}» بيتقرا في $where ومش معرَّف في SettingsSchema");
    }
} else {
    ok('كل المفاتيح الحرفية المقروءة معرَّفة (' . count($found) . ' مفتاح)');
}

/* المفاتيح المتجمّعة بالتسلسل (`ai_key_1`/`ai_key_2`) لازم تكون معرَّفة
   بأسمائها الكاملة — الفحص هنا على **النتيجة** مش على شكل الاستدعاء. */
foreach (['ai_key_1', 'ai_key_2'] as $k) {
    SettingsSchema::has($k) ? ok("المفتاح المتجمّع «{$k}» معرَّف بالكامل")
                            : bad("المفتاح المتجمّع «{$k}» مش معرَّف");
}
if ($dynamic) {
    echo '    (استدعاءات بمفتاح متغيّر: ' . implode(' | ', array_unique($dynamic)) . ")\n";
}

// ═══════════════════════════════════════════════════════════ ②
sec('② كل مفتاح موجود في القاعدة معروف');

$dbKeys = array_column(Database::all('SELECT setting_key FROM settings'), 'setting_key');
$unknown = [];
foreach ($dbKeys as $k) {
    if (!SettingsSchema::has($k) && !isset(SettingsSchema::LEGACY[$k])) { $unknown[] = $k; }
}
if ($unknown) {
    bad('مفاتيح في القاعدة بلا تعريف: ' . implode(' · ', $unknown));
} else {
    ok('كل مفاتيح القاعدة معروفة (' . count($dbKeys) . ' مفتاح · '
        . count(SettingsSchema::LEGACY) . ' منهم تاريخي مسموح)');
}

// ═══════════════════════════════════════════════════════════ ③
sec('③ تعريفات السجل سليمة');

$badDefs = [];
foreach (SettingsSchema::all() as $key => $raw) {
    $d = SettingsSchema::get($key);
    if (trim((string) $d['label']) === '')            { $badDefs[] = "$key: بلا تسمية"; }
    if (trim((string) $d['help']) === '')             { $badDefs[] = "$key: بلا شرح"; }
    if (!isset(SettingsSchema::SECTIONS[$d['section'] ?? ''])) { $badDefs[] = "$key: قسم مجهول"; }
    if (!$d['affects'])                               { $badDefs[] = "$key: بلا «بيأثّر على إيه»"; }
    if ($d['type'] === SettingsSchema::T_ENUM && !$d['options']) { $badDefs[] = "$key: enum بلا قيم"; }
    if (!in_array($d['role'], array_keys(\App\Core\Access::ROLES), true)) { $badDefs[] = "$key: دور مجهول"; }
    // المُدار والحالة لازم يقولوا مين بيكتبهم — رسالة الرفض بتوجّه بيها
    if (in_array($d['tier'], [SettingsSchema::TIER_STATE, SettingsSchema::TIER_MANAGED], true)
        && trim((string) $d['writer']) === '') { $badDefs[] = "$key: بلا writer"; }
    // دالة تصفير الكاش المعرَّفة لازم تكون موجودة فعلاً — وإلا الكتابة بترمي
    if ($d['refresh'] !== null && !is_callable($d['refresh'])) {
        $badDefs[] = "$key: دالة تصفير الكاش مش موجودة";
    }
}
if ($badDefs) {
    foreach (array_slice($badDefs, 0, 12) as $b) { bad($b); }
    if (count($badDefs) > 12) { echo '    (+' . (count($badDefs) - 12) . " تاني)\n"; }
} else {
    ok('كل التعريفات كاملة (' . count(SettingsSchema::all()) . ' مفتاح · '
        . count(SettingsSchema::SECTIONS) . ' قسم)');
}

/* ⚠️ قاعدة docs/19 §④: أي إعداد **مالي خطر** لازم يكون «سارٍ من تاريخ» —
   تغييره بأثر رجعي بيحرّك أرصدة تاريخية بالسكوت. الاستثناء الوحيد المقبول
   المفاتيح اللي **هي نفسها تاريخ** (خطوط الفصل) لأن تأريخها هو قيمتها. */
$notDated = [];
foreach (SettingsSchema::all() as $key => $raw) {
    $d = SettingsSchema::get($key);
    if ($d['tier'] !== SettingsSchema::TIER_DANGER) { continue; }
    if ($d['type'] === SettingsSchema::T_DATE) { continue; }
    if (!$d['dated'] && $d['type'] !== SettingsSchema::T_LIST) { $notDated[] = $key; }
}
$notDated ? bad('إعداد مالي خطر بلا «سارٍ من تاريخ»: ' . implode(' · ', $notDated))
          : ok('كل إعداد مالي خطر إما مؤرَّخ أو تاريخ بنفسه');

// ═══════════════════════════════════════════════════════════ ④
sec('④ التحقّق من الأنواع — بيقبل الصالح ويرفض الفاسد');

/** @return bool هل رمت؟ */
$throws = static function (string $key, $val): bool {
    try { SettingsSchema::normalize($key, $val); return false; }
    catch (\InvalidArgumentException $e) { return true; }
};

$cases = [
    // [مفتاح, قيمة, يرفض؟, وصف]
    ['vat_rate',               '0.14',        false, 'نسبة صالحة'],
    ['vat_rate',               'خمستاشر',      true,  'نسبة بحروف مرفوضة'],
    /* ⚠️ `2` **مقبولة عن قصد** = ٢٪. قاعدة «١٤ يعني ٠.١٤» بتقسم على ١٠٠ أي رقم
       فوق الواحد، فاللي بيترفض فعلاً هو اللي بيفضل فوق الواحد **بعد** القسمة. */
    ['vat_rate',               '2',           false, 'نسبة ٢٪ مقبولة'],
    ['vat_rate',               '150',         true,  'نسبة فوق ١٠٠٪ مرفوضة'],
    ['custody_bill_deduction', '150',         false, 'مبلغ صالح'],
    ['custody_bill_deduction', '-5',          true,  'مبلغ سالب مرفوض'],
    ['custody_bill_deduction', '999999',      true,  'مبلغ فوق السقف مرفوض'],
    ['track_expire_hours',     '48',          false, 'عدد صحيح صالح'],
    ['track_expire_hours',     '48.5',        true,  'كسر في خانة عدد صحيح مرفوض'],
    ['track_expire_hours',     '0',           true,  'صفر تحت الحد الأدنى مرفوض'],
    ['rep_custody_epoch',      '2026-08-09',  false, 'تاريخ صالح'],
    ['rep_custody_epoch',      '2026-02-31',  true,  'تاريخ مش موجود مرفوض'],
    ['rep_custody_epoch',      '09/08/2026',  true,  'صيغة تاريخ غلط مرفوضة'],
    ['rep_custody_epoch',      '',            false, 'تاريخ فاضي = مطفي'],
    ['ai_active',              '2',           false, 'قيمة enum مسموحة'],
    ['ai_active',              '3',           true,  'قيمة enum برّه القايمة مرفوضة'],
    ['permit_stages_config',   '{"a":1}',     false, 'JSON صالح'],
    ['permit_stages_config',   '{غلط',        true,  'JSON فاسد مرفوض'],
    ['push_enabled',           'on',          false, 'checkbox بتتحوّل 1'],
];
$typeFails = 0;
foreach ($cases as [$k, $v, $shouldThrow, $label]) {
    if ($throws($k, $v) !== $shouldThrow) { bad("التحقّق: $label"); $typeFails++; }
}
$typeFails === 0 ? ok('كل حالات التحقّق سليمة (' . count($cases) . ' حالة)') : null;

// تطبيع: 14 بتتحوّل 0.14 (نفس تسامح الفورم القديم بالحرف)
SettingsSchema::normalize('vat_rate', '14') === '0.14'
    ? ok('نسبة 14 بتتطبّع 0.14 — نفس تسامح الفورم القديم')
    : bad('تطبيع النسبة اتغيّر عن سلوك الفورم القديم');

// تطبيع: فواصل الآلاف بتتفكّ (خانات المبالغ بتبعت «1,500»)
SettingsSchema::normalize('custody_bill_deduction', '1,500') === '1500'
    ? ok('فواصل الآلاف بتتفكّ في خانة المبلغ')
    : bad('فواصل الآلاف ما بتتفكّش — الخانة بتبعت «1,500»');

// المفتاح المجهول بيترفض
$throws('مفتاح_مخترع_مش_موجود', 'x')
    ? ok('المفتاح المجهول بيترفض — الرفض هو الافتراضي')
    : bad('المفتاح المجهول عدّى');

// ═══════════════════════════════════════════════════════════ ⑤
sec('⑤ الكتابة بتسيب أثرًا فعليًّا (سلوك — جوّه rollback)');

if (!Settings::refreshHistoryReady()) {
    skp('جدول settings_history مش موجود — شغّل bin/migrate_v73.php الأول');
} else {
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        $probe = 'track_expire_hours';
        $orig  = (string) App::setting($probe, '48');

        $before = (int) Database::scalar(
            'SELECT COUNT(*) FROM settings_history WHERE setting_key = ?', [$probe]);

        // الكتابة الخام (زي أي هجرة قديمة) لازم تسيب أثرًا برضه
        App::setSetting($probe, '72');
        $after = (int) Database::scalar(
            'SELECT COUNT(*) FROM settings_history WHERE setting_key = ?', [$probe]);
        $after === $before + 1
            ? ok('App::setSetting بتسجّل صفًّا في السجل')
            : bad("App::setSetting ما سجّلتش (قبل $before · بعد $after)");

        $row = Database::row(
            'SELECT * FROM settings_history WHERE setting_key = ? ORDER BY id DESC LIMIT 1', [$probe]);
        ((string) $row['old_value'] === $orig && (string) $row['new_value'] === '72')
            ? ok('السجل بيمسك القيمة قبل وبعد صح')
            : bad('القيمة قبل/بعد في السجل غلط: «' . $row['old_value'] . '» → «' . $row['new_value'] . '»');

        // كتابة بنفس القيمة = مفيش صف جديد (تغيير وهمي ما بيتسجّلش)
        $b2 = (int) Database::scalar('SELECT COUNT(*) FROM settings_history WHERE setting_key = ?', [$probe]);
        App::setSetting($probe, '72');
        $a2 = (int) Database::scalar('SELECT COUNT(*) FROM settings_history WHERE setting_key = ?', [$probe]);
        $a2 === $b2 ? ok('الكتابة بنفس القيمة ما بتسجّلش صفًّا')
                    : bad('تغيير وهمي اتسجّل في السجل');

        // ⚠️ **نبضة العامل الخلفي ما بتدخلش السجل** — وإلا ١٤٤٠ صف في اليوم
        //    كانوا هيدفنوا التغييرات الحقيقية.
        $b3 = (int) Database::scalar('SELECT COUNT(*) FROM settings_history');
        Settings::setState('push_worker_last_run', (string) time());
        $a3 = (int) Database::scalar('SELECT COUNT(*) FROM settings_history');
        $a3 === $b3 ? ok('نبضة الحالة ما بتدخلش السجل')
                    : bad('نبضة الحالة اتسجّلت — السجل هيتدفن');

        // القيمة الفاسدة عبر البوابة بترفض **قبل** ما توصل القاعدة
        $rowsBefore = (int) Database::scalar('SELECT COUNT(*) FROM settings_history');
        $rejected = false;
        try { Settings::set('track_expire_hours', 'ساعتين'); }
        catch (\InvalidArgumentException $e) { $rejected = true; }
        $rowsAfter = (int) Database::scalar('SELECT COUNT(*) FROM settings_history');
        ($rejected && $rowsAfter === $rowsBefore)
            ? ok('القيمة الفاسدة بترفض قبل ما تلمس القاعدة')
            : bad('القيمة الفاسدة عدّت من البوابة');

        // Settings::set بترجّع false لو القيمة هي هي
        Settings::set('track_expire_hours', '72') === false
            ? ok('set بترجّع false على تغيير وهمي')
            : bad('set بترجّع true على تغيير وهمي');

        // الرجوع بضغطة بيرجّع القيمة السابقة فعلاً
        $hist = Settings::history($probe, 1);
        if ($hist) {
            Settings::revert((int) $hist[0]['id']);
            (string) App::setting($probe, '') === (string) $hist[0]['old_value']
                ? ok('الرجوع بضغطة بيرجّع القيمة السابقة')
                : bad('الرجوع ما رجّعش القيمة الصح');
        } else {
            bad('السجل فاضي بعد الكتابة');
        }
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        App::flushSettingsCache();   // الكاش اتلوّث بقيم اتلغت — لازم يتصفّر
    }
}

// ═══════════════════════════════════════════════════════════ ⑥
sec('⑥ الحرّاس');

$denied = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

$denied(static fn() => Settings::set('push_worker_last_run', '123'))
    ? ok('مفتاح الحالة بيترفض من البوابة العامة')
    : bad('مفتاح الحالة اتكتب من البوابة العامة');

$denied(static fn() => Settings::set('rep_portal_maint', '1'))
    ? ok('المفتاح المُدار بيترفض ويوجّه لشاشته')
    : bad('المفتاح المُدار اتكتب من البوابة العامة');

$denied(static fn() => Settings::setState('vat_rate', '0.2'))
    ? ok('setState بترفض مفتاح مش حالة')
    : bad('setState كتبت على إعداد عادي');

$denied(static fn() => Settings::set('مفتاح_مخترع', 'x'))
    ? ok('البوابة بترفض أي مفتاح مش في السجل')
    : bad('البوابة كتبت مفتاحًا مش في السجل');

// ترتيب الأدوار: المدير ما يقدرش يمسّ إعداد المالك، والعكس بيقدر
(!Settings::roleAllows('manager', 'owner') && Settings::roleAllows('owner', 'manager')
 && !Settings::roleAllows('read_only', 'manager'))
    ? ok('ترتيب الأدوار صح — الرفض هو الافتراضي')
    : bad('ترتيب الأدوار غلط');

// المفتاح السرّي بيتقنّع في السجل
$secretKeys = [];
foreach (SettingsSchema::all() as $k => $d) {
    if (($d['type'] ?? '') === SettingsSchema::T_SECRET) { $secretKeys[] = $k; }
}
$secretKeys ? ok('مفاتيح سرّية محروسة بالتقنيع: ' . count($secretKeys))
            : bad('مفيش أي مفتاح متعلَّم إنه سرّي');

if (Settings::historyReady()) {
    $leak = (int) Database::scalar(
        'SELECT COUNT(*) FROM settings_history WHERE setting_key IN ('
        . implode(',', array_fill(0, count($secretKeys), '?')) . ")
           AND ((old_value <> '' AND old_value IS NOT NULL AND old_value NOT LIKE '••••%')
             OR (new_value <> '' AND new_value IS NOT NULL AND new_value NOT LIKE '••••%'))",
        $secretKeys);
    $leak === 0 ? ok('ولا مفتاح سرّي متخزّن صريحًا في السجل')
                : bad("فيه $leak صف بمفتاح سرّي غير مقنّع");
}

// ═══════════════════════════════════════════════════════════ ⑦
sec('⑦ العزل بالبناء — ولا ملف مالي بيعرف settings_history');

/** ⚠️ الحارس ده لازم يفضل: `settings_history` سجل قراءة، وأي `JOIN` عليه من
 *  كود الخزنة معناه إنه بقى جزءًا من حساب — وده بيكسر قاعدة ① بالسكوت. */
$financial = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/CompanySafe.php',
    'app/Models/Expense.php', 'app/Models/RepCustody.php', 'app/Models/DailyReview.php',
    'app/Models/Debt.php', 'app/Models/Metrics.php',
];
$breach = [];
foreach ($financial as $rel) {
    $p = $root . '/' . $rel;
    if (!is_file($p)) { continue; }
    if (str_contains(code_only((string) file_get_contents($p)), 'settings_history')) { $breach[] = $rel; }
}
$breach ? bad('ملف مالي بيذكر settings_history: ' . implode(' · ', $breach))
        : ok('ولا ملف مالي بيعرف الجدول (' . count($financial) . ' ملف مفحوص)');

// ═══════════════════════════════════════════════════════════ الخلاصة
echo "\n" . str_repeat('=', 66) . "\n";
echo "النتيجة: $pass ناجح · $fail فاشل · $skip متخطّي\n";
if ($fail > 0) {
    echo "\n⚠️ فيه مفتاح إعداد بلا تعريف أو حارس مكسور — راجع الفوق.\n";
    exit(1);
}
echo "✓ سجل الإعدادات سليم.\n";
exit(0);
