<?php
namespace App\Models;

use App\Core\Database;

/**
 * محرك البحث الشامل الذكي — يبحث في كل جداول النظام دفعةً واحدة.
 *
 * الذكاء هنا أربع طبقات:
 *   1) تطبيع عربي + أرقام: يوحّد الهمزات/الألف/ة-ه/ى-ي/التطويل/التشكيل ويحوّل
 *      الأرقام العربية (٠-٩) للإنجليزية، فيلاقي «احمد/أحمد/محمّد» و«٢٢٥/225» سواء.
 *      التطبيع يتمّ في SQL (على العمود) وفي PHP (على الاستعلام) بنفس القواعد.
 *   2) بحث متعدّد الكلمات: «محمد ٢٢» = السائق محمد + الحاوية ٢٢ في نفس السجل
 *      (كل كلمة لازم تطابق عمودًا ما — AND بين الكلمات، OR بين الأعمدة).
 *   3) ترتيب بالأهمية: المطابقة التامة > البادئة > الاحتواء، مع ترجيح الحقل
 *      الرئيسي (العنوان) على الفرعي، فالمطابقة الدقيقة تطلع أول نتيجة دائمًا.
 *   4) تسامح إملائي (fuzzy): لو مفيش نتائج، يقترح الأقرب بمسافة Levenshtein
 *      على أعمدة الأسماء الرئيسية.
 *
 * لكل مصدر: أعمدة البحث، بنّاء العنوان/الوصف، رابط عميق للسجل، وحقل التاريخ/المبلغ.
 * النتيجة مجمّعة حسب التصنيف، وكل صف يحمل «الحقل المطابق» (سائق/حاوية/رقم سيارة…)
 * حتى يعرف المستخدم لماذا ظهرت هذه النتيجة.
 */
class Search
{
    /** تسميات عربية للأعمدة — تظهر كوسم يوضّح سبب المطابقة */
    private const FIELD_LABELS = [
        'bill_number'      => 'رقم بوليصة',
        'permit_no'        => 'رقم إذن',
        'cert_number'      => 'رقم شهادة',
        'cert_name'        => 'اسم شهادة',
        'declaration_owner'=> 'صاحب البيان',
        'owner'            => 'صاحب البيان',
        'company'          => 'شركة',
        'client'           => 'وكيل',
        'container_no'     => 'حاوية',
        'container_type'   => 'نوع حاوية',
        'car_no'           => 'رقم سيارة',
        'driver'           => 'سائق',
        'car_owner'        => 'مالك سيارة',
        'destination'      => 'وجهة',
        'address'          => 'عنوان',
        'statement_no'     => 'رقم كشف',
        'invoice_no'       => 'رقم فاتورة',
        'doc_no'           => 'رقم مستند',
        'internal_no'      => 'رقم داخلي',
        'serial_no'        => 'رقم مسلسل',
        'custom_title'     => 'عنوان الفاتورة',
        'ref_no'           => 'رقم إيصال',
        'name'             => 'اسم',
        'client_name'      => 'عميل',
        'shipping_co'      => 'شركة شحن',
        'agency'           => 'توكيل',
        'port'             => 'ميناء',
        'beneficiary'      => 'مستفيد',
        'category'         => 'تصنيف',
        'classification'   => 'تصنيف',
        'phone'            => 'هاتف',
        'account_key'      => 'حساب',
        'note'             => 'ملاحظة',
    ];

    /**
     * جدول التطبيع (حرف ⇐ بديله). يُستخدم في PHP (strtr) وفي SQL (REPLACE).
     * لا يحوي محارف اقتباس فيؤمَن حقنه نصيًّا في SQL.
     * التشكيل والتطويل والألف الخنجرية تُحذَف على حدة (SQL_STRIP + preg في PHP).
     */
    private const NORM_MAP = [
        'أ'=>'ا','إ'=>'ا','آ'=>'ا','ٱ'=>'ا',   // توحيد الألف
        'ى'=>'ي','ة'=>'ه','ؤ'=>'و','ئ'=>'ي',   // ى/ة/ؤ/ئ
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9', // عربية
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', // فارسية
    ];

