<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use RuntimeException;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * سطور الإيصالات (v109) — **الإيصال الواحد بسعره وعربيته**
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * البند في `permit_stages` كان بيوصف نفسه بـ`amount` (إجمالي) + `qty` (عدد)،
 * والسعر مشتقّ بالقسمة. الافتراض ده — إن الـ١٢ إيصال نسخ من إيصال واحد —
 * **غلط**: كل إيصال هيئة بسعره. الموديل ده بيخزّن السعر الحقيقي لكل إيصال
 * ويربطه بعربيته.
 *
 * ⚠️⚠️ **المصدر المالي الوحيد يفضل `permit_stages.amount`.** الخزنة والتقفيل
 *    والعهدة والفاتورة كلهم بيقروا منه زي ما هُمّ. السطور **بتفصّل** الإجمالي:
 *    `sync()` بترفض أي قايمة مجموعها ≠ الإجمالي، فمستحيل التفصيل يخالف المال.
 *    (`verify_v109` بيفشل لو أي ملف مالي ذكر الجدول.)
 *
 * ⚠️ **`sync()` هي الكاتب الوحيد.** أي مسار تاني بيغيّر `amount`/`qty` لبند
 *    ليه سطور لازم ينده `onStageChanged()` بعده — وإلا التفصيل بيقول حاجة
 *    والمال بيقول حاجة تانية **بلا أي خطأ ظاهر**، وده بالظبط اللي القاعدة ②
 *    في CLAUDE.md موجودة عشانه.
 *
 * ⚠️ **`parse()` هي المصدر الوحيد لتفكيك نص الأسعار** — بتتنادى من بوابة
 *    المندوب ومن صندوق المراجعة ومن فورم الإذن. لو اتكتبت تاني في أي مكان،
 *    الشاشتين بيقبلوا صيغتين مختلفتين لنفس الخانة.
 *
 * ⚠️ **الفاصلة `,` فاصل بين إيصالات — مش فاصل آلاف.** ودي مخالفة مقصودة
 *    لـ`money_unformat()`: «250,300» في الخانة دي **إيصالين** لا مية تلاتين
 *    ألف. الخانة مش `data-money` عن قصد لنفس السبب، والمعاينة الحيّة (العدد
 *    والإجمالي تحت الخانة) هي اللي بتخلّي أي سوء فهم يبان قبل الحفظ.
 */
final class ReceiptLine
{
    /** أقصى عدد إيصالات في البند الواحد — نفس حدّ `qty` في كل النظام. */
    public const MAX = 10000;

    /**
     * ══ قواعد الخانة — **تعريف واحد بينزل للخادم وللموبايل مع بعض** ══
     *
     * ⚠️ الشاشة لازم تقرا نفس النص اللي الخادم هيقراه، وإلا المعاينة الحيّة
     *    تقول «٣ إيصالات · ٩٠٠» والحفظ يرفض — وده أسوأ من عدم وجود معاينة
     *    أصلاً. عشان كده `m/entry.php` بينزّل الثوابت دي بـ`js_json()` بدل ما
     *    يكتب القواعد تاني بجافاسكريبت (نفس منطق زوج `money_unformat` /
     *    `toRaw` الموثّق في CLAUDE.md).
     */
    public const SEPARATORS = ['،', '؛', ';', ',', "\r", "\n", "\t"];
    public const TOKEN_RE   = '^\\d+(\\.\\d{1,2})?$';
    public const MULT_RE    = '^(.+?)\\s*[*x×X]\\s*(\\d+)$';

    private static ?bool $ready = null;

    // ─────────────────────────────────────────────── الجاهزية

    /** الجدول والعمود موجودين؟ (قبل ترحيل v109 الوحدة بتتصرّف كأنها مش موجودة.) */
    public static function ready(): bool
    {
        if (self::$ready !== null) { return self::$ready; }
        try {
            $t = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'receipt_lines'");
            $c = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'rep_submissions'
                    AND column_name = 'lines_json'");
            return self::$ready = ($t > 0 && $c > 0);
        } catch (\Throwable) {
            return self::$ready = false;
        }
    }

