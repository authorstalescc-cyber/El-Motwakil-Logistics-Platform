<?php
namespace App\Models;

use App\Core\Database;

class NameSuggestion
{
    public static function search(string $type, string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') { return []; }
        $names = self::names($type); $q = Maintenance::normkey($query); $ranked=[];
        foreach ($names as $name) {
            $n = Maintenance::normkey($name); if ($n === '') { continue; }
            $kind='similar'; $score=0;
            if ($n === $q) { $kind='exact'; $score=1000; }
            elseif (str_contains($n,$q) || str_contains($q,$n)) { $kind='match'; $score=800-min(abs(mb_strlen($n)-mb_strlen($q)),50); }
            else {
                $distance=self::distance($q,$n); $max=max(mb_strlen($q),mb_strlen($n),1);
                $ratio=1-($distance/$max);
                $tokensQ=array_filter(explode(' ',$q)); $tokensN=array_filter(explode(' ',$n));
                $common=count(array_intersect($tokensQ,$tokensN));
                $score=(int)round($ratio*500)+$common*80;
                if ($ratio < .48 && $common === 0) { continue; }
            }
            $ranked[]=['name'=>$name,'kind'=>$kind,'score'=>$score];
        }
        usort($ranked, fn($a,$b)=>$b['score']<=>$a['score'] ?: strcmp($a['name'],$b['name']));
        return array_slice($ranked,0,max(1,min(20,$limit)));
    }

    /**
     * حوكمة الاسم عند الحفظ — مصدر الحقيقة الوحيد للعميل والخادم معًا.
     * يُرجع:
     *   value    => التهجئة النهائية (المعتمدة لو تطابق normkey مع اسم موجود، وإلا كما أُدخِل)
     *   snapped  => هل استُبدل بالتهجئة المخزّنة (اختلاف همزة/ألف/ة/مسافات)؟
     *   conflict => اسم مخزّن قريب جدًا لكن بمفتاح مختلف (خطأ إملائي محتمل)، أو null
     *
     * القاعدة: تطابق `normkey` ⇒ نفس الكيان قطعًا ⇒ يُوحَّد تلقائيًا بلا إزعاج.
     * القرب بمسافة تحرير صغيرة مع **نفس أول حرفين** ⇒ يُعرَض للاختيار (لا يُوحَّد تلقائيًا).
     * شرط أول حرفين يمنع الخلط بين أسماء قصيرة مختلفة (احمد ≠ محمد).
     */
    public static function resolve(string $type, string $raw): array
    {
        $raw = trim($raw);
        $out = ['value' => $raw, 'snapped' => false, 'conflict' => null];
        if ($raw === '') { return $out; }
        $nk = Maintenance::normkey($raw);
        if ($nk === '') { return $out; }

        $names = self::names($type);

        // 1) تطابق المفتاح المُوحَّد ⇒ استخدم التهجئة المخزّنة (توحيد تلقائي آمن)
        foreach ($names as $name) {
            if (Maintenance::normkey($name) === $nk) {
                $out['value']   = $name;
                $out['snapped'] = ($name !== $raw);
                return $out;
            }
        }

        // 2) قرب إملائي محافظ: نفس أول حرفين + مسافة تحرير صغيرة، لأسماء طولها ≥ 4
        $len = mb_strlen($nk, 'UTF-8');
        if ($len >= 4) {
            $maxDist = $len <= 8 ? 1 : 2;
            $pfx = mb_substr($nk, 0, 2, 'UTF-8');
            $bestDist = PHP_INT_MAX;
            foreach ($names as $name) {
                $n = Maintenance::normkey($name);
                if ($n === '' || $n === $nk) { continue; }
                if (mb_substr($n, 0, 2, 'UTF-8') !== $pfx) { continue; }
                $d = self::distance($nk, $n);
                if ($d <= $maxDist && $d < $bestDist) { $bestDist = $d; $out['conflict'] = $name; }
            }
        }
        return $out;
    }

    private static function names(string $type): array
    {
        $allowed=array_keys(Lookup::TYPES); $out=[];
        if (in_array($type,$allowed,true)) { $out=Lookup::ofType($type); }
        $sources = [
            'owner'=>[['bawalis','declaration_owner'],['agents','name']],
            'company'=>[['bawalis','company'],['companies','name'],['permits','company']],
            'client'=>[['agents','name'],['permits','client']],
            'driver'=>[['bawalis','driver'],['movements','driver']],
            'car_owner'=>[['bawalis','car_owner'],['movements','car_owner']],
            'destination'=>[['bawalis','destination'],['permits','destination']],
            'classification'=>[['bawalis','classification']],
            'container_type'=>[['bawalis','container_type']],
        ];
        foreach ($sources[$type] ?? [] as [$table,$column]) {
            try {
                foreach (Database::all("SELECT DISTINCT TRIM(`$column`) name FROM `$table` WHERE `$column` IS NOT NULL AND TRIM(`$column`)<>'' LIMIT 2000") as $row) { $out[]=$row['name']; }
            } catch (\Throwable $e) {}
        }
        $seen=[]; $unique=[];
        foreach ($out as $name) { $key=Maintenance::normkey($name); if ($key!==''&&!isset($seen[$key])){$seen[$key]=1;$unique[]=trim($name);} }
        return $unique;
    }

    /** Levenshtein Unicode صغير للأسماء العربية. */
    private static function distance(string $a, string $b): int
    {
        $aa=preg_split('//u',$a,-1,PREG_SPLIT_NO_EMPTY); $bb=preg_split('//u',$b,-1,PREG_SPLIT_NO_EMPTY);
        $prev=range(0,count($bb));
        foreach ($aa as $i=>$ca) {
            $cur=[$i+1];
            foreach ($bb as $j=>$cb) { $cur[$j+1]=min($cur[$j]+1,$prev[$j+1]+1,$prev[$j]+($ca===$cb?0:1)); }
            $prev=$cur;
        }
        return $prev[count($bb)];
    }
}
