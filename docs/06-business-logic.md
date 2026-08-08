# 06 — منطق الحسابات المالية

> كل المعادلات المالية في مكانين: **`app/Calc.php`** (حساب الصف الواحد عند الحفظ) و**`app/Models/Metrics.php`** (التجميعات والتقارير). المعادلات **مطابقة تمامًا لمعادلات الإكسل** (موثّق في docblock).
> راجع أيضًا [02-domain-glossary.md](02-domain-glossary.md) §2 لاتجاه المال المعكوس.

---

## 1. `app/Calc.php` — معادلات الصف الواحد

مساعد `n($v)` يحوّل لـ float (غير الرقمي → 0.0). الموزّع `apply($entity, $row)` يوجّه:
`bawalis→bawala`, `takhlis→takhlis`, `generators→generator`, `shamil→shamil`.

### 1.1 البوالص — `bawala()`
```
debt = freight − custody − transfer − discount + overnight_collection
     = نولون − عهدة − تحويلة − خصم + بياتة(تحصيل)
★ قاعدة النولون المؤجَّل:  freight ≤ 0  ⇒  debt = 0   (بلا نولون = ليست ديناً بعد)
```
- `debt` (دين البوليصة) هو المحسوب هنا فقط. **`paid`/`remaining`/`pay_status` لا تُحسب هنا** — تأتي من جدول `bill_payments` عبر `Bill`.
- **النولون المؤجَّل:** النولون قد يُدخَل في مرحلة لاحقة. البوليصة بلا نولون كانت تُنتج ديناً **سالباً** من الخصومات وحدها؛ لذا `freight ≤ 0 ⇒ debt = 0` وحالتها `pending` (انظر §2). نفس الجارد مُزامَن في `app.js`. (نظام الإكسل `build_system.py` لا يملك عمود `debt` — «باقي» فيه معادلة مختلفة لا تُنتج هذا السالب.)

### 1.2 التخليص — `takhlis()`
```
total_statement = fees + expenses          (إجمالي الكشف)
remaining       = total_statement − payments   (المتبقي)
```

### 1.3 المولدات — `generator()`
```
remaining = freight − payments             (المتبقي = النولون − الدفعات)
```

### 1.4 شامل + القيمة المضافة — `shamil()`
نسبة الضريبة من الإعدادات: `vat_rate` (افتراضي 0.14).
```
vat           = round((freight + data_idle) × vat_rate, 2)         (قيمة مضافة)
invoice_total = freight + data_idle + vat − discount_ats + receipts (إجمالي الفاتورة)
account_total = invoice_total − payments                           (إجمالي الحساب)
profit_total  = freight − actual_freight − expense                 (إجمالي ربح)
```
> `other_profit` مُخزّن لكن **لا يُضاف هنا** — يُجمَع منفصلًا في التقارير/Metrics.

---

## 2. `Bill.php` — منطق "البوليصة = دين"

يدير حالة السداد لـ `bawalis` باستخدام `bill_payments` (مش table wrapper). ثوابت `STATUS_PAID/PARTIAL/UNPAID/PENDING`.
- `paidOf(billId)` = `SUM(amount)` من bill_payments.
- **`statusOf($debt, $paid)` — قاعدة مهمة:** البوليصة "مدفوعة" فقط لو فيه **دفعة حقيقية**:
  - `paid <= 0` → **UNPAID** (حتى لو `debt <= 0`).
  - ثم `debt <= 0` → PAID.
  - `paid + 0.005 >= debt` → PAID.
  - غير كده → PARTIAL.
- **`statusFor($freight, $debt, $paid)`:** غلاف فوق `statusOf` — `freight ≤ 0 && paid ≤ 0` → **PENDING** («بانتظار النولون»، ليست ديناً)، وإلا `statusOf`. القاعدة تسري على البوالص الحالية والقديمة معًا؛ فالباقي السالب القديم الناتج عن عهدة/خصم قبل إدخال النولون لا يعني أن البوليصة مدفوعة. حالة `pending` تُستثنى تلقائياً من كل فلاتر `pay_status IN ('unpaid','partial')` (سداد البوالص/كشوف أصحاب البيان/المديونيات)، ولها شارة `.st-pending`.
- `recalc(billId)` — يقرأ `debt` **و`freight`**، يحسب `paid` + `remaining = debt − paid` + `statusFor(...)`، يكتبها في bawalis. (هجرة `migrate_v13` تعيد الحساب لكل البوالص الموجودة.)
- `addPayment` / `deletePayment` → insert/delete ثم `recalc`.

