<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit();
}

try {
    $total = (int) $conn->query("SELECT COUNT(DISTINCT id) FROM otrs_participants")->fetchColumn();
    echo json_encode(['success' => true, 'total' => $total]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'total' => 0]);
}
