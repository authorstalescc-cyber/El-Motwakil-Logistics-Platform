<?php
namespace App\Models;

use App\Core\App;
use App\Core\Database;
use App\Core\Settings;
use App\Core\SettingsSchema;

/**
 * **فحص صحة الإعدادات + التصدير والاستيراد** (docs/19 · المرحلة ٧ · v79).
 *
 * ═══ اللزمة ═══
 * بعد ٦ مراحل بقى فيه **٦٠+ مفتاح** وسلاسل مؤرَّخة وتفضيلات وقائمة وصلاحيات.
 * والمالك مالوش طريقة يعرف بيها إن حاجة **متعارضة** أو **خارج نطاقها** غير
 * إنه يفتح كل قسم ويبصّ. والنقل بين محلي وإنتاج كان لسه بالإيد.
 *
 * ⚠️ **الفحص بيقول المشكلة والحل** — «الرقم ده غلط» بلا «أعمل إيه» بيخلّي
 *    المالك يقفل الشاشة. كل بند فيه `fix` بالعامية.
 *
 * ⚠️ **التصدير ما بيطلّعش المفاتيح السرّية أبدًا** — ملف الإعدادات بيتبعت
 *    بالإيميل أو بيتحط في مجلد، ومفتاح Groq فيه = تسريب دائم.
 */
class SettingsHealth
{
    /**
     * كل المشاكل: `[level, title, fix]`.
     * level: `error` لازم يتصلّح · `warn` يستاهل نظرة.
     */
    public static function check(): array
    {
        $out = [];

        // ① مفتاح في القاعدة برّه السجل — إعداد بيتخزّن ومحدّش بيقراه
        foreach (Database::all('SELECT setting_key FROM settings') as $r) {
            $k = (string) $r['setting_key'];
            if (SettingsSchema::has($k) || isset(SettingsSchema::LEGACY[$k])) { continue; }
            $out[] = ['error', "مفتاح «{$k}» في القاعدة ومش معرَّف",
                      'ده إعداد بيتخزّن ومحدّش بيقراه. سيبه أو اشطبه من القاعدة.'];
        }

        // ② قيمة خارج نطاقها — بتتقبل من سكربت قديم وبتفضل غلط بالسكوت
        foreach (SettingsSchema::editable() as $key => $def) {
            $raw = App::setting($key, null);
            if ($raw === null || $raw === '') { continue; }
            try {
                SettingsSchema::normalize($key, $raw);
            } catch (\Throwable $e) {
                $out[] = ['error', "«{$def['label']}» قيمتها مش صالحة: " . $e->getMessage(),
                          'افتح قسمها في الإعدادات واحفظ قيمة صحيحة.'];
            }
        }

        // ③ تعارضات معروفة بين المفاتيح
        $warn = Settings::valueOn('notify_submission_warn_hours');
        $urg  = Settings::valueOn('notify_submission_urgent_hours');
        if ($warn !== '' && $urg !== '' && (int) $urg <= (int) $warn) {
            $out[] = ['error', 'ساعات «عاجل» أقل من أو تساوي «متأخّر»',
                      'خلّي «عاجل» أكبر — وإلا كل بند متأخّر بيتعلّم عاجل على طول.'];
        }

        /* ⚠️ الشرط ده **مكتوب في الكود من v67**: `rep_bank_topup_from` لازم
           تكون قبل أو تساوي `rep_custody_epoch`، وإلا حسابات العهدة بتتلغبط. */
        $bank  = trim((string) App::setting('rep_bank_topup_from', ''));
        $epoch = trim((string) App::setting('rep_custody_epoch', ''));
        if ($bank !== '' && $epoch !== '' && $bank > $epoch) {
            $out[] = ['error', 'تاريخ «الخزنة = كل كاش الشركة» بعد خط فصل عهد المناديب',
                      'خلّي تاريخ الخزنة قبل خط الفصل أو مساوي له.'];
        }

        // ④ حالة التشغيل
        $backup = trim((string) App::setting('backup_last_ok', ''));
        if ($backup === '') {
            $out[] = ['warn', 'مفيش نسخة احتياطية مسجَّلة', 'المفروض تتاخد لوحدها كل يوم — خد واحدة من قسم الصيانة.'];
        } elseif (strtotime($backup) !== false && strtotime($backup) < strtotime('-3 days')) {
            $out[] = ['warn', 'آخر نسخة احتياطية من أكتر من ٣ أيام', 'خد نسخة جديدة.'];
        }

        if ((string) App::setting('push_enabled', '1') !== '1') {
            $out[] = ['warn', 'إشعارات الأجهزة مطفية لكل المستخدمين',
                      'لو ده مش مقصود، شغّلها من قسم الإشعارات.'];
        }
        if (trim((string) App::setting('public_base_url', '')) === '') {
            $out[] = ['warn', 'الرابط العام فاضي',
                      'روابط تتبع الشحنة للسائقين مش هتتولّد. حطّه في قسم التشغيل.'];
        }

        // ⑤ سلاسل مؤرَّخة بلا أساس — القراءة بترجع للإعداد بالسكوت
        if (Settings::scheduleReady()) {
            foreach (Settings::DATED_KEYS as $key) {
                $n = (int) Database::scalar(
                    'SELECT COUNT(*) FROM setting_schedules WHERE setting_key = ? AND effective_from = ?',
                    [$key, Settings::EPOCH_DATE]);
                if ($n === 0) {
                    $def = SettingsSchema::get($key);
                    $out[] = ['error', "«{$def['label']}» بلا أساس تاريخي",
                              'شغّل bin/migrate_v74.php تاني — الماضي محتاج قيمة.'];
                }
            }
        }

        // ⑥ وحدة مطفية فيها بيانات — تنبيه مش خطأ
        if (NavModule::ready()) {
            foreach (Database::all('SELECT slug FROM module_settings WHERE is_disabled = 1') as $r) {
                $out[] = ['warn', "وحدة «{$r['slug']}» مطفية",
                          'بياناتها موجودة زي ما هي — شغّلها من قسم الوحدات لو محتاجها.'];
            }
        }

        return $out;
    }

