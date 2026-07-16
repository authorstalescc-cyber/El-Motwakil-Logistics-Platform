<?php
/**
 * ترحيل v18 — الإقفال اليومي والترحيل (day-end close / carry-forward):  جدول daily_closings
 *
 *   نظام إقفال يومي احترافي «زي البرامج الرائدة»:
 *   • آخر كل يوم تضغط «اقفل اليوم» فيتثبَّت الرصيد النهائي (closing_balance)
 *     ويصبح «رصيد بداية بكرة» (opening) — الأرقام تتجمّد ولا تتغيّر بعدها.
 *   • الرصيد المرحّل = opening + عهد مضافة − مصروفات − عهد سائقين (بعد خصم 100).
 *     أي أن الإقفال (والرصيد الحالي المبني عليه) يخصم المصروفات — بعكس محفظة
 *     العهدة القديمة التي كانت تتجاهلها.
 *   • رصيد العهدة الحالي = closing_balance لآخر يوم متقفل + حركة الأيام المفتوحة بعده.
 *
 *   القواعد (باختيار صاحب الشركة):
 *   • الإقفال بزر (يدوي بمراجعة)، تسلسليًا من الأقدم للأحدث (بلا فجوات).
 *   • الفتح تسلسليًا من الأحدث (لا يُفتح يوم قديم قبل فتح ما بعده).
 *   • القفل صارم: أي يوم متقفل لا تُقبل الكتابة عليه حتى يُفتح يدويًا.
 *   • «وجود صف لتاريخ في هذا الجدول = ذلك اليوم متقفل». الفتح يحذف الصف.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v18.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 18", 'hi');
say("  (الإقفال اليومي والترحيل — daily_closings)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

Database::exec(
    "CREATE TABLE IF NOT EXISTS daily_closings (
        id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        close_date      DATE          NOT NULL,
        opening         DECIMAL(14,2) NOT NULL DEFAULT 0,   -- رصيد بداية اليوم (= إقفال اليوم السابق)
        topups          DECIMAL(14,2) NOT NULL DEFAULT 0,   -- عهد مضافة للرصيد في اليوم
        expenses        DECIMAL(14,2) NOT NULL DEFAULT 0,   -- مصروفات اليوم (بنود + يدوية، بلا فيزا)
        driver_custody  DECIMAL(14,2) NOT NULL DEFAULT 0,   -- عهد السائقين في اليوم (بعد خصم 100 لكل بوليصة)
        closing_balance DECIMAL(14,2) NOT NULL DEFAULT 0,   -- المرحّل = opening + topups − expenses − driver_custody
        is_locked       TINYINT(1)    NOT NULL DEFAULT 1,   -- محجوز للتوسّع؛ وجود الصف نفسه = متقفل
        closed_by       VARCHAR(180)  NULL,                 -- من قام بالإقفال
        closed_at       TIMESTAMP     NULL DEFAULT NULL,    -- وقت الإقفال
        note            VARCHAR(255)  NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_close_date (close_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
say("✓ جدول daily_closings جاهز", 'ok');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v18 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
