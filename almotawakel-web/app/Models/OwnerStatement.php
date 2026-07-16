<?php
namespace App\Models;

use App\Core\Database;

/**
 * كشف حساب صاحب البيان (النظام الثاني للفوترة).
 *
 * المنطق:
 *   • الكشف يخصّ «صاحب بيان» واحداً (bawalis.declaration_owner).
 *   • المبالغ تُؤخذ من البوالص مباشرةً (debt/paid/remaining) — لا إدخال يدوي.
 *   • المستند بجزئين:
 *       (أ) ملخّص واحد للبوالص التي استلم عليها (عدد + إجمالي مدفوع فقط).
 *       (ب) البوالص الباقية له (remaining > 0) — «فاضل كام» بكافة التفاصيل.
 *     + ملخّص علوي بإجمالي دَينه/مستلَمه/باقيه (كل بوالصه وقت الإصدار).
 *   • بلا ضريبة إطلاقاً — مستند تشغيلي.
 *
 * كل كشف يُحفَظ كلقطة ثابتة (items_json) فلا يتأثّر بأي تعديل لاحق على البوالص أو الدفعات.
 *
 * ملاحظة الاتجاه: الدين «علينا» لصاحب البيان (نحن مدينون له). «استلم» = ما صرفناه له.
 */
class OwnerStatement
{
    /** أعمدة البوليصة التي تُلتقط في اللقطة */
    private const BILL_COLS =
        'id, bill_type, bill_date, bill_number, cert_number, permit_no,
         declaration_owner, company, destination, classification, container_no,
         container_type, container_count, car_no, driver, freight, custody, transfer, discount,
         overnight_collection, debt, paid, remaining, pay_status, is_legacy';

    // --------------------------------------------------------------- الاسم/الشرط
    /** شرط مطابقة صاحب البيان: يرجّع [SQL, params, label] */
    public static function ownerClause(string $owner): array
    {
        $key = trim($owner);
        if ($key === '' || $key === 'None') {
            return ["(declaration_owner IS NULL OR TRIM(declaration_owner) = '')", [], '(بدون صاحب بيان)'];
        }
        $master = PartyMaster::resolve('owner', $key);
        if ($master) {
            return [
                '(declaration_owner_id = ? OR (declaration_owner_id IS NULL AND TRIM(declaration_owner) = ?))',
                [(int)$master['id'], $key],
                $master['name'],
            ];
        }
        return ['TRIM(declaration_owner) = ?', [$key], $key];
    }

    /** شرط كشوف صاحب البيان المحفوظة، مع دعم الاسم الأساسي وكل aliases. */
    private static function statementOwnerClause(string $owner): array
    {
        $key = trim($owner);
        $master = PartyMaster::resolve('owner', $key);
        if ($master) {
            return [
                '(declaration_owner_id = ? OR (declaration_owner_id IS NULL AND owner = ?))',
                [(int)$master['id'], $key],
            ];
        }
        return ['owner = ?', [$key]];
    }

    /** إحصاءات صاحب البيان الكاملة (كل بوالصه) — أساس ملخّص الكشف. */
    public static function ownerStats(string $owner): array
    {
        [$cond, $params] = self::ownerClause($owner);
        return Database::row(
            "SELECT COUNT(*)                                AS bills,
                    SUM(pay_status='unpaid')                AS unpaid_bills,
                    SUM(pay_status='partial')               AS partial_bills,
                    SUM(pay_status='paid')                  AS paid_bills,
                    SUM(pay_status='pending')               AS pending_bills,
                    SUM(pay_status IN ('unpaid','partial')) AS due_bills,
                    COALESCE(SUM(debt),0)                   AS debt,
                    COALESCE(SUM(paid),0)                   AS paid,
                    COALESCE(SUM(remaining),0)              AS remaining,
                    MIN(bill_date)                          AS first_date,
                    MAX(bill_date)                          AS last_date
             FROM bawalis WHERE $cond",
            $params
        ) ?: ['bills'=>0,'due_bills'=>0,'debt'=>0,'paid'=>0,'remaining'=>0,'first_date'=>null,'last_date'=>null];
    }

