<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v67 — خزنة الشركة الموسّعة: مصروفات بفئات · تحويل لكروت الفيزا · عهدة مندوب
 *       مباشرة · مرفقات · تعديل · ميزانية شهرية.
 *
 * ═══ اللزمة (طلب المالك 2026-08-08) ═══
 * v66 عملت خزنة الشركة بأربع حركات بس (إيداع · تحصيل عميل · سحب من المكتب ·
 * تحويل للمكتب) وكان مكتوب فيها صراحةً: «تغذّي المكتب وبس — مفيش مصروف بيتصرف
 * منها، ولو اتضاف بعدين يبقى قرار مالك جديد». المالك أخد القرار ده وطلب معاه
 * حزمة مزايا كاملة.
 *
 * ═══ ⚠️⚠️ العزل بالبناء لسه هو القاعدة — لا تعكسها ═══
 * `company_safe_entries` **كل كود الخزنة عمره ما سمع عنه** (`Closing` ·
 * `Custody` · `DailyReview` · `Expense` · `Metrics` · `backfill_closings`).
 * الترحيل ده **ما بيكسرش القاعدة دي**: كل الأنواع الجديدة بتتصرّف زي v66 —
 * إما بلا أي أثر برّه الجدول (`expense`)، أو بأثر بيمرّ على **API الوحدة
 * التانية** (`visa_deposits` عبر `VisaCard::createDeposit`، و
 * `rep_custody_deposits` عبر `RepCustody::createDeposit`) زي ما التحويل
 * للمكتب بيمرّ على `Custody::createTopup` بالظبط.
 *
 * ⚠️⚠️ **قرار المالك (سؤال مباشر): مصروف خزنة الشركة معزول عن `/expenses`.**
 *   يعني **مفيش leg جديد في `Expense::unionSql()`** — ما يظهرش في سجل
 *   المصروفات العام ولا التقارير ولا كارت «مصروفات اليوم» في الداشبورد.
 *   الأثر المعروف والمقبول: إجمالي مصروفات النظام أقل من الحقيقة بمقدار
 *   مصروفات خزنة الشركة — والشاشة بتقول ده بالنص. لو اتقرّر عكسه بعدين،
 *   الطريق الصح `Expense::unionSql()` + `Expense::SOURCES` (نمط `rep_misc`
 *   في v39) **مش** توصيل الجدول بـ`Closing`.
 *
 * ═══ ⚠️ ليه `kind` ما احتاجش ALTER و`source` احتاج ═══
 * `company_safe_entries.kind` اتعمل **VARCHAR(16)** في v66 عن قصد عشان أي نوع
 * جديد ما يحتاجش `ALTER` على جدول حيّ — فالأربع أنواع الجديدة بتتضاف في
 * `CompanySafe::KINDS` وبس. بالمقابل `rep_custody_deposits.source`
 * **ENUM('bank','office','carry')** فإضافة `company` محتاجة `ALTER` فعلي.
 *
 * ⚠️⚠️ **ليه مصدر تنزيل جديد أصلاً بدل ما نعيد استخدام `bank`؟**
 *   حسابيًّا `bank` كان هيدّي نفس النتيجة في النموذجين، لكن `sourceBadge`
 *   كانت هتقول للمالك «من البنك للمندوب على طول» وهي **كذبة**: الفلوس جت من
 *   خزنة الشركة. و`source` اتعمل في v61 عشان المالك يعرف الفلوس جت منين
 *   بالظبط (ده نص سبب وجوده الموثَّق)، فتلويثه بيلغي فايدته.
 *   و`RepCustody::depositAffectsSafe('company')` = `bankTopupActive()` —
 *   **نفس حساب `bank` بالحرف** عشان الأرقام تفضل صح في النموذجين.
 *
 * ⚠️ `verify()` بتثبت **حالة بنيوية دائمة** (أعمدة · فهارس · قيمة enum ·
 *    ولا قيمة خردة) — **مش لقطة بيانات لحظية** (درس v61 الموثَّق).
 *
 * التشغيل:  php bin/migrate_v67.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;

use App\Models\CompanySafe;
use App\Models\Custody;
use App\Models\RepCustody;

$v67Dry    = in_array('--dry-run', $argv ?? [], true);
$v67Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v67Say = static function (string $m) use ($v67Direct): void { if ($v67Direct) { echo $m . "\n"; } };

$v67HasCol = static function (string $t, string $c): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
};
$v67HasIdx = static function (string $t, string $i): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);
};

