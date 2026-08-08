<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص رحلة الشحنة (v62) — تايم لاين حالة الإذن التشغيلية.
 *
 * القواعد المحروسة هنا:
 *   ① **العزل بالبناء** — ولا عمود مالي، وولا ملف خزنة بيعرف الجدول أصلاً،
 *      ورصيد الخزنة وعهد المناديب قبل = بعد تسجيل حالات حقيقية.
 *   ② **الفورم مش مصدر ثقة** — مرحلة من نوع تاني أو بوليصة من إذن تاني
 *      بتترفض في **الموديل** لا في الكنترولر.
 *   ③ **قاعدة الحالة** — أبعد مرحلة اتسجّلت، واللي قبلها بلا حدث «متخطّاة»
 *      و**ما بنخترعلهاش حدث**.
 *   ④ **المفتاح الفريد شغّال** — تسجيل نفس المرحلة تاني بيحدّث مش بيكرّر.
 *   ⑤ **قاعدة v35** — ولا مبلغ بيخصّ العميل في شاشة المندوب ولا في الإشعار.
 *   ⑥ **الأيقونة في الخريطتين** — `icon()` للصفحة و`app.js` للجرس (الناقصة
 *      بتقع على شكل افتراضي بالسكوت).
 *   ⑦ **كل كلاس بيتطبع له CSS** (مصيدة `.table-mobile-scroll` الموثَّقة)،
 *      وكلاسات البوابة في `m.css` لأن `/m` ما بتحمّلش `app.css`.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v62.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\PermitTrack;
use App\Models\RepCustody;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function src(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
}
/** كود بلا تعليقات — عشان تعليق بيوثّق القاعدة ما يديش نجاحًا/فشلًا كاذبًا. */
function code_only(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
function has(string $l, string $file, string $needle): void {
    str_contains(src($file), $needle) ? ok($l) : bad("{$l} — «{$needle}» مش موجودة في {$file}");
}

echo "فحص رحلة الشحنة (v62)\n" . str_repeat('=', 62) . "\n";

if (!PermitTrack::ready()) {
    echo "\n⚠️ جدول permit_track_events مش موجود — شغّل php bin/migrate_v62.php الأول.\n";
    exit(1);
}

$treasuryBefore = Closing::currentBalance();
$repsBefore     = RepCustody::available() ? RepCustody::totalOutstanding() : 0.0;

// ══════════════════════════════════════════ ① العزل بالبناء
sec('① العزل بالبناء — ولا ملف خزنة بيعرف الوحدة');

/* ⚠️ ده الحارس اللي بيخلّي العزل «بالبناء» مش «باستثناء بنفتكره».
   نفس نمط `rep_misc_expenses` (v39) و`car_expenses` (v54). */
$safeFiles = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/DailyReview.php',
    'app/Models/Expense.php', 'app/Models/Metrics.php', 'app/Models/RepCustody.php',
    'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php',
    'app/Calc.php',
];
foreach ($safeFiles as $f) {
    $code = code_only($f);
    (!str_contains($code, 'permit_track_events') && !str_contains($code, 'PermitTrack'))
        ? ok(basename($f) . ' عمره ما سمع عن الوحدة')
        : bad(basename($f) . ' بيذكر الوحدة — العزل بالبناء اتكسر');
}

$cols = array_column(Database::all(
    "SELECT column_name cn FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'permit_track_events'"), 'cn');
$money = array_values(array_filter($cols, function ($c) {
    foreach (['amount','custody','freight','debt','paid','price','total','balance','visa'] as $b) {
        if (str_contains((string) $c, $b)) { return true; }
    }
    return false;
}));
!$money ? ok('ولا عمود مالي في الجدول (' . count($cols) . ' عمود)')
        : bad('أعمدة مالية: ' . implode(', ', $money));

// ══════════════════════════════════════════ ② تعريف المراحل
sec('② تعريف المراحل');

count(PermitTrack::stages('sader')) === 8 ? ok('الصادر ٨ مراحل') : bad('عدد مراحل الصادر اتغيّر');
count(PermitTrack::stages('warid')) === 7 ? ok('الوارد ٧ مراحل') : bad('عدد مراحل الوارد اتغيّر');
PermitTrack::normType('حاجة غريبة') === 'sader'
    ? ok('النوع المجهول بيرجع للصادر (زي Permit::theme)') : bad('normType مش بترجع للصادر');

/* ⚠️ المفاتيح مسبوقة بالنوع — لو اتشالت البادئة، حدث من نوع بيتلزق على
   مرحلة النوع التاني ويدّي تايم لاين يقرا صح وهو غلط. */
$mixed = 0;
foreach (PermitTrack::stages('sader') as $s) {
    if (!str_starts_with($s['key'], 'sader_')) { $mixed++; }
    if (PermitTrack::stageMeta('warid', $s['key']) !== null) { $mixed++; }
}
foreach (PermitTrack::stages('warid') as $s) {
    if (!str_starts_with($s['key'], 'warid_')) { $mixed++; }
}
$mixed === 0 ? ok('مفاتيح كل نوع معزولة عن التاني') : bad("فيه {$mixed} مفتاح متداخل بين النوعين");

