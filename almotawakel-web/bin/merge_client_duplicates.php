<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * توحيد العملاء المكرَّرين قبل استيراد كشوف الإكسل (v92).
 *
 * ═══ اللزمة ═══
 * تحذير ③ في CLAUDE.md: **الربط بأسماء نصية بدون FK — تهجئة مختلفة = كيان
 * منفصل.** ولمّا اتقارن الإكسل (٥٧ حساب) بدليل الأطراف (٧٥ وكيل) طلع **نفس
 * العميل مسجَّل مرتين** بأربع صور مختلفة، وكل نسخة شايلة جزءًا من فلوسه:
 *
 *   سيلوباك #7 · سلوباك (٦,٦٧٦ — إذن 355141 نفسه اللي في شيت سيلوباك)
 *   يونايتد #9 · يونايتد كارجو (١٠٨,٩٣٩ — أذونات 620-13x نفسها اللي في شيت يونايتد)
 *   العالمية #52 · العالمية تكستايل (٢٢,٠٠٨ — إذن 942654 نفسه اللي في شيت العالمية)
 *
 * ومعاهم صورتان الاسم فيهما مختلف عن الإكسل بلا تكرار (فالحل تسمية لا دمج):
 *
 *   «Nas shipping» = «ناس شيبنج» (إذن 944879 بـ٤٦,٦٨٧ في الشيتين)
 *   «استاذ تامر»   = «تامر الجوخ» (فاتورتاه ٤٦,٢٩٥ = إجمالي شيت تامر الجوخ بالحرف)
 *
 * لو الاستيراد اتنفّذ قبل التوحيد، حساب العميل بيتقسم على كيانين: الكارت
 * بيقول رقم والحقيقة رقم تاني، **بلا أي خطأ ظاهر**.
 *
 * ═══ ⚠️ اللي **مش** بيتعمل هنا ═══
 * «نافك» و«نافي بلو» شبه «نافيكس»، و«سلمى» و«ابراهيم حسن» و«علاء عبدالعزيز»
 * مالهمش شيت في الإكسل أصلاً. **ما اتلمسوش**: التشابه اللفظي مش دليل، والدمج
 * الغلط بيخلط فلوس عميلين وبيرجّعه شغل يدوي طويل. بيتطبعوا في التقرير بس.
 *
 * ═══ الأمان ═══
 *   • الدمج بيمرّ على `PartyMaster::merge()` — **نفس الدالة** اللي الشاشة
 *     بتستخدمها: بتنقل كل المراجع (أذونات · فواتير · تحصيلات · شركات)،
 *     وبتسيب الاسم القديم كـalias فالبحث القديم يفضل شغّال، وبتسجّل في التدقيق.
 *   • الحلّ بالاسم لا بالـid — **المحلي والإنتاج أرقامهم مختلفة** (٦٠ × ٧٥ وكيل).
 *   • كل الشغل في معاملة واحدة: يا كله يا ولا حاجة.
 *   • `--dry-run` بيطبع الخطة بلا كتابة، وهو **الافتراضي** لو مفيش `--apply`.
 *
 * التشغيل:
 *   php bin/merge_client_duplicates.php            # تقرير بلا كتابة
 *   php bin/merge_client_duplicates.php --apply    # تنفيذ
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\PartyMaster;

$apply = in_array('--apply', $argv, true);

/**
 * دمج: الاسم القديم يتلمّ داخل الاسم القانوني.
 * @var array<int,array{0:string,1:string,2:string}> [المصدر, الهدف, الدليل]
 */
const MERGES = [
    ['سلوباك',           'سيلوباك',  'إذن 355141 بـ٦,٦٧٦ موجود في شيت سيلوباك'],
    ['يونايتد كارجو',    'يونايتد',  'أذونات 620-132/133/134 و58374 و62255 في شيت يونايتد'],
    ['العالمية تكستايل', 'العالمية', 'إذن CFA0942654 بـ٢٢,٠٠٨ موجود في شيت العالمية'],
];

