<?php
// backend/config/database.php
// ⚠️  CRITICAL FIX: DB name, user, and password must match your hosting panel.
//     Your SQL dump shows: database = u934999676_pet_doc
//     Previous file had:  database = vetcare_db  ← this was the root cause of ALL "Network Error" failures

class Database {
    private static ?PDO $connection = null;

    // ── UPDATE THESE TO MATCH YOUR HOSTING PANEL ──────────────
    private const HOST    = 'localhost';
    private const DB      = 'u934999676_pet_doc';   // ← FIXED (was 'vetcare_db')
    private const USER    = 'u934999676_narayans';  // ← match your DB user
    private const PASS    = 'RAMVP@5ns';   // ← replace with real password
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
            try {
                self::$connection = new PDO($dsn, self::USER, self::PASS, $options);
            } catch (PDOException $e) {
                // Return a proper JSON error so Android sees it, not a PHP error page
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Database connection failed. Check server config.',
                    // In production remove the detail below:
                    'detail'  => $e->getMessage(),
                ]);
                exit;
            }
        }
        return self::$connection;
    }

    /** Call this to reset connection (useful after long-lived processes) */
    public static function reset(): void {
        self::$connection = null;
    }
}