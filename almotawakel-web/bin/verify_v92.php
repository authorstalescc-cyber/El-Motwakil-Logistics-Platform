<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v92 — **المستند الأرشيفي** واستيراد كشوف حسابات العملاء.
 *
 * اللزمة: ٥٧ حساب عميل من إكسل (٩٢١ فاتورة + ٣٣٩ تحصيل + ٩ أرصدة مرحّلة =
 * ٨,٢٦٤,٠١٩ مطلوبة من العملاء) اتنقلوا للنظام كمستندات بلا أذونات.
 *
 * أهم اللي بيتأكد منه:
 *   ① **البنية**: `permit_id` بيقبل NULL والمفتاح الأجنبي **لسه حارس**،
 *      و`origin`/`import_key` موجودين والفريد فريد فعلاً.
 *   ② **صفر أثر على الخزنة** — أهم فحص في الوحدة. أربعين مليون تحصيل اتسجّل،
 *      ولو صفّ واحد منهم اترشّح على العهدة أو كارت فيزا أو خزنة الشركة يبقى
 *      رصيد الدرج اتزوّر. الفحص بيقيس **رصيد الخزنة قبل وبعد** إدخال تحصيل
 *      أرشيفي حقيقي، وبيتأكد إن ولا صف أرشيفي بيحمل أي ربط نقدي.
 *   ③ **مفيش تحسيب مزدوج**: ولا رقم إذن واحد بيظهر في مستند نظام حيّ **و**
 *      مستند أرشيفي حيّ في نفس الوقت. ده الحارس اللي بيمسك أي استيراد تاني
 *      بالغلط — ومسك ٣٠ حالة فعلاً وقت البناء (النظام بيكتب `CFA0940630`
 *      والشيت بيكتب `940630`).
 *   ④ **الاستيراد ما يتكررش**: نفس `import_key` مرتين = رفض من القاعدة نفسها
 *      لا من السكربت، فحتى التشغيل اليدوي الغلط ما يقدرش يضاعف الأرقام.
 *   ⑤ **السلوك**: المستند الأرشيفي بيوصل لرصيد العميل زيّه زي أي فاتورة،
 *      والتحصيل بيقلّله — يعني كشف الحساب شغّال مش بس الصفوف متخزَّنة.
 *   ⑥ **الأرشيفي بلا إذن**: ولا صفّ `origin='legacy'` بيشاور على إذن، وولا
 *      واحد بياخد رقم من سلسلة `MT-` الحيّة.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v92.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\ClientAccount;
use App\Models\ClientPayment;
use App\Models\PartyMaster;
use App\Models\PermitInvoice;

