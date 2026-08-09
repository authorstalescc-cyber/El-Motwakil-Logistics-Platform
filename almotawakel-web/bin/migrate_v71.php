<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v71 — وسم النثرية: المندوب بيقول النثرية دي **نوعها إيه** وقت ما يسجّلها.
 *
 * ═══ اللزمة (طلب المالك 2026-08-09) ═══
 * «عند إضافة نثرية يظهر له سلايدر يختار منه نوع النثرية: مسحوب شخصي (سلفة) ·
 *  مواصلات داخلية (داخل الميناء) · مواصلات خارجية (خارج الميناء)، ولازم تتسجّل
 *  بالتاج بتاعها — لأننا هنحسب السلفة اللي أخدها المندوب من العهدة ونخصمها من
 *  راتبه، والمواصلات الداخلية هتتخصم من مصروف المناديب الأسبوعي.»
 *
 * ═══ ⚠️⚠️ الترحيل ده وسم بحت — صفر أثر مالي ═══
 * ولا معادلة اتغيّرت، ولا صف اتحرّك من محفظة لمحفظة. النثرية بكل أنواعها لسه:
 *   • بتتخصم من عهدة المندوب (`RepMiscExpense::totalFor`)
 *   • بتتخصم من الخزنة يوم صرفها لو كاش (`Closing::sumRepMisc`)
 *   • بتظهر في `/expenses` كمصدر `rep_misc`
 * **السلفة لسه مصروف شركة في النظام.** تحويلها لـ«مستحق على المندوب» يتخصم من
 * راتبه هو **قرار مالي جديد** بتاع وحدة الرواتب الجاية — والوسم ده هو اللي
 * بيخلّيها ممكنة. لو اتقرّر بعدين، الطريق الصح استثناء `advance` من
 * `RepMiscExpense::cashOnlySql()` **مش** لمس أي جدول خزنة.
 * والدليل: `$up` بياخد لقطة قبل/بعد لرصيد الخزنة ومجموع النثريات والبنود وصفوف
 * الإقفال، وبيرمي استثناء لو أي رقم اتحرّك مليم.
 *
 * ═══ ⚠️ ليه عمود جديد بدل ما نستخدم `category` الموجود؟ ═══
 * `category` **نص عرض** حر: بيتعرض في `/expenses` وبيتفلتر بـ`LIKE`، ولو
 * المالك غيّر تسمية بكرة كل الاستعلامات اللي بتفلتر بالنوع تفشل بالسكوت.
 * `misc_kind` **مفتاح آلة** ثابت (`advance` · `transport_in` …) عمره ما يتعرض،
 * والاتنين بيتكتبوا مع بعض من `RepMiscKind` فمفيش تعريفين ممكن يختلفوا.
 *
 * ═══ ⚠️ ليه VARCHAR(16) مش ENUM؟ (درس v67) ═══
 * `ENUM` بيخلّي أي نوع جديد محتاج `ALTER` على جدول حيّ. VARCHAR + تحقّق بنيوي
 * على `RepMiscKind::keys()` بيدّي نفس الأمان بلا DDL في المستقبل.
 *
 * ⚠️ `verify()` بتثبت **حالة بنيوية دائمة** (عمود · فهرس · ولا قيمة برّه
 *    الكتالوج) — مش لقطة بيانات لحظية (درس v61).
 *
 * ⚠️ **الرقم v71 مش v69 ولا v70**: v68 وv69 نسختان بلا هجرة (قفل الصيانة ·
 *    تقرير تدقيق UX)، وv70 اتاخد لسجل الإعدادات. رقم الترحيل بيتبع رقم النسخة.
 *
 * التشغيل:  php bin/migrate_v71.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\RepMiscExpense;
use App\Models\RepMiscKind;
use App\Models\RepPortal;

$v71Dry    = in_array('--dry-run', $argv ?? [], true);
$v71Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v71Say = static function (string $m) use ($v71Direct): void { if ($v71Direct) { echo $m . "\n"; } };

