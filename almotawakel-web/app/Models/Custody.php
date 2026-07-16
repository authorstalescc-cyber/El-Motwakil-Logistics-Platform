<?php
namespace App\Models;

use App\Core\App;
use App\Core\Database;

/**
 * رصيد عهدة المكتب (محفظة العهدة) — صفحة مستقلة بسجل تراكمي.
 *
 * القواعد (كما اتفق عليها صاحب الشركة):
 *  • الرصيد يزيد بـ«إضافة عهدة يدوية» (توب-أب في جدول manual_custody).
 *  • الرصيد يقل بعهد السائقين فقط (bawalis.custody) — لا تُخصم منه مصروفات المكتب.
 *  • يُطرح مبلغ ثابت (افتراضي 100ج) من عهدة كل بوليصة قبل خصمها من الرصيد:
 *      خصم البوليصة = عهدة السائق − 100 (لا يقل عن صفر). الـ100 تبقى في الرصيد.
 *  • استثناء: سيارات مُعيَّنة (setting: custody_deduction_exempt_cars، افتراضيًا «س ع م 8737»)
 *    تُخصم عهدتها كاملة بلا طرح الـ100. المطابقة على bawalis.car_no بعد إزالة المسافات.
 *  • قاعدة: عهدة أي بوليصة لا يمكن أن تتجاوز رصيد عهدة المكتب المتاح.
 *
 * الرصيد = Σ(الإضافات اليدوية) − Σ(عهدة كل بوليصة − 100 [أو كاملة للسيارات المستثناة]).
 */
class Custody
{
    /** المبلغ المطروح من عهدة كل بوليصة (setting: custody_bill_deduction، افتراضي 100). */
    public static function deductionRate(): float
    {
        return (float) App::setting('custody_bill_deduction', '100');
    }

    /** توحيد رقم السيارة للمقارنة: إزالة كل المسافات (يطابق REPLACE(car_no,' ','') في SQL). */
    public static function normCar(?string $carNo): string
    {
        return preg_replace('/\s+/u', '', (string) $carNo);
    }

    /**
     * أرقام السيارات المستثناة من قاعدة طرح الـ100 (تُخصم عهدتها كاملة).
     * setting: custody_deduction_exempt_cars — قائمة مفصولة بفواصل/أسطر، افتراضيًا «س ع م 8737».
     * تُعاد كخريطة [رقم مُوحَّد => true] لسرعة الفحص.
     */
    public static function exemptCars(): array
    {
        $raw = (string) App::setting('custody_deduction_exempt_cars', 'س ع م 8737');
        $out = [];
        foreach (preg_split('/[,\n\r]+/u', $raw) as $c) {
            $norm = self::normCar($c);
            if ($norm !== '') { $out[$norm] = true; }
        }
        return $out;
    }

    /** هل هذه السيارة مستثناة من طرح الـ100؟ */
    public static function isExempt(?string $carNo): bool
    {
        $norm = self::normCar($carNo);
        return $norm !== '' && isset(self::exemptCars()[$norm]);
    }

    /**
     * تعبير SQL يحسب المخصوم من الرصيد لكل بوليصة داخل SUM(...):
     *  • سيارة مستثناة → تُخصم العهدة كاملة.
     *  • غير ذلك → GREATEST(custody − rate, 0).
     * يُعيد [sql, params]؛ تُدمج params بالترتيب في مكان التعبير داخل الاستعلام.
     */
    public static function deductionSql(string $custodyCol = 'custody', string $carCol = 'car_no'): array
    {
        $rate   = self::deductionRate();
        $exempt = array_keys(self::exemptCars());
        if (!$exempt) {
            return ["GREATEST($custodyCol - ?, 0)", [$rate]];
        }
        $ph  = implode(',', array_fill(0, count($exempt), '?'));
        $sql = "CASE WHEN REPLACE($carCol, ' ', '') IN ($ph) THEN $custodyCol"
             . " ELSE GREATEST($custodyCol - ?, 0) END";
        return [$sql, array_merge($exempt, [$rate])];
    }

    /**
     * خصم بوليصة واحدة من الرصيد = العهدة − المطروح (لا يقل عن صفر).
     * السيارة المستثناة تُخصم عهدتها كاملة (المطروح = 0).
     */
    public static function billDeduction(float $custody, ?string $carNo = null): float
    {
        if ($custody <= 0) { return 0.0; }
        $rate = self::isExempt($carNo) ? 0.0 : self::deductionRate();
        return round(max($custody - $rate, 0), 2);
    }

    // ─────────────────────────────────────────────── الرصيد والإجماليات

