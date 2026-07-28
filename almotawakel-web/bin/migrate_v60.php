<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v60 — المندوب بيضيف بوليصة من موبايله، وعهدة السائق بتتخصم من عهدته هو.
 *
 * قرار المالك (سؤال مباشر 2026-07-27): شاشة «تسليم عهدة لسائق» بتتشال من بوابة
 * المندوب ومكانها **إضافة بوليصة**. والمندوب بيدخّل عهدة السائق في البوليصة
 * نفسها، و**المبلغ بيتخصم من عهدة المندوب مش من خزنة المكتب** — لأنه دفعها من
 * الفلوس النازلة له، مش من درج المكتب.
 *
 * العمود الوحيد الجديد: **`bawalis.custody_rep_id`** — وسم «مين دفع العهدة دي».
 *   • NULL  = خزنة المكتب (كل السجلات القديمة والبوالص اللي المكتب بيفتحها) —
 *             **صفر تغيير في أي رصيد قائم**.
 *   • رقم مندوب = العهدة طلعت من محفظة المندوب ده.
 *
 * ⚠️⚠️ **الاتجاهان لازم يمشوا مع بعض** (نفس تحذير v45 عن الفيزا): استثناء
 *    البوليصة من الخزنة **بلا** إضافتها لعهدة المندوب = **فلوس بتتبخّر من
 *    النظام**. المصدر الوحيد للشرطين هو `App\Models\RepBillCustody`:
 *      • `officeExcludeSql()` — بتتنادى من كل كود خزنة بيقرا `bawalis.custody`
 *      • `totalFor/totalsByRep/totalsByDay/totalAll/forRep` — الجانب التاني
 *    و`ready()` بترجّع false قبل الترحيل، فالنظام يفضل بسلوكه القديم بالحرف.
 *
 * ⚠️ **المخصوم من المندوب = العهدة كاملة، مش «العهدة − 100».** الـ100 دي قاعدة
 *    محاسبية بتخصّ خزنة المكتب (`Custody::billDeduction`)، والمندوب سلّم
 *    السائق المبلغ كامل من جيبه. ومحفظة المندوب معزولة تمامًا عن الخزنة (v35)
 *    فمفيش مطابقة بين الرقمين أصلاً.
 *
 * ⚠️ **مفيش أي أثر مالي من الترحيل نفسه** — العمود بيتضاف NULL لكل الصفوف،
 *    فرصيد الخزنة وعهدة كل مندوب قبل = بعد. الترحيل بيتحقق من ده صراحةً.
 *
 * التشغيل:  php bin/migrate_v60.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\RepCustody;

$v60Dry    = in_array('--dry-run', $argv ?? [], true);
$v60Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v60Say = static function (string $m) use ($v60Direct): void { if ($v60Direct) { echo $m . "\n"; } };

$v60HasCol = static function (string $t, string $c): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]
    );
};

/** لقطة كل الأرصدة اللي ممنوع تتغيّر — الخزنة + عهدة كل مندوب. */
$v60Snapshot = static function (): array {
    $out = ['safe' => round((float) Custody::balance(), 2), 'reps' => []];
    if (RepCustody::available()) {
        foreach (Database::all('SELECT id FROM reps') as $r) {
            $out['reps'][(int) $r['id']] = round(RepCustody::balance((int) $r['id']), 2);
        }
    }
    return $out;
};

$v60Up = static function () use ($v60Dry, $v60Say, $v60HasCol, $v60Snapshot): void {
    $before = $v60Snapshot();

    /* ⚠️ DDL برّه أي transaction — `ALTER TABLE` بيعمل commit ضمني في MySQL
       ومستحيل يترجع (مصيدة v59 الموثَّقة). والتعديل هنا **إضافي وidempotent
       بحت**: عمود nullable جديد + فهرس، مفيش فيه فقد بيانات نحتاج نرجع منه. */
    if ($v60Dry) {
        $v60Say('  (تجربة) تعديلات البنية اتخطّت — DDL مش بيترجع في MySQL');
    } elseif (!$v60HasCol('bawalis', 'custody_rep_id')) {
        Database::exec('ALTER TABLE bawalis ADD COLUMN custody_rep_id INT UNSIGNED NULL DEFAULT NULL');
        // الفهرس على (المندوب، التاريخ) لأن كل قراءات عهدة اليوم بتفلتر بالاتنين.
        Database::exec('ALTER TABLE bawalis ADD INDEX idx_bawali_custody_rep (custody_rep_id, bill_date)');
        $v60Say('  + bawalis.custody_rep_id (+ فهرس)');
    } else {
        $v60Say('  = bawalis.custody_rep_id (موجود)');
    }

    // بعد الـDDL: الكاش الساكن في RepBillCustody اتقرا قبل الإضافة (نفس مصيدة
    // v57 اللي فشّلت الترحيل على الإنتاج) — نصفّره عشان التحقّق تحت يقرا الصح.
    \App\Models\RepBillCustody::refreshReady();

    $after = $v60Snapshot();
    if (abs($after['safe'] - $before['safe']) > 0.005) {
        throw new RuntimeException('رصيد الخزنة اتغيّر بالترحيل — ' . $before['safe'] . ' ← ' . $after['safe']);
    }
    foreach ($before['reps'] as $rid => $bal) {
        if (abs(($after['reps'][$rid] ?? 0) - $bal) > 0.005) {
            throw new RuntimeException("عهدة المندوب #{$rid} اتغيّرت بالترحيل.");
        }
    }
    $v60Say('  ✓ رصيد الخزنة وعهدة كل مندوب ما اتغيّروش');
};

$v60Verify = static function () use ($v60Say, $v60HasCol): bool {
    if (!$v60HasCol('bawalis', 'custody_rep_id')) {
        $v60Say('✗ العمود custody_rep_id مش موجود.');
        return false;
    }
    // كل السجلات القديمة لازم تفضل «خزنة المكتب» — أي صف موسوم بعد الترحيل
    // مباشرةً معناه إن الترحيل كتب حاجة ما كانش المفروض يكتبها.
    $tagged = (int) Database::scalar('SELECT COUNT(*) FROM bawalis WHERE custody_rep_id IS NOT NULL');
    $v60Say("  ✓ العمود موجود · بوالص موسومة لمندوب: {$tagged}");

    // الشرط الواحد لازم يكون شغّال (مش فاضي) بعد الترحيل، وإلا الاستثناء مش بيسري.
    $clause = \App\Models\RepBillCustody::officeExcludeSql();
    if (strpos($clause, 'custody_rep_id') === false) {
        $v60Say('✗ RepBillCustody::officeExcludeSql لسه بترجّع شرطًا فاضيًا بعد الترحيل.');
        return false;
    }
    $v60Say('  ✓ شرط استثناء الخزنة فعّال');
    return true;
};

if ($v60Direct) {
    echo 'v60 — بوليصة المندوب + عهدة السائق من محفظته' . ($v60Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v60Up();
    if ($v60Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v60.\n"); exit(1); }
    echo ($v60Dry ? "\nشغّل من غير --dry-run عشان يتطبّق.\n" : "\n✓ تمّ.\n");
}

return ['up' => $v60Up, 'verify' => $v60Verify];
