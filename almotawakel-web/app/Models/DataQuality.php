<?php
namespace App\Models;

use App\Core\Database;

/**
 * تنظيف البيانات — كشف المسميات المكرَّرة ودمجها **بقرار المالك**.
 *
 * ليه موجودة: حارس الأسماء (المرحلة ١) بيمنع الدنس **الجديد** وبس. القيمة
 * الملوَّثة المتسجّلة بالفعل بيطابقها المستوى ١ حرفيًّا وبتعدّي — فلازم حد
 * ينضّف القديم، وإلا «ميناء الإسكندرية» و«الإسكندرية» يفضلوا صفّين في كل تقرير.
 *
 * ⚠️ **البديل الواعي لزرار «توحيد المسميات» الأعمى** اللي كان في الإعدادات:
 *    ده كان بيدمج من غير ما يوريك هيدمج إيه في إيه، وبيختار الاسم المعتمد
 *    لوحده (الأكتر استخدامًا ثم الأطول). هنا **المالك بيشوف كل مجموعة بعدد
 *    استخدام كل تهجئة وبيختار بنفسه**.
 *
 * ⚠️ **الأسماء اللي ليها سجل قانوني** (شركة/وكيل/صاحب بيان) **ما بتتدمجش من
 *    هنا** — دمجها بيحرّك `*_id` و`party_aliases` و`agent_company`، وده شغل
 *    `PartyMaster::merge()` من `/directory`. الشاشة بتوصّلك لهناك بدل ما
 *    تكرّر المنطق ناقصًا (تحديث نصّي بس كان هيسيب الـid مشاور على سجل متشال).
 *
 * ⚠️ **العزل المالي:** الوحدة بتحدّث **أعمدة وصفية بس** (اسم/جهة/تصنيف) —
 *    ولا بتلمس مبلغ ولا خزنة ولا تقفيل ولا عهدة. `verify_data_quality` بيقيس
 *    رصيد الخزنة قبل↔بعد الدمج.
 */
class DataQuality
{
    /** أقصى عدد قيم بنفحصها للتشابه في المرة (حماية من O(n²) على قوائم ضخمة). */
    private const SCAN_LIMIT = 1200;

    /** كل الحقول اللي بتتراجع — من `Maintenance::groups()` (المصدر الوحيد). */
    public static function fields(): array
    {
        $out = [];
        foreach (Maintenance::groups() as $label => $g) {
            $out[$g['key']] = $g + ['label' => $label];
        }
        return $out;
    }

    /**
     * كل القيم المستخدَمة للحقل + عدد استخدامها (من كل أعمدته + قائمته المنسدلة).
     * @return array<string,int> القيمة الخام => العدد
     */
    public static function values(string $key): array
    {
        $g = Maintenance::group($key);
        if (!$g) { return []; }

        $counts = [];
        foreach ($g['cols'] as $c) {
            [$t, $col] = $c; $extra = $c[2] ?? '';
            if (!Maintenance::hasTable($t)) { continue; }
            $w = "`$col` IS NOT NULL AND TRIM(`$col`) <> ''" . ($extra ? " AND $extra" : '');
            foreach (Database::all("SELECT `$col` v, COUNT(*) c FROM `$t` WHERE $w GROUP BY `$col`") as $r) {
                $counts[(string) $r['v']] = ($counts[(string) $r['v']] ?? 0) + (int) $r['c'];
            }
        }
        // قيم القائمة المنسدلة بعدد 0 — عشان المفردة الميتة تبان وتتشال
        if (!empty($g['lookup'])) {
            foreach (Lookup::ofType($g['lookup']) as $v) {
                if (!isset($counts[(string) $v])) { $counts[(string) $v] = 0; }
            }
        }
        arsort($counts);
        return $counts;
    }

