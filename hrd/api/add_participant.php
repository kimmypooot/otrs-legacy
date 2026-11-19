<?php
// api/add_participant.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../inc/audit_log.php';

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

$trainingId      = filter_input(INPUT_POST, 'trainingId', FILTER_VALIDATE_INT);
$fname           = strtoupper(trim($_POST['fname'] ?? ''));
$mname           = strtoupper(trim($_POST['mname'] ?? ''));
$lname           = strtoupper(trim($_POST['lname'] ?? ''));
$suffix          = strtoupper(trim($_POST['suffix'] ?? ''));
$email           = trim($_POST['email'] ?? '');
$gender          = trim($_POST['gender'] ?? '');
$trainingMode    = trim($_POST['trainingMode'] ?? '');
$positionLevel   = strtoupper(trim($_POST['position_level'] ?? ''));
$salaryGrade     = trim($_POST['salary_grade'] ?? '');
$agencyUnit      = strtoupper(trim($_POST['agency_unit'] ?? ''));
$agencyName      = strtoupper(trim($_POST['agency_name'] ?? ''));
$sector          = strtoupper(trim($_POST['sector'] ?? ''));
$geo             = strtoupper(trim($_POST['geo'] ?? ''));
$location        = trim($_POST['location'] ?? '');
$agencyAddress   = strtoupper(trim($_POST['agency_address'] ?? ''));
$homeAddress     = strtoupper(trim($_POST['home_address'] ?? ''));
$foodRestrictions = ($trainingMode === 'Face to Face') ? strtoupper(trim($_POST['food_restrictions'] ?? '')) : '';
$radioPersonal   = trim($_POST['radioPersonal'] ?? '');
$radioCharge     = trim($_POST['radioCharge'] ?? '');
$radioCert       = trim($_POST['radioCert'] ?? '');

// Accept both old and new field names
$civilStatus     = strtoupper(trim($_POST['civil_status'] ?? $_POST['civilstatus'] ?? ''));
$mobileNo        = trim($_POST['mobile_no'] ?? $_POST['mobileno'] ?? '');
$positionTitle   = strtoupper(trim($_POST['position_title'] ?? $_POST['positiontitle'] ?? ''));

// dob: accept dob directly; fallback to age not stored anymore
$dob = trim($_POST['dob'] ?? '');
if ($dob === '') {
    $dob = null;
}

$validPwd = ['Yes', 'No'];
$pwd      = in_array(trim($_POST['pwd'] ?? ''), $validPwd) ? trim($_POST['pwd']) : 'No';

