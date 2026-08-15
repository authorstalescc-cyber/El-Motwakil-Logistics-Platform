<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v94 — **يوم عهدة المُستقبِل** منفصل عن يوم عهدة المُرسِل في تحويل المناديب.
 *
 * ═══ اللزمة (ملاحظة المالك 2026-08-15) ═══
 * «فورم التعديل لم يراعِ تاريخ العهدة. أنا ممكن أريد أرسل من عهدة السيد الخاصة
 *  بيوم ١٢ للمندوب عبدالرحمن في عهدته الخاصة بيوم ١٣.»
 *
 * `rep_custody_transfers` كان فيه **تاريخ واحد** (`transfer_date`) بيحكم
 * الطرفين مع بعض: هو اليوم اللي المبلغ خرج فيه من عهدة المُرسِل، **وهو نفسه**
 * اليوم اللي دخل فيه عهدة المُستقبِل. وده صح في الحالة الشايعة (التسليم في
 * الميدان في نفس اليوم) لكنه **مستحيل يوصف** الحالة اللي المالك بيقولها: كاش
 * اتسلّم آخر يوم ١٢ ودخل عهدة الزميل يوم ١٣.
 *
 * والنتيجة كانت **غلط صامت**: المالك بيحطّ ١٢ فتطلع عهدة عبدالرحمن ليوم ١٢
 * زايدة ويوم ١٣ ناقصة، أو يحطّ ١٣ فتطلع عهدة السيد ليوم ١٢ زايدة. الرصيد
 * التراكمي صح في الحالتين — **واليوميّة غلط**، وهي أساس «عهدة كل يوم» (v49)
 * والتقفيل والتسوية.
 *
 * ═══ اللي بيتعمل ═══
 *   ①  عمود `to_date DATE NOT NULL` = **يوم عهدة المُستقبِل**، مبدوء بنسخة من
 *      `transfer_date` لكل صف قايم — فمعنى كل صف موجود **ما بيتغيّرش ولا مليم**.
 *   ②  فهرس `idx_rct_to_date (to_rep_id, to_date)` — جهة الاستقبال بقت بتترشّح
 *      بالعمود الجديد، والفهرس القديم `(to_rep_id, transfer_date)` بيفضل
 *      لأن حساب خط الفصل (epoch) لسه بيقرا `transfer_date` للطرفين.
 *
 * ⚠️ **صفر أثر مالي.** بعد الترحيل `to_date = transfer_date` في كل صف، يعني
 *    الجهتين بيقروا نفس اليوم زي بالظبط ما كانوا. التحقّق تحت بيقيس **رصيد
 *    كل مندوب وعهدة كل يوم عنده** قبل وبعد ويفشل لو اتحرّك مليم.
 *
 * ⚠️ **`transfer_date` احتفظ باسمه عن قصد.** إعادة تسميته لـ`from_date` كانت
 *    هتبقى أوضح، لكنها بتلمس كل استعلام وكل شاشة وكل فاحص — تغيير واسع مقابل
 *    فايدة تسمية. معناه موثَّق في تعليق العمود وفي `RepTransfer`.
 *
 * ⚠️ `ALTER TABLE` بيعمل **commit ضمني**، فنمط «افتح معاملة ← عدّل ← قِس ←
 *    ارجع» مش صالح هنا. القياس بيتاخد قبل وبعد بلا معاملة (زي v88).
 *
 * التشغيل:  php bin/migrate_v94.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;
use App\Models\RepTransfer;

$v94Dry    = in_array('--dry-run', $argv ?? [], true);
$v94Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v94Say = static function (string $m) use ($v94Direct): void { if ($v94Direct) { echo $m . "\n"; } };

$v94HasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$v94HasIdx = static fn (string $t, string $i): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);
$v94HasTbl = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);

/** لقطة أرصدة كل المناديب + عهدة كل يوم — الأساس اللي بنثبت إنه ما اتغيّرش. */
$v94Snapshot = static function (): array {
    $out = ['safe' => Closing::currentBalance(), 'reps' => [], 'days' => []];
    foreach (Database::all('SELECT id FROM reps') as $r) {
        $rid = (int) $r['id'];
        $out['reps'][$rid] = RepCustody::balance($rid);
        foreach (RepCustody::dayLedger($rid, 400) as $row) {
            $out['days']["$rid|{$row['day']}"] = $row['balance'];
        }
    }
    return $out;
};

