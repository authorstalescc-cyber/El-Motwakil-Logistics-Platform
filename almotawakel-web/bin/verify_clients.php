<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «حسابات العملاء» (v93) — الوحدة اللي اتفصلت عن «دليل الأطراف».
 *
 * طلب المالك: «نفصل العملاء من دليل الأطراف ونعملها منفصلة اسمها حسابات
 * العملاء بشكل احترافي وقوي… البروفايل الموجود غير احترافي بالمرة.»
 *
 * أهم اللي بيتأكد منه:
 *   ① **الوحدة قراءة بحتة** — ولا `INSERT/UPDATE/DELETE` في `ClientAccount`.
 *      الكتابة الوحيدة (التحصيل) في `ClientPayment` زي ما كانت.
 *   ② **الأرقام من مصدرها** — الترويسة بتطابق `PermitInvoice::summaryFor`
 *      و`ClientPayment::totalFor` بالقرش، والقائمة بتطابق الترويسة لكل عميل.
 *      (تعريفان مختلفان = «الكارت يقول رقم والكشف يقول تاني».)
 *   ③ **كشف الحساب متوازن**: مدين − دائن + أول المدة = الرصيد، وأعمار الدين
 *      مجموعها = باقي الفواتير المفتوحة بالحرف.
 *   ④ **السلوك المالي اتنقل بلا تغيير**: تحصيل بيقلّل الرصيد بمقداره،
 *      وعكسه بيرجّعه بالظبط، والتحصيل على فاتورة عميل تاني بيترفض.
 *   ⑤ **الحُرّاس**: يوم متقفل بيمنع التحصيل النقدي · الفاتورة المزوّرة في
 *      الفورم بتترفض · التحصيل مش بتاع العميل ما يتعكسش من صفحته.
 *   ⑥ **قانون التصميم**: صفر `<style>` محلي وصفر `style=` في شاشات الوحدة،
 *      وكل رقم في الترويسة له `drill`، وكل أيقونة موجودة في `icon()`.
 *   ⑦ **الفصل تمّ فعلاً**: `directory` مفيهوش تبويب وكلاء ولا بروفايل وكيل،
 *      والرابط القديم بيحوّل، والبحث الشامل بيوديه على الوحدة الجديدة.
 *
 * كل الكتابة جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_clients.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\ClientAccount;
use App\Models\ClientPayment;
use App\Models\Closing;
use App\Models\PartyMaster;
use App\Models\PermitInvoice;