    /** محارف تُحذف تمامًا عند التطبيع (تطويل + تشكيل + ألف خنجرية) */
    private const SQL_STRIP = [
        'ـ',            // تطويل \x{0640}
        'ً','ٌ','ٍ','َ','ُ','ِ','ّ','ْ', // تشكيل \x{064B}-\x{0652}
        'ٰ',            // ألف خنجرية \x{0670}
    ];

    /**
     * تعريف كل مصادر البحث. كل مصدر يُنفَّذ باستعلام واحد ثم يُبنى منه صفوف موحّدة.
     * الترتيب هنا = الترتيب الأساسي للمجموعات (يُعاد ترتيبه حسب الأهمية والنوع).
     * 'fuzzy' = أعمدة الأسماء التي يُطبَّق عليها التسامح الإملائي عند غياب النتائج.
     */
    private static function sources(): array
    {
        return [
            'bawalis' => [
                'title' => 'البوالص', 'icon' => 'truck', 'table' => 'bawalis',
                'cols'  => ['bill_number','permit_no','cert_number','declaration_owner',
                            'company','container_no','container_type','car_no','driver',
                            'car_owner','destination'],
                'label' => ['bill_number','cert_number','permit_no'],
                'sub'   => ['company','declaration_owner','driver','car_no','destination'],
                'fuzzy' => ['declaration_owner','company','driver','car_owner'],
                'amount'=> 'freight', 'date' => 'bill_date',
                'route' => 'bawalis/{id}',
            ],
            'legacy_bawalis' => [
                'title' => 'أرشيف البوالص القديمة', 'icon' => 'inbox', 'table' => 'legacy_bawalis',
                'cols'  => ['bill_number','declaration_owner','company','container_no','car_no',
                            'driver','car_owner','destination','classification'],
                'label' => ['bill_number'],
                'sub'   => ['company','declaration_owner','driver','car_no','destination'],
                'fuzzy' => ['declaration_owner','company','driver'],
                'amount'=> 'freight', 'date' => 'bill_date',
                'route' => 'legacy/{id}',
            ],
            'permits' => [
                'title' => 'الإصدارات (أذونات/شهادات)', 'icon' => 'folder', 'table' => 'permits',
                'cols'  => ['permit_no','company','client','destination','shipping_co','agency','port','container_type','note'],
                'label' => ['permit_no'],
                'sub'   => ['company','client','destination','port'],
                'fuzzy' => ['company','client'],
                'amount'=> null, 'date' => 'permit_date',
                'route' => 'permits/{id}',
            ],
            'receipts' => [
                // الإيصالات المسجّلة داخل حركات الأذونات (permit_stages بمفتاح receipts).
                // البحث بـ «رقم الإيصال» (ref_no) أو اسمه/بيانه (note).
                'title' => 'الإيصالات', 'icon' => 'receipt', 'table' => 'permit_stages',
                'cols'  => ['ref_no','note'],
                'where_extra' => "stage_key = 'receipts'",
                'label' => ['note','ref_no'],
                'sub'   => ['ref_no'],
                'amount'=> 'amount', 'date' => 'entry_date',
                'route' => 'permits/{permit_id}/manage#stage-receipts-{movement_id}',
            ],
            'takhlis' => [
                'title' => 'التخليص الجمركي', 'icon' => 'clipboard', 'table' => 'takhlis',
                'cols'  => ['cert_name','cert_number'],
                'label' => ['cert_number','cert_name'],
                'sub'   => ['cert_name'],
                'amount'=> 'fees', 'date' => 'cert_date',
                'route' => 'takhlis/{id}/edit',
            ],
            'generators' => [
                'title' => 'المولدات', 'icon' => 'cpu', 'table' => 'generators',
                'cols'  => ['company','address','permit_no'],
                'label' => ['permit_no','company'],
                'sub'   => ['company','address'],
                'fuzzy' => ['company'],
                'amount'=> 'freight', 'date' => 'gen_date',
                'route' => 'generators/{id}/edit',
            ],
            'permit_invoices' => [
                'title' => 'فواتير الأذونات', 'icon' => 'receipt', 'table' => 'permit_invoices',
                'cols'  => ['doc_no','internal_no','serial_no','custom_title','client','company','permit_no','destination','agency','port'],
                'label' => ['serial_no','doc_no','internal_no'],
                'sub'   => ['client','company','permit_no'],
                'fuzzy' => ['client','company'],
                'amount'=> 'total', 'date' => 'invoice_date',
                'route' => 'permits/invoice/{id}',
            ],
            'owner_statements' => [
                'title' => 'كشوف أصحاب البيان', 'icon' => 'file-text', 'table' => 'owner_statements',
                'cols'  => ['doc_no','owner'],
                'label' => ['doc_no','owner'],
                'sub'   => ['owner'],
                'fuzzy' => ['owner'],
                'amount'=> 'stat_remaining', 'date' => 'statement_date',
                'route' => 'statements/{id}',
            ],
            'debt_accounts' => [
                'title' => 'المديونيات', 'icon' => 'credit-card', 'table' => 'debt_accounts',
                'cols'  => ['name','account_key','note'],
                'label' => ['name'],
                'sub'   => ['note'],
                'fuzzy' => ['name'],
                'amount'=> null, 'date' => null,
                'route' => 'debts/account/{id}',
            ],
            'movements' => [
                'title' => 'الحركات', 'icon' => 'shuffle', 'table' => 'movements',
                'cols'  => ['driver','car_owner','shipping_co','agency','port','note'],
                'label' => ['driver','car_owner'],
                'sub'   => ['shipping_co','agency','port'],
                'fuzzy' => ['driver','car_owner'],
                'amount'=> null, 'date' => 'movement_date',
                'route' => 'movements/{id}/edit',
            ],
            'expenses' => [
                'title' => 'المصروفات', 'icon' => 'trending-down', 'table' => 'manual_expenses',
                'cols'  => ['beneficiary','category','note'],
                'label' => ['beneficiary','category'],
                'sub'   => ['category','note'],
                'fuzzy' => ['beneficiary'],
                'amount'=> 'amount', 'date' => 'exp_date',
                'route' => 'expenses/{id}/edit',
            ],
            'client_payments' => [
                'title' => 'تحصيلات العملاء', 'icon' => 'wallet', 'table' => 'client_payments',
                'cols'  => ['client_name','note'],
                'label' => ['client_name'],
                'sub'   => ['note'],
                'fuzzy' => ['client_name'],
                'amount'=> 'amount', 'date' => 'pay_date',
                'route' => 'payments',
            ],
            'reps' => [
                'title' => 'المناديب', 'icon' => 'user', 'table' => 'reps',
                'cols'  => ['name','phone','note'],
                'label' => ['name'],
                'sub'   => ['phone','note'],
                'fuzzy' => ['name'],
                'amount'=> null, 'date' => null,
                'route' => 'reps/{id}',
            ],
            'agents' => [
                'title' => 'الوكلاء (العملاء)', 'icon' => 'user', 'table' => 'agents',
                'cols'  => ['name','note'],
                'label' => ['name'],
                'sub'   => ['note'],
                'fuzzy' => ['name'],
                'amount'=> null, 'date' => null,
                'route' => 'directory/agent/{id}',
            ],
            'companies' => [
                'title' => 'الشركات (الجهات)', 'icon' => 'building', 'table' => 'companies',
                'cols'  => ['name','note'],
                'label' => ['name'],
                'sub'   => ['note'],
                'fuzzy' => ['name'],
                'amount'=> null, 'date' => null,
                'route' => 'directory/company/{id}',
            ],
        ];
    }

