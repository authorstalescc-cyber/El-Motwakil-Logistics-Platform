<?php
namespace App\Models;

use App\Core\App;
use App\Core\Database;

/**
 * سيارات المكتب — السيارات اللي الشركة مالكاها بنفسها (v54).
 *
 * الوحدة **عدسة على بيانات قايمة + سجل مصاريف خاص**:
 *   • الرحلات والحاويات والشركات والموانئ والوجهات والعهد ← بتتقرا من
 *     `bawalis` (+ `permits` للميناء والتوكيل) **قراءة فقط**.
 *   • المصاريف (سولار/إصلاح/…) ← جدول `car_expenses` الخاص بالوحدة.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * ⚠️ **العزل عن خزنة المكتب (قرار المالك — v54). لا تعكسه بلا مراجعة.**
 * ══════════════════════════════════════════════════════════════════════════
 * `car_expenses` **ما بيدخلش** الخزنة ولا التقفيل اليومي ولا مراجعة التقفيل
 * ولا سجل المصروفات العام. العزل **بالبناء** زي `rep_misc_expenses` (v39):
 * جدول خاص كل كود الخزنة عمره ما سمع عنه، فمفيش نقطة مزامنة ممكن تتنسي.
 * `bin/verify_v54.php` بيفشل لو أي ملف خزنة ذكر الجدول.
 *
 * ⚠️ **الأثر المعروف:** خزنة المكتب بتقول رقمًا أعلى من الحقيقة بمقدار
 *    مصاريف السيارات. الشاشة بتقول ده بالنص.
 *
 * ⚠️ **الربط بالرحلات نصّي بلا FK (تحذير ٣).** البوليصة بتتنسب للسيارة
 *    بمطابقة `bawalis.car_no` على **مفتاح اللوحة** (`plateKey`) — يعني
 *    «1474 ط ف ر» و«ط ف ر 1474» نفس السيارة. البوالص المُرحَّلة
 *    (`is_legacy = 1`) **مستبعدة** التزامًا بعزل الأرشيف (v15).
 */
class OfficeCar
{
    /** @var array<string,array<string,mixed>>|null خريطة مفاتيح اللوحات للمطابقة داخل الطلب */
    private static ?array $matchPlateMap = null;

    /** فئات مصاريف السيارة — المفتاح بيتخزَّن والتسمية للعرض. */
    public const EXPENSE_KINDS = [
        'fuel'      => 'سولار / بنزين',
        'repair'    => 'إصلاحات وقطع غيار',
        'oil'       => 'زيوت وفلاتر',
        'tyres'     => 'كاوتش',
        'license'   => 'رخص وتراخيص',
        'insurance' => 'تأمين',
        'fines'     => 'مخالفات',
        'wash'      => 'غسيل ونظافة',
        'other'     => 'أخرى',
    ];

    /** أيقونة كل فئة (من `icon()` — مش إيموجي). */
    public const EXPENSE_ICONS = [
        'fuel'      => 'zap',
        'repair'    => 'settings',
        'oil'       => 'coins',
        'tyres'     => 'package',
        'license'   => 'file-text',
        'insurance' => 'landmark',
        'fines'     => 'alert',
        'wash'      => 'sparkles',
        'other'     => 'layers',
    ];

    public static function kindLabel(?string $k): string
    {
        return self::EXPENSE_KINDS[(string) $k] ?? 'أخرى';
    }

    public static function kindIcon(?string $k): string
    {
        return self::EXPENSE_ICONS[(string) $k] ?? 'layers';
    }

