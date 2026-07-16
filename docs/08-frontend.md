# 08 — الواجهة الأمامية

Server-rendered PHP: كل الصفحات templates تُلَفّ في layout واحد. لا SPA. التفاعلية = ملف JS واحد (vanilla) + Chart.js منسوخ محليًا. **RTL عربي بالكامل.**

---

## 1. القالب الرئيسي — `app/Views/layout.php`

الغلاف الوحيد لكل صفحة مصادَق عليها. الكنترولر يمرّر `$content` (HTML الـ view المُصيَّر) + `$title` + `$scripts` اختياريان.

- `<html lang="ar" dir="rtl">` — RTL والعربية معلَنة في الجذر.
- **العنوان** = `$title — App::setting('company_name')`.
- **Favicon** = SVG inline (مربّع navy + "م" ذهبية).

### قائمة التنقّل (`$nav` array من `[segment, label, icon]`)
عناصر بـ `icon = null` = فواصل أقسام. الحالة النشطة عبر `str_starts_with`.

| Segment | التسمية | ملاحظات |
|---------|---------|---------|
| `` | لوحة التحكم 📊 | |
| — | **الإدخال** (فاصل) | |
| `permits` | الإصدارات 🗂️ | |
| `bawalis` | البوالص 🚛 | |
| `settlements` | سداد البوالص 💵 | |
| `closing` | التقفيل اليومي 🧮 | |
| `takhlis` | التخليص 📋 | يستخدم `crud/` العام (لا view مخصّص) |
| `generators` | المولدات ⚙️ | يستخدم `crud/` العام |
| `shamil` | شامل — الفواتير 🧾 | |
| `expenses` | المصروفات 💸 | |
| `balances` | الأرصدة 💰 | |
| `directory` | الوكلاء والشركات 🗃️ | |
| — | **التقارير والإدارة** (فاصل) | |
| `reports` | التقارير 📈 | |
| `settings` | الإعدادات ⚙ | |

### الهيكل
- `.app` (flex) → `aside.sidebar` ثابت + `.main`.
- Sidebar: `.brand` (شعار "م" + اسم الشركة + tagline) + `.nav` + `.foot` (الإصدار 1.0).
- `.main` → `header.topbar` لاصق + `main.content`.
- **البحث:** `form.search action="{url('search')}" method="get"` بحقل `q` واحد.
- **الخروج:** form POST بـ `csrfField()` وكلاس `no-confirm`.
- **Flash:** يعرض `flash('success')`/`flash('error')` كـ banners ثم `$content`.

### تحميل الأصول
- CSS: `assets/css/app.css` واحد في `<head>`.
- JS: `assets/js/app.js` قبل `</body>`، يتبعه `<?= $scripts ?? '' ?>` (الـ hook لحقن سكربتات الصفحة — Chart.js يُحقَن هنا من اللوحة/التقارير، مش عالميًا).
- كل الروابط عبر `url()`/`App::baseUrl()`.

> `auth/login.php` و`errors/404.php` **مستندات HTML مستقلّة** لا تستخدم الـ layout (لكن تربط نفس `app.css`).

---

## 2. الـ views (كلها تحت `app/Views/`)

