<?php
// imis/otrs/hrd/ccs_cert_view.php
// Universal PDF viewer for any CCS certificate.
// URL: ccs_cert_view.php?id={otrs_ccs_certificates.id}

require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

$certId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$certId) { http_response_code(400); exit('Invalid certificate ID.'); }

try {
    $stmt = $conn->prepare("
        SELECT certificate_file, participant_name, training_title, source, status
          FROM otrs_ccs_certificates
         WHERE id = :id
         LIMIT 1
    ");
    $stmt->execute([':id' => $certId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row)                          { http_response_code(404); exit('Certificate not found.'); }
    if ($row['status'] === 'voided')    { http_response_code(410); exit('This certificate has been voided.'); }
} catch (PDOException $e) {
    http_response_code(500); exit('Database error.');
}

$relPath  = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $row['certificate_file']);
$baseDir  = realpath(__DIR__ . '/../assets/uploads/certificates');
$fullPath = $baseDir ? ($baseDir . DIRECTORY_SEPARATOR . $relPath) : '';

if (!$fullPath || !file_exists($fullPath)) {
    http_response_code(404);
    exit('Certificate PDF not found on server. Please re-issue it.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($fullPath);
exit;
