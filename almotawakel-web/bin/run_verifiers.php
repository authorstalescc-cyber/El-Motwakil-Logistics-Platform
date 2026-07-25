<?php
declare(strict_types=1);


// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Unified runner for bin/verify_*.php.
 *
 * The default profile never opens a database connection. Database-backed
 * verifiers are only run when an explicitly named, isolated test database is
 * supplied through ALMOTAWAKEL_TEST_DB.
 */

const VERIFY_RUNNER_USAGE_ERROR = 2;

$catalog = [
    'verify_access_matrix.php'    => ['impact' => 'none',       'self_guard' => false, 'note' => 'مصفوفة route/action × roles بنيوية بلا قاعدة'],
    'verify_accessibility_foundation.php' => ['impact' => 'none', 'self_guard' => false, 'note' => 'أساس الوصولية والكاش لشاشتي التخطيط والدخول بلا قاعدة'],
    'verify_closing_redesign.php' => ['impact' => 'read',       'self_guard' => false, 'note' => 'يرندر من بيانات القاعدة دون كتابة مقصودة'],
    'verify_components.php'       => ['impact' => 'read',       'self_guard' => false, 'note' => 'يرندر مكوّنات app/Views/partials في الذاكرة ويقرأ رصيد الخزنة للمقارنة'],
    'verify_forms_feedback.php'   => ['impact' => 'none',       'self_guard' => false, 'note' => 'فحص بنيوي وحالة جلسة بلا قاعدة'],
    'verify_installer.php'        => ['impact' => 'isolated',   'self_guard' => true,  'note' => 'يفحص سجل الترحيلات ويرفض قاعدة العمل'],
    'verify_legacy_merge.php'     => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'قراءة وبروفة كتابة داخل rollback'],
    'verify_mobile.php'           => ['impact' => 'read',       'self_guard' => false, 'note' => 'فحص تجربة الموبايل على الملفات ويقرأ رصيد الخزنة للمقارنة'],
    'verify_movement_capacity.php'=> ['impact' => 'rollback',   'self_guard' => false, 'note' => 'fixtures داخل transaction ثم rollback'],
    'verify_owner_hub.php'        => ['impact' => 'read',       'self_guard' => false, 'note' => 'دمج شاشات أصحاب البيان — رندر الشريط ويقرأ رصيد الخزنة للمقارنة'],
    'verify_permit_bulk.php'      => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'fixtures داخل transaction ثم rollback'],
    'verify_performance_foundation.php' => ['impact' => 'none', 'self_guard' => false, 'note' => 'حارس قياس الأداء وredaction بلا قاعدة'],
    'verify_pwa.php'              => ['impact' => 'none',       'self_guard' => false, 'note' => 'تثبيت النظام على الهاتف وشاشة البدء — ملفات ورندر بلا قاعدة'],
    'verify_quick_entries.php'    => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'fixtures داخل transaction ثم rollback'],
    'verify_receipt_transfer.php' => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'fixtures داخل transaction ثم rollback'],
    'verify_receipts_complete.php'=> ['impact' => 'rollback',   'self_guard' => false, 'note' => 'قراءة ثم تحديث عينة داخل rollback'],
    'verify_rep_dashboard.php'    => ['impact' => 'read',       'self_guard' => false, 'note' => 'قراءة لوحة عهدة المناديب'],
    'verify_rep_inbox.php'        => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'رندر صندوق مراجعة بنود المناديب + إجراءاته داخل rollback'],
    'verify_rep_custody_day.php'  => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'رندر عهدة المناديب بعدسة اليوم + تبويبات كارت المندوب داخل rollback'],
    'verify_design_system.php'    => ['impact' => 'read',       'self_guard' => false, 'note' => 'لغة تصميم النظام + قاعدة «ممنوع رقم أصمّ» + نافذة التفاصيل الواحدة'],
    'verify_dashboard.php'        => ['impact' => 'read',       'self_guard' => false, 'note' => 'لوحة التحكم بأربع طبقات + رندر نوافذ التفاصيل السبعة بلا كتابة'],
    'verify_reps_ui.php'          => ['impact' => 'read',       'self_guard' => false, 'note' => 'وحدة المناديب باللغة الجديدة — رندر التبويبات والنوافذ بلا كتابة'],
    'verify_permits_ui.php'       => ['impact' => 'read',       'self_guard' => false, 'note' => 'شاشة الإذن الواحدة بوضعين — رندر الوضعين بلا كتابة'],
    'verify_money_ui.php'         => ['impact' => 'read',       'self_guard' => false, 'note' => 'شاشات الفلوس باللغة الجديدة — رندر الشاشات ونوافذها بلا كتابة'],
    'verify_dark_print.php'       => ['impact' => 'none',       'self_guard' => false, 'note' => 'الوضع الليلي وتوحيد هوية الطباعة — فحص ملفات بلا قاعدة'],
    'verify_restore.php'          => ['impact' => 'recreate',   'self_guard' => true,  'note' => 'يحذف قاعدة الاختبار ويعيد إنشاءها واستعادتها'],
    'verify_v29.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'fixtures داخل transaction ثم rollback'],
    'verify_v31.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'fixtures داخل transaction ثم rollback'],
    'verify_v32.php'              => ['impact' => 'read',       'self_guard' => false, 'note' => 'قواعد الإيصالات تقرأ settings من القاعدة'],
    'verify_v33.php'              => ['impact' => 'cleanup',    'self_guard' => false, 'note' => 'ينشئ fixtures ثم يحذفها صراحة'],
    'verify_v34.php'              => ['impact' => 'cleanup',    'self_guard' => false, 'note' => 'fixtures بتنظيف صريح وقد يستخدم الشبكة'],
    'verify_v35.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'fixtures داخل transaction ثم rollback'],
    'verify_v36.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'fixtures داخل transaction ثم rollback'],
    'verify_v39.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'fixtures داخل transaction ثم rollback'],
    'verify_v40.php'              => ['impact' => 'read',       'self_guard' => false, 'note' => 'قراءة مخطط المستخدمين والأدوار'],
    'verify_v41.php'              => ['impact' => 'cleanup',    'self_guard' => true,  'note' => 'تزامن وfixtures بتنظيف صريح ويرفض قاعدة العمل'],
    'verify_v42.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'قراءة وfixtures داخل transaction ثم rollback'],
    'verify_v43.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'رؤية/فتح إذن المندوب داخل transaction ثم rollback'],
    'verify_v44.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'صلاحية فتح إذن المندوب داخل transaction ثم rollback'],
    'verify_v45.php'              => ['impact' => 'rollback',   'self_guard' => true,  'note' => 'فيزا المندوب وتسليم السائق وعزل الخزنة داخل rollback'],
    'verify_v46.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'تحويل عهدة بين المناديب داخل transaction ثم rollback'],
    'verify_v47.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'متابعة المناديب (قراءة) داخل transaction ثم rollback'],
    'verify_v48.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'تقوية دخول المناديب داخل transaction ثم rollback'],
    'verify_v49.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'عهدة المناديب اليومية وحارس اليوم المتقفل داخل rollback'],
    'verify_v50.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'مراجعة التقفيل بعلامة لكل بند داخل transaction ثم rollback'],
    'verify_v51.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'إشعارات التطبيق (Web Push) داخل transaction ثم rollback'],
    'verify_v52.php'              => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'أساس نظام الإشعارات الموحَّد داخل transaction ثم rollback'],
    'verify_security.php'         => ['impact' => 'none',       'self_guard' => false, 'note' => 'فحص أمان بنيوي على الملفات بلا قاعدة'],
    'verify_security_panel.php'   => ['impact' => 'rollback',   'self_guard' => false, 'note' => 'لوحة المراقبة الأمنية داخل transaction ثم rollback'],
    'verify_ux.php'               => ['impact' => 'none',       'self_guard' => true,  'note' => 'فحص UX بنيوي قراءة فقط بلا قاعدة'],
];

