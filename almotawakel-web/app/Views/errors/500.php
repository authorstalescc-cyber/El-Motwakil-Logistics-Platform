<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>حدث خطأ</title>
  <style>
    body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6f8;color:#15355b;font-family:Tahoma,Arial,sans-serif}
    main{width:min(520px,calc(100% - 36px));box-sizing:border-box;padding:34px;border:1px solid #dfe4e8;border-radius:16px;background:#fff;text-align:center;box-shadow:0 12px 34px rgba(21,53,91,.08)}
    h1{margin:0 0 12px;font-size:1.45rem}p{line-height:1.8;color:#596978}code{display:inline-block;padding:5px 9px;border-radius:7px;background:#eef2f5;color:#15355b}
    a{display:inline-block;margin-top:14px;padding:9px 16px;border-radius:9px;background:#15355b;color:#fff;text-decoration:none}
  </style>
</head>
<body><main>
  <h1>حدث خطأ غير متوقع</h1>
  <p>لم تُعرض التفاصيل حفاظًا على بيانات النظام. حاول إعادة الصفحة، وإذا تكرر الخطأ أرسل رقم المرجع للمسؤول.</p>
  <p>رقم المرجع: <code><?= htmlspecialchars((string) ($errorId ?? ''), ENT_QUOTES, 'UTF-8') ?></code></p>
  <a href="<?= htmlspecialchars(\App\Core\App::url(''), ENT_QUOTES, 'UTF-8') ?>">العودة للرئيسية</a>
</main></body>
</html>
