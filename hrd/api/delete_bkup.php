<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

// Accept only the filename (no directory separators) to prevent path traversal.
$filename = basename(trim($_POST['file'] ?? ''));

if ($filename === '' || $filename === '.' || $filename === '..') {
    echo json_encode(['success' => false, 'message' => 'Invalid filename.']);
    exit;
}

$backupDir = realpath(__DIR__ . '/../db_backup');
$filePath  = $backupDir . DIRECTORY_SEPARATOR . $filename;

if (!$backupDir || strpos($filePath, $backupDir) !== 0 || !is_file($filePath)) {
    echo json_encode(['success' => false, 'message' => 'File not found.']);
    exit;
}

if (unlink($filePath)) {
    echo json_encode(['success' => true, 'message' => 'File deleted successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error deleting the file.']);
}
?>
