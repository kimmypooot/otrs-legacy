<?php
ob_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

// Optional filters from GET
$filterTrainingId = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT) ?: null;
$filterLocation   = trim($_GET['location'] ?? '');
$filterDateFrom   = trim($_GET['date_from'] ?? '');
$filterDateTo     = trim($_GET['date_to']   ?? '');

$where  = [];
$params = [];

if ($filterTrainingId) {
    $where[]  = 'rr.training_id = :training_id';
    $params[':training_id'] = $filterTrainingId;
}
if ($filterLocation !== '') {
    $where[]  = 'rr.replaced_by_location = :location';
    $params[':location'] = $filterLocation;
}
if ($filterDateFrom !== '') {
    $where[]  = 'DATE(rr.replaced_at) >= :date_from';
    $params[':date_from'] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where[]  = 'DATE(rr.replaced_at) <= :date_to';
    $params[':date_to'] = $filterDateTo;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $stmt = $conn->prepare("
        SELECT rr.id,
               rr.enrollment_id,
               rr.training_id,
               CONCAT(op.lname, ', ', op.fname,
                      IF(op.mname != '', CONCAT(' ', op.mname), '')) AS original_name,
               op.position_title  AS original_position,
               op.agency_name     AS original_agency,
               CONCAT(np.lname, ', ', np.fname,
                      IF(np.mname != '', CONCAT(' ', np.mname), '')) AS new_name,
               np.position_title  AS new_position,
               np.agency_name     AS new_agency,
               t.name             AS training_name,
               t.start_date,
               t.end_date,
               rr.replacement_reason,
               rr.replaced_by,
               rr.replaced_by_location,
               rr.replaced_at
        FROM   otrs_replacement_requests rr
        JOIN   otrs_participants op ON op.id = rr.original_participant_id
        JOIN   otrs_participants np ON np.id = rr.new_participant_id
        JOIN   otrs_trainings    t  ON t.id  = rr.training_id
        {$whereClause}
        ORDER BY rr.replaced_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $rows]);

} catch (PDOException $ex) {
    error_log('[fetch_replacement_requests HRD] ' . $ex->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to load replacement records.']);
}
