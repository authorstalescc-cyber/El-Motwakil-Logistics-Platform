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
 *    `bin/verify_dark_print.php` بيحرس ده (بيقارن القيم نفسها مش مجرد الوجود).
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
    /* هوية الشركة — نفس قيم app.css بالظبط (`bin/verify_dark_print.php`
       بيقارنهم ويفشل لو اختلفوا — كانت منحرفة: `--warid` كان لونًا تانيًا
       خالص، و`--red/--green/--sader/--gold-l` درجات مختلفة عن الشاشة) */
    --navy:#15355B; --navy2:#1E4B7A; --gold:#C9A227; --gold-l:#F3E9C6; --gold-d:#8A6D10;
    --ink:#1A2433; --muted:#5A6472; --line:#E2E7EE; --bg:#F4F6F9;
    --red:#9B2C2C; --green:#1E6E3C;
    --sader:#B26A1B; --warid:#0E7C86;
    --font:'Cairo',system-ui,-apple-system,Tahoma,Arial,sans-serif;
    /* مقاسات واستدارات — كانت ناقصة هنا ف`office/statement.php` (اللي بيستخدم
       var(--fs-*) وvar(--radius-*)) كان بيسقط لخط ومقاسات المتصفح **بصمت** */
    --fs-1:.75rem; --fs-2:.82rem; --fs-3:.9rem; --fs-4:1rem; --fs-5:1.15rem; --fs-6:1.35rem;
    --radius-1:8px; --radius-2:12px;
  }
</style>
