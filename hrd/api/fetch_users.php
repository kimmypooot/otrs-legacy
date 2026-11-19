<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT username, name FROM users_portal");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($users);
} catch (PDOException $e) {
    error_log('[fetch_users] ' . $e->getMessage());
    echo json_encode([]);
}
?>
