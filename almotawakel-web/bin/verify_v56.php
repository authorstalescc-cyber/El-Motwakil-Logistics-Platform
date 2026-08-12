<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v56 — مستندات الأطراف (البطاقة الضريبية / السجل التجاري / …).
 *
 * أهم أربع حاجات:
 *   ١) **العزل:** ولا عمود مالي، ولا ملف خزنة بيعرف الجدول، ورصيد الخزنة ثابت.
 *   ٢) **`rep_visible` بتشتغل فعلاً:** المستند المخفي **مش** بيوصل شاشة المندوب
 *      ولا ملفه — الحدّ في `FileController::mayReadDoc` مش في إخفاء زرار.
 *   ٣) **مفيش تعريف تاني:** `keySet()`/`permitHasDocs()` (السريعة للقايمة) لازم
 *      تدّي نفس نتيجة `forPermit()` (البطيئة الدقيقة)، وإلا الكارت يقول «فيه
 *      مستندات» والصفحة تقول «مفيش».
 *   ٤) **الربط بيلمّ التهجئات** عبر `PartyMaster` (تحذير ٣) — «الاسكندريه»
 *      و«الإسكندرية» نفس الشركة.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v56.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\PrivateFile;
use App\Models\Closing;
use App\Models\PartyDoc;
use App\Models\PartyMaster;
use App\Models\RepPortal;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function yes(string $m, bool $c): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 60) . "\n"; }
function same(string $l, $a, $e): void {
    $a == $e ? ok($l) : bad("$l — متوقع " . var_export($e, true) . " / فعلي " . var_export($a, true));
}

