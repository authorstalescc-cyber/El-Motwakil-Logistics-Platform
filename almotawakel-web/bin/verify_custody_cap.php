<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «سقف عهدة البوليصة» — تعديل البوليصة لازم يفضل حرًّا.
 *
 * الباج اللي الفحص ده اتعمل عشانه (بلاغ المالك 2026-07-27):
 *   «عند تعديل تاريخ أي بوليصة بيقولي لا توجد عهدة تكفي».
 *
 * السبب: القاعدة القديمة كانت بتقارن **عهدة البوليصة كلها** بالرصيد المتاح في
 * كل حفظة — حتى لو التعديل ما لمسش المبلغ خالص. ولأن Closing::currentBalance
 * بيبني على آخر إقفال، خصم أي بوليصة تاريخها **قبل** آخر إقفال بيبقى متجمّدًا
 * جوّه اللقطة، فـ$excludeBill مش بيقدر يرجّعه للرصيد → الرصيد المتاح بيطلع أقل
 * من عهدة البوليصة نفسها والحفظ يترفض بلا سبب حقيقي.
 *
 * القاعدة الجديدة (المصدر الوحيد Custody::capError):
 *   • تعديل  → الفحص على **الفرق** بين الخصم الجديد والقديم فقط.
 *              فرق ≤ 0 (تاريخ/سائق/أي حقل بلا زيادة) = مفيش فحص خالص.
 *   • إنشاء  → الخصم كله جديد فيتقارن بالرصيد المتاح (السلوك القديم).
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_custody_cap.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\Custody;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function has(string $l, string $file, string $needle): void {
    $p = dirname(__DIR__) . '/' . $file;
    $src = is_file($p) ? (string) file_get_contents($p) : '';
    str_contains($src, $needle) ? ok($l) : bad("{$l} — «{$needle}» مش موجودة في {$file}");
}
function hasnt(string $l, string $file, string $needle): void {
    $p = dirname(__DIR__) . '/' . $file;
    $src = is_file($p) ? (string) file_get_contents($p) : '';
    !str_contains($src, $needle) ? ok($l) : bad("{$l} — «{$needle}» لسه موجودة في {$file}");
}

echo "فحص سقف عهدة البوليصة (حرية تعديل البوليصة)\n" . str_repeat('=', 62) . "\n";

$balanceBefore = Closing::currentBalance();

// ══════════════════════════════════════════════════ ١) البنية والمصدر الوحيد
sec('١) المصدر الوحيد للفحص');

method_exists(Custody::class, 'capError')
    ? ok('Custody::capError موجودة') : bad('Custody::capError ناقصة');

has('BawalisController بينادي capError', 'app/Controllers/BawalisController.php',
    'Custody::capError(');
has('PermitController::updateBill بينادي capError', 'app/Controllers/PermitController.php',
    'Custody::capError(');

// ⚠️ ممنوع أي كنترولر يكتب شرط السقف بإيده — وإلا يفترق عن القاعدة مع أول تعديل.
hasnt('BawalisController مش بيقارن بالرصيد بإيده', 'app/Controllers/BawalisController.php',
    'availableForBill');
