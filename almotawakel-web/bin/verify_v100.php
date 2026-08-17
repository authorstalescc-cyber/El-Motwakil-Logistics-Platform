<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v100 — تفضيلات فوترة العميل (ضريبي · فاتورة/كشف/مطالبة).
 *
 * طلب المالك: «في عملاء بيتحاسبوا ضريبي وعملاء لأ، وعملاء بيفضل لهم كشف
 * الحساب وعملاء المطالبة — محتاج السيستم يتعلّم ده».
 *
 * أهم اللي بيتأكد منه:
 *   ① **الطبقتان بترتيبهما**: المثبَّت بيغلب المستنتَج، والمستنتَج بيغلب العام.
 *   ② **`NULL` مش `0`** — «ما قالش» غير «قال: غير خاضع». خلطهم بيقفل باب
 *      الاستنتاج على كل العملاء بالسكوت.
 *   ③ **القائمة المقفولة حارس في الموديل** — قيمة مزوّرة من الفورم بترجع NULL.
 *   ④ **حارس الضريبة بيغلب التفضيل**: الكشف والمطالبة ما بيخضعوش إطلاقًا.
 *   ⑤ **فورم الفوترة بيفتح على التفضيل فعلاً** (رندر خادمي حقيقي).
 *   ⑥ **صفر أثر مالي** — ولا فاتورة قايمة اتغيّرت.
 *   ⑦ **المصدر ظاهر للمالك** — «مثبَّت» غير «من التاريخ»، وإلا التخمين يتقري كقرار.
 *
 * fixtures جوّه transaction + rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v100.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\ClientPref;
use App\Models\PartyMaster;
use App\Models\PermitInvoice;