$v71HasTbl = static function (string $t): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
};
$v71HasCol = static function (string $t, string $c): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
};
$v71HasIdx = static function (string $t, string $i): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);
};

/**
 * الجدولين اللي بياخدوا العمود، والفهرس بتاع كل واحد.
 *   rep_submissions    → وسم البند وهو لسه pending (المندوب بيختاره، المكتب يصحّحه)
 *   rep_misc_expenses  → الوسم بعد الاعتماد (ده اللي التقارير بتقرا منه)
 *
 * ⚠️ العمود **NULL-able بلا افتراضي حرفي**: الصف القديم بيفضل NULL و
 *    `RepMiscKind::sqlKey()` بترجّعه `general` في أي قراءة — فمفيش UPDATE على
 *    صفوف قديمة، ومفيش صف بيختفي من GROUP BY.
 */
$v71Tables = [
    'rep_submissions'   => ['idx' => 'idx_sub_misc_kind',  'cols' => '(misc_kind, entry_date)'],
    'rep_misc_expenses' => ['idx' => 'idx_rme_misc_kind',  'cols' => '(misc_kind, exp_date)'],
];
$v71Ddl = "VARCHAR(16) NULL COMMENT 'وسم النثرية (v71) — مفتاح من RepMiscKind، NULL = general'";

