<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Habit;
use App\Models\NameSuggestion;

class SuggestionController extends Controller
{
    /**
     * عادات الطرف — «العميل ده بيروح فين عادةً؟» (v97).
     *
     * ⚠️ **بيرجّع اقتراحًا وخلاص — ما بيكتبش ولا حقل.** الجافاسكريبت هو اللي
     *    بيملا الشاشة، والمالك شايف القيمة قدامه قبل الحفظ. لو الجافاسكريبت
     *    ما اشتغلش، الفورم بيفضل فاضي زي الأول بالحرف.
     */
    public function habits(): void
    {
        $this->requireAuth();
        $ctx = [
            'client'  => trim((string) ($_GET['client']  ?? '')),
            'company' => trim((string) ($_GET['company'] ?? '')),
        ];
        $items = ($ctx['client'] !== '' || $ctx['company'] !== '') ? Habit::all($ctx) : [];
        $this->json(['items' => $items]);
        exit;   // ⚠️ `json()` بتطبع وما بتوقفش — أي تحذير بعدها بيتلزق جوّه الـJSON
    }

    /**
     * الأذونات اللي الاقتراح اتعلّم منها — نافذة «الرقم ده جاي منين؟».
     * (قاعدة المالك الدائمة: ممنوع رقم أصمّ.)
     */
    public function habitDrill(string $field): void
    {
        $this->requireAuth();
        if (!isset(Habit::FIELDS[$field])) {
            $this->notFound('نوع الاقتراح ده مش موجود.');
        }
        $ctx = [
            'client'  => trim((string) ($_GET['client']  ?? '')),
            'company' => trim((string) ($_GET['company'] ?? '')),
        ];
        // ⚠️ محروسة بـ`headers_sent()`: أي مخرَج سابق بيخلّي `header()` ترمي
        //    Warning **جوّه جسم الرد** فالنافذة تستقبل HTML متلوّث (باج v50).
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('permits/_habit_drill', [
            'field'   => $field,
            'label'   => Habit::FIELDS[$field],
            'hit'     => Habit::top($field, $ctx),
            'samples' => Habit::samples($field, $ctx),
        ], false);
    }

    public function names(): void
    {
        $this->requireAuth();
        $type=trim($_GET['type'] ?? ''); $q=trim($_GET['q'] ?? '');
        $long = mb_strlen($q,'UTF-8') >= 2;
        $this->json([
            'q'     => $q,
            'items' => $long ? NameSuggestion::search($type,$q) : [],
            // حوكمة الاسم (توحيد تلقائي / تعارض قريب) — نفس منطق الخادم عند الحفظ
            'guard' => $long ? NameSuggestion::resolve($type,$q) : ['value'=>$q,'snapped'=>false,'conflict'=>null],
        ]);
    }
}