    /**
     * مجموعات الاشتباه للحقل.
     *
     * نوعان:
     *   `same`    تهجئات مفتاحها واحد (أ/إ/ة/ى) ⇒ **نفس الشيء قطعًا** — دمجها آمن.
     *   `similar` الحارس شايفها متقاربة (ميناء X ≡ X · احتواء · غلط مطبعي) ⇒
     *             **محتاجة عين المالك** لأنها ممكن تكون كيانين مختلفين فعلاً.
     *
     * @return array{same:list<array>,similar:list<array>,total:int,scanned:int}
     */
    public static function clusters(string $key): array
    {
        $g = Maintenance::group($key);
        if (!$g) { return ['same' => [], 'similar' => [], 'total' => 0, 'scanned' => 0]; }

        $counts  = self::values($key);
        $suggest = $g['suggest'];

        // ── (أ) نفس المفتاح ⇒ نفس الشيء قطعًا ──────────────────────────────
        $byKey = [];
        foreach ($counts as $v => $n) {
            $k = Maintenance::normkey((string) $v);
            if ($k === '') { continue; }
            $byKey[$k][(string) $v] = $n;
        }
        $same = [];
        foreach ($byKey as $k => $spellings) {
            if (count($spellings) < 2) { continue; }
            arsort($spellings);
            $same[] = ['kind' => 'same', 'members' => $spellings, 'suggested' => (string) array_key_first($spellings)];
        }

        // ── (ب) متشابه — بيمرّ على نفس حارس الإدخال فمفيش تعريفين للتشابه ──
        $reps = [];
        foreach ($byKey as $k => $spellings) { arsort($spellings); $reps[$k] = (string) array_key_first($spellings); }
        $scan = array_slice($reps, 0, self::SCAN_LIMIT, true);

        $seen = []; $similar = [];
        foreach ($scan as $k => $v) {
            if (isset($seen[$k])) { continue; }
            $others = [];
            foreach ($scan as $k2 => $v2) { if ($k2 !== $k && !isset($seen[$k2])) { $others[] = $v2; } }
            $hit = NameSuggestion::against($suggest, $v, $others);
            if ($hit['conflict'] === null) { continue; }

            $k2 = Maintenance::normkey((string) $hit['conflict']);
            $members = ($byKey[$k] ?? []) + ($byKey[$k2] ?? []);
            arsort($members);
            $seen[$k] = $seen[$k2] = true;
            $similar[] = [
                'kind'      => 'similar',
                'reason'    => $hit['reason'],
                'members'   => $members,
                'suggested' => (string) array_key_first($members),
            ];
        }

        return [
            'same'    => $same,
            'similar' => $similar,
            'total'   => count($counts),
            'scanned' => count($scan),
        ];
    }

    /**
     * عدد مجموعات «نفس الشيء بتهجئتين» — **رخيص** (تجميع بالمفتاح، بلا أي مقارنة
     * بين القيم). ده الرقم المؤكَّد والقابل للتصرّف، وهو اللي بيغذّي الشارة.
     */
    public static function sameCount(string $key): int
    {
        $byKey = [];
        foreach (self::values($key) as $v => $n) {
            $k = Maintenance::normkey((string) $v);
            if ($k !== '') { $byKey[$k][] = $v; }
        }
        $c = 0;
        foreach ($byKey as $spellings) { if (count($spellings) > 1) { $c++; } }
        return $c;
    }

    /**
     * نظرة عامة لكل الحقول.
     *
     * ⚠️ **`$deep = false` افتراضيًّا عن قصد.** كشف «المتشابه» بيقارن كل قيمة
     *    بكل القيم (levenshtein على ٧٥٢ سائق) — متقاس بـ**٦٫٨ ثانية** لكل
     *    الحقول. الشاشة الرئيسية والشارة بيستخدموا العدّ الرخيص، والمقارنة
     *    الكاملة بتجري على **صفحة الحقل الواحد بس** (`clusters()`).
     *    لو خلّيت الشارة تنادي العميق، كل نداء للجرس (كل ٦٠ ثانية لكل مستخدم)
     *    هيولّع السيرفر.
     */
    public static function overview(bool $deep = false): array
    {
        $out = [];
        foreach (self::fields() as $key => $g) {
            $row = [
                'label'   => $g['label'],
                'party'   => $g['party'] ?? null,
                'total'   => count(self::values($key)),
                'same'    => self::sameCount($key),
                'similar' => null,   // null = ما اتحسبش (مش صفر)
            ];
            if ($deep) {
                $c = self::clusters($key);
                $row['similar'] = count($c['similar']);
            }
            $out[$key] = $row;
        }
        return $out;
    }

