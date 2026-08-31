<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\FormState;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\PushNotify;
use App\Models\RepCustody;

/**
 * خزنة المكتب (`/custody`) — **أُعيد تنظيمها 2026-08-31** (docs/21 المرحلة ٣).
 *
 * ═══ الشكل ═══
 * كانت **صفحة واحدة طويلة**: ٤ كروت ← نموذج إدخال ← نموذج إدخال تاني ← سجل.
 * وده بالحرف الشكل اللي المالك رفضه في خزنة الشركة قبل v68. بقت:
 *   ترويسة + إجراء أساسي ← ٤ تبويبات (`?tab=`) ← جسم التبويب ← درج الإدخال.
 *
 * ⚠️⚠️ **كل تبويب بياناته هو بس.** «نظرة عامة» (الشاشة اللي بتفتح) بتستعمل
 *    الأرقام المجمّعة الرخيصة بس + حركة **النهارده**؛ وسجل الفترة الكامل
 *    بيتحمّل في تبويب «الحركات» لما يتطلب. مقيس على الإنتاج: الشهر الكامل
 *    **٠.٦٧ ثانية** (١,٩١٠ صف) والمجمّعات **٠.٠٩** واليوم **٠.٠١** — فتحميل
 *    الشهر في كل فتحة كان هيبطّئ الشاشة الافتتاحية ٧ أضعاف بلا داعي.
 *
 * ⚠️ **التبويبات روابط حقيقية** (`?tab=`) لا تبديل بالجافاسكريبت — الرجوع
 *    بالمتصفح والمشاركة والفتح في تبويب جديد كلها شغّالة (قاعدة `tabs.php`).
 *
 * ⚠️ **ولا رقم بيتحسب هنا** — كله من `Custody::movements()` و`Closing`.
 */
class CustodyController extends Controller
{
    /** ⚠️ قايمة بيضا — أي قيمة تانية من الرابط بترجع للافتراضي. */
    public const TABS = ['overview', 'moves', 'drivers', 'reps'];

    private static function tab(): string
    {
        $t = (string) ($_GET['tab'] ?? '');
        return in_array($t, self::TABS, true) ? $t : 'overview';
    }

    public function index(): void
    {
        $this->requireAuth();
        $tab = self::tab();
        [$from, $to] = $this->range();
        $day = work_date();

        /* الدرج: `?new=topup` أو `?new=rep` — **حالة في الرابط لا في
           الجافاسكريبت**، فالحفظ لو فشل بيرجع والدرج مفتوح وفيه اللي اتكتب. */
        $newKind = (string) ($_GET['new'] ?? '');
        $newKind = in_array($newKind, ['topup', 'rep'], true) ? $newKind : '';

        $data = [
            'title'      => 'خزنة المكتب',
            'tab'        => $tab,
            'day'        => $day,
            'from'       => $from,
            'to'         => $to,
            'rate'       => Custody::deductionRate(),
            /* ⚠️ تفكيك المحفظة — «الرصيد» معناه بيتغيّر مع النموذج الفعّال:
               في النموذج المجمّع هو الدرج **+ جيوب المناديب**. لازم الشاشة
               تفرّقهم وإلا بتقول «متاح» على فلوس مش في الدرج. */
            'wallet'     => Closing::walletSplit(),
            'breakdown'  => Closing::currentBreakdown(),
            'lastClosed' => Closing::lastClosedDate(),
            'aggregate'  => RepCustody::bankTopupActive(),
            'repList'    => RepCustody::available() ? RepCustody::activeReps() : [],
            'drawer'     => $newKind,
            'topupState' => FormState::consume('custody.topup.create', 'custody'),
            'repDepState'=> FormState::consume('custody.repdep.create', 'custody'),
        ];

        /* ⚠️ **الغالي بيتحمّل عند الحاجة بس** — شرحها في ترويسة الكلاس. */
        $data += match ($tab) {
            'moves'   => ['move' => Custody::movements($from, $to)],
            'drivers' => ['move' => Custody::movements($from, $to)],
            'reps'    => ['move' => null],
            default   => ['move' => Custody::movements($day, $day),   // نظرة عامة: النهارده بس
                          'series' => Closing::balanceSeries(14)],
        };

        $this->render('custody/index', $data);
    }

