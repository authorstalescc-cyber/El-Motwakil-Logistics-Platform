<?php
namespace App\Models;

use App\Core\Database;

/**
 * فاتورة العميل الخاصة بالأذونات (النظام الأول للفوترة).
 *
 * المنطق:
 *   • الفاتورة تخص إذناً واحداً، ولا تحتوي البوالص كبنود.
 *   • «النولون الفعلي» المسجّل = مجموع نولون بوالص الإذن (للاطلاع فقط).
 *   • «النولون للفاتورة» = رقم جديد يُدخِله المستخدم عند الفوترة (هو ما يظهر ويُحسب عليه).
 *   • بنود المصروفات = المصروفات الفعلية للإذن من permit_stages، بقيمة «السعر المخصّص
 *     للعميل» (actual_amount) وإن كان فارغاً يرجع للمبلغ المدفوع فعلاً (amount).
 *   • ق. مضافة (14%) تُحسب على النولون الجديد فقط، وللفواتير الضريبية الخاضعة فقط.
 *
 * كل فاتورة تُحفَظ كلقطة ثابتة (items_json) فلا تتأثّر بأي تعديل لاحق على الإذن.
 */
class PermitInvoice
{
    public const KIND_INVOICE   = 'invoice';    // فاتورة
    public const KIND_STATEMENT = 'statement';  // كشف حساب
    public const KIND_CLAIM     = 'claim';      // مطالبة بالإيصالات (كل الإيصالات بلا نولون)

    public const KINDS = [
        self::KIND_INVOICE   => 'فاتورة',
        self::KIND_STATEMENT => 'كشف حساب',
        self::KIND_CLAIM     => 'مطالبة بالإيصالات',
    ];

    public const STATUS_DRAFT   = 'draft';
    public const STATUS_ISSUED  = 'issued';
    public const STATUS_PARTIAL = 'partially_paid';
    public const STATUS_PAID    = 'paid';
    public const STATUS_VOID    = 'void';

    public const STATUSES = [
        self::STATUS_DRAFT   => 'مسودة',
        self::STATUS_ISSUED  => 'صادرة',
        self::STATUS_PARTIAL => 'مدفوعة جزئياً',
        self::STATUS_PAID    => 'مدفوعة',
        self::STATUS_VOID    => 'ملغاة',
    ];

    /** النولون الفعلي المسجّل للإذن = مجموع نولون بوالصه (مرجعي فقط). */
    public static function actualFreight(int $permitId): float
    {
        return round((float) Database::scalar(
            'SELECT COALESCE(SUM(freight),0) FROM bawalis WHERE permit_id = ?', [$permitId]
        ), 2);
    }

    /**
     * بنود الإذن المرشّحة للفاتورة: **الإيصالات + الرسوم الأولية فقط**
     * (المصروفات الإضافية `custom` لا تُسحب تلقائياً — بقرار المالك).
     * يُستورَد لكل بند: الكمية (qty) + سعر الوحدة (unit) + الإجمالي (amount)
     * حتى تظهر الكمية والسعر منفصلَين في الفاتورة (لا الإجمالي فقط).
     * تُضاف الرسوم الأولية المحسوبة تلقائياً (كشف + ضمان) فقط إن لم يُسجَّل بند رسوم أولية يدوي.
     *
     * كل عنصر: [key, stage_id, role, label, note, date, qty, unit, amount, auto].
     *   • amount = الإجمالي = قيمة العميل (actual_amount إن وُجد وإلا amount المخزَّن).
     *   • unit   = سعر الوحدة = amount ÷ qty.
     *   • stage_id = معرّف البند في permit_stages (0 للرسوم التلقائية) — يُستخدم للمزامنة العكسية.
     */
    public static function candidateItems(int $permitId): array
    {
        $items = [];
        // الإيصالات والرسوم الأولية فقط (مع استبعاد بنود العلامة «لا يوجد»)
        $rows = Database::all(
            "SELECT * FROM permit_stages s
              WHERE s.permit_id = ? AND s.stage_key IN ('receipts','primary_fees')
                AND s.status <> 'none'
              ORDER BY entry_date, id",
            [$permitId]
        );
        $hasPrimary = false;
        foreach ($rows as $r) {
            $key = $r['stage_key'];
            if ($key === 'primary_fees') { $hasPrimary = true; }
            $qty = max(1, (int)($r['qty'] ?? 1));
            // إجمالي العميل = actual_amount إن وُجد، وإلا المبلغ المخزَّن (كلاهما إجمالي = سعر الوحدة × الكمية)
            $total = ($r['actual_amount'] !== null && $r['actual_amount'] !== '')
                ? (float) $r['actual_amount'] : (float) $r['amount'];
            $unit  = $qty > 0 ? round($total / $qty, 2) : round($total, 2);
            $label = $key === 'receipts'
                ? (trim((string)$r['note']) !== '' ? $r['note'] : 'إيصال')
                : Permit::label($key);
            $items[] = [
                'key'      => $key,
                'stage_id' => (int) $r['id'],
                'role'     => $key === 'primary_fees' ? 'primary' : 'receipt',
                'label'    => $label,
                'note'     => $r['note'],
                'date'     => $r['entry_date'],
                'qty'      => $qty,
                'unit'     => $unit,
                'amount'   => round($total, 2),
                'auto'     => false,
            ];
        }
        // الرسوم الأولية التلقائية (كشف + ضمان) إن لم تُسجَّل يدوياً — عرض فقط (لا تُثبَّت في الإذن)
        if (!$hasPrimary) {
            $def = Permit::primaryFeesDefault($permitId);
            if (($def['containers'] ?? 0) > 0 && !empty($def['rates_set']) && ($def['total'] ?? 0) > 0) {
                $items[] = [
                    'key'      => 'primary_fees',
                    'stage_id' => 0,
                    'role'     => 'primary',
                    'label'    => 'رسوم أولية (كشف + ضمان)',
                    'note'     => 'محسوبة تلقائياً من حاويات الإذن',
                    'date'     => null,
                    'qty'      => 1,
                    'unit'     => round((float)$def['total'], 2),
                    'amount'   => round((float)$def['total'], 2),
                    'auto'     => true,
                ];
            }
        }
        return $items;
    }

