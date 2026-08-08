<?php
namespace App\Models;

use App\Core\Database;

/**
 * دفعات سداد العملاء (الوكلاء) + توجيهها للعهدة.
 *
 * اتجاه الفلوس هنا: العميل **يسلّم** الشركة مبلغًا سدادًا على حسابه (داخل للشركة).
 * ويمكن «توجيه» المبلغ لأحد رصيدَي العهدة:
 *   • route='cash' → إضافة عهدة يدوية (manual_custody) بتاريخ الدفعة → تزيد عهدة اليوم
 *                    وتدخل التقفيل اليومي. تحترم قاعدة الإقفال (Closing::guard) في الكنترولر.
 *   • route='visa' → شحنة رصيد (visa_deposits) على كارت مختار → تزيد رصيد الكارت.
 *   • route='company' → حركة في **خزنة الشركة** (company_safe_entries، v66) → تزيد رصيدها.
 *                    ⚠️ **بلا Closing::guard** عن قصد: الفلوس دخلت خزنة الشركة مش الدرج،
 *                    فمفيش رصيد مثبَّت في daily_closings بيتغيّر — زي route='visa' بالظبط.
 *   • route='none' → تُسجَّل في حساب العميل فقط بلا مساس بأي عهدة.
 *
 * الدفعة هي مصدر الحقيقة؛ السجل المُولَّد في manual_custody/visa_deposits/company_safe_entries
 * مربوط عبر custody_id/deposit_id/company_entry_id ليُعكس معها. الربط الأساسي بالوكيل
 * agent_id، مع إبقاء الاسم كلقطة.
 *
 * كشف الحساب: المطلوب من العميل = فواتير الأذونات (+ شامل إن وُجد) ناقص مجموع الدفعات.
 */
class ClientPayment
{
    public const ROUTES = [
        'none'    => 'بدون توجيه',
        'cash'    => 'عهدة نقدية (عهدة اليوم)',
        'visa'    => 'شحن كارت فيزا',
        'company' => 'خزنة الشركة',
    ];

    public static function routeLabel(string $route): string
    {
        return self::ROUTES[$route] ?? $route;
    }

    /**
     * ⚠️⚠️ **المصدر الوحيد لسؤال «أنهي توجيه متاح دلوقتي؟»** — لازم تقرا منه
     *    الشاشتان (`payments/index` · `directory/agent`) **والكنترولران**
     *    (`PaymentController::pay` · `DirectoryController::addPayment`) مع بعض.
     *    لو الشاشة عرضت خيارًا والكنترولر رفضه، المستخدم بيختار ويلاقي دفعته
     *    اتسجّلت «بدون توجيه» **بلا أي رسالة** — وده أسوأ من منع الخيار أصلاً.
     *
     * ⚠️ `company` بتختفي قبل migrate_v66 — الجدول مش موجود فالحفظ كان هيرمي.
     *
     * @return array<string,string>  [المفتاح => التسمية]
     */
    public static function routeChoices(): array
    {
        $out = self::ROUTES;
        if (!CompanySafe::ready()) { unset($out['company']); }
        return $out;
    }

