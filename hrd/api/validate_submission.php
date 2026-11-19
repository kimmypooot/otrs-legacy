<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../inc/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['username']) || strtolower($_SESSION['type'] ?? '') !== 'hrd') {
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

$submissionId = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
$newStatus    = trim($_POST['status'] ?? '');
$remarks      = trim($_POST['remarks'] ?? '');

if (!$submissionId || !in_array($newStatus, ['approved', 'rejected', 'pending'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters.']);
    exit;
}

$validatorId = isset($_SESSION['id']) ? (int)$_SESSION['id'] : null;

try {
    $stmt = $conn->prepare("
        UPDATE otrs_training_submissions
        SET    validation_status = :status,
               validated_by     = :validator,
               validated_at     = NOW(),
               remarks          = :remarks
        WHERE  id = :id
    ");
    $stmt->execute([
        ':status'    => $newStatus,
        ':validator' => $validatorId,
        ':remarks'   => $remarks ?: null,
        ':id'        => $submissionId,
    ]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Submission not found.']);
        exit;
    }

    otrsAuditLog($conn, "submission_{$newStatus}", 'submission', $submissionId,
        null, null,
        json_encode(['remarks' => $remarks ?: null]),
        $_SESSION['username'] ?? 'system', $_SERVER['REMOTE_ADDR'] ?? null
    );

    echo json_encode(['status' => 'success', 'message' => 'Submission updated.']);
} catch (PDOException $e) {
    error_log('[validate_submission] ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
