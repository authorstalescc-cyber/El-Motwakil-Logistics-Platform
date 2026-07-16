<?php
namespace App\Models;

use App\Core\Database;

/**
 * أرشيف البوالص القديمة (legacy_bawalis) — «عرض فقط».
 *
 *   جدول مستقل تماماً مستورد من النظام القديم (Excel). لا يقرأه أي حساب في النظام
 *   (العهدة/التقفيل/المديونيات/التقارير/الفوترة) — استعلامات عرض وبحث فقط.
 *   لا كتابة، لا حذف، لا تعديل من الواجهة.
 */
class LegacyBawali
{
    /** أعمدة البحث النصّي */
    private const SEARCH_COLS = [
        'bill_number','declaration_owner','company','destination',
        'container_no','car_no','driver','car_owner','classification',
    ];

    /** تعريف الحقول للعرض التفصيلي: key => [label, group, type] */
    public static function fields(): array
    {
        return [
            // التعريف
            'bill_number'       => ['رقم البوليصة', 'identity', 'text'],
            'bill_date'         => ['التاريخ',       'identity', 'date'],
            'declaration_owner' => ['صاحب البيان',   'identity', 'text'],
            'classification'    => ['التصنيف',       'identity', 'text'],
            'company'           => ['اسم الشركة',    'identity', 'text'],
            'destination'       => ['الجهة',         'identity', 'text'],
            // السيارة
            'container_no'      => ['رقم الحاوية',   'vehicle', 'text'],
            'car_no'            => ['رقم السيارة',   'vehicle', 'text'],
            'driver'            => ['اسم السائق',    'vehicle', 'text'],
            'car_owner'         => ['صاحب السيارة',  'vehicle', 'text'],
            // الإيراد/التحصيل
            'freight'           => ['نولون',         'revenue', 'money'],
            'custody'           => ['عهدة',          'revenue', 'money'],
            'transfer'          => ['تحويلة',        'revenue', 'money'],
            'discount'          => ['خصم',           'revenue', 'money'],
            'overnight_collection' => ['بياتة (تحصيل)', 'revenue', 'money'],
            'remaining'         => ['باقى',          'revenue', 'money'],
            'payment'           => ['دفعة',          'revenue', 'money'],
            'total'             => ['إجمالى',        'revenue', 'money'],
            // المصروفات
            'driver_share'      => ['دور سواق',      'expense', 'money'],
            'assistant_share'   => ['دور تباع',      'expense', 'money'],
            'overnight_expense' => ['بياتة (مصروف)', 'expense', 'money'],
            'expense'           => ['مصروف',         'expense', 'money'],
            'cards'             => ['كرتات',         'expense', 'money'],
            'tahia_masr'        => ['تحيا مصر',      'expense', 'money'],
            'thabtan'           => ['ثبتان',         'expense', 'money'],
            'z_kawtsh'          => ['ظ كاوتش',       'expense', 'money'],
            'permits_fee'       => ['تصاريح',        'expense', 'money'],
            'kawtsh'            => ['كاوتش',         'expense', 'money'],
            'other_expense'     => ['مصاريف أخرى',   'expense', 'money'],
            'gas'               => ['جاز',           'expense', 'money'],
            // محسوبة في النظام القديم (أرشيف)
            'total_expense'     => ['إجمال المصروف',      'result', 'money'],
            'custody_remaining' => ['الباقى من العهدة',   'result', 'money'],
            'car_net'           => ['صافى السيارة',       'result', 'money'],
        ];
    }

