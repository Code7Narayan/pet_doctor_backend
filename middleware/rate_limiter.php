<?php
// backend/middleware/rate_limiter.php
//
// Simple IP-based rate limiter using a MySQL table.
// Protects high-risk endpoints (login, register) from brute-force attacks.
//
// Usage: RateLimiter::check('login', 5, 60);   // max 5 attempts per 60 seconds

require_once __DIR__ . '/../config/database.php';

class RateLimiter {

    /**
     * @param string $endpoint   Logical identifier: 'login', 'register', 'tracking'
     * @param int    $maxHits    Maximum allowed requests in the window
     * @param int    $windowSecs Time window in seconds
     *
     * Calls respond(429,...) and exits if limit exceeded.
     */
    public static function check(string $endpoint, int $maxHits = 10, int $windowSecs = 60): void {
        $ip  = self::clientIp();
        $key = $endpoint . ':' . $ip;
        $db  = Database::getConnection();

        // Purge stale records (keep table lean)
        $db->prepare('DELETE FROM rate_limit_log WHERE expires_at < NOW()')
           ->execute();

        // Count existing hits in window
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM rate_limit_log
            WHERE `key` = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ');
        $stmt->execute([$key, $windowSecs]);
        $hits = (int) $stmt->fetchColumn();

        if ($hits >= $maxHits) {
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . $windowSecs);
            echo json_encode([
                'success' => false,
                'message' => 'Too many requests. Please wait and try again.',
                'retry_after_seconds' => $windowSecs,
            ]);
            exit;
        }

        // Record this hit
        $exp = date('Y-m-d H:i:s', time() + $windowSecs);
        $db->prepare('INSERT INTO rate_limit_log (`key`, expires_at) VALUES (?,?)')
           ->execute([$key, $exp]);
    }

    private static function clientIp(): string {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                return explode(',', $_SERVER[$h])[0];
            }
        }
        return '0.0.0.0';
    }
}


// ── SQL for the rate_limit_log table (add to schema.sql) ──────
// CREATE TABLE `rate_limit_log` (
//   `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
//   `key`        VARCHAR(120)     NOT NULL,
//   `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   `expires_at` DATETIME         NOT NULL,
//   PRIMARY KEY (`id`),
//   KEY `idx_key_created` (`key`, `created_at`),
//   KEY `idx_expires`     (`expires_at`)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; 