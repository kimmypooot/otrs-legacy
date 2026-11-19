<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

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

$regId = filter_input(INPUT_POST, 'participantId', FILTER_VALIDATE_INT);
if (!$regId) {
    echo json_encode(['success' => false, 'message' => 'Invalid registration ID.']);
    exit;
}

// ── File validation ────────────────────────────────────────────────────────────
if (empty($_FILES['proofFile']) || $_FILES['proofFile']['error'] !== UPLOAD_ERR_OK) {
    $errCodes = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit.',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
    ];
    $code = $_FILES['proofFile']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'message' => $errCodes[$code] ?? 'Upload error.']);
    exit;
}

$file         = $_FILES['proofFile'];
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
$allowedExts  = ['pdf', 'jpg', 'jpeg', 'png'];
$finfo        = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($file['tmp_name']);

if (!in_array($detectedMime, $allowedMimes, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only PDF, JPG, PNG accepted.']);
    exit;
}

$ext = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExts, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file extension.']);
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File exceeds the 5 MB limit.']);
    exit;
}

// ── Look up registration details ───────────────────────────────────────────────
try {
    $stmt = $conn->prepare("
        SELECT t.name        AS training_name,
               t.start_date,
               p.fname, p.mname, p.lname
        FROM   otrs_registrations r
        JOIN   otrs_trainings     t ON t.id = r.training_id
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.id = :rid
        LIMIT  1
    ");
    $stmt->execute([':rid' => $regId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[upload_proof_payment HRD] lookup: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Registration not found.']);
    exit;
}

$trainingName    = $row['training_name'];
$trainingDate    = $row['start_date'];
$participantName = trim($row['lname'] . ', ' . $row['fname'] . ($row['mname'] ? ' ' . $row['mname'][0] . '.' : ''));

// ── Build save path (mirrors public otrs/api/submit_proof_payment.php) ─────────
$safeTraining    = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', $trainingName);
$safeDate        = preg_replace('/[^A-Za-z0-9_\-]/', '_', $trainingDate);
$safeParticipant = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', $participantName);

// Saves into the public otrs filesystem so files are accessible from otrs.cscro8.com
$uploadDir = __DIR__ . '/../../../otrs/outputs/' . $safeTraining . '/' . $safeDate . '/' . $safeParticipant . '/Proof_of_Payment/';
$fileName  = $safeParticipant . '_POP.' . $ext;
$destPath  = $uploadDir . $fileName;
$relPath   = 'outputs/' . $safeTraining . '/' . $safeDate . '/' . $safeParticipant . '/Proof_of_Payment/' . $fileName;

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    echo json_encode(['success' => false, 'message' => 'Could not create upload directory.']);
    exit;
}

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
    exit;
}

// ── Update database ────────────────────────────────────────────────────────────
try {
    $conn->prepare("
        UPDATE otrs_registrations
        SET    proof_file  = :proof,
               updated_at  = NOW()
        WHERE  id = :rid
    ")->execute([':proof' => $relPath, ':rid' => $regId]);

    echo json_encode(['success' => true, 'message' => 'Proof file uploaded successfully.']);
} catch (PDOException $e) {
    if (file_exists($destPath)) unlink($destPath);
    error_log('[upload_proof_payment HRD] db: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
