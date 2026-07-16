<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Party;
use App\Models\PermitInvoice;
use App\Models\ClientPayment;
use App\Models\Closing;
use App\Models\VisaCard;
use App\Models\PartyMaster;

/**
 * دليل الوكلاء والشركات — وحدة «عرض فقط».
 *   /directory                 قائمة الوكلاء + الشركات (تبويبان)
 *   /directory/agent/{id}      بروفايل الوكيل
 *   /directory/company/{id}    بروفايل الشركة
 */
class DirectoryController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $requestedTab = $_GET['tab'] ?? 'agents';
        $tab = in_array($requestedTab,['agents','companies','owners'],true) ? $requestedTab : 'agents';
        $q   = trim($_GET['q'] ?? '');
        $agents = Party::agents($q);
        // إثراء بطاقات الوكلاء بالمتبقّي (فواتير الأذونات − دفعات السداد) — خريطتان مجمّعتان
        $charges = PermitInvoice::totalsByClient();
        $paid    = ClientPayment::totalsByClient();
        foreach ($agents as &$a) {
            $c = $charges[$a['name']] ?? 0.0;
            $p = $paid[$a['name']] ?? 0.0;
            $a['charges']   = $c;
            $a['paid']      = $p;
            $a['remaining'] = round($c - $p, 2);
        }
        unset($a);
        $this->render('directory/index', [
            'title'     => 'دليل الأطراف',
            'tab'       => $tab,
            'q'         => $q,
            'agents'    => $agents,
            'companies' => Party::companies($q),
            'owners'    => Party::owners($q),
        ]);
    }

    public function agent(string $id): void
    {
        $this->requireAuth();
        $agent = Party::agent((int)$id);
        if (!$agent) { $this->notFound(); return; }
        $name = $agent['name'];
        $summary       = Party::summary('client', $name);
        $permitInvSum  = PermitInvoice::summaryFor('client', $name);
        // المطلوب من العميل = فواتير الأذونات + فواتير شامل (إن وُجدت)
        $charges       = (float) ($permitInvSum['total'] ?? 0) + (float) ($summary['invoices']['total'] ?? 0);
        $permitInvoices = PermitInvoice::forName('client', $name);
        // مجموعة أرقام الأذونات التي صُدِرت لها فاتورة (لتمييزها بلون في جدول الأذونات)
        $invoicedPermitIds = [];
        foreach ($permitInvoices as $iv) {
            if (!empty($iv['permit_id'])) { $invoicedPermitIds[(int)$iv['permit_id']] = true; }
        }
        $this->render('directory/agent', [
            'title'     => 'وكيل: ' . $name,
            'agent'     => $agent,
            'companies' => Party::agentCompanies((int)$id),
            'summary'   => $summary,
            'permits'   => Party::permitsBy('client', $name),
            'invoices'  => Party::invoicesBy('client', $name),
            'permitInvoices' => $permitInvoices,
            'invoicedPermitIds' => $invoicedPermitIds,
            'permitInvSum'   => $permitInvSum,
            'balance'   => Party::clientBalance($name),
            'payments'  => ClientPayment::forClient($name),
            'statement' => ClientPayment::statement($name, $charges),
            'visaCards' => VisaCard::all(true),
        ]);
    }

    /** تسجيل دفعة سداد للعميل (مع توجيه اختياري للعهدة النقدية أو كارت فيزا). */
    public function addPayment(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $agent = Party::agent((int)$id);
        if (!$agent) { $this->notFound(); return; }
        $name  = $agent['name'];
        $back  = 'directory/agent/' . (int)$id;

        $amount = ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'];
        if ($amount <= 0) { flash('error', 'أدخل مبلغ الدفعة.'); redirect($back); }

        $date  = trim($_POST['pay_date'] ?? '');
        $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : work_date();
        $route = in_array($_POST['route'] ?? 'none', ['cash', 'visa'], true) ? $_POST['route'] : 'none';
        $card  = (int) ($_POST['visa_card_id'] ?? 0);

        if ($route === 'visa' && $card <= 0) {
            flash('error', 'اختر كارت الفيزا لتوجيه الدفعة إليه.'); redirect($back);
        }
        // العهدة النقدية تدخل التقفيل اليومي → امنع الكتابة على يوم متقفل
        if ($route === 'cash' && ($err = Closing::guard($date))) { flash('error', $err); redirect($back); }

        try {
            ClientPayment::create([
                'client_name'  => $name,
                'agent_id'     => (int)$agent['id'],
                'pay_date'     => $date,
                'amount'       => $amount,
                'note'         => trim($_POST['note'] ?? ''),
                'route'        => $route,
                'visa_card_id' => $card,
                'auto_allocate'=> !empty($_POST['auto_allocate']),
            ]);
            flash('success', 'تم تسجيل التحصيل.');
        } catch (\Throwable $e) { flash('error',$e->getMessage()); }
        redirect($back);
    }

    /** حذف دفعة سداد (مع السجل المرتبط بها في العهدة/الفيزا). */
    public function deletePayment(string $id, string $pid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $back = 'directory/agent/' . (int)$id;
        $pay  = ClientPayment::find((int)$pid);
        if (!$pay) { flash('error', 'الدفعة غير موجودة.'); redirect($back); }
        $agentId = (int)$id;
        if ((int)($pay['agent_id'] ?? 0) !== $agentId) {
            http_response_code(404);
            flash('error','التحصيل لا يخص هذا الوكيل.'); redirect($back);
        }
        // لو الدفعة أنشأت عهدة نقدية على يوم متقفل، امنع الحذف حتى يُفتَح الإقفال
        if ($pay['route'] === 'cash' && ($err = Closing::guard($pay['pay_date']))) {
            flash('error', $err); redirect($back);
        }
        try {
            ClientPayment::reverse((int)$pid, trim($_POST['reason'] ?? ''));
            flash('success', 'تم عكس التحصيل مع الاحتفاظ بسجله.');
        } catch (\Throwable $e) { flash('error',$e->getMessage()); }
        redirect($back);
    }

    public function company(string $id): void
    {
        $this->requireAuth();
        $company = Party::company((int)$id);
        if (!$company) { $this->notFound(); return; }
        $name = $company['name'];
        $this->render('directory/company', [
            'title'    => 'شركة: ' . $name,
            'company'  => $company,
            'agents'   => Party::companyAgents((int)$id),
            'summary'  => Party::summary('company', $name),
            'permits'  => Party::permitsBy('company', $name),
            'invoices' => Party::invoicesBy('company', $name),
            'permitInvoices' => PermitInvoice::forName('company', $name),
            'permitInvSum'   => PermitInvoice::summaryFor('company', $name),
        ]);
    }

    public function createParty(string $type): void
    {
        $this->requireAuth(); $this->requireCsrf();
        try {
            PartyMaster::create($type,$_POST);
            flash('success','تمت إضافة الطرف إلى البيانات الأساسية.');
        } catch (\Throwable $e) { flash('error',$e->getMessage()); }
        redirect('directory?tab='.$this->tabForType($type));
    }

    public function updateParty(string $type,string $id): void
    {
        $this->requireAuth(); $this->requireCsrf();
        try {
            PartyMaster::update($type,(int)$id,$_POST,(int)($_POST['lock_version'] ?? 0));
            flash('success','تم تحديث البيانات الأساسية.');
        } catch (\Throwable $e) { flash('error',$e->getMessage()); }
        redirect('directory?tab='.$this->tabForType($type));
    }

    public function addAlias(string $type,string $id): void
    {
        $this->requireAuth(); $this->requireCsrf();
        try { PartyMaster::addAlias($type,(int)$id,trim($_POST['alias'] ?? '')); flash('success','تمت إضافة الاسم البديل.'); }
        catch (\Throwable $e) { flash('error',$e->getMessage()); }
        redirect('directory?tab='.$this->tabForType($type));
    }

    public function mergeParty(string $type,string $id): void
    {
        $this->requireAuth(); $this->requireCsrf();
        try {
            PartyMaster::merge($type,(int)$id,(int)($_POST['target_id'] ?? 0),(int)($_POST['lock_version'] ?? 0));
            flash('success','تم الدمج ونقل جميع العلاقات إلى الطرف المختار، وحُفظ الاسم القديم كاسم بديل.');
        } catch (\Throwable $e) { flash('error',$e->getMessage()); }
        redirect('directory?tab='.$this->tabForType($type));
    }

    private function tabForType(string $type): string
    {
        return $type==='company'?'companies':($type==='owner'?'owners':'agents');
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('errors/404', ['title' => 'غير موجود'], false);
    }
}
