<?php
require_once __DIR__ . '/../../includes/bootstrap.php'; 

$location = $_GET['location'];

// NEW: read start_date and end_date instead of legacy 'date'
$start_date = $_GET['start_date'] ?? null;
$end_date   = $_GET['end_date']   ?? null;

$locationText = '';
if ($location === '' || $location === 'hrd') {
    $locationText = 'CSC RO VIII - Human Resource Division';
} elseif ($location === 'lfoi') {
    $locationText = 'CSC Field Office - Leyte I';
} elseif ($location === 'lfoii') {
    $locationText = 'CSC Field Office - Leyte II';
} elseif ($location === 'bfo') {
    $locationText = 'CSC Field Office - Biliran';
} elseif ($location === 'wlso') {
    $locationText = 'CSC Satellite Office - Western Leyte';
} elseif ($location === 'slfo') {
    $locationText = 'CSC Field Office - Southern Leyte';
} elseif ($location === 'sfo') {
    $locationText = 'CSC Field Office - Samar';
} elseif ($location === 'esfo') {
    $locationText = 'CSC Field Office - Eastern Samar';
} elseif ($location === 'nsfo') {
    $locationText = 'CSC Field Office - Northern Samar';
}

$trainingId = $_GET['id'];

// NEW: fetch start_date and end_date from trainings instead of legacy 'date'
$stmt = $conn->prepare("SELECT name, start_date, end_date FROM otrs_trainings WHERE id = :id");
$stmt->bindParam(':id', $trainingId);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);

// Helper: format a date range for display (e.g. "May 5–9, 2026")
function formatDateRange($start, $end) {
    $s = new DateTime($start);
    $e = new DateTime($end);
    if ($s->format('mY') === $e->format('mY')) {
        return $s->format('F j') . '–' . $e->format('j, Y');
    }
    return $s->format('F j') . '–' . $e->format('F j, Y');
}

