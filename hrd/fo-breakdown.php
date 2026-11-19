<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

$location = trim(filter_input(INPUT_GET, 'location', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');

$locationMap = [
    'hrd'   => 'CSC RO VIII - Human Resource Division',
    'lfoi'  => 'CSC Field Office - Leyte I',
    'lfoii' => 'CSC Field Office - Leyte II',
    'slfo'  => 'CSC Field Office - Southern Leyte',
    'wlso'  => 'CSC Satellite Office - Western Leyte',
    'bfo'   => 'CSC Field Office - Biliran',
    'sfo'   => 'CSC Field Office - Samar',
    'esfo'  => 'CSC Field Office - Eastern Samar',
    'nsfo'  => 'CSC Field Office - Northern Samar',
    'ro6'   => 'CSC Regional Office VI',
    'ro7'   => 'CSC Regional Office VII',
];

$filterLabel = ($location && $location !== 'all' && isset($locationMap[$location]))
    ? $locationMap[$location]
    : 'Consolidated Data (All Offices)';

$rows       = [];
$queryError = null;

try {
    $sql = "
        SELECT t.id, t.name, t.start_date, t.end_date, t.slots,
               t.location AS venue, t.mode,
               COUNT(r.id)                                                       AS participant_count,
               COALESCE(SUM(r.payment_status IN ('pending','paid','promissory note','free')), 0) AS participant_status_count,
               COALESCE(SUM(r.payment_status = 'paid'),            0)            AS paid_count,
               COALESCE(SUM(r.payment_status = 'promissory note'), 0)            AS promissory_count,
               COALESCE(SUM(r.payment_status = 'pending'),         0)            AS pending_count,
               COALESCE(SUM(r.payment_status = 'free'),            0)            AS free_count,
               COALESCE(SUM(r.payment_status = 'cancelled'),       0)            AS cancelled_count
        FROM   otrs_trainings t
        LEFT JOIN otrs_registrations r ON r.training_id = t.id
        LEFT JOIN otrs_participants  p ON p.id = r.participant_id
    ";

    $params = [];
    if ($location && $location !== 'all') {
        $sql .= " WHERE p.location = :location";
        $params[':location'] = $location;
    }

    $sql .= " GROUP BY t.id ORDER BY t.start_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[fo-breakdown] ' . $e->getMessage());
    $queryError = true;
}

// Count rows per mode for tab badges
$modeCounts = ['all' => count($rows), 'Face to Face' => 0, 'Online' => 0, 'Hybrid' => 0];
foreach ($rows as $r) {
    $m = $r['mode'] ?? '';
    if (isset($modeCounts[$m])) $modeCounts[$m]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FO Breakdown – OTRS HRD</title>
  <link href="../../assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.0.1/css/buttons.dataTables.min.css" rel="stylesheet">
  <style>
    .mode-tab-badge {
      display: inline-flex; align-items: center; justify-content: center;
      background: #e2e8f0; color: #475569;
      font-size: .62rem; font-weight: 700;
      min-width: 18px; height: 18px; padding: 0 .38em;
      border-radius: 999px; margin-left: .35em;
    }
    .nav-tabs .nav-link.active .mode-tab-badge {
      background: #0077b6; color: #fff;
    }
    .badge-free {
      background: #dbeafe; color: #1e40af;
      font-size: .65rem; font-weight: 700;
      padding: .18em .52em; border-radius: 999px;
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
        <div class="otrs-ph-icon" style="background:linear-gradient(135deg,#0f4c81,#1a6baa);color:#fff;">
          <i class="bi bi-diagram-3-fill"></i>
        </div>
        <div>
          <h1>FO Breakdown</h1>
          <nav><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="index">HRD Dashboard</a></li><li class="breadcrumb-item active">FO Breakdown</li></ol></nav>
        </div>
      </div>

      <!-- Location filter + Export -->
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <label class="mb-0" style="font-size:.8rem;font-weight:600;color:#64748b;">Filter by Office:</label>
        <select id="locationFilter" class="form-select form-select-sm" style="min-width:220px;font-size:.8rem;">
          <option value="" <?php echo $location === '' ? 'selected' : ''; ?>>All Offices</option>
          <?php foreach ($locationMap as $code => $label): ?>
          <option value="<?php echo $code; ?>" <?php echo $location === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
          <?php endforeach; ?>
        </select>
        <button id="exportBtn" class="btn btn-sm btn-success d-flex align-items-center gap-1" style="font-size:.8rem;">
          <i class="bi bi-file-earmark-excel"></i> Export Excel
        </button>
      </div>
    </div>
  </div><!-- /pagetitle -->

  <section class="section">
    <div class="card">
      <div class="card-body pt-3">

        <?php if ($queryError): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Failed to load data. Please try again later.</div>
        <?php else: ?>

        <p class="mb-3" style="font-size:.82rem;color:#64748b;">
          <i class="bi bi-funnel me-1"></i>
          Showing: <strong><?php echo htmlspecialchars($filterLabel); ?></strong>
          &nbsp;&middot;&nbsp; <?php echo count($rows); ?> training<?php echo count($rows) !== 1 ? 's' : ''; ?>
        </p>

        <!-- ── Mode Tabs ───────────────────────────────────────────── -->
        <ul class="nav nav-tabs mb-3" id="modeTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active d-flex align-items-center gap-1" id="tab-all" data-bs-toggle="tab"
                    data-bs-target="#pane-all" data-mode="" type="button" role="tab">
              <i class="bi bi-grid-3x3-gap-fill"></i> All
              <span class="mode-tab-badge"><?php echo $modeCounts['all']; ?></span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link d-flex align-items-center gap-1" id="tab-f2f" data-bs-toggle="tab"
                    data-bs-target="#pane-f2f" data-mode="Face to Face" type="button" role="tab">
              <i class="bi bi-people-fill"></i> Face to Face
              <span class="mode-tab-badge"><?php echo $modeCounts['Face to Face']; ?></span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link d-flex align-items-center gap-1" id="tab-online" data-bs-toggle="tab"
                    data-bs-target="#pane-online" data-mode="Online" type="button" role="tab">
              <i class="bi bi-camera-video-fill"></i> Online
              <span class="mode-tab-badge"><?php echo $modeCounts['Online']; ?></span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link d-flex align-items-center gap-1" id="tab-hybrid" data-bs-toggle="tab"
                    data-bs-target="#pane-hybrid" data-mode="Hybrid" type="button" role="tab">
              <i class="bi bi-arrow-left-right"></i> Hybrid
              <span class="mode-tab-badge"><?php echo $modeCounts['Hybrid']; ?></span>
            </button>
          </li>
        </ul>

        <div class="table-responsive">
          <table id="breakdownTable" class="table table-bordered table-hover table-striped" style="font-size:.82rem;width:100%;">
            <thead>
              <tr>
                <th rowspan="2" class="text-center align-middle" style="min-width:200px;">Training Name</th>
                <th rowspan="2" class="text-center align-middle" style="min-width:160px;">Date</th>
                <th rowspan="2" class="text-center align-middle" style="min-width:160px;">Venue</th>
                <th rowspan="2" class="text-center align-middle">Slots</th>
                <th colspan="6" class="text-center align-middle"><?php echo htmlspecialchars($filterLabel); ?></th>
                <th rowspan="2" class="text-center align-middle">Action</th>
                <th rowspan="2">Mode</th>
              </tr>
              <tr>
                <th class="text-center">Total Pax</th>
                <th class="text-center">Paid</th>
                <th class="text-center">Promissory Note</th>
                <th class="text-center">Pending</th>
                <th class="text-center">Free</th>
                <th class="text-center">Cancelled</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
              $sd = $row['start_date'];
              $ed = (!empty($row['end_date']) && $row['end_date'] !== '0000-00-00') ? $row['end_date'] : $sd;
              if ($sd === $ed) {
                  $dateDisplay = date('F j, Y', strtotime($sd));
              } elseif (date('Y-m', strtotime($sd)) === date('Y-m', strtotime($ed))) {
                  $dateDisplay = date('F', strtotime($sd)) . ' ' . date('j', strtotime($sd)) . '–' . date('j, Y', strtotime($ed));
              } else {
                  $dateDisplay = date('F j, Y', strtotime($sd)) . ' – ' . date('F j, Y', strtotime($ed));
              }
              $freeCount = (int)$row['free_count'];
            ?>
            <tr>
              <td><?php echo htmlspecialchars($row['name']); ?></td>
              <td class="text-nowrap"><?php echo htmlspecialchars($dateDisplay); ?></td>
              <td><?php echo htmlspecialchars($row['venue'] ?: '—'); ?></td>
              <td class="text-center"><?php echo (int)$row['slots']; ?></td>
              <td class="text-center"><?php echo (int)$row['participant_status_count']; ?></td>
              <td class="text-center"><?php echo (int)$row['paid_count']; ?></td>
              <td class="text-center"><?php echo (int)$row['promissory_count']; ?></td>
              <td class="text-center"><?php echo (int)$row['pending_count']; ?></td>
              <td class="text-center">
                <?php if ($freeCount > 0): ?>
                <span class="badge-free"><?php echo $freeCount; ?></span>
                <?php else: ?>
                <span style="color:#cbd5e1;">0</span>
                <?php endif; ?>
              </td>
              <td class="text-center"><?php echo (int)$row['cancelled_count']; ?></td>
              <td class="text-center text-nowrap">
                <a href="participants.php?id=<?php echo (int)$row['id']; ?>&location=<?php echo htmlspecialchars($location); ?>"
                   class="btn btn-danger btn-sm" title="View Participants">
                  <i class="bi bi-list-ul"></i>
                </a>
              </td>
              <td><?php echo htmlspecialchars($row['mode'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php endif; ?>
      </div>
    </div>
  </section>
</main>

<?php imis_include('footer_db') ?>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="../../assets/js/main.js"></script>

<script>
$(function () {
    var locationCode = '<?php echo addslashes($location ?: 'all'); ?>';
    var today        = new Date();
    var dateStr      = (today.getMonth()+1) + '-' + today.getDate() + '-' + today.getFullYear();

    var table = $('#breakdownTable').DataTable({
        scrollX: true,
        columnDefs: [{ targets: -1, visible: false, searchable: true }],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        buttons: [{
            extend: 'excel',
            filename: function () {
                var mode = $('#modeTabs .nav-link.active').data('mode') || 'all';
                return locationCode + '_' + mode.replace(/\s+/g,'-').toLowerCase() + '_trng-brkdwn_' + dateStr;
            },
            exportOptions: { columns: ':not(:last-child):visible' }
        }],
        language: {
            search: '<i class="bi bi-search"></i>',
            searchPlaceholder: 'Search',
            lengthMenu: 'Show _MENU_ entries',
            paginate: {
                next: '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            }
        },
        lengthMenu: [10, 50, 100],
        pageLength: 10
    });

    // Hide the native DataTables Excel button; we drive it from #exportBtn
    table.buttons('.buttons-excel').container().hide();

    // Tab → filter by mode column
    $('#modeTabs button').on('shown.bs.tab', function (e) {
        var mode = $(e.target).data('mode');
        if (mode === '' || mode === undefined) {
            table.column(-1).search('').draw();
        } else {
            table.column(-1).search('^' + mode + '$', true, false).draw();
        }
    });

    // Export button
    $('#exportBtn').on('click', function () {
        table.button('.buttons-excel').trigger();
    });

    // Location filter dropdown
    $('#locationFilter').on('change', function () {
        var val = $(this).val();
        window.location.href = 'fo-breakdown' + (val ? '?location=' + encodeURIComponent(val) : '');
    });
});
</script>
</body>
</html>
