<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

/* ── Filters ─────────────────────────────────────────────────────────────── */
$filterAction   = trim(filter_input(INPUT_GET, 'action',    FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$filterStatus   = trim(filter_input(INPUT_GET, 'status',    FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$filterFrom     = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
$filterTo       = filter_input(INPUT_GET, 'date_to',   FILTER_SANITIZE_SPECIAL_CHARS) ?? '';

$allowedActions  = ['Payment Updated', 'Registration Cancelled', 'Participant Info Updated'];
$allowedStatuses = ['pending', 'paid', 'cancelled', 'promissory note', 'free'];
if (!in_array($filterAction,  $allowedActions,  true)) $filterAction  = '';
if (!in_array($filterStatus,  $allowedStatuses, true)) $filterStatus  = '';

/* ── Query ───────────────────────────────────────────────────────────────── */
try {
    $where  = [];
    $params = [];

    if ($filterAction) {
        $where[]            = 'pl.action = :action';
        $params[':action']  = $filterAction;
    }
    if ($filterStatus) {
        $where[]            = 'LOWER(pl.new_status) = LOWER(:status)';
        $params[':status']  = $filterStatus;
    }
    if ($filterFrom) {
        $where[]            = 'DATE(pl.updated_at) >= :dfrom';
        $params[':dfrom']   = $filterFrom;
    }
    if ($filterTo) {
        $where[]            = 'DATE(pl.updated_at) <= :dto';
        $params[':dto']     = $filterTo;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $conn->prepare("
        SELECT  pl.id,
                pl.registration_id,
                pl.action,
                pl.old_status,
                pl.new_status,
                pl.updated_by,
                pl.updated_at,
                CONCAT(p.lname, ', ', p.fname,
                       IF(p.mname <> '', CONCAT(' ', LEFT(p.mname, 1), '.'), '')) AS participant_name,
                p.agency_name,
                t.id         AS training_id,
                t.name       AS training_name,
                t.start_date,
                t.end_date
        FROM    otrs_payment_logs  pl
        JOIN    otrs_registrations r  ON r.id  = pl.registration_id
        JOIN    otrs_participants   p  ON p.id  = r.participant_id
        JOIN    otrs_trainings      t  ON t.id  = r.training_id
        $whereClause
        ORDER BY pl.updated_at DESC, pl.id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[payment_logs] ' . $e->getMessage());
    $rows = [];
}

function fmtDate(?string $s, ?string $e): string {
    if (!$s || $s === '0000-00-00') return '—';
    try {
        $sd = new DateTimeImmutable($s);
        $ed = ($e && $e !== '0000-00-00') ? new DateTimeImmutable($e) : null;
        if (!$ed || $sd->format('Y-m-d') === $ed->format('Y-m-d')) return $sd->format('M j, Y');
        if ($sd->format('Y-m') === $ed->format('Y-m')) return $sd->format('M j') . '–' . $ed->format('j, Y');
        return $sd->format('M j') . ' – ' . $ed->format('M j, Y');
    } catch (Exception $ex) { return htmlspecialchars($s); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Status Audit Log – OTRS HRD</title>
<link href="../../assets/img/favicon.png" rel="icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
.status-badge {
    display: inline-block;
    padding: .2em .6em;
    border-radius: 4px;
    font-size: .72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.sb-paid       { background:#d1fae5; color:#065f46; }
.sb-pending    { background:#fef9c3; color:#854d0e; }
.sb-cancelled  { background:#fee2e2; color:#991b1b; }
.sb-promissory { background:#e0f2fe; color:#0369a1; }
.sb-free       { background:#ede9fe; color:#5b21b6; }
.sb-profile    { background:#f1f5f9; color:#334155; }
.action-badge  { display:inline-flex; align-items:center; gap:.3rem;
                 font-size:.72rem; font-weight:600; padding:.2em .6em;
                 border-radius:4px; }
.ab-updated    { background:#fef3c7; color:#92400e; }
.ab-cancelled  { background:#fee2e2; color:#991b1b; }
.ab-profile    { background:#dbeafe; color:#1d4ed8; }
.arrow-icon    { color:#9ca3af; font-size:.9rem; }
</style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-amber"><i class="bi bi-clock-history"></i></div>
                <div>
                    <h1>Payment Audit Log</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Payment Logs</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body p-4">

                <div class="alert alert-info d-flex align-items-center gap-2 mb-3" style="font-size:.84rem;">
                    <i class="bi bi-info-circle-fill fs-5"></i>
                    <span>Every payment status change is recorded here — who changed it, from what, to what, and when.</span>
                </div>

                <!-- ── Filters ──────────────────────────────────────────── -->
                <form method="get" class="row g-2 align-items-end mb-3">
                    <div class="col-sm-2">
                        <label class="form-label fw-semibold mb-1" style="font-size:.82rem;">Action</label>
                        <select name="action" class="form-select form-select-sm">
                            <option value="">All Actions</option>
                            <?php foreach ($allowedActions as $a): ?>
                            <option value="<?php echo $a; ?>" <?php echo ($filterAction === $a) ? 'selected' : ''; ?>>
                                <?php echo $a; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label fw-semibold mb-1" style="font-size:.82rem;">New Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Statuses</option>
                            <?php foreach ($allowedStatuses as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo ($filterStatus === $s) ? 'selected' : ''; ?>>
                                <?php echo $s; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label fw-semibold mb-1" style="font-size:.82rem;">From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($filterFrom); ?>">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label fw-semibold mb-1" style="font-size:.82rem;">To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($filterTo); ?>">
                    </div>
                    <div class="col-sm-auto">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-funnel me-1"></i>Filter
                        </button>
                        <a href="payment_logs.php" class="btn btn-outline-secondary btn-sm ms-1">Clear</a>
                    </div>
                    <div class="col-sm-auto ms-auto align-self-center">
                        <span class="badge bg-secondary rounded-pill"><?php echo count($rows); ?> record<?php echo count($rows) !== 1 ? 's' : ''; ?></span>
                    </div>
                </form>

                <!-- ── Table ────────────────────────────────────────────── -->
                <table id="paymentLogTable" class="table table-bordered table-hover table-striped w-100" style="font-size:12.5px;">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">#</th>
                                <th>Participant</th>
                                <th>Agency</th>
                                <th>Training</th>
                                <th class="text-center">Date of Training</th>
                                <th class="text-center">Action</th>
                                <th class="text-center">Status Change</th>
                                <th>Updated By</th>
                                <th class="text-center">Updated At</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No payment log entries found for the selected filters.</td></tr>
                        <?php else: foreach ($rows as $i => $row):
                            $oldLower    = strtolower($row['old_status'] ?? '');
                            $newLower    = strtolower($row['new_status'] ?? '');
                            $badgeMap    = [
                                'paid'             => 'sb-paid',
                                'pending'          => 'sb-pending',
                                'cancelled'        => 'sb-cancelled',
                                'promissory note'  => 'sb-promissory',
                                'free'             => 'sb-free',
                                'profile_updated'  => 'sb-profile',
                            ];
                            $oldCls      = $badgeMap[$oldLower] ?? '';
                            $newCls      = $badgeMap[$newLower] ?? '';
                            $isProfile   = ($row['action'] === 'Participant Info Updated');
                            $actionCls   = $isProfile ? 'ab-profile'
                                         : (($row['action'] === 'Registration Cancelled') ? 'ab-cancelled' : 'ab-updated');
                            $actionIcon  = $isProfile ? 'bi-person-gear'
                                         : (($row['action'] === 'Registration Cancelled') ? 'bi-x-circle' : 'bi-pencil');
                            $updatedAt   = $row['updated_at']
                                ? date('M j, Y g:i A', strtotime($row['updated_at']))
                                : '—';
                        ?>
                            <tr>
                                <td class="text-center align-middle"><?php echo $i + 1; ?></td>
                                <td class="align-middle fw-semibold">
                                    <?php echo htmlspecialchars($row['participant_name']); ?>
                                </td>
                                <td class="align-middle" style="max-width:150px;white-space:normal;font-size:.82rem;">
                                    <?php echo htmlspecialchars($row['agency_name'] ?? '—'); ?>
                                </td>
                                <td class="align-middle" style="max-width:200px;white-space:normal;font-size:.82rem;">
                                    <a href="participants?id=<?php echo (int)$row['training_id']; ?>"
                                       class="text-decoration-none">
                                        <?php echo htmlspecialchars($row['training_name']); ?>
                                    </a>
                                </td>
                                <td class="text-center align-middle text-nowrap">
                                    <?php echo fmtDate($row['start_date'], $row['end_date']); ?>
                                </td>
                                <td class="text-center align-middle">
                                    <span class="action-badge <?php echo $actionCls; ?>">
                                        <i class="bi <?php echo $actionIcon; ?>"></i>
                                        <?php echo htmlspecialchars($row['action']); ?>
                                    </span>
                                </td>
                                <td class="text-center align-middle">
                                    <div class="d-flex align-items-center justify-content-center gap-1 flex-wrap">
                                        <span class="status-badge <?php echo $oldCls; ?>">
                                            <?php echo htmlspecialchars($row['old_status'] ?? '—'); ?>
                                        </span>
                                        <i class="bi bi-arrow-right arrow-icon"></i>
                                        <span class="status-badge <?php echo $newCls; ?>">
                                            <?php echo htmlspecialchars($row['new_status']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="align-middle" style="font-size:.82rem;">
                                    <?php echo htmlspecialchars($row['updated_by'] ?? '—'); ?>
                                </td>
                                <td class="text-center align-middle text-nowrap"
                                    data-order="<?php echo htmlspecialchars($row['updated_at'] ?? ''); ?>">
                                    <?php echo $updatedAt; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>

            </div>
        </div>
    </section>
</main>

<?php imis_include('footer_db') ?>
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="../../assets/js/main.js"></script>
<script>
$(function () {
    <?php if (!empty($rows)): ?>
    $('#paymentLogTable').DataTable({
        pageLength: 25,
        order:      [[8, 'desc']],
        autoWidth:  false,
        columnDefs: [{
            targets:   0,
            orderable: false,
            render: function (data, type, row, meta) {
                return meta.row + meta.settings._iDisplayStart + 1;
            }
        }],
        dom: "<'row'<'col-sm-6'l><'col-sm-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-5'i><'col-sm-7'p>>",
        language: {
            search: '', searchPlaceholder: 'Search…',
            paginate: { next: '<i class="bi bi-chevron-right"></i>', previous: '<i class="bi bi-chevron-left"></i>' }
        }
    });
    <?php endif; ?>
});
</script>
</body>
</html>
