<?php
namespace App\Models;

use App\Core\Database;
use App\Core\App;

/**
 * الإصدار: إذن (للصادر) / شهادة (للوارد) — أكبر عملية في النظام.
 * الإصدار هو الأب، ويضم بوليصة واحدة أو أكثر، وله تايم لاين من المراحل.
 *
 * قاعدة المصروفات المهمة:
 *   كل بند مرحلة له تاريخ (entry_date). البند يدخل «تقفيل يوم تاريخه»،
 *   لكنه يبقى مسجَّلاً على الإصدار للأبد مهما كان تاريخ الإصدار نفسه.
 */
class Permit
{
    public const TYPES = [
        'sader' => ['label' => 'إذن (صادر)',  'no' => 'رقم الإذن'],
        'warid' => ['label' => 'شهادة (وارد)', 'no' => 'رقم الشهادة'],
    ];

    /**
     * هوية النوع البصرية (مصدر واحد للحقيقة): وسم قصير + سهم اتجاهي + كلاس CSS.
     *   صادر ↗ = بضاعة رايحة للتصدير (برونزي)   |   وارد ↘ = بضاعة جايّة من الميناء (أزرق بحري)
     */
    public const TYPE_THEME = [
        'sader' => ['short' => 'صادر', 'full' => 'إذن صادر',   'arrow' => '↗', 'en' => 'EXPORT', 'cls' => 'sader'],
        'warid' => ['short' => 'وارد', 'full' => 'شهادة وارد', 'arrow' => '↘', 'en' => 'IMPORT', 'cls' => 'warid'],
    ];

    /** هوية النوع البصرية (يرجع للصادر إن كان النوع غير معروف). */
    public static function theme(string $type): array
    {
        return self::TYPE_THEME[$type] ?? self::TYPE_THEME['sader'];
    }

    /** اتجاه الفلوس: expense=مصروف يخصم | revenue=محصّل/إيراد | link=مشتقّ من جدول | info=بيانات */
    public const DIR_EXPENSE = 'expense';
    public const DIR_REVENUE = 'revenue';
    public const DIR_LINK    = 'link';
    public const DIR_INFO    = 'info';

    /**
     * تعريف مراحل التايم لاين بالترتيب.
     *  store=stages  → بنودها في جدول permit_stages
     *  store=bills   → البوالص المرتبطة (دَين قابل للسداد)
     *  store=generators → سجل المولدات المطابق لرقم الإذن
     *  store=header  → بيانات الإصدار نفسه
     *  multi=true    → يسمح بأكثر من بند (مثل المصاريف الإضافية)
     */
    public const STAGES = [
        'data'         => ['label' => 'بيانات الإذن',     'icon' => '📄', 'store' => 'header',     'dir' => self::DIR_INFO,    'multi' => false, 'scope' => 'permit'],
        'primary_fees' => ['label' => 'رسوم أولية',       'icon' => '🪙', 'store' => 'stages',     'dir' => self::DIR_EXPENSE, 'multi' => false, 'scope' => 'permit'],
        'bills'        => ['label' => 'البوليصة',         'icon' => '🚛', 'store' => 'bills',      'dir' => self::DIR_LINK,    'multi' => true,  'scope' => 'movement'],
        // بند الإيصالات الموحّد: كل الإيصالات (تخصيص/حمولة/تعتيق/هيئة/رسوم محصّلة…) في مكان واحد متعدّد.
        // اسم كل إيصال يُحفظ في «البيان» (note). دُمجت فيه المراحل الخمس المنفصلة القديمة عبر migrate_v14.
        'receipts'     => ['label' => 'إيصالات',          'icon' => '🧾', 'store' => 'stages',     'dir' => self::DIR_EXPENSE, 'multi' => true,  'scope' => 'movement'],
        // المصاريف الإضافية: بنود حرة، مصروف أو محصّل (إيراد) لكل بند. (دُمج فيه «extra» القديم عبر migrate_v9.)
        'custom'       => ['label' => 'مصروفات إضافية',   'icon' => '➕', 'store' => 'stages',     'dir' => self::DIR_INFO,    'multi' => true,  'scope' => 'movement'],
        'generators'   => ['label' => 'المولدات',         'icon' => '⚙️', 'store' => 'generators', 'dir' => self::DIR_LINK,    'multi' => true,  'scope' => 'movement'],
    ];

    /** المراحل التي لا يمكن إخفاؤها (أساسية) */
    public const ALWAYS_ON = ['data', 'bills'];

    /** المراحل المخزَّنة في permit_stages فقط */
    public static function stageKeys(): array
    {
        return array_keys(array_filter(self::STAGES, fn($s) => $s['store'] === 'stages'));
    }

    /**
     * تكوين المراحل الفعّال = الافتراضي + تعديلات الإعدادات (اسم/إظهار/ترتيب).
     * يُخزَّن التعديل كـ JSON في الإعداد `permit_stages_config`.
     */
    public static function stageConfig(): array
    {
        $ov = json_decode((string) App::setting('permit_stages_config', ''), true);
        if (!is_array($ov)) { $ov = []; }
        $i = 0; $out = [];
        foreach (self::STAGES as $key => $meta) {
            $o = $ov[$key] ?? [];
            $meta['key']     = $key;
            $meta['label']   = (isset($o['label']) && trim((string)$o['label']) !== '') ? trim($o['label']) : $meta['label'];
            $meta['enabled'] = in_array($key, self::ALWAYS_ON, true) ? true : (bool)($o['enabled'] ?? true);
            $meta['order']   = isset($o['order']) ? (int)$o['order'] : $i;
            $out[$key] = $meta;
            $i += 10;
        }
        uasort($out, fn($a, $b) => $a['order'] <=> $b['order']);
        return $out;
    }

