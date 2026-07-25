<?php
namespace App\Models;

use App\Core\Database;

/**
 * مراجعة التقفيل اليومي — علامة ✓ جنب كل بند (v50، بديلة مطابقة v28 اليدوية).
 *
 * الفكرة: بدل ما المالك يكتب إجمالي يدوي لكل عميل ويطارد الفرق، بيبص على كل بند
 * في اليوم ويعلّمه «تمت مراجعته». الإقفال بيتفتح لما كل بند **نقدي** يتعلّم.
 *
 * ⚠️ **طبقة تدقيق بحتة — مفيش أي أثر مالي.** الكلاس ده عمره ما بيلمس مبلغ ولا
 *    بيدخل في حساب رصيد ولا مرحّل؛ بيقرا بنود اليوم زي ما هي وبيخزّن علامة.
 *    مصدر كل رقم فيه هو نفس مصادر `Closing` بالظبط، فمستحيل الشاشتين يختلفوا.
 *
 * ⚠️ **العلامة بتسقط لو المبلغ اتغيّر بعد المراجعة** (`amount_snapshot`): من غير
 *    كده تبقى العلامة شاهدة على رقم مبقاش موجود. البند بيرجع «محتاج مراجعة»
 *    وبياخد شارة صفرا في الشاشة.
 *
 * مجموعات البنود:
 *   topups  — عهدة مضافة للخزنة (داخل)            | نقدي، بيمنع الإقفال
 *   permits — إيصالات ومصروفات الأذونات (خارج)     | نقدي، بيمنع الإقفال
 *   general — مصروفات عامة بلا إذن + كاش المناديب  | نقدي، بيمنع الإقفال
 *   custody — عهد السائقين (المخصوم بعد الخصم)     | نقدي، بيمنع الإقفال
 *   visa    — المدفوع بالفيزا                      | معلوماتي، **ما بيمنعش**
 */
class DailyReview
{
    /** مجموعات نقدية تمنع الإقفال لو فيها بند غير متراجَع. */
    public const BLOCKING = ['topups', 'permits', 'general', 'custody'];

    private static ?bool $available = null;

