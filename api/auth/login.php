<?php
// backend/api/auth/login.php
// POST /api/auth/login
// Body: { phone, password, role?, fcm_token? }
// role is optional – if omitted, first match is returned.
// If same phone exists for both owner and doctor, `role` must be supplied.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/jwt.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST,OPTIONS');
header('Access-Control-Allow-Headers: Authorization,Content-Type,Accept');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'Method not allowed', [], 405);

$body  = body();
$phone = trim($body['phone'] ?? '');
$pass  = $body['password'] ?? '';
$role  = $body['role']     ?? '';   // 'owner' | 'doctor' | '' (auto-detect)

if (!$phone || !$pass) respond(false, 'Phone and password are required', [], 422);

$db = Database::getConnection();

// Build query based on whether role is supplied
if ($role && in_array($role, ['owner', 'doctor'], true)) {
    $stmt = $db->prepare('
        SELECT u.id, u.name, u.phone, u.role, u.password, u.profile_pic,
               u.lat, u.lng, u.language, u.is_active,
               dp.specialization, dp.is_available, dp.rating, dp.consultation_fee
        FROM users u
        LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
        WHERE u.phone = ? AND u.role = ?
        LIMIT 1
    ');
    $stmt->execute([$phone, $role]);
} else {
    // Auto-detect: return first match (prefer owner if ambiguous)
    $stmt = $db->prepare('
        SELECT u.id, u.name, u.phone, u.role, u.password, u.profile_pic,
               u.lat, u.lng, u.language, u.is_active,
               dp.specialization, dp.is_available, dp.rating, dp.consultation_fee
        FROM users u
        LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
        WHERE u.phone = ?
        ORDER BY FIELD(u.role, "owner", "doctor")
        LIMIT 1
    ');
    $stmt->execute([$phone]);
}

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

// Save location if provided at login
if (!empty($body['lat']) && !empty($body['lng'])) {
    $db->prepare('UPDATE users SET lat = ?, lng = ? WHERE id = ?')
       ->execute([(float)$body['lat'], (float)$body['lng'], $user['id']]);
    $user['lat'] = (float)$body['lat'];
    $user['lng'] = (float)$body['lng'];
}

$tokenPayload = ['sub' => (int)$user['id'], 'role' => $user['role']];
$access  = JWT::generateAccessToken($tokenPayload);
$refresh = JWT::generateRefreshToken($tokenPayload);

$exp = date('Y-m-d H:i:s', time() + 2592000);
$db->prepare('INSERT INTO refresh_tokens (user_id, token, expires_at) VALUES (?,?,?)')
   ->execute([$user['id'], $refresh, $exp]);

unset($user['password'], $user['is_active']);

// Ensure numeric types
$user['id']  = (int)$user['id'];
$user['lat'] = $user['lat'] ? (float)$user['lat'] : null;
$user['lng'] = $user['lng'] ? (float)$user['lng'] : null;

respond(true, 'Login successful', [
    'user'          => $user,
    'access_token'  => $access,
    'refresh_token' => $refresh,
]);