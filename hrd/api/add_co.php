<?php
// api/add_co.php
// Inserts a collecting officer record.
// POST { user_id: int, location: string }  — looks up name/position from users_cscro8.
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$userId   = filter_var($_POST['user_id']  ?? null, FILTER_VALIDATE_INT);
$location = trim($_POST['location'] ?? '');

if (!$userId || $location === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

try {
    // Fetch name and position from users_cscro8
    $stmtU = $conn->prepare("
        SELECT CONCAT(
                   fname, ' ',
                   CASE WHEN mname != '' THEN CONCAT(LEFT(mname, 1), '. ') ELSE '' END,
                   lname
               ) AS name,
               position
        FROM   users_cscro8
        WHERE  id = :id
        LIMIT  1
    ");
    $stmtU->execute([':id' => $userId]);
    $user = $stmtU->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO otrs_collecting_officer (name, position, location)
         VALUES (:name, :position, :location)"
    );
    $stmt->execute([
        ':name'     => $user['name'],
        ':position' => $user['position'],
        ':location' => $location,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Collecting Officer added successfully.',
        'data'    => [
            'id'       => (int) $conn->lastInsertId(),
            'name'     => $user['name'],
            'position' => $user['position'],
            'location' => $location,
        ],
    ]);

} catch (PDOException $e) {
    error_log('[add_co] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
