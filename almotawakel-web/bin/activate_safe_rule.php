<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * تفعيل **قاعدة الخزنة الجديدة** + تصحيح الأرصدة الافتتاحية (docs/16 — المرحلة ٢ و Q4).
 *
 * ═══ اللي بيتغيّر ═══
 * قبل: صرف المندوب المعتمَد بيتخصم من درج المكتب (قاعدة v42)، والتنزيل مالوش أثر.
 * بعد: **صرف المندوب ما بيمسّش الدرج** (محفظة مستقلة)، و**التنزيل بيتخصم من
 *      الدرج بس لو طلع منه فعلاً** (`source='office'`).
 *
 * السبب: الفلوس بقت تيجي من البنك للمندوب على طول (قرار المالك Q2: كل عهد
 * 2026-07-29 التلاتة من البنك)، فالدرج كان بيتخصم لفلوس **عمرها ما دخلته**.
 *
 * ═══ اللي السكربت بيعمله، جوّه معاملة واحدة ═══
 *   ① بيكتب إعداد `rep_safe_rule_from = 2026-07-29` (مفتاح التفعيل).
 *   ② **تصحيح Q4:** بيسجّل تسوية `manual_custody` بـ−10,930 يوم 2026-07-29
 *      («الكاش اللي كان مع المناديب قبل بداية النظام الجديد»)، فالخزنة تقول
 *      كاش الدرج الحقيقي **56,780** بدل الرقم المجمَّع 67,710.
 *   ③ بيسجّل تنزيلَين بمصدر `carry` يوم 2026-07-29:
 *        عبدالرحمن 2,930  ·  السيد الفنان 8,000
 *      فالكاش اللي كان في جيبهم يبان في محفظتهم من أول يوم في النظام الجديد.
 *
 * ⚠️⚠️ **ليه تسوية يوم ٢٩ وليه مش إعادة تقفيل يوم ٢٨ (قرار المالك الحرفي):**
 *    `Closing::refreshLockedChainFrom()` **بتعيد حساب `closing_balance` من الصفوف
 *    الخام** لأي يوم متقفل، وبتتنفّذ لوحدها مع «الدخول الحر». فرقم متكتوب بالإيد
 *    على صف يوم ٢٨ كان **هيترجع 67,710 بالسكوت** أول مرة يحصل ده — يعني التصحيح
 *    يتبخّر بلا أي خطأ. بالتسوية المؤرَّخة النتيجة **نفسها بالظبط** من أول يوم في
 *    النظام الجديد (وهي الفترة الوحيدة اللي المالك بيشتغل فيها)، و**ولا يوم متقفل
 *    بيتلمس** (قاعدة §5.1/G5). والرقم المخزَّن ليوم ٢٨ بيفضل **صحيحًا تاريخيًّا**
 *    لأنه فعلاً كان الرقم المجمَّع (درج + جيوب) في اليوم ده.
 *
 * ⚠️ **الفلوس محفوظة**: قبل = خزنة 67,710 + مناديب 81,000 = 148,710
 *                        بعد = خزنة 56,780 + مناديب 91,930 = 148,710
 *    السكربت بيتحقق من ده صراحةً وبيرفض يكمّل لو الإجمالي اتغيّر.
 *
 * ⚠️ **idempotent**: بيتعرّف على صفوفه بملاحظاتها، فإعادة التشغيل ما بتضيفش نسخة.
 * ⚠️ **التراجع**: `--off` بيمسح الإعداد فترجع قاعدة v42 فورًا. الصفوف بتفضل
 *    (بيانات صحيحة) بس أثرها على الدرج بيتعطّل مع القاعدة.
 *
 * التشغيل:  php bin/activate_safe_rule.php --dry-run
 *           php bin/activate_safe_rule.php
 *           php bin/activate_safe_rule.php --off
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

const SAFE_FROM   = '2026-07-29';
const ADJ_NOTE    = 'تسوية بداية النظام الجديد — الكاش اللي كان مع المناديب';
const CARRY_NOTE  = 'رصيد مرحَّل من قبل بداية النظام الجديد';
/** الكاش اللي كان في جيب كل مندوب آخر يوم 2026-07-28 (تفكيك المالك — docs/16 §5.2). */
const CARRY = ['عبدالرحمن' => 2930.0, 'السيد' => 8000.0];

$dry = in_array('--dry-run', $argv, true);
$off = in_array('--off', $argv, true);

$snap = static function (): array {
    $safe = round(Closing::currentBalance(), 2);
    $reps = round(RepCustody::totalOutstanding(), 2);
    return ['safe' => $safe, 'reps' => $reps, 'total' => round($safe + $reps, 2)];
};
$show = static function (string $title, array $s): void {
    printf("  %-26s خزنة %12s   مناديب %12s   الإجمالي %12s\n",
        $title, number_format($s['safe'], 2), number_format($s['reps'], 2), number_format($s['total'], 2));
};

echo "\n═══ تفعيل قاعدة الخزنة الجديدة (المرحلة ٢ + تصحيح Q4) ═══"
   . ($dry ? '   (تجربة — كله بيترجع)' : '') . "\n\n";

