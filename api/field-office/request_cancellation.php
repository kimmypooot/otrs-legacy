<?php
ob_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

$enrollmentId  = filter_input(INPUT_POST, 'enrollmentId',  FILTER_VALIDATE_INT);
$participantId = filter_input(INPUT_POST, 'participantId', FILTER_VALIDATE_INT);
$trainingId    = filter_input(INPUT_POST, 'trainingId',    FILTER_VALIDATE_INT);
$remarks       = mb_substr(trim($_POST['remarks'] ?? ''), 0, 1000);

if (!$enrollmentId || !$participantId || !$trainingId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit;
}
if ($remarks === '') {
    echo json_encode(['status' => 'error', 'message' => 'Cancellation remarks are required.']);
    exit;
}

$requestedBy  = $_SESSION['name'] ?? $_SESSION['username'];
$sessionType  = strtolower($_SESSION['type'] ?? '');
$isHrd        = ($sessionType === 'hrd');

try {
    // HRD manages every office, so it isn't scoped to a single location like
    // a field-office caller is — but the request is still attributed to the
    // participant's actual office (so that office sees it in their own
    // history, same as if they'd filed it themselves).
    $chk = $conn->prepare("
        SELECT r.id, r.payment_status, p.location AS participant_location
        FROM   otrs_registrations r
        JOIN   otrs_participants   p ON p.id = r.participant_id
        WHERE  r.id              = :eid
          AND  r.participant_id  = :pid
          AND  (:is_hrd = 1 OR UPPER(p.location) = UPPER(:user_loc))
        LIMIT  1
    ");
    $chk->execute([
        ':eid'      => $enrollmentId,
        ':pid'      => $participantId,
        ':is_hrd'   => $isHrd ? 1 : 0,
        ':user_loc' => $sessionType,
    ]);
    $enrollment = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$enrollment) {
        echo json_encode(['status' => 'error', 'message' => 'Enrollment record not found or access denied.']);
        exit;
    }

    $requestedByLoc = $isHrd
        ? strtolower($enrollment['participant_location'] ?? 'hrd')
        : $sessionType;
    if ($enrollment['payment_status'] === 'cancelled') {
        echo json_encode(['status' => 'error', 'message' => 'This participant is already cancelled.']);
        exit;
    }

    // Prevent duplicate pending/approved requests
    $dup = $conn->prepare("
        SELECT id, status
        FROM   otrs_cancellation_requests
        WHERE  enrollment_id = :eid AND status IN ('pending','approved')
        LIMIT  1
    ");
    $dup->execute([':eid' => $enrollmentId]);
    $existing = $dup->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $msg = $existing['status'] === 'approved'
            ? 'An approved cancellation already exists for this participant.'
            : 'A pending cancellation request already exists for this participant.';
        echo json_encode(['status' => 'error', 'message' => $msg]);
        exit;
    }

    $conn->beginTransaction();

    $ins = $conn->prepare("
        INSERT INTO otrs_cancellation_requests
            (enrollment_id, participant_id, training_id, remarks, status,
             requested_by, requested_by_location, requested_at)
        VALUES
            (:eid, :pid, :tid, :remarks, 'pending',
             :requested_by, :requested_by_location, NOW())
    ");
    $ins->execute([
        ':eid'                   => $enrollmentId,
        ':pid'                   => $participantId,
        ':tid'                   => $trainingId,
        ':remarks'               => $remarks,
        ':requested_by'          => $requestedBy,
        ':requested_by_location' => $requestedByLoc,
    ]);
    $requestId = (int) $conn->lastInsertId();

    $log = $conn->prepare("
        INSERT INTO otrs_cancellation_logs
            (request_id, action, actor, actor_location, remarks, created_at)
        VALUES
            (:rid, 'submitted', :actor, :actor_location, :remarks, NOW())
    ");
    $log->execute([
        ':rid'           => $requestId,
        ':actor'         => $requestedBy,
        ':actor_location'=> $requestedByLoc,
        ':remarks'       => $remarks,
    ]);

    $conn->commit();

    echo json_encode([
        'status'  => 'success',
        'message' => 'Cancellation request submitted successfully. Awaiting HRD approval.',
    ]);

} catch (PDOException $ex) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[request_cancellation] ' . $ex->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to submit request. Please try again.']);
}
