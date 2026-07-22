<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v29 — توثيق تاريخ العمل وتاريخ المستند في سجل العمليات.
 *
 * العمودان منفصلان عمدًا:
 *  - work_date: التاريخ المختار للجلسة وقت الإدخال.
 *  - record_date: التاريخ المحفوظ على المستند نفسه.
 * وبذلك يمكن اكتشاف أي إدخال سُجّل على يوم مختلف فورًا من سجل الإدخالات.
 * آمن لإعادة التشغيل (idempotent).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function v29ColumnExists(string $table, string $column): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    );
}

function v29IndexExists(string $table, string $index): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
        [$table, $index]
    );
}

if (!v29ColumnExists('audit_logs', 'work_date')) {
    Database::exec('ALTER TABLE audit_logs ADD COLUMN work_date DATE NULL AFTER meta_json');
}
if (!v29ColumnExists('audit_logs', 'record_date')) {
    Database::exec('ALTER TABLE audit_logs ADD COLUMN record_date DATE NULL AFTER work_date');
}
if (!v29IndexExists('audit_logs', 'idx_audit_work_date')) {
    Database::exec('ALTER TABLE audit_logs ADD INDEX idx_audit_work_date (work_date, action)');
}
if (!v29IndexExists('audit_logs', 'idx_audit_record_date')) {
    Database::exec('ALTER TABLE audit_logs ADD INDEX idx_audit_record_date (record_date, action)');
}

echo "✓ v29: سجل الإدخالات وتدقيق تاريخ العمل جاهزان\n";
