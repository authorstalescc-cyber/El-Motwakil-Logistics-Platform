<?php
namespace App\Models;

use App\Core\App;
use App\Core\Database;
use App\Core\Groq;

/**
 * المساعد الذكي — يجيب على أسئلة صاحب الشركة بالعامية اعتماداً على بيانات النظام.
 *
 * الطريقة (grounded / بلا تخمين):
 *  1) يبني «نظرة عامة لحظية» من محرّك التجميعات (Metrics) والعهدة (Custody).
 *  2) يفكّك السؤال لكلمات ويبحث بها عبر محرّك البحث الشامل (Search) لجلب السجلات المتعلّقة.
 *  3) يرسل كل ذلك مع السؤال إلى Groq مع تعليمات صارمة: «اعتمد فقط على المعطى، وممنوع اختراع أرقام».
 *
 * كل المبالغ في السياق بالجنيه المصري. اتجاه المال: «المتبقي» = دين على الشركة للعميل.
 */
class Assistant
{
    /** كلمات شائعة تُتجاهل عند تفكيك السؤال للبحث (حتى لا تُنتج ضجيجاً) */
    private const STOP = [
        'كام','احنا','إحنا','عليا','علينا','عليه','عليها','ليه','لهم','ليها','في','من','على','عن','الى','إلى',
        'اللي','الي','ايه','إيه','عايز','عاوز','ممكن','هو','هي','ده','دي','دى','كل','مع','بكام','فلوس','مبلغ',
        'حساب','اعرف','عرفني','قولي','قوللي','وريني','لو','عند','عندنا','عندي','الشركة','شركة','مصنع','السيد',
        'الاستاذ','استاذ','هل','متى','امتى','إمتى','فين','مين','كده','يعني','ايوه','واحد','ولا','اكتر','أكتر',
        'اقل','أقل','النهاردة','امبارح','بتاع','بتاعت','دلوقتي','عشان','علشان','and','the','total',
    ];

    /** هل المساعد جاهز للعمل؟ */
    public static function enabled(): bool
    {
        return Groq::enabled();
    }