    /** بناء شرط WHERE + المعاملات من الفلاتر */
    private static function buildWhere(array $f): array
    {
        $where = []; $params = [];
        $q = trim($f['q'] ?? '');
        if ($q !== '') {
            $like = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $q) . '%';
            $ors = [];
            foreach (self::SEARCH_COLS as $c) { $ors[] = "`$c` LIKE ? ESCAPE '\\\\'"; $params[] = $like; }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }
        if (!empty($f['from']) && self::isDate($f['from'])) { $where[] = 'bill_date >= ?'; $params[] = $f['from']; }
        if (!empty($f['to'])   && self::isDate($f['to']))   { $where[] = 'bill_date <= ?'; $params[] = $f['to']; }
        $sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        return [$sql, $params];
    }

    private static function isDate($d): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', (string)$d);
        return $dt && $dt->format('Y-m-d') === $d;
    }

    /**
     * قائمة مُصفَّحة + إجماليات المجموعة المُصفّاة.
     * @return array{rows:array,total:int,page:int,pages:int,per:int,sums:array}
     */
    public static function paginate(array $f, int $page = 1, int $per = 50): array
    {
        [$w, $params] = self::buildWhere($f);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM legacy_bawalis $w", $params);
        $pages = max(1, (int) ceil($total / $per));
        $page  = max(1, min($page, $pages));
        $off   = ($page - 1) * $per;

        $rows = Database::all(
            "SELECT * FROM legacy_bawalis $w ORDER BY bill_date DESC, id DESC LIMIT $per OFFSET $off",
            $params
        );
        $sums = Database::row(
            "SELECT COUNT(*) n, COALESCE(SUM(freight),0) freight, COALESCE(SUM(custody),0) custody,
                    COALESCE(SUM(remaining),0) remaining, COALESCE(SUM(total_expense),0) total_expense
               FROM legacy_bawalis $w",
            $params
        ) ?: ['n'=>0,'freight'=>0,'custody'=>0,'remaining'=>0,'total_expense'=>0];

        return ['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'pages'=>$pages, 'per'=>$per, 'sums'=>$sums];
    }

    public static function find(int $id): ?array
    {
        return Database::row("SELECT * FROM legacy_bawalis WHERE id = ?", [$id]);
    }

    /**
     * اقتراح أسماء أصحاب البيان (autocomplete) مع تحمّل الأخطاء الإملائية.
     *
     * يجلب كل الأسماء المميّزة (عددها صغير) ويرتّبها في PHP:
     *   تطابق normkey كامل > بادئة > احتواء > تشابه حروف (Levenshtein على normkey).
     * @return array<int,array{name:string,bills:int,last:?string,kind:string}>
     */
    public static function ownerSuggest(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') { return []; }
        $nq = Maintenance::normkey($q);
        if ($nq === '') { return []; }

        $scored = [];
        foreach (self::distinctOwners() as $o) {
            $no = $o['nkey'];
            if ($no === $nq)                          { $score = 100; $kind = 'match'; }
            elseif (str_starts_with($no, $nq))        { $score = 90;  $kind = 'match'; }
            elseif (mb_strpos($no, $nq) !== false)    { $score = 80;  $kind = 'match'; }
            else {
                $sim = self::similarity($nq, $no);
                if ($sim < 0.5) { continue; }
                $score = (int) round($sim * 70);      // أقصى تشابه إملائي = 70 (تحت أي تطابق حرفي)
                $kind  = 'similar';
            }
            $scored[] = ['name'=>$o['name'], 'bills'=>$o['bills'], 'last'=>$o['last'], 'kind'=>$kind, '_s'=>$score];
        }
        usort($scored, fn($a,$b) => [$b['_s'],$b['bills']] <=> [$a['_s'],$a['bills']]);
        return array_map(fn($r) => array_diff_key($r, ['_s'=>1]), array_slice($scored, 0, $limit));
    }

    /**
     * كشف حساب صاحب بيان من الأرشيف — قراءة فقط، لا يُحفَظ ولا يمسّ أي حساب.
     *
     * دلالة الأعمدة الأرشيفية: «باقى» (remaining) = صافي مستحق البوليصة له
     * (نولون − عهدة − خصم + تحويلة)، و«دفعة» (payment) = ما استلمه فعلاً.
     * فالباقي الفعلي للبوليصة = remaining − payment، وعمود «إجمالى» تراكمي من الإكسل فيُتجاهَل.
     *
     * تُدمَج كل التهجئات التي تتطابق بعد normkey (ي/ى، الهمزات، ة/ه…).
     * @return ?array{owner:string,variants:array,rows_due:array,rows_paid:array,sums:array}
     */
    public static function ownerStatement(string $owner): ?array
    {
        $nq = Maintenance::normkey($owner);
        if ($nq === '') { return null; }

        $variants = []; $canonical = null; $max = -1;
        foreach (self::distinctOwners() as $o) {
            if ($o['nkey'] !== $nq) { continue; }
            $variants[] = $o['name'];
            if ($o['bills'] > $max) { $max = $o['bills']; $canonical = $o['name']; }
        }
        if (!$variants) { return null; }

        $ph   = implode(',', array_fill(0, count($variants), '?'));
        $rows = Database::all(
            "SELECT * FROM legacy_bawalis WHERE declaration_owner IN ($ph)
              ORDER BY bill_date ASC, id ASC", $variants
        );

        $due = []; $paid = [];
        $sums = ['bills'=>count($rows), 'debt'=>0.0, 'paid'=>0.0, 'net'=>0.0];
        foreach ($rows as $r) {
            $r['due']  = (float)($r['remaining'] ?? 0);
            $r['paid'] = (float)($r['payment']   ?? 0);
            $r['net']  = $r['due'] - $r['paid'];
            $sums['debt'] += $r['due'];
            $sums['paid'] += $r['paid'];
            $sums['net']  += $r['net'];
            if ($r['net'] > 0.005) { $due[] = $r; } else { $paid[] = $r; }
        }

        return ['owner'=>$canonical, 'variants'=>$variants, 'rows_due'=>$due, 'rows_paid'=>$paid, 'sums'=>$sums];
    }

    /** كل أسماء أصحاب البيان المميّزة مع العدد وآخر تاريخ + مفتاح التهجئة (كاش لطلب واحد) */
    private static function distinctOwners(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $rows = Database::all(
                "SELECT declaration_owner name, COUNT(*) bills, MAX(bill_date) last
                   FROM legacy_bawalis
                  WHERE declaration_owner IS NOT NULL AND declaration_owner <> ''
                  GROUP BY declaration_owner"
            );
            foreach ($rows as $r) {
                $cache[] = ['name'=>$r['name'], 'bills'=>(int)$r['bills'], 'last'=>$r['last'],
                            'nkey'=>Maintenance::normkey($r['name'])];
            }
        }
        return $cache;
    }

    /** تشابه 0..1 على مستوى الحروف (Levenshtein متعدّد البايتات — أسماء قصيرة فقط) */
    private static function similarity(string $a, string $b): float
    {
        $ca = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $cb = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        $la = count($ca); $lb = count($cb);
        if (!$la || !$lb) { return 0.0; }
        $prev = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $lb; $j++) {
                $cur[$j] = min($prev[$j] + 1, $cur[$j-1] + 1,
                               $prev[$j-1] + ($ca[$i-1] === $cb[$j-1] ? 0 : 1));
            }
            $prev = $cur;
        }
        return 1.0 - $prev[$lb] / max($la, $lb);
    }

    /** إحصائيات لرأس الصفحة — تحترم نفس فلاتر القائمة (بحث + من/إلى) */
    public static function stats(array $f = []): array
    {
        [$w, $params] = self::buildWhere($f);
        $r = Database::row(
            "SELECT COUNT(*) n, MIN(bill_date) min_d, MAX(bill_date) max_d,
                    COALESCE(SUM(freight),0) freight, COALESCE(SUM(custody),0) custody,
                    COALESCE(SUM(remaining),0) remaining
               FROM legacy_bawalis $w",
            $params
        );
        return $r ?: ['n'=>0,'min_d'=>null,'max_d'=>null,'freight'=>0,'custody'=>0,'remaining'=>0];
    }
}
