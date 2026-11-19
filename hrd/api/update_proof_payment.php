<?php
// api/update_proof_payment.php
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

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$regId            = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$orNo             = trim($_POST['or_no'] ?? $_POST['orno'] ?? '');
$orDate           = trim($_POST['ordate'] ?? '');
$collectingOfficer = trim($_POST['collecting_officer'] ?? '');
$primeHrm         = filter_input(INPUT_POST, 'prime_hrm_discount', FILTER_VALIDATE_INT) ? 1 : 0;
$remarks          = trim($_POST['remarks'] ?? '');
$status           = trim($_POST['status'] ?? '');

// Normalise date — reject invalid / zero dates
if ($orDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $orDate)) $orDate = null;

if (!$regId) {
    echo json_encode(['success' => false, 'message' => 'Invalid registration ID.']);
    exit;
}

try {
    $conn->beginTransaction();

    /* Fetch current status before overwriting it */
    $prev = $conn->prepare("SELECT payment_status, registration_status, training_id FROM otrs_registrations WHERE id = :id LIMIT 1");
    $prev->execute([':id' => $regId]);
    $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
    $oldStatus            = $prevRow['payment_status']      ?? false;
    $oldRegistrationStatus = $prevRow['registration_status'] ?? '';
    $trainingId            = (int) ($prevRow['training_id']  ?? 0);

    if ($oldStatus === false) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Registration not found.']);
        exit;
    }

    $isCancelled   = in_array(strtolower($status), ['cancelled', 'not qualified'], true);
    $paymentStatus = $isCancelled ? $oldStatus : $status;
    $regStatus     = $isCancelled ? $status    : 'confirmed';

    $conn->prepare(
        "UPDATE otrs_registrations
         SET or_no               = :or_no,
             or_date             = :or_date,
             collecting_officer  = :officer,
             prime_hrm_discount  = :prime,
             remarks             = :remarks,
             payment_status      = :pay_status,
             registration_status = :reg_status,
             updated_at          = NOW()
         WHERE id = :id"
    )->execute([
        ':or_no'      => $orNo,
        ':or_date'    => $orDate ?: null,
        ':officer'    => $collectingOfficer,
        ':prime'      => $primeHrm,
        ':remarks'    => $remarks,
        ':pay_status' => $paymentStatus,
        ':reg_status' => $regStatus,
        ':id'         => $regId,
    ]);

    $updatedBy = $_SESSION['name'] ?? $_SESSION['username'] ?? 'HRD';

    /* Restore a slot when a participant is marked Not Qualified for the first time */
    if (strtolower($status) === 'not qualified'
        && !in_array(strtolower($oldRegistrationStatus), ['cancelled', 'not qualified'], true)
        && $trainingId > 0
    ) {
        $conn->prepare("UPDATE otrs_trainings SET slots = slots + 1 WHERE id = :tid")
             ->execute([':tid' => $trainingId]);
    }

    /* Log only when the status actually changed */
    if (strcasecmp($oldStatus, $status) !== 0) {
        $action = (strtolower($status) === 'cancelled') ? 'Registration Cancelled' : 'Payment Updated';
        $conn->prepare("
            INSERT INTO otrs_payment_logs (registration_id, action, old_status, new_status, updated_by)
            VALUES (:reg_id, :action, :old_status, :new_status, :updated_by)
        ")->execute([
            ':reg_id'     => $regId,
            ':action'     => $action,
            ':old_status' => $oldStatus,
            ':new_status' => $status,
            ':updated_by' => $updatedBy,
        ]);
    }

    /* Record HRD-initiated cancellation in otrs_cancellation_requests */
    if (strtolower($status) === 'cancelled' && strcasecmp($oldStatus, 'cancelled') !== 0) {
        $trainingIdRow = $conn->prepare("SELECT training_id, participant_id FROM otrs_registrations WHERE id = :id LIMIT 1");
        $trainingIdRow->execute([':id' => $regId]);
        $regRow = $trainingIdRow->fetch(PDO::FETCH_ASSOC);

        $cancelRemarks = !empty($remarks) ? $remarks : 'Cancelled by HRD administrator.';

        $pendingStmt = $conn->prepare("
            SELECT id FROM otrs_cancellation_requests
            WHERE  enrollment_id = :eid AND status = 'pending' LIMIT 1
        ");
        $pendingStmt->execute([':eid' => $regId]);
        $pendingId = $pendingStmt->fetchColumn();

        if ($pendingId) {
            $conn->prepare("
                UPDATE otrs_cancellation_requests
                SET    status = 'approved', reviewed_by = :by, reviewed_at = NOW(),
                       review_remarks = 'Approved — cancelled directly by HRD administrator.'
                WHERE  id = :id
            ")->execute([':by' => $updatedBy, ':id' => $pendingId]);

            $conn->prepare("
                INSERT INTO otrs_cancellation_logs (request_id, action, actor, actor_location, remarks)
                VALUES (:rid, 'approved', :actor, 'hrd', 'Approved and processed by HRD administrator.')
            ")->execute([':rid' => $pendingId, ':actor' => $updatedBy]);
        } else {
            $conn->prepare("
                INSERT INTO otrs_cancellation_requests
                    (enrollment_id, participant_id, training_id, remarks, status,
                     requested_by, requested_by_location, requested_at,
                     reviewed_by, reviewed_at, review_remarks)
                VALUES
                    (:eid, :pid, :tid, :remarks, 'approved',
                     :by, 'hrd', NOW(), :by, NOW(),
                     'Auto-approved — cancelled directly by HRD administrator.')
            ")->execute([
                ':eid'     => $regId,
                ':pid'     => $regRow['participant_id'] ?? 0,
                ':tid'     => $regRow['training_id']    ?? 0,
                ':remarks' => $cancelRemarks,
                ':by'      => $updatedBy,
            ]);
            $newReqId = (int) $conn->lastInsertId();
            $conn->prepare("
                INSERT INTO otrs_cancellation_logs (request_id, action, actor, actor_location, remarks)
                VALUES (:rid, 'approved', :actor, 'hrd', :remarks)
            ")->execute([':rid' => $newReqId, ':actor' => $updatedBy, ':remarks' => 'Cancelled directly by HRD administrator.']);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Participant updated successfully!']);

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[update_proof_payment] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please contact support.']);
}
?>
