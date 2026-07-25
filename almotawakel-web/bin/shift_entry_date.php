<?php
/**
 * نقل المصروفات والإيصالات من يوم لليوم اللي قبله (أداة لمرة واحدة).
 * ---------------------------------------------------------------
 *   php bin/shift_entry_date.php --from=2026-07-23 --to=2026-07-22            # مسح فقط (الافتراضي)
 *   php bin/shift_entry_date.php --from=2026-07-23 --to=2026-07-22 --apply    # التنفيذ داخل transaction
 *
 * النطاق: المصروفات والإيصالات اللي بتتسجّل يوميًّا فقط —
 *   manual_expenses.exp_date      (مصروفات يدوية)
 *   permit_stages.entry_date      (إيصالات ومصروفات الأذونات)
 *   rep_misc_expenses.exp_date    (نثريات المناديب)
 *   rep_submissions.entry_date    (بنود المناديب — عشان تفضل متسقة مع البنود المعتمدة)
 *
 * ما بيلمسش: البوالص والحركات والتخليص والشامل وسداد المديونيات وأي دفعات —
 * دول تواريخهم تابعة لمستندات مش لإدخال يومي.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

/* ── المعاملات ─────────────────────────────────────────────── */
$opts  = getopt('', ['from:', 'to:', 'apply']);
$from  = $opts['from'] ?? null;
$to    = $opts['to']   ?? null;
$apply = isset($opts['apply']);

$isDate = static fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)
                        && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4));

if (!$isDate($from) || !$isDate($to)) {
    fwrite(STDERR, "استخدم: php bin/shift_entry_date.php --from=YYYY-MM-DD --to=YYYY-MM-DD [--apply]\n");
    exit(1);
}
if ($from === $to) { fwrite(STDERR, "التاريخان متطابقان — مفيش حاجة تتعمل.\n"); exit(1); }

/* ── الجداول المشمولة ──────────────────────────────────────── */
$targets = [
    ['manual_expenses',   'exp_date',   'مصروفات يدوية'],
    ['permit_stages',     'entry_date', 'إيصالات ومصروفات الأذونات'],
    ['rep_misc_expenses', 'exp_date',   'نثريات المناديب'],
    ['rep_submissions',   'entry_date', 'بنود المناديب (صندوق المراجعة)'],
];

$exists = static function (string $t): bool {
    return (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?", [$t]
    ) > 0;
};

echo "قاعدة البيانات: " . Database::scalar('SELECT DATABASE()') . "\n";
echo "نقل من {$from} إلى {$to}" . ($apply ? "  [تنفيذ]" : "  [مسح فقط]") . "\n";
echo str_repeat('─', 62) . "\n";

/* ── حالة التقفيل اليومي للطرفين ───────────────────────────── */
if ($exists('daily_closings')) {
    foreach ([$from, $to] as $d) {
        // v18: وجود صف لتاريخ في daily_closings = اليوم ده متقفل
        $row = Database::row("SELECT * FROM daily_closings WHERE close_date = ?", [$d]);
        echo "التقفيل اليومي {$d}: " . ($row ? '🔒 متقفل' : 'مفتوح') . "\n";
    }
    echo str_repeat('─', 62) . "\n";
}

/* ── المسح ─────────────────────────────────────────────────── */
$total = 0;
$plan  = [];

foreach ($targets as [$table, $col, $label]) {
    if (!$exists($table)) { echo "— {$label} ({$table}): الجدول مش موجود، اتخطّى\n"; continue; }

    $n   = (int) Database::scalar("SELECT COUNT(*) FROM {$table} WHERE {$col} = ?", [$from]);
    $sum = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE {$col} = ?", [$from]);

    echo sprintf("%-34s %3d بند   %12s ج.م\n", $label, $n, number_format($sum, 2));

    if ($n > 0) {
        $rows = Database::all("SELECT id, amount, note FROM {$table} WHERE {$col} = ? ORDER BY id", [$from]);
        foreach ($rows as $r) {
            echo sprintf("      #%-6s %10s   %s\n",
                $r['id'], number_format((float)$r['amount'], 2), mb_substr((string)($r['note'] ?? ''), 0, 40));
        }
        $plan[] = [$table, $col, $label, $n];
        $total += $n;
    }
}

echo str_repeat('─', 62) . "\n";
echo "الإجمالي: {$total} بند\n";

if ($total === 0)  { echo "مفيش أي بند بتاريخ {$from} — مفيش حاجة تتعمل.\n"; exit(0); }
if (!$apply)       { echo "\nده مسح فقط. للتنفيذ ضيف --apply\n"; exit(0); }

/* ── التنفيذ ───────────────────────────────────────────────── */
Database::conn()->beginTransaction();
try {
    $done = 0;
    foreach ($plan as [$table, $col, $label, $expected]) {
        $n = Database::exec("UPDATE {$table} SET {$col} = ? WHERE {$col} = ?", [$to, $from]);
        if ((int)$n !== (int)$expected) {
            throw new RuntimeException("{$table}: اتغيّر {$n} صف والمتوقّع {$expected} — تم الإلغاء.");
        }
        echo "✔ {$label}: {$n} بند\n";
        $done += (int)$n;
    }

    // تأكيد إن مفيش بقايا على التاريخ القديم
    foreach ($plan as [$table, $col]) {
        $left = (int) Database::scalar("SELECT COUNT(*) FROM {$table} WHERE {$col} = ?", [$from]);
        if ($left !== 0) throw new RuntimeException("{$table}: فاضل {$left} صف على {$from} — تم الإلغاء.");
    }

    Database::conn()->commit();
    echo str_repeat('─', 62) . "\n";
    echo "✅ تمّ نقل {$done} بند من {$from} إلى {$to}.\n";
} catch (Throwable $e) {
    Database::conn()->rollBack();
    fwrite(STDERR, "❌ فشل: " . $e->getMessage() . "\nاترجع كل حاجة زي ما كانت.\n");
    exit(1);
}
