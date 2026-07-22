-- ==========================================================================
--  نظام المتوكل لإدارة النقل والتخليص الجمركي
--  مخطط قاعدة البيانات — MySQL 8+ / 9+
-- ==========================================================================
SET NAMES utf8mb4;
SET time_zone = '+02:00';

-- --------------------------------------------------------------------------
--  المستخدمون (تسجيل الدخول)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(60)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(120) NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'read_only',
    can_free_edit TINYINT(1)   NOT NULL DEFAULT 0,
    failed_tries  INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until  DATETIME     NULL,
    last_failed_at DATETIME    NULL,
    last_login_at DATETIME     NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username),
    KEY idx_users_role (role),
    KEY idx_users_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username_hash CHAR(64) NOT NULL,
    ip_hash       CHAR(64) NOT NULL,
    was_success   TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_ip_time (ip_hash, was_success, attempted_at),
    KEY idx_login_user_time (username_hash, attempted_at),
    KEY idx_login_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;

-- --------------------------------------------------------------------------
--  الإعدادات العامة (مفتاح/قيمة)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(60)  NOT NULL,
    setting_value TEXT         NOT NULL,   -- يتّسع لإعدادات JSON (تكوين التايم لاين)
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('company_name', 'شركة المتوكل'),
    ('vat_rate',     '0.14'),
    ('report_year',  '2026'),
    ('currency',     'ج.م')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- --------------------------------------------------------------------------
