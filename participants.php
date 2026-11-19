<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['username'])) {
    header('Location: ../login.php');
    exit();
}

$sessionType = strtolower($_SESSION['type'] ?? '');
$location    = ($sessionType === 'hrd')
    ? strtolower(filter_input(INPUT_GET, 'location', FILTER_SANITIZE_SPECIAL_CHARS) ?? '')
    : $sessionType;
$trainingId = filter_input(INPUT_GET, 'id',       FILTER_VALIDATE_INT);
$filter     = filter_input(INPUT_GET, 'filter',   FILTER_SANITIZE_SPECIAL_CHARS) ?? 'All';

if (!$trainingId) {
    die('<div class="alert alert-danger m-3">Invalid or missing training ID.</div>');
}

$locationLabels = [
    ''      => 'CSC RO VIII – Human Resource Division',
    'hrd'   => 'CSC RO VIII – Human Resource Division',
    'lfoi'  => 'CSC Field Office – Leyte I',
    'lfoii' => 'CSC Field Office – Leyte II',
    'bfo'   => 'CSC Field Office – Biliran',
    'wlso'  => 'CSC Satellite Office – Western Leyte',
    'slfo'  => 'CSC Field Office – Southern Leyte',
    'sfo'   => 'CSC Field Office – Samar',
    'esfo'  => 'CSC Field Office – Eastern Samar',
    'nsfo'  => 'CSC Field Office – Northern Samar',
    'ro6'   => 'CSC Regional Office VI',
    'ro7'   => 'CSC Regional Office VII',
];
$locationText = $locationLabels[$location] ?? strtoupper($location);

$showCheckboxColumn = in_array($location, ['ro6', 'ro7'], true);
$showButtons        = $showCheckboxColumn;

function formatDateRange(string $start, ?string $end): string
{
    if (empty($start) || $start === '0000-00-00') return 'N/A';
    try {
        $s = new DateTimeImmutable($start);
        $e = ($end && $end !== '0000-00-00') ? new DateTimeImmutable($end) : null;
        if (!$e || $s->format('Y-m-d') === $e->format('Y-m-d')) return $s->format('F j, Y');
        if ($s->format('Y-m') === $e->format('Y-m'))            return $s->format('F j') . '–' . $e->format('j, Y');
        return $s->format('F j') . ' – ' . $e->format('F j, Y');
    } catch (Exception $ex) {
        return htmlspecialchars($start);
    }
}

/* ── Training ──────────────────────────────────────────────────────────── */
try {
    $stmtT = $conn->prepare("
        SELECT id, name, start_date, end_date, location, mode, slots, fee, training_status
        FROM   otrs_trainings
        WHERE  id = :id
        LIMIT  1
    ");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log('[participants.php] training query: ' . $ex->getMessage());
    die('<div class="alert alert-danger m-3">Error loading training details.</div>');
}

if (!$training) die('<div class="alert alert-warning m-3">Training not found.</div>');

$trainingDateDisplay = formatDateRange($training['start_date'], $training['end_date']);
$isFaceToFace        = (stripos(trim($training['mode']), 'face') !== false);

/* ── Proof-of-payment pending count (uploaded, not yet paid) ── */
$popCount = 0;
try {
    $popSql = "
        SELECT COUNT(*)
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.training_id = :tid
          AND  r.proof_file  IS NOT NULL
          AND  r.proof_file  != ''
          AND  r.proof_file  != 'outputs/'
          AND  LOWER(r.payment_status) = 'pending'
          AND  r.registration_status NOT IN ('cancelled','not qualified')
    ";
    $popParams = [':tid' => $trainingId];
    if ($location !== '') {
        $popSql .= " AND UPPER(p.location) = UPPER(:loc)";
        $popParams[':loc'] = $location;
    }
    $popStmt = $conn->prepare($popSql);
    $popStmt->execute($popParams);
    $popCount = (int)$popStmt->fetchColumn();
} catch (PDOException $ex) { /* non-fatal */ }

/* ── Participants ──────────────────────────────────────────────────────── */
$filterOptions = ['All', 'paid', 'pending', 'promissory note', 'free', 'cancelled', 'not qualified'];
if (!in_array(strtolower($filter), array_map('strtolower', $filterOptions), true)) {
    $filter = 'All';
}

try {
    $sql = "
        SELECT p.id          AS participant_id,
               r.id          AS enrollment_id,
               p.fname, p.mname, p.lname,
               p.gender,
               TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) AS age,
               p.civil_status,
               p.position_title,
               p.agency_name,
               p.agency_address,
               p.email,
               p.mobile_no,
               p.location    AS fo,
               p.food_restrictions,
               r.payment_status,
               r.or_no,
               r.or_date,
               r.collecting_officer,
               r.remarks,
               r.training_status,
               r.registered_at
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.training_id = :tid
    ";
    $params = [':tid' => $trainingId];

    if ($location !== '') {
        $sql .= " AND UPPER(p.location) = UPPER(:location)";
        $params[':location'] = $location;
    }

    if ($filter === 'not qualified') {
        $sql .= " AND LOWER(r.registration_status) = 'not qualified'";
    } elseif ($filter !== 'All') {
        $sql .= " AND LOWER(r.payment_status) = LOWER(:status)";
        $params[':status'] = $filter;
    }

    $sql .= " ORDER BY p.lname ASC, p.fname ASC";

    $stmtP = $conn->prepare($sql);
    $stmtP->execute($params);
    $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    /* ── Payment status counts (unfiltered, respects location) ── */
    $sqlCounts   = "
        SELECT r.payment_status, r.registration_status
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.training_id = :tid
    ";
    $countParams = [':tid' => $trainingId];
    if ($location !== '') {
        $sqlCounts .= " AND UPPER(p.location) = UPPER(:loc)";
        $countParams[':loc'] = $location;
    }
    $stmtCounts = $conn->prepare($sqlCounts);
    $stmtCounts->execute($countParams);
    $counts = ['paid' => 0, 'pending' => 0, 'promissory note' => 0, 'free' => 0, 'cancelled' => 0, 'not qualified' => 0];
    foreach ($stmtCounts->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $rs = strtolower($cr['registration_status']);
        $ps = strtolower($cr['payment_status']);
        if ($rs === 'not qualified') { $counts['not qualified']++; }
        elseif ($rs === 'cancelled')  { $counts['cancelled']++; }
        elseif (isset($counts[$ps])) { $counts[$ps]++; }
    }
} catch (PDOException $ex) {
    error_log('[participants.php] participant query: ' . $ex->getMessage());
    $participants = [];
    $counts = ['paid' => 0, 'pending' => 0, 'promissory note' => 0, 'free' => 0, 'cancelled' => 0, 'not qualified' => 0];
}

$colOffset = $showCheckboxColumn ? 1 : 0;

