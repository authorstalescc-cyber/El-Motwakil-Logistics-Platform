<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v50 — مراجعة التقفيل بعلامة ✓ جنب كل بند.
 *
 * كل الكتابة جوّه transaction بيترجع (rollback) فما بيسيبش أي أثر في القاعدة.
 * أهم فحصين:
 *   • **مفيش تعريف تاني للأرقام**: مجاميع أقسام المراجعة = أرقام `Closing::dayView`
 *     بالمليم. لو الاتنين اختلفوا يبقى الشاشة بتعرض حاجة والرصيد بيقول حاجة تانية.
 *   • **العزل المالي**: رصيد الخزنة قبل ↔ بعد كل عمليات المراجعة (المراجعة طبقة
 *     تدقيق، ممنوع يكون ليها أي أثر على قرش واحد).
 *
 * التشغيل: php bin/verify_v50.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\DailyClosingController;
use App\Core\Auth;
use App\Core\Database;
use App\Models\Closing;
use App\Models\DailyReview;

$pass = 0; $failed = 0;
$ok   = function (string $m): void { global $pass; $pass++; echo "  ✓ $m\n"; };
$bad  = function (string $m): void { global $failed; $failed++; echo "  ✗ $m\n"; };
$is   = function (string $m, $got, $want) use ($ok, $bad): void {
    if ($got === $want) { $ok($m); }
    else { $bad($m . ' — المتوقع ' . var_export($want, true) . ' والفعلي ' . var_export($got, true)); }
};
$chk  = function (string $m, bool $cond) use ($ok, $bad): void { $cond ? $ok($m) : $bad($m); };
$sec  = function (string $t): void { echo "\n" . $t . "\n" . str_repeat('─', 58) . "\n"; };
$root = dirname(__DIR__);
$near = fn(float $a, float $b): bool => abs($a - $b) < 0.005;
$code = function (string $rel) use ($root): string {
    $src = @file_get_contents($root . '/' . $rel);
    // التعليقات بتتشال قبل أي فحص «النص ده مش موجود» — تعليق بيشرح القديم
    // كان بيدّي إنذار كاذب (مصيدة code_only الموثَّقة في verify_security).
    return $src === false ? '' : (string) preg_replace('~/\*.*?\*/|//[^\n]*|(?<!:)#[^\n]*~s', '', $src);
};

echo "فحص v50 — مراجعة التقفيل بعلامة لكل بند\n" . str_repeat('=', 58) . "\n";

// ═══════════════════════════════════ 0) البنية
$sec('0) البنية');
$chk('جدول daily_closing_reviews موجود (شغّل migrate_v50 لو ناقص)', DailyReview::available());
if (!DailyReview::available()) { echo "\nالبنية ناقصة — وقّف الفحص.\n"; exit(1); }
$chk('المفتاح الفريد uq_review_item موجود (بند واحد = علامة واحدة)',
    (bool) Database::scalar("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema=DATABASE() AND table_name='daily_closing_reviews' AND index_name='uq_review_item'"));
$chk('ملف موديل المطابقة القديم (DailyReconciliation) اتشال',
    !is_file($root . '/app/Models/DailyReconciliation.php'));
/* ⚠️ المسح بـPHP مش بـshell_exec: **كل دوال تشغيل العمليات مقفولة على الإنتاج**
   (exec/shell_exec/proc_open) — الفحص كان بيقف عند السطر ده على السيرفر. */
$leftovers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php'
        && str_contains((string) file_get_contents($file->getPathname()), 'DailyReconciliation')) {
        $leftovers[] = $file->getFilename();
    }
}
$chk('مفيش أي كود في app/ لسه بينادي DailyReconciliation'
    . ($leftovers ? ' (' . implode(', ', $leftovers) . ')' : ''), !$leftovers);

