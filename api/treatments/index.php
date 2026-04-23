<?php
// backend/api/treatments/index.php
// POST   /api/treatments          → owner: request treatment
// GET    /api/treatments          → list (owner sees own | doctor sees pending/accepted)
// GET    /api/treatments/{id}     → treatment detail
// PUT    /api/treatments/{id}     → doctor: accept/reject/complete + add notes

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,PUT');
header('Access-Control-Allow-Headers: Authorization,Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$auth        = AuthMiddleware::requireAuth('owner', 'doctor');
$userId      = $auth['sub'];
$role        = $auth['role'];
$db          = Database::getConnection();
$method      = $_SERVER['REQUEST_METHOD'];
$treatmentId = (int) (basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
if (!is_numeric(basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)))) $treatmentId = 0;

// ── POST: Owner requests treatment ────────────────────────────
if ($method === 'POST') {
    if ($role !== 'owner') respond(false, 'Only owners can request treatments', [], 403);

    $body     = body();
    $animalId = (int)($body['animal_id'] ?? 0);
    $symptoms = trim($body['symptoms']   ?? '');

    if (!$animalId || !$symptoms) {
        respond(false, 'animal_id and symptoms are required', [], 422);
    }

    // Verify animal belongs to owner
    $stmt = $db->prepare('SELECT id, name FROM animals WHERE id = ? AND owner_id = ? AND is_active = 1');
    $stmt->execute([$animalId, $userId]);
    $animal = $stmt->fetch();
    if (!$animal) respond(false, 'Animal not found or access denied', [], 404);

    // Prevent duplicate pending request for same animal
    $stmt = $db->prepare("
        SELECT id FROM treatments
        WHERE animal_id = ? AND status IN ('pending','accepted','in_progress')
        LIMIT 1
    ");
    $stmt->execute([$animalId]);
    if ($stmt->fetch()) {
        respond(false, 'An active treatment request already exists for this animal', [], 409);
    }

    $stmt = $db->prepare('
        INSERT INTO treatments
            (animal_id, owner_id, symptoms, status, owner_lat, owner_lng, visit_type)
        VALUES (?, ?, ?, "pending", ?, ?, ?)
    ');
    $stmt->execute([
        $animalId,
        $userId,
        $symptoms,
        $body['lat']        ?: null,
        $body['lng']        ?: null,
        $body['visit_type'] ?? 'home_visit',
    ]);

    respond(true, 'Treatment request sent successfully', [
        'treatment_id' => (int) $db->lastInsertId(),
        'animal_name'  => $animal['name'],
    ], 201);
}

// ── GET list ───────────────────────────────────────────────────
if ($method === 'GET' && !$treatmentId) {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(50, (int)($_GET['limit'] ?? 15));
    $offset = ($page - 1) * $limit;
    $status = $_GET['status'] ?? '';  // optional filter

    $where  = '';
    $params = [];

    if ($role === 'owner') {
        $where    = 'WHERE t.owner_id = ?';
        $params[] = $userId;
    } else {
        // Doctor sees: pending (all) + their own accepted/in_progress/completed
        $where    = "WHERE (t.status = 'pending' OR t.doctor_id = ?)";
        $params[] = $userId;
    }

    if ($status) {
        $where   .= ' AND t.status = ?';
        $params[] = $status;
    }

    $stmt = $db->prepare("
        SELECT
            t.id, t.symptoms, t.status, t.visit_type,
            t.requested_at, t.accepted_at, t.completed_at,
            a.id AS animal_id, a.name AS animal_name, a.type AS animal_type, a.photo AS animal_photo,
            o.name AS owner_name, o.phone AS owner_phone,
            d.name AS doctor_name
        FROM treatments t
        INNER JOIN animals a ON a.id = t.animal_id
        INNER JOIN users   o ON o.id = t.owner_id
        LEFT  JOIN users   d ON d.id = t.doctor_id
        $where
        ORDER BY t.requested_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $treatments = $stmt->fetchAll();

    respond(true, 'Treatment list', [
        'treatments' => $treatments,
        'page'       => $page,
        'per_page'   => $limit,
    ]);
}

// ── GET single treatment ───────────────────────────────────────
if ($method === 'GET' && $treatmentId) {
    $stmt = $db->prepare('
        SELECT
            t.*,
            a.name AS animal_name, a.type AS animal_type, a.breed,
            a.gender, a.dob, a.weight_kg, a.allergies, a.photo AS animal_photo,
            o.name AS owner_name, o.phone AS owner_phone,
            o.lat AS owner_lat_user, o.lng AS owner_lng_user,
            d.name AS doctor_name, d.phone AS doctor_phone
        FROM treatments t
        INNER JOIN animals a ON a.id = t.animal_id
        INNER JOIN users   o ON o.id = t.owner_id
        LEFT  JOIN users   d ON d.id = t.doctor_id
        WHERE t.id = ?
        LIMIT 1
    ');
    $stmt->execute([$treatmentId]);
    $t = $stmt->fetch();
    if (!$t) respond(false, 'Treatment not found', [], 404);

    // Access control
    $allowed = ($role === 'owner' && (int)$t['owner_id'] === $userId)
            || ($role === 'doctor' && ((int)$t['doctor_id'] === $userId || $t['status'] === 'pending'));
    if (!$allowed) respond(false, 'Access denied', [], 403);

    // Prescriptions
    $stmt = $db->prepare('SELECT * FROM prescriptions WHERE treatment_id = ?');
    $stmt->execute([$treatmentId]);
    $t['prescriptions'] = $stmt->fetchAll();

    respond(true, 'Treatment detail', ['treatment' => $t]);
}

// ── PUT: Update treatment status / notes ───────────────────────
if ($method === 'PUT' && $treatmentId) {
    $body   = body();
    $action = $body['action'] ?? '';  // accept | reject | complete | update_notes

    $stmt = $db->prepare('SELECT * FROM treatments WHERE id = ? LIMIT 1');
    $stmt->execute([$treatmentId]);
    $t = $stmt->fetch();
    if (!$t) respond(false, 'Treatment not found', [], 404);

    // ── Doctor actions ──────────────────────────────────────────
    if ($role === 'doctor') {
        if ($action === 'accept') {
            if ($t['status'] !== 'pending') respond(false, 'Treatment is not pending', [], 409);
            $db->prepare("
                UPDATE treatments
                SET status='accepted', doctor_id=?, accepted_at=NOW()
                WHERE id=?
            ")->execute([$userId, $treatmentId]);
            respond(true, 'Treatment accepted');
        }

        if ($action === 'reject') {
            if ($t['status'] !== 'pending') respond(false, 'Cannot reject', [], 409);
            $db->prepare("
                UPDATE treatments
                SET status='rejected', rejection_reason=?
                WHERE id=?
            ")->execute([$body['reason'] ?? 'Doctor unavailable', $treatmentId]);
            respond(true, 'Treatment rejected');
        }

        if ($action === 'start') {
            $db->prepare("UPDATE treatments SET status='in_progress' WHERE id=?")
               ->execute([$treatmentId]);
            respond(true, 'Treatment started');
        }

        if ($action === 'complete') {
            if ((int)$t['doctor_id'] !== $userId) respond(false, 'Not your case', [], 403);
            $db->prepare("
                UPDATE treatments
                SET status='completed', completed_at=NOW(),
                    diagnosis=?, treatment_notes=?
                WHERE id=?
            ")->execute([
                $body['diagnosis']        ?? '',
                $body['treatment_notes']  ?? '',
                $treatmentId,
            ]);
            respond(true, 'Treatment marked complete');
        }

        if ($action === 'update_notes') {
            $db->prepare("
                UPDATE treatments SET diagnosis=?, treatment_notes=? WHERE id=?
            ")->execute([$body['diagnosis'] ?? null, $body['treatment_notes'] ?? null, $treatmentId]);
            respond(true, 'Notes updated');
        }
    }

    // ── Owner actions ──────────────────────────────────────────
    if ($role === 'owner') {
        if ($action === 'cancel') {
            if (!in_array($t['status'], ['pending', 'accepted'], true)) {
                respond(false, 'Cannot cancel at this stage', [], 409);
            }
            $db->prepare("UPDATE treatments SET status='cancelled' WHERE id=?")
               ->execute([$treatmentId]);
            respond(true, 'Treatment cancelled');
        }

        if ($action === 'rate') {
            if ($t['status'] !== 'completed') respond(false, 'Can only rate completed treatments', [], 409);
            $rating = (int)($body['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) respond(false, 'Rating must be 1-5', [], 422);
            $db->prepare("UPDATE treatments SET rating=?, review=? WHERE id=?")
               ->execute([$rating, $body['review'] ?? null, $treatmentId]);

            // Recompute doctor rating
            $stmt = $db->prepare("
                SELECT AVG(rating) AS avg_r, COUNT(*) AS cnt
                FROM treatments WHERE doctor_id=? AND rating IS NOT NULL
            ");
            $stmt->execute([$t['doctor_id']]);
            $r = $stmt->fetch();
            $db->prepare("UPDATE doctor_profiles SET rating=?, total_ratings=? WHERE user_id=?")
               ->execute([$r['avg_r'], $r['cnt'], $t['doctor_id']]);

            respond(true, 'Rating submitted. Thank you!');
        }
    }

    respond(false, 'Unknown action', [], 422);
}

respond(false, 'Not found', [], 404);