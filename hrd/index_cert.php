<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">

<title>HRD - L&D</title>
<meta content="" name="description">
<meta content="" name="keywords">

<!-- Favicons -->
<link href="../../assets/img/favicon.png" rel="icon">
<link href="../../assets/img/apple-touch-icon.png" rel="apple-touch-icon">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<!-- Vendor CSS Files -->
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">

<!-- Template Main CSS File -->
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
<link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

</head>

<body>

<!-- ======= Header ======= -->
<?php imis_include('header_js') ?>
<!-- ======= Sidebar ======= -->
<?php include 'inc/sidebar.php' ?>
<!-- End Sidebar-->

<main id="main" class="main">

  <div class="pagetitle otrs-ph">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <div class="otrs-ph-icon icon-purple"><i class="bi bi-file-earmark-check-fill"></i></div>
        <div>
          <h1>Generate Certificate of Completion</h1>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="index">Home</a></li>
              <li class="breadcrumb-item">HRD</li>
              <li class="breadcrumb-item active" aria-current="page">Certificates</li>
            </ol>
          </nav>
        </div>
      </div>
      <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
    </div>
  </div><!-- End Page Title -->

  <section class="section py-4">
    <div class="container-fluid">
      <div class="row g-4">
        <!-- Form Column -->
        <div class="col-lg-5">
          <div class="otrs-card">
            <div class="info-panel-head" style="background:linear-gradient(135deg,var(--otrs-navy),var(--otrs-navy-mid));">
              <i class="bi bi-file-earmark-text-fill"></i> Certificate Generator
            </div>
            <div class="otrs-card-body">
              <form id="uploadForm" enctype="multipart/form-data" novalidate>
                <!-- Select Training -->
                <div class="mb-3">
                  <label for="trainingSelect" class="form-label fw-semibold">Select Training:</label>
                  <select class="form-select" id="trainingSelect" name="trainingSelect" required aria-describedby="trainingSelectHelp">
                    <option selected hidden value="">-- Select Training --</option>
                    <?php
                      try {
                        $query = "SELECT DISTINCT name FROM otrs_list_trainings WHERE type = 'Online'";
                        $stmt = $conn->prepare($query);
                        $stmt->execute();
                        $trainings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($trainings as $training) {
                          echo '<option value="' . htmlspecialchars($training['name']) . '">' . htmlspecialchars($training['name']) . '</option>';
                        }
                      } catch (PDOException $e) {
                        echo '<option value="" disabled>Error fetching trainings</option>';
                      }
                    ?>
                  </select>
                  <div id="trainingSelectHelp" class="form-text">Choose the relevant training session.</div>
                </div>

                <!-- Venue -->
                <div class="mb-3">
                  <label for="venueInput" class="form-label fw-semibold">Venue of Training:</label>
                  <input type="text" class="form-control" id="venueInput" name="venue" placeholder="e.g. CSC RO VIII, 1st Floor, HRC Bldg." required aria-describedby="venueHelp" autocomplete="off">
                  <div id="venueHelp" class="form-text">Where the training will take place.</div>
                </div>

                <!-- Date -->
                <div class="mb-3">
                  <label for="dateInput" class="form-label fw-semibold">Date of Training:</label>
                  <input type="date" class="form-control" id="dateInput" name="date" required aria-describedby="dateHelp">
                  <div id="dateHelp" class="form-text">Select the date of the training.</div>
                </div>

                <!-- Training Hours -->
                <div class="mb-3">
                  <label for="hoursTrainingInput" class="form-label fw-semibold">Training Hours:</label>
                  <input type="number" class="form-control" id="hoursTrainingInput" name="hours" min="1" max="24" placeholder="e.g. 8" required aria-describedby="hoursHelp">
                  <div id="hoursHelp" class="form-text">Enter total training hours (1-24).</div>
                </div>

                <!-- Type of Training -->
                <div class="mb-3">
                  <label for="typeTraining" class="form-label fw-semibold">Type of Training:</label>
                  <select class="form-select" id="typeTraining" name="typeTraining" required aria-describedby="typeHelp">
                    <option selected hidden value="">-- Select Type --</option>
                    <option value="Technical">Technical</option>
                    <option value="Foundation">Foundation</option>
                    <option value="Leadership and Management">Leadership and Management</option>
                  </select>
                  <div id="typeHelp" class="form-text">Choose the training category.</div>
                </div>

                <!-- CSV Upload -->
                <div class="mb-3">
                  <label for="fileInput" class="form-label fw-semibold">Upload CSV File:</label>
                  <input type="file" class="form-control" id="fileInput" name="csv_file" accept=".csv,text/csv" required aria-describedby="fileHelp">
                  <div id="fileHelp" class="form-text">CSV should include participant full names.</div>
                </div>

                <!-- Generate Button -->
                <div class="d-grid mt-4">
                  <button type="button" class="btn btn-success btn-lg" id="generateCertBtn" disabled
                          style="border-radius:var(--otrs-r-md);font-weight:700;font-size:.9rem;">
                    <i class="bi bi-file-earmark-check-fill me-1" aria-hidden="true"></i>
                    Generate Certificates
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Participant Table -->
        <div class="col-lg-7">
          <div class="otrs-card">
            <div class="info-panel-head" style="background:linear-gradient(135deg,var(--otrs-teal),#0369a1);">
              <i class="bi bi-people-fill"></i> Participants from CSV
            </div>
            <div class="otrs-card-body">
              <div class="table-responsive">
                <table id="participantTable" class="table table-striped table-hover otrs-table nowrap w-100">
                  <thead>
                    <tr>
                      <th scope="col" style="width: 40px;">
                        <input type="checkbox" id="selectAll" aria-label="Select all participants">
                      </th>
                      <th scope="col">Full Name</th>
                    </tr>
                  </thead>
                  <tbody>
                    <!-- Filled dynamically via JS on CSV upload -->
                  </tbody>
                </table>
              </div>
              <small class="text-muted mt-2 d-block">Select participants who should receive certificates.</small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

