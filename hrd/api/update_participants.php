<?php
// api/update_participants.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../models/Participant.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

try {
    $registrationIds = json_decode($_POST['participantIds'] ?? '[]', true);
    $newStatus       = trim($_POST['newStatus'] ?? '');

    if (empty($registrationIds) || empty($newStatus)) {
        throw new Exception('Invalid parameters');
    }

    $participant = new Participant($conn);

    if ($participant->updateTrainingStatus($registrationIds, $newStatus)) {
        echo json_encode([
            'status'  => 'success',
            'message' => 'Participants updated successfully',
        ]);
    } else {
        throw new Exception('Failed to update participants');
    }
} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
}
?>
