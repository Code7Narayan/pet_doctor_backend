<?php
// backend/api/vaccinations/index.php
// POST /api/vaccinations              → add vaccination record (owner or doctor)
// GET  /api/vaccinations?animal_id=X  → list for animal
// PUT  /api/vaccinations/{id}         → update record

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

// Extract optional vax ID from path: /api/vaccinations/7
$uriParts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$vaxId    = (int) end($uriParts);
if (!is_numeric(end($uriParts))) $vaxId = 0;

// ── POST: Add vaccination ──────────────────────────────────────
if ($method === 'POST') {
    $body     = body();
    $animalId = (int) ($body['animal_id']    ?? 0);
    $vaccine  = trim($body['vaccine_name']   ?? '');
    $givenDate= trim($body['given_date']     ?? '');

    if (!$animalId || !$vaccine || !$givenDate) {
        respond(false, 'animal_id, vaccine_name and given_date are required', [], 422);
    }

    // Verify ownership or doctor treating this animal
    $stmt = $db->prepare('SELECT owner_id FROM animals WHERE id = ? AND is_active = 1');
    $stmt->execute([$animalId]);
    $animal = $stmt->fetch();
    if (!$animal) respond(false, 'Animal not found', [], 404);

    if ($role === 'owner' && (int)$animal['owner_id'] !== $userId) {
        respond(false, 'Access denied', [], 403);
    }

    $stmt = $db->prepare('
        INSERT INTO vaccinations
            (animal_id, vaccine_name, given_date, next_due_date, given_by, batch_number, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $animalId,
        $vaccine,
        $givenDate,
        $body['next_due_date'] ?: null,
        trim($body['given_by']      ?? ''),
        trim($body['batch_number']  ?? ''),
        trim($body['notes']         ?? ''),
    ]);

    respond(true, 'Vaccination record added', [
        'vaccination_id' => (int) $db->lastInsertId(),
    ], 201);
}

// ── GET: List vaccinations for an animal ──────────────────────
if ($method === 'GET' && !$vaxId) {
    $animalId = (int) ($_GET['animal_id'] ?? 0);
    if (!$animalId) respond(false, 'animal_id is required', [], 422);

    $stmt = $db->prepare('
        SELECT * FROM vaccinations
        WHERE animal_id = ?
        ORDER BY given_date DESC
    ');
    $stmt->execute([$animalId]);
    respond(true, 'Vaccination records', ['vaccinations' => $stmt->fetchAll()]);
}

// ── PUT: Update vaccination ────────────────────────────────────
if ($method === 'PUT' && $vaxId) {
    $body = body();
    $db->prepare('
        UPDATE vaccinations
        SET vaccine_name=?, given_date=?, next_due_date=?, given_by=?, batch_number=?, notes=?
        WHERE id=?
    ')->execute([
        $body['vaccine_name']  ?? null,
        $body['given_date']    ?? null,
        $body['next_due_date'] ?: null,
        $body['given_by']      ?? null,
        $body['batch_number']  ?? null,
        $body['notes']         ?? null,
        $vaxId,
    ]);
    respond(true, 'Vaccination record updated');
}

respond(false, 'Not found', [], 404);