<?php
namespace App\Models;

use App\Core\Database;

/**
 * أجهزة مشتركة في الإشعارات — جهاز واحد = صف.
 *
 * الاشتراك بيتعمل من المتصفح بعد ما صاحب الجهاز يدوس «فعّل الإشعارات» بنفسه.
 * **مفيش طريقة تشترك لحد غصب عنه** — ده قرار المتصفحات نفسها مش نقص عندنا.
 *
 * ⚠️ **مفيش أي عمود مالي هنا** ولا استعلام بيلمس خزنة/عهدة/تقفيل — الوحدة
 *    وصفية بحتة زي `dashboard_notes` و`shipment_tracks`.
 */
class PushSubscription
{
    /** لو الترحيل لسه ما اتشغّلش، كل حاجة بترجّع فاضي بدل ما النظام يقع. */
    public static function available(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'push_subscriptions'");
        }
        return $has;
    }

    /**
     * يسجّل جهازًا (أو يحدّث تسجيله لو رجع بنفس العنوان).
     * @param array{endpoint:string,p256dh:string,auth:string} $sub
     */
    public static function save(array $sub, string $audience, ?string $userName, ?int $repId, string $ua): int
    {
        if (!self::available()) { return 0; }
        $endpoint = trim($sub['endpoint']);
        if ($endpoint === '' || !preg_match('#^https://#', $endpoint)) {
            throw new \RuntimeException('عنوان الاشتراك غير صالح.');
        }
        if (strlen($sub['p256dh']) < 16 || strlen($sub['auth']) < 8) {
            throw new \RuntimeException('مفاتيح الجهاز ناقصة.');
        }
        $audience = $audience === 'rep' ? 'rep' : 'office';

        // ⚠️ الفهرس على هاش العنوان مش على العنوان نفسه — عناوين الدفع طويلة
        //    (بتوصل 500+ حرف) وMySQL مش بتقبل مفتاح فريد على TEXT كامل.
        Database::exec(
            "INSERT INTO push_subscriptions
                (audience, user_name, rep_id, endpoint, endpoint_hash, p256dh, auth_secret, user_agent, is_active)
             VALUES (?,?,?,?,?,?,?,?,1)
             ON DUPLICATE KEY UPDATE
                audience=VALUES(audience), user_name=VALUES(user_name), rep_id=VALUES(rep_id),
                p256dh=VALUES(p256dh), auth_secret=VALUES(auth_secret), user_agent=VALUES(user_agent),
                is_active=1, fail_count=0",
            [$audience, $userName, $repId, $endpoint, hash('sha256', $endpoint),
             $sub['p256dh'], $sub['auth'], mb_substr($ua, 0, 255)]
        );
        return (int) Database::scalar(
            "SELECT id FROM push_subscriptions WHERE endpoint_hash=?", [hash('sha256', $endpoint)]);
    }

    /** إلغاء اشتراك جهاز (المستخدم قفل الإشعارات من التطبيق). */
    public static function remove(string $endpoint): void
    {
        if (!self::available()) { return; }
        Database::exec("DELETE FROM push_subscriptions WHERE endpoint_hash=?", [hash('sha256', trim($endpoint))]);
    }

    /**
     * أجهزة جمهور معيّن.
     * `office` = كل أجهزة المكتب · `rep` = كل المناديب · `rep:{id}` = مندوب واحد
     * · `all` = الكل.
     * @return array<int,array<string,mixed>>
     */
    public static function forAudience(string $audience): array
    {
        if (!self::available()) { return []; }
        $sql = "SELECT id, endpoint, p256dh, auth_secret AS auth, audience, user_name, rep_id
                  FROM push_subscriptions WHERE is_active=1";
        $args = [];
        if ($audience === 'office' || $audience === 'rep') {
            $sql .= " AND audience=?";
            $args[] = $audience;
        } elseif (str_starts_with($audience, 'rep:')) {
            $sql .= " AND audience='rep' AND rep_id=?";
            $args[] = (int) substr($audience, 4);
        }
        return Database::all($sql, $args);
    }

    /**
     * أجهزة **شخص واحد** — أساس التوصيل لكل مستلم على حِدة (v52).
     * `user` = اسم مستخدم المكتب · `rep` = رقم المندوب.
     */
    public static function forParty(string $partyType, string $partyKey): array
    {
        if (!self::available() || $partyKey === '') { return []; }
        if ($partyType === 'rep') {
            return Database::all(
                "SELECT id, endpoint, p256dh, auth_secret AS auth, audience, user_name, rep_id
                   FROM push_subscriptions
                  WHERE is_active = 1 AND audience = 'rep' AND rep_id = ?", [(int) $partyKey]);
        }
        return Database::all(
            "SELECT id, endpoint, p256dh, auth_secret AS auth, audience, user_name, rep_id
               FROM push_subscriptions
              WHERE is_active = 1 AND audience = 'office' AND user_name = ?", [$partyKey]);
    }

    /** عدد الأجهزة المفعّلة لكل جمهور — للعرض في شاشة الإشعارات. */
    public static function counts(): array
    {
        if (!self::available()) { return ['office' => 0, 'rep' => 0]; }
        $rows = Database::all(
            "SELECT audience, COUNT(*) n FROM push_subscriptions WHERE is_active=1 GROUP BY audience");
        $out = ['office' => 0, 'rep' => 0];
        foreach ($rows as $r) { $out[$r['audience']] = (int) $r['n']; }
        return $out;
    }

    /** كل الأجهزة مع صاحبها — لشاشة الإدارة. */
    public static function listing(): array
    {
        if (!self::available()) { return []; }
        return Database::all(
            "SELECT s.*, r.name AS rep_name
               FROM push_subscriptions s
               LEFT JOIN reps r ON r.id = s.rep_id
              ORDER BY s.is_active DESC, s.created_at DESC");
    }

    /** هل الجهاز ده مشترك بالفعل؟ (الواجهة بتسأل عشان تعرف شكل الزرار) */
    public static function isSubscribed(string $endpoint): bool
    {
        if (!self::available() || $endpoint === '') { return false; }
        return (bool) Database::scalar(
            "SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash=? AND is_active=1",
            [hash('sha256', trim($endpoint))]);
    }

    /**
     * تسجيل نتيجة الإرسال: النجاح بيصفّر العدّاد، و404/410 بيوقف الاشتراك فورًا
     * (التطبيق اتشال من الجهاز)، والفشل المتكرّر ٥ مرات بيوقفه كمان.
     */
    public static function recordResult(int $id, bool $ok, bool $gone): void
    {
        if (!self::available()) { return; }
        if ($gone) {
            Database::exec("DELETE FROM push_subscriptions WHERE id=?", [$id]);
            return;
        }
        if ($ok) {
            Database::exec("UPDATE push_subscriptions SET fail_count=0, last_ok_at=NOW() WHERE id=?", [$id]);
            return;
        }
        Database::exec(
            "UPDATE push_subscriptions
                SET fail_count = fail_count + 1,
                    is_active = IF(fail_count + 1 >= 5, 0, is_active)
              WHERE id=?", [$id]);
    }
}
