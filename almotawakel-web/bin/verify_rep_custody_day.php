<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص إعادة تصميم عهدة المناديب بعدسة اليوم (2026-07-25).
 *
 * الشاشة القديمة كانت بتعرض **كل الأيام مع بعض** في صفحة واحدة فيها ١١ قسم فوق
 * بعض، فالمالك مايعرفش «اللي حصل النهاردة» من غير ما يقرا الجدول كله. دلوقتي:
 *   • `/rep-custody`      → كروت وأعمدة **يوم العمل** (`Auth::workDate`).
 *   • `/rep-custody/{id}` → ٤ تبويبات بروابط حقيقية، الافتراضي «اليوم».
 *
 * الفحص **بيرندر الصفحات فعليًّا** ببيانات حقيقية — مش فحص نصّي — عشان يمسك
 * الفشل الصامت الموثَّق في `Controller::render` (مفتاح بيتخطّى بـEXTR_SKIP).
 *
 * أهم المحروس هنا:
 *   • **بند يوم تاني ما بيظهرش في تبويب اليوم** — دي الميزة نفسها؛ لو فشلت
 *     تبقى الشاشة رجعت لسلوكها القديم بالسكوت.
 *   • **مفيش تعريف تاني لأي رقم** — مجاميع `dayActivity` = `breakdown($rep,$day)`
 *     بالمليم، ومجموع كروت الشاشة = `dayTotalsByRep` بالظبط.
 *   • **كل مسار حفظ بيحمل `?tab=`** — من غيره المرساة (`#deposit-form`) تروح
 *     على تبويب مش معروض فالمالك ما يشوفش الخطأ.
 *   • **التبويبات روابط `<a href>`** لا تبديل بجافاسكريبت (قاعدة مكوّن `tabs`).
 *   • **العزل المالي** — رصيد الخزنة قبل = بعد.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_rep_custody_day.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Controllers\RepCustodyController;
use App\Models\Closing;
use App\Models\RepCustody;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

echo "فحص عهدة المناديب — عدسة اليوم\n" . str_repeat('=', 62) . "\n";

$pdo = Database::conn();
$treasuryBefore = Closing::currentBalance();
$lockedDay = Closing::lastClosedDate();

// يوم مفتوح للاختبار + يوم قبله (عشان نثبت إن القديم مش بيتسرّب)
$day = date('Y-m-d');
for ($i = 0; $i < 400 && $lockedDay !== null && $day <= $lockedDay; $i++) {
    $day = date('Y-m-d', strtotime("+$i day"));
}
$prevDay = date('Y-m-d', strtotime($day . ' -1 day'));

$ctrlSrc = (string) file_get_contents(__DIR__ . '/../app/Controllers/RepCustodyController.php');
$cardSrc = (string) file_get_contents(__DIR__ . '/../app/Views/rep_custody/card.php');
$idxSrc  = (string) file_get_contents(__DIR__ . '/../app/Views/rep_custody/index.php');

