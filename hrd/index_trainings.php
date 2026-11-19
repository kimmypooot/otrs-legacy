<?php
// imis/otrs/hrd/index_trainings.php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

// Auto-transition trainings (5-minute lock shared with field-office page)
$_itLock = sys_get_temp_dir() . '/otrs_auto_complete.lock';
if (!file_exists($_itLock) || (time() - filemtime($_itLock)) > 300) {
    try {
        $conn->prepare(
            "UPDATE otrs_trainings SET training_status='ongoing'
             WHERE start_date IS NOT NULL AND start_date != '0000-00-00'
               AND start_date <= CURDATE()
               AND (end_date IS NULL OR end_date = '0000-00-00' OR end_date >= CURDATE())
               AND LOWER(training_status) NOT IN ('completed','cancelled','ongoing','rescheduled')"
        )->execute();
        $conn->prepare(
            "UPDATE otrs_trainings SET training_status='completed'
             WHERE end_date IS NOT NULL AND end_date != '0000-00-00'
               AND end_date < CURDATE()
               AND LOWER(training_status) NOT IN ('completed','cancelled','rescheduled')"
        )->execute();
    } catch (PDOException $_e) { error_log('[index_trainings auto] ' . $_e->getMessage()); }
    @touch($_itLock);
}
unset($_itLock, $_e);

$locationCode = isset($_GET['location']) ? $_GET['location'] : '';
$offices = [
    'hrd'   => ['title' => 'CSC RO VIII - Human Resource Division', 'breadcrumb' => 'HRD'],
    'lfoi'  => ['title' => 'CSC Field Office - Leyte I',            'breadcrumb' => 'LFOI'],
    'lfoii' => ['title' => 'CSC Field Office - Leyte II',           'breadcrumb' => 'LFOII'],
    'bfo'   => ['title' => 'CSC Field Office - Biliran',            'breadcrumb' => 'BFO'],
    'wlso'  => ['title' => 'CSC Satellite Office - Western Leyte',  'breadcrumb' => 'WLSO'],
    'slfo'  => ['title' => 'CSC Field Office - Southern Leyte',     'breadcrumb' => 'SLFO'],
    'sfo'   => ['title' => 'CSC Field Office - Samar',              'breadcrumb' => 'SFO'],
    'esfo'  => ['title' => 'CSC Field Office - Eastern Samar',      'breadcrumb' => 'ESFO'],
    'nsfo'  => ['title' => 'CSC Field Office - Northern Samar',     'breadcrumb' => 'NSFO'],
    'ro6'   => ['title' => 'CSC Regional Office VI',                'breadcrumb' => 'RO6'],
    'ro7'   => ['title' => 'CSC Regional Office VII',               'breadcrumb' => 'RO7'],
];

$pageTitle      = 'Learning and Development';
$breadcrumbText = 'HRD';
$locationText   = 'Consolidated Data';

if (isset($offices[$locationCode])) {
    $pageTitle      = $offices[$locationCode]['title'];
    $breadcrumbText = $offices[$locationCode]['breadcrumb'];
    $locationText   = $offices[$locationCode]['title'];
} elseif ($locationCode === '' || $locationCode === 'index_trainings') {
    $locationText = 'Consolidated Data';
}

/* ── Tab counts ─────────────────────────────────────────────────────────── */
$tabCounts = ['upcoming' => 0, 'ongoing' => 0, 'completed' => 0, 'cancelled' => 0, 'rescheduled' => 0];
try {
    $tcStmt = $conn->query("SELECT LOWER(training_status) AS s, COUNT(*) AS n FROM otrs_trainings GROUP BY s");
    foreach ($tcStmt->fetchAll(PDO::FETCH_ASSOC) as $tc) {
        $s = trim($tc['s']);
        if (isset($tabCounts[$s])) $tabCounts[$s] = (int)$tc['n'];
    }
} catch (PDOException $_e) { /* non-fatal */ }

