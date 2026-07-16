# 05 — قاعدة البيانات والهجرات

> **الدخول الحر و`daily_closings`:** عند التعديل على تاريخ مقفول يظل صف الإقفال موجودًا، ثم يعيد `Closing::refreshLockedChainFrom()` حسابه وكل الإقفالات اللاحقة بالترتيب، مع الحفاظ على `closed_by/closed_at`.

MySQL 8+/9+، InnoDB، `utf8mb4_unicode_ci`، timezone `+02:00` (مصر). قاعدة `almotawakel`.
الملف الأساسي: `database/schema.sql`.

> **★ تحذير: `schema.sql` متأخّر عن الهجرات.** الشكل الحقيقي وقت التشغيل = `schema.sql` + كل الهجرات مطبّقة. أعمدة موجودة في migrations **فقط**:
> - `is_visa TINYINT` على `permit_stages`, `manual_expenses`, `movement_items` (من migrate_v6).
> - `permits.shipping_co`, `permits.agency`, `permits.port` (من migrate_v6).
>
> الكود التشغيلي (`Movement.php`, `Expense.php`, `Permit.php`) يعتمد عليها. راجع [10-conventions-gotchas.md](10-conventions-gotchas.md).

---

## 1. مخطط العلاقات (ERD)

```
permits (الإصدار: إذن صادر / شهادة وارد)  ── العملية الأكبر، "الأب"
  ├─1:N─ permit_movements  (حركات متسلسلة داخل الإصدار)   [FK CASCADE]
  ├─1:N─ permit_stages     (بنود التايم لاين المالية)      [FK CASCADE]
  └─1:N─ bawalis           (البوالص؛ bawalis.permit_id, .movement_id)

bawalis (البوالص = كل بوليصة دين على الشركة)
  ├─1:N─ bill_payments     (دفعات السداد)                 [FK CASCADE]
  └─1:N─ movements         (bawalis.id ← movements.bill_id) [FK SET NULL]
                └─1:N─ movement_items (بنود مصروف/إيصال/خصم) [FK CASCADE]

agents (الوكلاء = عملاؤنا)  ──M:N── companies (الشركات = المستفيدة)
                              عبر agent_company  [FK CASCADE على الطرفين]

مستقلة / مربوطة بالاسم النصّي فقط (بدون FK):
  takhlis, generators, shamil, manual_expenses,
  driver_custody, balances, lookups, settings, users

معزولة تمامًا (أرشيف — لا يقرأها أي حساب/تقفيل/عهدة/تقرير):
  legacy_bawalis  (بوالص النظام القديم المستوردة من Excel — عرض/بحث فقط)

debt_accounts (الدائنون: الكشف والضمان + مديونيات أخرى)
  └─1:N─ debt_payments  (دفعات صادرة للدائن)  [FK CASCADE]
```

> **★ Foreign Keys مُعلَنة فقط على:** `bill_payments→bawalis`, `movements→bawalis`, `movement_items→movements`, `permit_movements→permits`, `permit_stages→permits`, `agent_company→agents/companies`.
> **كل ما عداها مربوط بمطابقة أسماء نصية** (company/client/permit_no) — وده سبب وجود موديل `Maintenance` للتطبيع، ومصدر هشاشة بنيوية.

---

## 2. الجداول

### `users` — حسابات الدخول
`id` PK, `username` (unique), `password_hash`, `display_name`, `created_at`.

### `settings` — إعدادات key/value
PK `setting_key`, `setting_value` TEXT (موسّع ليحمل JSON، مثل إعداد التايم لاين). مبذور: `company_name`, `vat_rate='0.14'`, `report_year='2026'`, `currency='ج.م'`.

### `lookups` — كل القوائم المنسدلة
`id` PK؛ `list_type`؛ `value`. Unique `(list_type, value)`.
أنواع القوائم (14): `classification, company, destination, driver, car_owner, client, container_type, shipping_co, agency, port, mv_expense, mv_receipt, mv_discount, expense_cat`.

