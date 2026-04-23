<?php
// backend/api/doctors/profile.php
// GET  /api/doctors/{id}         → public profile (any auth)
// PUT  /api/doctors/profile      → doctor updates own profile

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,PUT');
header('Access-Control-Allow-Headers: Authorization,Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$auth   = AuthMiddleware::requireAuth('owner', 'doctor');
$userId = $auth['sub'];
$role   = $auth['role'];
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Extract optional doctor ID from URL: /api/doctors/5
$uriParts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$doctorId = (int) end($uriParts);
if (!is_numeric(end($uriParts))) $doctorId = 0;

// ── GET: Public doctor profile ─────────────────────────────────
if ($method === 'GET' && $doctorId) {
    $stmt = $db->prepare('
        SELECT u.id, u.name, u.phone, u.profile_pic, u.lat, u.lng,
               dp.specialization, dp.license_number, dp.experience_yrs,
               dp.consultation_fee, dp.bio, dp.clinic_name, dp.clinic_address,
               dp.is_available, dp.rating, dp.total_ratings,
               dll.updated_at AS location_updated_at
        FROM users u
        INNER JOIN doctor_profiles dp ON dp.user_id = u.id
        LEFT  JOIN doctor_location_latest dll ON dll.doctor_id = u.id
        WHERE u.id = ? AND u.role = "doctor" AND u.is_active = 1
        LIMIT 1
    ');
    $stmt->execute([$doctorId]);
    $profile = $stmt->fetch();

    if (!$profile) respond(false, 'Doctor not found', [], 404);

    // Completed treatment count (public stat)
    $stmt = $db->prepare('
        SELECT COUNT(*) AS total_treated FROM treatments
        WHERE doctor_id = ? AND status = "completed"
    ');
    $stmt->execute([$doctorId]);
    $profile['total_treated'] = (int) $stmt->fetchColumn();

    respond(true, 'Doctor profile', ['doctor' => $profile]);
}

// ── PUT: Doctor updates own profile ───────────────────────────
if ($method === 'PUT') {
    if ($role !== 'doctor') respond(false, 'Only doctors can update doctor profiles', [], 403);

    $body = body();

    // Update users table (name, lat/lng, profile_pic)
    $db->prepare('
        UPDATE users
        SET name        = COALESCE(?, name),
            profile_pic = COALESCE(?, profile_pic)
        WHERE id = ?
    ')->execute([
        $body['name']        ?? null,
        $body['profile_pic'] ?? null,
        $userId,
    ]);

    // Update doctor_profiles
    $db->prepare('
        UPDATE doctor_profiles
        SET specialization   = COALESCE(?, specialization),
            consultation_fee = COALESCE(?, consultation_fee),
            bio              = COALESCE(?, bio),
            clinic_name      = COALESCE(?, clinic_name),
            clinic_address   = COALESCE(?, clinic_address),
            is_available     = COALESCE(?, is_available)
        WHERE user_id = ?
    ')->execute([
        $body['specialization']   ?? null,
        isset($body['consultation_fee']) ? (float)$body['consultation_fee'] : null,
        $body['bio']              ?? null,
        $body['clinic_name']      ?? null,
        $body['clinic_address']   ?? null,
        isset($body['is_available']) ? (int)(bool)$body['is_available'] : null,
        $userId,
    ]);

    respond(true, 'Profile updated');
}

respond(false, 'Not found', [], 404);