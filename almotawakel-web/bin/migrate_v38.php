<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v38 — إصلاح مفاتيح تطبيع أسماء الأطراف (party_aliases).
 *
 * المشكلة (اتصادفت فعليًا عند إدخال إذن 63):
 *   SQLSTATE[23000] Duplicate entry «المصرية الألمانية» for key companies.uq_company_name
 *
 * السبب: هجرة v31 عبّت `normalized_name` وصفوف `party_aliases` بتطبيع SQL مبسّط
 * (`LOWER(TRIM(REGEXP_REPLACE(...)))`) — ده بيوحّد المسافات وبس. لكن الكود بيقرا
 * بـ`Maintenance::normkey()` اللي كمان **بتوحّد الألف** (أ إ آ ← ا) **والتاء المربوطة**
 * (ة ← ه) والياء والهمزات. فاسم زي «المصرية الألمانية» مفتاحه في الجدول
 * `المصرية الألمانية` بينما الكود بيدوّر على `المصريه الالمانيه` — ما يلاقيهوش.
 *
 * النتيجة: `PartyMaster::resolve()` بترجّع null → `create()` بتحاول تدخّل الاسم من
 * جديد → قاعدة البيانات بترفض بالمفتاح الفريد `uq_company_name`.
 *
 * الإصلاح هنا: أعِد حساب `normalized_name` بـ`normkey()` لكل الأطراف، وأضِف
 * صف alias بالمفتاح الصحيح لكل سجل ناقص. الـaliases القديمة **ما بتتمسحش**
 * (اسم بديل زيادة ما بيضرّش، والمفتاح القديم فضل شغّال لو حد بيدوّر بيه).
 *
 * ⚠️ لا يمسّ أي حساب مالي ولا عهدة ولا تقفيل — أسماء ومفاتيح بحث فقط.
 * ⚠️ التصادمات (اسمان مختلفان لهما نفس المفتاح، مثل «الأندلس» و«الاندلس») **بتتبلّغ
 *    ولا تُدمَج تلقائيًا** — الدمج قرار صاحب الشركة من شاشة البيانات الأساسية.
 *
 * آمن لإعادة التشغيل (idempotent) + transaction واحدة.
 * التشغيل:  php bin/migrate_v38.php --dry-run   ثم   php bin/migrate_v38.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\Maintenance;
use App\Models\PartyMaster;

$dry = in_array('--dry-run', $argv ?? [], true);

function v38Say(string $m): void { echo $m . PHP_EOL; }

v38Say('ترحيل v38 — إصلاح مفاتيح تطبيع أسماء الأطراف…' . ($dry ? '  [معاينة فقط]' : ''));

if (!Database::scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='party_aliases'")) {
    v38Say('  ! جدول party_aliases غير موجود — شغّل migrate_v31.php الأول.');
    exit(1);
}

$balanceBefore = Closing::currentBalance();
v38Say('  · رصيد الخزنة قبل الترحيل: ' . number_format($balanceBefore, 2) . ' ج');

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    $totals = ['norm' => 0, 'alias' => 0];
    $clashes = [];

    foreach (PartyMaster::TYPES as $type => $meta) {
        $table = $meta['table'];
        $rows  = Database::all("SELECT id, name, normalized_name FROM `$table` ORDER BY id");
        $fixedNorm = 0; $addedAlias = 0;

        foreach ($rows as $row) {
            $id   = (int)$row['id'];
            $name = trim((string)$row['name']);
            $key  = Maintenance::normkey($name);
            if ($key === '') { continue; }

            if ((string)$row['normalized_name'] !== $key) {
                if (!$dry) { Database::exec("UPDATE `$table` SET normalized_name=? WHERE id=?", [$key, $id]); }
                $fixedNorm++;
            }

            $owner = Database::row(
                'SELECT party_id FROM party_aliases WHERE party_type=? AND normalized_alias=?',
                [$type, $key]
            );
            if ($owner) {
                // المفتاح متاخد بالفعل: لو لسجل تاني فده تصادم حقيقي يحتاج قرار دمج يدوي.
                if ((int)$owner['party_id'] !== $id) {
                    $other = Database::row("SELECT name FROM `$table` WHERE id=?", [(int)$owner['party_id']]);
                    // ملاحظة: لازم {} حوالين المتغيّر — PHP بتعتبر بايتات «» جزءًا من اسم المتغيّر.
                    $clashes[] = "{$table}: «{$name}» (#{$id}) ↔ «" . (string)($other['name'] ?? '?') . '» (#' . (int)$owner['party_id'] . ") — مفتاح واحد [{$key}]";
                }
                continue;
            }

            if (!$dry) {
                Database::exec(
                    'INSERT INTO party_aliases (party_type, party_id, alias, normalized_alias, is_primary)
                     VALUES (?,?,?,?,0)',
                    [$type, $id, $name, $key]
                );
            }
            $addedAlias++;
        }

        $totals['norm']  += $fixedNorm;
        $totals['alias'] += $addedAlias;
        v38Say("  · $table: صُحّح normalized_name لـ$fixedNorm سجل، وأُضيف $addedAlias مفتاح بحث ناقص.");
    }

    // تحقّق سلامة: كل طرف فعّال لازم يبقى قابلاً للحل باسمه الأساسي.
    if (!$dry) {
        $unresolved = [];
        foreach (PartyMaster::TYPES as $type => $meta) {
            foreach (Database::all("SELECT id, name FROM `{$meta['table']}` WHERE merged_into_id IS NULL") as $row) {
                if (trim((string)$row['name']) === '') { continue; }
                if (!PartyMaster::resolve($type, (string)$row['name'])) {
                    $unresolved[] = "{$meta['table']} #{$row['id']} «{$row['name']}»";
                }
            }
        }
        if ($unresolved) {
            throw new RuntimeException("فشل التحقّق: سجلات لسه مش قابلة للحل:\n  - " . implode("\n  - ", array_slice($unresolved, 0, 20)));
        }
    }

    $balanceAfter = Closing::currentBalance();
    if (abs($balanceAfter - $balanceBefore) > 0.001) {
        throw new RuntimeException('فشل التحقّق: رصيد الخزنة اتغيّر — الترحيل ده مالوش أي أثر مالي المفروض.');
    }

    if ($dry) { $pdo->rollBack(); v38Say('  · معاينة فقط — اتلغى كل شيء.'); }
    else      { $pdo->commit();   v38Say('  ✓ تم الحفظ.'); }

    v38Say('  · الإجمالي: ' . $totals['norm'] . ' تطبيع + ' . $totals['alias'] . ' مفتاح بحث.');
    v38Say('  · رصيد الخزنة بعد الترحيل: ' . number_format($balanceAfter, 2) . ' ج  (لم يتغيّر ✓)');

    if ($clashes) {
        v38Say('');
        v38Say('  ⚠️ أسماء مختلفة بتطبيع واحد — محتاجة قرار دمج يدوي من «البيانات الأساسية»:');
        foreach ($clashes as $c) { v38Say('     - ' . $c); }
        v38Say('     (سيبناها زي ما هي — الدمج التلقائي ممنوع.)');
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    v38Say('  ✗ فشل الترحيل ولم يُحفظ أي تغيير: ' . $e->getMessage());
    exit(1);
}

v38Say('انتهى ترحيل v38.');
