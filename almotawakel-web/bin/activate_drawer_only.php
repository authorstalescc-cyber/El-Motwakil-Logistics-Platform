<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * تحويل معنى «الخزنة» من **كل كاش الشركة** إلى **كاش الدرج بس** (قرار المالك 2026-07-31).
 *
 * ═══ الفرق ═══
 *   • النموذج الحالي (`rep_bank_topup_from`): الخزنة = الدرج + جيوب المناديب.
 *     تنزيل العهدة من البنك **بيزوّد** الخزنة، وصرف المندوب **بيخصم** منها،
 *     والتنزيل من الدرج **تحويل داخلي بلا أثر**.
 *   • النموذج المطلوب (`rep_safe_rule_from`): الخزنة = **الكاش اللي في الدرج
 *     وتقدر تعدّه بإيدك**. التنزيل من الدرج **بيتخصم**، وصرف المندوب من محفظته
 *     **ما بيمسّش الدرج**، وفلوس البنك اللي راحت للمندوب على طول **عمرها ما دخلت**.
 *
 * ⚠️⚠️ **النموذجان بديلان مش متجمّعان.** لو الاتنين شغّالين مع بعض: فلوس البنك
 *    بتزوّد الخزنة وصرف المندوب ما بيخصمش ⇒ **الفلوس عمرها ما تخرج من الرقم**.
 *    عشان كده السكربت بيقفل الأول وبيفتح التاني في نفس المعاملة.
 *
 * ⚠️⚠️ **ليه محتاج صف تسوية واحد:** قفل `bank_topup` بيشيل **كل** تنزيلات البنك
 *    من الإضافات (مش اللي بعد تاريخ التحويل بس)، بينما صرف المندوب اللي **قبل**
 *    تاريخ التحويل بيفضل مخصومًا. الفترة القديمة بتبقى ناقصة تمويلها، والصف ده
 *    بيمتصّ الفرق **مرة واحدة** فالرقم من تاريخ التحويل ورايح يبقى صح. نفس أسلوب
 *    `activate_safe_rule.php` بالحرف.
 *
 * ⚠️ **الهدف الافتراضي = الدرج اللي النظام نفسه بيقوله** (`Closing::walletSplit()['drawer']`
 *    = كل الكاش − اللي مع المناديب) — يعني **الفلوس محفوظة بالبناء**. و`--drawer=N`
 *    بتحطّ عدّة المالك بإيده مكانه، وأي فرق بينهم = **عجز/زيادة حقيقية** والسكربت
 *    بيقوله صراحةً بدل ما يبلعه.
 *
 * ⚠️ **ولا يوم متقفل بيتلمس** — الصف مؤرَّخ بيوم التحويل (مفتوح)، والأيام
 *    المتقفلة أرقامها مثبَّتة وشرط `safeChargeSql` محدود بتاريخ الصف نفسه.
 *
 * ⚠️ idempotent: بيتعرّف على صفه بملاحظته، فإعادة التشغيل ما بتضيفش نسخة تانية.
 * ⚠️ التراجع: `--off` بيرجّع النموذج القديم وبيمسح صف التسوية.
 *
 * التشغيل:
 *   php bin/activate_drawer_only.php --dry-run
 *   php bin/activate_drawer_only.php --drawer=16850 --dry-run
 *   php bin/activate_drawer_only.php --drawer=16850
 *   php bin/activate_drawer_only.php --off
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

const ADJ_NOTE  = 'تسوية تحويل الخزنة لكاش الدرج بس';
const PREV_KEY  = 'rep_bank_topup_from_prev';

$dry     = in_array('--dry-run', $argv, true);
$off     = in_array('--off', $argv, true);
$from    = work_date();
$drawerT = null;
foreach ($argv as $a) {
    if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $a, $m))      { $from = $m[1]; }
    if (preg_match('/^--drawer=(-?[\d.]+)$/', $a, $m))             { $drawerT = round((float) $m[1], 2); }
}

function money2(float $v): string { return number_format($v, 2); }
function line(string $l, $v): void { printf("  %-38s %14s\n", $l, is_string($v) ? $v : money2((float) $v)); }
function sec2(string $t): void { echo "\n" . $t . "\n" . str_repeat('─', 62) . "\n"; }

/** لقطة كاملة: الخزنة بمعناها الحالي + محافظ المناديب + إجمالي الكاش. */
function snap(): array
{
    $w = Closing::walletSplit();
    return ['safe' => round(Closing::currentBalance(), 2), 'drawer' => round((float) $w['drawer'], 2),
            'reps' => round((float) $w['reps_total'], 2), 'total' => round((float) $w['total'], 2)];
}

