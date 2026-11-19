<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT ot.slots,
            COALESCE(SUM(r.payment_status IN ('pending','paid','promissory note','free')), 0) AS participant_status_count,
            COALESCE(SUM(r.payment_status = 'pending'), 0)                                    AS pending_count,
            COALESCE(SUM(r.payment_status = 'paid'), 0)                                       AS paid_count,
            COALESCE(SUM(r.payment_status = 'promissory note'), 0)                            AS promissory_count,
            COALESCE(SUM(r.registration_status = 'cancelled'), 0)                             AS cancelled_count,
            COALESCE(SUM(r.payment_status = 'free' AND r.registration_status != 'cancelled'), 0) AS free_count
        FROM otrs_trainings ot
        LEFT JOIN otrs_registrations r ON r.training_id = ot.id
        WHERE ot.id = :id
        GROUP BY ot.id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Training not found']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => array_map('intval', $row)]);
} catch (PDOException $e) {
    error_log('[fetch_training_row] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
