<?php
// api/transfer_participant.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/../models/Training.php';
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
    $regIdsRaw   = trim($_POST['participantIds'] ?? '');
    $newTrainingId = (int)($_POST['newTrainingId'] ?? 0);

    if (empty($regIdsRaw) || $newTrainingId <= 0) {
        throw new Exception('Invalid parameters');
    }

    $regIds = array_map('intval', array_filter(explode(',', $regIdsRaw)));

    if (empty($regIds)) {
        throw new Exception('No valid registration IDs provided');
    }

    // Verify new training exists in otrs_trainings
    $training  = new Training($conn);
    $trainingData = $training->getById($newTrainingId);

    if (!$trainingData) {
        throw new Exception('Training not found');
    }

    $participant = new Participant($conn);

    $participant->transferToTraining($regIds, $newTrainingId);

    $by = $_SESSION['username'] ?? 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    foreach ($regIds as $rid) {
        otrsAuditLog($conn, 'participant_transferred', 'registration', $rid,
            $newTrainingId,
            null,
            json_encode(['to_training_id' => $newTrainingId, 'to_training' => $trainingData['name'] ?? '']),
            $by, $ip
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Participants transferred successfully',
    ]);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
