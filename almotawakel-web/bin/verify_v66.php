<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص خزنة الشركة (v66).
 *
 * القواعد المحروسة هنا:
 *   ① **العزل بالبناء** — ولا ملف خزنة بيعرف `company_safe_entries` ولا
 *      `CompanySafe`، والأثر الوحيد على خزنة المكتب بيمرّ عبر `manual_custody`.
 *   ② **الاتجاه صح** — الإيداع والتحصيل ما بيمسّوش الدرج، والتحويل بينقل
 *      الفلوس بالظبط، والسحب بيعكسه، **والفلوس محفوظة** (المجموع ثابت).
 *   ③ **الحذف بيشيل الصفّين مع بعض** — مفيش صف `manual_custody` يتيم.
 *   ④ **الحُرّاس** — نوع مجهول · مبلغ ≤ صفر · يوم متقفل للتحويل بس ·
 *      تحصيل عميل مش بيتحذف من هنا.
 *   ⑤ **قايمة التوجيه مصدر واحد** — `routeChoices()` بيقراها الكنترولران
 *      والشاشتان (خيار معروض والخادم يرفضه = فشل صامت).
 *   ⑥ **`walletSplit['total']` معناه ما اتغيّرش** = الدرج + المناديب،
 *      وخزنة الشركة في مفتاحين مستقلين.
 *   ⑦ **المسارات الحرفية قبل الجشعة** (تحذير ٤) + الصلاحيات.
 *   ⑧ **رندر فعلي** للشاشة والنوافذ الأربعة.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v66.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\ClientPayment;
use App\Models\Closing;
use App\Models\CompanySafe;
use App\Models\Custody;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function note(string $m): void { echo "  • $m\n"; }
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

echo "فحص خزنة الشركة (v66)\n" . str_repeat('=', 62) . "\n";

if (!CompanySafe::ready()) {
    echo "\n⚠️ جدول company_safe_entries مش موجود — شغّل php bin/migrate_v66.php الأول.\n";
    exit(1);
}

// ═════════════════════════════════════════════ ① العزل بالبناء
sec('① العزل بالبناء — ولا ملف خزنة بيعرف الوحدة');

/* ⚠️⚠️ ده أهم فحص في الوحدة. لو ظهر ذكر للجدول في أي ملف خزنة، يبقى العزل
   اتكسر وبقى فيه نقطة مزامنة جديدة لازم تتفكر في ٨ أماكن — وهو بالظبط اللي
   الوحدة اتصممت عشان تتجنّبه (نفس حارس v39/v45/v54/v62). */
$safeFiles = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/DailyReview.php',
    'app/Models/Expense.php', 'app/Models/Metrics.php', 'app/Models/RepCustody.php',
    'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php',
    'app/Calc.php',
];
$leaks = [];
foreach ($safeFiles as $f) {
    $c = code_only($f);
    if ($c === '') { continue; }
    if (str_contains($c, 'company_safe_entries')) { $leaks[] = $f . ' (الجدول)'; }
    /* `Closing::walletSplit` استثناء **مقصود ومحدود**: بتقرا الرصيد للعرض بس،
       وفحص ⑥ تحت بيتأكد إن `total` معناه ما اتغيّرش. */
    if ($f !== 'app/Models/Closing.php' && str_contains($c, 'CompanySafe')) {
        $leaks[] = $f . ' (الكلاس)';
    }
}
$leaks ? bad('ملفات خزنة بتعرف الوحدة: ' . implode('، ', $leaks))
       : ok('ولا ملف خزنة بيذكر company_safe_entries ولا CompanySafe (ماعدا walletSplit للعرض)');

$closingCode = code_only('app/Models/Closing.php');
substr_count($closingCode, 'CompanySafe') === 1
    ? ok('`Closing` بتذكر CompanySafe مرة واحدة بس (walletSplit — عرض)')
    : bad('`Closing` بتذكر CompanySafe ' . substr_count($closingCode, 'CompanySafe') . ' مرات — العزل اتكسر');

