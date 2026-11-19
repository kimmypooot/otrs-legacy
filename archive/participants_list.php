<?php
/**
 * participants_list.php  — OTRS Participants List
 *
 * MIGRATION NOTES:
 *   Old tables  : participants, trainings
 *   New tables  : otrs_participants, otrs_enrollments, otrs_trainings
 *
 *   Key behavioural changes:
 *   • Training lookup uses otrs_trainings (start_date / end_date instead of legacy date string).
 *   • Participant list is built from a JOIN of otrs_participants + otrs_enrollments
 *     keyed on otrs_enrollments.training_id (from $_GET['id']).
 *   • The enrollment_id (otrs_enrollments.id) is the primary handle passed to JS
 *     for all modal / update operations — replaces the old participant.id.
 *   • Column names updated: civilstatus→civil_status, positiontitle→position_title,
 *     mobileno→mobile_no, status→payment_status, orno→or_no, ordate→or_date,
 *     timereg→registered_at.
 *   • Filter now operates on enrollment.payment_status.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/connect.php';
include_once __DIR__ . '/../imis_include.php';

if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    header('Location: admin/login');
    exit();
}

if (isset($_GET['location'])) {
    $location = $_GET['location'];
    if ($_SESSION['type'] !== $location) {
        header('location: index?location=' . $_SESSION['type']);
        exit();
    }
}

include_once 'connect.php';

$location     = $_GET['location'] ?? '';
$trainingId   = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
$filter       = $_GET['filter'] ?? '';
$showButtons  = in_array($location, ['ro6', 'ro7'], true);

// ── Location label map ─────────────────────────────────────────────────────
$locationLabels = [
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
$locationText = $locationLabels[$location] ?? 'CSC OTRS';

// ── Fetch training details from otrs_trainings ─────────────────────────────
$training = [];
if ($trainingId > 0) {
    try {
        $conn = new PDO(
            'mysql:host=' . $servername . ';dbname=' . $dbname . ';charset=utf8mb4',
            $username,
            $password
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $conn->prepare("
            SELECT id, name, start_date, end_date, location, mode, fee, slots
            FROM otrs_trainings
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $trainingId, PDO::PARAM_INT);
        $stmt->execute();
        $training = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[participants_list] Training fetch error: ' . $e->getMessage());
    }
}

if (!$training) {
    die('<div class="alert alert-danger">Invalid or missing training ID.</div>');
}

// Human-readable date range for display
$dateDisplay = date('M d, Y', strtotime($training['start_date']))
             . ' – '
             . date('M d, Y', strtotime($training['end_date']));

$isFaceToFace = (strtolower($training['mode']) === 'face-to-face'
              || strtolower($training['mode']) === 'face to face');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="author" content="CSCRO8-ITG">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title><?php echo htmlspecialchars($training['name']); ?> — <?php echo $dateDisplay; ?> — <?php echo $locationText; ?></title>

  <link href="assets/img/favicon.png" rel="icon">
  <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="assets/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.11.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.min.css">
  <link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">

  <style>
    input[type="text"], input[type="date"], select.form-select { font-size: 14px; }
    .accordion-button, .accordion-button:not(.collapsed) { background-color: #E8F2FD; }
    .sidebar { display: none; }
    .main    { margin-left: 0 !important; }
    .btn-xs  { padding: .25rem .5rem; font-size: .75rem; line-height: 1.5; border-radius: .2rem; }
    #toggleColumnsBtn { transition: background-color .15s ease, box-shadow .15s ease; }
    #toggleColumnsBtn.active-expanded {
        background-color: #5a3e9e !important; border-color: #5a3e9e !important;
        box-shadow: inset 0 0 0 2px rgba(255,255,255,.3); color: #fff !important;
    }
    #colViewBadge {
        font-size: .72rem; font-weight: 600; padding: .18rem .52rem; border-radius: 20px;
        background: #e9ecef; color: #495057; white-space: nowrap; transition: background .2s, color .2s;
    }
    #colViewBadge.expanded { background: #5a3e9e; color: #fff; }
  </style>
</head>
<body>

<?php imis_include('header'); ?>
<?php include 'inc/sidebar.php'; ?>

<main id="main" class="main">

  <div class="pagetitle">
    <h1>List of Participants — <?php echo htmlspecialchars($training['name']); ?> — <?php echo $dateDisplay; ?></h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index?location=<?php echo $location; ?>">Home</a></li>
        <li class="breadcrumb-item"><?php echo strtoupper($_SESSION['type']); ?></li>
        <li class="breadcrumb-item"><?php echo htmlspecialchars($training['name']); ?></li>
        <li class="breadcrumb-item active">List of Participants</li>
      </ol>
    </nav>
  </div>

  <section class="section">
    <div class="participant">
      <div class="card">
        <div class="card-body" style="overflow-x: auto;">
          <br>

          <!-- ── ACTION BUTTON ROW ─────────────────────────────────────── -->
          <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <a class="btn btn-danger btn-sm" href="index?location=<?php echo $location; ?>">
              <i class="bi bi-arrow-left-circle"></i> Go Back
            </a>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#infoTrainingModal">
              <i class="bi bi-info-square"></i>
            </button>
            <button id="exportButton" class="btn btn-success btn-sm">
              <i class="bi bi-file-earmark-excel"></i> Export to Excel
            </button>

            <?php if ($showButtons): ?>
              <button id="sendEmailAck" class="btn btn-primary btn-sm" onclick="sendAcknowledgmentLetter()">
                <i class="ri ri-mail-send-line"></i> Send Ack Letter
              </button>
              <button id="sendEmailZoom" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#zoomLinkModal">
                <i class="ri ri-mail-send-line"></i> Email Zoom Link
              </button>
              <button id="sendEmailAdvisory" class="btn btn-primary btn-sm" onclick="sendEmailAdvisory()">
                <i class="ri ri-mail-send-line"></i> Send Training Advisory
              </button>
            <?php endif; ?>

            <!-- ── COLUMN VISIBILITY TOGGLE ──────────────────────────── -->
            <div class="d-flex align-items-center gap-2 ms-auto">
              <button id="toggleColumnsBtn" class="btn btn-secondary btn-sm" title="Toggle extra columns">
                <i class="bi bi-layout-three-columns me-1"></i>
                <span id="toggleColumnsLabel">Show All</span>
              </button>
              <span id="colViewBadge">Core view</span>
            </div>
          </div>

          <?php
          // ── Fetch participants via JOIN ─────────────────────────────────
          // otrs_enrollments is the FK bridge; filtered by training_id.
          // location filter applied at SQL level for efficiency.
          try {
              $conn = new PDO(
                  'mysql:host=' . $servername . ';dbname=' . $dbname . ';charset=utf8mb4',
                  $username,
                  $password
              );
              $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

              // Base query — always filter by training_id (indexed FK)
              $sql = "
                  SELECT
                      e.id              AS enrollment_id,
                      p.id              AS participant_id,
                      p.fname,
                      p.mname,
                      p.lname,
                      p.gender,
                      p.age,
                      p.civil_status,
                      p.position_title,
                      p.agency_name,
                      p.agency_address,
                      p.email,
                      p.mobile_no,
                      p.location,
                      p.food_restrictions,
                      e.payment_status,
                      e.or_no,
                      e.or_date,
                      e.collecting_officer,
                      e.remarks,
                      e.status_training,
                      e.registered_at
                  FROM otrs_enrollments  e
                  JOIN otrs_participants  p ON p.id = e.participant_id
                  WHERE e.training_id = :training_id
              ";

              $params = [':training_id' => $trainingId];

              // Filter by FO location when provided (location lives on otrs_participants)
              if (!empty($location)) {
                  $sql    .= " AND UPPER(p.location) = UPPER(:location)";
                  $params[':location'] = $location;
              }

              $sql .= " ORDER BY p.lname ASC, p.fname ASC";

              $stmt = $conn->prepare($sql);
              $stmt->execute($params);
              $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

              // ── Filter options ─────────────────────────────────────────
              $filterOptions = ['All', 'Paid', 'Pending', 'Promissory Note', 'Cancelled'];

              echo '<div class="col-md-6 d-flex align-items-center mb-3">';
              echo '<div class="col-2"><label for="filter" class="form-label mb-0">Filter by:</label></div>';
              echo '<div class="ms-1"><select id="filter" name="filter" class="form-select form-select-sm" onchange="applyFilter(this.value)">';
              foreach ($filterOptions as $opt) {
                  $sel = ($filter === $opt) ? 'selected' : '';
                  echo '<option value="' . $opt . '" ' . $sel . '>' . $opt . '</option>';
              }
              echo '</select></div>';
              echo '</div>';

              // ── JS column config injected by PHP ───────────────────────
              // Checkbox column adds offset of 1 when shown.
              // Column layout (0-based, no-checkbox baseline):
              //   0  action          ← always
              //   1  #               ← always
              //   2  name            ← CORE
              //   3  gender          ← extra
              //   4  age             ← extra
              //   5  civil_status    ← extra
              //   6  position_title  ← CORE
              //   7  agency_name     ← CORE
              //   8  agency_address  ← extra
              //   9  email           ← CORE
              //  10  mobile_no       ← CORE
              //  11  FO (location)   ← CORE
              //  12  payment_status  ← CORE
              //  13  or_no           ← extra
              //  14  or_date         ← extra
              //  15  collecting_off  ← extra
              // [16  food_restrict]  ← extra (F2F only)
              //  16/17  remarks      ← extra
              //  17/18  status_trng  ← extra
              //  18/19  reg date     ← CORE
              $showCheckboxColumn = $showButtons;
              $colOffset          = $showCheckboxColumn ? 1 : 0;
              $isFtFjs            = $isFaceToFace ? 'true' : 'false';
              echo "<script>
                  const COL_OFFSET      = {$colOffset};
                  const IS_FACE_TO_FACE = {$isFtFjs};
              </script>";

              if (count($participants) > 0) {
                  echo '<table id="participantsList" class="table table-bordered table-hover table-striped" style="font-size:14px;width:100%">';
                  echo '<thead><tr>';

                  if ($showCheckboxColumn) {
                      echo '<th style="text-align:center;vertical-align:middle"><input style="transform:scale(1.8)" type="checkbox" id="checkAll"></th>';
                  }
                  echo '<th style="text-align:center;vertical-align:middle">Action</th>';
                  echo '<th style="text-align:center;vertical-align:middle">#</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Name of Participant</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Gender</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Age</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Civil Status</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Position Title</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Agency/Office</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Agency Address</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Email Address</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Contact No.</th>';
                  echo '<th style="text-align:center;vertical-align:middle">FO</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Status</th>';
                  echo '<th style="text-align:center;vertical-align:middle">OR No.</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Date of OR</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Collecting Officer</th>';
                  if ($isFaceToFace) {
                      echo '<th style="text-align:center;vertical-align:middle">Food Restrictions</th>';
                  }
                  echo '<th style="text-align:center;vertical-align:middle">Remarks</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Training Status</th>';
                  echo '<th style="text-align:center;vertical-align:middle">Reg Date &amp; Time</th>';
                  echo '</tr></thead>';
                  echo '<tbody>';

                  $count = 0;
                  foreach ($participants as $row) {

                      // PHP-side filter on payment_status (mirrors old status filter)
                      if (!empty($filter) && $filter !== 'All' && $row['payment_status'] !== $filter) {
                          continue;
                      }

                      $count++;
                      $enrollmentId  = $row['enrollment_id'];
                      $paymentStatus = $row['payment_status'];

                      echo '<tr>';

                      if ($showCheckboxColumn) {
                          $name = $row['lname'] . ', ' . $row['fname'] . ' ' . $row['mname'];
                          echo '<td style="text-align:center">
                              <input style="transform:scale(1.8)" type="checkbox" name="enrollments[]"
                                  value="' . $enrollmentId . '"
                                  class="participant-checkbox"
                                  data-enrollment-id="' . $enrollmentId . '"
                                  data-participant-id="' . $row['participant_id'] . '"
                                  data-status="' . htmlspecialchars($paymentStatus) . '"
                                  data-name="' . htmlspecialchars($name) . '">
                          </td>';
                      }

                      // Action button — disabled for Cancelled
                      echo '<td style="text-align:center">';
                      if ($paymentStatus === 'Cancelled') {
                          echo '<button class="btn btn-primary btn-sm disabled" disabled
                                    data-bs-toggle="tooltip" data-bs-placement="left"
                                    title="Participant is Cancelled">
                                    <i class="bi bi-pencil-square"></i></button>';
                      } else {
                          echo '<button class="btn btn-primary btn-sm"
                                    onclick="showUpdateModal(' . $enrollmentId . ')"
                                    data-bs-toggle="tooltip" data-bs-placement="left"
                                    title="Update Participant">
                                    <i class="bi bi-pencil-square"></i></button>';
                      }
                      echo '</td>';

                      echo '<td>' . $count . '</td>';
                      echo '<td>' . htmlspecialchars($row['fname'] . ' ' . $row['mname'] . ' ' . $row['lname']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['gender']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['age']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['civil_status']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['position_title']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['agency_name']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['agency_address']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['email']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['mobile_no']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['location']) . '</td>';

                      // Payment status badge
                      $badgeMap = [
                          'Pending'         => 'badge bg-primary',
                          'Cancelled'       => 'badge bg-danger',
                          'Paid'            => 'badge bg-success',
                          'Promissory Note' => 'badge bg-warning text-dark',
                      ];
                      $badgeClass = $badgeMap[$paymentStatus] ?? 'badge bg-secondary';
                      echo '<td><span class="' . $badgeClass . '">' . htmlspecialchars($paymentStatus) . '</span></td>';

                      echo '<td>' . htmlspecialchars($row['or_no']) . '</td>';
                      // or_date is a DATE column; format for display
                      echo '<td>' . (!empty($row['or_date']) ? date('m/d/Y', strtotime($row['or_date'])) : '') . '</td>';
                      echo '<td>' . htmlspecialchars($row['collecting_officer']) . '</td>';

                      if ($isFaceToFace) {
                          echo '<td>' . htmlspecialchars($row['food_restrictions']) . '</td>';
                      }

                      echo '<td>' . htmlspecialchars($row['remarks']) . '</td>';

                      // Training status badge
                      $tsBadgeMap = [
                          'Completed'       => 'badge bg-success',
                          'Not Completed'   => 'badge bg-primary',
                          'For Compliance'  => 'badge bg-danger',
                      ];
                      $tsBadge = $tsBadgeMap[$row['status_training']] ?? '';
                      echo '<td><span class="' . $tsBadge . '">' . htmlspecialchars($row['status_training']) . '</span></td>';

                      echo '<td>' . htmlspecialchars($row['registered_at'] ?? '') . '</td>';
                      echo '</tr>';
                  }

                  echo '</tbody></table>';

              } else {
                  echo '<div class="alert alert-danger"><i class="bi bi-x-circle"></i> No participants found for <b>'
                       . htmlspecialchars($training['name']) . '</b></div>';
              }

          } catch (PDOException $e) {
              error_log('[participants_list] Query error: ' . $e->getMessage());
              echo '<div class="alert alert-danger">Failed to load participants. Please try again.</div>';
          }
          ?>
        </div><!-- /.card-body -->
      </div><!-- /.card -->
    </div><!-- /.participant -->
  </section>
</main>

<?php include 'inc/footer.php'; ?>

<a href="#" class="back-to-top d-flex align-items-center justify-content-center">
  <i class="bi bi-arrow-up-short"></i>
</a>

<!-- ═══════════════════════════════════════════════════════════════════════
     UPDATE MODAL
     NOTE: hidden field is now enrollmentId (was participantId).
     All POST keys match new column names in otrs_participants / otrs_enrollments.
════════════════════════════════════════════════════════════════════════════ -->
<div id="updateModal" class="modal fade" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title">Update Participant — <b><span id="fullName"></span></b></span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="updateForm" style="font-size:14px">
          <!-- Hidden: enrollment_id is the PK for this update operation -->
          <input type="hidden" id="enrollmentId"  name="enrollmentId">
          <input type="hidden" id="participantId" name="participantId">

          <div class="row">
            <div class="col-md-4">
              <label class="form-label"><b>First Name:</b></label>
              <input type="text" class="form-control" id="fname" name="fname">
            </div>
            <div class="col-md-2">
              <label class="form-label"><b>MI:</b></label>
              <input type="text" class="form-control" id="mname" name="mname" maxlength="2">
            </div>
            <div class="col-md-3">
              <label class="form-label"><b>Last Name:</b></label>
              <input type="text" class="form-control" id="lname" name="lname">
            </div>
            <div class="col-md-2">
              <label class="form-label"><b>Gender:</b></label>
              <select class="form-select" id="gender" name="gender">
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="col-md-1">
              <label class="form-label"><b>Age:</b></label>
              <input type="text" class="form-control" id="age" name="age" maxlength="3">
            </div>
          </div><br>

          <div class="row">
            <div class="col-md-3">
              <label class="form-label"><b>Civil Status:</b></label>
              <select class="form-select" id="civil_status" name="civil_status">
                <option>Single</option>
                <option>Married</option>
                <option>Widowed/Widower</option>
                <option>Separated</option>
                <option>Others</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><b>Email Address:</b></label>
              <input type="text" class="form-control" id="email" name="email">
            </div>
            <div class="col-md-3">
              <label class="form-label"><b>Mobile No.:</b></label>
              <input type="text" class="form-control" id="mobile_no" name="mobile_no" maxlength="11">
            </div>
          </div><br>

          <div class="row">
            <div class="col-md-4">
              <label class="form-label"><b>Position Title:</b></label>
              <input type="text" class="form-control" id="position_title" name="position_title">
            </div>
            <div class="col-md-4">
              <label class="form-label"><b>Agency/Office:</b></label>
              <input type="text" class="form-control" id="agency_name" name="agency_name">
            </div>
            <div class="col-md-4">
              <label class="form-label"><b>Agency Address:</b></label>
              <input type="text" class="form-control" id="agency_address" name="agency_address">
            </div>
          </div><br>

          <div class="row">
            <div class="col-md-3">
              <label class="form-label"><b>FO:</b></label>
              <select class="form-select" id="location" name="location">
                <option value="HRD">Region VIII-HRD</option>
                <option value="LFOI">LFOI</option>
                <option value="LFOII">LFOII</option>
                <option value="BFO">BFO</option>
                <option value="WLSO">WLSO</option>
                <option value="SLFO">SLFO</option>
                <option value="SFO">SFO</option>
                <option value="ESFO">ESFO</option>
                <option value="NSFO">NSFO</option>
                <option value="RO6">Region VI</option>
                <option value="RO7">Region VII</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label"><b>Status:</b></label>
              <!-- Mapped to otrs_enrollments.payment_status -->
              <select class="form-select" id="payment_status" name="payment_status">
                <option value="Pending">Pending</option>
                <option value="Paid">Paid</option>
                <option value="Cancelled">Cancelled</option>
                <option value="Promissory Note">Promissory Note</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><b>Collecting Officer:</b></label>
              <select class="form-select" id="collecting_officer" name="collecting_officer"></select>
            </div>
          </div><br>

          <div class="row">
            <div class="col-md-2">
              <label class="form-label"><b>OR No.:</b></label>
              <!-- Mapped to otrs_enrollments.or_no -->
              <input type="text" class="form-control" id="or_no" name="or_no">
            </div>
            <div class="col-md-3">
              <label class="form-label"><b>Date of OR:</b></label>
              <!-- Mapped to otrs_enrollments.or_date (DATE type) -->
              <input type="date" class="form-control" id="or_date" name="or_date">
            </div>
            <div class="col-md-7">
              <label class="form-label"><b>Remarks:</b></label>
              <textarea class="form-control" id="remarks" name="remarks" style="font-size:14px"></textarea>
            </div>
          </div>
        </div><!-- /#updateForm -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
          <i class="bi bi-x-circle"></i> Cancel
        </button>
        <button type="button" class="btn btn-success" onclick="updateParticipant()">
          <i class="bi bi-save2"></i> Update
        </button>
      </div>
    </div>
  </div>
</div>

<!-- TRAINING INFO MODAL — now reads from otrs_trainings columns -->
<div class="modal fade" id="infoTrainingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title">Training Details — <b><?php echo htmlspecialchars($training['name']); ?></b></span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <table class="table table-bordered" style="font-size:14px">
          <tbody>
            <tr>
              <td><b>Name of Training:</b></td>
              <td colspan="3"><?php echo htmlspecialchars($training['name']); ?></td>
            </tr>
            <tr>
              <td><b>Date of Training:</b></td>
              <td colspan="3"><?php echo $dateDisplay; ?></td>
            </tr>
            <tr>
              <td><b>Place of Training:</b></td><td><?php echo htmlspecialchars($training['location']); ?></td>
              <td><b>Mode of Training:</b></td><td><?php echo htmlspecialchars($training['mode']); ?></td>
            </tr>
            <tr>
              <td><b>Slots Remaining:</b></td>
              <td colspan="3"><?php echo (int) $training['slots']; ?></td>
            </tr>
            <tr>
              <td><b>Registration Fee:</b></td>
              <td colspan="3">&#8369; <?php echo number_format((float) $training['fee'], 2); ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
          <i class="bi bi-x-circle"></i> Close
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ZOOM LINK MODAL (ro6/ro7 only) -->
<div class="modal fade" id="zoomLinkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title">Enter Zoom Link to <span style="color:red"><b>"Paid" and "Promissory Note"</b></span> Participants</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label>Enter Zoom Link:</label>
        <input id="zoomLinkInput" class="form-control" type="text" placeholder="Paste the Zoom link here…" autofocus>
        <label class="mt-3">Upload Training Advisory PDF:</label>
        <input type="file" id="zoomPDF" class="form-control" accept="application/pdf">
        <label class="mt-3">Upload Zoom Background:</label>
        <input type="file" id="zoomBg"  class="form-control" accept="image/*">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
          <i class="bi bi-x-circle"></i> Cancel
        </button>
        <button type="button" class="btn btn-primary" onclick="confirmSendEmailZoom()">
          <i class="ri ri-mail-send-line"></i> Send
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── JS Libraries ─────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.5.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>
<script src="assets/js/main.js"></script>

<script>
$(document).ready(function () {

    // ── 1. COLUMN VISIBILITY SETUP ─────────────────────────────────────────
    // COL_OFFSET and IS_FACE_TO_FACE injected by PHP above.
    const EXTRA_BASE = [3, 4, 5, 8, 13, 14, 15];
    if (IS_FACE_TO_FACE) EXTRA_BASE.push(16);
    const afterFood = IS_FACE_TO_FACE ? 1 : 0;
    EXTRA_BASE.push(16 + afterFood); // remarks
    EXTRA_BASE.push(17 + afterFood); // status_training
    const EXTRA_INDICES = EXTRA_BASE.map(i => i + COL_OFFSET);
    const colDefs = EXTRA_INDICES.map(idx => ({ targets: idx, visible: false }));

    // ── 2. DATATABLE INIT ──────────────────────────────────────────────────
    function isMobile() { return $(window).width() <= 767; }

    var table = $('#participantsList').DataTable({
        scrollX:      true,
        fixedHeader:  { headerOffset: 50 },
        columnDefs:   colDefs,
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>"                                  +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        fixedColumns: { left: isMobile() ? 0 : (COL_OFFSET + 3) },
        buttons: [{
            extend: 'excel',
            text: 'Export to Excel',
            filename: getExportFilename(),
            exportOptions: { columns: ':gt(' + COL_OFFSET + ')' }
        }],
        language: {
            search: '<i class="bi bi-search"></i>',
            searchPlaceholder: 'Search',
            lengthMenu: 'Show _MENU_ entries',
            paginate: {
                next:     '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            }
        },
        lengthMenu: [10, 50, 100],
        pageLength:  10
    });

    $(window).on('resize', () => table.fixedColumns().update());
    $('#exportButton').on('click', () => table.button('.buttons-excel').trigger());
    if (isMobile()) table.buttons('.buttons-excel').container().hide();

    // ── 3. COLUMN TOGGLE ───────────────────────────────────────────────────
    var expanded = false;
    $('#toggleColumnsBtn').on('click', function () {
        expanded = !expanded;
        EXTRA_INDICES.forEach(idx => table.column(idx).visible(expanded));
        $(this).toggleClass('active-expanded', expanded);
        $('#toggleColumnsLabel').text(expanded ? 'Core Only' : 'Show All');
        $('#colViewBadge').text(expanded ? 'Extended view' : 'Core view').toggleClass('expanded', expanded);
    });

    // ── 4. CHECK-ALL (ro6/ro7) ─────────────────────────────────────────────
    const checkAll = document.getElementById('checkAll');
    if (checkAll) {
        const cbs = document.querySelectorAll('.participant-checkbox');
        checkAll.addEventListener('change', () => cbs.forEach(cb => cb.checked = checkAll.checked));
    }

    // ── 5. COLLECTING OFFICER ──────────────────────────────────────────────
    function fetchCollectingOfficers(location) {
        $.ajax({
            url: 'fetch_co_dropdown.php', method: 'POST',
            data: { location: location }, dataType: 'json',
            success: function (res) {
                var sel = $('#collecting_officer').empty();
                res.forEach(co => sel.append($('<option>').val(co.id).text(co.name)));
            }
        });
    }

    function handleStatusChange(status) {
        var active = (status === 'Paid' || status === 'Promissory Note');
        $('#collecting_officer, #or_no, #or_date').prop('disabled', !active);
        if (active) {
            fetchCollectingOfficers($('#location').val());
        } else {
            $('#collecting_officer').val('');
            $('#or_no, #or_date').val('');
        }
    }

    $('#updateModal').on('show.bs.modal', () => handleStatusChange($('#payment_status').val()));
    $('#payment_status').on('change', function () { handleStatusChange($(this).val()); });
    $('#location').on('change', function () {
        if (['Paid', 'Promissory Note'].includes($('#payment_status').val())) {
            fetchCollectingOfficers($(this).val());
        }
    });

    // ── 6. EMAIL BUTTON VISIBILITY (page load) ─────────────────────────────
    (function () {
        var f    = '<?php echo addslashes($filter); ?>';
        var ack  = document.getElementById('sendEmailAck');
        var zoom = document.getElementById('sendEmailZoom');
        var adv  = document.getElementById('sendEmailAdvisory');
        if (!ack) return;

        if (f === 'Cancelled') {
            [ack, zoom, adv].forEach(b => b.style.display = 'none');
        } else if (f === 'Paid') {
            ack.style.display = adv.style.display = 'none';
            zoom.style.display = 'inline-block';
        } else if (f === 'Pending' || f === 'Promissory Note') {
            ack.style.display = adv.style.display = 'inline-block';
            zoom.style.display = 'none';
        }
        document.getElementById('filter').value = f || 'All';
    })();

    // ── Utilities ──────────────────────────────────────────────────────────
    function getExportFilename() {
        return '<?php echo addslashes($training["name"]); ?>'
             + '_' + getParam('location')
             + '_' + formatDate();
    }
    function formatDate() {
        var d = new Date();
        return ('0'+(d.getMonth()+1)).slice(-2) + '-' + ('0'+d.getDate()).slice(-2) + '-' + d.getFullYear();
    }
    function getParam(name) {
        var m = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)').exec(location.href);
        return m && m[2] ? decodeURIComponent(m[2].replace(/\+/g,' ')) : '';
    }

}); // end ready


// ══════════════════════════════════════════════════════════════════════════
// SHOW UPDATE MODAL
// Sends enrollmentId to fetch_participant.php; maps response to
// new column names (civil_status, position_title, mobile_no, etc.)
// ══════════════════════════════════════════════════════════════════════════
function showUpdateModal(enrollmentId) {
    $.ajax({
        url:      'fetch_participant.php',
        type:     'POST',
        data:     { enrollmentId: enrollmentId },
        dataType: 'json',
        success: function (res) {
            if (res.status !== 'success') {
                Swal.fire('Error', 'Failed to fetch participant details.', 'error');
                return;
            }
            var p = res.participant;

            // Hidden IDs
            $('#enrollmentId').val(p.enrollment_id);
            $('#participantId').val(p.participant_id);

            // Personal data (otrs_participants columns)
            $('#fname').val(p.fname);
            $('#mname').val(p.mname);
            $('#lname').val(p.lname);
            $('#gender').val(p.gender);
            $('#age').val(p.age);
            $('#civil_status').val(p.civil_status);
            $('#position_title').val(p.position_title);
            $('#agency_name').val(p.agency_name);
            $('#agency_address').val(p.agency_address);
            $('#email').val(p.email);
            $('#mobile_no').val(p.mobile_no);
            $('#location').val(p.location);

            // Enrollment data (otrs_enrollments columns)
            $('#payment_status').val(p.payment_status);
            $('#or_no').val(p.or_no);
            $('#or_date').val(p.or_date);           // DATE format: YYYY-MM-DD → works with input[type=date]
            $('#remarks').val(p.remarks);

            // Display name
            $('#fullName').text(p.fname + ' ' + p.mname + ' ' + p.lname);

            $('#updateModal').modal('show');
        },
        error: function (xhr, s, e) {
            Swal.fire('AJAX Error', 'Request failed: ' + s, 'error');
        }
    });
}


// ══════════════════════════════════════════════════════════════════════════
// UPDATE PARTICIPANT
// POSTs to update_participant.php using enrollmentId + new field names.
// ══════════════════════════════════════════════════════════════════════════
function updateParticipant() {
    var status = $('#payment_status').val();

    function doUpdate() {
        $.ajax({
            url:      'update_participant.php',
            type:     'POST',
            data: {
                enrollmentId:        $('#enrollmentId').val(),
                fname:               $('#fname').val(),
                mname:               $('#mname').val(),
                lname:               $('#lname').val(),
                gender:              $('#gender').val(),
                age:                 $('#age').val(),
                civil_status:        $('#civil_status').val(),
                position_title:      $('#position_title').val(),
                agency_name:         $('#agency_name').val(),
                agency_address:      $('#agency_address').val(),
                email:               $('#email').val(),
                mobile_no:           $('#mobile_no').val(),
                location:            $('#location').val(),
                payment_status:      status,
                or_no:               $('#or_no').val(),
                or_date:             $('#or_date').val(),
                collecting_officer:  $('#collecting_officer').val(),
                remarks:             $('#remarks').val()
            },
            dataType: 'json',
            success: function (res) {
                if (res.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Participant Updated', text: res.message })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function (xhr, s, e) {
                Swal.fire({ icon: 'error', title: 'AJAX Error', text: 'Failed: ' + s });
            }
        });
    }

    if (status === 'Cancelled') {
        Swal.fire({
            icon: 'warning', title: 'Are you sure?',
            text: "This will cancel the participant's registration.",
            showCancelButton: true, confirmButtonText: 'Yes, cancel', cancelButtonText: 'No, keep'
        }).then(r => { if (r.isConfirmed) doUpdate(); });
    } else {
        doUpdate();
    }
}


// ══════════════════════════════════════════════════════════════════════════
// SEND ACKNOWLEDGMENT LETTER
// Uses enrollmentId in data attributes.
// ══════════════════════════════════════════════════════════════════════════
function sendAcknowledgmentLetter() {
    var ids = getCheckedIds();
    if (!ids.length) {
        Swal.fire('Error', 'Please select at least one participant.', 'error');
        return;
    }
    Swal.fire({
        title: 'Send Acknowledgment Letter',
        html:  'Send <b>acknowledgment letter</b> to the selected participant(s)?',
        icon:  'warning', showCancelButton: true,
        confirmButtonText: 'Send', showLoaderOnConfirm: true,
        preConfirm: () => new Promise((resolve, reject) => {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'send_email-ack.php?id=<?php echo $trainingId; ?>', true);
            xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4)
                    xhr.status === 200 ? resolve(xhr.responseText) : reject('Send failed.');
            };
            xhr.send('enrollmentIds=' + JSON.stringify(ids));
        })
    }).then(r => { if (r.isConfirmed) Swal.fire('Email Sent', 'Acknowledgment letter sent.', 'success'); })
      .catch(e => Swal.fire('Error', e, 'error'));
}


// ══════════════════════════════════════════════════════════════════════════
// SEND ZOOM LINK
// ══════════════════════════════════════════════════════════════════════════
function confirmSendEmailZoom() {
    var participants = getCheckedParticipantsWithStatus().filter(p => p.status !== 'Cancelled');
    if (!participants.length) {
        Swal.fire('Error', 'Please select at least one paid participant.', 'error');
        return;
    }
    var unpaid = participants.filter(p => p.status !== 'Paid' && p.status !== 'Promissory Note');
    if (unpaid.length) {
        var names = unpaid.map((p, i) => '<b>' + (i + 1) + ': </b>' + p.name).join('<br>');
        Swal.fire({ title: 'Unpaid Participants', html: names, icon: 'error' });
        return;
    }
    var zoomLink = $('#zoomLinkInput').val().trim();
    var zoomPDF  = document.getElementById('zoomPDF').files[0];
    var zoomBg   = document.getElementById('zoomBg').files[0];
    bootstrap.Modal.getInstance(document.getElementById('zoomLinkModal')).hide();

    Swal.fire({
        title: 'Send Zoom Link', text: 'Send Zoom link to selected participants?',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Send', showLoaderOnConfirm: true,
        preConfirm: () => new Promise((resolve, reject) => {
            var fd = new FormData();
            fd.append('enrollmentIds', JSON.stringify(participants.map(p => p.id)));
            fd.append('zoomLinkInput', zoomLink);
            if (zoomPDF) fd.append('zoomPDF', zoomPDF);
            if (zoomBg)  fd.append('zoomBg',  zoomBg);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'send_email-link.php?id=<?php echo $trainingId; ?>', true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4)
                    xhr.status === 200 ? resolve(xhr.responseText) : reject('Send failed.');
            };
            xhr.send(fd);
        })
    }).then(r => { if (r.isConfirmed) Swal.fire('Email Sent', 'Zoom link sent.', 'success'); })
      .catch(e => Swal.fire('Error', e, 'error'));
}


// ══════════════════════════════════════════════════════════════════════════
// SEND TRAINING ADVISORY
// ══════════════════════════════════════════════════════════════════════════
function sendEmailAdvisory() {
    var participants = getCheckedParticipantsWithStatus().filter(p => p.status === 'Pending');
    if (!participants.length) {
        Swal.fire('Error', 'Please select at least one <b>pending</b> participant.', 'error');
        return;
    }
    Swal.fire({
        title: 'Send Training Advisory', html: 'Send the <b>training advisory</b>?',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Send', showLoaderOnConfirm: true,
        preConfirm: () => new Promise((resolve, reject) => {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'send_email-adv.php?id=<?php echo $trainingId; ?>', true);
            xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4)
                    xhr.status === 200 ? resolve(xhr.responseText) : reject('Send failed.');
            };
            xhr.send('participants=' + JSON.stringify(participants));
        })
    }).then(r => { if (r.isConfirmed) Swal.fire('Email Sent', 'Training advisory sent.', 'success'); })
      .catch(e => Swal.fire('Error', e, 'error'));
}


// ── FILTER ──────────────────────────────────────────────────────────────
function applyFilter(val) {
    var p = new URLSearchParams(location.search);
    val === 'All' ? p.delete('filter') : p.set('filter', val);
    location.search = p.toString();
}


// ── HELPERS — now use data-enrollment-id ──────────────────────────────
function getCheckedIds() {
    return [...document.querySelectorAll('.participant-checkbox:checked')]
        .map(cb => cb.getAttribute('data-enrollment-id'));
}

function getCheckedParticipantsWithStatus() {
    return [...document.querySelectorAll('.participant-checkbox:checked')].map(cb => ({
        id:     cb.getAttribute('data-enrollment-id'),
        status: cb.getAttribute('data-status'),
        name:   cb.getAttribute('data-name')
    }));
}
</script>

</body>
</html>