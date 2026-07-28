<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v58 — تجهيز القوائم المقفولة (الموانئ · تصنيف الحاويات · التوكيلات · شركات الشحن).
 *
 * **مفيش أي تغيير في بنية القاعدة** — الترحيل ده بيملّي القوائم المنسدلة بالقيم
 * **المستخدَمة فعلاً** قبل ما نقفلها، وإلا الشغل اليومي بيقف: موظف بيفتح إذن
 * لتوكيل شغّال ومش لاقيه في القائمة.
 *
 * ⚠️ **القاعدة: بنزرع القيمة لو حارس الأسماء مش شايفها متعارضة مع قيمة في القائمة.**
 *    يعني «دبابة» و«كساحة ملاكى» (أوصاف حمولة مشروعة) بتدخل، و«20 قرش» (غلط
 *    مطبعي عن «20 قدم») **ما بتدخلش**. الترحيل بيطبع **الاتنين** عشان المالك يشوف
 *    إيه اللي اتزرع وإيه اللي اتساب.
 *
 * ⚠️ القيمة القديمة اللي ما اتزرعتش **ما بتضيعش**: السجل بتاعها بيفضل شغّال
 *    (`Controller::strictListError` بيقبلها على سجلها)، وهي بتظهر في الشاشة
 *    بشارة «مش في القائمة المعتمدة». المالك بيقرّر يضيفها أو يصلّحها من
 *    شاشة التنظيف.
 *
 * ⚠️⚠️ **البنية إلزامية من v49**: الملف لازم يرجّع `['up'=>callable,'verify'=>callable]`
 *      وإلا `MigrationRunner` بيرفضه و**بيوقف النشر كله** («الترحيل v58 يجب أن
 *      يعيد up وverify قابلتين للاستدعاء» — اتصادف فعليًا في deploy).
 *      و`verify` لازم ترجّع `true` بعد التطبيق، وإلا الإصدار ما بيتسجّلش في ledger.
 *
 * idempotent — إعادة التشغيل ما بتضيفش مكرَّر ولا بتلمس أي حاجة المالك عدّلها.
 * التشغيل:  php bin/migrate_v58.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\Lookup;
use App\Models\Maintenance;
use App\Models\NameSuggestion;

/** مصادر الاستخدام الفعلي لكل قائمة مقفولة. */
const V58_SOURCES = [
    'port'           => [['permits', 'port']],
    'agency'         => [['permits', 'agency']],
    'shipping_co'    => [['permits', 'shipping_co']],
    'container_type' => [['permits', 'container_type'], ['bawalis', 'container_type'], ['permit_movements', 'container_type']],
];

$v58Dry    = in_array('--dry-run', $argv ?? [], true);
$v58Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
/* الطباعة للتشغيل المباشر بس — تحت `deploy.sh` الترحيل بيتنادى برمجيًّا. */
$v58Say = static function (string $m) use ($v58Direct): void { if ($v58Direct) { echo $m . "\n"; } };

/** بيرجّع لكل قائمة: القيم المستخدَمة فعلاً وأكتر تهجئة لكل مفتاح. */
$v58Used = static function (array $cols): array {
    $used = [];
    foreach ($cols as [$t, $c]) {
        $rows = Database::all(
            "SELECT `$c` v, COUNT(*) n FROM `$t`
              WHERE `$c` IS NOT NULL AND TRIM(`$c`) <> '' GROUP BY `$c`"
        );
        foreach ($rows as $r) {
            $k = Maintenance::normkey((string) $r['v']);
            if ($k === '') { continue; }
            $used[$k][(string) $r['v']] = ($used[$k][(string) $r['v']] ?? 0) + (int) $r['n'];
        }
    }
    return $used;
};

