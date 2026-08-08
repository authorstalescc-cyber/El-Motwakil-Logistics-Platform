<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;

/**
 * رحلة الشحنة — تايم لاين حالة الإذن التشغيلية (v62).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️⚠️ وحدة وصفية معزولة بالبناء — ولا عمود مالي واحد.
 * ═══════════════════════════════════════════════════════════════════════════
 * نفس منطق العزل في `dashboard_notes` (v19) و`shipment_tracks` (v33) و
 * `car_expenses` (v54): الجدول **كل كود الخزنة عمره ما سمع عنه**، فمفيش
 * «استثناء» لازم نفتكره في ٨ أماكن ونسيان واحد يبوّظ الرصيد. الوحدة دي
 * **ما بتدخلش** أي حساب ولا تقفيل ولا عهدة ولا فاتورة عميل، ومفيش فيها
 * `Closing::guard` عن قصد لأنها ما بتمسّش أي رصيد مثبَّت.
 * `bin/verify_v62.php` بيفشل لو أي ملف خزنة ذكر `permit_track_events` أو
 * `PermitTrack`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * القاعدة الوحيدة للحالة — احفظها كويس
 * ═══════════════════════════════════════════════════════════════════════════
 *   **حالة الإذن = أبعد مرحلة في الترتيب اتسجّلت عليها حركة**، سواء اتسجّلت
 *   على الإذن كله (`bill_id = 0`) أو على أي بوليصة فيه.
 *   **حالة البوليصة = أبعد مرحلة اتسجّلت عليها هي، أو على الإذن كله.**
 *
 * المرحلة اللي قبل الحالية ومالهاش حركة بتتعرض **«متخطّاة»** — **ما بنخترعش
 * لها حدث**. ده قرار مقصود: التايم لاين شهادة على اللي اتسجّل فعلاً، وتوليد
 * أحداث وهمية بيخلّي «إمتى وصلت العميل؟» يجاوب برقم محدش كتبه.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ الوقت — قاعدة v48/v29
 * ═══════════════════════════════════════════════════════════════════════════
 *   • `happened_at` بتتكتب بـ`NOW()` (**ساعة القاعدة**) زي كل أعمدة الوقت
 *     في النظام، والعرض بيمرّ على `dtfmt()` اللي بتضيف فرق الساعة المقيس.
 *     **ممنوع تكتبها من PHP بـ`date()`** وإلا بتتعرض متأخرة ٣ ساعات.
 *   • `work_date` = **يوم حصول المرحلة** (قابل للتعديل، الافتراضي يوم العمل)
 *     — التاريخ بلا وقت فما بيمرّش على `dtfmt` (قاعدة موثَّقة).
 */
class PermitTrack
{
    /** 0 = الحدث على **الإذن كله** لا على بوليصة بعينها. */
    public const WHOLE_PERMIT = 0;

    /** مصادر التسجيل. */
    public const SRC_OFFICE = 'office';
    public const SRC_REP    = 'rep';
    public const SRC_SYSTEM = 'system';

