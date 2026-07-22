<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v31 — سلامة بيانات الأطراف ودورة الفاتورة والتحصيل.
 *
 * المبادئ:
 * - الأعمدة النصية القديمة تظل لقطات تاريخية، وتُضاف بجانبها مفاتيح master data.
 * - المستند المالي لا يُحذف بعد الإصدار؛ يُلغى، والتحصيل يُعكس.
 * - التخصيصات تربط التحصيلات بالفواتير، مع رصيد غير مخصص.
 * - lock_version يمنع الكتابة فوق تعديل متزامن في المسارات الجديدة.
 *
 * آمن لإعادة التشغيل.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function v31Say(string $message): void { echo $message . PHP_EOL; }

function v31ColumnExists(string $table, string $column): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    );
}

function v31IndexExists(string $table, string $index): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
        [$table, $index]
    );
}

function v31FkExists(string $table, string $constraint): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name=? AND constraint_type="FOREIGN KEY"',
        [$table, $constraint]
    );
}

function v31AddColumn(string $table, string $column, string $definition): void
{
    if (!v31ColumnExists($table, $column)) {
        Database::exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function v31AddIndex(string $table, string $name, string $definition): void
{
    if (!v31IndexExists($table, $name)) {
        Database::exec("ALTER TABLE `$table` ADD $definition");
    }
}

function v31AddFk(string $table, string $name, string $definition): void
{
    if (!v31FkExists($table, $name)) {
        Database::exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
    }
}

$pdo = Database::conn();
v31Say('بدء ترحيل v31: الأطراف والفواتير والتحصيلات...');

// -------------------------------------------------------------------------
// 1) Master data
// -------------------------------------------------------------------------
v31AddColumn('agents', 'normalized_name', "VARCHAR(180) NULL AFTER name");
v31AddColumn('agents', 'phone', "VARCHAR(40) NULL AFTER note");
v31AddColumn('agents', 'email', "VARCHAR(190) NULL AFTER phone");
v31AddColumn('agents', 'tax_id', "VARCHAR(80) NULL AFTER email");
v31AddColumn('agents', 'address', "VARCHAR(500) NULL AFTER tax_id");
v31AddColumn('agents', 'payment_terms_days', "INT UNSIGNED NOT NULL DEFAULT 30 AFTER address");
v31AddColumn('agents', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1 AFTER payment_terms_days");
v31AddColumn('agents', 'merged_into_id', "INT UNSIGNED NULL AFTER is_active");
v31AddColumn('agents', 'lock_version', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER merged_into_id");
v31AddColumn('agents', 'updated_at', "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

v31AddColumn('companies', 'normalized_name', "VARCHAR(180) NULL AFTER name");
v31AddColumn('companies', 'phone', "VARCHAR(40) NULL AFTER note");
v31AddColumn('companies', 'email', "VARCHAR(190) NULL AFTER phone");
v31AddColumn('companies', 'tax_id', "VARCHAR(80) NULL AFTER email");
v31AddColumn('companies', 'address', "VARCHAR(500) NULL AFTER tax_id");
v31AddColumn('companies', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1 AFTER address");
v31AddColumn('companies', 'merged_into_id', "INT UNSIGNED NULL AFTER is_active");
v31AddColumn('companies', 'lock_version', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER merged_into_id");
v31AddColumn('companies', 'updated_at', "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

$pdo->exec("CREATE TABLE IF NOT EXISTS declaration_owners (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(190) NOT NULL,
    normalized_name VARCHAR(190) NULL,
    phone           VARCHAR(40) NULL,
    email           VARCHAR(190) NULL,
    tax_id          VARCHAR(80) NULL,
    address         VARCHAR(500) NULL,
    note            VARCHAR(255) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    merged_into_id  INT UNSIGNED NULL,
    lock_version    INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_owner_name (name),
    KEY idx_owner_active (is_active),
    KEY idx_owner_merged (merged_into_id),
    CONSTRAINT fk_owner_merged FOREIGN KEY (merged_into_id) REFERENCES declaration_owners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS party_aliases (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    party_type       VARCHAR(16) NOT NULL,
    party_id         INT UNSIGNED NOT NULL,
    alias            VARCHAR(190) NOT NULL,
    normalized_alias VARCHAR(190) NOT NULL,
    is_primary       TINYINT(1) NOT NULL DEFAULT 0,
    created_by       INT UNSIGNED NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_party_alias_norm (party_type, normalized_alias),
    KEY idx_party_alias_target (party_type, party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

v31AddIndex('agents', 'idx_agents_active', 'KEY `idx_agents_active` (`is_active`)');
v31AddIndex('agents', 'idx_agents_merged', 'KEY `idx_agents_merged` (`merged_into_id`)');
v31AddIndex('companies', 'idx_companies_active', 'KEY `idx_companies_active` (`is_active`)');
v31AddIndex('companies', 'idx_companies_merged', 'KEY `idx_companies_merged` (`merged_into_id`)');
v31AddFk('agents', 'fk_agents_merged', 'FOREIGN KEY (`merged_into_id`) REFERENCES `agents` (`id`) ON DELETE RESTRICT');
v31AddFk('companies', 'fk_companies_merged', 'FOREIGN KEY (`merged_into_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT');

// أنشئ سجلات master لكل اسم تشغيلي موجود، لا للأرشيف القديم فقط.
$pdo->exec("INSERT IGNORE INTO agents (name)
    SELECT DISTINCT x.name FROM (
        SELECT TRIM(client) name FROM permits WHERE client IS NOT NULL AND TRIM(client)<>''
        UNION SELECT TRIM(client) FROM permit_invoices WHERE client IS NOT NULL AND TRIM(client)<>''
        UNION SELECT TRIM(client_name) FROM client_payments WHERE client_name IS NOT NULL AND TRIM(client_name)<>''
    ) x");
$pdo->exec("INSERT IGNORE INTO companies (name)
    SELECT DISTINCT x.name FROM (
        SELECT TRIM(company) name FROM permits WHERE company IS NOT NULL AND TRIM(company)<>''
        UNION SELECT TRIM(company) FROM bawalis WHERE is_legacy=0 AND company IS NOT NULL AND TRIM(company)<>''
        UNION SELECT TRIM(company) FROM permit_invoices WHERE company IS NOT NULL AND TRIM(company)<>''
    ) x");
$pdo->exec("INSERT IGNORE INTO declaration_owners (name)
    SELECT DISTINCT x.name FROM (
        SELECT TRIM(declaration_owner) name FROM bawalis WHERE declaration_owner IS NOT NULL AND TRIM(declaration_owner)<>''
        UNION SELECT TRIM(owner) FROM owner_statements WHERE owner IS NOT NULL AND TRIM(owner)<>''
    ) x");

// تطبيع محافظ داخل SQL؛ التطبيع العربي الأوسع يتم في Party::normalizeName عند كل كتابة جديدة.
$pdo->exec("UPDATE agents SET normalized_name=LOWER(TRIM(REGEXP_REPLACE(name,'[[:space:]]+',' '))) WHERE normalized_name IS NULL OR normalized_name=''");
$pdo->exec("UPDATE companies SET normalized_name=LOWER(TRIM(REGEXP_REPLACE(name,'[[:space:]]+',' '))) WHERE normalized_name IS NULL OR normalized_name=''");
$pdo->exec("UPDATE declaration_owners SET normalized_name=LOWER(TRIM(REGEXP_REPLACE(name,'[[:space:]]+',' '))) WHERE normalized_name IS NULL OR normalized_name=''");

$pdo->exec("INSERT IGNORE INTO party_aliases (party_type,party_id,alias,normalized_alias,is_primary)
    SELECT 'agent',id,name,normalized_name,1 FROM agents WHERE normalized_name IS NOT NULL AND normalized_name<>''");
$pdo->exec("INSERT IGNORE INTO party_aliases (party_type,party_id,alias,normalized_alias,is_primary)
    SELECT 'company',id,name,normalized_name,1 FROM companies WHERE normalized_name IS NOT NULL AND normalized_name<>''");
$pdo->exec("INSERT IGNORE INTO party_aliases (party_type,party_id,alias,normalized_alias,is_primary)
    SELECT 'owner',id,name,normalized_name,1 FROM declaration_owners WHERE normalized_name IS NOT NULL AND normalized_name<>''");

// -------------------------------------------------------------------------
// 2) معرفات الأطراف داخل المستندات مع إبقاء النص كلقطة.
// -------------------------------------------------------------------------
v31AddColumn('permits', 'agent_id', 'INT UNSIGNED NULL AFTER client');
v31AddColumn('permits', 'company_id', 'INT UNSIGNED NULL AFTER company');
v31AddColumn('permits', 'lock_version', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER status');
v31AddColumn('bawalis', 'company_id', 'INT UNSIGNED NULL AFTER company');
v31AddColumn('bawalis', 'declaration_owner_id', 'INT UNSIGNED NULL AFTER declaration_owner');
v31AddColumn('bawalis', 'lock_version', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER pay_status');
v31AddColumn('permit_invoices', 'agent_id', 'INT UNSIGNED NULL AFTER client');
v31AddColumn('permit_invoices', 'company_id', 'INT UNSIGNED NULL AFTER company');
v31AddColumn('owner_statements', 'declaration_owner_id', 'INT UNSIGNED NULL AFTER owner');
v31AddColumn('client_payments', 'agent_id', 'INT UNSIGNED NULL AFTER client_name');

$pdo->exec('UPDATE permits p JOIN agents a ON a.name=p.client SET p.agent_id=a.id WHERE p.agent_id IS NULL');
$pdo->exec('UPDATE permits p JOIN companies c ON c.name=p.company SET p.company_id=c.id WHERE p.company_id IS NULL');
$pdo->exec('UPDATE bawalis b JOIN companies c ON c.name=b.company SET b.company_id=c.id WHERE b.company_id IS NULL');
$pdo->exec('UPDATE bawalis b JOIN declaration_owners o ON o.name=b.declaration_owner SET b.declaration_owner_id=o.id WHERE b.declaration_owner_id IS NULL');
$pdo->exec('UPDATE permit_invoices i JOIN agents a ON a.name=i.client SET i.agent_id=a.id WHERE i.agent_id IS NULL');
$pdo->exec('UPDATE permit_invoices i JOIN companies c ON c.name=i.company SET i.company_id=c.id WHERE i.company_id IS NULL');
$pdo->exec('UPDATE owner_statements s JOIN declaration_owners o ON o.name=s.owner SET s.declaration_owner_id=o.id WHERE s.declaration_owner_id IS NULL');
$pdo->exec('UPDATE client_payments p JOIN agents a ON a.name=p.client_name SET p.agent_id=a.id WHERE p.agent_id IS NULL');

v31AddIndex('permits', 'idx_permits_agent', 'KEY `idx_permits_agent` (`agent_id`)');
v31AddIndex('permits', 'idx_permits_company', 'KEY `idx_permits_company` (`company_id`)');
v31AddIndex('bawalis', 'idx_bawalis_company_id', 'KEY `idx_bawalis_company_id` (`company_id`)');
v31AddIndex('bawalis', 'idx_bawalis_owner_id', 'KEY `idx_bawalis_owner_id` (`declaration_owner_id`)');
v31AddIndex('permit_invoices', 'idx_pinv_agent', 'KEY `idx_pinv_agent` (`agent_id`)');
v31AddIndex('permit_invoices', 'idx_pinv_company', 'KEY `idx_pinv_company` (`company_id`)');
v31AddIndex('owner_statements', 'idx_owner_statement_party', 'KEY `idx_owner_statement_party` (`declaration_owner_id`)');
v31AddIndex('client_payments', 'idx_client_payment_agent', 'KEY `idx_client_payment_agent` (`agent_id`)');

v31AddFk('permits', 'fk_permits_agent', 'FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE RESTRICT');
v31AddFk('permits', 'fk_permits_company', 'FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT');
v31AddFk('bawalis', 'fk_bawalis_company', 'FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT');
v31AddFk('bawalis', 'fk_bawalis_owner', 'FOREIGN KEY (`declaration_owner_id`) REFERENCES `declaration_owners` (`id`) ON DELETE RESTRICT');
v31AddFk('permit_invoices', 'fk_pinv_agent', 'FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE RESTRICT');
v31AddFk('permit_invoices', 'fk_pinv_company', 'FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT');
v31AddFk('owner_statements', 'fk_owner_statement_party', 'FOREIGN KEY (`declaration_owner_id`) REFERENCES `declaration_owners` (`id`) ON DELETE RESTRICT');
v31AddFk('client_payments', 'fk_client_payment_agent', 'FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE RESTRICT');

// -------------------------------------------------------------------------
// 3) دورة الفاتورة والتحصيل والتخصيص.
// -------------------------------------------------------------------------
v31AddColumn('permit_invoices', 'status', "VARCHAR(20) NOT NULL DEFAULT 'draft' AFTER doc_kind");
v31AddColumn('permit_invoices', 'due_date', 'DATE NULL AFTER invoice_date');
v31AddColumn('permit_invoices', 'paid_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total');
v31AddColumn('permit_invoices', 'balance_due', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER paid_amount');
v31AddColumn('permit_invoices', 'issued_at', 'DATETIME NULL AFTER note');
v31AddColumn('permit_invoices', 'voided_at', 'DATETIME NULL AFTER issued_at');
v31AddColumn('permit_invoices', 'voided_by', 'INT UNSIGNED NULL AFTER voided_at');
v31AddColumn('permit_invoices', 'void_reason', 'VARCHAR(500) NULL AFTER voided_by');
v31AddColumn('permit_invoices', 'lock_version', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER void_reason');
v31AddColumn('permit_invoices', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

v31AddColumn('client_payments', 'status', "VARCHAR(16) NOT NULL DEFAULT 'posted' AFTER amount");
v31AddColumn('client_payments', 'unallocated_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER status');
v31AddColumn('client_payments', 'reversal_of_id', 'INT UNSIGNED NULL AFTER visa_card_id');
v31AddColumn('client_payments', 'reversed_at', 'DATETIME NULL AFTER reversal_of_id');
v31AddColumn('client_payments', 'reversed_by', 'INT UNSIGNED NULL AFTER reversed_at');
v31AddColumn('client_payments', 'reverse_reason', 'VARCHAR(500) NULL AFTER reversed_by');
v31AddColumn('client_payments', 'lock_version', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER reverse_reason');

v31AddColumn('owner_statements', 'status', "VARCHAR(16) NOT NULL DEFAULT 'issued' AFTER statement_date");
v31AddColumn('owner_statements', 'voided_at', 'DATETIME NULL AFTER note');
v31AddColumn('owner_statements', 'voided_by', 'INT UNSIGNED NULL AFTER voided_at');
v31AddColumn('owner_statements', 'void_reason', 'VARCHAR(500) NULL AFTER voided_by');
v31AddColumn('owner_statements', 'lock_version', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER void_reason');
v31AddColumn('owner_statements', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

$pdo->exec("CREATE TABLE IF NOT EXISTS invoice_payment_allocations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id  INT UNSIGNED NOT NULL,
    payment_id  INT UNSIGNED NOT NULL,
    amount      DECIMAL(14,2) NOT NULL,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_payment (invoice_id,payment_id),
    KEY idx_allocation_payment (payment_id),
    CONSTRAINT fk_allocation_invoice FOREIGN KEY (invoice_id) REFERENCES permit_invoices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_allocation_payment FOREIGN KEY (payment_id) REFERENCES client_payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("UPDATE permit_invoices i
    LEFT JOIN agents a ON a.id=i.agent_id
    LEFT JOIN (
        SELECT x.invoice_id,SUM(x.amount) paid
        FROM invoice_payment_allocations x
        JOIN client_payments p ON p.id=x.payment_id AND p.status='posted'
        GROUP BY x.invoice_id
    ) al ON al.invoice_id=i.id
    SET i.issued_at=COALESCE(i.issued_at,i.created_at),
        i.due_date=COALESCE(i.due_date,DATE_ADD(i.invoice_date, INTERVAL COALESCE(a.payment_terms_days,30) DAY)),
        i.paid_amount=IF(i.status='void',i.paid_amount,LEAST(i.total,COALESCE(al.paid,0))),
        i.balance_due=IF(i.status='void',0,GREATEST(i.total-COALESCE(al.paid,0),0)),
        i.status=CASE WHEN i.status='void' THEN 'void'
                      WHEN COALESCE(al.paid,0)>=i.total-0.005 THEN 'paid'
                      WHEN COALESCE(al.paid,0)>0.005 THEN 'partially_paid'
                      ELSE 'issued' END");
$pdo->exec("UPDATE client_payments p
    LEFT JOIN (SELECT payment_id,SUM(amount) allocated FROM invoice_payment_allocations GROUP BY payment_id) al
      ON al.payment_id=p.id
    SET p.unallocated_amount=IF(p.status='reversed',0,GREATEST(p.amount-COALESCE(al.allocated,0),0))");

v31AddIndex('permit_invoices', 'idx_pinv_status_due', 'KEY `idx_pinv_status_due` (`status`,`due_date`)');
v31AddIndex('client_payments', 'idx_client_payment_status', 'KEY `idx_client_payment_status` (`status`,`pay_date`)');
v31AddIndex('client_payments', 'idx_client_payment_reversal', 'KEY `idx_client_payment_reversal` (`reversal_of_id`)');
v31AddFk('client_payments', 'fk_client_payment_reversal', 'FOREIGN KEY (`reversal_of_id`) REFERENCES `client_payments` (`id`) ON DELETE RESTRICT');

// الفاتورة لقطة مالية يجب أن تمنع حذف الإذن الأب بدلاً من CASCADE.
$deleteRule = Database::scalar("SELECT DELETE_RULE FROM information_schema.referential_constraints
    WHERE constraint_schema=DATABASE() AND table_name='permit_invoices' AND constraint_name='fk_pinv_permit'");
if ($deleteRule && strtoupper((string)$deleteRule) !== 'RESTRICT') {
    Database::exec('ALTER TABLE permit_invoices DROP FOREIGN KEY fk_pinv_permit');
}
v31AddFk('permit_invoices', 'fk_pinv_permit', 'FOREIGN KEY (`permit_id`) REFERENCES `permits` (`id`) ON DELETE RESTRICT');

// -------------------------------------------------------------------------
// 4) تفرد على مستوى القاعدة، لا فحص تطبيق قابل للسباق فقط.
// -------------------------------------------------------------------------
v31AddIndex('permit_invoices', 'uq_pinv_doc_no', 'UNIQUE KEY `uq_pinv_doc_no` (`doc_no`)');
v31AddIndex('permit_invoices', 'uq_pinv_serial_no', 'UNIQUE KEY `uq_pinv_serial_no` (`serial_no`)');
v31AddIndex('permits', 'uq_permit_type_no', 'UNIQUE KEY `uq_permit_type_no` (`permit_type`,`permit_no`)');
v31AddIndex('permit_movements', 'uq_permit_movement_seq', 'UNIQUE KEY `uq_permit_movement_seq` (`permit_id`,`seq`)');

if (!v31ColumnExists('bawalis', 'live_bill_number')) {
    Database::exec("ALTER TABLE bawalis ADD COLUMN live_bill_number VARCHAR(80)
        GENERATED ALWAYS AS (CASE WHEN is_legacy=0 AND bill_number IS NOT NULL AND bill_number<>'' THEN bill_number ELSE NULL END) STORED AFTER bill_number");
}
v31AddIndex('bawalis', 'uq_bawalis_live_number', 'UNIQUE KEY `uq_bawalis_live_number` (`live_bill_number`)');

v31Say('اكتمل ترحيل v31 بنجاح.');
