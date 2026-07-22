<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v25 — عدد الحاويات وتصنيفها على مستوى الحركة:
 *   permit_movements.container_count  (INT)
 *   permit_movements.container_type   (VARCHAR)
 *
 *   كل حركة (نقلة) داخل الإذن يكون لها عدد حاويات وتصنيف خاصّان بها،
 *   يُدخَلان وقت إنشاء/تعديل الحركة مستقلَّين عن بيانات الإذن.
 *   حقلان وصفيان معزولان تمامًا: لا يدخلان أي حساب مالي ولا تقفيل ولا عهدة —
 *   العدّاد 📦 في بلوك البوليصة يستخدم عدد حاويات الحركة كمقام لو وُجد،
 *   وإلا يرجع لإجمالي حاويات الإذن (توافقًا مع الحركات القديمة).
 *   يشارك تصنيفُ الحركة قائمةَ القيم المنسدلة `container_type` نفسها.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v25.php
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
say("  ترحيل نظام المتوكل إلى الإصدار 25", 'hi');
say("  (عدد الحاويات وتصنيفها على مستوى الحركة)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

addCol('permit_movements', 'container_count', "INT UNSIGNED NULL AFTER `note`");
addCol('permit_movements', 'container_type',  "VARCHAR(150) NULL AFTER `container_count`");

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v25 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
