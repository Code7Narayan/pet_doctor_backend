<?php
// backend/config/database.php

class Database {
    private static ?PDO $connection = null;

    private const HOST = 'localhost';
    private const DB   = 'vetcare_db';
    private const USER = 'vetcare_user';
    private const PASS = 'StrongPass@2024!';  // change in production
    private const CHARSET = 'utf8mb4';

    private function __construct() {}

    public static function getConnection(): PDO {
        if (self::$connection === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                self::HOST, self::DB, self::CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+05:30'",  // IST
            ];
            self::$connection = new PDO($dsn, self::USER, self::PASS, $options);
        }
        return self::$connection;
    }
}