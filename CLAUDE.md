# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**OTRS** (Online Training Registration System) is a subsystem of the **IMIS** platform for CSC Regional Office VIII. It manages training programs, participant registration, payment tracking, certificate generation, and post-training evaluations.

The repository root is `imis/otrs/`. The parent `imis/` directory contains shared infrastructure (session, DB, bootstrap, layout helpers).

## Running the Application

No build step — pure PHP/MySQL. Use XAMPP (Apache + MySQL):
- Main IMIS dashboard: `http://localhost/ongbak/imis/`
- OTRS field office view: `http://localhost/ongbak/imis/otrs/`
- HRD admin area: `http://localhost/ongbak/imis/otrs/hrd/`
- Login is handled at the IMIS platform level (`imis/login.php`)

There are no test suites, linting configs, or CI pipelines.

## Architecture

### Directory Layout

```
imis/                     ← shared IMIS infrastructure
  includes/
    bootstrap.php         ← single-file entry point (replaces the 3-line pattern below)
    session.php           ← session hardening, auth gate, $conn, CSRF seed
    connect.php           ← canonical PDO connection ($conn, Asia/Manila timezone)
    security.php          ← ImisSecurityManager: rate limiting, audit log, sanitizers
  imis_include.php        ← imis_include() helper — resolves inc/ partials from any depth

imis/otrs/                ← THIS REPO
  index.php               ← field office + HRD training list (role-aware via $_SESSION['type'])
  participants.php        ← field office participant list (?location=)
  cancellation_requests.php  ← field office cancellation request form
  replacement_requests.php   ← field office replacement request form
  unpaid_participants.php    ← field office unpaid participant view
  proof_payment.php          ← field office proof-of-payment upload
  connect.php             ← OTRS-local DB credentials (rarely needed; bootstrap.php preferred)
  .env                    ← SMTP credentials (two accounts: HRD + IMIS system)
  inc/
    mail_config.php       ← reads .env, defines SMTP_* / MAIL_* constants
  api/
    cancellation_logs.php ← fetch cancellation audit log
    field-office/         ← AJAX endpoints for field office actions
  cron/
    auto_complete_trainings.php  ← CLI-only; marks overdue trainings Completed
  db/                     ← schema migrations (run once on production, safe to re-run)
    add_sme_management.sql      ← creates otrs_smes, otrs_sme_training_days, otrs_attendance; adds sme_id FK to otrs_training_smes
    add_sme_type.sql            ← adds sme_type + user_id columns to otrs_smes
    add_sme_photo.sql           ← adds photo column to otrs_smes
    add_attendance_source.sql   ← adds source column to otrs_attendance
    add_eval_day_number.sql     ← adds day_number to otrs_evaluations
    add_training_suspensions.sql ← creates otrs_training_suspensions
    add_reschedule_fields.sql   ← reschedule tracking fields
    migrate_lowercase_statuses.sql ← normalises payment_status casing
    otrs_payment_logs.sql       ← payment audit log table

  hrd/                    ← HRD admin area (type = 'hrd' only)
    index.php             ← HRD dashboard with stats
    participants.php      ← participant management per training (SME tab + Attendance tab)
    sme_management.php    ← global SME registry CRUD (internal + external, with profile photos)
    cancellation_requests.php   ← HRD review of FO cancellation requests
    replacement_requests.php    ← audit view of participant replacements
    generate_certificates.php   ← certificate generation UI
    submission_review.php       ← review participant output submissions per day
    evaluation_results.php      ← post-training evaluation results
    hrd_calendar.php            ← HRD event calendar with recurrence
    annual_report.php           ← annual training statistics report
    api/                  ← all HRD AJAX endpoints (flat procedural PHP)
      fetch_attendance.php       ← attendance sheet data (paid + promissory note only)
      export_attendance.php      ← CSV export of attendance sheet (GET, streams download)
      fetch_training_smes.php    ← SMEs for a training incl. sme_type, photo, user_profile
      fetch_smes_master.php      ← global SME registry (migration-resilient: SHOW COLUMNS guard)
      fetch_csc_users.php        ← employee search for internal SME autocomplete (incl. profile)
      fetch_sme_trainings.php    ← training history for a registry SME by sme_id (HRD auth)
    assets/js/
      participants-sme-attendance.js  ← SME tab + attendance tab logic for participants.php

  assets/                 ← CSS/JS/vendor (NiceAdmin Bootstrap + ApexCharts + DataTables)
    uploads/
      certificates/       ← generated certificate PDFs
      qrcodes/            ← QR code PNGs for certificates
      sme_photos/         ← uploaded profile photos for external SMEs
```