// ═══════════════════════════════════ 1) مفيش تعريف تاني للأرقام
$sec('1) أرقام المراجعة = أرقام الإقفال بالظبط');
// أكتر يوم فيه حركة — الفحص هنا قراءة فقط فمفيش مشكلة لو متقفل.
$lastClosed = Closing::lastClosedDate();
$busyDay = Database::scalar(
    "SELECT entry_date FROM permit_stages WHERE amount<>0 AND entry_date IS NOT NULL
     GROUP BY entry_date ORDER BY COUNT(*) DESC LIMIT 1"
) ?: work_date();
$view = DailyReview::view($busyDay);
$day  = Closing::dayView($busyDay);
/* ⚠️ **المجموع مشتقّ من `BLOCKING` مش مكتوب بالإيد.** كان `['permits','general',
   'custody']` حرفيًّا، فلما المرحلة ٢ ضافت قسم `rep_deposit` (وهو داخل
   `Closing::sumExpenses` فعلاً) فَلَت من المطابقة **بالسكوت** — وما بانش لأن
   مفيش تنزيل من الدرج في البيانات لسه. والاشتقاق بيستثني `dir='in'` (الإضافات)
   لأن المقارنة على **الخارج** بس. */
$cashOut = 0.0;
foreach (DailyReview::BLOCKING as $g) {
    if (($view['groups'][$g]['dir'] ?? '') === 'out') { $cashOut += (float) $view['groups'][$g]['total']; }
}
echo "  ℹ️  يوم $busyDay: بنود = " . (int) $view['totals']['count'] . "\n";
$chk('إجمالي الأقسام النقدية = مصروفات اليوم + عهد السائقين في Closing (' . number_format($cashOut, 2) . ')',
    $near($cashOut, (float) $day['expenses'] + (float) $day['driver']));
/* ⚠️ **المقارنة على بنود الدرج بس مش على مجموع القسم.** بطلب المالك بقت عهدة
   المناديب تنزل في نفس القسم (الفلوس داخلة برضه، بس لجيب المندوب مش للدرج)،
   فمجموع القسم بقى أكبر من `Closing::sumTopups` عن قصد. القاعدة اللي بتتحرس:
   **بنود الدرج بالظبط = topups في Closing** — ودي اللي بتضمن إن المرحّل صح. */
/* ⚠️ **المقارنة على البنود اللي بتتحسب فعلاً**: الدرج + عهدة المندوب من البنك.
   بنود `carry` معروضة للتوثيق بس (رصيد متحسوب خلاص في مرحّل امبارح) فمستثناة —
   لو دخلت الحساب بتتعدّ مرتين. */
$countedTopups = 0.0;
/* ⚠️⚠️ **القايمة مشتقّة من `DailyReview::countedTopupTypes()` مش مكتوبة بالإيد**
   (تصحيح 2026-08-01): أنهي نوع بيتحسب في `Closing::sumTopups` بيعتمد على النموذج
   الفعّال — عهدة البنك محسوبة في «الخزنة = كل كاش الشركة» ومستثناة في «الدرج بس».
   القايمة الثابتة كانت هتنجح في نموذج وتفشل في التاني بإنذار كاذب. */
$topupTypes = DailyReview::countedTopupTypes();
foreach ($view['groups']['topups']['items'] as $it) {
    if (in_array($it['type'] ?? '', $topupTypes, true)) { $countedTopups += (float) $it['amount']; }
}
$chk('بنود «عهدة مضافة» المحسوبة (درج + بنك) = topups في Closing',
    $near($countedTopups, (float) $day['topups']));
$chk('وتفكيك القسم بيفصل الدرج عن المناديب عن المرحَّل',
    $near($countedTopups, (float) (($view['totals']['topups_drawer'] ?? 0) + ($view['totals']['rep_bank'] ?? 0))));
$chk('قسم «عهد السائقين» = driver في Closing (المخصوم بعد الخصم مش العهدة الخام)',
    $near((float) $view['groups']['custody']['total'], (float) $day['driver']));
