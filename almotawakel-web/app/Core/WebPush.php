<?php
namespace App\Core;

/**
 * إرسال إشعارات الدفع للمتصفح (Web Push) — **مكتوبة من الصفر بلا أي تبعيات**.
 *
 * المشروع مالوش composer، والمكتبات الجاهزة كلها بتتطلبه — فالتشفير هنا متنفّذ
 * بـopenssl الموجود في PHP نفسه. اتأكدنا إن كل الدوال المطلوبة متاحة على
 * الاستضافة فعلاً (`openssl_pkey_derive` · `hash_hkdf` · `curl_multi_*`).
 *
 * المعايير المتّبعة (لو حبيت تراجع أي سطر):
 *   • VAPID (RFC 8292) — توقيع ES256 بيثبت للمتصفح إن الإشعار من سيرفرنا.
 *   • aes128gcm (RFC 8188 + RFC 8291) — تشفير المحتوى **من طرف لطرف**:
 *     جوجل/آبل بيوصّلوا الرسالة وهُمّ **مش قادرين يقروها**، لأن المفتاح مشتق
 *     من مفتاح الجهاز نفسه. ده مهم عندنا لأن نص الإشعار فيه أسماء عملاء ومبالغ.
 *
 * ⚠️ **الإرسال ما بيوقفش المستخدم أبدًا.** `PushNotify` بينادي الإرسال في
 *    `register_shutdown_function` بعد ما الصفحة تكون اتبعتت، ومهلة كل طلب
 *    محدودة — فأسوأ حالة إن الإشعار ما يتبعتش، مش إن الحفظ يتعطّل.
 *
 * ⚠️ **مفاتيح VAPID بتتولّد مرّة واحدة** (هجرة v51) وبتتخزّن في `settings`.
 *    **لو اتغيّرت، كل الاشتراكات القديمة بتبطل** ولازم كل واحد يفعّل الإشعارات
 *    من تاني — فما تولّدهاش تاني إلا لو مضطر.
 */
class WebPush
{
    /** مدة حفظ الإشعار عند خدمة الدفع لو الجهاز مقفول (يوم). */
    public const TTL = 86400;

    /** أقصى حجم للحمولة المشفّرة — أصغر من حد المعايير (4096) بهامش أمان. */
    private const MAX_PAYLOAD = 3000;

    /** ترويسة مفتاح عام EC prime256v1 بصيغة DER — ثابتة لكل مفاتيح المنحنى ده. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** هل الإرسال ممكن أصلاً على السيرفر ده؟ (دوال + مفاتيح) */
    public static function available(): bool
    {
        foreach (['openssl_pkey_new', 'openssl_pkey_derive', 'openssl_sign', 'hash_hkdf', 'curl_multi_init'] as $fn) {
            if (!function_exists($fn)) { return false; }
        }
        return self::publicKey() !== '' && self::privateKey() !== '';
    }

    public static function publicKey(): string
    {
        return (string) App::setting('push_vapid_public', '');
    }

    private static function privateKey(): string
    {
        return (string) App::setting('push_vapid_private', '');
    }

