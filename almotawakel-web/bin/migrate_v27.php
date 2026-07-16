<?php
/**
 * v27 — طبقة أمان تجربة الاستخدام:
 *  - صلاحية مستقلة للدخول الحر.
 *  - سجل عمليات قبل/بعد.
 *  - سلة محذوفات عامة قابلة للاسترجاع.
 *
 * آمن لإعادة التشغيل (idempotent).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function v27ColumnExists(string $table, string $column): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    );
}

if (!v27ColumnExists('users', 'can_free_edit')) {
    Database::exec('ALTER TABLE users ADD COLUMN can_free_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER display_name');
}
// الحساب الأساسي مدير النظام؛ يُمنح الصلاحية افتراضيًا، وباقي المستخدمين من الإعدادات.
Database::exec('UPDATE users SET can_free_edit=1 WHERE id=(SELECT x.id FROM (SELECT MIN(id) id FROM users) x)');

Database::exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  action VARCHAR(30) NOT NULL,
  entity VARCHAR(80) NOT NULL,
  record_id VARCHAR(80) NULL,
  description VARCHAR(255) NULL,
  old_json LONGTEXT NULL,
  new_json LONGTEXT NULL,
  meta_json LONGTEXT NULL,
  can_undo TINYINT(1) NOT NULL DEFAULT 0,
  undone_at DATETIME NULL,
  undone_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_entity (entity, record_id),
  INDEX idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

Database::exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS deleted_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(80) NOT NULL,
  record_id VARCHAR(80) NULL,
  label VARCHAR(255) NULL,
  data_json LONGTEXT NOT NULL,
  related_json LONGTEXT NULL,
  deleted_by INT UNSIGNED NULL,
  deleted_by_name VARCHAR(120) NULL,
  restored_at DATETIME NULL,
  restored_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_deleted_created (created_at),
  INDEX idx_deleted_entity (entity, restored_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

echo "✓ v27: صلاحية الدخول الحر + سجل العمليات + سلة المحذوفات\n";
