<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Freight;
use App\Models\Habit;
use App\Models\NameSuggestion;
use App\Models\Permit;

class SuggestionController extends Controller
{
    /**
     * عادات الطرف — «العميل ده بيروح فين عادةً؟» (v97).
     *
     * ⚠️ **بيرجّع اقتراحًا وخلاص — ما بيكتبش ولا حقل.** الجافاسكريبت هو اللي
     *    بيملا الشاشة، والمالك شايف القيمة قدامه قبل الحفظ. لو الجافاسكريبت
     *    ما اشتغلش، الفورم بيفضل فاضي زي الأول بالحرف.
     */
    public function habits(): void
    {
        $this->requireAuth();
        $ctx = [
            'client'  => trim((string) ($_GET['client']  ?? '')),
            'company' => trim((string) ($_GET['company'] ?? '')),
        ];
        $items = ($ctx['client'] !== '' || $ctx['company'] !== '') ? Habit::all($ctx) : [];
        $this->json(['items' => $items]);
        exit;   // ⚠️ `json()` بتطبع وما بتوقفش — أي تحذير بعدها بيتلزق جوّه الـJSON
    }

    /**
     * سعر السيارة المعتاد لعميل الإذن (v98) — أكبر مكسب في خطة الاقتراح.
     *
     * ⚠️ **بياخد رقم الإذن بس** ويستخرج السياق (عميل · جهة · تصنيف · عدد
     *    سيارات) من القاعدة بنفسه. الشاشة ما بتبعتش السياق عشان **ما تقدرش
     *    تزوّره**: العميل الجاي من الفورم ممكن يتغيّر بإيد المستخدم قبل ما
     *    يبعت، والسعر ساعتها بيتعلّم من عميل تاني. الحارس في الموديل مش في
     *    الشاشة (القاعدة ③).
     *
     * ⚠️ **بيرجّع رقمًا للعرض — ولا بيكتب مليم.** الفلوس اقتراح يتداس.
     */
    public function carPrice(string $permitId): void
    {
        $this->requireAuth();
        $pid = (int) $permitId;
        $p = Permit::find($pid);
        if (!$p) { $this->json(['ok' => false]); exit; }

        $ctx = [
            'client'         => (string) ($p['client'] ?? ''),
            'destination'    => (string) ($p['destination'] ?? ''),
            'container_type' => (string) ($p['container_type'] ?? ''),
            'exclude_permit' => $pid,
        ];
        $bills = Permit::bills($pid);
        $hit   = Habit::carPrice($ctx);

        $this->json([
            'ok'    => true,
            /* عدد السيارات من **نفس** مصدر فورم الفوترة (`Freight`) — لو
               اتفرّقوا، الشاشة تقول «٤ سيارات» والاقتراح يضرب في ٥. */
            'cars'  => Freight::carsFor(
                self::permitBreakdown($pid), (int) ($p['containers'] ?? 0), $p['container_type'] ?? null
            ) ?: count($bills),
            'price' => $hit,
        ]);
        exit;
    }

    /** نافذة «سعر السيارة ده جاي منين؟» — الفواتير السابقة وأسعارها. */
    public function carPriceDrill(string $permitId): void
    {
        $this->requireAuth();
        $pid = (int) $permitId;
        $p = Permit::find($pid);
        if (!$p) { $this->notFound('الإذن ده مش موجود.'); }
        $ctx = [
            'client'         => (string) ($p['client'] ?? ''),
            'destination'    => (string) ($p['destination'] ?? ''),
            'container_type' => (string) ($p['container_type'] ?? ''),
            'exclude_permit' => $pid,
        ];
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('permits/_price_drill', [
            'hit'     => Habit::carPrice($ctx),
            'samples' => Habit::priceSamples($ctx),
        ], false);
    }

    /** تفصيل حاويات الإذن من بوالصه — نفس شكل `$ctBreakdown` في الشاشات. */
    private static function permitBreakdown(int $pid): array
    {
        return array_map(
            static fn (array $r): array => ['type' => (string) $r['type'], 'cnt' => (int) $r['cnt']],
            \App\Core\Database::all(
                "SELECT container_type AS type, COALESCE(SUM(container_count),0) AS cnt
                   FROM bawalis
                  WHERE permit_id = ? AND container_type IS NOT NULL AND container_type <> ''
                  GROUP BY container_type",
                [$pid]
            )
        );
    }

    /**
     * الأذونات اللي الاقتراح اتعلّم منها — نافذة «الرقم ده جاي منين؟».
     * (قاعدة المالك الدائمة: ممنوع رقم أصمّ.)
     */
    public function habitDrill(string $field): void
    {
        $this->requireAuth();
        if (!isset(Habit::FIELDS[$field])) {
            $this->notFound('نوع الاقتراح ده مش موجود.');
        }
        $ctx = [
            'client'  => trim((string) ($_GET['client']  ?? '')),
            'company' => trim((string) ($_GET['company'] ?? '')),
        ];
        // ⚠️ محروسة بـ`headers_sent()`: أي مخرَج سابق بيخلّي `header()` ترمي
        //    Warning **جوّه جسم الرد** فالنافذة تستقبل HTML متلوّث (باج v50).
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('permits/_habit_drill', [
            'field'   => $field,
            'label'   => Habit::FIELDS[$field],
            'hit'     => Habit::top($field, $ctx),
            'samples' => Habit::samples($field, $ctx),
        ], false);
    }

    public function names(): void
    {
        $this->requireAuth();
        $type=trim($_GET['type'] ?? ''); $q=trim($_GET['q'] ?? '');
        $long = mb_strlen($q,'UTF-8') >= 2;
        $this->json([
            'q'     => $q,
            'items' => $long ? NameSuggestion::search($type,$q) : [],
            // حوكمة الاسم (توحيد تلقائي / تعارض قريب) — نفس منطق الخادم عند الحفظ
            'guard' => $long ? NameSuggestion::resolve($type,$q) : ['value'=>$q,'snapped'=>false,'conflict'=>null],
        ]);
    }
}
