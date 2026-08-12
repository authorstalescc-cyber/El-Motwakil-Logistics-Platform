<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص النسخة الاحتياطية اليومية التلقائية.
 *
 * القواعد المحروسة هنا:
 *   ① **مفيش شرط بشري** — النسخة اليومية بتتاخد من كرون **شغّال فعلاً**
 *      (`bin/push_worker.php`) مش من كرون شِل لازم يتظبّط بالإيد من لوحة
 *      الاستضافة. ده جوهر العطل اللي الوحدة دي اتعملت عشانه: السكربت الشِل
 *      كان سليمًا ١٠٠٪ وقعد ١٥ يوم ما اشتغلش ولا مرة.
 *   ② **الـdump بلا أي دالة تشغيل عمليات** — دوال التشغيل مقفولة على
 *      الاستضافة، فأي اعتماد على `mysqldump` من PHP = صفر نسخ على الإنتاج.
 *   ③ **الأعمدة المولَّدة برّه قايمة `INSERT`** — وجود عمود مولَّد في
 *      الـINSERT بيفشل **الاستعادة** لا النسخ، يعني العطل بيظهر يوم ما تكون
 *      محتاجها. الفحص بيدوّر على أعمدة مولَّدة حقيقية في القاعدة.
 *   ④ **النسخة كاملة** — كل جدول له `CREATE TABLE`، وكل مفتاح في عيّنة
 *      جداول موجود في النص فعلاً (مش مجرد «الملف حجمه كبير»).
 *   ⑤ **«كملت» تتقاس بآخر سطر** — ملف gzip فاضي بيعدّي من `gzip -t` بنجاح،
 *      فالدليل الوحيد هو `Dump completed`.
 *   ⑥ **الفشل ما بيلمسش `backup_last_ok`** — وإلا التوقّف يبقى صامت للأبد
 *      و`Notification::staleBackup()` تسكت وهي المفروض تصرخ.
 *   ⑦ **مرة واحدة في اليوم + خنق إعادة المحاولة** — دوال خالصة متفحوصة
 *      بمصفوفة حالات، مش بتوقيت التشغيل.
 *
 * الفحص **بيكتب ملف نسخة مؤقّت في `storage/` وبيمسحه**، وما بيكتبش ولا صف
 * في القاعدة (مفاتيح الإعدادات بترجع لقيمتها في `finally`).
 *
 * التشغيل:  php bin/verify_backup_auto.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\Settings;
use App\Core\SettingsSchema;
use App\Core\SqlDump;
use App\Models\AutoBackup;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }
function note(string $m): void { echo "  • $m\n"; }

/** يشيل تعليقات PHP — تعليق بيشرح نمطًا ما يعتبرش استخدامًا له. */
function code_only(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}
function src(string $file): string
{
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
}

echo "فحص النسخة الاحتياطية اليومية التلقائية\n" . str_repeat('=', 62) . "\n";

// ═══ ① الاعتماد على كرون شغّال فعلاً ═══════════════════════════════════
sec('① النسخة معلَّقة على نبضة قايمة — مش على ضغطة من حد');

$worker = code_only(src('bin/push_worker.php'));
chk(str_contains($worker, 'AutoBackup::runIfDue()'),
    'عامل الكرون الشغّال (`push_worker.php`) بينادي `AutoBackup::runIfDue()`');
chk(str_contains($worker, 'push_worker_last_run'),
    'ونفس العامل بيختم آخر تشغيل — فتوقّفه نفسه بيبان');

$autoSrc = src('app/Models/AutoBackup.php');
chk($autoSrc !== '', '`app/Models/AutoBackup.php` موجود');

// نقاط الاستدعاء: أي مكان تاني بيدّي نسخة يومية لازم يكون معروفًا
$callers = [];
foreach (['bin/push_worker.php', 'bin/backup_db.php', 'bin/backup_cron.sh'] as $f) {
    if (str_contains(src($f), 'AutoBackup::')) { $callers[] = $f; }
}
chk(in_array('bin/push_worker.php', $callers, true),
    'نقطة الاستدعاء الأساسية معروفة: ' . implode(' · ', $callers));

