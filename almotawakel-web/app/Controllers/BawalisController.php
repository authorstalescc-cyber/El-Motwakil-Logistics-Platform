<?php
namespace App\Controllers;

use App\Models\Bill;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\Movement;
use App\Models\Permit;
use App\Core\Database;
use App\Core\FormState;
use App\Calc;
use App\Schema;
use App\Models\Audit;
use App\Models\PartyMaster;

class BawalisController extends CrudController
{
    protected string $key = 'bawalis';

    /** القيم المالية قبل التعديل لبوليصة مُرحَّلة (تُلتقط في update وتُستهلك في afterSave) */
    private ?array $legacyOld = null;
    /** يفعّل قواعد إنشاء البوليصة القديمة خلال الطلب الحالي فقط. */
    private bool $creatingLegacy = false;

    /** نسخ البيانات الوصفية من آخر بوليصة لتسريع الإدخال، دون نسخ رقم أو مبالغ. */
    protected function prepareCreateRow(array $ent, array $row): array
    {
        if (($_GET['copy'] ?? '') !== 'last') { return $row; }
        $last = Database::row('SELECT * FROM bawalis WHERE is_legacy=0 ORDER BY id DESC LIMIT 1');
        if (!$last) { return $row; }
        foreach (['bill_type','classification','company','destination','container_type','container_count','car_no','driver','car_owner','discount'] as $key) {
            if (array_key_exists($key, $last)) { $row[$key] = $last[$key]; }
        }
        $row['bill_date'] = work_date();
        foreach (['bill_number','cert_number','permit_no','container_no','freight','custody','transfer','overnight_collection'] as $key) {
            $row[$key] = in_array($key, ['freight','custody','transfer','overnight_collection'], true) ? 0 : '';
        }
        return $row;
    }

    /** تحقّق حي من رقم البوليصة قبل إرسال النموذج. */
    public function checkNumber(): void
    {
        $this->requireAuth();
        $number = trim((string)($_GET['number'] ?? ''));
        $except = max(0, (int)($_GET['except'] ?? 0));
        $exists = $number !== '' && Bill::numberExists($number, $except ?: null);
        $this->json(['ok'=>!$exists, 'exists'=>$exists, 'message'=>$exists ? 'رقم البوليصة مستخدم بالفعل.' : ($number !== '' ? 'الرقم متاح ✓' : '')]);
    }