### Public Participant Portals

Three separate participant-facing portals live at `cscro8.com/otrs/` (not inside `imis/otrs/`):

| Directory | Portal | Purpose |
|---|---|---|
| `otrs/f2f/` | Face-to-Face | Self-reported attendance + evaluation for in-person trainings |
| `otrs/free/` | Free/Certificate Claim | Evaluation + certificate download for free trainings |
| `otrs/online/` | Online | Self-reported attendance + evaluation for virtual trainings |

Each portal is fully independent with its own `includes/bootstrap.php`, `api/`, and `index.php`. They share a common DB connection singleton via `otrs/connect.php`:

```php
// otrs/connect.php — Database singleton (used by all three portals)
class Database { ... static function getInstance() ... }
function getDB(): PDO { return Database::getInstance()->getConnection(); }
```

Response format in portal APIs: `{success: true|false, data: [...], error: '...'}` — **different from HRD endpoints** which use `{status: 'success'|'error', ...}`.

`otrs/free/includes/response.php` provides a `Response` class (`Response::success()`, `Response::error()`). `otrs/f2f/` and `otrs/online/` bootstrap files also expose `Response::` via inline classes.

**Shared public endpoint:** `otrs/api/get_sme_trainings.php` — no auth required, returns training history for a registry SME. Called by all three portals for the SME training history modal.

**`api/get_smes.php`** in each portal returns `id, sme_name, designation, sort_order, sme_id` — the `sme_id` field is used by the portal JS to show a "Trainings" button only for registry-linked SMEs. Each portal's `index.php` renders an `openSmeHistoryModal()` that fetches `../api/get_sme_trainings.php`.

### Bootstrap Pattern

**Every non-login OTRS page now uses a single line:**
```php
require_once __DIR__ . '/../../includes/bootstrap.php';
// Adjust ../../ to match depth relative to imis/
```

`bootstrap.php` loads in order: `session.php` (auth gate + `$conn`) → `security.php` (rate limiting, CSRF) → supplemental security headers → `imis_include.php`. It is idempotent and safe on AJAX endpoints (expired sessions return HTTP 401 JSON, not HTML).

**Do not call** `$sec->createSessionFingerprint()` when bootstrap.php is active — `session.php` handles fingerprinting exclusively.

Depth table:
| File location | Bootstrap path |
|---|---|
| `otrs/*.php` | `__DIR__ . '/../includes/bootstrap.php'` |
| `otrs/hrd/*.php` | `__DIR__ . '/../../includes/bootstrap.php'` |
| `otrs/hrd/api/*.php` | `__DIR__ . '/../../../includes/bootstrap.php'` |
| `otrs/api/field-office/*.php` | `__DIR__ . '/../../../includes/bootstrap.php'` |

### Session & Permission System

`imis/includes/session.php` is the IMIS-wide auth layer:
- Strict cookie settings (HttpOnly, SameOrigin, Secure on HTTPS)
- Session ID rotated every 15 minutes; 60-minute idle timeout
- Key session variables set on login: `$_SESSION['type']` (office code), `$_SESSION['name']`, `$_SESSION['username']`, `$_SESSION['role']`

OTRS uses `$_SESSION['type']` (not IMIS role tables) for access control:
- `'hrd'` → HRD admin, routes to `hrd/index.php`
- All other values (`lfoi`, `lfoii`, `bfo`, `wlso`, `slfo`, `sfo`, `esfo`, `nsfo`, `ro6`, `ro7`) → field office, routes to `index.php?location={type}`

### OTRS Data Model

The schema is fully normalized. Legacy `participants` and `trainings` tables have been replaced.

