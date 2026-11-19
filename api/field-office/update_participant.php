<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

$enrollmentId  = filter_input(INPUT_POST, 'enrollmentId',  FILTER_VALIDATE_INT);
$participantId = filter_input(INPUT_POST, 'participantId', FILTER_VALIDATE_INT);

if (!$enrollmentId || !$participantId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid enrollment or participant ID.']);
    exit;
}

function safeStr(string $key, int $max = 254): string
{
    return mb_substr(trim($_POST[$key] ?? ''), 0, $max);
}

$primeHrmDiscount  = isset($_POST['prime_hrm_discount']) && $_POST['prime_hrm_discount'] === '1' ? 1 : 0;
$foodRestrictions  = strtoupper(safeStr('food_restrictions', 254));

$fname             = strtoupper(safeStr('fname', 64));
$mname             = strtoupper(safeStr('mname', 64));
$lname             = strtoupper(safeStr('lname', 64));
$suffix            = strtoupper(safeStr('suffix', 16));
$gender            = safeStr('gender', 32);
$civilStatus       = strtoupper(safeStr('civilstatus', 16));
$positionTitle     = strtoupper(safeStr('positiontitle', 254));
$agencyName        = strtoupper(safeStr('agency_name', 254));
$agencyAddress     = strtoupper(safeStr('agency_address', 254));
$email             = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$mobileNo          = safeStr('contactno', 16);
$location          = strtoupper(safeStr('location', 8));
$paymentStatus     = safeStr('paymentStatus', 24);
$orNo              = safeStr('orno', 12);
$orDate            = safeStr('ordate', 12);
$collectingOfficer = safeStr('collecting_officer', 64);
$remarks           = safeStr('remarks', 254);

