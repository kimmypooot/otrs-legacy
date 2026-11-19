<?php
// imis/otrs/hrd/api/agency_cert_issue_ajax.php
// Generates certificates in bulk for an agency-requested training (no otrs_trainings record needed).
// POST: training_title, venue, start_date, end_date, training_mode (face-to-face|online|hybrid|blended),
//       cert_types[] (Completion|Certification|Merged — fallback: singular cert_type),
//       participant_names[] (array of plain-text names),
//       include_signature ('1'|'0', default '1') — '0' suppresses the e-signature image on every generated cert
// Completion/Certification write to otrs_agency_cert_records (+ CCS dual-write), unchanged from before.
// Merged is CCS-only (otrs_ccs_certificates), allowed only when training_mode === face-to-face.
// Returns: { success, batch_id, count, results: [{...}], errors: [...] }

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
        : json_encode(['success' => false, 'error' => 'PDF generation failed. Check server error logs.']);
});

// ── Input validation ──────────────────────────────────────────────────────────

$trainingTitle = trim($_POST['training_title'] ?? '');
$venue         = trim($_POST['venue']          ?? '');
$startDate     = trim($_POST['start_date']     ?? '');
$endDate       = trim($_POST['end_date']       ?? '');
$noHrs         = max(0, (int)($_POST['no_hrs'] ?? 0));
$trainingType  = trim($_POST['training_type']  ?? '');
if (!in_array($trainingType, ['Technical', 'Foundation', 'Leadership and Management'], true)) {
    $trainingType = '';
}

$trainingMode = strtolower(trim($_POST['training_mode'] ?? 'face-to-face'));
if (!in_array($trainingMode, ['face-to-face', 'online', 'hybrid', 'blended'], true)) {
    $trainingMode = 'face-to-face';
}
$isF2F = $trainingMode === 'face-to-face';

// "Include Signature" checkbox — defaults to included (checked) when the
// field is absent, so any older caller still posting without it keeps the
// pre-existing behavior. Unchecking it only ever suppresses the e-signature
// image; it never forces one to appear on certs that hide it for other reasons.
$includeSignature = ($_POST['include_signature'] ?? '1') !== '0';
$hideSignature     = !$includeSignature;

// cert_types[] (new multi-select) — fall back to the legacy singular cert_type
// for backward compatibility with any caller still using the old radio UI.
$rawCertTypes = $_POST['cert_types'] ?? ($_POST['cert_type'] ?? 'Completion');
$rawCertTypesArr = is_array($rawCertTypes) ? $rawCertTypes : [$rawCertTypes];
$certTypes = [];
foreach ($rawCertTypesArr as $t) {
    $t = trim((string)$t);
    if (in_array($t, ['Completion', 'Certification'], true) && !in_array($t, $certTypes, true)) {
        $certTypes[] = $t;
    } elseif ($t === 'Merged' && $isF2F && !in_array('Merged', $certTypes, true)) {
        $certTypes[] = 'Merged';
    } elseif ($t === 'MergedCertification' && $isF2F && !in_array('MergedCertification', $certTypes, true)) {
        $certTypes[] = 'MergedCertification';
    }
}
if (empty($certTypes)) {
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'error' => 'Please select at least one valid certificate format for this training modality.']));
}
// Batch header's cert_type column predates multi-select and only stores one
// value (informational/denormalized) — prefer Completion, then Certification,
// falling back to Completion if only Merged was picked.
$batchHeaderCertType = in_array('Completion', $certTypes, true)
    ? 'Completion'
    : (in_array('Certification', $certTypes, true) ? 'Certification' : 'Completion');

if ($trainingTitle === '') { ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); exit(json_encode(['success' => false, 'error' => 'Training title is required.'])); }
if ($startDate === '')     { ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); exit(json_encode(['success' => false, 'error' => 'Start date is required.'])); }
if ($endDate   === '')     { ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); exit(json_encode(['success' => false, 'error' => 'End date is required.'])); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD.']));
}

$rawNames     = $_POST['participant_names']     ?? [];
$rawPositions = $_POST['participant_positions'] ?? [];
$rawAgencies  = $_POST['participant_agencies']  ?? [];
if (!is_array($rawNames))     $rawNames     = [$rawNames];
if (!is_array($rawPositions)) $rawPositions = [$rawPositions];
if (!is_array($rawAgencies))  $rawAgencies  = [$rawAgencies];