if ($off) {
    App::setSetting('rep_safe_rule_from', '');
    RepCustody::refreshSafeRule();
    $show('بعد الإطفاء:', $snap());
    echo "\n✓ القاعدة اتطفت — رجعنا لسلوك v42. الصفوف اللي اتسجّلت بتفضل مكانها.\n";
    exit(0);
}

if (!RepCustody::sourceReady()) {
    fwrite(STDERR, "✗ عمود `rep_custody_deposits.source` مش موجود — شغّل `php bin/migrate_v61.php` الأول.\n");
    exit(1);
}

$before = $snap();
$show('قبل:', $before);

$pdo = Database::conn();
$pdo->beginTransaction();
$fail = null;
try {
    // ① مفتاح التفعيل
    App::setSetting('rep_safe_rule_from', SAFE_FROM);
    RepCustody::refreshSafeRule();
    echo "  ① rep_safe_rule_from = " . SAFE_FROM . "\n";

    // ③ الأرصدة المرحَّلة للمناديب (بمصدر `carry` = مالوش أثر على الدرج)
    $carried = 0.0;
    foreach (CARRY as $needle => $amount) {
        $rep = Database::row(
            'SELECT id, name FROM reps WHERE name LIKE ? ORDER BY id LIMIT 1', ['%' . $needle . '%']);
        if (!$rep) { throw new RuntimeException("مش لاقي مندوب اسمه فيه «{$needle}»."); }
        $rid = (int) $rep['id'];
        $have = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits
              WHERE rep_id = ? AND dep_date = ? AND source = 'carry'", [$rid, SAFE_FROM]);
        $need = round($amount - $have, 2);
        if ($need > 0.005) {
            RepCustody::createDeposit($rid, $need, SAFE_FROM, CARRY_NOTE, 'carry');
            printf("  ③ %-18s + %s (مرحَّل)\n", $rep['name'], number_format($need, 2));
        } else {
            printf("  ③ %-18s = %s (متسجّل خلاص)\n", $rep['name'], number_format($amount, 2));
        }
        $carried += $amount;
    }

    // ② تسوية الدرج — بنفس مجموع المرحَّل بالظبط، فالفلوس تفضل محفوظة
    $adj = round(-$carried, 2);
    $exists = (float) Database::scalar(
        'SELECT COALESCE(SUM(amount),0) FROM manual_custody WHERE cust_date = ? AND note = ?',
        [SAFE_FROM, ADJ_NOTE]);
    if (abs($exists - $adj) > 0.005) {
        // ⚠️ مبلغ سالب في `manual_custody` = كاش **خرج** من الدرج. الجدول ده
        //    «حركة كاش الدرج» و`Closing::sumTopups` بتجمعه جمعًا مباشرًا، فالسالب
        //    بيشتغل حسابيًّا بلا أي تعديل — نفس منطق `rep_custody_settlements.returned`
        //    اللي بقى يقبل السالب.
        // ⚠️ الأعمدة الأربعة دي بالظبط هي اللي في الجدول (متأكَّد محليًّا وعلى
        //    الإنتاج) — مفيش `created_by` هنا، بعكس `rep_custody_deposits`.
        Database::exec('DELETE FROM manual_custody WHERE cust_date = ? AND note = ?', [SAFE_FROM, ADJ_NOTE]);
        Database::exec(
            'INSERT INTO manual_custody (cust_date, driver, amount, note) VALUES (?,?,?,?)',
            [SAFE_FROM, 'المناديب', $adj, ADJ_NOTE]);
        printf("  ② تسوية الدرج %s\n", number_format($adj, 2));
    } else {
        printf("  ② تسوية الدرج = %s (متسجّلة خلاص)\n", number_format($adj, 2));
    }

    $after = $snap();
    echo "\n";
    $show('بعد:', $after);

    // ⚠️ الفلوس لازم تفضل محفوظة — أي فرق معناه إن اتجاه من الاتجاهين ناقص
    if (abs($after['total'] - $before['total']) > 0.005) {
        throw new RuntimeException('الإجمالي اتغيّر (' . number_format($before['total'], 2)
            . ' ← ' . number_format($after['total'], 2) . ') — فيه اتجاه ناقص، اتوقف.');
    }
    echo "  ✓ الفلوس محفوظة: الإجمالي قبل = بعد بالمليم\n";

    if ($dry) { throw new RuntimeException('__DRY__'); }
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    // ⚠️ `App::setSetting` بتكاش في الذاكرة فما بترجعش مع الـrollback — لازم
    //    نصفّرها بإيدنا (مصيدة موثَّقة في `activate_custody_epoch`).
    RepCustody::refreshSafeRule();
    if ($e->getMessage() !== '__DRY__') { $fail = $e; }
}

if ($fail) { fwrite(STDERR, "\n✗ " . $fail->getMessage() . "\n"); exit(1); }
echo $dry
    ? "\n(تجربة) كله اترجع. شغّل من غير --dry-run عشان يتطبّق.\n"
    : "\n✓ اتطبّق. التراجع: php bin/activate_safe_rule.php --off\n";
