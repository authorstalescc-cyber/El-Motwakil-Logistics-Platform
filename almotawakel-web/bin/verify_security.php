<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * فحص أمان النظام — المرحلة الأولى.
 *
 * بيتشغّل على حاجتين:
 *   php bin/verify_security.php              → فحص الكود محليًّا (قبل الرفع)
 *   php bin/verify_security.php --remote URL → فحص الموقع الشغّال بعد الرفع
 *
 * `bin/deploy.sh` بينادي الاتنين تلقائيًّا: المحلي قبل الرفع (وبيوقف الرفع لو
 * فشل)، والبعيد بعده (وبيحذّر بصوت عالي لو حماية على السيرفر وقعت).
 *
 * ⚠️ الفحوصات دي بتحرس قرارات اتاخدت بعد ثغرات حقيقية اتلقت فعلاً — أي واحد
 *    يفشل معناه إن ثغرة رجعت، مش إن الفحص بايظ.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0; $warn = 0;
$failures = [];

function check(string $label, bool $ok, string $why = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; printf("  \033[32m✓\033[0m %s\n", $label); }
    else { $fail++; $failures[] = $label . ($why ? " — $why" : ''); printf("  \033[31m✗ %s\033[0m%s\n", $label, $why ? "\n      $why" : ''); }
}
function warn(string $label, string $why = ''): void {
    global $warn; $warn++;
    printf("  \033[33m!\033[0m %s%s\n", $label, $why ? "\n      $why" : '');
}
function head(string $t): void { printf("\n\033[1;36m%s\033[0m\n%s\n", $t, str_repeat('─', 62)); }

/**
 * الكود بلا تعليقات — عشان فحص «النص ده مش موجود» ما يفشلش بسبب تعليق
 * بيشرح النص القديم. (اتصادف فعليًا: تعليق بيوضّح رسالة «فاضلك N محاولات»
 * القديمة كان بيخلّي الفحص يقول إن الرسالة لسه موجودة.)
 */