$v71Snap = static function (): array {
    $t = static fn(string $tbl): bool => (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?', [$tbl]);
    return [
        'safe'     => round((float) Custody::balance(), 2),
        'misc'     => round((float) ($t('rep_misc_expenses') ? Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses') : 0), 2),
        'sub'      => round((float) ($t('rep_submissions') ? Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM rep_submissions') : 0), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];
};

$v71Up = static function () use ($v71Dry, $v71Say, $v71HasTbl, $v71HasCol, $v71HasIdx,
                                $v71Tables, $v71Ddl, $v71Snap): void {
    /* ⚠️ اللقطة **قبل** أي DDL — `ALTER TABLE` بيعمل commit ضمني فمستحيل
       يترجع، ونمط «افتح معاملة ← عدّل ← قِس ← ارجع» مش صالح هنا (مصيدة v59). */
    $before = $v71Snap();

    // ①  العمود + الفهرس على الجدولين
    foreach ($v71Tables as $tbl => $spec) {
        if (!$v71HasTbl($tbl)) {
            // قاعدة قبل v35/v39 — `RepMiscExpense::available()` بترجّع false
            // والنظام يفضل بسلوكه القديم بدل ما يكسر.
            $v71Say("  ! جدول $tbl مش موجود — اتخطّى.");
            continue;
        }
        if ($v71HasCol($tbl, 'misc_kind')) {
            $v71Say("  = $tbl.misc_kind (موجود)");
        } elseif ($v71Dry) {
            $v71Say("  • (تجربة) هيتضاف $tbl.misc_kind");
        } else {
            Database::exec("ALTER TABLE $tbl ADD COLUMN misc_kind $v71Ddl");
            $v71Say("  + $tbl.misc_kind");
        }

        if ($v71HasIdx($tbl, $spec['idx'])) {
            $v71Say("  = فهرس {$spec['idx']} (موجود)");
        } elseif ($v71Dry) {
            $v71Say("  • (تجربة) هيتضاف فهرس {$spec['idx']}");
        } elseif ($v71HasCol($tbl, 'misc_kind')) {
            Database::exec("ALTER TABLE $tbl ADD INDEX {$spec['idx']} {$spec['cols']}");
            $v71Say("  + فهرس {$spec['idx']}");
        }
    }

    /* ⚠️ تصفير الكاش الساكن إلزامي — `kindReady()`/`miscKindReady()` اتقرت قبل
       الـALTER في اللقطة فوق، والقيمة القديمة (false) بتخلّي الكتابة تتجاهل
       العمود الجديد **بالسكوت** (مصيدة v57). */
    if (!$v71Dry) {
        RepMiscExpense::refreshKindReady();
        RepPortal::refreshMiscKindReady();
    }

    // ②  إثبات إن ولا مليم اتحرّك
    $after = $v71Snap();
    foreach ($before as $k => $v) {
        if ($after[$k] !== $v) {
            // ⚠️ `{$k}` مش `$k` — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة v38).
            throw new \RuntimeException("v71: «{$k}» اتغيّر أثناء الترحيل — الوسم ممنوع يمسّ أي رقم.");
        }
    }
    $v71Say('  ✓ رصيد الخزنة ومجموع النثريات والبنود وصفوف الإقفال ما اتغيّروش');
};

$v71Verify = static function () use ($v71Say, $v71HasTbl, $v71HasCol, $v71HasIdx, $v71Tables): bool {
    foreach ($v71Tables as $tbl => $spec) {
        if (!$v71HasTbl($tbl)) { $v71Say("  ! جدول $tbl مش موجود — اتخطّى."); continue; }
        if (!$v71HasCol($tbl, 'misc_kind')) { $v71Say("✗ العمود $tbl.misc_kind ناقص."); return false; }
        if (!$v71HasIdx($tbl, $spec['idx'])) { $v71Say("✗ الفهرس {$spec['idx']} ناقص."); return false; }

        /* شرط بنيوي دائم: ولا قيمة وسم برّه الكتالوج. عدد العلامات محسوب من
           `RepMiscKind::keys()` — كتابته بالإيد بتكسر الفحص أول نوع جديد. */
        $keys = RepMiscKind::keys();
        $in   = implode(',', array_fill(0, count($keys), '?'));
        $bad  = Database::all(
            "SELECT DISTINCT misc_kind FROM $tbl
              WHERE misc_kind IS NOT NULL AND misc_kind <> '' AND misc_kind NOT IN ($in)", $keys);
        if ($bad) {
            $v71Say("✗ وسم نثرية مجهول في $tbl: " . implode(', ', array_column($bad, 'misc_kind')));
            return false;
        }
        $v71Say("  ✓ $tbl.misc_kind موجود + مفهرس + ولا قيمة برّه الكتالوج");
    }

    // الوسم مالوش أي معنى على بند مربوط بإذن — لو اتسجّل هناك يبقى فيه مسار
    // كتابة بيوسم الحاجة الغلط، والتقارير هتعدّ بنود أذونات كنثريات.
    if ($v71HasTbl('rep_submissions') && $v71HasCol('rep_submissions', 'misc_kind')) {
        $stray = (int) Database::scalar(
            "SELECT COUNT(*) FROM rep_submissions WHERE kind <> 'misc' AND misc_kind IS NOT NULL");
        if ($stray > 0) { $v71Say("✗ $stray بند على إذن متوسّم بوسم نثرية."); return false; }
        $v71Say('  ✓ ولا بند على إذن حامل وسم نثرية');
    }

    // التصنيف الافتراضي في `RepMiscKind` لازم يفضل مطابق لـ`RepMiscExpense::CATEGORY`
    // وإلا النثرية العامة تتعرض باسمين مختلفين حسب مين كتب الصف.
    if (RepMiscKind::category(RepMiscKind::DEFAULT) !== RepMiscExpense::CATEGORY) {
        $v71Say('✗ تصنيف النوع العام مش مطابق لـRepMiscExpense::CATEGORY.'); return false;
    }
    $v71Say('  ✓ تصنيف النوع العام مطابق للتصنيف التاريخي');

    if (RepMiscExpense::available() && !RepMiscExpense::refreshKindReady()) {
        $v71Say('✗ RepMiscExpense::kindReady بترجّع false بعد الترحيل.'); return false;
    }
    if (!RepPortal::refreshMiscKindReady()) {
        $v71Say('✗ RepPortal::miscKindReady بترجّع false بعد الترحيل.'); return false;
    }
    $v71Say('  ✓ كاشات الجاهزية اتصفّرت وبترجّع true');
    return true;
};

if ($v71Direct) {
    echo 'v71 — وسم النثرية (سلفة · مواصلات داخلية · مواصلات خارجية · عامة)'
        . ($v71Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v71Up();
    if ($v71Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v71Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v71.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v71Up, 'verify' => $v71Verify];