    /**
     * مراحل الرحلة بالترتيب لكل نوع إصدار.
     *
     * ⚠️ المفاتيح **مسبوقة بالنوع** (`sader_` / `warid_`) عن قصد رغم إن كل إذن
     *    له نوع واحد: لو نوع إذن اتغيّر بالغلط، المفتاح المسبوق بيخلّي الأحداث
     *    القديمة **تبان متخطّاة بوضوح** بدل ما تتلزق على مرحلة اسمها زيّها في
     *    النوع التاني وتدّي تايم لاين يقرا صح وهو غلط.
     *
     * `terminal` = المرحلة اللي بتقفل الرحلة (بتلوّن أخضر وبتوقف عدّاد الركود).
     */
    public const STAGES = [
        'sader' => [
            ['key' => 'sader_pickup_empty', 'label' => 'حمّل فارغ من الميناء', 'icon' => 'package',      'hint' => 'استلام الحاوية الفاضية'],
            ['key' => 'sader_gate_out',     'label' => 'خرج من الميناء',       'icon' => 'anchor',       'hint' => 'عدّى بوابة الميناء'],
            ['key' => 'sader_to_client',    'label' => 'في الطريق للعميل',     'icon' => 'truck',        'hint' => 'العربية على الطريق'],
            ['key' => 'sader_at_client',    'label' => 'وصل عند العميل',       'icon' => 'map-pin',      'hint' => 'وصل مخزن العميل'],
            ['key' => 'sader_loaded',       'label' => 'تمّ التحميل',           'icon' => 'layers',       'hint' => 'البضاعة اتحمّلت'],
            ['key' => 'sader_to_port',      'label' => 'في الطريق للميناء',    'icon' => 'truck',        'hint' => 'راجع محمّل'],
            ['key' => 'sader_cleared',      'label' => 'تمّ التعتيق',           'icon' => 'clipboard',    'hint' => 'إنهاء أوراق التعتيق'],
            ['key' => 'sader_delivered',    'label' => 'تمّ التسليم',           'icon' => 'check-circle', 'hint' => 'الشحنة اتسلّمت', 'terminal' => true],
        ],
        'warid' => [
            ['key' => 'warid_pickup_full',   'label' => 'تمّ تحميل الحاوية',    'icon' => 'package',      'hint' => 'استلام الحاوية من الميناء'],
            ['key' => 'warid_gate_out',      'label' => 'خرج من الميناء',       'icon' => 'anchor',       'hint' => 'عدّى بوابة الميناء'],
            ['key' => 'warid_to_client',     'label' => 'في الطريق للعميل',     'icon' => 'truck',        'hint' => 'العربية على الطريق'],
            ['key' => 'warid_at_client',     'label' => 'وصل عند العميل',       'icon' => 'map-pin',      'hint' => 'وصل مخزن العميل'],
            ['key' => 'warid_goods_cleared', 'label' => 'تمّ تعتيق البضاعة',    'icon' => 'clipboard',    'hint' => 'تفريغ وتعتيق البضاعة'],
            ['key' => 'warid_empty_back',    'label' => 'راجع فارغ في الطريق',  'icon' => 'truck',        'hint' => 'الحاوية الفاضية راجعة'],
            ['key' => 'warid_empty_cleared', 'label' => 'تمّ تعتيق الفارغ',     'icon' => 'check-circle', 'hint' => 'تسليم الفارغ وإنهاء الرحلة', 'terminal' => true],
        ],
    ];

    /** كاش «الجدول موجود؟» داخل الطلب. */
    private static ?bool $ready = null;

