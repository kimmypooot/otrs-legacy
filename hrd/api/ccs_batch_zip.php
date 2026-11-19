<?php
// imis/otrs/hrd/api/ccs_batch_zip.php
// GET: ids=1,2,3  → streams a ZIP of PDF files for given CCS cert IDs.
// All sources accepted; voided certs excluded.
// Missing PDF files are regenerated automatically before zipping.

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403); exit('Access denied.');
}

$rawIds = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
if (empty($rawIds)) {
    http_response_code(400); exit('ids parameter required (comma-separated CCS certificate IDs).');
}
if (count($rawIds) > 500) {
    http_response_code(400); exit('Maximum 500 IDs per ZIP request.');
}

$certBaseDir = __DIR__ . '/../../assets/uploads/certificates/';

try {
    $placeholders = implode(',', array_fill(0, count($rawIds), '?'));
    $stmt = $conn->prepare("
        SELECT id, certificate_file, participant_name, certificate_number, source
          FROM otrs_ccs_certificates
         WHERE id IN ({$placeholders}) AND status != 'voided'
    ");
    $stmt->execute($rawIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[ccs_batch_zip] ' . $e->getMessage());
    http_response_code(500); exit('Server error.');
}

// Collect which IDs need regeneration (file missing on disk)
$missingIds = [];
foreach ($rows as $r) {
    $absPath = $certBaseDir . ltrim($r['certificate_file'], '/');
    if (!file_exists($absPath)) {
        $missingIds[] = (int)$r['id'];
    }
}

// Regenerate missing PDFs using CertificateEngine::reissue()
if (!empty($missingIds)) {
    require_once __DIR__ . '/../../../../vendor/tcpdf/tcpdf.php';
    require_once __DIR__ . '/../../../../vendor/fpdi/src/autoload.php';
    require_once __DIR__ . '/../../../../vendor/phpqrcode/qrlib.php';
    require_once __DIR__ . '/CertificateEngine.php';

    $actorId = (int)($_SESSION['id'] ?? 0);
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

    foreach ($missingIds as $ccsId) {
        try {
            $result = CertificateEngine::reissue($conn, $ccsId, $actorId, $ip);
            // Update the in-memory row so the ZIP loop picks up the new path
            foreach ($rows as &$r) {
                if ((int)$r['id'] === $ccsId) {
                    $r['certificate_file'] = $result['cert_file'];
                    break;
                }
            }
            unset($r);
        } catch (Throwable $e) {
            error_log("[ccs_batch_zip] reissue failed for cert #{$ccsId}: " . $e->getMessage());
            // Continue — include whatever files we can successfully regenerate
        }
    }
}

$files = [];
foreach ($rows as $r) {
    $absPath = $certBaseDir . ltrim($r['certificate_file'], '/');
    if (file_exists($absPath)) {
        $safeName = preg_replace('/[^A-Za-z0-9_\- .]/', '_', $r['participant_name']);
        $certNo   = preg_replace('/[^A-Za-z0-9_-]/', '_', $r['certificate_number'] ?? '');
        $files[]  = ['path' => $absPath,
                     'name' => $certNo !== '' ? "{$safeName}_{$certNo}.pdf" : "{$safeName}.pdf"];
    }
}

if (empty($files)) {
    http_response_code(404); exit('No certificate files found for the requested IDs.');
}

$tmpZip = tempnam(sys_get_temp_dir(), 'ccszip_');
$zip    = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); exit('Could not create ZIP archive.');
}

$seen = [];
foreach ($files as $f) {
    $name = $f['name'];
    if (isset($seen[$name])) {
        $seen[$name]++;
        $name = pathinfo($name, PATHINFO_FILENAME) . '_' . $seen[$f['name']] . '.pdf';
    } else {
        $seen[$name] = 1;
    }
    $zip->addFile($f['path'], $name);
}
$zip->close();

$zipName = 'CCS_Certificates_' . date('Y-m-d') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache');
readfile($tmpZip);
@unlink($tmpZip);
