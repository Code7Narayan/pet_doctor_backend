<?php
// backend/api/inventory/index.php  — FIXED
// ID is now read from ?id=N (query param) first, then URL path fallback.
// The ?filter=low pattern for low-stock is also supported this way.

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

// ── FIXED: Parse item ID reliably ──────────────────────────
$itemId = (int)($_GET['id'] ?? 0);
if (!$itemId) {
    $lastSeg = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if (is_numeric($lastSeg)) $itemId = (int)$lastSeg;
}

$filter = $_GET['filter'] ?? '';

// ── GET: Low-stock items ────────────────────────────────────
if ($method === 'GET' && $filter === 'low') {
    $stmt = $db->prepare('
        SELECT *,
               CASE WHEN expiry_date IS NOT NULL AND expiry_date != "0000-00-00"
                    AND expiry_date < DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END AS expiring_soon
        FROM inventory
        WHERE doctor_id = ? AND is_active = 1 AND quantity <= low_stock_at
        ORDER BY quantity ASC
    ');
    $stmt->execute([$userId]);
    respond(true, 'Low stock items', ['items' => $stmt->fetchAll()]);
}

// ── GET: List ───────────────────────────────────────────────
if ($method === 'GET' && !$itemId) {
    $search = trim($_GET['search'] ?? '');
    $page   = max(1,  (int)($_GET['page']  ?? 1));
    $limit  = min(50, (int)($_GET['limit'] ?? 30));
    $offset = ($page - 1) * $limit;

    $where  = 'WHERE doctor_id = ? AND is_active = 1';
    $params = [$userId];

    if ($search) {
        $where   .= ' AND medicine_name LIKE ?';
        $params[] = "%$search%";
    }

    $stmt = $db->prepare("
        SELECT *,
               CASE WHEN quantity <= low_stock_at THEN 1 ELSE 0 END AS is_low_stock,
               CASE WHEN expiry_date IS NOT NULL AND expiry_date != '0000-00-00'
                    AND expiry_date < DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END AS expiring_soon
        FROM inventory
        $where
        ORDER BY medicine_name ASC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM inventory $where");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    respond(true, 'Inventory', [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $limit,
    ]);
}

// ── GET: Single item ────────────────────────────────────────
if ($method === 'GET' && $itemId) {
    $stmt = $db->prepare('
        SELECT * FROM inventory WHERE id = ? AND doctor_id = ? AND is_active = 1
    ');
    $stmt->execute([$itemId, $userId]);
    $item = $stmt->fetch();
    if (!$item) respond(false, 'Item not found', [], 404);
    respond(true, 'Item', ['item' => $item]);
}

// ── POST: Add item ──────────────────────────────────────────
if ($method === 'POST') {
    $body = body();
    $name = trim($body['medicine_name'] ?? '');
    if (!$name) respond(false, 'medicine_name is required', [], 422);

    $qty   = max(0,  (int)  ($body['quantity']     ?? 0));
    $price = max(0,  (float)($body['price']        ?? 0));
    $low   = max(1,  (int)  ($body['low_stock_at'] ?? 5));

    // Sanitise expiry: blank string → null
    $expiry = !empty($body['expiry_date']) ? $body['expiry_date'] : null;

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
        $expiry,
        trim($body['batch_number'] ?? ''),
        $low,
    ]);

    respond(true, 'Item added', ['item_id' => (int)$db->lastInsertId()], 201);
}

// ── PUT: Update item ────────────────────────────────────────
if ($method === 'PUT' && $itemId) {
    $stmt = $db->prepare('SELECT id FROM inventory WHERE id = ? AND doctor_id = ? AND is_active = 1');
    $stmt->execute([$itemId, $userId]);
    if (!$stmt->fetch()) respond(false, 'Item not found', [], 404);

    $body   = body();
    $expiry = !empty($body['expiry_date']) ? $body['expiry_date'] : null;

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
        isset($body['quantity'])     ? (int)   $body['quantity']     : null,
        $body['unit']          ?? null,
        isset($body['price'])        ? (float)  $body['price']        : null,
        $expiry,
        $body['batch_number']  ?? null,
        isset($body['low_stock_at']) ? (int)   $body['low_stock_at'] : null,
        $itemId, $userId,
    ]);

    respond(true, 'Item updated');
}

// ── DELETE: Soft-delete ─────────────────────────────────────
if ($method === 'DELETE' && $itemId) {
    $stmt = $db->prepare('SELECT id FROM inventory WHERE id = ? AND doctor_id = ? AND is_active = 1');
    $stmt->execute([$itemId, $userId]);
    if (!$stmt->fetch()) respond(false, 'Item not found', [], 404);

    $db->prepare('UPDATE inventory SET is_active = 0 WHERE id = ?')->execute([$itemId]);
    respond(true, 'Item deleted');
}

respond(false, 'Not found', [], 404);