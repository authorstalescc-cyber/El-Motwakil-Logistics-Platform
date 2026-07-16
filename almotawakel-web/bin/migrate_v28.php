<?php
/**
 * v28 — مطابقة التقفيل اليدوي مع مصروفات وإيصالات السيستم مجمّعة حسب العميل.
 *
 * يحفظ رأس مراجعة واحد لكل يوم، وصفًا لكل إذن تمت مراجعته. لا يغيّر أي حركة
 * مالية ولا يدخل في حساب الرصيد؛ هو طبقة تدقيق قبل تثبيت الإقفال فقط.
 * آمن لإعادة التشغيل (idempotent).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

Database::exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS daily_closing_reconciliations (
  close_date DATE PRIMARY KEY,
  manual_general_expenses DECIMAL(14,2) NOT NULL DEFAULT 0,
  note TEXT NULL,
  updated_by VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

Database::exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS daily_closing_reconciliation_clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  close_date DATE NOT NULL,
  client_key CHAR(64) NOT NULL,
  client_name VARCHAR(255) NOT NULL,
  manual_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_daily_recon_client (close_date, client_key),
  INDEX idx_daily_recon_client_name (client_name),
  INDEX idx_daily_recon_client_date (close_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

// تنظيف نسخة التطوير الأولى فقط إن كانت فارغة؛ لا نحذف أي بيانات مستخدم.
$oldExists = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='daily_closing_reconciliation_items'"
);
if ($oldExists && (int) Database::scalar('SELECT COUNT(*) FROM daily_closing_reconciliation_items') === 0) {
    Database::exec('DROP TABLE daily_closing_reconciliation_items');
}

echo "✓ v28: مطابقة التقفيل مجمّعة لكل عميل جاهزة\n";