function renderTrainingRows($statusFilter, $locationFilter, $sortDir = 'ASC') {
    global $conn;
    try {
        $query = "
            SELECT ot.*,
                ot.training_status AS status_trng,
                COUNT(r.id) AS participant_count,
                COALESCE(SUM(r.payment_status IN ('pending', 'paid', 'promissory note', 'free')), 0) AS participant_status_count,
                COALESCE(SUM(r.payment_status = 'pending'), 0)                                       AS pending_count,
                COALESCE(SUM(r.payment_status = 'paid'), 0)                                          AS paid_count,
                COALESCE(SUM(r.payment_status = 'promissory note'), 0)                               AS promissory_count,
                COALESCE(SUM(r.registration_status = 'cancelled'), 0)                                AS cancelled_count,
                COALESCE(SUM(r.payment_status = 'free' AND r.registration_status != 'cancelled'), 0)  AS free_count,
                COALESCE(SUM(
                    r.payment_status = 'pending'
                    AND r.proof_file IS NOT NULL
                    AND r.proof_file != ''
                ), 0) AS proof_pending_count
            FROM otrs_trainings AS ot";

        if (!empty($locationFilter)) {
            // Filter registrations to the selected office BEFORE the LEFT JOIN,
            // so trainings with zero participants from that office still show
            // (with zero counts) instead of disappearing from the list.
            $query .= "
            LEFT JOIN (
                SELECT reg.* FROM otrs_registrations reg
                INNER JOIN otrs_participants p ON p.id = reg.participant_id AND p.location = :location
            ) AS r ON r.training_id = ot.id
            LEFT JOIN otrs_participants AS op ON r.participant_id = op.id";
        } else {
            $query .= "
            LEFT JOIN otrs_registrations AS r  ON r.training_id   = ot.id
            LEFT JOIN otrs_participants  AS op ON r.participant_id = op.id";
        }

        $query .= " WHERE LOWER(ot.training_status) = LOWER(:status)";

        $dir    = ($sortDir === 'DESC') ? 'DESC' : 'ASC';
        $query .= " GROUP BY ot.id ORDER BY ot.start_date $dir";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':status', $statusFilter);
        if (!empty($locationFilter)) {
            $stmt->bindParam(':location', $locationFilter);
        }
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $trngBadgeMap = [
            'completed'                      => 'ts-completed',
            'ongoing registration'           => 'ts-ongoing',
            'accepting payments'             => 'ts-ongoing',
            'ongoing'                        => 'ts-ongoing',
            'upcoming'                       => 'ts-upcoming',
            'scheduled until further notice' => 'ts-default',
            'cancelled'                      => 'ts-cancelled',
            'rescheduled'                    => 'ts-rescheduled',
        ];

        $counter = 1;
        foreach ($result as $row) {
            $badgeClass = $trngBadgeMap[strtolower(trim($row['status_trng']))] ?? 'ts-default';

            $displayDate = '—';
            $hasNewDates = !empty($row['start_date']) && $row['start_date'] != '0000-00-00';
            if ($hasNewDates) {
                $start = new DateTime($row['start_date']);
                $end   = (!empty($row['end_date']) && $row['end_date'] != '0000-00-00') ? new DateTime($row['end_date']) : $start;
                if ($start->format('Y-m-d') === $end->format('Y-m-d')) $displayDate = $start->format('F j, Y');
                elseif ($start->format('Y') != $end->format('Y')) $displayDate = $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
                elseif ($start->format('m') != $end->format('m')) $displayDate = $start->format('M j') . ' – ' . $end->format('M j, Y');
                else                                               $displayDate = $start->format('F j') . '–' . $end->format('j, Y');
            }

            $encodedDates = $hasNewDates
                ? $row['start_date'] . '|' . $row['end_date']
                : '';

            echo '<tr>';
            echo '<td>' . $counter++ . '</td>';
            echo '<td style="width:325px">' . htmlspecialchars($row['name']) . '</td>';
            echo '<td>' . $displayDate . '</td>';
            echo '<td>' . $row['mode'] . '</td>';
            echo '<td class="text-end">' . ((float)$row['fee'] == 0 ? '<span class="fee-free">Free</span>' : '<span class="fee-paid">₱' . number_format($row['fee'], 2) . '</span>') . '</td>';
            echo '<td class="text-center" data-col="slots">' . $row['slots'] . '</td>';
            $baseUrl = 'participants?id=' . $row['id'] . '&location=' . urlencode($locationFilter);
            $cl = static function (int $n, string $filter) use ($baseUrl): string {
                return $n > 0
                    ? '<a href="' . $baseUrl . '&filter=' . urlencode($filter) . '" class="fw-bold text-primary">' . $n . '</a>'
                    : '0';
            };
            echo '<td class="text-center" data-col="participant_status_count" data-filter="All">'           . $cl($row['participant_status_count'], 'All')             . '</td>';
            echo '<td class="text-center" data-col="paid_count"               data-filter="paid">'        . $cl($row['paid_count'],               'paid')            . '</td>';
            echo '<td class="text-center" data-col="promissory_count"         data-filter="promissory note">' . $cl($row['promissory_count'],      'promissory note') . '</td>';
            echo '<td class="text-center" data-col="pending_count"            data-filter="pending">'     . $cl($row['pending_count'],             'pending')         . '</td>';
            echo '<td class="text-center" data-col="free_count"               data-filter="free">'        . $cl($row['free_count'],               'free')            . '</td>';
            echo '<td class="text-center" data-col="cancelled_count"          data-filter="cancelled">'   . $cl($row['cancelled_count'],           'cancelled')       . '</td>';
            echo '<td style="width:160px;">';
            echo '<div class="d-flex align-items-center justify-content-center gap-1 flex-wrap">';

            // Add Participant button
            if ($row['slots'] == 0) {
                echo '<button class="btn btn-success btn-sm add-participant-btn" data-toggle="tooltip" title="Add Participant" data-slots="' . $row['slots'] . '"><i class="bi bi-person-plus-fill"></i></button>';
            } else {
                echo '<button class="btn btn-success btn-sm add-participant-btn"
                        data-toggle="tooltip" title="Add Participant"
                        data-training-id="'    . $row['id']                              . '"
                        data-training-name="'  . htmlspecialchars($row['name'])          . '"
                        data-training-date="'  . htmlspecialchars($displayDate)          . '"
                        data-training-start-date="' . htmlspecialchars($row['start_date'] ?? '') . '"
                        data-training-end-date="'   . htmlspecialchars($row['end_date']   ?? '') . '"
                        data-training-mode="'  . $row['mode']                            . '"
                        data-participant-id="' . $row['id']                              . '"
                        data-slots="'          . $row['slots']                           . '">
                        <i class="bi bi-person-plus-fill"></i>
                    </button>';
            }

            echo '<span class="pop-btn-wrap">';
            echo '<a type="button" href="participants?id=' . $row['id'] . '" class="btn btn-primary btn-sm" data-toggle="tooltip" title="List of Participants"><i class="bi bi-list-ul"></i></a>';
            if ($row['proof_pending_count'] > 0) {
                echo '<span class="pop-count-badge">' . $row['proof_pending_count'] . '</span>';
            }
            echo '</span>';

            $graphDisabled = ($row['paid_count'] == 0 && $row['promissory_count'] == 0 && $row['pending_count'] == 0) ? 'disabled' : '';
            echo '<button type="button" class="btn btn-danger btn-sm view-graph-btn" ' . $graphDisabled . '
                    data-toggle="tooltip" title="View Pie Graph"
                    data-training-id="'    . $row['id']                             . '"
                    data-training-name="'  . htmlspecialchars($row['name'])         . '"
                    data-training-date="'  . htmlspecialchars($displayDate)         . '"
                    data-training-count="' . $row['paid_count']                     . '"
                    data-pending-count="'  . $row['pending_count']                  . '"
                    data-promissory-count="'. $row['promissory_count']              . '">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                </button>';

            if (strpos($row['name'], 'Supervisory Development Course') !== false) {
                echo '<a type="button" href="participants_status?id=' . $row['id'] . '&location=' . $locationFilter . '" class="btn btn-secondary btn-sm view-monitor-btn" data-training-id="' . $row['id'] . '" data-toggle="tooltip" title="View Monitoring Submission"><i class="bi bi-eye"></i></a>';
            }

            if (in_array(strtolower(trim($row['status_trng'])), ['ongoing registration', 'accepting payments'])) {
                echo '<div style="position:relative;display:inline-flex;">';
                echo '<a type="button" href="proof_payment?id=' . $row['id'] . '&location=' . urlencode($locationFilter) . '" class="btn btn-success btn-sm view-proof-btn" data-training-id="' . htmlspecialchars($row['id']) . '" data-toggle="tooltip" title="Proof of Payment"><i class="bi bi-cash-stack"></i></a>';
                if ($row['proof_pending_count'] > 0) {
                    echo '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark" style="font-size:0.6rem;">' . $row['proof_pending_count'] . '</span>';
                }
                echo '</div>';

                echo '<button type="button" class="btn btn-warning btn-sm view-breakdown-btn"
                        data-toggle="tooltip" title="Participant Breakdown"
                        data-training-id="'    . $row['id']                             . '"
                        data-training-name="'  . htmlspecialchars($row['name'])         . '"
                        data-training-date="'  . htmlspecialchars($displayDate)         . '"
                        data-encoded-dates="'  . htmlspecialchars($encodedDates)        . '">
                        <i class="bi bi-bar-chart-steps"></i>
                    </button>';
            }

            if ($statusFilter === 'ongoing') {
                echo '<button type="button" class="btn btn-success btn-sm mark-complete-btn"
                        data-toggle="tooltip" title="Mark as Completed"
                        data-id="'   . (int)$row['id']               . '"
                        data-name="' . htmlspecialchars($row['name']) . '">
                        <i class="bi bi-check-circle-fill"></i>
                    </button>';
            }

            echo '</div>';
            echo '</td></tr>';
        }
    } catch (PDOException $e) {
        error_log('renderTrainingRows: ' . $e->getMessage());
        echo "<tr><td colspan='12' class='text-center text-danger'>Error loading data.</td></tr>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>OTRS — <?php echo htmlspecialchars($locationText); ?></title>
    <link href="../assets/img/favicon.png" rel="icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/apexcharts/3.40.0/apexcharts.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
    <link href="assets/css/participants.css?v=3" rel="stylesheet">
    <style>
        .sidebar { display: none; }
        .main    { margin-left: 0 !important; }
        #breakdownTable tbody td:not(:first-child) { text-align: center; }

        /* ── Add Participant Modal ─────────────────────────────── */
        #addParticipant .modal-content {
            border: none; border-radius: 14px; overflow: hidden;
            box-shadow: 0 24px 64px rgba(0,0,0,.16);
            max-height: calc(100vh - 3.5rem); display: flex; flex-direction: column;
        }
        #addParticipant .modal-header {
            background: linear-gradient(135deg, #0d6832 0%, #198754 60%, #20a567 100%);
            padding: 1.1rem 1.75rem; flex-shrink: 0; border-bottom: none;
        }
        #addParticipant .modal-tabs {
            display: flex; background: #f4f7fb;
            border-bottom: 1.5px solid #dce4ef; flex-shrink: 0;
            overflow-x: auto; scrollbar-width: none; padding: 0 .75rem; gap: .125rem;
        }
        #addParticipant .modal-tabs::-webkit-scrollbar { display: none; }
        #addParticipant .modal-tab-btn {
            display: flex; align-items: center; gap: .4rem;
            padding: .8rem 1.1rem; font-size: .76rem; font-weight: 600;
            color: #6c7d95; background: none; border: none;
            border-bottom: 2.5px solid transparent; margin-bottom: -1.5px;
            cursor: pointer; white-space: nowrap;
            transition: color .15s, border-color .15s, background .15s; flex-shrink: 0;
        }
        #addParticipant .modal-tab-btn:hover {
            color: #198754; background: rgba(25,135,84,.05); border-radius: 6px 6px 0 0;
        }
        #addParticipant .modal-tab-btn.active {
            color: #198754; border-bottom-color: #198754;
            background: #fff; border-radius: 6px 6px 0 0;
        }
        #addParticipant .modal-tab-btn .tab-icon { font-size: .95rem; }
        #addParticipant .modal-body {
            overflow-y: auto; overflow-x: hidden; padding: 0; flex: 1 1 auto;
        }
        #addParticipant .tab-pane-content { padding: 1.5rem 1.75rem; display: none; }
        #addParticipant .tab-pane-content.active { display: block; }
        #addParticipant .form-section-label {
            display: flex; align-items: center; gap: .5rem;
            font-size: .69rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px;
            color: #198754; border-bottom: 1.5px solid #d4edda;
            padding-bottom: .5rem; margin-top: 1.75rem; margin-bottom: 1.1rem;
        }
        #addParticipant .tab-pane-content > .form-section-label:first-child { margin-top: 0; }
        #addParticipant .form-section-label i { font-size: .88rem; }
        #addParticipant .form-label {
            font-size: .73rem; font-weight: 700; color: #4a5568; margin-bottom: .35rem;
            text-transform: uppercase; letter-spacing: .6px; line-height: 1.3; display: block;
        }
        #addParticipant .form-label .req { color: #dc3545; }
        #addParticipant .form-control,
        #addParticipant .form-select {
            font-size: .86rem; border: 1.5px solid #dce4ef; border-radius: 8px;
            padding: .52rem .8rem; color: #1a2332; line-height: 1.5;
            height: auto; min-height: 38px; transition: border-color .18s, box-shadow .18s;
        }
        #addParticipant .form-control:focus,
        #addParticipant .form-select:focus {
            border-color: #198754; box-shadow: 0 0 0 3px rgba(25,135,84,.12); outline: none;
        }
        #addParticipant .input-group .input-group-text {
            font-size: .82rem; border-radius: 8px 0 0 8px !important;
            border: 1.5px solid #dce4ef; border-right: none !important;
            background: #f7f9fc; color: #6c7d95; padding: .52rem .8rem;
            min-height: 38px; line-height: 1.5;
        }
        #addParticipant .input-group .form-control {
            border-radius: 0 8px 8px 0 !important; border-left: none;
        }
        #addParticipant .modal-footer {
            background: #f8fafd; border-top: 1.5px solid #dce4ef;
            padding: .875rem 1.75rem; flex-shrink: 0;
            display: flex; align-items: center; gap: .625rem;
        }
        .btn-modal-add {
            font-size: .84rem; font-weight: 700; padding: .5rem 1.5rem; border-radius: 8px;
            border: none; background: #198754; color: #fff; cursor: pointer;
            line-height: 1.4; display: flex; align-items: center; gap: .45rem;
            transition: filter .15s, transform .12s;
        }
        .btn-modal-add:hover  { filter: brightness(.9); transform: translateY(-1px); }
        .btn-modal-add:active { filter: brightness(.8); transform: translateY(0); }
        @media (max-width: 576px) {
            #addParticipant .modal-dialog  { margin: .5rem; max-width: 100%; }
            #addParticipant .modal-content { max-height: calc(100vh - 1rem); border-radius: 12px; }
            #addParticipant .tab-pane-content { padding: 1.1rem; }
            #addParticipant .modal-footer  { padding: .75rem 1.1rem; }
            #addParticipant .modal-tabs    { padding: 0 .25rem; }
            #addParticipant .modal-tab-btn { padding: .65rem .875rem; font-size: .72rem; }
        }
        .pop-btn-wrap { position: relative; display: inline-flex; }
        .pop-count-badge { position: absolute; top: -7px; right: -7px; min-width: 18px; height: 18px; padding: 0 4px; background: #e63946; color: #fff; border-radius: 9px; font-size: .67rem; font-weight: 700; line-height: 18px; text-align: center; white-space: nowrap; }
        /* Rescheduled badge */
        .ts-rescheduled { background: #fae8ff; color: #7e22ce; display:inline-flex;align-items:center;padding:.18rem .6rem;border-radius:20px;font-size:.68rem;font-weight:700;white-space:nowrap; }
        /* Auto-uppercase text inputs in Add Participant modal */
        #addParticipant input[type="text"] { text-transform: uppercase; }
        /* Modal header standardization */
        .modal-header-info {
            padding: .7rem 1.25rem .5rem;
            background: #f8faff;
            border-bottom: 1.5px solid #dce4ef;
        }
        .modal-training-name { font-size: .9rem; font-weight: 700; color: #012970; line-height: 1.3; }
        .modal-training-date { font-size: .76rem; color: #64748b; margin-top: .2rem; display:flex; align-items:center; gap:.3rem; }
    </style>
</head>

<body>
<?php imis_include('header') ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-blue"><i class="bi bi-journal-text"></i></div>
                <div>
                    <h1>Trainings &amp; Participants</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Training List</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if ($locationCode): ?>
                <span class="location-badge">
                    <i class="bi bi-geo-alt-fill"></i>
                    <?php echo htmlspecialchars($locationText); ?>
                </span>
                <?php endif; ?>
                <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="otrs-card">
            <div class="otrs-card-body">

                        <!-- Filter bar -->
                        <div class="otrs-filter-bar mb-3">
                            <div class="row align-items-end g-2">
                                <div class="col-md-3 col-lg-3">
                                    <label for="officeSelect" class="form-label">
                                        <i class="bi bi-building me-1"></i> Filter by CSC Field Office
                                    </label>
                                    <select class="form-select" id="officeSelect">
                                        <option value="">All — Consolidated Participants</option>
                                        <?php foreach ($offices as $key => $data): ?>
                                            <option value="<?php echo $key; ?>"><?php echo $data['title']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Card top bar: tabs + toolbar -->
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">

                            <div class="seg-tabs" id="trainingTabs" role="tablist">
                                <button class="seg-tab active" id="upcomingTrainings-tab"
                                        data-bs-toggle="tab" data-bs-target="#upcomingTrainings" type="button" role="tab">
                                    <span class="tab-dot" style="background:#3b82f6;"></span> Upcoming
                                    <?php if ($tabCounts['upcoming'] > 0): ?><span class="badge ms-1" style="background:#3b82f6;font-size:.62rem;"><?php echo $tabCounts['upcoming']; ?></span><?php endif; ?>
                                </button>
                                <button class="seg-tab" id="ongoingTrainings-tab"
                                        data-bs-toggle="tab" data-bs-target="#ongoingTrainings" type="button" role="tab">
                                    <span class="tab-dot" style="background:#f59e0b;"></span> Ongoing
                                    <?php if ($tabCounts['ongoing'] > 0): ?><span class="badge ms-1" style="background:#f59e0b;font-size:.62rem;"><?php echo $tabCounts['ongoing']; ?></span><?php endif; ?>
                                </button>
                                <button class="seg-tab" id="inactiveTrainings-tab"
                                        data-bs-toggle="tab" data-bs-target="#inactiveTrainings" type="button" role="tab">
                                    <span class="tab-dot" style="background:#22c55e;"></span> Completed
                                    <?php if ($tabCounts['completed'] > 0): ?><span class="badge ms-1" style="background:#22c55e;font-size:.62rem;"><?php echo $tabCounts['completed']; ?></span><?php endif; ?>
                                </button>
                                <button class="seg-tab" id="cancelledTrainings-tab"
                                        data-bs-toggle="tab" data-bs-target="#cancelledTrainings" type="button" role="tab">
                                    <span class="tab-dot" style="background:#ef4444;"></span> Cancelled
                                    <?php if ($tabCounts['cancelled'] > 0): ?><span class="badge ms-1" style="background:#ef4444;font-size:.62rem;"><?php echo $tabCounts['cancelled']; ?></span><?php endif; ?>
                                </button>
                                <button class="seg-tab" id="rescheduledTrainings-tab"
                                        data-bs-toggle="tab" data-bs-target="#rescheduledTrainings" type="button" role="tab">
                                    <span class="tab-dot" style="background:#9333ea;"></span> Rescheduled
                                    <?php if ($tabCounts['rescheduled'] > 0): ?><span class="badge ms-1" style="background:#9333ea;font-size:.62rem;"><?php echo $tabCounts['rescheduled']; ?></span><?php endif; ?>
                                </button>
                            </div>

                        <?php
                        // Helper to render a standard training table shell
                        function tableShell($id, $locationText) {
                            echo '<div class="table-responsive">';
                            echo '<table id="' . $id . '" class="table table-bordered table-hover table-striped otrs-table w-100">';
                            echo '<thead><tr>
                                    <th rowspan="2" class="text-center align-middle">#</th>
                                    <th rowspan="2" class="text-center align-middle">Name of Training</th>
                                    <th rowspan="2" class="text-center align-middle">Date</th>
                                    <th rowspan="2" class="text-center align-middle">Mode</th>
                                    <th rowspan="2" class="text-center align-middle">Fee (₱)</th>
                                    <th rowspan="2" class="text-center align-middle">Slots</th>
                                    <th colspan="6" class="text-center align-middle">' . htmlspecialchars($locationText) . '</th>
                                    <th rowspan="2" class="text-center align-middle">Action</th>
                                  </tr>
                                  <tr>
                                    <th>Total</th><th>Paid</th><th>Promis.</th><th>Pending</th><th>Free</th><th>Cancelled</th>
                                  </tr></thead><tbody>';
                        }
                        ?>

                            <div class="otrs-toolbar">
                                <button id="exportButton" class="btn-f btn-f-success">
                                    <i class="bi bi-file-earmark-excel"></i> Export
                                </button>
                                <button type="button" class="btn-f btn-f-primary"
                                        data-bs-toggle="modal" data-bs-target="#breakdownModal">
                                    <i class="bi bi-diagram-3-fill"></i> FO Breakdown
                                </button>
                                <button type="button" class="btn-f btn-f-info"
                                        data-bs-toggle="modal" data-bs-target="#breakdownGender">
                                    <i class="bi bi-gender-ambiguous"></i> Gender Data
                                </button>
                            </div>

                        </div><!-- /.d-flex tabs + toolbar -->

                        <div class="tab-content" id="trainingTabContent">

                            <div class="tab-pane fade" id="ongoingTrainings" role="tabpanel">
                                <?php tableShell('trainingTableOngoing', $locationText); ?>
                                    <?php renderTrainingRows('ongoing', $locationCode); ?>
                                </tbody></table></div>
                            </div>

                            <div class="tab-pane fade show active" id="upcomingTrainings" role="tabpanel">
                                <?php tableShell('trainingTableUpcoming', $locationText); ?>
                                    <?php renderTrainingRows('upcoming', $locationCode); ?>
                                </tbody></table></div>
                            </div>

                            <div class="tab-pane fade" id="inactiveTrainings" role="tabpanel">
                                <?php tableShell('trainingTableInactive', $locationText); ?>
                                    <?php renderTrainingRows('completed', $locationCode, 'DESC'); ?>
                                </tbody></table></div>
                            </div>

                            <div class="tab-pane fade" id="cancelledTrainings" role="tabpanel">
                                <?php tableShell('trainingTableCancelled', $locationText); ?>
                                    <?php renderTrainingRows('cancelled', $locationCode, 'DESC'); ?>
                                </tbody></table></div>
                            </div>

                            <div class="tab-pane fade" id="rescheduledTrainings" role="tabpanel">
                                <?php tableShell('trainingTableRescheduled', $locationText); ?>
                                    <?php renderTrainingRows('rescheduled', $locationCode); ?>
                                </tbody></table></div>
                            </div>

                        </div><!-- /.tab-content -->
            </div><!-- /.otrs-card-body -->
        </div><!-- /.otrs-card -->
    </section>
</main>

<?php imis_include('footer_db') ?>

<!-- ══════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════ -->

<!-- Add Participant Modal -->
<div class="modal fade" id="addParticipant" aria-hidden="true" aria-labelledby="addParticipantLabel" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header">
                <div class="modal-title-wrap flex-grow-1">
                    <div class="modal-avatar"><i class="bi bi-person-plus-fill"></i></div>
                    <div class="modal-title-text">
                        <h6 id="addParticipantLabel">Add Participant</h6>
                        <small><span id="trainingNameHeader">—</span> &mdash; <span id="trainingDateHeader"></span></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Tabs -->
            <div class="modal-tabs" role="tablist">
                <button class="modal-tab-btn active" data-tab="addtab-personal" type="button">
                    <i class="bi bi-person-fill tab-icon"></i> Personal
                </button>
                <button class="modal-tab-btn" data-tab="addtab-professional" type="button">
                    <i class="bi bi-briefcase-fill tab-icon"></i> Professional
                </button>
                <button class="modal-tab-btn" data-tab="addtab-agency" type="button">
                    <i class="bi bi-building tab-icon"></i> Agency
                </button>
                <button class="modal-tab-btn" data-tab="addtab-consent" type="button">
                    <i class="bi bi-shield-check tab-icon"></i> Consent
                </button>
            </div>

            <!-- Body -->
            <div class="modal-body">
                <form id="addParticipantForm" novalidate>
                    <input type="hidden" id="trainingIdInput"   name="trainingId">
                    <input type="hidden" id="trainingModeInput" name="trainingMode">

                    <!-- ── Tab 1: Personal ─────────────────────────────── -->
                    <div class="tab-pane-content active" id="addtab-personal">

                        <div class="form-section-label"><i class="bi bi-person-vcard"></i> Identity</div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-4">
                                <label class="form-label">First Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="fname" name="fname" placeholder="e.g. Juan" required>
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label">M.I.</label>
                                <input type="text" class="form-control text-center" id="mname" name="mname" maxlength="2" placeholder="A">
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label">Last Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="lname" name="lname" placeholder="e.g. dela Cruz" required>
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label">Suffix</label>
                                <input type="text" class="form-control" id="suffix" name="suffix" placeholder="Jr., Sr." maxlength="16">
                            </div>
                        </div>

                        <div class="form-section-label"><i class="bi bi-person-lines-fill"></i> Demographics</div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" id="dob" name="dob">
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label">Gender <span class="req">*</span></label>
                                <select class="form-select" id="gender" name="gender" required>
                                    <option value="">— Select —</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Others">Others</option>
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label">Civil Status</label>
                                <select class="form-select" id="civilstatus" name="civil_status">
                                    <option value="">— Select —</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Others">Others</option>
                                </select>
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label">PWD?</label>
                                <select class="form-select" name="pwd">
                                    <option value="No" selected>No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-section-label"><i class="bi bi-envelope-at-fill"></i> Contact</div>
                        <div class="row g-3">
                            <div class="col-sm-7">
                                <label class="form-label">Email Address <span class="req">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="juandelacruz@agency.gov.ph" required>
                                </div>
                            </div>
                            <div class="col-sm-5">
                                <label class="form-label">Mobile No. <span class="req">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">+63</span>
                                    <input type="tel" class="form-control" id="mobileno" name="mobile_no" maxlength="11" placeholder="09XXXXXXXXX" required>
                                </div>
                            </div>
                        </div>

                    </div><!-- /addtab-personal -->

                    <!-- ── Tab 2: Professional ─────────────────────────── -->
                    <div class="tab-pane-content" id="addtab-professional">

                        <div class="form-section-label"><i class="bi bi-award-fill"></i> Position Details</div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-8">
                                <label class="form-label">Position Title <span class="req">*</span></label>
                                <input type="text" class="form-control" id="positiontitle" name="position_title" placeholder="e.g. Administrative Officer II" required>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label">Salary Grade</label>
                                <input type="text" class="form-control" name="salary_grade" placeholder="e.g. SG-11" maxlength="12">
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label">Position Level</label>
                                <select class="form-select" name="position_level">
                                    <option value="">— Select —</option>
                                    <option value="First Level">First Level</option>
                                    <option value="Second Level (Non-Supervisory)">Second Level (Rank-and-File)</option>
                                    <option value="Second Level (Supervisory)">Second Level (Supervisory)</option>
                                    <option value="Second Level (Executive/Managerial)">Second Level (Executive/Managerial)</option>
                                    <option value="Third Level">Third Level</option>
                                    <option value="N/A">N/A</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">CSC Field Office <span class="req">*</span></label>
                                <select class="form-select" name="location" required>
                                    <option value="" disabled selected>Select CSC Field Office…</option>
                                    <option value="LFOI">CSC Field Office — Leyte I</option>
                                    <option value="LFOII">CSC Field Office — Leyte II</option>
                                    <option value="WLSO">CSC Satellite Office — Western Leyte</option>
                                    <option value="SLFO">CSC Field Office — Southern Leyte</option>
                                    <option value="BFO">CSC Field Office — Biliran</option>
                                    <option value="SFO">CSC Field Office — Samar</option>
                                    <option value="ESFO">CSC Field Office — Eastern Samar</option>
                                    <option value="NSFO">CSC Field Office — Northern Samar</option>
                                    <option value="HRD">Outside Region VIII / Others</option>
                                </select>
                            </div>
                        </div>

                    </div><!-- /addtab-professional -->

                    <!-- ── Tab 3: Agency ───────────────────────────────── -->
                    <div class="tab-pane-content" id="addtab-agency">

                        <div class="form-section-label"><i class="bi bi-building"></i> Agency Information</div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Agency / Office Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="agency_name" name="agency_name" placeholder="e.g. Department of Education — Division of Tacloban City" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Agency Unit / Division</label>
                                <input type="text" class="form-control" id="agency_unit" name="agency_unit" placeholder="e.g. Administrative Division">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-4">
                                <label class="form-label">Sector <span class="req">*</span></label>
                                <select class="form-select" name="sector" required>
                                    <option value="" disabled selected>Select…</option>
                                    <option value="NGA">NGA</option>
                                    <option value="SUC">SUC</option>
                                    <option value="GOCC/Water Districts">GOCC / Water Districts</option>
                                    <option value="LGU">LGU</option>
                                    <option value="Local Hospitals">Local Hospitals</option>
                                    <option value="Other Agencies/Private Institutions">Other Agencies / Private Institutions</option>
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label">Geographical Location</label>
                                <select class="form-select" name="geo">
                                    <option value="" disabled selected>Select…</option>
                                    <option value="Province of Leyte">Province of Leyte</option>
                                    <option value="Province of Southern Leyte">Province of Southern Leyte</option>
                                    <option value="Province of Biliran">Province of Biliran</option>
                                    <option value="Province of Samar">Province of Samar</option>
                                    <option value="Province of Eastern Samar">Province of Eastern Samar</option>
                                    <option value="Province of Northern Samar">Province of Northern Samar</option>
                                    <option value="Outside Region VIII">Outside Region VIII</option>
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label">Agency Address</label>
                                <input type="text" class="form-control" id="agency_address" name="agency_address" placeholder="Street, Municipality, Province">
                            </div>
                        </div>

                        <div class="form-section-label"><i class="bi bi-house-fill"></i> Residence</div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Home Address</label>
                                <input type="text" class="form-control" id="home_address" name="home_address" placeholder="Barangay, Municipality, Province">
                            </div>
                        </div>

                        <div class="food-row" style="display:none;">
                            <div class="form-section-label"><i class="bi bi-egg-fried"></i> Dietary</div>
                            <div class="row g-3 mb-3">
                                <div class="col-sm-4">
                                    <label class="form-label">Has Food Restrictions?</label>
                                    <select class="form-select" id="hasFoodRestrictions" name="qtn_food">
                                        <option value="No" selected>No</option>
                                        <option value="Yes">Yes</option>
                                    </select>
                                </div>
                                <div class="col-sm-8" id="foodRestrictionsInput" style="display:none;">
                                    <label class="form-label">Specify Restrictions</label>
                                    <input type="text" class="form-control" name="food_restrictions" placeholder="e.g. Vegetarian, no shellfish…">
                                </div>
                            </div>
                        </div>

                    </div><!-- /addtab-agency -->

                    <!-- ── Tab 4: Consent ──────────────────────────────── -->
                    <div class="tab-pane-content" id="addtab-consent">

                        <div class="form-section-label"><i class="bi bi-shield-lock-fill"></i> Privacy &amp; Agreements</div>

                        <p style="font-size:.87rem;line-height:1.6;">I hereby agree and give consent for the processing of my personal data information, as well as agree to be bound by the <b>Privacy Policy</b>. <span class="req">*</span></p>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="radio" name="radioPersonal" id="radioPersonal" value="Yes" required>
                            <label class="form-check-label fw-semibold" for="radioPersonal">Yes, I agree</label>
                        </div>

                        <hr>

                        <p style="font-size:.87rem;line-height:1.6;"><b>Yes, I hereby agree that by registering for this seminar, I will be charged with the amount equivalent to 100% of the registration fee if I fail to attend the training or cancel my registration within <span class="text-danger">seven (7) days</span> before the conduct of the said training.</b> <span class="req">*</span></p>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="radio" name="radioCharge" id="radioCharge" value="Yes" required>
                            <label class="form-check-label fw-semibold" for="radioCharge">Yes, I agree</label>
                        </div>

                        <hr>

                        <p style="font-size:.87rem;line-height:1.6;">I agree that the <b>Certificate of Completion</b> shall only be issued to me if I pay the registration fee and completely submit all the accomplished Session Journals, Level 1 Evaluation tools, and other activity outputs. <span class="req">*</span></p>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="radio" name="radioCert" id="radioCert" value="Yes" required>
                            <label class="form-check-label fw-semibold" for="radioCert">Yes, I agree</label>
                        </div>

                        <p class="text-center text-muted" style="font-size:.78rem;">Data information asked will be exclusively used for the purpose of training. Contact details are solely for contacting participants and will not be shared without consent.</p>

                    </div><!-- /addtab-consent -->

                </form>
            </div><!-- /modal-body -->

            <!-- Footer -->
            <div class="modal-footer">
                <span style="font-size:.73rem;color:#a0aab5;margin-right:auto;display:flex;align-items:center;gap:.35rem;">
                    <i class="bi bi-shield-check text-success"></i> Fill all tabs before submitting.
                </span>
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i> Close
                </button>
                <button type="submit" form="addParticipantForm" class="btn-modal-add">
                    <i class="bi bi-person-plus-fill me-1"></i> Add Participant
                </button>
            </div>

        </div><!-- /modal-content -->
    </div><!-- /modal-dialog -->
</div>

<!-- Statistics Modal -->
<div class="modal fade" id="statisticsModal" tabindex="-1" role="dialog" aria-labelledby="statisticsModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#012970,#0077b6);border:none;padding:.9rem 1.25rem;">
                <div>
                    <div style="font-size:.7rem;font-weight:600;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.8px;margin-bottom:.2rem;">Payment Status</div>
                    <span class="modal-title" id="statisticsModalLabel" style="color:#fff;font-size:.88rem;font-weight:700;"></span>
                </div>
                <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"><div id="chart"></div></div>
        </div>
    </div>
</div>

<!-- Breakdown per FO Modal -->
<div class="modal fade" id="breakdownModal" tabindex="-1" aria-labelledby="breakdownModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#1d4ed8,#0077b6);border:none;padding:.9rem 1.25rem;">
                <div>
                    <div style="font-size:.7rem;font-weight:600;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.8px;margin-bottom:.2rem;"><i class="bi bi-diagram-3-fill me-1"></i>FO Breakdown</div>
                    <p class="modal-title mb-0" id="breakdownModalLabel" style="color:#fff;font-size:.92rem;font-weight:700;">Breakdown Data per CSC Field Office</p>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php
            // Build a map: unique name → [{id, date_label, sort_key}]
            $bdByName = [];
            try {
                $stmtBd = $conn->prepare("
                    SELECT id, name, start_date, end_date
                    FROM otrs_trainings
                    WHERE LOWER(training_status) != 'cancelled'
                    ORDER BY name ASC, start_date DESC
                ");
                $stmtBd->execute();
                $bdTrainings = $stmtBd->fetchAll(PDO::FETCH_ASSOC);
                foreach ($bdTrainings as $t) {
                    if (!empty($t['start_date']) && $t['start_date'] != '0000-00-00') {
                        $s = new DateTime($t['start_date']);
                        $e = (!empty($t['end_date']) && $t['end_date'] != '0000-00-00') ? new DateTime($t['end_date']) : $s;
                        $bdDate = ($s->format('Y-m-d') === $e->format('Y-m-d'))
                            ? $s->format('F j, Y')
                            : (($s->format('mY') === $e->format('mY'))
                                ? $s->format('F j') . '–' . $e->format('j, Y')
                                : $s->format('M j') . ' – ' . $e->format('M j, Y'));
                        $sortKey = $t['start_date'];
                    } else {
                        $bdDate  = 'No date set';
                        $sortKey = '0000-00-00';
                    }
                    $bdByName[$t['name']][] = ['id' => (int)$t['id'], 'date' => $bdDate, 'sort' => $sortKey];
                }
            } catch (PDOException $e) { $bdByName = []; }
            ?>
            <div class="modal-body">
                <div class="row g-2 align-items-end mb-3">
                    <!-- Dropdown 1: unique training names -->
                    <div class="col-md-7">
                        <label class="form-label fw-semibold mb-1" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.5px;">Training Name</label>
                        <select class="form-select form-select-sm" id="bdNameSelect">
                            <option value="" hidden>— Select training name —</option>
                            <?php foreach (array_keys($bdByName) as $nm): ?>
                                <option value="<?php echo htmlspecialchars($nm); ?>"><?php echo htmlspecialchars($nm); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Dropdown 2: dates for selected name (populated by JS) -->
                    <div class="col-md-5" id="bdDateWrapper" style="display:none;">
                        <label class="form-label fw-semibold mb-1" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.5px;">Date of Conduct</label>
                        <select class="form-select form-select-sm" id="bdDateSelect">
                            <option value="" hidden>— Select date —</option>
                        </select>
                    </div>
                </div>

                <!-- Info row: training name + date on the same line -->
                <div id="bdSelectedInfo" class="d-none mb-3 px-3 py-2 rounded-3" style="background:#f0f9ff;border:1.5px solid #bae6fd;">
                    <div class="d-inline-flex align-items-center gap-2">
                        <div class="modal-training-name" id="bdSelectedName"></div>
                        <div class="vr" style="height:1.2em;opacity:.4;"></div>
                        <div class="modal-training-date"><i class="bi bi-calendar3"></i><span id="bdSelectedDate"></span></div>
                    </div>
                </div>

                <!-- Training map for JS -->
                <script>const bdTrainingMap = <?php echo json_encode($bdByName, JSON_HEX_TAG | JSON_HEX_APOS); ?>;</script>

                <div class="table-responsive">
                    <table id="breakdownTable" class="table table-bordered table-hover table-striped" style="font-size:14px;">
                        <thead>
                            <tr>
                                <th style="text-align:center;vertical-align:middle;width:300px">CSC Field Office / Satellite Office</th>
                                <th style="text-align:center;vertical-align:middle;">Total Pax</th>
                                <th style="text-align:center;vertical-align:middle;">Paid</th>
                                <th style="text-align:center;vertical-align:middle;">Promissory Note</th>
                                <th style="text-align:center;vertical-align:middle;">Pending</th>
                                <th style="text-align:center;vertical-align:middle;">Free</th>
                                <th style="text-align:center;vertical-align:middle;">Cancelled</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot>
                            <tr>
                                <th style="text-align:right;">Overall Total:</th>
                                <th style="text-align:center;"></th><th style="text-align:center;"></th>
                                <th style="text-align:center;"></th><th style="text-align:center;"></th>
                                <th style="text-align:center;"></th><th style="text-align:center;"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Gender Breakdown Modal -->
<div class="modal fade" id="breakdownGender" tabindex="-1" aria-labelledby="breakdownGenderLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#0891b2,#0369a1);border:none;padding:.9rem 1.25rem;">
                <div>
                    <div style="font-size:.7rem;font-weight:600;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.8px;margin-bottom:.2rem;"><i class="bi bi-gender-ambiguous me-1"></i>Gender Data</div>
                    <p class="modal-title mb-0" id="breakdownGenderLabel" style="color:#fff;font-size:.92rem;font-weight:700;">Sex Disaggregated Data on Trainings</p>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table id="breakdownGenderTable" class="table table-bordered table-hover table-striped" style="font-size:14px;width:100%">
                        <thead>
                            <tr>
                                <th style="text-align:center;vertical-align:middle;width:600px" rowspan="2">Name of Training</th>
                                <th style="text-align:center;vertical-align:middle;width:300px" rowspan="2">Date of Training</th>
                                <th style="text-align:center;vertical-align:middle;" colspan="5">Male</th>
                                <th style="text-align:center;vertical-align:middle;" colspan="5">Female</th>
                            </tr>
                            <tr>
                                <th style="text-align:center;">Gen Z</th><th style="text-align:center;">Millenial</th><th style="text-align:center;">Gen X</th><th style="text-align:center;">Babyboomers</th><th style="text-align:center;">Not Indicated</th>
                                <th style="text-align:center;">Gen Z</th><th style="text-align:center;">Millenial</th><th style="text-align:center;">Gen X</th><th style="text-align:center;">Babyboomers</th><th style="text-align:center;">Not Indicated</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success me-auto" id="exportButtonGender"><i class="bi bi-file-earmark-excel"></i> Export to Excel</button>
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Participant Breakdown Modal -->
<div class="modal fade" id="breakdownDetailModal" tabindex="-1" aria-labelledby="breakdownDetailLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#b45309,#d97706);border:none;padding:.9rem 1.25rem;">
                <div>
                    <div style="font-size:.7rem;font-weight:600;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.8px;margin-bottom:.2rem;"><i class="bi bi-bar-chart-steps me-1"></i>Participant Breakdown</div>
                    <span class="modal-title" id="breakdownDetailLabel" style="color:#fff;font-size:.92rem;font-weight:700;"></span>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <!-- Training name + date separated below header -->
            <div class="modal-header-info" id="breakdownDetailInfoBar" style="display:none;">
                <div class="modal-training-name" id="breakdownDetailTrainingName"></div>
                <div class="modal-training-date"><i class="bi bi-calendar3"></i><span id="breakdownDetailTrainingDate"></span></div>
            </div>
            <div class="modal-body">
                <div id="breakdownDetailOutput">
                    <div class="text-center text-muted py-4" style="font-size:13px;">Loading…</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Close</button>
            </div>
        </div>
    </div>
</div>

<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0"></script>
<script type="text/javascript" src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/apexcharts/3.40.0/apexcharts.min.js"></script>
<script src="breakdown-renderer.js"></script>

<script>
$(function () {

    let chartInstance = null;

    const locationMap = {
        'LFOI': 'CSC Field Office - Leyte I', 'LFOII': 'CSC Field Office - Leyte II',
        'BFO': 'CSC Field Office - Biliran',  'WLSO': 'CSC Satellite Office - Western Leyte',
        'SLFO': 'CSC Field Office - Southern Leyte', 'SFO': 'CSC Field Office - Samar',
        'ESFO': 'CSC Field Office - Eastern Samar',  'NSFO': 'CSC Field Office - Northern Samar',
        'HRD': 'CSC RO VIII - HRD', 'RO6': 'CSC Regional Office VI', 'RO7': 'CSC Regional Office VII'
    };

    function getExportFilename() {
        const loc = new URLSearchParams(window.location.search).get('location') || 'hrd';
        const d = new Date();
        return `${loc}_participants_data_${d.getMonth()+1}-${d.getDate()}-${d.getFullYear()}`;
    }

    $('[data-toggle="tooltip"]').tooltip();

    // Breakdown table
    const breakdownTable = $("#breakdownTable").DataTable({
        footerCallback: function () {
            const api = this.api();
            const intVal = i => typeof i === 'string' ? i.replace(/[\$,]/g, '') * 1 : typeof i === 'number' ? i : 0;
            for (let i = 1; i <= 6; i++) {
                const total = api.column(i, { page: 'current' }).data().reduce((a, b) => intVal(a) + intVal(b), 0);
                $(api.column(i).footer()).html(total);
            }
        }
    });

    // Cascade: name → populate date dropdown
    $('#bdNameSelect').on('change', function () {
        const name = $(this).val();
        if (!name) return;

        const sessions = bdTrainingMap[name] || [];
        const $dateSelect = $('#bdDateSelect');
        $dateSelect.find('option:not([hidden])').remove();

        sessions.forEach(function (s) {
            $dateSelect.append($('<option>', { value: s.id, text: s.date }));
        });

        // If only one date available, auto-select and trigger fetch
        if (sessions.length === 1) {
            $dateSelect.val(sessions[0].id).trigger('change');
        } else {
            $('#bdDateWrapper').show();
            $dateSelect.val('');
            $('#bdSelectedInfo').addClass('d-none');
            breakdownTable.clear().draw();
        }
        $('#bdDateWrapper').show();
    });

    // Date selected → fetch breakdown
    $('#bdDateSelect').on('change', function () {
        const trainingId = $(this).val();
        if (!trainingId) return;

        const name = $('#bdNameSelect').val();
        const date = $(this).find('option:selected').text();
        $('#bdSelectedName').text(name);
        $('#bdSelectedDate').text(date);
        $('#bdSelectedInfo').removeClass('d-none');

        breakdownTable.clear().draw();
        $.ajax({
            type: 'POST', url: 'api/fetch_counts.php',
            data: { trainingId: trainingId, _csrf: window.csrfToken ?? '' },
            headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
            dataType: 'json',
            success: function (data) {
                if (data.error) { Swal.fire({ icon: 'error', title: 'Error', text: data.error }); return; }
                const rows = data.map(item => {
                    const label = locationMap[item.location] || item.location;
                    const paid  = parseInt(item.paid, 10) || 0;
                    const promo = parseInt(item['promissory note'], 10) || 0;
                    const pend  = parseInt(item.pending, 10) || 0;
                    const free  = parseInt(item.free, 10) || 0;
                    return [label, paid + promo + pend + free, item.paid, item['promissory note'], item.pending, item.free, item.cancelled];
                });
                breakdownTable.rows.add(rows).draw();
            },
            error: function (xhr) {
                console.error(xhr);
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Failed to fetch data.' });
            }
        });
    });

    // Main tables — all initialized with Excel export buttons
    const dtOptions = {
        autoWidth: false,
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        buttons: [{ extend: 'excel', text: 'Export to Excel', filename: getExportFilename, exportOptions: { columns: ':not(:last-child)' } }],
        language: { search: '<i class="bi bi-search"></i>', searchPlaceholder: 'Search', lengthMenu: 'Show _MENU_ entries', paginate: { next: '<i class="bi bi-chevron-right"></i>', previous: '<i class="bi bi-chevron-left"></i>' } },
        lengthMenu: [10, 50, 100], pageLength: 10
    };
    const dtOngoing      = $('#trainingTableOngoing').DataTable(dtOptions);
    const dtUpcoming     = $('#trainingTableUpcoming').DataTable(dtOptions);
    const dtInactive     = $('#trainingTableInactive').DataTable(dtOptions);
    const dtCancelled    = $('#trainingTableCancelled').DataTable(dtOptions);
    const dtRescheduled  = $('#trainingTableRescheduled').DataTable(dtOptions);
    [dtOngoing, dtUpcoming, dtInactive, dtCancelled, dtRescheduled].forEach(dt => dt.buttons('.buttons-excel').container().hide());

    // Export active tab's table
    const tabTableMap = {
        'ongoingTrainings':     dtOngoing,
        'upcomingTrainings':    dtUpcoming,
        'inactiveTrainings':    dtInactive,
        'cancelledTrainings':   dtCancelled,
        'rescheduledTrainings': dtRescheduled,
    };
    $('#exportButton').click(function () {
        const activePaneId = $('#trainingTabContent .tab-pane.show.active').attr('id');
        const dt = tabTableMap[activePaneId];
        if (dt) dt.button('.buttons-excel').trigger();
    });

    new bootstrap.Tab(document.getElementById('upcomingTrainings-tab')).show();

    // Add Participant modal — tab switching
    $('#addParticipant').on('click', '.modal-tab-btn', function () {
        const tab = $(this).data('tab');
        $('#addParticipant .modal-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('#addParticipant .tab-pane-content').removeClass('active');
        $('#' + tab).addClass('active');
    });

    // Food restrictions toggle within agency tab
    $(document).on('change', '#hasFoodRestrictions', function () {
        $('#foodRestrictionsInput').toggle($(this).val() === 'Yes');
    });

    // Add Participant button click
    let currentAddBtn = null;

    $(document).on('click', '.add-participant-btn', function () {
        currentAddBtn = $(this);
        const data = $(this).data();
        if (data.slots <= 0) {
            Swal.fire({ icon: 'warning', title: 'No Slots Available', text: 'There are no slots available for this training.', confirmButtonText: 'Ok' });
            return;
        }

        // Reset form and re-populate hidden fields after reset
        $('#addParticipantForm')[0].reset();
        $('#foodRestrictionsInput').hide();

        $('#trainingIdInput').val(data.trainingId);
        $('#trainingModeInput').val(data.trainingMode);
        $('#trainingNameHeader').text(data.trainingName);
        $('#trainingDateHeader').text(data.trainingDate);

        // Show food-row only for Face-to-Face
        $('#addtab-agency .food-row').toggle(data.trainingMode === 'Face to Face');

        // Reset to first tab
        $('#addParticipant .modal-tab-btn').removeClass('active');
        $('#addParticipant .modal-tab-btn[data-tab="addtab-personal"]').addClass('active');
        $('#addParticipant .tab-pane-content').removeClass('active');
        $('#addtab-personal').addClass('active');

        $('#addParticipant').modal('show');
    });

    // Add Participant form submit
    $('#addParticipantForm').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            url: 'api/add_participant.php', type: 'POST',
            data: $(this).serialize() + '&_csrf=' + encodeURIComponent(window.csrfToken ?? ''),
            headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
            dataType: 'json',
            success: function (response) {
                if (response.success && response.waitlisted) {
                    $('#addParticipantForm')[0].reset();
                    $('#addParticipant').modal('hide');
                    Swal.fire({ icon: 'info', title: 'Added to Waitlist', html: response.message, confirmButtonText: 'OK', confirmButtonColor: '#d97706' });
                } else if (response.success) {
                    const trainingId = currentAddBtn ? currentAddBtn.data('trainingId') : null;
                    $('#addParticipantForm')[0].reset();
                    $('#addParticipant').one('hidden.bs.modal', function () {
                        Swal.fire({ icon: 'success', title: 'Participant Added', html: response.message, allowOutsideClick: false })
                            .then(() => {
                                if (!trainingId) return;
                                $.getJSON('api/fetch_training_row.php', { id: trainingId }, function (res) {
                                    if (!res.success || !currentAddBtn) return;
                                    const d    = res.data;
                                    const $row = currentAddBtn.closest('tr');
                                    const loc  = new URLSearchParams(window.location.search).get('location') || '';
                                    const base = 'participants?id=' + trainingId + '&location=' + encodeURIComponent(loc);

                                    // Update slot cell + button
                                    $row.find('[data-col="slots"]').text(d.slots);
                                    currentAddBtn.data('slots', d.slots).attr('data-slots', d.slots);
                                    if (d.slots <= 0) currentAddBtn.prop('disabled', true).attr('title', 'No slots available');

                                    // Update each count cell
                                    $row.find('td[data-col][data-filter]').each(function () {
                                        const col    = $(this).data('col');
                                        const filter = $(this).data('filter');
                                        const n      = d[col] ?? 0;
                                        $(this).html(n > 0
                                            ? '<a href="' + base + '&filter=' + encodeURIComponent(filter) + '" class="fw-bold text-primary">' + n + '</a>'
                                            : '0');
                                    });

                                    // Invalidate the row in whichever DataTable is active
                                    const activePaneId2 = $('#trainingTabContent .tab-pane.show.active').attr('id');
                                    const activeDt = tabTableMap[activePaneId2];
                                    if (activeDt) activeDt.row($row).invalidate().draw(false);
                                });
                            });
                    });
                    $('#addParticipant').modal('hide');
                } else if (response.duplicate) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Already Registered',
                        html: response.message,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#f59e0b',
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', html: response.message });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Oops! Something went wrong.' }); }
        });
    });

    // Pie chart
    $(document).on('click', '.view-graph-btn', function () {
        const { trainingName, trainingDate, trainingCount: paid, pendingCount: pending, promissoryCount: promo } = $(this).data();
        if (chartInstance) chartInstance.destroy();
        const options = {
            chart: { height: 350, type: 'pie', toolbar: { show: true } },
            labels: ['paid', 'pending', 'promissory note'],
            series: [paid, pending, promo],
            colors: ['#198754', '#0d6efd', '#ffc107'],
            tooltip: { y: { formatter: function (val) { const total = paid + pending + promo; return `${val} (${total > 0 ? ((val / total) * 100).toFixed(2) : 0}%)`; } } },
            responsive: [{ breakpoint: 480, options: { chart: { width: 200 }, legend: { position: 'bottom' } } }]
        };
        chartInstance = new ApexCharts(document.querySelector("#chart"), options);
        chartInstance.render();
        // Header shows training name; date is sub-label via the header layout
        $('#statisticsModalLabel').html(trainingName + (trainingDate ? '<span style="font-weight:400;opacity:.75;font-size:.78rem;margin-left:.5rem;"><i class="bi bi-calendar3"></i> ' + trainingDate + '</span>' : ''));
        $('#statisticsModal').modal('show');
    });

    // Gender breakdown
    const genderTable = $('#breakdownGenderTable').DataTable({
        ajax: {
            url: 'api/fetch_gender_age_breakdown.php', method: 'GET',
            dataSrc: function (json) { if (json.error) { alert(json.error); return []; } return json; },
            error: function () { alert('An error occurred while fetching data.'); }
        },
        columns: [
            { data: 'training_name',        title: 'Training Name' },
            { data: 'training_date',         title: 'Training Date' },
            { data: 'male_gen_z',            title: 'Gen Z',         className: 'text-center' },
            { data: 'male_millennial',       title: 'Millennial',    className: 'text-center' },
            { data: 'male_gen_x',            title: 'Gen X',         className: 'text-center' },
            { data: 'male_babyboomers',      title: 'Baby Boomers',  className: 'text-center' },
            { data: 'male_not_indicated',    title: 'Not Indicated', className: 'text-center' },
            { data: 'female_gen_z',          title: 'Gen Z',         className: 'text-center' },
            { data: 'female_millennial',     title: 'Millennial',    className: 'text-center' },
            { data: 'female_gen_x',          title: 'Gen X',         className: 'text-center' },
            { data: 'female_babyboomers',    title: 'Baby Boomers',  className: 'text-center' },
            { data: 'female_not_indicated',  title: 'Not Indicated', className: 'text-center' }
        ]
    });
    $('#breakdownGender').on('show.bs.modal', function () { genderTable.ajax.reload(); });

    // Reset breakdown modal state when hidden
    $('#breakdownModal').on('hidden.bs.modal', function () {
        $('#bdNameSelect').val('');
        $('#bdDateSelect').find('option:not([hidden])').remove();
        $('#bdDateWrapper').hide();
        $('#bdSelectedInfo').addClass('d-none');
        breakdownTable.clear().draw();
    });

    // Office filter redirect
    $('#officeSelect').on('change', function () {
        const value = this.value;
        const base  = 'https://imis.cscro8.com/otrs/hrd/index_trainings';
        window.location.href = value ? `${base}?location=${value}` : base;
    });
    const currentLoc = new URLSearchParams(window.location.search).get('location');
    if (currentLoc) $('#officeSelect').val(currentLoc);

    // Mark as Completed
    $(document).on('click', '.mark-complete-btn', function () {
        const id   = $(this).data('id');
        const name = $(this).data('name');
        Swal.fire({
            title: 'Mark as Completed?',
            html: `Are you sure you want to mark <b>${name}</b> as <b>Completed</b>?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            confirmButtonText: 'Yes, Mark Completed',
            cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            fetch('api/mark_training_completed.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.csrfToken ?? '' },
                body: new URLSearchParams({ training_id: id, _csrf: window.csrfToken ?? '' })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Marked as Completed', text: data.message })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed. Please try again.' }));
        });
    });

    // Participant breakdown button
    // NEW: reads data-encoded-dates instead of legacy data-training-date for the API call
    const renderer = new BreakdownRenderer('#breakdownDetailOutput', {
        apiUrl: 'api/fetch_breakdown.php', showPct: true, exportable: true,
    });

    $(document).on('click', '.view-breakdown-btn', function () {
        const id           = $(this).data('training-id');
        const name         = $(this).data('training-name');
        const displayDate  = $(this).data('training-date');    // formatted string for UI only
        const encodedDates = $(this).data('encoded-dates');    // "start|end" for API

        // Header: just "Participant Breakdown"; name + date shown in info bar below
        $('#breakdownDetailLabel').text('Participant Breakdown');
        $('#breakdownDetailTrainingName').text(name);
        $('#breakdownDetailTrainingDate').text(displayDate + ' · Paid, Promissory Note & Pending');
        $('#breakdownDetailInfoBar').show();
        $('#breakdownDetailModal').modal('show');

        $('#breakdownDetailModal').one('shown.bs.modal', function () {
            // Pass encoded dates so fetch_breakdown.php can use start/end date columns
            renderer.load({ training_id: id, encoded_dates: encodedDates });
        });
    });

});
</script>
</body>
</html>