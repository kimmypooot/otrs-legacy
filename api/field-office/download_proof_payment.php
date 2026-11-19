<?php
ob_start();
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
ob_clean();

if (empty($_SESSION['username'])) { http_response_code(401); die('Unauthorized.'); }

$enrollmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$enrollmentId) { http_response_code(400); die('Invalid ID.'); }

$userLocation = strtolower($_SESSION['type'] ?? '');

try {
    $stmt = $conn->prepare("
        SELECT r.proof_file
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.id              = :eid
          AND  UPPER(p.location) = UPPER(:loc)
        LIMIT  1
    ");
    $stmt->execute([':eid' => $enrollmentId, ':loc' => $userLocation]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[fo/download_proof_payment] ' . $e->getMessage());
    http_response_code(500); die('Database error.');
}

if (!$row || empty($row['proof_file']) || trim($row['proof_file']) === 'outputs/') {
    http_response_code(404); die('File not found.');
}

$relativePath = ltrim($row['proof_file'], '/');
$otrsRoot     = realpath(__DIR__ . '/../../../../otrs');
$fullPath     = $otrsRoot . '/' . $relativePath;

if (!$fullPath || strpos(realpath($fullPath), $otrsRoot) !== 0 || !file_exists($fullPath)) {
    http_response_code(404); die('File not found on server.');
}

$ext     = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$mime    = $mimeMap[$ext] ?? 'application/octet-stream';

if (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes(basename($fullPath)) . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, no-store');
readfile($fullPath);
exit;