// أعمدة الجدول: مفيش عمود بيربطه بأي حساب خزنة قائم
$cols = array_column(Database::all(
    "SELECT column_name cn FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name='company_safe_entries'"), 'cn');
/* ⚠️ **قايمة الأعمدة الرابطة اتوسّعت في v67 عن قصد**: كل واحد فيهم بيربط الصف
   بالصف المُولَّد في محفظة تانية عشان الحذف يشيل الاتنين مع بعض — نفس دور
   `custody_id` بالظبط. أي عمود **تاني** بيربط الوحدة بحساب قائم (إذن · بوليصة ·
   بند · إقفال) لسه معناه إن العزل اتكسر. */
$linkCols = ['custody_id', 'visa_card_id', 'visa_deposit_id', 'rep_id', 'rep_deposit_id'];
$badCols = array_values(array_filter($cols, static fn ($c) =>
    (bool) preg_match('/(permit|bawali|bill|stage|closing|visa|rep_|custody)/i', (string) $c)
    && !in_array((string) $c, $linkCols, true)));
$badCols ? bad('عمود بيربط الوحدة بحساب قائم: ' . implode('، ', $badCols))
         : ok('الأعمدة الرابطة هي المعروفة بس: ' . implode('، ', $linkCols));

// ═════════════════════════════════════════════ ② + ③ + ④ سلوكيًّا داخل transaction
$snap = static fn (): array => [
    'office'  => round(Custody::balance(), 2),
    'company' => round(CompanySafe::balance(), 2),
];

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $_SESSION['uid'] = $_SESSION['uid'] ?? 1;
    $_SESSION['uname'] = $_SESSION['uname'] ?? 'verify';
    $_SESSION['role'] = 'owner';

    sec('② الاتجاه صح والفلوس محفوظة');
    $b = $snap();

    // إيداع: الشركة تزيد والمكتب ثابت
    CompanySafe::create(['entry_date' => work_date(), 'kind' => 'deposit',
                         'amount' => 100000, 'source' => 'فحص']);
    $s1 = $snap();
    near('الإيداع زوّد خزنة الشركة', $s1['company'], $b['company'] + 100000);
    near('⚠️ الإيداع ما مسّش خزنة المكتب', $s1['office'], $b['office']);

    // تحويل للمكتب: نقل بالظبط
    $tid = CompanySafe::create(['entry_date' => work_date(), 'kind' => 'to_office', 'amount' => 30000]);
    $s2 = $snap();
    near('التحويل نقّص خزنة الشركة', $s2['company'], $s1['company'] - 30000);
    near('التحويل زوّد خزنة المكتب بنفس المبلغ', $s2['office'], $s1['office'] + 30000);

    // سحب من المكتب: العكس
    $wid = CompanySafe::create(['entry_date' => work_date(), 'kind' => 'from_office', 'amount' => 5000]);
    $s3 = $snap();
    near('السحب نقّص خزنة المكتب', $s3['office'], $s2['office'] - 5000);
    near('السحب زوّد خزنة الشركة', $s3['company'], $s2['company'] + 5000);

    /* ⚠️⚠️ **أهم ثابت في الوحدة**: التحويل بين المحفظتين **ما بيخلقش ولا بيلغي
       فلوس** — المجموع لازم يفضل هو هو. لو الفحص ده فشل، يبقى الاتجاه اتكتب
       غلط في ناحية والفلوس بتتبخّر أو بتتكرّر بلا أي خطأ ظاهر. */
    near('⚠️ الفلوس محفوظة: (مكتب + شركة) ثابت عبر التحويلات',
         $s3['office'] + $s3['company'], $s1['office'] + $s1['company']);

    sec('③ الحذف بيشيل الصفّين مع بعض');
    $entry = CompanySafe::find($tid);
    $entry && $entry['custody_id']
        ? ok('التحويل ولّد صف manual_custody مربوط (#' . $entry['custody_id'] . ')')
        : bad('التحويل ما ولّدش صف manual_custody');
    $custodyId = (int) ($entry['custody_id'] ?? 0);

    CompanySafe::delete($tid);
    $s4 = $snap();
    near('حذف التحويل رجّع خزنة المكتب', $s4['office'], $s3['office'] - 30000);
    near('حذف التحويل رجّع خزنة الشركة', $s4['company'], $s3['company'] + 30000);
    Database::scalar('SELECT COUNT(*) FROM manual_custody WHERE id=?', [$custodyId])
        ? bad('⚠️ صف manual_custody فضل يتيم بعد الحذف')
        : ok('⚠️ صف manual_custody اتشال معاه (مفيش يتيم)');

    // الإيداع مالوش صف عهدة أصلاً
    $depRow = Database::row('SELECT custody_id FROM company_safe_entries WHERE kind=? ORDER BY id DESC LIMIT 1', ['deposit']);
    empty($depRow['custody_id'])
        ? ok('الإيداع مالوش صف عهدة (ما بيمسّش الدرج)')
        : bad('الإيداع ولّد صف عهدة — ده بيزوّد الدرج بلا سبب');

    sec('④ الحُرّاس');
    $reject = static function (string $label, callable $fn): void {
        try { $fn(); bad($label . ' — عدّى وهو المفروض يترفض'); }
        catch (\Throwable $e) { ok($label . ' (' . mb_substr($e->getMessage(), 0, 46) . '…)'); }
    };
    /* ⚠️ المثال كان `expense` وبقى **نوعًا حقيقيًّا** في v67، فالفحص كان بيفشل
       بإنذار كاذب. النوع المجهول لازم يفضل نصًّا مستحيل يبقى نوعًا يومًا ما. */
    $reject('نوع حركة مجهول بيترفض',
        static fn () => CompanySafe::create(['entry_date' => work_date(), 'kind' => '__nope__', 'amount' => 10]));
    $reject('مبلغ صفر بيترفض',
        static fn () => CompanySafe::create(['entry_date' => work_date(), 'kind' => 'deposit', 'amount' => 0]));
    $reject('مبلغ سالب بيترفض',
        static fn () => CompanySafe::create(['entry_date' => work_date(), 'kind' => 'deposit', 'amount' => -50]));

    $lc = Closing::lastClosedDate();
    if ($lc !== null) {
        $reject('تحويل على يوم متقفل بيترفض',
            static fn () => CompanySafe::create(['entry_date' => $lc, 'kind' => 'to_office', 'amount' => 10]));
        /* ⚠️ الإيداع **لازم يعدّي** على اليوم المتقفل — هو ما بيمسّش الدرج
           فمفيش رصيد مثبَّت بيتغيّر. منعه كان هيبقى تشديدًا بلا سبب. */
        $before = CompanySafe::balance();
        CompanySafe::create(['entry_date' => $lc, 'kind' => 'deposit', 'amount' => 10]);
        near('⚠️ الإيداع على يوم متقفل بيعدّي (ما بيمسّش الدرج)',
             CompanySafe::balance(), $before + 10);
    } else {
        note('مفيش يوم متقفل — فحص القفل اتخطّى');
    }

    sec('⑤ تحصيل العملاء — التوجيه الجديد');
    isset(ClientPayment::routeChoices()['company'])
        ? ok('`routeChoices()` فيها company') : bad('company مش في routeChoices');

    $agent = Database::row('SELECT id,name FROM agents ORDER BY id LIMIT 1');
    if ($agent) {
        $c0 = CompanySafe::balance(); $o0 = Custody::balance();
        $pid = ClientPayment::create(['client_name' => $agent['name'], 'agent_id' => (int) $agent['id'],
            'pay_date' => work_date(), 'amount' => 25000, 'note' => 'فحص', 'route' => 'company']);
        near('التحصيل زوّد خزنة الشركة', CompanySafe::balance(), $c0 + 25000);
        near('⚠️ التحصيل ما مسّش خزنة المكتب', Custody::balance(), $o0);
        $pay = Database::row('SELECT route, company_entry_id FROM client_payments WHERE id=?', [$pid]);
        !empty($pay['company_entry_id'])
            ? ok('الدفعة مربوطة بحركة الخزنة (company_entry_id)')
            : bad('الدفعة مش مربوطة — العكس مش هيلاقي الحركة');

        $entryId = (int) $pay['company_entry_id'];
        // ⚠️ التحصيل ممنوع يتحذف من شاشة خزنة الشركة — الدفعة مصدر الحقيقة
        $reject('حذف حركة تحصيل من خزنة الشركة بيترفض',
            static fn () => CompanySafe::delete($entryId));

        ClientPayment::reverse($pid, 'فحص');
        near('عكس التحصيل رجّع رصيد خزنة الشركة', CompanySafe::balance(), $c0);
        CompanySafe::find($entryId) === null
            ? ok('صف الحركة اتشال مع عكس الدفعة')
            : bad('⚠️ صف الحركة فضل بعد العكس — فلوس تحصيل ملغي');
    } else {
        note('مفيش وكلاء — فحص التحصيل اتخطّى');
    }

    sec('⑥ walletSplit — معنى `total` ما اتغيّرش');
    $w = Closing::walletSplit();
    foreach (['drawer', 'reps_total', 'total', 'company', 'grand'] as $k) {
        array_key_exists($k, $w) ? ok("المفتاح `{$k}` موجود") : bad("المفتاح `{$k}` ناقص");
    }
    /* ⚠️⚠️ الفحص ده هو اللي بيحمي `bin/verify_safe_rule.php` من الكسر: فيه
       قاعدة صريحة «الإجمالي = الدرج + المناديب دايمًا». لو خزنة الشركة اتضافت
       لـ`total` الفاحص ده بيفشل — عشان كده ليها مفتاحان مستقلان. */
    near('⚠️ `total` = الدرج + المناديب (خزنة الشركة **مش** فيه)',
         $w['total'], round($w['drawer'] + $w['reps_total'], 2));
    near('`company` = `CompanySafe::balance()` بالمليم', $w['company'], CompanySafe::balance());
    near('`grand` = total + company', $w['grand'], round($w['total'] + $w['company'], 2));

    sec('⑧ رندر فعلي');
    CompanySafe::create(['entry_date' => work_date(), 'kind' => 'deposit',
                         'amount' => 7000, 'source' => 'البنك الأهلي', 'note' => 'شيك فحص']);
    CompanySafe::create(['entry_date' => work_date(), 'kind' => 'to_office', 'amount' => 3000]);

    $render = static function (callable $fn): string {
        ob_start(); $fn(); return (string) ob_get_clean();
    };
    $clean = static function (string $label, string $html): void {
        $errs = [];
        foreach (['Fatal error', 'Warning:', 'Undefined variable', 'Undefined array key', 'Deprecated'] as $b) {
            if (str_contains($html, $b)) { $errs[] = $b; }
        }
        $errs ? bad("{$label} فيها أخطاء PHP: " . implode('، ', $errs)) : ok("{$label} اترندرت نضيفة");
    };

    $_SERVER['REQUEST_URI'] = '/company-safe';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $page = $render(static fn () => (new App\Controllers\CompanySafeController())->index());
    $clean('شاشة /company-safe', $page);
    /* ⚠️ **فحص على المعنى مش على صياغة بعينها**: «خزنة المكتب» جزء ثابت من وسم
       الصف المؤثّر مهما اتغيّرت الجملة حواليه. النسخة الأولى فحصت الجملة
       الكاملة فكسرت أول ما اتحسنت الصياغة — إنذار كاذب. */
    foreach (['البنك الأهلي', 'شيك فحص', 'خزنة المكتب'] as $needle) {
        str_contains($page, $needle) ? ok("الشاشة بتعرض «{$needle}»") : bad("«{$needle}» مش في الشاشة");
    }
    /* ⚠️ **الفحص على المعنى: الرصيد وتوزيعه ظاهرين** — مش على عدد بطاقات
       `d-kpi`. الشاشة اتعاد تصميمها في v68 (تبويبات + درج) والبطاقات بقت
       `.cs-kpi`؛ الفحص القديم كان بيقيس **شكلًا** فكسر مع أول إعادة تصميم
       مشروعة. شكل الواجهة الحالي بيحرسه `verify_v67`. */
    (str_contains($page, money0(CompanySafe::balance(), false))
        && substr_count($page, 'data-drill=') >= 3)
        ? ok('الرصيد ظاهر ومعاه نقاط تفاصيل (ممنوع رقم أصمّ)')
        : bad('الرصيد أو نقاط تفاصيله ناقصة');

    foreach (['balance', 'in', 'to_office', 'day', 'wallets'] as $what) {
        $h = $render(static fn () => (new App\Controllers\CompanySafeController())->drill($what));
        $clean("نافذة «{$what}»", $h);
        substr_count($h, 'd-row') > 0 ? ok("نافذة «{$what}» فيها بنود") : bad("نافذة «{$what}» فاضية");
    }

    sec('⑨ التصميم — الهيرو وشريط التوزيع والإجراء السريع');

    /* ⚠️ **كارت الرصيد بيجاوب على «الفلوس فين» في نظرة**: شريط مكدّس بتلات
       أجزاء، وكل جزء **زرار بيفتح تفاصيله** (ممنوع رقم أصمّ على الرسم كمان).
       ⚠️ الاسم اتغيّر من `cs-hero` لـ`cs-card hero` في إعادة تصميم v68 —
          الفحص على **وجود الكارت وسلوكه** لا على اسم كلاس بعينه. */
    str_contains($page, 'cs-card hero') ? ok('كارت الرصيد اترسم') : bad('كارت الرصيد مش موجود');
    substr_count($page, 'cs-seg ') >= 2
        ? ok('شريط توزيع الكاش فيه أجزاء متعددة') : bad('شريط التوزيع ناقص');
    preg_match_all('~class="cs-seg[^"]*"[^>]*data-drill=~', $page, $m);
    count($m[0]) === substr_count($page, 'class="cs-seg')
        ? ok('⚠️ كل جزء في الشريط بيفتح تفاصيله (مفيش رسم أصمّ)')
        : bad('فيه جزء في شريط التوزيع بلا تفاصيل');
    substr_count($page, 'class="cs-leg"') >= 3
        ? ok('مفتاح التوزيع بتلات محافظ (شركة · درج · مناديب)') : bad('مفتاح التوزيع ناقص');

    /* ⚠️⚠️ **أهم فحص في التصميم**: أزرار النوع لازم تفضل `radio` حقيقي — فلو
       الجافاسكريبت وقع، المدير لسه يقدر يسجّل حركة. لو اتحوّلت لـ`button`
       بجافاسكريبت، الفورم بيموت بالكامل عند أول خطأ سكربت. */
    /* ⚠️ الفورم عاش في الصفحة لحد v67 وبقى في **درج** من v68، فبيتفتح بـ`?new=1`.
       القاعدة المحروسة زي ما هي: كروت النوع لازم تفضل `radio` حقيقي. */
    $_GET = ['tab' => 'overview', 'new' => '1'];
    $entry = $render(static fn () => (new App\Controllers\CompanySafeController())->index());
    $_GET = [];
    preg_match_all('~<input type="radio" name="kind"~u', $entry, $mk);
    count($mk[0]) === count(CompanySafe::MANUAL_KINDS)
        ? ok('⚠️ أزرار النوع `radio` حقيقي — الفورم شغّال بلا جافاسكريبت')
        : bad('أزرار النوع مش radio — الفورم بيعتمد على السكربت');
    substr_count($entry, 'data-cs-amount=') >= 4
        ? ok('شرايح المبالغ السريعة موجودة') : bad('المبالغ السريعة ناقصة');

    /* التنبيه تحذير وظيفي: بيظهر بس لما الدرج فاضي/واطي — مش شرح دايم. */
    $lowRule = Custody::balance() < CompanySafe::officeLowThreshold() || Custody::balance() < 0.005;
    $lowRule === str_contains($page, 'class="cs-alert')
        ? ok('شريط التصرّف بيظهر بس لما الدرج واطي (تحذير وظيفي)')
        : bad('شريط التصرّف ظاهر/مخفي غلط');

    $pdo->rollBack();
    echo "\n  ↩ اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bad('استثناء: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ═════════════════════════════════════════════ ⑤ب مصدر واحد للتوجيه
