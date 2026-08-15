<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v71 — وسم النثرية (سلفة · مواصلات داخلية · مواصلات خارجية · عامة).
 *
 * أهم ٧ حاجات بيتأكد منها:
 *   ① **الحياد المالي**: نفس النثرية بأي وسم بتخصم **نفس الرقم** من الخزنة ومن
 *      عهدة المندوب. الوسم وسم — لو غيّر مليمًا يبقى بقى قرارًا ماليًّا بالسكوت.
 *   ② **الوسم بيوصل من الفورم للجدول النهائي** ومعاه `category` المشتق منه.
 *   ③ **إجباري على النثرية · ممنوع على بند الإذن** — والمجهول بيترفض.
 *   ④ **السلفة كاش بنيويًّا**: وسم الفيزا بيتلغى حتى لو الفورم بعته (فورم متزوّر).
 *   ⑤ **الصف القديم (NULL) بيتحسب `general`** — مفيش صف بيختفي من أي تجميع،
 *      و`totalsByKind` مجموعها = مجموع الصفوف بالظبط.
 *   ⑥ **المصدر الوحيد**: ولا مفتاح وسم مكتوب حرفيًّا برّه `RepMiscKind`.
 *   ⑦ **الواجهة**: كل كلاس `.mk-*` بيتطبع له CSS في `m.css`، وكل أيقونة في
 *      الكتالوج موجودة في `icon()` (الناقصة بترسم دايرة فاضية بلا خطأ).
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v71.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;
use App\Models\RepInbox;
use App\Models\RepMaintenance;
use App\Models\RepMiscExpense;
use App\Models\RepMiscKind;
use App\Models\RepPortal;
use App\Models\RepSubmissionValidationException;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
/** إشارة داخلية: «اتخطّى قسم السلوك» — مش خطأ ومش فشل. */
class SkipBehaviour extends \RuntimeException {}

