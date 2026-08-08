<?php
namespace App\Models;

use App\Core\Database;

/**
 * تثبيت الأذونات على شاشة المندوب (v63).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️⚠️ وحدة وصفية معزولة بالبناء — ولا عمود مالي واحد.
 * ═══════════════════════════════════════════════════════════════════════════
 * نفس منطق `dashboard_notes` (v19) و`car_expenses` (v54) و`permit_track_events`
 * (v62): جدول خاص **كل كود الخزنة عمره ما سمع عنه**، فمفيش «استثناء» لازم
 * نفتكره في ٨ أماكن. `bin/verify_v63.php` بيفشل لو أي ملف خزنة ذكر الجدول.
 *
 * ⚠️ **التثبيت تفضيل عرض بحت — مش صلاحية.** ما بيغيّرش مين يشوف أنهي إذن
 *    ولا مين يكتب على إيه: الحدّ الوحيد لسه `permits.status='open'` جوّه
 *    `RepPortal::assertAssigned` (قاعدة v43). إذن مثبَّت واتقفل بيختفي زي
 *    أي إذن مقفول، وكتابته بترفض — التثبيت ما بيفتحش أي باب.
 *
 * ⚠️ **مش زي `permit_assignments`.** التنزيل قرار **مكتب** على مندوب بعينه،
 *    وهو اللي المالك قرّر يشيله من شاشة المندوب (2026-07-28) لأنه كان بيوهم
 *    إن فيه أذونات «بتاعته». التثبيت هنا قرار **المندوب لنفسه** — ما بيظهرش
 *    لحد غيره ولا بيغيّر أي شيء في المكتب.
 */
class RepPin
{
    /** كاش «الجدول موجود؟» داخل الطلب. */
    private static ?bool $ready = null;

    /**
     * الجدول اترحّل؟ — النظام لازم يشتغل عادي قبل migrate_v63 (بيرجع false).
     *
     * ⚠️ الكاش الساكن ده هو **بالظبط** مصيدة v57 الموثَّقة: ملف الترحيل بيقرا
     *    حاجة قبل الـCREATE فبيكاش false، وبعده يفضل false فالتحقّق يفشل
     *    والترحيل ما يتسجّلش. عشان كده `refreshReady()` موجودة و`migrate_v63`
     *    بينادّيها في آخر `up()`.
     */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'rep_permit_pins'");
        }
        return self::$ready;
    }

    /** تصفير الكاش بعد الترحيل داخل نفس الطلب (مصيدة كاش المخطط — v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null;
        return self::ready();
    }

    /** اسم بديل متوافق مع باقي الوحدات (`available()` في `RepCustody`/`Notify`). */
    public static function available(): bool
    {
        return self::ready();
    }

    /**
     * أرقام الأذونات المثبَّتة لمندوب — **الأحدث تثبيتًا أولاً**.
     *
     * @return array<int,int>
     */
    public static function forRep(int $repId): array
    {
        if (!self::ready() || $repId <= 0) { return []; }
        return array_map('intval', array_column(Database::all(
            'SELECT permit_id FROM rep_permit_pins WHERE rep_id = ? ORDER BY pinned_at DESC, permit_id DESC',
            [$repId]), 'permit_id'));
    }

    /** الإذن ده مثبَّت عند المندوب ده؟ */
    public static function has(int $repId, int $permitId): bool
    {
        if (!self::ready() || $repId <= 0 || $permitId <= 0) { return false; }
        return (bool) Database::scalar(
            'SELECT 1 FROM rep_permit_pins WHERE rep_id = ? AND permit_id = ?', [$repId, $permitId]);
    }

    /**
     * تثبيت / فكّ التثبيت — بيرجّع الحالة الجديدة.
     *
     * ⚠️ **الإذن لازم يكون مفتوح** (نفس قاعدة v43): تثبيت إذن مقفول بيدّي
     *    المندوب سطرًا مالوش لازمة في شاشته — والحارس هنا في الموديل لا في
     *    الكنترولر، فأي مسار كتابة جديد بياخد نفس الحماية. **الفورم مش مصدر ثقة.**
     *
     * ⚠️ **مفيش `Closing::guard`** عن قصد — تفضيل عرض ما بيدخلش أي تقفيل ولا
     *    بيمسّ أي رصيد مثبَّت (نفس قرار v39/v54/v62).
     */
    public static function toggle(int $repId, int $permitId): bool
    {
        if (!self::ready()) { return false; }
        if ($repId <= 0 || $permitId <= 0) { return false; }

        $open = Database::scalar("SELECT id FROM permits WHERE id = ? AND status = 'open'", [$permitId]);
        if (!$open) { return false; }

        if (self::has($repId, $permitId)) {
            Database::exec('DELETE FROM rep_permit_pins WHERE rep_id = ? AND permit_id = ?',
                [$repId, $permitId]);
            return false;
        }
        // `INSERT IGNORE` مع المفتاح المركّب = مستحيل يتكرّر حتى لو اتبعت مرتين
        Database::exec('INSERT IGNORE INTO rep_permit_pins (rep_id, permit_id) VALUES (?,?)',
            [$repId, $permitId]);
        return true;
    }
}