$allowedStatuses = ['pending', 'paid', 'cancelled', 'promissory note'];
if (!in_array($paymentStatus, $allowedStatuses, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment status.']);
    exit;
}

if ($paymentStatus !== 'paid') {
    $orNo              = '';
    $orDate            = '';
    $collectingOfficer = '';
} else {
    if (empty($orNo)) {
        echo json_encode(['status' => 'error', 'message' => 'OR Number is required when payment status is Paid.']);
        exit;
    }
    if (empty($orDate)) {
        echo json_encode(['status' => 'error', 'message' => 'OR Date is required when payment status is Paid.']);
        exit;
    }
}

$userLocation = strtolower($_SESSION['type'] ?? '');

try {
    /* Verify enrollment belongs to this office and to the supplied participantId */
    $chk = $conn->prepare("
        SELECT r.participant_id, r.payment_status AS prev_status, r.training_id
        FROM   otrs_registrations r
        JOIN   otrs_participants   p ON p.id = r.participant_id
        WHERE  r.id              = :eid
          AND  UPPER(p.location) = UPPER(:user_loc)
        LIMIT  1
    ");
    $chk->bindValue(':eid',      $enrollmentId, PDO::PARAM_INT);
    $chk->bindValue(':user_loc', $userLocation);
    $chk->execute();
    $row = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Registration record not found or access denied.']);
        exit;
    }
    if ((int) $row['participant_id'] !== $participantId) {
        echo json_encode(['status' => 'error', 'message' => 'Participant ID mismatch — update rejected.']);
        exit;
    }

    /* Fetch old profile for change-detection logging */
    $oldProfileStmt = $conn->prepare(
        "SELECT fname, mname, lname, suffix, gender, civil_status,
                position_title, agency_name, agency_address,
                food_restrictions, email, mobile_no, location
         FROM otrs_participants WHERE id = :pid LIMIT 1"
    );
    $oldProfileStmt->execute([':pid' => $participantId]);
    $oldProfile = $oldProfileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $conn->beginTransaction();

    /* Update personal profile */
    $stmtP = $conn->prepare("
        UPDATE otrs_participants SET
            fname          = :fname,
            mname          = :mname,
            lname          = :lname,
            suffix         = :suffix,
            gender         = :gender,
            civil_status   = :civil_status,
            position_title = :position_title,
            agency_name        = :agency_name,
            agency_address     = :agency_address,
            food_restrictions  = :food_restrictions,
            email              = :email,
            mobile_no          = :mobile_no,
            location           = :location,
            updated_at         = NOW()
        WHERE id = :pid
    ");
    $stmtP->execute([
        ':fname'             => $fname,
        ':mname'             => $mname,
        ':lname'             => $lname,
        ':suffix'            => $suffix,
        ':gender'            => $gender,
        ':civil_status'      => $civilStatus,
        ':position_title'    => $positionTitle,
        ':agency_name'       => $agencyName,
        ':agency_address'    => $agencyAddress,
        ':food_restrictions' => $foodRestrictions,
        ':email'             => $email,
        ':mobile_no'         => $mobileNo,
        ':location'          => $location,
        ':pid'               => $participantId,
    ]);

    /* Update enrollment / payment data */
    $stmtE = $conn->prepare("
        UPDATE otrs_registrations SET
            payment_status      = :payment_status,
            or_no               = :or_no,
            or_date             = :or_date,
            collecting_officer  = :collecting_officer,
            remarks             = :remarks,
            prime_hrm_discount  = :prime_hrm_discount,
            updated_at          = NOW()
        WHERE id = :eid
    ");
    $stmtE->execute([
        ':payment_status'     => $paymentStatus,
        ':or_no'              => $orNo,
        ':or_date'            => $orDate ?: null,
        ':collecting_officer' => $collectingOfficer,
        ':remarks'            => $remarks,
        ':prime_hrm_discount' => $primeHrmDiscount,
        ':eid'                => $enrollmentId,
    ]);

    /* Restore slot when newly cancelled */
    if ($paymentStatus === 'cancelled' && $row['prev_status'] !== 'cancelled') {
        $conn->prepare("
            UPDATE otrs_trainings SET slots = slots + 1 WHERE id = :tid
        ")->execute([':tid' => (int) $row['training_id']]);
    }

    /* Log payment status change only when it actually changed */
    if ($paymentStatus !== $row['prev_status']) {
        $action = ($paymentStatus === 'cancelled') ? 'Registration Cancelled' : 'Payment Updated';
        $conn->prepare("
            INSERT INTO otrs_payment_logs (registration_id, action, old_status, new_status, updated_by)
            VALUES (:reg_id, :action, :old_status, :new_status, :updated_by)
        ")->execute([
            ':reg_id'     => $enrollmentId,
            ':action'     => $action,
            ':old_status' => $row['prev_status'],
            ':new_status' => $paymentStatus,
            ':updated_by' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'system',
        ]);
    }

    /* Log profile field changes */
    if ($oldProfile) {
        $profileFields = [
            'fname'             => ['label' => 'First Name',        'upper' => true,  'new' => $fname],
            'mname'             => ['label' => 'Middle Name',       'upper' => true,  'new' => $mname],
            'lname'             => ['label' => 'Last Name',         'upper' => true,  'new' => $lname],
            'suffix'            => ['label' => 'Suffix',            'upper' => true,  'new' => $suffix],
            'gender'            => ['label' => 'Gender',            'upper' => false, 'new' => $gender],
            'civil_status'      => ['label' => 'Civil Status',      'upper' => true,  'new' => $civilStatus],
            'position_title'    => ['label' => 'Position Title',    'upper' => true,  'new' => $positionTitle],
            'agency_name'       => ['label' => 'Agency',            'upper' => true,  'new' => $agencyName],
            'agency_address'    => ['label' => 'Agency Address',    'upper' => true,  'new' => $agencyAddress],
            'food_restrictions' => ['label' => 'Food Restrictions', 'upper' => true,  'new' => $foodRestrictions],
            'email'             => ['label' => 'Email',             'upper' => false, 'new' => $email],
            'mobile_no'         => ['label' => 'Mobile No.',        'upper' => false, 'new' => $mobileNo],
            'location'          => ['label' => 'Office',            'upper' => true,  'new' => $location],
        ];
        $changed = [];
        foreach ($profileFields as $col => $field) {
            $oldVal = trim((string)($oldProfile[$col] ?? ''));
            if ($field['upper']) $oldVal = strtoupper($oldVal);
            if ($oldVal !== $field['new']) {
                $changed[] = $field['label'];
            }
        }
        if (!empty($changed)) {
            $conn->prepare("
                INSERT INTO otrs_payment_logs
                    (registration_id, action, old_status, new_status, notes, updated_by)
                VALUES
                    (:reg_id, 'Participant Info Updated', NULL, 'profile_updated', :notes, :updated_by)
            ")->execute([
                ':reg_id'     => $enrollmentId,
                ':notes'      => 'Updated: ' . implode(', ', $changed),
                ':updated_by' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'system',
            ]);
        }
    }

    $conn->commit();

    echo json_encode(['status' => 'success', 'message' => 'Participant updated successfully.']);

} catch (PDOException $ex) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[update_participant] ' . $ex->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to save changes. Please try again.']);
}
