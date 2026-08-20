<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v109 — **الإيصال بسعره وعربيته**: البند بقى مجموعة إيصالات، لكل واحد سعره.
 *
 * ═══ اللزمة (طلب المالك بالحرف) ═══
 * «المناديب من ضغط الشغل بيدخلوا الإيصالات مجمّعة بمبلغ إجمالي… وإيصالات
 *  الهيئة مش كلها بسعر واحد، ممكن اتنين بنفس السعر والباقي سعر مختلف.
 *  + عاوز أربط إيصالات الجيش والهيئة بالسيارات عند اعتمادها.»
 *
 * ═══ ليه الموديل القديم ما كانش بيكفي ═══
 * `permit_stages` بيوصف البند بـ`amount` (إجمالي) + `qty` (عدد)، والسعر
 * **مشتقّ** بالقسمة. ده مبني على افتراض إن الـ١٢ إيصال نسخ من إيصال واحد —
 * والافتراض ده غلط: كل إيصال هيئة بسعره. فـ«١٢ × ٢٣٩٫١٧» رقم مالوش وجود في
 * الواقع، **وتوزيعه على العربيات بالتساوي بيكذب مرتين**: العربية اللي إيصالها
 * ٤٠٠ بتتحمّل ٢٣٩ والعربية اللي إيصالها ١٨٠ بتتحمّل ٢٣٩ برضه.
 *
 * ═══ اللي بيتعمل هنا ═══
 *   ①  `receipt_lines`  — سطر لكل إيصال حقيقي: سعره + عربيته (بوليصة).
 *   ②  `rep_submissions.lines_json` — قايمة الأسعار وهي لسه في صندوق الانتظار
 *      (البند ما بقاش صف في `permit_stages` غير عند الاعتماد).
 *   ③  إعداد `receipt_car_kinds` — أنواع الإيصالات اللي التوزيع على العربيات
 *      يهمّ فيها (هيئة · جيش) = مصدر عدّاد «لسه مش متوزّع».
 *
 * ═══ ⚠️⚠️ العزل المالي (القاعدة ①) ═══
 * **`permit_stages.amount` يفضل هو الحقيقة المالية الوحيدة.** الخزنة والتقفيل
 * والعهدة وفاتورة العميل كلهم بيقروا منه زي ما هُمّ بالحرف — ولا واحد فيهم
 * بيعرف `receipt_lines` أصلاً. السطور **بتفصّل** الإجمالي ولا بتزوّده ولا
 * بتنقّصه، والحارس `ReceiptLine::sync()` بيرفض أي قايمة مجموعها ≠ الإجمالي.
 * `verify_v109` **بيفشل** لو أي ملف خزنة/تقفيل/عهدة ذكر الجدول.
 *
 * ⚠️ **المفاتيح الخارجية هنا مقصودة — وهي عكس حالة v108.** جرد الخزينة كان
 *    شهادة مستقلة فمنعنا الـFK؛ السطر هنا **جزء من بنده** ومالوش معنى بدونه:
 *    `ON DELETE CASCADE` على البند بيضمن إن حذف بند ما يسيبش سطورًا يتيمة
 *    بتقول لعربية «عليكي ٢٥٠» وهي مدفوعتش، و`ON DELETE SET NULL` على البوليصة
 *    بيضمن إن حذف عربية يفكّ الربط بدل ما يشاور على بوليصة مش موجودة.
 *
 * ⚠️ **`seq` مش `id`** هو ترتيب الإيصال جوّه بنده: الترتيب ده بيتعرض للمالك
 *    وبيتقارن بالصورة اللي المندوب رفعها، فلازم يفضل ثابتًا بعد أي إعادة
 *    كتابة. المفتاح الفريد (stage_id, seq) بيمنع سطرين بنفس الترتيب.
 *
 * ⚠️ **`amount` بيقبل الصفر ما بيقبلش السالب.** إيصال ببلاش بيحصل (مُعفى)،
 *    وإيصال بسعر سالب مالوش أي معنى — والسالب كان هيخلّي مجموع السطور يطابق
 *    الإجمالي بأرقام متخبّطة.
 *
 * ⚠️ `CREATE TABLE`/`ALTER TABLE` بيعملوا commit ضمني — فمفيش نمط معاملة هنا.
 *
 * التشغيل:  php bin/migrate_v109.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$v109Dry    = in_array('--dry-run', $argv ?? [], true);