sec('⑤ب قايمة التوجيه — مصدر واحد للشاشة والخادم');
foreach ([
    'app/Controllers/PaymentController.php'   => 'الكنترولر /payments',
    'app/Controllers/ClientController.php'    => 'الكنترولر /clients',
] as $f => $label) {
    $c = code_only($f);
    str_contains($c, 'routeChoices()')
        ? ok("{$label} بيقرا `routeChoices()`")
        : bad("{$label} بيكتب قايمة التوجيه بإيده — خيار معروض والخادم يرفضه بالسكوت");
    preg_match("/\['cash',\s*'visa'\]/", $c)
        ? bad("{$label} لسه فيه القايمة القديمة المكتوبة بالإيد") : ok("{$label} بلا قايمة مكتوبة بالإيد");
}
foreach ([
    'app/Views/payments/index.php'   => 'شاشة /payments',
    'app/Views/clients/_tab_payments.php' => 'شاشة تحصيل العميل',
] as $f => $label) {
    str_contains(src($f), 'routeChoices()')
        ? ok("{$label} بتقرا `routeChoices()` قبل عرض الخيار")
        : bad("{$label} بتعرض الخيار بلا فحص الجاهزية");
}

// ⚠️ `reverse` لازم تشيل صف خزنة الشركة كمان
str_contains(code_only('app/Models/ClientPayment.php'), 'CompanySafe::deleteRaw')
    ? ok('`ClientPayment::reverse` بتشيل صف خزنة الشركة')
    : bad('عكس التحصيل مش بيشيل حركة خزنة الشركة — فلوس تحصيل ملغي بتفضل');

