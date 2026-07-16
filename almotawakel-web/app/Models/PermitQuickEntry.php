<?php
namespace App\Models;

use App\Core\Database;
use RuntimeException;

/** إدخال جماعي سريع لإيصالات ومصروفات الأذونات من لوحة التحكم. */
class PermitQuickEntry
{
    public const KINDS = [
        'receipt' => 'إيصال',
        'expense' => 'مصروف',
    ];

    /** الأذونات وحركاتها في شكل جاهز للواجهة. */
    public static function options(): array
    {
        $rows = Database::all(
            "SELECT p.id permit_id,p.permit_type,p.permit_no,p.permit_date,p.company,p.client,p.status,
                    p.containers,p.container_type permit_container_type,
                    m.id movement_id,m.seq,m.note movement_note,m.container_count,m.container_type
               FROM permits p JOIN permit_movements m ON m.permit_id=p.id
              ORDER BY p.status='closed',p.permit_date DESC,p.id DESC,m.seq,m.id"
        );
        $out = [];
        foreach ($rows as $row) {
            $pid = (int)$row['permit_id'];
            if (!isset($out[$pid])) {
                $type = ($row['permit_type'] ?? 'sader') === 'warid' ? 'شهادة' : 'إذن';
                $company = trim((string)($row['company'] ?: 'غير مسجلة'));
                $client = trim((string)($row['client'] ?: 'غير مسجل'));
                $no = trim((string)($row['permit_no'] ?: '#'.$pid));
                $out[$pid] = [
                    'id' => $pid,
                    'no' => $no,
                    'type' => $type,
                    'company' => $company,
                    'client' => $client,
                    'party' => $client !== 'غير مسجل' ? $client : $company,
                    'date' => $row['permit_date'],
                    'status' => $row['status'],
                    'containers' => (int)($row['containers'] ?? 0),
                    'container_type' => trim((string)($row['permit_container_type'] ?? '')),
                    'container_label' => '',
                    'label' => $type.' '.$no.' — '.$company,
                    'movements' => [],
                ];
            }
            $seq = (int)$row['seq'];
            $detail = [];
            if ((int)($row['container_count'] ?? 0) > 0) { $detail[] = (int)$row['container_count'].' حاوية'; }
            if (trim((string)($row['container_type'] ?? '')) !== '') { $detail[] = $row['container_type']; }
            if (trim((string)($row['movement_note'] ?? '')) !== '') { $detail[] = $row['movement_note']; }
            $out[$pid]['movements'][] = [
                'id' => (int)$row['movement_id'],
                'seq' => $seq,
                'container_count' => (int)($row['container_count'] ?? 0),
                'container_type' => trim((string)($row['container_type'] ?? '')),
                'note' => trim((string)($row['movement_note'] ?? '')),
                'label' => 'الحركة '.$seq.($detail ? ' — '.implode(' · ', $detail) : ''),
            ];
        }

        $breakdown = [];
        foreach (Database::all(
            "SELECT permit_id,container_type type,SUM(container_count) cnt
               FROM bawalis
              WHERE is_legacy=0 AND permit_id IS NOT NULL
                AND container_type IS NOT NULL AND container_type<>'' AND container_count>0
              GROUP BY permit_id,container_type"
        ) as $container) {
            $breakdown[(int)$container['permit_id']][] = [
                'type' => $container['type'],
                'cnt' => (int)$container['cnt'],
            ];
        }
        foreach ($out as $pid => &$permit) {
            $permit['container_label'] = Permit::containerLabel(
                $breakdown[$pid] ?? [],
                $permit['containers'],
                $permit['container_type']
            ) ?: 'بدون حاويات مسجلة';
            $permit['search'] = implode(' ', [
                $permit['type'], $permit['no'], $permit['company'], $permit['client'], $permit['container_label'],
            ]);
        }
        unset($permit);
        return array_values($out);
    }

