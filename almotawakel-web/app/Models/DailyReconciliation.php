<?php
namespace App\Models;

use App\Core\Database;

/**
 * مطابقة التقفيل اليدوي مع مصروفات السيستم النقدية مجمّعة حسب العميل واليوم.
 * كل أذونات العميل في اليوم تظهر في صف واحد، وإجمالي الإيصالات والمصروفات رقم واحد.
 * التفاصيل تحت الصف تحتفظ بالإذن والحركة ونوع البند وطريقة الدفع.
 */
class DailyReconciliation
{
    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available === null) {
            self::$available = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name IN ('daily_closing_reconciliations','daily_closing_reconciliation_clients')"
            ) === 2;
        }
        return self::$available;
    }

    private static function tableExists(string $name): bool
    {
        static $cache = [];
        if (!array_key_exists($name, $cache)) {
            $cache[$name] = (bool) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$name]
            );
        }
        return $cache[$name];
    }

    public static function view(string $date): array
    {
        if (!self::available()) {
            return [
                'available'=>false,'saved'=>false,'matched'=>false,'rows'=>[],
                'general'=>self::emptyGeneral(),'totals'=>self::emptyTotals(),
                'note'=>'','updated_by'=>null,'updated_at'=>null,
            ];
        }

        $header = Database::row('SELECT * FROM daily_closing_reconciliations WHERE close_date=?', [$date]);
        $rows = self::systemClientRows($date);
        foreach (Database::all(
            'SELECT * FROM daily_closing_reconciliation_clients WHERE close_date=? ORDER BY client_name', [$date]
        ) as $saved) {
            $key = (string) $saved['client_key'];
            if (!isset($rows[$key])) {
                $rows[$key] = self::baseRow($key, (string) $saved['client_name']);
                $rows[$key]['manual_only'] = true;
            }
            $rows[$key]['item_saved'] = true;
            $rows[$key]['manual_total'] = round((float) $saved['manual_total'], 2);
            $rows[$key]['note'] = (string) ($saved['note'] ?? '');
        }

        foreach ($rows as &$row) {
            $row['difference'] = round(($row['manual_total'] ?? 0) - $row['system_total'], 2);
            $row['matched'] = $row['item_saved'] && abs($row['difference']) < 0.005;
            $row['permits'] = array_values($row['permits']);
            usort($row['permits'], fn($a,$b) => strnatcasecmp((string)$a['permit_no'], (string)$b['permit_no']));
        }
        unset($row);
        uasort($rows, fn($a,$b) => strnatcasecmp((string)$a['client_name'], (string)$b['client_name']));

        $general = self::generalSystem($date);
        $general['manual'] = $header ? round((float) $header['manual_general_expenses'], 2) : null;
        $general['difference'] = round(($general['manual'] ?? 0) - $general['system'], 2);
        $general['matched'] = $header !== null && abs($general['difference']) < 0.005;

        $totals = self::totals(array_values($rows), $general, $date);
        $matched = $header !== null && $general['matched'];
        foreach ($rows as $row) { if (!$row['matched']) { $matched = false; break; } }

        return [
            'available'=>true,'saved'=>$header!==null,'matched'=>$matched,'rows'=>array_values($rows),
            'general'=>$general,'totals'=>$totals,'note'=>(string)($header['note']??''),
            'updated_by'=>$header['updated_by']??null,'updated_at'=>$header['updated_at']??null,
        ];
    }

    /** حفظ إدخال واحد لكل عميل ظاهر فعليًا في حركة اليوم. */
    public static function save(string $date, array $items, float $manualGeneral, ?string $note, ?string $user): void
    {
        if (!self::available()) {
            throw new \RuntimeException('جداول المطابقة غير موجودة. شغّل php bin/migrate_v28.php أولاً.');
        }
        $allowed = self::systemClientRows($date);
        foreach (array_keys($items) as $key) {
            if (!isset($allowed[$key])) {
                throw new \DomainException('لا يمكن مطابقة عميل بلا إيصالات أو مصروفات مسجلة في نفس اليوم. سجّل الحركة من صفحة الإذن أولًا.');
            }
        }

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            Database::exec(
                "INSERT INTO daily_closing_reconciliations
                    (close_date,manual_general_expenses,note,updated_by)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE manual_general_expenses=VALUES(manual_general_expenses),
                    note=VALUES(note),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP",
                [$date,round($manualGeneral,2),self::cleanText($note,4000),$user]
            );
            Database::exec('DELETE FROM daily_closing_reconciliation_clients WHERE close_date=?', [$date]);
            foreach ($items as $key => $item) {
                if (!isset($allowed[$key])) { continue; }
                Database::exec(
                    "INSERT INTO daily_closing_reconciliation_clients
                     (close_date,client_key,client_name,manual_total,note) VALUES (?,?,?,?,?)",
                    [$date,$key,$allowed[$key]['client_name'],round((float)($item['total']??0),2),self::cleanText($item['note']??null,500)]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** تفاصيل اليوم مجمعة حسب الاسم الموحّد للعميل. */
    private static function systemClientRows(string $date): array
    {
        $expSql = Permit::expenseSql('s');
        // v42: بنود عهدة المناديب بقت داخلة المطابقة زي أي بند. فلوس المندوب أصلها
        // من الدرج، والصرف بيتخصم يوم صرفه — فلازم يتطابق مع اليدوي زي غيره.
        $items = Database::all(
            "SELECT s.id,s.permit_id,s.movement_id,pm.seq AS movement_seq,s.stage_key,s.amount,
                    s.note,s.ref_no,s.is_visa,p.permit_no,p.permit_type,p.client,p.company
             FROM permit_stages s
             JOIN permits p ON p.id=s.permit_id
             LEFT JOIN permit_movements pm ON pm.id=s.movement_id
             WHERE $expSql AND s.amount<>0 AND s.entry_date=?
             ORDER BY p.client,p.id,s.id", [$date]
        );
        $rows = [];
        foreach ($items as $item) {
            $clientName = trim((string)($item['client'] ?? ''));
            if ($clientName === '') { $clientName = trim((string)($item['company'] ?? '')); }
            if ($clientName === '') { $clientName = 'بدون عميل'; }
            $key = self::clientKey($clientName);
            $rows[$key] ??= self::baseRow($key, $clientName);
            $amount = round((float)$item['amount'],2);
            $isVisa = (int)$item['is_visa'] === 1;
            if ($isVisa) { $rows[$key]['visa_excluded'] += $amount; }
            else {
                $rows[$key]['system_total'] += $amount;
                if ($item['stage_key']==='receipts') { $rows[$key]['receipts_info'] += $amount; }
                else { $rows[$key]['expenses_info'] += $amount; }
            }

            $pid = (int)$item['permit_id'];
            $rows[$key]['permits'][$pid] ??= [
                'permit_id'=>$pid,'permit_no'=>$item['permit_no'],'permit_type'=>$item['permit_type'],
                'company'=>$item['company'],'system_total'=>0.0,'visa_excluded'=>0.0,'details'=>[],
            ];
            if ($isVisa) { $rows[$key]['permits'][$pid]['visa_excluded'] += $amount; }
            else { $rows[$key]['permits'][$pid]['system_total'] += $amount; }
            $rows[$key]['permits'][$pid]['details'][] = [
                'stage_label'=>Permit::label((string)$item['stage_key']),
                'bucket'=>$item['stage_key']==='receipts'?'receipt':'expense',
                'movement_seq'=>$item['movement_seq'],'note'=>$item['note'],'ref_no'=>$item['ref_no'],
                'is_visa'=>$isVisa?1:0,'amount'=>$amount,
            ];
        }
        foreach ($rows as &$row) {
            foreach (['system_total','visa_excluded','receipts_info','expenses_info'] as $k) { $row[$k]=round($row[$k],2); }
            foreach ($row['permits'] as &$p) { $p['system_total']=round($p['system_total'],2);$p['visa_excluded']=round($p['visa_excluded'],2); }
            unset($p);
        }
        unset($row);
        // العميل الذي له فيزا فقط لا يحتاج خانة مطابقة كاش؛ يظل مبلغ الفيزا ظاهرًا
        // في الإجمالي المعلوماتي ولا يضيف صفًا صفريًا يبطئ الإدخال.
        foreach ($rows as $key => $row) {
            if (abs($row['system_total']) < 0.005) { unset($rows[$key]); }
        }
        return $rows;
    }

    private static function clientKey(string $name): string
    {
        $normalized = Maintenance::normkey($name);
        return hash('sha256', $normalized !== '' ? $normalized : $name);
    }

    private static function generalSystem(string $date): array
    {
        $details=[];$system=0.0;$visa=0.0;
        foreach (Database::all(
            'SELECT amount,category,beneficiary,note,is_visa FROM manual_expenses WHERE exp_date=? AND amount<>0 ORDER BY id',[$date]
        ) as $r) {
            $amount=round((float)$r['amount'],2);if((int)$r['is_visa']===1){$visa+=$amount;}else{$system+=$amount;}
            $details[]=['label'=>$r['category']?:'مصروف يدوي','party'=>$r['beneficiary']?:'—','note'=>$r['note'],'amount'=>$amount,'is_visa'=>(int)$r['is_visa']];
        }
        if(self::tableExists('rep_entries')){
            $cash=Rep::cashKindsList();
            foreach(Database::all(
                "SELECT e.kind,e.amount,e.note,e.is_visa,r.name FROM rep_entries e JOIN reps r ON r.id=e.rep_id
                 WHERE e.kind IN ($cash) AND e.amount<>0 AND e.entry_date=? ORDER BY e.id",[$date]
            ) as $r){
                $amount=round((float)$r['amount'],2);if((int)$r['is_visa']===1){$visa+=$amount;}else{$system+=$amount;}
                $details[]=['label'=>Rep::kindLabel((string)$r['kind']),'party'=>$r['name']?:'—','note'=>$r['note'],'amount'=>$amount,'is_visa'=>(int)$r['is_visa']];
            }
        }
        // نثريات المناديب (v39) — مصروف شركة بلا إذن ولا عميل، فمكانها الصف العام.
        // بقت كاش خارج من الخزنة من v42 فلازم تتطابق زي أي مصروف يدوي.
        // v45: الكاش بس بيتطابق. المدفوع بالفيزا بيتعدّ في `visa` المعلوماتي زي
        // أي بند فيزا، وما بيدخلش المطابقة لأنه ما خرجش من الدرج أصلاً.
        if(self::tableExists('rep_misc_expenses')){
            $miscVisa = RepMiscExpense::visaReady() ? 'x.is_visa' : '0';
            foreach(Database::all(
                "SELECT x.amount,x.category,x.note,$miscVisa AS is_visa,r.name FROM rep_misc_expenses x
                 JOIN reps r ON r.id=x.rep_id WHERE x.amount<>0 AND x.exp_date=? ORDER BY x.id",[$date]
            ) as $r){
                $amount=round((float)$r['amount'],2);
                $isVisa=!empty($r['is_visa']);
                if($isVisa){ $visa+=$amount; } else { $system+=$amount; }
                $details[]=['label'=>$r['category']?:RepMiscExpense::CATEGORY,'party'=>$r['name']?:'—','note'=>$r['note'],'amount'=>$amount,'is_visa'=>$isVisa?1:0];
            }
        }
        return ['system'=>round($system,2),'visa_excluded'=>round($visa,2),'manual'=>null,'difference'=>round(-$system,2),'matched'=>false,'details'=>$details];
    }

    private static function baseRow(string $key,string $name): array
    {
        return ['client_key'=>$key,'client_name'=>$name,'system_total'=>0.0,'visa_excluded'=>0.0,
            'receipts_info'=>0.0,'expenses_info'=>0.0,'manual_total'=>null,'difference'=>0.0,
            'item_saved'=>false,'matched'=>false,'manual_only'=>false,'note'=>'','permits'=>[]];
    }

    private static function totals(array $rows,array $general,string $date): array
    {
        $t=self::emptyTotals();
        foreach($rows as $r){$t['system_clients']+=$r['system_total'];$t['manual_clients']+=$r['manual_total']??0;}
        $t['system_general']=$general['system'];$t['manual_general']=$general['manual']??0;
        $expSql=Permit::expenseSql('s');
        $permitVisa=(float)Database::scalar("SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE $expSql AND s.is_visa=1 AND s.amount<>0 AND s.entry_date=?",[$date]);
        $t['visa_excluded']=round($permitVisa+$general['visa_excluded'],2);
        $t['system_cash']=round($t['system_clients']+$t['system_general'],2);$t['manual_cash']=round($t['manual_clients']+$t['manual_general'],2);
        $t['difference']=round($t['manual_cash']-$t['system_cash'],2);foreach($t as &$v){$v=round((float)$v,2);}unset($v);return $t;
    }
    private static function emptyGeneral(): array{return ['system'=>0.0,'visa_excluded'=>0.0,'manual'=>null,'difference'=>0.0,'matched'=>false,'details'=>[]];}
    private static function emptyTotals(): array{return ['system_clients'=>0.0,'manual_clients'=>0.0,'system_general'=>0.0,'manual_general'=>0.0,'visa_excluded'=>0.0,'system_cash'=>0.0,'manual_cash'=>0.0,'difference'=>0.0];}
    private static function cleanText($value,int $max):?string{$text=trim((string)($value??''));if($text===''){return null;}return function_exists('mb_substr')?mb_substr($text,0,$max):substr($text,0,$max);}
}
