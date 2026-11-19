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
$trainingId  = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$trainingId) {
    die('<div class="alert alert-danger m-3">Invalid or missing training ID.</div>');
}

$locationMap = [
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
$locationText = $locationMap[$location] ?? strtoupper($location);

/* ── Training ──────────────────────────────────────────────────────────── */
try {
    $stmtT = $conn->prepare("
        SELECT id, name, start_date, end_date, location, mode
        FROM   otrs_trainings
        WHERE  id = :id
        LIMIT  1
    ");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[proof_payment.php] training query: ' . $e->getMessage());
    die('<div class="alert alert-danger m-3">Database error loading training.</div>');
}

if (!$training) die('<div class="alert alert-warning m-3">Training not found.</div>');

/* Build display date */
$displayDate = '';
if (!empty($training['start_date']) && $training['start_date'] !== '0000-00-00') {
    try {
        $s = new DateTimeImmutable($training['start_date']);
        $e = (!empty($training['end_date']) && $training['end_date'] !== '0000-00-00')
             ? new DateTimeImmutable($training['end_date']) : null;
        if (!$e || $s->format('Y-m-d') === $e->format('Y-m-d')) {
            $displayDate = $s->format('F j, Y');
        } elseif ($s->format('Y-m') === $e->format('Y-m')) {
            $displayDate = $s->format('F j') . '–' . $e->format('j, Y');
        } else {
            $displayDate = $s->format('F j') . ' – ' . $e->format('F j, Y');
        }
    } catch (Exception) {
        $displayDate = $training['start_date'];
    }
}

/* ── Participants ──────────────────────────────────────────────────────── */
try {
    $sql = "
        SELECT r.id          AS enrollment_id,
               p.fname, p.mname, p.lname, p.suffix,
               p.position_title, p.salary_grade, p.agency_name,
               p.location    AS fo,
               r.payment_status,
               r.or_no, r.or_date, r.collecting_officer,
               r.remarks, r.proof_file
        FROM   otrs_registrations r
        JOIN   otrs_participants  p ON p.id = r.participant_id
        WHERE  r.training_id = :tid
          AND  LOWER(r.payment_status) IN ('pending', 'promissory note', 'paid')
          AND  r.registration_status NOT IN ('cancelled', 'not qualified')
    ";
    $params = [':tid' => $trainingId];
    if ($location !== '') {
        $sql .= " AND UPPER(p.location) = UPPER(:location)";
        $params[':location'] = $location;
    }
    $sql .= " ORDER BY p.lname ASC, p.fname ASC";

    $stmtP = $conn->prepare($sql);
    $stmtP->execute($params);
    $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[proof_payment.php] participant query: ' . $e->getMessage());
    $participants = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="author" content="CSCRO8-ITG">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Proof of Payment – <?php echo htmlspecialchars($training['name']); ?></title>

    <link href="assets/img/favicon.png" rel="icon">
    <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
<link href="../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
    <link href="assets/css/field-office.css?v=<?php echo filemtime(__DIR__.'/assets/css/field-office.css'); ?>" rel="stylesheet">

    <style>
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
        #editModal .payment-opt:checked + .payment-opt-label.paid    { background:#ecf9f2; border-color:#198754; }
        #editModal .payment-opt:checked + .payment-opt-label.pending { background:#fff3e0; border-color:#e07b00; }
        #editModal .payment-opt:checked + .payment-opt-label.pn      { background:#f3ebff; border-color:#6f42c1; }
        #editOrFieldsGroup {
            overflow:hidden; max-height:999px; opacity:1;
            transition:opacity .25s ease, max-height .3s ease;
        }
        #editOrFieldsGroup.hidden { max-height:0; opacity:0; pointer-events:none; }
        #editModal .field-hint {
            font-size:.69rem; color:#a0aab5; margin-top:.3rem;
            display:flex; align-items:center; gap:.3rem;
        }
        #editModal .invalid-feedback { font-size:.73rem; color:#dc3545; margin-top:.3rem; }

        /* ── Row action buttons ──────────────────────────────── */
        .row-action-btn {
            font-size:.7rem; padding:.15rem .4rem; line-height:1.45;
            white-space:nowrap; border:none; border-radius:4px; color:#fff;
            cursor:pointer; display:inline-flex; align-items:center; gap:.22rem;
            transition:filter .12s; text-decoration:none;
        }
        .row-action-btn:hover  { filter:brightness(.85); color:#fff; }
        .row-action-btn:active { filter:brightness(.75); color:#fff; }
        .row-action-btn.btn-edit { background:#0077b6; }
        .row-action-btn.btn-view { background:#198754; }
        .row-action-btn.btn-dl   { background:#6c757d; }

        /* ── Activity log button & timeline ── */
        .btn-pay-log {
            background:linear-gradient(135deg,#0f4c81,#1a6baa);
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
                            <li class="breadcrumb-item"><a href="index?location=<?php echo urlencode($location); ?>">Home</a></li>
                            <li class="breadcrumb-item"><?php echo strtoupper(htmlspecialchars($_SESSION['type'] ?? 'FO')); ?></li>
                            <li class="breadcrumb-item cell-ellipsis" style="max-width:280px;"><?php echo htmlspecialchars($training['name']); ?></li>
                            <li class="breadcrumb-item active">Proof of Payment</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="index?location=<?php echo urlencode($location); ?>" class="otrs-btn-back">
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
                        <span class="strip-meta-item"><i class="bi bi-calendar3"></i> <?php echo $displayDate; ?></span>
                        <span class="strip-meta-item"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($training['location'] ?? '—'); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-display"></i> <?php echo htmlspecialchars($training['mode']); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-building"></i> <?php echo htmlspecialchars($locationText); ?></span>
                    </div>
                </div>

                <!-- ── TOOLBAR ── -->
                <p class="section-label"><i class="bi bi-sliders me-1"></i> Actions</p>
                <div class="toolbar">
                    <button id="exportButton" class="btn-f btn-f-success">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                </div>

                <!-- ── TABLE ── -->
                <p class="section-label mt-3"><i class="bi bi-table me-1"></i> Proof of Payment Records</p>

                <?php if (count($participants) > 0): ?>
                    <div class="table-responsive">
                        <table id="participantsList"
                               class="table table-bordered table-hover table-striped otrs-table w-100">
                            <thead>
                                <tr>
                                    <th class="text-center align-middle">#</th>
                                    <th class="text-center align-middle">Name</th>
                                    <th class="text-center align-middle">Position Title</th>
                                    <th class="text-center align-middle">SG</th>
                                    <th class="text-center align-middle">Agency</th>
                                    <th class="text-center align-middle">FO</th>
                                    <th class="text-center align-middle">Status</th>
                                    <th class="text-center align-middle">OR No.</th>
                                    <th class="text-center align-middle">Remarks</th>
                                    <th class="text-center align-middle">Proof File</th>
                                    <th class="text-center align-middle">Log</th>
                                    <th class="text-center align-middle">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $count = 0;
                                foreach ($participants as $row):
                                    $count++;
                                    $enrollmentId = (int)$row['enrollment_id'];

                                    $mi       = !empty($row['mname'])  ? htmlspecialchars($row['mname'][0]) . '. ' : '';
                                    $sfx      = !empty($row['suffix']) ? ', ' . htmlspecialchars($row['suffix']) : '';
                                    $fullName = htmlspecialchars($row['fname']) . ' ' . $mi . htmlspecialchars($row['lname']) . $sfx;

                                    $payStatus  = $row['payment_status'];
                                    $badgeClass = match (strtolower($payStatus)) {
                                        'paid'            => 'ts-badge ts-completed',
                                        'promissory note' => 'ts-badge ts-ongoing',
                                        default           => 'ts-badge ts-upcoming',
                                    };

                                    $hasFile = !empty($row['proof_file']) && trim($row['proof_file']) !== 'outputs/';

                                    $orDateDisplay = '';
                                    if (!empty($row['or_date']) && $row['or_date'] !== '0000-00-00') {
                                        try { $orDateDisplay = (new DateTime($row['or_date']))->format('M j, Y'); }
                                        catch (Exception) {}
                                    }
                                ?>
                                <tr>
                                    <td class="text-center"><?php echo $count; ?></td>
                                    <td><?php echo $fullName; ?></td>
                                    <td><?php echo htmlspecialchars($row['position_title']); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($row['salary_grade'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['agency_name']); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($row['fo']); ?></td>
                                    <td class="text-center">
                                        <span class="<?php echo $badgeClass; ?>"><?php echo ucwords($payStatus); ?></span>
                                    </td>
                                    <td class="text-center"><?php echo htmlspecialchars($row['or_no'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td>
                                    <td class="text-center">
                                        <?php if ($hasFile): ?>
                                            <span class="ts-badge ts-completed">
                                                <i class="bi bi-paperclip me-1"></i>Attached
                                            </span>
                                        <?php else: ?>
                                            <span class="ts-badge ts-default">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn-pay-log pay-log-btn"
                                                data-id="<?php echo $enrollmentId; ?>"
                                                data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>"
                                                title="View activity log">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                    </td>
                                    <td class="text-center" style="white-space:nowrap;">
                                        <button class="row-action-btn btn-edit edit-btn me-1"
                                                data-id="<?php echo $enrollmentId; ?>"
                                                data-status="<?php echo htmlspecialchars(strtolower($payStatus), ENT_QUOTES); ?>"
                                                data-orno="<?php echo htmlspecialchars($row['or_no'] ?? '', ENT_QUOTES); ?>"
                                                data-ordate="<?php echo htmlspecialchars($row['or_date'] ?? '', ENT_QUOTES); ?>"
                                                data-remarks="<?php echo htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES); ?>"
                                                title="Edit payment details">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <?php if ($hasFile): ?>
                                            <a href="api/field-office/view_proof_payment.php?id=<?php echo $enrollmentId; ?>"
                                               target="_blank" class="row-action-btn btn-view me-1" title="View proof file">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="api/field-office/download_proof_payment.php?id=<?php echo $enrollmentId; ?>"
                                               class="row-action-btn btn-dl" title="Download">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="otrs-alert otrs-alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span>No participants found.</span>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </section>
</main>

<?php imis_include('footer_db') ?>

<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<!-- ══ EDIT MODAL ═══════════════════════════════════════════════════════ -->
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
                </div>

                <div id="editOrFieldsGroup" class="hidden">
                    <div class="form-section-label">
                        <i class="bi bi-receipt"></i> Official Receipt Details
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-5">
                            <label class="form-label">OR Number <span class="req">*</span></label>
                            <input type="text" class="form-control" id="edit_orno" placeholder="OR-XXXXXX" maxlength="20">
                            <div class="invalid-feedback">OR Number is required.</div>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label">OR Date</label>
                            <input type="date" class="form-control" id="edit_ordate">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Collecting Officer</label>
                            <select class="form-select" id="edit_collecting_officer">
                                <option value="">— Select Officer —</option>
                            </select>
                            <div class="field-hint">
                                <i class="bi bi-info-circle"></i> Officers filtered by your Field Office.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section-label">
                    <i class="bi bi-chat-left-text"></i> Remarks
                </div>
                <textarea class="form-control" id="edit_remarks" rows="2" placeholder="Optional notes…"></textarea>

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

<!-- ══ SCRIPTS ══════════════════════════════════════════════════════════ -->
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>
<script>
window.csrfToken = <?php echo json_encode($_SESSION['_csrf_token'] ?? ''); ?>;

const apiFetch = (url, opts = {}) => {
    const token = window.csrfToken ?? '';
    opts.headers = Object.assign({ 'X-CSRF-Token': token }, opts.headers ?? {});
    if (opts.body instanceof FormData) opts.body.append('_csrf', token);
    return fetch(url, opts);
};

$(function () {

    /* ── DataTable ── */
    const table = $('#participantsList').DataTable({
        scrollX:     true,
        fixedHeader: { headerOffset: 50 },
        order:       [[0, 'asc']],
        lengthMenu:  [10, 25, 50, 100],
        buttons: [{ extend: 'excel', filename: <?php echo json_encode($training['name'] . '_proof_' . date('m-d-Y')); ?> }],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
    });
    table.buttons().container().hide();
    $('#exportButton').on('click', () => table.button('.buttons-excel').trigger());

    /* ── Toast helper ── */
    const toast = (icon, title) => Swal.fire({
        icon, title, toast: true, position: 'top-end',
        showConfirmButton: false, timer: 2500
    });

    /* ── Status UI sync ── */
    const STATUS_CFG = {
        'paid':            { cls: 'paid',    icon: 'bi-check-circle-fill', alert: 'Paid — OR details required below.' },
        'pending':         { cls: 'pending', icon: 'bi-hourglass-split',   alert: 'Pending — waiting for payment.' },
        'promissory note': { cls: 'pn',      icon: 'bi-journal-text',      alert: 'Promissory Note — attach proof when available.' },
    };

    function syncStatusUI(value) {
        const cfg = STATUS_CFG[value] || {};
        const $alert = $('#edit_statusAlert');
        if (cfg.alert) {
            $alert.removeClass().addClass('status-alert ' + cfg.cls);
            $('#edit_statusAlertText').html(`<i class="bi ${cfg.icon} me-1"></i> ${cfg.alert}`);
        } else {
            $alert.removeClass().addClass('status-alert d-none');
        }
        if (value === 'paid') {
            $('#editOrFieldsGroup').removeClass('hidden');
            if (!$('#edit_collecting_officer option:not([value=""])').length) loadCollectingOfficers();
        } else {
            $('#editOrFieldsGroup').addClass('hidden');
        }
    }

    $('input[name="edit_statusRadio"]').on('change', function () { syncStatusUI($(this).val()); });

    /* ── Collecting officers ── */
    let coLoaded = false;
    function loadCollectingOfficers() {
        if (coLoaded) return;
        const fd = new FormData();
        apiFetch('api/field-office/fetch_co_dropdown.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!Array.isArray(res)) return;
                const $sel = $('#edit_collecting_officer');
                const cur  = $sel.val();
                $sel.html('<option value="">— Select Officer —</option>');
                res.forEach(co => $sel.append(`<option value="${co.name}">${co.name}</option>`));
                if (cur) $sel.val(cur);
                coLoaded = true;
            });
    }

    /* ── Populate modal ── */
    $(document).on('click', '.edit-btn', function () {
        const $btn = $(this);
        $('#edit_regId').val($btn.data('id'));
        $('#edit_orno').val($btn.data('orno') || '');
        $('#edit_ordate').val($btn.data('ordate') || '');
        $('#edit_remarks').val($btn.data('remarks') || '');

        const status = ($btn.data('status') || 'pending').toLowerCase();
        $(`input[name="edit_statusRadio"][value="${status}"]`).prop('checked', true);
        syncStatusUI(status);

        if (status === 'paid') {
            loadCollectingOfficers();
        }

        $('#editModal').modal('show');
    });

    /* ── Reset on close ── */
    $('#editModal').on('hidden.bs.modal', function () {
        $('input[name="edit_statusRadio"]').prop('checked', false);
        $('#edit_statusAlert').removeClass().addClass('status-alert d-none');
        $('#editOrFieldsGroup').addClass('hidden');
        coLoaded = false;
    });

    /* ── Save ── */
    $('#saveChanges').on('click', function () {
        const id     = $('#edit_regId').val();
        const status = $('input[name="edit_statusRadio"]:checked').val() || '';
        const orno   = $('#edit_orno').val().trim();
        const ordate = $('#edit_ordate').val();
        const co     = $('#edit_collecting_officer').val();
        const remarks = $('#edit_remarks').val().trim();

        if (status === 'paid' && !orno) {
            return Swal.fire('OR No. Required',
                'Please enter the OR No. before marking this participant as <b>Paid</b>.', 'warning');
        }

        const fd = new FormData();
        fd.append('id',     id);
        fd.append('status', status);
        fd.append('orno',   orno);
        fd.append('ordate', ordate);
        fd.append('collecting_officer', co);
        fd.append('remarks', remarks);

        apiFetch('api/field-office/update_proof_payment.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    $('#editModal').modal('hide');
                    toast('success', 'Updated successfully');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    Swal.fire('Error', res.message || 'Update failed.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Request failed.', 'error'));
    });

    /* ── Registration & Payment Log ── */
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.pay-log-btn');
        if (!btn) return;
        const regId = btn.dataset.id;
        const name  = btn.dataset.name || 'Participant';
        document.getElementById('payLogName').textContent = name;
        document.getElementById('payLogMeta').textContent = 'Enrollment #' + regId + ' · Registration & Payment Log';
        document.getElementById('payLogBody').innerHTML =
            '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading log…</div>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('payLogModal')).show();

        const fd = new FormData();
        fd.append('enrollment_id', regId);
        apiFetch('api/field-office/fetch_reg_payment_log.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.status !== 'success') {
                    document.getElementById('payLogBody').innerHTML = '<div class="alert alert-danger">Failed to load log.</div>';
                    return;
                }
                document.getElementById('payLogBody').innerHTML = buildPayLogTimeline(res);
            })
            .catch(() => {
                document.getElementById('payLogBody').innerHTML = '<div class="alert alert-danger">Error loading log.</div>';
            });
    });

    function buildPayLogTimeline(res) {
        var esc = function(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); };
        var fmtDate = function(s) {
            if (!s) return '—';
            var d = new Date(s);
            if (isNaN(d)) return s;
            return d.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})
                 + ' ' + d.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit',hour12:true});
        };
        var statusCls = function(s) {
            var m = {paid:'paid',pending:'pending',free:'free',cancelled:'cancel','promissory note':'pn','not qualified':'other'};
            return m[(s||'').toLowerCase()] || 'other';
        };
        var statusIcon = function(s) {
            var m = {paid:'bi-check-circle-fill',pending:'bi-hourglass-split',free:'bi-gift',
                     cancelled:'bi-x-circle-fill','promissory note':'bi-journal-text','not qualified':'bi-slash-circle'};
            return m[(s||'').toLowerCase()] || 'bi-circle';
        };
        var badge = function(s) {
            if (!s) return '';
            return '<span class="plt-badge ' + statusCls(s) + '">' + esc(s) + '</span>';
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
                  + '<div class="plt-body"><div class="plt-no-logs"><i class="bi bi-info-circle-fill"></i>No activity has been recorded for this enrollment.</div>'
                  + '</div></li>';
        } else {
            res.logs.forEach(function(log) {
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
                          + '<div class="plt-meta"><span class="plt-meta-item">' + badge(log.old_status)
                          + '<i class="bi bi-arrow-right" style="color:#94a3b8;font-size:.8rem;"></i>' + badge(log.new_status) + '</span></div>'
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
                    html += '<li class="plt-item">'
                          + '<div class="plt-dot ' + (approved ? 'paid' : 'cancel') + '"><i class="bi ' + (approved ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + '"></i></div>'
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
});
</script>

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
</body>
</html>