// ═══ ② الـdump بلا أي دالة تشغيل عمليات ════════════════════════════════
sec('② الـdump بيشتغل والاستضافة قافلة دوال التشغيل');

$dumpSrc = code_only(src('app/Core/SqlDump.php'));
/* ⚠️ `$pdo->exec(` مش `exec(` — الفحص النصّي الساذج كان بيبلّغ عن استعلام
      PDO عادي كأنه تشغيل عملية. لازم استدعاء **حرّ** مش نداء على كائن. */
$forbidden = ['proc_open', 'shell_exec', 'popen', 'passthru', 'system'];
$found = [];
foreach ($forbidden as $fn) {
    if (preg_match('/(?<![>:$\w])' . preg_quote($fn, '/') . '\s*\(/', $dumpSrc)) { $found[] = $fn; }
}
if (preg_match('/(?<![>:$\w])exec\s*\(/', $dumpSrc)) { $found[] = 'exec'; }
chk($found === [], '`SqlDump` مفيهاش ولا دالة تشغيل عمليات'
    . ($found ? ' — لقينا: ' . implode(', ', $found) : ''));
chk(str_contains($dumpSrc, 'Database::') || str_contains($dumpSrc, 'PDO'),
    'بتقرا بـPDO — نفس اتصال النظام');

$backupSrc = code_only(src('app/Core/DatabaseBackup.php'));
chk(str_contains($backupSrc, 'SqlDump::toFile'),
    'زرار النسخة اليدوي بيقع على `SqlDump` لما `mysqldump` مش متاحة (فبيشتغل على الإنتاج)');
chk(!str_contains($backupSrc, 'النسخ الاحتياطي من داخل النظام متوقف'),
    'ورسالة «النسخ الاحتياطي متوقف على السيرفر ده» اتشالت');

// ═══ ③ + ④ + ⑤ نسخة حقيقية على القرص ═══════════════════════════════════
sec('③④⑤ نسخة حقيقية: كاملة · الأعمدة المولَّدة برّه · وكملت فعلاً');

$tmpDir = App::basePath('storage/backup_check');
@mkdir($tmpDir, 0700, true);
$tmpFile = $tmpDir . '/probe-' . date('His') . '-' . bin2hex(random_bytes(3)) . '.sql.gz';

$dump = null;
try {
    $t0   = microtime(true);
    $dump = SqlDump::toFile($tmpFile);
    $ms   = round((microtime(true) - $t0) * 1000);
    ok("النسخة اتعملت: {$dump['tables']} جدول · " . number_format($dump['rows']) . " صف · "
       . number_format($dump['bytes'] / 1024) . " ك.ب · {$ms}ms");
} catch (\Throwable $e) {
    bad('فشل إنشاء النسخة: ' . $e->getMessage());
}

