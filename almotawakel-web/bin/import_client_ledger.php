<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * استيراد كشوف حسابات العملاء من ملف الإكسل التاريخي (v92).
 *
 * ═══ اللزمة (قرار المالك 2026-08-12) ═══
 * ٥٧ حساب عميل اتمسكوا في إكسل من ٣ يناير → ١١ أغسطس ٢٠٢٦ (٩٢١ فاتورة +
 * ٣٣٩ تحصيل + ٩ أرصدة مرحّلة من ٢٠٢٥ = **٨,٢٦٤,٠١٩** مطلوبة من العملاء)،
 * والنظام شايل منهم ٩١ مستند بس من ١١ يوليو وطالع. المطلوب: النظام يبقى
 * هو الحقيقة الكاملة.
 *
 * ═══ ⚠️⚠️ أخطر حاجة في السكربت ده: **التحسيب المزدوج** ═══
 * ٤٥ سطر في الإكسل **اتصدروا بالفعل** من النظام في يوليو وأغسطس (نفس رقم
 * الإذن). استيرادهم تاني = ١.٥ مليون جنيه بيتحسبوا مرتين على عملاء حقيقيين،
 * **بلا أي خطأ ظاهر** — الأرقام هتفضل تجمع صح والرصيد بس هو اللي غلط.
 *
 * فالحارس هنا **مش قايمة استثناءات مكتوبة بالإيد** (بتقدم أول ما يتصدر مستند
 * جديد): كل مستند في الملف بيتقارن **بأرقام الأذونات اللي في القاعدة دلوقتي**،
 * والمطابق بيتخطّى ويتطبع باسمه وقيمته. رقم الإذن بيتقارن **بحروفه وأرقامه
 * بس** (`CFA0944879` = `944879` = `cfa 944879`) لأن الشيت والنظام بيكتبوه
 * بصور مختلفة، والمقارنة الحرفية كانت هتفوّت ٣٠ حالة من الـ٤٥.
 *
 * ⚠️ والمقارنة **بالإذن لا بالعميل** عن قصد: تلات فواتير الإكسل حاططها على
 *    عميل والنظام على عميل تاني (إذن 395945 · 391879 · 273950188). هي هي
 *    الفاتورة، فبتتخطّى — وبتتطبع في التقرير تحت «خلاف في العميل» لأن ده
 *    فرق حقيقي في **مين المدين**، ومحتاج قرار مش تخطّي صامت.
 *
 * ═══ الأمان ═══
 *   • **الافتراضي تجربة بلا كتابة.** الكتابة محتاجة `--apply` صريحة.
 *   • كل الشغل في **معاملة واحدة**: يا الـ٥٧ حساب كلهم يا ولا حاجة.
 *   • كل صف بياخد `import_key` = «الشيت#رقم السطر»، والعمود **فريد** في
 *     القاعدة (v92). فتشغيل السكربت مرتين بيرمي خطأ تفرّد وبيرجع كل حاجة —
 *     مستحيل يضاعف الأرقام حتى لو اتنفّذ بالغلط تاني.
 *   • بعد الكتابة وقبل الـcommit بيتعمل **تحقّق لكل عميل**: اللي اتكتب فعلاً
 *     في القاعدة لازم يساوي اللي الشيت بيقوله ناقص المتخطّي. أي عميل بيخالف
 *     بأكتر من جنيه = **رجوع كامل**.
 *   • `origin='legacy'` على كل صف — التراجع كله أمر واحد:
 *       DELETE FROM client_payments WHERE origin='legacy';
 *       DELETE FROM permit_invoices WHERE origin='legacy';
 *
 * ⚠️ **صفر أثر على الخزنة**: التحصيلات بتتسجّل بـ`route='none'` (مفيش عهدة
 *    ولا كارت فيزا ولا خزنة شركة بتتشحن)، و`permit_invoices` مالهاش أي علاقة
 *    بالتقفيل أصلاً. الفلوس دي **اتقبضت في الماضي برّه النظام** — تسجيلها
 *    كدخول نقدي دلوقتي كان هيبوّظ رصيد الدرج بـ٤٠ مليون.
 *
 * التشغيل:
 *   php bin/import_client_ledger.php                            # تجربة + تقرير
 *   php bin/import_client_ledger.php --apply                    # تنفيذ
 *   php bin/import_client_ledger.php --apply --create-missing   # ويعمل العملاء الناقصين
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Audit;          // ⚠️ في Models مش في Core — `App\Core\Audit` بتفشل وقت التشغيل بس
use App\Models\ClientPayment;
use App\Models\PartyMaster;

