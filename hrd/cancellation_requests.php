<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

$reviewerName = $_SESSION['name'] ?? $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cancellation Requests – OTRS HRD</title>
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
.status-badge-pending  { background:#fd7e14; color:#fff; }
.status-badge-approved { background:#198754; color:#fff; }
.status-badge-rejected { background:#dc3545; color:#fff; }
.status-badge          { display:inline-block; padding:.25em .65em; border-radius:4px; font-size:.78rem; font-weight:600; }

.filter-tabs { border-bottom:2px solid #dee2e6; margin-bottom:1rem; }
.filter-tabs .nav-link { color:#555; font-size:.85rem; padding:.45rem .9rem; border:none; border-bottom:3px solid transparent; }
.filter-tabs .nav-link:hover { color:#012970; }
.filter-tabs .nav-link.active { color:#012970; font-weight:700; border-bottom:3px solid #012970; }

.history-timeline { padding:0; }
.history-item { display:flex; gap:.9rem; margin-bottom:1.2rem; }
.history-icon  { width:34px; text-align:center; flex-shrink:0; }
.history-body  { flex:1; }
.history-action { font-weight:700; font-size:.9rem; }
.history-actor  { font-size:.8rem; color:#6c757d; }
.history-remark { font-size:.85rem; background:#f4f6fa; padding:.4rem .7rem; border-radius:6px; margin-top:.3rem; }
.history-time   { font-size:.73rem; color:#adb5bd; margin-top:.2rem; }

.remark-cell { max-width:220px; white-space:normal; word-break:break-word; }
</style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-amber"><i class="bi bi-clipboard-x-fill"></i></div>
                <div>
                    <h1>Cancellation Requests</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Cancellation Requests</li>
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

                <!-- ── STATUS FILTER TABS ──────────────────────────────── -->
                <ul class="nav filter-tabs" id="statusTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-status="all" href="#" role="tab">
                            <i class="bi bi-list-ul me-1"></i>All
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-status="pending" href="#" role="tab">
                            <i class="bi bi-hourglass-split me-1"></i>Pending
                            <span id="badgePending" class="badge bg-warning text-dark ms-1" style="display:none;"></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-status="approved" href="#" role="tab">
                            <i class="bi bi-check-circle me-1"></i>Approved
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-status="rejected" href="#" role="tab">
                            <i class="bi bi-x-circle me-1"></i>Rejected
                        </a>
                    </li>
                </ul>

                <!-- ── TABLE ──────────────────────────────────────────── -->
                <div class="table-responsive">
                    <table id="cancelTable" class="table table-bordered table-hover table-striped w-100" style="font-size:13px;">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">#</th>
                                <th>Participant</th>
                                <th>Training</th>
                                <th class="text-center">Date of Training</th>
                                <th>Remarks</th>
                                <th class="text-center">Status</th>
                                <th>Action Officer</th>
                                <th class="text-center">Date Requested</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="cancelTbody">
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

<!-- ══ HISTORY MODAL ════════════════════════════════════════════════════ -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#0077b6;">
                <span class="modal-title text-white fw-bold">
                    <i class="bi bi-clock-history me-2"></i>History Log
                </span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="historyBody">
                <div class="text-center py-3"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ REJECT MODAL ═════════════════════════════════════════════════════ -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <span class="modal-title fw-bold">
                    <i class="bi bi-x-circle me-2"></i>Reject Cancellation Request
                </span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-1 text-muted" style="font-size:.88rem;">
                    Provide a reason for rejecting this request (optional):
                </p>
                <textarea id="rejectRemarks" class="form-control mt-2" rows="3"
                          placeholder="Enter remarks…"></textarea>
                <input type="hidden" id="rejectRequestId">
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" id="confirmRejectBtn">
                    <i class="bi bi-x-circle me-1"></i>Confirm Reject
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ SCRIPTS ═══════════════════════════════════════════════════════════ -->
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.1.9/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="../../assets/js/main.js"></script>

<script>
let currentStatusFilter = 'all';
let dtInstance = null;

/* ── UTILS ──────────────────────────────────────────────────────────── */
function esc(s) {
    if (!s) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(ds) {
    if (!ds || ds === '0000-00-00' || ds === '0000-00-00 00:00:00') return '—';
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
    const s = new Date(start), e = new Date(end);
    const os = s.toLocaleDateString('en-PH', { month:'short', day:'numeric' });
    const oe = e.toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
    return os + ' – ' + oe;
}

function statusBadge(st) {
    const map = {
        pending:  '<span class="status-badge status-badge-pending">Pending</span>',
        approved: '<span class="status-badge status-badge-approved">Approved</span>',
        rejected: '<span class="status-badge status-badge-rejected">Rejected</span>',
    };
    return map[st] || `<span class="status-badge" style="background:#6c757d;color:#fff;">${esc(st)}</span>`;
}

/* ── LOAD TABLE ─────────────────────────────────────────────────────── */
function loadRequests(status) {
    currentStatusFilter = status;

    $('#cancelTbody').html('<tr><td colspan="9" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading…</td></tr>');

    if (dtInstance) { dtInstance.destroy(); dtInstance = null; }

    fetch(`api/fetch_cancellation_requests.php?status=${encodeURIComponent(status)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'success') {
                $('#cancelTbody').html(`<tr><td colspan="9" class="text-center text-danger">${esc(res.message || 'Failed to load data.')}</td></tr>`);
                return;
            }

            const pc = res.pending_count || 0;
            if (pc > 0) {
                $('#badgePending').text(pc).show();
            } else {
                $('#badgePending').hide();
            }

            const rows = res.data || [];
            if (!rows.length) {
                $('#cancelTbody').html('<tr><td colspan="9" class="text-center py-4 text-muted">No cancellation requests found.</td></tr>');
                return;
            }

            let html = '';
            rows.forEach((r, i) => {
                const actionOfficer = r.requested_by
                    ? `${esc(r.requested_by)}<br><small class="text-muted">${esc((r.requested_by_location || '').toUpperCase())}</small>`
                    : '—';

                const actionBtns = r.status === 'pending'
                    ? (r.is_orphaned == 1
                        ? `<button class="btn btn-danger btn-sm w-100" onclick="openRejectModal(${r.id})">
                               <i class="bi bi-x-circle me-1"></i>Reject
                           </button>`
                        : `<button class="btn btn-success btn-sm mb-1 w-100" onclick="approveRequest(${r.id})">
                               <i class="bi bi-check-circle me-1"></i>Approve
                           </button>
                           <button class="btn btn-danger btn-sm w-100" onclick="openRejectModal(${r.id})">
                               <i class="bi bi-x-circle me-1"></i>Reject
                           </button>`)
                    : '';

                const participantCell = r.participant_name
                    ? esc(r.participant_name)
                    : '<span class="text-danger fst-italic">Deleted participant</span>';
                const trainingCell = r.training_name
                    ? esc(r.training_name)
                    : '<span class="text-danger fst-italic">Deleted training</span>';

                html += `<tr>
                    <td class="text-center align-middle">${i + 1}</td>
                    <td class="align-middle fw-semibold">${participantCell}</td>
                    <td class="align-middle">${trainingCell}</td>
                    <td class="text-center align-middle">${fmtDateRange(r.start_date, r.end_date)}</td>
                    <td class="remark-cell align-middle">${esc(r.remarks)}</td>
                    <td class="text-center align-middle">${statusBadge(r.status)}</td>
                    <td class="align-middle" style="font-size:.84rem;">${actionOfficer}</td>
                    <td class="text-center align-middle">${fmtDateTime(r.requested_at)}</td>
                    <td class="text-center align-middle" style="min-width:110px;">
                        ${actionBtns}
                        <button class="btn btn-outline-secondary btn-sm mt-1 w-100" onclick="showHistory(${r.id})">
                            <i class="bi bi-clock-history me-1"></i>History
                        </button>
                    </td>
                </tr>`;
            });

            $('#cancelTbody').html(html);

            dtInstance = $('#cancelTable').DataTable({
                pageLength: 15,
                lengthMenu: [10, 15, 25, 50],
                order:      [[7, 'desc']],
                columnDefs: [{ targets: [0, 5, 7, 8], orderable: false }],
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
            $('#cancelTbody').html('<tr><td colspan="9" class="text-center text-danger">Request failed. Please try again.</td></tr>');
        });
}

/* ── APPROVE ────────────────────────────────────────────────────────── */
function approveRequest(requestId) {
    Swal.fire({
        icon:  'question',
        title: 'Approve Cancellation?',
        html:  'This will mark the participant as <strong>Cancelled</strong> and restore the training slot.',
        showCancelButton:   true,
        confirmButtonText:  '<i class="bi bi-check-circle me-1"></i>Yes, Approve',
        confirmButtonColor: '#198754',
        cancelButtonText:   'Go Back',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const fd = new FormData();
            fd.append('requestId',      requestId);
            fd.append('action',         'approve');
            fd.append('review_remarks', '');
            fd.append('_csrf', window.csrfToken ?? '');
            return fetch('api/process_cancellation.php', { method:'POST', body:fd, headers:{ 'X-CSRF-Token': window.csrfToken ?? '' } })
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'success') Swal.showValidationMessage(res.message);
                    return res;
                })
                .catch(() => Swal.showValidationMessage('Request failed.'));
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then(result => {
        if (result.isConfirmed && result.value?.status === 'success') {
            Swal.fire({ icon:'success', title:'Approved!', text:result.value.message, timer:2200, showConfirmButton:false });
            loadRequests(currentStatusFilter);
        }
    });
}

/* ── REJECT ─────────────────────────────────────────────────────────── */
function openRejectModal(requestId) {
    $('#rejectRequestId').val(requestId);
    $('#rejectRemarks').val('');
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

$('#confirmRejectBtn').on('click', function () {
    const requestId = $('#rejectRequestId').val();
    const remarks   = $('#rejectRemarks').val().trim();
    const $btn      = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Rejecting…');

    const fd = new FormData();
    fd.append('requestId',      requestId);
    fd.append('action',         'reject');
    fd.append('review_remarks', remarks);
    fd.append('_csrf', window.csrfToken ?? '');

    fetch('api/process_cancellation.php', { method:'POST', body:fd, headers:{ 'X-CSRF-Token': window.csrfToken ?? '' } })
        .then(r => r.json())
        .then(res => {
            $btn.prop('disabled', false).html('<i class="bi bi-x-circle me-1"></i>Confirm Reject');
            bootstrap.Modal.getInstance(document.getElementById('rejectModal')).hide();
            if (res.status === 'success') {
                Swal.fire({ icon:'info', title:'Rejected', text:res.message, timer:2200, showConfirmButton:false });
                loadRequests(currentStatusFilter);
            } else {
                Swal.fire({ icon:'error', title:'Error', text:res.message });
            }
        })
        .catch(() => {
            $btn.prop('disabled', false).html('<i class="bi bi-x-circle me-1"></i>Confirm Reject');
            Swal.fire({ icon:'error', title:'Error', text:'Request failed.' });
        });
});

/* ── HISTORY ────────────────────────────────────────────────────────── */
function showHistory(requestId) {
    $('#historyBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    new bootstrap.Modal(document.getElementById('historyModal')).show();

    fetch(`../api/cancellation_logs.php?request_id=${requestId}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'success' || !res.data || !res.data.length) {
                $('#historyBody').html('<p class="text-muted text-center py-3">No history available.</p>');
                return;
            }
            const iconMap = {
                submitted: { icon:'bi-send-fill',        color:'#0077b6', label:'Request Submitted' },
                approved:  { icon:'bi-check-circle-fill', color:'#198754', label:'Approved by HRD'   },
                rejected:  { icon:'bi-x-circle-fill',     color:'#dc3545', label:'Rejected by HRD'   },
            };
            let html = '<div class="history-timeline">';
            res.data.forEach(log => {
                const cfg = iconMap[log.action] || { icon:'bi-circle-fill', color:'#6c757d', label: log.action };
                html += `
                <div class="history-item">
                    <div class="history-icon">
                        <i class="bi ${cfg.icon} fs-4" style="color:${cfg.color};"></i>
                    </div>
                    <div class="history-body">
                        <div class="history-action" style="color:${cfg.color};">${cfg.label}</div>
                        <div class="history-actor">${esc(log.actor)}
                            ${log.actor_location ? ' <span class="badge bg-secondary" style="font-size:.68rem;">' + esc(log.actor_location.toUpperCase()) + '</span>' : ''}
                        </div>
                        ${log.remarks ? `<div class="history-remark">${esc(log.remarks)}</div>` : ''}
                        <div class="history-time">${fmtDateTime(log.created_at)}</div>
                    </div>
                </div>`;
            });
            html += '</div>';
            $('#historyBody').html(html);
        })
        .catch(() => {
            $('#historyBody').html('<p class="text-danger text-center py-3">Failed to load history.</p>');
        });
}

/* ── INIT ───────────────────────────────────────────────────────────── */
$(function () {
    loadRequests('all');

    $('#statusTabs .nav-link').on('click', function (e) {
        e.preventDefault();
        $('#statusTabs .nav-link').removeClass('active');
        $(this).addClass('active');
        loadRequests($(this).data('status'));
    });
});
</script>

</body>
</html>
