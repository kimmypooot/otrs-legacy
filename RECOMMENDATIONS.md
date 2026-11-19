# OTRS — Improvement Recommendations

> **Purpose:** Review list of actionable improvements for the Online Training Registration System.  
> **Scope:** All active pages — HRD admin area (`hrd/`) and Field Office views (`otrs/`).  
> **Format per item:** Description → Why it matters → Suggested approach.  
> Priority levels: 🔴 High · 🟡 Medium · 🟢 Low  
> Effort: S = half-day, M = 1–2 days, L = 3–5 days

---

## 1. HRD Dashboard (`hrd/index.php`)

### 1.1 🔴 Clickable stat cards that drill down to filtered data (S)
Currently the six stat cards (Total Trainings, Ongoing Registrations, etc.) are static numbers. Clicking them leads nowhere.  
**Why:** HRD staff check these numbers to act on them — they should be one click away from the relevant list.  
**Approach:** Make each card a link to the relevant page with a pre-applied filter (e.g., Pending Cancellations card → `cancellation_requests.php?status=pending`; Pending Payments card → a filtered participant list or proof-payment view).

### 1.2 🔴 "Upcoming This Week" training widget (S)
The dashboard has no overview of trainings happening in the next 7 days.  
**Why:** HRD needs a daily at-a-glance of imminent trainings without navigating to the full list.  
**Approach:** Add a compact table/card below the stat row that queries `otrs_trainings WHERE training_status = 'upcoming' AND start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)`.

### 1.3 🟡 Trend indicators on stat cards (S)
No comparison to the previous month or year is shown.  
**Why:** Context (up/down vs. last month) helps prioritise without building a full BI tool.  
**Approach:** Add a small `+N vs. last month` sub-label using a second COUNT with a date filter on each existing query.

### 1.4 🟡 Recent activity feed (M)
No activity log is visible on the dashboard.  
**Why:** HRD supervisors benefit from seeing the last N actions (participant added, cancellation approved, certificate issued) in one place.  
**Approach:** Add a sidebar card pulling the last 15 rows from `otrs_cancellation_logs` and `otrs_replacement_requests`, ordered by timestamp DESC.

### 1.5 🟢 Replace temp-file auto-complete lock with DB-based lock (M)
`sys_get_temp_dir() . '/otrs_auto_complete.lock'` is wiped when the server clears `/tmp`, causing the UPDATE to run on every page load until the lock is re-created.  
**Why:** On Hostinger shared hosting, `/tmp` is cleared periodically. A failed lock means the UPDATE fires on every request during that window.  
**Approach:** Store the last-run timestamp in a small `otrs_settings` table (`key`, `value`, `updated_at`) and check/update it instead. This also makes the interval configurable without a code deploy.

---

## 2. HRD Participants Page (`hrd/participants.php`)

### 2.1 🔴 Bulk payment status update (M)
HRD can only change payment status one participant at a time.  
**Why:** After a training ends, HRD often needs to mark 10–30 participants as "Paid" once the OR batch arrives from the collecting officer. Doing it one by one is time-consuming.  
**Approach:** Add a checkbox column, a "Select All" toggle, and a "Bulk Update Status" button that opens a small modal asking for the new status + optional OR number / collecting officer. Back-end: a new `hrd/api/bulk_update_payment.php` endpoint with per-row PDO binding inside a transaction.

### 2.2 🔴 Duplicate participant detection on add (S)
No check exists for whether a participant is already registered in the same training.  
**Why:** Double-registration happens when a replacement is added without removing the original, or when a participant registers from two field offices.  
**Approach:** On `add_participant.php` (API), before INSERT check `SELECT 1 FROM otrs_registrations WHERE participant_id = :pid AND training_id = :tid`. If found, return `status: error` with a clear message. On the front-end, surface this before the form submits by checking email/full-name match.

### 2.3 🔴 Payment status change requires a reason field (S)
When HRD changes a participant from `pending` to `cancelled` or `not qualified`, no reason is recorded.  
**Why:** The `otrs_registrations` table has a `remarks` column but the modal does not always surface it, and there is no separate audit entry for the change.  
**Approach:** When the new status is `cancelled` or `not qualified`, make the Remarks field required in the edit modal. Log the change (old status → new status + actor + reason) into a new `otrs_registration_logs` table or reuse `otrs_cancellation_logs` with a `log_type` discriminator.

