# 04 — معمارية الويب (PHP MVC)

إطار PHP MVC **مكتوب يدويًا** (بدون framework خارجي، بدون Composer). namespace `App\` مُطابَق لمجلد `app/` عبر autoloader بسيط. PDO/MySQL. جلسة واحدة مشتركة. RTL عربي.

الجذر: `/Users/karimmokhtar/المتوكل/almotawakel-web`.

---

## 1. دورة حياة الطلب

```
1) public/index.php            ← Front Controller (نقطة الدخول الوحيدة)
   require app/bootstrap.php
2) app/bootstrap.php
   - spl_autoload_register  (PSR-4 لـ App\ → app/)
   - require Core/helpers.php
   - App::boot()             (تحميل config, timezone, UTF-8)
   - Auth::start()           (بدء الجلسة)
3) index.php يُنشئ Router ويُعرّف كل المسارات ($r->get/$r->post)
4) $r->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])
5) Router::dispatch()         يطابق (method+path) → "Class@action"
   Router::call()             يُنشئ App\Controllers\Class ويستدعي action(...params)
6) الـ action:
   - requireAuth()            (إعادة توجيه للـ login لو مش مسجّل)
   - requireCsrf()            (للـ POST — 419 عند الفشل)
   - يستدعي Schema/Calc/Models/Database
   - $this->render('view', $data)
7) Controller::render()       يلفّ الـ view في app/Views/layout.php
```

---

## 2. طبقة Core (`app/Core/`)

### `App.php` — حاوية التطبيق
- `boot()` — يحمّل `config/config.php`، يضبط timezone + `mb_internal_encoding('UTF-8')`.
- `config($key)` — قراءة إعدادات الملف.
- `basePath()` / `baseUrl()` / `url()` — بناء مسارات الملفات والروابط (يدعم تشغيل تحت مجلد فرعي؛ لو جذر الويب `public/` فالـ base = `''`).
- **`setting($key, $default)` / `setSetting()`** — قراءة/كتابة **جدول `settings`** بالكاش (إعدادات العمل القابلة للتعديل من الواجهة). `INSERT ... ON DUPLICATE KEY UPDATE`.

> **مصدران للإعدادات:** ملف `config.php` (بنية/اتصال/أسرار) مقابل جدول `settings` (إعدادات عمل تُعدَّل من الواجهة: اسم الشركة، نسبة الضريبة، سنة التقارير، الرصيد الافتتاحي، إعداد التايم لاين JSON).

### `Router.php` — راوتر بسيط
- المسارات = tuples مسطّحة `[method, path, handler]`. لا route names، لا groups، **لا middleware**.
- **تحويل النمط:** `{id}` → `([^/]+)` عبر regex، ثم anchored `#^...$#`. المجموعات الملتقطة تصير args للـ action بالترتيب.
- **أول تطابق يفوز (first-match-wins)** — **الترتيب حسّاس**. المسارات الجشعة `/{id}` مُسجّلة **آخرًا** عمدًا (مثال: `/permits/{id}/manage` قبل `/permits/{id}`). راجع [10-conventions-gotchas.md](10-conventions-gotchas.md).
- **404:** بدون تطابق → `errors/404` بدون layout.

### `Auth.php` — مصادقة بالجلسة
- حساب واحد مشترك.
- `start()` — تسمية الجلسة (`session_name` من config، افتراضي `almotawakel_session`) + بدؤها.
- `attempt($u, $p)` — بحث في `users` بالـ username، `password_verify()` ضد `password_hash`؛ عند النجاح يخزّن `$_SESSION['uid']`/`['uname']` + `session_regenerate_id(true)`.
- `check()` — صحّة `$_SESSION['uid']`. تُفرَض عبر `Controller::requireAuth()`.
- **CSRF:** `csrf()` يولّد token (32-byte hex في الجلسة)؛ `checkCsrf()` بـ `hash_equals`. تُفرَض عبر `Controller::requireCsrf()` (HTTP 419 عند الفشل).

