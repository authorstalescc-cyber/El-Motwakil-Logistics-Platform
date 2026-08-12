<?php
namespace App\Core;

/**
 * نسخة SQL كاملة من القاعدة — **بلا أي دالة تشغيل عمليات**.
 *
 * ⚠️ **ليه الملف ده موجود أصلاً؟** استضافة الإنتاج بتقفل
 *    `exec`/`shell_exec`/`proc_open`/`popen`/`passthru`، فـ`mysqldump`
 *    **مستحيل يتنادى من PHP هناك** حتى والبرنامج نفسه موجود في `/usr/bin`.
 *    ونتيجة كده كانت: النسخة اليومية معلّقة على كرون شِل لازم المالك يظبّطه
 *    بإيده من لوحة الاستضافة — و**ما اتظبّطش**، فالنسخة اليومية ما اشتغلتش
 *    ولا مرة (آخر علامة نجاح كانت يوم كتابة السكربت). الملف ده بيشيل الشرط
 *    ده خالص: القراءة كلها بـPDO والكتابة بـ`gzopen`، فأي كرون PHP قايم
 *    (`bin/push_worker.php`) يقدر ياخد النسخة.
 *
 * ⚠️ **الأعمدة المولَّدة (`GENERATED`) بتتشال من قايمة أعمدة الـINSERT.**
 *    `bawalis.live_bill_number` عمود مولَّد، وأي `INSERT` بيذكره بيفشل
 *    الاستعادة كلها بـ«The value specified for generated column». mysqldump
 *    بيعمل ده لوحده — وإحنا لازم نعمله بإيدنا.
 *
 * ⚠️ **آخر سطر `-- Dump completed on …` هو الدليل الوحيد إن النسخة كملت.**
 *    ملف gzip فاضي (٢٠ بايت) بيعدّي من `gzip -t` بنجاح، ونسخة تالفة أسوأ من
 *    نسخة مش موجودة لأنها بتدّي طمأنينة كاذبة. نفس العلامة اللي بيكتبها
 *    mysqldump بالحرف، فـ`bin/backup_cron.sh` وأي فحص قديم بيفضلوا صالحين.
 *
 * ⚠️ **القراءة صفًّا صفًّا (`fetch`) مش `fetchAll`، والكتابة على دفعات.**
 *    القاعدة كلها ~١٥ م.ب وأكبر جدول ٤ م.ب، فالقراءة المخزَّنة آمنة —
 *    بس تجميع الـSQL كله في نص واحد قبل الكتابة كان هيضاعفه في الذاكرة بلا داعٍ.
 *    (اتجنّبنا `MYSQL_ATTR_USE_BUFFERED_QUERY` عن قصد: الثابت **متوقّف
 *    من PHP 8.4**، وكان هيدّي إنذار مكتوم في اللوج بعد أي ترقية.)
 */
class SqlDump
{
    /** العلامة اللي بتثبت إن الـdump كمل — بالحرف زي mysqldump. */
    public const DONE_MARKER = 'Dump completed on';

    /** بايتات بتتجمّع في الذاكرة قبل الكتابة (توازن بين الذاكرة وعدد الكتابات). */
    private const FLUSH_AT = 262144;

    /** أقصى طول لأمر INSERT الواحد — نفس منطق `--max-allowed-packet` بتاع mysqldump. */
    private const MAX_INSERT = 4194304;

    /**
     * بيكتب النسخة في `$path`. لو الاسم منتهي بـ`.gz` بيتضغط أثناء الكتابة.
     *
     * @return array{path:string,bytes:int,tables:int,rows:int}
     * @throws \RuntimeException لو حصل أي فشل — والملف الناقص بيتمسح.
     */
    public static function toFile(string $path): array
    {
        $gzip   = str_ends_with(strtolower($path), '.gz');
        $handle = $gzip ? @gzopen($path, 'wb6') : @fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('تعذّر فتح ملف النسخة الاحتياطية للكتابة.');
        }

        $buffer = '';
        $write = static function (string $sql) use (&$buffer, $handle, $gzip): void {
            $buffer .= $sql;
            if (strlen($buffer) < self::FLUSH_AT) { return; }
            $ok = $gzip ? gzwrite($handle, $buffer) : fwrite($handle, $buffer);
            if ($ok === false) { throw new \RuntimeException('فشلت الكتابة في ملف النسخة الاحتياطية.'); }
            $buffer = '';
        };