$chk('قسم الفيزا معلوماتي — مش داخل المجموع النقدي',
    $view['groups']['visa']['kind'] === 'info'
    && !in_array('visa', DailyReview::BLOCKING, true));
/* ⚠️ المرحلة ٢ من docs/16 — قسمان جديدان، وتصنيفهما هو اللي بيحفظ المطابقة فوق:
     • `rep_wallet` = دفعها المندوب من محفظته ⇒ **معلوماتي** (ما خرجش من الدرج).
     • `rep_deposit` = كاش نزل من الدرج للمندوب ⇒ **نقدي ومانع** (خرج فعلاً).
   قلب أي واحد منهم بيخلّي مجموع الأقسام يخالف مصروفات `Closing` بالسكوت. */
$chk('قسم «دفعها المندوب من عهدته» معلوماتي — مش مانع للإقفال',
    ($view['groups']['rep_wallet']['kind'] ?? '') === 'info'
    && !in_array('rep_wallet', DailyReview::BLOCKING, true));
$chk('قسم «عهدة نزلت للمناديب من الدرج» نقدي ومانع للإقفال',
    ($view['groups']['rep_deposit']['kind'] ?? '') === 'cash'
    && in_array('rep_deposit', DailyReview::BLOCKING, true));

// ── تفاصيل البند اللي المالك بيراجع بيها (طلب صريح): الإذن والإيصال والمندوب
//    والتصنيف والسعر الفعلي وتفاصيل البوليصة.
$stageItem = $view['groups']['permits']['items'][0] ?? null;
if ($stageItem) {
    foreach ([
        'ref' => 'رقم الإذن/الشهادة', 'permit_type_label' => 'نوع الإصدار (صادر/وارد)',
        'party' => 'العميل', 'containers' => 'تصنيف الحاويات (عدد×مقاس)',
        'title' => 'نوع البند',
    ] as $field => $label) {
        $chk("بند الإذن بيحمل $label", ($stageItem[$field] ?? '') !== '');
    }
    foreach (['actual', 'qty', 'unit', 'by_rep', 'destination', 'ref_no',
              'agency', 'shipping_co', 'port', 'party_label'] as $field) {
        $chk("مفتاح {$field} موجود في بند الإذن", array_key_exists($field, $stageItem));
    }
    $withActual = 0; $withCt = 0; $withRep = 0;
    foreach ($view['groups']['permits']['items'] as $it) {
        if ($it['actual'] !== null) { $withActual++; }
        if (($it['containers'] ?? '') !== '') { $withCt++; }
        if (!empty($it['by_rep'])) { $withRep++; }
    }
    echo "  ℹ️  من " . count($view['groups']['permits']['items']) . " بند إذن: $withActual بسعر فعلي · "
       . "$withCt بتصنيف حاويات · $withRep دفعها مندوب\n";
    $chk('تصنيف الحاويات بيتحسب لبنود الأذونات (مش فاضي للكل)',
        $withCt > 0 || !$view['groups']['permits']['items']);
}
$custodyItem = $view['groups']['custody']['items'][0] ?? null;
if ($custodyItem) {
    foreach (['car', 'destination', 'containers', 'gross', 'company'] as $field) {
        $chk("بند البوليصة بيحمل {$field}", array_key_exists($field, $custodyItem));
    }
    $chk('بند البوليصة بيوضّح العهدة قبل الخصم', $custodyItem['gross'] >= $custodyItem['amount']);
}

