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

/* ── Participants ──────────────────────────────────────────────────────── */
$filterOptions = ['All', 'paid', 'pending', 'promissory note', 'cancelled'];
if (!in_array(strtolower($filter), array_map('strtolower', $filterOptions), true)) {
    $filter = 'pending';
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

    if ($filter !== 'All') {
        $sql .= " AND LOWER(r.payment_status) = LOWER(:status)";
        $params[':status'] = $filter;
    }

    $sql .= " ORDER BY p.lname ASC, p.fname ASC";

    $stmtP = $conn->prepare($sql);
    $stmtP->execute($params);
    $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log('[participants.php] participant query: ' . $ex->getMessage());
    $participants = [];
}

$colOffset = $showCheckboxColumn ? 1 : 0;
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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="assets/vendor/remixicon/remixicon.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>" rel="stylesheet">
<link href="../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
<link href="../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<link href="assets/css/field-office.css?v=<?php echo filemtime(__DIR__.'/assets/css/field-office.css'); ?>" rel="stylesheet">

</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-blue"><i class="bi bi-people-fill"></i></div>
                <div>
                    <h1>Unpaid Participants</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index.php?location=<?php echo urlencode($location); ?>">Home</a></li>
                            <li class="breadcrumb-item"><?php echo strtoupper(htmlspecialchars($_SESSION['type'] ?? 'FO')); ?></li>
                            <li class="breadcrumb-item cell-ellipsis" style="max-width:280px;"><?php echo htmlspecialchars($training['name']); ?></li>
                            <li class="breadcrumb-item active">Master List</li>
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
        <div class="otrs-card">
            <div class="otrs-card-body">

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
                        <table id="participantsList" class="table table-bordered table-hover table-striped otrs-table w-100">
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
                                    <th class="text-center align-middle">Position Title</th>
                                    <th class="text-center align-middle">Agency / Office</th>
                                    <th class="text-center align-middle">Agency Address</th>
                                    <th class="text-center align-middle">Email</th>
                                    <th class="text-center align-middle">Mobile</th>
                                    <th class="text-center align-middle">FO</th>
                                    <th class="text-center align-middle">Status</th>
                                    <th class="text-center align-middle">OR No.</th>
                                    <th class="text-center align-middle">OR Date</th>
                                    <th class="text-center align-middle">Collecting Officer</th>
                                    <?php if ($isFaceToFace): ?>
                                        <th class="text-center align-middle">Food Restrictions</th>
                                    <?php endif; ?>
                                    <th class="text-center align-middle">Remarks</th>
                                    <th class="text-center align-middle">Trng Status</th>
                                    <th class="text-center align-middle">Reg Date</th>
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
                                    'paid'            => 'ts-badge ts-completed',
                                    'cancelled'       => 'ts-badge ts-cancelled',
                                    'promissory note' => 'ts-badge ts-ongoing',
                                    default           => 'ts-badge ts-upcoming',
                                };
                                $trgBadge = match ($trgStatus) {
                                    'completed'      => 'ts-badge ts-completed',
                                    'For Compliance' => 'ts-badge ts-cancelled',
                                    default          => 'ts-badge ts-default',
                                };
                            ?>
                                <tr>
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
                                            <button class="btn-tbl btn-tbl-gray" disabled
                                                    data-bs-toggle="tooltip" title="cancelled">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        <?php else: ?>
                                            <div class="d-flex flex-column gap-1 align-items-center">
                                                <button class="btn-tbl btn-tbl-primary"
                                                        onclick="showUpdateModal(<?php echo $enrollmentId; ?>)"
                                                        data-bs-toggle="tooltip" title="Update">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <button class="btn-action-replace"
                                                        onclick="openReplaceModal(<?php echo $enrollmentId; ?>, <?php echo $participantId; ?>, <?php echo htmlspecialchars(json_encode($fullName), ENT_QUOTES, 'UTF-8'); ?>)"
                                                        data-bs-toggle="tooltip" title="Replace Participant">
                                                    <i class="bi bi-arrow-left-right"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $count; ?></td>
                                    <td><?php echo htmlspecialchars(trim($row['fname'] . ' ' . $row['mname'] . ' ' . $row['lname'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['gender']); ?></td>
                                    <td><?php echo htmlspecialchars($row['age'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['civil_status']); ?></td>
                                    <td><?php echo htmlspecialchars($row['position_title']); ?></td>
                                    <td><?php echo htmlspecialchars($row['agency_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['agency_address']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['mobile_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['fo']); ?></td>
                                    <td><span class="<?php echo $payBadge; ?>"><?php echo htmlspecialchars(ucwords($payStatus)); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['or_no'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['or_date'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['collecting_officer'] ?? ''); ?></td>
                                    <?php if ($isFaceToFace): ?>
                                        <td><?php echo htmlspecialchars($row['food_restrictions'] ?? ''); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td>
                                    <td><?php if ($trgStatus): ?><span class="<?php echo $trgBadge; ?>"><?php echo htmlspecialchars($trgStatus); ?></span><?php endif; ?></td>
                                    <td><?php echo htmlspecialchars($row['registered_at'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="otrs-alert otrs-alert-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span>
                            No participants found for <b><?php echo htmlspecialchars($training['name']); ?></b>
                            <?php if ($filter !== 'All'): ?>
                                with filter <b><?php echo htmlspecialchars(ucwords($filter)); ?></b>
                            <?php endif; ?>.
                        </span>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </section>
</main>

<?php include 'inc/footer.php' ?>

<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<!-- ══ UPDATE MODAL ══════════════════════════════════════════════════════ -->
<div id="updateModal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <div class="modal-avatar" id="modalAvatar">?</div>
                    <div class="modal-title-text">
                        <h6 id="fullName">Loading participant…</h6>
                        <small id="modalEnrollmentMeta"></small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Tab nav -->
            <div class="modal-tabs" role="tablist">
                <button class="modal-tab-btn active" data-tab="tab-personal" aria-selected="true">
                    <i class="bi bi-person tab-icon"></i> Personal
                    <span class="tab-badge" id="badge-personal"></span>
                </button>
                <button class="modal-tab-btn" data-tab="tab-professional" aria-selected="false">
                    <i class="bi bi-briefcase tab-icon"></i> Professional
                    <span class="tab-badge" id="badge-professional"></span>
                </button>
                <button class="modal-tab-btn" data-tab="tab-agency" aria-selected="false">
                    <i class="bi bi-building tab-icon"></i> Agency
                    <span class="tab-badge" id="badge-agency"></span>
                </button>
                <button class="modal-tab-btn" data-tab="tab-enrollment" aria-selected="false">
                    <i class="bi bi-clipboard-check tab-icon"></i> Enrollment
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
                                    <label class="form-label">OR No.</label>
                                    <input type="text" class="form-control" id="orno" name="orno">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">OR Date</label>
                                    <input type="date" class="form-control" id="ordate" name="ordate">
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
                                <input type="text" class="form-control" id="food_restrictions" readonly>
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
            <div class="modal-header modal-header-blue">
                <div class="modal-title-wrap">
                    <div class="modal-avatar"><i class="bi bi-camera-video-fill"></i></div>
                    <div class="modal-title-text">
                        <h6>Email Zoom Link</h6>
                        <small>Sends to Paid &amp; Promissory Note participants</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3"><label class="form-label">Zoom Link:</label>
                    <input id="zoomLinkInput" class="form-control" type="text" placeholder="Paste the Zoom link here…"></div>
                <div class="mb-3"><label class="form-label">Training Advisory PDF:</label>
                    <input type="file" id="zoomPDF" class="form-control" accept="application/pdf"></div>
                <div><label class="form-label">Zoom Background:</label>
                    <input type="file" id="zoomBg" class="form-control" accept="image/*"></div>
            </div>
            <div class="modal-footer-branded">
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal"><i class="bi bi-x me-1"></i> Cancel</button>
                <button type="button" class="btn-modal-save" onclick="confirmSendEmailZoom()"><i class="ri ri-mail-send-line me-1"></i> Send</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ REPLACEMENT MODAL ════════════════════════════════════════════════ -->
<div id="replaceModal" class="modal fade" tabindex="-1" aria-hidden="true">
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
                    <i class="bi bi-person tab-icon"></i> Personal
                </button>
                <button class="modal-tab-btn rep-tab-btn" data-tab="rep-tab-professional" aria-selected="false">
                    <i class="bi bi-briefcase tab-icon"></i> Professional
                </button>
                <button class="modal-tab-btn rep-tab-btn" data-tab="rep-tab-agency" aria-selected="false">
                    <i class="bi bi-building tab-icon"></i> Agency
                </button>
                <button class="modal-tab-btn rep-tab-btn" data-tab="rep-tab-reason" aria-selected="false">
                    <i class="bi bi-chat-text tab-icon"></i> Reason
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

$(function () {
    /* ── EXTRA COLUMNS (hidden by default) ────────────────── */
    const EXTRA_BASE = [3, 4, 5, 8, 13, 14, 15];
    <?php if ($isFaceToFace): ?>EXTRA_BASE.push(16);<?php endif; ?>
    const ftfShift = <?php echo $isFaceToFace ? 1 : 0; ?>;
    EXTRA_BASE.push(16 + ftfShift); // remarks
    EXTRA_BASE.push(17 + ftfShift); // training status
    const EXTRA = EXTRA_BASE.map(i => i + COL_OFFSET);

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

    /* ── COLUMN TOGGLE ─────────────────────────────────────── */
    let expanded = false;
    $('#toggleColumnsBtn').on('click', function () {
        expanded = !expanded;
        EXTRA.forEach(idx => table.column(idx).visible(expanded));
        $(this).toggleClass('btn-f-toggle-active', expanded);
        $('#toggleColumnsLabel').text(expanded ? 'Core Only' : 'Show All');
        $('#colToggleHint').text(expanded ? 'Extended view' : 'Core view');
    });

    /* ── CHECK-ALL ─────────────────────────────────────────── */
    $('#checkAll').on('change', function () {
        $('.participant-checkbox').prop('checked', this.checked);
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
        const showRemarksReq = (value === 'promissory note');
        $('#remarksRequired').toggle(showRemarksReq);
    };

    $('.payment-opt').on('change', function () { syncStatusUI($(this).val()); });

    /* ── COLLECTING OFFICERS ───────────────────────────────── */
    let coLoaded = {};
    window.loadCollectingOfficers = function (location) {
        const loc = location || $('#location').val() || '';
        if (coLoaded[loc]) return;
        coLoaded[loc] = true;
        const fd = new FormData();
        fd.append('location', loc);
        apiFetch('api/field-office/fetch_co_dropdown.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                const list    = Array.isArray(res) ? res : [];
                const $sel    = $('#collecting_officer');
                const current = $sel.val();
                $sel.html('<option value="">— Select Officer —</option>');
                list.forEach(co => $sel.append(`<option value="${co.name}">${co.name}</option>`));
                if (current) $sel.val(current);
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
        // Remarks required for Promissory Note
        if ($('#status').val() === 'promissory note') {
            const $rem = $('#remarks');
            const empty = !$rem.val().trim();
            $rem.toggleClass('is-invalid', empty);
            $('#badge-enrollment').toggleClass('show', empty);
            if (empty) ok = false;
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
        <?php if ($isFaceToFace): ?>
        $('#food_restrictions').val(p.food_restrictions || '');
        <?php endif; ?>

        // Status
        const statusVal  = (p.payment_status || 'pending').toLowerCase();
        const needsCO    = (statusVal === 'paid');
        const foLoc      = (p.location || '').toUpperCase();

        $(`input.payment-opt[value="${statusVal}"]`).prop('checked', true);

        // Block syncStatusUI from triggering a redundant loadCollectingOfficers call
        if (needsCO) coLoaded[foLoc] = true;
        syncStatusUI(statusVal);

        // Load COs and pre-select the stored officer
        if (needsCO) {
            const fd2 = new FormData();
            fd2.append('location', foLoc);
            apiFetch('api/field-office/fetch_co_dropdown.php', { method: 'POST', body: fd2 })
                .then(r => r.json())
                .then(res => {
                    const list = Array.isArray(res) ? res : [];
                    const $sel = $('#collecting_officer');
                    $sel.html('<option value="">— Select Officer —</option>');
                    list.forEach(co => $sel.append(`<option value="${co.name}">${co.name}</option>`));
                    if (p.collecting_officer) $sel.val(p.collecting_officer);
                })
                .catch(() => {});
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
            if (tab === 'tab-enrollment' && $('#status').val() === 'promissory note') {
                const rem = document.getElementById('remarks');
                return rem && !rem.value.trim();
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

        apiFetch('api/field-office/update_participant.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                $btn.removeClass('loading').prop('disabled', false);
                if (res.status === 'success') {
                    $('#updateModal').modal('hide');
                    Swal.fire({ icon: 'success', title: 'Updated!', text: res.message })
                        .then(() => location.reload());
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

</body>
</html>
