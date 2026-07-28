<?php
/**
 * صفحة السائق لما الرابط يبقى مقفول/منتهي/غلط — بلا layout زي صفحة السائق.
 * ما تكشفش أي بيانات عن الشحنة: رسالة بسيطة بس.
 */
use App\Core\App;
$company = App::setting('company_name', 'شركة المتوكل');
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#15355B">
<title>الرابط مش شغّال — <?= e($company) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif;background:#0f2340;
     min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:var(--radius-4);max-width:420px;width:100%;padding:32px 24px;text-align:center;
      box-shadow:0 18px 50px rgba(0,0,0,.35)}
.logo{width:52px;height:52px;border-radius:var(--radius-2);background:#15355B;color:#C9A227;font-weight:700;
      font-size:var(--fs-7);display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
h1{font-size:var(--fs-5);color:#15355B;margin-bottom:14px;font-weight:700}
.ic{font-size:var(--fs-8);margin-bottom:12px}
p{font-size:var(--fs-3);color:#5a6b82;line-height:1.9}
.foot{margin-top:20px;padding-top:16px;border-top:2px solid #eef1f6;font-size:var(--fs-1);color:#8fa0b6}
</style>
</head>
<body>
<div class="card">
  <div class="logo">م</div>
  <h1><?= e($company) ?></h1>
  <div class="ic"><?= icon('alert', 14) ?></div>
  <p><?= e($reason) ?></p>
  <div class="foot">مش محتاج تعمل أي حاجة تانية.</div>
</div>
</body>
</html>
