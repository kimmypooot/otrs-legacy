<?php
/**
 * hrd/api/delete_training_sme.php
 * POST — remove an SME from a training.
 * Cascades to otrs_sme_training_days (FK ON DELETE CASCADE).
 * Soft-blocks if evaluation ratings already exist for this SME.
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

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$id      = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid ID.']);
    exit;
}

try {
    // Warn if evaluation ratings already collected for this SME
    $stmtCheck = $conn->prepare("
        SELECT COUNT(*) FROM otrs_evaluation_sme_ratings WHERE sme_id = :id
    ");
    $stmtCheck->execute([':id' => $id]);
    $ratingCount = (int) $stmtCheck->fetchColumn();

    $force = !empty($payload['force']);

    if ($ratingCount > 0 && !$force) {
        http_response_code(409);
        echo json_encode([
            'status'       => 'error',
            'message'      => "This facilitator has {$ratingCount} evaluation rating(s) on record. Removing them would delete this evaluation data. Please confirm.",
            'has_ratings'  => true,
            'rating_count' => $ratingCount,
        ]);
        exit;
    }

    $conn->prepare("DELETE FROM otrs_training_smes WHERE id = :id")->execute([':id' => $id]);

    echo json_encode(['status' => 'success', 'message' => 'Facilitator removed from training.']);
} catch (PDOException $e) {
    error_log('[delete_training_sme] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to remove facilitator.']);
}
