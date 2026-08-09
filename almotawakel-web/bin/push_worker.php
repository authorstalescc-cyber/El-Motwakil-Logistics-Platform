<?php
// ═══ حارس: سطر الأوامر فقط ═══
// ⚠️ الملف ده بيبعت إشعارات فعليًّا، فممنوع منعًا باتًّا يتفتح من الويب.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * عامل إعادة إرسال الإشعارات — بيشتغل من الكرون كل ٥ دقايق.
 * صيغة الكرون الكاملة في docs/15-notifications-master-plan.md (الملحق أ).
 *
 * ⚠️ **ما تكتبش صيغة الكرون جوّه تعليق PHP** — نجمة-شرطة-مايلة بتقفل التعليق
 *    وتدّي «syntax error» (اتصادفت فعليًّا وأنا بكتب الملف ده).
 *
 * بياخد المستلمين اللي:
 *   • فشل إرسالهم (`failed`) وعدد محاولاتهم أقل من ٣، أو
 *   • لسه `pending` من أكتر من دقيقتين (يعني الإرسال اللحظي مات في نصّه —
 *     السيرفر اترستارت، أو الطلب اتقطع قبل `shutdown`).
 *
 * ⚠️ **الملف آمن من أول يوم:** لو مفيش أي طابور بيخرج فورًا بلا ما يعمل حاجة،
 *    فتقدر تظبّط الكرون قبل ما يبقى فيه إشعارات أصلاً.
 *
 * ⚠️ **مفيش تشغيلين مع بعض:** قفل ملف (`flock`) بيمنع نسختين تبعتوا نفس
 *    الإشعار مرتين لو تشغيل اتأخّر ودخل على اللي بعده.
 *
 * ⚠️ **`workerMode` لازم تتفعّل هنا** — الإشعارات مكتومة في سطر الأوامر
 *    افتراضيًّا (عشان سكربتات الاستيراد ما تبعتش مئات الرسائل)، والعامل هو
 *    **الاستثناء الوحيد** المسموح له يبعت من CLI.
 *
 * ⚠️ **المقارنات الزمنية جوّه SQL** (قاعدة v48): ساعة القاعدة UTC وPHP القاهرة،
 *    وفرق الـ٣ ساعات كان بيعطّل حراسات وقت قبل كده.
 *
 * بيسجّل آخر تشغيل في `settings.push_worker_last_run` عشان الشاشة تقدر تقول
 * «آخر تشغيل: من ٣ دقايق ✓» بدل ما تفضل مش عارفة الكرون شغّال ولا لأ.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Models\Notify;
use App\Models\NotifyWatch;
use App\Models\PushNotify;

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$say = static function (string $m) use ($verbose): void { if ($verbose) { echo $m . PHP_EOL; } };

// ── قفل: نسخة واحدة بس تشتغل في المرة ──────────────────────────────────
$lockPath = App::basePath('storage/push_worker.lock');
@mkdir(dirname($lockPath), 0775, true);
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "تعذّر فتح ملف القفل: $lockPath\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    $say('نسخة تانية شغّالة — خرجنا بهدوء.');
    exit(0);
}

$started = microtime(true);
$result = ['events' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

try {
    if (!Notify::available()) {
        $say('جداول الإشعارات لسه ما اترحّلتش — مفيش حاجة تتعمل.');
    } else {
        // الاستثناء الوحيد المسموح له يبعت من سطر الأوامر
        PushNotify::workerMode(true);
        PushNotify::mute(false);

        /* ── بوابة المناديب: الوقت المحدَّد عدّى؟ (v68) ─────────────────────
           ⚠️ **مش هي اللي بتفتح البوابة** — `RepMaintenance::isOn()` بتفتحها
              لحظة ما الوقت يعدّي بلا انتظار حد، فالمناديب ما بيتعلّقوش على
              كرون ممكن يكون واقف. اللي بيحصل هنا: تصفير المفتاح + رنّة
              «رجعنا». وبيتعمل **قبل** الإرسال عشان الرنّة تروح في نفس التشغيلة. */
        if (\App\Models\RepMaintenance::expireIfDue()) {
            $say('بوابة المناديب اتفتحت تلقائيًّا — الوقت المحدَّد عدّى.');
        }

        /* ⚠️ **الترتيب مقصود: التوليد قبل الإرسال.** التذكيرات اللي بتتولّد
           دلوقتي بتتبعت في نفس التشغيلة بدل ما تستنى ٥ دقايق كمان. */
        $watch = NotifyWatch::run();
        $made  = array_sum($watch);
        if ($made > 0) {
            $say('تنبيهات تلقائية اتولّدت: ' . json_encode($watch, JSON_UNESCAPED_UNICODE));
        }

        $result = PushNotify::deliverPending(100);
        $result['watch'] = $made;
        $say("أحداث: {$result['events']} · اتبعت: {$result['sent']} · فشل: {$result['failed']} · متخطّى: {$result['skipped']} · تلقائي: {$made}");
    }

    // ختم آخر تشغيل — الشاشة بتقراه عشان تقول إن الكرون شغّال فعلاً
    App::setSetting('push_worker_last_run', date('Y-m-d H:i:s'));
    App::setSetting('push_worker_last_stat', json_encode($result, JSON_UNESCAPED_UNICODE));
} catch (\Throwable $e) {
    // ⚠️ العامل ما يرميش أبدًا — الكرون بيشتغل بلا حد يشوف مخرجاته
    error_log('push_worker failed: ' . $e->getMessage());
    fwrite(STDERR, 'خطأ: ' . $e->getMessage() . PHP_EOL);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

$say('الوقت: ' . round((microtime(true) - $started) * 1000) . 'ms');
flock($lock, LOCK_UN);
fclose($lock);
exit(0);
