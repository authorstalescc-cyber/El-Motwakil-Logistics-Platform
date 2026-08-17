<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v102 — **تعريفة النولون**: قائمة الأسعار الرسمية + توقّع النولون في الفاتورة.
 *
 * ═══ اللزمة (طلب المالك) ═══
 * «المفروض النظام يتوقّع النولون ويقولّي في كارت جانبي على الشمال عند إصدار
 *  الفاتورة: النولون المتوقّع وبناءً على إيه. علمًا أن القيمة الفعلية بتتحسب
 *  وفقًا للجهة والميناء وتصنيف الحاويات ومبرّد ولا لأ.»
 *
 * ═══ ليه جدول تعريفة مش تعلّم إحصائي وبس؟ ═══
 * ⚠️ **السعر مش عادة — السعر قرار معلن.** محرّك v98 بيتعلّم السعر المعتاد من
 *    فواتير العميل، وده ممتاز **لما يكون فيه تاريخ**. لكن عميل جديد، أو جهة
 *    الشركة ما شحنتلهاش قبل كده، أو ميناء جديد (بورسعيد) = **صفر اقتراح**.
 *    والمالك عنده قائمة أسعار معتمدة مكتوبة أصلاً، فالنظام كان بيسأله عن رقم
 *    هو نفسه نشره في عرض أسعار.
 *    فالتعريفة هي **الأرضية** اللي بتشتغل من أول يوم، والتاريخ بيعلوها لما
 *    يكون موجود — والشاشة بتوري **الاتنين** لما يختلفوا بدل ما تخفي واحد.
 *
 * ═══ العزل بالبناء (القاعدة ①) ═══
 * ⚠️ الجدولان دول **مرجع أسعار بس** — ولا مليم بيتحرّك منهم. مفيش خزنة ولا
 *    تقفيل ولا عهدة ولا فاتورة محفوظة بتتغيّر: التعريفة بتقترح رقمًا في خانة
 *    الفورم، والمالك هو اللي بيحفظ. والفاتورة المحفوظة **لقطة ثابتة** زي ما
 *    هي — تغيير التعريفة بكرة ما بيلمسش فاتورة اتعملت النهاردة.
 *    `verify_v102` بيفشل لو أي ملف مالي ذكر الجدولين.
 *
 * ═══ اللي بيتعمل ═══
 *   ①  `freight_tariffs`      — صف لكل (ميناء × اتجاه × خدمة × جهة × وحدة)
 *   ②  `freight_surcharges`   — الإضافات (ثلاجة بورسعيد · بياتة · تحويلة فوارغ)
 *
 * ⚠️ **`effective_from` جزء من المفتاح الفريد** عن قصد: الأسعار بتتغيّر كل سنة،
 *    والقايمة الجديدة لازم تدخل **جنب** القديمة لا فوقها — عشان فاتورة قديمة
 *    يفضل ينفع نراجع على أي تعريفة اتعملت. `FreightTariff` بياخد أحدث تعريفة
 *    سارية في تاريخ الإذن لا أحدث صف مطلقًا.
 *
 * ⚠️ `CREATE TABLE` بيعمل commit ضمني — فمفيش نمط معاملة هنا (زي v100/v101).
 *
 * التشغيل:  php bin/migrate_v102.php [--dry-run]
 *   وبعده:  php bin/seed_tariffs_2026.php     (تعبئة قوائم ٢٠٢٦)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$v102Dry    = in_array('--dry-run', $argv ?? [], true);
$v102Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v102Say = static function (string $m) use ($v102Direct): void { if ($v102Direct) { echo $m . "\n"; } };

$v102HasTable = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);

