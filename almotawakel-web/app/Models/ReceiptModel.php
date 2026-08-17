<?php
namespace App\Models;

use App\Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * موديل الإيصالات المدرَّب — «الإذن ده المتوقّع فيه إيه، وكام، وامتى؟» (v101)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * طلب المالك: «عاوز الوحدة تتدرّب فعلاً على كل الأذون المقفولة عشان تتوقّع
 * الإيصالات المطلوبة في كل إذن — كل كام عربية بتاخد كام هيئة، والجيش امتى،
 * والبوصلة امتى، والتعتيق امتى».
 *
 * ═══ المقيس قبل ما يتكتب سطر واحد (بيانات إنتاج: ١٢٢ إذن مقفول · ١١٥٨ بند) ═══
 *
 *  ① **أي سياق بيتوقّع أحسن؟** (leave-one-out على الأذون المقفولة، F1)
 *       الشركة ٠.٧٤٥ · العميل ٠.٧١٠ · التوكيل+التصنيف ٠.٦٦٧ · الميناء ٠.٦٥٨
 *       · التوكيل ٠.٦٥٤ · النوع لوحده ٠.٦٥١
 *     ⇒ **`SCOPES` مرتّبة بالقياس ده بالحرف.** الترتيب البديهي («التوكيل هو
 *       اللي بيحدّد») طلع **غلط** — الشركة والعميل أثبت، لأن الروتين بيتكرّر
 *       مع نفس الجهة اللي بتتعامل معاها لا مع الخط الملاحي.
 *
 *  ② **العتبة**: عند ٠.٦٠ الدقة ٠.٨٤ والاسترجاع ٠.٥٨ · عند ٠.٤٥ الدقة بتنزل
 *     ٠.٧٦ · وعند ٠.٧٥ بتطلع ٠.٩٢ والاسترجاع بينزل ٠.٤٤.
 *     ⇒ **مفيش عتبة واحدة صح، فالنتيجة بتتدرّج**: فوق `P_SURE` «شبه مؤكّد» ·
 *       فوق `P_EXPECT` «متوقّع» (ودي بس اللي بتتحسب **ناقصة**) · فوق `P_HINT`
 *       «وارد» تلميح ما بيتعدّش · وتحت كده **صمت**. (نفس عقيدة `Habit`:
 *       أول توقّع غلط بيخلّي المالك يبطّل يبصّ للسطر ده خالص.)
 *
 *  ③ **الكمية ليها أساسان مش واحد** (نسبة الضبط بالحرف):
 *       لكل **سيارة**: جيش ٠.٨٥ · تعتيق ٠.٨٦ · هيئة فوارغ ٠.٨٦
 *       **ثابت للإذن كله**: تخصيص ٠.٩١ · حمولة ٠.٩٦ · إشعاع ١.٠٠ · بوصلة ١.٠٠
 *     ⇒ ده بالظبط سؤال المالك «كل كام عربية بتاخد كام هيئة» — والإجابة
 *       **بتتقاس لكل نوع في كل نطاق** مش بتتفرض.
 *
 *  ④ **الاقتران مقيس مش مفترض**: «بوصلة ⇒ إشعاع» طلعت ٠.٩٤ برفع ٣.٩٨ — وهي
 *     نفس القاعدة اللي كانت **متكتوبة بالإيد** في v32. الداتا أكّدتها، وكشفت
 *     كمان: «طرق وكباري ⇒ محافظة» ١.٠٠ · «مستودعات ⇒ هيئة فوارغ» ٠.٧٥ ·
 *     «زيادة وزن ⇒ تعتيق» ٠.٨٥ — تلاتة ما كانوش في القواعد خالص.
 *
 * ═══ العزل بالبناء (القاعدة ①) ═══
 * ⚠️ **ولا مليم بيتحرّك من هنا.** الموديل بيقرا `permit_stages` ويكتب في
 *    جداوله الأربعة بس. مفيش خزنة ولا تقفيل ولا عهدة ولا فاتورة. وكله
 *    **مشتقّ**: امسح الأربع جداول وشغّل التدريب يرجعوا بالحرف.
 *
 * ⚠️ **التدريب على المقفول بس.** الإذن المفتوح ناقص بطبيعته — التدريب عليه
 *    بيعلّم الموديل إن الناقص طبيعي، فبيبطّل يشوف الناقص. (وده أسوأ عطل
 *    ممكن في وحدة شغلها الوحيد إنها تشوف الناقص.)
 *
 * ⚠️ **بيكمّل v32 ما بيلغيهاش.** `Permit::receiptGuide` فضلت زي ما هي بعقدها،
 *    و`merged()` بتلمّ الاتنين: القاعدة بتقول «مطلوب»، والموديل بيقول «بنسبة
 *    كام، وكام واحد، وامتى». البند اللي الاتنين متفقين عليه بيتوسم `both`.
 */
final class ReceiptModel
{
    /**
     * نطاقات التعلّم من الأخصّ للأعمّ — **الترتيب مقيس** (اقرا ① فوق).
     * `parent` = النطاق اللي الاحتمال بينكمش ناحيته لما الدليل يقلّ.
     */
    public const SCOPES = [
        'type_company'     => ['label' => 'الشركة',              'parent' => 'type'],
        'type_client'      => ['label' => 'العميل',              'parent' => 'type'],
        'type_agency_ct'   => ['label' => 'التوكيل والتصنيف',    'parent' => 'type_agency'],
        'type_agency'      => ['label' => 'التوكيل',             'parent' => 'type'],
        'type_port'        => ['label' => 'الميناء',             'parent' => 'type'],
        'type'             => ['label' => 'كل الأذون المشابهة',  'parent' => null],
    ];

    /**
     * وزن الأب في الانكماش (عيّنة مكافئة).
     * المقيس عند عتبة ٠.٦: α=٢ ⇒ دقة ٠.٨٢ · α=٤ ⇒ ٠.٨٤ · α=٨ ⇒ ٠.٨٣.
     * اتاخدت ٤ لأن الدقة هي اللي بتتحاسب عليها مصداقية «ناقص».
     */
    private const PRIOR_WEIGHT = 4.0;

    /** أقل عدد أذون في نطاق عشان يُعتمد عليه بدل ما ننزل للأعمّ. */
    private const MIN_EVIDENCE = 3;

    /** تدرّج الثقة — اقرا ② فوق. */
    public const P_SURE   = 0.75;
    public const P_EXPECT = 0.60;
    public const P_HINT   = 0.45;

    /** بند مُدخَل واحتماله تحت كده (بدليل كافي) = **غير معتاد** يستاهل تنبيه. */
    private const P_UNUSUAL   = 0.12;
    private const UNUSUAL_MIN = 12;

    /**
     * أقل «ضبط» للأساس عشان النظام يجرؤ يقول «العدد ناقص».
     *
     * ⚠️ **الاحتمال والعدد ثقتين منفصلتين.** ممكن يبقى شبه مؤكّد إن الإذن فيه
     *    «هيئة» (٩٥٪) ومع ذلك **مفيش أساس ثابت لعددها**: المقيس على الإنتاج إن
     *    الهيئة في الصادر بتتسجّل أحيانًا واحدة للإذن كله وأحيانًا واحدة لكل
     *    سيارة (٩ سيارات ⇐ ٩، و١٠ سيارات ⇐ ١). الأمانة هنا إننا نقول «موجودة»
     *    وما ندّعيش رقمًا — لا إننا نلوّح بـ«ناقص ٩» على أساس مش مستقرّ.
     *    (وده كمان بيدّي المالك معلومة مفيدة: التسجيل نفسه مش متّسق.)
     */
    private const QTY_MIN_FIT = 0.70;

    /**
     * وأقل عدد أذون **ظهر فيها النوع فعلاً** عشان العدد يبقى ادّعاءً مشروعًا.
     *
     * ⚠️ «ضبط ١٠٠٪» محسوبة من إذن واحد مش دليل — هي إعادة صياغة للملاحظة
     *    الوحيدة نفسها. من غير الحد ده النظام بيقول «ناقص واحد نبطشية» بناءً
     *    على إذن يتيم شافه مرة.
     */
    private const QTY_MIN_SUPPORT = 3;

