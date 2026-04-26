<?php
// backend/api/inventory/index.php
// GET    /api/inventory            → list doctor's inventory
// POST   /api/inventory            → add item
// PUT    /api/inventory/{id}       → update item
// DELETE /api/inventory/{id}       → soft-delete item
// GET    /api/inventory/low-stock  → items below threshold

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
header('Access-Control-Allow-Headers: Authorization,Content-Type,Accept');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$auth   = AuthMiddleware::requireAuth('doctor');
$userId = $auth['sub'];
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Extract item ID from URL: /api/inventory/7  or  /api/inventory/low-stock
$uriParts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$lastPart = end($uriParts);
$itemId   = is_numeric($lastPart) ? (int)$lastPart : 0;
$action   = !is_numeric($lastPart) && $lastPart !== 'index.php' ? $lastPart : '';

// ── GET low-stock ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'low-stock') {
    $stmt = $db->prepare('
        SELECT * FROM inventory
        WHERE doctor_id = ? AND is_active = 1 AND quantity <= low_stock_at
        ORDER BY quantity ASC
    ');
    $stmt->execute([$userId]);
    respond(true, 'Low stock items', ['items' => $stmt->fetchAll()]);
}

// ── GET list ───────────────────────────────────────────────────
if ($method === 'GET' && !$itemId) {
    $search = $_GET['search'] ?? '';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(50, (int)($_GET['limit'] ?? 30));
    $offset = ($page - 1) * $limit;

    $where  = 'WHERE i.doctor_id = ? AND i.is_active = 1';
    $params = [$userId];

    if ($search) {
        $where   .= ' AND i.medicine_name LIKE ?';
        $params[] = "%$search%";
    }

    $stmt = $db->prepare("
        SELECT *,
               CASE WHEN quantity <= low_stock_at THEN 1 ELSE 0 END AS is_low_stock,
               CASE WHEN expiry_date IS NOT NULL AND expiry_date < DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END AS expiring_soon
        FROM inventory i
        $where
        ORDER BY medicine_name ASC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    // Count totals
    $total  = $db->prepare("SELECT COUNT(*) FROM inventory $where");
    $total->execute($params);

    respond(true, 'Inventory', [
        'items'    => $items,
        'total'    => (int)$total->fetchColumn(),
        'page'     => $page,
        'per_page' => $limit,
    ]);
}

// ── GET single ─────────────────────────────────────────────────
if ($method === 'GET' && $itemId) {
    $stmt = $db->prepare('SELECT * FROM inventory WHERE id = ? AND doctor_id = ? AND is_active = 1');
    $stmt->execute([$itemId, $userId]);
    $item = $stmt->fetch();
    if (!$item) respond(false, 'Item not found', [], 404);
    respond(true, 'Item', ['item' => $item]);
}

// ── POST: Add item ─────────────────────────────────────────────
if ($method === 'POST') {
    $body = body();
    $name = trim($body['medicine_name'] ?? '');
    if (!$name) respond(false, 'medicine_name is required', [], 422);

    $qty   = max(0, (int)($body['quantity']    ?? 0));
    $price = max(0, (float)($body['price']     ?? 0));
    $low   = max(1, (int)($body['low_stock_at'] ?? 5));

    $stmt = $db->prepare('
        INSERT INTO inventory
            (doctor_id, medicine_name, quantity, unit, price, expiry_date, batch_number, low_stock_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $userId,
        $name,
        $qty,
        trim($body['unit']         ?? 'units'),
        $price,
        $body['expiry_date']       ?: null,
        trim($body['batch_number'] ?? ''),
        $low,
    ]);

    respond(true, 'Item added', ['item_id' => (int)$db->lastInsertId()], 201);
}

// ── PUT: Update item ───────────────────────────────────────────
if ($method === 'PUT' && $itemId) {
    $stmt = $db->prepare('SELECT id FROM inventory WHERE id = ? AND doctor_id = ?');
    $stmt->execute([$itemId, $userId]);
    if (!$stmt->fetch()) respond(false, 'Item not found', [], 404);

    $body = body();

    $db->prepare('
        UPDATE inventory
        SET medicine_name = COALESCE(?, medicine_name),
            quantity      = COALESCE(?, quantity),
            unit          = COALESCE(?, unit),
            price         = COALESCE(?, price),
            expiry_date   = COALESCE(?, expiry_date),
            batch_number  = COALESCE(?, batch_number),
            low_stock_at  = COALESCE(?, low_stock_at)
        WHERE id = ? AND doctor_id = ?
    ')->execute([
        $body['medicine_name'] ?? null,
        isset($body['quantity'])    ? (int)$body['quantity']    : null,
        $body['unit']          ?? null,
        isset($body['price'])       ? (float)$body['price']     : null,
        $body['expiry_date']   ?: null,
        $body['batch_number']  ?? null,
        isset($body['low_stock_at']) ? (int)$body['low_stock_at'] : null,
        $itemId,
        $userId,
    ]);

    respond(true, 'Item updated');
}

// ── DELETE: Soft-delete ────────────────────────────────────────
if ($method === 'DELETE' && $itemId) {
    $stmt = $db->prepare('SELECT id FROM inventory WHERE id = ? AND doctor_id = ?');
    $stmt->execute([$itemId, $userId]);
    if (!$stmt->fetch()) respond(false, 'Item not found', [], 404);

    $db->prepare('UPDATE inventory SET is_active = 0 WHERE id = ?')->execute([$itemId]);
    respond(true, 'Item deleted');
}

respond(false, 'Not found', [], 404);