<?php
require_once __DIR__ . '/../../includes/bootstrap.php'; 
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title>OTRS - Backup Database</title>
  <meta content="" name="description">
  <meta content="" name="keywords">

  <!-- Favicons -->
  <link href="../assets/img/favicon.png" rel="icon">
  <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Google Fonts -->
  <script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <!-- Template Main CSS File -->
  <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
  <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
</head>

<body>

  <?php imis_include ('header_js') ?>
  <?php include 'inc/sidebar.php' ?>

  <main id="main" class="main">

    <div class="pagetitle otrs-ph">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <div class="otrs-ph-icon icon-navy"><i class="bi bi-hdd-stack"></i></div>
          <div>
            <h1>Backup Database</h1>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index">Home</a></li>
                <li class="breadcrumb-item">HRD</li>
                <li class="breadcrumb-item active">Backup Database</li>
              </ol>
            </nav>
          </div>
        </div>
        <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
      </div>
    </div><!-- End Page Title -->

    <section class="section">
      <div class="otrs-card">
        <div class="otrs-card-top">
          <p class="otrs-section-label mb-0"><i class="bi bi-database"></i> Database Backups</p>
          <button class="btn-f btn-f-success" id="backupButton"><i class="bi bi-database"></i> Backup Now</button>
        </div>
        <div class="otrs-card-body">
          <div class="table-responsive">
            <table id="fileTable" class="table table-bordered table-hover table-striped otrs-table w-100">
              <thead>
                <tr>
                  <th>File Name of Database</th>
                  <th>Date Created</th>
                  <th class="text-center">Action</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <!-- ======= Footer ======= -->
  
  <?php imis_include ('footer') ?>

  <!-- End Footer -->


  <a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

  <!-- Vendor JS Files -->
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
  $(document).ready(function() {
    // Define the password required for deletion
    var password = "cscro8hrd";

    $('#fileTable').DataTable({
  "ajax": {
    "url": "api/fetch_bkup.php",
    "dataSrc": "data"
  },
  "columns": [
    { "data": "file" },
    { "data": "modified" },
    {
      "data": null,
      "render": function(data, type, row) {
        var downloadLink = '<a href="' + row.download + '" class="btn-f btn-f-primary"><i class="bi bi-download"></i> Download</a>';
        var deleteButton = '<button class="deleteButton btn-f btn-f-danger" data-file="' + row.file + '"><i class="bi bi-trash"></i> Delete</button>';
        var emailButton = '<button class="emailButton btn-f btn-f-success" data-file="' + row.file + '"><i class="bi bi-envelope"></i> Send Email</button>';
        return '<div class="d-flex gap-1 justify-content-center flex-wrap">' + downloadLink + deleteButton + emailButton + '</div>';
      }
    }
  ],
  "order": [[1, "desc"]] // Sort by the second column in descending order
});

// Handle click event for the email button
$(document).on("click", ".emailButton", function() {
  var file = $(this).data("file");

  Swal.fire({
    title: "Enter email address",
    input: "email",
    inputAttributes: {
      autocapitalize: "off"
    },
    showCancelButton: true,
    confirmButtonText: "Send",
    showLoaderOnConfirm: true,
    preConfirm: function(email) {
      return new Promise(function(resolve) {
        if (email) {
          sendEmail(file, email)
            .then(function(response) {
              if (response === "success") {
                Swal.fire(
                  "Success",
                  "Email sent successfully!",
                  "success"
                );
                resolve();
              } else {
                Swal.fire(
                  "Error",
                  "Failed to send email, please check your internet connectivity and try again.",
                  "error"
                );
                resolve(false);
              }
            })
            .catch(function() {
              Swal.fire(
                "Error",
                "Failed to send email, please check your internet connectivity and try again.",
                "error"
              );
              resolve(false);
            });
        } else {
          Swal.showValidationMessage("Please enter an email address");
          resolve(false);
        }
      });
    },
    allowOutsideClick: false
  });
});


// Function to send email via AJAX
function sendEmail(file, email) {
  return new Promise(function(resolve, reject) {
    $.ajax({
      url: "api/send_email-bkup.php",
      method: "POST",
      data: { file: file, email: email, _csrf: window.csrfToken ?? '' },
      headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
      success: function(response) {
        resolve(response);
      },
      error: function() {
        reject();
      }
    });
  });
}

    // Handle delete button click event
    $('#fileTable').on('click', '.deleteButton', function() {
      var file = $(this).data('file');

      // Use SweetAlert2 for the delete confirmation with password prompt
      Swal.fire({
        title: 'Delete File',
        html: 'Are you sure you want to delete the file: <b>' + file + '</b>?',
        input: 'password',
        inputPlaceholder: 'Enter password',
        inputAttributes: {
          autocapitalize: 'off'
        },
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it'
      }).then((result) => {
        if (result.isConfirmed) {
          var enteredPassword = result.value;

          // Check if the entered password matches the required password
          if (enteredPassword === password) {
            // Perform the delete operation for the selected file
            $.ajax({
              url: 'api/delete_bkup.php',
              type: 'POST',
              data: { file: file, _csrf: window.csrfToken ?? '' },
              headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
              success: function(response) {
                Swal.fire({
                  title: 'File Deleted',
                  text: response,
                  icon: 'success'
                });
                // Refresh the DataTable after successful deletion
                $('#fileTable').DataTable().ajax.reload();
              },
              error: function() {
                Swal.fire({
                  title: 'Error',
                  text: 'Error deleting the file',
                  icon: 'error'
                });
              }
            });
          } else {
            Swal.fire({
              title: 'Invalid Password',
              text: 'The entered password is incorrect. Please try again.',
              icon: 'error'
            });
          }
        }
      });
    });
  });