### `bawalis` — البوالص (قلب النظام)
**كل بوليصة = دين على الشركة + سجل محاسبة رحلة شاحنة.** أهم الأعمدة:
- **ربط:** `permit_id`→permits, `movement_id`, `bill_type` (`sader`/`warid`).
- **هوية:** `bill_date`, `bill_number`, `cert_number` (للوارد), `permit_no` (للصادر), `declaration_owner`, `classification`, `company`, `destination`, `container_no`, `container_type`, `container_count`.
- **سيارة:** `car_no`, `driver`, `car_owner`.
- **إيراد/تحصيل:** `freight` (نولون), `custody` (عهدة), `transfer` (تحويلة), `discount` (خصم، افتراضي **200**), `overnight_collection` (بياتة), `payment` (دفعة قديمة).
- **مصروفات (أثرية — انتقلت لـ movements):** `driver_share, assistant_share, overnight_expense, expense, cards, tahia_masr, thabtan, z_kawtsh, permits, kawtsh, other_expense, gas`.
- **محسوبة (تُحدَّث عند الحفظ):** `debt`, `paid`, `remaining`, `pay_status` (paid/partial/unpaid).
- **وسم البوالص القديمة (v26):** `is_legacy=1`. في النسخ المستوردة يكون `legacy_id` = رقم صف المصدر `legacy_bawalis.src_row`؛ أما البوليصة القديمة المضافة يدويًا من `/bawalis/new-legacy` فتحمل `legacy_id=NULL`. كلاهما داخل سجل البوالص والسداد وكشوف أصحاب البيان والمديونيات، ومستبعد صراحةً من العهدة والتقفيل والمصروفات ولوحة التحكم والتقارير والدليل والمساعد، وبلا إذن أو حركة.
- **تجميعات أثرية مؤرشفة:** `total_collection, total_expense, custody_remaining, car_net, profit_pct`.
- Indexes: date, company, pay_status, declaration_owner, permit_id.

### `legacy_bawalis` — أرشيف البوالص القديمة — من migrate_v15
**جدول مصدر معزول تمامًا** لبوالص النظام القديم المستوردة من `old-system-data.xlsx` (شيت «بوالص»، 1736 بوليصة، 2026-01-01 → 2026-07-06). بلا Foreign Keys ولا يدخل بنفسه أي حساب؛ وتظل وحدة `/legacy` للعرض والبحث والكشف الأرشيفي. منذ v26 تُنسخ صفوفه مرةً واحدة إلى `bawalis` بوسم `is_legacy=1` للمتابعة والتعديل والسداد، مع استمرار عزل النسخ المُرحَّلة عن كل الحسابات التشغيلية.
- يحمل كل حقول البوليصة القديمة بأمانة: هوية (`declaration_owner, bill_date, bill_number, classification, company, destination`) + سيارة (`container_no, car_no, driver, car_owner`) + إيراد (`freight, custody, transfer, discount, overnight_collection, remaining, payment, total`) + مصروفات (`driver_share…gas, other_expense`) + محسوبة قديمة (`total_expense, custody_remaining, car_net`) + `src_row` (تتبّع صف Excel الأصلي).
- **توحيد المسميات عند الاستيراد** (سكربت `database/export_legacy_bawalis.py`): إزالة علامة تكرار «خ» النهائية من `declaration_owner` («احمد عبدو خ»→«احمد عبدو»)، وإزالة «ق» المنفصلة فقط لو الأساس موجود («حاتم البحر ق»→«حاتم البحر»)، ودمج تهجئات الأسماء بمفتاح `normkey` (نفس منطق `Maintenance`). لا تُطبَّق قاعدة خ/ق إلا على `declaration_owner` (في باقي الأعمدة «رزق/صديق/الشيخ» أحرف أصيلة).
- مبذور عبر `bin/import_legacy_bawalis.php` (يقرأ `database/legacy_bawalis.json`، DELETE ثم إعادة تحميل داخل معاملة — idempotent).
- **استبعاد المكرَّر مع النظام الحي (في سكربت الاستيراد):** شيت الإكسل استمرّ يُكتَب فيه بالتوازي بعد بدء النظام الحي (2026-07-07)، فآخر بوالصه مسجَّلة أيضًا في جدول `bawalis` الحي. سكربت الاستيراد يستبعد تلقائيًّا أي بوليصة **رقمها موجود في `bawalis` وتاريخها ≥ أول يوم في النظام الحي** (مصدر الحقيقة لها = النظام الحي؛ آخر تشغيل استبعد 42). لذلك حدّ الأرشيف الزمني = 2026-07-06.
- **إصلاح سنة 1900 (في سكربت التصدير):** صفوف أُدخلت في الإكسل بتاريخ سنة 1900 (خطأ إدخال؛ اليوم/الشهر صحيحان) تُصحَّح إلى 2026 تلقائيًّا في `dt()`.
- **تحديث الأرشيف من نسخة إكسل أحدث:** انسخ القديم لنسخة احتياطية مؤرَّخة (`old-system-data.BACKUP-<ts>.xlsx`) ثم ضع الملف الجديد مكان `old-system-data.xlsx` وشغّل التصدير فالاستيراد (أو مرّر مسارًا مباشرًا عبر `OLD_XLSX=...`). السكربت يقرأ الصفوف حتى آخر صف فعلي (بلا حد ثابت).
- **دلالة الأعمدة المالية:** «باقى» (`remaining`) = صافي مستحق البوليصة لصاحب البيان (نولون − عهدة − خصم + تحويلة) **قبل** الدفع، و«دفعة» (`payment`) = ما استلمه فعلاً، فالباقي الفعلي = `remaining − payment`. عمود «إجمالى» (`total`) **تراكمي من الإكسل** (running total) فلا يصلح لأي جمع per-bill.
- **كشف حساب أرشيفي** (قراءة فقط، لا يُحفَظ): من صفحة `/legacy` فورم باسم صاحب البيان مع **اقتراح أسماء يتحمّل الأخطاء الإملائية** (`GET /legacy/owners` JSON → `LegacyBawali::ownerSuggest`: تطابق normkey > بادئة > احتواء > Levenshtein متعدّد البايتات بحد تشابه 0.5، والمتشابه إملائيًّا يحمل `kind='similar'`) ثم معاينة/طباعة A4 (`GET /legacy/statement?owner=` → `LegacyBawali::ownerStatement`: يدمج كل تهجئات الاسم المتطابقة بعد normkey، ويقسم البوالص مسدّدة/عليها باقٍ حسب `remaining − payment`، مع إجماليات وتفقيط «الباقي له»).

