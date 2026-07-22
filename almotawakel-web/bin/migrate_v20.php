<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v20 — تحسينات فاتورة الأذونات (permit_invoices):
 *   • serial_no    — رقم مسلسل اختياري خاص بالعميل (فريد لا يتكرّر — يُفرَض في الكود).
 *   • custom_title — عنوان بديل اختياري للفاتورة عند الطباعة (بدل العنوان الافتراضي).
 *   • دعم نوع مستند جديد «مطالبة بالإيصالات» (doc_kind='claim') — العمود موجود بالفعل
 *     كـ VARCHAR فلا يحتاج تعديلاً؛ يُضاف فهرس فريد جزئي على serial_no للاطمئنان.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v20.php
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
say("  ترحيل نظام المتوكل إلى الإصدار 20", 'hi');
say("  (رقم مسلسل + عنوان مخصّص لفاتورة الأذونات)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

addCol('permit_invoices', 'serial_no',      "VARCHAR(120) NULL AFTER `internal_no`");
addCol('permit_invoices', 'custom_title',   "VARCHAR(180) NULL AFTER `serial_no`");
// اسم العميل المعروض في الطباعة فقط (اختياري) — لا يمسّ client الحقيقي المربوط بالإذن/البروفايل
addCol('permit_invoices', 'display_client', "VARCHAR(180) NULL AFTER `client`");

// فهرس على المسلسل لتسريع فحص التكرار (غير فريد على مستوى القاعدة لأن NULL مسموح ومتكرّر؛
// التفرّد للقيم غير الفارغة مفروض في الكود عبر PermitInvoice::serialExists).
$idxExists = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'permit_invoices' AND index_name = 'idx_serial'"
);
if (!$idxExists) {
    Database::exec("ALTER TABLE `permit_invoices` ADD KEY `idx_serial` (`serial_no`)");
    say("✓ فهرس idx_serial أُضيف", 'ok');
} else {
    say("• فهرس idx_serial موجود مسبقاً — تخطٍّ", 'y');
}

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v20 ✓", 'ok');
say("  (نسخة الويب PHP — لا يمسّ نظام الإكسل)", 'y');
say("═══════════════════════════════════════════════", 'hi');
