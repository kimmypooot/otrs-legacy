<?php
ob_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$sessionType = strtolower($_SESSION['type'] ?? '');
$location    = ($sessionType === 'hrd')
    ? strtolower(trim($_POST['location'] ?? ''))
    : $sessionType;

try {
    $stmt = $conn->prepare("
        SELECT cr.id,
               cr.enrollment_id,
               cr.training_id,
               CONCAT(p.fname, ' ',
                      IF(p.mname != '', CONCAT(p.mname, ' '), ''),
                      p.lname)          AS participant_name,
               t.name                  AS training_name,
               t.start_date,
               t.end_date,
               cr.remarks,
               cr.status,
               cr.requested_by,
               cr.requested_by_location,
               cr.requested_at,
               cr.reviewed_by,
               cr.reviewed_at,
               cr.review_remarks
        FROM   otrs_cancellation_requests cr
        JOIN   otrs_participants  p ON p.id = cr.participant_id
        JOIN   otrs_trainings     t ON t.id = cr.training_id
        WHERE  LOWER(cr.requested_by_location) = :location
        ORDER  BY cr.requested_at DESC
    ");
    $stmt->execute([':location' => $location]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $rows]);

} catch (PDOException $ex) {
    error_log('[fetch_my_cancellation_requests] ' . $ex->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to load requests.']);
}
