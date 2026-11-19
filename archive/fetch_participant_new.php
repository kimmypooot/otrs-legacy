<?php
/**
 * fetch_participant.php
 *
 * MIGRATION NOTE:
 *   Old: SELECT * FROM participants WHERE id = :id
 *   New: JOIN otrs_participants + otrs_enrollments on enrollment_id.
 *        The enrollment_id uniquely identifies a participant-training pair,
 *        eliminating the need for name/date matching.
 *
 * POST param: enrollmentId (otrs_enrollments.id)
 */

include_once '../connect.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_POST['enrollmentId']) || !is_numeric($_POST['enrollmentId'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid enrollment ID']);
    exit;
}

try {
    $conn = new PDO(
        'mysql:host=' . $servername . ';dbname=' . $dbname . ';charset=utf8mb4',
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE,        PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $stmt = $conn->prepare("
        SELECT
            -- Participant identity (otrs_participants)
            p.id              AS participant_id,
            p.fname,
            p.mname,
            p.lname,
            p.gender,
            p.age,
            p.civil_status,
            p.position_title,
            p.agency_name,
            p.agency_address,
            p.email,
            p.mobile_no,
            p.location,
            p.food_restrictions,

            -- Enrollment / payment data (otrs_enrollments)
            e.id              AS enrollment_id,
            e.training_id,
            e.payment_status,
            e.or_no,
            e.or_date,
            e.collecting_officer,
            e.remarks,
            e.status_training,
            e.registered_at

        FROM otrs_enrollments  e
        JOIN otrs_participants  p ON p.id = e.participant_id
        WHERE e.id = :enrollment_id
        LIMIT 1
    ");
    $stmt->bindValue(':enrollment_id', (int) $_POST['enrollmentId'], PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        echo json_encode(
            ['status' => 'success', 'participant' => $row],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Enrollment record not found']);
    }

} catch (PDOException $e) {
    // Do not expose DB details to the client
    error_log('[fetch_participant] PDOException: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error. Please contact support.']);
}