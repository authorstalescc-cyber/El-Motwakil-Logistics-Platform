<?php
/**
 * مُصيّرا صفوف الداشبورد — **نسخة واحدة** تخدم الصفحة ونوافذ التفاصيل.
 *
 * ⚠️ لازم يفضلوا مشتركين: لو الصفحة رسمت الصف بطريقة والنافذة بطريقة تانية،
 *    المالك بيحسّ إنه دخل مكان تاني، والاتنين بيفترقوا مع أول تعديل. عشان
 *    كده الملف ده بيتضمّن من `index.php` و`_drill.php` مع بعض.
 *
 * ⚠️ الحارس `function_exists` مقصود — الملفان ممكن يتحمّلوا في نفس الطلب.
 */
use App\Models\Permit;

if (!function_exists('dash_permit_row')) {
    /** صف إذن — `$ready=true` للجاهز للفوترة (بزر فوترة)، وإلا للمفتوح. */
    function dash_permit_row(array $p, bool $ready): void
    {
        $th = Permit::theme($p['permit_type']);
        $meta = [];
        if (!empty($p['ct_label'])) { $meta[] = ['icon' => 'package', 'text' => $p['ct_label']]; }
        $meta[] = $ready
            ? ['icon' => 'truck', 'text' => intf($p['bills']) . ' بوليصة']
            : ['icon' => 'layers', 'text' => intf($p['stages']) . ' بند'];
        $meta[] = ['icon' => 'calendar', 'text' => dfmt($ready ? $p['permit_date'] : $p['last_activity'])];
        partial('row_item', [
            'title'  => $p['party'],
            'ref'    => !empty($p['permit_no']) ? '#' . $p['permit_no'] : null,
            'glyph'  => $th['arrow'],
            'tone'   => $th['cls'],
            'url'    => url('permits/' . $p['id']),
            'meta'   => $meta,
            'action' => $ready
                ? ['label' => 'فوترة', 'url' => url('permits/' . $p['id'] . '/invoice')]
                : null,
        ]);
    }
}

if (!function_exists('dash_feed_row')) {
    /** صف مصروف/إيصال من تغذية اليوم. */
    function dash_feed_row(array $f): void
    {
        $rc = !empty($f['is_receipt']);
        $meta = [];
        if ($f['ref_kind'] === 'permit' && !empty($f['ref'])) {
            $meta[] = ['icon' => 'folder', 'text' => 'إذن ' . $f['ref']];
        } elseif ($f['ref_kind'] === 'bill' && !empty($f['ref'])) {
            $meta[] = ['icon' => 'truck', 'text' => 'بوليصة ' . $f['ref']];
        } else {
            $meta[] = ['icon' => 'wallet', 'text' => 'يدوي'];
        }
        if (!empty($f['owner'])) { $meta[] = ['icon' => 'user', 'text' => $f['owner']]; }
        if (!empty($f['note'])) { $meta[] = ['icon' => 'file-text', 'text' => $f['note']]; }
        partial('row_item', [
            'title'  => $f['label'] ?: ($rc ? 'إيصال' : 'مصروف'),
            'icon'   => $rc ? 'clipboard' : 'trending-down',
            'meta'   => $meta,
            'amount' => money($f['amount'], false),
            'amount_tone' => $rc ? 'rc' : 'ex',
            'sub'    => dfmt($f['d']),
        ]);
    }
}
