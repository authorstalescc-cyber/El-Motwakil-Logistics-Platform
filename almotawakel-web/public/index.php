<?php
/**
 * نظام المتوكل لإدارة النقل والتخليص الجمركي
 * الواجهة الأمامية — نقطة الدخول الوحيدة (Front Controller)
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Router;

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
$r->post('/dashboard/quick-entries','DashboardController@quickEntries');
$r->post('/notes',            'DashboardController@addNote');
$r->post('/notes/{id}/toggle','DashboardController@toggleNote');
$r->post('/notes/{id}/delete','DashboardController@deleteNote');
$r->post('/notes/{id}',       'DashboardController@editNote');

// شاشات الإدخال (CRUD) — نمط موحّد لكل كيان
$r->post('/bawalis/bulk-freight', 'BawalisController@bulkFreight');
$r->post('/bawalis/bulk-owner',   'BawalisController@bulkOwner');
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
$r->post('/tracking/places/{pid}/delete','TrackController@deletePlace');
$r->get('/tracking/{id}/feed',    'TrackController@feed');     // JSON — تحديث رحلة واحدة
$r->post('/tracking/{id}/status', 'TrackController@setStatus');
$r->post('/tracking/{id}/renew',  'TrackController@renew');
$r->post('/tracking/{id}/phone',  'TrackController@updatePhone');
$r->post('/tracking/{id}/delete', 'TrackController@destroy');
$r->get('/tracking/{id}',         'TrackController@show');     // الجشع أخيرًا

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
$r->post('/closing/reconcile',    'DailyClosingController@reconcile'); // حفظ مطابقة اليدوي مجمعة حسب العميل
$r->post('/closing/close',        'DailyClosingController@close');    // اقفل اليوم (تثبيت الرصيد)
$r->post('/closing/reopen',       'DailyClosingController@reopen');   // افتح الإقفال (آخر يوم فقط)

// رصيد العهدة (محفظة عهدة المكتب)
$r->get('/custody',                       'CustodyController@index');
$r->post('/custody/topup',                'CustodyController@storeTopup');
$r->get('/custody/topup/{id}/edit',       'CustodyController@editTopup');
$r->post('/custody/topup/{id}/delete',    'CustodyController@deleteTopup');
$r->post('/custody/topup/{id}',           'CustodyController@updateTopup');

// رصيد المكتب (كروت الفيزا) — المسارات الأخصّ قبل الجشعة /{id}
$r->get('/office',                        'OfficeController@index');
$r->get('/office/new',                    'OfficeController@create');
$r->post('/office',                       'OfficeController@store');
$r->get('/office/{id}/edit',              'OfficeController@edit');
$r->post('/office/{id}/delete',           'OfficeController@destroy');
$r->post('/office/{id}/deposit',          'OfficeController@storeDeposit');
$r->post('/office/deposit/{depId}/delete','OfficeController@deleteDeposit');
$r->get('/office/{id}/statement',         'OfficeController@statement');  // كشف حساب A4 (قبل الجشع)
$r->post('/office/{id}',                  'OfficeController@update');   // بعد كل مسارات /office/{id}/*
$r->get('/office/{id}',                   'OfficeController@card');     // الجشع أخيرًا

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

// سداد العملاء (دفعات سداد العملاء عبر كل الوكلاء + توجيهها للعهدة/الفيزا)
$r->get('/payments',                'PaymentController@index');
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
$r->post('/directory/agent/{id}/pay',                  'DirectoryController@addPayment');
$r->post('/directory/agent/{id}/payment/{pid}/delete', 'DirectoryController@deletePayment');
$r->get('/directory/agent/{id}',     'DirectoryController@agent');
$r->get('/directory/company/{id}',   'DirectoryController@company');

// التقارير
$r->get('/reports',        'ReportsController@index');
$r->get('/reports/print',  'ReportsController@printable');
$r->get('/reports/client', 'ReportsController@client');

// الإعدادات والقوائم
$r->get('/audit',                         'AuditController@index');
$r->post('/audit/{id}/undo',              'AuditController@undo');
$r->post('/audit/trash/{id}/restore',     'AuditController@restore');
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

$r->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
