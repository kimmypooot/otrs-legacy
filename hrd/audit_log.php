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
    <title>OTRS — Activity Audit Log</title>
    <link href="../assets/img/favicon.png" rel="icon">
    <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.0.1/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

    <style>
        .al-badge {
            display: inline-block; padding: .2rem .55rem;
            border-radius: 20px; font-size: .7rem; font-weight: 700;
        }
        .al-participant_added      { background:#d1fae5; color:#065f46; }
        .al-participant_updated    { background:#dbeafe; color:#1e3a8a; }
        .al-participant_deleted    { background:#fee2e2; color:#991b1b; }
        .al-participant_transferred{ background:#ede9fe; color:#4c1d95; }
        .al-cancellation_approved  { background:#d1fae5; color:#065f46; }
        .al-cancellation_rejected  { background:#fee2e2; color:#991b1b; }
        .al-submission_approved    { background:#d1fae5; color:#065f46; }
        .al-submission_rejected    { background:#fee2e2; color:#991b1b; }
        .al-cert_issued            { background:#fef3c7; color:#92400e; }
        .al-participant_waitlisted { background:#fef9c3; color:#854d0e; }
        .al-waitlist_promoted      { background:#d1fae5; color:#065f46; }
        .al-waitlist_withdrawn     { background:#f3f4f6; color:#374151; }
        .al-default                { background:#f3f4f6; color:#374151; }
        .al-details {
            font-size:.7rem; color:#64748b; cursor:pointer;
            max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        }
        .filter-bar { display:flex; flex-wrap:wrap; gap:.6rem; align-items:flex-end; margin-bottom:1rem; }
        .filter-bar label { font-size:.75rem; font-weight:600; color:#012970; margin-bottom:.2rem; display:block; }
        .filter-bar .form-control, .filter-bar .form-select { font-size:.8rem; }
    </style>
</head>
<body>
    <?php imis_include('header_js') ?>
    <?php include 'inc/sidebar.php' ?>

    <main id="main" class="main">

        <div class="pagetitle otrs-ph">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="otrs-ph-icon icon-amber"><i class="bi bi-journal-text"></i></div>
                    <div>
                        <h1>Activity Audit Log</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="index">Home</a></li>
                                <li class="breadcrumb-item">HRD</li>
                                <li class="breadcrumb-item active">Audit Log</li>
                            </ol>
                        </nav>
                    </div>
                </div>
                <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
            </div>
        </div>

        <section class="section">
            <div class="otrs-card">
                <div class="otrs-card-top">
                    <p class="otrs-section-label mb-0"><i class="bi bi-funnel"></i> Filters</p>
                </div>
                <div class="otrs-card-body">

                    <div class="filter-bar">
                        <div>
                            <label for="fAction">Action Type</label>
                            <select id="fAction" class="form-select form-select-sm" style="min-width:180px;">
                                <option value="">All Actions</option>
                                <option value="participant_added">Participant Added</option>
                                <option value="participant_updated">Participant Updated</option>
                                <option value="participant_deleted">Participant Deleted</option>
                                <option value="participant_transferred">Participant Transferred</option>
                                <option value="cancellation_approved">Cancellation Approved</option>
                                <option value="cancellation_rejected">Cancellation Rejected</option>
                                <option value="submission_approved">Submission Approved</option>
                                <option value="submission_rejected">Submission Rejected</option>
                                <option value="cert_issued">Certificate Issued</option>
                                <option value="participant_waitlisted">Participant Waitlisted</option>
                                <option value="waitlist_promoted">Waitlist Promoted</option>
                                <option value="waitlist_withdrawn">Waitlist Withdrawn</option>
                            </select>
                        </div>
                        <div>
                            <label for="fDateFrom">Date From</label>
                            <input type="date" id="fDateFrom" class="form-control form-control-sm" style="min-width:140px;">
                        </div>
                        <div>
                            <label for="fDateTo">Date To</label>
                            <input type="date" id="fDateTo" class="form-control form-control-sm" style="min-width:140px;">
                        </div>
                        <div>
                            <label for="fSearch">Search</label>
                            <input type="text" id="fSearch" class="form-control form-control-sm"
                                   placeholder="Name, user, details…" style="min-width:200px;">
                        </div>
                        <div style="padding-bottom:.1rem;">
                            <button id="applyFilter" class="btn-f btn-f-primary">
                                <i class="bi bi-search me-1"></i> Apply
                            </button>
                            <button id="clearFilter" class="btn-f btn-f-secondary ms-1">
                                <i class="bi bi-x-circle me-1"></i> Clear
                            </button>
                        </div>
                    </div>

                    <table id="auditTable" class="table table-bordered table-hover table-striped otrs-table w-100">
                        <thead>
                            <tr>
                                <th class="text-center">#</th>
                                <th class="text-center">Action</th>
                                <th>Participant</th>
                                <th>Training</th>
                                <th>Details</th>
                                <th class="text-center">Performed By</th>
                                <th class="text-center">Date &amp; Time</th>
                                <th class="text-center">IP</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>

                </div>
            </div>
        </section>
    </main>

    <?php imis_include('footer') ?>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    <script>
    function buildUrl() {
        const p = new URLSearchParams();
        const a  = document.getElementById('fAction').value;
        const df = document.getElementById('fDateFrom').value;
        const dt = document.getElementById('fDateTo').value;
        const s  = document.getElementById('fSearch').value.trim();
        if (a)  p.set('action',    a);
        if (df) p.set('date_from', df);
        if (dt) p.set('date_to',   dt);
        if (s)  p.set('search',    s);
        return 'api/fetch_audit_log.php?' + p.toString();
    }

    const ACTION_CLS = {
        participant_added:       'al-participant_added',
        participant_updated:     'al-participant_updated',
        participant_deleted:     'al-participant_deleted',
        participant_transferred: 'al-participant_transferred',
        cancellation_approved:   'al-cancellation_approved',
        cancellation_rejected:   'al-cancellation_rejected',
        submission_approved:     'al-submission_approved',
        submission_rejected:     'al-submission_rejected',
        cert_issued:             'al-cert_issued',
        participant_waitlisted:  'al-participant_waitlisted',
        waitlist_promoted:       'al-waitlist_promoted',
        waitlist_withdrawn:      'al-waitlist_withdrawn',
    };

    let dt = null;

    function loadTable() {
        if (dt) { dt.destroy(); dt = null; }
        const url = buildUrl();
        $('#auditTable tbody').html('<tr><td colspan="8" class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</td></tr>');

        fetch(url)
            .then(r => r.json())
            .then(json => {
                if (!json.success) {
                    $('#auditTable tbody').html('<tr><td colspan="8" class="text-danger text-center py-3">Failed to load data.</td></tr>');
                    return;
                }
                dt = $('#auditTable').DataTable({
                    destroy: true,
                    data:    json.data,
                    columns: [
                        { data: 'num',              className: 'text-center' },
                        { data: 'action_raw',       className: 'text-center',
                          render: (d, t, r) => {
                            const cls = ACTION_CLS[d] || 'al-default';
                            return `<span class="al-badge ${cls}">${r.action}</span>`;
                          }
                        },
                        { data: 'participant_name' },
                        { data: 'training_name' },
                        { data: 'details',
                          render: (d) => d
                            ? `<span class="al-details" title="${d}">${d}</span>`
                            : '<span class="text-muted">—</span>'
                        },
                        { data: 'performed_by',  className: 'text-center' },
                        { data: 'performed_at',  className: 'text-center',
                          render: d => d ? new Date(d).toLocaleString('en-PH', {month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—'
                        },
                        { data: 'ip_address',    className: 'text-center' },
                    ],
                    order:   [[6, 'desc']],
                    pageLength: 25,
                    dom: '<"d-flex justify-content-between align-items-center mb-2"lBf>t<"d-flex justify-content-between mt-2"ip>',
                    buttons: [{
                        extend: 'excelHtml5',
                        text: '<i class="bi bi-file-earmark-excel me-1"></i>Export',
                        className: 'btn btn-success btn-sm',
                        filename: 'OTRS_Audit_Log_' + new Date().toISOString().slice(0,10),
                    }],
                });
            })
            .catch(() => {
                $('#auditTable tbody').html('<tr><td colspan="8" class="text-danger text-center py-3">Request failed.</td></tr>');
            });
    }

    document.getElementById('applyFilter').addEventListener('click', loadTable);
    document.getElementById('clearFilter').addEventListener('click', () => {
        document.getElementById('fAction').value   = '';
        document.getElementById('fDateFrom').value = '';
        document.getElementById('fDateTo').value   = '';
        document.getElementById('fSearch').value   = '';
        loadTable();
    });

    // Click on truncated details to show full content
    document.addEventListener('click', e => {
        const el = e.target.closest('.al-details');
        if (!el) return;
        let parsed = el.title;
        try {
            const obj = JSON.parse(parsed);
            parsed = Object.entries(obj).map(([k,v]) => `${k}: ${v ?? '—'}`).join('\n');
        } catch {}
        Swal.fire({ title: 'Details', text: parsed, icon: 'info' });
    });

    loadTable();
    </script>
</body>
</html>
