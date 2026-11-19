<?php
// imis/otrs/hrd/appearance_certificates.php
// Bulk Certificate of Appearance generation — 3-step wizard.

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
    <title>Certificate of Appearance — OTRS HRD</title>
    <link href="../assets/img/favicon.png" rel="icon">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
    <style>
        .csv-drop-zone {
            border: 2px dashed #c6d9ff; border-radius: 10px; background: #f6f9ff;
            padding: 2rem 1.5rem; text-align: center; cursor: pointer;
            transition: border-color .2s, background .2s; position: relative;
        }
        .csv-drop-zone.dragover { border-color: #0077b6; background: #e6f2ff; }
        .csv-drop-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
        .csv-drop-zone .drop-icon { font-size: 2.5rem; color: #0077b6; margin-bottom: .5rem; }
        .csv-drop-zone .drop-text { font-size: .9rem; color: #5a7094; }
        .csv-drop-zone .drop-hint { font-size: .75rem; color: #adb5bd; margin-top: .3rem; }
        .csv-file-chosen { font-size: .82rem; font-weight: 600; color: #198754; margin-top: .5rem; display: none; }

        .step-card { border: 1px solid #ebeef4; border-radius: 10px; background: #fff; margin-bottom: 1.25rem; overflow: hidden; }
        .step-header {
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            color: #fff; padding: .75rem 1.25rem; display: flex; align-items: center; gap: .6rem;
        }
        .step-num {
            width: 26px; height: 26px; border-radius: 50%;
            background: rgba(255,255,255,.2); display: flex; align-items: center;
            justify-content: center; font-size: .8rem; font-weight: 700; flex-shrink: 0;
        }
        .step-title { font-size: .92rem; font-weight: 700; }
        .step-body { padding: 1.25rem; }

        #participantPreview { display: none; }
        .preview-count {
            display: inline-flex; align-items: center; gap: .3rem; background: #edfbf3;
            color: #155724; font-size: .78rem; font-weight: 700;
            padding: .2rem .65rem; border-radius: 20px; margin-bottom: .75rem;
        }

        #resultsSection { display: none; }
        .result-strip {
            background: linear-gradient(135deg, #198754, #0d6b42);
            border-radius: 10px; padding: .9rem 1.25rem; color: #fff; margin-bottom: 1rem;
        }
        .result-strip-title { font-size: .92rem; font-weight: 700; margin-bottom: .3rem; }
        .result-strip-meta  { font-size: .78rem; opacity: .85; }

        .cert-result-card {
            background: #fff; border: 1px solid #ebeef4; border-radius: 8px;
            padding: .75rem 1rem; display: flex; align-items: center;
            gap: .85rem; margin-bottom: .45rem;
        }
        .cert-result-card:hover { border-color: #0077b6; }
        .cert-result-icon {
            width: 40px; height: 40px; border-radius: 8px;
            background: #eef4ff; color: #0077b6;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; flex-shrink: 0;
        }
        .cert-result-icon.error { background: #fdecea; color: #dc3545; }
        .cert-result-name { font-size: .88rem; font-weight: 700; color: #012970; }
        .cert-result-no   { font-family: monospace; font-size: .75rem; color: #0077b6; }
        .cert-result-err  { font-size: .78rem; color: #dc3545; }
        .cert-result-actions { margin-left: auto; flex-shrink: 0; }

        .action-toolbar {
            display: flex; flex-wrap: wrap; gap: .5rem;
            padding: .75rem 1rem; background: #f6f9ff;
            border: 1px solid #ebeef4; border-radius: 8px; margin-bottom: 1rem;
        }
        .btn-action {
            border: none; color: #fff; font-size: .8rem; font-weight: 600;
            padding: .38rem .9rem; border-radius: 5px;
            display: inline-flex; align-items: center; gap: .3rem;
            cursor: pointer; transition: filter .13s, transform .12s; text-decoration: none;
        }
        .btn-action:hover  { filter: brightness(.87); transform: translateY(-1px); color: #fff; }
        .btn-action:active { filter: brightness(.78); transform: translateY(0); color: #fff; }
        .ba-green  { background: #198754; }
        .ba-blue   { background: #0077b6; }
        .ba-orange { background: #fd7e14; }
        .ba-teal   { background: #0d9488; }

        .section-label {
            font-size: .68rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: 1.5px; color: #899bbd;
            border-bottom: 1px solid #ebeef4; padding-bottom: .4rem; margin-bottom: .85rem;
        }
        #csvErrors { display: none; }
    </style>
</head>
<body>
<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

    <div class="pagetitle otrs-ph">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="otrs-ph-icon icon-teal"><i class="bi bi-person-badge-fill"></i></div>
                <div>
                    <h1>Certificate of Appearance</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Certificate of Appearance</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <div>
                <a href="ccs_dashboard" class="otrs-btn-back">
                    <i class="bi bi-grid-fill"></i> CCS Dashboard
                </a>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-8">

                <!-- Step 1: CSV Upload -->
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-num">1</div>
                        <div class="step-title">Upload Participant List (CSV)</div>
                    </div>
                    <div class="step-body">
                        <div class="csv-drop-zone" id="csvDropZone">
                            <input type="file" id="csvFileInput" accept=".csv">
                            <div class="drop-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                            <div class="drop-text">Drag &amp; drop your CSV here, or click to browse</div>
                            <div class="drop-hint">One participant name per row — no header needed</div>
                        </div>
                        <div class="csv-file-chosen" id="csvFileChosen">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <span id="csvFileName"></span>
                        </div>

                        <div id="csvErrors" class="alert alert-danger mt-2" role="alert"></div>

                        <!-- Participant preview -->
                        <div id="participantPreview" class="mt-3">
                            <div class="preview-count">
                                <i class="bi bi-people-fill"></i>
                                <span id="participantCount">0</span> participant(s) loaded
                            </div>
                            <div id="participantList" class="list-group list-group-flush"
                                 style="max-height: 220px; overflow-y: auto; border: 1px solid #ebeef4; border-radius: 6px;"></div>
                        </div>

                        <div class="mt-3 d-flex align-items-center gap-2">
                            <a href="api/agency_parse_csv.php?sample=1&type=appearance" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-download me-1"></i>Download Sample CSV
                            </a>
                            <span class="text-muted" style="font-size:.76rem;">Encoding: UTF-8</span>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Activity Details -->
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-num">2</div>
                        <div class="step-title">Activity Details</div>
                    </div>
                    <div class="step-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="activityTitle">
                                    Activity Title <span class="text-danger">*</span>
                                </label>
                                <input type="text" id="activityTitle" class="form-control"
                                       placeholder="e.g. Regional Consultative Workshop on …">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="activityType">
                                    Activity Type <span class="text-danger">*</span>
                                </label>
                                <select id="activityType" class="form-select">
                                    <option value="">— Select —</option>
                                    <option value="seminar">Seminar</option>
                                    <option value="workshop">Workshop</option>
                                    <option value="meeting">Meeting</option>
                                    <option value="orientation">Orientation</option>
                                    <option value="consultation">Consultation</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="refNo">
                                    Reference No. <span class="text-muted fw-normal">(optional)</span>
                                </label>
                                <input type="text" id="refNo" class="form-control"
                                       placeholder="e.g. CSCROVI-2026-001">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="venue">
                                    Venue <span class="text-danger">*</span>
                                </label>
                                <input type="text" id="venue" class="form-control"
                                       placeholder="e.g. CSC RO VIII Training Room, Tacloban City">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="startDate">Start Date <span class="text-danger">*</span></label>
                                <input type="date" id="startDate" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="endDate">End Date <span class="text-danger">*</span></label>
                                <input type="date" id="endDate" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Review & Generate -->
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-num">3</div>
                        <div class="step-title">Review &amp; Generate</div>
                    </div>
                    <div class="step-body">
                        <div id="reviewSummary" class="alert alert-light border mb-3" style="font-size:.87rem; display:none;">
                        </div>
                        <button id="btnGenerate" class="btn btn-primary px-4">
                            <i class="bi bi-award-fill me-1"></i>Generate Certificates of Appearance
                        </button>
                        <div id="generateSpinner" class="d-none ms-2 d-inline-flex align-items-center gap-2 text-muted" style="font-size:.83rem;">
                            <span class="spinner-border spinner-border-sm"></span>Generating PDFs…
                        </div>
                    </div>
                </div>

                <!-- ── Results ── -->
                <div id="resultsSection">
                    <div class="result-strip" id="resultStrip">
                        <div class="result-strip-title" id="resultTitle"></div>
                        <div class="result-strip-meta"  id="resultMeta"></div>
                    </div>

                    <div class="action-toolbar">
                        <a href="#" id="btnDownloadZip" class="btn-action ba-green" target="_blank">
                            <i class="bi bi-file-earmark-zip-fill"></i>Download All (ZIP)
                        </a>
                        <button id="btnNewBatch" class="btn-action ba-blue">
                            <i class="bi bi-plus-circle-fill"></i>New Batch
                        </button>
                        <a href="ccs_dashboard" class="btn-action ba-teal">
                            <i class="bi bi-grid-fill"></i>CCS Dashboard
                        </a>
                    </div>

                    <div id="certResultList"></div>
                </div>

            </div><!-- /col-lg-8 -->

            <!-- Info sidebar -->
            <div class="col-lg-4">
                <div class="step-card">
                    <div class="step-header" style="background: linear-gradient(135deg,#0d9488,#0a6b62);">
                        <i class="bi bi-info-circle-fill"></i>
                        <div class="step-title ms-1">How This Works</div>
                    </div>
                    <div class="step-body" style="font-size:.82rem;color:#4a4a6a;line-height:1.6;">
                        <p><strong>1. Upload a CSV</strong> listing the names of everyone who appeared at the activity — one name per row, no header needed.</p>
                        <p><strong>2. Fill in the activity details</strong> — title, type, venue, optional reference number, and start/end dates.</p>
                        <p><strong>3. Review the summary</strong> and click <strong>Generate Certificates of Appearance</strong> to produce PDFs in bulk.</p>
                        <p><strong>4. Download</strong> individual PDFs or the full ZIP archive.</p>
                        <hr>
                        <p class="mb-1"><strong>Accepted File Format</strong></p>
                        <ul class="mb-2 ps-3">
                            <li>Comma-delimited <code>.csv</code></li>
                            <li>One participant name per row — <em>no header row required</em></li>
                        </ul>
                        <p class="mb-0">Need a starting point? Use the <strong>Download Sample CSV</strong> link under Step 1.</p>
                        <hr>
                        <p class="mb-1"><strong>Note</strong></p>
                        <p class="mb-0">Certificate reference numbering and signatory follow the existing OTRS CCS standards. Certificates of Appearance do not carry a QR/verification code.</p>
                    </div>
                </div>
            </div>

        </div>
    </section>

</main>

<?php imis_include('footer') ?>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    'use strict';

    const csrf = <?= json_encode($_SESSION['_csrf_token'] ?? '') ?>;
    let parsedNames = [];
    let lastBatchId = 0;

    // ── CSV parsing ──────────────────────────────────────────────────────────

    const dropZone  = document.getElementById('csvDropZone');
    const fileInput = document.getElementById('csvFileInput');

    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
    });
    fileInput.addEventListener('change', () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });

    function handleFile(file) {
        if (!file.name.toLowerCase().endsWith('.csv')) {
            showCsvError('Please upload a .csv file.'); return;
        }
        const fd = new FormData();
        fd.append('csv_file', file);
        fd.append('_csrf', csrf);
        fetch('api/agency_parse_csv.php', { method: 'POST', body: fd,
            headers: { 'X-CSRF-TOKEN': csrf } })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { showCsvError(data.error || 'CSV parsing failed.'); return; }
                parsedNames = (data.participants || []).map(p => p.name);
                document.getElementById('csvFileChosen').style.display = 'block';
                document.getElementById('csvFileName').textContent = file.name + ' — ' + parsedNames.length + ' names';
                document.getElementById('csvErrors').style.display = 'none';
                renderPreview();
            })
            .catch(() => showCsvError('Network error while parsing CSV.'));
    }

    function showCsvError(msg) {
        const el = document.getElementById('csvErrors');
        el.textContent = msg; el.style.display = 'block';
    }

    function renderPreview() {
        const wrap = document.getElementById('participantPreview');
        const list = document.getElementById('participantList');
        document.getElementById('participantCount').textContent = parsedNames.length;
        list.innerHTML = parsedNames.map(n =>
            `<div class="list-group-item list-group-item-action py-1 px-2" style="font-size:.83rem;">
                <i class="bi bi-person-fill me-1 text-primary"></i>${escHtml(n)}
             </div>`
        ).join('');
        wrap.style.display = 'block';
    }

    // ── Review summary ───────────────────────────────────────────────────────

    function updateReviewSummary() {
        const title   = document.getElementById('activityTitle').value.trim();
        const type    = document.getElementById('activityType').value;
        const venue   = document.getElementById('venue').value.trim();
        const start   = document.getElementById('startDate').value;
        const end     = document.getElementById('endDate').value;
        const refNo   = document.getElementById('refNo').value.trim();
        const summary = document.getElementById('reviewSummary');
        if (!title || !type || !start || !end || parsedNames.length === 0) {
            summary.style.display = 'none'; return;
        }
        const typeLabel = document.getElementById('activityType').options[document.getElementById('activityType').selectedIndex].text;
        summary.innerHTML = `
            <strong>${escHtml(title)}</strong> &mdash; ${escHtml(typeLabel)}<br>
            <span class="text-muted">${start} to ${end}</span>
            ${venue ? ' &bull; ' + escHtml(venue) : ''}
            ${refNo ? ' &bull; Ref: ' + escHtml(refNo) : ''}<br>
            <span class="badge bg-primary mt-1">${parsedNames.length} participant(s)</span>
        `;
        summary.style.display = 'block';
    }

    ['activityTitle','activityType','venue','startDate','endDate','refNo'].forEach(id => {
        document.getElementById(id).addEventListener('input', updateReviewSummary);
        document.getElementById(id).addEventListener('change', updateReviewSummary);
    });

    // ── Generate ─────────────────────────────────────────────────────────────

    document.getElementById('btnGenerate').addEventListener('click', function () {
        const title  = document.getElementById('activityTitle').value.trim();
        const type   = document.getElementById('activityType').value;
        const venue  = document.getElementById('venue').value.trim();
        const start  = document.getElementById('startDate').value;
        const end    = document.getElementById('endDate').value;
        const refNo  = document.getElementById('refNo').value.trim();

        if (!parsedNames.length) { Swal.fire('Missing', 'Please upload a participant list (CSV).', 'warning'); return; }
        if (!title)              { Swal.fire('Missing', 'Activity title is required.', 'warning'); return; }
        if (!type)               { Swal.fire('Missing', 'Please select an activity type.', 'warning'); return; }
        if (!venue)              { Swal.fire('Missing', 'Venue is required.', 'warning'); return; }
        if (!start || !end)      { Swal.fire('Missing', 'Start and end dates are required.', 'warning'); return; }
        if (end < start)         { Swal.fire('Invalid', 'End date cannot be before start date.', 'warning'); return; }

        const btn     = this;
        const spinner = document.getElementById('generateSpinner');
        btn.disabled  = true;
        spinner.classList.remove('d-none');

        const fd = new FormData();
        fd.append('_csrf',          csrf);
        fd.append('activity_title', title);
        fd.append('activity_type',  type);
        fd.append('venue',          venue);
        fd.append('start_date',     start);
        fd.append('end_date',       end);
        fd.append('reference_no',   refNo);
        parsedNames.forEach(n => fd.append('participant_names[]', n));

        fetch('api/appearance_cert_issue_ajax.php', { method: 'POST', body: fd,
            headers: { 'X-CSRF-TOKEN': csrf } })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                spinner.classList.add('d-none');
                if (!data.success && !data.batch_id) {
                    Swal.fire('Error', data.error || 'Generation failed.', 'error'); return;
                }
                lastBatchId = data.batch_id;
                renderResults(data);
            })
            .catch(err => {
                btn.disabled = false;
                spinner.classList.add('d-none');
                Swal.fire('Error', 'Network error: ' + err.message, 'error');
            });
    });

    function renderResults(data) {
        document.getElementById('resultTitle').textContent =
            `Generated ${data.count} Certificate(s) of Appearance`;
        document.getElementById('resultMeta').textContent =
            data.errors.length
                ? `${data.errors.length} error(s) — check individual entries below.`
                : 'All certificates generated successfully.';

        const zipBtn = document.getElementById('btnDownloadZip');
        zipBtn.href  = `api/appearance_cert_zip.php?batch_id=${data.batch_id}`;

        const list = document.getElementById('certResultList');
        list.innerHTML = data.results.map(r => {
            if (!r.success) {
                return `<div class="cert-result-card">
                    <div class="cert-result-icon error"><i class="bi bi-x-circle-fill"></i></div>
                    <div>
                        <div class="cert-result-name">${escHtml(r.name)}</div>
                        <div class="cert-result-err">${escHtml(r.error)}</div>
                    </div>
                </div>`;
            }
            return `<div class="cert-result-card">
                <div class="cert-result-icon"><i class="bi bi-file-earmark-check-fill"></i></div>
                <div class="flex-grow-1">
                    <div class="cert-result-name">${escHtml(r.name)}</div>
                    ${r.cert_number ? `<div class="cert-result-no">${escHtml(r.cert_number)}</div>` : ''}
                </div>
                <div class="cert-result-actions d-flex gap-1">
                    <a href="appearance_cert_view?id=${r.cert_id}" target="_blank"
                       class="btn btn-primary btn-sm">
                        <i class="bi bi-eye me-1"></i>View
                    </a>
                    <button class="btn btn-warning btn-sm btn-reissue-appearance"
                            data-cert-id="${r.cert_id}"
                            data-name="${escAttr(r.name)}"
                            title="Re-issue">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                </div>
            </div>`;
        }).join('');

        document.getElementById('resultsSection').style.display = 'block';
        document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });
    }

    // ── Re-issue ──────────────────────────────────────────────────────────────

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-reissue-appearance');
        if (!btn) return;
        const certId = btn.dataset.certId;
        const name   = btn.dataset.name;

        Swal.fire({
            title: 'Re-issue Certificate?',
            html: `Re-generate the Certificate of Appearance for <strong>${escHtml(name)}</strong>?<br>
                   <small class="text-muted">Same certificate number, new PDF.</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Re-issue',
            confirmButtonColor: '#fd7e14',
        }).then(res => {
            if (!res.isConfirmed) return;

            const fd = new FormData();
            fd.append('_csrf',    csrf);
            fd.append('cert_id',  certId);

            fetch('api/appearance_cert_reissue_ajax.php', { method: 'POST', body: fd,
                headers: { 'X-CSRF-TOKEN': csrf } })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { Swal.fire('Error', data.error || 'Re-issue failed.', 'error'); return; }
                    Swal.fire({
                        title: 'Re-issued!',
                        html: `New PDF generated for <strong>${escHtml(name)}</strong>.`,
                        icon: 'success',
                        showCancelButton: true,
                        confirmButtonText: '<i class="bi bi-eye me-1"></i>View PDF',
                        cancelButtonText: 'Close',
                    }).then(r2 => {
                        if (r2.isConfirmed) {
                            window.open('appearance_cert_view?id=' + data.cert_id, '_blank');
                        }
                    });
                })
                .catch(err => Swal.fire('Error', 'Network error: ' + err.message, 'error'));
        });
    });

    // ── New Batch ─────────────────────────────────────────────────────────────

    document.getElementById('btnNewBatch').addEventListener('click', function () {
        parsedNames = [];
        lastBatchId = 0;
        document.getElementById('csvFileInput').value       = '';
        document.getElementById('csvFileChosen').style.display = 'none';
        document.getElementById('csvErrors').style.display  = 'none';
        document.getElementById('participantPreview').style.display = 'none';
        document.getElementById('activityTitle').value      = '';
        document.getElementById('activityType').value       = '';
        document.getElementById('venue').value              = '';
        document.getElementById('startDate').value          = '';
        document.getElementById('endDate').value            = '';
        document.getElementById('refNo').value              = '';
        document.getElementById('reviewSummary').style.display = 'none';
        document.getElementById('resultsSection').style.display = 'none';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function escAttr(s) { return escHtml(s); }

})();
</script>
</body>
</html>
