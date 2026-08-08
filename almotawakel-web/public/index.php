<?php
/**
 * نظام المتوكل لإدارة النقل والتخليص الجمركي
 * الواجهة الأمامية — نقطة الدخول الوحيدة (Front Controller)
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Router;
use App\Core\Performance;

$performanceBuffer = Performance::enabled();
if ($performanceBuffer) { ob_start(); }

$r = new Router();

// المصادقة
$r->get('/login',   'AuthController@showLogin');
$r->post('/login',  'AuthController@login');
$r->post('/logout', 'AuthController@logout');

// تاريخ العمل (Working Date) — يُسأل عنه عند الدخول ويُغيَّر من البانر
$r->get('/work-date',  'WorkDateController@show');
$r->post('/work-date', 'WorkDateController@save');

// لوحة التحكم
$r->get('/', 'DashboardController@index');
$r->get('/dashboard/quick-options','DashboardController@quickOptions');
// تفاصيل أي رقم في الداشبورد (قاعدة «ممنوع رقم أصمّ») — نوع محصور في قائمة بيضا
$r->get('/dashboard/drill/{what}', 'DashboardController@drill');
$r->post('/dashboard/quick-entries','DashboardController@quickEntries');
// مركز الإشعارات (v52 + المرحلة ٢) — الجرس وصفحة السجل.
// ⚠️ المسارات الحرفية **قبل** أي مسار جشع (تحذير ٤). الكتابة الوحيدة هنا هي
//    «مقروء» وبتخصّ صاحب الجلسة نفسه (الهوية من الجلسة لا من الفورم).
$r->get('/notifications/ping',        'NotificationController@ping');
// بصمة النسخة للتحديث الإجباري — بلا حارس جلسة عن قصد (ختم وبس، بلا أي بيانات)
$r->get('/build',                     'NotificationController@build');
$r->get('/notifications/prefs',       'NotificationController@prefs');
$r->post('/notifications/prefs',      'NotificationController@prefsSave');
$r->post('/notifications/read-all',   'NotificationController@readAll');
$r->post('/notifications/read/{id}',  'NotificationController@read');
$r->post('/notifications/snooze/{id}','NotificationController@snooze');
$r->get('/notifications',             'NotificationController@index');

// إشعارات التطبيق (Web Push) — v51.
// ⚠️ `subscribe`/`unsubscribe` بيخدموا **جلسة المكتب وجلسة المندوب** مع بعض
//    (الكنترولر بيحدّد الجمهور من الجلسة نفسها لا من الفورم)، عشان كده مش
//    محطوطين تحت `/m` ولا تحت حراسة دور معيّن. الإرسال محكوم في `Access`.
$r->get('/push',              'PushController@index');
$r->get('/push/key',          'PushController@key');
$r->post('/push/subscribe',   'PushController@subscribe');
$r->post('/push/unsubscribe', 'PushController@unsubscribe');
$r->post('/push/send',        'PushController@send');
$r->post('/push/test',        'PushController@test');

$r->post('/notes',            'DashboardController@addNote');
$r->post('/notes/{id}/toggle','DashboardController@toggleNote');
$r->post('/notes/{id}/delete','DashboardController@deleteNote');
$r->post('/notes/{id}',       'DashboardController@editNote');

// شاشات الإدخال (CRUD) — نمط موحّد لكل كيان
$r->post('/bawalis/bulk-freight', 'BawalisController@bulkFreight');
$r->post('/bawalis/bulk-owner',   'BawalisController@bulkOwner');
$r->get('/bawalis/excel-sync',    'ExcelSyncController@index');
$r->get('/bawalis/excel-sync/review', 'ExcelSyncController@review');
$r->post('/bawalis/excel-sync/confirm', 'ExcelSyncController@confirmSelection');
$r->get('/bawalis/excel-sync/confirm', 'ExcelSyncController@confirm');
$r->post('/bawalis/excel-sync/upload', 'ExcelSyncController@upload');
$r->post('/bawalis/excel-sync/apply',  'ExcelSyncController@apply');
$r->get('/bawalis/check-number',  'BawalisController@checkNumber');
$r->post('/bawalis/bulk-paste',   'BawalisController@bulkPaste');
$r->get('/bawalis/new-legacy',    'BawalisController@createLegacy');
$r->post('/bawalis/legacy',       'BawalisController@storeLegacy');
$entities = [
    'bawalis'    => 'BawalisController',
    'takhlis'    => 'TakhlisController',
    'generators' => 'GeneratorsController',
];
foreach ($entities as $route => $ctrl) {
    $r->get("/$route",             "$ctrl@index");
    $r->get("/$route/new",         "$ctrl@create");
    $r->post("/$route",            "$ctrl@store");
    $r->get("/$route/{id}/edit",   "$ctrl@edit");
    $r->post("/$route/{id}",       "$ctrl@update");
    $r->post("/$route/{id}/delete","$ctrl@destroy");
}
// صفحة تفاصيل البوليصة (بعد /new و/{id}/edit لتفادي التعارض)
$r->get('/bawalis/{id}', 'BawalisController@show');

// المصروفات (سجل موحّد يسحب من كل الوحدات + إدخال مصاريف يدوية)
$r->get('/expenses',              'ExpenseController@index');
$r->get('/expenses/new',          'ExpenseController@create');
// استكمال بيانات الإيصالات — مسارات حرفية قبل المسار الجشع /expenses/{id}
$r->get('/expenses/receipts',     'ExpenseController@receiptsComplete');
$r->post('/expenses/receipts',    'ExpenseController@receiptsCompleteSave');
$r->post('/expenses',             'ExpenseController@store');
$r->get('/expenses/{id}/edit',    'ExpenseController@edit');
$r->post('/expenses/{id}',        'ExpenseController@update');
$r->post('/expenses/{id}/delete', 'ExpenseController@destroy');

// إصدار الفواتير (التبويب المستقل: السجل + اختيار الإذن). الإصدار نفسه تحت /permits/{id}/invoice
$r->get('/invoices',                  'InvoiceController@index');
$r->get('/invoices/new',              'InvoiceController@pick');
$r->post('/invoices/delete',          'InvoiceController@bulkDelete');   // حذف جماعي من السجل

// الإصدارات (إذن للصادر / شهادة للوارد) — أكبر عملية في النظام، بتايم لاين
$r->get('/permits',                   'PermitController@index');
$r->get('/permits/print-batch',       'PermitController@printBatch'); // طباعة عدة أذونات دفعة واحدة (قبل الجشع /{id})
$r->post('/permits/bulk',             'PermitController@bulk');     // إجراءات جماعية على المحدَّد (قبل الجشع /{id})
$r->get('/permits/new',               'PermitController@create');
$r->post('/permits',                  'PermitController@store');
$r->get('/permits/{id}/edit',         'PermitController@edit');
$r->post('/permits/{id}',             'PermitController@update');
$r->post('/permits/{id}/delete',      'PermitController@destroy');
$r->post('/permits/{id}/stage',       'PermitController@saveStage');
$r->post('/permits/{id}/link-bill',   'PermitController@linkBill');
$r->post('/permits/{id}/create-bill', 'PermitController@createBill');
$r->post('/permits/{id}/update-bill', 'PermitController@updateBill');
$r->post('/permits/{id}/unlink-bill', 'PermitController@unlinkBill');
$r->post('/permits/{id}/delete-bill', 'PermitController@deleteBill');
$r->post('/permits/{id}/copy-bill',   'PermitController@copyBill');
$r->post('/permits/{id}/close',       'PermitController@close');
$r->post('/permits/{id}/checklist',   'PermitController@saveChecklist');    // حفظ إجابات مرشد الإيصالات (v32)
$r->post('/permits/{id}/rcp-dismiss',  'PermitController@receiptDismiss');   // «مش محتاجه في الإذن ده» (v32)
// رحلة الشحنة (v62) — تحديث الحالة التشغيلية. وصفي بحت بلا أي أثر مالي.
$r->post('/permits/{id}/track',        'PermitController@trackStage');
$r->post('/permits/{id}/track-undo',   'PermitController@trackUndo');
$r->post('/permits/{id}/movement',    'PermitController@createMovement');
$r->post('/permits/movement/{mid}/update','PermitController@updateMovement');
$r->post('/permits/movement/{mid}/delete','PermitController@deleteMovement');
$r->post('/permits/stage/{sid}/move',  'PermitController@moveReceipt');
$r->post('/permits/stage/{sid}/delete','PermitController@deleteStage');
// فوترة العميل الخاصة بالأذونات (مسارات «invoice» الحرفية قبل المسارات الجشعة /{id})
$r->get('/permits/invoice/{invId}',        'PermitController@invoicePrint');   // طباعة A4
$r->post('/permits/invoice/{invId}/delete','PermitController@invoiceDelete');
$r->get('/permits/{id}/invoice',      'PermitController@invoiceForm');   // شاشة إعداد الفاتورة
$r->post('/permits/{id}/invoice/preview','PermitController@invoicePreview'); // معاينة قبل الإصدار (بلا حفظ)
$r->post('/permits/{id}/invoice',     'PermitController@invoiceStore');  // حفظ + طباعة
$r->get('/permits/{id}/manage',       'PermitController@manage');  // صفحة الإدارة/التعديل (التايم لاين)
$r->get('/permits/{id}/print',        'PermitController@printSheet'); // كشف مراجعة كامل للطباعة A4
$r->get('/permits/{id}',              'PermitController@view');    // صفحة العرض للقراءة (بعد الكل لتفادي التعارض)

// تتبع الشحنات باللوكيشن (v33) — وحدة معزولة لا تمسّ أي حساب مالي
// المسارات الحرفية قبل الجشعة /{id}
$r->get('/tracking',              'TrackController@index');
$r->get('/tracking/new',          'TrackController@pick');     // اختيار الشحنة
$r->post('/tracking',             'TrackController@store');    // توليد الرابط
$r->get('/tracking/feed',         'TrackController@feedAll');  // JSON — خريطة اللوحة
// نقاط الوجهات (v34) — حرفية قبل الجشع /tracking/{id}
$r->get('/tracking/places',              'TrackController@places');
$r->post('/tracking/places',             'TrackController@savePlace');
$r->post('/tracking/places/auto',        'TrackController@autoPlace');   // حرفي قبل /{pid}/delete
$r->post('/tracking/places/lookup',      'TrackController@lookupPlace'); // حرفي قبل /{pid}/delete
$r->post('/tracking/places/{pid}/delete','TrackController@deletePlace');
$r->get('/tracking/{id}/feed',    'TrackController@feed');     // JSON — تحديث رحلة واحدة
$r->post('/tracking/{id}/status', 'TrackController@setStatus');
$r->post('/tracking/{id}/renew',  'TrackController@renew');
$r->post('/tracking/{id}/phone',  'TrackController@updatePhone');
$r->post('/tracking/{id}/delete', 'TrackController@destroy');
$r->get('/tracking/{id}',         'TrackController@show');     // الجشع أخيرًا

// بوابة المندوب على الموبايل (v35) — جلسة منفصلة تمامًا (RepAuth) ما بتحطش uid،
// فكل صفحات المكتب بترفضه تلقائيًا. المسارات الحرفية قبل الجشعة.
$r->get('/m/login',   'RepPortalController@showLogin');
$r->post('/m/login',  'RepPortalController@login');
$r->post('/m/logout', 'RepPortalController@logout');
$r->get('/m/ping', 'RepPortalController@ping');   // إشعارات حيّة (قراءة فقط) — حرفي قبل الجشع
// تاريخ العمل للمندوب — حرفي قبل الجشع `/m/permit/{id}` و`/m/entry/{sid}` (تحذير 4)
$r->get('/m/work-date',  'RepPortalController@workDateForm');
$r->post('/m/work-date', 'RepPortalController@workDateSave');
// متابعة المناديب للمشرف (v47) — قراءة فقط، حرفي قبل الجشع
$r->get('/m/team',       'RepPortalController@team');
$r->get('/m/team/{rid}', 'RepPortalController@teamRep');
// جرس المندوب (المرحلة ٦) — **حرفية قبل الجشع `/m/permit/{id}`** (تحذير ٤).
// نفس الكنترولر بتاع المكتب: بياخد الهوية من الجلسة عبر `Notify::me()`،
// وحارسه `requireAnySession` بيقبل الجلستين.
$r->get('/m/notifications',        'RepPortalController@notifications');
$r->get('/m/notifications/prefs',  'NotificationController@prefs');
$r->post('/m/notifications/prefs', 'NotificationController@prefsSave');
// تصفية العهدة مع المكتب — حرفي قبل الجشع
$r->get('/m/settle',  'RepPortalController@settleForm');
$r->post('/m/settle', 'RepPortalController@settleStore');
// تحويل عهدة لمندوب (v46) — حرفي قبل الجشع
$r->get('/m/transfer',  'RepPortalController@transferForm');
$r->post('/m/transfer', 'RepPortalController@transferStore');
$r->post('/m/transfer/{tid}/delete', 'RepPortalController@transferDelete');
// إضافة بوليصة من الموبايل (v60) — **بديل شاشة «تسليم عهدة لسائق» (v45)**.
// ⚠️ مسارات `/m/handover` اتشالت بقرار المالك: المندوب بقى يضيف البوليصة نفسها
//    ويكتب عهدة السائق جوّاها، والعهدة بتتخصم من محفظته زي التسليم بالظبط.
//    جدول `rep_driver_handovers` وسجلاته **ما اتلمسوش** — لسه بيتخصموا من
//    عهدة المناديب وبيبانوا في كارت المندوب بالمكتب (شيلهم = تغيير أرصدة).
// حرفية قبل الجشع `/m/permit/{id}` وقبل `/m/entry/{sid}` (تحذير 4).
$r->get('/m/bill/new',  'RepPortalController@billForm');
$r->post('/m/bill/new', 'RepPortalController@billStore');
// «سلّمت السائق عهدته» على بوليصة **موجودة** (المرحلة ٣.٣ من docs/16):
// المكتب بيكون سجّل البوليصة، والمندوب بيقول إنه هو اللي دفع عهدتها فتتشيل من
// خزنة المكتب وتتحطّ على محفظته. **حرفي قبل `/m/bill/{bid}/...`** (تحذير 4).
$r->get('/m/bill/custody',  'RepPortalController@billCustodyForm');
$r->post('/m/bill/custody', 'RepPortalController@billCustodyStore');
$r->post('/m/bill/{bid}/unclaim', 'RepPortalController@billCustodyUnclaim');
$r->post('/m/bill/{bid}/delete', 'RepPortalController@billDelete');
$r->get('/m/misc',    'RepPortalController@miscForm');   // نثرية عامة بلا إذن (v39)
$r->post('/m/misc',   'RepPortalController@miscStore');
// فتح إذن من الموبايل (v43) — **حرفي قبل الجشع `/m/permit/{id}`** (تحذير 4).
$r->get('/m/permit/new',  'RepPortalController@permitForm');
$r->post('/m/permit/new', 'RepPortalController@permitStore');
/* بنود يوم تاني — عرض للقراءة بس، ما بيلمسش يوم العمل (بند ٦).
   ⚠️ حرفي قبل الجشع `/m/entry/{sid}` (تحذير ٤). */
