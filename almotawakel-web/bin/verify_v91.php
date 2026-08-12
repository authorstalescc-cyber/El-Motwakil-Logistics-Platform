<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v91 — الرقم المسلسل في مستند العميل فريد **لكل عميل** لا لكل النظام.
 *
 * بلاغ المالك (2026-08-12): «رقم الكشف بيقول موجود، وهو المفروض ما يتكررش
 * للعميل بس مش لكل العملاء».
 *
 * أهم اللي بيتأكد منه:
 *   ① **الطبقتان بيقولا نفس الحكم**: القيد في القاعدة على (عميل + رقم)،
 *      وحارس الموديل على نفس النطاق. لو واحد اتغيّر بس، يبقى الفورم يسمح
 *      والقاعدة ترمي خطأ SQL خام في وش المستخدم (أو العكس: منع بلا سبب).
 *   ② **السلوك**: نفس الرقم لعميلين مختلفين **بيعدّي فعلاً** (الإدخال بينجح
 *      في القاعدة مش بس الفحص بيقول لأ)، ولنفس العميل **بيترفض**.
 *   ③ **العميل الفارغ نطاق قائم بذاته** — مش «كله مسموح».
 *   ④ **exceptId** بيستثني الصف نفسه (تعديل مستند ما يتهمش نفسه).
 *   ⑤ **المصدر الوحيد**: مفيش ملف تاني بيسأل «الرقم متكرّر؟» باستعلام بإيده،
 *      وكل نقاط الاستدعاء بتمرّر العميل (النداء بلا عميل = رجوع للحجب العام).
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v91.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\PermitInvoice;

