<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v43 — الأذونات المفتوحة لكل المناديب + فتح إذن من الموبايل.
 *
 * قرارا المالك اللي الفحص ده بيحرسهم:
 *   1) **كل إذن مفتوح بيظهر لكل مندوب** — التنزيل (permit_assignments) بقى شارة
 *      وترتيب بس، مش شرط رؤية ولا شرط كتابة. الحد الوحيد الباقي هو `status='open'`.
 *   2) **المندوب بيفتح الإذن بنفسه** من `/m/permit/new` وبيتفتح على طول بلا اعتماد،
 *      **بلا أي رقم مالي**: `inspection_rate`/`guarantee_rate` بيفضلوا NULL.
 *
 * ⚠️ أهم فحص هنا هو **العزل المالي**: فتح إذن ما بيمسّش رصيد الخزنة ولا عهدة
 *    المندوب — الإذن وعاء تشغيلي، الفلوس بتيجي من البنود اللي بتعدّي على
 *    صندوق الانتظار زي ما هي.
 *
 * آمن: كل حاجة جوّه transaction وبيعمل rollback في الآخر، فما بيسيبش أي أثر.
 *
 * التشغيل:  php bin/verify_v43.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepAssignment;
use App\Models\RepCustody;
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

echo "فحص v43 — أذونات مفتوحة للكل + فتح إذن من الموبايل\n" . str_repeat('=', 60) . "\n";

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    // ═══════════════════════════════════════════ 0) تجهيز
    sec('0) تجهيز بيانات وهمية');
    $repId = (int) Database::scalar("SELECT id FROM reps WHERE status='active' ORDER BY id LIMIT 1");
    if (!$repId) {
        Database::exec("INSERT INTO reps (name, status) VALUES ('__v43_rep','active')");
        $repId = Database::lastId();
    }
    ok("مندوب الاختبار #$repId");

    $balanceBefore = RepCustody::available() ? RepCustody::balance($repId) : 0.0;
    $treasuryBefore = Closing::currentBalance();
    ok('رصيد الخزنة قبل: ' . number_format($treasuryBefore, 2));

    // ═══════════════════════════════════════════ 1) الرؤية
    sec('1) كل إذن مفتوح بيظهر لكل مندوب');

    // إذن مفتوح **مش منزّل لأي مندوب** — القديم كان بيختفي، دلوقتي لازم يبان.
    Database::exec(
        "INSERT INTO permits (permit_type, permit_no, permit_date, company, client, status)
         VALUES ('sader', '__v43_open', CURDATE(), '__v43_co', '__v43_cl', 'open')");
    $openId = Database::lastId();
    \App\Models\Permit::createMovement($openId);

    $seen = [];
    foreach (RepPortal::myPermits($repId) as $p) { $seen[(int) $p['id']] = $p; }
    is('إذن مفتوح غير منزّل → ظاهر على موبايل المندوب', isset($seen[$openId]), true);

    try { RepPortal::assertAssigned($repId, $openId); ok('والكتابة عليه مسموحة'); }
    catch (\Throwable $e) { bad('✗ الكتابة على إذن مفتوح اترفضت: ' . $e->getMessage()); }

    /* ⚠️ 2026-07-28 — التنزيل اتشال خالص من شاشة المندوب (قرار المالك):
       مكانش فاضل منه غير شارة وترتيب، وده كان بيوهم المندوب إن فيه أذونات
       «بتاعته». الفحص اتقلب: التنزيل المفروض **ما يغيّرش أي حاجة** في اللي
       بيشوفه المندوب — لا مفتاح `mine` ولا ترتيب مختلف. */
    $before = array_map(fn($p) => (int) $p['id'], RepPortal::myPermits($repId));
    RepAssignment::assign($openId, $repId, '__v43');
    $after = RepPortal::myPermits($repId);
    is('التنزيل ما بيضيفش أي مفتاح `mine` للحزمة',
        array_key_exists('mine', $after[0] ?? []), false);
    is('والتنزيل ما بيغيّرش ترتيب القايمة (التاريخ هو الترتيب الوحيد)',
        array_map(fn($p) => (int) $p['id'], $after), $before);

    // ── الحد الحقيقي الوحيد: القفل
    Database::exec("UPDATE permits SET status='closed' WHERE id=?", [$openId]);
    $seesClosed = false;
    foreach (RepPortal::myPermits($repId) as $p) { if ((int) $p['id'] === $openId) { $seesClosed = true; } }
    is('الإذن المقفول → اختفى من الموبايل (حتى والتنزيل لسه active)', $seesClosed, false);
    try {
        RepPortal::assertAssigned($repId, $openId);
        bad('✗✗ الكتابة على إذن مقفول اتقبلت — الحد الوحيد مكسور!');
    } catch (\Throwable $e) { ok('والكتابة عليه اترفضت'); }
    Database::exec("UPDATE permits SET status='open' WHERE id=?", [$openId]);

    try {
        RepPortal::assertAssigned($repId, 99999999);
        bad('✗ إذن مش موجود اتقبل');
    } catch (\Throwable $e) { ok('إذن مش موجود → اترفض'); }

    // ═══════════════════════════════════════════ 2) فتح إذن من الموبايل
    sec('2) المندوب بيفتح إذن بنفسه');

    // v44 خلّت الصلاحية لكل مندوب على حِدة والافتراضي «ممنوع»، فبنمنحها لمندوب
    // الاختبار هنا. فحص المنع نفسه في bin/verify_v44.php.
    if (\App\Models\Rep::permitPermReady()) { \App\Models\Rep::setCanCreatePermit($repId, true); }
    else { skip('عمود الصلاحية مش موجود — شغّل migrate_v44'); }

    /* ⚠️ الميناء **لازم يتقرا من القائمة المعتمدة وقت التشغيل** لا يتكتب حرفيًّا:
       v58 خلّت `port` قائمة مقفولة، وقوائم المرجع بتختلف بين المحلي والإنتاج
       (اتصادف فعليًّا: «الدخيلة» موجودة محليًّا ومتشالة من الإنتاج لصالح «ميناء
       الدخيلة»، فالفحص كان بيفشل على السيرفر بس بإنذار كاذب). نفس المنطق ينطبق
       على أي حقل قائمة مقفولة يتضاف للفحص بعدين. */
    $ports = \App\Models\Lookup::ofType('port');
    if (!$ports) { skip('مفيش موانئ في القائمة المعتمدة — شغّل migrate_v58'); }
    $port = (string) $ports[0];

    $no = '__v43_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $res = RepPortal::createPermit($repId, [
        'permit_type' => 'sader', 'permit_no' => $no, 'permit_date' => date('Y-m-d'),
        'company' => '__v43 شركة الاختبار', 'client' => '__v43 وكيل الاختبار',
        'destination' => '__v43 وجهة', 'port' => $port,
        'containers' => 3, 'container_type' => '2*40', 'note' => 'من الفحص',
    ]);
    $newId = (int) $res['id'];
    ok("الإذن اتفتح #$newId — {$res['label']}");

    $row = Database::row('SELECT * FROM permits WHERE id = ?', [$newId]);
    is('حالته «مفتوح» على طول (بلا اعتماد)', $row['status'], 'open');
    is('اترصد عليه أول حركة جاهزة',
        (int) Database::scalar('SELECT COUNT(*) FROM permit_movements WHERE permit_id=?', [$newId]), 1);
    is('ظاهر في قايمة المندوب فورًا', (bool) RepPortal::myPermit($repId, $newId), true);
    // التنزيل التلقائي لسه بيتسجّل كسجل مكتب (كارت المندوب)، بس **مالوش أي أثر**
    // على شاشة المندوب بعد قرار 2026-07-28.
    is('واتسجّل تنزيل تلقائي (سجل مكتب بلا أثر على شاشة المندوب)',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM permit_assignments WHERE permit_id=? AND rep_id=? AND status='active'",
            [$newId, $repId]), 1);

    // ⚠️ أهم فحصين: مفيش أرقام مالية من المندوب
    is('inspection_rate فاضي (شغل المكتب)', $row['inspection_rate'], null);
    is('guarantee_rate فاضي (شغل المكتب)', $row['guarantee_rate'], null);

    // container_norm هي المصدر الوحيد للتوحيد (v30) — «2*40» لازم تبقى «40 قدم»
    is('تصنيف الحاوية اتوحّد بـcontainer_norm', $row['container_type'], container_norm('2*40'));
    is('عدد الحاويات زي ما اتكتب', (int) $row['containers'], 3);

    // الاسم القانوني من جدول الأطراف (تحذير 3)
    is('اتربط بوكيل في جدول الأطراف', (int) $row['agent_id'] > 0, true);
    is('واتربط بشركة في جدول الأطراف', (int) $row['company_id'] > 0, true);

    // سجل الإدخالات
    $log = Database::row(
        "SELECT * FROM audit_logs WHERE entity='permits' AND record_id=? ORDER BY id DESC LIMIT 1", [$newId]);
    is('اتسجّل في سجل الإدخالات', $log !== null, true);
    is('وبمصدر rep_portal', str_contains((string) ($log['meta_json'] ?? ''), 'rep_portal'), true);

    // ═══════════════════════════════════════════ 3) التحقق
    sec('3) الخادم مصدر الحقيقة (الفورم مش مصدر ثقة)');

    $cases = [
        'رقم مكرَّر' => ['permit_no' => $no],
        'رقم فاضي'   => ['permit_no' => ''],
        'تاريخ في المستقبل' => ['permit_date' => date('Y-m-d', strtotime('+3 days'))],
        'تاريخ غلط'  => ['permit_date' => 'xx'],
        'شركة فاضية' => ['company' => ''],
        'عميل فاضي'  => ['client' => ''],
        'نوع غلط'    => ['permit_type' => 'hack'],
        'حاويات سالبة' => ['containers' => -5],
    ];
    foreach ($cases as $label => $override) {
        $base = ['permit_type' => 'sader', 'permit_no' => '__v43_x' . substr(bin2hex(random_bytes(3)), 0, 6),
                 'permit_date' => date('Y-m-d'), 'company' => '__v43 شركة الاختبار',
                 'client' => '__v43 وكيل الاختبار', 'containers' => 1];
        try {
            RepPortal::createPermit($repId, array_replace($base, $override));
            bad("✗ $label اتقبل");
        } catch (RepSubmissionValidationException $e) { ok("$label → اترفض"); }
        catch (\Throwable $e) { bad("✗ $label رمى خطأ غير متوقّع: " . $e->getMessage()); }
    }

    // ═══════════════════════════════════════════ 4) العزل المالي (الأهم)
    sec('4) ⚠️ العزل المالي — فتح الإذن ما بيمسّش أي فلوس');

    $treasuryAfter = Closing::currentBalance();
    is('رصيد الخزنة ما اتغيّرش', $treasuryAfter, $treasuryBefore);

    if (RepCustody::available()) {
        is('رصيد عهدة المندوب ما اتغيّرش', RepCustody::balance($repId), $balanceBefore);
    } else { skip('عهدة المناديب مش متاحة'); }

    is('مفيش أي بند اتولّد على الإذن الجديد',
        (int) Database::scalar('SELECT COUNT(*) FROM permit_stages ps
                                JOIN permit_movements m ON m.id = ps.movement_id
                                WHERE m.permit_id = ?', [$newId]), 0);
    is('ومفيش أي بوليصة',
        (int) Database::scalar('SELECT COUNT(*) FROM bawalis WHERE permit_id = ?', [$newId]), 0);

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر في قاعدة البيانات.\n";
    echo "نجح: $pass | فشل: $fail | تخطّي: $skip\n";
    exit($fail > 0 ? 1 : 0);
}
