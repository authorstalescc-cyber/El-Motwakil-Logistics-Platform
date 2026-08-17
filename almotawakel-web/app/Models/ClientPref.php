<?php
namespace App\Models;

use App\Core\App;
use App\Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * تفضيل فوترة العميل — ضريبي/غير ضريبي · فاتورة/كشف/مطالبة (v100)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * طلب المالك: «في عملاء بيتحاسبوا ضريبي وعملاء مش بيتحاسبوا ضريبي، وفي عملاء
 * بيفضل لهم كشف الحساب وعملاء بيفضل لهم المطالبة — محتاج السيستم يتعلّم ده».
 *
 * ═══ ⚠️ ليه دي مش في `Habit`؟ ═══
 * لأنها **مش عادة إحصائية — دي حقيقة تجارية**. الجهة والسعر بيتغيّروا من شحنة
 * لشحنة فبيتقاسوا بالتكرار (v97/v98)، لكن «العميل ده بيتحاسب ضريبي» قرار
 * المالك عارفه ومش محتاج النظام يستنتجه كل مرة. فمكانه **بروفايل العميل**.
 *
 * ═══ طبقتان بترتيب صارم ═══
 *   ①  **مثبَّت** (`agents.default_*` مش NULL) ← قرار المالك، بيغلب أي حاجة.
 *   ②  **مستنتَج** من تاريخ فواتير العميل ← احتياطي لحد ما يتثبّت.
 * والفرق بينهم **بيتعرض على الشاشة** (`source`): المالك لازم يعرف هو بيشوف
 * قراره ولا تخمين النظام — الاتنين شكل واحد بيخلّي التخمين يتقري كقرار.
 *
 * ⚠️ **`NULL` مش `0`.** `NULL` = «المالك ما قالش» فبنستنتج؛ `0` = «قال: غير
 *    خاضع» فبنسكت ونطيع. خلطهم بيقفل باب الاستنتاج بالسكوت.
 *
 * ⚠️ **الدالة دي بتقترح افتراضي الفورم — والحارس النهائي فوقها.** قاعدة
 *    «كشف الحساب والمطالبة لا يخضعان للضريبة إطلاقاً» عايشة في
 *    `PermitInvoice::create()` وبتغلب أي تفضيل مخزَّن. الحارس في الموديل مش
 *    في الشاشة (القاعدة ③) — والتفضيل ده **شاشة**.
 */
final class ClientPref
{
    /** أقل عدد مستندات يسمح بالاستنتاج — أقل من كده مش نمط. */
    private const MIN_DOCS = 2;

    /** نسبة الثبات المطلوبة للاستنتاج. */
    private const MIN_SHARE = 0.60;

    /**
     * تفضيل العميل جاهز للفورم.
     *
     * @return array{doc_kind:string,taxable:int,vat_rate:float,
     *               source:string,kind_source:string,tax_source:string,
     *               docs:int,kind_hits:int,tax_hits:int}
     */
    public static function forAgent(?array $agent, string $clientName = ''): array
    {
        $name = trim($clientName) !== '' ? trim($clientName) : (string) ($agent['name'] ?? '');
        $globalVat = (float) App::setting('vat_rate', 0.14);

        $out = [
            'doc_kind'    => PermitInvoice::KIND_INVOICE,
            'taxable'     => 0,
            'vat_rate'    => $globalVat,
            'kind_source' => 'default',
            'tax_source'  => 'default',
            'docs'        => 0,
            'kind_hits'   => 0,
            'tax_hits'    => 0,
        ];

        $stats = self::history($name);
        $out['docs'] = $stats['docs'];

        // ② المستنتَج — بيتحسب الأول عشان المثبَّت يدهسه بعدين
        if ($stats['docs'] >= self::MIN_DOCS) {
            if ($stats['kind'] !== null && $stats['kind_share'] >= self::MIN_SHARE) {
                $out['doc_kind']    = $stats['kind'];
                $out['kind_source'] = 'learned';
                $out['kind_hits']   = $stats['kind_hits'];
            }
            if ($stats['tax'] !== null && $stats['tax_share'] >= self::MIN_SHARE) {
                $out['taxable']    = $stats['tax'];
                $out['tax_source'] = 'learned';
                $out['tax_hits']   = $stats['tax_hits'];
            }
        }

        // ① المثبَّت — قرار المالك بيغلب
        $kind = trim((string) ($agent['default_doc_kind'] ?? ''));
        if ($kind !== '' && isset(PermitInvoice::KINDS[$kind])) {
            $out['doc_kind']    = $kind;
            $out['kind_source'] = 'pinned';
        }
        if (($agent['default_taxable'] ?? null) !== null && $agent['default_taxable'] !== '') {
            $out['taxable']    = ((int) $agent['default_taxable']) === 1 ? 1 : 0;
            $out['tax_source'] = 'pinned';
        }
        $rate = $agent['default_vat_rate'] ?? null;
        if ($rate !== null && $rate !== '' && (float) $rate > 0) {
            $out['vat_rate'] = (float) $rate;
        }

        /* ⚠️ الحارس النهائي **متكرَّر هنا عن قصد**: الكشف والمطالبة ما بيخضعوش
           للضريبة إطلاقًا (`PermitInvoice::create`)، فتفضيل «خاضع» مع «كشف
           حساب» كان هيخلّي الفورم يفتح على حالة الخادم بيرفضها. تكرار حارس
           **بيضيّق** مسموح؛ اللي ممنوع تكرار حارس **بيوسّع**. */
        if ($out['doc_kind'] !== PermitInvoice::KIND_INVOICE) { $out['taxable'] = 0; }

        $out['source'] = ($out['kind_source'] === 'pinned' || $out['tax_source'] === 'pinned')
            ? 'pinned' : ($out['kind_source'] === 'learned' || $out['tax_source'] === 'learned'
                ? 'learned' : 'default');
        return $out;
    }

