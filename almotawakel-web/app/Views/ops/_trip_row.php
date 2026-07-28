<?php
/**
 * صف بوليصة واحدة — **نسخة واحدة مشتركة** بين الشاشة ونوافذ التفاصيل.
 *
 * ⚠️ ملف مستقل بيتضمّ بـ`include __DIR__` (نفس نمط `office_cars/_analytics.php`)
 *    لأن `partial()` بتدوّر في `app/Views/partials/` بس. نسختين كانوا هيفترقوا
 *    مع أول تعديل والمالك يلاقي شكل الصف في القايمة غير شكله في النافذة.
 *
 * ⚠️ **مفيش نولون هنا** — قرار المالك في الوحدة كلها.
 *
 * متغيّر مطلوب: `$t` (صف من `OpsReport::trips()`)
 */
$meta = array_values(array_filter([
    !empty($t['bill_date'])   ? ['icon' => 'calendar', 'text' => dfmt($t['bill_date'])] : null,
    !empty($t['port'])        ? ['icon' => 'anchor',   'text' => $t['port']] : null,
    !empty($t['company'])     ? ['icon' => 'building', 'text' => $t['company']] : null,
    !empty($t['destination']) ? ['icon' => 'map-pin',  'text' => $t['destination']] : null,
    !empty($t['driver'])      ? ['icon' => 'user',     'text' => $t['driver']] : null,
    !empty($t['car_no'])      ? ['icon' => 'truck',    'text' => $t['car_no']] : null,
    !empty($t['permit_no'])   ? ['icon' => 'folder',   'text' => 'إذن ' . $t['permit_no']] : null,
]));

$chips = [];
if ((int) $t['container_count'] > 0) {
    $chips[] = ['icon' => 'package',
                'text' => intf((int) $t['container_count']) . '×'
                        . (container_norm($t['container_type'] ?? '') ?: 'حاوية')];
}
if (!empty($t['agency'])) { $chips[] = ['icon' => 'briefcase', 'text' => $t['agency']]; }

partial('row_item', [
    'title'  => !empty($t['bill_number']) ? 'بوليصة ' . $t['bill_number'] : 'بوليصة #' . (int) $t['id'],
    'icon'   => 'truck',
    'tone'   => (string) ($t['permit_type'] ?? $t['bill_type'] ?? '') === 'warid' ? 'warid' : 'sader',
    'url'    => url('bawalis/' . (int) $t['id']),
    'meta'   => $meta,
    'chips'  => $chips,
    'amount' => (float) $t['custody'] > 0 ? money($t['custody'], false) : '—',
    'amount_tone' => 'rc',
    'sub'    => 'عهدة',
]);
