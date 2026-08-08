<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v61 — «الفلوس دي جاية منين؟» على تنزيل عهدة المندوب (المرحلة ٢ من docs/16).
 *
 * ═══ المشكلة اللي العمود ده بيقفلها ═══
 * النظام اتبنى على افتراض إن الكاش بيمشي **بنك ← درج المكتب ← توزيع**. الواقع
 * (بشهادة المالك 2026-07-30): المدير بيسحب من البنك ويسلّم **المندوب على طول**،
 * والفلوس دي **عمرها ما تدخل الدرج**. عشان الرصيد يفضل صح، كان بيتسجّل صفّان
 * لنفس السحب: `manual_custody` (توب-أب للخزنة) + `rep_custody_deposits` (تنزيل
 * للمندوب) — ومقيس على الإنتاج إن الرقمين **متطابقين بالجنيه** يومَي 26 و27/7
 * (124,000 و132,560). الازدواج ده كان بيلغي نفسه حسابيًّا في الخزنة، فالرقم
 * فضل قريب من الحقيقة، لكنه بقى **رقم مجمَّع** (درج + جيوب المناديب) مش كاش
 * الدرج — وده اللي بيخلّي المالك مش قادر يعدّ الدرج ويطابقه.
 *
 * العمود الوحيد الجديد: **`rep_custody_deposits.source`**
 *   • `bank`   = اتسحبت من البنك واتسلّمت للمندوب على طول → **مالهاش أثر على
 *                الخزنة**. الافتراضي، والحالة الغالبة (قرار المالك Q2: كل عهد
 *                يوم 29/7 التلاتة من البنك).
 *   • `office` = طلعت من درج المكتب → **بتتخصم من الخزنة يوم التنزيل**.
 *   • `carry`  = الكاش اللي كان مع المندوب قبل خط الفصل (رصيد افتتاحي) →
 *                **مالهاش أثر على الخزنة** لأن الخزنة حسبته قبل الخط.
 *
 * ⚠️ ليه `carry` منفصلة عن `bank` وميكانيكتهم واحدة: المعنى مختلف، والفرق ده
 *    لازم يبان في مطابقة الدرج وفي سجل التدقيق — «جاية من البنك» ≠ «كانت معاه
 *    من الأول». تُستخدم **مرة واحدة** عند خط الفصل (Q4 في docs/16).
 *
 * ⚠️⚠️ **مفيش أي أثر مالي من الترحيل نفسه.** كل الصفوف القديمة بتاخد `bank`
 *    وهي الحالة اللي **مالهاش أثر على الخزنة** = سلوك v42 الحالي للتنزيل بالحرف
 *    (التنزيل مالوش أثر). فالترحيل **إضافة قدرة بس**، وقلب القاعدة نفسه في
 *    الكود ومحكوم بخط الفصل. الترحيل بيتحقق من ده صراحةً.
 *
 * التشغيل:  php bin/migrate_v61.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\RepCustody;

$v61Dry    = in_array('--dry-run', $argv ?? [], true);
$v61Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v61Say = static function (string $m) use ($v61Direct): void { if ($v61Direct) { echo $m . "\n"; } };

$v61HasCol = static function (string $t, string $c): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]
    );
};