    /**
     * المعدّل لكل وحدة لازم يكون قريبًا من عدد صحيح عشان يُعتمد عليه.
     *
     * ⚠️ **المعدّل الكسري مش معدّل — هو عرض لتضارب.** «٠.٦٧ هيئة لكل حاوية»
     *    معناها إن أحيانًا فيه وأحيانًا مفيش، مش إن فيه تلتين إيصال. والخطر
     *    إن الكسر ده **بيتمدّ خطّيًّا**: عيّنة من أذون بحاويتين أو تلاتة
     *    بتوَلّد ادّعاء «١٦ هيئة» على إذن بـ٢٤ حاوية — استقراء أبعد بكتير من
     *    المدى اللي اتقاس فيه. القاعدة الحقيقية لما بتكون موجودة بتطلع
     *    **١.٠٠ بالظبط** (جيش · هيئة الوارد)، فالقُرب من الصحيح هو الفرق
     *    بين قاعدة وضوضاء.
     */
    private const QTY_RATE_TOL = 0.15;

    /** حدود قاعدة الاقتران — تحتها الزوج صدفة مش قاعدة. */
    private const PAIR_MIN_N    = 6;
    private const PAIR_MIN_P    = 0.70;
    private const PAIR_MIN_LIFT = 1.30;

    /**
     * أقل عدد أذون **فارقة** عشان نحكم بيها على أساس الكمية.
     *
     * ⚠️ **الإذن اللي فيه سيارة واحدة ما بيفرّقش بين الأسس خالص** — «ثابت=١»
     *    و«لكل سيارة ×١» بيدّوا نفس الرقم بالظبط. و٦٣٪ من الأذون سيارة واحدة،
     *    فالمقارنة على الكل بتغرق في أذون مالهاش رأي، والأساس الغلط بيكسب
     *    بفرق ضئيل. فالحكم بيتاخد من **الأذون اللي الأسس بتختلف فيها بس**.
     *    (بلا القاعدة دي «الهيئة» بتطلع «ثابت=١»، فإذن بأربع سيارات بيقول
     *    «الهيئة موجودة» وهو ناقص تلاتة — وده بالظبط سؤال المالك.)
     */
    private const MIN_DECISIVE = 3;

    /** كل كام إذن مقفول جديد يستاهلوا إعادة تدريب تلقائية من نبضة العامل. */
    private const AUTO_AFTER_PERMITS = 5;

    /** وكل كام يوم على الأكتر، حتى لو مفيش أذون جديدة (الأسماء بتتغيّر). */
    private const AUTO_AFTER_DAYS = 7;

    // ═══════════════════════════════════════════════════════════════════
    //  مفاتيح النطاق — **المصدر الوحيد**: التدريب والتوقّع بيندهوها الاتنين
    // ═══════════════════════════════════════════════════════════════════

