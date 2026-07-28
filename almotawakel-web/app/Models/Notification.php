<?php
namespace App\Models;

use App\Core\Access;
use App\Core\Auth;
use App\Core\Database;

/**
 * مركز الإشعارات — **مجمِّع قراءة فقط**.
 *
 * ⚠️ **مفيش جدول جديد ولا هجرة ولا عمود «آخر مشاهدة»** — نفس فلسفة العزل
 * المتّبعة في `dashboard_notes` و`legacy_bawalis` و`RepWatch`. كل الإشارات
 * محسوبة بالفعل في النظام، والوحدة دي بتجمّعها وبس.
 *
 * ⚠️ **ممنوع أي كتابة هنا نهائيًا** — لا INSERT ولا UPDATE ولا DELETE.
 * `bin/verify_ux.php` بيفشل لو أي استعلام كتابة اتكتب في الملف ده.
 *
 * ⚠️ **مفيش تعريف تاني لأي رقم** — بننادي نفس دوال المصدر اللي بتنادّيها
 * الشاشات (`RepInbox::pendingCount`، `Closing::lastClosedDate`، …) فمستحيل
 * الجرس يقول رقم والصفحة تقول رقم تاني.
 *
 * ⚠️ **الصلاحيات:** كل بند له `role` بيتفحص بـ`Access::allows()` قبل ما يتضاف،
 * فما يوصلش لمستخدم إشعار عن صفحة ممنوع يفتحها أصلاً.
 *
 * **الأداء:** الجرس **ما بيحسبش حاجة وقت رندر الصفحة** — الواجهة بتنادي
 * `/notifications/ping` بعد التحميل. والنتيجة متكاشة في الجلسة لمدة
 * `CACHE_SECS` عشان التبويبات المفتوحة ما تعملش ضغط على القاعدة.
 */
class Notification
{
    /** مدة كاش الجلسة بالثواني — بتمنع كل تبويب مفتوح من ضرب القاعدة كل 60ث. */
    private const CACHE_SECS = 30;

    /** أقصى عدد أيام مفتوحة نبلّغ عنها قبل ما نعتبرها «متراكمة». */
    private const CLOSING_ALERT_DAYS = 1;

