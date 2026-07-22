<?php
namespace App\Core;

/** رؤوس وضبط نقل آمن لا يغيّر طريقة تشغيل النفق أو الشبكة المحلية. */
class SecurityHeaders
{
    public static function send(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) { return; }

        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header("Permissions-Policy: geolocation=(self), camera=(self), microphone=()");
        header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'");

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=15552000');
        }
        if (!empty($_SESSION['uid']) || !empty($_SESSION['rep_id'])) {
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') { return true; }

        // cloudflared يصل للخادم المحلي من loopback. لا نثق في X-Forwarded-Proto
        // القادم مباشرة من أجهزة الشبكة حتى لا يستطيع العميل فرض Secure خطأً.
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trustedLocalProxy = in_array($remote, ['127.0.0.1', '::1'], true);
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        return $trustedLocalProxy && $proto === 'https';
    }
}
