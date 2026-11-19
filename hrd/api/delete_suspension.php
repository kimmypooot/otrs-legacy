<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../inc/training_dates.php';

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

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID.']);
    exit;
}

try {
    // Resolve training_id before deleting so we can recalculate after
    $trainingId = (int)$conn->query(
        "SELECT training_id FROM otrs_training_suspensions WHERE id = $id"
    )->fetchColumn();

    $conn->prepare("DELETE FROM otrs_training_suspensions WHERE id = :id")
         ->execute([':id' => $id]);

    if ($trainingId > 0) {
        $newHrs = count(getTrainingDateStrings($conn, $trainingId)) * 8;
        $conn->prepare("UPDATE otrs_trainings SET no_hrs = :hrs WHERE id = :id")
             ->execute([':hrs' => $newHrs, ':id' => $trainingId]);
        echo json_encode(['success' => true, 'new_no_hrs' => $newHrs]);
    } else {
        echo json_encode(['success' => true]);
    }
} catch (PDOException $e) {
    error_log('delete_suspension.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
