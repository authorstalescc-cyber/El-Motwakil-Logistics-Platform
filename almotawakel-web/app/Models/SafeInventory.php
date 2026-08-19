<?php
namespace App\Models;

/**
 * جرد الخزينة (v108) — **عدسة قراءة واحدة فوق الخزنتين**، بلا جدول وبلا رقم خاص.
 *
 * ═══ اللزمة (طلب المالك) ═══
 * «وحدة جديدة اسمها جرد خزينة — سواء المكتب أو خزينة الشركة في كشف واحد.»
 *
 * قبلها كان لازم يفتح شاشتين: `/closing` بتقول رصيد الدرج بمعادلة اليوم،
 * و`/company-safe` بتقول رصيد الشركة بكشفها — والجمع بإيده على ورقة.
 *
 * ═══ ⚠️⚠️ القاعدة الحاكمة: **ولا رقم واحد بيتحسب هنا من الأول** ═══
 * كل رقم في الوحدة دي **بيتنادى** من صاحبه:
 *   • درج المكتب وجيوب المناديب  ← `Closing::walletSplit()`
 *   • رصيد المكتب أول/آخر المدة  ← `Closing::openingFor()` / `closingFor()`
 *   • حركة المكتب في الفترة       ← `Closing::rangeMovement()`
 *   • صفوف المكتب                 ← `DailyReview::officeCashItems()`
 *   • خزنة الشركة كلها            ← `CompanySafe::statement()` / `balance()`
 *   • الفترات                     ← `CompanySafe::periodRange()`
 * فمستحيل الشاشة دي تقول رقمًا يخالف شاشة التقفيل أو شاشة خزنة الشركة.
 * **أي معادلة تتكتب هنا بالإيد = رقمان لنفس السؤال، وواحد فيهم هيتقادم.**
 *
 * ═══ ⚠️ التحويلات بين الخزنتين بتتعدّ مرتين — وده مقصود ═══
 * التحويل من الشركة للمكتب بيسيب **صفّين**: `to_office` (خارج من الشركة)
 * و`manual_custody` (داخل للدرج). في **الصافي** ده صح تمامًا (−X ثم +X = صفر
 * على المستوى المجمّع)، لكن في **الإجمالي الخام** بيضخّم «الداخل» و«الخارج»
 * بمقدار X الاتنين. عشان كده الوحدة بترجّع المفتاحين:
 *   • `in_gross`/`out_gross` — مجموع الخزنتين زي ما هو (بيطابق كل شاشة لوحدها)
 *   • `in`/`out`             — بعد شيل التحويلات الداخلية (السؤال الحقيقي:
 *                              «دخل الشركة كام من برّه؟»)
 * وإخفاء الفرق ده كان بيدّي «إجمالي داخل» أكبر من الحقيقة بلا أي خطأ ظاهر.
 *
 * ═══ ⚠️ «خزنة المكتب» معناها بيتغيّر مع النموذج الفعّال ═══
 * `Closing::currentBalance()` = الدرج لوحده في نموذج «الدرج بس»، و**الدرج +
 * جيوب المناديب** في النموذج المجمّع (`RepCustody::bankTopupActive`). عشان كده:
 *   • **العدّ الفعلي** بيتقارن بـ`walletSplit()['drawer']` — الرقم الوحيد اللي
 *     المالك يقدر يعدّه بإيده. المقارنة بالرقم المجمّع كانت هتدّي «عجز» دايم
 *     بمقدار اللي في جيوب المناديب.
 *   • **كشف الفترة** بيستخدم رقم النموذج زي ما هو، والشاشة بتقول صراحةً إنه
 *     شامل جيوب المناديب لما يكون كده.
 */
class SafeInventory
{
    /** الخزنتان اللي بتتجرد — قايمة بيضا، والمفتاح بيتخزَّن في `safe_audits`. */
    public const SAFES = ['office', 'company'];

    /**
     * ⚠️ **سقف أيام الصفوف**: صفوف جهة المكتب بتتبني بنداء `DailyReview` **لكل
     *    يوم** (هي المصدر الوحيد لتصنيف البند، ومفيش نسخة تانية بشرط نطاق).
     *    ٣١ يوم ≈ ٣١ نداء — مقبول، والسنة كانت هتبقى ٣٦٥. الفترة الأطول
     *    بترجّع **الإجماليات كاملة** وبتقول إن الصفوف مقطوعة صراحةً
     *    (**ممنوع قطع صامت**).
     */
    public const MAX_ROW_DAYS = 31;