$pass = 0; $fail = 0;
function ok91(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad91(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chk91(bool $c, string $m): void { $c ? ok91($m) : bad91($m); }
function sec91(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع. */
function code91(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

$hasIdx91 = static fn (string $i): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'permit_invoices' AND index_name = ?", [$i]);

echo "فحص v91 — الرقم المسلسل فريد لكل عميل\n" . str_repeat('=', 62) . "\n";

// ═══════════════════════════════ ① القيد في القاعدة
sec91('① القيد في القاعدة — نطاقه العميل مش النظام');

chk91(!$hasIdx91('uq_pinv_serial_no'),
    'مفيش فريد عام على serial_no (وجوده = رقم واحد يحجز على كل العملاء)');
chk91($hasIdx91('uq_pinv_agent_serial'), 'الفريد المركّب موجود');

$idxCols = array_column(Database::all(
    "SELECT column_name AS c FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'permit_invoices'
        AND index_name = 'uq_pinv_agent_serial' ORDER BY seq_in_index"), 'c');
chk91($idxCols === ['agent_id', 'serial_no'],
    'وأعمدته (agent_id, serial_no) بالترتيب ده — فعلاً «رقم واحد لكل عميل»');
chk91($hasIdx91('idx_serial'), 'وفهرس البحث على العمود لوحده باقٍ (البحث الشامل بيستعمله)');

// ═══════════════════════════════ ② السلوك الحقيقي
sec91('② السلوك — عميلان بنفس الرقم بيعدّوا، والعميل الواحد لأ');

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    $stamp  = 'ف٩١-' . substr((string) getmypid(), -4);
    $clientA = 'عميل فحص ٩١ ألف ' . $stamp;
    $clientB = 'عميل فحص ٩١ باء ' . $stamp;
    $serial  = 'SER-' . $stamp;

    /** إذن وهمي لعميل — الحد الأدنى من الأعمدة. */
    $mkPermit = static function (string $client) use ($stamp): int {
        Database::exec(
            'INSERT INTO permits (permit_type, permit_no, permit_date, client, company, containers)
             VALUES (?,?,?,?,?,?)',
            ['sader', 'PN-' . $stamp . '-' . substr(md5($client), 0, 6), work_date(), $client, 'شركة فحص ٩١', 1]
        );
        return (int) Database::lastId();
    };

    /** مستند عميل بالرقم المسلسل ده — بيرجّع id أو يرمي لو القاعدة رفضت. */
    $mkInvoice = static function (int $permitId, string $client, string $serial): int {
        return PermitInvoice::create([
            'permit_id' => $permitId, 'doc_kind' => 'statement', 'taxable' => 0,
            'serial_no' => $serial, 'invoice_date' => work_date(),
            'client' => $client, 'company' => 'شركة فحص ٩١', 'containers' => 1,
            'destination' => 'وجهة فحص', 'permit_no' => 'PN-فحص', 'permit_type' => 'sader',
            'agency' => 'توكيل فحص', 'port' => 'ميناء فحص',
            'items' => [['label' => 'بند فحص', 'qty' => 1, 'price' => 100]],
        ]);
    };

    $permitA = $mkPermit($clientA);
    $permitB = $mkPermit($clientB);
    $invA    = $mkInvoice($permitA, $clientA, $serial);

    chk91($invA > 0, 'اتّصدر مستند للعميل الأول بالرقم المسلسل');

    // ★ لبّ البلاغ: نفس الرقم مع عميل تاني لازم يعدّي — في الحارس **وفي القاعدة**.
    chk91(!PermitInvoice::serialExists($serial, $clientB),
        '★ الحارس بيسمح بنفس الرقم لعميل تاني');

    $invB = null;
    try {
        $invB = $mkInvoice($permitB, $clientB, $serial);
        ok91('★ والقاعدة قبلته فعلاً (مستند تاني بنفس الرقم لعميل مختلف)');
    } catch (\Throwable $e) {
        bad91('★ القاعدة رفضت نفس الرقم لعميل تاني — القيد لسه عام: ' . $e->getMessage());
    }

    chk91(PermitInvoice::serialExists($serial, $clientA),
        'وبيمنع تكراره مع نفس العميل الأول');
    chk91($invB === null || PermitInvoice::serialExists($serial, $clientB),
        'وبيمنع تكراره مع العميل التاني بعد ما اتّصدر له');

    // ③ العميل الفارغ نطاق قائم بذاته
    chk91(!PermitInvoice::serialExists($serial, ''),
        'العميل الفارغ نطاق لوحده — رقم عميل مسمّى ما بيحجزش على المستندات بلا عميل');

    // ④ exceptId — تعديل المستند ما بيتّهمش نفسه
    chk91(!PermitInvoice::serialExists($serial, $clientA, $invA),
        'exceptId بيستثني المستند نفسه (وإلا أي تعديل يرفض رقمه هو)');

    // رقم مالوش وجود
    chk91(!PermitInvoice::serialExists($serial . '-مش-موجود', $clientA),
        'ورقم جديد بيعدّي عادي');
    chk91(!PermitInvoice::serialExists('', $clientA), 'والرقم الفاضي مش تكرارًا أبدًا');
} finally {
    $pdo->rollBack();
}

// ═══════════════════════════════ ⑤ المصدر الوحيد
sec91('⑤ المصدر الوحيد — سؤال «متكرّر؟» في دالة واحدة');

$model = code91('app/Models/PermitInvoice.php');
chk91(str_contains($model, 'function serialExists'), 'الدالة الحارسة موجودة في الموديل');
chk91(str_contains($model, 'PartyMaster::resolve'),
    'وبتطابق العميل بهويته القانونية (لا بالتهجئة الخام وحدها)');

$others = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/app'));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
    $rel = str_replace(dirname(__DIR__) . '/', '', $f->getPathname());
    if ($rel === 'app/Models/PermitInvoice.php') { continue; }
    $code = code91($rel);
    if (preg_match('/permit_invoices[^;]{0,200}serial_no\s*=/i', $code)) { $others[] = $rel; }
}
chk91($others === [],
    'ولا ملف تاني بيسأل عن تكرار الرقم باستعلام بإيده'
    . ($others ? ' — ' . implode(' · ', $others) : ''));

/* ⚠️ نقطة الاستدعاء اللي بتنسى تمرّر العميل بترجع للحجب العام **بلا أي خطأ**
   (المعامل له قيمة افتراضية) — فبنعدّ النقاط ونتأكد إن كلها ممرّرة النطاق. */
$ctrl  = code91('app/Controllers/PermitController.php');
$calls = preg_match_all('/serialExists\s*\(/', $ctrl);
$scoped = preg_match_all('/serialExists\s*\(\s*\$serial\s*,\s*\(string\)/', $ctrl);
chk91($calls >= 2, "نقاط استدعاء الحارس في الكنترولر: {$calls}");
chk91($calls === $scoped, "وكلها بتمرّر العميل ({$scoped}/{$calls}) — مفيش نداء بيرجع للحجب العام");

/* الرسالة لازم تقول النطاق: «مستخدَم مع فلان» مش «مستخدَم» على إطلاقها. */
$msgSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/PermitController.php');
chk91(str_contains($msgSrc, 'serialTakenMsg'), 'ورسالة التكرار في دالة واحدة');
chk91(preg_match('/serialTakenMsg[^}]{0,600}عميل تاني/u', $msgSrc) === 1,
    'وبتقول للمالك إن نفس الرقم مسموح مع عميل تاني');

echo "\n" . str_repeat('═', 62) . "\n";
echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
echo "\nنجح: $pass | فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
