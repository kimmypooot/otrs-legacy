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
<title>Email Template Management – OTRS HRD</title>
<link href="../../assets/img/favicon.png" rel="icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../../assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
<link href="../../assets/vendor/remixicon/remixicon.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
<link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">
<style>
/* ── card grid ── */
.tpl-card { border:1px solid #e2e8f0; border-radius:10px; background:#fff;
            transition:box-shadow .18s, border-color .18s; }
.tpl-card:hover { box-shadow:0 4px 18px rgba(1,41,112,.1); border-color:#c7d5f5; }
.tpl-card .card-top { padding:18px 18px 12px; border-bottom:1px solid #f0f4fc; }
.tpl-card .card-actions { padding:12px 18px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.tpl-badge-active   { background:#d1fae5; color:#065f46; font-size:.72rem; font-weight:700;
                      padding:.22em .7em; border-radius:999px; }
.tpl-badge-inactive { background:#fee2e2; color:#991b1b; font-size:.72rem; font-weight:700;
                      padding:.22em .7em; border-radius:999px; }
.tpl-code  { font-family:monospace; font-size:.78rem; color:#6b7280; background:#f3f4f6;
             padding:.15em .5em; border-radius:4px; }
.tpl-desc  { font-size:.83rem; color:#4b5563; margin:6px 0 0; line-height:1.55; }
.tpl-meta  { font-size:.73rem; color:#9ca3af; margin-top:8px; }

/* ── preview modal ── */
#previewIframe { width:100%; border:0; min-height:580px; border-radius:6px; background:#f9fafb; }
.preview-subject { font-size:.9rem; background:#f0f4ff; border:1px solid #c7d5f5;
                   border-radius:6px; padding:10px 14px; margin-bottom:12px; }

/* ── variable chips ── */
.var-chips { display:flex; flex-wrap:wrap; gap:6px; }
.var-chip  { font-family:monospace; font-size:.75rem; background:#f0f4ff; color:#3b4fc8;
             border:1px solid #c7d5f5; border-radius:4px; padding:.15em .55em;
             cursor:pointer; user-select:none; }
.var-chip:hover { background:#dce4ff; }

/* ── TinyMCE wrapper ── */
.tox-tinymce { border-radius:6px !important; border-color:#ced4da !important; }
#fBody { min-height:320px; }
</style>
</head>
<body>

<?php imis_include('header') ?>
<?php include 'inc/sidebar.php' ?>

<main id="main" class="main">

  <div class="pagetitle otrs-ph">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-envelope-paper-fill fs-4" style="color:#012970;"></i>
        <div>
          <h1>Email Template Management</h1>
          <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="index">HRD</a></li>
            <li class="breadcrumb-item active">Email Templates</li></ol></nav>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="btnSeedTemplates">
          <i class="bi bi-arrow-down-circle me-1"></i>Seed Defaults
        </button>
        <button class="btn btn-sm btn-primary" id="btnNewTemplate">
          <i class="bi bi-plus-circle me-1"></i>New Template
        </button>
      </div>
    </div>
  </div>

  <section class="section">
    <div id="templateGrid" class="row g-3">
      <div class="col-12 text-center py-5 text-muted" id="gridLoader">
        <div class="spinner-border spinner-border-sm me-2"></div> Loading templates…
      </div>
    </div>
  </section>

</main>

<!-- ════════════════════════════════════════════════════════════
     EDIT / CREATE MODAL
═══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalTitle"><i class="bi bi-pencil-square me-2"></i>Edit Template</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="editForm" novalidate>
          <input type="hidden" id="fId" name="id" value="0">

          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Template Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control font-monospace" id="fCode" name="template_code"
                     placeholder="e.g. registration_ack" pattern="[a-z0-9_]{2,64}" required>
              <div class="form-text">Lowercase letters, numbers, underscores — used by PHP callers.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Template Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="fName" name="template_name" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Badge Label</label>
              <input type="text" class="form-control" id="fBadge" name="badge_label"
                     placeholder="Shown in email header banner">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Email Subject <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="fSubject" name="email_subject" required>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Description</label>
            <input type="text" class="form-control" id="fDesc" name="description"
                   placeholder="Internal note about when this template is used">
          </div>

          <!-- Variable reference chips -->
          <div class="mb-3">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <label class="form-label fw-semibold mb-0">Available Placeholders</label>
              <small class="text-muted">Click to insert at cursor position in the editor</small>
            </div>
            <div class="var-chips">
              <?php
              $vars = [
                '{participant_name}','{training_title}','{training_date}','{training_schedule}',
                '{venue}','{payment_amount}','{payment_deadline}','{payment_status}',
                '{reference_number}','{agency}','{evaluation_link}','{training_link}',
                '{contact_person}','{contact_email}',
                '{bank_details_block}','{penalty_note}','{contact_line}','{signature_block}',
                '{registration_link_block}','{payment_status_badge}','{training_info_block}',
                '{evaluation_links_table}','{training_link_block}',
              ];
              foreach ($vars as $v): ?>
              <span class="var-chip" data-var="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></span>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- WYSIWYG HTML Body — TinyMCE attaches here -->
          <div class="mb-3">
            <label class="form-label fw-semibold">
              HTML Body (inner content) <span class="text-danger">*</span>
              <span class="badge bg-light text-secondary fw-normal ms-1" style="font-size:.7rem;">WYSIWYG</span>
            </label>
            <textarea id="fBody" name="email_body" required></textarea>
          </div>

          <!-- Plain-text AltBody -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Plain-text Alt Body</label>
            <textarea class="form-control font-monospace" id="fAlt" name="email_altbody" rows="6"
                      placeholder="Shown in email clients that block HTML"></textarea>
          </div>

          <!-- Change note (updates only) -->
          <div class="mb-2" id="changeNoteRow" style="display:none;">
            <label class="form-label fw-semibold">Change Note</label>
            <input type="text" class="form-control" id="fNote" name="change_note"
                   placeholder="Optional: describe what changed">
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-info" id="btnPreviewUnsaved">
          <i class="bi bi-eye me-1"></i>Full Preview
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btnSaveTemplate">
          <span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span>
          <i class="bi bi-floppy me-1"></i>Save Template
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     PREVIEW MODAL
═══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Email Preview <span class="text-muted fw-normal fs-6" id="previewVersionLabel"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="preview-subject">
          <span class="text-muted me-2">Subject:</span>
          <strong id="previewSubject"></strong>
        </div>
        <iframe id="previewIframe" title="Email Preview" sandbox="allow-same-origin"></iframe>
      </div>
      <div class="modal-footer">
        <div class="me-auto">
          <div class="input-group input-group-sm">
            <span class="input-group-text">Send test to</span>
            <input type="email" class="form-control" id="testEmail" style="min-width:230px;"
                   placeholder="admin@example.com">
            <button class="btn btn-outline-primary" id="btnDoTestSend">
              <i class="bi bi-send me-1"></i>Send
            </button>
          </div>
        </div>
        <button class="btn btn-outline-secondary" id="btnBackToEdit" style="display:none;">
          <i class="bi bi-arrow-left me-1"></i>Back to Edit
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     VERSION HISTORY MODAL
═══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="versionsModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Version History</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="versionsBody" class="p-3"></div>
      </div>
    </div>
  </div>
</div>

<?php imis_include('footer_db') ?>

<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- TinyMCE 6 self-hosted from cdnjs — no API key required -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.5/tinymce.min.js"></script>
<script>
const CSRF = '<?= htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES) ?>';
const API  = 'api/email_templates/';

// ── State ──────────────────────────────────────────────────────────
let activePreviewTemplateId = 0;
let activePreviewIsUnsaved  = false;
let previewFromEditModal    = false;
let pendingEditorContent    = '';   // body HTML to load once TinyMCE inits

// ── TinyMCE config ─────────────────────────────────────────────────
const TINYMCE_CONFIG = {
    selector: '#fBody',
    base_url: 'https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.5',
    suffix: '.min',
    plugins: 'lists link table image code',
    toolbar: [
        'undo redo | styles | bold italic underline | forecolor backcolor',
        'alignleft aligncenter alignright | bullist numlist | link table image | code',
    ],
    toolbar_mode: 'wrap',
    menubar: false,
    height: 420,
    skin: 'oxide',
    content_css: 'default',
    font_size_formats: '11px 12px 13px 14px 16px 18px 24px',
    style_formats: [
        { title: 'Paragraph (email)', block: 'p',
          styles: { margin:'0 0 14px', 'font-size':'14px', color:'#333', 'line-height':'1.7' } },
        { title: 'CSC Blue Bold', inline: 'b', styles: { color:'#012970' } },
        { title: 'Small note', inline: 'span', styles: { 'font-size':'13px', color:'#555' } },
    ],
    color_map: [
        '012970', 'CSC Navy',
        '0d6efd', 'Blue',
        '198754', 'Green',
        'dc3545', 'Red',
        'ffc107', 'Amber',
        '856404', 'Dark Amber',
        '333333', 'Dark Gray',
        '555555', 'Gray',
        'ffffff', 'White',
        '000000', 'Black',
    ],
    // Preserve all HTML including inline styles used by email templates
    valid_elements: '*[*]',
    extended_valid_elements: '*[*]',
    verify_html: false,
    // Don't convert URLs or encode entities that appear in {placeholders}
    convert_urls: false,
    entity_encoding: 'raw',
    setup(editor) {
        editor.on('init', () => {
            editor.setContent(pendingEditorContent);
        });
    },
};

function getEditor() { return tinymce.get('fBody'); }

function syncEditor() {
    const ed = getEditor();
    if (ed) ed.save(); // writes editor HTML back to the underlying textarea
}

// ── TinyMCE lifecycle tied to modal ────────────────────────────────
const editModalEl = document.getElementById('editModal');

editModalEl.addEventListener('shown.bs.modal', () => {
    if (!getEditor()) {
        tinymce.init(TINYMCE_CONFIG);
    }
});

editModalEl.addEventListener('hide.bs.modal', () => {
    syncEditor();
    tinymce.remove('#fBody');
});

// ── Helpers ────────────────────────────────────────────────────────
const post = (url, data) => fetch(url, {
    method: 'POST',
    headers: { 'X-CSRF-Token': CSRF },
    body: new URLSearchParams({ _csrf: CSRF, ...data }),
}).then(r => r.json());

const get = (url) => fetch(url).then(r => r.json());

function toast(icon, msg) {
    Swal.fire({ toast: true, position: 'top-end', icon, title: msg,
                showConfirmButton: false, timer: 3200, timerProgressBar: true });
}

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Load template grid ─────────────────────────────────────────────
function loadGrid() {
    document.getElementById('templateGrid').innerHTML =
        '<div class="col-12 text-center py-5 text-muted" id="gridLoader">' +
        '<div class="spinner-border spinner-border-sm me-2"></div> Loading templates…</div>';

    get(API + 'get_templates.php').then(data => {
        const grid = document.getElementById('templateGrid');
        if (!data.success) {
            grid.innerHTML = '<div class="col-12 text-danger p-4">Failed to load templates.</div>';
            return;
        }
        if (!data.templates.length) {
            grid.innerHTML = `<div class="col-12 text-center py-5 text-muted">
                <i class="bi bi-envelope-slash fs-2 d-block mb-2"></i>
                No templates yet. Click <b>Seed Defaults</b> to load the standard HRD templates,
                or <b>New Template</b> to create one.
            </div>`;
            return;
        }

        grid.innerHTML = data.templates.map(t => `
        <div class="col-md-6 col-xl-4">
          <div class="tpl-card h-100" data-id="${t.id}">
            <div class="card-top">
              <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                <h6 class="mb-0 fw-bold" style="font-size:.92rem;">${esc(t.template_name)}</h6>
                <span class="tpl-badge-${t.status} flex-shrink-0">${t.status.toUpperCase()}</span>
              </div>
              <div><span class="tpl-code">${esc(t.template_code)}</span></div>
              ${t.description ? `<p class="tpl-desc">${esc(t.description)}</p>` : ''}
              <div class="tpl-meta">
                v${t.version} &middot; ${t.version_count} archived version${t.version_count !== 1 ? 's' : ''}
                &middot; Updated ${new Date(t.updated_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})}
                by ${esc(t.updated_by || t.created_by)}
              </div>
            </div>
            <div class="card-actions">
              <button class="btn btn-sm btn-outline-primary btnEdit" data-id="${t.id}">
                <i class="bi bi-pencil me-1"></i>Edit
              </button>
              <button class="btn btn-sm btn-outline-info btnPreview" data-id="${t.id}">
                <i class="bi bi-eye me-1"></i>Preview
              </button>
              <button class="btn btn-sm btn-outline-secondary btnVersions" data-id="${t.id}">
                <i class="bi bi-clock-history me-1"></i>History
              </button>
              <button class="btn btn-sm ${t.status === 'active' ? 'btn-outline-warning' : 'btn-outline-success'} btnToggle"
                      data-id="${t.id}" data-status="${t.status}">
                <i class="bi bi-${t.status === 'active' ? 'pause-circle' : 'play-circle'} me-1"></i>
                ${t.status === 'active' ? 'Deactivate' : 'Activate'}
              </button>
            </div>
          </div>
        </div>`).join('');

        attachGridEvents();
    });
}

function attachGridEvents() {
    document.querySelectorAll('.btnEdit').forEach(b => b.addEventListener('click', () => openEdit(+b.dataset.id)));
    document.querySelectorAll('.btnPreview').forEach(b => b.addEventListener('click', () => openPreview(+b.dataset.id, false)));
    document.querySelectorAll('.btnVersions').forEach(b => b.addEventListener('click', () => openVersions(+b.dataset.id)));
    document.querySelectorAll('.btnToggle').forEach(b => b.addEventListener('click', () => toggleStatus(+b.dataset.id)));
}

// ── New / Edit ─────────────────────────────────────────────────────
document.getElementById('btnNewTemplate').addEventListener('click', () => openEdit(0));

function openEdit(id) {
    document.getElementById('editForm').reset();
    document.getElementById('fId').value = id;
    document.getElementById('changeNoteRow').style.display = id ? '' : 'none';
    document.getElementById('editModalTitle').innerHTML =
        `<i class="bi bi-${id ? 'pencil-square' : 'plus-circle'} me-2"></i>${id ? 'Edit' : 'New'} Template`;

    if (id) {
        get(API + `get_template.php?id=${id}`).then(d => {
            if (!d.success) { toast('error', 'Failed to load template.'); return; }
            const t = d.template;
            document.getElementById('fId').value      = t.id;
            document.getElementById('fCode').value    = t.template_code;
            document.getElementById('fName').value    = t.template_name;
            document.getElementById('fBadge').value   = t.badge_label;
            document.getElementById('fSubject').value = t.email_subject;
            document.getElementById('fDesc').value    = t.description;
            document.getElementById('fAlt').value     = t.email_altbody;
            document.getElementById('fNote').value    = '';

            // Store body for TinyMCE init callback; also set on live editor if already open
            pendingEditorContent = t.email_body;
            const ed = getEditor();
            if (ed) ed.setContent(pendingEditorContent);

            bootstrap.Modal.getOrCreateInstance(editModalEl).show();
        });
    } else {
        pendingEditorContent = '';
        bootstrap.Modal.getOrCreateInstance(editModalEl).show();
    }
}

// ── Save ───────────────────────────────────────────────────────────
document.getElementById('btnSaveTemplate').addEventListener('click', () => {
    syncEditor(); // flush TinyMCE → textarea before reading FormData

    const form = document.getElementById('editForm');
    if (!document.getElementById('fBody').value.trim()) {
        toast('warning', 'HTML Body cannot be empty.'); return;
    }
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const spin = document.getElementById('saveSpinner');
    spin.classList.remove('d-none');

    const data = {};
    new FormData(form).forEach((v, k) => data[k] = v);

    post(API + 'save_template.php', data).then(d => {
        spin.classList.add('d-none');
        if (d.success) {
            bootstrap.Modal.getInstance(editModalEl).hide();
            toast('success', d.message);
            loadGrid();
        } else {
            Swal.fire('Error', d.message, 'error');
        }
    });
});

// ── Placeholder chips → insert into TinyMCE ───────────────────────
document.querySelectorAll('.var-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        const val = chip.dataset.var;
        const ed  = getEditor();
        if (ed) {
            ed.insertContent(val);
            ed.focus();
        } else {
            // Fallback: direct textarea insertion (shouldn't normally reach here)
            const ta = document.getElementById('fBody');
            const s  = ta.selectionStart ?? ta.value.length;
            const e  = ta.selectionEnd   ?? ta.value.length;
            ta.value = ta.value.slice(0,s) + val + ta.value.slice(e);
            ta.selectionStart = ta.selectionEnd = s + val.length;
            ta.focus();
        }
    });
});

// ── Preview ────────────────────────────────────────────────────────
function getEditorDataForPreview() {
    syncEditor();
    const data = {};
    new FormData(document.getElementById('editForm')).forEach((v,k) => data[k] = v);
    return data;
}

function openPreview(id, unsaved, fromEdit = false) {
    activePreviewTemplateId = id;
    activePreviewIsUnsaved  = unsaved;
    previewFromEditModal    = fromEdit;
    document.getElementById('btnBackToEdit').style.display = fromEdit ? '' : 'none';
    document.getElementById('previewSubject').textContent = 'Loading…';
    document.getElementById('previewIframe').srcdoc =
        '<p style="font-family:sans-serif;padding:40px;color:#666;">Rendering preview…</p>';
    document.getElementById('previewVersionLabel').textContent = '';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal')).show();

    if (unsaved) {
        post(API + 'preview_template.php', getEditorDataForPreview()).then(showPreview);
    } else {
        get(API + `preview_template.php?id=${id}`).then(showPreview);
    }
}

function showPreview(d) {
    if (!d.success) {
        document.getElementById('previewSubject').textContent = 'Preview error: ' + d.message;
        return;
    }
    document.getElementById('previewSubject').textContent = d.subject;
    document.getElementById('previewIframe').srcdoc = d.body;
}

document.getElementById('btnPreviewUnsaved').addEventListener('click', () => {
    bootstrap.Modal.getInstance(editModalEl)?.hide();
    // Wait for hide animation + TinyMCE sync (hide.bs.modal calls syncEditor)
    editModalEl.addEventListener('hidden.bs.modal', function onHidden() {
        editModalEl.removeEventListener('hidden.bs.modal', onHidden);
        openPreview(0, true, true);
    });
});

// ── Back to Edit (from preview modal) ─────────────────────────────
document.getElementById('btnBackToEdit').addEventListener('click', () => {
    const previewModalEl = document.getElementById('previewModal');
    bootstrap.Modal.getInstance(previewModalEl)?.hide();
    previewModalEl.addEventListener('hidden.bs.modal', function onHidden() {
        previewModalEl.removeEventListener('hidden.bs.modal', onHidden);
        bootstrap.Modal.getOrCreateInstance(editModalEl).show();
    });
});

// ── Test send ──────────────────────────────────────────────────────
document.getElementById('btnDoTestSend').addEventListener('click', () => {
    const email = document.getElementById('testEmail').value.trim();
    if (activePreviewIsUnsaved || !activePreviewTemplateId) {
        toast('warning', 'Save the template first before sending a test email.'); return;
    }
    if (!email) { toast('warning', 'Enter a recipient email first.'); return; }

    post(API + 'test_send.php', { id: activePreviewTemplateId, to_email: email }).then(d => {
        toast(d.success ? 'success' : 'error', d.message);
    });
});

// ── Toggle status ──────────────────────────────────────────────────
function toggleStatus(id) {
    Swal.fire({
        title: 'Change template status?',
        text: 'Active templates are used by email-sending endpoints. Inactive templates are preserved but ignored.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, toggle it',
    }).then(r => {
        if (!r.isConfirmed) return;
        post(API + 'toggle_status.php', { id }).then(d => {
            toast(d.success ? 'success' : 'error', d.message);
            if (d.success) loadGrid();
        });
    });
}

// ── Version history ────────────────────────────────────────────────
function openVersions(id) {
    document.getElementById('versionsBody').innerHTML =
        '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading history…</div>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('versionsModal')).show();

    get(API + `get_versions.php?id=${id}`).then(d => {
        const el = document.getElementById('versionsBody');
        if (!d.success) { el.innerHTML = '<p class="text-danger p-3">Failed to load versions.</p>'; return; }
        if (!d.versions.length) {
            el.innerHTML = '<p class="text-muted p-4 text-center">No archived versions yet. Versions are saved each time you edit.</p>';
            return;
        }

        el.innerHTML = `
        <p class="text-muted mb-3 px-1">Showing ${d.versions.length} archived version(s) for
        <b>${esc(d.template_name)}</b> (current: <b>v${d.current_version}</b>)</p>
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light"><tr>
            <th>Version</th><th>Name</th><th>Subject</th>
            <th>Changed By</th><th>Date</th><th>Note</th><th></th>
          </tr></thead>
          <tbody>
          ${d.versions.map(v => `<tr>
            <td><span class="badge bg-secondary">v${v.version}</span></td>
            <td class="small">${esc(v.template_name)}</td>
            <td class="small">${esc(v.email_subject)}</td>
            <td class="small">${esc(v.changed_by)}</td>
            <td class="small text-nowrap">${new Date(v.changed_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})}</td>
            <td class="small text-muted">${esc(v.change_note || '—')}</td>
            <td>
              <button class="btn btn-xs btn-outline-secondary py-0 px-2 btnPreviewVersion"
                      data-tid="${id}" data-vid="${v.id}" data-ver="${v.version}">
                <i class="bi bi-eye"></i>
              </button>
              <button class="btn btn-xs btn-outline-warning py-0 px-2 ms-1 btnRestoreVersion"
                      data-tid="${id}" data-vid="${v.id}" data-ver="${v.version}">
                <i class="bi bi-arrow-counterclockwise"></i> Restore
              </button>
            </td>
          </tr>`).join('')}
          </tbody>
        </table></div>`;

        el.querySelectorAll('.btnPreviewVersion').forEach(b =>
            b.addEventListener('click', () => previewVersion(+b.dataset.tid, +b.dataset.vid, +b.dataset.ver)));
        el.querySelectorAll('.btnRestoreVersion').forEach(b =>
            b.addEventListener('click', () => restoreVersion(+b.dataset.tid, +b.dataset.vid, +b.dataset.ver)));
    });
}

function previewVersion(tid, vid, ver) {
    get(API + `get_versions.php?id=${tid}`).then(d => {
        if (!d.success) { toast('error', 'Failed to load version.'); return; }
        const v = d.versions.find(x => x.id === vid);
        if (!v) { toast('error', 'Version not found.'); return; }

        const data = {
            template_name: v.template_name,
            badge_label:   v.badge_label,
            email_subject: v.email_subject,
            email_body:    v.email_body,
            email_altbody: v.email_altbody,
        };
        post(API + 'preview_template.php', data).then(pd => {
            if (!pd.success) { toast('error', pd.message); return; }
            document.getElementById('previewVersionLabel').textContent = `(v${ver} — archived)`;
            document.getElementById('previewSubject').textContent = pd.subject;
            document.getElementById('previewIframe').srcdoc = pd.body;
            activePreviewTemplateId = 0;
            activePreviewIsUnsaved  = true;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal')).show();
        });
    });
}

function restoreVersion(tid, vid, ver) {
    Swal.fire({
        title: `Restore v${ver}?`,
        text: 'The current version will be archived before restoring.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, restore',
        confirmButtonColor: '#dc3545',
    }).then(r => {
        if (!r.isConfirmed) return;
        post(API + 'restore_version.php', { template_id: tid, version_id: vid }).then(d => {
            toast(d.success ? 'success' : 'error', d.message);
            if (d.success) {
                bootstrap.Modal.getInstance(document.getElementById('versionsModal'))?.hide();
                loadGrid();
            }
        });
    });
}

// ── Seed defaults ──────────────────────────────────────────────────
document.getElementById('btnSeedTemplates').addEventListener('click', () => {
    Swal.fire({
        title: 'Seed default templates?',
        html: 'Inserts the 4 standard HRD email templates. Existing templates with the same code are skipped.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Seed',
    }).then(r => {
        if (!r.isConfirmed) return;
        post(API + 'seed_defaults.php', {}).then(d => {
            Swal.fire(d.success ? 'Done' : 'Error', d.message, d.success ? 'success' : 'error');
            if (d.success) loadGrid();
        });
    });
});

// ── Boot ───────────────────────────────────────────────────────────
loadGrid();
</script>
</body>
</html>
