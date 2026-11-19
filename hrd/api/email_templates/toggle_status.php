<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../includes/bootstrap.php';

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
$csrf = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid template ID.']);
    exit;
}

$by = htmlspecialchars($_SESSION['username'] ?? 'system', ENT_QUOTES, 'UTF-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    $stmt = $conn->prepare("SELECT id, template_code, status FROM otrs_email_templates WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Template not found.']);
        exit;
    }

    $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
    $action    = ($newStatus === 'active')       ? 'activated' : 'deactivated';

    $conn->prepare("UPDATE otrs_email_templates SET status = :s, updated_by = :by, updated_at = NOW() WHERE id = :id")
         ->execute([':s' => $newStatus, ':by' => $by, ':id' => $id]);

    $conn->prepare("
        INSERT INTO otrs_email_template_logs
            (template_id, template_code, action, performed_by, performed_at, details, ip_address)
        VALUES (:tid, :code, :action, :by, NOW(), :details, :ip)
    ")->execute([
        ':tid'     => $id,
        ':code'    => $row['template_code'],
        ':action'  => $action,
        ':by'      => $by,
        ':details' => json_encode(['previous_status' => $row['status'], 'new_status' => $newStatus]),
        ':ip'      => $ip,
    ]);

    echo json_encode([
        'success'    => true,
        'new_status' => $newStatus,
        'message'    => 'Template ' . $action . ' successfully.',
    ]);
} catch (PDOException $e) {
    error_log('[toggle_status] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
