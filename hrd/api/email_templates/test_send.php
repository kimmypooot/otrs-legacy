<?php
/**
 * api/email_templates/test_send.php
 * Sends a test email with sample variables to the requesting admin's own email.
 * POST, HRD-only, CSRF required.
 *
 * POST body:
 *   id (int)  — template ID
 *   to_email  — recipient (defaults to session user's email)
 */
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../inc/email_template.php';
require_once __DIR__ . '/../../../inc/email_template_engine.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../../../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../../../../phpmailer/SMTP.php';
require_once __DIR__ . '/../../../../phpmailer/Exception.php';
require_once __DIR__ . '/../../../inc/mail_config.php';

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

$id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$toEmail = trim($_POST['to_email'] ?? '');

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing template ID.']);
    exit;
}
if ($toEmail === '') {
    // fall back to admin's own session email
    $toEmail = $_SESSION['email'] ?? '';
}
if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid recipient email address is required.']);
    exit;
}

$by = htmlspecialchars($_SESSION['username'] ?? 'system', ENT_QUOTES, 'UTF-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    $stmt = $conn->prepare("
        SELECT id, template_code, template_name, badge_label, email_subject,
               email_body, email_altbody, status, version
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

    $rendered = renderEmailTemplate($template, etSampleVars());

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = SMTP_PORT;
    $mail->Timeout    = 15;
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->isHTML(true);

    $mail->addAddress($toEmail, $by);
    $mail->Subject = '[TEST] ' . $rendered['subject'];
    $mail->Body    = $rendered['body'];
    $mail->AltBody = $rendered['altbody'];
    $mail->send();

    logEmailTemplateAction($conn, $id, $template['template_code'], 'test_sent', $by,
        json_encode(['to' => $toEmail]), $ip);

    echo json_encode([
        'success' => true,
        'message' => "Test email sent to {$toEmail}.",
    ]);
} catch (Exception $e) {
    error_log('[test_send] Mailer: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mail error: ' . $e->getMessage()]);
} catch (PDOException $e) {
    error_log('[test_send] DB: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
