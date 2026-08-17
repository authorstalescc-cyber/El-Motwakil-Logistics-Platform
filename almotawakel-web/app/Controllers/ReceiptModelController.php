<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\ReceiptKind;
use App\Models\ReceiptModel;

/**
 * شاشة «اللي النظام اتعلّمه» — `/receipt-model` (v101).
 *
 * ⚠️ **الشاشة دي هي ضمان المصداقية.** الموديل بيقول للمالك «الإذن ده ناقص
 *    جيش» — ولو مالوش طريقة يشوف **إزاي** اتعلّم كده، الادّعاء بيبقى صندوقًا
 *    أسود، وأول مرة يغلط بيبطّل يثق فيه خالص. هنا كل قاعدة اتعلّمها معروضة
 *    بالرقم اللي اتقاست منه، والدقة مقيسة على أذون **مستبعَدة من التدريب**
 *    لا على أذون التدريب نفسها.
 *
 * ⚠️ **قراءة بحتة + زرار تدريب.** ولا مليم ولا بند إيصال بيتغيّر من هنا:
 *    التدريب بيعيد بناء أربع جداول مشتقّة وبس.
 */
class ReceiptModelController extends Controller
{
    /** نظرة كاملة على الموديل: الدقة · أساس العدد · الاقتران · القواعد لكل سياق. */
    public function index(): void
    {
        $this->requireRole(['owner', 'manager']);

        $scope = (string) ($_GET['scope'] ?? 'type');
        // ⚠️ قائمة بيضا: `scope` بيروح لاستعلام، والقيمة الجاية من الرابط ممكن تتزوّر
        if (!isset(ReceiptModel::SCOPES[$scope])) { $scope = 'type'; }

        $this->render('receipts/model', [
            'title'     => 'اللي النظام اتعلّمه — الإيصالات',
            'fresh'     => ReceiptModel::freshness(),
            'runs'      => ReceiptModel::runs(10),
            'scope'     => $scope,
            'scopes'    => ReceiptModel::SCOPES,
            'rules'     => ReceiptModel::rules($scope, 300),
            'baseRules' => ReceiptModel::rules('type', 80),
            'pairs'     => ReceiptModel::pairs(20),
            'catalog'   => ReceiptKind::catalog(),
        ]);
    }

    /**
     * تدريب بضغطة.
     *
     * ⚠️ `requireCsrf` + `requireRole` **الاتنين** — مفيش middleware في النظام
     *    (تحذير ٥ في CLAUDE.md)، فالحارسين بيتكتبوا بالإيد في كل إجراء.
     */
    public function train(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        $r = ReceiptModel::train('manual');
        if (empty($r['ok'])) {
            $this->done((string) ($r['reason'] ?? 'التدريب ما تمّش.'), 'receipt-model', '', 'error');
        }
        $msg = 'اتدرّب على ' . intf((int) $r['permits']) . ' إذن مقفول · '
             . intf((int) $r['receipts']) . ' إيصال';
        if ($r['precision'] !== null) {
            $msg .= ' · دقة ' . number_format((float) $r['precision'] * 100, 0) . '٪';
        }
        $this->done($msg, 'receipt-model');
    }
}
