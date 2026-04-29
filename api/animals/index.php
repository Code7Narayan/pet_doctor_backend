<?php
// backend/api/animals/index.php — FIXED
// GET    /api/animals          → list owner's animals
// POST   /api/animals          → create animal
// GET    /api/animals?id=X     → single animal with full history
// PUT    /api/animals?id=X     → update animal

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,PUT');
header('Access-Control-Allow-Headers: Authorization,Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$auth   = AuthMiddleware::requireAuth('owner', 'doctor');
$userId = $auth['sub'];
$role   = $auth['role'];
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ── FIXED: ID from ?id=N query param ─────────────────────────
$animalId = (int)($_GET['id'] ?? 0);
if (!$animalId) {
    // Fallback: numeric last path segment (clean URL via .htaccess)
    $lastSeg = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if (is_numeric($lastSeg)) $animalId = (int)$lastSeg;
}

// ── POST: Create animal ───────────────────────────────────────
if ($method === 'POST' && !$animalId) {
    if ($role !== 'owner') respond(false, 'Only owners can add animals', [], 403);

    $body = body();
    $name = trim($body['name'] ?? '');
    $type = $body['type'] ?? '';

    if (!$name) respond(false, 'Animal name required', [], 422);

    $validTypes = ['dog','cat','cow','buffalo','horse','goat','sheep','poultry','other'];
    if (!in_array($type, $validTypes, true)) {
        respond(false, 'Invalid animal type. Must be one of: ' . implode(', ', $validTypes), [], 422);
    }

    $stmt = $db->prepare('
        INSERT INTO animals
            (owner_id, name, type, breed, gender, dob, weight_kg, color, tag_number, allergies, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $userId,
        $name,
        $type,
        trim($body['breed']      ?? ''),
        $body['gender']          ?? 'unknown',
        $body['dob']             ?: null,
        $body['weight_kg']       ?: null,
        trim($body['color']      ?? ''),
        trim($body['tag_number'] ?? ''),
        trim($body['allergies']  ?? ''),
        trim($body['notes']      ?? ''),
    ]);

    $newId = (int)$db->lastInsertId();
    respond(true, 'Animal added successfully', ['animal_id' => $newId], 201);
}

// ── GET: Single animal ────────────────────────────────────────
if ($method === 'GET' && $animalId) {
    $stmt = $db->prepare('
        SELECT a.*, u.name AS owner_name, u.phone AS owner_phone
        FROM animals a
        INNER JOIN users u ON u.id = a.owner_id
        WHERE a.id = ? AND a.is_active = 1
        LIMIT 1
    ');
    $stmt->execute([$animalId]);
    $animal = $stmt->fetch();
    if (!$animal) respond(false, 'Animal not found', [], 404);

    // Access control
    if ($role === 'owner' && (int)$animal['owner_id'] !== $userId) {
        respond(false, 'Access denied', [], 403);
    }

    // Last 10 treatments
    $tStmt = $db->prepare('
        SELECT t.id, t.symptoms, t.diagnosis, t.status,
               t.requested_at, t.completed_at,
               u.name AS doctor_name
        FROM treatments t
        LEFT JOIN users u ON u.id = t.doctor_id
        WHERE t.animal_id = ?
        ORDER BY t.requested_at DESC
        LIMIT 10
    ');
    $tStmt->execute([$animalId]);
    $animal['recent_treatments'] = $tStmt->fetchAll();

    // Vaccinations
    $vStmt = $db->prepare('SELECT * FROM vaccinations WHERE animal_id = ? ORDER BY given_date DESC');
    $vStmt->execute([$animalId]);
    $animal['vaccinations'] = $vStmt->fetchAll();

    respond(true, 'Animal details', ['animal' => $animal]);
}

// ── GET: List ─────────────────────────────────────────────────
if ($method === 'GET' && !$animalId) {
    $ownerId = ($role === 'owner') ? $userId : (int)($_GET['owner_id'] ?? 0);
    if (!$ownerId) respond(false, 'owner_id required', [], 422);

    $page   = max(1,  (int)($_GET['page']  ?? 1));
    $limit  = min(50, (int)($_GET['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $stmt = $db->prepare('
        SELECT id, name, type, breed, gender, dob, weight_kg, photo, tag_number
        FROM animals
        WHERE owner_id = ? AND is_active = 1
        ORDER BY name ASC
        LIMIT ? OFFSET ?
    ');
    $stmt->execute([$ownerId, $limit, $offset]);
    $animals = $stmt->fetchAll();

    $cntStmt = $db->prepare('SELECT COUNT(*) FROM animals WHERE owner_id = ? AND is_active = 1');
    $cntStmt->execute([$ownerId]);

    respond(true, 'Animal list', [
        'animals'  => $animals,
        'total'    => (int)$cntStmt->fetchColumn(),
        'page'     => $page,
        'per_page' => $limit,
    ]);
}

// ── PUT: Update animal ────────────────────────────────────────
if ($method === 'PUT' && $animalId) {
    $stmt = $db->prepare('SELECT owner_id FROM animals WHERE id = ? LIMIT 1');
    $stmt->execute([$animalId]);
    $row = $stmt->fetch();
    if (!$row) respond(false, 'Animal not found', [], 404);
    if ($role === 'owner' && (int)$row['owner_id'] !== $userId) {
        respond(false, 'Access denied', [], 403);
    }

    $body = body();
    $db->prepare('
        UPDATE animals
        SET name=COALESCE(?,name), breed=COALESCE(?,breed), gender=COALESCE(?,gender),
            dob=COALESCE(?,dob), weight_kg=COALESCE(?,weight_kg), color=COALESCE(?,color),
            allergies=COALESCE(?,allergies), notes=COALESCE(?,notes)
        WHERE id=?
    ')->execute([
        $body['name']      ?? null,
        $body['breed']     ?? null,
        $body['gender']    ?? null,
        $body['dob']       ?: null,
        $body['weight_kg'] ?: null,
        $body['color']     ?? null,
        $body['allergies'] ?? null,
        $body['notes']     ?? null,
        $animalId,
    ]);

    respond(true, 'Animal updated');
}

respond(false, 'Not found', [], 404);