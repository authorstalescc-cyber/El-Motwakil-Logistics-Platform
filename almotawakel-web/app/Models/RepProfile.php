<?php
namespace App\Models;

use App\Core\Database;

/**
 * بروفايل المندوب (v88) — **عدسة واحدة على كل بيانات المندوب في النظام**.
 *
 * ═══ اللزمة (طلب المالك 2026-08-11) ═══
 * «أريد إعادة بناء بروفايل المندوب مع إظهار كافة البيانات الخاصة به + إمكانية
 *  إصدار راتب عن فترة محددة.»
 *
 * بيانات المندوب كانت متفرّقة على **٨ وحدات**: كارته في `reps` · سجله في
 * `rep_entries` · محفظته الميدانية في `rep_deposits`/`rep_submissions` ·
 * نثرياته في `rep_misc_expenses` · أذونه في `permit_assignments` · عهد
 * البوالص في `rep_bill_custody` · تسليماته في `rep_driver_handovers` ·
 * راتبه في `payroll_lines`. البروفايل القديم كان بيعرض **اتنين** منهم بس.
 *
 * ═══ ⚠️⚠️ الوحدة دي **قراءة بحتة** — معزولة بالبناء ═══
 * ولا `INSERT` ولا `UPDATE` ولا `DELETE` ولا جدول جديد. كل رقم فيها بيتجاب من
 * **الموديل صاحبه** (`RepCustody::balance` · `Payroll::compute` · `Rep::summary`…)
 * لا باستعلام موازٍ — تعريفان بيختلفوا = «الكارت يقول رقم والكشف يقول تاني».
 * `verify_rep_profile` بيفشل لو أي كلمة كتابة دخلت الملف ده.
 */
final class RepProfile
{
    /**
     * تبويبات البروفايل — **روابط حقيقية** (`?tab=`) لا تبديل بجافاسكريبت،
     * فالرجوع بالمتصفح والمشاركة والفتح في تبويب جديد كلهم شغّالين.
     */
    public const TABS = [
        'salary'  => ['label' => 'الراتب',          'icon' => 'banknote'],
        'ledger'  => ['label' => 'كشف الحساب',      'icon' => 'layers'],
        'custody' => ['label' => 'العهدة الميدانية', 'icon' => 'briefcase'],
        'work'    => ['label' => 'الشغل',           'icon' => 'truck'],
        'card'    => ['label' => 'الكارت',          'icon' => 'user'],
    ];

    /** أي قيمة مجهولة بتقع على أول تبويب — الرابط المزوَّر ما يوقعش الشاشة. */
    public static function tab(?string $raw): string
    {
        $t = trim((string) $raw);
        return isset(self::TABS[$t]) ? $t : 'salary';
    }

    // ─────────────────────────────────────────────── أرقام الترويسة

    /**
     * الخمسة أرقام اللي فوق — كل واحد بيفتح بنوده (قاعدة «ممنوع رقم أصمّ»).
     *
     * @return array{balance:float,custody:float,advances:float,net:float,pending:int}
     */
    public static function head(int $repId, array $period): array
    {
        $sum = Rep::summary($repId);

        return [
            // المستحق له علينا دلوقتي (الدين علينا — تحذير ①)
            'balance' => (float) $sum['balance'],
            // محفظته الميدانية — معزولة تمامًا عن الخزنة (v35)
            'custody' => RepCustody::available() ? RepCustody::balance($repId) : 0.0,
            'advances' => self::periodAdvances($repId, $period),
            'net'      => self::periodNet($repId, $period),
            'pending'  => RepAccount::pendingCount($repId, $period),
        ];
    }

    /** سلف الفترة (من عهدته الميدانية) — نفس مصدر شاشة «حسابي» بالحرف. */
    public static function periodAdvances(int $repId, array $period): float
    {
        $byKind = RepMiscExpense::totalsByKind($repId, $period['from'], $period['to']);
        $out = 0.0;
        foreach (RepMiscKind::salaryKeys() as $k) { $out += (float) ($byKind[$k] ?? 0); }
        return round($out, 2);
    }

    /** صافي راتب الفترة — من `RepAccount::summary` (المصدر الوحيد). */
    public static function periodNet(int $repId, array $period): float
    {
        return (float) RepAccount::summary($repId, $period)['net'];
    }

    // ─────────────────────────────────────────────── تبويب الراتب

