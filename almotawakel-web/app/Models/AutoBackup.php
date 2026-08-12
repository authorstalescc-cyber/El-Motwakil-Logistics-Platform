<?php
namespace App\Models;

use App\Core\App;
use App\Core\Settings;
use App\Core\SqlDump;

/**
 * النسخة الاحتياطية اليومية — **بتتاخد لوحدها بلا أي ضغطة ولا أي كرون جديد**.
 *
 * ⚠️ **المشكلة اللي الوحدة دي بتحلّها:** `bin/backup_cron.sh` (v-2026-07-28)
 *    كان الحل الصح نظريًّا، بس كان معلَّقًا على **كرون شِل لازم يتظبّط بإيد
 *    المالك من لوحة هوستنجر** — وما اتظبّطش. النتيجة المقيسة يوم 2026-08-12:
 *    آخر «نسخة يومية» ناجحة كانت **من ١٥ يوم** (يوم كتابة السكربت نفسه)،
 *    والنسخ الموجودة على السيرفر كلها `pre-deploy-*` يعني بتتاخد وقت الرفع بس.
 *
 * ⚠️ **الحل: نركب على كرون قايم فعلاً.** `bin/push_worker.php` بيشتغل كل
 *    ٥ دقايق من لوحة هوستنجر **ومتحقَّق إنه شغّال** (`push_worker_last_run`
 *    بيتحدّث). فأول نبضة بعد نص الليل بتاخد النسخة — بلا لوحة تحكّم ولا
 *    ضغطة ولا كرون جديد.
 *
 * ⚠️ **وعشان كده الـdump لازم يكون PHP خالص** (`SqlDump`) — دوال تشغيل
 *    العمليات مقفولة على الاستضافة فـ`mysqldump` مستحيل يتنادى من العامل.
 *
 * ⚠️ **الفشل ما بيلمسش `backup_last_ok`.** العلامة دي معناها «آخر نجاح
 *    حقيقي»، و`Notification::staleBackup()` بتقيس عمرها. لو الفشل حدّثها كان
 *    التوقّف هيبقى صامت للأبد — وده بالظبط اللي بنحاربه.
 *
 * ⚠️ **`backup_last_try` بتخنق إعادة المحاولة على ساعة.** من غيرها فشل دايم
 *    (قرص مليان مثلاً) معناه محاولة dump كل ٥ دقايق = ٢٨٨ محاولة في اليوم.
 */
class AutoBackup
{
    /** عدد النسخ اليومية اللي بتفضل على السيرفر. */
    public const KEEP = 30;

    /** بادئة اسم الملف — نفس بادئة `bin/backup_cron.sh` فالتنظيف موحَّد. */
    public const PREFIX = 'daily-';

    /** أقل مدة بين محاولتين بعد فشل (ثانية). */
    public const RETRY_AFTER = 3600;

    public static function dir(): string
    {
        return App::basePath('database/backups');
    }

    /**
     * النسخة مستحقّة دلوقتي؟ — **دالة خالصة عشان تتفحص بلا قاعدة**.
     *
     * القاعدة: نسخة واحدة ناجحة كل **يوم تقويمي**، وبعد أي فشل بننتظر ساعة.
     */
    public static function isDue(string $lastOk, string $lastTry, ?int $now = null): bool
    {
        $now = $now ?? time();

        $okAt = strtotime(trim($lastOk));
        if ($okAt !== false && date('Y-m-d', $okAt) === date('Y-m-d', $now)) {
            return false;                       // النهاردة اتاخدت خلاص
        }
        $tryAt = strtotime(trim($lastTry));
        if ($tryAt !== false && ($now - $tryAt) < self::RETRY_AFTER) {
            return false;                       // محاولة فشلت من شوية — استنى
        }
        return true;
    }

