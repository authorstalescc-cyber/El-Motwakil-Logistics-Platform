<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * تحويل الأرصدة الافتتاحية لكروت الفيزا (visa_cards.opening_balance)
 * إلى شحنات مؤرَّخة (visa_deposits) بتاريخ 2026-07-11، وتصفير الافتتاحي.
 *
 * بيانات حسّاسة — الأمان:
 *   • يعمل داخل transaction (كله أو لا شيء).
 *   • idempotent: يحوّل فقط الكروت التي opening_balance <> 0؛ بعد التحويل تصير 0
 *     فإعادة التشغيل لا تُنشئ شحنات مكرّرة.
 *   • الثابت المضمون: رصيد كل كارت **قبل = بعد** (مجرد نقل من افتتاحي إلى شحنة).
 *     لو اختلف أي رصيد → rollback فورًا.
 *
 * التشغيل:  php bin/convert_visa_openings.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\VisaCard;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

const DEP_DATE = '2026-07-11';           // بتاريخ ١١ يوليو (كما طلب صاحب الشركة)
const DEP_NOTE = 'رصيد افتتاحي (محوَّل ١١ يوليو)';

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  تحويل الأرصدة الافتتاحية لكروت الفيزا → شحنات بتاريخ " . DEP_DATE, 'hi');
say("═══════════════════════════════════════════════", 'hi');

// لقطة الأرصدة قبل التحويل (للتحقق لاحقًا)
$before = [];
foreach (Database::all("SELECT id FROM visa_cards") as $c) {
    $before[(int)$c['id']] = VisaCard::balanceOf((int)$c['id']);
}

$targets = Database::all("SELECT id, name, opening_balance FROM visa_cards WHERE opening_balance <> 0 ORDER BY id");
if (!$targets) {
    say("لا كروت لها رصيد افتتاحي — لا شيء للتحويل (ربما نُفِّذ سابقًا).", 'y');
    exit(0);
}
say("سيتم تحويل " . count($targets) . " كارت:", 'y');

$pdo->beginTransaction();
try {
    foreach ($targets as $t) {
        $cid = (int)$t['id'];
        $amt = (float)$t['opening_balance'];
        Database::exec(
            "INSERT INTO visa_deposits (card_id, dep_date, amount, note) VALUES (?,?,?,?)",
            [$cid, DEP_DATE, $amt, DEP_NOTE]);
        Database::exec("UPDATE visa_cards SET opening_balance = 0 WHERE id = ?", [$cid]);
        say(sprintf("  ✓ كارت #%d (%s): %s → شحنة بتاريخ %s", $cid, $t['name'], number_format($amt,2), DEP_DATE), 'ok');
    }

    // التحقق الصارم: رصيد كل كارت لم يتغيّر
    $bad = [];
    foreach ($before as $cid => $bal) {
        $now = VisaCard::balanceOf($cid);
        if (round($now - $bal, 2) !== 0.0) {
            $bad[] = "كارت #$cid: قبل=$bal بعد=$now";
        }
    }
    if ($bad) {
        $pdo->rollBack();
        say("✗ اختلفت أرصدة — تراجُع كامل (rollback):", 'err');
        foreach ($bad as $b) { say("   $b", 'err'); }
        exit(1);
    }

    $pdo->commit();
    say("───────────────────────────────────────────────", 'hi');
    say("✓ تم التحويل بنجاح. كل الأرصدة مطابقة تمامًا قبل/بعد.", 'ok');
    say("  الأرصدة الافتتاحية الآن = 0، والمبالغ صارت شحنات بتاريخ " . DEP_DATE . ".", 'y');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    say("✗ خطأ — تراجُع كامل: " . $e->getMessage(), 'err');
    exit(1);
}
