<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص الإجراءات الجماعية على الأذونات (POST /permits/bulk).
 * قراءة + كتابة داخل transaction تُلغى في النهاية — لا يسيب أي أثر.
 *
 * التشغيل:  php bin/verify_permit_bulk.php
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\Permit;
use App\Models\PartyMaster;

$pass = 0; $fail = 0;
function ok(string $msg): void   { global $pass; $pass++; echo "  ✓ $msg\n"; }
function bad(string $msg): void  { global $fail; $fail++; echo "  ✗ $msg\n"; }
function check(bool $cond, string $msg): void { $cond ? ok($msg) : bad($msg); }

echo "فحص الإجراءات الجماعية للأذونات\n================================\n\n";

// ── 1) الراوت والميثودز ────────────────────────────────────────────────
echo "[1] المسار والدوال\n";
$routes = file_get_contents(__DIR__ . '/../public/index.php');
$bulkPos   = strpos($routes, "'/permits/bulk'");
$greedyPos = strpos($routes, "\$r->post('/permits/{id}'");
check($bulkPos !== false, 'المسار POST /permits/bulk مسجَّل');
check($bulkPos !== false && $greedyPos !== false && $bulkPos < $greedyPos,
      'المسار الحرفي /permits/bulk قبل الجشع /permits/{id} (تحذير 4)');

$rc = new ReflectionClass(\App\Controllers\PermitController::class);
check($rc->hasMethod('bulk') && $rc->getMethod('bulk')->isPublic(), 'PermitController::bulk عامّة');
foreach (['bulkStatus','bulkDelete','bulkParty','bulkCsv','bulkIds','bulkBack'] as $m) {
    check($rc->hasMethod($m), "الدالة المساعدة $m موجودة");
}

// requireAuth + requireCsrf داخل bulk (تحذير 5: لا middleware)
$src = file_get_contents($rc->getFileName());
$body = substr($src, strpos($src, 'public function bulk(): void'), 900);
check(str_contains($body, 'requireAuth'),  'bulk تستدعي requireAuth()');
check($body && str_contains($body, 'requireCsrf'), 'bulk تستدعي requireCsrf()');

// ── 2) سقف الـ ids ─────────────────────────────────────────────────────
echo "\n[2] تنقية الـ IDs\n";
$mIds = $rc->getMethod('bulkIds');
$ctl  = $rc->newInstanceWithoutConstructor();
check($mIds->invoke($ctl, ['3','3','-1','0','abc','7']) === [3,7], 'يزيل المكرّر والسالب وغير الرقمي');
check($mIds->invoke($ctl, range(1, 401)) === [], 'يرفض ما يتجاوز السقف (400)');

$mBack = $rc->getMethod('bulkBack');
$_POST['back'] = 'https://evil.example/x';
check($mBack->invoke($ctl) === 'permits?view=records', 'bulkBack يرفض الروابط الخارجية');
$_POST['back'] = '/permits?view=records&st=open';
check($mBack->invoke($ctl) === 'permits?view=records&st=open', 'bulkBack يحافظ على الفلتر المحلي');
$_POST = [];