    /**
     * كل الإشعارات المتاحة للمستخدم الحالي.
     * @return array{count:int, items:array<int,array<string,mixed>>, at:string}
     */
    public static function feed(bool $fresh = false): array
    {
        $key = 'nc_cache_' . Auth::role();
        if (!$fresh && isset($_SESSION[$key]['at_ts']) && (time() - (int) $_SESSION[$key]['at_ts']) < self::CACHE_SECS) {
            return $_SESSION[$key]['data'];
        }

        $items = [];
        foreach (self::sources() as $source) {
            try {
                $item = $source();
            } catch (\Throwable $e) {
                // إشعار واحد بايظ ما يقفلش الجرس كله ولا يكسر أي صفحة
                continue;
            }
            if ($item !== null && self::maySee($item)) {
                unset($item['handler']);
                $items[] = $item;
            }
        }

        // الأهم فوق: خطأ ← تحذير ← معلومة، وبعدين بالعدد
        $rank = ['err' => 0, 'warn' => 1, 'info' => 2];
        usort($items, static function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a['kind']] ?? 3;
            $rb = $rank[$b['kind']] ?? 3;
            return $ra === $rb ? ($b['count'] <=> $a['count']) : ($ra <=> $rb);
        });

        $data = ['count' => count($items), 'items' => $items, 'at' => date('H:i')];
        $_SESSION[$key] = ['at_ts' => time(), 'data' => $data];
        return $data;
    }

    /** المستخدم مسموح له يشوف الصفحة اللي الإشعار بيوصّل لها؟ */
    private static function maySee(array $item): bool
    {
        $handler = (string) ($item['handler'] ?? '');
        if ($handler === '') { return true; }
        return Access::allows(Auth::role(), $handler, 'GET');
    }

    /** @return array<int,callable():?array> */
    private static function sources(): array
    {
        return [
            [self::class, 'repPending'],
            [self::class, 'unclosedDays'],
            [self::class, 'readyToInvoice'],
            [self::class, 'receiptsMissing'],
            [self::class, 'staleTrackLinks'],
            [self::class, 'weakRepPins'],
            [self::class, 'failedLogins'],
            [self::class, 'dataQuality'],
        ];
    }

    /**
     * البند الواحد.
     * `stamp` هو أساس حالة «مقروء» في المتصفح: بيتغيّر لما الموقف يتغيّر،
     * فالإشعار اللي المستخدم شافه بـ7 بنود بيرجع «جديد» لما يبقوا 8.
     */
    private static function item(
        string $key, string $kind, string $icon, string $title, string $body,
        string $url, int $count, string $handler
    ): array {
        return [
            'key'     => $key,
            'kind'    => $kind,
            'icon'    => $icon,
            'title'   => $title,
            'body'    => $body,
            'url'     => url($url),
            'count'   => $count,
            'stamp'   => $key . ':' . $count,
            'handler' => $handler,
        ];
    }

    /* ═══════════════════ المصادر ═══════════════════ */

    /**
     * مسميات مكرَّرة محتاجة تنظيف — **حالة مستمرة** مش حدث (مش بترنّ ولا بتدخل السجل).
     * ⚠️ بيقرا `DataQuality::badgeCount()` **المكاش** لا الحساب الكامل: الحساب
     *    ١٤٠ مللي ثانية والجرس بينادي كل ٦٠ ثانية لكل مستخدم.
     */
    private static function dataQuality(): ?array
    {
        $n = DataQuality::badgeCount();
        if ($n < 1) { return null; }
        return self::item(
            'data_quality', 'info', 'clipboard',
            intf($n) . ' مجموعة مسميات مكرَّرة',
            'نفس الشيء متسجّل بأكتر من تهجئة — وحّدهم عشان التقارير ما تتفتّتش.',
            'quality', $n, 'QualityController@index'
        );
    }

    /** بنود المناديب المستنية المراجعة — `RepInbox::pendingCount()` (COUNT واحد). */
    private static function repPending(): ?array
    {
        if (!RepCustody::available()) { return null; }
        $n = RepInbox::pendingCount();
        if ($n < 1) { return null; }
        return self::item(
            'rep_pending', 'warn', 'inbox',
            intf($n) . ' بند من المناديب مستنّي المراجعة',
            'افتح صندوق المراجعة عشان تعتمدهم أو تعدّلهم.',
            'rep-custody/inbox', $n, 'RepCustodyController@inbox'
        );
    }

    /**
     * أيام لسه مش متقفلة — حساب تواريخ بحت من `Closing::lastClosedDate()`
     * (استعلام scalar واحد)، بلا أي مسح للحركات.
     */
    private static function unclosedDays(): ?array
    {
        $last = Closing::lastClosedDate();
        if ($last === null) { return null; }              // الوحدة مش مفعّلة أو مفيش تقفيل بعد
        $today = Auth::workDate();
        $days  = (int) floor((strtotime($today) - strtotime($last)) / 86400) - 1;
        if ($days < self::CLOSING_ALERT_DAYS) { return null; }
        return self::item(
            'closing_open', $days >= 3 ? 'err' : 'warn', 'calculator',
            intf($days) . ' يوم لسه مش متقفل',
            'آخر يوم متقفل ' . dfmt($last) . ' — الإقفال تسلسلي من الأقدم.',
            'closing', $days, 'DailyClosingController@index'
        );
    }

    /** أذونات مُغلقة بلا فاتورة — `Metrics::readyToInvoiceSummary()` (تجميعة واحدة). */
    private static function readyToInvoice(): ?array
    {
        $s = Metrics::readyToInvoiceSummary();
        $n = (int) ($s['count'] ?? 0);
        if ($n < 1) { return null; }
        return self::item(
            'ready_invoice', 'info', 'receipt',
            intf($n) . ' إذن جاهز للفوترة',
            'أذونات مقفولة ولسه ما اتصدرش لها فاتورة.',
            'invoices', $n, 'InvoiceController@index'
        );
    }

    /** إيصالات يوم العمل الناقصة الفعلي/رقم الإيصال — `Permit::receiptsMissingData()`. */
    private static function receiptsMissing(): ?array
    {
        $day  = Auth::workDate();
        $rows = Permit::receiptsMissingData($day);
        $n    = count($rows);
        if ($n < 1) { return null; }
        return self::item(
            'receipts_missing', 'warn', 'clipboard',
            intf($n) . ' إيصال ناقص بياناته',
            'الفعلي للعميل أو رقم الإيصال ناقص في ' . dfmt($day) . '.',
            'expenses/receipts', $n, 'ExpenseController@receiptsComplete'
        );
    }

    /**
     * روابط تتبع اتولّدت على عنوان نفق قديم — السائق هياخد خطأ 1033.
     * استعلام خفيف مخصوص (مش `Track::listing` اللي فيه joins وsubqueries).
     */
    private static function staleTrackLinks(): ?array
    {
        if (!self::hasTable('shipment_tracks')) { return null; }
        $now = rtrim((string) public_base_url(), '/');
        if ($now === '') { return null; }                 // النفق مش شغّال — مش وقت التبليغ
        $n = (int) Database::scalar(
            "SELECT COUNT(*) FROM shipment_tracks
              WHERE status = 'active' AND base_url <> '' AND base_url IS NOT NULL
                AND TRIM(TRAILING '/' FROM base_url) <> ?",
            [$now]
        );
        if ($n < 1) { return null; }
        return self::item(
            'track_stale', 'warn', 'map-pin',
            intf($n) . ' رابط تتبع على عنوان قديم',
            'السواقين هيشوفوا خطأ — جدّد الروابط من صفحة التتبع.',
            'tracking', $n, 'TrackController@index'
        );
    }

    /** مناديب برقم سري ضعيف (من v48) — للمالك والمدير بس. */
    private static function weakRepPins(): ?array
    {
        if (!in_array(Auth::role(), ['owner', 'manager'], true)) { return null; }
        if (!self::hasColumn('rep_users', 'weak_pin')) { return null; }
        $n = (int) Database::scalar(
            "SELECT COUNT(*) FROM rep_users ru
               JOIN reps r ON r.id = ru.rep_id
              WHERE ru.weak_pin = 1 AND ru.status = 'active' AND r.status = 'active'"
        );
        if ($n < 1) { return null; }
        return self::item(
            'weak_pins', 'warn', 'alert',
            intf($n) . ' مندوب برقم سري ضعيف',
            'جدّد أرقامهم السرية من كارت كل مندوب.',
            'rep-custody', $n, 'RepCustodyController@index'
        );
    }

    /** محاولات دخول فاشلة في آخر 24 ساعة — **للمالك بس** (زي لوحة /security). */
    private static function failedLogins(): ?array
    {
        if (Auth::role() !== 'owner') { return null; }
        if (!self::hasTable('auth_login_attempts')) { return null; }
        // ⚠️ المقارنة الزمنية جوّه SQL (قاعدة v48) — ساعة القاعدة مختلفة عن ساعة PHP
        $n = (int) Database::scalar(
            "SELECT COUNT(*) FROM auth_login_attempts
              WHERE was_success = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        if ($n < 10) { return null; }                     // الغلطة العادية مش حدث أمني
        return self::item(
            'failed_logins', $n >= 50 ? 'err' : 'warn', 'alert',
            intf($n) . ' محاولة دخول فاشلة خلال 24 ساعة',
            'راجع لوحة المراقبة الأمنية.',
            'security', $n, 'SecurityController@index'
        );
    }

    /* ═══════════════════ مساعدات ═══════════════════ */

    private static function hasTable(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) { return $cache[$table]; }
        return $cache[$table] = (int) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        ) === 1;
    }

    private static function hasColumn(string $table, string $col): bool
    {
        static $cache = [];
        $k = $table . '.' . $col;
        if (isset($cache[$k])) { return $cache[$k]; }
        return $cache[$k] = (int) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $col]
        ) === 1;
    }
}
