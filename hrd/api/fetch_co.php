<?php
// api/fetch_co.php
// Returns all collecting officers for a given location.
// POST { location: string }
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$location = trim($_POST['location'] ?? '');

try {
    $conn = new PDO(
        "mysql:host={$servername};dbname={$dbname};charset=utf8mb4",
        $username, $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $conn->prepare(
        "SELECT * FROM otrs_collecting_officer
         WHERE  UPPER(location) = UPPER(:location)
         ORDER  BY name"
    );
    $stmt->execute([':location' => $location]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (PDOException $e) {
    error_log('[fetch_co] ' . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
?>
