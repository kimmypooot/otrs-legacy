<?php
ob_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$sessionType = strtolower($_SESSION['type'] ?? '');
$location    = ($sessionType === 'hrd')
    ? strtolower(trim($_POST['location'] ?? ''))
    : $sessionType;

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
        WHERE  rr.replaced_by_location = :loc
        ORDER BY rr.replaced_at DESC
    ");
    $stmt->execute([':loc' => $location]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $rows]);

} catch (PDOException $ex) {
    error_log('[fetch_my_replacements] ' . $ex->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to load replacement records.']);
}
