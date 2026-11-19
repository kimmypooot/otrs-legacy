<?php
/**
 * hrd/api/delete_sme.php
 * POST — delete a global SME. Blocked if still actively assigned to any training.
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}
if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid SME ID.']);
    exit;
}

try {
    // Check if actively used in any current/upcoming training
    $stmtCheck = $conn->prepare("
        SELECT COUNT(*) FROM otrs_training_smes ts
        JOIN otrs_trainings t ON t.id = ts.training_id
        WHERE ts.sme_id = :id
          AND t.training_status IN ('Upcoming', 'Ongoing')
    ");
    $stmtCheck->execute([':id' => $id]);
    $activeCount = (int) $stmtCheck->fetchColumn();

    if ($activeCount > 0) {
        http_response_code(409);
        echo json_encode([
            'status'  => 'error',
            'message' => "This SME is assigned to {$activeCount} upcoming/ongoing training(s). Remove the assignment first.",
        ]);
        exit;
    }

    // Unlink (set sme_id = NULL) on completed/past training assignments before deleting
    $conn->prepare("UPDATE otrs_training_smes SET sme_id = NULL WHERE sme_id = :id")
         ->execute([':id' => $id]);

    $conn->prepare("DELETE FROM otrs_smes WHERE id = :id")->execute([':id' => $id]);

    echo json_encode(['status' => 'success', 'message' => 'SME deleted successfully.']);
} catch (PDOException $e) {
    error_log('[delete_sme] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to delete SME.']);
}