    /** قائمة التصنيفات (المفتاح ⇐ العنوان/الأيقونة/هل له تاريخ) — لواجهة فلتر النوع */
    public static function categories(): array
    {
        $out = [];
        foreach (self::sources() as $k => $s) {
            $out[$k] = ['title' => $s['title'], 'icon' => $s['icon'], 'dated' => !empty($s['date'])];
        }
        return $out;
    }

    /**
     * البحث الشامل. يُرجع مجموعات مرتّبة بالأهمية.
     * كل مجموعة قد تحمل 'fuzzy'=>true إن كانت نتائج تسامح إملائي (تهجئة قريبة).
     * @param ?string  $from  تاريخ البداية (YYYY-MM-DD) — يُقيّد المصادر الحاملة للتاريخ فقط
     * @param ?string  $to    تاريخ النهاية (YYYY-MM-DD)
     * @param string[] $types تصنيفات محددة (مفاتيح المصادر)؛ فارغ = الكل
     * @return array<string,array{title:string,icon:string,rows:array,fuzzy?:bool}>
     */
    public static function query(string $q, int $perGroup = 6, ?string $from = null, ?string $to = null, array $types = []): array
    {
        $q = trim($q);
        if ($q === '') { return []; }

        $from = self::validDate($from);
        $to   = self::validDate($to);
        $dateActive = ($from !== null || $to !== null);

        // فلتر النوع: أبقِ المفاتيح الصحيحة فقط
        $valid = array_keys(self::sources());
        $types = array_values(array_intersect($types, $valid));

        // تطبيع الاستعلام وتقطيعه لكلمات (بحث AND متعدّد الكلمات)
        $normQ  = self::normText($q);
        $tokens = preg_split('/\s+/u', $normQ, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$tokens) { return []; }

        $numeric = self::isNumberLike($q);
        $out     = [];

        foreach (self::sources() as $key => $src) {
            // فلتر النوع فعّال + هذا المصدر غير مختار ⇒ يُستبعد
            if ($types && !in_array($key, $types, true)) { continue; }
            // فلتر التاريخ فعّال + المصدر بلا حقل تاريخ (وكلاء/شركات/مديونيات) ⇒ يُستبعد
            if ($dateActive && empty($src['date'])) { continue; }
            try {
                $rows = self::runSource($src, $tokens, $normQ, $perGroup, $from, $to);
            } catch (\Throwable $e) {
                // جدول غير موجود (هجرة لم تُطبَّق) أو خطأ عمود — تجاهل هذا المصدر فقط
                continue;
            }
            if ($rows) {
                $out[$key] = ['title' => $src['title'], 'icon' => $src['icon'], 'rows' => $rows,
                              'score' => $rows[0]['_score']];
            }
        }

        // تسامح إملائي: لو لا نتائج إطلاقًا، جرّب أقرب الأسماء تهجئةً
        if (!$out) {
            $out = self::fuzzyQuery($normQ, $tokens, $perGroup, $types, $from, $to, $dateActive);
        }

        return self::prioritize($out, $numeric);
    }

