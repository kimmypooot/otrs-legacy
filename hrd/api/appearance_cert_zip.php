<?php
// imis/otrs/hrd/api/appearance_cert_zip.php
// GET: batch_id=N  → streams a ZIP of all appearance certs in that batch.
// Also accepts: ids=1,2,3  → ZIP for specific CCS cert IDs.

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit('Access denied.');
}

$certBaseDir = __DIR__ . '/../../../assets/uploads/certificates/';
$files       = [];
$zipLabel    = 'appearance_certificates';

try {
    if (!empty($_GET['batch_id'])) {
        $batchId = (int)$_GET['batch_id'];
        $stmt    = $conn->prepare("
            SELECT certificate_file, participant_name
              FROM otrs_ccs_certificates
             WHERE batch_id = :bid
               AND source   = 'appearance'
               AND status  != 'voided'
             ORDER BY id
        ");
        $stmt->execute([':bid' => $batchId]);

        // Grab batch title for the ZIP name
        $bStmt = $conn->prepare("SELECT activity_title, start_date FROM otrs_ccs_appearance_batches WHERE id = :id LIMIT 1");
        $bStmt->execute([':id' => $batchId]);
        $batch = $bStmt->fetch(PDO::FETCH_ASSOC);
        if ($batch) {
            $safeTitle = preg_replace('/[^A-Za-z0-9_-]/', '_', $batch['activity_title']);
            $date      = date('Y-m-d', strtotime($batch['start_date']));
            $zipLabel  = "Appearance_{$safeTitle}_{$date}";
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $absPath = $certBaseDir . ltrim($row['certificate_file'], '/');
            if (file_exists($absPath)) {
                $safeName = preg_replace('/[^A-Za-z0-9_\- .]/', '_', $row['participant_name']);
                $files[]  = ['path' => $absPath, 'name' => "{$safeName}.pdf"];
            }
        }

    } elseif (!empty($_GET['ids'])) {
        $rawIds = array_filter(array_map('intval', explode(',', $_GET['ids'])));
        if (empty($rawIds)) {
            http_response_code(400); exit('No valid IDs.');
        }
        $placeholders = implode(',', array_fill(0, count($rawIds), '?'));
        $stmt = $conn->prepare("
            SELECT certificate_file, participant_name
              FROM otrs_ccs_certificates
             WHERE id IN ({$placeholders})
               AND source = 'appearance'
               AND status != 'voided'
        ");
        $stmt->execute($rawIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $absPath = $certBaseDir . ltrim($row['certificate_file'], '/');
            if (file_exists($absPath)) {
                $safeName = preg_replace('/[^A-Za-z0-9_\- .]/', '_', $row['participant_name']);
                $files[]  = ['path' => $absPath, 'name' => "{$safeName}.pdf"];
            }
        }
        $zipLabel = 'Appearance_Selected_' . date('Y-m-d');
    } else {
        http_response_code(400); exit('batch_id or ids required.');
    }

} catch (Throwable $e) {
    error_log('[appearance_cert_zip] ' . $e->getMessage());
    http_response_code(500); exit('Server error.');
}

if (empty($files)) {
    http_response_code(404); exit('No certificate files found for this batch.');
}

// Stream ZIP
$tmpZip = tempnam(sys_get_temp_dir(), 'appzip_');
$zip    = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); exit('Could not create ZIP archive.');
}

$nameCounts = [];
foreach ($files as $f) {
    $base = $f['name'];
    if (isset($nameCounts[$base])) {
        $nameCounts[$base]++;
        $base = pathinfo($base, PATHINFO_FILENAME) . '_' . $nameCounts[$base] . '.pdf';
    } else {
        $nameCounts[$base] = 1;
    }
    $zip->addFile($f['path'], $base);
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipLabel . '.zip"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache');
readfile($tmpZip);
@unlink($tmpZip);
