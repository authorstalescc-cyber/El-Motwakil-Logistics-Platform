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

            $dep = (float) $c['deposits'];
            $c['pc_spent'] = $dep > 0 ? min(100, round($c['spent'] / $dep * 100, 1)) : 0.0;
            // مندوب متسوّي حسابه (رجّع الباقي ومفيش عنده مستنّي) — رصيده صفر
            // **مش لأن عهدته خلصت** فما ينفعش يتحسب «محتاج تنزيل» ويزعّق للمشرف.
            $c['settled'] = ($c['balance'] <= 0.005 && (float) $c['returned'] > 0 && $c['pending_cnt'] === 0);
            // «عهدته قربت تخلص» — نفس قاعدة الداشبورد: فاضل أقل من 15% مما نزل له
            $c['low'] = (!$c['settled'] && $dep > 0 && $c['pocket'] < $dep * 0.15);
            if ($c['low']) { $low++; }

            $tDep    += $dep;
            $tSpent  += (float) $c['spent'];
            $tBal    += (float) $c['balance'];
            $tPendCnt += $c['pending_cnt'];
            $tPendSum += $c['pending_sum'];

            // اللي عليه حركة بس — المندوب اللي عمره ما نزلت له عهدة مالوش لازمة هنا
            if ($dep > 0 || $c['spent'] > 0 || $c['pending_cnt'] > 0) { $reps[] = $c; }
        }

        // الأهم فوق: المحتاج تنزيل، ثم اللي عنده بنود مستنّية، ثم الأكبر رصيدًا
        usort($reps, function ($a, $b) {
            if ($a['low'] !== $b['low']) { return $a['low'] ? -1 : 1; }
            if ($a['pending_cnt'] !== $b['pending_cnt']) { return $b['pending_cnt'] <=> $a['pending_cnt']; }
            return $b['balance'] <=> $a['balance'];
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
            ],
        ];
    }

    // ─────────────────────────────────────────── تفصيل مندوب واحد

    /**
     * كل حاجة عن مندوب واحد: عهدته، وكل حركة طلعت منها، وتنزيلاته، وبنوده.
     * بترجّع null لو المندوب مش موجود (أو قبل migrate_v35).
     */
    public static function rep(int $viewerId, int $repId): ?array
    {
        self::assertAllowed($viewerId);
        if (!RepCustody::available()) { return null; }
        $rep = Database::row("SELECT id, name, phone, status FROM reps WHERE id = ?", [$repId]);
        if (!$rep) { return null; }

        $b = RepCustody::breakdown($repId);
        $pending = RepPortal::pendingAmount($repId);

        return [
            'rep'          => $rep,
            'b'            => $b,
            'pending'      => $pending,
            'pending_cnt'  => self::pendingCounts()[$repId] ?? 0,
            'pocket'       => round($b['balance'] - $pending, 2),
            'spending'     => array_slice(RepCustody::spending($repId), 0, self::SPEND_LIMIT),
            'deposits'     => RepCustody::deposits($repId),
            'transfers_in' => RepTransfer::available() ? RepTransfer::incomingFor($repId, 20) : [],
            'settlements'  => RepCustody::settlements($repId),
            'submissions'  => self::submissions($repId),
        ];
    }

    /**
     * بنود المندوب زي ما هي في صندوق الانتظار (مستنّي/اتقبل/اترفض).
     *
     * ⚠️ الأعمدة بالاسم عن قصد — **ممنوع `actual_unit`** (سعر العميل، v37).
     */
    public static function submissions(int $repId, string $status = ''): array
    {
        $where = 's.rep_id = ?';
        $args  = [$repId];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where .= ' AND s.status = ?';
            $args[] = $status;
        }
        return Database::all(
            "SELECT s.id, s.rep_id, s.permit_id, s.kind, s.note, s.qty, s.unit_amount, s.amount,
                    s.entry_date, s.ref_no, s.status, s.review_note, s.reviewed_by, s.reviewed_at,
                    s.review_started_at, s.created_at, s.is_visa,
                    (s.photo IS NOT NULL AND s.photo <> '') AS has_photo,
                    p.permit_no, p.permit_type, p.company, p.client
             FROM rep_submissions s
             LEFT JOIN permits p ON p.id = s.permit_id
             WHERE $where
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
             WHERE status = 'pending' GROUP BY rep_id") as $r) {
            $cache[(int) $r['rep_id']] = (int) $r['cnt'];
        }
        return $cache;
    }

    /** آخر يوم فيه إدخال لكل مندوب — [rep_id => date]. */
    private static function lastActivity(): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT rep_id, MAX(entry_date) d FROM rep_submissions GROUP BY rep_id") as $r) {
            $out[(int) $r['rep_id']] = $r['d'];
        }
        return $out;
    }
}