### `takhlis` — التخليص الجمركي
`cert_date, cert_name, cert_number, fees` (الأتعاب), `expenses` (المصروفات), `payments`, محسوبة `total_statement` + `remaining`.

### `generators` — نقل المولدات
`source` (`egycool`/`shujaie`), `gen_date, company, address, permit_no, gen_count, freight` (النولون), `payments`, محسوبة `remaining`.

### `shamil` — الفواتير الشاملة
`invoice_date, statement_no, invoice_no, permit_no, client, company, destination, containers` • `freight, data_idle, discount_ats, receipts, payments, actual_freight, inspection_guarantee, expense, other_profit` • محسوبة: `vat, invoice_total, account_total, profit_total`.

### `balances` — أرصدة العملاء/الموردين
`party_type` (`client`/`supplier`), `name, balance, note`.

### `bill_payments` — دفعات سداد البوالص
`bill_id` (FK→bawalis, **CASCADE**), `pay_date, amount, note`. منها تُشتقّ `paid`/`remaining`/`pay_status` على bawalis.

### `movements` — الحركات المربوطة ببوليصة
`movement_date, bill_id` (FK→bawalis, **SET NULL**), `driver, car_owner, shipping_co, agency` (توكيل), `port, note`, محسوبة `total_expense/total_receipt/total_discount`.

### `movement_items` — بنود الحركة
`movement_id` (FK→movements, **CASCADE**), `item_type` (`expense`/`receipt`/`discount`), `category, amount, note`. + `is_visa TINYINT` (من v6).

### `driver_custody` — عُهد السائقين
`custody_date, driver, amount, note`.

### `manual_expenses` — المصروفات اليدوية
`exp_date, category, beneficiary` (المستفيد), `amount, note`. + `is_visa` (من v6).

### `manual_custody` — الإضافات اليدوية لرصيد العهدة (توب-أب) — من migrate_v11
`cust_date, driver/note` (البيان/المصدر — اختياري)`, amount`. **إضافات يدوية تزيد رصيد عهدة المكتب** (محفظة العهدة، صفحة `/custody`). كل صف = مبلغ يوضَع في محفظة العهدة (+). تُدار من صفحة **رصيد العهدة**. الموديل: `App\Models\Custody` (توب-أب + حساب الرصيد/السجل).

### `daily_closings` — الإقفال اليومي والترحيل — من migrate_v18
`close_date` (**UNIQUE** — صفّ لكل يوم متقفل؛ **وجود الصف = اليوم متقفل**), `opening` (رصيد بداية اليوم = إقفال أمس), `topups` (عهد مضافة), `expenses` (مصروفات اليوم بلا فيزا), `driver_custody` (عهد سائقين بعد خصم 100), `closing_balance` (= opening+topups−expenses−driver_custody), `is_locked, closed_by, closed_at, note`. **قيم مجمّدة** تُخزَّن وقت الإقفال ولا تتغيّر بتعديل البيانات لاحقًا. الموديل: `App\Models\Closing` (الرصيد الحالي/المرحّل يُرتكزان على آخر صف هنا **ويخصمان المصروفات**؛ `guard()` يمنع الكتابة على أي يوم ≤ آخر يوم متقفل). الإقفال تسلسلي من الأقدم، الفتح (حذف الصف) من الأحدث. **بلا FK** — يقرأ حيًّا من `manual_custody/permit_stages/manual_expenses/bawalis` لكن لا يُعدّلها.

