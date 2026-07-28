<?php
namespace App\Models;

use App\Core\Database;
use App\Models\PartyDoc;
use RuntimeException;

/**
 * بوابة المندوب (v35) — البيانات اللي بتغذّي شاشة الموبايل `/m`.
 *
 * ⚠️ حدود الصلاحية بتتفرض هنا، مش في الواجهة:
 *   • المندوب يشوف **كل الأذونات المفتوحة** (قرار المالك — v43). التنزيل
 *     (permit_assignments) ما بقاش شرط للرؤية؛ بقى ترتيب وشارة «منزّل لي» بس.
 *     الفلتر الوحيد الباقي هو `permits.status='open'` — المقفول بيختفي وكتابته
 *     بترفض، وده الحد الحقيقي (شوف assertAssigned).
 *   • أي كتابة بتعدّي على assertAssigned — مش كفاية إن الفورم بعت id، لأن أي
 *     حد يقدر يزوّر الفورم.
 *   • مفيش أي مبلغ بيخصّ العميل بيتسرّب للشاشة (actual_amount عمره ما بيتقرا هنا).
 *
 * ⚠️ مفيش أي أثر مالي في الجدول ده إطلاقًا. البند بيقعد pending لحد ما المالك
 *    يعتمده، وساعتها بس بيتولّد صف permit_stages حقيقي (شوف RepInbox).
 */
class RepPortal
{
    /**
     * أنواع البند اللي المندوب يقدر يسجّلها.
     *   receipt/expense → على إذن منزّل له (بيدخلوا تايم لاين الإذن).
     *   misc            → **نثرية عامة بلا إذن** (v39) — بتروح للمصروفات وبس،
     *                     عمرها ما تدخل حساب عميل. permit_id بيفضل NULL.
     */
    public const KINDS = ['receipt' => 'إيصال', 'expense' => 'مصروف', 'misc' => 'نثرية'];

    /** الأنواع المربوطة بإذن — أي نوع برّه دي بيتعامل كنثرية عامة. */
    public const PERMIT_KINDS = ['receipt', 'expense'];

    /** هل النوع ده نثرية عامة (بلا إذن)؟ المصدر الوحيد للفحص ده. */
    public static function isMisc(?string $kind): bool
    {
        return $kind === 'misc';
    }

    // ─────────────────────────────────────────── الأذونات المفتوحة

    /** أقصى عدد أذونات بتتحمّل على شاشة الموبايل (بحث الصفحة بيفلترها محليًّا). */
    private const PERMIT_LIMIT = 300;

