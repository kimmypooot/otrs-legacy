<?php
// api/fetch_payment.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$regId = filter_input(INPUT_POST, 'participantId', FILTER_VALIDATE_INT);

if (!$regId) {
    echo json_encode(['success' => false, 'message' => 'Invalid registration ID.']);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT p.*,
                r.id                AS reg_id,
                r.payment_status    AS status,
                r.registration_status,
                r.or_no             AS orno,
                r.or_date           AS ordate,
                r.collecting_officer,
                r.remarks,
                r.proof_file,
                r.radio_charge,
                r.radio_cert,
                r.registered_at     AS timereg
         FROM otrs_participants p
         JOIN otrs_registrations r ON r.participant_id = p.id
         WHERE r.id = :reg_id
         LIMIT 1"
    );
    $stmt->execute([':reg_id' => $regId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Registration not found.']);
        exit;
    }

    echo json_encode($row);

} catch (PDOException $e) {
    error_log('fetch_payment Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
