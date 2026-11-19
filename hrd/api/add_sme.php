<?php
/**
 * hrd/api/add_sme.php
 * POST — add a new SME to the global otrs_smes registry.
 * Supports sme_type: 'internal' (CSC RO VIII employee) or 'external'.
 */

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}
if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

$smeType     = in_array($_POST['sme_type'] ?? '', ['internal', 'external']) ? $_POST['sme_type'] : 'external';
$userId      = ($smeType === 'internal') ? (filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?: null) : null;
$name        = trim($_POST['name']        ?? '');
$designation = trim($_POST['designation'] ?? '');
$email       = trim($_POST['email']       ?? '');
$mobileNo    = trim($_POST['mobile_no']   ?? '');
$agency      = $smeType === 'internal' ? 'Civil Service Commission – Regional Office VIII' : trim($_POST['agency'] ?? '');
$expertise   = trim($_POST['expertise']   ?? '');
$bio         = trim($_POST['bio']         ?? '');
$status      = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

if ($name === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Name is required.']);
    exit;
}

// ── Optional photo upload (external SMEs only) ─────────────────────────
$photoFilename = '';
if ($smeType === 'external' && !empty($_FILES['sme_photo']['tmp_name'])) {
    $file     = $_FILES['sme_photo'];
    $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime     = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Invalid image type. Use JPG, PNG, WebP, or GIF.']);
        exit;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Photo must be under 2 MB.']);
        exit;
    }
    $uploadDir = __DIR__ . '/../../../../assets/uploads/sme_photos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $photoFilename = 'sme_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $photoFilename)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to save photo.']);
        exit;
    }
}

try {
    $chk      = $conn->query("SHOW COLUMNS FROM otrs_smes LIKE 'photo'");
    $hasPhoto = $chk->rowCount() > 0;

    if ($hasPhoto) {
        $stmt = $conn->prepare("
            INSERT INTO otrs_smes (sme_type, user_id, name, designation, email, mobile_no, agency, expertise, bio, status, photo)
            VALUES (:sme_type, :user_id, :name, :designation, :email, :mobile_no, :agency, :expertise, :bio, :status, :photo)
        ");
        $stmt->execute([
            ':sme_type'    => $smeType,
            ':user_id'     => $userId,
            ':name'        => $name,
            ':designation' => $designation,
            ':email'       => $email,
            ':mobile_no'   => $mobileNo,
            ':agency'      => $agency,
            ':expertise'   => $expertise,
            ':bio'         => $bio,
            ':status'      => $status,
            ':photo'       => $photoFilename,
        ]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO otrs_smes (sme_type, user_id, name, designation, email, mobile_no, agency, expertise, bio, status)
            VALUES (:sme_type, :user_id, :name, :designation, :email, :mobile_no, :agency, :expertise, :bio, :status)
        ");
        $stmt->execute([
            ':sme_type'    => $smeType,
            ':user_id'     => $userId,
            ':name'        => $name,
            ':designation' => $designation,
            ':email'       => $email,
            ':mobile_no'   => $mobileNo,
            ':agency'      => $agency,
            ':expertise'   => $expertise,
            ':bio'         => $bio,
            ':status'      => $status,
        ]);
    }
    $newId = (int) $conn->lastInsertId();

    echo json_encode(['status' => 'success', 'message' => 'SME added successfully.', 'id' => $newId]);
} catch (PDOException $e) {
    error_log('[add_sme] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to add SME.']);
}
