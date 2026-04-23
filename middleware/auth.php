<?php
// backend/middleware/auth.php

require_once __DIR__ . '/../config/jwt.php';

class AuthMiddleware {

    /** Call at the top of any protected endpoint */
    public static function requireAuth(string ...$allowedRoles): array {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            self::abort(401, 'Authorization token missing');
        }

        try {
            $payload = JWT::decode($m[1]);
        } catch (RuntimeException $e) {
            self::abort(401, $e->getMessage());
        }

        if (!empty($allowedRoles) && !in_array($payload['role'] ?? '', $allowedRoles, true)) {
            self::abort(403, 'Access denied for your role');
        }

        return $payload;
    }

    private static function abort(int $code, string $message): never {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}

// ── Global response helpers ────────────────────────────────────
function respond(bool $success, string $message, array $data = [], int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_filter([
        'success' => $success,
        'message' => $message,
        'data'    => $data ?: null,
    ], fn($v) => $v !== null));
    exit;
}

function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}