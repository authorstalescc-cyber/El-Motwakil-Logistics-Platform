<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\FormState;
use App\Core\PrivateFile;
use App\Models\Closing;
use App\Models\CompanySafe;
use App\Models\Custody;
use App\Models\RepCustody;
use App\Models\BankAccount;

/**
 * خزنة الشركة (v66 · v67 · **أُعيد تنظيمها في v68**) — الخزنة الأكبر اللي
 * بتغذّي خزنة المكتب.
 *
 * ═══ تنظيم v68 (بلاغ المالك: «الشكل مش عاجبني — عايزها احترافية وعصرية») ═══
 * الشاشة كانت **صفحة واحدة طويلة** فيها ٧ أقسام تحت بعض ونموذج إدخال مزروع في
 * نُصّها. بقت:
 *   • **ترويسة + إجراء أساسي واحد** (`page_head`) — «حركة جديدة».
 *   • **٤ تبويبات روابط حقيقية** (`?tab=`): نظرة عامة · الحركات · المصروفات ·
 *     التقارير. الرجوع بالمتصفح والمشاركة والفتح في تبويب جديد شغّالين.
 *   • **الإدخال في درج جانبي** بدل نموذج مضمَّن.
 *
 * ⚠️⚠️ **كل تبويب بيحمّل بياناته هو بس** — الاستعلامات مقسومة على `match`
 *    فمفيش شاشة بتحسب سجل ٢٠٠ صف عشان تعرض كارت رصيد. الصفحة القديمة كانت
 *    بتعمل كل استعلامات الأربع تبويبات في كل فتحة.
 *
 * ⚠️ **الوحدة معزولة عن كل حسابات الخزنة بالبناء** (`company_safe_entries`
 *    جدول خاص). أي أثر برّه الجدول بيمرّ على **API الوحدة التانية**:
 *    `Custody::createTopup` · `BankAccount::createEntry` · `RepCustody::createDeposit`
 *    — فمفيش سطر واحد في `Closing`/`DailyReview`/`Expense`/`Metrics` اتغيّر.
 *
 * ⚠️ **`Closing::guard` بيتنادى هنا وفي الموديل مع بعض** (حزام أمان مزدوج):
 *    هنا عشان الخطأ يرجع للفورم برسالته وبمدخلات المستخدم محفوظة، وهناك عشان
 *    أي مسار جديد ما يعديش من غير فحص.
 */
class CompanySafeController extends Controller
{
    /** أقصى حجم إيصال — نفس حد مستندات الأطراف (v56). */
    private const MAX_UPLOAD = 12 * 1024 * 1024;

    /** ⚠️ التبويبات قايمة بيضا — أي قيمة تانية من الرابط بترجع للافتراضي. */
    public const TABS = ['overview', 'moves', 'expenses', 'reports'];

    private static function tab(): string
    {
        $t = (string) ($_GET['tab'] ?? '');
        return in_array($t, self::TABS, true) ? $t : 'overview';
    }