---

## 3. `Movement.php` — الحركات والبنود

- `ensureForBill(billId)` — يضمن وجود حركة للبوليصة (تُنشأ تلقائيًا مع البوليصة)؛ يسحب shipping_co/agency/port من **الإصدار** المرتبط.
- `saveItems(...)` — حذف ثم إعادة إدراج كل البنود (يتخطّى صفر/غير صالح)، يكتب `is_visa`، ثم `recalc`.
- `recalc(movementId)` — يعيد حساب `total_expense/total_receipt/total_discount` بجمع البنود حسب `item_type`.

---

## 4. `Permit.php` — محرّك الإصدارات

### 4.1 تعريف التايم لاين — `STAGES`
مرتّب. كل مرحلة لها `store` (`header`/`stages` في permit_stages/`bills` link/`generators` match), `dir`, `multi`, `scope` (`permit`/`movement`).
المراحل: `data, primary_fees, bills(link), receipts, custom, generators(link)`. `ALWAYS_ON = ['data','bills']`.

> **بعد v14:** مراحل الإيصالات الخمس المنفصلة (`takhsis/hemola/fees/ta3teeq/hay2a`) دُمجت في بند واحد متعدّد `receipts` (اسم كل إيصال في `note`). `expenseKeys()` صار = `primary_fees, receipts`. الحساب كله يمرّ عبر `expenseSql()`/`revenueSql()` المشتقّة، فلا حاجة لتعديل يدوي في المديونيات/التقفيل/الفوترة.

### 4.2 SQL المصروف/الإيراد (يُعاد استخدامها في Expense وMetrics)
- **`expenseSql($a)`** = `stage_key IN (المصروفات الثابتة) OR (stage_key='custom' AND direction='expense')`.
- **`revenueSql`** = `stage_key='custom' AND direction='revenue'`.

### 4.3 الرسوم الأولية = الكشف والضمان فقط — `primaryFeesDefault`
```
primary_fees المقترح = الكشف + الضمان
   الكشف  = containers × permits.inspection_rate   (★ لكل إذن، NULL = غير مُدخَل → 0)
   الضمان = containers × permits.guarantee_rate    (★ لكل إذن، NULL = غير مُدخَل → 0)
```
> **★ من v9: السعر لكل إذن** (عمودان `permits.inspection_rate/guarantee_rate`)، **لا سعر عام** ولا قيمة افتراضية — يُدخِله المستخدم في بيانات الإذن. `primaryFeesDefault` يُعيد `rates_set` (هل أُدخِل سعر واحد على الأقل)؛ العرض التلقائي للرسوم الأولية لا يظهر إلا إذا `rates_set=true` وعدد الحاويات > 0. الأذونات القديمة عُبّئت بـ20 في الترحيل. (أُزيل إعدادا `inspection_rate/guarantee_rate` العامّان من الإعدادات؛ بقي `inspection_batch` والمستحقّون.)

**السعر الفعلي (`actual_amount`):** لكل بند تايم لاين (عدا البوالص والرسوم الأولية) خانة «السعر الفعلي» = ما يُحاسَب به العميل. `Permit::totals` يضيف `charged` = Σ `actual_amount` على كل بنود الإذن (يظهر كبطاقة «السعر الفعلي (للعميل)»).
**لا يدخل الخصم هنا إطلاقاً** — الخصم (`bawalis.discount`, default 200) بند خاص بالبوليصة يُطرح في دين البوليصة فقط (§ حساب البوليصة)، وليس على الإذن أو الحركة. (سابقاً كان يُضاف كـ`base=200` ثابت في الرسوم الأولية — أُزيل.) `parseContainers()` يفسّر "2*40" / "٢×٤٠" (أرقام وفواصل عربية) → العدد قبل الفاصل.

