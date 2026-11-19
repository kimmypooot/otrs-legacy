<?php
// api/fetch_co_users.php
// Returns users from users_cscro8 whose fo_rsu matches the requested location.
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
if ($location === '') {
    echo json_encode([]);
    exit;
}

try {
    $conn = new PDO(
        "mysql:host={$servername};dbname={$dbname};charset=utf8mb4",
        $username, $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $conn->prepare("
        SELECT id,
               CONCAT(
                   COALESCE(fname, ''), ' ',
                   CASE 
                       WHEN mname IS NOT NULL AND mname != '' 
                       THEN CONCAT(LEFT(mname, 1), '. ') 
                       ELSE '' 
                   END,
                   COALESCE(lname, '')
               ) AS name,
               position
        FROM   users_cscro8
        WHERE  type = :location AND status = 'Active'
        ORDER  BY lname, fname
    ");
    $stmt->execute([':location' => $location]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (PDOException $e) {
    error_log('[fetch_co_users] ' . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
?>