// ═════════════════════════════════════════════ ⑦ المسارات والصلاحيات
sec('⑦ المسارات والصلاحيات');
/* ⚠️ **بلا تعليقات**: التعليق فوق المسارات بيذكر المسار الجشع بالنص، فـ`strpos`
   على المصدر الخام بتلاقيه **قبل** الحرفي وتدّي فشلًا كاذبًا. نفس مصيدة
   `code_only` الموثَّقة (وقعت فعليًّا وأنا بكتب الفحص ده). */
$routes = code_only('public/index.php');
$posDrill = strpos($routes, "/company-safe/drill/{what}");
$posGreedy = strpos($routes, "/company-safe/{id}/delete");
($posDrill !== false && $posGreedy !== false && $posDrill < $posGreedy)
    ? ok('⚠️ `/company-safe/drill/{what}` قبل الجشع `/company-safe/{id}/delete` (تحذير ٤)')
    : bad('ترتيب المسارات غلط — الجشع هيبلع الحرفي');
foreach (['/company-safe', '/company-safe/drill/{what}', '/company-safe/{id}/delete'] as $r) {
    str_contains($routes, $r) ? ok("المسار {$r} متسجّل") : bad("المسار {$r} مش متسجّل");
}

$access = src('app/Core/Access.php');
str_contains($access, "'CompanySafeController'")
    ? ok('`CompanySafeController` في FINANCIAL_CONTROLLERS (المحاسب يكتب، «قراءة فقط» يشوف بس)')
    : bad('الكنترولر مش في أي قايمة صلاحيات — الكتابة هتترفض للمحاسب');

