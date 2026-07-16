<?php
/**
 * ترحيل v32 — مرشد الإيصالات الذكي (checklist «الناقص في كل إذن»).
 *
 * يستبدل القائمة الثابتة القديمة (تخصيص/حمولة/تعتيق/هيئة لكل الأذونات) بمحرّك قواعد
 * مشروط حسب: نوع الإذن (صادر/وارد) + التوكيل + عدد الحاويات + إجابات checklist سريعة.
 *
 * ⚠️ استرشادي بحت — لا يمنع أي إدخال/فوترة/تقفيل، ولا يمسّ أي حساب مالي ولا العهدة.
 * كل الأعمدة الجديدة فارغة/صفرية افتراضيًا فالأذونات القديمة لا تتأثّر.
 *
 * آمن لإعادة التشغيل (idempotent).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\App;

function v32Say(string $m): void { echo $m . PHP_EOL; }

function v32ColumnExists(string $table, string $column): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    );
}

function v32AddColumn(string $table, string $column, string $def): void
{
    if (!v32ColumnExists($table, $column)) {
        Database::exec("ALTER TABLE `$table` ADD COLUMN `$column` $def");
        v32Say("  + $table.$column");
    } else {
        v32Say("  = $table.$column (موجود)");
    }
}

v32Say('ترحيل v32 — مرشد الإيصالات الذكي…');

// ----------------------------------------------------------------------------
// 1) أعمدة الـchecklist على الإذن (كلها وصفية/استرشادية، لا تدخل أي حساب)
// ----------------------------------------------------------------------------
v32AddColumn('permits', 'chk_ateeq',     "VARCHAR(10) NULL COMMENT 'مكان التعتيق: port|yard|sacia|none'");
v32AddColumn('permits', 'chk_load',      "VARCHAR(10) NULL COMMENT 'مكان الحمولة (صادر): port|outside'");
v32AddColumn('permits', 'chk_nabatshia', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'السائق عدّى 11 ومحمّلش؟'");
v32AddColumn('permits', 'chk_lamanea',   "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'وارد: لا مانع علينا؟'");
v32AddColumn('permits', 'chk_dismissed', "VARCHAR(255) NULL COMMENT 'إيصالات قال المستخدم مش محتاجها في هذا الإذن (labels مفصولة بـ |)'");

// ----------------------------------------------------------------------------
// 2) قوائم قواعد التوكيلات — قابلة للتعديل من الإعدادات (JSON arrays)
//    لا نكتب فوق قيمة موجودة (احترام أي تعديل سابق للمالك).
// ----------------------------------------------------------------------------
if (App::setting('receipt_rules_insurance_agencies', null) === null) {
    App::setSetting('receipt_rules_insurance_agencies', json_encode(['ميرسك', 'هابج لويد'], JSON_UNESCAPED_UNICODE));
    v32Say('  + إعداد: توكيلات التأمين (تُغني عن التخصيص) = ميرسك، هابج لويد');
}
if (App::setting('receipt_rules_ateeq_agencies', null) === null) {
    App::setSetting('receipt_rules_ateeq_agencies', json_encode(['أركاس'], JSON_UNESCAPED_UNICODE));
    v32Say('  + إعداد: توكيلات تعتيق الحاويات (صادر) = أركاس');
}

v32Say('اكتمل ترحيل v32 بنجاح.');