    /**
     * إجمالي المجموعات المؤكَّدة (نفس المفتاح) — الحساب الكامل.
     * ⚠️ رخيص نسبيًّا (بلا مقارنات) لكنه **١٤٠ مللي ثانية** لأنه بيقرا كل قيم
     *    الـ١١ حقل. ما ينفعش يتنادى من الجرس مباشرةً — استخدم `badgeCount()`.
     */
    public static function pendingCount(): int
    {
        $n = 0;
        foreach (array_keys(self::fields()) as $key) { $n += self::sameCount($key); }
        return $n;
    }

    private const BADGE_KEY = 'dq_same_count';
    private const BADGE_AT  = 'dq_same_count_at';
    private const BADGE_TTL = 3600;   // ساعة

    /**
     * الرقم اللي بتعرضه شارة «محتاج منك» — **مكاش في `settings`**.
     *
     * ⚠️ الجرس بينادي `/notifications/ping` كل ٦٠ ثانية لكل مستخدم. الحساب
     *    الكامل ١٤٠ مللي ثانية، يعني لو اتنادى مباشرةً بيبقى تدهور **٥٨×** على
     *    الـping البارد (المقاس ٢٫٤ مللي). فبنحسبه مرة كل ساعة كحد أقصى،
     *    وبنحدّثه فورًا بعد أي دمج فالرقم ما بيكدبش على المالك بعد ما ينضّف.
     */
    public static function badgeCount(): int
    {
        return (int) \App\Core\App::setting(self::BADGE_KEY, '0');
    }

    /** هل الرقم المخزَّن قديم ومحتاج إعادة حساب؟ */
    public static function badgeStale(): bool
    {
        $at = (int) \App\Core\App::setting(self::BADGE_AT, '0');
        return $at <= 0 || (time() - $at) >= self::BADGE_TTL;
    }

    public static function refreshBadge(): int
    {
        $n = self::pendingCount();
        \App\Core\App::setSetting(self::BADGE_KEY, (string) $n);
        \App\Core\App::setSetting(self::BADGE_AT, (string) time());
        return $n;
    }