$v102Tables = [

    'freight_tariffs' => "CREATE TABLE freight_tariffs (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        port_group     VARCHAR(24)  NOT NULL COMMENT 'alex = الإسكندرية/الدخيلة/أبوقير | psd_east | psd_west',
        direction      VARCHAR(8)   NOT NULL DEFAULT 'any' COMMENT 'import = وارد | export = صادر | any = الاتنين',
        service        VARCHAR(12)  NOT NULL DEFAULT 'container' COMMENT 'container | reefer | lcl',
        zone_label     VARCHAR(190) NOT NULL COMMENT 'الجهة زي ما هي مكتوبة في عرض الأسعار (ممكن تجمع أكتر من اسم)',
        unit           VARCHAR(16)  NOT NULL COMMENT '20 | 40 | 2x20 | reefer40 | genset | jumbo_closed | jumbo_open | dababa_closed | dababa_open',
        price          DECIMAL(12,2) NOT NULL DEFAULT 0,
        effective_from DATE         NOT NULL COMMENT 'من إمتى القايمة دي سارية',
        source_ref     VARCHAR(60)  NULL COMMENT 'مرجع العرض (MT-Q-2026…)',
        active         TINYINT(1)   NOT NULL DEFAULT 1,
        updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_rate (port_group, direction, service, zone_label, unit, effective_from),
        KEY idx_lookup (port_group, direction, service, effective_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='تعريفة النولون المعتمدة (v102) — مرجع أسعار، لا أثر مالي'",

    'freight_surcharges' => "CREATE TABLE freight_surcharges (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        port_group     VARCHAR(24)  NOT NULL DEFAULT 'any',
        code           VARCHAR(32)  NOT NULL COMMENT 'reefer_trip | overnight_20 | overnight_40 | overnight_2x20 | empty_desert | empty_agri',
        label          VARCHAR(120) NOT NULL,
        amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
        effective_from DATE         NOT NULL,
        active         TINYINT(1)   NOT NULL DEFAULT 1,
        updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_sur (port_group, code, effective_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='إضافات النولون (v102) — ثلاجة/بياتة/تحويلة فوارغ'",
];

$v102Up = static function () use ($v102Dry, $v102Say, $v102HasTable, $v102Tables): void {
    $pdo = Database::conn();
    foreach ($v102Tables as $name => $ddl) {
        if ($v102HasTable($name)) { $v102Say("  • جدول $name موجود"); continue; }
        if (!$v102Dry) { $pdo->exec($ddl); }
        $v102Say("  ✓ جدول: $name");
    }
};

/**
 * ⚠️ التحقّق بيثبت **حالة بنيوية دائمة** (الجدول موجود · المفتاح الفريد فعّال
 *    و**فيه `effective_from`**) لا لقطة بيانات — الأسعار بتتغيّر كل سنة.
 */
$v102Verify = static function () use ($v102Say, $v102HasTable): bool {
    $ok = true;
    $need = [
        'freight_tariffs'    => ['uq_rate' => ['port_group', 'direction', 'service', 'zone_label', 'unit', 'effective_from']],
        'freight_surcharges' => ['uq_sur'  => ['port_group', 'code', 'effective_from']],
    ];
    foreach ($need as $table => $uniques) {
        if (!$v102HasTable($table)) { $v102Say("  ✗ جدول ناقص: $table"); $ok = false; continue; }
        foreach ($uniques as $idx => $cols) {
            $got = array_map(static fn ($r) => (string) $r['col'], Database::all(
                'SELECT COLUMN_NAME AS col FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? AND non_unique = 0
                  ORDER BY seq_in_index', [$table, $idx]));
            if ($got !== $cols) {
                $v102Say("  ✗ $table.$idx لازم يكون فريدًا على (" . implode(', ', $cols) . ')');
                $ok = false;
            }
        }
    }
    /* التعريفة مرجع مستقل — مفيش FK يربطها بإذن ولا فاتورة (الفاتورة لقطة). */
    $fks = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.key_column_usage
          WHERE table_schema = DATABASE()
            AND table_name IN ('freight_tariffs','freight_surcharges')
            AND referenced_table_name IS NOT NULL");
    if ($fks > 0) { $v102Say("  ✗ فيه $fks مفتاح خارجي — التعريفة لازم تفضل مرجعًا مستقلًّا"); $ok = false; }

    if ($ok) { $v102Say('  ✓ الجدولان موجودان بمفاتيحهما الفريدة (وفيها التاريخ) وبلا ارتباط خارجي.'); }
    return $ok;
};

if ($v102Direct) {
    echo 'v102 — تعريفة النولون' . ($v102Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";

    $snap = static fn (): array => [
        'inv'   => (int) Database::scalar('SELECT COUNT(*) FROM permit_invoices'),
        'total' => (float) Database::scalar('SELECT COALESCE(SUM(total),0) FROM permit_invoices'),
    ];
    $before = $snap();

    $v102Up();
    if ($v102Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }

    if ($v102Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v102.\n"); exit(1); }

    $after = $snap();
    if ($after['inv'] !== $before['inv'] || abs($after['total'] - $before['total']) > 0.005) {
        fwrite(STDERR, "✗✗ اتغيّرت فاتورة — مستحيل في ترحيل بنيوي.\n");
        exit(1);
    }
    printf("✓ %d فاتورة بإجمالي %s — ما اتغيّرش ولا مليم.\n",
        $after['inv'], number_format($after['total'], 2));

    echo "\n✓ تمّ. دلوقتي: php bin/seed_tariffs_2026.php\n";
}

return ['up' => $v102Up, 'verify' => $v102Verify];
