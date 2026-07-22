<?php
namespace App\Models;

use App\Core\Database;

/**
 * ملاحظات لوحة التحكم — تذكيرات سريعة على الصفحة الرئيسية.
 * معزولة تمامًا: لا تدخل في أي حساب مالي أو تقفيل.
 */
class Note
{
    public const COLORS = ['gold', 'navy', 'green', 'red', 'teal', 'purple'];

    /** الملاحظات: غير المكتملة أولاً ثم المكتملة، والأحدث قبل الأقدم */
    public static function all(int $limit = 40): array
    {
        return Database::all(
            "SELECT * FROM dashboard_notes ORDER BY done ASC, created_at DESC, id DESC LIMIT $limit"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::row("SELECT * FROM dashboard_notes WHERE id = ?", [$id]);
    }

    public static function create(string $body, string $color = 'gold', ?string $author = null): int
    {
        $body = trim(mb_substr($body, 0, 500));
        if ($body === '') { return 0; }
        if (!in_array($color, self::COLORS, true)) { $color = 'gold'; }
        Database::exec(
            "INSERT INTO dashboard_notes (body, color, author) VALUES (?, ?, ?)",
            [$body, $color, $author]
        );
        return Database::lastId();
    }

    /** تعديل نص/لون ملاحظة */
    public static function update(int $id, string $body, string $color = 'gold'): void
    {
        $body = trim(mb_substr($body, 0, 500));
        if ($body === '') { return; }
        if (!in_array($color, self::COLORS, true)) { $color = 'gold'; }
        Database::exec(
            "UPDATE dashboard_notes SET body = ?, color = ? WHERE id = ?",
            [$body, $color, $id]
        );
    }

    /** يقلب حالة الإكمال (مكتمل ⇄ غير مكتمل) ويضبط وقت الإكمال */
    /**
     * يقلب حالة «مكتملة» ويُرجِع الحالة الجديدة.
     *
     * الإرجاع اتضاف في المرحلة ٢: الإجراء كان **صامت تمامًا** (بيقلب الحالة
     * وبيعمل redirect بلا أي رسالة)، وعشان الرسالة تقول الصح لازم نعرف
     * الحالة الجديدة فعلاً مش نخمّنها.
     */
    public static function toggleDone(int $id): bool
    {
        // MySQL يقيّم الأعمدة يسارًا فيمينًا بالقيم المُحدَّثة، فيصبح `done` هنا = القيمة الجديدة.
        Database::exec(
            "UPDATE dashboard_notes
             SET done = 1 - done,
                 done_at = CASE WHEN done = 1 THEN CURRENT_TIMESTAMP ELSE NULL END
             WHERE id = ?",
            [$id]
        );
        return (int) Database::scalar("SELECT done FROM dashboard_notes WHERE id = ?", [$id]) === 1;
    }

    public static function delete(int $id): void
    {
        Database::exec("DELETE FROM dashboard_notes WHERE id = ?", [$id]);
    }

    public static function count(bool $pendingOnly = false): int
    {
        $w = $pendingOnly ? " WHERE done = 0" : "";
        return (int) Database::scalar("SELECT COUNT(*) FROM dashboard_notes$w");
    }
}