$opt = static function (string $name, ?string $default = null) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$name}=")) { return substr($a, strlen($name) + 3); }
    }
    return $default;
};
$flag = static fn (string $n): bool => in_array("--{$n}", $argv, true);

$file        = $opt('file', dirname(__DIR__) . '/storage/imports/client_ledger.json');
$apply       = $flag('apply');
$createParty = $flag('create-missing');
$noAllocate  = $flag('no-allocate');
$onlyClient  = $opt('client');

/** فرق مقبول في تقريب القرش. */
const EPS = 1.0;
/** بادئة مستند الاستيراد — متميّزة عن `MT-` فسلسلة النظام الحيّة ما تتلخبطش. */
const DOC_PREFIX = 'MTL-';

$money = static fn ($v): string => number_format((float) $v, 2);

/**
 * ⚠️⚠️ **حارس التحسيب المزدوج — أهم ٣٠ سطر في الملف كله.**
 *
 * نفس الفاتورة بتتكتب في المكانين برقم إذن **مختلف الشكل**: النظام بيكتب رقم
 * الشحنة كامل بالبادئة (`CFA0944158` · `EBKG17564907` · `Eglv598600009088` ·
 * `WHL62255` · `620-GOA-011-A` · `OOLU2335350340`)، والشيت بيكتب الأرقام
 * الأخيرة بس (`944158` · `17564907` · `9088` · `62255` · `620-011`).
 *
 * المقارنة الحرفية كانت بتمسك ٤٤ حالة من ٧٤ — والـ٣٠ الباقيين (**٢.٣ مليون**)
 * كانوا هيتحسبوا مرتين على عملاء حقيقيين بلا أي خطأ ظاهر. فالمطابقة **٣ طبقات**،
 * وكل سطر بيتطبع ومعاه القاعدة اللي مسكته:
 *
 *   ① `alnum`  — حروف وأرقام بلا فواصل (`620-011` = `620011`)
 *   ② `digits` — الأرقام بس بلا أصفار البداية، بشرط ٤ خانات على الأقل
 *                (`Cfa0940630` = `940630`). الحد بيمنع «١٦» من إنه يطابق أي حاجة.
 *   ③ المبلغ + العميل — **للقرش** وفي حدود ١٥ يوم ومبلغ ≥ ١٠٠٠.
 *      دي الطبقة الوحيدة الاستدلالية، وبتمسك الحالات اللي رقم الإذن نفسه
 *      اتكتب مختلف (٢٦٩٩٢٤ × ٢٥٨٥٥). تطابُق ٢٨٨,٨٤٢.١٧ بالقرش على نفس العميل
 *      في نفس الأسبوع مش صدفة — والحدود التلاتة مع بعض بتمنع سطرين صغيرين
 *      متشابهين إنهم يتلغوا غلط.
 *
 * ⚠️ ومستند النظام الواحد **بيمتص سطرًا واحدًا بس** (بيتشال من الخرايط بعد
 *    ما يتطابق) — من غير كده مستند واحد كان بيخفي فاتورتين حقيقيتين.
 */
$alnumKey = static function ($raw): string {
    return mb_strtoupper((string) preg_replace('/[^0-9A-Za-z]/u', '', (string) $raw), 'UTF-8');
};
$digitKey = static function ($raw): string {
    $d = ltrim((string) preg_replace('/\D/', '', (string) $raw), '0');
    return mb_strlen($d) >= 4 ? $d : '';
};

/** أول رقم في «2*40» = عدد الحاويات. «بالتة» وأمثالها = صفر. */
$containerCount = static function ($raw): int {
    return preg_match('/\d+/', (string) $raw, $m) ? min(9999, (int) $m[0]) : 0;
};

// ─────────────────────────────────────────────── ① تحميل الملف والتأكد منه

if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "✗ ملف الاستيراد مش موجود: {$file}\n   شغّل bin/export_client_ledger.py الأول.\n");
    exit(1);
}
$manifest = json_decode((string) file_get_contents($file), true);
if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'almutawakkil.client_ledger/1') {
    fwrite(STDERR, "✗ الملف مش بصيغة كشوف العملاء المتوقَّعة.\n");
    exit(1);
}
$clients = $manifest['clients'] ?? [];
if (!$clients) { fwrite(STDERR, "✗ الملف مفيهوش أي عميل.\n"); exit(1); }