$r->get('/m/entries', 'RepPortalController@entriesDay');
$r->get('/m/entry/{sid}/edit', 'RepPortalController@entryEditForm');
$r->post('/m/entry/{sid}/delete', 'RepPortalController@entryDelete');
$r->post('/m/entry/{sid}', 'RepPortalController@entryUpdate');
$r->get('/m/permit/{id}/info', 'RepPortalController@permitInfo');    // تفاصيل الإذن — حرفي قبل الجشع
$r->post('/m/permit/{id}/file', 'RepPortalController@permitFileStore');   // رفع ورقة على الإذن (v64)
$r->post('/m/permit-file/{id}/delete', 'RepPortalController@permitFileDelete');
$r->get('/file/permit-file/{id}', 'FileController@permitFile');
$r->get('/m/permit/{id}/docs', 'RepPortalController@permitDocs');   // مستندات الأطراف (v56) — قبل الجشع
$r->post('/m/permit/{id}/pin', 'RepPortalController@permitPin');    // تثبيت الإذن (v63) — قبل الجشع
// رحلة الشحنة (v62) — تحديث الحالة من الميدان. **حرفية قبل الجشع** (تحذير 4).
$r->get('/m/permit/{id}/track',  'RepPortalController@trackForm');
$r->post('/m/permit/{id}/track', 'RepPortalController@trackStore');
$r->get('/m/permit/{id}',  'RepPortalController@entryForm');
$r->post('/m/permit/{id}', 'RepPortalController@entryStore');
$r->get('/m', 'RepPortalController@home');

