<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** فحص v45: فيزا المندوب وتسليم السائق وعزل الخزنة، داخل rollback كامل. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Closing;
use App\Models\DailyReconciliation;
use App\Models\RepCustody;

$database = (string) App::config('db')['name'];
if (!preg_match('/^almotawakel_test_[a-z0-9_]{6,48}$/', $database)) {
    fwrite(STDERR, "حارس DSN: فحص v45 يعمل على قاعدة اختبار معزولة فقط.\n");
    exit(2);
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? '✓ ' : '✗ ') . $message . PHP_EOL;
    if (!$ok) { $failures[] = $message; }
};
$column = static fn(string $table, string $name): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
    [$table, $name]
);
$table = static fn(string $name): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$name]
);

$check($column('rep_submissions', 'is_visa'), 'وسم الفيزا موجود في مدخلات المندوب');
$check($column('rep_misc_expenses', 'is_visa'), 'وسم الفيزا موجود في نثريات المندوب');
$check($table('rep_driver_handovers'), 'جدول تسليم عهدة السائق موجود');

$pdo = Database::conn();
$before = Closing::currentBalance();
$pdo->beginTransaction();
try {
    $tag = '__v45_' . bin2hex(random_bytes(4));
    Database::exec('INSERT INTO reps(name,status) VALUES(?,\'active\')', [$tag]);
    $repId = Database::lastId();
    Database::exec(
        'INSERT INTO rep_custody_deposits(rep_id,dep_date,amount,note) VALUES(?,?,?,?)',
        [$repId, '2037-02-03', 1000, $tag]
    );
    Database::exec(
        'INSERT INTO permits(permit_no,permit_date,status,company,client) VALUES(?,?,\'open\',?,?)',
        [$tag, '2037-02-03', $tag, $tag]
    );
    $permitId = Database::lastId();
    foreach ([[100, 0], [200, 1]] as [$amount, $visa]) {
        Database::exec(
            "INSERT INTO permit_stages
                (permit_id,stage_key,direction,amount,entry_date,status,is_visa,paid_by_rep_id)
             VALUES(?,'custom','expense',?,?,'done',?,?)",
            [$permitId, $amount, '2037-02-03', $visa, $repId]
        );
    }
    foreach ([[30, 0], [40, 1]] as [$amount, $visa]) {
        Database::exec(
            'INSERT INTO rep_misc_expenses(rep_id,exp_date,note,amount,is_visa) VALUES(?,?,?,?,?)',
            [$repId, '2037-02-03', $tag, $amount, $visa]
        );
    }
    foreach ([[50, 0], [60, 1]] as [$amount, $visa]) {
        Database::exec(
            "INSERT INTO rep_submissions(rep_id,note,entry_date,kind,amount,status,is_visa)
             VALUES(?,?,?,'expense',?,'pending',?)",
            [$repId, $tag, '2037-02-03', $amount, $visa]
        );
    }
    Database::exec(
        'INSERT INTO rep_driver_handovers(rep_id,driver_name,amount,handover_date,note) VALUES(?,?,?,?,?)',
        [$repId, $tag . '_driver', 70, '2037-02-03', $tag]
    );

    $panel = RepCustody::dashboard('2037-02-03', 10, 1000);
    $card = null;
    foreach ($panel['reps'] as $candidate) {
        if ((int) $candidate['id'] === $repId) { $card = $candidate; break; }
    }
    $check($card !== null, 'مندوب العينة ظاهر في لوحة العهدة');
    $check(abs((float) ($card['spent'] ?? -1) - 200.0) < 0.005,
        'المصروف من العهدة = كاش الإذن + كاش النثرية + تسليم السائق فقط');
    $check(abs((float) ($card['balance'] ?? -1) - 800.0) < 0.005,
        'رصيد الكارت يطابق RepCustody::balance بعد مصادر v45');
    $check(abs(RepCustody::balance($repId) - 800.0) < 0.005, 'مصدر الحقيقة يعيد الرصيد نفسه');
    $check((int) ($card['pending_cnt'] ?? -1) === 1 && abs((float) ($card['pending_sum'] ?? -1) - 50.0) < 0.005,
        'المنتظر على عهدة الكاش يستبعد بند الفيزا');
    $check((int) $panel['day']['paid_cnt'] === 1 && abs((float) $panel['day']['paid_sum'] - 100.0) < 0.005,
        'المعتمد من العهدة في اليوم يستبعد الفيزا');
    $check((int) $panel['day']['sent_cnt'] === 2 && abs((float) $panel['day']['sent_sum'] - 110.0) < 0.005,
        'نشاط الإرسال الوصفي يظل شاملًا للكاش والفيزا');

    $after = Closing::currentBalance();
    $check(abs(($before - $after) - 130.0) < 0.005,
        'الخزنة تخصم كاش الإذن والنثرية فقط ولا تخصم الفيزا أو تسليم السائق');
    $reconciliation = DailyReconciliation::view('2037-02-03');
    $check(abs((float) $reconciliation['general']['system'] - 30.0) < 0.005,
        'المطابقة العامة تعد نثرية الكاش');
    $check(abs((float) $reconciliation['general']['visa_excluded'] - 40.0) < 0.005,
        'المطابقة تعرض نثرية الفيزا كمستبعدة من الدرج');

    $closingSource = (string) file_get_contents(App::basePath('app/Models/Closing.php'));
    $check(!str_contains($closingSource, 'rep_driver_handovers'),
        'تسليم السائق معزول بنيويًا عن كود الخزنة');
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

$check(abs(Closing::currentBalance() - $before) < 0.005, 'rollback أعاد رصيد الخزنة كما كان');
if ($failures) {
    echo 'فشل ' . count($failures) . " اختبار.\n";
    exit(1);
}
echo "اكتمل فحص v45 بنجاح.\n";
