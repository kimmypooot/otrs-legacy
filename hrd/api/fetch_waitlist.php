<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$trainingId = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT);
if (!$trainingId) {
    echo json_encode(['success' => false, 'message' => 'Invalid training ID.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT w.id, w.position, w.status, w.added_by, w.added_at, w.promoted_at,
               CONCAT_WS(' ', TRIM(p.fname), NULLIF(CONCAT(LEFT(TRIM(p.mname),1),'.'),'.'), TRIM(p.lname)) AS participant_name,
               p.agency_name
        FROM   otrs_waitlist w
        JOIN   otrs_participants p ON p.id = w.participant_id
        WHERE  w.training_id = :tid
        ORDER  BY
               FIELD(w.status,'waiting','promoted','withdrawn'),
               w.position ASC,
               w.added_at ASC
    ");
    $stmt->execute([':tid' => $trainingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    error_log('[fetch_waitlist] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