    /** ينفّذ استعلام مصدر واحد (بحث AND متعدّد الكلمات) ويحوّله لصفوف مرتّبة بالأهمية */
    private static function runSource(array $src, array $tokens, string $normQ, int $limit, ?string $from = null, ?string $to = null): array
    {
        // تعبير التطبيع لكل عمود يُحسب مرة ويُعاد استخدامه عبر كل الكلمات
        $normCols = [];
        foreach ($src['cols'] as $c) { $normCols[$c] = self::normSql($c); }

        // كل كلمة: (عمود1 LIKE ? OR عمود2 LIKE ? …) — والكلمات مجموعة بـ AND
        $andClauses = [];
        $params = [];
        foreach ($tokens as $tok) {
            $like = '%' . self::escapeLike($tok) . '%';
            $ors  = [];
            foreach ($normCols as $expr) {
                $ors[] = "$expr LIKE ? ESCAPE '\\\\'";
                $params[] = $like;
            }
            $andClauses[] = '(' . implode(' OR ', $ors) . ')';
        }
        $where = '(' . implode(' AND ', $andClauses) . ')';

        // قيد ثابت على المصدر (مثل: الإيصالات = permit_stages بمفتاح receipts فقط)
        if (!empty($src['where_extra'])) { $where .= ' AND (' . $src['where_extra'] . ')'; }

        // قيد التاريخ (للمصادر الحاملة لحقل تاريخ فقط)
        if (!empty($src['date'])) {
            if ($from !== null) { $where .= " AND `{$src['date']}` >= ?"; $params[] = $from; }
            if ($to   !== null) { $where .= " AND `{$src['date']}` <= ?"; $params[] = $to; }
        }

        // نجلب نافذة أوسع من المطلوب لنرتّب بالأهمية في PHP ثم نقتطع
        $fetch = max($limit * 5, 40);
        $order = $src['date'] ? "ORDER BY `{$src['date']}` DESC" : "ORDER BY `id` DESC";
        $sql = "SELECT * FROM `{$src['table']}` WHERE $where $order LIMIT $fetch";

        $rows = Database::all($sql, $params);
        return self::rankRows($src, $rows, $tokens, $normQ, $limit);
    }

