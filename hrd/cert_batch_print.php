<?php
// imis/otrs/hrd/cert_batch_print.php
// Batch download page — lists all issued certificates for selected
// participants and lets staff open / download each PDF.
// URL: cert_batch_print.php?ids=1,2,3&tid={training_id}&category=completion|certification|merged|merged_certification
// category=completion (default) reads the legacy otrs_certificates table and
// links to cert_view.php, exactly as before. certification/merged/merged_certification
// are CCS-only — they read otrs_ccs_certificates (keyed by participant_id +
// training_id + category) and link to ccs_cert_view.php instead.

require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

$trainingId = filter_input(INPUT_GET, 'tid', FILTER_VALIDATE_INT);
$rawIds     = $_GET['ids'] ?? '';
$ids        = array_values(array_filter(array_map('intval', explode(',', $rawIds)), fn($v) => $v > 0));

$category = strtolower(trim($_GET['category'] ?? 'completion'));
if (!in_array($category, ['completion', 'certification', 'merged', 'merged_certification'], true)) {
    $category = 'completion';
}
$isCcs = $category !== 'completion';
$categoryLabel = [
    'completion'            => 'Completion',
    'certification'         => 'Certification',
    'merged'                => 'Merged — Completion + Appearance',
    'merged_certification'  => 'Merged — Certification + Appearance',
][$category];

if (empty($ids) || !$trainingId) {
    die('<div style="font-family:sans-serif;padding:2rem;color:red;">Invalid request: missing IDs.</div>');
}
$ids = array_slice($ids, 0, 100);