    public function index(): void
    {
        $this->requireAuth();

        $tab    = self::tab();
        $day    = work_date();
        $mStart = date('Y-m-01', strtotime($day));
        $mEnd   = date('Y-m-t', strtotime($day));

        $f = [
            'kind' => (string) ($_GET['kind'] ?? ''),
            'cat'  => (string) ($_GET['cat'] ?? ''),
            'from' => (string) ($_GET['from'] ?? ''),
            'to'   => (string) ($_GET['to'] ?? ''),
            'q'    => (string) ($_GET['q'] ?? ''),
        ];
        $limit     = 200;
        $officeBal = Custody::balance();

        /* وضع الدرج: `?new=1` أو `?edit={id}` — **حالة في الرابط لا في
           الجافاسكريبت**، فالدرج بيفضل مفتوحًا لو الحفظ فشل، والرجوع بالمتصفح
           بيقفله. ⚠️ الرابط مش مصدر ثقة: الصف لازم يكون موجود وقابل للتعديل. */
        $editId  = (int) ($_GET['edit'] ?? 0);
        $editRow = $editId > 0 ? CompanySafe::find($editId) : null;
        if ($editRow && (string) $editRow['kind'] === 'from_client') { $editRow = null; }
        $drawer  = $editRow !== null || ($_GET['new'] ?? '') !== '';

        $data = [
            'title'      => 'خزنة الشركة',
            'tab'        => $tab,
            'day'        => $day,
            'mStart'     => $mStart,
            'mEnd'       => $mEnd,
            'ready'      => CompanySafe::ready(),
            'balance'    => CompanySafe::balance(),
            'officeBal'  => $officeBal,
            // ⚠️ نفس دالة شاشة التقفيل — مفيش تعريف تاني لأي رصيد محفظة.
            'wallets'    => Closing::walletSplit(),
            'budget'     => CompanySafe::budgetProgress($day),
            /* ⚠️ **تحذير وظيفي مش شرح**: المدير مايعرفش إن الدرج قرب يخلص غير
               لما يفتح شاشة تانية. الحدّ مشتقّ من سلوك الشركة نفسها. */
            'lowOffice'  => $officeBal < CompanySafe::officeLowThreshold(),
            'filters'    => $f,
            'cats'       => CompanySafe::EXPENSE_CATS,
            'drawer'     => $drawer,
            'editRow'    => $editRow,
            'sources'    => CompanySafe::recentSources(),
            'reps'       => RepCustody::available() ? RepCustody::activeReps() : [],
            'lastClosed' => Closing::lastClosedDate(),
            'entryState' => FormState::consume(
                $editRow ? 'company_safe.entry.' . (int) $editRow['id'] : 'company_safe.entry',
                'company-safe'),
        ];

        /* ⚠️⚠️ **كل تبويب بياناته هو بس.** الحساب المشترك فوق رخيص (أرصدة
           مجمّعة)، والغالي (السجل · الاتجاه · تقارير الفئات) بيتحمّل عند
           الحاجة. جمعهم كلهم في كل فتحة كان معناه ٦ استعلامات زيادة على كل
           ضغطة تبويب. */
        $data += match ($tab) {
            'moves' => [
                'rows'  => CompanySafe::ledger($f, $limit),
                'total' => CompanySafe::countLedger($f),
                'limit' => $limit,
            ],
            'expenses' => [
                'catRows'   => CompanySafe::byCategory($mStart, $mEnd),
                'partyRows' => CompanySafe::byParty($mStart, $mEnd, 12),
                'rows'      => CompanySafe::ledger(
                    ['kind' => 'expense', 'cat' => $f['cat'], 'from' => $mStart, 'to' => $mEnd], 100),
            ],
            /* ⚠️ **التقارير بتفتح على اليوم افتراضيًّا** (طلب المالك): الشهر كان
               بيخلّي أرقام التقرير تخالف كروت النظرة العامة اليومية بلا سبب
               ظاهر. الفترة كلها من `periodRange()` — المصدر الوحيد. */
            'reports' => (static function () use ($day) {
                $p = CompanySafe::periodRange($_GET['period'] ?? null, $day,
                                              $_GET['from'] ?? null, $_GET['to'] ?? null);
                return [
                    'period'      => $p,
                    'choices'     => CompanySafe::periodChoices(),
                    'rangeTotals' => CompanySafe::rangeTotals($p['from'], $p['to']),
                    'breakdown'   => CompanySafe::breakdownRange($p['from'], $p['to']),
                    'trend'       => self::trendBars($day),
                    'statement'   => CompanySafe::statement($p['from'], $p['to']),
                ];
            })(),
            default => [   // overview
                'breakdown' => CompanySafe::breakdown(),
                'dayTotals' => CompanySafe::dayTotals($day),
                'trend'     => self::trendBars($day),
                'recent'    => CompanySafe::ledger([], 8),
            ],
        };

        $this->render('company_safe/index', $data);
    }