**Core tables:**

| Table | Purpose |
|---|---|
| `otrs_trainings` | Master training record. `training_status`: `Upcoming\|Ongoing\|Completed\|Cancelled`. `status`: `active\|inactive\|draft\|archived`. Types: `Public-Offered`, `Free`, `Congress-Conducted`. |
| `otrs_participants` | Person profile (name, contact, position, agency). Training history is in `otrs_registrations`, not here. |
| `otrs_registrations` | Participant–training bridge (replaces `participants` + `otrs_enrollments`). `payment_status`: `pending\|paid\|free\|cancelled\|promissory note`. `registration_status`: `Pending\|Confirmed\|Cancelled`. |
| `otrs_certificates` | Certificate metadata: `certificate_number`, `status` (`active\|revoked`), `email_status`, `claim_source`, `download_count`. Re-issue regenerates `verification_token` but retains `certificate_number`. |
| `otrs_proof_payment` | Uploaded payment proof files, with review workflow (`pending\|approved\|rejected`). |
| `otrs_cancellation_requests` | FO cancellation requests with `status` (`pending\|approved\|rejected`), reviewed by HRD. |
| `otrs_cancellation_logs` | Audit trail for every status change on a cancellation request. |
| `otrs_replacement_requests` | Audit trail for participant replacements within a registration. |
| `otrs_evaluations` | Post-training participant evaluation (ratings, comments, SME ratings). |
| `otrs_training_submissions` | Participant output files per training day (`day_number`). Validated by HRD (`pending\|approved\|rejected`). |
| `otrs_smes` | Global SME registry. `sme_type`: `internal\|external`. `user_id`: FK to `users_cscro8` (internal only, NULL for external). `photo`: filename in `imis/assets/uploads/sme_photos/` (external only, URL `/assets/uploads/sme_photos/{photo}`). Migrations: run `add_sme_management.sql` → `add_sme_type.sql` → `add_sme_photo.sql` in order. |
| `otrs_training_smes` | Subject-matter experts / facilitators per training. `sme_id` FK → `otrs_smes.id`; NULL for ad-hoc SMEs added directly without a registry entry. |
| `otrs_sme_training_days` | Which day numbers each training SME covers. No rows = SME applies to all days (backward-compatible). |
| `otrs_attendance` | Per-participant, per-day attendance records. `status`: `present\|absent\|excused`. `source`: `hrd\|self` (self = participant self-reported via portal). |
| `otrs_hrd_calendar` | HRD events with recurrence (`none\|monthly\|quarterly\|annually`) and exception dates. Trainings can be auto-synced. |
| `otrs_training_names` | Training name library (lookup for add-training form). |
| `otrs_collecting_officer` | Collecting officer lookup table. |
| `otrs_cert_email_jobs` | Batch certificate email job tracking (`processing\|done`). |
| `otrs_claim_tokens` | One-time tokens for participants to claim/download certificates. |
| `otrs_rate_limits` | Per-IP rate limiting log. |

**Key relationship:** `otrs_participants` (1) → (N) `otrs_registrations` (N) → (1) `otrs_trainings`. Always JOIN these two when fetching "a participant in a training".

### HRD vs Field Office Access

- **`hrd/`** — `$_SESSION['type'] === 'hrd'` required. Full CRUD on trainings, participants, certificates, calendar. All mutating endpoints in `hrd/api/` also validate CSRF.
- **Root `otrs/` pages** — field office users. Training and participant lists are filtered by `?location=` (or `$_SESSION['type']` when not HRD).
  - **Standard FOs** (LFOI, LFOII, BFO, WLSO, SLFO, SFO, ESFO, NSFO) — read-only views plus proof-of-payment upload and cancellation/replacement request submission.
  - **RO6 and RO7** — same as standard FOs plus bulk email buttons (acknowledgment, Zoom link, training advisory). Controlled by `$showButtons = ($location === 'ro6' || $location === 'ro7')`.

### AJAX Pattern

