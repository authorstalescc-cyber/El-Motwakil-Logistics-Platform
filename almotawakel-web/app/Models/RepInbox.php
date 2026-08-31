<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/**
 * مراجعة بنود المناديب (v35) — الصندوق اللي بيتحوّل فيه البند المرسَل لبند حقيقي.
 *
 * دورة حياة البند:
 *   المندوب يبعت → rep_submissions (status=pending، **بلا أي أثر مالي**)
 *   المالك يعتمد  → يتولّد صف permit_stages حقيقي موسوم paid_by_rep_id
 *   المالك يرفض   → يفضل مرفوض بسبب مكتوب، **بلا أي أثر مالي**
 *
 * ⚠️ البند المعتمد:
 *   • بيدخل تايم لاين الإذن وفاتورة العميل وسجل المصروفات **زي أي بند عادي**.
 *   • **بيتخصم من الخزنة يوم صرفه** (v42) *و*من عهدة المندوب. مفيش تحسيب مزدوج:
 *     تنزيل العهدة للمندوب مالوش أثر على الخزنة، فالخصم الوحيد هو الصرف ده.
 *   • actual_amount بيفضل NULL — المالك بيحطه بإيده بعدين من `/expenses/receipts`
 *     («استكمال بيانات الإيصالات»)، زي عادته بالظبط. المندوب عمره ما يحط سعر العميل.
 *   • تاريخه = اليوم اللي المندوب دفع فيه، **مش يوم الاعتماد** (قاعدة v29).
 *
 * ⚠️ **Closing::guard إلزامي هنا من v42** (كان مقصودًا غيابه في v35 لما كان البند
 *    معزولًا عن الخزنة): البند بقى بيغيّر رصيد يومه، فاعتماده على يوم متقفل كان
 *    هيعدّل رصيدًا مثبَّتًا في الخفا. الاعتماد بيترفض برسالة بتقول افتح اليوم الأول.
 */
class RepInbox
{
    // ─────────────────────────────────────────── العرض

    /** بند واحد + بيانات الإذن. */
    public static function find(int $id): ?array
    {
        return Database::row(
            "SELECT s.*, r.name AS rep_name,
                    p.permit_no, p.permit_type, p.company, p.client,
                    m.seq AS movement_seq
             FROM rep_submissions s
             JOIN reps r ON r.id = s.rep_id
             LEFT JOIN permits p ON p.id = s.permit_id
             LEFT JOIN permit_movements m ON m.id = s.movement_id
             WHERE s.id = ?", [$id]);
    }

    /**
     * شرط الفلترة المشترك — **المصدر الوحيد** لـ«أنهي بنود بتتعرض».
     * لازم `listing()` و`countListing()` يستخدموه مع بعض، وإلا الترقيم يقول
     * «صفحة ٢ من ٨» والقايمة تعرض حاجة تانية.
     *
     * @return array{0:string,1:array} [جملة WHERE، معاملاتها]
     */
    private static function filterWhere(string $status, int $repId, string $day): array
    {
        $w = []; $p = [];
        if ($status !== '') { $w[] = 's.status = ?'; $p[] = $status; }
        if ($repId > 0)     { $w[] = 's.rep_id = ?'; $p[] = $repId; }
        if ($day !== '')    { $w[] = 's.entry_date = ?'; $p[] = $day; }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /**
     * بنود للمراجعة. $status: pending|approved|rejected|'' (الكل).
     * $repId=0 → كل المناديب. $day='' → كل الأيام.
     *
     * ⚠️ `$limit` **إلزامي فعليًّا للتبويبات غير المستنية**: «معتمد» فيه ٣٤٦ بند
     *    على الإنتاج، ورسمهم في صفحة واحدة كان بيتقل الشاشة بلا داعٍ.
     *    `$limit = 0` = بلا حدّ (السلوك القديم — لسه مستخدَم في تبويب «مستني»
     *    وفي كارت المندوب اللي بيقرا بنود مندوب واحد).
     */
    public static function listing(string $status = 'pending', int $repId = 0, string $day = '', int $limit = 0, int $offset = 0): array
    {
        [$where, $p] = self::filterWhere($status, $repId, $day);
        // ⚠️ `LIMIT ?` ما بتنفعش: الاتصال بـ`ATTR_EMULATE_PREPARES => false`
        //    وMySQL بترفض معامل مربوط في LIMIT. الحقن كـint مضبوط (نفس نمط
        //    `Audit::recent` و`SecurityMonitor`).
        $lim = '';
        if ($limit > 0) {
            $lim = ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        }

        return Database::all(
            "SELECT s.*, r.name AS rep_name,
                    p.permit_no, p.permit_type, p.company, p.client,
                    m.seq AS movement_seq
             FROM rep_submissions s
             JOIN reps r ON r.id = s.rep_id
             LEFT JOIN permits p ON p.id = s.permit_id
             LEFT JOIN permit_movements m ON m.id = s.movement_id
             $where
             ORDER BY s.entry_date DESC, r.name, s.id$lim", $p);
    }

    /** عدد البنود المطابقة لنفس الفلتر — أساس الترقيم. */
    public static function countListing(string $status = 'pending', int $repId = 0, string $day = ''): int
    {
        [$where, $p] = self::filterWhere($status, $repId, $day);
        return (int) Database::scalar("SELECT COUNT(*) FROM rep_submissions s $where", $p);
    }

    /**
     * البنود المستنية مجمّعة بالمندوب ثم اليوم — عشان الاعتماد الجماعي.
     *
     * ⚠️ `$repId`/`$day` **عدسة عرض بس**: الصلاحيات والحُرّاس كلها في
     *    `approve/update/delete` وما بتتغيّرش. الغرض إن المالك يدوس على مندوب
     *    من كارته فيراجع بنوده هو بدل ما يدوّر وسط كل المناديب.
     */
    public static function pendingGrouped(int $repId = 0, string $day = ''): array
    {
        $out = [];
        foreach (self::listing('pending', $repId, $day) as $row) {
            $rid = (int) $row['rep_id'];
            $day = (string) $row['entry_date'];
            $out[$rid]['rep_id'] = $rid;
            $out[$rid]['rep_name'] = $row['rep_name'];
            $out[$rid]['total'] = ($out[$rid]['total'] ?? 0) + (float) $row['amount'];
            $out[$rid]['count'] = ($out[$rid]['count'] ?? 0) + 1;
            $out[$rid]['days'][$day]['day'] = $day;
            $out[$rid]['days'][$day]['total'] = ($out[$rid]['days'][$day]['total'] ?? 0) + (float) $row['amount'];
            $out[$rid]['days'][$day]['rows'][] = $row;
        }
        return $out;
    }

    /**
     * البنود المستنية مقسّمة على المناديب — لشرايح الفلترة فوق الصندوق.
     * استعلام واحد مجمَّع (مش لفّة على `pendingGrouped` اللي بتجيب كل الصفوف).
     *
     * @return array<int,array{rep_id:int,name:string,count:int,total:float}>
     */
    public static function pendingByRep(): array
    {
        if (!RepCustody::available()) { return []; }
        $rows = Database::all(
            "SELECT s.rep_id, r.name, COUNT(*) AS c, SUM(s.amount) AS t
             FROM rep_submissions s
             JOIN reps r ON r.id = s.rep_id
             WHERE s.status = 'pending'
             GROUP BY s.rep_id, r.name
             ORDER BY c DESC, r.name");
        return array_map(static fn (array $r): array => [
            'rep_id' => (int) $r['rep_id'],
            'name'   => (string) $r['name'],
            'count'  => (int) $r['c'],
            'total'  => (float) $r['t'],
        ], $rows);
    }

    /**
     * حركات مجموعة أذونات في استعلام واحد — بديل `movementsOf()` جوّه اللوب.
     *
     * ⚠️ كانت `movementsOf()` بتتنادى **لكل بند** في الشاشة (N+1). الشاشة
     *    بتعرض عشرات البنود على نفس الأذونات، فده كان استعلامًا لكل صف.
     *
     * @param int[] $permitIds
     * @return array<int,array<int,array>> permit_id → حركاته
     */
    public static function movementsFor(array $permitIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $permitIds))));
        if (!$ids) { return []; }
        $in = implode(',', $ids);   // ints مضبوطة — مفيش مدخل مستخدم هنا
        $out = [];
        foreach (Database::all(
            "SELECT permit_id, id, seq, note, container_count, container_type
             FROM permit_movements WHERE permit_id IN ($in) ORDER BY permit_id, seq, id") as $r) {
            $out[(int) $r['permit_id']][] = $r;
        }
        return $out;
    }

    /**
     * البند ده اعتماده هيترفض بسبب خط الفصل؟ (عرض بس — الحارس الحقيقي
     * `epochBlock` جوّه `approve`). بيخلّي الشاشة تقول السبب قبل الضغط بدل
     * ما المالك يدوس ويستغرب الرفض.
     */
    public static function isPreEpoch(?string $entryDate): bool
    {
        $epoch = RepCustody::epoch();
        $d = (string) ($entryDate ?? '');
        return $epoch !== '' && $d !== '' && $d < $epoch;
    }

    /** عدد البنود المستنية (للشارة في القائمة). */
    public static function pendingCount(): int
    {
        if (!RepCustody::available()) { return 0; }
        // ⚠️ خط الفصل: البنود القديمة **اعتمادها مرفوض** (`epochBlock`)، فعدّها في
        //    الشارة كان بيدّي رقمًا المالك مش قادر يقلّله مهما راجع — و«الجرس يقول
        //    رقم والصفحة تقول رقم تاني» (فرق 6 بنود مقيس على الإنتاج).
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM rep_submissions WHERE status = 'pending'"
            . RepCustody::epochSql('entry_date'));
    }

