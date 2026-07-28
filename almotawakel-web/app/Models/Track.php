<?php
namespace App\Models;

use App\Core\Database;
use App\Core\App;
use App\Models\Maintenance;

/**
 * تتبع الشحنات باللوكيشن (v33).
 *
 * كل بوليصة (شحنة) لها «رحلة تتبع» برابط عام عشوائي يُبعَت للسائق يدويًا على
 * الواتساب. السائق يفتح الرابط ويضغط «ابعت موقعي» (لقطة) أو يشغّل «التتبع
 * المباشر» فيُرسَل موقعه تلقائيًا كل شوية طول ما الصفحة مفتوحة.
 *
 * ⚠️ معزولة تمامًا: كل بياناتها وصفية (إحداثيات + وقت) — لا مبالغ، ولا تدخل أي
 *    حساب مالي/عهدة/تقفيل يومي/مصروفات، ولا تُقرأ من أي تقرير مالي.
 *    الربط بالبوليصة بالـ id بلا FK (تحذير 3) — حذف أي طرف لا يكسر الآخر.
 */
class Track
{
    /** حالات الرحلة */
    public const STATUSES = [
        'active'    => 'في الطريق',
        'done'      => 'وصلت',
        'cancelled' => 'ملغاة',
    ];

    /** أقصى معدّل استقبال: نقطة كل كام ثانية (حماية الرابط العام من الإغراق) */
    private const MIN_PING_GAP_SECS = 5;

    /** أقصى عدد نقاط للرحلة الواحدة (سقف أمان — الرحلة العادية عشرات النقاط) */
    private const MAX_PINGS = 5000;

    /** أقل مسافة (متر) تستدعي جلب عنوان جديد — نحترم حدود OpenStreetMap المجانية */
    private const GEOCODE_MIN_MOVE_M = 400;

