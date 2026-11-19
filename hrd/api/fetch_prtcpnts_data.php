<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$trainingId = isset($_GET['training_id']) ? (int)$_GET['training_id'] : 0;
$location   = isset($_GET['location'])    ? trim($_GET['location'])    : '';
$filter     = isset($_GET['filter'])      ? trim($_GET['filter'])      : '';

if (!$trainingId) {
    echo json_encode([]);
    exit;
}

try {
    // Fetch training info
    $stmtT = $conn->prepare("SELECT id, name, mode FROM otrs_trainings WHERE id = :training_id LIMIT 1");
    $stmtT->execute([':training_id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);

    if (!$training) {
        echo 'Training not found.';
        exit;
    }

    // Build participant query
    $sql = "
        SELECT p.id          AS participant_id,
               r.id          AS reg_id,
               p.fname, p.mname, p.lname,
               p.gender,
               p.civil_status      AS civilstatus,
               p.position_title    AS positiontitle,
               p.agency_name,
               p.agency_address,
               p.email,
               p.mobile_no         AS mobileno,
               p.location,
               r.payment_status    AS status,
               r.or_no             AS orno,
               r.collecting_officer,
               p.food_restrictions,
               r.remarks,
               r.training_status   AS status_training,
               r.registered_at     AS timereg
        FROM otrs_participants p
        JOIN otrs_registrations r ON r.participant_id = p.id
        WHERE r.training_id = :training_id
    ";

    $params = [':training_id' => $trainingId];

    if ($location !== '') {
        $sql .= " AND p.location = :location";
        $params[':location'] = $location;
    }

    if ($filter !== '') {
        $sql .= " AND (r.payment_status = :filter OR r.registration_status = :filter)";
        $params[':filter'] = $filter;
    }

    $sql .= " ORDER BY r.registered_at DESC";

    $stmtP = $conn->prepare($sql);
    $stmtP->execute($params);
    $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($participants);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
