<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/inc/training_dates.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

/* ── Training list for selector (SDC only, matching participant_output.php) */
try {
    $trainingList = $conn->query("
        SELECT id, name, start_date, end_date
        FROM   otrs_trainings
        WHERE  training_status NOT IN ('cancelled','Cancelled','Rescheduled','rescheduled')
          AND  status = 'active'
          AND  (name LIKE '%Supervisory Development Course%' OR name LIKE '%SDC%')
        ORDER BY start_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $trainingList = [];
}

/* ── Load selected training data ────────────────────────────────────────── */
$trainingId    = filter_input(INPUT_GET, 'training_id', FILTER_VALIDATE_INT);
$training      = null;
$registrations = [];
$submissions   = [];   // indexed by registration_id → day_number → row
$maxDay        = 0;
$suspensions   = [];   // suspended days for this training
$calendarDays  = 0;

if ($trainingId) {
    try {
        $stT = $conn->prepare("SELECT id, name, start_date, end_date FROM otrs_trainings WHERE id = :id LIMIT 1");
        $stT->execute([':id' => $trainingId]);
        $training = $stT->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* non-fatal */ }

    if ($training) {
        /* Suspended days — for display only (shared helper already excludes them) */
        try {
            $stSusp = $conn->prepare(
                "SELECT suspended_date, reason FROM otrs_training_suspensions
                 WHERE training_id = :tid ORDER BY suspended_date ASC"
            );
            $stSusp->execute([':tid' => $trainingId]);
            $suspensions = $stSusp->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { /* non-fatal */ }

        /* Actual training days (custom dates if set, else range minus suspensions) */
        $calendarDays = count(getTrainingDateStrings($conn, (int)$trainingId));
        $maxDay       = max(1, $calendarDays);

        /* Registrations */
        try {
            $stR = $conn->prepare("
                SELECT r.id AS reg_id, r.payment_status,
                       p.id AS participant_id,
                       CONCAT(p.lname, ', ', p.fname,
                              IF(p.mname <> '', CONCAT(' ', LEFT(p.mname,1), '.'), '')) AS full_name,
                       p.location, p.agency_name
                FROM   otrs_registrations r
                JOIN   otrs_participants  p ON p.id = r.participant_id
                WHERE  r.training_id = :tid
                  AND  r.registration_status NOT IN ('cancelled')
                ORDER BY p.lname ASC, p.fname ASC
            ");
            $stR->execute([':tid' => $trainingId]);
            $registrations = $stR->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { /* non-fatal */ }

        /* Submissions */
        if ($registrations) {
            $regIds = array_column($registrations, 'reg_id');
            $ph     = implode(',', array_fill(0, count($regIds), '?'));
            try {
                $stS = $conn->prepare("
                    SELECT id, registration_id, day_number, file_path,
                           file_original_name, validation_status, remarks,
                           validated_at, created_at
                    FROM   otrs_training_submissions
                    WHERE  registration_id IN ($ph)
                    ORDER BY registration_id, day_number
                ");
                $stS->execute($regIds);
                foreach ($stS->fetchAll(PDO::FETCH_ASSOC) as $sub) {
                    $submissions[$sub['registration_id']][$sub['day_number']] = $sub;
                    $maxDay = max($maxDay, (int)$sub['day_number']);
                }
            } catch (PDOException $e) { /* non-fatal */ }
        }
    }
}

$locationMap = [
    'hrd'   => 'HRD',   'lfoi'  => 'LFO I',   'lfoii' => 'LFO II',
    'bfo'   => 'BFO',   'wlso'  => 'WLSO',    'slfo'  => 'SLFO',
    'sfo'   => 'SFO',   'esfo'  => 'ESFO',    'nsfo'  => 'NSFO',
    'ro6'   => 'RO VI', 'ro7'   => 'RO VII',
];

function fmtDateRange(?string $s, ?string $e): string {
    if (!$s || $s === '0000-00-00') return '—';
    try {
        $sd = new DateTimeImmutable($s);
        $ed = ($e && $e !== '0000-00-00') ? new DateTimeImmutable($e) : null;
        if (!$ed || $sd->format('Y-m-d') === $ed->format('Y-m-d')) return $sd->format('M j, Y');
        if ($sd->format('Y-m') === $ed->format('Y-m')) return $sd->format('M j') . '–' . $ed->format('j, Y');
        return $sd->format('M j') . ' – ' . $ed->format('M j, Y');
    } catch (Exception $ex) { return htmlspecialchars($s); }
}

/** Segmented date display — splits around gaps in the training's actual dates. */
function fmtSegmentedDate(array $trainingDateStrings, string $fallbackStart, string $fallbackEnd): string {
    if (empty($trainingDateStrings)) return fmtDateRange($fallbackStart, $fallbackEnd);

    $days = array_map(fn($d) => new DateTime($d), $trainingDateStrings);

    // Group consecutive days into segments
    $segments = [];
    $sS = $days[0]; $sE = $days[0];
    for ($i = 1, $n = count($days); $i < $n; $i++) {
        $nxt = clone $days[$i - 1];
        $nxt->modify('+1 day');
        if ($nxt->format('Y-m-d') === $days[$i]->format('Y-m-d')) {
            $sE = $days[$i];
        } else {
            $segments[] = [$sS, $sE];
            $sS = $sE = $days[$i];
        }
    }
    $segments[] = [$sS, $sE];
    if (count($segments) === 1) return fmtDateRange($fallbackStart, $fallbackEnd);

    $allSameYear = count(array_unique(array_map(fn($s) => $s[0]->format('Y'), $segments))) === 1;
    $total  = count($segments);
    $parts  = [];
    foreach ($segments as $i => [$s, $e]) {
        $isLast = $i === $total - 1;
        $showYr = !$allSameYear || $isLast;
        $yrSfx  = $showYr ? ', ' . $e->format('Y') : '';
        if ($s == $e) {
            $parts[] = $showYr ? $s->format('F j, Y') : $s->format('F j');
        } elseif ($s->format('Y-m') === $e->format('Y-m')) {
            $parts[] = $s->format('F j') . '-' . $e->format('j') . $yrSfx;
        } else {
            $parts[] = $s->format('F j') . ' - ' . $e->format('F j') . $yrSfx;
        }
    }
    return implode(' - ', $parts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submission Review – OTRS HRD</title>
<link href="../../assets/img/favicon.png" rel="icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<style>
.badge-approved { background:#d1fae5; color:#065f46; font-size:.72rem; }
.badge-rejected { background:#fee2e2; color:#991b1b; font-size:.72rem; }
.badge-pending  { background:#fef9c3; color:#854d0e; font-size:.72rem; }
.day-cell { min-width:90px; text-align:center; }
</style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-blue"><i class="bi bi-card-checklist"></i></div>
                <div>
                    <h1>Submission Review</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Submission Review</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
        </div>
    </div>

    <section class="section">

        <!-- ── Training selector ─────────────────────────────────────── -->
        <div class="card mb-3">
            <div class="card-body py-3 px-4">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-sm-8 col-lg-6">
                        <label class="form-label fw-semibold mb-1" style="font-size:.82rem;">Select Training</label>
                        <select name="training_id" class="form-select form-select-sm">
                            <option value="">— Choose a training —</option>
                            <?php foreach ($trainingList as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>"
                                <?php echo ((int)$t['id'] === $trainingId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name']); ?>
                                (<?php echo fmtDateRange($t['start_date'], $t['end_date']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-auto">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search me-1"></i>View Submissions
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($trainingId && $training): ?>

        <!-- ── Summary badges ────────────────────────────────────────── -->
        <?php
        $totalSubs     = 0;
        $approvedCount = 0;
        $rejectedCount = 0;
        $pendingCount  = 0;
        foreach ($submissions as $dayMap) {
            foreach ($dayMap as $sub) {
                $totalSubs++;
                if ($sub['validation_status'] === 'approved') $approvedCount++;
                elseif ($sub['validation_status'] === 'rejected') $rejectedCount++;
                else $pendingCount++;
            }
        }
        ?>
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <span class="badge rounded-pill bg-secondary-subtle text-secondary border" style="font-size:.78rem;padding:.4em .8em;">
                    <i class="bi bi-people me-1"></i><?php echo count($registrations); ?> Participants
                </span>
            </div>
            <div class="col-auto">
                <span class="badge rounded-pill bg-success-subtle text-success border" style="font-size:.78rem;padding:.4em .8em;">
                    <i class="bi bi-check-circle me-1"></i><?php echo $approvedCount; ?> Approved
                </span>
            </div>
            <div class="col-auto">
                <span class="badge rounded-pill bg-warning-subtle text-warning border" style="font-size:.78rem;padding:.4em .8em;">
                    <i class="bi bi-hourglass me-1"></i><?php echo $pendingCount; ?> Pending
                </span>
            </div>
            <div class="col-auto">
                <span class="badge rounded-pill bg-danger-subtle text-danger border" style="font-size:.78rem;padding:.4em .8em;">
                    <i class="bi bi-x-circle me-1"></i><?php echo $rejectedCount; ?> Rejected
                </span>
            </div>
            <div class="col-auto">
                <span class="badge rounded-pill bg-light text-dark border" style="font-size:.78rem;padding:.4em .8em;">
                    <?php echo $maxDay; ?> actual training day<?php echo $maxDay !== 1 ? 's' : ''; ?>
                    <?php if (count($suspensions) > 0): ?>
                        <span class="text-warning ms-1">(<?php echo count($suspensions); ?> suspended)</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <?php if (!empty($suspensions)): ?>
        <div class="alert alert-warning d-flex gap-2 align-items-start py-2 mb-3" style="font-size:.82rem;">
            <i class="bi bi-calendar-x-fill mt-1 flex-shrink-0"></i>
            <div>
                <strong><?php echo count($suspensions); ?> suspended day(s) excluded from the submission matrix:</strong>
                <?php foreach ($suspensions as $susp): ?>
                    <span class="badge bg-warning text-dark ms-1">
                        <?php echo htmlspecialchars((new DateTime($susp['suspended_date']))->format('M j, Y')); ?>
                        <?php if (!empty($susp['reason'])): ?>— <?php echo htmlspecialchars($susp['reason']); ?><?php endif; ?>
                    </span>
                <?php endforeach; ?>
                <div class="text-muted mt-1" style="font-size:.76rem;">
                    Day numbering in the table reflects actual training days only.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Attendance matrix ─────────────────────────────────────── -->
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2" style="background:#f0f9ff;">
                <i class="bi bi-table text-primary"></i>
                <span class="fw-semibold" style="font-size:.9rem;">
                    <?php echo htmlspecialchars($training['name']); ?> —
                    <?php echo fmtSegmentedDate(getTrainingDateStrings($conn, (int)$trainingId), $training['start_date'], $training['end_date']); ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="submissionTable" class="table table-bordered table-hover table-sm mb-0" style="font-size:12.5px;">
                        <thead class="table-light">
                            <tr>
                                <th class="align-middle">#</th>
                                <th class="align-middle">Participant</th>
                                <th class="align-middle">Office</th>
                                <th class="align-middle">Payment</th>
                                <?php for ($d = 1; $d <= $maxDay; $d++): ?>
                                <th class="align-middle text-center day-cell">Day <?php echo $d; ?></th>
                                <?php endfor; ?>
                                <th class="align-middle text-center">Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($registrations)): ?>
                            <tr><td colspan="<?php echo 5 + $maxDay; ?>" class="text-center text-muted py-4">No participants found for this training.</td></tr>
                        <?php else: foreach ($registrations as $i => $reg):
                            $regSubs    = $submissions[$reg['reg_id']] ?? [];
                            $subApproved = count(array_filter($regSubs, fn($s) => $s['validation_status'] === 'approved'));
                            $subTotal    = count($regSubs);
                            $office = $locationMap[strtolower($reg['location'] ?? '')] ?? strtoupper($reg['location'] ?? '—');
                        ?>
                            <tr>
                                <td class="align-middle"><?php echo $i + 1; ?></td>
                                <td class="align-middle fw-semibold" style="white-space:nowrap;">
                                    <?php echo htmlspecialchars($reg['full_name']); ?>
                                </td>
                                <td class="align-middle">
                                    <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:.7rem;">
                                        <?php echo htmlspecialchars($office); ?>
                                    </span>
                                </td>
                                <td class="align-middle">
                                    <?php
                                    $ps = strtolower($reg['payment_status']);
                                    $pBadge = match($ps) {
                                        'paid'            => '<span class="badge bg-success" style="font-size:.7rem;">Paid</span>',
                                        'pending'         => '<span class="badge bg-warning text-dark" style="font-size:.7rem;">Pending</span>',
                                        'promissory note' => '<span class="badge bg-info text-dark" style="font-size:.7rem;">Promissory</span>',
                                        default           => '<span class="badge bg-secondary" style="font-size:.7rem;">' . htmlspecialchars(ucfirst($ps)) . '</span>',
                                    };
                                    echo $pBadge;
                                    ?>
                                </td>
                                <?php for ($d = 1; $d <= $maxDay; $d++):
                                    $sub = $regSubs[$d] ?? null;
                                ?>
                                <td class="align-middle day-cell">
                                    <?php if ($sub): ?>
                                    <div class="d-flex flex-column align-items-center gap-1">
                                        <?php
                                        $vs = $sub['validation_status'];
                                        $badgeCls = match($vs) {
                                            'approved' => 'badge-approved',
                                            'rejected' => 'badge-rejected',
                                            default    => 'badge-pending',
                                        };
                                        $icon = match($vs) {
                                            'approved' => 'bi-check-circle-fill text-success',
                                            'rejected' => 'bi-x-circle-fill text-danger',
                                            default    => 'bi-hourglass-split text-warning',
                                        };
                                        ?>
                                        <span class="badge <?php echo $badgeCls; ?>"><?php echo ucfirst($vs); ?></span>
                                        <div class="d-flex gap-1 mt-1">
                                            <?php
                                            $fp = htmlspecialchars($sub['file_path'], ENT_QUOTES);
                                            $fn = htmlspecialchars($sub['file_original_name'], ENT_QUOTES);
                                            ?>
                                            <a href="<?php echo $fp; ?>" target="_blank"
                                               class="btn btn-xs btn-outline-secondary" style="font-size:.65rem;padding:1px 5px;"
                                               title="View file: <?php echo $fn; ?>">
                                                <i class="bi bi-file-earmark"></i>
                                            </a>
                                            <button class="btn btn-xs btn-outline-primary validate-btn"
                                                    style="font-size:.65rem;padding:1px 5px;"
                                                    data-id="<?php echo (int)$sub['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($reg['full_name'], ENT_QUOTES); ?>"
                                                    data-day="<?php echo $d; ?>"
                                                    data-status="<?php echo htmlspecialchars($vs, ENT_QUOTES); ?>"
                                                    data-remarks="<?php echo htmlspecialchars($sub['remarks'] ?? '', ENT_QUOTES); ?>"
                                                    data-file="<?php echo $fp; ?>"
                                                    data-regid="<?php echo (int)$reg['reg_id']; ?>"
                                                    title="Validate">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted" style="font-size:.75rem;">—</span>
                                    <?php endif; ?>
                                </td>
                                <?php endfor; ?>
                                <td class="align-middle text-center">
                                    <?php if ($subTotal > 0): ?>
                                    <div style="font-size:.72rem;"><?php echo $subApproved; ?>/<?php echo $subTotal; ?></div>
                                    <div class="progress mt-1" style="height:5px;min-width:60px;">
                                        <div class="progress-bar bg-success" style="width:<?php echo $subTotal > 0 ? round($subApproved/$subTotal*100) : 0; ?>%"></div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted" style="font-size:.72rem;">No submissions</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($trainingId): ?>
        <div class="alert alert-warning">Training not found.</div>
        <?php endif; ?>

    </section>
</main>

<!-- ── Validate Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="validateModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0">Validate Submission</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="validateInfo" class="mb-2" style="font-size:.82rem;"></p>
                <a id="validateFileLink" href="#" target="_blank"
                   class="btn btn-outline-secondary btn-sm w-100 mb-2 d-none" style="font-size:.75rem;">
                    <i class="bi bi-file-earmark-pdf me-1"></i> View Submission File
                </a>
                <div class="mb-2">
                    <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">Decision</label>
                    <select id="validateStatus" class="form-select form-select-sm">
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="pending">Reset to Pending</option>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">Remarks (optional)</label>
                    <textarea id="validateRemarks" class="form-control form-control-sm" rows="3" placeholder="Add remarks…"></textarea>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="validateSaveBtn" class="btn btn-primary btn-sm">Save</button>
            </div>
        </div>
    </div>
</div>

<?php imis_include('footer_db') ?>
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
<script src="../../assets/js/main.js"></script>

<script>
$(function () {
    <?php if ($trainingId && $training && !empty($registrations)): ?>
    $('#submissionTable').DataTable({
        pageLength: 25,
        order:      [[1, 'asc']],
        language: {
            search: '', searchPlaceholder: 'Search…',
            paginate: { next: '<i class="bi bi-chevron-right"></i>', previous: '<i class="bi bi-chevron-left"></i>' }
        }
    });
    <?php endif; ?>

    let currentSubId  = null;
    let currentBtnRef = null;
    const modal = new bootstrap.Modal(document.getElementById('validateModal'));

    // Badge maps (mirror PHP)
    const BADGE_CLS  = { approved: 'badge-approved', rejected: 'badge-rejected', pending: 'badge-pending' };

    $(document).on('click', '.validate-btn', function () {
        currentSubId  = $(this).data('id');
        currentBtnRef = this;
        const name    = $(this).data('name');
        const day     = $(this).data('day');
        const status  = $(this).data('status');
        const rem     = $(this).data('remarks') || '';
        const file    = $(this).data('file')    || '';

        $('#validateInfo').text(name + ' – Day ' + day);
        $('#validateStatus').val(status);
        $('#validateRemarks').val(rem);

        // 4.3 — File preview link
        const $link = $('#validateFileLink');
        if (file) {
            $link.attr('href', file).removeClass('d-none');
        } else {
            $link.addClass('d-none');
        }
        modal.show();
    });

    $('#validateSaveBtn').on('click', function () {
        if (!currentSubId) return;
        const btn       = $(this).prop('disabled', true).text('Saving…');
        const newStatus = $('#validateStatus').val();
        const newRemark = $('#validateRemarks').val();

        fetch('api/validate_submission.php', {
            method:  'POST',
            headers: { 'X-CSRF-Token': window.csrfToken ?? '', 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({
                submission_id: currentSubId,
                status:        newStatus,
                remarks:       newRemark,
                _csrf:         window.csrfToken ?? ''
            })
        })
        .then(r => r.json())
        .then(res => {
            modal.hide();
            if (res.status === 'success') {
                // 4.4 — In-place DOM update: no page reload
                if (currentBtnRef) {
                    const $cell = $(currentBtnRef).closest('td.day-cell');
                    const badgeCls = BADGE_CLS[newStatus] || 'badge-pending';
                    $cell.find('.badge').attr('class', 'badge ' + badgeCls).text(
                        newStatus.charAt(0).toUpperCase() + newStatus.slice(1)
                    );
                    // Update data-status on button so re-opening the modal shows correct value
                    $(currentBtnRef).data('status', newStatus).data('remarks', newRemark);
                }
                Swal.fire({ icon: 'success', title: 'Saved', timer: 1000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to save.' });
            }
        })
        .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' }))
        .finally(() => btn.prop('disabled', false).text('Save'));
    });
});
</script>
</body>
</html>
