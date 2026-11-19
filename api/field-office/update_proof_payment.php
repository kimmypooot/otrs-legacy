<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$enrollmentId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$enrollmentId) {
    echo json_encode(['success' => false, 'message' => 'Invalid enrollment ID']);
    exit;
}

$allowedStatuses = ['pending', 'paid', 'promissory note', 'cancelled'];
$status          = trim($_POST['status'] ?? '');
if (!in_array($status, $allowedStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment status']);
    exit;
}

$orNo   = mb_substr(trim($_POST['orno'] ?? ''), 0, 20);
$orDate = trim($_POST['ordate'] ?? '');
if ($orDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $orDate)) $orDate = null;
$remarks = mb_substr(trim($_POST['remarks'] ?? ''), 0, 500);

$userLocation = strtolower($_SESSION['type'] ?? '');

try {
    /* Fetch registration — also verifies it belongs to this office */
    $fetchStmt = $conn->prepare("
        SELECT r.training_id, r.payment_status AS prev_status
        FROM   otrs_registrations r
        JOIN   otrs_participants   p ON p.id = r.participant_id
        WHERE  r.id              = :eid
          AND  UPPER(p.location) = UPPER(:user_loc)
        LIMIT  1
    ");
    $fetchStmt->bindValue(':eid',      $enrollmentId, PDO::PARAM_INT);
    $fetchStmt->bindValue(':user_loc', $userLocation);
    $fetchStmt->execute();
    $reg = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        echo json_encode(['success' => false, 'message' => 'Registration record not found or access denied.']);
        exit;
    }

    $conn->beginTransaction();

    $conn->prepare("
        UPDATE otrs_registrations
        SET    or_no          = :or_no,
               or_date        = :or_date,
               remarks        = :remarks,
               payment_status = :payment_status,
               updated_at     = NOW()
        WHERE  id = :eid
    ")->execute([
        ':or_no'          => $orNo,
        ':or_date'        => $orDate ?: null,
        ':remarks'        => $remarks,
        ':payment_status' => $status,
        ':eid'            => $enrollmentId,
    ]);

    /* Restore one slot when transitioning into Cancelled */
    if ($status === 'cancelled' && $reg['prev_status'] !== 'cancelled') {
        $conn->prepare("
            UPDATE otrs_trainings SET slots = slots + 1 WHERE id = :tid
        ")->execute([':tid' => (int) $reg['training_id']]);
    }

    /* Write audit log only when payment status actually changed */
    if (strcasecmp((string) $reg['prev_status'], $status) !== 0) {
        $action    = ($status === 'cancelled') ? 'Registration Cancelled' : 'Payment Updated';
        $updatedBy = $_SESSION['name'] ?? $_SESSION['username'] ?? strtoupper($userLocation);
        $conn->prepare("
            INSERT INTO otrs_payment_logs
                (registration_id, action, old_status, new_status, updated_by)
            VALUES
                (:reg_id, :action, :old_status, :new_status, :updated_by)
        ")->execute([
            ':reg_id'     => $enrollmentId,
            ':action'     => $action,
            ':old_status' => $reg['prev_status'],
            ':new_status' => $status,
            ':updated_by' => $updatedBy,
        ]);
    }

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Participant updated successfully!']);

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[update_proof_payment] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please contact support.']);
}
