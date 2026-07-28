<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v57 — بند «غير مدفوع»: إيصال/مصروف على الإذن بلا خصم من أي محفظة.
 *
 * **طلب المالك (2026-07-27):** «عاوز في صفحة المناديب إمكانية إدخال إيصال بدون
 * دفع مبلغ فعلي، لأنه تم محاسبته من خارج عهدتهم. يتم إضافته واعتماده من خلالي
 * زي باقي البنود، الفرق بس إنه يظهر لهم أوبشن يضغطوا «غير مدفوع» ويدخلوا قيمته
 * عادي، لكن بدون ما يتخصم من عهدتهم.»
 *
 * **قرار المالك المحسوم (سؤال مباشر 2026-07-27): البند ده ما يتخصمش من
 * خزنة المكتب كمان** — مفيش فلوس طلعت من الدرج بسببه خالص. وبيتسجّل في الإذن
 * وبيظهر في فاتورة العميل وسجل المصروفات عادي.
 *
 * **وبيخصّ بنود الأذونات بس (إيصال/مصروف) — مش النثريات** (قرار المالك):
 * النثرية دايمًا بتتدفع من عهدة المندوب فعلاً.
 *
 * عمودان TINYINT افتراضهم 0:
 *   • `permit_stages.unpaid`     — البند المعتمد على الإذن.
 *   • `rep_submissions.unpaid`   — البند وهو لسه في صندوق المراجعة.
 *
 * ⚠️ **الافتراضي 0 = كل السجلات القديمة تفضل «مدفوعة» بالحرف**، فالترحيل
 *    **مالوش أي أثر مالي** — وبيتحقّق من إن رصيد الخزنة وإجمالي عهد المناديب
 *    ما اتغيّروش.
 *
 * ⚠️ **الشرط الجديد بيتقرا من مكان واحد بس: `Permit::cashOutSql()`.** أي
 *    استعلام بيحسب كاش على `permit_stages` لازم يمرّ منها — والفاحص
 *    `bin/verify_unpaid_stages.php` بيفشل لو حد كتب `is_visa = 0` بإيده في
 *    كود الخزنة أو عهدة المناديب.
 *
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v57.php        (أو تلقائيًّا من bin/migration.php)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

$v57BalanceBefore   = Closing::currentBalance();
$v57RepsBefore      = RepCustody::available() ? RepCustody::totalOutstanding() : 0.0;
$v57Say = static function (string $line): void { echo $line . PHP_EOL; };

/** هل العمود موجود؟ */
$v57Has = static function (string $table, string $col): bool {
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$table, $col]);
};

$v57Up = static function () use ($v57Say, $v57Has): void {
    foreach (['permit_stages', 'rep_submissions'] as $table) {
        if ($v57Has($table, 'unpaid')) {
            $v57Say("  = {$table}.unpaid موجود بالفعل — اتخطّى.");
            continue;
        }
        Database::exec("ALTER TABLE {$table}
            ADD COLUMN unpaid TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'v57: اتحاسب من برّه العهدة والخزنة'");
        $v57Say("  + اتضاف {$table}.unpaid (افتراضي 0 = مدفوع زي ما هو).");
    }

    // فهرس بيخدم استعلامات الخزنة اليومية (entry_date + الشرطين مع بعض)
    $idx = (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'permit_stages'
           AND index_name = 'idx_stages_cash_day'");
    if (!$idx) {
        Database::exec("ALTER TABLE permit_stages
            ADD INDEX idx_stages_cash_day (entry_date, is_visa, unpaid)");
        $v57Say('  + اتضاف فهرس idx_stages_cash_day.');
    } else {
        $v57Say('  = الفهرس موجود بالفعل.');
    }

    // ⚠️ **إلزامي** — اقرا التعليق فوق `Permit::refreshUnpaidReady`: قراءة رصيد
    //    الخزنة فوق كاشت «العمود مش موجود»، ومن غير التصفير ده تحقّق ما بعد
    //    الترحيل بيفشل والإصدار ما بيتسجّلش رغم إن العمود اتضاف (اتصاد فعليًّا
    //    على الإنتاج أول محاولة رفع).
    \App\Models\Permit::refreshUnpaidReady();
};

$v57Verify = static function () use ($v57BalanceBefore, $v57RepsBefore, $v57Say, $v57Has): bool {
    $ok = true;
    $fail = static function (string $m) use (&$ok, $v57Say): void { $ok = false; $v57Say('  ✗ ' . $m); };

    foreach (['permit_stages', 'rep_submissions'] as $table) {
        $v57Has($table, 'unpaid') ? $v57Say("  ✓ {$table}.unpaid موجود.")
                                  : $fail("{$table}.unpaid مش موجود!");
    }

    // ⚠️ كل السجلات القديمة لازم تفضل «مدفوعة» — أي صف بـunpaid=1 بعد الترحيل
    //    معناه إن الافتراضي اتكتب غلط، والفلوس هتختفي من الخزنة بلا سبب.
    $flagged = (int) Database::scalar('SELECT COUNT(*) FROM permit_stages WHERE unpaid = 1');
    $flagged === 0 ? $v57Say('  ✓ ولا بند قديم اتعلّم «غير مدفوع» (الافتراضي 0).')
                   : $fail("فيه {$flagged} بند اتعلّم «غير مدفوع» بالغلط — الافتراضي المفروض 0.");

    // المصدر الوحيد للشرط لازم يبقى شايف العمود دلوقتي
    $sql = \App\Models\Permit::cashOutSql('s');
    str_contains($sql, 'unpaid')
        ? $v57Say('  ✓ Permit::cashOutSql بقت بتستثني «غير مدفوع».')
        : $fail('Permit::cashOutSql لسه مش شايفة العمود — الاستثناء مش هيسري.');

    $after = Closing::currentBalance();
    if (abs($after - $v57BalanceBefore) > 0.005) {
        $fail('رصيد الخزنة اتغيّر (' . number_format($v57BalanceBefore, 2)
            . ' ← ' . number_format($after, 2) . ') — الترحيل ده ممنوع يكون له أثر مالي.');
    } else {
        $v57Say('  ✓ رصيد الخزنة زي ما هو (' . number_format($after, 2) . ').');
    }

    if (\App\Models\RepCustody::available()) {
        $repsAfter = \App\Models\RepCustody::totalOutstanding();
        if (abs($repsAfter - $v57RepsBefore) > 0.005) {
            $fail('إجمالي عهد المناديب اتغيّر (' . number_format($v57RepsBefore, 2)
                . ' ← ' . number_format($repsAfter, 2) . ').');
        } else {
            $v57Say('  ✓ إجمالي عهد المناديب زي ما هو (' . number_format($repsAfter, 2) . ').');
        }
    }

    if ($ok) { $v57Say('  ✓ التحقق بعد التطبيق عدّى.'); }
    return $ok;
};

// ⚠️ مصيدة v49 الموثَّقة: الملف بيتنفّذ بطريقتين ولازم يشتغل صح في الاتنين.
$v57Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
if ($v57Direct) {
    echo "v57 — بند «غير مدفوع» على الإذن\n" . str_repeat('=', 60) . "\n";
    $v57Up();
    if ($v57Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v57.\n");
        exit(1);
    }
    echo "تمام.\n";
}

return ['up' => $v57Up, 'verify' => $v57Verify];
