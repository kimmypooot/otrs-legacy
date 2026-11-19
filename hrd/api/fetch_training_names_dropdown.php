<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT id, name FROM otrs_training_names WHERE status = 'active' ORDER BY name ASC"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data'   => $rows,
        'count'  => count($rows),
    ]);
} catch (PDOException $e) {
    error_log('fetch_training_names_dropdown.php PDO error: ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'A database error occurred.',
        'data'    => [],
    ]);
}
