<?php


class DB
{
    protected static $con1;
    protected static $pdo1;
    protected static $mysql_creds1;

    protected static $con2;
    protected static $pdo2;
    protected static $mysql_creds2;


    public static function initialize(
        array $credentials1,
        array $credentials2,
        $encoding = 'utf8mb4'
    )
    {

        $dsn1 = 'mysql:host=' . $credentials1['host'] . ';dbname=' . $credentials1['database'];
        if (!empty($credentials1['port'])) {
            $dsn1 .= ';port=' . $credentials1['port'];
        }

        $options = [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $encoding];
        $pdo1 = new PDO($dsn1, $credentials1['user'], $credentials1['pass'], $options);
        $pdo1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

        self::$pdo1 = $pdo1;
        self::$mysql_creds1 = $credentials1;


        $dsn2 = 'mysql:host=' . $credentials2['host'] . ';dbname=' . $credentials2['database'];
        if (!empty($credentials2['port'])) {
            $dsn2 .= ';port=' . $credentials2['port'];
        }

        $options = [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $encoding];
        $pdo2 = new PDO($dsn2, $credentials2['user'], $credentials2['pass'], $options);
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

        self::$pdo2 = $pdo2;
        self::$mysql_creds2 = $credentials2;

        return [self::$pdo1, self::$pdo2];
    }
}