<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Search;

class SearchController extends Controller
{
    /** صفحة البحث الكاملة */
    public function index(): void
    {
        $this->requireAuth();
        $q    = trim($_GET['q'] ?? '');
        $from = trim($_GET['from'] ?? '');
        $to   = trim($_GET['to'] ?? '');
        $type = trim($_GET['type'] ?? '');
        $types = $type !== '' ? array_values(array_filter(array_map('trim', explode(',', $type)))) : [];
        $results = $q !== '' ? Search::query($q, 50, $from ?: null, $to ?: null, $types) : [];
        $this->render('search/index', [
            'title'   => 'بحث سريع',
            'q'       => $q,
            'from'    => $from,
            'to'      => $to,
            'types'   => $types,
            'cats'    => Search::categories(),
            'results' => $results,
        ]);
    }

    /** نقطة JSON للبحث الحيّ في لوحة الأوامر (Ctrl+K) */
    public function api(): void
    {
        $this->requireAuth();
        $q    = trim($_GET['q'] ?? '');
        $from = trim($_GET['from'] ?? '');
        $to   = trim($_GET['to'] ?? '');
        $type = trim($_GET['type'] ?? '');
        $types = $type !== '' ? array_values(array_filter(array_map('trim', explode(',', $type)))) : [];
        $groups = $q !== '' ? Search::query($q, 6, $from ?: null, $to ?: null, $types) : [];

        $total = 0;
        $fuzzy = false;
        foreach ($groups as $g) { $total += count($g['rows']); if (!empty($g['fuzzy'])) { $fuzzy = true; } }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'q'      => $q,
            'total'  => $total,
            'fuzzy'  => $fuzzy,   // نتائج تسامح إملائي (تهجئة قريبة) بدل مطابقة دقيقة
            'groups' => array_values(array_map(function ($g) {
                return [
                    'title' => $g['title'],
                    'icon'  => $g['icon'],
                    'fuzzy' => !empty($g['fuzzy']),
                    'rows'  => array_map(function ($r) {
                        return [
                            'label' => $r['label'],
                            'sub'   => $r['sub'],
                            'field' => $r['field'],
                            'url'   => $r['url'],
                        ];
                    }, $g['rows']),
                ];
            }, $groups)),
        ], JSON_UNESCAPED_UNICODE);
    }
}
