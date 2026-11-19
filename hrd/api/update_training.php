<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

// ── 1. Collect & sanitise input ───────────────────────────────────────────────
$trainingId    = (int)   trim($_POST['trainingId']    ?? 0);
$name          = trim($_POST['editName']              ?? '');
$location      = trim($_POST['editLocation']          ?? '');
$start_date    = trim($_POST['editStartDate']         ?? '');
$end_date      = trim($_POST['editEndDate']           ?? '');
$link          = trim($_POST['editLink']              ?? '');
$deadline      = trim($_POST['editDeadline']          ?? '');
$mode          = trim($_POST['editMode']              ?? '');
$status_trng   = trim($_POST['editStatusTrng']        ?? '');
$target        = trim($_POST['editTarget']            ?? '');
$level         = trim($_POST['editLevel']             ?? '');
$description   = trim($_POST['editDescription']       ?? '');
$training_type = trim($_POST['training_type']         ?? 'Public-Offered');
$status        = strtolower(trim($_POST['editStatus'] ?? ''));

$slots         = max(0, (int)   ($_POST['editSlots']    ?? 0));
$no_hrs        = max(0, (int)   ($_POST['editNoHrs']    ?? 0));
$fee           = max(0, (float) str_replace(',', '', $_POST['editFee'] ?? '0'));
$force_display = isset($_POST['force_display']) && $_POST['force_display'] === '1' ? 1 : 0;

if ($training_type === 'Free') {
    $fee = 0.00;
}

$trainingDatesRaw = $_POST['training_dates'] ?? [];
$trainingDates    = [];
if (is_array($trainingDatesRaw) && !empty($trainingDatesRaw)) {
    foreach ($trainingDatesRaw as $d) {
        $d = trim((string)$d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) &&
            checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) {
            $trainingDates[$d] = $d;
        }
    }
    $trainingDates = array_values($trainingDates);
    sort($trainingDates);
}

if (!empty($trainingDates)) {
    $start_date = $trainingDates[0];
    $end_date   = $trainingDates[count($trainingDates) - 1];
}

// ── 2. Whitelist validation ───────────────────────────────────────────────────
$validTypes      = ['Public-Offered', 'Free', 'Congress-Conducted'];
$validModes      = ['Face to Face', 'Online', 'Hybrid'];
$validLevels     = ['Leadership and Management', 'Foundation', 'Technical'];
$validStatuses   = ['active', 'inactive'];
$validTrngStatus = [
    'upcoming', 'ongoing', 'completed', 'cancelled', 'rescheduled',
    'ongoing registration', 'accepting payments', 'scheduled until further notice',
];

$errors = [];

if ($trainingId < 1)                                 $errors[] = 'Training ID is missing or invalid.';
if (empty($name))                                    $errors[] = 'Training name is required.';
if (empty($location))                                $errors[] = 'Location is required.';
if (empty($start_date))                              $errors[] = 'Start date is required.';
if (empty($end_date))                                $errors[] = 'End date is required.';
if (!empty($link) && !filter_var($link, FILTER_VALIDATE_URL)) $errors[] = 'Registration URL must be valid (must start with http:// or https://).';
if (empty($deadline))                                $errors[] = 'Deadline is required.';
if (empty($target))                                  $errors[] = 'Target participants is required.';
if (empty($description))                             $errors[] = 'Description is required.';
if (!in_array($training_type, $validTypes, true))    $errors[] = 'Invalid training classification.';
if (!in_array($mode, $validModes, true))             $errors[] = 'Invalid training mode.';
if (!in_array($level, $validLevels, true))           $errors[] = 'Invalid curriculum area.';
if (!in_array($status, $validStatuses, true))        $errors[] = 'Invalid public status.';
if (!in_array($status_trng, $validTrngStatus, true)) $errors[] = 'Invalid training status.';

$startDt = $endDt = $deadlineDt = null;
try {
    $startDt    = new DateTime($start_date);
    $endDt      = new DateTime($end_date);
    $deadlineDt = new DateTime($deadline);

    if ($endDt < $startDt)      $errors[] = 'End date cannot be earlier than start date.';
    if ($deadlineDt > $startDt) $errors[] = 'Deadline must be on or before the start date.';
} catch (Exception $e) {
    $errors[] = 'One or more dates are invalid.';
}

if (!empty($errors)) {
    echo json_encode(['status' => 'error', 'message' => implode(' ', $errors)]);
    exit;
}

// ── 3. Database operation ─────────────────────────────────────────────────────
try {
    // Duplicate name+date check (excludes the training being edited)
    $dup = $conn->prepare(
        "SELECT id FROM otrs_trainings
         WHERE name = :name AND start_date = :start AND end_date = :end AND id != :id LIMIT 1"
    );
    $dup->execute([':name' => $name, ':start' => $start_date, ':end' => $end_date, ':id' => $trainingId]);
    if ($dup->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'A training with this name and date range already exists.']);
        exit;
    }

    $check = $conn->prepare("SELECT id, is_invite_only FROM otrs_trainings WHERE id = :id LIMIT 1");
    $check->execute([':id' => $trainingId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        echo json_encode(['status' => 'error', 'message' => 'Training record not found.']);
        exit;
    }
    if (!empty($existing['is_invite_only']) && $training_type !== 'Public-Offered') {
        echo json_encode(['status' => 'error', 'message' => 'This training is invite-only, which only applies to Public-Offered trainings. Change the classification is not allowed while invite-only is set.']);
        exit;
    }

    $sql = "
        UPDATE otrs_trainings SET
            name                  = :name,
            start_date            = :start_date,
            end_date              = :end_date,
            registration_link     = :link,
            registration_deadline = :deadline,
            location              = :location,
            fee                   = :fee,
            mode                  = :mode,
            type                  = :type,
            training_status       = :status_trng,
            target_audience       = :target,
            level                 = :level,
            slots                 = :slots,
            no_hrs                = :no_hrs,
            description           = :description,
            status                = :status,
            force_display         = :force_display
        WHERE id = :id
    ";

    $conn->beginTransaction();

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':name'          => $name,
        ':start_date'    => $start_date,
        ':end_date'      => $end_date,
        ':link'          => $link,
        ':deadline'      => $deadline,
        ':location'      => $location,
        ':fee'           => $fee,
        ':mode'          => $mode,
        ':type'          => $training_type,
        ':status_trng'   => $status_trng,
        ':target'        => $target,
        ':level'         => $level,
        ':slots'         => $slots,
        ':no_hrs'        => $no_hrs,
        ':description'   => $description,
        ':status'        => $status,
        ':force_display' => $force_display,
        ':id'            => $trainingId,
    ]);

    // Replace otrs_training_dates for this training: always delete first, so
    // toggling custom mode OFF (no training_dates[] submitted) correctly
    // reverts the training to legacy range mode.
    $conn->prepare("DELETE FROM otrs_training_dates WHERE training_id = :tid")
         ->execute([':tid' => $trainingId]);

    if (!empty($trainingDates)) {
        $insDate = $conn->prepare(
            "INSERT INTO otrs_training_dates (training_id, training_date, day_number)
             VALUES (:tid, :date, :dn)"
        );
        foreach ($trainingDates as $i => $d) {
            $insDate->execute([':tid' => $trainingId, ':date' => $d, ':dn' => $i + 1]);
        }
    }

    $conn->commit();

    echo json_encode([
        'status'  => 'success',
        'message' => 'Training updated successfully.',
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('update_training.php PDO error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred. Please try again.']);
}