/* ── Waitlist (location-filtered for FOs) ───────────────────────────────── */
$waitlist = [];
try {
    $wlSql = "
        SELECT w.position, w.added_at, w.status,
               CONCAT_WS(' ', TRIM(p.fname), NULLIF(CONCAT(LEFT(TRIM(p.mname),1),'.'),'.'), TRIM(p.lname)) AS display_name,
               p.agency_name, p.position_title
        FROM   otrs_waitlist w
        JOIN   otrs_participants p ON p.id = w.participant_id
        WHERE  w.training_id = :tid
          AND  w.status = 'waiting'
    ";
    $wlParams = [':tid' => $trainingId];
    if ($location !== '') {
        $wlSql .= " AND UPPER(p.location) = UPPER(:loc)";
        $wlParams[':loc'] = $location;
    }
    $wlSql .= " ORDER BY w.position ASC";
    $wlStmt = $conn->prepare($wlSql);
    $wlStmt->execute($wlParams);
    $waitlist = $wlStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) { /* non-fatal */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="author" content="CSCRO8-ITG">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title><?php echo htmlspecialchars($training['name']); ?> – <?php echo $trainingDateDisplay; ?></title>

<link href="assets/img/favicon.png" rel="icon">
<link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">
<link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
<link href="assets/vendor/remixicon/remixicon.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>" rel="stylesheet">
<link href="../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
<link href="assets/css/field-office.css?v=<?php echo filemtime(__DIR__.'/assets/css/field-office.css'); ?>" rel="stylesheet">
<link href="../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <!-- ── PAGE TITLE ─────────────────────────────────────────── -->
    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-blue"><i class="bi bi-people-fill"></i></div>
                <div>
                    <h1>List of Participants</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item">
                                <a href="index.php?location=<?php echo urlencode($location); ?>">Home</a>
                            </li>
                            <li class="breadcrumb-item">
                                <a href="index.php?location=<?php echo urlencode($location); ?>">Training List</a>
                            </li>
                            <li class="breadcrumb-item"
                                style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?php echo htmlspecialchars($training['name']); ?>
                            </li>
                            <li class="breadcrumb-item active"><?php echo $trainingDateDisplay; ?></li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="index.php?location=<?php echo urlencode($location); ?>" class="otrs-btn-back">
                <i class="bi bi-arrow-left-circle"></i> Go Back
            </a>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body p-4">

                <!-- ── TRAINING STRIP ── -->
                <div class="training-strip">
                    <div class="strip-label">Training Details</div>
                    <div class="strip-title"><?php echo htmlspecialchars($training['name']); ?></div>
                    <div class="strip-meta">
                        <span class="strip-meta-item"><i class="bi bi-calendar3"></i> <?php echo $trainingDateDisplay; ?></span>
                        <span class="strip-meta-item"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($training['location'] ?? '—'); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-display"></i> <?php echo htmlspecialchars($training['mode']); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-people"></i> <?php echo (int)$training['slots']; ?> slots</span>
                        <span class="strip-meta-item"><i class="bi bi-cash"></i> &#8369;<?php echo number_format((float)$training['fee'], 2); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-building"></i> <?php echo htmlspecialchars($locationText); ?></span>
                    </div>
                </div>

                <!-- ── TOOLBAR 1 ── -->
                <p class="section-label"><i class="bi bi-sliders me-1"></i> Actions &amp; Filters</p>
                <div class="toolbar">
                    <?php if ($showButtons): ?>
                        <div class="toolbar-divider"></div>
                        <button class="btn-f btn-f-primary" onclick="sendAcknowledgmentLetter()">
                            <i class="ri ri-mail-send-line"></i> Ack Letter
                        </button>
                        <button class="btn-f btn-f-primary" data-bs-toggle="modal" data-bs-target="#zoomLinkModal">
                            <i class="ri ri-mail-send-line"></i> Zoom Link
                        </button>
                        <button class="btn-f btn-f-primary" onclick="sendEmailAdvisory()">
                            <i class="ri ri-mail-send-line"></i> Advisory
                        </button>
                    <?php endif; ?>

                    <div class="toolbar-divider"></div>
                    <button id="exportButton" class="btn-f btn-f-success">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>

                    <div class="toolbar-divider"></div>
                    <span class="pop-btn-wrap">
                        <a href="proof_payment.php?id=<?php echo (int)$trainingId; ?>&location=<?php echo urlencode($location); ?>"
                           class="btn-f" style="background:#0077b6;"
                           title="Proof of Payment<?php echo $popCount > 0 ? ' — ' . $popCount . ' pending review' : ''; ?>">
                            <i class="bi bi-cash-stack"></i> Proof of Payment
                        </a>
                        <?php if ($popCount > 0): ?>
                            <span class="pop-count-badge"><?php echo $popCount; ?></span>
                        <?php endif; ?>
                    </span>

                    <div class="toolbar-spacer"></div>

                    <button id="toggleColumnsBtn" class="btn-f btn-f-secondary">
                        <i class="bi bi-layout-three-columns me-1"></i>
                        <span id="toggleColumnsLabel">Show All</span>
                    </button>
                    <span id="colToggleHint">Core view</span>

                    <div class="toolbar-divider"></div>

                    <label class="fw-bold mb-0" style="font-size:.78rem;color:#012970;white-space:nowrap;">
                        <i class="bi bi-funnel me-1 text-primary"></i>Filter:
                    </label>
                    <select id="filter" class="filter-select" onchange="applyFilter(this.value)">
                        <?php foreach ($filterOptions as $opt): ?>
                            <option value="<?php echo $opt; ?>"
                                <?php echo (strtolower($filter) === strtolower($opt)) ? 'selected' : ''; ?>>
                                <?php echo ucwords($opt); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- ── SUMMARY PILLS ── -->
                <div class="summary-pills">
                    <span class="summary-pill pill-paid">
                        <i class="bi bi-check-circle-fill"></i> Paid: <span id="pillCount-paid"><?php echo $counts['paid']; ?></span>
                    </span>
                    <span class="summary-pill pill-pending">
                        <i class="bi bi-hourglass-split"></i> Pending: <span id="pillCount-pending"><?php echo $counts['pending']; ?></span>
                    </span>
                    <span class="summary-pill pill-pn">
                        <i class="bi bi-journal-text"></i> Promissory Note: <span id="pillCount-pn"><?php echo $counts['promissory note']; ?></span>
                    </span>
                    <span class="summary-pill pill-free">
                        <i class="bi bi-gift"></i> Free: <span id="pillCount-free"><?php echo $counts['free']; ?></span>
                    </span>
                    <span class="summary-pill pill-cancelled">
                        <i class="bi bi-x-circle-fill"></i> Cancelled: <span id="pillCount-cancelled"><?php echo $counts['cancelled']; ?></span>
                    </span>
                    <?php if ($counts['not qualified'] > 0): ?>
                    <span class="summary-pill pill-nq">
                        <i class="bi bi-slash-circle"></i> Not Qualified: <span id="pillCount-nq"><?php echo $counts['not qualified']; ?></span>
                    </span>
                    <?php endif; ?>
                    <span class="summary-pill pill-total">
                        <i class="bi bi-people"></i> Total: <span id="pillCount-total"><?php echo array_sum($counts); ?></span>
                    </span>
                </div>

                <!-- ── TABLE ── -->
                <p class="section-label mt-3"><i class="bi bi-table me-1"></i> Participant Records</p>

                <?php if (count($participants) > 0): ?>
                    <?php
                    $isFtF_js    = $isFaceToFace ? 'true' : 'false';
                    ?>
                    <script>
                        const COL_OFFSET      = <?php echo (int)$colOffset; ?>;
                        const IS_FACE_TO_FACE = <?php echo $isFtF_js; ?>;
                        const TRAINING_ID     = <?php echo (int)$trainingId; ?>;
                    </script>

                    <div class="table-responsive">
                        <table id="participantsList" class="table table-bordered table-hover table-striped w-100" style="font-size:12px;">
                            <thead>
                                <tr>
                                    <?php if ($showCheckboxColumn): ?>
                                        <th class="text-center align-middle"><input type="checkbox" id="checkAll" style="transform:scale(1.5);"></th>
                                    <?php endif; ?>
                                    <th class="text-center align-middle">Action</th>
                                    <th class="text-center align-middle">#</th>
                                    <th class="text-center align-middle">Name</th>
                                    <th class="text-center align-middle">Gender</th>
                                    <th class="text-center align-middle">Age</th>
                                    <th class="text-center align-middle">Civil Status</th>
                                    <th class="text-center align-middle">Email</th>
                                    <th class="text-center align-middle">Mobile</th>
                                    <th class="text-center align-middle">Position Title</th>
                                    <th class="text-center align-middle">FO</th>
                                    <th class="text-center align-middle">Agency / Office</th>
                                    <th class="text-center align-middle">Agency Address</th>
                                    <th class="text-center align-middle">Status</th>
                                    <th class="text-center align-middle">OR No.</th>
                                    <th class="text-center align-middle">OR Date</th>
                                    <th class="text-center align-middle">Collecting Officer</th>
                                    <?php if ($isFaceToFace): ?>
                                        <th class="text-center align-middle">Food Restrictions</th>
                                    <?php endif; ?>
                                    <th class="text-center align-middle">Remarks</th>
                                    <th class="text-center align-middle">Trng Status</th>
                                    <th class="text-center align-middle">Log</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $count = 0;
                            foreach ($participants as $row):
                                $count++;
                                $enrollmentId  = (int)$row['enrollment_id'];
                                $participantId = (int)$row['participant_id'];
                                $payStatus     = $row['payment_status'];
                                $trgStatus     = $row['training_status'] ?? '';
                                $fullName      = trim($row['lname'] . ', ' . $row['fname'] . ' ' . $row['mname']);

                                $payBadge = match (strtolower($payStatus)) {
                                    'paid'            => 'badge bg-success',
                                    'cancelled'       => 'badge bg-danger',
                                    'promissory note' => 'badge bg-warning text-dark',
                                    default           => 'badge bg-primary',
                                };
                                $trgBadge = match ($trgStatus) {
                                    'completed'      => 'badge bg-success',
                                    'For Compliance' => 'badge bg-danger',
                                    default          => 'badge bg-secondary',
                                };
                            ?>
                                <tr data-enrollment-id="<?php echo $enrollmentId; ?>">
                                    <?php if ($showCheckboxColumn): ?>
                                        <td class="text-center align-middle">
                                            <input type="checkbox" style="transform:scale(1.5);"
                                                   class="participant-checkbox"
                                                   value="<?php echo $enrollmentId; ?>"
                                                   data-enrollment-id="<?php echo $enrollmentId; ?>"
                                                   data-participant-id="<?php echo $participantId; ?>"
                                                   data-status="<?php echo htmlspecialchars($payStatus); ?>"
                                                   data-name="<?php echo htmlspecialchars($fullName); ?>">
                                        </td>
                                    <?php endif; ?>
                                    <td class="text-center align-middle">
                                        <?php if (strtolower($payStatus) === 'cancelled'): ?>
                                            <button class="btn btn-secondary btn-sm" disabled
                                                    data-bs-toggle="tooltip" title="cancelled">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-primary btn-sm"
                                                    onclick="showUpdateModal(<?php echo $enrollmentId; ?>)"
                                                    data-bs-toggle="tooltip" title="Update">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $count; ?></td>
                                    <td><?php echo htmlspecialchars(trim($row['fname'] . ' ' . $row['mname'] . ' ' . $row['lname'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['gender']); ?></td>
                                    <td><?php echo htmlspecialchars($row['age'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['civil_status']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['mobile_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['position_title']); ?></td>
                                    <td><?php echo htmlspecialchars($row['fo']); ?></td>
                                    <td><?php echo htmlspecialchars($row['agency_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['agency_address']); ?></td>
                                    <td><span class="<?php echo $payBadge; ?> js-pay-badge"><?php echo htmlspecialchars(ucwords($payStatus)); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['or_no'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['or_date'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['collecting_officer'] ?? ''); ?></td>
                                    <?php if ($isFaceToFace): ?>
                                        <td><?php echo htmlspecialchars($row['food_restrictions'] ?? ''); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td>
                                    <td><?php if ($trgStatus): ?><span class="<?php echo $trgBadge; ?>"><?php echo htmlspecialchars($trgStatus); ?></span><?php endif; ?></td>
                                    <td class="text-center align-middle">
                                        <button class="btn-pay-log pay-log-btn"
                                                data-id="<?php echo $enrollmentId; ?>"
                                                data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>"
                                                title="View registration &amp; payment history">
                                            <i class="bi bi-clock-history me-1"></i>Log
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        No participants found for <b><?php echo htmlspecialchars($training['name']); ?></b>
                        <?php if ($filter !== 'All'): ?>
                            with filter <b><?php echo htmlspecialchars(ucwords($filter)); ?></b>
                        <?php endif; ?>.
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- ── WAITLIST ───────────────────────────────────────────────── -->
        <?php if (!empty($waitlist) || $training['slots'] <= 0): ?>
        <div class="card mt-3">
            <div class="card-body p-4">
                <p class="section-label">
                    <i class="bi bi-hourglass-split me-1" style="color:#d97706;"></i>
                    Waitlist
                    <?php if (!empty($waitlist)): ?>
                        <span class="badge ms-2" style="background:#d97706;font-size:.7rem;"><?php echo count($waitlist); ?></span>
                    <?php endif; ?>
                </p>

                <?php if (empty($waitlist)): ?>
                    <div class="alert alert-info d-flex gap-2 align-items-center" style="font-size:.85rem;">
                        <i class="bi bi-info-circle-fill flex-shrink-0"></i>
                        <span>This training has no available slots. No one from your office is currently on the waitlist.</span>
                    </div>
                <?php else: ?>
                    <p style="font-size:.82rem;color:#64748b;" class="mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        The following participants from your office are queued for this training.
                        They will be automatically registered when a slot opens up through a cancellation approval.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-striped w-100" style="font-size:12.5px;">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width:60px;">Position</th>
                                    <th>Name</th>
                                    <th>Position Title</th>
                                    <th>Agency / Office</th>
                                    <th class="text-center">Date Added</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($waitlist as $wl): ?>
                                <tr>
                                    <td class="text-center fw-bold" style="color:#d97706;">#<?php echo (int)$wl['position']; ?></td>
                                    <td><?php echo htmlspecialchars($wl['display_name']); ?></td>
                                    <td><?php echo htmlspecialchars($wl['position_title'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($wl['agency_name'] ?? '—'); ?></td>
                                    <td class="text-center">
                                        <?php
                                        try {
                                            echo (new DateTimeImmutable($wl['added_at']))->format('M j, Y g:i A');
                                        } catch (Exception $e) {
                                            echo htmlspecialchars($wl['added_at']);
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </section>
</main>

<?php include 'inc/footer.php' ?>

<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<!-- ══ UPDATE MODAL ══════════════════════════════════════════════════════ -->
<div id="updateModal" class="modal fade" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header border-0">
                <div class="modal-title-wrap flex-grow-1">
                    <div class="modal-avatar" id="modalAvatar">?</div>
                    <div class="modal-title-text">
                        <h6 id="fullName">Loading participant…</h6>
                        <small id="modalEnrollmentMeta"></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Tab nav -->
            <div class="modal-tabs" role="tablist">
                <button class="modal-tab-btn active" data-tab="tab-personal" aria-selected="true">
                    <i class="bi bi-person-fill tab-icon"></i> Personal
                    <span class="tab-badge" id="badge-personal"></span>
                </button>
                <button class="modal-tab-btn" data-tab="tab-professional" aria-selected="false">
                    <i class="bi bi-briefcase-fill tab-icon"></i> Professional
                    <span class="tab-badge" id="badge-professional"></span>
                </button>
                <button class="modal-tab-btn" data-tab="tab-agency" aria-selected="false">
                    <i class="bi bi-building tab-icon"></i> Agency
                    <span class="tab-badge" id="badge-agency"></span>
                </button>
                <button class="modal-tab-btn" data-tab="tab-enrollment" aria-selected="false">
                    <i class="bi bi-clipboard2-check-fill tab-icon"></i> Enrollment
                    <span class="tab-badge" id="badge-enrollment"></span>
                </button>
            </div>

            <!-- Modal body -->
            <div class="modal-body">

                <!-- Skeleton (shown while loading) -->
                <div id="modalSkeleton" class="skel-wrap">
                    <div class="skel-row skel-row-3">
                        <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                        <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                        <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                    </div>
                    <div class="skel-row skel-row-2">
                        <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                        <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                    </div>
                    <div class="skel-row skel-row-2">
                        <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                        <div><div class="skel-block skel-label"></div><div class="skel-block skel-input"></div></div>
                    </div>
                </div>

                <!-- Form (hidden while loading) -->
                <form id="updateForm" style="display:none;">
                    <input type="hidden" id="enrollmentId"  name="enrollmentId">
                    <input type="hidden" id="participantId" name="participantId">
                    <input type="hidden" id="status"        name="status">

                    <!-- ── Tab: Personal ── -->
                    <div id="tab-personal" class="tab-pane-content active">
                        <div class="form-section-label"><i class="bi bi-person-lines-fill"></i> Personal Information</div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="fname" name="fname">
                                <div class="invalid-feedback">Required</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">M.I.</label>
                                <input type="text" class="form-control text-center" id="mname" name="mname" maxlength="2">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="lname" name="lname">
                                <div class="invalid-feedback">Required</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Suffix</label>
                                <input type="text" class="form-control" id="suffix" name="suffix" maxlength="16" placeholder="Jr., Sr., III">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Gender</label>
                                <select class="form-select" id="gender" name="gender">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Civil Status</label>
                                <select class="form-select" id="civilstatus" name="civilstatus">
                                    <option>Single</option><option>Married</option>
                                    <option>Widowed/Widower</option><option>Separated</option><option>Others</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address <span class="req">*</span></label>
                                <input type="text" class="form-control" id="email" name="email">
                                <div class="invalid-feedback">Required</div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Mobile No.</label>
                                <input type="text" class="form-control" id="contactno" name="contactno" maxlength="11">
                            </div>
                        </div>
                    </div>

                    <!-- ── Tab: Professional ── -->
                    <div id="tab-professional" class="tab-pane-content">
                        <div class="form-section-label"><i class="bi bi-briefcase-fill"></i> Professional Details</div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Position Title <span class="req">*</span></label>
                                <input type="text" class="form-control" id="positiontitle" name="positiontitle">
                                <div class="invalid-feedback">Required</div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label">Field Office (FO)</label>
                                <select class="form-select" id="location" name="location">
                                    <option value="HRD">HRD</option>
                                    <option value="LFOI">LFOI</option><option value="LFOII">LFOII</option>
                                    <option value="BFO">BFO</option><option value="WLSO">WLSO</option>
                                    <option value="SLFO">SLFO</option><option value="SFO">SFO</option>
                                    <option value="ESFO">ESFO</option><option value="NSFO">NSFO</option>
                                    <option value="RO6">RO VI</option><option value="RO7">RO VII</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ── Tab: Agency ── -->
                    <div id="tab-agency" class="tab-pane-content">
                        <div class="form-section-label"><i class="bi bi-building"></i> Agency / Office</div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Agency / Office Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="agency_name" name="agency_name">
                                <div class="invalid-feedback">Required</div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Agency Address</label>
                                <input type="text" class="form-control" id="agency_address" name="agency_address">
                            </div>
                        </div>
                    </div>

                    <!-- ── Tab: Enrollment ── -->
                    <div id="tab-enrollment" class="tab-pane-content">
                        <div class="form-section-label"><i class="bi bi-clipboard-check-fill"></i> Enrollment &amp; Payment</div>

                        <!-- Status alert -->
                        <div id="statusAlert" class="status-alert d-none">
                            <span id="statusAlertText"></span>
                        </div>

                        <!-- Status radio pills -->
                        <div class="payment-status-row">
                            <input class="payment-opt" type="radio" name="paymentOpt" id="opt-paid"    value="paid">
                            <label class="payment-opt-label paid"    for="opt-paid"><i class="bi bi-check-circle-fill"></i> Paid</label>

                            <input class="payment-opt" type="radio" name="paymentOpt" id="opt-pending" value="pending">
                            <label class="payment-opt-label pending" for="opt-pending"><i class="bi bi-hourglass-split"></i> Pending</label>

                            <input class="payment-opt" type="radio" name="paymentOpt" id="opt-pn"      value="promissory note">
                            <label class="payment-opt-label pn"      for="opt-pn"><i class="bi bi-journal-text"></i> Promissory Note</label>

                            <input class="payment-opt" type="radio" name="paymentOpt" id="opt-cancel"  value="cancelled">
                            <label class="payment-opt-label cancel"  for="opt-cancel"><i class="bi bi-x-circle-fill"></i> Cancelled</label>
                        </div>

                        <!-- PRIME-HRM discount indicator -->
                        <div class="mt-3 hidden" id="primeHrmGroup">
                          <div class="p-2 rounded" style="background:#fef3c7;border:1.5px solid #f59e0b;">
                            <div class="form-check form-switch mb-0">
                              <input class="form-check-input" type="checkbox" role="switch"
                                     id="prime_hrm_discount" name="prime_hrm_discount" value="1"
                                     style="width:2em;height:1.1em;">
                              <label class="form-check-label fw-semibold" for="prime_hrm_discount" style="font-size:.84rem;">
                                <i class="bi bi-award-fill text-warning me-1"></i> PRIME-HRM 20% Discount Applied
                                <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;vertical-align:middle;">PRIME-HRM</span>
                              </label>
                            </div>
                            <div style="font-size:.71rem;color:#92400e;margin-top:3px;margin-left:2.5em;">
                              Agency is PRIME-HRM accredited and availed the 20% registration fee discount.
                            </div>
                          </div>
                        </div>

                        <!-- OR fields (shown for paid only) -->
                        <div id="orFieldsGroup" class="hidden">
                            <div class="form-section-label"><i class="bi bi-receipt"></i> Official Receipt Details</div>
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <label class="form-label">Collecting Officer</label>
                                    <select class="form-select" id="collecting_officer" name="collecting_officer">
                                        <option value="">— Select Officer —</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">OR No. <span class="req">*</span></label>
                                    <input type="text" class="form-control" id="orno" name="orno">
                                    <div class="invalid-feedback">OR Number is required.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">OR Date <span class="req">*</span></label>
                                    <input type="date" class="form-control" id="ordate" name="ordate">
                                    <div class="invalid-feedback">OR Date is required.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Remarks -->
                        <div class="form-section-label mt-3"><i class="bi bi-chat-text"></i> Remarks <span id="remarksRequired" style="display:none;color:#dc3545;font-size:.75rem;font-weight:600;margin-left:.3rem;">* required for Promissory Note</span></div>
                        <div class="row g-3">
                            <div class="col-12">
                                <textarea class="form-control" id="remarks" name="remarks" rows="3"></textarea>
                            </div>
                        </div>

                        <?php if ($isFaceToFace): ?>
                        <div class="form-section-label mt-3"><i class="bi bi-egg-fried"></i> Food Restrictions</div>
                        <div class="row g-3">
                            <div class="col-12">
                                <input type="text" class="form-control" id="food_restrictions" name="food_restrictions" maxlength="254">
                                <div class="field-locked-hint" style="font-size:.69rem;color:#a0aab5;margin-top:.3rem;display:flex;align-items:center;gap:.3rem;">
                                    <i class="bi bi-lock"></i> Read-only — managed by participant
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Tab progress dots -->
                <div id="tabProgress" class="tab-progress" style="display:none;">
                    <span class="tab-dot active" data-for="tab-personal"></span>
                    <span class="tab-dot"        data-for="tab-professional"></span>
                    <span class="tab-dot"        data-for="tab-agency"></span>
                    <span class="tab-dot"        data-for="tab-enrollment"></span>
                </div>
            </div>

            <!-- Footer -->
            <div class="modal-footer">
                <span class="footer-hint"><i class="bi bi-shield-check me-1"></i> Changes saved to participant record</span>
                <button type="button" class="btn-modal-replace" id="replaceFromUpdateBtn">
                    <i class="bi bi-arrow-left-right me-1"></i> Replace
                </button>
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-modal-save" id="updateParticipantBtn">
                    <div class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></div>
                    <span class="save-label"><i class="bi bi-floppy me-1"></i>Save Changes</span>
                </button>
            </div>

        </div>
    </div>
</div>
<div id="modalToast"></div>

<!-- ══ ZOOM LINK MODAL ══════════════════════════════════════════════════ -->
<div class="modal fade" id="zoomLinkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title fw-bold">Email Zoom Link to <span class="text-danger">Paid &amp; Promissory Note</span></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3"><label class="form-label fw-bold">Zoom Link:</label>
                    <input id="zoomLinkInput" class="form-control" type="text" placeholder="Paste the Zoom link here…"></div>
                <div class="mb-3"><label class="form-label fw-bold">Training Advisory PDF:</label>
                    <input type="file" id="zoomPDF" class="form-control" accept="application/pdf"></div>
                <div><label class="form-label fw-bold">Zoom Background:</label>
                    <input type="file" id="zoomBg" class="form-control" accept="image/*"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmSendEmailZoom()"><i class="ri ri-mail-send-line"></i> Send</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ REPLACEMENT MODAL ════════════════════════════════════════════════ -->
<div id="replaceModal" class="modal fade" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <div class="modal-avatar">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                    <div class="modal-title-text">
                        <h6>Replace Participant</h6>
                        <small>Replacing: <span id="rep_originalName">—</span></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-tabs" role="tablist">
                <button class="modal-tab-btn rep-tab-btn active" data-tab="rep-tab-personal" aria-selected="true">
                    <i class="bi bi-person-fill tab-icon"></i> Personal
                </button>
                <button class="modal-tab-btn rep-tab-btn" data-tab="rep-tab-professional" aria-selected="false">
                    <i class="bi bi-briefcase-fill tab-icon"></i> Professional
                </button>
                <button class="modal-tab-btn rep-tab-btn" data-tab="rep-tab-agency" aria-selected="false">
                    <i class="bi bi-building tab-icon"></i> Agency
                </button>
                <button class="modal-tab-btn rep-tab-btn" data-tab="rep-tab-reason" aria-selected="false">
                    <i class="bi bi-chat-text-fill tab-icon"></i> Reason
                </button>
            </div>

            <div class="modal-body">
                <form id="replaceForm">
                    <input type="hidden" name="enrollmentId"          id="rep_enrollmentId">
                    <input type="hidden" name="trainingId"            id="rep_trainingId">
                    <input type="hidden" name="originalParticipantId" id="rep_originalParticipantId">

                    <!-- Tab: Personal -->
                    <div id="rep-tab-personal" class="tab-pane-content active">
                        <div class="form-section-label"><i class="bi bi-person-lines-fill"></i> Personal Information</div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="rep_fname" name="new_fname">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="rep_mname" name="new_mname">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="rep_lname" name="new_lname">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">Suffix</label>
                                <input type="text" class="form-control" id="rep_suffix" name="new_suffix">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Gender</label>
                                <select class="form-select" id="rep_gender" name="new_gender">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Civil Status</label>
                                <select class="form-select" id="rep_civil_status" name="new_civil_status">
                                    <option>Single</option><option>Married</option>
                                    <option>Widowed/Widower</option><option>Separated</option><option>Others</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="rep_email" name="new_email">
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Mobile No.</label>
                                <input type="text" class="form-control" id="rep_mobile_no" name="new_mobile_no" maxlength="11">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" id="rep_dob" name="new_dob">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">PWD</label>
                                <select class="form-select" id="rep_pwd" name="new_pwd">
                                    <option value="No">No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Professional -->
                    <div id="rep-tab-professional" class="tab-pane-content">
                        <div class="form-section-label"><i class="bi bi-briefcase-fill"></i> Professional Details</div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Position Title <span class="req">*</span></label>
                                <input type="text" class="form-control" id="rep_position_title" name="new_position_title">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Salary Grade</label>
                                <input type="text" class="form-control" id="rep_salary_grade" name="new_salary_grade">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Position Level</label>
                                <select class="form-select" id="rep_position_level" name="new_position_level">
                                    <option value="">— Select —</option>
                                    <option value="First Level">First Level</option>
                                    <option value="Second Level (Non-Supervisory)">Second Level (Rank-and-File)</option>
                                    <option value="Second Level (Supervisory)">Second Level (Supervisory)</option>
                                    <option value="Second Level (Executive/Managerial)">Second Level (Executive/Managerial)</option>
                                    <option value="Third Level">Third Level</option>
                                    <option value="N/A">N/A</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Employment Status</label>
                                <select class="form-select" id="rep_employment_status" name="new_employment_status">
                                    <option value="">— Select —</option>
                                    <option>Permanent</option><option>Temporary</option>
                                    <option>Coterminous</option><option>Contract of Service</option>
                                    <option>Casual</option><option>Elective</option><option>Others</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Civil Servant</label>
                                <select class="form-select" id="rep_civil_servant" name="new_civil_servant">
                                    <option value="">— Select —</option>
                                    <option>Yes</option><option>No</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CSC Field Office</label>
                                <select class="form-select" id="rep_location" name="new_location">
                                    <option value="">— Select —</option>
                                    <option value="LFOI">CSC Field Office - Leyte I</option>
                                    <option value="LFOII">CSC Field Office - Leyte II</option>
                                    <option value="WLSO">CSC Satellite Office - Western Leyte</option>
                                    <option value="SLFO">CSC Field Office - Southern Leyte</option>
                                    <option value="BFO">CSC Field Office - Biliran</option>
                                    <option value="SFO">CSC Field Office - Samar</option>
                                    <option value="ESFO">CSC Field Office - Eastern Samar</option>
                                    <option value="NSFO">CSC Field Office - Northern Samar</option>
                                    <option value="HRD">Outside Region VIII / Others</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Agency -->
                    <div id="rep-tab-agency" class="tab-pane-content">
                        <div class="form-section-label"><i class="bi bi-building"></i> Agency / Office</div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Agency / Office Name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="rep_agency_name" name="new_agency_name">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Agency Unit</label>
                                <input type="text" class="form-control" id="rep_agency_unit" name="new_agency_unit">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Sector</label>
                                <select class="form-select" id="rep_sector" name="new_sector">
                                    <option value="">— Select —</option>
                                    <option>National Government</option>
                                    <option>Local Government</option>
                                    <option>Government-Owned Corporation</option>
                                    <option>State Universities and Colleges</option>
                                    <option>Others</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Province/Region</label>
                                <select class="form-select" id="rep_geo" name="new_geo">
                                    <option value="">— Select —</option>
                                    <option value="Province of Leyte">Province of Leyte</option>
                                    <option value="Province of Southern Leyte">Province of Southern Leyte</option>
                                    <option value="Province of Biliran">Province of Biliran</option>
                                    <option value="Province of Samar">Province of Samar</option>
                                    <option value="Province of Eastern Samar">Province of Eastern Samar</option>
                                    <option value="Province of Northern Samar">Province of Northern Samar</option>
                                    <option value="Outside Region VIII">Outside Region VIII</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Agency Address</label>
                                <input type="text" class="form-control" id="rep_agency_address" name="new_agency_address">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label">Home Address</label>
                                <input type="text" class="form-control" id="rep_home_address" name="new_home_address">
                            </div>
                        </div>
                        <div id="rep_foodRow" style="<?php echo $isFaceToFace ? '' : 'display:none;'; ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label"><i class="bi bi-egg-fried me-1"></i> Food Restrictions</label>
                                    <input type="text" class="form-control" id="rep_food_restrictions" name="new_food_restrictions">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Reason -->
                    <div id="rep-tab-reason" class="tab-pane-content">
                        <div class="form-section-label"><i class="bi bi-chat-text-fill"></i> Replacement Reason</div>
                        <div class="alert alert-info d-flex align-items-start gap-2" style="font-size:.83rem;">
                            <i class="bi bi-info-circle-fill mt-1 flex-shrink-0"></i>
                            <span>Provide the reason for replacing this participant. This will be recorded in the audit log.</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Reason <span class="req">*</span></label>
                                <textarea class="form-control" id="rep_replacement_reason" name="replacement_reason" rows="4"
                                          placeholder="e.g. The original participant can no longer attend due to…"></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <span class="footer-hint"><i class="bi bi-exclamation-circle me-1"></i> Replacement takes effect immediately</span>
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-modal-replace" id="replaceParticipantBtn">
                    <div class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></div>
                    <span class="save-label"><i class="bi bi-arrow-left-right me-1"></i> Replace Participant</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ PAYMENT LOG MODAL ════════════════════════════════════════════════ -->
<div class="modal fade" id="payLogModal" tabindex="-1" aria-labelledby="payLogModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header border-0">
                <div class="modal-title-wrap flex-grow-1">
                    <div class="modal-avatar">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="modal-title-text">
                        <h6 id="payLogName" class="mb-0">Loading…</h6>
                        <small id="payLogMeta">Registration &amp; Payment Log</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-2"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4" id="payLogBody" style="min-height:140px;">
                <div class="text-center py-4 text-muted">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    Loading log…
                </div>
            </div>

            <div class="modal-footer">
                <span class="footer-hint">
                    <i class="bi bi-shield-check me-1 text-success"></i>
                    Full audit trail for this enrollment.
                </span>
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i> Close
                </button>
            </div>

        </div>
    </div>
</div>

<!-- ══ SCRIPTS ══════════════════════════════════════════════════════════ -->
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>
<script src="../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>

<script>
const CONFIG = {
    trainingId:   <?php echo json_encode($trainingId); ?>,
    trainingName: <?php echo json_encode($training['name']); ?>,
    location:     <?php echo json_encode($location); ?>
};

// Wraps fetch() with the CSRF token in both header and body
const apiFetch = (url, opts = {}) => {
    const token = window.csrfToken ?? '';
    opts.headers = Object.assign({ 'X-CSRF-Token': token }, opts.headers ?? {});
    // Also inject into body so validation works even if the host strips custom headers
    if (opts.body instanceof FormData) {
        opts.body.append('_csrf', token);
    }
    return fetch(url, opts);
};

/* ── Summary pill live-update ──────────────────────────────────── */
let _oldPaymentStatus = '';
function adjustPillCounts(oldStatus, newStatus) {
    oldStatus = (oldStatus || '').toLowerCase().trim();
    newStatus = (newStatus || '').toLowerCase().trim();
    if (!oldStatus || !newStatus || oldStatus === newStatus) return;
    const map = {
        'paid':            'pillCount-paid',
        'pending':         'pillCount-pending',
        'promissory note': 'pillCount-pn',
        'free':            'pillCount-free',
        'cancelled':       'pillCount-cancelled',
        'not qualified':   'pillCount-nq',
    };
    function adj(key, delta) {
        const el = document.getElementById(map[key]);
        if (!el) return;
        el.textContent = Math.max(0, parseInt(el.textContent || '0') + delta);
    }
    adj(oldStatus, -1);
    adj(newStatus, +1);
}

$(function () {
    /* ── EXTRA COLUMNS (hidden by default) ────────────────── */
    const EXTRA_BASE = [3, 4, 5, 11, 13, 14, 15];
    <?php if ($isFaceToFace): ?>EXTRA_BASE.push(16);<?php endif; ?>
    const ftfShift = <?php echo $isFaceToFace ? 1 : 0; ?>;
    EXTRA_BASE.push(16 + ftfShift); // remarks
    EXTRA_BASE.push(17 + ftfShift); // training status
    const EXTRA = EXTRA_BASE.map(i => i + COL_OFFSET);

    // Group header column ranges — used to keep colspans correct when columns toggle
    const _o = COL_OFFSET;
    const GROUP_DEFS = [
        { cls: 'th-group-personal',     cols: [_o+2, _o+3, _o+4, _o+5, _o+6, _o+7] },
        { cls: 'th-group-professional', cols: [_o+8, _o+9] },
        { cls: 'th-group-agency',       cols: [_o+10, _o+11] },
        { cls: 'th-group-enrollment',   cols: IS_FACE_TO_FACE
            ? [_o+12, _o+13, _o+14, _o+15, _o+16, _o+17, _o+18]
            : [_o+12, _o+13, _o+14, _o+15, _o+16, _o+17] },
    ];

    /* ── DATATABLE ─────────────────────────────────────────── */
    const table = $('#participantsList').DataTable({
        fixedHeader: { headerOffset: 50 },
        columnDefs:  EXTRA.map(idx => ({ targets: idx, visible: false })),
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        buttons: [{
            extend: 'excel',
            filename: CONFIG.trainingName + '_' + CONFIG.location + '_' + new Date().toISOString().slice(0,10),
            exportOptions: { columns: ':gt(' + COL_OFFSET + ')' }
        }],
        language: {
            search: '', searchPlaceholder: 'Search…',
            paginate: { next: '<i class="bi bi-chevron-right"></i>', previous: '<i class="bi bi-chevron-left"></i>' }
        },
        pageLength: 10, lengthMenu: [10, 50, 100]
    });

    table.buttons().container().hide();
    $('#exportButton').on('click', () => table.button('.buttons-excel').trigger());

    // Inject group header row AFTER DataTables init so it never sees the multi-row thead
    const fillerCols = 2 + COL_OFFSET;
    const enrollmentCols = IS_FACE_TO_FACE ? 7 : 6;
    $('#participantsList thead').prepend(`
        <tr>
            <th class="th-group-filler" colspan="${fillerCols}"></th>
            <th class="text-center th-group th-group-personal" colspan="6"><i class="bi bi-person-fill me-1"></i> Personal</th>
            <th class="text-center th-group th-group-professional" colspan="2"><i class="bi bi-briefcase-fill me-1"></i> Professional</th>
            <th class="text-center th-group th-group-agency" colspan="2"><i class="bi bi-building me-1"></i> Agency</th>
            <th class="text-center th-group th-group-enrollment" colspan="${enrollmentCols}"><i class="bi bi-clipboard2-check-fill me-1"></i> Enrollment</th>
            <th class="th-group-filler"></th>
        </tr>
    `);

    // Sync group header colspans with currently visible columns
    const updateGroupHeaders = () => {
        GROUP_DEFS.forEach(({ cls, cols }) => {
            const $th = $('#participantsList thead tr:first-child .' + cls);
            if (!$th.length) return;
            const n = cols.filter(i => table.column(i).visible()).length;
            if (n === 0) $th.hide();
            else $th.show().attr('colspan', n);
        });
    };
    updateGroupHeaders();

    /* ── COLUMN TOGGLE ─────────────────────────────────────── */
    let expanded = false;
    $('#toggleColumnsBtn').on('click', function () {
        expanded = !expanded;
        EXTRA.forEach(idx => table.column(idx).visible(expanded));
        $(this).toggleClass('btn-f-toggle-active', expanded);
        $('#toggleColumnsLabel').text(expanded ? 'Core Only' : 'Show All');
        $('#colToggleHint').text(expanded ? 'Extended view' : 'Core view');
        updateGroupHeaders();
    });

    /* ── CHECK-ALL ─────────────────────────────────────────── */
    $('#checkAll').on('change', function () {
        $('.participant-checkbox').prop('checked', this.checked);
    });

    /* ── SHIFT+CLICK RANGE SELECT ──────────────────────────── */
    let lastChecked = null;

    $('#participantsList').on('click', '.participant-checkbox', function (e) {
        if (e.shiftKey && lastChecked && lastChecked !== this) {
            const $all = $('.participant-checkbox');
            const from = Math.min($all.index(lastChecked), $all.index(this));
            const to   = Math.max($all.index(lastChecked), $all.index(this));
            $all.slice(from, to + 1).prop('checked', $(lastChecked).prop('checked'));
        } else {
            lastChecked = this;
        }
        const total   = $('.participant-checkbox').length;
        const checked = $('.participant-checkbox:checked').length;
        $('#checkAll').prop({ checked: checked === total, indeterminate: checked > 0 && checked < total });
    });

    $('#participantsList').on('draw.dt', function () {
        lastChecked = null;
        $('#checkAll').prop({ checked: false, indeterminate: false });
    });

    /* ── TAB SWITCHING ─────────────────────────────────────── */
    window.switchTab = function (tabId) {
        $('.modal-tab-btn').each(function () {
            const $b = $(this);
            $b.toggleClass('active', $b.data('tab') === tabId);
            $b.attr('aria-selected', $b.data('tab') === tabId);
        });
        $('.tab-pane-content').each(function () {
            $(this).toggleClass('active', this.id === tabId);
        });
        $('.tab-dot').each(function () {
            $(this).toggleClass('active', $(this).data('for') === tabId);
        });
    };
    $('.modal-tab-btn').on('click', function () { switchTab($(this).data('tab')); });
    $('.tab-dot').on('click', function ()       { switchTab($(this).data('for')); });

    /* ── SKELETON ──────────────────────────────────────────── */
    window.showSkeleton = function () {
        $('#modalSkeleton').show();
        $('#updateForm').hide();
        $('#tabProgress').hide();
    };
    window.hideSkeleton = function () {
        $('#modalSkeleton').hide();
        $('#updateForm').show();
        $('#tabProgress').css('display', 'flex');
    };

    /* ── MINI TOAST ────────────────────────────────────────── */
    window.miniToast = function (msg, ms) {
        const el = document.getElementById('modalToast');
        if (!el) return;
        el.textContent = msg;
        el.classList.add('show');
        setTimeout(() => el.classList.remove('show'), ms || 2200);
    };

    /* ── STATUS CONFIG ─────────────────────────────────────── */
    const STATUS_CONFIG = {
        'paid':            { cls: 'paid',    alert: 'Paid — OR details required below.',         icon: 'bi-check-circle-fill' },
        'pending':         { cls: 'pending', alert: 'Pending — waiting for payment.',             icon: 'bi-hourglass-split'   },
        'promissory note': { cls: 'pn',      alert: 'Promissory Note — Remarks is required below.', icon: 'bi-journal-text'    },
        'cancelled':       { cls: 'cancel',  alert: 'Cancelled — slot will be returned.',         icon: 'bi-x-circle-fill'    },
    };

    /* ── SYNC STATUS UI ────────────────────────────────────── */
    window.syncStatusUI = function (value) {
        $('#status').val(value);
        const cfg = STATUS_CONFIG[value] || {};
        if (cfg.alert) {
            $('#statusAlert').removeClass().addClass('status-alert mb-3 ' + cfg.cls);
            $('#statusAlertText').html(`<i class="bi ${cfg.icon} me-1"></i> ${cfg.alert}`);
        } else {
            $('#statusAlert').removeClass().addClass('status-alert mb-3 d-none');
        }
        const showOR = (value === 'paid');
        if (showOR) {
            $('#orFieldsGroup').removeClass('hidden');
            if (!$('#collecting_officer option:not([value=""])').length) {
                loadCollectingOfficers();
            }
        } else {
            $('#orFieldsGroup').addClass('hidden');
            $('#orno').val('');
            $('#ordate').val('');
            $('#collecting_officer').val('');
        }
        if (showOR) {
            $('#primeHrmGroup').removeClass('hidden');
        } else {
            $('#primeHrmGroup').addClass('hidden');
            $('#prime_hrm_discount').prop('checked', false);
        }
        const showRemarksReq = (value === 'promissory note');
        $('#remarksRequired').toggle(showRemarksReq);
    };

    $('.payment-opt').on('change', function () { syncStatusUI($(this).val()); });

    /* ── COLLECTING OFFICERS ───────────────────────────────── */
    let coLoaded = {};
    window.loadCollectingOfficers = function (location, preselect) {
        const loc = location || $('#location').val() || '';
        if (coLoaded[loc]) {
            // Already loaded — just apply preselect if needed
            if (preselect) $('#collecting_officer').val(preselect);
            return;
        }
        const fd = new FormData();
        fd.append('location', loc);
        apiFetch('api/field-office/fetch_co_dropdown.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                coLoaded[loc] = true;   // Mark loaded only after success
                const list = Array.isArray(res) ? res : [];
                const $sel = $('#collecting_officer');
                $sel.html('<option value="">— Select Officer —</option>');
                list.forEach(co => $sel.append(`<option value="${co.name}">${co.name}</option>`));
                if (preselect) $sel.val(preselect);
            })
            .catch(() => {});
    };

    $('#location').on('change', function () {
        coLoaded = {};
        const st = $('#status').val();
        if (st === 'paid') loadCollectingOfficers($(this).val());
    });

    /* ── VALIDATION ────────────────────────────────────────── */
    const REQUIRED_FIELDS = {
        'tab-personal':     ['fname', 'lname', 'email'],
        'tab-professional': ['positiontitle'],
        'tab-agency':       ['agency_name'],
        'tab-enrollment':   [],
    };

    function validateTab(tabId) {
        const fields = REQUIRED_FIELDS[tabId] || [];
        let valid = true;
        fields.forEach(id => {
            const $el = $(`#${id}`);
            if (!$el.length) return;
            const empty = !$el.val().trim();
            $el.toggleClass('is-invalid', empty);
            if (empty) valid = false;
        });
        $(`#badge-${tabId.replace('tab-', '')}`).toggleClass('show', !valid);
        return valid;
    }

    window.validateAll = function () {
        let ok = Object.keys(REQUIRED_FIELDS).reduce((acc, tab) => validateTab(tab) && acc, true);
        if ($('#status').val() === 'promissory note') {
            const $rem = $('#remarks');
            const empty = !$rem.val().trim();
            $rem.toggleClass('is-invalid', empty);
            $('#badge-enrollment').toggleClass('show', empty);
            if (empty) ok = false;
        }
        if ($('#status').val() === 'paid') {
            const ornoEmpty   = !$('#orno').val().trim();
            const ordateEmpty = !$('#ordate').val().trim();
            $('#orno').toggleClass('is-invalid', ornoEmpty);
            $('#ordate').toggleClass('is-invalid', ordateEmpty);
            if (ornoEmpty || ordateEmpty) {
                $('#badge-enrollment').addClass('show');
                ok = false;
            }
        }
        return ok;
    };

    $('#updateForm .form-control, #updateForm .form-select').on('input change', function () {
        $(this).removeClass('is-invalid');
    });

    /* ── POPULATE FORM ─────────────────────────────────────── */
    window.populateForm = function (p) {
        // Personal
        $('#fname').val(p.fname || '');
        $('#mname').val(p.mname || '');
        $('#lname').val(p.lname || '');
        $('#suffix').val(p.suffix || '');
        $('#gender').val(p.gender || 'Male');
        $('#civilstatus').val(p.civil_status || '');
        $('#email').val(p.email || '');
        $('#contactno').val(p.mobile_no || '');
        // Professional
        $('#positiontitle').val(p.position_title || '');
        $('#location').val((p.location || 'HRD').toUpperCase());
        // Agency
        $('#agency_name').val(p.agency_name || '');
        $('#agency_address').val(p.agency_address || '');
        // Enrollment
        $('#orno').val(p.or_no || '');
        $('#ordate').val(p.or_date || '');
        $('#remarks').val(p.remarks || '');
        $('#prime_hrm_discount').prop('checked', !!p.prime_hrm_discount);
        <?php if ($isFaceToFace): ?>
        $('#food_restrictions').val(p.food_restrictions || '');
        <?php endif; ?>

        // Status
        const statusVal  = (p.payment_status || 'pending').toLowerCase();
        _oldPaymentStatus = statusVal;
        const needsCO    = (statusVal === 'paid');
        const foLoc      = (p.location || '').toUpperCase();

        $(`input.payment-opt[value="${statusVal}"]`).prop('checked', true);

        syncStatusUI(statusVal);

        // Load COs and pre-select the stored officer using the race-condition-safe helper
        if (needsCO) {
            loadCollectingOfficers(foLoc, p.collecting_officer || null);
        }

        // Header
        const fname = p.fname || '', lname = p.lname || '';
        $('#fullName').text(`${fname} ${lname}`.trim() || 'Unknown');
        $('#modalAvatar').text(((fname.charAt(0) || '') + (lname.charAt(0) || '')).toUpperCase() || '?');
        $('#modalEnrollmentMeta').text(`Enrollment #${p.enrollment_id || '—'}`);

        $('.tab-badge').removeClass('show');
        $('.is-invalid').removeClass('is-invalid');
        switchTab('tab-personal');
    };

    /* ── MODAL RESET ON CLOSE ──────────────────────────────── */
    $('#updateModal').on('hidden.bs.modal', function () {
        showSkeleton();
        $('#updateForm')[0].reset();
        $('.payment-opt').prop('checked', false);
        $('#status').val('');
        $('#statusAlert').removeClass().addClass('status-alert mb-3 d-none');
        $('#orFieldsGroup').addClass('hidden');
        $('#collecting_officer').html('<option value="">— Select Officer —</option>');
        $('#remarksRequired').hide();
        $('#fullName').text('Loading participant…');
        $('#modalAvatar').text('?');
        $('#modalEnrollmentMeta').text('');
        $('.is-invalid').removeClass('is-invalid');
        $('.tab-badge').removeClass('show');
        coLoaded = {};
    });

    /* ── SAVE BUTTON ───────────────────────────────────────── */
    $('#updateParticipantBtn').on('click', function () { updateParticipant(); });

    /* ── REPLACE FROM UPDATE MODAL ─────────────────────────── */
    $('#replaceFromUpdateBtn').on('click', function () {
        const fname = $('#fname').val().trim() || '';
        const lname = $('#lname').val().trim() || '';
        const name  = lname ? `${lname}, ${fname}` : (fname || '—');
        const eid   = $('#enrollmentId').val();
        const pid   = $('#participantId').val();

        $('#updateModal').one('hidden.bs.modal', function () {
            openReplaceModal(eid, pid, name);
        });
        $('#updateModal').modal('hide');
    });
});


/* ── SHOW UPDATE MODAL ──────────────────────────────────────────────── */
function showUpdateModal(enrollmentId) {
    showSkeleton();
    switchTab('tab-personal');
    $('#enrollmentId').val(enrollmentId);
    $('#updateModal').modal('show');

    const fd = new FormData();
    fd.append('enrollmentId', enrollmentId);
    apiFetch('api/field-office/fetch_participant.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            hideSkeleton();
            if (res.status !== 'success') {
                $('#updateModal').modal('hide');
                Swal.fire('Error', res.message || 'Could not load participant.', 'error');
                return;
            }
            const p = res.participant;
            $('#participantId').val(p.participant_id);
            populateForm(p);
        })
        .catch(() => {
            hideSkeleton();
            Swal.fire('Error', 'Request failed.', 'error');
        });
}


/* ── UPDATE PARTICIPANT ─────────────────────────────────────────────── */
function updateParticipant() {
    if (!validateAll()) {
        miniToast('⚠ Please fill in all required fields.');
        const firstBad = ['tab-personal', 'tab-professional', 'tab-agency', 'tab-enrollment'].find(tab => {
            const fields = { 'tab-personal': ['fname','lname','email'], 'tab-professional': ['positiontitle'], 'tab-agency': ['agency_name'], 'tab-enrollment': [] };
            if ((fields[tab] || []).some(id => { const el = document.getElementById(id); return el && !el.value.trim(); })) return true;
            if (tab === 'tab-enrollment') {
                if ($('#status').val() === 'promissory note') {
                    const rem = document.getElementById('remarks');
                    return rem && !rem.value.trim();
                }
                if ($('#status').val() === 'paid') {
                    const orno   = document.getElementById('orno');
                    const ordate = document.getElementById('ordate');
                    return (orno && !orno.value.trim()) || (ordate && !ordate.value.trim());
                }
            }
            return false;
        });
        if (firstBad) switchTab(firstBad);
        return;
    }

    const statusVal = $('#status').val();

    const doUpdate = () => {
        const $btn = $('#updateParticipantBtn');
        $btn.addClass('loading').prop('disabled', true);

        const fd = new FormData();
        fd.append('enrollmentId',       $('#enrollmentId').val());
        fd.append('participantId',      $('#participantId').val());
        fd.append('fname',              $('#fname').val());
        fd.append('mname',              $('#mname').val());
        fd.append('lname',              $('#lname').val());
        fd.append('suffix',             $('#suffix').val());
        fd.append('gender',             $('#gender').val());
        fd.append('civilstatus',        $('#civilstatus').val());
        fd.append('positiontitle',      $('#positiontitle').val());
        fd.append('agency_name',        $('#agency_name').val());
        fd.append('agency_address',     $('#agency_address').val());
        fd.append('email',              $('#email').val());
        fd.append('contactno',          $('#contactno').val());
        fd.append('location',           $('#location').val());
        fd.append('paymentStatus',      statusVal);
        fd.append('orno',               $('#orno').val());
        fd.append('ordate',             $('#ordate').val());
        fd.append('collecting_officer', $('#collecting_officer').val());
        fd.append('remarks',            $('#remarks').val());
        fd.append('prime_hrm_discount', document.getElementById('prime_hrm_discount').checked ? '1' : '0');
        fd.append('food_restrictions',  $('#food_restrictions').val() || '');

        const _enrollId  = $('#enrollmentId').val();
        const _newStatus = statusVal;

        apiFetch('api/field-office/update_participant.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                $btn.removeClass('loading').prop('disabled', false);
                if (res.status === 'success') {
                    /* Update summary pills */
                    adjustPillCounts(_oldPaymentStatus, _newStatus);

                    /* Update the row badge in-place */
                    const badgeClsMap = {
                        'paid':            'badge bg-success js-pay-badge',
                        'cancelled':       'badge bg-danger js-pay-badge',
                        'promissory note': 'badge bg-warning text-dark js-pay-badge',
                        'free':            'badge bg-success js-pay-badge',
                    };
                    const newBadgeCls = badgeClsMap[_newStatus] || 'badge bg-primary js-pay-badge';
                    const newBadgeTxt = _newStatus.replace(/\b\w/g, c => c.toUpperCase());
                    const $row = $(`tr[data-enrollment-id="${_enrollId}"]`);
                    if ($row.length) {
                        $row.find('.js-pay-badge').attr('class', newBadgeCls).text(newBadgeTxt);
                        $row.find('input.participant-checkbox').attr('data-status', _newStatus);
                        if (_newStatus === 'cancelled') {
                            $row.find('button.btn-primary').prop('disabled', true)
                                .removeClass('btn-primary').addClass('btn-secondary');
                        }
                    }

                    $('#updateModal').modal('hide');
                    Swal.fire({ icon: 'success', title: 'Updated!', text: res.message });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            })
            .catch(() => {
                $btn.removeClass('loading').prop('disabled', false);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' });
            });
    };

    if (statusVal === 'cancelled') {
        // Bootstrap 5 modal enforces focus at the browser/ARIA level (aria-modal), which
        // blocks keyboard input in any overlay not inside the modal's own DOM — no JS patch
        // can fully override this. Reliable fix: dismiss the Bootstrap modal first, show
        // SweetAlert2 after it is fully gone, then reopen the modal if the user cancels.

        const _enrollmentId  = $('#enrollmentId').val();
        const _participantId = $('#participantId').val();
        const _bsModal       = bootstrap.Modal.getInstance(document.getElementById('updateModal'));

        $('#updateModal').one('hidden.bs.modal', function () {
            Swal.fire({
                icon:  'warning',
                title: 'Request Cancellation',
                html:  '<p class="text-muted mb-2" style="font-size:.88rem;">' +
                           'Cancellation requires <strong>HRD approval</strong>.<br>' +
                           'Provide the reason and HRD will be notified.' +
                       '</p>',
                input:            'textarea',
                inputPlaceholder: 'Enter reason for cancellation…',
                inputAttributes:  { rows: 3 },
                didOpen: () => {
                    const label = Swal.getPopup().querySelector('.swal2-input-label');
                    if (label) label.innerHTML = 'Remarks / Reason <span style="color:#dc3545;">*</span>';
                    Swal.getPopup().querySelector('.swal2-textarea')?.focus();
                },
                showCancelButton:   true,
                confirmButtonText:  '<i class="bi bi-send me-1"></i>Submit Request',
                confirmButtonColor: '#dc3545',
                cancelButtonText:   'Go Back',
                inputValidator: value => {
                    if (!value.trim()) return 'Please provide a reason for the cancellation request.';
                }
            }).then(result => {
                if (!result.isConfirmed) {
                    _bsModal.show();   // user backed out — reopen the edit modal
                    return;
                }

                Swal.fire({ title: 'Submitting…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                const fd = new FormData();
                fd.append('enrollmentId',  _enrollmentId);
                fd.append('participantId', _participantId);
                fd.append('trainingId',    CONFIG.trainingId);
                fd.append('remarks',       result.value.trim());

                apiFetch('api/field-office/request_cancellation.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'success') {
                            Swal.fire({
                                icon:  'success',
                                title: 'Request Submitted',
                                html:  res.message + '<br><small class="text-muted">Track the status on the <strong>Cancellation Requests</strong> page.</small>',
                            }).then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        }
                    })
                    .catch(() => {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed. Please try again.' });
                    });
            });
        });

        _bsModal.hide();
    } else {
        doUpdate();
    }
}


/* ── FILTER ─────────────────────────────────────────────────────────── */
function applyFilter(val) {
    const url = new URL(window.location.href);
    val === 'All' ? url.searchParams.delete('filter') : url.searchParams.set('filter', val);
    window.location.href = url.toString();
}


/* ── HELPERS ─────────────────────────────────────────────────────────── */
function getCheckedEnrollmentIds() {
    return [...document.querySelectorAll('.participant-checkbox:checked')]
           .map(cb => cb.getAttribute('data-enrollment-id'));
}
function getCheckedParticipantsWithStatus() {
    return [...document.querySelectorAll('.participant-checkbox:checked')]
           .map(cb => ({
               id:     cb.getAttribute('data-enrollment-id'),
               status: cb.getAttribute('data-status'),
               name:   cb.getAttribute('data-name')
           }));
}


/* ── EMAIL RESULT DIALOG ─────────────────────────────────────────────── */
function _showEmailResult(res) {
    const icon = res.failed > 0 ? (res.sent > 0 ? 'warning' : 'error') : 'success';
    let html = `<b>${res.sent}</b> sent`;
    if (res.failed) html += `, <b>${res.failed}</b> failed`;
    if (res.errors && res.errors.length) {
        html += '<ul style="text-align:left;margin-top:.5rem;font-size:.85rem">'
              + res.errors.slice(0, 5).map(e => `<li>${e}</li>`).join('')
              + (res.errors.length > 5 ? `<li>…and ${res.errors.length - 5} more</li>` : '')
              + '</ul>';
    }
    Swal.fire({ icon, title: icon === 'success' ? 'Sent!' : 'Partial Send', html });
}

/* ── SEND ACKNOWLEDGMENT LETTER ─────────────────────────────────────── */
function sendAcknowledgmentLetter() {
    const ids = getCheckedEnrollmentIds();
    if (!ids.length) return Swal.fire('Warning', 'Select at least one participant.', 'warning');
    Swal.fire({
        title: 'Send Acknowledgment Letter?',
        html: `Sending to <b>${ids.length}</b> participant(s).`,
        icon: 'question', showCancelButton: true, confirmButtonText: 'Send',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const fd = new FormData();
            fd.append('enrollmentIds', JSON.stringify(ids));
            return apiFetch(`api/field-office/send_email-ack.php?id=${CONFIG.trainingId}`, { method: 'POST', body: fd })
                .then(r => r.json())
                .catch(() => Promise.reject('Server error'));
        }
    }).then(r => { if (r.isConfirmed) _showEmailResult(r.value); })
      .catch(err => Swal.fire('Error', String(err), 'error'));
}


/* ── SEND ZOOM LINK ─────────────────────────────────────────────────── */
function confirmSendEmailZoom() {
    const selected = getCheckedParticipantsWithStatus().filter(p => p.status !== 'cancelled');
    if (!selected.length) return Swal.fire('Warning', 'Select at least one paid participant.', 'warning');
    const unpaid = selected.filter(p => !['paid', 'promissory note'].includes(p.status));
    if (unpaid.length) {
        return Swal.fire({ title: 'Unpaid Selected', html: unpaid.map(p => `<li>${p.name}</li>`).join(''), icon: 'error' });
    }

    const zoomLink = document.getElementById('zoomLinkInput').value.trim();
    const pdfFile  = document.getElementById('zoomPDF').files[0];
    const bgFile   = document.getElementById('zoomBg').files[0];
    bootstrap.Modal.getInstance(document.getElementById('zoomLinkModal')).hide();

    Swal.fire({
        title: 'Send Zoom Links?',
        text: `Sending to ${selected.length} participant(s).`,
        icon: 'question', showCancelButton: true, confirmButtonText: 'Send',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const fd = new FormData();
            fd.append('enrollmentIds', JSON.stringify(selected.map(p => p.id)));
            fd.append('zoomLinkInput', zoomLink);
            if (pdfFile) fd.append('zoomPDF', pdfFile);
            if (bgFile)  fd.append('zoomBg',  bgFile);
            return apiFetch(`api/field-office/send_email-link.php?id=${CONFIG.trainingId}`, { method: 'POST', body: fd })
                .then(r => r.json())
                .catch(() => Promise.reject('Server error'));
        }
    }).then(r => { if (r.isConfirmed) _showEmailResult(r.value); })
      .catch(err => Swal.fire('Error', String(err), 'error'));
}


/* ── SEND TRAINING ADVISORY ─────────────────────────────────────────── */
function sendEmailAdvisory() {
    const selected = getCheckedParticipantsWithStatus().filter(p => p.status === 'pending');
    if (!selected.length) return Swal.fire('Warning', 'Select at least one <b>Pending</b> participant.', 'warning');
    Swal.fire({
        title: 'Send Advisory?',
        text: `Sending to ${selected.length} pending participant(s).`,
        icon: 'question', showCancelButton: true, confirmButtonText: 'Send',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const fd = new FormData();
            fd.append('participants', JSON.stringify(selected));
            return apiFetch(`api/field-office/send_email-adv.php?id=${CONFIG.trainingId}`, { method: 'POST', body: fd })
                .then(r => r.json())
                .catch(() => Promise.reject('Server error'));
        }
    }).then(r => { if (r.isConfirmed) _showEmailResult(r.value); })
      .catch(err => Swal.fire('Error', String(err), 'error'));
}
</script>

<script>
/* ── REPLACEMENT MODAL ──────────────────────────────────────────────── */
function repSwitchTab(tabId) {
    document.querySelectorAll('#replaceModal .rep-tab-btn').forEach(function (btn) {
        var active = btn.dataset.tab === tabId;
        btn.classList.toggle('active', active);
        btn.setAttribute('aria-selected', active);
    });
    document.querySelectorAll('#replaceModal .tab-pane-content').forEach(function (pane) {
        pane.classList.toggle('active', pane.id === tabId);
    });
}

function openReplaceModal(enrollmentId, participantId, name) {
    document.getElementById('replaceForm').reset();
    document.getElementById('rep_enrollmentId').value          = enrollmentId;
    document.getElementById('rep_trainingId').value            = TRAINING_ID;
    document.getElementById('rep_originalParticipantId').value = participantId;
    document.getElementById('rep_originalName').textContent    = name || '—';
    repSwitchTab('rep-tab-personal');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('replaceModal')).show();
}

document.querySelectorAll('#replaceModal .rep-tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { repSwitchTab(this.dataset.tab); });
});

document.getElementById('replaceParticipantBtn').addEventListener('click', function () {
    var fname  = document.getElementById('rep_fname').value.trim();
    var lname  = document.getElementById('rep_lname').value.trim();
    var reason = document.getElementById('rep_replacement_reason').value.trim();

    if (!fname || !lname) {
        Swal.fire('Missing Fields', 'First name and last name are required.', 'warning');
        repSwitchTab('rep-tab-personal');
        return;
    }
    if (!reason) {
        Swal.fire('Missing Fields', 'Please provide a replacement reason.', 'warning');
        repSwitchTab('rep-tab-reason');
        return;
    }

    var btn = this;
    btn.disabled = true;
    btn.classList.add('loading');

    apiFetch('api/field-office/replace_participant.php', {
        method: 'POST',
        body:   new FormData(document.getElementById('replaceForm'))
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        btn.disabled = false;
        btn.classList.remove('loading');
        if (res.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('replaceModal')).hide();
            Swal.fire({ icon: 'success', title: 'Replaced!', text: res.message || 'Participant successfully replaced.' })
                .then(function () { location.reload(); });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Replacement failed.' });
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.classList.remove('loading');
        Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed. Please try again.' });
    });
});

document.getElementById('replaceModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('replaceForm').reset();
    document.getElementById('rep_originalName').textContent = '—';
    repSwitchTab('rep-tab-personal');
});
</script>

<script>
/* ── REGISTRATION & PAYMENT LOG TIMELINE ──────────────────────────────── */
document.getElementById('participantsList').addEventListener('click', function (e) {
    const btn = e.target.closest('.pay-log-btn');
    if (!btn) return;

    const regId = btn.dataset.id;
    const name  = btn.dataset.name || 'Participant';

    document.getElementById('payLogName').textContent = name;
    document.getElementById('payLogMeta').textContent = 'Enrollment #' + regId + ' · Registration & Payment Log';
    document.getElementById('payLogBody').innerHTML =
        '<div class="text-center py-4 text-muted">' +
        '<div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading log…</div>';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('payLogModal')).show();

    const fd = new FormData();
    fd.append('enrollment_id', regId);
    apiFetch('api/field-office/fetch_reg_payment_log.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.status !== 'success') {
                document.getElementById('payLogBody').innerHTML =
                    '<div class="alert alert-danger">' + (res.message || 'Failed to load log.') + '</div>';
                return;
            }
            document.getElementById('payLogBody').innerHTML = buildPayLogTimeline(res);
        })
        .catch(function () {
            document.getElementById('payLogBody').innerHTML =
                '<div class="alert alert-danger">Network error. Please try again.</div>';
        });
});

function buildPayLogTimeline(res) {
    var esc = function (s) {
        return (s || '—').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    };
    var statusCls = function (s) {
        var m = { 'paid':'paid','pending':'pending','cancelled':'cancel','promissory note':'pn','free':'free','not qualified':'nq' };
        return m[(s || '').toLowerCase()] || 'other';
    };
    var statusIcon = function (s) {
        var m = { 'paid':'bi-check-circle-fill','pending':'bi-hourglass-split','cancelled':'bi-x-circle-fill',
                  'promissory note':'bi-journal-text','free':'bi-gift','not qualified':'bi-slash-circle' };
        return m[(s || '').toLowerCase()] || 'bi-circle';
    };
    var badge = function (s) {
        return '<span class="plt-badge ' + statusCls(s) + '">' + esc(s) + '</span>';
    };
    var fmtDate = function (str) {
        if (!str) return '—';
        var d = new Date(str.replace(' ', 'T'));
        if (isNaN(d)) return str;
        return d.toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' })
             + ' ' + d.toLocaleTimeString('en-PH', { hour:'numeric', minute:'2-digit', hour12:true });
    };

    var html = '<ul class="pay-log-timeline list-unstyled mb-0">';

    html += '<li class="plt-item">'
          + '<div class="plt-dot reg"><i class="bi bi-person-check-fill"></i></div>'
          + '<div class="plt-body">'
          + '<div class="plt-title">Participant Registered</div>'
          + '<div class="plt-meta">'
          + '<span class="plt-meta-item"><i class="bi bi-calendar3"></i>' + fmtDate(res.registered_at) + '</span>'
          + '<span class="plt-meta-item"><i class="bi bi-tag"></i>Initial status: ' + badge(res.initial_status) + '</span>'
          + '</div></div></li>';

    if (!res.logs || res.logs.length === 0) {
        html += '<li class="plt-item" style="padding-bottom:0;">'
              + '<div class="plt-dot other"><i class="bi bi-info-circle"></i></div>'
              + '<div class="plt-body">'
              + '<div class="plt-no-logs"><i class="bi bi-info-circle-fill"></i>'
              + 'No activity has been recorded for this enrollment.</div>'
              + '</div></li>';
    } else {
        res.logs.forEach(function (log) {
            var type = log.event_type || 'payment';

            if (type === 'payment') {
                var sc = statusCls(log.new_status);
                var orRow = '';
                if (log.or_no || log.or_date || log.collecting_officer) {
                    orRow = '<div class="plt-meta mt-1">'
                          + (log.or_no   ? '<span class="plt-meta-item"><i class="bi bi-receipt"></i>OR No.: <strong>' + esc(log.or_no) + '</strong></span>' : '')
                          + (log.or_date ? '<span class="plt-meta-item"><i class="bi bi-calendar-check"></i>OR Date: <strong>' + esc(log.or_date) + '</strong></span>' : '')
                          + (log.collecting_officer ? '<span class="plt-meta-item"><i class="bi bi-person-badge"></i>' + esc(log.collecting_officer) + '</span>' : '')
                          + '</div>';
                }
                html += '<li class="plt-item">'
                      + '<div class="plt-dot ' + sc + '"><i class="bi ' + statusIcon(log.new_status) + '"></i></div>'
                      + '<div class="plt-body">'
                      + '<div class="plt-title">' + esc(log.action) + '</div>'
                      + '<div class="plt-meta"><span class="plt-meta-item">'
                      + badge(log.old_status)
                      + '<i class="bi bi-arrow-right" style="color:#94a3b8;font-size:.8rem;"></i>'
                      + badge(log.new_status) + '</span></div>'
                      + orRow
                      + '<div class="plt-meta mt-2">'
                      + '<span class="plt-meta-item"><i class="bi bi-person-fill"></i>' + esc(log.actor) + '</span>'
                      + '<span class="plt-meta-item"><i class="bi bi-calendar3"></i>' + fmtDate(log.event_at) + '</span>'
                      + '</div></div></li>';

            } else if (type === 'cancellation') {
                html += '<li class="plt-item">'
                      + '<div class="plt-dot cancel-req"><i class="bi bi-send-exclamation-fill"></i></div>'
                      + '<div class="plt-body">'
                      + '<div class="plt-title">' + esc(log.action) + '</div>'
                      + (log.remarks ? '<div class="plt-meta"><span class="plt-meta-item" style="font-style:italic;color:#92400e;">&ldquo;' + esc(log.remarks) + '&rdquo;</span></div>' : '')
                      + '<div class="plt-meta mt-2">'
                      + '<span class="plt-meta-item"><i class="bi bi-person-fill"></i>' + esc(log.actor) + '</span>'
                      + '<span class="plt-meta-item"><i class="bi bi-calendar3"></i>' + fmtDate(log.event_at) + '</span>'
                      + '</div></div></li>';

            } else if (type === 'cancellation_review') {
                var approved = (log.request_status || '').toLowerCase() === 'approved';
                var dotCls = approved ? 'paid' : 'cancel';
                var icon   = approved ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
                html += '<li class="plt-item">'
                      + '<div class="plt-dot ' + dotCls + '"><i class="bi ' + icon + '"></i></div>'
                      + '<div class="plt-body">'
                      + '<div class="plt-title">' + esc(log.action) + '</div>'
                      + (log.remarks ? '<div class="plt-meta"><span class="plt-meta-item" style="font-style:italic;">&ldquo;' + esc(log.remarks) + '&rdquo;</span></div>' : '')
                      + '<div class="plt-meta mt-2">'
                      + '<span class="plt-meta-item"><i class="bi bi-person-fill"></i>' + esc(log.actor) + '</span>'
                      + '<span class="plt-meta-item"><i class="bi bi-calendar3"></i>' + fmtDate(log.event_at) + '</span>'
                      + '</div></div></li>';

            } else if (type === 'replacement') {
                html += '<li class="plt-item">'
                      + '<div class="plt-dot replace"><i class="bi bi-arrow-left-right"></i></div>'
                      + '<div class="plt-body">'
                      + '<div class="plt-title">' + esc(log.action) + '</div>'
                      + '<div class="plt-meta"><span class="plt-meta-item">'
                      + '<i class="bi bi-person-dash" style="color:#dc2626;"></i>' + esc(log.original_name)
                      + '<i class="bi bi-arrow-right" style="color:#94a3b8;font-size:.8rem;"></i>'
                      + '<i class="bi bi-person-check" style="color:#16a34a;"></i>' + esc(log.new_name)
                      + '</span></div>'
                      + (log.remarks ? '<div class="plt-meta"><span class="plt-meta-item" style="font-style:italic;">&ldquo;' + esc(log.remarks) + '&rdquo;</span></div>' : '')
                      + '<div class="plt-meta mt-2">'
                      + '<span class="plt-meta-item"><i class="bi bi-person-fill"></i>' + esc(log.actor) + '</span>'
                      + '<span class="plt-meta-item"><i class="bi bi-calendar3"></i>' + fmtDate(log.event_at) + '</span>'
                      + '</div></div></li>';

            } else if (type === 'profile_update') {
                html += '<li class="plt-item">'
                      + '<div class="plt-dot other" style="background:#ede9fe;color:#7c3aed;border-color:#ddd6fe;"><i class="bi bi-pencil-square"></i></div>'
                      + '<div class="plt-body">'
                      + '<div class="plt-title">Participant Info Updated</div>'
                      + (log.notes ? '<div class="plt-meta"><span class="plt-meta-item" style="color:#6b7280;font-size:.78rem;">' + esc(log.notes) + '</span></div>' : '')
                      + '<div class="plt-meta mt-2">'
                      + '<span class="plt-meta-item"><i class="bi bi-person-fill"></i>' + esc(log.actor) + '</span>'
                      + '<span class="plt-meta-item"><i class="bi bi-calendar3"></i>' + fmtDate(log.event_at) + '</span>'
                      + '</div></div></li>';
            }
        });
    }

    html += '</ul>';
    return html;
}
</script>

</body>
</html>