    private static function hasTable(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'client_payments'");
        }
        return $ok;
    }

    // ─────────────────────────────────────────────── قراءة

    public static function find(int $id): ?array
    {
        if (!self::hasTable()) { return null; }
        return Database::row("SELECT * FROM client_payments WHERE id = ?", [$id]);
    }

    /** دفعات عميل (بالاسم) مرتّبة من الأحدث للأقدم. */
    public static function forClient(string $name): array
    {
        if (!self::hasTable()) { return []; }
        $agent = PartyMaster::resolve('agent', $name);
        if ($agent) {
            return Database::all(
                "SELECT * FROM client_payments WHERE agent_id=?
                 ORDER BY (pay_date IS NULL),pay_date DESC,id DESC", [(int)$agent['id']]
            );
        }
        return Database::all(
            "SELECT * FROM client_payments WHERE client_name = ?
             ORDER BY (pay_date IS NULL), pay_date DESC, id DESC", [$name]);
    }

    /** خريطة [اسم العميل => إجمالي دفعاته] — لقائمة العملاء (استعلام واحد). */
    public static function totalsByClient(): array
    {
        if (!self::hasTable()) { return []; }
        $out = [];
        foreach (Database::all(
            "SELECT a.name client_name,COALESCE(SUM(CASE WHEN p.status='posted' THEN p.amount ELSE 0 END),0) v
             FROM client_payments p JOIN agents a ON a.id=p.agent_id GROUP BY p.agent_id,a.name
             UNION ALL
             SELECT p.client_name,COALESCE(SUM(CASE WHEN p.status='posted' THEN p.amount ELSE 0 END),0) v
             FROM client_payments p WHERE p.agent_id IS NULL GROUP BY p.client_name") as $r) {
            $out[$r['client_name']] = round(($out[$r['client_name']] ?? 0)+(float)$r['v'], 2);
        }
        return $out;
    }

    /** مجموع دفعات عميل. */
    public static function totalFor(string $name): float
    {
        if (!self::hasTable()) { return 0.0; }
        $agent = PartyMaster::resolve('agent', $name);
        if ($agent) {
            return round((float)Database::scalar(
                "SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE agent_id=? AND status='posted'", [(int)$agent['id']]
            ), 2);
        }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE client_name=? AND status='posted'", [$name]), 2);
    }

    /** شرط WHERE للصفحة المستقلة (عميل/تاريخ/توجيه/بحث). */
    private static function filterWhere(array $f): array
    {
        $conds = []; $params = [];
        if (!empty($f['client'])) {
            $agent = PartyMaster::resolve('agent', $f['client']);
            if ($agent) { $conds[]='agent_id=?'; $params[]=(int)$agent['id']; }
            else { $conds[]='client_name=?'; $params[]=$f['client']; }
        }
        if (!empty($f['route']) && isset(self::ROUTES[$f['route']])) { $conds[] = "route = ?"; $params[] = $f['route']; }
        if (!empty($f['from'])) { $conds[] = "pay_date >= ?"; $params[] = $f['from']; }
        if (!empty($f['to']))   { $conds[] = "pay_date <= ?"; $params[] = $f['to']; }
        if (!empty($f['q'])) {
            $conds[] = "(client_name LIKE ? OR note LIKE ?)";
            $like = '%' . $f['q'] . '%'; $params[] = $like; $params[] = $like;
        }
        return [$conds ? 'WHERE ' . implode(' AND ', $conds) : '', $params];
    }

    /** كل الدفعات عبر العملاء (للصفحة المستقلة) مرتّبة بالأحدث. */
    public static function all(array $f = [], int $limit = 500): array
    {
        if (!self::hasTable()) { return []; }
        [$where, $params] = self::filterWhere($f);
        $limit = max(1, $limit);
        return Database::all(
            "SELECT * FROM client_payments $where
             ORDER BY (pay_date IS NULL), pay_date DESC, id DESC LIMIT $limit", $params);
    }

    /** إجماليات الصفحة المستقلة: الكلّي + لكل توجيه + العدد (يحترم الفلاتر). */
    public static function summary(array $f = []): array
    {
        $out = ['grand' => 0.0, 'count' => 0, 'by_route' => []];
        foreach (self::ROUTES as $k => $lbl) { $out['by_route'][$k] = ['label' => $lbl, 'value' => 0.0, 'count' => 0]; }
        if (!self::hasTable()) { return $out; }
        [$where, $params] = self::filterWhere($f);
        foreach (Database::all(
            "SELECT route, COALESCE(SUM(CASE WHEN status='posted' THEN amount ELSE 0 END),0) v,
                    SUM(status='posted') c
             FROM client_payments $where GROUP BY route", $params) as $r) {
            $k = $r['route'];
            if (!isset($out['by_route'][$k])) { $out['by_route'][$k] = ['label' => $k, 'value' => 0.0, 'count' => 0]; }
            $out['by_route'][$k]['value'] = round((float) $r['v'], 2);
            $out['by_route'][$k]['count'] = (int) $r['c'];
            $out['grand'] += (float) $r['v'];
            $out['count'] += (int) $r['c'];
        }
        $out['grand'] = round($out['grand'], 2);
        return $out;
    }

    // ─────────────────────────────────────────────── كتابة (مع التوجيه)

    /**
     * إنشاء دفعة + توليد السجل المرتبط حسب التوجيه.
     * $d: client_name, pay_date, amount, note, route, visa_card_id.
     * يفترض أن الكنترولر تحقّق من Closing::guard قبل النداء لو route='cash'.
     */
    public static function create(array $d): int
    {
        $route  = in_array($d['route'] ?? 'none', ['cash', 'visa', 'company'], true) ? $d['route'] : 'none';
        $name   = trim((string) ($d['client_name'] ?? ''));
        $date   = ($d['pay_date'] ?? '') ?: null;
        $amount = (float) ($d['amount'] ?? 0);
        $note   = trim((string) ($d['note'] ?? ''));
        $cardId = (int) ($d['visa_card_id'] ?? 0);

        $agent = !empty($d['agent_id']) ? PartyMaster::find('agent',(int)$d['agent_id']) : PartyMaster::ensure('agent',$name);
        if (!$agent) { throw new \RuntimeException('العميل غير موجود في البيانات الأساسية.'); }
        $name = $agent['name'];

        $custodyId = null; $depositId = null; $visaCardId = null; $companyEntryId = null;
        $pdo = Database::conn();
        $started = !$pdo->inTransaction();
        if ($started) { $pdo->beginTransaction(); }
        try {
            if ($route === 'company') {
                /* ⚠️ **بلا `Closing::guard`** عن قصد: الفلوس دخلت خزنة الشركة
                   (رصيد جارٍ بلا إقفال) مش الدرج، فمفيش رصيد مثبَّت بيتغيّر —
                   نفس منطق `route='visa'` بالحرف. */
                if (!CompanySafe::ready()) {
                    throw new \RuntimeException('خزنة الشركة مش مفعّلة. شغّل php bin/migrate_v66.php أولاً.');
                }
                $companyEntryId = CompanySafe::createFromClient($name, $date, $amount, $note);
            } elseif ($route === 'cash') {
                $custodyId = Custody::createTopup([
                    'cust_date' => $date,
                    'driver'    => $name,
                    'amount'    => $amount,
                    'note'      => 'تحصيل من عميل' . ($note !== '' ? ' — ' . $note : ''),
                ]);
            } elseif ($route === 'visa' && $cardId > 0) {
                $visaCardId = $cardId;
                $depositId  = VisaCard::createDeposit($cardId, [
                    'dep_date' => $date,
                    'amount'   => $amount,
                    'note'     => 'تحصيل من عميل: ' . $name . ($note !== '' ? ' — ' . $note : ''),
                ]);
            } else {
                $route = 'none';
            }

            Database::exec(
                "INSERT INTO client_payments
                    (client_name,agent_id,pay_date,amount,status,unallocated_amount,note,route,custody_id,deposit_id,visa_card_id,company_entry_id)
                 VALUES (?,?,?,?, 'posted', ?,?,?,?,?,?,?)",
                [$name,(int)$agent['id'],$date,$amount,$amount,$note!==''?$note:null,$route,$custodyId,$depositId,$visaCardId,$companyEntryId]
            );
            $id = Database::lastId();
            $invoiceId = (int)($d['invoice_id'] ?? 0);
            if ($invoiceId > 0) { self::allocate($id,$invoiceId,$amount); }
            elseif (!empty($d['auto_allocate'])) { self::autoAllocate($id); }
            Audit::log('create','client_payments',$id,null,self::find($id),'تسجيل تحصيل من العميل',[
                'record_date'=>$date,'agent_id'=>(int)$agent['id']
            ]);
            if ($started) { $pdo->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * حذف دفعة + السجل المرتبط بها (عهدة يدوية / شحنة فيزا / حركة خزنة شركة).
     * يفترض أن الكنترولر تحقّق من Closing::guard على تاريخ العهدة لو route='cash'.
     */
    public static function reverse(int $id, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') { throw new \RuntimeException('سبب العكس مطلوب.'); }
        $pdo = Database::conn();
        $started = !$pdo->inTransaction();
        if ($started) { $pdo->beginTransaction(); }
        try {
            $row = Database::row('SELECT * FROM client_payments WHERE id=? FOR UPDATE',[$id]);
            if (!$row) { throw new \RuntimeException('التحصيل غير موجود.'); }
            if ($row['status'] === 'reversed') { throw new \RuntimeException('تم عكس هذا التحصيل من قبل.'); }
            $invoiceIds = array_map('intval',array_column(Database::all(
                'SELECT DISTINCT invoice_id FROM invoice_payment_allocations WHERE payment_id=?',[$id]
            ),'invoice_id'));
            Database::exec('DELETE FROM invoice_payment_allocations WHERE payment_id=?',[$id]);
            if ($row['route']==='cash' && $row['custody_id']) { Custody::deleteTopup((int)$row['custody_id']); }
            elseif ($row['route']==='visa' && $row['deposit_id']) { VisaCard::deleteDeposit((int)$row['deposit_id']); }
            // ⚠️ لازم يتشال هنا كمان وإلا خزنة الشركة تفضل فيها فلوس تحصيل اتعكس.
            elseif ($row['route']==='company' && !empty($row['company_entry_id'])) {
                CompanySafe::deleteRaw((int)$row['company_entry_id']);
            }
            Database::exec(
                "UPDATE client_payments SET status='reversed',unallocated_amount=0,reversed_at=NOW(),reversed_by=?,
                 reverse_reason=?,lock_version=lock_version+1 WHERE id=? AND status='posted'",
                [(int)($_SESSION['uid'] ?? 0) ?: null,$reason,$id]
            );
            foreach ($invoiceIds as $invoiceId) { PermitInvoice::refreshStatus($invoiceId); }
            Audit::log('reverse','client_payments',$id,$row,self::find($id),'عكس تحصيل: '.$reason,['record_date'=>$row['pay_date']]);
            if ($started) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    public static function delete(int $id): void
    {
        self::reverse($id,'عكس من المسار القديم');
    }

    public static function allocate(int $paymentId, int $invoiceId, float $amount): void
    {
        $payment = Database::row('SELECT * FROM client_payments WHERE id=? FOR UPDATE',[$paymentId]);
        $invoice = Database::row('SELECT * FROM permit_invoices WHERE id=? FOR UPDATE',[$invoiceId]);
        if (!$payment || $payment['status']!=='posted') { throw new \RuntimeException('التحصيل غير صالح للتخصيص.'); }
        if (!$invoice || !in_array($invoice['status'],['issued','partially_paid'],true)) { throw new \RuntimeException('الفاتورة غير قابلة للتحصيل.'); }
        if ((int)$payment['agent_id'] !== (int)$invoice['agent_id']) { throw new \RuntimeException('لا يمكن تخصيص تحصيل عميل على فاتورة عميل آخر.'); }
        self::refreshAllocation($paymentId);
        PermitInvoice::refreshStatus($invoiceId);
        $payment = Database::row('SELECT * FROM client_payments WHERE id=? FOR UPDATE',[$paymentId]);
        $invoice = Database::row('SELECT * FROM permit_invoices WHERE id=? FOR UPDATE',[$invoiceId]);
        $amount = round(min($amount,(float)$payment['unallocated_amount'],(float)$invoice['balance_due']),2);
        if ($amount <= 0) { throw new \RuntimeException('لا يوجد مبلغ متاح للتخصيص.'); }
        Database::exec(
            'INSERT INTO invoice_payment_allocations (invoice_id,payment_id,amount,created_by) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE amount=amount+VALUES(amount)',
            [$invoiceId,$paymentId,$amount,(int)($_SESSION['uid'] ?? 0) ?: null]
        );
        self::refreshAllocation($paymentId);
        PermitInvoice::refreshStatus($invoiceId);
        Audit::log('allocate','client_payments',$paymentId,null,['invoice_id'=>$invoiceId,'amount'=>$amount],
            'تخصيص تحصيل على فاتورة',['invoice_id'=>$invoiceId]);
    }

    /** يخصص على الأقدم استحقاقاً حتى ينفد التحصيل. */
    public static function autoAllocate(int $paymentId): void
    {
        $payment = self::find($paymentId);
        if (!$payment || !$payment['agent_id'] || $payment['status']!=='posted') { return; }
        foreach (PermitInvoice::openForAgent((int)$payment['agent_id']) as $invoice) {
            self::refreshAllocation($paymentId);
            $left = (float)Database::scalar('SELECT unallocated_amount FROM client_payments WHERE id=?',[$paymentId]);
            if ($left <= .005) { break; }
            self::allocate($paymentId,(int)$invoice['id'],min($left,(float)$invoice['balance_due']));
        }
    }

    public static function refreshAllocation(int $paymentId): void
    {
        Database::exec(
            "UPDATE client_payments p LEFT JOIN (
                SELECT payment_id,SUM(amount) allocated FROM invoice_payment_allocations GROUP BY payment_id
             ) x ON x.payment_id=p.id
             SET p.unallocated_amount=IF(p.status='posted',GREATEST(p.amount-COALESCE(x.allocated,0),0),0)
             WHERE p.id=?",[$paymentId]
        );
    }

    public static function allocations(int $paymentId): array
    {
        return Database::all(
            'SELECT a.*,i.doc_no,i.invoice_date,i.total FROM invoice_payment_allocations a
             JOIN permit_invoices i ON i.id=a.invoice_id WHERE a.payment_id=? ORDER BY a.id',[$paymentId]
        );
    }

    // ─────────────────────────────────────────────── كشف الحساب

    /**
     * كشف حساب العميل: المطلوب (فواتير الأذونات + شامل) − الدفعات = المتبقّي عليه.
     * $charges = إجمالي الفواتير الصادرة للعميل (يُمرَّر من الكنترولر لتفادي تكرار الاستعلام).
     */
    public static function statement(string $name, float $charges): array
    {
        $paid = self::totalFor($name);
        return [
            'charges'   => round($charges, 2),
            'paid'      => round($paid, 2),
            'remaining' => round($charges - $paid, 2),
        ];
    }
}
