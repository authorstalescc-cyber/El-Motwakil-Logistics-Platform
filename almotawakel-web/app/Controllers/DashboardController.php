<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Performance;
use App\Models\Custody;
use App\Models\Metrics;
use App\Models\Note;
use App\Models\Lookup;
use App\Models\PermitQuickEntry;
use App\Models\RepCustody;
use App\Models\VisaCard;

class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        // كل بيانات الصفحة تحترم تاريخ العمل المحدَّد
        $date = Auth::workDate();
        $year = (int) substr($date, 0, 4);
        $profile = static fn(string $name, callable $operation) => Performance::span($name, $operation);

        $charts = [
            'trend'    => $profile('dashboard.chart.trend', fn() => Metrics::activityTrend(14, $date)),
            'clients'  => $profile('dashboard.chart.clients', fn() => Metrics::clientDebts(7)),
            'monthly'  => $profile('dashboard.chart.monthly', fn() => array_values(Metrics::monthlyExpensePayments($year))),
            'expenses' => $profile('dashboard.chart.expenses', fn() => Metrics::expenseReport()),
        ];
        $custodyBalance = $profile('dashboard.custody', fn() => Custody::balance());
        $readyData = $profile('dashboard.ready', fn() => Metrics::readyToInvoiceDashboard(8));
        $timeline = $profile('dashboard.feed', fn() => Metrics::expenseReceiptFeed($date, 300));
        $dayExpenses = $profile('dashboard.day_expenses', static fn() => array_values(array_filter(
            $timeline,
            fn($row) => $row['d'] === $date && !$row['is_receipt']
        )));
        $dayReceipts = $profile('dashboard.day_receipts', static fn() => array_values(array_filter(
            $timeline,
            fn($row) => $row['d'] === $date && $row['is_receipt']
        )));
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
            'daily'    => $profile('dashboard.daily', fn() => Metrics::dashDaily($date)),
            'counters' => $profile('dashboard.counters', fn() => Metrics::counters()),
            'permits'  => $profile('dashboard.permits', fn() => Metrics::permitsSummary()),
            'custody'  => $custodyBalance,
            'ready'    => $readyData['rows'],
            'readySum' => $readyData['summary'],
            'openList' => $profile('dashboard.open_permits', fn() => Metrics::openPermitsList(8)),
            'feed'     => array_slice($timeline, 0, 14),
            'dayExp'   => $dayExpenses,
            'dayRec'   => $dayReceipts,
            'custodyDay'=> $profile('dashboard.custody_day', fn() => Metrics::custodyDay($date, $custodyBalance)),
            'activity' => $profile('dashboard.activity', fn() => Metrics::recentActivity(10)),
            // لوحة متابعة عهدة المناديب — قراءة فقط؛ الصرف المعتمد يدخل الخزنة (v42)
            'repsPanel'=> $profile('dashboard.reps', fn() => RepCustody::dashboard($date, 6, 6)),
            'notes'    => $profile('dashboard.notes', fn() => Note::all(20)),
            'charts'   => $charts,
            // قائمة الأذونات قد تكون مئات الصفوف؛ تُحمّل فقط عند فتح الإدخال السريع.
            'quickPermits' => [],
            'quickCards' => [],
            'quickReceipts' => [],
            'quickExpenses' => [],
            'quickOld' => $quickOld,
            'quickApplied' => $quickApplied,
            'quickToken' => $quickToken,
        ]);
    }

    /** خيارات الإدخال السريع الثقيلة — تُجلب عند فتح الأداة بدل تضخيم الداشبورد. */
    public function quickOptions(): void
    {
        $this->requireAuth();
        header('Cache-Control: private, no-store, max-age=0');
        $lookups = Lookup::ofTypes(['mv_receipt', 'mv_expense']);
        $cards = array_map(
            static fn(array $card): array => [
                'id' => (int) $card['id'],
                'label' => $card['name'] . ($card['owner'] ? ' — ' . $card['owner'] : ''),
            ],
            VisaCard::all(true)
        );
        $this->json([
            'ok' => true,
            'permits' => PermitQuickEntry::options(),
            'cards' => $cards,
            'receipts' => $lookups['mv_receipt'] ?? [],
            'expenses' => $lookups['mv_expense'] ?? [],
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
        if ($body === '') { $this->fail('اكتب نص الملاحظة الأول.', ''); }
        Note::create($body, $color, Auth::user());
        $this->done('تمت إضافة الملاحظة.', '');
    }

    /** تعديل نص/لون ملاحظة */
    public function editNote(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $body  = trim($_POST['body'] ?? '');
        $color = trim($_POST['color'] ?? 'gold');
        if ($body === '') { $this->fail('نص الملاحظة ما ينفعش يبقى فاضي.', ''); }
        Note::update((int) $id, $body, $color);
        $this->done('تم تعديل الملاحظة.', '');
    }

    /** تعليم/إلغاء تعليم ملاحظة كمكتملة */
    public function toggleNote(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $done = Note::toggleDone((int) $id);
        $this->done($done ? 'تم تعليم الملاحظة كمكتملة.' : 'رجّعت الملاحظة لغير مكتملة.', '');
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