// المسار العام للسائق — **بلا auth وبلا CSRF عن قصد** (التوكن العشوائي هو الصلاحية).
// قصير عمدًا عشان يبقى مقروء في رسالة الواتساب. راجع TrackController للتفاصيل.
$r->get('/t/{token}',       'TrackController@driver');
$r->post('/t/{token}/ping', 'TrackController@ping');

// الحركات (نظام قديم — تُنشأ تلقائياً مع البوالص؛ محتفَظ بها للأرشيف)
$r->get('/movements',             'MovementController@index');
$r->get('/movements/{id}/edit',   'MovementController@edit');
$r->post('/movements/{id}',       'MovementController@update');
$r->post('/movements/{id}/delete','MovementController@destroy');

// التقفيل اليومي (تقفيل يوم للشركة) + الإقفال والترحيل
$r->get('/closing',               'DailyClosingController@index');
$r->get('/closing/print',         'DailyClosingController@printable');
// ⚠️ v50: مطابقة اليدوي القديمة (/closing/reconcile) اتشالت بالكامل. المراجعة
//    بقت علامة ✓ جنب كل بند — بند واحد، قسم كامل، أو حفظ الشاشة كلها بلا JS.
$r->post('/closing/review',       'DailyClosingController@review');      // تعليم/إلغاء بند واحد
$r->post('/closing/review-group', 'DailyClosingController@reviewGroup'); // تعليم/إلغاء قسم كامل
$r->post('/closing/review-save',  'DailyClosingController@reviewSave');  // حفظ كل علامات الشاشة (بلا JS)
// ⚠️ استلام كاش من مندوب وتصفير عهدة اليوم — بيمرّ على `RepCustody::settleZero`
//    نفسها بتاعة كارت المندوب، فمفيش تعريف تاني لمبلغ الاستلام.
$r->post('/closing/receive-rep',  'DailyClosingController@receiveRep');
$r->post('/closing/close',        'DailyClosingController@close');    // اقفل اليوم (تثبيت الرصيد)
$r->post('/closing/reopen',       'DailyClosingController@reopen');   // افتح الإقفال (آخر يوم فقط)