    /**
     * الأذونات اللي المندوب يقدر يشتغل عليها + حركاتها + وصف حاوياتها.
     *
     * ⚠️ v43 — **كل الأذونات المفتوحة بتظهر لكل المناديب** (قرار المالك):
     * التنزيل مش شرط رؤية ولا شرط كتابة، والمقفول هو الوحيد اللي بيختفي. لو
     * رجّعت شرط التنزيل هنا لازم ترجّعه في `assertAssigned` كمان، وإلا المندوب
     * هيبقى يقدر يكتب على إذن مش شايفه.
     *
     * ⚠️ **2026-07-28 — التنزيل اتشال خالص من شاشة المندوب** (قرار المالك:
     * «مش هننزّل إذن لمندوب معيّن»): مكانش فاضل منه غير **شارة وترتيب**،
     * وده كان بيوهم المندوب إن فيه أذونات «بتاعته» وأذونات «تانية» وهو
     * الحقيقة بيشتغل على أي إذن مفتوح. القايمة بقت **واحدة مرتّبة بالتاريخ**،
     * والاستعلام اتخفّف من استعلامين فرعيين مترابطين على كل صف.
     * الجدول `permit_assignments` **ما اتلمسش** — لسه سجل مكتب في كارت
     * المندوب، بس مالوش أي أثر على اللي بيشوفه المندوب.
     */
    public static function myPermits(int $repId): array
    {
        // خطوة أولى: نحدّد أذونات الشاشة ونرتّبها قبل ما نجيب الحركات، عشان
        // الـLIMIT يبقى على الأذونات مش على صفوف الحركات.
        $picked = Database::all(
            "SELECT p.id, p.created_at
             FROM permits p
             WHERE p.status = 'open'
             ORDER BY p.permit_date DESC, p.id DESC
             LIMIT " . self::PERMIT_LIMIT);
        if (!$picked) { return []; }

        // توقيت الإنشاء — الشاشة بتقارنه بآخر زيارة محفوظة في المتصفح لكشف «الجديد»
        $meta = [];
        foreach ($picked as $r) { $meta[(int) $r['id']] = $r['created_at']; }
        $ids = implode(',', array_map('intval', array_keys($meta)));

        $rows = Database::all(
            "SELECT p.id permit_id, p.permit_type, p.permit_no, p.permit_date, p.company, p.client,
                    p.status, p.containers, p.container_type permit_container_type,
                    p.port, p.agency, p.shipping_co, p.destination,
                    m.id movement_id, m.seq, m.note movement_note, m.container_count, m.container_type
             FROM permits p
             LEFT JOIN permit_movements m ON m.permit_id = p.id
             WHERE p.id IN ($ids)
             ORDER BY p.id, m.seq, m.id");

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row['permit_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = [
                    'id'         => $pid,
                    'type'       => ($row['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader',
                    'type_label' => ($row['permit_type'] ?? 'sader') === 'warid' ? 'شهادة' : 'إذن',
                    'no'         => trim((string) ($row['permit_no'] ?: '#' . $pid)),
                    'company'    => trim((string) ($row['company'] ?: '')),
                    'client'     => trim((string) ($row['client'] ?: '')),
                    'port'       => trim((string) ($row['port'] ?? '')),
                    'agency'     => trim((string) ($row['agency'] ?? '')),
                    'shipping_co'=> trim((string) ($row['shipping_co'] ?? '')),
                    'destination'=> trim((string) ($row['destination'] ?? '')),
                    'date'       => $row['permit_date'],
                    'containers' => (int) ($row['containers'] ?? 0),
                    'container_type' => trim((string) ($row['permit_container_type'] ?? '')),
                    'container_label' => '',
                    'container_parts' => [],   // ['8×20 قدم', '2×40 مبرّد'] — كل واحد شارة لوحده
                    'created_at' => $meta[$pid] ?? null,   // لكشف «إذن جديد» على الشاشة
                    'bills'      => 0,          // عدد البوالص (يتملّى تحت)
                    'drivers'    => [],         // أسماء السائقين
                    'cars'       => 0,          // عدد العربيات المختلفة
                    'movements'  => [],
                ];
            }
            if (!empty($row['movement_id'])) {
                $detail = [];
                if ((int) ($row['container_count'] ?? 0) > 0) { $detail[] = (int) $row['container_count'] . ' حاوية'; }
                if (trim((string) ($row['container_type'] ?? '')) !== '') { $detail[] = container_norm($row['container_type']); }
                if (trim((string) ($row['movement_note'] ?? '')) !== '') { $detail[] = $row['movement_note']; }
                $out[$pid]['movements'][] = [
                    'id'    => (int) $row['movement_id'],
                    'seq'   => (int) $row['seq'],
                    'label' => 'الحركة ' . (int) $row['seq'] . ($detail ? ' — ' . implode(' · ', $detail) : ''),
                ];
            }
        }

        // وصف الحاويات من بوالص كل إذن (نفس منطق الإدخال السريع)
        $breakdown = [];
        if ($out) {
            $ids = implode(',', array_map('intval', array_keys($out)));
            foreach (Database::all(
                "SELECT permit_id, container_type type, SUM(container_count) cnt
                 FROM bawalis
                 WHERE is_legacy = 0 AND permit_id IN ($ids)
                   AND container_type IS NOT NULL AND container_type <> '' AND container_count > 0
                 GROUP BY permit_id, container_type") as $c) {
                $breakdown[(int) $c['permit_id']][] = ['type' => $c['type'], 'cnt' => (int) $c['cnt']];
            }
        }
        foreach ($out as $pid => &$p) {
            $p['container_label'] = Permit::containerLabel(
                $breakdown[$pid] ?? [], $p['containers'], $p['container_type']
            ) ?: '';
            // نفس منطق Permit::containerLabel بس مفكوك لأجزاء، عشان كل «عدد×تصنيف»
            // يبقى شارة لوحدها ومعزولة اتجاهيًا (وإلا الأرقام بتتقلب في العرض RTL).
            $parts = [];
            foreach (($breakdown[$pid] ?? []) as $c) {
                $t = trim((string) ($c['type'] ?? ''));
                $n = (int) ($c['cnt'] ?? 0);
                if ($t !== '' && $n > 0) { $parts[] = $n . '×' . container_norm($t); }
            }
            if (!$parts && $p['containers'] > 0) {
                $parts[] = $p['container_type'] !== ''
                    ? $p['containers'] . '×' . container_norm($p['container_type'])
                    : $p['containers'] . ' حاوية';
            }
            $p['container_parts'] = $parts;
        }
        unset($p);

        // إحصائيات البوالص لكل إذن: العدد + السائقين + العربيات (وصفي، قراءة فقط).
        // من بوالص الإذن الحيّة بس (is_legacy=0) — نفس أساس عدّاد الحاويات فوق.
        if ($out) {
            $ids = implode(',', array_map('intval', array_keys($out)));
            foreach (Database::all(
                "SELECT permit_id,
                        COUNT(*) bills,
                        COUNT(DISTINCT NULLIF(TRIM(car_no),'')) cars,
                        GROUP_CONCAT(DISTINCT NULLIF(TRIM(driver),'') ORDER BY driver SEPARATOR '|') drivers
                 FROM bawalis
                 WHERE is_legacy = 0 AND permit_id IN ($ids)
                 GROUP BY permit_id") as $s) {
                $pid = (int) $s['permit_id'];
                if (!isset($out[$pid])) { continue; }
                $out[$pid]['bills'] = (int) $s['bills'];
                $out[$pid]['cars']  = (int) $s['cars'];
                $names = array_values(array_filter(array_map('trim', explode('|', (string) $s['drivers']))));
                $out[$pid]['drivers'] = array_slice($names, 0, 8);   // سقف للعرض
            }
        }

        // هل الإذن ليه مستندات ظاهرة للمندوب؟ (v56 — وصفي بحت، **استعلامان
        // للشاشة كلها** لا استعلامين لكل إذن. تفاصيل السبب في PartyDoc::keySet.)
        $docKeys = PartyDoc::keySet(true);
        foreach ($out as &$pd) {
            $pd['has_docs'] = $docKeys
                ? PartyDoc::permitHasDocs($docKeys, $pd['company'], $pd['client'])
                : false;
        }
        unset($pd);

        // ⚠️ نرجّع بترتيب الاستعلام الأول (التاريخ) مش بترتيب الـid — الاستعلام
        //    التاني بيرتّب بـ`p.id` عشان تجميع الحركات، فالترتيب المعروض لازم
        //    يترجع من `$meta` اللي متولّدة من الترتيب الصح.
        $ordered = [];
        foreach (array_keys($meta) as $pid) {
            if (isset($out[$pid])) { $ordered[] = $out[$pid]; }
        }
        return $ordered;
    }

    /** إذن واحد ظاهر للمندوب (أو null لو مقفول/مش موجود) — للفورم. */
    public static function myPermit(int $repId, int $permitId): ?array
    {
        foreach (self::myPermits($repId) as $p) {
            if ($p['id'] === $permitId) { return $p; }
        }
        return null;
    }

    /**
     * ⚠️ حارس الصلاحية — لازم يتنادى قبل أي كتابة. الواجهة مش مصدر ثقة.
     *
     * v43: الشرط بقى **«الإذن مفتوح»** بس، بعد ما المالك قرّر إن كل الأذونات
     * المفتوحة تظهر لكل المناديب. التنزيل ما بقاش شرط للكتابة زي ما بقاش شرط
     * للرؤية — لو رجّعت شرط التنزيل هنا لازم ترجّعه في `myPermits` كمان.
     *
     * $repId متسابة في التوقيع (مش مستخدَمة دلوقتي) عشان كل نقط النداء تفضل
     * ماشية، ولو رجع تقييد لكل مندوب يبقى مكانه هنا بلا تعديل الكنترولرات.
     */
    public static function assertAssigned(int $repId, int $permitId): void
    {
        $status = Database::scalar("SELECT status FROM permits WHERE id = ?", [$permitId]);
        if ($status === null) {
            throw new RuntimeException('الإذن ده مش موجود.');
        }
        // الإذن ممكن يتقفل والصفحة لسه مفتوحة على موبايل المندوب، فبنقفل الكتابة هنا.
        if ($status !== 'open') {
            throw new RuntimeException('الإذن ده اتقفل — كلّم المكتب.');
        }
    }

    // ─────────────────────────────────────────── البنود المرسَلة

    /**
     * بنود المندوب في **يوم العمل ده بس** (الأحدث أولاً) — للعرض على شاشته.
     *
     * ⚠️ v49 — قرار المالك: شاشة المندوب تعرض **عهدة اليوم والمدخلات اللي دخلت
     *    على العهدة دي وبس**. أي بند من يوم تاني ممنوع يبان هنا، لأنه بيخلّي
     *    الأرقام تبان وكأنها خصومات من عهدة اليوم وهي مش منها.
     *    لو عايز يشوف يوم تاني، يغيّر يوم العمل — ده هو التنقّل بين الأيام.
     *
     *    ده **بدّل** فلتر «صفحة جديدة بعد التسوية» القديم (`periodCutoff`):
     *    فلتر اليوم أضيق منه وبيغطّيه، وتشغيل الاتنين مع بعض كان ممكن يخفي
     *    بنود اليوم نفسه لو حصلت تسوية النهاردة.
     *
     * ⚠️ الفلتر ده **على شاشة المندوب بس** — المكتب بيقرا `rep_submissions`
     *    مباشرة بلا أي فلتر، فمفيش بند بيضيع من المراجعة ولا من الفواتير.
     */
    public static function mySubmissions(int $repId, int $limit = 40, string $status = '', string $day = ''): array
    {
        $limit = max(1, min(200, $limit));
        $where = 's.rep_id = ?';
        $params = [$repId];
        if ($day !== '') {
            $where .= ' AND s.entry_date = ?';
            $params[] = $day;
        }
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where .= ' AND s.status = ?';
            $params[] = $status;
        }
        return Database::all(
            "SELECT s.*, p.permit_no, p.permit_type, p.company
             FROM rep_submissions s
             LEFT JOIN permits p ON p.id = s.permit_id
             WHERE $where
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT $limit", $params);
    }

    /**
     * آخر تسوية للمندوب — بعدها تبدأ فترة «بنودي» جديدة على موبايله.
     * بترجّع ['at' => ختم التسجيل للفلترة, 'date' => تاريخ التسوية للعرض] أو null.
     */
    public static function lastSettlement(int $repId): ?array
    {
        if (!RepCustody::available()) { return null; }
        $row = Database::row(
            "SELECT created_at, settle_date FROM rep_custody_settlements
             WHERE rep_id = ? ORDER BY created_at DESC, id DESC LIMIT 1", [$repId]);
        if (!$row) { return null; }
        return ['at' => $row['created_at'], 'date' => $row['settle_date']];
    }

    /** ختم آخر تسوية (للفلترة) أو null لو عمره ما اتسوّى. */
    public static function periodCutoff(int $repId): ?string
    {
        $s = self::lastSettlement($repId);
        return $s['at'] ?? null;
    }

    /** بند يخص المندوب، مع بيانات الإذن اللازمة للعرض والتعديل. */
    public static function findMine(int $repId, int $subId): ?array
    {
        return Database::row(
            "SELECT s.*, p.permit_no, p.permit_type, p.company, p.client
             FROM rep_submissions s
             LEFT JOIN permits p ON p.id = s.permit_id
             WHERE s.id = ? AND s.rep_id = ?", [$subId, $repId]);
    }

    /** القيم التي يجوز نسخها لنموذج تعديل/إعادة إرسال — بلا صورة أو حالة مراجعة. */
    public static function formValues(array $s): array
    {
        return [
            'kind'        => (string) ($s['kind'] ?? 'receipt'),
            'movement_id' => (string) ($s['movement_id'] ?? ''),
            'note'        => (string) ($s['note'] ?? ''),
            'qty'         => (string) ($s['qty'] ?? 1),
            'unit_amount' => (string) ($s['unit_amount'] ?? ''),
            'entry_date'  => (string) ($s['entry_date'] ?? date('Y-m-d')),
            'ref_no'      => (string) ($s['ref_no'] ?? ''),
            'is_visa'     => !empty($s['is_visa']) ? '1' : '',
            'visa_card_id'=> (string) ($s['visa_card_id'] ?? ''),
            'unpaid'      => !empty($s['unpaid']) ? '1' : '',   // v57
        ];
    }

    /**
     * عدّادات سريعة لشاشة المندوب — **ليوم العمل ده بس**.
     * لازم تفضل بنفس فلتر `mySubmissions` بالظبط، وإلا الشارة تقول رقم
     * والقايمة تحته تعرض رقم تاني.
     */
    public static function myCounts(int $repId, string $day = ''): array
    {
        $params = [$repId];
        $extra = '';
        if ($day !== '') { $extra = ' AND entry_date = ?'; $params[] = $day; }
        $row = Database::row(
            "SELECT
                COALESCE(SUM(status='pending'),0)  AS pending,
                COALESCE(SUM(status='approved'),0) AS approved,
                COALESCE(SUM(status='rejected'),0) AS rejected
             FROM rep_submissions WHERE rep_id = ?$extra", $params);
        return [
            'pending'  => (int) ($row['pending'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }

    /**
     * إجمالي البنود المستنية الاعتماد اللي **دفعها المندوب كاش من عهدته** بس لسه
     * ما اتعتمدتش، فمش داخلة في spentTotal. الرصيد المعروض له = رصيد العهدة −
     * ده (عشان يبقى واقعي مع اللي في جيبه).
     *
     * ⚠️ v45: **المدفوع بالفيزا مستثنى** (`is_visa = 0`). البند ده لما يتعتمد
     *    هيتخصم من كارت المكتب مش من عهدة المندوب، فما ينفعش نخصمه من «معايا
     *    دلوقتي» وهو لسه مستني — وإلا يبان إن عهدته نقصت بحاجة ما دفعهاش كاش.
     *    (نفس منطق استثناء الفيزا في spentTotal و RepCustody::dashboard.)
     */
    public static function pendingAmount(int $repId, ?string $day = null): float
    {
        $args = [$repId];
        $w = '';
        if ($day !== null && $day !== '') { $w = ' AND entry_date = ?'; $args[] = $day; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_submissions
             WHERE rep_id = ? AND status = 'pending' AND is_visa = 0$w", $args), 2);
    }

    /**
     * «معايا كام» زي ما المندوب شايفها = رصيد العهدة − اللي دفعه ولسه مستني اعتماد.
     * ده الرقم الصادق بالنسبة له، لأن فلوس البنود المستنية خرجت من إيده فعلاً.
     */
    public static function pocket(int $repId): array
    {
        $b = RepCustody::breakdown($repId);
        $pending = self::pendingAmount($repId);
        return [
            'deposits' => $b['deposits'],
            'spent'    => $b['spent'],
            'returned' => $b['returned'],
            'pending'  => $pending,
            'official' => $b['balance'],
            'pocket'   => round($b['balance'] - $pending, 2),
        ];
    }

    /**
     * ⚠️ v49 — **عهدة يوم العمل**، وهي الرقم اللي المندوب بيشوفه وبيسحب منه.
     *
     * كل يوم عهدة مستقلة بلا أي ترحيل من اليوم اللي قبله (قرار المالك)، فالرقم
     * ده = المنزَّل في اليوم + المستلَم فيه − المصروف فيه − المرتجع − المحوَّل.
     * `pocket` بيخصم كمان اللي دفعه ولسه مستني اعتماد — فلوسه خرجت فعلاً.
     *
     * ⚠️ **مفيش أي رقم تراكمي هنا عن قصد (قرار المالك).** شاشة المندوب تعرض
     *    عهدة اليوم والمدخلات اللي اتدخلت على العهدة دي **وبس** — أي رقم من
     *    أيام تانية بيبان وكأنه خصم من عهدة النهاردة وهو مش منها. الرصيد
     *    التراكمي شغل المكتب (`/rep-custody`) وشاشة المشرف، مش شاشته هو.
     *    لو ضفت مفتاح تراكمي هنا، `bin/verify_v49.php` بيفشل.
     *
     * `locked` = اليوم متقفل في المكتب فالكتابة عليه مرفوضة (نفس حارس الكتابة).
     */
    public static function dayPocket(int $repId, string $day): array
    {
        $b = RepCustody::breakdown($repId, $day);
        $pending = self::pendingAmount($repId, $day);
        return [
            'day'          => $day,
            'deposits'     => $b['deposits'],
            'spent'        => $b['spent'],
            'returned'     => $b['returned'],
            'transfer_in'  => $b['transfer_in'],
            'transfer_out' => $b['transfer_out'],
            'pending'      => $pending,
            'official'     => $b['balance'],
            'pocket'       => round($b['balance'] - $pending, 2),
            'locked'       => RepCustody::isDayLocked($day),
            'lock_reason'  => RepCustody::dayLockReason($day),
        ];
    }

    /**
     * ⚠️ حارس اليوم المتقفل — لازم يتنادى قبل أي كتابة مؤرَّخة من بوابة المندوب.
     *
     * قرار المالك (v49): لو المكتب قفل اليوم، المندوب مش بيسجّل عليه ولا بيعدّل
     * فيه. ده متسق مع v42 — بند المندوب بيتخصم من خزنة يوم صرفه، واليوم المتقفل
     * رصيده مثبَّت. الحارس هنا (في الموديل) مش في الواجهة: إخفاء الزرار مش حماية.
     */
    public static function assertDayOpen(string $day): void
    {
        $reason = RepCustody::dayLockReason($day);
        if ($reason !== null) {
            throw new RepSubmissionValidationException(['entry_date' => $reason]);
        }
    }

    /**
     * حالة خفيفة للـping (إشعارات حيّة والتطبيق مفتوح) — قراءة فقط، بلا أثر مالي.
     * بترجّع أحدث ختم زمني لأذون مفتوح ولتنزيل عهدة + عدّاد المستني.
     */
    public static function pingState(int $repId, string $day = ''): array
    {
        // v49: التنزيل والمستنّي مقصورين على يوم العمل — الشارة لازم تطابق
        // اللي ظاهر على الشاشة، وإلا الجرس يقول رقم والقايمة تقول تاني.
        $depArgs = [$repId];
        $depWhere = '';
        if ($day !== '') { $depWhere = ' AND dep_date = ?'; $depArgs[] = $day; }
        return [
            'permit_at'  => Database::scalar(
                "SELECT MAX(created_at) FROM permits WHERE status = 'open'"),
            'deposit_at' => RepCustody::available() ? Database::scalar(
                "SELECT MAX(created_at) FROM rep_custody_deposits WHERE rep_id = ?$depWhere", $depArgs) : null,
            'pending'    => self::myCounts($repId, $day)['pending'],
        ];
    }

    /**
     * أحدث تنزيلات العهدة (للتنبيه «نزلت عهدة جديدة») — قراءة فقط، بلا أثر مالي.
     * الواجهة بتقارن `at` بآخر زيارة محفوظة في المتصفح (localStorage) عشان
     * تعرف الجديد بلا أي هجرة ولا عمود «آخر مشاهدة» في القاعدة.
     */
    public static function recentDeposits(int $repId, int $limit = 5, string $day = ''): array
    {
        $rows = RepCustody::deposits($repId);
        // v49: تنبيه «نزلت عهدة جديدة» يخصّ عهدة يوم العمل بس — تنزيل يوم تاني
        // مالوش علاقة بالعهدة اللي هو بيصرف منها دلوقتي.
        if ($day !== '') {
            $rows = array_values(array_filter($rows, static fn(array $d): bool => (string) $d['dep_date'] === $day));
        }
        $out = [];
        foreach (array_slice($rows, 0, max(1, $limit)) as $d) {
            $out[] = [
                'amount' => round((float) $d['amount'], 2),
                'date'   => $d['dep_date'],
                'at'     => $d['created_at'] ?? $d['dep_date'],   // ختم زمني للمقارنة
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────── إرسال بند

    /**
     * المندوب بيبعت بند. **مفيش أي أثر مالي** — بيقعد pending لحد الاعتماد.
     * بيرجع id البند.
     *
     * $d: permit_id, movement_id, kind, note, qty, unit_amount, entry_date,
     *     ref_no, photo, client_token
     */
    public static function submit(int $repId, array $d): int
    {
        $token = trim((string) ($d['client_token'] ?? ''));
        // الاستدعاءات الداخلية/اختبارات v35 القديمة لا تعرف الرمز؛ نولّد لها واحدًا.
        // الواجهة نفسها ترسل رمزًا ثابتًا محفوظًا في المسودة كي تصبح الإعادة idempotent.
        if ($token === '') { $token = bin2hex(random_bytes(16)); }
        elseif (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            throw new RepSubmissionValidationException([
                '_form' => 'تعذّر تأمين محاولة الإرسال. حدّث الصفحة وحاول تاني.',
            ]);
        }

        // لو الطلب وصل قبل كده لكن رد الشبكة ضاع، رجّع نفس البند بدل إنشاء نسخة.
        $existing = Database::scalar(
            'SELECT id FROM rep_submissions WHERE rep_id = ? AND client_token = ?',
            [$repId, $token]
        );
        if ($existing) { return (int) $existing; }

        $n = self::normaliseSubmission($repId, $d);

        // ملحوظة: مفيش Closing::guard هنا عن قصد — البند ده ما بيمسّش الخزنة
        // لا دلوقتي ولا بعد الاعتماد (مدفوع من عهدة المندوب — v35).

        try {
            Database::exec(
                "INSERT INTO rep_submissions
                    (rep_id, client_token, permit_id, movement_id, kind, note, qty,
                     unit_amount, amount, entry_date, ref_no, photo, is_visa, visa_card_id,
                     unpaid, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending')",
                [$repId, $token, $n['permit_id'], $n['movement_id'], $n['kind'], $n['note'],
                 $n['qty'], $n['unit_amount'], $n['amount'], $n['entry_date'], $n['ref_no'],
                 ($d['photo'] ?? '') !== '' ? $d['photo'] : null,
                 $n['is_visa'], $n['visa_card_id'], $n['unpaid']]);
        } catch (\Throwable $e) {
            // خط دفاع للطلبين المتزامنين بنفس الرمز؛ لا نخفي أي خطأ آخر.
            $existing = Database::scalar(
                'SELECT id FROM rep_submissions WHERE rep_id = ? AND client_token = ?',
                [$repId, $token]
            );
            if ($existing) { return (int) $existing; }
            throw $e;
        }

        return Database::lastId();
    }

    /** تعديل بند معلّق قبل أن يبدأ المكتب مراجعته. */
    public static function updateMine(int $repId, int $subId, array $d): array
    {
        $old = self::assertEditable($repId, $subId);
        $d['permit_id'] = (int) $old['permit_id'];

        // البند ما ينفعش يقلب من «على إذن» لـ«نثرية» أو العكس وهو بيتعدّل: الفورمين
        // مختلفين (نثرية بلا إذن ولا حركة)، والقلب كان هيسيب حركة يتيمة على بند
        // بلا إذن. فالعائلة بتتقفل على الأصل والمندوب يمسح ويسجّل من جديد لو غلط.
        $wasMisc = self::isMisc((string) $old['kind']);
        if ($wasMisc !== self::isMisc((string) ($d['kind'] ?? ''))) {
            $d['kind'] = $wasMisc ? 'misc' : (string) $old['kind'];
        }

        $n = self::normaliseSubmission($repId, $d);

        $photo = array_key_exists('photo', $d) ? trim((string) $d['photo']) : (string) ($old['photo'] ?? '');
        $affected = Database::exec(
            "UPDATE rep_submissions
             SET movement_id=?, kind=?, note=?, qty=?, unit_amount=?, amount=?, entry_date=?,
                 ref_no=?, photo=?, is_visa=?, visa_card_id=?, unpaid=?, updated_at=NOW()
             WHERE id=? AND rep_id=? AND status='pending' AND review_started_at IS NULL",
            [$n['movement_id'], $n['kind'], $n['note'], $n['qty'], $n['unit_amount'],
             $n['amount'], $n['entry_date'], $n['ref_no'], $photo !== '' ? $photo : null,
             $n['is_visa'], $n['visa_card_id'], $n['unpaid'], $subId, $repId]);

        if ($affected !== 1) {
            // MySQL قد يرجع 0 لو المستخدم حفظ نفس القيم في نفس الثانية.
            // إعادة الفحص تفرّق ده عن سباق حقيقي بدأ فيه المكتب المراجعة.
            self::assertEditable($repId, $subId);
        }
        return $old;
    }

    /** يتأكد أن البند ما زال قابلًا للتعديل/الحذف ويرجعه. */
    public static function assertEditable(int $repId, int $subId): array
    {
        $row = self::findMine($repId, $subId);
        if (!$row) { throw new RuntimeException('البند مش موجود.'); }
        if ($row['status'] !== 'pending') {
            throw new RuntimeException('البند اتراجع خلاص — مش هينفع تعدّله.');
        }
        if (!empty($row['review_started_at'])) {
            throw new RuntimeException('المكتب بدأ مراجعة البند — مش هينفع تعدّله دلوقتي.');
        }
        return $row;
    }

    /** المندوب بيمسح بند بتاعه — المستني بس (المعتمد بقى بند حقيقي). */
    public static function deleteMine(int $repId, int $subId): ?string
    {
        $row = self::assertEditable($repId, $subId);
        $affected = Database::exec(
            "DELETE FROM rep_submissions
             WHERE id = ? AND rep_id = ? AND status='pending' AND review_started_at IS NULL",
            [$subId, $repId]
        );
        if ($affected !== 1) {
            throw new RuntimeException('المكتب بدأ مراجعة البند دلوقتي؛ مش هينفع تمسحه.');
        }
        return $row['photo'] ?: null;
    }

    /** تحقق واحد تستخدمه الإضافة والتعديل؛ الخادم يظل مصدر الحقيقة. */
    private static function normaliseSubmission(int $repId, array $d): array
    {
        $errors = [];
        $kind = (string) ($d['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) { $errors['kind'] = 'اختر نوع البند.'; }

        // النثرية (v39) مصروف عام مش مربوط بإذن — فمفيش إذن نتحقق منه ولا حركة.
        // أي permit_id بعته الفورم بيتجاهَل هنا عن قصد: النوع هو اللي بيحكم.
        $misc = self::isMisc($kind);
        $permitId = $misc ? 0 : (int) ($d['permit_id'] ?? 0);
        if (!$misc) {
            try { self::assertAssigned($repId, $permitId); }
            catch (\Throwable $e) { $errors['_form'] = $e->getMessage(); }
        }

        $note = preg_replace('/\s+/u', ' ', trim((string) ($d['note'] ?? '')));
        if ($note === '') { $errors['note'] = 'اكتب البيان، زي تخصيص أو حمولة.'; }
        elseif (mb_strlen($note) > 255) { $errors['note'] = 'البيان لازم يكون 255 حرف أو أقل.'; }

        $qty = (int) ($d['qty'] ?? 1);
        if ($qty < 1 || $qty > 10000) { $errors['qty'] = 'الكمية لازم تكون من 1 لـ10000.'; }

        $rawUnit = strtr(ar2en_digits(trim((string) ($d['unit_amount'] ?? ''))), [
            ',' => '', '٬' => '', ' ' => '', '٫' => '.',
        ]);
        $unit = round((float) $rawUnit, 2);
        if ($rawUnit === '' || $unit <= 0) { $errors['unit_amount'] = 'اكتب المبلغ اللي دفعته.'; }
        elseif ($unit > 999999999999.99) { $errors['unit_amount'] = 'المبلغ أكبر من الحد المسموح.'; }

        $movementId = $misc ? null : ((int) ($d['movement_id'] ?? 0) ?: null);
        $hasMovements = $permitId > 0 ? (int) Database::scalar(
            'SELECT COUNT(*) FROM permit_movements WHERE permit_id = ?', [$permitId]) : 0;
        if ($movementId === null && $hasMovements > 0) {
            $errors['movement_id'] = 'اختر الحركة اللي دفعت عليها.';
        } elseif ($movementId !== null) {
            $belongs = Database::scalar(
                'SELECT COUNT(*) FROM permit_movements WHERE id = ? AND permit_id = ?',
                [$movementId, $permitId]
            );
            if (!$belongs) { $errors['movement_id'] = 'الحركة المختارة مش تابعة للإذن.'; }
        }

        $date = trim((string) ($d['entry_date'] ?? ''));
        if (!self::validDate($date)) { $errors['entry_date'] = 'اكتب تاريخ دفع صحيح.'; }
        elseif ($date > date('Y-m-d')) { $errors['entry_date'] = 'تاريخ الدفع ما ينفعش يكون في المستقبل.'; }
        // ⚠️ v49 — اليوم المتقفل مرفوض هنا (نقطة الاختناق الوحيدة للإضافة والتعديل
        //    مع بعض)، فمفيش مسار كتابة يقدر يفوّته حتى لو الفورم اتزوّر.
        elseif (($lock = RepCustody::dayLockReason($date)) !== null) { $errors['entry_date'] = $lock; }

        $ref = preg_replace('/\s+/u', ' ', trim((string) ($d['ref_no'] ?? '')));
        if (mb_strlen($ref) > 120) { $errors['ref_no'] = 'رقم الإيصال لازم يكون 120 حرف أو أقل.'; }

        // ── الفيزا (v45): البند المدفوع بكارت المكتب بيتخصم من الكارت وبس.
        // الكارت **إلزامي** لو الفيزا مفعّلة، وإلا المبلغ هيضيع: لا من الكارت
        // (مش منسوب لحد) ولا من العهدة (اتستثنى) ولا من الخزنة (is_visa=1).
        $isVisa = !empty($d['is_visa']);
        $cardId = (int) ($d['visa_card_id'] ?? 0) ?: null;
        if (!$isVisa) {
            $cardId = null;   // الاختيار بيتخزّن بس لو الفيزا مفعّلة (نمط v22)
        } elseif ($cardId === null) {
            $errors['visa_card_id'] = 'اختر الكارت اللي دفعت بيه.';
        } elseif (!VisaCard::isActive($cardId)) {
            $errors['visa_card_id'] = 'الكارت ده مش موجود أو متوقف — اختر كارت تاني.';
        }

        // ── «غير مدفوع» (v57): البند اتحاسب من برّه عهدة المندوب وبرّه الخزنة
        // كمان (قرار المالك). بيتسجّل على الإذن وبيتفوتر للعميل عادي، لكن
        // **ما بيخصمش ولا مليم من أي محفظة**.
        // ⚠️ بنود الأذونات بس — النثرية دايمًا بتتدفع من عهدته فعلاً (قرار المالك).
        // ⚠️ ومتنافي مع الفيزا: يا إما اتدفع بكارت المكتب يا إما ما اتدفعش منّنا خالص.
        $unpaid = !$misc && !empty($d['unpaid']);
        if ($unpaid && $isVisa) {
            $errors['unpaid'] = 'اختار واحدة بس: يا «مدفوع بالفيزا» يا «غير مدفوع».';
        }
        if ($unpaid) { $isVisa = false; $cardId = null; }

        $amount = round($unit * $qty, 2);
        if ($amount > 999999999999.99) { $errors['unit_amount'] = 'إجمالي المبلغ أكبر من الحد المسموح.'; }

        if ($errors) { throw new RepSubmissionValidationException($errors); }
        return [
            // النثرية بتتخزن بـpermit_id = NULL (العمود بقى nullable في v39)
            'permit_id' => $misc ? null : $permitId,
            'movement_id' => $movementId, 'kind' => $kind,
            'note' => $note, 'qty' => $qty, 'unit_amount' => $unit, 'amount' => $amount,
            'entry_date' => $date, 'ref_no' => $ref !== '' ? $ref : null,
            'is_visa' => $isVisa ? 1 : 0, 'visa_card_id' => $cardId,
            'unpaid' => $unpaid ? 1 : 0,
        ];
    }

    /**
     * هل المندوب ده مشرف يتابع باقي المناديب؟ (v47 — اختصار لـ`Rep::canWatchReps`
     * عشان الواجهة تسأل من نفس المكان اللي بتسأل منه عن باقي الصلاحيات).
     * الحارس الحقيقي في `RepWatch::assertAllowed` قبل أي قراءة.
     */
    public static function canWatchReps(int $repId): bool
    {
        return Rep::canWatchReps($repId);
    }

    // ─────────────────────────────────────────── فتح إذن من الموبايل (v43)

    /** الحقول اللي بيتحكم فيها حارس الأسماء: حقل الفورم ⇒ نوع الاقتراح. */
    private const PERMIT_NAME_FIELDS = ['company' => 'company', 'client' => 'client', 'destination' => 'destination'];

    /** هل المندوب ده مسموح له يفتح إذن؟ (اختصار لـ`Rep::canCreatePermit` عشان الواجهة تسأل من مكان واحد) */
    public static function canCreatePermit(int $repId): bool
    {
        return Rep::canCreatePermit($repId);
    }

    /**
     * ⚠️ حارس صلاحية فتح الإذن (v44) — بيرمي لو المندوب مش مسموح له.
     * الرفض هو الافتراضي: قبل migrate_v44 بيرفض الكل.
     */
    public static function assertCanCreatePermit(int $repId): void
    {
        if (!Rep::canCreatePermit($repId)) {
            throw new RepSubmissionValidationException([
                '_form' => 'مالكش صلاحية تفتح إذن. كلّم المكتب لو محتاج تفتحه.',
            ]);
        }
    }

    /**
     * المندوب بيفتح إذن/شهادة من موبايله (قرار المالك — بيتفتح على طول بلا اعتماد).
     *
     * ⚠️ **بلا أي أثر مالي**: الإذن نفسه مجرد وعاء تشغيلي — الفلوس بتيجي من
     * الإيصالات/المصروفات اللي بتتسجّل عليه بعدين وبتعدّي على صندوق الانتظار
     * زي ما هي. عشان كده مفيش `Closing::guard` هنا (زي `PermitController::store`
     * بالظبط)، و**النِسَب المالية `inspection_rate`/`guarantee_rate` بتتساب NULL**
     * — المالك هو اللي بيحطّها من المكتب، والمندوب أصلاً ما بيشوفهاش.
     *
     * بيرجع ['id' => .., 'label' => ..].
     */
    public static function createPermit(int $repId, array $d): array
    {
        // ⚠️ v44 — الحارس الحقيقي هنا، مش في الواجهة ولا في الكنترولر بس:
        // ده المسار الوحيد لإنشاء إذن من بوابة المندوب، فأي طريق تاني (فورم
        // مزوّر، استدعاء مباشر) بيعدّي عليه إجباريًّا. الرفض هو الافتراضي.
        self::assertCanCreatePermit($repId);

        $n = self::normalisePermit($d);

        // الاسم القانوني من جدول الأطراف (تحذير 3) — نفس مسار شاشة المكتب.
        $agent   = PartyMaster::ensure('agent', $n['client']);
        $company = PartyMaster::ensure('company', $n['company']);

        $data = [
            'permit_type'  => $n['permit_type'],
            'permit_no'    => $n['permit_no'],
            'permit_date'  => $n['permit_date'],
            'company'      => $company['name'] ?? null,
            'company_id'   => $company['id'] ?? null,
            'client'       => $agent['name'] ?? null,
            'agent_id'     => $agent['id'] ?? null,
            'destination'  => $n['destination'],
            'shipping_co'  => $n['shipping_co'],
            'agency'       => $n['agency'],
            'port'         => $n['port'],
            'containers'   => $n['containers'],
            'container_type' => $n['container_type'],
            // النِسَب المالية شغل المكتب — المندوب ما بيدخّلهاش (وما بيشوفهاش).
            'inspection_rate' => null,
            'guarantee_rate'  => null,
            'note'         => $n['note'],
        ];

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $cols = array_keys($data);
            Database::exec(
                "INSERT INTO permits (`" . implode('`,`', $cols) . "`) VALUES ("
                . implode(',', array_map(fn($c) => ":$c", $cols)) . ")",
                array_combine(array_map(fn($c) => ":$c", $cols), array_values($data))
            );
            $id = Database::lastId();

            // أول حركة جاهزة فورًا — من غيرها المندوب ما يقدرش يسجّل بند على الإذن
            // (normaliseSubmission بتطلب حركة لو الإذن ليه حركات، والفورم بيحتاج واحدة).
            Permit::createMovement($id);

            // بينزّل نفسه على الإذن اللي فتحه: بيدي شارة «منزّل لي» ويخلّيه فوق في
            // قايمته. التنزيل وصفي بحت (مفيش أثر مالي) — v35.
            try { RepAssignment::assign($id, $repId); } catch (\Throwable $e) { /* وصفي — فشله ما يلغيش الإذن */ }

            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        // تعلّم القوائم المنسدلة من إدخال المندوب — نفس سلوك شاشة المكتب.
        foreach (['company' => $data['company'], 'client' => $data['client'],
                  'destination' => $data['destination'], 'shipping_co' => $data['shipping_co'],
                  'agency' => $data['agency'], 'port' => $data['port'],
                  'container_type' => $data['container_type']] as $type => $value) {
            if (!empty($value)) { Lookup::add($type, (string) $value); }
        }

        $label = Permit::noLabel($n['permit_type']) . ' ' . $n['permit_no'];
        $saved = Database::row('SELECT * FROM permits WHERE id = ?', [$id]);
        Audit::log(
            'create', 'permits', $id, null, $saved,
            'إضافة ' . $label . ' — من موبايل المندوب',
            ['permit_id' => $id, 'permit_no' => $n['permit_no'],
             'record_date' => $n['permit_date'], 'rep_id' => $repId, 'source' => 'rep_portal']
        );

        return ['id' => $id, 'label' => $label];
    }

    /**
     * تحقق فتح الإذن — الخادم هو مصدر الحقيقة زي كل حاجة في البوابة دي.
     * بيرجع القيم النظيفة أو بيرمي RepSubmissionValidationException.
     */
    private static function normalisePermit(array $d): array
    {
        $errors = [];

        $type = (string) ($d['permit_type'] ?? '');
        if (!isset(Permit::TYPES[$type])) { $errors['permit_type'] = 'اختار نوع الإصدار.'; $type = 'sader'; }

        $no = preg_replace('/\s+/u', ' ', trim((string) ($d['permit_no'] ?? '')));
        if ($no === '') {
            $errors['permit_no'] = 'اكتب ' . Permit::noLabel($type) . '.';
        } elseif (mb_strlen($no) > 60) {
            $errors['permit_no'] = 'الرقم لازم يكون 60 حرف أو أقل.';
        } elseif (Permit::noExists($no, $type)) {
            $errors['permit_no'] = Permit::noLabel($type) . ' «' . $no . '» متسجّل قبل كده. '
                                 . 'راجع الرقم — لو ده نفس الإذن هتلاقيه في قايمتك.';
        }

        $date = trim((string) ($d['permit_date'] ?? ''));
        if (!self::validDate($date)) { $errors['permit_date'] = 'اكتب تاريخ صحيح.'; }
        elseif ($date > date('Y-m-d')) { $errors['permit_date'] = 'التاريخ ما ينفعش يكون في المستقبل.'; }

        // حارس الأسماء (نفس منطق PermitController::nameGuardError): بيوحّد التهجئة
        // تلقائيًا، وبيقف لو الاسم «قريب لكن مختلف» لحد ما المندوب يأكّد إنه جديد.
        $names = [];
        foreach (self::PERMIT_NAME_FIELDS as $field => $suggestType) {
            $raw = preg_replace('/\s+/u', ' ', trim((string) ($d[$field] ?? '')));
            if ($raw === '') { $names[$field] = ''; continue; }
            if (mb_strlen($raw) > 120) { $errors[$field] = 'الاسم لازم يكون 120 حرف أو أقل.'; }
            $g = NameSuggestion::resolve($suggestType, $raw);
            if ($g['conflict'] !== null && empty($d['newname_' . $field])) {
                $errors[$field] = 'الاسم «' . $raw . '» قريب جدًا من «' . $g['conflict'] . '» المسجّل عندنا. '
                                . 'اختار الاسم الموجود، أو أكّد إنه اسم جديد فعلاً.';
            }
            $names[$field] = (string) $g['value'];
        }
        if (($names['company'] ?? '') === '') { $errors['company'] = 'اكتب الشركة اللي لصالحها الإذن.'; }
        if (($names['client'] ?? '') === '')  { $errors['client']  = 'اكتب العميل (الوكيل).'; }

        // ⚠️ القوائم المقفولة (`Lookup::STRICT`): المندوب بيختار من المعتمد وبس.
        //    الفورم بيعرض `<select>` لكنه **مش مصدر ثقة** — الفحص هنا هو الحد الحقيقي.
        //    مفيش استثناء «قيمة قديمة» لأن ده إنشاء إذن جديد مش تعديل سجل قايم.
        $short = [];
        foreach (['shipping_co' => 'التوكيل الملاحي', 'agency' => 'التوكيل', 'port' => 'الميناء'] as $field => $label) {
            $v = preg_replace('/\s+/u', ' ', trim((string) ($d[$field] ?? '')));
            if (mb_strlen($v) > 120) { $errors[$field] = $label . ' لازم يكون 120 حرف أو أقل.'; }
            elseif ($v !== '' && ($fixed = self::strictValue($field, $v)) === null) {
                $errors[$field] = $label . ' لازم يكون من القائمة — اختار من الموجود واطلب من المكتب يضيف الناقص.';
            } elseif ($v !== '') { $v = $fixed; }
            $short[$field] = $v;
        }

        $containers = (int) ($d['containers'] ?? 0);
        if ($containers < 0 || $containers > 10000) { $errors['containers'] = 'عدد الحاويات لازم يكون من 0 لـ10000.'; }

        // container_norm هي المصدر الوحيد لتوحيد التصنيف (v30) — ممنوع تحويل موازٍ.
        $ctype = container_norm((string) ($d['container_type'] ?? ''));
        if (mb_strlen($ctype) > 120) { $errors['container_type'] = 'التصنيف لازم يكون 120 حرف أو أقل.'; }
        elseif ($ctype !== '' && ($fixedCt = self::strictValue('container_type', $ctype)) === null) {
            $errors['container_type'] = 'التصنيف لازم يكون من القائمة — اختار من الموجود.';
        } elseif ($ctype !== '') { $ctype = $fixedCt; }

        $note = preg_replace('/\s+/u', ' ', trim((string) ($d['note'] ?? '')));
        if (mb_strlen($note) > 500) { $errors['note'] = 'الملاحظة لازم تكون 500 حرف أو أقل.'; }

        if ($errors) { throw new RepSubmissionValidationException($errors); }
        return [
            'permit_type' => $type,
            'permit_no'   => $no,
            'permit_date' => $date,
            'company'     => $names['company'],
            'client'      => $names['client'],
            'destination' => $names['destination'] !== '' ? $names['destination'] : null,
            'shipping_co' => $short['shipping_co'] !== '' ? $short['shipping_co'] : null,
            'agency'      => $short['agency'] !== '' ? $short['agency'] : null,
            'port'        => $short['port'] !== '' ? $short['port'] : null,
            'containers'  => $containers,
            'container_type' => $ctype !== '' ? $ctype : null,
            'note'        => $note !== '' ? $note : null,
        ];
    }

    /**
     * قيمة من قائمة مقفولة: بترجّع التهجئة المعتمدة، أو `null` لو مش من القائمة.
     * ⚠️ نسخة البوابة من `Controller::strictListError` — الاتنين لازم يفضلوا
     *    بنفس القاعدة (مطابقة `normkey` على `Lookup::ofType`)، وإلا المندوب
     *    يقدر يدخّل قيمة المكتب بيرفضها أو العكس.
     */
    private static function strictValue(string $type, string $value): ?string
    {
        $key = Maintenance::normkey($value);
        foreach (Lookup::ofType($type) as $v) {
            if (Maintenance::normkey((string) $v) === $key) { return (string) $v; }
        }
        return null;
    }

    // ─────────────────────────────────────────── إضافة بوليصة من الموبايل (v60)

    /** حقول البوليصة اللي بتعدّي على حارس الأسماء: حقل الفورم ⇒ نوع الاقتراح. */
    private const BILL_NAME_FIELDS = ['driver' => 'driver', 'car_owner' => 'car_owner'];

    /** أقصى عهدة في بوليصة واحدة — حاجز غلط إدخال مش سياسة. */
    private const BILL_MAX_CUSTODY = 999999999.99;

    /**
     * المندوب بيضيف بوليصة على إذن مفتوح من موبايله (v60).
     *
     * قرار المالك: **بتدخل النظام على طول بلا اعتماد** — زي فتح الإذن (v43)
     * وبعكس بنود الإيصالات اللي بتقعد في صندوق المراجعة. المندوب مسؤول عن اللي
     * بيسجّله، والمكتب بيراجعه ويعدّله من صفحة الإذن.
     *
     * ⚠️ **الفلوس اللي بتخصّ العميل ما بتوصلش الشاشة دي** (قاعدة v35): النولون
     *    والتحويلة والبياتة **بتتساب صفر** والمكتب هو اللي بيحطّها. الرقم الوحيد
     *    اللي المندوب بيدخّله هو **عهدة السائق** — وهي فلوس هو دفعها من محفظته.
     *
     * ⚠️⚠️ **عهدة السائق هنا بتتخصم من عهدة المندوب مش من خزنة المكتب** — البوليصة
     *    بتتوسم بـ`custody_rep_id` والوسم ده بيستثنيها من الخزنة ويضيفها لصرفه.
     *    المصدر الوحيد للاتجاهين `App\Models\RepBillCustody`.
     *
     * ⚠️ **صاحب البيان بيتساب NULL دايمًا** (طلب المالك الحرفي): المندوب مش
     *    بيشوف الخانة ولا بيقدر يبعتها، والمكتب بيحطّها بعدين. لو ضفتها للفورم
     *    هتبقى مصدر تلوّث أسماء (تحذير 3) من غير مراجعة.
     *
     * ⚠️ **التاريخ = يوم العمل إجباريًّا** — بيتاخد من الجلسة في الكنترولر
     *    وبيتفرض هنا، والفورم مالوش خانة تاريخ خالص (قاعدة v49).
     *
     * بيرجع ['id' => .., 'label' => ..].
     */
    public static function createBill(int $repId, array $d): array
    {
        $n = self::normaliseBill($repId, $d);

        $data = [
            'permit_id'         => $n['permit_id'],
            'movement_id'       => $n['movement_id'],
            'bill_type'         => $n['bill_type'],
            'bill_date'         => $n['bill_date'],
            'bill_number'       => $n['bill_number'],
            'permit_no'         => $n['bill_type'] === 'sader' ? $n['permit_no'] : null,
            'cert_number'       => $n['bill_type'] === 'warid' ? $n['permit_no'] : null,
            // ⚠️ صاحب البيان شغل المكتب — المندوب ما بيدخّلوش (طلب المالك).
            'declaration_owner'    => null,
            'declaration_owner_id' => null,
            'company'           => $n['company'],
            'company_id'        => $n['company_id'],
            'destination'       => $n['destination'],
            'container_no'      => $n['container_no'],
            'container_type'    => $n['container_type'],
            'container_count'   => $n['container_count'],
            'car_no'            => $n['car_no'],
            'driver'            => $n['driver'],
            'car_owner'         => $n['car_owner'],
            // ⚠️ أرقام العميل شغل المكتب (v35) — الخصم بياخد الافتراضي من Schema
            //    زي شاشة المكتب بالظبط عشان ما يبقاش فيه تعريفان للقيمة.
            'freight'              => 0,
            'custody'              => $n['custody'],
            'transfer'             => 0,
            'discount'             => $n['discount'],
            'overnight_collection' => 0,
        ];
        $data['debt'] = \App\Calc::bawala($data)['debt'];
        // الوسم بيتحط بس لو فيه فلوس اتدفعت فعلاً — بوليصة بعهدة صفر ما خرجش
        // منها ولا مليم من محفظة حد، فتفضل بلا وسم زي أي بوليصة مكتب.
        if ($n['custody'] > 0 && RepBillCustody::ready()) {
            $data['custody_rep_id'] = $repId;
        }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            // صف المندوب هو mutex كل مصادر عهدته — نفس القفل اللي بيستخدمه
            // RepInbox::approve وRepDriverHandover::create.
            Database::row('SELECT id FROM reps WHERE id = ? FOR UPDATE', [$repId]);

            $cols = array_keys($data);
            Database::exec(
                "INSERT INTO bawalis (`" . implode('`,`', $cols) . "`) VALUES ("
                . implode(',', array_map(fn($c) => ":$c", $cols)) . ")",
                array_combine(array_map(fn($c) => ":$c", $cols), array_values($data))
            );
            $billId = Database::lastId();
            Bill::recalc($billId);
            Movement::ensureForBill($billId);   // نفس مسار شاشة المكتب بالحرف

            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        // تعلّم القوائم المنسدلة — نفس سلوك شاشة المكتب.
        if ($n['driver'] !== null)    { Lookup::add('driver', $n['driver']); }
        if ($n['car_owner'] !== null) { Lookup::add('car_owner', $n['car_owner']); }

        $label = $n['bill_number'] !== null ? ('بوليصة ' . $n['bill_number']) : ('بوليصة #' . $billId);
        $saved = Database::row('SELECT * FROM bawalis WHERE id = ?', [$billId]);
        Audit::log('create', 'bawalis', $billId, null, $saved,
            'إضافة ' . $label . ' — من موبايل المندوب'
            . ($n['custody'] > 0 ? ' (عهدة السائق من محفظته)' : ''),
            ['permit_id' => $n['permit_id'], 'permit_no' => $n['permit_no'],
             'movement_id' => $n['movement_id'], 'record_date' => $n['bill_date'],
             'rep_id' => $repId, 'source' => 'rep_portal']);

        return ['id' => $billId, 'label' => $label, 'permit_id' => $n['permit_id']];
    }

    /**
     * تحقق بوليصة المندوب — **الخادم هو مصدر الحقيقة**، الفورم مش موثوق.
     * بيرجع القيم النظيفة أو بيرمي RepSubmissionValidationException برسايل عربية.
     */
    private static function normaliseBill(int $repId, array $d): array
    {
        $errors = [];

        // ① الإذن — لازم مفتوح وظاهر للمندوب (قاعدة v43). ده الحد الأمني الحقيقي.
        $permitId = (int) ($d['permit_id'] ?? 0);
        $permit = null;
        if ($permitId <= 0) {
            $errors['permit_id'] = 'اختار الإذن الأول.';
        } else {
            try { self::assertAssigned($repId, $permitId); }
            catch (\Throwable $e) { $errors['permit_id'] = $e->getMessage(); }
            $permit = Database::row(
                'SELECT id, permit_type, permit_no, company, company_id, destination, container_type
                   FROM permits WHERE id = ?', [$permitId]);
            if (!$permit) { $errors['permit_id'] = 'الإذن ده مش موجود.'; }
        }

        // ② الحركة — لازم تتبع نفس الإذن (مستحيل تتسجّل على إذن تاني لو الفورم اتزوّر)
        $movementId = (int) ($d['movement_id'] ?? 0) ?: null;
        if ($permit && $movementId !== null) {
            $owns = Database::scalar(
                'SELECT id FROM permit_movements WHERE id = ? AND permit_id = ?', [$movementId, $permitId]);
            if (!$owns) { $errors['movement_id'] = 'الحركة دي مش من الإذن ده — اقفل الصفحة وافتحها تاني.'; }
        }
        if ($permit && $movementId === null
            && Database::scalar('SELECT COUNT(*) FROM permit_movements WHERE permit_id = ?', [$permitId])) {
            $errors['movement_id'] = 'اختار الحركة (النقلة) اللي البوليصة دي عليها.';
        }

        // ③ رقم البوليصة — إجباري ومستحيل يتكرر
        $billNo = preg_replace('/\s+/u', ' ', trim((string) ($d['bill_number'] ?? '')));
        if ($billNo === '') {
            $errors['bill_number'] = 'اكتب رقم البوليصة زي ما هو في الورقة.';
        } elseif (mb_strlen($billNo) > 80) {
            $errors['bill_number'] = 'رقم البوليصة لازم يكون 80 حرف أو أقل.';
        } elseif (Bill::numberExists($billNo)) {
            $errors['bill_number'] = 'رقم البوليصة «' . $billNo . '» متسجّل قبل كده. '
                                   . 'راجع الرقم — لو البوليصة دي اتسجّلت خلاص مش محتاج تعيدها.';
        }

        // ④ عدد الحاويات — لازم يلتزم بسعة الحركة الظاهرة (وإلا الإذن يتعدّى)
        // ⚠️ الحد الأدنى **حاوية واحدة** (طلب المالك): رقم الحاوية بقى إجباري،
        //    فبوليصة بصفر حاويات مالهاش رقم تتكتب فيه أصلاً.
        $count = (int) ($d['container_count'] ?? 0);
        if ($count < 1) {
            $errors['container_count'] = 'عدد الحاويات لازم يكون حاوية واحدة على الأقل.';
        } elseif ($count > 10000) {
            $errors['container_count'] = 'عدد الحاويات لازم يكون من 1 لـ10000.';
        } elseif ($permit && !isset($errors['movement_id'])) {
            $cap = Permit::billContainerCapacity($permitId, $movementId);
            if ($cap['total'] > 0 && $cap['allocated'] + $count > $cap['total']) {
                $where = $cap['scope'] === 'movement' ? 'الحركة' : 'الإذن';
                $errors['container_count'] = 'العدد ده أكتر من الفاضل في ' . $where
                    . ' (فاضل ' . $cap['left'] . ' من ' . $cap['total'] . ').';
            }
        }

        // ⑤ تصنيف الحاويات — قائمة مقفولة (v58)؛ الفورم `select` لكنه مش مصدر ثقة.
        $ctype = container_norm((string) ($d['container_type'] ?? ''));
        if ($ctype === '') {
            $errors['container_type'] = 'اختار تصنيف الحاويات.';
        } elseif (($fixedCt = self::strictValue('container_type', $ctype)) === null) {
            $errors['container_type'] = 'التصنيف لازم يكون من القائمة — اختار من الموجود.';
        } else {
            $ctype = $fixedCt;
        }

        /* ⑥ أرقام الحاويات — **إجبارية، رقم لكل حاوية** (طلب المالك).
           ⚠️ bootstrap بيلمّ `container_no[]` في نصّ واحد **وبيتجاهل الفاضي**
              (`container_nos_join`)، فعدد الأجزاء بعد التفكيك = عدد الخانات
              اللي المندوب ملاها فعلاً. مقارنته بعدد الحاويات هي اللي بتمسك
              «كتب رقم واحد وسايب التاني فاضي». */
        $containerNo = preg_replace('/\s+/u', ' ', trim((string) ($d['container_no'] ?? '')));
        $nos = array_values(array_filter(
            array_map('trim', container_nos_split($containerNo, 0)),
            static fn ($x) => $x !== ''));
        if (!$nos) {
            $errors['container_no'] = 'اكتب رقم الحاوية.';
        } elseif ($count >= 1 && count($nos) < $count) {
            $missing = $count - count($nos);
            $errors['container_no'] = 'ناقص ' . $missing . ' رقم حاوية — لازم رقم لكل حاوية ('
                                    . $count . ').';
        } elseif (mb_strlen($containerNo) > 120) {
            $errors['container_no'] = 'أرقام الحاويات أطول من المسموح — راجعها.';
        } else {
            foreach ($nos as $one) {
                if (mb_strlen($one) > 40) { $errors['container_no'] = 'رقم الحاوية أطول من 40 حرف.'; break; }
            }
        }

        // ⑦ السائق وصاحب العربية — بيعدّوا على حارس الأسماء (تحذير 3)
        $names = [];
        foreach (self::BILL_NAME_FIELDS as $field => $suggestType) {
            $raw = preg_replace('/\s+/u', ' ', trim((string) ($d[$field] ?? '')));
            if ($raw === '') { $names[$field] = ''; continue; }
            if (mb_strlen($raw) > 150) { $errors[$field] = 'الاسم لازم يكون 150 حرف أو أقل.'; }
            $g = NameSuggestion::resolve($suggestType, $raw);
            if ($g['conflict'] !== null && empty($d['newname_' . $field])) {
                $errors[$field] = 'الاسم «' . $raw . '» قريب جدًا من «' . $g['conflict'] . '» المسجّل عندنا. '
                                . 'اختار الاسم الموجود، أو أكّد إنه اسم جديد فعلاً.';
            }
            $names[$field] = (string) $g['value'];
        }
        if (($names['driver'] ?? '') === '')    { $errors['driver'] = 'اكتب اسم السائق.'; }
        // ⚠️ صاحب العربية **إجباري** (طلب المالك) — كان اختياريًّا.
        if (($names['car_owner'] ?? '') === '') { $errors['car_owner'] = 'اكتب صاحب العربية.'; }

        /* ⑦ب رقم العربية = **٣ حروف + ٤ أرقام**، كل حرف في خانته لوحده.
           ليه مقفول كده: قياس على البيانات الحقيقية لقى **1792 لوحة من 1819**
           بالشكل ده بالظبط (98.5%)، والباقي حالات نادرة قديمة. تقييد شاشة
           المندوب بالشكل ده بيمنع الإدخال الغلط تمامًا، وشاشة المكتب بتفضل
           حرّة للحالات النادرة.
           ⚠️ الناتج المخزَّن `«ح ح ح 1234»` — نفس صيغة `PermitController::
              composeCarNo` بالحرف، وإلا نفس العربية تتقري لوحتين مختلفتين في
              وحدة سيارات المكتب (`OfficeCar::plateKey`). */
        $letters = [];
        foreach (['car_l1', 'car_l2', 'car_l3'] as $k) {
            $c = trim((string) ($d[$k] ?? ''));
            if ($c === '') {
                $errors['car_no'] = 'اكتب حروف العربية التلاتة.';
            } elseif (!preg_match('/^[\x{0621}-\x{064A}]$/u', $c)) {
                $errors['car_no'] = 'كل خانة فيها حرف عربي واحد بس.';
            }
            $letters[] = $c;
        }
        $carDigits = preg_replace('/\D+/', '', ar2en_digits(trim((string) ($d['car_digits'] ?? ''))));
        if ($carDigits === '') {
            $errors['car_no'] = 'اكتب أرقام العربية الأربعة.';
        } elseif (strlen($carDigits) !== 4) {
            $errors['car_no'] = 'أرقام العربية لازم تكون ٤ أرقام بالظبط.';
        }
        $carNo = trim(implode(' ', array_filter($letters)) . ' ' . $carDigits);

        // ⑧ عهدة السائق — الرقم الوحيد اللي المندوب بيدخّله، وبيطلع من محفظته هو
        $rawAmt = strtr(ar2en_digits(trim((string) ($d['custody'] ?? ''))), [
            ',' => '', '٬' => '', ' ' => '', '٫' => '.',
        ]);
        $custody = round((float) $rawAmt, 2);
        if ($rawAmt === '') {
            $errors['custody'] = 'اكتب العهدة اللي سلّمتها للسائق (اكتب 0 لو مسلّمتش حاجة).';
        } elseif ($custody < 0) {
            $errors['custody'] = 'العهدة ما ينفعش تكون بالسالب.';
        } elseif ($custody > self::BILL_MAX_CUSTODY) {
            $errors['custody'] = 'المبلغ أكبر من الحد المسموح.';
        }

        // ⑨ التاريخ = يوم العمل إجباريًّا (v49) — مش من الفورم خالص
        $date = trim((string) ($d['bill_date'] ?? ''));
        if (!self::validDate($date)) {
            $errors['bill_date'] = 'يوم العمل مش مظبوط — افتح الشاشة تاني.';
        } elseif ($custody > 0 && ($lock = RepCustody::dayLockReason($date)) !== null) {
            // العهدة بتتخصم من عهدة يومها، فاليوم المتقفل مرفوض زي أي بند (v49).
            $errors['bill_date'] = $lock;
        }

        if ($errors) { throw new RepSubmissionValidationException($errors); }

        // الخصم الافتراضي من Schema — نفس مصدر شاشة المكتب بالحرف.
        $discField = array_column(\App\Schema::get('bawalis')['fields'], null, 'key')['discount'] ?? [];

        return [
            'permit_id'       => $permitId,
            'permit_no'       => $permit['permit_no'] ?: null,
            'movement_id'     => $movementId,
            'bill_type'       => ($permit['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader',
            'bill_date'       => $date,
            'bill_number'     => $billNo,
            'company'         => $permit['company'] ?: null,
            'company_id'      => $permit['company_id'] ?? null,
            'destination'     => $permit['destination'] ?: null,
            'container_no'    => $containerNo !== '' ? $containerNo : null,
            'container_type'  => $ctype !== '' ? $ctype : ($permit['container_type'] ?? null),
            'container_count' => $count,
            'car_no'          => $carNo,
            'driver'          => $names['driver'],
            'car_owner'       => $names['car_owner'],
            'custody'         => $custody,
            'discount'        => (float) ($discField['default'] ?? 0),
        ];
    }

    /**
     * حذف بوليصة المندوب من موبايله — **بوليصته هو في يوم العمل بس**.
     * غير كده شغل المكتب (البوليصة ممكن يكون اتعمل عليها فاتورة أو سداد).
     */
    public static function deleteMyBill(int $repId, int $billId, string $day): void
    {
        $bill = RepBillCustody::findMine($repId, $billId);
        if (!$bill) {
            throw new RuntimeException('البوليصة دي مش بتاعتك أو المكتب عدّلها — كلّم المكتب.');
        }
        if ((string) $bill['bill_date'] !== $day) {
            throw new RuntimeException('تقدر تمسح بوالص يوم شغلك الحالي بس.');
        }
        if (($lock = RepCustody::dayLockReason($day)) !== null) { throw new RuntimeException($lock); }
        if ((float) ($bill['paid'] ?? 0) > 0) {
            throw new RuntimeException('البوليصة دي عليها سداد — كلّم المكتب.');
        }
        Database::exec('DELETE FROM movements WHERE bill_id = ?', [$billId]);
        Database::exec('DELETE FROM bawalis WHERE id = ?', [$billId]);
        Audit::log('delete', 'bawalis', $billId, $bill, null,
            'حذف بوليصة ' . ($bill['bill_number'] ?: '#' . $billId) . ' — من موبايل المندوب',
            ['permit_id' => $bill['permit_id'] !== null ? (int) $bill['permit_id'] : null,
             'record_date' => $bill['bill_date'], 'rep_id' => $repId]);
    }

    /** بوالص المندوب في يوم العمل — للعرض تحت الفورم. */
    public static function myBills(int $repId, string $day): array
    {
        return RepBillCustody::forRep($repId, 20, $day);
    }

    private static function validDate(string $v): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return false; }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y);
    }
}
