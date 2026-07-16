<?php
/**
 * ترحيل v30 — توحيد «تصنيف الحاويات» (فك العدد المدموج).
 *
 * المشكلة: النظام القديم كان يخزّن العدد والتصنيف في نصّ واحد («1*40»، «2*20»)
 * بينما النظام الجديد يفصل «عدد الحاويات» (رقم) عن «تصنيف الحاويات» (وصف المقاس).
 * فتظهر السجلات القديمة متعارضة/مكرّرة العدد («1*40 × 1»).
 *
 * هذا الترحيل يوحّد نصّ التصنيف فقط عبر container_norm() (المصدر الوحيد للحقيقة
 * في app/Core/helpers.php) — **دون أن يلمس عدد الحاويات ولا أي قيمة مالية**.
 * (تحقّقنا مسبقًا أن container_count لكل السجلات المدموجة = بادئة العدد بالفعل.)
 *
 * النطاق:
 *   • bawalis.container_type      (المشكلة الأساسية — ~1733 سجل)
 *   • bawalis.classification      (نُسخت فيها نفس القيمة القديمة — بقرار المالك)
 *   • permits.container_type      (سجل واحد ملوّث)
 *   • permit_movements.container_type
 *   • owner_statements.items_json (6 لقطات كشوف محفوظة — بقرار المالك)
 *   • lookups                     (إزالة القيم الملوّثة + إضافة «45 قدم»)
 *
 * legacy_bawalis (الأرشيف الخام) **لا يُلمَس** لأنه مصدر إعادة الاستيراد
 * وعدّاد الحاويات يُشتق من بادئة «العدد*» — يُوحَّد عرضه فقط في /legacy.
 *
 * آمن لإعادة التشغيل (idempotent): القيم الموحّدة تمرّ دون تغيير.
 * معاملة واحدة (transaction): إمّا الكل أو لا شيء.
 *
 * التشغيل:
 *   php bin/migrate_v30_container_types.php --dry-run   # معاينة بلا كتابة
 *   php bin/migrate_v30_container_types.php             # تنفيذ
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل v30 — توحيد تصنيف الحاويات", 'hi');
say($DRY ? "  [وضع المعاينة — لن تحدث أي كتابة]" : "  [تنفيذ فعلي]", $DRY ? 'y' : 'ok');
say("═══════════════════════════════════════════════", 'hi');

$pdo = Database::conn();

/** لقطة تحقّق قبلية: مجموع الأعداد والمبالغ التي يجب ألّا تتغيّر إطلاقًا. */
$guardBefore = [
    'bawalis_rows'      => (int) Database::scalar("SELECT COUNT(*) FROM bawalis"),
    'bawalis_count_sum' => (int) Database::scalar("SELECT COALESCE(SUM(container_count),0) FROM bawalis"),
    'bawalis_freight'   => (string) Database::scalar("SELECT COALESCE(SUM(freight),0) FROM bawalis"),
    'bawalis_payment'   => (string) Database::scalar("SELECT COALESCE(SUM(payment),0) FROM bawalis"),
    'bawalis_remaining' => (string) Database::scalar("SELECT COALESCE(SUM(remaining),0) FROM bawalis"),
    'permits_rows'      => (int) Database::scalar("SELECT COUNT(*) FROM permits"),
    'permits_cont_sum'  => (int) Database::scalar("SELECT COALESCE(SUM(containers),0) FROM permits"),
    'mv_count_sum'      => (int) Database::scalar("SELECT COALESCE(SUM(container_count),0) FROM permit_movements"),
];

/**
 * يوحّد عمودًا نصّيًا في جدول عبر container_norm() صفًّا صفًّا.
 * يُحدّث فقط الصفوف التي تتغيّر قيمتها فعلاً. يرجع [عدد المفحوص, عدد المتغيّر, أمثلة].
 */