try {
    $stmtT = $conn->prepare("SELECT name FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$training) die('Training not found.');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($isCcs) {
        $stmt = $conn->prepare("
            SELECT cc.id AS cert_id, cc.certificate_number, cc.certificate_file,
                   cc.issued_at, cc.status AS cert_status,
                   p.fname, p.mname, p.lname, p.suffix, p.agency_name, p.location
            FROM   otrs_ccs_certificates cc
            JOIN   otrs_participants p ON p.id = cc.participant_id
            WHERE  cc.participant_id IN ({$placeholders})
              AND  cc.training_id = ?
              AND  cc.category = ?
              AND  cc.status = 'active'
            ORDER BY p.lname, p.fname
        ");
        $stmt->execute([...$ids, $trainingId, $category]);
        $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtNoCert = $conn->prepare("
            SELECT p.id, p.fname, p.mname, p.lname, p.suffix, p.agency_name, p.location
            FROM   otrs_participants p
            WHERE  p.id IN ({$pPlaceholders})
              AND  p.id NOT IN (
                  SELECT participant_id FROM otrs_ccs_certificates
                  WHERE training_id = ? AND category = ? AND status = 'active'
              )
            ORDER BY p.lname, p.fname
        ");
        $stmtNoCert->execute([...$ids, $trainingId, $category]);
        $noCerts = $stmtNoCert->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare("
            SELECT c.id AS cert_id, c.certificate_number, c.certificate_file,
                   c.issued_at, c.status AS cert_status,
                   p.fname, p.mname, p.lname, p.suffix, p.agency_name, p.location
            FROM   otrs_certificates c
            JOIN   otrs_participants p ON p.id = c.participant_id
            WHERE  c.participant_id IN ({$placeholders})
              AND  c.training_id = ?
              AND  c.status = 'active'
            ORDER BY p.lname, p.fname
        ");
        $stmt->execute([...$ids, $trainingId]);
        $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also find participants without certificates
        $pPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtNoCert = $conn->prepare("
            SELECT p.id, p.fname, p.mname, p.lname, p.suffix, p.agency_name, p.location
            FROM   otrs_participants p
            WHERE  p.id IN ({$pPlaceholders})
              AND  p.id NOT IN (
                  SELECT participant_id FROM otrs_certificates
                  WHERE training_id = ? AND status = 'active'
              )
            ORDER BY p.lname, p.fname
        ");
        $stmtNoCert->execute([...$ids, $trainingId]);
        $noCerts = $stmtNoCert->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
}

$certCount   = count($certs);
$noCertCount = count($noCerts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch <?php echo htmlspecialchars($categoryLabel); ?> — <?php echo htmlspecialchars($training['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Nunito', sans-serif; background: #f0f4f8; padding: 0; margin: 0; }

        /* Toolbar */
        .batch-toolbar {
            background: #1a2a4a; color: #fff;
            padding: .7rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
            gap: .75rem; flex-wrap: wrap;
            box-shadow: 0 2px 8px rgba(0,0,0,.3);
            position: sticky; top: 0; z-index: 10;
        }
        .batch-toolbar .info { font-size: .82rem; opacity: .9; }
        .batch-toolbar .info strong { color: #e8c96a; }
        .btn-tb { font-size: .78rem; font-weight: 700; border: none; border-radius: 5px;
                  padding: .38rem .95rem; cursor: pointer;
                  display: inline-flex; align-items: center; gap: .35rem; transition: filter .15s; }
        .btn-tb:hover { filter: brightness(.85); }
        .btn-tb-gold    { background: #c19836; color: #fff; }
        .btn-tb-muted   { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.25); }

        /* Content area */
        .content-wrap { max-width: 960px; margin: 1.5rem auto; padding: 0 1rem 2rem; }

        /* Training strip */
        .t-strip { background: linear-gradient(135deg,#0077b6,#023e8a);
                   border-radius: 10px; padding: .85rem 1.25rem; color: #fff; margin-bottom: 1.25rem; }
        .t-strip-name  { font-size: 1rem; font-weight: 700; margin-bottom: .3rem; }
        .t-strip-meta  { font-size: .75rem; opacity: .85; }

        /* Stat bar */
        .stat-bar { display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
        .stat-card { flex: 1 1 160px; border-radius: 8px; padding: .85rem 1rem;
                     display: flex; align-items: center; gap: .7rem; }
        .stat-icon { width: 40px; height: 40px; border-radius: 8px;
                     display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink:0; }
        .stat-val   { font-size: 1.4rem; font-weight: 800; line-height: 1; }
        .stat-lbl   { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; opacity: .7; }
        .sc-ready   { background: #edfbf3; color: #155724; }
        .sc-ready .stat-icon   { background: #c6f4d9; color: #198754; }
        .sc-missing { background: #fff3cd; color: #7a4700; }
        .sc-missing .stat-icon { background: #fde8c0; color: #e07b00; }

        /* Section label */
        .sec-label { font-size: .68rem; font-weight: 700; text-transform: uppercase;
                     letter-spacing: 1.5px; color: #899bbd;
                     border-bottom: 1px solid #ebeef4; padding-bottom: .4rem; margin-bottom: .85rem; }

        /* Cert cards */
        .cert-card { background: #fff; border-radius: 8px; border: 1px solid #ebeef4;
                     padding: .85rem 1rem; display: flex; align-items: center;
                     gap: 1rem; margin-bottom: .5rem; }
        .cert-card:hover { border-color: #0077b6; box-shadow: 0 2px 8px rgba(0,119,182,.1); }
        .cert-icon { width: 42px; height: 42px; border-radius: 8px;
                     background: #e6f2ff; color: #0077b6;
                     display: flex; align-items: center; justify-content: center;
                     font-size: 1.2rem; flex-shrink: 0; }
        .cert-name { font-size: .88rem; font-weight: 700; color: #012970; margin-bottom: 1px; }
        .cert-meta { font-size: .72rem; color: #6c757d; }
        .cert-refno { font-family: monospace; font-size: .72rem; color: #0077b6; }
        .cert-actions { margin-left: auto; display: flex; gap: .4rem; flex-shrink: 0; }

        /* Missing cert cards */
        .no-cert-card { background: #fffbf0; border: 1px solid #fde8c0;
                        border-radius: 8px; padding: .75rem 1rem;
                        display: flex; align-items: center; gap: .85rem; margin-bottom: .45rem; }
        .no-cert-icon { width: 36px; height: 36px; border-radius: 7px;
                        background: #fde8c0; color: #e07b00;
                        display: flex; align-items: center; justify-content: center;
                        font-size: 1rem; flex-shrink: 0; }
        .no-cert-name { font-size: .85rem; font-weight: 600; color: #7a4700; }

        @media print { .batch-toolbar { display: none; } }
    </style>
</head>
<body>

<!-- Toolbar -->
<div class="batch-toolbar">
    <div class="info">
        Batch <?php echo htmlspecialchars($categoryLabel); ?> &mdash;
        <strong><?php echo htmlspecialchars($training['name']); ?></strong>
        &nbsp;&middot;&nbsp;
        <?php echo $certCount; ?> certificate<?php echo $certCount !== 1 ? 's' : ''; ?> ready
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <button class="btn-tb btn-tb-gold" onclick="openAll()">
            <i class="bi bi-box-arrow-up-right"></i> Open All PDFs
        </button>
        <button class="btn-tb btn-tb-muted" onclick="window.close()">
            <i class="bi bi-x-lg"></i> Close
        </button>
    </div>
</div>

<div class="content-wrap">

    <!-- Training info -->
    <div class="t-strip">
        <div class="t-strip-name"><?php echo htmlspecialchars($training['name']); ?></div>
        <div class="t-strip-meta"><?php echo htmlspecialchars($categoryLabel); ?> Certificate Batch</div>
    </div>

    <!-- Stat bar -->
    <div class="stat-bar">
        <div class="stat-card sc-ready">
            <div class="stat-icon"><i class="bi bi-patch-check-fill"></i></div>
            <div>
                <div class="stat-val"><?php echo $certCount; ?></div>
                <div class="stat-lbl">Ready to Print</div>
            </div>
        </div>
        <?php if ($noCertCount > 0): ?>
        <div class="stat-card sc-missing">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div>
                <div class="stat-val"><?php echo $noCertCount; ?></div>
                <div class="stat-lbl">Not Yet Issued</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($certs)): ?>
        <div class="alert alert-warning">
            <strong>No issued certificates found</strong> for the selected participants.
            Please issue certificates from the Certificate Management page first.
        </div>
    <?php else: ?>

    <!-- Issued certificates -->
    <p class="sec-label"><i class="bi bi-patch-check me-1"></i> Issued Certificates</p>
    <?php $viewerScript = $isCcs ? 'ccs_cert_view.php' : 'cert_view.php'; ?>
    <?php foreach ($certs as $c):
        $mi     = $c['mname'] ? $c['mname'][0] . '. ' : '';
        $sfx    = !empty(trim($c['suffix'] ?? '')) ? ', ' . trim($c['suffix']) : '';
        $name   = trim("{$c['fname']} {$mi}{$c['lname']}") . $sfx;
        $issued = date('M j, Y', strtotime($c['issued_at']));
    ?>
    <div class="cert-card">
        <div class="cert-icon"><i class="bi bi-file-earmark-pdf-fill"></i></div>
        <div>
            <div class="cert-name"><?php echo htmlspecialchars(strtoupper($name)); ?></div>
            <div class="cert-meta"><?php echo htmlspecialchars($c['agency_name']); ?> &middot; <?php echo htmlspecialchars($c['location']); ?></div>
            <div class="cert-refno"><?php echo htmlspecialchars($c['certificate_number']); ?> &middot; Issued <?php echo $issued; ?></div>
        </div>
        <div class="cert-actions">
            <a href="<?php echo $viewerScript; ?>?id=<?php echo (int)$c['cert_id']; ?>"
               target="_blank"
               class="btn btn-primary btn-sm"
               title="View / Print PDF">
                <i class="bi bi-printer-fill me-1"></i>Print
            </a>
            <a href="<?php echo $viewerScript; ?>?id=<?php echo (int)$c['cert_id']; ?>"
               download
               class="btn btn-outline-secondary btn-sm"
               title="Download PDF">
                <i class="bi bi-download"></i>
            </a>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

    <?php if (!empty($noCerts)): ?>
    <p class="sec-label mt-4"><i class="bi bi-exclamation-triangle me-1"></i> Not Yet Issued</p>
    <?php foreach ($noCerts as $p):
        $mi   = $p['mname'] ? $p['mname'][0] . '. ' : '';
        $sfx  = !empty(trim($p['suffix'] ?? '')) ? ', ' . trim($p['suffix']) : '';
        $name = trim("{$p['fname']} {$mi}{$p['lname']}") . $sfx;
    ?>
    <div class="no-cert-card">
        <div class="no-cert-icon"><i class="bi bi-dash-circle-fill"></i></div>
        <div>
            <div class="no-cert-name"><?php echo htmlspecialchars(strtoupper($name)); ?></div>
            <div style="font-size:.72rem;color:#a06000;"><?php echo htmlspecialchars($p['agency_name']); ?></div>
        </div>
        <div style="margin-left:auto;">
            <span class="badge bg-warning text-dark" style="font-size:.7rem;">No Certificate</span>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
// Store cert view URLs for "Open All"
const certUrls = [
    <?php foreach ($certs as $c): ?>
    '<?php echo $viewerScript; ?>?id=<?php echo (int)$c['cert_id']; ?>',
    <?php endforeach; ?>
];

function openAll() {
    if (certUrls.length === 0) return alert('No certificates to open.');
    certUrls.forEach((url, i) => setTimeout(() => window.open(url, '_blank'), i * 300));
}
</script>
</body>
</html>