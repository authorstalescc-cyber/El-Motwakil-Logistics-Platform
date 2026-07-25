<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v50 — مراجعة التقفيل بقت «علامة ✓ جنب كل بند» (قرار المالك 2026-07-25).
 *
 * الطريقة القديمة (v28) كانت بتطلب من المالك يكتب **إجمالي يدوي لكل عميل**
 * ويقارنه بالسيستم لحد ما الفرق يبقى صفر. دي مراجعة بالجملة: بتقول «فيه فرق»
 * بس ما بتقولش **البند** اللي غلط، والمالك كان بيدوّر بإيده جوّه الأذونات.
 *
 * الطريقة الجديدة: كل بند في اليوم (إيصال/مصروف/عهدة سائق/عهدة مضافة/نثرية)
 * له **علامة مراجعة واحدة**. المالك بيبص على البند ويعلّمه ✓. الإقفال بيتفتح
 * لما كل بند نقدي يبقى متراجَع. مفيش أرقام تتكتب تاني، ومفيش فروق تتحسب.
 *
 * الجدول الجديد `daily_closing_reviews`:
 *   • صف لكل (يوم، نوع البند، رقم البند) — علامة مراجعة واحدة لا أكتر.
 *   • `amount_snapshot` = مبلغ البند **وقت المراجعة**. لو البند اتعدّل بعد كده،
 *     العلامة بتسقط تلقائيًّا وبتظهر شارة «المبلغ اتغيّر بعد المراجعة» — من غير
 *     ده كانت العلامة ممكن تشهد على رقم مبقاش موجود.
 *
 * ⚠️ **طبقة تدقيق بحتة — مفيش أي أثر مالي.** الجدول مالوش أي علاقة بحساب
 *    الرصيد ولا بالمرحّل ولا بعهدة المناديب؛ هو بس بوابة قبل زرار «اقفل اليوم».
 *    الهجرة بتتحقّق إن رصيد الخزنة ما اتغيّرش ولا مليم.
 *
 * ⚠️ **جداول v28 ما بتتمسحش.** بياناتها (المطابقات اليدوية القديمة) بتفضل في
 *    القاعدة كأرشيف، بس مفيش كود بيقراها بعد النهاردة. لو اتقرّر مسحها يبقى
 *    قرار منفصل بنسخة احتياطية قبله.
 *
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v50.php        (أو تلقائيًّا من bin/migration.php)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

/** رصيد الخزنة قبل أي كتابة — بيتقارن في verify. */
$v50BalanceBefore = Closing::currentBalance();

$v50TableExists = static fn(string $t): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?", [$t]);

$v50IndexExists = static fn(string $t, string $i): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?", [$t, $i]);

$v50Say = static function (string $line): void {
    // الصمت مش خيار: مخرجات الترحيل بتتسجّل في سجل الأحداث كدليل تنفيذ.
    echo $line . PHP_EOL;
};

$v50Up = static function () use ($v50TableExists, $v50Say): void {
    if ($v50TableExists('daily_closing_reviews')) {
        $v50Say('  = جدول daily_closing_reviews موجود بالفعل.');
        return;
    }
    Database::exec(<<<'SQL'
CREATE TABLE daily_closing_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  close_date DATE NOT NULL,
  item_type VARCHAR(20) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  amount_snapshot DECIMAL(14,2) NOT NULL DEFAULT 0,
  reviewed_by VARCHAR(120) NULL,
  reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review_item (close_date, item_type, item_id),
  INDEX idx_review_date (close_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $v50Say('  ✚ اتعمل جدول daily_closing_reviews (علامة مراجعة لكل بند في اليوم).');
};

$v50Verify = static function () use ($v50TableExists, $v50IndexExists, $v50Say, $v50BalanceBefore): bool {
    $ok = true;
    $fail = static function (string $msg) use (&$ok, $v50Say): void { $ok = false; $v50Say('  ✗ ' . $msg); };

    if (!$v50TableExists('daily_closing_reviews')) {
        $fail('جدول daily_closing_reviews مش موجود.');
        return false;
    }
    if (!$v50IndexExists('daily_closing_reviews', 'uq_review_item')) {
        $fail('المفتاح الفريد uq_review_item ناقص — ممكن يتسجّل نفس البند مرتين.');
    }
    foreach (['close_date', 'item_type', 'item_id', 'amount_snapshot', 'reviewed_by', 'reviewed_at'] as $col) {
        $exists = (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'daily_closing_reviews' AND column_name = ?", [$col]);
        if (!$exists) { $fail("العمود $col ناقص."); }
    }

    // العزل المالي: طبقة تدقيق، فرصيد الخزنة ممنوع يتحرّك ولا مليم.
    $after = Closing::currentBalance();
    if (abs($after - $v50BalanceBefore) > 0.005) {
        $fail('رصيد الخزنة اتغيّر (' . number_format($v50BalanceBefore, 2)
            . ' ← ' . number_format($after, 2) . ') — الهجرة دي ممنوع يكون لها أثر مالي.');
    }

    if ($ok) { $v50Say('  ✓ التحقق بعد التطبيق عدّى.'); }
    return $ok;
};

// ⚠️ الملف ده بيتنفّذ بطريقتين، ولازم يشتغل صح في الاتنين (مصيدة v49 الموثَّقة):
//   • `bin/migration.php apply-one` بيعمل require وبيشغّل up/verify بنفسه.
//   • `install.php` (runPending) بيشغّل الملف كسكربت مستقل وبيرمي القيمة المُرجَعة.
$v50Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
if ($v50Direct) {
    echo "v50 — مراجعة التقفيل بعلامة لكل بند\n" . str_repeat('=', 60) . "\n";
    $v50Up();
    if ($v50Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v50.\n");
        exit(1);
    }
    echo "تمام.\n";
}

return ['up' => $v50Up, 'verify' => $v50Verify];
