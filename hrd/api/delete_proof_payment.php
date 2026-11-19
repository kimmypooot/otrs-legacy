<?php
// api/delete_proof_payment.php
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

$regId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$regId) {
    echo json_encode(['success' => false, 'message' => 'Invalid registration ID.']);
    exit;
}

try {
    $fetchStmt = $conn->prepare(
        "SELECT proof_file FROM otrs_registrations WHERE id = :id LIMIT 1"
    );
    $fetchStmt->execute([':id' => $regId]);
    $file = $fetchStmt->fetchColumn();

    if (!$file) {
        echo json_encode(['success' => false, 'message' => 'No file attached.']);
        exit;
    }

    $updateStmt = $conn->prepare(
        "UPDATE otrs_registrations SET proof_file = NULL WHERE id = :id"
    );
    $updateStmt->execute([':id' => $regId]);

    if (file_exists($file)) {
        unlink($file);
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('delete_proof_payment Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