$term = 0;
foreach (['sader', 'warid'] as $t) {
    $list = PermitTrack::stages($t);
    if (!empty(end($list)['terminal'])) { $term++; }
}
$term === 2 ? ok('آخر مرحلة في كل نوع موسومة terminal') : bad('مرحلة النهاية مش موسومة');

// ⚠️ كل أيقونة مستعملة لازم تكون في `icon()` — الناقصة بتقع على دايرة فاضية.
$missingIcon = [];
foreach (['sader', 'warid'] as $t) {
    foreach (PermitTrack::stages($t) as $s) {
        if (icon($s['icon'], 14) === icon('__لا_يوجد__', 14)) { $missingIcon[] = $s['icon']; }
    }
}
!$missingIcon ? ok('كل أيقونات المراحل موجودة في icon()')
              : bad('أيقونات ناقصة: ' . implode(', ', array_unique($missingIcon)));

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    // ══════════════════════════════════════ ③ بيئة الاختبار
    sec('③ تسجيل حقيقي على إذن صادر');

    $day = '2037-09-14';   // بعيد عن أي إقفال أو بيانات حقيقية
    Database::exec(
        "INSERT INTO permits (permit_no, permit_type, permit_date, status, company, client)
         VALUES ('__tr_sader', 'sader', ?, 'open', '__tr_co', '__tr_cl')", [$day]);
    $pid = Database::lastId();
    Database::exec(
        "INSERT INTO permits (permit_no, permit_type, permit_date, status)
         VALUES ('__tr_warid', 'warid', ?, 'open')", [$day]);
    $otherPid = Database::lastId();

    Database::exec(
        "INSERT INTO bawalis (bill_number, bill_date, permit_id, car_no, driver, is_legacy)
         VALUES ('__tr_b1', ?, ?, 'ط ف ر 1111', '__tr_drv', 0)", [$day, $pid]);
    $billA = Database::lastId();
    Database::exec(
        "INSERT INTO bawalis (bill_number, bill_date, permit_id, car_no, is_legacy)
         VALUES ('__tr_b2', ?, ?, 'ط ف ر 2222', 0)", [$day, $pid]);
    $billB = Database::lastId();
    Database::exec(
        "INSERT INTO bawalis (bill_number, bill_date, permit_id, is_legacy)
         VALUES ('__tr_b3', ?, ?, 0)", [$day, $otherPid]);
    $billOther = Database::lastId();

    $stages = PermitTrack::stages('sader');

    $s0 = PermitTrack::summary($pid, 'sader');
    ($s0['current'] === null && $s0['percent'] === 0 && !$s0['started'])
        ? ok('إذن جديد: مفيش مرحلة والنسبة صفر') : bad('حالة الإذن الجديد غلط');
    ($s0['next']['key'] ?? '') === $stages[0]['key']
        ? ok('والمرحلة الجاية = أول مرحلة') : bad('المرحلة الجاية الأولى غلط');

    PermitTrack::record($pid, $stages[0]['key'], 0, PermitTrack::SRC_OFFICE, 'اختبار', $day);
    $s1 = PermitTrack::summary($pid, 'sader');
    ($s1['current_key'] ?? '') === $stages[0]['key'] ? ok('اتسجّلت المرحلة الأولى') : bad('التسجيل ما اشتغلش');
    $s1['percent'] === (int) round(1 / 8 * 100) ? ok('النسبة = موقع المرحلة (12%)') : bad('النسبة غلط: ' . $s1['percent']);

    // ══════════════════════════════════════ ④ قاعدة الحالة
    sec('④ قاعدة الحالة: أبعد مرحلة اتسجّلت + «متخطّاة» بلا حدث مخترَع');

    PermitTrack::record($pid, $stages[4]['key'], 0, PermitTrack::SRC_OFFICE, null, $day);   // قفزة
    $s2 = PermitTrack::summary($pid, 'sader');
    ($s2['current_key'] ?? '') === $stages[4]['key']
        ? ok('القفزة خلّت الحالة على المرحلة الخامسة') : bad('الحالة ما اتحرّكتش مع القفزة');

    $states = array_column($s2['timeline'], 'state', 'key');
    ($states[$stages[0]['key']] ?? '') === 'done'    ? ok('الأولى done') : bad('الأولى مش done');
    ($states[$stages[1]['key']] ?? '') === 'skipped' ? ok('التانية «متخطّاة»') : bad('التانية مش skipped');
    ($states[$stages[4]['key']] ?? '') === 'current' ? ok('الخامسة current') : bad('الخامسة مش current');
    ($states[$stages[5]['key']] ?? '') === 'pending' ? ok('السادسة pending') : bad('السادسة مش pending');

    /* ⚠️ أهم فحص في القاعدة: «متخطّاة» **ما بيتكتبلهاش حدث** — التايم لاين
       شهادة على اللي اتسجّل فعلاً، وتوليد حدث وهمي بيخلّي «إمتى وصلت العميل؟»
       يجاوب برقم محدش كتبه. */
    (int) Database::scalar('SELECT COUNT(*) FROM permit_track_events WHERE permit_id = ?', [$pid]) === 2
        ? ok('★ المرحلة المتخطّاة مالهاش حدث مخزَّن (٢ صف بس)')
        : bad('اتكتبت أحداث وهمية للمراحل المتخطّاة');

    // ══════════════════════════════════════ ⑤ المفتاح الفريد
    sec('⑤ المفتاح الفريد: إعادة التسجيل بتحدّث مش بتكرّر');

    PermitTrack::record($pid, $stages[4]['key'], 0, PermitTrack::SRC_OFFICE, 'ملاحظة تانية', $day);
    (int) Database::scalar(
        'SELECT COUNT(*) FROM permit_track_events WHERE permit_id = ? AND bill_id = 0 AND stage_key = ?',
        [$pid, $stages[4]['key']]) === 1
        ? ok('صف واحد بعد إعادة التسجيل') : bad('اتكرّر الصف — المفتاح الفريد مش شغّال');
    Database::scalar(
        'SELECT note FROM permit_track_events WHERE permit_id = ? AND bill_id = 0 AND stage_key = ?',
        [$pid, $stages[4]['key']]) === 'ملاحظة تانية'
        ? ok('والملاحظة اتحدّثت') : bad('الملاحظة ما اتحدّثتش');

    // ══════════════════════════════════════ ⑥ الفورم مش مصدر ثقة
    sec('⑥ الفورم مش مصدر ثقة — الحُرّاس في الموديل');

    $reject = function (callable $fn, string $why) {
        try { $fn(); bad("اتقبل واللازم يترفض: {$why}"); }
        catch (\RuntimeException $e) { ok("اترفض: {$why} — «" . mb_substr($e->getMessage(), 0, 40) . '»'); }
    };
    $reject(fn() => PermitTrack::record($pid, 'warid_gate_out', 0),
        'مرحلة من نوع تاني على إذن صادر');
    $reject(fn() => PermitTrack::record($pid, '__مفتاح_مخترع__', 0),
        'مفتاح مرحلة مش موجود خالص');
    $reject(fn() => PermitTrack::record($pid, $stages[1]['key'], $billOther),
        'بوليصة من إذن تاني');
    $reject(fn() => PermitTrack::record(99999999, $stages[1]['key'], 0),
        'إذن مش موجود');

    // ══════════════════════════════════════ ⑦ رحلة البوليصة
    sec('⑦ رحلة كل عربية على حِدة');

    PermitTrack::record($pid, $stages[6]['key'], $billA, PermitTrack::SRC_REP, null, $day);
    $bills = PermitTrack::billSummaries($pid, 'sader');
    $byId = [];
    foreach ($bills as $b) { $byId[(int) $b['bill']['id']] = $b; }

    ($byId[$billA]['current_key'] ?? '') === $stages[6]['key']
        ? ok('البوليصة أ وصلت المرحلة السابعة') : bad('حالة البوليصة أ غلط');
    ($byId[$billB]['current_key'] ?? '') === $stages[4]['key']
        ? ok('★ والبوليصة ب لسه على مرحلة الإذن العامة (الحدث الخاص ما أثّرش عليها)')
        : bad('حدث بوليصة أ اتسرّب للبوليصة ب');
    ($byId[$billOther] ?? null) === null
        ? ok('وبوليصة الإذن التاني مش في القايمة') : bad('بوليصة إذن تاني ظهرت');

    // حالة الإذن = أبعد مرحلة على أي حاجة فيه
    ((PermitTrack::summary($pid, 'sader')['current_key'] ?? '') === $stages[6]['key'])
        ? ok('وحالة الإذن اتحرّكت لأبعد مرحلة على أي بوليصة') : bad('حالة الإذن ما اتحرّكتش');

    // ══════════════════════════════════════ ⑧ التسجيل التلقائي
    sec('⑧ تسجيل النظام: بيسجّل الناقص وما بيدهسش المكتوب');

    PermitTrack::systemMark($pid, $stages[0]['key'], 0, 'من النظام');
    Database::scalar(
        'SELECT source FROM permit_track_events WHERE permit_id = ? AND bill_id = 0 AND stage_key = ?',
        [$pid, $stages[0]['key']]) === 'office'
        ? ok('★ systemMark ما دهستش تسجيل المكتب') : bad('النظام دهس تسجيل المكتب');

    PermitTrack::systemMark($pid, $stages[1]['key'], $billB, 'اتسجّلت بوليصة');
    Database::scalar(
        'SELECT source FROM permit_track_events WHERE permit_id = ? AND bill_id = ? AND stage_key = ?',
        [$pid, $billB, $stages[1]['key']]) === 'system'
        ? ok('وسجّل المرحلة الناقصة بمصدر system') : bad('systemMark ما سجّلش الناقص');

    // ══════════════════════════════════════ ⑨ التراجع
    sec('⑨ التراجع');

    PermitTrack::undo($pid, $stages[6]['key'], $billA)
        ? ok('التراجع شال الحدث') : bad('التراجع ما اشتغلش');
    !PermitTrack::undo($pid, $stages[6]['key'], $billA)
        ? ok('وتراجع تاني على نفس المرحلة بيرجّع false') : bad('التراجع بيرجّع true على حاجة مش موجودة');

    // ══════════════════════════════════════ ⑩ ★ العزل المالي فعليًّا
    sec('⑩ ★ العزل المالي — ولا مليم اتحرّك');

    near('رصيد خزنة المكتب زي ما هو بالمليم', Closing::currentBalance(), $treasuryBefore);
    if (RepCustody::available()) {
        near('وإجمالي عهد المناديب زي ما هو', RepCustody::totalOutstanding(), $repsBefore);
    }
    $view = Closing::dayView($day);
    ((float) ($view['expenses'] ?? 0)) == 0.0
        ? ok('ومصروفات تقفيل اليوم صفر (مفيش بند اتولّد)') : bad('الوحدة ولّدت مصروفًا في التقفيل');

    // ══════════════════════════════════════ ⑪ الأداء
    sec('⑪ الأداء: summaryFor استعلامان للصفحة كلها');

    $ids = array_column(Database::all('SELECT id FROM permits ORDER BY id DESC LIMIT 60'), 'id');
    $t0 = microtime(true);
    $map = PermitTrack::summaryFor($ids);
    $ms = (microtime(true) - $t0) * 1000;
    $ms < 120 ? ok('حالة ' . count($ids) . ' إذن في ' . round($ms, 1) . 'ms')
              : bad('بطيئة: ' . round($ms, 1) . 'ms — يمكن رجعت حلقة استعلامات');
    (isset($map[$pid]) && ($map[$pid]['current_key'] ?? '') === PermitTrack::summary($pid, 'sader')['current_key'])
        ? ok('★ ونتيجتها مطابقة لـsummary() المفردة') : bad('summaryFor بتقول رقمًا وsummary تقول تاني');
    !isset($map[$otherPid])
        ? ok('والإذن بلا أي تحديث مالوش مدخل (مفيش شارة فاضية)') : bad('إذن بلا تحديث رجع في الخريطة');

    // ══════════════════════════════════════ ⑫ الرندر الفعلي
    sec('⑫ رندر الشاشات فعليًّا');

    $render = function (string $view, array $data): string {
        extract($data, EXTR_SKIP);
        ob_start();
        include dirname(__DIR__) . '/app/Views/' . $view . '.php';
        return (string) ob_get_clean();
    };

    $events = PermitTrack::events($pid);
    $html = $render('permits/_journey', [
        'p' => Database::row('SELECT * FROM permits WHERE id = ?', [$pid]),
        'pid' => $pid, 'mode' => 'view', 'isClosed' => false, 'trackReady' => true,
        'journey' => PermitTrack::summary($pid, 'sader', 0, $events),
        'journeyBills' => PermitTrack::billSummaries($pid, 'sader', $events),
    ]);
    !preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html)
        ? ok('لوح الرحلة بيرندر بلا أي خطأ PHP') : bad('خطأ PHP في لوح الرحلة');
    substr_count($html, 'pj-step') >= 8 ? ok('وبيرسم الـ٨ مراحل') : bad('المراحل ما اترسمتش');
    str_contains($html, 'pj-bills') ? ok('وقسم رحلة كل عربية موجود') : bad('قسم العربيات ناقص');
    str_contains($html, 'permits/' . $pid . '/track')
        ? ok('وفورم التسجيل موجّه للمسار الصح') : bad('مسار التسجيل غلط');

    /* ⚠️ قاعدة v35: ممنوع أي مبلغ بيخصّ العميل يوصل شاشة المندوب. */
    $permitCard = [
        'id' => $pid, 'no' => '__tr_sader', 'type' => 'sader', 'type_label' => 'إذن',
        'company' => '__tr_co', 'client' => '__tr_cl',
    ];
    $mHtml = $render('m/track', [
        'permit' => $permitCard,
        'journey' => PermitTrack::summary($pid, 'sader', 0, $events),
        'bills' => PermitTrack::billSummaries($pid, 'sader', $events),
    ]);
    !preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $mHtml)
        ? ok('شاشة المندوب بترندر بلا أي خطأ PHP') : bad('خطأ PHP في شاشة المندوب');
    substr_count($mHtml, 'mt-step') >= 8 ? ok('وبترسم المراحل') : bad('مراحل شاشة المندوب ناقصة');
    !preg_match('/actual_amount|actual_unit|ج\.م/u', $mHtml)
        ? ok('★ وولا مبلغ فيها (قاعدة v35)') : bad('مبلغ وصل شاشة المندوب');
    str_contains($mHtml, 'assets/css/m.css')
        ? ok('وبتحمّل m.css (البوابة برّه تخطيط المكتب)') : bad('شاشة المندوب بتحمّل ملف غلط');

    // ══════════════════════════════════════ ⑫ب نصّ الإشعار فعليًّا
    sec('⑫ب الإشعار: بيتبعت فعلاً وبيقول حاجة مفيدة');

    /* بنبعت إشعارات حقيقية جوّه المعاملة وبنقرا الصف المتسجّل — رندر النص
       بس ما بيمسكش الفرق بين «اتسجّل» و«اتخطّى بلا مستلمين». والإرسال للموبايل
       مكتوم في CLI فمفيش أي رنّة بتطلع (PushNotify::enabled = false). */
    $canEmit = \App\Models\Notify::available()
        && count(\App\Models\Notify::recipientsFor('office', 'PermitController@index')) > 0;

    if (!$canEmit) {
        ok('⚠️ اتخطّى فحص الإشعار — مفيش مستلمين مكتب في القاعدة دي');
    } else {
        $lastNotif = function () {
            return Database::row("SELECT * FROM notifications ORDER BY id DESC LIMIT 1") ?: [];
        };
        $before = (int) (Database::scalar("SELECT COALESCE(MAX(id),0) FROM notifications") ?? 0);

        // ① مرحلة على الإذن كله
        \App\Models\PushNotify::trackStage([
            'permit_id' => $pid, 'stage_key' => $stages[2]['key'], 'bill_id' => 0,
            'source' => PermitTrack::SRC_OFFICE, 'by' => '__tr_user',
        ]);
        $n = $lastNotif();
        ((int) ($n['id'] ?? 0) > $before && ($n['kind'] ?? '') === 'track.stage')
            ? ok('★ الإشعار اتسجّل فعلاً بنوع track.stage') : bad('مفيش إشعار اتسجّل');
        str_contains((string) ($n['title'] ?? ''), $stages[2]['label'])
            ? ok('والعنوان فيه اسم المرحلة') : bad('اسم المرحلة مش في العنوان');
        str_contains((string) ($n['title'] ?? ''), '__tr_sader')
            ? ok('ورقم الإذن') : bad('رقم الإذن مش في العنوان');
        str_contains((string) ($n['body'] ?? ''), '__tr_cl')
            ? ok('والجسم فيه العميل') : bad('العميل مش في الجسم');
        str_contains((string) ($n['body'] ?? ''), 'من ' . count($stages))
            ? ok('والتقدّم (مرحلة كذا من كذا)') : bad('التقدّم مش في الجسم');
        str_contains((string) ($n['body'] ?? ''), '__tr_user')
            ? ok('واسم اللي حدّثها') : bad('اسم المحدِّث مش في الجسم');
        str_contains((string) ($n['url'] ?? ''), '#journey')
            ? ok('والرابط بيفتح على لوح الرحلة مباشرةً') : bad('الرابط مش موجّه للوح الرحلة');
        ($n['actor_type'] ?? '') === 'user'
            ? ok('وactor_type=user لتسجيل المكتب') : bad('نوع الفاعل غلط لتسجيل المكتب');

        /* ⚠️ قاعدة v35: الإشعار بيمرّ في مسار المندوب فممنوع يحمل أي مبلغ. */
        !preg_match('/\d[\d,]*\.\d{2}|ج\.م/u', (string) ($n['title'] ?? '') . ' ' . ($n['body'] ?? ''))
            ? ok('★ وولا مبلغ في الإشعار (قاعدة v35)') : bad('مبلغ وصل نص الإشعار');

        // ② مرحلة على بوليصة بعينها → العربية والسائق
        \App\Models\PushNotify::trackStage([
            'permit_id' => $pid, 'stage_key' => $stages[3]['key'], 'bill_id' => $billA,
            'source' => PermitTrack::SRC_REP, 'by' => '__tr_rep',
        ]);
        $nb = $lastNotif();
        (str_contains((string) ($nb['body'] ?? ''), '__tr_b1')
         && str_contains((string) ($nb['body'] ?? ''), 'ط ف ر 1111')
         && str_contains((string) ($nb['body'] ?? ''), '__tr_drv'))
            ? ok('★ حدث البوليصة فيه رقمها وعربيتها وسايقها')
            : bad('بيانات البوليصة ناقصة من الإشعار: ' . ($nb['body'] ?? ''));
        ($nb['actor_type'] ?? '') === 'rep'
            ? ok('وactor_type=rep لتسجيل المندوب') : bad('نوع الفاعل غلط لتسجيل المندوب');

        // ③ المرحلة النهائية لها نبرة مختلفة
        \App\Models\PushNotify::trackStage([
            'permit_id' => $pid, 'stage_key' => $stages[count($stages) - 1]['key'], 'bill_id' => 0,
            'source' => PermitTrack::SRC_OFFICE, 'by' => '__tr_user',
        ]);
        str_contains((string) ($lastNotif()['title'] ?? ''), 'خلصت الرحلة')
            ? ok('★ والمرحلة النهائية بتقول «خلصت الرحلة»') : bad('المرحلة النهائية زي أي مرحلة');

        // ④ التراجع خبر مستقل بنبرة تحذير
        \App\Models\PushNotify::trackStage([
            'permit_id' => $pid, 'stage_key' => $stages[2]['key'], 'bill_id' => 0,
            'source' => PermitTrack::SRC_OFFICE, 'by' => '__tr_user', 'undo' => true,
        ]);
        $nu = $lastNotif();
        str_contains((string) ($nu['title'] ?? ''), 'رجعت الحالة')
            ? ok('والتراجع بيتبعت كخبر واضح') : bad('التراجع ما بيبعتش خبر مفهوم');
        ($nu['severity'] ?? '') === 'warn'
            ? ok('★ وبنبرة تحذير (رجوع لورا مش تقدّم)') : bad('التراجع بنبرة عادية');

        // ⑤ إذن مش موجود = مفيش إشعار وهمي
        $cnt = (int) Database::scalar("SELECT COUNT(*) FROM notifications");
        \App\Models\PushNotify::trackStage([
            'permit_id' => 99999999, 'stage_key' => $stages[0]['key'], 'by' => 'x',
        ]);
        (int) Database::scalar("SELECT COUNT(*) FROM notifications") === $cnt
            ? ok('وإذن مش موجود ما بيولّدش إشعار') : bad('اتبعت إشعار عن إذن مش موجود');

        /* ══ ⑥ الحدث بيوصل المناديب كمان (قرار المالك 2026-08-02) ══════════ */
        $activeReps = Database::all("SELECT id FROM reps WHERE status = 'active' LIMIT 2");
        if (!$activeReps) {
            ok('⚠️ اتخطّى فحص وصول المناديب — مفيش مندوب نشط في القاعدة دي');
        } else {
            \App\Models\PushNotify::trackStage([
                'permit_id' => $pid, 'stage_key' => $stages[1]['key'], 'bill_id' => 0,
                'source' => PermitTrack::SRC_OFFICE, 'by' => '__tr_user',
            ]);
            $nid = (int) Database::scalar("SELECT MAX(id) FROM notifications");
            $kinds = array_column(Database::all(
                "SELECT DISTINCT party_type FROM notification_recipients WHERE notification_id = ?",
                [$nid]), 'party_type');
            (in_array('rep', $kinds, true) && in_array('user', $kinds, true))
                ? ok('★ الحدث وصل المكتب والمناديب مع بعض') : bad('الجمهور لسه المكتب بس: ' . implode(',', $kinds));

            /* ⚠️ أهم فحص في التوسيع: رابط المندوب لازم يبقى بوابته هو، وإلا
               يدوس على الإشعار ويتقذف على شاشة دخول المكتب. */
            $row = Database::row("SELECT * FROM notifications WHERE id = ?", [$nid]);
            \App\Models\Notify::urlFor($row, 'rep') === 'm/permit/' . $pid . '/track'
                ? ok('★ ورابط المندوب بوابته هو مش صفحة المكتب')
                : bad('رابط المندوب ميت: ' . \App\Models\Notify::urlFor($row, 'rep'));
            str_contains(\App\Models\Notify::urlFor($row, 'user'), 'permits/' . $pid)
                ? ok('ورابط المكتب زي ما هو') : bad('رابط المكتب اتغيّر');

            /* الحارس العام: أي حدث بلا بديل ورابطه صفحة مكتب = بلا رابط عند
               المندوب — خبر يُقرا أهون من رابط بيرمي ٤٠٣. */
            \App\Models\Notify::urlFor(['url' => 'closing', 'meta_json' => null], 'rep') === ''
                ? ok('★ وصفحة مكتب بلا بديل بتوصل المندوب بلا رابط بدل رابط ميت')
                : bad('رابط مكتب ميت بيعدّي للمندوب');
            \App\Models\Notify::urlFor(['url' => 'm/settle', 'meta_json' => null], 'rep') === 'm/settle'
                ? ok('ورابط البوابة بيعدّي زي ما هو') : bad('رابط بوابة سليم اتشال');

            // اللي عمل الإجراء ما بيرنّش على نفسه
            $rid = (string) $activeReps[0]['id'];
            \App\Models\PushNotify::trackStage([
                'permit_id' => $pid, 'stage_key' => $stages[6]['key'], 'bill_id' => 0,
                'source' => PermitTrack::SRC_REP, 'by' => '__tr_rep', 'actor_key' => $rid,
            ]);
            $nid2 = (int) Database::scalar("SELECT MAX(id) FROM notifications");
            (int) Database::scalar(
                "SELECT COUNT(*) FROM notification_recipients
                  WHERE notification_id = ? AND party_type = 'rep' AND party_key = ?", [$nid2, $rid]) === 0
                ? ok('★ واللي حدّثها بنفسه ما رنّش عليه') : bad('المندوب اترنّ على إجراء عمله هو');
            (int) Database::scalar(
                "SELECT COUNT(*) FROM notification_recipients
                  WHERE notification_id = ? AND party_type = 'user'", [$nid2]) > 0
                ? ok('والمكتب أخد الخبر عادي') : bad('الاستثناء شال مستلمين غلط');
        }
    }

    /* ⚠️ الحارس اللي بيمنع الرنّة المكرّرة: `hasEvent` بترجّع true بعد التسجيل،
       فالكنترولر يعرف إن إعادة الحفظ مش «حالة اتغيّرت». */
    PermitTrack::hasEvent($pid, $stages[0]['key'], 0)
        ? ok('★ hasEvent بتشوف المرحلة المسجَّلة') : bad('hasEvent ما شافتش مرحلة مسجَّلة');
    !PermitTrack::hasEvent($pid, $stages[5]['key'], 0)
        ? ok('وما بتشوفش مرحلة لسه ما اتسجّلتش') : bad('hasEvent بترجّع true على مرحلة مش موجودة');

    PermitTrack::eventContext(99999999, $stages[0]['key']) === null
        ? ok('وeventContext بترجّع null لإذن مش موجود') : bad('eventContext بترجّع سياق لإذن وهمي');

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ══════════════════════════════════════════ ⑬ الماركب والتنسيق
sec('⑬ كل كلاس بيتطبع له CSS (مصيدة .table-mobile-scroll)');

$appCss = src('public/assets/css/app.css');
$mCss   = src('public/assets/css/m.css');
foreach (['pj','pj-head','pj-now','pj-badge','pj-prog','pj-next','pj-track','pj-step','pj-dot',
          'pj-body','pj-label','pj-when','pj-src','pj-note','pj-act','pj-mark','pj-undo',
          'pj-bills','pj-bill','pj-mini','pj-manual','pj-manual-f','pv-trk'] as $c) {
    str_contains($appCss, '.' . $c)
        ? ok(".{$c} معرَّف في app.css") : bad(".{$c} بيتطبع بلا CSS");
}
/* ⚠️ كلاسات البوابة لازم تكون في m.css — `/m` **ما بتحمّلش app.css**. */
foreach (['hm-stub-track','mt-now','mt-next','mt-track','mt-step','mt-dot','mt-mark',
          'mt-bills','mt-bill','mt-bill-btn','mt-fin'] as $c) {
    str_contains($mCss, '.' . $c)
        ? ok(".{$c} معرَّف في m.css") : bad(".{$c} بيتطبع بلا CSS في البوابة");
}

/* ⚠️ مقطع الوحدة لازم يفضل **قبل** مقطع الوضع الليلي — الإلحاق في آخر الملف
   بيحطّه جوّه المقطع الليلي و`verify_dark_print` بيفشل صح. */
$darkAt = strpos($appCss, 'الوضع الليلي — المرحلة ١٥');
$pjAt   = strpos($appCss, '.pj-head{');
($pjAt !== false && $darkAt !== false && $pjAt < $darkAt)
    ? ok('مقطع الرحلة قبل مقطع الوضع الليلي') : bad('مقطع الرحلة وقع جوّه المقطع الليلي');

sec('⑭ المسارات والإشعار');

$routes = src('public/index.php');
str_contains($routes, "'/permits/{id}/track',        'PermitController@trackStage'")
    ? ok('مسار تسجيل المرحلة مسجَّل') : bad('مسار التسجيل ناقص');
str_contains($routes, "'/permits/{id}/track-undo'") ? ok('ومسار التراجع') : bad('مسار التراجع ناقص');

/* ⚠️ تحذير ٤: المسارات الحرفية قبل الجشعة. */
$repTrack = strpos($routes, "'/m/permit/{id}/track'");
$repGreedy = strpos($routes, "'/m/permit/{id}',  'RepPortalController@entryForm'");
($repTrack !== false && $repGreedy !== false && $repTrack < $repGreedy)
    ? ok('ومسار المندوب حرفي قبل الجشع /m/permit/{id}') : bad('ترتيب مسارات البوابة غلط');

has('نوع الإشعار track.stage في KINDS', 'app/Models/Notify.php', "'track.stage'");
/* ⚠️ `both` بقرار المالك (2026-08-02) — والمندوب لازم ياخد `meta.rep_url`
      وإلا الإشعار يوصله برابط صفحة مكتب مقفولة عليه. */
has('وجمهوره both (المكتب والمناديب)', 'app/Models/Notify.php', "'track.stage'           => ['both'");
has('وبيخزّن رابط بوابة المندوب', 'app/Models/PushNotify.php', "'rep_url' => 'm/permit/");
has('وPushNotify::trackStage موجودة', 'app/Models/PushNotify.php', 'function trackStage');
/* ⚠️ الأيقونة لازم تكون في الخريطتين: icon() للصفحة وapp.js للجرس. */
str_contains(src('public/assets/js/app.js'), "'map-pin'")
    ? ok('وأيقونة map-pin في خريطة app.js كمان') : bad('الأيقونة ناقصة من app.js — هتقع على شكل افتراضي');

/* ⚠️ الإشعار بيمرّ في مسار المندوب فممنوع يحمل أي مبلغ (قاعدة v35). */
$push = code_only('app/Models/PushNotify.php');
$fn = '';
if (preg_match('~function trackStage\(.*?\n    \}~s', $push, $m)) { $fn = $m[0]; }
($fn !== '' && !preg_match('~amount|money|number_format~', $fn))
    ? ok('★ وولا مبلغ في نص الإشعار') : bad('الإشعار بيحمل مبلغ');

sec('⑮ الحُرّاس في الكنترولرات');

has('trackStore بينادي assertAssigned قبل الكتابة',
    'app/Controllers/RepPortalController.php', 'RepPortal::assertAssigned($repId, $pid)');
has('وبيسجّل بمصدر المندوب',
    'app/Controllers/RepPortalController.php', 'PermitTrack::SRC_REP');
has('وإقفال الإذن بيسجّل المرحلة الأخيرة',
    'app/Controllers/PermitController.php', 'PermitTrack::systemMark($pid, $last[');
has('وإنشاء البوليصة بيسجّل أول مرحلة عليها',
    'app/Controllers/PermitController.php', 'PermitTrack::systemMark($pid, $first[');

/* ⚠️ الهوكات التلقائية **لازم** تكون ملفوفة في try/catch — فشل تسجيل حالة
   وصفية ممنوع يوقف حفظ بوليصة أو إقفال إذن (درس Notify::emit في v52). */
$pc = code_only('app/Controllers/PermitController.php');
$wrapped = preg_match_all('~try\s*\{\s*[^}]*PermitTrack::~s', $pc);
$wrapped >= 2 ? ok('★ والهوكان ملفوفان في try/catch') : bad('هوك تلقائي بلا try/catch — ممكن يوقف حفظ بوليصة');

/* ⚠️ **كل مسارات الكتابة التلاتة لازم تبعت** — كان مسار المكتب ساكتًا تمامًا:
      المندوب يحدّث فالمكتب يعرف، والمكتب يحدّث فمحدش يعرف. */
$rpc = code_only('app/Controllers/RepPortalController.php');
foreach ([
    ['المكتب: تسجيل المرحلة بيبعت إشعار', $pc,  'trackStage'],
    ['المندوب: تسجيل المرحلة بيبعت إشعار', $rpc, 'trackStore'],
] as [$label, $code, $method]) {
    // نقصّ جسم الدالة لحد أول `\n    }` عشان ما نبلعش اللي بعدها
    $body = preg_match('~function ' . $method . '\(.*?\n    \}~s', $code, $m) ? $m[0] : '';
    (str_contains($body, 'PushNotify::trackStage') && str_contains($body, 'PermitTrack::hasEvent'))
        ? ok($label . ' — ومشروط بأنها مرحلة جديدة')
        : bad($label . ' ناقص أو بلا حارس التكرار');
}
$undoBody = preg_match('~function trackUndo\(.*?\n    \}~s', $pc, $m) ? $m[0] : '';
str_contains($undoBody, 'PushNotify::trackStage')
    ? ok('والتراجع بيبعت إشعار كمان') : bad('التراجع بيشيل مرحلة بلا ما حد يعرف');

/* ⚠️ `systemMark` **ممنوعة تبعت** — نقطتا النداء بيبعتوا `bill.linked` و
      `permit.closed` أصلاً، فإشعار حالة كمان = نفس الخبر مرتين. */
$smBody = preg_match('~function systemMark\(.*?\n    \}~s', code_only('app/Models/PermitTrack.php'), $m) ? $m[0] : '';
($smBody !== '' && !str_contains($smBody, 'PushNotify'))
    ? ok('★ والتسجيل التلقائي ساكت عن قصد (مفيش خبر مكرّر)') : bad('systemMark بتبعت إشعار مكرّر');

echo "\n" . str_repeat('═', 62) . "\n";
echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
near('رصيد الخزنة قبل = بعد', Closing::currentBalance(), $treasuryBefore);
if (RepCustody::available()) {
    near('وإجمالي عهد المناديب قبل = بعد', RepCustody::totalOutstanding(), $repsBefore);
}
echo "\nنجح: $pass | فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