    /**
     * بياخد النسخة **لو مستحقّة**. بيرجّع تفاصيلها، أو `null` لو مش مستحقّة
     * أو نسخة تانية شغّالة.
     *
     * ⚠️ **ما بيرميش أبدًا** — بيتنادى من عامل الكرون، وأي استثناء هنا كان
     *    هيوقف إرسال الإشعارات.
     */
    public static function runIfDue(): ?array
    {
        if (!self::isDue(
                (string) App::setting('backup_last_ok', ''),
                (string) App::setting('backup_last_try', ''))) {
            return null;
        }

        // ⚠️ قفل مستقل عن قفل العامل — `bin/backup_cron.sh` لو اتظبّط يومًا
        //    ما بيشتغل برّه العامل، فنسختين ممكن يتلاقوا على نفس الملف.
        $lockPath = App::basePath('storage/auto_backup.lock');
        @mkdir(dirname($lockPath), 0775, true);
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) { return null; }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }

        try {
            // إعادة الفحص جوّه القفل — نسخة سبقتنا بجزء من الثانية ممكن تكون خلّصت.
            if (!self::isDue(
                    (string) App::setting('backup_last_ok', ''),
                    (string) App::setting('backup_last_try', ''))) {
                return null;
            }
            Settings::setState('backup_last_try', date('Y-m-d H:i:s'));
            $res = self::run();
            Settings::setState('backup_last_ok', date('Y-m-d H:i:s'));
            Settings::setState('backup_last_msg', 'تمام — ' . self::humanSize($res['bytes']));
            return $res;
        } catch (\Throwable $e) {
            error_log('auto backup failed: ' . $e->getMessage());
            // `backup_last_ok` ما بتتلمسش — الفشل لازم يفضل باينًا في عمر آخر نجاح.
            try { Settings::setState('backup_last_msg', 'فشلت: ' . $e->getMessage()); }
            catch (\Throwable) { /* السجل مش أهم من إن العامل يكمّل */ }
            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * بياخد نسخة **فورًا** بلا أي شرط استحقاق، وبيتأكد إنها كملت.
     *
     * @return array{path:string,file:string,bytes:int,tables:int,rows:int}
     */
    public static function run(?string $dir = null): array
    {
        $dir = $dir ?? self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('تعذّر تجهيز مجلد النسخ الاحتياطية.');
        }
        @chmod($dir, 0700);

        $path = rtrim($dir, '/') . '/' . self::PREFIX . date('Ymd-His') . '.sql.gz';
        $res  = SqlDump::toFile($path);

        // ⚠️ الحجم مش دليل. الدليل الوحيد إن الـdump كمل هو آخر سطر — وملف
        //    gzip فاضي بيعدّي من `gzip -t` بنجاح تام.
        if (!str_contains(self::tail($path), SqlDump::DONE_MARKER)) {
            @unlink($path);
            throw new \RuntimeException('النسخة ما كملتش — الملف اتمسح.');
        }
        @chmod($path, 0600);

        self::prune($dir);

        return $res + ['file' => basename($path)];
    }

    /** آخر بايتات من ملف مضغوط — بلا ما نحمّله كله في الذاكرة. */
    public static function tail(string $path, int $bytes = 400): string
    {
        $handle = @gzopen($path, 'rb');
        if ($handle === false) { return ''; }
        $tail = '';
        while (!gzeof($handle)) {
            $tail = substr($tail . (string) gzread($handle, 65536), -$bytes);
        }
        gzclose($handle);
        return $tail;
    }

    /**
     * الأسماء اللي المفروض تتمسح — **دالة خالصة** (الترتيب بالاسم لأن الاسم
     * فيه الختم الزمني، فمفيش اعتماد على تاريخ تعديل الملف).
     */
    public static function pruneList(array $names, int $keep = self::KEEP): array
    {
        sort($names, SORT_STRING);
        $extra = count($names) - max(0, $keep);
        return $extra > 0 ? array_slice($names, 0, $extra) : [];
    }

    /** بيسيب آخر `KEEP` نسخة يومية بس. النسخ التانية (`pre-deploy-*`) ما بتتلمسش. */
    public static function prune(?string $dir = null): int
    {
        $dir   = $dir ?? self::dir();
        $files = glob(rtrim($dir, '/') . '/' . self::PREFIX . '*.sql.gz') ?: [];
        $names = array_map('basename', $files);
        $gone  = 0;
        foreach (self::pruneList($names) as $name) {
            if (@unlink(rtrim($dir, '/') . '/' . $name)) { $gone++; }
        }
        return $gone;
    }

    /** آخر النسخ اليومية للعرض. @return list<array{file:string,bytes:int,at:string}> */
    public static function recent(int $limit = 5): array
    {
        $files = glob(rtrim(self::dir(), '/') . '/' . self::PREFIX . '*.sql.gz') ?: [];
        rsort($files, SORT_STRING);
        $out = [];
        foreach (array_slice($files, 0, $limit) as $path) {
            $out[] = [
                'file'  => basename($path),
                'bytes' => (int) filesize($path),
                'at'    => date('Y-m-d H:i', (int) filemtime($path)),
            ];
        }
        return $out;
    }

    private static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1) . ' م.ب'
            : number_format($bytes / 1024, 0) . ' ك.ب';
    }
}
