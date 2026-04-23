<?php
// backend/api/users/profile.php
// GET  /api/users/profile          → own profile
// PUT  /api/users/profile          → update name, profile_pic, fcm_token, language

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,PUT');
header('Access-Control-Allow-Headers: Authorization,Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$auth   = AuthMiddleware::requireAuth('owner', 'doctor');
$userId = $auth['sub'];
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET own profile ────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $db->prepare('
        SELECT u.id, u.name, u.phone, u.email, u.role, u.profile_pic,
               u.lat, u.lng, u.language, u.created_at,
               dp.specialization, dp.consultation_fee, dp.rating,
               dp.total_ratings, dp.is_available, dp.clinic_name,
               dp.experience_yrs, dp.bio
        FROM users u
        LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
        WHERE u.id = ? LIMIT 1
    ');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();

    if (!$profile) respond(false, 'User not found', [], 404);

    // Animal count for owners
    if ($profile['role'] === 'owner') {
        $cnt = $db->prepare('SELECT COUNT(*) FROM animals WHERE owner_id=? AND is_active=1');
        $cnt->execute([$userId]);
        $profile['animal_count'] = (int) $cnt->fetchColumn();
    }

    respond(true, 'Profile', ['user' => $profile]);
}

// ── PUT: Update own profile ────────────────────────────────────
if ($method === 'PUT') {
    $body = body();

    $updateFields = [];
    $params       = [];

    // Fields any user can update
    if (isset($body['name'])) {
        $updateFields[] = 'name = ?';
        $params[] = trim($body['name']);
    }
    if (isset($body['language']) && in_array($body['language'], ['en','mr'], true)) {
        $updateFields[] = 'language = ?';
        $params[] = $body['language'];
    }
    if (isset($body['fcm_token'])) {
        $updateFields[] = 'fcm_token = ?';
        $params[] = trim($body['fcm_token']);
    }
    if (isset($body['profile_pic'])) {
        $updateFields[] = 'profile_pic = ?';
        $params[] = trim($body['profile_pic']);
    }
    if (isset($body['lat'], $body['lng'])) {
        $updateFields[] = 'lat = ?';
        $updateFields[] = 'lng = ?';
        $params[] = (float) $body['lat'];
        $params[] = (float) $body['lng'];
    }

    if (!empty($updateFields)) {
        $params[] = $userId;
        $db->prepare('UPDATE users SET ' . implode(', ', $updateFields) . ' WHERE id = ?')
           ->execute($params);
    }

    // Password change
    if (!empty($body['password']) && !empty($body['current_password'])) {
        $stmt = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($body['current_password'], $row['password'])) {
            respond(false, 'Current password is incorrect', [], 403);
        }
        if (strlen($body['password']) < 6) {
            respond(false, 'New password must be at least 6 characters', [], 422);
        }
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')
           ->execute([password_hash($body['password'], PASSWORD_BCRYPT), $userId]);
    }

    respond(true, 'Profile updated');
}

respond(false, 'Method not allowed', [], 405);