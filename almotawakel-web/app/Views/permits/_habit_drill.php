<?php
/**
 * نافذة «الاقتراح ده جاي منين؟» — الأذونات اللي العادة اتعلّمت منها (v97).
 *
 * ⚠️ **دي مش زينة — دي شرط.** قاعدة المالك الدائمة «ممنوع رقم أصمّ»: «٩ من ١٠»
 *    اللي جنب الاقتراح لازم تُضغَط وتوري العشرة نفسهم، وإلا يبقى النظام بيقول
 *    رقمًا والمالك مالوش طريقة يتأكد منه.
 *
 * بيترندر HTML للنافذة العامة الوحيدة (`#uiDrill` في `layout.php`) — مش JSON،
 * عشان الصفوف تستخدم نفس `row_item` بتاع باقي النظام فالشكل واحد.
 */
use App\Models\Permit;

$hit     = $hit ?? null;
$samples = $samples ?? [];
$label   = (string) ($label ?? '');
$match   = $hit !== null ? (string) $hit['value'] : '';
?>
<?php partial('drill', [
    'how'   => $hit !== null
        ? 'المعتاد لهذا ' . (string) $hit['scope'] . ': ' . $match
        : null,
    'count' => count($samples),
    'total' => $hit !== null
        ? ['label' => $label, 'value' => $hit['hits'] . ' من ' . $hit['total']]
        : null,
    'empty' => ['title' => 'مفيش معاملات سابقة'],
    'more'  => ['label' => 'كل الأذونات', 'url' => url('permits')],
    'body'  => function () use ($samples, $match) {
        foreach ($samples as $s) {
            $value = trim((string) ($s['value'] ?? ''));
            /* ⚠️ الصف المطابق بيتلوّن والمخالف بيفضل عادي — عشان المالك يشوف
               بعينه إن ٩ من ١٠ فعلاً، مش ياخدها كلامًا.
               ⚠️ **`rc` مش `pos`**: `row_item` بيوثّق أربع نغمات، بس CSS
                  `.d-feed-side b` معرِّف **`ex` و`rc` بس** — فـ`pos` بتتطبع
                  كلاسًا بلا تعريف وتقع على الشكل الافتراضي **بالسكوت** (نفس
                  مصيدة `.table-mobile-scroll`). */
            $same = $value !== '' && $value === $match;
            partial('row_item', [
                'title' => trim((string) ($s['permit_no'] ?? '')) !== ''
                    ? (string) $s['permit_no'] : '#' . (int) $s['id'],
                'ref'   => $s['permit_date'] ? dfmt($s['permit_date']) : null,
                'icon'  => 'clipboard',
                'meta'  => array_values(array_filter([
                    trim((string) ($s['company'] ?? '')) !== ''
                        ? ['icon' => 'building', 'text' => (string) $s['company']] : null,
                    trim((string) ($s['client'] ?? '')) !== ''
                        ? ['icon' => 'user', 'text' => (string) $s['client']] : null,
                    ['icon' => 'file-text', 'text' => Permit::typeLabel((string) ($s['permit_type'] ?? 'sader'))],
                ])),
                'amount'      => $value !== '' ? $value : '—',
                'amount_tone' => $same ? 'rc' : '',
            ]);
        }
    },
]); ?>
