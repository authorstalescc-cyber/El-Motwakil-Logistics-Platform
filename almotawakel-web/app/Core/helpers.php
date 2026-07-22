<?php
use App\Core\App;
use App\Core\Auth;

/** تهريب HTML */
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * تحويل أي قيمة إلى JSON صالح للوضع **داخل وسم <script>**.
 * المصدر الوحيد لهذا التحويل — استخدمه في كل `<?= ... ?>` جوّه سكربت.
 *
 * ⚠️ ليه موجودة: `json_encode(..., JSON_UNESCAPED_SLASHES)` بتسيب الشرطة `/`
 *    زي ما هي، فنص زي «</script>» مخزَّن في أي خانة (اسم عميل، وجهة بوليصة…)
 *    بيقفل الوسم ويشغّل كود المهاجم في متصفح كل من يفتح الصفحة — بما فيهم
 *    المالك (تصعيد صلاحيات من أقل مستخدم). `JSON_HEX_TAG` بتحوّل `<` و`>`
 *    إلى `<`/`>` فيستحيل الخروج من الوسم مهما كان المحتوى، ومعاها
 *    HEX_AMP/APOS/QUOT عشان تفضل آمنة كمان لو اتحطّت جوّه سمة HTML.
 *
 * بنسيب JSON_UNESCAPED_SLASHES شغّالة عن قصد: الروابط تفضل مقروءة، وبقاء
 * `/` بلا تهريب مش خطر بعد ما بقى `<` نفسه مستحيل يعدّي.
 */
