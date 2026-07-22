<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** فحص آمن لمحرّك مرشد الإيصالات (v32) — سيناريوهات بلا مساس بالبيانات. */
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Models\Permit;

function show(string $title, array $permit, array $notes): void
{
    $g = Permit::receiptGuide($permit, array_map(fn($n) => ['note' => $n], $notes));
    $chips = [];
    foreach ($g['items'] as $it) {
        $tag = $it['present'] ? '✓' : ($it['dismissed'] ? '✕متجاهَل' : (!empty($it['optional']) ? '~اختياري' : '○ناقص'));
        $chips[] = $it['label'] . "[$tag]";
    }
    echo "── $title\n";
    echo "   المتوقّع: " . implode('  ', $chips) . "\n";
    echo "   الناقص الفعلي: " . (implode('، ', $g['missing']) ?: '—') . "\n\n";
}

echo "توكيلات التأمين: " . implode('، ', Permit::ruleAgencies('receipt_rules_insurance_agencies')) . "\n";
echo "توكيلات تعتيق الحاويات: " . implode('، ', Permit::ruleAgencies('receipt_rules_ateeq_agencies')) . "\n\n";

show('صادر · ميرسك · محمّل من الميناء · تعتيق صينية · معه تأمين',
    ['permit_type'=>'sader','agency'=>'ميرسك','chk_load'=>'port','chk_ateeq'=>'sacia','chk_nabatshia'=>0],
    ['إيصال تأمين', 'هيئة صادر', 'جيش']);

show('صادر · أركاس · محمّل من خارج الميناء · فيه بوصلة',
    ['permit_type'=>'sader','agency'=>'أركاس','chk_load'=>'outside','chk_ateeq'=>'yard','chk_nabatshia'=>1],
    ['مصاريف بوصلة']);

show('وارد · تعتيق في الميناء · لا مانع علينا',
    ['permit_type'=>'warid','agency'=>'','chk_ateeq'=>'port','chk_lamanea'=>1],
    ['جيش']);

show('وارد · تعتيق ساحة خارجية · لا مانع مش علينا',
    ['permit_type'=>'warid','agency'=>'','chk_ateeq'=>'yard','chk_lamanea'=>0],
    ['تعتيق ساحة', 'جيش']);

show('صادر · تجاهُل «تخصيص»',
    ['permit_type'=>'sader','agency'=>'','chk_load'=>'port','chk_dismissed'=>'تخصيص'],
    ['هيئة صادر', 'هيئة فوارغ', 'جيش']);

echo "تمّ الفحص.\n";
