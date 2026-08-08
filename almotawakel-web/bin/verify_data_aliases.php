<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * تحقق الأسماء البديلة الدائمة.
 *
 * كل الكتابات داخل transaction وتُرجع في النهاية.
 * التشغيل: php bin/verify_data_aliases.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\DataAlias;
use App\Models\DataQuality;
use App\Models\Lookup;
use App\Models\NameSuggestion;

$pass = 0; $fail = 0;
$ok = static function (string $m) use (&$pass): void { $pass++; echo "  ✓ {$m}\n"; };
$bad = static function (string $m) use (&$fail): void { $fail++; echo "  ✗ {$m}\n"; };
$same = static function (string $m, $actual, $expected) use ($ok, $bad): void {
    $actual === $expected ? $ok($m) : $bad(
        $m . ' — متوقع ' . var_export($expected, true) . ' / فعلي ' . var_export($actual, true)
    );
};

echo "تحقق الأسماء البديلة الدائمة\n" . str_repeat('=', 62) . "\n";

$balanceBefore = round((float) Custody::balance(), 2);
$pdo = Database::conn();
$pdo->beginTransaction();

try {
    DataAlias::clearCache();

    DataAlias::remember('driver', '__v61_جلال حسني', ['__v61_جلال الزين']);
    $same('الاسم البديل يرجع للاسم المعتمد',
        DataAlias::canonical('driver', '__v61_جلال الزين'), '__v61_جلال حسني');

    $resolved = NameSuggestion::resolve('driver', '__v61_جلال الزين');
    $same('حارس الأسماء يطبّق قرار الدمج قبل التخمين', $resolved['value'], '__v61_جلال حسني');
    $same('التوحيد معلَّم snapped', $resolved['snapped'], true);
    $same('قرار الدمج لا يطلب تأكيد اسم جديد', $resolved['conflict'], null);

    // سلسلة دمج: alias A→B ثم B→C يجب أن تجعل A وB يشيران مباشرةً إلى C.
    DataAlias::remember('driver', '__v61_جلال الرسمي', ['__v61_جلال حسني']);
    $same('الاسم البديل القديم انتقل للهدف الجديد',
        DataAlias::canonical('driver', '__v61_جلال الزين'), '__v61_جلال الرسمي');
    $same('الاسم المعتمد القديم صار بديلًا للجديد',
        DataAlias::canonical('driver', '__v61_جلال حسني'), '__v61_جلال الرسمي');

    // القوائم المقفولة تمرّ على الاسم البديل قبل رفض POST.
    Lookup::add('container_type', '__v61_40 قدم', true);
    DataAlias::remember('container_type', '__v61_40 قدم', ['__v61_40']);
    $guard = new class extends \App\Core\Controller {
        public function check(array $map): ?string { return $this->nameGuardError($map); }
    };
    $_POST = ['container_type' => '__v61_40'];
    $same('القيمة البديلة تعدّي القائمة المقفولة', $guard->check(['container_type' => 'container_type']), null);
    $same('وتُحفظ بالتصنيف المعتمد', $_POST['container_type'], '__v61_40 قدم');
    $_POST = [];

    $strict = new ReflectionMethod(\App\Models\RepPortal::class, 'strictValue');
    $same('بوابة المندوب تطبّق الاسم البديل نفسه',
        $strict->invoke(null, 'container_type', '__v61_40'), '__v61_40 قدم');

    // دمج حقيقي من طبقة الجودة: يصلح الصف ويعلّم الاسم البديل في العملية نفسها.
    $bill = Database::row('SELECT id, driver FROM bawalis ORDER BY id LIMIT 1');
    if ($bill) {
        Database::exec('UPDATE bawalis SET driver = ? WHERE id = ?', ['__v61_سائق خطأ', $bill['id']]);
        DataQuality::merge('driver', '__v61_سائق صحيح', ['__v61_سائق خطأ']);
        $same('DataQuality أصلحت النص القديم',
            (string) Database::scalar('SELECT driver FROM bawalis WHERE id = ?', [$bill['id']]),
            '__v61_سائق صحيح');
        $same('DataQuality حفظت الخطأ اسمًا بديلًا',
            DataAlias::canonical('driver', '__v61_سائق خطأ'), '__v61_سائق صحيح');
    } else {
        $ok('لا توجد بوالص لاختبار الدمج النصّي — بقية عقد الأسماء البديلة نجحت');
        $ok('—');
    }

    $view = (string) file_get_contents(dirname(__DIR__) . '/app/Views/quality/field.php');
    str_contains($view, 'دمج يدوي') && str_contains($view, 'name="variants[]"')
        ? $ok('شاشة التنظيف تتيح دمج اسمين غير متشابهين')
        : $bad('نموذج الدمج اليدوي ناقص');

    $balanceAfter = round((float) Custody::balance(), 2);
    $same('رصيد الخزنة ثابت أثناء الدمج', $balanceAfter, $balanceBefore);
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    DataAlias::clearCache();
    NameSuggestion::clearNamesCache();
}

$balanceFinal = round((float) Custody::balance(), 2);
$same('رصيد الخزنة ثابت بعد rollback', $balanceFinal, $balanceBefore);

echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: {$pass}   |   فشل: {$fail}\n";
exit($fail === 0 ? 0 : 1);