        $rows = 0;
        $tables = [];
        /* ⚠️⚠️ **أخطر سطر في الملف — وصاده تمرين الاستعادة.**
              أعمدة `TIMESTAMP` متخزّنة UTC وبتتحوّل لتوقيت **جلسة القراءة**.
              الملف بيقول `SET TIME_ZONE='+00:00'` عند الاستعادة، فلو القراءة
              حصلت بتوقيت القاهرة تبقى القيم اتقرت +٣ واترجعت كأنها UTC =
              **كل تواريخ النظام بتزحف ٣ ساعات في النسخة**. مقيس فعلاً:
              `14:58:40` رجعت `17:58:40`. القراءة والاستعادة لازم يبقوا على
              نفس التوقيت — ومحلي القاهرة والإنتاج UTC، فالتثبيت على UTC هو
              الوحيد اللي بينقل نسخة بين الاتنين بأمان.
           ⚠️ والتوقيت بيرجع لأصله في `finally` — الاتصال مشترك مع باقي
              النظام، وأي `NOW()` بعد كده كان هيبقى غلط. */
        $pdo = Database::conn();
        $tz  = (string) Database::scalar('SELECT @@session.time_zone');
        $pdo->exec("SET time_zone = '+00:00'");
        try {
            $tables = self::tables();
            $write(self::header());
            // البِنى كلها الأول ثم البيانات — عشان أي مفتاح أجنبي يلاقي جدوله
            // معرَّفًا وقت الاستعادة حتى لو الحارس اتشال بعدين.
            foreach ($tables as $table) {
                $write(self::structure($table));
            }
            foreach ($tables as $table) {
                $rows += self::data($table, $write);
            }
        } catch (\Throwable $e) {
            $gzip ? gzclose($handle) : fclose($handle);
            @unlink($path);
            throw new \RuntimeException('فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage(), 0, $e);
        } finally {
            $pdo->exec('SET time_zone = ' . $pdo->quote($tz !== '' ? $tz : 'SYSTEM'));
        }

        $buffer .= self::footer();
        $ok = $gzip ? gzwrite($handle, $buffer) : fwrite($handle, $buffer);
        $closed = $gzip ? gzclose($handle) : fclose($handle);
        if ($ok === false || $closed === false) {
            @unlink($path);
            throw new \RuntimeException('فشل إقفال ملف النسخة الاحتياطية.');
        }

        return [
            'path'   => $path,
            'bytes'  => (int) filesize($path),
            'tables' => count($tables),
            'rows'   => $rows,
        ];
    }

