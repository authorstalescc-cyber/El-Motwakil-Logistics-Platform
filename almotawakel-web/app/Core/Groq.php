<?php
namespace App\Core;

/**
 * عميل HTTP بسيط لواجهة Groq (متوافقة مع OpenAI Chat Completions).
 * يُستخدم من المساعد الذكي. كل الأخطاء تُمسك وتُرجَع كنص عربي واضح
 * حتى لا تنكسر الواجهة عند انقطاع الإنترنت أو خطأ في المفتاح.
 */
class Groq
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    /**
     * الإعدادات الفعّالة لـ Groq.
     * الأولوية لإعدادات جدول settings (القابلة للتعديل من الواجهة) ثم config.php كاحتياطي.
     * المفتاح النشط = ai_key_1 أو ai_key_2 حسب ai_active.
     */
    public static function config(): array
    {
        $file = App::config('groq');
        $file = is_array($file) ? $file : [];

        try {
            $active = (App::setting('ai_active', '1') === '2') ? '2' : '1';
            $key    = trim((string) App::setting('ai_key_' . $active, ''));
            if ($key === '') { $key = (string) ($file['api_key'] ?? ''); }   // احتياطي: مفتاح الملف

            $model = trim((string) App::setting('ai_model', ''));
            if ($model === '') { $model = (string) ($file['model'] ?? 'llama-3.3-70b-versatile'); }

            $en      = App::setting('ai_enabled', null);
            $enabled = ($en === null) ? !empty($file['enabled']) : ($en === '1');

            return ['enabled' => $enabled, 'api_key' => $key, 'model' => $model, 'timeout' => $file['timeout'] ?? 40];
        } catch (\Throwable $e) {
            // لو تعذّر قراءة جدول settings لأي سبب — ارجع لإعدادات الملف
            return [
                'enabled' => !empty($file['enabled']),
                'api_key' => (string) ($file['api_key'] ?? ''),
                'model'   => (string) ($file['model'] ?? 'llama-3.3-70b-versatile'),
                'timeout' => $file['timeout'] ?? 40,
            ];
        }
    }

    /** هل المساعد مُفعّل ومفتاحه موجود؟ */
    public static function enabled(): bool
    {
        $c = self::config();
        return !empty($c['enabled']) && !empty($c['api_key']);
    }

    /** الموديل الافتراضي */
    public static function model(): string
    {
        $c = self::config();
        return !empty($c['model']) ? $c['model'] : 'llama-3.3-70b-versatile';
    }

    /**
     * محادثة واحدة مع الموديل.
     * @param array $messages رسائل بصيغة OpenAI: [['role'=>'system|user|assistant','content'=>'...'], ...]
     * @param array $opts     خيارات: model, temperature, max_tokens
     * @return array{ok:bool, content:string, error:?string, usage:array}
     */
    public static function chat(array $messages, array $opts = []): array
    {
        $c = self::config();
        // يسمح بتمرير مفتاح مؤقت (لاختبار مفتاح قبل حفظه) عبر $opts['api_key']
        $apiKey = trim((string) ($opts['api_key'] ?? $c['api_key'] ?? ''));
        if ($apiKey === '') {
            return ['ok' => false, 'content' => '', 'error' => 'مفتاح Groq غير مُعدّ. أضِفه من الإعدادات أو في config.php.', 'usage' => []];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'content' => '', 'error' => 'إضافة cURL غير مفعّلة في PHP على الخادم.', 'usage' => []];
        }

        $payload = [
            'model'       => $opts['model']       ?? self::model(),
            'messages'    => $messages,
            'temperature' => $opts['temperature'] ?? 0.2,
            'max_tokens'  => $opts['max_tokens']  ?? 900,
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => (int)($c['timeout'] ?? 40),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // ملاحظة: curl_close لم يعد ضرورياً منذ PHP 8.0 (المورد يُحرَّر تلقائياً)

        if ($errno) {
            return ['ok' => false, 'content' => '', 'error' => 'تعذّر الاتصال بخدمة الذكاء الاصطناعي: ' . $err, 'usage' => []];
        }

        $data = json_decode((string) $raw, true);
        if ($code !== 200 || !is_array($data)) {
            $msg = $data['error']['message'] ?? ('رمز الخطأ ' . $code);
            return ['ok' => false, 'content' => '', 'error' => 'الخدمة ردّت بخطأ: ' . $msg, 'usage' => []];
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        return ['ok' => true, 'content' => trim($content), 'error' => null, 'usage' => $data['usage'] ?? []];
    }

    /**
     * اختبار سريع لمفتاح + موديل معيّن (قبل الاعتماد عليه) — استدعاء صغير جدًا.
     * @return array{ok:bool, message?:string, error?:string}
     */
    public static function ping(string $apiKey, string $model): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') { return ['ok' => false, 'error' => 'المفتاح فاضي — الصقه الأول.']; }
        $model = trim($model) !== '' ? trim($model) : 'llama-3.3-70b-versatile';

        $res = self::chat(
            [['role' => 'user', 'content' => 'رد بكلمة واحدة: جاهز']],
            ['api_key' => $apiKey, 'model' => $model, 'max_tokens' => 5, 'temperature' => 0]
        );
        return $res['ok']
            ? ['ok' => true,  'message' => 'المفتاح شغّال ✓ (موديل: ' . $model . ')']
            : ['ok' => false, 'error'   => $res['error']];
    }
}
