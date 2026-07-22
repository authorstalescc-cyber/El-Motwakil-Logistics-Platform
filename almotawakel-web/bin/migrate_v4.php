<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v4 — دليل الوكلاء والشركات:
 *   1) إنشاء جداول agents / companies / agent_company
 *   2) سيد الوكلاء (52) والشركات وعلاقاتها من database/directory_seed.json
 *      (المصدر: شيت «مرجع عملاء» — الوكيل = عميلنا، الشركة = مَن يُصدَر الإذن لصالحها)
 *   3) توحيد أملاء الوكلاء القديمة إلى المعتمدة (بيرفكت→برفكت ...) في:
 *      lookups(client) / balances / permits.client / shamil.client
 *   4) إعادة بناء قائمة العملاء المنسدلة (lookups.client) لتساوي وكلاء المرجع (52)
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v4.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 4 (دليل الوكلاء والشركات)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// (1) الجداول
$pdo->exec("CREATE TABLE IF NOT EXISTS agents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(180) NOT NULL, note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_agent_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS companies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(180) NOT NULL, note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_company_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS agent_company (
    agent_id INT UNSIGNED NOT NULL, company_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (agent_id, company_id), KEY idx_company (company_id),
    CONSTRAINT fk_ac_agent   FOREIGN KEY (agent_id)   REFERENCES agents(id)    ON DELETE CASCADE,
    CONSTRAINT fk_ac_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ الجداول: agents, companies, agent_company", 'ok');

// (2) قراءة ملف السيد
$seedPath = App::basePath('database/directory_seed.json');
if (!is_file($seedPath)) {
    say("✗ لا يوجد ملف السيد: database/directory_seed.json", 'err');
    say("  أنشئه بتشغيل مستخرج المرجع أولاً.", 'y');
    exit(1);
}
$seed = json_decode(file_get_contents($seedPath), true);
if (!$seed || empty($seed['agents'])) { say("✗ ملف السيد تالف أو فارغ.", 'err'); exit(1); }

$agents    = $seed['agents']    ?? [];
$companies = $seed['companies'] ?? [];
$links     = $seed['links']     ?? [];
$renames   = $seed['renames']   ?? [];

// سيد الوكلاء
foreach ($agents as $a) {
    Database::exec("INSERT IGNORE INTO agents (name) VALUES (?)", [$a]);
}
// سيد الشركات
foreach ($companies as $c) {
    Database::exec("INSERT IGNORE INTO companies (name) VALUES (?)", [$c]);
}
// خرائط الأسماء → id
$agentId = [];   foreach (Database::all("SELECT id, name FROM agents")    as $r) { $agentId[$r['name']]   = (int)$r['id']; }
$compId  = [];   foreach (Database::all("SELECT id, name FROM companies") as $r) { $compId[$r['name']]    = (int)$r['id']; }

// سيد العلاقات
$nLink = 0;
foreach ($links as [$a, $c]) {
    if (isset($agentId[$a], $compId[$c])) {
        Database::exec("INSERT IGNORE INTO agent_company (agent_id, company_id) VALUES (?,?)",
            [$agentId[$a], $compId[$c]]);
        $nLink++;
    }
}
say("✓ الوكلاء: " . count($agents) . " | الشركات: " . count($companies) . " | العلاقات: $nLink", 'ok');

// (3) توحيد أملاء الوكلاء القديمة إلى المعتمدة في بيانات النظام
//     (قائمة العملاء المنسدلة تُعاد بناؤها بالكامل في الخطوة 4، فلا نلمسها هنا)
$nRen = 0;
foreach ($renames as $old => $new) {
    // الأرصدة: ادمج القديم في الجديد (أو أعد التسمية)
    $newBalExists = Database::scalar("SELECT COUNT(*) FROM balances WHERE party_type='client' AND name=?", [$new]);
    if ($newBalExists) {
        Database::exec("DELETE FROM balances WHERE party_type='client' AND name=?", [$old]);
    } else {
        Database::exec("UPDATE balances SET name=? WHERE party_type='client' AND name=?", [$new, $old]);
    }
    // الأذونات والفواتير: أعد التسمية في حقل العميل
    Database::exec("UPDATE permits SET client=? WHERE client=?", [$new, $old]);
    if (Database::scalar("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema=DATABASE() AND table_name='shamil'")) {
        Database::exec("UPDATE shamil SET client=? WHERE client=?", [$new, $old]);
    }
    $nRen++;
}
say("✓ توحيد أملاء الوكلاء: $nRen اسم", 'ok');

// (4) إعادة بناء قائمة العملاء المنسدلة (client) لتساوي وكلاء المرجع (52)
//     (نُبقي بيانات الأرصدة/الأذونات القديمة كما هي — فقط قائمة الاختيار تصبح رسمية)
$pdo->exec("DELETE FROM lookups WHERE list_type='client'");
foreach ($agents as $a) {
    Database::exec("INSERT IGNORE INTO lookups (list_type, value) VALUES ('client', ?)", [$a]);
}
// إتاحة شركات المرجع في قائمة الشركات المنسدلة أيضاً (دون حذف الموجود)
foreach ($companies as $c) {
    Database::exec("INSERT IGNORE INTO lookups (list_type, value) VALUES ('company', ?)", [$c]);
}
say("✓ قائمة العملاء المنسدلة أُعيد بناؤها = " . count($agents) . " وكيل", 'ok');

$tA = (int) Database::scalar("SELECT COUNT(*) FROM agents");
$tC = (int) Database::scalar("SELECT COUNT(*) FROM companies");
$tL = (int) Database::scalar("SELECT COUNT(*) FROM agent_company");
say("• الإجمالي الآن — وكلاء: $tA | شركات: $tC | علاقات: $tL", 'hi');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v4 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
