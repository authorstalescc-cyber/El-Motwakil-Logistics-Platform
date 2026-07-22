<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v44 — صلاحية «فتح إذن» لكل مندوب على حِدة.
 *
 * قرار المالك: v43 خلّت أي مندوب يفتح إذن وده كان أوسع من اللازم. دلوقتي
 * `reps.can_create_permit` بيحدّد مين، و**الرفض هو الافتراضي**.
 *
 * ⚠️ أهم فحص هنا: **المنع بيتفرض في الموديل مش في الواجهة**. إخفاء الزرار
 *    مش حماية — المندوب الممنوع لازم يترفض حتى لو ناداها مباشرةً بفورم مزوّر.
 *
 * آمن: كل حاجة جوّه transaction وبيعمل rollback في الآخر، فما بيسيبش أي أثر.
 *
 * التشغيل:  php bin/verify_v44.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\Rep;
use App\Models\RepPortal;
use App\Models\RepSubmissionValidationException;

$pass = 0; $fail = 0; $skip = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 60) . "\n"; }

function is(string $label, $actual, $expected): void
{
    $same = (is_float($expected) || is_float($actual))
        ? abs((float) $actual - (float) $expected) < 0.005
        : $actual == $expected;
    $same ? ok($label) : bad("$label — متوقع: " . var_export($expected, true) . " / فعلي: " . var_export($actual, true));
}

/** بيانات إذن صالحة — عشان أي رفض يبقى بسبب الصلاحية مش بسبب التحقق. */
function v44Payload(): array
{
    return [
        'permit_type' => 'sader',
        'permit_no'   => '__v44_' . substr(bin2hex(random_bytes(4)), 0, 8),
        'permit_date' => date('Y-m-d'),
        'company'     => '__v44 شركة', 'client' => '__v44 وكيل', 'containers' => 1,
    ];
}

echo "فحص v44 — صلاحية فتح إذن للمناديب\n" . str_repeat('=', 60) . "\n";

// ═══════════════════════════════════════════ 0) البنية
sec('0) البنية (migrate_v44)');
$ready = Rep::permitPermReady();
$ready ? ok('عمود reps.can_create_permit موجود')
       : bad('العمود ناقص — شغّل php bin/migrate_v44.php');

