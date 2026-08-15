<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** فحص الخطة 04: عزل النماذج، الرجوع الآمن، ومنع الطلب المكرر. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\FormState;

$pass = 0; $fail = 0;
function chk(bool $ok, string $label): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? "  ✅ " : "  ❌ ") . $label . "\n"; }

$session = $_SESSION;
$get = $_GET;
$post = $_POST;
$server = $_SERVER;

try {
    unset($_SESSION['_form_bags'], $_SESSION['_used_form_tokens']);
    echo "\n═══ فحص النماذج والرسائل والسياق ═══\n\n";

    $a = FormState::token(); $b = FormState::token();
    chk((bool) preg_match('/^[a-f0-9]{32}$/', $a), 'رمز النموذج عشوائي بالصيغة المتوقعة');
    chk($a !== $b, 'تبويبان يحصلان على رمزين مختلفين');

    $_POST = ['_form_token'=>$a, '_back'=>'/expenses?q=وقود&page=2#row-9', 'amount'=>'11', '_csrf'=>'secret'];
    FormState::store('expenses.create', $_POST, ['amount'=>'خطأ أ'], 'expenses');
    $_POST = ['_form_token'=>$b, '_back'=>'/expenses?q=مكتب', 'amount'=>'22'];
    FormState::store('expenses.create', $_POST, ['amount'=>'خطأ ب'], 'expenses');

    $_GET = ['_form'=>$b];
    $bagB = FormState::consume('expenses.create', 'expenses');
    chk(($bagB['old']['amount'] ?? '') === '22', 'التبويب الثاني يسترجع قيمه وحده');
    chk(($bagB['errors']['amount'] ?? '') === 'خطأ ب', 'التبويب الثاني يسترجع خطأه وحده');
    chk($bagB['back'] === 'expenses?q=مكتب', 'سياق الرجوع مع query محفوظ');

    $_GET = ['_form'=>$a];
    $bagA = FormState::consume('expenses.create', 'expenses');
    chk(($bagA['old']['amount'] ?? '') === '11', 'التبويب الأول لم تختلط قيمه بالثاني');
    chk(!array_key_exists('_csrf', $bagA['old']), 'لا تُخزّن قيمة CSRF داخل old input');
    chk($bagA['back'] === 'expenses?q=وقود&page=2#row-9', 'الفلتر والصفحة والمرساة محفوظة');

    $multi = FormState::token();
    $_POST = ['_form_token'=>$multi, 'amount'=>'44'];
    FormState::store('debts.visa.create', $_POST, ['amount'=>'خطأ'], 'debts?tab=visa');
    $_GET = ['_form'=>$multi];
    $otherForm = FormState::consume('debts.account.create', 'debts?tab=others');
    $targetForm = FormState::consume('debts.visa.create', 'debts?tab=visa');
    chk($otherForm['token'] !== $multi, 'النموذج الآخر في الصفحة لا يرث رمز حقيبة الخطأ');
    chk($targetForm['token'] === $multi && ($targetForm['old']['amount'] ?? '') === '44',
        'النموذج المقصود وحده يستعيد الرمز والقيم');
    chk(FormState::claim($otherForm['token']) && FormState::claim($targetForm['token']),
        'نماذج الصفحة المتعددة تظل مستقلة وقابلة للحفظ');

    $_GET = ['_form'=>$a];
    $gone = FormState::consume('expenses.create', 'expenses');
    chk($gone['old'] === [], 'الحقيبة تُستهلك مرة واحدة ولا تتسرب لطلب لاحق');

    chk(safe_back('expenses', 'https://evil.example/steal') === 'expenses', 'safe_back يرفض الرابط الخارجي');
    chk(safe_back('expenses', '//evil.example/steal') === 'expenses', 'safe_back يرفض الرابط protocol-relative');
    chk(safe_back('expenses', '/payments?route=cash#pay') === 'payments?route=cash#pay', 'safe_back يقبل المسار الداخلي وسياقه');
    chk(safe_back('expenses', "/payments\nLocation:https://evil") === 'expenses', 'safe_back يرفض محارف التحكم');

    $once = FormState::token();
    chk(FormState::claim($once), 'أول طلب بالرمز يُقبل');
    chk(!FormState::claim($once), 'تكرار الطلب نفسه يُرفض');
    chk(!FormState::claim('not-a-token'), 'الرمز غير الصحيح لا يصل للكتابة');

    $_POST = ['_form_token'=>$once, '_back'=>'/payments', 'amount'=>'33'];
    $retry = FormState::store('payments.pay', $_POST, ['_form'=>'تعذر الحفظ'], 'payments');
    chk($retry !== $once, 'فشل بعد claim يصدر رمزًا جديدًا صالحًا لإعادة المحاولة');
    chk(FormState::claim($retry), 'رمز إعادة المحاولة الجديد قابل للاستهلاك');

    $sources = [
        'المصروفات' => 'app/Views/expenses/form.php',
        'التحصيلات' => 'app/Views/payments/index.php',
        'إدارة الإذن' => 'app/Views/permits/_body_manage.php',   // اتقسم في المرحلة ١٣ (الغلاف permits/show.php)
    ];
    foreach ($sources as $label => $file) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
        chk(str_contains($src, 'formStateFields') && str_contains($src, 'data-primary-submit'), "$label تستخدم حالة النموذج ومنع الإرسال المزدوج");
    }

    $financialWaves = [
        'العهدة' => [
            'view' => 'app/Views/custody/index.php',
            'controller' => 'app/Controllers/CustodyController.php',
            'formId' => 'custody.topup.create',
        ],
        'مديونيات العملاء والفيزا' => [
            'view' => 'app/Views/debts/index.php',
            'controller' => 'app/Controllers/DebtController.php',
            'formId' => 'debts.visa.create',
        ],
        'تسويات البوالص' => [
            'view' => 'app/Views/settlements/show.php',
            'controller' => 'app/Controllers/SettlementController.php',
            'formId' => 'settlements.payment.',
        ],
        'الفواتير' => [
            'view' => 'app/Views/invoices/index.php',
            'controller' => 'app/Controllers/InvoiceController.php',
            'formId' => 'invoices.bulk.void',
        ],
    ];
    foreach ($financialWaves as $label => $files) {
        $view = (string) file_get_contents(dirname(__DIR__) . '/' . $files['view']);
        $controller = (string) file_get_contents(dirname(__DIR__) . '/' . $files['controller']);
        chk(str_contains($view, 'formStateFields') && str_contains($view, 'formErrorSummary'),
            "$label تعرض حالة النموذج وملخص أخطاء مفهوم");
        chk(str_contains($controller, $files['formId'])
            && str_contains($controller, 'formFailure(')
            && str_contains($controller, 'claimForm('),
            "$label تحفظ القيم وتمنع تكرار الكتابة");
    }

    $v41 = (string) file_get_contents(dirname(__DIR__) . '/bin/verify_v41.php');
    // ⚠️ كارت المندوب اتقسم لتبويبات (إعادة تصميم 2026-07-25): الغلاف `card.php`
    //    + `_tab_*.php`. الفحص بيقرا **الشاشة كلها** مش الغلاف، وإلا بيفشل
    //    بإنذار كاذب لمجرد إن النموذج اتنقل لملف تبويبه.
    $repCard = '';
    foreach (glob(dirname(__DIR__) . '/app/Views/rep_custody/{card,_tab_*}.php', GLOB_BRACE) ?: [] as $f) {
        $repCard .= (string) file_get_contents($f);
    }
    $repCustodyController = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/RepCustodyController.php');
    /* ⚠️ **كان مربوطًا بجملة حرفية** («هذا وضع عمل مسموح») — وده بالظبط اللي
       التوثيق بيحذّر منه: الفحص المكتوب على تسمية بيفشل بإنذار كاذب أول إعادة
       صياغة مشروعة. حصل فعلاً لما الجملة اتقصّت لتحذير ≤٦ كلمات (قانون التصميم).
       الضمانة الحقيقية اتنين ولسه محروستين: **السلوك** (الرصيد السالب مقبول —
       `verify_v41`) و**الإبلاغ** (الشاشة بتقول إن ده مستحق للمندوب) — والتاني
       بيتفحص بالمعنى دلوقتي مش بالصياغة. */
    chk(str_contains($v41, 'RepCustody::balance($repId) === -10.0')
        && str_contains($repCard, 'مستحق للمندوب'),
        'الرصيد السالب لعهدة المندوب يظل مسموحًا ويُبلَّغ كمستحق للمندوب');
    chk(str_contains($repCard, 'formStateFields($depositState[\'token\']')
        && str_contains($repCard, 'formStateFields($settleState[\'token\']')
        && str_contains($repCustodyController, "formFailure('rep_custody.deposit.")
        && str_contains($repCustodyController, "formFailure('rep_custody.settle."),
        'تنزيل عهدة المندوب وتسويتها يحفظان القيم والأخطاء لكل نموذج');
    // مسارات الرجوع بقت بتتبني من `cardUrl()` عشان كل حفظ يرجّع لتبويبه — الفحص
    // على **السلوك** (كل كتابة بتاخد رمز محاولة) مش على شكل السلسلة النصّية.
    chk(substr_count($repCustodyController, 'claimForm(self::cardUrl($repId') >= 4,
        'كتابات كارت عهدة المندوب المالية محمية من تكرار الطلب');
    /* ⚠️ **الشاشة + بارشيال الدرج مع بعض** (v95): ماركب درج التعديل اتنقل
       لـ`partials/rep_submission_edit` عشان تبويبَي «مستني» و«معتمد» يستخدموا
       نفس الحقول. السلوك المفحوص هو هو — بس مقسوم على ملفين. */
    $repInbox = (string) file_get_contents(dirname(__DIR__) . '/app/Views/rep_custody/inbox.php')
              . (string) file_get_contents(dirname(__DIR__) . '/app/Views/partials/rep_submission_edit.php');
    // ⚠️ الفحص على **السلوك** مش على أسماء المتغيّرات: إعادة بناء الصندوق
    //    (2026-07-25) شالت رمز اليوم `$dayToken` لأن الصفحة بقت فورمًا واحدًا،
    //    والسلوك المطلوب زي ما هو — رمز إعادة المحاولة بيرجع للفورم المتعثر،
    //    ولكل بند رمزه المستقل، ودرج التعديل بيتفتح على البند اللي فشل.
    chk(str_contains($repCustodyController, "FormState::consume('rep_custody.inbox'")
        && str_contains($repInbox, '$bulkFailed ? $inboxState[\'token\'] : FormState::token()')
        && str_contains($repInbox, '$rowToken(')
        && str_contains($repInbox, '$editFailed ? \'\' : \'hidden\''),
        'صندوق المراجعة يعزل المحاولات ويعيد فتح تعديل البند المتعثر');
    chk(str_contains($repCustodyController, '$safeOld = [\'username\' => $username]')
        && !str_contains($repCustodyController, '$safeOld = $_POST')
        && str_contains($repCard, 'formStateFields($accountState[\'token\']'),
        'حساب المندوب يستعيد اسم الدخول من دون تخزين PIN الخام');
    chk(str_contains($repCustodyController, '[\'permit_ids\' => $failedIds, \'note\' => $note]')
        && str_contains($repCard, '$selectedPermits'),
        'إعادة محاولة تنزيل الأذونات تحمل العناصر التي فشلت فقط');

    $settlement = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/SettlementController.php');
    chk(str_contains($settlement, 'FOR UPDATE')
        && str_contains($settlement, '$amount > $remaining + 0.005')
        && str_contains($settlement, 'beginTransaction()'),
        'سداد البوالص يرفض تجاوز المتبقي ويكتب الدفعة الجماعية ذريًا');
    chk(str_contains($settlement, '(int)$payment[\'bill_id\'] !== (int)$id'),
        'حذف دفعة البوليصة يتحقق من ملكيتها للمسار');

    $permitInvoice = (string) file_get_contents(dirname(__DIR__) . '/app/Views/permits/invoice_form.php');
    chk(str_contains($permitInvoice, 'formStateFields($voidState[\'token\']')
        && str_contains($permitInvoice, 'formErrorSummary($voidState'),
        'إلغاء الفاتورة من داخل الإذن يرسل الرمز ويحفظ سبب الفشل');
    $crud = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CrudController.php');
    chk(str_contains($crud, 'FormState::consume') && !str_contains($crud, "\$_SESSION['_old']"),
        'CRUD العام انتقل من الحقيبة العامة إلى FormState');
    $auth = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/AuthController.php');
    chk(str_contains($auth, "formFailure('auth.login'") && !str_contains($auth, "\$_SESSION['_old']"),
        'فشل الدخول معزول ولا يستخدم _old عامة');
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
    $uiSources = $js;
    foreach (glob(dirname(__DIR__) . '/app/Views/*/*.php') ?: [] as $view) { $uiSources .= "\n" . file_get_contents($view); }
    chk(!preg_match('/(?<![.\w])(?:prompt|alert)\s*\(|window\.confirm\s*\(/', $uiSources),
        'لا توجد prompt/alert/window.confirm أصلية في الواجهات');
    chk(str_contains($js, 'قد يكون الخادم نفّذ الكتابة'), 'فشل AJAX لا يعيد POST تلقائيًا');
    chk(str_contains($js, 'ensureAttemptToken(form)')
        && str_contains($js, 'showAjaxErrors(form, data.errors')
        && str_contains($js, 'aria-invalid'),
        'نماذج AJAX تولّد رمز محاولة وتربط أخطاء الخادم بالحقول');
    $permitController = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/PermitController.php');
    chk(str_contains($permitController, "jsonFormFailure(['entry_date'")
        && str_contains($permitController, "jsonFormFailure(['bill_number'")
        && substr_count($permitController, 'claimForm(') >= 10,
        'إدارة الإذن تعيد أخطاء حقول وتستهلك الرمز قبل الكتابة');
} finally {
    $_SESSION = $session; $_GET = $get; $_POST = $post; $_SERVER = $server;
}

echo "\n────────────────────────────────────\n";
echo $fail ? "❌ فشل $fail من " . ($pass + $fail) . "\n" : "✅ كل الفحوص نجحت ($pass)\n";
exit($fail ? 1 : 0);