// رصيد العهدة (محفظة عهدة المكتب)
$r->get('/custody',                       'CustodyController@index');
$r->post('/custody/topup',                'CustodyController@storeTopup');
$r->get('/custody/topup/{id}/edit',       'CustodyController@editTopup');
$r->post('/custody/topup/{id}/delete',    'CustodyController@deleteTopup');
$r->post('/custody/topup/{id}',           'CustodyController@updateTopup');
// نزّل عهدة لمندوب **من درج المكتب** (المصدر = office) — حرفي، بلا تعارض مع /custody/topup/{id}
$r->post('/custody/rep-deposit',          'CustodyController@storeRepDeposit');

// خزنة الشركة (v66) — الخزنة الأكبر اللي بتغذّي خزنة المكتب.
// ⚠️ المسارات الحرفية قبل الجشع /company-safe/{id}/delete (تحذير ٤).
$r->get('/company-safe',                  'CompanySafeController@index');
$r->get('/company-safe/drill/{what}',     'CompanySafeController@drill');   // تفاصيل رقم — حرفي
$r->get('/company-safe/statement',        'CompanySafeController@statement'); // كشف A4 — حرفي
$r->post('/company-safe',                 'CompanySafeController@store');
$r->post('/company-safe/budget',          'CompanySafeController@saveBudget'); // حرفي قبل /{id}
$r->post('/company-safe/{id}/update',     'CompanySafeController@update');
$r->post('/company-safe/{id}/delete',     'CompanySafeController@destroy');

