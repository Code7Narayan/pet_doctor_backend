<?php
// backend/config/jwt.php
// Minimal JWT without external libraries (HMAC-SHA256)

class JWT {
    private const SECRET      = 'VetCare_JWT_Secret_Key_2024_@Change_Me';
    private const ACCESS_TTL  = 3600;        // 1 hour
    private const REFRESH_TTL = 2592000;     // 30 days
    private const ALGO        = 'sha256';

    // ── Encode ─────────────────────────────────────────────────
    public static function generateAccessToken(array $payload): string {
        return self::encode(array_merge($payload, [
            'exp' => time() + self::ACCESS_TTL,
            'type' => 'access',
        ]));
    }

    public static function generateRefreshToken(array $payload): string {
        return self::encode(array_merge($payload, [
            'exp' => time() + self::REFRESH_TTL,
            'type' => 'refresh',
        ]));
    }

    private static function encode(array $payload): string {
        $header  = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64url(json_encode($payload));
        $sig     = self::sign("$header.$payload");
        return "$header.$payload.$sig";
    }

    // ── Decode / Validate ───────────────────────────────────────
    public static function decode(string $token): array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new RuntimeException('Invalid token structure');

        [$header, $payload, $sig] = $parts;

        $expected = self::sign("$header.$payload");
        if (!hash_equals($expected, $sig)) throw new RuntimeException('Invalid signature');

        $data = json_decode(self::base64urlDecode($payload), true);
        if (!$data) throw new RuntimeException('Invalid payload');
        if (($data['exp'] ?? 0) < time()) throw new RuntimeException('Token expired');

        return $data;
    }

    // ── Helpers ─────────────────────────────────────────────────
    private static function sign(string $input): string {
        return self::base64url(hash_hmac(self::ALGO, $input, self::SECRET, true));
    }

    private static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}