function normalizeColumn(PDO $pdo, string $table, string $idCol, string $col, bool $dry): array
{
    $rows = Database::all("SELECT `$idCol` AS id, `$col` AS val FROM `$table`
                            WHERE `$col` IS NOT NULL AND `$col` <> ''");
    $changed = 0; $samples = [];
    $st = $dry ? null : $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE `$idCol` = ?");
    foreach ($rows as $r) {
        $old = (string) $r['val'];
        $new = container_norm($old);
        if ($new === null) { $new = ''; }
        if ($new !== $old) {
            $changed++;
            if (count($samples) < 8) { $samples[$old] = $new; }
            if (!$dry) { $st->execute([$new, $r['id']]); }
        }
    }
    return [count($rows), $changed, $samples];
}

/** يوحّد تصنيف/تصنيف-حاويات كل بند داخل لقطة كشف (items_json). */
function normalizeStatementJson(PDO $pdo, bool $dry): array
{
    $rows = Database::all("SELECT id, items_json FROM owner_statements
                            WHERE items_json IS NOT NULL AND items_json <> ''");
    $changedRows = 0; $changedItems = 0; $samples = [];
    $st = $dry ? null : $pdo->prepare("UPDATE owner_statements SET items_json = ? WHERE id = ?");
    foreach ($rows as $r) {
        $data = json_decode($r['items_json'], true);
        if (!is_array($data)) { continue; }
        $rowTouched = false;
        foreach (['paid', 'remaining'] as $bucket) {
            if (empty($data[$bucket]) || !is_array($data[$bucket])) { continue; }
            foreach ($data[$bucket] as &$item) {
                if (!is_array($item)) { continue; }
                foreach (['container_type', 'classification'] as $k) {
                    if (!isset($item[$k]) || $item[$k] === null || $item[$k] === '') { continue; }
                    $old = (string) $item[$k];
                    $new = container_norm($old);
                    if ($new !== null && $new !== $old) {
                        if (count($samples) < 8) { $samples[$old] = $new; }
                        $item[$k] = $new; $rowTouched = true; $changedItems++;
                    }
                }
            }
            unset($item);
        }
        if ($rowTouched) {
            $changedRows++;
            if (!$dry) { $st->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $r['id']]); }
        }
    }
    return [count($rows), $changedRows, $changedItems, $samples];
}

/** إزالة قيم القائمة المنسدلة الملوّثة وإضافة القيم القانونية الناقصة. */
function fixLookups(PDO $pdo, bool $dry): array
{
    $polluted = Database::all(
        "SELECT id, value FROM lookups WHERE list_type='container_type'
          AND value REGEXP '[0-9]+[*x× ]*[0-9]'"  // أي قيمة فيها بادئة عدّ مثل «1*40»
    );
    // نُزيل فقط القيم ذات نمط «عدد*...» — نتحقّق يدويًا عبر container_norm
    $removed = []; $added = [];
    foreach ($polluted as $p) {
        $norm = container_norm($p['value']);
        if ($norm !== $p['value']) { $removed[] = $p; }
    }
    if (!$dry) {
        foreach ($removed as $p) {
            Database::exec("DELETE FROM lookups WHERE id=?", [$p['id']]);
        }
    }
    // نضمن وجود القيم القانونية التي ستظهر بعد التوحيد
    $canonical = ['20 قدم','40 قدم','45 قدم','40 مبرّد','20 مبرّد','تانك','أوبن توب','فلات راك'];
    foreach ($canonical as $v) {
        $exists = (int) Database::scalar(
            "SELECT COUNT(*) FROM lookups WHERE list_type='container_type' AND value=?", [$v]);
        if (!$exists) {
            $added[] = $v;
            if (!$dry) { Database::exec("INSERT INTO lookups (list_type, value) VALUES ('container_type', ?)", [$v]); }
        }
    }
    return [$removed, $added];
}