    /** لصق صفوف من Excel وإضافتها دفعة واحدة (كل العملية تنجح أو تُلغى). */
    public function bulkPaste(): void
    {
        $this->requireAuth(); $this->requireCsrf();
        $text = trim((string)($_POST['rows'] ?? ''));
        if ($text === '') { $this->pasteFailure($text, 'الصق صفوف Excel أولًا.'); }
        $lines = preg_split('/\R/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$lines || count($lines) < 2 || count($lines) > 201) {
            $this->pasteFailure($text, 'لازم صف عناوين وصف بيانات واحد على الأقل (الحد الأقصى 200 صف).');
        }
        $cells = fn(string $line) => array_map('trim', str_getcsv($line, "\t"));
        $norm = fn($v) => preg_replace('/[\s_\-]+/u', '', mb_strtolower(trim((string)$v), 'UTF-8'));
        $map = [
            'رقمالبوليصة'=>'bill_number','التاريخ'=>'bill_date','النوع'=>'bill_type','صاحبالبيان'=>'declaration_owner',
            'التصنيف'=>'classification','الشركة'=>'company','الجهة'=>'destination','الحاوية'=>'container_no',
            'تصنيفالحاوية'=>'container_type','عددالحاويات'=>'container_count','السيارة'=>'car_no','السائق'=>'driver',
            'صاحبالسيارة'=>'car_owner','النولون'=>'freight','ناولون'=>'freight','عهدة'=>'custody','العهدة'=>'custody',
            'تحويلة'=>'transfer','خصم'=>'discount','بياتة'=>'overnight_collection',
        ];
        $headers = $cells(array_shift($lines)); $keys=[];
        foreach ($headers as $header) { $keys[] = $map[$norm($header)] ?? null; }
        if (!in_array('bill_number', $keys, true) || !in_array('bill_date', $keys, true)) {
            $this->pasteFailure($text, 'صف العناوين لازم يحتوي «رقم البوليصة» و«التاريخ». استخدم النموذج الظاهر في النافذة.');
        }

        $ent = $this->entity(); $inserted=[]; $pdo=Database::conn();
        try {
            $pdo->beginTransaction();
            foreach ($lines as $lineNo=>$line) {
                $values=$cells($line); $raw=[];
                foreach ($keys as $i=>$key) { if ($key) { $raw[$key]=$values[$i] ?? ''; } }
                if (($raw['bill_type'] ?? '') === 'صادر') { $raw['bill_type']='sader'; }
                if (($raw['bill_type'] ?? '') === 'وارد') { $raw['bill_type']='warid'; }
                if (!empty($raw['bill_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw['bill_date'])) {
                    $parsed=null;
                    foreach (['d/m/Y','d-m-Y','d.m.Y','m/d/Y'] as $format) {
                        $date=\DateTime::createFromFormat('!'.$format,$raw['bill_date']);
                        if ($date && $date->format($format)===$raw['bill_date']) { $parsed=$date->format('Y-m-d'); break; }
                    }
                    if (!$parsed && is_numeric($raw['bill_date']) && (int)$raw['bill_date'] > 20000) {
                        $parsed=(new \DateTime('1899-12-30'))->modify('+'.((int)$raw['bill_date']).' days')->format('Y-m-d');
                    }
                    if ($parsed) { $raw['bill_date']=$parsed; }
                }
                foreach (['freight','custody','transfer','discount','overnight_collection','container_count'] as $moneyKey) {
                    if (isset($raw[$moneyKey])) {
                        $raw[$moneyKey]=strtr($raw[$moneyKey],['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٬'=>'','،'=>'','٫'=>'.',','=>'']);
                    }
                }
                $_POST = array_merge($_POST, $raw);
                $data = Calc::apply($ent['calc'], $this->collect($ent));
                $data = $this->prepareSaveData($data,null);
                $data['bill_type'] = in_array($data['bill_type'] ?? '', ['sader','warid'], true) ? $data['bill_type'] : 'sader';
                if (empty($data['bill_number'])) { throw new \RuntimeException('رقم البوليصة ناقص في صف Excel '.($lineNo+2)); }
                if (empty($data['bill_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['bill_date'])) {
                    throw new \RuntimeException('التاريخ غير صحيح في صف Excel '.($lineNo+2).'؛ استخدم YYYY-MM-DD.');
                }
                if ($err=$this->validate($data, null)) { throw new \RuntimeException('صف '.($lineNo+2).': '.$err); }
                $this->insert($ent['table'], $data); $id=Database::lastId();
                $this->learnLookups($ent,$data); $this->afterSave($id,$data,true); $inserted[]=$id;
            }
            Audit::log('bulk_update','bawalis',null,null,null,'لصق وإضافة بوالص من Excel',['ids'=>$inserted,'count'=>count($inserted)]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $this->pasteFailure($text, 'ما اتضافش ولا صف: '.$e->getMessage());
        }
        $this->done('تمت إضافة '.count($inserted).' بوليصة من Excel بنجاح.', 'bawalis');
    }

    /**
     * فشل لصق الإكسل — **بيرجّع اللصق كله** بدل ما يضيع.
     *
     * ده كان أسوأ ضياع مُدخَل في النظام: المستخدم بينسخ 200 صف من الإكسل،
     * وأي غلطة في صف واحد كانت بترميه على القايمة والـtextarea فاضية،
     * فيضطر ينسخ من أول وجديد ويدوّر على الغلطة من غير ما يشوفها.
     */
    private function pasteFailure(string $rows, string $msg): never
    {
        $this->formFailure('bawalis.paste', 'bawalis', ['rows' => $rows], ['rows' => $msg], 'bawalis');
    }

    /** نموذج إضافة بوليصة قديمة يدويًا: بلا إذن وبلا حركة وخارج الحسابات التشغيلية. */
    public function createLegacy(): void
    {
        $this->requireAuth();
        $ent = $this->entity();
        $row = $this->emptyRow($ent);
        $state = FormState::consume('bawalis.legacy.create', 'bawalis?legacy=1');
        if ($state['old']) { $row=array_replace($row,$state['old']); }
        $this->render('crud/form', [
            'title'        => 'إضافة بوليصة قديمة',
            'ent'          => $ent,
            'row'          => $row,
            'lookups'      => \App\Models\Lookup::allGrouped(),
            'action'       => url('bawalis/legacy'),
            'isEdit'       => false,
            'legacyCreate' => true,
            'legacyPaid'   => $state['old']['legacy_paid'] ?? 0,
            'errors'       => $state['errors'],
            'formToken'    => $state['token'],
            'back'         => $state['back'],
        ]);
    }

    /** حفظ بوليصة قديمة مع دفعتها التاريخية الاختيارية داخل معاملة واحدة. */
    public function storeLegacy(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->creatingLegacy = true;
        $ent = $this->entity();
        $data = Calc::apply($ent['calc'], $this->collect($ent));
        $data = $this->prepareSaveData($data,null);
        $paidRaw = trim((string)($_POST['legacy_paid'] ?? ''));
        if ($paidRaw !== '' && (!is_numeric($paidRaw) || (float)$paidRaw < 0)) {
            $this->formFailure('bawalis.legacy.create', 'bawalis/new-legacy', $_POST,
                ['legacy_paid'=>'قيمة المدفوع التاريخي غير صحيحة.'], 'bawalis?legacy=1');
        }
        if ($err = $this->validate($data, null)) {
            $errors=['_form'=>$err];
            if (str_contains($err,'رقم البوليصة')) { $errors['bill_number']=$err; }
            $this->formFailure('bawalis.legacy.create', 'bawalis/new-legacy', $_POST, $errors, 'bawalis?legacy=1');
        }

        // أعمدة العزل تُفرض من الخادم ولا تؤخذ من النموذج إطلاقًا.
        $data['permit_id'] = null;
        $data['movement_id'] = null;
        $data['is_legacy'] = 1;
        $data['legacy_id'] = null;
        $paid = $paidRaw === '' ? 0.0 : round((float)$paidRaw, 2);

        $this->claimForm('bawalis?legacy=1');
        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            $this->insert($ent['table'], $data);
            $id = Database::lastId();
            $this->learnLookups($ent, $data);
            $this->afterSave($id, $data, true); // يعيد الحساب، ولا ينشئ حركة لأن is_legacy=1
            if ($paid > 0) {
                Database::exec(
                    'INSERT INTO bill_payments (bill_id, pay_date, amount, note, is_legacy) VALUES (?,?,?,?,1)',
                    [$id, $data['bill_date'] ?: work_date(), $paid, 'مدفوع تاريخي عند إضافة بوليصة قديمة']
                );
                Bill::recalc($id);
            }
            Audit::log('create', 'bawalis', $id, null, Database::row('SELECT * FROM bawalis WHERE id=?', [$id]), 'إضافة بوليصة قديمة يدويًا');
            $pdo->commit();
            flash('success', 'تمت إضافة البوليصة القديمة معزولة عن العهدة والتقفيل، وبدون إذن أو حركة.');
            redirect(safe_back('bawalis?legacy=1', $_POST['_back'] ?? null));
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $this->formFailure('bawalis.legacy.create', 'bawalis/new-legacy', $_POST,
                ['_form'=>'لم تُضف البوليصة: ' . $e->getMessage()], 'bawalis?legacy=1');
        }
    }

    /** تحديد/تحديث النولون لعدة بوالص في معاملة واحدة. */
    public function bulkFreight(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $values = (array)($_POST['freight'] ?? []);
        $ids = $this->bulkIds(array_keys($values));
        if (!$ids) { flash('error', 'حدّد بوليصة واحدة على الأقل.'); redirect($this->bulkBack()); }

        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::all("SELECT * FROM bawalis WHERE id IN ($ph) FOR UPDATE", $ids);
            if (count($rows) !== count($ids)) { throw new \RuntimeException('بعض البوالص المحددة لم تعد موجودة.'); }

            $updated = 0;
            foreach ($rows as $row) {
                $raw = $values[(string)$row['id']] ?? $values[(int)$row['id']] ?? null;
                if ($raw === null || trim((string)$raw) === '') { continue; }
                if (!is_numeric($raw) || (float)$raw < 0) {
                    throw new \RuntimeException('قيمة النولون غير صحيحة للبوليصة ' . ($row['bill_number'] ?: '#'.$row['id']) . '.');
                }
                if (!(int)$row['is_legacy'] && ($err = Closing::guard($row['bill_date'] ?? null))) {
                    throw new \RuntimeException('البوليصة ' . ($row['bill_number'] ?: '#'.$row['id']) . ': ' . $err);
                }

                $freight = round((float)$raw, 2);
                $calc = Calc::bawala(array_merge($row, ['freight' => $freight]));
                Database::exec('UPDATE bawalis SET freight = ?, debt = ? WHERE id = ?',
                    [$freight, $calc['debt'], (int)$row['id']]);
                Bill::recalc((int)$row['id']);
                $updated++;
            }
            if ($updated === 0) { throw new \RuntimeException('أدخل قيمة نولون لبوليصة واحدة على الأقل.'); }
            Audit::log('bulk_update', 'bawalis', null, null, null, 'إضافة/تعديل نولون لمجموعة بوالص', ['ids'=>$ids,'updated'=>$updated]);
            $pdo->commit();
            flash('success', 'تم تحديث النولون لـ ' . $updated . ' بوليصة وإعادة حساب المديونية.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('error', 'لم يتم تعديل أي بوليصة: ' . $e->getMessage());
        }
        redirect($this->bulkBack());
    }

    /** تغيير صاحب البيان لمجموعة بوالص، دون لمس أي قيمة مالية أو قفل يومي. */
    public function bulkOwner(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ids = $this->bulkIds((array)($_POST['ids'] ?? []));
        $owner = trim((string)($_POST['owner'] ?? ''));
        if (!$ids) { flash('error', 'حدّد بوليصة واحدة على الأقل.'); redirect($this->bulkBack()); }
        if ($owner === '' || mb_strlen($owner, 'UTF-8') > 150) {
            flash('error', 'اكتب اسم صاحب بيان صحيحًا.'); redirect($this->bulkBack());
        }

        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $found = Database::all("SELECT id,declaration_owner FROM bawalis WHERE id IN ($ph) FOR UPDATE", $ids);
            if (count($found) !== count($ids)) { throw new \RuntimeException('بعض البوالص المحددة لم تعد موجودة.'); }
            $ownerMaster=PartyMaster::ensure('owner',$owner);
            Database::exec("UPDATE bawalis SET declaration_owner=?,declaration_owner_id=?,lock_version=lock_version+1 WHERE id IN ($ph)", array_merge([$ownerMaster['name'],$ownerMaster['id']], $ids));
            Audit::log('bulk_update', 'bawalis', null, ['owners'=>$found], ['owner'=>$owner], 'تغيير صاحب البيان لمجموعة بوالص', ['ids'=>$ids]);
            $pdo->commit();
            flash('success', 'تم تغيير صاحب البيان إلى «' . $owner . '» لعدد ' . count($ids) . ' بوليصة.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('error', 'لم يتم تعديل أي بوليصة: ' . $e->getMessage());
        }
        redirect($this->bulkBack());
    }

    /** IDs موجبة فريدة مع سقف يحمي POST/SQL من الأحجام غير المقصودة. */
    private function bulkIds(array $raw): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($id) => $id > 0)));
        return count($ids) <= 1000 ? $ids : [];
    }