echo 'استيراد كشوف حسابات العملاء' . ($apply ? '' : '  (تجربة — مفيش كتابة)') . "\n";
echo str_repeat('=', 92) . "\n";
printf("المصدر: %s  (بصمة %s…)\n", $manifest['source_file'] ?? '?', substr((string) ($manifest['source_sha256'] ?? ''), 0, 16));
printf("الملف بيقول: %d عميل · %d فاتورة · %d تحصيل\n\n",
    count($clients), (int) ($manifest['documents_count'] ?? 0), (int) ($manifest['payments_count'] ?? 0));

// ─────────────────────────────── ② خريطة الأذونات المصدَّرة فعلاً من النظام

$live = Database::all(
    "SELECT i.id,i.doc_no,i.permit_no,i.client,i.agent_id,i.total,i.doc_kind,i.invoice_date,
            COALESCE(a.name,i.client) AS agent_name
       FROM permit_invoices i
       LEFT JOIN agents a ON a.id = i.agent_id
      WHERE i.status <> 'void' AND COALESCE(i.origin,'system') = 'system'");

$byAlnum = $byDigit = $byAmount = [];
$taken   = [];   // id المستند اللي اتطابق خلاص — الاستهلاك واحد-لواحد
foreach ($live as $row) {
    $id = (int) $row['id'];
    if (($row['permit_no'] ?? '') !== '') {
        if (($k = $alnumKey($row['permit_no'])) !== '') { $byAlnum[$k][] = $id; }
        if (($k = $digitKey($row['permit_no'])) !== '') { $byDigit[$k][] = $id; }
    }
    $byAmount[PartyMaster::normalizeName((string) $row['agent_name']) . '|' . number_format((float) $row['total'], 2, '.', '')][] = $id;
}
$liveById = [];
foreach ($live as $row) { $liveById[(int) $row['id']] = $row; }

/** بيدوّر على مستند نظام بيطابق سطر الإكسل. بيرجع [الصف, القاعدة] أو null. */
$findExisting = static function (array $d, string $clientName)
        use (&$byAlnum, &$byDigit, &$byAmount, &$taken, $liveById, $alnumKey, $digitKey): ?array {
    $pick = static function (array $ids, string $rule) use (&$taken, $liveById): ?array {
        foreach ($ids as $id) {
            if (!isset($taken[$id])) { $taken[$id] = true; return [$liveById[$id], $rule]; }
        }
        return null;
    };
    $permit = (string) ($d['permit_no'] ?? '');
    if ($permit !== '') {
        if (($k = $alnumKey($permit)) !== '' && isset($byAlnum[$k])
            && ($h = $pick($byAlnum[$k], 'رقم الإذن'))) { return $h; }
        if (($k = $digitKey($permit)) !== '' && isset($byDigit[$k])
            && ($h = $pick($byDigit[$k], 'أرقام الإذن'))) { return $h; }
    }
    $amount = round((float) $d['total'], 2);
    if ($amount < 1000) { return null; }
    $k = PartyMaster::normalizeName($clientName) . '|' . number_format($amount, 2, '.', '');
    foreach ($byAmount[$k] ?? [] as $id) {
        if (isset($taken[$id])) { continue; }
        $a = strtotime((string) $d['date']); $b = strtotime((string) $liveById[$id]['invoice_date']);
        if ($a && $b && abs($a - $b) <= 15 * 86400) {
            $taken[$id] = true; return [$liveById[$id], 'المبلغ والعميل'];
        }
    }
    return null;
};

printf("في القاعدة دلوقتي: %d مستند من شغل النظام — هيتقارنوا بكل سطر بـ٣ قواعد.\n\n", count($live));

// ─────────────────────────────────────────────── ③ الخطة قبل أي كتابة

$plan = []; $missing = []; $skipped = []; $clientMismatch = [];
$sumDocs = $sumPays = $sumSkip = $sumOpen = 0.0;

foreach ($clients as $c) {
    $name = (string) $c['client'];
    if ($onlyClient !== null && $name !== $onlyClient) { continue; }

    $agent = PartyMaster::resolve('agent', $name);
    if (!$agent) { $missing[] = $name; }

    $docs = []; $skipHere = 0.0;
    foreach ($c['documents'] as $d) {
        $found = $findExisting($d, $name);
        if ($found !== null) {
            [$hit, $rule] = $found;
            $skipped[] = [$name, $d, $hit, $rule];
            $skipHere += (float) $d['total'];
            /* ⚠️ المقارنة بـ**هوية العميل** (`agent_id`) لا بنص اللقطة: عمود
               `client` لقطة مجمّدة وقت الإصدار، والدمج بينقل `agent_id` وبيسيب
               النص القديم زي ما هو. مقارنة النص كانت بتقول «سلوباك ≠ سيلوباك»
               بعد ما بقوا عميلًا واحدًا — ٤ إنذارات كاذبة تغطّي على ٣ حقيقية. */
            $sameParty = $agent && (int) ($hit['agent_id'] ?? 0) === (int) $agent['id'];
            if (!$sameParty
                && PartyMaster::normalizeName((string) ($hit['agent_name'] ?? '')) !== PartyMaster::normalizeName($name)) {
                $clientMismatch[] = [$name, $d, $hit];
            }
            continue;
        }
        $docs[] = $d;
    }

    $writeDocs = array_sum(array_map(static fn ($d) => (float) $d['total'], $docs));
    $writePays = array_sum(array_map(static fn ($p) => (float) $p['amount'], $c['payments']));
    $plan[] = [
        'client' => $name, 'agent' => $agent, 'row' => $c,
        'docs' => $docs, 'skipped_value' => $skipHere,
        'write_docs' => $writeDocs, 'write_pays' => $writePays,
        'top_ups' => [],   // بتتعبّى تحت بعد ما فروق المبالغ كلها تتحسب
        // الرصيد اللي المفروض يطلع من اللي هنكتبه لوحده
        'expect' => round((float) $c['opening'] + $writeDocs - $writePays, 2),
    ];
    $sumDocs += $writeDocs; $sumPays += $writePays; $sumSkip += $skipHere; $sumOpen += (float) $c['opening'];
}

// ── تقرير المتخطّي
echo "① سطور اتصدرت من النظام قبل كده — **بتتخطّى**\n" . str_repeat('-', 92) . "\n";
if (!$skipped) { echo "  (مفيش)\n"; }
$byRule = [];
foreach ($skipped as [$cl, $d, $hit, $rule]) {
    $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
    printf("  %-16s %s  إذن %-14s %14s   ↔ %s %s   [%s]\n",
        mb_substr($cl, 0, 16), $d['date'], mb_substr((string) $d['permit_no'], 0, 14),
        $money($d['total']), $hit['doc_no'], $money($hit['total']), $rule);
}
printf("  ── %d سطر بقيمة %s", count($skipped), $money($sumSkip));
foreach ($byRule as $r => $n) { printf("  ·  %s: %d", $r, $n); }
echo "\n\n";

/* ═══ ⚠️⚠️ المتخطّي بمبلغ **ناقص** — مصيدة الدين الضايع ═══
 *
 * في ١٠ حالات نفس الإذن والمبلغ مختلف، والسبب مش غلط في الشيت: النظام مصدّر
 * للعميل **«مطالبة بالإيصالات»** — مستند بالإيصالات لوحدها **بلا نولون**
 * بالتعريف. فمستند النظام ٢,٤٩٠ والشيت ٢٠,٦٩٠، والفرق ١٨,٢٠٠ **نولون مستحق
 * فعلاً ولسه ما اتفوترش**.
 *
 * تخطّي السطر بحاله هنا كان بيضيّع **٩٦,٨٧٠ جنيه دين حقيقي** من أرصدة أربع
 * عملاء (يونايتد لوحده ٧٨,١٠٠) — بلا أي رسالة، لأن قاعدة «سيب اللي في النظام»
 * بتفترض إن المستندين نفس الحاجة وهُمّ مش نفس الحاجة.
 *
 * فالتصرّف بيتفرق حسب الاتجاه:
 *   • الشيت **أكبر** → مستند **فرق مكمّل** بالفرق بس. اللي اتصدر للعميل ما
 *     اتكررش، والدين الناقص رجع.
 *   • النظام **أكبر** → بيتساب زي ما هو (قرار المالك: اللي في النظام أدقّ)،
 *     وبيتطبع عشان الفرق يبقى معروفًا لا مفاجأة.
 */
$amountDiff = []; $topUps = [];
foreach ($skipped as [$cl, $d, $hit, $rule]) {
    $delta = round((float) $d['total'] - (float) $hit['total'], 2);
    if (abs($delta) <= EPS) { continue; }
    $amountDiff[] = [$cl, $d, $hit, $delta];
    if ($delta > EPS) { $topUps[$cl][] = [$d, $hit, $delta]; }
}
if ($amountDiff) {
    echo "⚠️ متخطّي بمبلغ مختلف عن مستند النظام\n" . str_repeat('-', 92) . "\n";
    $tUp = $tDown = 0.0;
    foreach ($amountDiff as [$cl, $d, $hit, $delta]) {
        $delta > 0 ? $tUp += $delta : $tDown += $delta;
        printf("  %-16s إذن %-14s الإكسل %13s × النظام %13s (%s %s)  فرق %13s  %s\n",
            mb_substr($cl, 0, 16), mb_substr((string) $d['permit_no'], 0, 14),
            $money($d['total']), $money($hit['total']), $hit['doc_no'], $hit['doc_kind'], $money($delta),
            $delta > 0 ? '← مستند فرق مكمّل' : '← بيتساب (النظام أكبر)');
    }
    printf("  ── %d حالة · فرق مكمّل هيتكتب %s · فرق لصالح النظام بيتساب %s\n\n",
        count($amountDiff), $money($tUp), $money($tDown));
}

// الفروق المكمّلة بتدخل الخطة وبتعدّل الرصيد المتوقَّع — الحارس بيتحقّق منها زيها زي الفواتير.
foreach ($plan as &$__p) {
    $__p['top_ups'] = $topUps[$__p['client']] ?? [];
    $__p['expect']  = round($__p['expect'] + array_sum(array_map(
        static fn ($t) => (float) $t[2], $__p['top_ups'])), 2);
}
unset($__p);

if ($clientMismatch) {
    echo "⚠️ منهم دول: **الفاتورة واحدة والعميل مختلف** — قرار مطلوب منك\n" . str_repeat('-', 92) . "\n";
    foreach ($clientMismatch as [$cl, $d, $hit]) {   // @phpstan-ignore-line
        printf("  إذن %-13s %14s   الإكسل: «%s»   ×   النظام: «%s» (%s)\n",
            mb_substr((string) $d['permit_no'], 0, 13), $money($d['total']), $cl, $hit['agent_name'], $hit['doc_no']);
    }
    echo "  الرصيد هيفضل على عميل النظام. لو الإكسل هو الصح، الفاتورة محتاجة تتنقل يدويًّا.\n\n";
}

// ── تقرير العملاء الناقصين
if ($missing) {
    echo "② عملاء مش موجودين في دليل الأطراف\n" . str_repeat('-', 92) . "\n";
    foreach ($missing as $m) { echo "  • {$m}\n"; }
    echo $createParty
        ? "  ← هيتعملوا (--create-missing متفعّلة)\n\n"
        : "  ✗ شغّل بـ--create-missing عشان يتعملوا، أو وحّدهم الأول بـbin/merge_client_duplicates.php\n\n";
    if (!$createParty) { fwrite(STDERR, "توقّف: عملاء ناقصين.\n"); exit(1); }
}

// ── جدول الخطة
echo "③ الخطة لكل عميل\n" . str_repeat('-', 92) . "\n";
printf("%-20s %7s %7s %14s %14s %14s\n", 'العميل', 'فواتير', 'تحصيل', 'مرحّل', 'صافي هيتكتب', 'رصيد الشيت');
foreach ($plan as $p) {
    printf("%-20s %7d %7d %14s %14s %14s%s\n",
        mb_substr($p['client'], 0, 20), count($p['docs']), count($p['row']['payments']),
        $money($p['row']['opening']), $money($p['expect']), $money($p['row']['sheet_closing']),
        $p['skipped_value'] > 0 ? '  (−' . $money($p['skipped_value']) . ' متخطّي)' : '');
}
echo str_repeat('-', 92) . "\n";
printf("%-20s %7d %7d %14s %14s\n", 'الإجمالي',
    array_sum(array_map(static fn ($p) => count($p['docs']), $plan)),
    array_sum(array_map(static fn ($p) => count($p['row']['payments']), $plan)),
    $money($sumOpen), $money($sumOpen + $sumDocs - $sumPays));

if (!$apply) {
    echo "\n" . str_repeat('=', 92) . "\nتجربة بس. شغّل بـ--apply عشان يتكتب.\n";
    exit(0);
}

// ─────────────────────────────────────────────── ④ الكتابة

echo "\n" . str_repeat('=', 92) . "\nالكتابة…\n";
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $nDocs = $nPays = $nAlloc = $nTop = 0;

    foreach ($plan as $p) {
        $name  = $p['client'];
        $agent = $p['agent'] ?: PartyMaster::find('agent', PartyMaster::create('agent', ['name' => $name]));
        if (!$agent) { throw new RuntimeException("تعذّر تجهيز العميل «{$name}»."); }
        $agentId = (int) $agent['id'];
        $terms   = max(0, (int) ($agent['payment_terms_days'] ?? 30));

        /** كتابة مستند أرشيفي واحد. بيرجع id. */
        $writeDoc = static function (array $d) use ($agentId, $name, $terms, $containerCount): int {
            $total    = round((float) $d['total'], 2);
            $vat      = round((float) ($d['vat_amount'] ?? 0), 2);
            $freight  = round((float) ($d['freight'] ?? 0), 2);
            $subtotal = round($total - $vat, 2);
            // نسبة الضريبة للعرض بس — **المبلغ نفسه هو اللي من الشيت** لا ناتج
            // ضرب، فالتقريب ما يقدرش يزحزح قرشًا عن الأصل.
            $vatRate  = ($vat > 0 && $freight > 0) ? round($vat / $freight, 4) : 0.0;
            $date     = (string) $d['date'];
            $company  = trim((string) ($d['company'] ?? ''));
            $companyId = null;
            if ($company !== '') {
                $co = PartyMaster::ensure('company', $company);
                $companyId = $co ? (int) $co['id'] : null;
                $company   = $co ? (string) $co['name'] : $company;
            }
            $lines = [];
            foreach ($d['lines'] as $l) {
                $lines[] = ['label' => (string) $l['label'], 'qty' => 1,
                            'price' => round((float) $l['amount'], 2),
                            'total' => round((float) $l['amount'], 2),
                            'freight' => (int) ($l['freight'] ?? 0), 'source' => 'excel'];
            }
            $note = trim(((string) ($d['note'] ?? '')) . ' — مستورَد من كشف الإكسل (' . $d['key'] . ')');

            Database::exec(
                "INSERT INTO permit_invoices
                    (permit_id,doc_kind,status,taxable,internal_no,invoice_date,due_date,
                     client,agent_id,company,company_id,destination,permit_no,containers,
                     freight,vat_rate,vat_amount,subtotal,total,paid_amount,balance_due,
                     items_json,note,issued_at,origin,import_key)
                 VALUES (NULL,'invoice','issued',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,'legacy',?)",
                [
                    $vat > 0 ? 1 : 0,
                    trim((string) ($d['invoice_ref'] ?? '')) ?: null,
                    $date,
                    date('Y-m-d', strtotime($date . ' +' . $terms . ' days')),
                    $name, $agentId,
                    $company !== '' ? $company : null, $companyId,
                    trim((string) ($d['destination'] ?? '')) ?: null,
                    trim((string) ($d['permit_no'] ?? '')) ?: null,
                    $containerCount($d['containers'] ?? ''),
                    $freight, $vatRate, $vat, $subtotal, $total, $total,
                    json_encode($lines, JSON_UNESCAPED_UNICODE),
                    mb_substr($note, 0, 500),
                    $date . ' 12:00:00',
                    $d['key'],
                ]
            );
            $id = Database::lastId();
            Database::exec('UPDATE permit_invoices SET doc_no=? WHERE id=?',
                [DOC_PREFIX . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            return $id;
        };

        // ⓐ الرصيد المرحّل من ٢٠٢٥ — مستند بعنوانه الصريح، عشان الكشف يقول
        //    من فين جه الرقم بدل ما يبدأ من نص الحكاية.
        $opening = round((float) $p['row']['opening'], 2);
        if (abs($opening) >= 0.01) {
            $writeDoc([
                'key' => 'opening#' . $name, 'date' => (string) $p['row']['opening_date'],
                'permit_no' => '', 'company' => '', 'destination' => '', 'containers' => '',
                'invoice_ref' => '', 'freight' => 0.0, 'vat_amount' => 0.0,
                'total' => $opening, 'note' => 'رصيد مرحّل من ٢٠٢٥',
                'lines' => [['label' => 'رصيد مرحّل من سنة ٢٠٢٥', 'amount' => $opening, 'freight' => 0]],
            ]);
            $nDocs++;
        }

        // ⓑ الفواتير — بترتيب التاريخ عشان التخصيص بعدين يمشي على الأقدم صح.
        $docs = $p['docs'];
        usort($docs, static fn ($a, $b) => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
        foreach ($docs as $d) { $writeDoc($d); $nDocs++; }

        // ⓑ٢ الفروق المكمّلة — الجزء اللي مستند النظام ما غطّاهوش (النولون
        //     اللي «المطالبة بالإيصالات» ما بتشملهوش بالتعريف).
        foreach ($p['top_ups'] as [$d, $hit, $delta]) {
            $writeDoc([
                'key' => 'topup#' . $d['key'], 'date' => (string) $d['date'],
                'permit_no' => (string) $d['permit_no'], 'company' => (string) ($d['company'] ?? ''),
                'destination' => (string) ($d['destination'] ?? ''), 'containers' => '',
                'invoice_ref' => '', 'freight' => 0.0, 'vat_amount' => 0.0,
                'total' => round((float) $delta, 2),
                'note' => 'فرق مكمّل لمستند ' . $hit['doc_no'] . ' (الشيت ' . $money($d['total'])
                          . ' × المستند ' . $money($hit['total']) . ')',
                'lines' => [['label' => 'فرق مستحق غير مشمول في ' . $hit['doc_no'],
                             'amount' => round((float) $delta, 2), 'freight' => 0]],
            ]);
            $nTop++;
        }

        // ⓒ التحصيلات — `route='none'` = ولا مليم بيلمس خزنة.
        $pays = $p['row']['payments'];
        usort($pays, static fn ($a, $b) => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
        $payIds = [];
        foreach ($pays as $pm) {
            $amt  = round((float) $pm['amount'], 2);
            $note = trim((string) ($pm['note'] ?? ''));
            Database::exec(
                "INSERT INTO client_payments
                    (client_name,agent_id,pay_date,amount,status,unallocated_amount,note,route,origin,import_key)
                 VALUES (?,?,?,?,'posted',?,?,'none','legacy',?)",
                [$name, $agentId, (string) $pm['date'], $amt, $amt,
                 mb_substr(trim($note . ' — مستورَد من كشف الإكسل (' . $pm['key'] . ')'), 0, 255),
                 $pm['key']]
            );
            $payIds[] = Database::lastId();
            $nPays++;
        }

        // ⓓ توزيع التحصيل على الأقدم — من غيره كل الفواتير بتفضل «مفتوحة»
        //    وشاشة أعمار الديون بتقول ٤٧ مليون متأخرة وهي مدفوعة أصلاً.
        if (!$noAllocate) {
            foreach ($payIds as $pid) {
                $left = (float) Database::scalar('SELECT unallocated_amount FROM client_payments WHERE id=?', [$pid]);
                if ($left <= 0.005) { continue; }
                foreach (Database::all(
                    "SELECT id,balance_due FROM permit_invoices
                      WHERE agent_id=? AND status IN ('issued','partially_paid') AND balance_due>0
                      ORDER BY (due_date IS NULL),due_date,id", [$agentId]) as $iv) {
                    if ($left <= 0.005) { break; }
                    $take = min($left, (float) $iv['balance_due']);
                    if ($take <= 0.005) { continue; }
                    ClientPayment::allocate($pid, (int) $iv['id'], $take);
                    $left = (float) Database::scalar('SELECT unallocated_amount FROM client_payments WHERE id=?', [$pid]);
                    $nAlloc++;
                }
            }
        }

        Audit::log('import', 'permit_invoices', $agentId, null,
            ['docs' => count($docs), 'payments' => count($pays), 'opening' => $opening],
            'استيراد كشف حساب العميل «' . $name . '» من الإكسل', ['agent_id' => $agentId]);
    }

    // ─────────────────────────── ⑤ التحقّق قبل الـcommit — الحارس الأخير
    echo "\nالتحقّق…\n";
    $bad = [];
    foreach ($plan as $p) {
        $agent = PartyMaster::resolve('agent', $p['client']);
        if (!$agent) { $bad[] = [$p['client'], 'العميل اختفى بعد الكتابة', 0.0]; continue; }
        $id = (int) $agent['id'];
        $charges = (float) Database::scalar(
            "SELECT COALESCE(SUM(total),0) FROM permit_invoices
              WHERE agent_id=? AND status<>'void' AND origin='legacy'", [$id]);
        $paid = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM client_payments
              WHERE agent_id=? AND status='posted' AND origin='legacy'", [$id]);
        $got  = round($charges - $paid, 2);
        $diff = round($got - $p['expect'], 2);
        if (abs($diff) > EPS) { $bad[] = [$p['client'], "المكتوب {$got} × المتوقَّع {$p['expect']}", $diff]; }
    }
    if ($bad) {
        echo "\n✗ التحقّق فشل — بيترجع كله:\n";
        foreach ($bad as [$cl, $why, $d]) { printf("   • %-20s %s (فرق %s)\n", $cl, $why, $money($d)); }
        throw new RuntimeException('فشل تحقّق ما بعد الكتابة.');
    }
    printf("  ✓ الـ%d حساب كلهم مطابقين لما الشيت بيقوله.\n", count($plan));

    /* ⑥ الرصيد النهائي زي ما النظام هيعرضه (المستورَد + اللي كان فيه أصلاً)
          مقابل رصيد الشيت. الفرق **مش خطأ بالضرورة**: النظام فيه ٣٢ مستند
          مالهمش أثر في الإكسل خالص، وفيه ٨ حالات النظام قسّم فيها الفاتورة
          الواحدة لكشف + مطالبة بمبلغ مختلف. الجدول ده هو اللي بيخلّي الفرق
          **مرئيًّا ومفسَّرًا** بدل ما يتاكل في رقم مجمّع. */
    echo "\n④ الرصيد النهائي × رصيد الشيت\n" . str_repeat('-', 92) . "\n";
    printf("%-20s %16s %16s %14s\n", 'العميل', 'النظام دلوقتي', 'الشيت', 'الفرق');
    $tSys = $tSheet = 0.0; $offCount = 0;
    foreach ($plan as $p) {
        $agent = PartyMaster::resolve('agent', $p['client']);
        if (!$agent) { continue; }
        $id = (int) $agent['id'];
        $live = round(
            (float) Database::scalar("SELECT COALESCE(SUM(total),0) FROM permit_invoices
                                       WHERE agent_id=? AND status<>'void'", [$id])
            - (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM client_payments
                                         WHERE agent_id=? AND status='posted'", [$id]), 2);
        $sheet = round((float) $p['row']['sheet_closing'], 2);
        $delta = round($live - $sheet, 2);
        $tSys += $live; $tSheet += $sheet;
        if (abs($delta) > EPS) { $offCount++; }
        printf("%-20s %16s %16s %14s%s\n", mb_substr($p['client'], 0, 20),
            $money($live), $money($sheet), $money($delta), abs($delta) > EPS ? '  ←' : '');
    }
    echo str_repeat('-', 92) . "\n";
    printf("%-20s %16s %16s %14s\n", 'الإجمالي', $money($tSys), $money($tSheet), $money($tSys - $tSheet));
    printf("\n%d عميل رصيده مطابق للشيت · %d عنده فرق (سببه مستندات في النظام مش في الإكسل).\n",
        count($plan) - $offCount, $offCount);

    Audit::log('import', 'permit_invoices', 0, null,
        ['clients' => count($plan), 'documents' => $nDocs, 'payments' => $nPays],
        'استيراد كشوف حسابات العملاء من الإكسل (v92)');

    $pdo->commit();

    printf("\n✓ تمّ: %d عميل · %d مستند (منها %d فرق مكمّل) · %d تحصيل · %d تخصيص.\n",
        count($plan), $nDocs + $nTop, $nTop, $nPays, $nAlloc);
    echo "   التراجع لو احتجت:\n";
    echo "     DELETE FROM invoice_payment_allocations WHERE payment_id IN\n";
    echo "       (SELECT id FROM client_payments WHERE origin='legacy');\n";
    echo "     DELETE FROM client_payments WHERE origin='legacy';\n";
    echo "     DELETE FROM permit_invoices  WHERE origin='legacy';\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, "\n✗ فشل — ولا صف اتحفظ: " . $e->getMessage() . "\n");
    exit(1);
}