### 2.4 🟡 Promissory note expiration tracking (M)
Participants with `payment_status = 'promissory note'` have no deadline tracked anywhere.  
**Why:** Promissory notes are a temporary arrangement. Without an expiry date, HRD has no mechanism to follow up or auto-escalate.  
**Approach:**  
1. Add a `promissory_due_date DATE` column to `otrs_registrations`.  
2. In the payment modal, show a "Due Date" picker that appears when "Promissory Note" is selected.  
3. Add a dashboard stat card (or highlight in the participant list) for registrations whose `promissory_due_date < CURDATE()`.  
4. Optionally, auto-send a reminder email N days before the due date via the existing PHPMailer setup.

### 2.5 🟡 Inline slot count / overbooking warning (S)
The participants page does not warn HRD when the number of confirmed participants exceeds the training's `slots` value.  
**Why:** Overbooking causes logistical problems (venue, materials).  
**Approach:** On page load, compare `COUNT(*) WHERE registration_status = 'Confirmed'` against `otrs_trainings.slots` and show a dismissible alert banner if exceeded.

### 2.6 🟢 Export participants to PDF (named list / attendance sheet) (M)
Currently only Excel export exists.  
**Why:** HRD prints attendance sheets and master participant lists for the training venue. PDF is the required format for official documents.  
**Approach:** Add a "Print / PDF" button that triggers a `hrd/api/export_participants_pdf.php` endpoint using the existing TCPDF library. Template: simple table with name, position, agency, FO, payment status, signature column.

---

## 3. Certificate Generation (`hrd/generate_certificates.php`)

### 3.1 🔴 "Send All Unsent" batch email button (S)
Currently, HRD must click the email button for each certificate individually.  
**Why:** After a training with 50+ participants, sending certificates one at a time takes an impractical amount of time.  
**Approach:** Add a "Send All Unsent Certificates" button that calls `cert_email_ajax.php` sequentially via a JS loop with progress feedback, or better, enqueue the job and process it server-side (using the existing `otrs_cert_email_jobs` table).

### 3.2 🔴 Use the `otrs_cert_email_jobs` queue properly (L)
The `otrs_cert_email_jobs` table exists for batch certificate email jobs but it is not clear it is being used. Sending emails synchronously in a loop will hit Hostinger's SMTP rate limits and execution time limits.  
**Why:** PHPMailer + SMTP on shared hosting has a hard ceiling on emails per minute. A queue + cron-based sender is the safe pattern.  
**Approach:**  
1. "Send All Unsent" inserts one row per recipient into `otrs_cert_email_jobs` with `status = 'pending'`.  
2. A cron job (`cron/send_cert_emails.php`) runs every 5 minutes, picks up pending jobs in batches of 10, sends, marks `done`.  
3. The UI polls `cert_email_status.php` every 10 seconds and updates the table rows.

### 3.3 🟡 Certificate preview (thumbnail) before email (M)
HRD cannot preview a generated certificate before deciding to send it.  
**Why:** If the template, name, or certificate number is wrong, sending an email is not easily reversible.  
**Approach:** Add a "Preview" icon per row that opens an `<iframe>` or a new tab pointing to a `view_certificate.php?id=X` endpoint that serves the existing PDF file inline (using `Content-Disposition: inline`).

### 3.4 🟡 Revoke certificate UI (S)
`otrs_certificates.status` supports `revoked` but there is no UI to trigger it.  
**Why:** If a certificate was issued in error (wrong participant, duplicate) HRD needs a way to invalidate it without deleting the audit record.  
**Approach:** Add a "Revoke" action (with confirmation) that sets `status = 'revoked'` and records the actor + reason. The certificate verification page should display "REVOKED" prominently when this status is set.

### 3.5 🟢 Download all certificates as ZIP (M)
No batch download exists.  
**Why:** HRD sometimes needs to send certificates via email as attachments outside the system, or archive them.  
**Approach:** A "Download All as ZIP" button that calls a PHP endpoint which reads the `certificate_file` paths from `otrs_certificates`, zips them using PHP's `ZipArchive`, and streams the result.