    /**
     * بيانات الكشف المرشّحة (حيّة لعرض النموذج):
     *   'paid_summary' = ملخّص البوالص التي استلم عليها (paid > 0) بلا تحميل سطورها.
     *   'remaining' = البوالص الباقية له = غير المسدّدة بالكامل (pay_status: unpaid/partial)،
     *                 الأقدم أولاً — متوافقة مع تعريف «المتبقّي» في وحدة سداد البوالص،
     *                 فلا تُسقَط بوالص دَينها صفر أو سالب (بدل الاعتماد على إشارة remaining).
     * البوليصة المدفوعة جزئياً تظهر في القائمتين (استلم جزءاً وباقٍ له جزء) — وهذا مقصود.
     */
    public static function candidates(string $owner): array
    {
        [$cond, $params] = self::ownerClause($owner);

        $paidSummary = Database::row(
            "SELECT COUNT(*) AS count, COALESCE(SUM(paid),0) AS paid
               FROM bawalis WHERE $cond AND paid > 0.005",
            $params
        ) ?: ['count'=>0, 'paid'=>0];
        $remaining = Database::all(
            'SELECT ' . self::BILL_COLS . "
             FROM bawalis
             WHERE $cond AND pay_status IN ('unpaid','partial')
             ORDER BY bill_date, id
             LIMIT 1000",
            $params
        );
        return ['paid_summary' => $paidSummary, 'remaining' => $remaining];
    }

    /** كل بوالص صاحب البيان للـ drill-down في بطاقات ملخص شاشة إصدار الكشف. */
    public static function billsForOwner(string $owner): array
    {
        [$cond, $params] = self::ownerClause($owner);
        return Database::all(
            'SELECT ' . self::BILL_COLS . "
               FROM bawalis
              WHERE $cond
              ORDER BY bill_date DESC, id DESC
              LIMIT 1000",
            $params
        );
    }

    /** يحوّل صف بوليصة (من DB) إلى سطر لقطة نظيف. */
    private static function billLine(array $b): array
    {
        return [
            'type'            => $b['bill_type'] ?? null,
            'date'            => $b['bill_date'] ?? null,
            'number'          => ($b['bill_number'] ?? '') ?: ($b['cert_number'] ?? ''),
            'permit_no'       => $b['permit_no'] ?? null,
            'company'         => $b['company'] ?? null,
            'destination'     => $b['destination'] ?? null,
            'classification'  => $b['classification'] ?? null,
            'container_no'    => $b['container_no'] ?? null,
            'container_type'  => $b['container_type'] ?? null,
            'container_count' => (int)($b['container_count'] ?? 0),
            'freight'         => round((float)($b['freight'] ?? 0), 2),
            'custody'         => round((float)($b['custody'] ?? 0), 2),
            'transfer'        => round((float)($b['transfer'] ?? 0), 2),
            'discount'        => round((float)($b['discount'] ?? 0), 2),
            'overnight_collection' => round((float)($b['overnight_collection'] ?? 0), 2),
            'debt'            => round((float)($b['debt'] ?? 0), 2),
            'paid'            => round((float)($b['paid'] ?? 0), 2),
            'remaining'       => round((float)($b['remaining'] ?? 0), 2),
            'status'          => $b['pay_status'] ?? null,
            'is_legacy'       => (int)($b['is_legacy'] ?? 0),
            'last_pay_date'   => $b['last_pay_date'] ?? null,
        ];
    }