    /** أقصى عدد صفوف بيتعرض — الزيادة بتتقال بالنص مش بتتشال بالسكوت. */
    public const MAX_ROWS = 800;

    public static function labels(): array
    {
        return ['office' => 'درج المكتب', 'company' => 'خزنة الشركة'];
    }

    /** ⚠️ كل اسم لازم يكون في خريطة `icon()` وإلا بيرسم دايرة فاضية. */
    public static function icons(): array
    {
        return ['office' => 'briefcase', 'company' => 'home'];
    }

    public static function label(string $safe): string
    {
        return self::labels()[$safe] ?? $safe;
    }

    public static function icon(string $safe): string
    {
        return self::icons()[$safe] ?? 'wallet';
    }

    /** ⚠️ الفورم والرابط مش مصدر ثقة — أي مفتاح برّه القايمة بيترفض. */
    public static function isSafe(?string $safe): bool
    {
        return in_array((string) $safe, self::SAFES, true);
    }

    // ───────────────────────────────────────────── الرصيد الدفتري (لحظي)

    /**
     * ⚠️⚠️ **المصدر الوحيد للرقم اللي العدّ الفعلي بيتقارن بيه.** بيقراه
     *    `SafeAudit::save()` والشاشة والفاحص مع بعض — لو الشاشة عرضت رقمًا
     *    والحفظ خزّن رقمًا تاني، الفرق المسجَّل بيبقى كذبًا كامل.
     *
     * ⚠️ المكتب = **الدرج لوحده** لا `currentBalance()`: في النموذج المجمّع
     *    الرقم التاني شامل جيوب المناديب، واللي في جيب المندوب مش في الدرج
     *    عشان المالك يعدّه.
     */
    public static function bookBalance(string $safe): float
    {
        return match ($safe) {
            'office'  => round((float) Closing::walletSplit()['drawer'], 2),
            'company' => round(CompanySafe::balance(), 2),
            default   => 0.0,
        };
    }

    /**
     * لقطة «الكاش فين دلوقتي» — كروت الشاشة الأولى.
     *
     * ⚠️ **تراكمية مش يومية** عن قصد (قاعدة v49: التراكمي هو الحقيقة المالية).
     *    السؤال هنا «أعدّ كام دلوقتي؟» وده مالوش علاقة بيوم العمل المختار.
     *
     * @return array{safes:array<string,array>,reps:float,reps_count:int,grand:float,
     *               office_is_aggregate:bool,wallets:array}
     */
    public static function snapshot(): array
    {
        $w = Closing::walletSplit();
        $safes = [];
        foreach (self::SAFES as $key) {
            $book  = self::bookBalance($key);
            $audit = SafeAudit::latest($key);
            $safes[$key] = [
                'key'     => $key,
                'label'   => self::label($key),
                'icon'    => self::icon($key),
                'book'    => $book,
                'audit'   => $audit,
                /* ⚠️ الفرق بيتحسب على **الرصيد الدفتري وقت الجرد** المخزَّن في
                   الصف، لا على رصيد دلوقتي: الجرد شهادة على لحظة، وإعادة
                   حسابه دلوقتي بتخلّي شهادة امبارح تقول رقمًا تالتًا. */
                'diff'    => $audit ? round((float) $audit['diff'], 2) : null,
                'counted_on' => $audit['audit_date'] ?? null,
            ];
        }
        return [
            'safes'  => $safes,
            'reps'   => round((float) $w['reps_total'], 2),
            'reps_count' => count($w['reps']),
            // ⚠️ `grand` من `walletSplit` نفسها — جمعها هنا كان تعريفًا تانيًا.
            'grand'  => round((float) $w['grand'], 2),
            'office_is_aggregate' => RepCustody::bankTopupActive(),
            'wallets' => $w,
        ];
    }

    // ───────────────────────────────────────────── كشف الفترة الموحّد

