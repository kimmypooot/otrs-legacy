<?php
// api/download_proof_payment.php
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    die('Error: Access denied.');
}

if (!isset($_GET['id'])) {
    die('Error: Invalid request - Registration ID not provided.');
}

$regId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
if ($regId === false || $regId <= 0) {
    die('Error: Invalid registration ID.');
}

try {
    $stmt = $conn->prepare(
        "SELECT proof_file FROM otrs_registrations WHERE id = :id LIMIT 1"
    );
    $stmt->bindParam(':id', $regId, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result || empty($result['proof_file']) || trim($result['proof_file']) === '' || trim($result['proof_file']) === 'outputs/') {
        die('Error: No proof of payment file found for this registration.');
    }

    $relativePath = ltrim($result['proof_file'], '/');
    $otrsRoot     = realpath(__DIR__ . '/../../../../otrs');
    $fullPath     = $otrsRoot . '/' . $relativePath;

    // Prevent path traversal
    if (!$fullPath || strpos(realpath($fullPath), $otrsRoot) !== 0) {
        http_response_code(403);
        die('Error: Access denied.');
    }

    if (!file_exists($fullPath)) {
        http_response_code(404);
        die('Error: File not found on server.');
    }

    $filename = basename($fullPath);
    $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeMap  = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';

    if (ob_get_level()) ob_end_clean();

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: private, no-store');

    readfile($fullPath);
    exit;

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>