    /**
     * الجدول اترحّل؟ — النظام لازم يشتغل عادي قبل migrate_v62 (بيرجع false).
     *
     * ⚠️ الكاش الساكن ده هو **بالظبط** مصيدة v57 الموثَّقة: ملف الترحيل بيقرا
     *    حاجة قبل الـALTER فبيكاش false، وبعد الـALTER يفضل false فالتحقق يفشل.
     *    عشان كده `refreshReady()` موجودة ولازم `migrate_v62` ينادّيها في آخره.
     */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'permit_track_events'");
        }
        return self::$ready;
    }

    /** تصفير الكاش بعد الترحيل داخل نفس الطلب (مصيدة كاش المخطط — v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null;
        return self::ready();
    }

    /** اسم بديل متوافق مع باقي الوحدات (`available()` في `RepCustody`/`Notify`). */
    public static function available(): bool
    {
        return self::ready();
    }

    /** النوع المعتمد (بيرجع للصادر لو غير معروف — زي `Permit::theme`). */
    public static function normType(?string $type): string
    {
        return isset(self::STAGES[$type ?? '']) ? (string) $type : 'sader';
    }

    /** مراحل النوع بالترتيب. */
    public static function stages(?string $type): array
    {
        return self::STAGES[self::normType($type)];
    }

    /** وصف مرحلة بمفتاحها داخل نوع معيّن (null لو المفتاح مش من النوع ده). */
    public static function stageMeta(?string $type, string $key): ?array
    {
        foreach (self::stages($type) as $i => $s) {
            if ($s['key'] === $key) { $s['index'] = $i; return $s; }
        }
        return null;
    }

    /** اسم المرحلة للعرض في أي مكان (سجل، إشعار، بحث) بلا معرفة النوع. */
    public static function labelOf(string $key): string
    {
        foreach (self::STAGES as $list) {
            foreach ($list as $s) { if ($s['key'] === $key) { return $s['label']; } }
        }
        return $key;
    }

    // ═══════════════════════════════════════════════ الكتابة

    /**
     * تسجيل مرحلة (أو تحديثها لو كانت مسجَّلة على نفس الهدف).
     *
     * ⚠️ **الفورم مش مصدر ثقة**: المفتاح لازم يكون من مراحل **نوع الإذن ده
     *    بالتحديد**، والبوليصة لازم تكون **تابعة للإذن ده**. الاتنين بيتفحصوا
     *    هنا في الموديل لا في الكنترولر — ده المسار الوحيد للكتابة.
     *
     * @throws \RuntimeException برسالة عربية واضحة عند أي رفض.
     */
    public static function record(
        int $permitId,
        string $stageKey,
        int $billId = self::WHOLE_PERMIT,
        string $source = self::SRC_OFFICE,
        ?string $note = null,
        ?string $workDate = null,
        ?string $actorName = null,
        ?int $repId = null
    ): bool {
        if (!self::ready()) { return false; }

        $p = Database::row('SELECT id, permit_type, permit_no FROM permits WHERE id = ?', [$permitId]);
        if (!$p) { throw new \RuntimeException('الإذن ده مش موجود.'); }

        $type = self::normType($p['permit_type'] ?? null);
        if (self::stageMeta($type, $stageKey) === null) {
            throw new \RuntimeException('المرحلة دي مش من مراحل النوع ده.');
        }

        $billId = max(0, $billId);
        if ($billId > 0) {
            $ok = Database::scalar('SELECT id FROM bawalis WHERE id = ? AND permit_id = ?', [$billId, $permitId]);
            if (!$ok) { throw new \RuntimeException('البوليصة دي مش على الإذن ده.'); }
        }

        $source = in_array($source, [self::SRC_OFFICE, self::SRC_REP, self::SRC_SYSTEM], true)
            ? $source : self::SRC_OFFICE;

        $note = trim((string) $note);
        if ($note === '') { $note = null; }
        if ($note !== null && mb_strlen($note) > 200) { $note = mb_substr($note, 0, 200); }

        $day = self::validDay($workDate) ?? (function_exists('work_date') ? work_date() : date('Y-m-d'));
        $actor = trim((string) ($actorName ?? self::actor($source)));

        /* ⚠️ `happened_at` بـ`NOW()` — ساعة القاعدة (قاعدة v48). كتابتها من PHP
              بـ`date()` بتخلّي `dtfmt()` تضيف الفرق فوقها فتتعرض متأخرة. */
        Database::exec(
            "INSERT INTO permit_track_events
               (permit_id, bill_id, stage_key, happened_at, work_date, note, source, actor_name, rep_id)
             VALUES (?,?,?,NOW(),?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               happened_at = NOW(), work_date = VALUES(work_date), note = VALUES(note),
               source = VALUES(source), actor_name = VALUES(actor_name), rep_id = VALUES(rep_id)",
            [$permitId, $billId, $stageKey, $day, $note, $source, $actor !== '' ? $actor : null, $repId]
        );
        return true;
    }

    /** تراجع عن مرحلة مسجَّلة (بيشيل الحدث خالص — التايم لاين شهادة مش أرشيف). */
    public static function undo(int $permitId, string $stageKey, int $billId = self::WHOLE_PERMIT): bool
    {
        if (!self::ready()) { return false; }
        return Database::exec(
            'DELETE FROM permit_track_events WHERE permit_id = ? AND bill_id = ? AND stage_key = ?',
            [$permitId, max(0, $billId), $stageKey]
        ) > 0;
    }

    /**
     * المرحلة دي مسجَّلة على الهدف ده فعلاً؟
     *
     * ⚠️ **دي اللي بتفرّق بين «حدث جديد» و«تعديل بيانات حدث قايم»** — و`record`
     *    بتعمل `ON DUPLICATE KEY UPDATE` فمستحيل تعرف الفرق من نتيجتها. الكنترولر
     *    بينادّيها **قبل** التسجيل عشان يقرّر يبعت إشعار ولا لأ: إعادة تسجيل نفس
     *    المرحلة (تصحيح ملاحظة أو تاريخ) مش «حالة اتغيّرت» وما تستاهلش رنّة.
     */
    public static function hasEvent(int $permitId, string $stageKey, int $billId = self::WHOLE_PERMIT): bool
    {
        if (!self::ready()) { return false; }
        return (bool) Database::scalar(
            'SELECT id FROM permit_track_events WHERE permit_id = ? AND bill_id = ? AND stage_key = ?',
            [$permitId, max(0, $billId), $stageKey]);
    }

    /**
     * تسجيل تلقائي من النظام — **بيسجّل بس لو المرحلة مش مسجَّلة** فما بيدهسش
     * حاجة كتبها المكتب أو المندوب بإيده.
     *
     * ⚠️ ملفوفة في try/catch عند كل نقطة نداء: فشل تسجيل حالة **ممنوع** يوقف
     *    حفظ بوليصة أو إقفال إذن (نفس درس `Notify::emit` في v52).
     *
     * ⚠️ **ما بتبعتش إشعار عن قصد** — نقطتا النداء الوحيدتان (أول بوليصة على
     *    الإذن · إقفال الإذن) بيبعتوا `bill.linked` و`permit.closed` أصلاً،
     *    فإشعار حالة كمان = **نفس الخبر مرتين** في نفس الثانية.
     */
    public static function systemMark(int $permitId, string $stageKey, int $billId = self::WHOLE_PERMIT, ?string $note = null): bool
    {
        if (!self::ready()) { return false; }
        if (self::hasEvent($permitId, $stageKey, $billId)) { return false; }
        return self::record($permitId, $stageKey, $billId, self::SRC_SYSTEM, $note, null, 'النظام');
    }

    // ═══════════════════════════════════════════════ بيانات الإشعار

    /**
     * حزمة العرض اللي الإشعار بيتبني منها — **المصدر الوحيد** لسياق أي حدث رحلة.
     *
     * الكنترولرات التلاتة (المكتب: تسجيل وتراجع · المندوب: تسجيل) بيقروا من هنا
     * فمستحيل الإشعار يقول كلامًا مختلفًا حسب الشاشة اللي اتحدّث منها.
     *
     * ⚠️ **ولا عمود مالي في الاستعلام** (قاعدة v35): الحدث بيمرّ في مسار المندوب،
     *    وممنوع أي مبلغ بيخصّ العميل يوصل شاشته ولا نصّ إشعاره.
     *
     * @return array{permit_no:string,type:string,client:string,company:string,
     *               destination:string,bill_number:string,car_no:string,driver:string,
     *               label:string,icon:string,index:int,total:int,terminal:bool}|null
     */
    public static function eventContext(int $permitId, string $stageKey, int $billId = self::WHOLE_PERMIT): ?array
    {
        $p = Database::row(
            'SELECT permit_no, permit_type, client, company, destination FROM permits WHERE id = ?',
            [$permitId]);
        if (!$p) { return null; }

        $type  = self::normType($p['permit_type'] ?? null);
        $meta  = self::stageMeta($type, $stageKey);
        $bill  = ['bill_number' => '', 'car_no' => '', 'driver' => '', 'destination' => ''];
        if ($billId > 0) {
            $b = Database::row(
                'SELECT bill_number, car_no, driver, destination FROM bawalis WHERE id = ? AND permit_id = ?',
                [$billId, $permitId]);
            if ($b) { $bill = $b; }
        }

        return [
            'permit_no'   => (string) ($p['permit_no'] ?? ''),
            'type'        => $type,
            'client'      => (string) ($p['client'] ?? ''),
            'company'     => (string) ($p['company'] ?? ''),
            // وجهة البوليصة أدقّ من وجهة الإذن لما الحدث على عربية بعينها
            'destination' => (string) (($bill['destination'] ?? '') ?: ($p['destination'] ?? '')),
            'bill_number' => (string) ($bill['bill_number'] ?? ''),
            'car_no'      => (string) ($bill['car_no'] ?? ''),
            'driver'      => (string) ($bill['driver'] ?? ''),
            'label'       => $meta['label'] ?? self::labelOf($stageKey),
            'icon'        => $meta['icon'] ?? 'map-pin',
            'index'       => (int) ($meta['index'] ?? 0),
            'total'       => count(self::stages($type)),
            'terminal'    => !empty($meta['terminal']),
        ];
    }

    // ═══════════════════════════════════════════════ القراءة

    /** كل أحداث الإذن (الأقدم أولاً في ترتيب المراحل مش الوقت). */
    public static function events(int $permitId): array
    {
        if (!self::ready()) { return []; }
        return Database::all(
            'SELECT * FROM permit_track_events WHERE permit_id = ? ORDER BY work_date, happened_at, id',
            [$permitId]);
    }

    /**
     * التايم لاين الجاهز للعرض.
     *
     * $billId = 0 → رحلة الإذن (بتضم أحداث الإذن **وأحداث كل البوالص**، لأن
     *               حالة الإذن = أبعد مرحلة اتسجّلت على أي حاجة فيه).
     * $billId > 0 → رحلة البوليصة دي (أحداثها + أحداث الإذن كله).
     *
     * كل مرحلة بترجع بـ`state`: done | current | skipped | pending
     */
    public static function timeline(int $permitId, ?string $type, int $billId = self::WHOLE_PERMIT, ?array $events = null): array
    {
        $stages = self::stages($type);
        $events = $events ?? self::events($permitId);

        // نجمع أحدث حدث لكل مرحلة ضمن النطاق المطلوب
        $byStage = [];
        foreach ($events as $ev) {
            $b = (int) $ev['bill_id'];
            if ($billId > 0 && $b !== 0 && $b !== $billId) { continue; }
            $k = (string) $ev['stage_key'];
            // الحدث المخصّص للبوليصة بيكسب على حدث الإذن العام في عرض البوليصة
            $cur = $byStage[$k] ?? null;
            if ($cur === null || ($billId > 0 && $b === $billId && (int) $cur['bill_id'] === 0)) {
                $byStage[$k] = $ev;
            }
        }

        $lastIdx = -1;
        foreach ($stages as $i => $s) {
            if (isset($byStage[$s['key']])) { $lastIdx = $i; }
        }

        $out = [];
        foreach ($stages as $i => $s) {
            $ev = $byStage[$s['key']] ?? null;
            if ($ev !== null) {
                $state = $i === $lastIdx ? 'current' : 'done';
            } else {
                $state = $i < $lastIdx ? 'skipped' : 'pending';
            }
            $s['index'] = $i;
            $s['state'] = $state;
            $s['event'] = $ev;
            $s['terminal'] = !empty($s['terminal']);
            $out[] = $s;
        }
        return $out;
    }

    /**
     * ملخّص حالة الإذن — الرقم اللي بيتعرض في الهيرو والقايمة والشارة.
     *
     * بيرجع: current (المرحلة الحالية أو null) · index · total · done · percent
     *         · started · finished · last_at · idle_hours (سكون من آخر تحديث)
     */
    public static function summary(int $permitId, ?string $type, int $billId = self::WHOLE_PERMIT, ?array $events = null): array
    {
        $tl = self::timeline($permitId, $type, $billId, $events);
        $total = count($tl);
        $done = 0; $current = null; $lastAt = null; $finished = false;
        foreach ($tl as $s) {
            if ($s['event'] !== null) {
                $done++;
                if ($s['state'] === 'current') {
                    $current = $s;
                    $lastAt = $s['event']['happened_at'] ?? null;
                    $finished = $s['terminal'];
                }
            }
        }
        // النسبة بتتحسب على **موقع المرحلة الحالية** لا على عدد الأحداث:
        // المرحلة الخامسة من ثمانية = 62% حتى لو اللي قبلها اتخطّوا بلا تسجيل.
        $percent = $current === null ? 0 : (int) round((($current['index'] + 1) / max(1, $total)) * 100);

        return [
            'total'       => $total,
            'done'        => $done,
            'percent'     => $percent,
            'current'     => $current,
            'current_key' => $current['key'] ?? null,
            'label'       => $current['label'] ?? 'لسه ما بدأتش',
            'icon'        => $current['icon'] ?? 'clock',
            'started'     => $current !== null,
            'finished'    => $finished,
            'last_at'     => $lastAt,
            'idle_hours'  => self::idleHours($lastAt),
            'next'        => self::nextStage($tl),
            'timeline'    => $tl,
        ];
    }

    /** المرحلة الجاية المقترحة (أول `pending` بعد الحالية) — للزرار السريع. */
    public static function nextStage(array $timeline): ?array
    {
        foreach ($timeline as $s) {
            if ($s['state'] === 'pending') { return $s; }
        }
        return null;
    }

    /** حالة كل بوليصة على الإذن — الطبقة التفصيلية تحت رحلة الإذن. */
    public static function billSummaries(int $permitId, ?string $type, ?array $events = null): array
    {
        if (!self::ready()) { return []; }
        $events = $events ?? self::events($permitId);
        $bills = Database::all(
            'SELECT id, bill_number, car_no, driver, destination, container_no
               FROM bawalis WHERE permit_id = ? ORDER BY id', [$permitId]);
        $out = [];
        foreach ($bills as $b) {
            $s = self::summary($permitId, $type, (int) $b['id'], $events);
            $s['bill'] = $b;
            $out[] = $s;
        }
        return $out;
    }

    /**
     * حالة مجموعة أذونات دفعة واحدة — **استعلام واحد** لشاشة القايمة.
     *
     * ⚠️ ممنوع تنادي `summary()` في حلقة على ٥٠ إذن (٥٠ استعلام). الدالة دي
     *    بتجيب كل الأحداث مرة واحدة وبتوزّعها في PHP.
     */
    public static function summaryFor(array $permitIds): array
    {
        if (!self::ready() || !$permitIds) { return []; }
        $ids = array_values(array_unique(array_map('intval', $permitIds)));
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $types = [];
        foreach (Database::all("SELECT id, permit_type FROM permits WHERE id IN ($in)", $ids) as $r) {
            $types[(int) $r['id']] = (string) $r['permit_type'];
        }
        $grouped = [];
        foreach (Database::all(
            "SELECT * FROM permit_track_events WHERE permit_id IN ($in)
              ORDER BY work_date, happened_at, id", $ids) as $ev) {
            $grouped[(int) $ev['permit_id']][] = $ev;
        }
        $out = [];
        foreach ($ids as $pid) {
            if (!isset($grouped[$pid])) { continue; }   // بلا أي تحديث = مفيش شارة
            $out[$pid] = self::summary($pid, $types[$pid] ?? null, self::WHOLE_PERMIT, $grouped[$pid]);
        }
        return $out;
    }

    // ═══════════════════════════════════════════════ مساعدات

    /**
     * ساعات السكون من آخر تحديث.
     *
     * ⚠️ الفرق بيتحسب بعد تعديل ساعة القاعدة (`Clock::dbOffsetMinutes`) —
     *    المقارنة بين وقت جاي من القاعدة و`time()` بتاع PHP بلا التعديل ده
     *    بتدّي فرق ٣ ساعات وهمي (الباج الموثَّق في v48).
     */
    public static function idleHours($happenedAt): ?float
    {
        if (!$happenedAt) { return null; }
        $ts = strtotime((string) $happenedAt);
        if ($ts === false) { return null; }
        $ts += Clock::dbOffsetMinutes() * 60;
        return max(0, (time() - $ts) / 3600);
    }

    /** «من ساعتين» / «امبارح» — نص نسبي قصير للعرض. */
    public static function ago($happenedAt): string
    {
        $h = self::idleHours($happenedAt);
        if ($h === null) { return ''; }
        if ($h < 1)  { $m = (int) round($h * 60); return $m <= 1 ? 'دلوقتي' : 'من ' . $m . ' دقيقة'; }
        if ($h < 24) { $n = (int) round($h); return $n === 1 ? 'من ساعة' : 'من ' . $n . ' ساعة'; }
        $d = (int) floor($h / 24);
        if ($d === 1) { return 'من إمبارح'; }
        return 'من ' . $d . ' يوم';
    }

    private static function validDay(?string $d): ?string
    {
        $d = trim((string) $d);
        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { return null; }
        return $d;
    }

    /**
     * اسم المسجِّل.
     *
     * ⚠️ جلسة المندوب مالهاش `uid` ولا `uname` (v35)، فـ`Auth::user()` بترجّع
     *    null وكان الحدث هيتسجّل بلا اسم — نفس باج `Audit::log` الموثَّق في v43.
     */
    private static function actor(string $source): string
    {
        if ($source === self::SRC_SYSTEM) { return 'النظام'; }
        if ($source === self::SRC_REP) {
            return 'مندوب: ' . (string) ($_SESSION['rep_name'] ?? 'غير معروف');
        }
        return (string) (Auth::user() ?? 'المكتب');
    }
}
