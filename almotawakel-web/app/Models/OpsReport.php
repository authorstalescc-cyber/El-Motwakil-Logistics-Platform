<?php
namespace App\Models;

use App\Core\Database;

/**
 * تقارير التشغيل — «إيه اللي اتحرّك في الفترة دي؟» (يومي / أسبوعي / شهري / مخصّص).
 *
 * بيجاوب على سؤال المالك: **كام حاوية اتداولت، من أنهي موانئ، لأنهي شركات،
 * وكام عهدة اتدفعت** — **بعيدًا عن النولون** تمامًا بقراره.
 *
 * ⚠️⚠️ **الوحدة قراءة فقط بالكامل — مفيش ولا `INSERT/UPDATE/DELETE` واحد.**
 *    مفيش جدول جديد ولا عمود جديد ولا هجرة: كل رقم بيتحسب لحظيًّا من
 *    `bawalis` + `permits` القايمين. يعني **مستحيل تمسّ خزنة ولا تقفيل ولا
 *    عهدة ولا فاتورة عميل**، ومستحيل تختلف عن مصدرها لأنها مالهاش مصدر تاني.
 *    `bin/verify_ops_report.php` بيفشل لو أي كتابة دخلت الملف ده.
 *
 * ⚠️ **النولون مستبعَد عن قصد** (طلب المالك الحرفي «بعيدًا عن الناولون»).
 *    لو حد ضاف عمود نولون هنا بعدين، الوحدة بتتحوّل لنسخة تانية من `/cars`
 *    وبتفقد سبب وجودها. الفاحص بيحرس ده.
 *
 * ⚠️ **الحاويات بتتعدّ من البوالص مش من الأذونات.** الإذن ممكن يكون فيه ٧
 *    حاويات واتحرّك منها ٦ بس في الفترة — والسؤال «اتداولت» يعني **اللي
 *    اتحرّك فعلاً**، فالمصدر `bawalis.container_count`.
 *
 * ⚠️ **الميناء والتوكيل وشركة الشحن بتيجي من الإذن المرتبط** (`permit_id`)،
 *    والشركة والوجهة والسواق من البوليصة نفسها — نفس تقسيم `OfficeCar::trips`.
 *
 * ⚠️ **البوالص المُرحَّلة من الأرشيف (`is_legacy=1`) مستبعدة**، التزامًا بعزل
 *    الأرشيف (v15/v26): هي بوالص تاريخية بلا إذن ولا حركة، ودخولها كان
 *    هيضخّم كل رقم في الوحدة (١٧٣٧ بوليصة مقابل ٨٥ تشغيلية).
 *
 * ⚠️ **البوليصة بلا تاريخ مستبعدة** عشان الإجماليات تطابق مخطط الاتجاه
 *    بالظبط. عددها بيتعرض في الشاشة (`undatedCount`) بدل ما تختفي بالسكوت.
 */
class OpsReport
{
    /** الفترات المتاحة — `custom` بتاخد حدودها من الفورم. */
    public const PERIODS = [
        'day'    => 'اليوم',
        'week'   => 'الأسبوع',
        'month'  => 'الشهر',
        'custom' => 'مخصّص',
        'all'    => 'كل الفترات',
    ];

    /**
     * أبعاد التقسيم المتاحة: المفتاح → [الاسم المعروض, تعبير SQL, أيقونة].
     *
     * ⚠️ **المصدر الوحيد لأي تقسيم** — الشاشة والنافذة والطباعة كلهم بيقروا
     *    من هنا، فمستحيل «الموانئ» تتحسب بتعبير في مكان وبتعبير تاني في مكان.
     */
    public const DIMS = [
        'port'        => ['الموانئ',            'p.port',              'anchor'],
        'company'     => ['الشركات',            'b.company',           'building'],
        'client'      => ['العملاء',            'p.client',            'users'],
        'destination' => ['الوجهات',            'b.destination',       'map-pin'],
        'agency'      => ['التوكيلات',          'p.agency',            'briefcase'],
        'shipping_co' => ['شركات الشحن',        'p.shipping_co',       'send'],
        'owner'       => ['أصحاب البيان',       'b.declaration_owner', 'file-text'],
        'driver'      => ['السواقين',           'b.driver',            'user'],
        'car'         => ['السيارات',           'b.car_no',            'truck'],
    ];

