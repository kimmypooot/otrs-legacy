<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

try {
    $stmt = $conn->query("
        SELECT
            r.id          AS reg_id,
            p.fname, p.mname, p.lname,
            p.email,
            p.location,
            p.agency_name,
            r.payment_status,
            r.registered_at,
            t.id          AS training_id,
            t.name        AS training_name,
            t.start_date,
            t.end_date
        FROM  otrs_registrations r
        JOIN  otrs_participants   p ON p.id = r.participant_id
        JOIN  otrs_trainings      t ON t.id = r.training_id
        WHERE r.payment_status = 'pending'
          AND (r.proof_file IS NULL OR r.proof_file = '')
          AND r.registration_status NOT IN ('cancelled')
          AND t.training_status NOT IN ('completed', 'cancelled')
        ORDER BY t.start_date ASC, p.lname ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[pending_payments] ' . $e->getMessage());
    $rows = [];
}

$locationMap = [
    'hrd'   => 'HRD',   'lfoi'  => 'LFO I',   'lfoii' => 'LFO II',
    'bfo'   => 'BFO',   'wlso'  => 'WLSO',    'slfo'  => 'SLFO',
    'sfo'   => 'SFO',   'esfo'  => 'ESFO',    'nsfo'  => 'NSFO',
    'ro6'   => 'RO VI', 'ro7'   => 'RO VII',
];

function fmtDate(?string $start, ?string $end): string {
    if (!$start || $start === '0000-00-00') return '—';
    try {
        $s = new DateTimeImmutable($start);
        $e = ($end && $end !== '0000-00-00') ? new DateTimeImmutable($end) : null;
        if (!$e || $s->format('Y-m-d') === $e->format('Y-m-d')) return $s->format('M j, Y');
        if ($s->format('Y-m') === $e->format('Y-m')) return $s->format('M j') . '–' . $e->format('j, Y');
        return $s->format('M j') . ' – ' . $e->format('M j, Y');
    } catch (Exception $ex) {
        return htmlspecialchars($start);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pending Proofs of Payment – OTRS HRD</title>
<link href="../../assets/img/favicon.png" rel="icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">
    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-red"><i class="bi bi-receipt"></i></div>
                <div>
                    <h1>Pending Proofs of Payment</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Pending Payments</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="ts-badge ts-cancelled"><?php echo count($rows); ?> pending</span>
                <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-danger text-white fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-receipt"></i> Registrations Without Proof of Payment
                <small class="ms-auto fw-normal opacity-75">Active, non-cancelled trainings only</small>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                <table id="pendingTable" class="table table-bordered table-hover table-sm mb-0" style="font-size:13px;width:100%">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Participant</th>
                            <th>Office</th>
                            <th>Agency</th>
                            <th>Email</th>
                            <th>Training</th>
                            <th>Date</th>
                            <th>Registered</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $i => $row):
                        $name     = trim($row['fname'] . ' ' . ($row['mname'] ? $row['mname'][0] . '. ' : '') . $row['lname']);
                        $office   = $locationMap[strtolower($row['location'] ?? '')] ?? strtoupper($row['location'] ?? '—');
                        $dateStr  = fmtDate($row['start_date'], $row['end_date']);
                        $regDate  = $row['registered_at'] ? date('M j, Y', strtotime($row['registered_at'])) : '—';
                    ?>
                        <tr>
                            <td class="text-center"><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($name, ENT_QUOTES); ?></td>
                            <td><span class="badge bg-primary bg-opacity-10 text-primary"><?php echo htmlspecialchars($office, ENT_QUOTES); ?></span></td>
                            <td style="max-width:160px;white-space:normal;"><?php echo htmlspecialchars($row['agency_name'] ?? '—', ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars($row['email'] ?? '—', ENT_QUOTES); ?></td>
                            <td style="max-width:200px;white-space:normal;"><?php echo htmlspecialchars($row['training_name'], ENT_QUOTES); ?></td>
                            <td class="text-nowrap"><?php echo $dateStr; ?></td>
                            <td class="text-nowrap"><?php echo $regDate; ?></td>
                            <td class="text-center">
                                <a href="participants?id=<?php echo (int)$row['training_id']; ?>"
                                   class="btn btn-sm btn-outline-primary" title="View training participants">
                                    <i class="bi bi-people"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">No pending proofs of payment found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
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
$('#pendingTable').DataTable({
    order:      [[7, 'asc']],
    pageLength: 25,
    dom: "<'row'<'col-sm-6'l><'col-sm-6'f>>" +
         "<'row'<'col-sm-12'tr>>" +
         "<'row'<'col-sm-5'i><'col-sm-7'p>>",
    language: { search: '', searchPlaceholder: 'Search…' }
});
</script>
</body>
</html>
