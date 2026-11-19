<?php
/**
 * OTRS – Learning and Development | List of Trainings
 *
 * DEPENDENCIES:
 *  - includes/session.php  — $_SESSION['name'], $_SESSION['fo_rsu']
 *  - includes/connect.php  — provides $conn (PDO, utf8mb4, Asia/Manila tz)
 *  - imis_include.php      — imis_include() helper
 *
 * MIGRATION NOTES (Legacy → Normalized):
 *  - trainings              → otrs_trainings
 *  - participants           → otrs_participants  (profile)
 *                           + otrs_enrollments   (training bridge / payment status)
 *  - JOIN on t.name=p.training (string match) → FK otrs_enrollments.training_id = otrs_trainings.id
 *  - Dual-date hack (date/start_date)         → eliminated; otrs_trainings has start_date/end_date (DATE NOT NULL)
 *  - trainings.status_trng                    → otrs_trainings.training_status
 *  - participants.status (payment)            → otrs_enrollments.payment_status
 *  - participants.location                    → otrs_participants.location  (varchar 8, location code)
 *  - otrs_trainings.status guards visibility  (active | inactive | draft | archived)
 *  - getOtrsDb() factory removed              → reuses $conn from connect.php directly
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/connect.php';
include_once __DIR__ . '/../imis_include.php';

// ============================================================
// 1. PAGE / LOCATION CONFIGURATION
// ============================================================

const LOCATION_MAP = [
    'hrd'   => ['title' => 'Human Resource Division',              'breadcrumb' => 'HRD'],
    'lfoi'  => ['title' => 'CSC Field Office – Leyte I',           'breadcrumb' => 'LFOI'],
    'lfoii' => ['title' => 'CSC Field Office – Leyte II',          'breadcrumb' => 'LFOII'],
    'bfo'   => ['title' => 'CSC Field Office – Biliran',           'breadcrumb' => 'BFO'],
    'wlso'  => ['title' => 'CSC Satellite Office – Western Leyte', 'breadcrumb' => 'WLSO'],
    'slfo'  => ['title' => 'CSC Field Office – Southern Leyte',    'breadcrumb' => 'SLFO'],
    'sfo'   => ['title' => 'CSC Field Office – Samar',             'breadcrumb' => 'SFO'],
    'esfo'  => ['title' => 'CSC Field Office – Eastern Samar',     'breadcrumb' => 'ESFO'],
    'nsfo'  => ['title' => 'CSC Field Office – Northern Samar',    'breadcrumb' => 'NSFO'],
    'ro6'   => ['title' => 'CSC Regional Office VI',               'breadcrumb' => 'RO6'],
    'ro7'   => ['title' => 'CSC Regional Office VII',              'breadcrumb' => 'RO7'],
];

const TRAINING_TABS = [
    'ongoing'   => ['label' => 'Ongoing / Active',    'status' => 'Ongoing'],
    'completed' => ['label' => 'Completed',           'status' => 'Completed'],
    'cancelled' => ['label' => 'Cancelled Trainings', 'status' => 'Cancelled'],
];

$location = isset($_GET['location']) ? trim((string) $_GET['location']) : '';
if (!array_key_exists($location, LOCATION_MAP)) {
    $location = '';
}

$pageTitle      = 'Learning and Development';
$breadcrumbText = 'HRD';

if ($location !== '') {
    $pageTitle      = LOCATION_MAP[$location]['title'];
    $breadcrumbText = LOCATION_MAP[$location]['breadcrumb'];
}

// ============================================================
// 2. HELPERS
// ============================================================

function formatTrainingDateRange(string $startDate, string $endDate): string
{
    if ($startDate === '' || $startDate === '0000-00-00') return '—';
    try {
        $start = new DateTimeImmutable($startDate);
        $end   = (!empty($endDate) && $endDate !== '0000-00-00')
            ? new DateTimeImmutable($endDate)
            : $start;

        if ($start == $end)                                   return $start->format('F j, Y');
        if ($start->format('Y') !== $end->format('Y'))        return $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
        if ($start->format('m') !== $end->format('m'))        return $start->format('M j') . ' – ' . $end->format('M j, Y');
        return $start->format('F j') . '–' . $end->format('j, Y');
    } catch (Exception $e) {
        return htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8');
    }
}

function getStatusBadgeClass(string $status): string
{
    return match ($status) {
        'Completed'                      => 'badge bg-success',
        'Accepting Payments'             => 'badge bg-primary',
        'Scheduled Until Further Notice' => 'badge bg-warning text-dark',
        'Cancelled'                      => 'badge bg-danger',
        default                          => 'badge bg-secondary',
    };
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// 3. CORE RENDER FUNCTION
// ============================================================

function renderTrainingRows(string $statusFilter, string $locationFilter, PDO $conn): void
{
    try {
        $locationOnClause = $locationFilter !== '' ? 'AND p.location = :location' : '';

$sql = "
            SELECT
                t.id, t.name, t.start_date, t.end_date, t.location, t.mode, t.training_status,
                
                -- We add 'AND p.id IS NOT NULL' to ensure we only count enrollments where the participant matched the location filter
                COALESCE(SUM(CASE WHEN e.payment_status IN ('Pending','Paid','Promissory Note') AND p.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS participant_status_count,
                COALESCE(SUM(CASE WHEN e.payment_status = 'Paid'            AND p.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS paid_count,
                COALESCE(SUM(CASE WHEN e.payment_status = 'Promissory Note' AND p.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS promissory_count,
                COALESCE(SUM(CASE WHEN e.payment_status = 'Pending'         AND p.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN e.payment_status = 'Free'            AND p.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS free_count,
                COALESCE(SUM(CASE WHEN e.payment_status = 'Cancelled'       AND p.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS cancelled_count
                
            FROM otrs_trainings AS t
            LEFT JOIN otrs_enrollments  AS e ON e.training_id   = t.id
            LEFT JOIN otrs_participants AS p ON p.id = e.participant_id {$locationOnClause}
            WHERE t.training_status = :training_status AND t.status = 'active'
            GROUP BY t.id, t.name, t.start_date, t.end_date, t.location, t.mode, t.training_status
            ORDER BY t.start_date DESC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':training_status', $statusFilter, PDO::PARAM_STR);
        if ($locationFilter !== '') $stmt->bindValue(':location', $locationFilter, PDO::PARAM_STR);
        $stmt->execute();
        $rows    = $stmt->fetchAll();
        $counter = 1;

        if (empty($rows)) return;

        foreach ($rows as $row) {
            $trainingId  = (int) $row['id'];
            $displayDate = formatTrainingDateRange((string) $row['start_date'], (string) $row['end_date']);
            $badgeClass  = getStatusBadgeClass((string) $row['training_status']);
            $isCancelled = ($row['training_status'] === 'Cancelled');
            $hasPending  = ((int) $row['pending_count'] > 0);
            $locParam    = urlencode($locationFilter);
            $startParam  = urlencode((string) $row['start_date']);

            echo '<tr>';
            echo '<td class="text-center">' . $counter++ . '</td>';
            echo '<td>' . e((string) $row['name']) . '</td>';
            echo '<td>' . $displayDate . '</td>';
            echo '<td>' . e((string) $row['location']) . '</td>';
            echo '<td>' . e((string) $row['mode']) . '</td>';
            echo '<td><span class="' . $badgeClass . '">' . e((string) $row['training_status']) . '</span></td>';
            echo '<td class="text-center fw-semibold">' . (int) $row['participant_status_count'] . '</td>';
            echo '<td class="text-center">'             . (int) $row['paid_count']               . '</td>';
            echo '<td class="text-center">'             . (int) $row['promissory_count']          . '</td>';
            echo '<td class="text-center">'             . (int) $row['pending_count']             . '</td>';
            echo '<td class="text-center">'             . (int) $row['free_count']           . '</td>';
            echo '<td class="text-center">'             . (int) $row['cancelled_count']           . '</td>';

            echo '<td class="text-center">';
            printf(
                '<a href="unpaid_participants.php?id=%d&date=%s&location=%s"'
                . ' class="btn btn-primary btn-sm me-1 %s" title="Unpaid Participants">'
                . '<i class="bi bi-person-exclamation"></i></a>',
                $trainingId, $startParam, $locParam, $hasPending ? '' : 'disabled'
            );
            printf(
                '<a href="participants.php?id=%d&date=%s&location=%s"'
                . ' class="btn btn-success btn-sm participants-link me-1 %s" title="Master List of Participants">'
                . '<i class="bi bi-people-fill"></i></a>',
                $trainingId, $startParam, $locParam, $isCancelled ? 'disabled' : ''
            );
            printf(
                '<button type="button" class="btn btn-danger btn-sm view-graph-btn"'
                . ' title="View Payment Breakdown"'
                . ' data-training-id="%d" data-training-name="%s" data-training-date="%s"'
                . ' data-paid-count="%d" data-pending-count="%d" data-promissory-count="%d">'
                . '<i class="bi bi-pie-chart-fill"></i></button>',
                $trainingId, e((string) $row['name']), e($displayDate),
                (int) $row['paid_count'], (int) $row['pending_count'], (int) $row['promissory_count']
            );
            echo '</td>';
            echo '</tr>';
        }

    } catch (PDOException $ex) {
        error_log('[OTRS][index] DB error: ' . $ex->getMessage());
        echo '<tr><td colspan="12" class="text-danger text-center py-3">'
           . '<i class="bi bi-exclamation-triangle-fill me-1"></i>'
           . 'Error loading data. Please try again or contact the system administrator.'
           . '</td></tr>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>OTRS – <?= e((string) $_SESSION['name']) ?></title>

    <link href="assets/img/favicon.png" rel="icon">
    <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="assets/vendor/remixicon/remixicon.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">

    <style>
        /* ── Layout ────────────────────────────────────────────────── */
        .sidebar { display: none; }
        .main    { margin-left: 0 !important; }

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
            margin-bottom: 1rem;
        }
        .toolbar-divider {
            width: 1px; height: 20px;
            background: #d1d8e8;
            flex-shrink: 0; margin: 0 .05rem;
        }
        .toolbar-spacer { flex: 1 1 auto; }

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

        /* ── Tab strip ──────────────────────────────────────────────── */
        #trainingTabs {
            border-bottom: 2px solid #e0e8f5;
            gap: .15rem;
        }
        #trainingTabs .nav-link {
            font-size: .82rem; font-weight: 500;
            color: #6c757d; border: none;
            padding: .55rem 1.1rem;
            border-radius: 6px 6px 0 0;
            transition: background .15s, color .15s;
        }
        #trainingTabs .nav-link:hover:not(.active) {
            background: #eef3fb; color: #012970;
        }
        #trainingTabs .nav-link.active {
            font-weight: 700; color: #0077b6;
            background: #fff;
            border-top: 3px solid #0077b6;
            border-left: 1px solid #e0e8f5;
            border-right: 1px solid #e0e8f5;
            margin-bottom: -2px;
        }

        /* ── Summary card strip (above table) ───────────────────────── */
        .summary-strip {
            display: flex; flex-wrap: wrap; gap: .5rem;
            margin-bottom: .9rem;
        }
        .summary-chip {
            display: inline-flex; align-items: center; gap: .35rem;
            font-size: .74rem; font-weight: 600;
            padding: .28rem .7rem; border-radius: 20px;
            white-space: nowrap;
        }
        .chip-registered { background: #e8f0fe; color: #012970; }
        .chip-paid       { background: #d1e7dd; color: #0a3622; }
        .chip-promissory { background: #fff3cd; color: #664d03; }
        .chip-pending    { background: #cfe2ff; color: #084298; }
        .chip-cancelled  { background: #f8d7da; color: #58151c; }

        /* ── Table styling ──────────────────────────────────────────── */
        #trainingTabsContent table thead {
            background: #f0f5ff;
        }
        #trainingTabsContent table thead th {
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #2c3e6b;
            vertical-align: middle;
        }
        .action-col { min-width: 100px; }

        /* ── Page title header box ──────────────────────────────────── */
        .office-badge {
            display: inline-flex; align-items: center;
            gap: .4rem; font-size: .74rem; font-weight: 600;
            padding: .22rem .65rem; border-radius: 20px;
            background: #e8f0fe; color: #012970;
            margin-top: .3rem;
        }
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
                    <i class="bi bi-mortarboard fs-5 text-primary"></i>
                </div>
                <div>
                    <h1 class="mb-0" style="font-size:1.1rem;"><?= e($pageTitle) ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 mt-1">
                            <li class="breadcrumb-item">
                                <a href="../index_dashboard">Home</a>
                            </li>
                            <li class="breadcrumb-item"><?= e($breadcrumbText) ?></li>
                            <li class="breadcrumb-item active">List of Trainings</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </div>
    <!-- ── END PAGE TITLE ──────────────────────────────────────────────── -->

    <section class="section">
        <div class="card">
            <div class="card-body p-4">

                <!-- ── TOOLBAR ───────────────────────────────────────── -->
                <p class="section-label">
                    <i class="bi bi-sliders me-1"></i> Actions
                </p>

                <div class="toolbar d-flex justify-content-between align-items-center w-100">
                    <!-- Left Side: Export Button -->
                    <button id="exportButton" class="btn-f btn-f-success">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>

                    <!-- Right Side: Back to Dashboard Button -->
                    <button id="backDashboardButton" class="btn-f btn-f-primary">
                        <i class="bi bi-house-door"></i> Back to IMIS Dashboard
                    </button>
                </div>

                <!-- ── TAB NAVIGATION ─────────────────────────────────── -->
                <p class="section-label">
                    <i class="bi bi-table me-1"></i> Training Records
                </p>

                <ul class="nav nav-tabs" id="trainingTabs" role="tablist">
                    <?php $firstTab = true; ?>
                    <?php foreach (TRAINING_TABS as $tabId => $tab): ?>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link <?= $firstTab ? 'active' : '' ?>"
                                id="<?= e($tabId) ?>-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#<?= e($tabId) ?>"
                                type="button" role="tab"
                                aria-controls="<?= e($tabId) ?>"
                                aria-selected="<?= $firstTab ? 'true' : 'false' ?>">
                                <?= e($tab['label']) ?>
                            </button>
                        </li>
                        <?php $firstTab = false; ?>
                    <?php endforeach; ?>
                </ul>

                <!-- ── TAB PANELS ─────────────────────────────────────── -->
                <div class="tab-content mt-3" id="trainingTabsContent">

                    <?php $firstPanel = true; ?>
                    <?php foreach (TRAINING_TABS as $tabId => $tab): ?>

                        <div class="tab-pane fade <?= $firstPanel ? 'show active' : '' ?>"
                             id="<?= e($tabId) ?>"
                             role="tabpanel"
                             aria-labelledby="<?= e($tabId) ?>-tab">

                            <div>
                                <table
                                    id="table_<?= e($tabId) ?>"
                                    class="table table-bordered table-hover table-striped w-100"
                                    style="font-size:13px;">

                                    <thead>
                                        <tr>
                                            <th rowspan="2" class="align-middle text-center">#</th>
                                            <th rowspan="2" class="align-middle text-center">Name of Training</th>
                                            <th rowspan="2" class="align-middle text-center">Date</th>
                                            <th rowspan="2" class="align-middle text-center">Venue</th>
                                            <th rowspan="2" class="align-middle text-center">Mode</th>
                                            <th rowspan="2" class="align-middle text-center">Status</th>
                                            <th colspan="6" class="align-middle text-center">
                                                <?php if (!empty($_SESSION['fo_rsu'])): ?>
                                                    <span class="office-badge">
                                                        <i class="bi bi-building"></i>
                                                        <?= e((string) $_SESSION['fo_rsu']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </th>
                                            <th rowspan="2" class="align-middle text-center action-col">Action</th>
                                        </tr>
                                        <tr>
                                            <th class="text-center">Registered</th>
                                            <th class="text-center">Paid</th>
                                            <th class="text-center">Promis.</th>
                                            <th class="text-center">Pending</th>
                                            <th class="text-center">Free</th>
                                            <th class="text-center">Cancelled</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php renderTrainingRows($tab['status'], $location, $conn); ?>
                                    </tbody>

                                </table>
                            </div>

                        </div><!-- /.tab-pane -->

                        <?php $firstPanel = false; ?>
                    <?php endforeach; ?>

                </div><!-- /#trainingTabsContent -->

            </div><!-- /.card-body -->
        </div><!-- /.card -->
    </section>

</main>

<?php imis_include('footer_db') ?>

<a href="#" class="back-to-top d-flex align-items-center justify-content-center">
    <i class="bi bi-arrow-up-short"></i>
</a>

<!-- ── Payment Breakdown Modal ──────────────────────────────────────────── -->
<div class="modal fade" id="statisticsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#0077b6;">
                <h6 class="modal-title text-white fw-bold" id="statisticsModalLabel"></h6>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div id="chart"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger btn-sm"
                        data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Scripts ─────────────────────────────────────────────────────────── -->
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/apexcharts/3.40.0/apexcharts.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(function () {

    // ════════════════════════════════════════════════════
    // 1. HELPERS
    // ════════════════════════════════════════════════════

    function getExportFilename() {
        const loc   = new URLSearchParams(window.location.search).get('location') || 'hrd';
        const today = new Date();
        const mm    = String(today.getMonth() + 1).padStart(2, '0');
        const dd    = String(today.getDate()).padStart(2, '0');
        return `${loc}_trainings_${mm}-${today.getFullYear()}`;
    }

    // ════════════════════════════════════════════════════
    // 2. DATATABLES
    // ════════════════════════════════════════════════════

    /*
     * orderCellsTop: true — fix _DT_CellIndex TypeError on rowspan+colspan thead.
     * columnDefs (not columns[]) — fix TN/18 column-count mismatch.
     */
    const dtConfig = {
        scrollX       : false,
        autoWidth     : false,
        orderCellsTop : true,
        columnDefs: [
            { orderable: false, targets: [6, 7, 8, 9, 10, 11] }
        ],
        dom     : "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>"
                + "<'row'<'col-sm-12'tr>>"
                + "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        buttons : [{
            extend        : 'excel',
            text          : 'Export to Excel',
            filename      : getExportFilename,
            exportOptions : { columns: ':not(:last-child)' }
        }],
        language: {
            search           : '<i class="bi bi-search"></i>',
            searchPlaceholder: 'Search',
            lengthMenu       : 'Show _MENU_ entries',
            paginate: {
                next    : '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            },
            emptyTable  : '<span class="text-muted">No trainings found for this period.</span>',
            zeroRecords : 'No matching records found.'
        },
        lengthMenu : [10, 50, 100],
        pageLength : 10,
        order      : []
    };

    // Initialise every tab table
    const tableIds = ['#table_ongoing', '#table_completed', '#table_cancelled'];

    tableIds.forEach(function (selector) {
        if ($(selector).length) {
            const dt = $(selector).DataTable(dtConfig);
            dt.buttons('.buttons-excel').container().hide();
        }
    });

    // Re-adjust column widths when a hidden tab becomes visible
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const targetId    = $(e.target).data('bs-target');
        const tableInTab  = $(targetId).find('table');
        if (tableInTab.length && $.fn.dataTable.isDataTable(tableInTab)) {
            tableInTab.DataTable().columns.adjust();
        }
    });

    // Export button triggers the visible tab's DataTable export
    $('#exportButton').on('click', function () {
        const $activeTable = $('.tab-pane.active table');
        if ($activeTable.length && $.fn.dataTable.isDataTable($activeTable)) {
            $activeTable.DataTable().button('.buttons-excel').trigger();
        }
    });

    // ════════════════════════════════════════════════════
    // 3. PAYMENT BREAKDOWN PIE CHART
    // ════════════════════════════════════════════════════

    let chartInstance = null;

    $(document).on('click', '.view-graph-btn', function () {
        const $btn            = $(this);
        const trainingName    = $btn.data('training-name');
        const trainingDate    = $btn.data('training-date');
        const paidCount       = parseInt($btn.data('paid-count'),       10) || 0;
        const pendingCount    = parseInt($btn.data('pending-count'),    10) || 0;
        const promissoryCount = parseInt($btn.data('promissory-count'), 10) || 0;

        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
            document.getElementById('chart').innerHTML = '';
        }

        const total = paidCount + pendingCount + promissoryCount;

        chartInstance = new ApexCharts(document.querySelector('#chart'), {
            chart  : { height: 300, type: 'pie', toolbar: { show: false } },
            labels : ['Paid', 'Pending', 'Promissory Note'],
            series : [paidCount, pendingCount, promissoryCount],
            colors : ['#198754', '#0d6efd', '#ffc107'],
            tooltip: {
                y: {
                    formatter: val => {
                        const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                        return `${val} participant${val !== 1 ? 's' : ''} (${pct}%)`;
                    }
                }
            },
            legend: { position: 'bottom', fontSize: '13px' },
            noData: { text: 'No participant data available.' },
            responsive: [{
                breakpoint: 480,
                options: { chart: { width: 240 }, legend: { position: 'bottom' } }
            }]
        });

        chartInstance.render();

        $('#statisticsModalLabel').html(
            `<i class="bi bi-pie-chart-fill me-2 opacity-75"></i>${trainingName}`
            + `<br><small class="fw-normal opacity-75">${trainingDate}</small>`
        );

        new bootstrap.Modal(document.getElementById('statisticsModal')).show();
    });

    document.getElementById('statisticsModal').addEventListener('hidden.bs.modal', function () {
        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
            document.getElementById('chart').innerHTML = '';
        }
    });

    // ════════════════════════════════════════════════════
    // 4. CANCELLED TRAINING CLICK GUARD
    // ════════════════════════════════════════════════════

    $(document).on('click', '.participants-link.disabled', function (e) {
        e.preventDefault();
        Swal.fire({
            icon              : 'warning',
            title             : 'Training Cancelled',
            text              : 'This training has been cancelled by the CSC RO VIII – Human Resource Division. '
                              + 'The master list is not accessible for cancelled trainings.',
            confirmButtonText : 'Okay',
            confirmButtonColor: '#198754'
        });
    });

    // ════════════════════════════════════════════════════
    // 5. TOOLTIPS
    // ════════════════════════════════════════════════════

    document.querySelectorAll('[data-bs-toggle="tooltip"], [title]').forEach(function (el) {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
    });

});
</script>

</body>
</html>