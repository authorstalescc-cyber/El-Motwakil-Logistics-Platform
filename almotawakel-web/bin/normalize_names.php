<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * توحيد المسميات المكررة تلقائياً (سائقون / أصحاب سيارات / شركات / عملاء / جهات …).
 * المنطق في App\Models\Maintenance::normalizeNames — تُستخدم أيضاً من واجهة الإعدادات.
 *
 * معاينة بدون تغيير:   php bin/normalize_names.php --dry
 * تطبيق فعلي:          php bin/normalize_names.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\Maintenance;

$DRY = in_array('--dry', $argv, true);

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'dim'=>"\033[90m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

say("═══════════════════════════════════════════════", 'hi');
say("  توحيد المسميات المكررة" . ($DRY ? "  [معاينة فقط]" : ""), 'hi');
say("═══════════════════════════════════════════════", 'hi');

$rep = Maintenance::normalizeNames($DRY);

foreach ($rep['groups'] as $g) {
    if ($g['merges'] > 0) {
        say(sprintf("▸ %s: %d مجموعة | %d صف مُوحَّد | %d عنصر قائمة محذوف",
            $g['label'], $g['merges'], $g['rows'], $g['lookupDel']), 'ok');
        foreach ($g['samples'] as $s) {
            say("    «" . implode('» ، «', $s['from']) . "»  →  «" . $s['to'] . "»", 'dim');
        }
    } else {
        say("▸ {$g['label']}: لا تكرار", 'dim');
    }
}

say("───────────────────────────────────────────────", 'hi');
say(sprintf("الإجمالي: %d مجموعة | %d صف مُوحَّد | %d عنصر قائمة محذوف",
    $rep['totals']['merges'], $rep['totals']['rows'], $rep['totals']['lookupDel']), 'hi');
say($DRY ? "معاينة فقط. للتطبيق:  php bin/normalize_names.php" : "✓ اكتمل توحيد المسميات.", $DRY ? 'y' : 'ok');
say("═══════════════════════════════════════════════", 'hi');
