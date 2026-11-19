<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT ct.id,
               ct.token,
               ct.is_used,
               ct.used_at,
               ct.used_ip,
               ct.used_ua,
               ct.expires_at,
               ct.created_at,
               cert.certificate_number,
               CONCAT(p.lname, ', ', p.fname,
                      IF(p.mname <> '', CONCAT(' ', p.mname), '')) AS participant_name,
               t.name AS training_name,
               t.start_date
        FROM   otrs_claim_tokens ct
        JOIN   otrs_certificates cert ON cert.id = ct.certificate_id
        JOIN   otrs_participants  p    ON p.id   = ct.participant_id
        JOIN   otrs_trainings     t    ON t.id   = ct.training_id
        ORDER BY ct.created_at DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[cert_verification_log] ' . $e->getMessage());
    $rows = [];
}

$totalScanned  = count(array_filter($rows, fn($r) => (int)$r['is_used'] === 1));
$totalPending  = count($rows) - $totalScanned;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Certificate QR Scan Log – OTRS</title>
<link href="../../assets/img/favicon.png" rel="icon">
<link href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
<link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
  .scan-used   { background: #d1fae5; color: #065f46; display:inline-flex; align-items:center; gap:.3rem;
                 padding:.2em .6em; border-radius:20px; font-size:.7rem; font-weight:700; }
  .scan-unused { background: #fef9c3; color: #854d0e; display:inline-flex; align-items:center; gap:.3rem;
                 padding:.2em .6em; border-radius:20px; font-size:.7rem; font-weight:700; }
  .ua-cell     { max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:normal;
                 font-size:.7rem; color:#64748b; cursor:default; word-break:break-word; }
</style>
</head>
<body>
<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">
  <div class="pagetitle otrs-ph">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <div class="otrs-ph-icon icon-green"><i class="bi bi-qr-code-scan"></i></div>
        <div>
          <h1>Certificate QR Scan Log</h1>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="index">Home</a></li>
              <li class="breadcrumb-item active">QR Scan Log</li>
            </ol>
          </nav>
        </div>
      </div>
      <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
    </div>
  </div>

  <section class="section">

    <!-- ── Summary strip ── -->
    <div class="otrs-stat-strip mb-3">
      <div class="otrs-stat-pill">
        <div class="sp-icon" style="background:#d1fae5;color:#065f46;"><i class="bi bi-qr-code-scan"></i></div>
        <div>
          <div class="sp-val text-success"><?= $totalScanned ?></div>
          <div class="sp-lbl">QR Scanned</div>
        </div>
      </div>
      <div class="otrs-stat-pill">
        <div class="sp-icon" style="background:#fef9c3;color:#854d0e;"><i class="bi bi-hourglass-split"></i></div>
        <div>
          <div class="sp-val" style="color:#854d0e;"><?= $totalPending ?></div>
          <div class="sp-lbl">Not Yet Scanned</div>
        </div>
      </div>
      <div class="otrs-stat-pill">
        <div class="sp-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-shield-check"></i></div>
        <div>
          <div class="sp-val text-primary"><?= count($rows) ?></div>
          <div class="sp-lbl">Total Tokens</div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm border-0">
      <div class="card-header d-flex align-items-center gap-2 bg-success text-white">
        <i class="bi bi-qr-code-scan fs-5"></i>
        <span class="fw-semibold">Certificate QR Scan &amp; Verification Log</span>
        <small class="ms-auto fw-normal opacity-75">
          Each row = one QR code token issued. "Scanned" means the QR was verified by a recipient.
        </small>
      </div>
      <div class="card-body p-3">
        <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-3" style="font-size:.82rem;">
          <i class="bi bi-info-circle-fill fs-5"></i>
          <span>
            <strong>Scanned</strong> = the recipient scanned the QR code and verified their certificate.
            <strong>Not Scanned</strong> = token issued but QR has not yet been scanned.
          </span>
        </div>
        <table id="verificationTable" class="table table-bordered table-hover table-striped w-100" style="font-size:12.5px;">
          <thead class="table-light">
            <tr>
              <th class="text-center">#</th>
              <th>Certificate No.</th>
              <th>Participant</th>
              <th>Training</th>
              <th class="text-center">QR Status</th>
              <th class="text-center">Scanned At</th>
              <th>IP Address</th>
              <th>Browser / Device</th>
              <th class="text-center">Token Expires</th>
              <th class="text-center">Issued At</th>
            </tr>
          </thead>
          <tbody>
          <?php $i = 1; foreach ($rows as $row):
              $isUsed   = (int)$row['is_used'] === 1;
              $scannedAt = $isUsed && $row['used_at']
                  ? date('M j, Y g:i A', strtotime($row['used_at']))
                  : '—';
              $expiresAt = $row['expires_at']
                  ? date('M j, Y', strtotime($row['expires_at']))
                  : '—';
              $issuedAt  = $row['created_at']
                  ? date('M j, Y g:i A', strtotime($row['created_at']))
                  : '—';
              $ua = htmlspecialchars(substr($row['used_ua'] ?? '', 0, 90), ENT_QUOTES);
          ?>
          <tr>
            <td class="text-center align-middle"><?= $i++ ?></td>
            <td class="align-middle fw-semibold" style="font-size:.78rem;"><?= htmlspecialchars($row['certificate_number'], ENT_QUOTES) ?></td>
            <td class="align-middle fw-semibold"><?= htmlspecialchars($row['participant_name'], ENT_QUOTES) ?></td>
            <td class="align-middle" style="font-size:.8rem;"><?= htmlspecialchars($row['training_name'], ENT_QUOTES) ?></td>
            <td class="text-center align-middle">
              <?php if ($isUsed): ?>
                <span class="scan-used"><i class="bi bi-check-circle-fill"></i> Scanned</span>
              <?php else: ?>
                <span class="scan-unused"><i class="bi bi-hourglass-split"></i> Not Scanned</span>
              <?php endif ?>
            </td>
            <td class="text-center align-middle" style="font-size:.78rem;"><?= $scannedAt ?></td>
            <td class="align-middle" style="font-size:.78rem;"><?= htmlspecialchars($row['used_ip'] ?? '—', ENT_QUOTES) ?></td>
            <td class="align-middle">
              <span class="ua-cell" title="<?= htmlspecialchars($row['used_ua'] ?? '', ENT_QUOTES) ?>"><?= $ua ?: '—' ?></span>
            </td>
            <td class="text-center align-middle" style="font-size:.78rem;"><?= $expiresAt ?></td>
            <td class="text-center align-middle" style="font-size:.78rem;"><?= $issuedAt ?></td>
          </tr>
          <?php endforeach ?>
          <?php if (empty($rows)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No QR scan tokens issued yet.</td></tr>
          <?php endif ?>
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
  $('#verificationTable').DataTable({
    order: [[9, 'desc']],
    pageLength: 25,
    dom: "<'row'<'col-sm-6'l><'col-sm-6'f>>" +
         "<'row'<'col-sm-12'tr>>" +
         "<'row'<'col-sm-5'i><'col-sm-7'p>>",
    language: {
      search: '',
      searchPlaceholder: 'Search records…',
      paginate: {
        next: '<i class="bi bi-chevron-right"></i>',
        previous: '<i class="bi bi-chevron-left"></i>'
      }
    }
  });
  <?php endif ?>
});
</script>
</body>
</html>