    // ------------------------------------------------------------------------
    //  قراءة
    // ------------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        return Database::row('SELECT * FROM shipment_tracks WHERE id = ?', [$id]);
    }

    public static function findByToken(string $token): ?array
    {
        return Database::row('SELECT * FROM shipment_tracks WHERE token = ?', [$token]);
    }

    /** الرحلة النشطة لبوليصة (لو موجودة) — عشان ما نولّدش رابطين لنفس الشحنة */
    public static function activeForBill(int $billId): ?array
    {
        return Database::row(
            "SELECT * FROM shipment_tracks WHERE bill_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$billId]
        );
    }

    /** كل رحلات بوليصة (للعرض في صفحة البوليصة) */
    public static function forBill(int $billId): array
    {
        return Database::all(
            'SELECT * FROM shipment_tracks WHERE bill_id = ? ORDER BY id DESC',
            [$billId]
        );
    }

    /**
     * قائمة الرحلات مع بيانات الشحنة وآخر موقع — الاستعلام الرئيسي للوحة التتبع.
     * LEFT JOIN على البوليصة: لو البوليصة اتمسحت الرحلة تفضل تظهر بلقطتها.
     */
    public static function listing(string $status = 'active', string $q = ''): array
    {
        $w = [];
        $p = [];
        if (isset(self::STATUSES[$status])) { $w[] = 't.status = ?'; $p[] = $status; }
        if (trim($q) !== '') {
            $like = '%' . trim($q) . '%';
            $w[] = '(t.driver LIKE ? OR t.car_no LIKE ? OR b.bill_number LIKE ? OR b.container_no LIKE ?'
                 . ' OR b.destination LIKE ? OR b.company LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like, $like);
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        return Database::all("
            SELECT t.*,
                   b.bill_number, b.bill_date, b.container_no, b.container_type, b.container_count,
                   b.destination, b.company, b.declaration_owner, b.permit_id,
                   p.lat AS last_lat, p.lng AS last_lng, p.address AS last_address,
                   p.accuracy_m AS last_accuracy, p.created_at AS last_at,
                   (SELECT COALESCE(SUM(dist_m),0) FROM track_pings WHERE track_id = t.id) AS total_m
              FROM shipment_tracks t
              LEFT JOIN bawalis b ON b.id = t.bill_id
              LEFT JOIN track_pings p ON p.id = (
                    SELECT id FROM track_pings WHERE track_id = t.id ORDER BY id DESC LIMIT 1)
              $where
             ORDER BY (t.last_ping_at IS NULL), t.last_ping_at DESC, t.id DESC
             LIMIT 300
        ", $p);
    }

    /** عدّاد كل حالة (لأزرار الفلترة) */
    public static function counts(): array
    {
        $out = ['active' => 0, 'done' => 0, 'cancelled' => 0];
        foreach (Database::all('SELECT status, COUNT(*) c FROM shipment_tracks GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    /** نقاط رحلة بالترتيب الزمني */
    public static function pings(int $trackId, string $order = 'ASC'): array
    {
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        return Database::all(
            "SELECT * FROM track_pings WHERE track_id = ? ORDER BY id $order",
            [$trackId]
        );
    }

    public static function lastPing(int $trackId): ?array
    {
        return Database::row(
            'SELECT * FROM track_pings WHERE track_id = ? ORDER BY id DESC LIMIT 1',
            [$trackId]
        );
    }

    /** بوالص مرشّحة للتتبع: لها سائق، وما لهاش رحلة نشطة، والأحدث أولًا */
    public static function trackableBills(string $q = '', int $limit = 60): array
    {
        $w = ["b.is_legacy = 0",
              "NOT EXISTS (SELECT 1 FROM shipment_tracks t WHERE t.bill_id = b.id AND t.status = 'active')"];
        $p = [];
        if (trim($q) !== '') {
            $like = '%' . trim($q) . '%';
            $w[] = '(b.bill_number LIKE ? OR b.driver LIKE ? OR b.car_no LIKE ? OR b.container_no LIKE ?'
                 . ' OR b.destination LIKE ? OR b.company LIKE ? OR b.declaration_owner LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like, $like, $like);
        }
        return Database::all("
            SELECT b.id, b.bill_number, b.bill_date, b.driver, b.car_no, b.container_no,
                   b.container_type, b.container_count, b.destination, b.company, b.declaration_owner,
                   (SELECT phone FROM driver_contacts dc WHERE dc.driver = b.driver LIMIT 1) AS driver_phone
              FROM bawalis b
             WHERE " . implode(' AND ', $w) . "
             ORDER BY b.bill_date DESC, b.id DESC
             LIMIT " . (int) $limit, $p);
    }

    // ------------------------------------------------------------------------
    //  إنشاء وتحديث
    // ------------------------------------------------------------------------

    /**
     * يولّد رحلة تتبع لبوليصة. لو فيه رحلة نشطة بالفعل يرجّعها بدل ما يعمل رابط تاني
     * (فالسائق يفضل على نفس الرابط ونقاطه ما تتفرّقش على رحلتين).
     */
    public static function createForBill(int $billId, string $phone = '', string $note = '', ?string $by = null): array
    {
        if ($existing = self::activeForBill($billId)) {
            if ($phone !== '') { self::setPhone((int) $existing['id'], $phone, $existing['driver'] ?? ''); }
            return self::find((int) $existing['id']) ?? $existing;
        }

        $bill = Database::row('SELECT * FROM bawalis WHERE id = ?', [$billId]);
        if (!$bill) { throw new \RuntimeException('البوليصة غير موجودة'); }

        $hours = max(1, (int) App::setting('track_expire_hours', 48));
        $phone = self::normPhone($phone);

        Database::exec(
            'INSERT INTO shipment_tracks
                (bill_id, token, driver, driver_phone, car_no, note, expires_at, base_url, created_by)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?, ?)',
            [
                $billId,
                self::newToken(),
                $bill['driver'] ?: null,
                $phone ?: null,
                $bill['car_no'] ?: null,
                trim(mb_substr($note, 0, 255)) ?: null,
                $hours,
                mb_substr(public_base_url(), 0, 200) ?: null,
                $by,
            ]
        );
        $id = Database::lastId();

        if ($phone !== '' && ($bill['driver'] ?? '') !== '') {
            self::rememberPhone((string) $bill['driver'], $phone);
        }

        // حدّد وجهة الشحنة على الخريطة تلقائيًا لو لسه متعرّفش عليها (v34).
        // فشله ما يعطّلش توليد الرابط إطلاقًا — المسافة تحسين مش شرط.
        try { self::learnDestination($bill['destination'] ?? null); } catch (\Throwable $e) { /* تجاهل */ }

        return self::find($id) ?? [];
    }

    /** توكن عشوائي فريد — قصير عشان الرابط يبقى مقروء في الواتساب */
    private static function newToken(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $t = substr(bin2hex(random_bytes(8)), 0, 14);
            if (!Database::scalar('SELECT COUNT(*) FROM shipment_tracks WHERE token = ?', [$t])) {
                return $t;
            }
        }
        throw new \RuntimeException('تعذّر توليد رابط فريد — حاول تاني');
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!isset(self::STATUSES[$status])) { return; }
        Database::exec('UPDATE shipment_tracks SET status = ? WHERE id = ?', [$status, $id]);
    }

    public static function setPhone(int $id, string $phone, string $driver = ''): void
    {
        $phone = self::normPhone($phone);
        Database::exec('UPDATE shipment_tracks SET driver_phone = ? WHERE id = ?', [$phone ?: null, $id]);
        if ($phone !== '' && trim($driver) !== '') { self::rememberPhone($driver, $phone); }
    }

    /** يمدّ صلاحية الرابط من دلوقتي + يحدّث الرابط العام المسجّل عليه */
    public static function renew(int $id): void
    {
        $hours = max(1, (int) App::setting('track_expire_hours', 48));
        Database::exec(
            'UPDATE shipment_tracks
                SET expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR), base_url = ?, status = "active"
              WHERE id = ?',
            [$hours, mb_substr(public_base_url(), 0, 200) ?: null, $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::exec('DELETE FROM track_pings WHERE track_id = ?', [$id]);
        Database::exec('DELETE FROM shipment_tracks WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------------
    //  استقبال الموقع من السائق (المسار العام)
    // ------------------------------------------------------------------------

    /** هل الرابط لسه صالح للاستخدام؟ */
    public static function isOpen(array $track): bool
    {
        if (($track['status'] ?? '') !== 'active') { return false; }
        if (empty($track['expires_at'])) { return true; }
        // ⚠️ المقارنة جوّه SQL. الإنتاج: MySQL بـUTC وPHP بـAfrica/Cairo (3 ساعات
        //    فرق)، فمقارنة `strtotime($expires_at) < time()` كانت بتقفل رابط
        //    السائق **قبل ميعاده بـ3 ساعات**. الطرفين هنا على نفس الساعة.
        return (int) Database::scalar('SELECT ? > NOW()', [$track['expires_at']]) === 1;
    }

    /** سبب رفض الرابط بلغة السائق */
    public static function closedReason(array $track): string
    {
        $st = $track['status'] ?? '';
        if ($st === 'done')      { return 'الرحلة دي اتقفلت — وصلت بالفعل. مش محتاج تبعت موقعك.'; }
        if ($st === 'cancelled') { return 'الرحلة دي اتلغت. كلّم المكتب لو محتاج حاجة.'; }
        return 'الرابط ده انتهت صلاحيته. اطلب رابط جديد من المكتب.';
    }

    public static function markOpened(int $id): void
    {
        Database::exec(
            'UPDATE shipment_tracks SET opened_at = COALESCE(opened_at, NOW()) WHERE id = ?',
            [$id]
        );
    }

    /**
     * تسجيل نقطة موقع. يرجّع ['ok'=>bool, 'msg'=>string, 'ping'=>?array].
     * لا يرمي استثناءات — المسار العام لازم يرد رد نظيف للسائق مهما حصل.
     */
    public static function addPing(array $track, float $lat, float $lng, array $meta = []): array
    {
        $id = (int) $track['id'];

        if (!self::isOpen($track)) {
            return ['ok' => false, 'msg' => self::closedReason($track)];
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return ['ok' => false, 'msg' => 'الموقع اللي وصلنا مش مظبوط — حاول تاني.'];
        }
        if ((int) $track['ping_count'] >= self::MAX_PINGS) {
            return ['ok' => false, 'msg' => 'وصلنا للحد الأقصى من النقاط للرحلة دي.'];
        }

        $source = (($meta['source'] ?? '') === 'live') ? 'live' : 'manual';
        $prev   = self::lastPing($id);

        // حماية من الإغراق: نقطة كل 5 ثواني بحد أقصى.
        // ⚠️ الفرق بيتحسب جوّه SQL. `time() - strtotime($created_at)` كان بيدّي
        //    فرق ثابت ≈3 ساعات على الإنتاج (MySQL بـUTC وPHP بـCairo)، يعني
        //    الشرط عمره ما تحقّق و**الحد ده كان معطّل تمامًا** على أهم مسار عام
        //    بلا تسجيل دخول في النظام.
        if ($prev) {
            $gap = (int) Database::scalar(
                'SELECT TIMESTAMPDIFF(SECOND, ?, NOW())', [$prev['created_at']]);
            if ($gap < self::MIN_PING_GAP_SECS) {
                return ['ok' => false, 'msg' => 'استنى ثواني وحاول تاني.'];
            }
        }

        $dist = 0;
        if ($prev) {
            $dist = (int) round(self::haversine(
                (float) $prev['lat'], (float) $prev['lng'], $lat, $lng
            ));
        }

        // العنوان: نجيبه للّقطات دايمًا، وللتتبع المباشر لو اتحرّك مسافة معتبرة فقط
        // (حدود OpenStreetMap المجانية ~طلب/ثانية). فشله لا يكسر التسجيل أبدًا.
        $address = null;
        $needGeo = ($source === 'manual') || !$prev || $dist >= self::GEOCODE_MIN_MOVE_M;
        if ($needGeo) { $address = self::reverseGeocode($lat, $lng); }
        if ($address === null && $prev && $dist < self::GEOCODE_MIN_MOVE_M) {
            $address = $prev['address'] ?: null;   // ما اتحركش كتير — نفس العنوان
        }

        $acc = isset($meta['accuracy']) && $meta['accuracy'] !== '' ? (int) round((float) $meta['accuracy']) : null;
        if ($acc !== null && ($acc < 0 || $acc > 100000)) { $acc = null; }
        $spd = isset($meta['speed']) && $meta['speed'] !== '' && (float) $meta['speed'] >= 0
             ? round((float) $meta['speed'] * 3.6, 2) : null;   // م/ث → كم/س
        if ($spd !== null && $spd > 400) { $spd = null; }

        Database::exec(
            'INSERT INTO track_pings (track_id, lat, lng, accuracy_m, speed_kmh, address, source, dist_m, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id, round($lat, 7), round($lng, 7), $acc, $spd, $address, $source, $dist,
                mb_substr((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200) ?: null,
            ]
        );
        $pingId = Database::lastId();

        Database::exec(
            'UPDATE shipment_tracks
                SET last_ping_at = NOW(), ping_count = ping_count + 1, opened_at = COALESCE(opened_at, NOW())
              WHERE id = ?',
            [$id]
        );

        return [
            'ok'   => true,
            'msg'  => 'تم تسجيل موقعك ✓',
            'ping' => Database::row('SELECT * FROM track_pings WHERE id = ?', [$pingId]),
        ];
    }

    // ------------------------------------------------------------------------
    //  حسابات ومساعدات
    // ------------------------------------------------------------------------

    /** المسافة بين نقطتين بالأمتار (Haversine) */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R  = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a  = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** ملخّص رحلة: إجمالي المسافة + المدة + متوسط السرعة */
    public static function summary(int $trackId): array
    {
        $r = Database::row(
            'SELECT COUNT(*) n, COALESCE(SUM(dist_m),0) m, MIN(created_at) f, MAX(created_at) l
               FROM track_pings WHERE track_id = ?',
            [$trackId]
        ) ?: [];
        $n    = (int) ($r['n'] ?? 0);
        $secs = ($n > 1) ? max(0, strtotime($r['l']) - strtotime($r['f'])) : 0;
        $km   = ((int) ($r['m'] ?? 0)) / 1000;
        return [
            'pings' => $n,
            'km'    => $km,
            'secs'  => $secs,
            'first' => $r['f'] ?? null,
            'last'  => $r['l'] ?? null,
            'kmh'   => $secs > 60 ? round($km / ($secs / 3600), 1) : 0.0,
        ];
    }

    /** «من ساعتين» / «من 5 دقايق» — نص عامّي لآخر ظهور */
    public static function ago(?string $ts): string
    {
        if (!$ts) { return 'لسه مبعتش'; }
        $s = time() - strtotime($ts);
        if ($s < 0)     { return 'دلوقتي'; }
        if ($s < 60)    { return 'دلوقتي'; }
        if ($s < 3600)  { $m = (int) ($s / 60);   return 'من ' . $m . ' ' . ($m === 1 ? 'دقيقة' : 'دقيقة'); }
        if ($s < 86400) { $h = (int) ($s / 3600); return 'من ' . $h . ' ' . ($h === 1 ? 'ساعة' : 'ساعة'); }
        $d = (int) ($s / 86400);
        return 'من ' . $d . ' ' . ($d === 1 ? 'يوم' : 'يوم');
    }

    /**
     * حالة السائق للعرض: بيبعت دلوقتي / ساكت / لسه مفتحش.
     * استرشادي بحت للعين — مالوش أي أثر على أي بيانات.
     */
    public static function pulse(array $t): array
    {
        if (empty($t['last_ping_at'])) {
            return empty($t['opened_at'])
                ? ['cold', 'لسه مفتحش الرابط']
                : ['warn', 'فتح الرابط وماضغطش'];
        }
        $s = time() - strtotime($t['last_ping_at']);
        if ($s < 300)   { return ['live', 'بيبعت دلوقتي']; }
        if ($s < 3600)  { return ['ok',   'آخر موقع ' . self::ago($t['last_ping_at'])]; }
        return ['warn', 'ساكت ' . self::ago($t['last_ping_at'])];
    }

    /** هل الرابط اتولّد على رابط عام مختلف عن الحالي (يعني مات)؟ */
    public static function isStaleLink(array $t): bool
    {
        $now = public_base_url();
        $was = rtrim((string) ($t['base_url'] ?? ''), '/');
        return $now !== '' && $was !== '' && $now !== $was;
    }

    /** الرابط العام الكامل للسائق */
    public static function publicLink(array $t): string
    {
        $base = public_base_url();
        return $base === '' ? '' : $base . '/t/' . $t['token'];
    }

    /** نص رسالة الواتساب الجاهزة */
    public static function waMessage(array $t, string $link): string
    {
        $bits = ['السلام عليكم' . (($t['driver'] ?? '') !== '' ? ' يا ' . $t['driver'] : '') . ' 🚚'];
        $ship = [];
        if (!empty($t['bill_number'])) { $ship[] = 'بوليصة ' . $t['bill_number']; }
        if (!empty($t['car_no']))      { $ship[] = 'سيارة ' . $t['car_no']; }
        if (!empty($t['destination'])) { $ship[] = 'رايحة ' . $t['destination']; }
        if ($ship) { $bits[] = implode(' — ', $ship); }
        $bits[] = 'من فضلك افتح الرابط ده واضغط «ابعت موقعي» عشان نعرف مكانك:';
        $bits[] = $link;
        $bits[] = 'شكرًا — شركة المتوكل';
        return implode("\n", $bits);
    }

    // ------------------------------------------------------------------------
    //  الوجهة: المسافة المتبقية والوصول المتوقع (v34)
    //
    //  ⚠️ استرشادي بحت — تقدير مش وعد:
    //    - المسافة **خط مستقيم** (Haversine) × معامل طريق، مش مسافة طريق حقيقية.
    //    - الوقت = المسافة ÷ سرعة (سرعة الرحلة الفعلية إن توفّرت، وإلا الافتراضية).
    //    - ما يمنعش أي إدخال ولا يمسّ أي حساب/عهدة/تقفيل.
    // ------------------------------------------------------------------------

    /** «وصل» لو بقى أقرب من كده للوجهة (خط مستقيم) */
    private const ARRIVED_KM = 5.0;

    /** أقل رحلة نثق في متوسط سرعتها الفعلي: 8 كم و20 دقيقة */
    private const SPEED_MIN_KM   = 8.0;
    private const SPEED_MIN_SECS = 1200;

    /** كاش نقاط الوجهات داخل الطلب — خاصية مشتركة عشان clearPointsCache() تقدر تفضّيه */
    private static ?array $pointsCache = null;

    /** كل نقاط الوجهات (كاش داخل الطلب — تُقرأ لكل كارت في اللوحة) */
    public static function points(): array
    {
        if (self::$pointsCache === null) {
            try {
                self::$pointsCache = Database::all('SELECT * FROM destination_points ORDER BY name');
            } catch (\Throwable $e) {
                self::$pointsCache = [];   // الهجرة لسه ما اتشغلتش — الوحدة تشتغل عادي بلا وجهات
            }
        }
        return self::$pointsCache;
    }

    /**
     * يطابق نص الوجهة («العاشر»، «ب سويف»، «مدينة بدر المنطقة 6») بنقطة على الخريطة.
     * الربط نصّي بلا FK (تحذير 3) فالمطابقة على 4 مستويات بترتيب الثقة:
     *   exact → alias → partial (الاسم بادئة النص) → fuzzy (خطأ إملائي بسيط)
     * يرجّع ['point'=>array, 'match'=>string] أو null.
     */
    public static function resolveDestination(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') { return null; }
        $nk = Maintenance::normkey($raw);
        if ($nk === '') { return null; }

        $points = self::points();
        if (!$points) { return null; }

        // 1) تطابق تام
        foreach ($points as $p) {
            if ($p['normkey'] === $nk) { return ['point' => $p, 'match' => 'exact']; }
        }
        // 2) اسم بديل (الاختصارات المستعملة فعلاً)
        foreach ($points as $p) {
            foreach (explode('|', (string) $p['aliases']) as $a) {
                if (trim($a) !== '' && $a === $nk) { return ['point' => $p, 'match' => 'alias']; }
            }
        }
        // 3) جزئي: «مدينه بدر المنطقه 6» يبدأ بـ«مدينه بدر». نطابق أطول اسم يبدأ بيه
        //    النص (على حدود كلمة) عشان «بدر» ما تتلغبطش بـ«بدرشين».
        $best = null; $bestLen = 0;
        foreach ($points as $p) {
            $cands = array_merge([$p['normkey']], array_filter(explode('|', (string) $p['aliases'])));
            foreach ($cands as $c) {
                $c = trim($c);
                if ($c === '' || mb_strlen($c) < 4) { continue; }
                if ($nk === $c || str_starts_with($nk, $c . ' ')) {
                    if (mb_strlen($c) > $bestLen) { $best = $p; $bestLen = mb_strlen($c); }
                }
            }
        }
        if ($best) { return ['point' => $best, 'match' => 'partial']; }

        // 4) خطأ إملائي بسيط (زي NameSuggestion) — مسافة تحرير ≤2 وبنسبة معقولة للطول
        $bestD = PHP_INT_MAX;
        foreach ($points as $p) {
            $d = levenshtein($nk, $p['normkey']);
            $max = mb_strlen($nk) <= 6 ? 1 : 2;
            if ($d <= $max && $d < $bestD) { $bestD = $d; $best = $p; }
        }
        return $best ? ['point' => $best, 'match' => 'fuzzy'] : null;
    }

    /**
     * تقدير المسافة المتبقية والوصول المتوقع لرحلة.
     * يرجّع null لو مفيش موقع أو مفيش نقطة للوجهة.
     */
    public static function eta(array $track, ?string $destination): ?array
    {
        $last = self::lastPing((int) $track['id']);
        if (!$last) { return null; }

        $r = self::resolveDestination($destination);
        if (!$r) { return null; }
        $p = $r['point'];
        // ⚠️ من v59 الوجهة ممكن تتسجّل **بالاسم بس بلا إحداثيات** (السجل بقى
        //    سجل جهات عام مش للخريطة وحدها). بلا إحداثيات مفيش تقدير مسافة —
        //    بنرجّع null فالواجهة بتقول «مش متحدّدة» بدل ما تحسب من (0,0).
        if ($p['lat'] === null || $p['lng'] === null) { return null; }

        $straightKm = self::haversine(
            (float) $last['lat'], (float) $last['lng'], (float) $p['lat'], (float) $p['lng']
        ) / 1000;

        $factor = max(1.0, (float) App::setting('track_road_factor', 1.3));
        $roadKm = $straightKm * $factor;

        // وصل؟ (خط مستقيم — الوجهة مدينة مش نقطة بالضبط)
        if ($straightKm <= self::ARRIVED_KM) {
            return [
                'point' => $p, 'match' => $r['match'], 'arrived' => true,
                'straight_km' => $straightKm, 'km' => $roadKm,
                'hours' => 0.0, 'speed' => 0.0, 'speed_src' => 'arrived',
                'text' => 'وصل الوجهة تقريبًا',
            ];
        }

        // السرعة: الفعلية لو الرحلة كبيرة كفاية نثق فيها، وإلا الافتراضية
        $sum = self::summary((int) $track['id']);
        $speedSrc = 'default';
        $speed = (float) App::setting('track_avg_speed_kmh', 45);
        if ($sum['km'] >= self::SPEED_MIN_KM && $sum['secs'] >= self::SPEED_MIN_SECS && $sum['kmh'] > 5) {
            $speed = (float) $sum['kmh'];
            $speedSrc = 'actual';
        }
        if ($speed <= 0) { $speed = 45; $speedSrc = 'default'; }

        $hours = $roadKm / $speed;

        return [
            'point' => $p, 'match' => $r['match'], 'arrived' => false,
            'straight_km' => $straightKm, 'km' => $roadKm,
            'hours' => $hours, 'speed' => $speed, 'speed_src' => $speedSrc,
            'text' => 'فاضل ~' . number_format($roadKm, 0) . ' كم — ' . self::hoursText($hours),
        ];
    }

    /** 2.6 ساعة → «متوقع يوصل خلال ~2 ساعة و36 دقيقة» */
    public static function hoursText(float $hours): string
    {
        if ($hours <= 0) { return 'وصل'; }
        $mins = (int) round($hours * 60);
        if ($mins < 60) { return 'متوقع يوصل خلال ~' . $mins . ' دقيقة'; }
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        $ht = $h === 1 ? 'ساعة' : ($h === 2 ? 'ساعتين' : $h . ' ساعات');
        if ($h > 10) { $ht = $h . ' ساعة'; }
        return 'متوقع يوصل خلال ~' . $ht . ($m >= 5 ? ' و' . $m . ' دقيقة' : '');
    }

    /** وقت الوصول المتوقع كساعة حائط (لو الرحلة شغّالة) */
    public static function etaClock(float $hours): string
    {
        return date('H:i', time() + (int) round($hours * 3600));
    }

    // ------------------------------------------------------------------------
    //  التحديد التلقائي للوجهة من OpenStreetMap (v34)
    //
    //  ⚠️ خلّي بالك: البحث الخام في Nominatim **بيغلط غلط فادح** مع أسماء
    //     الوجهات المصرية المختصرة — متحقَّق منه فعليًا:
    //       «العاشر»  → مقبرة «رمسيس العاشر» في الأقصر (بترتيب أعلى من المدينة!)
    //       «6 أكتوبر» → حديقة في حلوان + كافيه في الإسكندرية
    //     عشان كده **ممنوع ناخد أول نتيجة**. الفلتر تحت بيقبل الأماكن السكنية
    //     بس (place/city|town|village…) ويرتّبها بنوع المكان لا بالـ importance
    //     (لأن المقبرة كان importance بتاعها أعلى). أي نقطة تلقائية تتوسم
    //     is_auto=1 عشان المالك يراجعها.
    // ------------------------------------------------------------------------

    /** أنواع الأماكن المقبولة، بترتيب الأولوية (الأكبر أولًا) */
    private const PLACE_RANK = [
        'city' => 100, 'town' => 90, 'municipality' => 85, 'village' => 70,
        'suburb' => 60, 'neighbourhood' => 50, 'hamlet' => 40, 'quarter' => 40,
        'industrial' => 45, 'locality' => 30,
    ];

    /**
     * يدوّر على اسم وجهة في OpenStreetMap ويرجّع أفضل مرشّح **موثوق** أو null.
     * يرجّع ['lat','lng','label','kind','confidence'].
     */
    public static function geocodePlace(string $name): ?array
    {
        $name = trim($name);
        if (mb_strlen($name) < 3) { return null; }

        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=10'
             . '&countrycodes=eg&addressdetails=1&accept-language=ar&q=' . rawurlencode($name);
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT      => 'AlmotawakelShipmentTracking/1.0 (self-hosted)',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
            if ($code !== 200 || !$body) { return null; }
            $rows = json_decode((string) $body, true);
            if (!is_array($rows) || !$rows) { return null; }
        } catch (\Throwable $e) {
            return null;
        }

        // 1) الأماكن السكنية بس — ده اللي بيرمي المقبرة والحديقة والكافيه
        $best = null; $bestScore = -1;
        foreach ($rows as $r) {
            if (($r['category'] ?? '') !== 'place') { continue; }
            $type = (string) ($r['type'] ?? '');
            if (!isset(self::PLACE_RANK[$type])) { continue; }
            // الترتيب بنوع المكان الأول، والـ importance يفصل بين نفس النوع فقط
            $score = self::PLACE_RANK[$type] * 100 + min(99, (int) (((float) ($r['importance'] ?? 0)) * 100));
            if ($score > $bestScore) { $bestScore = $score; $best = $r; $best['_kind'] = $type; }
        }

        // 2) لو مفيش مكان سكني: نقبل طريق أو حدود إدارية بثقة أقل («طريق التحدي»)
        if (!$best) {
            foreach ($rows as $r) {
                $cat = (string) ($r['category'] ?? '');
                if ($cat !== 'highway' && $cat !== 'boundary') { continue; }
                $best = $r; $best['_kind'] = $cat . '/' . ($r['type'] ?? '');
                break;
            }
            if (!$best) { return null; }   // مفيش حاجة موثوقة — أحسن من رقم غلط
            return [
                'lat' => (float) $best['lat'], 'lng' => (float) $best['lon'],
                'label' => (string) ($best['display_name'] ?? $name),
                'kind' => $best['_kind'], 'confidence' => 'low',
            ];
        }

        return [
            'lat' => (float) $best['lat'], 'lng' => (float) $best['lon'],
            'label' => (string) ($best['display_name'] ?? $name),
            'kind' => $best['_kind'],
            'confidence' => in_array($best['_kind'], ['city', 'town', 'municipality'], true) ? 'high' : 'medium',
        ];
    }

    /**
     * يضمن إن لوجهة نقطة: لو متطابقة خلاص، وإلا يدوّر عليها تلقائيًا ويحفظها.
     * يرجّع ['point'=>…, 'match'=>…] أو null لو مفيش حاجة موثوقة.
     * لا يرمي استثناءات ولا يعطّل أي عملية — التحديد تحسين مش شرط.
     */
    public static function learnDestination(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') { return null; }

        if ($hit = self::resolveDestination($raw)) { return $hit; }   // متعرّفة خلاص

        $g = self::geocodePlace($raw);
        if (!$g) { return null; }

        try {
            $nk = Maintenance::normkey($raw);
            if ($nk === '' || Database::scalar('SELECT COUNT(*) FROM destination_points WHERE normkey=?', [$nk])) {
                return null;
            }
            Database::exec(
                'INSERT INTO destination_points (name, normkey, lat, lng, note, is_auto)
                 VALUES (?, ?, ?, ?, ?, 1)',
                [mb_substr($raw, 0, 120), $nk, round($g['lat'], 7), round($g['lng'], 7),
                 mb_substr('اتحدّدت تلقائيًا: ' . $g['label'], 0, 160)]
            );
        } catch (\Throwable $e) {
            return null;
        }
        self::clearPointsCache();
        return self::resolveDestination($raw);
    }

    /** الكاش لازم يتفضّى بعد أي كتابة عشان الصفحة تشوف الجديد فورًا */
    public static function clearPointsCache(): void
    {
        self::$pointsCache = null;
    }

    // ------------------------------------------------------------------------
    //  إدارة نقاط الوجهات
    // ------------------------------------------------------------------------

    public static function savePoint(int $id, string $name, float $lat, float $lng, string $aliases = '', string $note = ''): void
    {
        $name = trim(mb_substr($name, 0, 120));
        if ($name === '') { throw new \RuntimeException('اكتب اسم الوجهة'); }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            throw new \RuntimeException('الإحداثيات مش مظبوطة');
        }
        $nk = Maintenance::normkey($name);
        // الأسماء البديلة تُخزَّن مُوحّدة عشان المطابقة تبقى رخيصة وقت العرض
        $al = array_values(array_unique(array_filter(array_map(
            fn($a) => Maintenance::normkey($a),
            preg_split('/[|,،\n]+/u', $aliases) ?: []
        ), fn($a) => $a !== '' && $a !== $nk)));

        $clash = Database::scalar(
            'SELECT id FROM destination_points WHERE normkey = ? AND id <> ?', [$nk, $id]
        );
        if ($clash) { throw new \RuntimeException('فيه وجهة بنفس الاسم بالفعل'); }

        $args = [$name, $nk, $al ? implode('|', $al) : null, $lat, $lng,
                 trim(mb_substr($note, 0, 160)) ?: null];
        if ($id > 0) {
            $args[] = $id;
            Database::exec('UPDATE destination_points SET name=?, normkey=?, aliases=?, lat=?, lng=?, note=?, is_auto=0 WHERE id=?', $args);
        } else {
            Database::exec('INSERT INTO destination_points (name, normkey, aliases, lat, lng, note) VALUES (?,?,?,?,?,?)', $args);
        }
        // المالك راجعها بإيده → مبقتش تلقائية، والكاش لازم يشوف الجديد
        self::clearPointsCache();
    }

    public static function deletePoint(int $id): void
    {
        Database::exec('DELETE FROM destination_points WHERE id = ?', [$id]);
        self::clearPointsCache();
    }

    public static function findPoint(int $id): ?array
    {
        return Database::row('SELECT * FROM destination_points WHERE id = ?', [$id]);
    }

    /**
     * الوجهات المستعملة في البوالص ومعاها هل ليها نقطة ولا لأ — لصفحة الإدارة.
     * الأكثر استخدامًا أولًا عشان المالك يظبّط المهم الأول.
     */
    public static function destinationCoverage(): array
    {
        $rows = Database::all(
            "SELECT destination d, COUNT(*) n FROM bawalis
              WHERE destination IS NOT NULL AND destination <> '' AND is_legacy = 0
              GROUP BY destination ORDER BY n DESC LIMIT 200"
        );
        $out = [];
        foreach ($rows as $r) {
            $m = self::resolveDestination($r['d']);
            $out[] = [
                'name'  => $r['d'],
                'count' => (int) $r['n'],
                'point' => $m['point'] ?? null,
                'match' => $m['match'] ?? null,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------------
    //  أرقام السائقين
    // ------------------------------------------------------------------------

    public static function rememberPhone(string $driver, string $phone): void
    {
        $driver = trim($driver);
        $phone  = self::normPhone($phone);
        if ($driver === '' || $phone === '') { return; }
        Database::exec(
            'INSERT INTO driver_contacts (driver, phone) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE phone = VALUES(phone)',
            [$driver, $phone]
        );
    }

    public static function phoneOf(string $driver): string
    {
        if (trim($driver) === '') { return ''; }
        return (string) (Database::scalar(
            'SELECT phone FROM driver_contacts WHERE driver = ? LIMIT 1', [trim($driver)]
        ) ?? '');
    }

    /** تنظيف رقم الموبايل للتخزين (أرقام إنجليزية بلا مسافات) */
    private static function normPhone(string $p): string
    {
        $p = preg_replace('/[^\d+]/', '', ar2en_digits($p));
        return mb_substr((string) $p, 0, 30);
    }

    // ------------------------------------------------------------------------
    //  العنوان النصّي (OpenStreetMap Nominatim — مجاني)
    // ------------------------------------------------------------------------

    /**
     * إحداثيات → عنوان عربي. يرجع null عند أي فشل (مفيش نت/بطء/رد غريب)
     * والتسجيل بيكمّل عادي — العنوان تحسين للعرض مش شرط.
     */
    private static function reverseGeocode(float $lat, float $lng): ?string
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2'
             . '&lat=' . rawurlencode((string) round($lat, 6))
             . '&lon=' . rawurlencode((string) round($lng, 6))
             . '&zoom=16&accept-language=ar';
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,          // قصير: ما نعطّلش رد السائق
                CURLOPT_CONNECTTIMEOUT => 3,
                // Nominatim بيطلب User-Agent معرِّف — شرط استخدامها المجاني
                CURLOPT_USERAGENT      => 'AlmotawakelShipmentTracking/1.0 (self-hosted)',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // بلا curl_close(): مهملة من PHP 8.5 وتحذيرها كان بيتطبع جوّه رد الـ JSON
            // بتاع السائق فيكسره. المورد بيتقفل لوحده مع نهاية الطلب.
            unset($ch);
            if ($code !== 200 || !$body) { return null; }

            $j = json_decode((string) $body, true);
            if (!is_array($j)) { return null; }
            $a = $j['address'] ?? [];

            // نبني عنوان قصير مفيد بدل السطر الطويل بتاع Nominatim
            $parts = array_filter([
                $a['road'] ?? ($a['neighbourhood'] ?? null),
                $a['suburb'] ?? ($a['village'] ?? ($a['town'] ?? null)),
                $a['city'] ?? ($a['county'] ?? null),
                $a['state'] ?? null,
            ]);
            $short = implode('، ', array_unique(array_map('strval', $parts)));
            if ($short === '') { $short = (string) ($j['display_name'] ?? ''); }
            $short = trim($short);
            return $short === '' ? null : mb_substr($short, 0, 255);
        } catch (\Throwable $e) {
            return null;   // أي مشكلة → بلا عنوان، والنقطة اتسجّلت
        }
    }
}