$v109Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v109Say = static function (string $m) use ($v109Direct): void { if ($v109Direct) { echo $m . "\n"; } };

$v109HasTable = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);

$v109HasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);

$v109Ddl = "CREATE TABLE receipt_lines (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    stage_id   INT UNSIGNED      NOT NULL COMMENT 'البند في permit_stages — إجماليه هو الحقيقة المالية',
    seq        SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ترتيب الإيصال جوّه بنده (ثابت)',
    amount     DECIMAL(12,2)     NOT NULL DEFAULT 0 COMMENT 'سعر الإيصال ده لوحده',
    bawali_id  INT UNSIGNED      NULL COMMENT 'العربية (البوليصة) — NULL = لسه مش متوزّع',
    ref_no     VARCHAR(120)      NULL COMMENT 'رقم الإيصال — اختياري',
    created_by VARCHAR(120)      NULL,
    created_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(120)      NULL,
    updated_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_line (stage_id, seq),
    KEY idx_line_bawali (bawali_id),
    CONSTRAINT fk_rl_stage  FOREIGN KEY (stage_id)  REFERENCES permit_stages(id) ON DELETE CASCADE,
    CONSTRAINT fk_rl_bawali FOREIGN KEY (bawali_id) REFERENCES bawalis(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سطور الإيصالات (v109) — تفصيل بلا أثر مالي: المجموع = permit_stages.amount'";

$v109Up = static function () use ($v109Dry, $v109Say, $v109HasTable, $v109HasCol, $v109Ddl): void {

    // ① جدول السطور
    if ($v109HasTable('receipt_lines')) { $v109Say('  • جدول receipt_lines موجود'); }
    elseif (!$v109Dry) { Database::conn()->exec($v109Ddl); $v109Say('  ✓ جدول: receipt_lines'); }
    else { $v109Say('  ✓ جدول: receipt_lines (تجربة)'); }

    /* ② قايمة الأسعار في صندوق الانتظار.
       ⚠️ **JSON مش جدول تاني** عن قصد: `rep_submissions` صندوق انتظار بلا أي
          أثر مالي وبيتمسح بالاعتماد أو الرفض، فجدول سطور تاني ليه كان معناه
          دورة حياة تانية لازم تتزامن معاه. نفس سابقة `office_driver_statements
          .items_json`. السطور الحقيقية بتتولد وقت الاعتماد بس. */
    if ($v109HasCol('rep_submissions', 'lines_json')) { $v109Say('  • عمود rep_submissions.lines_json موجود'); }
    elseif (!$v109Dry) {
        Database::conn()->exec(
            "ALTER TABLE rep_submissions
                ADD COLUMN lines_json TEXT NULL DEFAULT NULL
                COMMENT 'أسعار الإيصالات مفصّلة [250,250,400] — NULL = بند بسعر واحد متكرر'
                AFTER amount");
        $v109Say('  ✓ عمود: rep_submissions.lines_json');
    } else { $v109Say('  ✓ عمود: rep_submissions.lines_json (تجربة)'); }

    // ③ تصفير الكاش الساكن (مصيدة v57) — من غيره أول طلب بعد الترحيل بيفضل
    //    شايف الجدول/العمود «مش موجود» ويطفي الوحدة كلها بالسكوت.
    if (!$v109Dry && class_exists(\App\Models\ReceiptLine::class)) {
        \App\Models\ReceiptLine::refreshReady();
    }
};

/**
 * ⚠️ التحقّق بيثبت **حالة بنيوية دائمة** لا لقطة بيانات: الجدول موجود بمفتاحه
 *    الفريد، والـCASCADE/SET NULL فعّالين، والعمود موجود. الجدول بيفضل فاضي
 *    لحد أول إيصال مفصّل — فأي فحص على صفوف كان هيبقى غير قابل لإعادة التحقّق.
 */
$v109Verify = static function () use ($v109Say, $v109HasTable, $v109HasCol): bool {
    $ok = true;

    if (!$v109HasTable('receipt_lines')) { $v109Say('  ✗ جدول ناقص: receipt_lines'); return false; }

    $cols = array_column(Database::all(
        'SELECT COLUMN_NAME AS c FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ?', ['receipt_lines']), 'c');
    foreach (['stage_id', 'seq', 'amount', 'bawali_id', 'ref_no'] as $need) {
        if (!in_array($need, $cols, true)) { $v109Say("  ✗ عمود ناقص: receipt_lines.$need"); $ok = false; }
    }

    if (!$v109HasCol('rep_submissions', 'lines_json')) {
        $v109Say('  ✗ عمود ناقص: rep_submissions.lines_json'); $ok = false;
    }

    // المفتاح الفريد (stage_id, seq) — هو اللي بيمنع سطرين بنفس الترتيب
    $uq = array_map(static fn ($r) => (string) $r['col'], Database::all(
        'SELECT COLUMN_NAME AS col FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? AND non_unique = 0
          ORDER BY seq_in_index', ['receipt_lines', 'uq_line']));
    if ($uq !== ['stage_id', 'seq']) {
        $v109Say('  ✗ uq_line لازم يكون فريدًا على (stage_id, seq)'); $ok = false;
    }

    /* ⚠️ **قواعد الحذف هي الحارس الحقيقي** — من غير CASCADE، حذف بند بيسيب
       سطورًا يتيمة بتقول لعربية «عليكي ٢٥٠» وهي اتمسحت أصلاً. */
    $rules = [];
    foreach (Database::all(
        "SELECT rc.constraint_name AS n, rc.delete_rule AS r
           FROM information_schema.referential_constraints rc
          WHERE rc.constraint_schema = DATABASE() AND rc.table_name = 'receipt_lines'") as $r) {
        $rules[(string) $r['n']] = strtoupper((string) $r['r']);
    }
    if (($rules['fk_rl_stage'] ?? '') !== 'CASCADE') {
        $v109Say('  ✗ fk_rl_stage لازم يكون ON DELETE CASCADE'); $ok = false;
    }
    if (($rules['fk_rl_bawali'] ?? '') !== 'SET NULL') {
        $v109Say('  ✗ fk_rl_bawali لازم يكون ON DELETE SET NULL'); $ok = false;
    }

    if ($ok) { $v109Say('  ✓ receipt_lines موجود بمفتاحه الفريد وقواعد حذفه، وlines_json موجود.'); }
    return $ok;
};

if ($v109Direct) {
    echo 'v109 — الإيصال بسعره وعربيته' . ($v109Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";

    /* ⚠️ **الأرصدة قبل وبعد لازم تكون هي هي بالمليم.** الترحيل بيضيف تفصيلًا
       فوق بنود قايمة وما بيلمسش ولا مبلغ — أي حركة في رصيد معناها إن حاجة
       مالية اتمسّت وما كانش المفروض. */
    $snap = static fn (): array => [
        'office'  => round(\App\Models\Closing::currentBalance(), 2),
        'company' => round(\App\Models\CompanySafe::balance(), 2),
    ];
    $before = $snap();
    $stagesBefore = round((float) Database::scalar(
        "SELECT COALESCE(SUM(amount),0) FROM permit_stages WHERE stage_key='receipts'"), 2);

    $v109Up();
    if ($v109Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }

    if ($v109Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v109.\n"); exit(1); }

    $after = $snap();
    foreach ($before as $k => $v) {
        if (abs($after[$k] - $v) > 0.005) {
            fwrite(STDERR, "✗✗ اتغيّر رصيد $k — مستحيل في ترحيل بنيوي.\n");
            exit(1);
        }
    }
    $stagesAfter = round((float) Database::scalar(
        "SELECT COALESCE(SUM(amount),0) FROM permit_stages WHERE stage_key='receipts'"), 2);
    if (abs($stagesAfter - $stagesBefore) > 0.005) {
        fwrite(STDERR, "✗✗ اتغيّر إجمالي الإيصالات — الترحيل ما بيلمسش أي مبلغ.\n");
        exit(1);
    }

    printf("✓ درج المكتب %s · خزنة الشركة %s · إجمالي الإيصالات %s — ولا مليم اتحرّك.\n",
        number_format($after['office'], 2), number_format($after['company'], 2),
        number_format($stagesAfter, 2));

    echo "\n✓ تمّ. وبعده: php bin/verify_v109.php\n";
}

return ['up' => $v109Up, 'verify' => $v109Verify];
