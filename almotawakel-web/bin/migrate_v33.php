<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v33 — تتبع الشحنات باللوكيشن (رابط عام للسائق على الواتساب).
 *
 * كل بوليصة (شحنة) يمكن توليد رابط عام لها يُبعَت للسائق يدويًا على الواتساب.
 * السائق يفتح الرابط ويضغط «ابعت موقعي» فيُسجَّل موقعه بالتاريخ والساعة،
 * أو يشغّل «التتبع المباشر» فيُرسَل موقعه تلقائيًا طول ما الصفحة مفتوحة.
 *
 * ⚠️ وحدة معزولة تمامًا (زي الملاحظات وأرشيف البوالص):
 *    - لا تدخل أي حساب مالي ولا عهدة ولا تقفيل يومي ولا مصروفات.
 *    - كل بياناتها وصفية (إحداثيات + وقت) — لا مبالغ إطلاقًا.
 *    - حذف التتبع لا يمسّ البوليصة، وحذف البوليصة لا يكسر التتبع (بلا FK — تحذير 3).
 *
 * آمن لإعادة التشغيل (idempotent).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\App;

function v33Say(string $m): void { echo $m . PHP_EOL; }

function v33TableExists(string $table): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
        [$table]
    );
}

v33Say('ترحيل v33 — تتبع الشحنات باللوكيشن…');

// ----------------------------------------------------------------------------
// 1) رحلات التتبع — رابط واحد لكل شحنة (بوليصة)
// ----------------------------------------------------------------------------
if (!v33TableExists('shipment_tracks')) {
    Database::exec("
        CREATE TABLE shipment_tracks (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            bill_id      INT UNSIGNED NOT NULL COMMENT 'البوليصة (الشحنة) — بلا FK',
            token        VARCHAR(24)  NOT NULL COMMENT 'مفتاح الرابط العام (عشوائي)',
            driver       VARCHAR(150) NULL COMMENT 'لقطة اسم السائق وقت التوليد',
            driver_phone VARCHAR(30)  NULL COMMENT 'رقم الواتساب',
            car_no       VARCHAR(120) NULL COMMENT 'لقطة رقم السيارة وقت التوليد',
            status       VARCHAR(12)  NOT NULL DEFAULT 'active' COMMENT 'active|done|cancelled',
            note         VARCHAR(255) NULL,
            expires_at   DATETIME     NULL COMMENT 'انتهاء صلاحية الرابط',
            opened_at    DATETIME     NULL COMMENT 'أول مرة فتح السائق الرابط',
            last_ping_at DATETIME     NULL,
            ping_count   INT UNSIGNED NOT NULL DEFAULT 0,
            base_url     VARCHAR(200) NULL COMMENT 'الرابط العام وقت التوليد — لكشف الروابط الميتة',
            created_by   VARCHAR(120) NULL,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_track_token (token),
            KEY idx_track_bill (bill_id),
            KEY idx_track_status (status, last_ping_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    v33Say('  + جدول shipment_tracks');
} else {
    v33Say('  = جدول shipment_tracks (موجود)');
}

// ----------------------------------------------------------------------------
// 2) نقاط الموقع — كل ضغطة/إرسال تلقائي = صف
// ----------------------------------------------------------------------------
if (!v33TableExists('track_pings')) {
    Database::exec("
        CREATE TABLE track_pings (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            track_id   INT UNSIGNED NOT NULL,
            lat        DECIMAL(10,7) NOT NULL,
            lng        DECIMAL(10,7) NOT NULL,
            accuracy_m INT UNSIGNED NULL COMMENT 'دقة الموقع بالأمتار (من جهاز السائق)',
            speed_kmh  DECIMAL(6,2)  NULL,
            address    VARCHAR(255)  NULL COMMENT 'العنوان النصي (OpenStreetMap — قد يكون فارغًا)',
            source     VARCHAR(8) NOT NULL DEFAULT 'manual' COMMENT 'manual=ضغط الزرار | live=تتبع مباشر',
            dist_m     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'المسافة من النقطة السابقة بالأمتار',
            ip         VARCHAR(45)  NULL,
            user_agent VARCHAR(200) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ping_track (track_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    v33Say('  + جدول track_pings');
} else {
    v33Say('  = جدول track_pings (موجود)');
}

// ----------------------------------------------------------------------------
// 3) أرقام السائقين — يتذكّر الرقم فيتعبّى تلقائيًا في المرة الجاية
// ----------------------------------------------------------------------------
if (!v33TableExists('driver_contacts')) {
    Database::exec("
        CREATE TABLE driver_contacts (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            driver     VARCHAR(150) NOT NULL,
            phone      VARCHAR(30)  NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_driver_contact (driver)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    v33Say('  + جدول driver_contacts');
} else {
    v33Say('  = جدول driver_contacts (موجود)');
}

// ----------------------------------------------------------------------------
// 4) الإعدادات — لا نكتب فوق قيمة موجودة (احترام أي تعديل سابق للمالك)
// ----------------------------------------------------------------------------
if (App::setting('track_expire_hours', null) === null) {
    App::setSetting('track_expire_hours', '48');
    v33Say('  + إعداد: صلاحية رابط التتبع = 48 ساعة');
}
if (App::setting('track_live_secs', null) === null) {
    App::setSetting('track_live_secs', '30');
    v33Say('  + إعداد: كل قد إيه يبعت موقعه في التتبع المباشر = 30 ثانية');
}
if (App::setting('public_base_url', null) === null) {
    App::setSetting('public_base_url', '');
    v33Say('  + إعداد: الرابط العام (يملأه سكربت «تشغيل عام على النت» تلقائيًا)');
}

v33Say('اكتمل ترحيل v33 بنجاح.');
