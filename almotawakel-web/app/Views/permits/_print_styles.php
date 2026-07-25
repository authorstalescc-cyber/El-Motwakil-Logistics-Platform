<?php partial('print_base'); ?>
<style>
  /* ⚠️ override مقصود فوق `print_base`: ورقة المراجعة بخلفية أفتح
     وذهب أفتح شوية عشان الجداول الكتير فيها ما تبقاش تقيلة. */
  :root{ --navy:#15355B; --navy2:#1e4a7a; --gold:#C9A227; --gold-l:#f6edcf; --gold-d:#8a6d10;
         --ink:#1A2433; --muted:#5A6472; --line:#E2E7EE; --bg:#EEF1F6; --red:#B4383E; --green:#1f7a52;
         --sader:#a76a1e; --warid:#1e4a7a; }
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);
    font-family:'Segoe UI',Tahoma,'Cairo',sans-serif;direction:rtl;text-align:right;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:12px;line-height:1.42}
  .sheet{width:210mm;margin:14px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden}
  .pad{padding:9mm 10mm 10mm}
  .topbar{height:8px;background:linear-gradient(90deg,var(--gold),var(--navy))}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:0}
  .wm img{width:60%;max-width:420px;opacity:.045}
  .pad>*{position:relative;z-index:1}

  /* الترويسة */
  .head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;
    padding-bottom:10px;border-bottom:2px solid var(--line)}
  .brand{display:flex;flex-direction:column;align-items:center;gap:4px}
  .brandlogo{height:60px;width:auto;max-width:200px;object-fit:contain}
  .brand .logo{width:60px;height:60px;border-radius:14px;background:var(--navy);color:var(--gold);
    display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800}
  .brand .cap{color:var(--navy);font-size:12.5px;font-weight:800;letter-spacing:.5px}
  .doc-tag{text-align:left;min-width:210px}
  .doc-tag .word{display:inline-block;background:var(--navy);color:#fff;font-size:16px;font-weight:800;
    padding:6px 18px;border-radius:9px;letter-spacing:.5px}
  .doc-tag .meta{margin-top:8px;font-size:11.5px;color:var(--muted);line-height:1.6;white-space:nowrap}
  .doc-tag .meta b{color:var(--navy);font-size:12.5px}
  .type-chip{display:inline-block;margin-top:7px;padding:4px 12px;border-radius:999px;font-weight:800;font-size:11.5px;color:#fff}
  .type-chip.sader{background:var(--sader)} .type-chip.warid{background:var(--warid)}
  .st-chip{display:inline-block;margin-inline-start:6px;padding:5px 12px;border-radius:999px;font-weight:800;font-size:11.5px}
  .st-chip.closed{background:#e4f5ec;color:var(--green)} .st-chip.open{background:#fef3d7;color:var(--gold-d)}

  /* بيانات الإذن */
  .sec{margin-top:11px}
  .sec-h{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:800;color:var(--navy);
    padding:5px 11px;background:linear-gradient(90deg,var(--gold-l),transparent);
    border-inline-start:4px solid var(--gold);border-radius:0 8px 8px 0;margin-bottom:7px}
  .sec-h .cnt{margin-inline-start:auto;font-size:11px;color:var(--muted);font-weight:700}
  .info{border:1px solid var(--line);border-radius:10px;overflow:hidden;
    display:grid;grid-template-columns:repeat(5,1fr)}
  .info .cell{padding:5px 9px;border-bottom:1px solid var(--line);border-inline-start:1px solid var(--line)}
  .info .cell .l{font-size:9px;color:var(--muted);font-weight:700;margin-bottom:1px}
  .info .cell .v{font-weight:800;color:var(--ink);font-size:11.5px}
  .info .cell.wide{grid-column:span 2}

  /* بطاقات الملخّص */
  .tiles{display:grid;grid-template-columns:repeat(8,1fr);gap:6px;margin-top:4px}
  .tile{border:1px solid var(--line);border-radius:9px;padding:6px 8px;background:#fcfdfe;
    display:flex;flex-direction:column;gap:1px}
  .tile .tl{font-size:8.5px;color:var(--muted);font-weight:700;line-height:1.25}
  .tile .tv{font-size:12px;font-weight:800;color:var(--navy);font-variant-numeric:tabular-nums;
    overflow-wrap:anywhere;line-height:1.2}
  .tile.neg .tv{color:var(--red)} .tile.pos .tv{color:var(--green)} .tile.gold .tv{color:var(--gold-d)}

  /* تغطية الإيصالات */
  .cover{margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:11.5px}
  .cover .lbl{font-weight:800;color:var(--navy)}
  .cover .have{background:#e4f5ec;color:var(--green);padding:3px 10px;border-radius:999px;font-weight:700}
  .cover .miss{background:#fdeceb;color:var(--red);padding:3px 10px;border-radius:999px;font-weight:700}

  /* الجداول — table-layout:fixed يمنع الجدول من تجاوز عرض الصفحة (يلتفّ النص بدل القصّ) */
  table.grid{width:100%;table-layout:fixed;border-collapse:collapse;font-size:12px;border:1px solid var(--line);
    border-radius:10px;overflow:hidden;margin-bottom:4px}
  table.grid thead th{background:var(--navy);color:#fff;padding:8px 6px;font-weight:700;text-align:center;font-size:11.5px;
    word-break:break-word}
  table.grid thead th.r{text-align:right}
  table.grid tbody td{padding:7px 6px;border-bottom:1px solid var(--line);text-align:center;vertical-align:middle;
    word-break:break-word;overflow-wrap:anywhere}
  table.grid tbody td.r{text-align:right}
  table.grid tbody td.num{font-variant-numeric:tabular-nums}
  /* الجدول العريض (البوالص): خط وحشو أصغر ليدخل كل الأعمدة في عرض A4 */
  table.grid.wide{font-size:10.5px}
  table.grid.wide thead th{padding:7px 4px;font-size:10.5px}
  table.grid.wide tbody td{padding:6px 4px}
  table.grid tbody td.mut{color:var(--muted)}
  table.grid tbody td.act{font-weight:800;color:var(--navy)}
  table.grid tbody tr:nth-child(even){background:#f8fafc}
  table.grid tr.sumrow td{background:var(--gold-l);font-weight:800;color:var(--gold-d);border-top:1.5px solid var(--gold)}
  .neg{color:var(--red)} .pos{color:var(--green)}
  .chip{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;white-space:nowrap}
  .chip.ok{background:#e4f5ec;color:var(--green)} .chip.pend{background:#fef3d7;color:var(--gold-d)}
  .chip.none{background:#eef1f6;color:var(--muted)} .chip.visa{background:#efe7fb;color:#5b2ea6}
  .chip.pos-t{background:#e4f5ec;color:var(--green)} .chip.neg-t{background:#fdeceb;color:var(--red)}
  .chip.ct{background:#eaf1fb;color:var(--navy)}
  .empty-row{padding:12px;text-align:center;color:var(--muted);font-size:12px;
    border:1px dashed var(--line);border-radius:10px;background:#fcfdfe}

  /* الحركات */
  .mv{border:1px solid var(--line);border-radius:11px;overflow:hidden;margin-bottom:10px;break-inside:avoid}
  .mv-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:7px 12px;
    background:var(--navy);color:#fff}
  .mv-head .seq{font-weight:800;font-size:13.5px}
  .mv-head .note{font-size:12px;opacity:.9}
  .mv-head .ct{background:rgba(255,255,255,.16);padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700}
  .mv-head .st{margin-inline-start:auto;display:flex;gap:12px;font-size:12px;font-weight:700}
  .mv-head .st .v{color:var(--gold)}
  .mv-body{padding:9px 10px}
  .mv-body .blk{margin-bottom:9px}
  .mv-body .blk:last-child{margin-bottom:0}
  .blk-h{font-size:12px;font-weight:800;color:var(--navy);margin-bottom:6px;display:flex;align-items:center;gap:6px}
  .blk-h .n{background:#eef1f6;color:var(--muted);border-radius:999px;padding:1px 8px;font-size:11px}
  .none-note{font-size:11.5px;color:var(--muted);padding:6px 10px;background:#f8fafc;border-radius:8px;
    border:1px dashed var(--line)}

  /* رسوم أولية تلقائية */
  .auto{border:1px solid var(--gold);background:var(--gold-l);border-radius:10px;padding:10px 13px;font-size:12px}
  .auto .ah{font-weight:800;color:var(--gold-d);margin-bottom:6px}
  .auto .ar{display:flex;justify-content:space-between;padding:3px 0}
  .auto .ar b{color:var(--navy)} .auto .ar.tot{border-top:1px solid var(--gold);margin-top:4px;padding-top:6px;font-weight:800}

  /* التذييل + التواقيع */
  .foot{margin-top:20px;padding-top:14px;border-top:2px solid var(--line);
    display:grid;grid-template-columns:1fr 1fr 1fr;gap:30px;align-items:end}
  .sign{text-align:center}
  .sign .line{margin-top:34px;border-top:1.4px dashed var(--muted);padding-top:5px;color:var(--muted);font-size:12px;font-weight:700}
  .pagefoot{text-align:center;color:var(--muted);font-size:10px;padding:12px 0 4px;margin-top:8px;border-top:1px solid var(--line)}
  .note-box{margin-top:6px;border:1px solid var(--line);border-radius:10px;padding:9px 13px;
    font-size:12px;background:#fcfdfe}
  .note-box .t{color:var(--gold-d);font-weight:800;font-size:10.5px;margin-bottom:2px}

  /* شريط الأدوات */
  .bar{max-width:210mm;margin:8px auto 0;display:flex;gap:10px;justify-content:center;padding:6px;flex-wrap:wrap}
  .bar .bar-count{font-weight:800;color:var(--navy);align-self:center}
  .btn{padding:10px 22px;border:none;border-radius:10px;font-weight:800;font-size:14px;cursor:pointer;
    font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn.gold{background:var(--gold);color:var(--navy)}
  .btn.ghost{background:#fff;color:var(--navy);border:1.5px solid var(--navy)}

  @media print{
    /* margin:0 عشان الورقة تملأ الصفحة بنفس عرض الشاشة (210مم) فيتطابق قياس سكربت التصغير مع الطباعة.
       الهامش الفعلي داخل .pad نفسه. */
    @page{size:A4;margin:0}
    html,body{background:#fff}
    .noprint{display:none!important}
    .sheet{margin:0 auto;box-shadow:none;width:auto}
    .pad{padding:8mm 9mm}
    .topbar{display:none}
    .mv,table.grid tr,.tile,.sign{break-inside:avoid}
    .sec-h{break-after:avoid}
    /* في الطباعة المجمّعة: كل إذن يبدأ في صفحة جديدة (عدا الأول) */
    .sheet{break-before:page}
    .sheet:first-of-type{break-before:auto}
  }
</style>