**العرض التلقائي:** في التايم لاين (`manage.php` و`view.php`)، مرحلة «رسوم أولية» **تعرض تلقائيًا** تفصيل الكشف + الضمان وإجماليهما (مع بادج «تلقائي» والإجمالي في رأس المرحلة) طالما **لا يوجد بند مسجّل يدويًا** وعدد حاويات الإذن > 0 — بدل حالة «فارغة». أي بند يُدخَل يدويًا يَحلّ محلّ القيمة التلقائية.

### 4.4 `totals(permitId)` — تجميع الإصدار
`expense` = Σ permit_stages حيث expenseSql • `fees` (إيراد) = Σ حيث revenueSql • `custody, debt, remaining, bills` count من bawalis WHERE permit_id.

### 4.5 بيان سائق سيارة المكتب (v65)

```text
صافي النقلة = العهدة + العهد/التسويات الموجبة
            − المبالغ المرتجعة/التسويات السالبة
            − المصروفات التفصيلية
            − الإيصالات التي دفعها السائق من عهدته
```

`الرصيد النهائي = الرصيد السابق + Σ صافي النقلات والحركات العامة`.

المصروفات التفصيلية تُجمع حسب التصنيف إلى أعمدة `Calc::EXPENSE_COLS`. الإيصال الموجود في `permit_stages` لا يُنسخ كمصروف جديد: يُخصَّص منه مبلغ للبوليصة، ثم يحسب درج المكتب فقط `stage.amount − Σ تخصيصات السائق` عبر `OfficeDriverStatement::stageSafeAmountSql()`.

---

## 5. `Expense.php` — السجل الموحّد (افتراضي، UNION من 6 مصادر)

رؤية موحّدة لكل مصروف في النظام، تُسحب حيًّا وقت العرض (بدون تخزين مكرّر).
`SOURCES`: `manual, permits, bawalis, takhlis, shamil, debt`.

**`unionSql()`** = `UNION ALL` فوق:
1. `manual_expenses`.
2. `permit_stages` (بنود المصروف عبر `Permit::expenseSql`، مع `CASE` يحوّل stage_key لتسمية عربية).
3. `movement_items` حيث `item_type='expense'`.
4. `takhlis.expenses`.
5. `shamil.expense`.
6. `debt_payments` (دفعات المديونيات = فلوس صادرة للدائنين؛ category = «سداد الكشف/الضمان/مديونية» حسب `debt_accounts.kind`، party = اسم المستحق، `ref_id`=`account_id`). **نقدي وفيزا معاً** (يحترم `is_visa`). لا تؤثّر على التقفيل اليومي (له استعلاماته الخاصة).

الأعمدة الموحّدة: `source, ref_id, d, category, party, amount, note, is_visa`. **فقط `manual` قابل للتعديل** (الباقي link للأصل).
- `outerWhere` فلاتر: source, date from/to, visa (`only`/`cash` → `is_visa`), نص.
- `totals` = إجمالي كلّي + لكل مصدر.

---

## 5.1 مطابقة التقفيل حسب العميل — `DailyReconciliation` (v28)

- مصدر صفوف العملاء = بنود `permit_stages` التي يطابقها `Permit::expenseSql` وتاريخها `entry_date = يوم المطابقة` و`is_visa=0`.
- كل أذونات الاسم نفسه في `permits.client` تُجمع بعد `Maintenance::normkey` في صف عميل واحد، وبقيمة واحدة: `system_total = Σ الإيصالات + Σ مصروفات الإذن`.
- التفاصيل تحت الصف فقط تحتفظ بتقسيم الإذن/الحركة/نوع البند؛ الإدخال اليدوي **رقم واحد للعميل** بلا فصل إيصالات ومصروفات.
- المصروفات غير المرتبطة بإذن (`manual_expenses` + كاش المناديب `advance|settlement`) تُطابق في صف «مصروفات عامة بدون إذن».
- الفيزا معلوماتية ولا تدخل الفرق. لا تُقبل مفاتيح عملاء بلا حركة نقدية في اليوم، ولا يسمح `DailyClosingController::close` بالإقفال من الواجهة حتى تصبح كل الفروق صفرًا.