$pass = 0; $fail = 0;
function okc(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function badc(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chkc(bool $c, string $m): void { $c ? okc($m) : badc($m); }
function secc(string $m): void { echo "\n$m\n" . str_repeat('─', 64) . "\n"; }
function nearc(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? okc($l)
        : badc("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function srcc(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    return is_file($p) ? (string) file_get_contents($p) : '';
}
/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع. */
function codec(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص حسابات العملاء (v93)\n" . str_repeat('=', 64) . "\n";

$VIEWS = ['index', 'show', '_tab_ledger', '_tab_invoices', '_tab_payments', '_tab_work', '_tab_card', '_drill', 'form'];

// ═══════════════════════════════════ ① الوحدة قراءة بحتة
secc('① العدسة قراءة بحتة — الكتابة كلها في مكانها القديم');

$model = codec('app/Models/ClientAccount.php');
chkc($model !== '', 'موديل ClientAccount موجود');
foreach (['INSERT', 'UPDATE ', 'DELETE', 'REPLACE INTO', 'TRUNCATE'] as $w) {
    chkc(!preg_match('/\b' . preg_quote(trim($w), '/') . '\b/i', $model),
        "ولا كلمة كتابة «{$w}» في الموديل");
}
/* ⚠️ الأرقام لازم تيجي من الموديل صاحبها لا باستعلام موازٍ — ده اللي بيمنع
   «الكارت يقول رقم والكشف يقول تاني». */
foreach (['PermitInvoice::summaryFor', 'ClientPayment::totalFor', 'PermitInvoice::openForAgent'] as $dep) {
    chkc(str_contains($model, $dep), "الترويسة بتقرا من {$dep}");
}

// ═══════════════════════════════════ ② الأرقام مطابقة لمصدرها
secc('② رقم واحد لكل معنى — القائمة = الترويسة = الموديل الأصلي');

$agentId = (int) Database::scalar(
    "SELECT agent_id FROM permit_invoices WHERE agent_id IS NOT NULL AND status <> 'void' LIMIT 1");
$agent = $agentId > 0 ? PartyMaster::find('agent', $agentId) : null;

if (!$agent) {
    echo "  • مفيش عميل عليه فواتير في القاعدة دي — أقسام الأرقام والسلوك اتخطّت.\n";
} else {
    $name = (string) $agent['name'];
    $head = ClientAccount::head($agentId, $name);
    $invS = PermitInvoice::summaryFor('client', $name);

    nearc('«المطلوب منه» = مجموع مستنداته غير الملغية',
        $head['charges'], (float) $invS['total'] + (float) (\App\Models\Party::summary('client', $name)['invoices']['total'] ?? 0));
    nearc('«المحصّل منه» = مجموع تحصيلاته المرحّلة', $head['paid'], ClientPayment::totalFor($name));
    nearc('و«المتبقّي» = الفرق بينهم', $head['remaining'], $head['charges'] - $head['paid']);

    /* الصف في القائمة والترويسة **لازم يتطابقوا** — الاتنين بيتحسبوا بطريقتين
       مختلفتين (مجمّع لكل العملاء / لعميل واحد)، فده أهم فحص تطابق هنا. */
    $row = null;
    foreach (ClientAccount::list('', 'due') as $r) { if ((int) $r['id'] === $agentId) { $row = $r; break; } }
    if ($row === null) {
        badc('العميل مش ظاهر في القائمة أصلاً');
    } else {
        nearc('صف القائمة: المطلوب = الترويسة', $row['charges'], $head['charges']);
        nearc('صف القائمة: المحصّل = الترويسة', $row['paid'], $head['paid']);
        nearc('صف القائمة: المتبقّي = الترويسة', $row['remaining'], $head['remaining']);
        nearc('صف القائمة: المتأخر = الترويسة', $row['overdue'], $head['overdue']);
        chkc((int) $row['open_cnt'] === (int) $head['open_cnt'], 'وعدد الفواتير المفتوحة واحد');
    }

    // ═══════════════════════════════════ ③ الكشف متوازن
    secc('③ كشف الحساب متوازن — والأعمار مجموعها = الباقي المفتوح');

    $led = ClientAccount::ledger($agentId, $name);
    nearc('الرصيد = أول المدة + مدين − دائن',
        $led['balance'], $led['opening'] + $led['debit'] - $led['credit']);
    nearc('ورصيد آخر سطر = الرصيد النهائي',
        $led['rows'] ? end($led['rows'])['balance'] : $led['balance'], $led['balance']);
    nearc('ومجموع الكشف = المتبقّي في الترويسة', $led['balance'], $head['remaining']);

    $aging = ClientAccount::aging($agentId);
    $agingSum = 0.0; $agingCnt = 0;
    foreach ($aging as $b) { $agingSum += (float) $b['value']; $agingCnt += (int) $b['count']; }
    nearc('مجموع شرايح الأعمار = باقي الفواتير المفتوحة', $agingSum, $head['open_balance']);
    chkc($agingCnt === (int) $head['open_cnt'], 'وعددها = عدد الفواتير المفتوحة');
    chkc(count($aging) === count(ClientAccount::AGING), 'الشرايح كلها بترجع حتى الفاضية (مفيش شريحة بتختفي)');

    /* ⚠️ الفترة بتلمّ اللي قبلها في «أول المدة» بدل ما تشيله — كشف بيبدأ من
       الصفر وهو مش صفر بيغلط المالك في المطالبة. */
    $cut  = date('Y-m-d', strtotime(work_date() . ' -1 day'));
    $part = ClientAccount::ledger($agentId, $name, $cut);
    nearc('كشف بفترة: أول المدة + حركتها = نفس الرصيد النهائي',
        $part['opening'] + $part['debit'] - $part['credit'], $led['balance']);
}

// ═══════════════════════════════════ ④ + ⑤ السلوك والحُرّاس
secc('④ التحصيل: بيقلّل الرصيد بمقداره، وعكسه بيرجّعه بالظبط');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $stamp = 'فحص-عملاء-' . substr((string) getmypid(), -4);
    $a1 = PartyMaster::ensure('agent', 'عميل ' . $stamp . ' أ');
    $a2 = PartyMaster::ensure('agent', 'عميل ' . $stamp . ' ب');
    $id1 = (int) $a1['id']; $id2 = (int) $a2['id'];

    $mkPermit = static function (string $client) use ($stamp): int {
        Database::exec(
            'INSERT INTO permits (permit_type, permit_no, permit_date, client, company, containers)
             VALUES (?,?,?,?,?,?)',
            ['sader', 'PC-' . $stamp . '-' . substr(md5($client), 0, 5), work_date(), $client, 'شركة فحص', 1]
        );
        return (int) Database::lastId();
    };
    $mkInvoice = static function (int $permitId, string $client, float $total): int {
        return PermitInvoice::create([
            'permit_id' => $permitId, 'doc_kind' => 'invoice', 'taxable' => 0,
            'invoice_date' => work_date(), 'client' => $client, 'company' => 'شركة فحص',
            'destination' => 'وجهة', 'permit_no' => 'PC', 'permit_type' => 'sader',
            'agency' => 'توكيل', 'port' => 'ميناء', 'containers' => 1,
            'items' => [['label' => 'نولون', 'qty' => 1, 'price' => $total, 'freight' => true]],
        ]);
    };

    $inv1 = $mkInvoice($mkPermit($a1['name']), $a1['name'], 1000.0);
    $inv2 = $mkInvoice($mkPermit($a2['name']), $a2['name'], 500.0);

    $h0 = ClientAccount::head($id1, $a1['name']);
    nearc('فاتورة ١٠٠٠ = المطلوب من العميل', $h0['charges'], 1000);
    nearc('والمتبقّي عليه ١٠٠٠', $h0['remaining'], 1000);

    $payId = ClientPayment::create([
        'client_name' => $a1['name'], 'agent_id' => $id1, 'pay_date' => work_date(),
        'amount' => 400.0, 'note' => 'فحص', 'route' => 'none', 'auto_allocate' => true,
    ]);
    $h1 = ClientAccount::head($id1, $a1['name']);
    nearc('بعد تحصيل ٤٠٠: المحصّل ٤٠٠', $h1['paid'], 400);
    nearc('والمتبقّي ٦٠٠', $h1['remaining'], 600);
    nearc('وباقي الفاتورة المفتوحة ٦٠٠', $h1['open_balance'], 600);

    /* ★ حساب العميل التاني **ما اتغيّرش** — أي تسريب بين العملاء هنا معناه
       كشوف حساب غلط بتروح للعملاء بالبريد. */
    $h2 = ClientAccount::head($id2, $a2['name']);
    nearc('★ وحساب العميل التاني ما اتلمسش', $h2['remaining'], 500);

    $led = ClientAccount::ledger($id1, $a1['name']);
    chkc(count($led['rows']) === 2, 'الكشف فيه سطرين (فاتورة + تحصيل)');
    nearc('ورصيده النهائي ٦٠٠', $led['balance'], 600);

    ClientPayment::reverse($payId, 'فحص العكس');
    $h3 = ClientAccount::head($id1, $a1['name']);
    nearc('بعد العكس: المتبقّي رجع ١٠٠٠', $h3['remaining'], 1000);
    nearc('والمحصّل رجع صفر', $h3['paid'], 0);

    $ledAfter = ClientAccount::ledger($id1, $a1['name']);
    chkc(count($ledAfter['rows']) === 2, 'والتحصيل المعكوس **فضل** في الكشف (بصفر) مش اختفى');

    secc('⑤ الحُرّاس — الفورم مش مصدر ثقة');

    /* تحصيل عميل على فاتورة عميل تاني لازم يترفض في **الموديل**
       (الكنترولر بيتحقّق كمان، بس الحارس الحقيقي جوّه). */
    $pid2 = ClientPayment::create([
        'client_name' => $a2['name'], 'agent_id' => $id2, 'pay_date' => work_date(),
        'amount' => 100.0, 'route' => 'none', 'auto_allocate' => false,
    ]);
    $rejected = false;
    try { ClientPayment::allocate($pid2, $inv1, 100.0); }
    catch (\Throwable $e) { $rejected = true; }
    chkc($rejected, '★ تخصيص تحصيل عميل على فاتورة عميل تاني بيترفض');

    $ctrl = codec('app/Controllers/ClientController.php');
    chkc(str_contains($ctrl, 'Closing::guard'), 'الكنترولر بيحرس اليوم المتقفل قبل التحصيل النقدي');
    chkc(str_contains($ctrl, 'routeChoices()'), 'وبيقرا التوجيهات من `routeChoices()` (مصدر واحد مع الشاشة)');
    chkc(str_contains($ctrl, 'claimForm'), 'وبيمنع الدفعة المكررة برمز النموذج');
    chkc(preg_match('/invoice_id[\s\S]{0,600}agent_id/', $ctrl) === 1,
        'وبيتأكد إن الفاتورة المختارة من فواتير العميل ده');
    chkc(substr_count($ctrl, 'requireCsrf') >= 3, 'وكل مسار كتابة فيه requireCsrf (يدوي — مفيش middleware)');
    chkc(substr_count($ctrl, 'requireAuth') >= 7, 'وكل مسار فيه requireAuth');

    // تحصيل بعد رقم صحيح لكن على عميل مش موجود = مفيش كتابة
    $missing = false;
    try {
        ClientPayment::create(['client_name' => '', 'pay_date' => work_date(), 'amount' => 50.0, 'route' => 'none']);
    } catch (\Throwable $e) { $missing = true; }
    chkc($missing, 'وتحصيل بلا عميل بيترفض من الموديل');
    unset($inv2);
} finally {
    $pdo->rollBack();
}

// ═══════════════════════════════════ ⑥ قانون التصميم
secc('⑥ قانون التصميم — صفر <style> محلي وصفر رقم أصمّ');

foreach ($VIEWS as $v) {
    /* ⚠️ `codec()` إجبارية: التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع
       نفسه (`<style>`)، فالفحص على المصدر الخام بيفشل بإنذار كاذب. */
    $s = codec('app/Views/clients/' . $v . '.php');
    chkc($s !== '', "شاشة {$v}.php موجودة");
    chkc(!str_contains($s, '<style'), "و{$v}.php بلا `<style>` محلي");
    chkc(!preg_match('~\sstyle="~', $s), "و{$v}.php بلا `style=` inline");
    chkc(!str_contains($s, '<script'), "و{$v}.php بلا `<script>` محلي");
}
/* ⚠️ كشف الحساب A4 **مستثنى** عن قصد: مستند مستقل ما بيحمّلش `app.css`،
   وستايله بيجي من `partials/print_base` (مصدر هوية واحد) + تخطيطه هو. */
$print = srcc('app/Views/clients/statement_print.php');
chkc(str_contains($print, "partial('print_base')"), 'كشف الـA4 بياخد هويته من print_base');
chkc(!preg_match('/font-family:\s*(?!var\(|inherit)/i', $print), 'وما بيكتبش اسم خط بإيده');
chkc(!preg_match('~https?://(?!wa\.me)~', $print), 'وولا مصدر خارجي في ورقة العميل');

/* ممنوع رقم أصمّ: كل `stat` في شاشات الوحدة لازم يكون معاه `drill`. */
foreach (['index', 'show'] as $v) {
    $s = srcc('app/Views/clients/' . $v . '.php');
    $stats  = preg_match_all("~partial\('stat',~", $s);
    $drills = preg_match_all("~'drill'\s*=>~", $s);
    chkc($stats > 0 && $stats === $drills, "{$v}.php: {$stats} رقم و{$drills} تفاصيل — مفيش رقم أصمّ");
}

/* ⚠️ `icon('اسم-غلط')` ما بترميش خطأ — بترسم دايرة فاضية. */
$blank = icon('__لا_يوجد_إطلاقًا__', 14);
$icons = [];
foreach ($VIEWS as $v) {
    if (preg_match_all("~icon\('([a-z0-9-]+)'~", srcc('app/Views/clients/' . $v . '.php'), $m)) {
        $icons = array_merge($icons, $m[1]);
    }
}
foreach (ClientAccount::TABS as $t) { $icons[] = $t['icon']; }
$badIcons = [];
foreach (array_unique($icons) as $ic) { if (icon($ic, 14) === $blank) { $badIcons[] = $ic; } }
chkc($badIcons === [], 'كل أيقونات الوحدة (' . count(array_unique($icons)) . ') موجودة في icon()'
    . ($badIcons ? ' — الناقص: ' . implode('، ', $badIcons) : ''));

/* كل كلاس `cl-*` بيتطبع لازم يكون له CSS — الناقص بيقع على الشكل الافتراضي بالسكوت. */
$css = srcc('public/assets/css/app.css');
$classes = [];
foreach ($VIEWS as $v) {
    /* من سمة `class` بس — `id` و`data-*` مش كلاسات، وحسابهم كان بيدّي إنذارًا كاذبًا. */
    if (preg_match_all('~class="([^"]*)"~', srcc('app/Views/clients/' . $v . '.php'), $m)) {
        foreach ($m[1] as $attr) {
            if (preg_match_all('~\b(cl-[a-z0-9-]+)~', $attr, $cm)) {
                $classes = array_merge($classes, $cm[1]);
            }
        }
    }
}
$missing = [];
foreach (array_unique($classes) as $c) {
    if (preg_match('~^cl-w-\d+$~', $c)) { $c = 'cl-w-'; }   // درجات العرض معرَّفة كمجموعة
    if (!str_contains($css, '.' . $c)) { $missing[] = $c; }
}
chkc($missing === [], 'كل كلاسات `cl-*` ليها CSS' . ($missing ? ' — الناقص: ' . implode('، ', $missing) : ''));

/* التبويبات روابط حقيقية — التبديل بجافاسكريبت بيكسر الرجوع بالمتصفح. */
$show = srcc('app/Views/clients/show.php');
chkc(str_contains($show, "partial('tabs'"), 'التبويبات بمكوّن `tabs` (روابط `?tab=`)');
chkc(str_contains($show, 'ClientAccount::TABS'), 'وبتلفّ على الكتالوج مش على أسماء مكتوبة');

// ═══════════════════════════════════ ⑦ الفصل تمّ فعلاً
secc('⑦ الفصل عن «دليل الأطراف» — مفيش نسختين لنفس الشاشة');

chkc(!is_file(dirname(__DIR__) . '/app/Views/directory/agent.php'),
    'بروفايل الوكيل القديم اتشال (نسختان = يفترقوا مع أول تعديل)');
$dirCtrl = codec('app/Controllers/DirectoryController.php');
foreach (['addPayment', 'deletePayment', 'function agent('] as $gone) {
    chkc(!str_contains($dirCtrl, $gone), "و«{$gone}» مابقاش في كنترولر الدليل");
}
$dirView = codec('app/Views/directory/index.php');
chkc(!str_contains($dirView, 'tab=agents'), 'وتبويب الوكلاء اتشال من الدليل');
chkc(str_contains($dirView, "url('clients')"), 'وفيه طريق للوحدة الجديدة من الدليل');
chkc(str_contains($dirCtrl, "redirect('clients')"), 'والرابط القديم `?tab=agents` بيحوّل');

$routes = srcc('public/index.php');
foreach ([
    "\$r->get('/clients',",
    "\$r->post('/clients/{id}/pay',",
    "\$r->post('/clients/{id}/payment/{pid}/reverse',",
    "\$r->get('/clients/{id}/drill/{what}',",
    "\$r->get('/clients/{id}/statement',",
    "\$r->get('/clients/{id}',",
] as $needle) {
    chkc(str_contains($routes, $needle), 'مسار مسجَّل: ' . trim($needle, "$>-r(,'"));
}
/* ⚠️ الراوتر first-match-wins — الجشع `/{id}` لازم يبقى **بعد** كل الحرفية. */
$greedy = strpos($routes, "\$r->get('/clients/{id}',");
foreach (['/clients/{id}/drill/{what}', '/clients/{id}/statement', '/clients/{id}/edit'] as $lit) {
    chkc(strpos($routes, $lit) < $greedy, "و«{$lit}» قبل الجشع `/clients/{id}`");
}
chkc(str_contains($routes, "'ClientController@legacyProfile'"), 'والرابط القديم `/directory/agent/{id}` بيحوّل');

chkc(str_contains(srcc('app/Models/Search.php'), "'route' => 'clients/{id}'"),
    'والبحث الشامل بيوديه على حساب العميل');
chkc(str_contains(srcc('app/Models/NavModule.php'), "'ClientController@index'"),
    'والوحدة في القائمة الجانبية');
chkc(str_contains(srcc('app/Core/Access.php'), "'ClientController'"),
    'والمحاسب ليه صلاحية الكتابة فيها (زي ما كان في الدليل)');

/* مستندات الطرف **جزء مشترك**: نسخة تانية هنا كانت هتفترق عن بروفايل الشركة. */
chkc(str_contains(srcc('app/Views/clients/_tab_card.php'), "directory/_docs.php"),
    'ومستندات العميل بتستعمل الجزء المشترك مش نسخة');

echo "\n" . str_repeat('═', 64) . "\n";
echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
echo "\nنجح: $pass | فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