// رصيد المكتب (كروت الفيزا) — المسارات الأخصّ قبل الجشعة /{id}
$r->get('/office',                        'OfficeController@index');
$r->get('/office/new',                    'OfficeController@create');
// تفاصيل أرقام الكروت (ممنوع رقم أصمّ) — حرفي قبل الجشع /office/{id}
$r->get('/office/drill/{what}',           'OfficeController@drill');
$r->post('/office',                       'OfficeController@store');
$r->get('/office/{id}/edit',              'OfficeController@edit');
$r->post('/office/{id}/delete',           'OfficeController@destroy');
$r->post('/office/{id}/deposit',          'OfficeController@storeDeposit');
$r->post('/office/deposit/{depId}/delete','OfficeController@deleteDeposit');
$r->get('/office/{id}/statement',         'OfficeController@statement');  // كشف حساب A4 (قبل الجشع)
$r->post('/office/{id}',                  'OfficeController@update');   // بعد كل مسارات /office/{id}/*
$r->get('/office/{id}',                   'OfficeController@card');     // الجشع أخيرًا

// سيارات المكتب (v54) — المسارات الأخصّ قبل الجشعة /{id} (تحذير ٤)
$r->get('/cars',                          'OfficeCarController@index');
$r->get('/cars/new',                      'OfficeCarController@create');
// تفاصيل الأرقام (ممنوع رقم أصمّ) — حرفي قبل الجشع /cars/{id}
$r->get('/cars/drill/{what}',             'OfficeCarController@drill');
// فحص حيّ لفورم البوليصة: اللوحة سيارة مكتب؟ — حرفي قبل الجشع /cars/{id}
$r->get('/cars/is-office',                'OfficeCarController@isOffice');
$r->post('/cars',                         'OfficeCarController@store');
// تعديل/حذف مصروف + إخفاء لوحة «مش بتاعتنا» — حرفية قبل الجشع /cars/{id}
$r->post('/cars/expense/{eid}/update',    'OfficeCarController@updateExpense');
$r->post('/cars/expense/{eid}/delete',    'OfficeCarController@deleteExpense');
$r->post('/cars/ignore',                  'OfficeCarController@ignorePlate');
$r->post('/cars/unignore',                'OfficeCarController@unignorePlate');
$r->get('/cars/{id}/driver/print/{sid}',  'OfficeDriverController@printSaved');
$r->get('/cars/{id}/driver/print',        'OfficeDriverController@printable');
$r->post('/cars/{id}/driver/issue',       'OfficeDriverController@issue');
$r->get('/cars/{id}/driver/bill/{bid}',   'OfficeDriverController@bill');
$r->post('/cars/{id}/driver/bill/{bid}',  'OfficeDriverController@saveBill');
$r->post('/cars/{id}/driver/movement',    'OfficeDriverController@addMovement');
$r->post('/cars/{id}/driver/movement/{mid}/delete','OfficeDriverController@deleteMovement');
$r->get('/cars/{id}/driver',              'OfficeDriverController@index');
$r->get('/cars/{id}/edit',                'OfficeCarController@edit');
$r->get('/cars/{id}/report',              'OfficeCarController@report');   // تقرير A4 (قبل الجشع)
$r->post('/cars/{id}/expense',            'OfficeCarController@storeExpense');
$r->post('/cars/{id}/delete',             'OfficeCarController@destroy');
$r->post('/cars/{id}',                    'OfficeCarController@update');   // بعد كل مسارات /cars/{id}/*
$r->get('/cars/{id}',                     'OfficeCarController@card');     // الجشع أخيرًا

