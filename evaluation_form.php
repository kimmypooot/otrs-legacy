<?php
/**
 * imis/otrs/evaluation_form.php
 * Attendance + Evaluation wizard for HRD-generated signed links.
 * Access: ?reg_id=X&day=N&sig=HASH
 * Mirrors the f2f/index.php 4-step wizard; authenticated via HMAC (no IMIS session).
 */
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/connect.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, must-revalidate');

$regId  = filter_input(INPUT_GET, 'reg_id', FILTER_VALIDATE_INT);
$dayNum = filter_input(INPUT_GET, 'day',    FILTER_VALIDATE_INT);
$sig    = trim($_GET['sig'] ?? '');
$error  = '';
$reg    = null;

function evalFormSig(int $regId, int $dayNum, string $email, string $regDate): string {
    $key = $email . substr($regDate, 0, 10);
    return substr(hash_hmac('sha256', "{$regId}:{$dayNum}", $key), 0, 32);
}

if (!$regId || !$dayNum || $sig === '' || $dayNum < 1) {
    $error = 'Invalid or missing link parameters. Please use the link provided to you.';
} else {
    try {
        $stmtR = $conn->prepare("
            SELECT r.id AS reg_id, r.training_id, r.participant_id,
                   r.registration_status, r.payment_status,
                   DATE(r.created_at) AS reg_date,
                   p.fname, p.lname, p.mname, p.suffix, p.email,
                   t.name AS training_name, t.start_date, t.end_date,
                   t.mode, t.location, t.no_hrs
            FROM   otrs_registrations r
            JOIN   otrs_participants  p ON p.id = r.participant_id
            JOIN   otrs_trainings     t ON t.id = r.training_id
            WHERE  r.id = :rid
            LIMIT  1
        ");
        $stmtR->execute([':rid' => $regId]);
        $reg = $stmtR->fetch(PDO::FETCH_ASSOC);

        if (!$reg) {
            $error = 'Registration not found.';
        } else {
            $expectedSig = evalFormSig($regId, $dayNum, $reg['email'], $reg['reg_date']);
            if (!hash_equals($expectedSig, $sig)) {
                $error = 'Invalid link signature. Please use the original link sent to you.';
            } elseif (in_array(strtolower($reg['registration_status']), ['cancelled', 'canceled'], true)) {
                $error = 'Your registration has been cancelled.';
            } elseif (in_array(strtolower($reg['payment_status']), ['cancelled', 'canceled', 'not qualified'], true)) {
                $error = 'Your registration is not eligible for this evaluation.';
            }
        }
    } catch (PDOException $e) {
        error_log('[evaluation_form] ' . $e->getMessage());
        $error = 'A system error occurred. Please try again later.';
    }
}

$participantName = '';
if ($reg) {
    $mid = $reg['mname'] ? mb_substr(trim($reg['mname']), 0, 1) . '.' : '';
    $participantName = trim(implode(' ', array_filter([$reg['fname'], $mid, $reg['lname'], $reg['suffix'] ?? ''])));
}

function fmtDateRange(?string $s, ?string $e): string {
    if (!$s || $s === '0000-00-00') return '—';
    try {
        $sd = new DateTimeImmutable($s);
        $ed = ($e && $e !== '0000-00-00') ? new DateTimeImmutable($e) : null;
        if (!$ed || $sd->format('Y-m-d') === $ed->format('Y-m-d')) return $sd->format('F j, Y');
        if ($sd->format('Y-m') === $ed->format('Y-m')) return $sd->format('F j') . '–' . $ed->format('j, Y');
        return $sd->format('F j') . ' – ' . $ed->format('F j, Y');
    } catch (Exception $ex) { return htmlspecialchars($s ?? ''); }
}

function likertRow(string $name): string {
    return implode('', array_map(fn($v) =>
        "<td><input type=\"radio\" class=\"likert-radio\" name=\"{$name}\" value=\"{$v}\" required></td>",
        [1, 2, 3, 4, 5]
    ));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Training Evaluation Form – CSC RO VIII</title>
<meta name="robots" content="noindex, nofollow">
<link href="../assets/img/favicon.png" rel="icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<style>
  :root {
    --ev-primary: #1e3a5f;
    --ev-blue:    #0f6cbf;
    --ev-teal:    #1acc8d;
    --ev-green:   #16a34a;
    --surface:    #f0f4f8;
    --shadow:     0 2px 20px rgba(15,108,191,.10);
  }
  * { box-sizing: border-box; }
  body { font-family: 'Poppins', sans-serif; background: var(--surface); margin: 0; min-height: 100vh; }

  .page-header {
    background: linear-gradient(135deg, var(--ev-primary) 0%, var(--ev-blue) 100%);
    color: #fff; padding: 1.5rem 1.5rem .9rem; border-radius: 12px; margin-bottom: 0;
  }
  .page-header h1 { font-size: 1.05rem; font-weight: 700; margin: 0 0 .2rem; }
  .page-header .ph-meta { font-size: .74rem; opacity: .85; display: flex; flex-wrap: wrap; gap: .2rem .85rem; margin-top: .3rem; }

  /* Wizard card */
  .wizard-card { background: #fff; border-radius: 14px; box-shadow: var(--shadow); overflow: hidden; margin: 1.2rem 0 2rem; }
  .wizard-header {
    background: linear-gradient(135deg, var(--ev-primary) 0%, var(--ev-blue) 100%);
    padding: .95rem 1.4rem; color: #fff;
    display: flex; align-items: center; justify-content: space-between; gap: .5rem;
  }
  .wizard-header h4 { margin: 0; font-size: .88rem; font-weight: 700; }

  /* Step bar */
  .step-bar { display: flex; align-items: center; padding: .85rem 1.4rem; background: #f8faff; border-bottom: 1px solid #ebeef4; }
  .step-item { display: flex; align-items: center; gap: .4rem; flex: 1; font-size: .67rem; font-weight: 600; color: #adb5bd; }
  .step-item.active { color: var(--ev-blue); }
  .step-item.done   { color: var(--ev-teal); }
  .step-num { width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: .67rem; font-weight: 700; background: #e9ecef; color: #6c757d; }
  .step-item.active .step-num { background: var(--ev-blue); color: #fff; }
  .step-item.done   .step-num { background: var(--ev-teal); color: #fff; }
  .step-divider { flex: 0 0 18px; height: 2px; background: #e9ecef; margin: 0 .2rem; }
  .step-divider.done { background: var(--ev-teal); }

  /* Progress */
  .wizard-progress { height: 3px; background: #ebeef4; }
  .wizard-progress-bar { height: 3px; background: var(--ev-blue); transition: width .4s ease; }

  /* Body + steps */
  .wizard-body { padding: 1.3rem; }
  .wizard-step { display: none; }
  .wizard-step.active { display: block; animation: fadeSlide .25s ease both; }
  @keyframes fadeSlide { from{opacity:0;transform:translateX(12px)}to{opacity:1;transform:translateX(0)} }

  .wizard-training-strip { background: linear-gradient(135deg, var(--ev-primary) 0%, var(--ev-blue) 100%); border-radius: 8px; padding: .75rem 1rem; color: #fff; margin-bottom: 1rem; }
  .wts-title { font-size: .87rem; font-weight: 700; margin-bottom: .18rem; }
  .wts-meta  { font-size: .68rem; opacity: .85; display: flex; flex-wrap: wrap; gap: .18rem .75rem; }

  .pax-chip { background: #e0efff; border: 1.5px solid #93c5fd; border-radius: 8px; padding: .55rem .9rem; margin-bottom: 1rem; font-size: .8rem; color: var(--ev-primary); display: flex; align-items: center; gap: .45rem; }
  .pax-chip strong { font-weight: 700; }

  /* Eval form */
  .eval-section { background: #f8faff; border-radius: 10px; border: 1px solid #ebeef4; padding: 1rem 1.1rem; margin-bottom: .95rem; }
  .eval-section-title { font-size: .73rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: var(--ev-primary); margin-bottom: .75rem; display: flex; align-items: center; gap: .42rem; }
  .eval-intro { background: #dbeafe; border-left: 4px solid var(--ev-blue); border-radius: 0 8px 8px 0; padding: .78rem .9rem; margin-bottom: .95rem; font-size: .77rem; color: #1e3a5f; line-height: 1.6; }
  .likert-grid { width: 100%; border-collapse: collapse; font-size: .75rem; }
  .likert-grid th { text-align: center; padding: .28rem .16rem; font-size: .63rem; font-weight: 700; color: #6c757d; border-bottom: 2px solid #ebeef4; background: #f8faff; }
  .likert-grid th:first-child { text-align: left; padding-left: .5rem; width: 48%; }
  .likert-grid td { text-align: center; padding: .42rem .16rem; border-bottom: 1px solid #f0f4ff; vertical-align: middle; }
  .likert-grid td:first-child { text-align: left; padding-left: .5rem; font-weight: 500; color: #334155; }
  .likert-grid tr:last-child td { border-bottom: none; }
  .likert-radio { width: 16px; height: 16px; accent-color: var(--ev-blue); cursor: pointer; }
  .likert-scale-lbl { font-size: .52rem; font-weight: 400; color: #94a3b8; display: block; margin-top: 1px; line-height: 1.2; }
  .eval-label { font-size: .74rem; font-weight: 600; color: var(--ev-primary); margin-bottom: .25rem; display: block; }
  .eval-textarea { width: 100%; border: 1.5px solid #dce3f3; border-radius: 8px; padding: .58rem .82rem; font-size: .78rem; font-family: 'Poppins', sans-serif; resize: vertical; min-height: 68px; transition: border-color .2s, box-shadow .2s; }
  .eval-textarea:focus { border-color: var(--ev-blue); box-shadow: 0 0 0 3px rgba(15,108,191,.12); outline: none; }
  .barrier-check-item { display: flex; align-items: flex-start; gap: .42rem; padding: .26rem 0; font-size: .74rem; color: #444; }
  .barrier-check-item input[type="checkbox"] { width: 15px; height: 15px; margin-top: 2px; flex-shrink: 0; accent-color: var(--ev-blue); }
  .resubmit-notice { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; padding: .65rem .9rem; font-size: .74rem; color: #92400e; display: flex; align-items: flex-start; gap: .48rem; margin-bottom: .85rem; }

  /* Confirmation */
  .confirm-box { background: #eff6ff; border: 1.5px solid #93c5fd; border-radius: 12px; padding: 1.8rem 1.4rem; text-align: center; }
  .confirm-icon { font-size: 3rem; color: var(--ev-blue); margin-bottom: .7rem; }
  .ref-number { font-family: 'Courier New', monospace; font-size: .9rem; color: var(--ev-blue); font-weight: 700; }

  /* Footer */
  .wizard-footer { padding: .85rem 1.4rem 1.1rem; display: flex; align-items: center; justify-content: space-between; gap: .5rem; border-top: 1px solid #ebeef4; }
  .btn-wz { border: none; border-radius: 50px; padding: .48rem 1.35rem; font-size: .78rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: .32rem; transition: filter .15s, transform .12s; }
  .btn-wz:hover  { filter: brightness(.9); transform: translateY(-1px); }
  .btn-wz:disabled { opacity: .55; cursor: not-allowed; transform: none; }
  .btn-wz-back   { background: #e9ecef; color: #495057; }
  .btn-wz-next   { background: var(--ev-blue);  color: #fff; }
  .btn-wz-submit { background: var(--ev-green); color: #fff; }

  /* Loading + info */
  .loading-block { text-align: center; padding: 2.8rem 1rem; color: #64748b; }
  .loading-block .spinner-border { color: var(--ev-blue); width: 2.2rem; height: 2.2rem; }
  .info-block { text-align: center; padding: 2.4rem 1.4rem; }
  .info-block .info-icon { font-size: 3rem; margin-bottom: .7rem; }
  .error-page { text-align: center; padding: 2.8rem 1.4rem; background: #fff; border-radius: 14px; box-shadow: var(--shadow); margin: 1.8rem 0; }
  .error-page .err-icon { font-size: 3.2rem; color: #dc2626; margin-bottom: .9rem; }

  @media (max-width: 576px) {
    .wizard-body, .wizard-footer, .step-bar { padding: .7rem .85rem; }
    .step-item span.label { display: none; }
    .page-header { padding: 1.1rem .95rem .75rem; }
  }
</style>
</head>
<body>

<div class="container" style="max-width:780px;padding:0 12px;">

<?php if ($error): ?>
<div class="error-page mt-3">
  <div class="err-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
  <h4 class="fw-bold mb-2" style="color:#1e3a5f;">Access Denied</h4>
  <p style="font-size:.86rem;color:#555;"><?php echo htmlspecialchars($error); ?></p>
  <p style="font-size:.75rem;color:#94a3b8;margin-top:.65rem;">If you believe this is an error, please contact the CSC RO VIII Human Resource Division.</p>
</div>

<?php else: ?>
<div class="page-header mt-3">
  <h1><i class="bi bi-journal-check me-2"></i>Training Evaluation Form</h1>
  <div class="ph-meta">
    <span><i class="bi bi-building me-1"></i>CSC Regional Office VIII</span>
    <span><i class="bi bi-shield-check me-1"></i>Secure Personalized Link</span>
  </div>
</div>

<div class="wizard-card">
  <div class="wizard-header">
    <h4><i class="bi bi-clipboard-check-fill me-2"></i>Evaluation Wizard</h4>
    <span id="wizardStepLabel" style="font-size:.7rem;opacity:.82;white-space:nowrap;"></span>
  </div>
  <div class="wizard-progress"><div class="wizard-progress-bar" id="progressBar" style="width:0%"></div></div>

  <div class="step-bar" id="stepBar" style="display:none;">
    <div class="step-item" id="stepInd1"><div class="step-num" id="stepNum1">1</div><span class="label">Facilitators</span></div>
    <div class="step-divider" id="stepDiv1"></div>
    <div class="step-item" id="stepInd2"><div class="step-num" id="stepNum2">2</div><span class="label" id="step3Label">Evaluation</span></div>
    <div class="step-divider" id="stepDiv2"></div>
    <div class="step-item" id="stepInd3"><div class="step-num" id="stepNum3">3</div><span class="label">Submitted</span></div>
  </div>

  <div class="wizard-body">

    <!-- Loading -->
    <div id="stepLoading" class="loading-block">
      <div class="spinner-border mb-3" role="status"></div>
      <p style="font-size:.83rem;">Loading your evaluation form…</p>
    </div>

    <!-- Info block (cert claimed / not available / error) -->
    <div id="infoBlock" style="display:none;"></div>

    <!-- Step 1 (Facilitators preview) -->
    <div class="wizard-step" id="step2">
      <div class="wizard-training-strip"><div class="wts-title" id="wtsTitle2"></div><div class="wts-meta" id="wtsMeta2"></div></div>
      <div class="pax-chip" id="paxChip2"></div>
      <div class="eval-intro">
        <strong>Facilitator(s) scheduled for this evaluation:</strong><br>
        The system has identified the Subject Matter Expert(s) whose last assigned day corresponds to the evaluation session linked to your form.
        Please proceed to rate them in the evaluation form.
      </div>
      <div id="smePreviewList"></div>
    </div>

    <!-- Step 3: Evaluation form -->
    <div class="wizard-step" id="step3">
      <div id="evalIntro3" class="eval-intro"></div>
      <div style="background:#eff6ff;border-radius:8px;padding:.6rem .9rem;margin-bottom:.9rem;font-size:.71rem;color:#555;">
        <strong style="color:var(--ev-primary);">Rating Scale:</strong>
        5 = Strongly Agree &nbsp;|&nbsp; 4 = Agree &nbsp;|&nbsp; 3 = Neither &nbsp;|&nbsp; 2 = Disagree &nbsp;|&nbsp; 1 = Strongly Disagree
      </div>
      <div id="evalContextNote"></div>

      <!-- SME sections (rendered dynamically) -->
      <div id="smeSections3"></div>

      <!-- General sections (only when training ended) -->
      <div id="generalEvalSections" style="display:none;">

        <div class="eval-section">
          <div class="eval-section-title"><i class="bi bi-palette-fill" style="color:var(--ev-blue)"></i> I. DESIGN</div>
          <table class="likert-grid">
            <thead><tr><th>Statement</th><th>1<br><small class="likert-scale-lbl">Strongly<br>Disagree</small></th><th>2<br><small class="likert-scale-lbl">Disagree</small></th><th>3<br><small class="likert-scale-lbl">Neither</small></th><th>4<br><small class="likert-scale-lbl">Agree</small></th><th>5<br><small class="likert-scale-lbl">Strongly<br>Agree</small></th></tr></thead>
            <tbody>
              <tr><td>I understood the learning objectives.</td><?= likertRow('design_q1') ?></tr>
              <tr><td>The learning objectives are aligned with my learning needs.</td><?= likertRow('design_q2') ?></tr>
              <tr><td>The new knowledge/skills I learned are highly relevant to my work.</td><?= likertRow('design_q3') ?></tr>
              <tr><td>I am comfortable with the pace of the program/course.</td><?= likertRow('design_q4') ?></tr>
            </tbody>
          </table>
          <div style="margin-top:.8rem;"><label class="eval-label" for="designComment">Comment on Design: <span class="text-danger">*</span></label>
          <textarea class="eval-textarea" id="designComment" rows="2" placeholder="Share your comments on the design of this training…"></textarea></div>
        </div>

        <div class="eval-section">
          <div class="eval-section-title"><i class="bi bi-journal-bookmark-fill" style="color:var(--ev-blue)"></i> II. TRAINING MATERIALS AND METHODOLOGIES</div>
          <table class="likert-grid">
            <thead><tr><th>Statement</th><th>1<br><small class="likert-scale-lbl">Strongly<br>Disagree</small></th><th>2<br><small class="likert-scale-lbl">Disagree</small></th><th>3<br><small class="likert-scale-lbl">Neither</small></th><th>4<br><small class="likert-scale-lbl">Agree</small></th><th>5<br><small class="likert-scale-lbl">Strongly<br>Agree</small></th></tr></thead>
            <tbody>
              <tr><td>The training materials are clear and appropriate for me.</td><?= likertRow('materials_q1') ?></tr>
              <tr><td>The methodologies facilitated my learnings.</td><?= likertRow('materials_q2') ?></tr>
              <tr><td>The activities during the session kept me engaged.</td><?= likertRow('materials_q3') ?></tr>
              <tr><td>The venue and facilities were conducive for learning.</td><?= likertRow('materials_q4') ?></tr>
            </tbody>
          </table>
          <div style="margin-top:.8rem;"><label class="eval-label" for="materialsComment">Comment on Materials: <span class="text-danger">*</span></label>
          <textarea class="eval-textarea" id="materialsComment" rows="2" placeholder="Share your comments on training materials and methodologies…"></textarea></div>
        </div>

        <div class="eval-section">
          <div class="eval-section-title"><i class="bi bi-building-fill" style="color:var(--ev-blue)"></i> IV. ADMINISTRATION</div>
          <table class="likert-grid">
            <thead><tr><th>Statement</th><th>1<br><small class="likert-scale-lbl">Strongly<br>Disagree</small></th><th>2<br><small class="likert-scale-lbl">Disagree</small></th><th>3<br><small class="likert-scale-lbl">Neither</small></th><th>4<br><small class="likert-scale-lbl">Agree</small></th><th>5<br><small class="likert-scale-lbl">Strongly<br>Agree</small></th></tr></thead>
            <tbody>
              <tr><td>The Learning Environment is conducive for my learning.</td><?= likertRow('admin_q1') ?></tr>
              <tr><td>I received the training information ahead of the training date.</td><?= likertRow('admin_q2') ?></tr>
              <tr><td>Training administrators attended to participant's and SME needs.</td><?= likertRow('admin_q3') ?></tr>
              <tr><td>The training administrator communicated with me in a professional manner.</td><?= likertRow('admin_q4') ?></tr>
            </tbody>
          </table>
          <div style="margin-top:.8rem;"><label class="eval-label" for="adminComment">Comment on Administration: <span class="text-danger">*</span></label>
          <textarea class="eval-textarea" id="adminComment" rows="2" placeholder="Share your comments on the administration of this training…"></textarea></div>
        </div>

        <div class="eval-section">
          <div class="eval-section-title"><i class="bi bi-lightning-charge-fill text-warning"></i> APPLICATION — Confidence Level</div>
          <table class="likert-grid">
            <thead><tr><th>Statement</th><th>1<br><small class="likert-scale-lbl">Strongly<br>Disagree</small></th><th>2<br><small class="likert-scale-lbl">Disagree</small></th><th>3<br><small class="likert-scale-lbl">Neither</small></th><th>4<br><small class="likert-scale-lbl">Agree</small></th><th>5<br><small class="likert-scale-lbl">Strongly<br>Agree</small></th></tr></thead>
            <tbody><tr><td>I am confident that I can apply my learnings from this session to perform my job.</td><?= likertRow('confidence_rating') ?></tr></tbody>
          </table>
          <div class="barrier-checks" id="confidenceBarriers"></div>
        </div>

        <div class="eval-section">
          <div class="eval-section-title"><i class="bi bi-hand-thumbs-up-fill text-success"></i> APPLICATION — Commitment Level</div>
          <table class="likert-grid">
            <thead><tr><th>Statement</th><th>1<br><small class="likert-scale-lbl">Strongly<br>Disagree</small></th><th>2<br><small class="likert-scale-lbl">Disagree</small></th><th>3<br><small class="likert-scale-lbl">Neither</small></th><th>4<br><small class="likert-scale-lbl">Agree</small></th><th>5<br><small class="likert-scale-lbl">Strongly<br>Agree</small></th></tr></thead>
            <tbody><tr><td>I can commit to apply the learnings.</td><?= likertRow('commitment_rating') ?></tr></tbody>
          </table>
          <div class="barrier-checks" id="commitmentBarriers"></div>
        </div>

        <div class="eval-section">
          <div class="eval-section-title"><i class="bi bi-chat-square-text-fill" style="color:var(--ev-blue)"></i> Open-Ended Feedback</div>
          <label class="eval-label" for="likedMost">1. What parts of the session did you like the most? <span class="text-danger">*</span></label>
          <textarea class="eval-textarea" id="likedMost" rows="3" placeholder="Share what you appreciated most about this training…"></textarea>
          <label class="eval-label mt-3" for="improvements">2. What needs to be improved? <span class="text-danger">*</span></label>
          <textarea class="eval-textarea" id="improvements" rows="3" placeholder="Suggest specific areas that can be improved…"></textarea>
          <label class="eval-label mt-3" for="recommendations">3. Other suggestions / recommendations <span class="text-danger">*</span></label>
          <textarea class="eval-textarea" id="recommendations" rows="3" placeholder="Any other feedback or recommendations…"></textarea>
          <label class="eval-label mt-3" for="futureTopics">4. What specific topics or skills would you like to see in future training programs? <span class="text-danger">*</span></label>
          <textarea class="eval-textarea" id="futureTopics" rows="3" placeholder="Topics, tools, or skills you want covered in future trainings…"></textarea>
        </div>

      </div><!-- /generalEvalSections -->
    </div><!-- /step3 -->

    <!-- Step 4: Confirmation -->
    <div class="wizard-step" id="step4">
      <div class="confirm-box" id="step4ConfirmBox">
        <div class="confirm-icon"><i class="bi bi-patch-check-fill"></i></div>
        <h5 id="step4Heading" style="font-weight:700;color:var(--ev-primary);">Forms Successfully Submitted!</h5>
        <p id="step4Msg" style="font-size:.82rem;color:#555;margin:.45rem 0 .75rem;">Thank you for completing your evaluation. Your feedback has been recorded.</p>
        <div class="ref-number" id="refNumberDisplay"></div>
        <div id="step4CertNote" style="background:#dbeafe;border:1px solid #93c5fd;border-radius:8px;padding:.6rem .88rem;font-size:.74rem;color:#1e40af;display:flex;align-items:flex-start;gap:.42rem;margin-top:.85rem;text-align:left;">
          <i class="bi bi-award-fill" style="font-size:1rem;flex-shrink:0;margin-top:1px;"></i>
          <span><strong>Certificate Processing.</strong> Your evaluation has been recorded. Please coordinate with HRD staff regarding your Certificate of Completion.</span>
        </div>
        <div style="margin-top:.9rem;padding-top:.8rem;border-top:1px dashed #93c5fd;font-size:.7rem;color:#6c757d;"><i class="bi bi-envelope me-1"></i>For concerns, contact CSC RO VIII Human Resource Division.</div>
      </div>
      <div id="certReadyBox" style="display:none;text-align:center;padding:1.3rem .9rem;">
        <div style="width:58px;height:58px;border-radius:50%;background:#eff6ff;border:2px solid #93c5fd;display:flex;align-items:center;justify-content:center;margin:0 auto .85rem;font-size:1.8rem;color:var(--ev-blue);"><i class="bi bi-patch-check-fill"></i></div>
        <h5 style="font-weight:700;color:var(--ev-primary);margin-bottom:.32rem;">Your Certificate is Ready!</h5>
        <p style="font-size:.82rem;color:#555;margin:.32rem 0 .72rem;">Congratulations! Your evaluation has been recorded and your official certificate has been generated.</p>
        <div id="certNumberDisplay" style="font-size:.76rem;font-weight:600;color:#1e3a5f;background:#dbeafe;border-radius:6px;padding:.28rem .72rem;display:inline-block;margin-bottom:.82rem;"></div>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:.6rem .88rem;font-size:.74rem;color:#92400e;display:flex;align-items:flex-start;gap:.42rem;margin-bottom:.85rem;text-align:left;">
          <i class="bi bi-exclamation-triangle-fill" style="font-size:1rem;flex-shrink:0;margin-top:1px;"></i>
          <span><strong>One-Time Download.</strong> This link is valid for <strong>1 hour</strong> and may only be used <strong>once</strong>. Save your file immediately after downloading.</span>
        </div>
        <a href="#" id="downloadBtn" target="_blank" style="display:inline-flex;align-items:center;gap:.42rem;background:#1e3a5f;color:#fff;padding:.58rem 1.35rem;border-radius:8px;font-size:.83rem;font-weight:600;text-decoration:none;transition:background .2s;">
          <i class="bi bi-file-earmark-arrow-down-fill"></i> Download Certificate PDF
        </a>
        <div id="downloadUsedMsg" style="display:none;margin-top:.8rem;">
          <div style="background:#eff6ff;border:1.5px solid #93c5fd;border-radius:8px;padding:.65rem .9rem;font-size:.76rem;color:#1e3a5f;">
            <i class="bi bi-check-circle-fill me-2"></i><strong>Certificate downloaded.</strong> Please check your downloads folder.
          </div>
        </div>
      </div>
    </div><!-- /step4 -->

  </div><!-- /.wizard-body -->

  <div class="wizard-footer" id="wizardFooter" style="display:none;">
    <button class="btn-wz btn-wz-back" id="btnBack" style="display:none;"><i class="bi bi-arrow-left"></i> Back</button>
    <div class="ms-auto d-flex gap-2">
      <button class="btn-wz btn-wz-next"   id="btnNext">Continue <i class="bi bi-arrow-right"></i></button>
      <button class="btn-wz btn-wz-submit" id="btnSubmit" style="display:none;"><i class="bi bi-send-fill"></i> Submit Evaluation</button>
    </div>
  </div>
</div><!-- /.wizard-card -->
<?php endif; ?>
</div><!-- /container -->

<!-- Barrier template -->
<template id="barrierTpl">
  <div style="font-size:.71rem;font-weight:700;color:#555;margin:.5rem 0 .25rem;">Please indicate the reason(s):</div>
  <div class="barrier-check-item"><input type="checkbox" name="__ID___reasons" value="no_knowledge"     id="__ID__r1"><label for="__ID__r1">I do not have the necessary knowledge and skills.</label></div>
  <div class="barrier-check-item"><input type="checkbox" name="__ID___reasons" value="no_picture"       id="__ID__r2"><label for="__ID__r2">I do not have a clear picture of what is expected of me.</label></div>
  <div class="barrier-check-item"><input type="checkbox" name="__ID___reasons" value="other_priorities" id="__ID__r3"><label for="__ID__r3">I have other higher priorities.</label></div>
  <div class="barrier-check-item"><input type="checkbox" name="__ID___reasons" value="no_resources"     id="__ID__r4"><label for="__ID__r4">I do not have the necessary resources to do it.</label></div>
  <div class="barrier-check-item"><input type="checkbox" name="__ID___reasons" value="no_support"       id="__ID__r5"><label for="__ID__r5">I do not have the human support to do it.</label></div>
  <div class="barrier-check-item" id="__ID__rHigh"><input type="checkbox" name="__ID___reasons" value="rated_high" id="__ID__r6" disabled><label for="__ID__r6" style="color:#059669;font-weight:600;">RATED 4 or 5</label></div>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<?php if (!$error): ?>
<script>
(function () {
  'use strict';

  /* ── PHP constants ── */
  const REG_ID           = <?php echo json_encode($regId); ?>;
  const DAY_NUM          = <?php echo json_encode($dayNum); ?>;
  const SIG              = <?php echo json_encode($sig); ?>;
  const PARTICIPANT_NAME = <?php echo json_encode($participantName); ?>;
  const TRAINING_NAME    = <?php echo json_encode($reg['training_name'] ?? ''); ?>;
  const TRAINING_DATE    = <?php echo json_encode(fmtDateRange($reg['start_date'] ?? null, $reg['end_date'] ?? null)); ?>;
  const TRAINING_MODE    = <?php echo json_encode($reg['mode'] ?? ''); ?>;
  const TRAINING_LOC     = <?php echo json_encode($reg['location'] ?? ''); ?>;
  const TRAINING_HRS     = <?php echo json_encode((string)($reg['no_hrs'] ?? '')); ?>;
  const DOWNLOAD_BASE    = 'https://otrs.cscro8.com/f2f/download.php';

  /* ── State ── */
  let trainingEnded = false;
  let currentEvalDay = null;
  let currentSmes = [];
  let claimToken = null, certNumber = null, refNumber = null;
  let currentStep = 0, isSubmitting = false, evalAlreadySubmitted = false;
  let evalDayNums    = [];
  let showGeneralEval = false;

  /* ── Helpers ── */
  function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }
  async function apiFetch(url, opts = {}) {
    const res = await fetch(url, opts);
    if (!res.ok) { const d = await res.json().catch(() => ({})); throw new Error(d.error || `HTTP ${res.status}`); }
    return res.json();
  }
  function postJSON(url, body) {
    return apiFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  }
  function buildMetaHtml() {
    return [
      TRAINING_DATE ? `<span><i class="bi bi-calendar3 me-1"></i>${esc(TRAINING_DATE)}</span>` : '',
      TRAINING_MODE ? `<span><i class="bi bi-people me-1"></i>${esc(TRAINING_MODE)}</span>` : '',
      TRAINING_LOC  ? `<span><i class="bi bi-geo-alt me-1"></i>${esc(TRAINING_LOC)}</span>` : '',
      TRAINING_HRS  ? `<span><i class="bi bi-clock-history me-1"></i>${esc(TRAINING_HRS)} hrs</span>` : '',
    ].filter(Boolean).join('');
  }
  function populateStrips() {
    const meta = buildMetaHtml();
    const chip = `<i class="bi bi-person-fill"></i><span>Participant: <strong>${esc(PARTICIPANT_NAME)}</strong></span>`;
    const t = document.getElementById('wtsTitle2'); if (t) t.textContent = TRAINING_NAME;
    const m = document.getElementById('wtsMeta2');  if (m) m.innerHTML = meta;
    const p = document.getElementById('paxChip2');  if (p) p.innerHTML = chip;
    const ei = document.getElementById('evalIntro3');
    if (ei) ei.innerHTML = `Thank you for your participation in <strong>${esc(TRAINING_NAME)}</strong>${TRAINING_DATE ? ` on <strong>${esc(TRAINING_DATE)}</strong>` : ''}.${TRAINING_LOC ? ` Venue: <strong>${esc(TRAINING_LOC)}</strong>.` : ''}<br>Please take a few minutes to fill out this evaluation form. All feedback is treated with utmost confidentiality.`;
  }

  /* ── Step management ──
   * Internal step numbers: 2 = Facilitators, 3 = Evaluation, 4 = Submitted
   * Display as:            1 of 3,            2 of 3,         3 of 3
   */
  function setStep(n) {
    currentStep = n;
    const displayNum = n - 1; // 2→1, 3→2, 4→3
    const labels = { 2: 'Facilitators', 3: showGeneralEval ? 'Evaluation' : 'Facilitation', 4: 'Submitted' };
    const sl = document.getElementById('wizardStepLabel');
    if (sl) sl.textContent = n >= 2 && n <= 4 ? `Step ${displayNum} of 3 — ${labels[n] || ''}` : '';
    const pb = document.getElementById('progressBar');
    if (pb) pb.style.width = ({ 2: 33, 3: 67, 4: 100 }[n] || 0) + '%';
    const s3l = document.getElementById('step3Label');
    if (s3l) s3l.textContent = showGeneralEval ? 'Evaluation' : 'Facilitation';

    // stepInd1 → n=2, stepInd2 → n=3, stepInd3 → n=4
    [1, 2, 3].forEach(i => {
      const ind = document.getElementById('stepInd' + i);
      const num = document.getElementById('stepNum' + i);
      if (!ind || !num) return;
      const thisN = i + 1;
      if      (thisN < n)  { ind.className = 'step-item done';   num.innerHTML = '<i class="bi bi-check2"></i>'; }
      else if (thisN === n){ ind.className = 'step-item active'; num.textContent = i; }
      else                 { ind.className = 'step-item';        num.textContent = i; }
      const dv = document.getElementById('stepDiv' + i);
      if (dv) dv.classList.toggle('done', thisN < n);
    });

    // Step visibility
    document.getElementById('stepLoading').style.display = 'none';
    document.getElementById('infoBlock').style.display   = 'none';
    [2, 3, 4].forEach(i => {
      const el = document.getElementById('step' + i);
      if (el) el.classList.toggle('active', i === n);
    });
    document.getElementById('stepBar').style.display      = (n >= 2 && n <= 4) ? '' : 'none';
    document.getElementById('wizardFooter').style.display = (n >= 2 && n < 4)  ? '' : 'none';
    const btnBack   = document.getElementById('btnBack');
    const btnNext   = document.getElementById('btnNext');
    const btnSubmit = document.getElementById('btnSubmit');
    if (btnBack)   btnBack.style.display   = (n === 3) ? '' : 'none';   // Back only on Evaluation step
    if (btnNext)   btnNext.style.display   = (n === 2) ? '' : 'none';   // Next only on Facilitators step
    if (btnSubmit) btnSubmit.style.display = (n === 3) ? '' : 'none';
  }

  function showInfoBlock(type, data) {
    document.getElementById('stepLoading').style.display = 'none';
    document.getElementById('stepBar').style.display      = 'none';
    document.getElementById('wizardFooter').style.display = 'none';
    [1,2,3,4].forEach(i => { const el = document.getElementById('step' + i); if (el) el.classList.remove('active'); });
    const el = document.getElementById('infoBlock');
    el.style.display = '';
    if (type === 'cert_claimed') {
      el.innerHTML = `<div class="info-block"><div class="info-icon" style="color:#dc2626;"><i class="bi bi-patch-check-fill"></i></div>
        <h5 style="font-weight:700;color:#1e3a5f;">Certificate Already Claimed</h5>
        <p style="font-size:.84rem;color:#555;margin:.35rem 0 .65rem;">This certificate${data.cert_number ? ` (<strong>${esc(data.cert_number)}</strong>)` : ''} has already been downloaded.</p>
        <p style="font-size:.74rem;color:#94a3b8;">Each certificate may only be claimed once for security purposes.</p></div>`;
    } else if (type === 'not_available') {
      el.innerHTML = `<div class="info-block"><div class="info-icon" style="color:#f59e0b;"><i class="bi bi-clock-history"></i></div>
        <h5 style="font-weight:700;color:#1e3a5f;">Evaluation Not Yet Available</h5>
        <p style="font-size:.84rem;color:#555;">${esc(data.message || 'Please return on the evaluation day.')}</p></div>`;
    } else {
      el.innerHTML = `<div class="info-block"><div class="info-icon" style="color:#dc2626;"><i class="bi bi-exclamation-circle-fill"></i></div>
        <h5 style="font-weight:700;color:#1e3a5f;">Error</h5>
        <p style="font-size:.84rem;color:#555;">${esc(data.message || 'An unexpected error occurred.')}</p></div>`;
    }
  }

  document.getElementById('btnBack').addEventListener('click', () => {
    if (currentStep === 3) setStep(2);
  });
  document.getElementById('btnNext').addEventListener('click', () => {
    if (currentStep === 2) setStep(3);
  });

  /* ── Init ── */
  async function init() {
    populateStrips();
    try {
      const data = await apiFetch(`api/eval_eligibility.php?reg_id=${REG_ID}&day=${DAY_NUM}&sig=${encodeURIComponent(SIG)}`);
      if (!data.success) throw new Error(data.error || 'Failed to load evaluation data.');

      if (data.cert_claimed) { showInfoBlock('cert_claimed', data); return; }

      // Fast path: cert generated but not yet downloaded → re-issue token
      if (data.cert_exists && !data.cert_claimed) {
        try {
          const r = await postJSON('api/submit_evaluation.php', { reg_id: REG_ID, day: DAY_NUM, sig: SIG, fast_path: true, eval_day: DAY_NUM });
          if (r.success && r.claim_token) {
            claimToken = r.claim_token; certNumber = r.cert_number;
            document.getElementById('step4Heading').textContent    = 'Certificate Ready for Download';
            document.getElementById('step4Msg').textContent        = 'Your evaluation has already been submitted and your certificate is ready.';
            document.getElementById('step4CertNote').style.display = 'none';
            prepStep4(); setStep(4); return;
          }
        } catch (_e) { /* fall through */ }
      }

      trainingEnded        = !!data.training_ended;
      currentEvalDay       = data.current_eval_day || null;
      evalAlreadySubmitted = !!data.eval_submitted;
      evalDayNums          = (data.eval_days || []).map(d => d.day_number);

      if (currentEvalDay === null && !trainingEnded) {
        showInfoBlock('not_available', { message: 'The evaluation for this training is not yet available. Please return on the scheduled evaluation day.' });
        return;
      }

      await fetchAndSetupEvalStep();
    } catch (e) {
      showInfoBlock('error', { message: e.message });
    }
  }

  /* ── Fetch SMEs and route ── */
  async function fetchAndSetupEvalStep() {
    isSubmitting = true;
    const btn = document.getElementById('btnNext');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading…'; }
    try {
      const effectiveEvalDay = DAY_NUM;
      const evalDayParam     = `&eval_day=${DAY_NUM}`;
      const smeData  = await apiFetch(`api/eval_get_smes.php?reg_id=${REG_ID}&day=${DAY_NUM}&sig=${encodeURIComponent(SIG)}${evalDayParam}`);
      currentSmes = smeData.smes || [];

      // General sections (Design, Materials, Admin, Confidence, Commitment, Open-Ended)
      // only appear when the training has ended AND this is the last eval day.
      const lastEvalDay  = evalDayNums.length > 0 ? Math.max(...evalDayNums) : null;
      showGeneralEval    = trainingEnded && (lastEvalDay === null || effectiveEvalDay === lastEvalDay);

      buildBarriers('confidence');
      buildBarriers('commitment');
      setupEvalIntro();

      if (currentSmes.length) {
        renderSmeSections(currentSmes);
        renderSmePreviewStep(currentSmes);
        setGeneralSectionsVisible(showGeneralEval);
        setStep(2);
      } else if (trainingEnded) {
        renderSmeSections([]);
        renderSmePreviewStep([]);
        setGeneralSectionsVisible(showGeneralEval);
        setStep(3);
      } else {
        showInfoBlock('not_available', { message: `The facilitator evaluation for Day ${DAY_NUM} is not yet available. Please try again on the scheduled evaluation day.` });
      }
    } catch (e) {
      Swal.fire({ icon: 'error', title: 'Error', text: e.message, confirmButtonColor: '#dc3545' });
    } finally {
      isSubmitting = false;
      if (btn) { btn.disabled = false; btn.innerHTML = 'Continue <i class="bi bi-arrow-right"></i>'; }
    }
  }

  function setGeneralSectionsVisible(v) {
    document.getElementById('generalEvalSections').style.display = v ? '' : 'none';
  }

  function setupEvalIntro() {
    const note = document.getElementById('evalContextNote');
    if (note) note.innerHTML = showGeneralEval ? '' : `<div style="background:#fef9c3;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:.65rem .9rem;margin-bottom:.85rem;font-size:.76rem;color:#78350f;"><i class="bi bi-info-circle-fill me-2"></i><strong>Facilitation Evaluation Only.</strong> The training design, materials, and overall program evaluation will be available once the training has fully concluded.</div>`;
    const existing = document.getElementById('evalResubmitNotice');
    if (existing) existing.remove();
    if (evalAlreadySubmitted) {
      const banner = document.createElement('div');
      banner.id = 'evalResubmitNotice'; banner.className = 'resubmit-notice';
      banner.innerHTML = '<i class="bi bi-arrow-repeat" style="font-size:1rem;flex-shrink:0;margin-top:1px;"></i><span><strong>Re-submission:</strong> You have already submitted an evaluation for this training. Submitting again will replace your previous responses.</span>';
      const intro = document.getElementById('evalIntro3');
      if (intro) intro.parentElement.insertBefore(banner, intro);
    }
  }

  /* ── SME preview ── */
  function renderSmePreviewStep(smes) {
    const c = document.getElementById('smePreviewList');
    if (!smes.length) { c.innerHTML = ''; return; }
    c.innerHTML = smes.map(s => {
      let photoUrl = null;
      if (s.sme_type === 'internal' && s.profile_photo) photoUrl = `https://imis.cscro8.com/admin/uploads/${esc(s.profile_photo)}`;
      else if (s.sme_type === 'external' && s.profile_photo) photoUrl = `https://imis.cscro8.com/assets/uploads/sme_photos/${esc(s.profile_photo)}`;
      const avatar = photoUrl
        ? `<img src="${photoUrl}" alt="${esc(s.sme_name)}" style="width:2.3rem;height:2.3rem;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #93c5fd;" onerror="this.replaceWith(Object.assign(document.createElement('div'),{textContent:'${esc((s.sme_name||'?')[0].toUpperCase())}',style:'width:2.3rem;height:2.3rem;border-radius:50%;background:var(--ev-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex-shrink:0'}));">`
        : `<div style="width:2.3rem;height:2.3rem;border-radius:50%;background:var(--ev-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex-shrink:0;">${esc((s.sme_name||'?')[0].toUpperCase())}</div>`;
      return `<div style="display:flex;align-items:center;gap:.65rem;padding:.52rem .82rem;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:.38rem;background:#f8fafc;">${avatar}<div style="flex:1;min-width:0;"><div style="font-weight:700;font-size:.8rem;color:#1e293b;">${esc(s.sme_name)}</div>${s.designation ? `<div style="font-size:.68rem;color:#64748b;">${esc(s.designation)}</div>` : ''}<div style="font-size:.66rem;color:#0f6cbf;margin-top:.08rem;"><i class="bi bi-calendar3 me-1"></i>${esc(formatDaysLabel(s.days || []))}</div></div><i class="bi bi-person-check-fill" style="color:var(--ev-blue);font-size:1rem;"></i></div>`;
    }).join('');
  }

  function formatDaysLabel(days) {
    if (!days || !days.length) return 'All Days';
    const s = [...days].sort((a,b) => a - b);
    return s.length === 1 ? 'Day ' + s[0] : 'Days ' + s.join(', ');
  }

  /* ── SME eval sections ── */
  function renderSmeSections(smes) {
    const c = document.getElementById('smeSections3');
    if (!smes.length) { c.innerHTML = ''; return; }
    const qs = [
      'My knowledge about the topic has improved because of the expertise of the SME.',
      'The SME encouraged my interaction and participation.',
      'The style of the SME kept me engaged throughout the session.',
      'The SME discussed the topics based on my learning pace.',
    ];
    c.innerHTML = smes.map(sme => {
      let photoUrl = null;
      if (sme.sme_type === 'internal' && sme.profile_photo) photoUrl = `https://imis.cscro8.com/admin/uploads/${esc(sme.profile_photo)}`;
      else if (sme.sme_type === 'external' && sme.profile_photo) photoUrl = `https://imis.cscro8.com/assets/uploads/sme_photos/${esc(sme.profile_photo)}`;
      const avatar = photoUrl
        ? `<img src="${photoUrl}" alt="${esc(sme.sme_name)}" style="width:1.8rem;height:1.8rem;border-radius:50%;object-fit:cover;border:2px solid #93c5fd;flex-shrink:0;">`
        : `<i class="bi bi-person-video3" style="color:var(--ev-blue)"></i>`;
      return `<div class="eval-section"><div class="eval-section-title">${avatar} ${showGeneralEval ? 'III.' : 'I.'} FACILITATION — ${esc(sme.sme_name)}${sme.designation ? ` <span style="font-weight:400;font-size:.66rem;color:#6c757d;">(${esc(sme.designation)})</span>` : ''}</div>
      <table class="likert-grid"><thead><tr><th>Statement</th><th>1<br><small class="likert-scale-lbl">Strongly<br>Disagree</small></th><th>2<br><small class="likert-scale-lbl">Disagree</small></th><th>3<br><small class="likert-scale-lbl">Neither</small></th><th>4<br><small class="likert-scale-lbl">Agree</small></th><th>5<br><small class="likert-scale-lbl">Strongly<br>Agree</small></th></tr></thead>
      <tbody>${qs.map((q, qi) => `<tr><td>${esc(q)}</td>${[1,2,3,4,5].map(v => `<td><input type="radio" class="likert-radio" name="sme_${sme.id}_q${qi+1}" value="${v}" required></td>`).join('')}</tr>`).join('')}</tbody></table>
      <div style="margin-top:.8rem;"><label class="eval-label" for="smeComment_${sme.id}">Comment on Facilitation of ${esc(sme.sme_name)}: <span class="text-danger">*</span></label>
      <textarea class="eval-textarea" id="smeComment_${sme.id}" rows="2" placeholder="Share your comments on this facilitator…"></textarea></div></div>`;
    }).join('');
  }

  /* ── Barrier logic ── */
  function buildBarriers(type) {
    const tpl = document.getElementById('barrierTpl').innerHTML.replace(/__ID__/g, type);
    const el  = document.getElementById(type + 'Barriers');
    if (el) { el.innerHTML = tpl; el.querySelectorAll(`[name="${type}_reasons"]`).forEach(cb => cb.disabled = true); }
  }
  function handleRatingChange(type, value) {
    const high = value >= 4;
    document.querySelectorAll(`[name="${type}_reasons"]`).forEach(cb => {
      if (cb.value === 'rated_high') { cb.disabled = !high; cb.checked = high; }
      else { cb.disabled = high; cb.checked = false; }
    });
  }
  document.addEventListener('change', e => {
    if (e.target.name === 'confidence_rating') handleRatingChange('confidence', +e.target.value);
    if (e.target.name === 'commitment_rating')  handleRatingChange('commitment',  +e.target.value);
  });

  /* ── Submit ── */
  document.getElementById('btnSubmit').addEventListener('click', async function () {
    if (isSubmitting) return;

    if (showGeneralEval) {
      for (const sec of [{ p:'design', n:'Design' }, { p:'materials', n:'Materials' }, { p:'admin', n:'Administration' }]) {
        for (let q = 1; q <= 4; q++) {
          if (!document.querySelector(`[name="${sec.p}_q${q}"]:checked`)) {
            Swal.fire({ icon:'warning', title:'Incomplete', text:`Please rate all questions in the ${sec.n} section.`, confirmButtonColor:'#0f6cbf' }); return;
          }
        }
      }
      if (!document.querySelector('[name="confidence_rating"]:checked')) { Swal.fire({ icon:'warning', title:'Incomplete', text:'Please rate your confidence level.', confirmButtonColor:'#0f6cbf' }); return; }
      if (!document.querySelector('[name="commitment_rating"]:checked'))  { Swal.fire({ icon:'warning', title:'Incomplete', text:'Please rate your commitment level.',  confirmButtonColor:'#0f6cbf' }); return; }
    }

    for (const sme of currentSmes) {
      for (let q = 1; q <= 4; q++) {
        if (!document.querySelector(`[name="sme_${sme.id}_q${q}"]:checked`)) {
          Swal.fire({ icon:'warning', title:'Incomplete', text:`Please rate all questions for ${sme.sme_name}.`, confirmButtonColor:'#0f6cbf' }); return;
        }
      }
    }

    let confRating = 0, commRating = 0;
    if (showGeneralEval) {
      confRating = +document.querySelector('[name="confidence_rating"]:checked').value;
      commRating = +document.querySelector('[name="commitment_rating"]:checked').value;
      if (confRating <= 3 && ![...document.querySelectorAll('[name="confidence_reasons"]:checked')].filter(c => c.value !== 'rated_high').length) {
        Swal.fire({ icon:'warning', title:'Confidence Barriers', text:'Please indicate at least one reason for your confidence rating.', confirmButtonColor:'#0f6cbf' }); return;
      }
      if (commRating <= 3 && ![...document.querySelectorAll('[name="commitment_reasons"]:checked')].filter(c => c.value !== 'rated_high').length) {
        Swal.fire({ icon:'warning', title:'Commitment Barriers', text:'Please indicate at least one reason for your commitment rating.', confirmButtonColor:'#0f6cbf' }); return;
      }
      for (const f of [{id:'designComment',label:'Comment on Design'},{id:'materialsComment',label:'Comment on Training Materials'},{id:'adminComment',label:'Comment on Administration'}]) {
        if (!document.getElementById(f.id).value.trim()) { Swal.fire({ icon:'warning', title:'Required Field', text:`"${f.label}" is required.`, confirmButtonColor:'#0f6cbf' }); document.getElementById(f.id).focus(); return; }
      }
      for (const f of [{id:'likedMost',label:'What parts of the session did you like the most?'},{id:'improvements',label:'What needs to be improved?'},{id:'recommendations',label:'Other suggestions / recommendations'},{id:'futureTopics',label:'Future training topics / skills'}]) {
        if (!document.getElementById(f.id).value.trim()) { Swal.fire({ icon:'warning', title:'Required Field', text:`Please answer: "${f.label}"`, confirmButtonColor:'#0f6cbf' }); document.getElementById(f.id).focus(); return; }
      }
    }

    for (const sme of currentSmes) {
      const el = document.getElementById(`smeComment_${sme.id}`);
      if (!el || !el.value.trim()) { Swal.fire({ icon:'warning', title:'Required Field', text:`Comment on facilitation of "${sme.sme_name}" is required.`, confirmButtonColor:'#0f6cbf' }); if (el) el.focus(); return; }
    }

    const payload = {
      reg_id: REG_ID, day: DAY_NUM, sig: SIG,
      eval_day:  DAY_NUM,
      sme_only:  !showGeneralEval,
      sme_ratings: currentSmes.map(sme => ({
        sme_id: sme.id,
        q1: +document.querySelector(`[name="sme_${sme.id}_q1"]:checked`)?.value,
        q2: +document.querySelector(`[name="sme_${sme.id}_q2"]:checked`)?.value,
        q3: +document.querySelector(`[name="sme_${sme.id}_q3"]:checked`)?.value,
        q4: +document.querySelector(`[name="sme_${sme.id}_q4"]:checked`)?.value,
        comments: document.getElementById(`smeComment_${sme.id}`)?.value || '',
      })),
      ...(showGeneralEval ? {
        design_q1: +document.querySelector('[name="design_q1"]:checked').value,
        design_q2: +document.querySelector('[name="design_q2"]:checked').value,
        design_q3: +document.querySelector('[name="design_q3"]:checked').value,
        design_q4: +document.querySelector('[name="design_q4"]:checked').value,
        design_comment:    document.getElementById('designComment').value,
        materials_q1: +document.querySelector('[name="materials_q1"]:checked').value,
        materials_q2: +document.querySelector('[name="materials_q2"]:checked').value,
        materials_q3: +document.querySelector('[name="materials_q3"]:checked').value,
        materials_q4: +document.querySelector('[name="materials_q4"]:checked').value,
        materials_comment: document.getElementById('materialsComment').value,
        admin_q1: +document.querySelector('[name="admin_q1"]:checked').value,
        admin_q2: +document.querySelector('[name="admin_q2"]:checked').value,
        admin_q3: +document.querySelector('[name="admin_q3"]:checked').value,
        admin_q4: +document.querySelector('[name="admin_q4"]:checked').value,
        admin_comment:     document.getElementById('adminComment').value,
        confidence_rating:  confRating,
        confidence_reasons: [...document.querySelectorAll('[name="confidence_reasons"]:checked')].map(c => c.value),
        commitment_rating:  commRating,
        commitment_reasons: [...document.querySelectorAll('[name="commitment_reasons"]:checked')].map(c => c.value),
        liked_most:     document.getElementById('likedMost').value,
        improvements:   document.getElementById('improvements').value,
        recommendations:document.getElementById('recommendations').value,
        future_topics:  document.getElementById('futureTopics').value,
      } : {}),
    };

    isSubmitting = true;
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting…';
    Swal.fire({ title:'Submitting…', html:'<small>Saving your forms. Please wait.</small>', allowOutsideClick:false, didOpen:() => Swal.showLoading() });

    try {
      const res = await postJSON('api/submit_evaluation.php', payload);
      if (!res.success) throw new Error(res.error || 'Submission failed.');
      Swal.close();

      if (res.cert_pending) {
        const remaining = (res.remaining_days || []).map(d => `Day ${d}`).join(', ');
        await Swal.fire({
          icon: 'success', title: 'Evaluation Submitted',
          html: `<p style="font-size:.9rem;">${esc(res.message || 'Evaluation submitted.')}</p>` + (remaining ? `<p style="font-size:.82rem;color:#64748b;">Remaining: <strong>${esc(remaining)}</strong></p>` : ''),
          confirmButtonColor: '#0f6cbf', confirmButtonText: 'Got it',
        });
        document.getElementById('step4Heading').textContent    = 'Evaluation Submitted';
        document.getElementById('step4Msg').textContent        = res.message || 'Evaluation submitted. Please return to complete the remaining evaluation day(s).';
        document.getElementById('step4CertNote').style.display = remaining ? '' : 'none';
        if (remaining) document.getElementById('step4CertNote').innerHTML = `<i class="bi bi-info-circle-fill" style="font-size:1rem;flex-shrink:0;margin-top:1px;"></i><span><strong>Remaining Days:</strong> ${esc(remaining)}. Please return on the next evaluation day to complete your submission and receive your certificate.</span>`;
        document.getElementById('refNumberDisplay').textContent = res.reference ? 'Reference: ' + res.reference : '';
        setStep(4); return;
      }

      refNumber  = res.reference;
      claimToken = res.claim_token;
      certNumber = res.cert_number;
      document.getElementById('step4Heading').textContent    = res.is_update ? 'Submission Updated!' : 'Submission Received!';
      document.getElementById('step4Msg').textContent        = 'Thank you for completing your evaluation. Your feedback has been recorded.';
      document.getElementById('refNumberDisplay').textContent = refNumber ? 'Reference: ' + refNumber : '';
      if (claimToken) prepStep4();
      else document.getElementById('step4CertNote').style.display = '';
      setStep(4);
    } catch (e) {
      Swal.fire({ icon:'error', title:'Submission Failed', text:e.message, confirmButtonColor:'#dc3545' });
    } finally {
      isSubmitting = false; btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill"></i> Submit Evaluation';
    }
  });

  /* ── Certificate ready ── */
  function prepStep4() {
    document.getElementById('step4ConfirmBox').style.display = 'none';
    const box = document.getElementById('certReadyBox');
    box.style.display = '';
    document.getElementById('certNumberDisplay').textContent = certNumber || '';
    const dlBtn  = document.getElementById('downloadBtn');
    const dlUsed = document.getElementById('downloadUsedMsg');
    dlBtn.href  = DOWNLOAD_BASE + '?token=' + encodeURIComponent(claimToken);
    dlBtn.style.pointerEvents = '';
    dlBtn.innerHTML = '<i class="bi bi-file-earmark-arrow-down-fill"></i> Download Certificate PDF';
    dlUsed.style.display = 'none';
    dlBtn.addEventListener('click', function onFirst() {
      setTimeout(() => { dlBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Downloaded'; dlBtn.style.pointerEvents = 'none'; dlUsed.style.display = ''; }, 1500);
      dlBtn.removeEventListener('click', onFirst);
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
</script>
<?php endif; ?>
</body>
</html>
