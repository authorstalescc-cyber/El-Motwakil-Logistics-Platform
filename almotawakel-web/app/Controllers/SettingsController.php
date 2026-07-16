<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\App;
use App\Core\Database;
use App\Core\Groq;
use App\Models\Lookup;
use App\Models\Permit;
use App\Models\Maintenance;
use App\Models\Debt;
use App\Models\Audit;

class SettingsController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $this->render('settings/index', [
            'title'   => 'الإعدادات',
            'lookups' => Lookup::withIds(),
            'types'   => Lookup::TYPES,
            'general' => [
                'company_name' => App::setting('company_name', 'شركة المتوكل'),
                'vat_rate'     => App::setting('vat_rate', '0.14'),
                'report_year'  => App::setting('report_year', date('Y')),
                'currency'     => App::setting('currency', 'ج.م'),
                'opening_balance'      => App::setting('opening_balance', '0'),
                'opening_balance_date' => App::setting('opening_balance_date', ''),
                'company_phone'   => App::setting('company_phone', ''),
                'company_address' => App::setting('company_address', ''),
                'company_tax_id'  => App::setting('company_tax_id', ''),
                'company_email'   => App::setting('company_email', ''),
            ],
            'permitCfg'  => Permit::stageConfig(),
            // قواعد مرشد الإيصالات (v32): قوائم التوكيلات — سطر لكل توكيل
            'rcpRules'   => [
                'insurance' => implode("\n", Permit::ruleAgencies('receipt_rules_insurance_agencies')),
                'ateeq'     => implode("\n", Permit::ruleAgencies('receipt_rules_ateeq_agencies')),
            ],
            'permitNums' => [
                // سعر الكشف والضمان صار لكل إذن على حِدة (يُدخَل في الإذن نفسه) — لا سعر عام هنا.
                'inspection_batch'    => App::setting('inspection_batch', '10'),
                'inspection_creditor' => Debt::inspectionAccount()['name'],   // مستحق الكشف
                'guarantee_creditor'  => Debt::guaranteeAccount()['name'],    // مستحق الضمان
            ],
            'ai' => $this->aiSettings(),
            'users'  => Database::all('SELECT id, username, display_name, can_free_edit, created_at FROM users ORDER BY id'),
            'currentUid' => (int)($_SESSION['uid'] ?? 0),
        ]);
    }

    /** إضافة مستخدم جديد (اسم دخول + كلمة سر) */
    public function addUser(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $display  = trim($_POST['display_name'] ?? '');
        $canFree  = !empty($_POST['can_free_edit']) ? 1 : 0;

        if ($username === '' || $password === '') {
            flash('error', 'لازم تدخل اسم المستخدم وكلمة السر.');
            redirect('settings#tab-users');
        }
        if (mb_strlen($password) < 4) {
            flash('error', 'كلمة السر قصيرة جدًا — الحد الأدنى ٤ أحرف.');
            redirect('settings#tab-users');
        }
        if (Database::row('SELECT id FROM users WHERE username = ?', [$username])) {
            flash('error', 'اسم المستخدم ده موجود بالفعل — اختر اسمًا آخر.');
            redirect('settings#tab-users');
        }

        Database::exec(
            'INSERT INTO users (username, password_hash, display_name, can_free_edit) VALUES (?, ?, ?, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $display !== '' ? $display : null, $canFree]
        );
        $newId=Database::lastId();
        Audit::log('create','users',$newId,null,Database::row('SELECT id,username,display_name,can_free_edit,created_at FROM users WHERE id=?',[$newId]),'إضافة مستخدم جديد');
        flash('success', 'تمت إضافة المستخدم «' . ($display !== '' ? $display : $username) . '».');
        redirect('settings#tab-users');
    }

    /** تعديل مستخدم (اسم الدخول + الاسم الظاهر + كلمة السر اختيارياً) */
    public function updateUser(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $id = (int)$id;

        $user = Database::row('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user) {
            flash('error', 'المستخدم غير موجود.');
            redirect('settings#tab-users');
        }

        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $display  = trim($_POST['display_name'] ?? '');
        $canFree  = !empty($_POST['can_free_edit']) ? 1 : 0;

        if ($username === '') {
            flash('error', 'اسم المستخدم مطلوب.');
            redirect('settings#tab-users');
        }
        // اسم دخول مكرَّر عند مستخدم آخر؟
        if (Database::row('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id])) {
            flash('error', 'اسم المستخدم ده مستخدَم بالفعل — اختر اسمًا آخر.');
            redirect('settings#tab-users');
        }
        // لو أدخل كلمة سر جديدة لازم تكون ٤ أحرف على الأقل
        if ($password !== '' && mb_strlen($password) < 4) {
            flash('error', 'كلمة السر قصيرة جدًا — الحد الأدنى ٤ أحرف.');
            redirect('settings#tab-users');
        }

        $before=['id'=>$user['id'],'username'=>$user['username'],'display_name'=>$user['display_name'],'can_free_edit'=>$user['can_free_edit']??0];
        if ($password !== '') {
            Database::exec(
                'UPDATE users SET username = ?, display_name = ?, can_free_edit = ?, password_hash = ? WHERE id = ?',
                [$username, $display !== '' ? $display : null, $canFree, password_hash($password, PASSWORD_DEFAULT), $id]
            );
        } else {
            Database::exec(
                'UPDATE users SET username = ?, display_name = ?, can_free_edit = ? WHERE id = ?',
                [$username, $display !== '' ? $display : null, $canFree, $id]
            );
        }
        Audit::log('update','users',$id,$before,Database::row('SELECT id,username,display_name,can_free_edit FROM users WHERE id=?',[$id]),'تعديل صلاحيات/بيانات مستخدم');

        // لو عدّل حسابه الحالي، حدّث الاسم الظاهر في الجلسة
        if ($id === (int)($_SESSION['uid'] ?? 0)) {
            $_SESSION['uname'] = $display !== '' ? $display : $username;
            $_SESSION['can_free_edit'] = $canFree;
            if (!$canFree) { \App\Core\Auth::setFreeEditMode(false); }
        }

        flash('success', 'تم تعديل بيانات المستخدم' . ($password !== '' ? ' وتغيير كلمة السر.' : '.'));
        redirect('settings#tab-users');
    }

    /** حذف مستخدم (لا يُسمح بحذف نفسك ولا آخر مستخدم) */
    public function deleteUser(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $id = (int)$id;

        if ($id === (int)($_SESSION['uid'] ?? 0)) {
            flash('error', 'مينفعش تحذف حسابك اللي داخل بيه دلوقتي.');
            redirect('settings#tab-users');
        }
        if ((int)Database::scalar('SELECT COUNT(*) FROM users') <= 1) {
            flash('error', 'مينفعش تحذف آخر مستخدم في النظام.');
            redirect('settings#tab-users');
        }

        $user=Database::row('SELECT * FROM users WHERE id=?',[$id]);
        if (!$user) { flash('error','المستخدم غير موجود.'); redirect('settings#tab-users'); }
        $pdo=Database::conn();$pdo->beginTransaction();
        try{Audit::trash('users',$user,$user['display_name']?:$user['username']);Database::exec('DELETE FROM users WHERE id=?',[$id]);$pdo->commit();}
        catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','تعذّر حذف المستخدم: '.$e->getMessage());redirect('settings#tab-users');}
        flash('success', 'تم نقل المستخدم إلى سلة المحذوفات.');
        redirect('settings#tab-users');
    }

    /** إعدادات المساعد الذكي للعرض في الفورم (مع الرجوع لـ config.php كقيمة أولية) */
    private function aiSettings(): array
    {
        $file = App::config('groq');
        $file = is_array($file) ? $file : [];
        return [
            'enabled'    => App::setting('ai_enabled', !empty($file['enabled']) ? '1' : '0'),
            'active'     => App::setting('ai_active', '1') === '2' ? '2' : '1',
            'model'      => App::setting('ai_model', $file['model'] ?? 'llama-3.3-70b-versatile'),
            'key1'       => App::setting('ai_key_1', $file['api_key'] ?? ''),
            'key1_label' => App::setting('ai_key_1_label', 'مفتاح ١'),
            'key2'       => App::setting('ai_key_2', ''),
            'key2_label' => App::setting('ai_key_2_label', 'مفتاح ٢'),
            'models'     => [
                'llama-3.3-70b-versatile',
                'openai/gpt-oss-120b',
                'qwen/qwen3.6-27b',
                'allam-2-7b',
                'meta-llama/llama-4-scout-17b-16e-instruct',
                'llama-3.1-8b-instant',
            ],
        ];
    }

    /** حفظ إعدادات المساعد الذكي (المفاتيح + النشط + الموديل) */
    public function saveAi(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        App::setSetting('ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
        App::setSetting('ai_active',  ($_POST['ai_active'] ?? '1') === '2' ? '2' : '1');
        App::setSetting('ai_model',   trim($_POST['ai_model'] ?? '') ?: 'llama-3.3-70b-versatile');
        App::setSetting('ai_key_1',   trim($_POST['ai_key_1'] ?? ''));
        App::setSetting('ai_key_2',   trim($_POST['ai_key_2'] ?? ''));
        App::setSetting('ai_key_1_label', trim($_POST['ai_key_1_label'] ?? '') ?: 'مفتاح ١');
        App::setSetting('ai_key_2_label', trim($_POST['ai_key_2_label'] ?? '') ?: 'مفتاح ٢');
        flash('success', 'تم حفظ إعدادات المساعد الذكي.');
        redirect('settings#tab-ai');
    }

    /** اختبار مفتاح + موديل (AJAX JSON) — قبل الاعتماد عليه */
    public function testAi(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $key   = trim($_POST['key'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $this->json(Groq::ping($key, $model));
    }

    /** حفظ إعدادات الأذونات والتايم لاين */
    public function savePermits(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        // سعر الكشف والضمان لم يعد إعداداً عاماً — يُدخَل لكل إذن في صفحته.
        App::setSetting('inspection_batch',  (string)max(1, (int)($_POST['inspection_batch'] ?? 10)));
        Debt::setInspectionCreditor((string)($_POST['inspection_creditor'] ?? ''));  // مستحق الكشف (يُسمح بالفراغ)
        Debt::setGuaranteeCreditor((string)($_POST['guarantee_creditor'] ?? ''));    // مستحق الضمان

        // حماية: لا نعيد بناء إعدادات المراحل إلا لو الفورم أرسل مصفوفة المراحل فعلاً.
        // (يمنع تصفير إظهار/أسماء المراحل عند إرسال جزئي للنموذج.)
        if (isset($_POST['stage_label']) && is_array($_POST['stage_label'])) {
            $labels  = $_POST['stage_label'];
            $orders  = $_POST['stage_order']   ?? [];
            $enabled = $_POST['stage_enabled'] ?? [];   // checkbox: المفتاح موجود = مفعّل

            $cfg = [];
            foreach (Permit::STAGES as $key => $meta) {
                $cfg[$key] = [
                    'label'   => trim((string)($labels[$key] ?? '')),
                    'order'   => (int)($orders[$key] ?? 0),
                    'enabled' => in_array($key, Permit::ALWAYS_ON, true) ? true : isset($enabled[$key]),
                ];
            }
            App::setSetting('permit_stages_config', json_encode($cfg, JSON_UNESCAPED_UNICODE));
        }

        // قواعد مرشد الإيصالات (v32): قوائم التوكيلات — تُحفَظ كـ JSON.
        // كل سطر = توكيل. تُحفظ فقط لو أرسل الفورم الحقل (إرسال جزئي لا يصفّرها).
        $splitAgencies = static function (string $raw): array {
            $parts = preg_split('/[\r\n,]+/u', $raw) ?: [];
            $out = [];
            foreach ($parts as $x) { $x = trim($x); if ($x !== '') { $out[] = $x; } }
            return array_values(array_unique($out));
        };
        if (isset($_POST['insurance_agencies'])) {
            App::setSetting('receipt_rules_insurance_agencies',
                json_encode($splitAgencies((string)$_POST['insurance_agencies']), JSON_UNESCAPED_UNICODE));
        }
        if (isset($_POST['ateeq_agencies'])) {
            App::setSetting('receipt_rules_ateeq_agencies',
                json_encode($splitAgencies((string)$_POST['ateeq_agencies']), JSON_UNESCAPED_UNICODE));
        }

        flash('success', 'تم حفظ إعدادات التايم لاين والأذونات.');
        redirect('settings');
    }

    public function saveGeneral(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        App::setSetting('company_name', trim($_POST['company_name'] ?? 'شركة المتوكل'));
        $vat = (float)($_POST['vat_rate'] ?? 0.14);
        if ($vat > 1) { $vat = $vat / 100; }   // لو أدخل 14 بدل 0.14
        App::setSetting('vat_rate', (string)$vat);
        App::setSetting('report_year', (string)(int)($_POST['report_year'] ?? date('Y')));
        App::setSetting('currency', trim($_POST['currency'] ?? 'ج.م'));
        App::setSetting('opening_balance', (string)(float)($_POST['opening_balance'] ?? 0));
        // تاريخ الرصيد الافتتاحي: يُقبل فقط بصيغة YYYY-MM-DD (وإلا يُخزَّن فارغاً) — يُستخدم في استعلامات التقفيل اليومي
        $obDate = trim($_POST['opening_balance_date'] ?? '');
        App::setSetting('opening_balance_date', preg_match('/^\d{4}-\d{2}-\d{2}$/', $obDate) ? $obDate : '');
        App::setSetting('company_phone',   trim($_POST['company_phone'] ?? ''));
        App::setSetting('company_address', trim($_POST['company_address'] ?? ''));
        App::setSetting('company_tax_id',  trim($_POST['company_tax_id'] ?? ''));
        App::setSetting('company_email',   trim($_POST['company_email'] ?? ''));
        flash('success', 'تم حفظ الإعدادات العامة.');
        redirect('settings');
    }

    public function addLookup(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $type  = $_POST['list_type'] ?? '';
        $value = trim($_POST['value'] ?? '');
        if (isset(Lookup::TYPES[$type]) && $value !== '') {
            Lookup::add($type, $value);
            flash('success', 'تمت إضافة العنصر.');
        }
        redirect('settings');
    }

    public function deleteLookup(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        Lookup::delete((int)$id);
        flash('success', 'تم الحذف.');
        redirect('settings');
    }

    /** توحيد المسميات المكررة تلقائياً (أداة صيانة) */
    public function normalizeNames(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $rep = Maintenance::normalizeNames(false);
        $t = $rep['totals'];
        if ($t['merges'] > 0) {
            flash('success', sprintf('تم توحيد المسميات: %d مجموعة مكرَّرة · %d صف مُوحَّد · %d عنصر مكرَّر أُزيل من القوائم.',
                $t['merges'], $t['rows'], $t['lookupDel']));
        } else {
            flash('success', 'لا توجد مسميات مكرَّرة — كل الأسماء موحَّدة بالفعل ✓');
        }
        redirect('settings#tab-maint');
    }
}