### `Database.php` — PDO singleton فوق MySQL
- `conn()` — يبني DSN من `config('db')`، `ERRMODE_EXCEPTION`، `FETCH_ASSOC`، `EMULATE_PREPARES=false`. عند الفشل يموت بصفحة خطأ عربية تقترح `php bin/install.php`.
- `serverConn()` — اتصال بدون قاعدة محدّدة (للمثبِّت لـ `CREATE DATABASE`).
- مساعدات (كلها prepared statements): `all()` (كل الصفوف)، `row()` (صف/null)، `scalar()` (قيمة عمود)، `exec()` (عدد الصفوف المتأثرة)، `lastId()`.

### `helpers.php` — دوال عامة للـ views
`e()` (HTML escape)، `url()`، مُنسّقات الأرقام/العملة/التاريخ (`money`, `money0`, `intf`, `pct`, `dfmt`)، `old()`، `csrfField()`، `flash()`، `redirect()`، `signClass()`، `initials()`، و**`tafqit()`** (تفقيط المبالغ لكلمات عربية — للفواتير).

### `Controller.php` (القاعدة)
- `render($view, $data, $layout=true)` — يستخرج البيانات للـ scope، يلتقط ناتج الـ view في buffer، يلفّه في `layout.php`.
- `requireAuth()` — يعيد التوجيه للـ login لو مش مسجّل.
- `requireCsrf()` — HTTP 419 عند فشل الـ token.

---

## 3. المعماريّة المدفوعة بالبيانات: `CrudController`

`abstract class CrudController extends Controller` — كنترولر عام لكل شاشات الإدخال، مدفوع بتعريفات `Schema.php`. الكيانات الأربعة: `bawalis`, `takhlis`, `generators`, `shamil`.

الفئات الفرعية تضبط `protected string $key` فقط. القاعدة توفّر:
- `index` — بحث/فلترة/ترقيم صفحات (LIKE لكل عمود، فلتر سنة/شهر/مصدر، حجم صفحة 50، `SUM` للإجماليات، قائمة سنوات distinct).
- `create` / `store` / `edit` / `update` / `destroy`.
- تجمّع/تحوّل `$_POST` حسب نوع الحقل، تشغّل `Calc::apply()` للحقول المحسوبة، تتعلّم قيم القوائم الجديدة عبر `Lookup::add()`، وتوفّر hook `afterSave()`.
- **Views:** `crud/index.php`, `crud/form.php`.

---

## 4. جدول المسارات الكامل

من `public/index.php`. **كل action (ما عدا شاشات المصادقة) يستدعي `requireAuth()`؛ كل POST يستدعي `requireCsrf()`.**

مسارات CRUD للكيانات الأربعة تُولَّد في حلقة على `[bawalis, takhlis, generators, shamil]`.

### المصادقة واللوحة
| Method | Path | Handler |
|--------|------|---------|
| GET | `/login` | AuthController@showLogin |
| POST | `/login` | AuthController@login |
| POST | `/logout` | AuthController@logout |
| GET | `/` | DashboardController@index |
| POST | `/dashboard/quick-entries` | DashboardController@quickEntries — تطبيق دفعة إيصالات/مصاريف داخل الأذونات |

### CRUD عام (لكل كيان من bawalis/takhlis/generators/shamil)
| Method | Path | Handler |
|--------|------|---------|
| GET | `/{entity}` | `{Ctrl}@index` |
| GET | `/{entity}/new` | `{Ctrl}@create` |
| POST | `/{entity}` | `{Ctrl}@store` |
| GET | `/{entity}/{id}/edit` | `{Ctrl}@edit` |
| POST | `/{entity}/{id}` | `{Ctrl}@update` |
| POST | `/{entity}/{id}/delete` | `{Ctrl}@destroy` |

إضافات خاصة: `GET /bawalis/{id}` → `BawalisController@show` • `POST /bawalis/bulk-freight` → تحديث نولون مجموعة • `POST /bawalis/bulk-owner` → تغيير صاحب البيان لمجموعة • `GET /bawalis/check-number` → فحص الرقم حيًا • `POST /bawalis/bulk-paste` → لصق دفعة من Excel • `GET /bawalis/new-legacy` + `POST /bawalis/legacy` → إضافة بوليصة قديمة يدوية معزولة • `GET /shamil/{id}/invoice` → `ShamilController@invoice`. كل المسارات الحرفية للبوالص يجب أن تسبق `/bawalis/{id}` الجشع.