// ═══════════════════════════════════ 2) سلوك العلامة (fixtures + rollback)
$sec('2) سلوك العلامة — تعليم/إلغاء/سقوط عند تغيّر المبلغ');
$balanceBefore = Closing::currentBalance();
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    // يوم بعيد في المستقبل عشان ما يلمسش أي بيانات حقيقية ولا أي إقفال
    $testDay = '2038-03-11';
    Database::exec("INSERT INTO manual_expenses (exp_date,category,beneficiary,amount,note,is_visa)
                    VALUES (?,?,?,?,?,0)", [$testDay, 'فحص v50', 'جهة فحص', 250.00, 'بند فحص أول']);
    $e1 = (int) $pdo->lastInsertId();
    Database::exec("INSERT INTO manual_expenses (exp_date,category,beneficiary,amount,note,is_visa)
                    VALUES (?,?,?,?,?,0)", [$testDay, 'فحص v50', 'جهة فحص', 130.00, 'بند فحص تاني']);
    $e2 = (int) $pdo->lastInsertId();
    Database::exec("INSERT INTO manual_expenses (exp_date,category,beneficiary,amount,note,is_visa)
                    VALUES (?,?,?,?,?,1)", [$testDay, 'فحص v50', 'جهة فحص', 900.00, 'بند فيزا']);
    $e3 = (int) $pdo->lastInsertId();
    Database::exec("INSERT INTO manual_custody (cust_date,driver,amount,note) VALUES (?,?,?,?)",
        [$testDay, 'فحص', 1000.00, 'عهدة فحص']);
    $c1 = (int) $pdo->lastInsertId();

    $v = DailyReview::view($testDay);
    $is('اليوم فيه 4 بنود (٣ مصروفات + عهدة مضافة)', (int) $v['totals']['count'], 4);
    $is('البنود اللي بتمنع الإقفال 3 (الفيزا مستثناة)', (int) $v['totals']['blocking'], 3);
    $is('مفيش علامة في الأول', (int) $v['totals']['reviewed'], 0);
    $is('اليوم مش جاهز للإقفال قبل المراجعة', $v['complete'], false);
    $is('بند الفيزا راح لقسم الفيزا', $v['groups']['visa']['count'], 1);

    $v = DailyReview::mark($testDay, 'manual', $e1, true, 'فاحص');
    $is('بعد تعليم بند: العدد المراجَع = 1', (int) $v['totals']['reviewed'], 1);
    $is('لسه مش كامل (فاضل بندين)', $v['complete'], false);

    $v = DailyReview::markGroup($testDay, 'general', true, 'فاحص');
    $is('«علّم القسم كله» علّم المصروفين', (int) $v['groups']['general']['reviewed'], 2);
    $is('القسم اتعلّم إنه خلص', $v['groups']['general']['done'], true);
    $is('لسه فاضل العهدة المضافة', (int) $v['totals']['blocking_pending'], 1);

    $v = DailyReview::mark($testDay, 'topup', $c1, true, 'فاحص');
    $is('بعد آخر بند: اليوم جاهز للإقفال', $v['complete'], true);
    $is('النسبة 100%', (int) $v['totals']['percent'], 100);
    $is('بند الفيزا لسه بلا علامة ومع ذلك اليوم جاهز', $v['groups']['visa']['reviewed'], 0);

    // ⚠️ أهم حارس: البند اللي اتغيّر مبلغه بعد المراجعة لازم يرجع «محتاج مراجعة»
    Database::exec("UPDATE manual_expenses SET amount=999 WHERE id=?", [$e1]);
    $v = DailyReview::view($testDay);
    $item = null;
    foreach ($v['groups']['general']['items'] as $it) { if ($it['key'] === 'manual:' . $e1) { $item = $it; } }
    $is('البند المتغيّر اتوسم stale', $item['stale'] ?? null, true);
    $is('وعلامته سقطت (مش متراجَع)', $item['reviewed'] ?? null, false);
    $is('واليوم رجع مش جاهز للإقفال', $v['complete'], false);
    Database::exec("UPDATE manual_expenses SET amount=250 WHERE id=?", [$e1]);
    $is('رجوع المبلغ الأصلي بيرجّع العلامة', DailyReview::view($testDay)['complete'], true);

    // إلغاء العلامة
    $v = DailyReview::mark($testDay, 'manual', $e2, false, 'فاحص');
    $is('إلغاء العلامة بيشيلها', (int) $v['totals']['blocking_pending'], 1);

    // حفظ المجموعة الكاملة (مسار بلا جافاسكريبت): اللي مش في القايمة بتتشال علامته
    $v = DailyReview::saveSet($testDay, ['manual:' . $e1, 'manual:' . $e2, 'topup:' . $c1], 'فاحص');
    $is('حفظ الشاشة كلها بيعلّم اللي متعلّم', (int) $v['totals']['blocking_reviewed'], 3);
    $v = DailyReview::saveSet($testDay, ['manual:' . $e1], 'فاحص');
    $is('واللي اتشال تعليمه بتتشال علامته', (int) $v['totals']['blocking_reviewed'], 1);

    // بند مش من اليوم ده — الفورم مش مصدر ثقة
    $rejected = false;
    try { DailyReview::mark($testDay, 'manual', 999999, true, 'فاحص'); }
    catch (\DomainException $e) { $rejected = true; }
    $is('بند مش من بنود اليوم بيترفض (POST مزوّر)', $rejected, true);
    $is('ومفيش صف اتكتب له',
        (int) Database::scalar("SELECT COUNT(*) FROM daily_closing_reviews WHERE close_date=? AND item_id=999999",
            [$testDay]), 0);

    $rejectedGroup = false;
    try { DailyReview::markGroup($testDay, 'nonsense', true, 'فاحص'); }
    catch (\DomainException $e) { $rejectedGroup = true; }
    $is('مجموعة مش معروفة بترفض', $rejectedGroup, true);

    $pdo->rollBack();
    $ok('اترجّع كل شيء (rollback) — الفحص ما سابش أثر');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $bad('استثناء أثناء فحص السلوك: ' . $e->getMessage());
}
$chk('رصيد الخزنة ما اتغيّرش — المراجعة طبقة تدقيق بلا أثر مالي',
    $near($balanceBefore, Closing::currentBalance()));

// ═══════════════════════════════════ 3) الحرّاس في الكود
$sec('3) الحرّاس: اليوم المتقفل + بوابة الإقفال');
$ctrl = $code('app/Controllers/DailyClosingController.php');
$chk('الإقفال مشروط بمراجعة كل البنود', str_contains($ctrl, "review['complete']"));
$chk('الكتابة على يوم متقفل بترفض (lockMessage → Closing::isLocked)',
    str_contains($ctrl, 'Closing::isLocked') && str_contains($ctrl, 'lockMessage'));
$chk('كل مسارات المراجعة الثلاثة بتنادي lockMessage',
    substr_count($ctrl, '$this->lockMessage(') === 3);
$chk('CSRF مفروض على كل مسارات المراجعة', substr_count($ctrl, 'requireCsrf()') >= 5);
$chk('مفيش دالة reconcile قديمة في الكنترولر', !str_contains($ctrl, 'function reconcile'));
/* ⚠️ حارس باج اتصادف فعليًّا: `json()` بتطبع وما بتوقفش، فمن غير `exit` الكود
   بيكمّل لـ`done()` → `redirect()` → تحذير PHP بيتلزق جوّه الرد فيبوظ الـJSON
   والمتصفح يقول «رد غير متوقّع من الخادم». */
$chk('respond() بتوقف بعد الـJSON (مفيش redirect ورا الرد)',
    (bool) preg_match('~\$this->json\(\[.*?\]\);\s*exit;~s', $ctrl));

$routes = $code('public/index.php');
$chk('مسار /closing/reconcile اتشال', !str_contains($routes, 'closing/reconcile'));
foreach (['closing/review', 'closing/review-group', 'closing/review-save'] as $r) {
    $chk("مسار /$r متسجَّل", str_contains($routes, "'/$r'"));
}

$model = $code('app/Models/DailyReview.php');
$chk('الموديل بيقرا مصادر الخزنة نفسها (permit_stages/manual_expenses/rep_*/bawalis/manual_custody)',
    str_contains($model, 'permit_stages') && str_contains($model, 'manual_expenses')
    && str_contains($model, 'rep_misc_expenses') && str_contains($model, 'manual_custody')
    && str_contains($model, 'bawalis'));
$chk('شرط بنود المصروف من Permit::expenseSql مش مكتوب بالإيد',
    str_contains($model, 'Permit::expenseSql'));
$chk('خصم عهدة البوليصة من Custody::billDeduction مش محسوب بالإيد',
    str_contains($model, 'Custody::billDeduction'));
$chk('كاش المناديب من Rep::cashKindsList مش مكتوب بالإيد',
    str_contains($model, 'Rep::cashKindsList'));

// ═══════════════════════════════════ 4) رندر الشاشة
$sec('4) رندر الشاشة');
Auth::start();
$user = Database::row('SELECT id FROM users ORDER BY id LIMIT 1');
if (!$user) { $bad('مفيش مستخدم لفحص الرندر'); }
else {
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['uname'] = 'فحص v50';

    /* ⚠️ الرندر لازم يكون على يوم **فيه بنود** و**مش متقفل**: اليوم المتقفل بيرندر
       بلا أزرار تعليم، واليوم الفاضي بيخلّي فحص «كل بند له خانة» ينجح كذبًا وهو
       بيعدّ صفر. فبنزرع بنود على يوم اختبار جوّه transaction وبنرجّعها بعدها. */
    $renderDay = '2038-03-12';
    $pdo->beginTransaction();
    Database::exec("INSERT INTO manual_expenses (exp_date,category,beneficiary,amount,note,is_visa)
                    VALUES (?,?,?,?,?,0)", [$renderDay, 'فحص رندر', 'جهة', 75.00, 'بند رندر']);
    Database::exec("INSERT INTO manual_custody (cust_date,driver,amount,note) VALUES (?,?,?,?)",
        [$renderDay, 'فحص', 500.00, 'عهدة رندر']);

    $_SESSION['work_date'] = $renderDay;
    $_GET['date'] = $renderDay;
    $_SERVER['REQUEST_URI'] = '/closing?date=' . rawurlencode($renderDay);
    ob_start();
    (new DailyClosingController())->index();
    $html = ob_get_clean();
    $pdo->rollBack();

    foreach ([
        'cl-page' => 'هيكل الصفحة',
        'id="review"' => 'قسم المراجعة',
        'rv-check' => 'خانة العلامة جنب البند',
        'rv-gauge' => 'مؤشّر التقدّم',
        'data-group-toggle' => 'زرار «علّم القسم كله»',
        'rvSearch' => 'البحث في البنود',
        'closing/review-save' => 'مسار الحفظ بلا جافاسكريبت',
        'final-close' => 'قسم الإقفال',
    ] as $needle => $label) {
        $chk("$label ظاهر في الرندر", str_contains($html, $needle));
    }

    // ⚠️ `{$gone}` مش `$gone»` — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة v38/v49)
    foreach (['manual_total', 'reconForm', 'closing/reconcile', 'طابق اليدوي', 'الفرق النهائي'] as $gone) {
        $chk("أثر المطابقة القديمة «{$gone}» اتشال", !str_contains($html, $gone));
    }

    $items = substr_count($html, 'class="rv-item');
    $checks = substr_count($html, 'class="rv-check"');
    $chk("الرندر فيه بنود فعلاً ($items بند) — وإلا الفحص اللي بعده بينجح كذبًا", $items >= 2);
    $chk("كل بند له خانة علامة واحدة ($items بند / $checks خانة)", $items === $checks);

    preg_match_all('~\sid="([^"]+)"~', $html, $ids);
    $dupes = array_filter(array_count_values($ids[1] ?? []), fn($n) => $n > 1);
    $chk('مفيش معرّفات HTML مكررة' . ($dupes ? ' (' . implode(', ', array_keys($dupes)) . ')' : ''), !$dupes);

    // الموبايل: الشاشة لازم يكون فيها نقط كسر حقيقية ومساحات لمس ≥44px
    $chk('فيه نقطة كسر للهاتف (640px)', str_contains($html, 'max-width:640px'));
    $chk('فيه نقطة كسر للتابلت (900px)', str_contains($html, 'max-width:900px'));
    $chk('مساحة اللمس للعلامة 44px', str_contains($html, '.rv-pick{display:grid;place-items:center;width:44px;height:44px'));
    $chk('مفيش شبكة grid-template-columns مكتوبة inline (بتدهس التجاوب)',
        !preg_match('~style="[^"]*grid-template-columns~', $html));
    $chk('الحركة بتحترم prefers-reduced-motion', str_contains($html, 'prefers-reduced-motion'));

    /* ⚠️ التسلسل البصري (طلب المالك: «اسم البند صغير جدًا ومش ظاهر»):
       اسم البند لازم يفضل أكبر نص في الصف، وسطر الحقائق ممنوع ينزل تحت fs-2. */
    $chk('اسم البند بحجم fs-4 (أوضح سطر في الصف)',
        (bool) preg_match('~\.rv-title\{[^}]*font-size:var\(--fs-4\)~', $html));
    $chk('سطر الحقائق مش أصغر من fs-2',
        (bool) preg_match('~\.rv-facts dd\{[^}]*font-size:var\(--fs-2\)~', $html)
        && (bool) preg_match('~\.rv-facts dt\{[^}]*font-size:var\(--fs-2\)~', $html));
    $chk('شارات التعريف مش أصغر من fs-2',
        (bool) preg_match('~\.rv-tag\{[^}]*font-size:var\(--fs-2\)~', $html));
    $chk('المبلغ بحجم fs-5', (bool) preg_match('~\.rv-amt\{[^}]*font-size:var\(--fs-5\)~', $html));
    /* ⚠️ توقيع `AppUI.notice(message, title)` — التاني نص مش كائن. تمرير كائن
       كان بيطبع «[object Object]» مكان العنوان. اتصادف فعليًّا. */
    $chk('رسالة الفشل بتستخدم توقيع notice(msg, title) الصح',
        !preg_match('~notice\([^)]*\{\s*title~', $html));
    // (قاعدة «ممنوع alert/confirm الأصلية» محروسة عامةً في verify_forms_feedback)

    // اليوم المتقفل: المراجعة تتعرض للقراءة بس — الخانات متعطّلة ومفيش أزرار تعليم
    if ($lastClosed !== null) {
        $_SESSION['work_date'] = $lastClosed;
        $_GET['date'] = $lastClosed;
        $_SERVER['REQUEST_URI'] = '/closing?date=' . rawurlencode($lastClosed);
        ob_start();
        (new DailyClosingController())->index();
        $locked = ob_get_clean();
        $chk('اليوم المتقفل بيعرض تنبيه إن المراجعة مثبّتة', str_contains($locked, 'rv-locked'));
        preg_match_all('~<input type="checkbox" class="rv-check".*?>~s', $locked, $boxes);
        $allDisabled = true;
        foreach ($boxes[0] ?? [] as $box) { if (!str_contains($box, 'disabled')) { $allDisabled = false; } }
        $chk('وكل خانات العلامة فيه متعطّلة (' . count($boxes[0] ?? []) . ' خانة)', $allDisabled);
        $chk('ومفيش زرار «علّم القسم كله» على يوم متقفل', !str_contains($locked, 'data-group-toggle'));
        $chk('ومفيش سكربت حفظ تلقائي على يوم متقفل', !str_contains($locked, "getElementById('rvForm')"));
    }
}

// ═══════════════════════════════════ الخلاصة
echo "\n" . str_repeat('=', 58) . "\n";
echo $failed === 0
    ? "✅ كل الفحوصات عدّت ($pass فحص)\n"
    : "❌ فشل $failed فحص من " . ($pass + $failed) . "\n";
exit($failed === 0 ? 0 : 1);
