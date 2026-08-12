<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v92 — **المستند الأرشيفي**: فاتورة/تحصيل جايين من برّه النظام بلا إذن.
 *
 * ═══ اللزمة (قرار المالك 2026-08-12) ═══
 * «ده ملف إكسل كنا شغالين عليه حساب كل العملاء الذين لدينا حتى الآن. أريد
 *  تغذية النظام بكل الحسابات الموجودة، علمًا بأن هناك بعض الفواتير تم إصدارها
 *  للنظام من قبل.»
 *
 * الملف فيه **٥٧ حساب عميل** من ٣ يناير → ١١ أغسطس ٢٠٢٦ (٩٢١ سطر فاتورة +
 * ٣٣٩ سطر تحصيل + ٩ أرصدة مرحّلة من ٢٠٢٥)، وإجماليها **٨,٢٦٤,٠١٩** مطلوبة من
 * العملاء. الحركة دي حصلت **قبل** ما النظام يشتغل، فمالهاش أذونات ولا بوالص
 * ولا بنود مصروفات — عندها **الرقم النهائي بس**.
 *
 * و`permit_invoices.permit_id` كان `NOT NULL` بمفتاح أجنبي على `permits`،
 * فحفظ الحركة دي كان بيتطلّب **اختراع ٨٧٧ إذن وهمي** بلا مصروف ولا بوليصة:
 * بيتحسبوا في عدّاد الأذونات والحاويات في لوحة المعلومات، وبيولّدوا ٨٧٧ تنبيه
 * «إذن ناقص»، وبيتخبطوا في `uq_permit_type_no` (٦ أرقام مكرّرة + ٣٢ سطر بلا
 * رقم إذن أصلاً). **بيانات تشغيلية متولَّدة من العدم عشان قيد فهرسة.**
 *
 * ═══ اللي بيتعمل ═══
 *   ①  `permit_invoices.permit_id` بقى **يقبل NULL** — مستند بلا إذن حاجة
 *      مشروعة دلوقتي. المفتاح الأجنبي بيفضل زي ما هو (NULL بيعدّي منه بالتعريف)،
 *      فالمستند اللي **له** إذن لسه محروس إنه يشاور على إذن موجود فعلاً.
 *   ②  عمود `origin` في `permit_invoices` و`client_payments`:
 *        `system` (الافتراضي) = اتولد من شغل النظام · `legacy` = مستورَد.
 *      العلامة دي هي اللي بتخلّي الاستيراد **قابلًا للتراجع بأمر واحد**، وبتخلّي
 *      أي شاشة تقدر تفرق بين «رقم النظام حسبه» و«رقم جاي من الإكسل».
 *   ③  عمود `import_key` **فريد** في الجدولين = بصمة السطر في ملف الإكسل
 *      (شيت + رقم السطر). ده **حارس التكرار**: تشغيل الاستيراد مرتين بيرمي
 *      خطأ تفرّد بدل ما يضاعف ٨ مليون جنيه بالسكوت.
 *
 * ⚠️ **صفر أثر مالي** — الترحيل ده بنية بحتة: عمودان وعلامة تفرّد وتخفيف قيد.
 *    ولا صفّ اتغيّرت قيمته، ولا مليم اتحرّك. وكل الصفوف القايمة بتاخد
 *    `origin='legacy'`؟ **لأ** — بتاخد `system` وهو الافتراضي، فالمعنى بيفضل
 *    صح: اللي موجود دلوقتي كله اتولد من النظام فعلاً.
 *
 * ⚠️ `permit_invoices` **مالهاش أي علاقة** بالخزنة ولا التقفيل ولا العهدة
 *    (متحقَّق: ولا `Closing` ولا `CompanySafe` ولا `Custody` ولا `Expense`
 *    بتذكر الجدول)، و`client_payments` بـ`route='none'` مالهاش أثر نقدي.
 *    فالاستيراد اللي جاي بعد الترحيل ده **مستحيل** يلمس رصيد خزنة.
 *
 * ⚠️ الصفوف بـ`permit_id IS NULL` بتخرج تلقائيًّا من كل عدّادات الأذونات
 *    (`WHERE i.permit_id = p.id` ما بيطابقش NULL) — وده **السلوك الصح**:
 *    المستند الأرشيفي مش مربوط بإذن في النظام، فما يعدّش عليه.
 *
 * التشغيل:  php bin/migrate_v92.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$v92Dry    = in_array('--dry-run', $argv ?? [], true);
$v92Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v92Say = static function (string $m) use ($v92Direct): void { if ($v92Direct) { echo $m . "\n"; } };

