<?php
// imis/otrs/hrd/api/ccs_void_ajax.php
// POST: cert_id (int), CSRF
// Voids a CCS certificate. Also back-propagates to otrs_certificates for public_offered source.

ob_start();
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    ob_end_clean();
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Access denied.']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed.']));
}

$csrf = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrf)) {
    ob_end_clean();
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Invalid CSRF token.']));
}

$certId = (int)($_POST['cert_id'] ?? 0);
if ($certId <= 0) {
    ob_end_clean();
    exit(json_encode(['success' => false, 'error' => 'Invalid certificate ID.']));
}

ob_end_clean();

try {
    $stmt = $conn->prepare("SELECT id, source, certificate_number, status FROM otrs_ccs_certificates WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $certId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        exit(json_encode(['success' => false, 'error' => 'Certificate not found.']));
    }
    if ($row['status'] === 'voided') {
        exit(json_encode(['success' => false, 'error' => 'Certificate is already voided.']));
    }

    $conn->beginTransaction();

    // Void in CCS table
    $conn->prepare("UPDATE otrs_ccs_certificates SET status = 'voided' WHERE id = :id")
         ->execute([':id' => $certId]);

    // Back-propagate to legacy table for public_offered certs
    if ($row['source'] === 'public_offered') {
        $conn->prepare("UPDATE otrs_certificates SET status = 'revoked' WHERE certificate_number = :cn")
             ->execute([':cn' => $row['certificate_number']]);
    }

    // Audit log
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    $actor  = $_SESSION['username'] ?? 'hrd';
    $userId = isset($_SESSION['id']) ? (int)$_SESSION['id'] : null;
    $conn->prepare("
        INSERT INTO otrs_ccs_issuance_log
            (certificate_id, action, actor, actor_id, ip_address, notes)
        VALUES (:cid, 'voided', :actor, :aid, :ip, :notes)
    ")->execute([
        ':cid'   => $certId,
        ':actor' => $actor,
        ':aid'   => $userId,
        ':ip'    => $ip,
        ':notes' => 'Voided via CCS Dashboard.',
    ]);

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Certificate voided successfully.']);

} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('[ccs_void_ajax] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error while voiding certificate.']);
}