$pass = 0; $fail = 0;
function ok100(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad100(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chk100(bool $c, string $m, string $why = ''): void {
    $c ? ok100($m) : bad100($m . ($why !== '' ? " — {$why}" : ''));
}
function sec100(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع نفسه. */
function code100(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص v100 — تفضيلات فوترة العميل\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);

// ═══════════════════════════════════════ ⓪ البنية
sec100('⓪ البنية — التلاتة موجودة وبتقبل NULL وبلا افتراضي');

/* ⚠️ **أسماء مستعارة إجبارية**: MySQL 8 بيرجّع أعمدة `information_schema`
   بحروف كابيتال، فالقراءة بالحروف الصغيرة بتدّي مفتاحًا غير معرَّف — والفحص
   يفشل بإنذار كاذب على عمود سليم (اتصاد فعليًّا أول تشغيل للترحيل). */
/* ⚠️ **MariaDB بترجّع الافتراضي كنص `'NULL'` لا كقيمة فاضية** (من 10.2) و MySQL
   بترجّع `null` حقيقي. المقارنة بـ`=== null` لوحدها بتعدّي محليًّا وتفشل على
   الإنتاج — وده اللي وقّف نشر v100 أول مرة بعد ما الأعمدة اتعملت فعلاً. */
$noDefault = static fn ($d): bool => $d === null || strcasecmp(trim((string) $d), 'NULL') === 0;

foreach (['default_doc_kind', 'default_taxable', 'default_vat_rate'] as $col) {
    $meta = Database::row(
        'SELECT IS_NULLABLE AS n, COLUMN_DEFAULT AS d FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', ['agents', $col]);
    chk100($meta !== null && ($meta['n'] ?? '') === 'YES' && $noDefault($meta['d']),
        "`agents.{$col}` موجود، بيقبل NULL، وبلا افتراضي",
        $meta === null ? 'العمود ناقص' : "nullable={$meta['n']} default=" . var_export($meta['d'], true));
}
chk100($noDefault('NULL') && $noDefault(null) && !$noDefault('invoice'),
    'وقارن الافتراضي بيفهم صيغتَي MySQL وMariaDB — والقيمة الحقيقية لسه بتترفض');

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    $stamp = 'ف١٠٠-' . substr((string) getmypid(), -4);

    /** عميل + بروفايل. */
    $mkAgent = static function (string $name): array {
        Database::exec('INSERT INTO agents (name) VALUES (?)', [$name]);
        return Database::row('SELECT * FROM agents WHERE id = ?', [Database::lastId()]);
    };
    /** مستند فوترة وهمي. */
    $mkDoc = static function (string $client, string $kind, int $taxable, string $status = 'issued') {
        Database::exec(
            'INSERT INTO permit_invoices (doc_no, doc_kind, taxable, invoice_date, client, freight, subtotal, total, status)
             VALUES (?,?,?,?,?,?,?,?,?)',
            ['V100-' . substr(md5(uniqid('', true)), 0, 8), $kind, $taxable, date('Y-m-d'),
             $client, 1000, 1000, 1000, $status]
        );
    };

    // ═══════════════════════════════════════ ①
    sec100('① الاستنتاج من التاريخ — قبل ما المالك يثبّت حاجة');

    $learn = "عميل استنتاج {$stamp}";
    $ag = $mkAgent($learn);
    chk100(ClientPref::forAgent($ag)['kind_source'] === 'default',
        'بلا تاريخ ولا تثبيت ⇒ الافتراضي العام (مفيش تخمين من الهوا)');

    $mkDoc($learn, PermitInvoice::KIND_STATEMENT, 0);
    chk100(ClientPref::forAgent($ag)['kind_source'] === 'default',
        'ومستند واحد لسه مش نمط — الاستنتاج بيسكت');

    $mkDoc($learn, PermitInvoice::KIND_STATEMENT, 0);
    $p = ClientPref::forAgent($ag);
    chk100($p['doc_kind'] === PermitInvoice::KIND_STATEMENT && $p['kind_source'] === 'learned',
        'ومستندان من نفس النوع ⇒ استنتاج «كشف حساب» موسوم «من التاريخ»',
        json_encode($p, JSON_UNESCAPED_UNICODE));

    // ═══════════════════════════════════════ ②
    sec100('② المثبَّت بيغلب المستنتَج');

    PartyMaster::update('agent', (int) $ag['id'],
        ['name' => $learn, 'default_doc_kind' => PermitInvoice::KIND_CLAIM],
        (int) $ag['lock_version']);
    $ag2 = Database::row('SELECT * FROM agents WHERE id = ?', [(int) $ag['id']]);
    $p2 = ClientPref::forAgent($ag2);
    chk100($p2['doc_kind'] === PermitInvoice::KIND_CLAIM && $p2['kind_source'] === 'pinned',
        'قرار المالك بيغلب التاريخ (مطالبة رغم إن التاريخ كشوف)',
        json_encode($p2, JSON_UNESCAPED_UNICODE));

    // ═══════════════════════════════════════ ③
    sec100('③ ⚠️ NULL مش صفر — «ما قالش» غير «قال: غير خاضع»');

    $taxCli = "عميل ضريبي {$stamp}";
    $tg = $mkAgent($taxCli);
    $mkDoc($taxCli, PermitInvoice::KIND_INVOICE, 1);
    $mkDoc($taxCli, PermitInvoice::KIND_INVOICE, 1);
    $tp = ClientPref::forAgent($tg);
    chk100($tp['taxable'] === 1 && $tp['tax_source'] === 'learned',
        'فاتورتان ضريبيتان ⇒ استنتاج «خاضع» (الحقل NULL فالاستنتاج شغّال)');

    PartyMaster::update('agent', (int) $tg['id'],
        ['name' => $taxCli, 'default_taxable' => '0'], (int) $tg['lock_version']);
    $tg2 = Database::row('SELECT * FROM agents WHERE id = ?', [(int) $tg['id']]);
    chk100($tg2['default_taxable'] !== null && (int) $tg2['default_taxable'] === 0,
        'و«غير خاضع» بيتخزّن `0` **مش** NULL — قرار صريح مش غياب');
    $tp2 = ClientPref::forAgent($tg2);
    chk100($tp2['taxable'] === 0 && $tp2['tax_source'] === 'pinned',
        'فالصفر المثبَّت بيسكّت الاستنتاج ويطيع المالك',
        json_encode($tp2, JSON_UNESCAPED_UNICODE));

    PartyMaster::update('agent', (int) $tg['id'],
        ['name' => $taxCli, 'default_taxable' => ''], (int) $tg2['lock_version']);
    $tg3 = Database::row('SELECT * FROM agents WHERE id = ?', [(int) $tg['id']]);
    chk100($tg3['default_taxable'] === null,
        'و«حسب التاريخ» بيرجّع الحقل NULL — الباب بيتفتح تاني');
    chk100(ClientPref::forAgent($tg3)['tax_source'] === 'learned',
        'والاستنتاج بيرجع يشتغل فورًا');

    // ═══════════════════════════════════════ ④
    sec100('④ القائمة المقفولة — الحارس في الموديل مش في الشاشة');

    $tg4 = Database::row('SELECT * FROM agents WHERE id = ?', [(int) $tg['id']]);
    PartyMaster::update('agent', (int) $tg['id'],
        ['name' => $taxCli, 'default_doc_kind' => 'DROP TABLE agents', 'default_taxable' => '7'],
        (int) $tg4['lock_version']);
    $tg5 = Database::row('SELECT default_doc_kind, default_taxable FROM agents WHERE id = ?', [(int) $tg['id']]);
    chk100($tg5['default_doc_kind'] === null,
        'نوع مستند مزوّر من الفورم بيترفض ويتخزّن NULL');
    chk100((int) $tg5['default_taxable'] === 0,
        'وقيمة ضريبة برّه (0|1) بتتحوّل لصفر مش بتتخزّن زي ما هي');

    // ═══════════════════════════════════════ ⑤
    sec100('⑤ حارس الضريبة بيغلب التفضيل');

    /* ⚠️ الكشف والمطالبة **ما بيخضعوش للضريبة إطلاقًا** (`PermitInvoice::create`)
       — فتفضيل «خاضع» مع «كشف حساب» كان هيفتح الفورم على حالة الخادم بيرفضها. */
    $mix = "عميل متناقض {$stamp}";
    $mg = $mkAgent($mix);
    PartyMaster::update('agent', (int) $mg['id'],
        ['name' => $mix, 'default_doc_kind' => PermitInvoice::KIND_STATEMENT, 'default_taxable' => '1'],
        (int) $mg['lock_version']);
    $mg2 = Database::row('SELECT * FROM agents WHERE id = ?', [(int) $mg['id']]);
    $mp = ClientPref::forAgent($mg2);
    chk100($mp['doc_kind'] === PermitInvoice::KIND_STATEMENT && $mp['taxable'] === 0,
        'كشف حساب + «خاضع» ⇒ الضريبة بتتصفّر (تكرار حارس بيضيّق — مسموح)',
        json_encode($mp, JSON_UNESCAPED_UNICODE));

    // ═══════════════════════════════════════ ⑥
    sec100('⑥ فورم الفوترة بيفتح على التفضيل فعلاً');

    $prefCli = "عميل فورم {$stamp}";
    $pg = $mkAgent($prefCli);
    PartyMaster::update('agent', (int) $pg['id'],
        ['name' => $prefCli, 'default_doc_kind' => PermitInvoice::KIND_CLAIM],
        (int) $pg['lock_version']);
    Database::exec(
        'INSERT INTO permits (permit_type, permit_no, permit_date, client, company, containers)
         VALUES (?,?,?,?,?,0)',
        ['sader', 'V100-' . substr(md5(uniqid('', true)), 0, 8), date('Y-m-d'), $prefCli, "شركة {$prefCli}"]
    );
    $pid = Database::lastId();

    $ctrl = new class extends App\Core\Controller {
        public function form(array $data): string
        {
            ob_start();
            $this->render('permits/invoice_form', $data, false);
            return (string) ob_get_clean();
        }
    };
    $html = $ctrl->form([
        'p' => \App\Models\Permit::find($pid),
        'movements' => \App\Models\Permit::movements($pid),
        'totals' => \App\Models\Permit::totals($pid),
        'bills' => \App\Models\Permit::bills($pid),
        'expenseRows' => \App\Models\Permit::expenseRows($pid),
        'ctBreakdown' => [],
        'candidates' => PermitInvoice::candidateItems($pid),
        'actualFreight' => PermitInvoice::actualFreight($pid),
        'vatRate' => 0.14, 'currency' => 'ج.م',
        'invoices' => PermitInvoice::forPermit($pid),
        'paymentTermsDays' => 30,
        'formState' => ['token' => 't', 'old' => [], 'errors' => [], 'back' => 'permits'],
        'voidState' => ['token' => 't', 'old' => [], 'errors' => [], 'back' => 'permits'],
    ]);

    chk100($html !== '' && !str_contains($html, 'Fatal') && !str_contains($html, 'Undefined'),
        'الفورم بيترندر بلا أي تحذير PHP');
    chk100(preg_match('~name="doc_kind" value="claim"[^>]*checked~', $html) === 1,
        '**«مطالبة بالإيصالات» مختارة سلفًا** — الفورم فتح على تفضيل العميل');
    chk100(preg_match('~name="doc_kind" value="invoice"[^>]*checked~', $html) !== 1,
        'والفاتورة مش مختارة (الافتراضي القديم اتغيّر فعلاً)');

    // ═══════════════════════════════════════ ⑦
    sec100('⑦ المصدر ظاهر — التخمين ما يتقريش كقرار');

    $prof = new class extends App\Core\Controller {
        public function card(array $d): string
        { ob_start(); $this->render('clients/show', $d, false); return (string) ob_get_clean(); }
    };
    chk100(str_contains(code100('app/Views/clients/show.php'), 'ClientPref::')
           && str_contains(code100('app/Views/clients/show.php'), 'sourceLabel'),
        'بروفايل العميل بيعرض التفضيل **ومصدره** جنبه');
    chk100(ClientPref::sourceLabel('pinned') !== ClientPref::sourceLabel('learned'),
        'و«مثبَّت» غير «من التاريخ» في النص — مش نفس الكلمة للاتنين');

    $formCode = code100('app/Views/clients/form.php');
    chk100(str_contains($formCode, 'default_doc_kind') && str_contains($formCode, 'default_taxable'),
        'وفورم بيانات العميل فيه الخانتين — التثبيت من غير شاشة جديدة');
    /* ⚠️ العمود اتعمل لكنه **مش موصَّل**: نقطة الحفظ بتاخد النسبة من الإعدادات
       العامة، فخانة في الشاشة كانت هتوري نسبة ما بتتطبّقش — كذبة صامتة. */
    chk100(!str_contains($formCode, 'default_vat_rate'),
        'و«نسبة خاصة» مش معروضة — العمود لسه مش موصَّل بنقطة الحفظ');

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bad100('استثناء أثناء الفحص: ' . $e->getMessage());
}

// ═══════════════════════════════════════ ⑧
sec100('⑧ المصدر الوحيد وصفر أثر مالي');

$prefCode = code100('app/Models/ClientPref.php');
foreach (['INSERT', 'UPDATE ', 'DELETE'] as $w) {
    chk100(stripos($prefCode, $w) === false, "`ClientPref` مفيهوش `{$w}` — قراءة بحتة");
}
chk100(!preg_match('~\b(closing|company_safe|driver_custody)\b~i', $prefCode),
    'ولا ذكر لخزنة ولا تقفيل ولا عهدة');

/* الفورم لازم ينده `ClientPref` مش يكتب الشرط بإيده — وإلا الفورم يقول تفضيلًا
   والبروفايل يقول تاني بلا أي خطأ ظاهر. */
$inv = code100('app/Views/permits/invoice_form.php');
chk100(str_contains($inv, 'ClientPref::forName('),
    'وفورم الفوترة بينده `ClientPref` مش بيقرا الأعمدة بنفسه');
chk100(!preg_match("~\\\$old\['doc_kind'\]\s*\?\?\s*'invoice'~", $inv),
    'والافتراضي المكتوب بالإيد (`invoice`) اتشال');

echo "\n" . str_repeat('=', 62) . "\n";
printf("ناجح: %d · فاشل: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
