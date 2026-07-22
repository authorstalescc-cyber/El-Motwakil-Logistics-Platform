<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Notification;

/**
 * مركز الإشعارات — نقطة نداء واحدة **قراءة فقط**.
 *
 * الجرس ما بيحسبش حاجة وقت رندر الصفحة عشان ما يزوّدش أي تكلفة على كل فتحة؛
 * الواجهة بتنادي `/notifications/ping` بعد التحميل وكل 60 ثانية.
 * الكاش في الجلسة (`Notification::CACHE_SECS`) بيمنع التبويبات المفتوحة من
 * الضغط على القاعدة.
 *
 * ⚠️ **مفيش فيه أي إجراء كتابة** — حالة «مقروء» بتتخزّن في متصفح المستخدم
 * (localStorage) زي شارات «جديد» في بوابة المناديب، فمفيش جدول ولا هجرة.
 */
class NotificationController extends Controller
{
    public function ping(): void
    {
        $this->requireAuth();
        header('Cache-Control: private, no-store, max-age=0');
        $this->json(['ok' => true] + Notification::feed());
    }
}
