<?php
/**
 * الإدخال السريع الجماعي — إيصالات ومصاريف على أذونات متعددة في دفعة واحدة.
 *
 * ⚠️ **اتنقل من الداشبورد لهنا (2026-08-17) بقرار المالك.** كان قاعد في لوحة
 *    التحكم وهو **أداة موظف إدخال** مش شاشة إدارة: ٢٠٠ سطر JS + شاشتَي اختيار
 *    كانوا بيتحمّلوا مع كل فتحة للداشبورد لكل مستخدم، والمالكين (أيمن وأحمد)
 *    قالوا «معلومات كتير ومبعثرة». الوظيفة **ما اتغيّرتش ولا سطر** — المكان بس.
 *
 * ⚠️ المسارات بقت `/permits/quick-options` و`/permits/quick-entries`، واللي
 *    بيرجع بعد الحفظ بيرجع لشاشة الأذونات لا للداشبورد.
 *
 * متغيّرات: $quickToken $quickOld $quickApplied $quickPermits $quickCards
 *          $quickReceipts $quickExpenses
 */

/* ⚠️⚠️ **يوم العمل مش `$date` بتاع الشاشة.** في الداشبورد كان `$date` هو يوم
   العمل، لكن في شاشة الأذونات `$date` هو **فلتر تاريخ الإذن** في وضع
   السجلّات — فلو الأداة قرت منه كانت هتحطّ تاريخ الفلتر تاريخًا للبند
   **بالسكوت** (والبند بيتسجّل بيوم صرفه — قاعدة v29). ومفيش `$date` أصلاً
   في وضع المتابعة فكان بيدّي `Undefined variable`. */
$qeDay = work_date();
?>
<!-- ═══════════════ الإدخال السريع الجماعي ═══════════════ -->
<style>
.qe-restore{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;
  background:var(--gold-light);border:1px solid var(--gold-line);color:var(--gold-fg);border-radius:var(--radius-2);padding:10px 14px;margin-bottom:12px;font-size:var(--fs-3)}
