<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\CompanySafe;
use App\Models\SafeAudit;
use App\Models\SafeInventory;

/**
 * جرد الخزينة (v108) — **الخزنتان في كشف واحد**.
 *
 * ═══ اللزمة (طلب المالك) ═══
 * «وحدة جديدة اسمها جرد خزينة — سواء المكتب أو خزينة الشركة في كشف واحد.»
 *
 * ═══ تلات تبويبات = تلات أسئلة ═══
 *   • **الجرد**  — الكاش فين دلوقتي، وهل اللي في الدرج = اللي في الورق؟
 *   • **الكشف**  — حركة الخزنتين في الفترة، مدموجة زمنيًّا في جدول واحد.
 *   • **السجل**  — كل عمليات الجرد السابقة وفروقها.
 *
 * ⚠️ **التبويبات روابط حقيقية** (`?tab=`) لا تبديل بالجافاسكريبت — الرجوع
 *    بالمتصفح والمشاركة والفتح في تبويب جديد شغّالين (قاعدة `tabs.php`).
 * ⚠️ **كل تبويب بيحمّل بياناته هو بس** (`match`) — تبويب الجرد ما بيحسبش كشف
 *    شهر عشان يعرض كارت رصيد.
 *
 * ⚠️⚠️ **الوحدة ما بتكتبش ولا مليم في أي محفظة.** الكتابة الوحيدة فيها صفّ
 *    شهادة في `safe_audits` (جدول معزول بالبناء)، **والفرق ما بيتقيّدش**: لو
 *    المالك عايز يصحّح، بيسجّل الحركة في وحدتها وتبان في السجل باسمها.
 *
 * ⚠️ **تسجيل الجرد للمالك والمدير بس** — الكنترولر مش في
 *    `Access::FINANCIAL_CONTROLLERS` عن قصد: الجرد **شهادة** والمفروض تبقى
 *    باسم اللي مسؤول عن الخزنة. باقي الأدوار بتقرا الكشف عادي (GET مفتوح).
 */
class SafeAuditController extends Controller
{
    /** ⚠️ قايمة بيضا — أي قيمة تانية من الرابط بترجع للافتراضي. */
    public const TABS = ['count', 'moves', 'history'];

    private static function tab(): string
    {
        $t = (string) ($_GET['tab'] ?? '');
        return in_array($t, self::TABS, true) ? $t : 'count';
    }

    /**
     * ⚠️ **الفترة من `CompanySafe::periodRange()` — المصدر الوحيد** (v67).
     *    كتابة منطق فترة تاني هنا كان معناه إن «الشهر» يعني حاجة في شاشة
     *    وحاجة تانية في الشاشة اللي جنبها.
     */
    private static function period(string $day): array
    {
        return CompanySafe::periodRange($_GET['period'] ?? null, $day,
                                        $_GET['from'] ?? null, $_GET['to'] ?? null);
    }

    public function index(): void
    {
        $this->requireAuth();

        $tab = self::tab();
        $day = work_date();

        $data = [
            'title' => 'جرد الخزينة',
            'tab'   => $tab,
            'day'   => $day,
            'ready' => SafeAudit::ready(),
        ];

        $data += match ($tab) {
            'moves' => (static function () use ($day) {
                $p = self::period($day);
                return [
                    'period'  => $p,
                    'choices' => CompanySafe::periodChoices(),
                    'st'      => SafeInventory::statement($p['from'], $p['to']),
                ];
            })(),
            'history' => (static function () {
                $f = [
                    'safe'      => (string) ($_GET['safe'] ?? ''),
                    'from'      => (string) ($_GET['from'] ?? ''),
                    'to'        => (string) ($_GET['to'] ?? ''),
                    'only_diff' => ($_GET['only_diff'] ?? '') !== '',
                ];
                return [
                    'filters' => $f,
                    'rows'    => SafeAudit::history($f, 300),
                    'total'   => SafeAudit::countHistory($f),
                    'limit'   => 300,
                    'stats'   => SafeAudit::stats($f),
                ];
            })(),
            default => (static function () use ($day) {
                /* ⚠️ جرد **النهاردة** لكل خزنة بيتجاب لوحده عشان الفورم يفتح
                   بالقيمة المسجّلة (تصحيح عدّة) بدل خانة فاضية بتوهم إن مفيش جرد. */
                $today = [];
                foreach (SafeInventory::SAFES as $s) { $today[$s] = SafeAudit::on($day, $s); }
                return [
                    'snap'   => SafeInventory::snapshot(),
                    'today'  => $today,
                    'recent' => SafeAudit::history([], 6),
                    'stats'  => SafeAudit::stats([]),
                ];
            })(),
        };

        $this->render('safe_audit/index', $data);
    }

