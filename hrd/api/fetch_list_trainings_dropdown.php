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
        "SELECT name FROM otrs_training_names WHERE status = 'active' ORDER BY name ASC"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $options = [];
    foreach ($rows as $row) {
        $options[] = ['value' => $row['name'], 'label' => $row['name']];
    }

    echo json_encode($options);
} catch (PDOException $e) {
    error_log('fetch_list_trainings_dropdown.php PDO error: ' . $e->getMessage());
    echo json_encode(['error' => 'A database error occurred.']);
}