    /**
     * إنشاء كشف حساب وحفظ لقطته الثابتة.
     * $data يجب أن يحوي: owner, statement_date, note,
     *   include_paid_summary (إدراج ملخّص كل البوالص المستلَمة)،
     *   remaining_ids (مصفوفة معرّفات البوالص الباقية المختارة).
     * الملخّص العلوي (stat_*) يُلتقط من كل بوالص صاحب البيان وقت الإصدار (لا من المختار فقط).
     * يُعيد id الكشف الجديد.
     */
    public static function create(array $data): int
    {
        $owner   = trim((string)($data['owner'] ?? ''));
        $stats   = self::ownerStats($owner);

        $paidIds = array_values(array_unique(array_map('intval', (array)($data['paid_ids'] ?? []))));
        $remIds  = array_values(array_unique(array_map('intval', (array)($data['remaining_ids'] ?? []))));
        $includePaid = !empty($data['include_paid_summary']);

        [$ownerCond, $ownerParams] = self::ownerClause($owner);

        // البوالص التي استلم عليها تُحفَظ كملخّص واحد؛ يمنع تضخم الكشف مع مئات البوالص
        // المسددة، مع الاحتفاظ بالعدد وإجمالي ما استلمه صاحب البيان فقط.
        $paidSummary = ['count' => 0, 'paid' => 0.0];
        if ($includePaid) {
            $paidSummary = Database::row(
                "SELECT COUNT(*) AS count, COALESCE(SUM(paid),0) AS paid
                   FROM bawalis WHERE $ownerCond AND paid > 0.005",
                $ownerParams
            ) ?: $paidSummary;
            $paidSummary = [
                'count'   => (int)$paidSummary['count'],
                'paid'    => round((float)$paidSummary['paid'], 2),
            ];
        } elseif ($paidIds) {
            $ph = implode(',', array_fill(0, count($paidIds), '?'));
            $paidSummary = Database::row(
                "SELECT COUNT(*) AS count, COALESCE(SUM(paid),0) AS paid
                   FROM bawalis
                  WHERE id IN ($ph) AND $ownerCond AND paid > 0.005",
                array_merge($paidIds, $ownerParams)
            ) ?: $paidSummary;
            $paidSummary = [
                'count'   => (int)$paidSummary['count'],
                'paid'    => round((float)$paidSummary['paid'], 2),
            ];
        }

        // لقطة القائمة الباقية
        $remLines = []; $remSum = 0.0;
        if ($remIds) {
            $ph = implode(',', array_fill(0, count($remIds), '?'));
            $rows = Database::all(
                'SELECT ' . self::BILL_COLS . " FROM bawalis
                  WHERE id IN ($ph) AND $ownerCond AND pay_status IN ('unpaid','partial')
                  ORDER BY bill_date, id",
                array_merge($remIds, $ownerParams)
            );
            foreach ($rows as $r) { $line = self::billLine($r); $remLines[] = $line; $remSum += $line['remaining']; }
        }

        $items = ['paid_summary' => $paidSummary, 'remaining' => $remLines];

        $ownerMaster = PartyMaster::ensure('owner',$owner);
        Database::exec(
            'INSERT INTO owner_statements
                (statement_date,status, owner,declaration_owner_id, stat_bills, stat_debt, stat_paid, stat_remaining,
                 paid_count, paid_sum, remaining_count, remaining_sum, items_json, note)
             VALUES (?,\'issued\',?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                ($data['statement_date'] ?? '') ?: work_date(),
                $ownerMaster['name'] ?? ($owner ?: null),
                $ownerMaster['id'] ?? null,
                (int)($stats['bills'] ?? 0),
                round((float)($stats['debt'] ?? 0), 2),
                round((float)($stats['paid'] ?? 0), 2),
                round((float)($stats['remaining'] ?? 0), 2),
                $paidSummary['count'], $paidSummary['paid'],
                count($remLines), round($remSum, 2),
                json_encode($items, JSON_UNESCAPED_UNICODE),
                trim((string)($data['note'] ?? '')) ?: null,
            ]
        );
        $id = Database::lastId();
        Database::exec('UPDATE owner_statements SET doc_no = ? WHERE id = ?', [self::formatNo($id), $id]);
        Audit::log('issue','owner_statements',$id,null,self::find($id),'إصدار كشف صاحب بيان',['record_date'=>$data['statement_date'] ?? work_date()]);
        return $id;
    }

    public static function find(int $id): ?array
    {
        return Database::row('SELECT * FROM owner_statements WHERE id = ?', [$id]);
    }

    /** كل كشوف صاحب بيان معيّن (الأحدث أولاً). */
    public static function forOwner(string $owner): array
    {
        if (!self::hasTable()) { return []; }
        [$cond, $params] = self::statementOwnerClause($owner);
        return Database::all("SELECT * FROM owner_statements WHERE $cond ORDER BY id DESC", $params);
    }

    /** كشوف مرتبطة باسم (لبروفايل الوكيل — قد يكون صاحب بيان). */
    public static function forName(string $name): array
    {
        if (!self::hasTable()) { return []; }
        [$cond, $params] = self::statementOwnerClause($name);
        return Database::all("SELECT * FROM owner_statements WHERE $cond ORDER BY id DESC LIMIT 500", $params);
    }

    /** ملخّص كشوف اسم (العدد + آخر باقٍ مسجّل). */
    public static function summaryFor(string $name): array
    {
        if (!self::hasTable()) { return ['cnt' => 0, 'last_remaining' => 0]; }
        [$cond, $params] = self::statementOwnerClause($name);
        return Database::row(
            "SELECT COUNT(*) AS cnt,
                    COALESCE((SELECT stat_remaining FROM owner_statements
                              WHERE $cond ORDER BY id DESC LIMIT 1),0) AS last_remaining
             FROM owner_statements WHERE $cond", array_merge($params, $params)
        ) ?: ['cnt' => 0, 'last_remaining' => 0];
    }

