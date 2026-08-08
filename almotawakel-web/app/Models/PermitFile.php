<?php
namespace App\Models;

use App\Core\Database;
use App\Core\PrivateFile;

/**
 * ملفات الإذن المهمة (v64) — أي ورقة تخصّ الإذن ده بعينه.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️⚠️ وحدة وصفية معزولة بالبناء — ولا عمود مالي واحد.
 * ═══════════════════════════════════════════════════════════════════════════
 * نفس منطق `permit_track_events` (v62) و`rep_permit_pins` (v63): جدول خاص
 * **كل كود الخزنة عمره ما سمع عنه**. `bin/verify_v64.php` بيفشل لو أي ملف
 * خزنة ذكر `permit_files` أو `PermitFile`.
 *
 * ⚠️ **مش زي `party_documents` (v56):** دي أوراق **الطرف** (بطاقة ضريبية ·
 *    سجل تجاري) بتتحطّ مرّة وتظهر على كل أذوناته؛ ودي أوراق **الإذن ده**.
 *
 * ⚠️ الملف بيتخزّن **برّه متناول الإنترنت** في `storage/private_uploads/permits`
 *    وبيتقدّم عبر `FileController` بعد فحص الجلسة — نفس مسار باقي المرفقات.
 */
class PermitFile
{
    /** أقصى حجم للورقة الواحدة — الموبايل بيصوّر بجودة عالية. */
    public const MAX_BYTES = 12 * 1024 * 1024;

    private static ?bool $ready = null;

