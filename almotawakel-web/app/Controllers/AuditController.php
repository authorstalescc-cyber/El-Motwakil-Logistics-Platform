<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Audit;

class AuditController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $mode = ($_GET['mode'] ?? '') === 'entries' ? 'entries' : 'operations';
        $action = $mode === 'entries' ? 'create' : trim($_GET['action'] ?? '');
        $from = $this->date($_GET['from'] ?? '');
        $to = $this->date($_GET['to'] ?? '');
        $this->render('audit/index', [
            'title' => $mode === 'entries' ? 'سجل الإدخالات' : 'سجل العمليات وسلة المحذوفات',
            'logs' => Audit::recent(500, trim($_GET['entity'] ?? ''), $action, $from, $to),
            'trash' => Audit::trashRows(),
            'mode' => $mode,
            'entity' => trim($_GET['entity'] ?? ''),
            'action' => $action,
            'from' => $from,
            'to' => $to,
            'restorableEntities'=>Audit::restorableEntities(),
        ]);
    }

    private function date($value): string
    {
        $value = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    public function undo(string $id): void
    {
        $this->requireAuth(); $this->requireCsrf();
        try { Audit::undo((int)$id); flash('success', 'تم التراجع واستعادة القيم السابقة.'); }
        catch (\Throwable $e) { flash('error', $e->getMessage()); }
        redirect('audit');
    }

    public function restore(string $id): void
    {
        $this->requireAuth(); $this->requireCsrf();
        try { Audit::restore((int)$id); flash('success', 'تم استرجاع السجل من سلة المحذوفات.'); }
        catch (\Throwable $e) { flash('error', $e->getMessage()); }
        redirect('audit#trash');
    }
}