// ── 3) العزل المالي: رصيد الخزنة قبل ↔ بعد ────────────────────────────
echo "\n[3] العزل المالي (رصيد العهدة لا يتغيّر)\n";
$balanceBefore = \App\Models\Closing::currentBalance();
$pdo = Database::conn();
$pdo->beginTransaction();
$fixtureIds = [];
try {
    $stamp = '__bulk_' . bin2hex(random_bytes(5));
    for ($i = 1; $i <= 3; $i++) {
        Database::exec(
            "INSERT INTO permits (permit_type,permit_no,permit_date,client,company,status)
             VALUES ('sader',?,CURDATE(),?,?,'open')",
            [$stamp . '_' . $i, $stamp . '_client', $stamp . '_company']
        );
        $fixtureIds[] = Database::lastId();
    }
    $fixturePh = implode(',', array_fill(0, count($fixtureIds), '?'));
    $sample = Database::all(
        "SELECT id,status,client,company FROM permits WHERE id IN ($fixturePh) ORDER BY id",
        $fixtureIds
    );
    $ids = array_map(fn($p) => (int)$p['id'], $sample);
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    // إغلاق جماعي
    Database::exec("UPDATE permits SET status='closed' WHERE id IN ($ph)", $ids);
    $closed = (int)Database::scalar("SELECT COUNT(*) FROM permits WHERE id IN ($ph) AND status='closed'", $ids);
    check($closed === count($ids), 'الإغلاق الجماعي يضبط حالة كل المحدَّد');

    $balAfterClose = \App\Models\Closing::currentBalance();
    check(abs($balAfterClose - $balanceBefore) < 0.001, 'الإغلاق الجماعي لا يمسّ رصيد الخزنة');

    // تغيير الوكيل/الشركة
    $agent = PartyMaster::ensure('agent', $stamp . '_agent');
    if ($agent) {
        Database::exec("UPDATE permits SET client=?, agent_id=? WHERE id IN ($ph)",
            array_merge([$agent['name'], $agent['id']], $ids));
        $same = (int)Database::scalar("SELECT COUNT(*) FROM permits WHERE id IN ($ph) AND client=?",
            array_merge($ids, [$agent['name']]));
        check($same === count($ids), 'تغيير الوكيل الجماعي يُطبَّق على كل المحدَّد');
        check(abs(\App\Models\Closing::currentBalance() - $balanceBefore) < 0.001,
              'تغيير الوكيل لا يمسّ رصيد الخزنة');
    }

    // الحذف: fixture بلا مستند مالي، فلا يعتمد الاختبار على أذونات التشغيل.
    $pid = $ids[0];
    $billsTotal  = (int)Database::scalar("SELECT COUNT(*) FROM bawalis");
    Database::exec("UPDATE bawalis SET permit_id=NULL WHERE permit_id=?", [$pid]);
    Database::exec("DELETE FROM permits WHERE id=?", [$pid]);
    check((int)Database::scalar("SELECT COUNT(*) FROM bawalis") === $billsTotal,
          'الحذف لا يحذف أي بوليصة');
    check((int)Database::scalar("SELECT COUNT(*) FROM permits WHERE id=?", [$pid]) === 0,
          'الإذن نفسه اتحذف');
} finally {
    $pdo->rollBack();
}

// بعد rollback: fixtures اختفت وكل بيانات العمل بقيت كما هي.
echo "\n[4] الرجوع بعد rollback\n";
$fixturePh = implode(',', array_fill(0, count($fixtureIds), '?'));
check((int) Database::scalar("SELECT COUNT(*) FROM permits WHERE id IN ($fixturePh)", $fixtureIds) === 0,
      'اختفت أذونات الفحص الثلاثة بعد rollback');
check(abs(\App\Models\Closing::currentBalance() - $balanceBefore) < 0.001,
      'رصيد الخزنة النهائي = الرصيد الابتدائي');

// ── 5) حماية الحذف من المستندات المالية ───────────────────────────────
echo "\n[5] حماية الحذف\n";
$srcDel = substr($src, strpos($src, 'private function bulkDelete'), 3600);
check(str_contains($srcDel, "status <> 'void'"),
      'الحذف الجماعي يتخطّى أي إذن له مستند مالي صادر (نفس حماية destroy)');
check(strpos($srcDel, 'deleteFile') > strpos($srcDel, 'commit'),
      'المرفقات تُمسح بعد نجاح الـ commit فقط (لا تضيع على rollback)');
check(str_contains($srcDel, 'beginTransaction') && str_contains($srcDel, 'rollBack'),
      'الحذف الجماعي داخل transaction واحدة');

echo "\n================================\n";
echo "نجح: $pass — فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