    /** رجوع محلي آمن لنفس جدول/فلتر البوالص. */
    private function bulkBack(): string
    {
        // بيمرّ على safe_back() زي أي رجوع تاني في النظام. النسخة القديمة
        // كانت بتفحص '://' و'//' والسطر الجديد بس — أضعف من المُنضِّف الموحّد.
        // بنقبل `_back` (المعتمد) و`back` (القديم) للتوافق مع الشاشات اللي لسه بتبعته.
        return safe_back('bawalis', $_POST['_back'] ?? $_POST['back'] ?? null);
    }

    /**
     * قاعدة: عهدة السائق في البوليصة لا يمكن أن تتجاوز رصيد عهدة المكتب المتاح.
     * عند التعديل نستثني خصم البوليصة نفسها من الرصيد (حتى لا يُحتسب مرّتين).
     *
     * البوالص المُرحَّلة من الأرشيف (is_legacy=1، من v26) خارج كل الأرصدة —
     * فلا ينطبق عليها قفل الأيام ولا قاعدة العهدة (تعديلها لا يمسّ أي رصيد مثبَّت).
     */
    protected function validate(array $data, ?int $id): ?string
    {
        $old = $id !== null
            ? Database::row('SELECT is_legacy, bill_date, bill_number FROM bawalis WHERE id = ?', [$id])
            : null;
        $isLegacy = $this->creatingLegacy || ($old && (int)$old['is_legacy'] === 1);

        // (0) القفل الصارم: لا كتابة على بوليصة تاريخها يقع ضمن فترة متقفلة
        //     (لا التاريخ الجديد ولا القديم عند التعديل).
        if (!$isLegacy) {
            if ($err = Closing::guard($data['bill_date'] ?? null)) { return $err; }
            if ($old && $old['bill_date'] && ($err = Closing::guard($old['bill_date']))) { return $err; }
        }

        // (0.5) منع تكرار رقم البوليصة — فقط عند تغيير الرقم (الأرشيف فيه أرقام مكرّرة
        //       تاريخيًّا، فلا يتعطّل حفظ تعديل لا يمسّ الرقم أصلًا).
        $billNo = trim((string)($data['bill_number'] ?? ''));
        $oldNo  = $old ? trim((string)($old['bill_number'] ?? '')) : '';
        if ($billNo !== '' && ($id === null || $billNo !== $oldNo) && Bill::numberExists($billNo, $id)) {
            return 'رقم البوليصة «' . $billNo . '» مُستخدَم من قبل — لا يمكن تكراره. اختر رقمًا مختلفًا.';
        }

        // (1) عهدة السائق لا تتجاوز رصيد عهدة المكتب المتاح (عهد البوالص المُرحَّلة تاريخية — تُستثنى).
        $custody = (float) ($data['custody'] ?? 0);
        if (!$isLegacy && $custody > 0) {
            $available = Custody::availableForBill($id ?? 0);
            if ($custody > $available + 0.005) {
                return 'عهدة السائق (' . number_format($custody, 0) . ') تتجاوز رصيد عهدة المكتب المتاح ('
                     . number_format($available, 0) . '). أضِف عهدة للرصيد أولاً من صفحة «رصيد العهدة».';
            }
        }

        // (2) عدد الحاويات لا يتجاوز سعة الحركة الظاهرة؛ الحركات القديمة بلا سعة
        //     ترجع لإجمالي الإذن. يحمي مسار التعديل العام خارج صفحة الإذن أيضًا.
        //     (الإنشاء محميّ بالفعل في PermitController::createBill).
        if ($id !== null) {
            $link = Database::row('SELECT permit_id,movement_id FROM bawalis WHERE id = ?', [$id]);
            $permitId = (int)($link['permit_id'] ?? 0);
            if ($permitId) {
                $capacity = Permit::billContainerCapacity($permitId, !empty($link['movement_id']) ? (int)$link['movement_id'] : null, $id);
                if ($capacity['total'] > 0) {
                    $newCount = max(0, (int) ($data['container_count'] ?? 0));
                    if ($capacity['allocated'] + $newCount > $capacity['total']) {
                        $whereLabel = $capacity['scope'] === 'movement' ? 'الحركة' : 'إجمالي الإذن';
                        return 'عدد الحاويات (' . $newCount . ') يتجاوز المتبقّي من ' . $whereLabel . ' (المتبقّي '
                             . $capacity['left'] . ' من ' . $capacity['total'] . '). لم تُحفظ التعديلات.';
                    }
                }
            }
        }
        return null;
    }

