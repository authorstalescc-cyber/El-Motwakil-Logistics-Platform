<script>
// تصغير تلقائي: يضمن أن كل ورقة إذن تُطبع في صفحة A4 واحدة.
// نقيس ارتفاع كل .sheet، ولو تعدّى ارتفاع صفحة A4 نُصغّرها (zoom) لتدخل كلها في صفحة واحدة.
(function () {
  var A4_H = 1122;   // ارتفاع صفحة A4 عند 96dpi (297مم) — الصفحة بلا هامش (@page margin:0)
  function sheets() { return Array.prototype.slice.call(document.querySelectorAll('.sheet')); }
  function fit() {
    sheets().forEach(function (s) {
      s.style.zoom = '';               // إعادة الضبط قبل القياس
      var h = s.scrollHeight;
      if (h > A4_H) { s.style.zoom = (A4_H / h).toFixed(4); }
    });
  }
  function reset() { sheets().forEach(function (s) { s.style.zoom = ''; }); }
  window.__fitPrint = function () { fit(); window.print(); };
  window.addEventListener('beforeprint', fit);
  window.addEventListener('afterprint', reset);
})();
</script>
