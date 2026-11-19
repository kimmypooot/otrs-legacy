<?php
// imis/otrs/hrd/api/appearance_cert_issue_ajax.php
// Bulk-generate Certificates of Appearance for a single activity batch.
// Uses CertificateEngine::renderAppearance() — the same QR-less, A4
// letter-style design used as the right half of Merged certs. No QR/
// verification token and NO certificate number is generated for this
// format (certificate_number is stored as NULL).
// POST: activity_title, activity_type, venue, start_date, end_date,
//       reference_no (optional, stored on the batch only — not printed),
//       participant_names[] (array)
// Returns: { success, batch_id, count, results:[{cert_id,name,cert_file}], errors:[] }

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

// ── Input ─────────────────────────────────────────────────────────────────────

$actTitle  = trim($_POST['activity_title'] ?? '');
$actType   = trim($_POST['activity_type']  ?? '');
$venue     = trim($_POST['venue']          ?? '');
$startDate = trim($_POST['start_date']     ?? '');
$endDate   = trim($_POST['end_date']       ?? '');
$refNo     = trim($_POST['reference_no']   ?? '');

$validActTypes = ['seminar', 'workshop', 'meeting', 'orientation', 'consultation', 'other'];
if ($actTitle === '') {
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'error' => 'Activity title is required.']));
}
if (!in_array(strtolower($actType), $validActTypes, true)) {
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'error' => 'Invalid activity type.']));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD.']));
}

$rawNames = $_POST['participant_names'] ?? [];
if (!is_array($rawNames)) $rawNames = [$rawNames];
$names = array_values(array_filter(array_map('trim', $rawNames), fn($n) => $n !== ''));
if (empty($names)) {
    exit(json_encode(['success' => false, 'error' => 'No participant names provided.']));
}

$userId      = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
$displayDate = CertificateEngine::buildDisplayDate($startDate, $endDate);
$ip          = $_SERVER['REMOTE_ADDR'] ?? '';

// ── DB + generation ───────────────────────────────────────────────────────────

try {
    $conn->beginTransaction();

    // Create appearance batch
    $stmtBatch = $conn->prepare("
        INSERT INTO otrs_ccs_appearance_batches
            (activity_title, activity_type, venue, start_date, end_date, reference_no, created_by)
        VALUES (:at, :atype, :venue, :sd, :ed, :ref, :uid)
    ");
    $stmtBatch->execute([
        ':at'    => $actTitle,
        ':atype' => strtolower($actType),
        ':venue' => $venue,
        ':sd'    => $startDate,
        ':ed'    => $endDate,
        ':ref'   => $refNo,
        ':uid'   => $userId ?: null,
    ]);
    $batchId = (int)$conn->lastInsertId();

    $generatedCount = 0;
    $errors         = [];
    $results        = [];

    foreach ($names as $idx => $paxName) {
        try {
            $details = [
                'category'         => 'appearance',
                'participant_name' => $paxName,
                'training_title'   => $actTitle,
                'display_date'     => $displayDate,
                'start_date'       => $startDate,
                'end_date'         => $endDate,
                'venue'            => $venue,
                'source'           => 'appearance',
                'index'            => $idx,
            ];

            $certFile = CertificateEngine::generatePDF($details);
            if (!$certFile) throw new RuntimeException('PDF generation failed: file not written.');

            $ccsId = CertificateEngine::storeRecord($conn, [
                'certificate_number' => null, // Appearance certs carry no certificate number
                'source'             => 'appearance',
                'category'           => 'appearance',
                'participant_name'   => $paxName,
                'training_title'     => $actTitle,
                'venue'              => $venue,
                'start_date'         => $startDate,
                'end_date'           => $endDate,
                'batch_id'           => $batchId,
                'certificate_file'   => $certFile,
                'qr_code'            => '',
                'qr_code_data'       => '',
                'verification_token' => null,
                'status'             => 'active',
                'issued_by'          => $_SESSION['name'] ?? 'HRD',
                'generated_by'       => $userId ?: null,
            ], $_SESSION['username'] ?? 'hrd', $userId ?: null, $ip);

            $results[] = [
                'success'   => true,
                'cert_id'   => $ccsId,
                'name'      => $paxName,
                'cert_file' => $certFile,
            ];
            $generatedCount++;

        } catch (Throwable $ex) {
            $errors[]  = "Error for \"{$paxName}\": " . $ex->getMessage();
            $results[] = ['success' => false, 'name' => $paxName, 'error' => $ex->getMessage()];
        }
    }

    $conn->commit();

    echo json_encode([
        'success'  => $generatedCount > 0,
        'batch_id' => $batchId,
        'count'    => $generatedCount,
        'results'  => $results,
        'errors'   => $errors,
        'message'  => "Generated {$generatedCount} Certificate(s) of Appearance.",
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('[appearance_cert_issue_ajax] ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
