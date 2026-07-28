<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v54 — وحدة «سيارات المكتب» (`/cars`).
 *
 * السيارات اللي الشركة مالكاها بنفسها (مش عربيات مستأجرة من ملّاك برّه).
 * الوحدة بتجمع في مكان واحد: أداء كل سيارة، الرحلات اللي مشيتها والحاويات،
 * الشركات والموانئ والوجهات، العهد اللي اتدفعت لسواقينها، ومصاريفها من
 * سولار وإصلاحات.
 *
 * الجدولان:
 *   • `office_cars`  — سجل السيارات (لوحة + مفتاح تطبيع فريد + بدائل تهجئة)
 *   • `car_expenses` — مصاريف السيارة (سولار/إصلاح/كاوتش/…)
 *
 * ══════════════════════════════════════════════════════════════════════════
 * ⚠️ قرار المالك (2026-07-26): **مصاريف السيارة معزولة عن خزنة المكتب.**
 * ══════════════════════════════════════════════════════════════════════════
 * `car_expenses` جدول **خاص بالوحدة** ما بيدخلش الخزنة ولا التقفيل اليومي
 * ولا مطابقة المراجعة ولا سجل المصروفات العام — نفس منطق العزل **بالبناء**
 * في `rep_misc_expenses` (v39) و`rep_driver_handovers` (v45) و`legacy_bawalis`
 * (v15): كل كود الخزنة القديم **عمره ما سمع عن الجدول ده**، فمفيش «استثناء»
 * لازم نفتكره في ٨ أماكن ونسيان واحد يبوّظ الرصيد.
 *
 * ⚠️ **الأثر المعروف والمقبول:** خزنة المكتب هتقول رقمًا **أعلى من الحقيقة**
 *    بمقدار السولار والإصلاحات، لأن الفلوس دي طلعت فعلاً وما اتخصمتش. القرار
 *    ده مأخوذ بوعي، والشاشة بتقوله بالنص للمالك. لو اتقرّر عكسه لاحقًا،
 *    الطريق الصح هو `manual_expenses` + عمود وسم `car_id` (زي `visa_card_id`)
 *    **مش** توصيل الجدول ده بالخزنة.
 *
 * ⚠️ **الربط بالرحلات نصّي بلا FK (تحذير ٣) وقراءة فقط.** الوحدة **ما بتلمسش
 *    ولا بوليصة واحدة** — بتقرا `bawalis` وبتطابق `car_no` بمفتاح اللوحة.
 *    البوالص المُرحَّلة (`is_legacy=1`) **مستبعدة** التزامًا بعزل الأرشيف.
 *
 * البذر: كل `car_no` مميّز في البوالص الحيّة بـ`car_owner` = «المكتب» بيتعمل
 * له سجل سيارة، والتهجئات المختلفة لنفس اللوحة بتتجمّع تحت سيارة واحدة
 * بمفتاح «الأرقام-الحروف» وتتسجّل الزيادة منها كبدائل. (متحقَّق فعليًّا:
 * «1474 ط ف ر» و«ط ف ر 1474» نفس السيارة.)
 *
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v54.php        (أو تلقائيًّا من bin/migration.php)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

$v54BalanceBefore = Closing::currentBalance();

$v54TableExists = static fn(string $t): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?", [$t]);

$v54Say = static function (string $line): void { echo $line . PHP_EOL; };

/**
 * مفتاح تطبيع اللوحة — **نسخة مستقلة عن الموديل عن قصد**.
 *
 * الترحيل ممنوع يعتمد على كود التطبيق (اللي ممكن يتغيّر بعده)، فالمنطق
 * متكرَّر هنا **مرة واحدة وقت البذر**. المصدر الوحيد وقت التشغيل هو
 * `OfficeCar::plateKey()`، والفاحص `verify_v54` بيتأكد إن الاتنين بيدّوا
 * نفس النتيجة على كل لوحة في القاعدة.
 */
$v54PlateKey = static function (?string $raw): string {
    $s = (string) $raw;
    // أرقام عربية/فارسية → إنجليزية (نفس ar2en_digits)
    $s = strtr($s, [
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    ]);
    // توحيد الألف والهمزات والتاء المربوطة والياء (نفس Maintenance::normkey)
    $s = strtr($s, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ٱ'=>'ا','ة'=>'ه','ى'=>'ي','ؤ'=>'و','ئ'=>'ي','ء'=>'']);
    $s = str_replace("\u{0640}", '', $s);            // تطويل
    $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s) ?? $s;   // تشكيل
    preg_match_all('/[0-9]+/u', $s, $dm);
    preg_match_all('/[\x{0621}-\x{064A}A-Za-z]+/u', $s, $lm);
    $digits  = implode('', $dm[0]);
    $letters = mb_strtolower(implode('', $lm[0]), 'UTF-8');
    if ($digits === '' && $letters === '') { return ''; }
    return $digits . '-' . $letters;
};