    /**
     * **الكشف الواحد**: الخزنتان في ورقة واحدة — أول المدة · داخل · خارج ·
     * آخر المدة لكل واحدة، والمجمّع، وكل الحركات مدموجة زمنيًّا.
     *
     * ⚠️ **`drift`**: للمكتب بس، = (آخر المدة − أول المدة) − صافي الحركة. المفروض
     *    صفر دايمًا؛ لو طلع رقم يبقى فيه **إقفال مثبَّت في `daily_closings`
     *    اتقادم** بعد تعديل على بيانات يوم متقفل. الوحدة بتقوله صراحةً بدل ما
     *    تخبّيه — ده بالظبط نوع الغلط اللي الجرد موجود عشانه. (خزنة الشركة
     *    مالهاش تقفيل فـ`drift` بتاعها صفر بالبناء.)
     *
     * @return array{from:string,to:string,safes:array<string,array>,total:array,
     *               rows:list<array>,rows_capped:bool,rows_total:int,transfers:float,
     *               transfer_count:int,days:int}
     */
    public static function statement(string $from, string $to): array
    {
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        // ── جهة المكتب: كل رقم من `Closing` نفسه
        $oOpen  = round(Closing::openingFor($from), 2);
        $oClose = round(Closing::closingFor($to), 2);
        $oMove  = Closing::rangeMovement($from, $to);

        // ── جهة الشركة: الكشف الجاهز بتاعها بالحرف
        $cs = CompanySafe::statement($from, $to);

        $safes = [
            'office' => [
                'key' => 'office', 'label' => self::label('office'), 'icon' => self::icon('office'),
                'opening' => $oOpen, 'closing' => $oClose,
                'in' => round((float) $oMove['in'], 2), 'out' => round((float) $oMove['out'], 2),
                'net' => round((float) $oMove['net'], 2),
                'drift' => round($oClose - $oOpen - (float) $oMove['net'], 2),
                // تفكيك الخارج — عشان الكارت يقول راح فين بدل رقم مجمّع أصمّ.
                'parts' => ['topups' => round((float) $oMove['topups'], 2),
                            'expenses' => round((float) $oMove['expenses'], 2),
                            'driver' => round((float) $oMove['driver'], 2)],
            ],
            'company' => [
                'key' => 'company', 'label' => self::label('company'), 'icon' => self::icon('company'),
                'opening' => round((float) $cs['opening'], 2), 'closing' => round((float) $cs['closing'], 2),
                'in' => round((float) $cs['in'], 2), 'out' => round((float) $cs['out'], 2),
                'net' => round((float) $cs['in'] - (float) $cs['out'], 2),
                'drift' => 0.0,
                'parts' => ['to_office' => round((float) $cs['to_office'], 2),
                            'expense' => round((float) $cs['expense'], 2),
                            'other_out' => round((float) $cs['other_out'], 2)],
            ],
        ];

        [$rows, $capped, $rowsTotal, $transfers, $transferCount] =
            self::mergedRows($from, $to, $cs['rows']);

        $inGross  = round($safes['office']['in']  + $safes['company']['in'], 2);
        $outGross = round($safes['office']['out'] + $safes['company']['out'], 2);

        $total = [
            'opening'   => round($safes['office']['opening'] + $safes['company']['opening'], 2),
            'closing'   => round($safes['office']['closing'] + $safes['company']['closing'], 2),
            'in_gross'  => $inGross,
            'out_gross' => $outGross,
            // ⚠️ التحويل الداخلي بيتشال من الطرفين — شرحها في ترويسة الكلاس.
            'in'        => round($inGross - $transfers, 2),
            'out'       => round($outGross - $transfers, 2),
            'net'       => round($inGross - $outGross, 2),
        ];
        $total['drift'] = round($total['closing'] - $total['opening'] - $total['net'], 2);

        return [
            'from' => $from, 'to' => $to,
            'days' => self::dayCount($from, $to),
            'safes' => $safes, 'total' => $total,
            'rows' => $rows, 'rows_capped' => $capped, 'rows_total' => $rowsTotal,
            'transfers' => $transfers, 'transfer_count' => $transferCount,
        ];
    }

