<?php /* وسوم الـPWA لشاشات المندوب — تتضمّن داخل <head>. آمنة على أي شاشة.
         الوسوم والسكربتات نفسها بقت في ملف واحد مشترك مع المكتب (`_pwa_head.php`)
         عشان الأيقونة وشاشة البدء ما يتفرّعوش لنسختين تختلفوا مع الوقت. */ ?>
<?php $pwaApp = 'rep'; require dirname(__DIR__) . '/_pwa_head.php'; ?>
<?php /* الرجوع لنفس المكان بعد الحفظ — نفس وحدة المكتب بالحرف. البوابة دي
         **ما بتحمّلش app.js**، فلو الوحدة فضلت هناك كانت شاشات المندوب
         هتفضل بترجع لأول الصفحة بعد كل حفظ. defer عشان الـDOM يبقى جاهز. */ ?>
<?php $rsJs = \App\Core\App::basePath('public/assets/js/resume-scroll.js'); ?>
<script defer src="<?= url('assets/js/resume-scroll.js') ?>?v=<?= is_file($rsJs) ? filemtime($rsJs) : 1 ?>"></script>
<?php $laJs = \App\Core\App::basePath('public/assets/js/lookup-autofill.js'); ?>
<?php /* شركة الشحن = العميل تلقائيًا — ملف مشترك بين المكتب وبوابة المندوب
         (`/m` ما بتحمّلش app.js — نفس سبب `resume-scroll.js`). */ ?>
<script defer src="<?= url('assets/js/lookup-autofill.js') ?>?v=<?= is_file($laJs) ? filemtime($laJs) : 1 ?>"></script>
<?php $miJs = \App\Core\App::basePath('public/assets/js/money-input.js'); ?>
<?php /* فواصل الآلاف في خانات المبالغ — نفس وحدة المكتب بالحرف. البوابة دي
         **ما بتحمّلش app.js**، وخانات المبالغ هنا هي أكتر خانات بتتكتب. */ ?>
<script defer src="<?= url('assets/js/money-input.js') ?>?v=<?= is_file($miJs) ? filemtime($miJs) : 1 ?>"></script>
<?php $mcJs = \App\Core\App::basePath('public/assets/js/m-confirm.js'); ?>
<?php /* تأكيد الحذف — نفس سبب اللي فوق بالظبط: معالج `confirm-delete` عايش في
         `app.js` والبوابة دي ما بتحمّلهاش، فكان مكتوبًا جوّه `m/home.php` وبس
         وباقي الشاشات بتحذف بلا سؤال. هنا بيتحمّل على **كل** شاشة. */ ?>
<script defer src="<?= url('assets/js/m-confirm.js') ?>?v=<?= is_file($mcJs) ? filemtime($mcJs) : 1 ?>"></script>
