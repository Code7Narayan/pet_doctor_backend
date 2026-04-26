<?php
// backend/api/doctors/nearby.php
// GET /api/doctors/nearby?lat=&lng=&radius=&page=&limit=
// Returns available doctors within radius_km, sorted by distance
// FIX: This file was empty in the original project — that caused all "Find Nearby Vets" failures

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,OPTIONS');
header('Access-Control-Allow-Headers: Authorization,Content-Type,Accept');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$auth   = AuthMiddleware::requireAuth('owner', 'doctor');
$db     = Database::getConnection();

// ── Parameters ──────────────────────────────────────────────
$lat      = (float) ($_GET['lat']    ?? 0);
$lng      = (float) ($_GET['lng']    ?? 0);
$radius   = max(1, min(200, (int)($_GET['radius'] ?? 50)));
$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = min(50, (int)($_GET['limit'] ?? 20));
$offset   = ($page - 1) * $limit;

if (!$lat || !$lng) {
    respond(false, 'lat and lng are required', [], 422);
}

// ── Use stored procedure if available, otherwise inline Haversine ──
try {
    $stmt = $db->prepare('CALL find_nearby_doctors(?, ?, ?, ?, ?)');
    $stmt->execute([$lat, $lng, $radius, $limit, $offset]);
    $doctors = $stmt->fetchAll();
    $stmt->closeCursor();

    // Count (run the same Haversine without LIMIT for total)
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT u.id,
                   (6371 * ACOS(
                       COS(RADIANS(?)) * COS(RADIANS(u.lat)) *
                       COS(RADIANS(u.lng) - RADIANS(?)) +
                       SIN(RADIANS(?)) * SIN(RADIANS(u.lat))
                   )) AS distance_km
            FROM users u
            INNER JOIN doctor_profiles dp ON dp.user_id = u.id
            LEFT JOIN doctor_location_latest dll ON dll.doctor_id = u.id
            WHERE u.role = 'doctor'
              AND u.is_active = 1
              AND dp.is_available = 1
              AND u.lat IS NOT NULL
            HAVING distance_km <= ?
        ) AS sub
    ");
    $countStmt->execute([$lat, $lng, $lat, $radius]);
    $total = (int)$countStmt->fetchColumn();

} catch (\PDOException $e) {
    // Stored procedure not available — fallback to inline query
    $stmt = $db->prepare("
        SELECT
            u.id,
            u.name,
            u.phone,
            u.profile_pic,
            u.lat,
            u.lng,
            dp.specialization,
            dp.consultation_fee,
            dp.rating,
            dp.total_ratings,
            dp.is_available,
            dp.clinic_name,
            dp.experience_yrs,
            dll.updated_at AS location_updated_at,
            (6371 * ACOS(
                COS(RADIANS(?)) * COS(RADIANS(u.lat)) *
                COS(RADIANS(u.lng) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(u.lat))
            )) AS distance_km
        FROM users u
        INNER JOIN doctor_profiles dp ON dp.user_id = u.id
        LEFT JOIN doctor_location_latest dll ON dll.doctor_id = u.id
        WHERE u.role = 'doctor'
          AND u.is_active = 1
          AND u.lat IS NOT NULL
        HAVING distance_km <= ?
        ORDER BY distance_km ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$lat, $lng, $lat, $radius, $limit, $offset]);
    $doctors = $stmt->fetchAll();

    // Count total
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT u.id,
                (6371 * ACOS(
                    COS(RADIANS(?)) * COS(RADIANS(u.lat)) *
                    COS(RADIANS(u.lng) - RADIANS(?)) +
                    SIN(RADIANS(?)) * SIN(RADIANS(u.lat))
                )) AS distance_km
            FROM users u
            INNER JOIN doctor_profiles dp ON dp.user_id = u.id
            WHERE u.role = 'doctor' AND u.is_active = 1 AND u.lat IS NOT NULL
            HAVING distance_km <= ?
        ) AS sub
    ");
    $countStmt->execute([$lat, $lng, $lat, $radius]);
    $total = (int)$countStmt->fetchColumn();
}

// ── Enrich with online status ──────────────────────────────
foreach ($doctors as &$doc) {
    $doc['distance_km'] = round((float)$doc['distance_km'], 2);
    if (!empty($doc['location_updated_at'])) {
        $age = time() - strtotime($doc['location_updated_at']);
        $doc['is_online']           = $age < 300;  // online if updated in last 5 min
        $doc['last_seen_mins_ago']  = (int)($age / 60);
    } else {
        $doc['is_online']          = false;
        $doc['last_seen_mins_ago'] = null;
    }
    // Cast numeric strings → numbers
    $doc['lat']              = (float)$doc['lat'];
    $doc['lng']              = (float)$doc['lng'];
    $doc['consultation_fee'] = (float)$doc['consultation_fee'];
    $doc['rating']           = (float)$doc['rating'];
    $doc['is_available']     = (bool)$doc['is_available'];
}
unset($doc);

respond(true, count($doctors) . ' doctor(s) found', [
    'doctors'   => $doctors,
    'total'     => $total,
    'page'      => $page,
    'per_page'  => $limit,
    'radius_km' => $radius,
]);