// المناديب (كارت + كشف حساب بطرفين + تقفيل دوري) — المسارات الأخصّ قبل الجشعة /{id}
$r->get('/reps',                             'RepController@index');
$r->get('/reps/new',                         'RepController@create');
$r->post('/reps',                            'RepController@store');
$r->get('/reps/{id}/edit',                   'RepController@edit');
$r->post('/reps/{id}/delete',                'RepController@destroy');
$r->post('/reps/{id}/entry',                 'RepController@addEntry');
$r->post('/reps/{id}/settle',                'RepController@settle');
$r->get('/reps/{id}/settlement/{sid}/print', 'RepController@settlementPrint');
$r->post('/reps/entry/{eid}/delete',         'RepController@deleteEntry');
$r->post('/reps/{id}',                       'RepController@update');   // بعد كل مسارات /reps/{id}/*
$r->get('/reps/{id}',                        'RepController@show');     // الجشع أخيرًا

// عهدة المناديب (v35) — محفظة منفصلة تمامًا عن الخزنة (زي كروت الفيزا).
// المسارات الحرفية قبل الجشعة /{id}
$r->get('/rep-custody',                            'RepCustodyController@index');
$r->get('/rep-custody/inbox',                      'RepCustodyController@inbox');   // حرفي قبل /{id}
$r->get('/rep-custody/drill/{what}',               'RepCustodyController@drill');   // تفاصيل رقم — حرفي قبل /{id}
$r->post('/rep-custody/approve-many',              'RepCustodyController@approveMany');
$r->post('/rep-custody/reject-many',               'RepCustodyController@rejectMany');
$r->post('/rep-custody/bulk-edit',                 'RepCustodyController@bulkEdit');
$r->post('/rep-custody/delete-many',               'RepCustodyController@deleteMany');   // حرفي قبل /{sid}
$r->post('/rep-custody/deposit/{depId}/delete',    'RepCustodyController@deleteDeposit');
$r->post('/rep-custody/settlement/{sid}/delete',   'RepCustodyController@deleteSettlement');
$r->get('/rep-custody/settlement/{sid}/print',     'RepCustodyController@settlementPrint');  // إيصال A4 — حرفي قبل /{id}
$r->post('/rep-custody/assign/{aid}/done',         'RepCustodyController@assignDone');
$r->post('/rep-custody/assign/{aid}/back',         'RepCustodyController@assignBack');
$r->post('/rep-custody/assign/{aid}/remove',       'RepCustodyController@assignRemove');
$r->post('/rep-custody/{sid}/review-start',        'RepCustodyController@startReview');
$r->post('/rep-custody/{sid}/approve',             'RepCustodyController@approve');
$r->post('/rep-custody/{sid}/reject',              'RepCustodyController@reject');
$r->post('/rep-custody/{sid}/edit',                'RepCustodyController@editSubmission');
$r->post('/rep-custody/{sid}/delete',              'RepCustodyController@deleteSubmission');   // حذف نهائي من الصندوق
$r->post('/rep-custody/transfer/{tid}/delete',     'RepCustodyController@deleteTransfer');     // v46 — حرفي قبل /{id}
$r->post('/rep-custody/transfer/{tid}/update',     'RepCustodyController@updateTransfer');     // تعديل تحويل — حرفي قبل /{id}
$r->post('/rep-custody/{id}/permit-perm',          'RepCustodyController@togglePermitPerm');  // v44
$r->post('/rep-custody/{id}/watch-perm',           'RepCustodyController@toggleWatchPerm');   // v47
$r->post('/rep-custody/{id}/account/toggle',       'RepCustodyController@toggleAccount');
$r->post('/rep-custody/{id}/account/reset',        'RepCustodyController@resetPin');
$r->post('/rep-custody/{id}/account',              'RepCustodyController@saveAccount');
$r->post('/rep-custody/{id}/assign',               'RepCustodyController@assign');
$r->post('/rep-custody/{id}/deposit',              'RepCustodyController@storeDeposit');
$r->post('/rep-custody/{id}/settle-all',           'RepCustodyController@settleAll');   // تصفية بضغطة
$r->post('/rep-custody/{id}/settle',               'RepCustodyController@settle');
$r->get('/rep-custody/{id}',                       'RepCustodyController@card');   // الجشع أخيرًا