if ($dump !== null) {
    $sql = (string) @gzdecode((string) @file_get_contents($tmpFile));
    chk($sql !== '', 'الملف gzip سليم وبيتفكّ');

    // ⑤ الدليل الوحيد إن الـdump كمل
    chk(str_contains(AutoBackup::tail($tmpFile), SqlDump::DONE_MARKER),
        'آخر سطر فيه «' . SqlDump::DONE_MARKER . '» — الدليل الوحيد إن الـdump كمل');

    // ④ كل جدول له بنية
    $tables  = SqlDump::tables();
    $missing = [];
    foreach ($tables as $t) {
        if (!str_contains($sql, 'CREATE TABLE `' . $t . '`')) { $missing[] = $t; }
    }
    chk($missing === [], 'كل الجداول (' . count($tables) . ') لها `CREATE TABLE`'
        . ($missing ? ' — ناقص: ' . implode(', ', array_slice($missing, 0, 5)) : ''));

    chk(str_contains($sql, 'SET FOREIGN_KEY_CHECKS=0'),
        'المفاتيح الأجنبية متوقّفة أثناء الاستعادة (وإلا الترتيب بيفشلها)');

    /* ③ قايمة أعمدة الـINSERT = **كل** أعمدة الجدول ناقص المولَّد بس.
          ⚠️⚠️ الفحص ده هو اللي صاد المصيدة الحقيقية: شرط
             `extra LIKE '%GENERATED%'` بيطابق `DEFAULT_GENERATED` كمان —
             وده وسم أي عمود عنده `DEFAULT CURRENT_TIMESTAMP`، يعني كل
             `created_at`/`updated_at` كانت بتتشال من النسخة **بالسكوت**
             وتترجع بوقت الاستعادة. عمود ناقص ما بيديش أي خطأ، فالفحص
             لازم يقارن القايمة كلها لا يدوّر على الأسماء المعروفة. */
    $genPairs = [];
    foreach (SqlDump::generatedColumns() as $g) { $genPairs[$g['t'] . '.' . $g['c']] = true; }
    note('أعمدة مولَّدة حقيقية في القاعدة: ' . (count($genPairs) ?: 'ولا واحد')
         . ($genPairs ? ' (' . implode(' · ', array_keys($genPairs)) . ')' : ''));

    $colProblems = [];
    foreach ($tables as $t) {
        $needle = 'INSERT INTO `' . $t . '` (';
        $at = strpos($sql, $needle);
        if ($at === false) { continue; }               // جدول فاضي — مفيش INSERT
        $end  = strpos($sql, ')', $at);
        $cols = substr($sql, $at + strlen($needle), (int) ($end - $at - strlen($needle)));
        $inDump = array_map(
            static fn(string $c): string => trim($c, " `"),
            explode(',', $cols));

        $all = Database::all(
            "SELECT column_name AS c FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position", [$t]);
        foreach ($all as $row) {
            $col       = (string) $row['c'];
            $generated = isset($genPairs["$t.$col"]);
            $present   = in_array($col, $inDump, true);
            if ($generated && $present)  { $colProblems[] = "$t.$col مولَّد ومكتوب (الاستعادة هتفشل)"; }
            if (!$generated && !$present) { $colProblems[] = "$t.$col ناقص من النسخة (فقد صامت)"; }
        }
    }
    chk($colProblems === [],
        'قايمة أعمدة كل `INSERT` = أعمدة الجدول بالظبط ناقص المولَّد'
        . ($colProblems ? ' — ' . implode(' | ', array_slice($colProblems, 0, 4))
                          . (count($colProblems) > 4 ? ' … +' . (count($colProblems) - 4) : '') : ''));

    /* ④ اكتمال البيانات — مش «الحجم كبير». بناخد جداول صغيرة معروفة
          ونتأكد إن **كل مفتاح فيها** موجود في النص. */
    $samples = [
        ['settings', 'setting_key'],
        ['users', 'username'],
    ];
    foreach ($samples as [$table, $col]) {
        if (!in_array($table, $tables, true)) { continue; }
        $keys = Database::all("SELECT `$col` AS k FROM `$table`");
        if ($keys === []) { note("$table فاضي — اتخطّى"); continue; }
        $lost = 0;
        foreach ($keys as $row) {
            $v = (string) $row['k'];
            if ($v !== '' && !str_contains($sql, $v)) { $lost++; }
        }
        chk($lost === 0, "كل قيم `$table.$col` (" . count($keys) . ") موجودة في النسخة"
            . ($lost ? " — ناقص $lost" : ''));
    }

    // عدد الصفوف اللي الـdump قاله = العدد الحقيقي في القاعدة
    $real = 0;
    foreach ($tables as $t) { $real += (int) Database::scalar("SELECT COUNT(*) FROM `$t`"); }
    chk($dump['rows'] === $real,
        "عدد الصفوف المكتوبة = عدد صفوف القاعدة (" . number_format($real) . ")");
}

@unlink($tmpFile);
@rmdir($tmpDir);
chk(!is_file($tmpFile), 'ملف الفحص المؤقّت اتمسح');