.qe-restore b{color:var(--gold-fg)}
.qe-restore-btns{display:flex;gap:8px;flex-shrink:0}
</style>
<div class="qe-modal" id="quickEntryModal" hidden aria-hidden="true">
  <div class="qe-back" data-qe-close></div>
  <div class="qe-panel" role="dialog" aria-modal="true" aria-labelledby="qeTitle">
    <div class="qe-head">
      <div><span class="qe-kicker">إدخال جماعي آمن</span><h2 id="qeTitle">الإيصالات والمصاريف</h2></div>
      <div class="qe-steps"><span class="active" data-qe-dot="edit">1 · الإدخال</span><i></i><span data-qe-dot="review">2 · المراجعة</span></div>
      <button type="button" class="qe-x" data-qe-close aria-label="إغلاق"><?= icon('x', 14) ?></button>
    </div>
    <aside class="qe-permit-browser" id="qePermitBrowser" hidden aria-label="اختيار الإذن">
      <button type="button" class="qe-browser-back" data-qe-browser-close aria-label="إغلاق"></button>
      <div class="qe-browser-sheet">
        <div class="qe-browser-head">
          <div><span>اختيار الإذن</span><b>ميّزه بالشركة والحاويات</b></div>
          <button type="button" data-qe-browser-close aria-label="إغلاق"><?= icon('x', 14) ?></button>
        </div>
        <label class="qe-browser-search"><span aria-hidden="true">⌕</span><input type="search" id="qePermitSearch" placeholder="بحث" autocomplete="off"></label>
        <div class="qe-permit-results" id="qePermitResults"></div>
        <div class="qe-browser-empty" id="qePermitEmpty" hidden>لا يوجد إذن مطابق للبحث.</div>
      </div>
    </aside>
    <aside class="qe-permit-browser qe-movement-browser" id="qeMovementBrowser" hidden aria-label="اختيار الحركة">
      <button type="button" class="qe-browser-back" data-qe-movement-close aria-label="إغلاق"></button>
      <div class="qe-browser-sheet">
        <div class="qe-browser-head">
          <div><span>اختيار الحركة</span><b id="qeMovementPermitTitle">حركات الإذن</b></div>
          <button type="button" data-qe-movement-close aria-label="إغلاق"><?= icon('x', 14) ?></button>
        </div>
        <div class="qe-movement-guide">اختر الحركة من بطاقتها. إذا كانت حركة واحدة فقط يختارها السيستم تلقائيًا.</div>
        <div class="qe-permit-results qe-movement-results" id="qeMovementResults"></div>
      </div>
    </aside>
    <form method="post" action="<?= url('permits/quick-entries') ?>" id="quickEntryForm" class="no-confirm">
      <?= csrfField() ?><input type="hidden" name="quick_token" value="<?= e($quickToken) ?>">
      <section class="qe-edit" data-qe-step="edit">
        <div class="qe-guide">
          <div><b>أدخل كل البنود أولًا</b><span>الصف الجديد ينسخ الإذن والحركة والتاريخ من الصف السابق تلقائيًا.</span></div>
          <div><b>راجع قبل التطبيق</b><span>لن يُحفظ أي شيء إلا بعد شاشة المراجعة، وأي خطأ يلغي الدفعة كلها.</span></div>
        </div>
        <div class="qe-rows" id="quickEntryRows"></div>
        <div class="qe-edit-foot">
          <button type="button" class="btn ghost" id="quickEntryAdd"><?= icon('plus',15) ?> إضافة صف آخر</button>
          <span class="qe-live-summary" id="quickLiveSummary">0 بند</span>
          <button type="button" class="btn gold" id="quickEntryReview">مراجعة الإدخالات <?= icon('chevron',15) ?></button>
        </div>
      </section>
      <section class="qe-review" data-qe-step="review" hidden>
        <div class="qe-review-summary">
          <div><span>عدد البنود</span><b id="qeReviewCount">0</b></div>
          <div class="receipt"><span>إجمالي الإيصالات</span><b id="qeReceiptTotal">0</b></div>
          <div class="expense"><span>إجمالي المصاريف</span><b id="qeExpenseTotal">0</b></div>
        </div>
        <div class="table-wrap"><table class="data qe-review-table">
          <thead><tr><th>#</th><th>الإذن والحركة</th><th>النوع</th><th>البيان</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th><th>الفعلي</th><th>التاريخ</th><th>الدفع</th></tr></thead>
          <tbody id="qeReviewBody"></tbody>
        </table></div>
        <div class="qe-apply-note"><?= icon('check-circle',17) ?><span><b>سيتم تطبيق كل الصفوف معًا.</b> إذا تعذّر بند واحد فلن يُحفظ أي بند.</span></div>
        <div class="qe-review-foot">
          <button type="button" class="btn ghost" id="quickEntryBack">رجوع للتعديل</button>
          <button type="submit" class="btn gold" id="quickEntryApply"><?= icon('check',16) ?> تطبيق داخل الأذونات</button>
        </div>
      </section>
    </form>
  </div>
</div>

<datalist id="qeReceiptNames"><?php foreach($quickReceipts as $name): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>
<datalist id="qeExpenseNames"><?php foreach($quickExpenses as $name): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>