    /**
     * يقفل البند على المندوب لحظة ما موظف المكتب يبدأ مراجعته.
     * العملية idempotent: الضغط مرة ثانية لا يغيّر صاحب/وقت بداية المراجعة.
     */
    public static function startReview(int $subId): void
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $s = self::lockSubmission($subId);
            if (!$s) { throw new RuntimeException('البند مش موجود.'); }
            if ($s['status'] !== 'pending') { throw new RuntimeException('البند اتراجع قبل كده.'); }
            if (empty($s['review_started_at'])) {
                Database::exec(
                    "UPDATE rep_submissions SET review_started_at=NOW(), review_started_by=?
                     WHERE id=? AND status='pending' AND review_started_at IS NULL",
                    [Auth::user(), $subId]
                );
            }
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    // ─────────────────────────────────────────── التعديل قبل الاعتماد (v37)

    /**
     * تعديل بند مستني — المالك بيصلّحه بدل ما يرفضه ويستنى المندوب يعيده.
     * الحقول المسموح تعديلها: note, qty, unit_amount, actual_unit, entry_date,
     * ref_no, movement_id, kind.
     *
     * ⚠️ **بلا أي أثر مالي** — البند لسه في الانتظار. الأثر بيحصل وقت الاعتماد بس.
     * $d فيها مفاتيح موجودة بس = تتعدّل؛ الباقي يفضل زي ما هو.
     */
    public static function update(int $subId, array $d): void
    {
        $s = self::find($subId);
        if (!$s) { throw new RuntimeException('البند مش موجود.'); }
        if ($s['status'] !== 'pending') {
            throw new RuntimeException($s['status'] === 'approved'
                ? 'البند ده اتعتمد خلاص — عدّله من تبويب «معتمد» بزرار «عدّل المعتمد».'
                : 'البند ده اتراجع خلاص.');
        }

        $v = self::validated($s, $d);
        self::writeSubmission($subId, 'pending', $v);

        Audit::log('update', 'rep_submissions', $subId, $s, self::find($subId),
            'تعديل بند مندوب قبل الاعتماد «' . $v['note'] . '» — ' . number_format($v['amount'], 2, '.', ','),
            ['rep_id' => (int) $s['rep_id'], 'record_date' => $v['date']]);
    }

    /**
     * **المصدر الوحيد لتوليد سطور الإيصال بعد الاعتماد أو بعد تعديل معتمد.**
     *
     * ⚠️ **الاعتماد ما يقعش أبدًا بسبب تفصيل.** لو `lines_json` مخالف لإجمالي
     *    البند أو لعدده (بند اتعدّل من مسار قديم قبل ما التفصيل يتحدّث)،
     *    بنرجع للتقسيم بالتساوي بدل ما نسيب `sync()` ترمي وتفشّل اعتمادًا
     *    ماليًّا سليم. المال هو الأصل والتفصيل خادم له، مش العكس.
     */
    private static function syncStageLines(int $stageId, array $sub): void
    {
        if (!ReceiptLine::ready() || $stageId <= 0) { return; }

        $lines  = ReceiptLine::decode($sub['lines_json'] ?? null);
        $amount = round((float) ($sub['amount'] ?? 0), 2);
        $qty    = (int) ($sub['qty'] ?? 0);

        if ($lines !== null
            && count($lines) === $qty
            && abs(round(array_sum($lines), 2) - $amount) < 0.005) {
            ReceiptLine::sync($stageId, $lines);
            return;
        }

        // بلا تفصيل (أو تفصيل متقادم) → سطر لكل إيصال بالتساوي، عشان التوزيع
        // على العربيات يشتغل على كل البنود بنفس الطريقة.
        ReceiptLine::ensureFor($stageId);
    }

    /**
     * وصف العدد في سجل التدقيق — «(١٢ إيصال · ٣×١٨٠ · ٢×٢٥٠)» للأسعار
     * المختلفة، و«(٣ × ٢٥٠)» للسعر الواحد المتكرر.
     *
     * ⚠️ **ممنوع «الكمية × سعر الوحدة» على بند أسعاره مختلفة** — الرقم ده
     *    متوسط ما دفعوش حد، وكتابته في السجل بتخلّيه شاهدًا على رقم وهمي.
     */
    private static function qtyLabel(array $sub): string
    {
        $qty = (int) ($sub['qty'] ?? 1);
        if ($qty <= 1) { return ''; }

        $lines = ReceiptLine::decode($sub['lines_json'] ?? null);
        if ($lines !== null && ReceiptLine::varied($lines)) {
            return ' (' . $qty . ' إيصال · ' . ReceiptLine::summary($lines) . ')';
        }
        return ' (' . $qty . ' × ' . number_format((float) ($sub['unit_amount'] ?? 0), 2, '.', ',') . ')';
    }

