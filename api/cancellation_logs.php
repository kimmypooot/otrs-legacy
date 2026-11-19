<?php
ob_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);
if (!$requestId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request ID.']);
    exit;
}

// HRD can view logs for any request (they review all offices). A field-office
// user may only view logs for a request their own office filed.
$sessionType = strtolower($_SESSION['type'] ?? '');
if ($sessionType !== 'hrd') {
    $own = $conn->prepare(
        "SELECT id FROM otrs_cancellation_requests
         WHERE id = :rid AND LOWER(requested_by_location) = :loc
         LIMIT 1"
    );
    $own->execute([':rid' => $requestId, ':loc' => $sessionType]);
    if (!$own->fetch()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
        exit;
    }
}

try {
    $stmt = $conn->prepare("
        SELECT action, actor, actor_location, remarks, created_at
        FROM   otrs_cancellation_logs
        WHERE  request_id = :rid
        ORDER  BY created_at ASC
    ");
    $stmt->execute([':rid' => $requestId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $logs]);

} catch (PDOException $ex) {
    error_log('[cancellation_logs] ' . $ex->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to load logs.']);
}
