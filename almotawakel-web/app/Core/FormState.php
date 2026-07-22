<?php
namespace App\Core;

/**
 * حالة نماذج PRG معزولة لكل تبويب/محاولة.
 *
 * لا نعتمد حقيبة `_old` عامة؛ كل نموذج يحمل token عشوائيًا، وتُحفظ أخطاؤه وقيمه
 * تحته ثم يُعاد فتحه عبر `_form=<token>`. كما يُستخدم الرمز لمنع تنفيذ الكتابة
 * المالية نفسها مرتين عند تكرار الطلب.
 */
class FormState
{
    private const TTL = 1800;
    private const MAX_BAGS = 24;

    public static function token(?string $candidate = null): string
    {
        $candidate = trim((string) $candidate);
        return preg_match('/^[a-f0-9]{32}$/', $candidate)
            ? $candidate
            : bin2hex(random_bytes(16));
    }

    /** @return array{token:string,old:array,errors:array,back:string} */
    public static function consume(string $formId, string $fallbackBack): array
    {
        self::prune();
        $token = self::token($_GET['_form'] ?? null);
        $bag = $_SESSION['_form_bags'][$token] ?? null;
        if (!is_array($bag) || !hash_equals((string) ($bag['form_id'] ?? ''), $formId)) {
            // الصفحة قد تحتوي عدة نماذج وتستدعي consume لكل واحد منها. رمز حقيبة
            // الخطأ يخص نموذجًا واحدًا فقط؛ إعادة استخدامه للنماذج الأخرى تجعل أول
            // كتابة ناجحة تستهلك الرمز ثم تُرفض بقية النماذج على أنها طلب مكرر.
            return ['token' => self::token(), 'old' => [], 'errors' => [], 'back' => safe_back($fallbackBack, $_GET['back'] ?? null)];
        }
        unset($_SESSION['_form_bags'][$token]);
        return [
            'token'  => $token,
            'old'    => is_array($bag['old'] ?? null) ? $bag['old'] : [],
            'errors' => is_array($bag['errors'] ?? null) ? $bag['errors'] : [],
            'back'   => safe_back($fallbackBack, $bag['back'] ?? null),
        ];
    }

    public static function store(string $formId, array $old, array $errors, string $fallbackBack): string
    {
        self::prune();
        $token = self::token($_POST['_form_token'] ?? null);
        if (isset($_SESSION['_used_form_tokens'][$token])) { $token = self::token(); }
        $_SESSION['_form_bags'][$token] = [
            'form_id' => $formId,
            'old'     => self::inputOnly($old),
            'errors'  => $errors,
            'back'    => safe_back($fallbackBack, $_POST['_back'] ?? null),
            'at'      => time(),
        ];
        return $token;
    }

    /**
     * يستهلك رمز المحاولة مرة واحدة. يجب استدعاؤه بعد نجاح validation وقبل الكتابة.
     */
    public static function claim(?string $candidate): bool
    {
        self::prune();
        $token = trim((string) $candidate);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) { return false; }
        if (isset($_SESSION['_used_form_tokens'][$token])) { return false; }
        $_SESSION['_used_form_tokens'][$token] = time();
        return true;
    }

    public static function redirectPath(string $path, string $token): string
    {
        $hash = '';
        if (($at = strpos($path, '#')) !== false) {
            $hash = substr($path, $at);
            $path = substr($path, 0, $at);
        }
        return $path . (str_contains($path, '?') ? '&' : '?') . '_form=' . rawurlencode($token) . $hash;
    }

    private static function inputOnly(array $data): array
    {
        unset($data['_csrf'], $data['_form_token'], $data['_back']);
        return $data;
    }

    private static function prune(): void
    {
        $cut = time() - self::TTL;
        foreach (['_form_bags', '_used_form_tokens'] as $key) {
            $rows = $_SESSION[$key] ?? [];
            if (!is_array($rows)) { $rows = []; }
            foreach ($rows as $id => $row) {
                $at = is_array($row) ? (int) ($row['at'] ?? 0) : (int) $row;
                if ($at < $cut) { unset($rows[$id]); }
            }
            if (count($rows) > self::MAX_BAGS) {
                uasort($rows, static function ($a, $b): int {
                    $aa = is_array($a) ? (int) ($a['at'] ?? 0) : (int) $a;
                    $bb = is_array($b) ? (int) ($b['at'] ?? 0) : (int) $b;
                    return $bb <=> $aa;
                });
                $rows = array_slice($rows, 0, self::MAX_BAGS, true);
            }
            $_SESSION[$key] = $rows;
        }
    }
}