$pass = 0; $fail = 0;
function ok92(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad92(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec92(string $t): void { echo "\n$t\n" . str_repeat('-', 62) . "\n"; }
function chk92(bool $c, string $m, string $why = ''): void {
    $c ? ok92($m) : bad92($m . ($why !== '' ? " — $why" : ''));
}

$hasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);

echo "فحص v92 — المستند الأرشيفي واستيراد كشوف العملاء\n" . str_repeat('=', 62) . "\n";

if (!$hasCol('permit_invoices', 'origin')) {
    echo "\n⚠️ عمود origin مش موجود — شغّل php bin/migrate_v92.php الأول.\n";
    exit(1);
}

// ═══════════════════════════════════════════ ① البنية

sec92('① البنية — المستند يعيش بلا إذن والحراسة لسه قايمة');

$nullable = 'YES' === (string) Database::scalar(
    "SELECT is_nullable FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'permit_invoices' AND column_name = 'permit_id'");
chk92($nullable, 'permit_id بيقبل NULL', 'المستند الأرشيفي مستحيل يتحفظ');

$fk = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE() AND table_name = 'permit_invoices'
        AND constraint_name = 'fk_pinv_permit'");
chk92($fk === 1, 'المفتاح الأجنبي على الإذن لسه حارس',
    'تخفيف القيد اتحوّل لإلغاء الحراسة — المستند بقى يشاور على إذن مش موجود');

foreach ([['permit_invoices', 'uq_pinv_import_key'], ['client_payments', 'uq_cpay_import_key']] as [$t, $ix]) {
    $uniq = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? AND non_unique = 0", [$t, $ix]);
    chk92($uniq === 1, "{$t}: {$ix} فريد فعلاً", 'الاستيراد يقدر يتكرر ويضاعف الأرقام');

    $def = trim((string) Database::scalar(
        'SELECT column_default FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, 'origin']), "'");
    chk92($def === 'system', "{$t}: origin افتراضيه system",
        "افتراضيه «{$def}» — كل صف جديد هيتحسب مستورَدًا");
}

// ═══════════════════════════════════════════ ② صفر أثر على الخزنة

sec92('② صفر أثر على الخزنة — أهم فحص في الوحدة');

/* ⚠️ الصف الأرشيفي **ممنوع** يحمل أي ربط نقدي. الأعمدة دي هي كل الطرق اللي
      تحصيل العميل يقدر يمسّ بيها فلوسًا حقيقية (عهدة · كارت فيزا · خزنة شركة). */
$moneyLinks = array_values(array_filter(
    ['custody_id', 'deposit_id', 'visa_card_id', 'company_entry_id'],
    static fn (string $c): bool => $hasCol('client_payments', $c)));
if ($moneyLinks) {
    $w = implode(' OR ', array_map(static fn ($c) => "$c IS NOT NULL", $moneyLinks));
    $leak = (int) Database::scalar("SELECT COUNT(*) FROM client_payments WHERE origin='legacy' AND ($w)");
    chk92($leak === 0, 'ولا تحصيل أرشيفي مربوط بعهدة/فيزا/خزنة شركة',
        "{$leak} صف مربوط — الفلوس دي اتقبضت في الماضي، تسجيلها كدخول نقدي بيزوّر الرصيد");
}
$route = (int) Database::scalar("SELECT COUNT(*) FROM client_payments WHERE origin='legacy' AND route<>'none'");
chk92($route === 0, "كل التحصيلات الأرشيفية route='none'", "{$route} صف بمسار بيمسّ فلوس");

/* السلوك مش البنية: نزرع تحصيلًا أرشيفيًّا حقيقيًّا ونقيس رصيد الخزنة قبل وبعد. */
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $before = \App\Models\Closing::currentBalance();

    $agent = PartyMaster::ensure('agent', 'فاحص أرشيف v92');
    if (!$agent) { throw new RuntimeException('تعذّر تجهيز عميل الفحص.'); }
    $aid = (int) $agent['id'];

    Database::exec(
        "INSERT INTO permit_invoices
            (permit_id,doc_kind,status,taxable,invoice_date,due_date,client,agent_id,
             containers,freight,vat_rate,vat_amount,subtotal,total,paid_amount,balance_due,
             items_json,note,issued_at,origin,import_key)
         VALUES (NULL,'invoice','issued',0,'2026-01-15','2026-02-14',?,?,0,0,0,0,?,?,0,?,?,?,NOW(),'legacy',?)",
        [$agent['name'], $aid, 5000.00, 5000.00, 5000.00,
         json_encode([['label' => 'بند فحص', 'qty' => 1, 'price' => 5000, 'total' => 5000, 'freight' => 0]], JSON_UNESCAPED_UNICODE),
         'فحص v92', 'verify92#doc']
    );
    $docId = Database::lastId();
    Database::exec('UPDATE permit_invoices SET doc_no=? WHERE id=?', ['MTL-V92T', $docId]);

    Database::exec(
        "INSERT INTO client_payments
            (client_name,agent_id,pay_date,amount,status,unallocated_amount,note,route,origin,import_key)
         VALUES (?,?,'2026-02-01',?, 'posted',?,?,'none','legacy',?)",
        [$agent['name'], $aid, 2000.00, 2000.00, 'فحص v92', 'verify92#pay']
    );
    $payId = Database::lastId();

    $after = \App\Models\Closing::currentBalance();
    chk92(abs($after - $before) < 0.005,
        'رصيد الخزنة ما اتحركش بعد مستند + تحصيل أرشيفي',
        'اتحرك من ' . number_format($before, 2) . ' لـ' . number_format($after, 2));

    // ═══════════════════════════════ ⑤ السلوك — الرقم بيوصل لكشف العميل

    sec92('⑤ السلوك — المستند الأرشيفي بيوصل لرصيد العميل');

    $charges = (float) (PermitInvoice::summaryFor('client', $agent['name'])['total'] ?? 0);
    chk92(abs($charges - 5000.00) < 0.01, 'المستند الأرشيفي محسوب في مديونية العميل',
        'الإجمالي طلع ' . number_format($charges, 2) . ' بدل 5,000');

    $paid = (float) ClientPayment::totalFor($agent['name']);
    chk92(abs($paid - 2000.00) < 0.01, 'التحصيل الأرشيفي محسوب في تحصيلات العميل',
        'المدفوع طلع ' . number_format($paid, 2) . ' بدل 2,000');

    $ledger = ClientAccount::ledger($aid, (string) $agent['name']);
    $rows = $ledger['rows'] ?? $ledger;
    $seenDoc = $seenPay = false;
    foreach ((array) $rows as $r) {
        if (($r['kind'] ?? '') === 'invoice' && abs((float) ($r['debit'] ?? 0) - 5000) < 0.01) { $seenDoc = true; }
        if (($r['kind'] ?? '') === 'payment' && abs((float) ($r['credit'] ?? 0) - 2000) < 0.01) { $seenPay = true; }
    }
    chk92($seenDoc && $seenPay, 'الاتنين ظاهرين في كشف حساب العميل',
        'الصفوف متخزَّنة بس الكشف مش شايفها');

    // ═══════════════════════════════ ④ الاستيراد ما يتكررش

    sec92('④ حارس التكرار — القاعدة نفسها بترفض، مش السكربت');

    $rejected = false;
    try {
        Database::exec(
            "INSERT INTO client_payments
                (client_name,agent_id,pay_date,amount,status,unallocated_amount,route,origin,import_key)
             VALUES (?,?,'2026-02-01',?, 'posted',?,'none','legacy',?)",
            [$agent['name'], $aid, 2000.00, 2000.00, 'verify92#pay']
        );
    } catch (\Throwable $e) { $rejected = true; }
    chk92($rejected, 'نفس import_key مرتين = رفض من القاعدة',
        'تشغيل الاستيراد تاني هيضاعف كل الأرقام بالسكوت');

    $pdo->rollBack();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bad92('فشل فحص السلوك: ' . $e->getMessage());
}

// ═══════════════════════════════════════════ ③ مفيش تحسيب مزدوج

sec92('③ مفيش تحسيب مزدوج — رقم الإذن الواحد في ناحية واحدة بس');

/* ⚠️⚠️ الحارس ده هو اللي بيمسك أي استيراد اتنفّذ مرتين أو حارس تكرار اتكسر.
      المقارنة بالأرقام بس (بلا أصفار البداية) لأن النظام بيكتب `CFA0940630`
      والشيت بيكتب `940630` — والمقارنة الحرفية كانت بتفوّت ٣٠ حالة من ٧٤.

   ⚠️ **الاستثناء الوحيد المشروع** هو المستند المكمّل (`import_key` بيبدأ بـ
      `topup#`): لمّا النظام يكون مصدّر «مطالبة بالإيصالات» — مستند بالإيصالات
      لوحدها **بلا نولون بالتعريف** — بيفضل النولون مستحقًّا وما اتفوترش، فبيتكتب
      مستند بالفرق **بنفس رقم الإذن** عن قصد. الاستثناء متضيَّق: المكمّل الواحد
      لكل إذن وبس. أي مستند أرشيفي تاني بيشارك رقم إذن = تحسيب مزدوج ويفشل. */
$dupes = Database::all(
    "SELECT TRIM(LEADING '0' FROM REGEXP_REPLACE(permit_no,'[^0-9]','')) AS k,
            COUNT(DISTINCT origin) AS sides,
            SUM(origin='legacy' AND COALESCE(import_key,'') NOT LIKE 'topup#%') AS plain_legacy,
            SUM(origin='legacy' AND COALESCE(import_key,'') LIKE 'topup#%')     AS top_ups,
            GROUP_CONCAT(DISTINCT doc_no ORDER BY doc_no SEPARATOR ' + ') AS docs,
            SUM(total) AS val
       FROM permit_invoices
      WHERE status <> 'void' AND permit_no IS NOT NULL AND permit_no <> ''
        AND CHAR_LENGTH(TRIM(LEADING '0' FROM REGEXP_REPLACE(permit_no,'[^0-9]',''))) >= 4
      GROUP BY k HAVING sides > 1");

$realDupes = array_values(array_filter($dupes,
    static fn (array $d): bool => (int) $d['plain_legacy'] > 0 || (int) $d['top_ups'] > 1));
if ($realDupes) {
    bad92(count($realDupes) . ' رقم إذن ظاهر في مستند نظام **و** مستند أرشيفي — تحسيب مزدوج');
    foreach (array_slice($realDupes, 0, 8) as $d) {
        echo "      إذن {$d['k']}: {$d['docs']} = " . number_format((float) $d['val'], 2) . "\n";
    }
} else {
    ok92('ولا رقم إذن مشترك بين مستند نظام ومستند أرشيفي'
        . ($dupes ? ' (غير ' . count($dupes) . ' مستند مكمّل مقصود)' : ''));
}

// ═══════════════════════════════════════════ ⑥ الأرشيفي بلا إذن وبلا رقم حيّ

sec92('⑥ المستند الأرشيفي فاضل أرشيفيًّا');

$withPermit = (int) Database::scalar(
    "SELECT COUNT(*) FROM permit_invoices WHERE origin='legacy' AND permit_id IS NOT NULL");
chk92($withPermit === 0, 'ولا مستند أرشيفي مربوط بإذن في النظام',
    "{$withPermit} مستند مربوط — بيدخل عدّادات الأذونات والحاويات");

$badNo = (int) Database::scalar(
    "SELECT COUNT(*) FROM permit_invoices WHERE origin='legacy' AND doc_no LIKE 'MT-%'");
chk92($badNo === 0, 'ولا مستند أرشيفي خد رقمًا من سلسلة MT- الحيّة',
    "{$badNo} مستند بياكل من ترقيم النظام");

$noKey = (int) Database::scalar(
    "SELECT COUNT(*) FROM permit_invoices WHERE origin='legacy' AND (import_key IS NULL OR import_key='')");
chk92($noKey === 0, 'كل مستند أرشيفي له بصمة مصدر',
    "{$noKey} مستند بلا بصمة — مستحيل تتبّعه أو تراجعه");

// ═══════════════════════════════════════════ الخلاصة

$legacyDocs = (int) Database::scalar("SELECT COUNT(*) FROM permit_invoices WHERE origin='legacy'");
$legacyPays = (int) Database::scalar("SELECT COUNT(*) FROM client_payments WHERE origin='legacy'");
echo "\n" . str_repeat('=', 62) . "\n";
printf("في القاعدة: %d مستند أرشيفي · %d تحصيل أرشيفي\n", $legacyDocs, $legacyPays);
printf("ناجح: %d · فاشل: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
