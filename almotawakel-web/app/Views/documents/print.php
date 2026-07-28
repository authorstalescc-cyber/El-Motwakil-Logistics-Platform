<?php
/**
 * صفحة طباعة مستند طرف — للصور بس (v56).
 *
 * الـPDF بيتفتح في عارض المتصفح المدمج (بيطبع أحسن من أي صفحة نعملها)،
 * فالمسار بيحوّله هناك ومش بيوصل للملف ده أصلاً.
 *
 * ⚠️ **ولا رقم مالي في الصفحة دي** — بتتفتح على موبايل المندوب كمان
 *    (قاعدة v35). العنوان واسم الطرف وبس، والصورة ملء الورقة.
 *
 * ⚠️ الطباعة **دايمًا فاتحة** (قاعدة المرحلة ١٥) — مفيش أي وضع ليلي هنا.
 *
 * المتغيّرات: $doc $party
 */
$party = $party ?? null;
$src   = url('attachments/party-doc/' . (int) $doc['id']);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e((string) $doc['title']) ?></title>
<?php /* ⚠️ المستند ده كان **المستند الوحيد اللي ما بيستدعيش `print_base`** —
         فكان بيطلب `'IBM Plex Sans Arabic'` وهو اسم غير معلَن في أي مكان،
         يعني بيقع على خط النظام. الاستدعاء بيجيب الوجه وتوكنز الهوية. */ ?>
<?php partial('print_base'); ?>
<style>
  @page { size: A4; margin: 12mm; }
  *{box-sizing:border-box}
  body{margin:0;background:#f4f5f7;color:#111;
       font-family:var(--font);font-weight:500}
  .sheet{max-width:210mm;margin:0 auto;background:#fff;padding:14mm;min-height:297mm;
         display:flex;flex-direction:column;gap:8mm}
  .hd{display:flex;align-items:baseline;justify-content:space-between;gap:10px;
      border-bottom:1.5px solid #d8dbe0;padding-bottom:6mm}
  .hd h1{margin:0;font-size:18pt;font-weight:600}
  .hd .who{font-size:11pt;color:#555}
  .hd .no{font-size:10pt;color:#555;direction:ltr}
  .shot{flex:1;display:flex;align-items:center;justify-content:center}
  .shot img{max-width:100%;max-height:230mm;object-fit:contain;
            border:1px solid #e2e4e8;border-radius:4px}
  .bar{display:flex;gap:8px;justify-content:center;padding:10px 0}
  .bar button,.bar a{font:inherit;font-size:11pt;padding:9px 18px;border-radius:8px;
      border:1px solid #c9ccd2;background:#fff;color:#111;cursor:pointer;text-decoration:none}
  .bar button{background:#15355B;color:#fff;border-color:#15355B}
  @media print { body{background:#fff} .sheet{padding:0;min-height:auto} .bar{display:none} }
</style>
</head>
<body>
  <div class="bar">
    <button type="button" onclick="window.print()">اطبع</button>
    <a href="<?= e($src) ?>?dl=1">تحميل الملف</a>
  </div>

  <div class="sheet">
    <div class="hd">
      <div>
        <h1><?= e((string) $doc['title']) ?></h1>
        <?php if ($party): ?><div class="who"><?= e((string) $party['name']) ?></div><?php endif; ?>
      </div>
      <div>
        <?php if (!empty($doc['doc_no'])): ?><div class="no">رقم: <?= e((string) $doc['doc_no']) ?></div><?php endif; ?>
        <?php if (!empty($doc['expires_at'])): ?><div class="no">ينتهي: <?= dfmt($doc['expires_at']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="shot"><img src="<?= e($src) ?>" alt="<?= e((string) $doc['title']) ?>"></div>
  </div>
</body>
</html>