    /**
     * كل أرقام راتب الفترة لمندوب واحد + بنوده اليدوية + سجل رواتبه السابقة.
     *
     * ⚠️ الأرقام بتيجي من `RepAccount::summary` و`Payroll::repShare` — يعني
     *    **نفس اللي المندوب بيشوفه على موبايله** بالحرف.
     */
    public static function salary(int $repId, array $period): array
    {
        $sum   = RepAccount::summary($repId, $period);
        $lock  = Payroll::lockOn($period['from']);

        return [
            'period'      => $period,
            'salary'      => (float) $sum['salary'],
            'advances'    => (float) $sum['advances'],
            'commission'  => (float) $sum['commission'],
            'expense'     => (float) $sum['expenseShare'],
            'adjustments' => Payroll::available()
                ? Payroll::adjustmentsFor($repId, $period['from'], $period['to']) : 0.0,
            'net'         => (float) $sum['net'],
            'approved'    => (bool) $sum['approved'],
            /* ⚠️ الفترة المقفولة = راتبها اتعتمد. الشاشة بتخفي فورم الإدخال
               وبتعرض تحذيرًا وظيفيًّا — والحارس الحقيقي في `Rep::createEntry`
               مش في الإخفاء ده (قاعدة ③). */
            'locked'      => $lock,
            'rows'        => Payroll::available()
                ? Payroll::adjustmentRows($repId, $period['from'], $period['to']) : [],
            'history'     => self::payrollHistory($repId),
            /* ⚠️ **«إصدار الراتب» = التقفيل القايم مش مسارًا ماليًّا جديدًا**
               (قاعدة ②): نفس `Rep::settle` بنفس `rep_settlements` وبنفس
               الإيصال — الجديد إن الفترة بتتعبّى لوحدها والصافي بيتشاف قبل
               الضغط. مسار تاني للصرف كان هيبقى تعريفًا تانيًا للراتب. */
            'preview'     => Rep::settlementPreview($repId, $period['from'], $period['to']),
        ];
    }

    /** آخر الفترات اللي المندوب اتحسبله فيها راتب. */
    public static function payrollHistory(int $repId, int $limit = 12): array
    {
        if (!Payroll::available()) { return []; }
        return Database::all(
            "SELECT l.*, p.period_from, p.period_to, p.status, p.approved_at
               FROM payroll_lines l
               JOIN payroll_periods p ON p.id = l.period_id
              WHERE l.rep_id = ?
              ORDER BY p.period_from DESC
              LIMIT " . max(1, min(60, $limit)), [$repId]);
    }

    // ─────────────────────────────────────────────── تبويب العهدة الميدانية

    /**
     * محفظة المندوب الميدانية — **معزولة تمامًا عن الخزنة** (v35).
     * بترجّع فاضية لو الوحدة لسه ما اترحّلتش، فالتبويب بيقول «مش مفعّلة».
     */
    public static function custody(int $repId): array
    {
        if (!RepCustody::available()) { return ['ready' => false]; }

        return [
            'ready'     => true,
            'balance'   => RepCustody::balance($repId),
            'breakdown' => RepCustody::breakdown($repId),
            'deposits'  => RepCustody::deposits($repId),
            'spending'  => RepCustody::spending($repId),
            'settles'   => RepCustody::settlements($repId),
            'out'       => RepTransfer::available() ? RepTransfer::outgoingFor($repId, 20) : [],
            'in'        => RepTransfer::available() ? RepTransfer::incomingFor($repId) : [],
        ];
    }

    // ─────────────────────────────────────────────── تبويب الشغل

    /** كل شغل المندوب: أذونه · بنوده تحت المراجعة · عهد بوالص · تسليمات سائقين. */
    public static function work(int $repId): array
    {
        return [
            'permits'    => RepCustody::available() ? RepAssignment::forRep($repId) : [],
            'pending'    => RepCustody::available()
                ? RepPortal::mySubmissions($repId, 30, 'pending') : [],
            'bills'      => RepBillCustody::ready() ? RepBillCustody::forRep($repId, 20) : [],
            'handovers'  => RepDriverHandover::available()
                ? RepDriverHandover::forRep($repId, 20) : [],
            'misc'       => RepMiscExpense::available() ? RepMiscExpense::forRep($repId) : [],
        ];
    }

    // ─────────────────────────────────────────────── تبويب الكارت

    /** بيانات الكارت + الصلاحيات + حالة بوابة الموبايل. */
    public static function card(int $repId): array
    {
        return [
            'canCreatePermit' => Rep::canCreatePermit($repId),
            'canWatchReps'    => Rep::canWatchReps($repId),
            'permReady'       => Rep::permitPermReady(),
            'watchReady'      => Rep::watchPermReady(),
            'pins'            => RepPin::available() ? RepPin::forRep($repId) : [],
            'settlements'     => Rep::settlements($repId),
        ];
    }
}
