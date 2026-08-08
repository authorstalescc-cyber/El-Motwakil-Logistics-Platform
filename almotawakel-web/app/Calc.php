<?php
namespace App;

use App\Core\App;

/**
 * محرّك الحسابات — المصدر الوحيد للمعادلات.
 * كل دالة تستقبل صف بيانات (المدخلات) وتُعيد نفس الصف مضافاً إليه الحقول المحسوبة،
 * مطابقةً تماماً لمعادلات نظام الإكسل الأصلي.
 */
class Calc
{
    /**
     * أعمدة مصروفات السيارة الـ١٢ في `bawalis` (بنود نظام الإكسل القديم).
     *
     * ⚠️ **المصدر الوحيد للقايمة دي** — بيقراها `Calc::bawala` (إجمال المصروف)
     *    و`OfficeCar` (مصاريف البوالص في أداء السيارة) و`Schema`/الفاحص. لو
     *    اتكتبت مرة تانية في أي مكان، إضافة بند جديد بتدخل في حساب وتغيب عن
     *    التاني فالأرقام تختلف **بلا أي خطأ ظاهر**.
     */
    public const EXPENSE_COLS = [
        'driver_share', 'assistant_share', 'gas', 'kawtsh', 'z_kawtsh', 'tahia_masr',
        'thabtan', 'permits', 'cards', 'overnight_expense', 'expense', 'other_expense',
    ];

    private static function n($v): float
    {
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /**
     * البوالص — دين البوليصة على العميل.
     * دين البوليصة = النولون − العهدة − التحويلة − الخصم + البياتة
     * (المدفوع/المتبقي/الحالة تُحسب من جدول الدفعات عبر App\Models\Bill)
     *
     * ★ قاعدة النولون المؤجَّل: النولون قد يُدخَل في مرحلة لاحقة. البوليصة التي لم يُدخَل
     *   نولونها بعد ليست ديناً — فالخصومات (عهدة/تحويلة/خصم) وحدها كانت تُنتج ديناً سالباً
     *   لا معنى له. لذا: نولون = 0 ⇒ دين = 0 (تُعامَل كبوليصة «بانتظار النولون» في Bill).
     */
    public static function bawala(array $r): array
    {
        $freight  = self::n($r['freight'] ?? 0);           // نولون
        $custody  = self::n($r['custody'] ?? 0);           // عهدة
        $transfer = self::n($r['transfer'] ?? 0);          // تحويلة
        $discount = self::n($r['discount'] ?? 0);          // خصم
        $overn    = self::n($r['overnight_collection'] ?? 0); // بياتة

        // بلا نولون مُدخَل ⇒ ليست ديناً بعد (نمنع السالب الناتج عن الخصومات وحدها).
        // كذلك نُثبّت الدين عند صفر لو الخصومات (عهدة/تحويلة/خصم) تجاوزت النولون —
        // فالدين لا يكون سالباً أبداً (قرار صاحب الشركة)، حتى لا يُنقِص إجمالي دَين صاحب البيان.
        $debt = $freight <= 0 ? 0.0 : max(0.0, $freight - $custody - $transfer - $discount + $overn);
        $r['debt'] = round($debt, 2);

        /* ═══ مصروفات السيارة (سيارات المكتب) ═══
           ⚠️ **برّه معادلة الدين تمامًا** — الدين فوق اتحسب خلاص من النولون
              والعهدة والتحويلة والخصم والبياتة وبس. البنود دي تكلفة تشغيل
              عربيتنا، مالهاش أي علاقة بحساب العميل، فما تدخّلهاش في `$debt`.
           ⚠️ المعادلات التلاتة مستخرجة من بيانات نظام الإكسل القديم ومتحقَّق
              منها على ٧٣ صف في `legacy_bawalis` بالمليم. الباقى من العهدة
              **بيطلع سالب عادي** (يعني السواق صرف أكتر من عهدته) — ما تحطّش
              `max(0,…)` عليه زي الدين، ده رقم تشغيلي مش دين. */
        $totalExpense = 0.0;
        foreach (self::EXPENSE_COLS as $col) { $totalExpense += self::n($r[$col] ?? 0); }
        $r['total_expense']     = round($totalExpense, 2);
        $r['custody_remaining'] = round($custody - $totalExpense, 2);
        $r['car_net']           = round($freight - $totalExpense, 2);
        return $r;
    }

    /** التخليص */
    public static function takhlis(array $r): array
    {
        $fees = self::n($r['fees'] ?? 0);
        $exp  = self::n($r['expenses'] ?? 0);
        $pay  = self::n($r['payments'] ?? 0);
        $r['total_statement'] = round($fees + $exp, 2);          // إجمالي الكشف
        $r['remaining']       = round($r['total_statement'] - $pay, 2);  // المتبقي
        return $r;
    }

    /** المولدات */
    public static function generator(array $r): array
    {
        $freight = self::n($r['freight'] ?? 0);
        $pay     = self::n($r['payments'] ?? 0);
        $r['remaining'] = round($freight - $pay, 2);   // المتبقي
        return $r;
    }

    /** شامل (الفواتير) */
    public static function shamil(array $r): array
    {
        $vatRate  = (float) App::setting('vat_rate', 0.14);
        $freight  = self::n($r['freight'] ?? 0);
        $dataIdle = self::n($r['data_idle'] ?? 0);
        $discAts  = self::n($r['discount_ats'] ?? 0);
        $receipts = self::n($r['receipts'] ?? 0);
        $payments = self::n($r['payments'] ?? 0);
        $actual   = self::n($r['actual_freight'] ?? 0);
        $expense  = self::n($r['expense'] ?? 0);

        $vat          = round(($freight + $dataIdle) * $vatRate, 2);          // قيمة مضافة
        $invoiceTotal = $freight + $dataIdle + $vat - $discAts + $receipts;    // إجمالي الفاتورة
        $accountTotal = $invoiceTotal - $payments;                             // إجمالي الحساب
        $profitTotal  = $freight - $actual - $expense;                         // إجمالي ربح

        $r['vat']           = $vat;
        $r['invoice_total'] = round($invoiceTotal, 2);
        $r['account_total'] = round($accountTotal, 2);
        $r['profit_total']  = round($profitTotal, 2);
        return $r;
    }

    /** توجيه حسب اسم الكيان */
    public static function apply(string $entity, array $r): array
    {
        return match ($entity) {
            'bawalis'    => self::bawala($r),
            'takhlis'    => self::takhlis($r),
            'generators' => self::generator($r),
            'shamil'     => self::shamil($r),
            default      => $r,
        };
    }
}
