<?php
// backend/api/reminders/index.php
// GET    /api/reminders           → list upcoming reminders for owner
// POST   /api/reminders           → create reminder
// PUT    /api/reminders/{id}      → mark sent / update
// DELETE /api/reminders/{id}      → delete

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE');
header('Access-Control-Allow-Headers: Authorization,Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$auth   = AuthMiddleware::requireAuth('owner');
$userId = $auth['sub'];
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Extract reminder ID from URL
$uriParts   = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$reminderId = (int) end($uriParts);
if (!is_numeric(end($uriParts))) $reminderId = 0;

// ── POST: Create reminder ──────────────────────────────────────
if ($method === 'POST') {
    $body      = body();
    $title     = trim($body['title']      ?? '');
    $remindAt  = trim($body['remind_at']  ?? '');

    if (!$title || !$remindAt) {
        respond(false, 'title and remind_at are required', [], 422);
    }

    $validTypes = ['vaccine','medicine','checkup','deworming','other'];
    $type = in_array($body['type'] ?? '', $validTypes, true) ? $body['type'] : 'other';

    $db->prepare('
        INSERT INTO reminders (owner_id, animal_id, title, description, remind_at, type)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([
        $userId,
        (int)($body['animal_id'] ?? 0) ?: null,
        $title,
        trim($body['description'] ?? ''),
        $remindAt,
        $type,
    ]);

    respond(true, 'Reminder created', ['reminder_id' => (int)$db->lastInsertId()], 201);
}

// ── GET: List upcoming reminders ──────────────────────────────
if ($method === 'GET' && !$reminderId) {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;
    $filter = $_GET['filter'] ?? 'upcoming';  // upcoming | all | overdue

    $whereExtra = '';
    if ($filter === 'upcoming') $whereExtra = 'AND r.remind_at >= NOW()';
    elseif ($filter === 'overdue') $whereExtra = 'AND r.remind_at < NOW() AND r.is_sent = 0';

    $stmt = $db->prepare("
        SELECT r.*, a.name AS animal_name, a.type AS animal_type
        FROM reminders r
        LEFT JOIN animals a ON a.id = r.animal_id
        WHERE r.owner_id = ? $whereExtra
        ORDER BY r.remind_at ASC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$userId]);
    respond(true, 'Reminders', ['reminders' => $stmt->fetchAll(), 'page' => $page]);
}

// ── GET single ────────────────────────────────────────────────
if ($method === 'GET' && $reminderId) {
    $stmt = $db->prepare('SELECT * FROM reminders WHERE id = ? AND owner_id = ? LIMIT 1');
    $stmt->execute([$reminderId, $userId]);
    $r = $stmt->fetch();
    if (!$r) respond(false, 'Reminder not found', [], 404);
    respond(true, 'Reminder', ['reminder' => $r]);
}

// ── PUT: Update / mark sent ───────────────────────────────────
if ($method === 'PUT' && $reminderId) {
    $body = body();

    // Verify ownership
    $stmt = $db->prepare('SELECT id FROM reminders WHERE id = ? AND owner_id = ?');
    $stmt->execute([$reminderId, $userId]);
    if (!$stmt->fetch()) respond(false, 'Reminder not found', [], 404);

    $db->prepare('
        UPDATE reminders
        SET title=COALESCE(?,title),
            description=COALESCE(?,description),
            remind_at=COALESCE(?,remind_at),
            type=COALESCE(?,type),
            is_sent=COALESCE(?,is_sent)
        WHERE id=?
    ')->execute([
        $body['title']       ?? null,
        $body['description'] ?? null,
        $body['remind_at']   ?? null,
        $body['type']        ?? null,
        isset($body['is_sent']) ? (int)$body['is_sent'] : null,
        $reminderId,
    ]);
    respond(true, 'Reminder updated');
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE' && $reminderId) {
    $stmt = $db->prepare('SELECT id FROM reminders WHERE id = ? AND owner_id = ?');
    $stmt->execute([$reminderId, $userId]);
    if (!$stmt->fetch()) respond(false, 'Reminder not found', [], 404);

    $db->prepare('DELETE FROM reminders WHERE id = ?')->execute([$reminderId]);
    respond(true, 'Reminder deleted');
}

respond(false, 'Not found', [], 404);