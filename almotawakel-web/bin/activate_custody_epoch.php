<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * تفعيل خط الفصل لعهدة المناديب (docs/16 — الخطوة 1.8).
 *
 * بيعمل حاجتين بس، جوّه معاملة واحدة:
 *   ① بيكمّل تنزيلَي العهدة اللي المالك نسي يسجّلهم يوم 2026-07-29:
 *        السيد الفنان 21,000  ·  عبدالرحمن 10,000
 *      (**idempotent**: بيكمّل للناقص بس، فإعادة التشغيل ما بتضيفش نسخة تانية)
 *   ② بيكتب إعداد `rep_custody_epoch = 2026-07-29`.
 *
 * ⚠️ **مفيش ولا صف قديم بيتمسح أو يتعدّل** — قرار المالك «البنود ما تتلمسش
 *    إطلاقًا». الفصل بفلتر تاريخ، والتاريخ القديم بيفضل ظاهر في قسم «أرشيف ما
 *    قبل …» في كارت المندوب.
 *
 * ⚠️ **التراجع:** `php bin/activate_custody_epoch.php --off` بيمسح الإعداد
 *    فيرجّع كل رقم زي ما كان فورًا (التنزيلان بيفضلوا — دول بيانات صحيحة).
 *
 * التشغيل:  php bin/activate_custody_epoch.php --dry-run
 *           php bin/activate_custody_epoch.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;
use App\Models\RepPortal;

const EPOCH   = '2026-07-29';
const DEPOSITS = ['السيد' => 21000.0, 'عبدالرحمن' => 10000.0];

$dry = in_array('--dry-run', $argv, true);
$off = in_array('--off', $argv, true);

$reps = Database::all('SELECT id, name FROM reps ORDER BY id');
$table = static function (string $title): void {
    echo "\n$title\n" . str_repeat('─', 78) . "\n";
    printf("  %-20s %12s %12s %12s\n", 'المندوب', 'الرصيد', 'مستني', 'فاضل معاه');
};
$rows = static function () use ($reps): array {
    $o = [];
    foreach ($reps as $r) {
        $id = (int) $r['id'];
        $b  = RepCustody::balance($id);
        $p  = RepPortal::pendingAmount($id);
        if (abs($b) < 0.005 && abs($p) < 0.005) { continue; }
        $o[(string) $r['name']] = ['b' => $b, 'p' => $p, 'k' => round($b - $p, 2)];
    }
    return $o;
};
$show = static function (array $rs): void {
    $tb = $tp = $tk = 0.0;
    foreach ($rs as $n => $v) {
        printf("  %-20s %12s %12s %12s\n", mb_substr($n, 0, 18),
            number_format($v['b'], 2), number_format($v['p'], 2), number_format($v['k'], 2));
        $tb += $v['b']; $tp += $v['p']; $tk += $v['k'];
    }
    echo '  ' . str_repeat('─', 60) . "\n";
    printf("  %-20s %12s %12s %12s\n", 'الإجمالي',
        number_format($tb, 2), number_format($tp, 2), number_format($tk, 2));
};

// ─── قبل
App::setSetting('rep_custody_epoch', $off ? EPOCH : '');
RepCustody::refreshEpoch();
$safeBefore = Closing::currentBalance();
$table($off ? '◀ قبل الإطفاء' : '◀ قبل التفعيل (الوضع الحالي)');
$show($rows());
echo "\n  خزنة المكتب: " . number_format($safeBefore, 2) . "\n";

if ($off) {
    if (!$dry) { App::setSetting('rep_custody_epoch', ''); RepCustody::refreshEpoch(); }
    $table('▶ بعد الإطفاء'); $show($rows());
    echo "\n  خزنة المكتب: " . number_format(Closing::currentBalance(), 2) . "\n";
    echo "\n" . ($dry ? '(تجربة) ' : '') . "✅ الميزة اتطفت — كل رقم رجع زي ما كان.\n";
    exit(0);
}

// ─── ① التنزيلان الناقصان
echo "\n① تنزيلات " . EPOCH . " الناقصة\n" . str_repeat('─', 78) . "\n";
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    foreach (DEPOSITS as $needle => $amt) {
        $hit = false;
        foreach ($reps as $r) {
            if (!str_contains((string) $r['name'], $needle)) { continue; }
            $hit = true;
            $id   = (int) $r['id'];
            $have = round((float) Database::scalar(
                'SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits WHERE rep_id=? AND dep_date=?',
                [$id, EPOCH]), 2);
            $need = round($amt - $have, 2);
            if ($need <= 0.005) {
                printf("  ✓ %-18s موجود بالفعل (%s) — مفيش إضافة\n",
                    mb_substr((string) $r['name'], 0, 16), number_format($have, 2));
                continue;
            }
            // ⚠️ بيتنفّذ **حتى في التجربة**: كله جوّه معاملة بترجع، ومن غير كده
            //    جدول «بعد التفعيل» بيطلع ناقص التنزيلين فيكدب على المالك.
            RepCustody::createDeposit($id, $need, EPOCH, 'عهدة ' . EPOCH);
            printf("  + %-18s اتضاف %s (كان %s ← بقى %s)%s\n",
                mb_substr((string) $r['name'], 0, 16), number_format($need, 2),
                number_format($have, 2), number_format($amt, 2), $dry ? '  [تجربة]' : '');
        }
        if (!$hit) { echo "  ⚠️  «{$needle}» مش موجود في جدول المناديب!\n"; }
    }
    // ⚠️ **الإعداد بيتكتب جوّه نفس المعاملة** عشان المعاينة تبقى حقيقية.
    //    أول نسخة كانت بتتخطّى الكتابة في وضع التجربة، فجدول «بعد التفعيل» كان
    //    بيطلع نسخة من «قبل» — معاينة بلا أي فايدة. دلوقتي بنكتب ونحسب ونرجّع.
    App::setSetting('rep_custody_epoch', EPOCH);
    RepCustody::refreshEpoch();
    echo "\n② خط الفصل: " . RepCustody::epoch() . ($dry ? '  [تجربة]' : '') . "\n";

    $table('▶ بعد التفعيل');
    $after = $rows();
    $show($after);
    $safeAfter = Closing::currentBalance();
    echo "\n  خزنة المكتب: " . number_format($safeAfter, 2)
       . (abs($safeAfter - $safeBefore) <= 0.005 ? '   ✅ ما اتغيّرتش' : '   ❌ اتغيّرت!') . "\n";

    if ($dry) { $pdo->rollBack(); } else { $pdo->commit(); }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n❌ فشل: " . $e->getMessage() . "\n"; exit(1);
}
RepCustody::refreshEpoch();   // بعد الـrollback الكاش لازم يقرا القيمة الحقيقية تاني

if ($dry) {
    echo "\n(تجربة) مفيش حاجة اتحفظت — كل اللي فوق اترجع. شغّل بلا --dry-run للتنفيذ.\n";
    // ⚠️ **من القاعدة مباشرةً مش من `App::setting`** — كاش الإعدادات في الذاكرة
    //    ما بيرجعش مع الـrollback، فكان بيقول «مفعّل» وهو مترجَّع فعلاً.
    $real = (string) (Database::scalar(
        "SELECT setting_value FROM settings WHERE setting_key = 'rep_custody_epoch'") ?: '');
    echo "الإعداد في القاعدة: [" . $real . "] (فاضي = مطفي ✅)\n";
} else {
    echo "\n✅ اتفعّل. للتراجع الفوري: php bin/activate_custody_epoch.php --off\n";
}