// ═══ ⑥ الفشل ما بيلمسش علامة النجاح ════════════════════════════════════
sec('⑥ الفشل بيفضل باينًا — ما بيزوّرش «آخر نجاح»');

$auto = code_only($autoSrc);
$catchAt = strpos($auto, 'catch (\Throwable $e)');
$catchBlock = $catchAt === false ? '' : substr($auto, $catchAt, 600);
chk($catchAt !== false && !str_contains($catchBlock, "setState('backup_last_ok'"),
    'مسار الفشل ما بيكتبش `backup_last_ok` — عمر آخر نجاح بيفضل حقيقيًّا');
chk(str_contains($catchBlock, 'backup_last_msg'),
    'وبيسجّل سبب الفشل في `backup_last_msg`');
chk(str_contains($auto, 'DONE_MARKER'),
    'النسخة بتتفحص بعلامة الاكتمال قبل ما تتعلن ناجحة');
chk(str_contains($auto, '@unlink($path)'),
    'والنسخة الناقصة بتتمسح — نسخة تالفة أسوأ من نسخة مش موجودة');

$notif = src('app/Models/Notification.php');
chk(str_contains($notif, 'backup_last_ok') && str_contains($notif, 'backup_stale'),
    'وتنبيه «النسخة وقفت» لسه بيقيس عمر آخر نجاح');

foreach (['backup_last_ok', 'backup_last_msg', 'backup_last_try'] as $key) {
    $def = SettingsSchema::get($key);
    chk($def !== null && $def['tier'] === SettingsSchema::TIER_STATE,
        "المفتاح `$key` مسجَّل كمفتاح حالة (مش خانة إدخال في الشاشة)");
}

// ═══ ⑦ مرة واحدة في اليوم + خنق إعادة المحاولة ═════════════════════════
sec('⑦ الاستحقاق: مرة في اليوم، وبعد الفشل ساعة');

$now   = strtotime('2026-08-13 04:00:00');
$cases = [
    ['',                    '',                    true,  'مفيش ولا نسخة قبل كده → مستحقّة'],
    ['2026-08-13 00:03:00', '2026-08-13 00:03:00', false, 'اتاخدت النهاردة → مش مستحقّة'],
    ['2026-08-12 23:59:00', '',                    true,  'آخر نسخة إمبارح بالليل → مستحقّة'],
    ['2026-08-12 00:03:00', '2026-08-13 03:30:00', false, 'محاولة فشلت من نص ساعة → استنى'],
    ['2026-08-12 00:03:00', '2026-08-13 02:30:00', true,  'المحاولة الفاشلة بقالها ساعة ونص → أعد'],
    ['كلام مش تاريخ',        '',                    true,  'علامة مش مقروءة → مستحقّة (الأمان الافتراضي)'],
];
foreach ($cases as [$okAt, $tryAt, $want, $label]) {
    chk(AutoBackup::isDue($okAt, $tryAt, $now) === $want, $label);
}

// اليوم التقويمي هو الحد — مش ٢٤ ساعة
chk(AutoBackup::isDue('2026-08-12 23:00:00', '', strtotime('2026-08-13 01:00:00')) === true,
    'ساعتان بس بس اليوم اتغيّر → مستحقّة (اليوم التقويمي هو الحد)');
chk(AutoBackup::isDue('2026-08-13 01:00:00', '', strtotime('2026-08-13 23:00:00')) === false,
    'اليوم نفسه بعد ٢٢ ساعة → مش مستحقّة');

sec('⑧ التنظيف: آخر ٣٠ نسخة يومية بالاسم لا بتاريخ التعديل');

$names = [];
for ($i = 1; $i <= 35; $i++) { $names[] = sprintf('daily-202607%02d-030000.sql.gz', $i); }
shuffle($names);                                   // الترتيب الداخل مش مهم
$drop = AutoBackup::pruneList($names, 30);
chk(count($drop) === 5, 'من ٣٥ نسخة بتتمسح ٥ (الحد ٣٠)');
chk($drop[0] === 'daily-20260701-030000.sql.gz' && in_array('daily-20260705-030000.sql.gz', $drop, true),
    'الأقدم هو اللي بيتمسح — الترتيب بالختم اللي في الاسم');
