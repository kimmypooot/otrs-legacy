<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$trainingId = isset($_POST['training_id']) ? (int)$_POST['training_id'] : 0;
$reason     = trim($_POST['reason'] ?? '');

if ($trainingId < 1 || empty($reason)) {
    echo json_encode(['success' => false, 'error' => 'Training ID and reason are required.']);
    exit;
}

try {
    // Verify training exists and is not already rescheduled/completed
    $check = $conn->prepare(
        "SELECT training_status FROM otrs_trainings WHERE id = :id LIMIT 1"
    );
    $check->execute([':id' => $trainingId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Training not found.']);
        exit;
    }
    if (in_array(strtolower($row['training_status']), ['rescheduled', 'completed'])) {
        echo json_encode(['success' => false, 'error' => 'Training cannot be rescheduled from its current status.']);
        exit;
    }

    $upd = $conn->prepare(
        "UPDATE otrs_trainings
         SET training_status = 'Rescheduled', reschedule_reason = :reason
         WHERE id = :id"
    );
    $upd->execute([':reason' => $reason, ':id' => $trainingId]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('reschedule_training.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