/** لقطة كل رقم ممنوع يتغيّر: الخزنة + عهدة كل مندوب + كل صفوف الإقفال. */
$v61Snapshot = static function (): array {
    $out = [
        'safe'     => round((float) Custody::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
        'reps'     => [],
    ];
    if (RepCustody::available()) {
        foreach (Database::all('SELECT id FROM reps') as $r) {
            $out['reps'][(int) $r['id']] = round(RepCustody::balance((int) $r['id']), 2);
        }
    }
    return $out;
};

$v61Up = static function () use ($v61Dry, $v61Say, $v61HasCol, $v61Snapshot): void {
    if (!Database::scalar("SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema = DATABASE() AND table_name = 'rep_custody_deposits'")) {
        throw new RuntimeException('جدول rep_custody_deposits مش موجود — شغّل migrate_v35 الأول.');
    }

    $before = $v61Snapshot();

    /* ⚠️ DDL برّه أي transaction — `ALTER TABLE` بيعمل commit ضمني في MySQL
       ومستحيل يترجع (مصيدة v59 الموثَّقة). والتعديل **إضافي وidempotent بحت**:
       عمود جديد بافتراضي بيساوي السلوك القائم، مفيش فقد بيانات نرجع منه. */
    if ($v61Dry) {
        $v61Say('  (تجربة) تعديلات البنية اتخطّت — DDL مش بيترجع في MySQL');
    } elseif (!$v61HasCol('rep_custody_deposits', 'source')) {
        Database::exec(
            "ALTER TABLE rep_custody_deposits
             ADD COLUMN source ENUM('bank','office','carry') NOT NULL DEFAULT 'bank'");
        // كل قراءات الخزنة الجديدة بتفلتر (المصدر، التاريخ) مع بعض.
        Database::exec('ALTER TABLE rep_custody_deposits ADD INDEX idx_repdep_source (source, dep_date)');
        $v61Say('  + rep_custody_deposits.source (+ فهرس)');
    } else {
        $v61Say('  = rep_custody_deposits.source (موجود)');
    }

    /* ⚠️ تصفير الكاش الساكن **إلزامي** — `RepCustody::sourceReady()` اتقرت قبل
       الـALTER (وقت لقطة الأرصدة) فبتفضل false بعده، فتحقّق ما بعد الترحيل يفشل
       و**الإصدار ما يتسجّلش في الـledger رغم إن العمود اتضاف فعلاً**. ده بالظبط
       اللي حصل في v57 على الإنتاج. */
    RepCustody::refreshSourceReady();

    $after = $v61Snapshot();
    if (abs($after['safe'] - $before['safe']) > 0.005) {
        throw new RuntimeException('رصيد الخزنة اتغيّر بالترحيل — '
            . $before['safe'] . ' ← ' . $after['safe']);
    }
    if ($after['closings'] !== $before['closings']) {
        throw new RuntimeException('صف إقفال اتغيّر بالترحيل — الرصيد المرحّل بيتعاد حسابه!');
    }
    foreach ($before['reps'] as $rid => $bal) {
        if (abs(($after['reps'][$rid] ?? 0) - $bal) > 0.005) {
            throw new RuntimeException("عهدة المندوب #{$rid} اتغيّرت بالترحيل.");
        }
    }
    $v61Say('  ✓ الخزنة وعهدة كل مندوب وكل صفوف الإقفال ما اتغيّروش');
};

$v61Verify = static function () use ($v61Say, $v61HasCol): bool {
    if (!$v61HasCol('rep_custody_deposits', 'source')) {
        $v61Say('✗ العمود source مش موجود.');
        return false;
    }
    // كل الصفوف القديمة لازم تفضل `bank` — أي صف `office` بعد الترحيل مباشرةً
    // معناه إن الترحيل كتب حاجة ما كانش المفروض يكتبها (وكان هيخصم من الخزنة).
    $rows = [];
    foreach (Database::all('SELECT source, COUNT(*) c FROM rep_custody_deposits GROUP BY source') as $r) {
        $rows[(string) $r['source']] = (int) $r['c'];
    }
    if (($rows['office'] ?? 0) > 0 || ($rows['carry'] ?? 0) > 0) {
        $v61Say('✗ فيه صفوف مش `bank` بعد الترحيل — الترحيل ما كانش المفروض يوسم حاجة.');
        return false;
    }
    $v61Say('  ✓ كل التنزيلات القديمة `bank` (' . ($rows['bank'] ?? 0) . ' صف) — صفر أثر مالي');

    // الشرط الواحد لازم يبقى فعّالًا بعد الترحيل، وإلا القاعدة الجديدة مش بتسري.
    if (!RepCustody::sourceReady()) {
        $v61Say('✗ RepCustody::sourceReady لسه بترجّع false بعد الترحيل (كاش ساكن؟).');
        return false;
    }
    $v61Say('  ✓ RepCustody::sourceReady فعّالة');
    return true;
};

if ($v61Direct) {
    echo 'v61 — مصدر عهدة المندوب (بنك / درج / مرحَّل)' . ($v61Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v61Up();
    if ($v61Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v61.\n"); exit(1); }
    echo ($v61Dry ? "\nشغّل من غير --dry-run عشان يتطبّق.\n" : "\n✓ تمّ.\n");
}

return ['up' => $v61Up, 'verify' => $v61Verify];
