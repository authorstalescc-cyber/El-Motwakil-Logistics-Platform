<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v110 — الحساب البنكي هو المحفظة الأم، والفيز التابعة تشترك في رصيده.
 *
 * قواعد الترحيل:
 *   • نفس اسم البنك الحالي = حساب واحد (قرار المالك 2026-08-26).
 *   • `visa_deposits` يظل جدول الحركات حفاظًا على deposit_id/visa_deposit_id.
 *   • كل رصيد افتتاحي قديم يتحول لحركة مؤرخة ثم يُصفّر العمود.
 *   • الرصيد الكلي قبل وبعد لازم يتطابق بالمليم.
 *   • DDL يعمل commit ضمني؛ كل خطوة idempotent والتحقق يثبت حالة دائمة.
 *
 * التشغيل: php bin/migrate_v110.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\BankAccount;
use App\Models\VisaCard;

$v110Dry = in_array('--dry-run', $argv ?? [], true);
$v110Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v110Say = static function (string $m) use ($v110Direct): void { if ($v110Direct) { echo $m . "\n"; } };

$v110Table = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$t]);
$v110Col = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', [$t, $c]);
$v110Index = static fn (string $t, string $i): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema=DATABASE() AND table_name=? AND index_name=?', [$t, $i]);
$v110Constraint = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.table_constraints
      WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name=?', [$t, $c]);

