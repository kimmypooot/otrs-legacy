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
<title>Cancellation Requests – <?php echo htmlspecialchars($locationText); ?></title>
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
.history-action { font-weight:700; font-size:.88rem; }
.history-actor  { font-size:.78rem; color:#64748b; }
.history-remark { font-size:.82rem; background:#f1f5f9; padding:.4rem .7rem; border-radius:6px; margin-top:.3rem; color:#334155; }
.history-time   { font-size:.72rem; color:#94a3b8; margin-top:.2rem; }
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
                            <li class="breadcrumb-item"><a href="index?location=<?php echo urlencode($location); ?>">Home</a></li>
                            <li class="breadcrumb-item"><?php echo strtoupper(htmlspecialchars($location)); ?></li>
                            <li class="breadcrumb-item active">Cancellation Requests</li>
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
                <strong><?php echo htmlspecialchars($locationText); ?></strong> — Cancellation requests
                submitted by your office are listed below. HRD approval is required before a registration
                is marked as Cancelled.
            </span>
        </div>

        <div class="otrs-card">

            <!-- ── TOP BAR: filter tabs ── -->
            <div class="otrs-card-top">
                <div class="seg-tabs" id="statusTabs" role="tablist">
                    <button class="seg-tab active" data-status="all" type="button">
                        <i class="bi bi-list-ul"></i> All
                    </button>
                    <button class="seg-tab" data-status="pending" type="button">
                        <span class="tab-dot" style="background:#f59e0b;"></span> Pending
                        <span id="badgePending" class="tab-count" style="display:none;"></span>
                    </button>
                    <button class="seg-tab" data-status="approved" type="button">
                        <span class="tab-dot" style="background:#16a34a;"></span> Approved
                    </button>
                    <button class="seg-tab" data-status="rejected" type="button">
                        <span class="tab-dot" style="background:#ef4444;"></span> Rejected
                    </button>
                </div>
            </div>

            <!-- ── TABLE ── -->
            <div class="otrs-card-body">
                <div class="table-responsive">
                    <table id="cancelTable" class="table table-bordered table-hover table-striped otrs-table w-100">
                        <thead>
                            <tr>
                                <th class="text-center">#</th>
                                <th>Participant</th>
                                <th>Training</th>
                                <th class="text-center">Date of Training</th>
                                <th class="text-center">Status</th>
                                <th>Action Officer</th>
                                <th class="text-center">Date Requested</th>
                                <th class="text-center">History</th>
                            </tr>
                        </thead>
                        <tbody id="cancelTbody">
                            <tr><td colspan="8" class="text-center py-4 text-muted">
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

<!-- ══ HISTORY MODAL ════════════════════════════════════════════════════ -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-blue">
                <div class="modal-title-wrap">
                    <div class="modal-avatar"><i class="bi bi-clock-history"></i></div>
                    <div class="modal-title-text">
                        <h6>History Log</h6>
                        <small>Audit trail for this cancellation request</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="historyBody">
                <div class="text-center py-3"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer-branded">
                <button class="btn-modal-cancel" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Close
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
<script src="../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>

<script>
let dtInstance = null;

/* ── UTILS ──────────────────────────────────────────────────────────── */
function esc(s) {
    if (!s) return '';
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

function statusBadge(st) {
    const map = {
        pending:  '<span class="req-badge req-pending">Pending</span>',
        approved: '<span class="req-badge req-approved">Approved</span>',
        rejected: '<span class="req-badge req-rejected">Rejected</span>',
    };
    return map[st] || `<span class="req-badge" style="background:#e2e8f0;color:#475569;">${esc(st)}</span>`;
}

/* ── LOAD TABLE ─────────────────────────────────────────────────────── */
function loadRequests(status) {
    $('#cancelTbody').html('<tr><td colspan="8" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading…</td></tr>');
    if (dtInstance) { dtInstance.destroy(); dtInstance = null; }

    const _cfd = new FormData(); _cfd.append('_csrf', window.csrfToken ?? '');
    _cfd.append('location', <?php echo json_encode($location); ?>);
    fetch('api/field-office/fetch_my_cancellation_requests.php', { method:'POST', body: _cfd, headers: { 'X-CSRF-Token': window.csrfToken ?? '' } })
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'success') {
                $('#cancelTbody').html(`<tr><td colspan="8" class="text-center text-danger">${esc(res.message || 'Failed to load.')}</td></tr>`);
                return;
            }

            let rows = res.data || [];

            // Client-side filter by status tab
            if (status !== 'all') rows = rows.filter(r => r.status === status);

            const pendingCount = (res.data || []).filter(r => r.status === 'pending').length;
            if (pendingCount > 0) {
                $('#badgePending').text(pendingCount).show();
            } else {
                $('#badgePending').hide();
            }

            if (!rows.length) {
                $('#cancelTbody').html('<tr><td colspan="8" class="text-center py-4 text-muted">No cancellation requests found.</td></tr>');
                return;
            }

            let html = '';
            rows.forEach((r, i) => {
                // For FO: "Action Officer" = who approved/rejected (reviewed_by)
                // For pending: show who requested (that's the FO itself, so show "Awaiting HRD"
                let actionOfficer;
                if (r.status === 'pending') {
                    actionOfficer = '<em class="text-muted">Awaiting HRD Review</em>';
                } else {
                    actionOfficer = r.reviewed_by
                        ? `${esc(r.reviewed_by)}<br><small class="text-muted">HRD</small>`
                        : '—';
                }

                html += `<tr>
                    <td class="text-center align-middle">${i + 1}</td>
                    <td class="align-middle fw-semibold">${esc(r.participant_name)}</td>
                    <td class="align-middle">${esc(r.training_name)}</td>
                    <td class="text-center align-middle">${fmtDateRange(r.start_date, r.end_date)}</td>
                    <td class="text-center align-middle">${statusBadge(r.status)}</td>
                    <td class="align-middle" style="font-size:.84rem;">${actionOfficer}</td>
                    <td class="text-center align-middle">${fmtDateTime(r.requested_at)}</td>
                    <td class="text-center align-middle">
                        <button class="btn-f btn-f-secondary" style="font-size:.72rem;padding:.22rem .6rem;" onclick="showHistory(${r.id})">
                            <i class="bi bi-clock-history"></i> Logs
                        </button>
                    </td>
                </tr>`;
            });

            $('#cancelTbody').html(html);

            dtInstance = $('#cancelTable').DataTable({
                pageLength: 15,
                lengthMenu: [10, 15, 25, 50],
                order:      [[6, 'desc']],
                columnDefs: [{ targets: [0, 4, 7], orderable: false }],
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
            $('#cancelTbody').html('<tr><td colspan="8" class="text-center text-danger">Request failed. Please try again.</td></tr>');
        });
}

/* ── HISTORY ────────────────────────────────────────────────────────── */
function showHistory(requestId) {
    $('#historyBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    new bootstrap.Modal(document.getElementById('historyModal')).show();

    fetch(`api/cancellation_logs.php?request_id=${requestId}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'success' || !res.data || !res.data.length) {
                $('#historyBody').html('<p class="text-muted text-center py-3">No history available.</p>');
                return;
            }
            const iconMap = {
                submitted: { icon:'bi-send-fill',        cls:'tl-reg',    color:'#1d4ed8', label:'Request Submitted' },
                approved:  { icon:'bi-check-circle-fill', cls:'tl-paid',   color:'#15803d', label:'Approved by HRD'   },
                rejected:  { icon:'bi-x-circle-fill',     cls:'tl-cancel', color:'#991b1b', label:'Rejected by HRD'   },
            };
            let html = '<ul class="otrs-timeline">';
            res.data.forEach(log => {
                const cfg = iconMap[log.action] || { icon:'bi-circle-fill', cls:'tl-info', color:'#64748b', label: log.action };
                html += `
                <li class="otrs-tl-item">
                    <div class="otrs-tl-dot ${cfg.cls}"><i class="bi ${cfg.icon}"></i></div>
                    <div class="otrs-tl-body">
                        <div class="history-action" style="color:${cfg.color};">${cfg.label}</div>
                        <div class="history-actor">${esc(log.actor)}
                            ${log.actor_location ? ' <span class="ts-badge ts-default" style="font-size:.6rem;">' + esc(log.actor_location.toUpperCase()) + '</span>' : ''}
                        </div>
                        ${log.remarks ? `<div class="history-remark">${esc(log.remarks)}</div>` : ''}
                        <div class="history-time">${fmtDateTime(log.created_at)}</div>
                    </div>
                </li>`;
            });
            html += '</ul>';
            $('#historyBody').html(html);
        })
        .catch(() => {
            $('#historyBody').html('<p class="text-danger text-center py-3">Failed to load history.</p>');
        });
}

/* ── INIT ───────────────────────────────────────────────────────────── */
$(function () {
    loadRequests('all');

    $('#statusTabs .seg-tab').on('click', function (e) {
        e.preventDefault();
        $('#statusTabs .seg-tab').removeClass('active');
        $(this).addClass('active');
        loadRequests($(this).data('status'));
    });
});
</script>

</body>
</html>