--  القوائم المنسدلة (التصنيفات/الشركات/الجهات/السائقين/أصحاب السيارات/العملاء)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lookups (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_type  VARCHAR(30)  NOT NULL,   -- classification | company | destination | driver | car_owner | client
    value      VARCHAR(180) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_type_value (list_type, value),
    KEY idx_type (list_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تصنيفات وجهات مبدئية (تُثرى تلقائياً من بيانات النظام القديم)
INSERT IGNORE INTO lookups (list_type, value) VALUES
    ('classification','مستورد'), ('classification','مصدّر'), ('classification','ترانزيت'),
    ('classification','بضائع عامة'), ('classification','مواد خام'), ('classification','معدات'),
    ('classification','أخرى'),
    ('destination','الإسكندرية'), ('destination','الدخيلة'), ('destination','العين السخنة'),
    ('destination','دمياط'), ('destination','القاهرة'), ('destination','بورسعيد'),
    ('destination','السويس'), ('destination','6 أكتوبر'), ('destination','العاشر من رمضان');

-- --------------------------------------------------------------------------
--  البوالص  (سجل بوالص الشحن ومحاسبة رحلات السيارات)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bawalis (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permit_id         INT UNSIGNED NULL,   -- الإصدار (إذن/شهادة) المرتبط
    movement_id       INT UNSIGNED NULL,   -- الحركة داخل الإذن
    bill_type         VARCHAR(10)  NOT NULL DEFAULT 'sader',  -- sader=صادر | warid=وارد
    bill_date         DATE         NULL,
    bill_number       VARCHAR(80)  NULL,
    cert_number       VARCHAR(120) NULL,   -- رقم الشهادة (وارد)
    permit_no         VARCHAR(120) NULL,   -- رقم الإذن (صادر)
    declaration_owner VARCHAR(150) NULL,   -- صاحب البيان
    classification    VARCHAR(120) NULL,
    company           VARCHAR(180) NULL,
    destination       VARCHAR(120) NULL,
    container_no      VARCHAR(120) NULL,   -- رقم الحاوية (يدوي)
    container_type    VARCHAR(80)  NULL,   -- تصنيف الحاويات (يدوي)
    container_count   INT          NOT NULL DEFAULT 0,  -- عدد الحاويات في البوليصة
    car_no            VARCHAR(120) NULL,
    driver            VARCHAR(150) NULL,
    car_owner         VARCHAR(150) NULL,
    -- إيرادات / تحصيل
    freight             DECIMAL(14,2) NOT NULL DEFAULT 0,  -- نولون
    custody             DECIMAL(14,2) NOT NULL DEFAULT 0,  -- عهدة
    transfer            DECIMAL(14,2) NOT NULL DEFAULT 0,  -- تحويلة
    discount            DECIMAL(14,2) NOT NULL DEFAULT 200,  -- خصم (افتراضي 200)
    overnight_collection DECIMAL(14,2) NOT NULL DEFAULT 0, -- بياتة (تحصيل)
    payment             DECIMAL(14,2) NOT NULL DEFAULT 0,  -- دفعة
    -- مصروفات
    driver_share      DECIMAL(14,2) NOT NULL DEFAULT 0,  -- دور سواق
    assistant_share   DECIMAL(14,2) NOT NULL DEFAULT 0,  -- دور تبّاع
    overnight_expense DECIMAL(14,2) NOT NULL DEFAULT 0,  -- بياتة (مصروف)
    expense           DECIMAL(14,2) NOT NULL DEFAULT 0,  -- مصروف
    cards             DECIMAL(14,2) NOT NULL DEFAULT 0,  -- كرتات
    tahia_masr        DECIMAL(14,2) NOT NULL DEFAULT 0,  -- تحيا مصر
    thabtan           DECIMAL(14,2) NOT NULL DEFAULT 0,  -- ثبتان
    z_kawtsh          DECIMAL(14,2) NOT NULL DEFAULT 0,  -- ظ كاوتش
    permits           DECIMAL(14,2) NOT NULL DEFAULT 0,  -- تصاريح
    kawtsh            DECIMAL(14,2) NOT NULL DEFAULT 0,  -- كاوتش
    other_expense     DECIMAL(14,2) NOT NULL DEFAULT 0,  -- م أخرى
    gas               DECIMAL(14,2) NOT NULL DEFAULT 0,  -- جاز
    -- محسوبة (تُحدَّث عند كل حفظ)
    debt              DECIMAL(14,2) NOT NULL DEFAULT 0,  -- دين البوليصة = نولون−عهدة−تحويلة−خصم+بياتة
    paid              DECIMAL(14,2) NOT NULL DEFAULT 0,  -- المدفوع (مجموع الدفعات)
    remaining         DECIMAL(14,2) NOT NULL DEFAULT 0,  -- المتبقي = الدين − المدفوع
    pay_status        VARCHAR(12)   NOT NULL DEFAULT 'unpaid',  -- paid|partial|unpaid
    is_legacy         TINYINT(1)    NOT NULL DEFAULT 0,  -- مرحّلة من أرشيف Excel: خارج الحسابات التشغيلية
    legacy_id         INT UNSIGNED  NULL,                -- src_row الثابت في ملف Excel القديم
    -- أعمدة النظام القديم (محفوظة للأرشيف — المصروفات انتقلت إلى الحركات)
    total_collection  DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_expense     DECIMAL(14,2) NOT NULL DEFAULT 0,
    custody_remaining DECIMAL(14,2) NOT NULL DEFAULT 0,
    car_net           DECIMAL(14,2) NOT NULL DEFAULT 0,
    profit_pct        DECIMAL(8,4)  NOT NULL DEFAULT 0,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date (bill_date),
    KEY idx_company (company),
    KEY idx_status (pay_status),
    KEY idx_bawalis_is_legacy (is_legacy),
    UNIQUE KEY uq_bawalis_legacy_id (legacy_id),
    KEY idx_client (declaration_owner),
    KEY idx_permit (permit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  التخليص  (شهادات وأعمال التخليص الجمركي)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS takhlis (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cert_date       DATE         NULL,
    cert_name       VARCHAR(180) NULL,   -- اسم الشهادة / الشركة
    cert_number     VARCHAR(120) NULL,   -- رقم الشهادة
    fees            DECIMAL(14,2) NOT NULL DEFAULT 0,   -- الأتعاب
    expenses        DECIMAL(14,2) NOT NULL DEFAULT 0,   -- المصروفات
    payments        DECIMAL(14,2) NOT NULL DEFAULT 0,   -- الدفعات
    total_statement DECIMAL(14,2) NOT NULL DEFAULT 0,   -- إجمالي الكشف (محسوب)
    remaining       DECIMAL(14,2) NOT NULL DEFAULT 0,   -- المتبقي (محسوب)
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date (cert_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  المولدات  (ايجي كول + الشجيع في جدول واحد بعمود المصدر)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS generators (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source     VARCHAR(20)  NOT NULL DEFAULT 'egycool',  -- egycool | shujaie
    gen_date   DATE         NULL,
    company    VARCHAR(180) NULL,   -- اسم الشركة
    address    VARCHAR(255) NULL,   -- العنوان
    permit_no  VARCHAR(120) NULL,   -- رقم الإذن
    gen_count  INT          NOT NULL DEFAULT 0,   -- عدد المولدات
    freight    DECIMAL(14,2) NOT NULL DEFAULT 0,  -- النولون
    payments   DECIMAL(14,2) NOT NULL DEFAULT 0,  -- الدفعات
    remaining  DECIMAL(14,2) NOT NULL DEFAULT 0,  -- المتبقي (محسوب)
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_source_date (source, gen_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  شامل  (الفواتير وحسابات العملاء)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shamil (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_date        DATE         NULL,
    statement_no        VARCHAR(120) NULL,   -- رقم الكشف
    invoice_no          VARCHAR(120) NULL,   -- رقم الفاتورة
    permit_no           VARCHAR(120) NULL,   -- رقم الإذن
    client              VARCHAR(180) NULL,   -- اسم العميل
    company             VARCHAR(180) NULL,   -- اسم الشركة
    destination         VARCHAR(120) NULL,   -- الجهة
    containers          INT          NOT NULL DEFAULT 0,  -- عدد الحاويات
    freight             DECIMAL(14,2) NOT NULL DEFAULT 0, -- النولون
    data_idle           DECIMAL(14,2) NOT NULL DEFAULT 0, -- بيانات أو عطالات
    discount_ats        DECIMAL(14,2) NOT NULL DEFAULT 0, -- خصم أ ت ص
    receipts            DECIMAL(14,2) NOT NULL DEFAULT 0, -- الإيصالات
    payments            DECIMAL(14,2) NOT NULL DEFAULT 0, -- الدفعات
    actual_freight      DECIMAL(14,2) NOT NULL DEFAULT 0, -- نولون فعلي
    inspection_guarantee DECIMAL(14,2) NOT NULL DEFAULT 0,-- كشف وضمان
    expense             DECIMAL(14,2) NOT NULL DEFAULT 0, -- مصروف
    other_profit        DECIMAL(14,2) NOT NULL DEFAULT 0, -- ربح آخر
    -- محسوبة
    vat            DECIMAL(14,2) NOT NULL DEFAULT 0,  -- قيمة مضافة
    invoice_total  DECIMAL(14,2) NOT NULL DEFAULT 0,  -- إجمالي الفاتورة
    account_total  DECIMAL(14,2) NOT NULL DEFAULT 0,  -- إجمالي الحساب
    profit_total   DECIMAL(14,2) NOT NULL DEFAULT 0,  -- إجمالي ربح
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date (invoice_date),
    KEY idx_client (client),
    KEY idx_company (company)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  أرصدة العملاء والموردين
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS balances (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    party_type VARCHAR(12)  NOT NULL DEFAULT 'client',  -- client | supplier
    name       VARCHAR(180) NOT NULL,
    balance    DECIMAL(14,2) NOT NULL DEFAULT 0,
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_party (party_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  دفعات سداد البوالص (كل بوليصة لها عدة دفعات)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bill_payments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bill_id    INT UNSIGNED NOT NULL,
    pay_date   DATE          NULL,
    amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
    note       VARCHAR(255)  NULL,
    is_legacy  TINYINT(1)    NOT NULL DEFAULT 0,  -- دفعة تاريخية مرحّلة؛ لا تدخل التقارير
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bill (bill_id),
    KEY idx_date (pay_date),
    CONSTRAINT fk_pay_bill FOREIGN KEY (bill_id) REFERENCES bawalis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  الحركات (تُربط ببوليصة، وتحمل المصروفات والإيصالات والخصومات)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movements (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    movement_date DATE          NULL,
    bill_id       INT UNSIGNED  NULL,
    driver        VARCHAR(150)  NULL,   -- السائق (العهدة مربوطة به)
    car_owner     VARCHAR(150)  NULL,
    shipping_co   VARCHAR(180)  NULL,   -- شركة الشحن
    agency        VARCHAR(180)  NULL,   -- توكيل
    port          VARCHAR(150)  NULL,   -- الميناء
    note          VARCHAR(255)  NULL,
    total_expense DECIMAL(14,2) NOT NULL DEFAULT 0,  -- مجموع المصروفات (محسوب)
    total_receipt DECIMAL(14,2) NOT NULL DEFAULT 0,  -- مجموع الإيصالات (محسوب)
    total_discount DECIMAL(14,2) NOT NULL DEFAULT 0, -- مجموع الخصومات (محسوب)
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date (movement_date),
    KEY idx_bill (bill_id),
    KEY idx_driver (driver),
    CONSTRAINT fk_mv_bill FOREIGN KEY (bill_id) REFERENCES bawalis(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  بنود الحركة (مصروف / إيصال / خصم) بفئاتها وأرقامها
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movement_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    movement_id INT UNSIGNED NOT NULL,
    item_type   VARCHAR(12)  NOT NULL DEFAULT 'expense',  -- expense | receipt | discount
    category    VARCHAR(150) NULL,     -- الفئة (مثل: إيصال تخصيص، جاز...)
    amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
    note        VARCHAR(255)  NULL,
    is_visa     TINYINT(1)   NOT NULL DEFAULT 0,  -- دُفع بالفيزا؟ (migrate_v6)
    visa_card_id INT UNSIGNED NULL DEFAULT NULL,   -- كارت الفيزا المستخدَم (migrate_v22 — رصيد المكتب)
    PRIMARY KEY (id),
    KEY idx_mv (movement_id),
    KEY idx_type (item_type),
    CONSTRAINT fk_item_mv FOREIGN KEY (movement_id) REFERENCES movements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  عُهد السائقين (يُمكن أكثر من عهدة لنفس السائق في اليوم)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS driver_custody (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    custody_date DATE          NULL,
    driver       VARCHAR(150)  NOT NULL,
    amount       DECIMAL(14,2) NOT NULL DEFAULT 0,
    note         VARCHAR(255)  NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_driver_date (driver, custody_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فئات بنود الحركة (تظهر في القوائم — أضف المزيد من الإعدادات)
INSERT IGNORE INTO lookups (list_type, value) VALUES
    ('mv_expense','دور سواق'), ('mv_expense','دور تبّاع'), ('mv_expense','بياتة'),
    ('mv_expense','مصروف'), ('mv_expense','كرتات'), ('mv_expense','تحيا مصر'),
    ('mv_expense','ثبتان'), ('mv_expense','ظ كاوتش'), ('mv_expense','تصاريح'),
    ('mv_expense','كاوتش'), ('mv_expense','م أخرى'), ('mv_expense','جاز'),
    ('mv_discount','إيصال تخصيص'), ('mv_discount','إيصال حمولة'),
    ('mv_discount','إيصال هيئة'), ('mv_discount','إيصال طرق وكباري'),
    ('mv_receipt','إيصال عام');

-- --------------------------------------------------------------------------
--  المصروفات اليدوية  (مصاريف عامة تُسجَّل يدوياً: إيجار، مرتبات، كهرباء...)
--  وحدة المصروفات تجمع هذا الجدول مع مصروفات باقي الوحدات في سجل واحد.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS manual_expenses (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exp_date    DATE          NULL,       -- تاريخ المصروف
    category    VARCHAR(150)  NULL,       -- الفئة (إيجار/مرتبات/كهرباء...)
    beneficiary VARCHAR(180)  NULL,       -- المستفيد / الجهة المدفوع لها
    amount      DECIMAL(14,2) NOT NULL DEFAULT 0,  -- المبلغ
    note        VARCHAR(255)  NULL,       -- ملاحظة / بيان
    is_visa     TINYINT(1)    NOT NULL DEFAULT 0,  -- دُفع بالفيزا؟ (migrate_v6)
    visa_card_id INT UNSIGNED NULL DEFAULT NULL,   -- كارت الفيزا المستخدَم (migrate_v22 — رصيد المكتب)
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date (exp_date),
    KEY idx_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فئات المصروفات اليدوية المبدئية (تُثرى تلقائياً عند الإدخال)
INSERT IGNORE INTO lookups (list_type, value) VALUES
    ('expense_cat','إيجار'), ('expense_cat','مرتبات'), ('expense_cat','كهرباء'),
    ('expense_cat','مياه'), ('expense_cat','تليفون وإنترنت'), ('expense_cat','صيانة'),
    ('expense_cat','بنزين وسولار'), ('expense_cat','مصاريف مكتب'), ('expense_cat','ضيافة'),
    ('expense_cat','رسوم حكومية'), ('expense_cat','نظافة'), ('expense_cat','أخرى');

-- --------------------------------------------------------------------------
--  إضافات رصيد العهدة (توب-أب) — عهدة إضافية غير مرتبطة ببوليصة (migrate_v11)
--  تموّل محفظة العهدة (/custody) وتدخل «عهد اليوم» في التقفيل اليومي.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS manual_custody (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cust_date   DATE          NULL,
    driver      VARCHAR(180)  NULL,
    amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
    note        VARCHAR(255)  NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date (cust_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
--  الإصدارات (إذن للصادر / شهادة للوارد) — أكبر عملية في النظام
--  الإصدار هو الأب، ويشمل بوليصة واحدة أو أكثر (bawalis.permit_id)،
--  وله تايم لاين من المراحل (permit_stages).
-- ==========================================================================
CREATE TABLE IF NOT EXISTS permits (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permit_type  VARCHAR(10)  NOT NULL DEFAULT 'sader',  -- sader=إذن(صادر) | warid=شهادة(وارد)
    permit_no    VARCHAR(120) NULL,     -- رقم الإذن/الشهادة (يُكتب يدوياً)
    permit_date  DATE         NULL,     -- تاريخ الإصدار
    company      VARCHAR(180) NULL,     -- الشركة
    client       VARCHAR(180) NULL,     -- العميل / صاحب البيان
    destination  VARCHAR(120) NULL,     -- الجهة
    shipping_co  VARCHAR(180) NULL,     -- شركة الشحن (migrate_v6)
    agency       VARCHAR(180) NULL,     -- التوكيل الملاحي (migrate_v6)
    port         VARCHAR(180) NULL,     -- الميناء (migrate_v6)
    containers   INT          NOT NULL DEFAULT 0,  -- عدد الحاويات الإجمالي (لحساب الكشف والضمان)
    container_type VARCHAR(150) NULL,    -- تصنيف الحاويات على مستوى الإذن (migrate_v16) — يُملأ تلقائيًا في البوليصة
    inspection_rate DECIMAL(10,2) NULL,   -- سعر الكشف لكل حاوية (لكل إذن، NULL = غير مُدخَل)
    guarantee_rate  DECIMAL(10,2) NULL,   -- سعر الضمان لكل حاوية (لكل إذن، NULL = غير مُدخَل)
    note         VARCHAR(255) NULL,
    status       VARCHAR(12)  NOT NULL DEFAULT 'open',   -- open=جارٍ | closed=مقفول
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_type (permit_type),
    KEY idx_no (permit_no),
    KEY idx_date (permit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (عمود bawalis.permit_id مُعرّف داخل جدول bawalis أعلاه؛ الترحيل يضيفه للقاعدة القديمة)

-- --------------------------------------------------------------------------
--  حركات الإذن (كل إذن يضم حركة أو أكثر برقم تسلسلي، ولكل حركة تايم لاين كامل)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permit_movements (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permit_id  INT UNSIGNED NOT NULL,
    seq        INT          NOT NULL DEFAULT 1,   -- الرقم التسلسلي داخل الإذن
    note       VARCHAR(255) NULL,
    container_count INT UNSIGNED NULL,             -- عدد حاويات الحركة (وصفي — v25)
    container_type  VARCHAR(150) NULL,             -- تصنيف حاويات الحركة (وصفي — v25)
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_permit (permit_id),
    CONSTRAINT fk_pmv_permit FOREIGN KEY (permit_id) REFERENCES permits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  مراحل التايم لاين المالية (تخصيص/حمولة/رسوم/تعتيق/هيئة/مصاريف إضافية)
--  كل بند: مبلغ + تاريخ (يحدد التقفيل) + رقم مرجعي + ملاحظة + حالة + مرفق.
--  ملاحظة: مرحلة «البوليصة» = البوالص المرتبطة، ومرحلة «المولدات» = سجل المولدات؛
--  الاثنتان لا تُخزَّنان هنا بل تُشتقّان من جداولهما.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permit_stages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permit_id   INT UNSIGNED NOT NULL,
    movement_id INT UNSIGNED NULL,       -- الحركة (NULL = مرحلة على مستوى الإذن مثل رسوم أولية)
    stage_key   VARCHAR(30)  NOT NULL,   -- primary_fees|receipts|custom (قديمة مدمجة عبر v14: takhsis|hemola|fees|ta3teeq|hay2a)
    direction   VARCHAR(10)  NULL,       -- receipts=expense · custom: expense | revenue
    amount      DECIMAL(14,2) NOT NULL DEFAULT 0,   -- الإجمالي المصروف (= سعر الوحدة × qty) — هو ما تقرأه كل الحسابات
    qty         INT           NOT NULL DEFAULT 1,   -- كمية الإيصال (migrate_v17) — الإجمالي = سعر الوحدة × qty
    actual_amount DECIMAL(14,2) NULL,    -- السعر الفعلي (اللي هنحاسب بيه العميل) — لكل البنود ماعدا البوالص
    entry_date  DATE          NULL,      -- تاريخ البند (يدخل تقفيل يومه)
    ref_no      VARCHAR(120)  NULL,      -- رقم مرجعي
    note        VARCHAR(255)  NULL,
    status      VARCHAR(12)   NOT NULL DEFAULT 'done',  -- done=تمّت | pending=ناقصة
    attachment  VARCHAR(255)  NULL,      -- مسار الملف داخل storage/uploads
    is_visa     TINYINT(1)    NOT NULL DEFAULT 0,  -- دُفع بالفيزا؟ (migrate_v6)
    visa_card_id INT UNSIGNED NULL DEFAULT NULL,   -- كارت الفيزا المستخدَم (migrate_v22 — رصيد المكتب)
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_permit (permit_id),
    KEY idx_stage (stage_key),
    KEY idx_date (entry_date),
    CONSTRAINT fk_stage_permit FOREIGN KEY (permit_id) REFERENCES permits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  فواتير العملاء الخاصة بالأذونات (النظام الأول للفوترة)
--  لقطة ثابتة لكل فاتورة/كشف حساب صادر عن إذن: نولون مُدخَل عند الفوترة (غير
--  الفعلي) + مصروفاته الفعلية المختارة + ق. مضافة على النولون فقط (للخاضعة).
--  البنود تُخزَّن كـ JSON لتبقى ثابتة مهما تغيّر الإذن لاحقاً.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permit_invoices (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permit_id    INT UNSIGNED NOT NULL,
    doc_no       VARCHAR(40)  NULL,                        -- رقم المستند النظامي (تلقائي: MT-00001)
    doc_kind     VARCHAR(12)  NOT NULL DEFAULT 'invoice',  -- invoice=فاتورة | statement=كشف حساب
    taxable      TINYINT(1)   NOT NULL DEFAULT 0,          -- خاضعة للقيمة المضافة؟ (للفواتير فقط)
    internal_no  VARCHAR(120) NULL,                        -- الرقم الداخلي (رقم الفاتورة) — للفواتير الضريبية
    invoice_date DATE         NULL,
    client       VARCHAR(180) NULL,                        -- الوكيل/العميل (لقطة)
    company      VARCHAR(180) NULL,                        -- الشركة (لقطة)
    destination  VARCHAR(120) NULL,                        -- الجهة (لقطة)
    permit_no    VARCHAR(120) NULL,                        -- رقم الإذن/الشهادة (لقطة)
    permit_type  VARCHAR(10)  NULL,                        -- sader=صادر/إذن | warid=وارد/شهادة (لقطة)
    agency       VARCHAR(180) NULL,                        -- التوكيل الملاحي (لقطة)
    port         VARCHAR(120) NULL,                        -- الميناء (لقطة)
    containers   INT          NOT NULL DEFAULT 0,          -- عدد الحاويات (لقطة)
    freight      DECIMAL(14,2) NOT NULL DEFAULT 0,         -- النولون المُدخَل عند الفوترة
    vat_rate     DECIMAL(6,4)  NOT NULL DEFAULT 0,         -- نسبة الضريبة المطبّقة (0 لو غير خاضعة)
    vat_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,         -- قيمة الضريبة = النولون × النسبة
    subtotal     DECIMAL(14,2) NOT NULL DEFAULT 0,         -- إجمالي البنود (نولون + مصروفات مختارة)
    total        DECIMAL(14,2) NOT NULL DEFAULT 0,         -- الإجمالي الكلي = subtotal + vat_amount
    items_json   MEDIUMTEXT   NULL,                        -- لقطة بنود الفاتورة [{label, amount}]
    note         VARCHAR(500) NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_permit (permit_id),
    KEY idx_date (invoice_date),
    KEY idx_kind (doc_kind),
    CONSTRAINT fk_pinv_permit FOREIGN KEY (permit_id) REFERENCES permits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- كشوف حساب أصحاب البيان (النظام الثاني للفوترة) — لقطة ثابتة بلا ضريبة
CREATE TABLE IF NOT EXISTS owner_statements (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    doc_no         VARCHAR(40)  NULL,                        -- رقم المستند النظامي (تلقائي: MT-S-00001)
    statement_date DATE         NULL,                        -- تاريخ إصدار الكشف
    owner          VARCHAR(190) NULL,                        -- صاحب البيان (لقطة الاسم)
    stat_bills     INT          NOT NULL DEFAULT 0,          -- إجمالي عدد بوالصه
    stat_debt      DECIMAL(16,2) NOT NULL DEFAULT 0,         -- إجمالي الدين (علينا له)
    stat_paid      DECIMAL(16,2) NOT NULL DEFAULT 0,         -- إجمالي ما استلمه (المدفوع له)
    stat_remaining DECIMAL(16,2) NOT NULL DEFAULT 0,         -- إجمالي الباقي له
    paid_count      INT          NOT NULL DEFAULT 0,         -- عدد البوالص المدرَجة كمستلَمة
    paid_sum        DECIMAL(16,2) NOT NULL DEFAULT 0,        -- مجموع ما استلمه في القائمة المدرَجة
    remaining_count INT          NOT NULL DEFAULT 0,         -- عدد البوالص الباقية المدرَجة
    remaining_sum   DECIMAL(16,2) NOT NULL DEFAULT 0,        -- مجموع الباقي في القائمة المدرَجة
    items_json     MEDIUMTEXT   NULL,                        -- لقطة القائمتين {"paid":[...],"remaining":[...]}
    note           VARCHAR(500) NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_owner (owner),
    KEY idx_date (statement_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فئات المصاريف الإضافية للإصدار (اقتراحات في القائمة)
INSERT IGNORE INTO lookups (list_type, value) VALUES
    ('mv_expense','أرضية'), ('mv_expense','نقل'), ('mv_expense','عمولة');

-- ==========================================================================
--  دليل الوكلاء والشركات
--  الوكيل = عميلنا الفعلي (نتعامل معه مباشرة).
--  الشركة = الجهة التي يُصدَر الإذن لصالحها (الوكيل ينوب عنها).
--  الوكيل الواحد يمثّل عدة شركات، والشركة قد يمثّلها أكثر من وكيل.
-- ==========================================================================
CREATE TABLE IF NOT EXISTS agents (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(180) NOT NULL,   -- اسم الوكيل (عميلنا)
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(180) NOT NULL,   -- اسم الشركة (تُصدَر الأذونات لصالحها)
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_company_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ربط الوكيل بالشركات التي يمثّلها (علاقة متعدد-إلى-متعدد)
CREATE TABLE IF NOT EXISTS agent_company (
    agent_id   INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (agent_id, company_id),
    KEY idx_company (company_id),
    CONSTRAINT fk_ac_agent   FOREIGN KEY (agent_id)   REFERENCES agents(id)    ON DELETE CASCADE,
    CONSTRAINT fk_ac_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
--  المديونيات (الدائنون) — الجهات التي تدين لها الشركة
--  • حساب «الكشف والضمان» (ياسر أبو عمر / كونتراكت): kind='inspection'،
--    مديونيته تُحتسب تلقائياً = Σ (حاويات كل إذن × قيمة الكشف والضمان).
--  • حسابات يدوية (kind='manual') لأي مديونيات أخرى (أصلها opening).
--  الدفعات = مبالغ صادرة من الشركة للدائن (اتجاه المال معكوس كباقي النظام).
-- ==========================================================================
CREATE TABLE IF NOT EXISTS debt_accounts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_key VARCHAR(64)  NULL,                       -- مفتاح ثابت للحسابات الخاصة (inspection)
    name        VARCHAR(200) NOT NULL,                   -- اسم الجهة الدائنة
    kind        VARCHAR(20)  NOT NULL DEFAULT 'manual',  -- inspection | manual
    opening     DECIMAL(14,2) NOT NULL DEFAULT 0,        -- أصل المديونية (للحسابات اليدوية)
    note        VARCHAR(255) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_debt_key (account_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS debt_payments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT UNSIGNED NOT NULL,
    pay_date   DATE          NULL,
    amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
    note       VARCHAR(255)  NULL,
    is_visa    TINYINT(1)    NOT NULL DEFAULT 0,          -- دُفعت بالفيزا؟
    visa_card_id INT UNSIGNED NULL DEFAULT NULL,          -- كارت الفيزا المستخدَم (migrate_v22 — رصيد المكتب)
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_debt_account (account_id),
    CONSTRAINT fk_debtpay_account FOREIGN KEY (account_id) REFERENCES debt_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بذر حسابَي الكشف والضمان (منفصلين، كل واحد لمستحقه)
--   الكشف: المستحق يُختار من الإعدادات (يبدأ فارغاً).
--   الضمان: ياسر أبو عمر / كونتراكت (قابل للتعديل من الإعدادات).
INSERT IGNORE INTO debt_accounts (account_key, name, kind, opening, note) VALUES
    ('inspection', '', 'inspection', 0, 'مديونية الكشف — تُحتسب تلقائياً من حاويات الأذونات'),
    ('guarantee', 'ياسر أبو عمر / كونتراكت', 'guarantee', 0, 'مديونية الضمان — تُحتسب تلقائياً من حاويات الأذونات');

-- ═══════════════════════════════════════════════════════════════════
-- رصيد المكتب — كروت الفيزا (migrate_v22)
--   visa_cards: كارت الفيزا (اسم/رقم + صاحبه + بنك + رصيد افتتاحي).
--   visa_deposits: شحنات/إيداعات رصيد على الكارت (تزيد الرصيد).
--   الرصيد = افتتاحي + Σ الشحنات − Σ المدفوعات المعلَّمة is_visa=1 والمنسوبة للكارت
--   عبر عمود visa_card_id في: manual_expenses / permit_stages / movement_items / debt_payments.
-- ═══════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS visa_cards (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(180)  NOT NULL,           -- اسم/رقم الكارت
    owner           VARCHAR(180)  NULL,               -- صاحب الكارت
    bank            VARCHAR(180)  NULL,               -- البنك
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0, -- الرصيد الافتتاحي
    status          VARCHAR(20)   NOT NULL DEFAULT 'active',  -- active | inactive
    note            VARCHAR(255)  NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_owner (owner),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visa_deposits (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    card_id     INT UNSIGNED NOT NULL,
    dep_date    DATE          NULL,
    amount      DECIMAL(14,2) NOT NULL DEFAULT 0,   -- موجب = شحن للكارت
    note        VARCHAR(255)  NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_card (card_id),
    KEY idx_date (dep_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل الترحيلات: يمنع إعادة التطبيق وتعديل ملف سبق اعتماده.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version       INT UNSIGNED NOT NULL,
    filename      VARCHAR(190) NOT NULL,
    checksum      CHAR(64) NOT NULL,
    apply_mode    VARCHAR(12) NOT NULL DEFAULT 'applied',
    result        VARCHAR(12) NOT NULL DEFAULT 'success',
    execution_ms  INT UNSIGNED NOT NULL DEFAULT 0,
    applied_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version),
    UNIQUE KEY uq_schema_migration_file (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
