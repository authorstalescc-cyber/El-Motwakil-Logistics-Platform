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

        // ═══ 3ب) ⚠️ «باقي العهدة» = عهدة اليوم بعد المستنّي — مش تراكمي
        sec('3ب) ⚠️ الرقم الكبير = عهدة اليوم بعد المستنّي (بلا ترحيل ولا انتظار اعتماد)');

        $lockedThrough = Closing::lastClosedDate();
        $wDay = date('Y-m-d');
        for ($i = 0; $i < 400 && $lockedThrough !== null && $wDay <= $lockedThrough; $i++) {
            $wDay = date('Y-m-d', strtotime("+$i day"));
        }
        $wPrev = date('Y-m-d', strtotime($wDay . ' -1 day'));

        $tag = '__v47_' . bin2hex(random_bytes(3));
        Database::exec("INSERT INTO reps(name,status) VALUES(?,'active')", [$tag]);
        $subject = Database::lastId();
        Database::exec(
            "INSERT INTO permits(permit_no,permit_date,status,company,client) VALUES(?,?,'open',?,?)",
            [$tag, $wDay, $tag . ' شركة', $tag . ' وكيل']);
        $wPermit = Database::lastId();
        Database::exec("INSERT INTO permit_movements(permit_id,seq) VALUES(?,1)", [$wPermit]);
        $wMov = Database::lastId();

        // إمبارح: نزل 5000 وصرف 3000  →  فاضل 2000 **ما بيترحّلش**
        // النهاردة: نزل 2000 وصرف معتمد 500 ودفع 300 لسه مستني اعتماد
        //           →  «فاضل معاه» = 2000 − 500 − 300 = 1200
        Database::exec("INSERT INTO rep_custody_deposits(rep_id,dep_date,amount) VALUES(?,?,?)",
            [$subject, $wPrev, 5000]);
        Database::exec("INSERT INTO rep_custody_deposits(rep_id,dep_date,amount) VALUES(?,?,?)",
            [$subject, $wDay, 2000]);
        $wStage = static function (string $d, float $amt) use ($wPermit, $wMov, $subject, $tag): void {
            Database::exec(
                "INSERT INTO permit_stages
                    (permit_id, movement_id, stage_key, direction, note, qty, amount, entry_date,
                     is_visa, paid_by_rep_id)
                 VALUES (?,?,'receipts','expense',?,1,?,?,0,?)",
                [$wPermit, $wMov, $tag . ' إيصال', $amt, $d, $subject]);
        };
        $wStage($wPrev, 3000);
        $wStage($wDay, 500);
        Database::exec(
            "INSERT INTO rep_submissions
                (rep_id, permit_id, movement_id, kind, note, qty, unit_amount, amount,
                 entry_date, status, is_visa)
             VALUES (?,?,?,'receipt',?,1,300,300,?, 'pending',0)",
            [$subject, $wPermit, $wMov, $tag . ' مستني', $wDay]);

        // ⚠️ إعادة ضبط أساس فحص «قراءة فقط» (قسم 5) **بعد** ما الفاحص نفسه زرع
        //    fixtures بتخصم من الخزنة (البند المعتمد بيتخصم يوم صرفه — قاعدة v42)
        //    وبتضيف بندًا مستنيًّا. المقصود من القسم إن **RepWatch** مالوش أثر،
        //    مش إن الفاحص ما كتبش — والكتابة كلها جوّه transaction وبترجع.
        $treasuryBefore = Closing::currentBalance();
        $subsBefore = (int) Database::scalar("SELECT COUNT(*) FROM rep_submissions");

        Rep::setCanWatchReps($watcher, true);
        $_SESSION['rep_work_date'] = $wDay;
        $team2 = RepWatch::team($watcher);
        $row = null;
        foreach ($team2['reps'] as $r) { if ((int) $r['id'] === $subject) { $row = $r; } }

        if ($row === null) {
            bad('المندوب المزروع مش ظاهر في شاشة المتابعة');
        } else {
            is('عهدة اليوم النازلة = 2,000 (عهدة إمبارح ما اترحّلتش)', (float) $row['day_deposits'], 2000.0);
            is('★ «فاضل من عهدة اليوم» = 1,200 (2000 − 500 معتمد − 300 مستني)',
                (float) $row['day_pocket'], 1200.0);
            is('والمستنّي بيتخصم فعلاً (فرقه عن الرصيد المحاسبي 300)',
                round((float) $row['day_balance'] - (float) $row['day_pocket'], 2), 300.0);
            // ⚠️ الحارس الأهم: الرقم المعروض **مش** التراكمي (5000−3000+2000−500 = 3500)
            is('★★ والرقم مش التراكمي (3,500) — عهدة كل يوم مستقلة',
                abs((float) $row['day_pocket'] - 3500) > 0.005, true);
            is('التراكمي لسه موجود في الحزمة كحقيقة مالية بتسمية منفصلة',
                (float) $row['balance'], RepCustody::balance($subject));
        }

        $dSub = RepWatch::rep($watcher, $subject);
        if ($dSub === null) {
            bad('تفصيل المندوب المزروع رجع null');
        } else {
            is('★ تفصيل المندوب: «الفاضل معاه» = 1,200',
                (float) $dSub['day_pocket']['pocket'], 1200.0);
            is('و`pocket` التراكمي فضل زي ما هو (مستخدم في التسوية)',
                (float) $dSub['pocket'],
                round(RepCustody::balance($subject) - RepPortal::pendingAmount($subject), 2));
        }

        // ═══ 3ج) الشاشتان بترندروا فعليًّا وبيعرضوا الرقم الصح
        sec('3ج) رندر فعلي لشاشتي المشرف');
        $render = static function (array $vars, string $view): string {
            ob_start();
            try {
                extract($vars, EXTR_SKIP);
                require \App\Core\App::basePath('app/Views/' . $view . '.php');
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                bad('استثناء وقت رندر ' . $view . ': ' . $e->getMessage());
                return '';
            }
        };
        $noPhpError = static function (string $h, string $where): void {
            preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key|Undefined index/', $h, $m)
                ? bad("خطأ PHP في $where: " . ($m[0] ?? ''))
                : ok("$where بيرندر بلا أخطاء PHP");
        };
        $_SESSION['rep_id'] = $watcher;
        $_SESSION['rep_name'] = '__v47_watcher';
        $htmlTeam = $render(['reps' => $team2['reps'], 'totals' => $team2['totals']], 'm/team');
        if ($htmlTeam === '') {
            bad('/m/team ما رندرش');
        } else {
            $noPhpError($htmlTeam, '/m/team');
            is('الشاشة بتقول «فاضل من عهدة اليوم» (مش رصيد تراكمي)',
                str_contains($htmlTeam, 'فاضل من عهدة اليوم')
                || str_contains($htmlTeam, 'الفاضل من عهدة'), true);
            // ⚠️ قرار المالك 2026-07-26: **ولا رقم تراكمي على الشاشة** — كان فيها
            //    سطر «برّه معاه (كل الأيام)» و«مستني كله» فبقت مشتِّتة.
            is('★ مفيش أي رقم «كل الأيام» على شاشة المتابعة',
                str_contains($htmlTeam, 'كل الأيام'), false);
            is('★ ولا «مستني كله» (عدّاد تراكمي)',
                str_contains($htmlTeam, 'مستني كله'), false);
            is('وفيه شريط يوضّح اليوم وطريق لتغييره',
                str_contains($htmlTeam, 'بتشوف بيانات يوم') && str_contains($htmlTeam, 'm/work-date'), true);
        }
        if ($dSub !== null) {
            $htmlRep = $render(['d' => $dSub, 'tab' => 'spend'], 'm/team_rep');
            if ($htmlRep === '') { bad('/m/team/{rid} ما رندرش'); } else {
                $noPhpError($htmlRep, '/m/team/{rid}');
                is('★ تفصيل المندوب مفيهوش أي رقم تراكمي',
                    str_contains($htmlRep, 'كل الأيام') || str_contains($htmlRep, 'صرف كله')
                    || str_contains($htmlRep, 'نزل له كله'), false);
                is('وفيه شريط اليوم وطريق لتغييره',
                    str_contains($htmlRep, 'بتشوف بيانات يوم') && str_contains($htmlRep, 'm/work-date'), true);
            }
        }

        // ═══ 3د) ★★ الحارس الأهم: ولا بند من يوم تاني بيتسرّب للشاشة
        sec('3د) ★★ الشاشة يوم واحد بس — ولا بند من يوم تاني');
        // بند إمبارح بعلامة مميزة: لازم **ما يظهرش** في أي تبويب من تبويبات اليوم
        $mark = $tag . '_بند_يوم_قديم';
        Database::exec(
            "INSERT INTO rep_submissions
                (rep_id, permit_id, movement_id, kind, note, qty, unit_amount, amount,
                 entry_date, status, is_visa)
             VALUES (?,?,?,'receipt',?,1,777,777,?, 'approved',0)",
            [$subject, $wPermit, $wMov, $mark, $wPrev]);
        Database::exec("INSERT INTO rep_custody_deposits(rep_id,dep_date,amount,note) VALUES(?,?,?,?)",
            [$subject, $wPrev, 4321, $mark . '_تنزيل']);

        // إعادة ضبط أساس فحص «قراءة فقط» بعد آخر كتابة يعملها الفاحص نفسه
        // (كل ده جوّه transaction وبيترجع في الآخر).
        $treasuryBefore = Closing::currentBalance();
        $subsBefore = (int) Database::scalar("SELECT COUNT(*) FROM rep_submissions");

        $dSub2 = RepWatch::rep($watcher, $subject);
        is('spending فيه بنود اليوم بس', count($dSub2['spending']), 1);
        $leak = false;
        foreach ($dSub2['spending'] as $row) { if ((string) $row['d'] !== $wDay) { $leak = true; } }
        is('ولا حركة بتاريخ تاني في كشف الصرف', $leak, false);
        $subLeak = false;
        foreach ($dSub2['submissions'] as $row) { if ((string) $row['entry_date'] !== $wDay) { $subLeak = true; } }
        is('★ ولا إدخال من يوم تاني في «إدخالاته»', $subLeak, false);
        $depLeak = false;
        foreach ($dSub2['deposits'] as $row) { if ((string) $row['dep_date'] !== $wDay) { $depLeak = true; } }
        is('★ ولا تنزيل عهدة من يوم تاني', $depLeak, false);

        foreach (['spend', 'entries', 'in'] as $t) {
            $h = $render(['d' => $dSub2, 'tab' => $t], 'm/team_rep');
            // ⚠️ `{$t}` إلزامية: بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة v38)
            is("تبويب «{$t}» مفيهوش بند اليوم القديم", $h !== '' && str_contains($h, $mark), false);
        }
        // والدليل المضاد: بند النهاردة **موجود** فعلاً (عشان الفحص ما ينجحش على صفحة فاضية)
        $hToday = $render(['d' => $dSub2, 'tab' => 'entries'], 'm/team_rep');
        is('وبند النهاردة ظاهر فعلاً (الفحص مش بينجح على صفحة فاضية)',
            str_contains($hToday, $tag . ' مستني'), true);

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