    /**
     * أعمدة اتجاه آخر ١٤ يوم — استعلام واحد.
     * كل عمود بيفلتر تبويب الحركات على يومه، فالرسم **نقطة دخول مش صورة**.
     */
    private static function trendBars(string $day): array
    {
        $bars = [];
        foreach (CompanySafe::daily($day, 14) as $p) {
            $move = $p['in'] + $p['out'];
            $bars[] = [
                'label'   => (int) substr($p['d'], 8, 2),
                'value'   => $move,
                /* ⚠️ يوم بلا حركة بيفضل عمودًا رماديًّا **بلا رقم** — «0» مكرّرة
                   ١٠ مرات ضوضاء بتخفي الأيام اللي فيها شغل فعلاً. */
                'display' => $move > 0 ? money0($move, false) : '',
                'url'     => url('company-safe') . '?tab=moves&from=' . $p['d'] . '&to=' . $p['d'],
                'title'   => dfmt($p['d']) . ' — داخل ' . money($p['in'], false)
                           . ' · خارج ' . money($p['out'], false),
                'on'      => $p['d'] === $day,
            ];
        }
        // الرسم بيتخفي لو كل الأيام صفر — عمود فاضي × ١٤ مش معلومة.
        return array_filter($bars, static fn ($b) => $b['value'] > 0) ? $bars : [];
    }

    /**
     * تفاصيل أي رقم — قاعدة المالك «ممنوع رقم أصمّ».
     *
     * ⚠️ **قائمة بيضا** والمسار بيمرّ على `Access::enforce` زي أي مسار (الرفض
     *    هو الافتراضي — تحذير ٥). بيرندر بلا layout ويرجّع HTML عشان الصفوف
     *    تستخدم نفس مكوّن `row_item`.
     */
    /**
     * ⚠️⚠️ **كل كارت له نوع تفاصيل خاص بنطاقه بالظبط** (طلب المالك: «كل كارت
     *    بيتداس عليه بيعرض بياناته التفصيلية فقط وليس كل البيانات»).
     *    الأنواع الـ`day_*` مقصورة على **يوم العمل**، و`office_*` على تفكيك
     *    محفظة المكتب. خلط نطاقين في نافذة واحدة = رقم في الكارت ورقم مخالف
     *    في نافذته.
     */
    private const DRILL = [
        'balance', 'in', 'out', 'to_office', 'expense', 'day',
        'wallets', 'cats', 'parties', 'budget',
        'day_in', 'day_to_office', 'day_expense', 'office_total', 'office_drawer',
    ];