    protected function prepareSaveData(array $data, ?int $id): array
    {
        $company=PartyMaster::ensure('company',(string)($data['company'] ?? ''));
        $owner=PartyMaster::ensure('owner',(string)($data['declaration_owner'] ?? ''));
        $data['company']=$company['name'] ?? null;
        $data['company_id']=$company['id'] ?? null;
        $data['declaration_owner']=$owner['name'] ?? null;
        $data['declaration_owner_id']=$owner['id'] ?? null;
        return $data;
    }

    /**
     * التقاط الحقول المالية قبل تعديل بوليصة مُرحَّلة — دينها الأرشيفي («باقى» الإكسل)
     * قد يخالف معادلة النظام (تعديلات يدوية قديمة)، فنحافظ عليه ما لم تتغيّر فلوسها.
     */
    public function update(string $id): void
    {
        $this->legacyOld = Database::row(
            'SELECT is_legacy, debt, freight, custody, transfer, discount, overnight_collection
               FROM bawalis WHERE id = ?', [(int)$id]);
        parent::update($id);
    }

    /** صفحة تفاصيل البوليصة: كل البيانات + الحركة المرتبطة + الدفعات */
    public function show(string $id): void
    {
        $this->requireAuth();
        $bill = Database::row('SELECT * FROM bawalis WHERE id = ?', [(int)$id]);
        if (!$bill) { $this->notFound('البوليصة دي مش موجودة أو اتمسحت.'); }
        $mv = Database::row('SELECT * FROM movements WHERE bill_id = ? ORDER BY id LIMIT 1', [(int)$id]);
        // بيانات الشحن الأصلية من الإذن (تُستخدم كبديل لو الحركة لا تحملها)
        $permit = !empty($bill['permit_id'])
            ? Database::row('SELECT id, permit_no, permit_type, shipping_co, agency, port FROM permits WHERE id = ?', [(int)$bill['permit_id']])
            : null;
        $this->render('bawalis/show', [
            'title'   => 'تفاصيل البوليصة ' . ($bill['bill_number'] ?: '#'.$id),
            'bill'    => $bill,
            'ent'     => Schema::get('bawalis'),
            'movement'=> $mv,
            'permit'  => $permit,
            'items'   => $mv ? Movement::items((int)$mv['id']) : [],
            'payments'=> Bill::payments((int)$id),
        ]);
    }

    /** حذف بوليصة — يُمنع لو تاريخها ضمن فترة متقفلة (المُرحَّلة مستثناة: خارج الأرصدة). */
    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $row = Database::row('SELECT bill_date, is_legacy FROM bawalis WHERE id = ?', [(int)$id]);
        if ($row && !(int)$row['is_legacy'] && $row['bill_date']
            && ($err = Closing::guard($row['bill_date']))) {
            $this->fail($err, 'bawalis');
        }
        parent::destroy($id);
    }