---

## 4. Cancellation Requests

### 4.1 🔴 Email notification to FO when request is processed (S)
When HRD approves or rejects a cancellation request, no automatic notification is sent to the field office.  
**Why:** FO staff have no way of knowing the outcome except by logging in and checking the status. Time-sensitive cancellations (payment return, slot reallocation) are delayed.  
**Approach:** In `hrd/api/process_cancellation.php`, after updating the status, send an email using PHPMailer to the FO user's registered email (join with `imis_users` or `otrs_participants`) with the outcome and HRD's remarks.

### 4.2 🔴 Allow FO to withdraw a pending cancellation request (S)
Once submitted, FO has no way to cancel a pending request if it was submitted by mistake.  
**Why:** Accidental submissions are common and currently require a phone call to HRD to reject.  
**Approach:** Add a "Withdraw" button on the FO `cancellation_requests.php` visible only for `status = 'pending'` rows. The action calls `api/field-office/withdraw_cancellation.php` which sets status to `withdrawn` (add this value to the enum) and logs it. HRD's view should filter out `withdrawn` from the Pending tab.

### 4.3 🟡 HRD bulk approve / reject (S)
HRD can only process one request at a time.  
**Why:** After a training is cancelled entirely, HRD may need to approve a batch of cancellation requests at once.  
**Approach:** Add checkboxes and a "Bulk Action" dropdown (Approve / Reject with a shared remarks field) to the HRD cancellation requests table.

### 4.4 🟡 FO — filter cancellation requests by training (S)
The FO view shows all their cancellation requests across all trainings with no filter.  
**Why:** FOs managing many active trainings have difficulty locating relevant requests.  
**Approach:** Add a training name filter (dropdown or search) above the DataTable.

---

## 5. Proof of Payment (`proof_payment.php`)

### 5.1 🔴 Show HRD review outcome to FO (S)
When HRD approves or rejects a proof-of-payment, the FO user sees the payment status change but gets no explanation if rejected.  
**Why:** A rejected proof means the FO must re-upload, but they don't know why the first file was rejected.  
**Approach:** Store the reviewer's remarks in `otrs_registrations.remarks` (already exists) or a dedicated `proof_review_remarks` column. Display this on the FO proof-payment row with a rejection notice badge.

### 5.2 🔴 File type and size validation (client + server) (S)
No visible client-side or server-side validation of the uploaded proof file type or size.  
**Why:** Users may accidentally upload the wrong file type (`.docx`, `.xlsx`) or a file that is too large, causing a silent failure.  
**Approach:**  
- Client-side: `accept="image/jpeg,image/png,application/pdf"` on the `<input type="file">` + a JS size check before submit.  
- Server-side in `update_proof_payment.php`: check `$_FILES['file']['type']` against a whitelist and reject files over a configured size limit (e.g., 5 MB).

### 5.3 🟡 HRD — "Approve All Pending" action (S)
HRD reviews proof-of-payment uploads one at a time.  
**Why:** When field offices upload proofs for a large batch (common on training day), reviewing each individually is slow.  
**Approach:** Checkbox + "Approve Selected" bulk action on the HRD proof-payment view, updating `payment_status = 'paid'` and setting OR/collecting officer details in a single transaction.

### 5.4 🟡 Proof review status visible from participant list (S)
The main participant list shows the payment status but not whether a proof file has been uploaded and is awaiting review.  
**Why:** HRD and FO staff have to navigate to the separate proof-payment page to check this.  
**Approach:** Add a small icon/badge in the payment status column of the participant table when `proof_file IS NOT NULL AND payment_status NOT IN ('paid','free')`. Clicking it links directly to the proof-payment page filtered to that participant.

---

## 6. Submission Review (`hrd/submission_review.php`)

### 6.1 🔴 Email notification to participant/FO on validation outcome (S)
When HRD validates or rejects a day's submission, no notification goes out.  
**Why:** Participants (via their FO) need to know whether to re-submit or if they are cleared.  
**Approach:** After `validate_submission.php` updates the status, send an email to the participant's registered email (from `otrs_participants.email`) with the outcome, the day number, the training name, and HRD's remarks.