    public function drill(string $what): void
    {
        $this->requireAuth();
        if (!in_array($what, self::DRILL, true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }
        // ⚠️ محروسة بـ`headers_sent()` — أي مخرَج سابق بيلزق Warning جوّه جسم
        //    الرد فالنافذة تستقبل HTML متلوّث (باج v50).
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $day    = work_date();
        $mStart = date('Y-m-01', strtotime($day));
        $mEnd   = date('Y-m-t', strtotime($day));

        /* ⚠️ نافذة الفئات والجهات على **نفس شهر** الكارت اللي فوقها بالظبط —
           لو اتحسبت على كل الفترات هتقول رقمًا مختلفًا عن اللي المالك ضغط عليه
           (درس `/payments` في المرحلة ١٤). */
        $rows = match ($what) {
            'wallets', 'cats', 'parties', 'office_total', 'office_drawer' => [],
            'day'       => CompanySafe::ledger(['from' => $day, 'to' => $day], 500),
            'to_office' => CompanySafe::ledger(['kind' => 'to_office'], 500),
            'expense', 'budget' => CompanySafe::ledger(
                ['kind' => 'expense', 'from' => $mStart, 'to' => $mEnd], 500),
            // ── نطاق اليوم بالظبط: كل كارت وبنوده هو
            'day_in'        => CompanySafe::ledger(['dir' => 'in',  'from' => $day, 'to' => $day], 500),
            'day_to_office' => CompanySafe::ledger(['kind' => 'to_office', 'from' => $day, 'to' => $day], 500),
            'day_expense'   => CompanySafe::ledger(['kind' => 'expense',   'from' => $day, 'to' => $day], 500),
            default     => CompanySafe::ledger([], 500),
        };

        $needWallets = in_array($what, ['wallets', 'office_total', 'office_drawer'], true);

        $this->render('company_safe/_drill', [
            'what'      => $what,
            'day'       => $day,
            'mStart'    => $mStart,
            'mEnd'      => $mEnd,
            'breakdown' => CompanySafe::breakdown(),
            'dayTotals' => CompanySafe::dayTotals($day),
            'balance'   => CompanySafe::balance(),
            'budget'    => $what === 'budget' ? CompanySafe::budgetProgress($day) : null,
            'cats'      => $what === 'cats'    ? CompanySafe::byCategory($mStart, $mEnd) : [],
            'parties'   => $what === 'parties' ? CompanySafe::byParty($mStart, $mEnd) : [],
            // ⚠️ نفس `Closing::walletSplit()` بتاعة الشاشة وشاشة التقفيل.
            'wallets'   => $needWallets ? Closing::walletSplit() : null,
            'rows'      => $rows,
        ], false);
    }

    // ─────────────────────────────────────────────────────── الكتابة

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->save(0);
    }

    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->save((int) $id);
    }

    /**
     * ⚠️⚠️ **مسار حفظ واحد للإضافة والتعديل** — فورمان منفصلان كانوا معناهم إن
     *    أول حقل جديد يتضاف في واحد وينسى في التاني فيضيع **بلا أي خطأ ظاهر**
     *    (نفس درس تعديل مصروف السيارة). كل الفحوصات هنا مرّة واحدة.
     */
    private function save(int $id): void
    {
        $editing = $id > 0;
        $row     = $editing ? CompanySafe::find($id) : null;
        if ($editing && !$row) { $this->fail('الحركة مش موجودة.', 'company-safe'); }

        /* ⚠️ حقيبة `FormState` بتفرّق بين الإضافة والتعديل: لو الاتنين بنفس
           المفتاح، فشل التعديل بيرجّع البيانات في فورم الإضافة فالمالك يضيف
           نسخة تانية بدل ما يصلّح الأصلية.
           ⚠️ والرجوع بيفتح **الدرج** تاني (`?new=1`/`?edit=`) عشان المالك
              يلاقي اللي كتبه قدامه لا صفحة فاضية. */
        $bag  = $editing ? 'company_safe.entry.' . $id : 'company_safe.entry';
        $back = $editing ? 'company-safe?edit=' . $id : 'company-safe?new=1';
        $fail = fn (array $errors) => $this->formFailure($bag, $back, $_POST, $errors, 'company-safe');

        // ⚠️ في التعديل النوع بييجي من **الصف المحفوظ** لا من الفورم (ممنوع يتغيّر).
        $kind   = $editing ? (string) $row['kind'] : (string) ($_POST['kind'] ?? '');
        $amount = ($_POST['amount'] ?? '') === '' ? 0.0 : round((float) $_POST['amount'], 2);
        $date   = trim((string) ($_POST['entry_date'] ?? ''));
        $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : work_date();
        $source = trim((string) ($_POST['source'] ?? ''));
        $note   = trim((string) ($_POST['note'] ?? ''));

        /* ⚠️ **الفورم مش مصدر ثقة**: `from_client` مستبعد من `MANUAL_KINDS` عن
           قصد — بيتولّد من «سداد العملاء» بس، فتسجيله يدويًّا كان هيدي صفّين
           لنفس التحصيل. */
        if (!in_array($kind, CompanySafe::MANUAL_KINDS, true)) {
            $fail(['kind' => 'اختار نوع الحركة.']);
        }
        if ($amount <= 0) { $fail(['amount' => 'اكتب مبلغ أكبر من صفر.']); }

        // التحويلات مع المكتب بس هي اللي بتمسّ الدرج ⇒ هي وحدها اللي عليها قفل اليوم
        if (in_array($kind, CompanySafe::OFFICE_KINDS, true)) {
            foreach (array_unique(array_filter([$date, $editing ? (string) $row['entry_date'] : null])) as $chk) {
                if (($err = Closing::guard($chk)) !== null) { $fail(['entry_date' => $err]); }
            }
        }
        if (in_array($kind, CompanySafe::VISA_KINDS, true)
            && !BankAccount::isActive((int) ($_POST['bank_account_id'] ?? 0))) {
            $fail(['bank_account_id' => 'اختار الحساب البنكي.']);
        }
        if ($kind === 'to_rep' && !isset(RepCustody::activeReps()[(int) ($_POST['rep_id'] ?? 0)])) {
            $fail(['rep_id' => 'اختار المندوب.']);
        }

        /* ⚠️ فحص الرصيد على **الفرق** في التعديل مش على المبلغ كله (درس سقف عهدة
           البوليصة 2026-07-27): تعديل تاريخ حركة سحب كان هيترفض بلا سبب حقيقي. */
        if (!CompanySafe::isIn($kind)) {
            $need = $editing ? $amount - round((float) $row['amount'], 2) : $amount;
            if ($need > CompanySafe::balance() + 0.005) {
                $fail(['amount' => ($editing ? 'الزيادة' : 'المبلغ') . ' أكبر من رصيد خزنة الشركة ('
                    . money(CompanySafe::balance(), false) . '). قلّل المبلغ أو سجّل إيداع أولاً.']);
            }
        }

        // المرفق: صورة أو PDF. الرفع قبل `claimForm` عشان الخطأ يرجع للفورم معبّى.
        $attachment = null;
        try {
            $attachment = PrivateFile::storeUploaded(
                $_FILES['receipt'] ?? [], 'company_safe', PrivateFile::PERMIT_MIME, self::MAX_UPLOAD);
        } catch (\Throwable $e) {
            $fail(['receipt' => $e->getMessage()]);
        }

        $this->claimForm($back);
        $data = [
            'entry_date' => $date, 'kind' => $kind, 'amount' => $amount,
            'source' => $source, 'note' => $note,
            'category'     => $_POST['category'] ?? null,
            'bank_account_id' => $_POST['bank_account_id'] ?? null,
            'rep_id'       => $_POST['rep_id'] ?? null,
            'attachment'   => $attachment,
        ];
        try {
            if ($editing) { CompanySafe::update($id, $data); }
            else          { CompanySafe::create($data); }
        } catch (\Throwable $e) {
            /* ⚠️ الملف اترفع خلاص والحفظ وقع — لازم يتشال وإلا بيفضل يتيم على
               القرص للأبد (مفيش صف بيشاور عليه). */
            if ($attachment !== null) { PrivateFile::delete($attachment); }
            $fail(['amount' => $e->getMessage()]);
        }

        /* ⚠️ **تحذير مش منع** (فلسفة v49: الرصيد السالب بيسجّل واقعة حصلت).
           لكن لو الدرج بقى سالب لازم المالك يعرف **دلوقتي** مش لما يفتح
           التقفيل — رصيد سالب بيمرّ صامت هو بالظبط اللي بيتكشف متأخر. */
        $msg = $editing ? 'اتعدّلت الحركة.' : self::successMessage($kind, $amount);
        $officeAfter = Custody::balance();
        if (in_array($kind, CompanySafe::OFFICE_KINDS, true) && $officeAfter < -0.005) {
            $msg .= ' ⚠️ خزنة المكتب بقت بالسالب (' . money($officeAfter, false) . ').';
        }
        $b = CompanySafe::budgetProgress($date);
        if ($kind === 'expense' && $b['over']) {
            $msg .= ' ⚠️ عدّيت ميزانية الشهر بـ' . money(abs($b['left']), false) . '.';
        }
        // بعد الحفظ بنرجع لتبويب الحركات — المكان اللي الحركة الجديدة بتبان فيه.
        $this->done($msg, 'company-safe?tab=moves');
    }

    private static function successMessage(string $kind, float $amount): string
    {
        $m = money($amount, false);
        return match ($kind) {
            'to_office'   => 'اتحوّل ' . $m . ' من خزنة الشركة لخزنة المكتب.',
            'from_office' => 'اتسحب ' . $m . ' من خزنة المكتب لخزنة الشركة.',
            'expense'     => 'اتسجّل مصروف ' . $m . ' من خزنة الشركة.',
            'to_visa'     => 'اتحوّل ' . $m . ' للحساب البنكي.',
            'from_visa'   => 'اتسحب ' . $m . ' من الحساب البنكي لخزنة الشركة.',
            'to_rep'      => 'نزلت عهدة ' . $m . ' للمندوب من خزنة الشركة.',
            default       => 'اتضاف ' . $m . ' لخزنة الشركة.',
        };
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $row = CompanySafe::find((int) $id);
        if (!$row) { $this->fail('الحركة مش موجودة.', 'company-safe'); }
        $this->claimForm('company-safe');
        try {
            CompanySafe::delete((int) $id);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 'company-safe?tab=moves');
        }
        $extra = match (true) {
            !empty($row['custody_id'])      => ' ومعاها أثرها على خزنة المكتب.',
            !empty($row['visa_deposit_id']) => ' ومعاها حركة الحساب البنكي.',
            !empty($row['rep_deposit_id'])  => ' ومعاها تنزيل عهدة المندوب.',
            default                         => '.',
        };
        $this->done('اتمسحت الحركة' . $extra, 'company-safe?tab=moves');
    }

    /** سقف المصروف الشهري — إعداد واحد، فاضي = مفيش سقف. */
    public function saveBudget(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->claimForm('company-safe#budget');
        CompanySafe::setBudget($_POST['budget'] ?? null);
        $b = CompanySafe::budget();
        $this->done($b > 0 ? 'اتظبط سقف المصروف الشهري على ' . money($b, false) . '.'
                           : 'اتشال سقف المصروف الشهري.', 'company-safe?tab=expenses');
    }

    /**
     * كشف حساب الفترة للطباعة (A4).
     *
     * ⚠️ الفترة الافتراضية **الشهر الحالي** — بلا تاريخ الكشف بيطلع كل الحركات
     *    من أول يوم في النظام وده مش المقصود من زرار طباعة.
     */
    public function statement(): void
    {
        $this->requireAuth();
        $day  = work_date();
        $ok   = static fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) === 1;
        $from = $ok($_GET['from'] ?? null) ? (string) $_GET['from'] : date('Y-m-01', strtotime($day));
        $to   = $ok($_GET['to']   ?? null) ? (string) $_GET['to']   : date('Y-m-t',  strtotime($day));
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        /* ⚠️ **قسم «تفاصيل إضافية» أرصدة لحظية مش أرقام فترة** — رصيد الدرج
           والمناديب دلوقتي، مصدرهم `Closing::walletSplit()` نفسها اللي بتغذّي
           الشاشة وشاشة التقفيل. حسابهم هنا بالإيد كان هيدّي «كارت يقول رقم
           وكشف يقول رقم تاني»، والورقة بتقول صراحةً إنهم وقت الطباعة. */
        $this->render('company_safe/statement', [
            'title'   => 'كشف حساب خزنة الشركة',
            'st'      => CompanySafe::statement($from, $to),
            'wallets' => Closing::walletSplit(),
        ], false);
    }
}
