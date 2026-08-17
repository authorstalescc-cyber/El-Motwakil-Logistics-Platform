<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v100 — **تفضيلات فوترة العميل**: ضريبي/غير ضريبي · فاتورة/كشف/مطالبة.
 *
 * ═══ اللزمة (طلب المالك) ═══
 * «في عملاء بيتحاسبوا ضريبي وعملاء مش بيتحاسبوا ضريبي، وفي عملاء بيفضل لهم
 *  كشف الحساب وعملاء بيفضل لهم المطالبة وهكذا — محتاج السيستم يتعلّم الموضوع ده.»
 *
 * ⚠️ **ودي مش «عادة يتعلّمها» — دي حقيقة عن العميل.** الفرق جوهري: الجهة
 *    والسعر بيتغيّروا من شحنة لشحنة فبيتقاسوا إحصائيًّا (v97/v98)، لكن «العميل
 *    ده بيتحاسب ضريبي» **قرار تجاري ثابت** المالك عارفه ومش محتاج النظام
 *    يستنتجه كل مرة. فمكانه **بروفايل العميل** لا محرّك العادات.
 *    والاستنتاج من التاريخ بيفضل **احتياطيًّا** للعميل اللي لسه ما اتثبّتلوش
 *    تفضيل — عشان النظام يبقى مفيد من أول يوم بلا ما المالك يملا حاجة.
 *
 * ═══ اللي بيتعمل ═══
 *   ①  `agents.default_doc_kind`  VARCHAR(12) NULL — invoice|statement|claim
 *   ②  `agents.default_taxable`   TINYINT(1)  NULL — 1=خاضع | 0=غير خاضع | NULL=استنتج
 *   ③  `agents.default_vat_rate`  DECIMAL(6,4) NULL — نسبة خاصة بالعميل (NULL=العام)
 *
 * ⚠️ **الافتراضي `NULL` في التلاتة عن قصد — مش قيمة.** `NULL` معناها «المالك
 *    ما قالش»، و`0` معناها «قال: غير خاضع». لو حطّينا `0` افتراضيًّا كنا هنقفل
 *    باب الاستنتاج على كل العملاء **بالسكوت** ونعلن قرارًا المالك ما اتخذوش.
 *
 * ⚠️ **صفر أثر مالي.** ولا فاتورة قايمة بتتغيّر: الأعمدة دي بتحكم **الافتراضي
 *    في الفورم** بس، والفاتورة المحفوظة لقطة ثابتة. والتحقّق تحت بيقيس
 *    مجاميع الفواتير والضريبة قبل وبعد ويفشل لو اتحرّك مليم.
 *
 * ⚠️ `ALTER TABLE` بيعمل **commit ضمني**، فنمط «افتح معاملة ← عدّل ← قِس ←
 *    ارجع» مش صالح هنا. القياس قبل وبعد بلا معاملة (زي v88 و v94).
 *
 * التشغيل:  php bin/migrate_v100.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$v100Dry    = in_array('--dry-run', $argv ?? [], true);
$v100Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v100Say = static function (string $m) use ($v100Direct): void { if ($v100Direct) { echo $m . "\n"; } };

$v100HasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);

/** لقطة أرقام الفوترة — الأساس اللي بنثبت إنه ما اتغيّرش. */
$v100Snapshot = static fn (): array => [
    'docs'  => (int) Database::scalar('SELECT COUNT(*) FROM permit_invoices'),
    'total' => (float) Database::scalar('SELECT COALESCE(SUM(total),0) FROM permit_invoices'),
    'vat'   => (float) Database::scalar('SELECT COALESCE(SUM(vat_amount),0) FROM permit_invoices'),
    'taxed' => (int) Database::scalar('SELECT COUNT(*) FROM permit_invoices WHERE taxable = 1'),
];

$v100Cols = [
    'default_doc_kind' => "VARCHAR(12) NULL COMMENT 'تفضيل نوع المستند (v100): invoice|statement|claim — NULL = استنتج من التاريخ'",
    'default_taxable'  => "TINYINT(1) NULL COMMENT 'تفضيل الضريبة (v100): 1=خاضع 0=غير خاضع NULL=استنتج'",
    'default_vat_rate' => "DECIMAL(6,4) NULL COMMENT 'نسبة ضريبة خاصة بالعميل (v100) — NULL = النسبة العامة'",
];