foreach (['owner' => true, 'manager' => true, 'accountant' => true, 'viewer' => false] as $role => $canWrite) {
    $got = App\Core\Access::allows($role, 'CompanySafeController@store', 'POST');
    $got === $canWrite ? ok("الدور «{$role}» " . ($canWrite ? 'يقدر' : 'مايقدرش') . ' يسجّل حركة')
                       : bad("صلاحية الدور «{$role}» غلط (متوقع " . var_export($canWrite, true) . ')');
}

// القائمة الجانبية
/* ⚠️ القائمة اتنقلت من `layout.php` لـ`NavModule::DEFAULTS` في v77 — الفحص
   بقى على **وجود البند في القائمة** مش على وجود نص في ملف بعينه. */
str_contains(src('app/Models/NavModule.php'), "'CompanySafeController@index'")
    ? ok('بند «خزنة الشركة» في القائمة الجانبية') : bad('البند مش في القائمة');

// ═════════════════════════════════════════════ ⑨ب كل كلاس مطبوع له شكل
sec('⑨ب كل كلاس `.cs-*` له CSS (مصيدة `.table-mobile-scroll`)');

/* ⚠️⚠️ أكتر باج بيتكرر في المشروع: كلاس في الماركب ومالوش تعريف بيقع على
   الشكل الافتراضي **بالسكوت** — `.table-mobile-scroll` عاش شهور كده،
   و`.btn.primary` و`.cars-period` نفس الحكاية. */
