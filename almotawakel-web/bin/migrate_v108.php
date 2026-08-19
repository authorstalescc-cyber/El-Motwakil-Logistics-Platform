<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v108 — **جرد الخزينة**: كشف واحد للخزنتين + تسجيل العدّ الفعلي والفرق.
 *
 * ═══ اللزمة (طلب المالك) ═══
 * «ابنيلي وحدة جديدة اسمها جرد خزينة — سواء المكتب أو خزينة الشركة في كشف واحد.»
 *
 * النظام كان بيعرف الخزنتين في **شاشتين منفصلتين**: `/closing` بتقول رصيد
 * الدرج بمعادلة اليوم، و`/company-safe` بتقول رصيد الشركة بكشفها. مفيش ورقة
 * واحدة بتجاوب على «الكاش كله فين دلوقتي، وهل اللي في الدرج فعلاً هو اللي
 * النظام بيقوله؟» — والمالك كان بيفتح شاشتين ويجمع بإيده.
 *
 * ═══ اللي بيتعمل هنا: **جدول واحد بس** ═══
 *   `safe_audits` — صف لكل (يوم × خزنة): الرصيد الدفتري وقت الجرد + المعدود
 *                    فعليًّا + الفرق. **لقطة تاريخية** زي الفاتورة بالظبط.
 *
 * ⚠️ **الكشف نفسه مش محتاج ولا عمود جديد.** كل رقم فيه بيتحسب من مصادره
 *    القايمة (`Closing` · `CompanySafe` · `DailyReview`) — الوحدة **عدسة**
 *    فوق اللي موجود، مش نسخة تانية من الحقيقة. أي تخزين للأرصدة كان معناه
 *    رقمين لنفس السؤال وواحد فيهم هيتقادم.
 *
 * ═══ العزل بالبناء (القاعدة ①) ═══
 * ⚠️⚠️ `safe_audits` **جدول شهادة لا جدول مال**: بيسجّل «عدّينا الدرج لقيناه
 *    كذا» وخلاص. ولا خزنة ولا تقفيل ولا عهدة ولا مصروف بيقرا منه أو بيتغيّر
 *    بسببه — **والفرق ما بيتقيّدش في أي مكان** (لو المالك عايز يصحّح، بيسجّل
 *    حركة عادية في الوحدة صاحبة الشأن وتبان في السجل باسمها).
 *    `verify_v108` **بيفشل** لو أي ملف مالي ذكر الجدول.
 *
 * ⚠️ **`book_balance` لقطة وقت الجرد لا مشتقّ عند العرض** — لو اتحسب من جديد
 *    كل مرة، جرد الشهر اللي فات بيتغيّر رقمه أول ما حد يعدّل حركة قديمة،
 *    والشهادة اللي المالك وقّع عليها تبقى بتقول حاجة تانية. (نفس قاعدة
 *    «الفاتورة لقطة ثابتة» v10.)
 *
 * ⚠️ `diff` عمود عادي **مش مولَّد** عن قصد: الأعمدة المولَّدة بتكسر استعادة
 *    نسخة الإنتاج محليًّا (`DEFAULT_GENERATED` — مصيدة موثَّقة في CLAUDE.md).
 *    الحساب في `SafeAudit::save()` وهو المصدر الوحيد.
 *
 * ⚠️ `CREATE TABLE` بيعمل commit ضمني — فمفيش نمط معاملة هنا (زي v100/v101/v102).
 *
 * التشغيل:  php bin/migrate_v108.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$v108Dry    = in_array('--dry-run', $argv ?? [], true);
$v108Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v108Say = static function (string $m) use ($v108Direct): void { if ($v108Direct) { echo $m . "\n"; } };

$v108HasTable = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);

/* ⚠️ `safe_key` نص قصير مش FK — الخزنتين مش صفوف في جدول، هما **وحدتان في
   الكود** (`SafeInventory::SAFES`). المفتاح الفريد (تاريخ × خزنة) بيمنع
   صفّين لنفس الجرد، والتعديل بيدهس الصف بدل ما يزوّد نسخة. */