function code_only(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

$remote = null;
$dbPrivs = in_array('--db-privs', $argv, true);
foreach ($argv as $i => $a) {
    if ($a === '--remote') { $remote = rtrim((string) ($argv[$i + 1] ?? ''), '/'); }
}

// ══════════════════════════════════════════════════════════════════════════
if ($remote === null) {
    printf("\n\033[1m فحص أمان نظام المتوكل — الكود المحلي\033[0m\n");

    // استضافة الإنتاج بتقفل دوال تشغيل العمليات، فبنعرف من الأول إيه المتاح
    // (الفحص لازم يشتغل في البيئتين بلا ما يموت بـfatal).
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $canExec  = function_exists('exec') && !in_array('exec', $disabled, true);

    // ⚠️ التحميل مرة واحدة هنا. `bootstrap.php` بتعمل `require` (مش require_once)
    //    لـhelpers، فلو حمّلنا helpers لوحدها الأول بيطلع «Cannot redeclare e()».
    //    الاتصال بالقاعدة بيتأجّل لحد أول استعلام، فالفحص يفضل شغّال حتى لو
    //    القاعدة مش متاحة (القسم 4هـ ملفوف بـtry/catch).
    $booted = false;
    try { require_once "$root/app/bootstrap.php"; $booted = true; }
    catch (\Throwable $e) { require_once "$root/app/Core/helpers.php"; }

    // ⚠️ `--db-privs` وضع **مخصّص** بيشتغل لوحده: بيفحص صلاحيات القاعدة بس.
    //    السبب إنه بيتنادى على **السيرفر** (خطوة 8 في deploy.sh)، وهناك مفيش
    //    مستودع git ولا ملف .gitignore — فباقي الفحوصات كانت هتفشل بإنذار كاذب.
    if ($dbPrivs) { goto db_privs_only; }

    // ── 1) XSS: ممنوع json_encode في أي view ──────────────────────────────
    head('1) حماية XSS — المخرجات داخل وسوم <script>');
    $bad = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app/Views")) as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        if (str_contains((string) file_get_contents($f->getPathname()), 'json_encode(')) {
            $bad[] = str_replace("$root/", '', $f->getPathname());
        }
    }
    check(
        'مفيش json_encode في أي view (لازم js_json)',
        $bad === [],
        $bad ? 'موجودة في: ' . implode(', ', array_slice($bad, 0, 5))
             . "\n      json_encode بـJSON_UNESCAPED_SLASHES بتسيب «</script>» تخرج من الوسم = XSS مخزَّن."
             : ''
    );

    $helpers = (string) file_get_contents("$root/app/Core/helpers.php");
    check('دالة js_json موجودة', str_contains($helpers, 'function js_json'));
    check('js_json بتستخدم JSON_HEX_TAG (اللي بيقفل الثغرة)', str_contains($helpers, 'JSON_HEX_TAG'));

    // إثبات عملي إن الثغرة مقفولة فعلاً (helpers اتحمّلت فوق مع bootstrap)
    $payload = '</script><script>alert(1)</script>';
    check(
        'اختبار حقيقي: نص خبيث ما بيقدرش يخرج من وسم <script>',
        stripos(js_json(['x' => $payload]), '</script') === false,
        'js_json رجّعت نص فيه </script> — الثغرة مفتوحة.'
    );
    check(
        'اختبار حقيقي: القيمة بترجع صح بعد التهريب (مفيش كسر في العرض)',
        json_decode(js_json(['x' => $payload]), true)['x'] === $payload
    );

    // ── 2) سكربتات bin ────────────────────────────────────────────────────
    head('2) سكربتات bin/ — حارس سطر الأوامر');
    $unguarded = [];
    foreach (glob("$root/bin/*.php") as $f) {
        $s = (string) file_get_contents($f);
        if (!str_contains($s, 'PHP_SAPI') && !str_contains($s, 'php_sapi_name')) {
            $unguarded[] = basename($f);
        }
    }
    check(
        'كل سكربتات bin/ محميّة من التشغيل عبر الويب',
        $unguarded === [],
        $unguarded ? 'بلا حارس: ' . implode(', ', array_slice($unguarded, 0, 8))
                   . "\n      دي فيها هجرات وDROP DATABASE بلا تسجيل دخول." : ''
    );

    // ── 3) ملفات .htaccess ────────────────────────────────────────────────
    head('3) حماية الخادم (.htaccess) — محفوظة في المستودع');
    check('.htaccess بتاع الجذر موجود في المستودع', is_file("$root/.htaccess"));
    check('.htaccess بتاع public موجود في المستودع', is_file("$root/public/.htaccess"));

    $ht = is_file("$root/.htaccess") ? (string) file_get_contents("$root/.htaccess") : '';
    check('بيمنع مجلدات الكود والبيانات (app|config|bin|…)', str_contains($ht, 'RewriteRule ^(app|config|database|storage|bin|docs)'));
    check('بيضبط Content-Security-Policy بـ«always set»', str_contains($ht, 'always set Content-Security-Policy'));
    check("CSP فيها connect-src 'self' (بتمنع تسريب البيانات لو حصل XSS)", str_contains($ht, "connect-src 'self'"));
    check("CSP فيها object-src 'none'", str_contains($ht, "object-src 'none'"));
    check('بيمنع الملفات المخفية والنسخ الجانبية', str_contains($ht, '~$|\\.bak-'));
    check(
        'مفيش «php_flag engine off» (بتطفّي النظام كله)',
        !preg_match('/^\s*php_flag\s+engine\s+off/mi', $ht),
        'السطر ده بينزل للمجلدات الفرعية وبيوقّف public/ كمان.'
    );
    $htPub = is_file("$root/public/.htaccess") ? (string) file_get_contents("$root/public/.htaccess") : '';
    check('public/.htaccess بيمنع المرفقات القديمة', str_contains($htPub, 'RewriteRule ^uploads/(permits|reps)/ - [F,L]'));
    // ملحوظة: بندوّر على التوجيه في أول السطر — عشان ذكره جوّه تعليق شارح
    // (زي التحذير الموجود في public/.htaccess) ما يعدّش غلط.
    $directiveRe = '/^\s*<Directory\b/mi';
    check(
        'مفيش توجيه <Directory> فعلي في .htaccess (بيدّي خطأ 500)',
        !preg_match($directiveRe, $htPub) && !preg_match($directiveRe, $ht)
    );

    // ── 4) تقديم المرفقات ─────────────────────────────────────────────────
    head('4) تقديم المرفقات — منع تنفيذ ملف متنكّر');
    $fc = (string) file_get_contents("$root/app/Controllers/FileController.php");
    check('في قائمة بيضاء للأنواع المسموح عرضها inline', str_contains($fc, 'INLINE_MIME'));
    check('أي نوع تاني بينزل كـattachment', str_contains($fc, "'inline' : 'attachment'") || str_contains($fc, '$safe ? \'inline\' : \'attachment\''));
    check('النوع المجهول بيتبعت كـapplication/octet-stream', str_contains($fc, 'application/octet-stream'));
    check("بيبعت CSP بـdefault-src 'none' على المرفق", str_contains($fc, "default-src 'none'"));
    check('بيبعت X-Content-Type-Options', str_contains($fc, 'X-Content-Type-Options'));

    // ── 4ب) دخول المناديب (المرحلة 2) ─────────────────────────────────────
    head('4ب) تقوية دخول المناديب');
    $ra = (string) file_get_contents("$root/app/Core/RepAuth.php");
    check(
        'الرقم السري 6 خانات على الأقل',
        preg_match('/PIN_MIN\s*=\s*(\d+)/', $ra, $m) && (int) $m[1] >= 6,
        'أربع خانات = 10,000 احتمال بس، بتتكسر في أسبوعين.'
    );
    check(
        'القفل بيتصاعد — العدّاد ما بيتصفّرش وقت القفل',
        !preg_match('/failed_tries\s*=\s*0\s*,\s*\R?\s*locked_until\s*=\s*DATE_ADD/i', $ra)
            && str_contains($ra, 'LOCK_STEPS'),
        'التصفير كان بيخلّي التصعيد ما يحصلش: 5 محاولات كل 10 دقايق للأبد.'
    );
    check('فيه حد على مستوى الـIP', str_contains($ra, 'IP_MAX_FAILURES'));
    check('محاولات الدخول بتتسجّل', str_contains($ra, 'auth_login_attempts'));
    check('فيه انتهاء للجلسة بالخمول', str_contains($ra, 'IDLE_MINUTES'));
    check('بيعيد التأكد إن الحساب لسه مفعّل', str_contains($ra, 'stillActive'));
    check('هاش وهمي ضد كشف الحسابات بالتوقيت', str_contains($ra, 'DUMMY_HASH'));
    check(
        'رسالة الفشل ما بتقولش عدد المحاولات الفاضلة',
        !str_contains(code_only($ra), 'فاضلك'),
        'الرسالة دي كانت بتأكّد للمهاجم إن الحساب موجود وشغّال.'
    );
    check('الخروج بينهي الكوكي', str_contains($ra, 'Auth::logout()'));

    // ⚠️ مصيدة متحقَّق منها على الإنتاج: MySQL بتشتغل بـUTC وPHP بـAfrica/Cairo
    //    (فرق 3 ساعات). أي مقارنة بين وقت جاي من القاعدة و`time()` بتاعة PHP
    //    بتطلع غلط بـ3 ساعات — وده كان **بيعطّل قفل الحساب تمامًا** (القفل
    //    10 دقايق كان بيتقرا على إنه عدّى من 170 دقيقة). المقارنة لازم تحصل
    //    جوّه SQL عشان الطرفين يكونوا على نفس الساعة.
    $tzSafe = static function (string $src): bool {
        $code = code_only($src);
        return !preg_match('/strtotime\s*\([^)]*(locked_until|expires_at)/i', $code)
            && !preg_match('/(locked_until|expires_at)[^;]{0,80}\btime\s*\(\s*\)/i', $code);
    };
    check(
        'قفل الحساب بيتقارن جوّه SQL مش بوقت PHP (RepAuth)',
        $tzSafe($ra),
        'MySQL بـUTC وPHP بـCairo — المقارنة في PHP بتعطّل القفل بالكامل.'
    );
    check(
        'قفل الحساب بيتقارن جوّه SQL مش بوقت PHP (Auth)',
        $tzSafe((string) file_get_contents("$root/app/Core/Auth.php")),
        'نفس المصيدة كانت بتعطّل قفل حساب المكتب.'
    );
    check(
        'صلاحية رابط السائق بتتقارن جوّه SQL',
        $tzSafe((string) file_get_contents("$root/app/Models/Track.php")),
        'كانت بتقفل رابط السائق قبل ميعاده بـ3 ساعات.'
    );

    $au = (string) file_get_contents("$root/app/Core/Auth.php");
    check(
        'جلسة المكتب بتتأكد إن المستخدم لسه موجود ودوره ما اتغيّرش',
        str_contains($au, 'function revalidate'),
        'من غيرها حذف مستخدم أو تنزيل دوره ما بيأثّرش على جلسته المفتوحة.'
    );

    // ── 4ج) تسريب البيانات للخارج (المرحلة 3) ─────────────────────────────
    head('4ج) منع تسريب البيانات لأطراف تانية');
    // ⚠️ بندوّر على **التحميل التلقائي** بس: <img>/<script>/<iframe>/<link>
    //    وfetch. الـ`<a href="https://wa.me/...">` و«افتح في خرايط جوجل» **مش**
    //    تسريب — دول روابط المستخدم بيدوس عليها بنفسه ودي وظيفتها. اللي كان
    //    خطر هو الـQR: `<img src="https://api.qrserver.com/...?data=اسم العميل
    //    والمبلغ">` — كان بيتحمّل لوحده ويبعت البيانات مع كل طبعة.
    //    خرايط OpenStreetMap مسموحة صراحةً في الـCSP (بتبعت إحداثيات بس).
    $riskyRe = '#(<img\b[^>]*\bsrc|<script\b[^>]*\bsrc|<iframe\b[^>]*\bsrc'
             . '|<link\b[^>]*\bhref|fetch\s*\(\s*["\'])\s*=?\s*["\']?https?://#i';
    $allowHost = '#https?://(\{s\}\.)?tile\.openstreetmap\.org#i';
    $extern = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app/Views")) as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        $src = code_only((string) file_get_contents($f->getPathname()));
        if (preg_match_all($riskyRe, $src, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as [$hit, $off]) {
                $line = substr($src, $off, 160);
                if (preg_match($allowHost, $line)) { continue; }
                $extern[] = basename($f->getPathname());
            }
        }
    }
    $extern = array_values(array_unique($extern));
    check(
        'مفيش مصدر خارجي بيتحمّل تلقائيًّا في أي صفحة',
        $extern === [],
        $extern ? 'في: ' . implode(', ', $extern)
                . "\n      الـQR كان بيبعت اسم العميل والمبلغ لـapi.qrserver.com كل طبعة." : ''
    );
    check(
        'CSP ما بتسمحش لـqrserver',
        !str_contains($ht, 'qrserver'),
        'اتشال الـQR فالسماح ده مالوش لزمة.'
    );
    check(
        'فيه حد لأسئلة المساعد الذكي (منع استنزاف رصيد Groq)',
        str_contains((string) file_get_contents("$root/app/Controllers/AssistantController.php"), 'ASK_LIMIT')
    );
    // ⚠️ **مش check** عن قصد. `LimitRequestBody` موجود في .htaccess بس متحقَّق
    //    فعليًّا إن LiteSpeed بتاعة الاستضافة بتتجاهله (رفع 30 ميجا عدّى كامل).
    //    لو خلّيناه فحصًا ناجحًا هيدّي **طمأنينة كاذبة** إن فيه حد وهو مش موجود.
    if (preg_match('/^\s*LimitRequestBody\s+\d+/m', $htPub)) {
        warn('حد حجم الطلب مكتوب بس الاستضافة بتتجاهله',
             "لازم يتظبط من لوحة تحكم هوستنجر: upload_max_filesize=20M و post_max_size=24M.\n"
             . '      دلوقتي 1536M — الحماية القايمة هي فحص الـ12 ميجا في الكود بعد الاستقبال.');
    } else {
        check('حد حجم الطلب مكتوب في .htaccess', false, 'اتشال من public/.htaccess');
    }

    // ── 4د) نسخ قاعدة البيانات ما تترفعش في git ───────────────────────────
    head('4د) نسخ قاعدة البيانات بعيدة عن git');
    $giPath = "$root/../.gitignore";
    if (!is_file($giPath)) {
        warn('مفيش .gitignore هنا — الفحص ده للمستودع بس', 'طبيعي على السيرفر.');
    } else {
        $gi = (string) file_get_contents($giPath);
        check('*.sql متجاهَلة', str_contains($gi, '*.sql'));
        check('*.sql.gz متجاهَلة', str_contains($gi, '*.sql.gz'));
        check('مجلد database/backups متجاهَل', str_contains($gi, 'database/backups/'));
    }
    if ($canExec && is_dir("$root/../.git")) {
        $tracked = trim((string) @shell_exec(
            'cd ' . escapeshellarg("$root/..") . " && git ls-files | grep -E '\\.sql(\\.gz)?$' | grep -v 'schema.sql' 2>/dev/null"));
        check(
            'مفيش أي نسخة قاعدة متتبَّعة في git',
            $tracked === '',
            $tracked ? "متتبَّعة: " . str_replace("\n", ', ', $tracked)
                     . "\n      دي فيها بيانات العملاء وهاشات كلمات السر." : ''
        );
    } else {
        warn('اتخطّى فحص git — مش متاح هنا');
    }

    // ── 4هـ) صلاحيات مستخدم قاعدة البيانات ────────────────────────────────
    // ⚠️ ليه ده مهم: لو ظهرت ثغرة SQL يومًا ما، **صلاحيات المستخدم هي اللي
    //    بتحدّد حجم الكارثة**. أخطرها `FILE` — بتخلّي المهاجم يقرا ملفات
    //    السيرفر (config.php) أو يكتب webshell، يعني الثغرة تتحوّل لاختراق
    //    كامل للجهاز. القسم ده بيتأكد إن الصلاحيات الخطرة دي مرفوضة.
    // ⚠️ بتتشغّل بـ`--db-privs` بس، و`deploy.sh` بينادّيها **على السيرفر**
    //    بعد الرفع. السبب: قاعدة التطوير المحلية صلاحياتها واسعة بطبيعتها
    //    (وده عادي)، فتشغيلها محليًّا كان هيفشل الرفع بإنذار كاذب. الصلاحيات
    //    اللي تهمّنا هي بتاعة **الإنتاج**، فبنفحصها هناك.
    if (!$dbPrivs) {
        head('4هـ) صلاحيات قاعدة البيانات — اتخطّت');
        warn('بتتفحص على السيرفر بعد الرفع',
             'شغّلها يدويًّا بـ: php bin/verify_security.php --db-privs');
        goto after_db_privs;
    }
    db_privs_only:
    head('صلاحيات مستخدم قاعدة البيانات');
    try {
        if (!$booted) { throw new RuntimeException('الإقلاع فشل — غالبًا إعدادات القاعدة'); }
        $db = 'App\Core\Database';

        $grants = array_map(static fn($r) => (string) reset($r), $db::all('SHOW GRANTS'));
        $global = implode(' ', array_filter($grants, static fn($g) => str_contains($g, 'ON *.*')));
        check(
            'مفيش صلاحيات عامة على كل القواعد (USAGE بس)',
            !preg_match('/GRANT\s+(?!USAGE\b)[A-Z, ]*\s+ON\s+\*\.\*/i', $global),
            "الصلاحيات العامة: " . substr($global, 0, 120)
        );

        // FILE صلاحية عامة بس — لو مش في `ON *.*` يبقى مش ممنوحة. وبنأكّد عمليًّا.
        $fileOk = true;
        try { $fileOk = $db::scalar("SELECT LOAD_FILE('/etc/hostname')") === null; }
        catch (\Throwable $e) { $fileOk = true; }
        check(
            'صلاحية FILE مرفوضة (مايقدرش يقرا ملفات السيرفر ولا يكتب webshell)',
            $fileOk,
            'دي أخطر صلاحية — بتحوّل أي ثغرة SQL لاختراق كامل للسيرفر.'
        );

        $others = false;
        try {
            $me = (string) $db::scalar('SELECT SUBSTRING_INDEX(CURRENT_USER(), "@", 1)');
            foreach ($db::all('SHOW FULL PROCESSLIST') as $r) {
                if ((string) ($r['User'] ?? $me) !== $me) { $others = true; break; }
            }
        } catch (\Throwable $e) { /* مرفوضة أصلاً = تمام */ }
        check('مايقدرش يشوف اتصالات مستخدمين تانيين (PROCESS)', !$others);

        $foreign = false;
        try { $db::all('SELECT 1 FROM mysql.user LIMIT 1'); $foreign = true; }
        catch (\Throwable $e) { /* مرفوضة = تمام */ }
        check('مايقدرش يقرا قواعد بيانات تانية', !$foreign);

        $li = $db::row("SHOW VARIABLES LIKE 'local_infile'");
        check('local_infile مقفولة', strtoupper((string) ($li['Value'] ?? 'OFF')) === 'OFF');

        // معلوماتي: صلاحيات كاملة على قاعدته هو — لازمة للهجرات، وتقليلها
        // محتاج مستخدم تاني من لوحة الاستضافة (قرار مؤجَّل، موثَّق في CLAUDE.md).
        if (preg_match('/GRANT\s+ALL\s+PRIVILEGES\s+ON\s+`?[^`\s]+`?\.\*/i', implode(' ', $grants))) {
            warn('صلاحيات كاملة على قاعدته هو (DROP/ALTER ضمنها)',
                 "لازمة عشان الهجرات تشتغل. تقليلها يحتاج مستخدم تاني من لوحة\n"
                 . '      الاستضافة — الخطر محدود لأن الحقن نظيف وFILE مرفوضة.');
        }
    } catch (\Throwable $e) {
        warn('اتخطّى فحص صلاحيات القاعدة', substr($e->getMessage(), 0, 90));
    }
    if ($dbPrivs) { goto done; }
    after_db_privs:

    // ── 5) أساسيات ما تتكسرش ──────────────────────────────────────────────
    head('5) أساسيات موجودة سلفًا — نتأكد إنها ما اتكسرتش');
    $auth = (string) file_get_contents("$root/app/Core/Auth.php");
    check('كلمة السر متشفّرة بـpassword_verify', str_contains($auth, 'password_verify'));
    check('الجلسة بتتجدّد عند الدخول (يمنع تثبيت الجلسة)', str_contains($auth, 'session_regenerate_id(true)'));
    check('كوكي الجلسة httponly', str_contains($auth, "'httponly' => true"));
    check('توكن CSRF بيتقارن بـhash_equals', str_contains($auth, 'hash_equals'));

    /* ⚠️⚠️ عمر الجلسة — باج «المندوب بيتطرد وهو بيرفق إيصال» (2026-08-02).
       كان الكوكي `lifetime => 0` (كوكي متصفح): تطبيق PWA بيتقفل من نظام
       الموبايل وهو في الخلفية أثناء فتح الكاميرا ⇒ الكوكي بيضيع ⇒ المندوب
       بيلاقي نفسه على شاشة الدخول والصورة راحت. وPHP كمان كانت بتمسح ملف
       الجلسة بعد ٢٤ دقيقة خمول (`gc_maxlifetime=1440`) وده بيلغي سياسة النظام
       نفسها (مندوب ٨ ساعات · مكتب ساعتين) **بالسكوت**.
       ⚠️ **ده مش تخفيف أمني**: الحد الحقيقي خادمي (`IDLE_MINUTES` في
          `Auth`/`RepAuth` + إعادة التأكّد من الحساب كل دقيقتين) وما اتغيّرش. */
    check('عمر كوكي الجلسة أطول من صفر (تطبيق PWA ما يخرجش لما النظام يقفله)',
        str_contains($auth, "'lifetime' => self::SESSION_LIFETIME"));
    check('وعمر ملف الجلسة على الخادم متظبّط كمان (مش 24 دقيقة الافتراضية)',
        str_contains($auth, "ini_set('session.gc_maxlifetime'"));
    check('⚠️ وبيتظبّط قبل session_start (بعدها مالوش أي تأثير)',
        strpos($auth, "ini_set('session.gc_maxlifetime'") < strpos($auth, 'session_start()'));
    check('النافذة متحرّكة مع النشاط (الكوكي بتتجدّد كل طلب)',
        str_contains($auth, 'refreshCookie'));
    check('⚠️ والتجديد بعد فحص headers_sent (وإلا تحذير جوّه أي رد JSON)',
        str_contains($auth, 'headers_sent()'));
    check('⚠️ والزائر بلا جلسة ماياخدش كوكي دايمة على الفاضي',
        str_contains($auth, 'if (empty($_SESSION)) { return; }'));
    check('★ سياسة الخمول الخادمية ما اتلمستش — المكتب',
        str_contains($auth, 'IDLE_MINUTES = 120'));
    check('★ وسياسة خمول المندوب كمان',
        str_contains((string) file_get_contents("$root/app/Core/RepAuth.php"), 'IDLE_MINUTES = 480'));
    /* ⚠️ عمر الملف لازم **يغطّي** أطول نافذة خمول، وإلا PHP بتمسح الجلسة قبل
         ما النظام يقول إنها انتهت — وده بالظبط اللي كان بيحصل. */
    check('★ عمر ملف الجلسة ≥ أطول نافذة خمول في النظام',
        \App\Core\Auth::SESSION_LIFETIME >= 480 * 60);
    check('الصلاحيات بتتفرض مركزيًّا في الراوتر', str_contains((string) file_get_contents("$root/app/Core/Router.php"), 'Access::enforce'));
    // نفس السبب: shell_exec مقفولة على الإنتاج. بنقرا فهرس git بلا تشغيل أوامر.
    $gitDir = "$root/../.git";
    $tracked = ($canExec && is_dir($gitDir))
        ? trim((string) @shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files config/config.php 2>/dev/null'))
        : '';
    check('config.php مش متتبَّع في git', $tracked === '');
    check('config.php موجود ومقروء للتطبيق بس', !is_file("$root/config/config.php")
        || (fileperms("$root/config/config.php") & 0o077) === 0);

    // ── 6) سلامة نحوية ────────────────────────────────────────────────────
    // ⚠️ استضافة الإنتاج بتقفل كل دوال تشغيل العمليات (exec/shell_exec/…)،
    //    فالقسم ده بيشتغل محليًّا بس. من غير الحارس ده السكربت بيموت بـfatal
    //    على السيرفر (اتصادف فعليًا) — والفحص لازم يشتغل في البيئتين.
    head('6) سلامة الملفات المعدَّلة');
    if (!$canExec) {
        warn('اتخطّى فحص النحو — دوال التشغيل مقفولة على الاستضافة',
             'بيتفحص محليًّا قبل الرفع (خطوة 0 في deploy.sh)، وده الأهم.');
    } else {
        $badSyntax = [];
        foreach (array_merge(glob("$root/bin/*.php"), [
            "$root/app/Core/helpers.php", "$root/app/Core/Auth.php",
            "$root/app/Core/RepAuth.php", "$root/app/Controllers/FileController.php",
        ]) as $f) {
            $o = []; $rc = 0;
            exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
            if ($rc !== 0) { $badSyntax[] = basename($f); }
        }
        check('كل الملفات المعدَّلة سليمة نحويًّا', $badSyntax === [], $badSyntax ? implode(', ', $badSyntax) : '');
    }

// ══════════════════════════════════════════════════════════════════════════
} else {
    printf("\n\033[1m فحص أمان الموقع الشغّال — %s\033[0m\n", $remote);

    $get = function (string $path) use ($remote): array {
        $ch = curl_init($remote . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // مفيش curl_close: مهملة من PHP 8.0 ومالهاش أي تأثير، وبتطبع تحذير
        // على 8.5 بيغرق مخرجات الرفع. الـhandle بيتقفل لوحده مع نهاية النطاق.
        return ['code' => $code, 'headers' => strtolower(substr($body, 0, $hlen)), 'body' => substr($body, $hlen)];
    };

    head('1) الملفات الحسّاسة مش مفتوحة على الإنترنت');
    foreach ([
        '/config/config.php'   => 'باسورد قاعدة البيانات ومفتاح Groq',
        '/bin/install.php'     => 'سكربت فيه DROP DATABASE',
        '/bin/migration.php'   => 'بوابة تشغيل وتسجيل الترحيلات',
        '/bin/reconcile_migrations.php' => 'أداة تسوية ledger',
        '/bin/migrate_v47.php' => 'هجرة بتعدّل القاعدة',
        '/app/Core/Auth.php'   => 'كود المصادقة',
        '/storage/'            => 'سجلات ونسخ احتياطية',
        '/database/'           => 'مخطط القاعدة',
        '/uploads/permits/'    => 'مرفقات العملاء',
        '/.htaccess'           => 'ملف الحماية نفسه',
    ] as $p => $what) {
        $r = $get($p);
        check(sprintf('%-24s محجوب (%s)', $p, $what), in_array($r['code'], [403, 404], true), "رد بـHTTP {$r['code']} — مكشوف!");
    }

    head('2) رؤوس الأمان واصلة فعلًا للمتصفح');
    $r = $get('/login');
    check('صفحة الدخول شغّالة (200)', $r['code'] === 200, "ردت بـ{$r['code']}");
    $h = $r['headers'];
    check('CSP فيها connect-src (مش مستبدَلة من الاستضافة)', str_contains($h, "connect-src 'self'"),
        "الاستضافة بتكتب CSP خاصة بيها فوق بتاعتنا. الرد الحالي:\n      "
        . trim(implode(' ', array_filter(explode("\n", $h), fn($l) => str_contains($l, 'content-security-policy')))));
    check("CSP فيها object-src 'none'", str_contains($h, "object-src 'none'"));
    check('Strict-Transport-Security مبعوت', str_contains($h, 'strict-transport-security'));
    check('X-Content-Type-Options: nosniff', str_contains($h, 'nosniff'));
    check('X-Frame-Options مبعوت', str_contains($h, 'x-frame-options'));
    check('X-Robots-Tag: noindex (النظام مخفي عن جوجل)', str_contains($h, 'noindex'));
    check('مفيش X-Powered-By', !str_contains($h, 'x-powered-by'));
    check('كوكي الجلسة Secure + HttpOnly', str_contains($h, 'secure') && str_contains($h, 'httponly'));

    head('3) الصفحات المحميّة مقفولة على غير المسجَّل');
    // ملحوظة: لوحة التحكم على '/' مش '/dashboard' (شوف public/index.php سطر 26).
    foreach (['/', '/expenses', '/settings', '/permits', '/audit'] as $p) {
        $r = $get($p);
        check(sprintf('%-12s بيحوّل لتسجيل الدخول', $p), in_array($r['code'], [302, 303, 401, 403], true), "رد بـ{$r['code']}");
    }
    $r = $get('/m/login');
    check('بوابة المناديب شغّالة', $r['code'] === 200, "ردت بـ{$r['code']}");
}

done:
// ══════════════════════════════════════════════════════════════════════════
printf("\n%s\n", str_repeat('═', 62));
printf(" \033[32mنجح: %d\033[0m   \033[31mفشل: %d\033[0m   \033[33mتنبيه: %d\033[0m\n", $pass, $fail, $warn);
if ($fail) {
    printf("\n\033[31m فشل الفحص — دي الحاجات اللي لازم تتصلّح:\033[0m\n");
    foreach ($failures as $f) { printf("   • %s\n", $f); }
    printf("\n");
    exit(1);
}
printf("\n\033[32m كل الفحوصات عدّت.\033[0m\n\n");
exit(0);