    /** يبني الصفوف الموحّدة، يحسب درجة الأهمية لكل صف، يرتّب ويقتطع */
    private static function rankRows(array $src, array $rows, array $tokens, string $normQ, int $limit): array
    {
        $built = [];
        foreach ($rows as $r) {
            $built[] = self::makeRow($src, $r, $tokens, $normQ);
        }
        // ترتيب: الأهمية تنازليًّا، ثم التاريخ/المعرّف تنازليًّا (كسر التعادل)
        usort($built, function ($a, $b) {
            if ($a['_score'] !== $b['_score']) { return $b['_score'] <=> $a['_score']; }
            if ($a['date'] !== $b['date'])     { return strcmp((string)$b['date'], (string)$a['date']); }
            return (int)$b['id'] <=> (int)$a['id'];
        });
        return array_slice($built, 0, $limit);
    }

    /** يبني صفًّا موحّدًا من سجل خام + يحسب درجة أهميته مقابل الاستعلام */
    private static function makeRow(array $src, array $r, array $tokens, string $normQ, ?string $fuzzyField = null): array
    {
        [$score, $field] = self::scoreRow($src, $r, $tokens, $normQ);
        return [
            'id'     => $r['id'],
            'label'  => self::pick($r, $src['label'], 3, ' · ') ?: '(بدون عنوان)',
            'sub'    => self::pick($r, $src['sub'], 3, ' — '),
            'field'  => $fuzzyField ?? $field,
            'amount' => $src['amount'] ? (float)($r[$src['amount']] ?? 0) : null,
            'date'   => $src['date'] ? ($r[$src['date']] ?? '') : '',
            'url'    => url(self::buildRoute($src['route'], $r)),
            '_score' => $fuzzyField !== null ? -1 : $score, // نتائج fuzzy درجتها أدنى دائمًا
        ];
    }