    /** تصفير الكاش الساكن — **إجباري في آخر ترحيل v109** (مصيدة v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null;
        return self::ready();
    }

    // ─────────────────────────────────────────────── القراءة والكتابة النصّية

    /**
     * تفكيك نص الأسعار لمصفوفة أرقام — **المصدر الوحيد**.
     *
     * بيقبل: `250 250 400` · `250، 250، 400` · سطر لكل رقم · `250*3` أو
     * `250x3` أو `250×3` (نفس السعر متكرر) · الأرقام العربية.
     *
     * ⚠️ **الضرب بيتفكّ لسطور حقيقية مش بيتخزّن كاختصار**: `250*3` بتبقى
     *    تلات سطور، عشان كل واحد فيهم يقدر ياخد عربية مختلفة بعدين.
     *
     * @return array<int,float> الأسعار بالترتيب اللي اتكتب بيه
     * @throws RuntimeException لو فيه رمز مش رقم، أو سعر سالب، أو العدد تعدّى الحد
     */
    public static function parse(string|array $raw): array
    {
        $s = trim(ar2en_digits(self::rawText($raw)));
        if ($s === '') { return []; }

        // توحيد كل الفواصل الممكنة (مسافة · سطر · فاصلة عربي/إنجليزي · فاصلة منقوطة)
        $s = str_replace(self::SEPARATORS, ' ', $s);
        $s = str_replace('٫', '.', $s);                       // الفاصلة العشرية العربية
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        $out = [];
        foreach (explode(' ', trim($s)) as $tok) {
            if ($tok === '') { continue; }

            // `250*3` — نفس السعر متكرر
            $times = 1;
            if (preg_match('/' . self::MULT_RE . '/u', $tok, $m)) {
                $tok   = $m[1];
                $times = (int) $m[2];
                if ($times < 1) { throw new RuntimeException('عدد التكرار لازم يكون ١ على الأقل.'); }
            }

            if (!preg_match('/' . self::TOKEN_RE . '/', $tok)) {
                throw new RuntimeException("«{$tok}» مش رقم — اكتب الأسعار بأرقام والمسافة بينهم.");
            }
            $val = round((float) $tok, 2);

            for ($i = 0; $i < $times; $i++) {
                $out[] = $val;
                if (count($out) > self::MAX) {
                    throw new RuntimeException('عدد الإيصالات أكبر من ' . self::MAX . '.');
                }
            }
        }

        return $out;
    }