> **أدوات الجداول العامة:** `public/assets/js/app.js` يفعّل تلقائيًا كل `table.data` و`table.pv-tbl`: ترتيب الأعمدة، إخفاء/إظهار، كثافة مدمجة/مريحة، تثبيت العمود الأول، تغيير عرض الأعمدة بالسحب، وتصدير الأعمدة الظاهرة CSV. الاختيارات تُحفظ في `localStorage` لكل مسار وبصمة عناوين الجدول، وأعمدة checkbox تظل ظاهرة حتى لا تتعطل إجراءات المجموعات. قوائم CRUD ترتّب من الخادم حتى يشمل الترتيب كل الصفحات، و`MutationObserver` يفعّل الأدوات نفسها للجداول المضافة عبر AJAX أو Popup. استخدم `data-no-sort`/`data-no-hide` على `<th>` للاستثناء، أو `data-table-tools-off` على الجدول لتعطيل الميزة كاملة.

### المصروفات
| Method | Path | Handler |
|--------|------|---------|
| GET | `/expenses` | ExpenseController@index |
| GET | `/expenses/new` | ExpenseController@create |
| POST | `/expenses` | ExpenseController@store |
| GET | `/expenses/{id}/edit` | ExpenseController@edit |
| POST | `/expenses/{id}` | ExpenseController@update |
| POST | `/expenses/{id}/delete` | ExpenseController@destroy |

### إصدار الفواتير (التبويب المستقل)
| Method | Path | Handler |
|--------|------|---------|
| GET | `/invoices` | InvoiceController@index (سجل كل الفواتير + بحث/فلترة/إجماليات) |
| GET | `/invoices/new` | InvoiceController@pick (اختيار الإذن) |

> الإصدار/الطباعة/الحذف نفسها تحت `/permits/{id}/invoice` و`/permits/invoice/{invId}` (انظر مجموعة الأذونات). هذا التبويب هو البوابة والسجل.

### الإصدارات / الأذونات (أكبر مجموعة)
| Method | Path | Handler |
|--------|------|---------|
| GET | `/permits` | PermitController@index |
| GET | `/permits/new` | PermitController@create |
| POST | `/permits` | PermitController@store |
| GET | `/permits/{id}/edit` | PermitController@edit |
| POST | `/permits/{id}` | PermitController@update |
| POST | `/permits/{id}/delete` | PermitController@destroy |
| POST | `/permits/{id}/stage` | PermitController@saveStage |
| POST | `/permits/{id}/link-bill` | PermitController@linkBill |
| POST | `/permits/{id}/create-bill` | PermitController@createBill |
| POST | `/permits/{id}/unlink-bill` | PermitController@unlinkBill |
| POST | `/permits/{id}/delete-bill` | PermitController@deleteBill |
| POST | `/permits/{id}/copy-bill` | PermitController@copyBill |
| POST | `/permits/{id}/close` | PermitController@close |
| POST | `/permits/{id}/movement` | PermitController@createMovement |
| POST | `/permits/movement/{mid}/delete` | PermitController@deleteMovement |
| POST | `/permits/stage/{sid}/delete` | PermitController@deleteStage |
| GET | `/permits/invoice/{invId}` | PermitController@invoicePrint |
| POST | `/permits/invoice/{invId}/delete` | PermitController@invoiceDelete |
| GET | `/permits/{id}/invoice` | PermitController@invoiceForm |
| POST | `/permits/{id}/invoice` | PermitController@invoiceStore |
| GET | `/permits/{id}/manage` | PermitController@manage |
| GET | `/permits/print-batch?ids=1,2,3` | PermitController@printBatch — طباعة عدة أذونات دفعة واحدة (مسجّل قبل الجشع `/{id}`) |
| GET | `/permits/{id}/print` | PermitController@printSheet — كشف مراجعة A4 كامل (بلا layout) |
| GET | `/permits/{id}` | PermitController@view |

> **ترتيب حسّاس:** `/permits/{id}/manage` و`/permits/{id}` مُسجّلان **بعد** كل المسارات الأخصّ لتفادي التعارض.

