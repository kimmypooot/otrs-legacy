<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['username'])) {
    header('Location: ../login.php');
    exit();
}

$sessionType  = strtolower($_SESSION['type'] ?? '');
$location     = ($sessionType === 'hrd')
    ? strtolower(filter_input(INPUT_GET, 'location', FILTER_SANITIZE_SPECIAL_CHARS) ?? '')
    : $sessionType;
$locationLabels = [
    ''      => 'CSC RO VIII – Human Resource Division',
    'hrd'   => 'CSC RO VIII – Human Resource Division',
    'lfoi'  => 'CSC Field Office – Leyte I',
    'lfoii' => 'CSC Field Office – Leyte II',
    'bfo'   => 'CSC Field Office – Biliran',
    'wlso'  => 'CSC Satellite Office – Western Leyte',
    'slfo'  => 'CSC Field Office – Southern Leyte',
    'sfo'   => 'CSC Field Office – Samar',
    'esfo'  => 'CSC Field Office – Eastern Samar',
    'nsfo'  => 'CSC Field Office – Northern Samar',
    'ro6'   => 'CSC Regional Office VI',
    'ro7'   => 'CSC Regional Office VII',
];
$locationText = $locationLabels[$location] ?? strtoupper($location);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Replacement Requests – <?php echo htmlspecialchars($locationText); ?></title>
<link href="assets/img/favicon.png" rel="icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>" rel="stylesheet">
<link href="../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
<link href="../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<link href="assets/css/field-office.css?v=<?php echo filemtime(__DIR__.'/assets/css/field-office.css'); ?>" rel="stylesheet">

<style>
.participant-card {
    background: #f8fafc;
    border: 1px solid #ebeef4;
    border-radius: 8px;
    padding: .5rem .75rem;
    font-size: .8rem;
    line-height: 1.5;
}
.participant-card .pname { font-weight: 700; font-size: .85rem; color: #012970; }
.participant-card .pmeta { color: #64748b; font-size: .73rem; }
.arrow-col { text-align: center; vertical-align: middle !important; color: #0077b6; }
.remark-cell { max-width: 200px; white-space: normal; word-break: break-word; font-size: .8rem; }
</style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-blue"><i class="bi bi-arrow-left-right"></i></div>
                <div>
                    <h1>Replacement Requests</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index?location=<?php echo urlencode($location); ?>">Home</a></li>
                            <li class="breadcrumb-item"><?php echo strtoupper(htmlspecialchars($location)); ?></li>
                            <li class="breadcrumb-item active">Replacement Requests</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="index?location=<?php echo urlencode($location); ?>" class="otrs-btn-back">
                <i class="bi bi-arrow-left-circle"></i> Go Back
            </a>
        </div>
    </div>

    <section class="section">

        <!-- Office info banner -->
        <div class="otrs-alert otrs-alert-info">
            <i class="bi bi-info-circle-fill"></i>
            <span>
                <strong><?php echo htmlspecialchars($locationText); ?></strong> — Participant replacements
                performed by your office are listed below.
            </span>
        </div>

        <div class="otrs-card">
            <div class="otrs-card-body">
                <div class="table-responsive">
                    <table id="replaceTable" class="table table-bordered table-hover table-striped otrs-table w-100">
                        <thead>
                            <tr>
                                <th class="text-center">#</th>
                                <th>Original Participant</th>
                                <th class="text-center" style="width:34px;"></th>
                                <th>Replacement Participant</th>
                                <th>Training</th>
                                <th class="text-center">Date of Training</th>
                                <th>Replaced By</th>
                                <th class="text-center">Date Replaced</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody id="replaceTbody">
                            <tr><td colspan="9" class="text-center py-4 text-muted">
                                <div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading…
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include 'inc/footer.php' ?>
<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<!-- ══ SCRIPTS ═══════════════════════════════════════════════════════════ -->
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>

<script>
function esc(s) {
    if (!s) return '—';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(ds) {
    if (!ds || ds === '0000-00-00') return '—';
    const d = new Date(ds);
    return d.toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' });
}

function fmtDateTime(ds) {
    if (!ds) return '—';
    const d = new Date(ds);
    return d.toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' })
         + ' ' + d.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit' });
}

function fmtDateRange(start, end) {
    if (!start || start === '0000-00-00') return '—';
    if (!end || end === '0000-00-00' || start === end) return fmtDate(start);
    const os = new Date(start).toLocaleDateString('en-PH', { month:'short', day:'numeric' });
    const oe = new Date(end).toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
    return os + ' – ' + oe;
}

function participantCard(name, position, agency) {
    return `<div class="participant-card">
        <div class="pname">${esc(name)}</div>
        ${position ? `<div class="pmeta">${esc(position)}</div>` : ''}
        ${agency   ? `<div class="pmeta">${esc(agency)}</div>`   : ''}
    </div>`;
}

$(function () {
    const _rfd = new FormData(); _rfd.append('_csrf', window.csrfToken ?? '');
    _rfd.append('location', <?php echo json_encode($location); ?>);
    fetch('api/field-office/fetch_my_replacements.php', { method: 'POST', body: _rfd, headers: { 'X-CSRF-Token': window.csrfToken ?? '' } })
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'success') {
                $('#replaceTbody').html(`<tr><td colspan="9" class="text-center text-danger">${esc(res.message || 'Failed to load.')}</td></tr>`);
                return;
            }

            const rows = res.data || [];
            if (!rows.length) {
                $('#replaceTbody').html('<tr><td colspan="9" class="text-center py-4 text-muted">No replacement records found for your office.</td></tr>');
                return;
            }

            let html = '';
            rows.forEach((r, i) => {
                html += `<tr>
                    <td class="text-center align-middle">${i + 1}</td>
                    <td class="align-middle">${participantCard(r.original_name, r.original_position, r.original_agency)}</td>
                    <td class="arrow-col"><i class="bi bi-arrow-right fs-5"></i></td>
                    <td class="align-middle">${participantCard(r.new_name, r.new_position, r.new_agency)}</td>
                    <td class="align-middle" style="font-size:.84rem;">${esc(r.training_name)}</td>
                    <td class="text-center align-middle">${fmtDateRange(r.start_date, r.end_date)}</td>
                    <td class="align-middle" style="font-size:.84rem;">${esc(r.replaced_by)}</td>
                    <td class="text-center align-middle">${fmtDateTime(r.replaced_at)}</td>
                    <td class="remark-cell align-middle">${esc(r.replacement_reason)}</td>
                </tr>`;
            });

            $('#replaceTbody').html(html);

            $('#replaceTable').DataTable({
                pageLength: 15,
                lengthMenu: [10, 15, 25, 50],
                order:      [[7, 'desc']],
                columnDefs: [{ targets: [0, 2], orderable: false }],
                language: {
                    search: '', searchPlaceholder: 'Search…',
                    paginate: {
                        next:     '<i class="bi bi-chevron-right"></i>',
                        previous: '<i class="bi bi-chevron-left"></i>'
                    }
                }
            });
        })
        .catch(() => {
            $('#replaceTbody').html('<tr><td colspan="9" class="text-center text-danger">Request failed. Please try again.</td></tr>');
        });
});
</script>

</body>
</html>