    public static function find(int $id): ?array
    {
        self::refreshStatus($id);
        return Database::row('SELECT * FROM permit_invoices WHERE id = ?', [$id]);
    }

    /** كل فواتير إذن معيّن (الأحدث أولاً). */
    public static function forPermit(int $permitId): array
    {
        self::refreshAllStatuses();
        return Database::all(
            'SELECT * FROM permit_invoices WHERE permit_id = ? ORDER BY id DESC', [$permitId]
        );
    }

    /**
     * يبني اللقطة الثابتة (صفّ الفاتورة كما سيُحفَظ) من $data **بلا أي كتابة في القاعدة**.
     * $data يجب أن يحوي: permit_id, doc_kind, taxable, internal_no, invoice_date,
     *   client, company, destination, permit_no, permit_type, agency, port, containers,
     *   vat_rate, note, items (مصفوفة كل عنصر: [label, qty, price, freight(bool)]، total=qty×price،
     *   والبند المعلَّم freight=true هو «النولون» الذي تُحسب عليه الضريبة فقط).
     * تُستخدم من `create()` (للحفظ) ومن `preview()` (للمعاينة قبل الإصدار) فتبقى الحسابات في مكان واحد.
     * يُعيد مصفوفة بنفس أعمدة صفّ `permit_invoices` (بلا id/doc_no اللذين يُولَّدان عند الحفظ).
     */
    public static function buildSnapshot(array $data): array
    {
        $kind     = in_array($data['doc_kind'] ?? '', array_keys(self::KINDS), true)
                    ? $data['doc_kind'] : self::KIND_INVOICE;
        // كشف الحساب والمطالبة بالإيصالات لا يخضعان للضريبة إطلاقاً — الضريبة للفاتورة فقط
        $taxable  = ($kind === self::KIND_INVOICE && !empty($data['taxable'])) ? 1 : 0;
        $vatRate  = $taxable ? (float)($data['vat_rate'] ?? 0) : 0.0;

        // بنود اللقطة: بيان + كمية + سعر + إجمالي (كمية × سعر)
        $lines = []; $subtotal = 0.0; $freightTotal = 0.0;
        foreach (($data['items'] ?? []) as $it) {
            $label = trim((string)($it['label'] ?? ''));
            $qty   = (float)($it['qty'] ?? 1);
            $price = (float)($it['price'] ?? 0);
            $lineTotal = round($qty * $price, 2);
            $isFreight = !empty($it['freight']);
            $source = trim((string)($it['source'] ?? ''));
            if ($label === '' && $lineTotal == 0.0) { continue; }   // تجاهل الصفوف الفارغة
            if ($label === '') { $label = 'بند'; }
            $lines[] = ['label' => $label, 'qty' => $qty, 'price' => round($price, 2),
                        'total' => $lineTotal, 'freight' => $isFreight ? 1 : 0,
                        'source' => $source !== '' ? $source : null];
            $subtotal += $lineTotal;
            if ($isFreight) { $freightTotal += $lineTotal; }
        }
        $subtotal     = round($subtotal, 2);
        $freightTotal = round($freightTotal, 2);
        $vatAmt       = $taxable ? round($freightTotal * $vatRate, 2) : 0.0;
        $total        = round($subtotal + $vatAmt, 2);

        return [
            'permit_id'      => (int)$data['permit_id'],
            'doc_kind'       => $kind,
            'taxable'        => $taxable,
            // رقم الفاتورة اليدوي للفاتورة فقط (إجباري للضريبية، اختياري لغيرها).
            'internal_no'    => $kind === self::KIND_INVOICE ? (trim((string)($data['internal_no'] ?? '')) ?: null) : null,
            'serial_no'      => trim((string)($data['serial_no'] ?? '')) ?: null,
            'custom_title'   => trim((string)($data['custom_title'] ?? '')) ?: null,
            'invoice_date'   => $data['invoice_date'] ?: work_date(),
            'client'         => $data['client'] ?: null,
            'display_client' => trim((string)($data['display_client'] ?? '')) ?: null,
            'company'        => $data['company'] ?: null,
            'destination'    => $data['destination'] ?: null,
            'permit_no'      => $data['permit_no'] ?: null,
            'permit_type'    => $data['permit_type'] ?: null,
            'agency'         => $data['agency'] ?: null,
            'port'           => $data['port'] ?: null,
            'containers'     => (int)($data['containers'] ?? 0),
            'freight'        => $freightTotal,
            'vat_rate'       => $vatRate,
            'vat_amount'     => $vatAmt,
            'subtotal'       => $subtotal,
            'total'          => $total,
            'items_json'     => json_encode($lines, JSON_UNESCAPED_UNICODE),
            'note'           => trim((string)($data['note'] ?? '')) ?: null,
        ];
    }