$v54Up = static function () use ($v54TableExists, $v54PlateKey, $v54Say): void {

    /* ── ① سجل السيارات ─────────────────────────────────────────────────── */
    if (!$v54TableExists('office_cars')) {
        Database::exec(<<<'SQL'
CREATE TABLE office_cars (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,                 -- اسم مختصر للسيارة (فولفو الكبيرة…)
  plate VARCHAR(80) NOT NULL,                 -- اللوحة زي ما بتتكتب
  plate_key VARCHAR(120) NOT NULL,            -- مفتاح التطبيع «أرقام-حروف» (فريد)
  aliases VARCHAR(500) NULL,                  -- تهجئات بديلة مفصولة بـ|
  model VARCHAR(120) NULL,                    -- الموديل/النوع
  driver VARCHAR(150) NULL,                   -- السائق الثابت (وصفي)
  status VARCHAR(12) NOT NULL DEFAULT 'active',   -- active | inactive
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_car_plate_key (plate_key),
  KEY idx_car_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $v54Say('  ✚ اتعمل جدول office_cars (سجل سيارات المكتب).');
    } else {
        $v54Say('  = جدول office_cars موجود بالفعل.');
    }

    /* ── ② مصاريف السيارة — معزولة عن الخزنة بالبناء ─────────────────────── */
    if (!$v54TableExists('car_expenses')) {
        // ⚠️ **مفيش `is_visa` ولا `visa_card_id` هنا عن قصد.** الوحدة معزولة
        //    عن كل مسارات الفلوس القائمة؛ إضافة وسم فيزا معناها إن المبلغ لازم
        //    يتخصم من كارت، وده بيفتح نقطة مزامنة جديدة (تحذير v45).
        Database::exec(<<<'SQL'
CREATE TABLE car_expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  car_id INT UNSIGNED NOT NULL,
  exp_date DATE NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'other',   -- مفتاح من OfficeCar::EXPENSE_KINDS
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ref_no VARCHAR(80) NULL,                          -- رقم فاتورة/إيصال المحطة أو الورشة
  vendor VARCHAR(150) NULL,                         -- المحطة / الورشة
  note VARCHAR(255) NULL,
  created_by VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_carexp_car_date (car_id, exp_date),
  KEY idx_carexp_date (exp_date),
  KEY idx_carexp_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $v54Say('  ✚ اتعمل جدول car_expenses (معزول عن الخزنة بالبناء).');
    } else {
        $v54Say('  = جدول car_expenses موجود بالفعل.');
    }

    /* ── ③ البذر من البوالص الحيّة ───────────────────────────────────────── */
    // ⚠️ قراءة فقط من `bawalis` — الترحيل **ما بيكتبش ولا بايت** في جدول البوالص.
    $rows = Database::all(
        "SELECT car_no, COUNT(*) trips, MAX(driver) drv, MAX(bill_date) last_d
         FROM bawalis
         WHERE is_legacy = 0 AND car_owner = 'المكتب'
           AND car_no IS NOT NULL AND TRIM(car_no) <> ''
         GROUP BY car_no
         ORDER BY trips DESC");

    $grouped = [];   // key => ['plate'=>الأشهر, 'spellings'=>[نص=>عدد], 'driver'=>…]
    foreach ($rows as $r) {
        $plate = trim((string) $r['car_no']);
        $key = $v54PlateKey($plate);
        if ($key === '') { continue; }
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['plate' => $plate, 'trips' => 0, 'spellings' => [], 'driver' => $r['drv']];
        }
        $grouped[$key]['spellings'][$plate] = (int) $r['trips'];
        $grouped[$key]['trips'] += (int) $r['trips'];
        // اللوحة المعتمدة = التهجئة الأكتر استخدامًا
        if ((int) $r['trips'] >= ($grouped[$key]['spellings'][$grouped[$key]['plate']] ?? 0)) {
            $grouped[$key]['plate'] = $plate;
        }
    }

    $added = 0; $touched = 0;
    foreach ($grouped as $key => $g) {
        $existing = Database::row("SELECT * FROM office_cars WHERE plate_key = ?", [$key]);
        // البدائل = كل التهجئات ما عدا المعتمدة
        $alts = array_values(array_filter(array_keys($g['spellings']),
            static fn ($s) => $s !== $g['plate']));

        if (!$existing) {
            Database::exec(
                "INSERT INTO office_cars (name, plate, plate_key, aliases, driver, status, note)
                 VALUES (?,?,?,?,?, 'active', ?)",
                [$g['plate'], $g['plate'], $key,
                 $alts ? implode('|', $alts) : null,
                 $g['driver'] ?: null,
                 'اتسجّلت تلقائيًّا من البوالص وقت ترحيل v54']);
            $added++;
            continue;
        }

        // ⚠️ **ما بنكتبش فوق أي تعديل عمله المالك** — بنستكمل البدائل الناقصة بس.
        $have = array_values(array_filter(array_map('trim',
            explode('|', (string) ($existing['aliases'] ?? '')))));
        $merged = $have;
        foreach ($alts as $a) {
            if ($a !== (string) $existing['plate'] && !in_array($a, $merged, true)) { $merged[] = $a; }
        }
        if (count($merged) !== count($have)) {
            Database::exec("UPDATE office_cars SET aliases = ? WHERE id = ?",
                [$merged ? implode('|', $merged) : null, (int) $existing['id']]);
            $touched++;
        }
    }

    $v54Say('  ✓ البذر: ' . count($grouped) . ' سيارة مميّزة من البوالص'
          . ' — ' . $added . ' اتضافت، ' . $touched . ' اتستكملت بدائلها.');

    foreach ($grouped as $key => $g) {
        if (count($g['spellings']) > 1) {
            $v54Say('    ⚠ لوحة بأكتر من تهجئة اتجمّعت تحت سيارة واحدة ('
                  . $key . '): ' . implode(' / ', array_keys($g['spellings'])));
        }
    }
};

$v54Verify = static function () use ($v54TableExists, $v54PlateKey, $v54BalanceBefore, $v54Say): bool {
    $ok = true;
    $fail = static function (string $m) use (&$ok, $v54Say): void { $ok = false; $v54Say('  ✗ ' . $m); };

    foreach (['office_cars', 'car_expenses'] as $t) {
        if (!$v54TableExists($t)) { $fail("الجدول {$t} مش موجود."); }
    }
    if (!$ok) { return false; }

    // ── مفتاح اللوحة فريد ومحسوب صح
    $bad = Database::all("SELECT id, plate, plate_key FROM office_cars");
    foreach ($bad as $c) {
        $expected = $v54PlateKey((string) $c['plate']);
        if ($expected !== (string) $c['plate_key']) {
            $fail("مفتاح اللوحة غلط للسيارة #{$c['id']} ({$c['plate']}): "
                . "المخزَّن «{$c['plate_key']}» والمحسوب «{$expected}».");
        }
    }

    // ── التهجئتان المعروفتان لازم يبقوا سيارة واحدة
    $k1 = $v54PlateKey('1474 ط ف ر');
    $k2 = $v54PlateKey('ط ف ر 1474');
    if ($k1 === '' || $k1 !== $k2) {
        $fail("تطبيع اللوحة مش بيجمع «1474 ط ف ر» مع «ط ف ر 1474» ({$k1} ≠ {$k2}).");
    }

    // ── ⚠️ العزل بالبناء: ممنوع أي ملف خزنة يذكر car_expenses
    $cashFiles = [
        'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/Expense.php',
        'app/Models/DailyReview.php', 'app/Models/Metrics.php',
        'app/Controllers/DailyClosingController.php', 'app/Controllers/CustodyController.php',
        'app/Controllers/ExpenseController.php', 'bin/backfill_closings.php',
    ];
    $root = dirname(__DIR__);
    foreach ($cashFiles as $rel) {
        $p = $root . '/' . $rel;
        if (is_file($p) && str_contains((string) file_get_contents($p), 'car_expenses')) {
            $fail("ملف الخزنة {$rel} بيذكر car_expenses — العزل بالبناء اتكسر.");
        }
    }

    // ── ⚠️ صفر أثر مالي
    $after = Closing::currentBalance();
    if (abs($after - $v54BalanceBefore) > 0.005) {
        $fail('رصيد الخزنة اتغيّر (' . number_format($v54BalanceBefore, 2)
            . ' ← ' . number_format($after, 2) . ') — الترحيل ده ممنوع يكون له أثر مالي.');
    }

    // ── ⚠️ الترحيل ممنوع يلمس البوالص
    $tagged = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'bawalis' AND column_name = 'car_id'");
    if ($tagged > 0) {
        $fail('اتلاقى عمود bawalis.car_id — الربط المفروض يفضل نصّيًّا بلا لمس جدول البوالص.');
    }

    if ($ok) {
        $cars = (int) Database::scalar("SELECT COUNT(*) FROM office_cars");
        $v54Say('  ✓ التحقق بعد التطبيق عدّى (' . $cars . ' سيارة في السجل).');
    }
    return $ok;
};

// ⚠️ مصيدة v49 الموثَّقة: الملف بيتنفّذ بطريقتين ولازم يشتغل صح في الاتنين.
$v54Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
if ($v54Direct) {
    echo "v54 — سيارات المكتب\n" . str_repeat('=', 60) . "\n";
    $v54Up();
    if ($v54Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v54.\n");
        exit(1);
    }
    echo "تمام.\n";
}

return ['up' => $v54Up, 'verify' => $v54Verify];