    /**
     * تبويبات «التوزيع» بالترتيب اللي يهمّ المدير — الأبعاد + تصنيف الحاويات.
     *
     * ⚠️ **`mix` بُعد خاص** (بيتحسب بـ`containerMix` عشان يمرّ على
     *    `container_norm`) فمش في `DIMS`، لكنه بيتعرض في نفس المكوّن.
     *    المصدر الوحيد لترتيب التبويبات هنا — الشاشة والنوافذ بيقروا منه.
     */
    public static function dimTabs(): array
    {
        $out = [];
        foreach (self::DIMS as $k => $v) {
            $out[$k] = ['label' => $v[0], 'icon' => $v[2]];
            if ($k === 'client') { $out['mix'] = ['label' => 'تصنيف الحاويات', 'icon' => 'layers']; }
        }
        return $out;
    }

    /**
     * سقف الصفوف في نوافذ التفاصيل قبل ما نقول «بنعرض N من M».
     *
     * ⚠️ **الشاشة الرئيسية بتعرض `TRIP_PREVIEW` بس مش السقف ده.** أول نسخة
     *    كانت بترسم ٤٠٠ صف في الصفحة نفسها فطلعت **٦٢٣ كيلوبايت** مقابل
     *    ٩٤ لـ`/cars` — كل صف فيه ٨ أيقونات SVG. الصفحة دي غرضها الإجماليات،
     *    والتفصيل مكانه النافذة (اضغط أي رقم) وتقرير الطباعة المفصّل.
     */
    public const TRIP_CAP = 400;

    /** الصفوف المعروضة في الشاشة نفسها — الباقي وراء النافذة والطباعة. */
    public const TRIP_PREVIEW = 25;

    /**
     * تسمية القيمة الفاضية — **ثابت واحد** لأنها بتتكتب في التقسيم وبتتقرا
     * وقت الفلترة. لو اتكتبت نصًّا في المكانين، أول تعديل على واحدة بيخلّي
     * الضغط على الصف يرجّع «مفيش بنود» **بلا أي خطأ ظاهر**.
     */
    public const UNSET_LABEL = '(غير محدَّد)';

    // ══════════════════════════════════════════════ الفترة

