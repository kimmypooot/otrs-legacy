<?php
/**
 * hrd/api/update_sme.php
 * POST — update an existing SME in the global otrs_smes registry.
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

$id          = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
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

if (!$id || $name === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'ID and name are required.']);
    exit;
}

// ── Optional photo upload (external only) ─────────────────────────────
$newPhoto  = null; // null = keep existing
$clearPhoto = (isset($_POST['clear_photo']) && $_POST['clear_photo'] === '1');

if ($smeType === 'external' && !empty($_FILES['sme_photo']['tmp_name'])) {
    $file    = $_FILES['sme_photo'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime    = mime_content_type($file['tmp_name']);
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
    $newPhoto = 'sme_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $newPhoto)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to save photo.']);
        exit;
    }
}

try {
    $chk      = $conn->query("SHOW COLUMNS FROM otrs_smes LIKE 'photo'");
    $hasPhoto = $chk->rowCount() > 0;

    if ($hasPhoto) {
        // Fetch old photo to delete if replaced or cleared
        $old = $conn->prepare("SELECT photo FROM otrs_smes WHERE id = :id LIMIT 1");
        $old->execute([':id' => $id]);
        $oldPhoto = $old->fetchColumn() ?: '';

        // Determine final photo value
        if ($newPhoto !== null) {
            $finalPhoto = $newPhoto;
        } elseif ($clearPhoto) {
            $finalPhoto = '';
        } else {
            $finalPhoto = $oldPhoto;
        }

        $stmt = $conn->prepare("
            UPDATE otrs_smes
            SET sme_type=:sme_type, user_id=:user_id, name=:name, designation=:designation,
                email=:email, mobile_no=:mobile_no, agency=:agency,
                expertise=:expertise, bio=:bio, status=:status, photo=:photo
            WHERE id=:id
        ");
        $stmt->execute([
            ':id'          => $id,
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
            ':photo'       => $finalPhoto,
        ]);

        // Delete old file only after a successful DB update
        if (($newPhoto !== null || $clearPhoto) && $oldPhoto !== '') {
            $oldPath = __DIR__ . '/../../../../assets/uploads/sme_photos/' . $oldPhoto;
            if (is_file($oldPath)) @unlink($oldPath);
        }
    } else {
        $stmt = $conn->prepare("
            UPDATE otrs_smes
            SET sme_type=:sme_type, user_id=:user_id, name=:name, designation=:designation,
                email=:email, mobile_no=:mobile_no, agency=:agency,
                expertise=:expertise, bio=:bio, status=:status
            WHERE id=:id
        ");
        $stmt->execute([
            ':id'          => $id,
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

    echo json_encode(['status' => 'success', 'message' => 'SME updated successfully.']);
} catch (PDOException $e) {
    error_log('[update_sme] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update SME.']);
}
