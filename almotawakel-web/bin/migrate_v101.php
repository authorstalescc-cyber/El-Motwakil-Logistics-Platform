<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v101 — **موديل الإيصالات المدرَّب**: النظام بيتعلّم من الأذون المقفولة
 *        إيه الإيصالات المتوقّعة في كل إذن، وبكام، وامتى.
 *
 * ═══ اللزمة (طلب المالك) ═══
 * «عاوز متوقّع الإيصالات يتوقّع المطلوب في كل إذن بناءً على البيانات المشابهة —
 *  كل كام عربية بتاخد كام هيئة، والجيش يكون امتى، والبوصلة امتى، والتعتيق امتى —
 *  ويتدرّب فعلاً على كل الأذون المقفولة.»
 *
 * ⚠️ **ده امتداد لمرشد v32 مش بديله.** v32 محرّك قواعد مكتوب بالإيد بيقول
 *    «الإذن ده المفروض فيه كذا» من النوع والتوكيل وإجابات الـchecklist.
 *    الموديل ده بيضيف الطبقة اللي القواعد ما بتعرفهاش: **الاحتمال** (٩٤٪ مش
 *    «مطلوب» صمّاء) · **العدد** (٤ سيارات = ٤ هيئة) · **الاقتران** (بوصلة ⇒
 *    إشعاع — القاعدة اللي كانت متحطوطة بالإيد في v32 اتقاست من الداتا فطلعت
 *    ٠.٩٤ برفع ٣.٩٨) · **اليوم المعتاد**. القواعد فضلت زي ما هي.
 *
 * ═══ العزل بالبناء (القاعدة ① في CLAUDE.md) ═══
 * الأربع جداول دي **ما بتمسّش** خزنة ولا تقفيل ولا عهدة ولا فاتورة — ولا قرش
 * واحد بيتحرّك بسببها. كلها **مشتقّة**: تتمسح كلها ويتعاد التدريب فيرجع نفس
 * الناتج بالحرف. عشان كده `verify_v101` بيفشل لو أي ملف خزنة/تقفيل ذكر أيًّا
 * منها. ومفيش FK على `permits` عن قصد: الموديل لقطة إحصائية، ومسح إذن قديم
 * ما يصحّش يمسح إحصاءه — التدريب الجاي هو اللي بيصحّحها.
 *
 * ═══ اللي بيتعمل ═══
 *   ①  `receipt_kinds`       — قاموس أنواع الإيصالات (بذرة في الكود + مكتشَف من الداتا)
 *   ②  `receipt_model_stats` — الإحصاء لكل (نطاق × نوع): احتمال + أساس الكمية + اليوم
 *   ③  `receipt_model_pairs` — الاقتران: «لما يبقى فيه A بيبقى فيه B»
 *   ④  `receipt_model_runs`  — سجل جولات التدريب ودقّتها المقيسة (holdout)
 *
 * ⚠️ **`scope_key` بيتخزّن مُوحَّدًا** (`ReceiptKind::scopeKey`) مش خام — «MSC»
 *    و«msc » و«M S C» لازم يبقوا مفتاحًا واحدًا وإلا الدليل بيتفتّت والاحتمال
 *    بينزل غلط. والـUNIQUE تحت هو اللي بيخلّي التدريب **idempotent**:
 *    `INSERT ... ON DUPLICATE KEY UPDATE` بدل «امسح كل حاجة وابني» اللي
 *    بيسيب الشاشة فاضية لو التدريب وقع في النص.
 *
 * ⚠️ `ALTER/CREATE TABLE` بيعمل commit ضمني — فمفيش نمط معاملة هنا (زي v100).
 *
 * التشغيل:  php bin/migrate_v101.php [--dry-run]
 *   وبعده:  php bin/train_receipts.php     (أول تدريب)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$v101Dry    = in_array('--dry-run', $argv ?? [], true);
$v101Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v101Say = static function (string $m) use ($v101Direct): void { if ($v101Direct) { echo $m . "\n"; } };

$v101HasTable = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);

/**
 * ⚠️ **`prec`/`rec` مش `precision`/`recall`** — `PRECISION` كلمة محجوزة في
 *    MySQL، والعمود باسمها بيدّي خطأ نحوي 1064 وقت الإنشاء مش تحذير.
 */
