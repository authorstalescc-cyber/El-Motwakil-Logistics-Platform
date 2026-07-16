<?php
namespace App\Models;

use App\Core\Database;

/**
 * أدوات صيانة البيانات — تُستخدم من واجهة الإعدادات ومن سطر الأوامر.
 * أهمها: توحيد المسميات المكررة (سائقون/أصحاب سيارات/شركات/عملاء/جهات …).
 */
class Maintenance
{
    /** المفتاح الموحَّد للمقارنة (يزيل الفروق الإملائية السطحية) */
    public static function normkey($s): string
    {
        $s = trim((string)$s);
        if ($s === '') { return ''; }
        $s = preg_replace('/[\x{0640}\x{064B}-\x{0652}\x{0670}]/u', '', $s);   // تشكيل/تطويل/ألف خنجرية
        $s = preg_replace('/[\x{0623}\x{0625}\x{0622}\x{0671}]/u', 'ا', $s);   // توحيد الألف
        $s = strtr($s, ['ى'=>'ي', 'ة'=>'ه', 'ؤ'=>'و', 'ئ'=>'ي']);
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower(trim($s), 'UTF-8');
    }

    private static function tableExists(string $t): bool
    {
        static $cache = [];
        if (!isset($cache[$t])) {
            $cache[$t] = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$t]);
        }
        return $cache[$t];
    }

    /** مجموعات الحقول التي تحمل نفس نوع الاسم */
    private static function groups(): array
    {
        return [
            'السائقون' => [
                'cols' => [['bawalis','driver'], ['movements','driver']],
                'lookup' => 'driver', 'prefer' => null,
            ],
            'أصحاب السيارات' => [
                'cols' => [['bawalis','car_owner'], ['movements','car_owner']],
                'lookup' => 'car_owner', 'prefer' => null,
            ],
            'الشركات' => [
                'cols' => [['bawalis','company'], ['permits','company'], ['generators','company'], ['shamil','company']],
                'lookup' => 'company', 'prefer' => 'companies',
            ],
            'العملاء (الوكلاء)' => [
                'cols' => [['permits','client'], ['shamil','client'], ['balances','name',"party_type='client'"]],
                'lookup' => 'client', 'prefer' => 'agents',
            ],
            'الجهات' => [
                'cols' => [['bawalis','destination'], ['permits','destination'], ['shamil','destination']],
                'lookup' => 'destination', 'prefer' => null,
            ],
            'التصنيفات' => [
                'cols' => [['bawalis','classification']],
                'lookup' => 'classification', 'prefer' => null,
            ],
            'تصنيف الحاويات' => [
                'cols' => [['bawalis','container_type']],
                'lookup' => 'container_type', 'prefer' => null,
            ],
            'أصحاب البيانات' => [
                'cols' => [['bawalis','declaration_owner']],
                'lookup' => null, 'prefer' => null,
            ],
        ];
    }

    /**
     * توحيد المسميات. يُعيد تقريراً:
     *   ['dry'=>bool, 'groups'=>[['label','merges','rows','lookupDel','samples'[]]], 'totals'=>[...]]
     */
    public static function normalizeNames(bool $dry = false): array
    {
        $report = ['dry' => $dry, 'groups' => [], 'totals' => ['merges'=>0,'rows'=>0,'lookupDel'=>0]];

        foreach (self::groups() as $label => $g) {
            $cols = $g['cols']; $lookup = $g['lookup']; $prefer = $g['prefer'];

            // جمع القيم وعدد استخدامها
            $counts = [];
            foreach ($cols as $c) {
                [$t, $col] = $c; $extra = $c[2] ?? '';
                if (!self::tableExists($t)) { continue; }
                $w = "`$col` IS NOT NULL AND `$col` <> ''" . ($extra ? " AND $extra" : '');
                foreach (Database::all("SELECT `$col` v, COUNT(*) c FROM `$t` WHERE $w GROUP BY `$col`") as $r) {
                    $counts[$r['v']] = ($counts[$r['v']] ?? 0) + (int)$r['c'];
                }
            }
            $lookupVals = [];
            if ($lookup) {
                $lookupVals = array_column(Database::all("SELECT value FROM lookups WHERE list_type=?", [$lookup]), 'value');
                foreach ($lookupVals as $lv) { if (!isset($counts[$lv])) { $counts[$lv] = 0; } }
            }
            if (!$counts) { continue; }

            $prefNames = [];
            if ($prefer === 'agents' && self::tableExists('agents')) {
                $prefNames = array_column(Database::all("SELECT name FROM agents"), 'name');
            } elseif ($prefer === 'companies' && self::tableExists('companies')) {
                $prefNames = array_column(Database::all("SELECT name FROM companies"), 'name');
            }

            $byKey = [];
            foreach ($counts as $v => $c) {
                $k = self::normkey($v);
                if ($k === '') { continue; }
                $byKey[$k]['values'][$v] = $c;
            }
            foreach ($prefNames as $pn) {
                $k = self::normkey($pn);
                if (isset($byKey[$k])) { $byKey[$k]['pref'][] = $pn; }
            }

            $gMerges = 0; $gRows = 0; $gLookupDel = 0; $samples = [];
            foreach ($byKey as $grp) {
                $vals = $grp['values'];
                if (!empty($grp['pref'])) {
                    $canonical = $grp['pref'][0];
                } else {
                    arsort($vals);
                    $max = reset($vals);
                    $cands = array_keys(array_filter($grp['values'], fn($c) => $c === $max));
                    usort($cands, fn($a, $b) => (mb_strlen($b) <=> mb_strlen($a)) ?: strcmp($a, $b));
                    $canonical = $cands[0];
                }
                $variants = array_filter(array_keys($grp['values']), fn($v) => $v !== $canonical);
                if (!$variants) { continue; }

                $gMerges++;
                if (count($samples) < 8) {
                    $samples[] = ['from' => array_values($variants), 'to' => $canonical];
                }
                foreach ($variants as $v) {
                    foreach ($cols as $c) {
                        [$t, $col] = $c; $extra = $c[2] ?? '';
                        if (!self::tableExists($t)) { continue; }
                        $w = "`$col` = ?" . ($extra ? " AND $extra" : '');
                        if ($dry) {
                            $gRows += (int) Database::scalar("SELECT COUNT(*) FROM `$t` WHERE $w", [$v]);
                        } else {
                            $gRows += Database::exec("UPDATE `$t` SET `$col` = ? WHERE $w", [$canonical, $v]);
                        }
                    }
                }
                if ($lookup) {
                    foreach ($variants as $v) {
                        if (in_array($v, $lookupVals, true)) {
                            if (!$dry) { Database::exec("DELETE FROM lookups WHERE list_type=? AND value=?", [$lookup, $v]); }
                            $gLookupDel++;
                        }
                    }
                    $canonInLookup = in_array($canonical, $lookupVals, true);
                    if ($prefer === null && !$canonInLookup && !$dry) {
                        Database::exec("INSERT IGNORE INTO lookups (list_type, value) VALUES (?, ?)", [$lookup, $canonical]);
                    }
                }
            }

            $report['groups'][] = [
                'label' => $label, 'merges' => $gMerges, 'rows' => $gRows,
                'lookupDel' => $gLookupDel, 'samples' => $samples,
            ];
            $report['totals']['merges']    += $gMerges;
            $report['totals']['rows']      += $gRows;
            $report['totals']['lookupDel'] += $gLookupDel;
        }

        return $report;
    }
}