    /**
     * تفاصيل أي رقم في الشاشة — قاعدة المالك «ممنوع رقم أصمّ».
     *
     * ⚠️ **النوع محصور في قايمة بيضا**، والمسار بيمرّ على `requireAuth` زي أي
     *    مسار تاني (الرفض هو الافتراضي — تحذير ٣).
     *
     * ⚠️⚠️ **ما بيكتبش استعلامًا جديدًا** — بيقرا من نفس دوال `Custody`/`Closing`
     *    اللي الأرقام اتحسبت منها، فمستحيل النافذة تقول رقمًا والكارت يقول غيره.
     *    (الشاشة القديمة كانت بتوجّه أرقامها الأربعة لنفس النافذة بالحرف، يعني
     *    الأربعة كانوا بيدّوا نفس الجواب.)
     */
    public const DRILL = ['balance', 'drawer', 'reps', 'topups', 'expenses', 'driver', 'retained'];

    public function drill(string $what): void
    {
        $this->requireAuth();
        if (!in_array($what, self::DRILL, true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }
        /* ⚠️ محروسة بـ`headers_sent()` — أي مخرَج سابق بيلزق Warning جوّه جسم
           الرد فالنافذة تستقبل HTML متلوّث (باج v50). */
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        [$from, $to] = $this->range();
        /* الأرقام المجمّعة بتكفي التلاتة الأولى — بلا تحميل صفوف الفترة. */
        $needsRows = in_array($what, ['topups', 'expenses', 'driver', 'retained'], true);
        $this->render('custody/_drill', [
            'what'      => $what,
            'from'      => $from,
            'to'        => $to,
            'rate'      => Custody::deductionRate(),
            'wallet'    => Closing::walletSplit(),
            'breakdown' => Closing::currentBreakdown(),
            'aggregate' => RepCustody::bankTopupActive(),
            'move'      => $needsRows ? Custody::movements($from, $to) : null,
        ], false);
    }

    /** كشف حساب A4 للخزنة بفترة (صفحة مستقلّة للطباعة/حفظ PDF). */
    public function statement(): void
    {
        $this->requireAuth();
        [$from, $to] = $this->range();
        $this->render('custody/statement', [
            'title'     => 'كشف حساب خزنة المكتب',
            'move'      => Custody::movements($from, $to),
            'wallet'    => Closing::walletSplit(),
            'aggregate' => RepCustody::bankTopupActive(),
            'rate'      => Custody::deductionRate(),
        ], false);
    }

    /**
     * فترة العرض من الرابط، والافتراضي الشهر الحالي.
     *
     * ⚠️ التاريخ بيتفحص بالشكل قبل ما يوصل لأي استعلام — الرابط مش مصدر ثقة.
     */
    private function range(): array
    {
        [$defFrom, $defTo] = Custody::defaultRange();
        $from = self::cleanDate($_GET['from'] ?? '') ?: $defFrom;
        $to   = self::cleanDate($_GET['to'] ?? '') ?: $defTo;
        return $from > $to ? [$to, $from] : [$from, $to];
    }

    /** تاريخ بالشكل `Y-m-d` أو فاضي — أي حاجة تانية بترجع فاضية. */
    private static function cleanDate($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    // ─────────────────────────────────────────── الإضافات اليدوية (توب-أب)

    public function storeTopup(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        if ($data['amount'] <= 0) {
            $this->formFailure('custody.topup.create', 'custody#topup', $_POST,
                ['amount' => 'اكتب مبلغ إضافة أكبر من صفر.'], 'custody');
        }
        if ($err = Closing::guard($data['cust_date'])) {
            $this->formFailure('custody.topup.create', 'custody#topup', $_POST,
                ['cust_date' => $err], 'custody');
        }
        $this->claimForm('custody#topup');
        Custody::createTopup($data);
        flash('success', 'تمت إضافة العهدة إلى الرصيد.');
        redirect('custody');
    }

    public function editTopup(string $id): void
    {
        $this->requireAuth();
        $row = Custody::findTopup((int) $id);
        if (!$row) { flash('error', 'الإضافة غير موجودة.'); redirect('custody'); }
        $state = FormState::consume('custody.topup.edit.' . (int) $id, 'custody');
        $this->render('custody/topup_form', [
            'title'  => 'تعديل إضافة عهدة',
            'row'    => array_replace($row, $state['old']),
            'action' => url('custody/topup/' . $id),
            'errors' => $state['errors'],
            'formToken' => $state['token'],
            'back' => $state['back'],
        ]);
    }

    public function updateTopup(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        $old  = Custody::findTopup((int) $id);
        $formId = 'custody.topup.edit.' . (int) $id;
        $formPath = 'custody/topup/' . (int) $id . '/edit';
        if ($data['amount'] <= 0) {
            $this->formFailure($formId, $formPath, $_POST,
                ['amount' => 'اكتب مبلغ إضافة أكبر من صفر.'], 'custody');
        }
        // امنع لو التاريخ الجديد أو القديم يقع ضمن فترة متقفلة
        if ($err = (Closing::guard($data['cust_date']) ?? ($old ? Closing::guard($old['cust_date']) : null))) {
            $this->formFailure($formId, $formPath, $_POST, ['cust_date' => $err], 'custody');
        }
        $this->claimForm('custody');
        Custody::updateTopup((int) $id, $data);
        flash('success', 'تم حفظ التعديلات.');
        redirect('custody');
    }

    public function deleteTopup(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $old = Custody::findTopup((int) $id);
        if ($old && ($err = Closing::guard($old['cust_date']))) { flash('error', $err); redirect('custody'); }
        $this->claimForm('custody');
        Custody::deleteTopup((int) $id);
        flash('success', 'تم حذف الإضافة.');
        redirect('custody');
    }

    // ───────────────────── نزّل عهدة لمندوب **من درج المكتب** (المصدر = office)

    /**
     * الكاش بيخرج من الدرج لجيب المندوب — **من صفحة الخزنة نفسها** بدل ما المالك
     * يفتح كارت المندوب ويفتكر يختار المصدر صح.
     *
     * ⚠️⚠️ **المصدر مثبَّت `office` في الكود مش جاي من الفورم** — الشاشة دي معناها
     *    «الفلوس دي طلعت من الدرج اللي قدّامي»، فقبول مصدر من الفورم كان بيسمح
     *    بتسجيل `bank` (فلوس جديدة داخلة) من مكان بيقول العكس.
     *
     * ⚠️ **مفيش منطق حساب جديد هنا**: نفس `RepCustody::createDeposit()` ونفس
     *    `depositAffectsSafe()` بتوع كارت المندوب بالحرف — الشاشة نقطة دخول تانية
     *    لنفس الإجراء، مش مسار تاني ممكن يفترق عنه.
     */
    public function storeRepDeposit(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $repId  = (int) ($_POST['rep_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $date   = trim((string) ($_POST['dep_date'] ?? ''));
        $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : work_date();
        $note   = trim((string) ($_POST['note'] ?? ''));
        $fail   = fn (array $errors) => $this->formFailure(
            'custody.repdep.create', 'custody#rep-deposit', $_POST, $errors, 'custody');

        // الفورم مش مصدر ثقة: لازم مندوب **نشط** موجود فعلاً
        if (!isset(RepCustody::activeReps()[$repId])) { $fail(['rep_id' => 'اختار المندوب الأول.']); }
        if ($amount <= 0) { $fail(['amount' => 'اكتب مبلغ العهدة (أكبر من صفر).']); }

        $source = 'office';
        if (RepCustody::depositAffectsSafe($source) && ($lock = Closing::guard($date)) !== null) {
            $fail(['dep_date' => $lock]);
        }

        $this->claimForm('custody#rep-deposit');
        RepCustody::createDeposit($repId, $amount, $date, $note, $source);
        // ⚠️ الإشعار بعد نجاح العملية وخارج أي transaction (docs/15 §٨.٢)
        PushNotify::repDeposit($repId, (string) (RepCustody::activeReps()[$repId] ?? 'مندوب'),
                               $amount, $date, (string) (Auth::user() ?? 'المكتب'));
        $this->done('نزلت عهدة ' . money($amount, false) . ' من الدرج لـ'
                    . (RepCustody::activeReps()[$repId] ?? 'المندوب') . '.', 'custody', 'rep-deposit');
    }

    private function collect(): array
    {
        $date = trim($_POST['cust_date'] ?? '');
        return [
            'cust_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : work_date(),
            'driver'    => trim($_POST['driver'] ?? ''),
            'amount'    => ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'],
            'note'      => trim($_POST['note'] ?? ''),
        ];
    }
}
