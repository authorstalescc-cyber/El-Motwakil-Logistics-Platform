<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * تدريب موديل الإيصالات (v101) — بيقرا كل الأذون المقفولة ويبني منها
 * الاحتمالات وأسس الكمية وقواعد الاقتران، وبيطبع تقريرًا مفهومًا.
 *
 * ⚠️ **بيمسح ويبني الإحصاء بس** — ولا بند إيصال ولا مبلغ ولا إذن بيتلمس.
 *    (الجداول الأربعة مشتقّة بالكامل: امسحها وشغّله يرجّعها بالحرف.)
 *
 *   php bin/train_receipts.php              التدريب + التقرير
 *   php bin/train_receipts.php --quiet      من غير تفاصيل (للنبضة)
 *   php bin/train_receipts.php --explain    يزوّد شرح القواعد المتعلَّمة
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\ReceiptKind;
use App\Models\ReceiptModel;

$quiet   = in_array('--quiet', $argv, true);
$explain = in_array('--explain', $argv, true);
$say     = static function (string $m = '') use ($quiet): void { if (!$quiet) { echo $m . "\n"; } };

$say('تدريب موديل الإيصالات');
$say(str_repeat('=', 66));

$r = ReceiptModel::train('manual');

if (empty($r['ok'])) {
    fwrite(STDERR, ($r['reason'] ?? 'فشل التدريب') . "\n");
    exit(1);
}

$pct = static fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 1) . '٪';

$say();
$say("اتدرّب على {$r['permits']} إذن مقفول · {$r['receipts']} بند إيصال · {$r['kinds']} نوع");
$say("اتولّد {$r['cells']} قاعدة سياق و{$r['pairs']} قاعدة اقتران في {$r['secs']} ثانية");
if (!empty($r['discovered'])) { $say("أنواع جديدة اتكشفت من الداتا: {$r['discovered']}"); }

$say();
$say('── الدقة المقيسة على أذون **مستبعَدة من التدريب** (٥ طيّات) ──');
$say('   دقة التوقّع     : ' . $pct($r['precision']) . '   (من كل ١٠ بنود بيقول إنها متوقّعة، كام واحد بيطلع فعلاً موجود)');
$say('   الاسترجاع       : ' . $pct($r['recall'])    . '   (من كل ١٠ بنود موجودة فعلاً، كام واحد النظام توقّعه)');
$say('   F1              : ' . $pct($r['f1']));
$say('   العدد المظبوط   : ' . $pct($r['qty_exact']) . '   (لما يقول «٤ هيئة»، كام مرة بيطلع ٤ بالحرف)');

if ($explain) {
    $say();
    $say('── أقوى قواعد الاقتران المتعلَّمة ──');
    foreach (ReceiptModel::pairs(12) as $p) {
        printf("   لما يبقى فيه «%s» بيبقى فيه «%s» بنسبة %s (رفع %s · %d إذن)\n",
            $p['kind_a'], $p['kind_b'], $pct((float) $p['p_ba']), number_format((float) $p['lift'], 1), (int) $p['n_a']);
    }

    $say();
    $say('── أساس الكمية لكل نوع (على مستوى كل الأذون) ──');
    $basis = ['fixed' => 'عدد ثابت للإذن', 'per_car' => 'لكل سيارة', 'per_container' => 'لكل حاوية'];
    foreach (ReceiptModel::rules('type', 80) as $c) {
        if ((int) $c['n_hits'] < 5) { continue; }
        printf("   %-14s %-6s | احتمال %-6s | %-16s ×%-5s | ضبط %-6s | يوم %s\n",
            $c['kind'], $c['scope_key'], $pct((float) $c['prob']),
            $basis[$c['qty_basis']] ?? $c['qty_basis'], rtrim(rtrim(number_format((float) $c['qty_rate'], 2), '0'), '.'),
            $pct((float) $c['qty_fit']), $c['day_offset'] === null ? '—' : (int) $c['day_offset']);
    }

    $say();
    $say('── قاموس الأنواع ──');
    $n = 0;
    foreach (ReceiptKind::catalog() as $k) {
        if ($k['origin'] === 'learned') { $n++; }
    }
    $say('   بذرة في الكود: ' . count(ReceiptKind::seedNames()) . ' نوع · مكتشَف من الداتا: ' . $n . ' نوع');
}

$say();
$say('✓ تمّ. الشاشة: /receipt-model');
exit(0);
