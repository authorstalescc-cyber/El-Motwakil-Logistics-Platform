<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\OpsReport;

/**
 * تقارير التشغيل — «إيه اللي اتحرّك في الفترة دي؟»
 *
 * ⚠️ **الوحدة قراءة فقط بالكامل — مفيش فيها ولا فورم كتابة واحد**، وكل
 *    مساراتها `GET`. `bin/verify_ops_report.php` بيفشل لو أي مسار `POST`
 *    اتضاف هنا أو أي كتابة دخلت `OpsReport`.
 *
 * ⚠️ **الفترة بتتمرّر لكل نافذة تفاصيل ولصفحة الطباعة** (`?p=&d=&from=&to=`).
 *    لو النافذة اتحسبت على فترة تانية هتقول رقمًا مختلفًا عن الشاشة — نفس
 *    درس `/payments` الموثَّق في المرحلة ١٤ ودرس `/cars` في v54.
 */
class OpsReportController extends Controller
{
    /**
     * أنواع نوافذ التفاصيل المسموح بيها — **قائمة بيضا**.
     * الأبعاد بتتولّد من `OpsReport::DIMS` فمستحيل تفترق عن التقسيمات المعروضة.
     */
    private const DRILL_STATS = ['containers', 'trips', 'permits', 'custody', 'deducted', 'mix', 'rows'];

    public function index(): void
    {
        $this->requireAuth();
        $p = $this->period();

        $totals = OpsReport::totals($p['from'], $p['to']);
        $prev   = $p['prev_from'] === null ? null : OpsReport::totals($p['prev_from'], $p['prev_to']);
        $daily  = OpsReport::daily($p['from'], $p['to']);

        // ⚠️ **بُعد واحد بس بيتحسب** — التبويبات روابط حقيقية (`?dim=`) مش
        //    تبديل بجافاسكريبت (قاعدة مكوّن `tabs`). ده كمان بيخلّي الصفحة
        //    تعمل استعلام تقسيم واحد بدل تسعة.
        $dim  = (string) ($_GET['dim'] ?? 'port');
        $tabs = OpsReport::dimTabs();
        if (!isset($tabs[$dim])) { $dim = 'port'; }
        $rows = $dim === 'mix'
            ? OpsReport::containerMix($p['from'], $p['to'])
            : OpsReport::breakdown($dim, $p['from'], $p['to']);

        $this->render('ops/index', [
            'title'     => 'تقارير التشغيل',
            'period'    => $p,
            'totals'    => $totals,
            'prev'      => $prev,
            'rates'     => OpsReport::rates($totals, $daily),
            'trend'     => OpsReport::trend($p['kind'], 12, $_GET['d'] ?? null),
            'dim'       => $dim,
            'tabs'      => $tabs,
            'rows'      => $rows,
            'attention' => OpsReport::attention($p['from'], $p['to']),
            'undated'   => OpsReport::undatedCount(),
        ]);
    }