    /**
     * درجة أهمية الصف + الحقل المطابق. الأوزان:
     *   مطابقة تامة 1000 · بادئة 500 · احتواء الاستعلام كاملًا 250 · تغطية كلمة 60.
     *   ويُرجَّح الحقل الرئيسي (عنوان ×3) على الفرعي (×1) على غيره (×0.5).
     * @return array{0:int,1:?string}  [الدرجة، تسمية الحقل المطابق]
     */
    private static function scoreRow(array $src, array $r, array $tokens, string $normQ): array
    {
        $labelSet = array_flip($src['label']);
        $subSet   = array_flip($src['sub']);
        $score = 0;
        $bestField = null;
        $bestFieldW = -1;

        foreach ($src['cols'] as $c) {
            $raw = (string)($r[$c] ?? '');
            if ($raw === '') { continue; }
            $v = self::normText($raw);
            if ($v === '') { continue; }

            // وزن الحقل: عنوان > فرعي > عادي
            $w = isset($labelSet[$c]) ? 3.0 : (isset($subSet[$c]) ? 1.0 : 0.5);

            $colScore = 0;
            if ($v === $normQ)                        { $colScore = 1000; } // مطابقة تامة
            elseif (self::startsWith($v, $normQ))     { $colScore = 500;  } // بادئة
            elseif (mb_strpos($v, $normQ, 0, 'UTF-8') !== false) { $colScore = 250; } // احتواء كامل

            // تغطية الكلمات (تفيد البحث متعدّد الكلمات وإن لم يوجد احتواء متّصل)
            $hits = 0;
            foreach ($tokens as $tok) {
                if ($tok !== '' && mb_strpos($v, $tok, 0, 'UTF-8') !== false) { $hits++; }
            }
            $colScore += $hits * 60;

            if ($colScore > 0) {
                $score += (int)round($colScore * $w);
                // الحقل المطابق المعروض = صاحب أعلى وزن
                if ($hits > 0 && $w > $bestFieldW) {
                    $bestFieldW = $w;
                    $bestField  = self::FIELD_LABELS[$c] ?? null;
                }
            }
        }
        return [$score, $bestField];
    }

    /**
     * تسامح إملائي: يُستدعى فقط عند غياب أي نتيجة. يبحث عن أقرب الأسماء
     * تهجئةً (Levenshtein على مفتاح مطبَّع) في أعمدة الأسماء الرئيسية لكل مصدر،
     * ثم يجلب سجلاتها. النتائج تُوسَم fuzzy وتُعرض تحت المطابقات الدقيقة.
     */
    private static function fuzzyQuery(string $normQ, array $tokens, int $limit, array $types, ?string $from, ?string $to, bool $dateActive): array
    {
        // نطبّق التسامح على الاستعلام ككل (اسم مكوّن من كلمة أو أكثر)
        $len = mb_strlen($normQ, 'UTF-8');
        if ($len < 3) { return []; }               // قصير جدًّا ⇒ ضجيج
        $maxDist = $len <= 4 ? 1 : ($len <= 7 ? 2 : 3);
        $out = [];

        foreach (self::sources() as $key => $src) {
            if (empty($src['fuzzy'])) { continue; }
            if ($types && !in_array($key, $types, true)) { continue; }
            if ($dateActive && empty($src['date'])) { continue; }

            try {
                $matchedVals = self::fuzzyValues($src, $normQ, $maxDist);
                if (!$matchedVals) { continue; }
                $rows = self::fuzzyRows($src, $matchedVals, $limit, $from, $to);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$rows) { continue; }

            $built = [];
            foreach ($rows as $r) {
                $built[] = self::makeRow($src, $r, $tokens, $normQ, 'تهجئة قريبة');
            }
            $out[$key] = ['title' => $src['title'], 'icon' => $src['icon'],
                          'rows' => array_slice($built, 0, $limit), 'fuzzy' => true, 'score' => -1];
        }
        return $out;
    }