// هذه الفاحصات تدقق سيناريوهات على عينة تشغيل موجودة مسبقًا؛ لا تُعد فشلًا
// لتثبيت جديد فارغ، لذلك تبقى في profile=isolated ولا تدخل بوابة CI الحتمية.
$ciDataDependent = [
    'verify_legacy_merge.php'      => 'يتحقق من بقاء بوالص حية بجوار الأرشيف المدموج',
    'verify_movement_capacity.php' => 'يحتاج حركة ممتلئة وبوليصة بحاوية واحدة',
    'verify_quick_entries.php'     => 'يحتاج إذنين وحركتين وكارت فيزا نشطًا',
    'verify_v29.php'               => 'يحتاج إذنًا أقدم من تاريخ العمل مع حركة',
    'verify_v31.php'               => 'يحتاج إذنًا مرتبطًا بمعرف وكيل',
];

$impactLabels = [
    'none'     => 'بلا قاعدة',
    'read'     => 'قراءة قاعدة',
    'rollback' => 'كتابة + rollback',
    'cleanup'  => 'كتابة + تنظيف صريح',
    'isolated' => 'قاعدة معزولة فقط',
    'recreate' => 'حذف/إعادة إنشاء',
];

$usage = static function (): void {
    echo <<<'TXT'
الاستخدام:
  php bin/run_verifiers.php [--profile=safe|ci|isolated] [--only=file1,file2]
                               [--list]
                               [--include-recreate --confirm-recreate=DB_NAME]

safe (الافتراضي): يشغّل الفاحصات التي لا تتصل بقاعدة البيانات فقط.
ci: يتطلب قاعدة اختبار معزولة ويشغّل الفاحصات الحتمية الصالحة لتثبيت جديد.
isolated: يتطلب ALMOTAWAKEL_TEST_DB باسم almotawakel_test_* ويشغّل كل ما عدا
          فحص إعادة إنشاء القاعدة، بما فيها تدقيقات تعتمد على عينة تشغيل.

خيار --include-recreate لا يعمل إلا مع --profile=isolated ومع تأكيد اسم قاعدة
الاختبار حرفيًا عبر --confirm-recreate. لا ينشئ runner قاعدة اختبار تلقائيًا.
TXT;
    echo PHP_EOL;
};

