<?php
namespace App\Core;

/**
 * المتحكّم الأساسي: عرض القوالب داخل الـ layout.
 */
class Controller
{
    /**
     * عرض قالب. $layout=true يلفّه بالتخطيط العام (الشريط الجانبي...).
     */
    public function render(string $view, array $data = [], bool $layout = true): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = App::basePath('app/Views/' . $view . '.php');
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo "القالب غير موجود: " . e($view);
            return;
        }

        if (!$layout) {
            require $viewFile;
            return;
        }

        // التقاط محتوى القالب داخل متغيّر $content ثم تمريره للـ layout
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        require App::basePath('app/Views/layout.php');
    }

    /** يرندر قالبًا (بدون layout) ويُرجعه كنص — لردود AJAX الجزئية. */
    protected function renderToString(string $view, array $data = []): string
    {
        ob_start();
        $this->render($view, $data, false);
        return ob_get_clean();
    }

    /** هل الطلب عبر AJAX (fetch/XMLHttpRequest)؟ */
    protected function wantsJson(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    protected function requireAuth(): void
    {
        if (!Auth::check()) {
            redirect('login');
        }
    }

    /** حارس إضافي للعمليات الحساسة؛ السياسة المركزية في Access تظل الحارس الأول. */
    protected function requireRole(string|array $roles): void
    {
        $this->requireAuth();
        $roles = (array) $roles;
        if (!in_array(Auth::role(), $roles, true)) {
            // نفس صفحة 403 اللي بيعرضها Access::deny — مسار رفض واحد بشكل واحد.
            // `flash` مضافة جنب `error` لأن ajaxSubmit في app.js بتقرا data.flash
            // وكانت بتوصلها `error` بس فتعرض «تعذّر الحفظ» العامة بدل السبب.
            $msg = 'ليست لديك صلاحية لتنفيذ هذا الإجراء.';
            http_response_code(403);
            if ($this->wantsJson()) {
                $this->json(['ok' => false, 'error' => $msg, 'flash' => $msg, 'errors' => ['_form' => $msg]]);
                exit;
            }
            $this->render('errors/403', ['title' => 'غير مسموح']);
            exit;
        }
    }

    protected function requireCsrf(): void
    {
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            die('انتهت صلاحية الجلسة — أعد تحميل الصفحة وحاول مجدداً.');
        }
    }

    protected function json($data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * ══════════════════════════════════════════════════════════════════════
     * عقد المسار بعد الحفظ/الحذف — المصدر الوحيد (المرحلة ٢).
     * ══════════════════════════════════════════════════════════════════════
     *
     * قبل كده كان فيه **14 أسلوب رجوع** في 311 موضع: `safe_back` في مكان،
     * و`bulkBack()`/`redirectBack()`/`inboxBack()`/`safeReturn()` مكتوبين
     * بالإيد في 4 أماكن، و3 حقول رجوع مختلفة (`_back`/`back`/`return`)،
     * و**18 إجراء بيخلص من غير أي رسالة** فالمستخدم مش عارف حصل إيه.
     *
     * القاعدة دلوقتي:
     *   • **الرسالة إجبارية** — مفيش طريقة تنادي `done()` من غير ما تقول
     *     للمستخدم إيه اللي حصل.
     *   • **الرجوع للمكان اللي جه منه** لو الفورم بعت `_back` (بيتنضّف
     *     بـ`safe_back` فمستحيل يوديه لدومين برّه)، وإلا `$fallback`.
     *   • **`$anchor`** بيرجّعه لنفس البند اللي اشتغل عليه جوّه الصفحة.
     *
     * @param string $msg      رسالة للمستخدم (إجبارية — مفيش إجراء صامت)
     * @param string $fallback الوجهة لو الفورم ما بعتش `_back`
     * @param string $anchor   مرساة اختيارية (بلا `#`) للرجوع لنفس البند
     */
    protected function done(string $msg, string $fallback, string $anchor = '', string $type = 'success'): never
    {
        flash($type, $msg);
        $target = safe_back($fallback, $_POST['_back'] ?? null);
        if ($anchor !== '' && !str_contains($target, '#')) { $target .= '#' . $anchor; }
        redirect($target);
    }

    /**
     * فشل مش متعلّق بحقل معيّن (السجل مش موجود، اليوم متقفل، مفيش صلاحية…).
     *
     * بيحلّ محل نمط `flash('error', …); redirect('permits');` المتكرر —
     * وأخطر منه نمط `redirect('permits');` **من غير أي رسالة** اللي كان
     * موجود في 17 حارس في PermitController لوحده، فالمستخدم كان بيلاقي
     * نفسه على القايمة ومش عارف ليه.
     */
    protected function fail(string $msg, string $fallback, string $anchor = ''): never
    {
        if ($this->wantsJson()) {
            http_response_code(422);
            $this->json(['ok' => false, 'flash' => $msg, 'errors' => ['_form' => $msg]]);
            exit;
        }
        $this->done($msg, $fallback, $anchor, 'error');
    }

    /**
     * السجل اللي المستخدم بيفتحه مش موجود (طلب GET).
     *
     * الفرق عن `fail()`: ده لصفحة بيفتحها المستخدم بالرابط، فالصح إننا نعرض
     * **صفحة 404 بسبب واضح** بدل ما نحوّله للقايمة. `fail()` للإجراءات (POST)
     * اللي لازم ترجّعه لمكانه ومعاه رسالة.
     */
    protected function notFound(string $msg = 'السجل ده مش موجود أو اتمسح.'): never
    {
        http_response_code(404);
        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'flash' => $msg, 'errors' => ['_form' => $msg]]);
            exit;
        }
        $this->render('errors/404', ['title' => 'غير موجود', 'notFoundMsg' => $msg]);
        exit;
    }

    /**
     * حوكمة الأسماء عند الحفظ (منع البيانات الرديئة / التكرار) — **نقطة الاختناق الوحيدة**.
     *   - يوحِّد تهجئة كل اسم تلقائيًا في `$_POST` لو طابق مفتاحه اسمًا مخزّنًا (همزة/ألف/ة/مسافات).
     *   - يُرجع رسالة خطأ لأول اسم متعارض غير مؤكَّد (stop-and-choose)، وإلا null.
     *
     * التأكيد يجي من العميل عبر الحقل المخفي `__newname_<field>=1` («ده اسم جديد فعلاً»)،
     * واللي بيحطّه سكربت `smart-name` في `app.js` — فالخادم والعميل بيستخدموا نفس العقد.
     *
     * ⚠️ مكانها الكلاس الأساسي عن قصد: أي شاشة إدخال جديدة بتقدر تحرس أسماءها بسطر
     *    واحد، وتعريفها ما يتفرّعش لنسختين تختلفوا مع الوقت. **لو ضفت دالة هنا، شغّل
     *    `verify_ux` قبل الرفع** — تضييق مستوى الوصول في كنترولر وارث = خطأ قاتل
     *    و`php -l` ما بيمسكوش (المصيدة الموثَّقة في `notFound()`).
     *
     * **القوائم المقفولة** (`Lookup::STRICT`) بتتفحص هنا كمان: القيمة لازم تكون من
     * القائمة، و`__newname_` **ما بيتخطّاهاش** — ده بالظبط الفرق بين «قائمة مقفولة»
     * و«حارس». الاستثناء الوحيد القيمة القديمة المتسجّلة على السجل نفسه (`$existing`).
     *
     * @param array<string,string> $map      حقل الإدخال ⇒ نوع الاقتراح (owner/driver/company/port…)
     * @param array<string,mixed>  $existing صف السجل قبل التعديل — عشان القيمة القديمة تعدّي
     */
    protected function nameGuardError(array $map, array $existing = []): ?string
    {
        foreach ($map as $field => $type) {
            $raw = trim((string) ($_POST[$field] ?? ''));
            if ($raw === '') { continue; }

            if (\App\Models\Lookup::isStrict($type)) {
                if ($err = $this->strictListError($field, $type, $raw, $existing)) { return $err; }
                continue;   // القائمة المقفولة مالهاش «قريب من» — إما من القائمة أو لأ
            }

            $g = \App\Models\NameSuggestion::resolve($type, $raw);
            if ($g['conflict'] !== null && empty($_POST['__newname_' . $field])) {
                $why = match ($g['reason'] ?? null) {
                    'concept' => 'غالبًا هي نفسها «' . $g['conflict'] . '» المخزَّنة — نفس الاسم بصيغة تانية.',
                    'subset'  => 'ناقصة أو زايدة كلمة عن «' . $g['conflict'] . '» المخزَّنة.',
                    default   => 'قريبة جدًا من «' . $g['conflict'] . '» المخزَّنة.',
                };
                return 'القيمة «' . $raw . '» ' . $why
                     . ' اختر الموجودة لتفادي التكرار، أو أكِّد أنها قيمة جديدة فعلاً.';
            }
            $_POST[$field] = $g['value']; // توحيد التهجئة المعتمدة (أو إبقاؤها كما هي)
        }
        return null;
    }

    /**
     * فحص القائمة المقفولة لحقل واحد. بيوحّد التهجئة في `$_POST` لو القيمة موجودة
     * بشكل مختلف، وبيرجّع رسالة لو مش موجودة خالص.
     *
     * ⚠️ **الفورم مش مصدر ثقة**: الشاشة بتعرض `<select>` لكن أي POST مباشر يقدر
     *    يبعت أي نص، فالفحص هنا هو الحد الحقيقي مش شكل الخانة.
     */
    private function strictListError(string $field, string $type, string $raw, array $existing): ?string
    {
        $list = \App\Models\Lookup::ofType($type);
        $key  = \App\Models\Maintenance::normkey($raw);

        foreach ($list as $v) {
            if (\App\Models\Maintenance::normkey($v) === $key) {
                $_POST[$field] = $v;   // التهجئة المعتمدة من القائمة
                return null;
            }
        }
        // قيمة قديمة متسجّلة على السجل نفسه ⇒ تعدّي (وإلا تعديل أي حقل تاني
        // في سجل قديم كان هيترفض أو يغيّر الميناء بالسكوت)
        $old = trim((string) ($existing[$field] ?? ''));
        if ($old !== '' && \App\Models\Maintenance::normkey($old) === $key) {
            $_POST[$field] = $old;
            return null;
        }

        $label = \App\Models\Lookup::TYPES[$type] ?? $type;
        return 'القيمة «' . $raw . '» مش من قائمة ' . $label . ' المعتمدة. '
             . 'اختر من القائمة، أو أضفها أول من الإعدادات ← القوائم المنسدلة.';
    }

    /** PRG موحد لفشل validation مع عزل القيم والأخطاء لكل تبويب. */
    protected function formFailure(string $formId, string $path, array $old, array $errors, string $fallbackBack): never
    {
        $token = FormState::store($formId, $old, $errors, $fallbackBack);
        redirect(FormState::redirectPath($path, $token));
    }

    /** منع تنفيذ طلب كتابة يحمل نفس رمز النموذج أكثر من مرة. */
    protected function claimForm(string $fallback): void
    {
        if (FormState::claim($_POST['_form_token'] ?? null)) { return; }
        if ($this->wantsJson()) {
            $message = 'هذا الطلب نُفّذ أو انتهت صلاحيته. راجع السجل قبل إعادة المحاولة.';
            $this->json(['ok' => false, 'flash' => $message, 'errors' => ['_form' => $message]]);
            exit;
        }
        flash('error', 'هذا الطلب نُفّذ أو انتهت صلاحيته. راجع السجل قبل إعادة المحاولة.');
        redirect(safe_back($fallback, $_POST['_back'] ?? null));
    }

    /** رد validation موحد لنماذج AJAX مع أخطاء مرتبطة بالحقول. */
    protected function jsonFormFailure(array $errors, string $fallback = 'تعذّر الحفظ'): never
    {
        $message = (string)($errors['_form'] ?? reset($errors) ?: $fallback);
        $this->json(['ok' => false, 'flash' => $message, 'errors' => $errors]);
        exit;
    }
}
