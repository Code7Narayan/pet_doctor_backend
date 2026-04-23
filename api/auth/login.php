<?php
// backend/api/auth/login.php
// POST /api/auth/login

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/jwt.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'Method not allowed', [], 405);

$body  = body();
$phone = trim($body['phone'] ?? '');
$pass  = $body['password'] ?? '';

if (!$phone || !$pass) respond(false, 'Phone and password required', [], 422);

$db   = Database::getConnection();
$stmt = $db->prepare('
    SELECT u.id, u.name, u.phone, u.role, u.password, u.profile_pic,
           u.lat, u.lng, u.language, u.is_active,
           dp.specialization, dp.is_available, dp.rating, dp.consultation_fee
    FROM users u
    LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
    WHERE u.phone = ?
    LIMIT 1
');
$stmt->execute([$phone]);
$user = $stmt->fetch();

if (!$user || !password_verify($pass, $user['password'])) {
    respond(false, 'Invalid phone or password', [], 401);
}

if (!$user['is_active']) {
    respond(false, 'Account deactivated. Contact support.', [], 403);
}

// Update FCM token if provided
if (!empty($body['fcm_token'])) {
    $db->prepare('UPDATE users SET fcm_token = ? WHERE id = ?')
       ->execute([$body['fcm_token'], $user['id']]);
}

$tokenPayload = ['sub' => (int)$user['id'], 'role' => $user['role']];
$access  = JWT::generateAccessToken($tokenPayload);
$refresh = JWT::generateRefreshToken($tokenPayload);

$exp = date('Y-m-d H:i:s', time() + 2592000);
$db->prepare('INSERT INTO refresh_tokens (user_id, token, expires_at) VALUES (?,?,?)')
   ->execute([$user['id'], $refresh, $exp]);

unset($user['password'], $user['is_active']);

respond(true, 'Login successful', [
    'user'          => $user,
    'access_token'  => $access,
    'refresh_token' => $refresh,
]);