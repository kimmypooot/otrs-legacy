<?php
// imis/otrs/hrd/api/ccs_preview_ajax.php
// Streams a sample certificate PDF rendered by CertificateEngine, using either
// placeholder sample data or HRD-supplied overrides. Nothing is written to the
// database and the generated PDF/QR files are deleted immediately after the
// response is sent — this is a visual template preview only, not a real issuance.

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/../../../../vendor/tcpdf/tcpdf.php';
require_once __DIR__ . '/../../../../vendor/fpdi/src/autoload.php';
require_once __DIR__ . '/../../../../vendor/phpqrcode/qrlib.php';
require_once __DIR__ . '/CertificateEngine.php';

$allowedCategories = ['completion', 'certification', 'appearance', 'merged', 'merged_certification'];
$allowedSources    = ['public_offered', 'agency', 'internal', 'appearance'];
$allowedModes      = ['face-to-face', 'online', 'hybrid', 'blended'];

$category = strtolower(trim($_GET['category'] ?? 'completion'));
if (!in_array($category, $allowedCategories, true)) $category = 'completion';

$source = strtolower(trim($_GET['source'] ?? ($category === 'appearance' ? 'appearance' : 'agency')));
if (!in_array($source, $allowedSources, true)) $source = 'agency';

$mode = strtolower(trim($_GET['training_mode'] ?? 'face-to-face'));
if (!in_array($mode, $allowedModes, true)) $mode = 'face-to-face';

$participantName = trim($_GET['participant_name'] ?? '') ?: 'Juan Dela Cruz';
$trainingTitle    = trim($_GET['training_title'] ?? '') ?: 'Sample Training Program on Public Service Excellence';
$venue            = trim($_GET['venue'] ?? '') ?: 'CSC Regional Office VIII, Candahug, Palo, Leyte';
$referenceNo      = trim($_GET['reference_no'] ?? '') ?: 'PREVIEW-0000';

$noHrs = filter_var($_GET['no_hrs'] ?? 24, FILTER_VALIDATE_INT);
if ($noHrs === false || $noHrs < 0) $noHrs = 24;

$trainingType  = trim($_GET['training_type'] ?? '') ?: 'Technical';
$trainingLevel = trim($_GET['training_level'] ?? '') ?: 'Regular';

$positionTitle = trim($_GET['position_title'] ?? '') ?: 'Information Technology Officer II';
$agencyName    = trim($_GET['agency_name'] ?? '') ?: 'Civil Service Commission Regional Office VIII';

$startDate = trim($_GET['start_date'] ?? '');
$startDate = (DateTime::createFromFormat('Y-m-d', $startDate)) ? $startDate : date('Y-m-d');
$endDate   = trim($_GET['end_date'] ?? '');
$endDate   = (DateTime::createFromFormat('Y-m-d', $endDate)) ? $endDate : $startDate;

$certNumber  = 'CERT-PREVIEW-000000';
$verifyUrl   = CertificateEngine::makeVerificationUrl('preview-' . bin2hex(random_bytes(8)));
$qrFile      = CertificateEngine::generateQRCode($certNumber, $verifyUrl);

$relPath = CertificateEngine::generatePDF([
    'category'         => $category,
    'participant_name' => $participantName,
    'cert_number'      => $certNumber,
    'qr_file'          => $qrFile ?: '',
    'training_title'   => $trainingTitle,
    'display_date'     => CertificateEngine::buildDisplayDate($startDate, $endDate),
    'start_date'       => $startDate,
    'end_date'         => $endDate,
    'venue'            => $venue,
    'training_mode'    => $mode,
    'no_hrs'           => $noHrs,
    'training_type'    => $trainingType,
    'training_level'   => $trainingLevel,
    'source'           => $source,
    'reference_no'     => $referenceNo,
    'position_title'   => $positionTitle,
    'agency_name'      => $agencyName,
    'index'            => 0,
]);

// ── Cleanup helper: delete generated PDF + QR and prune now-empty folders ──
$cleanup = function () use ($relPath, $qrFile) {
    $certDir = realpath(__DIR__ . '/../../assets/uploads/certificates');
    if ($relPath && $certDir) {
        $abs = $certDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath);
        if (file_exists($abs)) @unlink($abs);
        $dir = dirname($abs);
        while ($dir && $dir !== $certDir && is_dir($dir) && count(glob($dir . DIRECTORY_SEPARATOR . '*')) === 0) {
            @rmdir($dir);
            $dir = dirname($dir);
        }
    }
    if ($qrFile) {
        $qrDir = realpath(__DIR__ . '/../../assets/uploads/qrcodes');
        if ($qrDir) {
            $qrAbs = $qrDir . DIRECTORY_SEPARATOR . $qrFile;
            if (file_exists($qrAbs)) @unlink($qrAbs);
        }
    }
};

if (!$relPath) {
    http_response_code(500);
    exit('Preview generation failed.');
}

$certDir  = realpath(__DIR__ . '/../../assets/uploads/certificates');
$fullPath = $certDir ? ($certDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath)) : '';

if (!$fullPath || !file_exists($fullPath)) {
    http_response_code(500);
    exit('Preview file not found after generation.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="template_preview.pdf"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: no-store, must-revalidate');
readfile($fullPath);
$cleanup();
exit;