<?php /* Chart.js (200KB) اتشال من الداشبورد مع الرسوم — بيتحمّل في /reports بس */ ?>
<script>
/* ── الإدخال السريع الجماعي ── */
(function(){
  const modal=document.getElementById('quickEntryModal'), form=document.getElementById('quickEntryForm'), rowsEl=document.getElementById('quickEntryRows');
  if(!modal||!form||!rowsEl)return;
  const browser=document.getElementById('qePermitBrowser'),permitSearch=document.getElementById('qePermitSearch'),permitResults=document.getElementById('qePermitResults'),permitEmpty=document.getElementById('qePermitEmpty');
  const movementBrowser=document.getElementById('qeMovementBrowser'),movementResults=document.getElementById('qeMovementResults'),movementTitle=document.getElementById('qeMovementPermitTitle');
  let permits=<?= js_json($quickPermits) ?>;
  let cards=<?= js_json(array_map(fn($c)=>['id'=>(int)$c['id'],'label'=>$c['name'].($c['owner']?' — '.$c['owner']:'')], $quickCards)) ?>;
  const old=<?= js_json($quickOld) ?>;
  let permitMap=new Map(permits.map(p=>[String(p.id),p]));
  let permitsLoaded=permits.length>0, permitsPromise=null;
  function fillDatalist(id,values){const list=document.getElementById(id);if(list)list.innerHTML=values.map(value=>`<option value="${esc(value)}"></option>`).join('');}
  function refreshCardOptions(){[...rowsEl.querySelectorAll('[name="visa_card_id[]"]')].forEach(select=>{const selected=select.value;select.innerHTML=cardOptions(selected);select.value=selected;});}
  async function loadPermits(){
    if(permitsLoaded)return permits;
    if(permitsPromise)return permitsPromise;
    permitsPromise=fetch(<?= js_json(url('permits/quick-options')) ?>,{headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},credentials:'same-origin'})
      .then(async response=>{if(!response.ok)throw new Error('HTTP '+response.status);const data=await response.json();if(!data.ok||!Array.isArray(data.permits)||!Array.isArray(data.cards)||!Array.isArray(data.receipts)||!Array.isArray(data.expenses))throw new Error('invalid payload');
        permits=data.permits;cards=data.cards;permitMap=new Map(permits.map(p=>[String(p.id),p]));permitsLoaded=true;
        fillDatalist('qeReceiptNames',data.receipts);fillDatalist('qeExpenseNames',data.expenses);refreshCardOptions();
        [...rowsEl.children].forEach(row=>{paintPermitButton(row);paintMovementButton(row);});return permits;})
      .catch(error=>{permitsPromise=null;throw error;});
    return permitsPromise;
  }
  /* ── حفظ تلقائي للمسودة (يمنع ضياع البيانات لو انتهت الجلسة أو اتقفلت الصفحة) ── */
  const DRAFT_KEY='almotawakel-quick-entry-draft';
  const quickApplied=<?= !empty($quickApplied)?'true':'false' ?>;
  let autosaveOn=false,pendingRestore=false,restoreOffered=false,draftTimer=null,restoreBanner=null;
  const draftMeaningful=rows=>Array.isArray(rows)&&rows.some(r=>r&&(r.permit_id||(r.note&&String(r.note).trim())||(+r.amount>0)));
  function loadDraft(){try{const d=JSON.parse(localStorage.getItem(DRAFT_KEY)||'null');return d&&draftMeaningful(d.rows)?d:null;}catch(e){return null;}}
  function clearDraft(){try{localStorage.removeItem(DRAFT_KEY);}catch(e){}}
  function writeDraft(){const rows=[...rowsEl.children].map(rowData);if(!draftMeaningful(rows)){clearDraft();return;}try{localStorage.setItem(DRAFT_KEY,JSON.stringify({t:Date.now(),rows}));}catch(e){}}
  function scheduleDraftSave(){if(!autosaveOn||pendingRestore)return;clearTimeout(draftTimer);draftTimer=setTimeout(writeDraft,500);}
  function draftTimeLabel(t){try{return new Date(t).toLocaleString('ar-EG',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});}catch(e){return '';}}
  function removeRestore(){pendingRestore=false;if(restoreBanner){restoreBanner.remove();restoreBanner=null;}}
  function doRestore(draft){rowsEl.innerHTML='';(draft.rows||[]).forEach(d=>addRow(d));if(!rowsEl.children.length)addRow({entry_date:<?= js_json($qeDay) ?>,qty:1});removeRestore();updateLive();}
  function offerRestore(){
    if(restoreOffered)return;restoreOffered=true;
    if(oldCount)return;const draft=loadDraft();if(!draft)return;
    pendingRestore=true;
    const b=document.createElement('div');b.className='qe-restore';
    b.innerHTML=`<span>✏ <b>لقينا بيانات لم تُطبَّق</b> من آخر مرة (${draft.rows.length} صف · ${draftTimeLabel(draft.t)}). عايز ترجّعها؟</span>`+
      `<span class="qe-restore-btns"><button type="button" class="btn gold sm" data-qe-restore>استرجاع البيانات</button><button type="button" class="btn ghost sm" data-qe-fresh>ابدأ من جديد</button></span>`;
    rowsEl.parentNode.insertBefore(b,rowsEl);restoreBanner=b;
    b.querySelector('[data-qe-restore]').addEventListener('click',()=>doRestore(draft));
    b.querySelector('[data-qe-fresh]').addEventListener('click',()=>{clearDraft();removeRestore();});
  }
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const money=v=>new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(+v||0);
  const val=(name,i,def='')=>Array.isArray(old[name])?(old[name][i]??def):def;
  const oldCount=Array.isArray(old.permit_id)?old.permit_id.length:0;
  let dirty=false,activePermitRow=null,activeMovementRow=null;

  const norm=v=>String(v??'').toLocaleLowerCase('ar').normalize('NFKD').replace(/[\u064b-\u065f\u0670]/g,'').replace(/[\s\-_/]/g,'');
  function containerChips(p){return String(p?.container_label||'بدون حاويات مسجلة').split('،').map(x=>`<span>${esc(x.trim())}</span>`).join('');}
  function permitButtonHtml(p){return p
    ? `<span class="qe-pick-top"><b>${esc(p.type)} ${esc(p.no)}</b><em>${p.status==='closed'?'مغلق':'جارٍ'}</em></span><span class="qe-pick-company">الشركة: <strong>${esc(p.company)}</strong></span><span class="qe-pick-containers">${containerChips(p)}</span>`
    : `<span class="qe-pick-placeholder"><b>اختر الإذن / الشهادة</b></span>`;}
  function paintPermitButton(row){const input=row.querySelector('[name="permit_id[]"]'),p=permitMap.get(String(input.value)),btn=row.querySelector('.qe-permit-pick');btn.innerHTML=permitButtonHtml(p);btn.classList.toggle('selected',!!p);btn.classList.remove('invalid');}
  function movementDetail(m){const parts=[];if(+m?.container_count>0)parts.push(`${m.container_count} حاوية`);if(m?.container_type)parts.push(m.container_type);return parts;}
  function movementButtonHtml(p,m){return m
    ? `<span class="qe-pick-top"><b>الحركة ${m.seq}</b>${p?.movements?.length===1?'<em>اختيار تلقائي</em>':''}</span><span class="qe-movement-chips">${movementDetail(m).map(x=>`<span>${esc(x)}</span>`).join('')||'<span>بدون حاويات محددة</span>'}</span>${m.note?`<small>${esc(m.note)}</small>`:''}`
    : `<span class="qe-pick-placeholder"><b>اختر الحركة</b></span>`;}
  function paintMovementButton(row){const p=permitMap.get(String(row.querySelector('[name="permit_id[]"]').value)),input=row.querySelector('[name="movement_id[]"]'),m=p?.movements?.find(x=>String(x.id)===String(input.value)),btn=row.querySelector('.qe-movement-pick');btn.innerHTML=movementButtonHtml(p,m);btn.classList.toggle('selected',!!m);btn.classList.remove('invalid');}
  function cardOptions(selected){return '<option value="">اختر الكارت…</option>'+cards.map(c=>`<option value="${c.id}" ${String(c.id)===String(selected)?'selected':''}>${esc(c.label)}</option>`).join('');}
  function rowData(row){const q=s=>row.querySelector(s);return {
    permit_id:q('[name="permit_id[]"]').value,movement_id:q('[name="movement_id[]"]').value,kind:q('[name="kind[]"]').value,
    note:q('[name="note[]"]').value.trim(),qty:q('[name="qty[]"]').value,amount:q('[name="amount[]"]').value,actual_amount:q('[name="actual_amount[]"]').value,
    entry_date:q('[name="entry_date[]"]').value,ref_no:q('[name="ref_no[]"]').value.trim(),
    payment_method:q('[name="payment_method[]"]').value,visa_card_id:q('[name="visa_card_id[]"]').value};}
  function addRow(data={}){
    const idx=rowsEl.children.length+1,kind=data.kind||'receipt',payment=data.payment_method||'cash';
    const row=document.createElement('article');row.className='qe-row';
    row.innerHTML=`<div class="qe-row-no"><b>${idx}</b><span>بند</span></div><div class="qe-row-grid">
      <label class="wide qe-permit-wrap"><span>الإذن / الشهادة</span><input type="hidden" name="permit_id[]" value="${esc(data.permit_id||'')}"><button type="button" class="qe-permit-pick">${permitButtonHtml(permitMap.get(String(data.permit_id||'')))}</button></label>
      <label class="qe-movement-wrap"><span>الحركة</span><input type="hidden" name="movement_id[]" value="${esc(data.movement_id||'')}"><button type="button" class="qe-movement-pick">${movementButtonHtml(permitMap.get(String(data.permit_id||'')),permitMap.get(String(data.permit_id||''))?.movements?.find(x=>String(x.id)===String(data.movement_id||'')))}</button></label>
      <div class="qe-kind-field"><span>نوع البند</span><input type="hidden" name="kind[]" class="qe-kind" value="${esc(kind)}"><div class="qe-kind-switch"><button type="button" data-kind="receipt" class="${kind==='receipt'?'active':''}">إيصال</button><button type="button" data-kind="expense" class="${kind==='expense'?'active':''}">مصروف</button></div></div>
      <label class="wide"><span>البيان</span><input name="note[]" class="qe-note" list="${kind==='receipt'?'qeReceiptNames':'qeExpenseNames'}" value="${esc(data.note||'')}" placeholder="${kind==='receipt'?'تخصيص / حمولة / هيئة…':'أرضية / غرامة / عمولة…'}" maxlength="255" required></label>
      <label class="qe-qty"><span>الكمية</span><input type="number" name="qty[]" value="${esc(data.qty||1)}" min="1" max="10000" step="1" required></label>
      <label><span>سعر الوحدة</span><input data-money type="number" name="amount[]" value="${esc(data.amount||'')}" min="0.01" step="0.01" placeholder="0" required></label>
      <label class="qe-actual" ${kind==='expense'?'hidden':''}><span>الفعلي للوحدة <small>اختياري</small></span><input data-money type="number" name="actual_amount[]" value="${kind==='expense'?'':esc(data.actual_amount||'')}" min="0" step="0.01" placeholder="—"></label>
      <label><span>التاريخ</span><input type="date" name="entry_date[]" value="${esc(data.entry_date||<?= js_json($qeDay) ?>)}" required></label>
      <label><span>رقم مرجعي <small>اختياري</small></span><input name="ref_no[]" value="${esc(data.ref_no||'')}" maxlength="120" placeholder="رقم الإيصال"></label>
      <label><span>طريقة الدفع</span><select name="payment_method[]" class="qe-payment"><option value="cash" ${payment==='cash'?'selected':''}>نقدي</option><option value="visa" ${payment==='visa'?'selected':''}>فيزا</option></select></label>
      <label class="qe-card" ${payment==='visa'?'':'hidden'}><span>كارت الفيزا</span><select name="visa_card_id[]">${cardOptions(data.visa_card_id||'')}</select></label>
    </div><button type="button" class="qe-remove" title="حذف الصف">✕</button>`;
    rowsEl.appendChild(row);bindRow(row);renumber();updateLive();return row;
  }
  function bindRow(row){
    const permit=row.querySelector('[name="permit_id[]"]'),movement=row.querySelector('[name="movement_id[]"]'),kind=row.querySelector('.qe-kind'),payment=row.querySelector('.qe-payment');
    row.querySelector('.qe-permit-pick').addEventListener('click',()=>openPermitBrowser(row));
    row.querySelector('.qe-movement-pick').addEventListener('click',()=>openMovementBrowser(row));
    row.querySelectorAll('[data-kind]').forEach(btn=>btn.addEventListener('click',()=>setKind(row,btn.dataset.kind)));
    payment.addEventListener('change',()=>{const wrap=row.querySelector('.qe-card');wrap.hidden=payment.value!=='visa';if(payment.value!=='visa')wrap.querySelector('select').value='';});
    row.querySelector('.qe-remove').addEventListener('click',()=>{if(rowsEl.children.length===1){row.querySelectorAll('input').forEach(i=>{if(i.type!=='date')i.value='';});row.querySelector('[name="qty[]"]').value='1';paintPermitButton(row);paintMovementButton(row);updateLive();return;}row.remove();renumber();updateLive();dirty=true;});
    row.addEventListener('input',()=>{dirty=true;updateLive();});row.addEventListener('change',()=>{dirty=true;updateLive();});
    const p=permitMap.get(String(permit.value));if(p?.movements?.length===1&&!movement.value)movement.value=String(p.movements[0].id);paintMovementButton(row);
  }
  function setKind(row,value){const kind=row.querySelector('.qe-kind'),receipt=value==='receipt',note=row.querySelector('.qe-note'),actual=row.querySelector('.qe-actual');kind.value=receipt?'receipt':'expense';row.querySelectorAll('[data-kind]').forEach(btn=>btn.classList.toggle('active',btn.dataset.kind===kind.value));note.setAttribute('list',receipt?'qeReceiptNames':'qeExpenseNames');note.placeholder=receipt?'تخصيص / حمولة / هيئة…':'أرضية / غرامة / عمولة…';actual.hidden=!receipt;if(!receipt)actual.querySelector('input').value='';dirty=true;updateLive();}
  function renderPermitResults(){const q=norm(permitSearch.value),matches=permits.filter(p=>!q||norm(p.search).includes(q));
    permitResults.innerHTML=matches.slice(0,120).map(p=>`<button type="button" class="qe-permit-option ${activePermitRow?.querySelector('[name="permit_id[]"]')?.value===String(p.id)?'active':''}" data-permit-id="${p.id}">
      <span class="qe-option-top"><i>${esc(p.type)}</i><b>${esc(p.no)}</b><em class="${p.status==='closed'?'closed':''}">${p.status==='closed'?'مغلق':'جارٍ'}</em></span>
      <span class="qe-option-company">الشركة <strong>${esc(p.company)}</strong></span>
      <span class="qe-option-meta"><small>العميل: ${esc(p.client)}</small>${p.date?`<small>التاريخ: ${esc(p.date)}</small>`:''}</span>
      <span class="qe-option-containers"><u>الحاويات</u>${containerChips(p)}</span></button>`).join('');
    permitEmpty.hidden=matches.length>0;}
  async function openPermitBrowser(row){activePermitRow=row;permitSearch.value='';browser.hidden=false;permitSearch.disabled=true;permitEmpty.hidden=true;permitResults.innerHTML='<div class="qe-browser-empty">جارٍ تحميل الأذونات…</div>';
    try{await loadPermits();if(activePermitRow!==row)return;permitSearch.disabled=false;renderPermitResults();setTimeout(()=>permitSearch.focus(),30);}
    catch(error){permitResults.innerHTML='<div class="qe-browser-empty">تعذّر تحميل الأذونات. أغلق النافذة وحاول مرة أخرى.</div>';}}
  function closePermitBrowser(){browser.hidden=true;activePermitRow=null;}
  function selectPermit(pid){if(!activePermitRow)return;const row=activePermitRow,input=row.querySelector('[name="permit_id[]"]'),movement=row.querySelector('[name="movement_id[]"]'),p=permitMap.get(String(pid));if(!p)return;
    input.value=String(p.id);movement.value=p.movements?.length===1?String(p.movements[0].id):'';paintPermitButton(row);paintMovementButton(row);dirty=true;updateLive();closePermitBrowser();}
  function renderMovementResults(){const row=activeMovementRow,p=permitMap.get(String(row?.querySelector('[name="permit_id[]"]')?.value)),selected=row?.querySelector('[name="movement_id[]"]')?.value;movementTitle.textContent=p?`${p.type} ${p.no} — ${p.company}`:'حركات الإذن';
    movementResults.innerHTML=(p?.movements||[]).map(m=>`<button type="button" class="qe-permit-option qe-movement-option ${String(m.id)===String(selected)?'active':''}" data-movement-id="${m.id}"><span class="qe-option-top"><i>حركة</i><b>${m.seq}</b></span><span class="qe-option-containers"><u>الحاويات</u>${movementDetail(m).map(x=>`<span>${esc(x)}</span>`).join('')||'<span>غير محددة</span>'}</span>${m.note?`<span class="qe-movement-note">ملاحظة: <strong>${esc(m.note)}</strong></span>`:''}</button>`).join('');}
  function openMovementBrowser(row){const p=permitMap.get(String(row.querySelector('[name="permit_id[]"]').value));if(!p){openPermitBrowser(row);return;}if(p.movements?.length===1){row.querySelector('[name="movement_id[]"]').value=String(p.movements[0].id);paintMovementButton(row);return;}activeMovementRow=row;renderMovementResults();movementBrowser.hidden=false;setTimeout(()=>movementResults.querySelector('button')?.focus(),30);}
  function closeMovementBrowser(){movementBrowser.hidden=true;activeMovementRow=null;}
  function selectMovement(mid){if(!activeMovementRow)return;const row=activeMovementRow,p=permitMap.get(String(row.querySelector('[name="permit_id[]"]').value)),m=p?.movements?.find(x=>String(x.id)===String(mid));if(!m)return;row.querySelector('[name="movement_id[]"]').value=String(m.id);paintMovementButton(row);dirty=true;updateLive();closeMovementBrowser();}
  function renumber(){[...rowsEl.children].forEach((r,i)=>r.querySelector('.qe-row-no b').textContent=i+1);}
  function filledRows(){return [...rowsEl.children].map(rowData).filter(r=>r.permit_id||r.note||(+r.amount>0));}
  function updateLive(){const rs=filledRows(),total=r=>(+r.amount||0)*Math.max(1,+r.qty||1),rec=rs.filter(r=>r.kind==='receipt').reduce((s,r)=>s+total(r),0),exp=rs.filter(r=>r.kind==='expense').reduce((s,r)=>s+total(r),0);
    document.getElementById('quickLiveSummary').textContent=`${rs.length} بند · إيصالات ${money(rec)} · مصاريف ${money(exp)}`;scheduleDraftSave();}
  function validate(){const rows=[...rowsEl.children];for(const row of rows){const d=rowData(row);if(!d.permit_id&&!d.note&&!(+d.amount))continue;
      if(!d.permit_id){row.querySelector('.qe-permit-pick').classList.add('invalid');openPermitBrowser(row);return false;}
      if(!d.movement_id){row.querySelector('.qe-movement-pick').classList.add('invalid');openMovementBrowser(row);return false;}
      for(const el of row.querySelectorAll('[required]')){if(!el.checkValidity()){el.reportValidity();el.focus();return false;}}
      if(d.payment_method==='visa'&&!d.visa_card_id){const card=row.querySelector('[name="visa_card_id[]"]');card.setCustomValidity('اختر كارت الفيزا');card.reportValidity();card.setCustomValidity('');return false;}}
    if(!filledRows().length){window.AppUI.notice('أضف بندًا واحدًا على الأقل.','بيانات ناقصة');return false;}return true;}
  function review(){if(!validate())return;const rs=filledRows(),body=document.getElementById('qeReviewBody');let rec=0,exp=0;body.innerHTML='';
    rs.forEach((r,i)=>{const p=permitMap.get(String(r.permit_id)),m=p?.movements.find(x=>String(x.id)===String(r.movement_id)),qty=Math.max(1,+r.qty||1),lineTotal=qty*(+r.amount||0);if(r.kind==='receipt')rec+=lineTotal;else exp+=lineTotal;
      const tr=document.createElement('tr');tr.innerHTML=`<td>${i+1}</td><td><b>${esc(p?.no||'—')}</b><small>الشركة: ${esc(p?.company||'—')} · ${esc(p?.container_label||'')}</small><small>${esc(m?.label||'—')}</small></td><td><span class="qe-kind-pill ${r.kind}">${r.kind==='receipt'?'إيصال':'مصروف'}</span></td><td><b>${esc(r.note)}</b>${r.ref_no?`<small>#${esc(r.ref_no)}</small>`:''}</td><td><b>${qty}</b></td><td>${money(r.amount)}</td><td><b>${money(lineTotal)}</b></td><td>${r.actual_amount?money((+r.actual_amount)*qty):'—'}</td><td>${esc(r.entry_date)}</td><td>${r.payment_method==='visa'?'فيزا':'نقدي'}</td>`;body.appendChild(tr);});
    document.getElementById('qeReviewCount').textContent=rs.length;document.getElementById('qeReceiptTotal').textContent=money(rec);document.getElementById('qeExpenseTotal').textContent=money(exp);showStep('review');}
  function showStep(step){modal.querySelectorAll('[data-qe-step]').forEach(s=>s.hidden=s.dataset.qeStep!==step);modal.querySelectorAll('[data-qe-dot]').forEach(s=>s.classList.toggle('active',s.dataset.qeDot===step));}
  function open(){modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';showStep('edit');autosaveOn=true;offerRestore();void loadPermits();setTimeout(()=>rowsEl.querySelector('.qe-permit-pick')?.focus(),60);}
  function close(force){if(!movementBrowser.hidden){closeMovementBrowser();return;}if(!browser.hidden){closePermitBrowser();return;}if(force!==true&&dirty&&filledRows().length){window.AppUI.confirm('ستفقد الصفوف غير المطبقة. هل تريد الإغلاق؟',()=>close(true),{title:'إغلاق شاشة الإدخال',okText:'نعم، أغلق'});return;}modal.hidden=true;modal.setAttribute('aria-hidden','true');document.body.style.overflow='';}
  document.getElementById('quickEntryOpen').addEventListener('click',open);modal.querySelectorAll('[data-qe-close]').forEach(el=>el.addEventListener('click',close));
  document.getElementById('quickEntryAdd').addEventListener('click',()=>{const last=rowsEl.lastElementChild,d=last?rowData(last):{};d.note='';d.qty='1';d.amount='';d.actual_amount='';d.ref_no='';addRow(d).querySelector('.qe-note').focus();});
  document.getElementById('quickEntryReview').addEventListener('click',review);document.getElementById('quickEntryBack').addEventListener('click',()=>showStep('edit'));
  permitSearch.addEventListener('input',renderPermitResults);permitResults.addEventListener('click',e=>{const option=e.target.closest('[data-permit-id]');if(option)selectPermit(option.dataset.permitId);});browser.querySelectorAll('[data-qe-browser-close]').forEach(el=>el.addEventListener('click',closePermitBrowser));
  movementResults.addEventListener('click',e=>{const option=e.target.closest('[data-movement-id]');if(option)selectMovement(option.dataset.movementId);});movementBrowser.querySelectorAll('[data-qe-movement-close]').forEach(el=>el.addEventListener('click',closeMovementBrowser));
  form.addEventListener('submit',e=>{if(!validate()){e.preventDefault();showStep('edit');return;}const btn=document.getElementById('quickEntryApply');btn.disabled=true;btn.textContent='جارٍ التطبيق…';dirty=false;});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close();});
  // لو المستخدم بدأ يكتب والبانر ظاهر → يعتبره «ابدأ من جديد» فتُحفظ كتابته الجديدة
  rowsEl.addEventListener('input',()=>{if(pendingRestore){clearDraft();removeRestore();scheduleDraftSave();}});
  if(quickApplied)clearDraft();   // تأكيد نجاح الحفظ من السيرفر → امسح المسودة
  if(oldCount){for(let i=0;i<oldCount;i++)addRow({permit_id:val('permit_id',i),movement_id:val('movement_id',i),kind:val('kind',i,'receipt'),note:val('note',i),qty:val('qty',i,'1'),amount:val('amount',i),actual_amount:val('actual_amount',i),entry_date:val('entry_date',i,<?= js_json($qeDay) ?>),ref_no:val('ref_no',i),payment_method:val('payment_method',i,'cash'),visa_card_id:val('visa_card_id',i)});dirty=true;}else addRow({entry_date:<?= js_json($qeDay) ?>,qty:1});
  <?php if(!empty($_GET['quick'])): ?>open();<?php endif; ?>
})();
</script>
