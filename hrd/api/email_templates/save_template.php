<?php
/**
 * api/email_templates/save_template.php
 * Create a new template (id absent or 0) or update an existing one.
 * Archives the current version before overwriting.
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

$by = htmlspecialchars($_SESSION['username'] ?? 'system', ENT_QUOTES, 'UTF-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

// ── Input ────────────────────────────────────────────────────────────
$id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$code        = trim($_POST['template_code']  ?? '');
$name        = trim($_POST['template_name']  ?? '');
$badge       = trim($_POST['badge_label']    ?? '');
$subject     = trim($_POST['email_subject']  ?? '');
$body        = trim($_POST['email_body']     ?? '');
$altbody     = trim($_POST['email_altbody']  ?? '');
$description = trim($_POST['description']    ?? '');
$changeNote  = trim($_POST['change_note']    ?? '');

// Validate
if ($code === '' || $name === '' || $subject === '' || $body === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'template_code, template_name, email_subject, and email_body are required.']);
    exit;
}
if (!preg_match('/^[a-z0-9_]{2,64}$/', $code)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'template_code must be lowercase letters, numbers, and underscores only (2–64 chars).']);
    exit;
}
if (mb_strlen($name) > 128 || mb_strlen($subject) > 255 || mb_strlen($badge) > 64 || mb_strlen($description) > 255) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'One or more fields exceed maximum length.']);
    exit;
}

try {
    if ($id > 0) {
        // ── UPDATE ────────────────────────────────────────────────────
        $stmt = $conn->prepare("
            SELECT id, template_code, template_name, badge_label, email_subject,
                   email_body, email_altbody, version
            FROM   otrs_email_templates
            WHERE  id = :id
            LIMIT  1
        ");
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Template not found.']);
            exit;
        }

        // Check code uniqueness if changed
        if ($code !== $current['template_code']) {
            $dup = $conn->prepare("SELECT id FROM otrs_email_templates WHERE template_code = :code AND id != :id LIMIT 1");
            $dup->execute([':code' => $code, ':id' => $id]);
            if ($dup->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "template_code '{$code}' is already in use."]); exit;
            }
        }

        // Archive current version
        archiveEmailTemplateVersion($conn, $current, $by, $changeNote);
        $newVersion = (int)$current['version'] + 1;

        $conn->prepare("
            UPDATE otrs_email_templates
            SET    template_code = :code, template_name = :name, badge_label = :badge,
                   email_subject = :subj, email_body = :body, email_altbody = :alt,
                   description   = :desc, version = :ver,
                   updated_by = :by, updated_at = NOW()
            WHERE  id = :id
        ")->execute([
            ':code' => $code, ':name' => $name, ':badge' => $badge,
            ':subj' => $subject, ':body' => $body, ':alt'  => $altbody,
            ':desc' => $description, ':ver'  => $newVersion,
            ':by'   => $by, ':id'   => $id,
        ]);

        logEmailTemplateAction($conn, $id, $code, 'updated', $by,
            json_encode(['version' => $newVersion, 'note' => $changeNote]), $ip);

        echo json_encode(['success' => true, 'message' => 'Template updated.', 'id' => $id, 'version' => $newVersion]);

    } else {
        // ── CREATE ────────────────────────────────────────────────────
        $dup = $conn->prepare("SELECT id FROM otrs_email_templates WHERE template_code = :code LIMIT 1");
        $dup->execute([':code' => $code]);
        if ($dup->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "template_code '{$code}' is already in use."]); exit;
        }

        $conn->prepare("
            INSERT INTO otrs_email_templates
                (template_code, template_name, badge_label, email_subject,
                 email_body, email_altbody, description, status, version,
                 created_by, created_at, updated_by, updated_at)
            VALUES
                (:code, :name, :badge, :subj, :body, :alt, :desc,
                 'active', 1, :created_by, NOW(), :updated_by, NOW())
        ")->execute([
            ':code'       => $code, ':name'  => $name, ':badge' => $badge,
            ':subj'       => $subject, ':body' => $body, ':alt'  => $altbody,
            ':desc'       => $description,
            ':created_by' => $by, ':updated_by' => $by,
        ]);
        $newId = (int)$conn->lastInsertId();

        logEmailTemplateAction($conn, $newId, $code, 'created', $by,
            json_encode(['name' => $name]), $ip);

        echo json_encode(['success' => true, 'message' => 'Template created.', 'id' => $newId, 'version' => 1]);
    }
} catch (PDOException $e) {
    error_log('[save_template] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
