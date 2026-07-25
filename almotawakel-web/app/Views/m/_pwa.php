<?php /* وسوم الـPWA لشاشات المندوب — تتضمّن داخل <head>. آمنة على أي شاشة.
         الوسوم والسكربتات نفسها بقت في ملف واحد مشترك مع المكتب (`_pwa_head.php`)
         عشان الأيقونة وشاشة البدء ما يتفرّعوش لنسختين تختلفوا مع الوقت. */ ?>
<?php $pwaApp = 'rep'; require dirname(__DIR__) . '/_pwa_head.php'; ?>
<?php /* الرجوع لنفس المكان بعد الحفظ — نفس وحدة المكتب بالحرف. البوابة دي
         **ما بتحمّلش app.js**، فلو الوحدة فضلت هناك كانت شاشات المندوب
         هتفضل بترجع لأول الصفحة بعد كل حفظ. defer عشان الـDOM يبقى جاهز. */ ?>
<?php $rsJs = \App\Core\App::basePath('public/assets/js/resume-scroll.js'); ?>
<script defer src="<?= url('assets/js/resume-scroll.js') ?>?v=<?= is_file($rsJs) ? filemtime($rsJs) : 1 ?>"></script>