    /** المراحل المفعّلة فقط ومرتّبة (لعرض التايم لاين) */
    public static function activeStages(): array
    {
        return array_filter(self::stageConfig(), fn($s) => $s['enabled']);
    }

    /** الاسم الفعّال لمرحلة (بعد تعديل الإعدادات) */
    public static function label(string $key): string
    {
        $cfg = self::stageConfig();
        return $cfg[$key]['label'] ?? (self::STAGES[$key]['label'] ?? $key);
    }

    /** هل يمكن إخفاء هذه المرحلة؟ */
    public static function isHideable(string $key): bool
    {
        return isset(self::STAGES[$key]) && !in_array($key, self::ALWAYS_ON, true);
    }

    /** المراحل المفعّلة ضمن نطاق معيّن: 'permit' (على مستوى الإذن) | 'movement' (داخل الحركة) */
    public static function scopedStages(string $scope): array
    {
        return array_filter(self::activeStages(), fn($s) => ($s['scope'] ?? 'movement') === $scope);
    }

    // ---------------- الحركات ----------------

    /** حركات الإذن مرتّبة بالرقم التسلسلي */
    public static function movements(int $permitId): array
    {
        return Database::all('SELECT * FROM permit_movements WHERE permit_id = ? ORDER BY seq, id', [$permitId]);
    }

    public static function movement(int $movementId): ?array
    {
        return Database::row('SELECT * FROM permit_movements WHERE id = ?', [$movementId]);
    }

    /**
     * إنشاء حركة جديدة برقم تسلسلي تلقائي داخل الإذن.
     * $containerCount/$containerType وصفيان على مستوى الحركة (لا يمسّان أي حساب).
     */
    public static function createMovement(int $permitId, ?string $note = null, ?int $containerCount = null, ?string $containerType = null): int
    {
        $seq = (int) Database::scalar('SELECT COALESCE(MAX(seq),0)+1 FROM permit_movements WHERE permit_id = ?', [$permitId]);
        Database::exec(
            'INSERT INTO permit_movements (permit_id, seq, note, container_count, container_type) VALUES (?,?,?,?,?)',
            [$permitId, $seq, $note, $containerCount, $containerType]
        );
        return Database::lastId();
    }

    /** تعديل وصف الحركة: ملاحظة + عدد/تصنيف الحاويات (حقول وصفية معزولة) */
    public static function updateMovement(int $movementId, ?string $note, ?int $containerCount, ?string $containerType): void
    {
        Database::exec(
            'UPDATE permit_movements SET note = ?, container_count = ?, container_type = ? WHERE id = ?',
            [$note, $containerCount, $containerType, $movementId]
        );
    }

    /**
     * سعة الحاويات المطبّقة عند حفظ بوليصة.
     * لو الحركة لها عدد محدد (>0) يكون الحد على مستوى الحركة، مطابقًا للعداد الظاهر فيها.
     * الحركات القديمة بلا عدد ترجع لإجمالي الإذن كحد احتياطي.
     */
    public static function billContainerCapacity(int $permitId, ?int $movementId, ?int $excludeBillId = null): array
    {
        $movementTotal = 0;
        if ($movementId) {
            $movementTotal = (int) Database::scalar(
                'SELECT COALESCE(container_count,0) FROM permit_movements WHERE id=? AND permit_id=?',
                [$movementId, $permitId]
            );
        }

        $params = [];
        if ($movementTotal > 0) {
            $where = 'movement_id=?';
            $params[] = $movementId;
            $total = $movementTotal;
            $scope = 'movement';
        } else {
            $where = 'permit_id=?';
            $params[] = $permitId;
            $total = (int) Database::scalar('SELECT COALESCE(containers,0) FROM permits WHERE id=?', [$permitId]);
            $scope = 'permit';
        }
        if ($excludeBillId) { $where .= ' AND id<>?'; $params[] = $excludeBillId; }
        $allocated = (int) Database::scalar(
            'SELECT COALESCE(SUM(container_count),0) FROM bawalis WHERE '.$where,
            $params
        );
        return [
            'scope' => $scope,
            'total' => $total,
            'allocated' => $allocated,
            'left' => $total - $allocated,
        ];
    }

    /** بوالص حركة بعينها */
    public static function movementBills(int $movementId): array
    {
        return Database::all('SELECT * FROM bawalis WHERE movement_id = ? ORDER BY bill_date, id', [$movementId]);
    }

