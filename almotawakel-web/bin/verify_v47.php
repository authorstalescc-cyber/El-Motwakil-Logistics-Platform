<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v47 — متابعة المناديب من موبايل المشرف.
 *
 * أهم 3 حاجات بيتأكد منها:
 *   1) **المنع بيتفرض في الموديل مش في الواجهة** — إخفاء التبويب مش حماية.
 *   2) **الأرقام واحدة** — رصيد كل مندوب في شاشة المتابعة = `RepCustody::balance()`
 *      بالمليم، عشان ما يبقاش عندنا تعريفين للرصيد.
 *   3) **قراءة فقط + مفيش سعر عميل** — الخزنة وعهدة المناديب وعدد البنود ما
 *      بيتغيّروش، ولا عمود `actual_unit`/`actual_amount` بيوصل الشاشة (قاعدة v35).
 *
 * آمن: كل حاجة جوّه transaction وبيعمل rollback في الآخر، فما بيسيبش أي أثر.
 *
 * التشغيل:  php bin/verify_v47.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\Rep;
use App\Models\RepCustody;
use App\Models\RepPortal;
use App\Models\RepWatch;

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

echo "فحص v47 — متابعة المناديب\n" . str_repeat('=', 60) . "\n";

// ═══════════════════════════════════════════ 0) البنية
sec('0) البنية (migrate_v47)');
$ready = Rep::watchPermReady();
$ready ? ok('عمود reps.can_watch_reps موجود')
       : bad('العمود ناقص — شغّل php bin/migrate_v47.php');

if ($ready) {
    $def = Database::scalar(
        "SELECT COLUMN_DEFAULT FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='reps' AND column_name='can_watch_reps'");
    is('الافتراضي 0 (الرفض هو الأصل — أي مندوب جديد ممنوع)', (int) $def, 0);
}

