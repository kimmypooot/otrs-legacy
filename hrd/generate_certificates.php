<?php
// imis/otrs/hrd/generate_certificates.php
// Certificate Generation Management Page
// URL: generate_certificates.php?id={training_id}

require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

$trainingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$trainingId) die('<div class="alert alert-danger m-4">Invalid or missing training ID.</div>');

try {
    $stmt = $conn->prepare("SELECT * FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $trainingId));
    $training = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$training) die('<div class="alert alert-danger m-4">Training not found.</div>');

    // Updated Validation Logic
    $trainingType = strtolower($training['type'] ?? '');
    $allowedTypes = ['free', 'public-offered', 'congress-conducted', 'internal'];
    $canGenerateCertificates = ((float)$training['fee'] == 0.0) || in_array($trainingType, $allowedTypes);

    if (!$canGenerateCertificates) {
        die('<div class="alert alert-warning m-4"><strong>Not available.</strong> Certificate generation currently supports <em>Free, Public-Offered, Congress-Conducted, and Internal Trainings</em> only.</div>');
    }

    // Prepare Display Variables
    $tTypeDisplay = $training['type'] ?? 'Public-Offered';
    $feeDisplay = (strtolower($tTypeDisplay) === 'free' || (float)$training['fee'] == 0.0)
        ? 'free'
        : '₱ ' . number_format((float)$training['fee'], 2);

    // Display date (otrs_trainings always has start_date; no legacy date column)
    $displayDate = $training['start_date'];
    if (!empty($training['start_date']) && $training['start_date'] !== '0000-00-00') {
        $s = new DateTime($training['start_date']);
        $e = (!empty($training['end_date']) && $training['end_date'] !== '0000-00-00')
           ? new DateTime($training['end_date']) : $s;
        if ($s == $e)                                        $displayDate = $s->format('F j, Y');
        elseif ($s->format('Y') !== $e->format('Y')) $displayDate = $s->format('M j, Y') . ' – ' . $e->format('M j, Y');
        elseif ($s->format('m') !== $e->format('m')) $displayDate = $s->format('M j') . ' – ' . $e->format('M j, Y');
        else                                         $displayDate = $s->format('F j') . '–' . $e->format('j, Y');
    }

    // Participants + certificate status (normalized schema)
    $stmtPax = $conn->prepare("
        SELECT
            p.id, p.fname, p.mname, p.lname, p.suffix,
            p.position_title AS positiontitle,
            p.agency_name, p.location, p.email,
            r.payment_status AS payment_status,
            r.training_status AS status_training,
            c.id             AS cert_id,
            c.certificate_number,
            c.certificate_file,
            c.issued_at,
            c.status         AS cert_status,
            c.emailed_at,
            c.email_status,
            ccs.id           AS ccs_cert_id,
            ccs.certificate_number AS ccs_certificate_number,
            ccs.category     AS ccs_category,
            ccs.issued_at    AS ccs_issued_at,
            ccs.emailed_at   AS ccs_emailed_at,
            ccs.email_status AS ccs_email_status
        FROM otrs_participants p
        INNER JOIN otrs_registrations r
            ON r.participant_id = p.id AND r.training_id = :tid
        LEFT JOIN otrs_certificates c
            ON c.participant_id = p.id AND c.training_id = :tid2
        LEFT JOIN otrs_ccs_certificates ccs
            ON ccs.id = (
                SELECT cc2.id FROM otrs_ccs_certificates cc2
                 WHERE cc2.participant_id = p.id
                   AND cc2.training_id    = :tid3
                   AND cc2.status        != 'voided'
                 ORDER BY cc2.generated_at DESC
                 LIMIT 1
            )
        WHERE LOWER(r.payment_status) IN ('paid', 'promissory note', 'free')
        ORDER BY p.lname, p.fname
    ");
    $stmtPax->execute([
        ':tid'  => $trainingId,
        ':tid2' => $trainingId,
        ':tid3' => $trainingId,
    ]);
    $participants = $stmtPax->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[generate_certificates] ' . $e->getMessage());
    die('<div class="alert alert-danger m-4">Database error.</div>');
}

$totalPax     = count($participants);
// A participant counts as "issued" if they have a Completion cert (otrs_certificates)
// OR any other format (Certification/Merged/Merged-Certification, CCS-only) — the
// latter never get a row in otrs_certificates, so checking cert_id alone undercounts.
$totalIssued  = count(array_filter($participants, function($p) { return !empty($p['cert_id']) || !empty($p['ccs_cert_id']); }));
$totalPending = $totalPax - $totalIssued;
$totalEmailed = count(array_filter($participants, function($p) { return !empty($p['emailed_at']) || !empty($p['ccs_emailed_at']); }));

// Suspended days
$suspStmt = $conn->prepare(
    "SELECT suspended_date, reason FROM otrs_training_suspensions WHERE training_id = :tid ORDER BY suspended_date ASC"
);
$suspStmt->execute([':tid' => $trainingId]);
$suspensions    = $suspStmt->fetchAll(PDO::FETCH_ASSOC);
$suspendedCount = count($suspensions);

// Check if no_hrs is in sync with suspended days
$calendarDays = 0;
if (!empty($training['start_date']) && $training['start_date'] !== '0000-00-00'
    && !empty($training['end_date'])  && $training['end_date']  !== '0000-00-00') {
    $calendarDays = (new DateTime($training['start_date']))->diff(new DateTime($training['end_date']))->days + 1;
}
$expectedHrs  = max(0, ($calendarDays - $suspendedCount) * 8);
$storedHrs    = (int)($training['no_hrs'] ?? 0);
$hrsOutOfSync = $suspendedCount > 0 && $storedHrs !== $expectedHrs;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Certificates — <?php echo htmlspecialchars($training['name']); ?></title>
    <link href="../assets/img/favicon.png" rel="icon">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
    <style>
        .sidebar { display: none; }
        .main    { margin-left: 0 !important; }

        /* Stat cards */
        .stat-card { border: none; border-radius: 10px; padding: 1rem 1.25rem; display: flex; align-items: center; gap: .85rem; }
        .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .stat-val   { font-size: 1.5rem; font-weight: 700; line-height: 1; }
        .stat-lbl   { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; opacity: .7; margin-top: 2px; }
        .sc-total   { background: #eef4ff; color: #012970; }
        .sc-total .stat-icon   { background: #d4e3ff; color: #0077b6; }
        .sc-issued  { background: #edfbf3; color: #155724; }
        .sc-issued .stat-icon  { background: #c6f4d9; color: #198754; }
        .sc-pending { background: #fff8ec; color: #7a4700; }
        .sc-pending .stat-icon { background: #fde8c0; color: #e07b00; }
        .sc-emailed { background: #f0f4ff; color: #2c3e8c; }
        .sc-emailed .stat-icon { background: #d6e0ff; color: #4361ee; }

        /* Training strip */
        .training-strip { background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%); border-radius: 10px; padding: .9rem 1.25rem; color: #fff; margin-bottom: 1.25rem; }
        .strip-label { font-size: .65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; opacity: .7; margin-bottom: .2rem; }
        .strip-title { font-size: 1rem; font-weight: 700; margin-bottom: .4rem; line-height: 1.3; }
        .strip-meta  { display: flex; flex-wrap: wrap; gap: .3rem 1rem; }
        .strip-meta-item { font-size: .75rem; opacity: .88; display: inline-flex; align-items: center; gap: .25rem; }

        /* Section label */
        .section-label { font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; color: #899bbd; border-bottom: 1px solid #ebeef4; padding-bottom: .4rem; margin-bottom: .9rem; }

        /* Toolbar */
        .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .38rem; padding: .55rem .9rem; background: #f6f9ff; border: 1px solid #ebeef4; border-radius: 6px; margin-bottom: .5rem; }
        .btn-f { border: none; color: #fff; font-size: .78rem; font-weight: 600; padding: .3rem .75rem; border-radius: 4px; display: inline-flex; align-items: center; gap: .3rem; cursor: pointer; text-decoration: none; white-space: nowrap; transition: filter .13s, transform .12s; line-height: 1.45; }
        .btn-f:hover  { filter: brightness(.86); transform: translateY(-1px); color: #fff; }
        .btn-f:active { filter: brightness(.78); transform: translateY(0); color: #fff; }
        .btn-f-success   { background: #198754; }
        .btn-f-primary   { background: #0077b6; }
        .btn-f-secondary { background: #6c757d; }
        .btn-f-danger    { background: #dc3545; }
        .btn-f-email     { background: #4361ee; }
        .btn-f-download  { background: #7b00cc; }

        /* Cert status badges */
        .badge-issued  { background: #198754; color: #fff; font-size: .65rem; font-weight: 700; padding: .15rem .4rem; border-radius: 3px; white-space: nowrap; }
        .badge-pending { background: #6c757d; color: #fff; font-size: .65rem; font-weight: 700; padding: .15rem .4rem; border-radius: 3px; white-space: nowrap; }

        /* Email status badges */
        .badge-emailed  { background: #4361ee; color: #fff; font-size: .65rem; font-weight: 700; padding: .15rem .4rem; border-radius: 3px; white-space: nowrap; }
        .badge-not-sent { background: #adb5bd; color: #fff; font-size: .65rem; font-weight: 700; padding: .15rem .4rem; border-radius: 3px; white-space: nowrap; }
        .badge-failed   { background: #dc3545; color: #fff; font-size: .65rem; font-weight: 700; padding: .15rem .4rem; border-radius: 3px; white-space: nowrap; }

        /* Training type badge */
        .badge-type { display: inline-flex; align-items: center; gap: .3rem; background: #eef4ff; color: #012970; font-size: .72rem; font-weight: 700; padding: .25rem .6rem; border-radius: 20px; border: 1px solid #c6d9ff; }

        /* Back button */
        .btn-back-page { background: #dc3545; border: none; color: #fff; font-size: .78rem; font-weight: 600; padding: .32rem .78rem; border-radius: 5px; display: inline-flex; align-items: center; gap: .3rem; text-decoration: none; transition: filter .13s, transform .12s; white-space: nowrap; }
        .btn-back-page:hover { filter: brightness(.86); transform: translateY(-1px); color: #fff; }

        /* Disabled email button */
        .btn-email-disabled {
            opacity: .4;
            cursor: not-allowed !important;
            pointer-events: none;
        }
        .toolbar-divider { width: 1px; height: 20px; background: #d1d8e8; flex-shrink: 0; }
    </style>
</head>
<body>
<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-purple"><i class="bi bi-patch-check-fill"></i></div>
                <div>
                    <h1>Generate Certificates</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item"><a href="index_trainings">Training List</a></li>
                            <li class="breadcrumb-item active">Certificates</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="type-badge type-<?php echo strtolower($tTypeDisplay) === 'free' ? 'free' : (strtolower($tTypeDisplay) === 'public-offered' ? 'public' : 'congress'); ?>">
                    <i class="bi bi-award-fill"></i> <?php echo htmlspecialchars($tTypeDisplay); ?>
                </span>
                <a href="participants?id=<?php echo $trainingId; ?>" class="otrs-btn-back">
                    <i class="bi bi-arrow-left-circle"></i> Participants
                </a>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body p-4">

                <!-- Training strip -->
                <div class="training-strip">
                    <div class="strip-label">Training</div>
                    <div class="strip-title"><?php echo htmlspecialchars($training['name']); ?></div>
                    <div class="strip-meta">
                        <span class="strip-meta-item"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($displayDate); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-display"></i> <?php echo htmlspecialchars($training['mode']); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-cash"></i> <?php echo htmlspecialchars($feeDisplay); ?></span>
                        <span class="strip-meta-item"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($training['location'] ?? '—'); ?></span>
                    </div>
                </div>

                <?php if ($suspendedCount > 0): ?>
                <!-- Suspended days notice -->
                <div class="alert d-flex gap-2 align-items-start py-2 mb-3
                            <?= $hrsOutOfSync ? 'alert-danger' : 'alert-success' ?>"
                     style="font-size:.85rem;">
                  <i class="bi <?= $hrsOutOfSync ? 'bi-exclamation-triangle-fill' : 'bi-calendar-check-fill' ?> mt-1 flex-shrink-0"></i>
                  <div>
                    <strong><?= $suspendedCount ?> suspended day(s) on record:</strong>
                    <?php foreach ($suspensions as $idx => $s): ?>
                      <span class="badge bg-secondary me-1">
                        <?= htmlspecialchars((new DateTime($s['suspended_date']))->format('M j')) ?>
                        <?= $s['reason'] ? '— ' . htmlspecialchars($s['reason']) : '' ?>
                      </span>
                    <?php endforeach; ?>
                    <br>
                    <?php if ($hrsOutOfSync): ?>
                      <strong class="text-danger">
                        Credit hours mismatch — stored: <strong><?= $storedHrs ?> hrs</strong>,
                        expected: <strong><?= $expectedHrs ?> hrs</strong>.
                        Update <em>No. of Hours</em> in Edit Training before issuing certificates.
                      </strong>
                    <?php else: ?>
                      Credit hours (<?= $storedHrs ?> hrs) are already adjusted for suspended days.
                    <?php endif; ?>
                  </div>
                </div>
                <?php endif; ?>

                <!-- Stat cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card sc-total">
                            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                            <div><div class="stat-val"><?php echo $totalPax; ?></div><div class="stat-lbl">Total Participants</div></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sc-issued">
                            <div class="stat-icon"><i class="bi bi-patch-check-fill"></i></div>
                            <div><div class="stat-val" id="statIssuedCount"><?php echo $totalIssued; ?></div><div class="stat-lbl">Certificates Issued</div></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sc-pending">
                            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div><div class="stat-val" id="statPendingCount"><?php echo $totalPending; ?></div><div class="stat-lbl">Not Yet Issued</div></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sc-emailed">
                            <div class="stat-icon"><i class="bi bi-envelope-check-fill"></i></div>
                            <div><div class="stat-val" id="statEmailedCount"><?php echo $totalEmailed; ?></div><div class="stat-lbl">Certificates Emailed</div></div>
                        </div>
                    </div>
                </div>

                <!-- Credit hours summary (4.5) -->
                <?php
                $certHrs = (int)($training['no_hrs'] ?? 0);
                $certExpected = $suspendedCount > 0 ? max(0, ($calendarDays - $suspendedCount) * 8) : null;
                ?>
                <?php if ($suspendedCount > 0): ?>
                <div class="alert <?= ($hrsOutOfSync ? 'alert-warning' : 'alert-info') ?> d-flex gap-2 align-items-start py-2 mb-3" style="font-size:.82rem;">
                    <i class="bi bi-<?= $hrsOutOfSync ? 'exclamation-triangle-fill' : 'info-circle-fill' ?> mt-1 flex-shrink-0"></i>
                    <div>
                        <?php if ($hrsOutOfSync): ?>
                            <strong>Credit hours may be incorrect.</strong>
                            Stored: <strong><?= $certHrs ?> hrs</strong> —
                            Expected based on <?= $calendarDays ?> days − <?= $suspendedCount ?> suspended: <strong><?= $certExpected ?> hrs</strong>.
                            <a href="#" id="btnRecalcHrs" class="alert-link ms-1">Recalculate &amp; Save</a>
                        <?php else: ?>
                            Certificates will credit <strong><?= $certHrs ?> hrs</strong>
                            (<?= $calendarDays ?> days − <?= $suspendedCount ?> suspended day<?= $suspendedCount !== 1 ? 's' : '' ?> × 8 hrs).
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif ($certHrs > 0): ?>
                <div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-3" style="font-size:.82rem;">
                    <i class="bi bi-info-circle-fill mt-1 flex-shrink-0"></i>
                    <div>Certificates will credit <strong><?= $certHrs ?> hrs</strong> (<?= $calendarDays ?>-day training × 8 hrs).</div>
                </div>
                <?php endif; ?>

                <!-- Toolbar -->
                <p class="section-label"><i class="bi bi-sliders me-1"></i> Actions</p>
                <div class="toolbar mb-3">
                    <!-- Issue -->
                    <button id="btnIssueSelected" class="btn-f btn-f-success">
                        <i class="bi bi-patch-check-fill"></i> Issue Selected
                    </button>
                    <button id="btnPrintSelected" class="btn-f btn-f-primary">
                        <i class="bi bi-printer-fill"></i> Print Selected
                    </button>
                    <button id="btnDownloadSelected" class="btn-f btn-f-download">
                        <i class="bi bi-download"></i> Download Selected
                    </button>
                    <div class="toolbar-divider"></div>
                    <button id="btnIssueAll" class="btn-f btn-f-secondary">
                        <i class="bi bi-check2-all"></i> Issue All
                    </button>
                    <button id="btnPrintAll" class="btn-f btn-f-secondary">
                        <i class="bi bi-printer"></i> Print All
                    </button>
                    <button id="btnDownloadAll" class="btn-f btn-f-download">
                        <i class="bi bi-download"></i> Download All
                    </button>
                    <div class="toolbar-divider"></div>
                    <!-- Email -->
                    <button id="btnEmailSelected" class="btn-f btn-f-email">
                        <i class="bi bi-envelope-fill"></i> Email Selected
                    </button>
                    <button id="btnEmailAll" class="btn-f btn-f-email">
                        <i class="bi bi-envelope-paper-fill"></i> Email All (Issued)
                    </button>
                </div>

                <!-- Table -->
                <p class="section-label"><i class="bi bi-table me-1"></i> Participant Certificate Status</p>
                <div class="table-responsive">
                    <table id="certTable" class="table table-bordered table-hover table-striped" style="font-size:13px;width:100%">
                        <thead>
                            <tr>
                                <th class="text-center align-middle" style="width:40px;">
                                    <input type="checkbox" id="checkAll" style="transform:scale(1.4);">
                                </th>
                                <th class="text-center">#</th>
                                <th>Name</th>
                                <th>Position</th>
                                <th>Agency</th>
                                <th class="text-center">FO</th>
                                <th class="text-center">Payment</th>
                                <th class="text-center">Cert Status</th>
                                <th>Certificate No.</th>
                                <th class="text-center">Issued On</th>
                                <th class="text-center">Email Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
<?php
$counter = 1;
foreach ($participants as $p):

    $participantId  = (int) $p['id'];
    $middleInitial  = !empty($p['mname']) ? strtoupper($p['mname'][0]) . '. ' : '';
    $suffixPart     = !empty(trim($p['suffix'] ?? '')) ? ', ' . trim($p['suffix']) : '';
    $fullName       = strtoupper(trim("{$p['fname']} {$middleInitial}{$p['lname']}")) . $suffixPart;
    // A Completion cert lives in otrs_certificates; Certification/Merged/
    // Merged-Certification are CCS-only and never get a row there — so
    // "issued" must check both to reflect every format a participant may
    // have received, not just Completion.
    $hasCompletionCert = !empty($p['cert_id']);
    $hasCcsCert        = !empty($p['ccs_cert_id']);
    $hasIssuedCert     = $hasCompletionCert || $hasCcsCert;
    $hasEmail          = !empty(trim($p['email'] ?? ''));

    $certificateNumber = $hasCompletionCert
        ? htmlspecialchars($p['certificate_number'])
        : ($hasCcsCert ? htmlspecialchars($p['ccs_certificate_number']) : '—');
    $ccsFormatLabel = $hasCcsCert ? str_replace('_', ' ', $p['ccs_category'] ?? '') : '';

    $issuedDateRaw = $hasCompletionCert ? ($p['issued_at'] ?? null) : ($hasCcsCert ? ($p['ccs_issued_at'] ?? null) : null);
    $issuedDate    = $issuedDateRaw ? date('M j, Y', strtotime($issuedDateRaw)) : '—';

    $emailedAtRaw = $hasCompletionCert ? ($p['emailed_at'] ?? null) : ($hasCcsCert ? ($p['ccs_emailed_at'] ?? null) : null);
    $emailedAt    = !empty($emailedAtRaw) ? date('M j, Y', strtotime($emailedAtRaw)) : null;
    $emailStatus  = $hasCompletionCert ? ($p['email_status'] ?? null) : ($hasCcsCert ? ($p['ccs_email_status'] ?? null) : null);

    // Email button only works for Completion certs — cert_email_ajax.php reads
    // exclusively from otrs_certificates, so CCS-only formats can't be sent
    // from here (use the CCS Dashboard's Email action for those instead).
    $emailBtnDisabled  = (!$hasCompletionCert || !$hasEmail) ? 'btn-email-disabled' : '';
    $emailBtnTitle     = !$hasCompletionCert
        ? ($hasCcsCert ? 'Only Completion-format certificates can be emailed from this page — use the CCS Dashboard' : 'Certificate not yet issued')
        : (!$hasEmail ? 'No email address on record' : 'Send certificate via email');
?>
<tr data-pid="<?= $participantId; ?>" data-has-cert="<?= $hasIssuedCert ? '1' : '0'; ?>" data-has-completion-cert="<?= $hasCompletionCert ? '1' : '0'; ?>">

    <!-- Checkbox -->
    <td class="text-center">
        <input type="checkbox" class="pax-checkbox form-check-input"
               value="<?= $participantId; ?>"
               style="transform: scale(1.3);">
    </td>

    <!-- Counter -->
    <td class="text-center"><?= $counter++; ?></td>

    <!-- Name -->
    <td><?= htmlspecialchars($fullName); ?></td>

    <!-- Position -->
    <td><?= htmlspecialchars($p['positiontitle']); ?></td>

    <!-- Agency -->
    <td><?= htmlspecialchars($p['agency_name']); ?></td>

    <!-- Location -->
    <td class="text-center"><?= htmlspecialchars($p['location']); ?></td>

    <!-- Payment Status -->
    <td class="text-center">
        <?php
        $payStatus = strtolower(trim($p['payment_status'] ?? ''));
        if ($payStatus === 'paid'): ?>
            <span style="background:#198754;color:#fff;font-size:.65rem;font-weight:700;padding:.15rem .45rem;border-radius:3px;white-space:nowrap;">
                <i class="bi bi-check-circle-fill me-1"></i>Paid
            </span>
        <?php elseif ($payStatus === 'promissory note'): ?>
            <span style="background:#e07b00;color:#fff;font-size:.65rem;font-weight:700;padding:.15rem .45rem;border-radius:3px;white-space:nowrap;">
                <i class="bi bi-file-earmark-text-fill me-1"></i>Promissory Note
            </span>
        <?php else: ?>
            <span style="color:#adb5bd;font-size:.75rem;">—</span>
        <?php endif; ?>
    </td>

    <!-- Cert Status -->
    <td class="text-center td-cert-status">
        <?php if ($hasIssuedCert): ?>
            <span class="badge-issued"><i class="bi bi-check-circle-fill me-1"></i>Issued</span>
            <?php if (!$hasCompletionCert && $ccsFormatLabel): ?>
                <div style="font-size:.62rem;color:#6c757d;margin-top:2px;text-transform:capitalize;"><?= htmlspecialchars($ccsFormatLabel); ?></div>
            <?php endif; ?>
        <?php else: ?>
            <span class="badge-pending"><i class="bi bi-dash-circle me-1"></i>Not Issued</span>
        <?php endif; ?>
    </td>

    <!-- Certificate Number -->
    <td class="td-cert-number" style="font-family: monospace; font-size: .75rem;">
        <?= $certificateNumber; ?>
    </td>

    <!-- Issued Date -->
    <td class="text-center td-issued-date" style="font-size: .75rem;">
        <?= $issuedDate; ?>
    </td>

    <!-- Email Status -->
    <td class="text-center td-email-status">
        <?php if (!$hasIssuedCert): ?>
            <span style="color:#adb5bd;font-size:.75rem;">—</span>
        <?php elseif ($emailedAt && strtolower($emailStatus ?? '') === 'sent'): ?>
            <span class="badge-emailed" title="Sent on <?= $emailedAt; ?>">
                <i class="bi bi-envelope-check-fill me-1"></i>Sent
            </span>
            <div style="font-size:.65rem;color:#6c757d;margin-top:2px;"><?= $emailedAt; ?></div>
        <?php elseif (strtolower($emailStatus ?? '') === 'failed'): ?>
            <span class="badge-failed"><i class="bi bi-envelope-x-fill me-1"></i>Failed</span>
        <?php else: ?>
            <span class="badge-not-sent"><i class="bi bi-envelope me-1"></i>Not Sent</span>
        <?php endif; ?>
    </td>

    <!-- Actions -->
    <td class="text-center td-actions" style="white-space: nowrap;">
        <?php if ($hasIssuedCert): ?>
            <?php if ($hasCompletionCert): ?>
            <a href="cert_view.php?id=<?= (int) $p['cert_id']; ?>" target="_blank"
               class="btn btn-primary btn-sm" title="View PDF">
                <i class="bi bi-eye"></i>
            </a>
            <?php endif; ?>
            <?php if ($hasCcsCert): ?>
            <a href="ccs_cert_view.php?id=<?= (int) $p['ccs_cert_id']; ?>" target="_blank"
               class="btn btn-outline-primary btn-sm" title="View CCS Copy">
                <i class="bi bi-file-earmark-richtext"></i>
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-warning btn-sm btn-reissue"
                    data-pid="<?= $participantId; ?>" data-name="<?= htmlspecialchars($fullName); ?>"
                    title="Re-issue">
                <i class="bi bi-arrow-repeat"></i>
            </button>
        <?php else: ?>
            <button type="button" class="btn btn-success btn-sm btn-issue-single"
                    data-pid="<?= $participantId; ?>" data-name="<?= htmlspecialchars($fullName); ?>"
                    title="Issue Certificate">
                <i class="bi bi-patch-check"></i> Issue
            </button>
        <?php endif; ?>
        <!-- Email Button -->
        <button type="button"
                class="btn btn-sm btn-send-email <?= $emailBtnDisabled; ?>"
                style="background:#4361ee;color:#fff;<?= (!$hasCompletionCert || !$hasEmail) ? 'opacity:.4;cursor:not-allowed;' : ''; ?>"
                data-pid="<?= $participantId; ?>"
                data-name="<?= htmlspecialchars($fullName); ?>"
                data-email="<?= htmlspecialchars($p['email'] ?? ''); ?>"
                data-cert-id="<?= (int)($p['cert_id'] ?? 0); ?>"
                <?= (!$hasCompletionCert || !$hasEmail) ? 'disabled' : ''; ?>
                title="<?= $emailBtnTitle; ?>">
            <i class="bi bi-envelope-fill"></i>
        </button>
    </td>

</tr>
<?php endforeach; ?>
        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </section>
</main>

<?php imis_include('footer_db') ?>
<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>

<script>
$(function () {
    const TRAINING_ID  = <?php echo json_encode($trainingId); ?>;

    const dt = $('#certTable').DataTable({
        pageLength: 25,
        language: {
            search: '<i class="bi bi-search"></i>',
            searchPlaceholder: 'Search participants...',
            paginate: { next: '<i class="bi bi-chevron-right"></i>', previous: '<i class="bi bi-chevron-left"></i>' }
        }
    });

    // DataTables detaches rows that aren't on the current page from the live
    // DOM — plain jQuery selectors like $('.pax-checkbox[value=...]') can't
    // find them. rows().nodes() returns nodes for ALL rows regardless of
    // pagination, so row lookups below must go through it, not the DOM.
    const allRows      = () => dt.rows().nodes().toArray();
    const findRowByPid = (pid) => {
        const node = allRows().find(n => String($(n).find('.pax-checkbox').val()) === String(pid));
        return node ? $(node) : $();
    };

    // ── Select all ───────────────────────────────────────────────
    $('#checkAll').on('change', function () {
        $('.pax-checkbox').prop('checked', this.checked);
    });

    // ── Shift+click range select ─────────────────────────────────
    let lastChecked = null;

    $('#certTable').on('click', '.pax-checkbox', function (e) {
        if (e.shiftKey && lastChecked && lastChecked !== this) {
            const $all = $('.pax-checkbox');
            const from = Math.min($all.index(lastChecked), $all.index(this));
            const to   = Math.max($all.index(lastChecked), $all.index(this));
            $all.slice(from, to + 1).prop('checked', $(lastChecked).prop('checked'));
        } else {
            lastChecked = this;
        }
        const total   = $('.pax-checkbox').length;
        const checked = $('.pax-checkbox:checked').length;
        $('#checkAll').prop({ checked: checked === total, indeterminate: checked > 0 && checked < total });
    });

    $('#certTable').on('draw.dt', function () {
        lastChecked = null;
        $('#checkAll').prop({ checked: false, indeterminate: false });
    });

    const getSelectedIds = () => $(allRows()).find('.pax-checkbox:checked').map((i, el) => $(el).val()).get();

    // ── Issue ────────────────────────────────────────────────────
    function issueAndRefresh(participantIds, label) {
        if (!participantIds.length) {
            Swal.fire('Warning', 'Please select at least one participant.', 'warning');
            return;
        }

        const catPromise = Swal.fire({
            title: 'Certificate Format',
            html: `<select id="swal-cert-format" class="form-select mt-2">
                       <option value="completion" selected>Certificate of Completion</option>
                       <option value="certification">Certification</option>
                       <option value="merged">Merged — Completion + Appearance</option>
                       <option value="merged_certification">Merged — Certification + Appearance</option>
                   </select>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Next',
            preConfirm: () => document.getElementById('swal-cert-format').value,
        });

        catPromise.then(catRes => {
            if (!catRes.isConfirmed) return;
            const certFormats = [catRes.value];

        Swal.fire({
            title: 'Generate Certificates?',
            html: `Will generate server-side PDFs for <strong>${participantIds.length}</strong> participant(s).<br><small class="text-muted">${label}</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-patch-check-fill me-1"></i> Generate',
            confirmButtonColor: '#198754',
        }).then(result => {
            if (!result.isConfirmed) return;

            Swal.fire({
                title: 'Generating certificates…',
                html: '<small>This may take a moment. Do not close this page.</small>',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const formData = new FormData();
            formData.append('trainingId', TRAINING_ID);
            participantIds.forEach(id => formData.append('participantIds[]', id));
            formData.append('_csrf', window.csrfToken ?? '');
            certFormats.forEach(f => formData.append('cert_formats[]', f));

            fetch('api/cert_issue_ajax.php', { method: 'POST', body: formData, headers: { 'X-CSRF-Token': window.csrfToken ?? '' } })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(res => {
                if (!res.success && res.error) {
                    Swal.fire({ icon: 'error', title: 'Generation failed', text: res.error });
                    return;
                }

                const issued     = res.results ? res.results.filter(r => r.success).length : 0;
                const failedList = res.errors || [];
                const failedCount = failedList.length;

                let htmlMessage = failedCount > 0
                    ? `<div class="text-danger mt-2" style="font-size:.82rem;text-align:left;">${failedList.join('<br>')}</div>`
                    : 'All certificates generated and saved as PDF.';

                Swal.fire({
                    icon: issued > 0 ? 'success' : 'error',
                    title: issued > 0 ? `${issued} certificate(s) generated` : 'Generation failed',
                    html: htmlMessage,
                    confirmButtonText: 'OK'
                }).then(() => {
                    if (issued > 0) {
                        const table = $('#certTable').DataTable();
                        const today = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                        let newlyIssued = 0;

                        res.results.forEach(result => {
                            if (!result.success) return;
                            const $row = findRowByPid(result.pid);
                            if (!$row.length) return;

                            const isCompletion = !result.format || result.format === 'completion';
                            if ($row.attr('data-has-cert') !== '1') newlyIssued++;
                            $row.attr('data-has-cert', '1');

                            if (isCompletion) {
                                $row.attr('data-has-completion-cert', '1');

                                // Cert Status (col 7)
                                $row.find('td').eq(7).html('<span class="badge-issued"><i class="bi bi-check-circle-fill me-1"></i>Issued</span>');
                                // Cert Number (col 8)
                                $row.find('td').eq(8).text(result.cert_number);
                                // Issued Date (col 9)
                                $row.find('td').eq(9).text(today);
                                // Email Status (col 10) — update to "Not Sent" now that cert exists
                                $row.find('td').eq(10).html('<span class="badge-not-sent"><i class="bi bi-envelope me-1"></i>Not Sent</span>');

                                // Action buttons (col 11) — if this response didn't come
                                // back with a ccs_cert_id, keep whatever CCS view button
                                // was already rendered for this row (e.g. from page load)
                                // instead of wiping it out; a re-issue of an already
                                // CCS-linked cert shouldn't make that link disappear.
                                const $existingCcsBtn = $row.find('td').eq(11).find('a[href*="ccs_cert_view.php"]');
                                const viewBtn    = `<a href="cert_view.php?id=${result.cert_id}" target="_blank" class="btn btn-primary btn-sm" title="View PDF"><i class="bi bi-eye"></i></a>`;
                                const ccsViewBtn = result.ccs_cert_id
                                    ? `<a href="ccs_cert_view.php?id=${result.ccs_cert_id}" target="_blank" class="btn btn-outline-primary btn-sm" title="View CCS Copy"><i class="bi bi-file-earmark-richtext"></i></a>`
                                    : ($existingCcsBtn.length ? $existingCcsBtn[0].outerHTML : '');
                                const reissueBtn = `<button type="button" class="btn btn-warning btn-sm btn-reissue" data-pid="${result.pid}" data-name="${result.name}" title="Re-issue"><i class="bi bi-arrow-repeat"></i></button>`;
                                const emailBtn   = `<button type="button" class="btn btn-sm btn-send-email" style="background:#4361ee;color:#fff;" data-pid="${result.pid}" data-name="${result.name}" data-cert-id="${result.cert_id}" title="Send certificate via email"><i class="bi bi-envelope-fill"></i></button>`;
                                $row.find('td').eq(11).html(`${viewBtn} ${ccsViewBtn} ${reissueBtn} ${emailBtn}`);
                            } else {
                                // Certification / Merged / Merged-Certification — CCS-only,
                                // no row in otrs_certificates. result.cert_id is the
                                // otrs_ccs_certificates id for these formats, so point the
                                // "View CCS Copy" button at the cert just (re)issued instead
                                // of leaving it pointing at whatever was linked before —
                                // that mismatch is what made a re-issue with a different
                                // format appear to still show the old certificate.
                                const ccsViewBtn = `<a href="ccs_cert_view.php?id=${result.cert_id}" target="_blank" class="btn btn-outline-primary btn-sm" title="View CCS Copy"><i class="bi bi-file-earmark-richtext"></i></a>`;
                                const $actionsTd = $row.find('td').eq(11);
                                const $existingCcsBtn = $actionsTd.find('a[href*="ccs_cert_view.php"]');
                                if ($existingCcsBtn.length) {
                                    $existingCcsBtn.replaceWith(ccsViewBtn);
                                } else {
                                    $actionsTd.append(' ' + ccsViewBtn);
                                }

                                // Completion takes display precedence when both exist (mirrors
                                // the PHP render logic) — only reflect this CCS-only cert in
                                // Cert Status/Number/Issued if there's no Completion cert too.
                                if ($row.attr('data-has-completion-cert') !== '1') {
                                    $row.find('td').eq(7).html(
                                        '<span class="badge-issued"><i class="bi bi-check-circle-fill me-1"></i>Issued</span>' +
                                        '<div style="font-size:.62rem;color:#6c757d;margin-top:2px;text-transform:capitalize;">' +
                                        (result.format || '').replace(/_/g, ' ') + '</div>'
                                    );
                                    $row.find('td').eq(8).text(result.cert_number);
                                    $row.find('td').eq(9).text(today);

                                    // First cert for this participant — swap the plain "Issue"
                                    // button for "Re-issue" (View-Completion/Email don't apply
                                    // to CCS-only certs, so they're intentionally omitted).
                                    if ($actionsTd.find('.btn-issue-single').length) {
                                        $actionsTd.find('.btn-issue-single').remove();
                                        $actionsTd.prepend(`<button type="button" class="btn btn-warning btn-sm btn-reissue" data-pid="${result.pid}" data-name="${result.name}" title="Re-issue"><i class="bi bi-arrow-repeat"></i></button> `);
                                    }
                                }
                            }

                            table.row($row).invalidate();
                        });

                        if (newlyIssued > 0) {
                            $('#statIssuedCount').text(parseInt($('#statIssuedCount').text()) + newlyIssued);
                            $('#statPendingCount').text(Math.max(0, parseInt($('#statPendingCount').text()) - newlyIssued));
                        }

                        table.draw(false);
                        $('#checkAll').prop('checked', false);
                        $('.pax-checkbox').prop('checked', false);
                    }
                });
            })
            .catch(error => {
                console.error("Fetch Error:", error);
                Swal.fire('Error', 'Request failed. Please check your console.', 'error');
            });
        }); // end Swal.fire confirm
        }); // end catPromise.then
    }

    // 4.5 — Warn before issuing when no_hrs is out of sync with suspended days
    const hrsOutOfSync   = <?= $hrsOutOfSync ? 'true' : 'false' ?>;
    const expectedHrs    = <?= (int)($certExpected ?? $certHrs) ?>;

    function guardHrsThenIssue(ids, label) {
        if (!hrsOutOfSync) { issueAndRefresh(ids, label); return; }
        Swal.fire({
            icon: 'warning',
            title: 'Credit Hours Mismatch',
            html: `The stored credit hours (<b><?= $certHrs ?> hrs</b>) don't match the expected value after suspended days (<b>${expectedHrs} hrs</b>).<br><br>
                   <strong>Recalculate before issuing?</strong>`,
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: 'Recalculate &amp; Issue',
            denyButtonText: 'Issue Anyway',
            cancelButtonText: 'Cancel',
        }).then(r => {
            if (r.isConfirmed) {
                $('#btnRecalcHrs').trigger('click');
            } else if (r.isDenied) {
                issueAndRefresh(ids, label);
            }
        });
    }

    // Recalculate no_hrs via API (reuses add_suspension endpoint pattern)
    $('#btnRecalcHrs').on('click', function (e) {
        e.preventDefault();
        $.post('api/recalc_hrs.php', { training_id: <?= $trainingId ?>, _csrf: window.csrfToken ?? '' })
         .done(res => {
            if (res.success) {
                location.reload();
            } else {
                Swal.fire('Error', res.error || 'Recalculation failed.', 'error');
            }
         }).fail(() => Swal.fire('Error', 'Request failed.', 'error'));
    });

    $('#btnIssueSelected').click(() => guardHrsThenIssue(getSelectedIds(), 'Selected participants'));
    $('#btnIssueAll').click(() => {
        const ids = $(allRows()).find('.pax-checkbox').map((i, el) => $(el).val()).get();
        guardHrsThenIssue(ids, 'All participants in this training');
    });
    $(document).on('click', '.btn-issue-single', function () {
        issueAndRefresh([$(this).data('pid')], $(this).data('name'));
    });
    $(document).on('click', '.btn-reissue', function () {
        const pid = $(this).data('pid'), name = $(this).data('name');
        Swal.fire({
            title: 'Re-issue Certificate?',
            html: `Generates a new PDF for <em>${name}</em>. <b>The existing certificate number will be retained.</b>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Re-issue',
            confirmButtonColor: '#e07b00',
        }).then(r => { if (r.isConfirmed) issueAndRefresh([pid], name); });
    });

    // ── Print ────────────────────────────────────────────────────
    function openBatchPrint(ids, category) {
        if (!ids.length) { Swal.fire('Warning', 'Please select at least one participant.', 'warning'); return; }
        const cat = category ? '&category=' + encodeURIComponent(category) : '';
        window.open('cert_batch_print.php?ids=' + ids.join(',') + '&tid=' + TRAINING_ID + cat, '_blank');
    }
    $('#btnPrintSelected').click(() => openBatchPrint(getSelectedIds()));
    $('#btnPrintAll').click(() => {
        openBatchPrint($(allRows()).find('.pax-checkbox').map((i, el) => $(el).val()).get());
    });

    // ── Download (format-aware) ─────────────────────────────────────
    // Prompts for which issued format to view/download — Merged certs live
    // in the CCS repository, separate from the Completion/Certification
    // table tracked on this page, so the viewer needs to know which to read.
    function promptDownloadFormat(ids) {
        if (!ids.length) { Swal.fire('Warning', 'Please select at least one participant.', 'warning'); return; }
        Swal.fire({
            title: 'Which certificate format?',
            html: `<select id="swal-dl-format" class="form-select mt-2">
                       <option value="completion" selected>Certificate of Completion</option>
                       <option value="certification">Certification</option>
                       <option value="merged">Merged — Completion + Appearance</option>
                       <option value="merged_certification">Merged — Certification + Appearance</option>
                   </select>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'View / Download',
            preConfirm: () => document.getElementById('swal-dl-format').value,
        }).then(res => {
            if (!res.isConfirmed) return;
            openBatchPrint(ids, res.value);
        });
    }
    $('#btnDownloadSelected').click(() => promptDownloadFormat(getSelectedIds()));
    $('#btnDownloadAll').click(() => {
        promptDownloadFormat($(allRows()).find('.pax-checkbox').map((i, el) => $(el).val()).get());
    });

    // ── Email ────────────────────────────────────────────────────

    /**
     * sendEmailCertificates(participantIds, label)
     * Sends certificate emails via cert_email_ajax.php, which only reads
     * otrs_certificates — so eligibility is gated on data-has-completion-cert,
     * not data-has-cert (which also covers CCS-only formats it can't send).
     */
    function sendEmailCertificates(participantIds, label) {
        if (!participantIds.length) {
            Swal.fire('Warning', 'Please select at least one participant.', 'warning');
            return;
        }

        // Filter out participants who don't have an emailable Completion cert
        const eligibleIds = participantIds.filter(pid => {
            return findRowByPid(pid).attr('data-has-completion-cert') === '1';
        });

        if (!eligibleIds.length) {
            Swal.fire({
                icon: 'warning',
                title: 'No Eligible Participants',
                html: 'None of the selected participants have a certificate issued yet.<br><small class="text-muted">Please generate certificates first before sending emails.</small>'
            });
            return;
        }

        const skippedCount = participantIds.length - eligibleIds.length;
        const skipNote = skippedCount > 0
            ? `<br><small class="text-warning">${skippedCount} participant(s) skipped — no certificate issued yet.</small>`
            : '';

        Swal.fire({
            title: 'Send Certificate Emails?',
            html: `Will email certificates to <strong>${eligibleIds.length}</strong> participant(s).<br>
                   <small class="text-muted">${label}</small>${skipNote}`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-envelope-fill me-1"></i> Send Emails',
            confirmButtonColor: '#4361ee',
        }).then(result => {
            if (!result.isConfirmed) return;

            Swal.fire({
                title: 'Sending emails…',
                html: '<small>This may take a moment. Do not close this page.</small>',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const formData = new FormData();
            formData.append('trainingId', TRAINING_ID);
            eligibleIds.forEach(id => formData.append('participantIds[]', id));
            formData.append('_csrf', window.csrfToken ?? '');

            fetch('api/cert_email_ajax.php', { method: 'POST', body: formData, headers: { 'X-CSRF-Token': window.csrfToken ?? '' } })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(res => {
                if (!res.success && res.error) {
                    Swal.fire({ icon: 'error', title: 'Email failed', text: res.error });
                    return;
                }

                const sent       = res.results ? res.results.filter(r => r.success).length : 0;
                const failedList = res.errors || [];
                const failedCount = failedList.length;

                let htmlMsg = sent > 0
                    ? `<strong>${sent}</strong> email(s) sent successfully.`
                    : '';
                if (failedCount > 0) {
                    htmlMsg += `<div class="text-danger mt-2" style="font-size:.82rem;text-align:left;">${failedList.join('<br>')}</div>`;
                }

                Swal.fire({
                    icon: sent > 0 ? 'success' : 'error',
                    title: sent > 0 ? `${sent} email(s) sent` : 'All failed',
                    html: htmlMsg,
                    confirmButtonText: 'OK'
                }).then(() => {
                    if (res.results) {
                        const today = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                        let newEmailedCount = parseInt($('#statEmailedCount').text()) || 0;

                        res.results.forEach(r => {
                            const $row = findRowByPid(r.pid);
                            if (!$row.length) return;

                            const wasAlreadyEmailed = $row.find('.td-email-status .badge-emailed').length > 0;

                            if (r.success) {
                                $row.find('.td-email-status').html(
                                    `<span class="badge-emailed" title="Sent on ${today}"><i class="bi bi-envelope-check-fill me-1"></i>Sent</span>
                                     <div style="font-size:.65rem;color:#6c757d;margin-top:2px;">${today}</div>`
                                );
                                if (!wasAlreadyEmailed) newEmailedCount++;
                            } else {
                                $row.find('.td-email-status').html(
                                    `<span class="badge-failed"><i class="bi bi-envelope-x-fill me-1"></i>Failed</span>`
                                );
                            }
                        });

                        // Update stat card
                        $('#statEmailedCount').text(newEmailedCount);

                        // Clear checkboxes
                        $('#checkAll').prop('checked', false);
                        $('.pax-checkbox').prop('checked', false);
                    }
                });
            })
            .catch(error => {
                console.error("Email Fetch Error:", error);
                Swal.fire('Error', 'Request failed. Please check your console.', 'error');
            });
        });
    }

    // Toolbar: Email Selected
    $('#btnEmailSelected').click(() => {
        sendEmailCertificates(getSelectedIds(), 'Selected participants');
    });

    // Toolbar: Email All (only already-issued Completion certs — the only
    // format cert_email_ajax.php can send)
    $('#btnEmailAll').click(() => {
        const ids = $(allRows()).filter('[data-has-completion-cert="1"]').find('.pax-checkbox').map((i, el) => $(el).val()).get();
        sendEmailCertificates(ids, 'All participants with issued Completion certificates');
    });

    // Per-row Email button
    $(document).on('click', '.btn-send-email:not([disabled])', function () {
        const pid  = $(this).data('pid');
        const name = $(this).data('name');
        sendEmailCertificates([String(pid)], name);
    });

});
</script>
</body>
</html>