<!DOCTYPE html>
<html>
<head>
  <!-- Include SweetAlert2 CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.4/dist/sweetalert2.min.css">
</head>
<body>
  <aside id="sidebar" class="sidebar">
    <ul class="sidebar-nav" id="sidebar-nav">
      <li class="nav-item">
        <a class="nav-link collapsed" href="index.php?location=<?php echo $_SESSION['type']; ?>">
          <i class="bi bi-grid"></i>
          <span>Dashboard</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link collapsed" onclick="generateCertificate()">
          <i class="bi bi-grid"></i>
          <span>Generate Certificate</span>
        </a>
      </li>
      <?php
      // When the including page has already resolved $location (e.g. HRD
      // drilled into a specific office via ?location=), carry that same
      // office through to these links instead of always pointing at the
      // session's own type (which for HRD is just 'hrd', a location that
      // never matches any real field-office request).
      $sidebarLocation = isset($location) ? $location : ($_SESSION['type'] ?? '');
      ?>
      <li class="nav-item">
        <a class="nav-link collapsed" href="cancellation_requests.php?location=<?php echo htmlspecialchars($sidebarLocation); ?>">
          <i class="bi bi-clipboard-x-fill" style="color:#fd7e14;"></i>
          <span>Cancellation Requests</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link collapsed" href="replacement_requests.php?location=<?php echo htmlspecialchars($sidebarLocation); ?>">
          <i class="bi bi-arrow-left-right" style="color:#0d6efd;"></i>
          <span>Replacement Requests</span>
        </a>
      </li><!-- End Dashboard Nav -->
    </ul>
  </aside>

  <!-- Include SweetAlert2 JS -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.4/dist/sweetalert2.all.min.js"></script>

  <script>
    function generateCertificate() {
      Swal.fire({
        title: 'Generate Certificate',
        html: 'This feature will be available in the future. <br>Stay tuned.',
        icon: 'question',
        confirmButtonText: 'Okeeeh! ♥'
      });
    }
  </script>
</body>
</html>