// سداد العملاء (دفعات سداد العملاء عبر كل الوكلاء + توجيهها للعهدة/الفيزا)
$r->get('/payments',                'PaymentController@index');
// تفاصيل أرقام التحصيلات (ممنوع رقم أصمّ) — حرفي قبل /payments/{id}/delete
$r->get('/payments/drill/{what}',   'PaymentController@drill');
$r->post('/payments/pay',           'PaymentController@pay');
$r->post('/payments/{id}/delete',   'PaymentController@delete');

// سداد البوالص (ديون العملاء)
$r->get('/settlements',                       'SettlementController@index');
$r->post('/settlements/pay',                  'SettlementController@pay');
$r->post('/settlements/assign-owner',         'SettlementController@assignOwner');
$r->get('/settlements/{id}',                  'SettlementController@show');
$r->post('/settlements/{id}/pay',             'SettlementController@addPayment');
$r->post('/settlements/{id}/payment/{pid}/delete', 'SettlementController@deletePayment');

// كشوف حساب أصحاب البيان (النظام الثاني للفوترة) — المسارات الحرفية قبل الجشعة /{id}
$r->get('/statements',            'OwnerStatementController@index');   // السجل
$r->get('/statements/new',        'OwnerStatementController@pick');    // اختيار صاحب البيان
$r->get('/statements/form',       'OwnerStatementController@form');    // شاشة الإعداد (?owner=)
$r->get('/statements/batch',      'OwnerStatementController@batchPrint'); // طباعة مجمعة — حرفي قبل /{id}
$r->post('/statements',           'OwnerStatementController@store');   // حفظ
$r->post('/statements/{id}/delete','OwnerStatementController@delete');
$r->get('/statements/{id}',       'OwnerStatementController@printDoc'); // طباعة A4 (بعد الكل)

// المديونيات (الدائنون: الكشف والضمان + أصحاب البيان + مديونيات أخرى + تتبع الفيزا)
$r->get('/debts',                       'DebtController@index');
$r->post('/debts/visa',                 'DebtController@storeVisa');
$r->post('/debts/account',              'DebtController@storeAccount');
$r->post('/debts/account/{id}/pay',     'DebtController@addPayment');
$r->post('/debts/account/{id}/delete',  'DebtController@deleteAccount');
$r->post('/debts/payment/{pid}/delete', 'DebtController@deletePayment');
$r->get('/debts/account/{id}',          'DebtController@account');  // بعد المسارات الأخصّ

// أرشيف البوالص القديمة (عرض/بحث فقط — جدول معزول لا يمسّ أي حساب)
$r->get('/legacy',           'LegacyController@index');
$r->get('/legacy/owners',    'LegacyController@owners');     // JSON اقتراح أسماء أصحاب البيان
$r->get('/legacy/statement', 'LegacyController@statement');  // كشف حساب أرشيفي (معاينة/طباعة)
$r->get('/legacy/{id}',      'LegacyController@show');       // الجشع أخيرًا

