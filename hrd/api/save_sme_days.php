<?php
/**
 * hrd/api/save_sme_days.php
 * POST — replace all day assignments for a given training SME entry.
 *
 * Body: { training_sme_id: int, days: [{day_number: int, training_date: 'Y-m-d'}] }
 * Passing days: [] clears all assignments (= applies to all days).
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

$payload       = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$trainingSmeId = filter_var($payload['training_sme_id'] ?? null, FILTER_VALIDATE_INT);
$days          = is_array($payload['days'] ?? null) ? $payload['days'] : [];

if (!$trainingSmeId) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid training_sme_id.']);
    exit;
}

try {
    // Verify the training_sme row exists and belongs to an HRD-accessible training
    $stmtCheck = $conn->prepare("SELECT id FROM otrs_training_smes WHERE id = :id LIMIT 1");
    $stmtCheck->execute([':id' => $trainingSmeId]);
    if (!$stmtCheck->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Facilitator assignment not found.']);
        exit;
    }

    $conn->beginTransaction();

    // Delete existing day assignments
    $conn->prepare("DELETE FROM otrs_sme_training_days WHERE training_sme_id = :id")
         ->execute([':id' => $trainingSmeId]);

    // Insert new ones
    if ($days) {
        $stmtIns = $conn->prepare("
            INSERT INTO otrs_sme_training_days (training_sme_id, day_number, training_date)
            VALUES (:tsm, :dn, :td)
        ");
        foreach ($days as $d) {
            $dn   = (int)($d['day_number']   ?? 0);
            $date = trim($d['training_date'] ?? '') ?: null;
            if ($dn < 1) continue;
            $stmtIns->execute([':tsm' => $trainingSmeId, ':dn' => $dn, ':td' => $date]);
        }
    }

    $conn->commit();

    echo json_encode([
        'status'  => 'success',
        'message' => $days ? 'Day assignments saved.' : 'Day assignments cleared (facilitator applies to all days).',
        'count'   => count($days),
    ]);
} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[save_sme_days] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to save day assignments.']);
}
