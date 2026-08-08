<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v65 — حساب سائق سيارة المكتب وبيان كل بوليصة.
 *
 * الجداول الثلاثة تفصل بين:
 *   • تفاصيل مصروفات البوليصة (وتُزامَن مجمّعة مع أعمدة S:AF في bawalis).
 *   • الجزء الذي دفعه السائق من إيصال موجود في permit_stages.
 *   • العهد الإضافية/المرتجعات/التسويات على حساب السائق.
 *
 * ⚠️ تخصيص إيصال للسائق يغيّر «مصدر الدفع» فقط: الجزء المخصّص لا يخرج مرة
 * ثانية من درج المكتب، لكنه يظل مصروفًا على حساب السائق من عهدته.
 *
 * التشغيل: php bin/migrate_v65.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\OfficeDriverStatement;

$v65Dry    = in_array('--dry-run', $argv ?? [], true);
$v65Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v65Say = static function (string $m) use ($v65Direct): void {
    if ($v65Direct) { echo $m . "\n"; }
};
$v65HasTable = static function (string $t): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE() AND table_name=?', [$t]
    );
};

$v65Up = static function () use ($v65Dry, $v65Say, $v65HasTable): void {
    $safeBefore = round((float) Closing::currentBalance(), 2);

    $tables = [
        'office_driver_bill_items' =>
            "CREATE TABLE office_driver_bill_items (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                car_id       INT UNSIGNED NOT NULL,
                bill_id      INT UNSIGNED NOT NULL,
                category     VARCHAR(40) NOT NULL,
                description  VARCHAR(190) NULL,
                amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
                sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_by   VARCHAR(120) NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_odb_bill (bill_id, sort_order, id),
                KEY idx_odb_car (car_id, bill_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'office_driver_stage_allocations' =>
            "CREATE TABLE office_driver_stage_allocations (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                car_id       INT UNSIGNED NOT NULL,
                bill_id      INT UNSIGNED NOT NULL,
                stage_id     INT UNSIGNED NOT NULL,
                amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
                note         VARCHAR(190) NULL,
                created_by   VARCHAR(120) NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_odsa_bill_stage (bill_id, stage_id),
                KEY idx_odsa_stage (stage_id),
                KEY idx_odsa_car (car_id, bill_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'office_driver_movements' =>
            "CREATE TABLE office_driver_movements (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                car_id       INT UNSIGNED NOT NULL,
                bill_id      INT UNSIGNED NULL,
                move_date    DATE NOT NULL,
                kind         VARCHAR(30) NOT NULL,
                amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
                note         VARCHAR(190) NULL,
                created_by   VARCHAR(120) NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_odm_car_date (car_id, move_date, id),
                KEY idx_odm_bill (bill_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'office_driver_statements' =>
            "CREATE TABLE office_driver_statements (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                car_id       INT UNSIGNED NOT NULL,
                from_date    DATE NOT NULL,
                to_date      DATE NOT NULL,
                opening      DECIMAL(12,2) NOT NULL DEFAULT 0,
                closing      DECIMAL(12,2) NOT NULL DEFAULT 0,
                totals_json  TEXT NOT NULL,
                items_json   MEDIUMTEXT NOT NULL,
                created_by   VARCHAR(120) NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ods_car_date (car_id, to_date, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $name => $sql) {
        if ($v65HasTable($name)) {
            $v65Say("  • {$name} موجود — اتخطّى.");
        } elseif ($v65Dry) {
            $v65Say("  • (تجربة) هيتعمل جدول {$name}.");
        } else {
            Database::exec($sql);
            $v65Say("  ✓ اتعمل جدول {$name}");
        }
    }

    if (!$v65Dry) { OfficeDriverStatement::refreshReady(); }
    $safeAfter = round((float) Closing::currentBalance(), 2);
    if (abs($safeAfter - $safeBefore) > 0.005) {
        throw new RuntimeException('الترحيل الفارغ غيّر رصيد الخزنة — راجع التنفيذ فورًا.');
    }
    $v65Say('  ✓ إنشاء الجداول لم يغيّر رصيد الخزنة');
};

$v65Verify = static function () use ($v65Say): bool {
    $need = [
        'office_driver_bill_items' => ['car_id','bill_id','category','description','amount'],
        'office_driver_stage_allocations' => ['car_id','bill_id','stage_id','amount'],
        'office_driver_movements' => ['car_id','bill_id','move_date','kind','amount'],
        'office_driver_statements' => ['car_id','from_date','to_date','opening','closing','items_json'],
    ];
    foreach ($need as $table => $columns) {
        $have = array_column(Database::all(
            'SELECT column_name cn FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name=?', [$table]), 'cn');
        if (!$have) { $v65Say("✗ جدول {$table} ناقص."); return false; }
        foreach ($columns as $column) {
            if (!in_array($column, $have, true)) {
                $v65Say("✗ العمود {$column} ناقص من {$table}."); return false;
            }
        }
    }
    $v65Say('  ✓ الجداول والأعمدة المطلوبة موجودة');
    return true;
};

if ($v65Direct) {
    echo 'v65 — حساب سائق سيارة المكتب' . ($v65Dry ? ' (تجربة)' : '') . "\n";
    echo str_repeat('=', 58) . "\n";
    $v65Up();
    if ($v65Dry) { echo "\nشغّل بدون --dry-run للتطبيق.\n"; exit(0); }
    if (!$v65Verify()) { exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v65Up, 'verify' => $v65Verify];
