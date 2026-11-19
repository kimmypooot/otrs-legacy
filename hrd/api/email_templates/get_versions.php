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
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid template ID.']);
    exit;
}

try {
    // Verify template exists
    $check = $conn->prepare("SELECT id, template_name, version FROM otrs_email_templates WHERE id = :id LIMIT 1");
    $check->execute([':id' => $id]);
    $current = $check->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Template not found.']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT id, template_id, version, template_name, badge_label, email_subject,
               email_body, email_altbody, changed_by, changed_at, change_note
        FROM   otrs_email_template_versions
        WHERE  template_id = :id
        ORDER  BY version DESC
    ");
    $stmt->execute([':id' => $id]);
    $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'         => true,
        'template_name'   => $current['template_name'],
        'current_version' => (int)$current['version'],
        'versions'        => $versions,
    ]);
} catch (PDOException $e) {
    error_log('[get_versions] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