### `daily_closing_reconciliations` + `daily_closing_reconciliation_clients` — مطابقة اليدوي — من migrate_v28
الرأس صف واحد لكل `close_date`: إجمالي المصروفات العامة اليدوية + ملاحظة + آخر مستخدم/وقت. جدول العملاء يحفظ صفًا لكل `(close_date, client_key)` فيه لقطة اسم العميل و`manual_total` والملاحظة. **لا يدخلان أي حساب مالي**؛ `DailyReconciliation` يجمع حيًّا كل إيصالات ومصروفات `permit_stages` النقدية في اليوم حسب `permits.client` (كل أذونات العميل = صف واحد)، ويقارنها باليدوي. الفيزا معلوماتية ومستبعدة. لا يقبل الحفظ لعميل بلا حركة نقدية في نفس اليوم، وواجهة الإقفال تشترط مطابقة صفرية قبل الإغلاق.

### `permits` — الإصدارات (الأب)
`permit_type` (`sader`/`warid`), `permit_no, permit_date, company, client` (الوكيل), `destination, containers` (يحرّك حساب كشف وضمان), `note, status` (`open`/`closed`). + `shipping_co/agency/port` (من v6). + `inspection_rate`/`guarantee_rate` (من v9 — **سعر الكشف والضمان لكل إذن على حِدة**، NULL = غير مُدخَل، لا قيمة افتراضية؛ الأذونات القديمة عُبّئت بـ20 في الترحيل). + `container_type` (من v16 — **تصنيف الحاويات على مستوى الإذن**، يُملأ تلقائيًا في نموذج البوليصة داخل الحركة ويظل قابلاً للتعديل لكل بوليصة؛ يشارك قائمة `container_type`).

### `permit_movements` — حركات داخل الإصدار
`permit_id` (FK→permits, **CASCADE**), `seq, note`. كل حركة لها تايم لاين كامل خاص.

### `permit_stages` — بنود التايم لاين المالية
`permit_id` (FK→permits, **CASCADE**), `movement_id` (NULL = بند على مستوى الإصدار), `stage_key, direction` (`expense`/`revenue`، فقط للـ custom), `amount, entry_date` (**يحدّد أي يوم تقفيل تُصيبه**), `ref_no, note, status` (`done`/`pending`), `attachment`. + `is_visa` (من v6). + `actual_amount` (من v9 — **السعر الفعلي الذي يُحاسَب به العميل**، لكل البنود عدا البوالص والرسوم الأولية؛ NULL = لم يُدخَل). + `qty` (من v17 — **كمية الإيصال**، الافتراضي 1؛ **`amount` المخزَّن = سعر الوحدة × qty = الإجمالي** الذي تقرأه كل الحسابات، وفورم الإيصال يُدخِل سعر الوحدة والكمية ويعرض الإجمالي حيًّا. المراحل الأخرى لا تُرسِل qty فتبقى 1).
> **stage_key بعد v14:** `primary_fees|receipts|custom` — **دُمجت مراحل الإيصالات الخمس (`takhsis|hemola|fees|ta3teeq|hay2a`) في بند واحد متعدّد `receipts`** (مصروف؛ اسم كل إيصال محفوظ في `note`). سابقًا (v9) دُمج `extra` داخل `custom` («مصروفات إضافية»، يقبل مصروف أو محصّل).
> مرحلتا "bills" و"generators" **غير مُخزّنتين هنا** — تُشتقّان من `bawalis` و`generators`.

### `permit_invoices` — فواتير العملاء الخاصة بالأذونات — من migrate_v10
**النظام الأول للفوترة.** لقطة ثابتة لكل فاتورة/كشف حساب صادر عن إذن. `permit_id` (FK→permits, **CASCADE**), `doc_no` (رقم نظامي تلقائي `MT-00001`), `doc_kind` (`invoice`=فاتورة / `statement`=كشف حساب), `taxable` (خاضعة للقيمة المضافة؟ — للفاتورة فقط)، `internal_no` (الرقم الداخلي/رقم الفاتورة — للضريبية)، `invoice_date`, لقطات (`client, company, destination, permit_no, permit_type, agency, port, containers`), `freight` (**النولون المُدخَل عند الفوترة — غير الفعلي المسجّل على البوالص**; يُخزَّن هنا إجمالي بنود النولون)، `vat_rate/vat_amount` (الضريبة تُحسب على النولون فقط)، `subtotal` (كل البنود)، `total`, `items_json` (لقطة البنود `[{label,qty,price,total,freight}]`), `note`. **(الأعمدة `permit_type/agency/port` أُضيفت ضمن migrate_v10 نفسه بحراسة idempotent.)**
> **البنود = بيان/كمية/سعر/إجمالي** (إجمالي=كمية×سعر). تُملأ من المصروفات الفعلية للإذن بقيمة `actual_amount` (وإلا `amount`) + الرسوم الأولية التلقائية، مع صف **النولون** أولاً (`freight=1`، البند الوحيد الذي تُحسب عليه الضريبة). المستخدم يضيف/يحذف/يعدّل البنود؛ أحياناً تُصدَر على النولون فقط. الفاتورة **لا تحتوي البوالص**. اللقطة (`items_json`) تجعلها ثابتة مهما تغيّر الإذن لاحقاً.

