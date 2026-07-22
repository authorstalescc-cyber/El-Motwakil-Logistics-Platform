<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Assistant;

/**
 * المساعد الذكي — صفحة محادثة + نقطة JSON للسؤال.
 */
class AssistantController extends Controller
{
    /** صفحة المحادثة */
    public function index(): void
    {
        $this->requireAuth();
        $this->render('assistant/index', [
            'title'   => 'المساعد الذكي',
            'enabled' => Assistant::enabled(),
        ]);
    }

    /**
     * حد الأسئلة لكل مستخدم: عدد الأسئلة في النافذة الزمنية.
     * ⚠️ كل سؤال بيروح لـGroq وبيتحسب على رصيد المفتاح. من غير الحد ده أي
     *    مستخدم مسجَّل (حتى «قراءة فقط») يقدر يفضّل يبعت أسئلة لحد ما الرصيد
     *    يخلص فيتعطّل المساعد على الكل. الحد بالجلسة كافي هنا لأن المسار
     *    محمي بـrequireAuth أصلاً — مش مسار عام.
     */
    private const ASK_LIMIT   = 30;
    private const ASK_WINDOW  = 300;   // 5 دقايق

    /** استقبال سؤال والرد عليه (JSON) */
    public function ask(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        if (!$this->allowAsk()) {
            $this->json([
                'ok'    => false,
                'error' => 'أسئلة كتير في وقت قصير. استنى شوية وحاول تاني.',
            ]);
            return;
        }

        $question = trim($_POST['q'] ?? '');

        $history = [];
        if (!empty($_POST['history'])) {
            $decoded = json_decode($_POST['history'], true);
            if (is_array($decoded)) { $history = $decoded; }
        }

        $this->json(Assistant::ask($question, $history));
    }

    /** نافذة منزلقة بسيطة: بنسيب أوقات آخر الأسئلة وبنعدّ اللي جوه النافذة. */
    private function allowAsk(): bool
    {
        $now  = time();
        $hits = array_values(array_filter(
            (array) ($_SESSION['_ai_hits'] ?? []),
            static fn($t) => is_int($t) && ($now - $t) < self::ASK_WINDOW
        ));
        if (count($hits) >= self::ASK_LIMIT) {
            $_SESSION['_ai_hits'] = $hits;
            return false;
        }
        $hits[] = $now;
        $_SESSION['_ai_hits'] = $hits;
        return true;
    }
}