$participantNames     = [];
$participantPositions = [];
$participantAgencies  = [];
foreach ($rawNames as $i => $n) {
    $n = trim((string)$n);
    if ($n === '') continue;
    $participantNames[]     = $n;
    $participantPositions[] = mb_substr(trim((string)($rawPositions[$i] ?? '')), 0, 255);
    $participantAgencies[]  = mb_substr(trim((string)($rawAgencies[$i] ?? '')), 0, 255);
}

if (empty($participantNames)) {
    exit(json_encode(['success' => false, 'error' => 'No participant names provided.']));
}

$sessionUserId = isset($_SESSION['id']) ? (int) $_SESSION['id'] : 0;

$isLocal        = in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1']);
$currentBaseUrl = $isLocal ? 'http://localhost/csc-tms/' : 'https://otrs.cscro8.com/';

$displayDate = agencyBuildDisplayDate($startDate, $endDate);
$year        = date('Y', strtotime($startDate));

// ── DB work ───────────────────────────────────────────────────────────────────

try {
    $conn->beginTransaction();

    // Create the batch record
    $stmtBatch = $conn->prepare("
        INSERT INTO otrs_agency_batches (training_title, venue, start_date, end_date, cert_type, no_hrs, training_type, training_mode, created_by)
        VALUES (:title, :venue, :sd, :ed, :ct, :nhrs, :ttype, :tmode, :uid)
    ");
    $stmtBatch->execute([
        ':title'  => $trainingTitle,
        ':venue'  => $venue,
        ':sd'     => $startDate,
        ':ed'     => $endDate,
        ':ct'     => $batchHeaderCertType,
        ':nhrs'   => $noHrs ?: null,
        ':ttype'  => $trainingType ?: null,
        ':tmode'  => $trainingMode,
        ':uid'    => $sessionUserId ?: null,
    ]);
    $batchId = (int) $conn->lastInsertId();

    $stmtInsert = $conn->prepare("
        INSERT INTO otrs_agency_cert_records
            (batch_id, participant_name, position, agency, certificate_number, certificate_file,
             qr_code, qr_code_data, cert_type, issued_at, issued_by, status)
        VALUES
            (:bid, :name, :position, :agency, :cert_no, :cert_file,
             :qr_code, :qr_data, :ct, NOW(), :uid, 'active')
    ");

    $generatedCount = 0;
    $errors         = [];
    $results        = [];

    foreach ($participantNames as $idx => $participantName) {
        $participantPosition = $participantPositions[$idx] ?? '';
        $participantAgency    = $participantAgencies[$idx]  ?? '';

        foreach ($certTypes as $certType) {
        try {
            if ($certType === 'Merged' || $certType === 'MergedCertification') {
                // CCS-only — no otrs_agency_cert_records row.
                // Merged: Completion (left) + Appearance (right).
                // MergedCertification: Certification (left) + Appearance (right).
                $ccsCategory = $certType === 'MergedCertification' ? 'merged_certification' : 'merged';
                $certNumber = CertificateEngine::generateCertNumber($conn, $year);
                $verificationToken = bin2hex(random_bytes(32));
                $verificationUrl   = $currentBaseUrl . 'verify-certificate.php?token=' . $verificationToken;

                $qrFile = CertificateEngine::generateQRCode($certNumber, $verificationUrl);
                if (!$qrFile) throw new Exception('QR code generation failed.');

                $certFilePath = CertificateEngine::generatePDF([
                    'category'         => $ccsCategory,
                    'participant_name' => $participantName,
                    'cert_number'      => $certNumber,
                    'qr_file'          => $qrFile,
                    'training_title'   => $trainingTitle,
                    'display_date'     => $displayDate,
                    'start_date'       => $startDate,
                    'end_date'         => $endDate,
                    'venue'            => $venue,
                    'training_mode'    => $trainingMode,
                    'no_hrs'           => $noHrs,
                    'training_type'    => $trainingType,
                    'source'           => 'agency',
                    'index'            => $idx,
                    'position_title'   => $participantPosition,
                    'agency_name'      => $participantAgency,
                    'hide_signature'   => $hideSignature,
                ]);
                if (!$certFilePath) throw new Exception('PDF generation failed: file was not written to disk.');

                $certId = CertificateEngine::storeRecord($conn, [
                    'certificate_number' => $certNumber,
                    'source'             => 'agency',
                    'category'           => $ccsCategory,
                    'participant_name'   => $participantName,
                    'training_title'     => $trainingTitle,
                    'venue'              => $venue,
                    'start_date'         => $startDate,
                    'end_date'           => $endDate,
                    'no_hrs'             => $noHrs,
                    'training_type'      => $trainingType,
                    'training_mode'      => $trainingMode,
                    'batch_id'           => $batchId,
                    'certificate_file'   => $certFilePath,
                    'qr_code'            => $qrFile,
                    'qr_code_data'       => $verificationUrl,
                    'verification_token' => $verificationToken,
                    'status'             => 'active',
                    'issued_by'          => 'OTRS System',
                    'generated_by'       => $sessionUserId ?: null,
                    'position'           => $participantPosition,
                    'agency'             => $participantAgency,
                ], 'system', $sessionUserId ?: null, $_SERVER['REMOTE_ADDR'] ?? '');
            } else {
                $certNumber = agencyGenerateCertNumber($conn, $year);
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
                    'index'            => $idx,
                    'position'         => $participantPosition,
                    'agency'           => $participantAgency,
                    'hide_signature'   => $hideSignature,
                ];

                $certFilePath = agencyGenerateCertificateFile($details, $certNumber, $qrFile);
                if (!$certFilePath) throw new Exception('PDF generation failed: file was not written to disk.');

                $stmtInsert->execute([
                    ':bid'       => $batchId,
                    ':name'      => $participantName,
                    ':position'  => $participantPosition ?: null,
                    ':agency'    => $participantAgency ?: null,
                    ':cert_no'   => $certNumber,
                    ':cert_file' => $certFilePath,
                    ':qr_code'   => $qrFile,
                    ':qr_data'   => $verificationUrl,
                    ':ct'        => $certType,
                    ':uid'       => $sessionUserId ?: null,
                ]);
                $certId = (int) $conn->lastInsertId();

                // Dual-write to the CCS repository so this certificate shows up in
                // hrd/ccs_dashboard.php (which reads only from otrs_ccs_certificates).
                try {
                    CertificateEngine::storeRecord($conn, [
                        'certificate_number' => $certNumber,
                        'source'             => 'agency',
                        'category'           => strtolower($certType),
                        'participant_name'   => $participantName,
                        'training_title'     => $trainingTitle,
                        'venue'              => $venue,
                        'start_date'         => $startDate,
                        'end_date'           => $endDate,
                        'no_hrs'             => $noHrs,
                        'training_type'      => $trainingType,
                        'training_mode'      => $trainingMode,
                        'batch_id'           => $batchId,
                        'certificate_file'   => $certFilePath,
                        'qr_code'            => $qrFile,
                        'qr_code_data'       => $verificationUrl,
                        'verification_token' => $verificationToken,
                        'status'             => 'active',
                        'issued_by'          => 'OTRS System',
                        'generated_by'       => $sessionUserId ?: null,
                        'position'           => $participantPosition,
                        'agency'             => $participantAgency,
                    ], 'system', $sessionUserId ?: null, $_SERVER['REMOTE_ADDR'] ?? '');
                } catch (Throwable $ccsEx) {
                    // CCS dual-write failure must not abort the main cert generation
                    error_log('[agency_cert_issue_ajax] CCS dual-write failed for "' . $participantName . '": ' . $ccsEx->getMessage());
                }
            }

            $results[] = [
                'success'          => true,
                'format'           => $certType,
                'cert_id'          => $certId,
                'cert_number'      => $certNumber,
                'name'             => $participantName,
                'certificate_file' => $certFilePath,
            ];
            $generatedCount++;

        } catch (Throwable $ex) {
            $errors[]  = "Error for \"{$participantName}\" ({$certType}): " . $ex->getMessage();
            $results[] = ['success' => false, 'format' => $certType, 'name' => $participantName, 'error' => $ex->getMessage()];
        }
        } // end foreach certTypes
    }

    $conn->commit();

    echo json_encode([
        'success'  => $generatedCount > 0,
        'batch_id' => $batchId,
        'count'    => $generatedCount,
        'results'  => $results,
        'errors'   => $errors,
        'message'  => "Successfully generated {$generatedCount} certificate(s).",
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('[agency_cert_issue_ajax] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