if ($ready) {
    $def = Database::scalar(
        "SELECT COLUMN_DEFAULT FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='reps' AND column_name='can_create_permit'");
    is('الافتراضي 0 (الرفض هو الأصل — أي مندوب جديد ممنوع)', (int) $def, 0);
}

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    $treasuryBefore = Closing::currentBalance();

    // ═══════════════════════════════════════════ 1) الممنوع بيترفض
    sec('1) ⚠️ المندوب الممنوع بيترفض في الموديل (مش بإخفاء الزرار)');

    Database::exec("INSERT INTO reps (name, status) VALUES ('__v44_denied','active')");
    $denied = Database::lastId();
    if ($ready) { Database::exec("UPDATE reps SET can_create_permit = 0 WHERE id = ?", [$denied]); }

    is('canCreatePermit = false', Rep::canCreatePermit($denied), false);
    is('والواجهة بتسأل نفس المصدر', RepPortal::canCreatePermit($denied), false);

    try {
        RepPortal::createPermit($denied, v44Payload());
        bad('✗✗ مندوب ممنوع فتح إذن — الحارس مكسور!');
    } catch (RepSubmissionValidationException $e) {
        ok('createPermit اترفضت برسالة عربية واضحة');
        is('والرسالة بتقوله يكلّم المكتب',
            str_contains((string) ($e->errors()['_form'] ?? ''), 'صلاحية'), true);
    } catch (\Throwable $e) {
        bad('✗ اترفضت بخطأ غير متوقّع: ' . $e->getMessage());
    }

    is('ومفيش إذن اتكتب في القاعدة',
        (int) Database::scalar("SELECT COUNT(*) FROM permits WHERE permit_no LIKE '__v44\\_%'"), 0);

    // ═══════════════════════════════════════════ 2) المسموح بيعدّي
    sec('2) المندوب المسموح بيعدّي عادي');

    Database::exec("INSERT INTO reps (name, status) VALUES ('__v44_allowed','active')");
    $allowed = Database::lastId();
    if ($ready) {
        Rep::setCanCreatePermit($allowed, true);
        is('canCreatePermit = true بعد المنح', Rep::canCreatePermit($allowed), true);
        try {
            $res = RepPortal::createPermit($allowed, v44Payload());
            ok('فتح الإذن نجح — ' . $res['label']);
            $row = Database::row('SELECT status, inspection_rate, guarantee_rate FROM permits WHERE id = ?', [$res['id']]);
            is('والإذن مفتوح', $row['status'], 'open');
            // قاعدة v43 لسه قائمة: مفيش أي رقم مالي من المندوب
            is('وبلا نسبة كشف (شغل المكتب)', $row['inspection_rate'], null);
            is('وبلا نسبة ضمان (شغل المكتب)', $row['guarantee_rate'], null);
        } catch (\Throwable $e) {
            bad('✗ المسموح له اترفض بالغلط: ' . $e->getMessage());
        }

        // ═══════════════════════════════════════════ 3) السحب بيشتغل فورًا
        sec('3) سحب الصلاحية بيشتغل فورًا');
        Rep::setCanCreatePermit($allowed, false);
        is('canCreatePermit رجعت false', Rep::canCreatePermit($allowed), false);
        try {
            RepPortal::createPermit($allowed, v44Payload());
            bad('✗✗ لسه بيفتح بعد سحب الصلاحية!');
        } catch (RepSubmissionValidationException $e) { ok('وفتح الإذن اترفض بعد السحب'); }
    } else { skip('العمود مش موجود — اتخطّى فحوصات المنح'); }

    // ═══════════════════════════════════════════ 4) المنع ما بيأثرش على باقي شغله
    sec('4) المندوب الممنوع لسه بيشتغل عادي (المنع على الفتح بس)');

    $openPermit = (int) Database::scalar(
        "SELECT p.id FROM permits p WHERE p.status='open'
           AND EXISTS(SELECT 1 FROM permit_movements m WHERE m.permit_id=p.id) LIMIT 1");
    if ($openPermit) {
        $sees = false;
        foreach (RepPortal::myPermits($denied) as $p) { if ((int) $p['id'] === $openPermit) { $sees = true; } }
        is('لسه شايف الأذونات المفتوحة (قاعدة v43)', $sees, true);
        try { RepPortal::assertAssigned($denied, $openPermit); ok('ولسه بيقدر يسجّل عليها بنوده'); }
        catch (\Throwable $e) { bad('✗ اتمنع من التسجيل — المنع أوسع من اللازم!'); }
    } else { skip('مفيش إذن مفتوح بحركة للاختبار'); }

    // ═══════════════════════════════════════════ 5) العزل المالي
    sec('5) ⚠️ العزل المالي — الصلاحية وصفية بحتة');
    is('رصيد الخزنة ما اتغيّرش', Closing::currentBalance(), $treasuryBefore);

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر في قاعدة البيانات.\n";

    // الحالة الفعلية بعد التراجع — عشان المالك يشوف مين مسموح له فعلاً
    if (Rep::permitPermReady()) {
        $live = Database::all(
            "SELECT r.name, COALESCE(ru.username,'—') u FROM reps r
             LEFT JOIN rep_users ru ON ru.rep_id = r.id
             WHERE r.can_create_permit = 1 ORDER BY r.name");
        echo "\nالمسموح لهم يفتحوا إذن دلوقتي (" . count($live) . "):\n";
        foreach ($live as $x) { echo "  • {$x['name']} ({$x['u']})\n"; }
        if (!$live) { echo "  (مفيش حد)\n"; }
    }

    echo "\nنجح: $pass | فشل: $fail | تخطّي: $skip\n";
    exit($fail > 0 ? 1 : 0);
}