### باقي الوحدات
| Method | Path | Handler |
|--------|------|---------|
| GET | `/tracking` | TrackController@index (لوحة تتبع الشحنات — خريطة + كروت) |
| GET | `/tracking/new` | TrackController@pick (اختيار البوليصة) |
| POST | `/tracking` | TrackController@store (توليد الرابط) |
| GET | `/tracking/feed` | TrackController@feedAll (JSON — كل السيارات) |
| GET | `/tracking/{id}/feed` | TrackController@feed (JSON — رحلة واحدة) |
| POST | `/tracking/{id}/status` | TrackController@setStatus |
| POST | `/tracking/{id}/renew` | TrackController@renew (تجديد الرابط بعد تغيّر النفق) |
| POST | `/tracking/{id}/phone` | TrackController@updatePhone |
| POST | `/tracking/{id}/delete` | TrackController@destroy |
| GET | `/tracking/{id}` | TrackController@show (الجشع أخيرًا) |
| GET | `/tracking/places` | TrackController@places (وجهات الخريطة — v34، حرفي قبل الجشع) |
| POST | `/tracking/places` | TrackController@savePlace |
| POST | `/tracking/places/auto` | TrackController@autoPlace (تحديد تلقائي — حرفي قبل `/{pid}/delete`) |
| POST | `/tracking/places/{pid}/delete` | TrackController@deletePlace |
| GET | `/t/{token}` | TrackController@driver — **عام: بلا auth/CSRF** (صفحة السائق) |
| POST | `/t/{token}/ping` | TrackController@ping — **عام: بلا auth/CSRF** (استقبال الموقع، JSON) |
| GET | `/movements` | MovementController@index |
| GET | `/movements/{id}/edit` | MovementController@edit |
| POST | `/movements/{id}` | MovementController@update |
| POST | `/movements/{id}/delete` | MovementController@destroy |
| GET | `/closing` | DailyClosingController@index |
| GET | `/closing/print` | DailyClosingController@printable |
| POST | `/closing/reconcile` | DailyClosingController@reconcile (حفظ مطابقة اليدوي مجمعة حسب العميل) |
| GET | `/settlements` | SettlementController@index |
| POST | `/settlements/pay` | SettlementController@pay |
| GET | `/settlements/{id}` | SettlementController@show |
| POST | `/settlements/{id}/pay` | SettlementController@addPayment |
| POST | `/settlements/{id}/payment/{pid}/delete` | SettlementController@deletePayment |
| GET | `/statements/batch` | OwnerStatementController@batchPrint (كشف PDF/طباعة مجمّع؛ قبل `/{id}`) |
| GET | `/audit` | AuditController@index |
| POST | `/audit/{id}/undo` | AuditController@undo |
| POST | `/audit/trash/{id}/restore` | AuditController@restore |