function js_json($value): string
{
    return json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

/**
 * تحويل الأرقام العربية (٠١٢…) والفارسية (۰۱۲…) إلى إنجليزية (012…).
 * المصدر الوحيد لهذا التحويل — استخدمه في أي مكان يحتاج توحيد الأرقام.
 */
function ar2en_digits($s): string
{
    return strtr((string)($s ?? ''), [
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    ]);
}

/**
 * توحيد كل الأرقام الواردة في الطلب لتكون إنجليزية دائمًا — حتى لو كتبها
 * المستخدم بالعربية. يمشي على $_POST/$_GET/$_REQUEST بشكل تكراري ويحوّل
 * الأرقام في القيم النصية فقط. يستثني حقول كلمات السر/المفاتيح السرّية
 * (لأن قيمتها الحرفية مهمّة ولا يجوز تغييرها).
 */
function ar2en_normalize_request(): void
{
    // أسماء الحقول التي لا تُلمَس قيمتها إطلاقًا (سرّية/حرفية)
    $skip = '/(pass|pwd|token|csrf|secret|api_?key|ai_key)/i';
    $walk = function (array &$arr) use (&$walk, $skip): void {
        foreach ($arr as $k => &$v) {
            if (is_string($k) && preg_match($skip, $k)) { continue; }
            if (is_array($v))        { $walk($v); }
            elseif (is_string($v))   { $v = ar2en_digits($v); }
        }
        unset($v);
    };
    if (!empty($_POST)    && is_array($_POST))    { $walk($_POST); }
    if (!empty($_GET)     && is_array($_GET))     { $walk($_GET); }
    if (!empty($_REQUEST) && is_array($_REQUEST)) { $walk($_REQUEST); }
}

/** رابط داخل التطبيق */
function url(string $path = ''): string
{
    return App::url($path);
}

/** تنسيق مبلغ مالي */
function money($v, bool $withCurrency = true): string
{
    $v = (float) $v;
    $s = number_format($v, 2, '.', ',');
    return $withCurrency ? $s . ' ' . App::setting('currency', 'ج.م') : $s;
}

/** تنسيق مبلغ بدون كسور */
function money0($v): string
{
    return number_format((float)$v, 0, '.', ',') . ' ' . App::setting('currency', 'ج.م');
}

/** تنسيق عدد صحيح */
function intf($v): string
{
    return number_format((float)$v, 0, '.', ',');
}

/**
 * توحيد نص «تصنيف الحاويات» — المصدر الوحيد للحقيقة لهذا التحويل.
 *
 * النظام القديم كان يدمج العدد والتصنيف في نصّ واحد («1*40»، «2*20 ث»)،
 * بينما النظام الجديد يفصل «عدد الحاويات» (رقم) عن «تصنيف الحاويات» (وصف المقاس).
 * هذه الدالة تُزيل العدد المدموج من نص التصنيف وتوحّده على الصيغة القانونية:
 *   1*40      → 40 قدم      |  2*20  → 20 قدم   |  1*45 → 45 قدم
 *   1*40 ث    → 40 مبرّد    (ث = ثلاجة/مبرّد)
 *   1*20 ot   → أوبن توب    |  1*20 تنك → تانك  |  1*20 فلا → فلات راك
 * القيم النظيفة أصلاً («40 قدم»، «مبرّد») والقيم الوصفية غير الحاوية
 * («دبابة»، «19 طرد») تُعاد كما هي دون أي تغيير — الدالة لا تتدخّل إلا
 * عندما يوجد فاصل عدّ صريح (* أو x أو ×).
 *
 * ⚠️ لا تستخدمها على مصدر إعادة الاستيراد الخام (legacy_bawalis) لأن عدّاد
 * الحاويات يُشتق من بادئة «العدد*» — احتفظ بالخام هناك واستخدمها عند العرض فقط.
 */
function container_norm($raw): ?string
{
    if ($raw === null) { return null; }
    $s = trim((string) $raw);
    if ($s === '') { return $s; }
    // تحويل الأرقام العربية إلى إنجليزية
    $s = ar2en_digits($s);
    // لا نتدخّل إلا عند وجود فاصل عدّ صريح: «عدد <*|x|×> باقي»
    if (!preg_match('#^\s*\d+\s*[*x×]\s*(.+)$#u', $s, $m)) {
        return trim((string) $raw); // نظيف أصلاً أو وصف غير حاوية — يُعاد كما هو
    }
    $rest = trim($m[1]);            // مثال: «40» أو «40 ث» أو «20 ot» أو «20 تنك»
    if (!preg_match('#^(\d+)\s*(.*)$#u', $rest, $mm)) {
        return $rest;               // لا يوجد مقاس رقمي — نُعيد الباقي كما هو
    }
    $size   = $mm[1];
    $suffix = trim($mm[2]);
    switch (true) {
        case $suffix === '':                                     return $size . ' قدم';
        case $suffix === 'ث':                                    return $size . ' مبرّد';
        case mb_strtolower($suffix) === 'ot' || $suffix === 'او': return 'أوبن توب';
        case $suffix === 'تنك' || $suffix === 'تانك':            return 'تانك';
        case $suffix === 'فلا' || mb_stripos($suffix, 'فلات') !== false: return 'فلات راك';
        default:                                                 return $size . ' ' . $suffix; // لاحقة غير معروفة: نُزيل العدد ونُبقي الوصف
    }
}

/** تنسيق «تصنيف الحاويات × عددها» — يرجع «النوع × العدد» أو «العدد حاوية» لو النوع فارغ */
function containerLabel($type, $count): string
{
    $count = (int) $count;
    // دفاعي: لو وصل تصنيف قديم لسه فيه العدد مدموج نوحّده قبل العرض
    $type  = trim((string) (container_norm($type) ?? ''));
    if ($type !== '') {
        return $type . ' × ' . number_format($count, 0);
    }
    return number_format($count, 0) . ' ' . ($count === 1 ? 'حاوية' : 'حاوية');
}

/** تنسيق نسبة مئوية */
function pct($v): string
{
    return number_format(((float)$v) * 100, 1) . '%';
}

/**
 * تاريخ العمل الحالي (Y-m-d) — الافتراضي لكل مدخلات المستخدم الجديدة.
 * استخدمه بدل date('Y-m-d') في أي قيمة تاريخ افتراضية داخل نماذج الإدخال.
 */
function work_date(): string
{
    return Auth::workDate();
}

/** تنسيق تاريخ للعرض */
function dfmt($v): string
{
    if (!$v) return '';
    try {
        $d = new DateTime($v);
        return $d->format('d/m/Y');
    } catch (Exception $e) {
        return (string)$v;
    }
}

/* ملحوظة: دالة `old()` القديمة اتشالت في المرحلة ٠ — كانت بتقرا `$_SESSION['_old']`
   اللي **مفيش حاجة في المشروع كله بتكتب فيه**، ومفيش view واحد كان بينادّيها.
   الاحتفاظ بالمدخلات عند الخطأ بيتم عبر `App\Core\FormState` وهو المصدر الوحيد. */

/** حقل CSRF مخفي */
function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Auth::csrf()) . '">';
}

