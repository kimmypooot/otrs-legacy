<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

if (empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Location is always derived from the session — never trusted from POST
$location = strtoupper($_SESSION['type'] ?? '');

try {
    $stmt = $conn->prepare("
        SELECT id, name
        FROM   otrs_collecting_officer
        WHERE  UPPER(location) = UPPER(:location)
        ORDER  BY name ASC
    ");
    $stmt->execute([':location' => $location]);
    $officers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($officers, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('[fetch_co_dropdown] ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch collecting officers']);
}