---

## 6. `Metrics.php` — التجميعات والتقارير

محرّك التجميع المالي "المطابق لمعادلات لوحة/تقارير الإكسل".

### 6.1 ★ `totals($from, $to)` — مؤشرات اللوحة الستة
> **اتجاه المال معكوس** — راجع [02](02-domain-glossary.md) §2.

| المؤشّر | الحساب |
|---------|--------|
| `revenue` (إيراد) | `bawalis.freight + shamil.invoice_total + takhlis.total_statement + generators.freight` |
| `expenses` (مصروفات) | `movements.total_expense + shamil.expense + takhlis.expenses` |
| `profit` (ربح) | `shamil.profit_total + shamil.other_profit + (Σ bawalis.freight − Σ movement.expense)` |
| `collections` (مدفوعات صادرة للعملاء) | `bill_payments + shamil.payments + takhlis.payments + generators.payments` |
| `custody` (عهدة) | Σ `bawalis.custody` |
| `outstanding` (دين على الشركة، غير مؤرّخ) | `bawalis.remaining + shamil.account_total + takhlis.remaining + generators.remaining` |
| `freight` | `bawalis + shamil + generators` freight |

### 6.2 باقي الدوال
- `counters()` — bawalis, invoices, containers, generators (Σ gen_count), certs.
- `permitsSummary()` — إجمالي/مفتوح/مقفول، `incomplete` (إصدار مفتوح ناقص بوالص أو مراحل أو فيه مرحلة pending)، حاويات، مصروف/عهدة اليوم.
- `monthly($year)` — سلسلة 12 شهر (revenue/expenses/profit/collections/freight/custody).
- `byCompany` — top-N بالنولون، net = car_net (bawalis) / profit_total (shamil).
- `clientDebts` — top-N متأخرات (bawalis.remaining + shamil.account_total)، `HAVING remaining>0`.
- `carPerformance`, `customerProfit` (تشغيلي بلا ربح), `expenseReport`, `paymentsReport`, `movementsReport`, `permitsReport`.
- `customerProfile($name)` — ماليّة عميل واحد + سلسلة 12 شهر.
- `bawalisExpenses` — توزيع الـ12 عمود مصروف الأثري.
- `generatorsComparison` — ايجي كول vs الشجيع.
- `supplierBalances`, `suppliersTotal`.
- `anchorDate()` — أحدث تاريخ نشاط (يثبّت الرسوم على بيانات حقيقية).
- `todayPulse`, `recentActivity` (UNION عبر 6 وحدات), `activityTrend`.

---

## 7. `Maintenance.php` — تطبيع الأسماء

يدمج تهجئات الأسماء المكرّرة/الخاطئة عبر الجداول (لأن الربط بالاسم النصّي بدون FK).
- `normkey($s)` — مفتاح تطبيع: يشيل التشكيل والتطويل، يوحّد الألف → ا، ى→ي، ة→ه، إلخ، يطبّق whitespace، lowercase. **ده يُعرّف "نفس الاسم".**
- `groups()` — أي `(table, column)` تتشارك نوع اسم، وقائمتها، و"المصدر القانوني المفضّل" (`agents` للعملاء، `companies` للشركات).
- `normalizeNames($dry)` — يعدّ استخدام كل تهجئة، يجمّعها بـ `normkey`، يختار canonical (المصدر المفضّل ثم الأكثر استخدامًا)، يحدّث كل المتغيّرات، ينظّف lookups. `--dry` = معاينة بلا كتابة.

---

## 8. ★ تحذير: تزامن المعادلات بين الأنظمة الثلاثة

نفس المعادلات مكتوبة في **3 أماكن** ولازم تتزامن عند أي تعديل:
1. **Excel** — داخل `build_system.py` (سلاسل معادلات Excel).
2. **PHP الخادم** — `app/Calc.php`.
3. **JavaScript العميل** — `public/assets/js/app.js` (الحساب الحيّ للحقول المقفلة).

تغيير معادلة في مكان بدون الآخرين = عدم اتساق بين ما يراه المستخدم لحظيًا (JS)، ما يُحفظ (PHP)، وملف الإكسل.
