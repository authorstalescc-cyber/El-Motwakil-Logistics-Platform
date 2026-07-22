<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص آمن للوحة متابعة عهدة المناديب في الداشبورد.
 * قراءة فقط — ما بيكتبش أي حاجة في القاعدة.
 *
 * التشغيل:  php bin/verify_rep_dashboard.php
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Custody;
use App\Models\RepCustody;
use App\Models\RepInbox;

$ok = 0; $fail = 0;
function chk(string $name, bool $cond, string $extra = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ✅ $name" . ($extra ? " — $extra" : '') . "\n"; }
    else       { $fail++; echo "  ❌ $name" . ($extra ? " — $extra" : '') . "\n"; }
}

echo "\n══ فحص لوحة عهدة المناديب في الداشبورد ══\n\n";

// ① العزل: رصيد خزنة المكتب ما بيتغيّرش من قراءة اللوحة
$before = Custody::balance();
$day    = Auth::workDate() ?: date('Y-m-d');
$d      = RepCustody::dashboard($day, 12);
$after  = Custody::balance();

echo "① العزل عن خزنة المكتب\n";
chk('رصيد العهدة العامة ما اتغيّرش', abs($before - $after) < 0.0001,
    number_format($before, 2) . ' ↔ ' . number_format($after, 2));

// ② شكل البيانات
echo "\n② شكل البيانات\n";
foreach (['available','reps','feed','totals','day'] as $k) {
    chk("المفتاح `$k` موجود", array_key_exists($k, $d));
}
if (!$d['available']) {
    echo "\n⚠️  عهدة المناديب مش متاحة (migrate_v35 لسه ما اتشغّلش) — اللوحة بتختفي بأمان.\n";
    echo "\nالنتيجة: $ok ناجح · $fail فاشل\n";
    exit($fail ? 1 : 0);
}
foreach (['deposits','spent','returned','outstanding','pending_cnt','pending_sum','reps','low'] as $k) {
    chk("totals.$k موجود", array_key_exists($k, $d['totals']));
}
foreach (['sent_cnt','sent_sum','paid_cnt','paid_sum'] as $k) {
    chk("day.$k موجود", array_key_exists($k, $d['day']));
}

// ③ الأرقام مطابقة لمصدر الحقيقة (نفس اللي بتعرضه /rep-custody)
echo "\n③ مطابقة مصدر الحقيقة\n";
chk('الإجمالي البرّه = RepCustody::totalOutstanding()',
    abs($d['totals']['outstanding'] - RepCustody::totalOutstanding()) < 0.005,
    number_format($d['totals']['outstanding'], 2));
chk('عدد المستنّي = RepInbox::pendingCount()',
    $d['totals']['pending_cnt'] === RepInbox::pendingCount(),
    $d['totals']['pending_cnt'] . ' بند');

$bad = [];
foreach ($d['reps'] as $c) {
    $expected = RepCustody::balance((int) $c['id']);
    if (abs($c['balance'] - $expected) >= 0.005) { $bad[] = $c['name']; }
    if (abs(($c['balance'] - $c['pending_sum']) - $c['pocket']) >= 0.005) { $bad[] = $c['name'] . ' (pocket)'; }
}
chk('رصيد كل مندوب = RepCustody::balance() بالظبط', !$bad,
    $bad ? implode('، ', $bad) : count($d['reps']) . ' مندوب');

// ④ نسب شريط العرض في حدودها
echo "\n④ شريط الاستهلاك (عرض فقط)\n";
$oob = [];
foreach ($d['reps'] as $c) {
    foreach (['pc_spent','pc_pending','pc_return'] as $k) {
        if ($c[$k] < 0 || $c[$k] > 100) { $oob[] = $c['name'] . ".$k={$c[$k]}"; }
    }
}
chk('كل النسب بين 0 و 100', !$oob, $oob ? implode('، ', $oob) : 'تمام');

// ⑤ الفيد
echo "\n⑤ آخر المدخلات\n";
chk('الفيد ما بيزيدش عن الحد المطلوب', count($d['feed']) <= 12, count($d['feed']) . ' بند');
$badStatus = array_filter($d['feed'], fn($s) => !in_array($s['status'], ['pending','approved','rejected'], true));
chk('كل حالة معروفة (pending/approved/rejected)', !$badStatus);

// ملخّص للعين
echo "\n── ملخّص اللحظة ──\n";
printf("  نازل %s · مصروف %s · مرتجع %s · برّه %s\n",
    number_format($d['totals']['deposits'], 2), number_format($d['totals']['spent'], 2),
    number_format($d['totals']['returned'], 2), number_format($d['totals']['outstanding'], 2));
printf("  مستنّي المراجعة: %d بند بـ %s · مناديب باللوحة: %d · محتاج تنزيل: %d\n",
    $d['totals']['pending_cnt'], number_format($d['totals']['pending_sum'], 2),
    $d['totals']['reps'], $d['totals']['low']);
printf("  نشاط %s: اتبعت %d بند (%s) · معتمد %d بند (%s)\n", $day,
    $d['day']['sent_cnt'], number_format($d['day']['sent_sum'], 2),
    $d['day']['paid_cnt'], number_format($d['day']['paid_sum'], 2));

echo "\nالنتيجة: $ok ناجح · $fail فاشل\n";
exit($fail ? 1 : 0);