    /** هل جدول العلامات موجود؟ (النظام يشتغل قبل migrate_v50 بلا كسر) */
    public static function available(): bool
    {
        if (self::$available === null) {
            self::$available = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'daily_closing_reviews'"
            );
        }
        return self::$available;
    }

    private static function tableExists(string $name): bool
    {
        static $cache = [];
        if (!array_key_exists($name, $cache)) {
            $cache[$name] = (bool) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name=?', [$name]
            );
        }
        return $cache[$name];
    }

    // ───────────────────────────────────────────── العرض

    /**
     * كل بنود اليوم مجمّعة، ومعاها حالة المراجعة لكل بند.
     * المفاتيح: available · date · groups · index · totals · complete · last_at · last_by
     */
    public static function view(string $date): array
    {
        $groups = [
            'topups'  => self::group('topups',  'عهدة مضافة للخزنة', '➕', 'in',  'cash',
                'فلوس دخلت الدرج بتاريخ اليوم'),
            'permits' => self::group('permits', 'إيصالات ومصروفات الأذونات', '📄', 'out', 'cash',
                'كل بند نقدي مسجَّل على إذن بتاريخ اليوم'),
            'general' => self::group('general', 'مصروفات عامة بدون إذن', '🧾', 'out', 'cash',
                'مصروفات يدوية + سُلف وتسويات المناديب + نثرياتهم النقدية'),
            'custody' => self::group('custody', 'عهد السائقين', '🚛', 'out', 'cash',
                'المخصوم فعليًّا من الخزنة بعد خصم قيمة البوليصة'),
            'visa'    => self::group('visa',    'مدفوعات الفيزا', '💳', 'out', 'info',
                'اتدفعت من كارت المكتب — مش من الدرج، فما بتمنعش الإقفال'),
        ];

        foreach (self::collect($date) as $item) {
            $groups[$item['group']]['items'][] = $item;
        }

        $marks = self::marks($date);
        $index = [];
        $lastAt = null; $lastBy = null;
        foreach ($groups as $key => &$group) {
            foreach ($group['items'] as &$item) {
                $mark = $marks[$item['key']] ?? null;
                $item['stale'] = $mark !== null && abs($mark['amount'] - $item['amount']) >= 0.005;
                $item['reviewed'] = $mark !== null && !$item['stale'];
                $item['reviewed_by'] = $mark['by'] ?? null;
                $item['reviewed_at'] = $mark['at'] ?? null;
                if ($item['reviewed'] && ($lastAt === null || (string) $item['reviewed_at'] > $lastAt)) {
                    $lastAt = (string) $item['reviewed_at'];
                    $lastBy = $item['reviewed_by'];
                }
                $group['total'] += $item['amount'];
                $group['count']++;
                if ($item['reviewed']) { $group['reviewed']++; }
                if ($item['stale']) { $group['stale']++; }
                $index[$item['key']] = ['group' => $key, 'amount' => $item['amount']];
            }
            unset($item);
            $group['total'] = round($group['total'], 2);
            $group['pending'] = $group['count'] - $group['reviewed'];
            $group['done'] = $group['count'] > 0 && $group['pending'] === 0;
        }
        unset($group);

        $totals = ['count'=>0,'reviewed'=>0,'pending'=>0,'stale'=>0,
                   'cash'=>0.0,'visa'=>0.0,'blocking'=>0,'blocking_reviewed'=>0];
        foreach ($groups as $key => $group) {
            $totals['count'] += $group['count'];
            $totals['reviewed'] += $group['reviewed'];
            $totals['stale'] += $group['stale'];
            if (in_array($key, self::BLOCKING, true)) {
                $totals['blocking'] += $group['count'];
                $totals['blocking_reviewed'] += $group['reviewed'];
                $totals['cash'] += $group['dir'] === 'out' ? $group['total'] : 0;
            } else {
                $totals['visa'] += $group['total'];
            }
        }
        $totals['pending'] = $totals['count'] - $totals['reviewed'];
        $totals['blocking_pending'] = $totals['blocking'] - $totals['blocking_reviewed'];
        foreach (['cash', 'visa'] as $k) { $totals[$k] = round((float) $totals[$k], 2); }
        $totals['percent'] = $totals['blocking'] > 0
            ? (int) floor($totals['blocking_reviewed'] * 100 / $totals['blocking']) : 100;

        return [
            'available' => self::available(),
            'date'      => $date,
            'groups'    => $groups,
            'index'     => $index,
            'totals'    => $totals,
            'complete'  => self::available() && $totals['blocking_pending'] === 0,
            'last_at'   => $lastAt,
            'last_by'   => $lastBy,
        ];
    }

    private static function group(string $key, string $title, string $icon, string $dir, string $kind, string $hint): array
    {
        return ['key'=>$key,'title'=>$title,'icon'=>$icon,'dir'=>$dir,'kind'=>$kind,'hint'=>$hint,
                'items'=>[],'total'=>0.0,'count'=>0,'reviewed'=>0,'stale'=>0,'pending'=>0,'done'=>false];
    }

    /** علامات اليوم المخزَّنة: key ⇒ [amount, by, at]. */
    private static function marks(string $date): array
    {
        if (!self::available()) { return []; }
        $out = [];
        foreach (Database::all(
            'SELECT item_type,item_id,amount_snapshot,reviewed_by,reviewed_at
             FROM daily_closing_reviews WHERE close_date=?', [$date]
        ) as $row) {
            $out[$row['item_type'] . ':' . (int) $row['item_id']] = [
                'amount' => round((float) $row['amount_snapshot'], 2),
                'by'     => $row['reviewed_by'],
                'at'     => $row['reviewed_at'],
            ];
        }
        return $out;
    }

    // ───────────────────────────────────────────── بنود اليوم (مصدر الحقيقة)

    /**
     * كل بند مالي في اليوم كصف موحّد. المصادر هي نفس مصادر `Closing` بالظبط:
     * manual_custody · permit_stages · manual_expenses · rep_entries ·
     * rep_misc_expenses · bawalis.custody — بلا أي تعريف موازٍ.
     */
    private static function collect(string $date): array
    {
        $items = [];

        // ① عهدة مضافة للخزنة (داخل)
        foreach (Database::all(
            'SELECT id,amount,driver,note FROM manual_custody WHERE cust_date=? AND amount<>0 ORDER BY id', [$date]
        ) as $r) {
            $items[] = self::item('topup', (int) $r['id'], 'topups', (float) $r['amount'], [
                'ref'   => 'إضافة عهدة',
                'title' => 'عهدة مضافة للدرج',
                'party' => $r['driver'] ?: '—',
                'party_label' => 'المصدر',
                'note'  => $r['note'],
                'link'  => 'custody',
            ]);
        }

        // ② بنود الأذونات — الكاش في «permits» والفيزا في «visa»
        $expSql   = Permit::expenseSql('s');
        $qtyCol   = self::columnExists('permit_stages', 'qty') ? 's.qty' : '1';
        $actualCol= self::columnExists('permit_stages', 'actual_amount') ? 's.actual_amount' : 'NULL';
        $repCol   = self::columnExists('permit_stages', 'paid_by_rep_id') ? 's.paid_by_rep_id' : 'NULL';
        $repJoin  = $repCol === 'NULL' ? '' : 'LEFT JOIN reps rp ON rp.id = s.paid_by_rep_id';
        $repName  = $repCol === 'NULL' ? 'NULL' : 'rp.name';
        $mvCols   = self::columnExists('permit_movements', 'container_count')
            ? 'pm.container_count AS mv_cnt, pm.container_type AS mv_type'
            : 'NULL AS mv_cnt, NULL AS mv_type';
        $rows = Database::all(
            "SELECT s.id,s.amount,s.note,s.ref_no,s.stage_key,s.is_visa,s.permit_id,
                    $qtyCol AS qty, $actualCol AS actual_amount, $repName AS rep_name,
                    pm.seq AS movement_seq, $mvCols,
                    p.permit_no,p.permit_type,p.company,p.client,p.destination,
                    p.agency,p.shipping_co,p.port,p.containers,p.container_type
             FROM permit_stages s
             JOIN permits p ON p.id=s.permit_id
             LEFT JOIN permit_movements pm ON pm.id=s.movement_id
             $repJoin
             WHERE $expSql AND s.amount<>0 AND s.entry_date=?
             ORDER BY p.client,p.permit_no,s.id", [$date]
        );
        // تاج الحاويات المعتاد «عدد×تصنيف» — من بوالص الإذن، وإلا من الإذن نفسه.
        $ctByPermit = self::containerLabels(array_column($rows, 'permit_id'));
        foreach ($rows as $r) {
            $visa = (int) $r['is_visa'] === 1;
            $qty  = max(1, (int) ($r['qty'] ?? 1));
            $mvCount = (int) ($r['mv_cnt'] ?? 0);
            $mvType  = trim((string) ($r['mv_type'] ?? ''));
            // «إذن 123» / «شهادة 123» — الوسم الطويل («رقم الإذن») بيتحوّل شارة تحت
            $theme = Permit::theme((string) $r['permit_type']);
            $kind  = (string) $r['permit_type'] === 'warid' ? 'شهادة' : 'إذن';
            $items[] = self::item('stage', (int) $r['id'], $visa ? 'visa' : 'permits', (float) $r['amount'], [
                'ref'       => ($r['permit_no'] ? $kind . ' ' . $r['permit_no'] : '#' . (int) $r['permit_id']),
                'title'     => Permit::label((string) $r['stage_key']),
                'party'     => trim((string) ($r['client'] ?: $r['company'])) ?: '—',
                'party_label' => 'العميل',
                'company'   => trim((string) $r['company']) ?: null,
                'client'    => trim((string) $r['client']) ?: null,
                'permit_type_label' => $theme['arrow'] . ' ' . $theme['short'],
                'destination' => trim((string) $r['destination']) ?: null,
                'agency'      => trim((string) $r['agency']) ?: null,
                'shipping_co' => trim((string) $r['shipping_co']) ?: null,
                'port'        => trim((string) $r['port']) ?: null,
                'containers' => $mvCount > 0
                    ? Permit::containerLabel([], $mvCount, $mvType !== '' ? $mvType : null)
                    : ($ctByPermit[(int) $r['permit_id']] ?? ''),
                'note'      => $r['note'],
                'ref_no'    => $r['ref_no'],
                'qty'       => $qty,
                'unit'      => $qty > 1 ? round(((float) $r['amount']) / $qty, 2) : null,
                'actual'    => $r['actual_amount'] === null ? null : round((float) $r['actual_amount'], 2),
                'by_rep'    => $r['rep_name'] ?: null,
                'movement'  => $r['movement_seq'] ? 'حركة ' . (int) $r['movement_seq'] : null,
                'visa'      => $visa,
                'link'      => 'permits/' . (int) $r['permit_id'],
            ]);
        }

        // ③ مصروفات يدوية
        foreach (Database::all(
            'SELECT id,amount,category,beneficiary,note,is_visa FROM manual_expenses
             WHERE amount<>0 AND exp_date=? ORDER BY id', [$date]
        ) as $r) {
            $visa = (int) $r['is_visa'] === 1;
            $items[] = self::item('manual', (int) $r['id'], $visa ? 'visa' : 'general', (float) $r['amount'], [
                'ref'   => 'مصروف يدوي',
                'title' => $r['category'] ?: 'مصروف يدوي',
                'party' => $r['beneficiary'] ?: '—',
                'party_label' => 'المستفيد',
                'note'  => $r['note'],
                'visa'  => $visa,
                'link'  => 'expenses/' . (int) $r['id'] . '/edit',
            ]);
        }

        // ④ كاش المناديب (سُلف + دفعات تسوية) — الوحيد اللي بيطلّع كاش من وحدة المناديب
        if (self::tableExists('rep_entries')) {
            $cash = Rep::cashKindsList();
            foreach (Database::all(
                "SELECT e.id,e.rep_id,e.kind,e.amount,e.note,e.is_visa,r.name
                 FROM rep_entries e JOIN reps r ON r.id=e.rep_id
                 WHERE e.kind IN ($cash) AND e.amount<>0 AND e.entry_date=? ORDER BY e.id", [$date]
            ) as $r) {
                $visa = (int) $r['is_visa'] === 1;
                $items[] = self::item('rep', (int) $r['id'], $visa ? 'visa' : 'general', (float) $r['amount'], [
                    'ref'   => 'مندوب',
                    'title' => Rep::kindLabel((string) $r['kind']),
                    'party' => $r['name'] ?: '—',
                    'party_label' => 'المندوب',
                    'note'  => $r['note'],
                    'visa'  => $visa,
                    'link'  => 'reps/' . (int) $r['rep_id'],
                ]);
            }
        }

        // ⑤ نثريات المناديب (v39) — كاش خرج من الدرج عبر عهدة المندوب (v42)
        if (self::tableExists('rep_misc_expenses')) {
            $visaCol = RepMiscExpense::visaReady() ? 'x.is_visa' : '0';
            foreach (Database::all(
                "SELECT x.id,x.rep_id,x.amount,x.category,x.note,x.ref_no,$visaCol AS is_visa,r.name
                 FROM rep_misc_expenses x JOIN reps r ON r.id=x.rep_id
                 WHERE x.amount<>0 AND x.exp_date=? ORDER BY x.id", [$date]
            ) as $r) {
                $visa = !empty($r['is_visa']);
                $items[] = self::item('rep_misc', (int) $r['id'], $visa ? 'visa' : 'general', (float) $r['amount'], [
                    'ref'    => 'نثرية مندوب',
                    'title'  => $r['category'] ?: RepMiscExpense::CATEGORY,
                    'party'  => $r['name'] ?: '—',
                    'party_label' => 'المندوب',
                    'note'   => $r['note'],
                    'ref_no' => $r['ref_no'],
                    'visa'   => $visa,
                    'link'   => 'rep-custody/' . (int) $r['rep_id'],
                ]);
            }
        }

        // ⑥ عهد السائقين — المبلغ المراجَع هو **المخصوم فعلاً** (بعد خصم البوليصة)
        foreach (Database::all(
            'SELECT b.id,b.bill_number,b.driver,b.company,b.custody,b.car_no,b.car_owner,
                    b.destination,b.container_type,b.container_count,b.declaration_owner,
                    b.freight,b.permit_id,p.permit_no,p.permit_type,p.agency,p.client
             FROM bawalis b LEFT JOIN permits p ON p.id=b.permit_id
             WHERE b.is_legacy=0 AND b.bill_date=? AND b.custody>0 ORDER BY b.id', [$date]
        ) as $r) {
            $custody   = (float) $r['custody'];
            $deduction = Custody::billDeduction($custody, $r['car_no'] ?? null);
            $items[] = self::item('custody', (int) $r['id'], 'custody', $deduction, [
                'ref'   => $r['bill_number'] ? 'بوليصة ' . $r['bill_number']
                                             : ($r['permit_no'] ? 'إذن ' . $r['permit_no'] : '#' . (int) $r['id']),
                'title' => 'عهدة سائق',
                'party' => $r['driver'] ?: ($r['company'] ?: '—'),
                'party_label' => 'السائق',
                'company' => trim((string) $r['company']) ?: null,
                'client'  => trim((string) ($r['client'] ?: $r['declaration_owner'])) ?: null,
                'owner'   => trim((string) $r['declaration_owner']) ?: null,
                'agency'  => trim((string) $r['agency']) ?: null,
                'destination' => trim((string) $r['destination']) ?: null,
                'containers'  => Permit::containerLabel([], (int) $r['container_count'],
                                                        $r['container_type'] ?: null),
                'car'     => trim((string) $r['car_no']) ?: null,
                'car_owner' => trim((string) $r['car_owner']) ?: null,
                'permit_ref' => $r['permit_no']
                    ? ((string) $r['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ') . $r['permit_no'] : null,
                'freight' => $r['freight'] === null ? null : round((float) $r['freight'], 2),
                'gross'   => round($custody, 2),
                'note'    => 'العهدة المسلَّمة ' . number_format($custody, 2)
                             . ' − خصم ' . number_format(round($custody - $deduction, 2), 2),
                'link'    => 'bawalis/' . (int) $r['id'],
            ]);
        }

        return $items;
    }

    private static function item(string $type, int $id, string $group, float $amount, array $extra): array
    {
        return array_merge([
            'ref'=>'', 'title'=>'', 'party'=>'—', 'note'=>null, 'ref_no'=>null,
            'movement'=>null, 'visa'=>false, 'link'=>null,
            // تفاصيل إضافية للعرض — كلها وصفية ولا تدخل أي حساب
            'company'=>null, 'client'=>null, 'permit_type_label'=>null, 'destination'=>null,
            'agency'=>null, 'shipping_co'=>null, 'port'=>null, 'owner'=>null, 'party_label'=>'الجهة',
            'containers'=>'', 'qty'=>1, 'unit'=>null, 'actual'=>null, 'by_rep'=>null,
            'car'=>null, 'car_owner'=>null, 'permit_ref'=>null, 'freight'=>null, 'gross'=>null,
        ], $extra, [
            'type'=>$type, 'id'=>$id, 'key'=>$type . ':' . $id,
            'group'=>$group, 'amount'=>round($amount, 2),
        ]);
    }

    /**
     * تاج الحاويات «عدد×تصنيف» لكل إذن — من بوالص الإذن الحيّة، بنفس منطق
     * `Metrics::attachContainerLabels` وعبر `Permit::containerLabel` (المصدر
     * الوحيد للصياغة) فما فيش تحويل موازٍ.
     */
    private static function containerLabels(array $permitIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $permitIds))));
        if (!$ids) { return []; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $breakdown = [];
        foreach (Database::all(
            "SELECT permit_id, container_type AS type, SUM(container_count) AS cnt
             FROM bawalis
             WHERE is_legacy=0 AND permit_id IN ($ph)
               AND container_type IS NOT NULL AND container_type <> '' AND container_count > 0
             GROUP BY permit_id, container_type", $ids
        ) as $r) {
            $breakdown[(int) $r['permit_id']][] = ['type' => $r['type'], 'cnt' => (int) $r['cnt']];
        }
        $fallback = [];
        foreach (Database::all(
            "SELECT id, containers, container_type FROM permits WHERE id IN ($ph)", $ids
        ) as $p) { $fallback[(int) $p['id']] = $p; }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = Permit::containerLabel(
                $breakdown[$id] ?? [],
                (int) ($fallback[$id]['containers'] ?? 0),
                $fallback[$id]['container_type'] ?? null
            );
        }
        return $out;
    }

    /** وجود عمود اختياري (أعمدة ترحيلات لاحقة زي qty/paid_by_rep_id). */
    private static function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = (bool) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', [$table, $column]);
        }
        return $cache[$key];
    }

    // ───────────────────────────────────────────── الكتابة (علامة المراجعة)

    /**
     * تعليم/إلغاء تعليم بند واحد. البند لازم يكون **موجود فعلاً في اليوم**
     * (بيتفحص من `view`) — الفورم مش مصدر ثقة. بترجّع حالة اليوم بعد التعديل.
     */
    public static function mark(string $date, string $type, int $id, bool $state, ?string $user): array
    {
        self::assertReady();
        $view = self::view($date);
        $key = $type . ':' . $id;
        if (!isset($view['index'][$key])) {
            throw new \DomainException('البند ده مش من بنود يوم ' . dfmt($date) . '. حدّث الصفحة وجرّب تاني.');
        }
        self::write($date, [$key => $view['index'][$key]['amount']], $state, $user);
        return self::view($date);
    }

    /** تعليم/إلغاء تعليم كل بنود مجموعة (زرار «علّم الكل»). بترجّع عدد اللي اتغيّر. */
    public static function markGroup(string $date, string $group, bool $state, ?string $user): array
    {
        self::assertReady();
        $view = self::view($date);
        if (!isset($view['groups'][$group])) {
            throw new \DomainException('مجموعة بنود غير معروفة.');
        }
        $pairs = [];
        foreach ($view['groups'][$group]['items'] as $item) { $pairs[$item['key']] = $item['amount']; }
        self::write($date, $pairs, $state, $user);
        return self::view($date);
    }

    /**
     * حفظ المجموعة الكاملة من الفورم (مسار بلا جافاسكريبت): اللي متعلّم يتسجّل،
     * واللي مش متعلّم تتشال علامته. بيتجاهل أي مفتاح مش من بنود اليوم.
     */
    public static function saveSet(string $date, array $keys, ?string $user): array
    {
        self::assertReady();
        $view = self::view($date);
        $wanted = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (isset($view['index'][$key])) { $wanted[$key] = $view['index'][$key]['amount']; }
        }
        $drop = [];
        foreach ($view['index'] as $key => $meta) {
            if (!isset($wanted[$key])) { $drop[$key] = $meta['amount']; }
        }
        if ($wanted) { self::write($date, $wanted, true, $user); }
        if ($drop)   { self::write($date, $drop, false, $user); }
        return self::view($date);
    }

    /** الكتابة الفعلية — نقطة واحدة، فمفيش استعلام كتابة متكرر في الكلاس. */
    private static function write(string $date, array $pairs, bool $state, ?string $user): void
    {
        if (!$pairs) { return; }
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            foreach ($pairs as $key => $amount) {
                [$type, $id] = explode(':', (string) $key, 2);
                if ($state) {
                    Database::exec(
                        'INSERT INTO daily_closing_reviews (close_date,item_type,item_id,amount_snapshot,reviewed_by)
                         VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE amount_snapshot=VALUES(amount_snapshot),
                            reviewed_by=VALUES(reviewed_by),reviewed_at=CURRENT_TIMESTAMP',
                        [$date, $type, (int) $id, round((float) $amount, 2), $user]
                    );
                } else {
                    Database::exec(
                        'DELETE FROM daily_closing_reviews WHERE close_date=? AND item_type=? AND item_id=?',
                        [$date, $type, (int) $id]
                    );
                }
            }
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    private static function assertReady(): void
    {
        if (!self::available()) {
            throw new \RuntimeException('جدول المراجعة غير موجود. شغّل php bin/migrate_v50.php أولاً.');
        }
    }
}