    /**
     * ⚠️⚠️ **المصدر الوحيد للتحقّق من حقول بند المندوب** — بيتنادى من
     * `update()` (البند المستني) ومن `updateApproved()` (البند المعتمد).
     *
     * من غيرها كانت الـ١٢ قاعدة اللي تحت هتتكتب مرتين، ونسيان واحدة معناها إن
     * الشاشتين بيقبلوا حاجتين مختلفتين على **نفس البند** — يعني بند يعدّي من
     * تبويب ويترفض من التاني بلا سبب مفهوم.
     *
     * `$d` فيها مفاتيح موجودة بس = تتعدّل؛ الباقي يفضل زي ما هو في `$s`.
     *
     * @param array $s صف البند الحالي (من `find()`)
     * @return array{note:string,kind:string,is_misc:bool,qty:int,unit:float,actual:?float,
     *               amount:float,date:string,ref:?string,movement_id:?int,misc_kind:?string,
     *               is_visa:bool,visa_card_id:?int,unpaid:bool}
     */
    private static function validated(array $s, array $d): array
    {
        $note = array_key_exists('note', $d) ? trim((string) $d['note']) : (string) $s['note'];
        if ($note === '') { throw new RuntimeException('اكتب البيان.'); }
        if (mb_strlen($note) > 255) { throw new RuntimeException('البيان أطول من 255 حرفًا.'); }

        $kind = array_key_exists('kind', $d) ? (string) $d['kind'] : (string) $s['kind'];
        if (!isset(RepPortal::KINDS[$kind])) { throw new RuntimeException('نوع البند غير صحيح.'); }

        // النثرية مالهاش إذن — فما ينفعش تتحوّل لبند على إذن ولا العكس من هنا،
        // لأن البند العادي لازمه إذن وحركة والنثرية مالهاش. لو المالك عايز يحوّلها
        // فعلاً، يرفضها ويسجّلها من مكانها الصح.
        $wasMisc = RepPortal::isMisc((string) $s['kind']);
        if ($wasMisc !== RepPortal::isMisc($kind)) {
            throw new RuntimeException($wasMisc
                ? 'دي نثرية عامة بلا إذن — ما ينفعش تحوّلها لبند على إذن. ارفضها والمندوب يسجّلها من الإذن.'
                : 'البند ده على إذن — ما ينفعش تحوّله لنثرية عامة. ارفضه والمندوب يسجّله كنثرية.');
        }

        /* ══ المبلغ (v109) — يا قايمة أسعار يا سعر واحد متكرر ══
           نفس قاعدة `RepPortal::normaliseSubmission` بالحرف: خانة `amounts`
           لما تتبعت بتحكم، والعدد والإجمالي بيطلعوا منها. الطريق القديم
           (`qty` + `unit_amount`) باقي زي ما هو للنثرية وللتعديل الجماعي
           اللي بيبعت التاريخ بس.

           ⚠️ **الحقل الفاضي المبعوت صراحةً = «شيل التفصيل»** مش «سيبه»: المالك
              اللي مسح الأسعار وكتب سعرًا واحدًا لازم التفصيل القديم يروح
              معاه، وإلا `receipt_lines` بيفضل شايل أسعارًا مجموعها مش
              الإجمالي الجديد. */
        $lines = null;
        if (array_key_exists('amounts', $d) && trim(ReceiptLine::rawText($d['amounts'])) !== '') {
            $lines = ReceiptLine::parse($d['amounts']);
            if (!$lines) { throw new RuntimeException('اكتب سعر إيصال واحد على الأقل.'); }
        }

        if ($lines !== null) {
            $qty  = count($lines);
            $sum  = round(array_sum($lines), 2);
            if ($sum <= 0) { throw new RuntimeException('إجمالي الأسعار لازم يكون أكبر من صفر.'); }
            $unit = round($sum / $qty, 2);
        } else {
            $qty = array_key_exists('qty', $d) ? (int) $d['qty'] : (int) $s['qty'];
            if ($qty < 1 || $qty > 10000) { throw new RuntimeException('الكمية لازم تكون من 1 لـ10000.'); }

            $unit = array_key_exists('unit_amount', $d)
                ? round((float) $d['unit_amount'], 2) : round((float) $s['unit_amount'], 2);
            if ($unit <= 0) { throw new RuntimeException('المبلغ لازم يكون أكبر من صفر.'); }
            $sum  = round($unit * $qty, 2);
        }

        // السعر الفعلي للعميل — فاضي = يتحطّ بعدين من /expenses/receipts.
        // ⚠️ النثرية مالهاش سعر فعلي أصلاً (مش بتتحاسب على أي عميل)، فبنفرضه NULL
        //    حتى لو الفورم بعت قيمة — وإلا رقم بلا معنى يقعد في القاعدة.
        $actual = $wasMisc ? null : (array_key_exists('actual_unit', $d)
            ? (trim((string) $d['actual_unit']) === '' ? null : round((float) $d['actual_unit'], 2))
            : ($s['actual_unit'] === null ? null : round((float) $s['actual_unit'], 2)));
        if ($actual !== null && $actual < 0) { throw new RuntimeException('السعر الفعلي لا يصح أن يكون سالبًا.'); }

        $date = array_key_exists('entry_date', $d) ? trim((string) $d['entry_date']) : (string) $s['entry_date'];
        if (!self::validDate($date)) { throw new RuntimeException('التاريخ غير صحيح.'); }

        $ref = array_key_exists('ref_no', $d) ? trim((string) $d['ref_no']) : (string) ($s['ref_no'] ?? '');

        // النثرية بلا إذن → بلا حركة، فبنتخطّى فحص تبعية الحركة كله
        $movementId = $wasMisc ? null : (array_key_exists('movement_id', $d)
            ? ((int) $d['movement_id'] ?: null) : ($s['movement_id'] ? (int) $s['movement_id'] : null));
        if ($wasMisc) {
            $movementId = null;
        } elseif ($movementId !== null) {
            $belongs = Database::scalar(
                "SELECT COUNT(*) FROM permit_movements WHERE id = ? AND permit_id = ?",
                [$movementId, (int) $s['permit_id']]);
            if (!$belongs) { throw new RuntimeException('الحركة المختارة مش تابعة للإذن.'); }
        } elseif (Database::scalar("SELECT COUNT(*) FROM permit_movements WHERE permit_id = ?", [(int) $s['permit_id']])) {
            throw new RuntimeException('اختر الحركة.');
        }

        // الفيزا (v45) — المالك يقدر يصحّح وسم الدفع أو الكارت قبل الاعتماد،
        // زي أي حقل تاني. الكارت إلزامي لو الوسم مفعّل وإلا المبلغ ما يتنسبش لحد.
        /* وسم النثرية (v71) — المالك يقدر يصحّحه قبل الاعتماد زي أي حقل، لأن
           المندوب ممكن يكون اختار غلط والوسم ده هيتحاسب عليه في الراتب.
           ⚠️ النثرية بس: البند على إذن بياخد NULL دايمًا مهما بعت الفورم. */
        $miscKind = null;
        if ($wasMisc) {
            if (array_key_exists('misc_kind', $d)) {
                // الفورم بعت الحقل صراحةً → إجباري وصالح. النثرية القديمة (قبل
                // v71) بتوصل هنا بقيمة فاضية فالمالك مضطر يختار — وده المطلوب.
                $miscKind = trim((string) $d['misc_kind']);
                if ($miscKind === '') {
                    throw new RuntimeException('اختر نوع النثرية: سلفة أو مواصلات داخلية أو خارجية أو عامة.');
                }
                if (!RepMiscKind::has($miscKind)) {
                    throw new RuntimeException('نوع النثرية مش من القايمة المعتمدة.');
                }
            } else {
                /* ⚠️ الحقل مش مبعوت خالص (تعديل جماعي للتاريخ مثلاً) → بنسيب
                   المخزَّن زي ما هو **بلا تحقّق**. من غير الاستثناء ده، تعديل
                   دفعة فيها نثرية قديمة بلا وسم كان هيرمي ويرجّع الدفعة كلها. */
                $miscKind = ($s['misc_kind'] ?? '') !== '' ? (string) $s['misc_kind'] : null;
            }
        }

        $isVisa = array_key_exists('is_visa', $d) ? !empty($d['is_visa']) : !empty($s['is_visa']);
        $cardId = array_key_exists('visa_card_id', $d)
            ? ((int) $d['visa_card_id'] ?: null)
            : (!empty($s['visa_card_id']) ? (int) $s['visa_card_id'] : null);
        // ⚠️ v71 — السلفة كاش بنيويًّا (كارت المكتب ما بينزّلش كاش لجيب المندوب).
        if ($isVisa && $miscKind !== null && RepMiscKind::isCashOnly($miscKind)) {
            $isVisa = false; $cardId = null;
        }
        if (!$isVisa) {
            $cardId = null;
        } elseif ($cardId === null) {
            throw new RuntimeException('اختر كارت الفيزا اللي اتدفع منه، أو شيل علامة «مدفوع بالفيزا».');
        } elseif (!VisaCard::isActive($cardId)) {
            throw new RuntimeException('كارت الفيزا المختار مش موجود أو متوقف.');
        }

        // «غير مدفوع» (v57) — المالك يقدر يحطّه أو يشيله قبل الاعتماد زي أي حقل.
        // ⚠️ النثرية مالهاش الوسم ده (قرار المالك: بتتدفع من عهدته فعلاً)،
        //    ومتنافي مع الفيزا: يا اتدفع من كارت المكتب يا ما اتدفعش منّنا خالص.
        $unpaid = $wasMisc ? false : (array_key_exists('unpaid', $d)
            ? !empty($d['unpaid']) : !empty($s['unpaid']));
        if ($unpaid && $isVisa) {
            throw new RuntimeException('اختار واحدة بس: يا «مدفوع بالفيزا» يا «غير مدفوع».');
        }

        return [
            'note'         => $note,
            'kind'         => $kind,
            'is_misc'      => $wasMisc,
            'qty'          => $qty,
            'unit'         => $unit,
            'actual'       => $actual,
            /* ⚠️ **الإجمالي من مجموع الأسعار لا من الضرب** لما القايمة تتبعت:
               `round(sum/qty, 2) × qty` ما بيرجّعش المجموع لما الأسعار تختلف
               (١٢ إيصال بـ٢٬٨٧٠ بيدّي ٢٬٨٧٠٫٠٤) — والقرش ده كان هيتخصم من
               عهدة المندوب بلا سبب. */
            'amount'       => $sum,
            'lines'        => $lines,
            'date'         => $date,
            'ref'          => $ref !== '' ? $ref : null,
            'movement_id'  => $movementId,
            'misc_kind'    => $miscKind,
            'is_visa'      => $isVisa,
            'visa_card_id' => $cardId,
            'unpaid'       => $unpaid,
        ];
    }

