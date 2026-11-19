<?php
// imis/otrs/hrd/api/ccs_edit_ajax.php
// POST — update editable meta-fields on a otrs_ccs_certificates record.
// Does NOT regenerate the PDF — call ccs_reissue_ajax.php after editing if needed.

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed.']));
}
if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Access denied.']));
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Invalid CSRF token.']));
}

$certId = filter_input(INPUT_POST, 'cert_id', FILTER_VALIDATE_INT);
if (!$certId) {
    exit(json_encode(['success' => false, 'error' => 'Invalid certificate ID.']));
}

// Fetch source to know which fields apply
$chk = $conn->prepare('SELECT source FROM otrs_ccs_certificates WHERE id = :id LIMIT 1');
$chk->execute([':id' => $certId]);
$cert = $chk->fetch(PDO::FETCH_ASSOC);
if (!$cert) {
    exit(json_encode(['success' => false, 'error' => 'Certificate not found.']));
}
$source = $cert['source'];

// Common inputs
$participantName = trim($_POST['participant_name'] ?? '');
$trainingTitle   = trim($_POST['training_title']   ?? '');
$venue           = trim($_POST['venue']            ?? '');
$startDate       = trim($_POST['start_date']       ?? '');
$endDate         = trim($_POST['end_date']         ?? '');

if ($participantName === '' || $trainingTitle === '') {
    exit(json_encode(['success' => false, 'error' => 'Participant name and training title are required.']));
}

$allowedModes = ['face-to-face', 'online', 'hybrid', 'blended', ''];
$allowedTypes = ['Technical', 'Foundation', 'Leadership and Management', ''];
$allowedCats  = ['completion', 'certification', 'merged', 'merged_certification'];

try {
    if ($source === 'appearance') {
        $stmt = $conn->prepare("
            UPDATE otrs_ccs_certificates
               SET participant_name = :pname,
                   training_title   = :ttitle,
                   venue            = :venue,
                   start_date       = :sd,
                   end_date         = :ed
             WHERE id = :id
        ");
        $stmt->execute([
            ':pname'  => $participantName,
            ':ttitle' => $trainingTitle,
            ':venue'  => $venue,
            ':sd'     => $startDate ?: null,
            ':ed'     => $endDate   ?: null,
            ':id'     => $certId,
        ]);
    } else {
        $noHrs        = max(0, (int)($_POST['no_hrs']        ?? 0));
        $trainingType = trim($_POST['training_type']  ?? '');
        $trainingLevel= trim($_POST['training_level'] ?? '');
        $trainingMode = trim($_POST['training_mode']  ?? '');
        $category     = trim($_POST['category']       ?? 'completion');
        $position     = trim($_POST['position']        ?? '');
        $agency       = trim($_POST['agency']          ?? '');

        if (!in_array($trainingType, $allowedTypes, true)) $trainingType = '';
        if (!in_array($trainingMode, $allowedModes, true)) $trainingMode = '';
        if (!in_array($category,     $allowedCats,  true)) $category     = 'completion';

        $stmt = $conn->prepare("
            UPDATE otrs_ccs_certificates
               SET participant_name = :pname,
                   training_title   = :ttitle,
                   venue            = :venue,
                   start_date       = :sd,
                   end_date         = :ed,
                   no_hrs           = :nhrs,
                   training_type    = :ttype,
                   training_level   = :tlevel,
                   training_mode    = :tmode,
                   category         = :cat,
                   position         = :pos,
                   agency           = :agc
             WHERE id = :id
        ");
        $stmt->execute([
            ':pname'  => $participantName,
            ':ttitle' => $trainingTitle,
            ':venue'  => $venue,
            ':sd'     => $startDate   ?: null,
            ':ed'     => $endDate     ?: null,
            ':nhrs'   => $noHrs,
            ':ttype'  => $trainingType  ?: null,
            ':tlevel' => $trainingLevel ?: null,
            ':tmode'  => $trainingMode  ?: null,
            ':cat'    => $category,
            ':pos'    => $position ?: null,
            ':agc'    => $agency   ?: null,
            ':id'     => $certId,
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Certificate record updated.']);
} catch (Throwable $e) {
    error_log('[ccs_edit_ajax] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Update failed.']);
}
