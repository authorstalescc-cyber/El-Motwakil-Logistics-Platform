<?php
namespace App\Models;

use App\Core\Database;
use RuntimeException;

/**
 * متابعة المناديب من الموبايل (v47) — شاشة المندوب المشرف `/m/team`.
 *
 * قرار المالك: مندوب واحد (محمد فكري) يتابع باقي المناديب من موبايله — يشوف
 * إدخالاتهم، عهدة كل واحد، صرف كام، فاضل كام، وكل حركة طلعت من العهدة بالتفصيل.
 *
 * ⚠️ **قراءة فقط بالكامل.** مفيش هنا ولا سطر بيكتب: الاعتماد والرفض والتنزيل
 *    وتنزيل العهدة والتسوية كلها فضلت شغل المكتب لوحده (`/rep-custody`).
 *    الموديل ده **ما بيحسبش أي رصيد جديد** — بينادي `RepCustody` و`RepPortal`
 *    بالظبط زي ما كارت المكتب بيعمل، فمستحيل يطلع رقم مختلف عن الكارت.
 *
 * ⚠️ **قاعدة v35 لسه سارية: ممنوع أي مبلغ بيخصّ العميل يوصل شاشة المندوب.**
 *    عشان كده `submissions()` بتختار أعمدة بالاسم و**ما بتقربش من
 *    `rep_submissions.actual_unit`** (سعر العميل اللي بيحطّه المكتب — v37)،
 *    ولا من `permit_stages.actual_amount`. لو زوّدت عمود هنا، راجع ده الأول.
 *
 * ⚠️ صور الإيصالات مش بتتعرض للمشرف: `FileController::submission` بيسمح
 *    لصاحب البند أو لجلسة المكتب بس، وسبناه زي ما هو عن قصد. الشاشة بتوضّح
 *    إن فيه صورة (📷) والمكتب هو اللي بيفتحها.
 */
class RepWatch
{
    /** أقصى عدد بنود بتتحمّل في كشف المندوب الواحد. */
    private const FEED_LIMIT = 80;

    /** أقصى عدد حركات عهدة بتتعرض في التفصيل. */
    private const SPEND_LIMIT = 120;

    // ─────────────────────────────────────────── الصلاحية

    /** هل المندوب ده مشرف؟ (اختصار لـ`Rep::canWatchReps` — مصدر واحد للفحص) */
    public static function allowed(int $viewerId): bool
    {
        return Rep::canWatchReps($viewerId);
    }

    /**
     * ⚠️ الحارس الحقيقي — لازم يتنادى قبل أي قراءة هنا، مش في الكنترولر بس.
     * إخفاء التبويب مش حماية: أي حد يقدر يفتح `/m/team` بإيده.
     */
    public static function assertAllowed(int $viewerId): void
    {
        if (!self::allowed($viewerId)) {
            throw new RuntimeException('مالكش صلاحية تتابع المناديب. كلّم المكتب لو محتاجها.');
        }
    }

    // ─────────────────────────────────────────── نظرة عامة