    /**
     * دمج تهجئات في تهجئة معتمدة.
     *
     * ⚠️ **ممنوع على الأسماء اللي ليها سجل قانوني** — لازم تمرّ على
     *    `PartyMaster::merge()` عشان الـid والـaliases يتحرّكوا معاها.
     *
     * @param  list<string> $variants التهجئات اللي هتتشال
     * @return array{rows:int,lookup:int} عدد الصفوف اللي اتغيّرت وعدد مفردات القائمة اللي اتشالت
     */
    public static function merge(string $key, string $canonical, array $variants): array
    {
        $g = Maintenance::group($key);
        if (!$g) { throw new \RuntimeException('الحقل ده مش معروف.'); }
        if (!empty($g['party'])) {
            throw new \RuntimeException(
                'أسماء «' . $g['label'] . '» ليها سجل قانوني — دمجها من «البيانات الأساسية» '
                . 'عشان الفواتير والكشوف المرتبطة تتحرّك معاها.'
            );
        }

        $canonical = trim($canonical);
        if ($canonical === '') { throw new \RuntimeException('اختر الاسم المعتمد الأول.'); }
        $variants = array_values(array_filter(array_map('trim', $variants), static fn($v) => $v !== '' && $v !== $canonical));
        if (!$variants) { throw new \RuntimeException('مفيش تهجئات مختلفة للدمج.'); }

        $rows = 0; $lookupDel = 0;
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            foreach ($g['cols'] as $c) {
                [$t, $col] = $c; $extra = $c[2] ?? '';
                if (!Maintenance::hasTable($t)) { continue; }
                foreach ($variants as $v) {
                    $w = "`$col` = ?" . ($extra ? " AND $extra" : '');
                    $rows += Database::exec("UPDATE `$t` SET `$col` = ? WHERE $w", [$canonical, $v]);
                }
            }
            if (!empty($g['lookup'])) {
                foreach ($variants as $v) {
                    $lookupDel += Database::exec(
                        'DELETE FROM lookups WHERE list_type = ? AND value = ?', [$g['lookup'], $v]
                    );
                }
                // الاسم المعتمد لازم يفضل في القائمة (خصوصًا لو القائمة مقفولة)
                Lookup::add($g['lookup'], $canonical, true);
            }

            // الدمج لا يصلّح الماضي فقط: كل قيمة مختلفة فعلًا في مفتاحها
            // تُحفظ اسمًا بديلًا، فأول إدخال لها لاحقًا يتحوّل للمعتمد.
            DataAlias::remember($key, $canonical, $variants);

            // ⚠️ **الجهات: الدمج بيعلّم السجل المرجعي.** التهجئة اللي اتدمجت بتتسجّل
            //    **اسمًا بديلًا** للجهة المعتمدة، فأول ما حد يكتبها تاني تتوحّد
            //    تلقائيًّا — يعني نفس الغلطة **ما بتحتاجش دمج يدوي مرّة تانية**.
            //    دي هي اللي بتحوّل التنظيف من شغل متكرر لشغل بيتقلّ كل مرة.
            if ($key === 'destination') { self::teachAliases($canonical, $variants); }

            Audit::log('update', 'lookups', 0, ['من' => $variants], ['إلى' => $canonical],
                'توحيد مسميات: ' . $g['label']);

            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        NameSuggestion::clearNamesCache();
        self::refreshBadge();   // الرقم في الشارة ما يكدبش بعد ما المالك ينضّف
        return ['rows' => $rows, 'lookup' => $lookupDel];
    }

    /**
     * تسجيل التهجئات المدموجة كأسماء بديلة للجهة المعتمدة في `destination_points`.
     * بيتخطّى بأمان لو الجهة مش في السجل أو وحدة التتبع مش مترحّلة.
     */
    private static function teachAliases(string $canonical, array $variants): void
    {
        try {
            $hit = Track::resolveDestination($canonical);
            if (!$hit || ($hit['match'] ?? '') !== 'exact') { return; }
            $id  = (int) $hit['point']['id'];
            $now = array_filter(array_map('trim', explode('|', (string) ($hit['point']['aliases'] ?? ''))));

            foreach ($variants as $v) {
                $k = Maintenance::normkey($v);
                if ($k !== '' && $k !== (string) $hit['point']['normkey'] && !in_array($k, $now, true)) {
                    $now[] = $k;
                }
            }
            Database::exec('UPDATE destination_points SET aliases = ? WHERE id = ?',
                [implode('|', array_slice($now, 0, 20)), $id]);
            Track::clearPointsCache();
        } catch (\Throwable $e) { /* السجل المرجعي مش متاح — الدمج النصّي تمّ برضو */ }
    }

    /**
     * شيل مفردة من القائمة المنسدلة **من غير ما تلمس أي سجل**.
     * للخردة اللي مش مستخدمة في أي بوليصة/إذن («2201» · «10 طر د»).
     */
    public static function dropLookup(string $key, string $value): void
    {
        $g = Maintenance::group($key);
        if (!$g || empty($g['lookup'])) { throw new \RuntimeException('الحقل ده مالوش قائمة منسدلة.'); }

        $used = self::values($key)[$value] ?? 0;
        if ($used > 0) {
            throw new \RuntimeException(
                'المفردة دي مستخدَمة في ' . $used . ' سجل — ادمجها في الاسم الصح بدل ما تشيلها.'
            );
        }
        Database::exec('DELETE FROM lookups WHERE list_type = ? AND value = ?', [$g['lookup'], $value]);
        Audit::log('delete', 'lookups', 0, ['value' => $value], null, 'شيل مفردة غير مستخدَمة: ' . $g['label']);
        NameSuggestion::clearNamesCache();
        self::refreshBadge();
    }
}
