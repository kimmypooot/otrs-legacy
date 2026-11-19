<?php
// api/update_participant.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/../inc/audit_log.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}

try {
    if (empty($_POST['participantId'])) {
        throw new Exception('Participant ID is required');
    }
    if (empty($_POST['regId'])) {
        throw new Exception('Registration ID is required');
    }

    $paymentStatus = $_POST['status'] ?? '';
    $registrationStatus = in_array($paymentStatus, ['cancelled', 'not qualified'], true)
        ? $paymentStatus
        : 'confirmed';

    if (in_array($paymentStatus, ['promissory note', 'not qualified'], true)
        && trim($_POST['remarks'] ?? '') === '') {
        $label = $paymentStatus === 'not qualified' ? 'Not Qualified' : 'Promissory Note';
        throw new Exception("Remarks is required when status is {$label}.");
    }

    $validPwd = ['Yes', 'No'];
    $rawPwd   = trim($_POST['pwd'] ?? 'No');

    $isPaid = ($paymentStatus === 'paid');
    if ($isPaid) {
        if (trim($_POST['orno'] ?? '') === '') {
            throw new Exception('OR Number is required when payment status is Paid.');
        }
        if (trim($_POST['ordate'] ?? '') === '') {
            throw new Exception('OR Date is required when payment status is Paid.');
        }
    }
    $data = [
        'id'                  => (int) $_POST['participantId'],
        'reg_id'              => (int) $_POST['regId'],
        'fname'               => strtoupper(trim($_POST['fname'] ?? '')),
        'mname'               => strtoupper(trim($_POST['mname'] ?? '')),
        'lname'               => strtoupper(trim($_POST['lname'] ?? '')),
        'suffix'              => strtoupper(trim($_POST['suffix'] ?? '')),
        'gender'              => trim($_POST['gender'] ?? ''),
        'pwd'                 => in_array($rawPwd, $validPwd) ? $rawPwd : 'No',
        'civil_status'        => strtoupper(trim($_POST['civil_status'] ?? $_POST['civilstatus'] ?? '')),
        'position_title'      => strtoupper(trim($_POST['position_title'] ?? $_POST['positiontitle'] ?? '')),
        'salary_grade'        => trim($_POST['salary_grade'] ?? ''),
        'employment_status'   => trim($_POST['employment_status'] ?? ''),
        'position_level'      => strtoupper(trim($_POST['position_level'] ?? '')),
        'agency_name'         => strtoupper(trim($_POST['agency_name'] ?? '')),
        'agency_address'      => strtoupper(trim($_POST['agency_address'] ?? '')),
        'home_address'        => strtoupper(trim($_POST['home_address'] ?? '')),
        'food_restrictions'   => strtoupper(trim($_POST['food_restrictions'] ?? '')),
        'email'               => trim($_POST['email'] ?? ''),
        'mobile_no'           => trim($_POST['mobile_no'] ?? $_POST['contactno'] ?? ''),
        'location'            => trim($_POST['location'] ?? ''),
        'payment_status'      => $paymentStatus,
        'registration_status' => $registrationStatus,
        'training_status'     => trim($_POST['status_training'] ?? ''),
        'or_no'               => $isPaid ? trim($_POST['orno'] ?? '') : '',
        'or_date'             => $isPaid && !empty($_POST['ordate']) ? trim($_POST['ordate']) : null,
        'collecting_officer'  => $isPaid ? trim($_POST['collecting_officer'] ?? '') : '',
        'remarks'             => trim($_POST['remarks'] ?? ''),
        'prime_hrm_discount'  => isset($_POST['prime_hrm_discount']) && $_POST['prime_hrm_discount'] === '1' ? 1 : 0,
        'updated_by'          => $_SESSION['name'] ?? $_SESSION['username'] ?? 'system',
    ];

    $participant = new Participant($conn);

    if ($participant->update($data)) {
        otrsAuditLog($conn, 'participant_updated', 'registration', $data['reg_id'],
            null, "{$data['fname']} {$data['lname']}",
            json_encode(['payment_status' => $data['payment_status'], 'registration_status' => $data['registration_status']]),
            $data['updated_by'], $_SERVER['REMOTE_ADDR'] ?? null
        );
        $response = [
            'status'  => 'success',
            'message' => 'Participant updated successfully',
        ];
    } else {
        throw new Exception('Failed to update participant');
    }
} catch (Exception $e) {
    error_log('update_participant Error: ' . $e->getMessage());
    $response = [
        'status'  => 'error',
        'message' => $e->getMessage(),
    ];
}

echo json_encode($response);
exit;
?>
