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

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$statusFilter   = trim($_GET['status'] ?? 'all');
$allowedFilters = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}

try {
    $sql = "
        SELECT cr.id,
               cr.enrollment_id,
               cr.training_id,
               CASE WHEN p.id IS NULL THEN NULL
                    ELSE CONCAT(p.lname, ', ', p.fname,
                                IF(p.mname != '', CONCAT(' ', p.mname), ''))
               END AS participant_name,
               t.name          AS training_name,
               t.start_date,
               t.end_date,
               cr.remarks,
               cr.status,
               cr.requested_by,
               cr.requested_by_location,
               cr.requested_at,
               cr.reviewed_by,
               cr.reviewed_at,
               cr.review_remarks,
               (r.id IS NULL OR p.id IS NULL) AS is_orphaned
        FROM   otrs_cancellation_requests cr
        LEFT JOIN otrs_registrations  r ON r.id = cr.enrollment_id
        LEFT JOIN otrs_participants   p ON p.id = cr.participant_id
        LEFT JOIN otrs_trainings      t ON t.id = cr.training_id
    ";

    $params = [];
    if ($statusFilter !== 'all') {
        $sql .= " WHERE cr.status = :status";
        $params[':status'] = $statusFilter;
    }

    $sql .= " ORDER BY FIELD(cr.status,'pending','approved','rejected'), cr.requested_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count pending for badge
    $cntStmt = $conn->prepare("SELECT COUNT(*) FROM otrs_cancellation_requests WHERE status = 'pending'");
    $cntStmt->execute();
    $pendingCount = (int) $cntStmt->fetchColumn();

    echo json_encode(['status' => 'success', 'data' => $rows, 'pending_count' => $pendingCount]);

} catch (PDOException $ex) {
    error_log('[hrd/fetch_cancellation_requests] ' . $ex->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to load requests.']);
}
