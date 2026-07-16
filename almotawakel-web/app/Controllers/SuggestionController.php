<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\NameSuggestion;

class SuggestionController extends Controller
{
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