    // ─────────────────────────────────────────────────────── التفاصيل

    /**
     * تفاصيل أي رقم — قاعدة المالك «ممنوع رقم أصمّ».
     *
     * ⚠️ **قائمة بيضا**، والمسار بيمرّ على `Access::enforce` زي أي مسار.
     *    بيرندر بلا layout عشان الصفوف تستخدم نفس مكوّن `row_item`.
     */
    private const DRILL = [
        'office', 'company', 'reps', 'grand',
        'period_in', 'period_out', 'period_net', 'transfers', 'audits',
    ];

    public function drill(string $what): void
    {
        $this->requireAuth();
        if (!in_array($what, self::DRILL, true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }
        // ⚠️ محروسة بـ`headers_sent()` — أي مخرَج سابق بيلزق Warning جوّه الرد.
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }

        $day = work_date();
        /* ⚠️ نوافذ الفترة بتقرا **نفس** بارامترات الشاشة اللي فوقها — لو
           اتحسبت على فترة افتراضية هتقول رقمًا مخالفًا للكارت اللي المالك
           ضغط عليه (درس `/payments` في المرحلة ١٤). */
        $needPeriod = in_array($what, ['period_in', 'period_out', 'period_net', 'transfers'], true);
        $p  = $needPeriod ? self::period($day) : null;
        $st = $needPeriod ? SafeInventory::statement($p['from'], $p['to']) : null;

        $this->render('safe_audit/_drill', [
            'what'   => $what,
            'day'    => $day,
            'period' => $p,
            'st'     => $st,
            'snap'   => $needPeriod ? null : SafeInventory::snapshot(),
            'audits' => $what === 'audits' ? SafeAudit::history([], 40) : [],
        ], false);
    }

    // ─────────────────────────────────────────────────────── كشف A4

    /**
     * كشف الجرد للطباعة — صفحة A4 مستقلّة.
     *
     * ⚠️ **بيسأل عن الفترة قبل ما يفتح** (نفس نمط كشف خزنة الشركة v67):
     *    الطباعة بلا سؤال كانت بتخلّي المالك يطبع ورقة غلط ويرجع.
     */
    public function statement(): void
    {
        $this->requireAuth();
        $day = work_date();
        $p   = self::period($day);
        $st  = SafeInventory::statement($p['from'], $p['to']);
        $this->render('safe_audit/statement', [
            'title'  => 'كشف جرد الخزينة',
            'period' => $p,
            'st'     => $st,
            'snap'   => SafeInventory::snapshot(),
            'audits' => SafeAudit::history(['from' => $p['from'], 'to' => $p['to']], 100),
        ], false);
    }

    // ─────────────────────────────────────────────────────── الكتابة

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $safe = (string) ($_POST['safe'] ?? '');
        $date = (string) ($_POST['audit_date'] ?? '');
        $back = 'safe-audit?tab=count';

        /* ⚠️ **الحارس هنا وفي الموديل مع بعض** (حزام أمان مزدوج): هنا عشان
           الرسالة ترجع للمالك في مكانها، وهناك عشان أي مسار جديد ما يعديش من
           غير فحص — الفورم مش مصدر ثقة (القاعدة ③). */
        if (!SafeInventory::isSafe($safe)) {
            $this->fail('اختار الخزنة الأول.', $back);
        }

        try {
            SafeAudit::save($date, $safe, (string) ($_POST['counted'] ?? ''),
                            $_POST['note'] ?? null);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $back);
        }

        $row  = SafeAudit::on($date ?: work_date(), $safe);
        $diff = $row ? round((float) $row['diff'], 2) : 0.0;
        /* ⚠️ **الرسالة بتقول النتيجة مش «تم الحفظ»**: الجرد كل قيمته في الفرق،
           والمالك المفروض يعرفه من غير ما يدوّر عليه في الشاشة. */
        $msg = abs($diff) < 0.005
            ? 'الجرد اتسجّل — ' . SafeInventory::label($safe) . ' مطابقة بالظبط.'
            : 'الجرد اتسجّل — ' . ($diff > 0 ? 'زيادة ' : 'عجز ')
              . money(abs($diff), false) . ' في ' . SafeInventory::label($safe) . '.';
        $this->done($msg, $back, 'safeCount', abs($diff) < 0.005 ? 'success' : 'warning');
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        try {
            SafeAudit::delete((int) $id);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 'safe-audit?tab=history');
        }
        $this->done('الجرد اتمسح.', 'safe-audit?tab=history');
    }
}
