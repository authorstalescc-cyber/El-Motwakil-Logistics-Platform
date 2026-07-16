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
            http_response_code(500);
            die('<div style="font-family:sans-serif;direction:rtl;padding:40px;text-align:center">'
                . '<h2>تعذّر الاتصال بقاعدة البيانات</h2>'
                . '<p>تأكد أن خادم MySQL يعمل وأن بيانات الاتصال في <code>config/config.php</code> صحيحة.</p>'
                . '<p style="color:#9b2c2c">' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<p>شغّل التثبيت أولاً: <code>php bin/install.php</code></p></div>');
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
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** إرجاع صف واحد */
    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    /** إرجاع قيمة واحدة */
    public static function scalar(string $sql, array $params = [])
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    /** تنفيذ أمر (INSERT/UPDATE/DELETE) وإرجاع عدد الصفوف المتأثرة */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::conn()->lastInsertId();
    }
}