    /**
     * كتابة القيم المتحقَّقة في `rep_submissions`.
     *
     * ⚠️ `$status` في شرط `WHERE` **مش زينة**: هو اللي بيضمن إن التعديل ما
     *    يتنفّذش على بند غيّر حالته من طلب تاني بين القراءة والكتابة.
     */
    private static function writeSubmission(int $subId, string $status, array $v): void
    {
        $kindCol = RepPortal::miscKindReady();   // v71
        $lineCol = ReceiptLine::ready();         // v109
        $set = [$v['note'], $v['kind'], $v['qty'], $v['unit'], $v['actual'], $v['amount'],
                $v['date'], $v['ref'], $v['movement_id'], $v['is_visa'] ? 1 : 0, $v['visa_card_id'],
                $v['unpaid'] ? 1 : 0];
        if ($kindCol) { $set[] = $v['misc_kind']; }
        if ($lineCol) { $set[] = $v['lines'] !== null ? json_encode($v['lines']) : null; }
        Database::exec(
            "UPDATE rep_submissions
             SET note=?, kind=?, qty=?, unit_amount=?, actual_unit=?, amount=?,
                 entry_date=?, ref_no=?, movement_id=?, is_visa=?, visa_card_id=?, unpaid=?"
                 . ($kindCol ? ', misc_kind=?' : '') . ($lineCol ? ', lines_json=?' : '') . ",
                 edited_by=?, edited_at=NOW()
             WHERE id=? AND status=?",
            array_merge($set, [Auth::user(), $subId, $status]));
    }

    // ─────────────────────────────────────────── التعديل بعد الاعتماد (v95)

    /**
     * تعديل بند **معتمد** من صندوق المراجعة — تحكّم كامل بعد الاعتماد
     * (قرار المالك 2026-08-15).
     *
     * كان الطريق الوحيد لتصحيح بند معتمد إنك تفتح تايم لاين الإذن وتدوّر عليه،
     * والنثرية مكانش ليها طريق أصلاً. دلوقتي البند بيتعدّل من مكانه.
     *
     * ⚠️⚠️ **الفرق الجوهري عن `update()`: البند ده ليه أثر مالي قايم.** الاعتماد
     *    ولّد صفًّا حقيقيًّا (`permit_stages` أو `rep_misc_expenses`) بيتخصم من
     *    الخزنة يوم صرفه ومن عهدة المندوب ويدخل فاتورة العميل. فالتعديل هنا
     *    **لازم يمسّ الصفّين مع بعض جوّه معاملة واحدة** — تعديل سجل المراجعة
     *    لوحده بيدّي «الصندوق يقول رقم والإذن يقول رقم تاني» بلا أي خطأ ظاهر.
     *
     * الحُرّاس (كلها في الموديل — الشاشة مش مصدر ثقة):
     *   ① البند لازم يكون `approved` **وله صفّه الفعلي لسه موجود**. لو الصف
     *     اتمسح من الإذن قبل كده، السجل بيفضل **شاهدًا على الاعتماد** وما
     *     بيتعدّلش (نفس قاعدة `delete()` بالحرف).
     *   ② **اليومان مع بعض**: البند بيخرج من رصيد يومه القديم ويدخل رصيد يومه
     *     الجديد — فالاتنين لازم يكونوا مفتوحين، وإلا التعديل بيغيّر رصيدًا
     *     مثبَّتًا في الخفا. والفيزا مستثناة بنفس شرط `approve()` بالحرف
     *     (بتتخصم من الكارت مش من الخزنة).
     *   ③ **ممنوع التاريخ الجديد يبقى قبل خط الفصل** — `epochSql` بتستثني اللي
     *     قبله من كل حسابات العهدة، فنقل بند معتمد لهناك بيخفيه من رصيد
     *     المندوب بلا أي رسالة.
     *   ④ الإيصال المربوط ببيان سائق سيارة المكتب ما ينفعش مبلغه ينزل تحت
     *     الجزء المربوط (نفس حارس `PermitController::saveStage`).
     *
     * ⚠️ **اللي ما بيتغيّرش هنا خالص:** المندوب صاحب العهدة (`paid_by_rep_id`)،
     *    والإذن، وصورة الإيصال، وحالة البند. تغيير المندوب = نقل فلوس من محفظة
     *    لمحفظة، وده قرار تاني له مساره (`rep_custody_transfers`).
     */
    public static function updateApproved(int $subId, array $d): void
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $s = self::lockSubmission($subId);
            if (!$s) { throw new RuntimeException('البند مش موجود.'); }
            if ($s['status'] !== 'approved') {
                throw new RuntimeException($s['status'] === 'pending'
                    ? 'البند ده لسه مستني اعتمادك — عدّله من تبويب «مستني».'
                    : 'البند ده مرفوض — مالوش بند فعلي يتعدّل.');
            }

            $stageId = $s['stage_id'] !== null ? (int) $s['stage_id'] : 0;
            $miscId  = $s['misc_id']  !== null ? (int) $s['misc_id']  : 0;
            if ($stageId === 0 && $miscId === 0) {
                throw new RuntimeException(
                    'البند الفعلي اتمسح من الإذن قبل كده — السجل ده شاهد على إن الاعتماد حصل وما بيتعدّلش.');
            }

            // صف المندوب هو mutex كل مصادر عهدته (اعتماد/تنزيل/تسوية/تعديل).
            Database::row('SELECT id FROM reps WHERE id=? FOR UPDATE', [(int) $s['rep_id']]);

            $before = $stageId
                ? Database::row('SELECT * FROM permit_stages WHERE id=? FOR UPDATE', [$stageId])
                : Database::row('SELECT * FROM rep_misc_expenses WHERE id=? FOR UPDATE', [$miscId]);
            if (!$before) {
                throw new RuntimeException(
                    'البند الفعلي مش موجود في الإذن/المصروفات — يمكن اتمسح. السجل بيفضل شاهدًا على الاعتماد.');
            }

            $v = self::validated($s, $d);

            /* ② اليوم القديم واليوم الجديد. بنقرا اليوم القديم من **الصف
               الفعلي** مش من سجل المراجعة: هو اللي بيدخل الخزنة فعلاً، ولو
               الاتنين افترقوا لأي سبب فالحقيقة عنده. */
            $oldDate = (string) ($stageId ? $before['entry_date'] : $before['exp_date']);
            if (empty($before['is_visa']) && ($err = Closing::guard($oldDate))) {
                throw new RuntimeException('البند مسجَّل في يوم متقفل (' . dfmt($oldDate) . ') — ' . $err);
            }
            if (!$v['is_visa'] && ($err = Closing::guard($v['date']))) {
                throw new RuntimeException($err);
            }

            /* ③ خط فصل العهد — النقل لما قبله بيخفي البند من رصيد المندوب.
               ⚠️ الشرط على **النقل** مش على الحالة: البند اللي أصلاً قبل الخط
                  (اتعتمد قبل ما الخط يتحطّ) لازم يفضل قابلًا للتصحيح، وإلا
                  الحارس بيقفل تعديل بنود قديمة بلا أي فايدة. */
            if (self::isPreEpoch($v['date']) && !self::isPreEpoch($oldDate)) {
                throw new RuntimeException('التاريخ الجديد قبل بداية النظام الجديد ('
                    . dfmt(RepCustody::epoch()) . ') — البند هيختفي من عهدة المندوب. اختر تاريخًا بعده.');
            }

