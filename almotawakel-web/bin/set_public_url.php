<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * يحفظ «الرابط العام» الحالي في إعدادات النظام (settings.public_base_url).
 *
 * بيتنادى تلقائيًا من سكربت «تشغيل-عام-على-النت.command» أول ما نفق cloudflared
 * يجهّز رابطه، عشان روابط تتبع الشحنات اللي بتتبعت للسائقين تتبني على الرابط ده
 * بدل localhost (اللي مش هيفتح عند السائق).
 *
 * الاستخدام:
 *   php bin/set_public_url.php https://xxxx.trycloudflare.com
 *   php bin/set_public_url.php --clear      (مسح الرابط)
 *   php bin/set_public_url.php --show       (عرض الرابط المحفوظ)
 *
 * ⚠️ إعداد وصفي بحت — ما يمسّش أي حساب مالي.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;

$arg = $argv[1] ?? '';

if ($arg === '--show') {
    $cur = (string) App::setting('public_base_url', '');
    echo ($cur !== '' ? $cur : '(مفيش رابط عام محفوظ)') . PHP_EOL;
    exit(0);
}

if ($arg === '--clear') {
    App::setSetting('public_base_url', '');
    echo 'اتمسح الرابط العام.' . PHP_EOL;
    exit(0);
}

if ($arg === '') {
    fwrite(STDERR, "الاستخدام: php bin/set_public_url.php <https://…>  |  --clear  |  --show" . PHP_EOL);
    exit(1);
}

$url = rtrim(trim($arg), '/');
if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
    fwrite(STDERR, "رابط غير صالح: $url" . PHP_EOL);
    exit(1);
}
// رابط لوكال ما ينفعش يتبعت لسائق
$host = parse_url($url, PHP_URL_HOST) ?: '';
if (is_local_host($host)) {
    fwrite(STDERR, "الرابط ده محلّي ($host) — مش هيفتح عند السائق. اتجاهل." . PHP_EOL);
    exit(1);
}

App::setSetting('public_base_url', mb_substr($url, 0, 200));
echo "الرابط العام اتحفظ: $url" . PHP_EOL;