/** حقول حالة النموذج المعزولة وسياق الرجوع الداخلي. */
function formStateFields(string $token, string $back): string
{
    return '<input type="hidden" name="_form_token" value="' . e($token) . '">' .
           '<input type="hidden" name="_back" value="' . e(safe_back('', $back)) . '">';
}

/** ملخص أخطاء موحّد، قابل للتركيز وقارئ الشاشة. */
function formErrorSummary(array $errors): string
{
    if (!$errors) { return ''; }
    $messages = [];
    foreach ($errors as $message) {
        if (is_string($message) && trim($message) !== '') { $messages[] = trim($message); }
    }
    $messages = array_values(array_unique($messages));
    if (!$messages) { return ''; }
    $items = implode('', array_map(fn(string $m): string => '<li>' . e($m) . '</li>', $messages));
    return '<div class="form-error-summary" role="alert" tabindex="-1" data-form-error-summary>' .
           '<strong>راجع البيانات التالية:</strong><ul>' . $items . '</ul></div>';
}

function fieldErrorAttrs(string $name, array $errors): string
{
    return empty($errors[$name]) ? '' : ' aria-invalid="true" aria-describedby="err-' . e($name) . '"';
}

function fieldError(string $name, array $errors): string
{
    return empty($errors[$name]) ? '' : '<small class="field-error" id="err-' . e($name) . '" role="alert">' . e($errors[$name]) . '</small>';
}

/** المسار الحالي داخل التطبيق، مع query وfragment إن وُجدا. */
function current_app_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $base = App::baseUrl();
    if ($base !== '' && str_starts_with($uri, $base . '/')) { $uri = substr($uri, strlen($base)); }
    return safe_back('', $uri);
}

/**
 * يحوّل `HTTP_REFERER` لمسار داخلي نسبي **لو كان من نفس الموقع فقط**.
 *
 * ⚠️ الـReferer جاي من المتصفح فهو **مش مصدر ثقة**: لو رجّعناه زي ما هو
 * بيبقى ثغرة تحويل مفتوح (المهاجم يبعت لينك لصفحة 404 عندنا فيها زر رجوع
 * بيودّي لموقعه). عشان كده بنقارن الـhost بـhost الطلب الحالي، وبنرجّع ''
 * لأي حاجة غريبة، وبعدين `safe_back()` بتنضّف الناتج تاني.
 *
 * بيُستخدم في صفحتي 404/403 بس — أي فورم بيبعت `_back` بنفسه وهو الأدق.
 */
function referer_path(): string
{
    $ref = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($ref === '' || preg_match('/[\x00-\x1F\x7F]/', $ref)) { return ''; }
    $parts = @parse_url($ref);
    if ($parts === false) { return ''; }
    // ⚠️ أي scheme غير http/https مرفوض. من غير السطر ده `javascript:alert(1)`
    // بيعدّي، لأن parse_url بتفكّه لـscheme + path بلا host فيتخطّى فحص الموقع
    // تحت — متحقَّق فعليًّا وقت الاختبار.
    if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return '';
    }
    if (!empty($parts['host'])) {
        $here = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $there = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        // نقبل بالبورت وبدونه: HTTP_HOST بيشيله أحيانًا والريفرر لأ
        if ($here === '' || ($there !== $here && strtolower($parts['host']) !== explode(':', $here)[0])) {
            return '';
        }
    }
    $out = (string) ($parts['path'] ?? '');
    if (!empty($parts['query'])) { $out .= '?' . $parts['query']; }
    return $out;
}