$pdo->beginTransaction();
try {
    $tag = '__rcd_' . bin2hex(random_bytes(4));
    Database::exec("INSERT INTO reps(name,status) VALUES(?,'active')", [$tag]);
    $repId = Database::lastId();
    Database::exec(
        "INSERT INTO permits(permit_no,permit_date,status,company,client) VALUES(?,?,'open',?,?)",
        [$tag, $day, $tag . ' شركة', $tag . ' وكيل']);
    $permitId = Database::lastId();
    Database::exec("INSERT INTO permit_movements(permit_id,seq) VALUES(?,1)", [$permitId]);
    $movId = Database::lastId();

    // عهدة اليوم: نزل 3000 وصرف إيصال 400 (2×200) ومصروف 150
    Database::exec(
        "INSERT INTO rep_custody_deposits(rep_id,dep_date,amount,note) VALUES(?,?,?,?)",
        [$repId, $day, 3000, $tag . ' عهدة اليوم']);
    // ويوم قبله: نزل 5000 وصرف إيصال 900 — **ممنوع يتسرّب لتبويب اليوم**
    Database::exec(
        "INSERT INTO rep_custody_deposits(rep_id,dep_date,amount,note) VALUES(?,?,?,?)",
        [$repId, $prevDay, 5000, $tag . ' عهدة إمبارح']);

    /* ⚠️ **مندوب تاني عليه حركة في نفس اليوم واسمه مش مطابق للبحث** — موجود
       عشان فحص ③ب يبقى له معنى: من غيره الفلترة بـ`$tag` بترجّع نفس المجموعة
       فالإجماليات ما بتتغيّرش سواء الكود صح أو غلط، والفحص بيعدّي على الحالتين
       (اتجرّب فعليًّا: عدّى على الكنترولر القايم **وعلى المعطوب** قبل ما يتزوّد). */
    $tagOther = '__rcd2_' . bin2hex(random_bytes(4));
    Database::exec("INSERT INTO reps(name,status) VALUES(?,'active')", [$tagOther]);
    Database::exec(
        "INSERT INTO rep_custody_deposits(rep_id,dep_date,amount,note) VALUES(?,?,?,?)",
        [Database::lastId(), $day, 1700, $tagOther . ' عهدة اليوم']);
    $treasuryAfterDeposits = Closing::currentBalance();

    $stage = static function (string $d, string $key, string $note, int $qty, float $unit)
                     use ($permitId, $movId, $repId): void {
        Database::exec(
            "INSERT INTO permit_stages
                (permit_id, movement_id, stage_key, direction, note, qty, amount, entry_date,
                 is_visa, paid_by_rep_id)
             VALUES (?,?,?,?,?,?,?,?,0,?)",
            [$permitId, $movId, $key, $key === 'receipts' ? 'expense' : 'expense',
             $note, $qty, $unit * $qty, $d, $repId]);
    };
    $stage($day, 'receipts', $tag . ' إيصال النهاردة', 2, 200);
    $stage($day, 'custom',   $tag . ' مصروف النهاردة', 1, 150);
    $stage($prevDay, 'receipts', $tag . ' إيصال إمبارح', 1, 900);

    // بند دفعه المندوب النهاردة ولسه مستني اعتماد: فلوسه خرجت من إيده فعلاً،
    // فلازم يتخصم من «فاضل معاه» وإن كان لسه ما اتحسبش في `spent`.
    Database::exec(
        "INSERT INTO rep_submissions
            (rep_id, permit_id, movement_id, kind, note, qty, unit_amount, amount,
             entry_date, status, is_visa)
         VALUES (?,?,?,'receipt',?,1,250,250,?, 'pending',0)",
        [$repId, $permitId, $movId, $tag . ' مستني النهاردة', $day]);

    // ═══ ① الأرقام: مفيش تعريف تاني
    sec('① الأرقام كلها من مصدر واحد');
    $act = RepCustody::dayActivity($repId, $day);
    $b   = RepCustody::breakdown($repId, $day);
    chk(abs($act['cash'] - $b['spent']) < 0.005,
        'مجموع كاش dayActivity = breakdown(day).spent بالمليم ('
        . number_format($act['cash'], 2) . ')');
    chk(abs($b['deposits'] - 3000) < 0.005, 'نزل له اليوم = 3,000 (بلا عهدة إمبارح)');
    chk(abs($b['balance'] - (3000 - 550)) < 0.005,
        'فاضل من عهدة اليوم = 2,450 — **مفيش ترحيل** من إمبارح');
    chk($act['receipts']['cnt'] === 1 && abs($act['receipts']['sum'] - 400) < 0.005,
        'إيصالات اليوم: بند واحد بـ400 (2 × 200)');
    chk($act['expenses']['cnt'] === 1 && abs($act['expenses']['sum'] - 150) < 0.005,
        'مصروفات الأذونات اليوم: بند واحد بـ150');

    $cum = RepCustody::breakdown($repId);
    chk(abs($cum['deposits'] - 8000) < 0.005 && abs($cum['spent'] - 1450) < 0.005,
        'التراكمي ما اتلمسش (8,000 نازل · 1,450 مصروف) — أساس التسوية وحد التحويل');
    chk(abs(RepCustody::balance($repId) - $cum['balance']) < 0.005,
        'balance() = breakdown() — مفيش تعريفين للرصيد');

    $map = RepCustody::dayTotalsByRep($day);
    chk(isset($map[$repId]) && abs($map[$repId]['balance'] - $b['balance']) < 0.005,
        'dayTotalsByRep (اللي بتغذّي الشاشة الرئيسية) = breakdown(day) للمندوب نفسه');

    // ⚠️ «فاضل معاه» المعروض = pocket = balance − المستنّي (كاش). البند اللي
    //    المندوب دفعه ولسه في صندوق المراجعة فلوسه خرجت من إيده فعلاً، فلو ما
    //    اتخصمش الشاشة بتقول إن معاه فلوس هو أصلاً صرفها.
    chk(abs((float) $map[$repId]['pending'] - 250) < 0.005, 'المستنّي اليوم = 250');
    chk(abs((float) $map[$repId]['pocket'] - (3000 - 550 - 250)) < 0.005,
        '★ «فاضل معاه» = 2,200 (بعد خصم المستنّي كمان) مش 2,450');
    chk(abs(\App\Models\RepPortal::dayPocket($repId, $day)['pocket']
            - (float) $map[$repId]['pocket']) < 0.005,
        'ونفس رقم شاشة المندوب بالمليم (dayPocket) — مفيش تعريفين');

    // ═══ ② العزل: spending($day) بيرجّع اليوم بس
    sec('② فلتر اليوم على كشف الصرف');
    $allRows = RepCustody::spending($repId);
    $dayRows = RepCustody::spending($repId, $day);
    chk(count($allRows) === 3, 'الكشف الكامل فيه 3 بنود (اليومين مع بعض)');
    chk(count($dayRows) === 2, 'كشف اليوم فيه بندين بس');
    $leak = false;
    foreach ($dayRows as $r) { if ((string) $r['d'] !== $day) { $leak = true; } }
    chk(!$leak, 'ولا بند من يوم تاني تسرّب لكشف اليوم');

    // ═══ ③ رندر الشاشة الرئيسية
    sec('③ /rep-custody بيرندر بعدسة اليوم');
    $_SESSION = ['uid' => (int) Database::scalar('SELECT MIN(id) FROM users'), 'role' => 'owner',
                 'uname' => 'فحص', 'last_activity' => time(), 'uid_checked' => time(),
                 'work_date' => $day];
    $render = static function (callable $fn) : string {
        ob_start();
        try { $fn(); return (string) ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); bad('استثناء وقت الرندر: ' . $e->getMessage()); return ''; }
    };
    $ctrl = new RepCustodyController();
    $_GET = ['q' => $tag];
    $html = $render(static fn () => $ctrl->index());
    $noPhpError = static function (string $h, string $where) {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $h, $m)
            ? bad("خطأ PHP في $where: " . ($m[0] ?? ''))
            : ok("$where بيرندر بلا أخطاء PHP");
    };
    if ($html === '') { bad('/rep-custody ما رندرش'); } else {
        $noPhpError($html, '/rep-custody');
        chk(str_contains($html, $tag), 'المندوب ظاهر في الشاشة');
        // ⚠️ الفحص على **السلوك** مش على ماركب بعينه: الجدول اتحوّل لكروت في
        //    المرحلة ١٠، والمطلوب إن أرقام اليوم هي الأساسية بغض النظر عن الشكل.
        chk(str_contains($html, 'نزل للمناديب اليوم')
            && (str_contains($html, 'فاضل معاهم من عهدة اليوم')
                || str_contains($html, 'فاضل من عهدة اليوم')),
            'أرقام اليوم هي الأساسية في الشاشة');
        // ★ الرقم المعروض بعد خصم المستنّي (2,200) مش الرصيد المحاسبي (2,450)
        chk(str_contains($html, '2,200') && !str_contains($html, '2,450'),
            '★ الشاشة بتعرض «فاضل معاه» بعد خصم المستنّي (2,200) مش 2,450');
        /* ⚠️ **التسمية اتغيّرت بقرار المالك (2026-07-27) — مش ترخية للفحص.**
           «برّه معاه (كل الأيام)» كان رقمًا أصمًّا بيتقري كأنه فاضل اليوم.
           بقى «مفتوح من أيام سابقة» = الرصيد − عهدة اليوم، يعني **شغل ناقص
           محتاج تصفية** وله زرار يقفله. لسه ظاهر (ممنوع نخبّي فلوس برّه)
           لكن بمعنى صحيح. الفحص على المعنى مش على النص القديم. */
        chk(str_contains($html, 'مفتوح من أيام سابقة'),
            'الرصيد المفتوح من أيام تانية ظاهر (مش مخبّي) وبتسمية صحيحة');
        chk(str_contains($html, 'غيّر يوم العمل'),
            'فيه طريق واضح لتغيير يوم العمل من الشاشة');
    }

    /* ═══ ③ب البحث بيفلتر الكروت بس — الإجماليات ما بتتغيّرش
       ⚠️ **الباج اللي الفحص ده اتكتب عشانه:** `$dsum` كان بيتجمّع من
       `cards($q)` المفلترة، فكتابة اسم في خانة البحث كانت بتغيّر الأربع أرقام
       الكبيرة **بالسكوت** — بينما نوافذ التفاصيل (`dayDeposits`/`daySpending`/
       `dayBalanceRows`) عمرها ما اتفلترت. النتيجة: الكارت يقول رقمًا وتدوس
       عليه فتلاقي بنودًا بمجموع تاني، وده بيكسر قاعدة «ممنوع رقم أصمّ» من جوّه.
       الفحص على **السلوك** (نفس قيم الـKPI مع البحث وبدونه) مش على رقم مزروع. */
    sec('③ب البحث بيفلتر الكروت مش الإجماليات');
    $kpis = static function (string $h): array {
        preg_match_all('~<div class="d-kpi-val">\s*([^<]+?)\s*<~s', $h, $m);
        return array_map('trim', $m[1] ?? []);
    };
    $_GET = [];
    $htmlAll = $render(static fn () => $ctrl->index());
    $_GET = ['q' => $tag];
    $htmlQ  = $render(static fn () => $ctrl->index());
    $kAll = $kpis($htmlAll);
    $kQ   = $kpis($htmlQ);
    chk($kAll !== [] && $kAll === $kQ,
        '★ أرقام الشاشة زي ما هي مع البحث وبدونه (' . implode(' · ', $kAll) . ')');
    chk(str_contains($htmlQ, 'الأرقام فوق لكل المناديب'),
        'الشاشة بتقول صراحةً إن البحث بيفلتر الكروت بس');
    chk(!str_contains($htmlAll, 'الأرقام فوق لكل المناديب'),
        'وما بتقولهاش لما مفيش بحث');
    // ★ الحقيقة المالية اللي كانت غايبة تمامًا عن الشاشة
    chk(str_contains($htmlAll, 'برّه معاهم'),
        '★ إجمالي الفلوس اللي برّه مع المناديب معروض على الشاشة');
    chk(str_contains($htmlAll, money0(RepCustody::totalOutstanding())),
        'والرقم المعروض هو `totalOutstanding()` نفسه مش جمع بالإيد في العرض');

    // ═══ ④ رندر كارت المندوب — التبويبات الأربعة
    sec('④ كارت المندوب: ٤ تبويبات وكلها بترندر');
    $tabs = ['day', 'permits', 'account', 'settings'];
    $tabHtml = [];
    foreach ($tabs as $t) {
        $_GET = ['tab' => $t];
        $tabHtml[$t] = $render(static fn () => $ctrl->card((string) $repId));
        $tabHtml[$t] === ''
            ? bad("تبويب $t ما رندرش")
            : $noPhpError($tabHtml[$t], "تبويب $t");
    }
    // تبويب غير معروف بيرجع لليوم بدل ما يفضى
    $_GET = ['tab' => 'zzz'];
    $fallback = $render(static fn () => $ctrl->card((string) $repId));
    chk($fallback !== '' && str_contains($fallback, 'أداء المندوب اليوم'),
        'تبويب مش معروف بيرجع للافتراضي «اليوم» بدل صفحة فاضية');
    $_GET = [];

    // ═══ ⑤ الميزة نفسها: تبويب اليوم ما بيعرضش يوم تاني
    sec('⑤ ⚠️ تبويب اليوم بيعرض اليوم بس (لو فشل يبقى رجع للسلوك القديم)');
    $d = $tabHtml['day'] ?? '';
    if ($d === '') { bad('تبويب اليوم ما رندرش — الفحص اتخطّى'); } else {
        chk(str_contains($d, $tag . ' إيصال النهاردة'), 'إيصال اليوم ظاهر');
        chk(str_contains($d, $tag . ' مصروف النهاردة'), 'مصروف اليوم ظاهر');
        chk(!str_contains($d, $tag . ' إيصال إمبارح'), '**بند إمبارح مش ظاهر** في تبويب اليوم');
        chk(str_contains($d, $tag . ' عهدة اليوم'), 'تنزيل عهدة اليوم ظاهر');
        chk(!str_contains($d, $tag . ' عهدة إمبارح'), '**تنزيل إمبارح مش ظاهر** في تبويب اليوم');
        chk(str_contains($d, 'أداء المندوب اليوم'), 'قسم أداء المندوب موجود');
        chk(str_contains($d, 'id="deposit-form"'), 'فورم تنزيل العهدة في تبويب اليوم');
        // ★ المعادلة بتوصل «فاضل معاه» بخطوة صريحة للمستنّي — الفرق بيتشرح مش يختفي
        chk(str_contains($d, 'دفعه ومستني اعتمادك'),
            '★ معادلة اليوم فيها خطوة «دفعه ومستني اعتمادك»');
        chk(str_contains($d, 'فاضل معاه'), 'ونتيجتها «فاضل معاه»');
        chk(str_contains($d, '2,200') || str_contains($d, '2200'),
            '★ ونتيجة المعادلة 2,200 (بعد خصم المستنّي)');
    }
    $a = $tabHtml['account'] ?? '';
    chk($a !== '' && str_contains($a, $tag . ' إيصال إمبارح'),
        'بند إمبارح موجود في تبويب «الحساب الكامل» (ما اتشالش من النظام)');
    chk($a !== '' && str_contains($a, 'id="settle-form"'), 'فورم التسوية في تبويب الحساب');

    /* ⚠️ **لوح التحويلات الصادرة (2026-07-31).** قبله كان الصادر بيبان **جوّه
       كشف الصرف بس** وسط الإيصالات والمصروفات و**بلا أي زرار** — فالمالك
       مايقدرش يلغيه ولا يعدّله إلا لو راح لكارت المُستقبِل ودوّر عليه. التحويل
       حركة خارجة من عهدة المُرسِل، فمكان إجرائه كارته هو. الفحص بيزرع تحويل
       حقيقي ويتأكد إنه بيرندر بإجراءاته. */
    $peer = (int) Database::scalar(
        "SELECT id FROM reps WHERE status='active' AND id <> ? ORDER BY id LIMIT 1", [$repId]);
    if ($peer > 0) {
        \App\Models\RepTransfer::create($repId, [
            'to_rep_id' => $peer, 'amount' => '120', 'transfer_date' => $day, 'note' => $tag . ' تحويل']);
        $_GET = ['tab' => 'account'];
        $a2 = $render(static fn () => $ctrl->card((string) $repId));
        $_GET = [];
        $noPhpError($a2, 'تبويب الحساب بعد تحويل صادر');
        chk(str_contains($a2, 'id="transfers-out"'), '⚠️ لوح «تحويلات حوّلها لزملاء» ظاهر');
        chk(str_contains($a2, $tag . ' تحويل'), 'والتحويل نفسه ظاهر فيه');
        chk(str_contains($a2, '/update'), '⚠️ وفيه فورم تعديل (مش عرض بس)');
        chk(substr_count($a2, 'transfer/') >= 2, '⚠️ وفيه فورم إلغاء كمان');
        /* ⚠️ فورم الإلغاء لازم يفضل **برّه** فورم التعديل — فورم جوّه فورم
           ماركب غير صالح والمتصفح بيفكّه بصمت فالزرار يبعت على المسار الغلط. */
        chk(!preg_match('~<form[^>]*/update"[^>]*>(?:(?!</form>).)*<form~s', $a2),
            '⚠️ فورم الإلغاء مش متداخل جوّه فورم التعديل');
    } else {
        ok('(اتخطّى فحص التحويلات — مفيش مندوب تاني نشط)');
    }
    chk(($tabHtml['permits'] ?? '') !== '' && str_contains($tabHtml['permits'], 'id="assign-form"'),
        'فورم تنزيل الأذونات في تبويب الأذونات');
    chk(($tabHtml['settings'] ?? '') !== '' && str_contains($tabHtml['settings'], 'id="account-form"')
        && str_contains($tabHtml['settings'], 'id="permit-perm"'),
        'حساب الدخول والصلاحيات في تبويب الإعدادات');

    // ═══ ⑥ التبويبات روابط حقيقية
    sec('⑥ التبويبات روابط حقيقية مش تبديل بجافاسكريبت');
    chk(preg_match_all('/<a class="tab[^"]*" href="[^"]*tab=/', $d ?: '') >= 4,
        'كل تبويب `<a href>` — الرجوع بالمتصفح والمشاركة بيشتغلوا');
    chk(!preg_match('/\.tab-panel|classList\.toggle\([\'"]active/', $cardSrc),
        'مفيش لوحات مخفية بالجافاسكريبت في الكارت');

    // ═══ ⑦ كل مسار حفظ بيحمل ?tab=
    sec('⑦ ⚠️ كل رجوع بعد الحفظ بيحمل تبويبه (وإلا المرساة تضيع)');
    $src = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $ctrlSrc) ?? $ctrlSrc;
    chk(!preg_match("/(redirect|claimForm|formFailure)\(.*'rep-custody\/' \. \\\$repId/", $src),
        'مفيش مسار رجوع لكارت المندوب مكتوب بالإيد بلا تبويب');
    chk(substr_count($src, 'self::cardUrl(') >= 40,
        'كل مسارات الكارت بتمرّ على cardUrl() — نقطة واحدة لو التبويبات اتغيّرت');
    chk(str_contains($src, "\$out = 'rep-custody/' . \$repId . '?tab=' . \$tab"),
        'cardUrl بتبني `?tab=` دايمًا');
    foreach ([['account-form', 'TAB_SETTINGS'], ['assign-form', 'TAB_PERMITS'],
              ['deposit-form', 'TAB_DAY'], ['settle-form', 'TAB_ACCOUNT']] as [$anchor, $tabConst]) {
        chk(str_contains($src, "self::cardUrl(\$repId, self::$tabConst, '$anchor')"),
            "مرساة #$anchor بترجع على $tabConst");
    }

    // ═══ ⑧ الشاشة ما بتخترعش أرقام
    sec('⑧ الشاشة قراءة فقط — بتقرا الموديل ما بتحسبش');
    foreach (['card.php', '_tab_day.php', '_tab_permits.php', '_tab_account.php', '_tab_settings.php',
              'index.php'] as $f) {
        $v = (string) file_get_contents(__DIR__ . '/../app/Views/rep_custody/' . $f);
        chk(!preg_match('/\b(INSERT|UPDATE|DELETE|Database::)\b/', $v),
            "$f مافيهوش أي SQL ولا كتابة");
    }
    chk(!str_contains($idxSrc, 'json_encode'), 'index.php مافيهوش json_encode (قاعدة js_json الأمنية)');
    chk(!str_contains($cardSrc, 'json_encode'), 'card.php مافيهوش json_encode');

    // ═══ ⑨ب نوافذ التفاصيل — قاعدة «ممنوع رقم أصمّ» شغّالة فعليًّا
    sec('⑨ب نوافذ التفاصيل بترندر ببيانات حقيقية');
    foreach (['deposits' => 'العهدة النازلة', 'spent' => 'الصرف',
              'pending' => 'المستني', 'balance' => 'الفاضل'] as $what => $lbl) {
        $_GET = [];
        $html = $render(static fn () => $ctrl->drill($what));
        if ($html === '') { bad("نافذة «{$lbl}» ما رندرتش"); continue; }
        $noPhpError($html, "نافذة «{$lbl}»");
        chk(str_contains($html, 'drill-how'), "نافذة «{$lbl}» بتقول إزاي اتحسب الرقم");
        chk(str_contains($html, 'drill-foot') || str_contains($html, 'class="empty"'),
            "نافذة «{$lbl}» فيها مجموع وطريق للشاشة الكاملة (أو حالة فراغ)");
        chk(!str_contains($html, '<!doctype') && !str_contains($html, '<aside'),
            "نافذة «{$lbl}» بترندر **بلا layout** (جسم النافذة بس)");
    }
    // البيانات اللي زرعناها لازم تبان جوّه النوافذ — وبعدسة اليوم زي الشاشة
    $_GET = [];
    $spentHtml = $render(static fn () => $ctrl->drill('spent'));
    chk(str_contains($spentHtml, $tag . ' إيصال النهاردة'),
        'نافذة الصرف فيها إيصال اليوم فعلاً');
    chk(!str_contains($spentHtml, $tag . ' إيصال إمبارح'),
        '**وبند إمبارح مش فيها** — النافذة بعدسة اليوم زي الشاشة');
    $depHtml = $render(static fn () => $ctrl->drill('deposits'));
    chk(str_contains($depHtml, $tag . ' عهدة اليوم') && !str_contains($depHtml, $tag . ' عهدة إمبارح'),
        'نافذة التنزيلات فيها تنزيل اليوم بس');
    /* ⚠️ نوع مش في القائمة البيضا: **ما ينفعش يتجرَّب في نفس العملية** لأن
       `notFound()` بتعمل `exit` (وده صح — بتوقف التنفيذ فورًا)، فاستدعاؤها هنا
       كان بيقتل الفاحص قبل باقي الأقسام. الفحص بقى على الكود، والسلوك نفسه
       محروس في `verify_design_system.php` §⑥. */
    $drillSrc = preg_replace('~/\*.*?\*/~s', '',
        (string) file_get_contents(__DIR__ . '/../app/Controllers/RepCustodyController.php')) ?? '';
    /* القائمة البيضا بقت ثابتين (`DRILL_ALL` للشاشة العامة و`DRILL_REP` لكارت
       المندوب) بعد ما اتضافت تفاصيل على مستوى المندوب في المرحلة ١٢. */
    chk(str_contains($drillSrc, 'private const DRILL_ALL')
        && str_contains($drillSrc, 'private const DRILL_REP')
        && str_contains($drillSrc, 'private const DRILL_INBOX')
        && str_contains($drillSrc, 'array_merge(self::DRILL_ALL, self::DRILL_REP, self::DRILL_INBOX)')
        && str_contains($drillSrc, "\$this->notFound('نوع التفاصيل ده مش موجود.')"),
        '⚠️ نوع تفاصيل مش في القائمة البيضا بيترفض بـnotFound');
    chk(str_contains($drillSrc, "\$this->notFound('المندوب ده مش موجود.')"),
        '⚠️ وتفاصيل مندوب لازم `?rep=` لمندوب موجود فعلاً');

    /* ═══ ⑩ إخفاء الأرصدة **اتشال خالص** — قرار المالك (2026-08-02) ═══
       التوقّع **اتقلب عن قصد مش ترخية**: الفحص القديم كان بيتأكد إن الرقم
       مقنّع `••••••` وإن له زرار كشف (كان بيحرس باجًا حقيقيًّا: المعالج كان
       محليًّا في `office/index.php` فالأرقام في شاشات تانية تفضل مقنّعة للأبد).
       المالك قال «مش عايز إخفاء أرصدة»، فالميزة اتشالت من جذرها — والفحص
       دلوقتي بيحرس **إنها ما ترجعش نص-نص**: أي `data-secret` أو `'secret'`
       بلا معالج بيرجّع نفس الباج القديم بالظبط. */
    sec('⑩ إخفاء الأرصدة — اتشال بالكامل');
    $appJs = (string) file_get_contents(__DIR__ . '/../public/assets/js/app.js');
    chk(!str_contains($appJs, 'secretValues') && !str_contains($appJs, 'secret-toggle'),
        'معالج التقنيع اتشال من app.js');
    $leaks = [];
    foreach (glob(__DIR__ . '/../app/Views/**/*.php') ?: [] as $f) {
        $v = (string) file_get_contents($f);
        if (str_contains($v, 'data-secret') || str_contains($v, "'secret' => true")
            || str_contains($v, 'secret-toggle')) {
            $leaks[] = basename(dirname($f)) . '/' . basename($f);
        }
    }
    chk($leaks === [], 'ولا شاشة لسه بتقنّع رقم' . ($leaks ? ' — ' . implode(' · ', $leaks) : ''));
    foreach (['stat.php', 'entity_card.php'] as $f) {
        $v = (string) file_get_contents(__DIR__ . '/../app/Views/partials/' . $f);
        chk(!str_contains($v, 'secret'), "مكوّن $f بلا خيار تقنيع");
    }

    /* ═══ ⑨ العزل المالي
       ⚠️ **التوقّع اتقلب مع المرحلة ٢ من docs/16** (مش ترخية — قلب مقصود):
          قاعدة v42 كانت «صرف المندوب المعتمَد بيتخصم من الخزنة يوم صرفه»، وكانت
          صح **بس** طول ما كل تنزيل معاه توب-أب مرايا في `manual_custody`. لمّا
          الفلوس بقت تيجي من البنك للمندوب على طول، الخزنة بقت تتخصم لفلوس عمرها
          ما دخلت الدرج. القاعدة الجديدة: **محفظة المندوب مستقلة وصرفه ما بيمسّ
          الدرج**، واللي بيمسّه هو التنزيل لو طلع منه (`source='office'`).
          الفحص هنا بقى على **السلوك الساري** بدل توقّع محفوظ. */
    sec('⑨ العزل المالي');
    /* ⚠️ **التوقّع واعي بالنموذج** — قرار المالك (2026-07-30): «أي عهدة تتضاف
   للمندوب كأنها في الدرج»، فتنزيل البنك بقى **بيزوّد** رصيد الخزنة.
   `RepCustody::bankTopupActive()` هي المصدر الوحيد للفحص. */
    $depAdds = \App\Models\RepCustody::bankTopupActive();
    chk(abs($treasuryAfterDeposits - $treasuryBefore - ($depAdds ? 8000 : 0)) < 0.005,
        $depAdds ? 'تنزيل العهدة (8,000) زوّد خزنة المكتب (الخزنة = كل كاش الشركة)'
                 : 'تنزيل العهدة (8,000) من البنك ما مسّش خزنة المكتب');
    $newRule = \App\Models\RepCustody::newSafeRuleActive()
             && $day >= \App\Models\RepCustody::safeRuleFrom();
    /* ⚠️ الأساس هو الخزنة **بعد التنزيل** مش قبله — لما التنزيل بقى بيزوّد
       الخزنة (قرار المالك) الفرق ده بقى فارقًا. */
    $expected = $newRule ? $treasuryAfterDeposits : $treasuryAfterDeposits - 1450;
    chk(abs(Closing::currentBalance() - $expected) < 0.005,
        $newRule
            ? 'صرف المندوب المعتمَد (1,450) **ما مسّش** الخزنة — محفظته مستقلة (المرحلة ٢)'
            : 'صرف المندوب المعتمَد (1,450) اتخصم من الخزنة يوم صرفه (قاعدة v42)');

    $_SESSION = [];
} finally {
    $pdo->rollBack();
}

$treasuryAfter = Closing::currentBalance();
chk(abs($treasuryAfter - $treasuryBefore) < 0.005,
    'رصيد الخزنة قبل = بعد (' . number_format($treasuryBefore, 2) . ') — الفحص ما ساب أثر');

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