$v92HasTbl = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$v92HasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$v92HasIdx = static fn (string $t, string $i): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);
$v92Nullable = static fn (string $t, string $c): bool => 'YES' === (string) Database::scalar(
    'SELECT is_nullable FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);

$v92Up = static function () use ($v92Dry, $v92Say, $v92HasTbl, $v92HasCol, $v92HasIdx, $v92Nullable): void {
    $pdo = Database::conn();

    if (!$v92HasTbl('permit_invoices')) {
        $v92Say('  ✗ permit_invoices مش موجود — شغّل bin/migrate_v10.php الأول.');
        return;
    }
    if (!$v92HasTbl('client_payments')) {
        $v92Say('  ✗ client_payments مش موجود — شغّل bin/migrate_v23.php الأول.');
        return;
    }

    // ①  المستند يقدر يعيش بلا إذن.
    if ($v92Nullable('permit_invoices', 'permit_id')) {
        $v92Say('  • permit_id بيقبل NULL أصلاً');
    } else {
        if (!$v92Dry) {
            $pdo->exec('ALTER TABLE permit_invoices MODIFY permit_id INT UNSIGNED NULL');
        }
        $v92Say('  ✓ permit_id بقى يقبل NULL (المفتاح الأجنبي زي ما هو)');
    }

    // ②③  علامة المصدر + بصمة سطر الاستيراد — في الجدولين بنفس التعريف.
    foreach (['permit_invoices', 'client_payments'] as $tbl) {
        if (!$v92HasCol($tbl, 'origin')) {
            if (!$v92Dry) {
                $pdo->exec("ALTER TABLE `$tbl`
                    ADD COLUMN origin VARCHAR(12) NOT NULL DEFAULT 'system'
                        COMMENT 'system=اتولد من النظام | legacy=مستورَد من كشوف قديمة'");
            }
            $v92Say("  ✓ {$tbl}.origin (الافتراضي system)");
        } else {
            $v92Say("  • {$tbl}.origin موجود");
        }

        if (!$v92HasCol($tbl, 'import_key')) {
            if (!$v92Dry) {
                $pdo->exec("ALTER TABLE `$tbl`
                    ADD COLUMN import_key VARCHAR(80) NULL
                        COMMENT 'بصمة سطر المصدر — فريدة فالاستيراد ما يتكررش'");
            }
            $v92Say("  ✓ {$tbl}.import_key");
        } else {
            $v92Say("  • {$tbl}.import_key موجود");
        }

        $idx = $tbl === 'permit_invoices' ? 'uq_pinv_import_key' : 'uq_cpay_import_key';
        if (!$v92HasIdx($tbl, $idx)) {
            if (!$v92Dry) {
                $pdo->exec("ALTER TABLE `$tbl` ADD UNIQUE KEY `$idx` (import_key)");
            }
            $v92Say("  ✓ فريد {$idx} — حارس التكرار");
        } else {
            $v92Say("  • فريد {$idx} موجود");
        }

        $oIdx = $tbl === 'permit_invoices' ? 'idx_pinv_origin' : 'idx_cpay_origin';
        if (!$v92HasIdx($tbl, $oIdx)) {
            if (!$v92Dry) { $pdo->exec("ALTER TABLE `$tbl` ADD KEY `$oIdx` (origin)"); }
            $v92Say("  ✓ فهرس {$oIdx}");
        } else {
            $v92Say("  • فهرس {$oIdx} موجود");
        }
    }
};

/** ⚠️ التحقّق على **حالة بنيوية دائمة** لا لقطة بيانات. */
$v92Verify = static function () use ($v92Say, $v92HasCol, $v92HasIdx, $v92Nullable): bool {
    $ok = true;

    if (!$v92Nullable('permit_invoices', 'permit_id')) {
        $v92Say('✗ permit_id لسه NOT NULL — المستند الأرشيفي مستحيل يتحفظ.'); $ok = false;
    } else { $v92Say('  ✓ permit_id بيقبل NULL'); }

    // المفتاح الأجنبي لازم **يفضل** — تخفيف القيد مش إلغاء الحراسة.
    $fk = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.referential_constraints
          WHERE constraint_schema = DATABASE() AND table_name = 'permit_invoices'
            AND constraint_name = 'fk_pinv_permit'");
    if ($fk !== 1) {
        $v92Say('✗ المفتاح الأجنبي fk_pinv_permit اتشال — المستند بقى يشاور على إذن مش موجود.');
        $ok = false;
    } else { $v92Say('  ✓ المفتاح الأجنبي على الإذن لسه حارس'); }

    foreach ([['permit_invoices', 'uq_pinv_import_key', 'idx_pinv_origin'],
              ['client_payments', 'uq_cpay_import_key', 'idx_cpay_origin']] as [$tbl, $uq, $ix]) {
        foreach (['origin', 'import_key'] as $col) {
            if (!$v92HasCol($tbl, $col)) { $v92Say("✗ {$tbl}.{$col} ناقص."); $ok = false; }
        }
        if (!$v92HasIdx($tbl, $uq)) {
            $v92Say("✗ فريد {$uq} ناقص — الاستيراد يقدر يتكرر ويضاعف الأرقام."); $ok = false;
        }
        if (!$v92HasIdx($tbl, $ix)) { $v92Say("✗ فهرس {$ix} ناقص."); $ok = false; }

        // الفريد لازم يكون **فريدًا فعلاً** لا فهرسًا عاديًّا بنفس الاسم.
        $uniq = (int) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ?
                AND index_name = ? AND non_unique = 0", [$tbl, $uq]);
        if ($uniq < 1) { $v92Say("✗ {$uq} مش فريد فعلاً."); $ok = false; }
    }
    if ($ok) { $v92Say('  ✓ علامة المصدر وبصمة الاستيراد فعّالة في الجدولين'); }

    // الافتراضي لازم يفضل system — لو اتقلب، كل صف جديد هيتحسب مستورَدًا.
    foreach (['permit_invoices', 'client_payments'] as $tbl) {
        $def = (string) Database::scalar(
            'SELECT column_default FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$tbl, 'origin']);
        if (trim($def, "'") !== 'system') {
            $v92Say("✗ {$tbl}.origin افتراضيه «{$def}» مش system."); $ok = false;
        }
    }

    return $ok;
};

if ($v92Direct) {
    echo 'v92 — المستند الأرشيفي (فاتورة/تحصيل بلا إذن)' . ($v92Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v92Up();
    if ($v92Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v92Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v92.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v92Up, 'verify' => $v92Verify];
