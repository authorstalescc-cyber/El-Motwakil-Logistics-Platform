<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\FreightTariff;

/**
 * شاشة قائمة أسعار النولون — `/tariffs` (v102).
 *
 * ⚠️ **الأسعار بتتغيّر، فالشاشة بتعدّل.** الملف `bin/seed_tariffs_2026.php`
 *    نقطة البداية بس — بعد كده المالك بيعدّل من هنا بلا ما يستنّى مبرمج.
 *
 * ⚠️ **التعديل بيغيّر التوقّع الجاي بس — ولا فاتورة محفوظة بتتلمس.** الفاتورة
 *    لقطة ثابتة (قرار v10)، فتحديث التعريفة النهارده ما بيرجّعش يغيّر مستندًا
 *    اتطبع الشهر اللي فات.
 */
class TariffController extends Controller
{
    public function index(): void
    {
        $this->requireRole(['owner', 'manager']);

        $port    = (string) ($_GET['port'] ?? 'alex');
        $service = (string) ($_GET['service'] ?? '');
        // ⚠️ قائمة بيضا: القيمتان بيروحوا للاستعلام
        if (!isset(FreightTariff::PORT_GROUPS[$port])) { $port = 'alex'; }
        if (!in_array($service, ['', 'container', 'reefer', 'lcl'], true)) { $service = ''; }

        $this->render('tariffs/index', [
            'title'    => 'قائمة أسعار النولون',
            'port'     => $port,
            'service'  => $service,
            'rows'     => FreightTariff::all($port, $service ?: null),
            'ports'    => FreightTariff::PORT_GROUPS,
            'units'    => FreightTariff::UNITS,
            'ready'    => FreightTariff::ready(),
        ]);
    }

    /** حفظ الأسعار المعدَّلة — الصفوف اللي اتغيّرت بس. */
    public function save(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        $ids    = (array) ($_POST['id'] ?? []);
        $prices = (array) ($_POST['price'] ?? []);
        $n = 0;
        foreach ($ids as $i => $id) {
            $raw = trim((string) ($prices[$i] ?? ''));
            if ($raw === '') { continue; }
            /* ⚠️ `money_unformat` هي المصدر الوحيد لفكّ فواصل الآلاف — الخانة
               بتتحوّل لـ`type=text` بجافاسكريبت فالقيمة ممكن توصل «22,300». */
            $val = (float) money_unformat($raw);
            if ($val < 0) { continue; }
            if (FreightTariff::updatePrice((int) $id, $val)) { $n++; }
        }
        $back = 'tariffs?port=' . rawurlencode((string) ($_POST['port'] ?? 'alex'));
        $this->done($n > 0 ? "اتحدّث $n سعر." : 'مفيش سعر اتغيّر.', $back, '', $n > 0 ? 'success' : 'error');
    }
}