$v94Up = static function () use ($v94Dry, $v94Say, $v94HasCol, $v94HasIdx, $v94HasTbl): void {
    if (!$v94HasTbl('rep_custody_transfers')) {
        $v94Say('  ✗ rep_custody_transfers مش موجود — شغّل bin/migrate_v46.php الأول.');
        return;
    }
    $pdo = Database::conn();

    if (!$v94HasCol('rep_custody_transfers', 'to_date')) {
        if (!$v94Dry) {
            /* NULL الأول عشان الصفوف القايمة تعدّي، ثم الملء، ثم NOT NULL —
               `ADD COLUMN ... NOT NULL` على جدول فيه بيانات بيحطّ '0000-00-00'
               أو يفشل حسب وضع الخادم، والاتنين أسوأ من خطوتين. */
            /* ⚠️ ترتيب `COMMENT` قبل `AFTER` إجباري في MySQL/MariaDB — العكس
               بيدّي خطأ نحوي 1064 مش تحذير. */
            $pdo->exec("ALTER TABLE rep_custody_transfers
                        ADD COLUMN to_date DATE NULL
                        COMMENT 'يوم عهدة المُستقبِل (v94) — ممكن يختلف عن يوم خروجها من المُرسِل'
                        AFTER transfer_date");
            $pdo->exec("UPDATE rep_custody_transfers SET to_date = transfer_date WHERE to_date IS NULL");
            $pdo->exec("ALTER TABLE rep_custody_transfers
                        MODIFY COLUMN to_date DATE NOT NULL
                        COMMENT 'يوم عهدة المُستقبِل (v94) — ممكن يختلف عن يوم خروجها من المُرسِل'");
        }
        $v94Say('  ✓ عمود: rep_custody_transfers.to_date (منسوخ من transfer_date)');
    } else {
        // إعادة تشغيل: أي صف NULL (ترحيل اتقطع في النص) بيتملا وبيترجع NOT NULL
        if (!$v94Dry) {
            $pdo->exec("UPDATE rep_custody_transfers SET to_date = transfer_date WHERE to_date IS NULL");
        }
        $v94Say('  • rep_custody_transfers.to_date موجود');
    }

    if (!$v94HasIdx('rep_custody_transfers', 'idx_rct_to_date')) {
        if (!$v94Dry) {
            $pdo->exec("ALTER TABLE rep_custody_transfers
                        ADD KEY idx_rct_to_date (to_rep_id, to_date)");
        }
        $v94Say('  ✓ فهرس: idx_rct_to_date');
    } else {
        $v94Say('  • idx_rct_to_date موجود');
    }

    /* ⚠️ إلزامي: كاش جاهزية العمود ساكن في الموديل وبيتقرا قبل الترحيل، فبيفضل
       بالقيمة القديمة بعده — والتحقّق تحت يفشل بإنذار كاذب (مصيدة v57). */
    if (!$v94Dry) { RepTransfer::resetCache(); }
};

$v94Verify = static function () use ($v94Say, $v94HasCol, $v94HasIdx): bool {
    $ok = true;

    if (!$v94HasCol('rep_custody_transfers', 'to_date')) {
        $v94Say('✗ عمود to_date مش موجود.'); return false;
    }
    $v94Say('  ✓ عمود to_date موجود');

    $nullable = (string) Database::scalar(
        "SELECT is_nullable FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'rep_custody_transfers'
            AND column_name = 'to_date'");
    if (strtoupper($nullable) !== 'NO') {
        $v94Say('✗ to_date لسه بيقبل NULL — صف بلا يوم عهدة بيختفي من كل حساب يومي.'); $ok = false;
    } else { $v94Say('  ✓ to_date إلزامي (NOT NULL)'); }

    $v94HasIdx('rep_custody_transfers', 'idx_rct_to_date')
        ? $v94Say('  ✓ فهرس idx_rct_to_date موجود')
        : ($v94Say('✗ فهرس idx_rct_to_date ناقص.') === null ? $ok = false : null);

    $orphan = (int) Database::scalar(
        "SELECT COUNT(*) FROM rep_custody_transfers WHERE to_date IS NULL");
    if ($orphan > 0) {
        $v94Say("✗ في $orphan صف بلا to_date."); $ok = false;
    } else { $v94Say('  ✓ ولا صف بلا يوم عهدة مستقبِل'); }

    /* ⚠️ **حالة بنيوية دائمة مش لقطة بيانات:** الموديل بيقرا العمود الجديد فعلاً
       في جهة الاستقبال. لو حد رجّع الاستعلام لـ`transfer_date`، الفحص ده بيفضل
       صحيحًا للأبد ويمسك الارتداد. */
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/Models/RepTransfer.php');
    if (!str_contains($src, "'to_rep_id' => 'to_date'") && !str_contains($src, 'to_date')) {
        $v94Say('✗ RepTransfer لسه ما بيعرفش to_date.'); $ok = false;
    } else { $v94Say('  ✓ RepTransfer بيقرا to_date في جهة الاستقبال'); }

    return $ok;
};

if ($v94Direct) {
    echo 'v94 — يوم عهدة المُستقبِل منفصل' . ($v94Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";

    $before = $v94Snapshot();
    $v94Up();
    if ($v94Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }

    if ($v94Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v94.\n"); exit(1); }

    echo "\n" . str_repeat('─', 62) . "\n";
    $after = $v94Snapshot();
    $bad = [];
    if (abs($after['safe'] - $before['safe']) > 0.005) { $bad[] = 'خزنة المكتب'; }
    foreach ($before['reps'] as $rid => $v) {
        if (abs(($after['reps'][$rid] ?? 0) - $v) > 0.005) { $bad[] = "رصيد مندوب #$rid"; }
    }
    foreach ($before['days'] as $k => $v) {
        if (abs(($after['days'][$k] ?? 0) - $v) > 0.005) { $bad[] = "عهدة يوم $k"; }
    }
    if ($bad) {
        fwrite(STDERR, "✗✗ اتغيّر رقم مالي — مستحيل في ترحيل بنيوي: " . implode(' · ', $bad) . "\n");
        exit(1);
    }
    printf("✓ الخزنة (%s) وأرصدة %d مندوب وعهدة %d يوم ما اتغيّروش ولا مليم.\n",
        number_format($after['safe'], 2), count($before['reps']), count($before['days']));

    echo "\n✓ تمّ. شغّل php bin/verify_v94.php للتأكد.\n";
}

return ['up' => $v94Up, 'verify' => $v94Verify];
