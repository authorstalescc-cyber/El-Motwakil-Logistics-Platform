<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\LegacyBawali;

/**
 * أرشيف البوالص القديمة — وحدة «عرض فقط».
 *   /legacy         قائمة + بحث + فلتر تاريخ + تصفيح
 *   /legacy/{id}    تفاصيل بوليصة أرشيفية
 *
 * لا كتابة/حذف/تعديل — أرشيف معزول لا يمسّ أي حساب في النظام.
 */
class LegacyController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $f = [
            'q'    => trim($_GET['q'] ?? ''),
            'from' => trim($_GET['from'] ?? ''),
            'to'   => trim($_GET['to'] ?? ''),
        ];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $res  = LegacyBawali::paginate($f, $page, 50);

        // مفاتيح مفرودة (لا تستخدم اسم 'data' — يتضارب مع بارامتر render())
        $this->render('legacy/index', [
            'title'  => 'أرشيف البوالص القديمة',
            'f'      => $f,
            'rows'   => $res['rows'],
            'total'  => $res['total'],
            'page'   => $res['page'],
            'pages'  => $res['pages'],
            'per'    => $res['per'],
            'sums'   => $res['sums'],
            'stats'  => LegacyBawali::stats($f),
        ]);
    }

    /** JSON: اقتراح أسماء أصحاب البيان (تطابق + أسماء متشابهة إملائيًّا) */
    public function owners(): void
    {
        $this->requireAuth();
        $q = trim($_GET['q'] ?? '');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['q' => $q, 'items' => $q !== '' ? LegacyBawali::ownerSuggest($q, 10) : []],
            JSON_UNESCAPED_UNICODE
        );
    }

    /** كشف حساب أرشيفي لصاحب بيان — معاينة/طباعة فقط، لا يُحفَظ شيء */
    public function statement(): void
    {
        $this->requireAuth();
        $owner = trim($_GET['owner'] ?? '');
        $st    = $owner !== '' ? LegacyBawali::ownerStatement($owner) : null;
        if (!$st) {
            flash('error', 'لا توجد بوالص في الأرشيف لصاحب البيان «' . $owner . '».');
            redirect('legacy');
        }
        $this->render('legacy/statement', [
            'title' => 'كشف حساب أرشيفي — ' . $st['owner'],
            'st'    => $st,
        ], false);
    }

    public function show(string $id): void
    {
        $this->requireAuth();
        $bill = LegacyBawali::find((int)$id);
        if (!$bill) {
            http_response_code(404);
            $this->render('errors/404', ['title' => 'غير موجود'], false);
            return;
        }
        $this->render('legacy/show', [
            'title'  => 'بوليصة أرشيفية' . ($bill['bill_number'] ? ' — ' . $bill['bill_number'] : ''),
            'bill'   => $bill,
            'fields' => LegacyBawali::fields(),
        ]);
    }
}
