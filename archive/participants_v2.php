<?php
/**
 * participants.php (user version)
 *
 * OTRS — List of Participants (Refactored)
 *
 * Migration notes
 * ───────────────
 * OLD  → NEW
 * trainings            → otrs_trainings
 * participants         → otrs_participants  (identity)
 *                      + otrs_enrollments   (payment / training status)
 *
 * The page is now keyed on otrs_trainings.id  (GET: id)
 * and otrs_enrollments.id  (enrollment_id) — the canonical
 * participant-training pivot row.  Legacy participant.id is
 * no longer used as a routing key.
 *
 * Column renames
 * ──────────────
 * civilstatus        → civil_status          (otrs_participants)
 * positiontitle      → position_title        (otrs_participants)
 * mobileno           → mobile_no             (otrs_participants)
 * status             → payment_status        (otrs_enrollments)
 * orno / ordate      → or_no / or_date       (otrs_enrollments)
 * status_training    → status_training       (otrs_enrollments)  [same name]
 * timereg            → registered_at         (otrs_enrollments)
 * date (varchar)     → start_date / end_date (otrs_trainings)
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/connect.php';
include_once __DIR__ . '/../imis_include.php';

/* ── Auth ─────────────────────────────────────────────────────────────── */
if (empty($_SESSION['username'])) {
    header('Location: admin/login');
    exit();
}

$location = $_GET['location'] ?? '';

if ($location && $_SESSION['type'] !== $location) {
    header('Location: index?location=' . $_SESSION['type']);
    exit();
}

$trainingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$trainingId) {
    die('<div class="alert alert-danger m-3">Invalid or missing training ID.</div>');
}

/* ── Location label ───────────────────────────────────────────────────── */
$locationLabels = [
    ''       => 'CSC RO VIII – Human Resource Division',
    'hrd'    => 'CSC RO VIII – Human Resource Division',
    'lfoi'   => 'CSC Field Office – Leyte I',
    'lfoii'  => 'CSC Field Office – Leyte II',
    'bfo'    => 'CSC Field Office – Biliran',
    'wlso'   => 'CSC Satellite Office – Western Leyte',
    'slfo'   => 'CSC Field Office – Southern Leyte',
    'sfo'    => 'CSC Field Office – Samar',
    'esfo'   => 'CSC Field Office – Eastern Samar',
    'nsfo'   => 'CSC Field Office – Northern Samar',
    'ro6'    => 'CSC Regional Office VI',
    'ro7'    => 'CSC Regional Office VII',
];
$locationText = $locationLabels[$location] ?? strtoupper($location);

/* ── Helpers ──────────────────────────────────────────────────────────── */
function formatDateRange(string $start, ?string $end): string
{
    if (empty($start) || $start === '0000-00-00') return 'N/A';
    try {
        $s = new DateTimeImmutable($start);
        $e = ($end && $end !== '0000-00-00') ? new DateTimeImmutable($end) : null;
        if (!$e || $s->format('Y-m-d') === $e->format('Y-m-d')) return $s->format('F j, Y');
        if ($s->format('Y-m') === $e->format('Y-m')) return $s->format('F j') . '–' . $e->format('j, Y');
        return $s->format('F j') . ' – ' . $e->format('F j, Y');
    } catch (Exception $ex) {
        return htmlspecialchars($start);
    }
}

