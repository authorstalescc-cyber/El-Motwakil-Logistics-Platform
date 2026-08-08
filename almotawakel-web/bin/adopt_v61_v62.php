<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * اعتماد v61 و v62 في ledger — أداة **لمرة واحدة** بإثبات صارم (2026-08-02).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * الحادثة (مقيسة على الإنتاج، مش افتراض):
 *   الترحيلان **اتطبّقا فعلاً** بتشغيل ملفهما مباشرةً (`php bin/migrate_v61.php`)
 *   زي ما هو موثَّق في CLAUDE.md، لكن التشغيل المباشر **ما بيسجّلش في ledger**.
 *   النتيجة: `pending=61,62` بينما الأعمدة والجداول موجودة وشغّالة، و
 *   `bin/deploy.sh` بيقف عند خطوة ٤ في **كل** رفعة.
 *
 * ⚠️ ليه `bin/reconcile_migrations.php` **مش** بتنفع هنا: هي مقفولة على حادثة
 *    v2–v48 التاريخية (بترفض أي ledger مش `v2…v41` بالظبط).
 *
 * ⚠️⚠️ ليه `migration.php apply-one` بيفشل على v61 وهو متطبّق:
 *    `verify()` بتاعته فيه شرط **لقطة لحظية** — «كل التنزيلات لسه `bank`» —
 *    وده كان صح **لحظة الترحيل** لإثبات إن الترحيل مالوش أثر مالي. لكن بعدها
 *    اتفعّلت قاعدة الدرج واتسجّلت تنزيلات `office` حقيقية، فالشرط ده بقى
 *    **مستحيل يعدّي تاني** — ومش لأن حاجة ناقصة. عشان كده الأداة دي بتثبت
 *    **الجزء البنيوي القابل للإثبات النهارده** بدل ما تعيد تشغيل شرط لقطة فات.
 *
 * ⚠️ **ما بتشغّلش أي ترحيل ولا بتلمس أي بيانات** — كتابتها الوحيدة صفّان في
 *    `schema_migrations` وحدثان في `schema_migration_events`. ومع كده بتقيس
 *    رصيد الخزنة قبل↔بعد وبترفض لو اتحرّك.
 *
 * المعاينة (بلا كتابة):
 *   php bin/adopt_v61_v62.php plan
 *
 * التنفيذ:
 *   php bin/adopt_v61_v62.php apply --confirm-database=DB --operator=NAME
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\MigrationRunner;
use App\Models\Closing;
use App\Models\PermitTrack;
use App\Models\RepCustody;

const ADOPT_VERSIONS = [61, 62];

/** الحالة الوحيدة اللي الأداة دي مصمَّمة لها — أي غيرها بترفض. */
function adopt_assert_state(array $migrations): void
{
    $applied = array_keys(MigrationRunner::applied());
    $pending = array_values(array_diff(array_keys($migrations), $applied));
    sort($pending);
    if ($pending !== ADOPT_VERSIONS) {
        throw new RuntimeException(
            'الأداة دي مخصّصة لحالة pending = 61,62 بالظبط. الحالي: '
            . ($pending ? implode(',', $pending) : 'مفيش'));
    }
    foreach (ADOPT_VERSIONS as $v) {
        if (!isset($migrations[$v])) { throw new RuntimeException("ملف v{$v} مش موجود."); }
    }
}

/**
 * الإثبات البنيوي لكل إصدار — **ده بديل `verify()` مش تخطّي ليها**.
 *
 * v62 بتعدّي على `verify()` بتاعتها زي ما هي (مالهاش شرط لقطة).
 * v61 بتتثبت بالجزء اللي لسه قابل للإثبات: العمود موجود + الشرط فعّال + قِيَم
 * العمود كلها من القائمة المعروفة (مفيش قيمة خردة اتكتبت).
 *
 * @return array<int,array{ok:bool,proof:array<string,bool>}>
 */