    /** أسماء الجداول الحقيقية (بلا views — مفيش views في النظام، والقايمة بترتيب ثابت). */
    public static function tables(): array
    {
        $rows = Database::all(
            "SELECT table_name AS t FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
              ORDER BY table_name");
        return array_map(static fn(array $r): string => (string) $r['t'], $rows);
    }

    private static function header(): string
    {
        $db = (string) Database::scalar('SELECT DATABASE()');
        $ver = (string) Database::conn()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        return "-- نسخة احتياطية لنظام المتوكل (بلا mysqldump)\n"
             . "-- القاعدة: {$db}\n-- الخادم: {$ver}\n"
             . '-- التاريخ: ' . date('Y-m-d H:i:s') . "\n\n"
             . "/*!40101 SET NAMES utf8mb4 */;\n"
             . "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
             . "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n"
             . "/*!40103 SET TIME_ZONE='+00:00' */;\n"
             . "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS;\n"
             . "SET FOREIGN_KEY_CHECKS=0;\n"
             . "SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS;\n"
             . "SET UNIQUE_CHECKS=0;\n\n";
    }

    private static function footer(): string
    {
        return "\nSET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n"
             . "SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;\n"
             . "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n"
             . "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n\n"
             . '-- ' . self::DONE_MARKER . ' ' . date('Y-m-d H:i:s') . "\n";
    }

    private static function structure(string $table): string
    {
        $row = Database::row('SHOW CREATE TABLE ' . self::quoteName($table));
        // المفتاح اسمه «Create Table» — بناخد آخر قيمة عشان اختلاف حالة الحروف.
        $create = $row === null ? '' : (string) (array_values($row)[1] ?? '');
        if ($create === '') {
            throw new \RuntimeException("تعذّر قراءة بنية الجدول {$table}.");
        }
        return "DROP TABLE IF EXISTS " . self::quoteName($table) . ";\n{$create};\n\n";
    }

    /**
     * بيسرّب صفوف جدول واحد كـINSERT متعدّد القيم.
     * @param callable(string):void $write
     */
    private static function data(string $table, callable $write): int
    {
        $columns = self::insertColumns($table);
        if ($columns === []) { return 0; }          // جدول كله أعمدة مولَّدة (نظريًّا)

        $pdo  = Database::conn();
        $list = implode(', ', array_map([self::class, 'quoteName'], $columns));
        $head = 'INSERT INTO ' . self::quoteName($table) . " ({$list}) VALUES\n";

        $stmt = $pdo->query('SELECT ' . $list . ' FROM ' . self::quoteName($table));
        if ($stmt === false) { throw new \RuntimeException("تعذّر قراءة بيانات {$table}."); }

        $count = 0; $chunk = ''; $started = false;
        while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
            }
            $tuple = '(' . implode(',', $values) . ')';
            if (!$started) { $chunk = $head . $tuple; $started = true; }
            else           { $chunk .= ",\n" . $tuple; }
            $count++;
            if (strlen($chunk) >= self::MAX_INSERT) {
                $write($chunk . ";\n"); $chunk = ''; $started = false;
            }
        }
        $stmt->closeCursor();
        if ($started) { $write($chunk . ";\n"); }
        if ($count > 0) { $write("\n"); }
        return $count;
    }

    /**
     * الشرط اللي بيميّز **العمود المولَّد** عن غيره.
     *
     * ⚠️⚠️ **مصيدة كلّفت بيانات لو عدّت:** `extra LIKE '%GENERATED%'` **غلط**.
     *    في MySQL 8 أي عمود عنده `DEFAULT CURRENT_TIMESTAMP` بياخد
     *    `extra = 'DEFAULT_GENERATED'` — وده **عمود عادي تمامًا**. الشرط
     *    الفضفاض كان بيشيل كل أعمدة `created_at`/`updated_at` من قايمة
     *    الـINSERT، فالنسخة تستعاد «بنجاح» و**كل تواريخ الإنشاء تتبدّل بوقت
     *    الاستعادة** — فقد بيانات صامت بلا أي رسالة خطأ. (اتصاد بالفاحص
     *    قبل ما يشوف الإنتاج.) القيم الحقيقية `VIRTUAL GENERATED` و
     *    `STORED GENERATED` بس — والاتنين بنفس التسمية في MySQL وMariaDB.
     */
    private const GENERATED_WHERE =
        "(extra LIKE '%VIRTUAL GENERATED%' OR extra LIKE '%STORED GENERATED%')";

    /**
     * الأعمدة المولَّدة في القاعدة — **مصدر واحد** للـdump وللفاحص.
     * @return list<array{t:string,c:string}>
     */
    public static function generatedColumns(): array
    {
        return Database::all(
            "SELECT table_name AS t, column_name AS c FROM information_schema.columns
              WHERE table_schema = DATABASE() AND " . self::GENERATED_WHERE . "
              ORDER BY table_name, ordinal_position");
    }

    /**
     * أعمدة الجدول **بلا الأعمدة المولَّدة**.
     *
     * ⚠️ ذكر عمود مولَّد في `INSERT` بيفشل الاستعادة كلها — والخطأ بيظهر
     *    وقت الاستعادة بس، يعني يوم ما تكون محتاج النسخة فعلاً.
     */
    public static function insertColumns(string $table): array
    {
        $rows = Database::all(
            "SELECT column_name AS c FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?
                AND NOT " . self::GENERATED_WHERE . "
              ORDER BY ordinal_position", [$table]);
        return array_map(static fn(array $r): string => (string) $r['c'], $rows);
    }

    /** تهريب اسم جدول/عمود — الشرطة المائلة الخلفية جوّه الاسم بتتضاعف. */
    private static function quoteName(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