function src71(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
}
/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع. */
function code71(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص v71 — وسم النثرية\n" . str_repeat('=', 62) . "\n";

if (!RepMiscExpense::kindReady() || !RepPortal::miscKindReady()) {
    echo "\n⚠️ عمود misc_kind مش موجود — شغّل php bin/migrate_v71.php الأول.\n";
    exit(1);
}

$treasuryBefore = Closing::currentBalance();
$repsBefore     = RepCustody::available() ? RepCustody::totalOutstanding() : 0.0;

// ═══════════════════════════════ ⓪ الكتالوج نفسه (بلا كتابة)
sec('⓪ الكتالوج: مفاتيح ثابتة وتصنيفات فريدة');

$keys = RepMiscKind::keys();
chk(count($keys) >= 4, 'الكتالوج فيه ٤ أنواع على الأقل (' . count($keys) . ')');
chk(in_array(RepMiscKind::DEFAULT, $keys, true), 'النوع الافتراضي جوّه الكتالوج');
chk(RepMiscKind::category(RepMiscKind::DEFAULT) === RepMiscExpense::CATEGORY,
    '★ تصنيف النوع العام مطابق لـRepMiscExpense::CATEGORY (وإلا اسمان لنفس الشيء)');

/* ⚠️ التصنيف هو اللي بيتعرض في `/expenses` وكشف حساب المندوب — لو نوعان
   بنفس التصنيف، المالك يشوف سطرين متطابقين ومش عارف مين مين. */
$cats = array_map([RepMiscKind::class, 'category'], $keys);
chk(count(array_unique($cats)) === count($cats),
    'ولا تصنيفين متطابقين (' . implode(' · ', $cats) . ')');

/* ⚠️ الطول لازم يوسع أطول مفتاح — VARCHAR(16) بيقصّ بالسكوت في MySQL غير
   الصارم، والقيمة المقصوصة بتبقى برّه الكتالوج فتقع على general للأبد. */
$longest = max(array_map('strlen', $keys));
chk($longest <= 16, "أطول مفتاح ($longest حرف) جوّه VARCHAR(16)");

foreach ($keys as $k) {
    chk(RepMiscKind::normalize($k) === $k, "normalize بتحافظ على «{$k}»");
}
chk(RepMiscKind::normalize('') === RepMiscKind::DEFAULT, 'الفاضي بيقع على النوع العام');
chk(RepMiscKind::normalize(null) === RepMiscKind::DEFAULT, 'وNULL كمان');
chk(RepMiscKind::normalize('__مش_موجود__') === RepMiscKind::DEFAULT, 'والمجهول كمان');
chk(!RepMiscKind::has(''), 'has بترفض الفاضي');
chk(!RepMiscKind::has('__مش_موجود__'), 'وبترفض المجهول');

/* ⚠️ الأيقونة الناقصة بترسم **دايرة فاضية بلا خطأ** (مصيدة موثَّقة). */
$blank = icon('__لا_يوجد_إطلاقًا__', 14);
foreach ($keys as $k) {
    chk(icon(RepMiscKind::icon($k), 14) !== $blank,
        "أيقونة «{$k}» (" . RepMiscKind::icon($k) . ") موجودة في icon()");
}

// ═══════════════════════════════ ① المصدر الوحيد
sec('① المصدر الوحيد — ولا مفتاح وسم مكتوب بالإيد');

/* ⚠️ الخطر الحقيقي مش كلمة `advance` في أي مكان (كلمة شايعة)، لكن **مقارنة
   العمود بقيمة مكتوبة بالإيد** في SQL: `misc_kind = 'advance'`. تغيير مفتاح
   في الكتالوج بعدها بيسيب الاستعلام ده بيقارن على قيمة مالهاش وجود — **وما
   بيرميش خطأ**، بيرجّع صفر وبس. المصدر الوحيد `RepMiscExpense::kindSql()`. */
$OWNERS = ['app/Models/RepMiscKind.php', 'app/Models/RepMiscExpense.php'];
$scan = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/app'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $rel = str_replace(dirname(__DIR__) . '/', '', $f->getPathname());
        if (in_array($rel, $OWNERS, true)) { continue; }
        $scan[$rel] = code71($rel);
    }
}
$keyAlt  = implode('|', array_map(static fn($k) => preg_quote($k, '~'), $keys));
$handSql = [];
foreach ($scan as $rel => $code) {
    /* الشرط بيطلب **مفتاحًا حقيقيًّا من الكتالوج** بعد علامة المقارنة: المقارنة
       بمعامل مربوط (`?`) سليمة، والإسناد في جافاسكريبت (`errors.misc_kind = '…'`)
       مش SQL أصلاً — من غير القيد ده الفحص بيدّي إنذارًا كاذبًا على الشاشة. */
    if (preg_match('~misc_kind\s*(=|<>|!=|\bIN\b\s*\()\s*[\'"](' . $keyAlt . ')[\'"]~i', $code)) {
        $handSql[] = $rel;
    }
}
chk($handSql === [], '★ ولا ملف بيقارن misc_kind بقيمة مكتوبة بالإيد في SQL'
    . ($handSql ? ' — ' . implode(' · ', $handSql) : ''));

/* والتصنيف العربي بيتكتب من `RepMiscKind::category()` وبس — أي `INSERT`/`UPDATE`
   تانية على `rep_misc_expenses.category` بتعمل تسمية موازية. */
$catWriters = [];
foreach ($scan as $rel => $code) {
    if (preg_match('~(INSERT\s+INTO|UPDATE)\s+rep_misc_expenses~i', $code)) { $catWriters[] = $rel; }
}
chk($catWriters === [], 'وولا ملف تاني بيكتب في rep_misc_expenses مباشرةً'
    . ($catWriters ? ' — ' . implode(' · ', $catWriters) : ''));

// `create()` لازم تشتقّ التصنيف من الوسم لا تاخده من المستدعي
chk(!preg_match('~\$d\[.category.\]~', code71('app/Models/RepMiscExpense.php')),
    '★ RepMiscExpense::create ما بتاخدش category من المستدعي — بتشتقّه من الوسم');

/* الكتالوج هو المكان الوحيد اللي فيه التسميات — لو `RepMiscKind` نفسها بقت
   بتقرا من مكان تاني تبقى الحلقة اتقفلت غلط. */
chk(!str_contains(code71('app/Models/RepMiscKind.php'), 'Database'),
    'والكتالوج ثابت في الكود — مش بيقرا من القاعدة');

// ═══════════════════════════════ ② السلوك (جوّه transaction)
/* ⚠️⚠️ **حارسان لازم يتسألوا قبل أي كتابة، وإلا الفحص بيموت بلا رسالة.**
   ① `RepMaintenance` (v68): قفل بوابة المندوب بيخلّي `RepPortal::submit` ترمي.
   ② اليوم المتقفل: `dayLockReason` بترفض الكتابة عليه.
   الاتنين **حالة بيئة مش عيب في الكود** — الفحص بيقولها بصوت عالي وبيتخطّى
   قسم السلوك بدل ما يفشل كذبًا (اتصادفوا على الإنتاج فعليًّا). */
