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

$id            = isset($_POST['id'])   ? (int)$_POST['id']        : 0;
$name          = trim($_POST['name']               ?? '');
$defaultTarget = trim($_POST['defaultTarget']      ?? '') ?: null;
$defaultLevel  = trim($_POST['defaultLevel']       ?? '') ?: null;
$defaultDesc   = trim($_POST['defaultDescription'] ?? '') ?: null;

if ($id < 1 || empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input.']);
    exit;
}

try {
    $stmt = $conn->prepare(
        "UPDATE otrs_training_names
         SET name = :name, default_target = :target, default_level = :level,
             default_description = :description
         WHERE id = :id"
    );
    $stmt->execute([
        ':name'        => $name,
        ':target'      => $defaultTarget,
        ':level'       => $defaultLevel,
        ':description' => $defaultDesc,
        ':id'          => $id,
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('update_single_training.php PDO error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
