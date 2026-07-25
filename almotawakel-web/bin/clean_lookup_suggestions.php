<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * تنظيف اقتراحات أسماء الإيصالات/المصروفات (جدول lookups) من أخطاء الإدخال.
 *
 * المندوب بيشوف اقتراحات لأسماء البنود من الأنواع mv_receipt / mv_expense /
 * mv_misc. أي قيمة فيها **رقم** = خطأ إدخال (الرقم كمية، والكمية ليها خانة
 * مستقلة منذ v17) — زي «٢ جيش هيئة» أو «نولون 1*20». السكربت ده بيشيلها،
 * وحارس Lookup::add بيمنع رجوعها. **بيمسّ جدول الاقتراحات فقط** — لا يلمس
 * أي بوليصة/إيصال/مصروف/إذن ولا أي حساب مالي.
 *
 *   php bin/clean_lookup_suggestions.php --dry-run   # يوري اللي هيتشال بلا حذف
 *   php bin/clean_lookup_suggestions.php             # يحذف فعليًا (transaction)
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

const NOTE_TYPES = ['mv_receipt', 'mv_expense', 'mv_misc'];

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--dry-run') {
        fwrite(STDERR, "معامل غير معروف: $arg\n");
        exit(1);
    }
}

/** القيمة فيها رقم (عربي/فارسي/إنجليزي) بعد التطبيع؟ */
$hasDigit = static fn(string $v): bool => (bool) preg_match('/[0-9]/', ar2en_digits($v));

$placeholders = implode(',', array_fill(0, count(NOTE_TYPES), '?'));
$rows = Database::all(
    "SELECT id, list_type, value FROM lookups WHERE list_type IN ($placeholders) ORDER BY list_type, value",
    NOTE_TYPES
);

$bad = array_values(array_filter($rows, static fn(array $r): bool => $hasDigit((string) $r['value'])));

echo ($dryRun ? '— تجربة (بلا حذف) —' : '— تنظيف فعلي —') . "\n";
echo 'قاعدة البيانات: ' . Database::scalar('SELECT DATABASE()') . "\n";
echo 'إجمالي اقتراحات الأسماء: ' . count($rows) . "\n";
echo 'اللي فيها رقم (هتتشال): ' . count($bad) . "\n";
foreach ($bad as $r) {
    echo '  ✗ #' . $r['id'] . '  [' . $r['list_type'] . ']  ' . $r['value'] . "\n";
}

if (!$bad) {
    echo "مفيش قيم فيها أرقام — الاقتراحات نضيفة.\n";
    exit(0);
}

if ($dryRun) {
    echo "\nمفيش حاجة اتحذفت. شغّل الأمر بلا --dry-run عشان التنفيذ.\n";
    exit(0);
}

$ids = array_map(static fn(array $r): int => (int) $r['id'], $bad);
$del = implode(',', array_fill(0, count($ids), '?'));
Database::conn()->beginTransaction();
try {
    $affected = Database::exec("DELETE FROM lookups WHERE id IN ($del)", $ids);
    Database::conn()->commit();
} catch (\Throwable $e) {
    if (Database::conn()->inTransaction()) { Database::conn()->rollBack(); }
    fwrite(STDERR, '✗ فشل الحذف: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "\n✓ اتشال $affected اقتراح فيه أرقام. الاقتراحات بقت نضيفة.\n";
