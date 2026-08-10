<?php
/**
 * المساعد الذكي — المفاتيح والموديل (docs/19 · المرحلة ٣).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * @var array $ai
 */
?>

  <div class="card set-pad narrow">
    <div class="section-head"><?= icon('cpu', 17) ?> مفاتيح المساعد الذكي (Groq AI)</div>
    <p class="st-note">المفتاح زي كلمة السر — بلاش تنشره لأي حد. تقدر تحفظ مفتاحين وتبدّل بينهم لو خلص رصيد واحد منهم. اضغط «اختبار» عشان تتأكد إن المفتاح شغّال قبل ما تعتمد عليه.</p>
    <form method="post" action="<?= url('settings/ai') ?>" id="ai-set-form" class="no-confirm">
      <?= csrfField() ?>
      <label class="st-key-top"><input type="checkbox" name="ai_enabled" value="1" <?= $ai['enabled']==='1'?'checked':'' ?>> تفعيل المساعد الذكي</label>

      <div class="field set-mt-narrow"><label>الموديل المستخدم</label>
        <select name="ai_model" id="ai-model">
          <?php foreach ($ai['models'] as $m): ?>
            <option value="<?= e($m) ?>" <?= $ai['model']===$m?'selected':'' ?>><?= e($m) ?></option>
          <?php endforeach; ?>
          <?php if ($ai['model']!=='' && !in_array($ai['model'],$ai['models'],true)): ?>
            <option value="<?= e($ai['model']) ?>" selected><?= e($ai['model']) ?> (مخصّص)</option>
          <?php endif; ?>
        </select>
        <small class="hint">llama-3.3-70b سريع وعربيته كويسة · allam-2-7b متخصص عربي</small>
      </div>

      <?php foreach ([
          ['n'=>'1','label'=>$ai['key1_label'],'val'=>$ai['key1'],'set'=>$ai['key1_set'],'hint'=>$ai['key1_hint']],
          ['n'=>'2','label'=>$ai['key2_label'],'val'=>$ai['key2'],'set'=>$ai['key2_set'],'hint'=>$ai['key2_hint']],
      ] as $k): ?>
        <div class="st-key">
          <div class="st-key-top">
            <label class=""><input type="radio" name="ai_active" value="<?= $k['n'] ?>" <?= $ai['active']===$k['n']?'checked':'' ?>> <b>المفتاح النشط</b></label>
            <input type="text" name="ai_key_<?= $k['n'] ?>_label" value="<?= e($k['label']) ?>" class="st-key-name" placeholder="اسم المفتاح (مثال: MO-<?= $k['n'] ?>)">
          </div>
          <div class="st-key-row">
            <input type="password" name="ai_key_<?= $k['n'] ?>" value="" placeholder="<?= $k['set'] ? e('محفوظ: '.$k['hint'].' — اتركه فارغًا للإبقاء عليه') : 'gsk_...' ?>" autocomplete="new-password" class="st-token" data-token="<?= $k['n'] ?>">
            <button type="button" class="btn ghost sm ai-eye" data-eye="<?= $k['n'] ?>" title="إظهار/إخفاء"><?= icon('search', 14) ?></button>
            <button type="button" class="btn ghost sm ai-test" data-test="<?= $k['n'] ?>">🔌 اختبار</button>
          </div>
          <?php if ($k['set']): ?><label class="hint"><input type="checkbox" name="clear_ai_key_<?= $k['n'] ?>" value="1"> حذف المفتاح المحفوظ عند الحفظ</label><?php endif; ?>
          <div class="st-res" data-res="<?= $k['n'] ?>"></div>
        </div>
      <?php endforeach; ?>

      <div class="mt-4"><button class="btn gold" type="submit"><?= icon('download', 14) ?> حفظ إعدادات المساعد</button></div>
    </form>
  </div>
<script>
(function(){
  var form = document.getElementById('ai-set-form');
  if(!form) return;
  var TEST_URL = <?= js_json(url('settings/ai/test')) ?>;
  var csrf = form.querySelector('[name=_csrf]').value;
  form.querySelectorAll('.ai-eye').forEach(function(btn){
    btn.addEventListener('click', function(){
      var inp = form.querySelector('.st-token[data-token="'+btn.dataset.eye+'"]');
      inp.type = inp.type === 'password' ? 'text' : 'password';
    });
  });
  form.querySelectorAll('.ai-test').forEach(function(btn){
    btn.addEventListener('click', function(){
      var n = btn.dataset.test;
      var key = form.querySelector('.st-token[data-token="'+n+'"]').value.trim();
      var model = form.querySelector('#ai-model').value;
      var res = form.querySelector('.st-res[data-res="'+n+'"]');
      res.className = 'st-res show muted'; res.textContent = 'جارٍ الاختبار…';
      btn.disabled = true;
      var fd = new FormData();
      fd.append('_csrf', csrf); fd.append('key', key); fd.append('slot', n); fd.append('model', model);
      fetch(TEST_URL, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){return r.json();})
        .then(function(d){
          if(d && d.ok){ res.className='st-res show ok'; res.textContent = d.message || 'شغّال ✓'; }
          else { res.className='st-res show err'; res.textContent = (d && d.error) ? d.error : 'فشل الاختبار'; }
        })
        .catch(function(){ res.className='st-res show err'; res.textContent='تعذّر الاتصال بالسيرفر'; })
        .finally(function(){ btn.disabled=false; });
    });
  });
})();
</script>
