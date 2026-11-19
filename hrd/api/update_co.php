<?php
// api/update_co.php
// Updates a collecting officer record.
// POST { id: int, user_id: int, location: string }
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

$id       = filter_var($_POST['id']       ?? null, FILTER_VALIDATE_INT);
$userId   = filter_var($_POST['user_id']  ?? null, FILTER_VALIDATE_INT);
$location = trim($_POST['location'] ?? '');

if (!$id || !$userId || $location === '') {
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
        "UPDATE otrs_collecting_officer
         SET    name     = :name,
                position = :position,
                location = :location
         WHERE  id       = :id"
    );
    $stmt->execute([
        ':name'     => $user['name'],
        ':position' => $user['position'],
        ':location' => $location,
        ':id'       => $id,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Collecting Officer updated successfully.',
        'data'    => [
            'id'       => $id,
            'name'     => $user['name'],
            'position' => $user['position'],
            'location' => $location,
        ],
    ]);

} catch (PDOException $e) {
    error_log('[update_co] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