    /**
     * حدود الفترة + الفترة اللي قبلها بنفس الطول (للمقارنة).
     *
     * ⚠️ **بتفوّض `OfficeCar::period()` في يوم/أسبوع/شهر/الكل عن قصد** — عشان
     *    «الأسبوع بيبدأ السبت» و«الشهر تقويمي» يفضلوا **تعريفًا واحدًا** في
     *    النظام كله. لو اتكتبوا هنا من تاني، أول تعديل على واحد منهم بيخلّي
     *    `/cars` و`/ops` يقولوا أسبوعين مختلفين. الجديد هنا هو `custom` بس.
     */
    public static function period(string $kind, ?string $anchor = null,
                                  ?string $from = null, ?string $to = null): array
    {
        $kind = isset(self::PERIODS[$kind]) ? $kind : 'month';

        if ($kind === 'custom') {
            $from = self::cleanDate($from);
            $to   = self::cleanDate($to);
            if ($from === null && $to === null) {
                // مخصّص بلا حدود = مالوش معنى؛ نرجع للشهر بدل ما نعرض «كل الفترات» بالغلط
                return self::period('month', $anchor);
            }
            if ($from !== null && $to !== null && $from > $to) { [$from, $to] = [$to, $from]; }

            $label = $from !== null && $to !== null
                ? 'من ' . dfmt($from) . ' إلى ' . dfmt($to)
                : ($from !== null ? 'من ' . dfmt($from) . ' وبعدها' : 'لحد ' . dfmt($to));

            // المقارنة بتشتغل بس لو الفترة مقفولة الطرفين (طولها معروف)
            $pf = $pt = $prevLabel = null;
            if ($from !== null && $to !== null) {
                $days = (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1;
                $pt = date('Y-m-d', strtotime($from . ' -1 day'));
                $pf = date('Y-m-d', strtotime($pt . ' -' . ($days - 1) . ' day'));
                $prevLabel = 'الـ' . intf($days) . ' يوم اللي قبلها';
            }

            return ['kind' => 'custom', 'label' => $label, 'from' => $from, 'to' => $to,
                    'prev_from' => $pf, 'prev_to' => $pt, 'prev_label' => $prevLabel];
        }

        return OfficeCar::period($kind, $anchor);
    }

    /** نسبة التغيّر — نفس تعريف `/cars` بالحرف (مصدر واحد). */
    public static function delta(float $now, float $prev): ?float
    {
        return OfficeCar::delta($now, $prev);
    }

    private static function cleanDate($raw): ?string
    {
        $raw = trim((string) $raw);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }

    // ══════════════════════════════════════════════ نطاق القراءة

    /**
     * شرط النطاق المشترك لكل استعلامات الوحدة — **المصدر الوحيد**.
     * لو اتكتب الشرط بالإيد في أي استعلام، رقم من الأرقام هيشمل بوالص
     * الأرشيف أو البوالص بلا تاريخ وهيخالف الباقي بلا أي خطأ ظاهر.
     */
    private static function scope(?string $from, ?string $to, array &$args): string
    {
        $w = 'b.is_legacy = 0 AND b.bill_date IS NOT NULL';
        if ($from !== null) { $w .= ' AND b.bill_date >= ?'; $args[] = $from; }
        if ($to   !== null) { $w .= ' AND b.bill_date <= ?'; $args[] = $to; }
        return $w;
    }

    private const JOIN = 'FROM bawalis b LEFT JOIN permits p ON p.id = b.permit_id';

    // ══════════════════════════════════════════════ الإجماليات

    /**
     * أرقام الفترة.
     *
     * ⚠️ **`custody` و`deducted` رقمان مختلفان — لا تخلطهم:**
     *    • `custody`  = العهدة كاملة اللي خرجت من إيد الشركة للسواق (ده اللي
     *                   المالك سمّاه «العهد اللي اتدفعت»).
     *    • `deducted` = المخصوم فعليًّا من خزنة المكتب = العهدة − ١٠٠ لكل
     *                   بوليصة (والسيارة المستثناة تتخصم كاملة).
     *    الفرق بينهم بيتعرض في الشاشة عشان ما حدّش يفتكرهم رقمًا واحدًا.
     *
     * ⚠️ المخصوم بيتحسب بـ**`Custody::deductionSql()` نفسها** اللي بيحسب بيها
     *    رصيد الخزنة — فمستحيل التقرير يقول رقمًا والخزنة تقول غيره.
     */
    public static function totals(?string $from = null, ?string $to = null): array
    {
        $args = [];
        $w = self::scope($from, $to, $args);

        [$dedSql, $dedArgs] = Custody::deductionSql('b.custody', 'b.car_no');
        // بارامترات تعبير الخصم بتيجي **قبل** بارامترات النطاق لأن التعبير في SELECT
        $args = array_merge($dedArgs, $args);

        $row = Database::all(
            "SELECT COUNT(*) trips,
                    COALESCE(SUM(GREATEST(b.container_count, 0)), 0) containers,
                    COUNT(DISTINCT b.permit_id) permits,
                    COALESCE(SUM(b.custody), 0) custody,
                    COALESCE(SUM(CASE WHEN b.custody > 0 THEN $dedSql ELSE 0 END), 0) deducted,
                    COUNT(DISTINCT NULLIF(TRIM(COALESCE(p.port, '')), '')) ports,
                    COUNT(DISTINCT NULLIF(TRIM(COALESCE(b.company, '')), '')) companies
             " . self::JOIN . " WHERE $w", $args)[0] ?? [];

        return [
            'trips'      => (int)   ($row['trips'] ?? 0),
            'containers' => (int)   ($row['containers'] ?? 0),
            'permits'    => (int)   ($row['permits'] ?? 0),
            'custody'    => round((float) ($row['custody'] ?? 0), 2),
            'deducted'   => round((float) ($row['deducted'] ?? 0), 2),
            'ports'      => (int)   ($row['ports'] ?? 0),
            'companies'  => (int)   ($row['companies'] ?? 0),
        ];
    }

    /** بوالص تشغيلية بلا تاريخ — مستبعدة من التقرير، بنقولها بدل ما تختفي. */
    public static function undatedCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM bawalis WHERE is_legacy = 0 AND bill_date IS NULL");
    }

    // ══════════════════════════════════════════════ التقسيمات

    /**
     * تقسيم الفترة حسب بُعد واحد (ميناء / شركة / توكيل / وجهة / عميل …).
     *
     * ⚠️ **بيتجمّع بالقيمة الخام زي ما هي مكتوبة — مفيش دمج تلقائي لتهجئتين.**
     *    «ميناء الإسكندرية» و«الإسكندرية» بيفضلوا صفّين. ده مقصود: الربط في
     *    النظام نصّي بلا FK (تحذير ٣)، ودمج الأسماء **قرار المالك** من
     *    «البيانات الأساسية» مش تخمين تقرير. الدمج الصامت هنا كان هيخفي غلط
     *    الإدخال بدل ما يوريه.
     */
    public static function breakdown(string $dim, ?string $from = null, ?string $to = null,
                                     int $limit = 0): array
    {
        if (!isset(self::DIMS[$dim])) { return []; }
        $col = self::DIMS[$dim][1];

        $args = [self::UNSET_LABEL];
        $w = self::scope($from, $to, $args);

        $sql = "SELECT COALESCE(NULLIF(TRIM($col), ''), ?) name,
                       COUNT(*) trips,
                       COALESCE(SUM(GREATEST(b.container_count, 0)), 0) containers,
                       COALESCE(SUM(b.custody), 0) custody,
                       COUNT(DISTINCT b.permit_id) permits
                " . self::JOIN . " WHERE $w
                GROUP BY name
                ORDER BY containers DESC, trips DESC, name ASC";
        if ($limit > 0) { $sql .= ' LIMIT ' . (int) $limit; }

        $out = [];
        foreach (Database::all($sql, $args) as $r) {
            $out[] = [
                'name'       => (string) $r['name'],
                'trips'      => (int) $r['trips'],
                'containers' => (int) $r['containers'],
                'permits'    => (int) $r['permits'],
                'custody'    => round((float) $r['custody'], 2),
            ];
        }
        return $out;
    }

    /**
     * تقسيم الحاويات حسب التصنيف («٤٠ قدم» / «٢٠ قدم» …).
     *
     * ⚠️ **بيمرّ على `container_norm()`** — المصدر الوحيد لتوحيد التصنيف
     *    (v30). من غيره «1*40» و«40 قدم» بيبانوا نوعين مختلفين.
     */
    public static function containerMix(?string $from = null, ?string $to = null): array
    {
        $args = [];
        $w = self::scope($from, $to, $args);

        $out = [];
        foreach (Database::all(
            "SELECT b.container_type t,
                    COALESCE(SUM(GREATEST(b.container_count, 0)), 0) c,
                    COUNT(*) trips
             " . self::JOIN . " WHERE $w GROUP BY b.container_type", $args) as $r) {
            $key = container_norm($r['t']) ?: self::UNSET_LABEL;
            $out[$key] ??= ['name' => $key, 'containers' => 0, 'trips' => 0];
            $out[$key]['containers'] += (int) $r['c'];
            $out[$key]['trips']      += (int) $r['trips'];
        }
        uasort($out, static fn ($a, $b) => [$b['containers'], $b['trips']] <=> [$a['containers'], $a['trips']]);
        return array_values($out);
    }

    // ══════════════════════════════════════════════ الرحلات

    /**
     * بوالص الفترة بالتفصيل — للقايمة وللنوافذ وللطباعة.
     * `$limit = 0` يعني بلا حد (الطباعة والتصدير).
     *
     * `$dim`+`$value` بيضيّقوها على قيمة بُعد واحدة — «وريني بوالص ميناء
     * الإسكندرية بس». ده اللي بيخلّي الضغط على صف في «التوزيع» يفتح **بنوده
     * هو** بدل ما يعيد نفس القايمة (تكرار).
     */
    public static function trips(?string $from = null, ?string $to = null, int $limit = 0,
                                 ?string $dim = null, ?string $value = null): array
    {
        $args = [];
        $w = self::scope($from, $to, $args);

        // ⚠️ `mix` مستثنى من فلترة SQL: تصنيف الحاوية بيتوحّد بـ`container_norm()`
        //    في PHP (v30)، فمافيش عمود مخزَّن يطابق القيمة الموحَّدة — الفلترة
        //    بتحصل بعد الجلب. أي محاولة لمطابقته في SQL هترجّع نتيجة ناقصة.
        $post = null;
        if ($dim !== null && $value !== null && $value !== '') {
            if ($dim === 'mix') {
                $post = $value;
            } elseif (isset(self::DIMS[$dim])) {
                $col = self::DIMS[$dim][1];
                if ($value === self::UNSET_LABEL) {
                    $w .= " AND COALESCE(NULLIF(TRIM($col), ''), '') = ''";
                } else {
                    $w .= " AND TRIM($col) = ?";
                    $args[] = $value;
                }
            }
        }

        $sql = "SELECT b.id, b.bill_number, b.bill_date, b.bill_type, b.company, b.destination,
                       b.container_no, b.container_type, b.container_count, b.custody,
                       b.car_no, b.driver, b.declaration_owner,
                       b.permit_id, p.permit_no, p.permit_type, p.port, p.agency,
                       p.shipping_co, p.client
                " . self::JOIN . " WHERE $w
                ORDER BY b.bill_date DESC, b.id DESC";
        if ($limit > 0 && $post === null) { $sql .= ' LIMIT ' . (int) $limit; }
        $rows = Database::all($sql, $args);

        if ($post !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool =>
                (container_norm($r['container_type'] ?? '') ?: self::UNSET_LABEL) === $post));
            if ($limit > 0) { $rows = array_slice($rows, 0, $limit); }
        }
        return $rows;
    }