if (!$DRY) { $pdo->beginTransaction(); }
try {
    // 1) bawalis.container_type
    say("\n── bawalis.container_type ──", 'hi');
    [$scanned, $changed, $samples] = normalizeColumn($pdo, 'bawalis', 'id', 'container_type', $DRY);
    say("  فُحص: $scanned | سيُوحَّد: $changed");
    foreach ($samples as $o=>$n) { say("    «{$o}» → «{$n}»", 'y'); }

    // 2) bawalis.classification
    say("\n── bawalis.classification ──", 'hi');
    [$scanned, $changed, $samples] = normalizeColumn($pdo, 'bawalis', 'id', 'classification', $DRY);
    say("  فُحص: $scanned | سيُوحَّد: $changed");
    foreach ($samples as $o=>$n) { say("    «{$o}» → «{$n}»", 'y'); }

    // 3) permits.container_type
    say("\n── permits.container_type ──", 'hi');
    [$scanned, $changed, $samples] = normalizeColumn($pdo, 'permits', 'id', 'container_type', $DRY);
    say("  فُحص: $scanned | سيُوحَّد: $changed");
    foreach ($samples as $o=>$n) { say("    «{$o}» → «{$n}»", 'y'); }

    // 4) permit_movements.container_type
    say("\n── permit_movements.container_type ──", 'hi');
    [$scanned, $changed, $samples] = normalizeColumn($pdo, 'permit_movements', 'id', 'container_type', $DRY);
    say("  فُحص: $scanned | سيُوحَّد: $changed");
    foreach ($samples as $o=>$n) { say("    «{$o}» → «{$n}»", 'y'); }

    // 5) owner_statements.items_json (لقطات محفوظة)
    say("\n── owner_statements.items_json ──", 'hi');
    [$scanned, $cr, $ci, $samples] = normalizeStatementJson($pdo, $DRY);
    say("  فُحص: $scanned كشف | كشوف ستتغيّر: $cr | بنود ستُوحَّد: $ci");
    foreach ($samples as $o=>$n) { say("    «{$o}» → «{$n}»", 'y'); }

    // 6) lookups
    say("\n── lookups (القائمة المنسدلة) ──", 'hi');
    [$removed, $added] = fixLookups($pdo, $DRY);
    foreach ($removed as $p) { say("    حذف قيمة ملوّثة: «{$p['value']}»", 'y'); }
    foreach ($added as $v)    { say("    إضافة قيمة قانونية: «{$v}»", 'ok'); }
    if (!$removed && !$added)  { say("    لا تغيير في القائمة", 'y'); }

    // ── تحقّق صارم: الأعداد والمبالغ لم تتغيّر ──
    $guardAfter = [
        'bawalis_rows'      => (int) Database::scalar("SELECT COUNT(*) FROM bawalis"),
        'bawalis_count_sum' => (int) Database::scalar("SELECT COALESCE(SUM(container_count),0) FROM bawalis"),
        'bawalis_freight'   => (string) Database::scalar("SELECT COALESCE(SUM(freight),0) FROM bawalis"),
        'bawalis_payment'   => (string) Database::scalar("SELECT COALESCE(SUM(payment),0) FROM bawalis"),
        'bawalis_remaining' => (string) Database::scalar("SELECT COALESCE(SUM(remaining),0) FROM bawalis"),
        'permits_rows'      => (int) Database::scalar("SELECT COUNT(*) FROM permits"),
        'permits_cont_sum'  => (int) Database::scalar("SELECT COALESCE(SUM(containers),0) FROM permits"),
        'mv_count_sum'      => (int) Database::scalar("SELECT COALESCE(SUM(container_count),0) FROM permit_movements"),
    ];
    say("\n── تحقّق السلامة (يجب أن تتطابق القيم) ──", 'hi');
    $ok = true;
    foreach ($guardBefore as $k=>$v) {
        $match = ((string)$v === (string)$guardAfter[$k]);
        $ok = $ok && $match;
        say(sprintf("  %-20s قبل=%s  بعد=%s  %s", $k, $v, $guardAfter[$k], $match ? '✓' : '✗ اختلاف!'),
            $match ? 'ok' : 'err');
    }
    if (!$ok) { throw new RuntimeException('فشل تحقّق السلامة: تغيّرت قيمة عددية/مالية — إلغاء.'); }

    if ($DRY) {
        say("\n✓ المعاينة انتهت — لم تُكتب أي بيانات. أعد التشغيل بلا --dry-run للتنفيذ.", 'ok');
    } else {
        $pdo->commit();
        say("\n✓ تم التوحيد بنجاح داخل معاملة واحدة.", 'ok');
    }
} catch (\Throwable $e) {
    if (!$DRY && $pdo->inTransaction()) { $pdo->rollBack(); }
    say("\n✗ خطأ — تم التراجع عن كل التغييرات: " . $e->getMessage(), 'err');
    exit(1);
}

say("═══════════════════════════════════════════════", 'hi');
