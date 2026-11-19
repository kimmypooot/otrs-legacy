<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

try {
    $location = trim($_POST['location'] ?? '');
    $status   = trim($_POST['status']   ?? '');

    $pdo = $conn;

    $baseJoin = "
        FROM otrs_trainings ot
        LEFT JOIN otrs_registrations r ON r.training_id = ot.id
    ";

    if (!empty($location)) {
        $baseJoin .= " LEFT JOIN otrs_participants p ON p.id = r.participant_id";
    }

    $whereSQL = "WHERE ot.training_status = :status";
    $params   = [':status' => $status];

    if (!empty($location)) {
        $whereSQL .= " AND p.location = :location";
        $params[':location'] = $location;
    }

    $sql = "
        SELECT
            ot.id,
            ot.name,
            ot.start_date,
            ot.end_date,
            ot.mode,
            ot.slots,
            ot.training_status             AS status_trng,
            ot.type                        AS training_type,
            COUNT(DISTINCT r.id)           AS participant_count,
            COALESCE(SUM(r.registration_status != 'cancelled'
                AND r.payment_status IN ('pending','paid','promissory note')), 0) AS participant_status_count,
            COALESCE(SUM(r.registration_status != 'cancelled'
                AND r.payment_status = 'pending'), 0)           AS pending_count,
            COALESCE(SUM(r.registration_status != 'cancelled'
                AND r.payment_status = 'paid'), 0)              AS paid_count,
            COALESCE(SUM(r.registration_status != 'cancelled'
                AND r.payment_status = 'promissory note'), 0)   AS promissory_count,
            COALESCE(SUM(r.registration_status = 'cancelled'), 0) AS cancelled_count,
            COALESCE(SUM(r.registration_status != 'cancelled'
                AND r.payment_status = 'pending'
                AND r.proof_file IS NOT NULL
                AND r.proof_file != ''), 0)                     AS proof_pending_count
        {$baseJoin}
        {$whereSQL}
        GROUP BY ot.id
        ORDER BY ot.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $badgeMap = [
        'completed'                      => 'badge bg-success',
        'accepting payments'             => 'badge bg-primary',
        'ongoing registration'           => 'badge bg-primary',
        'ongoing'                        => 'badge bg-primary',
        'upcoming'                       => 'badge bg-info',
        'scheduled until further notice' => 'badge bg-secondary',
        'cancelled'                      => 'badge bg-danger',
    ];

    $data    = [];
    $counter = 1;

    foreach ($result as $row) {
        $statusTrng = $row['status_trng'];
        $badgeClass = $badgeMap[strtolower(trim($statusTrng))] ?? 'badge bg-secondary';
        $actions    = buildActionButtons($row, $location);

        $data[] = [
            'counter'          => $counter++,
            'name'             => htmlspecialchars($row['name']),
            'start_date'       => htmlspecialchars($row['start_date'] ?? ''),
            'end_date'         => htmlspecialchars($row['end_date']   ?? ''),
            'mode'             => htmlspecialchars($row['mode']),
            'status_badge'     => '<span class="' . $badgeClass . '">' . htmlspecialchars(ucwords(strtolower($statusTrng))) . '</span>',
            'slots'            => (int)$row['slots'],
            'total_pax'        => (int)$row['participant_status_count'],
            'paid_count'       => (int)$row['paid_count'],
            'promissory_count' => (int)$row['promissory_count'],
            'pending_count'    => (int)$row['pending_count'],
            'cancelled_count'  => (int)$row['cancelled_count'],
            'actions'          => $actions,
        ];
    }

    echo json_encode(['data' => $data]);

} catch (PDOException $e) {
    error_log('ajax_training_data.php PDO error: ' . $e->getMessage());
    echo json_encode(['error' => 'Database error occurred']);
}

function buildActionButtons($row, $location)
{
    $id         = htmlspecialchars($row['id']);
    $name       = htmlspecialchars($row['name']);
    $startDate  = htmlspecialchars($row['start_date'] ?? '');
    $mode       = htmlspecialchars($row['mode']);
    $slots      = (int)$row['slots'];
    $statusTrng = $row['status_trng'];

    $buttons = [];

    $buttons[] = sprintf(
        '<button class="btn btn-success btn-sm add-participant-btn" data-toggle="tooltip" title="Add Participant"
        data-training-id="%s" data-training-name="%s" data-training-date="%s"
        data-training-mode="%s" data-slots="%d">
        <i class="bi bi-person-plus-fill"></i></button>',
        $id, $name, $startDate, $mode, $slots
    );

    $buttons[] = sprintf(
        '<a href="participants?id=%s&location=%s" class="btn btn-primary btn-sm"
        data-toggle="tooltip" title="List of Participants">
        <i class="bi bi-list-ul"></i></a>',
        $id, urlencode($location)
    );

    $allZero  = ($row['paid_count'] == 0 && $row['promissory_count'] == 0 && $row['pending_count'] == 0);
    $disabled = $allZero ? 'disabled' : '';

    $buttons[] = sprintf(
        '<button type="button" class="btn btn-danger btn-sm view-graph-btn" %s
        data-training-id="%s" data-training-name="%s" data-training-date="%s"
        data-training-count="%d" data-pending-count="%d" data-promissory-count="%d"
        data-toggle="tooltip" title="View Pie Graph">
        <i class="bi bi-file-earmark-bar-graph"></i></button>',
        $disabled, $id, $name, $startDate,
        $row['paid_count'], $row['pending_count'], $row['promissory_count']
    );

    if (in_array($row['name'], [
        'Supervisory Development Course (SDC), Track 1',
        'Supervisory Development Course (SDC), Tracks 2 and 3',
    ])) {
        $buttons[] = sprintf(
            '<a href="participants_status?id=%s&location=%s"
            class="btn btn-secondary btn-sm" data-toggle="tooltip" title="View Monitoring Submission">
            <i class="bi bi-eye"></i></a>',
            $id, urlencode($location)
        );
    }

    if (in_array($statusTrng, ['ongoing', 'accepting payments'])) {
        $badge = '';
        if ($row['proof_pending_count'] > 0) {
            $badge = sprintf(
                '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark"
                style="font-size: 0.6rem;" title="Pending submissions with proof">%d</span>',
                $row['proof_pending_count']
            );
        }

        $buttons[] = sprintf(
            '<div style="position: relative; display: inline-block;">
            <a href="proof_payment?id=%s&location=%s"
            class="btn btn-success btn-sm" data-toggle="tooltip" title="Proof of Payment">
            <i class="bi bi-cash-stack"></i></a>%s</div>',
            $id, urlencode($location), $badge
        );
    }

    return implode(' ', $buttons);
}