### `agents` — الوكلاء (عملاؤنا)
`name` (unique), `note`.

### `companies` — الشركات (المستفيدة)
`name` (unique), `note`.

### `agent_company` — جدول الربط M:N
PK مركّب `(agent_id, company_id)`, FK **CASCADE** على الطرفين. وكيل واحد = شركات كتير، وشركة = وكلاء كتير.

### `debt_accounts` — حسابات المديونيات (الدائنون) — من migrate_v7
الجهات التي **تدين لها الشركة**. `account_key` (unique، مفتاح ثابت للحسابات الخاصة)، `name`، `kind` (`inspection`/`manual`)، `opening` (أصل المديونية للحسابات اليدوية)، `note`.
- حسابان خاصّان مبذوران (مديونيتهما **محسوبة تلقائيًا** من الأذونات، غير مخزّنة في `opening`):
  - `account_key='inspection'` (**الكشف**, kind=`inspection`): = Σ (`containers`×`inspection_rate`). المستحق يُختار من الإعدادات (يبدأ فارغاً).
  - `account_key='guarantee'` (**الضمان**, kind=`guarantee`): = Σ (`containers`×`guarantee_rate`). المستحق = «ياسر أبو عمر / كونتراكت» (قابل للتعديل من الإعدادات).
  - عدد الكشوفات = عدد الضمانات = `ceil(containers/inspection_batch)`. الإعدادات: `inspection_rate`/`guarantee_rate`/`inspection_batch` (defaults 20/20/10). (كل واحد له دفعاته المستقلة في `debt_payments`.)
- الحسابات اليدوية (`kind='manual'`) = المديونيات الأخرى؛ أصلها = `opening`.

### `debt_payments` — دفعات المديونيات — من migrate_v7
`account_id` (FK→debt_accounts, **CASCADE**), `pay_date, amount, note`, `is_visa TINYINT`. **دفعات صادرة من الشركة للدائن** (اتجاه المال معكوس كباقي النظام). المتبقّي = أصل المديونية − Σ الدفعات.

### `reps` — المناديب — من migrate_v21
`name, phone, monthly_salary DECIMAL, hire_date, status ('active'|'inactive'), note` + timestamps. كارت كل مندوب. الرصيد (المستحق له) يُحسب حيًّا من `rep_entries`.

### `rep_entries` — بنود سجل المندوب — من migrate_v21
`rep_id, entry_date, kind, amount DECIMAL (يُخزَّن موجبًا)، is_visa, note, settlement_id (NULL حتى يتحاسب)` + timestamps. `kind` ∈ مستحقات **له (+)**: `salary|transport|expense_logistic|bonus` — وخصومات/صرف **عليه (−)**: `expense_deducted|deduction|advance|settlement`. **الرصيد = Σ(المستحقات) − Σ(الخصومات)**. الإشارة مشتقّة من النوع لا مخزَّنة (`Rep::sign`). **بس `advance`+`settlement` (Rep::CASH_KINDS) بتطلّع كاش** → تدخل `Expense` (مصدر reps) والعهدة/الإقفال.

### `rep_settlements` — تقفيلات حساب المندوب — من migrate_v21
`rep_id, period_from, period_to, total_due, total_deductions, total_advances, net, paid_amount, is_visa, note, settled_at, settled_by, pay_date`. لقطة تقفيل دوري (يدوي من–إلى): `net = due − deductions − advances`. عند التقفيل تُربَط بنود الفترة غير المتحاسبة بـ`settlement_id`، ويُنشأ بند `settlement` بتاريخ الدفع (كاش يخرج فعلاً).

---

## 3. الهجرات (schema evolution)

كل الهجرات **idempotent** (آمنة لإعادة التشغيل) وتستخدم حراسة `information_schema`. الترتيب:

| الهجرة | ماذا تفعل |
|--------|-----------|
| **migrate_v2** | نموذج "البوليصة = دين". يُنشئ `bill_payments, movements, movement_items, driver_custody`؛ يضيف أعمدة bawalis (`bill_type, cert_number, permit_no, debt, paid, pay_status`)؛ يحوّل الـ `payment` القديم لصف `bill_payments`؛ يحوّل الـ12 عمود مصروف لـ movement + movement_items؛ يعيد حساب debt (Calc) وpaid/status (Bill::recalc). |
| **migrate_v3** | نموذج "الإصدار/الإذن". يُنشئ `permits, permit_stages, permit_movements`؛ يضيف `bawalis.permit_id/movement_id/container_type/container_count`، `permits.containers`، `permit_stages.direction/movement_id`؛ يوسّع `settings.setting_value` لـ TEXT؛ يجمّع البوالص في إصدارات (نفس permit_no/cert + النوع = إصدار، مُرحّل `closed`)؛ ينقل بنود الحركة لـ permit_stages عبر خريطة category→stage_key. |
| **migrate_v4** | دليل الوكلاء/الشركات. يُنشئ `agents, companies, agent_company`؛ يبذرهم وروابطهم من `directory_seed.json` (52 وكيل)؛ يعيد تسمية العملاء القدامى للشكل القانوني عبر balances/permits.client/shamil.client؛ يعيد بناء قائمة `lookups.client` لتطابق 52 وكيل. |
| **migrate_v5** | وحدة المصروفات. يُنشئ `manual_expenses`؛ يبذر `expense_cat` (إيجار، مرتبات، كهرباء…). لا جداول أخرى — السجل الموحّد يقرأ حيًّا من الجداول الموجودة. |
| **migrate_v6** | بيانات شحن الإصدار + علم الفيزا. يضيف `permits.shipping_co/agency/port`؛ يضيف `is_visa TINYINT` لـ `permit_stages, manual_expenses, movement_items`؛ يبذر قائمة `port`. |
| **migrate_v7** | وحدة المديونيات. يُنشئ `debt_accounts` + `debt_payments`؛ يبذر حساب الكشف (`account_key='inspection'`). idempotent (حراسة `information_schema`). (هذه الجداول أُضيفت أيضًا لـ `schema.sql`.) |
| **migrate_v8** | فصل الكشف عن الضمان. يُنشئ حساب الضمان `account_key='guarantee'` = «ياسر أبو عمر / كونتراكت»؛ ويُفرِغ اسم مستحق حساب الكشف لو كان لا يزال الاسم الافتراضي القديم (كل حساب صار لمستحقه ودفعاته المستقلة). idempotent. |
| **migrate_v9** | ثلاث تحسينات على الأذونات: (1) `permits.inspection_rate/guarantee_rate` — **سعر الكشف والضمان لكل إذن** (يعبّئ الأذونات القديمة بالسعر الحالي 20 حفاظاً على أرقامها؛ الجديدة تبدأ فارغة). (2) `permit_stages.actual_amount` — **السعر الفعلي للعميل**. (3) دمج بنود `stage_key='extra'` داخل `custom` باتجاه `expense`. idempotent (حراسة `information_schema`). (الأعمدة أُضيفت أيضاً لـ `schema.sql`، لكن النقل/الدمج يحصل في الترحيل فقط.) |
| **migrate_v10** | **نظام فوترة العملاء الخاص بالأذونات (النظام الأول).** يُنشئ جدول `permit_invoices` (لقطة ثابتة لكل فاتورة/كشف حساب صادر عن إذن: نولون مُدخَل عند الفوترة + مصروفات فعلية مختارة + ق. مضافة على النولون فقط). idempotent (حراسة `information_schema`). (الجدول أُضيف أيضاً لـ `schema.sql`.) |
| **migrate_v11** | **رصيد العهدة (محفظة عهدة المكتب).** يُنشئ جدول `manual_custody` — إضافات يدوية (توب-أب) تزيد رصيد العهدة في صفحة `/custody`. الرصيد = Σ(الإضافات) − Σ(عهدة كل بوليصة − 100). idempotent (`CREATE TABLE IF NOT EXISTS`). |
| **migrate_v15** | **أرشيف البوالص القديمة (معزول).** يُنشئ جدول `legacy_bawalis` — بوالص النظام القديم المستوردة من Excel (وحدة `/legacy` عرض/بحث فقط). **بلا FK ولا يقرأه أي حساب** — لا يؤثّر على العهدة/التقفيل/التقارير. idempotent (`CREATE TABLE IF NOT EXISTS`). الاستيراد: `python3 database/export_legacy_bawalis.py` ثم `php bin/import_legacy_bawalis.php`. (v12=owner_statements، v13=إصلاح ديون البوالص، v14=توحيد بنود الإيصالات.) |
| **migrate_v16** | **تصنيف الحاويات على مستوى الإذن.** يضيف عمود `permits.container_type` (VARCHAR). يُدخَل في «بيانات الإذن» ثم يُملأ تلقائيًا في نموذج إنشاء البوليصة داخل الحركة (يظل قابلاً للتعديل لكل بوليصة؛ وإن تُرك فارغاً في `createBill` يُورَّث من الإذن). يشارك قائمة `container_type`. idempotent (حراسة `information_schema`). |
| **migrate_v17** | **كمية الإيصال.** يضيف عمود `permit_stages.qty` (INT، افتراضي 1). فورم الإيصال يُدخِل «سعر الوحدة» + «الكمية» والواجهة تعرض الإجمالي حيًّا؛ المخزَّن في `amount` = سعر الوحدة × qty (الإجمالي الذي يقرأه التقفيل/الفوترة كما هو، بلا أي تغيير في منطق الحسابات). عند التعديل يُعرَض سعر الوحدة = `amount ÷ qty`. المراحل الأخرى (رسوم أولية/مخصّصة) لا تُرسِل qty فتبقى 1. idempotent (حراسة `information_schema`). |
| **migrate_v18** | **الإقفال اليومي والترحيل (day-end close).** يُنشئ جدول `daily_closings` (صفّ لكل يوم متقفل بقيم مجمّدة: opening/topups/expenses/driver_custody/closing_balance + closed_by/closed_at). موديل `App\Models\Closing` يبني عليه: الرصيد الحالي والمرحّل يُرتكزان على آخر إقفال ويخصمان المصروفات. القفل صارم (لا كتابة على يوم متقفل). idempotent (`CREATE TABLE IF NOT EXISTS`). ترحيل الأيام القديمة: `php bin/backfill_closings.php <تاريخ-النهاية>`. |
| **migrate_v21** | **وحدة المناديب.** يُنشئ `reps` + `rep_entries` + `rep_settlements`. كارت مندوب + كشف حساب بطرفين + تقفيل دوري يدوي. الربط بالمصروفات/العهدة عبر `advance`+`settlement` فقط (تجنّب التحسيب المزدوج). idempotent (`CREATE TABLE IF NOT EXISTS`). (v12=owner_statements، v13=إصلاح ديون البوالص، v14=توحيد بنود الإيصالات، v19=dashboard_notes، v20=serial/custom_title لفواتير الأذونات.) |
| **migrate_v26** | **دمج آمن للبوالص القديمة داخل السجل الحي.** يضيف `bawalis.is_legacy` و`bawalis.legacy_id` (فريد) و`bill_payments.is_legacy`. التشغيل: `php bin/migrate_v26.php` ثم `php bin/import_legacy_to_live.php --dry-run` ثم الاستيراد الفعلي، والتحقق عبر `php bin/verify_legacy_merge.php`. المستورد transaction + idempotent: لا يستبدل أي تعديل لاحق، ولا ينشئ إذنًا أو حركة، ويرفض تصادم الأرقام مع البوالص الحالية. الدفعات التاريخية مقفلة، أما أي دفعة جديدة فتُسجَّل كدفعة حالية. الباقي القديم يدخل عمدًا في السداد/المديونيات/كشوف أصحاب البيان فقط، ولا يدخل العهدة/التقفيل/المصروفات/المؤشرات/التقارير. |
| **migrate_v27** | **صلاحية الدخول الحر + سجل التدقيق وسلة المحذوفات.** يضيف `users.can_free_edit`، وينشئ `audit_logs` (نوع العملية، المستخدم، الجدول/السجل، before/after JSON، IP) و`deleted_records` (لقطة قابلة للاستعادة). الترحيل idempotent، ويمنح المستخدم الإداري الأول صلاحية الدخول الحر افتراضيًا فقط. الدخول الحر يتجاوز قفل الأيام للمستخدم المخوّل مع تسجيله، ولا يغيّر معادلات العهدة أو التقفيل. |
| **migrate_v28** | **مطابقة مصروفات التقفيل مع اليدوي حسب العميل.** ينشئ `daily_closing_reconciliations` + `daily_closing_reconciliation_clients`. كل أذونات العميل ذات الحركة النقدية في اليوم تُجمع في صف واحد وإجمالي واحد، مع drill-down للأذونات والبنود. الفيزا معلوماتية، والمصروفات بلا إذن صف عام. لا تغيّر الجداول أي حساب؛ المطابقة شرط واجهة قبل الإقفال. |
| **migrate_v29** | **سجل الإدخالات وتدقيق التاريخ.** يضيف إلى `audit_logs` عمودي `work_date` (تاريخ جلسة العمل لحظة الكتابة) و`record_date` (تاريخ المستند المحفوظ) مع فهارس. `Audit::log` يستخرجهما تلقائيًا، فتستطيع `/audit?mode=entries` إظهار التطابق أو الاختلاف بلا خلط مع `created_at`. الترحيل idempotent. |
| **normalize_names** | (ليست هجرة schema) غلاف CLI فوق `Maintenance::normalizeNames()` لدمج تهجئات الأسماء المكرّرة (سائقين، ملاك سيارات، شركات، عملاء…). يدعم `--dry` (معاينة). |