### 6.2 🟡 Missing submission count / highlight (S)
The grid shows empty cells for days where a participant has not submitted, but there is no aggregate "X participants have not submitted Day N" visible.  
**Why:** HRD has to scan the full grid to find missing submissions, which is impractical for large trainings.  
**Approach:** Add a row above the participant table (or a summary badge per day column header) showing the count of missing submissions for that day.

### 6.3 🟡 Bulk approve all pending submissions for a day (S)
HRD approves each submission individually.  
**Why:** When all participants submitted on time and quality is uniform, individual approval is repetitive.  
**Approach:** A "Approve All for Day N" button per day column that bulk-updates `validation_status = 'approved'` for all `pending` submissions on that day.

### 6.4 🟢 Download all submissions for a day as ZIP (M)
HRD can download individual files but not all submissions for a training day.  
**Why:** HRD keeps offline archives of participant outputs.  
**Approach:** A "Download Day N" button per column that streams a ZIP via `ZipArchive` of all `file_path` values for that `day_number`.

---

## 7. Evaluation Results (`hrd/evaluation_results.php`)

### 7.1 🔴 Export evaluation results to Excel / PDF (M)
No export exists on the evaluation results page.  
**Why:** Evaluation summaries are included in HRD's official post-training reports submitted to CSC Central Office.  
**Approach:** Add an "Export to Excel" button (using the existing DataTables Buttons + JSZip vendor files already loaded elsewhere) and a "Print / PDF" button using TCPDF.

### 7.2 🟡 Training selector shows only trainings with evaluations (S)
The training dropdown is populated with all active trainings regardless of whether any evaluations exist. While `eval_count` is fetched via JOIN, the dropdown doesn't visually separate those with/without responses.  
**Why:** HRD wastes time selecting a training only to see an empty results page.  
**Approach:** In the dropdown, append the evaluation count next to each training name (e.g., `Basic Writing Skills — 14 responses`) and move trainings with `eval_count = 0` to a disabled optgroup or hide them altogether.

### 7.3 🟡 Response rate indicator (S)
The page shows aggregate scores but not what percentage of registered participants actually submitted an evaluation.  
**Why:** A 4.8/5 average from 3 out of 40 participants is misleading.  
**Approach:** Show `N responses out of M registered participants (X%)` prominently at the top of the results section. Query `COUNT(DISTINCT r.participant_id) WHERE registration_status != 'cancelled'` for the denominator.

### 7.4 🟢 Cross-training trend comparison (L)
No way to compare evaluation scores across multiple trainings.  
**Why:** HRD tracks trainer/SME performance over time and needs to see trends.  
**Approach:** Add an "Overview" tab on the evaluation page (or a new `evaluation_overview.php`) that shows a line/bar chart (ApexCharts is already available) with average scores per training for the selected year.

---

## 8. Annual Report (`hrd/annual_report.php`)

### 8.1 🔴 Export to printable PDF / Excel (M)
The annual report currently appears to only display on-screen with no export button visible.  
**Why:** Annual reports are formal government documents that must be printed and signed.  
**Approach:** Add a "Export to PDF" button (TCPDF, formatted as a formal CSC report template) and an "Export to Excel" button (DataTables Buttons).

### 8.2 🟡 Month-by-month training completion chart (S)
The report shows year totals but no temporal distribution.  
**Why:** Seasonal patterns (Q4 heavy, Q1 light) inform planning for the following year.  
**Approach:** Add an ApexCharts bar/column chart breaking down `total registrations` and `trainings conducted` by month within the selected year.

### 8.3 🟡 Gender and age breakdown (S)
The page queries field office breakdown but demographic breakdowns (gender, age group) are only partially visible.  
**Why:** CSC reports routinely require demographic statistics for GFPS and HR planning.  
**Approach:** Add summary tables for:  
- Gender distribution (`SELECT gender, COUNT(*) FROM otrs_participants JOIN otrs_registrations ...`)  
- Age group (compute from `birthdate` or a dedicated `age` column if stored)  
- Position level / salary grade distribution

### 8.4 🟢 Per-training drill-down from the annual report (S)
The annual report shows aggregate data but no way to click through to a specific training's details.  
**Why:** If a stat looks unusual, HRD should be able to investigate immediately.  
**Approach:** Make training names in any breakdown table clickable, linking to `hrd/participants.php?id={training_id}`.

