<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

/**
 * الجرد الفعلي (v108) — «عدّينا الكاش لقيناه كام؟».
 *
 * ═══ ⚠️⚠️ العزل بالبناء (القاعدة ①) — لا تعكسها ═══
 * `safe_audits` **جدول شهادة لا جدول مال**. مفيش سطر في `Closing` ولا
 * `CompanySafe` ولا `DailyReview` ولا `Expense` ولا `Metrics` بيقرا منه، ومفيش
 * رصيد بيتغيّر بسببه. **الفرق (عجز/زيادة) بيتسجّل ولا يتقيّد**: لو المالك عايز
 * يصحّح، بيسجّل الحركة في وحدتها (إضافة عهدة أو مصروف) وتبان في السجل باسمها
 * الحقيقي — تقييد تلقائي هنا كان معناه فلوس بتتحرّك من شاشة تدقيق.
 * `verify_v108` **بيفشل** لو أي ملف مالي ذكر الجدول.
 *
 * ═══ ⚠️ لقطة لا اشتقاق ═══
 * `book_balance` بيتخزَّن **وقت الجرد** من `SafeInventory::bookBalance()`.
 * إعادة حسابه عند العرض كانت بتخلّي شهادة الشهر اللي فات تقول رقمًا تالتًا أول
 * ما حد يعدّل حركة قديمة (نفس قاعدة «الفاتورة لقطة ثابتة» v10).
 *
 * ═══ ⚠️ جرد واحد لكل (يوم × خزنة) ═══
 * المفتاح الفريد `uq_audit` بيمنع صفّين لنفس الجرد، والحفظ التاني **بيدهس**
 * الأول (تصحيح عدّة) بدل ما يزوّد نسخة. الصف القديم بيروح `audit_log` زي أي
 * تعديل، فالتاريخ ما بيضيعش.
 */
class SafeAudit
{
    private static ?bool $ready = null;