---

## 4. `app/Schema.php` — تعريفات الكيانات (metadata)

المصدر الوحيد لبيانات الكيانات، يغذّي الموديلات والجداول والتحقّق والإجماليات والتقارير تلقائيًا. يغطّي **أربعة كيانات إدخال فقط:** `bawalis, takhlis, generators, shamil`.
- أنواع الحقول: `text | int | money | date | select | pct` (+ UI: `billtype, source, status`).
- مجموعات الحقول (تحرّك اللون): `identity | vehicle | revenue | expense | result`.
- `entities()` → التعريفات؛ `get($key)` → واحد؛ `inputFields($entity)` → الحقول القابلة للتعديل فقط (يستبعد `calc=>true`).
- كل تعريف يحمل: `table, route, title, subtitle, icon, calc` (أي دالة Calc), `date_field, search, sum_fields, list_fields, fields[]`.
- أعلام مميّزة: `bawalis` → `detail=>true` + `discount` افتراضي 200؛ `generators` → `has_source=>true`؛ `shamil` → `invoice=>true`.

---

## 5. ملفات البيانات (`database/`)

- **`directory_seed.json`** (~19KB): `agents` (52 اسم), `companies` (252 اسم), `links` (263 زوج [وكيل، شركة]), `renames` (7 تصحيحات تهجئة).
- **`old_data.json`** (~1.1MB): payload النقل من الإكسل. `bawalis` (1728 سجل، 28 حقل), `takhlis` (0), `generators` (49), `balances` (55), `lookups` (قيم distinct: classification 18, company 313, destination 124, driver 730, car_owner 310, client 47).
- **`export_old_data.py`** (~6KB): يقرأ `old-system-data.xlsx` (env `OLD_XLSX` أو الافتراضي)، مطابقة أسماء شيتات مرنة، يكتب `old_data.json`.
- **`backups/`**: نسخ SQL احتياطية.

