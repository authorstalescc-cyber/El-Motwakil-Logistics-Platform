<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * استيراد أرشيف البوالص القديمة إلى جدول legacy_bawalis.
 *
 *   يقرأ database/legacy_bawalis.json (المُنتَج من database/export_legacy_bawalis.py)
 *   ويملأ جدول legacy_bawalis. الجدول أرشيف معزول — لا يمسّ أي حساب في النظام.
 *
 *   آمن لإعادة التشغيل: يفرّغ الجدول ثم يعيد التحميل بالكامل داخل معاملة واحدة
 *   (idempotent — تشغيله عشر مرات = نفس النتيجة). يتطلّب migrate_v15 أولاً.
 *
 * التشغيل:  php bin/import_legacy_bawalis.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$pdo  = Database::conn();
$file = dirname(__DIR__) . '/database/legacy_bawalis.json';

say("═══════════════════════════════════════════════", 'hi');
say("  استيراد أرشيف البوالص القديمة → legacy_bawalis", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// تحقّق من وجود الجدول (يعني migrate_v15 اتشغّل)
$exists = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='legacy_bawalis'");
if (!$exists) {
    say("✗ الجدول legacy_bawalis غير موجود. شغّل أولاً:  php bin/migrate_v15.php", 'err');
    exit(1);
}
if (!is_file($file)) {
    say("✗ الملف غير موجود: $file", 'err');
    say("  أنتِجه أولاً:  python3 database/export_legacy_bawalis.py", 'y');
    exit(1);
}

$data = json_decode(file_get_contents($file), true);
$rows = $data['bawalis'] ?? null;
if (!is_array($rows)) {
    say("✗ صيغة JSON غير صحيحة (لا يوجد مفتاح bawalis)", 'err');
    exit(1);
}
say("• قُرئ " . count($rows) . " سجل من الملف", 'y');

// ─── استبعاد البوالص المسجّلة فعلاً في نظام البوالص الحي (جدول bawalis) ───
// شيت الإكسل القديم استمرّ يُكتَب فيه بالتوازي بعد بدء النظام الحي، فآخر بوالصه
// مكرّرة في النظامين. مصدر الحقيقة لها هو النظام الحي — تدخل الأرشيفَ فقط
// البوالص التي لا يعرفها النظام الحي. القاعدة: رقم بوليصة موجود في bawalis
// + تاريخ ≥ أول يوم في النظام الحي ⇒ مكرّرة تُستبعَد. (المطابقة بالرقم وحده لا
// تكفي نظريًّا لأن الأرقام قد تتكرّر تاريخيًّا قبل بدء النظام الحي.)
$liveNums = array_column(Database::all(
    "SELECT DISTINCT TRIM(bill_number) n FROM bawalis
      WHERE bill_number IS NOT NULL AND TRIM(bill_number) <> ''"), 'n');
$liveSet  = array_flip($liveNums);
$liveMin  = Database::scalar("SELECT MIN(bill_date) FROM bawalis");

$kept = []; $skipped = [];
foreach ($rows as $r) {
    $no = trim((string)($r['bill_number'] ?? ''));
    $dup = $no !== '' && isset($liveSet[$no]) && $liveMin !== null
        && ($r['bill_date'] ?? null) !== null && $r['bill_date'] >= $liveMin;
    if ($dup) { $skipped[] = $no; } else { $kept[] = $r; }
}
$rows = $kept;
if ($skipped) {
    sort($skipped, SORT_NATURAL);
    say("• استُبعد " . count($skipped) . " بوليصة موجودة فعلاً في النظام الحي (bawalis منذ $liveMin):", 'y');
    say("  " . implode('، ', $skipped), 'y');
} else {
    say("• لا بوالص مكرّرة مع النظام الحي.", 'y');
}

// الأعمدة (نفس ترتيب INSERT) — كل ما عدا id/created_at
$cols = [
    'src_row','declaration_owner','bill_date','bill_number','classification','company',
    'destination','container_no','car_no','driver','car_owner',
    'freight','custody','transfer','discount','overnight_collection','remaining','payment','total',
    'driver_share','assistant_share','overnight_expense','expense','cards','tahia_masr','thabtan',
    'z_kawtsh','permits_fee','kawtsh','other_expense','gas',
    'total_expense','custody_remaining','car_net',
];
$moneyCols = array_flip([
    'freight','custody','transfer','discount','overnight_collection','remaining','payment','total',
    'driver_share','assistant_share','overnight_expense','expense','cards','tahia_masr','thabtan',
    'z_kawtsh','permits_fee','kawtsh','other_expense','gas','total_expense','custody_remaining','car_net',
]);

$placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
$sql = "INSERT INTO legacy_bawalis (`" . implode('`,`', $cols) . "`) VALUES $placeholders";

$pdo->beginTransaction();
try {
    // DELETE (وليس TRUNCATE) — TRUNCATE يسبّب implicit commit وينهي المعاملة
    $pdo->exec("DELETE FROM legacy_bawalis");
    $st = $pdo->prepare($sql);
    $n = 0;
    foreach ($rows as $r) {
        $params = [];
        foreach ($cols as $c) {
            $v = $r[$c] ?? null;
            if (isset($moneyCols[$c])) { $v = (float) ($v ?? 0); }
            $params[] = $v;
        }
        $st->execute($params);
        $n++;
    }
    $pdo->commit();
    say("✓ أُدرِج $n بوليصة في legacy_bawalis (بعد تفريغ الجدول)", 'ok');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    say("✗ فشل الاستيراد — تراجعت المعاملة: " . $e->getMessage(), 'err');
    exit(1);
}

// ملخّص تحقّق (أرشيف فقط — لا يؤثّر على أي حساب)
$cnt   = (int)   Database::scalar("SELECT COUNT(*) FROM legacy_bawalis");
$minD  = (string)Database::scalar("SELECT MIN(bill_date) FROM legacy_bawalis");
$maxD  = (string)Database::scalar("SELECT MAX(bill_date) FROM legacy_bawalis");
$sumF  = (float) Database::scalar("SELECT SUM(freight) FROM legacy_bawalis");
$sumC  = (float) Database::scalar("SELECT SUM(custody) FROM legacy_bawalis");
say("═══════════════════════════════════════════════", 'hi');
say("  الإجمالي: $cnt بوليصة   |   التواريخ: $minD → $maxD", 'y');
say("  Σ النولون = " . number_format($sumF) . "   Σ العهدة = " . number_format($sumC), 'y');
say("  (أرشيف معزول — العهدة والتقفيل والحسابات الحالية لم تتغيّر)", 'ok');
say("═══════════════════════════════════════════════", 'hi');
