<?php
namespace App\Models;

use App\Core\Database;

/**
 * جاهزية الإذن للتقفيل — **المصدر الوحيد** لسؤال «الإذن ده خلص شغله ولا لسه؟».
 *
 * التعريف قرار المالك (2026-08-17):
 *   «خرج كل السيارات الخاصة به **+** الإيصالات من متوقّع الإيصالات مكتملة
 *    مش ناقصها حاجة».
 *
 * يعني شرطان لازم يتحققوا مع بعض:
 *   ① **السيارات خرجت** — مجموع حاويات البوالص وصل لعدد حاويات الإذن.
 *   ② **الإيصالات مكتملة** — مفيش بند «ناقص» ولا «عدده أقل» في مرشد الإيصالات.
 *
 * ⚠️⚠️ **دالة واحدة بتطلّع الحالتين مع بعض عن قصد.** كارت «محتاجة تتقفل»
 *    وقايمة «إيصالات ناقصة» بيقروا من **نفس** المسح — لو كل واحد حسب بنفسه
 *    كان ممكن الكارت يقول ٣ والقايمة تقول ٤ **بلا أي خطأ ظاهر** (وده بالظبط
 *    عطل «كارت يقول رقم وكشف يقول رقم تاني» المحذَّر منه في CLAUDE.md).
 *
 * ⚠️ **الإيصالات بتتحسب من `ReceiptModel::combine()` مش من الموديل لوحده** —
 *    نفس اللي شاشة الإذن وقايمة الأذونات بيعرضوه بالحرف. القاعدة (v32) بتغلب
 *    على صمت الإحصاء، فالبند اللي القاعدة بتقول عنه «مطلوب» بيفضل ناقصًا.
 *
 * ⚠️ **بلا استعلام لكل صف**: البوالص والإيصالات بيتجابوا لكل الأذونات في
 *    استعلامين، وفهارس الموديل كاش ساكن — نفس نمط `PermitController::index`.
 *
 * وحدة **قراءة فقط بالكامل** — مالهاش جدول ولا بتكتب صفًّا واحدًا، وما بتمسّش
 * خزنة ولا تقفيل ولا عهدة (العزل بالبناء — القاعدة ① في CLAUDE.md).
 */
class PermitReadiness
{
    /** الحكم: خلص خالص · فاضل إيصالات · لسه بيشتغل. */
    public const READY    = 'ready';
    public const RECEIPTS = 'receipts';
    public const RUNNING  = 'running';