/* ── DB connection ────────────────────────────────────────────────────── */
try {
    $conn = new PDO(
        'mysql:host=' . $servername . ';dbname=' . $dbname . ';charset=utf8mb4',
        $username, $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    die('<div class="alert alert-danger m-3">Database connection failed.</div>');
}

/* ── Fetch training ───────────────────────────────────────────────────── */
try {
    $stmtT = $conn->prepare("
        SELECT id, name, start_date, end_date, date_legacy,
               location, mode, slots, fee, training_status, status
        FROM otrs_trainings WHERE id = :id LIMIT 1
    ");
    $stmtT->bindValue(':id', $trainingId, PDO::PARAM_INT);
    $stmtT->execute();
    $training = $stmtT->fetch();
} catch (PDOException $ex) {
    die('<div class="alert alert-danger m-3">Error loading training details.</div>');
}

if (!$training) die('<div class="alert alert-warning m-3">Training not found.</div>');

$trainingDateDisplay = (!empty($training['start_date']) && $training['start_date'] !== '0000-00-00')
    ? formatDateRange($training['start_date'], $training['end_date'])
    : htmlspecialchars($training['date_legacy'] ?? '');

$isFaceToFace      = (stripos(trim($training['mode']), 'face') !== false);
$showCheckboxColumn = in_array($location, ['ro6', 'ro7'], true);

/* ── Fetch participants ───────────────────────────────────────────────── */
$filter = $_GET['filter'] ?? '';

try {
    $sql = "
        SELECT p.id AS participant_id, p.fname, p.mname, p.lname,
               p.gender, p.age, p.civil_status, p.position_title,
               p.agency_name, p.agency_address, p.email, p.mobile_no,
               p.location AS fo, p.food_restrictions,
               e.id AS enrollment_id, e.payment_status, e.or_no, e.or_date,
               e.collecting_officer, e.remarks, e.status_training, e.registered_at
        FROM otrs_enrollments e
        JOIN otrs_participants p ON p.id = e.participant_id
        WHERE e.training_id = :training_id
    ";
    $params = [':training_id' => $trainingId];
    if ($location !== '') {
        $sql .= " AND UPPER(p.location) = UPPER(:location)";
        $params[':location'] = $location;
    }
    $sql .= " ORDER BY p.lname ASC, p.fname ASC";

    $stmtP = $conn->prepare($sql);
    foreach ($params as $key => $value) $stmtP->bindValue($key, $value);
    $stmtP->execute();
    $participants = $stmtP->fetchAll();
} catch (PDOException $ex) {
    $participants = [];
}

if ($filter !== '' && $filter !== 'All') {
    $participants = array_values(array_filter(
        $participants,
        fn($row) => $row['payment_status'] === $filter
    ));
}

$totalCount = count($participants);
$colOffset  = $showCheckboxColumn ? 1 : 0;
$filterOptions = ['All', 'Paid', 'Pending', 'Promissory Note', 'Cancelled'];
$pageTitle = htmlspecialchars($training['name']) . ' – ' . $trainingDateDisplay . ' – ' . $locationText;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="author" content="CSCRO8-ITG">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title><?= $pageTitle ?></title>

    <link href="assets/img/favicon.png" rel="icon">
    <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="assets/vendor/remixicon/remixicon.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">

    <style>
        /* ── Layout ────────────────────────────────────────────────── */
        .sidebar  { display: none; }
        .main     { margin-left: 0 !important; }

        label, input[type="text"], input[type="date"],
        select.form-select, .form-control { font-size: 14px; }

        /* ── Training info strip ────────────────────────────────────── */
        .training-strip {
            background: #0077b6;
            border-radius: 7px;
            padding: .85rem 1.2rem;
            color: #fff;
            margin-bottom: 1.1rem;
        }
        .strip-label {
            font-size: .67rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 1px;
            opacity: .72; margin-bottom: .2rem;
        }
        .strip-title {
            font-size: .98rem; font-weight: 700;
            line-height: 1.35; margin-bottom: .45rem;
        }
        .strip-meta { display: flex; flex-wrap: wrap; gap: .4rem 1rem; }
        .strip-meta-item {
            display: inline-flex; align-items: center;
            gap: .28rem; font-size: .77rem; opacity: .88;
        }

        /* ── Table styling ──────────────────────────────────────────── */
        .table thead {
            background: #f0f5ff;
        }
        .table thead th {
            font-size: .60rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            /* color: #2c3e6b; */
            vertical-align: middle;
        }

        /* ── Section label ──────────────────────────────────────────── */
        .section-label {
            font-size: .68rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 1.5px;
            color: #899bbd; border-bottom: 1px solid #ebeef4;
            padding-bottom: .4rem; margin-bottom: .75rem;
        }

        /* ── Toolbar ────────────────────────────────────────────────── */
        .toolbar {
            display: flex; flex-wrap: wrap;
            align-items: center; gap: .38rem;
            padding: .6rem .9rem;
            background: #f6f9ff;
            border: 1px solid #ebeef4;
            border-radius: 6px;
        }
        .toolbar + .toolbar { margin-top: .45rem; }
        .toolbar-divider { width: 1px; height: 20px; background: #d1d8e8; flex-shrink: 0; margin: 0 .05rem; }
        .toolbar-spacer  { flex: 1 1 auto; }

        /* ── Flat button system ─────────────────────────────────────── */
        .btn-f {
            border: none; color: #fff;
            font-size: .78rem; font-weight: 600;
            padding: .3rem .75rem; border-radius: 4px;
            display: inline-flex; align-items: center; gap: .3rem;
            cursor: pointer; text-decoration: none;
            white-space: nowrap; line-height: 1.45;
            transition: filter .13s ease, transform .12s ease;
        }
        .btn-f:hover  { filter: brightness(.86); transform: translateY(-1px); color: #fff; }
        .btn-f:active { filter: brightness(.78); transform: translateY(0);    color: #fff; }
        .btn-f:focus  { outline: none; }
        .btn-f-primary   { background: #0077b6; }
        .btn-f-success   { background: #198754; }
        .btn-f-danger    { background: #dc3545; }
        .btn-f-secondary { background: #6c757d; }
        .btn-f-warning   { background: #e07b00; }

        /* Column-toggle active state */
        .btn-f-toggle-active {
            background: #5a3e9e !important;
            box-shadow: inset 0 0 0 2px rgba(255,255,255,.35);
        }

        /* ── Filter select ──────────────────────────────────────────── */
        .filter-select {
            font-size: .77rem !important; font-weight: 600;
            border: 1px solid #d1d8e8; border-radius: 4px;
            padding: .28rem .55rem; color: #012970;
            background: #fff; height: auto; cursor: pointer;
        }
        .filter-select:focus {
            border-color: #0077b6;
            box-shadow: 0 0 0 .15rem rgba(0,119,182,.15);
            outline: none;
        }

        /* ── Back button ────────────────────────────────────────────── */
        .btn-back-page {
            background: #dc3545; border: none; color: #fff;
            font-size: .78rem; font-weight: 600;
            padding: .32rem .78rem; border-radius: 5px;
            display: inline-flex; align-items: center; gap: .3rem;
            text-decoration: none;
            transition: filter .13s ease, transform .12s ease;
            white-space: nowrap; align-self: flex-start;
        }
        .btn-back-page:hover  { filter: brightness(.86); transform: translateY(-1px); color: #fff; }
        .btn-back-page:active { filter: brightness(.78); transform: translateY(0);    color: #fff; }

        /* ── Column-toggle hint ─────────────────────────────────────── */
        #colToggleHint {
            font-size: .68rem; color: #6c757d;
            margin-left: .3rem; white-space: nowrap;
        }

        /* ── Table container ────────────────────────────────────────── */
        .table-container { position: relative; min-height: 200px; }
    </style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <!-- ── PAGE TITLE ──────────────────────────────────────────────────── -->
    <div class="pagetitle mb-3">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <div class="d-flex align-items-center justify-content-center rounded-3"
                     style="width:44px;height:44px;flex-shrink:0;background:#e8f0fe;">
                    <i class="bi bi-people fs-5 text-primary"></i>
                </div>
                <div>
                    <h1 class="mb-0" style="font-size:1.1rem;">List of Participants</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 mt-1">
                            <li class="breadcrumb-item">
                                <a href="index?location=<?= htmlspecialchars($location) ?>">Home</a>
                            </li>
                            <li class="breadcrumb-item"><?= strtoupper(htmlspecialchars($_SESSION['type'])) ?></li>
                            <li class="breadcrumb-item active"
                                style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= htmlspecialchars($training['name']) ?>
                            </li>
                            <li class="breadcrumb-item active">Participants</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="index?location=<?= htmlspecialchars($location) ?>" class="btn-back-page">
                <i class="bi bi-arrow-left-circle"></i> Go Back
            </a>
        </div>
    </div>
    <!-- ── END PAGE TITLE ──────────────────────────────────────────────── -->

    <section class="section">
        <div class="card">
            <div class="card-body p-4">

                <!-- ── TRAINING INFO STRIP ───────────────────────────── -->
                <div class="training-strip">
                    <div class="strip-label">Training Details</div>
                    <div class="strip-title"><?= htmlspecialchars($training['name']) ?></div>
                    <div class="strip-meta">
                        <span class="strip-meta-item">
                            <i class="bi bi-calendar3"></i> <?= $trainingDateDisplay ?>
                        </span>
                        <span class="strip-meta-item">
                            <i class="bi bi-geo-alt"></i>
                            <?= htmlspecialchars($training['location'] ?? '—') ?>
                        </span>
                        <span class="strip-meta-item">
                            <i class="bi bi-display"></i>
                            <?= htmlspecialchars($training['mode']) ?>
                        </span>
                        <span class="strip-meta-item">
                            <i class="bi bi-people"></i>
                            <?= (int) $training['slots'] ?> slots
                        </span>
                        <span class="strip-meta-item">
                            <i class="bi bi-cash"></i>
                            ₱<?= number_format((float) $training['fee'], 2) ?>
                        </span>
                        <span class="strip-meta-item">
                            <i class="bi bi-building"></i>
                            <?= htmlspecialchars($locationText) ?>
                        </span>
                    </div>
                </div>

                <!-- ── TOOLBAR 1 — Actions & Filters ────────────────── -->
                <p class="section-label"><i class="bi bi-sliders me-1"></i> Actions &amp; Filters</p>

                <div class="toolbar">

                    <button class="btn-f btn-f-primary"
                            data-bs-toggle="modal" data-bs-target="#infoTrainingModal">
                        <i class="bi bi-info-square"></i> Info
                    </button>

                    <div class="toolbar-divider"></div>

                    <button id="exportButton" class="btn-f btn-f-success">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>

                    <?php if ($showCheckboxColumn): ?>
                        <div class="toolbar-divider"></div>
                        <button class="btn-f btn-f-primary" onclick="sendAcknowledgmentLetter()">
                            <i class="ri ri-mail-send-line"></i> Ack Letter
                        </button>
                        <button class="btn-f btn-f-primary"
                                data-bs-toggle="modal" data-bs-target="#zoomLinkModal">
                            <i class="ri ri-mail-send-line"></i> Zoom Link
                        </button>
                        <button class="btn-f btn-f-primary" onclick="sendEmailAdvisory()">
                            <i class="ri ri-mail-send-line"></i> Advisory
                        </button>
                    <?php endif; ?>

                    <div class="toolbar-spacer"></div>

                    <!-- Column visibility toggle -->
                    <button id="toggleColumnsBtn" class="btn-f btn-f-secondary"
                            title="Toggle extended columns">
                        <i class="bi bi-layout-three-columns"></i>
                        <span id="toggleColumnsLabel">Show All</span>
                    </button>
                    <span id="colToggleHint">Core view</span>

                    <div class="toolbar-divider"></div>

                    <!-- Filter -->
                    <div class="d-flex align-items-center gap-2">
                        <label for="filter" class="fw-bold mb-0"
                               style="font-size:.78rem;color:#012970;white-space:nowrap;">
                            <i class="bi bi-funnel me-1 text-primary"></i>Filter:
                        </label>
                        <select id="filter" class="filter-select" onchange="applyFilter(this.value)">
                            <?php foreach ($filterOptions as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"
                                    <?= ($filter === $opt || ($filter === '' && $opt === 'All')) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($opt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- ── TABLE ─────────────────────────────────────────── -->
                <p class="section-label mt-3">
                    <i class="bi bi-table me-1"></i> Participant Records
                </p>

                <?php if ($totalCount > 0): ?>

                    <!-- JS config injected by PHP — must precede DataTables init -->
                    <script>
                        const COL_OFFSET      = <?= (int) $colOffset ?>;
                        const IS_FACE_TO_FACE = <?= $isFaceToFace ? 'true' : 'false' ?>;
                        const TRAINING_ID     = <?= (int) $trainingId ?>;
                    </script>

                    <div class="table-container">
                        <div class="table-responsive">
                            <table id="participantsList"
                                   class="table table-bordered table-hover table-striped w-100"
                                   style="font-size:12px;">
                                <thead>
                                    <tr>
                                        <?php if ($showCheckboxColumn): ?>
                                            <th class="text-center align-middle">
                                                <input type="checkbox" id="checkAll"
                                                       style="transform:scale(1.5);">
                                            </th>
                                        <?php endif; ?>
                                        <th class="text-center align-middle">Action</th>
                                        <th class="text-center align-middle">#</th>
                                        <th class="text-center align-middle">Name of Participant</th>
                                        <th class="text-center align-middle">Gender</th>
                                        <th class="text-center align-middle">Age</th>
                                        <th class="text-center align-middle">Civil Status</th>
                                        <th class="text-center align-middle">Position Title</th>
                                        <th class="text-center align-middle">Agency/Office</th>
                                        <th class="text-center align-middle">Agency Address</th>
                                        <th class="text-center align-middle">Email Address</th>
                                        <th class="text-center align-middle">Contact No.</th>
                                        <th class="text-center align-middle">FO</th>
                                        <th class="text-center align-middle">Status</th>
                                        <th class="text-center align-middle">OR No.</th>
                                        <th class="text-center align-middle">Date of OR</th>
                                        <th class="text-center align-middle">Collecting Officer</th>
                                        <?php if ($isFaceToFace): ?>
                                            <th class="text-center align-middle">Food Restrictions</th>
                                        <?php endif; ?>
                                        <th class="text-center align-middle">Remarks</th>
                                        <th class="text-center align-middle">Training Status</th>
                                        <th class="text-center align-middle">Reg Date &amp; Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $count = 0;
                                    foreach ($participants as $row):
                                        $count++;
                                        $enrollmentId  = (int) $row['enrollment_id'];
                                        $participantId = (int) $row['participant_id'];
                                        $payStatus     = $row['payment_status'];
                                        $trgStatus     = $row['status_training'];
                                        $fullName      = trim($row['lname'] . ', ' . $row['fname'] . ' ' . $row['mname']);

                                        $payBadge = match ($payStatus) {
                                            'Paid'            => 'badge bg-success',
                                            'Cancelled'       => 'badge bg-danger',
                                            'Promissory Note' => 'badge bg-warning text-dark',
                                            default           => 'badge bg-primary',
                                        };
                                        $trgBadge = match ($trgStatus) {
                                            'Completed'      => 'badge bg-success',
                                            'For Compliance' => 'badge bg-danger',
                                            default          => 'badge bg-primary',
                                        };
                                    ?>
                                        <tr>
                                            <?php if ($showCheckboxColumn): ?>
                                                <td class="text-center align-middle">
                                                    <input type="checkbox"
                                                           class="participant-checkbox form-check-input"
                                                           style="transform:scale(1.3);"
                                                           value="<?= $enrollmentId ?>"
                                                           data-enrollment-id="<?= $enrollmentId ?>"
                                                           data-participant-id="<?= $participantId ?>"
                                                           data-status="<?= htmlspecialchars($payStatus) ?>"
                                                           data-name="<?= htmlspecialchars($fullName) ?>">
                                                </td>
                                            <?php endif; ?>

                                            <td class="text-center align-middle">
                                                <?php if ($payStatus === 'Cancelled'): ?>
                                                    <button class="btn btn-primary btn-sm disabled"
                                                            data-bs-toggle="tooltip"
                                                            data-bs-placement="left"
                                                            title="Participant is Cancelled">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-primary btn-sm"
                                                            onclick="showUpdateModal(<?= $enrollmentId ?>)"
                                                            data-bs-toggle="tooltip"
                                                            data-bs-placement="left"
                                                            title="Update Participant">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center"><?= $count ?></td>
                                            <td><?= htmlspecialchars(trim($row['fname'] . ' ' . $row['mname'] . ' ' . $row['lname'])) ?></td>
                                            <td class="text-center"><?= htmlspecialchars($row['gender']) ?></td>
                                            <td class="text-center"><?= htmlspecialchars($row['age']) ?></td>
                                            <td><?= htmlspecialchars($row['civil_status']) ?></td>
                                            <td><?= htmlspecialchars($row['position_title']) ?></td>
                                            <td><?= htmlspecialchars($row['agency_name']) ?></td>
                                            <td><?= htmlspecialchars($row['agency_address']) ?></td>
                                            <td><?= htmlspecialchars($row['email']) ?></td>
                                            <td><?= htmlspecialchars($row['mobile_no']) ?></td>
                                            <td class="text-center"><?= htmlspecialchars($row['fo']) ?></td>
                                            <td class="text-center">
                                                <span class="<?= $payBadge ?>">
                                                    <?= htmlspecialchars($payStatus) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($row['or_no']) ?></td>
                                            <td><?= htmlspecialchars($row['or_date'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['collecting_officer']) ?></td>
                                            <?php if ($isFaceToFace): ?>
                                                <td><?= htmlspecialchars($row['food_restrictions']) ?></td>
                                            <?php endif; ?>
                                            <td><?= htmlspecialchars($row['remarks']) ?></td>
                                            <td class="text-center">
                                                <span class="<?= $trgBadge ?>">
                                                    <?= htmlspecialchars($trgStatus) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($row['registered_at'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-x-circle me-2"></i>
                        No participants found for <b><?= htmlspecialchars($training['name']) ?></b>.
                    </div>
                <?php endif; ?>

            </div><!-- /.card-body -->
        </div><!-- /.card -->
    </section>

</main>

<?php include 'inc/footer.php' ?>
<a href="#" class="back-to-top d-flex align-items-center justify-content-center">
    <i class="bi bi-arrow-up-short"></i>
</a>

<!-- ══════════════════════════════════════════════════════════
     UPDATE MODAL
════════════════════════════════════════════════════════════ -->
<div id="updateModal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background:#0077b6;">
                <span class="modal-title text-white fw-bold">
                    Update Participant — <span id="fullName"></span>
                </span>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div id="formAlert" class="alert d-none" role="alert"></div>
                <input type="hidden" id="enrollmentId"  name="enrollmentId">
                <input type="hidden" id="participantId" name="participantId">

                <div class="row g-3" style="font-size:14px;">
                    <!-- Row 1: Name -->
                    <div class="col-md-4">
                        <label class="form-label fw-bold">First Name</label>
                        <input type="text" class="form-control" id="fname" name="fname">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">MI</label>
                        <input type="text" class="form-control" id="mname" name="mname" maxlength="2">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Last Name</label>
                        <input type="text" class="form-control" id="lname" name="lname">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Gender</label>
                        <select class="form-select" id="gender" name="gender">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-bold">Age</label>
                        <input type="text" class="form-control" id="age" name="age" maxlength="3">
                    </div>

                    <!-- Row 2: Civil status / Email / Mobile -->
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Civil Status</label>
                        <select class="form-select" id="civilstatus" name="civilstatus">
                            <option>Single</option>
                            <option>Married</option>
                            <option>Widowed/Widower</option>
                            <option>Separated</option>
                            <option>Others</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Email Address</label>
                        <input type="text" class="form-control" id="email" name="email">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Mobile No.</label>
                        <input type="text" class="form-control" id="contactno" name="contactno" maxlength="11">
                    </div>

                    <!-- Row 3: Position / Agency -->
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Position Title</label>
                        <input type="text" class="form-control" id="positiontitle" name="positiontitle">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Agency/Office</label>
                        <input type="text" class="form-control" id="agency_name" name="agency_name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Agency Address</label>
                        <input type="text" class="form-control" id="agency_address" name="agency_address">
                    </div>

                    <!-- Row 4: FO / Payment status / Collecting officer -->
                    <div class="col-md-3">
                        <label class="form-label fw-bold">FO</label>
                        <select class="form-select" id="fo" name="fo">
                            <option value="HRD">Region VIII-HRD</option>
                            <option value="LFOI">LFOI</option>
                            <option value="LFOII">LFOII</option>
                            <option value="BFO">BFO</option>
                            <option value="WLSO">WLSO</option>
                            <option value="SLFO">SLFO</option>
                            <option value="SFO">SFO</option>
                            <option value="ESFO">ESFO</option>
                            <option value="NSFO">NSFO</option>
                            <option value="RO6">Region VI</option>
                            <option value="RO7">Region VII</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Payment Status</label>
                        <select class="form-select" id="paymentStatus" name="paymentStatus">
                            <option value="Pending">Pending</option>
                            <option value="Paid">Paid</option>
                            <option value="Cancelled">Cancelled</option>
                            <option value="Promissory Note">Promissory Note</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Collecting Officer</label>
                        <select class="form-select" id="collecting_officer" name="collecting_officer"></select>
                    </div>

                    <!-- Row 5: OR details / Remarks -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">OR No.</label>
                        <input type="text" class="form-control" id="orno" name="orno">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Date of OR</label>
                        <input type="date" class="form-control" id="ordate" name="ordate">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label fw-bold">Remarks</label>
                        <textarea class="form-control" id="remarks" name="remarks"
                                  style="font-size:14px;"></textarea>
                    </div>
                </div>
            </div><!-- /.modal-body -->
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="updateParticipant()">
                    <i class="bi bi-save2"></i> Update
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Training Info Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="infoTrainingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#0077b6;">
                <span class="modal-title text-white fw-bold">
                    Training Details — <?= htmlspecialchars($training['name']) ?>
                </span>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <table class="table table-bordered mb-0" style="font-size:.88rem;">
                    <tbody>
                        <tr>
                            <td class="fw-bold" style="width:180px;">Name of Training</td>
                            <td colspan="3"><?= htmlspecialchars($training['name']) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Date of Training</td>
                            <td colspan="3"><?= $trainingDateDisplay ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Place of Training</td>
                            <td><?= htmlspecialchars($training['location']) ?></td>
                            <td class="fw-bold">Mode of Training</td>
                            <td><?= htmlspecialchars($training['mode']) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Slots Remaining</td>
                            <td colspan="3"><?= (int) $training['slots'] ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Registration Fee</td>
                            <td colspan="3">&#8369; <?= number_format((float) $training['fee'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Training Status</td>
                            <td colspan="3"><?= htmlspecialchars($training['training_status']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Zoom Link Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="zoomLinkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:#0077b6;">
                <span class="modal-title text-white fw-bold">Email Zoom Link</span>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted mb-3" style="font-size:.82rem;">
                    <i class="bi bi-info-circle me-1 text-primary"></i>
                    Will only send to <span class="fw-bold text-danger">Paid</span> and
                    <span class="fw-bold text-warning">Promissory Note</span> participants.
                </p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Zoom Link</label>
                    <input id="zoomLinkInput" class="form-control" type="text"
                           placeholder="Paste the Zoom link here…">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Training Advisory (PDF)</label>
                    <input type="file" id="zoomPDF" class="form-control" accept="application/pdf">
                </div>
                <div>
                    <label class="form-label fw-bold">Zoom Background Image</label>
                    <input type="file" id="zoomBg" class="form-control" accept="image/*">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmSendEmailZoom()">
                    <i class="ri ri-mail-send-line"></i> Send
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     JS LIBRARIES
════════════════════════════════════════════════════════════ -->
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.5.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>

<!-- ════════════════════════════════════════════════════════════
     PAGE SCRIPT
════════════════════════════════════════════════════════════ -->
<script>
$(document).ready(function () {

    /* ── 1. COLUMN VISIBILITY SETUP ─────────────────────────────────────
     *
     * Extra (hidden-by-default) column indices — baseline (no checkbox):
     *   gender=3, age=4, civil_status=5, agency_addr=8,
     *   or_no=13, or_date=14, coll_off=15
     *   food (F2F only): 16
     *   remarks: 16|17, trng_status: 17|18
     */
    const EXTRA_BASE = [3, 4, 5, 8, 13, 14, 15];
    if (IS_FACE_TO_FACE) EXTRA_BASE.push(16);

    const ftfShift = IS_FACE_TO_FACE ? 1 : 0;
    EXTRA_BASE.push(16 + ftfShift);  // remarks
    EXTRA_BASE.push(17 + ftfShift);  // training status

    const EXTRA_INDICES = EXTRA_BASE.map(i => i + COL_OFFSET);
    const colDefs = EXTRA_INDICES.map(idx => ({ targets: idx, visible: false }));

    /* ── 2. DATATABLE INIT ───────────────────────────────────────────── */
    const isMobile = () => $(window).width() <= 767;

    const table = $('#participantsList').DataTable({
        scrollX:      true,
        fixedHeader:  { headerOffset: 50 },
        columnDefs:   colDefs,
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>"                                  +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        fixedColumns: { left: isMobile() ? 0 : (COL_OFFSET + 3) },
        buttons: [{
            extend:  'excel',
            text:    'Export to Excel',
            filename: getExportFilename(),
            exportOptions: { columns: ':gt(' + COL_OFFSET + ')' }
        }],
        language: {
            search:           '<i class="bi bi-search"></i>',
            searchPlaceholder:'Search',
            lengthMenu:       'Show _MENU_ entries',
            paginate: {
                next:     '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            }
        },
        lengthMenu: [10, 50, 100],
        pageLength:  10
    });

    $(window).on('resize', () => table.fixedColumns().update());
    $('#exportButton').on('click', () => table.button('.buttons-excel').trigger());

    /* ── 3. COLUMN VISIBILITY TOGGLE ────────────────────────────────── */
    let columnsExpanded = false;

    $('#toggleColumnsBtn').on('click', function () {
        columnsExpanded = !columnsExpanded;
        EXTRA_INDICES.forEach(idx => table.column(idx).visible(columnsExpanded));
        $(this).toggleClass('btn-f-toggle-active', columnsExpanded);
        $('#toggleColumnsLabel').text(columnsExpanded ? 'Core Only' : 'Show All');
        $('#colToggleHint').text(columnsExpanded ? 'Extended view' : 'Core view');
    });

    /* ── 4. CHECK-ALL ────────────────────────────────────────────────── */
    const checkAllEl = document.getElementById('checkAll');
    if (checkAllEl) {
        const boxes = document.querySelectorAll('.participant-checkbox');
        checkAllEl.addEventListener('change', () =>
            boxes.forEach(cb => { cb.checked = checkAllEl.checked; })
        );
    }

    /* ── 5. COLLECTING OFFICER DROPDOWN ─────────────────────────────── */
    function fetchCollectingOfficers(fo) {
        $.ajax({
            url: 'fetch_co_dropdown.php', method: 'POST',
            data: { location: fo }, dataType: 'json',
            success(list) {
                const sel = $('#collecting_officer').empty();
                list.forEach(co => sel.append($('<option>').val(co.id).text(co.name)));
            }
        });
    }

    function handlePaymentStatusChange(status) {
        const isPaidOrPN = status === 'Paid' || status === 'Promissory Note';
        $('#collecting_officer, #orno, #ordate').prop('disabled', !isPaidOrPN);
        if (isPaidOrPN) {
            fetchCollectingOfficers($('#fo').val());
        } else {
            $('#collecting_officer').val('');
            $('#orno').val('');
            $('#ordate').val('');
        }
    }

    $('#updateModal').on('show.bs.modal', () => handlePaymentStatusChange($('#paymentStatus').val()));
    $('#paymentStatus').on('change', function () { handlePaymentStatusChange($(this).val()); });
    $('#fo').on('change', function () {
        const s = $('#paymentStatus').val();
        if (s === 'Paid' || s === 'Promissory Note') fetchCollectingOfficers($(this).val());
    });

    /* ── 6. EMAIL BUTTON VISIBILITY ─────────────────────────────────── */
    (function applyEmailButtonVisibility() {
        const initialFilter = <?= json_encode($filter) ?>;
        const ackBtn  = document.getElementById('sendEmailAck');
        const zoomBtn = document.getElementById('sendEmailZoom');
        const advBtn  = document.getElementById('sendEmailAdvisory');
        if (!ackBtn) return;

        const show = (btn, visible) => {
            if (btn) btn.style.display = visible ? 'inline-flex' : 'none';
        };

        if (initialFilter === 'Cancelled') {
            show(ackBtn, false); show(zoomBtn, false); show(advBtn, false);
        } else if (initialFilter === 'Paid') {
            show(ackBtn, false); show(zoomBtn, true);  show(advBtn, false);
        } else if (initialFilter === 'Pending' || initialFilter === 'Promissory Note') {
            show(ackBtn, true);  show(zoomBtn, false); show(advBtn, true);
        } else {
            show(ackBtn, true); show(zoomBtn, true); show(advBtn, true);
        }
    })();

    /* ── Utilities ───────────────────────────────────────────────────── */
    function getExportFilename() {
        const loc  = getQS('location');
        const d    = new Date();
        const mm   = String(d.getMonth() + 1).padStart(2, '0');
        const dd   = String(d.getDate()).padStart(2, '0');
        return <?= json_encode(addslashes($training['name'])) ?>
               + '_' + loc + '_' + mm + '-' + dd + '-' + d.getFullYear();
    }
    function getQS(name) {
        const m = new RegExp('[?&]' + name.replace(/[\[\]]/g, '\\$&') + '(=([^&#]*)|&|#|$)')
                      .exec(window.location.href);
        if (!m || !m[2]) return '';
        return decodeURIComponent(m[2].replace(/\+/g, ' '));
    }

}); // end $(document).ready


/* ════════════════════════════════════════════════════════════
   SHOW UPDATE MODAL
════════════════════════════════════════════════════════════ */
function showUpdateModal(enrollmentId) {
    $.ajax({
        url: 'fetch_participant.php', type: 'POST',
        data: { enrollmentId }, dataType: 'json',
        success(res) {
            if (res.status !== 'success') {
                Swal.fire('Error', res.message || 'Could not load participant.', 'error');
                return;
            }
            const p = res.participant;
            $('#enrollmentId').val(p.enrollment_id);
            $('#participantId').val(p.participant_id);
            $('#fname').val(p.fname);
            $('#mname').val(p.mname);
            $('#lname').val(p.lname);
            $('#gender').val(p.gender);
            $('#age').val(p.age);
            $('#civilstatus').val(p.civil_status);
            $('#positiontitle').val(p.position_title);
            $('#agency_name').val(p.agency_name);
            $('#agency_address').val(p.agency_address);
            $('#email').val(p.email);
            $('#contactno').val(p.mobile_no);
            $('#fo').val((p.location || '').toUpperCase());
            $('#paymentStatus').val(p.payment_status);
            $('#orno').val(p.or_no);
            $('#ordate').val(p.or_date);
            $('#remarks').val(p.remarks);
            $('#fullName').text(p.fname + ' ' + p.mname + ' ' + p.lname);
            $('#formAlert').addClass('d-none').text('');
            $('#updateModal').modal('show');
        },
        error(xhr, status, err) {
            Swal.fire('Error', 'AJAX request failed: ' + status + ' — ' + err, 'error');
        }
    });
}

/* ════════════════════════════════════════════════════════════
   UPDATE PARTICIPANT
════════════════════════════════════════════════════════════ */
function updateParticipant() {
    const payStatus = $('#paymentStatus').val();

    function doUpdate() {
        $.ajax({
            url: 'update_participant.php', type: 'POST',
            data: {
                enrollmentId:       $('#enrollmentId').val(),
                participantId:      $('#participantId').val(),
                fname:              $('#fname').val(),
                mname:              $('#mname').val(),
                lname:              $('#lname').val(),
                gender:             $('#gender').val(),
                age:                $('#age').val(),
                civilstatus:        $('#civilstatus').val(),
                positiontitle:      $('#positiontitle').val(),
                agency_name:        $('#agency_name').val(),
                agency_address:     $('#agency_address').val(),
                email:              $('#email').val(),
                contactno:          $('#contactno').val(),
                location:           $('#fo').val(),
                paymentStatus:      payStatus,
                orno:               $('#orno').val(),
                ordate:             $('#ordate').val(),
                collecting_officer: $('#collecting_officer').val(),
                remarks:            $('#remarks').val()
            },
            dataType: 'json',
            success(res) {
                if (res.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Updated', text: res.message })
                        .then(() => window.location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error(xhr, s, e) {
                Swal.fire({ icon: 'error', title: 'AJAX Error', text: s + ' — ' + e });
            }
        });
    }

    if (payStatus === 'Cancelled') {
        Swal.fire({
            icon: 'warning', title: 'Are you sure?',
            text: "This will cancel the participant's registration.",
            showCancelButton: true,
            confirmButtonText: 'Yes, cancel registration',
            cancelButtonText:  'No, keep registration'
        }).then(result => { if (result.isConfirmed) doUpdate(); });
    } else {
        doUpdate();
    }
}

/* ════════════════════════════════════════════════════════════
   SEND ACKNOWLEDGMENT LETTER
════════════════════════════════════════════════════════════ */
function sendAcknowledgmentLetter() {
    const ids = getCheckedEnrollmentIds();
    if (!ids.length) {
        Swal.fire('Select Participants',
            'Please select at least one participant to send the '
            + '<span style="color:blue"><b>acknowledgment letter</b></span>.', 'error');
        return;
    }
    Swal.fire({
        title: 'Send Acknowledgment Letter',
        html:  'Send the <b style="color:blue">acknowledgment letter</b> to '
               + ids.length + ' selected participant(s)?',
        icon:  'warning', showCancelButton: true,
        confirmButtonText: 'Send', cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: () => fetch(
            'send_email-ack.php?training_id=' + TRAINING_ID,
            { method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: 'enrollmentIds=' + encodeURIComponent(JSON.stringify(ids)) }
        ).then(r => r.ok ? r.text() : Promise.reject('Server error'))
    }).then(result => {
        if (result.isConfirmed) Swal.fire('Sent', 'Acknowledgment letter sent.', 'success');
    }).catch(err => Swal.fire('Error', String(err), 'error'));
}

/* ════════════════════════════════════════════════════════════
   SEND ZOOM LINK
════════════════════════════════════════════════════════════ */
function confirmSendEmailZoom() {
    const selected = getCheckedParticipantsWithStatus()
                         .filter(p => p.status !== 'Cancelled');
    if (!selected.length) {
        Swal.fire('Select Participants',
            'Please select at least one <b style="color:red">paid</b> participant.', 'error');
        return;
    }
    const unpaid = selected.filter(p => p.status !== 'Paid' && p.status !== 'Promissory Note');
    if (unpaid.length) {
        const names = unpaid.map((p, i) => '<b>' + (i + 1) + ':</b> ' + p.name).join('<br>');
        Swal.fire({ title: 'Unpaid Participants',
                    html:  'The following are not Paid or Promissory Note:<br><br>' + names,
                    icon:  'error' });
        return;
    }
    const zoomLink = $('#zoomLinkInput').val().trim();
    const pdfFile  = document.getElementById('zoomPDF').files[0];
    const bgFile   = document.getElementById('zoomBg').files[0];
    bootstrap.Modal.getInstance(document.getElementById('zoomLinkModal')).hide();
    Swal.fire({
        title: 'Send Zoom Link', text: 'Send the Zoom link to selected participants?',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Send', cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const fd = new FormData();
            fd.append('enrollmentIds', JSON.stringify(selected.map(p => p.id)));
            fd.append('zoomLinkInput', zoomLink);
            if (pdfFile) fd.append('zoomPDF', pdfFile);
            if (bgFile)  fd.append('zoomBg',  bgFile);
            return fetch('send_email-link.php?training_id=' + TRAINING_ID,
                         { method: 'POST', body: fd })
                       .then(r => r.ok ? r.text() : Promise.reject('Server error'));
        }
    }).then(result => {
        if (result.isConfirmed) Swal.fire('Sent', 'Zoom link sent.', 'success');
    }).catch(err => Swal.fire('Error', String(err), 'error'));
}

/* ════════════════════════════════════════════════════════════
   SEND TRAINING ADVISORY
════════════════════════════════════════════════════════════ */
function sendEmailAdvisory() {
    const selected = getCheckedParticipantsWithStatus()
                         .filter(p => p.status === 'Pending');
    if (!selected.length) {
        Swal.fire('Select Participants',
            'Please select at least one <b style="color:blue">pending</b> participant.', 'error');
        return;
    }
    Swal.fire({
        title: 'Send Training Advisory',
        html:  'Send the <b style="color:blue">training advisory</b> to '
               + selected.length + ' pending participant(s)?',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Send', cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: () => fetch(
            'send_email-adv.php?training_id=' + TRAINING_ID,
            { method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: 'participants=' + encodeURIComponent(JSON.stringify(selected)) }
        ).then(r => r.ok ? r.text() : Promise.reject('Server error'))
    }).then(result => {
        if (result.isConfirmed) Swal.fire('Sent', 'Training advisory sent.', 'success');
    }).catch(err => Swal.fire('Error', String(err), 'error'));
}

/* ── Filter ──────────────────────────────────────────────────────────── */
function applyFilter(val) {
    const p = new URLSearchParams(window.location.search);
    val === 'All' ? p.delete('filter') : p.set('filter', val);
    window.location.search = p.toString();
}

/* ── Helpers ─────────────────────────────────────────────────────────── */
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
</script>

</body>
</html>