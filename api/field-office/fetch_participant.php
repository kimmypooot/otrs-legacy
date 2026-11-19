<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$enrollmentId = filter_input(INPUT_POST, 'enrollmentId', FILTER_VALIDATE_INT);
if (!$enrollmentId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid enrollment ID']);
    exit;
}

$userLocation = strtolower($_SESSION['type'] ?? '');

try {
    $stmt = $conn->prepare("
        SELECT
            p.id                                        AS participant_id,
            r.id                                        AS enrollment_id,
            p.fname,
            p.mname,
            p.lname,
            p.suffix,
            p.gender,
            TIMESTAMPDIFF(YEAR, p.dob, CURDATE())       AS age,
            p.civil_status,
            p.position_title,
            p.agency_name,
            p.agency_address,
            p.email,
            p.mobile_no,
            p.location,
            p.food_restrictions,
            r.payment_status,
            r.or_no,
            r.or_date,
            r.collecting_officer,
            r.remarks,
            r.training_status,
            r.registered_at,
            r.prime_hrm_discount
        FROM otrs_registrations r
        JOIN otrs_participants p ON p.id = r.participant_id
        WHERE r.id              = :enrollment_id
          AND UPPER(p.location) = UPPER(:user_loc)
        LIMIT 1
    ");
    $stmt->bindValue(':enrollment_id', $enrollmentId, PDO::PARAM_INT);
    $stmt->bindValue(':user_loc',      $userLocation);
    $stmt->execute();
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$participant) {
        echo json_encode(['status' => 'error', 'message' => 'Participant not found']);
        exit;
    }

    echo json_encode(['status' => 'success', 'participant' => $participant], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[fetch_participant] ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