    /** بعد حفظ البوليصة: حساب حالة السداد + إنشاء/مزامنة الحركة تلقائياً */
    protected function afterSave(int $id, array $data, bool $isNew): void
    {
        // بوليصة مُرحَّلة لم تتغيّر حقولها المالية ⇒ نسترجع دينها الأرشيفي كما كان
        // (Calc::bawala أعاد حسابه بمعادلة النظام قبل الحفظ، والأرشيف فيه قيم معدَّلة يدويًّا).
        $old = $this->legacyOld; $this->legacyOld = null;
        if (!$isNew && $old && (int)$old['is_legacy'] === 1) {
            $same = true;
            foreach (['freight','custody','transfer','discount','overnight_collection'] as $k) {
                if (abs((float)($data[$k] ?? 0) - (float)$old[$k]) > 0.005) { $same = false; break; }
            }
            if ($same) {
                Database::exec('UPDATE bawalis SET debt = ? WHERE id = ?', [$old['debt'], $id]);
            }
        }

        Bill::recalc($id);

        // المُرحَّلة بلا حركات نهائيًّا (شرط العزل: لا إذن ولا حركة جديدة لها)
        $isLegacy = (int) Database::scalar('SELECT is_legacy FROM bawalis WHERE id = ?', [$id]) === 1;
        if (!$isLegacy) {
            Movement::ensureForBill($id);   // تُنشأ الحركة أوتوماتيك فور إنشاء البوليصة
        }
    }
}