---

## 6. ملاحظات schema مهمة

- **البوليصة "مدفوعة" فقط لو فيه دفعة حقيقية:** `Bill::statusOf()` — `paid<=0` → UNPAID حتى لو `debt<=0`. (منطق مقصود.)
- **أعمدة البوالص المصروفية الـ12** + `total_collection/total_expense/car_net/profit_pct` **أثرية/مؤرشفة** — المصروفات الحيّة الآن في `movements/movement_items`. لكن `Metrics::bawalisExpenses` و`byCompany` (car_net) لسه بيقرأوا الأعمدة القديمة للسجلات التاريخية.

---

## 7. تتبع الشحنات (v33) — جداول معزولة

`shipment_tracks` / `track_pings` / `driver_contacts`. **بيانات وصفية بحتة (إحداثيات + وقت) — مفيش أي عمود مالي، ولا تُقرأ من أي حساب/عهدة/تقفيل/تقرير مالي.** الربط بالبوليصة بالـ `bill_id` **بلا FK** (تحذير 3) فحذف أي طرف لا يكسر الآخر.

| الجدول | أهم الأعمدة | ملاحظات |
|--------|-------------|---------|
| `shipment_tracks` | `bill_id`, `token` (UNIQUE), `driver/car_no` (لقطة), `driver_phone`, `status` (active\|done\|cancelled), `expires_at`, `opened_at`, `last_ping_at`, `ping_count`, `base_url` | رحلة واحدة نشطة لكل بوليصة (يُفرَض في `Track::createForBill`، مش في القاعدة). `base_url` = الرابط العام وقت التوليد — يقارنه `Track::isStaleLink()` بالحالي لكشف الروابط الميتة. |
| `track_pings` | `track_id`, `lat/lng` DECIMAL(10,7), `accuracy_m`, `speed_kmh`, `address`, `source` (manual\|live), `dist_m`, `ip`, `user_agent` | `dist_m` = المسافة من النقطة السابقة (Haversine) تُحسب وقت الإدخال. `address` من Nominatim وقد يكون NULL. |
| `driver_contacts` | `driver` (UNIQUE), `phone` | يفتكر رقم واتساب كل سائق فيتعبّى تلقائيًا في المرة الجاية. |

إعدادات `settings` المرتبطة: `track_expire_hours` (48)، `track_live_secs` (30)، `public_base_url` (يكتبه `bin/set_public_url.php`).

### وجهات الخريطة (v34)

`destination_points` — إحداثيات مرجعية تُحسب منها المسافة المتبقية والوصول المتوقع. **وصفي بحت: لا يمسّ أي حساب، ولا يعدّل `bawalis.destination`** (المطابقة قراءة فقط).

| العمود | ملاحظات |
|--------|---------|
| `name` | الاسم القانوني |
| `normkey` UNIQUE | `Maintenance::normkey(name)` — يغطي «بنى سويف»→«بني سويف» و«الاسماعيليه»→«الإسماعيلية» |
| `aliases` | اختصارات مُوحّدة مفصولة بـ`\|` («العاشر»، «ب سويف»، «م نصر») |
| `lat` / `lng` | DECIMAL(10,7) — الـ45 المبذورة متحقَّق منها مقابل OpenStreetMap |
| `is_auto` | 1 = حدّدها النظام تلقائيًا وتستاهل مراجعة؛ أول تعديل يدوي يصفّرها |

إعدادات: `track_avg_speed_kmh` (45)، `track_road_factor` (1.3).