            // ④ الإيصال المربوط ببيان سائق سيارة المكتب
            if ($stageId) {
                $allocated = OfficeDriverStatement::stageAllocationTotal($stageId);
                if ($v['amount'] + 0.005 < $allocated) {
                    throw new RuntimeException('المبلغ الجديد أقل من الجزء (' . money0($allocated)
                        . ') المربوط ببيان سائق سيارة المكتب. فكّ الربط من بيان السائق الأول.');
                }
            }

            self::writeSubmission($subId, 'approved', $v);

            if ($stageId) {
                /* نفس اشتقاق `approve()` بالحرف — مصدر واحد للسؤال «البند ده
                   بيتكتب على أنهي مرحلة وباتجاه إيه؟». */
                BankAccount::withCardSpendChanges([[
                    'old_card' => !empty($before['is_visa']) ? $before['visa_card_id'] : null,
                    'old_amount' => !empty($before['is_visa']) ? $before['amount'] : 0,
                    'new_card' => $v['is_visa'] ? $v['visa_card_id'] : null,
                    'new_amount' => $v['is_visa'] ? $v['amount'] : 0,
                ]], static function () use ($v, $stageId): void {
                    Database::exec(
                        "UPDATE permit_stages
                            SET stage_key=?, direction=?, movement_id=?, amount=?, qty=?, actual_amount=?,
                                entry_date=?, ref_no=?, note=?, is_visa=?, visa_card_id=?, unpaid=?
                          WHERE id=?",
                        [
                            $v['kind'] === 'receipt' ? 'receipts' : 'custom',
                            $v['kind'] === 'expense' ? 'expense' : null,
                            $v['movement_id'], $v['amount'], $v['qty'],
                            $v['actual'] === null ? null : round($v['actual'] * $v['qty'], 2),
                            $v['date'], $v['ref'], $v['note'], $v['is_visa'] ? 1 : 0,
                            $v['visa_card_id'], $v['unpaid'] ? 1 : 0, $stageId,
                        ]);
                });

                /* v109 — التفصيل بيتحدّث مع البند في نفس المعاملة. لو ما
                   اتحدّثش هنا، بند اتغيّر إجماليه بيفضل شايل سطورًا مجموعها
                   الإجمالي القديم — وكارت العربية يقول رقم والإذن يقول رقم
                   تاني بلا أي خطأ ظاهر. */
                // ⚠️ القراءة **بعد** `writeSubmission` عن قصد: هي اللي كتبت
                //    الأسعار الجديدة، فالسطور بتتولّد من آخر قيمة لا من القديمة.
                //    والاستعلام نفسه مشروط بجاهزية العمود — على قاعدة قبل v109
                //    كان `lines_json` هيرمي خطأ SQL ويفشّل تعديلًا سليمًا.
                if (ReceiptLine::ready()) {
                    self::syncStageLines($stageId, Database::row(
                        'SELECT amount, qty, lines_json FROM rep_submissions WHERE id=?', [$subId]) ?? []);
                }

                $after = Database::row('SELECT * FROM permit_stages WHERE id=?', [$stageId]);
                Audit::log('update', 'permit_stages', $stageId, $before, $after,
                    'تعديل بند مندوب **بعد اعتماده** «' . $v['note'] . '» — '
                    . number_format($v['amount'], 2, '.', ',')
                    . ' — ' . Permit::noLabel($s['permit_type'] ?? 'sader')
                    . ' ' . ($s['permit_no'] ?: '#' . $s['permit_id'])
                    . ' — من عهدة ' . $s['rep_name'],
                    [
                        'permit_id'     => (int) $s['permit_id'],
                        'permit_no'     => $s['permit_no'],
                        'movement_id'   => $v['movement_id'],
                        'record_date'   => $v['date'],
                        'rep_id'        => (int) $s['rep_id'],
                        'submission_id' => $subId,
                    ]);
                Lookup::add($v['kind'] === 'receipt' ? 'mv_receipt' : 'mv_expense', $v['note']);
            } else {
                /* ⚠️ التطبيع (الوسم → التصنيف، والسلفة بتلغي الفيزا) عايش في
                   `RepMiscExpense` نفسه — مش متكرر هنا. */
                RepMiscExpense::update($miscId, [
                    'exp_date'     => $v['date'],
                    'note'         => $v['note'],
                    'qty'          => $v['qty'],
                    'unit_amount'  => $v['unit'],
                    'ref_no'       => $v['ref'],
                    'is_visa'      => $v['is_visa'],
                    'visa_card_id' => $v['visa_card_id'],
                    'misc_kind'    => $v['misc_kind'],
                ]);
                $after = RepMiscExpense::find($miscId);
                Audit::log('update', 'rep_misc_expenses', $miscId, $before, $after,
                    'تعديل نثرية مندوب **بعد اعتمادها** «' . $v['note'] . '» — '
                    . number_format($v['amount'], 2, '.', ',') . ' — من عهدة ' . $s['rep_name'],
                    [
                        'record_date'   => $v['date'],
                        'rep_id'        => (int) $s['rep_id'],
                        'submission_id' => $subId,
                    ]);
                Lookup::add('mv_misc', $v['note']);
            }

