<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v26 — دمج البوالص القديمة في نظام البوالص الحي بوسم عزل:
 *   bawalis.is_legacy      TINYINT(1) NOT NULL DEFAULT 0  — بوليصة مُرحَّلة من الأرشيف
 *   bawalis.legacy_id      INT UNSIGNED NULL              — مرجع سجل legacy_bawalis الأصلي
 *   bill_payments.is_legacy TINYINT(1) NOT NULL DEFAULT 0 — دفعة مُرحَّلة من الإكسل (سداد تاريخي)
 *
 *   البوالص الموسومة is_legacy=1:
 *   • لا تدخل رصيد العهدة ولا الإقفال اليومي ولا المصروفات ولا مؤشرات لوحة التحكم
 *     ولا التقارير ولا بروفايلات الدليل ولا إحصاءات المساعد (فلتر is_legacy=0 في الكود).
 *   • تدخل عمدًا: قائمة البوالص + البحث + سداد البوالص + كشوف أصحاب البيان + المديونيات
 *     (قرار المالك: الديون القديمة حقيقية وتُتابَع من النظام).
 *   • بلا إذن ولا حركة (permit_id/movement_id = NULL) ولا يُنشأ لها أي منهما.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v26.php
 * البذر:    php bin/import_legacy_to_live.php  (بعد هذا الترحيل)
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

function colExists(string $table, string $col): bool {
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$table, $col]);
}
function idxExists(string $table, string $idx): bool {
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema=DATABASE() AND table_name=? AND index_name=?", [$table, $idx]);
}

say("═══════════════════════════════════════════", 'hi');
say("  ترحيل v26 — وسم البوالص القديمة المُرحَّلة", 'hi');
say("═══════════════════════════════════════════", 'hi');

if (!colExists('bawalis', 'is_legacy')) {
    Database::exec("ALTER TABLE bawalis ADD COLUMN is_legacy TINYINT(1) NOT NULL DEFAULT 0 AFTER pay_status");
    say("✓ bawalis.is_legacy أُضيف", 'ok');
} else { say("• bawalis.is_legacy موجود", 'y'); }

if (!colExists('bawalis', 'legacy_id')) {
    Database::exec("ALTER TABLE bawalis ADD COLUMN legacy_id INT UNSIGNED NULL AFTER is_legacy");
    say("✓ bawalis.legacy_id أُضيف", 'ok');
} else { say("• bawalis.legacy_id موجود", 'y'); }

if (!idxExists('bawalis', 'idx_bawalis_is_legacy')) {
    Database::exec("ALTER TABLE bawalis ADD INDEX idx_bawalis_is_legacy (is_legacy)");
    say("✓ فهرس idx_bawalis_is_legacy أُضيف", 'ok');
} else { say("• الفهرس موجود", 'y'); }

// NULL مسموح لكل البوالص الحية، لكن كل سجل أرشيفي لا يجوز أن يُرحَّل مرتين.
if (!idxExists('bawalis', 'uq_bawalis_legacy_id')) {
    Database::exec("ALTER TABLE bawalis ADD UNIQUE INDEX uq_bawalis_legacy_id (legacy_id)");
    say("✓ فهرس فريد uq_bawalis_legacy_id أُضيف", 'ok');
} else { say("• الفهرس الفريد legacy_id موجود", 'y'); }

if (!colExists('bill_payments', 'is_legacy')) {
    Database::exec("ALTER TABLE bill_payments ADD COLUMN is_legacy TINYINT(1) NOT NULL DEFAULT 0 AFTER note");
    say("✓ bill_payments.is_legacy أُضيف", 'ok');
} else { say("• bill_payments.is_legacy موجود", 'y'); }

say("✓ v26 اكتمل. البذر: php bin/import_legacy_to_live.php", 'ok');
