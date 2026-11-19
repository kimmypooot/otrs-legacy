# Non-Consecutive Training Dates — Design

Date: 2026-08-04
Scope: `imis/otrs/hrd/index_manage_trainings.php` (Add/Edit Training modals) and all downstream consumers of a training's day list.

## Problem

`otrs_trainings` currently models a schedule as a single continuous `start_date`→`end_date` range. Every downstream feature (attendance, evaluations, certificates, calendar, exports, credit-hour calculation) assumes every calendar day in that range is a training day, minus any dates logged in `otrs_training_suspensions`. Some trainings actually run on non-consecutive dates (e.g. Aug 25 and Aug 27, with Aug 26 not a training day). There is currently no way to express this without either running a wide range and suspending every gap day (impractical for sparse schedules) or misrepresenting the schedule.

## Goals

- Let HRD staff pick specific non-consecutive training dates in the Add and Edit Training modals.
- Preserve the existing simple Start/End range workflow as the default for ordinary consecutive trainings.
- Full backward compatibility: existing records with only `start_date`/`end_date` continue to work with zero data migration.
- All downstream features (attendance, reports, calendar, notifications, certificates, evaluations, credit hours) use the true set of training dates rather than assuming every day in the range is a training day.

## Non-Goals

- No change to `otrs_training_suspensions` semantics — it still means "a planned training day did not happen" and continues to work on top of custom dates.
- No bulk migration/backfill of existing trainings into the new table.
- No change to how registration deadlines are computed.

## Data Model

New table:

```sql
CREATE TABLE IF NOT EXISTS otrs_training_dates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  training_id INT NOT NULL,
  training_date DATE NOT NULL,
  day_number TINYINT NOT NULL,
  UNIQUE KEY uniq_training_date (training_id, training_date),
  KEY idx_training_id (training_id),
  CONSTRAINT fk_training_dates_training FOREIGN KEY (training_id)
    REFERENCES otrs_trainings(id) ON DELETE CASCADE
);
```

Migration file: `db/add_training_dates.sql` (idempotent, follows existing `db/add_*.sql` convention).

Semantics:
- **Zero rows** for a `training_id` → legacy/simple mode. The training's days are derived from `start_date`/`end_date` minus `otrs_training_suspensions`, exactly as today.
- **One or more rows** → custom mode is authoritative. The training's days are exactly the rows in this table, minus any of those dates also present in `otrs_training_suspensions`.
- `day_number` is the chronological rank (1-indexed) of `training_date` among that training's rows. Recomputed and rewritten on every save (delete+reinsert in a transaction).
- `otrs_trainings.start_date` / `end_date` remain required, non-null columns. In custom mode they are auto-set to `MIN(training_date)` / `MAX(training_date)` from the submitted dates, so every existing feature that reads `start_date`/`end_date` directly (sorting, filtering, `registration_deadline` comparisons, the auto-complete cron's `end_date < CURDATE()` check, annual report's `YEAR(start_date)` grouping) continues to work unchanged.

## Shared Helper

New file: `otrs/hrd/inc/training_dates.php`

```php
function getTrainingDates(PDO $conn, int $trainingId): array
```

Returns an ordered array of `['date' => 'Y-m-d', 'day_number' => int]`, with any `otrs_training_suspensions` dates removed.

Logic:
1. Query `otrs_training_dates` for the training. If rows exist, use them as the base date set.
2. If no rows exist, fall back to the current range-loop logic (`start_date` to `end_date`, one day at a time).
3. In both cases, remove any date present in `otrs_training_suspensions` for that training.
4. Return in ascending date order with `day_number` as the array index + 1 (re-ranked after suspension removal, matching current behavior).

This function becomes the single source of truth for "what are this training's days," replacing the duplicated range loops currently in:
- `hrd/api/fetch_attendance.php` (`getTrainingDates()` local function — renamed usage points here, logic moves to the shared file)
- `hrd/api/export_attendance.php`
- `api/eval_get_smes.php`
- `hrd/submission_review.php` (keeps its own consecutive-day "segment" grouping logic on top, but sources its base date list from the shared helper)
- `hrd/api/cert_issue_ajax.php`

And fixes an existing inconsistency: `hrd/participants.php:136` and `hrd/evaluation_results.php:79` currently compute total days as `DateTime::diff()->days + 1` without subtracting suspensions. Both switch to `count(getTrainingDates(...))`.

## Credit Hours (`no_hrs`)

`hrd/api/recalc_hrs.php`, `hrd/api/add_suspension.php`, `hrd/api/delete_suspension.php` currently compute:
```sql
(DATEDIFF(end_date, start_date) + 1 - suspension_count) * 8
```
This changes to `count(getTrainingDates($conn, $trainingId)) * 8`, computed in PHP after calling the shared helper (rather than a pure-SQL expression), since the day count is no longer derivable from a single SQL formula once custom dates exist. This formula is correct for both legacy and custom-date trainings, unifying what were previously two implicit code paths into one.

## Frontend — Add & Edit Training Modals

