#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
مولّد أصول التطبيق (PWA) — يتشغّل مرّة واحدة، ونتيجته متسلَّمة في المستودع.

    python3 bin/make_pwa_assets.py

⚠️ **تطبيقان بأيقونتين مختلفتين على نفس النطاق** (قرار المالك 2026-07-27):
   المالك بيثبّت الاتنين على نفس الموبايل، ولو ليهم نفس الأيقونة بيبقى
   مستحيل يفرّق بينهم من شاشة الهاتف. فالفرق **في الأيقونة نفسها**:

     • **المكتب** (`/`)  → خلفية **زرقاء** (تدرّج الهوية الكحلي) + علامة
       الشاحنة والمخزن البيضا `tex-mark.png` + حلقة ذهبية رفيعة.
     • **المندوب** (`/m`) → لوجو الشركة الكامل على كارت فاتح (زي ما كان).

   لو غيّرت واحدة فيهم، **ما تخليهاش تشبه التانية** — ده الغرض كله.

المصدران: public/assets/img/logo.png (لوجو الشركة) و
          public/assets/img/tex-mark.png (علامة الشاحنة، خطوط بيضا على شفاف).

المخرجات في public/assets/img/pwa/ (المندوب) و pwa/office/ (المكتب):
  • icon-{192,256,384,512}.png       → أيقونة عادية (زوايا مستديرة + شفافية برّاها)
  • icon-maskable-{192,512}.png      → أيقونة أندرويد التكيّفية (مربّع كامل + منطقة أمان 80%)
  • apple-touch-icon.png (180)       → أيقونة آيفون (معتمة تمامًا — iOS بيستدير لوحده)
  • splash/apple-splash-{w}x{h}.png  → شاشة البدء لكل مقاس آيفون/آيباد (مشتركة)

⚠️ ممنوع كتابة أي نص عربي في الصور دي: مكتبة PIL **ما بتشكّلش** الحروف العربية
   (بتطلع مقطّعة ومقلوبة). اسم الشركة موجود جوّه اللوجو نفسه كرسم، والنص الحقيقي
   بيتكتب HTML في شاشة البدء الظاهرة بعد الإقلاع.

⚠️ شاشة البدء المولَّدة هنا **لازم تفضل مطابقة** للشاشة اللي بيرسمها
   `app/Views/partials/pwa.php` بالـHTML (نفس التدرّج الكحلي + نفس الكارت الأبيض)،
   وإلا المستخدم هيشوف شاشتين مختلفتين ورا بعض عند فتح التطبيق.
   الصورة دي هي أول فريم، والحركة بتكمّل من الـHTML — فنفس التدرّج ونفس
   الكارت ونفس الطريق والعربية.
