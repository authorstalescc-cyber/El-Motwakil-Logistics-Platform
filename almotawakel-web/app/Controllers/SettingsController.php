<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\App;
use App\Core\Access;
use App\Core\Auth;
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
        $this->requireRole(['owner', 'manager']);
        $owner = Auth::isOwner();
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
            'userState'  => \App\Core\FormState::consume('settings.user.add', 'settings#tab-users'),
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
            'ai' => $owner ? $this->aiSettings() : [],
            'users'  => $owner
                ? Database::all('SELECT id, username, display_name, role, can_free_edit, created_at FROM users ORDER BY id')
                : [],
            'currentUid' => (int)($_SESSION['uid'] ?? 0),
            'canManageSecrets' => $owner,
            'roleLabels' => Access::ROLES,
        ]);
    }

    /** إضافة مستخدم جديد (اسم دخول + كلمة سر) */
    public function addUser(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $display  = trim($_POST['display_name'] ?? '');
        $canFree  = !empty($_POST['can_free_edit']) ? 1 : 0;
        $role     = Access::normalizeRole($_POST['role'] ?? 'read_only');

        if ($username === '' || $password === '') {
            $this->userFailure('لازم تدخل اسم المستخدم وكلمة السر.');
        }
        if (mb_strlen($password) < Auth::MIN_PASSWORD_LENGTH) {
            $this->userFailure('كلمة السر قصيرة — الحد الأدنى ٨ أحرف.');
        }
        if (Database::row('SELECT id FROM users WHERE username = ?', [$username])) {
            $this->userFailure('اسم المستخدم ده موجود بالفعل — اختار اسم تاني.');
        }

        Database::exec(
            'INSERT INTO users (username, password_hash, display_name, role, can_free_edit) VALUES (?, ?, ?, ?, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $display !== '' ? $display : null, $role, $canFree]
        );
        $newId=Database::lastId();
        Audit::log('create','users',$newId,null,Database::row('SELECT id,username,display_name,role,can_free_edit,created_at FROM users WHERE id=?',[$newId]),'إضافة مستخدم جديد');
        $this->done('تمت إضافة المستخدم «' . ($display !== '' ? $display : $username) . '».', 'settings#tab-users');
    }

    /**
     * فشل إضافة مستخدم — بيرجّع الحقول متعبّية.
     *
     * ⚠️ **كلمة السر مقصود إنها ما ترجعش**: تخزينها في الجلسة عشان نعيد
     * تعبئتها بيحطّها نصًّا صريحًا في مخزن الجلسة على الخادم بلا داعي.
     * نفس اللي بيعمله نموذج الدخول بالظبط.
     */
    private function userFailure(string $msg): never
    {
        $this->formFailure(
            'settings.user.add',
            'settings#tab-users',
            [
                'username'      => trim((string)($_POST['username'] ?? '')),
                'display_name'  => trim((string)($_POST['display_name'] ?? '')),
                'role'          => (string)($_POST['role'] ?? 'read_only'),
                'can_free_edit' => !empty($_POST['can_free_edit']) ? '1' : '',
            ],
            ['_form' => $msg],
            'settings#tab-users'
        );
    }

    /** تعديل مستخدم (اسم الدخول + الاسم الظاهر + كلمة السر اختيارياً) */
    public function updateUser(string $id): void
    {
        $this->requireRole('owner');
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
        $role     = Access::normalizeRole($_POST['role'] ?? 'read_only');

        if ($username === '') {
            flash('error', 'اسم المستخدم مطلوب.');
            redirect('settings#tab-users');
        }
        // اسم دخول مكرَّر عند مستخدم آخر؟
        if (Database::row('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id])) {
            flash('error', 'اسم المستخدم ده مستخدَم بالفعل — اختر اسمًا آخر.');
            redirect('settings#tab-users');
        }
        if (($user['role'] ?? '') === 'owner' && $role !== 'owner'
            && (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role='owner'") <= 1) {
            flash('error', 'لا يمكن تغيير دور آخر مالك في النظام. عيّن مالكًا آخر أولًا.');
            redirect('settings#tab-users');
        }
        // لو أدخل كلمة سر جديدة لازم تكون ٨ أحرف على الأقل
        if ($password !== '' && mb_strlen($password) < Auth::MIN_PASSWORD_LENGTH) {
            $this->userFailure('كلمة السر قصيرة — الحد الأدنى ٨ أحرف.');
        }

        $before=['id'=>$user['id'],'username'=>$user['username'],'display_name'=>$user['display_name'],'role'=>$user['role']??'read_only','can_free_edit'=>$user['can_free_edit']??0];
        if ($password !== '') {
            Database::exec(
                'UPDATE users SET username = ?, display_name = ?, role = ?, can_free_edit = ?, password_hash = ? WHERE id = ?',
                [$username, $display !== '' ? $display : null, $role, $canFree, password_hash($password, PASSWORD_DEFAULT), $id]
            );
        } else {
            Database::exec(
                'UPDATE users SET username = ?, display_name = ?, role = ?, can_free_edit = ? WHERE id = ?',
                [$username, $display !== '' ? $display : null, $role, $canFree, $id]
            );
        }
        Audit::log('update','users',$id,$before,Database::row('SELECT id,username,display_name,role,can_free_edit FROM users WHERE id=?',[$id]),'تعديل صلاحيات/بيانات مستخدم');

        // لو عدّل حسابه الحالي، حدّث الاسم الظاهر في الجلسة
        if ($id === (int)($_SESSION['uid'] ?? 0)) {
            $_SESSION['uname'] = $display !== '' ? $display : $username;
            $_SESSION['role'] = $role;
            $_SESSION['can_free_edit'] = $canFree;
            if (!$canFree) { \App\Core\Auth::setFreeEditMode(false); }
        }

        flash('success', 'تم تعديل بيانات المستخدم' . ($password !== '' ? ' وتغيير كلمة السر.' : '.'));
        redirect('settings#tab-users');
    }

    /** حذف مستخدم (لا يُسمح بحذف نفسك ولا آخر مستخدم) */
    public function deleteUser(string $id): void
    {
        $this->requireRole('owner');
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
        if (($user['role'] ?? '') === 'owner'
            && (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role='owner'") <= 1) {
            flash('error', 'لا يمكن حذف آخر مالك في النظام.');
            redirect('settings#tab-users');
        }
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
            'key1'       => '',
            'key1_set'   => $this->aiKey(1) !== '',
            'key1_hint'  => $this->maskedKey($this->aiKey(1)),
            'key1_label' => App::setting('ai_key_1_label', 'مفتاح ١'),
            'key2'       => '',
            'key2_set'   => $this->aiKey(2) !== '',
            'key2_hint'  => $this->maskedKey($this->aiKey(2)),
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
        $this->requireRole('owner');
        $this->requireCsrf();
        App::setSetting('ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
        App::setSetting('ai_active',  ($_POST['ai_active'] ?? '1') === '2' ? '2' : '1');
        App::setSetting('ai_model',   trim($_POST['ai_model'] ?? '') ?: 'llama-3.3-70b-versatile');
        foreach ([1, 2] as $slot) {
            $posted = trim((string) ($_POST['ai_key_'.$slot] ?? ''));
            if (!empty($_POST['clear_ai_key_'.$slot])) {
                App::setSetting('ai_key_'.$slot, '');
            } elseif ($posted !== '') {
                App::setSetting('ai_key_'.$slot, $posted);
            }
        }
        App::setSetting('ai_key_1_label', trim($_POST['ai_key_1_label'] ?? '') ?: 'مفتاح ١');
        App::setSetting('ai_key_2_label', trim($_POST['ai_key_2_label'] ?? '') ?: 'مفتاح ٢');
        flash('success', 'تم حفظ إعدادات المساعد الذكي.');
        redirect('settings#tab-ai');
    }

    /** اختبار مفتاح + موديل (AJAX JSON) — قبل الاعتماد عليه */
    public function testAi(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $slot  = (int) ($_POST['slot'] ?? 0);
        $key   = trim($_POST['key'] ?? '');
        if ($key === '' && in_array($slot, [1, 2], true)) { $key = $this->aiKey($slot); }
        $model = trim($_POST['model'] ?? '');
        $this->json(Groq::ping($key, $model));
    }

    /** حفظ إعدادات الأذونات والتايم لاين */
    public function savePermits(): void
    {
        $this->requireRole(['owner', 'manager']);
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

        $this->done('تم حفظ إعدادات التايم لاين والأذونات.', 'settings#tab-permits');
    }

    public function saveGeneral(): void
    {
        $this->requireRole(['owner', 'manager']);
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
        $this->done('تم حفظ الإعدادات العامة.', 'settings#tab-general');
    }

    public function addLookup(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        $type  = $_POST['list_type'] ?? '';
        $value = trim($_POST['value'] ?? '');
        if (!isset(Lookup::TYPES[$type])) { $this->fail('نوع القائمة ده مش معروف.', 'settings#tab-lists'); }
        if ($value === '') { $this->fail('اكتب قيمة العنصر الأول.', 'settings#tab-lists'); }

        // الشاشة دي هي الباب المتعمَّد لإضافة مفردة جديدة — فبتوقف وتخيّر بدل ما تبلع
        // بالسكوت زي المسارات التلقائية. المالك يقدر يأكّد ويعدّي (`confirm_new`).
        if (empty($_POST['confirm_new'])) {
            $key = \App\Models\Maintenance::normkey($value);
            foreach (Lookup::ofType($type) as $existing) {
                if (\App\Models\Maintenance::normkey($existing) === $key) {
                    $this->fail('«' . $existing . '» موجود في القائمة بالفعل — نفس الاسم بتهجئة تانية.', 'settings#tab-lists');
                }
            }
            $g = \App\Models\NameSuggestion::resolve($type, $value);
            if ($g['conflict'] !== null) {
                $this->fail(
                    'القيمة «' . $value . '» قريبة جدًا من «' . $g['conflict'] . '» الموجودة. '
                    . 'استخدم الموجودة، أو أعِد الإضافة مع تأكيد إنها مفردة جديدة فعلاً.',
                    'settings#tab-lists'
                );
            }
        }

        Lookup::add($type, $value, true);
        $this->done('تمت إضافة العنصر.', 'settings#tab-lists');
    }

    public function deleteLookup(string $id): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        Lookup::delete((int)$id);
        $this->done('تم حذف العنصر من القائمة.', 'settings#tab-lists');
    }

    /** توحيد المسميات المكررة تلقائياً (أداة صيانة) */
    public function normalizeNames(): void
    {
        $this->requireRole(['owner', 'manager']);
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

    private function aiKey(int $slot): string
    {
        $file = App::config('groq');
        $fallback = $slot === 1 && is_array($file) ? (string) ($file['api_key'] ?? '') : '';
        return (string) App::setting('ai_key_'.$slot, $fallback);
    }

    private function maskedKey(string $key): string
    {
        if ($key === '') { return ''; }
        $tail = mb_substr($key, -4, null, 'UTF-8');
        return '••••••••' . $tail;
    }
}