/** أعمدة v67 على `company_safe_entries` — المصدر الوحيد للترحيل والتحقّق. */
$v67Cols = [
    'category'        => "VARCHAR(32)  NULL COMMENT 'فئة المصروف — للنوع expense فقط'",
    'attachment'      => "VARCHAR(255) NULL COMMENT 'مسار إيصال منطقي private/company_safe/...'",
    'visa_card_id'    => "INT UNSIGNED NULL COMMENT 'كارت الفيزا (to_visa/from_visa) — بلا FK، تحذير ٣'",
    'visa_deposit_id' => "INT UNSIGNED NULL COMMENT 'صف visa_deposits المُولَّد — عشان الحذف يشيل الاتنين'",
    'rep_id'          => "INT UNSIGNED NULL COMMENT 'المندوب (to_rep) — بلا FK'",
    'rep_deposit_id'  => "INT UNSIGNED NULL COMMENT 'صف rep_custody_deposits المُولَّد'",
    // ⚠️ `updated_at` موجود من v66 بـ`ON UPDATE CURRENT_TIMESTAMP` — مش محتاج
    //    إضافة، والـ`UPDATE` بيحطّه صراحةً برضه عشان النية تبان في الكود.
    'updated_by'      => "VARCHAR(80)  NULL COMMENT 'آخر من عدّل الحركة'",
];

$v67Idx = [
    'idx_cse_cat'   => 'company_safe_entries (category, entry_date)',
    'idx_cse_party' => 'company_safe_entries (source(64), entry_date)',
    'idx_cse_visa'  => 'company_safe_entries (visa_deposit_id)',
    'idx_cse_rep'   => 'company_safe_entries (rep_deposit_id)',
];