---

## 9. Field Office Dashboard (`index.php`)

### 9.1 🔴 Slots remaining visible in training list (S)
The training list does not show available slots.  
**Why:** FO staff need to know if there is still room before registering a new participant.  
**Approach:** Add a `Slots` column showing `{confirmed_count} / {total_slots}`. Color-code: green if seats remain, orange if ≤ 5 seats, red if full.

### 9.2 🔴 Quick-action button to submit a cancellation from the training row (S)
FOs must navigate to `cancellation_requests.php`, then find the participant. There is no shortcut from the training list.  
**Why:** Fewer clicks = faster response time for time-sensitive cancellations.  
**Approach:** Add a small "Request Cancellation" link/button in the training row's actions column that pre-fills the training ID and opens the submission form.

### 9.3 🟡 Pending proof-of-payment review notification badge (S)
The FO training list shows a "pending proof" count only on some views. When HRD rejects a proof, the FO has no notification.  
**Why:** FOs need a clear prompt to re-upload a rejected proof file.  
**Approach:** Add a badge/indicator on the training row (and at the top of the page) for registrations where `proof_file IS NOT NULL AND payment_status NOT IN ('paid','free')` and optionally where `proof_review_status = 'rejected'`.

### 9.4 🟡 Summary stats limited to active trainings only — add "this year" totals (S)
The FO summary card shows counts for non-completed active trainings. There is no historical view.  
**Why:** FO supervisors report on their staff's annual training participation.  
**Approach:** Add a secondary summary row (or a toggle) showing year-to-date totals (`YEAR(t.start_date) = YEAR(NOW())`).

### 9.5 🟢 Confirmation dialog before submitting any replacement or cancellation (S)
Replacement and cancellation requests are irreversible or difficult to undo. No confirmation step is visible.  
**Why:** Accidental submissions are a support burden on HRD.  
**Approach:** Add a SweetAlert2 confirmation dialog (already used in the project) before the AJAX call is sent.

---

## 10. Replacement Requests (`replacement_requests.php`, both FO and HRD)

### 10.1 🟡 Filter by training name (FO and HRD view) (S)
Both views list all replacements with no way to filter by training.  
**Why:** HRD and FOs managing multiple active trainings find it hard to locate relevant records.  
**Approach:** Add a `SELECT` training filter dropdown above the DataTable that re-fetches data via AJAX on change.

