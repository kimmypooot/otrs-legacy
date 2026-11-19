<?php
// api/delete_participant.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/../inc/audit_log.php';

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

try {
    if (empty($_POST['id'])) {
        throw new Exception('Participant ID is required');
    }

    $participant = new Participant($conn);

    $pid = (int)$_POST['id'];
    if ($participant->delete($pid)) {
        otrsAuditLog($conn, 'participant_deleted', 'participant', $pid,
            null, null, null,
            $_SESSION['username'] ?? 'system', $_SERVER['REMOTE_ADDR'] ?? null
        );
        echo json_encode(['success' => true, 'message' => 'Participant deleted successfully']);
    } else {
        throw new Exception('Failed to delete participant');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