    /**
     * كل المناديب النشطين + عهدة كل واحد + المستنّي عنده.
     *
     * الأرصدة كلها من `RepCustody::cards()` — نفس المصدر اللي بتقراه صفحة
     * `/rep-custody` بالمليم، فمفيش تعريف تاني للرصيد ينفع يختلف عنها.
     */
    public static function team(int $viewerId): array
    {
        self::assertAllowed($viewerId);
        $empty = ['reps' => [], 'totals' => [
            'deposits' => 0.0, 'spent' => 0.0, 'balance' => 0.0,
            'pending_cnt' => 0, 'pending_sum' => 0.0, 'reps' => 0, 'low' => 0,
        ]];
        if (!RepCustody::available()) { return $empty; }

        $cards = RepCustody::cards(true);
        if (!$cards) { return $empty; }

        $pendCnt  = self::pendingCounts();
        $lastSeen = self::lastActivity();
        // ⚠️ عدّاد بنود **اليوم** لكل مندوب — الشاشة بقت يوم واحد بس (قرار المالك
        //    2026-07-26)، فـ«آخر إدخال ٢٥/٧» كان بيدّي إحساس إن فيه شغل النهاردة
        //    وهو مش كده. استعلام مجمَّع واحد بدل استعلام لكل مندوب.
        $dayEntries = self::entryCountsFor(\App\Core\RepAuth::workDate());
        // v49 — عهدة يوم عمل المشرف نفسه، من نفس مصدر الكارت في المكتب.
        $day    = \App\Core\RepAuth::workDate();
        $dayMap = RepCustody::dayTotalsByRep($day);

        $reps = []; $low = 0;
        $tDep = $tSpent = $tBal = 0.0; $tPendCnt = 0; $tPendSum = 0.0;
        foreach ($cards as $c) {
            $rid = (int) $c['id'];
            // ⚠️ نفس تعريف «المستنّي» بتاع شاشة المندوب نفسه (كاش بس، الفيزا
            //    مش بتتخصم من العهدة) — بننادي المصدر بدل ما نكرّر الشرط.
            $c['pending_cnt'] = $pendCnt[$rid] ?? 0;
            $c['pending_sum'] = RepPortal::pendingAmount($rid);
            $c['pocket']      = round($c['balance'] - $c['pending_sum'], 2);
            $c['last_entry']  = $lastSeen[$rid] ?? null;
            // بنود اليوم بس — هو اللي بيتعرض على الكارت (مش «آخر إدخال» من يوم فات)
            $c['day_entries']     = (int) ($dayEntries['all'][$rid] ?? 0);
            $c['day_pending_cnt'] = (int) ($dayEntries['pending'][$rid] ?? 0);
            $dayRow = $dayMap[$rid] ?? null;
            $c['day']          = $day;
            $c['day_deposits'] = $dayRow['deposits'] ?? 0.0;
            $c['day_spent']    = $dayRow['spent'] ?? 0.0;
            $c['day_balance']  = $dayRow['balance'] ?? 0.0;
            // ⚠️ **«الباقي» اللي المشرف بيشوفه = عهدة اليوم بعد المستنّي.**
            //    قرار المالك: الرقم الكبير على الشاشة عمره ما يكون تراكميًّا —
            //    عهدة المناديب يومية بلا ترحيل (v49)، فرقم من يوم سابق بيتقري
            //    وكأنه فاضل من عهدة النهاردة. و«بعد المستنّي» لأن فلوس البنود
            //    اللي لسه ما اتعتمدتش **خرجت من إيده فعلاً**، فلو ما اتخصمتش
            //    الشاشة بتقول إن معاه فلوس هو أصلاً صرفها.
            //    مفيش تعريف تاني هنا: `pocket` جاية من `dayTotalsByRep` نفسها
            //    اللي بتغذّي شاشة المكتب (`/rep-custody`) بالمليم.
            $c['day_pending'] = $dayRow['pending'] ?? 0.0;
            $c['day_pocket']  = $dayRow['pocket']  ?? 0.0;
            // باقي حقائق اليوم — الكارت بقى يوم واحد بس فما ينفعش يعرض تراكميًّا
            $c['day_returned']     = $dayRow['returned'] ?? 0.0;
            $c['day_transfer_in']  = $dayRow['transfer_in'] ?? 0.0;
            $c['day_transfer_out'] = $dayRow['transfer_out'] ?? 0.0;

            $dep = (float) $c['deposits'];
            $c['pc_spent'] = $dep > 0 ? min(100, round($c['spent'] / $dep * 100, 1)) : 0.0;
            // شريط استهلاك **عهدة اليوم** (المصروف ثم المستنّي) — الشريط لازم
            // يوصف نفس الرقم الكبير، وإلا يوري «خلصت» على رقم يوم تاني.
            $dDep = (float) $c['day_deposits'];
            $c['day_pc_spent']   = $dDep > 0 ? min(100, round($c['day_spent'] / $dDep * 100, 1)) : 0.0;
            $c['day_pc_pending'] = $dDep > 0
                ? min(100 - $c['day_pc_spent'], round($c['day_pending'] / $dDep * 100, 1)) : 0.0;
            // مندوب متسوّي حسابه (رجّع الباقي ومفيش عنده مستنّي) — رصيده صفر
            // **مش لأن عهدته خلصت** فما ينفعش يتحسب «محتاج تنزيل» ويزعّق للمشرف.
            // ⚠️ `returned` ممكن يكون سالبًا بعد تصفية رصيد سالب (المكتب دفع له)
            //    — الشرط بالقيمة المطلقة عشان الحالة دي تتحسب «متسوّي» برضه.
            $c['settled'] = ($c['balance'] <= 0.005 && abs((float) $c['returned']) > 0.005 && $c['pending_cnt'] === 0);
            // «عهدته قربت تخلص» — على **عهدة اليوم** زي الرقم المعروض: فاضل أقل
            // من 15% من اللي نزل له في اليوم ده (مندوب ما نزلش له اليوم = مفيش
            // إنذار، مش «خلصت عهدته»).
            $c['low'] = (!$c['settled'] && $dDep > 0 && $c['day_pocket'] < $dDep * 0.15);
            if ($c['low']) { $low++; }

            $tDep    += $dep;
            $tSpent  += (float) $c['spent'];
            $tBal    += (float) $c['balance'];
            $tPendCnt += $c['pending_cnt'];
            $tPendSum += $c['pending_sum'];

            // اللي عليه حركة بس — المندوب اللي عمره ما نزلت له عهدة مالوش لازمة هنا.
            //
            // ⚠️ **الرصيد شرط دخول برضه** — من غيره المندوب اللي كل حركته «تحويل
            //    من زميل» (مفيش تنزيل ولا صرف ولا مستني) بيختفي من القايمة
            //    و**رصيده بيفضل في الإجمالي**، فالشاشة تقول رقمًا ومجموع صفوفها
            //    يقول رقمًا تاني. اتكشف على الإنتاج بعد خط الفصل: محمد جمال معاه
            //    17,400 من تحويل وكان مش ظاهر (`verify_v47` مسكها).
            if ($dep > 0 || $c['spent'] > 0 || $c['pending_cnt'] > 0
                || abs((float) $c['balance']) > 0.005) { $reps[] = $c; }
        }

        // الأهم فوق: المحتاج تنزيل، ثم اللي عنده بنود مستنّية، ثم الأكبر في
        // عهدة اليوم (نفس الرقم المعروض — الترتيب لازم يوافق اللي العين شايفاه)
        usort($reps, function ($a, $b) {
            if ($a['low'] !== $b['low']) { return $a['low'] ? -1 : 1; }
            if ($a['pending_cnt'] !== $b['pending_cnt']) { return $b['pending_cnt'] <=> $a['pending_cnt']; }
            return $b['day_pocket'] <=> $a['day_pocket'];
        });

        return [
            'reps'   => $reps,
            'totals' => [
                'deposits'    => round($tDep, 2),
                'spent'       => round($tSpent, 2),
                'balance'     => round($tBal, 2),
                'pending_cnt' => $tPendCnt,
                'pending_sum' => round($tPendSum, 2),
                'reps'        => count($reps),
                'low'         => $low,
                'day'          => $day,
                'day_deposits' => round(array_sum(array_column($reps, 'day_deposits')), 2),
                'day_spent'    => round(array_sum(array_column($reps, 'day_spent')), 2),
                'day_balance'  => round(array_sum(array_column($reps, 'day_balance')), 2),
                'day_pending'  => round(array_sum(array_column($reps, 'day_pending')), 2),
                'day_pocket'   => round(array_sum(array_column($reps, 'day_pocket')), 2),
            ],
        ];
    }