"""
import os
import sys

try:
    from PIL import Image, ImageDraw, ImageFilter
except ImportError:                                              # pragma: no cover
    sys.exit('محتاج مكتبة Pillow:  pip3 install Pillow')

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'public', 'assets', 'img', 'logo.png')
MARK = os.path.join(ROOT, 'public', 'assets', 'img', 'tex-mark.png')
OUT = os.path.join(ROOT, 'public', 'assets', 'img', 'pwa')
OFFICE_OUT = os.path.join(OUT, 'office')
SPLASH_OUT = os.path.join(OUT, 'splash')

NAVY = (21, 53, 91)          # --navy     #15355B
NAVY_MID = (30, 75, 122)     # --navy-mid #1E4B7A — أعلى تدرّج أيقونة المكتب
NAVY_DARK = (11, 30, 56)     # أغمق شوية من --navy-dark عشان عمق التدرّج
GOLD = (201, 162, 39)        # --gold   #C9A227
CARD_TOP = (255, 255, 255)
CARD_BOT = (238, 243, 249)   # لمسة رمادية زرقاء خفيفة تدّي إحساس بالعمق


def load_logo():
    """يفتح اللوجو ويقصّ الهوامش البيضا حواليه عشان يتوسّط بالظبط."""
    im = Image.open(SRC).convert('RGBA')
    rgb = im.convert('RGB')
    w, h = im.size
    px = rgb.load()
    left, top, right, bottom = w, h, 0, 0
    for y in range(h):
        for x in range(w):
            r, g, b = px[x, y]
            if r > 246 and g > 246 and b > 246:                 # أبيض ≈ هامش
                continue
            left = min(left, x); right = max(right, x)
            top = min(top, y); bottom = max(bottom, y)
    if right <= left or bottom <= top:
        return im
    return im.crop((left, top, right + 1, bottom + 1))


def vgradient(size, top_color, bottom_color):
    """تدرّج رأسي — الأساس اللي بيدّي الإحساس المصقول بدل لون مسطّح."""
    w, h = size
    grad = Image.new('RGB', (1, h))
    d = grad.load()
    for y in range(h):
        t = y / max(1, h - 1)
        d[0, y] = tuple(int(top_color[i] + (bottom_color[i] - top_color[i]) * t) for i in range(3))
    return grad.resize((w, h), Image.BILINEAR)


def gold_glow(size, cx_ratio=0.5, cy_ratio=0.30, radius_ratio=0.95, alpha=38):
    """هالة ذهبية ناعمة — بتكسر رتابة التدرّج الكحلي."""
    w, h = size
    small = max(24, min(w, h) // 8)
    layer = Image.new('L', (small, small), 0)
    dr = ImageDraw.Draw(layer)
    r = int(small * radius_ratio / 2)
    cx, cy = int(small * cx_ratio), int(small * cy_ratio)
    steps = 26
    for i in range(steps, 0, -1):
        rr = int(r * i / steps)
        dr.ellipse([cx - rr, cy - rr, cx + rr, cy + rr], fill=int(alpha * (1 - i / steps) ** 1.6))
    layer = layer.resize((w, h), Image.BICUBIC).filter(ImageFilter.GaussianBlur(max(2, min(w, h) // 40)))
    glow = Image.new('RGBA', (w, h), GOLD + (0,))
    glow.putalpha(layer)
    return glow


def rounded_mask(size, radius):
    m = Image.new('L', size, 0)
    ImageDraw.Draw(m).rounded_rectangle([0, 0, size[0] - 1, size[1] - 1], radius=radius, fill=255)
    return m


def fit(logo, box_w, box_h):
    """يكبّر/يصغّر اللوجو جوّه صندوق مع الحفاظ على نسبته."""
    lw, lh = logo.size
    scale = min(box_w / lw, box_h / lh)
    return logo.resize((max(1, int(lw * scale)), max(1, int(lh * scale))), Image.LANCZOS)


# ── الأيقونات ────────────────────────────────────────────────────────────────
def make_icon(logo, size, *, maskable=False, opaque=False):
    canvas = vgradient((size, size), CARD_TOP, CARD_BOT).convert('RGBA')

    # اللوجو: 74% للعادية، و54% للتكيّفية عشان يفضل جوّه دايرة الأمان (80%)
    ratio = 0.54 if maskable else 0.74
    art = fit(logo, int(size * ratio), int(size * ratio * 0.86))
    canvas.paste(art, ((size - art.size[0]) // 2, (size - art.size[1]) // 2), art)

    if maskable or opaque:
        return canvas.convert('RGB') if opaque else canvas

    # زوايا مستديرة + إطار داخلي رفيع كحلي شفّاف (يفصل الأيقونة عن الخلفيات البيضا)
    radius = int(size * 0.225)
    frame = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    ImageDraw.Draw(frame).rounded_rectangle(
        [1, 1, size - 2, size - 2], radius=radius, outline=NAVY + (26,), width=max(1, size // 128)
    )
    canvas = Image.alpha_composite(canvas, frame)
    canvas.putalpha(rounded_mask((size, size), radius))
    return canvas


# ── أيقونة المكتب: زرقاء بعلامة الشاحنة ──────────────────────────────────────
def load_mark():
    """علامة الشاحنة والمخزن — خطوط بيضا على شفاف، بتتقصّ على حدود الرسم."""
    im = Image.open(MARK).convert('RGBA')
    box = im.getbbox()                                          # الشفاف حوالين الرسم
    im = im.crop(box) if box else im
    # الرسم كله أبيض مصمت، فأي بكسل نصف-شفاف لازم لونه يفضل أبيض كامل —
    # من غير كده الحواف بتاخد رمادي من قيم RGB المخزَّنة وتبان متّسخة على الأزرق.
    im.putdata([(255, 255, 255, a) for (_, _, _, a) in im.getdata()])
    return im


def fit_mark(mark, box_w, box_h):
    """
    ⚠️ العلامة الأصلية **146×68 بكسل بس**، والأيقونة بتوصل 512 — يعني تكبير
       ×2 وأكتر. `LANCZOS` لوحده بيسيب هالة رمادية حوالين الحواف فتبان
       الأيقونة «مبهّتة ومسنّنة» على الشاشات الكبيرة.

       العلاج: بعد التكبير بنعدّي **قناة الشفافية** على منحنى حاد (كل قيمة
       تحت النص بتروح لصفر وفوقها لـ255، مع شريحة انتقال رفيعة تحافظ على
       نعومة الحواف). النتيجة حافة نضيفة بلا هالة — والرسم نفسه silhouette
       فالتفاصيل ما بتضيعش.
    """
    lw, lh = mark.size
    scale = min(box_w / lw, box_h / lh)
    tw, th = max(1, int(lw * scale)), max(1, int(lh * scale))

    # ① كبّر ×4 فوق المقاس المطلوب — الشغل كله بيحصل على دقّة عالية
    big = mark.getchannel('A').resize((tw * 4, th * 4), Image.BICUBIC)
    # ② ضبابية بقدر السلّمة الواحدة — بتذوّب التسنين لتدرّج ناعم
    # ⚠️ نصف السلّمة بالظبط: أعلى من كده بيلحّم الخطوط الرفيعة (شكمانات
    #    الشاحنة) في كتلة واحدة، وأقل من كده التسنين بيفضل ظاهر.
    big = big.filter(ImageFilter.GaussianBlur(max(1.0, scale * 4 / 3.4)))
    # ③ منحنى حاد بيرجّع الحافة قاطعة (بس على دقّة ×4 فبقت ناعمة مش مسنّنة)
    big = big.point([0 if v < 110 else (255 if v > 146 else int((v - 110) * 255 / 36)) for v in range(256)])
    # ④ صغّر للمقاس النهائي — التصغير هو اللي بيدّي التنعيم (anti-aliasing) النضيف
    alpha = big.resize((tw, th), Image.LANCZOS)

    art = Image.new('RGBA', (tw, th), (255, 255, 255, 0))
    art.putalpha(alpha)
    return art


def make_office_icon(mark, size, mode='normal'):
    """
    الأيقونة الزرقاء بتلات أوضاع — الفرق بينهم **نسبة العلامة** و**الاستدارة**،
    والهوية (التدرّج الكحلي + الحلقة الذهبية) بتفضل واحدة على كل الأجهزة:

      • normal   → 62% + زوايا مستديرة (شفافية برّاها) + حلقة ذهبية
      • maskable → 44% بس، عشان تفضل جوّه دايرة الأمان (80%) اللي أندرويد
                   بيقصّ برّاها؛ ومن غير حلقة لأن الحلقة نفسها بتتقصّ.
      • apple    → 62% معتمة تمامًا وبلا استدارة — ⚠️ iOS **ما بيقصّش** أي
                   منطقة أمان (هو بيستدير الزوايا بنفسه بس)، فاستعمال نسبة
                   الـmaskable هنا كان بيدّي علامة ضايعة في نص مربّع أزرق.
    """
    canvas = vgradient((size, size), NAVY_MID, NAVY_DARK).convert('RGBA')
    canvas = Image.alpha_composite(canvas, gold_glow((size, size), 0.5, 0.26, 1.05, 46))

    if mode != 'maskable':
        # حلقة ذهبية رفيعة — بتفصل الأيقونة عن أي خلفية وبتقول «هوية» بلا زحمة
        ring = Image.new('RGBA', (size, size), (0, 0, 0, 0))
        inset = int(size * 0.085)
        ImageDraw.Draw(ring).rounded_rectangle(
            [inset, inset, size - inset - 1, size - inset - 1],
            radius=int(size * 0.16), outline=GOLD + (150,), width=max(1, int(size / 96))
        )
        canvas = Image.alpha_composite(canvas, ring)

    ratio = 0.44 if mode == 'maskable' else 0.62
    art = fit_mark(mark, int(size * ratio), int(size * ratio))
    canvas.paste(art, ((size - art.size[0]) // 2, (size - art.size[1]) // 2), art)

    if mode == 'maskable':
        return canvas
    if mode == 'apple':
        return canvas.convert('RGB')

    canvas.putalpha(rounded_mask((size, size), int(size * 0.225)))
    return canvas


# ── شاشة البدء ───────────────────────────────────────────────────────────────
def make_splash(logo, w, h):
    canvas = vgradient((w, h), NAVY, NAVY_DARK).convert('RGBA')
    canvas = Image.alpha_composite(canvas, gold_glow((w, h)))

    short = min(w, h)
    card_w = int(short * 0.66)
    card_h = int(card_w * 0.62)
    radius = int(card_w * 0.14)

    # ظل ناعم تحت الكارت. الكارت أعلى من النص شوية (45%) — المركز البصري
    # بيبقى أريح للعين من المركز الحسابي، وبيسيب مساحة للشرطة الذهبية تحته.
    shadow = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    sx, sy = (w - card_w) // 2, int(h * 0.45) - card_h // 2
    ImageDraw.Draw(shadow).rounded_rectangle(
        [sx, sy + int(card_h * 0.06), sx + card_w, sy + card_h + int(card_h * 0.06)],
        radius=radius, fill=(0, 0, 0, 70)
    )
    canvas = Image.alpha_composite(canvas, shadow.filter(ImageFilter.GaussianBlur(short // 60)))

    card = vgradient((card_w, card_h), CARD_TOP, CARD_BOT).convert('RGBA')
    card.putalpha(rounded_mask((card_w, card_h), radius))
    art = fit(logo, int(card_w * 0.78), int(card_h * 0.70))
    card.paste(art, ((card_w - art.size[0]) // 2, (card_h - art.size[1]) // 2), art)
    canvas.paste(card, (sx, sy), card)

    # الطريق والعربية — نفس اللي بيتحرّك في شاشة البدء بالـHTML، بس ساكن هنا.
    # الصورة دي هي **أول فريم** اللي بيشوفه المستخدم على الآيفون، فلو اختلفت
    # عن شاشة الـHTML هيحسّ بقفزة وقت ما التطبيق يخلص تحميل.
    road_w = int(short * 0.55)
    road_y = sy + card_h + int(short * 0.085)
    draw_road(canvas, (w - road_w) // 2, road_y, road_w, short)
    return canvas.convert('RGB')


def draw_road(canvas, x, y, road_w, short):
    """خط الأسفلت بشرَطه الذهبية + عربية نقل مرسومة بالخط الذهبي فوقه."""
    d = ImageDraw.Draw(canvas)
    line_h = max(2, short // 300)
    d.rectangle([x, y, x + road_w, y + line_h], fill=(255, 255, 255, 46))

    # الشرَط الذهبية (بتبهت عند الطرفين زي قناع الـCSS)
    dash, gap = int(road_w * 0.07), int(road_w * 0.11)
    cx_road = x + road_w / 2
    px = x
    while px < x + road_w:
        fade = 1 - min(1.0, abs(px + dash / 2 - cx_road) / (road_w / 2)) ** 2
        if fade > 0.05:
            d.rectangle([px, y, min(px + dash, x + road_w), y + line_h],
                        fill=GOLD + (int(240 * fade),))
        px += dash + gap

    # العربية: بترسم على طبقة لوحدها بدقّة ×4 وبتتقلب أفقيًّا (الكابينة ناحية
    # الشمال = اتجاه السير) وبعدين تتصغّر — أنعم من الرسم المباشر بكتير.
    tw = int(road_w * 0.46)
    ss = 4
    W, H = 108 * ss, 44 * ss
    layer = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    ld = ImageDraw.Draw(layer)
    lw = max(2, int(3 * ss))                                    # نفس سُمك خط الـSVG
    ld.rectangle([14 * ss, 8 * ss, 60 * ss, 34 * ss], outline=GOLD, width=lw)
    ld.polygon([(60 * ss, 16 * ss), (74 * ss, 16 * ss), (86 * ss, 28 * ss),
                (86 * ss, 34 * ss), (60 * ss, 34 * ss)], outline=GOLD, width=lw)
    for cx in (30, 78):
        ld.ellipse([(cx - 6.5) * ss, 29.5 * ss, (cx + 6.5) * ss, 42.5 * ss],
                   fill=NAVY_DARK, outline=GOLD, width=lw)
    layer = layer.transpose(Image.FLIP_LEFT_RIGHT)
    th = int(tw * H / W)
    layer = layer.resize((tw, th), Image.LANCZOS)
    canvas.paste(layer, (int(x + (road_w - tw) / 2), int(y + line_h - th + max(2, short // 260))), layer)


# مقاسات آيفون/آيباد (عرض×ارتفاع بالبكسل الحقيقي، عمودي).
# الشاشة اللي مالهاش صورة مطابقة بتقع على `background_color` وبعدها شاشة البدء بالـHTML.
SPLASHES = [
    (1320, 2868), (1290, 2796), (1206, 2622), (1284, 2778), (1179, 2556),
    (1170, 2532), (1125, 2436), (1242, 2688), (1242, 2208), (828, 1792),
    (750, 1334), (640, 1136),
    (1536, 2048), (1620, 2160), (1640, 2360), (1668, 2224), (1668, 2388), (2048, 2732),
]


def main():
    if not os.path.isfile(SRC):
        sys.exit('مش لاقي اللوجو: ' + SRC)
    if not os.path.isfile(MARK):
        sys.exit('مش لاقي علامة الشاحنة: ' + MARK)
    os.makedirs(OUT, exist_ok=True)
    os.makedirs(OFFICE_OUT, exist_ok=True)
    os.makedirs(SPLASH_OUT, exist_ok=True)
    logo = load_logo()
    print('اللوجو بعد قص الهوامش:', logo.size)

    # ① أيقونة المندوب — لوجو الشركة على كارت فاتح
    for s in (192, 256, 384, 512):
        make_icon(logo, s).save(os.path.join(OUT, f'icon-{s}.png'), optimize=True)
    for s in (192, 512):
        make_icon(logo, s, maskable=True).save(os.path.join(OUT, f'icon-maskable-{s}.png'), optimize=True)
    make_icon(logo, 180, maskable=True, opaque=True).save(os.path.join(OUT, 'apple-touch-icon.png'), optimize=True)
    print('أيقونات المندوب: تمام')

    # ② أيقونة المكتب — زرقاء بعلامة الشاحنة (لازم تفضل مختلفة عن ① بالعين)
    mark = load_mark()
    print('علامة الشاحنة بعد قص الشفاف:', mark.size)
    for s in (192, 256, 384, 512):
        make_office_icon(mark, s).save(os.path.join(OFFICE_OUT, f'icon-{s}.png'), optimize=True)
    for s in (192, 512):
        make_office_icon(mark, s, 'maskable').save(
            os.path.join(OFFICE_OUT, f'icon-maskable-{s}.png'), optimize=True)
    make_office_icon(mark, 180, 'apple').save(
        os.path.join(OFFICE_OUT, 'apple-touch-icon.png'), optimize=True)
    print('أيقونات المكتب: تمام')

    # ③ نسخة العرض للويب (شاشة الدخول)
    # ⚠️ الملف الأصلي **250×250 والرسم جوّاه 146×68 بس** — الباقي شفاف. لو
    #    اتعرض كما هو، المتصفح بيحجز المربّع كامل فالعلامة تبان صغيرة وسط
    #    فراغ، والتخطيط حواليها يطلع غلط. فبنقصّه وبنكبّره ×4 بنفس تنعيم
    #    الأيقونات عشان يفضل حادًّا على شاشات الريتينا.
    web = fit_mark(mark, mark.size[0] * 4, mark.size[1] * 4)
    web.save(os.path.join(ROOT, 'public', 'assets', 'img', 'tex-mark-lg.png'), optimize=True)
    print('علامة العرض (tex-mark-lg.png):', web.size)

    for w, h in SPLASHES:
        make_splash(logo, w, h).save(os.path.join(SPLASH_OUT, f'apple-splash-{w}x{h}.png'), optimize=True)
    print(f'شاشات البدء: {len(SPLASHES)} ملف')


if __name__ == '__main__':
    main()
