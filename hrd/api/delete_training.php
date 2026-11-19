<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

$id    = isset($_POST['id'])    ? (int)$_POST['id']    : 0;
$force = isset($_POST['force']) ? (int)$_POST['force'] : 0;

if ($id < 1) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing training ID.']);
    exit;
}

try {
    // Check for active enrollments unless force=1
    if (!$force) {
        $cnt = $conn->prepare("
            SELECT COUNT(*) FROM otrs_registrations
            WHERE training_id = :id
              AND registration_status NOT IN ('cancelled', 'not qualified')
        ");
        $cnt->execute([':id' => $id]);
        $enrolled = (int) $cnt->fetchColumn();

        if ($enrolled > 0) {
            echo json_encode([
                'status'   => 'has_registrations',
                'count'    => $enrolled,
                'message'  => "This training has {$enrolled} enrolled participant(s).",
            ]);
            exit;
        }
    }

    $stmt = $conn->prepare("DELETE FROM otrs_trainings WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Training deleted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No training found with this ID.']);
    }
} catch (PDOException $e) {
    error_log('delete_training.php PDO error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
