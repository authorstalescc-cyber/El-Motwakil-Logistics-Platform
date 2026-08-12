<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Party;
use App\Models\PermitInvoice;
use App\Models\PartyMaster;
use App\Models\PartyDoc;

/**
 * دليل الأطراف — وحدة «عرض فقط» للشركات وأصحاب البيان.
 *   /directory                 قائمة الشركات + أصحاب البيان (تبويبان)
 *   /directory/company/{id}    بروفايل الشركة
 *
 * ⚠️⚠️ **العملاء (الوكلاء) اتفصلوا من هنا في v93** لوحدة «حسابات العملاء»
 *    (`ClientController` · `/clients`): العميل عنده **حساب** (فواتير وتحصيلات
 *    وأعمار دين) مش مجرد كارت في دليل، وحشره جنب الشركات كان بيخلّي نص
 *    الشاشة أرقامًا ماليّة ونصّها التاني أسماء. الرابط القديم بيحوّل هناك،
 *    والتحصيل كله (تسجيل/عكس) اتنقل معاه بلا أي تغيير في المنطق.
 */
class DirectoryController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        /* ⚠️ الرابط القديم `?tab=agents` بيحوّل للوحدة الجديدة بدل ما يدّي
           تبويبًا فاضيًا — الروابط المحفوظة عند المالك لازم تفضل شغّالة. */
        if (($_GET['tab'] ?? '') === 'agents') { redirect('clients'); }
        $requestedTab = $_GET['tab'] ?? 'companies';
        $tab = in_array($requestedTab,['companies','owners'],true) ? $requestedTab : 'companies';
        $q   = trim($_GET['q'] ?? '');
        $this->render('directory/index', [
            'title'     => 'دليل الأطراف',
            'tab'       => $tab,
            'q'         => $q,
            'companies' => Party::companies($q),
            'owners'    => Party::owners($q),
        ]);
    }

    public function company(string $id): void
    {
        $this->requireAuth();
        $company = Party::company((int)$id);
        if (!$company) { $this->notFound(); }
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
            'docs'      => PartyDoc::forParty('company', (int)$id),
            'docParty'  => ['type' => 'company', 'id' => (int)$id, 'name' => $name],
            'docState'  => \App\Core\FormState::consume('partydoc.company.' . (int)$id, 'directory/company/' . (int)$id),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  مستندات الطرف — البطاقة الضريبية / السجل التجاري / … (v56)
    //
    //  ⚠️ وحدة وصفية معزولة: **ولا رقم مالي ولا لمسة لخزنة أو تقفيل**.
    //     الملف بيتخزَّن برّه `public` وبيتقدّم عبر `FileController` بعد
    //     فحص الجلسة، و`rep_visible` بتقرّر هل يوصل موبايل المندوب.
    // ══════════════════════════════════════════════════════════════════

    /** رفع مستند جديد لطرف (شركة/وكيل/صاحب بيان). */
    public function addDoc(string $type, string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $partyId = (int) $id;
        $back    = $this->partyUrl($type, $partyId);

        try {
            $docId = PartyDoc::create(
                $type,
                $partyId,
                $_POST,
                $_FILES['doc_file'] ?? [],
                \App\Core\Auth::user()
            );
        } catch (\Throwable $e) {
            // ⚠️ الملف نفسه ما بيتحفظش في الحقيبة (مستحيل تقنيًّا)، فالرسالة
            //    بتقول للمالك يختاره تاني — والباقي بيرجع متعبّي زي ما كان.
            flash('error', $e->getMessage());
            $this->formFailure(
                'partydoc.' . $type . '.' . $partyId,
                $back . '#party-docs',
                $_POST,
                ['_form' => $e->getMessage()],
                $back
            );
        }

        \App\Models\Audit::log(
            'create', 'party_documents', $docId, null,
            ['party_type' => $type, 'party_id' => $partyId, 'title' => $_POST['title'] ?? ''],
            'رفع مستند لطرف (' . PartyDoc::partyLabel($type) . ')'
        );
        $this->done('اترفع المستند وبقى ظاهر في أذونات الطرف ده.', $back, 'party-docs');
    }

    /** تعديل بيانات مستند (بلا استبدال الملف — ارفع مستندًا جديدًا بدله). */
    public function updateDoc(string $docId): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $doc = PartyDoc::find((int) $docId);
        if (!$doc) { $this->notFound('المستند ده مش موجود أو اتمسح.'); }
        $back = $this->partyUrl((string) $doc['party_type'], (int) $doc['party_id']);

        try {
            PartyDoc::update((int) $docId, $_POST);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $back, 'party-docs');
        }
        \App\Models\Audit::log('update', 'party_documents', (int) $docId, $doc, $_POST, 'تعديل بيانات مستند طرف');
        $this->done('اتحفظت بيانات المستند.', $back, 'party-docs');
    }

    /** حذف مستند وملفه. */
    public function deleteDoc(string $docId): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $doc = PartyDoc::find((int) $docId);
        if (!$doc) { $this->notFound('المستند ده مش موجود أو اتمسح.'); }
        $back = $this->partyUrl((string) $doc['party_type'], (int) $doc['party_id']);

        PartyDoc::delete((int) $docId);
        \App\Models\Audit::log('delete', 'party_documents', (int) $docId, $doc, null, 'حذف مستند طرف');
        $this->done('اتمسح المستند.', $back, 'party-docs');
    }

    /** المصدر الوحيد لرابط بروفايل الطرف — ممنوع تكتبه بالإيد في أي مكان. */
    private function partyUrl(string $type, int $id): string
    {
        return match ($type) {
            'company' => 'directory/company/' . $id,
            // ⚠️ العميل بقى في وحدته (v93) — رفع مستند من هناك لازم يرجع هناك.
            'agent'   => 'clients/' . $id . '?tab=card',
            default   => 'directory?tab=owners',
        };
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

    /* `notFound()` الخاصة اتشالت في المرحلة ٢ — المشتركة في `App\Core\Controller`
       بترندر 404 **جوّه الـlayout** (قائمة + بحث + رجوع) بدل صفحة مستقلة. */
}
