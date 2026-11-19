<?php
// api/update_payment.php
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
    echo json_encode(['success' => false]);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$regId  = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$orNo   = trim($_POST['or_no'] ?? $_POST['orno'] ?? '');
$status = trim($_POST['status'] ?? '');

if (!$regId) {
    echo json_encode(['success' => false, 'message' => 'Invalid registration ID.']);
    exit;
}

$allowedStatuses = ['pending', 'paid', 'free', 'cancelled', 'promissory note'];
if (!in_array($status, $allowedStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment status.']);
    exit;
}

try {
    $conn->beginTransaction();

    // Fetch old status inside the transaction so the log captures the true prior value.
    $oldStmt = $conn->prepare(
        "SELECT payment_status FROM otrs_registrations WHERE id = :id LIMIT 1"
    );
    $oldStmt->execute([':id' => $regId]);
    $oldStatus = $oldStmt->fetchColumn();

    if ($oldStatus === false) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Registration not found.']);
        exit;
    }

    if ($status === 'cancelled') {
        $conn->prepare(
            "UPDATE otrs_registrations
             SET registration_status = 'cancelled',
                 payment_status      = :payment_status,
                 updated_at          = NOW()
             WHERE id = :id"
        )->execute([
            ':payment_status' => $status,
            ':id'             => $regId,
        ]);
    } else {
        $conn->prepare(
            "UPDATE otrs_registrations
             SET or_no          = :or_no,
                 payment_status = :payment_status,
                 updated_at     = NOW()
             WHERE id = :id"
        )->execute([
            ':or_no'          => $orNo,
            ':payment_status' => $status,
            ':id'             => $regId,
        ]);
    }

    // Write audit log only when the status actually changed.
    if (strcasecmp((string) $oldStatus, $status) !== 0) {
        $action    = ($status === 'cancelled') ? 'Registration Cancelled' : 'Payment Updated';
        $updatedBy = $_SESSION['name'] ?? $_SESSION['username'] ?? 'HRD';
        $conn->prepare("
            INSERT INTO otrs_payment_logs
                (registration_id, action, old_status, new_status, updated_by)
            VALUES
                (:reg_id, :action, :old_status, :new_status, :updated_by)
        ")->execute([
            ':reg_id'     => $regId,
            ':action'     => $action,
            ':old_status' => $oldStatus,
            ':new_status' => $status,
            ':updated_by' => $updatedBy,
        ]);
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('update_payment Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please contact support.']);
}
?>