`/audit?mode=entries` هو تبويب سجل الإدخالات داخل الصفحة نفسها؛ يدعم `entity/from/to` ويقارن `audit_logs.work_date` بـ`record_date`، بينما `/audit` بلا `mode` يظل سجل العمليات الكامل وسلة المحذوفات.
| GET | `/suggest/names` | SuggestionController@names (اقتراحات أسماء متسامحة مع الخطأ الإملائي) |
| GET | `/office` | OfficeController@index (رصيد المكتب — كروت الفيزا) |
| GET | `/office/new` | OfficeController@create |
| POST | `/office` | OfficeController@store |
| GET | `/office/{id}/edit` | OfficeController@edit |
| POST | `/office/{id}/delete` | OfficeController@destroy |
| POST | `/office/{id}/deposit` | OfficeController@storeDeposit |
| POST | `/office/deposit/{depId}/delete` | OfficeController@deleteDeposit |
| POST | `/office/{id}` | OfficeController@update (بعد مسارات `/office/{id}/*`) |
| GET | `/office/{id}` | OfficeController@card (الجشع أخيرًا) |
| GET | `/debts` | DebtController@index |
| POST | `/debts/visa` | DebtController@storeVisa |
| POST | `/debts/account` | DebtController@storeAccount |
| POST | `/debts/account/{id}/pay` | DebtController@addPayment |
| POST | `/debts/account/{id}/delete` | DebtController@deleteAccount |
| POST | `/debts/payment/{pid}/delete` | DebtController@deletePayment |
| GET | `/debts/account/{id}` | DebtController@account |
| GET | `/legacy` | LegacyController@index |
| GET | `/legacy/owners` | LegacyController@owners |
| GET | `/legacy/statement` | LegacyController@statement |
| GET | `/legacy/{id}` | LegacyController@show |
| GET | `/reps` | RepController@index |
| GET | `/reps/new` | RepController@create |
| POST | `/reps` | RepController@store |
| GET | `/reps/{id}/edit` | RepController@edit |
| POST | `/reps/{id}/delete` | RepController@destroy |
| POST | `/reps/{id}/entry` | RepController@addEntry |
| POST | `/reps/{id}/settle` | RepController@settle |
| GET | `/reps/{id}/settlement/{sid}/print` | RepController@settlementPrint |
| POST | `/reps/entry/{eid}/delete` | RepController@deleteEntry |
| POST | `/reps/{id}` | RepController@update (بعد كل مسارات `/reps/{id}/*`) |
| GET | `/reps/{id}` | RepController@show (الجشع أخيرًا) |
| GET | `/directory` | DirectoryController@index |
| GET | `/directory/agent/{id}` | DirectoryController@agent |
| GET | `/directory/company/{id}` | DirectoryController@company |
| GET | `/balances` | BalancesController@index |
| POST | `/balances` | BalancesController@store |
| POST | `/balances/{id}` | BalancesController@update |
| POST | `/balances/{id}/delete` | BalancesController@destroy |
| GET | `/reports` | ReportsController@index |
| GET | `/reports/print` | ReportsController@printable |
| GET | `/reports/client` | ReportsController@client |
| GET | `/settings` | SettingsController@index |
| POST | `/settings/general` | SettingsController@saveGeneral |
| POST | `/settings/permits` | SettingsController@savePermits |
| POST | `/settings/lookup` | SettingsController@addLookup |
| POST | `/settings/lookup/{id}/delete` | SettingsController@deleteLookup |
| POST | `/settings/normalize` | SettingsController@normalizeNames |
| POST | `/settings/ai` | SettingsController@saveAi (حفظ مفاتيح/موديل المساعد) |
| POST | `/settings/ai/test` | SettingsController@testAi (JSON — اختبار مفتاح حيًّا) |
| GET | `/assistant` | AssistantController@index (صفحة محادثة المساعد الذكي) |
| POST | `/assistant/ask` | AssistantController@ask (JSON — سؤال/رد عبر Groq) |
| GET | `/search/api` | SearchController@api (JSON — البحث الحيّ للوحة الأوامر Ctrl+K) |
| GET | `/search` | SearchController@index (الصفحة الكاملة) |
| POST | `/backup` | BackupController@download |

