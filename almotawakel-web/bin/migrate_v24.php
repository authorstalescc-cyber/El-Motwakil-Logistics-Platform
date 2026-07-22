<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v24 — إكمال/تعديل ملاحظات لوحة التحكم (dashboard_notes):
 *   • done    — هل الملاحظة مكتملة؟ (0/1)
 *   • done_at — وقت التعليم كمكتملة (NULL إن لم تكتمل)
 *
 * تُتيح تعديل نص/لون الملاحظة وتعليمها «مكتمل». معزولة تمامًا لا تدخل أي حساب.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v24.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

function colExists(string $table, string $col): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $col]
    );
}

function addCol(string $table, string $col, string $definition): void
{
    if (colExists($table, $col)) { say("• $table.$col موجود مسبقاً — تخطٍّ", 'y'); return; }
    Database::exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
    say("✓ $table.$col أُضيف", 'ok');
}

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 24", 'hi');
say("  (إكمال/تعديل ملاحظات لوحة التحكم)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

addCol('dashboard_notes', 'done',    "TINYINT(1) NOT NULL DEFAULT 0 AFTER `color`");
addCol('dashboard_notes', 'done_at', "TIMESTAMP NULL DEFAULT NULL AFTER `done`");

say("تم بنجاح.", 'ok');