chk(AutoBackup::pruneList(['daily-20260701-030000.sql.gz'], 30) === [],
    'أقل من الحد → مفيش حذف');
chk(AutoBackup::pruneList([], 30) === [], 'مجلد فاضي → مفيش حذف');

$pruneSrc = code_only($autoSrc);
chk(str_contains($pruneSrc, "self::PREFIX . '*.sql.gz'"),
    'التنظيف بيلمس النسخ اليومية بس — `pre-deploy-*` في أمان');

/* ⚠️ الفحص على **جسم دالة `prune()` وحده** لا على الملف كله: `recent()`
      بتستخدم `filemtime` للعرض وده مشروع، والفحص على الملف كله كان بيدّي
      إنذارًا كاذبًا (نفس مصيدة «القصّ قبل الفحص» الموثَّقة). */
$pruneBody = '';
if (preg_match('~function prune\(.*?\n    \}~s', $pruneSrc, $m)) { $pruneBody = $m[0]; }
chk($pruneBody !== '' && !str_contains($pruneBody, 'filemtime'),
    'التنظيف نفسه مش معتمد على تاريخ تعديل الملف (الترتيب من الاسم)');

// ═══ ⑧ب تمرين استعادة حقيقي ════════════════════════════════════════════
sec('⑧ب تمرين استعادة: النسخة بتترجع فعلاً على قاعدة نضيفة');

/* ⚠️ **ليه قاعدة خردة بدل rollback؟** الاستعادة `DROP/CREATE TABLE` وهو
      DDL بيعمل commit ضمني — فالمعاملة مالهاش أي معنى هنا. بنعمل قاعدة
      باسم `almotawakel_dumpcheck_*` **إحنا اللي أنشأناها في السطر ده**،
      وبنحذف الاسم ده وبس. أي قاعدة تانية مستحيل تتلمس.
   ⚠️ على الإنتاج `proc_open` مقفولة وصلاحية `CREATE DATABASE` مش موجودة —
      فالتمرين **بيتخطّى بملاحظة** مش بيفشل. */
$scratch = 'almotawakel_dumpcheck_' . bin2hex(random_bytes(4));
$mysql   = function_exists('proc_open') ? \App\Core\DatabaseBackup::findBinary('mysql') : null;
$probe   = App::basePath('storage/backup_check_restore.sql.gz');