    /** يجلب القيم المميّزة من أعمدة الأسماء ويرشّح الأقرب تهجئةً للاستعلام */
    private static function fuzzyValues(array $src, string $normQ, int $maxDist): array
    {
        $seen = [];   // مفتاح مطبَّع ⇒ [القيمة الأصلية, المسافة]
        foreach ($src['fuzzy'] as $col) {
            $vals = Database::all(
                "SELECT DISTINCT `$col` AS v FROM `{$src['table']}` WHERE `$col` IS NOT NULL AND `$col` <> '' LIMIT 5000"
            );
            foreach ($vals as $row) {
                $orig = (string)$row['v'];
                $nk   = self::normText($orig);
                if ($nk === '') { continue; }
                // مطابقة كلمة داخل اسم مركّب أيضًا (أقرب كلمة)
                $dist = self::nameDistance($nk, $normQ);
                if ($dist <= $maxDist && (!isset($seen[$nk]) || $dist < $seen[$nk][1])) {
                    $seen[$nk] = [$orig, $dist, $col];
                }
            }
        }
        if (!$seen) { return []; }
        // رتّب بالأقرب مسافةً واحتفظ بأفضل بضع قيم
        uasort($seen, fn($a, $b) => $a[1] <=> $b[1]);
        return array_slice($seen, 0, 8, true);
    }

    /** أقرب مسافة Levenshtein بين الاستعلام والاسم كاملًا أو أيٍّ من كلماته */
    private static function nameDistance(string $name, string $q): int
    {
        $best = self::lev($name, $q);
        if ($best === 0) { return 0; }
        foreach (preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $d = self::lev($w, $q);
            if ($d < $best) { $best = $d; }
        }
        return $best;
    }