/**
 * تسمية: نفس الكيان، الاسم بس مختلف عن الإكسل — والاسم القديم بيفضل alias.
 * @var array<int,array{0:string,1:string,2:string}> [الاسم الحالي, الاسم الجديد, الدليل]
 */
const RENAMES = [
    ['Nas shipping', 'ناس شيبنج',  'إذن CFA0944879 بـ٤٦,٦٨٧ في شيت ناس شيبنج'],
    ['استاذ تامر',   'تامر الجوخ', 'فاتورتاه ٤٦,٢٩٥ = إجمالي شيت تامر الجوخ بالحرف'],
];

/** أسماء متشابهة **بلا دليل** — تقرير فقط، ولا بتتلمس. */
const REVIEW_ONLY = [
    'نافك'      => 'شبه «نافيكس» — بس بلا أي فاتورة تربطهم. سيبناه.',
    'نافي بلو'  => 'شبه «نافيكس» — بلا دليل. سيبناه.',
    'سلمى'      => 'مالوش شيت في الإكسل — وفاتورته (إذن 273950188) الإكسل حاطّها على «ناتورالي».',
    'ابراهيم حسن'    => 'مالوش شيت في الإكسل. اتساب.',
    'علاء عبدالعزيز' => 'مالوش شيت في الإكسل. اتساب.',
    'المصرية للتريكو'=> 'فاتورته (إذن 395945) الإكسل حاطّها على «ايمن رضوان».',
];

