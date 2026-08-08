<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * تحويل معنى «رصيد الخزنة» إلى **كل كاش الشركة برّه البنك** (قرار المالك 2026-07-30).
 *
 * ═══ قرار المالك الحرفي ═══
 *   «أي عهدة يتم إضافتها للمندوب كأنها في الدرج» — أكّدها تلات مرات.
 *
 * ═══ النموذجان ═══
 *   • **الخزنة = الدرج بس** (المرحلة ٢، اللي كان شغّال): التنزيل مالوش أثر،
 *     وصرف المندوب مالوش أثر. الرقم = كاش درج المكتب اللي تقدر تعدّه بإيدك.
 *   • **الخزنة = كل كاش الشركة** (ده): تنزيل من البنك **بيزوّد**، وصرف المندوب
 *     **بيخصم**. الرقم = درج + جيوب المناديب، وتفكيكه في قسم «الكاش برّه البنك».
 *
 * ═══ اللي بيتعمل، جوّه معاملة واحدة ═══
 *   ① `rep_bank_topup_from = 2026-07-29`  → تنزيل البنك بيزوّد الخزنة
 *   ② `rep_safe_rule_from = ''`            → صرف المندوب يرجع يخصم من الخزنة
 *   ③ شيل تسوية الـ−10,930 من يوم 28 وإقفاله من جديد → بيرجع **67,710**
 *
 * ⚠️⚠️ **ليه التسوية بتتشال (أهم نقطة):** حطّيناها امبارح عشان نطلّع فلوس
 *    المناديب من رقم الدرج — وده كان مطلوبًا في النموذج القديم. في النموذج ده
 *    فلوس المناديب **جزء من الرقم أصلاً**، فسيبانها معناه خصمها **مرتين**.
 *    والمرحّل بيرجع 67,710 وهو الرقم اللي المالك أكّد تفكيكه بنفسه:
 *    درج 56,780 + عبدالرحمن 2,930 + السيد 8,000.
 *
 * ⚠️ **`carry` و`office` عمرهم ما بيزوّدوا الخزنة** — `office` تحويل داخلي
 *    و`carry` متحسوب خلاص في مرحّل امبارح. `bank` بس هي الفلوس الجديدة.
 *
 * ⚠️ **التراجع:** `--off` بيرجّع النموذج القديم (الخزنة = الدرج) ويرجّع التسوية.
 *
 * التشغيل:  php bin/activate_bank_topup.php --dry-run
 *           php bin/activate_bank_topup.php
 *           php bin/activate_bank_topup.php --off
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

const FROM_DATE = '2026-07-29';
const ADJ_DATE  = '2026-07-28';
const ADJ_NOTE  = 'تسوية بداية النظام الجديد — الكاش اللي كان مع المناديب';

$dry = in_array('--dry-run', $argv, true);
$off = in_array('--off', $argv, true);

$snap = static function (): array {
    $safe = round(Closing::currentBalance(), 2);
    $reps = round(RepCustody::totalOutstanding(), 2);
    return [
        'safe'  => $safe,
        'reps'  => $reps,
        'close' => round((float) Database::scalar(
            'SELECT closing_balance FROM daily_closings WHERE close_date = ?', [ADJ_DATE]), 2),
    ];
};
$show = static function (string $t, array $s): void {
    printf("  %-6s رصيد الخزنة %14s   ·   مع المناديب %14s   ·   مرحّل %s %14s\n",
        $t, number_format($s['safe'], 2), number_format($s['reps'], 2),
        ADJ_DATE, number_format($s['close'], 2));
};

echo "\n═══ «رصيد الخزنة» = كل كاش الشركة برّه البنك ═══"
   . ($dry ? '   (تجربة — كله بيترجع)' : ($off ? '   (تراجع)' : '')) . "\n\n";

if (!RepCustody::sourceReady()) {
    fwrite(STDERR, "✗ عمود `source` مش موجود — شغّل `php bin/migrate_v61.php` الأول.\n");
    exit(1);
}

$before = $snap();
$show('قبل:', $before);

