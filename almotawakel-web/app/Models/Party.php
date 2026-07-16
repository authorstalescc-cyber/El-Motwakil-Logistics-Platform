<?php
namespace App\Models;

use App\Core\Database;

/**
 * دليل الوكلاء والشركات.
 *   الوكيل  = عميلنا الفعلي (نتعامل معه مباشرة).
 *   الشركة  = الجهة التي يُصدَر الإذن لصالحها، والوكيل ينوب عنها.
 * البروفايل «عرض فقط»: يُجمَّع تلقائياً من الأذونات والفواتير والأرصدة بمطابقة الاسم.
 */
class Party
{
    /** قائمة الوكلاء + عدد الشركات ومعاينة أسمائها (للبطاقات) */
    public static function agents(string $q = ''): array
    {
        Database::exec("SET SESSION group_concat_max_len = 1000000");
        $where = ''; $args = [];
        $where = "WHERE a.is_active=1 AND a.merged_into_id IS NULL"; $args = [];
        if ($q !== '') { $where .= " AND (a.name LIKE ? OR EXISTS (SELECT 1 FROM party_aliases pa WHERE pa.party_type='agent' AND pa.party_id=a.id AND pa.alias LIKE ?))"; $args[] = "%$q%"; $args[]="%$q%"; }
        return Database::all(
            "SELECT a.*,
                    COUNT(ac.company_id) AS companies,
                    GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR '|') AS preview
             FROM agents a
             LEFT JOIN agent_company ac ON ac.agent_id = a.id
             LEFT JOIN companies c ON c.id = ac.company_id
             $where
             GROUP BY a.id
             ORDER BY a.name", $args
        );
    }

    /** قائمة الشركات + عدد الوكلاء ومعاينة أسمائهم (للبطاقات) */
    public static function companies(string $q = ''): array
    {
        Database::exec("SET SESSION group_concat_max_len = 1000000");
        $where = 'WHERE c.is_active=1 AND c.merged_into_id IS NULL'; $args = [];
        if ($q !== '') { $where .= " AND (c.name LIKE ? OR EXISTS (SELECT 1 FROM party_aliases pa WHERE pa.party_type='company' AND pa.party_id=c.id AND pa.alias LIKE ?))"; $args[] = "%$q%"; $args[]="%$q%"; }
        return Database::all(
            "SELECT c.*,
                    COUNT(ac.agent_id) AS agents,
                    GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR '|') AS preview
             FROM companies c
             LEFT JOIN agent_company ac ON ac.company_id = c.id
             LEFT JOIN agents a ON a.id = ac.agent_id
             $where
             GROUP BY c.id
             ORDER BY c.name", $args
        );
    }

    public static function agent(int $id): ?array
    {
        return Database::row("SELECT * FROM agents WHERE id = ?", [$id]);
    }

    public static function company(int $id): ?array
    {
        return Database::row("SELECT * FROM companies WHERE id = ?", [$id]);
    }

    public static function owners(string $q=''): array
    {
        $where='WHERE o.is_active=1 AND o.merged_into_id IS NULL'; $args=[];
        if($q!==''){$where.=" AND (o.name LIKE ? OR EXISTS (SELECT 1 FROM party_aliases pa WHERE pa.party_type='owner' AND pa.party_id=o.id AND pa.alias LIKE ?))";$args[]="%$q%";$args[]="%$q%";}
        return Database::all(
            "SELECT o.*,(SELECT COUNT(*) FROM bawalis b WHERE b.declaration_owner_id=o.id) bills,
                    (SELECT COUNT(*) FROM owner_statements s WHERE s.declaration_owner_id=o.id AND s.status<>'void') statements
             FROM declaration_owners o $where ORDER BY o.name",$args
        );
    }

    /** الشركات التي يمثّلها وكيل */
    public static function agentCompanies(int $agentId): array
    {
        return Database::all(
            "SELECT c.id, c.name
             FROM agent_company ac JOIN companies c ON c.id = ac.company_id
             WHERE ac.agent_id = ? ORDER BY c.name", [$agentId]
        );
    }

    /** الوكلاء الذين يمثّلون شركة */
    public static function companyAgents(int $companyId): array
    {
        return Database::all(
            "SELECT a.id, a.name
             FROM agent_company ac JOIN agents a ON a.id = ac.agent_id
             WHERE ac.company_id = ? ORDER BY a.name", [$companyId]
        );
    }

    /**
     * خريطة الأسماء للربط التلقائي في النماذج (JS):
     *   agentCompanies: اسم الوكيل → [أسماء شركاته]
     *   companyAgents:  اسم الشركة → [أسماء وكلائها]
     */
    public static function nameMap(): array
    {
        $agentCompanies = [];
        $companyAgents  = [];
        $rows = Database::all(
            "SELECT a.name AS agent, c.name AS company
             FROM agent_company ac
             JOIN agents a    ON a.id = ac.agent_id
             JOIN companies c ON c.id = ac.company_id
             ORDER BY a.name, c.name"
        );
        foreach ($rows as $r) {
            $agentCompanies[$r['agent']][]  = $r['company'];
            $companyAgents[$r['company']][] = $r['agent'];
        }
        return ['agentCompanies' => $agentCompanies, 'companyAgents' => $companyAgents];
    }

    /**
     * خريطة صاحب السيارة → أحدث رقم سيارة له (للتعبئة التلقائية في نموذج البوليصة).
     *   اسم صاحب السيارة → "رقم السيارة" (أحدث بوليصة غير فارغة الرقم).
     */
    public static function carOwnerMap(): array
    {
        $map = [];
        // نمرّ تنازليًا بالـ id فتبقى أحدث قيمة غير فارغة لكل صاحب سيارة
        $rows = Database::all(
            "SELECT car_owner, car_no
             FROM bawalis
             WHERE is_legacy = 0
               AND car_owner IS NOT NULL AND car_owner <> ''
               AND car_no IS NOT NULL AND car_no <> ''
             ORDER BY id DESC"
        );
        foreach ($rows as $r) {
            $owner = trim((string)$r['car_owner']);
            if ($owner !== '' && !isset($map[$owner])) {
                $map[$owner] = trim((string)$r['car_no']);
            }
        }
        return $map;
    }

    /** هل جدول شامل موجود؟ (قد لا يكون فيه بيانات بعد) */
    private static function hasShamil(): bool
    {
        return (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'shamil'"
        );
    }

    /** أذونات مرتبطة باسم (العميل أو الشركة) */
    public static function permitsBy(string $field, string $name): array
    {
        $col = $field === 'company' ? 'company' : 'client';
        $master=PartyMaster::resolve($field==='company'?'company':'agent',$name);
        $idCol=$field==='company'?'company_id':'agent_id';
        if($master){return Database::all(
            "SELECT id,permit_type,permit_no,permit_date,company,client,destination,status FROM permits
             WHERE $idCol=? ORDER BY permit_date DESC,id DESC LIMIT 500",[(int)$master['id']]);}
        return Database::all(
            "SELECT id, permit_type, permit_no, permit_date, company, client, destination, status
             FROM permits WHERE $col = ? ORDER BY permit_date DESC, id DESC LIMIT 500",
            [$name]
        );
    }

    /** فواتير شامل مرتبطة باسم (العميل أو الشركة) */
    public static function invoicesBy(string $field, string $name): array
    {
        if (!self::hasShamil()) { return []; }
        $col = $field === 'company' ? 'company' : 'client';
        return Database::all(
            "SELECT id, invoice_date, invoice_no, permit_no, client, company,
                    invoice_total, payments, account_total
             FROM shamil WHERE $col = ? ORDER BY invoice_date DESC, id DESC LIMIT 500",
            [$name]
        );
    }

    /** رصيد الوكيل من جدول الأرصدة (إن وُجد) */
    public static function clientBalance(string $name): ?array
    {
        return Database::row(
            "SELECT balance, note FROM balances WHERE party_type='client' AND name = ? LIMIT 1",
            [$name]
        );
    }

    /** ملخّص أرقام (للبطاقات العلوية في البروفايل) */
    public static function summary(string $field, string $name): array
    {
        $col = $field === 'company' ? 'company' : 'client';
        $master=PartyMaster::resolve($field==='company'?'company':'agent',$name);
        $idCol=$field==='company'?'company_id':'agent_id';
        $p = Database::row(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(status='open'),0)   AS open_cnt,
                    COALESCE(SUM(status='closed'),0) AS closed_cnt
             FROM permits WHERE ".($master?"$idCol = ?":"$col = ?"), [$master?(int)$master['id']:$name]
        ) ?: ['cnt'=>0,'open_cnt'=>0,'closed_cnt'=>0];

        $inv = ['cnt'=>0,'total'=>0,'remaining'=>0];
        if (self::hasShamil()) {
            $inv = Database::row(
                "SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(invoice_total),0) AS total,
                        COALESCE(SUM(account_total),0)  AS remaining
                 FROM shamil WHERE $col = ?", [$name]
            ) ?: $inv;
        }
        return ['permits' => $p, 'invoices' => $inv];
    }
}
