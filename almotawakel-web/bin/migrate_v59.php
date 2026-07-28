<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v59 — سجل مرجعي للجهات (المرحلة ٤ من خطة جودة المدخلات).
 *
 * الفكرة: الجهات **لازم تفضل مفتوحة** (جهة جديدة بتظهر كل أسبوع) فما ينفعش
 * نقفلها زي الموانئ. البديل: سجل مرجعي فيه **الاسم المعتمد + أسماء بديلة**،
 * وكل حفظة بتمرّ عليه — فـ«ط التحدى» بتتحوّل لـ«طريق التحدي» تلقائيًّا **للأبد**
 * بدل ما المالك يدمجها بإيده كل شوية.
 *
 * ⚠️ **بنعيد استخدام `destination_points` (v33/v34) مش جدول جديد** — هو أصلاً
 *    سجل جهات بأسماء بديلة (٤٧ وجهة) وله شاشة إدارة `/tracking/places`.
 *    جدول موازٍ كان هيدّي **سجلّين للجهات** يفترقوا مع أول تعديل.
 *
 * اللي بيتغيّر:
 *   ١) `lat`/`lng` بقوا **NULL-able** — الوجهة تتسجّل بالاسم من غير إحداثيات.
 *      كانت NOT NULL لأن الجدول اتعمل للخريطة، وده كان بيمنع تسجيل أي جهة
 *      المالك ما حدّدش مكانها. **المستهلك الوحيد للإحداثيات `Track::eta()`**
 *      وفيه حارس بيتخطّى الوجهة بلا إحداثيات (تقدير المسافة بيختفي وبس).
 *   ٢) تسجيل كل جهة مستخدَمة فعلاً ومش في السجل (بالاسم الأكتر استخدامًا).
 *   ٣) `bawalis.destination_id` + `permits.destination_id` — **وسم استرشادي**
 *      على نمط `visa_card_id` (v22): بيتعبّى ويتحدَّث، **ولا يمسّ أي حساب ولا
 *      تقرير قائم** (كلهم لسه بيقروا النص). فايدته إن إعادة تسمية جهة في
 *      المستقبل تقدر تتتبّع سجلاتها بالرقم بدل ما تدوّر بالنص.
 *
 * ⚠️ **مفيش أي أثر مالي** — كله أعمدة وصفية، والترحيل بيتحقق إن رصيد الخزنة
 *    ما اتغيّرش وبيلغي كل حاجة لو اتغيّر.
 *
 * التشغيل:  php bin/migrate_v59.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\Maintenance;
use App\Models\Track;

$v59Dry    = in_array('--dry-run', $argv ?? [], true);
$v59Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v59Say = static function (string $m) use ($v59Direct): void { if ($v59Direct) { echo $m . "\n"; } };

$v59HasCol = static function (string $t, string $c): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]
    );
};