    /**
     * تفاصيل أي رقم (قاعدة المالك: **ممنوع رقم أصمّ**).
     * بترندر بلا layout وبترجّع HTML للنافذة العامة `#uiDrill`.
     */
    public function drill(string $what): void
    {
        $this->requireAuth();
        $isDim = isset(OpsReport::DIMS[$what]);
        if (!$isDim && !in_array($what, self::DRILL_STATS, true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }

        $p = $this->period();
        $data = ['what' => $what, 'period' => $p, 'isDim' => $isDim, 'slice' => null];

        if ($isDim) {
            $data['rows'] = OpsReport::breakdown($what, $p['from'], $p['to']);
        } elseif ($what === 'mix') {
            $data['rows'] = OpsReport::containerMix($p['from'], $p['to']);
        } elseif ($what === 'permits') {
            $data['rows'] = OpsReport::permits($p['from'], $p['to'], OpsReport::TRIP_CAP);
        } elseif ($what === 'rows') {
            // بوالص قيمة واحدة من التوزيع («بوالص ميناء الإسكندرية بس»).
            // ⚠️ البُعد بيتفحص على `dimTabs()` — الفورم مش مصدر ثقة، وأي مفتاح
            //    مش في القايمة البيضا بيترفض بدل ما يتحوّل لشرط SQL.
            $sd = (string) ($_GET['dim'] ?? '');
            $sv = (string) ($_GET['v'] ?? '');
            if (!isset(OpsReport::dimTabs()[$sd]) || $sv === '') {
                $this->notFound('التقسيم ده مش موجود.');
            }
            $data['slice'] = ['dim' => $sd, 'value' => $sv,
                              'label' => OpsReport::dimTabs()[$sd]['label']];
            $data['rows'] = OpsReport::trips($p['from'], $p['to'], OpsReport::TRIP_CAP, $sd, $sv);
        } else {
            $data['rows'] = OpsReport::trips($p['from'], $p['to'], OpsReport::TRIP_CAP);
        }
        $data['totals'] = OpsReport::totals($p['from'], $p['to']);

        // ⚠️ محروسة بـ`headers_sent()`: أي مخرَج سابق بيخلّي `header()` ترمي
        //    Warning **جوّه جسم الرد** فالنافذة تستقبل HTML متلوّث (باج v50).
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('ops/_drill', $data, false);
    }

    /**
     * تقرير A4 للطباعة أو الحفظ PDF — صفحة مستقلة بلا القائمة الجانبية.
     *
     * ⚠️ **التوزيعات بتتولّد من `OpsReport::DIMS` مش مكتوبة بالإيد** — النسخة
     *    القديمة كانت بتطبع **٥ أبعاد من ٩**، فالسواقين والسيارات وشركات
     *    الشحن وأصحاب البيان كانوا **بيختفوا من الورقة بالسكوت** وهُمّ
     *    موجودين على الشاشة. أي بُعد جديد يتضاف للثابت بيطلع في المطبوع
     *    تلقائيًّا، ومستحيل يتنسي. (`verify_ops_report` بيعدّهم.)
     *
     * ⚠️ **الطباعة بتعرض نفس أرقام الشاشة كاملة** (قرار المالك: «تعرض كافة
     *    المعلومات»): المقارنة بالفترة السابقة والمعدلات و«محتاج انتباهك»
     *    والبوالص بلا تاريخ — كلها كانت على الشاشة وبس.
     */
    public function printable(): void
    {
        $this->requireAuth();
        $p = $this->period();

        $totals = OpsReport::totals($p['from'], $p['to']);
        $daily  = OpsReport::daily($p['from'], $p['to']);

        $dims = [];
        foreach (OpsReport::DIMS as $key => $def) {
            $dims[$key] = ['label' => $def[0],
                           'rows'  => OpsReport::breakdown($key, $p['from'], $p['to'])];
        }

        $this->render('ops/print', [
            'title'     => 'تقرير التشغيل — ' . $p['label'],
            'period'    => $p,
            'totals'    => $totals,
            'prev'      => $p['prev_from'] === null ? null
                            : OpsReport::totals($p['prev_from'], $p['prev_to']),
            'rates'     => OpsReport::rates($totals, $daily),
            'dims'      => $dims,
            'mix'       => OpsReport::containerMix($p['from'], $p['to']),
            'permits'   => OpsReport::permits($p['from'], $p['to']),
            'attention' => OpsReport::attention($p['from'], $p['to']),
            'undated'   => OpsReport::undatedCount(),
            'detail'    => !empty($_GET['detail']),
            'trips'     => !empty($_GET['detail']) ? OpsReport::trips($p['from'], $p['to']) : [],
        ], false);
    }

    // ═══════════════════════════════════════════════ مشترك

    /** الفترة من الرابط — **نقطة واحدة** تقرأها الشاشة والنافذة والطباعة. */
    private function period(): array
    {
        return OpsReport::period(
            (string) ($_GET['p'] ?? 'month'),
            $this->cleanDate($_GET['d'] ?? '') ?: work_date(),
            (string) ($_GET['from'] ?? ''),
            (string) ($_GET['to'] ?? '')
        );
    }

    private function cleanDate($raw): string
    {
        $raw = trim((string) $raw);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
    }
}
