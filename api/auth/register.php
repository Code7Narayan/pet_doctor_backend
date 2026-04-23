<?php
// backend/api/auth/register.php
// POST /api/auth/register

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/jwt.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed', [], 405);
}

$body = body();

// ── Validate required fields ───────────────────────────────────
$required = ['name', 'phone', 'password', 'role'];
foreach ($required as $field) {
    if (empty($body[$field])) {
        respond(false, "Field '$field' is required", [], 422);
    }
}

$name  = trim($body['name']);
$phone = trim($body['phone']);
$email = trim($body['email'] ?? '');
$pass  = $body['password'];
$role  = $body['role'];

if (!in_array($role, ['owner', 'doctor'], true)) {
    respond(false, 'Invalid role. Must be owner or doctor', [], 422);
}

if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
    respond(false, 'Invalid phone number', [], 422);
}

if (strlen($pass) < 6) {
    respond(false, 'Password must be at least 6 characters', [], 422);
}

$db = Database::getConnection();

// ── Check duplicate phone ──────────────────────────────────────
$stmt = $db->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
$stmt->execute([$phone]);
if ($stmt->fetch()) {
    respond(false, 'Phone number already registered', [], 409);
}

$db->beginTransaction();
try {
    // ── Insert user ────────────────────────────────────────────
    $stmt = $db->prepare('
        INSERT INTO users (name, phone, email, password, role, language)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $name,
        $phone,
        $email ?: null,
        password_hash($pass, PASSWORD_BCRYPT),
        $role,
        $body['language'] ?? 'en',
    ]);
    $userId = (int) $db->lastInsertId();

    // ── Doctor: insert profile ─────────────────────────────────
    if ($role === 'doctor') {
        $spec    = trim($body['specialization'] ?? 'General');
        $license = trim($body['license_number'] ?? '');
        if (empty($license)) respond(false, 'License number required for doctors', [], 422);

        $stmt = $db->prepare('
            INSERT INTO doctor_profiles (user_id, specialization, license_number,
                                         experience_yrs, consultation_fee, clinic_name)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId, $spec, $license,
            (int)   ($body['experience_yrs']   ?? 0),
            (float) ($body['consultation_fee'] ?? 0),
            trim($body['clinic_name'] ?? ''),
        ]);
    }

    $db->commit();

    // ── Generate tokens ────────────────────────────────────────
    $tokenPayload = ['sub' => $userId, 'role' => $role];
    $access  = JWT::generateAccessToken($tokenPayload);
    $refresh = JWT::generateRefreshToken($tokenPayload);

    // Store refresh token
    $exp = date('Y-m-d H:i:s', time() + 2592000);
    $db->prepare('INSERT INTO refresh_tokens (user_id, token, expires_at) VALUES (?,?,?)')
       ->execute([$userId, $refresh, $exp]);

    respond(true, 'Registration successful', [
        'user_id'       => $userId,
        'name'          => $name,
        'role'          => $role,
        'access_token'  => $access,
        'refresh_token' => $refresh,
    ], 201);

} catch (Exception $e) {
    $db->rollBack();
    respond(false, 'Registration failed: ' . $e->getMessage(), [], 500);
}