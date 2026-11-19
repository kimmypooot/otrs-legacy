<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$enrollmentId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$enrollmentId) {
    echo json_encode(['success' => false, 'message' => 'Invalid enrollment ID']);
    exit;
}

$userLocation = strtolower($_SESSION['type'] ?? '');

try {
    /* Fetch proof file — also verifies the enrollment belongs to this office */
    $stmt = $conn->prepare("
        SELECT r.proof_file
        FROM   otrs_registrations r
        JOIN   otrs_participants   p ON p.id = r.participant_id
        WHERE  r.id              = :id
          AND  UPPER(p.location) = UPPER(:user_loc)
        LIMIT 1
    ");
    $stmt->bindValue(':id',       $enrollmentId, PDO::PARAM_INT);
    $stmt->bindValue(':user_loc', $userLocation);
    $stmt->execute();
    $filePath = $stmt->fetchColumn();

    if (empty($filePath) || trim($filePath) === 'outputs/') {
        echo json_encode(['success' => false, 'message' => 'No file attached.']);
        exit;
    }

    /* Clear proof_file in otrs_registrations */
    $conn->prepare("UPDATE otrs_registrations SET proof_file = '', updated_at = NOW() WHERE id = :id")
         ->execute([':id' => $enrollmentId]);

    /* Also clear matching row in otrs_proof_payment if it exists */
    $regRow = $conn->prepare("SELECT participant_id, training_id FROM otrs_registrations WHERE id = :id LIMIT 1");
    $regRow->bindValue(':id', $enrollmentId, PDO::PARAM_INT);
    $regRow->execute();
    $reg = $regRow->fetch(PDO::FETCH_ASSOC);
    if ($reg) {
        $conn->prepare("
            DELETE FROM otrs_proof_payment
            WHERE  participant_id = :pid AND training_id = :tid
        ")->execute([':pid' => $reg['participant_id'], ':tid' => $reg['training_id']]);
    }

    /* Delete physical file */
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('[delete_proof_payment] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