$v110Create = "CREATE TABLE bank_accounts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(180)  NOT NULL COMMENT 'اسم الحساب الظاهر',
    bank_name   VARCHAR(180)  NULL COMMENT 'اسم البنك',
    account_ref VARCHAR(80)   NULL COMMENT 'آخر أرقام/مرجع الحساب — لا نخزن الرقم كاملًا',
    owner       VARCHAR(180)  NULL,
    status      VARCHAR(20)   NOT NULL DEFAULT 'active',
    note        VARCHAR(255)  NULL,
    legacy_key  VARCHAR(191)  NULL COMMENT 'مفتاح ترحيل v110 فقط — يمنع التكرار',
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bank_legacy (legacy_key),
    KEY idx_bank_status (status),
    KEY idx_bank_name (bank_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الحسابات البنكية — المحفظة الأم لكروت الفيزا (v110)'";

$v110Up = static function () use (
    $v110Dry, $v110Say, $v110Table, $v110Col, $v110Index, $v110Constraint, $v110Create
): void {
    if (!$v110Table('visa_cards') || !$v110Table('visa_deposits')) {
        throw new RuntimeException('شغّل migrate_v22 قبل v110.');
    }

    if (!$v110Table('bank_accounts')) {
        if (!$v110Dry) { Database::conn()->exec($v110Create); }
        $v110Say('  ✓ جدول bank_accounts' . ($v110Dry ? ' (تجربة)' : ''));
    } else { $v110Say('  • جدول bank_accounts موجود'); }

    $columns = [
        ['visa_cards', 'bank_account_id', 'INT UNSIGNED NULL AFTER bank'],
        ['visa_deposits', 'bank_account_id', 'INT UNSIGNED NULL AFTER card_id'],
    ];
    if ($v110Table('client_payments')) {
        $columns[] = ['client_payments', 'bank_account_id', 'INT UNSIGNED NULL AFTER visa_card_id'];
    }
    if ($v110Table('company_safe_entries')) {
        $columns[] = ['company_safe_entries', 'bank_account_id', 'INT UNSIGNED NULL AFTER visa_card_id'];
    }
    foreach ($columns as [$table, $column, $ddl]) {
        if ($v110Col($table, $column)) { $v110Say("  • $table.$column موجود"); continue; }
        if (!$v110Dry) { Database::conn()->exec("ALTER TABLE $table ADD COLUMN $column $ddl"); }
        $v110Say("  ✓ عمود $table.$column" . ($v110Dry ? ' (تجربة)' : ''));
    }
    if ($v110Dry) { return; }

    // كل اسم بنك حالي يبقى حسابًا واحدًا. عند إعادة التشغيل نحافظ على الربط
    // القائم بدل إنشاء حساب ثانٍ لو اسم البنك اتعدل بعد الترحيل.
    $cards = Database::all(
        'SELECT id,name,owner,bank,bank_account_id,opening_balance,created_at FROM visa_cards ORDER BY id'
    );
    $existingByBank = [];
    foreach ($cards as $card) {
        $accountId = (int) ($card['bank_account_id'] ?? 0);
        $bank = trim((string) ($card['bank'] ?? ''));
        if ($accountId <= 0 || $bank === '') { continue; }
        $bankKey = 'bank:' . sha1(mb_strtolower($bank));
        if (isset($existingByBank[$bankKey]) && $existingByBank[$bankKey] !== $accountId) {
            throw new RuntimeException('اسم بنك واحد مربوط بأكثر من حساب قبل اكتمال v110؛ راجع البيانات يدويًا.');
        }
        $existingByBank[$bankKey] = $accountId;
    }

    $groups = [];
    foreach ($cards as $card) {
        $bank = trim((string) ($card['bank'] ?? ''));
        $bankKey = $bank !== '' ? 'bank:' . sha1(mb_strtolower($bank)) : '';
        $existingAccount = (int) ($card['bank_account_id'] ?? 0);
        if ($existingAccount <= 0 && $bankKey !== '') {
            $existingAccount = (int) ($existingByBank[$bankKey] ?? 0);
        }
        $key = $existingAccount > 0 ? 'account:' . $existingAccount
            : ($bankKey !== '' ? $bankKey : 'card:' . (int) $card['id']);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'bank' => $bank !== '' ? $bank : null,
                'name' => $bank !== '' ? 'حساب ' . $bank : 'حساب ' . $card['name'],
                'owner' => ($card['owner'] ?? '') !== '' ? $card['owner'] : null,
                'existing_account_id' => $existingAccount ?: null,
                'cards' => [],
            ];
        } elseif (($groups[$key]['owner'] ?? null) !== ($card['owner'] ?? null)) {
            $groups[$key]['owner'] = null;
        }
        $groups[$key]['cards'][] = $card;
    }

    foreach ($groups as $legacyKey => $group) {
        $accountId = (int) ($group['existing_account_id'] ?? 0);
        if (!$accountId) {
            $accountId = (int) Database::scalar('SELECT id FROM bank_accounts WHERE legacy_key=?', [$legacyKey]);
        }
        if (!$accountId) {
            Database::exec(
                'INSERT INTO bank_accounts(name,bank_name,owner,status,legacy_key) VALUES(?,?,?,\'active\',?)',
                [$group['name'], $group['bank'], $group['owner'], $legacyKey]
            );
            $accountId = Database::lastId();
        }
        foreach ($group['cards'] as $card) {
            $cardId = (int) $card['id'];
            Database::exec('UPDATE visa_cards SET bank_account_id=? WHERE id=?', [$accountId, $cardId]);
            $opening = round((float) $card['opening_balance'], 2);
            if (abs($opening) > 0.005) {
                $note = 'ترحيل رصيد افتتاحي v110 — كارت #' . $cardId;
                $exists = (int) Database::scalar(
                    'SELECT COUNT(*) FROM visa_deposits WHERE card_id=? AND note=?', [$cardId, $note]
                );
                if (!$exists) {
                    $date = substr((string) ($card['created_at'] ?? ''), 0, 10);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = work_date(); }
                    Database::exec(
                        'INSERT INTO visa_deposits(card_id,bank_account_id,dep_date,amount,note)
                         VALUES(?,?,?,?,?)', [$cardId, $accountId, $date, $opening, $note]
                    );
                }
                Database::exec('UPDATE visa_cards SET opening_balance=0 WHERE id=?', [$cardId]);
            }
        }
    }

    Database::exec(
        'UPDATE visa_deposits d JOIN visa_cards c ON c.id=d.card_id
            SET d.bank_account_id=c.bank_account_id WHERE d.bank_account_id IS NULL'
    );

    // صف تاريخي يتيم لا يُرمى: يوضع في حساب مراجعة مستقل بدل تخمين صاحبه.
    $orphans = (int) Database::scalar('SELECT COUNT(*) FROM visa_deposits WHERE bank_account_id IS NULL');
    if ($orphans > 0) {
        $legacyKey = 'legacy:orphan-deposits';
        $fallback = (int) Database::scalar('SELECT id FROM bank_accounts WHERE legacy_key=?', [$legacyKey]);
        if (!$fallback) {
            Database::exec(
                "INSERT INTO bank_accounts(name,status,note,legacy_key)
                 VALUES('حساب حركات قديمة للمراجعة','inactive','حركات قديمة بلا كارت صالح',?)", [$legacyKey]
            );
            $fallback = Database::lastId();
        }
        Database::exec('UPDATE visa_deposits SET bank_account_id=? WHERE bank_account_id IS NULL', [$fallback]);
    }

    if ($v110Table('client_payments')) {
        Database::exec(
            'UPDATE client_payments p JOIN visa_deposits d ON d.id=p.deposit_id
                SET p.bank_account_id=d.bank_account_id
              WHERE p.route=\'visa\' AND p.bank_account_id IS NULL'
        );
        Database::exec(
            'UPDATE client_payments p JOIN visa_cards c ON c.id=p.visa_card_id
                SET p.bank_account_id=c.bank_account_id
              WHERE p.route=\'visa\' AND p.bank_account_id IS NULL'
        );
    }
    if ($v110Table('company_safe_entries')) {
        Database::exec(
            'UPDATE company_safe_entries e JOIN visa_deposits d ON d.id=e.visa_deposit_id
                SET e.bank_account_id=d.bank_account_id
              WHERE e.kind IN (\'to_visa\',\'from_visa\') AND e.bank_account_id IS NULL'
        );
        Database::exec(
            'UPDATE company_safe_entries e JOIN visa_cards c ON c.id=e.visa_card_id
                SET e.bank_account_id=c.bank_account_id
              WHERE e.kind IN (\'to_visa\',\'from_visa\') AND e.bank_account_id IS NULL'
        );
    }

    // الحركات الجديدة تخص الحساب وقد لا تخص كارتًا بعينه.
    $cardNullable = (string) Database::scalar(
        "SELECT is_nullable FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='visa_deposits' AND column_name='card_id'"
    );
    if ($cardNullable !== 'YES') {
        Database::conn()->exec('ALTER TABLE visa_deposits MODIFY card_id INT UNSIGNED NULL');
    }

    foreach ([
        ['visa_cards', 'idx_vc_bank_account', 'bank_account_id'],
        ['visa_deposits', 'idx_vd_bank_account', 'bank_account_id'],
        ['client_payments', 'idx_cp_bank_account', 'bank_account_id'],
        ['company_safe_entries', 'idx_cse_bank_account', 'bank_account_id'],
    ] as [$table, $index, $column]) {
        if (!$v110Table($table) || !$v110Col($table, $column) || $v110Index($table, $index)) { continue; }
        Database::conn()->exec("ALTER TABLE $table ADD KEY $index ($column)");
    }

    Database::conn()->exec('ALTER TABLE visa_cards MODIFY bank_account_id INT UNSIGNED NOT NULL');
    Database::conn()->exec('ALTER TABLE visa_deposits MODIFY bank_account_id INT UNSIGNED NOT NULL');

    if (!$v110Constraint('visa_cards', 'fk_vc_bank_account')) {
        Database::conn()->exec(
            'ALTER TABLE visa_cards ADD CONSTRAINT fk_vc_bank_account
             FOREIGN KEY(bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT'
        );
    }
    if (!$v110Constraint('visa_deposits', 'fk_vd_bank_account')) {
        Database::conn()->exec(
            'ALTER TABLE visa_deposits ADD CONSTRAINT fk_vd_bank_account
             FOREIGN KEY(bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT'
        );
    }
    if ($v110Table('client_payments') && $v110Col('client_payments','bank_account_id')
        && !$v110Constraint('client_payments','fk_cp_bank_account')) {
        Database::conn()->exec(
            'ALTER TABLE client_payments ADD CONSTRAINT fk_cp_bank_account
             FOREIGN KEY(bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT'
        );
    }
    if ($v110Table('company_safe_entries') && $v110Col('company_safe_entries','bank_account_id')
        && !$v110Constraint('company_safe_entries','fk_cse_bank_account')) {
        Database::conn()->exec(
            'ALTER TABLE company_safe_entries ADD CONSTRAINT fk_cse_bank_account
             FOREIGN KEY(bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT'
        );
    }

    BankAccount::refreshReady();
};

$v110Verify = static function () use ($v110Say, $v110Table, $v110Col, $v110Constraint): bool {
    $ok = true;
    foreach (['bank_accounts', 'visa_cards', 'visa_deposits'] as $table) {
        if (!$v110Table($table)) { $v110Say("  ✗ جدول ناقص: $table"); $ok = false; }
    }
    foreach ([['visa_cards','bank_account_id'],['visa_deposits','bank_account_id']] as [$table,$column]) {
        if (!$v110Col($table,$column)) { $v110Say("  ✗ عمود ناقص: $table.$column"); $ok = false; }
    }
    if (!$ok) { return false; }
    if ((int) Database::scalar('SELECT COUNT(*) FROM visa_cards WHERE bank_account_id IS NULL') !== 0) {
        $v110Say('  ✗ كروت بلا حساب بنكي'); $ok = false;
    }
    if ((int) Database::scalar('SELECT COUNT(*) FROM visa_deposits WHERE bank_account_id IS NULL') !== 0) {
        $v110Say('  ✗ حركات بلا حساب بنكي'); $ok = false;
    }
    if (!$v110Constraint('visa_cards','fk_vc_bank_account') || !$v110Constraint('visa_deposits','fk_vd_bank_account')) {
        $v110Say('  ✗ مفاتيح الحساب البنكي ناقصة'); $ok = false;
    }
    foreach ([['client_payments','fk_cp_bank_account'],['company_safe_entries','fk_cse_bank_account']] as [$table,$fk]) {
        if ($v110Table($table) && $v110Col($table,'bank_account_id') && !$v110Constraint($table,$fk)) {
            $v110Say("  ✗ مفتاح الحساب البنكي ناقص: $table"); $ok = false;
        }
    }
    $split = (int) Database::scalar(
        "SELECT COUNT(*) FROM (
           SELECT LOWER(TRIM(bank)) bank_key,COUNT(DISTINCT bank_account_id) accounts_n
             FROM visa_cards WHERE bank IS NOT NULL AND TRIM(bank)<>''
            GROUP BY LOWER(TRIM(bank)) HAVING COUNT(DISTINCT bank_account_id)<>1
         ) x"
    );
    if ($split) { $v110Say('  ✗ اسم بنك واحد اتقسم على أكثر من حساب'); $ok = false; }
    if ($ok) { $v110Say('  ✓ كل كارت وحركة لهما حساب، ونفس اسم البنك متجمع، والمفاتيح فعالة.'); }
    return $ok;
};

if ($v110Direct) {
    echo 'v110 — الحسابات البنكية برصيد موحّد' . ($v110Dry ? ' (تجربة)' : '') . "\n";
    echo str_repeat('=', 64) . "\n";

    $before = round(
        (float) Database::scalar('SELECT COALESCE(SUM(opening_balance),0) FROM visa_cards')
        + (float) Database::scalar('SELECT COALESCE(SUM(amount),0) FROM visa_deposits')
        - (float) Database::scalar('SELECT COALESCE(SUM(amount),0) FROM (' . VisaCard::paymentsUnion() . ') t'),
        2
    );
    $v110Up();
    if ($v110Dry) { echo "\nشغّل من غير --dry-run للتطبيق.\n"; exit(0); }
    if (!$v110Verify()) { fwrite(STDERR, "فشل التحقق بعد v110.\n"); exit(1); }
    BankAccount::refreshReady();
    $after = round((float) BankAccount::summary()['balance'], 2);
    if (abs($after - $before) > 0.005) {
        fwrite(STDERR, '✗✗ الرصيد تغيّر: قبل ' . number_format($before,2) . ' / بعد ' . number_format($after,2) . "\n");
        exit(1);
    }
    printf("✓ الرصيد محفوظ بالمليم: %s · الحسابات %d · الكروت %d\n",
        number_format($after, 2), count(BankAccount::all()), count(VisaCard::all()));
    echo "\n✓ تمّ. وبعده: php bin/verify_v110.php\n";
}

return ['up' => $v110Up, 'verify' => $v110Verify];
