<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v3 — تحويل النظام إلى نموذج «الإصدار» (إذن للصادر / شهادة للوارد):
 *   1) إنشاء جدولَي permits و permit_stages + عمود bawalis.permit_id
 *   2) تجميع البوالص الحالية في إصدارات (نفس رقم الإذن/الشهادة ونوعها = إصدار واحد)
 *   3) تحويل بنود الحركات (المصروفات/الإيصالات/الخصومات) إلى بنود مراحل التايم لاين
 *
 * آمن لإعادة التشغيل (idempotent): يعالج فقط البوالص غير المربوطة بإصدار.
 * التشغيل:  php bin/migrate_v3.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

function colExists(string $table, string $col): bool {
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $col]
    );
}

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 3 (الإصدارات: إذن/شهادة)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// (1) الجداول الجديدة + العمود
$pdo->exec("CREATE TABLE IF NOT EXISTS permits (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permit_type  VARCHAR(10)  NOT NULL DEFAULT 'sader',
    permit_no    VARCHAR(120) NULL,
    permit_date  DATE         NULL,
    company      VARCHAR(180) NULL,
    client       VARCHAR(180) NULL,
    destination  VARCHAR(120) NULL,
    note         VARCHAR(255) NULL,
    status       VARCHAR(12)  NOT NULL DEFAULT 'open',
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_type (permit_type), KEY idx_no (permit_no), KEY idx_date (permit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS permit_stages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permit_id   INT UNSIGNED NOT NULL,
    stage_key   VARCHAR(30)  NOT NULL,
    direction   VARCHAR(10)  NULL,
    amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
    entry_date  DATE          NULL,
    ref_no      VARCHAR(120)  NULL,
    note        VARCHAR(255)  NULL,
    status      VARCHAR(12)   NOT NULL DEFAULT 'done',
    attachment  VARCHAR(255)  NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_permit (permit_id), KEY idx_stage (stage_key), KEY idx_date (entry_date),
    CONSTRAINT fk_stage_permit FOREIGN KEY (permit_id) REFERENCES permits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// عمود البنود المخصّصة (للنسخ التي شغّلت v3 قبل إضافته)
if (!colExists('permit_stages', 'direction')) {
    $pdo->exec("ALTER TABLE permit_stages ADD COLUMN direction VARCHAR(10) NULL AFTER stage_key");
    say("✓ أُضيف عمود permit_stages.direction", 'ok');
}

// عمود عدد الحاويات على مستوى الإذن (للكشف والضمان)
if (!colExists('permits', 'containers')) {
    $pdo->exec("ALTER TABLE permits ADD COLUMN containers INT NOT NULL DEFAULT 0 AFTER destination");
    say("✓ أُضيف عمود permits.containers", 'ok');
}
// حقول الحاويات على البوليصة (رقم + تصنيف + عدد) — كلها يدوية
if (!colExists('bawalis', 'container_type')) {
    $pdo->exec("ALTER TABLE bawalis ADD COLUMN container_type VARCHAR(80) NULL AFTER container_no");
    say("✓ أُضيف عمود bawalis.container_type", 'ok');
}
if (!colExists('bawalis', 'container_count')) {
    $pdo->exec("ALTER TABLE bawalis ADD COLUMN container_count INT NOT NULL DEFAULT 0 AFTER container_type");
    say("✓ أُضيف عمود bawalis.container_count", 'ok');
}
// إزالة قائمة «مقاسات الحاويات» القديمة (رقم الحاوية صار يدوياً)
$pdo->exec("DELETE FROM lookups WHERE list_type = 'container'");
// تصنيف الحاويات = قائمة اختيار (للبوليصة)
$pdo->exec("INSERT IGNORE INTO lookups (list_type, value) VALUES
  ('container_type','20 قدم'),('container_type','40 قدم'),('container_type','40 هاي كيوب'),
  ('container_type','20 مبرّد'),('container_type','40 مبرّد'),('container_type','فلات راك'),
  ('container_type','أوبن توب'),('container_type','تانك')");

// طبقة الحركات: جدول permit_movements + عمودَي movement_id + ترحيل البيانات لحركة افتراضية
$pdo->exec("CREATE TABLE IF NOT EXISTS permit_movements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT, permit_id INT UNSIGNED NOT NULL,
    seq INT NOT NULL DEFAULT 1, note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_permit (permit_id),
    CONSTRAINT fk_pmv_permit FOREIGN KEY (permit_id) REFERENCES permits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if (!colExists('bawalis', 'movement_id')) {
    $pdo->exec("ALTER TABLE bawalis ADD COLUMN movement_id INT UNSIGNED NULL AFTER permit_id, ADD KEY idx_movement (movement_id)");
}
if (!colExists('permit_stages', 'movement_id')) {
    $pdo->exec("ALTER TABLE permit_stages ADD COLUMN movement_id INT UNSIGNED NULL AFTER permit_id, ADD KEY idx_movement (movement_id)");
}
$mvStageKeys = "'takhsis','hemola','fees','ta3teeq','hay2a','extra','custom'";
$toSplit = Database::all(
    "SELECT DISTINCT p.id FROM permits p
     WHERE EXISTS (SELECT 1 FROM bawalis b WHERE b.permit_id=p.id AND b.movement_id IS NULL)
        OR EXISTS (SELECT 1 FROM permit_stages s WHERE s.permit_id=p.id AND s.movement_id IS NULL AND s.stage_key IN ($mvStageKeys))"
);
$nMoves = 0;
foreach ($toSplit as $row) {
    $pid = (int)$row['id'];
    $mid = Database::scalar("SELECT id FROM permit_movements WHERE permit_id=? ORDER BY seq LIMIT 1", [$pid]);
    if (!$mid) {
        Database::exec("INSERT INTO permit_movements (permit_id, seq, note) VALUES (?,1,?)", [$pid, 'حركة مُرحّلة']);
        $mid = Database::lastId();
        $nMoves++;
    }
    Database::exec("UPDATE bawalis SET movement_id=? WHERE permit_id=? AND movement_id IS NULL", [$mid, $pid]);
    Database::exec("UPDATE permit_stages SET movement_id=? WHERE permit_id=? AND movement_id IS NULL AND stage_key IN ($mvStageKeys)", [$mid, $pid]);
}
say("✓ طبقة الحركات: permit_movements + movement_id (حركات مُنشأة: $nMoves)", 'ok');

// توسيع عمود الإعدادات ليتّسع لإعدادات JSON (تكوين التايم لاين)
$svType = (string) Database::scalar(
    "SELECT DATA_TYPE FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'settings' AND column_name = 'setting_value'"
);
if (strtolower($svType) !== 'text') {
    $pdo->exec("ALTER TABLE settings MODIFY setting_value TEXT NOT NULL");
    say("✓ وُسِّع عمود settings.setting_value إلى TEXT", 'ok');
}

if (!colExists('bawalis', 'permit_id')) {
    $pdo->exec("ALTER TABLE bawalis ADD COLUMN permit_id INT UNSIGNED NULL AFTER id, ADD KEY idx_permit (permit_id)");
    say("✓ أُضيف عمود bawalis.permit_id", 'ok');
} else {
    say("• عمود bawalis.permit_id موجود مسبقاً", 'y');
}
say("✓ الجداول: permits, permit_stages", 'ok');

// خريطة تصنيف بند الحركة إلى مرحلة تايم لاين
function stageFor(string $itemType, ?string $category): string {
    $c = trim((string)$category);
    $map = [
        'إيصال تخصيص' => 'takhsis',
        'إيصال حمولة' => 'hemola',
        'إيصال هيئة'  => 'hay2a',
        'إيصال تعتيق' => 'ta3teeq',
        'تعتيق'       => 'ta3teeq',
    ];
    if (isset($map[$c])) { return $map[$c]; }
    if ($itemType === 'receipt') { return 'fees'; }   // الإيصالات العامة → الرسوم المحصلة
    return 'extra';                                    // باقي المصروفات/الخصومات → مصاريف إضافية
}

// (2)+(3) تجميع البوالص في إصدارات + ترحيل بنود الحركات
$bills = Database::all("SELECT * FROM bawalis WHERE permit_id IS NULL ORDER BY bill_date, id");
say("• بوالص غير مربوطة: " . count($bills), 'y');

$pdo->beginTransaction();
$nPermits = 0; $nLinked = 0; $nStages = 0;
$cache = [];   // "type|no" => permit_id

foreach ($bills as $b) {
    $bid  = (int)$b['id'];
    $type = ($b['bill_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader';
    $key  = trim((string)($type === 'warid' ? ($b['cert_number'] ?? '') : ($b['permit_no'] ?? '')));

    // إيجاد/إنشاء الإصدار
    $permitId = 0;
    if ($key !== '') {
        $ck = $type . '|' . $key;
        if (isset($cache[$ck])) {
            $permitId = $cache[$ck];
        } else {
            $found = Database::scalar(
                "SELECT id FROM permits WHERE permit_type = ? AND permit_no = ? LIMIT 1", [$type, $key]
            );
            $permitId = $found ? (int)$found : 0;
            $cache[$ck] = $permitId;   // قد تكون 0 مؤقتاً ثم نحدّثها بعد الإنشاء
        }
    }
    if (!$permitId) {
        // الأذونات المُرحّلة أرشيف: تُقفل تلقائياً (status='closed')
        Database::exec(
            "INSERT INTO permits (permit_type, permit_no, permit_date, company, client, destination, note, status)
             VALUES (?,?,?,?,?,?,?, 'closed')",
            [$type, ($key !== '' ? $key : null), $b['bill_date'] ?: null,
             $b['company'] ?: null, $b['declaration_owner'] ?: null, $b['destination'] ?: null,
             'مُرحّل من النظام القديم']
        );
        $permitId = Database::lastId();
        $nPermits++;
        if ($key !== '') { $cache[$type . '|' . $key] = $permitId; }
    }

    Database::exec("UPDATE bawalis SET permit_id = ? WHERE id = ?", [$permitId, $bid]);
    $nLinked++;

    // ترحيل بنود الحركات المرتبطة بهذه البوليصة
    $items = Database::all(
        "SELECT mi.item_type, mi.category, mi.amount, mi.note, m.movement_date
         FROM movement_items mi JOIN movements m ON m.id = mi.movement_id
         WHERE m.bill_id = ?", [$bid]
    );
    foreach ($items as $it) {
        $amt = (float)$it['amount'];
        if ($amt == 0) { continue; }
        $stage = stageFor($it['item_type'], $it['category']);
        $note  = trim((string)($it['note'] ?? ''));
        // للمصاريف الإضافية نحتفظ بالفئة في الملاحظة إن لم توجد ملاحظة
        if ($stage === 'extra' && $note === '' && !empty($it['category'])) { $note = $it['category']; }
        Database::exec(
            "INSERT INTO permit_stages (permit_id, stage_key, amount, entry_date, note, status)
             VALUES (?,?,?,?,?, 'done')",
            [$permitId, $stage, $amt, $it['movement_date'] ?: $b['bill_date'], $note ?: null]
        );
        $nStages++;
    }
}
$pdo->commit();

say("✓ إصدارات أُنشئت: $nPermits", 'ok');
say("✓ بوالص رُبطت بإصدارات: $nLinked", 'ok');
say("✓ بنود مراحل مُرحّلة من الحركات: $nStages", 'ok');

$totPermits = (int) Database::scalar("SELECT COUNT(*) FROM permits");
say("• إجمالي الإصدارات الآن: $totPermits", 'hi');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v3 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