    /**
     * لقطة معاينة (بلا حفظ): تُعيد صفّاً بشكل الفاتورة المحفوظة صالحاً لعرضه في صفحة الطباعة.
     * id=0 و doc_no فارغ لأن الفاتورة لم تُصدَر بعد.
     */
    public static function preview(array $data): array
    {
        $snap = self::buildSnapshot($data);
        $snap['id']     = 0;
        $snap['doc_no'] = '';
        return $snap;
    }

    /** إنشاء فاتورة/كشف حساب وحفظ لقطتها الثابتة. يُعيد id الفاتورة الجديدة. */
    public static function create(array $data): int
    {
        $snap = self::buildSnapshot($data);

        $agent = PartyMaster::ensure('agent', (string)($snap['client'] ?? ''));
        $company = PartyMaster::ensure('company', (string)($snap['company'] ?? ''));
        $terms = $agent ? (int)($agent['payment_terms_days'] ?? 30) : 30;
        $dueDate = trim((string)($data['due_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $base = $snap['invoice_date'] ?: work_date();
            $dueDate = date('Y-m-d', strtotime($base . ' +' . max(0, $terms) . ' days'));
        }

        Database::exec(
            'INSERT INTO permit_invoices
                (permit_id, doc_kind, status, taxable, internal_no, serial_no, custom_title, invoice_date,due_date,
                 client,agent_id, display_client, company,company_id, destination, permit_no, permit_type, agency, port,
                 containers, freight, vat_rate, vat_amount, subtotal, total,paid_amount,balance_due, items_json, note,issued_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
            [
                $snap['permit_id'], $snap['doc_kind'], self::STATUS_ISSUED, $snap['taxable'],
                $snap['internal_no'], $snap['serial_no'], $snap['custom_title'],
                $snap['invoice_date'], $dueDate, $snap['client'], $agent['id'] ?? null, $snap['display_client'],
                $snap['company'], $company['id'] ?? null, $snap['destination'], $snap['permit_no'], $snap['permit_type'],
                $snap['agency'], $snap['port'], $snap['containers'],
                $snap['freight'], $snap['vat_rate'], $snap['vat_amount'],
                $snap['subtotal'], $snap['total'], 0, $snap['total'], $snap['items_json'], $snap['note'],
            ]
        );
        $id = Database::lastId();
        // رقم مستند نظامي تلقائي ثابت
        Database::exec('UPDATE permit_invoices SET doc_no = ? WHERE id = ?', [self::formatNo($id), $id]);
        Audit::log('issue', 'permit_invoices', $id, null, self::find($id), 'إصدار فاتورة/مستند عميل', [
            'permit_id'=>$snap['permit_id'], 'record_date'=>$snap['invoice_date']
        ]);
        return $id;
    }

    /** هل جدول الفواتير موجود؟ (حماية إن لم تُشغَّل الهجرة بعد) */
    public static function hasTable(): bool
    {
        return (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'permit_invoices'"
        );
    }

    /** هل الرقم المسلسل الخاص بالعميل مستخدَم من قبل؟ (لمنع التكرار) */
    public static function serialExists(string $serial, ?int $exceptId = null): bool
    {
        $serial = trim($serial);
        if ($serial === '' || !self::hasTable()) { return false; }
        $sql = 'SELECT COUNT(*) FROM permit_invoices WHERE serial_no = ?';
        $params = [$serial];
        if ($exceptId) { $sql .= ' AND id <> ?'; $params[] = $exceptId; }
        return (bool) Database::scalar($sql, $params);
    }

    /** فواتير الأذونات المرتبطة باسم (العميل أو الشركة) — لبروفايل الوكيل/الشركة. */
    public static function forName(string $field, string $name): array
    {
        if (!self::hasTable()) { return []; }
        $master = PartyMaster::resolve($field === 'company' ? 'company' : 'agent', $name);
        $idCol = $field === 'company' ? 'company_id' : 'agent_id';
        $col = $field === 'company' ? 'company' : 'client';
        if ($master) {
            return Database::all("SELECT * FROM permit_invoices WHERE $idCol=? ORDER BY id DESC LIMIT 500", [(int)$master['id']]);
        }
        return Database::all(
            "SELECT * FROM permit_invoices WHERE $col = ? ORDER BY id DESC LIMIT 500", [$name]
        );
    }

    /** ملخّص فواتير الأذونات لاسم (العدد + الإجمالي). */
    public static function summaryFor(string $field, string $name): array
    {
        if (!self::hasTable()) { return ['cnt' => 0, 'total' => 0]; }
        self::refreshAllStatuses();
        $master = PartyMaster::resolve($field === 'company' ? 'company' : 'agent', $name);
        $idCol = $field === 'company' ? 'company_id' : 'agent_id';
        $col = $field === 'company' ? 'company' : 'client';
        if ($master) {
            return Database::row(
                "SELECT COUNT(*) cnt,COALESCE(SUM(CASE WHEN status<>'void' THEN total ELSE 0 END),0) total,
                        COALESCE(SUM(balance_due),0) balance_due FROM permit_invoices WHERE $idCol=?",
                [(int)$master['id']]
            ) ?: ['cnt'=>0,'total'=>0,'balance_due'=>0];
        }
        return Database::row(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN status<>'void' THEN total ELSE 0 END),0) AS total,
                    COALESCE(SUM(balance_due),0) balance_due
             FROM permit_invoices WHERE $col = ?", [$name]
        ) ?: ['cnt' => 0, 'total' => 0, 'balance_due'=>0];
    }

    /** خريطة [اسم العميل => إجمالي فواتيره] — لقائمة العملاء (استعلام واحد). */
    public static function totalsByClient(): array
    {
        if (!self::hasTable()) { return []; }
        $out = [];
        foreach (Database::all(
            "SELECT a.name client,COALESCE(SUM(CASE WHEN i.status<>'void' THEN i.total ELSE 0 END),0) v
             FROM permit_invoices i JOIN agents a ON a.id=i.agent_id GROUP BY i.agent_id,a.name
             UNION ALL
             SELECT i.client,COALESCE(SUM(CASE WHEN i.status<>'void' THEN i.total ELSE 0 END),0) v
             FROM permit_invoices i WHERE i.agent_id IS NULL AND i.client IS NOT NULL AND i.client<>'' GROUP BY i.client") as $r) {
            $out[$r['client']] = round(($out[$r['client']] ?? 0)+(float)$r['v'], 2);
        }
        return $out;
    }

    /** رقم المستند النظامي: MT-00001 */
    public static function formatNo(int $id): string
    {
        return 'MT-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    }

    /** بنود اللقطة المحفوظة كمصفوفة PHP. */
    public static function lines(array $invoice): array
    {
        $j = json_decode((string)($invoice['items_json'] ?? ''), true);
        return is_array($j) ? $j : [];
    }

    /** إلغاء محاسبي يحفظ المستند ورقمه ويحرر أي تخصيصات تحصيل مرتبطة. */
    public static function void(int $id, string $reason, ?int $expectedVersion = null): ?int
    {
        $pdo = Database::conn();
        $started = !$pdo->inTransaction();
        if ($started) { $pdo->beginTransaction(); }
        $inv = Database::row('SELECT * FROM permit_invoices WHERE id=? FOR UPDATE', [$id]);
        if (!$inv) { if($started){$pdo->rollBack();} return null; }
        if ($inv['status'] === self::STATUS_VOID) { if($started){$pdo->rollBack();} throw new \RuntimeException('المستند ملغى بالفعل.'); }
        $reason = trim($reason);
        if ($reason === '') { if($started){$pdo->rollBack();} throw new \RuntimeException('سبب الإلغاء مطلوب.'); }
        if ($expectedVersion !== null && (int)$inv['lock_version'] !== $expectedVersion) {
            if($started){$pdo->rollBack();}
            throw new \RuntimeException('تغير المستند منذ فتح الصفحة. أعد التحميل قبل الإلغاء.');
        }
        try {
            $paymentIds = array_map('intval', array_column(Database::all(
                'SELECT DISTINCT payment_id FROM invoice_payment_allocations WHERE invoice_id=?', [$id]
            ), 'payment_id'));
            Database::exec('DELETE FROM invoice_payment_allocations WHERE invoice_id=?', [$id]);
            foreach ($paymentIds as $paymentId) { ClientPayment::refreshAllocation($paymentId); }
            $changed = Database::exec(
                "UPDATE permit_invoices SET status='void',paid_amount=0,balance_due=0,voided_at=NOW(),voided_by=?,void_reason=?,lock_version=lock_version+1 WHERE id=? AND status<>'void'",
                [(int)($_SESSION['uid'] ?? 0) ?: null,$reason,$id]
            );
            if ($changed !== 1) { throw new \RuntimeException('تعذّر إلغاء المستند بسبب تعارض متزامن.'); }
            Audit::log('void','permit_invoices',$id,$inv,self::find($id),'إلغاء مستند مالي: '.$reason,['permit_id'=>$inv['permit_id']]);
            if ($started) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return (int)$inv['permit_id'];
    }

    /** توافق خلفي: لا حذف فعلياً؛ يلزم سبب صريح عبر void(). */
    public static function delete(int $id): ?int
    {
        return self::void($id, 'إلغاء من المسار القديم');
    }

    public static function statusLabel(string $status, ?string $dueDate = null): string
    {
        if ($status === self::STATUS_ISSUED && $dueDate && $dueDate < work_date()) { return 'متأخرة'; }
        return self::STATUSES[$status] ?? $status;
    }

    public static function openForAgent(int $agentId): array
    {
        self::refreshAllStatuses();
        return Database::all(
            "SELECT id,doc_no,invoice_date,due_date,total,paid_amount,balance_due,status
             FROM permit_invoices WHERE agent_id=? AND status IN ('issued','partially_paid') AND balance_due>0
             ORDER BY (due_date IS NULL),due_date,id", [$agentId]
        );
    }

    public static function refreshStatus(int $id): void
    {
        if ($id <= 0 || !self::hasTable()) { return; }
        $paid = (float)Database::scalar(
            "SELECT COALESCE(SUM(a.amount),0) FROM invoice_payment_allocations a
             JOIN client_payments p ON p.id=a.payment_id AND p.status='posted' WHERE a.invoice_id=?", [$id]
        );
        Database::exec(
            "UPDATE permit_invoices SET paid_amount=?,balance_due=CASE WHEN status='void' THEN 0 ELSE GREATEST(total-?,0) END,
             status=CASE WHEN status='void' THEN 'void' WHEN ? >= total-0.005 THEN 'paid'
                         WHEN ?>0.005 THEN 'partially_paid' WHEN status='draft' THEN 'draft' ELSE 'issued' END
             WHERE id=?", [$paid,$paid,$paid,$paid,$id]
        );
    }

    public static function refreshAllStatuses(): void
    {
        if (!self::hasTable()) { return; }
        Database::exec(
            "UPDATE permit_invoices i LEFT JOIN (
                SELECT a.invoice_id,SUM(a.amount) paid FROM invoice_payment_allocations a
                JOIN client_payments p ON p.id=a.payment_id AND p.status='posted' GROUP BY a.invoice_id
             ) x ON x.invoice_id=i.id
             SET i.paid_amount=COALESCE(x.paid,0),i.balance_due=IF(i.status='void',0,GREATEST(i.total-COALESCE(x.paid,0),0)),
                 i.status=CASE WHEN i.status='void' THEN 'void' WHEN i.status='draft' THEN 'draft'
                    WHEN COALESCE(x.paid,0)>=i.total-0.005 THEN 'paid'
                    WHEN COALESCE(x.paid,0)>0.005 THEN 'partially_paid' ELSE 'issued' END"
        );
    }

    public static function kindLabel(string $kind): string
    {
        return self::KINDS[$kind] ?? $kind;
    }
}