hasnt('PermitController مش بيقارن بالرصيد بإيده', 'app/Controllers/PermitController.php',
    'availableForBill');

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    // ══════════════════════════════════════════════ ٢) بيئة الاختبار
    sec('٢) بيئة الاختبار — بوليصة بعهدة أكبر من الرصيد الحالي');

    $day = date('Y-m-d');

    /**
     * بنثبّت رصيد الخزنة على رقم معلوم قبل كل قياس.
     * ⚠️ لازم يشتغل مع الرصيد **السالب** كمان — رصيد الإنتاج ساعة كتابة الفحص
     *    كان −59,080، وأول نسخة افترضت إنه موجب فطلعت أرقامًا غلط على السيرفر.
     */
    $setBalance = static function (float $target) use ($day): void {
        $diff = round($target - Closing::currentBalance(), 2);
        if (abs($diff) <= 0.005) { return; }
        if ($diff > 0) {
            Database::exec(
                "INSERT INTO manual_custody (cust_date, driver, amount, note)
                 VALUES (?, '__cap_seed', ?, '__cap_test')", [$day, $diff]);
        } else {
            Database::exec(
                "INSERT INTO manual_expenses (exp_date, category, amount, is_visa)
                 VALUES (?, '__cap_drain', ?, 0)", [$day, -$diff]);
        }
    };

    $seedBalance = Closing::currentBalance();

    Database::exec(
        "INSERT INTO bawalis (bill_number, bill_date, driver, car_no, custody, is_legacy)
         VALUES ('__CAP_TEST__', ?, '__cap_driver', '__cap 9999', 5000, 0)", [$day]);
    $billId = Database::lastId();

    near('خصم البوليصة من الخزنة = العهدة − 100', $seedBalance - Closing::currentBalance(), 4900);

    // أسوأ حالة: بوليصة عهدتها 5000 وخزنة فاضية تمامًا — نفس اللي بيشتكي منه المالك.
    $setBalance(0.0);
    near('الرصيد الحالي بقى صفر (أسوأ حالة)', Closing::currentBalance(), 0);

    // ══════════════════════════════════════════════ ٣) سلوك التعديل (قلب الإصلاح)
    sec('٣) التعديل — بلاغ المالك');

    $r = Custody::capError(5000, '__cap 9999', $billId);
    $r === null
        ? ok('تعديل بلا تغيير في المبلغ (تاريخ/سائق) مسموح رغم إن الرصيد صفر')
        : bad('لسه بيرفض تعديل بلا تغيير في المبلغ: ' . $r);

    $r = Custody::capError(3000, '__cap 9999', $billId);
    $r === null
        ? ok('تقليل العهدة مسموح دايمًا') : bad('تقليل العهدة اترفض: ' . $r);

    $r = Custody::capError(5001, '__cap 9999', $billId);
    $r !== null
        ? ok('زيادة العهدة فوق الرصيد لسه بترفض (الحماية شغّالة)')
        : bad('زيادة العهدة فوق الرصيد عدّت — الحماية اتكسرت');
    if ($r !== null) {
        str_contains($r, 'الزيادة')
            ? ok('والرسالة بتقول إنها «الزيادة» مش العهدة كلها')
            : bad('الرسالة لسه بتتكلم عن العهدة كلها: ' . $r);
    }

    // ══════════════════════════════════════════════ ٤) الزيادة المسموحة
    sec('٤) الزيادة اللي الخزنة تستحملها');

    $setBalance(700.0);
    near('نزّلنا 700 للخزنة', Closing::currentBalance(), 700);

    $r = Custody::capError(5700, '__cap 9999', $billId);
    $r === null ? ok('زيادة 700 (= الرصيد بالظبط) مسموحة') : bad('زيادة في حدود الرصيد اترفضت: ' . $r);

    $r = Custody::capError(5701, '__cap 9999', $billId);
    $r !== null ? ok('وزيادة 701 (أعلى بجنيه) بترفض — الحد دقيق')
                : bad('الحد مش دقيق — 701 عدّت والرصيد 700');

    // ══════════════════════════════════════════════ ٥) البوليصة الجديدة
    sec('٥) الإنشاء — السلوك القديم زي ما هو');

    $r = Custody::capError(700, '__cap 9999', null);
    $r === null ? ok('بوليصة جديدة بعهدة في حدود الرصيد مسموحة') : bad('اترفضت بلا سبب: ' . $r);

    $r = Custody::capError(99999, '__cap 9999', null);
    $r !== null ? ok('وبوليصة جديدة بعهدة أكبر من الرصيد بترفض')
                : bad('بوليصة جديدة فوق الرصيد عدّت — الحماية اتكسرت');
    if ($r !== null) {
        !str_contains($r, 'الزيادة')
            ? ok('ورسالة الإنشاء بتتكلم عن العهدة نفسها') : bad('رسالة الإنشاء اتخلطت برسالة التعديل');
    }

    // ══════════════════════════════════════════════ ٦) البوليصة القديمة (السبب الجذري)
    sec('٦) بوليصة تاريخها قبل آخر إقفال — السبب الجذري للبلاغ');

    $lastClosed = Closing::lastClosedDate();
    if ($lastClosed === null) {
        ok('مفيش أي يوم متقفل في القاعدة دي — الحالة دي بتتغطّى بفحص القسم ٣ (تخطّي)');
    } else {
        $old = Database::row(
            "SELECT id, custody, car_no FROM bawalis
             WHERE is_legacy = 0 AND custody > 0 AND bill_date <= ?
             ORDER BY custody DESC LIMIT 1", [$lastClosed]);
        if (!$old) {
            ok('مفيش بوليصة بعهدة قبل آخر إقفال — لا ينطبق (تخطّي)');
        } else {
            $cust = (float) $old['custody'];
            // القديم: availableForBill مش بيقدر يرجّع خصمًا متجمّدًا جوّه اللقطة
            $frozen = Custody::availableForBill((int) $old['id']);
            near('خصم البوليصة القديمة فعلاً متجمّد (المتاح = الرصيد الحالي)',
                 $frozen, Closing::currentBalance());
            $r = Custody::capError($cust, $old['car_no'] ?? null, (int) $old['id']);
            $r === null
                ? ok('تعديل البوليصة القديمة (#' . $old['id'] . ') بلا تغيير في المبلغ مسموح')
                : bad('لسه بيرفض تعديل بوليصة قديمة: ' . $r);
        }
    }

    // ══════════════════════════════════════════════ ٧) الأرشيف
    sec('٧) البوالص المُرحَّلة من الأرشيف خارج القاعدة');

    Database::exec(
        "INSERT INTO bawalis (bill_number, bill_date, driver, car_no, custody, is_legacy)
         VALUES ('__CAP_LEGACY__', ?, '__cap_driver', '__cap 9999', 999999, 1)", [$day]);
    $legacyId = Database::lastId();
    Custody::capError(999999, '__cap 9999', $legacyId) === null
        ? ok('بوليصة أرشيفية بعهدة ضخمة ما بتتفحصش (خارج كل الأرصدة)')
        : bad('الأرشيف اتفحص بالغلط');
    near('والأرشيف ما مسّش رصيد الخزنة', Closing::currentBalance(), 700);

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 62) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
    near('رصيد الخزنة قبل = بعد', Closing::currentBalance(), $balanceBefore);
    echo "\nنجح: $pass | فشل: $fail\n";
    exit($fail > 0 ? 1 : 0);
}