$v58Up = static function () use ($v58Dry, $v58Say, $v58Used): void {
    /* ⚠️ الترحيل ده **وصفي بحت** — بيلمس `lookups` بس. رصيد الخزنة بيتقاس قبل
       وبعد جوّه نفس المعاملة، وأي فرق بيلغي كل حاجة. */
    $balanceBefore = (float) Custody::balance();
    Database::conn()->beginTransaction();

    try {
        $added = 0; $skipped = 0;
        foreach (V58_SOURCES as $type => $cols) {
            $label = Lookup::TYPES[$type] ?? $type;

            $have = [];
            foreach (Lookup::ofType($type) as $v) { $have[Maintenance::normkey((string) $v)] = true; }

            $used = $v58Used($cols);

            /* بنبدأ بالقائمة المعتمدة، والمزروع بينضمّ ليها فوريًّا عشان القيمة
               اللي بعدها تتقارن بيه كمان («22 طرد» بتتقارن بـ«طرد» بعد زرعها). */
            $approved = array_map('strval', Lookup::ofType($type));

            // الأكتر استخدامًا الأول — التهجئة الغالبة هي اللي تدخل القائمة
            uasort($used, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

            $in = []; $out = [];
            foreach ($used as $k => $spellings) {
                if (isset($have[$k])) { continue; }
                arsort($spellings);
                $best  = (string) array_key_first($spellings);
                $count = array_sum($spellings);

                /* ⚠️ المقارنة **بالقائمة المعتمدة وحدها** لا بالبيانات الحيّة — وإلا
                   كل قيمة موجودة في البيانات بتعدّي (وهي دي الحالة اللي بنفلترها). */
                $g = NameSuggestion::against($type, $best, $approved);
                if ($g['conflict'] !== null) {
                    $out[] = "{$best} ({$count}) ← يشبه «{$g['conflict']}»";
                    $skipped++;
                    continue;
                }
                if (!$v58Dry) { Lookup::add($type, $best, true); }
                $have[$k] = true; $approved[] = $best;
                $in[] = "{$best} ({$count})";
                $added++;
            }

            $v58Say(sprintf("\n%s — في القائمة: %d · اتزرع: %d · اتساب: %d",
                $label, count($have), count($in), count($out)));
            foreach ($in  as $x) { $v58Say("   + $x"); }
            foreach ($out as $x) { $v58Say("   – $x"); }
        }

        $balanceAfter = (float) Custody::balance();
        if (abs($balanceAfter - $balanceBefore) > 0.005) {
            throw new RuntimeException('رصيد الخزنة اتغيّر — الترحيل اتلغى.');
        }

        if ($v58Dry) {
            Database::conn()->rollBack();
            $v58Say("\n(تجربة) اترجع كل حاجة زي ما كانت.");
        } else {
            Database::conn()->commit();
        }
        $v58Say("\n" . str_repeat('=', 62));
        $v58Say("اتزرع: $added   |   اتساب: $skipped");
        $v58Say('رصيد الخزنة ثابت: ' . number_format($balanceBefore, 2));
    } catch (\Throwable $e) {
        if (Database::conn()->inTransaction()) { Database::conn()->rollBack(); }
        throw $e;
    }
};

/**
 * ⚠️ التحقّق بعد التطبيق: كل قيمة مستخدَمة فعلاً لازم تبقى **يا إما في القائمة
 *    يا إما متعارضة مع قيمة فيها** — مفيش قيمة بتتساب بلا سبب. ده اللي بيمنع
 *    تسجيل الإصدار في ledger لو الزرع فشل نصّ الطريق.
 *    في وضع `--dry-run` بنرجّع true من غير فحص (مفيش كتابة أصلاً).
 */
$v58Verify = static function () use ($v58Dry, $v58Say, $v58Used): bool {
    if ($v58Dry) { return true; }

    $orphans = [];
    foreach (V58_SOURCES as $type => $cols) {
        $have = [];
        foreach (Lookup::ofType($type) as $v) { $have[Maintenance::normkey((string) $v)] = true; }
        $approved = array_map('strval', Lookup::ofType($type));

        foreach ($v58Used($cols) as $k => $spellings) {
            if (isset($have[$k])) { continue; }
            arsort($spellings);
            $best = (string) array_key_first($spellings);
            if (NameSuggestion::against($type, $best, $approved)['conflict'] === null) {
                $orphans[] = "{$type}:{$best}";
            }
        }
    }

    if ($orphans !== []) {
        $v58Say('✗ قيم مستخدَمة وما اتزرعتش ولا متعارضة: ' . implode(' · ', array_slice($orphans, 0, 5)));
        return false;
    }
    $v58Say('  ✓ التحقق بعد التطبيق عدّى — كل قيمة مستخدَمة في القائمة أو متعارضة بوضوح.');
    return true;
};

// ⚠️ مصيدة v49 الموثَّقة: الملف بيتنفّذ بطريقتين ولازم يشتغل صح في الاتنين.
if ($v58Direct) {
    echo 'v58 — تجهيز القوائم المقفولة' . ($v58Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v58Up();
    if ($v58Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v58.\n");
        exit(1);
    }
    echo ($v58Dry ? "شغّل من غير --dry-run عشان يتطبّق.\n" : "✓ تمّ.\n");
}

return ['up' => $v58Up, 'verify' => $v58Verify];