/**
 * يقبل مسارًا داخليًا نسبيًا فقط، ويرفض scheme/host و`//` ومحارف التحكم.
 */
function safe_back(string $fallback, $candidate = null): string
{
    $fallback = ltrim(trim($fallback), '/');
    $raw = trim((string) ($candidate ?? ''));
    if ($raw === '' || preg_match('/[\x00-\x1F\x7F]/', $raw) || str_starts_with($raw, '//') ||
        preg_match('/^[a-z][a-z0-9+.-]*:/i', $raw)) { return $fallback; }
    // نفصل يدويًا بدل parse_url كي لا تتشوّه query عربية خام في بعض إصدارات PHP.
    $fragment = '';
    if (($at = strpos($raw, '#')) !== false) { $fragment = substr($raw, $at + 1); $raw = substr($raw, 0, $at); }
    $query = '';
    if (($at = strpos($raw, '?')) !== false) { $query = substr($raw, $at + 1); $raw = substr($raw, 0, $at); }
    $path = $raw;
    $base = App::baseUrl();
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) { $path = substr($path, strlen($base)); }
    if ($path === '' || str_starts_with($path, '//') || str_contains($path, '\\')) { return $fallback; }
    if (!str_starts_with($path, '/') && (str_starts_with($path, '.') || str_contains($path, ':'))) { return $fallback; }
    $out = ltrim($path, '/');
    if ($query !== '') { $out .= '?' . $query; }
    if ($fragment !== '') { $out .= '#' . $fragment; }
    return $out;
}

/**
 * رسالة فلاش — طابور (المرحلة ٠).
 *
 * قبل كده كان `$_SESSION['_flash'][$type]` نصًّا واحدًا، يعني تاني `flash('success', …)`
 * في نفس الطلب بيمسح الأولى **بالسكوت**. دلوقتي بقت مصفوفة فالرسايل بتتراكم.
 *
 * القراءة `flash('success')` بترجّع **أول رسالة** وبتفضّي نوعها بالكامل — ده بالظبط
 * سلوك النظام القديم (رسالة واحدة كانت بتوصل)، فمفيش أي صفحة اتغيّر سلوكها،
 * ومفيش رسالة بتتسرّب للصفحة اللي بعدها. الطابور كله يتقرا بـ`flash_all()`
 * اللي هتستخدمه شاشة التوستات في المرحلة ١.
 */
function flash(string $type, ?string $msg = null)
{
    if ($msg === null) {
        $bucket = $_SESSION['_flash'][$type] ?? null;
        unset($_SESSION['_flash'][$type]);
        if ($bucket === null) { return null; }
        // توافق رجعي: جلسة قديمة ممكن يكون فيها نص مخزَّن قبل الترقية
        return is_array($bucket) ? ($bucket[0] ?? null) : $bucket;
    }
    $_SESSION['_flash'][$type][] = $msg;
    return null;
}

/**
 * يسحب **كل** رسائل الفلاش المتراكمة ويفضّي الطابور.
 * بترجّع `[['type' => 'success', 'msg' => '…'], …]` بترتيب النوع ثم الإضافة.
 */
function flash_all(): array
{
    $queue = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    $out = [];
    foreach ($queue as $type => $bucket) {
        foreach ((array) $bucket as $msg) {
            if (is_string($msg) && $msg !== '') {
                $out[] = ['type' => (string) $type, 'msg' => $msg];
            }
        }
    }
    return $out;
}

/** إعادة توجيه */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** كلاس CSS للأرقام الموجبة/السالبة */
function signClass($v): string
{
    $v = (float)$v;
    if ($v < 0) return 'neg';
    if ($v > 0) return 'pos';
    return '';
}

/**
 * تفقيط: تحويل مبلغ رقمي إلى حروف عربية (للفواتير).
 * يدعم حتى المليارات + القروش. مثال: 1234.50 → «ألف ومئتان وأربعة وثلاثون جنيهاً وخمسون قرشاً لا غير».
 */