| المجلد | الملف | يعرض |
|--------|-------|------|
| `auth/` | login.php | بطاقة دخول مركزية مستقلّة على تدرّج navy. |
| `dashboard/` | index.php | 8 بلاطات إجراء + 4 KPI + 5 عدّادات + شريط متابعة أذونات + سجل نشاط + نبض اليوم + 3 رسوم Chart.js. |
| `permits/` | index.php | hub بمبدّل 3 أوضاع: follow / movements / records. |
| | form.php | إنشاء/تعديل رأس الإصدار (datalist + ربط وكيل↔شركة). |
| | view.php | تفاصيل للقراءة: hero بحلقة تقدّم SVG + بنود المراحل + accordions للحركات. |
| | manage.php | مساحة عمل كاملة (463 سطر): بنود المراحل بمرفقات وعلم فيزا، حركات، بوالص، إغلاق، حذف. |
| `bawalis/` | show.php | تفاصيل البوليصة: بطاقات مجمّعة + الحركة المرتبطة + الدفعات. |
| `settlements/` | index.php | وضعان: دليل أصحاب البيان (بطاقات) عند عدم البحث، وعند أي بحث/فلترة يعرض **البوالص المستهدفة نفسها** (جدول قابل للتحديد + عمود صاحب البيان) للدفع الجماعي. |
| | owner.php | صفحة صاحب البيان: هيرو + 6 KPI إحصاءات فورية + بحث/فلترة موسّعة + جدول بوالصه. |
| | _bills.php | جزء مشترك: جدول البوالص القابل للتحديد + شريط الدفع الجماعي + JS (يستدعيه index في وضع البحث و owner). |
| | show.php | سداد بوليصة واحدة + سجل الدفعات القابل للحذف. |
| `movements/` | index.php | قائمة الحركات بفلاتر + مجاميع مصروف/إيصال/خصم. |
| | form.php | محرّر حركة بجدول بنود ديناميكي ومجاميع حيّة (JS). |
| `shamil/` | invoice.php | فاتورة A4 مستقلّة للطباعة/PDF: رأس ذهبي + بطاقات + بنود + تفقيط (`tafqit`) + توقيعات + watermark. |
| `expenses/` | index.php | سجل موحّد: بطاقات ملوّنة بالمصدر + فلاتر + جدول (اليدوي قابل للتعديل، الباقي link ↗️). |
| | form.php | مصروف يدوي + toggle فيزا. |
| `closing/` | index.php | تقفيل يوم مختار: 4 KPI + مصروفات اليوم + عهدة اليوم + معادلة الترحيل. |
| | print.php | نسخة طباعة A4 landscape. |
| `crud/` | index.php | جدول مدفوع بالإعدادات: أعمدة من تعريف الحقول + فلاتر + ترقيم + مجاميع تذييل. |
| | form.php | نموذج مدفوع بالإعدادات مجمّع بـ identity/vehicle/revenue/expense/result؛ يربط `data-calc`/`data-vat` للحساب الحيّ. |
| `directory/` | index.php | تبويبات وكلاء/شركات + شبكة بطاقات. |
| | agent.php | بروفايل وكيل: hero + إحصاءات + الشركات الممثَّلة + أذونات + فواتير. |
| | company.php | بروفايل شركة: hero + الوكلاء الممثِّلون + أذونات + فواتير. |
| `balances/` | index.php | عمودان قابلان للتعديل: أرصدة العملاء (له) + الموردين (عليه). |
| `search/` | index.php | نتائج بحث مجمّعة بالكيان + رابط فتح. |
| `reports/` | index.php | لوحة تقارير: فلتر تاريخ + 5 KPI + 6 تبويبات + رسوم Chart.js. |
| | client.php | بروفايل تقرير عميل واحد + رسم شهري + دفتر بوالص. |
| | print.php | نسخة طباعة A4 للتقارير التشغيلية. |
| `settings/` | index.php | 4 تبويبات: عام / القوائم / مراحل الأذونات / الصيانة (نسخ احتياطي + تطبيع أسماء). |
| `errors/` | 404.php | صفحة "غير موجودة" مستقلّة. |

---

## 3. نظام التصميم — `public/assets/css/app.css` (779 سطر)

نظام تصميم يدوي واحد (بدون framework)، تعليقاته عربية.

### اللوحة اللونية (CSS custom properties)
- **الهوية:** `--navy:#15355B`, `--navy-dark:#0E2440`, `--navy-mid:#1E4B7A`؛ `--gold:#C9A227`, `--gold-light:#F3E9C6`.
- **دلالية:** `--green:#1E6E3C`, `--red:#9B2C2C`, `--teal:#14746F`, `--purple:#5B3E7A`.
- **محايدة:** `--ink:#1A2433` (نص), `--grey:#5A6472`, `--line/--line2` (حدود), `--bg:#F4F6F9`, `--card:#FFFFFF`, `--zebra:#F7F9FB`, `--input:#FFFDF3` (تعبئة إدخال دافئة).
- **أخرى:** `--shadow/--shadow-lg`, `--radius:14px`, `--sidebar-w:248px`. الخط: `'Segoe UI','Tahoma',system-ui,...`، جذر 15px.

