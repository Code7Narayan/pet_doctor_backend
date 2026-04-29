<?php
// backend/api/tracking/index.php — FIXED
// POST /api/tracking/update       → doctor pushes GPS
// GET  /api/tracking/{doctor_id}  → owner polls doctor location

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Authorization,Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$auth   = AuthMiddleware::requireAuth('owner', 'doctor');
$userId = $auth['sub'];
$role   = $auth['role'];
$db     = Database::getConnection();
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ── POST: Doctor pushes location ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'doctor') respond(false, 'Only doctors can update location', [], 403);

    $body = body();
    $lat  = (float)($body['lat'] ?? 0);
    $lng  = (float)($body['lng'] ?? 0);

    if (!$lat || !$lng) respond(false, 'lat and lng are required', [], 422);

    $heading  = isset($body['heading'])   ? (int)$body['heading']     : null;
    $speed    = isset($body['speed_kmh']) ? (float)$body['speed_kmh'] : null;

    // Insert history (purged hourly by event)
    $db->prepare('
        INSERT INTO tracking (doctor_id, lat, lng, heading, speed_kmh)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([$userId, $lat, $lng, $heading, $speed]);

    // Upsert latest position
    $db->prepare('
        INSERT INTO doctor_location_latest (doctor_id, lat, lng, heading)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE lat=VALUES(lat), lng=VALUES(lng),
                                heading=VALUES(heading), updated_at=NOW()
    ')->execute([$userId, $lat, $lng, $heading]);

    // Sync user lat/lng for nearby-doctor queries
    $db->prepare('UPDATE users SET lat=?, lng=? WHERE id=?')
       ->execute([$lat, $lng, $userId]);

    respond(true, 'Location updated');
}

// ── GET: Owner polls doctor location ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // FIXED: Accept ?doctor_id=N (set by .htaccess) or numeric path segment
    $doctorId = (int)($_GET['doctor_id'] ?? 0);
    if (!$doctorId) {
        $parts    = explode('/', trim($path, '/'));
        $lastSeg  = end($parts);
        if (is_numeric($lastSeg)) $doctorId = (int)$lastSeg;
    }

    if (!$doctorId) respond(false, 'Doctor ID required', [], 422);

    // Owner must have an active treatment with this doctor
    if ($role === 'owner') {
        $stmt = $db->prepare("
            SELECT id FROM treatments
            WHERE owner_id = ? AND doctor_id = ?
              AND status IN ('accepted','in_progress')
            LIMIT 1
        ");
        $stmt->execute([$userId, $doctorId]);
        if (!$stmt->fetch()) {
            respond(false, 'No active treatment with this doctor', [], 403);
        }
    }

    $stmt = $db->prepare('
        SELECT dll.lat, dll.lng, dll.heading, dll.updated_at,
               u.name AS doctor_name, u.phone AS doctor_phone,
               dp.is_available
        FROM doctor_location_latest dll
        INNER JOIN users           u  ON u.id  = dll.doctor_id
        INNER JOIN doctor_profiles dp ON dp.user_id = dll.doctor_id
        WHERE dll.doctor_id = ?
        LIMIT 1
    ');
    $stmt->execute([$doctorId]);
    $loc = $stmt->fetch();

    if (!$loc) respond(false, 'Doctor location not available yet', [], 404);

    // Staleness: >2 minutes = offline
    $ageSeconds = time() - strtotime($loc['updated_at']);
    $loc['is_online']             = ($ageSeconds < 120);
    $loc['last_seen_seconds']     = $ageSeconds;   // FIXED key name matching Android model
    $loc['lat']                   = (float)$loc['lat'];
    $loc['lng']                   = (float)$loc['lng'];

    respond(true, 'Doctor location', ['location' => $loc]);
}

respond(false, 'Bad request', [], 400);