function tafqit($amount): string
{
    $amount = round((float)$amount, 2);
    $pounds  = (int) floor($amount);
    $piasters = (int) round(($amount - $pounds) * 100);

    $words = $pounds > 0 ? _tafqitInt($pounds) . ' جنيهاً' : 'صفر جنيه';
    if ($piasters > 0) {
        $words .= ' و' . _tafqitInt($piasters) . ' قرشاً';
    }
    return 'فقط ' . $words . ' لا غير';
}

/** يحوّل عدداً صحيحاً موجباً إلى حروف عربية */
function _tafqitInt(int $n): string
{
    if ($n === 0) return 'صفر';

    $ones = ['','واحد','اثنان','ثلاثة','أربعة','خمسة','ستة','سبعة','ثمانية','تسعة',
             'عشرة','أحد عشر','اثنا عشر','ثلاثة عشر','أربعة عشر','خمسة عشر','ستة عشر',
             'سبعة عشر','ثمانية عشر','تسعة عشر'];
    $tens = ['','','عشرون','ثلاثون','أربعون','خمسون','ستون','سبعون','ثمانون','تسعون'];
    $hundreds = ['','مئة','مئتان','ثلاثمئة','أربعمئة','خمسمئة','ستمئة','سبعمئة','ثمانمئة','تسعمئة'];

    // مجموعة من ثلاث خانات → حروف
    $group = function(int $g) use ($ones, $tens, $hundreds): string {
        $parts = [];
        $h = intdiv($g, 100);
        $r = $g % 100;
        if ($h > 0) { $parts[] = $hundreds[$h]; }
        if ($r > 0) {
            if ($r < 20) { $parts[] = $ones[$r]; }
            else {
                $u = $r % 10; $t = intdiv($r, 10);
                $parts[] = ($u > 0) ? $ones[$u] . ' و' . $tens[$t] : $tens[$t];
            }
        }
        return implode(' و', $parts);
    };

    // أسماء المراتب: [مفرد، مثنى، جمع 3..10]
    $scales = [
        1000000000 => ['مليار','ملياران','مليارات'],
        1000000    => ['مليون','مليونان','ملايين'],
        1000       => ['ألف','ألفان','آلاف'],
    ];

    $parts = [];
    foreach ($scales as $value => $names) {
        if ($n >= $value) {
            $count = intdiv($n, $value);
            $n -= $count * $value;
            if ($count === 1)      { $parts[] = $names[0]; }
            elseif ($count === 2)  { $parts[] = $names[1]; }
            elseif ($count <= 10)  { $parts[] = $group($count) . ' ' . $names[2]; }
            else                   { $parts[] = $group($count) . ' ' . $names[0]; }
        }
    }
    if ($n > 0) { $parts[] = $group($n); }

    return implode(' و', $parts);
}

/**
 * أيقونة SVG متجهيّة (Lucide-style) — تحل محل الإيموجي في الواجهة.
 * كلها stroke بنفس السماكة واللون currentColor لتناسق كامل ودعم الوضعين.
 * الاستخدام: icon('truck') أو icon('truck', 18, 'extra-class')
 */