</main>

<?php imis_include ('footer') ?>

<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<!-- Vendor JS Files -->
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<!-- Template Main JS File -->
<script src="../../assets/js/main.js"></script>
<script>
$(document).ready(function () {
    const table = $('#participantTable').DataTable({
        paging: false,
        searching: false,
        info: false,
        ordering: false,
        scrollX: true,
        columns: [
            { orderable: false },
            null
        ]
    });

    // Disable button initially
    $('#generateCertBtn').prop('disabled', true);

    $('#fileInput').on('change', function () {
        const reader = new FileReader();
        reader.onload = function (e) {
            const rows = e.target.result.split('\n');
            table.clear();

            let hasRows = false;

            rows.forEach((row, i) => {
                if (row.trim() !== "") {
                    const fullName = row.trim();
                    table.row.add([
                        `<input type="checkbox" class="participant-checkbox" value="${fullName}">`,
                        fullName
                    ]);
                    hasRows = true;
                }
            });

            table.draw();

            // Enable button only if rows exist
            $('#generateCertBtn').prop('disabled', !hasRows);

            // Reset the Select All checkbox
            $('#selectAll').prop('checked', false);
        };
        reader.readAsText(this.files[0]);
    });

    // Select/Deselect all checkboxes
    $('#selectAll').on('click', function () {
        $('.participant-checkbox').prop('checked', this.checked);
    });
});
</script>
<script>    
document.getElementById("generateCertBtn").addEventListener("click", function() {
    let formData = new FormData(document.getElementById("uploadForm"));

    Swal.fire({
        title: "Are you sure?",
        text: "This will generate certificates for the selected training!",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Yes, generate it!",
        cancelButtonText: "Cancel"
    }).then((result) => {
        if (result.isConfirmed) {
            formData.append('_csrf', window.csrfToken ?? '');
            fetch("generate_certificates.php", {
                method: "POST",
                body: formData,
                headers: { 'X-CSRF-Token': window.csrfToken ?? '' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire("Success!", "Certificates have been generated.", "success");
                    // document.getElementById("uploadForm").reset(); // Reset the form
                } else {
                    Swal.fire("Error!", data.message, "error");
                }
            })
            .catch(error => {
                Swal.fire("Error!", "Something went wrong.", "error");
                console.error("Error:", error);
            });
        }
    });
});
</script>
</body>

</html>