$pdo = Database::conn();
$pdo->beginTransaction();
$fail = null;
try {
    /* ⚠️ فتح اليوم لازم يسبق أي تغيير في الإعدادات — `reopenDay` بيتحقق إنه آخر
       يوم متقفل، والإقفال بعدين بيتحسب بالقاعدة الجديدة. */
    $last = Closing::lastClosedDate();
    if ($last !== ADJ_DATE) {
        throw new RuntimeException("آخر يوم متقفل {$last} مش " . ADJ_DATE . ' — اتوقف.');
    }
    $r = Closing::reopenDay(ADJ_DATE);
    if (empty($r['ok'])) { throw new RuntimeException('فشل فتح ' . ADJ_DATE . ': ' . ($r['msg'] ?? '')); }

    if ($off) {
        App::setSetting('rep_bank_topup_from', '');
        App::setSetting('rep_safe_rule_from', FROM_DATE);
        // نرجّع التسوية (النموذج القديم محتاجها عشان يطلّع فلوس المناديب)
        if (!Database::scalar('SELECT id FROM manual_custody WHERE cust_date = ? AND note = ?',
                              [ADJ_DATE, ADJ_NOTE])) {
            Database::exec('INSERT INTO manual_custody (cust_date, driver, amount, note) VALUES (?,?,?,?)',
                [ADJ_DATE, 'المناديب', -10930.00, ADJ_NOTE]);
        }
        echo "  ↩ رجعنا للنموذج القديم (الخزنة = الدرج بس) والتسوية رجعت\n";
    } else {
        App::setSetting('rep_bank_topup_from', FROM_DATE);
        App::setSetting('rep_safe_rule_from', '');
        echo "  ① تنزيل البنك بقى بيزوّد الخزنة (من " . FROM_DATE . ")\n";
        echo "  ② صرف المندوب رجع يخصم من الخزنة\n";
        /* ⚠️ التسوية بتتشال — سيبانها في النموذج ده = خصم فلوس المناديب مرتين. */
        $n = Database::exec('DELETE FROM manual_custody WHERE cust_date = ? AND note = ?',
                            [ADJ_DATE, ADJ_NOTE]);
        echo '  ③ تسوية الـ−10,930 اتشالت (' . $n . " صف) — مبقاش ليها معنى\n";
    }
    RepCustody::refreshBankTopup();
    RepCustody::refreshSafeRule();

    $c = Closing::closeDay(ADJ_DATE, 'تحويل نموذج الخزنة');
    if (empty($c['ok'])) { throw new RuntimeException('فشل إقفال ' . ADJ_DATE . ': ' . ($c['msg'] ?? '')); }
    echo '  ④ ' . ($c['msg'] ?? '') . "\n\n";

    $after = $snap();
    $show('بعد:', $after);

    /* ⚠️ **حارس الدوام**: إعادة حساب اليوم من الصفوف الخام (بتحصل لوحدها مع
       «الدخول الحر») لازم تطلّع نفس الرقم، وإلا التصحيح بيتبخّر بالسكوت. */
    Closing::refreshLockedChainFrom(ADJ_DATE);
    if (abs($snap()['close'] - $after['close']) > 0.005) {
        throw new RuntimeException('إعادة الحساب غيّرت المرحّل — النتيجة مش دايمة!');
    }
    echo "  ✓ إعادة حساب اليوم بتطلّع نفس الرقم — النتيجة دايمة\n";

    if (abs($after['reps'] - $before['reps']) > 0.005) {
        throw new RuntimeException('أرصدة المناديب اتغيّرت — النموذج ده بيمسّ الخزنة بس.');
    }
    echo "  ✓ أرصدة المناديب ما اتغيّرتش (النموذج بيمسّ رقم الخزنة بس)\n";

    if ($dry) { throw new RuntimeException('__DRY__'); }
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    // ⚠️ `App::setSetting` بتكاش في الذاكرة فما بترجعش مع الـrollback
    RepCustody::refreshBankTopup();
    RepCustody::refreshSafeRule();
    if ($e->getMessage() !== '__DRY__') { $fail = $e; }
}

if ($fail) { fwrite(STDERR, "\n✗ " . $fail->getMessage() . "\n"); exit(1); }
echo $dry
    ? "\n(تجربة) كله اترجع. شغّل من غير --dry-run عشان يتطبّق.\n"
    : "\n✓ اتطبّق. التراجع: php bin/activate_bank_topup.php --off\n";