### 10.2 🟡 Email notification to FO when a replacement is recorded by HRD (S)
If HRD performs a replacement on behalf of a FO, the FO is not notified.  
**Why:** FO needs to update their own records and communicate the change to the participant.  
**Approach:** In `hrd/api/replace_participant.php`, after successful replacement, send an email to the originating FO (derive from `otrs_participants.location` → FO user's email) summarising the change.

---

## 11. Cross-Cutting / Architectural

### 11.1 🔴 Centralise the location code → label map (S)
The `$locationMap` / `$titleMap` / `$locationLabels` array is defined identically in at least 7 separate PHP files (`index.php`, `participants.php`, `proof_payment.php`, `hrd/participants.php`, `hrd/annual_report.php`, etc.).  
**Why:** Any time a new field office is added, all 7+ files must be updated. Missing one causes inconsistent labels.  
**Approach:** Define it once in `includes/otrs_config.php` (or add to an existing shared include) as a `const` or a `function get_location_map()`. All files `require_once` this and use the shared map.

### 11.2 🔴 Remove `unpaid_participants.php` — it duplicates `participants.php` (S)
`unpaid_participants.php` is `participants.php` with a default `filter=pending`. Both pages are maintained separately.  
**Why:** Any bug fix or feature added to one must be duplicated manually in the other.  
**Approach:** Delete `unpaid_participants.php`. Change any links pointing to it so they link to `participants.php?filter=pending` (or `payment_status=pending`). Confirm no other page links directly to it before deleting.

### 11.3 🟡 Consolidate `update_participant.php` and `update_participant_new.php` (S)
Two near-identical API endpoints exist in `api/field-office/`. The `_new` variant appears to be a migration leftover.  
**Why:** Maintaining two endpoints for the same operation creates confusion about which is authoritative.  
**Approach:** Audit both files to confirm they are functionally equivalent (or that `_new` is strictly better). If so, update all callers to use `update_participant.php` and delete `_new`. If they differ, merge the differences into the canonical file.

### 11.4 🟡 Clean up the `cert_issue_ajax_v1.php` legacy endpoint (S)
The `v1` file sits alongside the current `cert_issue_ajax.php`.  
**Why:** Legacy files in the live `api/` directory are a security risk (outdated code, possible bypass of new validations).  
**Approach:** Confirm no page calls `cert_issue_ajax_v1.php` (grep for it), then move it to `archive/` or delete it.

### 11.5 🟡 Add pagination / server-side DataTables for large participant lists (M)
Participant tables load all rows on page load. A training with 200+ participants loads the full result set.  
**Why:** On Hostinger shared hosting, large query results + DOM rendering will slow page load noticeably.  
**Approach:** Switch the participant DataTable to server-side processing. The relevant `fetch_participants_ajax.php` / `fetch_participants_db.php` endpoints already exist — connect them to DataTables' `serverSide: true` mode with `draw`, `start`, `length`, `search.value` parameters.

### 11.6 🟡 Unified in-app notification / alert banner (M)
Currently, the only cross-page notification mechanism is the login success toast on `hrd/index.php`. No persistent notification centre exists.  
**Why:** HRD needs to know when: a new cancellation request arrives, a proof-of-payment is uploaded, a new replacement is recorded. FOs need to know when: their cancellation was approved/rejected, their proof was reviewed.  
**Approach:**  
1. Add a `otrs_notifications` table (`user_id`, `type`, `reference_id`, `message`, `is_read`, `created_at`).  
2. Insert rows on key events (cancellation submitted/processed, proof uploaded/reviewed, etc.).  
3. Show a Bell icon with badge count in the top header that loads unread notifications via a polling `fetch()` every 60 seconds or on page load.

### 11.7 🟢 Consolidate duplicate `getTrainingData.php` / `fetch_data.php` / `fetch_trng_data.php` endpoints (S)
Three near-identically named endpoints exist in `hrd/api/`. It is unclear which is canonical.  
**Why:** Unused endpoints are dead code that can mask security gaps and confuse future developers.  
**Approach:** Audit callers of each file, consolidate into a single named endpoint, and delete the others (move to `archive/` if uncertain).

### 11.8 🟢 HTTPS-only asset loading (S)
Some pages load CSS/JS from `http://` CDN links (verify — older template files sometimes do this).  
**Why:** Mixed-content warnings degrade browser trust on the HTTPS production site.  
**Approach:** Audit all `<link>` and `<script>` `src`/`href` attributes across the page templates for protocol-relative URLs (`//`) or `http://` and change them to `https://`.

---

## 12. Security Hardening

### 12.1 🔴 `proof_payment.php` uses session check only, not the IMIS role system (S)
The page guards with `if (empty($_SESSION['username']))` rather than `checkSystemAccess()` / `enforceSystemAccess()`.  
**Why:** Any logged-in IMIS user could potentially access this URL even if they have no OTRS permissions.  
**Approach:** Replace the loose session check with the same pattern used in `hrd/participants.php` — validate `$_SESSION['type']` against the expected location or use `hasSystemPermission('otrs', 'User')`.

### 12.2 🔴 `hrd/api/` endpoints that accept a `training_id` or `participant_id` should verify ownership (S)
Several HRD API endpoints accept an integer ID and operate on it without confirming the row belongs to the OTRS system (not another subsystem's tables).  
**Why:** Though the risk is low in a single-DB setup, mixing IDs from different subsystems can silently corrupt data if table IDs overlap.  
**Approach:** Add an existence check (`SELECT id FROM otrs_trainings WHERE id = :id`) before acting on any externally supplied ID.

### 12.3 🟡 Rate-limit proof-of-payment file uploads (S)
File upload endpoints currently have no rate limiting visible beyond the general session-based limiter in `security.php`.  
**Why:** Repeated rapid uploads can fill disk quota on shared hosting.  
**Approach:** Add a per-user upload-count check in `update_proof_payment.php` (e.g., max 5 uploads per minute) using the `otrs_rate_limits` table that already exists.

---

## Priority Summary

| # | Item | Priority | Effort |
|---|------|----------|--------|
| 1.1 | Clickable stat cards | 🔴 High | S |
| 1.2 | Upcoming This Week widget | 🔴 High | S |
| 2.1 | Bulk payment status update | 🔴 High | M |
| 2.2 | Duplicate participant detection | 🔴 High | S |
| 2.3 | Payment status change reason | 🔴 High | S |
| 3.1 | Batch certificate email button | 🔴 High | S |
| 3.2 | Use cert email jobs queue properly | 🔴 High | L |
| 4.1 | Email FO when cancellation processed | 🔴 High | S |
| 4.2 | FO can withdraw pending request | 🔴 High | S |
| 5.1 | Show proof rejection reason to FO | 🔴 High | S |
| 5.2 | File type/size validation | 🔴 High | S |
| 6.1 | Email on submission validation | 🔴 High | S |
| 8.1 | Export annual report to PDF/Excel | 🔴 High | M |
| 9.1 | Slots remaining in training list | 🔴 High | S |
| 9.2 | Quick cancellation button from training row | 🔴 High | S |
| 11.1 | Centralise location map | 🔴 High | S |
| 11.2 | Remove `unpaid_participants.php` | 🔴 High | S |
| 12.1 | Fix `proof_payment.php` access check | 🔴 High | S |
| 1.3 | Trend indicators on stat cards | 🟡 Medium | S |
| 1.4 | Recent activity feed | 🟡 Medium | M |
| 2.4 | Promissory note expiration | 🟡 Medium | M |
| 2.5 | Overbooking warning | 🟡 Medium | S |
| 3.3 | Certificate preview before email | 🟡 Medium | M |
| 3.4 | Revoke certificate UI | 🟡 Medium | S |
| 4.3 | Bulk approve/reject cancellations | 🟡 Medium | S |
| 4.4 | Filter cancellations by training (FO) | 🟡 Medium | S |
| 5.3 | Approve all pending proofs (HRD) | 🟡 Medium | S |
| 5.4 | Proof status badge in participant list | 🟡 Medium | S |
| 6.2 | Missing submission count per day | 🟡 Medium | S |
| 6.3 | Bulk approve submissions for a day | 🟡 Medium | S |
| 7.1 | Export evaluation results | 🟡 Medium | M |
| 7.2 | Show eval count in training selector | 🟡 Medium | S |
| 7.3 | Evaluation response rate indicator | 🟡 Medium | S |
| 8.2 | Month-by-month chart in annual report | 🟡 Medium | S |
| 8.3 | Demographics breakdown | 🟡 Medium | S |
| 9.3 | Proof rejection badge on FO dashboard | 🟡 Medium | S |
| 9.4 | Year-to-date totals on FO summary | 🟡 Medium | S |
| 10.1 | Filter replacements by training | 🟡 Medium | S |
| 10.2 | Email FO on HRD-performed replacement | 🟡 Medium | S |
| 11.3 | Consolidate update_participant endpoints | 🟡 Medium | S |
| 11.4 | Clean up cert_issue_ajax_v1.php | 🟡 Medium | S |
| 11.5 | Server-side DataTables pagination | 🟡 Medium | M |
| 11.6 | In-app notification centre | 🟡 Medium | M |
| 12.2 | Verify ownership on ID-based endpoints | 🟡 Medium | S |
| 12.3 | Rate-limit file uploads | 🟡 Medium | S |
| 1.5 | DB-based auto-complete lock | 🟢 Low | M |
| 2.6 | Export participants to PDF | 🟢 Low | M |
| 3.5 | Download all certificates as ZIP | 🟢 Low | M |
| 6.4 | Download day submissions as ZIP | 🟢 Low | M |
| 7.4 | Cross-training evaluation trend | 🟢 Low | L |
| 8.4 | Drill-down from annual report | 🟢 Low | S |
| 9.5 | Confirmation dialogs on destructive FO actions | 🟢 Low | S |
| 11.7 | Consolidate duplicate analytics endpoints | 🟢 Low | S |
| 11.8 | HTTPS-only asset loading | 🟢 Low | S |

---

*Generated: 2026-05-30. Review with the development team before scheduling implementation sprints.*
