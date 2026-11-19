<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

if (!isset($_POST['trainingId'])) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$trainingId = trim($_POST['trainingId']);

try {
    $stmt = $conn->prepare(
        "SELECT name, date, location, fee, mode, status_trng, level, slots, status, description
         FROM otrs_trainings
         WHERE name = :name"
    );
    $stmt->execute([':name' => $trainingId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode($result ?: ['error' => 'Training not found']);
} catch (PDOException $e) {
    error_log('[fetch_training] ' . $e->getMessage());
    echo json_encode(['error' => 'Database error.']);
}
?>