$profile = 'safe';
$listOnly = false;
$includeRecreate = false;
$confirmRecreate = '';
$only = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        $usage();
        exit(0);
    }
    if ($argument === '--list') {
        $listOnly = true;
        continue;
    }
    if ($argument === '--include-recreate') {
        $includeRecreate = true;
        continue;
    }
    if (str_starts_with($argument, '--profile=')) {
        $profile = substr($argument, strlen('--profile='));
        continue;
    }
    if (str_starts_with($argument, '--confirm-recreate=')) {
        $confirmRecreate = substr($argument, strlen('--confirm-recreate='));
        continue;
    }
    if (str_starts_with($argument, '--only=')) {
        $names = explode(',', substr($argument, strlen('--only=')));
        $only = array_values(array_unique(array_merge($only, array_filter(array_map('trim', $names)))));
        continue;
    }

    fwrite(STDERR, "خيار غير معروف: $argument\n");
    $usage();
    exit(VERIFY_RUNNER_USAGE_ERROR);
}

if (!in_array($profile, ['safe', 'ci', 'isolated'], true)) {
    fwrite(STDERR, "قيمة --profile غير صالحة: $profile\n");
    exit(VERIFY_RUNNER_USAGE_ERROR);
}

$discovered = array_map('basename', glob(__DIR__ . '/verify_*.php') ?: []);
sort($discovered, SORT_STRING);
$classified = array_keys($catalog);
sort($classified, SORT_STRING);

$unclassified = array_values(array_diff($discovered, $classified));
$missingFiles = array_values(array_diff($classified, $discovered));
if ($unclassified || $missingFiles) {
    if ($unclassified) {
        fwrite(STDERR, 'فاحصات غير مصنفة (يُرفض تشغيلها): ' . implode(', ', $unclassified) . PHP_EOL);
    }
    if ($missingFiles) {
        fwrite(STDERR, 'تصنيفات بلا ملفات: ' . implode(', ', $missingFiles) . PHP_EOL);
    }
    exit(VERIFY_RUNNER_USAGE_ERROR);
}

foreach ($only as $name) {
    if (!isset($catalog[$name])) {
        fwrite(STDERR, "الفاحص المطلوب غير موجود أو غير مصنف: $name\n");
        exit(VERIFY_RUNNER_USAGE_ERROR);
    }
}

