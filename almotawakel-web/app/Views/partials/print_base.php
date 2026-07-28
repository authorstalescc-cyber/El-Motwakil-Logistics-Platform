<?php
/**
 * أساس ورق الطباعة — **مصدر واحد لهوية كل مستندات A4** (المرحلة ١٥).
 *
 * كان فيه **٧ مستندات طباعة** كل واحد بـ`<style>` خاص، وجوّه كل واحد نسخة من
 * نفس التوكنز بالحرف تقريبًا. النتيجة إن تغيير لون الهوية كان لازم يتعمل ٧
 * مرات، وأول مرة تُنسى بيبقى فيه مستند بلون تاني **بيتطبع ويروح للعميل**.
 *
 * الملف ده بيطبع التوكنز بس (`:root`) — **مش تخطيط**: كل مستند تخطيطه مختلف
 * فعلاً (فاتورة ≠ كشف حساب ≠ ورقة مراجعة)، ودمج التخطيطات كان هيعقّد السبعة
 * بلا مكسب. الستايل الخاص بكل مستند بيجي **بعده** فبيغلب لو احتاج.
 *
 * ⚠️ ألوان الهوية لازم تفضل مطابقة لـ`app.css` — لو اتغيّر لون في النظام
 *    والمستند فضل بالقديم، الشاشة والورقة يبقوا بلونين.
 *    `bin/verify_print.php` بيحرس ده.
 *
 * ⚠️⚠️ **الخط كان ناقص هنا لحد 2026-07-27 — وده كان معناه إن كل ورقة بتطلع
 *      للعميل مكتوبة بخط تاني غير خط النظام.** مستندات A4 **مستقلة**
 *      وما بتحمّلش `app.css`، وكانت بتعلن `'Segoe UI',Tahoma` بنفسها —
 *      وعلى الماك مفيش Segoe فكانت بتقع على **Tahoma**. واتنين منها كانوا
 *      بيطلبوا `'IBM Plex Sans Arabic'` وهو اسم **ما كانش معلَنًا أصلاً**
 *      (الوجه كان اسمه `'Plex Arabic'`) فكانوا بيقعوا على خط النظام كمان.
 *      دلوقتي الوجه والتوكن هنا، وكل مستند بيقول `font-family:var(--font)`.
 *      **ممنوع أي مستند يكتب اسم خط بإيده** — `bin/verify_typography.php` بيحرس.
 *
 * الاستخدام: `<?php partial('print_base'); ?>` قبل `<style>` بتاع المستند.
 */
?>
<style>
  /* الوجه مكرَّر عن قصد (المستند مستقل) — نفس ملفات `app.css` بالظبط */
  @font-face{font-family:'Cairo';font-style:normal;font-weight:200 1000;font-display:swap;size-adjust:103%;
    src:url('<?= e(url('assets/fonts/cairo-arabic.woff2')) ?>') format('woff2')}
  @font-face{font-family:'Cairo';font-style:normal;font-weight:200 1000;font-display:swap;size-adjust:103%;
    src:url('<?= e(url('assets/fonts/cairo-latin.woff2')) ?>') format('woff2')}
  :root{
    /* هوية الشركة — نفس قيم app.css */
    --navy:#15355B; --navy2:#1e4a7a; --gold:#C9A227; --gold-l:#f3e6bd; --gold-d:#8a6d10;
    --ink:#1A2433; --muted:#5A6472; --line:#E2E7EE; --bg:#F4F6FA;
    --red:#B4383E; --green:#1f7a52;
    --sader:#a76a1e; --warid:#1e4a7a;
    --font:'Cairo',system-ui,-apple-system,Tahoma,Arial,sans-serif;
  }
</style>
