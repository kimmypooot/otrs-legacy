<?php
// imis/otrs/hrd/ccs_dashboard.php
// Centralized Certificate System — unified repository dashboard.

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
    <title>CCS Dashboard — OTRS HRD</title>
    <link href="../assets/img/favicon.png" rel="icon">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
    <style>
        .sidebar { display: none; }
        .main    { margin-left: 0 !important; }
        /* ── Stat cards ──────────────────────────────────────────────────── */
        .ccs-stat-card {
            border: 1.5px solid #ebeef4; border-radius: 10px; background: #fff;
            padding: .9rem 1rem; display: flex; align-items: center; gap: .85rem;
            transition: box-shadow .15s, border-color .15s; height: 100%;
        }
        .ccs-stat-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.08); border-color: #d0d9f0; }
        .ccs-stat-card[data-stat-src], .ccs-stat-card[data-stat-status] {
            cursor: pointer;
        }
        .ccs-stat-card[data-stat-src]:hover, .ccs-stat-card[data-stat-status]:hover {
            border-color: #0077b6;
        }
        .ccs-stat-icon {
            width: 46px; height: 46px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 1.3rem;
        }
        .ccs-stat-icon.blue   { background: #eef4ff; color: #0077b6; }
        .ccs-stat-icon.green  { background: #edfbf3; color: #198754; }
        .ccs-stat-icon.purple { background: #f3eeff; color: #7b00cc; }
        .ccs-stat-icon.teal   { background: #e6fdf8; color: #0d9488; }
        .ccs-stat-icon.orange { background: #fff4e6; color: #fd7e14; }
        .ccs-stat-icon.amber  { background: #fff3cd; color: #d97706; }
        .ccs-stat-icon.violet { background: #f0ebff; color: #6f42c1; }
        .ccs-stat-icon.red    { background: #fdecea; color: #dc3545; }
        .ccs-stat-value { font-size: 1.5rem; font-weight: 800; color: #012970; line-height: 1.1; }
        .ccs-stat-label { font-size: .72rem; color: #5a7094; margin-top: .1rem; }

        /* ── Filter panel ────────────────────────────────────────────────── */
        .filter-label {
            font-size: .68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1.1px; color: #899bbd; margin-bottom: .3rem; display: block;
        }
        .filter-toggle-btn {
            font-size: .8rem; font-weight: 600; color: #5a7094;
            border: none; background: none; padding: 0; cursor: pointer;
            display: flex; align-items: center; gap: .3rem;
        }
        .filter-toggle-btn:hover { color: #0077b6; }

        /* ── Source / category / status badges ───────────────────────────── */
        .badge-source-public_offered { background: #0077b6; }
        .badge-source-agency         { background: #7b00cc; }
        .badge-source-internal       { background: #fd7e14; }
        .badge-source-appearance     { background: #0d9488; }
        .badge-cat-completion        { background: #198754; }
        .badge-cat-certification     { background: #0077b6; }
        .badge-cat-appearance        { background: #0d9488; }
        .badge-cat-merged                 { background: #fd7e14; }
        .badge-cat-merged_certification   { background: #6f42c1; }
        .badge-status-active         { background: #198754; }
        .badge-status-voided         { background: #dc3545; }
        .badge-status-archived       { background: #6c757d; }
        .badge-status-reissued       { background: #fd7e14; }

        /* ── DataTable tweaks ────────────────────────────────────────────── */
        #certTable thead th { font-size: .78rem; font-weight: 700; white-space: nowrap; }
        #certTable tbody td { font-size: .82rem; vertical-align: middle; }
        .dt-actions { white-space: nowrap; }
        .dt-actions .btn { padding: .18rem .4rem; font-size: .73rem; }
        .btn-copy-cert {
            border: none; background: none; padding: 0 2px; color: #adb5bd;
            line-height: 1; vertical-align: middle; cursor: pointer;
        }
        .btn-copy-cert:hover { color: #0077b6; }
        .btn-copy-cert.copied { color: #198754; }

        /* ── Bulk toolbar ────────────────────────────────────────────────── */
        #bulkToolbar {
            display: none; align-items: center; flex-wrap: wrap; gap: .5rem;
            padding: .55rem .85rem; background: #fff8e1;
            border-bottom: 1.5px solid #ffe082;
        }
        #bulkToolbar .bulk-count {
            font-size: .82rem; font-weight: 700; color: #5a3e00;
        }

        /* ── Card header row ─────────────────────────────────────────────── */
        .card-header-row {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: .5rem; padding: .7rem 1rem;
            background: #f8f9fc; border-bottom: 1.5px solid #ebeef4;
        }
        .card-header-title {
            font-size: .88rem; font-weight: 700; color: #012970;
            display: flex; align-items: center; gap: .45rem;
        }

        /* ── DataTables Bootstrap integration fixes ─────────────────────── */
        div.dataTables_wrapper div.dataTables_length,
        div.dataTables_wrapper div.dataTables_filter,
        div.dataTables_wrapper div.dataTables_info,
        div.dataTables_wrapper div.dataTables_paginate {
            padding: .6rem 1rem;
            font-size: .8rem;
        }
        div.dataTables_wrapper div.dataTables_length select {
            font-size: .8rem; padding: .2rem .5rem;
        }
        div.dataTables_wrapper div.dataTables_filter input {
            font-size: .8rem;
        }
        div.dataTables_wrapper div.dataTables_paginate .page-link {
            font-size: .78rem;
        }

        @media (max-width: 575px) {
            .ccs-stat-value { font-size: 1.25rem; }
            .ccs-stat-icon  { width: 38px; height: 38px; font-size: 1.1rem; }
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
                <div class="otrs-ph-icon icon-blue"><i class="bi bi-collection-fill"></i></div>
                <div>
                    <h1>Certificate Repository</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Certificate Repository</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#templatePreviewModal">
                    <i class="bi bi-eye-fill me-1"></i>Preview Template
                </button>
                <a href="appearance_certificates" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-person-badge-fill me-1"></i>New Appearance Batch
                </a>
                <a href="agency_certificates" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-building-fill-check me-1"></i>Agency Batch
                </a>

                <a href="index" class="btn btn-sm btn-danger">
                    <i class="bi bi-arrow-left-circle me-1"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <section class="section">

        <!-- ── Stat cards (4 per row on md+, 2 per row on mobile) ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="ccs-stat-card" data-stat-status="active" title="Show all active certificates">
                    <div class="ccs-stat-icon blue"><i class="bi bi-award-fill"></i></div>
                    <div>
                        <div class="ccs-stat-value" id="statTotal">—</div>
                        <div class="ccs-stat-label">Total Active</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ccs-stat-card" data-stat-src="public_offered" data-stat-status="active" title="Filter: OTRS Standard">
                    <div class="ccs-stat-icon green"><i class="bi bi-mortarboard-fill"></i></div>
                    <div>
                        <div class="ccs-stat-value" id="statStandard">—</div>
                        <div class="ccs-stat-label">OTRS Standard</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ccs-stat-card" data-stat-src="agency" data-stat-status="active" title="Filter: Agency-Requested">
                    <div class="ccs-stat-icon purple"><i class="bi bi-building-fill-check"></i></div>
                    <div>
                        <div class="ccs-stat-value" id="statAgency">—</div>
                        <div class="ccs-stat-label">Agency-Requested</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ccs-stat-card" data-stat-src="appearance" data-stat-status="active" title="Filter: Appearances">
                    <div class="ccs-stat-icon teal"><i class="bi bi-person-badge-fill"></i></div>
                    <div>
                        <div class="ccs-stat-value" id="statAppearance">—</div>
                        <div class="ccs-stat-label">Appearances</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ccs-stat-card" data-stat-src="internal" data-stat-status="active" title="Filter: Internal">
                    <div class="ccs-stat-icon orange"><i class="bi bi-house-gear-fill"></i></div>
                    <div>
                        <div class="ccs-stat-value" id="statInternal">—</div>
                        <div class="ccs-stat-label">Internal</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ccs-stat-card" data-stat-cat="merged" data-stat-status="active" title="Filter: Merged — Completion + Appearance">
                    <div class="ccs-stat-icon amber"><i class="bi bi-files"></i></div>
                    <div>
                        <div class="ccs-stat-value" id="statMerged">—</div>
                        <div class="ccs-stat-label">Merged (Comp+App)</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ccs-stat-card" data-stat-cat="merged_certification" data-stat-status="active" title="Filter: Merged — Certification + Appearance">
                    <div class="ccs-stat-icon violet"><i class="bi bi-patch-check-fill"></i></div>
                    <div>
                        <div class="ccs-stat-value" id="statMergedCert">—</div>
                        <div class="ccs-stat-label">Merged (Cert+App)</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ccs-stat-card" data-stat-status="voided" title="Filter: Voided">
                    <div class="ccs-stat-icon red"><i class="bi bi-slash-circle-fill"></i></div>
                    <div>
                        <div class="ccs-stat-value" id="statVoided">—</div>
                        <div class="ccs-stat-label">Voided</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Filter panel ── -->
        <div class="otrs-card mb-3">
            <div class="otrs-card-top">
                <span style="font-size:.85rem; font-weight:700; color:#3d4f6e;">
                    <i class="bi bi-funnel me-1 text-primary"></i>Filters
                </span>
                <button class="filter-toggle-btn" type="button"
                        data-bs-toggle="collapse" data-bs-target="#filterPanel"
                        aria-expanded="true" aria-controls="filterPanel">
                    <i class="bi bi-chevron-up" id="filterChevron"></i>
                    <span id="filterToggleLabel">Collapse</span>
                </button>
            </div>
            <div class="collapse show" id="filterPanel">
                <div class="otrs-card-body">
                    <div class="row g-2 align-items-end">

                        <!-- Search -->
                        <div class="col-12 col-sm-6 col-lg-3">
                            <label class="filter-label">Search</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white">
                                    <i class="bi bi-search text-muted"></i>
                                </span>
                                <input type="text" id="filterSearch" class="form-control"
                                       placeholder="Name or training title…">
                            </div>
                        </div>

                        <!-- Source -->
                        <div class="col-6 col-sm-3 col-lg-2">
                            <label class="filter-label">Source</label>
                            <select id="filterSource" class="form-select form-select-sm">
                                <option value="">All Sources</option>
                                <option value="public_offered">OTRS Standard</option>
                                <option value="agency">Agency</option>
                                <option value="appearance">Appearance</option>
                                <option value="internal">Internal</option>
                            </select>
                        </div>

                        <!-- Category -->
                        <div class="col-6 col-sm-3 col-lg-2">
                            <label class="filter-label">Category</label>
                            <select id="filterCategory" class="form-select form-select-sm">
                                <option value="">All Categories</option>
                                <option value="completion">Completion</option>
                                <option value="certification">Certification</option>
                                <option value="merged">Merged (Comp+App)</option>
                                <option value="merged_certification">Merged (Cert+App)</option>
                            </select>
                        </div>

                        <!-- Status -->
                        <div class="col-6 col-sm-3 col-lg-1">
                            <label class="filter-label">Status</label>
                            <select id="filterStatus" class="form-select form-select-sm">
                                <option value="active" selected>Active</option>
                                <option value="voided">Voided</option>
                                <option value="archived">Archived</option>
                                <option value="all">All Statuses</option>
                            </select>
                        </div>

                        <!-- Date range -->
                        <div class="col-6 col-sm-2 col-lg-2">
                            <label class="filter-label">From Date</label>
                            <input type="date" id="filterDateFrom" class="form-control form-control-sm">
                        </div>
                        <div class="col-6 col-sm-2 col-lg-2">
                            <label class="filter-label">To Date</label>
                            <input type="date" id="filterDateTo" class="form-control form-control-sm">
                        </div>

                        <!-- Buttons -->
                        <div class="col-12 col-sm-auto">
                            <div class="d-flex gap-2">
                                <button id="btnApplyFilter" class="btn btn-primary btn-sm px-3">
                                    <i class="bi bi-funnel-fill me-1"></i>Apply
                                </button>
                                <button id="btnResetFilter" class="btn btn-outline-secondary btn-sm px-3">
                                    <i class="bi bi-x-circle me-1"></i>Reset
                                </button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- ── Certificate table card ── -->
        <div class="otrs-card">

            <!-- Card header -->
            <div class="card-header-row">
                <div class="card-header-title">
                    <i class="bi bi-table text-primary"></i>
                    Certificates
                    <span class="badge rounded-pill bg-primary" id="recordCount" style="font-size:.7rem;">—</span>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <!-- Permanent toolbar: colvis + export (always visible) -->
                    <div id="dtColvisMount"></div>
                    <a id="btnExportCsv" href="#" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                    </a>
                    <!-- Bulk actions (shown only when rows selected) -->
                    <span id="bulkCountBadge" class="d-none badge bg-warning text-dark"></span>
                    <a href="#" id="btnBulkZip" class="d-none btn btn-sm btn-success">
                        <i class="bi bi-file-earmark-zip-fill me-1"></i>Download ZIP
                    </a>
                    <button id="btnBulkEmail" class="d-none btn btn-sm btn-info text-white">
                        <i class="bi bi-envelope-fill me-1"></i>Email Selected
                    </button>
                    <button id="btnBulkReissue" class="d-none btn btn-sm btn-warning text-dark">
                        <i class="bi bi-arrow-repeat me-1"></i>Re-issue Selected
                    </button>
                    <button id="btnBulkEditVenue" class="d-none btn btn-sm btn-outline-primary">
                        <i class="bi bi-geo-alt me-1"></i>Edit Venue
                    </button>
                    <button id="btnBulkVoid" class="d-none btn btn-sm btn-danger">
                        <i class="bi bi-slash-circle me-1"></i>Void Selected
                    </button>
                    <button id="btnClearSelection" class="d-none btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x me-1"></i>Clear
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table id="certTable" class="table table-hover align-middle mb-0" style="width:100%;">
                    <thead class="table-light">
                        <tr>
                            <th style="width:32px; padding-left:1rem;">
                                <input type="checkbox" id="chkAll" class="form-check-input" title="Select all">
                            </th>
                            <th style="width:40px;">#</th>
                            <th>Cert No.</th>
                            <th>Participant</th>
                            <th>Training / Activity</th>
                            <th>Venue</th>
                            <th>Category</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Issued</th>
                            <th style="width:110px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

        </div>

    </section>

</main>

<!-- ── PDF Preview Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="pdfPreviewModal" tabindex="-1" aria-labelledby="pdfPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width:900px;">
        <div class="modal-content" style="height:90vh;">
            <div class="modal-header py-2">
                <h6 class="modal-title text-white" id="pdfPreviewModalLabel">
                    <i class="bi bi-file-earmark-pdf-fill text-danger me-2"></i>
                    <span id="pdfPreviewTitle">Certificate Preview</span>
                </h6>
                <div class="d-flex align-items-center gap-2 ms-auto me-2">
                    <a id="pdfPreviewOpenNew" href="#" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open in new tab
                    </a>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="flex:1;overflow:hidden;">
                <iframe id="pdfPreviewFrame" src="" style="width:100%;height:100%;border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- ── Preview Template Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="templatePreviewModal" tabindex="-1" aria-labelledby="templatePreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title-wrap flex-grow-1">
                    <div class="modal-avatar"><i class="bi bi-eye-fill"></i></div>
                    <div class="modal-title-text">
                        <h6 class="mb-0" id="templatePreviewModalLabel">Preview Certificate Template</h6>
                        <small>Generates a sample PDF with placeholder data — nothing is saved.</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Category</label>
                        <select id="tplCategory" class="form-select form-select-sm">
                            <option value="completion">Completion</option>
                            <option value="certification">Certification</option>
                            <option value="merged">Merged — Completion + Appearance</option>
                            <option value="merged_certification">Merged — Certification + Appearance</option>
                        </select>
                    </div>
                    <div class="col-6" id="tplSourceWrap">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Source</label>
                        <select id="tplSource" class="form-select form-select-sm">
                            <option value="public_offered">OTRS Standard</option>
                            <option value="agency" selected>Agency</option>
                            <option value="internal">Internal</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Participant Name</label>
                        <input type="text" class="form-control form-control-sm" id="tplPname" placeholder="Juan Dela Cruz">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Training / Activity Title</label>
                        <input type="text" class="form-control form-control-sm" id="tplTtitle" placeholder="Sample Training Program on Public Service Excellence">
                    </div>
                    <div class="col-12" id="tplVenueWrap">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Venue</label>
                        <input type="text" class="form-control form-control-sm" id="tplVenue" placeholder="CSC Regional Office VIII, Candahug, Palo, Leyte">
                    </div>
                    <div class="col-6" id="tplModeWrap">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Training Mode</label>
                        <select id="tplMode" class="form-select form-select-sm">
                            <option value="face-to-face">Face-to-Face</option>
                            <option value="online">Online</option>
                            <option value="hybrid">Hybrid</option>
                            <option value="blended">Blended</option>
                        </select>
                    </div>
                    <div class="col-6" id="tplHrsWrap">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Credit Hours</label>
                        <input type="number" min="0" class="form-control form-control-sm" id="tplHrs" value="24">
                    </div>
                    <div class="col-6" id="tplTypeWrap">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Training Type</label>
                        <input type="text" class="form-control form-control-sm" id="tplType" placeholder="Technical">
                    </div>
                    <div class="col-6" id="tplRefWrap" style="display:none;">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Reference No.</label>
                        <input type="text" class="form-control form-control-sm" id="tplRef" placeholder="PREVIEW-0000">
                    </div>
                    <div class="col-6" id="tplPositionWrap" style="display:none;">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Position Title</label>
                        <input type="text" class="form-control form-control-sm" id="tplPosition" placeholder="Information Technology Officer II">
                    </div>
                    <div class="col-6" id="tplAgencyWrap" style="display:none;">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Agency Name</label>
                        <input type="text" class="form-control form-control-sm" id="tplAgency" placeholder="Civil Service Commission Regional Office VIII">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Start Date</label>
                        <input type="date" class="form-control form-control-sm" id="tplStart">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">End Date</label>
                        <input type="date" class="form-control form-control-sm" id="tplEnd">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="btnGenerateTemplatePreview" class="btn btn-sm btn-primary">
                    <i class="bi bi-file-earmark-pdf-fill me-1"></i>Generate Preview
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Edit Certificate Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="editCertModal" tabindex="-1" aria-labelledby="editCertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title-wrap flex-grow-1">
                    <div class="modal-avatar"><i class="bi bi-pencil-square"></i></div>
                    <div class="modal-title-text">
                        <h6 class="mb-0" id="editCertModalLabel">Edit Certificate</h6>
                        <small>Update record details</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editCertId">

                <!-- Common fields (all sources) -->
                <div class="row g-3">
                    <div class="col-12" id="editPnameWrap">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Participant Name</label>
                        <input type="text" class="form-control form-control-sm" id="editPname">
                    </div>
                    <div class="col-12" id="editTtitleWrap">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Training / Activity Title</label>
                        <input type="text" class="form-control form-control-sm" id="editTtitle">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Venue</label>
                        <input type="text" class="form-control form-control-sm" id="editVenue" placeholder="Location or city">
                    </div>
                    <div class="col-sm-6" id="editSdWrap">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Start Date</label>
                        <input type="date" class="form-control form-control-sm" id="editSd">
                    </div>
                    <div class="col-sm-6" id="editEdWrap">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">End Date</label>
                        <input type="date" class="form-control form-control-sm" id="editEd">
                    </div>
                </div>

                <!-- Non-appearance source fields -->
                <div id="editNonAppearance">
                    <hr class="my-3">
                    <p class="mb-2" style="font-size:.78rem; font-weight:700; color:#5a7094; text-transform:uppercase; letter-spacing:.8px;">
                        Training Details
                    </p>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Category</label>
                            <select class="form-select form-select-sm" id="editCategory">
                                <option value="completion">Completion</option>
                                <option value="certification">Certification</option>
                                <option value="merged">Merged — Completion + Appearance</option>
                                <option value="merged_certification">Merged — Certification + Appearance</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Training Mode</label>
                            <select class="form-select form-select-sm" id="editTmode">
                                <option value="">— Not specified —</option>
                                <option value="face-to-face">Face-to-face</option>
                                <option value="online">Online</option>
                                <option value="hybrid">Hybrid</option>
                                <option value="blended">Blended</option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">No. of Hours</label>
                            <input type="number" class="form-control form-control-sm" id="editNhrs" min="0" step="1" placeholder="0">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Training Type</label>
                            <select class="form-select form-select-sm" id="editTtype">
                                <option value="">— None —</option>
                                <option value="Technical">Technical</option>
                                <option value="Foundation">Foundation</option>
                                <option value="Leadership and Management">Leadership and Management</option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Training Level</label>
                            <input type="text" class="form-control form-control-sm" id="editTlevel" placeholder="e.g. Regular">
                        </div>
                    </div>

                    <!-- Certification-only fields -->
                    <div id="editCertificationFields" class="row g-3 mt-1" style="display:none;">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Position / Designation</label>
                            <input type="text" class="form-control form-control-sm" id="editPosition" placeholder="e.g. Administrative Officer II">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Agency / Office</label>
                            <input type="text" class="form-control form-control-sm" id="editAgency" placeholder="e.g. Department of Education">
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <small class="text-muted me-auto" id="editModalFooterNote" style="font-size:.75rem;">
                    <i class="bi bi-info-circle me-1"></i>This updates the record only. Re-issue to regenerate the PDF.
                </small>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnEditSubmit">
                    <i class="bi bi-check2 me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<?php imis_include('footer') ?>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function ($) {
    'use strict';

    const csrf      = <?= json_encode($_SESSION['_csrf_token'] ?? '') ?>;
    let selectedIds    = new Set();
    let table;
    let lastCheckedChk = null;

    // ── Filter panel chevron toggle ───────────────────────────────────────────

    document.getElementById('filterPanel').addEventListener('show.bs.collapse', function () {
        document.getElementById('filterChevron').className = 'bi bi-chevron-up';
        document.getElementById('filterToggleLabel').textContent = 'Collapse';
    });
    document.getElementById('filterPanel').addEventListener('hide.bs.collapse', function () {
        document.getElementById('filterChevron').className = 'bi bi-chevron-down';
        document.getElementById('filterToggleLabel').textContent = 'Expand';
    });

    // ── Stat cards ────────────────────────────────────────────────────────────

    function loadStats() {
        fetch('api/ccs_stats_ajax.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;
                const s = d.stats;
                document.getElementById('statTotal').textContent      = s.total_active.toLocaleString();
                document.getElementById('statStandard').textContent   = s.otrs_standard.toLocaleString();
                document.getElementById('statAgency').textContent     = s.agency.toLocaleString();
                document.getElementById('statAppearance').textContent = s.appearance.toLocaleString();
                document.getElementById('statInternal').textContent   = s.internal.toLocaleString();
                document.getElementById('statMerged').textContent     = s.merged.toLocaleString();
                document.getElementById('statMergedCert').textContent = s.merged_certification.toLocaleString();
                document.getElementById('statVoided').textContent     = s.voided.toLocaleString();
            });
    }

    // ── DataTable ─────────────────────────────────────────────────────────────

    const sourceLabel = {
        public_offered: 'OTRS Standard', agency: 'Agency',
        appearance: 'Appearance', internal: 'Internal',
    };
    const catLabel = {
        completion:            'Completion',
        certification:         'Certification',
        appearance:            'Appearance',
        merged:                'Merged (Comp+App)',
        merged_certification:  'Merged (Cert+App)',
    };

    function badge(cls, text) {
        return `<span class="badge text-white ${cls}" style="font-size:.72rem;">${escHtml(text)}</span>`;
    }

    function buildParams() {
        return {
            q:        document.getElementById('filterSearch').value.trim(),
            status:   document.getElementById('filterStatus').value,
            date_from: document.getElementById('filterDateFrom').value,
            date_to:   document.getElementById('filterDateTo').value,
            source:   document.getElementById('filterSource').value,
            category: document.getElementById('filterCategory').value,
        };
    }

    table = $('#certTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: function (dtParams, callback) {
            const extra = buildParams();
            const qs = new URLSearchParams();
            qs.set('draw',      dtParams.draw);
            qs.set('start',     dtParams.start);
            qs.set('length',    dtParams.length);
            qs.set('order_col', dtParams.order[0]?.column ?? 9);
            qs.set('order_dir', dtParams.order[0]?.dir    ?? 'desc');
            if (extra.q)         qs.set('q',         extra.q);
            if (extra.status)    qs.set('status',    extra.status);
            if (extra.date_from) qs.set('date_from', extra.date_from);
            if (extra.date_to)   qs.set('date_to',   extra.date_to);
            if (extra.source)    qs.set('source',    extra.source);
            if (extra.category)  qs.set('category',  extra.category);

            fetch('api/ccs_fetch_ajax.php?' + qs.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    const el = document.getElementById('recordCount');
                    if (el && data.recordsFiltered !== undefined) {
                        el.textContent = data.recordsFiltered.toLocaleString();
                    }
                    callback(data);
                })
                .catch(() => callback({ draw: dtParams.draw, recordsTotal: 0, recordsFiltered: 0, data: [] }));
        },
        columns: [
            { data: null, orderable: false, searchable: false,
              render: (_, __, row) =>
                `<input type="checkbox" class="form-check-input row-chk" data-id="${row.id}"
                        ${selectedIds.has(row.id) ? 'checked' : ''}>`
            },
            { data: null, orderable: false, searchable: false,
              render: (_, __, _r, meta) => `<span class="text-muted" style="font-size:.75rem;">${meta.row + 1}</span>` },
            { data: 'cert_number',
              render: d => d
                         ? `<code style="font-size:.75rem; white-space:nowrap;">${escHtml(d)}</code>`
                         + `<button class="btn-copy-cert" data-cert="${escAttr(d)}" title="Copy cert number">`
                         + `<i class="bi bi-clipboard" style="font-size:.7rem;"></i></button>`
                         : `<span class="text-muted" style="font-size:.75rem;">—</span>` },
            { data: 'participant',
              render: d => `<span style="font-size:.82rem;">${escHtml(d)}</span>` },
            { data: 'training',
              render: d => {
                  const safe = escHtml(d);
                  return d.length > 55
                    ? `<span title="${safe}" style="font-size:.8rem;">${safe.slice(0,55)}…</span>`
                    : `<span style="font-size:.8rem;">${safe}</span>`;
              }
            },
            { data: 'venue',
              render: d => {
                  if (!d) return '<span class="text-muted" style="font-size:.78rem;">—</span>';
                  const safe = escHtml(d);
                  return d.length > 40
                    ? `<span title="${safe}" style="font-size:.78rem; color:#5a7094;">${safe.slice(0,40)}…</span>`
                    : `<span style="font-size:.78rem; color:#5a7094;">${safe}</span>`;
              }
            },
            { data: 'category',  render: d => badge('badge-cat-' + d,    catLabel[d]    ?? d) },
            { data: 'source',    render: d => badge('badge-source-' + d,  sourceLabel[d] ?? d) },
            { data: 'status',    render: d => badge('badge-status-' + d,  d.charAt(0).toUpperCase() + d.slice(1)) },
            { data: 'issued_at',
              render: d => d
                ? `<span style="font-size:.78rem; white-space:nowrap;">${new Date(d).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' })}</span>`
                : '—'
            },
            { data: null, orderable: false, searchable: false,
              render: (_, __, row) => {
                const editData = escAttr(JSON.stringify({
                    id:               row.id,
                    source:           row.source           || '',
                    participant_name: row.participant_name || '',
                    training_title:   row.training_title  || '',
                    venue:            row.venue            || '',
                    start_date:       row.start_date       || '',
                    end_date:         row.end_date         || '',
                    category:         row.category         || '',
                    training_mode:    row.training_mode    || '',
                    no_hrs:           row.no_hrs           || 0,
                    training_type:    row.training_type    || '',
                    training_level:   row.training_level  || '',
                    position:         row.position         || '',
                    agency:           row.agency           || '',
                }));
                return `
                <div class="dt-actions d-flex gap-1">
                    <button class="btn btn-sm btn-outline-primary btn-dt-preview"
                            data-id="${row.id}" data-name="${escAttr(row.participant)}"
                            title="Preview PDF">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary btn-dt-edit"
                            data-row="${editData}" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-warning btn-dt-reissue"
                            data-id="${row.id}" data-name="${escAttr(row.participant)}" title="Re-issue">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                    ${row.status !== 'voided' ? `
                    <button class="btn btn-sm btn-outline-danger btn-dt-void"
                            data-id="${row.id}" data-name="${escAttr(row.participant)}" title="Void">
                        <i class="bi bi-slash-circle"></i>
                    </button>` : ''}
                </div>`;
              }
            },
        ],
        order:      [[9, 'desc']],
        pageLength: 25,
        language: {
            processing:     '<span class="spinner-border spinner-border-sm me-1 text-primary"></span> Loading…',
            emptyTable:     'No certificates found.',
            zeroRecords:    'No matching certificates found.',
            lengthMenu:     'Show _MENU_ per page',
            info:           'Showing _START_–_END_ of _TOTAL_',
            infoEmpty:      'No records',
            infoFiltered:   '(filtered from _MAX_)',
            paginate: { previous: '‹', next: '›' },
        },
        dom: 'lrtip',
        buttons: [{
            extend:    'colvis',
            text:      '<i class="bi bi-layout-three-columns me-1"></i>Columns',
            className: 'btn btn-sm btn-outline-secondary',
            columns:   ':not(:first-child):not(:nth-child(2)):not(:last-child)',
        }],
    });

    // Mount colvis button into header toolbar
    table.buttons().container().appendTo('#dtColvisMount');

    // ── Filters ───────────────────────────────────────────────────────────────

    document.getElementById('btnApplyFilter').addEventListener('click', () => table.ajax.reload());
    document.getElementById('filterSearch').addEventListener('keydown', e => {
        if (e.key === 'Enter') table.ajax.reload();
    });

    document.getElementById('btnResetFilter').addEventListener('click', () => {
        document.getElementById('filterSearch').value   = '';
        document.getElementById('filterStatus').value   = 'active';
        document.getElementById('filterSource').value   = '';
        document.getElementById('filterCategory').value = '';
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value   = '';
        table.ajax.reload();
    });

    // ── Row selection (with shift+click range) ────────────────────────────────

    $('#certTable').on('click', '.row-chk', function (e) {
        const allChk = [...document.querySelectorAll('.row-chk')];
        const thisIdx = allChk.indexOf(this);

        if (e.shiftKey && lastCheckedChk && lastCheckedChk !== this) {
            const lastIdx = allChk.indexOf(lastCheckedChk);
            const [from, to] = lastIdx < thisIdx ? [lastIdx, thisIdx] : [thisIdx, lastIdx];
            allChk.slice(from, to + 1).forEach(chk => {
                chk.checked = this.checked;
                const cid = parseInt(chk.dataset.id, 10);
                if (this.checked) selectedIds.add(cid); else selectedIds.delete(cid);
            });
        } else {
            const id = parseInt(this.dataset.id, 10);
            if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
        }

        lastCheckedChk = this;
        updateBulkToolbar();
    });

    document.getElementById('chkAll').addEventListener('change', function () {
        lastCheckedChk = null;
        document.querySelectorAll('.row-chk').forEach(chk => {
            chk.checked = this.checked;
            const id = parseInt(chk.dataset.id, 10);
            if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
        });
        updateBulkToolbar();
    });

    document.getElementById('btnClearSelection').addEventListener('click', () => {
        selectedIds.clear();
        lastCheckedChk = null;
        document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
        document.getElementById('chkAll').checked = false;
        updateBulkToolbar();
    });

    function updateBulkToolbar() {
        const n            = selectedIds.size;
        const badge        = document.getElementById('bulkCountBadge');
        const btnZip       = document.getElementById('btnBulkZip');
        const btnEmail     = document.getElementById('btnBulkEmail');
        const btnReissue   = document.getElementById('btnBulkReissue');
        const btnEditVenue = document.getElementById('btnBulkEditVenue');
        const btnVoid      = document.getElementById('btnBulkVoid');
        const btnClr       = document.getElementById('btnClearSelection');
        const show         = n > 0;

        badge.classList.toggle('d-none', !show);
        btnZip.classList.toggle('d-none', !show);
        btnEmail.classList.toggle('d-none', !show);
        btnReissue.classList.toggle('d-none', !show);
        btnEditVenue.classList.toggle('d-none', !show);
        btnVoid.classList.toggle('d-none', !show);
        btnClr.classList.toggle('d-none', !show);

        if (show) {
            badge.textContent = n + ' selected';
            btnZip.href = 'api/ccs_batch_zip.php?ids=' + [...selectedIds].join(',');
        }
    }

    // ── Void (single row) ────────────────────────────────────────────────────

    $('#certTable').on('click', '.btn-dt-void', function () {
        const certId = this.dataset.id;
        const name   = this.dataset.name;
        Swal.fire({
            title: 'Void Certificate?',
            html: `Void the certificate for <strong>${escHtml(name)}</strong>?<br>
                   <small class="text-danger">This cannot be undone without manual DB access.</small>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Void',
            confirmButtonColor: '#dc3545',
        }).then(res => {
            if (!res.isConfirmed) return;
            const fd = new FormData();
            fd.append('_csrf', csrf); fd.append('cert_id', certId);
            fetch('api/ccs_void_ajax.php', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(d => {
                    if (!d.success) { Swal.fire('Error', d.error || 'Failed.', 'error'); return; }
                    Swal.fire({ title: 'Voided', text: d.message, icon: 'success', timer: 1500, showConfirmButton: false });
                    table.ajax.reload(null, false);
                    loadStats();
                });
        });
    });

    // ── Bulk void ────────────────────────────────────────────────────────────

    document.getElementById('btnBulkVoid').addEventListener('click', () => {
        if (!selectedIds.size) return;
        Swal.fire({
            title: `Void ${selectedIds.size} certificates?`,
            text: 'This action cannot be easily undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Void All',
        }).then(async res => {
            if (!res.isConfirmed) return;
            const ids = [...selectedIds];
            const total = ids.length;
            let done = 0, failed = 0;

            Swal.fire({
                title: 'Voiding certificates…',
                html: bulkProgressHtml(0, total, 0),
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading(),
            });

            for (const id of ids) {
                const fd = new FormData();
                fd.append('_csrf', csrf); fd.append('cert_id', id);
                try {
                    const r = await fetch('api/ccs_void_ajax.php', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } });
                    const d = await r.json();
                    if (d.success) done++; else failed++;
                } catch { failed++; }
                Swal.update({ html: bulkProgressHtml(done + failed, total, failed) });
            }

            const msg = `Voided ${done} of ${total} certificate${total > 1 ? 's' : ''}.`
                + (failed ? ` ${failed} failed.` : '');
            Swal.fire('Done', msg, done === total ? 'success' : 'warning');
            selectedIds.clear();
            lastCheckedChk = null;
            updateBulkToolbar();
            table.ajax.reload(null, false);
            loadStats();
        });
    });

    // ── Bulk re-issue ─────────────────────────────────────────────────────────

    document.getElementById('btnBulkReissue').addEventListener('click', () => {
        if (!selectedIds.size) return;
        const total = selectedIds.size;
        Swal.fire({
            title: `Re-issue ${total} certificate${total > 1 ? 's' : ''}?`,
            html: 'New PDFs will be generated for each selected certificate.<br>'
                + '<small class="text-muted">Same certificate numbers — old PDFs removed.</small>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#fd7e14',
            confirmButtonText: 'Re-issue All',
        }).then(async res => {
            if (!res.isConfirmed) return;
            const ids = [...selectedIds];
            let done = 0, failed = 0;

            Swal.fire({
                title: 'Re-issuing certificates…',
                html: bulkProgressHtml(0, total, 0),
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading(),
            });

            for (const id of ids) {
                const fd = new FormData();
                fd.append('_csrf', csrf); fd.append('cert_id', id);
                try {
                    const r = await fetch('api/ccs_reissue_ajax.php', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } });
                    const d = await r.json();
                    if (d.success) done++; else failed++;
                } catch { failed++; }
                Swal.update({ html: bulkProgressHtml(done + failed, total, failed) });
            }

            const msg = `Re-issued ${done} of ${total} certificate${total > 1 ? 's' : ''}.`
                + (failed ? ` ${failed} failed.` : '');
            Swal.fire('Done', msg, done === total ? 'success' : 'warning');
            selectedIds.clear();
            lastCheckedChk = null;
            updateBulkToolbar();
            table.ajax.reload(null, false);
        });
    });

    // ── Re-issue (single row) ─────────────────────────────────────────────────

    $('#certTable').on('click', '.btn-dt-reissue', function () {
        const certId = this.dataset.id;
        const name   = this.dataset.name;
        Swal.fire({
            title: 'Re-issue Certificate?',
            html: `Generate a new PDF for <strong>${escHtml(name)}</strong>?<br>
                   <small class="text-muted">Same certificate number — old PDF removed.</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Re-issue',
            confirmButtonColor: '#fd7e14',
        }).then(res => {
            if (!res.isConfirmed) return;
            const fd = new FormData();
            fd.append('_csrf', csrf); fd.append('cert_id', certId);
            fetch('api/ccs_reissue_ajax.php', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(d => {
                    if (!d.success) { Swal.fire('Error', d.error || 'Re-issue failed.', 'error'); return; }
                    Swal.fire({
                        title: 'Re-issued!',
                        html: `New PDF ready for <strong>${escHtml(name)}</strong>.`,
                        icon: 'success',
                        showCancelButton: true,
                        confirmButtonText: '<i class="bi bi-eye me-1"></i>View PDF',
                        cancelButtonText: 'Close',
                    }).then(r2 => {
                        if (r2.isConfirmed) window.open('ccs_cert_view?id=' + d.cert_id, '_blank');
                    });
                    table.ajax.reload(null, false);
                });
        });
    });

    // ── Edit (single row) ────────────────────────────────────────────────────

    const editModal = new bootstrap.Modal(document.getElementById('editCertModal'));
    let editRowSource = '';
    let bulkMode = false;

    function enterBulkEditMode() {
        bulkMode = true;
        document.getElementById('editCertId').value = '';
        document.getElementById('editPname').value  = '';
        document.getElementById('editTtitle').value = '';
        document.getElementById('editVenue').value  = '';
        document.getElementById('editSd').value     = '';
        document.getElementById('editEd').value     = '';

        document.getElementById('editPnameWrap').style.display  = 'none';
        document.getElementById('editTtitleWrap').style.display = 'none';
        document.getElementById('editSdWrap').style.display     = 'none';
        document.getElementById('editEdWrap').style.display     = 'none';
        document.getElementById('editNonAppearance').style.display = 'none';

        document.getElementById('editCertModalLabel').textContent =
            `Edit Venue — ${selectedIds.size} certificates selected`;
        document.getElementById('editModalFooterNote').innerHTML =
            '<i class="bi bi-info-circle me-1"></i>Updates the database record only for all selected certificates — existing PDFs keep their original venue text until re-issued.';

        const btnSubmit = document.getElementById('btnEditSubmit');
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<i class="bi bi-check2 me-1"></i>Save Changes';
        editModal.show();
    }

    function exitBulkEditMode() {
        bulkMode = false;
        document.getElementById('editPnameWrap').style.display  = '';
        document.getElementById('editTtitleWrap').style.display = '';
        document.getElementById('editSdWrap').style.display     = '';
        document.getElementById('editEdWrap').style.display     = '';
        document.getElementById('editCertModalLabel').textContent = 'Edit Certificate';
        document.getElementById('editModalFooterNote').innerHTML =
            '<i class="bi bi-info-circle me-1"></i>This updates the record only. Re-issue to regenerate the PDF.';
        // editNonAppearance visibility is re-decided by the single-row open
        // handler on its next open (based on editRowSource), so it's left
        // alone here rather than force-shown.
    }

    document.getElementById('editCertModal').addEventListener('hidden.bs.modal', exitBulkEditMode);

    document.getElementById('btnBulkEditVenue').addEventListener('click', () => {
        if (!selectedIds.size) return;
        enterBulkEditMode();
    });

    $('#certTable').on('click', '.btn-dt-edit', function () {
        let row;
        try { row = JSON.parse(this.dataset.row || 'null'); } catch (e) { row = null; }
        if (!row) return;

        bulkMode = false;
        editRowSource = row.source || '';

        document.getElementById('editCertId').value = row.id;
        document.getElementById('editPname').value  = row.participant_name;
        document.getElementById('editTtitle').value = row.training_title;
        document.getElementById('editVenue').value  = row.venue       || '';
        document.getElementById('editSd').value     = row.start_date  || '';
        document.getElementById('editEd').value     = row.end_date    || '';

        const nonApp = document.getElementById('editNonAppearance');
        if (editRowSource === 'appearance') {
            nonApp.style.display = 'none';
        } else {
            nonApp.style.display = '';
            const cat = row.category || '';
            document.getElementById('editCategory').value = cat || 'completion';
            document.getElementById('editTmode').value    = row.training_mode || '';
            document.getElementById('editNhrs').value     = row.no_hrs        || 0;
            document.getElementById('editTtype').value    = row.training_type || '';
            document.getElementById('editTlevel').value   = row.training_level || '';
            document.getElementById('editPosition').value = row.position      || '';
            document.getElementById('editAgency').value   = row.agency        || '';
            toggleEditCertificationFields();
        }

        const btnSubmit = document.getElementById('btnEditSubmit');
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<i class="bi bi-check2 me-1"></i>Save Changes';
        editModal.show();
    });

    function toggleEditCertificationFields() {
        const cat = document.getElementById('editCategory').value;
        const isCert = cat === 'certification' || cat === 'merged_certification';
        document.getElementById('editCertificationFields').style.display = isCert ? '' : 'none';
    }
    document.getElementById('editCategory').addEventListener('change', toggleEditCertificationFields);

    function submitBulkVenue(venue) {
        const btn = document.getElementById('btnEditSubmit');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

        const fd = new FormData();
        fd.append('_csrf', csrf);
        [...selectedIds].forEach(id => fd.append('cert_ids[]', id));
        fd.append('venue', venue);

        fetch('api/ccs_bulk_edit_venue_ajax.php', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save Changes';
                if (!d.success) { Swal.fire('Error', d.error || 'Update failed.', 'error'); return; }
                editModal.hide();
                Swal.fire({ title: 'Updated!', text: d.message, icon: 'success', timer: 1500, showConfirmButton: false });
                selectedIds.clear();
                lastCheckedChk = null;
                updateBulkToolbar();
                table.ajax.reload(null, false);
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save Changes';
                Swal.fire('Error', 'Request failed.', 'error');
            });
    }

    document.getElementById('btnEditSubmit').addEventListener('click', function () {
        if (bulkMode) {
            const venue = document.getElementById('editVenue').value.trim();
            if (!venue) {
                Swal.fire({
                    title: `Clear venue on ${selectedIds.size} certificate(s)?`,
                    text: 'The venue field will be emptied on all selected certificates. This cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Clear Venue',
                }).then(res => {
                    if (res.isConfirmed) submitBulkVenue(venue);
                });
            } else {
                submitBulkVenue(venue);
            }
            return;
        }

        const certId = document.getElementById('editCertId').value;
        const pname  = document.getElementById('editPname').value.trim();
        const ttitle = document.getElementById('editTtitle').value.trim();
        if (!pname || !ttitle) {
            Swal.fire('Validation', 'Participant name and training title are required.', 'warning');
            return;
        }

        const fd = new FormData();
        fd.append('_csrf',            csrf);
        fd.append('cert_id',          certId);
        fd.append('participant_name', pname);
        fd.append('training_title',   ttitle);
        fd.append('venue',            document.getElementById('editVenue').value.trim());
        fd.append('start_date',       document.getElementById('editSd').value);
        fd.append('end_date',         document.getElementById('editEd').value);

        if (editRowSource !== 'appearance') {
            fd.append('category',       document.getElementById('editCategory').value);
            fd.append('training_mode',  document.getElementById('editTmode').value);
            fd.append('no_hrs',         document.getElementById('editNhrs').value);
            fd.append('training_type',  document.getElementById('editTtype').value);
            fd.append('training_level', document.getElementById('editTlevel').value.trim());
            fd.append('position',       document.getElementById('editPosition').value.trim());
            fd.append('agency',         document.getElementById('editAgency').value.trim());
        }

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

        fetch('api/ccs_edit_ajax.php', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save Changes';
                if (!d.success) { Swal.fire('Error', d.error || 'Update failed.', 'error'); return; }
                editModal.hide();
                Swal.fire({ title: 'Updated!', text: d.message, icon: 'success', timer: 1500, showConfirmButton: false });
                table.ajax.reload(null, false);
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save Changes';
                Swal.fire('Error', 'Request failed.', 'error');
            });
    });

    // ── Stat card drill-down ──────────────────────────────────────────────────

    document.querySelectorAll('.ccs-stat-card[data-stat-src], .ccs-stat-card[data-stat-status], .ccs-stat-card[data-stat-cat]')
        .forEach(card => {
            card.addEventListener('click', function () {
                const src    = this.dataset.statSrc    ?? '';
                const cat    = this.dataset.statCat    ?? '';
                const status = this.dataset.statStatus ?? 'active';
                document.getElementById('filterSource').value   = src;
                document.getElementById('filterCategory').value = cat;
                document.getElementById('filterStatus').value   = status;
                document.getElementById('filterSearch').value   = '';
                document.getElementById('filterDateFrom').value = '';
                document.getElementById('filterDateTo').value   = '';
                table.ajax.reload();
            });
        });

    // ── Export CSV ────────────────────────────────────────────────────────────

    document.getElementById('btnExportCsv').addEventListener('click', function (e) {
        e.preventDefault();
        const p = buildParams();
        const qs = new URLSearchParams();
        if (p.q)         qs.set('q',         p.q);
        if (p.status)    qs.set('status',    p.status);
        if (p.date_from) qs.set('date_from', p.date_from);
        if (p.date_to)   qs.set('date_to',   p.date_to);
        if (p.source)    qs.set('source',    p.source);
        if (p.category)  qs.set('category',  p.category);
        window.location.href = 'api/ccs_export_ajax.php?' + qs.toString();
    });

    // ── Copy cert number ──────────────────────────────────────────────────────

    $('#certTable').on('click', '.btn-copy-cert', function () {
        const certNo = this.dataset.cert;
        navigator.clipboard.writeText(certNo).then(() => {
            this.classList.add('copied');
            this.innerHTML = '<i class="bi bi-clipboard-check" style="font-size:.7rem;"></i>';
            setTimeout(() => {
                this.classList.remove('copied');
                this.innerHTML = '<i class="bi bi-clipboard" style="font-size:.7rem;"></i>';
            }, 1800);
        });
    });

    // ── PDF preview ───────────────────────────────────────────────────────────

    const pdfPreviewModal = new bootstrap.Modal(document.getElementById('pdfPreviewModal'));

    $('#certTable').on('click', '.btn-dt-preview', function () {
        const id   = this.dataset.id;
        const name = this.dataset.name;
        const url  = 'ccs_cert_view?id=' + id;
        document.getElementById('pdfPreviewTitle').textContent = name;
        document.getElementById('pdfPreviewFrame').src         = url;
        document.getElementById('pdfPreviewOpenNew').href      = url;
        pdfPreviewModal.show();
    });

    document.getElementById('pdfPreviewModal').addEventListener('hide.bs.modal', function () {
        document.getElementById('pdfPreviewFrame').src = '';
    });

    // ── Template preview (sample data, no DB writes) ─────────────────────────

    const templatePreviewModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('templatePreviewModal'));

    function toggleTplFields() {
        const category        = document.getElementById('tplCategory').value;
        const isAppearance    = category === 'appearance';
        const isCertLike      = category === 'certification' || category === 'merged_certification';
        document.getElementById('tplSourceWrap').classList.toggle('d-none', isAppearance);
        document.getElementById('tplModeWrap').classList.toggle('d-none', isAppearance);
        document.getElementById('tplHrsWrap').classList.toggle('d-none', isAppearance);
        document.getElementById('tplTypeWrap').classList.toggle('d-none', isAppearance);
        document.getElementById('tplRefWrap').style.display      = isAppearance ? '' : 'none';
        document.getElementById('tplPositionWrap').style.display = isCertLike ? '' : 'none';
        document.getElementById('tplAgencyWrap').style.display   = isCertLike ? '' : 'none';
    }
    document.getElementById('tplCategory').addEventListener('change', toggleTplFields);
    toggleTplFields();

    document.getElementById('btnGenerateTemplatePreview').addEventListener('click', function () {
        const qs = new URLSearchParams({
            category:       document.getElementById('tplCategory').value,
            source:         document.getElementById('tplSource').value,
            participant_name: document.getElementById('tplPname').value,
            training_title:   document.getElementById('tplTtitle').value,
            venue:            document.getElementById('tplVenue').value,
            training_mode:    document.getElementById('tplMode').value,
            no_hrs:           document.getElementById('tplHrs').value,
            training_type:    document.getElementById('tplType').value,
            reference_no:     document.getElementById('tplRef').value,
            position_title:   document.getElementById('tplPosition').value,
            agency_name:      document.getElementById('tplAgency').value,
            start_date:       document.getElementById('tplStart').value,
            end_date:         document.getElementById('tplEnd').value,
            _:                Date.now(), // cache-bust
        });
        const url = 'api/ccs_preview_ajax.php?' + qs.toString();

        templatePreviewModal.hide();
        document.getElementById('pdfPreviewTitle').textContent = 'Template Preview — Sample Data';
        document.getElementById('pdfPreviewFrame').src         = url;
        document.getElementById('pdfPreviewOpenNew').href      = url;
        pdfPreviewModal.show();
    });

    // ── Bulk email ────────────────────────────────────────────────────────────

    document.getElementById('btnBulkEmail').addEventListener('click', () => {
        if (!selectedIds.size) return;
        const total = selectedIds.size;
        Swal.fire({
            title: `Email ${total} certificate${total > 1 ? 's' : ''}?`,
            html: 'PDFs will be sent to each participant\'s registered email.<br>'
                + '<small class="text-muted">Participants without an email on file will be skipped.</small>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0dcaf0',
            confirmButtonText: 'Send All',
        }).then(async res => {
            if (!res.isConfirmed) return;
            const ids = [...selectedIds];
            let done = 0, failed = 0;

            Swal.fire({
                title: 'Sending emails…',
                html: bulkProgressHtml(0, total, 0),
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading(),
            });

            for (const id of ids) {
                const fd = new FormData();
                fd.append('_csrf', csrf); fd.append('cert_id', id);
                try {
                    const r = await fetch('api/ccs_email_ajax.php', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } });
                    const d = await r.json();
                    if (d.success) done++; else failed++;
                } catch { failed++; }
                Swal.update({ html: bulkProgressHtml(done + failed, total, failed) });
            }

            const msg = `Sent ${done} of ${total} email${total > 1 ? 's' : ''}.`
                + (failed ? ` ${failed} could not be sent (no email or file missing).` : '');
            Swal.fire('Done', msg, done === total ? 'success' : 'warning');
            selectedIds.clear();
            lastCheckedChk = null;
            updateBulkToolbar();
            table.ajax.reload(null, false);
        });
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    function bulkProgressHtml(current, total, failed) {
        const pct = total > 0 ? Math.round((current / total) * 100) : 0;
        const barColor = failed > 0 ? '#dc3545' : '#fd7e14';
        return `<div class="mb-2 text-muted" style="font-size:.9rem;">
                    Processing <strong>${current}</strong> of <strong>${total}</strong>
                    ${failed > 0 ? `<span class="text-danger ms-2">(${failed} failed)</span>` : ''}
                </div>
                <div class="progress" style="height:10px;border-radius:6px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated"
                         role="progressbar"
                         style="width:${pct}%;background-color:${barColor};transition:width .3s ease;">
                    </div>
                </div>
                <div class="mt-1 text-muted" style="font-size:.8rem;">${pct}% complete</div>`;
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function escAttr(s) { return escHtml(s); }

    // ── Init ──────────────────────────────────────────────────────────────────
    loadStats();

}(jQuery));
</script>
</body>
</html>