    /** النظام لازم يشتغل عادي **قبل** migrate_v108 — كل قارئ بيرجّع فاضي. */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'safe_audits'");
        }
        return self::$ready;
    }

    /** ⚠️ لازم تتنادى في آخر الترحيل — كاش المخطط الساكن بيفشّل التحقّق (مصيدة v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null;
        return self::ready();
    }

    // ───────────────────────────────────────────── القراءة

    public static function find(int $id): ?array
    {
        if (!self::ready()) { return null; }
        return Database::row('SELECT * FROM safe_audits WHERE id = ?', [$id]);
    }

    public static function on(string $date, string $safe): ?array
    {
        if (!self::ready() || !SafeInventory::isSafe($safe)) { return null; }
        return Database::row(
            'SELECT * FROM safe_audits WHERE audit_date = ? AND safe_key = ?', [$date, $safe]);
    }

    /** آخر جرد مسجَّل لخزنة — الكارت بيعرضه جنب الرصيد الدفتري. */
    public static function latest(string $safe): ?array
    {
        if (!self::ready() || !SafeInventory::isSafe($safe)) { return null; }
        return Database::row(
            'SELECT * FROM safe_audits WHERE safe_key = ?
              ORDER BY audit_date DESC, id DESC LIMIT 1', [$safe]);
    }

    /**
     * سجل عمليات الجرد (الأحدث أولاً) مع تسمية الخزنة.
     *
     * @param array{safe?:string,from?:string,to?:string,only_diff?:bool} $f
     */
    public static function history(array $f = [], int $limit = 200): array
    {
        if (!self::ready()) { return []; }
        [$w, $p] = self::filterWhere($f);
        $limit = max(1, min(1000, $limit));
        $rows = Database::all(
            "SELECT * FROM safe_audits WHERE $w ORDER BY audit_date DESC, id DESC LIMIT $limit", $p);
        foreach ($rows as &$r) {
            $r['safe_label'] = SafeInventory::label((string) $r['safe_key']);
            $r['safe_icon']  = SafeInventory::icon((string) $r['safe_key']);
            $r['diff']       = round((float) $r['diff'], 2);
            // ⚠️ التصنيف من الرقم لا من عمود مخزَّن — عمود تاني كان هيقدر يخالفه.
            $r['state']      = abs($r['diff']) < 0.005 ? 'match' : ($r['diff'] > 0 ? 'over' : 'short');
        }
        unset($r);
        return $rows;
    }

    /** عدد الصفوف المطابقة — عشان «بنعرض N من M» (ممنوع قطع صامت). */
    public static function countHistory(array $f = []): int
    {
        if (!self::ready()) { return 0; }
        [$w, $p] = self::filterWhere($f);
        return (int) Database::scalar("SELECT COUNT(*) FROM safe_audits WHERE $w", $p);
    }

    /**
     * ⚠️ **المصدر الوحيد لشرط الفلترة** — `history` و`countHistory` بيقروا منه
     *    مع بعض، وإلا الشاشة تقول «٥٠ من ١٢» وهي بتعرض غير اللي بتعدّه.
     */
    private static function filterWhere(array $f): array
    {
        $w = ['1']; $p = [];
        $safe = (string) ($f['safe'] ?? '');
        if (SafeInventory::isSafe($safe)) { $w[] = 'safe_key = ?'; $p[] = $safe; }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = (string) ($f[$k] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $w[] = "audit_date $op ?"; $p[] = $d; }
        }
        if (!empty($f['only_diff'])) { $w[] = 'ABS(diff) >= 0.005'; }
        return [implode(' AND ', $w), $p];
    }

    /**
     * إحصاء سريع للسجل — كارت «كام جرد وكام منهم طابق».
     * @return array{count:int,match:int,short:int,over:int,worst:float}
     */
    public static function stats(array $f = []): array
    {
        $out = ['count' => 0, 'match' => 0, 'short' => 0, 'over' => 0, 'worst' => 0.0];
        if (!self::ready()) { return $out; }
        [$w, $p] = self::filterWhere($f);
        $r = Database::row(
            "SELECT COUNT(*) c,
                    SUM(ABS(diff) < 0.005) m,
                    SUM(diff <= -0.005) s,
                    SUM(diff >=  0.005) o,
                    COALESCE(MAX(ABS(diff)),0) worst
               FROM safe_audits WHERE $w", $p) ?: [];
        return ['count' => (int) ($r['c'] ?? 0), 'match' => (int) ($r['m'] ?? 0),
                'short' => (int) ($r['s'] ?? 0), 'over'  => (int) ($r['o'] ?? 0),
                'worst' => round((float) ($r['worst'] ?? 0), 2)];
    }

    // ───────────────────────────────────────────── الكتابة

    /**
     * تسجيل (أو تصحيح) جرد خزنة في يوم.
     *
     * ⚠️ **الحارس في الموديل مش في الشاشة** (القاعدة ③): مفتاح الخزنة والتاريخ
     *    والمبلغ كلهم بيتفحصوا هنا، فأي مسار جديد أو فورم مزوّر بيعدّي على نفس
     *    القواعد.
     *
     * ⚠️ **مفيش `Closing::guard`** عن قصد: الجرد **ما بيمسّش رصيدًا مثبَّتًا**
     *    (ولا مليم بيتحرّك)، فمنع تسجيل عدّة على يوم متقفل كان هيمنع بالظبط
     *    الحاجة اللي التقفيل موجود عشانها — إثبات إن اللي في الدرج = اللي في
     *    الورق. (نفس منطق `ClientPayment` مع `route='visa'`.)
     *
     * ⚠️ **الرصيد الدفتري بيتاخد من `SafeInventory::bookBalance()` لا من الفورم**
     *    — قيمة جاية من الشاشة كانت بتخلّي «الفرق» رقمًا يقدر المستخدم يزوّره.
     *
     * @param string $counted نص المبلغ زي ما جه من الفورم (بفواصل آلاف)
     * @return int id الصف
     */
    public static function save(string $date, string $safe, string $counted, ?string $note): int
    {
        if (!self::ready()) {
            throw new \RuntimeException('جرد الخزينة مش مفعّل. شغّل php bin/migrate_v108.php أولاً.');
        }
        if (!SafeInventory::isSafe($safe)) {
            throw new \RuntimeException('خزنة غير معروفة.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = work_date(); }
        /* ⚠️ **جرد بتاريخ في المستقبل ممنوع** — الشهادة بتقول «عددت» وده فعل
           حصل، والتاريخ الجاي معناه رقم متسجّل على يوم لسه ما جاش. */
        if ($date > date('Y-m-d')) {
            throw new \RuntimeException('ما ينفعش تسجّل جرد بتاريخ في المستقبل.');
        }

        // ⚠️ فكّ فواصل الآلاف بالدالة المشتركة — تحويل مباشر بيقرا «1,500» كـ1.
        $value = round((float) money_unformat($counted), 2);
        if (trim((string) $counted) === '') {
            throw new \RuntimeException('اكتب المبلغ اللي عدّيته.');
        }
        if ($value < 0) {
            throw new \RuntimeException('المعدود ما ينفعش يكون بالسالب — الكاش المعدود صفر أو أكتر.');
        }

        $book = SafeInventory::bookBalance($safe);
        $diff = round($value - $book, 2);
        $note = trim((string) $note);
        $note = $note !== '' ? mb_substr($note, 0, 255) : null;

        $old = self::on($date, $safe);
        if ($old) {
            Database::exec(
                'UPDATE safe_audits SET book_balance=?, counted=?, diff=?, note=?,
                        updated_by=?, updated_at=NOW() WHERE id=?',
                [$book, $value, $diff, $note, Auth::user(), (int) $old['id']]);
            $id = (int) $old['id'];
            Audit::log('update', 'safe_audits', $id, $old, self::find($id),
                'تعديل جرد ' . SafeInventory::label($safe), ['record_date' => $date]);
            return $id;
        }

        Database::exec(
            'INSERT INTO safe_audits (audit_date, safe_key, book_balance, counted, diff, note, created_by)
             VALUES (?,?,?,?,?,?,?)',
            [$date, $safe, $book, $value, $diff, $note, Auth::user()]);
        $id = Database::lastId();
        Audit::log('create', 'safe_audits', $id, null, self::find($id),
            'جرد ' . SafeInventory::label($safe), ['record_date' => $date]);
        return $id;
    }

    public static function delete(int $id): void
    {
        $row = self::find($id);
        if (!$row) { throw new \RuntimeException('الجرد ده مش موجود.'); }
        Database::exec('DELETE FROM safe_audits WHERE id = ?', [$id]);
        Audit::log('delete', 'safe_audits', $id, $row, null,
            'حذف جرد ' . SafeInventory::label((string) $row['safe_key']),
            ['record_date' => (string) $row['audit_date']]);
    }
}