/** بيانات الوكيل + حجم شغله — عشان التقرير يقول إيه اللي بيتحرك بالظبط. */
$load = static function (string $name): ?array {
    $a = PartyMaster::resolve('agent', $name);
    if (!$a) { return null; }
    $id = (int) $a['id'];
    return $a + [
        'inv'  => (int)   Database::scalar('SELECT COUNT(*) FROM permit_invoices WHERE agent_id=?', [$id]),
        'val'  => (float) Database::scalar("SELECT COALESCE(SUM(total),0) FROM permit_invoices
                                             WHERE agent_id=? AND status<>'void'", [$id]),
        'prm'  => (int)   Database::scalar('SELECT COUNT(*) FROM permits WHERE agent_id=?', [$id]),
        'pay'  => (int)   Database::scalar('SELECT COUNT(*) FROM client_payments WHERE agent_id=?', [$id]),
    ];
};

$money = static fn (float $v): string => number_format($v, 2);

echo 'توحيد العملاء المكرَّرين' . ($apply ? '' : '  (تقرير — مفيش كتابة)') . "\n";
echo str_repeat('=', 76) . "\n\n";

$plan = ['merge' => [], 'rename' => [], 'skip' => []];

echo "① دمج المكرَّر\n" . str_repeat('-', 76) . "\n";
foreach (MERGES as [$from, $to, $why]) {
    $src = $load($from); $dst = $load($to);
    if (!$src)                       { $plan['skip'][] = "«{$from}» مش موجود — يمكن اتدمج قبل كده."; echo "  ⊘ {$from} — مش موجود\n"; continue; }
    if (!$dst)                       { $plan['skip'][] = "«{$to}» (الهدف) مش موجود."; echo "  ✗ {$to} — الهدف مش موجود\n"; continue; }
    if ((int)$src['id'] === (int)$dst['id']) { echo "  ✓ {$from} → {$to} — متوحّدين خلاص\n"; continue; }
    $plan['merge'][] = [$src, $dst, $why];
    printf("  • «%s» (#%d: %d فاتورة · %s · %d إذن · %d تحصيل)\n", $from, $src['id'], $src['inv'], $money($src['val']), $src['prm'], $src['pay']);
    printf("      ↳ يتلمّ داخل «%s» (#%d: %d فاتورة · %s)\n", $to, $dst['id'], $dst['inv'], $money($dst['val']));
    printf("      الدليل: %s\n", $why);
}
if (!$plan['merge']) { echo "  (مفيش دمج مطلوب)\n"; }

echo "\n② تسمية بالاسم اللي في الإكسل\n" . str_repeat('-', 76) . "\n";
foreach (RENAMES as [$from, $to, $why]) {
    $src = $load($from);
    if (!$src) { echo "  ⊘ {$from} — مش موجود\n"; continue; }
    if (PartyMaster::normalizeName((string)$src['name']) === PartyMaster::normalizeName($to)) {
        echo "  ✓ {$to} — الاسم متظبّط خلاص\n"; continue;
    }
    $clash = PartyMaster::resolve('agent', $to);
    if ($clash && (int)$clash['id'] !== (int)$src['id']) {
        echo "  ✗ «{$to}» موجود بالفعل (#{$clash['id']}) — ده دمج مش تسمية. اتساب.\n";
        $plan['skip'][] = "«{$from}» → «{$to}» محتاج دمج مش تسمية.";
        continue;
    }
    $plan['rename'][] = [$src, $to, $why];
    printf("  • «%s» (#%d: %d فاتورة · %s) ← يبقى اسمه «%s»\n", $src['name'], $src['id'], $src['inv'], $money($src['val']), $to);
    printf("      الاسم القديم بيفضل alias فالبحث القديم شغّال · الدليل: %s\n", $why);
}
if (!$plan['rename']) { echo "  (مفيش تسمية مطلوبة)\n"; }

echo "\n③ أسماء متشابهة **ما اتلمستش** (محتاجة عينك)\n" . str_repeat('-', 76) . "\n";
foreach (REVIEW_ONLY as $name => $why) {
    $a = $load($name);
    printf("  • %-18s %s%s\n", $name, $why, $a ? sprintf('  [%d فاتورة · %s]', $a['inv'], $money($a['val'])) : '  [مش موجود]');
}

foreach ($plan['skip'] as $s) { echo "\n  ⚠️ {$s}"; }
if ($plan['skip']) { echo "\n"; }

if (!$apply) {
    echo "\n" . str_repeat('=', 76) . "\n";
    printf("الخطة: %d دمج · %d تسمية. شغّل بـ--apply عشان تتنفّذ.\n", count($plan['merge']), count($plan['rename']));
    exit(0);
}

if (!$plan['merge'] && !$plan['rename']) { echo "\nمفيش حاجة تتعمل.\n"; exit(0); }

echo "\n" . str_repeat('=', 76) . "\nالتنفيذ…\n";
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    foreach ($plan['merge'] as [$src, $dst, $_]) {
        PartyMaster::merge('agent', (int)$src['id'], (int)$dst['id'], (int)$src['lock_version']);
        echo "  ✓ «{$src['name']}» اتلمّ داخل «{$dst['name']}»\n";
    }
    foreach ($plan['rename'] as [$src, $to, $_]) {
        // ⚠️ `update()` بتدهس كل الحقول القابلة للتعديل بالافتراضي لو ما اتبعتتش —
        //    فبنرجّع القيم القايمة معاها، وإلا الرينيم بيمسح التليفون ومدة السداد.
        PartyMaster::update('agent', (int)$src['id'], [
            'name'    => $to,
            'note'    => $src['note'],    'phone'   => $src['phone'],
            'email'   => $src['email'],   'tax_id'  => $src['tax_id'],
            'address' => $src['address'],
            'payment_terms_days' => (int)($src['payment_terms_days'] ?? 30),
        ], (int)$src['lock_version']);
        PartyMaster::addAlias('agent', (int)$src['id'], (string)$src['name']);
        echo "  ✓ «{$src['name']}» بقى «{$to}» (والقديم alias)\n";
    }
    $pdo->commit();
    echo "\n✓ تمّ.\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, "\n✗ فشل — ولا تغيير اتحفظ: " . $e->getMessage() . "\n");
    exit(1);
}