    // ─────────────────────────────────────────── تفصيل مندوب واحد

    /**
     * كل حاجة عن مندوب واحد **في يوم عمل المشرف وبس**: عهدة اليوم، والحركات اللي
     * طلعت منها فيه، وتنزيلاته فيه، وبنوده اللي اتسجّلت عليه.
     * بترجّع null لو المندوب مش موجود (أو قبل migrate_v35).
     *
     * ⚠️⚠️ **قرار المالك (2026-07-26): الشاشة دي يوم واحد بس — ولا بند من يوم
     *    تاني.** قبل كده كانت بتعرض كل الحركات وكل الإدخالات وكل التنزيلات على
     *    كل الأيام، فالمشرف بيفتح الشاشة ويلاقي بنود أسبوع فوق بعض ومايعرفش
     *    أنهي بند بتاع النهاردة — «مشتت». الفلترة كلها بتمرّ على **معامل `$day`
     *    الموجود أصلاً** في `RepCustody::spending/deposits` (v49) فمفيش شرط
     *    تاريخ مكتوب بالإيد ولا استعلام جديد.
     *    نفس قاعدة شاشة المندوب نفسه `/m` بالحرف (v49 §9ب).
     *
     * ⚠️ الأرقام التراكمية (`b` · `pending` · `pocket`) **باقية في الحزمة** لأنها
     *    الحقيقة المالية وأساس التسوية وبيقراها `verify_v47`، لكن **الشاشة مش
     *    بتعرضها** — العرض كله من مفاتيح `day_*`.
     */
    public static function rep(int $viewerId, int $repId): ?array
    {
        self::assertAllowed($viewerId);
        if (!RepCustody::available()) { return null; }
        $rep = Database::row("SELECT id, name, phone, status FROM reps WHERE id = ?", [$repId]);
        if (!$rep) { return null; }

        $b = RepCustody::breakdown($repId);
        $pending = RepPortal::pendingAmount($repId);
        $day = \App\Core\RepAuth::workDate();
        // ⚠️ **الرقم الكبير في الشاشة = `day_pocket['pocket']`** (عهدة اليوم بعد
        //    المستنّي). المصدر هو `RepPortal::dayPocket` نفسها اللي بتغذّي شاشة
        //    المندوب `/m` بالمليم، فمستحيل الشاشتين يقولوا رقمين.
        $dayPocket = RepPortal::dayPocket($repId, $day);

        // تحويلات وتسويات اليوم بس — الفلترة على العمود المؤرَّخ بتاع كل مصدر
        $transfersIn = [];
        if (RepTransfer::available()) {
            $transfersIn = array_values(array_filter(
                RepTransfer::incomingFor($repId, 60),
                static fn (array $t): bool => (string) ($t['transfer_date'] ?? '') === $day));
        }
        $settlements = array_values(array_filter(
            RepCustody::settlements($repId),
            static fn (array $s): bool => (string) ($s['settle_date'] ?? '') === $day));

        return [
            'rep'          => $rep,
            'b'            => $b,                                    // تراكمي — للحساب لا للعرض
            'day'          => $day,
            'day_b'        => RepCustody::breakdown($repId, $day),   // v49 — عهدة يوم العمل
            'day_pocket'   => $dayPocket,                            // عهدة اليوم بعد المستنّي
            'pending'      => $pending,                              // تراكمي — يقراه verify_v47
            'pending_cnt'  => self::pendingCounts()[$repId] ?? 0,
            'pocket'       => round($b['balance'] - $pending, 2),     // تراكمي — أساس التسوية
            'spending'     => array_slice(RepCustody::spending($repId, $day), 0, self::SPEND_LIMIT),
            'deposits'     => RepCustody::deposits($repId, $day),
            'transfers_in' => $transfersIn,
            'settlements'  => $settlements,
            'submissions'  => self::submissions($repId, '', $day),
        ];
    }

