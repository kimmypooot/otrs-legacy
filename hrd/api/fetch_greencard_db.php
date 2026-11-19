<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT p.*,
               r.id            AS reg_id,
               r.payment_status AS status,
               r.training_status,
               r.registered_at AS timereg,
               r.or_no         AS orno,
               r.training_id,
               ot.name         AS training_name,
               ot.start_date,
               ot.end_date
        FROM otrs_participants p
        LEFT JOIN otrs_registrations r  ON r.participant_id = p.id
        LEFT JOIN otrs_trainings ot     ON ot.id = r.training_id
        ORDER BY r.registered_at DESC
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => $data,
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'data'    => [],
    ]);
}
?>
