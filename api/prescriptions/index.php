<?php
// backend/api/prescriptions/index.php
// POST /api/prescriptions          → doctor adds prescription line
// GET  /api/prescriptions?treatment_id=X → list for treatment
// GET  /api/prescriptions?animal_id=X    → full history for animal

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$auth   = AuthMiddleware::requireAuth('owner', 'doctor');
$userId = $auth['sub'];
$role   = $auth['role'];
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ── POST: Add prescription ─────────────────────────────────────
if ($method === 'POST') {
    if ($role !== 'doctor') respond(false, 'Only doctors can add prescriptions', [], 403);

    $body        = body();
    $treatmentId = (int)($body['treatment_id'] ?? 0);
    $medicine    = trim($body['medicine'] ?? '');
    $dosage      = trim($body['dosage'] ?? '');
    $frequency   = trim($body['frequency'] ?? '');
    $duration    = (int)($body['duration_days'] ?? 5);

    if (!$treatmentId || !$medicine || !$dosage || !$frequency) {
        respond(false, 'treatment_id, medicine, dosage, frequency are required', [], 422);
    }

    // Verify doctor owns this treatment
    $stmt = $db->prepare('
        SELECT animal_id, doctor_id, status
        FROM treatments WHERE id = ? LIMIT 1
    ');
    $stmt->execute([$treatmentId]);
    $t = $stmt->fetch();

    if (!$t) respond(false, 'Treatment not found', [], 404);
    if ((int)$t['doctor_id'] !== $userId) respond(false, 'Not your treatment', [], 403);
    if (!in_array($t['status'], ['accepted','in_progress','completed'], true)) {
        respond(false, 'Cannot prescribe for this treatment status', [], 409);
    }

    $stmt = $db->prepare('
        INSERT INTO prescriptions
            (treatment_id, animal_id, doctor_id, medicine, dosage, frequency, duration_days, route, instructions)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $treatmentId,
        $t['animal_id'],
        $userId,
        $medicine,
        $dosage,
        $frequency,
        $duration,
        trim($body['route']        ?? ''),
        trim($body['instructions'] ?? ''),
    ]);

    respond(true, 'Prescription added', ['prescription_id' => (int)$db->lastInsertId()], 201);
}

// ── GET: List prescriptions ────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['treatment_id'])) {
        $stmt = $db->prepare('
            SELECT p.*, u.name AS doctor_name
            FROM prescriptions p
            INNER JOIN users u ON u.id = p.doctor_id
            WHERE p.treatment_id = ?
            ORDER BY p.created_at ASC
        ');
        $stmt->execute([(int)$_GET['treatment_id']]);
        respond(true, 'Prescriptions', ['prescriptions' => $stmt->fetchAll()]);
    }

    if (!empty($_GET['animal_id'])) {
        $animalId = (int)$_GET['animal_id'];

        // Verify access
        if ($role === 'owner') {
            $stmt = $db->prepare('SELECT owner_id FROM animals WHERE id = ? LIMIT 1');
            $stmt->execute([$animalId]);
            $a = $stmt->fetch();
            if (!$a || (int)$a['owner_id'] !== $userId) respond(false, 'Access denied', [], 403);
        }

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare("
            SELECT p.*, u.name AS doctor_name,
                   t.symptoms, t.diagnosis, t.completed_at AS treatment_date
            FROM prescriptions p
            INNER JOIN users u       ON u.id = p.doctor_id
            INNER JOIN treatments t  ON t.id = p.treatment_id
            WHERE p.animal_id = ?
            ORDER BY p.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute([$animalId]);
        respond(true, 'Animal prescription history', [
            'prescriptions' => $stmt->fetchAll(),
            'page' => $page,
        ]);
    }

    respond(false, 'Provide treatment_id or animal_id', [], 422);
}

respond(false, 'Method not allowed', [], 405);