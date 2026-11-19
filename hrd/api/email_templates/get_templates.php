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

try {
    $stmt = $conn->query("
        SELECT id, template_code, template_name, badge_label, email_subject,
               description, status, version, created_by, created_at, updated_by, updated_at
        FROM   otrs_email_templates
        ORDER  BY template_name ASC
    ");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $conn->query("SELECT COUNT(*) FROM otrs_email_template_versions");
    // Per-template version counts
    $vcStmt = $conn->query("
        SELECT template_id, COUNT(*) AS cnt
        FROM   otrs_email_template_versions
        GROUP  BY template_id
    ");
    $vcMap = [];
    foreach ($vcStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $vcMap[(int)$row['template_id']] = (int)$row['cnt'];
    }

    foreach ($templates as &$t) {
        $t['version_count'] = $vcMap[(int)$t['id']] ?? 0;
    }
    unset($t);

    echo json_encode(['success' => true, 'templates' => $templates]);
} catch (PDOException $e) {
    error_log('[get_templates] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