if ($mysql === null) {
    note('أداة `mysql` أو `proc_open` مش متاحة — تمرين الاستعادة اتخطّى (متوقّع على الإنتاج)');
} else {
    $server = null;
    try { $server = Database::serverConn(); } catch (\Throwable $e) { $server = null; }
    if ($server === null) {
        note('مفيش اتصال خادم — تمرين الاستعادة اتخطّى');
    } else {
        $created = false;
        try {
            $server->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $created = true;
        } catch (\Throwable $e) {
            note('مفيش صلاحية إنشاء قاعدة — تمرين الاستعادة اتخطّى (متوقّع على الإنتاج)');
        }
        if ($created) {
            try {
                $expected = [];
                foreach (SqlDump::tables() as $t) {
                    $expected[$t] = (int) Database::scalar("SELECT COUNT(*) FROM `$t`");
                }
                SqlDump::toFile($probe);

                $cfg = App::config('db');
                $env = getenv();
                if ((string) ($cfg['pass'] ?? '') !== '') { $env['MYSQL_PWD'] = (string) $cfg['pass']; }
                // gzip مفكوك في PHP والـSQL بيتحقن على stdin — بلا صدفة ولا أنبوب شِل
                $plain = App::basePath('storage/backup_check_restore.sql');
                file_put_contents($plain, (string) gzdecode((string) file_get_contents($probe)));

                $pipes = [];
                $proc = proc_open([
                    $mysql, '--host=' . $cfg['host'], '--port=' . (int) $cfg['port'],
                    '--user=' . $cfg['user'], '--default-character-set=utf8mb4', $scratch,
                ], [0 => ['file', $plain, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                   $pipes, App::basePath(), $env);

                $err = '';
                if (is_resource($proc)) {
                    stream_get_contents($pipes[1]); fclose($pipes[1]);
                    $err = (string) stream_get_contents($pipes[2]); fclose($pipes[2]);
                    $code = proc_close($proc);
                } else { $code = 1; }
                chk($code === 0, 'الاستعادة عدّت بلا خطأ' . ($code === 0 ? '' : ' — ' . trim($err)));

                if ($code === 0) {
                    $check = new \PDO(
                        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                                $cfg['host'], (int) $cfg['port'], $scratch),
                        $cfg['user'], $cfg['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

                    $diff = [];
                    foreach ($expected as $t => $n) {
                        $got = (int) $check->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                        if ($got !== $n) { $diff[] = "$t: $n → $got"; }
                    }
                    chk($diff === [], 'عدد صفوف كل جدول بعد الاستعادة زي الأصل'
                        . ($diff ? ' — ' . implode(' | ', array_slice($diff, 0, 3)) : ''));

                    /* ⚠️ العدد مش كفاية: عمود ناقص بيدّي نفس العدد بالظبط
                          وقيمًا مولودة من الافتراضي. بنقارن **القيم**. */
                    $valueDiff = [];
                    foreach (['settings' => 'setting_key', 'users' => 'username'] as $t => $key) {
                        if (!isset($expected[$t])) { continue; }
                        $mine = Database::all("SELECT * FROM `$t` ORDER BY `$key`");
                        $theirs = $check->query("SELECT * FROM `$t` ORDER BY `$key`")
                                        ->fetchAll(\PDO::FETCH_ASSOC);
                        if (json_encode($mine) !== json_encode($theirs)) { $valueDiff[] = $t; }
                    }
                    chk($valueDiff === [],
                        'وكل قيمة في `settings` و`users` رجعت بالحرف (مش الأعمدة الافتراضية)'
                        . ($valueDiff ? ' — اختلف: ' . implode(', ', $valueDiff) : ''));
                }
                @unlink($plain);
            } finally {
                @unlink($probe);
                // ⚠️ بنحذف **الاسم اللي أنشأناه في السطر اللي فوق** وبس
                $server->exec("DROP DATABASE IF EXISTS `$scratch`");
            }
        }
    }
}

// ═══ ⑨ التكرار: نسخة اليوم اتاخدت = مفيش تانية ═════════════════════════
sec('⑨ مفيش نسختين في اليوم');

$before = [
    'backup_last_ok'  => (string) App::setting('backup_last_ok', ''),
    'backup_last_try' => (string) App::setting('backup_last_try', ''),
    'backup_last_msg' => (string) App::setting('backup_last_msg', ''),
];
Database::conn()->beginTransaction();
try {
    Settings::setState('backup_last_ok', date('Y-m-d H:i:s'));
    Settings::setState('backup_last_try', date('Y-m-d H:i:s'));
    App::flushSettingsCache();
    $r = AutoBackup::runIfDue();
    chk($r === null, 'نسخة النهاردة موجودة → `runIfDue()` بترجع بلا ما تعمل ملف');
} finally {
    Database::conn()->rollBack();
    foreach ($before as $k => $v) { App::setSetting($k, $v); }
    App::flushSettingsCache();
}
chk((string) App::setting('backup_last_ok', '') === $before['backup_last_ok'],
    'مفاتيح الإعدادات رجعت زي ما كانت');

// ═══ الخلاصة ═══════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 62) . "\n";
printf("ناجح: %d   |   فاشل: %d\n", $pass, count($failures));
if ($failures) {
    echo "\nالفاشل:\n";
    foreach ($failures as $f) { echo "  ✗ $f\n"; }
    exit(1);
}
echo "✓ النسخة اليومية بتتاخد لوحدها، وكاملة، وفشلها بيبان.\n";
exit(0);