if (!$trainingId || $fname === '' || $lname === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

try {
    // Fetch training type to determine payment status
    $tTypeStmt = $conn->prepare("SELECT `type` FROM otrs_trainings WHERE id = :id LIMIT 1");
    $tTypeStmt->execute([':id' => $trainingId]);
    $trainingType = $tTypeStmt->fetchColumn();
    if ($trainingType === false) {
        echo json_encode(['success' => false, 'message' => 'Training not found.']);
        exit;
    }
    $paymentStatus = ($trainingType === 'Free') ? 'free' : 'pending';

    // Check duplicate registration (exclude cancelled — those can be re-added)
    $dupStmt = $conn->prepare(
        "SELECT r.id, r.payment_status, r.registration_status
         FROM otrs_registrations r
         JOIN otrs_participants p ON r.participant_id = p.id
         WHERE p.fname = :fname
           AND (p.mname = :mname OR (p.mname IS NULL AND :mname2 = ''))
           AND p.lname = :lname
           AND r.training_id = :training_id
           AND r.registration_status != 'Cancelled'
         LIMIT 1"
    );
    $dupStmt->execute([
        ':fname'       => $fname,
        ':mname'       => $mname,
        ':mname2'      => $mname,
        ':lname'       => $lname,
        ':training_id' => $trainingId,
    ]);
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $existingStatus = ucwords($existing['payment_status'] ?? 'registered');
        echo json_encode([
            'success'  => false,
            'duplicate'=> true,
            'message'  => "This participant is already registered for this training with a payment status of <b>{$existingStatus}</b>.",
        ]);
        exit;
    }

    // Check available slots
    $slotRow = $conn->prepare("SELECT slots FROM otrs_trainings WHERE id = :id LIMIT 1");
    $slotRow->execute([':id' => $trainingId]);
    $availableSlots = (int)($slotRow->fetchColumn() ?? 0);

    // No slots — add to waitlist instead
    if ($availableSlots <= 0) {
        // Insert participant profile first (may already exist; use existing via name match if present)
        $existPStmt = $conn->prepare(
            "SELECT id FROM otrs_participants WHERE fname=:f AND mname=:m AND lname=:l LIMIT 1"
        );
        $existPStmt->execute([':f' => $fname, ':m' => $mname, ':l' => $lname]);
        $existingPid = $existPStmt->fetchColumn();

        if (!$existingPid) {
            $pStmt2 = $conn->prepare(
                "INSERT INTO otrs_participants
                    (fname, mname, lname, suffix, email, mobile_no, position_title,
                     agency_name, location, created_at, updated_at)
                 VALUES (:fname,:mname,:lname,:suffix,:email,:mobile,:pos,:agency,:loc,NOW(),NOW())"
            );
            $pStmt2->execute([
                ':fname'  => $fname, ':mname' => $mname, ':lname' => $lname,
                ':suffix' => $suffix ?: null, ':email' => $email, ':mobile' => $mobileNo,
                ':pos'    => $positionTitle, ':agency' => $agencyName, ':loc' => $location,
            ]);
            $existingPid = (int)$conn->lastInsertId();
        }

        // Get next waitlist position for this training
        $posStmt = $conn->prepare(
            "SELECT COALESCE(MAX(position),0)+1 FROM otrs_waitlist WHERE training_id=:tid AND status='waiting'"
        );
        $posStmt->execute([':tid' => $trainingId]);
        $nextPos = (int)$posStmt->fetchColumn();

        $wlStmt = $conn->prepare(
            "INSERT INTO otrs_waitlist (participant_id, training_id, position, status, added_by)
             VALUES (:pid, :tid, :pos, 'waiting', :by)
             ON DUPLICATE KEY UPDATE status='waiting', position=:pos2, added_at=NOW()"
        );
        $wlStmt->execute([
            ':pid' => $existingPid, ':tid' => $trainingId,
            ':pos' => $nextPos,     ':pos2' => $nextPos,
            ':by'  => $_SESSION['username'] ?? 'system',
        ]);

        $wlTrnStmt = $conn->prepare("SELECT name FROM otrs_trainings WHERE id=:id LIMIT 1");
        $wlTrnStmt->execute([':id' => $trainingId]);
        $wlTrainingName = $wlTrnStmt->fetchColumn() ?: '';

        otrsAuditLog($conn, 'participant_waitlisted', 'waitlist', (int)$conn->lastInsertId(),
            $trainingId, "{$fname} {$lname}",
            json_encode(['position' => $nextPos, 'training' => $wlTrainingName]),
            $_SESSION['username'] ?? 'system', $_SERVER['REMOTE_ADDR'] ?? null
        );

        echo json_encode([
            'success'    => true,
            'waitlisted' => true,
            'position'   => $nextPos,
            'message'    => "No available slots. <b>{$fname} {$lname}</b> has been added to the waitlist at position <b>#{$nextPos}</b>.",
        ]);
        exit;
    }

    $conn->beginTransaction();

    // Reduce slots
    $slotStmt = $conn->prepare(
        "UPDATE otrs_trainings SET slots = slots - 1 WHERE id = :training_id AND slots > 0"
    );
    $slotStmt->execute([':training_id' => $trainingId]);

    // Insert participant
    $pStmt = $conn->prepare(
        "INSERT INTO otrs_participants
            (fname, mname, lname, suffix, email, mobile_no, gender, dob, civil_status,
             pwd, salary_grade, position_title, position_level, agency_unit, agency_name,
             sector, geo, location, agency_address, home_address, food_restrictions,
             radio_personal, created_at, updated_at)
         VALUES
            (:fname, :mname, :lname, :suffix, :email, :mobile_no, :gender, :dob, :civil_status,
             :pwd, :salary_grade, :position_title, :position_level, :agency_unit, :agency_name,
             :sector, :geo, :location, :agency_address, :home_address, :food_restrictions,
             :radio_personal, NOW(), NOW())"
    );

    $pStmt->bindParam(':fname',            $fname);
    $pStmt->bindParam(':mname',            $mname);
    $pStmt->bindParam(':lname',            $lname);
    $pStmt->bindValue(':suffix',           $suffix !== '' ? $suffix : null, $suffix !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $pStmt->bindParam(':email',            $email);
    $pStmt->bindParam(':mobile_no',        $mobileNo);
    $pStmt->bindParam(':gender',           $gender);
    if ($dob === null) {
        $pStmt->bindValue(':dob', null, PDO::PARAM_NULL);
    } else {
        $pStmt->bindParam(':dob',          $dob);
    }
    $pStmt->bindParam(':civil_status',     $civilStatus);
    $pStmt->bindParam(':pwd',              $pwd);
    $pStmt->bindParam(':salary_grade',     $salaryGrade);
    $pStmt->bindParam(':position_title',   $positionTitle);
    $pStmt->bindParam(':position_level',   $positionLevel);
    $pStmt->bindParam(':agency_unit',      $agencyUnit);
    $pStmt->bindParam(':agency_name',      $agencyName);
    $pStmt->bindParam(':sector',           $sector);
    $pStmt->bindParam(':geo',              $geo);
    $pStmt->bindParam(':location',         $location);
    $pStmt->bindParam(':agency_address',   $agencyAddress);
    $pStmt->bindParam(':home_address',     $homeAddress);
    $pStmt->bindParam(':food_restrictions',$foodRestrictions);
    $pStmt->bindParam(':radio_personal',   $radioPersonal);
    $pStmt->execute();

    $participantId = (int) $conn->lastInsertId();

    // Insert registration
    $rStmt = $conn->prepare(
        "INSERT INTO otrs_registrations
            (participant_id, training_id, registration_status, payment_status,
             radio_charge, radio_cert, registered_at, created_at, updated_at)
         VALUES
            (:participant_id, :training_id, 'confirmed', :payment_status,
             :radio_charge, :radio_cert, NOW(), NOW(), NOW())"
    );
    $rStmt->bindParam(':participant_id',  $participantId,  PDO::PARAM_INT);
    $rStmt->bindParam(':training_id',     $trainingId,     PDO::PARAM_INT);
    $rStmt->bindParam(':payment_status',  $paymentStatus);
    $rStmt->bindParam(':radio_charge',    $radioCharge);
    $rStmt->bindParam(':radio_cert',      $radioCert);
    $rStmt->execute();

    $conn->commit();

    // Fetch training name for the success message
    $tStmt = $conn->prepare("SELECT name FROM otrs_trainings WHERE id = :id LIMIT 1");
    $tStmt->execute([':id' => $trainingId]);
    $trainingName = $tStmt->fetchColumn() ?: 'the training';

    otrsAuditLog($conn, 'participant_added', 'registration', (int)$conn->lastInsertId(),
        $trainingId, "{$fname} {$lname}",
        json_encode(['training' => $trainingName, 'payment_status' => $paymentStatus]),
        $_SESSION['username'] ?? 'system', $_SERVER['REMOTE_ADDR'] ?? null
    );

    echo json_encode([
        'success' => true,
        'message' => "Participant for the <b>" . htmlspecialchars($trainingName) . "</b> has been added successfully.",
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('add_participant Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Oops! Something went wrong. Please try again later.']);
}
?>