// دليل الوكلاء والشركات + دفعات سداد العملاء (المسارات الأخصّ قبل الجشعة /{id})
$r->get('/directory',                'DirectoryController@index');
$r->post('/directory/master/{type}',                  'DirectoryController@createParty');
$r->post('/directory/master/{type}/{id}',             'DirectoryController@updateParty');
$r->post('/directory/master/{type}/{id}/alias',       'DirectoryController@addAlias');
$r->post('/directory/master/{type}/{id}/merge',       'DirectoryController@mergeParty');
// مستندات الأطراف (v56). ⚠️ «add» مقطع حرفي عشان `/doc/{type}/{id}` ما
// يبلعش `/doc/{docId}/delete` — الاتنين مقطعان والراوتر first-match-wins.
$r->post('/directory/doc/add/{type}/{id}', 'DirectoryController@addDoc');
$r->post('/directory/doc/{docId}/delete',  'DirectoryController@deleteDoc');
$r->post('/directory/doc/{docId}/update',  'DirectoryController@updateDoc');
$r->post('/directory/agent/{id}/pay',                  'DirectoryController@addPayment');
$r->post('/directory/agent/{id}/payment/{pid}/delete', 'DirectoryController@deletePayment');
$r->get('/directory/agent/{id}',     'DirectoryController@agent');
$r->get('/directory/company/{id}',   'DirectoryController@company');

// التقارير
$r->get('/reports',        'ReportsController@index');
$r->get('/reports/print',  'ReportsController@printable');
$r->get('/reports/client', 'ReportsController@client');
$r->get('/reports/drill/{what}', 'ReportsController@drill');   // تفاصيل رقم — قائمة بيضا في الكنترولر

// تقارير التشغيل (حاويات/موانئ/شركات/عهد — يومي أو أسبوعي أو شهري أو مخصّص)
// ⚠️ الوحدة **قراءة فقط**: كل مساراتها GET ومفيش فيها ولا POST واحد.
$r->get('/ops',             'OpsReportController@index');
$r->get('/ops/print',       'OpsReportController@printable');   // حرفي قبل الجشع
$r->get('/ops/drill/{what}','OpsReportController@drill');

// الإعدادات والقوائم
$r->get('/security',                      'SecurityController@index');   // للمالك بس (Access::OWNER_ONLY)
$r->get('/audit',                         'AuditController@index');
$r->post('/audit/{id}/undo',              'AuditController@undo');
$r->post('/audit/trash/{id}/restore',     'AuditController@restore');
// تنظيف البيانات — للمالك والمدير بس (Access::OWNER_MANAGER_ONLY)
// ⚠️ المسارات الحرفية قبل الجشع /quality/{key} (تحذير ٤)
$r->get('/quality',                     'QualityController@index');
$r->post('/quality/{key}/merge',        'QualityController@merge');
$r->post('/quality/{key}/drop',         'QualityController@drop');
$r->get('/quality/{key}',               'QualityController@field');

$r->get('/settings',                    'SettingsController@index');
$r->post('/settings/general',           'SettingsController@saveGeneral');
$r->post('/settings/permits',           'SettingsController@savePermits');
$r->post('/settings/lookup',            'SettingsController@addLookup');
$r->post('/settings/lookup/{id}/delete','SettingsController@deleteLookup');
$r->post('/settings/normalize',         'SettingsController@normalizeNames');
$r->post('/settings/ai',                'SettingsController@saveAi');
$r->post('/settings/ai/test',           'SettingsController@testAi');
$r->post('/settings/users',             'SettingsController@addUser');
$r->post('/settings/users/{id}/delete', 'SettingsController@deleteUser');
$r->post('/settings/users/{id}',        'SettingsController@updateUser');   // الجشع أخيرًا

// المساعد الذكي (Groq AI)
$r->get('/assistant',      'AssistantController@index');
$r->post('/assistant/ask', 'AssistantController@ask');

// البحث والنسخ الاحتياطي
$r->get('/suggest/names', 'SuggestionController@names');
$r->get('/search/api', 'SearchController@api');
$r->get('/search',  'SearchController@index');
$r->post('/backup', 'BackupController@download');

// المرفقات الخاصة — لا تُخدم كملفات static حتى لو كان السجل قديمًا داخل public/uploads.
$r->get('/attachments/stage/{id}',      'FileController@stage');
$r->get('/attachments/submission/{id}', 'FileController@submission');
$r->get('/attachments/misc/{id}',       'FileController@misc');
// إيصال حركة خزنة الشركة (v67) — جلسة المكتب بس (المندوب مرفوض عن قصد).
$r->get('/attachments/company-safe/{id}', 'FileController@companySafeFile');
// مستندات الأطراف (v56) — «print» حرفي قبل الجشع {id} (تحذير ٤).
$r->get('/attachments/party-doc/{id}/print', 'FileController@partyDocPrint');
$r->get('/attachments/party-doc/{id}',       'FileController@partyDoc');

$r->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
Performance::finish();
if ($performanceBuffer && ob_get_level() > 0) { ob_end_flush(); }