    /** الجدول اترحّل؟ — النظام لازم يشتغل عادي قبل migrate_v64. */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'permit_files'");
        }
        return self::$ready;
    }

    /** تصفير الكاش بعد الترحيل داخل نفس الطلب (مصيدة كاش المخطط — v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null;
        return self::ready();
    }

    public static function available(): bool { return self::ready(); }

    /** ملفات إذن — الأحدث أولاً. */
    public static function forPermit(int $permitId): array
    {
        if (!self::ready() || $permitId <= 0) { return []; }
        return Database::all(
            'SELECT f.*, r.name rep_name
             FROM permit_files f
             LEFT JOIN reps r ON r.id = f.rep_id
             WHERE f.permit_id = ? ORDER BY f.id DESC', [$permitId]);
    }

    public static function find(int $id): ?array
    {
        if (!self::ready() || $id <= 0) { return null; }
        return Database::row('SELECT * FROM permit_files WHERE id = ?', [$id]);
    }

    /** عدد ملفات كل إذن دفعة واحدة — **استعلام واحد** لشاشة فيها ٣٠٠ إذن. */
    public static function countsFor(array $permitIds): array
    {
        if (!self::ready() || !$permitIds) { return []; }
        $ids = array_values(array_unique(array_map('intval', $permitIds)));
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::all(
            "SELECT permit_id, COUNT(*) c FROM permit_files
              WHERE permit_id IN ($in) GROUP BY permit_id", $ids) as $r) {
            $out[(int) $r['permit_id']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * أول خانة رفع فيها ملف فعلاً — **المصدر الوحيد لاختيار الملف المرفوع**.
     *
     * ⚠️⚠️ شاشة المندوب فيها **خانتان** (`doc_cam` بتفتح الكاميرا · `doc_file`
     *    بتفتح مدير الملفات) لأن سمة `capture` بتمنع اختيار ملف موجود. كانوا
     *    بنفس الاسم فالمتصفح بيبعت **الاتنين** — الفاضية والمليانة — والفاضية
     *    بتغلب لأنها الأخيرة، فالرفع كان بيترفض بـ«اختار الملف الأول» رغم إن
     *    المندوب اختار (باج متحقَّق). الدالة دي بتاخد أول واحدة فيها ملف.
     *
     * @param array<string,mixed> $files عادةً `$_FILES`
     */
    public static function pickUpload(array $files, array $names = ['doc', 'doc_cam', 'doc_file']): array
    {
        foreach ($names as $n) {
            $f = $files[$n] ?? null;
            if (is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                return $f;
            }
        }
        return ['error' => UPLOAD_ERR_NO_FILE];
    }

    /**
     * رفع ورقة على إذن.
     *
     * ⚠️ **الحُرّاس هنا في الموديل لا في الكنترولر** — الإذن لازم يكون موجود،
     *    والملف لازم يعدّي على `PrivateFile` (نوع مسموح + حجم). الفورم مش مصدر
     *    ثقة، وأي مسار كتابة جديد بياخد نفس الحماية.
     *
     * ⚠️ **مفيش `Closing::guard`** عن قصد — ورقة وصفية ما بتدخلش أي تقفيل ولا
     *    بتمسّ أي رصيد مثبَّت (نفس قرار v62/v63).
     *
     * @throws \RuntimeException برسالة عربية واضحة عند أي رفض
     */
    public static function add(int $permitId, array $file, string $title = '',
                               ?int $repId = null, ?string $userName = null): int
    {
        if (!self::ready()) { throw new \RuntimeException('وحدة ملفات الإذن لسه مش مفعّلة.'); }
        if (!Database::scalar('SELECT id FROM permits WHERE id = ?', [$permitId])) {
            throw new \RuntimeException('الإذن ده مش موجود.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('اختار الملف الأول.');
        }
        $path = PrivateFile::storeUploaded($file, 'permits', PrivateFile::PERMIT_MIME, self::MAX_BYTES);
        if ($path === null) {
            throw new \RuntimeException('الملف مرفوض — لازم صورة أو PDF وأقل من ١٢ ميجا.');
        }

        $title = trim($title);
        if (mb_strlen($title) > 120) { $title = mb_substr($title, 0, 120); }

        Database::exec(
            'INSERT INTO permit_files
                (permit_id, file_path, orig_name, mime, size_bytes, title, rep_id, user_name)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $permitId, $path,
                mb_substr((string) ($file['name'] ?? ''), 0, 190),
                mb_substr((string) ($file['type'] ?? ''), 0, 80),
                (int) ($file['size'] ?? 0),
                $title !== '' ? $title : null,
                $repId ?: null,
                $userName !== null && $userName !== '' ? mb_substr($userName, 0, 120) : null,
            ]);
        return Database::lastId();
    }

    /**
     * حذف ورقة — بيشيل الصف **والملف من القرص**.
     *
     * ⚠️ المندوب يشيل **بتاعه هو بس**؛ المكتب يشيل أي حاجة. الحارس هنا مش في
     *    الشاشة، فأي فورم مزوّر بيترفض.
     */
    public static function remove(int $id, ?int $repId = null): bool
    {
        $row = self::find($id);
        if (!$row) { return false; }
        if ($repId !== null && (int) ($row['rep_id'] ?? 0) !== $repId) { return false; }

        Database::exec('DELETE FROM permit_files WHERE id = ?', [$id]);
        PrivateFile::delete($row['file_path'] ?? null);
        return true;
    }

    /** الورقة دي صورة؟ (تتعرض كمعاينة؛ الباقي أيقونة ملف) */
    public static function isImage(array $row): bool
    {
        return str_starts_with((string) ($row['mime'] ?? ''), 'image/');
    }

    /** اسم مفهوم للتنزيل — لو مفيش وصف بنستخدم الاسم الأصلي. */
    public static function downloadName(array $row): string
    {
        $ext  = pathinfo((string) ($row['file_path'] ?? ''), PATHINFO_EXTENSION) ?: 'bin';
        $base = trim((string) ($row['title'] ?? '')) ?: (string) ($row['orig_name'] ?? 'ملف');
        $base = preg_replace('~[\\\\/:*?"<>|]+~u', '-', $base);
        if (!str_ends_with(mb_strtolower($base), '.' . $ext)) { $base .= '.' . $ext; }
        return $base;
    }
}
