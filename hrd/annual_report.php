<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

$selectedYear = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?: (int) date('Y');

try {
    // Available years
    $yearsStmt = $conn->query(
        "SELECT DISTINCT YEAR(start_date) AS yr FROM otrs_trainings
         WHERE start_date IS NOT NULL AND start_date <> '0000-00-00'
         ORDER BY yr DESC"
    );
    $years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

    // Unique participants trained in selected year
    $uniqueStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT r.participant_id) AS total
         FROM otrs_registrations r
         JOIN otrs_trainings t ON t.id = r.training_id
         WHERE YEAR(t.start_date) = :yr
           AND r.registration_status NOT IN ('cancelled')"
    );
    $uniqueStmt->execute([':yr' => $selectedYear]);
    $uniqueParticipants = (int) $uniqueStmt->fetchColumn();

    // Total registrations (non-cancelled)
    $totalRegStmt = $conn->prepare(
        "SELECT COUNT(*) FROM otrs_registrations r
         JOIN otrs_trainings t ON t.id = r.training_id
         WHERE YEAR(t.start_date) = :yr
           AND r.registration_status NOT IN ('cancelled')"
    );
    $totalRegStmt->execute([':yr' => $selectedYear]);
    $totalRegistrations = (int) $totalRegStmt->fetchColumn();

    // Total trainings conducted
    $totalTrngStmt = $conn->prepare(
        "SELECT COUNT(*) FROM otrs_trainings
         WHERE YEAR(start_date) = :yr AND training_status NOT IN ('cancelled')"
    );
    $totalTrngStmt->execute([':yr' => $selectedYear]);
    $totalTrainings = (int) $totalTrngStmt->fetchColumn();

    // Breakdown by field office
    $locationMap = [
        'hrd'   => 'HRD',
        'lfoi'  => 'LFO I',
        'lfoii' => 'LFO II',
        'slfo'  => 'SLFO',
        'wlso'  => 'WLSO',
        'bfo'   => 'BFO',
        'sfo'   => 'SFO',
        'esfo'  => 'ESFO',
        'nsfo'  => 'NSFO',
        'ro6'   => 'RO VI',
        'ro7'   => 'RO VII',
    ];

    $foStmt = $conn->prepare(
        "SELECT p.location,
                COUNT(*) AS total,
                SUM(r.payment_status = 'paid') AS paid,
                SUM(r.payment_status = 'promissory note') AS promissory,
                SUM(r.payment_status = 'pending') AS pending
         FROM otrs_registrations r
         JOIN otrs_participants  p ON p.id = r.participant_id
         JOIN otrs_trainings     t ON t.id = r.training_id
         WHERE YEAR(t.start_date) = :yr
           AND r.registration_status NOT IN ('cancelled')
         GROUP BY p.location
         ORDER BY total DESC"
    );
    $foStmt->execute([':yr' => $selectedYear]);
    $foBreakdown = $foStmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly trend
    $monthlyStmt = $conn->prepare(
        "SELECT MONTH(t.start_date) AS month,
                COUNT(DISTINCT r.participant_id) AS unique_pax,
                COUNT(*) AS total_regs
         FROM otrs_registrations r
         JOIN otrs_trainings t ON t.id = r.training_id
         WHERE YEAR(t.start_date) = :yr
           AND r.registration_status NOT IN ('cancelled')
         GROUP BY MONTH(t.start_date)
         ORDER BY month"
    );
    $monthlyStmt->execute([':yr' => $selectedYear]);
    $monthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[annual_report] ' . $e->getMessage());
    $uniqueParticipants = $totalRegistrations = $totalTrainings = 0;
    $foBreakdown = $monthly = $years = [];
}

$monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Annual Participation Report – OTRS</title>
<link href="../../assets/img/favicon.png" rel="icon">
<link href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.4/datatables.min.css" rel="stylesheet">
</head>
<body>
<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">
  <div class="pagetitle otrs-ph">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <div class="otrs-ph-icon icon-green"><i class="bi bi-file-earmark-bar-graph-fill"></i></div>
        <div>
          <h1>Annual Participation Report</h1>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="index">Home</a></li>
              <li class="breadcrumb-item active">Annual Report</li>
            </ol>
          </nav>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
      <form method="get" class="d-flex align-items-center gap-2">
        <label class="fw-semibold mb-0">Year:</label>
        <select name="year" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
          <?php foreach ($years as $yr): ?>
            <option value="<?php echo $yr; ?>" <?php echo $yr == $selectedYear ? 'selected' : ''; ?>>
              <?php echo $yr; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
        <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
      </div><!-- /.d-flex right -->
    </div>
  </div>

  <section class="section">

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center py-4">
          <div class="fs-1 fw-bold text-primary"><?php echo number_format($uniqueParticipants); ?></div>
          <div class="text-muted small text-uppercase fw-semibold">Unique Participants</div>
          <div class="text-muted" style="font-size:.75rem;">in <?php echo $selectedYear; ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center py-4">
          <div class="fs-1 fw-bold text-success"><?php echo number_format($totalRegistrations); ?></div>
          <div class="text-muted small text-uppercase fw-semibold">Total Registrations</div>
          <div class="text-muted" style="font-size:.75rem;">non-cancelled</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center py-4">
          <div class="fs-1 fw-bold text-warning"><?php echo number_format($totalTrainings); ?></div>
          <div class="text-muted small text-uppercase fw-semibold">Trainings Conducted</div>
          <div class="text-muted" style="font-size:.75rem;">non-cancelled</div>
        </div>
      </div>
    </div>

    <div class="row g-4">

      <!-- Field Office Breakdown -->
      <div class="col-lg-7">
        <div class="card shadow-sm border-0">
          <div class="card-header fw-semibold bg-primary text-white d-flex align-items-center gap-2">
            <i class="bi bi-building"></i> Participation by Field Office
          </div>
          <div class="card-body table-responsive">
            <table class="table table-bordered table-hover table-sm" style="font-size:13px;">
              <thead class="table-light">
                <tr>
                  <th>Field Office</th>
                  <th class="text-center">Total</th>
                  <th class="text-center">Paid</th>
                  <th class="text-center">Promissory</th>
                  <th class="text-center">Pending</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($foBreakdown as $fo):
                  $label = $locationMap[$fo['location']] ?? strtoupper($fo['location']);
              ?>
                <tr>
                  <td><?php echo htmlspecialchars($label, ENT_QUOTES); ?></td>
                  <td class="text-center fw-bold"><?php echo $fo['total']; ?></td>
                  <td class="text-center text-success"><?php echo $fo['paid']; ?></td>
                  <td class="text-center text-warning"><?php echo $fo['promissory']; ?></td>
                  <td class="text-center text-danger"><?php echo $fo['pending']; ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($foBreakdown)): ?>
                <tr><td colspan="5" class="text-center text-muted">No data for <?php echo $selectedYear; ?></td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Monthly Trend -->
      <div class="col-lg-5">
        <div class="card shadow-sm border-0">
          <div class="card-header fw-semibold bg-success text-white d-flex align-items-center gap-2">
            <i class="bi bi-graph-up"></i> Monthly Trend
          </div>
          <div class="card-body table-responsive">
            <table class="table table-bordered table-hover table-sm" style="font-size:13px;">
              <thead class="table-light">
                <tr>
                  <th>Month</th>
                  <th class="text-center">Unique Participants</th>
                  <th class="text-center">Total Registrations</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($monthly as $m): ?>
                <tr>
                  <td><?php echo $monthNames[(int) $m['month']]; ?></td>
                  <td class="text-center fw-bold"><?php echo $m['unique_pax']; ?></td>
                  <td class="text-center"><?php echo $m['total_regs']; ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($monthly)): ?>
                <tr><td colspan="3" class="text-center text-muted">No data for <?php echo $selectedYear; ?></td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </section>
</main>

<?php imis_include('footer_db') ?>
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/main.js"></script>
</body>
</html>