$v108Ddl = "CREATE TABLE safe_audits (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    audit_date   DATE          NOT NULL COMMENT 'يوم الجرد',
    safe_key     VARCHAR(16)   NOT NULL COMMENT 'office = درج المكتب | company = خزنة الشركة',
    book_balance DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'الرصيد الدفتري لحظة الجرد — لقطة ثابتة',
    counted      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'المعدود فعليًّا بإيد المالك',
    diff         DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'counted − book_balance: موجب زيادة وسالب عجز',
    note         VARCHAR(255)  NULL,
    created_by   VARCHAR(120)  NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by   VARCHAR(120)  NULL,
    updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_audit (audit_date, safe_key),
    KEY idx_audit_date (audit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='جرد الخزينة (v108) — شهادة عدّ، بلا أي أثر مالي'";

$v108Up = static function () use ($v108Dry, $v108Say, $v108HasTable, $v108Ddl): void {
    if ($v108HasTable('safe_audits')) { $v108Say('  • جدول safe_audits موجود'); }
    elseif (!$v108Dry) { Database::conn()->exec($v108Ddl); $v108Say('  ✓ جدول: safe_audits'); }
    else { $v108Say('  ✓ جدول: safe_audits (تجربة)'); }

    // ⚠️ كاش المخطط الساكن لازم يتصفّر في آخر الترحيل (مصيدة v57) — من غيره
    //    أول طلب بعد الترحيل بيفضل شايف الجدول «مش موجود».
    if (!$v108Dry && class_exists(\App\Models\SafeAudit::class)) {
        \App\Models\SafeAudit::refreshReady();
    }
};

/**
 * ⚠️ التحقّق بيثبت **حالة بنيوية دائمة** (الجدول موجود · المفتاح الفريد فعّال
 *    على تاريخ+خزنة · مفيش ارتباط خارجي) لا لقطة بيانات — الجدول بيفضل فاضي
 *    لحد ما المالك يعمل أول جرد، فأي فحص على صفوف كان هيبقى غير قابل لإعادة
 *    التحقّق للأبد.
 */
$v108Verify = static function () use ($v108Say, $v108HasTable): bool {
    $ok = true;
    if (!$v108HasTable('safe_audits')) { $v108Say('  ✗ جدول ناقص: safe_audits'); return false; }

    $cols = array_column(Database::all(
        'SELECT COLUMN_NAME AS c FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ?', ['safe_audits']), 'c');
    foreach (['audit_date', 'safe_key', 'book_balance', 'counted', 'diff'] as $need) {
        if (!in_array($need, $cols, true)) { $v108Say("  ✗ عمود ناقص: $need"); $ok = false; }
    }

    $uq = array_map(static fn ($r) => (string) $r['col'], Database::all(
        'SELECT COLUMN_NAME AS col FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? AND non_unique = 0
          ORDER BY seq_in_index', ['safe_audits', 'uq_audit']));
    if ($uq !== ['audit_date', 'safe_key']) {
        $v108Say('  ✗ uq_audit لازم يكون فريدًا على (audit_date, safe_key)');
        $ok = false;
    }

    /* ⚠️ **`diff` لازم يفضل عمودًا عاديًّا** — العمود المولَّد بيكسر استعادة نسخة
       الإنتاج محليًّا (`DEFAULT_GENERATED`)، والفحص ده هو اللي بيمنع رجوعه. */
    $gen = (string) Database::scalar(
        "SELECT COALESCE(EXTRA,'') FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'safe_audits' AND column_name = 'diff'");
    if (stripos($gen, 'GENERATED') !== false) {
        $v108Say('  ✗ diff اتحوّل لعمود مولَّد — بيكسر استعادة النسخ'); $ok = false;
    }

    // شهادة عدّ مستقلة — مفيش FK بيربطها بأي جدول مالي.
    $fks = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.key_column_usage
          WHERE table_schema = DATABASE() AND table_name = 'safe_audits'
            AND referenced_table_name IS NOT NULL");
    if ($fks > 0) { $v108Say("  ✗ فيه $fks مفتاح خارجي — الجرد لازم يفضل معزولًا"); $ok = false; }

    if ($ok) { $v108Say('  ✓ safe_audits موجود بمفتاحه الفريد، وdiff عمود عادي، وبلا ارتباط خارجي.'); }
    return $ok;
};

if ($v108Direct) {
    echo 'v108 — جرد الخزينة' . ($v108Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";

    /* ⚠️ **الأرصدة قبل وبعد لازم تكون هي هي بالمليم** — ترحيل بنيوي بيضيف
       جدول شهادة، فأي حركة في رصيد معناها إن حاجة اتلمست وما كانش المفروض. */
    $snap = static fn (): array => [
        'office'  => round(\App\Models\Closing::currentBalance(), 2),
        'company' => round(\App\Models\CompanySafe::balance(), 2),
    ];
    $before = $snap();

    $v108Up();
    if ($v108Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }

    if ($v108Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v108.\n"); exit(1); }

    $after = $snap();
    foreach ($before as $k => $v) {
        if (abs($after[$k] - $v) > 0.005) {
            fwrite(STDERR, "✗✗ اتغيّر رصيد $k — مستحيل في ترحيل بنيوي.\n");
            exit(1);
        }
    }
    printf("✓ درج/خزنة المكتب %s · خزنة الشركة %s — ما اتغيّرش ولا مليم.\n",
        number_format($after['office'], 2), number_format($after['company'], 2));

    echo "\n✓ تمّ. الوحدة على /safe-audit — وبعده: php bin/verify_v108.php\n";
}

return ['up' => $v108Up, 'verify' => $v108Verify];
