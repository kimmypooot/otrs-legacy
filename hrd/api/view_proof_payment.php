<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    die('Access denied.');
}

$regId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$regId) {
    http_response_code(400);
    die('Invalid registration ID.');
}

try {
    $stmt = $conn->prepare(
        "SELECT proof_file FROM otrs_registrations WHERE id = :id LIMIT 1"
    );
    $stmt->bindParam(':id', $regId, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database error.');
}

if (!$result || empty($result['proof_file']) || trim($result['proof_file']) === 'outputs/') {
    http_response_code(404);
    die('No proof of payment file found.');
}

$relativePath = ltrim($result['proof_file'], '/');
$otrsRoot     = realpath(__DIR__ . '/../../../../otrs');
$fullPath     = $otrsRoot . '/' . $relativePath;

// Prevent path traversal
if (strpos(realpath($fullPath), $otrsRoot) !== 0) {
    http_response_code(403);
    die('Access denied.');
}

if (!file_exists($fullPath)) {
    http_response_code(404);
    die('File not found on server.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

if (ob_get_level()) ob_end_clean();

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . addslashes(basename($fullPath)) . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, no-store');

readfile($fullPath);
exit;
