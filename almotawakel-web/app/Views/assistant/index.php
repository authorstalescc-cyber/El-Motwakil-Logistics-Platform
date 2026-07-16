<?php use App\Core\Auth; ?>
<div class="ai-wrap">

<?php if (empty($enabled)): ?>
  <div class="card">
    <div class="empty">
      <div class="ic"><?= icon('sparkles', 44) ?></div>
      <h3 style="margin:6px 0">المساعد الذكي غير مُفعّل</h3>
      <p>عشان تشغّله، ضيف مفتاح Groq في ملف الإعدادات <code>config/config.php</code> تحت القسم <code>groq</code> وخلّي <code>enabled</code> بـ <code>true</code>.</p>
    </div>
  </div>
<?php else: ?>

  <div class="card ai-card">
    <div class="ai-head">
      <div class="ai-head-ic"><?= icon('sparkles', 22) ?></div>
      <div class="ai-head-txt">
        <div class="ai-head-title">المساعد الذكي</div>
        <div class="ai-head-sub">اسأل بالعامية عن أي حاجة في بيانات الشركة — الدين، العهدة، الأذونات، عميل معيّن…</div>
      </div>
    </div>

    <div class="ai-log" id="ai-log">
      <div class="ai-welcome" id="ai-welcome">
        <div class="ai-welcome-ic"><?= icon('sparkles', 34) ?></div>
        <p class="ai-welcome-t">أهلاً! أنا مساعدك. اسألني وأنا أجاوبك من بيانات البرنامج.</p>
        <div class="ai-suggest" id="ai-suggest">
          <button type="button" class="ai-chip" data-q="احنا علينا كام إجمالاً للعملاء دلوقتي؟">احنا علينا كام إجمالاً؟</button>
          <button type="button" class="ai-chip" data-q="مين أكتر ٥ عملاء علينا لهم فلوس؟">أكتر ٥ عملاء علينا لهم</button>
          <button type="button" class="ai-chip" data-q="رصيد محفظة العهدة كام دلوقتي؟">رصيد العهدة كام؟</button>
          <button type="button" class="ai-chip" data-q="كام إذن مفتوح وكام مقفول؟">الأذونات المفتوحة والمقفولة</button>
          <button type="button" class="ai-chip" data-q="إجمالي المصروفات كام؟">إجمالي المصروفات</button>
        </div>
      </div>
    </div>

    <form class="ai-composer no-confirm" id="ai-form" autocomplete="off">
      <textarea id="ai-input" class="ai-input" rows="1" placeholder="اكتب سؤالك هنا… (Enter للإرسال)" aria-label="سؤالك"></textarea>
      <button type="submit" class="btn ai-send" id="ai-send" aria-label="إرسال"><?= icon('send', 18) ?></button>
    </form>
    <div class="ai-foot">
      <span><?= icon('alert', 13) ?> المساعد ممكن يغلط أحياناً — راجع الأرقام المهمة من صفحتها. لا تُتخذ قرارات نهائية إلا بعد المراجعة.</span>
    </div>
  </div>

  <script>
    window.__AI_ASK  = <?= json_encode(url('assistant/ask'), JSON_UNESCAPED_SLASHES) ?>;
    window.__AI_CSRF = <?= json_encode(Auth::csrf()) ?>;
  </script>
  <script>
  (function () {
    var form = document.getElementById('ai-form');
    if (!form) return;
    var input   = document.getElementById('ai-input');
    var log     = document.getElementById('ai-log');
    var welcome = document.getElementById('ai-welcome');
    var sendBtn = document.getElementById('ai-send');
    var suggest = document.getElementById('ai-suggest');

    var messages = [];   // {role, content} — يُرسَل كسياق للأسئلة المترابطة
    var busy = false;

    function esc(s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    // تنسيق بسيط: **عريض** + أسطر جديدة
    function fmt(s) {
      var h = esc(s);
      h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      h = h.replace(/\n/g, '<br>');
      return h;
    }

    function scroll() { log.scrollTop = log.scrollHeight; }

    function addMsg(role, htmlContent, sources) {
      if (welcome) { welcome.remove(); welcome = null; }
      var row = document.createElement('div');
      row.className = 'ai-msg ai-' + role;

      var av = document.createElement('div');
      av.className = 'ai-av';
      av.innerHTML = role === 'user'
        ? '<?= e(mb_substr(Auth::user() ?? "م", 0, 1)) ?>'
        : '<?= icon('sparkles', 16) ?>';

      var bubble = document.createElement('div');
      bubble.className = 'ai-bubble';
      bubble.innerHTML = htmlContent;

      if (sources && sources.length) {
        var box = document.createElement('div');
        box.className = 'ai-sources';
        box.innerHTML = '<div class="ai-sources-h">مصادر مرتبطة:</div>';
        sources.slice(0, 8).forEach(function (r) {
          var a = document.createElement('a');
          a.className = 'ai-src';
          a.href = r.url;
          a.innerHTML = '<span class="ai-src-lbl">' + esc(r.label) + '</span>' +
                        '<span class="ai-src-grp">' + esc(r.group) + '</span>';
          box.appendChild(a);
        });
        bubble.appendChild(box);
      }

      row.appendChild(av);
      row.appendChild(bubble);
      log.appendChild(row);
      scroll();
      return bubble;
    }

    function typingBubble() {
      var bubble = addMsg('bot', '<span class="ai-dots"><i></i><i></i><i></i></span>', null);
      bubble.parentNode.classList.add('ai-typing');
      return bubble;
    }

    function setBusy(b) {
      busy = b;
      sendBtn.disabled = b;
      sendBtn.classList.toggle('js-busy', b);
    }

    function ask(q) {
      q = (q || '').trim();
      if (!q || busy) return;
      if (suggest) suggest.style.display = 'none';

      addMsg('user', fmt(q), null);
      var history = messages.slice(-6);
      messages.push({ role: 'user', content: q });

      input.value = '';
      autoGrow();
      setBusy(true);
      var typing = typingBubble();

      var fd = new FormData();
      fd.append('_csrf', window.__AI_CSRF);
      fd.append('q', q);
      fd.append('history', JSON.stringify(history));

      fetch(window.__AI_ASK, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          typing.parentNode.remove();
          if (data && data.ok) {
            addMsg('bot', fmt(data.answer), data.sources || []);
            messages.push({ role: 'assistant', content: data.answer });
          } else {
            var m = (data && data.error) ? data.error : 'حصل خطأ غير متوقع، حاول تاني.';
            addMsg('bot', '<span class="ai-err">' + esc(m) + '</span>', null);
          }
        })
        .catch(function () {
          typing.parentNode.remove();
          addMsg('bot', '<span class="ai-err">تعذّر الاتصال. اتأكد من الإنترنت وحاول تاني.</span>', null);
        })
        .finally(function () { setBusy(false); input.focus(); });
    }

    function autoGrow() {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    input.addEventListener('input', autoGrow);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input.value); }
    });

    document.addEventListener('click', function (e) {
      var chip = e.target.closest('.ai-chip');
      if (chip) { e.preventDefault(); ask(chip.dataset.q || chip.textContent); }
    });

    input.focus();
  })();
  </script>

  <style>
    .ai-wrap { max-width: 900px; margin: 0 auto; }
    .ai-card { display: flex; flex-direction: column; height: calc(100vh - 150px); min-height: 460px; }
    .ai-head { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--line); background: linear-gradient(180deg,#fff, var(--bg)); }
    .ai-head-ic { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; color: var(--gold); background: var(--navy); flex: none; }
    .ai-head-title { font-weight: 800; color: var(--navy); font-size: 1.05rem; }
    .ai-head-sub { color: var(--grey); font-size: .82rem; margin-top: 2px; }

    .ai-log { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 16px; background: var(--bg); }

    .ai-welcome { margin: auto; text-align: center; color: var(--grey); max-width: 560px; }
    .ai-welcome-ic { color: var(--gold); opacity: .8; margin-bottom: 6px; }
    .ai-welcome-t { font-weight: 600; color: var(--ink); margin-bottom: 16px; }
    .ai-suggest { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
    .ai-chip { background: #fff; border: 1px solid var(--line2); color: var(--navy); border-radius: 20px;
               padding: 7px 14px; font-size: .84rem; font-weight: 600; cursor: pointer; transition: .15s; font-family: inherit; }
    .ai-chip:hover { background: var(--navy); color: #fff; border-color: var(--navy); }

    .ai-msg { display: flex; gap: 10px; align-items: flex-start; max-width: 88%; }
    .ai-msg.ai-user { flex-direction: row-reverse; align-self: flex-start; margin-inline-start: auto; }
    .ai-msg.ai-bot  { align-self: flex-start; }
    .ai-av { width: 30px; height: 30px; border-radius: 9px; flex: none; display: grid; place-items: center; font-weight: 800; font-size: .85rem; }
    .ai-bot .ai-av  { background: var(--navy); color: var(--gold); }
    .ai-user .ai-av { background: var(--gold-light); color: var(--navy); }
    .ai-bubble { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 11px 15px;
                 line-height: 1.75; color: var(--ink); box-shadow: var(--shadow); font-size: .95rem; }
    .ai-user .ai-bubble { background: var(--navy); color: #fff; border-color: var(--navy); }
    .ai-user .ai-bubble strong { color: var(--gold-light); }
    .ai-err { color: var(--red); font-weight: 600; }

    .ai-sources { margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--line2); }
    .ai-sources-h { font-size: .74rem; color: var(--grey); margin-bottom: 6px; font-weight: 700; }
    .ai-src { display: inline-flex; flex-direction: column; gap: 1px; background: var(--bg); border: 1px solid var(--line2);
              border-radius: 9px; padding: 5px 10px; margin: 0 0 6px 6px; text-decoration: none; max-width: 230px; }
    .ai-src:hover { border-color: var(--navy); background: #fff; }
    .ai-src-lbl { color: var(--navy); font-weight: 700; font-size: .8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ai-src-grp { color: var(--grey); font-size: .68rem; }

    .ai-dots { display: inline-flex; gap: 4px; padding: 3px 0; }
    .ai-dots i { width: 7px; height: 7px; border-radius: 50%; background: var(--grey); opacity: .5; animation: ai-blink 1.2s infinite; }
    .ai-dots i:nth-child(2) { animation-delay: .2s; } .ai-dots i:nth-child(3) { animation-delay: .4s; }
    @keyframes ai-blink { 0%,60%,100% { opacity: .25; transform: translateY(0); } 30% { opacity: .9; transform: translateY(-3px); } }

    .ai-composer { display: flex; gap: 10px; padding: 14px 16px 8px; border-top: 1px solid var(--line); background: #fff; align-items: flex-end; }
    .ai-input { flex: 1; resize: none; border: 1.5px solid var(--line2); border-radius: 12px; padding: 11px 14px;
                font-family: inherit; font-size: .95rem; line-height: 1.5; background: var(--input); color: var(--ink); max-height: 160px; }
    .ai-input:focus { outline: none; border-color: var(--navy); background: #fff; }
    .ai-send { flex: none; width: 44px; height: 44px; padding: 0; border-radius: 12px; display: grid; place-items: center; }
    .ai-foot { padding: 2px 18px 12px; color: var(--grey); font-size: .74rem; display: flex; align-items: center; gap: 5px; background: #fff; border-radius: 0 0 var(--radius) var(--radius); }
    .ai-foot .ico { flex: none; color: var(--gold); }

    @media (max-width: 640px) {
      .ai-card { height: calc(100vh - 120px); }
      .ai-msg { max-width: 96%; }
    }
  </style>

<?php endif; ?>
</div>