All HRD AJAX endpoints in `hrd/api/` follow:
1. `ini_set('display_errors', 0)` + `require_once .../bootstrap.php`
2. `header('Content-Type: application/json; charset=utf-8')`
3. Method guard (405 if not POST)
4. Role check (403 if not `hrd`)
5. CSRF validation: `$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? ''); if (!validateCsrfToken($csrfToken)) { ... }`
6. Input via `filter_input()` / `trim()` with explicit casting
7. PDO prepared statement via `$conn`
8. `echo json_encode(['status' => 'success'|'error', ...])`

Field office endpoints in `api/field-office/` follow the same pattern without the HRD role check.

Frontend calls use `$.ajax()` or `fetch()`, sending CSRF token as `X-CSRF-TOKEN` header or `_csrf` POST field.

### Email System

SMTP credentials live in `.env`; loaded by `inc/mail_config.php` into named constants. **Never hardcode credentials in endpoint files** — always `require_once __DIR__ . '/../inc/mail_config.php'` (adjust depth) and use the constants.

Two SMTP accounts:
- `SMTP_*/MAIL_FROM*` — `cscro8.imis@gmail.com`, used for all training-related email (acknowledgments, Zoom links, advisories, certificates).
- `SMTP_IMIS_*/MAIL_IMIS_FROM*` — `cscro8webhost@gmail.com`, used for IMIS system emails (password resets).

| File | Purpose | Recipients |
|---|---|---|
| `hrd/api/send_email-ack.php` | Acknowledgment letter with payment instructions | Selected participants |
| `hrd/api/send_email-link.php` | Zoom/meeting link | Paid + Promissory Note only |
| `hrd/api/send_email-adv.php` | Training advisory | Pending only |
| `hrd/api/cert_email_ajax.php` | Certificate PDF attachment | Individual participant |
| `hrd/api/send_email-bkup.php` | DB backup file | Fixed admin recipient |

### Certificate Generation Pipeline

`hrd/generate_certificates.php` (UI) → `hrd/api/cert_issue_ajax.php` (TCPDF + PDO, writes `otrs_certificates`) → `hrd/api/cert_email_ajax.php` (PHPMailer, updates `email_status`).

- Only `Free`, `Public-Offered`, and `Congress-Conducted` trainings are eligible.
- PDF output: `assets/uploads/certificates/.../{year}/{training_name}/{date}/{participant_name}/cert_{timestamp}_{id}.pdf`
- PDFs are password-protected (print-only, no copy/edit).
- Re-issue: retains `certificate_number`, regenerates `verification_token`, resets `email_status`.
- QR encodes a verification URL; composited with CSC logo via GD.

### SME Management

`hrd/sme_management.php` manages the global SME registry (`otrs_smes`). Two types:

- **Internal** — CSC RO VIII employees. Selected via autocomplete (`fetch_csc_users.php`) which searches `users_cscro8`. Profile photo is pulled from `users_cscro8.profile` (stored in `imis/admin/uploads/`). URL: `/admin/uploads/{profile}` (note: `imis.cscro8.com` root maps to `imis/`, so never prefix with `/imis/`).
- **External** — outside speakers. Optional photo upload stored as `otrs_smes.photo` in `imis/assets/uploads/sme_photos/`. URL: `/assets/uploads/sme_photos/{photo}`. Requires `db/add_sme_photo.sql` migration.

`fetch_training_smes.php` (used by `participants.php` SME tab) JOINs both `otrs_smes` and `users_cscro8` to return `sme_type`, `photo`, and `user_profile` alongside each training SME row. The JS card renderer (`participants-sme-attendance.js`) picks the correct image source based on `sme_type`.

SMEs with a registry link (`sme_id` non-null) show a **"Trainings" button** in the card and in `sme_management.php` — clicking it opens a modal fetching `hrd/api/fetch_sme_trainings.php?sme_id=N`.

### Attendance Sheet

`hrd/participants.php` → Attendance tab → `hrd/api/fetch_attendance.php`:
- Only participants with `payment_status IN ('paid', 'promissory note')` are shown (pending, free, cancelled excluded).
- Export: `hrd/api/export_attendance.php` (GET, same filter) — streams a UTF-8 BOM CSV named `Attendance_{TrainingName}_{YYYYMMDD}.csv`. Export button sits beside the Refresh button in the attendance toolbar.

