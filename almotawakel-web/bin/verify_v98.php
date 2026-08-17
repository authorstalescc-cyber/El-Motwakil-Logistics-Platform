<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v98 — سعر السيارة المعتاد: أكبر مكسب في خطة الاقتراح.
 *
 * أهم اللي بيتأكد منه:
 *   ① **بنتعلّم من الرقم المكتوب لا من قسمة.** الفاتورة بتخزّن بند النولون
 *      «كمية × سعر»، والمالك بيكتب الكمية = سيارات والسعر = سعر السيارة.
 *      الـfixture بيزرع فاتورة **كميتها ≠ عدد حاوياتها** عن قصد: لو المحرّك
 *      قسّم (نولون ÷ حاويات) هيطلّع رقمًا تانيًا خالص ويسقط الفحص.
 *      (اتصاد في البيانات الحقيقية: إذن فيرتكس فيه **٨ بوالص** والمالك فوتره
 *      **٧ وحدات** — القسمة على البوالص كانت هتدّي ١٣٬٣٨٧ بدل ١٥٬٣٠٠.)
 *   ② **الملغاة مستبعدة** — سعر اتلغى مش سابقة.
 *   ③ **الإذن نفسه مستبعد** — وإلا الاقتراح بيتعلّم من نفسه ويثبّت أول رقم للأبد.
 *   ④ **حد الدليل الأدنى والنافذة الزمنية** — الصمت أنضف من التخمين.
 *   ⑤ **⚠️ الحارس الأهم: الخادم عمره ما بيكتب مبلغ.** فورم الفوترة بيترندر
 *      **بلا أي جافاسكريبت** وخانة سعر النولون بترجع **فاضية** رغم إن العميل
 *      عنده تاريخ أسعار قوي. الفلوس اقتراح يتداس لا تعبئة.
 *   ⑥ **صفر تصميم جديد** + **مصدر واحد لفكّ فواصل الآلاف** (`window.Money.raw`).
 *
 * الـfixtures جوّه transaction + rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v98.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Habit;

