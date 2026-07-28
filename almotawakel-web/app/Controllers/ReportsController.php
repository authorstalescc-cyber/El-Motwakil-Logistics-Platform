<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Metrics;

class ReportsController extends Controller
{
    /**
     * ⚠️ التبويبات **روابط حقيقية `?tab=`** مش تبديل بجافاسكريبت (قاعدة مكوّن
     *    `tabs`): الرجوع بالمتصفح والمشاركة والفتح في تبويب جديد بيشتغلوا،
     *    و**الصفحة بتحمّل بيانات تبويب واحد بدل الستة** — الشاشة القديمة كانت
     *    بتشغّل كل استعلامات التقارير مع كل فتحة حتى لو المالك شايف تبويبًا واحدًا.
     */
    private const TABS = ['overview', 'customers', 'payments', 'expenses', 'movements', 'permits'];

    /**
     * ⚠️ قائمة بيضا لأنواع التفاصيل — **ممنوع مسار تفاصيل عام** ياخد اسم جدول
     *    ويرجّع صفوف (قاعدة «ممنوع رقم أصمّ» §5أ في docs/14).
     */
    private const DRILL = ['freight', 'custody', 'expenses', 'collections', 'outstanding'];

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

    /** النطاق التاريخي المتحقَّق منه — مصدر واحد للشاشة والطباعة والتفاصيل. */
    private function range(): array
    {
        $from = trim((string) ($_GET['from'] ?? ''));
        $to   = trim((string) ($_GET['to']   ?? ''));
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : '';
        return [$from, $to, $from !== '' ? $from : null, $to !== '' ? $to : null];
    }

    private function show(bool $print): void
    {
        [$from, $to, $fromN, $toN] = $this->range();

        $tab = (string) ($_GET['tab'] ?? 'overview');
        if (!in_array($tab, self::TABS, true)) { $tab = 'overview'; }
        // الطباعة بتاخد كل حاجة — الورقة مستند كامل مش شاشة بتبويبات
        if ($print) { $tab = 'all'; }

        // سنة مخطّط الأداء الشهري: تُشتقّ من النطاق، وإلا سنة التقرير الافتراضية
        if ($toN)        { $year = (int) substr($toN, 0, 4); }
        elseif ($fromN)  { $year = (int) substr($fromN, 0, 4); }
        else             { $year = Metrics::reportYear(); }

        $monthNames = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

        $data = [
            'title'      => 'التقارير المالية والإدارية',
            'year'       => $year,
            'from'       => $from,
            'to'         => $to,
            'tab'        => $tab,
            'tabs'       => self::TABS,
            'totals'     => Metrics::totals($fromN, $toN),
            'monthNames' => $monthNames,
            'print'      => $print,
        ];

        /* ⚠️ كل استعلام هنا بيتشغّل **بس لو تبويبه معروض**. قبل كده كانوا الستة
           بيتشغّلوا مع كل فتحة — بما فيهم ٥٠٠ صف حركات و٥٠٠ دفعة. */
        $want = static fn (string $t): bool => $tab === 'all' || $tab === $t;

        if ($want('overview')) {
            $data['monthly']   = Metrics::monthly($year);
            $data['byCompany'] = Metrics::byCompany(15, $fromN, $toN);
            $data['debts']     = Metrics::clientDebts(15);
            // «حجم العمليات» اتنقل من الداشبورد (المرحلة ٤) — مكانه الطبيعي التقارير
            $data['trend']     = Metrics::activityTrend(14);
        }
        if ($want('customers')) { $data['customers']   = Metrics::customerProfit(25, $fromN, $toN); }
        if ($want('payments'))  { $data['payments']    = Metrics::paymentsReport($fromN, $toN); }
        if ($want('expenses'))  { $data['expenses']    = Metrics::expenseReport($fromN, $toN); }
        if ($want('movements')) { $data['movements']   = Metrics::movementsReport($fromN, $toN); }
        if ($want('permits'))   { $data['permitsList'] = Metrics::permitsReport($fromN, $toN); }

        if ($print) {
            $this->render('reports/print', $data, false);
        } else {
            $this->render('reports/index', $data);
        }
    }

    /**
     * تفاصيل رقم — بيرندر **بلا layout** ويرجّع HTML للنافذة العامة `#uiDrill`.
     *
     * ⚠️ النطاق بيتمرّر دايمًا: الرقم محسوب على الفترة المعروضة، فلو النافذة
     *    جابت الكل تبقى بتقول رقمًا تاني عن اللي المالك ضغط عليه.
     */
    public function drill(string $what): void
    {
        $this->requireAuth();
        if (!in_array($what, self::DRILL, true)) { $this->notFound('نوع التفاصيل ده مش معروف.'); }

        [$from, $to, $fromN, $toN] = $this->range();

        /* كل رقم بيفتح **المصدر اللي اتحسب منه فعلاً** — مش قايمة عامة. */
        $rows = match ($what) {
            'freight'     => Metrics::byCompany(50, $fromN, $toN),
            'custody'     => Metrics::customerProfit(50, $fromN, $toN),
            'expenses'    => $this->expenseRows($fromN, $toN),
            'collections' => Metrics::paymentsReport($fromN, $toN, 50)['rows'],
            'outstanding' => Metrics::clientDebts(50),
        };

        $this->render('reports/_drill', [
            'what'   => $what,
            'rows'   => $rows,
            'totals' => Metrics::totals($fromN, $toN),
            'from'   => $from,
            'to'     => $to,
        ], false);
    }

    /**
     * بنود المصروفات لنافذة التفاصيل — **مقسّمة بالمصدر**.
     *
     * ⚠️⚠️ **باجان اتصادوا هنا، والتاني ما ظهرش غير على بيانات الإنتاج:**
     *   ① أول نسخة كانت بتقرا `expenseReport()['categories']` بس — دي بنود
     *      الحركات فقط، فالكارت كان بيقول ٥١٣٬٣٥٠ والنافذة تفتح **فاضية**.
     *   ② وبعد ما ضفت البنود الأربعة (تخليص/فواتير/أذونات/يدوي) فضل **فرق
     *      ٦٬١٥٥ على الإنتاج**: `totals['expenses']` بيجي من `Expense::totals()`
     *      اللي بيحسب كمان **كاش المناديب ونثرياتهم**، وهما مش في `expenseReport`.
     *
     * ⚠️ **الحل: نقرا من `Expense::totals()` نفسها** اللي الكارت بيقرا منها —
     *    فالمطابقة **بالبناء** مش بجمع يدوي لازم يفتكر كل مصدر جديد. أي مصدر
     *    مصروفات يتضاف للنظام بعدين بيظهر في النافذة تلقائيًّا.
     *    («سداد المديونيات» مستبعد لأنه مش مصروف — نفس قاعدة `grand`.)
     */
    private function expenseRows(?string $from, ?string $to): array
    {
        $t    = \App\Models\Expense::totals(['from' => $from, 'to' => $to]);
        $rows = [];
        foreach ($t['by_source'] as $src => $b) {
            if ($src === 'debt' || (float) $b['value'] <= 0) { continue; }
            $rows[] = ['label' => $b['label'], 'value' => (float) $b['value'], 'cnt' => (int) $b['count']];
        }
        usort($rows, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        return $rows;
    }

    /** بروفايل عميل: كل التفاصيل والحركات السابقة وإجمالي المستحقّات */
    public function client(): void
    {
        $this->requireAuth();
        $name = trim((string) ($_GET['name'] ?? ''));
        if ($name === '') { $this->fail('اختار اسم العميل الأول.', 'reports'); }

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