            Audit::log('update', 'rep_submissions', $subId, $s, self::find($subId),
                'تعديل سجل بند مندوب معتمد «' . $v['note'] . '» — '
                . number_format($v['amount'], 2, '.', ','),
                ['rep_id' => (int) $s['rep_id'], 'record_date' => $v['date']]);

            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * تعديل جماعي — بيغيّر حقل واحد أو أكتر على كل البنود المعلَّمة مرة واحدة.
     * مفيد لتصحيح التاريخ لدفعة كاملة، أو تحويلها كلها لمصروف.
     * المسموح: entry_date, kind, movement_id (على مستوى الإذن)، actual_unit.
     * كلها أو ولا واحدة (transaction).
     */
    public static function bulkUpdate(array $ids, array $d): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) { throw new RuntimeException('اختر بندًا واحدًا على الأقل.'); }
        if (count($ids) > 200) { throw new RuntimeException('الحد الأقصى 200 بند في المرة.'); }

        // الحقول الفاضية تتشال — «سيبه زي ما هو»
        $fields = [];
        foreach (['entry_date', 'kind', 'actual_unit'] as $k) {
            if (array_key_exists($k, $d) && trim((string) $d[$k]) !== '') { $fields[$k] = $d[$k]; }
        }
        if (!$fields) { throw new RuntimeException('اختر حاجة واحدة على الأقل تعدّلها.'); }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $n = 0;
            foreach ($ids as $id) { self::update($id, $fields); $n++; }
            if ($own) { $pdo->commit(); }
            return $n;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** رفض جماعي بنفس السبب — كلها أو ولا واحدة. */
    public static function rejectMany(array $ids, string $reason): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        if (!$ids) { throw new RuntimeException('اختر بندًا واحدًا على الأقل.'); }
        if (count($ids) > 200) { throw new RuntimeException('الحد الأقصى 200 بند في المرة.'); }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $n = 0;
            foreach ($ids as $id) { self::reject($id, $reason); $n++; }
            if ($own) { $pdo->commit(); }
            return $n;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** حركات الإذن (لقائمة التعديل). */
    public static function movementsOf(int $permitId): array
    {
        return Database::all(
            "SELECT id, seq, note, container_count, container_type
             FROM permit_movements WHERE permit_id = ? ORDER BY seq, id", [$permitId]);
    }

    private static function validDate(string $v): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return false; }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y);
    }

    // ─────────────────────────────────────────── الاعتماد

    /**
     * اعتماد بند: بيولّد صف permit_stages حقيقي ويربطه بالبند.
     * بيرجع id الصف المُنشأ. بيرمي استثناء لو البند مش pending أو الإذن اتمسح.
     */
    /**
     * ⚠️ منع اعتماد بند تاريخه **قبل بداية النظام الجديد** (`rep_custody_epoch`).
     *
     * **ليه:** البنود العالقة من قبل خط الفصل كلها اتّضح إنها **عهد سواقين**
     * اتسجّلت غلط كإيصال/مصروف (متحقَّق على الإنتاج: ٦ بنود = 81,000، كلها
     * «عهده عربيه»/«عهد عربيه»). عهدة السائق دي **متخصومة أصلاً من خزنة المكتب
     * على بوليصتها**، فاعتمادها هنا = **خصم نفس الفلوس مرتين**. وفوق كده تاريخها
     * في يوم متقفل، فبتولّد بندًا جوّه لقطة مجمّدة.
     *
     * **القرار (المالك): البنود ما تتلمسش إطلاقًا** — لا تتمسح ولا تتعدّل. الحماية
     * من **منع الاعتماد** مش من الحذف؛ فبتفضل في السجل بحالتها للتوثيق.
     *
     * ⚠️ الحارس في **الموديل** مش في الشاشة — إخفاء الزرار مش حماية، والفورم مش
     *    مصدر ثقة. وبيرجّع null لو الميزة مطفية ⇒ السلوك القديم بالحرف.
     */
    private static function epochBlock(array $s): ?string
    {
        $epoch = RepCustody::epoch();
        $date  = (string) ($s['entry_date'] ?? '');
        if ($epoch === '' || $date === '' || $date >= $epoch) { return null; }
        return 'البند ده تاريخه ' . dfmt($date) . ' — يعني من قبل بداية النظام الجديد ('
             . dfmt($epoch) . ') فمش هينفع يتعتمد. البنود دي عهد سواقين متخصومة '
             . 'أصلاً على بوالصها من خزنة المكتب، واعتمادها هيخصم نفس الفلوس مرتين. '
             . 'سيبه زي ما هو للتوثيق، أو ارفضه.';
    }

    public static function approve(int $subId): int
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            // القفل يسبق فحص status وأي INSERT: طلب اعتماد ثانٍ ينتظر ثم يرى approved.
            $s = self::lockSubmission($subId);
            if (!$s) { throw new RuntimeException('البند مش موجود.'); }
            if ($s['status'] !== 'pending') { throw new RuntimeException('البند اتراجع قبل كده.'); }
            if (($err = self::epochBlock($s)) !== null) { throw new RuntimeException($err); }
            Database::exec(
                "UPDATE rep_submissions SET review_started_at=COALESCE(review_started_at,NOW()),
                        review_started_by=COALESCE(review_started_by,?) WHERE id=? AND status='pending'",
                [Auth::user(), $subId]
            );
            // صف المندوب هو mutex لكل مصادر عهدته (اعتماد/تنزيل/تسوية/حذف).
            Database::row('SELECT id FROM reps WHERE id=? FOR UPDATE', [(int) $s['rep_id']]);

            // v42: البند بيتخصم من الخزنة بتاريخ صرفه، فممنوع يدخل على يوم متقفل.
            // الفحص جوّه القفل عشان ما يحصلش سباق بين الاعتماد وإقفال اليوم.
            //
            // ⚠️ v45: **البند المدفوع بالفيزا مستثنى** — بيتخصم من رصيد الكارت
            //    وبس، وكل حسابات الخزنة بتفلتر `is_visa = 0` أصلاً (نمط v22).
            //    فاعتماده على يوم متقفل ما بيغيّرش أي رصيد مثبَّت، وحظره كان
            //    هيمنع المالك من تصفية صندوقه بلا أي فايدة.
            if (empty($s['is_visa']) && ($err = Closing::guard($s['entry_date']))) {
                throw new RuntimeException($err);
            }

            // النثرية (v39) مالهاش إذن أصلاً — مسار اعتماد مستقل داخل نفس القفل.
            if (RepPortal::isMisc((string) $s['kind'])) {
                $miscId = self::approveMisc($s);
                if ($own) { $pdo->commit(); }
                return $miscId;
            }

            if (empty($s['permit_no']) && !Database::scalar('SELECT COUNT(*) FROM permits WHERE id=?', [(int) $s['permit_id']])) {
                throw new RuntimeException('الإذن المرتبط بالبند اتمسح — ارفض البند.');
            }

            $stageKey  = $s['kind'] === 'receipt' ? 'receipts' : 'custom';
            $direction = $s['kind'] === 'expense' ? 'expense' : null;

            // السعر الفعلي للعميل: لو المالك حطّه وقت المراجعة (v37) يتنقل هنا
            // كإجمالي (سعر الوحدة × الكمية — قاعدة v17). فاضي = يفضل NULL
            // ويتحطّ بعدين من /expenses/receipts زي المعتاد.
            $actualTotal = ($s['actual_unit'] === null || $s['actual_unit'] === '')
                ? null : round((float) $s['actual_unit'] * (int) $s['qty'], 2);

            BankAccount::withCardSpendChanges([[
                'new_card' => !empty($s['is_visa']) ? ($s['visa_card_id'] ?? null) : null,
                'new_amount' => !empty($s['is_visa']) ? (float) $s['amount'] : 0,
            ]], static function () use ($s, $stageKey, $direction, $actualTotal): void {
                Database::exec(
                    "INSERT INTO permit_stages
                        (permit_id, movement_id, stage_key, direction, amount, qty, actual_amount,
                         entry_date, ref_no, note, status, attachment, is_visa, visa_card_id,
                         unpaid, paid_by_rep_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?, 'done', ?, ?, ?, ?, ?)",
                    [
                        (int) $s['permit_id'], $s['movement_id'] ? (int) $s['movement_id'] : null,
                        $stageKey, $direction, round((float) $s['amount'], 2), (int) $s['qty'], $actualTotal,
                        $s['entry_date'], $s['ref_no'], $s['note'], $s['photo'],
                        (int) ($s['is_visa'] ?? 0),
                        !empty($s['is_visa']) && !empty($s['visa_card_id']) ? (int) $s['visa_card_id'] : null,
                        (int) ($s['unpaid'] ?? 0), (int) $s['rep_id'],
                    ]);
            });
            $stageId = Database::lastId();

            // v109 — الأسعار المفصّلة بتتحوّل لسطور حقيقية دلوقتي (وقبل كده
            // كانت لسه JSON في صندوق الانتظار). دي أول لحظة يبقى فيها للبند id.
            self::syncStageLines($stageId, $s);

            $changed = Database::exec(
                "UPDATE rep_submissions
                 SET status='approved', reviewed_by=?, reviewed_at=NOW(), stage_id=?, review_note=NULL
                 WHERE id=? AND status='pending' AND stage_id IS NULL AND misc_id IS NULL",
                [Auth::user(), $stageId, $subId]);
            if ($changed !== 1) { throw new RuntimeException('سبق اعتماد البند من طلب آخر؛ لم يُنشأ تكرار.'); }

            Lookup::add($s['kind'] === 'receipt' ? 'mv_receipt' : 'mv_expense', $s['note']);

            $saved = Database::row('SELECT * FROM permit_stages WHERE id=?', [$stageId]);
            Audit::log('create', 'permit_stages', $stageId, null, $saved,
                'اعتماد بند مندوب: ' . RepPortal::KINDS[$s['kind']] . ' «' . $s['note'] . '» بقيمة '
                . number_format((float) $s['amount'], 2, '.', ',')
                . self::qtyLabel($s)
                . ' — ' . Permit::noLabel($s['permit_type'] ?? 'sader') . ' ' . ($s['permit_no'] ?: '#' . $s['permit_id'])
                . ' — مدفوع من عهدة ' . $s['rep_name'],
                [
                    'permit_id'   => (int) $s['permit_id'],
                    'permit_no'   => $s['permit_no'],
                    'movement_id' => $s['movement_id'],
                    'record_date' => $s['entry_date'],
                    'rep_id'      => (int) $s['rep_id'],
                    'submission_id' => $subId,
                ]);

            if ($own) { $pdo->commit(); }
            return $stageId;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * اعتماد نثرية (v39) — بتتحوّل لصف في `rep_misc_expenses` مش `permit_stages`.
     *
     * ⚠️ الفرق الجوهري عن البند العادي: **مفيش إذن ومفيش عميل**. فهي:
     *   • بتظهر في سجل المصروفات `/expenses` (مصدر «نثريات المناديب»).
     *   • بتتخصم من عهدة المندوب (هو دفعها من فلوسه النازلة).
     *   • **عمرها ما تدخل فاتورة أي عميل** — مفيش `actual_amount` فيها أصلاً.
     *   • **بتتخصم من الخزنة يوم صرفها** (v42) زي أي مصروف عام، وبتظهر في الصف
     *     العام في مطابقة التقفيل (مالهاش عميل تتحط عليه).
     *
     * الحارس على اليوم المتقفل بيتنادى في approve() قبل ما نوصل هنا.
     * بترجع id صف النثرية.
     */
    private static function approveMisc(array $s): int
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $miscId = RepMiscExpense::create([
                'rep_id'        => (int) $s['rep_id'],
                'submission_id' => (int) $s['id'],
                'exp_date'      => $s['entry_date'],   // يوم الصرف الفعلي (قاعدة v29)
                'note'          => $s['note'],
                'qty'           => (int) $s['qty'],
                'unit_amount'   => (float) $s['unit_amount'],
                'ref_no'        => $s['ref_no'],
                'attachment'    => $s['photo'],
                // v45: النثرية ممكن تكون مدفوعة بكارت المكتب — ساعتها بتتخصم من
                // الكارت وبس، وبتتستثنى من الخزنة ومن عهدة المندوب.
                'is_visa'       => (int) ($s['is_visa'] ?? 0),
                'visa_card_id'  => !empty($s['is_visa']) && !empty($s['visa_card_id'])
                                     ? (int) $s['visa_card_id'] : null,
                /* v71 — وسم النثرية بيتنقل زي ما هو من اختيار المندوب.
                   `RepMiscExpense::create` هي اللي بتطبّعه وبتشتقّ منه
                   `category` — فمفيش تسمية بتتكتب هنا بالإيد. */
                'misc_kind'     => $s['misc_kind'] ?? null,
            ]);

            $changed = Database::exec(
                "UPDATE rep_submissions
                 SET status='approved', reviewed_by=?, reviewed_at=NOW(), misc_id=?, review_note=NULL
                 WHERE id=? AND status='pending' AND stage_id IS NULL AND misc_id IS NULL",
                [Auth::user(), $miscId, (int) $s['id']]);
            if ($changed !== 1) { throw new RuntimeException('سبق اعتماد النثرية من طلب آخر؛ لم يُنشأ تكرار.'); }

            Lookup::add('mv_misc', $s['note']);

            $saved = RepMiscExpense::find($miscId);
            Audit::log('create', 'rep_misc_expenses', $miscId, null, $saved,
                'اعتماد نثرية مندوب «' . $s['note'] . '» بقيمة '
                . number_format((float) $s['amount'], 2, '.', ',')
                . ((int) $s['qty'] > 1 ? ' (' . (int) $s['qty'] . ' × ' . number_format((float) $s['unit_amount'], 2, '.', ',') . ')' : '')
                . ' — مصروف عام بلا إذن، مدفوع من عهدة ' . $s['rep_name'],
                [
                    'record_date'   => $s['entry_date'],
                    'rep_id'        => (int) $s['rep_id'],
                    'submission_id' => (int) $s['id'],
                ]);

            if ($own) { $pdo->commit(); }
            return $miscId;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** اعتماد دفعة — كلها أو ولا واحدة. بيرجع عدد اللي اتعمد. */
    public static function approveMany(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        if (!$ids) { throw new RuntimeException('اختر بندًا واحدًا على الأقل.'); }
        if (count($ids) > 200) { throw new RuntimeException('الحد الأقصى 200 بند في المرة.'); }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $n = 0;
            foreach ($ids as $id) { self::approve($id); $n++; }
            if ($own) { $pdo->commit(); }
            return $n;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    // ─────────────────────────────────────────── الرفض

    /** رفض بند بسبب — **بلا أي أثر مالي**. المندوب بيشوف السبب على موبايله. */
    public static function reject(int $subId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') { throw new RuntimeException('اكتب سبب الرفض — المندوب هيشوفه.'); }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $s = self::lockSubmission($subId);
            if (!$s) { throw new RuntimeException('البند مش موجود.'); }
            if ($s['status'] !== 'pending') {
                throw new RuntimeException($s['status'] === 'approved'
                    ? 'البند اتعمد خلاص ولا يمكن حذفه؛ راجع سجل الاعتماد.' : 'البند اتراجع قبل كده.');
            }
            $changed = Database::exec(
                "UPDATE rep_submissions SET status='rejected', review_note=?, reviewed_by=?, reviewed_at=NOW(),
                        review_started_at=COALESCE(review_started_at,NOW()), review_started_by=COALESCE(review_started_by,?)
                 WHERE id=? AND status='pending'",
                [mb_substr($reason, 0, 255), Auth::user(), Auth::user(), $subId]);
            if ($changed !== 1) { throw new RuntimeException('سبق اتخاذ قرار في البند من طلب آخر.'); }

            Audit::log('update', 'rep_submissions', $subId, $s, self::find($subId),
                'رفض بند مندوب «' . $s['note'] . '» (' . number_format((float) $s['amount'], 2, '.', ',') . ') — ' . $reason,
                ['rep_id' => (int) $s['rep_id'], 'record_date' => $s['entry_date']]);
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * **إلغاء اعتماد بند** (v95) — بيشيل الأثر المالي ويرجّع البند «مستني».
     *
     * ⚠️ **ده الميكانيكا اللي كل تحكّم بعد الاعتماد بيقف عليها:** البند الفعلي
     *    (`permit_stages`/`rep_misc_expenses`) بيتمسح فبيرجع المبلغ للخزنة
     *    ولعهدة المندوب وبيخرج من فاتورة العميل، والسجل بيرجع للصندوق فيقدر
     *    يتعدّل أو يترفض أو يتحذف بمساره العادي.
     *
     * ⚠️ **وده كمان اللي بيخلّي `deleteApproved` تحترم قاعدة السلة:** السلة
     *    (`Audit::RESTORABLE`) بتفترض إن سجل `rep_submissions` اللي فيها
     *    **مستني أو مرفوض** — يعني بلا بند فعلي. فالحذف بيمرّ من هنا الأول،
     *    وبعدين `delete()` العادية بتشتغل على بند مستني زي أي بند.
     *
     * ⚠️ **الصورة ما بتتمسحش من القرص** — نفس الملف مربوط بـ`rep_submissions.photo`
     *    (الاعتماد بينسخ المسار مش الملف)، ومسحه كان هيسيب البند بلا صورته.
     *
     * الحُرّاس: اليوم المتقفل (بلا فيزا) · الإيصال المربوط ببيان سائق المكتب ·
     * البند اللي بنده الفعلي اتمسح خلاص (السجل شاهد وما بيتلمسش).
     */
    public static function unapprove(int $subId): void
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $s = self::lockSubmission($subId);
            if (!$s) { throw new RuntimeException('البند مش موجود.'); }
            if ($s['status'] !== 'approved') {
                throw new RuntimeException('البند ده مش معتمد أصلاً.');
            }
            $stageId = $s['stage_id'] !== null ? (int) $s['stage_id'] : 0;
            $miscId  = $s['misc_id']  !== null ? (int) $s['misc_id']  : 0;
            if ($stageId === 0 && $miscId === 0) {
                throw new RuntimeException(
                    'البند الفعلي اتمسح من الإذن قبل كده — السجل ده شاهد على إن الاعتماد حصل وما بيتلمسش.');
            }

            Database::row('SELECT id FROM reps WHERE id=? FOR UPDATE', [(int) $s['rep_id']]);

            $before = $stageId
                ? Database::row('SELECT * FROM permit_stages WHERE id=? FOR UPDATE', [$stageId])
                : Database::row('SELECT * FROM rep_misc_expenses WHERE id=? FOR UPDATE', [$miscId]);
            if (!$before) {
                throw new RuntimeException('البند الفعلي مش موجود — السجل بيفضل شاهدًا على الاعتماد.');
            }

            $day = (string) ($stageId ? $before['entry_date'] : $before['exp_date']);
            if (empty($before['is_visa']) && ($err = Closing::guard($day))) {
                throw new RuntimeException('البند مسجَّل في يوم متقفل (' . dfmt($day) . ') — ' . $err);
            }
            if ($stageId && OfficeDriverStatement::stageAllocationTotal($stageId) > 0.005) {
                throw new RuntimeException(
                    'الإيصال مربوط ببيان سائق سيارة المكتب. فكّ الربط من بيان السائق الأول.');
            }

            $label = ' «' . $s['note'] . '» بقيمة ' . number_format((float) $s['amount'], 2, '.', ',')
                   . ' — من عهدة ' . $s['rep_name'];

            /* ⚠️⚠️ **فكّ الربط قبل الحذف — مش بعده.** `rep_submissions.stage_id`
               عليه مفتاح أجنبي بـ`ON DELETE RESTRICT`، فحذف صف `permit_stages`
               والسجل لسه بيشاور عليه بيرمي 1451 وبيرجّع المعاملة كلها.
               (مسكها `verify_v95` — الترتيب العكسي كان بيفشل بالكامل.)
               و`stage_id`/`misc_id` **لازم يرجعوا NULL** لأنهم شرط `approve()`
               و`delete()` مع بعض: لو فضلوا مليانين، البند يرجع «مستني» وهو
               «معتمد» في نظر الحُرّاس فلا بيتعتمد ولا بيتحذف. */
            $changed = Database::exec(
                "UPDATE rep_submissions
                    SET status='pending', stage_id=NULL, misc_id=NULL,
                        reviewed_by=NULL, reviewed_at=NULL, review_note=NULL,
                        review_started_at=NULL, review_started_by=NULL
                  WHERE id=? AND status='approved'",
                [$subId]);
            if ($changed !== 1) { throw new RuntimeException('اتغيّرت حالة البند من طلب تاني — حدّث الصفحة.'); }

            BankAccount::withCardSpendChanges([[
                'old_card'=>!empty($before['is_visa']) ? $before['visa_card_id'] : null,
                'old_amount'=>!empty($before['is_visa']) ? $before['amount'] : 0,
            ]], static function () use ($stageId, $miscId, $before, $label, $s, $day, $subId): void {
                if ($stageId) {
                    Database::exec('DELETE FROM permit_stages WHERE id=?', [$stageId]);
                    Audit::log('delete', 'permit_stages', $stageId, $before, null,
                        'إلغاء اعتماد بند مندوب' . $label . ' — ' . Permit::noLabel($s['permit_type'] ?? 'sader')
                        . ' ' . ($s['permit_no'] ?: '#' . $s['permit_id']),
                        ['permit_id' => (int) $s['permit_id'], 'permit_no' => $s['permit_no'],
                         'record_date' => $day, 'rep_id' => (int) $s['rep_id'], 'submission_id' => $subId]);
                } else {
                    Database::exec('DELETE FROM rep_misc_expenses WHERE id=?', [$miscId]);
                    Audit::log('delete', 'rep_misc_expenses', $miscId, $before, null,
                        'إلغاء اعتماد نثرية مندوب' . $label,
                        ['record_date' => $day, 'rep_id' => (int) $s['rep_id'], 'submission_id' => $subId]);
                }
            });

            Audit::log('update', 'rep_submissions', $subId, $s, self::find($subId),
                'إلغاء اعتماد بند مندوب' . $label . ' — رجع لصندوق المراجعة',
                ['rep_id' => (int) $s['rep_id'], 'record_date' => $day]);

            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * حذف بند **معتمد** نهائيًّا (v95) — خطوتان في معاملة واحدة:
     * `unapprove()` بتشيل الأثر المالي، وبعدين `delete()` العادية بتنقل السجل
     * لسلة المحذوفات.
     *
     * ⚠️ **الترتيب ده مش تفصيلة**: `delete()` بترفض المعتمد عن قصد، والسلة
     *    بتفترض إن اللي جوّاها بلا بند فعلي. فالمرور من `unapprove()` هو اللي
     *    بيخلّي الحذف **قابلًا للاسترجاع** بدل ما يبقى محوًا بلا رجعة — واللي
     *    بيرجع من السلة بيرجع **مستني** فالمالك بيراجعه من أول وجديد.
     *
     * بيرجع صف البند قبل الحذف (الكنترولر محتاجه للإشعار).
     */
    public static function deleteApproved(int $subId): array
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            self::unapprove($subId);
            $gone = self::delete($subId);
            if ($own) { $pdo->commit(); }
            return $gone;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    // ─────────────────────────────────────────── الحذف النهائي

    /**
     * حذف بند من صندوق المراجعة **نهائيًّا** — للبند اللي مالوش لزمة خالص
     * (اتبعت غلط، اتكرّر، تجربة من المندوب). بديل الرفض اللي بيسيب سجلًّا
     * بيفضل في الشاشة والكشوف.
     *
     * ⚠️ **مفيش أي أثر مالي — وده مش صدفة:** البند المستني/المرفوض عمره ما
     *    اتخصم من خزنة ولا من عهدة مندوب (الخصم بيحصل وقت الاعتماد بس، لما
     *    بيتولّد `permit_stages`/`rep_misc_expenses`). فالحذف ماليًّا **زي
     *    الرفض بالظبط**: البند بيخرج من «مستني» فبيرجع للمندوب في «فاضل معاه».
     *    عشان كده **مفيش `Closing::guard`** هنا — مفيش رصيد مثبَّت بيتغيّر.
     *
     * ⚠️ **البند المعتمد بيترفض** — له بند فعلي على الإذن (أو نثرية) داخل
     *    الفاتورة والمصروفات والخزنة. حذفه من هنا كان هيسيب البند الفعلي
     *    يتيم بلا سجل اعتماد. المسار الصح: امسح البند من تايم لاين الإذن،
     *    و`DeletionPolicy::detachStage` بتفكّ الربط وتسيب السجل بحالته.
     *
     * الصف بيتنقل **لسلة المحذوفات** (`Audit::trash`) قبل ما يتشال، فالمالك
     * يقدر يسترجعه من `/audit`. صورة الإيصال بتفضل على القرص عشان الاسترجاع
     * يرجّع البند بصورته.
     *
     * بيرجع صف البند قبل الحذف (الكنترولر محتاجه للإشعار).
     */
    public static function delete(int $subId): array
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $s = self::lockSubmission($subId);
            if (!$s) { throw new RuntimeException('البند مش موجود.'); }
            $linked = $s['stage_id'] !== null || $s['misc_id'] !== null;
            if ($s['status'] === 'approved' || $linked) {
                throw new RuntimeException($linked
                    ? 'البند ده معتمد وله بند فعلي على الإذن/المصروفات — ما ينفعش يتحذف من هنا. '
                      . 'امسح البند من تايم لاين الإذن، وسجل الاعتماد هيتفكّ لوحده.'
                    // الحالة دي بتحصل لما البند الفعلي اتمسح من الإذن قبل كده
                    // (DeletionPolicy::detachStage/detachMisc بتفكّ الربط وتسيب
                    // السجل شاهدًا على إن الاعتماد حصل). محو الشاهد ده ممنوع.
                    : 'البند ده اتعمد قبل كده وبنده الفعلي اتمسح من الإذن — السجل بيفضل شاهدًا على الاعتماد وما بيتحذفش.');
            }

            $label = trim((string) $s['note']) . ' — ' . number_format((float) $s['amount'], 2, '.', ',')
                   . ' ج · ' . $s['rep_name'] . ' · ' . $s['entry_date'];
            Audit::trash('rep_submissions', $s, mb_substr($label, 0, 255));

            $changed = Database::exec(
                "DELETE FROM rep_submissions
                 WHERE id = ? AND status <> 'approved' AND stage_id IS NULL AND misc_id IS NULL",
                [$subId]);
            if ($changed !== 1) { throw new RuntimeException('اتغيّرت حالة البند من طلب تاني — حدّث الصفحة.'); }

            if ($own) { $pdo->commit(); }
            return $s;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** حذف دفعة — كلها أو ولا واحدة. بيرجع عدد اللي اتحذف. */
    public static function deleteMany(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        if (!$ids) { throw new RuntimeException('اختر بندًا واحدًا على الأقل.'); }
        if (count($ids) > 200) { throw new RuntimeException('الحد الأقصى 200 بند في المرة.'); }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $n = 0;
            foreach ($ids as $id) { self::delete($id); $n++; }
            if ($own) { $pdo->commit(); }
            return $n;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** يقفل صف التقديم أولًا ثم يعيد نسخة العرض الكاملة تحت القفل نفسه. */
    private static function lockSubmission(int $subId): ?array
    {
        $locked = Database::row('SELECT id FROM rep_submissions WHERE id=? FOR UPDATE', [$subId]);
        return $locked ? self::find($subId) : null;
    }
}