    /**
     * **تصدير الإعدادات** — JSON للنقل بين محلي وإنتاج.
     *
     * ⚠️⚠️ **بلا أي مفتاح سرّي**: ملف الإعدادات بيتبعت بالإيميل أو بيتحط في
     *    مجلد، ومفتاح Groq أو VAPID فيه = تسريب دائم. الاستيراد بيسيبهم زي
     *    ما هم على الوجهة.
     * ⚠️ ومفاتيح **الحالة** برّه كمان — نبضة عامل بتتنقل بين بيئتين تدّي
     *    «آخر نسخة احتياطية» كذبًا.
     */
    public static function export(): array
    {
        $out = [];
        foreach (SettingsSchema::editable() as $key => $def) {
            if ($def['type'] === SettingsSchema::T_SECRET) { continue; }
            $v = App::setting($key, null);
            if ($v === null) { continue; }
            $out[$key] = (string) $v;
        }
        return [
            'version'  => 1,
            'exported' => date('Y-m-d H:i:s'),
            'company'  => (string) App::setting('company_name', ''),
            'settings' => $out,
        ];
    }

    /**
     * **استيراد** — بيتحقّق من كل قيمة قبل ما يكتب، وبيرجّع تقريرًا.
     *
     * ⚠️ **بيمرّ على `Settings::set()`** فالدور والنطاق والتسجيل في `Audit`
     *    كلهم بيتطبّقوا زي أي تعديل يدوي. الاستيراد مش باب خلفي.
     *
     * @return array{applied:int,skipped:int,errors:array}
     */
    public static function import(array $payload): array
    {
        $rows = $payload['settings'] ?? null;
        if (!is_array($rows)) {
            return ['applied' => 0, 'skipped' => 0, 'errors' => ['الملف مش فيه إعدادات.']];
        }
        $applied = 0; $skipped = 0; $errors = [];
        foreach ($rows as $key => $value) {
            $key = (string) $key;
            $def = SettingsSchema::get($key);
            if ($def === null || $def['type'] === SettingsSchema::T_SECRET
                || in_array($def['tier'], [SettingsSchema::TIER_STATE, SettingsSchema::TIER_MANAGED], true)) {
                $skipped++; continue;
            }
            try {
                Settings::set($key, (string) $value, 'استيراد ملف إعدادات') ? $applied++ : $skipped++;
            } catch (\Throwable $e) {
                $errors[] = $def['label'] . ': ' . $e->getMessage();
            }
        }
        return ['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors];
    }
}