</script>
<script>
$(document).ready(function() {
  // Trigger backup process when the button is clicked
  $('#backupButton').click(function() {
    Swal.fire({
      title: 'Backup Database',
      html: 'Would you like to backup the database?<br>The database file will also be sent to the <b>ro08.hrd@csc.gov.ph<br>(CSC RO VIII - Human Resource Division)</b>',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes',
      cancelButtonText: 'No'
    }).then((result) => {
      if (result.isConfirmed) {
        // Backup the database
        backupDatabase();
      }
    });
  });

  // Function to backup the database
  function backupDatabase() {
    Swal.fire({
      title: 'Backing up.. please wait',
      html: 'Backing up and preparing to send the file to <br><b>ro08.hrd@csc.gov.ph<br>(CSC RO VIII - <br>Human Resource Division)</b>.',
      icon: 'info',
      allowOutsideClick: false,
      showConfirmButton: false,
      didOpen: () => {
        Swal.showLoading();
      }
    });

    // Send an AJAX request to the server to perform the backup
    $.ajax({
      url: 'api/backup.php',
      type: 'POST',
      data: { _csrf: window.csrfToken ?? '' },
      headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
      success: function(response) {
        // Check if the backup was successful
        if (response === 'success') {
          Swal.fire({
            title: 'Backup Sent',
            html: 'The database backup was sent successfully to <br><b>CSC RO VIII - Human Resource Division</b>.',
            icon: 'success'
          }).then(function() {
            // Refresh the page
            location.reload();
          });
        } else {
          Swal.fire({
            title: 'Error',
            text: 'There was an error sending the database backup, it seems that we are able to save the backup file but we can`t email the file due to intermittent connection, please check your internet connectivity and try again.',
            icon: 'error'
          }).then(function() {
            // Refresh the page
            location.reload();
          });
        }
      },
      error: function() {
        Swal.fire({
          title: 'Error',
          text: 'There was an error performing the database backup, it seems that we are able to save the backup file but we can`t email the file due to intermittent connection, please check your internet connectivity and try again.',
          icon: 'error'
        }).then(function() {
            // Refresh the page
            location.reload();
          });
      }
    });
  }
});
</script>

  <!-- Template Main JS File -->
  <script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>

</body>
</html>