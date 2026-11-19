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
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$trainingName   = trim($_POST['trainingName']       ?? '');
$defaultTarget  = trim($_POST['defaultTarget']      ?? '') ?: null;
$defaultLevel   = trim($_POST['defaultLevel']       ?? '') ?: null;
$defaultDesc    = trim($_POST['defaultDescription'] ?? '') ?: null;

if (empty($trainingName)) {
    echo json_encode(['success' => false, 'error' => 'Training name is required.']);
    exit;
}

try {
    $dupStmt = $conn->prepare(
        "SELECT COUNT(*) FROM otrs_training_names WHERE LOWER(name) = LOWER(:name) AND status = 'active'"
    );
    $dupStmt->execute([':name' => $trainingName]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'This training name already exists in the library.']);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO otrs_training_names (name, default_target, default_level, default_description, status)
         VALUES (:name, :target, :level, :description, 'active')"
    );
    $stmt->execute([
        ':name'        => $trainingName,
        ':target'      => $defaultTarget,
        ':level'       => $defaultLevel,
        ':description' => $defaultDesc,
    ]);

    echo json_encode(['success' => true, 'message' => 'Training name added successfully.']);
} catch (PDOException $e) {
    error_log('add_training_name.php PDO error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
