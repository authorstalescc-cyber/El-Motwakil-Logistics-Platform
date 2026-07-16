<?php
namespace App\Core;

/**
 * المصادقة عبر الجلسة (حساب موحّد).
 */
class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(App::config('session_name') ?? 'almotawakel_session');
            session_start();
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $user = Database::row('SELECT * FROM users WHERE username = ?', [$username]);
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['uid']  = $user['id'];
            $_SESSION['uname'] = $user['display_name'] ?: $user['username'];
            $_SESSION['can_free_edit'] = (int)($user['can_free_edit'] ?? 0);
            session_regenerate_id(true);
            return true;
        }
        return false;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public static function user(): ?string
    {
        return $_SESSION['uname'] ?? null;
    }

    /** وضع جلسة اختياري يسمح بالكتابة على الأيام المقفولة حتى تسجيل الخروج. */
    public static function setFreeEditMode(bool $enabled): void
    {
        $_SESSION['free_edit_mode'] = ($enabled && self::canFreeEdit()) ? 1 : 0;
    }

    public static function canFreeEdit(): bool
    {
        return self::check() && (int)($_SESSION['can_free_edit'] ?? 0) === 1;
    }

    public static function freeEditMode(): bool
    {
        return self::check() && (int)($_SESSION['free_edit_mode'] ?? 0) === 1;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    // ───────────────────────────── تاريخ العمل (Working Date)
    // التاريخ الذي تُنسب إليه المدخلات الجديدة افتراضيًا (مصروف/إيصال/بوليصة…).
    // يُحدَّد عند الدخول ويظل ثابتًا طوال الجلسة حتى يغيّره المستخدم.

    /** تاريخ العمل الحالي (Y-m-d). يرجع لتاريخ اليوم لو لم يُحدَّد بعد. */
    public static function workDate(): string
    {
        $d = $_SESSION['work_date'] ?? null;
        if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return $d;
        }
        return date('Y-m-d');
    }

    /** يضبط تاريخ العمل (لا يقبل إلا صيغة Y-m-d صحيحة). */
    public static function setWorkDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
        $_SESSION['work_date'] = $date;
        return true;
    }

    /** هل تاريخ العمل هو تاريخ اليوم فعلاً؟ (لإظهار تحذير البانر عند الاختلاف) */
    public static function isWorkDateToday(): bool
    {
        return self::workDate() === date('Y-m-d');
    }

    /** توكن CSRF */
    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return !empty($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }
}
