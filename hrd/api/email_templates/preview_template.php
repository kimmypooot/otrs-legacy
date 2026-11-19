<?php
/**
 * api/email_templates/preview_template.php
 * Returns a rendered HTML preview of a template using sample variables.
 * Optionally accepts the template body/subject from POST to preview unsaved edits.
 * GET  → preview live template by id
 * POST → preview arbitrary body (unsaved editor content)
 * HRD-only, GET requires no CSRF; POST requires CSRF.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../inc/email_template.php';
require_once __DIR__ . '/../../../inc/email_template_engine.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $csrf = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
    if (!validateCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    // Preview unsaved editor content
    $template = [
        'id'            => 0,
        'template_code' => 'preview',
        'template_name' => trim($_POST['template_name'] ?? 'Preview'),
        'badge_label'   => trim($_POST['badge_label']   ?? 'Preview'),
        'email_subject' => trim($_POST['email_subject']  ?? '(Preview)'),
        'email_body'    => $_POST['email_body']    ?? '',
        'email_altbody' => $_POST['email_altbody'] ?? '',
        'version'       => 0,
    ];

} elseif ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing or invalid template ID.']);
        exit;
    }
    $stmt = $conn->prepare("
        SELECT id, template_code, template_name, badge_label, email_subject,
               email_body, email_altbody, version
        FROM   otrs_email_templates
        WHERE  id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Template not found.']);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $rendered = renderEmailTemplate($template, etSampleVars());
    echo json_encode([
        'success'  => true,
        'subject'  => $rendered['subject'],
        'body'     => $rendered['body'],
        'altbody'  => $rendered['altbody'],
    ]);
} catch (Throwable $e) {
    error_log('[preview_template] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Render error: ' . $e->getMessage()]);
}