    /**
     * يجيب على سؤال. يبني السياق ثم يستدعي Groq.
     * @param array $history سجل محادثة مختصر [['role'=>'user|assistant','content'=>'...'], ...]
     * @return array{ok:bool, answer:string, sources:array, error:?string, model:string}
     */
    public static function ask(string $question, array $history = []): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['ok' => false, 'answer' => '', 'sources' => [], 'error' => 'اكتب سؤالك الأول.', 'model' => ''];
        }
        if (!Groq::enabled()) {
            return ['ok' => false, 'answer' => '', 'sources' => [],
                    'error' => 'المساعد الذكي غير مُفعّل. راجع إعدادات groq في config.php.', 'model' => ''];
        }

        $sources = self::retrieve($question);
        $details = self::detailedContext($question);
        $context = self::snapshot()
                 . ($details !== '' ? "\n\n" . $details : '')
                 . "\n\n" . self::formatSources($sources);

        $messages = [['role' => 'system', 'content' => self::systemPrompt()]];

        // سجل محادثة مختصر (آخر 6 رسائل) لفهم الأسئلة المترابطة
        foreach (array_slice($history, -6) as $h) {
            $role    = (($h['role'] ?? '') === 'user') ? 'user' : 'assistant';
            $content = trim((string) ($h['content'] ?? ''));
            if ($content !== '') { $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)]; }
        }

        $messages[] = ['role' => 'user', 'content' =>
            "بيانات الشركة الحالية (اعتمد عليها فقط، وكل المبالغ بالجنيه المصري):\n\n" . $context .
            "\n\n=== سؤال صاحب الشركة ===\n" . $question];

        $res = Groq::chat($messages);
        if (!$res['ok']) {
            return ['ok' => false, 'answer' => '', 'sources' => $sources, 'error' => $res['error'], 'model' => Groq::model()];
        }
        return ['ok' => true, 'answer' => $res['content'], 'sources' => $sources, 'error' => null, 'model' => Groq::model()];
    }

    /** التعليمات الثابتة للموديل */
    private static function systemPrompt(): string
    {
        $company = App::setting('company_name', 'شركة المتوكل');
        return
"إنت «مساعد {$company}» الذكي. بتساعد صاحب الشركة (وهو مش مبرمج) إنه يفهم بيانات شركته للنقل والتخليص الجمركي.

قواعد لازم تلتزم بيها:
- جاوب بالعامية المصرية البسيطة والعملية، وباختصار ومن غير لفّ.
- اعتمد **فقط** على البيانات المكتوبة في رسالة المستخدم. **ممنوع تماماً تخترع أي رقم أو اسم** مش موجود قدامك.
- قبل ما تقول إن المعلومة مش موجودة، دوّر كويس في كل البيانات المعطاة — الأرقام الإجمالية، وتفاصيل السجلات المطابقة (فيها السائق ورقم العربية والنولون والمتبقي لكل بوليصة)، وقوائم الشركات والسائقين. لو لقيت المعلومة أو رقم قريب مرتبط، اعرضه.
- لو سُئلت عن شركة أو عميل واسمه **مش موجود** في «قائمة الشركات/العملاء المسجّلين»، قول له صراحة إن مفيش حد بالاسم ده، واعرض عليه الأسماء القريبة أو المتاحة من القائمة عشان يختار.
- لو فعلاً مفيش أي معلومة متعلّقة بالسؤال، ساعتها بس قول: «المعلومة دي مش ظاهرة قدامي دلوقتي» واقترح القسم اللي ممكن يدوّر فيه — من غير ما تخمّن.
- **متناقضش نفسك:** لو لقيت المعلومة وجاوبت بيها، بلاش تقول بعدها إنها مش موجودة أو مش ظاهرة. جاوب بوضوح ووقف. رد مختصر أحسن من رد فيه لفّ وتكرار.
- اتجاه الفلوس مهم: «المتبقي» أو «الدين» معناه إن **الشركة هي اللي عليها فلوس للعميل** (احنا اللي مدينين له)، مش العكس. و«المدفوعات» = فلوس **خارجة** من الشركة.
- لما تذكر مبلغ اكتبه واضح بالجنيه (مثال: 45,000 ج.م).
- استخدم الأرقام الجاهزة قدامك زي ما هي بالظبط (زي «متوسط نولون البوليصة» أو «إجمالي النولون»). ماتعملش عملية حسابية من عندك طول ما الرقم موجود جاهز. تحسب بس لو الرقم مش متوفّر جاهز، وساعتها وضّح إزاي حسبته.
- قاموس سريع للمصطلحات عشان تفهم قصد السؤال: «النولون» = سعر/أجرة نقل البوليصة. «الإصدار الصادر» = إذن (تصدير). «الشهادة الواردة» = وارد (استيراد). «العهدة» = فلوس مع السائق. «المتبقي/الدين» = فلوس علينا للعميل.
- بلاش أي كلام تقني أو أكواد. اتكلم زي محاسب شاطر بيشرح لصاحب الشغل.";
    }

    /** يبني «النظرة العامة اللحظية» كنصّ عربي مقروء */
    private static function snapshot(): string
    {
        $out = ['== نظرة عامة على الشركة (لحظية) =='];

        try {
            $t = Metrics::totals();
            $out[] = '• الدين المستحق علينا للعملاء (إجمالي المتبقي): ' . money($t['outstanding'], false);
            $out[] = '• إجمالي المصروفات: ' . money($t['expenses'], false);
            $out[] = '• إجمالي المدفوعات الصادرة للعملاء: ' . money($t['collections'], false);
            $out[] = '• إجمالي النولون: ' . money($t['freight'], false);
        } catch (\Throwable $e) {}

        try { $out[] = '• رصيد محفظة العهدة الحالي: ' . money(Custody::balance(), false); } catch (\Throwable $e) {}

        try {
            $c = Metrics::counters();
            $out[] = "• الأعداد — بوالص: {$c['bawalis']}، فواتير: {$c['invoices']}، حاويات: {$c['containers']}، شهادات تخليص: {$c['certs']}";
        } catch (\Throwable $e) {}

        try {
            $p = Metrics::permitsSummary();
            $out[] = "• الإصدارات (أذونات/شهادات) — الإجمالي: {$p['total']}، مفتوح: {$p['open']}، مقفول: {$p['closed']}، غير مكتمل: {$p['incomplete']}";
        } catch (\Throwable $e) {}

        // تفصيل الإصدارات حسب النوع: صادر = إذن، وارد = شهادة
        try {
            $names = ['sader' => 'صادر (إذن)', 'warid' => 'وارد (شهادة)'];
            $parts = [];
            foreach (Database::all("SELECT permit_type, COUNT(*) c, COALESCE(SUM(containers),0) cont FROM permits GROUP BY permit_type") as $r) {
                $lbl = $names[$r['permit_type']] ?? ($r['permit_type'] ?: 'غير محدّد');
                $parts[] = "{$lbl}: {$r['c']} إصدار ({$r['cont']} حاوية)";
            }
            if ($parts) { $out[] = '• تفصيل الإصدارات حسب النوع — ' . implode('، ', $parts); }
        } catch (\Throwable $e) {}

        // أرقام البوالص (النولون والعهد) — صادر/وارد.
        // المتوسط يُحسب هنا (الإجمالي ÷ العدد) ليطابق الحساب البديهي فلا يعيد الموديل حسابه بشكل مختلف.
        try {
            $b = Database::row(
                "SELECT COUNT(*) n, COALESCE(SUM(freight),0) tot,
                        COALESCE(SUM(custody),0) cust, COALESCE(SUM(remaining),0) rem,
                        COALESCE(SUM(bill_type='sader'),0) sader, COALESCE(SUM(bill_type='warid'),0) warid
                 FROM bawalis WHERE is_legacy = 0");
            if ($b) {
                $n   = (int) $b['n'];
                $avg = $n > 0 ? round((float) $b['tot'] / $n, 2) : 0.0;
                $out[] = "• البوالص — العدد: {$n} (صادر {$b['sader']}، وارد {$b['warid']})"
                       . '، إجمالي النولون: ' . money($b['tot'], false)
                       . '، متوسط نولون البوليصة (الإجمالي÷العدد): ' . money($avg, false)
                       . '، إجمالي العهد: ' . money($b['cust'], false)
                       . '، المتبقي علينا من البوالص: ' . money($b['rem'], false);
            }
        } catch (\Throwable $e) {}

        try {
            $s = Metrics::suppliersTotal();
            if ($s != 0) { $out[] = '• إجمالي مستحقات الموردين: ' . money($s, false); }
        } catch (\Throwable $e) {}

        try {
            $debts = Metrics::clientDebts(40);
            if ($debts) {
                $out[] = '';
                $out[] = '== العملاء اللي علينا لهم فلوس (مرتّبين من الأكبر) ==';
                foreach ($debts as $d) {
                    $out[] = '• ' . $d['name'] . ': علينا له ' . money($d['remaining'], false)
                           . ' (إجمالي نولون/فواتير ' . money($d['invoices'], false) . ')';
                }
            }
        } catch (\Throwable $e) {}

        // قائمة كل الشركات/العملاء المعروفين (تساعد على معرفة هل الاسم المسؤول عنه موجود أصلاً)
        try {
            $names = Database::all(
                "SELECT name FROM (
                    SELECT DISTINCT company AS name FROM bawalis WHERE is_legacy = 0 AND company IS NOT NULL AND company <> ''
                    UNION SELECT DISTINCT company FROM permits  WHERE company IS NOT NULL AND company <> ''
                    UNION SELECT DISTINCT client  FROM permits  WHERE client  IS NOT NULL AND client  <> ''
                 ) t ORDER BY name LIMIT 80");
            if ($names) {
                $list = array_map(fn($r) => $r['name'], $names);
                $out[] = '';
                $out[] = '== كل الشركات/العملاء المسجّلين في النظام (' . count($list) . ') ==';
                $out[] = implode('، ', $list);
            }
        } catch (\Throwable $e) {}

        // قائمة السائقين وأرقام عرباتهم (من البوالص)
        try {
            $drv = Database::all(
                "SELECT DISTINCT driver, car_no FROM bawalis
                 WHERE is_legacy = 0 AND driver IS NOT NULL AND driver <> '' ORDER BY driver LIMIT 60");
            if ($drv) {
                $out[] = '';
                $out[] = '== السائقون وأرقام العربيات ==';
                foreach ($drv as $r) {
                    $car = trim((string)$r['car_no']);
                    $out[] = '• ' . $r['driver'] . ($car !== '' ? ' — عربية رقم ' . $car : '');
                }
            }
        } catch (\Throwable $e) {}

        return implode("\n", $out);
    }

    /**
     * تفاصيل السجلات المطابقة لكلمات السؤال (بوالص/أذونات) — أعمق من ملخص البحث.
     * يعطي الموديل بيانات كل سجل (السائق/العربية/النولون/المتبقي…) للأسئلة التفصيلية.
     */
    private static function detailedContext(string $q): string
    {
        $tokens = self::tokens($q);
        if (!$tokens) { return ''; }

        $types = ['sader' => 'صادر', 'warid' => 'وارد'];
        $bills = []; $permits = [];

        foreach ($tokens as $tok) {
            if (count($bills) < 12) {
                try {
                    $like = '%' . self::escapeLike($tok) . '%';
                    $rows = Database::all(
                        "SELECT id, bill_number, bill_type, company, declaration_owner, driver, car_no, car_owner,
                                destination, container_no, freight, custody, remaining, bill_date, permit_no
                         FROM bawalis
                         WHERE is_legacy = 0 AND (
                               company LIKE ? OR declaration_owner LIKE ? OR driver LIKE ? OR car_no LIKE ?
                            OR bill_number LIKE ? OR permit_no LIKE ? OR destination LIKE ? OR car_owner LIKE ?)
                         ORDER BY bill_date DESC LIMIT 8",
                        array_fill(0, 8, $like));
                    foreach ($rows as $r) { $bills[(int)$r['id']] = $r; }
                } catch (\Throwable $e) {}
            }
            if (count($permits) < 8) {
                try {
                    $like = '%' . self::escapeLike($tok) . '%';
                    $rows = Database::all(
                        "SELECT id, permit_no, permit_type, company, client, destination, port, containers, status, permit_date
                         FROM permits
                         WHERE company LIKE ? OR client LIKE ? OR permit_no LIKE ? OR destination LIKE ? OR port LIKE ?
                         ORDER BY permit_date DESC LIMIT 6",
                        array_fill(0, 5, $like));
                    foreach ($rows as $r) { $permits[(int)$r['id']] = $r; }
                } catch (\Throwable $e) {}
            }
        }

        if (!$bills && !$permits) { return ''; }

        $out = ['== تفاصيل السجلات المطابقة لسؤالك =='];
        foreach (array_slice($bills, 0, 14) as $b) {
            $t = $types[$b['bill_type']] ?? $b['bill_type'];
            $line = '• [بوليصة ' . ($b['bill_number'] ?: '—') . '] نوع: ' . $t;
            if (!empty($b['company']))           { $line .= ' · الشركة: ' . $b['company']; }
            if (!empty($b['declaration_owner']))  { $line .= ' · صاحب البيان: ' . $b['declaration_owner']; }
            if (!empty($b['driver']))             { $line .= ' · السائق: ' . $b['driver']; }
            if (!empty($b['car_no']))             { $line .= ' · رقم العربية: ' . $b['car_no']; }
            if (!empty($b['car_owner']))          { $line .= ' · مالك العربية: ' . $b['car_owner']; }
            if (!empty($b['destination']))        { $line .= ' · الوجهة: ' . $b['destination']; }
            $line .= ' · النولون: ' . money($b['freight'], false)
                   . ' · العهدة: ' . money($b['custody'], false)
                   . ' · المتبقي علينا: ' . money($b['remaining'], false);
            if (!empty($b['bill_date']))          { $line .= ' · التاريخ: ' . $b['bill_date']; }
            $out[] = $line;
        }
        foreach (array_slice($permits, 0, 10) as $p) {
            $t = $types[$p['permit_type']] ?? $p['permit_type'];
            $st = ($p['status'] ?? '') === 'closed' ? 'مقفول' : 'مفتوح';
            $line = '• [' . ($t === 'صادر' ? 'إذن' : 'شهادة') . ' ' . ($p['permit_no'] ?: '—') . '] نوع: ' . $t . ' · الحالة: ' . $st;
            if (!empty($p['company']))     { $line .= ' · الشركة: ' . $p['company']; }
            if (!empty($p['client']))      { $line .= ' · الوكيل: ' . $p['client']; }
            if (!empty($p['destination'])) { $line .= ' · الوجهة: ' . $p['destination']; }
            if (!empty($p['port']))        { $line .= ' · الميناء: ' . $p['port']; }
            $line .= ' · الحاويات: ' . (int)$p['containers'];
            if (!empty($p['permit_date'])) { $line .= ' · التاريخ: ' . $p['permit_date']; }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /** تهريب رموز LIKE الخاصة */
    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /** يفكّك السؤال لكلمات مفيدة ويبحث بكل واحدة، ثم يدمج النتائج مزيلاً التكرار */
    private static function retrieve(string $q): array
    {
        $tokens = self::tokens($q);
        if (!$tokens) { return []; }

        $budget = 14;
        $seen   = [];
        $rows   = [];

        foreach ($tokens as $tok) {
            if (count($rows) >= $budget) { break; }
            try { $groups = Search::query($tok, 4); }
            catch (\Throwable $e) { continue; }

            foreach ($groups as $g) {
                foreach ($g['rows'] as $r) {
                    $url = $r['url'] ?? '';
                    if ($url === '' || isset($seen[$url])) { continue; }
                    $seen[$url] = true;
                    $rows[] = [
                        'group'  => $g['title'],
                        'label'  => $r['label'],
                        'sub'    => $r['sub'],
                        'field'  => $r['field'],
                        'amount' => $r['amount'],
                        'date'   => $r['date'],
                        'url'    => $url,
                    ];
                    if (count($rows) >= $budget) { break 2; }
                }
            }
        }
        return $rows;
    }

    /** كلمات السؤال بعد إزالة الرموز والكلمات الشائعة والقصيرة (مع فكّ البادئات العربية) */
    private static function tokens(string $q): array
    {
        $q = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s]/u', ' ', $q);
        $parts = preg_split('/\s+/u', trim((string) $q)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (mb_strlen($p, 'UTF-8') < 3) { continue; }
            if (in_array($p, self::STOP, true)) { continue; }
            $out[] = $p;
            // نسخة بلا البادئات المتّصلة (لـ/و/بـ/الـ…) — «لسوفتكس» ⇒ «سوفتكس»، «والشركة» ⇒ «شركة»
            $stripped = self::stripProclitic($p);
            if ($stripped !== $p && mb_strlen($stripped, 'UTF-8') >= 3 && !in_array($stripped, self::STOP, true)) {
                $out[] = $stripped;
            }
        }
        return array_values(array_unique($out));
    }

    /** يفكّ بادئة عربية متّصلة واحدة (أطولها أولاً) لو باقي الكلمة ≥ 3 أحرف */
    private static function stripProclitic(string $w): string
    {
        foreach (['وال','فال','بال','كال','لل','ال','و','ف','ب','ك','ل'] as $pre) {
            if (mb_strpos($w, $pre, 0, 'UTF-8') === 0) {
                $rest = mb_substr($w, mb_strlen($pre, 'UTF-8'), null, 'UTF-8');
                if (mb_strlen($rest, 'UTF-8') >= 3) { return $rest; }
            }
        }
        return $w;
    }

    /** يصيغ السجلات المسترجَعة كنصّ للسياق */
    private static function formatSources(array $rows): string
    {
        if (!$rows) {
            return "== سجلات متعلّقة بالسؤال ==\n(مافيش سجل مطابق مباشر — اعتمد على النظرة العامة فوق)";
        }
        $out = ['== سجلات متعلّقة بالسؤال (من بحث النظام) =='];
        foreach ($rows as $r) {
            $line = '• [' . $r['group'] . '] ' . $r['label'];
            if (!empty($r['sub']))            { $line .= ' — ' . $r['sub']; }
            if (!empty($r['field']))          { $line .= ' (طابق: ' . $r['field'] . ')'; }
            if (isset($r['amount']) && $r['amount'] !== null) { $line .= ' | مبلغ: ' . money($r['amount'], false); }
            if (!empty($r['date']))           { $line .= ' | تاريخ: ' . $r['date']; }
            $out[] = $line;
        }
        return implode("\n", $out);
    }
}
