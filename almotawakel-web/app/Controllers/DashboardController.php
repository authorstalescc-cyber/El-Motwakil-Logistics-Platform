<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Performance;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\Metrics;
use App\Models\Note;
use App\Models\PermitReadiness;
use App\Models\Rep;
use App\Models\RepCustody;

class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        // كل بيانات الصفحة تحترم تاريخ العمل المحدَّد
        $date = Auth::workDate();
        $year = (int) substr($date, 0, 4);
        $profile = static fn(string $name, callable $operation) => Performance::span($name, $operation);

        /* ⚠️ استعلامات الرسوم الأربعة اتشالت (المرحلة ٤): الرسوم اتنقلت
           لـ/reports، واتنين منها كانوا مكرَّرين هناك أصلاً. ده بيوفّر أربع
           تجميعات ثقيلة من **كل** فتحة للداشبورد. */
        /* ⚠️ المرحلة ١١ — الداشبورد اتعادت بناؤها بأربع طبقات، وكل بوب-أب
           بقى **يُجلَب عند الضغط** من `drill()` تحت بدل ما يترندر مع كل فتحة
           للصفحة في `#popStore`. اللي اتشال من هنا: `recentActivity` و
           `custodyDay` وتصفية مصروفات/إيصالات اليوم — كلها بقت في المسار
           الكسول، فالصفحة ما بتدفعش تكلفتها إلا لو المالك فتح التفاصيل. */
        /* ⚠️ **إعادة البناء (2026-08-17) — طلب المالكين بالحرف:**
             «رصيد الخزنة دخلها كام ونزل كام للمكتب · خرج كام عربية اليوم ·
              دخل كام إيصال · إيه الأذونات الجديدة اللي اتفتحت · الأذونات
              اللي محتاجة تتقفل · كل مندوب نزله كام وصرف قد إيه وسوَى ولا
              مسواش عهدته · الأذونات اللي فيها إيصالات محتملة ناقصة».
           كلها بتقرا من مصادر قايمة — مفيش تعريف جديد لأي رقم غير تعريف
           «الإذن خلص شغله» اللي عايش في `PermitReadiness` لوحده. */
        $custodyBalance = $profile('dashboard.custody', fn() => Custody::balance());
        /* ⚠️ `$date` = احترام يوم العمل: ما ينفعش المالك وهو على يوم ٢٦ يشوف إذنًا
   تاريخه ٢٨ (بلاغ المالك 2026-07-28). المتأخرات بتفضل، المستقبل بس بيتشال. */
        $readyData = $profile('dashboard.ready', fn() => Metrics::readyToInvoiceDashboard(8, $date));
        /* ⚠️ `true` = **اليوم ده بالظبط**. بلاغ المالك: لوح «فلوس اليوم» كان بياخد
   السجل التراكمي (`entry_date <= $date`) بلا فلترة، فكان بيعرض بنود ٢٦
   على إنها بتاعة ٢٧. التاريخ لازم يتحترم ١٠٠٪. */
        $timeline = $profile('dashboard.feed', fn() => Metrics::expenseReceiptFeed($date, 40, true));

        $this->render('dashboard/index', [
            'title'    => 'لوحة التحكم',
            'date'     => $date,
            'year'     => $year,
            'daily'    => $profile('dashboard.daily', fn() => Metrics::dashDaily($date)),
            'counters' => $profile('dashboard.counters', fn() => Metrics::counters()),
            'permits'  => $profile('dashboard.permits', fn() => Metrics::permitsSummary()),
            'custody'  => $custodyBalance,
            /* سياق كروت الأرقام (المرحلة ج): مقارنة بامبارح + رسم آخر ١٤ يوم.
               ⚠️ استعلامان مجمَّعان بس — مقيس ~3ms، مقابل الرسوم الأربعة اللي
               اتشالت في المرحلة ٤ (36.5ms). ومصدره **نفس** مصدر `dashDaily`. */
            'ctx'      => $profile('dashboard.context', fn() => Metrics::dashContext($date, $custodyBalance)),
            'ready'    => $readyData['rows'],
            'readySum' => $readyData['summary'],
            /* ⚠️ **طلب المالك (2026-07-27) — الداشبورد بعدسة اليوم بلا أي تراكمي:**
                 ① الأذونات اللي اتفتحت النهاردة.
                 ② عهدة كل مندوب **في اليوم ده** (مش رصيده التراكمي).
               الاتنين بيقروا من مصادر قايمة — مفيش تعريف جديد لأي رقم.
               («الأذونات اللي اتحرّك عليها» اتشالت 2026-08-17: كانت بتكرّر
                لوح «فلوس اليوم» اللي تحتها بالحرف.) */
            'opened'   => $profile('dashboard.opened',  fn() => Metrics::permitsOpened($date)),
            'repsDay'  => $profile('dashboard.reps_day', fn() => RepCustody::dayTotalsByRep($date)),
            'repNames' => $profile('dashboard.rep_names', fn() => Rep::all('', 'active')),
            /* ⚠️ **«خرج كام عربية النهاردة»** — العدّ بس هنا (استعلام COUNT
               واحد)، والقايمة بتتجاب عند الضغط من `drill('cars')`. */
            'cars'     => $profile('dashboard.cars', fn() => Metrics::carsOutCount($date)),
            /* ⚠️ **«محتاجة تتقفل» و«ناقصها إيصالات» من مسح واحد** — لو كل
               واحد اتحسب لوحده كان ممكن الكارت يقول رقمًا واللوح يقول غيره.
               (`PermitReadiness::scan` بتشرح التعريف ومصدره.) */
            'readiness'=> $profile('dashboard.readiness', fn() => PermitReadiness::scan($date)),
            /* ⚠️ **معادلة الخزنة** — `dayView` بترجّع فتح/دخل/مصروف/عهدة/قفل،
               و`dayOfficeDeposits` بتفصل «اللي نزل للمكتب» **من جوّه**
               `expenses` (مش رقم زيادة عليها — تحذير `Closing`). */
            'safeDay'   => $profile('dashboard.safe_day', fn() => Closing::dayView($date)),
            'safeOffice'=> $profile('dashboard.safe_office', fn() => Closing::dayOfficeDeposits($date)),
            'feed'     => array_slice($timeline, 0, 12),
            'locked'   => Closing::isLocked($date),
            /* عهدة المناديب — الكروت والمدخلات مكانها `/rep-custody`.
               ⚠️ **`available()` مش `dashboard()`** (2026-08-17): الشاشة كانت
                  بتنادي `RepCustody::dashboard()` — استعلام مجمَّع تقيل —
                  عشان تقرا منه **مفتاح `available` وبس**، وترمي الباقي. */
            'repsOn'   => $profile('dashboard.reps_on', fn() => RepCustody::available()),
            'notes'    => $profile('dashboard.notes', fn() => Note::all(20)),
        ]);
    }

    /**
     * تفاصيل أي رقم في الداشبورد — قاعدة المالك «ممنوع رقم أصمّ».
     *
     * ⚠️ ده بديل `#popStore` القديم اللي كان بيرندر تفاصيل **كل** البوب-أبات
     *    مع كل فتحة للصفحة حتى لو المالك ما فتحش ولا واحد. دلوقتي البيانات
     *    بتتجاب وقت الضغط بس، فالصفحة خفّت والقاعدة بقت قابلة للتعميم.
     *
     * ⚠️ النوع محصور في **قائمة بيضا** — مش اسم جدول جاي من المستخدم.
     * ⚠️ بيرندر **بلا layout** ويرجّع HTML مش JSON عشان الصفوف تستخدم نفس
     *    مكوّن `row_item` بتاع الصفحة.
     */
    public function drill(string $what): void
    {
        $this->requireAuth();
        if (!in_array($what, ['expenses', 'receipts', 'custody', 'ready', 'permits_open',
                              'opened', 'reps_day', 'cars', 'needs_closing', 'receipt_gaps',
                              'safe_day'], true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }
        $date = Auth::workDate();
        $data = ['what' => $what, 'date' => $date];

        switch ($what) {
            case 'expenses':
            case 'receipts':
                $wantReceipt = ($what === 'receipts');
                $data['rows'] = array_values(array_filter(
                    Metrics::expenseReceiptFeed($date, 300, true),
                    static fn ($row) => (bool) $row['is_receipt'] === $wantReceipt
                ));
                $data['daily'] = Metrics::dashDaily($date);
                break;
            case 'cars':
                $data['rows'] = Metrics::carsOut($date, 200);
                break;
            case 'needs_closing':
            case 'receipt_gaps':
                /* ⚠️ **نفس المسح اللي عدّ الكارت** — النافذة بتاخد فرعًا منه
                   مش بتعيد الحساب، فمستحيل الكارت والنافذة يفترقوا. */
                $scan = PermitReadiness::scan($date);
                $data['rows']   = $what === 'needs_closing' ? $scan['ready'] : $scan['gaps'];
                $data['counts'] = $scan['counts'];
                break;
            case 'safe_day':
                /* ⚠️ `cday` هي **نفس** مصدر نافذة «رصيد الخزنة» — البنود
                   الفعلية بتتعرض تحت المعادلة بلا تعريف تاني. */
                $data['day']    = Closing::dayView($date);
                $data['office'] = Closing::dayOfficeDeposits($date);
                $data['cday']   = Metrics::custodyDay($date, Custody::balance());
                break;
            case 'opened':
                $data['rows'] = Metrics::permitsOpened($date);
                break;
            case 'reps_day':
                $data['rows']  = RepCustody::dayTotalsByRep($date);
                $data['names'] = Rep::all('', 'active');
                break;
            case 'custody':
                $balance = Custody::balance();
                $data['balance'] = $balance;
                $data['cday'] = Metrics::custodyDay($date, $balance);
                break;
            case 'ready':
                $ready = Metrics::readyToInvoiceDashboard(40, $date);
                $data['rows'] = $ready['rows'];
                $data['summary'] = $ready['summary'];
                break;
            default:   // permits_open — النوع محصور في القائمة البيضا فوق
                $data['rows'] = Metrics::openPermitsList(40, $date);
                $data['permits'] = Metrics::permitsSummary();
        }

        // ⚠️ محروسة بـ`headers_sent()`: أي مخرَج سابق بيخلّي `header()` ترمي
        //    Warning **جوّه جسم الرد** فالنافذة تستقبل HTML متلوّث (باج v50).
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('dashboard/_drill', $data, false);
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