$v59Up = static function () use ($v59Dry, $v59Say, $v59HasCol): void {
    $balanceBefore = (float) Custody::balance();

    /* ═══════════════════════════════════════════════════════════════════════
       ⚠️⚠️ **DDL أولاً وبرّه أي transaction — مصيدة MySQL اتصادفت فعليًا.**
       `ALTER TABLE` في MySQL بيعمل **commit ضمني** ومستحيل يترجع، فأول نسخة
       كانت بتفتح transaction ثم تعمل ALTER ثم تحاول `rollBack()` فتموت بـ
       «There is no active transaction». يعني نمط «افتح ← عدّل ← قيس الرصيد ←
       ارجع» **مش صالح للترحيل اللي فيه DDL**.
       الحل: الـDDL هنا **إضافي وidempotent بحت** (عمود nullable جديد + تخفيف
       NOT NULL) — مفيش فيه فقد بيانات ممكن نحتاج نرجع منه، والبيانات لوحدها
       هي اللي جوّه transaction تحت.
       ═══════════════════════════════════════════════════════════════════════ */
    if ($v59Dry) {
        $v59Say('  (تجربة) تعديلات البنية اتخطّت — DDL مش بيترجع في MySQL');
    } else {
        $nullable = (string) Database::scalar(
            "SELECT is_nullable FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'destination_points' AND column_name = 'lat'"
        );
        if ($nullable === 'NO') {
            Database::exec('ALTER TABLE destination_points MODIFY lat DECIMAL(10,7) NULL');
            Database::exec('ALTER TABLE destination_points MODIFY lng DECIMAL(10,7) NULL');
            $v59Say('  + الإحداثيات بقت اختيارية (الوجهة تتسجّل بالاسم بس)');
        } else {
            $v59Say('  = الإحداثيات اختيارية بالفعل');
        }
        foreach ([['bawalis', 'destination_id'], ['permits', 'destination_id']] as [$t, $c]) {
            if (!$v59HasCol($t, $c)) {
                Database::exec("ALTER TABLE `$t` ADD COLUMN `$c` INT UNSIGNED NULL");
                Database::exec("ALTER TABLE `$t` ADD INDEX `idx_{$t}_dest_id` (`$c`)");
                $v59Say("  + {$t}.{$c}");
            } else {
                $v59Say("  = {$t}.{$c} (موجود)");
            }
        }
    }

    // ── البيانات: دي اللي بتترجع لو حصل أي مشكلة ────────────────────────────
    Database::conn()->beginTransaction();
    try {
        // ١) تسجيل الجهات المستخدَمة
        $used = [];
        foreach ([['bawalis', 'destination'], ['permits', 'destination']] as [$t, $c]) {
            foreach (Database::all(
                "SELECT `$c` v, COUNT(*) n FROM `$t`
                  WHERE `$c` IS NOT NULL AND TRIM(`$c`) <> '' GROUP BY `$c`"
            ) as $r) {
                $k = Maintenance::normkey((string) $r['v']);
                if ($k === '') { continue; }
                $used[$k][(string) $r['v']] = ($used[$k][(string) $r['v']] ?? 0) + (int) $r['n'];
            }
        }
        uasort($used, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

        /* ⚠️⚠️ **لازم نفحص الأسماء البديلة كمان مش المفتاح بس — باج اتصادف فعليًا.**
           أول نسخة فحصت `normkey` وحده، فسجّلت «العاشر» نقطة مستقلة وهي أصلاً
           **اسم بديل** لـ«العاشر من رمضان»، وكذلك «اكتوبر» (بديل «6 أكتوبر»)
           و«السادات» (بديل «مدينة السادات») — يعني الترحيل نفسه كان بيلوّث السجل
           اللي المفروض ينضّف بيه.
           بنبني مجموعة المفاتيح **والبدائل** مرة واحدة، وبنحدّثها مع كل إضافة. */
        $taken = [];
        foreach (Database::all('SELECT normkey, aliases FROM destination_points') as $p) {
            $taken[(string) $p['normkey']] = true;
            foreach (explode('|', (string) $p['aliases']) as $a) {
                $a = trim($a);
                if ($a !== '') { $taken[$a] = true; }
            }
        }

        $added = 0; $known = 0;
        foreach ($used as $k => $spellings) {
            arsort($spellings);
            $best = (string) array_key_first($spellings);
            if (isset($taken[$k])) { $known++; continue; }
            /* ⚠️⚠️ **التطابق الجزئي بيمنع التسجيل كمان — ارتداد اتصادف فعليًا.**
               «مدينة بدر المنطقة 6» بتتحلّ جزئيًّا لـ«مدينة بدر» في وحدة التتبع.
               لو سجّلناها نقطة مستقلة بتبقى تطابقًا **تامًّا** فتقصر مسار الحلّ
               الجزئي وتكسر تقدير المسافة (`verify_v34` مسك ده)، وكمان بتزحم
               شاشة الخريطة بمئات المتغيّرات بدل الوجهات الحقيقية.
               ⚠️ الإملائي (fuzzy) **مش** مانع — «البدرشين» و«بدرشين» متقاربين
               إملائيًّا وممكن يكونوا مكانين مختلفين، فالتسجيل أأمن من الدمج. */
            try {
                $hit = Track::resolveDestination($best);
                if ($hit && in_array($hit['match'] ?? '', ['exact', 'alias', 'partial'], true)) {
                    $known++; continue;
                }
            } catch (\Throwable $e) { /* السجل مش متاح — نكمّل بالتسجيل */ }
            if (!$v59Dry) {
                Database::exec(
                    'INSERT INTO destination_points (name, normkey, lat, lng, note) VALUES (?, ?, NULL, NULL, ?)',
                    [$best, $k, 'اتسجّلت تلقائيًا من البوالص']
                );
            }
            $taken[$k] = true;
            $added++;
        }
        Track::clearPointsCache();
        $v59Say("  + اتسجّل {$added} جهة جديدة في السجل ({$known} كانت معروفة)");

        /* ٢) تعبئة الوسم الاسترشادي — في PHP مش SQL لأن `normkey` دالة PHP
              (بتوحّد أ/إ/ة/ى) ومفيش مقابل ليها في MySQL. */
        $tagged = 0;
        if (!$v59Dry && $v59HasCol('bawalis', 'destination_id')) {
            // ⚠️ الخريطة بتشمل **الأسماء البديلة** كمان — من غير كده الصفوف
            //    المكتوبة بالاسم البديل («العاشر») بتفضل بلا وسم.
            $points = [];
            foreach (Database::all('SELECT id, normkey, aliases FROM destination_points') as $p) {
                $points[(string) $p['normkey']] = (int) $p['id'];
                foreach (explode('|', (string) $p['aliases']) as $a) {
                    $a = trim($a);
                    if ($a !== '' && !isset($points[$a])) { $points[$a] = (int) $p['id']; }
                }
            }
            foreach ([['bawalis', 'destination'], ['permits', 'destination']] as [$t, $c]) {
                foreach (Database::all(
                    "SELECT DISTINCT `$c` v FROM `$t` WHERE `$c` IS NOT NULL AND TRIM(`$c`) <> ''"
                ) as $r) {
                    $id = $points[Maintenance::normkey((string) $r['v'])] ?? null;
                    if ($id === null) { continue; }
                    $tagged += Database::exec(
                        "UPDATE `$t` SET destination_id = ?
                          WHERE `$c` = ? AND (destination_id IS NULL OR destination_id <> ?)",
                        [$id, (string) $r['v'], $id]
                    );
                }
            }
        }
        $v59Say("  + اتوسم {$tagged} سجل برقم الجهة");

        $balanceAfter = (float) Custody::balance();
        if (abs($balanceAfter - $balanceBefore) > 0.005) {
            throw new RuntimeException('رصيد الخزنة اتغيّر — الترحيل اتلغى.');
        }

        if ($v59Dry) {
            Database::conn()->rollBack();
            $v59Say("\n(تجربة) بيانات السجل اترجعت زي ما كانت.");
        } else {
            Database::conn()->commit();
        }
        $v59Say('رصيد الخزنة ثابت: ' . number_format($balanceBefore, 2));
    } catch (\Throwable $e) {
        if (Database::conn()->inTransaction()) { Database::conn()->rollBack(); }
        throw $e;
    }
};

/** كل جهة مستخدَمة لازم تبقى في السجل بعد التطبيق. */
$v59Verify = static function () use ($v59Dry, $v59Say, $v59HasCol): bool {
    if ($v59Dry) { return true; }

    if (!$v59HasCol('bawalis', 'destination_id') || !$v59HasCol('permits', 'destination_id')) {
        $v59Say('✗ عمود الوسم مش موجود'); return false;
    }
    // ⚠️ الاسم البديل يُحتسب «معروف» — «العاشر» بديل «العاشر من رمضان» ومش
    //    محتاج نقطة مستقلة (الفحص الأول من غير الشرط ده فشل بإنذار كاذب).
    $known = [];
    foreach (Database::all('SELECT normkey, aliases FROM destination_points') as $p) {
        $known[(string) $p['normkey']] = true;
        foreach (explode('|', (string) $p['aliases']) as $a) {
            $a = trim($a);
            if ($a !== '') { $known[$a] = true; }
        }
    }

    /* ⚠️ نفس قاعدة التسجيل بالظبط: «معروفة» = مفتاح أو بديل **أو تطابق جزئي**.
       لو الفحص قبل الجزئي بس، بيفشل على «مدينة بدر المنطقة 6» اللي احنا
       **متعمّدين** ما نسجّلهاش عشان ما نكسرش الحل الجزئي في التتبع. */
    $missing = [];
    foreach ([['bawalis', 'destination'], ['permits', 'destination']] as [$t, $c]) {
        foreach (Database::all(
            "SELECT DISTINCT `$c` v FROM `$t` WHERE `$c` IS NOT NULL AND TRIM(`$c`) <> ''"
        ) as $r) {
            $k = Maintenance::normkey((string) $r['v']);
            if ($k === '' || isset($known[$k])) { continue; }
            $hit = Track::resolveDestination((string) $r['v']);
            if ($hit && in_array($hit['match'] ?? '', ['exact', 'alias', 'partial'], true)) { continue; }
            $missing[] = (string) $r['v'];
        }
    }
    if ($missing !== []) {
        $v59Say('✗ جهات مستخدَمة ومش في السجل: ' . implode(' · ', array_slice($missing, 0, 5)));
        return false;
    }
    $v59Say('  ✓ كل جهة مستخدَمة موجودة في السجل.');
    return true;
};

if ($v59Direct) {
    echo 'v59 — سجل مرجعي للجهات' . ($v59Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v59Up();
    if ($v59Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v59.\n"); exit(1); }
    echo ($v59Dry ? "\nشغّل من غير --dry-run عشان يتطبّق.\n" : "\n✓ تمّ.\n");
}

return ['up' => $v59Up, 'verify' => $v59Verify];