    /** تفضيل عميل بالاسم — بيحلّ الاسم لبروفايله الأول. */
    public static function forName(string $clientName): array
    {
        return self::forAgent(PartyMaster::resolve('agent', $clientName), $clientName);
    }

    /**
     * تاريخ فوترة العميل: أشهر نوع مستند وأشهر حالة ضريبية.
     *
     * ⚠️ **الملغاة مستبعدة** — مستند اتلغى مش تفضيل.
     * ⚠️ والمقارنة على **كل تهجئات الاسم** عبر `Habit::spellings` — نفس العميل
     *    بتهجئتين كان هيتقسّم لنصّين وما يوصلش لحد الاستنتاج أبدًا.
     */
    public static function history(string $clientName): array
    {
        $empty = ['docs' => 0, 'kind' => null, 'kind_hits' => 0, 'kind_share' => 0.0,
                  'tax' => null, 'tax_hits' => 0, 'tax_share' => 0.0];
        $names = Habit::spellings('agent', $clientName);
        if (!$names) { return $empty; }
        $marks = implode(',', array_fill(0, count($names), '?'));

        $rows = Database::all(
            "SELECT doc_kind, taxable, COUNT(*) AS c
               FROM permit_invoices
              WHERE client IN ($marks) AND COALESCE(status,'') <> 'void'
              GROUP BY doc_kind, taxable",
            $names
        );
        if (!$rows) { return $empty; }

        $docs = 0; $byKind = []; $byTax = [];
        foreach ($rows as $r) {
            $c = (int) $r['c'];
            $docs += $c;
            $k = (string) $r['doc_kind'];
            $byKind[$k] = ($byKind[$k] ?? 0) + $c;
            /* ⚠️ الضريبة بتتقاس على **الفواتير بس**: الكشف والمطالبة ممنوعين
               ضريبيًّا بالقاعدة، فعدّهم كـ«غير خاضع» بيزوّر النسبة ويخلّي عميلاً
               ضريبيًّا يبان غير ضريبي لمجرد إنه أخد كشوف كتير. */
            if ($k === PermitInvoice::KIND_INVOICE) {
                $t = ((int) $r['taxable']) === 1 ? 1 : 0;
                $byTax[$t] = ($byTax[$t] ?? 0) + $c;
            }
        }
        arsort($byKind);
        arsort($byTax);

        $kind = $byKind ? (string) array_key_first($byKind) : null;
        $tax  = $byTax ? (int) array_key_first($byTax) : null;
        $taxDocs = array_sum($byTax);

        return [
            'docs'       => $docs,
            'kind'       => $kind,
            'kind_hits'  => $kind !== null ? (int) $byKind[$kind] : 0,
            'kind_share' => ($kind !== null && $docs > 0) ? $byKind[$kind] / $docs : 0.0,
            'tax'        => $tax,
            'tax_hits'   => $tax !== null ? (int) $byTax[$tax] : 0,
            'tax_share'  => ($tax !== null && $taxDocs > 0) ? $byTax[$tax] / $taxDocs : 0.0,
        ];
    }

    /** وسم مقروء لمصدر القيمة — الشاشة لازم تفرّق بين قرار المالك وتخمين النظام. */
    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'pinned'  => 'مثبَّت',
            'learned' => 'من التاريخ',
            default   => 'الافتراضي',
        };
    }
}
