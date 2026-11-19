<?php
// imis/otrs/hrd/api/agency_cert_zip.php
// Streams a ZIP archive of all active certificates in an agency batch.
// GET: batch_id={int}

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit('Access denied.');
}

$batchId = filter_input(INPUT_GET, 'batch_id', FILTER_VALIDATE_INT);
if (!$batchId) { http_response_code(400); exit('Invalid batch ID.'); }

try {
    $stmtB = $conn->prepare("SELECT training_title, start_date FROM otrs_agency_batches WHERE id = :id LIMIT 1");
    $stmtB->execute([':id' => $batchId]);
    $batch = $stmtB->fetch(PDO::FETCH_ASSOC);
    if (!$batch) { http_response_code(404); exit('Batch not found.'); }

    // Legacy agency records (Completion / Certification)
    $stmtR = $conn->prepare("
        SELECT participant_name, certificate_number, certificate_file
        FROM   otrs_agency_cert_records
        WHERE  batch_id = :bid AND status = 'active'
        ORDER  BY participant_name
    ");
    $stmtR->execute([':bid' => $batchId]);
    $legacyRecords = $stmtR->fetchAll(PDO::FETCH_ASSOC);

    // CCS records for this batch (Merged / MergedCertification — written only to otrs_ccs_certificates)
    $stmtC = $conn->prepare("
        SELECT participant_name, certificate_number, certificate_file
        FROM   otrs_ccs_certificates
        WHERE  batch_id = :bid AND source = 'agency' AND status != 'voided'
        ORDER  BY participant_name
    ");
    $stmtC->execute([':bid' => $batchId]);
    $ccsRecords = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    $records = array_merge($legacyRecords, $ccsRecords);

} catch (PDOException $e) {
    http_response_code(500);
    exit('Database error.');
}

if (empty($records)) {
    http_response_code(404);
    exit('No certificates found for this batch.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZIP extension not available on this server.');
}

// CertificateEngine writes files relative to otrs/assets/uploads/certificates/
$baseDir   = realpath(__DIR__ . '/../../assets/uploads/certificates');
$tmpZip    = tempnam(sys_get_temp_dir(), 'agency_cert_') . '.zip';
$zip       = new ZipArchive();
$opened    = $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($opened !== true) { http_response_code(500); exit('Could not create ZIP archive.'); }

$added  = 0;
$seen   = [];
foreach ($records as $r) {
    if (empty($r['certificate_file'])) continue;
    $relPath  = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $r['certificate_file']), DIRECTORY_SEPARATOR);
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $relPath;
    if (!file_exists($fullPath)) continue;

    $safeName = preg_replace('/[^A-Za-z0-9 _\-\.]/', '_', $r['participant_name']);
    $certNo   = $r['certificate_number'] ?? ('cert_' . $added);
    $zipName  = trim($safeName) . ' (' . $certNo . ').pdf';
    // Deduplicate ZIP entry names
    if (isset($seen[$zipName])) {
        $seen[$zipName]++;
        $zipName = trim($safeName) . ' (' . $certNo . ')_' . $seen[$zipName] . '.pdf';
    } else {
        $seen[$zipName] = 1;
    }
    $zip->addFile($fullPath, $zipName);
    $added++;
}
$zip->close();

if ($added === 0) {
    @unlink($tmpZip);
    http_response_code(404);
    exit('No certificate files found on disk.');
}

$safeTitle    = preg_replace('/[^A-Za-z0-9 _\-]/', '_', $batch['training_title']);
$dateStr      = date('Ymd', strtotime($batch['start_date']));
$downloadName = "Certificates_{$safeTitle}_{$dateStr}.zip";

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: private, max-age=0');
readfile($tmpZip);
@unlink($tmpZip);
exit;
