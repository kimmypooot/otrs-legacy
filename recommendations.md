OTRS / IMIS System Advisory — Prioritized Recommendations

---
1. Security (Critical / High)

1.1 CSRF Tokens Generated But Never Validated — Effort: Low

session.php generates $_SESSION['_csrf_token'], but no OTRS endpoint validates it. Every mutating POST endpoint (add_training.php, replace_participant.php, update_payment.php, etc.) is vulnerable to CSRF. validate_csrf_token() already exists in session.php — just call it.

1.2 backup.php Corrupts SQL Output on Special Characters — Effort: Low

Row values are concatenated raw with single quotes — any value with a ' breaks the SQL output. Also dumps legacy table names (participants, trainings) that no longer exist in the live schema.

1.3 update_payment.php Has No Session or Role Check — Effort: Low

Calls require_once '../connect.php' with no $_SESSION['username'] check. Any authenticated IMIS user (even a field office account) can POST to it and alter payment status or OR numbers.

1.4 Several Legacy HRD APIs Skip bootstrap.php Entirely — Effort: Low

add_training.php, update_training.php, add_participant.php use connect_v1.php with no session guard. A logged-out user with a REST client can add or modify training records.

1.5 cert_issue_ajax.php Internal Key Is a Literal Placeholder — Effort: Low

Line 10: === 'CHANGE_THIS_TO_A_RANDOM_SECRET' — if this endpoint is called non-interactively, anyone who discovers it can issue certificates for arbitrary participants.

---
2. Data Integrity & Business Logic

2.1 Slot Count Not Validated on Reactivation — Effort: Low

reactivate_participant.php uses GREATEST(slots - 1, 0) instead of checking WHERE slots > 0 — silently over-enrolls a full training.

2.2 Payment Status Has No Audit Trail — Effort: Low

Cancellations have otrs_cancellation_logs. Payments have nothing. No record of who changed a status, when, or what it was before. Needs an otrs_payment_logs table.

2.3 Duplicate Registration Check Is Email-Blind — Effort: Low

Duplicate check is (fname, mname, lname, training_id) only. Same person registering with a different name variation (or same email twice) gets through.

2.4 Replacement Workflow Has No HRD Approval Gate — Effort: Medium

Cancellations require HRD approval before taking effect. Replacements — which are higher-impact (they change who attends and who can receive a certificate) — execute immediately with no HRD review. A field office can replace a participant after training completion. Fix: add a status (pending/approved/rejected) column to otrs_replacement_requests and hold the swap until HRD approves.

2.5 Certificate email_status Not Reset on Re-Issue — Effort: Low

cert_issue_ajax.php regenerates the certificate and token but does not clear email_status or emailed_at. The HRD interface still shows the old "Sent" badge after re-issue, suggesting the new certificate was already emailed when it was not.

2.6 Participant::transferToTraining() Skips Slot Validation — Effort: Low

Transfers a participant without decrementing the destination training's slots or incrementing the source's — both slot counts become wrong.

---
3. Functional Gaps

3.1 No Email Notification on Cancellation Decision — Effort: Medium

Field office users must manually check cancellation_requests.php to find out if HRD approved or rejected. PHPMailer is already available — send a notification at the end of process_cancellation.php.

3.2 No Email to Participant When Cancellation Is Approved — Effort: Medium

Participant whose registration is cancelled receives nothing. If they paid (Promissory Note), they have no way of knowing their registration is gone.

3.3 Attendance/Submission Review Has No HRD UI — Effort: High

otrs_training_submissions is fully populated from the field office side, but there is no HRD page to view submissions, validate them day-by-day, or see an attendance matrix. The table is write-only. Prerequisite for making submission validation a precondition of certificate generation.

3.4 Evaluation Results Are Inaccessible — Effort: High

otrs_evaluations, otrs_evaluation_sme_ratings, and related tables exist with data, but no PHP page in hrd/ reads them. HRD cannot view aggregate training quality scores — data that is critical for CSC annual reporting.

3.5 No Annual Cross-Training Participation Report — Effort: Medium

No built-in way to answer "how many unique employees did we train in 2026?" or "which field office sent the most participants?" — required for CSC annual planning submissions.

3.6 Backup Button on HRD Dashboard Is a Mock — Effort: Low

handleBackup() in hrd/index.php shows a success dialog after a setTimeout(2000) regardless of outcome — it never calls the backup endpoint. HRD has false confidence their data is backed up.

