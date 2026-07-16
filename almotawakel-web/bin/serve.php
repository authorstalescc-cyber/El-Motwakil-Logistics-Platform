<?php
/**
 * تشغيل نظام المتوكل على خادم PHP المدمج.
 * التشغيل:  php bin/serve.php [المنفذ]
 */
$port = isset($argv[1]) ? (int)$argv[1] : 8000;
$publicDir = dirname(__DIR__) . '/public';

// إيجاد عناوين IP على الشبكة المحلية
$ips = [];
$out = [];
@exec("ipconfig getifaddr en0 2>/dev/null", $out);
@exec("ipconfig getifaddr en1 2>/dev/null", $out);
foreach ($out as $ip) { if (filter_var($ip, FILTER_VALIDATE_IP)) { $ips[] = $ip; } }
$ips = array_unique($ips);

$g="\033[32m"; $c="\033[36m"; $y="\033[33m"; $b="\033[1m"; $r="\033[0m";
echo "\n{$c}═══════════════════════════════════════════════{$r}\n";
echo "{$b}  نظام المتوكل — قيد التشغيل{$r}\n";
echo "{$c}═══════════════════════════════════════════════{$r}\n";
echo "  {$b}على هذا الجهاز:{$r}  {$g}http://localhost:{$port}{$r}\n";
foreach ($ips as $ip) {
    echo "  {$b}من الشبكة:{$r}      {$g}http://{$ip}:{$port}{$r}\n";
}
echo "\n  {$y}الدخول:{$r} admin / almotawakel  (غيّرها من الإعدادات لاحقاً)\n";
echo "  {$y}للإيقاف:{$r} اضغط Control + C\n";
echo "{$c}═══════════════════════════════════════════════{$r}\n\n";

// تشغيل الخادم على كل الواجهات ليصل إليه باقي أجهزة الشبكة.
// PHP_CLI_SERVER_WORKERS: يشغّل الخادم المدمج بعدة عُمّال بدل عامل واحد،
// فطلب بطيء (كطباعة عدة أذونات) لا يُجمّد بقية الصفحات. (يعمل على macOS/Linux.)
$workers = (int)(getenv('PHP_CLI_SERVER_WORKERS') ?: 6);
$cmd = sprintf(
    'PHP_CLI_SERVER_WORKERS=%d php -S 0.0.0.0:%d -t %s %s',
    $workers,
    $port,
    escapeshellarg($publicDir),
    escapeshellarg($publicDir . '/router.php')
);
passthru($cmd);
