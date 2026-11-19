<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Access denied.']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Invalid CSRF token.']));
}

$trainingId = filter_input(INPUT_POST, 'training_id', FILTER_VALIDATE_INT);
if (!$trainingId) {
    exit(json_encode(['success' => false, 'message' => 'Invalid or missing training_id']));
}

try {
    $check = $conn->prepare("SELECT id, name, training_status FROM otrs_trainings WHERE id = :id LIMIT 1");
    $check->execute([':id' => $trainingId]);
    $training = $check->fetch(PDO::FETCH_ASSOC);

    if (!$training) {
        exit(json_encode(['success' => false, 'message' => 'Training not found']));
    }

    if ($training['training_status'] !== 'ongoing') {
        exit(json_encode(['success' => false, 'message' => 'Training is not in Ongoing status']));
    }

    $stmt = $conn->prepare("UPDATE otrs_trainings SET training_status = 'completed' WHERE id = :id");
    $stmt->execute([':id' => $trainingId]);

    echo json_encode([
        'success' => true,
        'message' => htmlspecialchars($training['name']) . ' has been marked as Completed.',
    ]);

} catch (PDOException $e) {
    error_log('[mark_training_completed] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
