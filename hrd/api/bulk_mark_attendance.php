<?php
/**
 * hrd/api/bulk_mark_attendance.php
 * POST — mark all confirmed participants for a specific training day with one status.
 *
 * Body: { training_id, day_number, status: present|absent|excused, attendance_date? }
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
$trainingId     = filter_var($payload['training_id'] ?? null, FILTER_VALIDATE_INT);
$dayNumber      = filter_var($payload['day_number']  ?? null, FILTER_VALIDATE_INT);
$status         = trim($payload['status'] ?? 'present');
$attendanceDate = trim($payload['attendance_date'] ?? '') ?: null;

if (!$trainingId || !$dayNumber) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'training_id and day_number required.']);
    exit;
}
if (!in_array($status, ['present', 'absent', 'excused'], true)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid status value.']);
    exit;
}

$markedBy = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

try {
    // Fetch all eligible registrations for this training
    $stmtRegs = $conn->prepare("
        SELECT r.id AS registration_id, r.participant_id
        FROM otrs_registrations r
        WHERE r.training_id = :tid
          AND r.registration_status NOT IN ('Cancelled', 'cancelled')
          AND LOWER(r.payment_status) NOT IN ('cancelled', 'not qualified')
    ");
    $stmtRegs->execute([':tid' => $trainingId]);
    $regs = $stmtRegs->fetchAll(PDO::FETCH_ASSOC);

    if (empty($regs)) {
        echo json_encode(['status' => 'success', 'message' => 'No participants to update.', 'count' => 0]);
        exit;
    }

    $stmtUps = $conn->prepare("
        INSERT INTO otrs_attendance
            (registration_id, participant_id, training_id, day_number,
             attendance_date, status, marked_by, marked_at)
        VALUES (:reg, :pid, :tid, :dn, :adate, :status, :mby, NOW())
        ON DUPLICATE KEY UPDATE
            status          = VALUES(status),
            attendance_date = VALUES(attendance_date),
            marked_by       = VALUES(marked_by),
            marked_at       = NOW()
    ");

    $count = 0;
    foreach ($regs as $r) {
        $stmtUps->execute([
            ':reg'    => $r['registration_id'],
            ':pid'    => $r['participant_id'],
            ':tid'    => $trainingId,
            ':dn'     => $dayNumber,
            ':adate'  => $attendanceDate,
            ':status' => $status,
            ':mby'    => $markedBy,
        ]);
        $count++;
    }

    echo json_encode([
        'status'  => 'success',
        'message' => "Marked {$count} participant(s) as {$status} for Day {$dayNumber}.",
        'count'   => $count,
    ]);
} catch (PDOException $e) {
    error_log('[bulk_mark_attendance] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to bulk-mark attendance.']);
}