    /**
     * هل الوحدة مترحّلة؟ — الرفض هو الافتراضي (تحذير ٥): قبل migrate_v54
     * النظام بيفضل شغّال بسلوكه القديم بدل ما يكسر.
     */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'office_cars'");
        }
        return $ok;
    }

    // ══════════════════════════════════════════════ تطبيع اللوحة

    /**
     * **المصدر الوحيد لتطبيع لوحة السيارة.** ممنوع تكتب تطبيعًا موازيًا.
     *
     * الفكرة: اللوحة المصرية أرقام + حروف، والناس بتكتبهم بترتيب مختلف
     * («1474 ط ف ر» ↔ «ط ف ر 1474») وبمسافات مختلفة. المفتاح بيفصل الأرقام
     * عن الحروف ويجمّع كل نوع لوحده، فالترتيب بينهم مش بيفرق.
     *
     * بيوحّد كمان: الأرقام العربية/الفارسية، الألف بأشكالها، التاء المربوطة،
     * الياء، التطويل، والتشكيل — نفس قواعد `Maintenance::normkey`.
     */
    public static function plateKey(?string $raw): string
    {
        $s = ar2en_digits((string) $raw);
        $s = strtr($s, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ٱ'=>'ا','ة'=>'ه','ى'=>'ي','ؤ'=>'و','ئ'=>'ي','ء'=>'']);
        $s = str_replace("\u{0640}", '', $s);
        $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s) ?? $s;
        preg_match_all('/[0-9]+/u', $s, $dm);
        preg_match_all('/[\x{0621}-\x{064A}A-Za-z]+/u', $s, $lm);
        $digits  = implode('', $dm[0]);
        $letters = mb_strtolower(implode('', $lm[0]), 'UTF-8');
        if ($digits === '' && $letters === '') { return ''; }
        return $digits . '-' . $letters;
    }

    /** كل مفاتيح السيارة: اللوحة المعتمدة + كل التهجئات البديلة. */
    public static function keysOf(array $car): array
    {
        $keys = [self::plateKey((string) ($car['plate'] ?? ''))];
        foreach (self::aliasList($car) as $a) { $keys[] = self::plateKey($a); }
        return array_values(array_unique(array_filter($keys, static fn ($k) => $k !== '')));
    }

    /**
     * ⚠️ **المصدر الوحيد لسؤال «اللوحة دي سيارة مكتب ولا لأ؟»** — بترجّع سجل
     *    السيارة أو null. بيقراها فورم البوليصة (عشان يظهر خانات مصروفات
     *    السيارة) وصفحة البوليصة والفحص الحيّ في `/cars/is-office`.
     *
     * ⚠️ **ممنوع تكتب المقارنة دي في الجافاسكريبت** — التطبيع فيه توحيد حروف
     *    عربية وفصل أرقام عن حروف (`plateKey`)، ونسخة تانية منه في المتصفح
     *    هتفترق مع أول تعديل فالخانات تختفي عن سيارة مكتب **بلا أي خطأ ظاهر**.
     *    الواجهة بتسأل الخادم (زي فحص رقم البوليصة القايم).
     */
    public static function matchPlate(?string $carNo): ?array
    {
        $key = self::plateKey($carNo);
        if ($key === '' || !self::available()) { return null; }
        if (self::$matchPlateMap === null) {
            self::$matchPlateMap = [];
            foreach (self::all() as $car) {
                foreach (self::keysOf($car) as $carKey) {
                    self::$matchPlateMap[$carKey] = $car;
                }
            }
        }
        return self::$matchPlateMap[$key] ?? null;
    }

    /** هل اللوحة دي سيارة مكتب مسجَّلة؟ */
    public static function isOfficePlate(?string $carNo): bool
    {
        return self::matchPlate($carNo) !== null;
    }

    public static function aliasList(array $car): array
    {
        $raw = (string) ($car['aliases'] ?? '');
        if (trim($raw) === '') { return []; }
        return array_values(array_filter(array_map('trim', explode('|', $raw)),
            static fn ($a) => $a !== ''));
    }

    // ══════════════════════════════════════════════ اللوحات المتجاهَلة

    /**
     * لوحات مكتوب عليها «المكتب» في البوالص لكنها **مش بتاعتنا** (v55).
     *
     * ⚠️ لازمها لأن `car_owner` بيتكتب بالإيد، ومتحقَّق إن نفس اللوحة بتظهر
     *    مرة بـ«المكتب» ومرة باسم مالكها الحقيقي — يعني غلطة إدخال. من غير
     *    القايمة دي، تنبيه «لوحات مش في السجل» بيفضل يزنّ على لوحات المالك
     *    قال عليها «مش بتاعتنا»، **وترحيل v54 كان هيرجّع يسجّلها** لو اتشغّل
     *    تاني. القايمة مفاتيح تطبيع (`plateKey`) مش نصوص خام.
     *
     * قرار المالك (2026-07-26): سيارة المكتب الوحيدة لحد دلوقتي «س ع م 8737».
     */
    private const IGNORED_KEY = 'car_ignored_plates';

    public static function ignoredPlates(): array
    {
        $raw = (string) App::setting(self::IGNORED_KEY, '');
        if (trim($raw) === '') { return []; }
        $out = json_decode($raw, true);
        return is_array($out) ? array_values(array_filter(array_map('strval', $out))) : [];
    }

    public static function isIgnored(string $plateOrKey): bool
    {
        $k = self::plateKey($plateOrKey) ?: $plateOrKey;
        return in_array($k, self::ignoredPlates(), true);
    }

    public static function ignorePlate(string $plate): void
    {
        $k = self::plateKey($plate);
        if ($k === '') { return; }
        $list = self::ignoredPlates();
        if (!in_array($k, $list, true)) { $list[] = $k; }
        App::setSetting(self::IGNORED_KEY, json_encode(array_values($list), JSON_UNESCAPED_UNICODE));
    }

    public static function unignorePlate(string $plateOrKey): void
    {
        $k = self::plateKey($plateOrKey) ?: $plateOrKey;
        $list = array_values(array_filter(self::ignoredPlates(), static fn ($x) => $x !== $k));
        App::setSetting(self::IGNORED_KEY, json_encode($list, JSON_UNESCAPED_UNICODE));
    }

    /** اللوحات المتجاهَلة بأسمائها الخام وعدد رحلاتها — للعرض في القايمة المطويّة. */
    public static function ignoredDetail(): array
    {
        $keys = self::ignoredPlates();
        if (!$keys) { return []; }
        $rows = Database::all(
            "SELECT car_no, COUNT(*) trips, MAX(bill_date) last_d
             FROM bawalis
             WHERE is_legacy = 0 AND car_no IS NOT NULL AND TRIM(car_no) <> ''
             GROUP BY car_no");
        $out = [];
        foreach ($rows as $r) {
            $k = self::plateKey((string) $r['car_no']);
            if (!in_array($k, $keys, true)) { continue; }
            $out[$k] ??= ['key' => $k, 'plate' => (string) $r['car_no'], 'trips' => 0, 'last_d' => null];
            $out[$k]['trips'] += (int) $r['trips'];
            if ($r['last_d'] && $r['last_d'] > $out[$k]['last_d']) { $out[$k]['last_d'] = $r['last_d']; }
        }
        // مفاتيح متجاهَلة بلا أي بوليصة (اتشالت مثلاً) تفضل ظاهرة عشان تتلغى
        foreach ($keys as $k) {
            $out[$k] ??= ['key' => $k, 'plate' => $k, 'trips' => 0, 'last_d' => null];
        }
        return array_values($out);
    }

    /**
     * ⚠️ **صاحب العربية لسيارات المكتب — المصدر الوحيد.**
     * كل بوالص عربيات المكتب في السجل مكتوب فيها «المكتب» بالحرف (متحقَّق على
     * البيانات)، فأي شاشة بتملّى الحقل ده تلقائيًّا لازم تقرا من هنا — وإلا
     * تهجئة تانية بتعمل «صاحب عربية» جديد في البيانات الأساسية (تحذير 3).
     */
    public const OWNER_LABEL = 'المكتب';

    /**
     * تفكيك اللوحة لـ**٣ حروف + ٤ أرقام** — للشاشات اللي خاناتها مقسومة
     * (بوليصة المندوب v60). بترجّع null لو اللوحة مش بالشكل ده، فالشاشة تخفي
     * الزرار بدل ما تملا خانات غلط.
     *
     * ⚠️ بتقبل الترتيبين («س ع م 8737» و«8737 س ع م») لأن الاتنين موجودين في
     *    البيانات فعلاً — نفس تسامح `plateKey()` اللي بيعتبرهم عربية واحدة.
     */
    public static function plateParts(?string $plate): ?array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', ar2en_digits((string) $plate)));
        if ($raw === '') { return null; }
        if (!preg_match('/^\d{4}$|(?<=\s|^)\d{4}(?=\s|$)/u', $raw)) { return null; }
        $digits = ''; $letters = [];
        foreach (explode(' ', $raw) as $piece) {
            if (preg_match('/^\d+$/', $piece)) { $digits = $piece; continue; }
            foreach (preg_split('//u', $piece, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                if (preg_match('/^[\x{0621}-\x{064A}]$/u', $ch)) { $letters[] = $ch; }
            }
        }
        if (count($letters) !== 3 || strlen($digits) !== 4) { return null; }
        return ['letters' => $letters, 'digits' => $digits, 'plate' => implode(' ', $letters) . ' ' . $digits];
    }

    /**
     * عربيات المكتب الجاهزة لزرار «املا بيانات عربية المكتب» في شاشة المندوب.
     * كل صف: اللوحة مفكوكة + السائق المسجَّل + صاحب العربية الثابت.
     *
     * ⚠️ **السائق بيتقرا من سجل السيارة (`office_cars.driver`) مش مكتوب في الكود**
     *    — فالمالك بيغيّره من `/cars` بلا تعديل كود، ومفيش نسختين للاسم يفترقوا.
     */
    public static function fillPresets(): array
    {
        $out = [];
        foreach (self::all(true) as $c) {
            $parts = self::plateParts((string) ($c['plate'] ?? ''));
            if (!$parts) { continue; }   // لوحة مش ٣ حروف + ٤ أرقام → مفيش زرار
            $out[] = [
                'id'      => (int) $c['id'],
                'label'   => trim((string) ($c['name'] ?? '')) ?: $parts['plate'],
                'plate'   => $parts['plate'],
                'letters' => $parts['letters'],
                'digits'  => $parts['digits'],
                'driver'  => trim((string) ($c['driver'] ?? '')),
                'owner'   => self::OWNER_LABEL,
            ];
        }
        return $out;
    }

    // ══════════════════════════════════════════════ السجل (CRUD)

    public static function all(bool $activeOnly = false): array
    {
        if (!self::available()) { return []; }
        $where = $activeOnly ? "WHERE status = 'active'" : '';
        return Database::all("SELECT * FROM office_cars $where ORDER BY status='inactive', name");
    }

    public static function find(int $id): ?array
    {
        if (!self::available() || $id <= 0) { return null; }
        return Database::row("SELECT * FROM office_cars WHERE id = ?", [$id]);
    }

    /** هل المفتاح ده مأخوذ لسيارة تانية؟ (الفريدة مفروضة في القاعدة كمان). */
    public static function plateTaken(string $key, int $exceptId = 0): bool
    {
        if ($key === '') { return false; }
        return (bool) Database::scalar(
            "SELECT COUNT(*) FROM office_cars WHERE plate_key = ? AND id <> ?", [$key, $exceptId]);
    }

    public static function create(array $d): int
    {
        Database::exec(
            "INSERT INTO office_cars (name, plate, plate_key, aliases, model, driver, status, note)
             VALUES (:name,:plate,:plate_key,:aliases,:model,:driver,:status,:note)", self::bind($d));
        self::clearCache();
        return Database::lastId();
    }

    public static function update(int $id, array $d): void
    {
        $p = self::bind($d);
        $p[':id'] = $id;
        Database::exec(
            "UPDATE office_cars SET name=:name, plate=:plate, plate_key=:plate_key,
                    aliases=:aliases, model=:model, driver=:driver, status=:status, note=:note
             WHERE id=:id", $p);
        self::clearCache();
    }

    /**
     * حذف السيارة + مصاريفها.
     * ⚠️ البوالص **ما بتتلمسش** — الربط نصّي، فالرحلات بتفضل في مكانها
     *    وبتختفي من الوحدة بس.
     */
    public static function delete(int $id): void
    {
        Database::exec("DELETE FROM car_expenses WHERE car_id = ?", [$id]);
        Database::exec("DELETE FROM office_cars WHERE id = ?", [$id]);
        self::clearCache();
    }

    private static function bind(array $d): array
    {
        $plate = trim((string) ($d['plate'] ?? ''));
        $alias = trim((string) ($d['aliases'] ?? ''));
        return [
            ':name'      => trim((string) ($d['name'] ?? '')) ?: $plate,
            ':plate'     => $plate,
            ':plate_key' => self::plateKey($plate),
            ':aliases'   => $alias !== '' ? $alias : null,
            ':model'     => ($d['model'] ?? '') !== '' ? $d['model'] : null,
            ':driver'    => ($d['driver'] ?? '') !== '' ? $d['driver'] : null,
            ':status'    => ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ':note'      => ($d['note'] ?? '') !== '' ? $d['note'] : null,
        ];
    }

    // ══════════════════════════════════════════════ مطابقة الرحلات

    /** @var array<string,int>|null [car_no الحرفي => car_id] — كاش داخل الطلب */
    private static ?array $noMap = null;

    /** ⚠️ نادِها بعد أي كتابة على `office_cars` وإلا الصفحة تعرض القديم. */
    public static function clearCache(): void
    {
        self::$noMap = null;
        self::$matchPlateMap = null;
    }

    /**
     * خريطة كل `car_no` مكتوب في البوالص → السيارة اللي بيخصّها.
     *
     * بتتبني مرة واحدة في الطلب: كل اللوحات المميّزة في البوالص الحيّة
     * (عشرات قليلة) بتتطابق على مفاتيح السيارات في السجل. الحساب في PHP مش
     * في SQL لأن التطبيع فيه توحيد حروف عربية ما ينفعش يتكتب في استعلام.
     */
    public static function carNoMap(): array
    {
        if (self::$noMap !== null) { return self::$noMap; }
        self::$noMap = [];
        if (!self::available()) { return self::$noMap; }

        $byKey = [];
        foreach (self::all() as $car) {
            foreach (self::keysOf($car) as $k) { $byKey[$k] = (int) $car['id']; }
        }
        if (!$byKey) { return self::$noMap; }

        $nos = Database::all(
            "SELECT DISTINCT car_no FROM bawalis
             WHERE is_legacy = 0 AND car_no IS NOT NULL AND TRIM(car_no) <> ''");
        foreach ($nos as $r) {
            $no = (string) $r['car_no'];
            $k  = self::plateKey($no);
            if ($k !== '' && isset($byKey[$k])) { self::$noMap[$no] = $byKey[$k]; }
        }
        return self::$noMap;
    }

    /** التهجئات الحرفية اللي بتخصّ سيارة معيّنة (للاستعلام بـIN). */
    public static function carNos(int $carId): array
    {
        $out = [];
        foreach (self::carNoMap() as $no => $cid) {
            if ($cid === $carId) { $out[] = $no; }
        }
        return $out;
    }

    // ══════════════════════════════════════════════ الفترات والمقارنة

    public const PERIODS = [
        'day'   => 'اليوم',
        'week'  => 'الأسبوع',
        'month' => 'الشهر',
        'all'   => 'كل الفترات',
    ];

    /**
     * حدود الفترة + الفترة اللي قبلها بنفس الطول (للمقارنة).
     *
     * الأسبوع بيبدأ **السبت** (أسبوع الشغل في مصر)، والشهر تقويمي — عشان
     * الأرقام تطابق اللي في دماغ المالك مش نافذة متدحرجة.
     * `all` بترجع from/to = null (بلا حدود) وبلا مقارنة.
     */
    public static function period(string $kind, ?string $anchor = null): array
    {
        $anchor = $anchor ?: work_date();
        $t = strtotime($anchor) ?: time();
        $kind = isset(self::PERIODS[$kind]) ? $kind : 'month';

        if ($kind === 'all') {
            return ['kind' => 'all', 'label' => 'كل الفترات',
                    'from' => null, 'to' => null,
                    'prev_from' => null, 'prev_to' => null, 'prev_label' => null];
        }

        if ($kind === 'day') {
            $from = $to = date('Y-m-d', $t);
            $pf = $pt = date('Y-m-d', strtotime('-1 day', $t));
            $label = 'يوم ' . dfmt($from);
            $prevLabel = 'يوم ' . dfmt($pf);
        } elseif ($kind === 'week') {
            // (N + 1) % 7 = عدد الأيام من آخر سبت (السبت N=6 → 0)
            $back = ((int) date('N', $t) + 1) % 7;
            $start = strtotime('-' . $back . ' day', $t);
            $from = date('Y-m-d', $start);
            $to   = date('Y-m-d', strtotime('+6 day', $start));
            $pf   = date('Y-m-d', strtotime('-7 day', $start));
            $pt   = date('Y-m-d', strtotime('-1 day', $start));
            $label = 'أسبوع ' . dfmt($from) . ' ← ' . dfmt($to);
            $prevLabel = 'الأسبوع اللي قبله';
        } else {
            $from = date('Y-m-01', $t);
            $to   = date('Y-m-t', $t);
            $ps   = strtotime('-1 month', strtotime($from));
            $pf   = date('Y-m-01', $ps);
            $pt   = date('Y-m-t', $ps);
            $label = 'شهر ' . dfmt($from) . ' ← ' . dfmt($to);
            $prevLabel = 'الشهر اللي قبله';
        }

        return ['kind' => $kind, 'label' => $label, 'from' => $from, 'to' => $to,
                'prev_from' => $pf, 'prev_to' => $pt, 'prev_label' => $prevLabel];
    }

    /** نسبة التغيّر بين رقمين (null لو مفيش أساس نقارن عليه). */
    public static function delta(float $now, float $prev): ?float
    {
        if (abs($prev) < 0.005) { return null; }
        return round(($now - $prev) / abs($prev) * 100, 1);
    }

    // ══════════════════════════════════════════════ الرحلات

    /**
     * رحلات السيارة في الفترة — **قراءة فقط من البوالص**.
     *
     * الميناء والتوكيل وشركة الشحن بتيجي من الإذن المرتبط، والشركة والوجهة
     * من البوليصة نفسها. `owner_mismatch` بيتحسب لما `car_owner` في البوليصة
     * مش «المكتب» — بنعرض تنبيه بدل ما نخفي الرحلة، عشان غلط الإدخال يبان.
     */
    /**
     * تعبير SQL لمجموع مصروفات البوليصة الـ١٢ (سولار/كاوتش/دور سواق…).
     *
     * ⚠️ **`Calc::EXPENSE_COLS` هو المصدر الوحيد للقايمة** — ممنوع تكتب أسماء
     *    الأعمدة بإيدك هنا، وإلا بند جديد يدخل «إجمال المصروف» في البوليصة
     *    ويغيب عن «صافي السيارة» فالرقمين يختلفوا **بلا أي خطأ ظاهر**.
     */
    public static function billExpenseSql(string $alias = 'b'): string
    {
        $parts = array_map(static fn ($c) => "COALESCE({$alias}.`{$c}`,0)", \App\Calc::EXPENSE_COLS);
        return '(' . implode(' + ', $parts) . ')';
    }

    public static function trips(int $carId, ?string $from = null, ?string $to = null, int $limit = 0): array
    {
        $nos = self::carNos($carId);
        if (!$nos) { return []; }

        $ph = implode(',', array_fill(0, count($nos), '?'));
        $args = $nos;
        $where = "b.is_legacy = 0 AND b.car_no IN ($ph)";
        if ($from !== null) { $where .= " AND b.bill_date >= ?"; $args[] = $from; }
        if ($to   !== null) { $where .= " AND b.bill_date <= ?"; $args[] = $to; }

        $expenseCols = implode(', ', array_map(
            static fn ($column) => "b.`{$column}`", \App\Calc::EXPENSE_COLS
        ));
        $sql = "SELECT " . self::billExpenseSql('b') . " AS bill_expense,
                       b.id, b.bill_date, b.bill_number, b.car_no, b.car_owner, b.driver,
                       b.company, b.declaration_owner, b.destination,
                       b.container_count, b.container_type, b.classification,
                       b.freight, b.custody, b.transfer, b.debt, b.paid, b.pay_status,
                       $expenseCols,
                       b.permit_id, b.movement_id,
                       p.permit_no, p.permit_type, p.port, p.agency, p.shipping_co, p.client
                FROM bawalis b
                LEFT JOIN permits p ON p.id = b.permit_id
                WHERE $where
                ORDER BY (b.bill_date IS NULL), b.bill_date DESC, b.id DESC";
        if ($limit > 0) { $sql .= ' LIMIT ' . (int) $limit; }

        $rows = Database::all($sql, $args);
        foreach ($rows as &$r) {
            $r['owner_mismatch'] = trim((string) ($r['car_owner'] ?? '')) !== 'المكتب';
            // ⚠️ المخصوم من الخزنة **مش** العهدة كاملة — بيتحسب من نفس دالة
            //    الخزنة (`Custody::billDeduction`) فمستحيل الرقمين يختلفوا.
            $r['custody_deducted'] = Custody::billDeduction((float) $r['custody'], $r['car_no'] ?? null);
        }
        unset($r);
        return $rows;
    }

    // ══════════════════════════════════════════════ المصاريف

    public static function expenses(int $carId, ?string $from = null, ?string $to = null, int $limit = 0): array
    {
        if (!self::available()) { return []; }
        $args = [$carId];
        $where = 'car_id = ?';
        if ($from !== null) { $where .= ' AND exp_date >= ?'; $args[] = $from; }
        if ($to   !== null) { $where .= ' AND exp_date <= ?'; $args[] = $to; }
        $sql = "SELECT * FROM car_expenses WHERE $where ORDER BY exp_date DESC, id DESC";
        if ($limit > 0) { $sql .= ' LIMIT ' . (int) $limit; }
        return Database::all($sql, $args);
    }

    public static function findExpense(int $id): ?array
    {
        if (!self::available()) { return null; }
        return Database::row("SELECT * FROM car_expenses WHERE id = ?", [$id]);
    }

    /**
     * إضافة مصروف سيارة.
     *
     * ⚠️ **بلا `Closing::guard` عن قصد** (نفس قرار نثريات المناديب v39):
     *    البند ما بيدخلش أي تقفيل أصلاً، فتسجيله على يوم متقفل ما بيغيّرش
     *    أي رصيد مثبَّت.
     */
    public static function addExpense(int $carId, array $d): int
    {
        Database::exec(
            "INSERT INTO car_expenses (car_id, exp_date, category, amount, ref_no, vendor, note, created_by)
             VALUES (?,?,?,?,?,?,?,?)",
            [$carId,
             $d['exp_date'] ?: work_date(),
             isset(self::EXPENSE_KINDS[$d['category'] ?? '']) ? $d['category'] : 'other',
             (float) ($d['amount'] ?? 0),
             ($d['ref_no'] ?? '') !== '' ? $d['ref_no'] : null,
             ($d['vendor'] ?? '') !== '' ? $d['vendor'] : null,
             ($d['note'] ?? '') !== '' ? $d['note'] : null,
             ($d['created_by'] ?? '') !== '' ? $d['created_by'] : null]);
        return Database::lastId();
    }

    /**
     * تعديل مصروف سيارة مسجَّل.
     *
     * ⚠️ **بلا `Closing::guard` زي `addExpense` بالظبط** — البند ما بيدخلش أي
     *    تقفيل أصلاً (العزل بالبناء: `car_expenses` جدول خاص كل كود الخزنة
     *    عمره ما سمع عنه)، فتعديل مبلغه أو تاريخه ما بيغيّرش أي رصيد مثبَّت.
     * ⚠️ **`car_id` مش بيتغيّر من هنا** — نقل مصروف من سيارة لسيارة يغيّر أداء
     *    الاتنين، فيتعمل بحذف وإضافة عشان يفضل في السجل أثر واضح.
     */
    public static function updateExpense(int $id, array $d): void
    {
        Database::exec(
            "UPDATE car_expenses
                SET exp_date = ?, category = ?, amount = ?, ref_no = ?, vendor = ?, note = ?
              WHERE id = ?",
            [$d['exp_date'] ?: work_date(),
             isset(self::EXPENSE_KINDS[$d['category'] ?? '']) ? $d['category'] : 'other',
             (float) ($d['amount'] ?? 0),
             ($d['ref_no'] ?? '') !== '' ? $d['ref_no'] : null,
             ($d['vendor'] ?? '') !== '' ? $d['vendor'] : null,
             ($d['note'] ?? '') !== '' ? $d['note'] : null,
             $id]);
    }

    public static function deleteExpense(int $id): void
    {
        Database::exec("DELETE FROM car_expenses WHERE id = ?", [$id]);
    }

    /** إجمالي مصاريف سيارة في فترة. */
    public static function expenseTotal(int $carId, ?string $from = null, ?string $to = null): float
    {
        if (!self::available()) { return 0.0; }
        $args = [$carId];
        $where = 'car_id = ?';
        if ($from !== null) { $where .= ' AND exp_date >= ?'; $args[] = $from; }
        if ($to   !== null) { $where .= ' AND exp_date <= ?'; $args[] = $to; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM car_expenses WHERE $where", $args), 2);
    }

    /** إجمالي مصاريف كل السيارات في فترة [car_id => total]. */
    public static function expenseTotalsByCar(?string $from = null, ?string $to = null): array
    {
        if (!self::available()) { return []; }
        $args = []; $where = '1';
        if ($from !== null) { $where .= ' AND exp_date >= ?'; $args[] = $from; }
        if ($to   !== null) { $where .= ' AND exp_date <= ?'; $args[] = $to; }
        $out = [];
        foreach (Database::all(
            "SELECT car_id, COALESCE(SUM(amount),0) v FROM car_expenses WHERE $where GROUP BY car_id", $args) as $r) {
            $out[(int) $r['car_id']] = round((float) $r['v'], 2);
        }
        return $out;
    }

    /** تفكيك مصاريف السيارة حسب الفئة (مرتّب بالأكبر). */
    public static function expensesByCategory(int $carId, ?string $from = null, ?string $to = null): array
    {
        if (!self::available()) { return []; }
        $args = [$carId]; $where = 'car_id = ?';
        if ($from !== null) { $where .= ' AND exp_date >= ?'; $args[] = $from; }
        if ($to   !== null) { $where .= ' AND exp_date <= ?'; $args[] = $to; }
        $rows = Database::all(
            "SELECT category, COUNT(*) cnt, COALESCE(SUM(amount),0) total
             FROM car_expenses WHERE $where GROUP BY category ORDER BY total DESC", $args);
        foreach ($rows as &$r) {
            $r['label'] = self::kindLabel($r['category']);
            $r['icon']  = self::kindIcon($r['category']);
            $r['total'] = round((float) $r['total'], 2);
            $r['cnt']   = (int) $r['cnt'];
        }
        unset($r);
        return $rows;
    }

    // ══════════════════════════════════════════════ الأداء

    /**
     * أداء سيارة في فترة.
     *
     * ⚠️ **معادلة الصافي (قرار المالك v54):**
     *      صافي السيارة = النولون − العهدة المدفوعة للسواق − مصاريف السيارة
     *
     *    «العهدة» هنا هي `bawalis.custody` كاملة (اللي طلعت من إيد الشركة
     *    للسواق)، **مش** المخصوم من الخزنة (`custody − 100`). الاتنين
     *    معروضان في التفاصيل عشان الفرق يبان.
     *
     * ⚠️ **رقم استرشادي لأداء السيارة، مش ربح محاسبي.** ما بيدخلش فيه
     *    التحويلة ولا الخصم ولا أي تكلفة عامة على الشركة.
     */
    public static function perf(int $carId, ?string $from = null, ?string $to = null): array
    {
        $trips = self::trips($carId, $from, $to);
        $freight = 0.0; $custody = 0.0; $deducted = 0.0; $containers = 0; $billExp = 0.0;
        $drivers = []; $companies = []; $paidTrips = 0;
        $firstD = null; $lastD = null;
        foreach ($trips as $t) {
            $freight    += (float) $t['freight'];
            $custody    += (float) $t['custody'];
            $deducted   += (float) $t['custody_deducted'];
            $billExp    += (float) ($t['bill_expense'] ?? 0);
            $containers += max(0, (int) $t['container_count']);
            if (($t['driver'] ?? '') !== '')  { $drivers[trim((string) $t['driver'])] = true; }
            if (($t['company'] ?? '') !== '') { $companies[trim((string) $t['company'])] = true; }
            if ((string) $t['pay_status'] === 'paid') { $paidTrips++; }
            $d = $t['bill_date'] ?? null;
            if ($d) {
                if ($firstD === null || $d < $firstD) { $firstD = $d; }
                if ($lastD  === null || $d > $lastD)  { $lastD  = $d; }
            }
        }
        $exp = self::expenseTotal($carId, $from, $to);
        /* ⚠️ **مصدران للمصروف والاتنين بيتخصموا** (قرار المالك 2026-07-28):
             • `expenses`      = مصاريف مسجَّلة على السيارة نفسها في `/cars`.
             • `bill_expenses` = بنود البوليصة (سولار/كاوتش/دور سواق…) بعد ما
                                 رجعت لسيارات المكتب.
           بيفضلوا **مفتاحين منفصلين** عن قصد عشان الشاشة توضّح كل واحد جاي
           منين — لو السولار اتسجّل في المكانين بيتخصم مرتين، وده لازم يبان. */
        $billExp = round($billExp, 2);
        $expTotal = round($exp + $billExp, 2);

        return [
            'car_id'     => $carId,
            'trips'      => count($trips),
            'containers' => $containers,
            'freight'    => round($freight, 2),
            'custody'    => round($custody, 2),
            'custody_deducted' => round($deducted, 2),
            'expenses'   => $exp,
            'bill_expenses'  => $billExp,
            'expenses_total' => $expTotal,
            'net'        => round($freight - $custody - $expTotal, 2),
            'drivers'    => count($drivers),
            'companies'  => count($companies),
            'paid_trips' => $paidTrips,
            'first_trip' => $firstD,
            'last_trip'  => $lastD,
            'avg_freight'=> $trips ? round($freight / count($trips), 2) : 0.0,
        ];
    }

    /** أداء كل السيارات في فترة — كل صف = صف السيارة + مفاتيح `perf`. */
    public static function fleet(?string $from = null, ?string $to = null, bool $activeOnly = false): array
    {
        $cars = self::all($activeOnly);
        foreach ($cars as &$c) {
            $c = array_merge($c, self::perf((int) $c['id'], $from, $to));
        }
        unset($c);
        return $cars;
    }

    /** إجماليات الأسطول (مجموع أداء كل السيارات). */
    public static function fleetSummary(array $fleet): array
    {
        $s = ['cars' => count($fleet), 'trips' => 0, 'containers' => 0,
              'freight' => 0.0, 'custody' => 0.0, 'expenses' => 0.0,
              'bill_expenses' => 0.0, 'expenses_total' => 0.0, 'net' => 0.0];
        foreach ($fleet as $c) {
            $s['trips']      += (int) $c['trips'];
            $s['containers'] += (int) $c['containers'];
            $s['freight']    += (float) $c['freight'];
            $s['custody']    += (float) $c['custody'];
            $s['expenses']   += (float) $c['expenses'];
            $s['bill_expenses']  += (float) ($c['bill_expenses'] ?? 0);
            $s['expenses_total'] += (float) ($c['expenses_total'] ?? $c['expenses']);
            $s['net']        += (float) $c['net'];
        }
        foreach (['freight', 'custody', 'expenses', 'bill_expenses', 'expenses_total', 'net'] as $k) {
            $s[$k] = round($s[$k], 2);
        }
        return $s;
    }

    // ══════════════════════════════════════════════ التقسيمات

    /**
     * تقسيم رحلات السيارة حسب بُعد واحد (شركة / ميناء / وجهة / توكيل / سواق).
     * بيتحسب من `trips()` نفسها فمستحيل يخالف الأرقام اللي فوق.
     */
    public static function breakdown(array $trips, string $dim): array
    {
        $field = match ($dim) {
            'company'     => 'company',
            'port'        => 'port',
            'agency'      => 'agency',
            'shipping_co' => 'shipping_co',
            'driver'      => 'driver',
            default       => 'destination',
        };
        $out = [];
        foreach ($trips as $t) {
            $key = trim((string) ($t[$field] ?? ''));
            if ($key === '') { $key = '(غير محدَّد)'; }
            $out[$key] ??= ['name' => $key, 'trips' => 0, 'containers' => 0,
                            'freight' => 0.0, 'custody' => 0.0];
            $out[$key]['trips']++;
            $out[$key]['containers'] += max(0, (int) $t['container_count']);
            $out[$key]['freight']    += (float) $t['freight'];
            $out[$key]['custody']    += (float) $t['custody'];
        }
        foreach ($out as &$r) {
            $r['freight'] = round($r['freight'], 2);
            $r['custody'] = round($r['custody'], 2);
        }
        unset($r);
        uasort($out, static fn ($a, $b) => [$b['trips'], $b['freight']] <=> [$a['trips'], $a['freight']]);
        return array_values($out);
    }

    /** تقسيم الحاويات حسب التصنيف («2×20 قدم، 1×40 قدم»). */
    public static function containerMix(array $trips): array
    {
        $out = [];
        foreach ($trips as $t) {
            $type = container_norm($t['container_type'] ?? '') ?: '(غير محدَّد)';
            $out[$type] = ($out[$type] ?? 0) + max(0, (int) $t['container_count']);
        }
        arsort($out);
        return $out;
    }

    /** أحدث نشاط على السيارة (آخر رحلة أو آخر مصروف) للعرض في القائمة. */
    public static function lastActivity(int $carId): ?string
    {
        $nos = self::carNos($carId);
        $lastTrip = null;
        if ($nos) {
            $ph = implode(',', array_fill(0, count($nos), '?'));
            $lastTrip = Database::scalar(
                "SELECT MAX(bill_date) FROM bawalis WHERE is_legacy = 0 AND car_no IN ($ph)", $nos);
        }
        $lastExp = self::available()
            ? Database::scalar("SELECT MAX(exp_date) FROM car_expenses WHERE car_id = ?", [$carId])
            : null;
        $all = array_values(array_filter([$lastTrip, $lastExp]));
        return $all ? max($all) : null;
    }

    /**
     * لوحات في البوالص شبه لوحات المكتب لكن مش مسجَّلة — اقتراح للمالك.
     * قراءة فقط، والقرار قراره (تحذير ٣: التصادمات ما بتتدمجش تلقائيًّا).
     */
    public static function unregisteredOfficePlates(): array
    {
        if (!self::available()) { return []; }
        $known   = self::carNoMap();
        $ignored = self::ignoredPlates();
        $rows = Database::all(
            "SELECT car_no, COUNT(*) trips, MAX(bill_date) last_d
             FROM bawalis
             WHERE is_legacy = 0 AND car_owner = 'المكتب'
               AND car_no IS NOT NULL AND TRIM(car_no) <> ''
             GROUP BY car_no ORDER BY trips DESC");
        return array_values(array_filter($rows, static function ($r) use ($known, $ignored) {
            if (isset($known[(string) $r['car_no']])) { return false; }
            return !in_array(self::plateKey((string) $r['car_no']), $ignored, true);
        }));
    }

    // ══════════════════════════════════════════════ الإحصائيات الذكية (v55)

    /**
     * حركة كل يوم لمجموعة سيارات — **استعلامان بس** لأي مدى زمني.
     *
     * ده أساس كل الإحصائيات المشتقّة (الاتجاه · أيام الشغل · الفجوات)، وبيمنع
     * إن كل فترة في مخطط الاتجاه تعمل استعلاماتها لوحدها (١٢ فترة = ٢٤ استعلام).
     *
     * @return array<string,array{trips:int,containers:int,freight:float,custody:float,expenses:float}>
     */
    public static function daily(array $carIds, ?string $from = null, ?string $to = null): array
    {
        $carIds = array_values(array_unique(array_map('intval', $carIds)));
        $out = [];
        if (!$carIds) { return $out; }

        $nos = [];
        foreach (self::carNoMap() as $no => $cid) {
            if (in_array($cid, $carIds, true)) { $nos[] = $no; }
        }
        $blank = ['trips' => 0, 'containers' => 0, 'freight' => 0.0, 'custody' => 0.0,
                  'expenses' => 0.0, 'bill_expenses' => 0.0];

        if ($nos) {
            $args = $nos;
            $w = 'b.is_legacy = 0 AND b.bill_date IS NOT NULL AND b.car_no IN ('
               . implode(',', array_fill(0, count($nos), '?')) . ')';
            if ($from !== null) { $w .= ' AND b.bill_date >= ?'; $args[] = $from; }
            if ($to   !== null) { $w .= ' AND b.bill_date <= ?'; $args[] = $to; }
            foreach (Database::all(
                "SELECT b.bill_date d, COUNT(*) trips,
                        COALESCE(SUM(GREATEST(b.container_count,0)),0) conts,
                        COALESCE(SUM(b.freight),0) f, COALESCE(SUM(b.custody),0) c,
                        COALESCE(SUM(" . self::billExpenseSql('b') . "),0) be
                 FROM bawalis b WHERE $w GROUP BY b.bill_date", $args) as $r) {
                $d = (string) $r['d'];
                $out[$d] ??= $blank;
                $out[$d]['trips']      = (int) $r['trips'];
                $out[$d]['containers'] = (int) $r['conts'];
                $out[$d]['freight']    = round((float) $r['f'], 2);
                $out[$d]['custody']    = round((float) $r['c'], 2);
                $out[$d]['bill_expenses'] = round((float) $r['be'], 2);
            }
        }

        if (self::available()) {
            $args = $carIds;
            $w = 'car_id IN (' . implode(',', array_fill(0, count($carIds), '?')) . ')';
            if ($from !== null) { $w .= ' AND exp_date >= ?'; $args[] = $from; }
            if ($to   !== null) { $w .= ' AND exp_date <= ?'; $args[] = $to; }
            foreach (Database::all(
                "SELECT exp_date d, COALESCE(SUM(amount),0) v
                 FROM car_expenses WHERE $w GROUP BY exp_date", $args) as $r) {
                $d = (string) $r['d'];
                $out[$d] ??= $blank;
                $out[$d]['expenses'] = round((float) $r['v'], 2);
            }
        }

        ksort($out);
        return $out;
    }

    /**
     * اتجاه آخر `$n` فترة من نفس النوع (أقدم → أحدث) — لمخطط الأعمدة.
     * كل عنصر فيه حدود الفترة عشان الضغط على العمود ينقل الشاشة للفترة دي.
     */
    public static function trend(array $carIds, string $kind, int $n = 12, ?string $anchor = null): array
    {
        if ($kind === 'all') { $kind = 'month'; }
        $n = max(2, min(24, $n));
        $anchor = $anchor ?: work_date();

        // نبني حدود الفترات بالرجوع خطوة خطوة من الفترة الحالية
        $spans = [];
        $cursor = $anchor;
        for ($i = 0; $i < $n; $i++) {
            $p = self::period($kind, $cursor);
            array_unshift($spans, $p);
            $cursor = date('Y-m-d', strtotime('-1 day', strtotime($p['from'])));
        }

        $daily = self::daily($carIds, $spans[0]['from'], $spans[count($spans) - 1]['to']);
        $out = [];
        foreach ($spans as $p) {
            $row = ['from' => $p['from'], 'to' => $p['to'], 'kind' => $kind,
                    'trips' => 0, 'containers' => 0, 'freight' => 0.0,
                    'custody' => 0.0, 'expenses' => 0.0];
            foreach ($daily as $d => $v) {
                if ($d < $p['from'] || $d > $p['to']) { continue; }
                $row['trips']      += $v['trips'];
                $row['containers'] += $v['containers'];
                $row['freight']    += $v['freight'];
                $row['custody']    += $v['custody'];
                $row['expenses']   += $v['expenses'];
            }
            $row['net'] = round($row['freight'] - $row['custody'] - $row['expenses'], 2);
            $row['label'] = $kind === 'month'
                ? date('n/y', strtotime($p['from']))
                : date('j/n', strtotime($p['from']));
            $out[] = $row;
        }

        /* ⚠️ قصّ الفترات الفاضية **من الأول بس**.
           النظام بدأ في يوليو 2026، فرسم ١٢ شهرًا كان بيدي ١١ عمودًا فاضيًا
           وعمودًا واحدًا فيه كل حاجة — رسم مالوش أي معنى. الفترات الفاضية
           **في النص أو في الآخر بتفضل** لأنها معلومة حقيقية («الشهر ده مفيش
           فيه شغل»)، واللي قبل أول حركة خالص مش معلومة. وبنسيب ٣ أعمدة على
           الأقل عشان يفضل شكله رسمًا. */
        $firstActive = null;
        foreach ($out as $i => $r) {
            if ((int) $r['trips'] > 0 || (float) $r['expenses'] > 0) { $firstActive = $i; break; }
        }
        if ($firstActive !== null && $firstActive > 0) {
            $keepFrom = min($firstActive, max(0, count($out) - 3));
            $out = array_slice($out, $keepFrom);
        }
        return $out;
    }

    /**
     * معدلات مشتقّة — **مفيش رقم جديد بيتخزَّن**، كلها من `perf` و`daily`.
     *
     * ⚠️ أي نسبة مقامها صفر بترجع `null` مش صفر — «مفيش بيانات» غير «صفر
     *    بالمية»، وعرض صفر مضلِّل.
     */
    public static function rates(array $perf, array $period, array $daily): array
    {
        $trips = (int) $perf['trips'];
        $f     = (float) $perf['freight'];
        $div   = static fn (float $a, float $b): ?float => abs($b) < 0.005 ? null : round($a / $b, 2);
        $pct   = static fn (float $a, float $b): ?float => abs($b) < 0.005 ? null : round($a / $b * 100, 1);

        // `all` مالهاش حدود، فأيام الفترة بتتحسب من أول يوم فيه حركة لآخر يوم
        $from = $period['from'] ?? (array_key_first($daily) ?: null);
        $to   = $period['to']   ?? (array_key_last($daily) ?: null);
        $periodDays = ($from && $to)
            ? max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1) : null;

        $workDays = [];
        foreach ($daily as $d => $v) {
            if ($v['trips'] > 0) { $workDays[] = (string) $d; }
        }
        sort($workDays);

        // متوسط الفجوة بين رحلة واللي بعدها (بالأيام)
        $gap = null;
        if (count($workDays) >= 2) {
            $span = (strtotime((string) end($workDays)) - strtotime($workDays[0])) / 86400;
            $gap = round($span / (count($workDays) - 1), 1);
        }

        // واقفة من كام يوم؟ (من آخر رحلة لآخر يوم في الفترة أو النهاردة — الأقرب)
        $idle = null;
        if ($workDays) {
            $end = min($to ?: date('Y-m-d'), date('Y-m-d'));
            $idle = max(0, (int) round((strtotime($end) - strtotime((string) end($workDays))) / 86400));
        }

        return [
            'freight_per_trip'      => $div($f, (float) $trips),
            'freight_per_container' => $div($f, (float) $perf['containers']),
            'cost_per_trip'         => $div((float) $perf['custody'] + (float) $perf['expenses'], (float) $trips),
            'expense_per_trip'      => $div((float) $perf['expenses'], (float) $trips),
            'custody_pct'           => $pct((float) $perf['custody'], $f),
            'expense_pct'           => $pct((float) $perf['expenses'], $f),
            'net_pct'               => $pct((float) $perf['net'], $f),
            'work_days'             => count($workDays),
            'period_days'           => $periodDays,
            'utilization'           => $periodDays ? $pct((float) count($workDays), (float) $periodDays) : null,
            'avg_gap_days'          => $gap,
            'idle_days'             => $idle,
            'last_trip'             => $workDays ? (string) end($workDays) : null,
        ];
    }

    /**
     * قراءة سريعة بلغة المالك — جُمل بدل ما هو يقارن الأرقام بعينه.
     *
     * ⚠️ **كل جملة مشتقّة من نفس الأرقام المعروضة فوقها**، فمستحيل تقول حاجة
     *    والأرقام تقول غيرها. وأي حالة بلا بيانات كافية بتتخطّى بدل ما تطلع
     *    جملة على صفر (زي «الصافي نزل ١٠٠٪» وهو أصلاً مفيش رحلات).
     *
     * @return array<int,array{icon:string,tone:string,text:string}>
     */
    public static function insights(array $perf, ?array $prevPerf, array $rates,
                                    array $trips, array $period, array $trend = []): array
    {
        $out = [];
        $add = static function (string $icon, string $tone, string $text) use (&$out): void {
            $out[] = ['icon' => $icon, 'tone' => $tone, 'text' => $text];
        };

        if ((int) $perf['trips'] === 0) {
            $add('alert', 'warn', 'مفيش ولا رحلة في ' . $period['label'] . '.');
            if (!empty($rates['last_trip'])) {
                $add('clock', 'warn', 'آخر رحلة كانت في ' . dfmt((string) $rates['last_trip']) . '.');
            }
            return $out;
        }

        // ① واقفة من كام يوم
        if ($rates['idle_days'] !== null && $rates['idle_days'] >= 3) {
            $add('clock', $rates['idle_days'] >= 7 ? 'bad' : 'warn',
                'واقفة من ' . intf((int) $rates['idle_days']) . ' يوم — آخر رحلة '
                . dfmt((string) $rates['last_trip']) . '.');
        }

        // ② معدل الاستغلال
        if ($rates['utilization'] !== null && (int) $rates['period_days'] > 1) {
            $add('calendar', $rates['utilization'] >= 50 ? 'good' : 'warn',
                'اشتغلت ' . intf((int) $rates['work_days']) . ' يوم من '
                . intf((int) $rates['period_days']) . ' — يعني '
                . number_format((float) $rates['utilization'], 0) . '٪ من أيام الفترة.'
                . ($rates['avg_gap_days'] !== null
                    ? ' وبين الرحلة والتانية ' . number_format((float) $rates['avg_gap_days'], 1) . ' يوم في المتوسط.'
                    : ''));
        }

        // ③ العهد والمصاريف بياكلوا كام من النولون
        if ($rates['custody_pct'] !== null) {
            $eat = (float) $rates['custody_pct'] + (float) ($rates['expense_pct'] ?? 0);
            $add('wallet', $eat >= 70 ? 'bad' : ($eat >= 50 ? 'warn' : 'good'),
                'العهد والمصاريف بياكلوا ' . number_format($eat, 0) . '٪ من النولون'
                . ' (عهد ' . number_format((float) $rates['custody_pct'], 0) . '٪'
                . ($rates['expense_pct'] !== null
                    ? ' · مصاريف ' . number_format((float) $rates['expense_pct'], 0) . '٪' : '')
                . ') — والباقي ' . number_format((float) ($rates['net_pct'] ?? 0), 0) . '٪.');
        }

        // ④ أعلى وأقل رحلة
        $paid = array_values(array_filter($trips, static fn ($t) => (float) $t['freight'] > 0));
        if (count($paid) >= 2) {
            usort($paid, static fn ($a, $b) => (float) $b['freight'] <=> (float) $a['freight']);
            $hi = $paid[0];
            $lo = $paid[count($paid) - 1];
            $where = static fn (array $t): string => ($t['company'] ?: 'بلا شركة')
                . ($t['destination'] ? ' · ' . $t['destination'] : '');
            $add('bar-chart', 'info',
                'أعلى رحلة ' . money0($hi['freight']) . ' (' . $where($hi) . ')'
                . ' وأقلها ' . money0($lo['freight']) . ' (' . $where($lo) . ').');
        }

        // ⑤ رحلات بلا نولون — بند تشغيلي بيضيع وسط المتوسطات
        $zero = count($trips) - count($paid);
        if ($zero > 0) {
            $add('alert', 'warn',
                intf($zero) . ' من ' . intf(count($trips)) . ' رحلة النولون فيها صفر —'
                . ' يا إما لسه ما اتحطّش يا إما رحلة بلا نولون.');
        }

        // ⑥ أكتر وجهة وأعلى وجهة في متوسط النولون
        $dest = array_values(array_filter(self::breakdown($trips, 'destination'),
            static fn ($r) => $r['name'] !== '(غير محدَّد)'));
        if (count($dest) >= 2) {
            $top = $dest[0];
            $best = $dest;
            usort($best, static fn ($a, $b) =>
                ($b['trips'] ? $b['freight'] / $b['trips'] : 0) <=> ($a['trips'] ? $a['freight'] / $a['trips'] : 0));
            $b0 = $best[0];
            $avg = $b0['trips'] ? $b0['freight'] / $b0['trips'] : 0;
            $add('map-pin', 'info',
                'أكتر وجهة: ' . $top['name'] . ' (' . intf((int) $top['trips']) . ' رحلة).'
                . ' وأعلى وجهة في متوسط النولون: ' . $b0['name'] . ' بـ' . money0($avg) . ' للرحلة.');
        }

        // ⑦ المقارنة بالفترة السابقة
        if ($prevPerf !== null && (int) $prevPerf['trips'] > 0) {
            $dt = self::delta((float) $perf['trips'], (float) $prevPerf['trips']);
            $df = self::delta((float) $perf['freight'], (float) $prevPerf['freight']);
            $bits = [];
            if ($dt !== null) { $bits[] = 'الرحلات ' . ($dt >= 0 ? 'زادت' : 'نقصت') . ' ' . number_format(abs($dt), 0) . '٪'; }
            if ($df !== null) { $bits[] = 'والنولون ' . ($df >= 0 ? 'زاد' : 'نقص') . ' ' . number_format(abs($df), 0) . '٪'; }
            if ($bits) {
                $add('trending-down', ($df ?? 0) >= 0 ? 'good' : 'bad',
                    implode(' ', $bits) . ' عن ' . $period['prev_label'] . '.');
            }
        }

        // ⑧ توقّع نهاية الفترة (لو الفترة لسه شغّالة)
        $today = date('Y-m-d');
        if (($period['from'] ?? null) && ($period['to'] ?? null)
            && $period['from'] <= $today && $today < $period['to'] && (float) $perf['freight'] > 0) {
            $passed = max(1, (int) round((strtotime($today) - strtotime($period['from'])) / 86400) + 1);
            $total  = (int) round((strtotime($period['to']) - strtotime($period['from'])) / 86400) + 1;
            $add('zap', 'info',
                'بالمعدل ده الفترة هتقفل على حوالي ' . money0((float) $perf['freight'] / $passed * $total)
                . ' نولون (عدّى ' . intf($passed) . ' يوم من ' . intf($total) . ').');
        }

        // ⑨ أحسن فترة في مخطط الاتجاه
        $withTrips = array_values(array_filter($trend, static fn ($r) => (int) $r['trips'] > 0));
        if (count($withTrips) >= 3) {
            usort($withTrips, static fn ($a, $b) => (float) $b['freight'] <=> (float) $a['freight']);
            $add('bar-chart', 'info',
                'أحسن فترة في الرسم: ' . $withTrips[0]['label'] . ' بـ' . money0($withTrips[0]['freight'])
                . ' نولون من ' . intf((int) $withTrips[0]['trips']) . ' رحلة.');
        }

        return $out;
    }
}