    /**
     * أي شكل جاي من الفورم → نص واحد جاهز لـ`parse()`.
     *
     * ⚠️⚠️ **الخانات بتتبعت مصفوفة (`amounts[]`) — و`(string)` عليها بتدّي
     *    «Array» مع تحذير PHP.** ودي نفس عيلة الباج اللي وقّع النسخة اللي
     *    فاتت (`postedData` بتقصّ الحقل): القيمة بتوصل بشكل مش متوقَّع
     *    وبتتحوّل لحاجة تانية **بلا خطأ ظاهر**، والمندوب يشوف رسالة غلط
     *    مالهاش علاقة. الدالة دي هي **نقطة الالتقاء الوحيدة** للشكلين.
     */
    public static function rawText(string|array $raw): string
    {
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $v) {
                if (is_array($v)) { continue; }              // تعشيش مش متوقَّع — بيتتجاهل
                $v = trim((string) $v);
                if ($v !== '') { $parts[] = $v; }
            }
            return implode(' ', $parts);
        }
        return $raw;
    }

    /** المصفوفة → نص جاهز للخانة («250 250 400»). */
    public static function format(array $amounts): string
    {
        return implode(' ', array_map(self::num(...), $amounts));
    }

    /**
     * زي `format()` بس بيلمّ التكرار المتتالي في `250×12`.
     *
     * ⚠️ **ده اللي بيخلّي الخانة صالحة للبند الكبير**: ١٢ إيصال بنفس السعر
     *    مكتوبين بالحرف بيملوا الشاشة على الموبايل، والمندوب اللي جاي يعدّل
     *    رقم واحد بيتوه وسطهم. الاختصار بيتفكّ لسطور حقيقية تاني في `parse()`.
     */
    public static function formatCompact(array $amounts): string
    {
        $out = [];
        $i   = 0;
        $n   = count($amounts);
        $vals = array_values($amounts);

        while ($i < $n) {
            $run = 1;
            while ($i + $run < $n && abs((float) $vals[$i + $run] - (float) $vals[$i]) < 0.005) { $run++; }
            $out[] = $run >= 3 ? self::num($vals[$i]) . '×' . $run
                               : implode(' ', array_fill(0, $run, self::num($vals[$i])));
            $i += $run;
        }
        return implode(' ', $out);
    }

    /** رقم بلا أصفار زايدة («250» مش «250.00»، و«12.5» تفضل «12.5»). */
    private static function num($a): string
    {
        $s = number_format((float) $a, 2, '.', '');
        return str_contains($s, '.') ? (rtrim(rtrim($s, '0'), '.') ?: '0') : $s;
    }

    /**
     * فكّ `rep_submissions.lines_json` → مصفوفة أسعار، أو `null` لو مافيش تفصيل.
     *
     * ⚠️ **بيرجّع `null` بدل ما يرمي** على أي JSON بايظ: البند لسه في صندوق
     *    الانتظار وله إجمالي سليم، فالتفصيل الضايع بيرجّعه لبند بلا تفصيل —
     *    مش بيوقّف اعتماده.
     */
    public static function decode($json): ?array
    {
        if (!is_string($json) || trim($json) === '') { return null; }
        $arr = json_decode($json, true);
        if (!is_array($arr) || !$arr) { return null; }

        $out = [];
        foreach ($arr as $a) {
            if (!is_numeric($a)) { return null; }
            $out[] = round((float) $a, 2);
        }
        return $out;
    }

    /**
     * ملخّص مقروء للأسعار المتكرّرة: «٣×١٨٠ · ٢×٢٥٠ · ١×٤٠٠».
     *
     * ⚠️ **ده بديل «الكمية × السعر» مش زينة جنبه.** أي شاشة بتعرض بندًا ليه
     *    أسعار مختلفة **ممنوع** تعرض سعر وحدة، لأنه متوسط مالوش وجود في الواقع
     *    (١٢ × ٢٣٩٫١٧ = رقم ما دفعوش حد).
     */
    public static function summary(array $amounts, int $max = 4): string
    {
        if (!$amounts) { return ''; }

        $counts = [];
        foreach ($amounts as $a) {
            $k = (string) round((float) $a, 2);
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        arsort($counts);

        $parts = [];
        $shown = 0;
        foreach ($counts as $price => $n) {
            if ($shown >= $max) {
                $parts[] = '+' . (count($counts) - $shown) . ' غيرهم';
                break;
            }
            $parts[] = $n . '×' . number_format((float) $price, 2, '.', ',');
            $shown++;
        }
        return implode(' · ', $parts);
    }

    /** الأسعار مختلفة فعلاً؟ (بند بسعر واحد متكرر ما بيحتاجش عرض مفصّل.) */
    public static function varied(array $amounts): bool
    {
        return count(array_unique(array_map(static fn ($a) => (string) round((float) $a, 2), $amounts))) > 1;
    }

    // ─────────────────────────────────────────────── السطور

    /** سطور بند مرتّبة، ومعاها بيانات العربية. */
    public static function forStage(int $stageId): array
    {
        if (!self::ready() || $stageId <= 0) { return []; }
        return Database::all(
            "SELECT l.*, b.car_no, b.driver, b.bill_number
               FROM receipt_lines l
               LEFT JOIN bawalis b ON b.id = l.bawali_id
              WHERE l.stage_id = ?
              ORDER BY l.seq", [$stageId]);
    }

    /** أسعار بند كمصفوفة أرقام (فاضية = البند مالوش تفصيل). */
    public static function amountsOf(int $stageId): array
    {
        return array_map(static fn ($r) => (float) $r['amount'], self::forStage($stageId));
    }

    /** سطور كذا بند مرة واحدة — `stage_id => rows` (بيمنع N+1 في القوايم). */
    public static function forStages(array $stageIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $stageIds)));
        $ids = array_values(array_filter($ids, static fn ($i) => $i > 0));
        if (!self::ready() || !$ids) { return []; }

        $in  = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::all(
            "SELECT l.*, b.car_no, b.driver, b.bill_number
               FROM receipt_lines l
               LEFT JOIN bawalis b ON b.id = l.bawali_id
              WHERE l.stage_id IN ($in)
              ORDER BY l.stage_id, l.seq", $ids) as $r) {
            $out[(int) $r['stage_id']][] = $r;
        }
        return $out;
    }

    /**
     * **الكاتب الوحيد.** بيكتب قايمة أسعار البند ويحافظ على ربط العربيات.
     *
     * ⚠️ **الحارس الحاكم: مجموع السطور لازم يساوي `permit_stages.amount`
     *    بالقرش، وعددها يساوي `qty`.** من غير الشرطين دول الجدول ده بيبقى
     *    «حقيقة تانية» جنب المال — وأول ما يختلفوا، كارت العربية بيقول رقم
     *    وكشف الإذن بيقول رقم تاني بلا أي خطأ ظاهر.
     *
     * ⚠️ **ربط العربية بيتحفظ بالترتيب (`seq`) لا بالـ`id`**: الكتابة بتمسح
     *    وتعيد، فلو اتعلّقنا بالـid كان كل تعديل في سعر هيفكّ كل العربيات.
     */
    public static function sync(int $stageId, array $amounts, ?string $user = null): void
    {
        if (!self::ready()) { return; }

        $stage = Database::row(
            'SELECT id, stage_key, amount, qty FROM permit_stages WHERE id = ?', [$stageId]);
        if (!$stage) { throw new RuntimeException('البند مش موجود.'); }

        if (!$amounts) { self::forget($stageId); return; }

        foreach ($amounts as $a) {
            if ((float) $a < 0) { throw new RuntimeException('سعر الإيصال ما يصحّش يكون سالبًا.'); }
        }
        if (count($amounts) > self::MAX) {
            throw new RuntimeException('عدد الإيصالات أكبر من ' . self::MAX . '.');
        }

        $sum = round(array_sum(array_map('floatval', $amounts)), 2);
        if (abs($sum - round((float) $stage['amount'], 2)) > 0.005) {
            throw new RuntimeException(sprintf(
                'مجموع الأسعار %s والبند %s — لازم يتساووا.',
                number_format($sum, 2, '.', ','),
                number_format((float) $stage['amount'], 2, '.', ',')));
        }
        if (count($amounts) !== (int) $stage['qty']) {
            throw new RuntimeException(sprintf(
                'عدد الأسعار %d وعدد الإيصالات في البند %d — لازم يتساووا.',
                count($amounts), (int) $stage['qty']));
        }

        // الربط القديم بالترتيب — عشان تعديل سعر ما يفكّش العربيات
        $keep = [];
        foreach (Database::all(
            'SELECT seq, bawali_id, ref_no FROM receipt_lines WHERE stage_id = ?', [$stageId]) as $r) {
            $keep[(int) $r['seq']] = [
                'bawali_id' => $r['bawali_id'] !== null ? (int) $r['bawali_id'] : null,
                'ref_no'    => $r['ref_no'],
            ];
        }

        $by = $user ?? Auth::user();
        Database::exec('DELETE FROM receipt_lines WHERE stage_id = ?', [$stageId]);
        foreach (array_values($amounts) as $i => $a) {
            $seq = $i + 1;
            Database::exec(
                'INSERT INTO receipt_lines (stage_id, seq, amount, bawali_id, ref_no, created_by, updated_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$stageId, $seq, round((float) $a, 2),
                 $keep[$seq]['bawali_id'] ?? null, $keep[$seq]['ref_no'] ?? null, $by, $by]);
        }
    }

    /** مسح تفصيل بند (بيرجع «بند بلا تفصيل» — المال ما بيتلمسش). */
    public static function forget(int $stageId): void
    {
        if (!self::ready()) { return; }
        Database::exec('DELETE FROM receipt_lines WHERE stage_id = ?', [$stageId]);
    }

    /**
     * تفصيل بند قديم مالوش سطور — بيتولّد بالتساوي من إجماليه.
     *
     * ⚠️ **القسمة بـ`Payroll::split()`** (المصدر الوحيد للقسمة بلا فقد قرش):
     *    `round(amount/qty, 2) × qty` ما بيرجّعش الإجمالي لما القسمة ما تنزلش
     *    صحيحة — وده بالظبط اللي كان هيخلّي الحارس فوق يرفض بندًا سليمًا.
     *
     * بيرجع السطور بعد التوليد. بيسيب البند زي ما هو لو عنده سطور خلاص.
     */
    public static function ensureFor(int $stageId): array
    {
        if (!self::ready()) { return []; }

        $existing = self::forStage($stageId);
        if ($existing) { return $existing; }

        $stage = Database::row(
            'SELECT id, stage_key, amount, qty FROM permit_stages WHERE id = ?', [$stageId]);
        if (!$stage || (string) $stage['stage_key'] !== 'receipts') { return []; }

        $qty = max(1, (int) $stage['qty']);
        if ($qty > self::MAX) { return []; }

        self::sync($stageId, Payroll::split((float) $stage['amount'], $qty));
        return self::forStage($stageId);
    }

    /**
     * **المصالِح** — بيتنادى بعد أي تعديل خارجي على `amount`/`qty` لبند ليه سطور.
     *
     * لو التفصيل بقى مخالفًا للمال (المالك عدّل الإجمالي أو الكمية من مسار
     * تاني)، بيتعاد بناؤه بالتساوي **مع الحفاظ على ربط العربيات بالترتيب**.
     * الأسعار المفصّلة بتضيع في الحالة دي — وده مقصود: التفصيل اللي بيخالف
     * المال أسوأ من عدم وجوده، لأنه بيتعرض كأنه حقيقة.
     */
    public static function onStageChanged(int $stageId): void
    {
        if (!self::ready()) { return; }

        $rows = Database::all('SELECT amount FROM receipt_lines WHERE stage_id = ?', [$stageId]);
        if (!$rows) { return; }                       // بند بلا تفصيل — مفيش حاجة تتصالح

        $stage = Database::row('SELECT amount, qty, stage_key FROM permit_stages WHERE id = ?', [$stageId]);
        if (!$stage) { return; }                      // اتمسح — الـCASCADE شال سطوره

        $sum = round(array_sum(array_map(static fn ($r) => (float) $r['amount'], $rows)), 2);
        $qty = max(1, (int) $stage['qty']);
        if (abs($sum - round((float) $stage['amount'], 2)) <= 0.005 && count($rows) === $qty) {
            return;                                   // لسه مطابق
        }

        if ((string) $stage['stage_key'] !== 'receipts' || $qty > self::MAX) {
            self::forget($stageId);
            return;
        }
        self::sync($stageId, Payroll::split((float) $stage['amount'], $qty));
    }

    /**
     * **نقطة واحدة بعد أي حفظ لبند إيصالات**: بتولّد التفصيل لو مش موجود،
     * وبتصالحه لو موجود.
     *
     * ⚠️ **كل بند `receipts` لازم يبقى له سطور — من أي مسار دخل.** لو مسار
     *    (إضافة سريعة · فورم الإذن · اعتماد مندوب) ما ولّدش سطوره، عدّاد
     *    «لسه مش متوزّع» بيقلّ **بالسكوت** وبيقول للمالك إن كله اتوزّع
     *    وهو لأ — وده أسوأ من غياب العدّاد أصلاً.
     */
    public static function refreshFor(int $stageId): void
    {
        if (!self::ready() || $stageId <= 0) { return; }

        $has = (int) Database::scalar(
            'SELECT COUNT(*) FROM receipt_lines WHERE stage_id = ?', [$stageId]);
        if ($has > 0) { self::onStageChanged($stageId); } else { self::ensureFor($stageId); }
    }

    // ─────────────────────────────────────────────── التوزيع على العربيات

    /**
     * ربط سطر بعربية (بوليصة) — أو فكّ الربط بـ`null`.
     *
     * ⚠️ **الحارس في الموديل مش في الشاشة** (القاعدة ③): البوليصة لازم تكون
     *    تابعة **لنفس إذن** البند. من غير الفحص ده، قيمة مزوّرة في الفورم
     *    بتحمّل عربية إذن تاني مصروف مالهاش.
     */
    public static function assign(int $lineId, ?int $bawaliId, ?string $user = null): void
    {
        if (!self::ready()) { return; }

        $line = Database::row(
            'SELECT l.id, l.stage_id, s.permit_id
               FROM receipt_lines l JOIN permit_stages s ON s.id = l.stage_id
              WHERE l.id = ?', [$lineId]);
        if (!$line) { throw new RuntimeException('سطر الإيصال مش موجود.'); }

        if ($bawaliId !== null) {
            $ok = Database::scalar(
                'SELECT COUNT(*) FROM bawalis WHERE id = ? AND permit_id = ?',
                [$bawaliId, (int) $line['permit_id']]);
            if (!$ok) { throw new RuntimeException('العربية دي مش من عربيات الإذن ده.'); }
        }

        Database::exec(
            'UPDATE receipt_lines SET bawali_id = ?, updated_by = ? WHERE id = ?',
            [$bawaliId, $user ?? Auth::user(), $lineId]);
    }

    /**
     * توزيع بند كامل مرة واحدة — `[line_id => bawali_id|0]`.
     * `0`/فاضي = فكّ الربط. بيرجع عدد السطور اللي اتغيّرت فعلاً.
     */
    public static function assignMany(int $stageId, array $map, ?string $user = null): int
    {
        if (!self::ready()) { return 0; }

        $mine = [];
        foreach (Database::all(
            'SELECT id, bawali_id FROM receipt_lines WHERE stage_id = ?', [$stageId]) as $r) {
            $mine[(int) $r['id']] = $r['bawali_id'] !== null ? (int) $r['bawali_id'] : null;
        }

        $n = 0;
        foreach ($map as $lineId => $bawaliId) {
            $lineId = (int) $lineId;
            if (!array_key_exists($lineId, $mine)) { continue; }   // مش من سطور البند ده
            $target = (int) $bawaliId ?: null;
            if ($mine[$lineId] === $target) { continue; }          // ما اتغيّرش
            self::assign($lineId, $target, $user);
            $n++;
        }
        return $n;
    }

    /** عربيات الإذن جاهزة للاختيار (رقم العربية + السائق). */
    public static function carsOfPermit(int $permitId): array
    {
        if ($permitId <= 0) { return []; }
        $rows = Database::all(
            "SELECT id, car_no, driver, bill_number, bill_date, container_no
               FROM bawalis
              WHERE permit_id = ? AND is_legacy = 0
              ORDER BY bill_date, id", [$permitId]);

        foreach ($rows as &$r) {
            $bits = [];
            if (trim((string) $r['car_no']) !== '') { $bits[] = trim((string) $r['car_no']); }
            if (trim((string) $r['driver']) !== '') { $bits[] = trim((string) $r['driver']); }
            if (!$bits) { $bits[] = 'بوليصة ' . (trim((string) $r['bill_number']) ?: '#' . (int) $r['id']); }
            $r['label'] = implode(' · ', $bits);
        }
        return $rows;
    }

    /** إيصالات عربية واحدة (كل سطر معاه نوع بنده وتاريخه). */
    public static function forBawali(int $bawaliId): array
    {
        if (!self::ready() || $bawaliId <= 0) { return []; }
        return Database::all(
            "SELECT l.id, l.amount, l.ref_no, l.seq,
                    s.id AS stage_id, s.note, s.entry_date, s.permit_id
               FROM receipt_lines l
               JOIN permit_stages s ON s.id = l.stage_id
              WHERE l.bawali_id = ?
              ORDER BY s.entry_date, s.id, l.seq", [$bawaliId]);
    }

    /** إجمالي إيصالات عربية واحدة. */
    public static function totalForBawali(int $bawaliId): float
    {
        if (!self::ready() || $bawaliId <= 0) { return 0.0; }
        return round((float) Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM receipt_lines WHERE bawali_id = ?', [$bawaliId]), 2);
    }

    // ─────────────────────────────────────────────── «لسه مش متوزّع»

    /**
     * أنواع الإيصالات اللي التوزيع على العربيات يهمّ فيها — **المصدر الوحيد**.
     *
     * ⚠️ **إعداد مش ثابت في الكود**: المالك قال «الجيش والهيئة» النهارده،
     *    وأول ما نوع تالت يبقى مهم المفروض يزوّده من `/settings` مش يستنّى
     *    نسخة. والقايمة الفاضية = **صفر إلحاح** (الوحدة بتشتغل، بس مفيش عدّاد).
     */
    public static function carKinds(): array
    {
        /* ⚠️ **بلا افتراضي هنا عن قصد.** `Settings::get($k, $d)` بتعمل
           `$d ?? defaultOf($k)`، و`''` **مش** null — فتمرير فراغ كان بيدهس
           افتراضي السجل («هيئة · جيش») ويطفّي العدّاد بالسكوت. */
        $raw = (string) Settings::get('receipt_car_kinds');
        $out = [];
        foreach (preg_split('/[\r\n,،]+/u', $raw) ?: [] as $k) {
            $k = trim($k);
            if ($k !== '') { $out[] = $k; }
        }
        return $out;
    }

    /**
     * البند ده توزيعه على العربيات يهمّ؟ — بيمرّ على `ReceiptKind::of()`
     * عشان «هييه» و«٢ ايصال هيئة» و«هيئة صادر» كلهم يتعرفوا نوعًا واحدًا.
     */
    public static function needsCar(?string $note): bool
    {
        $kinds = self::carKinds();
        if (!$kinds || $note === null) { return false; }
        $kind = ReceiptKind::of($note);
        return $kind !== null && in_array($kind, $kinds, true);
    }

    /**
     * عدد الإيصالات اللي لسه مش متوزّعة في إذن — للأنواع المهمّة بس.
     *
     * ⚠️ **العدّ على السطور مش على البنود**: بند فيه ١٢ إيصال اتوزّع منهم ٨
     *    لسه ناقصه ٤ — ولو عدّينا بالبنود كان هيبان «متوزّع» أو «مش متوزّع»
     *    وخلاص، والمالك ما كانش هيعرف إن فيه أربعة ضايعين.
     */
    public static function pendingCarsForPermit(int $permitId): int
    {
        if (!self::ready() || $permitId <= 0) { return 0; }

        $rows = Database::all(
            "SELECT s.note, COUNT(*) AS n
               FROM receipt_lines l
               JOIN permit_stages s ON s.id = l.stage_id
              WHERE s.permit_id = ? AND s.stage_key = 'receipts' AND l.bawali_id IS NULL
              GROUP BY s.note", [$permitId]);

        $n = 0;
        foreach ($rows as $r) {
            if (self::needsCar((string) $r['note'])) { $n += (int) $r['n']; }
        }
        return $n;
    }
}