### SME Training History Modal

Available in three places, all fetching the same shared endpoint:
- `hrd/sme_management.php` → training count badge click → `hrd/api/fetch_sme_trainings.php`
- `hrd/participants.php` SME tab → "Trainings" button → `hrd/api/fetch_sme_trainings.php`
- `otrs/f2f/`, `otrs/free/`, `otrs/online/` portals → "Trainings" button on SME card → `otrs/api/get_sme_trainings.php` (public, no auth; returns `{success: true, data: [...]}`)

Response format differs: HRD endpoint uses `{status: 'success'}`, portal endpoint uses `{success: true}`.

### Auto-Complete Trainings

Overdue trainings (`end_date < CURDATE()`, not already `Completed`/`Cancelled`) are auto-completed by two mechanisms:
1. **Web-side lock** — both `hrd/index.php` and `otrs/index.php` run the UPDATE at most once every 5 minutes using `sys_get_temp_dir() . '/otrs_auto_complete.lock'` (filemtime check).
2. **CLI cron** — `cron/auto_complete_trainings.php` runs via Hostinger hPanel Cron Jobs (`php .../cron/auto_complete_trainings.php`). Rejects non-CLI requests with HTTP 403. Also clears the lock file after running.

### Vendor Libraries (manually included, no Composer)

- **TCPDF** (`vendor/tcpdf/`) + **FPDI** (`vendor/fpdi/`) — certificate PDF generation with template overlay
- **phpqrcode** (`vendor/phpqrcode/`) — QR code PNG composited with CSC logo via GD
- **PHPMailer** (`phpmailer/`) — Gmail SMTP, included directly via `require`

## Key Conventions

- All SQL uses PDO prepared statements — never string interpolation.
- All HTML output goes through `htmlspecialchars()`.
- All state-changing HRD endpoints validate a CSRF token via `validateCsrfToken()` (provided by `security.php` through `bootstrap.php`).
- `archive/` subdirectories contain superseded files — do not edit them.
- Files named `*_v1.php`, `*_v2.php`, `* copy.php` are legacy versions — do not edit them.
- The `hrd/connect.php` and `hrd/connect_v1.php` files exist for legacy compatibility; new code uses `bootstrap.php` which provides `$conn` via `session.php` → `connect.php`.
- Production DB user: `u390694310_cscro8`; local XAMPP dev uses `root`/no password.
- `imis.cscro8.com` maps to the `imis/` directory — image URLs must use `/admin/uploads/` and `/assets/uploads/`, never `/imis/admin/uploads/` or `/imis/assets/uploads/`.
- SME endpoints (`fetch_smes_master.php`, `add_sme.php`, `update_sme.php`) use `SHOW COLUMNS FROM otrs_smes LIKE 'column'` to detect whether migrations have run — all are migration-resilient and safe before and after schema changes.
- Named parameters **cannot be reused** in the same PDO prepared statement when `EMULATE_PREPARES => false` (production setting). Use `:q1`, `:q2`, `:q3` etc. Reusing `:q` causes `SQLSTATE[HY093]: Invalid parameter number`.
- **Do not put `JSON.stringify()` inside `onclick=""` HTML attributes.** Chrome compiles `onclick` at parse time — inner double-quotes from `JSON.stringify` break the attribute immediately. Pass data via `data-*` attributes and use delegated `addEventListener` instead.
- Middle name display convention: use `NULLIF(CONCAT(LEFT(TRIM(mname),1),'.'), '.')` in SQL — produces `'J.'` when mname is set, `NULL` when empty, then wrap in `CONCAT_WS(' ', fname, <expr>, lname)` which skips NULLs automatically.
- Public portal (otrs/f2f, otrs/free, otrs/online) API responses use `{success: true, data: [...]}`. HRD (imis/otrs/hrd) API responses use `{status: 'success', data: [...]}`. Never mix the two formats.
- MariaDB reserved words used as column names (`position`, `type`, `status`) must be backtick-quoted in SQL queries.
