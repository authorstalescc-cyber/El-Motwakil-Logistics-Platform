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
            throw new RuntimeException('البند اتراجع خلاص — عدّله من تايم لاين الإذن.');
        }

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

        $qty = array_key_exists('qty', $d) ? (int) $d['qty'] : (int) $s['qty'];
        if ($qty < 1 || $qty > 10000) { throw new RuntimeException('الكمية لازم تكون من 1 لـ10000.'); }

        $unit = array_key_exists('unit_amount', $d)
            ? round((float) $d['unit_amount'], 2) : round((float) $s['unit_amount'], 2);
        if ($unit <= 0) { throw new RuntimeException('المبلغ لازم يكون أكبر من صفر.'); }

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
        $isVisa = array_key_exists('is_visa', $d) ? !empty($d['is_visa']) : !empty($s['is_visa']);
        $cardId = array_key_exists('visa_card_id', $d)
            ? ((int) $d['visa_card_id'] ?: null)
            : (!empty($s['visa_card_id']) ? (int) $s['visa_card_id'] : null);
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

        Database::exec(
            "UPDATE rep_submissions
             SET note=?, kind=?, qty=?, unit_amount=?, actual_unit=?, amount=?,
                 entry_date=?, ref_no=?, movement_id=?, is_visa=?, visa_card_id=?, unpaid=?,
                 edited_by=?, edited_at=NOW()
             WHERE id=? AND status='pending'",
            [$note, $kind, $qty, $unit, $actual, round($unit * $qty, 2),
             $date, $ref !== '' ? $ref : null, $movementId, $isVisa ? 1 : 0, $cardId,
             $unpaid ? 1 : 0, Auth::user(), $subId]);

        Audit::log('update', 'rep_submissions', $subId, $s, self::find($subId),
            'تعديل بند مندوب قبل الاعتماد «' . $note . '» — ' . number_format($unit * $qty, 2, '.', ','),
            ['rep_id' => (int) $s['rep_id'], 'record_date' => $date]);
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

            Database::exec(
                "INSERT INTO permit_stages
                    (permit_id, movement_id, stage_key, direction, amount, qty, actual_amount,
                     entry_date, ref_no, note, status, attachment, is_visa, visa_card_id,
                     unpaid, paid_by_rep_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?, 'done', ?, ?, ?, ?, ?)",
                [
                    (int) $s['permit_id'], $s['movement_id'] ? (int) $s['movement_id'] : null,
                    $stageKey, $direction,
                    round((float) $s['amount'], 2), (int) $s['qty'], $actualTotal,
                    $s['entry_date'], $s['ref_no'], $s['note'],
                    $s['photo'],                    // صورة الإيصال بتروح مرفق على البند
                    // v45: وسم الفيزا بيتنقل زي ما هو. is_visa=1 معناه إن المبلغ
                    // خرج من الكارت — فبيتخصم منه، وبيتستثنى من الخزنة (زي أي بند
                    // فيزا) **ومن عهدة المندوب الكاش** (RepCustody::spentTotal).
                    (int) ($s['is_visa'] ?? 0),
                    !empty($s['is_visa']) && !empty($s['visa_card_id']) ? (int) $s['visa_card_id'] : null,
                    // v57: وسم «غير مدفوع» بيتنقل زي وسم الفيزا بالظبط — البند
                    // بيظهر على الإذن وفي فاتورة العميل، لكن `Permit::cashOutSql`
                    // بتستثنيه من **الخزنة ومن عهدة المندوب** مع بعض.
                    (int) ($s['unpaid'] ?? 0),
                    (int) $s['rep_id'],             // يربط المصروف بعهدة المندوب
                ]);
            $stageId = Database::lastId();

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
                . ((int) $s['qty'] > 1 ? ' (' . (int) $s['qty'] . ' × ' . number_format((float) $s['unit_amount'], 2, '.', ',') . ')' : '')
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