    /** بريد التواصل اللي بيتبعت لخدمة الدفع (شرط في المعيار). */
    private static function subject(): string
    {
        $mail = trim((string) App::setting('company_email', ''));
        return $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)
            ? 'mailto:' . $mail
            : 'mailto:info@almutawakkil.com';
    }

    /**
     * يولّد زوج مفاتيح VAPID جديد.
     * @return array{public:string,private:string} العام base64url خام، والخاص PEM
     */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) { throw new \RuntimeException('تعذّر توليد مفتاح الإشعارات — openssl مش مظبوط على السيرفر.'); }
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key);
        return [
            'public'  => self::b64url("\x04" . self::pad32($d['ec']['x']) . self::pad32($d['ec']['y'])),
            'private' => (string) $pem,
        ];
    }

    /**
     * يبعت إشعارًا لمجموعة اشتراكات.
     *
     * @param array<int,array{endpoint:string,p256dh:string,auth:string}> $subs
     * @param array<string,mixed> $payload المحتوى اللي الـService Worker هيقراه
     * @return array<int,array{ok:bool,status:int,gone:bool,error:string}> بنفس ترتيب المدخل
     */
    public static function send(array $subs, array $payload, int $timeout = 10): array
    {
        $out = [];
        if (!$subs || !self::available()) {
            foreach ($subs as $i => $_) { $out[$i] = self::result(false, 0, false, 'الإشعارات مش مفعّلة على السيرفر'); }
            return $out;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_PAYLOAD) {
            foreach ($subs as $i => $_) { $out[$i] = self::result(false, 0, false, 'محتوى الإشعار كبير أوي'); }
            return $out;
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($subs as $i => $sub) {
            try {
                $handles[$i] = self::prepare($sub, $json, $timeout);
                curl_multi_add_handle($multi, $handles[$i]);
            } catch (\Throwable $e) {
                $out[$i] = self::result(false, 0, false, $e->getMessage());
            }
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) { curl_multi_select($multi, 0.5); }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $i => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            // 404/410 = الاشتراك مات (التطبيق اتشال أو الإذن اتسحب) → نوقفه عندنا
            $out[$i] = self::result($code >= 200 && $code < 300, $code, $code === 404 || $code === 410, $err);
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);
        ksort($out);
        return $out;
    }

    // ───────────────────────────────────────────────────────── التفاصيل

    /** @param array{endpoint:string,p256dh:string,auth:string} $sub */
    private static function prepare(array $sub, string $json, int $timeout)
    {
        $endpoint = (string) $sub['endpoint'];
        $origin = self::origin($endpoint);
        if ($origin === '') { throw new \RuntimeException('عنوان اشتراك غير صالح'); }

        [$body, $asPublic] = self::encrypt($json, (string) $sub['p256dh'], (string) $sub['auth']);
        unset($asPublic);                                   // داخل الجسم بالفعل

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Authorization: vapid t=' . self::jwt($origin) . ', k=' . self::publicKey(),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: ' . self::TTL,
                'Urgency: normal',
            ],
        ]);
        return $ch;
    }

    /** توقيع VAPID: JWT بـES256 صالح 12 ساعة للنطاق المطلوب. */
    private static function jwt(string $audience): string
    {
        $header  = self::b64url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64url((string) json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => self::subject(),
        ], JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private(self::privateKey());
        if ($key === false) { throw new \RuntimeException('مفتاح الإشعارات الخاص مكسور'); }
        if (!openssl_sign($header . '.' . $payload, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('تعذّر توقيع الإشعار');
        }
        return $header . '.' . $payload . '.' . self::b64url(self::derToRaw((string) $der));
    }

    /**
     * تشفير الحمولة بـaes128gcm (RFC 8291).
     * @return array{0:string,1:string} [الجسم الكامل، المفتاح العام المؤقّت]
     */
    private static function encrypt(string $json, string $p256dhB64, string $authB64): array
    {
        $uaPublic = self::b64urlDecode($p256dhB64);
        $authSecret = self::b64urlDecode($authB64);
        if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) {
            throw new \RuntimeException('مفاتيح الجهاز غير صالحة');
        }

        // مفتاح مؤقّت لكل رسالة — ده اللي بيخلّي كل إشعار مشفّر بمفتاح لوحده
        $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($eph === false) { throw new \RuntimeException('تعذّر توليد مفتاح مؤقّت'); }
        $ed = openssl_pkey_get_details($eph);
        $asPublic = "\x04" . self::pad32($ed['ec']['x']) . self::pad32($ed['ec']['y']);

        $shared = openssl_pkey_derive(self::pemFromRaw($uaPublic), $eph, 32);
        if ($shared === false) { throw new \RuntimeException('تعذّر اشتقاق مفتاح مشترك'); }

        // ⚠️ ترتيب `uaPublic` قبل `asPublic` في info إلزامي — عكسه بيدّي مفتاحًا
        //    صحيحًا شكلاً وبيفشل فك التشفير على الجهاز بلا أي رسالة خطأ مفيدة.
        $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret);
        $salt  = random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // 0x02 = علامة «آخر سجل» في المعيار (بتتحط في آخر النص قبل التشفير)
        $cipher = openssl_encrypt($json . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) { throw new \RuntimeException('تعذّر تشفير الإشعار'); }

        $head = $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic;
        return [$head . $cipher . $tag, $asPublic];
    }

    /** يحوّل نقطة عامة خام (65 بايت) لمفتاح openssl. */
    private static function pemFromRaw(string $raw)
    {
        $der = (string) hex2bin(self::SPKI_PREFIX) . $raw;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) { throw new \RuntimeException('مفتاح الجهاز العام مكسور'); }
        return $key;
    }

    /**
     * توقيع openssl بيطلع بصيغة DER (متغيّر الطول)، والمعيار عايزه 64 بايت خام.
     * ⚠️ لازم كل رقم يتحشى لـ32 بايت بالظبط — التوقيع اللي طوله 63 بيترفض
     *    من خدمة الدفع برسالة عامة مالهاش أي دلالة.
     */
    private static function derToRaw(string $der): string
    {
        $pos = 0;
        $read = static function (string $s, int &$p): string {
            if (($s[$p] ?? '') !== "\x02") { throw new \RuntimeException('توقيع غير متوقّع'); }
            $p++;
            $len = ord($s[$p++]);
            $val = substr($s, $p, $len);
            $p += $len;
            return ltrim($val, "\x00");
        };
        if (($der[$pos] ?? '') !== "\x30") { throw new \RuntimeException('توقيع غير متوقّع'); }
        $pos++;
        $pos++;                                             // طول التسلسل — مش محتاجينه
        $r = $read($der, $pos);
        $s = $read($der, $pos);
        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) { return ''; }
        return 'https://' . $p['host'];
    }

    private static function pad32(string $v): string
    {
        return str_pad($v, 32, "\x00", STR_PAD_LEFT);
    }

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $v): string
    {
        $v = strtr(trim($v), '-_', '+/');
        return (string) base64_decode(str_pad($v, (int) (ceil(strlen($v) / 4) * 4), '='), true);
    }

    /** @return array{ok:bool,status:int,gone:bool,error:string} */
    private static function result(bool $ok, int $status, bool $gone, string $error): array
    {
        return ['ok' => $ok, 'status' => $status, 'gone' => $gone, 'error' => $error];
    }
}
