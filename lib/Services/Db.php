<?php
namespace Ork;

use PDO;

class Db
{
    private static $db;

    /**
     * Undocumented function
     *
     * @return PDO
     */
    public static function getDb(): PDO
    {
        if (!self::$db) {
            self::$db = new PDO(getenv('DB_DSN'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
            self::$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$db;
    }
}