    /** Levenshtein آمن لليونيكود (levenshtein المدمجة تعمل بالبايت لا بالحرف) */
    private static function lev(string $a, string $b): int
    {
        if ($a === $b) { return 0; }
        $aa = self::mbChars($a);
        $bb = self::mbChars($b);
        $la = count($aa); $lb = count($bb);
        if ($la === 0) { return $lb; }
        if ($lb === 0) { return $la; }
        $prev = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $lb; $j++) {
                $cost = ($aa[$i-1] === $bb[$j-1]) ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j-1] + 1, $prev[$j-1] + $cost);
            }
            $prev = $cur;
        }
        return $prev[$lb];
    }

    private static function mbChars(string $s): array
    {
        return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** يجلب سجلات المصدر التي تطابق قيمها أحد أعمدة الأسماء المرشَّحة */
    private static function fuzzyRows(array $src, array $matchedVals, int $limit, ?string $from, ?string $to): array
    {
        // نبني OR على كل (عمود fuzzy = قيمة) لكل قيمة مرشَّحة
        $ors = []; $params = [];
        $origVals = array_map(fn($x) => $x[0], array_values($matchedVals));
        foreach ($src['fuzzy'] as $col) {
            foreach ($origVals as $val) {
                $ors[] = "`$col` = ?";
                $params[] = $val;
            }
        }
        if (!$ors) { return []; }
        $where = '(' . implode(' OR ', $ors) . ')';
        if (!empty($src['where_extra'])) { $where .= ' AND (' . $src['where_extra'] . ')'; }
        if (!empty($src['date'])) {
            if ($from !== null) { $where .= " AND `{$src['date']}` >= ?"; $params[] = $from; }
            if ($to   !== null) { $where .= " AND `{$src['date']}` <= ?"; $params[] = $to; }
        }
        $order = $src['date'] ? "ORDER BY `{$src['date']}` DESC" : "ORDER BY `id` DESC";
        $fetch = max($limit * 3, 20);
        return Database::all("SELECT * FROM `{$src['table']}` WHERE $where $order LIMIT $fetch", $params);
    }

    /** يبني رابط السجل بإحلال أي عنصر نائب {col} بقيمة العمود المقابل من الصف */
    private static function buildRoute(string $route, array $row): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($row) {
            return (string)($row[$m[1]] ?? '');
        }, $route);
    }

    /** يبني نصًّا من أول القيم غير الفارغة لأعمدة معطاة */
    private static function pick(array $row, array $cols, int $max, string $sep): string
    {
        $parts = [];
        foreach ($cols as $c) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v !== '') { $parts[] = $v; }
            if (count($parts) >= $max) { break; }
        }
        return implode($sep, $parts);
    }

    /**
     * تطبيع نصّ عربي/رقمي (PHP): يوحّد الألف/ة/ى/الهمزات، يحذف التشكيل والتطويل،
     * ويحوّل الأرقام العربية/الفارسية للإنجليزية. يماثل normSql تمامًا.
     */
    public static function normText($s): string
    {
        $s = trim((string)$s);
        if ($s === '') { return ''; }
        $s = preg_replace('/[\x{0640}\x{064B}-\x{0652}\x{0670}]/u', '', $s); // تطويل/تشكيل/ألف خنجرية
        $s = strtr($s, self::NORM_MAP);
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower(trim($s), 'UTF-8');
    }

    /**
     * تعبير SQL يطبّق نفس قواعد normText على عمود، حتى تتطابق المقارنة
     * بين العمود المطبَّع والاستعلام المطبَّع. المحارف كلها ثابتة آمنة الحقن.
     */
    private static function normSql(string $col): string
    {
        $expr = "`$col`";
        foreach (self::SQL_STRIP as $ch) { $expr = "REPLACE($expr,'$ch','')"; }
        foreach (self::NORM_MAP as $from => $to) { $expr = "REPLACE($expr,'$from','$to')"; }
        return "LOWER($expr)";
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_substr($haystack, 0, mb_strlen($needle, 'UTF-8'), 'UTF-8') === $needle;
    }

    /** يتحقق من صيغة التاريخ YYYY-MM-DD ويعيده، وإلا null */
    private static function validDate(?string $d): ?string
    {
        $d = trim((string)$d);
        if ($d === '') { return null; }
        $dt = \DateTime::createFromFormat('Y-m-d', $d);
        return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
    }

    /** هل الاستعلام رقمي في جوهره (رقم بوليصة/حاوية/سيارة)؟ */
    private static function isNumberLike(string $q): bool
    {
        $q = self::normText($q); // بعد تحويل الأرقام العربية
        return (bool)preg_match('/^[\s\d\-\/]+$/u', $q) && preg_match('/\d/u', $q);
    }

    /** تهريب رموز LIKE الخاصة داخل الاستعلام */
    private static function escapeLike(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }

    /**
     * ترتيب المجموعات: الأقوى مطابقةً أولًا (أعلى درجة صف)، ثم — عند التعادل —
     * حسب طبيعة الاستعلام (رقمي يقدّم البوالص/الإيصالات، نصّي يقدّم الأسماء).
     */
    private static function prioritize(array $groups, bool $numeric): array
    {
        $base = $numeric
            ? ['bawalis','legacy_bawalis','receipts','permits','permit_invoices','owner_statements','generators','takhlis','movements','debt_accounts','expenses','client_payments','reps','agents','companies']
            : ['agents','companies','bawalis','legacy_bawalis','permits','receipts','debt_accounts','movements','expenses','client_payments','reps','takhlis','generators','permit_invoices','owner_statements'];
        $rank = array_flip($base);

        // نرتّب حاملين المفتاح: الأعلى درجةً أولًا، ثم الترتيب الأساسي عند التعادل
        $items = [];
        foreach ($groups as $k => $g) { $items[] = [$k, $g, $rank[$k] ?? 99]; }
        usort($items, function ($x, $y) {
            $sx = $x[1]['score'] ?? 0; $sy = $y[1]['score'] ?? 0;
            if ($sx !== $sy) { return $sy <=> $sx; }   // الأعلى درجةً أولًا
            return $x[2] <=> $y[2];                     // ثم الترتيب الأساسي
        });

        $sorted = [];
        foreach ($items as [$k, $g]) {
            unset($g['score']);
            foreach ($g['rows'] as &$row) { unset($row['_score']); }
            unset($row);
            $sorted[$k] = $g;
        }
        return $sorted;
    }
}
