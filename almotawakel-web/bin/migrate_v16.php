<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v16 — تصنيف الحاويات على مستوى الإذن:  permits.container_type
 *
 *   يضيف حقل «تصنيف الحاويات» في بيانات الإذن نفسه (مثله في البوليصة).
 *   الفائدة: يُدخَل مرّة واحدة في الإذن ثم يُملأ تلقائيًا في نموذج إنشاء
 *   البوليصة داخل الحركة (يظل قابلاً للتعديل لكل بوليصة).
 *   يشارك نفس قائمة القيم المنسدلة `container_type` المستخدمة في البوالص.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v16.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

/** هل يوجد عمود في جدول؟ */
function colExists(string $table, string $col): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $col]
    );
}

/** أضف عموداً إن لم يكن موجوداً */
function addCol(string $table, string $col, string $definition): void
{
    if (colExists($table, $col)) {
        say("• $table.$col موجود مسبقاً — تخطٍّ", 'y');
        return;
    }
    Database::exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
    say("✓ $table.$col أُضيف", 'ok');
}

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 16", 'hi');
say("  (تصنيف الحاويات على مستوى الإذن)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

addCol('permits', 'container_type', "VARCHAR(150) NULL AFTER `containers`");

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v16 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