echo "\n═══ تحويل «الخزنة» لكاش الدرج بس ═══\n";
if ($dry) { echo "  ⚠️  وضع المعاينة — مفيش أي حاجة هتتحفظ\n"; }

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    // ─────────────────────────────────────────────────────── التراجع
    if ($off) {
        $prev = (string) App::setting(PREV_KEY, '');
        Database::exec('DELETE FROM manual_custody WHERE note = ?', [ADJ_NOTE]);
        App::setSetting('rep_safe_rule_from', '');   RepCustody::refreshSafeRule();
        App::setSetting('rep_bank_topup_from', $prev); RepCustody::refreshBankTopup();
        $s = snap();
        sec2('رجعنا للنموذج القديم');
        line('الخزنة (كل كاش الشركة)', $s['safe']);
        line('منها في الدرج', $s['drawer']);
        line('ومع المناديب', $s['reps']);
        if ($dry) { $pdo->rollBack(); echo "\n  (معاينة — اترجع)\n"; }
        else      { $pdo->commit(); echo "\n  ✅ اترجع للنموذج القديم\n"; }
        exit(0);
    }

    // ─────────────────────────────────────────────────── ① قبل التحويل
    $before = snap();
    sec2('① قبل التحويل — النموذج الحالي (الخزنة = كل كاش الشركة)');
    line('رقم الخزنة المعروض', $before['safe']);
    line('منها في الدرج (مشتقّ)', $before['drawer']);
    line('ومع المناديب', $before['reps']);
    line('إجمالي كاش الشركة', $before['total']);

    /* ⚠️ الهدف الافتراضي = الدرج اللي النظام بيقوله — فالفلوس محفوظة بالبناء.
       `--drawer` بتحطّ عدّة المالك مكانه، والفرق بيتقال صراحةً. */
    $target = $drawerT ?? $before['drawer'];
    $countDiff = round($target - $before['drawer'], 2);

    // ─────────────────────────────────────────────────── ② قلب النموذج
    App::setSetting(PREV_KEY, (string) App::setting('rep_bank_topup_from', ''));
    App::setSetting('rep_bank_topup_from', ''); RepCustody::refreshBankTopup();
    App::setSetting('rep_safe_rule_from', $from); RepCustody::refreshSafeRule();

    $raw = round(Closing::currentBalance(), 2);
    $adj = round($target - $raw, 2);

    sec2('② بعد قلب النموذج وقبل التسوية');
    line('الدرج المحسوب من الصفوف الخام', $raw);
    line('الهدف (' . ($drawerT === null ? 'اللي النظام بيقوله' : 'عدّتك بإيدك') . ')', $target);
    line('⇒ صف التسوية المطلوب', ($adj >= 0 ? '+' : '') . money2($adj));

    // ─────────────────────────────────────────────────── ③ صف التسوية
    Database::exec('DELETE FROM manual_custody WHERE note = ?', [ADJ_NOTE]);
    if (abs($adj) >= 0.005) {
        Database::exec(
            'INSERT INTO manual_custody (cust_date, driver, amount, note) VALUES (?,?,?,?)',
            [$from, '', $adj, ADJ_NOTE]
        );
    }

    // ─────────────────────────────────────────────────── ④ التحقّق
    $after = snap();
    sec2('③ بعد التحويل — النموذج الجديد (الخزنة = كاش الدرج)');
    line('رقم الخزنة المعروض = الدرج', $after['safe']);
    line('ومع المناديب (منفصل)', $after['reps']);
    line('إجمالي كاش الشركة', $after['total']);

    sec2('④ التحقّق');
    $okDrawer = abs($after['safe'] - $target) <= 0.005;
    $okReps   = abs($after['reps'] - $before['reps']) <= 0.005;
    /* ⚠️ **أهم فحص**: إجمالي الكاش (درج + مناديب) لازم يفضل زي ما هو — إلا
       بمقدار فرق العدّة اليدوية لو المالك حطّ رقمه. أي فرق تاني = فلوس بتتبخّر. */
    $okTotal  = abs(($after['total'] - $before['total']) - $countDiff) <= 0.005;
    printf("  %s الدرج بقى = الهدف (%s)\n", $okDrawer ? '✓' : '✗', money2($target));
    printf("  %s محافظ المناديب ما اتغيّرتش (%s)\n", $okReps ? '✓' : '✗', money2($after['reps']));
    printf("  %s الفلوس محفوظة (إجمالي %s ⇒ %s)\n", $okTotal ? '✓' : '✗',
        money2($before['total']), money2($after['total']));
    if (abs($countDiff) >= 0.005) {
        printf("  ⚠️  عدّتك بإيدك تختلف عن حساب النظام بـ%s — %s حقيقي لازم تراجعه\n",
            money2(abs($countDiff)), $countDiff < 0 ? 'عجز' : 'زيادة');
    }
    // ⚠️ ولا يوم متقفل يتغيّر — الفحص على السلسلة كلها مش على آخر يوم بس
    $locked = (string) Database::scalar(
        "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,closing_balance) ORDER BY close_date),'')
         FROM daily_closings");
    Closing::refreshLockedChainFrom((string) Database::scalar('SELECT MIN(close_date) FROM daily_closings'));
    $locked2 = (string) Database::scalar(
        "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,closing_balance) ORDER BY close_date),'')
         FROM daily_closings");
    printf("  %s ولا يوم متقفل اتغيّر رقمه\n", $locked === $locked2 ? '✓' : '✗');

    $allOk = $okDrawer && $okReps && $okTotal && $locked === $locked2;
    if ($dry || !$allOk) {
        $pdo->rollBack();
        echo $dry ? "\n  (معاينة — اترجع، مفيش حاجة اتحفظت)\n"
                  : "\n  ❌ فحص فشل — اترجع كل شيء، مفيش حاجة اتحفظت\n";
        exit($allOk ? 0 : 1);
    }
    $pdo->commit();
    echo "\n  ✅ اتحوّل. رقم الخزنة دلوقتي = كاش الدرج.\n";
    echo "     للتراجع: php bin/activate_drawer_only.php --off\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n  ❌ استثناء: " . $e->getMessage() . "\n";
    exit(1);
}