    private static function dayCount(string $from, string $to): int
    {
        return (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1;
    }

    /**
     * الحركات المدموجة زمنيًّا من الخزنتين.
     *
     * ⚠️ **وسم التحويل الداخلي هو أهم حاجة هنا.** صف `to_office`/`from_office`
     *    في الشركة و**صف العهدة المُولَّد منه** في المكتب حدث واحد بوجهين.
     *    الربط بيتم بـ`custody_id` المخزَّن في صف الشركة (نفس العمود اللي
     *    `CompanySafe::removeLinked` بيمسح بيه) — **مش بمطابقة تاريخ ومبلغ**،
     *    اللي كانت هتوسم أي إضافة عهدة يدوية بنفس المبلغ في نفس اليوم غلطًا.
     *
     * @return array{0:list<array>,1:bool,2:int,3:float,4:int}
     */
    private static function mergedRows(string $from, string $to, array $companyRows): array
    {
        $rows = [];
        $transfers = 0.0; $transferCount = 0;

        // ① صفوف الشركة — جاهزة من `CompanySafe::statement()`
        $custodyIds = [];
        foreach ($companyRows as $r) {
            $kind = (string) $r['kind'];
            $isTransfer = in_array($kind, CompanySafe::OFFICE_KINDS, true);
            if ($isTransfer) {
                $transfers += round((float) $r['amount'], 2);
                $transferCount++;
                if (!empty($r['custody_id'])) { $custodyIds[(int) $r['custody_id']] = true; }
            }
            $party = trim((string) ($r['source'] ?? ''));
            $rows[] = [
                'safe'     => 'company',
                'date'     => (string) $r['entry_date'],
                'icon'     => CompanySafe::icon($kind),
                'title'    => (string) $r['label'],
                'party'    => $party !== '' ? $party : null,
                'note'     => trim((string) ($r['note'] ?? '')) ?: null,
                'extra'    => trim((string) ($r['wallet'] ?? '')) ?: ($r['cat_label'] ?? null),
                'signed'   => round((float) $r['signed'], 2),
                'transfer' => $isTransfer,
                'link'     => 'company-safe?tab=moves&from=' . (string) $r['entry_date']
                            . '&to=' . (string) $r['entry_date'],
            ];
        }

        // ② صفوف المكتب — نداء ليوم بيوم على المصدر الوحيد
        $capped = self::dayCount($from, $to) > self::MAX_ROW_DAYS;
        if (!$capped) {
            for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                foreach (DailyReview::officeCashItems($d) as $it) {
                    $isTransfer = (string) $it['type'] === 'topup'
                                && isset($custodyIds[(int) $it['id']]);
                    if ($isTransfer) { $transferCount++; }
                    $rows[] = [
                        'safe'     => 'office',
                        'date'     => $d,
                        'icon'     => self::groupIcon((string) $it['group']),
                        'title'    => (string) $it['title'],
                        'party'    => ($it['party'] ?? '—') !== '—' ? (string) $it['party'] : null,
                        'note'     => trim((string) ($it['note'] ?? '')) ?: null,
                        'extra'    => trim((string) ($it['ref'] ?? '')) ?: null,
                        'signed'   => round((float) $it['signed'], 2),
                        'transfer' => $isTransfer,
                        'link'     => $it['link'] ?? null,
                    ];
                }
            }
        }

        /* ⚠️ الترتيب **مستقر**: التاريخ ثم الخزنة ثم ترتيب الإدراج — من غير
           الكسر الأخير، صفوف نفس اليوم بتتنطط بين تحميل وتاني والرصيد الجاري
           يقرا مختلفًا في كل فتحة. */
        $seq = 0;
        foreach ($rows as &$r) { $r['seq'] = $seq++; }
        unset($r);
        usort($rows, static fn ($a, $b) =>
            [$a['date'], $a['safe'], $a['seq']] <=> [$b['date'], $b['safe'], $b['seq']]);

        $rowsTotal = count($rows);
        if ($rowsTotal > self::MAX_ROWS) { $rows = array_slice($rows, 0, self::MAX_ROWS); }

        return [$rows, $capped, $rowsTotal, round($transfers, 2), $transferCount];
    }

    /** أيقونة قسم المكتب — ⚠️ الأسماء كلها لازم تكون في خريطة `icon()`. */
    private static function groupIcon(string $group): string
    {
        return match ($group) {
            'topups'      => 'wallet',
            'permits'     => 'file-text',
            'general'     => 'trending-down',
            'custody'     => 'truck',
            'rep_deposit' => 'briefcase',
            default       => 'layers',
        };
    }
}
