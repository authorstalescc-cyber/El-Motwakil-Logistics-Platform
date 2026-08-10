<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\UserPref;

/**
 * تفضيلات العرض — نقطة الحفظ اللي الواجهة بتنادي عليها (docs/19 · المرحلة ٢).
 *
 * ⚠️ **كل الإجراءات دي بتخصّ صاحب الجلسة نفسه** — الهوية بتتاخد من `$_SESSION`
 *    لا من الفورم، فمستحيل مستخدم يكتب تفضيل لغيره مهما بعت إيه.
 *
 * ⚠️ **الحارس الحقيقي في `UserPref`** (قاعدة ③): القفل والمفاتيح المسموحة
 *    وطول القيمة كلها بتتفحص جوّه الموديل. الكنترولر بيترجم الاستثناء وبس.
 *
 * ⚠️ `json()` بتطبع وما بتوقفش — لازم `exit` بعدها وإلا أي تحذير PHP بيتلزق
 *    جوّه الـJSON ويبوّظه (المصيدة الموثَّقة).
 */
class PrefController extends Controller
{
    /** حفظ تفضيل واحد (fetch من `app.js`). */
    public function save(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $key   = (string) ($_POST['key'] ?? '');
        $value = (string) ($_POST['value'] ?? '');

        try {
            if ($value === '' || $value === 'null') {
                UserPref::forget($key);
            } else {
                UserPref::set($key, $value);
            }
        } catch (\Throwable $e) {
            http_response_code(422);
            $this->json(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $this->json(['ok' => true]);
        exit;
    }

    /**
     * ترحيل تفضيلات المتصفح للحساب — بيتنادى مرة واحدة من الواجهة.
     *
     * ⚠️ **بيضيف الناقص وبس**: لو المستخدم عنده تفضيل على الخادم خلاص، نسخة
     *    المتصفح ما تفوقهاش (ممكن تكون من جهاز قديم).
     */
    public function adopt(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $raw   = (string) ($_POST['pairs'] ?? '');
        $pairs = json_decode($raw, true);
        if (!is_array($pairs)) {
            http_response_code(422);
            $this->json(['ok' => false, 'error' => 'شكل البيانات غلط.']);
            exit;
        }
        // ⚠️ سقف عدد المفاتيح — الطلب جاي من العميل فمينفعش يبقى مفتوح
        $pairs = array_slice($pairs, 0, 200, true);
        $moved = UserPref::adopt(array_map('strval', $pairs));

        $this->json(['ok' => true, 'moved' => $moved]);
        exit;
    }

    /** تصفير تفضيلات المستخدم الحالي كلها (رجوع لافتراضي الشركة). */
    public function reset(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $n = UserPref::forgetAll();
        $this->done($n > 0 ? "اتصفّرت {$n} تفضيلة — رجعت لشكل الشركة." : 'مفيش تفضيلات خاصة عندك.',
            'settings', 'tab-display');
    }
}