$v100Up = static function () use ($v100Dry, $v100Say, $v100HasCol, $v100Cols): void {
    $pdo = Database::conn();
    $after = 'payment_terms_days';
    foreach ($v100Cols as $col => $def) {
        if ($v100HasCol('agents', $col)) { $v100Say("  • agents.$col موجود"); $after = $col; continue; }
        if (!$v100Dry) {
            /* ⚠️ ترتيب `COMMENT` قبل `AFTER` إجباري في MySQL/MariaDB —
               العكس بيدّي خطأ نحوي 1064 مش تحذير. */
            $pdo->exec("ALTER TABLE agents ADD COLUMN `$col` $def AFTER `$after`");
        }
        $v100Say("  ✓ عمود: agents.$col");
        $after = $col;
    }
};

/**
 * ⚠️ التحقّق بيثبت **حالة بنيوية دائمة** (العمود موجود · بيقبل NULL · القيم
 *    المسموحة محصورة) **لا لقطة بيانات لحظية** — وإلا يبقى غير قابل لإعادة
 *    التحقّق للأبد (قاعدة CLAUDE.md).
 */
$v100Verify = static function () use ($v100Say, $v100HasCol, $v100Cols): bool {
    $ok = true;
    foreach (array_keys($v100Cols) as $col) {
        if (!$v100HasCol('agents', $col)) { $v100Say("  ✗ عمود ناقص: agents.$col"); $ok = false; continue; }
        /* ⚠️ **الأسماء المستعارة إجبارية**: MySQL 8 بيرجّع أعمدة
           `information_schema` بحروف **كابيتال** (`IS_NULLABLE`)، فالقراءة
           بـ`$meta['is_nullable']` بتدّي مفتاحًا غير معرَّف — والفحص يفشل
           بإنذار كاذب على عمود سليم (اتصاد فعليًّا أول تشغيل). */
        $meta = Database::row(
            'SELECT IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', ['agents', $col]);
        if (($meta['is_nullable'] ?? '') !== 'YES') {
            $v100Say("  ✗ agents.$col لازم يقبل NULL — NULL = «المالك ما قالش»");
            $ok = false;
        }
        if ($meta['column_default'] !== null) {
            $v100Say("  ✗ agents.$col ليه افتراضي ({$meta['column_default']}) — الافتراضي لازم NULL");
            $ok = false;
        }
    }
    /* ولا قيمة برّه القائمة المسموحة (الحارس الحقيقي في الموديل، وده بيكشف
       أي كتابة خام عدّت من غير المرور عليه). */
    $bad = (int) Database::scalar(
        "SELECT COUNT(*) FROM agents
          WHERE default_doc_kind IS NOT NULL
            AND default_doc_kind NOT IN ('invoice','statement','claim')");
    if ($bad > 0) { $v100Say("  ✗ $bad عميل عنده نوع مستند مش في القائمة"); $ok = false; }

    if ($ok) { $v100Say('  ✓ التلات أعمدة موجودة، بتقبل NULL، وبلا افتراضي.'); }
    return $ok;
};

if ($v100Direct) {
    echo 'v100 — تفضيلات فوترة العميل' . ($v100Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";

    $before = $v100Snapshot();
    $v100Up();
    if ($v100Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }

    if ($v100Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v100.\n"); exit(1); }

    echo "\n" . str_repeat('─', 62) . "\n";
    $after = $v100Snapshot();
    $bad = [];
    foreach (['docs' => 'عدد المستندات', 'taxed' => 'عدد الضريبية'] as $k => $lbl) {
        if ($after[$k] !== $before[$k]) { $bad[] = $lbl; }
    }
    foreach (['total' => 'إجمالي الفواتير', 'vat' => 'إجمالي الضريبة'] as $k => $lbl) {
        if (abs($after[$k] - $before[$k]) > 0.005) { $bad[] = $lbl; }
    }
    if ($bad) {
        fwrite(STDERR, '✗✗ اتغيّر رقم مالي — مستحيل في ترحيل بنيوي: ' . implode(' · ', $bad) . "\n");
        exit(1);
    }
    printf("✓ %d مستند بإجمالي %s وضريبة %s — ما اتغيّرش ولا مليم.\n",
        $after['docs'], number_format($after['total'], 2), number_format($after['vat'], 2));

    echo "\n✓ تمّ. شغّل php bin/verify_v100.php للتأكد.\n";
}

return ['up' => $v100Up, 'verify' => $v100Verify];
