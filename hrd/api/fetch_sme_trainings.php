<?php
/**
 * hrd/api/fetch_sme_trainings.php
 * GET ?sme_id=N — returns all trainings a master-registry SME is assigned to.
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$smeId = filter_input(INPUT_GET, 'sme_id', FILTER_VALIDATE_INT);
if (!$smeId) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid SME ID.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            t.id            AS training_id,
            t.name          AS training_name,
            t.start_date,
            t.end_date,
            t.training_status,
            t.mode,
            t.location,
            t.type          AS training_type,
            ts.sme_name,
            ts.designation
        FROM otrs_training_smes ts
        JOIN otrs_trainings t ON t.id = ts.training_id
        WHERE ts.sme_id = :sme_id
        ORDER BY t.start_date DESC
    ");
    $stmt->execute([':sme_id' => $smeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $rows]);
} catch (PDOException $e) {
    error_log('[fetch_sme_trainings] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch trainings.']);
}
