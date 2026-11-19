<?php
/**
 * hrd/api/add_training_sme.php
 * POST — assign a facilitator to a training.
 *
 * Accepts either:
 *   - sme_id (from master registry) — auto-fills name/designation
 *   - sme_name + designation (ad-hoc, no master record)
 *
 * Optional: days[] — array of {day_number, training_date} to assign immediately.
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}
if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

$payload    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$trainingId = filter_var($payload['training_id'] ?? null, FILTER_VALIDATE_INT);
$smeId      = filter_var($payload['sme_id']      ?? null, FILTER_VALIDATE_INT) ?: null;
$smeName    = trim($payload['sme_name']    ?? '');
$designation = trim($payload['designation'] ?? '');
$sortOrder  = (int)($payload['sort_order'] ?? 0);
$days       = is_array($payload['days'] ?? null) ? $payload['days'] : [];

if (!$trainingId) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid training ID.']);
    exit;
}

try {
    // If from master registry, pull name + designation
    if ($smeId) {
        $stmtS = $conn->prepare("SELECT name, designation FROM otrs_smes WHERE id = :id AND status = 'active' LIMIT 1");
        $stmtS->execute([':id' => $smeId]);
        $master = $stmtS->fetch(PDO::FETCH_ASSOC);
        if (!$master) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'SME not found or inactive.']);
            exit;
        }
        $smeName     = $smeName ?: $master['name'];
        $designation = $designation ?: $master['designation'];
    }

    if ($smeName === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'SME name is required.']);
        exit;
    }

    $conn->beginTransaction();

    // Insert into otrs_training_smes
    $stmt = $conn->prepare("
        INSERT INTO otrs_training_smes (training_id, sme_id, sme_name, designation, sort_order)
        VALUES (:tid, :sme_id, :sme_name, :designation, :sort_order)
    ");
    $stmt->execute([
        ':tid'         => $trainingId,
        ':sme_id'      => $smeId,
        ':sme_name'    => $smeName,
        ':designation' => $designation,
        ':sort_order'  => $sortOrder,
    ]);
    $newTsmId = (int) $conn->lastInsertId();

    // Insert day assignments if provided
    if ($days) {
        $stmtDay = $conn->prepare("
            INSERT IGNORE INTO otrs_sme_training_days (training_sme_id, day_number, training_date)
            VALUES (:tsm, :dn, :td)
        ");
        foreach ($days as $d) {
            $dn   = (int)($d['day_number']    ?? 0);
            $date = trim($d['training_date']  ?? '') ?: null;
            if ($dn < 1) continue;
            $stmtDay->execute([':tsm' => $newTsmId, ':dn' => $dn, ':td' => $date]);
        }
    }

    $conn->commit();

    echo json_encode([
        'status'  => 'success',
        'message' => 'Facilitator assigned to training.',
        'id'      => $newTsmId,
    ]);
} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[add_training_sme] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to assign facilitator.']);
}
