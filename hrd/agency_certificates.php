<?php
// imis/otrs/hrd/agency_certificates.php
// Agency-Requested Trainings — Bulk Certificate Generation
// Allows HRD to upload a CSV of participant names and generate
// Certificates of Completion or Certifications without a training record.

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
    <title>Agency-Requested Certificates — OTRS HRD</title>
    <link href="../assets/img/favicon.png" rel="icon">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
    <style>
        /* ── Drop zone ───────────────────────────────────────────────────── */
        .csv-drop-zone {
            border: 2px dashed #c6d9ff;
            border-radius: 10px;
            background: #f6f9ff;
            padding: 2rem 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            position: relative;
        }
        .csv-drop-zone.dragover {
            border-color: #0077b6;
            background: #e6f2ff;
        }
        .csv-drop-zone input[type=file] {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }
        .csv-drop-zone .drop-icon { font-size: 2.5rem; color: #0077b6; margin-bottom: .5rem; }
        .csv-drop-zone .drop-text { font-size: .9rem; color: #5a7094; }
        .csv-drop-zone .drop-hint { font-size: .75rem; color: #adb5bd; margin-top: .3rem; }
        .csv-file-chosen { font-size: .82rem; font-weight: 600; color: #198754; margin-top: .5rem; display: none; }

        /* ── Step cards ──────────────────────────────────────────────────── */
        .step-card {
            border: 1px solid #ebeef4;
            border-radius: 10px;
            background: #fff;
            margin-bottom: 1.25rem;
            overflow: hidden;
        }
        .step-header {
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            color: #fff;
            padding: .75rem 1.25rem;
            display: flex;
            align-items: center;
            gap: .6rem;
        }
        .step-num {
            width: 26px; height: 26px;
            border-radius: 50%;
            background: rgba(255,255,255,.2);
            display: flex; align-items: center; justify-content: center;
            font-size: .8rem; font-weight: 700; flex-shrink: 0;
        }
        .step-title { font-size: .92rem; font-weight: 700; }
        .step-body { padding: 1.25rem; }

        /* ── Cert type selector ──────────────────────────────────────────── */
        .cert-type-grid { display: flex; gap: 1rem; flex-wrap: wrap; }
        .cert-type-option { flex: 1 1 200px; }
        .cert-type-option input[type=radio] { display: none; }
        .cert-type-label {
            display: block; border: 2px solid #e0e7ef; border-radius: 10px;
            padding: 1rem 1.25rem; cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .cert-type-label:hover { border-color: #0077b6; }
        .cert-type-option input:checked + .cert-type-label {
            border-color: #0077b6;
            background: #eef4ff;
        }
        .ct-icon { font-size: 1.6rem; margin-bottom: .4rem; }
        .ct-name { font-size: .92rem; font-weight: 700; color: #012970; margin-bottom: .2rem; }
        .ct-desc { font-size: .75rem; color: #5a7094; }

        /* ── Participant preview table ────────────────────────────────────── */
        #participantPreview { display: none; }
        .preview-count {
            display: inline-flex; align-items: center; gap: .3rem;
            background: #edfbf3; color: #155724;
            font-size: .78rem; font-weight: 700;
            padding: .2rem .65rem; border-radius: 20px;
            margin-bottom: .75rem;
        }

        /* ── Results section ─────────────────────────────────────────────── */
        #resultsSection { display: none; }
        .result-strip {
            background: linear-gradient(135deg, #198754, #0d6b42);
            border-radius: 10px; padding: .9rem 1.25rem; color: #fff;
            margin-bottom: 1rem;
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

        /* ── Toolbar ─────────────────────────────────────────────────────── */
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
        .ba-purple { background: #7b00cc; }
        .ba-red    { background: #dc3545; }

        .section-label { font-size: .68rem; font-weight: 600; text-transform: uppercase;
                         letter-spacing: 1.5px; color: #899bbd;
                         border-bottom: 1px solid #ebeef4; padding-bottom: .4rem; margin-bottom: .85rem; }

        /* ── CSV error list ──────────────────────────────────────────────── */
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
                <div class="otrs-ph-icon icon-purple"><i class="bi bi-building-fill-check"></i></div>
                <div>
                    <h1>Agency-Requested Certificates</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index">Home</a></li>
                            <li class="breadcrumb-item active">Agency-Requested Certificates</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <div>
                <a href="index_trainings" class="otrs-btn-back">
                    <i class="bi bi-arrow-left-circle"></i> Generate Certificates
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
                            <input type="file" id="csvFile" accept=".csv,.tsv,.txt,text/csv,text/plain,text/tab-separated-values" title="Choose a CSV file">
                            <div class="drop-icon"><i class="bi bi-file-earmark-spreadsheet"></i></div>
                            <div class="drop-text">Drag &amp; drop your CSV here, or click to browse</div>
                            <div class="drop-hint">Supports comma &amp; semicolon-delimited files &mdash; must have a <strong>Participant Name</strong> column</div>
                        </div>
                        <div class="csv-file-chosen" id="csvFileChosen">
                            <i class="bi bi-check-circle-fill me-1"></i><span id="csvFileName"></span>
                        </div>
                        <div id="csvErrors" class="mt-2"></div>
                    </div>
                </div>

                <!-- Step 2: Certificate Type -->
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-num">2</div>
                        <div class="step-title">Select Certificate Type</div>
                    </div>
                    <div class="step-body">
                        <div class="cert-type-grid">
                            <div class="cert-type-option">
                                <input type="checkbox" name="cert_types" id="ctCompletion" value="Completion" checked>
                                <label class="cert-type-label" for="ctCompletion">
                                    <div class="ct-icon text-success"><i class="bi bi-patch-check-fill"></i></div>
                                    <div class="ct-name">Certificate of Completion</div>
                                    <div class="ct-desc">For participants who successfully completed the training requirements.</div>
                                </label>
                            </div>
                            <div class="cert-type-option">
                                <input type="checkbox" name="cert_types" id="ctCertification" value="Certification">
                                <label class="cert-type-label" for="ctCertification">
                                    <div class="ct-icon text-primary"><i class="bi bi-award-fill"></i></div>
                                    <div class="ct-name">Certification</div>
                                    <div class="ct-desc">For participants who attended the training but do not qualify for a Certificate of Completion.</div>
                                </label>
                            </div>
                            <div class="cert-type-option" id="ctMergedWrap">
                                <input type="checkbox" name="cert_types" id="ctMerged" value="Merged" disabled>
                                <label class="cert-type-label" for="ctMerged">
                                    <div class="ct-icon text-warning"><i class="bi bi-file-earmark-richtext-fill"></i></div>
                                    <div class="ct-name">Merged — Completion + Appearance</div>
                                    <div class="ct-desc">A4 landscape, Completion and Appearance side by side. Face-to-Face trainings only.</div>
                                </label>
                            </div>
                            <div class="cert-type-option" id="ctMergedCertWrap">
                                <input type="checkbox" name="cert_types" id="ctMergedCert" value="MergedCertification" disabled>
                                <label class="cert-type-label" for="ctMergedCert">
                                    <div class="ct-icon text-info"><i class="bi bi-file-earmark-richtext-fill"></i></div>
                                    <div class="ct-name">Merged — Certification + Appearance</div>
                                    <div class="ct-desc">A4 landscape, Certification and Appearance side by side. Face-to-Face trainings only.</div>
                                </label>
                            </div>
                        </div>
                        <div class="form-text mt-2" id="ctMergedHint" style="display:none;">
                            <i class="bi bi-info-circle me-1"></i>Set the Training Modality below to <strong>Face-to-Face</strong> to enable the Merged formats.
                        </div>
                        <div class="form-check mt-3 pt-2 border-top">
                            <input type="checkbox" class="form-check-input" id="includeSignature" checked>
                            <label class="form-check-label fw-semibold" for="includeSignature" style="font-size:.88rem;">
                                Include Signatory's E-Signature
                            </label>
                            <div class="form-text" style="font-size:.75rem;">
                                Uncheck if these certificates will be signed by hand — no e-signature image will be placed on the PDF
                                (Director for Completion/Appearance, HRS Chief for Certification).
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Training Information -->
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-num">3</div>
                        <div class="step-title">Training Information</div>
                    </div>
                    <div class="step-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="trainingTitle">
                                    Training Title <span class="text-danger">*</span>
                                </label>
                                <input type="text" id="trainingTitle" class="form-control"
                                       placeholder="e.g. Basic Records Management Seminar"
                                       maxlength="255">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="trainingVenue">Venue</label>
                                <input type="text" id="trainingVenue" class="form-control"
                                       placeholder="e.g. CSC RO VIII Training Hall, Palo, Leyte"
                                       maxlength="255">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="startDate">
                                    Start Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" id="startDate" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="endDate">
                                    End Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" id="endDate" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="noHrs">No. of Training Hours</label>
                                <input type="number" id="noHrs" class="form-control" min="0" max="999"
                                       placeholder="e.g. 16">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold" for="trainingType">Training Type</label>
                                <select id="trainingType" class="form-select">
                                    <option value="">— (none) —</option>
                                    <option value="Technical">Technical</option>
                                    <option value="Foundation">Foundation</option>
                                    <option value="Leadership and Management">Leadership and Management</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="trainingMode">Training Modality</label>
                                <select id="trainingMode" class="form-select">
                                    <option value="face-to-face" selected>Face-to-Face</option>
                                    <option value="online">Online</option>
                                    <option value="hybrid">Hybrid</option>
                                    <option value="blended">Blended</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Participant Preview -->
                <div id="participantPreview" class="step-card">
                    <div class="step-header">
                        <div class="step-num">4</div>
                        <div class="step-title">Review Participants</div>
                    </div>
                    <div class="step-body">
                        <div class="preview-count" id="previewCount">
                            <i class="bi bi-people-fill"></i>
                            <span id="previewCountText">0 participants</span>
                        </div>
                        <div class="table-responsive" style="max-height:320px;overflow-y:auto;">
                            <table class="table table-sm table-bordered table-striped mb-0" style="font-size:.85rem;">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th class="text-center" style="width:50px;">#</th>
                                        <th>Participant Name</th>
                                        <th>Position</th>
                                        <th>Agency</th>
                                    </tr>
                                </thead>
                                <tbody id="previewTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Generate button -->
                <div class="d-flex justify-content-end mb-4">
                    <button id="btnGenerate" class="btn-action ba-purple" style="font-size:.92rem;padding:.6rem 1.5rem;">
                        <i class="bi bi-patch-check-fill me-1"></i> Generate Certificates
                    </button>
                </div>

                <!-- Results -->
                <div id="resultsSection">
                    <div class="result-strip" id="resultStrip">
                        <div class="result-strip-title" id="resultTitle"></div>
                        <div class="result-strip-meta"  id="resultMeta"></div>
                    </div>

                    <div class="action-toolbar">
                        <a href="#" id="btnDownloadZip" class="btn-action ba-blue" target="_blank">
                            <i class="bi bi-file-zip-fill me-1"></i> Download All (ZIP)
                        </a>
                        <button id="btnStartOver" class="btn-action ba-red">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Start New Batch
                        </button>
                    </div>

                    <p class="section-label"><i class="bi bi-list-check me-1"></i> Generated Certificates</p>
                    <div id="certResultsList"></div>
                </div>

            </div>

            <!-- Info sidebar -->
            <div class="col-lg-4">
                <div class="step-card">
                    <div class="step-header" style="background: linear-gradient(135deg,#7b00cc,#4a0080);">
                        <i class="bi bi-info-circle-fill"></i>
                        <div class="step-title ms-1">How This Works</div>
                    </div>
                    <div class="step-body" style="font-size:.82rem;color:#4a4a6a;line-height:1.6;">
                        <p><strong>1. Upload a CSV</strong> containing participant names. The minimum required column is <code>Participant Name</code> (header is auto-detected).</p>
                        <p><strong>2. Select the certificate type.</strong> Choose <em>Certificate of Completion</em> for participants who completed requirements, or <em>Certification</em> for attendees.</p>
                        <p><strong>3. Fill in training details</strong> — title, venue, and dates.</p>
                        <p><strong>4. Review the list</strong> and click <strong>Generate Certificates</strong> to produce PDFs in bulk.</p>
                        <p><strong>5. Download</strong> individual PDFs or the full ZIP archive.</p>
                        <hr>
                        <p class="mb-1"><strong>Accepted File Formats</strong></p>
                        <ul class="mb-2 ps-3">
                            <li>Comma-delimited <code>.csv</code></li>
                            <li>Semicolon-delimited <code>.csv</code> (European Excel)</li>
                        </ul>
                        <p class="mb-1"><strong>Column Header</strong> (case-insensitive, any of these):</p>
                        <ul class="mb-2 ps-3">
                            <li><code>Name</code></li>
                            <li><code>Participant Name</code></li>
                            <li><code>Full Name</code></li>
                            <li>Any column whose header contains the word <em>"name"</em></li>
                        </ul>
                        <p><strong>Position</strong> and <strong>Agency</strong> columns are optional but recommended — when present (any header containing "position"/"designation" or "agency"/"office"), they're captured alongside each participant. Other extra columns (e.g. No.) are ignored.</p>
                        <hr>
                        <p class="mb-1"><strong>Note</strong></p>
                        <p class="mb-0">Certificate reference numbering, QR validation, and signatory follow the existing OTRS standards.</p>
                    </div>
                </div>

                <!-- Sample CSV download -->
                <div class="step-card">
                    <div class="step-body text-center py-3">
                        <i class="bi bi-download text-muted" style="font-size:1.4rem;"></i>
                        <p class="mt-2 mb-3" style="font-size:.82rem;color:#5a7094;">
                            Need a template? Download a sample CSV to get started.
                        </p>
                        <button id="btnSampleCsv" class="btn-action ba-blue w-100 justify-content-center">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Sample CSV
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php imis_include('footer_db') ?>
<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>

<script>
$(function () {

    const CSRF = () => window.csrfToken ?? '';

    // ── Sample CSV download ───────────────────────────────────────────────────
    $('#btnSampleCsv').on('click', function () {
        // Tab-separated so Excel opens it correctly in all locales without
        // treating semicolons or commas as part of a name.
        const content = [
            'No.,Participant Name,Position,Agency',
            '1,Juan Dela Cruz,Administrative Officer II,Department of Education',
            '2,Maria Santos,HR Assistant,Bureau of Internal Revenue',
            '3,Pedro Reyes,Clerk III,Department of Health',
            '',
        ].join('\r\n');
        const blob = new Blob(['﻿' + content], { type: 'text/csv;charset=utf-8' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'sample_participants.csv';
        a.click();
        URL.revokeObjectURL(url);
    });

    // ── Drag & drop ───────────────────────────────────────────────────────────
    const $zone = $('#csvDropZone');
    $zone.on('dragover dragenter', function (e) {
        e.preventDefault();
        $zone.addClass('dragover');
    }).on('dragleave dragend drop', function (e) {
        e.preventDefault();
        $zone.removeClass('dragover');
        if (e.type === 'drop') {
            const files = e.originalEvent.dataTransfer.files;
            if (files.length) handleCsvFile(files[0]);
        }
    });

    $('#csvFile').on('change', function () {
        if (this.files.length) handleCsvFile(this.files[0]);
    });

    // ── CSV parse ─────────────────────────────────────────────────────────────
    let parsedParticipants = [];

    function handleCsvFile(file) {
        $('#csvErrors').hide().html('');
        $('#csvFileChosen').hide();
        $('#participantPreview').hide();
        parsedParticipants = [];

        const fd = new FormData();
        fd.append('csv_file', file);
        fd.append('_csrf', CSRF());

        Swal.fire({
            title: 'Parsing CSV…',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        fetch('api/agency_parse_csv.php', {
            method: 'POST', body: fd,
            headers: { 'X-CSRF-Token': CSRF() }
        })
        .then(r => r.json())
        .then(res => {
            Swal.close();
            if (!res.success) {
                Swal.fire('CSV Error', res.error || 'Could not parse the file.', 'error');
                return;
            }

            parsedParticipants = res.participants;
            $('#csvFileName').text(file.name + ' — ' + res.count + ' participant(s) found');
            $('#csvFileChosen').show();

            if (res.errors && res.errors.length) {
                let html = '<div class="alert alert-warning py-2 mb-0" style="font-size:.8rem;">'
                         + '<strong>Warnings:</strong><ul class="mb-0 ps-3 mt-1">'
                         + res.errors.map(e => `<li>${e}</li>`).join('')
                         + '</ul></div>';
                $('#csvErrors').html(html).show();
            }

            renderPreview();
        })
        .catch(() => {
            Swal.close();
            Swal.fire('Error', 'CSV upload failed. Please try again.', 'error');
        });
    }

    function renderPreview() {
        if (!parsedParticipants.length) return;
        const tbody = $('#previewTableBody').empty();
        parsedParticipants.forEach((p, i) => {
            tbody.append(
                `<tr><td class="text-center text-muted">${i + 1}</td>`
                + `<td>${$('<span>').text(p.name).html()}</td>`
                + `<td class="text-muted">${$('<span>').text(p.position || '—').html()}</td>`
                + `<td class="text-muted">${$('<span>').text(p.agency || '—').html()}</td></tr>`
            );
        });
        $('#previewCountText').text(parsedParticipants.length + ' participant(s)');
        $('#participantPreview').show();
        $('#resultsSection').hide();
    }

    // ── Merged format gating — only available for Face-to-Face modality ──────
    function toggleMergedAvailability() {
        const isF2F = $('#trainingMode').val() === 'face-to-face';
        $('#ctMerged').prop('disabled', !isF2F);
        if (!isF2F) $('#ctMerged').prop('checked', false);
        $('#ctMergedCert').prop('disabled', !isF2F);
        if (!isF2F) $('#ctMergedCert').prop('checked', false);
        $('#ctMergedHint').toggle(!isF2F);
    }
    $('#trainingMode').on('change', toggleMergedAvailability);
    toggleMergedAvailability();

    // ── Generate certificates ─────────────────────────────────────────────────
    $('#btnGenerate').on('click', function () {
        const title        = $('#trainingTitle').val().trim();
        const venue        = $('#trainingVenue').val().trim();
        const startDate    = $('#startDate').val();
        const endDate      = $('#endDate').val();
        const certTypes    = $('input[name=cert_types]:checked').map((i, el) => el.value).get();
        const noHrs        = parseInt($('#noHrs').val(), 10) || 0;
        const trainingType = $('#trainingType').val();
        const trainingMode = $('#trainingMode').val();
        const includeSignature = $('#includeSignature').is(':checked');

        if (!parsedParticipants.length) {
            Swal.fire('No Participants', 'Please upload a CSV file first.', 'warning');
            return;
        }
        if (!certTypes.length) {
            Swal.fire('Missing Info', 'Please select at least one certificate format.', 'warning');
            return;
        }
        if (!title) {
            Swal.fire('Missing Info', 'Please enter the training title.', 'warning');
            $('#trainingTitle').focus();
            return;
        }
        if (!startDate) {
            Swal.fire('Missing Info', 'Please enter the start date.', 'warning');
            $('#startDate').focus();
            return;
        }
        if (!endDate) {
            Swal.fire('Missing Info', 'Please enter the end date.', 'warning');
            $('#endDate').focus();
            return;
        }
        if (endDate < startDate) {
            Swal.fire('Invalid Dates', 'End date cannot be before start date.', 'warning');
            return;
        }

        const certLabel = certTypes.join(' + ');

        Swal.fire({
            title: 'Generate Certificates?',
            html: `Will generate <strong>${parsedParticipants.length}</strong> participant(s) × <em>${certLabel}</em> PDF(s).<br>
                   <small class="text-muted">This may take a moment. Do not close this page.</small>
                   ${includeSignature ? '' : '<br><small class="text-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>E-signature will be <strong>excluded</strong> — for hand-signed certificates.</small>'}`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-patch-check-fill me-1"></i> Generate',
            confirmButtonColor: '#7b00cc',
        }).then(r => {
            if (!r.isConfirmed) return;

            Swal.fire({
                title: 'Generating certificates…',
                html: `<small>Processing ${parsedParticipants.length} participant(s). Please wait.</small>`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('training_title', title);
            fd.append('venue',          venue);
            fd.append('start_date',     startDate);
            fd.append('end_date',       endDate);
            certTypes.forEach(t => fd.append('cert_types[]', t));
            fd.append('no_hrs',         noHrs);
            fd.append('training_type',  trainingType);
            fd.append('training_mode',  trainingMode);
            fd.append('include_signature', includeSignature ? '1' : '0');
            fd.append('_csrf',          CSRF());
            parsedParticipants.forEach(p => {
                fd.append('participant_names[]',     p.name);
                fd.append('participant_positions[]', p.position || '');
                fd.append('participant_agencies[]',  p.agency   || '');
            });

            fetch('api/agency_cert_issue_ajax.php', {
                method: 'POST', body: fd,
                headers: { 'X-CSRF-Token': CSRF() }
            })
            .then(resp => {
                const ct = resp.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    return resp.text().then(txt => {
                        throw new Error('Server returned non-JSON (' + resp.status + '): ' + txt.substring(0, 300));
                    });
                }
                return resp.json();
            })
            .then(res => {
                Swal.close();
                if (res.error) {
                    Swal.fire('Generation Failed', res.error, 'error');
                    return;
                }
                if (!res.success && !res.count) {
                    const errList = (res.errors || []).join('\n') || 'Unknown error — check server logs.';
                    Swal.fire('Generation Failed', '<pre style="text-align:left;font-size:.8rem;">' + escHtml(errList) + '</pre>', 'error');
                    return;
                }
                renderResults(res, title, startDate, endDate, certLabel);
            })
            .catch(err => {
                Swal.close();
                Swal.fire('Error', err.message || 'Request failed. Please try again.', 'error');
            });
        });
    });

    // ── Render results ────────────────────────────────────────────────────────
    function renderResults(res, title, startDate, endDate, certLabel) {
        const batchId    = res.batch_id;
        const count      = res.count || 0;
        const dateStr    = formatDateRange(startDate, endDate);

        $('#resultTitle').html(`<i class="bi bi-check-circle-fill me-1"></i> ${count} certificate(s) generated`);
        $('#resultMeta').text(`${certLabel} · ${title} · ${dateStr}`);

        $('#btnDownloadZip')
            .attr('href', `api/agency_cert_zip.php?batch_id=${batchId}`)
            .show();

        const $list = $('#certResultsList').empty();
        (res.results || []).forEach(r => {
            if (r.success) {
                // Completion/Certification live in otrs_agency_cert_records (legacy
                // agency viewer/re-issue endpoint); Merged types are CCS-only (otrs_ccs_certificates).
                const isCcs    = r.format === 'Merged' || r.format === 'MergedCertification';
                const viewUrl  = isCcs ? `ccs_cert_view.php?id=${r.cert_id}` : `agency_cert_view.php?id=${r.cert_id}`;
                $list.append(
                    `<div class="cert-result-card" data-cert-id="${r.cert_id}" data-is-ccs="${isCcs ? '1' : '0'}">
                        <div class="cert-result-icon"><i class="bi bi-file-earmark-pdf-fill"></i></div>
                        <div>
                            <div class="cert-result-name">${escHtml(r.name.toUpperCase())} ${r.format ? `<span class="text-muted" style="font-size:.7rem;">(${escHtml(r.format)})</span>` : ''}</div>
                            <div class="cert-result-no">${escHtml(r.cert_number)}</div>
                        </div>
                        <div class="cert-result-actions d-flex gap-1">
                            <a href="${viewUrl}" target="_blank"
                               class="btn btn-primary btn-sm" title="View PDF">
                                <i class="bi bi-eye me-1"></i>View
                            </a>
                            <button class="btn btn-warning btn-sm btn-reissue-agency"
                                    data-cert-id="${r.cert_id}" data-name="${escHtml(r.name)}"
                                    data-is-ccs="${isCcs ? '1' : '0'}"
                                    title="Re-issue">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                        </div>
                    </div>`
                );
            } else {
                $list.append(
                    `<div class="cert-result-card">
                        <div class="cert-result-icon error"><i class="bi bi-exclamation-triangle-fill"></i></div>
                        <div>
                            <div class="cert-result-name">${escHtml(r.name.toUpperCase())}</div>
                            <div class="cert-result-err">${escHtml(r.error || 'Generation failed')}</div>
                        </div>
                    </div>`
                );
            }
        });

        $('#participantPreview').hide();
        $('#resultsSection').show();
        $('html, body').animate({ scrollTop: $('#resultsSection').offset().top - 20 }, 400);
    }

    // ── Re-issue ──────────────────────────────────────────────────────────────
    $(document).on('click', '.btn-reissue-agency', function () {
        const certId = $(this).data('cert-id');
        const name   = $(this).data('name');
        const isCcs  = $(this).data('is-ccs') === '1' || $(this).data('is-ccs') === 1;
        const $btn   = $(this);

        Swal.fire({
            title: 'Re-issue Certificate?',
            html: `Generates a new PDF for <strong>${escHtml(name)}</strong>.<br>
                   <small class="text-muted">The existing certificate number will be retained.</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-arrow-repeat me-1"></i> Re-issue',
            confirmButtonColor: '#f0a500',
        }).then(result => {
            if (!result.isConfirmed) return;

            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

            const fd = new FormData();
            fd.append('cert_id', certId);
            fd.append('_csrf', CSRF());

            fetch(isCcs ? 'api/ccs_reissue_ajax.php' : 'api/agency_cert_reissue_ajax.php', {
                method: 'POST', body: fd,
                headers: { 'X-CSRF-Token': CSRF() }
            })
            .then(resp => {
                const ct = resp.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    return resp.text().then(txt => {
                        throw new Error('Server error (' + resp.status + '): ' + txt.substring(0, 300));
                    });
                }
                return resp.json();
            })
            .then(res => {
                $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i>');
                if (!res.success) {
                    Swal.fire('Re-issue Failed', res.error || 'Unknown error.', 'error');
                    return;
                }
                Swal.fire({
                    icon: 'success',
                    title: 'Re-issued!',
                    html: `Certificate <code>${escHtml(res.cert_number)}</code> has been re-generated.`,
                    confirmButtonText: 'View PDF',
                    showCancelButton: true,
                    cancelButtonText: 'Close',
                }).then(r2 => {
                    if (r2.isConfirmed) {
                        const viewUrl = isCcs ? 'ccs_cert_view.php?id=' : 'agency_cert_view.php?id=';
                        window.open(viewUrl + res.cert_id, '_blank');
                    }
                });
            })
            .catch(err => {
                $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i>');
                Swal.fire('Error', err.message || 'Request failed.', 'error');
            });
        });
    });

    // ── Start Over ────────────────────────────────────────────────────────────
    $('#btnStartOver').on('click', function () {
        parsedParticipants = [];
        $('#csvFile').val('');
        $('#csvFileChosen').hide();
        $('#csvErrors').hide().html('');
        $('#participantPreview').hide();
        $('#previewTableBody').empty();
        $('#resultsSection').hide();
        $('#certResultsList').empty();
        $('html, body').animate({ scrollTop: 0 }, 300);
    });

    // ── Helpers ───────────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function formatDateRange(start, end) {
        const s = new Date(start + 'T00:00:00');
        const e = new Date(end   + 'T00:00:00');
        const fmt = d => d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
        if (start === end) return fmt(s);
        if (s.getFullYear() !== e.getFullYear()) return fmt(s) + ' – ' + fmt(e);
        if (s.getMonth() !== e.getMonth())
            return s.toLocaleDateString('en-PH', { month: 'long', day: 'numeric' })
                 + ' – ' + fmt(e);
        return s.toLocaleDateString('en-PH', { month: 'long', day: 'numeric' })
             + '–' + e.getDate() + ', ' + e.getFullYear();
    }

});
</script>
</body>
</html>
