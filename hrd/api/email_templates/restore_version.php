<?php
/**
 * api/email_templates/restore_version.php
 * Restores a historical version as the new current version.
 * Archives the current version first, then overwrites the live row.
 * POST, HRD-only, CSRF required.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../inc/email_template_engine.php';

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

$templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
$versionId  = isset($_POST['version_id'])  ? (int)$_POST['version_id']  : 0;

if ($templateId <= 0 || $versionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing template_id or version_id.']);
    exit;
}

$by = htmlspecialchars($_SESSION['username'] ?? 'system', ENT_QUOTES, 'UTF-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    // Fetch current live row
    $curStmt = $conn->prepare("
        SELECT id, template_code, template_name, badge_label, email_subject,
               email_body, email_altbody, version
        FROM   otrs_email_templates
        WHERE  id = :id LIMIT 1
    ");
    $curStmt->execute([':id' => $templateId]);
    $current = $curStmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Template not found.']);
        exit;
    }

    // Fetch historical version
    $verStmt = $conn->prepare("
        SELECT id, template_id, version, template_name, badge_label,
               email_subject, email_body, email_altbody
        FROM   otrs_email_template_versions
        WHERE  id = :vid AND template_id = :tid
        LIMIT  1
    ");
    $verStmt->execute([':vid' => $versionId, ':tid' => $templateId]);
    $historical = $verStmt->fetch(PDO::FETCH_ASSOC);
    if (!$historical) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Version record not found.']);
        exit;
    }

    // Archive the current live version before overwriting
    $note = "Overwritten by restore of v{$historical['version']}";
    archiveEmailTemplateVersion($conn, $current, $by, $note);
    $newVersion = (int)$current['version'] + 1;

    // Restore: overwrite live row with historical data
    $conn->prepare("
        UPDATE otrs_email_templates
        SET    template_name  = :name,  badge_label   = :badge,
               email_subject  = :subj,  email_body    = :body,
               email_altbody  = :alt,   version       = :ver,
               updated_by = :by,        updated_at    = NOW()
        WHERE  id = :id
    ")->execute([
        ':name'  => $historical['template_name'],
        ':badge' => $historical['badge_label'],
        ':subj'  => $historical['email_subject'],
        ':body'  => $historical['email_body'],
        ':alt'   => $historical['email_altbody'],
        ':ver'   => $newVersion,
        ':by'    => $by,
        ':id'    => $templateId,
    ]);

    logEmailTemplateAction($conn, $templateId, $current['template_code'], 'restored', $by,
        json_encode(['restored_from_version' => $historical['version'], 'new_version' => $newVersion]), $ip);

    echo json_encode([
        'success'     => true,
        'message'     => "Version {$historical['version']} restored as new version {$newVersion}.",
        'new_version' => $newVersion,
    ]);
} catch (PDOException $e) {
    error_log('[restore_version] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