function icon(string $name, int $size = 20, string $class = ''): string
{
    static $paths = [
        'dashboard'   => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/>',
        'folder'      => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'truck'       => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        'banknote'    => '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
        'clipboard'   => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
        'cpu'         => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2M15 20v2M2 15h2M2 9h2M20 15h2M20 9h2M9 2v2M9 20v2"/>',
        'calculator'  => '<rect width="16" height="20" x="4" y="2" rx="2"/><line x1="8" x2="16" y1="6" y2="6"/><line x1="16" x2="16" y1="14" y2="18"/><path d="M16 10h.01M12 10h.01M8 10h.01M12 14h.01M8 14h.01M12 18h.01M8 18h.01"/>',
        'receipt'     => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'file-text'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8M16 17H8M10 9H8"/>',
        'layers'      => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'credit-card' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
        'briefcase'   => '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
        'trending-down'=> '<polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/>',
        'coins'       => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
        'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'bar-chart'   => '<line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/>',
        'settings'    => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'search'      => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'log-out'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'package'     => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73Z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/>',
        'clock'       => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'calendar'    => '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>',
        'plus'        => '<path d="M5 12h14M12 5v14"/>',
        'zap'         => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'landmark'    => '<line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
        'hourglass'   => '<path d="M5 22h14M5 2h14"/><path d="M17 22v-4.17a2 2 0 0 0-.59-1.41L12 12l-4.41 4.42A2 2 0 0 0 7 17.83V22"/><path d="M7 2v4.17a2 2 0 0 0 .59 1.41L12 12l4.41-4.42A2 2 0 0 0 17 6.17V2"/>',
        'bell'        => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
        'info'        => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'menu'        => '<line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'chevron'     => '<path d="m15 18-6-6 6-6"/>',
        'inbox'       => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'external'    => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'check'       => '<path d="M20 6 9 17l-5-5"/>',
        'alert'       => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/>',
        'shuffle'     => '<path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/>',
        'building'    => '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>',
        'user'        => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'map-pin'     => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'anchor'      => '<circle cx="12" cy="5" r="3"/><line x1="12" x2="12" y1="22" y2="8"/><path d="M5 12H2a10 10 0 0 0 20 0h-3"/>',
        'wallet'      => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2V5"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'sparkles'    => '<path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.14-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.14a.5.5 0 0 1 .96 0L14.06 8.5A2 2 0 0 0 15.5 9.94l6.14 1.58a.5.5 0 0 1 0 .96L15.5 14.06a2 2 0 0 0-1.44 1.44l-1.58 6.14a.5.5 0 0 1-.96 0z"/><path d="M20 3v4M22 5h-4M4 17v2M5 18H3"/>',
        'send'        => '<path d="M14.54 21.69a.5.5 0 0 0 .94-.02l6.5-19a.5.5 0 0 0-.64-.64l-19 6.5a.5.5 0 0 0-.02.94l7.93 3.18a2 2 0 0 1 1.11 1.11z"/><path d="m21.85 2.15-10.94 10.94"/>',
        'edit'        => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
        'trash'       => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
        'x'           => '<path d="M18 6 6 18M6 6l12 12"/>',
        'check-circle'=> '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
        'undo'        => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
    ];
    $body = $paths[$name] ?? '<circle cx="12" cy="12" r="9"/>';
    $cls  = 'ico' . ($class ? ' ' . $class : '');
    return '<svg class="' . $cls . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" '
         . 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
         . 'stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

/**
 * قائمة اختيار كارت الفيزا (رصيد المكتب) — تُعرض جنب خانة «مدفوع بالفيزا».
 * تظهر دائمًا: لو مفيش كروت تعرض رابط «أضف كارت» بدل الاختفاء.
 * تُخزَّن الكروت في static فلا تتكرّر الاستعلامات داخل نفس الطلب (مهم لجداول البنود).
 */
/**
 * @param string $form اسم فورم خارجي يتربط بيه الـselect (سمة form=""). لازمة في
 *        صندوق مراجعة المناديب: حقول التعديل جوّه div مربوطة بفورم برّه، ومن
 *        غير السمة دي الاختيار ما بيتبعتش أصلاً فالكارت بيضيع بصمت.
 */
function visa_card_select($selected = 0, string $name = 'visa_card_id', string $style = '', string $id = '', string $form = ''): string
{
    static $cards = null;
    if ($cards === null) { $cards = \App\Models\VisaCard::all(true); }
    $sel = (int) $selected;
    if (!$cards) {
        return '<span class="visa-card-empty" style="font-size:.8rem;color:var(--grey)">— لا كروت مسجّلة. '
             . '<a href="' . url('office') . '">➕ أضِف كارت فيزا</a></span>';
    }
    $st = $style !== '' ? ' style="' . e($style) . '"' : '';
    $idAttr = $id !== '' ? ' id="' . e($id) . '"' : '';
    $formAttr = $form !== '' ? ' form="' . e($form) . '"' : '';
    $h  = '<select name="' . e($name) . '"' . $idAttr . $formAttr . ' title="كارت الفيزا (رصيد المكتب)"' . $st . '>'
        . '<option value="">— اختر الكارت —</option>';
    foreach ($cards as $vc) {
        $lbl = e($vc['name']) . ($vc['owner'] ? ' — ' . e($vc['owner']) : '');
        $h  .= '<option value="' . (int) $vc['id'] . '"' . ($sel === (int) $vc['id'] ? ' selected' : '') . '>' . $lbl . '</option>';
    }
    return $h . '</select>';
}

/**
 * الرابط العام للنظام (اللي يفتح من برّه الجهاز) — لبناء روابط تتبع الشحنات.
 *
 * النظام شغّال على localhost والنفق (cloudflared) بيدي رابط بيتغيّر كل تشغيل،
 * فالترتيب هنا مهم:
 *   1) لو المستخدم نفسه فاتح النظام من رابط عام (مش لوكال) → استخدمه فورًا. أدقّ مصدر.
 *   2) وإلا → الإعداد `public_base_url` اللي بيكتبه سكربت «تشغيل عام على النت»
 *      عند بدء النفق (عبر bin/set_public_url.php).
 *   3) وإلا → '' (والواجهة تنبّه إن مفيش رابط عام).
 *
 * ⚠️ ما يرجعش localhost أبدًا: رابط localhost في الواتساب مش هيفتح عند السائق.
 */
function public_base_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && !is_local_host($host)) {
        // cloudflared/ngrok بيبعتوا X-Forwarded-Proto: https
        $fwd    = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https  = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $scheme = $fwd !== '' ? $fwd : ($https ? 'https' : 'http');
        return rtrim($scheme . '://' . $host . App::baseUrl(), '/');
    }
    return rtrim((string) App::setting('public_base_url', ''), '/');
}