    /**
     * يتحقق من كل الصفوف أولًا ثم يحفظها في transaction واحدة.
     * أي خطأ في أي صف يلغي الدفعة بالكامل.
     */
    public static function save(array $input): array
    {
        $rows = self::normalize($input);
        if (!$rows) { throw new RuntimeException('أضف بندًا واحدًا على الأقل قبل التطبيق.'); }
        if (count($rows) > 100) { throw new RuntimeException('الحد الأقصى 100 بند في الدفعة الواحدة.'); }

        $permitMap = [];
        foreach (Database::all('SELECT id,permit_type,permit_no,company,client FROM permits') as $p) {
            $permitMap[(int)$p['id']] = $p;
        }
        $movementMap = [];
        foreach (Database::all('SELECT id,permit_id,seq FROM permit_movements') as $m) {
            $movementMap[(int)$m['id']] = $m;
        }
        $cardMap = [];
        foreach (VisaCard::all(true) as $card) { $cardMap[(int)$card['id']] = $card; }

        foreach ($rows as $i => &$row) {
            $n = $i + 1;
            $permit = $permitMap[$row['permit_id']] ?? null;
            $movement = $movementMap[$row['movement_id']] ?? null;
            if (!$permit) { throw new RuntimeException("الصف $n: اختر إذنًا صحيحًا."); }
            if (!$movement || (int)$movement['permit_id'] !== $row['permit_id']) {
                throw new RuntimeException("الصف $n: الحركة المختارة لا تتبع الإذن.");
            }
            if (!isset(self::KINDS[$row['kind']])) { throw new RuntimeException("الصف $n: نوع البند غير صحيح."); }
            if ($row['note'] === '') { throw new RuntimeException("الصف $n: اكتب البيان."); }
            if (mb_strlen($row['note']) > 255) { throw new RuntimeException("الصف $n: البيان أطول من 255 حرفًا."); }
            if ($row['kind'] === 'expense') { $row['unit_actual'] = null; }
            if ($row['qty'] < 1 || $row['qty'] > 10000) { throw new RuntimeException("الصف $n: الكمية يجب أن تكون بين 1 و 10000."); }
            if ($row['unit_amount'] <= 0) { throw new RuntimeException("الصف $n: سعر الوحدة يجب أن يكون أكبر من صفر."); }
            $row['amount'] = round($row['unit_amount'] * $row['qty'], 2);
            $row['actual_amount'] = $row['unit_actual'] === null ? null : round($row['unit_actual'] * $row['qty'], 2);
            if (!self::validDate($row['entry_date'])) { throw new RuntimeException("الصف $n: التاريخ غير صحيح."); }
            if ($row['is_visa']) {
                if (!$row['visa_card_id'] || !isset($cardMap[$row['visa_card_id']])) {
                    throw new RuntimeException("الصف $n: اختر كارت فيزا نشطًا.");
                }
            } else {
                $row['visa_card_id'] = null;
                if ($err = Closing::guard($row['entry_date'])) {
                    throw new RuntimeException("الصف $n: $err");
                }
            }
            $row['permit'] = $permit;
            $row['movement_seq'] = (int)$movement['seq'];
        }
        unset($row);

        $batch = bin2hex(random_bytes(8));
        $ids = []; $totals = ['receipt'=>0.0,'expense'=>0.0];
        $pdo = Database::conn();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) { $pdo->beginTransaction(); }
        try {
            foreach ($rows as $row) {
                $stageKey = $row['kind'] === 'receipt' ? 'receipts' : 'custom';
                $direction = $row['kind'] === 'expense' ? 'expense' : null;
                Database::exec(
                    'INSERT INTO permit_stages
                     (permit_id,movement_id,stage_key,direction,amount,qty,actual_amount,entry_date,ref_no,note,status,is_visa,visa_card_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,\'done\',?,?)',
                    [$row['permit_id'],$row['movement_id'],$stageKey,$direction,$row['amount'],$row['qty'],$row['actual_amount'],
                     $row['entry_date'],$row['ref_no'],$row['note'],$row['is_visa']?1:0,$row['visa_card_id']]
                );
                $id = Database::lastId();
                $saved = Database::row('SELECT * FROM permit_stages WHERE id=?', [$id]);
                $ids[] = $id;
                $totals[$row['kind']] += $row['amount'];

                Lookup::add($row['kind'] === 'receipt' ? 'mv_receipt' : 'mv_expense', $row['note']);
                $permit = $row['permit'];
                $description = 'إضافة سريعة: '.self::KINDS[$row['kind']].' «'.$row['note'].'» بقيمة '.
                    number_format($row['amount'], 2, '.', ',').($row['qty'] > 1 ? ' ('.$row['qty'].' × '.number_format($row['unit_amount'], 2, '.', ',').')' : '').' — '.Permit::noLabel($permit['permit_type']).' '.
                    ($permit['permit_no'] ?: '#'.$row['permit_id']).' — حركة '.$row['movement_seq'];
                Audit::log('create', 'permit_stages', $id, null, $saved, $description, [
                    'permit_id'=>$row['permit_id'], 'permit_no'=>$permit['permit_no'],
                    'movement_id'=>$row['movement_id'], 'movement_seq'=>$row['movement_seq'],
                    'record_date'=>$row['entry_date'], 'quick_batch'=>$batch,
                ]);
            }
            if ($ownsTransaction) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['count'=>count($ids),'ids'=>$ids,'totals'=>$totals,'batch'=>$batch];
    }

    private static function normalize(array $input): array
    {
        $permitIds = (array)($input['permit_id'] ?? []);
        $rows = [];
        foreach ($permitIds as $i => $rawPermit) {
            $note = trim((string)(($input['note'] ?? [])[$i] ?? ''));
            $unitAmount = round((float)(($input['amount'] ?? [])[$i] ?? 0), 2);
            $permitId = (int)$rawPermit;
            if ($permitId <= 0 && $note === '' && $unitAmount == 0.0) { continue; }
            $actualRaw = trim((string)(($input['actual_amount'] ?? [])[$i] ?? ''));
            $payment = (string)(($input['payment_method'] ?? [])[$i] ?? 'cash');
            $rows[] = [
                'permit_id' => $permitId,
                'movement_id' => (int)(($input['movement_id'] ?? [])[$i] ?? 0),
                'kind' => (string)(($input['kind'] ?? [])[$i] ?? ''),
                'note' => $note,
                'qty' => (int)(($input['qty'] ?? [])[$i] ?? 1),
                'unit_amount' => $unitAmount,
                'unit_actual' => $actualRaw === '' ? null : round((float)$actualRaw, 2),
                'entry_date' => trim((string)(($input['entry_date'] ?? [])[$i] ?? '')) ?: work_date(),
                'ref_no' => trim((string)(($input['ref_no'] ?? [])[$i] ?? '')) ?: null,
                'is_visa' => $payment === 'visa',
                'visa_card_id' => (int)(($input['visa_card_id'] ?? [])[$i] ?? 0) ?: null,
            ];
        }
        return $rows;
    }

    private static function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return false; }
        [$y,$m,$d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }
}
