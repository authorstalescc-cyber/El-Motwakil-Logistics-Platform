<?php
/**
 * ترحيل v8 — فصل الكشف عن الضمان في المديونيات:
 *   • الضمان  → حساب مستقل «ياسر أبو عمر / كونتراكت» (account_key='guarantee').
 *   • الكشف   → يبقى حساب account_key='inspection' لكن المستحق يُختار من الإعدادات
 *              (يُفرَّغ الاسم لو كان لا يزال الاسم الافتراضي القديم = ياسر أبو عمر).
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v8.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Debt;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 8", 'hi');
say("  (فصل الكشف عن الضمان — كل واحد لمستحقه)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// (1) حساب الضمان (ياسر أبو عمر)
$gid = Debt::ensureGuaranteeAccount();
say("✓ حساب الضمان: #$gid — " . Debt::account($gid)['name'], 'ok');

// (2) حساب الكشف: تفريغ الاسم لو كان لا يزال الاسم الافتراضي القديم (لم يعدّله المستخدم)
$iid = Debt::ensureInspectionAccount();
$insp = Debt::account($iid);
if (trim((string)$insp['name']) === Debt::GUARANTEE_DEFAULT_NAME) {
    Database::exec(
        "UPDATE debt_accounts SET name = '', note = ? WHERE id = ?",
        ['مديونية الكشف — تُحتسب تلقائياً من حاويات الأذونات', $iid]
    );
    say("✓ حساب الكشف: #$iid — أُفرِغ اسم المستحق (اختره من الإعدادات)", 'ok');
} else {
    say("• حساب الكشف: #$iid — اسمه مخصّص («" . ($insp['name'] ?: 'غير محدّد') . "») — تُرك كما هو", 'y');
}

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v8 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
