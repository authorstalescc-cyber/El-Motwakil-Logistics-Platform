<?php
use App\Models\Permit;
$canManageSecrets = !empty($canManageSecrets);

// تجميع القوائم المنسدلة في فئات منطقية للعرض
$lookupGroups = [
    'أطراف التعامل' => ['company','client','destination'],
    'بيانات الشحن'  => ['shipping_co','agency','port'],
    'النقل والحاويات' => ['driver','car_owner','container_type','classification'],
    'الفئات المالية' => ['mv_expense','mv_receipt','mv_discount','expense_cat'],
];
$totalLookups = 0;
foreach ($lookups as $arr) { $totalLookups += count($arr); }
?>
<style>
.settings-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;border-bottom:2px solid var(--line);padding-bottom:2px}
.settings-tabs .tab-btn{background:transparent;border:0;padding:10px 18px;border-radius:10px 10px 0 0;
  font-family:inherit;font-size:var(--fs-3);font-weight:500;color:var(--grey);cursor:pointer;position:relative;top:2px}
.settings-tabs .tab-btn.active{color:var(--navy);background:var(--card);border:2px solid var(--line);border-bottom:2px solid var(--card)}
.tab-panel{display:none;animation:tp .18s ease}
.tab-panel.active{display:block}
@keyframes tp{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
.set-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
@media(max-width:820px){.set-grid{grid-template-columns:1fr}}
.lk-card{border:1px solid var(--line);border-radius:var(--radius-2);padding:16px;background:var(--card)}
.lk-card h4{margin:0 0 4px;color:var(--navy);font-size:var(--fs-4)}
.lk-card .cnt{color:var(--grey);font-weight:400;font-size:var(--fs-2)}
.maint-note{background:#f6f8ff;border:1px dashed #c5cbf0;border-radius:var(--radius-2);padding:14px 16px;color:var(--navy);line-height:1.8;margin-bottom:14px}
/* تأكيد «مفردة جديدة فعلاً» — يتجاوز حارس الأسماء بقرار المالك.
   محلي عن قصد: مستعمل في الشاشة دي بس (قاعدة المشروع بتمنع الـ<style> المحلي
   للكلاسات **المشتركة** بين شاشتين، زي درس `.cars-period`). */
.lk-confirm{display:inline-flex;align-items:center;gap:6px;flex:0 0 auto;font-size:var(--fs-1);color:var(--grey);cursor:pointer;white-space:nowrap}
.lk-confirm input{flex:0 0 auto;min-width:0;width:auto;padding:0}
</style>

<div class="settings-tabs" role="tablist" aria-label="أقسام الإعدادات">
  <button type="button" class="tab-btn active" data-tab="general"><?= icon('settings', 14) ?> عام</button>
  <button type="button" class="tab-btn" data-tab="lists"><?= icon('clipboard', 14) ?> القوائم المنسدلة</button>
  <button type="button" class="tab-btn" data-tab="permits"><?= icon('folder', 14) ?> الأذونات والتايم لاين</button>
  <?php if ($canManageSecrets): ?>
    <button type="button" class="tab-btn" data-tab="ai">🤖 المساعد الذكي</button>
    <button type="button" class="tab-btn" data-tab="users"><?= icon('users', 14) ?> المستخدمون</button>
  <?php endif; ?>
  <button type="button" class="tab-btn" data-tab="maint">🧰 الصيانة<?= $canManageSecrets ? ' والنسخ الاحتياطي' : '' ?></button>
</div>

<!-- ============ تبويب: عام ============ -->
<div class="tab-panel active" id="tab-general">
  <div class="card" style="padding:22px;max-width:760px">
    <div class="section-head"><?= icon('home', 17) ?> بيانات الشركة والإعدادات العامة</div>
    <form method="post" action="<?= url('settings/general') ?>">
      <?= csrfField() ?>
      <div class="set-grid">
        <div class="field"><label>اسم الشركة</label>
          <input type="text" name="company_name" value="<?= e($general['company_name']) ?>"></div>
        <div class="field"><label>العملة</label>
          <input type="text" name="currency" value="<?= e($general['currency']) ?>"></div>
        <div class="field"><label><?= icon('send', 14) ?> تليفون الشركة (يظهر في الفاتورة)</label>
          <input type="text" name="company_phone" value="<?= e($general['company_phone']) ?>" placeholder="مثال: 03-000000"></div>
        <div class="field"><label><?= icon('send', 14) ?> البريد الإلكتروني</label>
          <input type="text" name="company_email" value="<?= e($general['company_email']) ?>" placeholder="اختياري"></div>
        <div class="field" style="grid-column:1/-1"><label><?= icon('map-pin', 14) ?> عنوان الشركة (يظهر في الفاتورة)</label>
          <input type="text" name="company_address" value="<?= e($general['company_address']) ?>" placeholder="العنوان بالكامل"></div>
        <div class="field"><label><?= icon('receipt', 14) ?> البطاقة الضريبية / السجل التجاري</label>
          <input type="text" name="company_tax_id" value="<?= e($general['company_tax_id']) ?>" placeholder="اختياري"></div>
        <div class="field"><label>نسبة القيمة المضافة (0.14 أو 14)</label>
          <input type="number" step="0.01" name="vat_rate" value="<?= e($general['vat_rate']) ?>"></div>
        <div class="field"><label>سنة التقارير</label>
          <input type="number" name="report_year" value="<?= e($general['report_year']) ?>"></div>
        <div class="field"><label>باقي عهدة سابقة (رصيد افتتاحي)</label>
          <input type="number" step="0.01" name="opening_balance" value="<?= e($general['opening_balance']) ?>"></div>
        <div class="field" style="grid-column:1/-1"><label>تاريخ بداية احتساب الرصيد الافتتاحي</label>
          <input type="date" name="opening_balance_date" value="<?= e($general['opening_balance_date']) ?>"></div>
      </div>
      <div style="margin-top:16px"><button class="btn gold" type="submit"><?= icon('download', 14) ?> حفظ الإعدادات العامة</button></div>
    </form>
  </div>
</div>

<!-- ============ تبويب: القوائم المنسدلة ============ -->
<div class="tab-panel" id="tab-lists">
  <?php foreach ($lookupGroups as $gLabel => $keys): ?>
    <div class="section-head" style="margin-top:6px"><?= e($gLabel) ?></div>
    <div class="set-grid" style="margin-bottom:20px">
      <?php foreach ($keys as $key): if (!isset($types[$key])) continue; ?>
        <div class="lk-card">
          <h4><?= e($types[$key]) ?> <span class="cnt">(<?= count($lookups[$key]) ?>)</span></h4>
          <div class="chip-list" style="margin:10px 0">
            <?php foreach ($lookups[$key] as $item): ?>
              <span class="chip"><?= e($item['value']) ?>
                <form class="confirm-delete" method="post" action="<?= url('settings/lookup/'.$item['id'].'/delete') ?>" style="display:inline" data-confirm="حذف «<?= e($item['value']) ?>» من قائمة <?= e($types[$key]) ?>؟">
                  <?= csrfField() ?><button type="submit" title="حذف">×</button>
                </form>
              </span>
            <?php endforeach; ?>
            <?php if(!$lookups[$key]): ?><span style="color:var(--grey);font-size:var(--fs-2)">— فارغة —</span><?php endif; ?>
          </div>
          <form class="inline-form no-confirm" method="post" action="<?= url('settings/lookup') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="list_type" value="<?= e($key) ?>">
            <input type="text" name="value" placeholder="أضف عنصراً…">
            <button class="btn sm" type="submit">إضافة</button>
            <label class="lk-confirm" title="لو النظام قال إن العنصر قريب من عنصر موجود وانت متأكد إنه مختلف فعلاً">
              <input type="checkbox" name="confirm_new" value="1"> مفردة جديدة فعلاً
            </label>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<!-- ============ تبويب: الأذونات والتايم لاين ============ -->
<div class="tab-panel" id="tab-permits">
  <div class="card" style="padding:22px">
    <div class="section-head"><?= icon('folder', 17) ?> إعدادات الأذونات والتايم لاين</div>
    <form method="post" action="<?= url('settings/permits') ?>">
      <?= csrfField() ?>
      <div class="set-grid" style="margin-bottom:22px">
        <div class="field"><label>أقصى عدد حاويات للكشف/الضمان الواحد</label>
          <input type="number" step="1" min="1" name="inspection_batch" value="<?= e($permitNums['inspection_batch']) ?>"></div>
        <div class="field"><label>جهة الكشف (المستحق)</label>
          <input type="text" name="inspection_creditor" value="<?= e($permitNums['inspection_creditor']) ?>"></div>
        <div class="field"><label>جهة الضمان (المستحق)</label>
          <input type="text" name="guarantee_creditor" value="<?= e($permitNums['guarantee_creditor']) ?>"></div>
      </div>
      <div class="table-wrap"><table class="data" style="white-space:normal">
        <thead><tr>
          <th class="g-identity" style="width:90px">الترتيب</th>
          <th class="g-identity" style="width:60px">الأيقونة</th>
          <th class="g-identity">اسم المرحلة</th>
          <th class="g-identity" style="width:120px">النوع</th>
          <th class="g-identity" style="width:110px">الإظهار</th>
        </tr></thead>
        <tbody>
          <?php
            $dirTxt = [Permit::DIR_EXPENSE=>'مصروف', Permit::DIR_REVENUE=>'محصّل', Permit::DIR_LINK=>'مرتبط', Permit::DIR_INFO=>'بيانات'];
            foreach ($permitCfg as $key => $st):
              $core = in_array($key, Permit::ALWAYS_ON, true);
          ?>
            <tr>
              <td><input type="number" name="stage_order[<?= e($key) ?>]" value="<?= (int)$st['order'] ?>" style="width:70px;border:1px solid var(--line2);border-radius:var(--radius-1);padding:6px;text-align:center"></td>
              <td style="font-size:var(--fs-5)"><?= $st['icon'] ?></td>
              <td><input name="stage_label[<?= e($key) ?>]" value="<?= e($st['label']) ?>" style="width:100%;border:1px solid var(--line2);border-radius:var(--radius-1);padding:7px"></td>
              <td><?= $dirTxt[$st['dir']] ?? '' ?></td>
              <td style="text-align:center">
                <?php if ($core): ?>
                  <span class="pill st-paid">أساسية</span>
                <?php else: ?>
                  <label style="cursor:pointer"><input type="checkbox" name="stage_enabled[<?= e($key) ?>]" <?= $st['enabled']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--gold)"> ظاهرة</label>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>

      <div class="section-head" style="margin-top:26px"><?= icon('receipt', 17) ?> قواعد مرشد الإيصالات</div>
      <p style="font-size:var(--fs-2);color:var(--ink-mut);font-weight:500;margin:4px 0 14px">
        استرشادي بحت — بيقول «الإذن ده المتوقّع فيه كذا» بس، مايلزمش بأي حاجة. اكتب توكيل في كل سطر.
      </p>
      <div class="set-grid">
        <div class="field"><label>توكيلات التأمين (يُغني عن إيصال التخصيص للصادر)</label>
          <textarea name="insurance_agencies" rows="4" placeholder="ميرسك&#10;هابج لويد"
            style="width:100%;border:1px solid var(--line2);border-radius:var(--radius-1);padding:9px;font-weight:500;resize:vertical"><?= e($rcpRules['insurance'] ?? '') ?></textarea></div>
        <div class="field"><label>توكيلات تعتيق الحاويات (صادر)</label>
          <textarea name="ateeq_agencies" rows="4" placeholder="أركاس"
            style="width:100%;border:1px solid var(--line2);border-radius:var(--radius-1);padding:9px;font-weight:500;resize:vertical"><?= e($rcpRules['ateeq'] ?? '') ?></textarea></div>
      </div>

      <button class="btn gold" type="submit" style="margin-top:16px"><?= icon('download', 14) ?> حفظ إعدادات التايم لاين</button>
    </form>
  </div>
</div>

<!-- ============ تبويب: المساعد الذكي ============ -->
<?php if ($canManageSecrets): ?>
<style>
.ai-onoff{display:flex;align-items:center;gap:8px;font-weight:500;color:var(--navy);font-size:var(--fs-4)}
.ai-onoff input{width:18px;height:18px}
.ai-hint{display:block;color:var(--grey);font-size:var(--fs-1);margin-top:4px}
.ai-key{border:1px solid var(--line);border-radius:var(--radius-2);padding:14px;margin-top:14px;background:var(--zebra)}
.ai-key-top{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:8px}
.ai-active{display:flex;align-items:center;gap:6px;color:var(--navy);white-space:nowrap}
.ai-active input{width:16px;height:16px}
.ai-name{flex:1;min-width:140px;padding:6px 10px;border:1px solid var(--line2);border-radius:var(--radius-1);font-family:inherit;background:#fff}
.ai-key-in{display:flex;gap:8px}
.ai-token{flex:1;padding:9px 12px;border:1px solid var(--line2);border-radius:var(--radius-1);font-family:var(--mono,monospace);background:var(--input);direction:ltr}
.ai-res{margin-top:8px;font-size:var(--fs-2);font-weight:400;display:none}
.ai-res.show{display:block}
.ai-res.ok{color:var(--green)} .ai-res.err{color:var(--red)} .ai-res.muted{color:var(--grey)}
</style>
<div class="tab-panel" id="tab-ai">
  <div class="card" style="padding:22px;max-width:760px">
    <div class="section-head"><?= icon('cpu', 17) ?> مفاتيح المساعد الذكي (Groq AI)</div>
    <p class="maint-note">المفتاح زي كلمة السر — بلاش تنشره لأي حد. تقدر تحفظ مفتاحين وتبدّل بينهم لو خلص رصيد واحد منهم. اضغط «اختبار» عشان تتأكد إن المفتاح شغّال قبل ما تعتمد عليه.</p>
    <form method="post" action="<?= url('settings/ai') ?>" id="ai-set-form" class="no-confirm">
      <?= csrfField() ?>
      <label class="ai-onoff"><input type="checkbox" name="ai_enabled" value="1" <?= $ai['enabled']==='1'?'checked':'' ?>> تفعيل المساعد الذكي</label>

      <div class="field" style="margin-top:14px;max-width:440px"><label>الموديل المستخدم</label>
        <select name="ai_model" id="ai-model">
          <?php foreach ($ai['models'] as $m): ?>
            <option value="<?= e($m) ?>" <?= $ai['model']===$m?'selected':'' ?>><?= e($m) ?></option>
          <?php endforeach; ?>
          <?php if ($ai['model']!=='' && !in_array($ai['model'],$ai['models'],true)): ?>
            <option value="<?= e($ai['model']) ?>" selected><?= e($ai['model']) ?> (مخصّص)</option>
          <?php endif; ?>
        </select>
        <small class="ai-hint">llama-3.3-70b سريع وعربيته كويسة · allam-2-7b متخصص عربي</small>
      </div>

      <?php foreach ([
          ['n'=>'1','label'=>$ai['key1_label'],'val'=>$ai['key1'],'set'=>$ai['key1_set'],'hint'=>$ai['key1_hint']],
          ['n'=>'2','label'=>$ai['key2_label'],'val'=>$ai['key2'],'set'=>$ai['key2_set'],'hint'=>$ai['key2_hint']],
      ] as $k): ?>
        <div class="ai-key">
          <div class="ai-key-top">
            <label class="ai-active"><input type="radio" name="ai_active" value="<?= $k['n'] ?>" <?= $ai['active']===$k['n']?'checked':'' ?>> <b>المفتاح النشط</b></label>
            <input type="text" name="ai_key_<?= $k['n'] ?>_label" value="<?= e($k['label']) ?>" class="ai-name" placeholder="اسم المفتاح (مثال: MO-<?= $k['n'] ?>)">
          </div>
          <div class="ai-key-in">
            <input type="password" name="ai_key_<?= $k['n'] ?>" value="" placeholder="<?= $k['set'] ? e('محفوظ: '.$k['hint'].' — اتركه فارغًا للإبقاء عليه') : 'gsk_...' ?>" autocomplete="new-password" class="ai-token" data-token="<?= $k['n'] ?>">
            <button type="button" class="btn ghost sm ai-eye" data-eye="<?= $k['n'] ?>" title="إظهار/إخفاء"><?= icon('search', 14) ?></button>
            <button type="button" class="btn ghost sm ai-test" data-test="<?= $k['n'] ?>">🔌 اختبار</button>
          </div>
          <?php if ($k['set']): ?><label class="ai-hint"><input type="checkbox" name="clear_ai_key_<?= $k['n'] ?>" value="1"> حذف المفتاح المحفوظ عند الحفظ</label><?php endif; ?>
          <div class="ai-res" data-res="<?= $k['n'] ?>"></div>
        </div>
      <?php endforeach; ?>

      <div style="margin-top:16px"><button class="btn gold" type="submit"><?= icon('download', 14) ?> حفظ إعدادات المساعد</button></div>
    </form>
  </div>
</div>
<script>
(function(){
  var form = document.getElementById('ai-set-form');
  if(!form) return;
  var TEST_URL = <?= js_json(url('settings/ai/test')) ?>;
  var csrf = form.querySelector('[name=_csrf]').value;
  form.querySelectorAll('.ai-eye').forEach(function(btn){
    btn.addEventListener('click', function(){
      var inp = form.querySelector('.ai-token[data-token="'+btn.dataset.eye+'"]');
      inp.type = inp.type === 'password' ? 'text' : 'password';
    });
  });
  form.querySelectorAll('.ai-test').forEach(function(btn){
    btn.addEventListener('click', function(){
      var n = btn.dataset.test;
      var key = form.querySelector('.ai-token[data-token="'+n+'"]').value.trim();
      var model = form.querySelector('#ai-model').value;
      var res = form.querySelector('.ai-res[data-res="'+n+'"]');
      res.className = 'ai-res show muted'; res.textContent = 'جارٍ الاختبار…';
      btn.disabled = true;
      var fd = new FormData();
      fd.append('_csrf', csrf); fd.append('key', key); fd.append('slot', n); fd.append('model', model);
      fetch(TEST_URL, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){return r.json();})
        .then(function(d){
          if(d && d.ok){ res.className='ai-res show ok'; res.textContent = d.message || 'شغّال ✓'; }
          else { res.className='ai-res show err'; res.textContent = (d && d.error) ? d.error : 'فشل الاختبار'; }
        })
        .catch(function(){ res.className='ai-res show err'; res.textContent='تعذّر الاتصال بالسيرفر'; })
        .finally(function(){ btn.disabled=false; });
    });
  });
})();
</script>
<?php endif; ?>

<!-- ============ تبويب: الصيانة والنسخ الاحتياطي ============ -->
<!-- ============ تبويب: المستخدمون ============ -->
<?php if ($canManageSecrets): ?>
<div class="tab-panel" id="tab-users">
<?php if (($userState['errors']['_form'] ?? '') !== ''): ?>
  <div class="flash error" role="alert"><?= icon('alert',18) ?> <?= e($userState['errors']['_form']) ?> — البيانات اللي كتبتها زي ما هي (عدا كلمة السر).</div>
<?php endif; ?>
  <div class="set-grid">
    <div class="card" style="padding:22px">
      <div class="section-head"><?= icon('plus', 17) ?> إضافة مستخدم جديد</div>
      <form method="post" action="<?= url('settings/users') ?>" autocomplete="off">
        <?= csrfField() ?>
        <div class="field">
          <label>اسم الدخول (username)</label>
          <input type="text" name="username" value="<?= e($userState['old']['username'] ?? '') ?>" required autocomplete="off" placeholder="مثال: ahmed">
        </div>
        <div class="field">
          <label>الاسم الظاهر (اختياري)</label>
          <input type="text" name="display_name" value="<?= e($userState['old']['display_name'] ?? '') ?>" autocomplete="off" placeholder="مثال: أحمد محمد">
        </div>
        <div class="field">
          <label>كلمة السر</label>
          <input type="password" name="password" required autocomplete="new-password" minlength="8" placeholder="٨ أحرف على الأقل">
        </div>
        <div class="field"><label>الدور</label><select name="role" required>
          <?php foreach (($roleLabels ?? []) as $rk => $rl): ?><option value="<?= e($rk) ?>" <?= $rk === ($userState['old']['role'] ?? 'read_only') ? 'selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?>
        </select></div>
        <label class="free-login-option"><input type="checkbox" name="can_free_edit" value="1" <?= ($userState['old']['can_free_edit'] ?? '') !== '' ? 'checked' : '' ?>><span><b>صلاحية الدخول الحر</b><small>كتابة على الأيام المقفولة</small></span></label>
        <div style="margin-top:16px"><button class="btn gold" type="submit"><?= icon('plus', 14) ?> إضافة المستخدم</button></div>
      </form>
    </div>

    <div class="card" style="padding:22px">
      <div class="section-head"><?= icon('users', 17) ?> المستخدمون الحاليون</div>
      <div class="table-wrap">
      <table class="data">
        <thead><tr><th>اسم الدخول</th><th>الاسم الظاهر</th><th>الدور</th><th>الدخول الحر</th><th></th></tr></thead>
        <tbody>
        <?php foreach (($users ?? []) as $u): $uid = (int)$u['id']; $isSelf = $uid === (int)($currentUid ?? 0); ?>
          <tr>
            <td><?= e($u['username']) ?><?php if ($isSelf): ?> <span style="opacity:.6">(أنت)</span><?php endif; ?></td>
            <td><?= e($u['display_name'] ?: '—') ?></td>
            <td><span class="pill"><?= e(($roleLabels ?? [])[$u['role'] ?? 'read_only'] ?? 'قراءة فقط') ?></span></td>
            <td><?= !empty($u['can_free_edit']) ? '<span class="pill st-paid">مسموح</span>' : '<span class="pill">عادي</span>' ?></td>
            <td style="text-align:left;white-space:nowrap">
              <button type="button" class="btn ghost sm" data-edit-user="<?= $uid ?>" title="تعديل"><?= icon('edit', 14) ?> تعديل</button>
              <?php if (!$isSelf): ?>
                <form method="post" action="<?= url('settings/users/' . $uid . '/delete') ?>" data-confirm="حذف المستخدم «<?= e($u['username']) ?>»؟" style="display:inline">
                  <?= csrfField() ?>
                  <button type="submit" class="btn ghost sm" title="حذف"><?= icon('trash', 14) ?> حذف</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <tr class="user-edit-row" id="user-edit-<?= $uid ?>" style="display:none">
            <td colspan="5" style="background:var(--bg)">
              <form method="post" action="<?= url('settings/users/' . $uid) ?>" autocomplete="off" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;padding:6px 2px">
                <?= csrfField() ?>
                <div class="field" style="margin:0;flex:1 1 160px">
                  <label>اسم الدخول</label>
                  <input type="text" name="username" value="<?= e($u['username']) ?>" required autocomplete="off">
                </div>
                <div class="field" style="margin:0;flex:1 1 160px">
                  <label>الاسم الظاهر</label>
                  <input type="text" name="display_name" value="<?= e($u['display_name']) ?>" autocomplete="off">
                </div>
                <div class="field" style="margin:0;flex:1 1 160px">
                  <label>كلمة سر جديدة (اختياري)</label>
                  <input type="password" name="password" autocomplete="new-password" minlength="8" placeholder="اتركها فاضية لعدم التغيير">
                </div>
                <div class="field" style="margin:0;flex:1 1 140px"><label>الدور</label><select name="role">
                  <?php foreach (($roleLabels ?? []) as $rk => $rl): ?><option value="<?= e($rk) ?>" <?= ($u['role']??'read_only')===$rk?'selected':'' ?>><?= e($rl) ?></option><?php endforeach; ?>
                </select></div>
                <label style="display:flex;align-items:center;gap:7px;padding:9px;border:1px solid var(--line);border-radius:9px"><input type="checkbox" name="can_free_edit" value="1" <?= !empty($u['can_free_edit'])?'checked':'' ?>> صلاحية الدخول الحر</label>
                <div style="display:flex;gap:8px">
                  <button type="submit" class="btn gold sm"><?= icon('download', 14) ?> حفظ</button>
                  <button type="button" class="btn ghost sm" data-cancel-user="<?= $uid ?>">إلغاء</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="tab-panel" id="tab-maint">
  <div class="set-grid">
    <?php if ($canManageSecrets): ?>
    <div class="card" style="padding:22px">
      <div class="section-head"><?= icon('download', 17) ?> النسخ الاحتياطي</div>
      <form method="post" action="<?= url('backup') ?>" class="no-confirm">
        <?= csrfField() ?>
        <button class="btn" type="submit">⬇️ تنزيل نسخة احتياطية الآن</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card" style="padding:22px">
      <div class="section-head"><?= icon('file-text', 17) ?> توحيد المسميات المكررة</div>
      <p style="color:var(--grey);line-height:1.9;margin:0 0 12px">
        الزرار القديم كان بيدمج <b>من غير ما يوريك هيدمج إيه في إيه</b> وبيختار الاسم
        المعتمد لوحده. بقى فيه شاشة بتعرض كل مجموعة بعدد استخدام كل تهجئة
        <b>وانت اللي تختار</b>.
      </p>
      <a class="btn gold" href="<?= url('quality') ?>"><?= icon('clipboard', 14) ?> افتح شاشة تنظيف البيانات</a>
    </div>
  </div>
</div>

<script>
(function(){
  var btns = document.querySelectorAll('.settings-tabs .tab-btn');
  var panels = document.querySelectorAll('.tab-panel');
  function activate(tab){
    btns.forEach(function(b){ var on=b.dataset.tab===tab;b.classList.toggle('active',on);b.setAttribute('aria-selected',on?'true':'false');b.tabIndex=on?0:-1; });
    panels.forEach(function(p){ var on=p.id==='tab-'+tab;p.classList.toggle('active',on);p.hidden=!on; });
  }
  btns.forEach(function(b){ b.addEventListener('click', function(){ activate(b.dataset.tab); history.replaceState(null,'','#tab-'+b.dataset.tab); }); });
  // افتح التبويب من الهاش (مثلاً بعد توحيد المسميات → #tab-maint)
  var h = (location.hash||'').replace('#tab-','');
  if (h) activate(h);

  // إظهار/إخفاء فورم تعديل المستخدم داخل الجدول
  function toggleUserEdit(id, show){
    var row = document.getElementById('user-edit-'+id);
    if (row) row.style.display = show ? 'table-row' : 'none';
  }
  document.querySelectorAll('[data-edit-user]').forEach(function(b){
    b.addEventListener('click', function(){ toggleUserEdit(b.dataset.editUser, true); });
  });
  document.querySelectorAll('[data-cancel-user]').forEach(function(b){
    b.addEventListener('click', function(){ toggleUserEdit(b.dataset.cancelUser, false); });
  });
})();
</script>