    /**
     * مفاتيح كل النطاقات لإذن. `null` = النطاق ده مالوش مفتاح في الإذن ده
     * (الخانة فاضية) فبيتخطّى.
     *
     * ⚠️ **لازم تكون دالة واحدة.** لو التدريب وحّد الاسم والتوقّع ما وحّدش،
     *    المفتاح ما بيلاقيش نفسه فالموديل بيرجّع «مفيش دليل» على إذن هو
     *    نفسه كان في التدريب — عطل صامت تمامًا.
     */
    public static function scopeKeys(array $permit): array
    {
        $type    = ($permit['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader';
        $company = ReceiptKind::scopeKey((string) ($permit['company'] ?? ''), 'company');
        $client  = ReceiptKind::scopeKey((string) ($permit['client']  ?? ''), 'agent');
        $agency  = ReceiptKind::scopeKey((string) ($permit['agency']  ?? ''));
        $port    = ReceiptKind::scopeKey((string) ($permit['port']    ?? ''));
        $ctype   = ReceiptKind::scopeKey((string) ($permit['container_type'] ?? ''));

        return [
            'type'           => $type,
            'type_company'   => $company !== '' ? $type . '|' . $company : null,
            'type_client'    => $client  !== '' ? $type . '|' . $client  : null,
            'type_agency'    => $agency  !== '' ? $type . '|' . $agency  : null,
            'type_agency_ct' => ($agency !== '' && $ctype !== '') ? $type . '|' . $agency . '|' . $ctype : null,
            'type_port'      => $port    !== '' ? $type . '|' . $port    : null,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  التدريب
    // ═══════════════════════════════════════════════════════════════════

    /**
     * جولة تدريب كاملة على كل الأذون المقفولة اللي فيها إيصالات.
     *
     * @param string $source 'manual' (بضغطة) أو 'auto' (من نبضة العامل)
     * @return array تقرير الجولة (بيتعرض للمالك وبيتسجّل في `receipt_model_runs`)
     */
    public static function train(string $source = 'manual'): array
    {
        $t0 = microtime(true);
        /* ⚠️ الوقت من **ساعة القاعدة** لا من PHP — القاعدة UTC والتطبيق
           القاهرة، وفرق التلات ساعات بيخلّي حذف «القديم» يمسح الجديد. */
        $stamp = (string) Database::scalar('SELECT NOW()');

        $permits = self::trainingSet();
        if (!$permits) {
            return ['ok' => false, 'reason' => 'مفيش أذون مقفولة فيها إيصالات — التدريب محتاج سجلات كاملة.',
                    'permits' => 0, 'receipts' => 0];
        }

        // ① اكتشاف أنواع جديدة من الصيغ اللي مش في البذرة، وإعادة التصنيف بعدها
        $discovered = self::discoverKinds($permits);
        $permits    = self::classify($permits);

        // ② إحصاء كل خلية (نطاق × مفتاح × نوع)
        $cells = self::buildCells($permits);
        self::persistCells($cells, $stamp);

        // ③ الاقتران
        $pairs = self::buildPairs($permits);
        self::persistPairs($pairs, $stamp);

        // ④ الدقة المقيسة على أذون **مستبعَدة من التدريب**
        $score = self::evaluate($permits);

        $receipts = array_sum(array_map(static fn ($p) => count($p['rows']), $permits));
        $kinds    = count(array_unique(array_merge(...array_map(
            static fn ($p) => array_keys($p['kinds']), $permits))) ?: []);

        $secs = round(microtime(true) - $t0, 3);
        Database::exec(
            'INSERT INTO receipt_model_runs
                (ran_at, source, n_permits, n_receipts, n_kinds, n_cells,
                 prec_score, rec_score, f1_score, qty_exact, secs, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$stamp, $source === 'auto' ? 'auto' : 'manual', count($permits), $receipts, $kinds, count($cells),
             $score['precision'], $score['recall'], $score['f1'], $score['qty_exact'], $secs,
             $discovered > 0 ? "أنواع جديدة اتكشفت: $discovered" : null]
        );

        /* ⚠️ **مفيش مفتاح إعدادات لحالة التدريب عن قصد.** كانت هنا سطرين
           بيكتبوا آخر تدريب وعدد الأذون في `settings` — واتشالوا: `freshness()`
           بتقرا من `receipt_model_runs` أصلاً، فالمفتاحين كانوا **نسخة تانية
           من نفس الحقيقة** تقدر تفترق عنها (والسجل هو الأصل لأنه بيحتفظ
           بالتاريخ كله مش بآخر قيمة). و`verify_settings_registry` بيرفض أي
           مفتاح بلا تعريف في `SettingsSchema` — وهو محقّ: مفتاح مالوش خانة
           في شاشة الإعدادات ومالوش تعريف يبقى حالة مخبّية. */
        ReceiptKind::flush();

        return ['ok' => true, 'permits' => count($permits), 'receipts' => $receipts, 'kinds' => $kinds,
                'cells' => count($cells), 'pairs' => count($pairs), 'discovered' => $discovered,
                'secs' => $secs] + $score;
    }

    /**
     * أذون التدريب: **مقفولة** وفيها إيصال واحد على الأقل، ومعاها عدد
     * السيارات والحاويات الحقيقي من البوالص.
     */
    private static function trainingSet(): array
    {
        $rows = Database::all(
            "SELECT p.id, p.permit_type, p.company, p.client, p.agency, p.port,
                    p.container_type, p.containers, p.permit_date
               FROM permits p
              WHERE p.status = 'closed'
                AND EXISTS (SELECT 1 FROM permit_stages s
                             WHERE s.permit_id = p.id AND s.stage_key = 'receipts' AND s.status <> 'none')"
        );
        if (!$rows) { return []; }

        $permits = [];
        foreach ($rows as $r) { $permits[(int) $r['id']] = $r + ['cars' => 0, 'conts' => 0, 'rows' => [], 'kinds' => []]; }
        $ids = implode(',', array_keys($permits));

        foreach (self::countsFor(array_keys($permits)) as $id => $c) {
            $permits[$id]['cars']  = $c['cars'];
            $permits[$id]['conts'] = $c['conts'];
        }
        foreach (Database::all(
            "SELECT permit_id, note, qty, amount, entry_date
               FROM permit_stages
              WHERE permit_id IN ($ids) AND stage_key = 'receipts' AND status <> 'none'") as $r) {
            $permits[(int) $r['permit_id']]['rows'][] = $r;
        }
        return $permits;
    }

    /** اكتشاف الصيغ المتكرّرة اللي مالهاش نوع في البذرة وتسجيلها أنواعًا. */
    private static function discoverKinds(array $permits): int
    {
        $counts = [];
        foreach ($permits as $p) {
            foreach ($p['rows'] as $r) {
                $raw = (string) $r['note'];
                if (ReceiptKind::of($raw) !== ReceiptKind::OTHER) { continue; }
                $key = ReceiptKind::normalize($raw);
                if ($key === '') { continue; }
                $counts[$key]['hits']            = ($counts[$key]['hits'] ?? 0) + 1;
                $counts[$key]['labels'][$key]    = ($counts[$key]['labels'][$key] ?? 0) + 1;
                $d = $r['entry_date'] ?: null;
                if ($d) {
                    $counts[$key]['first'] = min($counts[$key]['first'] ?? $d, $d);
                    $counts[$key]['last']  = max($counts[$key]['last']  ?? $d, $d);
                }
            }
        }
        foreach ($counts as $k => $c) { $counts[$k]['label'] = $k; }
        return ReceiptKind::registerLearned($counts);
    }

    /** تصنيف بنود كل إذن لأنواع + تجميع الكمية والمبلغ واليوم. */
    private static function classify(array $permits): array
    {
        foreach ($permits as $id => $p) {
            $kinds = [];
            foreach ($p['rows'] as $r) {
                $kind = ReceiptKind::of((string) $r['note']);
                if ($kind === null || $kind === ReceiptKind::OTHER) { continue; }
                $qty = max(1, (int) $r['qty']);
                $kinds[$kind]['qty'] = ($kinds[$kind]['qty'] ?? 0) + $qty;
                if ((float) $r['amount'] > 0) { $kinds[$kind]['unit'][] = (float) $r['amount'] / $qty; }
                if (!empty($r['entry_date']) && !empty($p['permit_date'])) {
                    $kinds[$kind]['days'][] = (int) round(
                        (strtotime((string) $r['entry_date']) - strtotime((string) $p['permit_date'])) / 86400);
                }
            }
            $permits[$id]['kinds'] = $kinds;
            /* مفاتيح النطاق بتتحسب **مرة واحدة** هنا: التقييم بينده
               `buildCells` خمس مرات، وحسابها كل مرة كان بيضرب القاعدة
               (`PartyMaster::resolve`) آلاف المرات بلا داعي. */
            $permits[$id]['keys'] = self::scopeKeys($p);
        }
        return $permits;
    }

    /** مفاتيح النطاق لإذن — المحسوبة مسبقًا لو موجودة. */
    private static function keysOf(array $permit): array
    {
        return $permit['keys'] ?? self::scopeKeys($permit);
    }

    /**
     * بناء خلايا الإحصاء: لكل (نطاق، مفتاح، نوع) الاحتمال المنكمش وأساس
     * الكمية واليوم المعتاد والمبلغ المعتاد.
     */
    private static function buildCells(array $permits): array
    {
        // ① تجميع الأذون تحت كل مفتاح نطاق + كل الأنواع اللي ظهرت في التدريب
        $groups   = [];   // scope => key => [permit ids]
        $allKinds = [];
        foreach ($permits as $id => $p) {
            foreach (self::keysOf($p) as $scope => $key) {
                if ($key === null || $key === '') { continue; }
                $groups[$scope][$key][] = $id;
            }
            foreach (array_keys($p['kinds']) as $k) { $allKinds[$k] = true; }
        }
        $allKinds = array_keys($allKinds);

        /* ② من **العام للخاص** عشان احتمال الأب يبقى جاهز قبل ما ابنه يستعمله
              أساسًا للانكماش. (`type_agency_ct` أبوه `type_agency` فلازم
              يتحسب بعده — الترتيب ده مش تجميلي.) */
        $order = ['type', 'type_agency', 'type_company', 'type_client', 'type_port', 'type_agency_ct'];
        $prob  = [];    // scope|key|kind => احتمال منكمش
        $cells = [];

        foreach ($order as $scope) {
            $parent = self::SCOPES[$scope]['parent'] ?? null;
            foreach ($groups[$scope] ?? [] as $key => $ids) {
                $n = count($ids);
                /* بنمرّ على **كل** الأنواع مش على اللي ظهر في المجموعة بس:
                   غياب نوع في مجموعة معلومة زي حضوره، وابنها محتاج الاحتمال
                   ده أساسًا للانكماش حتى لو صفر. */
                $parentKey = $parent !== null ? (self::keysOf($permits[$ids[0]])[$parent] ?? null) : null;

                foreach ($allKinds as $kind) {
                    $hits = 0;
                    foreach ($ids as $id) { if (isset($permits[$id]['kinds'][$kind])) { $hits++; } }

                    /* بلا أب (النطاق الجذر) الافتراضي ٠.٥ — «ما نعرفش» بلا
                       ميل لا للحضور ولا للغياب. */
                    $prior = $parent === null ? 0.5 : ($prob[$parent . "\0" . $parentKey . "\0" . $kind] ?? 0.0);
                    $p = ($hits + self::PRIOR_WEIGHT * $prior) / ($n + self::PRIOR_WEIGHT);
                    $prob[$scope . "\0" . $key . "\0" . $kind] = $p;

                    if ($hits === 0) { continue; }   // خلية بلا ظهور = مفيش كمية تتقاس ولا سبب تتخزّن

                    $qty  = self::fitQuantity($permits, $ids, $kind);
                    $days = []; $units = [];
                    foreach ($ids as $id) {
                        foreach ($permits[$id]['kinds'][$kind]['days'] ?? [] as $d) { $days[] = $d; }
                        foreach ($permits[$id]['kinds'][$kind]['unit'] ?? [] as $u) { $units[] = $u; }
                    }
                    $cells[] = [
                        'scope' => $scope, 'scope_key' => $key, 'kind' => $kind,
                        'n_permits' => $n, 'n_hits' => $hits,
                        'prob' => round($p, 4), 'raw_prob' => round($hits / $n, 4),
                        'qty_basis' => $qty['basis'], 'qty_rate' => $qty['rate'], 'qty_fit' => $qty['fit'],
                        'day_offset' => $days ? (int) round(self::median($days)) : null,
                        'amount_med' => $units ? round(self::median($units), 2) : null,
                    ];
                }
            }
        }
        return $cells;
    }

    /**
     * أساس الكمية: ثابت للإذن · لكل سيارة · لكل حاوية — **بيتقاس مش بيتفرض**.
     *
     * المعيار: نسبة الأذون اللي الأساس ضبط عددها **بالحرف**. والتعادل بيتحسم
     * من الأذون **الفارقة** بس (اقرا `MIN_DECISIVE`).
     */
    private static function fitQuantity(array $permits, array $ids, string $kind): array
    {
        $has = [];
        foreach ($ids as $id) { if (isset($permits[$id]['kinds'][$kind])) { $has[] = $permits[$id]; } }
        if (!$has) { return ['basis' => 'fixed', 'rate' => 1.0, 'fit' => 0.0]; }

        $qs = array_map(static fn ($p) => (int) $p['kinds'][$kind]['qty'], $has);
        $fixed = max(1, (int) round(self::median($qs)));

        $rc = []; $rn = [];
        foreach ($has as $p) {
            if ($p['cars']  > 0) { $rc[] = $p['kinds'][$kind]['qty'] / $p['cars']; }
            if ($p['conts'] > 0) { $rn[] = $p['kinds'][$kind]['qty'] / $p['conts']; }
        }
        $perCar  = $rc ? self::median($rc) : 0.0;
        $perCont = $rn ? self::median($rn) : 0.0;

        $cand = [
            'fixed'         => ['rate' => (float) $fixed,  'pred' => static fn ($p) => $fixed],
            'per_car'       => ['rate' => (float) $perCar, 'pred' => static fn ($p) => $p['cars']  > 0 ? max(1, (int) round($perCar  * $p['cars']))  : null],
            'per_container' => ['rate' => (float) $perCont, 'pred' => static fn ($p) => $p['conts'] > 0 ? max(1, (int) round($perCont * $p['conts'])) : null],
        ];

        $score = static function (callable $pred, array $set) use ($kind): ?float {
            $ok = 0; $n = 0;
            foreach ($set as $p) {
                $q = $pred($p);
                if ($q === null) { continue; }
                $n++;
                if ($q === (int) $p['kinds'][$kind]['qty']) { $ok++; }
            }
            return $n ? $ok / $n : null;
        };

        $live = [];
        foreach ($cand as $name => $c) { if ($c['rate'] > 0) { $live[$name] = $c; } }
        if (!$live) { return ['basis' => 'fixed', 'rate' => (float) $fixed, 'fit' => 0.0]; }

        /* الأذون **الفارقة**: اللي فيها أساسان على الأقل بيدّوا رقمين مختلفين.
           غيرها بيصوّت لكل الأسس بالتساوي فما بيفيدش في الاختيار. */
        $decisive = [];
        foreach ($has as $p) {
            $preds = [];
            foreach ($live as $c) { $q = ($c['pred'])($p); if ($q !== null) { $preds[$q] = true; } }
            if (count($preds) > 1) { $decisive[] = $p; }
        }

        $judgeOn = count($decisive) >= self::MIN_DECISIVE ? $decisive : $has;
        $picks = [];
        foreach ($live as $name => $c) {
            $f = $score($c['pred'], $judgeOn);
            if ($f !== null) { $picks[$name] = $f; }
        }
        if (!$picks) { return ['basis' => 'fixed', 'rate' => (float) $fixed, 'fit' => 0.0]; }

        /* عند التساوي التام: الأبسط بيكسب (ثابت ← لكل سيارة ← لكل حاوية). */
        $rank = ['fixed' => 0, 'per_car' => 1, 'per_container' => 2];
        uksort($picks, static fn ($a, $b) => [-$picks[$a], $rank[$a]] <=> [-$picks[$b], $rank[$b]]);
        $best = array_key_first($picks);

        /* `qty_fit` المعروض = الضبط على **كل** الأذون لا على الفارقة بس —
           الرقم اللي بيتعرض للمالك لازم يوصف الواقع الكامل. */
        $overall = $score($live[$best]['pred'], $has);

        return ['basis' => $best, 'rate' => round($live[$best]['rate'], 3), 'fit' => round($overall ?? 0.0, 4)];
    }

    /** قواعد الاقتران: «لما يبقى فيه A بيبقى فيه B» — لكل نوع إذن على حدة. */
    private static function buildPairs(array $permits): array
    {
        $byType = [];
        foreach ($permits as $p) {
            $t = ($p['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader';
            $byType[$t][] = array_keys($p['kinds']);
        }
        $out = [];
        foreach ($byType as $type => $sets) {
            $total = count($sets);
            if ($total < self::PAIR_MIN_N) { continue; }
            $freq = [];
            foreach ($sets as $s) { foreach ($s as $k) { $freq[$k] = ($freq[$k] ?? 0) + 1; } }
            foreach (array_keys($freq) as $a) {
                if ($freq[$a] < self::PAIR_MIN_N) { continue; }
                foreach (array_keys($freq) as $b) {
                    if ($a === $b) { continue; }
                    $both = 0;
                    foreach ($sets as $s) {
                        if (in_array($a, $s, true) && in_array($b, $s, true)) { $both++; }
                    }
                    $pBA  = $both / $freq[$a];
                    $pB   = $freq[$b] / $total;
                    $lift = $pB > 0 ? $pBA / $pB : 0.0;
                    if ($pBA < self::PAIR_MIN_P || $lift < self::PAIR_MIN_LIFT) { continue; }
                    $out[] = ['permit_type' => $type, 'kind_a' => $a, 'kind_b' => $b,
                              'n_a' => $freq[$a], 'n_both' => $both,
                              'p_ba' => round($pBA, 4), 'lift' => round($lift, 3)];
                }
            }
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  الحفظ — upsert ثم مسح اللي ما اتلمسش في الجولة دي
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **`updated_at` بتتكتب صراحةً** مش سايبينها لـ`ON UPDATE`: MySQL
     *    **بيتخطّى** التحديث لما كل القيم تطلع نفسها، فالخلية الصحيحة اللي
     *    ما اتغيّرتش كانت هتفضل بختم قديم وتتمسح بعدها **بالسكوت**.
     */
    private static function persistCells(array $cells, string $stamp): void
    {
        foreach (array_chunk($cells, 200) as $chunk) {
            $sql = 'INSERT INTO receipt_model_stats
                      (scope, scope_key, kind, n_permits, n_hits, prob, raw_prob,
                       qty_basis, qty_rate, qty_fit, day_offset, amount_med, updated_at) VALUES ';
            $vals = []; $args = [];
            foreach ($chunk as $c) {
                $vals[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push($args, $c['scope'], $c['scope_key'], $c['kind'], $c['n_permits'], $c['n_hits'],
                    $c['prob'], $c['raw_prob'], $c['qty_basis'], $c['qty_rate'], $c['qty_fit'],
                    $c['day_offset'], $c['amount_med'], $stamp);
            }
            $sql .= implode(',', $vals) . '
                    ON DUPLICATE KEY UPDATE
                      n_permits = VALUES(n_permits), n_hits = VALUES(n_hits),
                      prob = VALUES(prob), raw_prob = VALUES(raw_prob),
                      qty_basis = VALUES(qty_basis), qty_rate = VALUES(qty_rate), qty_fit = VALUES(qty_fit),
                      day_offset = VALUES(day_offset), amount_med = VALUES(amount_med),
                      updated_at = VALUES(updated_at)';
            Database::exec($sql, $args);
        }
        Database::exec('DELETE FROM receipt_model_stats WHERE updated_at < ?', [$stamp]);
    }

    private static function persistPairs(array $pairs, string $stamp): void
    {
        foreach (array_chunk($pairs, 200) as $chunk) {
            $sql = 'INSERT INTO receipt_model_pairs
                      (permit_type, kind_a, kind_b, n_a, n_both, p_ba, lift, updated_at) VALUES ';
            $vals = []; $args = [];
            foreach ($chunk as $p) {
                $vals[] = '(?,?,?,?,?,?,?,?)';
                array_push($args, $p['permit_type'], $p['kind_a'], $p['kind_b'],
                    $p['n_a'], $p['n_both'], $p['p_ba'], $p['lift'], $stamp);
            }
            $sql .= implode(',', $vals) . '
                    ON DUPLICATE KEY UPDATE n_a = VALUES(n_a), n_both = VALUES(n_both),
                      p_ba = VALUES(p_ba), lift = VALUES(lift), updated_at = VALUES(updated_at)';
            Database::exec($sql, $args);
        }
        Database::exec('DELETE FROM receipt_model_pairs WHERE updated_at < ?', [$stamp]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  قياس الدقة — على أذون **مستبعَدة من التدريب**
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ٥ طيّات: كل مرة بنشيل خُمس الأذون، نبني الإحصاء من الباقي، ونتوقّع
     * على المستبعَد.
     *
     * ⚠️ **القياس على نفس أذون التدريب بيدّي رقمًا متفائلًا كذبًا** — الموديل
     *    شايف الإجابة. الرقم اللي بيتعرض للمالك لازم يكون على إذن الموديل
     *    عمره ما شافه، وإلا بنبيعله ثقة مش موجودة.
     */
    private static function evaluate(array $permits): array
    {
        $ids = array_keys($permits);
        if (count($ids) < 10) {
            return ['precision' => null, 'recall' => null, 'f1' => null, 'qty_exact' => null];
        }
        sort($ids);
        $folds = 5;
        $tp = $fp = $fn = 0; $qOk = 0; $qN = 0;

        for ($f = 0; $f < $folds; $f++) {
            $test = []; $train = [];
            foreach ($ids as $i => $id) { if ($i % $folds === $f) { $test[] = $id; } else { $train[$id] = $permits[$id]; } }
            if (!$train || !$test) { continue; }

            $cells = self::buildCells($train);
            $index = [];
            foreach ($cells as $c) { $index[$c['scope']][$c['scope_key']][$c['kind']] = $c; }
            $pairs = [];
            foreach (self::buildPairs($train) as $p) { $pairs[$p['permit_type']][$p['kind_a']][$p['kind_b']] = $p; }

            foreach ($test as $id) {
                $p = $permits[$id];
                /* ⚠️ **بلا الاقتران هنا عن قصد.** لو مرّرنا الأنواع الموجودة
                   فعلاً عشان تفعّل قواعد «A ⇒ B»، كنّا هنكون بنوري الموديل
                   الإجابة اللي بنقيسه عليها — والرقم يطلع متفائلًا كذبًا.
                   فالمقيس هو التوقّع من **السياق لوحده**، والاقتران بيزوّد
                   على كده في الاستخدام الحقيقي. */
                $pred = self::score($p, $index, $pairs, []);
                foreach ($pred as $kind => $r) {
                    $expected = $r['p'] >= self::P_EXPECT;
                    $actual   = isset($p['kinds'][$kind]);
                    if ($expected && $actual) {
                        $tp++;
                        $qN++;
                        if (self::expectedQty($r, $p['cars'], $p['conts']) === (int) $p['kinds'][$kind]['qty']) { $qOk++; }
                    } elseif ($expected) { $fp++; }
                    elseif ($actual)     { $fn++; }
                }
            }
        }
        $prec = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : null;
        $rec  = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : null;
        $f1   = ($prec && $rec) ? 2 * $prec * $rec / ($prec + $rec) : null;
        return [
            'precision' => $prec !== null ? round($prec, 4) : null,
            'recall'    => $rec  !== null ? round($rec, 4)  : null,
            'f1'        => $f1   !== null ? round($f1, 4)   : null,
            'qty_exact' => $qN > 0 ? round($qOk / $qN, 4) : null,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  التوقّع
    // ═══════════════════════════════════════════════════════════════════

    /**
     * تقييم كل نوع لإذن: الاحتمال + النطاق اللي جاب الدليل + أثر الاقتران.
     * `$index` و`$pairs` مفهرسة مسبقًا (من القاعدة أو من طيّة تقييم).
     *
     * @param array $present الأنواع الموجودة فعلاً في الإذن (بتفعّل الاقتران)
     */
    private static function score(array $permit, array $index, array $pairs, array $present): array
    {
        $keys = self::keysOf($permit);
        $type = $keys['type'];
        $out  = [];

        // كل الأنواع اللي ليها أي أثر في أي نطاق يخصّ الإذن ده
        $cands = [];
        foreach (self::SCOPES as $scope => $_) {
            $k = $keys[$scope] ?? null;
            if ($k === null) { continue; }
            foreach (array_keys($index[$scope][$k] ?? []) as $kind) { $cands[$kind] = true; }
        }

        foreach (array_keys($cands) as $kind) {
            $cell = null;
            foreach (array_keys(self::SCOPES) as $scope) {
                $k = $keys[$scope] ?? null;
                if ($k === null) { continue; }
                $c = $index[$scope][$k][$kind] ?? null;
                if ($c === null) { continue; }
                if ((int) $c['n_permits'] >= self::MIN_EVIDENCE) { $cell = $c + ['scope' => $scope]; break; }
                if ($cell === null) { $cell = $c + ['scope' => $scope]; }   // احتياطي لو مفيش دليل كافي خالص
            }
            if ($cell === null) { continue; }

            /* ⚠️ **الاحتمال والعدد بياخدوا دليلهم كل واحد لوحده.**
               «هل الإذن ده فيه جيش؟» أدقّ إجابة عليه من أضيق سياق فيه دليل
               (العميل مثلاً بأربع أذون). لكن «كام جيش؟» سؤال تاني خالص
               ومحتاج أذون أكتر عشان الأساس يستقرّ — فأربع أذون بضبط ٥٠٪
               ما يصحّش تلغي أساسًا مقيسًا على ٣١ إذن بضبط ٧٦٪ في سياق أوسع.
               ربط السؤالين بخلية واحدة كان بيضيّع أدق إجابة عند كل واحد. */
            $qty = null;
            foreach (array_keys(self::SCOPES) as $scope) {
                $k = $keys[$scope] ?? null;
                if ($k === null) { continue; }
                $c = $index[$scope][$k][$kind] ?? null;
                if ($c === null) { continue; }
                if ((float) $c['qty_fit'] >= self::QTY_MIN_FIT && (int) $c['n_hits'] >= self::QTY_MIN_SUPPORT) {
                    $qty = $c + ['scope' => $scope];
                    break;
                }
            }
            if ($qty !== null) {
                $cell['qty_basis'] = $qty['qty_basis'];
                $cell['qty_rate']  = $qty['qty_rate'];
                $cell['qty_fit']   = $qty['qty_fit'];
                $cell['qty_scope'] = $qty['scope'];
                $cell['qty_hits']  = $qty['n_hits'];
            } else {
                $cell['qty_scope'] = $cell['scope'];
                $cell['qty_hits']  = $cell['n_hits'];
            }

            $p      = (float) $cell['prob'];
            $reason = null;

            /* الاقتران: بند موجود بيرفع توقّع بند تاني — «فيه بوصلة يبقى فيه
               إشعاع». بياخد **الأعلى** لا بيتراكم: قاعدتان بيقولوا نفس
               الحاجة مش دليلًا مضاعفًا. */
            foreach ($present as $a) {
                $pr = $pairs[$type][$a][$kind] ?? null;
                if ($pr === null) { continue; }
                if ((float) $pr['p_ba'] > $p) {
                    $p = (float) $pr['p_ba'];
                    $reason = ['kind' => $a, 'p' => (float) $pr['p_ba'], 'lift' => (float) $pr['lift'], 'n' => (int) $pr['n_a']];
                }
            }

            $out[$kind] = $cell + ['p' => $p, 'pair' => $reason];
        }
        return $out;
    }

    /**
     * هل المعدّل «نظيف»؟ الثابت دايمًا نظيف (هو عدد بذاته)، والنسبي لازم
     * يقرب من صحيح ≥ ١ (اقرا `QTY_RATE_TOL`).
     */
    private static function rateIsClean(string $basis, float $rate): bool
    {
        if ($basis === 'fixed') { return $rate >= 1; }
        return $rate >= 1 - self::QTY_RATE_TOL && abs($rate - round($rate)) <= self::QTY_RATE_TOL;
    }

    /** العدد المتوقّع من أساس الكمية المتعلَّم. */
    private static function expectedQty(array $cell, int $cars, int $conts): int
    {
        $rate = (float) $cell['qty_rate'];
        return match ($cell['qty_basis']) {
            'per_car'       => $cars  > 0 ? max(1, (int) round($rate * $cars))  : max(1, (int) round($rate)),
            'per_container' => $conts > 0 ? max(1, (int) round($rate * $conts)) : max(1, (int) round($rate)),
            default         => max(1, (int) round($rate)),
        };
    }

    /**
     * عدد السيارات (البوالص) والحاويات لمجموعة أذون — استعلام واحد.
     *
     * ⚠️ **المصدر الوحيد** للعددين دول في الوحدة دي: القايمة وشاشة الإذن
     *    والتدريب لازم يشوفوا نفس الرقم، وإلا «٤ هيئة» في الشاشة و«٣» في
     *    القايمة بلا أي خطأ ظاهر.
     */
    public static function countsFor(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) { return []; }
        $in  = implode(',', $ids);
        $out = [];
        foreach ($ids as $id) { $out[$id] = ['cars' => 0, 'conts' => 0]; }
        foreach (Database::all(
            "SELECT permit_id, COUNT(*) cars, COALESCE(SUM(container_count),0) conts
               FROM bawalis WHERE permit_id IN ($in) GROUP BY permit_id") as $r) {
            $out[(int) $r['permit_id']] = ['cars' => (int) $r['cars'], 'conts' => (int) $r['conts']];
        }
        return $out;
    }

    /** فهرس الإحصاء من القاعدة (استعلام واحد لكل شاشة). */
    private static function index(): array
    {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $index = [];
        try {
            foreach (Database::all('SELECT * FROM receipt_model_stats') as $c) {
                $index[(string) $c['scope']][(string) $c['scope_key']][(string) $c['kind']] = $c;
            }
        } catch (\Throwable) { $index = []; }
        return $cache = $index;
    }

    /** فهرس الاقتران من القاعدة. */
    private static function pairIndex(): array
    {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $pairs = [];
        try {
            foreach (Database::all('SELECT * FROM receipt_model_pairs') as $p) {
                $pairs[(string) $p['permit_type']][(string) $p['kind_a']][(string) $p['kind_b']] = $p;
            }
        } catch (\Throwable) { $pairs = []; }
        return $cache = $pairs;
    }

    /** هل الموديل اتدرّب أصلاً؟ (شاشة الإذن بتسكت خالص لو لأ.) */
    public static function ready(): bool
    {
        return self::index() !== [];
    }

    /**
     * توقّع كامل لإذن — الناتج جاهز للعرض.
     *
     * @return array{ready:bool,items:array,missing:array,short:array,unusual:array,extra:array}
     *   `items` مرتّبة بالاحتمال: كل بند فيه
     *   `kind · p · tier · qty_expected · qty_entered · status · scope · evidence · day · amount · pair`
     */
    public static function forPermit(int $permitId): array
    {
        $permit = Permit::find($permitId);
        if (!$permit) { return self::emptyResult(); }
        $rows = Database::all(
            "SELECT note, qty FROM permit_stages
              WHERE permit_id = ? AND stage_key = 'receipts' AND status <> 'none'", [$permitId]);
        $counts = Database::row(
            'SELECT COUNT(*) cars, COALESCE(SUM(container_count),0) conts FROM bawalis WHERE permit_id = ?',
            [$permitId]) ?: [];
        return self::predict($permit, $rows, (int) ($counts['cars'] ?? 0), (int) ($counts['conts'] ?? 0));
    }

    /**
     * التوقّع من بيانات جاهزة (بتُستخدَم في القوائم من غير استعلام لكل صف).
     *
     * @param array $entered صفوف فيها `note` و`qty`
     */
    public static function predict(array $permit, array $entered, int $cars, int $conts): array
    {
        if (!self::ready()) { return self::emptyResult(); }

        // اللي مُدخَل فعلاً، مجمَّعًا بالنوع
        $have = [];
        foreach ($entered as $r) {
            $kind = ReceiptKind::of((string) ($r['note'] ?? ''));
            if ($kind === null || $kind === ReceiptKind::OTHER) { continue; }
            $have[$kind] = ($have[$kind] ?? 0) + max(1, (int) ($r['qty'] ?? 1));
        }
        $dismissed = Permit::dismissedReceipts($permit);

        $scored = self::score($permit, self::index(), self::pairIndex(), array_keys($have));

        $items = []; $missing = []; $short = []; $unusual = [];
        foreach ($scored as $kind => $c) {
            $p        = (float) $c['p'];
            $entQty   = $have[$kind] ?? 0;
            $expQty   = self::expectedQty($c, $cars, $conts);
            $isDismissed = in_array($kind, $dismissed, true);

            $tier = $p >= self::P_SURE ? 'sure' : ($p >= self::P_EXPECT ? 'expect' : ($p >= self::P_HINT ? 'hint' : 'low'));

            $qtyTrusted = (float) $c['qty_fit'] >= self::QTY_MIN_FIT
                       && (int) ($c['qty_hits'] ?? $c['n_hits']) >= self::QTY_MIN_SUPPORT
                       && self::rateIsClean((string) $c['qty_basis'], (float) $c['qty_rate']);

            if ($entQty > 0) {
                $status = ($entQty >= $expQty || !$qtyTrusted) ? 'ok' : 'short';
                /* بند مُدخَل واحتماله منخفض جدًّا بدليل كافي = غير معتاد.
                   ⚠️ **تنبيه مش اتهام**: المالك ممكن يكون عارف حاجة الداتا
                      ما شافتهاش، فالوسم بيقول «مش معتاد» لا «غلط». */
                if ($p < self::P_UNUSUAL && (int) $c['n_permits'] >= self::UNUSUAL_MIN) { $status = 'unusual'; }
            } elseif ($isDismissed)              { $status = 'dismissed'; }
            elseif ($tier === 'sure' || $tier === 'expect') { $status = 'missing'; }
            elseif ($tier === 'hint')            { $status = 'hint'; }
            else                                 { continue; }   // صمت

            $item = [
                'kind'         => $kind,
                'p'            => round($p, 4),
                'tier'         => $tier,
                'status'       => $status,
                'qty_expected' => $expQty,
                'qty_entered'  => $entQty,
                'qty_basis'    => (string) $c['qty_basis'],
                'qty_rate'     => (float) $c['qty_rate'],
                'qty_fit'      => (float) $c['qty_fit'],
                'qty_trusted'  => $qtyTrusted,
                'scope'        => (string) $c['scope'],
                'scope_label'  => self::SCOPES[$c['scope']]['label'] ?? '',
                'qty_scope'    => (string) ($c['qty_scope'] ?? $c['scope']),
                'qty_scope_label' => self::SCOPES[$c['qty_scope'] ?? $c['scope']]['label'] ?? '',
                'n_permits'    => (int) $c['n_permits'],
                'n_hits'       => (int) $c['n_hits'],
                'day'          => $c['day_offset'] !== null ? (int) $c['day_offset'] : null,
                'amount'       => $c['amount_med'] !== null ? (float) $c['amount_med'] : null,
                'pair'         => $c['pair'] ?? null,
            ];
            $items[] = $item;
            if ($status === 'missing') { $missing[] = $kind; }
            if ($status === 'short')   { $short[]   = $kind; }
            if ($status === 'unusual') { $unusual[] = $kind; }
        }

        usort($items, static function ($a, $b) {
            $rank = ['missing' => 0, 'short' => 1, 'unusual' => 2, 'hint' => 3, 'ok' => 4, 'dismissed' => 5];
            return [$rank[$a['status']], -$a['p']] <=> [$rank[$b['status']], -$b['p']];
        });

        // بنود مُدخَلة والموديل ما يعرفهاش خالص (مش غلط — بس مش في التوقّع)
        $extra = array_values(array_diff(array_keys($have), array_column($items, 'kind')));

        return ['ready' => true, 'items' => $items, 'missing' => $missing,
                'short' => $short, 'unusual' => $unusual, 'extra' => $extra];
    }

    private static function emptyResult(): array
    {
        return ['ready' => false, 'items' => [], 'missing' => [], 'short' => [], 'unusual' => [], 'extra' => []];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  الدمج مع محرّك قواعد v32 — **نقطة واحدة الشاشة بتقرا منها**
    // ═══════════════════════════════════════════════════════════════════

    /**
     * المرشد الكامل لإذن: قواعد v32 + الموديل المدرَّب في قائمة واحدة.
     *
     * ⚠️ **القاعدة ما بتتلغيش بالإحصاء والعكس.** البند اللي القاعدة بتقول عنه
     *    «مطلوب» بيفضل مطلوبًا حتى لو الموديل شايف احتماله ضعيفًا — القاعدة
     *    بتمثّل معرفة المالك بالشغل، والداتا بتمثّل اللي **اتسجّل** (وممكن
     *    يكون التسجيل هو الناقص). والعكس: الموديل بيضيف أنواعًا القواعد
     *    عمرها ما عرفتها (محافظة · مستودعات · ميزان · طرق وكباري …).
     *    عشان كده كل بند بيتوسم بمصدره: `rule` · `learned` · `both`.
     *
     * `ReceiptKind::of()` هي الجسر بين الاتنين: تسمية v32 «هيئة صادر» بتتحوّل
     * لنوع «هيئة»، فالاتنين بيتلمّوا في سطر واحد بدل سطرين متكرّرين.
     */
    public static function merged(int $permitId): array
    {
        $permit = Permit::find($permitId);
        if (!$permit) { return ['items' => [], 'missing' => [], 'ready' => false, 'model' => self::emptyResult()]; }

        $receipts = Database::all(
            "SELECT note, qty FROM permit_stages
              WHERE permit_id = ? AND stage_key = 'receipts' AND status <> 'none'", [$permitId]);
        $counts = self::countsFor([$permitId])[$permitId] ?? ['cars' => 0, 'conts' => 0];
        $cars  = $counts['cars'];
        $conts = $counts['conts'];

        $rule  = Permit::receiptGuide($permit, $receipts);
        $model = self::predict($permit, $receipts, $cars, $conts);

        return self::combine($rule, $model) + ['cars' => $cars, 'conts' => $conts, 'model' => $model];
    }

    /**
     * دمج ناتج قواعد v32 مع ناتج الموديل — **المصدر الوحيد للدمج**.
     *
     * ⚠️ **بتاخد ناتجين جاهزين ما بتستعلمش** عشان القايمة تقدر تستعملها من
     *    غير استعلام لكل صف. الشاشة والقايمة الاتنين بيعدّوا «الناقص» من هنا،
     *    وده اللي بيمنع «الشاشة تقول ٣ والقايمة تقول ٢».
     */
    public static function combine(array $rule, array $model): array
    {
        $byKind = [];
        foreach ($model['items'] as $it) { $byKind[$it['kind']] = $it; }

        $items = [];
        $seen  = [];

        // ① بنود القاعدة الأول — ترتيبها بيعكس تسلسل الشغل اللي المالك متعوّد عليه
        foreach ($rule['items'] ?? [] as $r) {
            $kind = ReceiptKind::of($r['label']);
            $m    = ($kind !== null && $kind !== ReceiptKind::OTHER) ? ($byKind[$kind] ?? null) : null;
            if ($m !== null) { $seen[$kind] = true; }
            $items[] = self::mergeRow($r['label'], $m === null ? 'rule' : 'both', $r, $m);
        }

        // ② اللي الموديل لوحده شايفه
        foreach ($model['items'] as $m) {
            if (isset($seen[$m['kind']])) { continue; }
            $items[] = self::mergeRow($m['kind'], 'learned', null, $m);
        }

        usort($items, static function ($a, $b) {
            $rank = ['missing' => 0, 'short' => 1, 'unusual' => 2, 'hint' => 3, 'ok' => 4, 'dismissed' => 5];
            return [$rank[$a['status']] ?? 9, -($a['p'] ?? 0)] <=> [$rank[$b['status']] ?? 9, -($b['p'] ?? 0)];
        });

        $missing = [];
        foreach ($items as $it) { if ($it['status'] === 'missing') { $missing[] = $it['label']; } }

        return [
            'ready'   => (bool) ($model['ready'] ?? false),
            'items'   => $items,
            'missing' => $missing,
            'short'   => $model['short']   ?? [],
            'unusual' => $model['unusual'] ?? [],
            'extra'   => $model['extra']   ?? [],
        ];
    }

    /** صفّ مدموج من بند قاعدة (v32) و/أو بند موديل. */
    private static function mergeRow(string $label, string $source, ?array $rule, ?array $model): array
    {
        $present   = $rule !== null ? (bool) $rule['present'] : ($model['qty_entered'] ?? 0) > 0;
        $dismissed = $rule !== null ? (bool) $rule['dismissed'] : (($model['status'] ?? '') === 'dismissed');
        $optional  = $rule !== null ? (bool) $rule['optional'] : false;

        if ($model !== null) {
            $status = $model['status'];
            /* القاعدة بتغلب على صمت الموديل: بند القاعدة الغايب بيفضل «ناقص»
               حتى لو احتماله الإحصائي واطي. */
            if ($rule !== null && !$present && !$dismissed && !$optional && $status !== 'missing') {
                $status = 'missing';
            }
        } elseif ($dismissed)          { $status = 'dismissed'; }
        elseif ($present)              { $status = 'ok'; }
        elseif ($optional)             { $status = 'hint'; }
        else                           { $status = 'missing'; }

        return [
            'label'        => $label,
            'source'       => $source,
            'status'       => $status,
            'present'      => $present,
            'dismissed'    => $dismissed,
            'optional'     => $optional,
            'hint'         => $rule['hint'] ?? '',
            'p'            => $model['p']            ?? null,
            'tier'         => $model['tier']         ?? null,
            'qty_expected' => $model['qty_expected'] ?? null,
            'qty_entered'  => $model['qty_entered']  ?? ($present ? 1 : 0),
            'qty_basis'    => $model['qty_basis']    ?? null,
            /* ⚠️ `qty_rate` كانت ناقصة هنا — والنافذة بتقرا منها عشان تكتب
               «لكل سيارة ×٢»، فكانت بتطبع تحذير PHP **جوّه الـHTML** بتاعها
               (نفس عائلة مصيدة `json()` اللي بتتلوّث بتحذير سابق). */
            'qty_rate'     => $model['qty_rate']     ?? 1.0,
            'qty_fit'      => $model['qty_fit']      ?? null,
            'qty_trusted'  => $model['qty_trusted']  ?? false,
            'qty_scope_label' => $model['qty_scope_label'] ?? null,
            'scope_label'  => $model['scope_label']  ?? null,
            'n_permits'    => $model['n_permits']    ?? null,
            'n_hits'       => $model['n_hits']       ?? null,
            'day'          => $model['day']          ?? null,
            'amount'       => $model['amount']       ?? null,
            'pair'         => $model['pair']         ?? null,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  حالة الموديل + التدريب التلقائي
    // ═══════════════════════════════════════════════════════════════════

    /** آخر جولة تدريب. */
    public static function lastRun(): ?array
    {
        try {
            return Database::row('SELECT * FROM receipt_model_runs ORDER BY ran_at DESC, id DESC LIMIT 1') ?: null;
        } catch (\Throwable) { return null; }
    }

    /**
     * هل الموديل بايت؟ — **بيتعرض للمالك صراحةً**.
     *
     * ⚠️ الجدول المادّي بيبوظ بالسكوت لما الداتا تتقدّم وهو واقف (تحذير
     *    موثَّق في `Habit`). العلاج مش إننا نبطّل نخزّن — العلاج إن البيات
     *    **يبان**: الشاشة بتقول «٧ أذون اتقفلت بعد آخر تدريب».
     */
    public static function freshness(): array
    {
        $run = self::lastRun();
        if (!$run) { return ['trained' => false, 'since' => 0, 'hours' => null, 'stale' => true]; }
        /* ⚠️ المقارنة بين وقت القاعدة و«دلوقتي» **جوّه SQL** — القاعدة UTC
           والتطبيق القاهرة (تحذير ④ في CLAUDE.md). */
        $hours = (int) Database::scalar('SELECT TIMESTAMPDIFF(HOUR, ?, NOW())', [$run['ran_at']]);
        $since = (int) Database::scalar(
            "SELECT COUNT(*) FROM permits p
              WHERE p.status = 'closed' AND p.updated_at > ?
                AND EXISTS (SELECT 1 FROM permit_stages s
                             WHERE s.permit_id = p.id AND s.stage_key = 'receipts' AND s.status <> 'none')",
            [$run['ran_at']]);
        return [
            'trained' => true, 'run' => $run, 'since' => $since, 'hours' => $hours,
            'stale'   => $since >= self::AUTO_AFTER_PERMITS || $hours >= self::AUTO_AFTER_DAYS * 24,
        ];
    }

    /**
     * إعادة تدريب تلقائية من نبضة `push_worker` — **ما بترميش أبدًا**.
     * بترجّع `null` لو مفيش داعي (فتكلفة النبضة العادية صفر).
     */
    public static function trainIfDue(): ?array
    {
        try {
            $f = self::freshness();
            if ($f['trained'] && !$f['stale']) { return null; }
            /* أول تشغيل: ما نتدرّبش على قاعدة لسه فاضية — نستنّى دليل حقيقي. */
            if (!$f['trained']) {
                $n = (int) Database::scalar(
                    "SELECT COUNT(*) FROM permits p WHERE p.status = 'closed'
                      AND EXISTS (SELECT 1 FROM permit_stages s
                                   WHERE s.permit_id = p.id AND s.stage_key = 'receipts' AND s.status <> 'none')");
                if ($n < 10) { return null; }
            }
            return self::train('auto');
        } catch (\Throwable $e) {
            error_log('receipt model auto-train failed: ' . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  الاستعراض — «النظام اتعلّم إيه؟»
    // ═══════════════════════════════════════════════════════════════════

    /**
     * الدليل الكامل ورا توقّع نوع بعينه في إذن بعينه — **بيغذّي نافذة
     * التفاصيل**، تنفيذًا لقاعدة «ممنوع رقم أصمّ»: كل نسبة معروضة يُضغط
     * عليها فتفتح الأذون اللي اتحسبت منها بالاسم.
     *
     * @return array{kind:string,levels:array,samples:array,pairs:array,item:?array}
     */
    public static function evidence(int $permitId, string $kind): array
    {
        $permit = Permit::find($permitId);
        $out = ['kind' => $kind, 'levels' => [], 'samples' => [], 'pairs' => [], 'item' => null, 'permit' => $permit];
        if (!$permit) { return $out; }

        $merged = self::merged($permitId);
        foreach ($merged['items'] as $it) {
            if ($it['label'] === $kind || ReceiptKind::of($it['label']) === $kind) { $out['item'] = $it; break; }
        }

        $realKind = ReceiptKind::of($kind);
        if ($realKind === null || $realKind === ReceiptKind::OTHER) { $realKind = $kind; }
        $keys  = self::scopeKeys($permit);
        $index = self::index();

        /* كل مستويات السياق — مش اللي اتاخد بس. المالك لازم يشوف ليه
           المستوى ده اتاخد ومستوى تحته اتساب. */
        foreach (self::SCOPES as $scope => $meta) {
            $k = $keys[$scope] ?? null;
            if ($k === null) { continue; }
            $c = $index[$scope][$k][$realKind] ?? null;
            $out['levels'][] = [
                'scope'  => $scope,
                'label'  => $meta['label'],
                'key'    => $k,
                'found'  => $c !== null,
                'n'      => $c ? (int) $c['n_permits'] : 0,
                'hits'   => $c ? (int) $c['n_hits'] : 0,
                'prob'   => $c ? (float) $c['prob'] : null,
                'raw'    => $c ? (float) $c['raw_prob'] : null,
                'basis'  => $c ? (string) $c['qty_basis'] : null,
                'rate'   => $c ? (float) $c['qty_rate'] : null,
                'fit'    => $c ? (float) $c['qty_fit'] : null,
                'day'    => $c && $c['day_offset'] !== null ? (int) $c['day_offset'] : null,
                'amount' => $c && $c['amount_med'] !== null ? (float) $c['amount_med'] : null,
                'used'   => false,
            ];
        }

        /* ⚠️ **مستوى واحد بس هو اللي «اتاخد».** كان كل مستوى عنده دليل كافي
           بيتوسم `used`، فالنافذة كانت بتعلّم خمسة من ستة وتقول ضمنًا إن
           الرقم جاي منهم كلهم — وده غلط: السلسلة **بتقف عند أول** مستوى فيه
           دليل كافي (الأخصّ)، والباقي معروض للمقارنة بس. */
        foreach ($out['levels'] as $i => $l) {
            if ($l['found'] && $l['n'] >= self::MIN_EVIDENCE) { $out['levels'][$i]['used'] = true; break; }
        }

        // الأذون المشابهة نفسها بالاسم — أقوى دليل ممكن يتعرض
        $used = null;
        foreach ($out['levels'] as $l) { if ($l['used']) { $used = $l; break; } }
        if ($used !== null) { $out['samples'] = self::samples($permit, $used['scope'], $realKind); }

        foreach (self::pairIndex()[$keys['type']] ?? [] as $a => $bs) {
            if (isset($bs[$realKind])) { $out['pairs'][] = $bs[$realKind] + ['kind_a' => $a]; }
        }
        usort($out['pairs'], static fn ($x, $y) => (float) $y['p_ba'] <=> (float) $x['p_ba']);

        return $out;
    }

    /**
     * الأذون المقفولة اللي كوّنت الدليل — مع «فيه ولا مفيش» لكل واحد.
     *
     * ⚠️ **الاستعلام بيعيد بناء المفتاح في PHP مش في SQL.** التوحيد
     *    (`ReceiptKind::scopeKey`) بيمرّ على `PartyMaster` وده مستحيل يتكتب
     *    في `WHERE` — فبنجيب أذون النوع ونصفّيها بنفس الدالة اللي التدريب
     *    استعملها. أي محاولة نقارن الأسماء الخام في SQL بترجّع عيّنة
     *    **غير** العيّنة اللي الرقم اتحسب منها، والمالك يشوف تناقضًا.
     */
    private static function samples(array $permit, string $scope, string $kind, int $limit = 24): array
    {
        $keys = self::scopeKeys($permit);
        $want = $keys[$scope] ?? null;
        if ($want === null) { return []; }
        $type = $keys['type'];

        $rows = Database::all(
            "SELECT p.id, p.permit_no, p.permit_date, p.company, p.client, p.agency, p.port,
                    p.container_type, p.permit_type
               FROM permits p
              WHERE p.status = 'closed' AND p.permit_type = ?
                AND EXISTS (SELECT 1 FROM permit_stages s
                             WHERE s.permit_id = p.id AND s.stage_key = 'receipts' AND s.status <> 'none')
              ORDER BY p.permit_date DESC, p.id DESC", [$type]);

        $match = [];
        foreach ($rows as $r) {
            if ((self::scopeKeys($r)[$scope] ?? null) === $want) { $match[(int) $r['id']] = $r; }
            if (count($match) >= $limit) { break; }
        }
        if (!$match) { return []; }

        $in = implode(',', array_keys($match));
        $got = [];
        foreach (Database::all(
            "SELECT permit_id, note, qty FROM permit_stages
              WHERE permit_id IN ($in) AND stage_key = 'receipts' AND status <> 'none'") as $s) {
            if (ReceiptKind::of((string) $s['note']) !== $kind) { continue; }
            $got[(int) $s['permit_id']] = ($got[(int) $s['permit_id']] ?? 0) + max(1, (int) $s['qty']);
        }
        $counts = self::countsFor(array_keys($match));

        $out = [];
        foreach ($match as $id => $r) {
            $out[] = $r + [
                'has'   => isset($got[$id]),
                'qty'   => $got[$id] ?? 0,
                'cars'  => $counts[$id]['cars']  ?? 0,
                'conts' => $counts[$id]['conts'] ?? 0,
            ];
        }
        return $out;
    }

    /** أقوى القواعد المتعلَّمة في نطاق معيّن (لشاشة الموديل). */
    public static function rules(string $scope, int $limit = 200): array
    {
        try {
            return Database::all(
                'SELECT * FROM receipt_model_stats
                  WHERE scope = ? AND n_permits >= ?
                  ORDER BY n_permits DESC, prob DESC, kind
                  LIMIT ' . max(1, $limit), [$scope, self::MIN_EVIDENCE]);
        } catch (\Throwable) { return []; }
    }

    /** قواعد الاقتران المتعلَّمة، الأقوى أولًا. */
    public static function pairs(int $limit = 40): array
    {
        try {
            return Database::all(
                'SELECT * FROM receipt_model_pairs ORDER BY lift DESC, p_ba DESC LIMIT ' . max(1, $limit));
        } catch (\Throwable) { return []; }
    }

    /** آخر الجولات (سجل التدريب). */
    public static function runs(int $limit = 12): array
    {
        try {
            return Database::all('SELECT * FROM receipt_model_runs ORDER BY ran_at DESC, id DESC LIMIT ' . max(1, $limit));
        } catch (\Throwable) { return []; }
    }

    /** الوسيط — بلا مكتبة، وبيشتغل على أعداد وكسور. */
    private static function median(array $a): float
    {
        $a = array_values($a);
        sort($a);
        $n = count($a);
        if ($n === 0) { return 0.0; }
        return $n % 2 ? (float) $a[intdiv($n, 2)] : ((float) $a[$n / 2 - 1] + (float) $a[$n / 2]) / 2;
    }
}
