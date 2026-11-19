<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$location   = isset($_GET['location']) ? $_GET['location'] : '';
$trainingId = isset($_GET['id'])       ? $_GET['id']       : '';
$filter     = isset($_GET['filter'])   ? $_GET['filter']   : 'All';

if (empty($trainingId)) die('Training ID is required.');

$locationMap = [
    ''      => 'CSC RO VIII - Human Resource Division',
    'hrd'   => 'CSC RO VIII - Human Resource Division',
    'lfoi'  => 'CSC Field Office - Leyte I',
    'lfoii' => 'CSC Field Office - Leyte II',
    'bfo'   => 'CSC Field Office - Biliran',
    'wlso'  => 'CSC Satellite Office - Western Leyte',
    'slfo'  => 'CSC Field Office - Southern Leyte',
    'sfo'   => 'CSC Field Office - Samar',
    'esfo'  => 'CSC Field Office - Eastern Samar',
    'nsfo'  => 'CSC Field Office - Northern Samar',
    'ro6'   => 'CSC Regional Office VI',
    'ro7'   => 'CSC Regional Office VII',
];
$locationText = isset($locationMap[$location]) ? $locationMap[$location] : 'CSC RO VIII';

try {
    $stmtTr = $conn->prepare("SELECT id, name, start_date, end_date, location, mode, slots, fee FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtTr->execute([':id' => $trainingId]);
    $training = $stmtTr->fetch(PDO::FETCH_ASSOC);

    if (!$training) die('Invalid training ID or Training not found.');

    /* ── Date display ── */
    $displayDate = '—';
    if (!empty($training['start_date']) && $training['start_date'] != '0000-00-00') {
        $start = new DateTime($training['start_date']);
        $end   = (!empty($training['end_date']) && $training['end_date'] != '0000-00-00') ? new DateTime($training['end_date']) : $start;
        if ($start == $end)                                $displayDate = $start->format('F j, Y');
        elseif ($start->format('Y') != $end->format('Y')) $displayDate = $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
        elseif ($start->format('m') != $end->format('m')) $displayDate = $start->format('M j') . ' - ' . $end->format('M j, Y');
        else                                               $displayDate = $start->format('F j') . '-' . $end->format('j, Y');
    }

    $whereLocation = !empty($location) ? 'AND op.location = :location' : '';

    /* ── Summary counts ── */
    $sqlCounts = "
        SELECT r.payment_status, r.registration_status
        FROM otrs_registrations r
        JOIN otrs_participants op ON r.participant_id = op.id
        WHERE r.training_id = :tid $whereLocation";
    $sCounts = $conn->prepare($sqlCounts);
    $sCounts->bindValue(':tid', (int)$trainingId, PDO::PARAM_INT);
    if (!empty($location)) $sCounts->bindParam(':location', $location);
    $sCounts->execute();
    $allRows = $sCounts->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['paid' => 0, 'pending' => 0, 'promissory note' => 0, 'cancelled' => 0, 'not qualified' => 0];
    foreach ($allRows as $row) {
        if ($row['registration_status'] === 'cancelled')     { $counts['cancelled']++; }
        elseif ($row['registration_status'] === 'not qualified') { $counts['not qualified']++; }
        elseif (isset($counts[$row['payment_status']]))      { $counts[$row['payment_status']]++; }
    }

    /* ── All participants for upload modal (unaffected by filter) ── */
    $stmtModal = $conn->prepare("
        SELECT r.id AS id, p.fname, p.mname, p.lname, p.suffix
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.training_id = :tid
          AND  r.registration_status NOT IN ('cancelled','not qualified')
        ORDER BY p.lname ASC, p.fname ASC
    ");
    $stmtModal->execute([':tid' => (int)$trainingId]);
    $modalParticipants = $stmtModal->fetchAll(PDO::FETCH_ASSOC);

    /* ── Fetch participants for display ── */
    $selectCols = "
        r.id AS id,
        p.id AS participant_id,
        CASE
            WHEN r.registration_status IN ('cancelled','not qualified') THEN r.registration_status
            ELSE r.payment_status
        END AS status,
        r.registration_status,
        p.fname, p.mname, p.lname, p.suffix,
        p.position_title  AS positiontitle,
        p.salary_grade,
        p.agency_name,
        p.location,
        r.or_no              AS orno,
        r.or_date            AS ordate,
        r.collecting_officer,
        r.remarks,
        r.prime_hrm_discount,
        r.proof_file";

    if ($filter === 'All') {
        $sql = "SELECT $selectCols
                FROM otrs_registrations r
                JOIN otrs_participants p ON r.participant_id = p.id
                WHERE r.training_id = :tid
                  AND r.payment_status IN ('pending','promissory note','paid')
                  AND r.registration_status NOT IN ('cancelled','not qualified')
                  $whereLocation
                ORDER BY p.lname ASC, p.fname ASC";
        $pStmt = $conn->prepare($sql);
        $pStmt->bindValue(':tid', (int)$trainingId, PDO::PARAM_INT);
        if (!empty($location)) $pStmt->bindParam(':location', $location);
    } elseif (in_array($filter, ['cancelled', 'not qualified'], true)) {
        $sql = "SELECT $selectCols
                FROM otrs_registrations r
                JOIN otrs_participants p ON r.participant_id = p.id
                WHERE r.training_id = :tid
                  AND r.registration_status = :filter_status
                  $whereLocation
                ORDER BY p.lname ASC, p.fname ASC";
        $pStmt = $conn->prepare($sql);
        $pStmt->bindValue(':tid', (int)$trainingId, PDO::PARAM_INT);
        $pStmt->bindParam(':filter_status', $filter);
        if (!empty($location)) $pStmt->bindParam(':location', $location);
    } else {
        $sql = "SELECT $selectCols
                FROM otrs_registrations r
                JOIN otrs_participants p ON r.participant_id = p.id
                WHERE r.training_id = :tid
                  AND r.payment_status = :filter_status
                  AND r.registration_status NOT IN ('cancelled','not qualified')
                  $whereLocation
                ORDER BY p.lname ASC, p.fname ASC";
        $pStmt = $conn->prepare($sql);
        $pStmt->bindValue(':tid', (int)$trainingId, PDO::PARAM_INT);
        $pStmt->bindParam(':filter_status', $filter);
        if (!empty($location)) $pStmt->bindParam(':location', $location);
    }

    $pStmt->execute();
    $participants = $pStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Proof of Payment — <?php echo htmlspecialchars($training['name']); ?></title>

    <link href="../assets/img/favicon.png" rel="icon">
    <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets/vendor/remixicon/remixicon.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.0.1/css/buttons.dataTables.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.min.css">
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

    <style>
        /* ── Layout ─────────────────────────────────────────── */
        .sidebar { display: none; }
        .main    { margin-left: 0 !important; }

        label, input, select, .form-control { font-size: 14px; }

        /* ── Loading overlay ─────────────────────────────────── */
        .table-container { position: relative; min-height: 200px; }
        .loading-overlay {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255,255,255,.8); z-index: 10;
            display: flex; justify-content: center; align-items: center;
        }

        /* ── Training info strip ─────────────────────────────── */
        .training-strip {
            background: #0077b6; border-radius: 7px;
            padding: .85rem 1.2rem; color: #fff; margin-bottom: 1.1rem;
        }
        .strip-label     { font-size:.67rem; font-weight:600; text-transform:uppercase; letter-spacing:1px; opacity:.72; margin-bottom:.2rem; }
        .strip-title     { font-size:.98rem; font-weight:700; line-height:1.35; margin-bottom:.45rem; }
        .strip-meta      { display:flex; flex-wrap:wrap; gap:.4rem 1rem; }
        .strip-meta-item { display:inline-flex; align-items:center; gap:.28rem; font-size:.77rem; opacity:.88; }

        /* ── Section label ───────────────────────────────────── */
        .section-label {
            font-size:.68rem; font-weight:600; text-transform:uppercase;
            letter-spacing:1.5px; color:#899bbd;
            border-bottom:1px solid #ebeef4; padding-bottom:.4rem; margin-bottom:.75rem;
        }

        /* ── Toolbar ─────────────────────────────────────────── */
        .toolbar {
            display:flex; flex-wrap:wrap; align-items:center; gap:.38rem;
            padding:.6rem .9rem; background:#f6f9ff;
            border:1px solid #ebeef4; border-radius:6px;
        }
        .toolbar + .toolbar { margin-top:.45rem; }
        .toolbar-divider    { width:1px; height:20px; background:#d1d8e8; flex-shrink:0; margin:0 .05rem; }
        .toolbar-spacer     { flex:1 1 auto; }

        /* ── Flat button system ──────────────────────────────── */
        /* ── Activity log button & timeline ─────────────────────── */
        .btn-pay-log {
            background: linear-gradient(135deg,#0f4c81,#1a6baa);
            color:#fff; border:none; padding:3px 9px; border-radius:6px;
            font-size:.72rem; font-weight:600; white-space:nowrap;
            cursor:pointer; transition:opacity .15s;
        }
        .btn-pay-log:hover { opacity:.82; }
        .pay-log-timeline { padding:0; margin:0; }
        .plt-item { display:flex; gap:14px; padding-bottom:22px; position:relative; }
        .plt-item:not(:last-child)::before {
            content:''; position:absolute; left:15px; top:34px; bottom:0;
            width:2px; background:linear-gradient(to bottom,#cbd5e1,transparent);
        }
        .plt-dot {
            flex-shrink:0; width:32px; height:32px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:.85rem; z-index:1; box-shadow:0 1px 3px rgba(0,0,0,.12);
        }
        .plt-dot.reg        { background:#dbeafe; color:#1d4ed8; }
        .plt-dot.paid       { background:#dcfce7; color:#15803d; }
        .plt-dot.pending    { background:#fef9c3; color:#92400e; }
        .plt-dot.cancel     { background:#fee2e2; color:#991b1b; }
        .plt-dot.pn         { background:#e0f2fe; color:#0369a1; }
        .plt-dot.free       { background:#dbeafe; color:#1e40af; }
        .plt-dot.other      { background:#f1f5f9; color:#64748b; }
        .plt-dot.cancel-req { background:#fff7ed; color:#c2410c; }
        .plt-dot.replace    { background:#f3e8ff; color:#7c3aed; }
        .plt-body  { flex:1; min-width:0; }
        .plt-title { font-size:.83rem; font-weight:600; color:#1e293b; margin-bottom:4px; line-height:1.3; }
        .plt-meta  { display:flex; flex-wrap:wrap; gap:4px 12px; font-size:.74rem; color:#64748b; margin-top:3px; }
        .plt-meta-item { display:flex; align-items:center; gap:4px; }
        .plt-badge {
            display:inline-flex; align-items:center; padding:1px 7px; border-radius:4px;
            font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.02em; line-height:1.6;
        }
        .plt-badge.paid    { background:#dcfce7; color:#15803d; }
        .plt-badge.pending { background:#fef9c3; color:#92400e; }
        .plt-badge.cancel  { background:#fee2e2; color:#991b1b; }
        .plt-badge.pn      { background:#e0f2fe; color:#0369a1; }
        .plt-badge.free    { background:#dbeafe; color:#1e40af; }
        .plt-badge.nq      { background:#f1f5f9; color:#475569; }
        .plt-badge.other   { background:#f1f5f9; color:#475569; }
        .plt-no-logs {
            display:flex; align-items:center; gap:8px; background:#f8fafc;
            border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px;
            font-size:.81rem; color:#64748b;
        }

        .btn-f {
            border:none; color:#fff; font-size:.78rem; font-weight:600;
            padding:.3rem .75rem; border-radius:4px;
            display:inline-flex; align-items:center; gap:.3rem;
            cursor:pointer; text-decoration:none; white-space:nowrap;
            transition:filter .13s ease, transform .12s ease; line-height:1.45;
        }
        .btn-f:hover  { filter:brightness(.86); transform:translateY(-1px); color:#fff; }
        .btn-f:active { filter:brightness(.78); transform:translateY(0);    color:#fff; }
        .btn-f:focus  { outline:none; }

        .btn-f-primary   { background:#0077b6; }
        .btn-f-success   { background:#198754; }
        .btn-f-danger    { background:#dc3545; }
        .btn-f-secondary { background:#6c757d; }
        .btn-f-warning   { background:#e07b00; }

        /* ── Inline filter select ────────────────────────────── */
        .filter-select {
            font-size:.77rem !important; font-weight:600;
            border:1px solid #d1d8e8; border-radius:4px;
            padding:.28rem .55rem; color:#012970; background:#fff;
            height:auto; cursor:pointer;
        }
        .filter-select:focus { border-color:#0077b6; box-shadow:0 0 0 .15rem rgba(0,119,182,.15); outline:none; }

        /* ── Back button ─────────────────────────────────────── */
        .btn-back-page {
            background:#dc3545; border:none; color:#fff;
            font-size:.78rem; font-weight:600; padding:.32rem .78rem; border-radius:5px;
            display:inline-flex; align-items:center; gap:.3rem;
            text-decoration:none;
            transition:filter .13s ease, transform .12s ease;
            white-space:nowrap; align-self:flex-start;
        }
        .btn-back-page:hover  { filter:brightness(.86); transform:translateY(-1px); color:#fff; }
        .btn-back-page:active { filter:brightness(.78); transform:translateY(0);    color:#fff; }

        /* ── Row action buttons ──────────────────────────────── */
        .row-action-btn {
            font-size:.7rem; padding:.15rem .4rem;
            line-height:1.45; white-space:nowrap; border:none;
            border-radius:4px; color:#fff; cursor:pointer;
            display:inline-flex; align-items:center; gap:.22rem;
            transition:filter .12s; text-decoration:none;
        }
        .row-action-btn:hover  { filter:brightness(.85); color:#fff; }
        .row-action-btn:active { filter:brightness(.75); color:#fff; }
        .row-action-btn.btn-edit   { background:#0077b6; }
        .row-action-btn.btn-delete { background:#dc3545; }
        .row-action-btn.btn-view   { background:#198754; }
        .row-action-btn.btn-dl     { background:#6c757d; }

        /* ── Proof file badge ────────────────────────────────── */
        .proof-file-badge {
            display:inline-flex; align-items:center; gap:.3rem;
            font-size:.72rem; font-weight:600;
            padding:.18rem .5rem; border-radius:4px;
        }
        .proof-file-badge.has-file { background:#d1e7dd; color:#0a3622; }
        .proof-file-badge.no-file  { background:#f0f0f0; color:#aaa; }

        /* ── Summary pills ───────────────────────────────────── */
        .summary-pills { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1rem; margin-top:.6rem; }
        .summary-pill  {
            display:inline-flex; align-items:center; gap:.35rem;
            padding:.28rem .7rem; border-radius:20px;
            font-size:.73rem; font-weight:700;
        }
        .pill-paid      { background:#d1e7dd; color:#0a3622; }
        .pill-pending   { background:#cfe2ff; color:#084298; }
        .pill-pn        { background:#fff3cd; color:#664d03; }
        .pill-cancelled { background:#f8d7da; color:#58151c; }
        .pill-total     { background:#e2e3e5; color:#41464b; }

        /* ── Edit modal — payment UI ─────────────────────────── */
        #editModal .modal-header { background:#0077b6; }
        #editModal .form-section-label {
            display:flex; align-items:center; gap:.5rem;
            font-size:.69rem; font-weight:700;
            text-transform:uppercase; letter-spacing:1.5px;
            color:#0077b6; border-bottom:1.5px solid #e2ecf8;
            padding-bottom:.5rem; margin-top:1.5rem; margin-bottom:1rem;
        }
        #editModal .form-section-label:first-child { margin-top:0; }
        #editModal .form-section-label i { font-size:.88rem; }
        #editModal .status-alert {
            display:flex; align-items:flex-start; gap:.65rem;
            padding:.7rem 1rem; border-radius:8px;
            font-size:.81rem; line-height:1.45;
            border:1px solid transparent; margin-bottom:1rem;
        }
        #editModal .status-alert.d-none { display:none !important; }
        #editModal .status-alert.paid    { background:#ecf9f2; border-color:#b6e6cf; color:#0d6832; }
        #editModal .status-alert.pending { background:#fff3e0; border-color:#ffcc80; color:#7a4a00; }
        #editModal .status-alert.pn      { background:#f3ebff; border-color:#c9b5f5; color:#4a1e8a; }
        #editModal .status-alert.cancel  { background:#fdecea; border-color:#f5c0ba; color:#922b21; }
        #editModal .status-alert.nq      { background:#f4f4f4; border-color:#ccc;    color:#555; }
        #editModal .status-alert i { margin-top:.15rem; flex-shrink:0; }
        #editModal .form-label {
            font-size:.73rem; font-weight:700; color:#4a5568;
            margin-bottom:.35rem; text-transform:uppercase;
            letter-spacing:.6px; line-height:1.3; display:block;
        }
        #editModal .req { color:#dc3545; }
        #editModal .form-control, #editModal .form-select {
            font-size:.86rem; border:1.5px solid #dce4ef;
            border-radius:8px; padding:.52rem .8rem;
            color:#1a2332; height:auto; min-height:38px;
            transition:border-color .18s, box-shadow .18s;
        }
        #editModal .form-control:focus, #editModal .form-select:focus {
            border-color:#0077b6; box-shadow:0 0 0 3px rgba(0,119,182,.12); outline:none;
        }
        #editModal textarea.form-control { resize:vertical; min-height:70px; }
        #editModal .payment-status-row { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1rem; }
        #editModal .payment-opt { display:none; }
        #editModal .payment-opt-label {
            display:inline-flex; align-items:center; gap:.45rem;
            padding:.45rem 1.05rem; border:1.5px solid #dce4ef;
            border-radius:24px; font-size:.79rem; font-weight:600;
            cursor:pointer; user-select:none; line-height:1.4;
            transition:background .15s, border-color .15s, transform .1s; color:#6c7d95;
        }
        #editModal .payment-opt-label:hover { transform:translateY(-1px); }
        #editModal .payment-opt-label.paid    { color:#0d6832; }
        #editModal .payment-opt-label.pending { color:#7a4a00; }
        #editModal .payment-opt-label.pn      { color:#6f42c1; }
        #editModal .payment-opt-label.cancel  { color:#922b21; }
        #editModal .payment-opt-label.nq      { color:#555; }
        #editModal .payment-opt:checked + .payment-opt-label.paid    { background:#ecf9f2; border-color:#198754; }
        #editModal .payment-opt:checked + .payment-opt-label.pending { background:#fff3e0; border-color:#e07b00; }
        #editModal .payment-opt:checked + .payment-opt-label.pn      { background:#f3ebff; border-color:#6f42c1; }
        #editModal .payment-opt:checked + .payment-opt-label.cancel  { background:#fdecea; border-color:#dc3545; }
        #editModal .payment-opt:checked + .payment-opt-label.nq      { background:#f4f4f4; border-color:#6c757d; }
        #editOrFieldsGroup {
            overflow:hidden; max-height:999px; opacity:1;
            transition:opacity .25s ease, max-height .3s ease;
        }
        #editOrFieldsGroup.hidden { max-height:0; opacity:0; pointer-events:none; }
        #editPrimeHrmGroup.hidden { display:none; }
        #editModal .field-hint {
            font-size:.69rem; color:#a0aab5; margin-top:.3rem;
            display:flex; align-items:center; gap:.3rem;
        }
        #editModal .invalid-feedback { font-size:.73rem; color:#dc3545; margin-top:.3rem; }
    </style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-green"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <h1>Proof of Payment</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index?location=<?php echo htmlspecialchars($location); ?>">Home</a></li>
                            <li class="breadcrumb-item"><a href="index_trainings?location=<?php echo htmlspecialchars($location); ?>">Training List</a></li>
                            <li class="breadcrumb-item"><a href="participants.php?id=<?php echo urlencode($trainingId); ?>&location=<?php echo urlencode($location); ?>">Participants</a></li>
                            <li class="breadcrumb-item cell-ellipsis" style="max-width:240px;"><?php echo htmlspecialchars($training['name']); ?></li>
                            <li class="breadcrumb-item active"><?php echo $displayDate; ?></li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="participants.php?id=<?php echo urlencode($trainingId); ?>&location=<?php echo urlencode($location); ?>"
               class="otrs-btn-back">
                <i class="bi bi-arrow-left-circle"></i> Go Back
            </a>
        </div>
    </div>

    <section class="section">
        <div class="otrs-card">
            <div class="otrs-card-body">

                <!-- ── TRAINING INFO STRIP ── -->
                <div class="training-strip">
                    <div class="strip-label">Training Details</div>
                    <div class="strip-title"><?php echo htmlspecialchars($training['name']); ?></div>
                    <div class="strip-meta">
                        <span class="strip-meta-item"><i class="bi bi-calendar3"></i> <?php echo $displayDate; ?></span>
                        <span class="strip-meta-item"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($training['location'] ?? '—'); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-display"></i> <?php echo htmlspecialchars($training['mode']); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-people"></i> <?php echo htmlspecialchars($training['slots']); ?> slots</span>
                        <span class="strip-meta-item"><i class="bi bi-cash"></i> ₱<?php echo htmlspecialchars($training['fee']); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-building"></i> <?php echo htmlspecialchars($locationText); ?></span>
                    </div>
                </div>

                <!-- ── TOOLBAR ── -->
                <p class="section-label"><i class="bi bi-sliders me-1"></i> Actions &amp; Filters</p>

                <div class="toolbar">
                    <button id="exportButton" class="btn-f btn-f-success">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>

                    <div class="toolbar-divider"></div>

                    <button class="btn-f btn-f-primary"
                            data-bs-toggle="modal" data-bs-target="#uploadProofModal">
                        <i class="bi bi-upload"></i> Upload Proof
                    </button>

                    <div class="toolbar-spacer"></div>

                    <div class="d-flex align-items-center gap-2">
                        <label for="filterSelect" class="fw-bold mb-0"
                               style="font-size:.78rem;color:#012970;white-space:nowrap;">
                            <i class="bi bi-funnel me-1 text-primary"></i>Filter:
                        </label>
                        <select id="filterSelect" class="filter-select"
                                onchange="applyFilter(this.value)">
                            <?php
                            foreach (['All','paid','pending','promissory note','cancelled','not qualified'] as $opt) {
                                $sel = ($filter === $opt) ? 'selected' : '';
                                echo "<option value='$opt' $sel>$opt</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <!-- ── SUMMARY PILLS ── -->
                <div class="summary-pills">
                    <span class="summary-pill pill-paid">
                        <i class="bi bi-check-circle-fill"></i>
                        Paid: <span id="pillCount-paid"><?php echo $counts['paid']; ?></span>
                    </span>
                    <span class="summary-pill pill-pending">
                        <i class="bi bi-hourglass-split"></i>
                        Pending: <span id="pillCount-pending"><?php echo $counts['pending']; ?></span>
                    </span>
                    <span class="summary-pill pill-pn">
                        <i class="bi bi-file-text"></i>
                        Promissory Note: <span id="pillCount-pn"><?php echo $counts['promissory note']; ?></span>
                    </span>
                    <span class="summary-pill pill-cancelled">
                        <i class="bi bi-x-circle"></i>
                        Cancelled: <span id="pillCount-cancelled"><?php echo $counts['cancelled']; ?></span>
                    </span>
                    <span class="summary-pill pill-total">
                        <i class="bi bi-people"></i>
                        Total: <span id="pillCount-total"><?php echo array_sum($counts); ?></span>
                    </span>
                </div>

                <!-- ── TABLE ── -->
                <p class="section-label mt-3"><i class="bi bi-table me-1"></i> Participant Payment Records</p>

                <div id="tableContainer" class="table-container">
                    <div class="loading-overlay d-none">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading…</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="proofTable"
                               class="table table-bordered table-hover table-striped otrs-table w-100">
                            <thead>
                                <tr>
                                    <th class="text-center align-middle">#</th>
                                    <th class="text-center align-middle">Name</th>
                                    <th class="text-center align-middle">Position</th>
                                    <th class="text-center align-middle">SG</th>
                                    <th class="text-center align-middle">Agency</th>
                                    <th class="text-center align-middle">FO</th>
                                    <th class="text-center align-middle">Status</th>
                                    <th class="text-center align-middle">OR No.</th>
                                    <th class="text-center align-middle">OR Date</th>
                                    <th class="text-center align-middle">Collecting Officer</th>
                                    <th class="text-center align-middle">Remarks</th>
                                    <th class="text-center align-middle">Proof File</th>
                                    <th class="text-center align-middle">Log</th>
                                    <th class="text-center align-middle">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $count = 0;
                                foreach ($participants as $p):
                                    $count++;
                                    $mi       = !empty($p['mname'])  ? htmlspecialchars($p['mname'][0])  . '. ' : '';
                                    $sfx      = !empty($p['suffix']) ? ', ' . htmlspecialchars($p['suffix']) : '';
                                    $fullName = htmlspecialchars($p['fname']) . ' ' . $mi . htmlspecialchars($p['lname']) . $sfx;

                                    $statusMap = [
                                        'pending'         => 'ts-upcoming',
                                        'paid'            => 'ts-completed',
                                        'promissory note' => 'ts-ongoing',
                                        'cancelled'       => 'ts-cancelled',
                                        'not qualified'   => 'ts-cancelled',
                                        'free'            => 'ts-upcoming',
                                    ];
                                    $badgeClass = $statusMap[strtolower(trim($p['status']))] ?? 'ts-default';

                                    $orDateDisplay = '';
                                    if (!empty($p['ordate']) && $p['ordate'] != '0000-00-00') {
                                        try { $orDateDisplay = (new DateTime($p['ordate']))->format('M j, Y'); }
                                        catch (Exception $e) { $orDateDisplay = htmlspecialchars($p['ordate']); }
                                    }

                                    $hasFile = !empty($p['proof_file'])
                                               && trim($p['proof_file']) !== ''
                                               && trim($p['proof_file']) !== 'outputs/';
                                ?>
                                <tr>
                                    <td class="text-center align-middle"><?php echo $count; ?></td>
                                    <td class="align-middle"><?php echo $fullName; ?></td>
                                    <td class="align-middle"><?php echo htmlspecialchars($p['positiontitle']); ?></td>
                                    <td class="text-center align-middle"><?php echo htmlspecialchars($p['salary_grade'] ?? ''); ?></td>
                                    <td class="align-middle"><?php echo htmlspecialchars($p['agency_name']); ?></td>
                                    <td class="text-center align-middle"><?php echo htmlspecialchars($p['location']); ?></td>
                                    <td class="text-center align-middle">
                                        <span class="ts-badge <?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars(ucwords(strtolower(trim($p['status'])))); ?>
                                        </span>
                                    </td>
                                    <td class="text-center align-middle"><?php echo htmlspecialchars($p['orno'] ?? ''); ?></td>
                                    <td class="text-center align-middle"><?php echo $orDateDisplay; ?></td>
                                    <td class="text-center align-middle"><?php echo htmlspecialchars($p['collecting_officer'] ?? ''); ?></td>
                                    <td class="align-middle"><?php echo htmlspecialchars($p['remarks'] ?? ''); ?></td>

                                    <!-- Proof file cell -->
                                    <td class="text-center align-middle">
                                        <?php if ($hasFile): ?>
                                            <span class="proof-file-badge has-file">
                                                <i class="bi bi-paperclip"></i> Attached
                                            </span>
                                        <?php else: ?>
                                            <span class="proof-file-badge no-file">
                                                <i class="bi bi-dash"></i> None
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Log cell -->
                                    <td class="text-center align-middle">
                                        <button class="btn-pay-log pay-log-btn"
                                                data-id="<?php echo $p['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>"
                                                title="View activity log">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                    </td>

                                    <!-- Action cell -->
                                    <td class="text-center align-middle" style="white-space:nowrap;">
                                        <button class="row-action-btn btn-edit edit-btn me-1"
                                                data-id="<?php echo $p['id']; ?>"
                                                data-status="<?php echo htmlspecialchars($p['status'], ENT_QUOTES); ?>"
                                                data-orno="<?php echo htmlspecialchars($p['orno'] ?? '', ENT_QUOTES); ?>"
                                                data-ordate="<?php echo htmlspecialchars($p['ordate'] ?? '', ENT_QUOTES); ?>"
                                                data-officer="<?php echo htmlspecialchars($p['collecting_officer'] ?? '', ENT_QUOTES); ?>"
                                                data-prime="<?php echo !empty($p['prime_hrm_discount']) ? '1' : '0'; ?>"
                                                data-remarks="<?php echo htmlspecialchars($p['remarks'] ?? '', ENT_QUOTES); ?>"
                                                data-location="<?php echo htmlspecialchars($p['location'] ?? '', ENT_QUOTES); ?>"
                                                title="Edit payment details">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <?php if ($hasFile): ?>
                                            <a href="api/view_proof_payment.php?id=<?php echo $p['id']; ?>" target="_blank"
                                               class="row-action-btn btn-view me-1" title="View proof file">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="api/download_proof_payment.php?id=<?php echo $p['id']; ?>"
                                               class="row-action-btn btn-dl me-1" title="Download">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <button class="row-action-btn btn-delete delete-btn"
                                                    data-id="<?php echo $p['id']; ?>"
                                                    title="Delete proof file">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div><!-- /.table-container -->

            </div><!-- /.otrs-card-body -->
        </div><!-- /.otrs-card -->
    </section>

</main>

<?php imis_include('footer_db') ?>

<!-- ══════════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════════ -->

<!-- Edit Payment Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header modal-header-blue">
                <div class="modal-title-wrap">
                    <div class="modal-avatar"><i class="bi bi-cash-coin"></i></div>
                    <div class="modal-title-text">
                        <h6>Edit Payment Details</h6>
                        <small>Update payment status and official receipt</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="edit_regId">
                <input type="hidden" id="edit_location">

                <div class="form-section-label" style="margin-top:0">
                    <i class="bi bi-cash-coin"></i> Payment Status
                </div>

                <div id="edit_statusAlert" class="status-alert d-none">
                    <i class="bi bi-info-circle-fill"></i>
                    <span id="edit_statusAlertText"></span>
                </div>

                <div class="payment-status-row">
                    <input type="radio" class="payment-opt" id="edit_opt_pending" name="edit_statusRadio" value="pending">
                    <label for="edit_opt_pending" class="payment-opt-label pending">
                        <i class="bi bi-hourglass-split"></i> Pending
                    </label>
                    <input type="radio" class="payment-opt" id="edit_opt_paid" name="edit_statusRadio" value="paid">
                    <label for="edit_opt_paid" class="payment-opt-label paid">
                        <i class="bi bi-check-circle-fill"></i> Paid
                    </label>
                    <input type="radio" class="payment-opt" id="edit_opt_pn" name="edit_statusRadio" value="promissory note">
                    <label for="edit_opt_pn" class="payment-opt-label pn">
                        <i class="bi bi-journal-text"></i> Promissory Note
                    </label>
                    <input type="radio" class="payment-opt" id="edit_opt_cancelled" name="edit_statusRadio" value="cancelled">
                    <label for="edit_opt_cancelled" class="payment-opt-label cancel">
                        <i class="bi bi-x-circle-fill"></i> Cancelled
                    </label>
                    <input type="radio" class="payment-opt" id="edit_opt_nq" name="edit_statusRadio" value="not qualified">
                    <label for="edit_opt_nq" class="payment-opt-label nq">
                        <i class="bi bi-slash-circle"></i> Not Qualified
                    </label>
                </div>

                <div id="editPrimeHrmGroup" class="mb-3 hidden">
                    <div class="p-2 rounded" style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1.5px solid #f59e0b;">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="edit_prime_hrm" name="prime_hrm_discount" value="1"
                                   style="width:2em;height:1.1em;">
                            <label class="form-check-label fw-semibold" for="edit_prime_hrm" style="font-size:.84rem;">
                                <i class="bi bi-award-fill text-warning me-1"></i> PRIME-HRM 20% Discount Applied
                                <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;vertical-align:middle;">PRIME-HRM</span>
                            </label>
                        </div>
                        <div style="font-size:.71rem;color:#92400e;margin-top:3px;margin-left:2.5em;">
                            Agency is PRIME-HRM accredited and availed the 20% registration fee discount.
                        </div>
                    </div>
                </div>

                <div id="editOrFieldsGroup" class="hidden">
                    <div class="form-section-label">
                        <i class="bi bi-receipt"></i> Official Receipt Details
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-5">
                            <label class="form-label">OR Number <span class="req">*</span></label>
                            <input type="text" class="form-control" id="edit_orno" name="orno"
                                   placeholder="OR-XXXXXX" maxlength="20">
                            <div class="invalid-feedback">OR Number is required.</div>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label">OR Date <span class="req">*</span></label>
                            <input type="date" class="form-control" id="edit_ordate" name="ordate">
                            <div class="invalid-feedback">OR Date is required.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Collecting Officer</label>
                            <select class="form-select" id="edit_collecting_officer" name="collecting_officer">
                                <option value="">— Select Officer —</option>
                            </select>
                            <div class="field-hint">
                                <i class="bi bi-info-circle"></i> Officers are filtered by the participant's Field Office.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section-label">
                    <i class="bi bi-chat-left-text"></i> Remarks
                </div>
                <textarea class="form-control" id="edit_remarks" name="remarks"
                          rows="2" placeholder="Optional notes…"></textarea>

            </div>
            <div class="modal-footer-branded">
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Close
                </button>
                <button type="button" class="btn-modal-save" id="saveChanges">
                    <i class="bi bi-save me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Upload Proof Modal -->
<div class="modal fade" id="uploadProofModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header modal-header-blue">
                <div class="modal-title-wrap">
                    <div class="modal-avatar"><i class="bi bi-upload"></i></div>
                    <div class="modal-title-text">
                        <h6>Upload Proof of Payment</h6>
                        <small>Attach a payment receipt for a participant</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="uploadProofForm" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Participant</label>
                        <select class="form-select" id="uploadParticipantId" name="participantId">
                            <?php foreach ($modalParticipants as $mp):
                                $mi_u  = !empty($mp['mname'])  ? ' ' . $mp['mname'][0] . '.' : '';
                                $sfx_u = !empty($mp['suffix']) ? ', ' . $mp['suffix']        : '';
                                $n = htmlspecialchars($mp['lname'] . ', ' . $mp['fname'] . $mi_u . $sfx_u, ENT_QUOTES);
                            ?>
                                <option value="<?php echo $mp['id']; ?>"><?php echo $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold">Proof File <span class="text-muted fw-normal">(PDF or Image)</span></label>
                        <input type="file" class="form-control" id="proofFile" name="proofFile"
                               accept="application/pdf,image/*">
                    </div>
                </form>
            </div>
            <div class="modal-footer-branded">
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal"><i class="bi bi-x me-1"></i>Cancel</button>
                <button type="button" class="btn-modal-save" id="confirmUpload">
                    <i class="bi bi-upload me-1"></i>Upload
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Registration & Payment Log Modal -->
<div class="modal fade" id="payLogModal" tabindex="-1" aria-labelledby="payLogModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header modal-header-blue">
                <div class="modal-title-wrap">
                    <div class="modal-avatar" id="payLogAvatar"><i class="bi bi-clock-history"></i></div>
                    <div class="modal-title-text">
                        <h6 id="payLogName" class="mb-0">Loading…</h6>
                        <small id="payLogMeta">Registration &amp; Payment Log</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="payLogBody" style="min-height:140px;">
                <div class="text-center text-muted py-4">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    Loading log…
                </div>
            </div>
            <div class="modal-footer modal-footer-branded">
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

<a href="#" class="back-to-top d-flex align-items-center justify-content-center">
    <i class="bi bi-arrow-up-short"></i>
</a>

<!-- ── JS ────────────────────────────────────────────────── -->
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>

<script>
$(function () {

    /* ── DataTable ── */
    const table = $('#proofTable').DataTable({
        paging:     true,
        searching:  true,
        ordering:   true,
        order:      [[0, 'asc']],
        lengthMenu: [10, 25, 50, 100],
        dom: "<'row'<'col-sm-6'l><'col-sm-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-5'i><'col-sm-7'p>>",
        buttons: [{
            extend:        'excel',
            text:          '<i class="bi bi-file-earmark-excel"></i> Export to Excel',
            title:         () => 'ProofOfPayment_<?php echo (int)$trainingId; ?>_' + new Date().toISOString().slice(0, 10),
            exportOptions: { columns: ':not(:nth-last-child(-n+2))' }
        }]
    });

    table.buttons().container().hide();
    $('#exportButton').on('click', () => table.button('.buttons-excel').trigger());

    /* ── Toast helper ── */
    const toast = (icon, title) => Swal.fire({
        icon, title, toast: true, position: 'top-end',
        showConfirmButton: false, timer: 2800
    });

    /* ── Summary pill live-update ── */
    let _editOldStatus = '';
    function adjustPillCounts(oldStatus, newStatus) {
        oldStatus = (oldStatus || '').toLowerCase().trim();
        newStatus = (newStatus || '').toLowerCase().trim();
        if (!oldStatus || !newStatus || oldStatus === newStatus) return;
        const map = {
            'paid':            'pillCount-paid',
            'pending':         'pillCount-pending',
            'promissory note': 'pillCount-pn',
            'cancelled':       'pillCount-cancelled',
        };
        function adj(key, delta) {
            const el = document.getElementById(map[key]);
            if (!el) return;
            el.textContent = Math.max(0, parseInt(el.textContent || '0') + delta);
        }
        adj(oldStatus, -1);
        adj(newStatus, +1);
    }

    /* ── Status UI sync ── */
    const EDIT_STATUS_CFG = {
        'paid':            { cls: 'paid',    icon: 'bi-check-circle-fill', alert: 'Paid — OR details required below.' },
        'pending':         { cls: 'pending', icon: 'bi-hourglass-split',   alert: 'Pending — waiting for payment.' },
        'promissory note': { cls: 'pn',      icon: 'bi-journal-text',      alert: 'Promissory Note — attach proof when available.' },
        'cancelled':       { cls: 'cancel',  icon: 'bi-x-circle-fill',     alert: 'Cancelled — slot will be returned.' },
        'not qualified':   { cls: 'nq',      icon: 'bi-slash-circle',      alert: 'Not Qualified — registration denied.' },
    };

    function syncEditStatusUI(value) {
        const cfg = EDIT_STATUS_CFG[value] || {};
        const $alert = $('#edit_statusAlert');
        if (cfg.alert) {
            $alert.removeClass().addClass('status-alert ' + cfg.cls);
            $('#edit_statusAlertText').html(`<i class="bi ${cfg.icon} me-1"></i> ${cfg.alert}`);
        } else {
            $alert.removeClass().addClass('status-alert d-none');
        }
        const isPaid = (value === 'paid');
        if (isPaid) {
            $('#editOrFieldsGroup').removeClass('hidden');
            $('#editPrimeHrmGroup').removeClass('hidden');
            if (!$('#edit_collecting_officer option:not([value=""])').length) {
                loadEditCollectingOfficers();
            }
        } else {
            $('#editOrFieldsGroup').addClass('hidden');
            $('#editPrimeHrmGroup').addClass('hidden');
            $('#edit_prime_hrm').prop('checked', false);
        }
    }

    $('input[name="edit_statusRadio"]').on('change', function () {
        syncEditStatusUI($(this).val());
    });

    /* ── Collecting officers ── */
    let editCoLoaded = false;
    function loadEditCollectingOfficers() {
        if (editCoLoaded) return;
        const loc = $('#edit_location').val() || '';
        $.ajax({
            url: 'api/fetch_co_dropdown.php', method: 'POST',
            data: { location: loc, _csrf: window.csrfToken ?? '' },
            headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
            dataType: 'json',
            success(res) {
                if (!Array.isArray(res)) return;
                const $sel = $('#edit_collecting_officer');
                const current = $sel.val();
                $sel.html('<option value="">— Select Officer —</option>');
                res.forEach(co => $sel.append(`<option value="${co.name}">${co.name}</option>`));
                if (current) $sel.val(current);
                editCoLoaded = true;
            }
        });
    }

    /* ── Edit modal populate ── */
    $(document).on('click', '.edit-btn', function () {
        const $btn = $(this);
        editCoLoaded = false;
        $('#edit_regId').val($btn.data('id'));
        $('#edit_location').val($btn.data('location') || '');
        $('#edit_orno').val($btn.data('orno') || '');
        $('#edit_ordate').val($btn.data('ordate') || '');
        $('#edit_remarks').val($btn.data('remarks') || '');
        $('#edit_prime_hrm').prop('checked', $btn.data('prime') == '1');

        const status = $btn.data('status') || 'pending';
        _editOldStatus = status;
        $(`input[name="edit_statusRadio"][value="${status}"]`).prop('checked', true);
        syncEditStatusUI(status);

        if (status === 'paid') {
            const officer = $btn.data('officer') || '';
            loadEditCollectingOfficers();
            setTimeout(() => { if (officer) $('#edit_collecting_officer').val(officer); }, 350);
        }

        $('#editModal').modal('show');
    });

    /* ── Reset on close ── */
    $('#editModal').on('hidden.bs.modal', function () {
        $('input[name="edit_statusRadio"]').prop('checked', false);
        $('#edit_statusAlert').removeClass().addClass('status-alert d-none');
        $('#editOrFieldsGroup').addClass('hidden');
        $('#editPrimeHrmGroup').addClass('hidden');
        $('#edit_prime_hrm').prop('checked', false);
    });

    /* ── Save changes ── */
    $('#saveChanges').on('click', function () {
        const id     = $('#edit_regId').val();
        const status = $('input[name="edit_statusRadio"]:checked').val() || '';
        const orno   = $('#edit_orno').val().trim();
        const ordate = $('#edit_ordate').val();
        const officer = $('#edit_collecting_officer').val();
        const prime  = $('#edit_prime_hrm').is(':checked') ? 1 : 0;
        const remarks = $('#edit_remarks').val().trim();

        if (status === 'paid' && !orno) {
            return Swal.fire('OR No. Required',
                'Please enter the OR No. before marking this participant as <b>Paid</b>.', 'warning');
        }

        const doSave = () => {
            $.ajax({
                url: 'api/update_proof_payment.php', method: 'POST',
                data: { id, status, orno, ordate, collecting_officer: officer, prime_hrm_discount: prime, remarks, _csrf: window.csrfToken ?? '' },
                headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
                dataType: 'json',
                success(res) {
                    if (res.success) {
                        adjustPillCounts(_editOldStatus, status);
                        $('#editModal').modal('hide');
                        syncRow(id, orno, ordate, officer, prime, remarks, status);
                        toast('success', 'Updated successfully');
                    } else {
                        toast('error', 'Error: ' + res.message);
                    }
                },
                error() { toast('error', 'An error occurred. Please try again.'); }
            });
        };

        if (status === 'cancelled') {
            Swal.fire({
                title: 'Cancel Registration?',
                text:  "This will mark the participant's registration as cancelled.",
                icon:  'warning', showCancelButton: true,
                confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Cancel'
            }).then(r => { if (r.isConfirmed) doSave(); });
        } else {
            doSave();
        }
    });

    /* ── Format ISO date for table display ── */
    function formatOrDate(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[+parts[1] - 1] + ' ' + +parts[2] + ', ' + parts[0];
    }

    /* ── Sync row in-place (no page reload) ── */
    function syncRow(id, orno, ordate, officer, prime, remarks, status) {
        const rowNode = table.row(function (i, d, node) {
            return $(node).find('.edit-btn').data('id') == id;
        }).node();
        if (!rowNode) return;

        const statusBadgeMap = {
            'pending':         'ts-upcoming',
            'paid':            'ts-completed',
            'promissory note': 'ts-ongoing',
            'cancelled':       'ts-cancelled',
            'not qualified':   'ts-cancelled',
            'free':            'ts-upcoming',
        };
        const badgeClass  = statusBadgeMap[status.toLowerCase()] || 'ts-default';
        const statusLabel = status.replace(/\b\w/g, c => c.toUpperCase());
        $(rowNode).find('td').eq(6).html(`<span class="ts-badge ${badgeClass}">${statusLabel}</span>`);
        $(rowNode).find('td').eq(7).text(orno);
        $(rowNode).find('td').eq(8).text(formatOrDate(ordate));
        $(rowNode).find('td').eq(9).text(officer);
        $(rowNode).find('td').eq(10).text(remarks);
        $(rowNode).find('.edit-btn').data({ orno, ordate, officer, prime, remarks, status });
        table.row(rowNode).invalidate().draw(false);
    }

    /* ── Delete proof file ── */
    $(document).on('click', '.delete-btn', function () {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Delete proof file?',
            text:  'This action cannot be undone.',
            icon:  'warning', showCancelButton: true,
            confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
        }).then(r => {
            if (!r.isConfirmed) return;
            $.ajax({
                url: 'api/delete_proof_payment.php', type: 'POST',
                data: { id, _csrf: window.csrfToken ?? '' },
                headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
                success(raw) {
                    const res = typeof raw === 'string' ? JSON.parse(raw) : raw;
                    if (res.success) {
                        clearProofCell(id);
                        toast('success', 'Proof file deleted');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error() { Swal.fire('Error', 'Failed to delete the proof file.', 'error'); }
            });
        });
    });

    function clearProofCell(id) {
        const rowNode = table.row(function (i, d, node) {
            return $(node).find('.edit-btn').data('id') == id;
        }).node();
        if (!rowNode) return;

        // Replace "Attached" badge with "None"
        $(rowNode).find('td').eq(11).html(
            '<span class="proof-file-badge no-file"><i class="bi bi-dash"></i> None</span>'
        );
        // Remove view/download/delete buttons, keep only edit
        const editBtn = $(rowNode).find('.edit-btn')[0].outerHTML;
        $(rowNode).find('td').eq(13).html(editBtn);
        table.row(rowNode).invalidate().draw(false);
    }

    /* ── Upload proof ── */
    $('#confirmUpload').on('click', function () {
        if (!$('#proofFile')[0].files[0]) {
            return Swal.fire('No file selected', 'Please choose a PDF or image to upload.', 'warning');
        }
        const fd = new FormData($('#uploadProofForm')[0]);
        fd.append('_csrf', window.csrfToken ?? '');
        $.ajax({
            url: 'upload_proof_payment.php', type: 'POST',
            data: fd, processData: false, contentType: false,
            headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
            success(res) {
                if (res.success) {
                    $('#uploadProofModal').modal('hide');
                    toast('success', 'Proof file uploaded');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    Swal.fire('Error', res.message || 'Upload failed.', 'error');
                }
            },
            error() { Swal.fire('Error', 'Upload request failed.', 'error'); }
        });
    });

    /* ── Filter ── */
    window.applyFilter = function (value) {
        const url = new URL(window.location.href);
        url.searchParams.set('filter', value);
        window.location.href = url.toString();
    };

    /* ── Bootstrap tooltips ── */
    $('[data-bs-toggle="tooltip"]').tooltip();

    /* ── Registration & Payment Log ── */
    $(document).on('click', '.pay-log-btn', function () {
        const regId = $(this).data('id');
        const name  = $(this).data('name') || 'Participant';
        $('#payLogName').text(name);
        $('#payLogMeta').text('Enrollment #' + regId + ' · Registration & Payment Log');
        $('#payLogBody').html(
            '<div class="text-center text-muted py-4">' +
            '<div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading log…</div>'
        );
        $('#payLogModal').modal('show');

        $.ajax({
            url: 'api/fetch_reg_payment_log.php', method: 'POST',
            data: { enrollment_id: regId, _csrf: window.csrfToken ?? '' },
            headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
            dataType: 'json',
            success(res) {
                if (res.status !== 'success') {
                    $('#payLogBody').html('<div class="alert alert-danger">Failed to load log.</div>');
                    return;
                }
                $('#payLogBody').html(buildPayLogTimeline(res));
            },
            error() {
                $('#payLogBody').html('<div class="alert alert-danger">Error loading log.</div>');
            }
        });
    });

    function buildPayLogTimeline(res) {
        const esc = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const fmtDate = s => {
            if (!s) return '—';
            const d = new Date(s);
            if (isNaN(d)) return s;
            return d.toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' })
                 + ' ' + d.toLocaleTimeString('en-PH', { hour:'numeric', minute:'2-digit', hour12:true });
        };
        const statusCls = s => {
            const m = { paid:'paid', pending:'pending', free:'free', cancelled:'cancel',
                        'promissory note':'pn', 'not qualified':'other' };
            return m[(s||'').toLowerCase()] || 'other';
        };
        const statusIcon = s => {
            const m = { paid:'bi-check-circle-fill', pending:'bi-hourglass-split', free:'bi-gift',
                        cancelled:'bi-x-circle-fill', 'promissory note':'bi-journal-text',
                        'not qualified':'bi-slash-circle' };
            return m[(s||'').toLowerCase()] || 'bi-circle';
        };
        const badge = s => {
            if (!s) return '';
            const cls = statusCls(s);
            return `<span class="plt-badge ${cls}">${esc(s)}</span>`;
        };

        let html = '<ul class="pay-log-timeline list-unstyled mb-0">';

        html += `<li class="plt-item">
            <div class="plt-dot reg"><i class="bi bi-person-check-fill"></i></div>
            <div class="plt-body">
                <div class="plt-title">Participant Registered</div>
                <div class="plt-meta">
                    <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(res.registered_at)}</span>
                    <span class="plt-meta-item"><i class="bi bi-tag"></i>Initial status: ${badge(res.initial_status)}</span>
                </div>
            </div>
        </li>`;

        if (!res.logs || res.logs.length === 0) {
            html += `<li class="plt-item" style="padding-bottom:0;">
                <div class="plt-dot other"><i class="bi bi-info-circle"></i></div>
                <div class="plt-body">
                    <div class="plt-no-logs"><i class="bi bi-info-circle-fill"></i>No activity has been recorded for this enrollment.</div>
                </div>
            </li>`;
        } else {
            res.logs.forEach(log => {
                const type = log.event_type || 'payment';
                if (type === 'payment') {
                    const sc = statusCls(log.new_status);
                    const orRow = (log.or_no || log.or_date || log.collecting_officer) ? `
                        <div class="plt-meta mt-1">
                            ${log.or_no   ? `<span class="plt-meta-item"><i class="bi bi-receipt"></i>OR No.: <strong>${esc(log.or_no)}</strong></span>` : ''}
                            ${log.or_date ? `<span class="plt-meta-item"><i class="bi bi-calendar-check"></i>OR Date: <strong>${esc(log.or_date)}</strong></span>` : ''}
                            ${log.collecting_officer ? `<span class="plt-meta-item"><i class="bi bi-person-badge"></i>${esc(log.collecting_officer)}</span>` : ''}
                        </div>` : '';
                    html += `<li class="plt-item">
                        <div class="plt-dot ${sc}"><i class="bi ${statusIcon(log.new_status)}"></i></div>
                        <div class="plt-body">
                            <div class="plt-title">${esc(log.action)}</div>
                            <div class="plt-meta">
                                <span class="plt-meta-item">${badge(log.old_status)}<i class="bi bi-arrow-right" style="color:#94a3b8;font-size:.8rem;"></i>${badge(log.new_status)}</span>
                            </div>
                            ${orRow}
                            <div class="plt-meta mt-2">
                                <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                            </div>
                        </div>
                    </li>`;
                } else if (type === 'cancellation') {
                    html += `<li class="plt-item">
                        <div class="plt-dot cancel-req"><i class="bi bi-send-exclamation-fill"></i></div>
                        <div class="plt-body">
                            <div class="plt-title">${esc(log.action)}</div>
                            ${log.remarks ? `<div class="plt-meta"><span class="plt-meta-item" style="font-style:italic;color:#92400e;">"${esc(log.remarks)}"</span></div>` : ''}
                            <div class="plt-meta mt-2">
                                <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                            </div>
                        </div>
                    </li>`;
                } else if (type === 'cancellation_review') {
                    const approved = (log.request_status || '').toLowerCase() === 'approved';
                    html += `<li class="plt-item">
                        <div class="plt-dot ${approved ? 'paid' : 'cancel'}"><i class="bi ${approved ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}"></i></div>
                        <div class="plt-body">
                            <div class="plt-title">${esc(log.action)}</div>
                            ${log.remarks ? `<div class="plt-meta"><span class="plt-meta-item" style="font-style:italic;">"${esc(log.remarks)}"</span></div>` : ''}
                            <div class="plt-meta mt-2">
                                <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                            </div>
                        </div>
                    </li>`;
                } else if (type === 'replacement') {
                    html += `<li class="plt-item">
                        <div class="plt-dot replace"><i class="bi bi-arrow-left-right"></i></div>
                        <div class="plt-body">
                            <div class="plt-title">${esc(log.action)}</div>
                            <div class="plt-meta"><span class="plt-meta-item">
                                <i class="bi bi-person-dash" style="color:#dc2626;"></i>${esc(log.original_name)}
                                <i class="bi bi-arrow-right" style="color:#94a3b8;font-size:.8rem;"></i>
                                <i class="bi bi-person-check" style="color:#16a34a;"></i>${esc(log.new_name)}
                            </span></div>
                            ${log.remarks ? `<div class="plt-meta"><span class="plt-meta-item" style="font-style:italic;">"${esc(log.remarks)}"</span></div>` : ''}
                            <div class="plt-meta mt-2">
                                <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                            </div>
                        </div>
                    </li>`;
                } else if (type === 'profile_update') {
                    html += `<li class="plt-item">
                        <div class="plt-dot other" style="background:#ede9fe;color:#7c3aed;border-color:#ddd6fe;"><i class="bi bi-pencil-square"></i></div>
                        <div class="plt-body">
                            <div class="plt-title">Participant Info Updated</div>
                            ${log.notes ? `<div class="plt-meta"><span class="plt-meta-item" style="color:#6b7280;font-size:.78rem;">${esc(log.notes)}</span></div>` : ''}
                            <div class="plt-meta mt-2">
                                <span class="plt-meta-item"><i class="bi bi-person-fill"></i>${esc(log.actor)}</span>
                                <span class="plt-meta-item"><i class="bi bi-calendar3"></i>${fmtDate(log.event_at)}</span>
                            </div>
                        </div>
                    </li>`;
                }
            });
        }
        html += '</ul>';
        return html;
    }
});
</script>
</body>
</html>