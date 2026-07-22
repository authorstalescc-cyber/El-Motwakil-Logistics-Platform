<?php
namespace App\Core;

/**
 * فرق الساعة بين قاعدة البيانات وPHP.
 *
 * ⚠️ ليه الملف ده موجود أصلاً — مصيدة متحقَّقة على الإنتاج (2026-07-22):
 *    MySQL على الاستضافة بتشتغل بـ**UTC** وPHP بـ**Africa/Cairo** — فرق 3 ساعات.
 *    يعني أي وقت مخزَّن بـ`NOW()`/`CURRENT_TIMESTAMP` لما يتعرض للمستخدم
 *    بيبان **متأخر 3 ساعات** عن ساعة الحيطة عنده. ده كان بيعطّل قفل الحسابات
 *    كمان (اتصلّح في v48 بنقل المقارنات جوّه SQL).
 *
 *    **قاعدتان مختلفتان لازم ما تتخلطش:**
 *      • **المقارنة** (هل الوقت ده عدّى؟) → لازم تحصل **جوّه SQL** (`> NOW()`).
 *        ما تستخدمش الكلاس ده لمقارنة — استخدمه للعرض بس.
 *      • **العرض** (أعرض الوقت للمستخدم) → استخدم `Clock::displayExpr()` عشان
 *        الوقت يطابق ساعة المستخدم.
 *
 *    الفرق بيتقاس **فعليًّا** من القاعدة مرة واحدة في الطلب، مش مكتوب بالإيد،
 *    فلو الاستضافة ظبّطت التوقيت بعدين الكلاس ده بيرجّع صفر تلقائيًّا ويبطّل
 *    التعديل من نفسه بلا ما نلمس أي كود.
 */
class Clock
{
    private static ?int $offset = null;

    /** فرق الدقائق (ساعة PHP − ساعة القاعدة). موجب = القاعدة متأخرة. */
    public static function dbOffsetMinutes(): int
    {
        if (self::$offset !== null) { return self::$offset; }
        try {
            $dbNow = (string) Database::scalar('SELECT NOW()');
            $diff  = (int) round((time() - strtotime($dbNow)) / 60);
            // أي فرق فوق يوم معناه إن حاجة غلط (ساعة سيرفر بايظة) — نتجاهله
            // بدل ما نعرض تواريخ مالهاش معنى.
            self::$offset = (abs($diff) > 1440) ? 0 : $diff;
        } catch (\Throwable $e) {
            self::$offset = 0;
        }
        return self::$offset;
    }

    /**
     * تعبير SQL بيرجّع العمود مضبوطًا على ساعة المستخدم — **للعرض فقط**.
     * مثال: `SELECT ' . Clock::displayExpr('attempted_at') . ' AS at`
     */
    public static function displayExpr(string $column): string
    {
        $m = self::dbOffsetMinutes();
        if ($m === 0) { return $column; }
        return 'DATE_ADD(' . $column . ', INTERVAL ' . $m . ' MINUTE)';
    }

    /** هل فيه فرق يستاهل التنبيه عليه؟ (للعرض في لوحة المراقبة) */
    public static function isSkewed(): bool
    {
        return abs(self::dbOffsetMinutes()) >= 5;
    }

    /** وصف الفرق بالعربي. */
    public static function skewLabel(): string
    {
        $m = self::dbOffsetMinutes();
        if ($m === 0) { return 'ساعة القاعدة مضبوطة على ساعة النظام.'; }
        $h = intdiv(abs($m), 60);
        $r = abs($m) % 60;
        $txt = $h > 0 ? ($h . ' ساعة' . ($r ? " و{$r} دقيقة" : '')) : ($r . ' دقيقة');
        return $m > 0
            ? "ساعة قاعدة البيانات متأخرة {$txt} عن ساعة النظام (بنصلّحها في العرض)."
            : "ساعة قاعدة البيانات مقدّمة {$txt} عن ساعة النظام (بنصلّحها في العرض).";
    }
}
