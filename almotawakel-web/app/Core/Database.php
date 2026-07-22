<?php
namespace App\Core;

use PDO;
use PDOException;

/**
 * اتصال قاعدة البيانات — PDO (نمط Singleton)
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $cfg = App::config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']
        );
        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('تعذّر الاتصال بقاعدة البيانات.', 0, $e);
        }
        return self::$pdo;
    }

    /** اتصال بدون تحديد قاعدة بيانات (للتثبيت وإنشاء القاعدة) */
    public static function serverConn(): PDO
    {
        $cfg = App::config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);
        return new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** تنفيذ استعلام مُعامَل وإرجاع كل الصفوف */
    public static function all(string $sql, array $params = []): array
    {
        return Performance::measure($sql, static function () use ($sql, $params): array {
            $st = self::conn()->prepare($sql);
            $st->execute($params);
            return $st->fetchAll();
        });
    }

    /** إرجاع صف واحد */
    public static function row(string $sql, array $params = []): ?array
    {
        return Performance::measure($sql, static function () use ($sql, $params): ?array {
            $st = self::conn()->prepare($sql);
            $st->execute($params);
            $r = $st->fetch();
            return $r === false ? null : $r;
        });
    }

    /** إرجاع قيمة واحدة */
    public static function scalar(string $sql, array $params = [])
    {
        return Performance::measure($sql, static function () use ($sql, $params) {
            $st = self::conn()->prepare($sql);
            $st->execute($params);
            return $st->fetchColumn();
        });
    }

    /** تنفيذ أمر (INSERT/UPDATE/DELETE) وإرجاع عدد الصفوف المتأثرة */
    public static function exec(string $sql, array $params = []): int
    {
        return Performance::measure($sql, static function () use ($sql, $params): int {
            $st = self::conn()->prepare($sql);
            $st->execute($params);
            return $st->rowCount();
        });
    }

    public static function lastId(): int
    {
        return (int) self::conn()->lastInsertId();
    }

    /** قطع الاتصال بين مراحل اختبار الاستعادة أو تبديل قاعدة CLI المعزولة. */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }
}