    /** أذونات الفترة (اللي اتحرّك عليها بوليصة) — مجمَّعة. */
    public static function permits(?string $from = null, ?string $to = null, int $limit = 0): array
    {
        $args = [];
        $w = self::scope($from, $to, $args);

        $sql = "SELECT p.id, p.permit_no, p.permit_type, p.company, p.client, p.port,
                       p.agency, p.destination, p.containers permit_containers,
                       COUNT(*) trips,
                       COALESCE(SUM(GREATEST(b.container_count, 0)), 0) containers,
                       COALESCE(SUM(b.custody), 0) custody,
                       MIN(b.bill_date) first_move, MAX(b.bill_date) last_move
                " . self::JOIN . " WHERE $w AND b.permit_id IS NOT NULL
                GROUP BY p.id, p.permit_no, p.permit_type, p.company, p.client, p.port,
                         p.agency, p.destination, p.containers
                ORDER BY containers DESC, trips DESC";
        if ($limit > 0) { $sql .= ' LIMIT ' . (int) $limit; }
        return Database::all($sql, $args);
    }

    // ══════════════════════════════════════════════ الاتجاه

    /**
     * إجمالي كل يوم في المدى — **استعلام واحد** لأي عدد فترات.
     * من غيره مخطط الـ١٢ فترة كان هيعمل ١٢ استعلام (درس `OfficeCar::daily`).
     */
    public static function daily(?string $from = null, ?string $to = null): array
    {
        $args = [];
        $w = self::scope($from, $to, $args);

        $out = [];
        foreach (Database::all(
            "SELECT b.bill_date d, COUNT(*) trips,
                    COALESCE(SUM(GREATEST(b.container_count, 0)), 0) containers,
                    COALESCE(SUM(b.custody), 0) custody
             " . self::JOIN . " WHERE $w GROUP BY b.bill_date", $args) as $r) {
            $out[(string) $r['d']] = [
                'trips'      => (int) $r['trips'],
                'containers' => (int) $r['containers'],
                'custody'    => round((float) $r['custody'], 2),
            ];
        }
        ksort($out);
        return $out;
    }

    /**
     * اتجاه آخر `$n` فترة من نفس النوع (أقدم → أحدث) — لمخطط الأعمدة.
     *
     * ⚠️ **بيتبني من `daily()` نفسها** فمستحيل عمود في الرسم يخالف الرقم اللي
     *    فوق. والفترات الفاضية **من الأول بس** بتتقصّ — الفاضية في النص
     *    معلومة حقيقية («الأسبوع ده مفيش شغل») مش ضوضاء.
     */
    public static function trend(string $kind, int $n = 12, ?string $anchor = null): array
    {
        if ($kind === 'all' || $kind === 'custom') { $kind = 'month'; }
        $n = max(2, min(24, $n));
        $anchor = $anchor ?: work_date();

        $spans = [];
        $cursor = $anchor;
        for ($i = 0; $i < $n; $i++) {
            $p = self::period($kind, $cursor);
            array_unshift($spans, $p);
            $cursor = date('Y-m-d', strtotime($p['from'] . ' -1 day'));
        }

        $daily = self::daily($spans[0]['from'], $spans[$n - 1]['to']);

        $out = [];
        foreach ($spans as $p) {
            $t = ['trips' => 0, 'containers' => 0, 'custody' => 0.0];
            foreach ($daily as $d => $v) {
                if ($d >= $p['from'] && $d <= $p['to']) {
                    $t['trips']      += $v['trips'];
                    $t['containers'] += $v['containers'];
                    $t['custody']    += $v['custody'];
                }
            }
            $t['from']  = $p['from'];
            $t['to']    = $p['to'];
            $t['label'] = self::shortLabel($kind, $p['from']);
            $t['on']    = ($p['from'] === self::period($kind, $anchor)['from']);
            $out[] = $t;
        }

        // قصّ الفترات الفاضية من الأول بس (النظام بدأ يوليو ٢٠٢٦)
        while (count($out) > 3 && $out[0]['trips'] === 0 && $out[1]['trips'] === 0) {
            array_shift($out);
        }
        return $out;
    }

    private static function shortLabel(string $kind, string $from): string
    {
        if ($kind === 'day')   { return date('d/m', strtotime($from)); }
        if ($kind === 'week')  { return date('d/m', strtotime($from)); }
        return date('m/Y', strtotime($from));
    }

    // ══════════════════════════════════════════════ المعدلات المشتقّة

    /**
     * أرقام **مشتقّة مالهاش مكان تاني في الشاشة** — دي اللي بتفرّق مع المدير.
     *
     * ⚠️ **كلها مبنية على `totals()` و`daily()` اللي فوق** — مفيش استعلام
     *    جديد ولا تعريف تاني لأي رقم، فمستحيل تخالف اللي معروض.
     *
     * ⚠️ **أي معدل مقامه صفر بيرجع `null` مش صفر** — «مفيش بيانات» غير
     *    «صفر»، والشاشة بتعرض «—» بدل ما تكدب (نفس قاعدة `OfficeCar::rates`).
     */
    public static function rates(array $t, array $daily): array
    {
        $days = count($daily);
        $busy = null;
        foreach ($daily as $d => $v) {
            if ($busy === null || $v['containers'] > $busy['containers']) {
                $busy = ['date' => $d, 'containers' => $v['containers'], 'trips' => $v['trips']];
            }
        }
        return [
            'work_days'     => $days,
            'per_day'       => $days > 0 ? round($t['containers'] / $days, 1) : null,
            'per_trip'      => $t['trips'] > 0 ? round($t['containers'] / $t['trips'], 2) : null,
            'per_container' => $t['containers'] > 0 ? round($t['custody'] / $t['containers'], 2) : null,
            'per_permit'    => $t['permits'] > 0 ? round($t['containers'] / $t['permits'], 1) : null,
            'busiest'       => $busy,
        ];
    }

    // ══════════════════════════════════════════════ محتاج انتباهك

    /**
     * بنود تصرّف حقيقية — **مش تكرار لأي رقم فوق**.
     *
     * ⚠️ الفرق بين ده وبين «قراءة سريعة» اللي اتشالت: القراءة السريعة كانت
     *    بتعيد صياغة نفس الأرقام المعروضة في جُمل (تكرار خالص). ده بيقول
     *    **حاجة مش معروضة في أي مكان تاني**: أذونات لسه فيها حاويات ما
     *    اتحركتش، وبيانات ناقصة بتخلّي التقرير نفسه أقل دقّة.
     *
     * ⚠️ **«الباقي» بيتقاس على عمر الإذن كله مش على الفترة** — الإذن ممكن
     *    يكون اتحرّك نصّه الشهر اللي فات، فقياسه على الفترة بس كان هيقول
     *    «باقي ٧» وهو باقي ٢.
     */
    public static function attention(?string $from = null, ?string $to = null, int $limit = 8): array
    {
        $args = [];
        $w = self::scope($from, $to, $args);

        // ① أذونات مفتوحة لسه فيها حاويات ما اتحركتش
        //
        // ⚠️ **الإذن بيدخل بشرطين لا شرط واحد**: يا إما اتحرّك عليه بوليصة في
        //    الفترة، **يا إما اتفتح في الفترة ولسه ما اتحركش خالص**. أول نسخة
        //    كانت بتشوف اللي اتحرّك بس، فالإذن اللي فيه ١٨ حاوية و**صفر حركة**
        //    كان بيختفي تمامًا — وهو بالظبط أهم بند المدير محتاج يشوفه
        //    (متحقَّق فعليًّا على بيانات الإنتاج: إذن #70).
        $pw = "p.status = 'open' AND p.containers > 0";
        $pArgs = $args;
        $inPeriod = "p.id IN (SELECT b.permit_id " . self::JOIN . " WHERE $w AND b.permit_id IS NOT NULL)";
        if ($from === null && $to === null) {
            $pw .= " AND ($inPeriod OR 1)";           // كل الفترات = كل الأذونات المفتوحة
        } else {
            $pw .= " AND ($inPeriod OR (p.permit_date IS NOT NULL";
            if ($from !== null) { $pw .= " AND p.permit_date >= ?"; $pArgs[] = $from; }
            if ($to   !== null) { $pw .= " AND p.permit_date <= ?"; $pArgs[] = $to; }
            $pw .= '))';
        }

        $partial = Database::all(
            "SELECT p.id, p.permit_no, p.permit_type, p.permit_date, p.client, p.company,
                    p.containers total,
                    COALESCE((SELECT SUM(GREATEST(b2.container_count, 0)) FROM bawalis b2
                              WHERE b2.permit_id = p.id AND b2.is_legacy = 0), 0) moved
             FROM permits p
             WHERE $pw
             HAVING moved < p.containers
             ORDER BY (p.containers - moved) DESC, p.id DESC
             LIMIT " . (int) $limit, $pArgs);

        // ② بيانات ناقصة بتخلّي التقرير نفسه أقل دقّة — استعلام واحد
        $args2 = [];
        $w2 = self::scope($from, $to, $args2);
        $gaps = Database::all(
            "SELECT
                SUM(CASE WHEN TRIM(COALESCE(p.port, '')) = '' THEN 1 ELSE 0 END) no_port,
                SUM(CASE WHEN TRIM(COALESCE(b.container_type, '')) = '' THEN 1 ELSE 0 END) no_type,
                SUM(CASE WHEN COALESCE(b.container_count, 0) <= 0 THEN 1 ELSE 0 END) no_count,
                SUM(CASE WHEN b.permit_id IS NULL THEN 1 ELSE 0 END) no_permit
             " . self::JOIN . " WHERE $w2", $args2)[0] ?? [];

        return [
            'partial' => array_map(static function (array $r): array {
                $r['left'] = max(0, (int) $r['total'] - (int) $r['moved']);
                return $r;
            }, $partial),
            'gaps' => [
                'no_port'   => (int) ($gaps['no_port'] ?? 0),
                'no_type'   => (int) ($gaps['no_type'] ?? 0),
                'no_count'  => (int) ($gaps['no_count'] ?? 0),
                'no_permit' => (int) ($gaps['no_permit'] ?? 0),
            ],
        ];
    }
}
