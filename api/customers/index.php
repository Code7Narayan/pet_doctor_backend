<?php
// backend/api/customers/index.php
// GET    /api/customers             → list doctor's customers (with balance)
// POST   /api/customers             → add customer
// GET    /api/customers/{id}        → customer detail + payment history
// PUT    /api/customers/{id}        → update customer
// POST   /api/customers/{id}/pay    → record payment or charge

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,PUT,OPTIONS');
header('Access-Control-Allow-Headers: Authorization,Content-Type,Accept');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$auth   = AuthMiddleware::requireAuth('doctor');
$userId = $auth['sub'];
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Parse URL
$parts      = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$customerId = 0;
$subAction  = '';
foreach ($parts as $i => $part) {
    if (is_numeric($part)) { $customerId = (int)$part; }
    if ($part === 'pay') { $subAction = 'pay'; }
}

// ── GET list ───────────────────────────────────────────────────
if ($method === 'GET' && !$customerId) {
    $search = $_GET['search'] ?? '';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(50, (int)($_GET['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where  = 'WHERE c.doctor_id = ? AND c.is_active = 1';
    $params = [$userId];

    if ($search) {
        $where   .= ' AND (c.name LIKE ? OR c.phone LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Use the balance view
    $stmt = $db->prepare("
        SELECT c.id, c.name, c.phone, c.address, c.notes, c.created_at,
               COALESCE(b.total_charged, 0) AS total_charged,
               COALESCE(b.total_paid,    0) AS total_paid,
               COALESCE(b.outstanding,   0) AS outstanding
        FROM customers c
        LEFT JOIN v_customer_balance b ON b.customer_id = c.id
        $where
        ORDER BY c.name ASC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);

    respond(true, 'Customers', ['customers' => $stmt->fetchAll(), 'page' => $page]);
}

// ── GET single customer + payments ───────────────────────────
if ($method === 'GET' && $customerId && !$subAction) {
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ? AND doctor_id = ? AND is_active = 1');
    $stmt->execute([$customerId, $userId]);
    $customer = $stmt->fetch();
    if (!$customer) respond(false, 'Customer not found', [], 404);

    // Balance
    $bStmt = $db->prepare('SELECT * FROM v_customer_balance WHERE customer_id = ?');
    $bStmt->execute([$customerId]);
    $balance = $bStmt->fetch();

    // Payment history
    $pStmt = $db->prepare('
        SELECT cp.*, t.symptoms, a.name AS animal_name
        FROM customer_payments cp
        LEFT JOIN treatments t ON t.id = cp.treatment_id
        LEFT JOIN animals a    ON a.id = t.animal_id
        WHERE cp.customer_id = ?
        ORDER BY cp.created_at DESC
        LIMIT 50
    ');
    $pStmt->execute([$customerId]);

    respond(true, 'Customer detail', [
        'customer' => array_merge($customer, [
            'total_charged' => $balance['total_charged'] ?? 0,
            'total_paid'    => $balance['total_paid']    ?? 0,
            'outstanding'   => $balance['outstanding']   ?? 0,
        ]),
        'payments' => $pStmt->fetchAll(),
    ]);
}

// ── POST: Add customer ─────────────────────────────────────────
if ($method === 'POST' && !$customerId) {
    $body = body();
    $name = trim($body['name'] ?? '');
    if (!$name) respond(false, 'name is required', [], 422);

    $stmt = $db->prepare('
        INSERT INTO customers (doctor_id, owner_id, name, phone, address, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $userId,
        (int)($body['owner_id'] ?? 0) ?: null,
        $name,
        trim($body['phone']   ?? ''),
        trim($body['address'] ?? ''),
        trim($body['notes']   ?? ''),
    ]);

    respond(true, 'Customer added', ['customer_id' => (int)$db->lastInsertId()], 201);
}

// ── PUT: Update customer ───────────────────────────────────────
if ($method === 'PUT' && $customerId) {
    $stmt = $db->prepare('SELECT id FROM customers WHERE id = ? AND doctor_id = ?');
    $stmt->execute([$customerId, $userId]);
    if (!$stmt->fetch()) respond(false, 'Customer not found', [], 404);

    $body = body();
    $db->prepare('
        UPDATE customers
        SET name    = COALESCE(?, name),
            phone   = COALESCE(?, phone),
            address = COALESCE(?, address),
            notes   = COALESCE(?, notes)
        WHERE id = ? AND doctor_id = ?
    ')->execute([
        $body['name']    ?? null,
        $body['phone']   ?? null,
        $body['address'] ?? null,
        $body['notes']   ?? null,
        $customerId, $userId,
    ]);

    respond(true, 'Customer updated');
}

// ── POST /customers/{id}/pay : Record charge or payment ────────
if ($method === 'POST' && $customerId && $subAction === 'pay') {
    $stmt = $db->prepare('SELECT id FROM customers WHERE id = ? AND doctor_id = ?');
    $stmt->execute([$customerId, $userId]);
    if (!$stmt->fetch()) respond(false, 'Customer not found', [], 404);

    $body   = body();
    $amount = (float)($body['amount'] ?? 0);
    $type   = in_array($body['type'] ?? '', ['charge', 'payment']) ? $body['type'] : 'charge';

    if ($amount <= 0) respond(false, 'Amount must be positive', [], 422);

    $db->prepare('
        INSERT INTO customer_payments
            (customer_id, doctor_id, treatment_id, amount, type, description, paid_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $customerId,
        $userId,
        (int)($body['treatment_id'] ?? 0) ?: null,
        $amount,
        $type,
        trim($body['description'] ?? ''),
        $type === 'payment' ? date('Y-m-d H:i:s') : null,
    ]);

    // Return updated balance
    $bStmt = $db->prepare('SELECT * FROM v_customer_balance WHERE customer_id = ?');
    $bStmt->execute([$customerId]);

    respond(true, $type === 'payment' ? 'Payment recorded' : 'Charge added', [
        'payment_id' => (int)$db->lastInsertId(),
        'balance'    => $bStmt->fetch(),
    ], 201);
}

respond(false, 'Not found', [], 404);