    /**
     * مسح الأذونات المفتوحة كلها مرة واحدة.
     *
     * ⚠️ `$upto` = احترام يوم العمل (نفس قاعدة `openPermitsList`): المالك وهو
     *    على يوم ٢٦ ما ينفعش يشوف إذنًا تاريخه ٢٨. المتأخرات بتفضل ظاهرة.
     *
     * @return array{
     *   rows:array<int,array>, ready:array<int,array>, gaps:array<int,array>,
     *   counts:array{open:int,ready:int,gaps:int,running:int}
     * }
     */
    public static function scan(?string $upto = null, int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        $rows  = Database::all(
            "SELECT p.*,
                    COALESCE(NULLIF(p.company,''), NULLIF(p.client,''), '—') AS party
               FROM permits p
              WHERE p.status = 'open'"
            . ($upto !== null ? ' AND (p.permit_date IS NULL OR p.permit_date <= ?)' : '')
            . " ORDER BY p.permit_date DESC, p.id DESC
              LIMIT $limit",
            $upto !== null ? [$upto] : []
        );
        if (!$rows) {
            return ['rows' => [], 'ready' => [], 'gaps' => [],
                    'counts' => ['open' => 0, 'ready' => 0, 'gaps' => 0, 'running' => 0]];
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $in  = implode(',', $ids);

        /* ⚠️ `qty` لازمة عشان الموديل يقارن **العدد** (٣ من ٧) — من غيرها أي
           بند مُدخَل بيتحسب «موجود» مهما كان عدده ناقصًا، فالإذن يطلع «جاهز»
           وهو ناقصه إيصالات. (نفس التحذير الموثَّق في `PermitController`.) */
        $receipts = [];
        foreach (Database::all(
            "SELECT permit_id, note, qty FROM permit_stages
              WHERE permit_id IN ($in) AND stage_key = 'receipts' AND status <> 'none'
              ORDER BY entry_date, id") as $r) {
            $receipts[(int) $r['permit_id']][] = $r;
        }

        $counts  = ReceiptModel::countsFor($ids);   // cars = عدد البوالص · conts = مجموع الحاويات
        $modelOn = ReceiptModel::ready();

        $out = ['rows' => [], 'ready' => [], 'gaps' => [],
                'counts' => ['open' => count($rows), 'ready' => 0, 'gaps' => 0, 'running' => 0]];

        foreach ($rows as $p) {
            $pid  = (int) $p['id'];
            $rcp  = $receipts[$pid] ?? [];
            $cars = (int) ($counts[$pid]['cars']  ?? 0);
            $done = (int) ($counts[$pid]['conts'] ?? 0);
            $need = (int) ($p['containers'] ?? 0);

            $guide   = ReceiptModel::combine(
                Permit::receiptGuide($p, $rcp),
                ReceiptModel::predict($p, $rcp, $cars, $done)
            );
            /* ⚠️ «ناقصها حاجة» = **الغايب والناقص عدده** مع بعض. لو حسبنا
               `missing` لوحدها كان الإذن اللي مسجَّل فيه هيئة واحدة والمطلوب
               تلاتة هيعدّي على إنه مكتمل.
               ⚠️ `short` بترجّع **مفاتيح الأنواع** — والمفتاح في `ReceiptKind`
                  هو الاسم المعروض نفسه («هيئة» · «تعتيق»)، فمفيش تحويل. */
            $missing = array_values(array_unique(array_merge(
                $guide['missing'] ?? [],
                $guide['short'] ?? []
            )));

            $row = $p + [
                'cars'        => $cars,
                'conts_done'  => $done,
                'conts_need'  => $need,
                'cars_done'   => self::carsDone($need, $done),
                'missing'     => $missing,
                'missing_cnt' => count($missing),
                'model_on'    => $modelOn,
            ];
            $row['verdict'] = self::verdict($row);
            $row['reason']  = self::reason($row);

            $out['rows'][] = $row;
            if ($row['verdict'] === self::READY)    { $out['ready'][] = $row; $out['counts']['ready']++; }
            elseif ($row['verdict'] === self::RECEIPTS) { $out['gaps'][] = $row; $out['counts']['gaps']++; }
            else                                    { $out['counts']['running']++; }
        }

        // الأقرب للتقفيل الأول: الأقل نقصًا ثم الأقدم (اللي قاعد من زمان يزعج أكتر)
        usort($out['gaps'], static fn ($a, $b) =>
            [$a['missing_cnt'], $a['permit_date'] ?? '9999'] <=> [$b['missing_cnt'], $b['permit_date'] ?? '9999']);

        return $out;
    }

    /**
     * ① خرجت كل السيارات؟
     *
     * ⚠️ **الإذن اللي مالوش عدد حاويات مسجَّل (`containers = 0`) مش «خلصان»** —
     *    ده إذن ناقص بياناته، والحكم عليه بالاكتمال بيخلّيه يظهر في «محتاج
     *    يتقفل» وهو محدش يعرف أصلاً كان مطلوب منه كام. الشرط بيطلب رقمًا موجبًا.
     */
    public static function carsDone(int $need, int $done): bool
    {
        return $need > 0 && $done >= $need;
    }

    /** ② الحكم النهائي — الشرطان مع بعض. */
    public static function verdict(array $row): string
    {
        if (!($row['cars_done'] ?? false)) { return self::RUNNING; }
        return ($row['missing_cnt'] ?? 0) > 0 ? self::RECEIPTS : self::READY;
    }

    /**
     * سبب الحكم في ≤٦ كلمات — **تحذير وظيفي مش شرح تعليمي** (قانون التصميم ⑤).
     * المالك لازم يعرف الإذن ناقصه إيه من غير ما يفتحه.
     */
    public static function reason(array $row): string
    {
        if (!($row['cars_done'] ?? false)) {
            $need = (int) ($row['conts_need'] ?? 0);
            $done = (int) ($row['conts_done'] ?? 0);
            if ($need <= 0) { return 'عدد الحاويات مش مسجّل'; }
            return 'خرج ' . intf($done) . ' من ' . intf($need);
        }
        $n = (int) ($row['missing_cnt'] ?? 0);
        if ($n === 0) { return 'خلص — جاهز للتقفيل'; }
        $names = array_slice((array) ($row['missing'] ?? []), 0, 2);
        return 'ناقص ' . implode(' · ', $names) . ($n > 2 ? ' +' . intf($n - 2) : '');
    }
}
