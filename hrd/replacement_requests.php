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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Replacement Requests – OTRS HRD</title>
<link href="../../assets/img/favicon.png" rel="icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../../assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
<link href="../../assets/vendor/remixicon/remixicon.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

<style>
.participant-card {
    background: #f8f9fa;
    border-radius: 6px;
    padding: .55rem .8rem;
    font-size: .82rem;
    line-height: 1.5;
}
.participant-card .pname  { font-weight: 700; font-size: .9rem; color: #012970; }
.participant-card .pmeta  { color: #6c757d; font-size: .78rem; }
.arrow-col { text-align: center; vertical-align: middle !important; color: #0077b6; }
.remark-cell { max-width: 200px; white-space: normal; word-break: break-word; font-size: .82rem; }
.office-badge {
    display: inline-block;
    padding: .2em .55em;
    border-radius: 4px;
    font-size: .72rem;
    font-weight: 600;
    background: #e7f1ff;
    color: #0d47a1;
    text-transform: uppercase;
    letter-spacing: .04em;
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
                <div class="otrs-ph-icon icon-blue"><i class="bi bi-arrow-left-right"></i></div>
                <div>
                    <h1>Replacement Requests</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Replacement Requests</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body p-4">

                <div class="alert alert-info d-flex align-items-center gap-2 mb-3" style="font-size:.85rem;">
                    <i class="bi bi-info-circle-fill fs-5"></i>
                    <span>This log shows all participant replacements performed by HRD and Field Offices. Replacements take effect immediately.</span>
                </div>

                <!-- ── FILTERS ────────────────────────────────────────── -->
                <form id="filterForm" class="row g-2 align-items-end mb-3">
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold mb-1" style="font-size:.82rem;">Office</label>
                        <select id="filterLocation" class="form-select form-select-sm">
                            <option value="">All Offices</option>
                            <option value="hrd">HRD</option>
                            <option value="lfoi">LFO I</option>
                            <option value="lfoii">LFO II</option>
                            <option value="bfo">BFO</option>
                            <option value="wlso">WLSO</option>
                            <option value="slfo">SLFO</option>
                            <option value="sfo">SFO</option>
                            <option value="esfo">ESFO</option>
                            <option value="nsfo">NSFO</option>
                            <option value="ro6">RO VI</option>
                            <option value="ro7">RO VII</option>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label fw-semibold mb-1" style="font-size:.82rem;">From</label>
                        <input type="date" id="filterFrom" class="form-control form-control-sm">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label fw-semibold mb-1" style="font-size:.82rem;">To</label>
                        <input type="date" id="filterTo" class="form-control form-control-sm">
                    </div>
                    <div class="col-sm-auto">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-funnel me-1"></i>Filter
                        </button>
                        <button type="button" id="clearFilter" class="btn btn-outline-secondary btn-sm ms-1">Clear</button>
                    </div>
                </form>

                <!-- ── TABLE ──────────────────────────────────────────── -->
                <div class="table-responsive">
                    <table id="replaceTable" class="table table-bordered table-hover table-striped w-100" style="font-size:13px;">
                        <thead class="table-light">
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

<!-- ══ SCRIPTS ═══════════════════════════════════════════════════════════ -->
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="../../assets/js/main.js"></script>

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
        <div class="pmeta">${esc(position) !== '—' ? esc(position) : ''}</div>
        <div class="pmeta">${esc(agency) !== '—' ? esc(agency) : ''}</div>
    </div>`;
}

let dtTable = null;

function loadReplacements() {
    const params = new URLSearchParams();
    const loc  = $('#filterLocation').val();
    const from = $('#filterFrom').val();
    const to   = $('#filterTo').val();
    if (loc)  params.set('location',  loc);
    if (from) params.set('date_from', from);
    if (to)   params.set('date_to',   to);

    $('#replaceTbody').html('<tr><td colspan="9" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading…</td></tr>');
    if (dtTable) { dtTable.destroy(); dtTable = null; }

    fetch('api/fetch_replacement_requests.php?' + params.toString())
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'success') {
                $('#replaceTbody').html(`<tr><td colspan="9" class="text-center text-danger">${esc(res.message || 'Failed to load.')}</td></tr>`);
                return;
            }

            const rows = res.data || [];
            if (!rows.length) {
                $('#replaceTbody').html('<tr><td colspan="9" class="text-center py-4 text-muted">No replacement records found for the selected filters.</td></tr>');
                return;
            }

            let html = '';
            rows.forEach((r, i) => {
                const replacedBy = r.replaced_by
                    ? `${esc(r.replaced_by)}<br><span class="office-badge">${esc((r.replaced_by_location || 'hrd').toUpperCase())}</span>`
                    : '—';

                html += `<tr>
                    <td class="text-center align-middle">${i + 1}</td>
                    <td class="align-middle">${participantCard(r.original_name, r.original_position, r.original_agency)}</td>
                    <td class="arrow-col"><i class="bi bi-arrow-right fs-5"></i></td>
                    <td class="align-middle">${participantCard(r.new_name, r.new_position, r.new_agency)}</td>
                    <td class="align-middle" style="font-size:.84rem;">${esc(r.training_name)}</td>
                    <td class="text-center align-middle">${fmtDateRange(r.start_date, r.end_date)}</td>
                    <td class="align-middle" style="font-size:.84rem;">${replacedBy}</td>
                    <td class="text-center align-middle">${fmtDateTime(r.replaced_at)}</td>
                    <td class="remark-cell align-middle">${esc(r.replacement_reason)}</td>
                </tr>`;
            });

            $('#replaceTbody').html(html);

            dtTable = $('#replaceTable').DataTable({
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
}

$(function () {
    loadReplacements();

    $('#filterForm').on('submit', function (e) {
        e.preventDefault();
        loadReplacements();
    });

    $('#clearFilter').on('click', function () {
        $('#filterLocation').val('');
        $('#filterFrom, #filterTo').val('');
        loadReplacements();
    });
});
</script>

</body>
</html>
