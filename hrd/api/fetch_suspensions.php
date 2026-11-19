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

$trainingId = isset($_POST['training_id']) ? (int)$_POST['training_id'] : 0;
if ($trainingId < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid training ID.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT id, suspended_date, reason, created_at
        FROM otrs_training_suspensions
        WHERE training_id = :tid
        ORDER BY suspended_date ASC
    ");
    $stmt->execute([':tid' => $trainingId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (PDOException $e) {
    error_log('fetch_suspensions.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
