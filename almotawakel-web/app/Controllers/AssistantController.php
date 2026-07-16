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

    /** استقبال سؤال والرد عليه (JSON) */
    public function ask(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $question = trim($_POST['q'] ?? '');

        $history = [];
        if (!empty($_POST['history'])) {
            $decoded = json_decode($_POST['history'], true);
            if (is_array($decoded)) { $history = $decoded; }
        }

        $this->json(Assistant::ask($question, $history));
    }
}