---
4. UX / Workflow Friction

4.1 Training Status Must Be Changed Manually — Effort: Medium

No auto-status update when training dates pass. An "Ongoing Registration" training remains visible on field-office dashboards as open after it ends. Fix: lightweight PHP script called on HRD dashboard load (with filemtime() cache) + Hostinger cron.

4.2 No Cross-Training Pending Payments View — Effort: Low

HRD must open each training individually to find pending payments. The dashboard card links to a training selector, not a consolidated list. Fix: hrd/pending_payments.php — a single JOIN query showing all Pending registrations with no proof file.

4.3 Old Certificate PDF Deleted Before New One Is Written — Effort: Low

cert_issue_ajax.php calls @unlink($oldFilePath) before generating the new PDF. If generation fails, the old certificate is permanently gone and the record points to a missing file. Fix: write to a temp file first, confirm it exists and is non-zero, then delete the old and rename.

4.4 Bulk Email Has No Per-Recipient Delivery Tracking — Effort: Low

send_email-ack.php and siblings return "Emails sent successfully." regardless of individual send failures. cert_email_ajax.php already does per-recipient result tracking — mirror that pattern.

---
5. Technical Debt

5.1 Three Active DB Credential Files in OTRS — Effort: Medium

otrs/connect.php, hrd/connect.php, hrd/connect_v1.php — all actively required by different files. Credential rotation is a multi-file operation. Standardize all endpoints on bootstrap.php.

5.2 PHPMailer Duplicated in Two Locations — Effort: Low

Both imis/phpmailer/ and imis/otrs/hrd/phpmailer/ exist. Security patches applied to one are missed by the other. Canonicalize all send files to the shared copy.

5.3 Participant::delete() Orphans Registration Rows — Effort: Low

DELETE FROM otrs_participants WHERE id = :id with FK ON DELETE NO ACTION leaves orphaned otrs_registrations rows and does not restore training slots.

5.4 dl_participant_output.php Has No Auth Check — Effort: Low

No session guard — any unauthenticated user who knows a registration ID can download submission files and certificate PDFs.

---
6. Reporting & Visibility

6.1 HRD Replacement Requests Table Has No Filters — Effort: Low

No filter by training, field office, or date range. fetch_replacement_requests.php returns all rows unconditionally.

6.2 No Admin View of Certificate Verification Audit — Effort: Low

otrs_claim_tokens tracks used_at, used_ip, used_ua — but no HRD page surfaces this data. If a certificate's authenticity is disputed, there's no way to quickly pull verification history.

6.3 No Per-Office Participation Summary on Field Office Dashboard — Effort: Medium

Field office users see a training list but no summary of "how many of our people are Paid / Pending / Cancelled across all active trainings." Needs a 3-card summary widget on index.php.

---
Priority Summary

┌──────────┬────────────────────────────────────────────────┬────────────┐
│ Priority │                      Item                      │   Effort   │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Critical │ update_payment.php — no auth/role check        │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Critical │ Legacy HRD APIs — no session check             │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Critical │ CSRF tokens never validated                    │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Critical │ Backup button is a mock (never fires)          │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ High     │ Certificate email_status not reset on re-issue │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ High     │ Old PDF deleted before new one confirmed       │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ High     │ Backup dumps legacy table names (wrong data)   │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ High     │ Internal cert key is a literal placeholder     │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ High     │ Replacement workflow lacks HRD approval gate   │ Medium     │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ High     │ Payment status has no audit log                │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Medium   │ Slot enforcement gap on reactivation           │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Medium   │ No cancellation decision email notification    │ Medium     │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Medium   │ Participant::delete() orphans registrations    │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Medium   │ dl_participant_output.php — no auth            │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Medium   │ Three credential files, mixed strategies       │ Medium     │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Medium   │ Training status requires manual update         │ Medium     │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Lower    │ Attendance submission review UI                │ High       │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Lower    │ Evaluation results UI                          │ High       │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Lower    │ Annual participation report                    │ Medium     │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Lower    │ Bulk email delivery tracking                   │ Low        │
├──────────┼────────────────────────────────────────────────┼────────────┤
│ Lower    │ Reporting/filter improvements                  │ Low–Medium │
└──────────┴────────────────────────────────────────────────┴────────────┘

---
Would you like me to start implementing any of these? The critical security fixes (auth checks, CSRF) are all low-effort and high-impact — good candidates to tackle first.