$pass = 0; $fail = 0;
function ok98(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad98(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chk98(bool $c, string $m, string $why = ''): void {
    $c ? ok98($m) : bad98($m . ($why !== '' ? " — {$why}" : ''));
}
function sec98(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع نفسه. */
function code98(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $src = (string) file_get_contents($p);
    if (str_ends_with($file, '.js')) {
        $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
        return preg_replace('~^\s*//[^\n]*$~m', '', $src) ?? $src;
    }
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص v98 — سعر السيارة المعتاد\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);
$pdo  = Database::conn();
$pdo->beginTransaction();

try {
    $stamp  = 'ف٩٨-' . substr((string) getmypid(), -4);
    $client = "عميل تسعير {$stamp}";

    /** إذن وهمي بسيط. */
    $mkPermit = static function (string $client, string $dest, string $ctype): int {
        Database::exec(
            'INSERT INTO permits (permit_type, permit_no, permit_date, client, company, destination, container_type, containers)
             VALUES (?,?,?,?,?,?,?,?)',
            ['sader', 'V98-' . substr(md5(uniqid('', true)), 0, 8), date('Y-m-d'),
             $client, "شركة {$client}", $dest, $ctype, 8]
        );
        return Database::lastId();
    };

    /**
     * فاتورة وهمية ببند نولون مسجَّل «كمية × سعر».
     * ⚠️ `$containers` بيتحطّ **مخالفًا** لـ`$qty` عن قصد — ده جوهر الفحص ①.
     */
    $mkInv = static function (int $permitId, string $client, float $unit, int $qty,
                              int $containers, string $date, string $status = 'issued',
                              string $dest = '', string $doc = 'MT-TEST') {
        $items = [[
            'label' => 'النولون', 'qty' => $qty, 'price' => $unit,
            'total' => $unit * $qty, 'freight' => 1, 'source' => 'freight',
        ]];
        Database::exec(
            'INSERT INTO permit_invoices (permit_id, doc_no, doc_kind, invoice_date, client, destination,
                                          containers, freight, subtotal, total, items_json, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$permitId, $doc . '-' . substr(md5(uniqid('', true)), 0, 6), 'invoice', $date,
             $client, $dest, $containers, $unit * $qty, $unit * $qty, $unit * $qty,
             json_encode($items, JSON_UNESCAPED_UNICODE), $status]
        );
    };

    $today = date('Y-m-d');
    $old   = date('Y-m-d', strtotime('-800 days'));

    // ═══════════════════════════════════════ ①
    sec98('① بيتعلّم السعر المكتوب — مش نولون ÷ حاويات');

    /* ٣ فواتير: ٤ سيارات × ٢٧٬٥٠٠ لكن **٨ حاويات** مسجَّلة.
       القسمة (٢٢٠٬٠٠٠ ÷ ٨) كانت هتدّي ١٣٬٧٥٠ — رقم مالوش وجود في أي فاتورة. */
    $pA = $mkPermit($client, 'بني سويف', '20 قدم');
    $pB = $mkPermit($client, 'بني سويف', '20 قدم');
    $pC = $mkPermit($client, 'بني سويف', '20 قدم');
    $mkInv($pA, $client, 27500, 4, 8, $today, 'issued', 'بني سويف');
    $mkInv($pB, $client, 27500, 4, 8, $today, 'issued', 'بني سويف');

    $target = $mkPermit($client, 'بني سويف', '20 قدم');
    $hit = Habit::carPrice([
        'client' => $client, 'destination' => 'بني سويف',
        'container_type' => '20 قدم', 'exclude_permit' => $target,
    ]);
    chk98($hit !== null && abs($hit['value'] - 27500) < 0.005,
        'السعر المقترح = سعر السيارة المكتوب (٢٧٬٥٠٠)',
        json_encode($hit, JSON_UNESCAPED_UNICODE));
    chk98($hit !== null && abs($hit['value'] - 13750) > 0.005,
        'ومش ناتج قسمة النولون على الحاويات (١٣٬٧٥٠) — الرقمان متعمَّد اختلافهما');
    chk98($hit !== null && $hit['hits'] === 2 && $hit['total'] === 2,
        'والعدّاد بيقول ٢ من ٢ — أرقام حقيقية تتفتح وتتعدّ',
        json_encode($hit, JSON_UNESCAPED_UNICODE));

    // ═══════════════════════════════════════ ②
    sec98('② الفاتورة الملغاة مش سابقة');

    $mkInv($pC, $client, 99999, 4, 8, $today, 'void', 'بني سويف');
    $afterVoid = Habit::carPrice([
        'client' => $client, 'destination' => 'بني سويف',
        'container_type' => '20 قدم', 'exclude_permit' => $target,
    ]);
    chk98($afterVoid !== null && abs($afterVoid['value'] - 27500) < 0.005
          && $afterVoid['total'] === 2,
        'سعر ملغي ما دخلش العدّ ولا حرّك الاقتراح',
        json_encode($afterVoid, JSON_UNESCAPED_UNICODE));

    // ═══════════════════════════════════════ ③
    sec98('③ الإذن نفسه مستبعد — الاقتراح ما بيتعلّمش من نفسه');

    $mkInv($target, $client, 50000, 4, 8, $today, 'issued', 'بني سويف');
    $selfIn = Habit::carPrice([
        'client' => $client, 'destination' => 'بني سويف',
        'container_type' => '20 قدم', 'exclude_permit' => $target,
    ]);
    chk98($selfIn !== null && abs($selfIn['value'] - 27500) < 0.005,
        'فاتورة الإذن الحالي (٥٠٬٠٠٠) ما أثّرتش على اقتراحه',
        json_encode($selfIn, JSON_UNESCAPED_UNICODE));

    $selfOut = Habit::carPrice([
        'client' => $client, 'destination' => 'بني سويف', 'container_type' => '20 قدم',
    ]);
    chk98($selfOut !== null && $selfOut['total'] === 3,
        'ومن غير الاستبعاد بتدخل العدّ فعلاً (فالاستبعاد شغّال مش صدفة)',
        json_encode($selfOut, JSON_UNESCAPED_UNICODE));

    // ═══════════════════════════════════════ ④
    sec98('④ حد الدليل والنافذة الزمنية');

    $lonely = "عميل فاتورة واحدة {$stamp}";
    $pL = $mkPermit($lonely, 'قنا', '40 قدم');
    $mkInv($pL, $lonely, 12000, 1, 1, $today, 'issued', 'قنا');
    chk98(Habit::carPrice(['client' => $lonely]) === null,
        'فاتورة واحدة = مفيش اقتراح سعر (الفلوس محتاجة دليل)');

    $aged = "عميل قديم {$stamp}";
    $pO1 = $mkPermit($aged, 'أسوان', '40 قدم');
    $pO2 = $mkPermit($aged, 'أسوان', '40 قدم');
    $mkInv($pO1, $aged, 9000, 1, 1, $old, 'issued', 'أسوان');
    $mkInv($pO2, $aged, 9000, 1, 1, $old, 'issued', 'أسوان');
    chk98(Habit::carPrice(['client' => $aged]) === null,
        'وفاتورتان أقدم من سنة = سعر ميت ما بيشدّش الاقتراح');

    chk98(Habit::carPrice(['client' => '']) === null,
        'وبلا عميل مفيش استعلام أصلاً');

    // ═══════════════════════════════════════ ⑤ الحارس الأهم
    sec98('⑤ ⚠️ الخادم عمره ما بيكتب مبلغ');

    /* بنرندر فورم الفوترة **بلا أي جافاسكريبت** لإذن عميله عنده تاريخ أسعار
       قوي، ونتأكد إن خانة سعر النولون رجعت **فاضية**. */
    $ctrl = new class extends App\Core\Controller {
        public function form(array $data): string
        {
            ob_start();
            $this->render('permits/invoice_form', $data, false);
            return (string) ob_get_clean();
        }
    };
    $p = \App\Models\Permit::find($target);
    $html = $ctrl->form([
        'p' => $p,
        'movements'     => \App\Models\Permit::movements($target),
        'totals'        => \App\Models\Permit::totals($target),
        'bills'         => \App\Models\Permit::bills($target),
        'expenseRows'   => \App\Models\Permit::expenseRows($target),
        'ctBreakdown'   => [],
        'candidates'    => \App\Models\PermitInvoice::candidateItems($target),
        'actualFreight' => \App\Models\PermitInvoice::actualFreight($target),
        'vatRate'       => 0.14,
        'currency'      => 'ج.م',
        'invoices'      => \App\Models\PermitInvoice::forPermit($target),
        'paymentTermsDays' => 30,
        'formState'     => ['token' => 't', 'old' => [], 'errors' => [], 'back' => 'permits'],
        'voidState'     => ['token' => 't', 'old' => [], 'errors' => [], 'back' => 'permits'],
    ]);

    chk98($html !== '' && !str_contains($html, 'Fatal') && !str_contains($html, 'Undefined'),
        'فورم الفوترة بيترندر بلا أي تحذير PHP');

    /* صف النولون: `class="i-price"` وقيمته لازم تبقى فاضية. */
    $freightRowOk = preg_match('~<tr class="inv-row freight-row".*?</tr>~s', $html, $fr) === 1;
    chk98($freightRowOk, 'صف النولون موجود في الماركب');
    if ($freightRowOk) {
        chk98(preg_match('~name="item_price\[\]" value=""~', $fr[0]) === 1,
            '**خانة سعر النولون رجعت فاضية** رغم إن العميل عنده تاريخ أسعار قوي');
        chk98(!str_contains($fr[0], '27500') && !str_contains($fr[0], '27,500'),
            'ولا السعر المعتاد اتسرّب للماركب — بيوصل بالجافاسكريبت بس');
    }
    chk98(substr_count($html, 'data-habit-price=') === 1,
        'وسمة واحدة بس بتربط الشاشة بالمحرّك (بصمة v98 في الملف = سطر واحد)',
        'الموجود: ' . substr_count($html, 'data-habit-price='));
    chk98(str_contains($html, 'data-actual-freight='),
        'والنولون الفعلي متاح للشاشة عشان تحسب الهامش بلا طلب تاني');

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bad98('استثناء أثناء الفحص: ' . $e->getMessage());
}

// ═══════════════════════════════════════ ⑥
sec98('⑥ المصدر الوحيد والتصميم');

$js = code98('public/assets/js/habit-suggest.js');

/* ⚠️ خانة المبلغ بتتحوّل `type=text` بفواصل آلاف، فـ`parseFloat("27,500")`
   بترجّع **27**. `window.Money.raw` هي المصدر الوحيد للفكّ (CLAUDE.md). */
chk98(str_contains($js, 'window.Money') && str_contains($js, '.raw'),
    'فكّ فواصل الآلاف بيمرّ على `window.Money.raw` — مش نسخة تانية من المنطق');
chk98(!preg_match('~parseFloat\(\s*priceEl\.value~', $js),
    'ومفيش `parseFloat` مباشر على خانة مبلغ (كان هيقرا «27,500» كـ٢٧)');

/* الحساب النهائي بتاع الفاتورة **مش متكرّر هنا**: بنبعت حدث `input` فبيشتغل
   `recalc()` بتاع الشاشة. نسختان من الحساب = كارت يقول رقم وفاتورة تقول تاني. */
chk98(str_contains($js, "new Event('input'"),
    'والاقتراح بيشغّل `recalc()` بتاع الفورم بحدث — مش بيحسب الإجمالي بنفسه');

$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$classes = [];
foreach ([['~class="([a-z0-9 \-]+)"~i', 1], ["~className\s*=\s*'([a-z0-9 \-]+)'~i", 1]] as [$re, $g]) {
    if (preg_match_all($re, $js, $m)) {
        foreach ($m[$g] as $chunk) {
            foreach (preg_split('~\s+~', trim($chunk)) as $c) { if ($c !== '') { $classes[$c] = true; } }
        }
    }
}
chk98($classes !== [], 'الفحص لقى كلاسات فعلاً (نمط ما بيمسكش حاجة بينجح للأبد)');
$missing = array_values(array_filter(array_keys($classes),
    static fn (string $c) => !str_contains($css, '.' . $c)));
chk98($missing === [], 'وكل كلاس في الجافاسكريبت له تعريف في `app.css` — صفر تصميم جديد',
    implode(' · ', $missing));

$form = (string) file_get_contents($root . '/app/Views/permits/invoice_form.php');
chk98(!str_contains($form, '<style') && !str_contains($form, 'style='),
    'وفورم الفوترة لسه بصفر `style=` محلي');

sec98('⑦ حُرّاس المسار');

$ctrlCode = code98('app/Controllers/SuggestionController.php');
chk98(substr_count($ctrlCode, 'requireAuth()') >= 5,
    'كل أكشن بينده `requireAuth()` بإيده');
chk98(str_contains($ctrlCode, 'Permit::find('),
    'والسياق بيتجاب من القاعدة برقم الإذن — الشاشة ما بتبعتش عميلاً يتزوّر');

$routes = code98('public/index.php');
chk98(strpos($routes, "'/suggest/price/{permit}/drill'") < strpos($routes, "'/suggest/price/{permit}'"),
    'ومسار التفاصيل الحرفي قبل الجشع (الراوتر first-match-wins)');

echo "\n" . str_repeat('=', 62) . "\n";
printf("ناجح: %d · فاشل: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
