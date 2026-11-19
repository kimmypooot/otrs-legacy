<?php
ob_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

// ── Input collection ──────────────────────────────────────────────────────
$enrollmentId          = filter_input(INPUT_POST, 'enrollmentId',          FILTER_VALIDATE_INT);
$trainingId            = filter_input(INPUT_POST, 'trainingId',            FILTER_VALIDATE_INT);
$originalParticipantId = filter_input(INPUT_POST, 'originalParticipantId', FILTER_VALIDATE_INT);
$replacementReason     = mb_substr(trim($_POST['replacement_reason'] ?? ''), 0, 2000);

if (!$enrollmentId || !$trainingId || !$originalParticipantId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required identifiers.']);
    exit;
}
if ($replacementReason === '') {
    echo json_encode(['status' => 'error', 'message' => 'Replacement reason is required.']);
    exit;
}

// ── New participant fields ─────────────────────────────────────────────────
$s = fn(string $k, int $max = 254): string =>
    mb_substr(trim($_POST[$k] ?? ''), 0, $max);

$fname             = $s('new_fname', 64);
$mname             = $s('new_mname', 64);
$lname             = $s('new_lname', 64);
$suffix            = $s('new_suffix', 16) ?: null;
$email             = $s('new_email', 64);
$mobileNo          = $s('new_mobile_no', 16);
$gender            = $s('new_gender', 32);
$dob               = $s('new_dob', 10) ?: null;
$civilStatus       = $s('new_civil_status', 16);
$pwd               = in_array($_POST['new_pwd'] ?? '', ['Yes', 'No'], true) ? $_POST['new_pwd'] : 'No';
$salaryGrade       = $s('new_salary_grade', 12);
$positionTitle     = $s('new_position_title');
$positionLevel     = $s('new_position_level', 64);
$employmentStatus  = $s('new_employment_status', 64);
$civilServant      = $s('new_civil_servant', 16);
$agencyUnit        = $s('new_agency_unit');
$agencyName        = $s('new_agency_name');
$sector            = $s('new_sector', 64);
$geo               = $s('new_geo', 32);
$location          = $s('new_location', 8);
$agencyAddress     = $s('new_agency_address');
$homeAddress       = $s('new_home_address');
$foodRestrictions  = $s('new_food_restrictions');

if ($fname === '' || $lname === '') {
    echo json_encode(['status' => 'error', 'message' => 'First name and last name are required.']);
    exit;
}

$replacedBy         = $_SESSION['name'] ?? $_SESSION['username'];
$replacedByLocation = strtolower($_SESSION['type'] ?? 'hrd');

try {
    // Verify enrollment exists and belongs to the claimed participant + training
    $check = $conn->prepare("
        SELECT r.id, r.registration_status, r.training_id
        FROM   otrs_registrations r
        WHERE  r.id             = :eid
          AND  r.participant_id = :pid
          AND  r.training_id    = :tid
        LIMIT 1
    ");
    $check->execute([':eid' => $enrollmentId, ':pid' => $originalParticipantId, ':tid' => $trainingId]);
    $reg = $check->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        echo json_encode(['status' => 'error', 'message' => 'Registration record not found.']);
        exit;
    }

    $conn->beginTransaction();

    // 1. Create new participant record
    $insP = $conn->prepare("
        INSERT INTO otrs_participants
            (fname, mname, lname, suffix, email, mobile_no, gender, dob, civil_status, pwd,
             salary_grade, position_title, position_level, employment_status, civil_servant,
             agency_unit, agency_name, sector, geo, location, agency_address, home_address,
             food_restrictions, radio_personal, created_at)
        VALUES
            (:fname, :mname, :lname, :suffix, :email, :mobile_no, :gender, :dob, :civil_status, :pwd,
             :salary_grade, :position_title, :position_level, :employment_status, :civil_servant,
             :agency_unit, :agency_name, :sector, :geo, :location, :agency_address, :home_address,
             :food_restrictions, 'yes', NOW())
    ");
    $insP->execute([
        ':fname'            => $fname,
        ':mname'            => $mname,
        ':lname'            => $lname,
        ':suffix'           => $suffix,
        ':email'            => $email,
        ':mobile_no'        => $mobileNo,
        ':gender'           => $gender,
        ':dob'              => $dob,
        ':civil_status'     => $civilStatus,
        ':pwd'              => $pwd,
        ':salary_grade'     => $salaryGrade,
        ':position_title'   => $positionTitle,
        ':position_level'   => $positionLevel,
        ':employment_status'=> $employmentStatus,
        ':civil_servant'    => $civilServant,
        ':agency_unit'      => $agencyUnit,
        ':agency_name'      => $agencyName,
        ':sector'           => $sector,
        ':geo'              => $geo,
        ':location'         => $location,
        ':agency_address'   => $agencyAddress,
        ':home_address'     => $homeAddress,
        ':food_restrictions'=> $foodRestrictions,
    ]);
    $newParticipantId = (int) $conn->lastInsertId();

    // 2. Swap participant on the registration (payment data carries over)
    $conn->prepare("
        UPDATE otrs_registrations
        SET participant_id = :new_pid,
            updated_at     = NOW()
        WHERE id = :eid
    ")->execute([':new_pid' => $newParticipantId, ':eid' => $enrollmentId]);

    // 3. Log the replacement
    $conn->prepare("
        INSERT INTO otrs_replacement_requests
            (enrollment_id, training_id, original_participant_id, new_participant_id,
             replacement_reason, replaced_by, replaced_by_location, replaced_at)
        VALUES
            (:eid, :tid, :orig, :new_pid, :reason, :by, :loc, NOW())
    ")->execute([
        ':eid'    => $enrollmentId,
        ':tid'    => $trainingId,
        ':orig'   => $originalParticipantId,
        ':new_pid'=> $newParticipantId,
        ':reason' => $replacementReason,
        ':by'     => $replacedBy,
        ':loc'    => $replacedByLocation,
    ]);

    $conn->commit();

    echo json_encode([
        'status'  => 'success',
        'message' => 'Participant successfully replaced.',
    ]);

} catch (PDOException $ex) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[replace_participant HRD] ' . $ex->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error. Please try again.']);
}
