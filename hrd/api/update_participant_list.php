<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit('Access denied.');
}

if (!isset($_GET['id'])) {
    echo 'Invalid training ID.';
    exit;
}

$trainingId = (int)$_GET['id'];
$location   = isset($_GET['location']) ? trim($_GET['location']) : '';
$filter     = isset($_GET['filter'])   ? trim($_GET['filter'])   : '';

try {
    // Fetch training info
    $stmtT = $conn->prepare("SELECT id, name, mode FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->bindParam(':id', $trainingId, PDO::PARAM_INT);
    $stmtT->execute();
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);

    if (!$training) {
        echo 'Training not found.';
        exit;
    }

    // Build participant query
    $sql = "
        SELECT p.id          AS participant_id,
               r.id          AS reg_id,
               p.fname, p.mname, p.lname,
               p.gender,
               p.civil_status      AS civilstatus,
               p.position_title    AS positiontitle,
               p.agency_name,
               p.agency_address,
               p.email,
               p.mobile_no         AS mobileno,
               p.location,
               r.payment_status    AS status,
               r.or_no             AS orno,
               r.collecting_officer,
               p.food_restrictions,
               r.remarks,
               r.training_status   AS status_training,
               r.registered_at     AS timereg
        FROM otrs_participants p
        JOIN otrs_registrations r ON r.participant_id = p.id
        WHERE r.training_id = :training_id
    ";

    $params = [':training_id' => $trainingId];

    if ($location !== '') {
        $sql .= " AND p.location = :location";
        $params[':location'] = $location;
    }

    if ($filter !== '') {
        $sql .= " AND r.payment_status = :filter";
        $params[':filter'] = $filter;
    }

    $sql .= " ORDER BY r.registered_at DESC";

    $stmtP = $conn->prepare($sql);
    $stmtP->execute($params);
    $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Generate the updated participant list HTML rows
    $output = '';
    $count  = 0;
    foreach ($participants as $participant) {
        $count++;
        $regId = $participant['reg_id'];
        $pId   = $participant['participant_id'];

        $output .= '<tr>';
        $output .= '<td style="text-align: center">';
        $output .= '<input type="checkbox" name="participants[]" value="' . $regId . '" class="participant-checkbox" data-participant-id="' . $regId . '">';
        $output .= '</td>';
        $output .= '<td style="text-align: center">';
        if ($participant['status'] == 'cancelled') {
            $output .= '<a href="#" onclick="deleteParticipant(' . $regId . ')" data-bs-toggle="tooltip" data-bs-placement="right" title="Delete Participant"><i class="bi bi-trash text-danger"></i></a>';
        } else {
            $output .= '<a type="button" class="btn btn-primary btn-xs" href="#" onclick="showUpdateModal(' . $pId . ')" data-reg-id="' . $regId . '" data-bs-toggle="tooltip" data-bs-placement="left" title="Update Participant"><i class="bi bi-pencil-square"></i></a> ';
            $output .= '<a type="button" class="btn btn-danger btn-xs" href="#" onclick="deleteParticipant(' . $regId . ')" data-bs-toggle="tooltip" data-bs-placement="right" title="Delete Participant"><i class="bi bi-trash"></i></a>';
        }
        $output .= '</td>';
        $output .= '<td>' . $count . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['fname'] . ' ' . $participant['mname'] . ' ' . $participant['lname']) . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['gender']) . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['civilstatus']) . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['positiontitle']) . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['agency_name']) . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['agency_address']) . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['email']) . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['mobileno']) . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['location']) . '</td>';
        $output .= '<td><span class="badge ' . getStatusBadgeClass($participant['status']) . '">' . htmlspecialchars(ucwords(strtolower($participant['status']))) . '</span></td>';
        $output .= '<td>' . htmlspecialchars($participant['orno']) . '</td>';
        $output .= '<td>' . htmlspecialchars($participant['collecting_officer']) . '</td>';
        if ($training['mode'] == 'Face to Face') {
            $output .= '<td>' . htmlspecialchars($participant['food_restrictions']) . '</td>';
        }
        $output .= '<td>' . htmlspecialchars($participant['remarks']) . '</td>';
        $output .= '<td><span class="badge ' . getTrainingStatusBadgeClass($participant['status_training']) . '">' . htmlspecialchars(ucwords(strtolower($participant['status_training']))) . '</span></td>';
        $output .= '<td>' . htmlspecialchars($participant['timereg']) . '</td>';
        $output .= '</tr>';
    }

    echo $output;

} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
}

function getStatusBadgeClass($status)
{
    switch (strtolower(trim($status))) {
        case 'pending':         return 'badge rounded-pill bg-primary';
        case 'cancelled':       return 'badge rounded-pill bg-danger';
        case 'paid':            return 'badge rounded-pill bg-success';
        case 'promissory note': return 'badge rounded-pill bg-warning';
        case 'free':            return 'badge rounded-pill bg-info';
        default:                return 'badge rounded-pill bg-secondary';
    }
}

function getTrainingStatusBadgeClass($status)
{
    switch (strtolower(trim($status))) {
        case 'completed':      return 'badge rounded-pill bg-success';
        case 'not completed':  return 'badge rounded-pill bg-primary';
        case 'for compliance': return 'badge rounded-pill bg-danger';
        default:               return 'badge rounded-pill bg-secondary';
    }
}
?>
