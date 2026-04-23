<?php
// backend/api/auth/refresh.php
// POST /api/auth/refresh
// Body: { "refresh_token": "eyJ..." }
// Returns a new access_token (and rotates the refresh token)

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/jwt.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'Method not allowed', [], 405);

$body         = body();
$refreshToken = trim($body['refresh_token'] ?? '');

if (empty($refreshToken)) respond(false, 'refresh_token is required', [], 422);

$db = Database::getConnection();

// 1. Validate JWT signature + expiry
try {
    $payload = JWT::decode($refreshToken);
} catch (RuntimeException $e) {
    respond(false, 'Invalid or expired refresh token', [], 401);
}

if (($payload['type'] ?? '') !== 'refresh') {
    respond(false, 'Not a refresh token', [], 401);
}

$userId = (int) ($payload['sub'] ?? 0);

// 2. Check token exists in DB and is not revoked
$stmt = $db->prepare('
    SELECT id FROM refresh_tokens
    WHERE user_id = ? AND token = ? AND revoked = 0 AND expires_at > NOW()
    LIMIT 1
');
$stmt->execute([$userId, $refreshToken]);
$row = $stmt->fetch();

if (!$row) {
    respond(false, 'Refresh token revoked or not found', [], 401);
}

// 3. Fetch user role
$stmt = $db->prepare('SELECT id, role, is_active FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !$user['is_active']) {
    respond(false, 'User not found or deactivated', [], 401);
}

// 4. Revoke old refresh token (rotation)
$db->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE id = ?')
   ->execute([$row['id']]);

// 5. Issue new tokens
$tokenPayload   = ['sub' => $userId, 'role' => $user['role']];
$newAccess      = JWT::generateAccessToken($tokenPayload);
$newRefresh     = JWT::generateRefreshToken($tokenPayload);

$exp = date('Y-m-d H:i:s', time() + 2592000);
$db->prepare('INSERT INTO refresh_tokens (user_id, token, expires_at) VALUES (?,?,?)')
   ->execute([$userId, $newRefresh, $exp]);

respond(true, 'Token refreshed', [
    'access_token'  => $newAccess,
    'refresh_token' => $newRefresh,
]);