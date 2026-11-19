<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

// Summary counts for stat strip
try {
    $stmtStats = $conn->query("
        SELECT
            COUNT(DISTINCT p.id)                                        AS total_participants,
            COUNT(DISTINCT r.id)                                        AS total_registrations,
            COUNT(DISTINCT CASE WHEN r.payment_status = 'paid'
                           THEN r.id END)                               AS total_paid,
            COUNT(DISTINCT CASE WHEN c.status = 'active'
                           THEN c.id END)                               AS total_certs
        FROM otrs_participants p
        INNER JOIN (
            SELECT fname, lname, MAX(id) AS max_id
            FROM   otrs_participants GROUP BY fname, lname
        ) latest ON p.id = latest.max_id
        LEFT JOIN otrs_registrations r ON r.participant_id = p.id
        LEFT JOIN otrs_certificates  c ON c.participant_id = p.id
    ");
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['total_participants' => 0, 'total_registrations' => 0, 'total_paid' => 0, 'total_certs' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>OTRS — Participants Data</title>

    <link href="../assets/img/favicon.png" rel="icon">
    <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

    <style>
        /* ── stat strip ── */
        .ip-stats { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
        .ip-stat  {
            flex:1; min-width:140px; background:#fff; border-radius:10px;
            border:1px solid #dee2e6; padding:.9rem 1.1rem;
            display:flex; align-items:center; gap:.85rem; box-shadow:0 1px 4px rgba(0,0,0,.05);
        }
        .ip-stat-icon {
            width:44px; height:44px; border-radius:50%; display:flex;
            align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;
        }
        .ip-stat-val  { font-size:1.45rem; font-weight:700; color:#012970; line-height:1; }
        .ip-stat-lbl  { font-size:.72rem; color:#6c757d; margin-top:.15rem; }
        /* ── loading overlay ── */
        #loadingOverlay {
            position:fixed; inset:0; background:rgba(255,255,255,.85);
            display:none; justify-content:center; align-items:center;
            flex-direction:column; z-index:9999;
        }
        #loadingOverlay .spinner {
            border:8px solid #f3f3f3; border-top:8px solid #3498db;
            border-radius:50%; width:60px; height:60px;
            animation:spin 1s linear infinite;
        }
        @keyframes spin { to { transform:rotate(360deg); } }
        #loadingOverlay p { margin-top:15px; font-size:16px; color:#333; }
        /* ── profile modal ── */
        .pm-profile {
            display:flex; align-items:center; gap:1rem;
            padding:.75rem 1rem; background:#f0f4ff;
            border-radius:8px; margin-bottom:1rem;
        }
        .pm-avatar {
            width:52px; height:52px; border-radius:50%;
            background:linear-gradient(135deg,#012970,#0a58ca);
            display:flex; align-items:center; justify-content:center;
            color:#fff; font-size:1.4rem; font-weight:700; flex-shrink:0;
        }
        .pm-name  { font-size:.97rem; font-weight:700; color:#012970; }
        .pm-meta  { font-size:.78rem; color:#555; margin-top:.1rem; }
        .pm-chips { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1rem; }
        .pm-chip  {
            display:flex; align-items:center; gap:.35rem;
            padding:.28rem .7rem; border-radius:20px;
            font-size:.75rem; font-weight:600;
        }
        .pm-chip-blue   { background:#dbeafe; color:#1e3a8a; }
        .pm-chip-green  { background:#d1fae5; color:#065f46; }
        .pm-chip-amber  { background:#fef3c7; color:#92400e; }
        /* payment badge colours */
        .pb-paid            { background:#d1fae5; color:#065f46; }
        .pb-pending         { background:#dbeafe; color:#1e3a8a; }
        .pb-free            { background:#e0f2fe; color:#075985; }
        .pb-cancelled       { background:#fee2e2; color:#991b1b; }
        .pb-promissory      { background:#fef3c7; color:#92400e; }
        /* training status */
        .ts-completed  { background:#d1fae5; color:#065f46; }
        .ts-ongoing    { background:#dbeafe; color:#1e3a8a; }
        .ts-upcoming   { background:#ede9fe; color:#4c1d95; }
        .ts-cancelled  { background:#fee2e2; color:#991b1b; }
        /* cert badge */
        .cert-yes { background:#d1fae5; color:#065f46; }
        .cert-no  { background:#f3f4f6; color:#6b7280; }
    </style>
</head>

<body>
    <?php imis_include('header_js') ?>
    <?php include 'inc/sidebar.php' ?>

    <main id="main" class="main">

        <div class="pagetitle otrs-ph">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="otrs-ph-icon icon-amber"><i class="bi bi-person-lines-fill"></i></div>
                    <div>
                        <h1>Participants Data</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="index">Home</a></li>
                                <li class="breadcrumb-item">HRD</li>
                                <li class="breadcrumb-item active">Participants Data</li>
                            </ol>
                        </nav>
                    </div>
                </div>
                <a href="index" class="otrs-btn-back"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
            </div>
        </div>

        <section class="section">

            <!-- Stat strip -->
            <div class="ip-stats">
                <div class="ip-stat">
                    <div class="ip-stat-icon" style="background:#dbeafe">
                        <i class="bi bi-people-fill" style="color:#1e3a8a"></i>
                    </div>
                    <div>
                        <div class="ip-stat-val"><?php echo number_format($stats['total_participants']); ?></div>
                        <div class="ip-stat-lbl">Unique Participants</div>
                    </div>
                </div>
                <div class="ip-stat">
                    <div class="ip-stat-icon" style="background:#ede9fe">
                        <i class="bi bi-journal-check" style="color:#4c1d95"></i>
                    </div>
                    <div>
                        <div class="ip-stat-val"><?php echo number_format($stats['total_registrations']); ?></div>
                        <div class="ip-stat-lbl">Total Registrations</div>
                    </div>
                </div>
                <div class="ip-stat">
                    <div class="ip-stat-icon" style="background:#d1fae5">
                        <i class="bi bi-cash-coin" style="color:#065f46"></i>
                    </div>
                    <div>
                        <div class="ip-stat-val"><?php echo number_format($stats['total_paid']); ?></div>
                        <div class="ip-stat-lbl">Paid Registrations</div>
                    </div>
                </div>
                <div class="ip-stat">
                    <div class="ip-stat-icon" style="background:#fef3c7">
                        <i class="bi bi-patch-check-fill" style="color:#92400e"></i>
                    </div>
                    <div>
                        <div class="ip-stat-val"><?php echo number_format($stats['total_certs']); ?></div>
                        <div class="ip-stat-lbl">Certificates Issued</div>
                    </div>
                </div>
            </div>

            <div class="otrs-card">
                <div class="otrs-card-top">
                    <p class="otrs-section-label mb-0"><i class="bi bi-funnel"></i> Filter by Field Office</p>
                    <select id="filterDropdown" class="form-select form-select-sm" style="width:280px;">
                        <option value="">All Offices</option>
                        <option value="HRD">CSC RO VIII — HRD</option>
                        <option value="LFOI">CSC Field Office — Leyte I</option>
                        <option value="LFOII">CSC Field Office — Leyte II</option>
                        <option value="BFO">CSC Field Office — Biliran</option>
                        <option value="WLSO">CSC Satellite Office — Western Leyte</option>
                        <option value="SLFO">CSC Field Office — Southern Leyte</option>
                        <option value="SFO">CSC Field Office — Samar</option>
                        <option value="ESFO">CSC Field Office — Eastern Samar</option>
                        <option value="NSFO">CSC Field Office — Northern Samar</option>
                    </select>
                </div>
                <div class="otrs-card-body">
                    <table id="participantData" class="table table-bordered table-hover table-striped otrs-table w-100">
                        <thead>
                            <tr>
                                <th class="text-center">#</th>
                                <th class="text-center">Name</th>
                                <th class="text-center">Position</th>
                                <th class="text-center">Mobile</th>
                                <th class="text-center">Email</th>
                                <th class="text-center">Agency</th>
                                <th class="text-center">FO</th>
                                <th class="text-center">Trainings</th>
                                <th class="text-center">Certs</th>
                                <th class="text-center" style="width:60px">View</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

        </section>
    </main>

    <?php imis_include('footer') ?>

    <div id="loadingOverlay">
        <div class="spinner"></div>
        <p>Loading participant data…</p>
    </div>

    <!-- Participant Profile Modal -->
    <div class="modal fade" id="participantModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <div class="modal-title-wrap flex-grow-1">
                        <div class="modal-avatar"><i class="bi bi-clock-history"></i></div>
                        <div class="modal-title-text">
                            <h6 class="mb-0">Training History</h6>
                            <small id="pmModalSubtitle" class="text-muted">—</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4 pb-4 pt-3" id="pmModalBody">
                    <p class="text-center text-muted py-4">
                        <span class="spinner-border spinner-border-sm me-2"></span>Loading…
                    </p>
                </div>

                <div class="modal-footer modal-footer-branded">
                    <button type="button" class="btn-f btn-f-success me-auto" id="pmExportBtn" style="display:none">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <a href="#" class="back-to-top d-flex align-items-center justify-content-center">
        <i class="bi bi-arrow-up-short"></i>
    </a>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    <script>
    const overlay = document.getElementById('loadingOverlay');
    const CSRF    = <?php echo json_encode($_SESSION['_csrf_token'] ?? ''); ?>;

    function showOverlay() { overlay.style.display = 'flex'; }
    function hideOverlay() { overlay.style.display = 'none'; }

    // ── badge helpers ──────────────────────────────────────────────────────────
    const PAYMENT_CLS = {
        'paid'            : 'pb-paid',
        'pending'         : 'pb-pending',
        'free'            : 'pb-free',
        'cancelled'       : 'pb-cancelled',
        'promissory note' : 'pb-promissory',
    };
    const TRAINING_CLS = {
        'completed' : 'ts-completed',
        'ongoing'   : 'ts-ongoing',
        'upcoming'  : 'ts-upcoming',
        'cancelled' : 'ts-cancelled',
    };
    function badge(text, classMap, fallback) {
        const key = (text || '').toLowerCase().trim();
        const cls = classMap[key] || fallback;
        const label = text ? text.charAt(0).toUpperCase() + text.slice(1) : '—';
        return `<span class="badge ${cls}" style="font-weight:600;font-size:.72rem">${label}</span>`;
    }

    // ── main table ─────────────────────────────────────────────────────────────
    $(function () {
        var table = $('#participantData').DataTable({
            ajax: function (data, callback) {
                showOverlay();
                fetch('api/fetch_participants_db.php')
                    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then(json => { hideOverlay(); callback({ data: json.data || [] }); })
                    .catch(() => {
                        hideOverlay();
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load participant data.' });
                    });
            },
            columns: [
                { data: 'id',            className: 'text-center' },
                { data: 'full_name' },
                { data: 'positiontitle' },
                { data: 'mobileno' },
                { data: 'email' },
                { data: 'agency_name' },
                { data: 'location',      className: 'text-center' },
                { data: 'training_count',className: 'text-center', orderable: false },
                { data: 'cert_count',    className: 'text-center', orderable: false },
                { data: 'action',        className: 'text-center', orderable: false, searchable: false },
            ],
            paging:    true,
            searching: true,
            info:      true,
            order:     [[1, 'asc']],
        });

        $('#filterDropdown').on('change', function () {
            const val = $(this).val();
            if (!val) { table.column(6).search('').draw(); return; }
            table.column(6).search('\\b' + $.fn.dataTable.util.escapeRegex(val) + '\\b', true, false).draw();
        });

        // ── participant modal ──────────────────────────────────────────────────
        var pmTable  = null;
        var pmModal  = bootstrap.Modal.getOrCreateInstance(document.getElementById('participantModal'));

        document.addEventListener('click', async function (e) {
            const btn = e.target.closest('.view-participant-btn');
            if (!btn) return;

            const pid   = btn.dataset.pid;
            const name  = btn.dataset.name;

            document.getElementById('pmModalSubtitle').textContent = name;
            document.getElementById('pmModalBody').innerHTML =
                '<p class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</p>';
            document.getElementById('pmExportBtn').style.display = 'none';

            if (pmTable) { pmTable.destroy(); pmTable = null; }
            pmModal.show();

            try {
                const fd = new FormData();
                fd.append('pid',  pid);
                fd.append('_csrf', CSRF);
                const res  = await fetch('api/fetch_trng_data.php', {
                    method: 'POST', body: fd,
                    headers: { 'X-CSRF-Token': CSRF }
                });
                const json = await res.json();

                if (!json.success) {
                    document.getElementById('pmModalBody').innerHTML =
                        `<p class="text-danger text-center py-4">${json.message || 'Failed to load data.'}</p>`;
                    return;
                }

                const p   = json.profile;
                const s   = json.summary;
                const trn = json.trainings;

                // ── profile header ────────────────────────────────────────────
                const initials = (p.display_name || '?').split(' ').map(w => w[0]).slice(0,2).join('');

                // payment breakdown chips
                const pbMap = { paid:'pm-chip-green', pending:'pm-chip-blue', free:'pm-chip-blue',
                                'promissory note':'pm-chip-amber', cancelled:'pm-chip-amber' };
                let pbChips = '';
                for (const [k, v] of Object.entries(s.payment_breakdown || {})) {
                    const cls = pbMap[k] || 'pm-chip-blue';
                    const lbl = k.charAt(0).toUpperCase() + k.slice(1);
                    pbChips += `<span class="pm-chip ${cls}"><i class="bi bi-circle-fill" style="font-size:.45rem"></i>${lbl}: ${v}</span>`;
                }

                // ── training rows ─────────────────────────────────────────────
                let rows = '';
                trn.forEach((t, i) => {
                    const certBadge = t.certificate_number
                        ? `<span class="badge cert-yes" style="font-size:.7rem" title="${t.certificate_number}"><i class="bi bi-patch-check-fill me-1"></i>Issued</span>`
                        : `<span class="badge cert-no"  style="font-size:.7rem">—</span>`;
                    rows += `<tr>
                        <td class="text-center">${i + 1}</td>
                        <td>${t.training}</td>
                        <td>${t.display_date || '—'}</td>
                        <td>${badge(t.training_status, TRAINING_CLS, 'bg-secondary')}</td>
                        <td>${badge(t.payment_status,  PAYMENT_CLS,  'bg-secondary')}</td>
                        <td class="text-center">${certBadge}</td>
                        <td style="font-size:.74rem;color:#666">${t.registered_at || '—'}</td>
                    </tr>`;
                });

                if (!rows) rows = '<tr><td colspan="7" class="text-center text-muted py-3">No training records found.</td></tr>';

                document.getElementById('pmModalBody').innerHTML = `
                    <div class="pm-profile">
                        <div class="pm-avatar">${initials}</div>
                        <div class="flex-grow-1">
                            <div class="pm-name">${p.display_name}</div>
                            <div class="pm-meta">${p.position_title} &bull; ${p.agency_name}</div>
                            <div class="pm-meta"><i class="bi bi-telephone me-1"></i>${p.mobile_no}
                                &nbsp;|&nbsp;<i class="bi bi-envelope me-1"></i>${p.email}</div>
                        </div>
                    </div>
                    <div class="pm-chips">
                        <span class="pm-chip pm-chip-blue"><i class="bi bi-journal-check"></i>Trainings: ${s.total_trainings}</span>
                        <span class="pm-chip pm-chip-green"><i class="bi bi-check-circle-fill"></i>Completed: ${s.completed}</span>
                        <span class="pm-chip pm-chip-amber"><i class="bi bi-patch-check-fill"></i>Certs: ${s.total_certs}</span>
                        ${pbChips}
                    </div>
                    <div class="table-responsive">
                        <table id="pmHistoryTable" class="table table-bordered table-hover table-striped otrs-table w-100" style="font-size:.82rem">
                            <thead>
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Training</th>
                                    <th>Date</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Payment</th>
                                    <th class="text-center">Certificate</th>
                                    <th class="text-center">Registered</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>`;

                pmTable = $('#pmHistoryTable').DataTable({
                    responsive: true,
                    pageLength: 10,
                    dom: '<"d-flex justify-content-between align-items-center mb-2"lf>t<"d-flex justify-content-between mt-2"ip>',
                    order: [],
                    buttons: [{
                        extend: 'excelHtml5',
                        filename: p.display_name + ' — Training History',
                        exportOptions: { columns: ':visible' }
                    }]
                });

                const exportBtn = document.getElementById('pmExportBtn');
                exportBtn.style.display = '';
                exportBtn.onclick = () => pmTable.button('.buttons-excel').trigger();

            } catch (err) {
                document.getElementById('pmModalBody').innerHTML =
                    '<p class="text-danger text-center py-4">Failed to load training history.</p>';
            }
        });

        document.getElementById('participantModal').addEventListener('hidden.bs.modal', function () {
            if (pmTable) { pmTable.destroy(); pmTable = null; }
        });
    });
    </script>
</body>
</html>