### RTL
`body{direction:rtl}` عالميًا؛ كل شيء بـ **logical properties** (`inset-inline-start/end`, `margin-inline-start`, `border-inline-start`) — لا حاجة لورقة LTR منفصلة.

### التخطيط
- الهيكل flexbox؛ الأقسام CSS Grid (`.kpi-grid`, `.counters`, `.charts`, `.form-grid`, `repeat(auto-fill, minmax(...))`).
- **Responsive:** breakpoints 1100px و820px؛ ≤820px الـ sidebar off-canvas + hamburger.
- **الطباعة:** `@media print` يخفي sidebar/topbar/actions (صفحات الفاتورة/التقفيل/التقارير المستقلّة تحمل print CSS خاصًّا).

### عائلات المكوّنات
- بطاقات KPI بحدود علوية ملوّنة، عدّادات، بلاطات إجراء (`.qa` بـ 9 تدرّجات).
- جداول بيانات (`table.data`): ترويسات navy لاصقة بألوان مجموعات (`.g-identity/.g-vehicle/.g-revenue/.g-expense/.g-result`)، صفوف zebra، تذييل navy/gold، حالات `.pos/.neg/.calc`.
- Pills: نوع (`.pill-sader/.pill-warid`)، حالة (`.st-paid/.st-partial/.st-unpaid`)، شارة فيزا (`.tl-visa-badge`).
- نماذج (`.form-card`, `.field.calc` للحقول المقفلة)، تايم لاين/stepper/accordions، حلقة تقدّم SVG، بطاقات directory.
- Confirm modal (`.confirm-overlay` + `.danger`) — يُدار من `app.js`.

---

## 4. الجافاسكربت — `public/assets/js/app.js` (139 سطر)

IIFE واحد، `'use strict'`, بدون تبعيات. أربع مسؤوليات:

1. **Sidebar موبايل** — `.menu-btn` يبدّل `.sidebar.open`؛ نقرة خارجية تُغلق (تحت 820px).
2. **Confirm modal (حفظ/حذف)** — يعترض **كل POST form** ويعرض modal قبل الإرسال، **إلا** GET/`.no-confirm`/`onsubmit` inline. `.confirm-delete` → نمط خطر. رسالة مخصّصة عبر `data-confirm`.
3. **★ الحساب الحيّ للحقول المقفلة** — على `form[data-calc]` (في `crud/form.php`). يعيد حساب الحقول المقفلة (🔒) لحظيًا. الحاسبات الأربع = **نفس معادلات `Calc.php`** بالضبط:
   - `bawalis`: `debt = freight − custody − transfer − discount + overnight_collection`.
   - `takhlis`: `total_statement = fees + expenses`; `remaining = total − payments`.
   - `generators`: `remaining = freight − payments`.
   - `shamil`: `vat = round((freight + data_idle) × vatRate, 2)` (rate من `data-vat`, افتراضي 0.14)؛ `invoice_total = freight + data_idle + vat − discount_ats + receipts`؛ `account_total = invoice_total − payments`؛ `profit_total = freight − actual_freight − expense`.
4. **حقول شرطية حسب نوع البوليصة** — على `[data-billtype]` (sader/warid): يُظهر/يخفي `[data-dep]` (رقم الشهادة للوارد vs رقم الإذن للصادر).

> **تفاعلات أخرى inline في الـ views** (مش في app.js): جدول بنود الحركة، double-click السداد، تبديل تبويبات التقارير/الإعدادات، ربط وكيل↔شركة.

### الرسوم البيانية
`public/assets/js/chart.umd.js` = **Chart.js منسوخ محليًا** (UMD رسمي). يُحمَّل عند الطلب فقط (اللوحة، التقارير، بروفايل العميل) عبر `<script>` متبوعًا بإعداد inline. Defaults مضبوطة لـ RTL (`legend.rtl`, `tooltip.rtl`).

> **تزامن المعادلات:** تعديل أي معادلة في JS لازم يتزامن مع `Calc.php` و`build_system.py`. راجع [06-business-logic.md](06-business-logic.md) §8.