    /** هل جدول الكشوف موجود؟ (حماية إن لم تُشغَّل الهجرة بعد) */
    public static function hasTable(): bool
    {
        return (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'owner_statements'"
        );
    }

    /** رقم المستند النظامي: MT-S-00001 (تمييزاً عن فواتير الأذونات MT-00001). */
    public static function formatNo(int $id): string
    {
        return 'MT-S-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    }

    /** بيانات اللقطة المحفوظة، مع توافق رجعي للكشوف القديمة ذات سطور paid التفصيلية. */
    public static function lines(array $statement): array
    {
        $j = json_decode((string)($statement['items_json'] ?? ''), true);
        return [
            'paid'      => is_array($j['paid'] ?? null) ? $j['paid'] : [],
            'paid_summary' => is_array($j['paid_summary'] ?? null) ? $j['paid_summary'] : null,
            'remaining' => is_array($j['remaining'] ?? null) ? $j['remaining'] : [],
        ];
    }

    /** أصحاب البيان المرشحون للطباعة المجمعة. الافتراضي: أصحاب المديونية الموجبة فقط. */
    public static function ownersForBatch(bool $dueOnly = true): array
    {
        $having = $dueOnly ? 'HAVING SUM(remaining)>0.005' : '';
        return array_column(Database::all(
            "SELECT COALESCE(o.name,TRIM(b.declaration_owner)) owner
               FROM bawalis b LEFT JOIN declaration_owners o ON o.id=b.declaration_owner_id
              WHERE b.declaration_owner_id IS NOT NULL
                 OR (b.declaration_owner IS NOT NULL AND TRIM(b.declaration_owner)<>'')
              GROUP BY b.declaration_owner_id,COALESCE(o.name,TRIM(b.declaration_owner)) $having
              ORDER BY owner"
        ), 'owner');
    }

    /** يبني كشفًا حيًا في الذاكرة للطباعة المجمعة، بلا إنشاء سجلات أو تغيير حسابات. */
    public static function liveDocument(string $owner, string $date): array
    {
        $stats=self::ownerStats($owner);$cands=self::candidates($owner);$rem=[];$remSum=0.0;
        foreach($cands['remaining'] as $row){$line=self::billLine($row);$rem[]=$line;$remSum+=(float)$line['remaining'];}
        $paid=['count'=>(int)($cands['paid_summary']['count']??0),'paid'=>round((float)($cands['paid_summary']['paid']??0),2)];
        return [
            'id'=>0,'doc_no'=>null,'statement_date'=>$date,'owner'=>$owner,'note'=>null,
            'stat_bills'=>(int)($stats['bills']??0),'stat_debt'=>round((float)($stats['debt']??0),2),
            'stat_paid'=>round((float)($stats['paid']??0),2),'stat_remaining'=>round((float)($stats['remaining']??0),2),
            'paid_count'=>$paid['count'],'paid_sum'=>$paid['paid'],'remaining_count'=>count($rem),'remaining_sum'=>round($remSum,2),
            'items_json'=>json_encode(['paid_summary'=>$paid,'remaining'=>$rem],JSON_UNESCAPED_UNICODE),
        ];
    }

    public static function delete(int $id): bool
    {
        return self::void($id,'إلغاء من المسار القديم');
    }

    public static function void(int $id,string $reason,?int $expectedVersion=null): bool
    {
        $row=self::find($id);if(!$row)return false;
        if($row['status']==='void')throw new \RuntimeException('الكشف ملغى بالفعل.');
        $reason=trim($reason);if($reason==='')throw new \RuntimeException('سبب الإلغاء مطلوب.');
        if($expectedVersion!==null&&(int)$row['lock_version']!==$expectedVersion)throw new \RuntimeException('تغير الكشف منذ فتح الصفحة. أعد التحميل.');
        $changed=Database::exec("UPDATE owner_statements SET status='void',voided_at=NOW(),voided_by=?,void_reason=?,lock_version=lock_version+1 WHERE id=? AND status<>'void'",[(int)($_SESSION['uid']??0)?:null,$reason,$id]);
        if($changed!==1)throw new \RuntimeException('تعذّر إلغاء الكشف بسبب تعارض متزامن.');
        Audit::log('void','owner_statements',$id,$row,self::find($id),'إلغاء كشف صاحب بيان: '.$reason,['record_date'=>$row['statement_date']]);
        return true;
    }
}