    /** إجمالي الإضافات اليدوية (توب-أب). */
    public static function topupsTotal(): float
    {
        return round((float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM manual_custody"), 2);
    }

    /**
     * إجمالي عهد السائقين المخصومة من الرصيد (بعد طرح الـ100 لكل بوليصة).
     * يمكن استثناء بوليصة (عند تعديلها) لحساب المتاح لها.
     */
    public static function driverCustodyOut(int $excludeBillId = 0): float
    {
        [$dedSql, $params] = self::deductionSql('custody', 'car_no');
        // is_legacy=0: البوالص المُرحَّلة من الأرشيف (v26) خارج رصيد العهدة تمامًا
        $sql = "SELECT COALESCE(SUM($dedSql),0)
                FROM bawalis WHERE is_legacy = 0 AND custody > 0" . ($excludeBillId > 0 ? " AND id <> ?" : "");
        if ($excludeBillId > 0) { $params[] = $excludeBillId; }
        return round((float) Database::scalar($sql, $params), 2);
    }

    /**
     * الرصيد الحالي في العهدة.
     * يُحسب عبر نظام الإقفال اليومي (App\Models\Closing): آخر إقفال + كل الحركة بعده،
     * وهو **يخصم المصروفات** (بنود + يدوية) — فيطابق الرصيد المرحّل في التقفيل اليومي،
     * بعكس الحساب القديم (topups − driverOut) الذي كان يتجاهل المصروفات.
     */
    public static function balance(): float
    {
        return Closing::currentBalance();
    }

    /**
     * الرصيد المتاح لبوليصة معيّنة (يستثني خصمها الحالي حتى لا يُحتسب مرّتين عند التعديل).
     * للبوليصة الجديدة مرّر 0. يعتمد نفس منطق الإقفال (يخصم المصروفات).
     */
    public static function availableForBill(int $excludeBillId = 0): float
    {
        return Closing::currentBalance($excludeBillId);
    }

    // ─────────────────────────────────────────────── السجل الموحّد

    /**
     * سجل موحّد بكل حركات العهدة (إضافات + عهد سائقين)، مرتّب من الأقدم للأحدث
     * مع رصيد جارٍ. كل صف: kind(topup|driver), d, label, party, amount(±), custody, deduction, running.
     */
    public static function ledger(): array
    {
        $rate = self::deductionRate();

        $topups = Database::all(
            "SELECT id, cust_date AS d, driver, note, amount FROM manual_custody");
        $rows = [];
        foreach ($topups as $t) {
            $rows[] = [
                'kind'      => 'topup',
                'id'        => (int) $t['id'],
                'd'         => $t['d'],
                'label'     => 'إضافة عهدة يدوية',
                'party'     => $t['driver'] ?: ($t['note'] ?: '—'),
                'note'      => $t['note'],
                'custody'   => null,
                'deduction' => null,
                'amount'    => round((float) $t['amount'], 2),   // موجب (+)
            ];
        }

        $drivers = Database::all(
            "SELECT b.id, b.bill_date AS d, b.bill_number, b.driver, b.company, b.custody, b.car_no, p.permit_no
             FROM bawalis b LEFT JOIN permits p ON p.id = b.permit_id
             WHERE b.is_legacy = 0 AND b.custody > 0");
        foreach ($drivers as $b) {
            $ded = self::billDeduction((float) $b['custody'], $b['car_no'] ?? null);
            $rows[] = [
                'kind'      => 'driver',
                'id'        => (int) $b['id'],
                'd'         => $b['d'],
                'label'     => $b['bill_number'] ?: ('إذن ' . $b['permit_no']),
                'party'     => $b['driver'] ?: '—',
                'note'      => $b['company'],
                'custody'   => round((float) $b['custody'], 2),
                'deduction' => $ded,
                'amount'    => -$ded,                            // سالب (−)
            ];
        }

        // ترتيب من الأقدم للأحدث (NULL أولاً) لحساب الرصيد الجارٍ، ثم عكسه للعرض
        usort($rows, function ($a, $b) {
            $da = $a['d'] ?? ''; $db = $b['d'] ?? '';
            return $da <=> $db ?: ($a['id'] <=> $b['id']);
        });
        $run = 0.0;
        foreach ($rows as &$r) { $run = round($run + $r['amount'], 2); $r['running'] = $run; }
        unset($r);

        return array_reverse($rows);
    }

    // ─────────────────────────────────────────────── إدارة الإضافات اليدوية (توب-أب)

    public static function findTopup(int $id): ?array
    {
        return Database::row("SELECT * FROM manual_custody WHERE id = ?", [$id]);
    }

    public static function createTopup(array $d): int
    {
        Database::exec(
            "INSERT INTO manual_custody (cust_date, driver, amount, note)
             VALUES (:cust_date, :driver, :amount, :note)", self::bind($d));
        return Database::lastId();
    }

    public static function updateTopup(int $id, array $d): void
    {
        $p = self::bind($d);
        $p[':id'] = $id;
        Database::exec(
            "UPDATE manual_custody SET cust_date=:cust_date, driver=:driver,
                    amount=:amount, note=:note WHERE id=:id", $p);
    }

    public static function deleteTopup(int $id): void
    {
        Database::exec("DELETE FROM manual_custody WHERE id = ?", [$id]);
    }

    private static function bind(array $d): array
    {
        return [
            ':cust_date' => $d['cust_date'] ?: null,
            ':driver'    => ($d['driver'] ?? '') !== '' ? $d['driver'] : null,
            ':amount'    => (float) ($d['amount'] ?? 0),
            ':note'      => ($d['note'] ?? '') !== '' ? $d['note'] : null,
        ];
    }
}
