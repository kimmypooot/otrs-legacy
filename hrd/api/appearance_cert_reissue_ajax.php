<?php
// imis/otrs/hrd/api/appearance_cert_reissue_ajax.php
// Re-issue a Certificate of Appearance: retains cert number, generates new QR + PDF.
// POST: cert_id (int), CSRF token
// Returns: { success, cert_id, cert_number, cert_file, message }

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Access denied.']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed.']));
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Invalid CSRF token.']));
}

$certId = (int)($_POST['cert_id'] ?? 0);
if ($certId <= 0) {
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'error' => 'Invalid certificate ID.']));
}

require_once __DIR__ . '/../../../../vendor/tcpdf/tcpdf.php';
require_once __DIR__ . '/../../../../vendor/fpdi/src/autoload.php';
require_once __DIR__ . '/../../../../vendor/phpqrcode/qrlib.php';
require_once __DIR__ . '/CertificateEngine.php';

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $buf = ob_get_clean();
    if (empty($buf)) return;
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo (isset($buf[0]) && $buf[0] === '{') ? $buf
        : json_encode(['success' => false, 'error' => 'PDF generation failed. Check server error logs.']);
});

try {
    // Verify cert exists and belongs to the appearance source
    $check = $conn->prepare("
        SELECT id FROM otrs_ccs_certificates
         WHERE id = :id AND source = 'appearance' LIMIT 1
    ");
    $check->execute([':id' => $certId]);
    if (!$check->fetchColumn()) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => false, 'error' => 'Certificate not found.']));
    }

    $userId = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';

    $conn->beginTransaction();
    $result = CertificateEngine::reissue($conn, $certId, $userId, $ip);
    $conn->commit();

    echo json_encode([
        'success'     => true,
        'cert_id'     => $result['cert_id'],
        'cert_number' => $result['cert_number'],
        'cert_file'   => $result['cert_file'],
        'message'     => 'Certificate of Appearance re-issued successfully.',
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('[appearance_cert_reissue_ajax] ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