if ($listOnly) {
    echo "الفاحص\tالتأثير\tCI فارغ\tيرفض قاعدة العمل بنفسه\tملاحظة\n";
    foreach ($discovered as $name) {
        $meta = $catalog[$name];
        echo $name . "\t" . $impactLabels[$meta['impact']] . "\t"
            . (isset($ciDataDependent[$name]) ? 'لا؛ يحتاج عينة تشغيل' : 'نعم') . "\t"
            . ($meta['self_guard'] ? 'نعم' : 'لا؛ يحميه runner') . "\t" . $meta['note'] . PHP_EOL;
    }
    exit(0);
}

$testDatabase = '';
if ($profile !== 'safe') {
    $testDatabase = (string) getenv('ALMOTAWAKEL_TEST_DB');
    if (!preg_match('/^almotawakel_test_[a-z0-9_]{6,48}$/', $testDatabase)) {
        fwrite(STDERR, "حارس DSN: يُرفض التشغيل. اضبط ALMOTAWAKEL_TEST_DB على اسم معزول مطابق almotawakel_test_*.\n");
        exit(VERIFY_RUNNER_USAGE_ERROR);
    }

    $configPath = dirname(__DIR__) . '/config/config.php';
    $config = require $configPath;
    $host = (string) ($config['db']['host'] ?? '');
    $port = (int) ($config['db']['port'] ?? 0);
    if ($host === '' || $port < 1 || $port > 65535) {
        fwrite(STDERR, "حارس DSN: إعداد host/port غير صالح.\n");
        exit(VERIFY_RUNNER_USAGE_ERROR);
    }

    // App::boot applies this exact CLI-only override before any child connects.
    echo "حارس DSN: مسموح بقاعدة الاختبار فقط mysql:host=$host;port=$port;dbname=$testDatabase\n";

    if ($includeRecreate && $profile !== 'isolated') {
        fwrite(STDERR, "فحص إعادة الإنشاء لا يُقبل إلا مع profile=isolated.\n");
        exit(VERIFY_RUNNER_USAGE_ERROR);
    }
    if ($includeRecreate && $confirmRecreate !== $testDatabase) {
        fwrite(STDERR, "فحص إعادة الإنشاء يتطلب --confirm-recreate=$testDatabase حرفيًا.\n");
        exit(VERIFY_RUNNER_USAGE_ERROR);
    }
} elseif ($includeRecreate || $confirmRecreate !== '') {
    fwrite(STDERR, "خيارات إعادة الإنشاء لا تُقبل مع profile=safe.\n");
    exit(VERIFY_RUNNER_USAGE_ERROR);
}

$results = [];
foreach ($discovered as $name) {
    $meta = $catalog[$name];
    $requested = !$only || in_array($name, $only, true);
    $eligible = match ($profile) {
        'isolated' => true,
        'ci'       => !isset($ciDataDependent[$name]),
        default    => $meta['impact'] === 'none',
    };
    if ($meta['impact'] === 'recreate' && !$includeRecreate) {
        $eligible = false;
    }

    if (!$requested || !$eligible) {
        $results[$name] = ['status' => 'skip', 'code' => null];
        continue;
    }

    echo PHP_EOL . "══ $name [{$impactLabels[$meta['impact']]}] ══" . PHP_EOL;
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $name);
    passthru($command, $code);
    $results[$name] = ['status' => $code === 0 ? 'pass' : 'fail', 'code' => $code];
}

$passed = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'pass'));
$failed = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'fail'));
$skipped = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'skip'));

echo PHP_EOL . "══ ملخص الفاحصات ══" . PHP_EOL;
foreach ($results as $name => $result) {
    $mark = $result['status'] === 'pass' ? '✓ نجح' : ($result['status'] === 'fail' ? '✗ فشل' : 'ـ تخطّي');
    $suffix = $result['code'] === null ? '' : " (exit {$result['code']})";
    echo "$mark  $name$suffix\n";
}
echo "الإجمالي: نجح $passed | فشل $failed | تخطّي $skipped\n";

exit($failed > 0 ? 1 : 0);