Both modals (`index_manage_trainings.php`, Add ~L362-437, Edit ~L537-602):

- Start Date / End Date inputs remain, unchanged, as the default.
- A new toggle switch, "Custom (non-consecutive) dates," placed directly below the Start/End row.
- Toggling **on**:
  - Hides the Start Date / End Date inputs (values retained in hidden fields, not cleared).
  - Shows a flatpickr instance in `mode: "multiple"` for picking individual calendar dates.
  - **Add modal**: picker starts empty.
  - **Edit modal**: if the training already has `otrs_training_dates` rows, the toggle is pre-enabled and the picker pre-populated with those dates on modal open. If the training is legacy range-only, enabling the toggle pre-seeds the picker with every date currently in `start_date`..`end_date` (so the admin only has to remove the gap days, not re-pick the whole schedule).
- Toggling **off** (in Edit, for a training currently in custom mode): reverts to plain Start/End inputs seeded with the current `MIN`/`MAX` of the training's dates; on save, this deletes all `otrs_training_dates` rows for the training (explicit revert to legacy mode).
- Client-side validation: at least one date required in custom mode; all dates must be unique (flatpickr enforces this natively) and on/after today for new trainings (existing rule, applied per-date instead of to a single start date).
- Vanilla JS + existing flatpickr-free date inputs pattern in this codebase — flatpickr is not currently a dependency, so it is added as a new vendor asset under `otrs/assets/vendor/flatpickr/` (self-hosted, consistent with the project's no-CDN, manually-vendored convention).

## Backend — Add & Edit Endpoints

`hrd/api/add_training.php` and `hrd/api/update_training.php`:

- Accept an optional `training_dates[]` POST array (`Y-m-d` strings).
- If present and non-empty:
  1. Validate each date format, dedupe, sort ascending.
  2. Validate each date ≥ today (Add only — same rule as today's single start_date check).
  3. `start_date = min(dates)`, `end_date = max(dates)`.
  4. Within the existing transaction: upsert `otrs_trainings` as today, then (Edit only) `DELETE FROM otrs_training_dates WHERE training_id = ?`, then bulk `INSERT INTO otrs_training_dates (training_id, training_date, day_number) VALUES ...` with day_number = 1-indexed position.
- If `training_dates[]` is absent (toggle was off): existing behavior, completely unchanged. For Edit, this also means deleting any pre-existing `otrs_training_dates` rows for that training (explicit revert to legacy mode, per the "toggling off" behavior above) — implemented as an unconditional `DELETE ... WHERE training_id = ?` executed before the conditional insert, so both branches share one code path.
- Duplicate-training check in `add_training.php` (currently by name+start+end) is unchanged — it still compares the derived `start_date`/`end_date`.

## Calendar Sync (`hrd/api/fetch_hrd_calendar.php`)

- When `otrs_training_dates` has rows for a training: emit one FullCalendar event per date (single-day event, `start = date`, `end = date + 1 day`).
- When it has no rows (legacy): emit the existing single continuous block (`start_date` → `end_date + 1 day`), unchanged.
- All events for one training share the same `training_id` reference/color-coding as today; only the event-count-per-training changes for custom-date trainings.

## Testing

No automated test suite in this project (per CLAUDE.md). Manual verification plan:
- Add a training with custom dates Aug 25 + Aug 27 2026 → verify `otrs_trainings.start_date=2026-08-25`, `end_date=2026-08-27`, and two rows in `otrs_training_dates`.
- Verify attendance grid (`hrd/participants.php` Attendance tab) shows exactly 2 day columns (25, 27), not 3.
- Verify CSV export, evaluation day options, and certificate date-range text all reflect only the 2 dates.
- Verify calendar shows two separate single-day blocks, not one 3-day block.
- Suspend Aug 25 on the custom-date training → verify it disappears from attendance/eval/cert everywhere, `no_hrs` recalculates to 8.
- Edit a pre-existing legacy (range-only) training, toggle Custom on → verify picker pre-populates with the full existing range; remove one day, save → verify all downstream views immediately reflect the new date set.
- Toggle Custom off on a custom-mode training → verify it reverts to a plain range and `otrs_training_dates` rows are deleted.
- Confirm an untouched legacy training (never opened in Edit) behaves byte-identical to current production behavior everywhere.

## Files Touched

- New: `db/add_training_dates.sql`, `hrd/inc/training_dates.php`
- New vendor: `otrs/assets/vendor/flatpickr/` (JS + CSS)
- Modified: `hrd/index_manage_trainings.php` (both modals + JS), `hrd/api/add_training.php`, `hrd/api/update_training.php`, `hrd/api/fetch_attendance.php`, `hrd/api/export_attendance.php`, `api/eval_get_smes.php`, `hrd/submission_review.php`, `hrd/api/cert_issue_ajax.php`, `hrd/api/recalc_hrs.php`, `hrd/api/add_suspension.php`, `hrd/api/delete_suspension.php`, `hrd/api/fetch_hrd_calendar.php`, `hrd/participants.php`, `hrd/evaluation_results.php`