    /** بنود مراحل حركة بعينها مجمّعة حسب المفتاح */
    public static function movementStagesGrouped(int $movementId): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM permit_stages WHERE movement_id = ? ORDER BY id', [$movementId]) as $s) {
            $out[$s['stage_key']][] = $s;
        }
        return $out;
    }

    /** الحركات المتاحة لنقل إيصال إليها، مجمعة ضمنيًا ببيانات الإذن الهدف. */
    public static function receiptMoveTargets(int $excludePermitId): array
    {
        return Database::all(
            "SELECT p.id AS permit_id, p.permit_type, p.permit_no, p.permit_date,
                    p.company, p.client, p.containers, p.container_type,
                    m.id AS movement_id, m.seq AS movement_seq, m.note AS movement_note
               FROM permits p
               JOIN permit_movements m ON m.permit_id = p.id
              WHERE p.id <> ? AND p.status = 'open'
              ORDER BY p.permit_date DESC, p.id DESC, m.seq, m.id
              LIMIT 800",
            [$excludePermitId]
        );
    }

    /**
     * ينقل سجل إيصال نفسه إلى حركة في إذن آخر.
     * لا يعيد إنشاء السجل، لذلك تبقى كل القيم والتاريخ والمرفق ووقت الإنشاء كما هي.
     */
    public static function moveReceipt(int $stageId, int $targetMovementId): array
    {
        $pdo = Database::conn();
        $started = !$pdo->inTransaction();
        if ($started) { $pdo->beginTransaction(); }
        try {
            $receipt = Database::row(
                "SELECT s.*, p.permit_no AS source_permit_no, p.permit_type AS source_permit_type,
                        p.status AS source_permit_status
                   FROM permit_stages s
                   JOIN permits p ON p.id = s.permit_id
                  WHERE s.id = ? FOR UPDATE",
                [$stageId]
            );
            if (!$receipt || $receipt['stage_key'] !== 'receipts' || $receipt['status'] === 'none') {
                throw new \RuntimeException('الإيصال غير موجود أو غير صالح للنقل.');
            }
            $sourcePermitId = (int)$receipt['permit_id'];
            if (($receipt['source_permit_status'] ?? 'open') === 'closed') {
                throw new \RuntimeException('الإذن المصدر مقفول. أعد فتحه أولاً قبل نقل الإيصال.');
            }

            $target = Database::row(
                "SELECT m.id AS movement_id, m.seq AS movement_seq, p.id AS permit_id,
                        p.permit_no, p.permit_type, p.status
                   FROM permit_movements m
                   JOIN permits p ON p.id = m.permit_id
                  WHERE m.id = ? FOR UPDATE",
                [$targetMovementId]
            );
            if (!$target) { throw new \RuntimeException('اختر إذنًا وحركة هدف صالحين.'); }
            $targetPermitId = (int)$target['permit_id'];
            if ($targetPermitId === $sourcePermitId) {
                throw new \RuntimeException('اختر إذنًا آخر؛ الإيصال موجود بالفعل داخل هذا الإذن.');
            }
            if (($target['status'] ?? 'open') === 'closed') {
                throw new \RuntimeException('الإذن الهدف مقفول ولا يمكن إضافة إيصال إليه.');
            }

            // النقل يغيّر نسبة المصروف بين إذنين؛ لذلك لا يتم عبر يوم مالي مُقفل.
            if ((int)($receipt['is_visa'] ?? 0) === 0 && ($err = Closing::guard($receipt['entry_date'] ?? null))) {
                throw new \RuntimeException($err);
            }

            // الفواتير الجديدة تحفظ مصدر كل بند. المستندات القديمة بلا مصدر تفصيلي تُعامل بحذر.
            if (PermitInvoice::hasTable()) {
                $docs = Database::all(
                    "SELECT id, doc_no, items_json FROM permit_invoices
                      WHERE permit_id = ? AND status <> 'void' FOR UPDATE",
                    [$sourcePermitId]
                );
                $hasUntrackedDocument = false;
                foreach ($docs as $doc) {
                    $lines = PermitInvoice::lines($doc);
                    $tracksSources = false;
                    foreach ($lines as $line) {
                        if (array_key_exists('source', $line)) { $tracksSources = true; }
                        if (($line['source'] ?? '') === 'stage:'.$stageId) {
                            throw new \RuntimeException('لا يمكن نقل الإيصال لأنه داخل المستند '.($doc['doc_no'] ?: '#'.$doc['id']).'. ألغِ المستند أولاً إذا كان النقل ضروريًا.');
                        }
                    }
                    if (!$tracksSources) { $hasUntrackedDocument = true; }
                }
                if ($hasUntrackedDocument) {
                    throw new \RuntimeException('لا يمكن نقل الإيصال لأن الإذن المصدر لديه مستند مالي قديم لا يحفظ ربط البنود تفصيليًا. ألغِ المستند أولاً أو راجع العملية يدويًا.');
                }
            }

            $before = $receipt;
            $changed = Database::exec(
                'UPDATE permit_stages SET permit_id = ?, movement_id = ? WHERE id = ? AND permit_id = ?',
                [$targetPermitId, $targetMovementId, $stageId, $sourcePermitId]
            );
            if ($changed !== 1) { throw new \RuntimeException('تغيّر الإيصال أثناء النقل. أعد تحميل الصفحة وحاول مرة أخرى.'); }
            $after = Database::row('SELECT * FROM permit_stages WHERE id = ?', [$stageId]);

            $sourceNo = $receipt['source_permit_no'] ?: '#'.$sourcePermitId;
            $targetNo = $target['permit_no'] ?: '#'.$targetPermitId;
            $receiptName = trim((string)($receipt['note'] ?? '')) ?: 'إيصال #'.$stageId;
            Audit::log('move', 'permit_stages', $stageId, $before, $after,
                'نقل الإيصال «'.$receiptName.'» من '.self::noLabel($receipt['source_permit_type']).' '.$sourceNo.
                ' إلى '.self::noLabel($target['permit_type']).' '.$targetNo.' — حركة '.(int)$target['movement_seq'],
                [
                    'permit_id'=>$targetPermitId,
                    'permit_no'=>$target['permit_no'],
                    'movement_id'=>$targetMovementId,
                    'source_permit_id'=>$sourcePermitId,
                    'source_movement_id'=>$receipt['movement_id'] ? (int)$receipt['movement_id'] : null,
                    'record_date'=>$receipt['entry_date'],
                ]
            );
            if ($started) { $pdo->commit(); }
            return [
                'receipt'=>$after,
                'source_permit_id'=>$sourcePermitId,
                'target_permit_id'=>$targetPermitId,
                'target_movement_id'=>$targetMovementId,
                'target_permit_no'=>$targetNo,
                'target_permit_type'=>$target['permit_type'],
            ];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** بنود المراحل على مستوى الإذن (movement_id IS NULL) مجمّعة */
    public static function permitStagesGrouped(int $permitId): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM permit_stages WHERE permit_id = ? AND movement_id IS NULL ORDER BY id', [$permitId]) as $s) {
            $out[$s['stage_key']][] = $s;
        }
        return $out;
    }

    /** مفاتيح المراحل ذات المصروف الثابت (بدون البنود المخصّصة) */
    public static function expenseKeys(): array
    {
        return array_keys(array_filter(
            self::STAGES,
            fn($s) => $s['store'] === 'stages' && $s['dir'] === self::DIR_EXPENSE
        ));
    }

    /**
     * شرط SQL للمصروفات: المراحل الثابتة + البنود المخصّصة ذات الاتجاه expense.
     * $a = اسم الجدول/الاسم المستعار لجدول permit_stages.
     */
    public static function expenseSql(string $a = 's'): string
    {
        $keys = "'" . implode("','", self::expenseKeys()) . "'";
        return "($a.stage_key IN ($keys) OR ($a.stage_key='custom' AND $a.direction='expense'))";
    }

    /**
     * هل عمود `permit_stages.unpaid` موجود؟ (v57) — النظام يشتغل قبل الترحيل
     * بسلوكه القديم بالحرف بدل ما يكسر (نفس نمط `RepMiscExpense::visaReady`).
     */
    private static ?bool $unpaidReady = null;

    public static function unpaidReady(): bool
    {
        if (self::$unpaidReady === null) {
            self::$unpaidReady = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'permit_stages'
                   AND column_name = 'unpaid'"
            );
        }
        return self::$unpaidReady;
    }

    /**
     * ⚠️ **لازمة للترحيل v57 نفسه — وباج متحقَّق اتصاد على الإنتاج.**
     *
     * `unpaidReady()` بتكاش نتيجتها لكل الطلب. وملف الترحيل بيقرا رصيد الخزنة
     * **قبل** ما يضيف العمود (عشان يتأكد إن الترحيل مالوش أثر مالي)، والقراءة
     * دي بتمرّ على `cashOutSql` → `unpaidReady` فبتتكاش **false**. وبعد الـALTER
     * فضل الكاش false، فتحقّق ما بعد الترحيل فشل والإصدار ما اتسجّلش في الـledger
     * رغم إن العمود اتضاف فعلاً. النداء ده بيصفّر الكاش بعد أي تغيير في المخطط.
     */
    public static function refreshUnpaidReady(): void
    {
        self::$unpaidReady = null;
    }

    /**
     * ⚠️⚠️ **المصدر الوحيد لشرط «البند ده طلّع كاش فعلاً»** — ممنوع تكتبه بإيدك.
     *
     * البند بيخصم من **خزنة المكتب** (Closing/التقفيل/المطابقة) ومن **عهدة
     * المندوب** (لو `paid_by_rep_id` متحطّ) بشرطين مع بعض:
     *   • `is_visa = 0`  — المدفوع بالفيزا خرج من كارت المكتب مش من الدرج (v22/v45).
     *   • `unpaid  = 0`  — بند «غير مدفوع» (v57) اتحاسب من برّه العهدة والخزنة
     *                      خالص، فبيتسجّل على الإذن وبيتفوتر للعميل عادي لكن
     *                      **ما بيخصمش ولا مليم من أي محفظة**.
     *
     * كل استعلام بيحسب كاش على `permit_stages` لازم يمرّ من هنا. لو اتكتب الشرط
     * بإيد في مكان واحد بس، الرصيد هيقول رقمًا والشاشة تقول رقمًا تاني **بلا أي
     * خطأ ظاهر** — و`bin/verify_unpaid_stages.php` بيفشل لو ده حصل.
     *
     * $a = الاسم المستعار للجدول ('' = بلا بادئة لاستعلام على الجدول مباشرةً).
     */
    public static function cashOutSql(string $a = 's'): string
    {
        $p = $a === '' ? '' : $a . '.';
        return "{$p}is_visa = 0" . (self::unpaidReady() ? " AND {$p}unpaid = 0" : '');
    }

    /** شرط SQL للمحصّل الإضافي (إيراد): البنود المخصّصة ذات الاتجاه revenue فقط. */
    public static function revenueSql(string $a = 's'): string
    {
        return "($a.stage_key='custom' AND $a.direction='revenue')";
    }

    /**
     * عدد الحاويات من نص مثل «2*40» أو «2/40» أو «٢×٤٠» = الرقم قبل الفاصل.
     * يدعم الأرقام العربية وفواصل: * x × / \ - ومسافة.
     */
    public static function parseContainers(?string $containerNo): int
    {
        $s = trim((string)$containerNo);
        if ($s === '') { return 0; }
        // تحويل الأرقام العربية إلى إنجليزية
        $s = strtr($s, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        // الرقم في البداية قبل أي فاصل
        if (preg_match('#^\s*(\d+)\s*(?:[*x×/\\\\\-]|$)#u', $s, $m)) { return (int)$m[1]; }
        return 0;
    }

    /** عدد الحاويات على مستوى الإذن نفسه (يُدخَل عند الإنشاء) */
    public static function containerCount(int $permitId): int
    {
        return (int) Database::scalar('SELECT COALESCE(containers,0) FROM permits WHERE id = ?', [$permitId]);
    }

    /**
     * الرسوم الأولية للإذن = الكشف + الضمان فقط (عدد حاويات الإذن × سعر كلٍّ **من الإذن نفسه**).
     * السعر يُدخِله المستخدم في بيانات الإذن (لا قيمة افتراضية) — إذا لم يُدخَل بعد فـ rates_set=false.
     * الخصم بند خاص بالبوليصة (bawalis.discount) ولا يدخل هنا إطلاقاً.
     */
    public static function primaryFeesDefault(int $permitId): array
    {
        $row = Database::row(
            'SELECT COALESCE(containers,0) AS containers, inspection_rate, guarantee_rate
               FROM permits WHERE id = ?', [$permitId]
        ) ?: ['containers' => 0, 'inspection_rate' => null, 'guarantee_rate' => null];

        $containers = (int) $row['containers'];
        $inspSet = $row['inspection_rate'] !== null && $row['inspection_rate'] !== '';
        $guarSet = $row['guarantee_rate']  !== null && $row['guarantee_rate']  !== '';
        $inspRate = $inspSet ? (float) $row['inspection_rate'] : 0.0;
        $guarRate = $guarSet ? (float) $row['guarantee_rate']  : 0.0;
        $inspection = round($containers * $inspRate, 2);   // الكشف
        $guarantee  = round($containers * $guarRate, 2);   // الضمان
        return [
            'containers'      => $containers,
            'inspection_rate' => $inspRate,
            'guarantee_rate'  => $guarRate,
            'inspection'      => $inspection,
            'guarantee'       => $guarantee,
            'total'           => round($inspection + $guarantee, 2),
            'rates_set'       => ($inspSet || $guarSet),   // أُدخِل سعر واحد على الأقل
        ];
    }

    public static function find(int $id): ?array
    {
        return Database::row('SELECT * FROM permits WHERE id = ?', [$id]);
    }

    /**
     * هل رقم الإذن/الشهادة مُستخدَم من قبل ضمن نفس النوع؟ (طبقة حماية ضد التكرار).
     * نطاق الفحص حسب النوع لأن «رقم الإذن» (صادر) و«رقم الشهادة» (وارد) فضاءان مختلفان.
     * الفارغ لا يُعتبر تكرارًا، و$exceptId يستثني السجل نفسه عند التعديل.
     */
    public static function noExists(?string $no, string $type, ?int $exceptId = null): bool
    {
        $no = trim((string)$no);
        if ($no === '') { return false; }
        $sql = 'SELECT COUNT(*) FROM permits WHERE permit_no = ? AND permit_type = ?';
        $params = [$no, $type];
        if ($exceptId !== null) { $sql .= ' AND id <> ?'; $params[] = $exceptId; }
        return (int) Database::scalar($sql, $params) > 0;
    }

    // ---------------- مرشد الإيصالات الذكي (v32) ----------------
    //
    // ⚠️ استرشادي بحت: يقول «الإذن ده المتوقّع فيه كذا» ويعرض الناقص — بلا أي إلزام.
    // لا يمنع إدخالاً ولا فوترة ولا تقفيلاً، ولا يمسّ أي حساب مالي أو عهدة.
    // القواعد مشروطة حسب: النوع (صادر/وارد) + التوكيل + عدد الحاويات + إجابات checklist.
    // الكمية غير مهمة (المالك يُدخل قيمة كل إيصال بيده) فنعرض «نوع» الإيصال فقط.

    /** قائمة توكيلات من الإعدادات (JSON array) — قابلة للتعديل من صفحة الإعدادات. */
    public static function ruleAgencies(string $key): array
    {
        $raw = (string) App::setting($key, '');
        $arr = json_decode($raw, true);
        if (!is_array($arr)) { return []; }
        return array_values(array_filter(array_map(fn($a) => trim((string)$a), $arr), fn($a) => $a !== ''));
    }

    /** هل ينطبق أحد التوكيلات في القائمة على توكيل الإذن؟ (مطابقة مرنة باحتواء الاسم) */
    private static function agencyInList(?string $agency, array $list): bool
    {
        $agency = trim((string)$agency);
        if ($agency === '' || !$list) { return false; }
        foreach ($list as $a) {
            if ($a !== '' && (mb_stripos($agency, $a) !== false || mb_stripos($a, $agency) !== false)) {
                return true;
            }
        }
        return false;
    }

    /** مطابقة اسم إيصال: يحتوي أيّ كلمة من $inc ولا يحتوي أيّ كلمة من $exc. */
    private static function noteMatches(array $notes, array $inc, array $exc = []): bool
    {
        foreach ($notes as $n) {
            $ok = false;
            foreach ($inc as $w) { if ($w !== '' && mb_stripos($n, $w) !== false) { $ok = true; break; } }
            if (!$ok) { continue; }
            foreach ($exc as $w) { if ($w !== '' && mb_stripos($n, $w) !== false) { $ok = false; break; } }
            if ($ok) { return true; }
        }
        return false;
    }

    /**
     * قائمة الإيصالات المتوقّعة لإذن بعينه — محرّك القواعد.
     * $permit = صف الإذن (يحتاج: permit_type, agency, chk_ateeq, chk_load, chk_nabatshia, chk_lamanea).
     * $notes  = أسماء الإيصالات المُدخَلة (لاكتشاف «بوصلة»→إشعاع و«تأمين»→يشيل التخصيص).
     * يرجع صفوف: ['label'=>.., 'inc'=>[..], 'exc'=>[..], 'optional'=>bool, 'hint'=>..].
     */
    public static function expectedReceiptDefs(array $permit, array $notes = []): array
    {
        $type   = ($permit['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader';
        $agency = $permit['agency'] ?? '';
        $ateeq  = $permit['chk_ateeq'] ?? '';
        $load   = $permit['chk_load'] ?? '';
        $defs   = [];

        // ── مشترك لكل الأذونات ─────────────────────────────────────────────
        $defs[] = ['label' => 'جيش', 'inc' => ['جيش'], 'exc' => [], 'optional' => false, 'hint' => 'إيصال لكل سيارة'];

        // إشعاع: يُتوقَّع فقط لو الحركة فيها مصروف «بوصلة» مُدخَل
        if (self::noteMatches($notes, ['بوصلة', 'بوصله'])) {
            $defs[] = ['label' => 'إشعاع', 'inc' => ['إشعاع', 'اشعاع'], 'exc' => [], 'optional' => false, 'hint' => 'لوجود مصاريف بوصلة'];
        }
        // نبطشية: لو السائق عدّى 11 ومحمّلش (إجابة checklist)
        if (!empty($permit['chk_nabatshia'])) {
            $defs[] = ['label' => 'نبطشية', 'inc' => ['نبطشية', 'نبطشيه'], 'exc' => [], 'optional' => false, 'hint' => 'السائق عدّى 11 ومحمّلش'];
        }

        if ($type === 'sader') {
            // ── صادر (إذن) ────────────────────────────────────────────────
            // تخصيص — إلا لو التوكيل من قائمة التأمين وفيه إيصال تأمين (يشيله)
            $insuranceReplaces = self::agencyInList($agency, self::ruleAgencies('receipt_rules_insurance_agencies'))
                              && self::noteMatches($notes, ['تأمين', 'تامين']);
            if (!$insuranceReplaces) {
                $defs[] = ['label' => 'تخصيص', 'inc' => ['تخصيص'], 'exc' => [], 'optional' => false, 'hint' => ''];
            }
            // هيئة صادر (دائمًا) + فوارغ (لو محمّل من الميناء) أو حمولة (لو من خارجه)
            // «إيصال هيئة» = «إيصال هيئة صادر» (اسم واحد) — نقبل «هيئة» المجرّدة، ونستثني «فوارغ» فقط
            $defs[] = ['label' => 'هيئة صادر', 'inc' => ['هيئة', 'هيئه'], 'exc' => ['فوارغ'], 'optional' => false, 'hint' => 'لكل حاوية — تُكتب «هيئة» أو «هيئة صادر»'];
            if ($load === 'port') {
                $defs[] = ['label' => 'هيئة فوارغ', 'inc' => ['فوارغ'], 'exc' => [], 'optional' => false, 'hint' => 'محمّل من الميناء'];
            } elseif ($load === 'outside') {
                $defs[] = ['label' => 'حمولة', 'inc' => ['حمولة', 'حموله'], 'exc' => [], 'optional' => false, 'hint' => 'محمّل من خارج الميناء'];
            }
            // تعتيق حاويات — لو التوكيل من قائمة تعتيق الحاويات (أركاس…)
            if (self::agencyInList($agency, self::ruleAgencies('receipt_rules_ateeq_agencies'))) {
                $defs[] = ['label' => 'تعتيق حاويات', 'inc' => ['تعتيق حاويات', 'تعتيق حاويه', 'حاويات'], 'exc' => ['صينية', 'صينيه'], 'optional' => false, 'hint' => 'حسب التوكيل'];
            }
            // تعتيق صينية — لو التعتيق في الصينية
            if ($ateeq === 'sacia') {
                $defs[] = ['label' => 'تعتيق صينية', 'inc' => ['صينية', 'صينيه'], 'exc' => [], 'optional' => false, 'hint' => 'التعتيق في الصينية'];
            }
        } else {
            // ── وارد (شهادة) ──────────────────────────────────────────────
            // لا تخصيص للشهادات — بدله «لا مانع» (اختياري: ممكن علينا أو على المستخلص)
            if (!empty($permit['chk_lamanea'])) {
                $defs[] = ['label' => 'لا مانع', 'inc' => ['مانع'], 'exc' => [], 'optional' => true, 'hint' => 'لو علينا (ممكن يقوم به المستخلص)'];
            }
            // هيئة (لو التعتيق في الميناء) / تعتيق (لو في ساحة خارجية)
            if ($ateeq === 'port') {
                $defs[] = ['label' => 'هيئة', 'inc' => ['هيئة', 'هيئه'], 'exc' => ['فوارغ', 'صادر'], 'optional' => false, 'hint' => 'التعتيق في الميناء'];
            } elseif ($ateeq === 'yard') {
                $defs[] = ['label' => 'تعتيق', 'inc' => ['تعتيق'], 'exc' => ['حاويات', 'صينية', 'صينيه'], 'optional' => false, 'hint' => 'التعتيق في ساحة خارجية'];
            }
        }

        return $defs;
    }

    /** قائمة الإيصالات التي أشّر المالك أنها «مش محتاجة» في هذا الإذن. */
    public static function dismissedReceipts(array $permit): array
    {
        $raw = trim((string)($permit['chk_dismissed'] ?? ''));
        if ($raw === '') { return []; }
        return array_values(array_filter(array_map('trim', explode('|', $raw)), fn($s) => $s !== ''));
    }

    /**
     * المرشد الكامل لإذن: يطبّق محرّك القواعد ويقارنه بالإيصالات المُدخَلة.
     * $receipts = صفوف بها 'note'.
     * يرجع ['items'=>[ ['label','present','optional','dismissed','hint'] ...], 'present'=>[..], 'missing'=>[..]].
     * present/missing قوائم labels (للتوافق مع العرض القديم «بدون …»).
     */
    public static function receiptGuide(array $permit, array $receipts): array
    {
        $notes = [];
        foreach ($receipts as $r) {
            $n = trim((string)($r['note'] ?? ''));
            if ($n !== '') { $notes[] = $n; }
        }
        $dismissed = self::dismissedReceipts($permit);
        $defs = self::expectedReceiptDefs($permit, $notes);

        $items = []; $present = []; $missing = [];
        foreach ($defs as $d) {
            $has  = self::noteMatches($notes, $d['inc'], $d['exc']);
            $isDismissed = in_array($d['label'], $dismissed, true);
            $items[] = [
                'label'     => $d['label'],
                'present'   => $has,
                'optional'  => (bool)($d['optional'] ?? false),
                'dismissed' => $isDismissed,
                'hint'      => $d['hint'] ?? '',
            ];
            if ($has) {
                $present[] = $d['label'];
            } elseif (!$isDismissed && empty($d['optional'])) {
                // «ناقص» الحقيقي = مطلوب وغير موجود وغير مُتجاهَل والاختياري لا يُحتسب ناقصًا
                $missing[] = $d['label'];
            }
        }
        return ['items' => $items, 'present' => $present, 'missing' => $missing];
    }

    /**
     * توافق رجعي: تغطية الإيصالات (present/missing labels).
     * $receipts = صفوف بها 'note'. $permit = صف الإذن (للقواعد المشروطة).
     */
    public static function receiptCoverage(array $receipts, array $permit = []): array
    {
        $g = self::receiptGuide($permit, $receipts);
        return ['present' => $g['present'], 'missing' => $g['missing']];
    }

    /** المرشد لإذن بعينه (استعلام مباشر — لصفحات العرض/الإدارة). */
    public static function receiptGuideForPermit(int $permitId): array
    {
        $permit = self::find($permitId) ?? [];
        $rows = Database::all(
            "SELECT note FROM permit_stages
              WHERE permit_id = ? AND stage_key = 'receipts' AND status <> 'none'",
            [$permitId]
        );
        return self::receiptGuide($permit, $rows);
    }

    /** تغطية الإيصالات لإذن بعينه (توافق رجعي). */
    public static function receiptCoverageForPermit(int $permitId): array
    {
        $g = self::receiptGuideForPermit($permitId);
        return ['present' => $g['present'], 'missing' => $g['missing']];
    }

    /**
     * تحديث قائمة «مش محتاجه» لإذن — إضافة/إزالة label (استرشادي، لا يمسّ أي حساب).
     *
     * بترجّع **true لو البند بقى متجاهَل** و false لو رجع للمطلوب. الإرجاع
     * اتضاف في المرحلة ٢ عشان الرسالة تقول اللي حصل فعلاً بدل ما تخمّن —
     * الإجراء كان بيخلص بالسكوت تمامًا.
     */
    public static function toggleDismissedReceipt(int $permitId, string $label): bool
    {
        $label = trim($label);
        if ($label === '') { return false; }
        $p = self::find($permitId);
        if (!$p) { return false; }
        $list = self::dismissedReceipts($p);
        $idx = array_search($label, $list, true);
        if ($idx === false) { $list[] = $label; $nowDismissed = true; }
        else { unset($list[$idx]); $nowDismissed = false; }
        Database::exec(
            'UPDATE permits SET chk_dismissed = ? WHERE id = ?',
            [$list ? implode('|', array_values($list)) : null, $permitId]
        );
        return $nowDismissed;
    }

    /** حفظ إجابات الـchecklist لإذن (استرشادي، لا يمسّ أي حساب). */
    public static function saveChecklist(int $permitId, array $in): void
    {
        $ateeq = in_array($in['chk_ateeq'] ?? '', ['port', 'yard', 'sacia', 'none'], true) ? $in['chk_ateeq'] : null;
        $load  = in_array($in['chk_load'] ?? '', ['port', 'outside'], true) ? $in['chk_load'] : null;
        Database::exec(
            'UPDATE permits SET chk_ateeq = ?, chk_load = ?, chk_nabatshia = ?, chk_lamanea = ? WHERE id = ?',
            [$ateeq, $load, !empty($in['chk_nabatshia']) ? 1 : 0, !empty($in['chk_lamanea']) ? 1 : 0, $permitId]
        );
    }

    /**
     * وصف الحاويات بصيغة «العدد×التصنيف» (مثل «2×20، 1×40»).
     * $breakdown = صفوف [['type'=>..,'cnt'=>..], ...] من البوالص (containerBreakdown).
     * يرجع لتصنيف/عدد الإذن نفسه إن لم توجد بوالص مصنّفة.
     */
    public static function containerLabel(array $breakdown, int $fallbackCount = 0, ?string $fallbackType = null): string
    {
        $parts = [];
        foreach ($breakdown as $c) {
            $t = trim((string)($c['type'] ?? ''));
            $n = (int)($c['cnt'] ?? 0);
            if ($t !== '' && $n > 0) { $parts[] = $n . '×' . $t; }
        }
        if ($parts) { return implode('، ', $parts); }
        $ft = trim((string)($fallbackType ?? ''));
        if ($fallbackCount > 0 && $ft !== '') { return $fallbackCount . '×' . $ft; }
        if ($fallbackCount > 0) { return $fallbackCount . ' حاوية'; }
        return '';
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    /** رقم الإصدار حسب النوع (إذن/شهادة) */
    public static function noLabel(string $type): string
    {
        return self::TYPES[$type]['no'] ?? 'الرقم';
    }

    /** البوالص المرتبطة بالإصدار */
    public static function bills(int $permitId): array
    {
        return Database::all(
            'SELECT * FROM bawalis WHERE permit_id = ? ORDER BY bill_date, id', [$permitId]
        );
    }

    /**
     * إيصالات يومٍ عملٍ معيّن الناقص فيها السعر الفعلي للعميل أو رقم الإيصال.
     * تُستخدم في شاشة «استكمال بيانات الإيصالات» داخل المصروفات — عرض فقط.
     */
    public static function receiptsMissingData(string $day): array
    {
        return Database::all(
            "SELECT s.id, s.permit_id, s.movement_id, s.amount, s.qty, s.actual_amount, s.ref_no,
                    s.note, s.entry_date, s.is_visa,
                    p.permit_type, p.permit_no, p.company, p.client,
                    m.seq AS movement_seq
             FROM permit_stages s
             JOIN permits p ON p.id = s.permit_id
             LEFT JOIN permit_movements m ON m.id = s.movement_id
             WHERE s.stage_key = 'receipts' AND s.status <> 'none' AND s.entry_date = ?
               AND (s.actual_amount IS NULL OR s.ref_no IS NULL OR s.ref_no = '')
             ORDER BY p.permit_no IS NULL, p.permit_no, s.id",
            [$day]
        );
    }

    /**
     * استكمال إيصال واحد: السعر الفعلي للعميل (سعر الوحدة × الكمية) و/أو رقم الإيصال.
     * لا يمسّ المبلغ النقدي (amount) ولا التاريخ حفاظاً على الإقفال اليومي والعهدة (قاعدة v20).
     * يُحدَّث الحقل فقط لو أُدخِلت له قيمة؛ الفارغ يُترك كما هو. يعيد true لو حصل تعديل فعلي.
     */
    public static function completeReceipt(int $stageId, ?float $unitActual, ?string $refNo): bool
    {
        $row = Database::row(
            "SELECT id, qty, actual_amount, ref_no, entry_date FROM permit_stages
             WHERE id = ? AND stage_key = 'receipts' AND status <> 'none'",
            [$stageId]
        );
        if (!$row) { return false; }

        $sets = []; $params = []; $newActual = null; $newRef = null;
        if ($unitActual !== null) {
            $qty       = max(1, (int)$row['qty']);
            $newActual = round($unitActual * $qty, 2);
            $sets[]    = 'actual_amount = ?';
            $params[]  = $newActual;
        }
        if ($refNo !== null && $refNo !== '') {
            $newRef   = mb_substr($refNo, 0, 120);
            $sets[]   = 'ref_no = ?';
            $params[] = $newRef;
        }
        if (!$sets) { return false; }

        $params[] = $stageId;
        Database::exec('UPDATE permit_stages SET '.implode(', ', $sets).' WHERE id = ?', $params);

        Audit::log('update', 'permit_stages', $stageId,
            ['actual_amount' => $row['actual_amount'], 'ref_no' => $row['ref_no']],
            ['actual_amount' => $newActual ?? $row['actual_amount'], 'ref_no' => $newRef ?? $row['ref_no']],
            'استكمال بيانات إيصال #'.$stageId.
                ($newActual !== null ? ' — الفعلي '.number_format($newActual, 2, '.', ',') : '').
                ($newRef !== null ? ' — رقم '.$newRef : ''),
            ['record_date' => (string)($row['entry_date'] ?? '') ?: null]);
        return true;
    }

    /** بنود المراحل (كلها أو مرحلة بعينها) */
    public static function stages(int $permitId, ?string $key = null): array
    {
        if ($key !== null) {
            return Database::all(
                'SELECT * FROM permit_stages WHERE permit_id = ? AND stage_key = ? ORDER BY entry_date, id',
                [$permitId, $key]
            );
        }
        return Database::all(
            'SELECT * FROM permit_stages WHERE permit_id = ? ORDER BY id', [$permitId]
        );
    }

    /** بنود المراحل مجمّعة حسب المفتاح */
    public static function stagesGrouped(int $permitId): array
    {
        $out = [];
        foreach (self::stages($permitId) as $s) {
            $out[$s['stage_key']][] = $s;
        }
        return $out;
    }

    /** المولدات المطابقة لرقم الإذن */
    public static function generators(?string $permitNo): array
    {
        if (!$permitNo) { return []; }
        return Database::all(
            'SELECT * FROM generators WHERE permit_no = ? ORDER BY gen_date, id', [$permitNo]
        );
    }

    /** إجماليات الإصدار (للعرض في القائمة والتفاصيل) */
    public static function totals(int $permitId): array
    {
        $expense = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM permit_stages s WHERE s.permit_id = ? AND " . self::expenseSql('s'),
            [$permitId]
        );
        $revenue = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM permit_stages s WHERE s.permit_id = ? AND " . self::revenueSql('s'),
            [$permitId]
        );
        // السعر الفعلي (اللي هنحاسب بيه العميل) = مجموع actual_amount على كل بنود الإذن
        $charged = (float) Database::scalar(
            "SELECT COALESCE(SUM(actual_amount),0) FROM permit_stages WHERE permit_id = ? AND actual_amount IS NOT NULL",
            [$permitId]
        );
        $b = Database::row(
            "SELECT COALESCE(SUM(custody),0) custody, COALESCE(SUM(debt),0) debt,
                    COALESCE(SUM(remaining),0) remaining, COUNT(*) n
             FROM bawalis WHERE permit_id = ?",
            [$permitId]
        ) ?: ['custody'=>0,'debt'=>0,'remaining'=>0,'n'=>0];
        return [
            'expense'   => round($expense, 2),
            'fees'      => round($revenue, 2),
            'charged'   => round($charged, 2),
            'custody'   => round((float)$b['custody'], 2),
            'debt'      => round((float)$b['debt'], 2),
            'remaining' => round((float)$b['remaining'], 2),
            'bills'     => (int)$b['n'],
        ];
    }
}
