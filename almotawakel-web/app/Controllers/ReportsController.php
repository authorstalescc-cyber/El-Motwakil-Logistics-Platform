<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Metrics;

class ReportsController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $this->show(false);
    }

    /** نسخة مخصّصة للطباعة (بدون شريط جانبي) */
    public function printable(): void
    {
        $this->requireAuth();
        $this->show(true);
    }

    private function show(bool $print): void
    {
        // فلتر النطاق التاريخي (اختياري)
        $from = trim((string)($_GET['from'] ?? ''));
        $to   = trim((string)($_GET['to']   ?? ''));
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : '';
        $fromN = $from !== '' ? $from : null;
        $toN   = $to   !== '' ? $to   : null;

        // سنة مخطّط الأداء الشهري: تُشتقّ من النطاق، وإلا سنة التقرير الافتراضية
        if ($toN)        $year = (int)substr($toN, 0, 4);
        elseif ($fromN)  $year = (int)substr($fromN, 0, 4);
        else             $year = Metrics::reportYear();

        $monthNames = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

        $data = [
            'title'      => 'التقارير المالية والإدارية',
            'year'       => $year,
            'from'       => $from,
            'to'         => $to,
            'totals'     => Metrics::totals($fromN, $toN),
            'monthly'    => Metrics::monthly($year),
            'monthNames' => $monthNames,
            'byCompany'  => Metrics::byCompany(15, $fromN, $toN),
            'debts'      => Metrics::clientDebts(15),
            'customers'  => Metrics::customerProfit(25, $fromN, $toN),
            'expenses'   => Metrics::expenseReport($fromN, $toN),
            'payments'   => Metrics::paymentsReport($fromN, $toN),
            'movements'  => Metrics::movementsReport($fromN, $toN),
            'permitsList'=> Metrics::permitsReport($fromN, $toN),
            'print'      => $print,
        ];

        if ($print) {
            $this->render('reports/print', $data, false);
        } else {
            $this->render('reports/index', $data);
        }
    }

    /** بروفايل عميل: كل التفاصيل والحركات السابقة وإجمالي المستحقّات */
    public function client(): void
    {
        $this->requireAuth();
        $name = trim((string)($_GET['name'] ?? ''));
        if ($name === '') {
            redirect('reports');
        }

        $profile = Metrics::customerProfile($name);
        if ($profile === null) {
            flash('error', 'لا توجد بيانات للعميل: ' . $name);
            redirect('reports');
        }

        $monthNames = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

        $this->render('reports/client', [
            'title'      => 'بروفايل العميل — ' . $name,
            'profile'    => $profile,
            'monthNames' => $monthNames,
        ]);
    }
}