$dateRangeDisplay = formatDateRange($result['start_date'], $result['end_date']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">

<!-- NEW: page title uses formatted date range -->
<title><?php echo htmlspecialchars($result['name']); ?> - <?php echo $dateRangeDisplay; ?> - <?php echo $locationText; ?></title>
<meta content="" name="description">
<meta content="" name="keywords">

<link href="../assets/img/favicon.png" rel="icon">
<link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">
<link href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
<link href="../assets/vendor/remixicon/remixicon.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.11.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.min.css">
<link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css" rel="stylesheet">
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
<link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<style>
    #trainingDetailsTable { transition: opacity 0.5s ease; }
    label, input[type="text"], input[type="date"], select.form-select, .form-control { font-size: 14px; }
    .accordion-button { background-color: #E8F2FD; }
    .accordion-button:not(.collapsed) { background-color: #E8F2FD; }
    .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; line-height: 1.5; border-radius: 0.2rem; }
    .sidebar { display: none; }
    .main { margin-left: 0 !important; }
</style>
</head>

<body>

<?php imis_include('header_js') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-blue"><i class="bi bi-clipboard-data-fill"></i></div>
                <div>
                    <h1>Monitoring — Participant Status</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index?location=<?php echo htmlspecialchars($location); ?>">Home</a></li>
                            <li class="breadcrumb-item"><a href="index_trainings?location=<?php echo htmlspecialchars($location); ?>">Training List</a></li>
                            <li class="breadcrumb-item cell-ellipsis" style="max-width:240px;"><?php echo htmlspecialchars($result['name']); ?></li>
                            <li class="breadcrumb-item active"><?php echo $dateRangeDisplay; ?></li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="index?location=<?php echo $location ?>" class="otrs-btn-back">
                <i class="bi bi-arrow-left-circle"></i> Go Back
            </a>
        </div>
    </div>

    <section class="section">
        <div class="participant">
            <div class="otrs-card">
                <div class="otrs-card-body" style="overflow-x: auto;">
                    <?php
                    try {
                        if (isset($_GET['id'])) {
                            $trainingId = $_GET['id'];

                            $stmt = $conn->prepare("SELECT * FROM otrs_trainings WHERE id = :id");
                            $stmt->bindParam(':id', $trainingId);
                            $stmt->execute();
                            $training = $stmt->fetch(PDO::FETCH_ASSOC);

                            // NEW: validate start_date and end_date instead of legacy 'date'
                            if (empty($start_date) || empty($end_date)) {
                                echo 'Training date range is not provided.';
                                exit;
                            }

                            // NEW: query participants using training_start_date / training_end_date
                            if (empty($_GET['location'])) {
                                $stmt = $conn->prepare("
                                    SELECT * FROM otrs_participants
                                    WHERE training = :training
                                    AND training_start_date = :start_date
                                    AND training_end_date   = :end_date
                                    AND status IN ('paid', 'promissory note')
                                ");
                                $stmt->bindParam(':training',    $training['name']);
                                $stmt->bindParam(':start_date',  $start_date);
                                $stmt->bindParam(':end_date',    $end_date);
                                $stmt->execute();
                                $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            } else {
                                $location = $_GET['location'];
                                $stmt = $conn->prepare("
                                    SELECT * FROM otrs_participants
                                    WHERE training = :training
                                    AND location = :location
                                    AND training_start_date = :start_date
                                    AND training_end_date   = :end_date
                                    AND status IN ('paid', 'promissory note')
                                ");
                                $stmt->bindParam(':training',    $training['name']);
                                $stmt->bindParam(':location',    $location);
                                $stmt->bindParam(':start_date',  $start_date);
                                $stmt->bindParam(':end_date',    $end_date);
                                $stmt->execute();
                                $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            }

                            $filterOptions = ['All', 'paid', 'pending', 'promissory note', 'cancelled', 'not qualified'];
                            $filter = isset($_GET['filter']) ? $_GET['filter'] : '';

                            echo '<div class="col-md-8 d-flex align-items-center">';
                            echo '<div class="col-1"><label for="filter" class="form-label">Filter by:</label></div>';
                            echo '<div class="ml-1"><select id="filter" name="filter" class="form-select" onchange="applyFilter(this.value)">';
                            foreach ($filterOptions as $option) {
                                $selected = ($filter == $option) ? 'selected' : '';
                                echo '<option value="' . $option . '" ' . $selected . '>' . $option . '</option>';
                            }
                            echo '</select></div>';
                            echo '<button id="exportButton" class="btn btn-success btn-sm ms-2"><i class="bi bi-file-earmark-excel"></i> Export to Excel</button>';
                            echo '</div><p></p>';

                            // NEW: use $dateRangeDisplay in table header
                            echo '<form id="participantForm">';
                            echo '<table id="participantsList" class="table table-bordered table-hover table-striped table-responsive" style="font-size: 14px; width: 100%">';
                            echo '<thead>
                                    <tr>
                                        <th rowspan="3" style="text-align:center;vertical-align:middle">#</th>
                                        <th rowspan="3" style="text-align:center;vertical-align:middle">Name of Participant</th>
                                        <th rowspan="3" style="text-align:center;vertical-align:middle">Payment Status</th>
                                        <th rowspan="3" style="text-align:center;vertical-align:middle">Download</th>
                                        <th colspan="10" style="text-align:center;vertical-align:middle">
                                            Monitoring Output Submissions for ' . htmlspecialchars($training['name']) . ' - ' . $dateRangeDisplay . '
                                        </th>
                                        <th rowspan="3" style="text-align:center;vertical-align:middle">Certificate of<br>Completion</th>
                                    </tr>
                                    <tr>
                                        <th colspan="2" style="text-align:center;vertical-align:middle">Day 1</th>
                                        <th colspan="2" style="text-align:center;vertical-align:middle">Day 2</th>
                                        <th colspan="2" style="text-align:center;vertical-align:middle">Day 3</th>
                                        <th colspan="2" style="text-align:center;vertical-align:middle">Day 4</th>
                                        <th colspan="2" style="text-align:center;vertical-align:middle">Day 5</th>
                                    </tr>
                                    <tr>
                                        <th style="text-align:center;vertical-align:middle">Status</th>
                                        <th style="text-align:center;vertical-align:middle">File</th>
                                        <th style="text-align:center;vertical-align:middle">Status</th>
                                        <th style="text-align:center;vertical-align:middle">File</th>
                                        <th style="text-align:center;vertical-align:middle">Status</th>
                                        <th style="text-align:center;vertical-align:middle">File</th>
                                        <th style="text-align:center;vertical-align:middle">Status</th>
                                        <th style="text-align:center;vertical-align:middle">File</th>
                                        <th style="text-align:center;vertical-align:middle">Status</th>
                                        <th style="text-align:center;vertical-align:middle">File</th>
                                    </tr>
                                </thead>';

                            echo '<tbody>';
                            $count = 0;

                            function buildFilePath($dbFilePath) {
                                if (empty($dbFilePath) || trim($dbFilePath) == 'outputs/') return '';
                                if (preg_match('/^https?:\/\//i', $dbFilePath)) return $dbFilePath;
                                $baseUrl   = 'https://otrs.cscro8.com/';
                                $cleanPath = ltrim($dbFilePath, '/');
                                $encodedPath = implode('/', array_map('rawurlencode', explode('/', $cleanPath)));
                                return $baseUrl . $encodedPath;
                            }

                            foreach ($participants as $participant) {
                                $count++;
                                $participantId   = $participant['id'];
                                $participantName = htmlspecialchars($participant['fname'] . ' ' . $participant['mname'] . ' ' . $participant['lname'], ENT_QUOTES, 'UTF-8');
                                $trainingName    = htmlspecialchars($training['name'], ENT_QUOTES, 'UTF-8');

                                // NEW: pass formatted date range to download button instead of legacy date
                                $dateRangeAttr = htmlspecialchars($dateRangeDisplay, ENT_QUOTES, 'UTF-8');

                                $hasFiles = !empty($participant['day1_file']) ||
                                            !empty($participant['day2_file']) ||
                                            !empty($participant['day3_file']) ||
                                            !empty($participant['day4_file']) ||
                                            !empty($participant['day5_file']) ||
                                            !empty($participant['cert_file']);

                                $buttonState = $hasFiles ? '' : 'disabled';

                                echo '<tr>';
                                echo '<td>' . $count . '</td>';
                                echo '<td>' . htmlspecialchars($participant['fname'] . ' ' . $participant['mname'] . ' ' . $participant['lname']) . '</td>';
                                echo '<td style="text-align:center;vertical-align:middle">' . htmlspecialchars($participant['status']) . '</td>';

                                echo '<td style="text-align:center;vertical-align:middle">';
                                echo '<a href="#" data-id="' . $participantId . '" data-participant-name="' . $participantName . '" data-training-name="' . $trainingName . '" data-date-training="' . $dateRangeAttr . '" class="btn btn-primary btn-sm download-btn ' . $buttonState . '"><i class="bi bi-download"></i></a>';
                                echo '</td>';

                                // Days 1–4 (same logic as before)
                                foreach (['day1_file', 'day2_file', 'day3_file', 'day4_file'] as $i => $field) {
                                    $dayLabel  = 'Day ' . ($i + 1) . ' File';
                                    $status    = empty($participant[$field]) ? 'pending' : 'Submitted';
                                    $badgeClass = ($status === 'pending') ? 'bg-primary' : 'bg-success';
                                    echo '<td style="text-align:center;vertical-align:middle"><span class="badge ' . $badgeClass . '">' . $status . '</span></td>';
                                    echo '<td style="text-align:center;vertical-align:middle">';
                                    $fp = buildFilePath($participant[$field]);
                                    if ($fp) echo '<a href="' . $fp . '" target="_blank" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-pdf-fill" data-bs-toggle="tooltip" title="' . $dayLabel . '"></i></a>';
                                    echo '</td>';
                                }

                                // Day 5
                                if ($training['name'] === 'Supervisory Development Course (SDC), Track 1') {
                                    echo '<td style="text-align:center;vertical-align:middle"><span class="badge bg-danger">Not Applicable</span></td>';
                                    echo '<td style="text-align:center;vertical-align:middle"><span class="badge bg-danger">Not Applicable</span></td>';
                                } else {
                                    $status    = empty($participant['day5_file']) ? 'pending' : 'Submitted';
                                    $badgeClass = ($status === 'pending') ? 'bg-primary' : 'bg-success';
                                    echo '<td style="text-align:center;vertical-align:middle"><span class="badge ' . $badgeClass . '">' . $status . '</span></td>';
                                    echo '<td style="text-align:center;vertical-align:middle">';
                                    $fp = buildFilePath($participant['day5_file']);
                                    if ($fp) echo '<a href="' . $fp . '" target="_blank" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-pdf-fill" data-bs-toggle="tooltip" title="Day 5 File"></i></a>';
                                    echo '</td>';
                                }

                                // Certificate
                                echo '<td style="text-align:center;vertical-align:middle">';
                                $fp = buildFilePath($participant['cert_file']);
                                if ($fp) echo '<a href="' . $fp . '" target="_blank" class="btn btn-danger btn-sm"><i class="bi bi-file-earmark-pdf-fill" data-bs-toggle="tooltip" title="Certificate"></i></a>';
                                echo '</td>';

                                echo '</tr>';
                            }

                            echo '</tbody></table></form>';
                        } else {
                            echo 'Invalid training ID.';
                        }
                    } catch (PDOException $e) {
                        echo 'Connection failed: ' . $e->getMessage();
                    }
                    ?>
                </div>
            </div>
        </div>
    </section>
</main>

<?php imis_include('footer') ?>
<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script type="text/javascript" src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>
<script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>

<script>
$(document).ready(function () {
    function isMobileView() { return $(window).width() <= 767; }

    var table = $('#participantsList').DataTable({
        scrollX: true,
        fixedHeader: { headerOffset: 50 },
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        fixedColumns: { left: isMobileView() ? 0 : 4 },
        buttons: [{ extend: 'excel', text: 'Export to Excel', filename: getExportFilename() }],
        language: {
            search: '<i class="bi bi-search"></i>',
            searchPlaceholder: 'Search',
            lengthMenu: 'Show _MENU_ entries',
            paginate: { next: '<i class="bi bi-chevron-right"></i>', previous: '<i class="bi bi-chevron-left"></i>' }
        },
        lengthMenu: [10, 50, 100],
        pageLength: 10,
    });

    $(window).on('resize', function () { table.fixedColumns().update(); });
    $('#exportButton').click(function () { table.button('.buttons-excel').trigger(); });

    function getExportFilename() {
        var location = getParameterByName('location');
        var today = new Date();
        var d = (today.getMonth()+1).toString().padStart(2,'0') + '-' + today.getDate().toString().padStart(2,'0') + '-' + today.getFullYear();
        return '<?php echo addslashes($result['name']); ?>' + '_' + location + '_' + d;
    }

    function getParameterByName(name) {
        name = name.replace(/[\[\]]/g, '\\$&');
        var results = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)').exec(window.location.href);
        if (!results) return null;
        if (!results[2]) return '';
        return decodeURIComponent(results[2].replace(/\+/g, ' '));
    }
});
</script>

<script>
function applyFilter(filterValue) {
    const urlParams = new URLSearchParams(window.location.search);
    if (filterValue === 'All') { urlParams.delete('filter'); } else { urlParams.set('filter', filterValue); }
    window.location.search = urlParams.toString();
}
document.addEventListener('DOMContentLoaded', function () {
    const filterDropdown = document.getElementById('filter');
    if (filterDropdown) filterDropdown.value = "<?php echo htmlspecialchars($filter ?? ''); ?>" || 'All';
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.download-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (this.classList.contains('disabled')) {
                Swal.fire({ title: 'Cannot Download', text: 'No files are available for download.', icon: 'info', confirmButtonText: 'OK' });
                return;
            }
            const participantId   = this.getAttribute('data-id');
            const participantName = this.getAttribute('data-participant-name');
            const trainingName    = this.getAttribute('data-training-name');
            const dateTraining    = this.getAttribute('data-date-training');

            Swal.fire({
                title: 'Are you sure?',
                html: `Do you want to download all training outputs for <b>${trainingName}</b> - ${dateTraining} for <b>${participantName}</b>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, download',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) window.location.href = `dl_participant_output.php?id=${participantId}`;
            });
        });
    });
});
</script>
</body>
</html>