    /**
     * بنود المندوب زي ما هي في صندوق الانتظار (مستنّي/اتقبل/اترفض).
     *
     * ⚠️ الأعمدة بالاسم عن قصد — **ممنوع `actual_unit`** (سعر العميل، v37).
     * `$day` (2026-07-26): بنود يوم واحد بس — `''` = كل الأيام (السلوك القديم).
     */
    public static function submissions(int $repId, string $status = '', string $day = ''): array
    {
        $where = 's.rep_id = ?';
        $args  = [$repId];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where .= ' AND s.status = ?';
            $args[] = $status;
        }
        // ⚠️ الفلترة على `entry_date` (يوم الصرف) مش `created_at` (يوم الإدخال):
        //    البند بيتخصم من عهدة يوم صرفه، فالمشرف لازم يشوفه على نفس اليوم
        //    اللي العهدة اتحسبت عليه — نفس قاعدة v29 و`/m`.
        if ($day !== '') {
            $where .= ' AND s.entry_date = ?';
            $args[] = $day;
        }
        return Database::all(
            "SELECT s.id, s.rep_id, s.permit_id, s.kind, s.note, s.qty, s.unit_amount, s.amount,
                    s.entry_date, s.ref_no, s.status, s.review_note, s.reviewed_by, s.reviewed_at,
                    s.review_started_at, s.created_at, s.is_visa,
                    (s.photo IS NOT NULL AND s.photo <> '') AS has_photo,
                    p.permit_no, p.permit_type, p.company, p.client
             FROM rep_submissions s
             LEFT JOIN permits p ON p.id = s.permit_id
             WHERE $where" . RepCustody::epochSql('s.entry_date') . "
             ORDER BY s.id DESC
             LIMIT " . self::FEED_LIMIT, $args);
    }

    // ─────────────────────────────────────────── مساعدات

    /** عدد البنود المستنّية لكل مندوب — [rep_id => count]. */
    private static function pendingCounts(): array
    {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $cache = [];
        foreach (Database::all(
            "SELECT rep_id, COUNT(*) cnt FROM rep_submissions
             WHERE status = 'pending'" . RepCustody::epochSql('entry_date') . " GROUP BY rep_id") as $r) {
            $cache[(int) $r['rep_id']] = (int) $r['cnt'];
        }
        return $cache;
    }

    /**
     * عدد بنود **يوم واحد** لكل مندوب — ['all'=>[rid=>n], 'pending'=>[rid=>n]].
     * استعلام مجمَّع واحد (مش استعلام لكل مندوب) وقراءة فقط.
     */
    private static function entryCountsFor(string $day): array
    {
        $out = ['all' => [], 'pending' => []];
        if ($day === '') { return $out; }
        foreach (Database::all(
            "SELECT rep_id, COUNT(*) cnt,
                    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pend
             FROM rep_submissions WHERE entry_date = ?" . RepCustody::epochSql('entry_date')
             . " GROUP BY rep_id", [$day]) as $r) {
            $rid = (int) $r['rep_id'];
            $out['all'][$rid] = (int) $r['cnt'];
            $out['pending'][$rid] = (int) $r['pend'];
        }
        return $out;
    }

    /** آخر يوم فيه إدخال لكل مندوب — [rep_id => date]. */
    private static function lastActivity(): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT rep_id, MAX(entry_date) d FROM rep_submissions WHERE 1" . RepCustody::epochSql('entry_date')
             . " GROUP BY rep_id") as $r) {
            $out[(int) $r['rep_id']] = $r['d'];
        }
        return $out;
    }
}
