<?php
/**
 * hrd/api/mark_attendance.php
 * POST — upsert one attendance record for a participant/day.
 *
 * Body: { registration_id, participant_id, training_id, day_number,
 *          status: present|absent|excused, attendance_date?, notes? }
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}
if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

$payload        = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$registrationId = filter_var($payload['registration_id'] ?? null, FILTER_VALIDATE_INT);
$participantId  = filter_var($payload['participant_id']  ?? null, FILTER_VALIDATE_INT);
$trainingId     = filter_var($payload['training_id']     ?? null, FILTER_VALIDATE_INT);
$dayNumber      = filter_var($payload['day_number']      ?? null, FILTER_VALIDATE_INT);
$status         = trim($payload['status'] ?? 'present');
$notes          = trim($payload['notes']  ?? '');
$attendanceDate = trim($payload['attendance_date'] ?? '') ?: null;

$validStatuses = ['present', 'absent', 'excused'];
if (!$registrationId || !$participantId || !$trainingId || !$dayNumber) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit;
}
if (!in_array($status, $validStatuses, true)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid status value.']);
    exit;
}

// Resolve marked_by from session
$markedBy = null;
if (!empty($_SESSION['user_id'])) {
    $markedBy = (int)$_SESSION['user_id'];
}

try {
    $stmt = $conn->prepare("
        INSERT INTO otrs_attendance
            (registration_id, participant_id, training_id, day_number,
             attendance_date, status, marked_by, marked_at, notes)
        VALUES (:reg, :pid, :tid, :dn, :adate, :status, :mby, NOW(), :notes)
        ON DUPLICATE KEY UPDATE
            status         = VALUES(status),
            attendance_date = VALUES(attendance_date),
            marked_by      = VALUES(marked_by),
            marked_at      = NOW(),
            notes          = VALUES(notes)
    ");
    $stmt->execute([
        ':reg'    => $registrationId,
        ':pid'    => $participantId,
        ':tid'    => $trainingId,
        ':dn'     => $dayNumber,
        ':adate'  => $attendanceDate,
        ':status' => $status,
        ':mby'    => $markedBy,
        ':notes'  => $notes ?: null,
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Attendance updated.']);
} catch (PDOException $e) {
    error_log('[mark_attendance] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to mark attendance.']);
}
