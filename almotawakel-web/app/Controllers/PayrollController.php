<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Payroll;
use App\Models\RepAccount;
use App\Models\RepMiscExpense;
use App\Models\RepMiscKind;
use RuntimeException;

/**
 * شاشة رواتب المناديب (v87) — كشف فترة واحدة: الثابت + الحوضين + الصافي.
 *
 * ⚠️ الشاشة **ما بتحسبش حاجة بنفسها**: كل رقم بيجي من `Payroll` (المصدر
 *    الوحيد)، والفترة من `RepAccount::period()` (نفس فترة شاشة «حسابي» بتاعة
 *    المندوب) — فمستحيل المكتب يشوف فترة والمندوب يشوف فترة تانية.
 * ⚠️ لا middleware — `requireAuth`/`requireCsrf` يدوية في كل action (تحذير ٥).
 */
class PayrollController extends Controller
{
    /** كشف الفترة (مسوّدة أو معتمدة). */
    public function index(): void
    {
        $this->requireAuth();

        $period = RepAccount::period(trim($_GET['at'] ?? ''));
        $saved  = Payroll::findPeriod($period['from'], $period['to']);

        if ($saved) {
            // ⚠️ الفترة المعتمدة بتتقري من **اللقطة** لا بتتحسب تاني.
            $calc = [
                'period'            => $period,
                'bills_counted'     => (int) $saved['bills_counted'],
                'bills_excluded'    => (int) $saved['bills_excluded'],
                'commission_pool'   => (float) $saved['commission_pool'],
                'expense_pool'      => (float) $saved['expense_pool'],
                'allowed_expenses'  => (float) $saved['allowed_expenses'],
                'expense_remainder' => (float) $saved['expense_remainder'],
                'deficit'           => (float) $saved['deficit'],
                'deficit_decision'  => (string) $saved['deficit_decision'],
                'reps_count'        => (int) $saved['reps_count'],
                'allowed_kinds'     => Payroll::allowedKinds(),
                'lines'             => array_map(static fn ($l) => [
                    'rep_id'           => (int) $l['rep_id'],
                    'rep_name'         => (string) $l['rep_name'],
                    'included'         => (bool) $l['included'],
                    'active'           => true,
                    'base_salary'      => (float) $l['base_salary'],
                    'advances'         => (float) $l['advances'],
                    'allowed'          => 0.0,
                    'commission_share' => (float) $l['commission_share'],
                    'expense_share'    => (float) $l['expense_share'],
                    'net'              => (float) $l['net'],
                ], Payroll::lines((int) $saved['id'])),
            ];
            $calc['net_total'] = round(array_sum(array_column($calc['lines'], 'net')), 2);
        } else {
            $exclude  = array_map('intval', (array) ($_GET['ex'] ?? []));
            $decision = trim($_GET['deficit'] ?? Payroll::DEFICIT_OFFICE);
            $calc     = Payroll::compute($period, $exclude, $decision);
        }

        $this->render('payroll/index', [
            'title'    => 'رواتب المناديب',
            'period'   => $period,
            'prev'     => RepAccount::shift($period, -1),
            'next'     => RepAccount::shift($period, 1),
            'isFuture' => RepAccount::isFuture(RepAccount::shift($period, 1)),
            'calc'     => $calc,
            'saved'    => $saved,
            'ready'    => Payroll::available(),
        ]);
    }

    /** اعتماد الفترة — بيولّد اللقطة وبنود الاستحقاق. */
    public function approve(): void
    {
        $this->requireAuth();
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        $period   = RepAccount::period(trim($_POST['at'] ?? ''));
        $exclude  = array_map('intval', (array) ($_POST['ex'] ?? []));
        $decision = trim($_POST['deficit'] ?? Payroll::DEFICIT_OFFICE);
        $note     = trim($_POST['note'] ?? '');

        try {
            Payroll::approve($period, $exclude, $decision, $note);
        } catch (RuntimeException $e) {
            $this->fail($e->getMessage(), 'payroll?at=' . $period['from']);
        }
        $this->done('الفترة اتعتمدت واستحقاقات المناديب اتسجّلت.', 'payroll?at=' . $period['from']);
    }

    /** إلغاء اعتماد فترة (بيشيل بنودها كمان). */
    public function unapprove(string $id): void
    {
        $this->requireAuth();
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        $row = Payroll::findPeriodById((int) $id);
        if (!$row) { $this->notFound('الفترة دي مش موجودة.'); }

        try {
            Payroll::unapprove((int) $id);
        } catch (RuntimeException $e) {
            $this->fail($e->getMessage(), 'payroll?at=' . $row['period_from']);
        }
        $this->done('الاعتماد اتلغى.', 'payroll?at=' . $row['period_from']);
    }

    /** أنواع التفاصيل المسموحة — الحارس بيتفرّج على القايمة دي لا على الرابط. */
    private const DRILLS = ['bills', 'commission', 'expense', 'allowed', 'advances', 'nets'];

    /**
     * تفاصيل أي رقم في الشاشة — «ممنوع رقم أصمّ» (قاعدة ⑤).
     * `rep`: رقم المندوب (٠ = الكل) — بيشتغل مع `advances`/`allowed` بس.
     */
    public function drill(string $what): void
    {
        $this->requireAuth();
        if (!in_array($what, self::DRILLS, true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }

        $period = RepAccount::period(trim($_GET['at'] ?? ''));
        $repId  = (int) ($_GET['rep'] ?? 0);
        $saved  = Payroll::findPeriod($period['from'], $period['to']);

        $rows = [];
        if ($what === 'advances' || $what === 'allowed') {
            $kinds = $what === 'allowed' ? Payroll::allowedKinds() : RepMiscKind::salaryKeys();
            $rows  = RepMiscExpense::rowsForKinds($repId, $kinds, $period['from'], $period['to']);
        } elseif ($what === 'nets') {
            /* الفترة المعتمدة بتقرا من اللقطة؛ المسوّدة بتتحسب — نفس منطق الشاشة. */
            $rows = $saved
                ? Payroll::lines((int) $saved['id'])
                : Payroll::compute($period)['lines'];
        } else {
            // bills | commission | expense — كلهم على نفس قايمة البوالص بعدسة مختلفة.
            $rows = array_values(array_filter(
                Payroll::billRows($period['from'], $period['to']),
                static fn ($b) => !$b['excluded']));
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('payroll/_drill', [
            'what'     => $what,
            'rows'     => $rows,
            'period'   => $period,
            'approved' => (bool) $saved,
        ], false);
    }
}
