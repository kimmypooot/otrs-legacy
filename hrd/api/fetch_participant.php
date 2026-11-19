<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

try {
    $enrollmentId = filter_input(INPUT_POST, 'enrollmentId', FILTER_VALIDATE_INT);
    if (!$enrollmentId) {
        throw new Exception('Enrollment ID is required');
    }

    $stmt = $conn->prepare("
        SELECT
            p.id                AS participant_id,
            r.id                AS enrollment_id,
            p.fname,
            p.mname,
            p.lname,
            p.suffix,
            p.email,
            p.mobile_no         AS mobileno,
            p.gender,
            p.pwd,
            p.civil_status      AS civilstatus,
            p.salary_grade,
            p.position_title    AS positiontitle,
            p.position_level,
            p.location,
            p.agency_name,
            p.agency_unit,
            p.agency_address,
            p.home_address,
            p.food_restrictions,
            IFNULL(TIMESTAMPDIFF(YEAR, p.dob, CURDATE()), 0) AS age,
            r.payment_status    AS status,
            r.registration_status,
            r.training_status   AS status_training,
            r.or_no             AS orno,
            r.or_date           AS ordate,
            r.collecting_officer,
            r.remarks,
            r.radio_charge      AS radioCharge,
            r.radio_cert        AS radioCert,
            r.prime_hrm_discount
        FROM otrs_participants p
        JOIN otrs_registrations r ON r.participant_id = p.id
        WHERE r.id = :enrollment_id
        LIMIT 1
    ");
    $stmt->execute([':enrollment_id' => $enrollmentId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        throw new Exception('Participant not found');
    }

    echo json_encode(['status' => 'success', 'participant' => $result]);

} catch (Exception $e) {
    error_log('fetch_participant.php Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;
