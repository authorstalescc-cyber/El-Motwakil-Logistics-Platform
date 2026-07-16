<?php
/**
 * تثبيت نظام المتوكل:
 *   1) إنشاء قاعدة البيانات والجداول
 *   2) إنشاء مستخدم الدخول الافتراضي
 *   3) نقل بيانات النظام القديم من old_data.json (إن وُجد) مع حسابات مطابقة
 *
 * التشغيل:  php bin/install.php          (تثبيت/تحديث)
 *           php bin/install.php --fresh   (حذف كل شيء والبدء من جديد)
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Calc;
use App\Models\PartyMaster;

$fresh = in_array('--fresh', $argv);
$cfg = App::config('db');

function say(string $s, string $c = ''){ $codes=['ok'=>"\033[32m",'err'=>"\033[31m",'hi'=>"\033[36m",'y'=>"\033[33m"];
    echo ($codes[$c]??'') . $s . "\033[0m\n"; }

say("═══════════════════════════════════════════════", 'hi');
say("  تثبيت نظام المتوكل لإدارة النقل والتخليص", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// (1) إنشاء قاعدة البيانات
try {
    $srv = Database::serverConn();
    if ($fresh) {
        $srv->exec("DROP DATABASE IF EXISTS `{$cfg['name']}`");
        say("• حُذفت القاعدة القديمة (--fresh)", 'y');
    }
    $srv->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    say("✓ قاعدة البيانات: {$cfg['name']}", 'ok');
} catch (Throwable $e) {
    say("✗ تعذّر الاتصال بخادم MySQL: " . $e->getMessage(), 'err');
    say("  تأكد أن الخادم يعمل:  brew services start mysql", 'y');
    exit(1);
}

// (2) تحميل المخطط
$schema = file_get_contents(App::basePath('database/schema.sql'));
// إزالة أسطر التعليقات ثم التقسيم على ';'
$lines = array_filter(explode("\n", $schema), fn($l) => !preg_match('/^\s*--/', $l));
$sql = implode("\n", $lines);
$statements = array_filter(array_map('trim', explode(";", $sql)));
$pdo = Database::conn();
foreach ($statements as $st) {
    if ($st !== '') { $pdo->exec($st); }
}
say("✓ الجداول أُنشئت (" . count($statements) . " أمر)", 'ok');

// (2ب) تشغيل كل الهجرات تلقائياً بالترتيب — شبكة أمان تمنع أي عمود/جدول ناقص.
//      كل الهجرات آمنة لإعادة التشغيل (idempotent)، وتُنفَّذ كعمليات منفصلة لأن
//      كل ملف يعرّف دواله الخاصة (say/colExists...) فلا يصحّ تضمينها في عملية واحدة.
$migFiles = glob(App::basePath('bin/migrate_v*.php'));
usort($migFiles, function ($a, $b) {
    preg_match('/migrate_v(\d+)\.php$/', $a, $ma);
    preg_match('/migrate_v(\d+)\.php$/', $b, $mb);
    return ((int)($ma[1] ?? 0)) <=> ((int)($mb[1] ?? 0));
});
$php = PHP_BINARY ?: 'php';
$migOk = 0; $migFail = 0;
foreach ($migFiles as $mf) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($mf) . ' 2>&1';
    exec($cmd, $out, $code);
    $ver = preg_replace('/.*migrate_(v\d+)\.php$/', '$1', $mf);
    if ($code === 0) { $migOk++; }
    else {
        $migFail++;
        say("✗ فشلت الهجرة $ver — " . trim(implode("\n", array_slice($out, -3))), 'err');
    }
    $out = [];
}
say($migFail ? "• الهجرات: $migOk نجحت، $migFail فشلت (راجع أعلاه)" : "✓ الهجرات: $migOk هجرة (كلها ناجحة)", $migFail ? 'y' : 'ok');

// (3) مستخدم الدخول الافتراضي
$du = App::config('default_user');
$exists = Database::scalar("SELECT COUNT(*) FROM users WHERE username = ?", [$du['username']]);
if (!$exists) {
    Database::exec(
        "INSERT INTO users (username, password_hash, display_name) VALUES (?,?,?)",
        [$du['username'], password_hash($du['password'], PASSWORD_DEFAULT), 'المدير']
    );
    say("✓ مستخدم الدخول: {$du['username']} / {$du['password']}", 'ok');
} else {
    say("• مستخدم الدخول موجود مسبقاً ({$du['username']})", 'y');
}

// (4) نقل بيانات النظام القديم
$jsonPath = App::basePath('database/old_data.json');
if (!is_file($jsonPath)) {
    say("• لا يوجد old_data.json — تخطّي نقل البيانات.", 'y');
    say("  لنقل البيانات القديمة:  python3 database/export_old_data.py  ثم أعد التشغيل.", 'y');
} else {
    $data = json_decode(file_get_contents($jsonPath), true);
    if (!$data) { say("✗ ملف old_data.json تالف.", 'err'); exit(1); }

    // تفريغ الجداول قبل النقل (لتجنّب التكرار عند إعادة التشغيل)
    foreach (['bawalis','takhlis','generators','balances'] as $t) {
        $pdo->exec("TRUNCATE TABLE `$t`");
    }

    // القوائم المنسدلة
    $lc = 0;
    foreach (($data['lookups'] ?? []) as $type => $values) {
        foreach ($values as $v) {
            if ($v !== null && $v !== '') {
                Database::exec("INSERT IGNORE INTO lookups (list_type, value) VALUES (?,?)", [$type, $v]);
                $lc++;
            }
        }
    }
    say("✓ القوائم المنسدلة: $lc عنصر", 'ok');

    // الأرصدة
    foreach (($data['balances'] ?? []) as $b) {
        Database::exec("INSERT INTO balances (party_type, name, balance) VALUES (?,?,?)",
            [$b['party_type'], $b['name'], $b['balance']]);
    }
    say("✓ الأرصدة: " . count($data['balances'] ?? []) . " سجل", 'ok');

    // الكيانات المحسوبة
    $importEntity = function(string $table, string $calc, array $rows) use ($pdo): int {
        if (!$rows) return 0;
        $pdo->beginTransaction();
        $stmt = null; $cols = null;
        foreach ($rows as $row) {
            $row = Calc::apply($calc, $row);
            if ($cols === null) {
                $cols = array_keys($row);
                $ph = array_map(fn($c)=>':'.$c, $cols);
                $stmt = $pdo->prepare("INSERT INTO `$table` (`".implode('`,`',$cols)."`) VALUES (".implode(',',$ph).")");
            }
            $params = [];
            foreach ($cols as $c) { $params[':'.$c] = $row[$c] ?? null; }
            $stmt->execute($params);
        }
        $pdo->commit();
        return count($rows);
    };

    $n1 = $importEntity('bawalis', 'bawalis', $data['bawalis'] ?? []);
    say("✓ البوالص: $n1 سجل", 'ok');
    $n2 = $importEntity('takhlis', 'takhlis', $data['takhlis'] ?? []);
    say("✓ التخليص: $n2 سجل", 'ok');
    $n3 = $importEntity('generators', 'generators', $data['generators'] ?? []);
    say("✓ المولدات: $n3 سجل", 'ok');
}

// (5) دليل الوكلاء والشركات (من شيت مرجع عملاء)
$seedPath = App::basePath('database/directory_seed.json');
if (is_file($seedPath)) {
    $seed = json_decode(file_get_contents($seedPath), true);
    $agents    = $seed['agents']    ?? [];
    $companies = $seed['companies'] ?? [];
    $links     = $seed['links']     ?? [];
    $renames   = $seed['renames']   ?? [];

    // لا نفرّغ جداول master: قد تكون مرتبطة بأذونات وفواتير، وقد تحوي بيانات أضافها المستخدم.
    // ensure يحترم الأسماء البديلة والدمج ويضيف فقط الناقص.
    $agentId = [];
    foreach ($agents as $a) {
        $master = PartyMaster::ensure('agent', (string)$a);
        if ($master) { $agentId[$a] = (int)$master['id']; }
    }
    $compId = [];
    foreach ($companies as $c) {
        $master = PartyMaster::ensure('company', (string)$c);
        if ($master) { $compId[$c] = (int)$master['id']; }
    }
    $nLink = 0;
    foreach ($links as [$a, $c]) {
        if (isset($agentId[$a], $compId[$c])) {
            Database::exec("INSERT IGNORE INTO agent_company (agent_id, company_id) VALUES (?,?)", [$agentId[$a], $compId[$c]]);
            $nLink++;
        }
    }

    // توحيد أملاء الوكلاء في الأرصدة (القوائم تُعاد بناؤها بعدها)
    foreach ($renames as $old => $new) {
        $has = Database::scalar("SELECT COUNT(*) FROM balances WHERE party_type='client' AND name=?", [$new]);
        if ($has) { Database::exec("DELETE FROM balances WHERE party_type='client' AND name=?", [$old]); }
        else      { Database::exec("UPDATE balances SET name=? WHERE party_type='client' AND name=?", [$new, $old]); }
    }
    // نضيف قيم المرجع ولا نحذف القيم المخصصة التي أضافها المستخدم.
    foreach ($agents    as $a) { Database::exec("INSERT IGNORE INTO lookups (list_type, value) VALUES ('client', ?)",  [$a]); }
    foreach ($companies as $c) { Database::exec("INSERT IGNORE INTO lookups (list_type, value) VALUES ('company', ?)", [$c]); }
    say("✓ دليل الوكلاء والشركات: " . count($agents) . " وكيل، " . count($companies) . " شركة، $nLink علاقة", 'ok');
} else {
    say("• لا يوجد directory_seed.json — تخطّي دليل الوكلاء والشركات.", 'y');
}

// بعض البيانات القديمة والدليل تُستورَد بعد أول مرور للهجرات؛ أعد v31 في النهاية
// لربط كل صف جديد بمعرّفات الـ master وبناء aliases. الهجرة idempotent.
$v31 = App::basePath('bin/migrate_v31.php');
if (is_file($v31)) {
    $out = [];
    exec(escapeshellarg($php) . ' ' . escapeshellarg($v31) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        say("✗ فشلت تسوية سلامة البيانات v31 — " . trim(implode("\n", array_slice($out, -3))), 'err');
        exit(1);
    }
    say('✓ تسوية معرفات الأطراف والدورة المحاسبية بعد الاستيراد', 'ok');
}

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل التثبيت بنجاح ✓", 'ok');
say("  شغّل النظام:  php bin/serve.php", 'hi');
say("═══════════════════════════════════════════════", 'hi');
