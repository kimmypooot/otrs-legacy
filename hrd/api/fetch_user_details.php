<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if (!$userId) {
    echo json_encode(['error' => 'Invalid user ID.']);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT id, username, name, email FROM users_portal WHERE id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode($user ?: []);
} catch (PDOException $e) {
    error_log($e->getMessage());
    echo json_encode(['error' => 'Database error.']);
}
?>
