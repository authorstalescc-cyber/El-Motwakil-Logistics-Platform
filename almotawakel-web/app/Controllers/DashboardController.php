<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Custody;
use App\Models\Metrics;
use App\Models\Note;
use App\Models\Lookup;
use App\Models\PermitQuickEntry;
use App\Models\VisaCard;

class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        // كل بيانات الصفحة تحترم تاريخ العمل المحدَّد
        $date = Auth::workDate();
        $year = (int) substr($date, 0, 4);

        $charts = [
            'trend'    => Metrics::activityTrend(14, $date),
            'clients'  => Metrics::clientDebts(7),
            'monthly'  => array_values(Metrics::monthlyExpensePayments($year)),
            'expenses' => Metrics::expenseReport(),
        ];
        $quickOld = $_SESSION['_quick_entry_old'] ?? [];
        unset($_SESSION['_quick_entry_old']);
        // إشارة النجاح لمرة واحدة (تُستهلك هنا) → الواجهة تمسح المسودة المحفوظة
        $quickApplied = !empty($_SESSION['_quick_entry_applied']);
        unset($_SESSION['_quick_entry_applied']);
        $quickToken = bin2hex(random_bytes(24));
        $_SESSION['_quick_entry_token'] = $quickToken;

        $this->render('dashboard/index', [
            'title'    => 'لوحة التحكم',
            'date'     => $date,
            'year'     => $year,
            'daily'    => Metrics::dashDaily($date),
            'counters' => Metrics::counters(),
            'permits'  => Metrics::permitsSummary(),
            'custody'  => Custody::balance(),
            'ready'    => Metrics::readyToInvoice(60),
            'readySum' => Metrics::readyToInvoiceSummary(),
            'openList' => Metrics::openPermitsList(60),
            'feed'     => Metrics::expenseReceiptFeed($date, 14),
            'dayExp'   => Metrics::dayExpenses($date),
            'dayRec'   => Metrics::dayReceipts($date),
            'custodyDay'=> Metrics::custodyDay($date),
            'activity' => Metrics::recentActivity(10),
            'notes'    => Note::all(20),
            'charts'   => $charts,
            'quickPermits' => PermitQuickEntry::options(),
            'quickCards' => VisaCard::all(true),
            'quickReceipts' => Lookup::ofType('mv_receipt'),
            'quickExpenses' => Lookup::ofType('mv_expense'),
            'quickOld' => $quickOld,
            'quickApplied' => $quickApplied,
            'quickToken' => $quickToken,
        ]);
    }

    /** تطبيق دفعة الإدخال السريع كاملة داخل الأذونات. */
    public function quickEntries(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $token = (string)($_POST['quick_token'] ?? '');
        $sessionToken = (string)($_SESSION['_quick_entry_token'] ?? '');
        if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            flash('error', 'انتهت صلاحية شاشة الإدخال السريع. افتحها من الداشبورد مرة أخرى.');
            redirect('?quick=1');
        }
        try {
            $result = PermitQuickEntry::save($_POST);
            unset($_SESSION['_quick_entry_token'], $_SESSION['_quick_entry_old']);
            // إشارة نجاح لمرة واحدة → تُخبر الواجهة أن تمسح مسودة المتصفح المحفوظة
            $_SESSION['_quick_entry_applied'] = 1;
            $parts = [];
            if ($result['totals']['receipt'] > 0) { $parts[] = 'إيصالات '.money($result['totals']['receipt'], false); }
            if ($result['totals']['expense'] > 0) { $parts[] = 'مصاريف '.money($result['totals']['expense'], false); }
            flash('success', 'تم تطبيق '.intf($result['count']).' بند داخل الأذونات بنجاح'.($parts ? ' — '.implode('، ', $parts) : '').'.');
            redirect('');
        } catch (\Throwable $e) {
            $_SESSION['_quick_entry_old'] = self::quickInputOnly($_POST);
            flash('error', 'لم يتم حفظ أي بند: '.$e->getMessage());
            redirect('?quick=1');
        }
    }

    /** لا نخزن CSRF/token في بيانات إعادة تعبئة النموذج. */
    private static function quickInputOnly(array $post): array
    {
        return array_intersect_key($post, array_flip([
            'permit_id','movement_id','kind','note','amount','actual_amount',
            'entry_date','ref_no','payment_method','visa_card_id',
        ]));
    }

    /** إضافة ملاحظة سريعة */
    public function addNote(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $body  = trim($_POST['body'] ?? '');
        $color = trim($_POST['color'] ?? 'gold');
        if ($body !== '') {
            Note::create($body, $color, Auth::user());
            flash('success', 'تمت إضافة الملاحظة.');
        }
        redirect('');
    }

    /** تعديل نص/لون ملاحظة */
    public function editNote(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $body  = trim($_POST['body'] ?? '');
        $color = trim($_POST['color'] ?? 'gold');
        if ($body !== '') {
            Note::update((int) $id, $body, $color);
            flash('success', 'تم تعديل الملاحظة.');
        }
        redirect('');
    }

    /** تعليم/إلغاء تعليم ملاحظة كمكتملة */
    public function toggleNote(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        Note::toggleDone((int) $id);
        redirect('');
    }

    /** حذف ملاحظة */
    public function deleteNote(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        Note::delete((int) $id);
        flash('success', 'تم حذف الملاحظة.');
        redirect('');
    }
}