$v67Up = static function () use ($v67Dry, $v67Say, $v67HasCol, $v67HasIdx, $v67Cols, $v67Idx): void {
    /* ⚠️ لقطة «ممنوع يتغيّر»: الترحيل ده بيضيف أعمدة فاضية وقيمة enum — فأي
       فرق في رصيد الخزنة أو خزنة الشركة أو صفوف الإقفال معناه إن حاجة اتلمست.
       (بنقيس **قبل** أي DDL لأن `ALTER TABLE` بيعمل commit ضمني ومستحيل يترجع
        — المصيدة الموثَّقة في v59.) */
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
        'repdep'   => round((float) Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits'), 2),
    ];

    // ①  أعمدة company_safe_entries
    foreach ($v67Cols as $col => $ddl) {
        if ($v67HasCol('company_safe_entries', $col)) {
            $v67Say("  = company_safe_entries.$col (موجود)");
        } elseif ($v67Dry) {
            $v67Say("  • (تجربة) هيتضاف company_safe_entries.$col");
        } else {
            Database::exec("ALTER TABLE company_safe_entries ADD COLUMN $col $ddl");
            $v67Say("  + company_safe_entries.$col");
        }
    }

    // ②  الفهارس
    foreach ($v67Idx as $name => $spec) {
        [$tbl, $cols] = explode(' ', $spec, 2);
        if ($v67HasIdx($tbl, $name)) {
            $v67Say("  = فهرس $name (موجود)");
        } elseif ($v67Dry) {
            $v67Say("  • (تجربة) هيتضاف فهرس $name");
        } else {
            Database::exec("ALTER TABLE $tbl ADD INDEX $name $cols");
            $v67Say("  + فهرس $name");
        }
    }

    /* ③  قيمة enum جديدة لمصدر تنزيل العهدة.
       ⚠️ `company` بتتضاف **في آخر القايمة** والافتراضي بيفضل `bank` — فولا صف
          قديم بيتغيّر. لو العمود مش موجود أصلاً (قاعدة قبل v61) بنتخطّى بأمان:
          `RepCustody::sourceReady()` بترجّع false والنظام يفضل بسلوكه القديم. */
    if (!$v67HasCol('rep_custody_deposits', 'source')) {
        $v67Say('  ! rep_custody_deposits.source مش موجود (قبل v61) — اتخطّى مصدر company.');
    } else {
        $type = (string) Database::scalar(
            "SELECT COLUMN_TYPE FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'rep_custody_deposits'
                AND column_name = 'source'");
        if (str_contains($type, "'company'")) {
            $v67Say('  = مصدر company في enum (موجود)');
        } elseif ($v67Dry) {
            $v67Say('  • (تجربة) هيتضاف مصدر company لـ enum');
        } else {
            Database::exec(
                "ALTER TABLE rep_custody_deposits
                 MODIFY COLUMN source ENUM('bank','office','carry','company')
                 NOT NULL DEFAULT 'bank'");
            $v67Say('  + مصدر company في enum');
        }
    }

    // ④  إعداد الميزانية الشهرية — فاضي = مفيش سقف = مفيش تنبيه.
    if (!$v67Dry && App::setting('company_safe_monthly_budget', null) === null) {
        App::setSetting('company_safe_monthly_budget', '');
        $v67Say('  + إعداد company_safe_monthly_budget (فاضي = بلا سقف)');
    }

    /* ⚠️ تصفير الكواش الساكنة **إلزامي** — `sourceReady()` و`ready()` اتقرت
       قبل الـALTER في اللقطة فوق، والقيمة القديمة بتفشّل التحقّق (مصيدة v57). */
    if (!$v67Dry) {
        RepCustody::refreshSourceReady();
        CompanySafe::refreshReady();
    }

    // ⑤  إثبات إن ولا مليم اتحرّك
    $after = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
        'repdep'   => round((float) Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits'), 2),
    ];
    foreach ($before as $k => $v) {
        if ($after[$k] !== $v) {
            // ⚠️ `{$k}` مش `$k` — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة v38).
            throw new \RuntimeException("v67: «{$k}» اتغيّر أثناء الترحيل — الترحيل ممنوع يمسّ أي رصيد.");
        }
    }
    $v67Say('  ✓ رصيد الخزنة وخزنة الشركة وصفوف الإقفال وتنزيلات العهدة ما اتغيّروش');
};

$v67Verify = static function () use ($v67Say, $v67HasCol, $v67HasIdx, $v67Cols, $v67Idx): bool {
    foreach (array_keys($v67Cols) as $col) {
        if (!$v67HasCol('company_safe_entries', $col)) {
            $v67Say("✗ العمود company_safe_entries.$col ناقص."); return false;
        }
    }
    $v67Say('  ✓ كل أعمدة v67 موجودة (' . count($v67Cols) . ')');

    foreach ($v67Idx as $name => $spec) {
        if (!$v67HasIdx(explode(' ', $spec, 2)[0], $name)) {
            $v67Say("✗ الفهرس $name ناقص."); return false;
        }
    }
    $v67Say('  ✓ كل فهارس v67 موجودة');

    if ($v67HasCol('rep_custody_deposits', 'source')) {
        $type = (string) Database::scalar(
            "SELECT COLUMN_TYPE FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'rep_custody_deposits'
                AND column_name = 'source'");
        if (!str_contains($type, "'company'")) {
            $v67Say('✗ مصدر company مش في enum بتاع rep_custody_deposits.source.'); return false;
        }
        $v67Say('  ✓ مصدر company موجود في enum');

        // شرط بنيوي دائم: ولا قيمة مصدر خارج القايمة المعتمدة.
        $srcIn = implode(',', array_fill(0, count(RepCustody::SOURCES), '?'));
        $bad = Database::all(
            "SELECT DISTINCT source FROM rep_custody_deposits WHERE source NOT IN ($srcIn)",
            array_keys(RepCustody::SOURCES));
        if ($bad) {
            $v67Say('✗ مصدر تنزيل مجهول: ' . implode(', ', array_column($bad, 'source'))); return false;
        }
        $v67Say('  ✓ ولا مصدر تنزيل خارج القايمة');
    }

    /* ⚠️ الشرط ده بنيوي دائم مش لقطة: أي نوع حركة في الجدول لازم يكون في
       `CompanySafe::KINDS`. عدد العلامات محسوب من الثابت — كتابته بالإيد
       (زي `(?,?,?,?)` في v66) بتكسر الفحص أول ما نوع جديد يتضاف. */
    $in  = implode(',', array_fill(0, count(CompanySafe::KINDS), '?'));
    $bad = Database::all(
        "SELECT DISTINCT kind FROM company_safe_entries WHERE kind NOT IN ($in)",
        CompanySafe::KINDS);
    if ($bad) {
        $v67Say('✗ نوع حركة مجهول: ' . implode(', ', array_column($bad, 'kind'))); return false;
    }
    $v67Say('  ✓ ولا نوع حركة خارج القايمة المعتمدة (' . count(CompanySafe::KINDS) . ' نوع)');

    // فئة مسجّلة على غير مصروف = بيان مضلِّل، وفئة مجهولة = عرض «—» صامت.
    $badCat = Database::all(
        'SELECT DISTINCT category FROM company_safe_entries
          WHERE category IS NOT NULL AND category <> \'\'
            AND category NOT IN (' . implode(',', array_fill(0, count(CompanySafe::EXPENSE_CATS), '?')) . ')',
        array_keys(CompanySafe::EXPENSE_CATS));
    if ($badCat) {
        $v67Say('✗ فئة مصروف مجهولة: ' . implode(', ', array_column($badCat, 'category'))); return false;
    }
    $v67Say('  ✓ ولا فئة مصروف خارج القايمة');

    if (!CompanySafe::refreshReady()) { $v67Say('✗ CompanySafe::ready بترجّع false.'); return false; }
    $v67Say('  ✓ CompanySafe::ready فعّالة');
    return true;
};

if ($v67Direct) {
    echo 'v67 — خزنة الشركة الموسّعة' . ($v67Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v67Up();
    if ($v67Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v67Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v67.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v67Up, 'verify' => $v67Verify];