> **المساعد الذكي (Groq AI):** موديل `App\Models\Assistant` يجيب على أسئلة صاحب الشركة بالعامية، **grounded بلا تخمين**: (1) يبني «نظرة عامة لحظية» من `Metrics` (الدين علينا، المصروفات، المدفوعات، النولون، الأعداد، ملخص الأذونات، أكبر 40 عميل علينا لهم) + `Custody::balance()`؛ (2) يفكّك السؤال لكلمات ويبحث بها عبر `Search::query()` لجلب السجلات المتعلّقة مع روابطها؛ (3) يرسل الكل مع السؤال إلى `App\Core\Groq` (واجهة OpenAI-compatible، `chat/completions`) مع تعليمات صارمة: «اعتمد فقط على المُعطى، ممنوع اختراع أرقام، والمتبقي = دين علينا للعميل». **إدارة المفاتيح من الواجهة:** تبويب «🤖 المساعد الذكي» في `/settings` يتيح حفظ **مفتاحين** (ai_key_1/ai_key_2) مع تسمية لكلٍّ، اختيار **المفتاح النشط** (ai_active)، اختيار الموديل (ai_model)، وتفعيل/تعطيل (ai_enabled) — كلها في جدول `settings` وتعلو على `config.php`. `Groq::config()` يقرأها ويرجع لـ `config.php` كاحتياطي. زر «اختبار» لكل مفتاح ينادي `POST /settings/ai/test` (يمرّر المفتاح المؤقت لـ `Groq::ping()` قبل الحفظ). يسمح بالتبديل السريع بين مفتاحين لو نفد رصيد أحدهما. المفتاح الأوّلي في `config.php` تحت `groq` (سرّي — لا يُنشر). الواجهة صفحة محادثة (`Views/assistant/index.php`، CSS+JS مضمّنان) مع اقتراحات جاهزة وسجل محادثة قصير يُرسَل كسياق. كل استدعاء يمرّ بـ `requireAuth()`+`requireCsrf()`. أخطاء الشبكة/المفتاح تُمسك وتُعرض كنص عربي دون كسر الواجهة.
>
> **البحث الشامل الذكي:** موديل `App\Models\Search` يبحث في **كل** جداول النظام دفعةً واحدة (بوالص/أذونات/تخليص/مولدات/شامل/فواتير الأذونات/كشوف أصحاب البيان/مديونيات/حركات/مصروفات/وكلاء/شركات) عبر تعريف مصادر config-driven، مع رابط عميق لكل سجل ووسم «الحقل المطابق» (سائق/حاوية/رقم سيارة/رقم إذن…) وترتيب ذكي حسب نوع الاستعلام (رقمي↔نصّي). كل مصدر محروس بـ try/catch (جداول الهجرات المتأخرة لا تكسر البحث). لوحة الأوامر (Command Palette) في `layout.php` + `app.js` تُفتح بـ **Ctrl/⌘+K** أو **/** وتبحث حيًّا عبر `/search/api`. **فلترة التاريخ:** بارامترات `from`/`to` (YYYY-MM-DD) على كلا المسارين مع أزرار جاهزة (اليوم/آخر ٧/٣٠ يوم/هذا الشهر/هذا العام) + نطاق مخصّص؛ عند تفعيل الفلتر تُستبعد المصادر بلا حقل تاريخ (وكلاء/شركات/مديونيات) تلقائيًا. الباك-إند stateless (يستقبل from/to فقط، يحسبها الكلاينت). **فلترة التصنيف:** بارامتر `type` (مفاتيح مصادر مفصولة بفواصل، المفاتيح غير الصالحة تُتجاهَل) — اللوحة متعدّدة الاختيار، الصفحة الكاملة مفردة؛ القائمة من `Search::categories()`. **عمليات البحث الأخيرة:** تُحفظ في `localStorage` (مفتاح `almotawakel_recent`، حتى 8) عند فتح نتيجة، وتظهر كاقتراحات عند فتح اللوحة فارغة (استرجاع الاستعلام بفلاتره).

---

## 5. نقاط الدخول (`public/`)

- **`public/index.php`** — الـ Front Controller الوحيد. جذر مستند الويب لازم يكون `public/`.
- **`public/router.php`** — سكربت راوتر لخادم PHP المدمج (`php -S`): يخدم الملفات الثابتة مباشرةً (`return false`)، وإلا يمرّر كل شيء لـ `index.php`.
- **`public/uploads/permits/`** — مرفقات الأذونات المرفوعة (jpg/jpeg/png/webp/gif/pdf).

---

## 6. ملاحظات معمارية مهمة

- **لا middleware:** الحماية (auth/CSRF) تُفرَض يدويًا داخل كل action.
- **الترتيب load-bearing:** المسارات الجشعة `/{id}` مُسجّلة آخرًا.
- **`MovementController::create`/`store` غير موصولة بمسار** (كود أثري) — الحركات تُنشأ تلقائيًا عبر `Movement::ensureForBill()`.
- كل موديل في `app/Models/`، الحسابات في `app/Calc.php`، تعريفات الكيانات في `app/Schema.php`، الـ views templates PHP في `app/Views/`.

> **⚠️ استثناء `requireAuth()`:** `/t/{token}` و`/t/{token}/ping` هما **المساران العامان الوحيدان** خارج شاشات المصادقة. السائق مالوش حساب، والتوكن العشوائي هو الصلاحية (مربوط برحلة واحدة + `expires_at` + `status`). صفحة السائق **ممنوع تعرض أي مبلغ**. التفاصيل والحمايات في CLAUDE.md (تحديث v33) و[07-modules.md](07-modules.md).
