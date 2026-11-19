<?php
// imis/otrs/hrd/api/agency_cert_reissue_ajax.php
// Re-issues a single agency certificate: generates a new PDF + QR code while
// retaining the original certificate number.
// POST: cert_id (int), CSRF token
// Returns: { success, cert_id, cert_number }

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Access denied.']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed.']));
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Invalid CSRF token.']));
}

$certId = (int)($_POST['cert_id'] ?? 0);
if ($certId <= 0) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'error' => 'Invalid certificate ID.']));
}

require_once __DIR__ . '/../../../../vendor/tcpdf/tcpdf.php';
require_once __DIR__ . '/../../../../vendor/fpdi/src/autoload.php';
require_once __DIR__ . '/../../../../vendor/phpqrcode/qrlib.php';
require_once __DIR__ . '/agency_cert_helpers.php';

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $buf = ob_get_clean();
    if (empty($buf)) return;
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo (isset($buf[0]) && $buf[0] === '{')
        ? $buf
        : json_encode(['success' => false, 'error' => 'Re-issue failed. Check server error logs.']);
});

try {
    // Fetch the cert record + batch info
    $stmt = $conn->prepare("
        SELECT r.id, r.participant_name, r.position, r.agency, r.certificate_number, r.cert_type,
               b.training_title, b.venue, b.start_date, b.end_date,
               b.no_hrs, b.training_type
        FROM otrs_agency_cert_records r
        JOIN otrs_agency_batches b ON b.id = r.batch_id
        WHERE r.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $certId]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => false, 'error' => 'Certificate record not found.']));
    }

    $certNumber      = $rec['certificate_number'];
    $participantName = $rec['participant_name'];
    $participantPosition = $rec['position'] ?? '';
    $participantAgency   = $rec['agency']   ?? '';
    $certType        = $rec['cert_type'];
    $trainingTitle   = $rec['training_title'];
    $venue           = $rec['venue'] ?? '';
    $startDate       = $rec['start_date'];
    $endDate         = $rec['end_date'] ?? '';
    $noHrs           = (int)($rec['no_hrs'] ?? 0);
    $trainingType    = trim($rec['training_type'] ?? '');
    $displayDate     = agencyBuildDisplayDate($startDate, $endDate);

    $isLocal        = in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1']);
    $currentBaseUrl = $isLocal ? 'http://localhost/csc-tms/' : 'https://otrs.cscro8.com/';

    // New QR code with new verification token
    $verificationToken = bin2hex(random_bytes(32));
    $verificationUrl   = $currentBaseUrl . 'verify-certificate.php?token=' . $verificationToken;

    $qrFile = agencyGenerateQRCode($certNumber, $verificationUrl);
    if (!$qrFile) throw new Exception('QR code generation failed.');

    $details = [
        'participant_name' => $participantName,
        'training_title'   => $trainingTitle,
        'venue'            => $venue,
        'display_date'     => $displayDate,
        'start_date'       => $startDate,
        'end_date'         => $endDate,
        'cert_type'        => $certType,
        'no_hrs'           => $noHrs,
        'training_type'    => $trainingType,
        'index'            => 0,
        'position'         => $participantPosition,
        'agency'           => $participantAgency,
    ];

    $certFilePath = agencyGenerateCertificateFile($details, $certNumber, $qrFile);
    if (!$certFilePath) throw new Exception('PDF generation failed: file was not written to disk.');

    // Update the record — retain certificate_number, replace file/QR/token
    $upd = $conn->prepare("
        UPDATE otrs_agency_cert_records
        SET certificate_file = :cf,
            qr_code          = :qr,
            qr_code_data     = :qrdata,
            issued_at        = NOW()
        WHERE id = :id
    ");
    $upd->execute([
        ':cf'     => $certFilePath,
        ':qr'     => $qrFile,
        ':qrdata' => $verificationUrl,
        ':id'     => $certId,
    ]);

    echo json_encode([
        'success'     => true,
        'cert_id'     => $certId,
        'cert_number' => $certNumber,
        'message'     => 'Certificate re-issued successfully.',
    ]);

} catch (Throwable $e) {
    error_log('[agency_cert_reissue_ajax] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