/** هل الـ host ده محلّي (مايفتحش من برّه الجهاز)؟ */
function is_local_host(string $host): bool
{
    $h = strtolower(preg_replace('/:\d+$/', '', trim($host, '[]')));
    return $h === 'localhost' || $h === '::1' || $h === '0.0.0.0'
        || str_ends_with($h, '.local')
        || (bool) preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $h);
}

/**
 * رقم موبايل مصري → صيغة واتساب الدولية بدون + (201012345678).
 * يُرجع '' لو الرقم مش صالح — عشان الواجهة تخفي زرار الواتساب بدل ما تبعت رابط مكسور.
 */
function wa_phone($phone): string
{
    $d = preg_replace('/\D+/', '', ar2en_digits((string) ($phone ?? '')));
    if ($d === '') { return ''; }
    if (str_starts_with($d, '00')) { $d = substr($d, 2); }        // 0020… → 20…
    if (str_starts_with($d, '01') && strlen($d) === 11) {          // 01012345678 → 201012345678
        return '2' . $d;
    }
    if (str_starts_with($d, '1') && strlen($d) === 10) {           // 1012345678 (بلا صفر)
        return '20' . $d;
    }
    if (str_starts_with($d, '20') && strlen($d) === 12) { return $d; }  // دولي جاهز
    return strlen($d) >= 10 ? $d : '';                             // رقم أجنبي — سيبه زي ما هو
}

/** الأحرف الأولى لاسم (للأڤاتار): أول حرف من أول كلمتين */
function initials(string $name): string
{
    $name = trim($name);
    if ($name === '') return '؟';
    $words = preg_split('/\s+/u', $name);
    // إنجليزي/لاتيني: أول حرفين كبار
    if (preg_match('/^[A-Za-z0-9]/', $name)) {
        return mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 2), 'UTF-8');
    }
    $a = mb_substr($words[0], 0, 1, 'UTF-8');
    $b = isset($words[1]) ? mb_substr($words[1], 0, 1, 'UTF-8') : '';
    return $a . $b;
}
