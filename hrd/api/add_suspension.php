<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../inc/training_dates.php';

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

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$trainingId     = isset($_POST['training_id'])     ? (int)$_POST['training_id']             : 0;
$suspendedDate  = trim($_POST['suspended_date']    ?? '');
$reason         = trim($_POST['reason']            ?? '');

if ($trainingId < 1 || empty($suspendedDate) || empty($reason)) {
    echo json_encode(['success' => false, 'error' => 'Training, date, and reason are all required.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $suspendedDate) || !checkdate(
    (int)substr($suspendedDate, 5, 2),
    (int)substr($suspendedDate, 8, 2),
    (int)substr($suspendedDate, 0, 4)
)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        INSERT INTO otrs_training_suspensions (training_id, suspended_date, reason)
        VALUES (:tid, :date, :reason)
    ");
    $stmt->execute([':tid' => $trainingId, ':date' => $suspendedDate, ':reason' => $reason]);
    $newId = (int)$conn->lastInsertId();

    // Recalculate no_hrs = actual training days (post-suspension) * 8
    $newHrs = count(getTrainingDateStrings($conn, $trainingId)) * 8;
    $conn->prepare("UPDATE otrs_trainings SET no_hrs = :hrs WHERE id = :id")
         ->execute([':hrs' => $newHrs, ':id' => $trainingId]);

    echo json_encode(['success' => true, 'id' => $newId, 'new_no_hrs' => $newHrs]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'error' => 'This date is already logged as suspended for this training.']);
    } else {
        error_log('add_suspension.php: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
    }
}