function adopt_proofs(): array
{
    // v61 — بنيوي
    $hasCol = (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'rep_custody_deposits'
            AND column_name = 'source'");
    $sources = array_column(Database::all(
        'SELECT DISTINCT source FROM rep_custody_deposits'), 'source');
    $stray = array_values(array_diff($sources, ['bank', 'office', 'carry']));

    $p61 = [
        'rep_custody_deposits.source موجود'      => $hasCol,
        'RepCustody::sourceReady فعّالة'          => $hasCol && RepCustody::sourceReady(),
        'ولا قيمة مصدر خارج bank/office/carry'  => $stray === [],
    ];

    // v62 — بتعدّي على verify() الأصلية
    $m62 = require dirname(__DIR__) . '/bin/migrate_v62.php';
    $p62 = [
        'permit_track_events موجود'  => PermitTrack::refreshReady(),
        'verify() الأصلية بتعدّي'     => is_array($m62) && ($m62['verify'])() === true,
    ];

    return [
        61 => ['ok' => !in_array(false, $p61, true), 'proof' => $p61],
        62 => ['ok' => !in_array(false, $p62, true), 'proof' => $p62],
    ];
}

try {
    $mode = (string) ($argv[1] ?? '');
    if (!in_array($mode, ['plan', 'apply'], true)) {
        throw new RuntimeException('استخدم plan للمعاينة أو apply للتنفيذ.');
    }
    $opt = [];
    foreach (array_slice($argv, 2) as $a) {
        if (!str_starts_with($a, '--') || !str_contains($a, '=')) {
            throw new RuntimeException("معامل غير معروف: {$a}");
        }
        [$k, $v] = explode('=', substr($a, 2), 2);
        $opt[$k] = $v;
    }

    if (!MigrationRunner::ledgerExists()) { throw new RuntimeException('سجل schema_migrations مش موجود.'); }
    MigrationRunner::ensureEventLedger();

    $migrations = MigrationRunner::discover();
    adopt_assert_state($migrations);
    $proofs = adopt_proofs();

    echo "اعتماد v61 و v62 في ledger" . ($mode === 'plan' ? '  (معاينة — بلا كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    echo 'database=' . App::config('db')['name'] . "\n\n";

    $allOk = true;
    foreach (ADOPT_VERSIONS as $v) {
        echo "v{$v} — {$migrations[$v]['filename']}\n";
        foreach ($proofs[$v]['proof'] as $label => $passed) {
            echo '  ' . ($passed ? '✓' : '✗') . " {$label}\n";
            if (!$passed) { $allOk = false; }
        }
        echo "  checksum: {$migrations[$v]['checksum']}\n\n";
    }
    if (!$allOk) { throw new RuntimeException('فشل إثبات — مش هيتعتمد أي إصدار.'); }

    if ($mode === 'plan') {
        echo "كل الإثباتات عدّت. شغّل apply مع --confirm-database و --operator.\n";
        exit(0);
    }

    if ((string) ($opt['confirm-database'] ?? '') !== (string) App::config('db')['name']) {
        throw new RuntimeException('--confirm-database مش مطابقة لقاعدة الاتصال.');
    }
    $operator = trim((string) ($opt['operator'] ?? ''));
    if ($operator === '' || mb_strlen($operator) > 120) {
        throw new RuntimeException('--operator مطلوب (اسم المنفّذ).');
    }

    /* ⚠️ الأداة ما بتلمسش بيانات، بس بنقيس الخزنة برضه — أرخص حارس ممكن
       يمسك لو حد عدّل الملف بعدين وحطّ فيه كتابة على حساب. */
    $before = Closing::currentBalance();

    MigrationRunner::acquireLock();
    try {
        Database::conn()->beginTransaction();
        foreach (ADOPT_VERSIONS as $v) {
            $m = $migrations[$v];
            Database::exec(
                'INSERT INTO schema_migrations(version,filename,checksum,apply_mode,result,execution_ms)
                 VALUES(?,?,?,?,?,0)',
                [$v, $m['filename'], $m['checksum'], 'adopted', 'success']);
            MigrationRunner::recordEvent([
                'version'         => $v,
                'action'          => 'adopted_after_manual_apply',
                'old_checksum'    => null,
                'new_checksum'    => $m['checksum'],
                'operator_name'   => $operator,
                'evidence_sha256' => hash('sha256', json_encode(
                    ['v' => $v, 'file' => $m['filename'], 'sum' => $m['checksum'],
                     'proof' => $proofs[$v]['proof']],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'details'         => [
                    'runner' => 'adopt_v61_v62.php',
                    'why'    => 'اتطبّق بتشغيل الملف مباشرةً فما اتسجّلش؛ الإثبات بنيوي',
                    'proof'  => $proofs[$v]['proof'],
                ],
            ]);
        }
        Database::conn()->commit();
    } catch (\Throwable $e) {
        if (Database::conn()->inTransaction()) { Database::conn()->rollBack(); }
        throw $e;
    } finally {
        MigrationRunner::releaseLock();
    }

    $after = Closing::currentBalance();
    if (abs($after - $before) > 0.005) {
        throw new RuntimeException('⚠️ رصيد الخزنة اتغيّر — راجع فورًا: ' . $before . ' ← ' . $after);
    }

    MigrationRunner::assertLedgerMatches(MigrationRunner::discover());
    echo "✓ اتسجّل v61 و v62 في ledger (apply_mode=adopted)\n";
    echo '✓ رصيد الخزنة زي ما هو: ' . number_format($before, 2) . "\n";
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, '✗ ' . $e->getMessage() . "\n");
    exit(1);
}
