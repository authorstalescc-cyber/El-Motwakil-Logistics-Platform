<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v15 — أرشيف البوالص القديمة (legacy_bawalis).
 *
 *   جدول أرشيف **مستقل تماماً** لاستيراد بوالص النظام القديم (Excel).
 *   * لا Foreign Keys، ولا يقرأه أي حساب في النظام (العهدة/التقفيل/المديونيات/
 *     التقارير/الفوترة/السداد) — لذلك استيراد آلاف البوالص القديمة لا يؤثّر على أي
 *     رقم في البرنامج الحالي. للعرض والبحث فقط.
 *   * يحمل كل حقول البوليصة القديمة بأمانة (نولون/عهدة/مصروفات/باقي/…).
 *
 * آمن لإعادة التشغيل (idempotent — CREATE TABLE IF NOT EXISTS).
 * التشغيل:  php bin/migrate_v15.php
 * الاستيراد بعده:  php bin/import_legacy_bawalis.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 15 (أرشيف البوالص القديمة)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

$pdo->exec("CREATE TABLE IF NOT EXISTS legacy_bawalis (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    src_row            INT UNSIGNED  NULL,               -- رقم الصف في ملف Excel الأصلي (تتبّع)
    declaration_owner  VARCHAR(190)  NULL,               -- صاحب البيان (موحّد)
    bill_date          DATE          NULL,               -- التاريخ
    bill_number        VARCHAR(60)   NULL,               -- رقم البوليصة
    classification     VARCHAR(150)  NULL,               -- التصنيف
    company            VARCHAR(190)  NULL,               -- اسم الشركة
    destination        VARCHAR(190)  NULL,               -- الجهة
    container_no       VARCHAR(190)  NULL,               -- رقم الحاوية
    car_no             VARCHAR(90)   NULL,               -- رقم السيارة
    driver             VARCHAR(190)  NULL,               -- اسم السائق
    car_owner          VARCHAR(190)  NULL,               -- صاحب السيارة
    -- الإيراد/التحصيل
    freight            DECIMAL(14,2) NOT NULL DEFAULT 0, -- نولون
    custody            DECIMAL(14,2) NOT NULL DEFAULT 0, -- عهدة
    transfer           DECIMAL(14,2) NOT NULL DEFAULT 0, -- تحويلة
    discount           DECIMAL(14,2) NOT NULL DEFAULT 0, -- خصم
    overnight_collection DECIMAL(14,2) NOT NULL DEFAULT 0, -- بياتة (تحصيل)
    remaining          DECIMAL(14,2) NOT NULL DEFAULT 0, -- باقى
    payment            DECIMAL(14,2) NOT NULL DEFAULT 0, -- دفعه
    total              DECIMAL(14,2) NOT NULL DEFAULT 0, -- اجمالى
    -- المصروفات
    driver_share       DECIMAL(14,2) NOT NULL DEFAULT 0, -- دور سواق
    assistant_share    DECIMAL(14,2) NOT NULL DEFAULT 0, -- دور تباع
    overnight_expense  DECIMAL(14,2) NOT NULL DEFAULT 0, -- بياتة (مصروف)
    expense            DECIMAL(14,2) NOT NULL DEFAULT 0, -- مصروف
    cards              DECIMAL(14,2) NOT NULL DEFAULT 0, -- كرتات
    tahia_masr         DECIMAL(14,2) NOT NULL DEFAULT 0, -- تحيا مصر
    thabtan            DECIMAL(14,2) NOT NULL DEFAULT 0, -- ثبتان
    z_kawtsh           DECIMAL(14,2) NOT NULL DEFAULT 0, -- ظ كاوتش
    permits_fee        DECIMAL(14,2) NOT NULL DEFAULT 0, -- تصاريح
    kawtsh             DECIMAL(14,2) NOT NULL DEFAULT 0, -- كاوتش
    other_expense      DECIMAL(14,2) NOT NULL DEFAULT 0, -- م اخرى (مجموع)
    gas                DECIMAL(14,2) NOT NULL DEFAULT 0, -- جاز
    -- محسوبة في النظام القديم (للأرشيف فقط)
    total_expense      DECIMAL(14,2) NOT NULL DEFAULT 0, -- اجمال المصروف
    custody_remaining  DECIMAL(14,2) NOT NULL DEFAULT 0, -- الباقى من العهدة
    car_net            DECIMAL(14,2) NOT NULL DEFAULT 0, -- صافى السيارة
    created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date    (bill_date),
    KEY idx_bill    (bill_number),
    KEY idx_owner   (declaration_owner),
    KEY idx_company (company),
    KEY idx_driver  (driver)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ جدول: legacy_bawalis (أرشيف معزول — بدون Foreign Keys، غير مرتبط بأي حساب)", 'ok');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v15 ✓", 'ok');
say("  الخطوة التالية:  php bin/import_legacy_bawalis.php", 'y');
say("═══════════════════════════════════════════════", 'hi');
