<?php
// imis/otrs/hrd/cert_view.php
// Single certificate view — serves the generated PDF from disk
// URL: cert_view.php?id={otrs_certificates.id}
// Opens the stored PDF inline; falls back to a styled error if missing.

require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

$certId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$certId) { http_response_code(400); die('Invalid certificate ID.'); }

try {
    $stmt = $conn->prepare("
        SELECT c.*, p.fname, p.mname, p.lname,
               t.name AS training_name
        FROM   otrs_certificates c
        JOIN   otrs_participants p ON p.id = c.participant_id
        JOIN   otrs_trainings   t ON t.id = c.training_id
        WHERE  c.id = :id AND c.status = 'active'
        LIMIT  1
    ");
    $stmt->execute([':id' => $certId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); die('Certificate not found or has been revoked.'); }

} catch (PDOException $e) {
    http_response_code(500);
    die('Database error.');
}

// Resolve the PDF path — check all possible base directories.
// CertificateEngine::certDir() (the current generator, used by cert_issue_ajax.php)
// writes to imis/otrs/assets/...; older candidates are kept for backward
// compatibility with files generated before that engine existed.
$relPath    = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $row['certificate_file']);
$candidates = [
    realpath(__DIR__ . '/../assets/uploads/certificates'),       // current: imis/otrs/assets/...
    realpath(__DIR__ . '/../../assets/uploads/certificates'),    // legacy: imis/assets/...
    realpath(__DIR__ . '/../../../assets/uploads/certificates'), // legacy: public_html/assets/...
];
$fullPath = null;
foreach ($candidates as $baseDir) {
    if ($baseDir === false) continue;
    $candidate = $baseDir . DIRECTORY_SEPARATOR . $relPath;
    if (file_exists($candidate)) { $fullPath = $candidate; break; }
}
if ($fullPath === null) {
    http_response_code(404);
    die('Certificate PDF not found on server. Please re-generate it.');
}

// Stream the PDF inline
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($fullPath);
exit;