// المسارات لازم تكون حرفية قبل الجشع (تحذير 4)
$routes = (string) @file_get_contents(\App\Core\App::basePath('public/index.php'));
$posTeam   = strpos($routes, "'/m/team'");
$posTeamId = strpos($routes, "'/m/team/{rid}'");
$posGreedy = strpos($routes, "'/m/permit/{id}'");
is('مسار /m/team موجود', $posTeam !== false, true);
is('و/m/team/{rid} بعده وقبل الجشع /m/permit/{id}',
    $posTeam !== false && $posTeamId !== false && $posGreedy !== false
    && $posTeam < $posTeamId && $posTeamId < $posGreedy, true);

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    $treasuryBefore = Closing::currentBalance();
    $subsBefore = (int) Database::scalar("SELECT COUNT(*) FROM rep_submissions");

    // ═══════════════════════════════════════════ 1) الممنوع بيترفض
    sec('1) ⚠️ المندوب الممنوع بيترفض في الموديل (مش بإخفاء التبويب)');

    Database::exec("INSERT INTO reps (name, status) VALUES ('__v47_denied','active')");
    $denied = Database::lastId();
    if ($ready) { Database::exec("UPDATE reps SET can_watch_reps = 0 WHERE id = ?", [$denied]); }

    is('canWatchReps = false', Rep::canWatchReps($denied), false);
    is('والواجهة بتسأل نفس المصدر', RepPortal::canWatchReps($denied), false);

    try {
        RepWatch::team($denied);
        bad('✗✗ مندوب ممنوع شاف قائمة المناديب — الحارس مكسور!');
    } catch (\RuntimeException $e) {
        ok('RepWatch::team اترفضت برسالة عربية واضحة');
        is('والرسالة بتقوله يكلّم المكتب', str_contains($e->getMessage(), 'صلاحية'), true);
    }
    try {
        RepWatch::rep($denied, $denied);
        bad('✗✗ مندوب ممنوع فتح تفصيل مندوب — الحارس مكسور!');
    } catch (\RuntimeException $e) { ok('وRepWatch::rep اترفضت كمان'); }

    // ═══════════════════════════════════════════ 2) المسموح بيشوف
    sec('2) المشرف بيشوف — والأرقام مطابقة للمكتب');

    Database::exec("INSERT INTO reps (name, status) VALUES ('__v47_watcher','active')");
    $watcher = Database::lastId();

    if (!$ready) {
        skip('العمود مش موجود — اتخطّى فحوصات المنح');
    } elseif (!RepCustody::available()) {
        skip('عهدة المناديب مش مترحّلة (migrate_v35) — اتخطّى');
    } else {
        Rep::setCanWatchReps($watcher, true);
        is('canWatchReps = true بعد المنح', Rep::canWatchReps($watcher), true);

        $team = RepWatch::team($watcher);
        ok('قائمة المناديب رجعت — ' . count($team['reps']) . ' مندوب عليه حركة');

        // ⚠️ أهم فحص رقمي: مفيش تعريف تاني للرصيد
        $mismatch = 0;
        foreach ($team['reps'] as $r) {
            if (abs((float) $r['balance'] - RepCustody::balance((int) $r['id'])) > 0.005) { $mismatch++; }
        }
        is('رصيد كل مندوب = RepCustody::balance() بالمليم', $mismatch, 0);

        $sum = round(array_sum(array_column($team['reps'], 'balance')), 2);
        is('وإجمالي الشاشة = مجموع أرصدتهم', (float) $team['totals']['balance'], $sum);

        // تفصيل مندوب حقيقي (لو فيه)
        $target = $team['reps'][0]['id'] ?? null;
        if ($target) {
            $d = RepWatch::rep($watcher, (int) $target);
            is('تفصيل المندوب رجع', $d !== null, true);
            is('ورصيده في التفصيل = balance()', (float) $d['b']['balance'], RepCustody::balance((int) $target));
            is('و«الفاضل معاه» = الرصيد − المستنّي',
                (float) $d['pocket'],
                round(RepCustody::balance((int) $target) - RepPortal::pendingAmount((int) $target), 2));
            is('وحركات العهدة نفس اللي بيقراها المكتب',
                count($d['spending']) <= count(RepCustody::spending((int) $target)), true);

            // ═══ 3) مفيش سعر عميل بيتسرّب
            sec('3) ⚠️ مفيش أي سعر بيخصّ العميل على شاشة المندوب (قاعدة v35)');
            $leak = [];
            foreach ($d['submissions'] as $row) {
                foreach (['actual_unit', 'actual_amount'] as $col) {
                    if (array_key_exists($col, $row)) { $leak[] = $col; }
                }
            }
            foreach ($d['spending'] as $row) {
                foreach (['actual_unit', 'actual_amount'] as $col) {
                    if (array_key_exists($col, $row)) { $leak[] = $col; }
                }
            }
            is('مفيش actual_unit/actual_amount في أي صف', implode(',', array_unique($leak)), '');
        } else {
            skip('مفيش مندوب عليه حركة للتفصيل');
        }

        // ═══ 4) السحب بيشتغل فورًا
        sec('4) سحب الصلاحية بيشتغل فورًا');
        Rep::setCanWatchReps($watcher, false);
        is('canWatchReps رجعت false', Rep::canWatchReps($watcher), false);
        try {
            RepWatch::team($watcher);
            bad('✗✗ لسه بيشوف بعد سحب الصلاحية!');
        } catch (\RuntimeException $e) { ok('والمتابعة اترفضت بعد السحب'); }
    }

    // ═══════════════════════════════════════════ 5) قراءة فقط
    sec('5) ⚠️ الشاشة قراءة فقط — مفيش أي أثر');
    is('رصيد الخزنة ما اتغيّرش', Closing::currentBalance(), $treasuryBefore);
    is('وعدد البنود في صندوق الانتظار ما اتغيّرش',
        (int) Database::scalar("SELECT COUNT(*) FROM rep_submissions"), $subsBefore);

    // الموديل ما بيعملش أي INSERT/UPDATE/DELETE أصلاً — نتأكد من الملف نفسه
    $src = (string) @file_get_contents(\App\Core\App::basePath('app/Models/RepWatch.php'));
    $writes = preg_match('/\b(INSERT|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $src);
    is('ملف RepWatch مافيهوش أي كتابة على القاعدة', (int) $writes, 0);
    is('وما بيقراش actual_unit/actual_amount',
        (int) preg_match('/actual_(unit|amount)\s*[,\s]/i', preg_replace('/^\s*\*.*$/m', '', $src)), 0);

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر في قاعدة البيانات.\n";

    if (Rep::watchPermReady()) {
        $live = Database::all(
            "SELECT r.name, COALESCE(ru.username,'—') u FROM reps r
             LEFT JOIN rep_users ru ON ru.rep_id = r.id
             WHERE r.can_watch_reps = 1 ORDER BY r.name");
        echo "\nالمسموح لهم يتابعوا المناديب دلوقتي (" . count($live) . "):\n";
        foreach ($live as $x) { echo "  • {$x['name']} ({$x['u']})\n"; }
        if (!$live) { echo "  (مفيش حد)\n"; }
    }

    echo "\nنجح: $pass | فشل: $fail | تخطّي: $skip\n";
    exit($fail > 0 ? 1 : 0);
}