$root = dirname(__DIR__);
$read = static fn(string $rel): string => is_file($root . '/' . $rel) ? (string) file_get_contents($root . '/' . $rel) : '';
/** يشيل التعليقات قبل أي فحص «النص ده مش موجود» — مصيدة code_only الموثَّقة. */
$code = static function (string $php): string {
    $out = '';
    foreach (token_get_all($php) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};

echo "فحص v56 — مستندات الأطراف\n" . str_repeat('=', 60) . "\n";

// ═══════════════════════════════════════════════════ ٠) البنية والعزل
sec('٠) البنية والعزل بالبناء');
yes('جدول party_documents موجود', PartyDoc::available());
if (!PartyDoc::available()) {
    echo "\nشغّل php bin/migrate_v56.php الأول.\n";
    exit(1);
}

$cols = array_column(Database::all(
    "SELECT column_name AS c FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'party_documents'"), 'c');
foreach (['party_type', 'party_id', 'doc_kind', 'title', 'file_path', 'rep_visible', 'expires_at'] as $c) {
    yes("العمود {$c} موجود", in_array($c, $cols, true));
}
$moneyish = [];
foreach ($cols as $c) {
    foreach (['amount', 'balance', 'custody', 'freight', 'total', 'paid', 'price'] as $m) {
        if (str_contains(strtolower($c), $m)) { $moneyish[] = $c; }
    }
}
yes('⚠️ ولا عمود مالي في الجدول (وحدة وصفية بحتة)', !$moneyish);

$treasury = ['app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/DailyReview.php',
    'app/Models/Metrics.php', 'app/Models/Expense.php',
    'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php'];
$leak = [];
foreach ($treasury as $f) {
    if (str_contains($read($f), 'party_documents') || str_contains($read($f), 'PartyDoc')) { $leak[] = $f; }
}
yes('⚠️ ولا ملف خزنة بيعرف الوحدة (' . count($treasury) . ' اتفحصوا)', !$leak);
if ($leak) { bad('  الملفات: ' . implode(', ', $leak)); }

$modelSrc = $code($read('app/Models/PartyDoc.php'));
yes('الموديل مالوش أي كتابة على جداول مالية',
    !preg_match('~(INSERT|UPDATE|DELETE)\s+(INTO\s+|FROM\s+)?(manual_expenses|permit_stages|bawalis|daily_closings|manual_custody)~i', $modelSrc));

// ═══════════════════════════════════════════════════ ١) التخزين الخاص
sec('١) الملف بيتخزَّن برّه public');
$privSrc = $read('app/Core/PrivateFile.php');
yes('تصنيف party_docs مسموح في assertCategory', str_contains($privSrc, "'party_docs'"));
yes('  › و resolve() بتعرف مساره', str_contains($privSrc, 'permits|reps|party_docs'));
$fakePath = 'private/party_docs/' . str_repeat('a', 40) . '.pdf';
same('مسار سليم بيتفكّ (الملف مش موجود فبيرجّع null)', PrivateFile::resolve($fakePath), null);
same('⚠️ محاولة خروج من المجلد بترفض', PrivateFile::resolve('private/party_docs/../../config/config.php'), null);
same('⚠️ تصنيف مش معروف بيترفض', PrivateFile::resolve('private/secrets/aaa.pdf'), null);
yes('مجلد التخزين برّه public', !str_contains($privSrc, 'public/storage'));

// ═══════════════════════════════════════════════════ ٢) المسارات
sec('٢) المسارات والترتيب (تحذير ٤)');
$routes = $read('public/index.php');
foreach ([
    "/attachments/party-doc/{id}/print" => 'FileController@partyDocPrint',
    "/attachments/party-doc/{id}"       => 'FileController@partyDoc',
    "/directory/doc/add/{type}/{id}"    => 'DirectoryController@addDoc',
    "/directory/doc/{docId}/delete"     => 'DirectoryController@deleteDoc',
    "/directory/doc/{docId}/update"     => 'DirectoryController@updateDoc',
    "/m/permit/{id}/docs"               => 'RepPortalController@permitDocs',
] as $path => $handler) {
    yes("المسار {$path} → {$handler}", str_contains($routes, "'{$path}'") && str_contains($routes, "'{$handler}'"));
}
yes('⚠️ print قبل الجشع party-doc/{id}',
    strpos($routes, "/attachments/party-doc/{id}/print") < strpos($routes, "'/attachments/party-doc/{id}'"));
yes('⚠️ /m/permit/{id}/docs قبل الجشع /m/permit/{id}',
    strpos($routes, "'/m/permit/{id}/docs'") < strpos($routes, "'/m/permit/{id}'"));
yes('⚠️ doc/add حرفي فما يتصادمش مع doc/{docId}/delete',
    str_contains($routes, "/directory/doc/add/{type}/{id}"));

// ═══════════════════════════════════════════════════ ٣) الحدّ الأمني في الكود
sec('٣) ⚠️ الحدّ الأمني: mayReadDoc + شاشة المندوب');
$fileCtl = $code($read('app/Controllers/FileController.php'));
yes('FileController بيفحص rep_visible', str_contains($fileCtl, "rep_visible"));
yes('  › جلسة المكتب بتعدّي (Auth::check)', str_contains($fileCtl, 'Auth::check()'));
yes('  › وجلسة المندوب بشرط (RepAuth::check)', str_contains($fileCtl, 'RepAuth::check()'));
yes('  › وبيرجّع 403 مش 200 للممنوع', str_contains($fileCtl, 'http_response_code(403)'));

$mDocs = $read('app/Views/m/docs.php');
yes('⚠️ شاشة المندوب مفيهاش actual_amount (قاعدة v35)', !str_contains($mDocs, 'actual'));
yes('⚠️ شاشة المندوب قراءة فقط — مفيش <form>', !str_contains($mDocs, '<form'));
yes('⚠️ ومفيش أي دالة فلوس فيها (money/intf على مبلغ)', !str_contains($mDocs, 'money('));

$repCtl = $code($read('app/Controllers/RepPortalController.php'));
yes('permitDocs بينادي requireRep قبل أي قراءة',
    (bool) preg_match('~function permitDocs\([^)]*\)\s*:\s*void\s*\{\s*\$this->requireRep\(\);~', $repCtl));
yes('  › وبيمرّ على myPermit (الإذن المقفول بيترفض)',
    (bool) preg_match('~function permitDocs.{0,400}RepPortal::myPermit~s', $repCtl));
yes('  › وبيطلب المستندات الظاهرة للمناديب بس (repOnly=true)',
    (bool) preg_match('~function permitDocs.{0,500}forPermit\(\$permit,\s*true\)~s', $repCtl));

// ═══════════════════════════════════════════════════ ٤) الكلاسات لها CSS
sec('٤) كل كلاس بيتطبع له تعريف CSS (مصيدة .table-mobile-scroll)');
$appCss = $read('public/assets/css/app.css');
$mCss   = $read('public/assets/css/m.css');
foreach (['pdoc-cnt', 'pdoc-hint', 'pdoc-empty', 'pdoc-party', 'pdoc-list', 'pdoc-item', 'pdoc-ic',
          'pdoc-main', 'pdoc-title', 'pdoc-meta', 'pdoc-kind', 'pdoc-ext', 'pdoc-flag', 'pdoc-note',
          'pdoc-acts', 'pdoc-edit', 'pdoc-grid', 'pdoc-check', 'pdoc-edit-acts', 'pdoc-add'] as $c) {
    yes("app.css فيها .{$c}", str_contains($appCss, '.' . $c));
}
foreach (['hm-stub-docs', 'hf-docs', 'md-hint', 'md-card', 'md-head', 'md-ic', 'md-title',
          'md-meta', 'md-flag', 'md-acts', 'md-btn'] as $c) {
    yes("m.css فيها .{$c}", str_contains($mCss, '.' . $c));
}
$appJs = $read('public/assets/js/app.js');
yes('⚠️ خطّاف js-pdoc-edit له تنفيذ في app.js العام (مش سكربت محلي)',
    str_contains($appJs, 'js-pdoc-edit') && str_contains($appJs, 'js-pdoc-cancel'));
yes('  › ومفيش نسخة محلية منه في أي view',
    !str_contains($read('app/Views/directory/company.php') . $read('app/Views/clients/_tab_card.php'), 'js-pdoc-edit'));

// ═══════════════════════════════════════════════════ ٥) قاعدة أمنية قائمة
sec('٥) قواعد قائمة: js_json + e() في الـviews');
foreach (['app/Views/partials/party_docs.php', 'app/Views/directory/_docs.php',
          'app/Views/permits/_docs.php', 'app/Views/m/docs.php', 'app/Views/documents/print.php'] as $v) {
    yes(basename($v) . ' بلا json_encode', !str_contains($read($v), 'json_encode'));
}
yes('مكوّن party_docs مسجَّل في README المكوّنات',
    str_contains($read('app/Views/partials/README.md'), 'party_docs'));

// ═══════════════════════════════════════════════════ ٦) السلوك الفعلي
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $t0 = Closing::currentBalance();

    sec('٦) السلوك — الربط والتهجئات وrep_visible');

    $coId = PartyMaster::create('company', ['name' => '__v56 الإسكندرية للتجارة']);
    $agId = PartyMaster::create('agent',   ['name' => '__v56 وكيل الاختبار']);
    ok('اتعمل طرفان للاختبار (شركة + وكيل)');

    $mk = static function (string $type, int $pid, string $title, int $visible, ?string $exp = null): int {
        Database::exec(
            'INSERT INTO party_documents (party_type, party_id, doc_kind, title, doc_no,
                expires_at, file_path, file_ext, rep_visible)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$type, $pid, 'tax_card', $title, '123-456', $exp,
             'private/party_docs/' . str_repeat('b', 40) . '.pdf', 'pdf', $visible]
        );
        return Database::lastId();
    };
    $visibleDoc = $mk('company', $coId, '__v56 البطاقة الضريبية', 1);
    $hiddenDoc  = $mk('company', $coId, '__v56 مستند حسّاس', 0);
    $agentDoc   = $mk('agent',   $agId, '__v56 سجل تجاري للوكيل', 1);

    same('المكتب بيشوف مستندَي الشركة', count(PartyDoc::forParty('company', $coId)), 2);
    same('⚠️ المندوب بيشوف المستند الظاهر بس', count(PartyDoc::forParty('company', $coId, true)), 1);
    $repDocs = PartyDoc::forParty('company', $coId, true);
    same('  › وهو المستند الصح', (int) $repDocs[0]['id'], $visibleDoc);

    // إذن وهمي (مصفوفة زي اللي بيبعتها الكنترولر) — بلا كتابة على permits
    $permit = ['company' => '__v56 الإسكندرية للتجارة', 'client' => '__v56 وكيل الاختبار'];
    $groups = PartyDoc::forPermit($permit);
    same('الإذن بيجيب مجموعتين (شركة + وكيل)', count($groups), 2);
    $byType = [];
    foreach ($groups as $g) { $byType[$g['type']] = count($g['docs']); }
    same('  › مستندا الشركة', $byType['company'] ?? 0, 2);
    same('  › ومستند الوكيل', $byType['agent'] ?? 0, 1);

    $repGroups = PartyDoc::forPermit($permit, true);
    $repTotal = 0;
    foreach ($repGroups as $g) { $repTotal += count($g['docs']); }
    same('⚠️ نسخة المندوب فيها ٢ بس (الحسّاس اتشال)', $repTotal, 2);
    $repFlat = '';
    foreach ($repGroups as $g) { foreach ($g['docs'] as $d) { $repFlat .= $d['title']; } }
    yes('  › والمستند المخفي مش فيها إطلاقًا', !str_contains($repFlat, 'مستند حسّاس'));

    sec('٧) ⚠️ التهجئات بتلمّ على نفس السجل (تحذير ٣)');
    $misspelled = ['company' => '__v56 الاسكندريه للتجاره', 'client' => ''];
    $g2 = PartyDoc::forPermit($misspelled);
    same('تهجئة مختلفة بترجّع نفس مستندات الشركة', count($g2[0]['docs'] ?? []), 2);
    $unknown = PartyDoc::forPermit(['company' => 'شركة مش موجودة خالص __zz', 'client' => '']);
    same('اسم مش معروف بيرجّع فاضي — مفيش تخمين', count($unknown), 0);

    sec('٨) ⚠️ مفيش تعريف تاني: keySet == forPermit');
    $keysAll = PartyDoc::keySet(false);
    $keysRep = PartyDoc::keySet(true);
    yes('keySet(all) بتشوف الشركة', PartyDoc::permitHasDocs($keysAll, $permit['company'], null));
    yes('  › وبالتهجئة الغلط كمان', PartyDoc::permitHasDocs($keysAll, $misspelled['company'], null));
    yes('  › واسم مجهول = false', !PartyDoc::permitHasDocs($keysAll, 'شركة مش موجودة خالص __zz', null));
    // المستند المخفي هو الوحيد على شركة تانية → لازم keySet(rep) ما تشوفهاش
    $onlyHidden = PartyMaster::create('company', ['name' => '__v56 شركة مخفية']);
    $mk('company', $onlyHidden, '__v56 مخفي بالكامل', 0);
    $keysRep2 = PartyDoc::keySet(true);
    yes('⚠️ keySet(rep) ما بتشوفش شركة كل مستنداتها مخفية',
        !PartyDoc::permitHasDocs($keysRep2, '__v56 شركة مخفية', null));
    yes('  › بينما keySet(all) بتشوفها',
        PartyDoc::permitHasDocs(PartyDoc::keySet(false), '__v56 شركة مخفية', null));
    // التطابق التام: كل شركة عندها مستندات ظاهرة لازم keySet(rep) توافق forPermit(rep)
    $mismatch = 0;
    foreach ([$permit['company'], $misspelled['company'], '__v56 شركة مخفية', 'شركة مش موجودة __zz'] as $nm) {
        $viaKeys = PartyDoc::permitHasDocs($keysRep2, $nm, null);
        $viaSlow = false;
        foreach (PartyDoc::forPermit(['company' => $nm, 'client' => ''], true) as $g) {
            if ($g['docs']) { $viaSlow = true; }
        }
        if ($viaKeys !== $viaSlow) { $mismatch++; }
    }
    same('⚠️ الطريقتان بيتفقوا على كل الحالات', $mismatch, 0);

    sec('٩) صلاحية المستند — شارة عرض بس');
    same('بلا تاريخ = none', PartyDoc::expiryState(['expires_at' => null])['state'], 'none');
    same('تاريخ فات = expired', PartyDoc::expiryState(['expires_at' => date('Y-m-d', strtotime('-3 day'))])['state'], 'expired');
    same('خلال شهر = soon', PartyDoc::expiryState(['expires_at' => date('Y-m-d', strtotime('+10 day'))])['state'], 'soon');
    same('بعيد = ok', PartyDoc::expiryState(['expires_at' => date('Y-m-d', strtotime('+200 day'))])['state'], 'ok');

    sec('١٠) اسم التنزيل بالعربي');
    $dl = PartyDoc::downloadName(PartyDoc::find($visibleDoc));
    yes('الاسم فيه عنوان المستند', str_contains($dl, 'البطاقة الضريبية'));
    yes('  › واسم الشركة', str_contains($dl, 'الإسكندرية'));
    yes('  › وبينتهي بامتداد الملف', str_ends_with($dl, '.pdf'));
    yes('  › وبلا أي حرف بيكسر أسماء الملفات',
        !preg_match('~[/\\\\:*?"<>|]~', $dl));

    sec('١١) رندر فعلي — لوح الإذن وشاشة المندوب');
    $grab = static function (callable $fn): string {
        ob_start();
        try { $fn(); } catch (\Throwable $e) { ob_end_clean(); return 'EXC: ' . $e->getMessage(); }
        return (string) ob_get_clean();
    };

    // لوح الإذن (المكتب) — بيقرا $p و$partyDocs
    $panel = $grab(static function () use ($permit, $groups) {
        $p = $permit; $partyDocs = $groups;
        include dirname(__DIR__) . '/app/Views/permits/_docs.php';
    });
    yes('لوح مستندات الإذن بيرندر', !str_starts_with($panel, 'EXC') && strlen($panel) > 300);
    yes('  › وفيه المستند الظاهر', str_contains($panel, '__v56 البطاقة الضريبية'));
    yes('  › وفيه المستند الحسّاس كمان (شاشة مكتب)', str_contains($panel, '__v56 مستند حسّاس'));
    yes('  › وفيه أزرار فتح/تحميل/طباعة', str_contains($panel, 'attachments/party-doc/' . $visibleDoc)
        && str_contains($panel, 'dl=1') && str_contains($panel, '/print'));
    yes('  › ومفيش أي مبلغ فيه', !str_contains($panel, 'ج.م'));

    // لوح فاضي = مايترندرش خالص (ما نزحمش الشاشة)
    $emptyPanel = $grab(static function () {
        $p = ['company' => 'مش موجودة __zz', 'client' => ''];
        include dirname(__DIR__) . '/app/Views/permits/_docs.php';
    });
    yes('الإذن اللي مالوش مستندات ما بيرسمش اللوح', trim($emptyPanel) === '');

    // شاشة المندوب
    $mScreen = $grab(static function () use ($repGroups) {
        $permit = ['id' => 1, 'no' => 'X-1', 'type' => 'sader', 'type_label' => 'إذن',
                   'company' => '__v56 الإسكندرية للتجارة', 'client' => ''];
        $groups = $repGroups;
        include dirname(__DIR__) . '/app/Views/m/docs.php';
    });
    yes('شاشة مستندات المندوب بترندر', !str_starts_with($mScreen, 'EXC') && strlen($mScreen) > 1000);
    yes('  › وفيها المستند الظاهر', str_contains($mScreen, '__v56 البطاقة الضريبية'));
    yes('  › ⚠️ ومفيهاش المستند الحسّاس', !str_contains($mScreen, '__v56 مستند حسّاس'));
    yes('  › وفيها زر التحميل', str_contains($mScreen, 'dl=1'));
    yes('  › وفيها زر الطباعة', str_contains($mScreen, '/print'));

    // صفحة الطباعة
    $printPage = $grab(static function () use ($visibleDoc, $coId) {
        $doc = PartyDoc::find($visibleDoc);
        $party = PartyMaster::find('company', $coId);
        include dirname(__DIR__) . '/app/Views/documents/print.php';
    });
    yes('صفحة الطباعة بترندر', !str_starts_with($printPage, 'EXC') && str_contains($printPage, 'window.print'));
    yes('  › وفيها @page A4', str_contains($printPage, '@page'));
    yes('  › ⚠️ وبلا أي مبلغ (بتتفتح على موبايل المندوب)', !str_contains($printPage, 'ج.م'));

    sec('١٢) الحذف بيشيل الصف');
    PartyDoc::delete($hiddenDoc);
    same('المستند اتشال من القاعدة', PartyDoc::find($hiddenDoc), null);
    same('  › والباقي زي ما هو', count(PartyDoc::forParty('company', $coId)), 1);

    sec('١٣) ⚠️ صفر أثر مالي');
    $t1 = Closing::currentBalance();
    abs($t1 - $t0) <= 0.005
        ? ok('رصيد الخزنة قبل = بعد (' . number_format($t0, 2) . ')')
        : bad('رصيد الخزنة اتغيّر: ' . number_format($t0, 2) . ' ← ' . number_format($t1, 2));
    same('ومفيش عمود دخل على bawalis',
        (int) Database::scalar("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'bawalis' AND column_name = 'doc_id'"), 0);

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
    echo "رصيد الخزنة دلوقتي: " . number_format(Closing::currentBalance(), 2) . "\n";
    echo "\nنجح: $pass | فشل: $fail\n";
    exit($fail > 0 ? 1 : 0);
}