/* ⚠️ الفحص على **كل** كلاس الشاشة بتطبعه مش على `.cs-*` بس — المصيدة اتصادت
   فعليًّا وأنا بابني التصميم: استخدمت `w180` وهي **مش معرَّفة** (الموجود
   `w160`/`w150`)، فالحقل كان هياخد عرضًا افتراضيًّا بالسكوت. الفحص الضيّق
   كان هيعدّيها. */
$css = src('public/assets/css/app.css');
foreach (glob(dirname(__DIR__) . '/public/assets/js/*.js') ?: [] as $js) {
    $css .= "\n/*JS*/" . (string) file_get_contents($js);
}
$printed = [];
foreach (['app/Views/company_safe/index.php', 'app/Views/company_safe/_drill.php'] as $f) {
    if (preg_match_all('~class="([^"$<]+)"~u', src($f), $m)) {
        foreach ($m[1] as $chunk) {
            foreach (preg_split('~\s+~', trim($chunk)) as $cls) {
                if ($cls !== '') { $printed[$cls] = true; }
            }
        }
    }
}
$missing = array_values(array_filter(array_keys($printed),
    static fn (string $c) => !preg_match('~[."\']' . preg_quote($c, '~') . '\b~', $css)));
$csCount = count(array_filter(array_keys($printed), static fn ($c) => str_starts_with($c, 'cs-')));
$printed ? ok('الشاشة بتطبع ' . count($printed) . " كلاس (منهم {$csCount} `.cs-*`)")
         : bad('مفيش كلاسات — الشاشة مش متنسّقة');
