<?php
namespace App\Models;

use App\Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * تفضيلات الإشعارات — نوع × قناة لكل شخص (v53، المرحلة ٤ من docs/15).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * كل واحد بيختار كل نوع إشعار يوصله على أنهي قناة:
 *   🔔 **الجرس** (`in_app`) — يظهر في جرسه وصفحة سجله
 *   📱 **الموبايل** (`push`) — يرنّ على تطبيقه
 *
 * ⚠️ **الغياب = مفعّل.** مفيش صف معناه إن النوع شغّال على القناتين. يعني:
 *    الجدول بيفضل فاضي لحد ما حد يغيّر بنفسه، ومستخدم جديد بياخد كل حاجة
 *    تلقائيًّا بلا ما نزرع له صفوف. **ما تقلبش الافتراضي ده** — قفل الكل
 *    افتراضيًّا معناه إشعارات مش بتوصل ومحدش عارف ليه.
 *
 * ⚠️ **التفضيل بيتطبّق وقت تسجيل الحدث مش وقت العرض.** لو اتطبّق وقت العرض،
 *    تغيير التفضيل كان هيخفي أحداثًا **قديمة** من السجل بأثر رجعي، والسجل
 *    المفروض يفضل شاهدًا على اللي حصل. فالتفضيل بيمسّ الجديد بس.
 *
 * ⚠️ **مش بديل عن الصلاحيات.** `Notify::recipientsFor()` بتفلتر بـ`Access`
 *    الأول (الرفض هو الافتراضي)، والتفضيل بيجي **بعدها**: مستحيل حد يفتح
 *    لنفسه إشعارًا عن صفحة ممنوعة بمجرد إنه يعلّم خانة.
 *
 * ⛔ **مفيش «ساعات هدوء»** — اتشالت بقرار المالك (docs/15 §٧.٤).
 *
 * ⚠️ **وصفي بحت — صفر أثر مالي.**
 */
class NotifyPref
{
    /** كاش داخل الطلب: الحدث الواحد بيسأل عن ١٢ مستلم فما نضربش القاعدة ١٢ مرة. */
    private static array $cache = [];

    public static function available(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'notification_prefs'");
        }
        return $has;
    }

    /** بيتنادى بعد أي كتابة عشان الطلب ما يفضلش شايف القديم. */
    public static function clearCache(): void { self::$cache = []; }

    /**
     * تفضيلات شخص كخريطة `kind => ['in_app'=>bool,'push'=>bool]`.
     * **الأنواع اللي مالهاش صف مش موجودة في الخريطة** — الغياب = مفعّل.
     */
    public static function forParty(string $partyType, string $partyKey): array
    {
        if (!self::available() || $partyKey === '') { return []; }
        $ck = $partyType . '|' . $partyKey;
        if (isset(self::$cache[$ck])) { return self::$cache[$ck]; }

        $out = [];
        foreach (Database::all(
            "SELECT kind, in_app, push FROM notification_prefs
              WHERE party_type = ? AND party_key = ?", [$partyType, $partyKey]) as $r) {
            $out[(string) $r['kind']] = [
                'in_app' => (int) $r['in_app'] === 1,
                'push'   => (int) $r['push'] === 1,
            ];
        }
        return self::$cache[$ck] = $out;
    }

    /**
     * الشخص ده عايز النوع ده على القناة دي؟
     * @param string $channel `in_app` أو `push`
     */
    public static function allows(string $partyType, string $partyKey, string $kind, string $channel): bool
    {
        $p = self::forParty($partyType, $partyKey);
        if (!isset($p[$kind])) { return true; }            // الغياب = مفعّل
        return (bool) ($p[$kind][$channel] ?? true);
    }

    /**
     * الخريطة الكاملة للعرض في شاشة الإعدادات — كل نوع معروف + حالته.
     * @return array<int,array{kind:string,label:string,icon:string,in_app:bool,push:bool}>
     */
    public static function matrix(string $partyType, string $partyKey, array $kinds): array
    {
        $saved = self::forParty($partyType, $partyKey);
        $out = [];
        foreach ($kinds as $kind) {
            $meta = Notify::kindMeta($kind);
            $out[] = [
                'kind'   => $kind,
                'label'  => $meta['label'],
                'icon'   => $meta['icon'],
                'in_app' => $saved[$kind]['in_app'] ?? true,
                'push'   => $saved[$kind]['push'] ?? true,
            ];
        }
        return $out;
    }

    /**
     * حفظ تفضيلات شخص.
     *
     * ⚠️ **بنمسح صف النوع اللي رجع للافتراضي** بدل ما نخزّن `1,1` — فالجدول
     *    يفضل صغيرًا ومعناه واضح: «فيه صف يعني حد غيّر حاجة».
     *
     * ⚠️ **الأنواع المجهولة بتترفض** — `$kinds` هي القائمة البيضا، والفورم
     *    مش مصدر ثقة.
     *
     * @param array<string,array{in_app:bool,push:bool}> $wanted
     * @param array<int,string> $allowedKinds قائمة بيضا
     * @return int عدد الأنواع اللي اتغيّرت عن الافتراضي
     */
    public static function save(string $partyType, string $partyKey, array $wanted, array $allowedKinds): int
    {
        if (!self::available() || $partyKey === '') { return 0; }
        $kept = 0;
        foreach ($allowedKinds as $kind) {
            $inApp = !empty($wanted[$kind]['in_app']);
            $push  = !empty($wanted[$kind]['push']);
            // ⚠️ الموبايل بلا جرس مالوش معنى: البند هيرنّ ومش هتلاقيه في النظام.
            //    فبنخلّي الجرس هو الأساس ونقفل الموبايل معاه.
            if (!$inApp) { $push = false; }

            if ($inApp && $push) {
                Database::exec(
                    "DELETE FROM notification_prefs
                      WHERE party_type = ? AND party_key = ? AND kind = ?",
                    [$partyType, $partyKey, $kind]);
                continue;
            }
            Database::exec(
                "INSERT INTO notification_prefs (party_type, party_key, kind, in_app, push)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE in_app = VALUES(in_app), push = VALUES(push)",
                [$partyType, $partyKey, $kind, $inApp ? 1 : 0, $push ? 1 : 0]);
            $kept++;
        }
        self::clearCache();
        return $kept;
    }

    /** رجوع الكل للافتراضي (كل حاجة شغّالة). */
    public static function reset(string $partyType, string $partyKey): int
    {
        if (!self::available() || $partyKey === '') { return 0; }
        $n = Database::exec(
            "DELETE FROM notification_prefs WHERE party_type = ? AND party_key = ?",
            [$partyType, $partyKey]);
        self::clearCache();
        return $n;
    }
}