$writeBlock = null;
if (RepMaintenance::isOn()) {
    $writeBlock = 'بوابة المندوب مقفولة للصيانة — افتحها من /settings#rep-maint وأعد الفحص.';
} elseif (RepCustody::dayLockReason(date('Y-m-d')) !== null) {
    $writeBlock = 'يوم ' . date('Y-m-d') . ' متقفل — الفحص محتاج يومًا مفتوحًا.';
}

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    if ($writeBlock !== null) {
        echo "\n⚠️ قسم السلوك اتخطّى: {$writeBlock}\n";
        throw new SkipBehaviour();
    }

    $rep = (int) Database::scalar("SELECT id FROM reps WHERE status = 'active' ORDER BY id LIMIT 1");
    if (!$rep) { throw new RuntimeException('مفيش مندوب نشط في القاعدة دي — الفحص محتاج واحد.'); }

    $day = date('Y-m-d');
    $tag = '__v71_';

    /* ═══════════════════════════════════════════════════════════════════
       ⚠️⚠️ **المسار الكامل من `$_POST` — مش من الموديل مباشرةً.**

       النسخة الأولى من الفحص ده كانت بتنادي `RepPortal::submit()` وبتحطّ
       `misc_kind` بإيدها، فعدّت ١٠٠٪ — والمندوب على الأرض كان بياخد **«اختر
       نوع النثرية» وهو مختار النوع فعلاً** (بلاغ المالك 2026-08-10).
       السبب: `RepPortalController::postedData()` **قايمة بيضا** بتنقّي حقول
       بعينها من `$_POST`، و`misc_kind` مكانش فيها — فالكنترولر كان بيرمي
       الوسم قبل ما الموديل يشوفه. الفحص كان بيقفز فوق الحلقة المقطوعة.

       القاعدة المستخلَصة: **أي حقل جديد في فورم لازم يتفحص من `$_POST`**،
       لأن نقطة الكسر مش في الموديل ولا في الشاشة — في الوصلة بينهم.
       ═══════════════════════════════════════════════════════════════════ */
    sec('②أ المسار الكامل: من `$_POST` للجدول (الوصلة اللي انكسرت فعلاً)');

    $posted = new ReflectionMethod(\App\Controllers\RepPortalController::class, 'postedData');
    $posted->setAccessible(true);
    $ctrl = (new ReflectionClass(\App\Controllers\RepPortalController::class))
        ->newInstanceWithoutConstructor();

    $postBackup = $_POST;
    foreach (RepMiscKind::keys() as $k) {
        $_POST = ['kind' => 'misc', 'misc_kind' => $k, 'note' => $tag . 'post',
                  'qty' => '1', 'unit_amount' => '12', 'client_token' => bin2hex(random_bytes(16))];
        $d = $posted->invoke($ctrl, 0, true);
        chk(($d['misc_kind'] ?? null) === $k,
            "★ `postedData` بتوصّل وسم «{$k}» من الفورم للموديل");
    }
    $_POST = $postBackup;

    // والوصلة التانية: درج تعديل المكتب — كان بيحفظ بلا ما يغيّر حاجة (فشل صامت)
    $inbox = code71('app/Controllers/RepCustodyController.php');
    chk(str_contains($inbox, "\$_POST['misc_kind']"),
        '★ ودرج تعديل المكتب بيقرا الوسم من الفورم');
    chk(preg_match('~misc_kind.{0,80}RepInbox::update~s', $inbox)
        || preg_match('~inboxData\[.misc_kind.\]~', $inbox),
        'وبيوصّله لـRepInbox::update فعلاً (مش بيتجمّع ويتنسى)');

    sec('② الوسم بيوصل من الفورم لجدول النثريات ومعاه تصنيفه');

    /** إرسال نثرية + اعتمادها، وبترجّع صف `rep_misc_expenses`. */
    $flow = static function (string $kind, float $unit, array $extra = []) use ($rep, $day, $tag): array {
        $subId = RepPortal::submit($rep, array_merge([
            'kind' => 'misc', 'misc_kind' => $kind, 'note' => $tag . $kind,
            'qty' => 1, 'unit_amount' => $unit, 'entry_date' => $day,
        ], $extra));
        $miscId = RepInbox::approve($subId);
        return [$subId, (array) RepMiscExpense::find($miscId)];
    };

    foreach (['advance', 'transport_in', 'transport_out', 'general'] as $i => $k) {
        if (!RepMiscKind::has($k)) { bad("النوع «{$k}» اختفى من الكتالوج"); continue; }
        [$subId, $row] = $flow($k, 10.0 + $i);
        chk((string) Database::scalar('SELECT misc_kind FROM rep_submissions WHERE id=?', [$subId]) === $k,
            "«{$k}»: الوسم اتخزّن على البند المعلَّق");
        chk((string) ($row['misc_kind'] ?? '') === $k, "«{$k}»: والوسم اتنقل لصف النثرية بعد الاعتماد");
        chk((string) ($row['category'] ?? '') === RepMiscKind::category($k),
            "«{$k}»: والتصنيف المعروض مشتقّ من الوسم («" . RepMiscKind::category($k) . "»)");
    }

    sec('③ إجباري على النثرية · ممنوع على بند الإذن · المجهول مرفوض');

    foreach ([['', 'بلا وسم'], ['__مجهول__', 'بوسم مجهول']] as [$bad, $label]) {
        try {
            RepPortal::submit($rep, ['kind' => 'misc', 'misc_kind' => $bad,
                'note' => $tag . 'رفض', 'qty' => 1, 'unit_amount' => 5, 'entry_date' => $day]);
            bad("★ نثرية $label اتقبلت!");
        } catch (RepSubmissionValidationException $e) {
            // ⚠️ لازم يترفض **بسبب الوسم** تحديدًا، وإلا نجاح كاذب من حقل تاني.
            chk(isset($e->errors()['misc_kind']), "نثرية $label اترفضت على خانة الوسم");
        }
    }

    // بند على إذن: الوسم بيتشال حتى لو الفورم بعته (فورم متزوّر)
    $permitId = (int) Database::scalar(
        "SELECT id FROM permits WHERE status = 'open' ORDER BY id DESC LIMIT 1");
    if ($permitId > 0) {
        $movId = Database::scalar('SELECT id FROM permit_movements WHERE permit_id=? LIMIT 1', [$permitId]);
        try {
            $pSub = RepPortal::submit($rep, [
                'permit_id' => $permitId, 'movement_id' => $movId ? (int) $movId : null,
                'kind' => 'expense', 'misc_kind' => 'advance', 'note' => $tag . 'إذن',
                'qty' => 1, 'unit_amount' => 7, 'entry_date' => $day,
            ]);
            chk(Database::scalar('SELECT misc_kind FROM rep_submissions WHERE id=?', [$pSub]) === null,
                '★ بند على إذن بعت وسمًا → اتخزّن NULL (الموديل هو الحارس)');
        } catch (\Throwable $e) {
            bad('بند على إذن اترفض لسبب تاني: ' . $e->getMessage());
        }
    } else {
        echo "  (مفيش إذن مفتوح — فحص بند الإذن اتخطّى)\n";
    }

    sec('④ السلفة كاش بنيويًّا — وسم الفيزا بيتلغى');

    $cashOnly = array_values(array_filter($keys, [RepMiscKind::class, 'isCashOnly']));
    chk($cashOnly !== [], 'فيه نوع واحد على الأقل «كاش بس» (' . implode(', ', $cashOnly) . ')');
    $card = (int) Database::scalar('SELECT id FROM visa_cards ORDER BY id LIMIT 1');
    foreach ($cashOnly as $k) {
        [$subId, $row] = $flow($k, 33.0, ['is_visa' => 1, 'visa_card_id' => $card ?: 1]);
        chk((int) Database::scalar('SELECT is_visa FROM rep_submissions WHERE id=?', [$subId]) === 0,
            "★ «{$k}»: وسم الفيزا اتلغى على البند رغم إن الفورم بعته");
        chk((int) ($row['is_visa'] ?? 1) === 0, "و«{$k}»: صف النثرية اتخزّن كاش");
        chk(($row['visa_card_id'] ?? null) === null, "و«{$k}»: ولا كارت اتنسب له");
    }

    sec('⑤ الحياد المالي — الوسم ما بيغيّرش ولا مليم');

    /* ⚠️ ده جوهر v71: نفس المبلغ بوسمين مختلفين لازم يخصم **نفس الرقم**
       من الخزنة ومن عهدة المندوب. لو اتفرّقوا يبقى الوسم بقى قرارًا ماليًّا. */
    $notCash = array_values(array_filter($keys, static fn($k) => !RepMiscKind::isCashOnly($k)));
    chk(count($notCash) >= 2, 'فيه نوعين كاش-وفيزا على الأقل للمقارنة');
    $moves = [];
    foreach (array_slice($notCash, 0, 2) as $k) {
        $t0 = Closing::currentBalance();
        $c0 = RepCustody::balance($rep);
        $flow($k, 500.0);
        $moves[$k] = [round($t0 - Closing::currentBalance(), 2), round($c0 - RepCustody::balance($rep), 2)];
    }
    $vals = array_values($moves);
    chk($vals[0] === $vals[1],
        '★ نوعان مختلفان بنفس المبلغ خصموا نفس الرقم بالظبط ('
        . implode(' / ', $vals[0]) . ' مقابل ' . implode(' / ', $vals[1]) . ')');
    near('والخصم من عهدة المندوب = المبلغ كامل', $vals[0][1], 500.0);

    sec('⑥ الصف القديم (NULL) بيتحسب «عام» — ولا صف بيختفي');

    // نثرية بلا وسم كأنها قبل الترحيل (كتابة خام بتتخطّى الموديل عن قصد)
    Database::exec(
        "INSERT INTO rep_misc_expenses (rep_id, exp_date, category, note, qty, unit_amount, amount)
         VALUES (?,?,?,?,1,777,777)", [$rep, $day, RepMiscExpense::CATEGORY, $tag . 'قديمة']);

    $byKind = RepMiscExpense::totalsByKind($rep);
    chk(array_keys($byKind) === $keys, 'totalsByKind بترجّع كل مفاتيح الكتالوج حتى الصفر');
    $sumKinds = round(array_sum($byKind), 2);
    $sumRows  = round((float) Database::scalar(
        'SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses
          WHERE rep_id = ? AND ' . RepMiscExpense::cashOnlySql()
        . RepCustody::epochSql('exp_date'), [$rep]), 2);
    near('★ مجموع كل الأوسمة = مجموع صفوف المندوب (ولا صف ضاع)', $sumKinds, $sumRows);
    chk($byKind[RepMiscKind::DEFAULT] >= 777.0,
        'والصف اللي بلا وسم اتحسب على النوع العام');

    // نفس الرقم من الطريق التاني — تعريفان بيختلفوا = كارت يقول رقم وكشف يقول تاني
    $perRep = RepMiscExpense::totalsByRepForKind(RepMiscKind::DEFAULT);
    near('totalsByRepForKind بتطابق totalsByKind لنفس النوع',
        $perRep[$rep] ?? 0.0, $byKind[RepMiscKind::DEFAULT]);

    // الفترة بتفلتر فعلاً (مش معامل متجاهَل)
    $future = date('Y-m-d', strtotime('+40 year'));
    chk(round(array_sum(RepMiscExpense::totalsByKind($rep, $future)), 2) === 0.0,
        'وفلتر الفترة شغّال (فترة مستقبلية = صفر)');

    sec('⑦ المكتب يقدر يصحّح الوسم قبل الاعتماد');

    $subId = RepPortal::submit($rep, [
        'kind' => 'misc', 'misc_kind' => 'general', 'note' => $tag . 'تصحيح',
        'qty' => 1, 'unit_amount' => 60, 'entry_date' => $day]);
    $target = $notCash[0] !== 'general' ? $notCash[0] : ($notCash[1] ?? 'general');
    RepInbox::update($subId, ['misc_kind' => $target]);
    chk((string) Database::scalar('SELECT misc_kind FROM rep_submissions WHERE id=?', [$subId]) === $target,
        "المكتب غيّر الوسم لـ«{$target}»");
    try {
        RepInbox::update($subId, ['misc_kind' => '__مجهول__']);
        bad('★ المكتب حطّ وسمًا مجهولًا!');
    } catch (\Throwable $e) { ok('والوسم المجهول من المكتب اترفض'); }

    /* ⚠️ التعديل الجماعي ما بيبعتش الحقل خالص — لازم يعدّي على نثرية بلا وسم
       بدل ما يرمي ويرجّع الدفعة كلها (نثريات ما قبل الترحيل لسه معلَّقة). */
    Database::exec(
        "INSERT INTO rep_submissions
            (rep_id, client_token, kind, note, qty, unit_amount, amount, entry_date, status)
         VALUES (?,?, 'misc', ?, 1, 90, 90, ?, 'pending')",
        [$rep, bin2hex(random_bytes(16)), $tag . 'بلا وسم', $day]);
    $legacy = Database::lastId();
    try {
        RepInbox::update($legacy, ['note' => $tag . 'بلا وسم معدّل']);
        ok('★ تعديل بند بلا وسم (مش بيبعت الحقل) عدّى بلا رفض');
    } catch (\Throwable $e) { bad('تعديل بند قديم بلا وسم اترفض: ' . $e->getMessage()); }
    chk(Database::scalar('SELECT misc_kind FROM rep_submissions WHERE id=?', [$legacy]) === null,
        'وفضل بلا وسم زي ما هو');

    sec('⑧ العزل: الوسم ما دخلش أي جدول خزنة');

    /* ⚠️ نفس نمط `rep_misc_expenses` نفسه (v39): ولا ملف خزنة يعرف الكتالوج،
       وإلا يبقى الوسم بقى مدخلًا في معادلة مالية. */
    foreach (['app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/Metrics.php',
              'app/Models/CompanySafe.php', 'bin/backfill_closings.php'] as $f) {
        chk(!str_contains(code71($f), 'RepMiscKind') && !str_contains(code71($f), 'misc_kind'),
            basename($f) . ' عمره ما سمع عن الوسم');
    }

} catch (SkipBehaviour $e) {
    // متخطّي بالتصميم — مش فشل.
} catch (\Throwable $e) {
    /* ⚠️ **إمساك كل شيء إجباري**: `ErrorHandler` بتاعة `bootstrap` بتبلع أي
       استثناء غير ملتقَط في CLI، فالفاحص كان **بيموت في نص الشاشة بلا ولا
       رسالة** وانت فاكر إنه اتقطع. اتصادفت على الإنتاج. */
    bad('انقطع قسم السلوك: ' . get_class($e) . ' — ' . $e->getMessage());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ═══════════════════════════════ ⑨ الواجهة
sec('⑨ الواجهة: كل كلاس بيتطبع له CSS، والوسم في الشاشتين');

/* ⚠️ الكلاس بلا CSS بيقع على الشكل الافتراضي **بالسكوت** (مصيدة
   `.table-mobile-scroll` اللي عاشت شهور). و`/m` ما بتحمّلش `app.css`
   فالكلاس لازم يعيش في `m.css` تحديدًا. */
$mcss = src71('public/assets/css/m.css');
$misc = src71('app/Views/m/misc.php');
foreach (['mk-field', 'mk-slide', 'mk-card', 'mk-ic'] as $cls) {
    $inView = str_contains($misc, $cls);
    $inCss  = str_contains($mcss, '.' . $cls);
    chk($inView && $inCss, ".{$cls} في الماركب وفي m.css مع بعض"
        . (!$inView ? ' — مش في الماركب' : '') . (!$inCss ? ' — مش في m.css' : ''));
}
chk(!str_contains($misc, '<style'), 'وصفر `<style>` محلي في شاشة النثرية');

/* الشاشة ما بتعرفش ولا مفتاح بالاسم — بتلفّ على الكتالوج. لو رجعت تكتب
   الأنواع في الماركب، أي نوع جديد هيحتاج تعديل في مكانين. */
chk(str_contains($misc, 'RepMiscKind::all()'), 'شاشة النثرية بتلفّ على الكتالوج مش على أسماء مكتوبة');

$inbox = src71('app/Views/rep_custody/inbox.php');
/* ⚠️ **الخانة في البارشيال مش في الشاشة** (v95): درج التعديل بقى مكوّنًا واحدًا
   لتبويبَي «مستني» و«معتمد». الشارة وCSS بتاعتها لسه في الشاشة (صف البند). */
chk(str_contains(src71('app/Views/partials/rep_submission_edit.php'), 'name="misc_kind"'),
    'ودرج تعديل المكتب فيه خانة الوسم');
chk(str_contains($inbox, 'ib-mkind') && str_contains($inbox, '.pill.ib-mkind'),
    'وشارة الوسم في صندوق الوارد ليها CSS');

echo "\n" . str_repeat('═', 62) . "\n";
echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
near('رصيد الخزنة قبل = بعد', Closing::currentBalance(), $treasuryBefore);
if (RepCustody::available()) {
    near('وإجمالي عهد المناديب قبل = بعد', RepCustody::totalOutstanding(), $repsBefore);
}
echo "\nنجح: $pass | فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