$missing ? bad('⚠️ كلاس مطبوع بلا شكل ولا سلوك: ' . implode('، ', $missing))
         : ok('⚠️ كل كلاس مطبوع له تعريف في app.css أو خطّاف في JS');

/* ⚠️ **في `app.css` لا `<style>` محلي** (قانون التصميم بند ٧): الشاشة والنافذة
   بيشاركوا `.cs-seg`/`.cs-dot`، ولو عاشوا في شاشة واحدة النافذة ترندر بلا ستايل. */
/* ⚠️ **بلا تعليقات**: التعليق فوق الشاشة بيوثّق القاعدة وبيذكر `<style>` بالنص،
   فالعدّ على المصدر الخام بيلاقيه ويدّي فشلًا كاذبًا. نفس مصيدة `code_only`
   اللي وقعت في فحص ترتيب المسارات فوق — **مرتين في نفس الملف**. */
$localStyle = 0;
foreach (['app/Views/company_safe/index.php', 'app/Views/company_safe/_drill.php'] as $f) {
    $localStyle += substr_count(code_only($f), '<style');
}
$localStyle === 0 ? ok('صفر `<style>` محلي في شاشات الوحدة')
                  : bad("فيه {$localStyle} بلوك `<style>` محلي — نقله لـapp.css");

/* الخطّاف في `app.js` العام — بوابة `/m` ما بتحمّلش app.css لكن الشاشة دي مكتب،
   والمهم إن السكربت مش inline. */
str_contains(src('public/assets/js/app.js'), 'data-cs-amount')
    ? ok('خطّاف الفورم في `app.js` العام مش inline') : bad('خطّاف الفورم مش في app.js');

echo "\n" . str_repeat('=', 62) . "\n";
printf("النتيجة: %d نجاح · %d فشل\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