$v101Tables = [

    'receipt_kinds' => "CREATE TABLE receipt_kinds (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(60)  NOT NULL COMMENT 'الاسم القانوني للنوع (اللي بيتعرض)',
        norm_key    VARCHAR(160) NULL     COMMENT 'الصيغة المُوحَّدة للأنواع المكتشَفة (NULL للبذرة — تحديدها من tokens الكود)',
        origin      VARCHAR(10)  NOT NULL DEFAULT 'learned' COMMENT 'seed = معرَّف في الكود | learned = اكتشفه التدريب',
        hits        INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'كام بند اتصنّف عليه في آخر تدريب',
        first_seen  DATE NULL,
        last_seen   DATE NULL,
        active      TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '0 = المالك أخفاه فما يتوقّعش',
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_name (name),
        UNIQUE KEY uq_norm (norm_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='قاموس أنواع الإيصالات (v101) — مشتق، لا أثر مالي'",

    'receipt_model_stats' => "CREATE TABLE receipt_model_stats (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        scope       VARCHAR(24)  NOT NULL COMMENT 'نطاق التعلّم: type | type_agency | type_agency_ctype | type_client | type_port',
        scope_key   VARCHAR(190) NOT NULL COMMENT 'مفتاح النطاق مُوحَّدًا (ReceiptKind::scopeKey)',
        kind        VARCHAR(60)  NOT NULL COMMENT 'اسم النوع (receipt_kinds.name)',
        n_permits   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'أذون النطاق كلها',
        n_hits      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'اللي ظهر فيها النوع',
        prob        DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT 'الاحتمال بعد الانكماش ناحية النطاق الأعم',
        raw_prob    DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT 'النسبة الخام n_hits/n_permits — للعرض والشرح',
        qty_basis   VARCHAR(16)  NOT NULL DEFAULT 'fixed' COMMENT 'fixed = عدد ثابت | per_car = لكل سيارة | per_container = لكل حاوية',
        qty_rate    DECIMAL(8,3) NOT NULL DEFAULT 1 COMMENT 'المعدّل: الثابت نفسه، أو نصيب السيارة/الحاوية',
        qty_fit     DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT 'نسبة الأذون اللي الأساس ده ضبط عددها بالظبط',
        day_offset  SMALLINT     NULL COMMENT 'وسيط أيام الفرق بين تاريخ البند وتاريخ الإذن',
        amount_med  DECIMAL(12,2) NULL COMMENT 'المبلغ المعتاد للوحدة (استرشادي — لا يدخل أي حساب)',
        updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cell (scope, scope_key, kind),
        KEY idx_lookup (scope, scope_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='إحصاء الموديل المدرَّب (v101) — مشتق بالكامل، يتمسح ويتعاد بناؤه'",

    'receipt_model_pairs' => "CREATE TABLE receipt_model_pairs (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        permit_type VARCHAR(10)  NOT NULL COMMENT 'sader | warid — الاقتران بيختلف بينهم',
        kind_a      VARCHAR(60)  NOT NULL COMMENT 'الموجود',
        kind_b      VARCHAR(60)  NOT NULL COMMENT 'المتوقّع معه',
        n_a         INT UNSIGNED NOT NULL DEFAULT 0,
        n_both      INT UNSIGNED NOT NULL DEFAULT 0,
        p_ba        DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT 'احتمال B بشرط A',
        lift        DECIMAL(8,3) NOT NULL DEFAULT 0 COMMENT 'الرفع = P(B|A) ÷ P(B) — أقل من 1 يعني A بتقلّل B',
        updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pair (permit_type, kind_a, kind_b),
        KEY idx_a (permit_type, kind_a)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='اقتران الإيصالات (v101) — مشتق، لا أثر مالي'",

    'receipt_model_runs' => "CREATE TABLE receipt_model_runs (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ran_at      DATETIME     NOT NULL,
        source      VARCHAR(12)  NOT NULL DEFAULT 'manual' COMMENT 'manual = بضغطة | auto = من نبضة العامل',
        n_permits   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'أذون التدريب (المقفولة اللي فيها إيصالات)',
        n_receipts  INT UNSIGNED NOT NULL DEFAULT 0,
        n_kinds     INT UNSIGNED NOT NULL DEFAULT 0,
        n_cells     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'خلايا الإحصاء المتولّدة',
        prec_score  DECIMAL(6,4) NULL COMMENT 'الدقة المقيسة على أذون مستبعَدة من التدريب (holdout)',
        rec_score   DECIMAL(6,4) NULL COMMENT 'الاسترجاع على نفس الأذون',
        f1_score    DECIMAL(6,4) NULL,
        qty_exact   DECIMAL(6,4) NULL COMMENT 'نسبة العدد المتوقَّع اللي طلع مظبوط بالحرف',
        secs        DECIMAL(8,3) NOT NULL DEFAULT 0,
        note        VARCHAR(255) NULL,
        PRIMARY KEY (id),
        KEY idx_ran (ran_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='سجل جولات تدريب موديل الإيصالات (v101)'",
];

$v101Up = static function () use ($v101Dry, $v101Say, $v101HasTable, $v101Tables): void {
    $pdo = Database::conn();
    foreach ($v101Tables as $name => $ddl) {
        if ($v101HasTable($name)) { $v101Say("  • جدول $name موجود"); continue; }
        if (!$v101Dry) { $pdo->exec($ddl); }
        $v101Say("  ✓ جدول: $name");
    }
};

/**
 * ⚠️ التحقّق بيثبت **حالة بنيوية دائمة** (الجدول موجود · المفتاح الفريد فعّال ·
 *    الأعمدة الحاكمة موجودة) **لا لقطة بيانات** — الجداول دي بتفضى وتتملى مع
 *    كل تدريب، فأي فحص على عدد صفوف بيبقى غير قابل لإعادة التحقّق.
 */
$v101Verify = static function () use ($v101Say, $v101HasTable): bool {
    $ok = true;
    $need = [
        'receipt_kinds'       => ['uq_name' => ['name'], 'uq_norm' => ['norm_key']],
        'receipt_model_stats' => ['uq_cell' => ['scope', 'scope_key', 'kind']],
        'receipt_model_pairs' => ['uq_pair' => ['permit_type', 'kind_a', 'kind_b']],
        'receipt_model_runs'  => [],
    ];
    foreach ($need as $table => $uniques) {
        if (!$v101HasTable($table)) { $v101Say("  ✗ جدول ناقص: $table"); $ok = false; continue; }
        foreach ($uniques as $idx => $cols) {
            $got = Database::all(
                'SELECT COLUMN_NAME AS col FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? AND non_unique = 0
                  ORDER BY seq_in_index', [$table, $idx]);
            $got = array_map(static fn ($r) => (string) $r['col'], $got);
            if ($got !== $cols) {
                $v101Say("  ✗ $table.$idx لازم يكون فريدًا على (" . implode(', ', $cols) . ') — التدريب بيعتمد عليه في ON DUPLICATE KEY');
                $ok = false;
            }
        }
    }
    /* الجداول مشتقّة بالكامل ⇒ ممنوع FK على `permits`: مسح إذن ما يصحّش
       يمسح إحصاءه، والتدريب الجاي هو اللي بيصحّح. */
    $fks = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.key_column_usage
          WHERE table_schema = DATABASE()
            AND table_name IN ('receipt_kinds','receipt_model_stats','receipt_model_pairs','receipt_model_runs')
            AND referenced_table_name IS NOT NULL");
    if ($fks > 0) { $v101Say("  ✗ فيه $fks مفتاح خارجي — الجداول دي لازم تفضل مشتقّة ومستقلّة"); $ok = false; }

    if ($ok) { $v101Say('  ✓ الأربع جداول موجودة بمفاتيحها الفريدة وبلا أي ارتباط خارجي.'); }
    return $ok;
};

if ($v101Direct) {
    echo 'v101 — موديل الإيصالات المدرَّب' . ($v101Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";

    /* ⚠️ لقطة مالية قبل/بعد: الترحيل ده **بنيوي بالكامل** فلازم يثبت إن ولا
       رقم مالي اتحرّك — نفس حارس v100. */
    $snap = static fn (): array => [
        'stages' => (int) Database::scalar('SELECT COUNT(*) FROM permit_stages'),
        'amount' => (float) Database::scalar('SELECT COALESCE(SUM(amount),0) FROM permit_stages'),
    ];
    $before = $snap();

    $v101Up();
    if ($v101Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }

    if ($v101Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v101.\n"); exit(1); }

    $after = $snap();
    if ($after['stages'] !== $before['stages'] || abs($after['amount'] - $before['amount']) > 0.005) {
        fwrite(STDERR, "✗✗ اتغيّر بند إيصال — مستحيل في ترحيل بنيوي.\n");
        exit(1);
    }
    printf("✓ %d بند إيصال بإجمالي %s — ما اتغيّرش ولا مليم.\n",
        $after['stages'], number_format($after['amount'], 2));

    echo "\n✓ تمّ. دلوقتي: php bin/train_receipts.php   ثم   php bin/verify_v101.php\n";
}

return ['up' => $v101Up, 'verify' => $v101Verify];
