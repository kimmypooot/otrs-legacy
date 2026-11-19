# Non-Consecutive Training Dates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let HRD staff schedule a training on non-consecutive dates (e.g. Aug 25 + Aug 27, skipping Aug 26) from the Add/Edit Training modals in `imis/otrs/hrd/index_manage_trainings.php`, with every downstream feature (attendance, exports, certificates, evaluations, credit hours, calendar) using the true set of training dates instead of assuming every day between `start_date` and `end_date` is a training day.

**Architecture:** A new child table `otrs_training_dates(training_id, training_date, day_number)` stores explicit dates when a training uses custom (non-consecutive) scheduling. Zero rows for a training means legacy/simple range mode (100% unchanged behavior — no data migration). A single shared PHP helper `getTrainingDates(PDO $conn, int $trainingId): array` becomes the one source of truth for "what are this training's actual days," replacing six independently-duplicated range loops. `otrs_trainings.start_date`/`end_date` stay populated (auto-derived as MIN/MAX of the custom dates) so every existing range-dependent query keeps working untouched.

**Tech Stack:** PHP 8.4 (no framework), PDO with `EMULATE_PREPARES => false`, vanilla JS + jQuery + Bootstrap 5 modals, no build step, MariaDB.

## Global Constraints

- All SQL uses PDO prepared statements — never string interpolation.
- All HTML output goes through `htmlspecialchars()`.
- Named parameters cannot repeat in one prepared statement (`EMULATE_PREPARES => false`) — use `:q1`, `:q2`, etc. if the same value is bound twice.
- All state-changing HRD endpoints validate CSRF via `validateCsrfToken()` (`$_SERVER['HTTP_X_CSRF_TOKEN']` or `$_POST['_csrf']`).
- HRD AJAX response format is `{status: 'success'|'error', ...}` (never the portal's `{success: true/false}` format), except `eval_get_smes.php` and other public `otrs/api/*` endpoints, which already use `{success: true/false}` — preserve each file's existing format, don't unify them.
- No test suite exists in this project. Verification is `php -l` for syntax + manual functional checks (curl / browser) described in each task.
- Do not add a new client-side vendor library (flatpickr, etc.) for the date picker — build the multi-date UI from plain `<input type="date">` elements + vanilla JS, consistent with every other date field in this codebase and avoiding a new dependency with no existing vendoring precedent for it.
- MariaDB reserved words as column names must be backtick-quoted (not applicable to new column names here, but preserve existing backticks when editing surrounding SQL).
- Production DB user: `u390694310_cscro8`; local XAMPP dev uses `root`/no password — do not hardcode credentials, use `$conn` from bootstrap.

---

## Task 1: Database migration — `otrs_training_dates` table

**Files:**
- Create: `otrs/db/add_training_dates.sql`

**Interfaces:**
- Produces: table `otrs_training_dates(id, training_id, training_date, day_number, created_at)` with `UNIQUE KEY uniq_training_date (training_id, training_date)` and `FOREIGN KEY (training_id) REFERENCES otrs_trainings(id) ON DELETE CASCADE`. Every later task that reads/writes this table depends on this exact shape.

- [ ] **Step 1: Write the migration file**

```sql
-- Migration: explicit non-consecutive training dates
-- Run once. Safe to re-run: IF NOT EXISTS guard.
-- Zero rows for a training_id = legacy/simple start_date..end_date range mode (unchanged behavior).
-- One or more rows = custom (non-consecutive) mode is authoritative for that training's days.

CREATE TABLE IF NOT EXISTS `otrs_training_dates` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `training_id`    INT(12)      NOT NULL,
    `training_date`  DATE         NOT NULL,
    `day_number`     TINYINT(4)   NOT NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_training_date` (`training_id`, `training_date`),
    KEY `idx_training_id` (`training_id`),
    CONSTRAINT `fk_training_dates_training` FOREIGN KEY (`training_id`)
        REFERENCES `otrs_trainings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Explicit non-consecutive training dates. Empty for a training = use start_date..end_date range.';
```

- [ ] **Step 2: Verify SQL syntax**

Run: `mysql -u root otrs_local_db_name < otrs/db/add_training_dates.sql` on local XAMPP MySQL (or wherever local dev DB lives — check `otrs/connect.php` for the local schema name first: `grep -A5 "new PDO" otrs/connect.php`).
Expected: no error, `DESCRIBE otrs_training_dates;` shows the 5 columns.

- [ ] **Step 3: Commit**

```bash
git add otrs/db/add_training_dates.sql
git commit -m "feat(otrs): add otrs_training_dates migration for non-consecutive schedules"
```

Note: `otrs/` may be gitignored in this repo (verify with `git check-ignore -v otrs/db/add_training_dates.sql`); if so, skip the commit and just leave the file on disk — this was already discovered during the design phase.

---

## Task 2: Shared PHP helper — `getTrainingDates()`

**Files:**
- Create: `otrs/hrd/inc/training_dates.php`

**Interfaces:**
- Produces: `getTrainingDates(PDO $conn, int $trainingId): array` — returns a list of `['date' => 'Y-m-d', 'day_number' => int]` ordered ascending by date, with any `otrs_training_suspensions` dates removed and `day_number` re-ranked 1..N after removal. Also produces `getTrainingDateStrings(PDO $conn, int $trainingId): array` — convenience wrapper returning just the `Y-m-d` strings (the shape most existing call sites want). Every later task (3, 4, 6, 7, 8, 9, 10, 11, 12, 13, 14) calls one of these two functions instead of duplicating a range loop.

- [ ] **Step 1: Write the helper file**

```php
<?php
/**
 * otrs/hrd/inc/training_dates.php
 *
 * Single source of truth for "what are this training's actual days".
 *
 * If otrs_training_dates has rows for a training, those explicit dates are
 * authoritative (custom / non-consecutive schedule). If it has no rows, the
 * training's days are derived from start_date..end_date (legacy range mode) —
 * exactly the behavior every call site had before this file existed.
 *
 * In both cases, any date present in otrs_training_suspensions for that
 * training is removed, and day_number is re-ranked 1..N over the remaining
 * dates in ascending order.
 */

if (!function_exists('getTrainingDates')) {
    function getTrainingDates(PDO $conn, int $trainingId): array
    {
        // 1. Base date set: custom dates if present, else the legacy range.
        $stmtCustom = $conn->prepare(
            "SELECT training_date FROM otrs_training_dates
             WHERE training_id = :tid ORDER BY training_date ASC"
        );
        $stmtCustom->execute([':tid' => $trainingId]);
        $customDates = array_column($stmtCustom->fetchAll(PDO::FETCH_ASSOC), 'training_date');

        if (!empty($customDates)) {
            $baseDates = $customDates;
        } else {
            $stmtT = $conn->prepare(
                "SELECT start_date, end_date FROM otrs_trainings WHERE id = :tid LIMIT 1"
            );
            $stmtT->execute([':tid' => $trainingId]);
            $training = $stmtT->fetch(PDO::FETCH_ASSOC);

            $baseDates = [];
            if ($training && !empty($training['start_date']) && $training['start_date'] !== '0000-00-00') {
                $endRaw = (!empty($training['end_date']) && $training['end_date'] !== '0000-00-00')
                    ? $training['end_date'] : $training['start_date'];
                $cursor = new DateTime($training['start_date']);
                $last   = new DateTime($endRaw);
                while ($cursor <= $last) {
                    $baseDates[] = $cursor->format('Y-m-d');
                    $cursor->modify('+1 day');
                }
            }
        }

        if (empty($baseDates)) {
            return [];
        }

        // 2. Remove suspended dates.
        $stmtS = $conn->prepare(
            "SELECT suspended_date FROM otrs_training_suspensions WHERE training_id = :tid"
        );
        $stmtS->execute([':tid' => $trainingId]);
        $suspended = array_map('strval', array_column($stmtS->fetchAll(PDO::FETCH_ASSOC), 'suspended_date'));

        $remaining = array_values(array_diff($baseDates, $suspended));
        sort($remaining);

        // 3. Re-rank day_number 1..N.
        $result = [];
        foreach ($remaining as $i => $date) {
            $result[] = ['date' => $date, 'day_number' => $i + 1];
        }
        return $result;
    }
}

if (!function_exists('getTrainingDateStrings')) {
    function getTrainingDateStrings(PDO $conn, int $trainingId): array
    {
        return array_column(getTrainingDates($conn, $trainingId), 'date');
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l otrs/hrd/inc/training_dates.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual smoke test against local DB**

Create a throwaway script `otrs/hrd/inc/_smoke_test.php` (delete after running) that does:
```php
<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/training_dates.php';
// Replace 1 with a real training_id from your local otrs_trainings table.
var_dump(getTrainingDates($conn, 1));
```
Run via browser (logged in as hrd) or `php otrs/hrd/inc/_smoke_test.php` if CLI has DB access. Confirm it returns the expected range for an existing legacy training (a plain array of `['date' => ..., 'day_number' => ...]` matching every day between that training's `start_date` and `end_date`, minus any suspensions). Delete the smoke test file afterward.

- [ ] **Step 4: Commit**

```bash
git add otrs/hrd/inc/training_dates.php
git commit -m "feat(otrs): add shared getTrainingDates() helper"
```
(Same gitignore caveat as Task 1 — skip commit if `otrs/` is ignored.)

---

## Task 3: Backend — `add_training.php` accepts custom dates

**Files:**
- Modify: `otrs/hrd/api/add_training.php`

**Interfaces:**
- Consumes: POST field `training_dates[]` (array of `Y-m-d` strings), optional. When present and non-empty, custom mode; when absent/empty, legacy mode (fully unchanged existing behavior).
- Produces: inserts rows into `otrs_training_dates` for the new training when custom mode is used. `otrs_trainings.start_date`/`end_date` become `min`/`max` of the submitted dates.

- [ ] **Step 1: Add date-array collection and derivation, right after the existing input block (after line 36, before the `$slots`/`$no_hrs`/`$fee` block at line 38)**

```php
$trainingDatesRaw = $_POST['training_dates'] ?? [];
$trainingDates    = [];
if (is_array($trainingDatesRaw) && !empty($trainingDatesRaw)) {
    foreach ($trainingDatesRaw as $d) {
        $d = trim((string)$d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) &&
            checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) {
            $trainingDates[$d] = $d; // dedupe via key
        }
    }
    $trainingDates = array_values($trainingDates);
    sort($trainingDates);
}

// Custom mode: derive start/end from the picked dates, overriding the plain inputs.
if (!empty($trainingDates)) {
    $start_date = $trainingDates[0];
    $end_date   = $trainingDates[count($trainingDates) - 1];
}
```

- [ ] **Step 2: Verify the derived `$start_date`/`$end_date` still flow through the existing validation block unchanged**

Read `otrs/hrd/api/add_training.php:46-81` (the whitelist validation and `DateTime` checks) — no changes needed there, since `$start_date`/`$end_date` are now correctly set before that block runs regardless of which mode was used.

- [ ] **Step 3: Insert `otrs_training_dates` rows after the successful `otrs_trainings` insert, replacing lines 130-142 (the `$newId` resolution block) with a version that also writes the dates table inside a transaction**

Replace:
```php
    $newId = (int) $conn->lastInsertId();

    // Fallback: lastInsertId() can return 0 if AUTO_INCREMENT is not set on the column.
    // Query the row directly so the response always carries a valid ID.
    if ($newId === 0) {
        $sel = $conn->prepare(
            "SELECT id FROM otrs_trainings
             WHERE name = :name AND start_date = :start AND end_date = :end
             ORDER BY id DESC LIMIT 1"
        );
        $sel->execute([':name' => $name, ':start' => $start_date, ':end' => $end_date]);
        $newId = (int) $sel->fetchColumn();
    }
```
With:
```php
    $newId = (int) $conn->lastInsertId();

    // Fallback: lastInsertId() can return 0 if AUTO_INCREMENT is not set on the column.
    // Query the row directly so the response always carries a valid ID.
    if ($newId === 0) {
        $sel = $conn->prepare(
            "SELECT id FROM otrs_trainings
             WHERE name = :name AND start_date = :start AND end_date = :end
             ORDER BY id DESC LIMIT 1"
        );
        $sel->execute([':name' => $name, ':start' => $start_date, ':end' => $end_date]);
        $newId = (int) $sel->fetchColumn();
    }

    if ($newId > 0 && !empty($trainingDates)) {
        $insDate = $conn->prepare(
            "INSERT INTO otrs_training_dates (training_id, training_date, day_number)
             VALUES (:tid, :date, :dn)"
        );
        foreach ($trainingDates as $i => $d) {
            $insDate->execute([':tid' => $newId, ':date' => $d, ':dn' => $i + 1]);
        }
    }
```

This keeps the existing `if ($newId > 0) { ... success response ... }` block below (line 144 in the original) unchanged.

- [ ] **Step 4: Verify syntax**

Run: `php -l otrs/hrd/api/add_training.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual functional test**

Using the browser (logged in as HRD, once Task 6 wires up the frontend) or a raw POST via `curl` with a valid session cookie + CSRF token: submit `training_dates[]=2026-08-25&training_dates[]=2026-08-27` plus the other required fields, omitting `start_date`/`end_date` (or send anything — they get overridden). Confirm:
- `otrs_trainings` row has `start_date = 2026-08-25`, `end_date = 2026-08-27`.
- `otrs_training_dates` has exactly 2 rows for that `training_id`: `(2026-08-25, day_number=1)`, `(2026-08-27, day_number=2)`.
- Submitting without `training_dates[]` (only `start_date`/`end_date`) behaves exactly as before — zero rows in `otrs_training_dates`.

- [ ] **Step 6: Commit**

```bash
git add otrs/hrd/api/add_training.php
git commit -m "feat(otrs): add_training.php accepts non-consecutive training_dates[]"
```

---

## Task 4: Backend — `update_training.php` accepts custom dates and supports reverting to legacy mode

**Files:**
- Modify: `otrs/hrd/api/update_training.php`

**Interfaces:**
- Consumes: POST field `training_dates[]`, same format as Task 3.
- Produces: replaces `otrs_training_dates` rows for the training (delete-then-conditional-insert as one shared path, so toggling custom mode off on save correctly reverts to legacy range mode).

- [ ] **Step 1: Add the same date-array collection/derivation block used in Task 3, placed after the existing input block (after line 44, before the whitelist validation at line 50)**

```php
$trainingDatesRaw = $_POST['training_dates'] ?? [];
$trainingDates    = [];
if (is_array($trainingDatesRaw) && !empty($trainingDatesRaw)) {
    foreach ($trainingDatesRaw as $d) {
        $d = trim((string)$d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) &&
            checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) {
            $trainingDates[$d] = $d;
        }
    }
    $trainingDates = array_values($trainingDates);
    sort($trainingDates);
}

if (!empty($trainingDates)) {
    $start_date = $trainingDates[0];
    $end_date   = $trainingDates[count($trainingDates) - 1];
}
```

- [ ] **Step 2: Replace the single `$stmt->execute([...])` call (lines 136-156) with a transaction that also rewrites `otrs_training_dates`**

Replace:
```php
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':name'          => $name,
        ':start_date'    => $start_date,
        ':end_date'      => $end_date,
        ':link'          => $link,
        ':deadline'      => $deadline,
        ':location'      => $location,
        ':fee'           => $fee,
        ':mode'          => $mode,
        ':type'          => $training_type,
        ':status_trng'   => $status_trng,
        ':target'        => $target,
        ':level'         => $level,
        ':slots'         => $slots,
        ':no_hrs'        => $no_hrs,
        ':description'   => $description,
        ':status'        => $status,
        ':force_display' => $force_display,
        ':id'            => $trainingId,
    ]);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Training updated successfully.',
    ]);
```
With:
```php
    $conn->beginTransaction();

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':name'          => $name,
        ':start_date'    => $start_date,
        ':end_date'      => $end_date,
        ':link'          => $link,
        ':deadline'      => $deadline,
        ':location'      => $location,
        ':fee'           => $fee,
        ':mode'          => $mode,
        ':type'          => $training_type,
        ':status_trng'   => $status_trng,
        ':target'        => $target,
        ':level'         => $level,
        ':slots'         => $slots,
        ':no_hrs'        => $no_hrs,
        ':description'   => $description,
        ':status'        => $status,
        ':force_display' => $force_display,
        ':id'            => $trainingId,
    ]);

    // Replace otrs_training_dates for this training: always delete first, so
    // toggling custom mode OFF (no training_dates[] submitted) correctly
    // reverts the training to legacy range mode.
    $conn->prepare("DELETE FROM otrs_training_dates WHERE training_id = :tid")
         ->execute([':tid' => $trainingId]);

    if (!empty($trainingDates)) {
        $insDate = $conn->prepare(
            "INSERT INTO otrs_training_dates (training_id, training_date, day_number)
             VALUES (:tid, :date, :dn)"
        );
        foreach ($trainingDates as $i => $d) {
            $insDate->execute([':tid' => $trainingId, ':date' => $d, ':dn' => $i + 1]);
        }
    }

    $conn->commit();

    echo json_encode([
        'status'  => 'success',
        'message' => 'Training updated successfully.',
    ]);
```

- [ ] **Step 3: Wrap the whole database operation in a rollback-on-failure**

The existing `catch (PDOException $e)` block at the bottom already wraps the whole `try` block (lines 95-166), so an exception thrown after `beginTransaction()` will skip `commit()` — add a rollback there. Modify the catch block:

```php
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('update_training.php PDO error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred. Please try again.']);
}
```

- [ ] **Step 4: Verify syntax**

Run: `php -l otrs/hrd/api/update_training.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual functional test**

- Edit an existing legacy training, submit with `training_dates[]=2026-09-01&training_dates[]=2026-09-03` → confirm `start_date`/`end_date` update to `2026-09-01`/`2026-09-03` and `otrs_training_dates` gets exactly 2 rows.
- Edit that same (now custom-mode) training again, this time submitting no `training_dates[]` (plain start/end only) → confirm `otrs_training_dates` rows for it are deleted (back to legacy mode) and `start_date`/`end_date` are whatever was submitted in the plain inputs.
- Edit an untouched legacy training with only `start_date`/`end_date` (as today) → confirm zero behavior change: no rows ever appear in `otrs_training_dates`.

- [ ] **Step 6: Commit**

```bash
git add otrs/hrd/api/update_training.php
git commit -m "feat(otrs): update_training.php supports custom dates and legacy revert"
```

---

## Task 5: Backend — `fetch_data.php` returns custom dates for Edit modal

**Files:**
- Modify: `otrs/hrd/api/fetch_data.php`

**Interfaces:**
- Produces: adds a `training_dates` key (array of `Y-m-d` strings, empty array for legacy trainings) to the JSON response, which Task 7's Edit modal populate logic consumes to pre-fill the custom-dates picker and pre-enable the toggle.

- [ ] **Step 1: Add a query for the training's custom dates after the existing `$stmt->execute(...)` / `$row = $stmt->fetch(...)` block (after line 63)**

```php
    $stmtDates = $conn->prepare(
        "SELECT training_date FROM otrs_training_dates WHERE training_id = :id ORDER BY training_date ASC"
    );
    $stmtDates->execute([':id' => (int)$trainingId]);
    $row['training_dates'] = array_column($stmtDates->fetchAll(PDO::FETCH_ASSOC), 'training_date');
```

- [ ] **Step 2: Verify syntax**

Run: `php -l otrs/hrd/api/fetch_data.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual functional test**

Call `fetch_data.php` (via the Edit button in the UI, or a direct POST with a valid session+CSRF) for a training with rows in `otrs_training_dates` → confirm `training_dates` in the response is a non-empty array of `Y-m-d` strings matching those rows. Call it for a legacy training → confirm `training_dates: []`.

- [ ] **Step 4: Commit**

```bash
git add otrs/hrd/api/fetch_data.php
git commit -m "feat(otrs): fetch_data.php returns custom training_dates"
```

---

## Task 6: Frontend — Add Training modal custom-dates UI

**Files:**
- Modify: `otrs/hrd/index_manage_trainings.php` (Add modal markup ~L430-439, Add submit handler ~L1513-1552, `autoCalcHours` wiring ~L1969-1974)

**Interfaces:**
- Consumes: none new (pure frontend).
- Produces: on submit, `FormData` includes `training_dates[]` entries when custom mode is enabled, and the plain `start_date`/`end_date` inputs are left blank (server derives them) — consumed by Task 3's `add_training.php`.

- [ ] **Step 1: Add the toggle + custom date list container in the modal markup, replacing lines 430-439**

Replace:
```html
                <div class="row g-2 mb-2">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="start_date" required>
                  </div>
                  <div class="col-6">
                    <label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="end_date" required>
                  </div>
                </div>
```
With:
```html
                <div class="row g-2 mb-2" id="addRangeDatesRow">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="start_date" id="addStartDate" required>
                  </div>
                  <div class="col-6">
                    <label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="end_date" id="addEndDate" required>
                  </div>
                </div>

                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" role="switch" id="addCustomDatesToggle">
                  <label class="form-check-label" for="addCustomDatesToggle">
                    Custom (non-consecutive) dates
                  </label>
                </div>

                <div class="mb-2 d-none" id="addCustomDatesWrap">
                  <label class="form-label fw-semibold">Training Dates <span class="text-danger">*</span></label>
                  <div id="addCustomDatesList" class="d-flex flex-column gap-2 mb-2"></div>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="addCustomDateBtn">
                    <i class="bi bi-plus-circle"></i> Add Date
                  </button>
                  <div class="form-text">Pick every actual training day. Days not listed here (e.g. a gap between two sessions) are not counted as training days.</div>
                </div>
```

- [ ] **Step 2: Add the toggle/list JS logic near the other Add-modal JS (insert immediately before the `AUTO-DEADLINE` section comment at line 1930, so it's defined before `autoCalcHours` is wired up below it)**

```javascript
    // ══════════════════════════════════════════════════════════════
    // CUSTOM (NON-CONSECUTIVE) DATES — Add modal
    // ══════════════════════════════════════════════════════════════
    function buildCustomDateRow(container, value = '') {
      const row = document.createElement('div');
      row.className = 'input-group input-group-sm';
      row.innerHTML = `
        <input type="date" class="form-control custom-date-input" value="${value}" required>
        <button type="button" class="btn btn-outline-danger remove-custom-date"><i class="bi bi-x-lg"></i></button>
      `;
      row.querySelector('.remove-custom-date').addEventListener('click', () => row.remove());
      container.appendChild(row);
      return row;
    }

    function getCustomDates(listEl) {
      return Array.from(listEl.querySelectorAll('.custom-date-input'))
        .map(i => i.value)
        .filter(v => v);
    }

    function wireCustomDatesToggle(toggleEl, rangeRowEl, wrapEl, listEl, addBtnEl, seedFromRange = null) {
      addBtnEl.addEventListener('click', () => buildCustomDateRow(listEl));

      toggleEl.addEventListener('change', function () {
        const on = this.checked;
        wrapEl.classList.toggle('d-none', !on);
        rangeRowEl.classList.toggle('d-none', on);
        if (on && listEl.children.length === 0) {
          if (seedFromRange) {
            seedFromRange().forEach(d => buildCustomDateRow(listEl, d));
          } else {
            buildCustomDateRow(listEl);
          }
        }
      });
    }

    wireCustomDatesToggle(
      document.getElementById('addCustomDatesToggle'),
      document.getElementById('addRangeDatesRow'),
      document.getElementById('addCustomDatesWrap'),
      document.getElementById('addCustomDatesList'),
      document.getElementById('addCustomDateBtn')
    );
```

- [ ] **Step 3: Wire the Add submit handler to send `training_dates[]` when custom mode is on — modify the submit handler at lines 1513-1552**

Replace the try block's opening (`const fd = new FormData(this);`) with logic that appends dates and clears the plain inputs when custom mode is active:

```javascript
      try {
        const fd = new FormData(this);
        fd.set('fee', stripFee(fd.get('fee')));

        if (document.getElementById('addCustomDatesToggle').checked) {
          const dates = getCustomDates(document.getElementById('addCustomDatesList'));
          if (dates.length === 0) {
            Swal.fire('Error', 'Add at least one training date.', 'error');
            return;
          }
          fd.delete('start_date');
          fd.delete('end_date');
          dates.forEach(d => fd.append('training_dates[]', d));
        }

        const res = await postJSON('api/add_training.php', fd);
```
(The remainder of the handler, from `if (res.status !== 'success') { ... }` onward, is unchanged.)

- [ ] **Step 4: Make `required` on the plain Start/End inputs conditional so custom mode can submit without them**

The native `required` attribute on `#addStartDate`/`#addEndDate` will block form submission (HTML5 validation) when they're hidden-but-empty in custom mode. Add this inside the `toggleEl.addEventListener('change', ...)` callback from Step 2, right after the `wrapEl.classList.toggle(...)` line:

```javascript
        document.getElementById('addStartDate').required = !on;
        document.getElementById('addEndDate').required   = !on;
```

- [ ] **Step 5: Verify syntax**

Run: `php -l otrs/hrd/index_manage_trainings.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Manual browser test**

Start the local server, log in as an HRD user, open Manage Trainings → Publish Training. Toggle "Custom (non-consecutive) dates" on → confirm the Start/End row hides, one empty date row appears, "Add Date" adds more rows, the X button removes a row. Fill in name/location/etc. plus two non-consecutive dates (e.g. 2026-08-25, 2026-08-27), submit → confirm success toast and the new row appears in the trainings table with the correct date range display. Toggle off and submit a normal training → confirm unchanged behavior (no regression).

- [ ] **Step 7: Commit**

```bash
git add otrs/hrd/index_manage_trainings.php
git commit -m "feat(otrs): Add Training modal supports custom non-consecutive dates"
```

---

## Task 7: Frontend — Edit Training modal custom-dates UI

**Files:**
- Modify: `otrs/hrd/index_manage_trainings.php` (Edit modal markup ~L595-604, populate logic ~L1357-1358, Edit submit handler ~L1396-1507)

**Interfaces:**
- Consumes: `data.training_dates` from Task 5's `fetch_data.php` response.
- Produces: on submit, `FormData` includes `training_dates[]` when custom mode is on (consumed by Task 4's `update_training.php`); omits it (and reverts) when toggled off.

- [ ] **Step 1: Add the same toggle + list markup to the Edit modal, replacing lines 595-604**

Replace:
```html
                <div class="row g-2 mb-2">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" class="form-control" id="editStartDate" name="editStartDate" required>
                  </div>
                  <div class="col-6">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" class="form-control" id="editEndDate" name="editEndDate" required>
                  </div>
                </div>
```
With:
```html
                <div class="row g-2 mb-2" id="editRangeDatesRow">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" class="form-control" id="editStartDate" name="editStartDate" required>
                  </div>
                  <div class="col-6">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" class="form-control" id="editEndDate" name="editEndDate" required>
                  </div>
                </div>

                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" role="switch" id="editCustomDatesToggle">
                  <label class="form-check-label" for="editCustomDatesToggle">
                    Custom (non-consecutive) dates
                  </label>
                </div>

                <div class="mb-2 d-none" id="editCustomDatesWrap">
                  <label class="form-label fw-semibold">Training Dates <span class="text-danger">*</span></label>
                  <div id="editCustomDatesList" class="d-flex flex-column gap-2 mb-2"></div>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="editCustomDateBtn">
                    <i class="bi bi-plus-circle"></i> Add Date
                  </button>
                  <div class="form-text">Pick every actual training day. Days not listed here are not counted as training days.</div>
                </div>
```

- [ ] **Step 2: Wire the toggle for the Edit modal, reusing the shared `buildCustomDateRow`/`getCustomDates`/`wireCustomDatesToggle` helpers from Task 6 Step 2 — add right after that Task 6 block (still before the `AUTO-DEADLINE` section)**

```javascript
    // Edit modal: toggling custom-on with an empty list seeds from the
    // current start/end range so the admin only has to remove gap days.
    function seedEditDatesFromRange() {
      const s = document.getElementById('editStartDate').value;
      const e = document.getElementById('editEndDate').value || s;
      if (!s) return [];
      const dates = [];
      const cur = new Date(s + 'T00:00:00');
      const last = new Date(e + 'T00:00:00');
      while (cur <= last) {
        dates.push(cur.toISOString().slice(0, 10));
        cur.setDate(cur.getDate() + 1);
      }
      return dates;
    }

    wireCustomDatesToggle(
      document.getElementById('editCustomDatesToggle'),
      document.getElementById('editRangeDatesRow'),
      document.getElementById('editCustomDatesWrap'),
      document.getElementById('editCustomDatesList'),
      document.getElementById('editCustomDateBtn'),
      seedEditDatesFromRange
    );

    document.getElementById('editCustomDatesToggle').addEventListener('change', function () {
      document.getElementById('editStartDate').required = !this.checked;
      document.getElementById('editEndDate').required   = !this.checked;
    });
```

- [ ] **Step 3: Populate the Edit modal's custom-dates state when opening, right after line 1358 (`document.getElementById('editEndDate').value = data.end_date;`)**

```javascript
        // Custom (non-consecutive) dates
        const editCustomList   = document.getElementById('editCustomDatesList');
        const editCustomToggle = document.getElementById('editCustomDatesToggle');
        editCustomList.innerHTML = '';
        const hasCustomDates = Array.isArray(data.training_dates) && data.training_dates.length > 0;
        editCustomToggle.checked = hasCustomDates;
        document.getElementById('editCustomDatesWrap').classList.toggle('d-none', !hasCustomDates);
        document.getElementById('editRangeDatesRow').classList.toggle('d-none', hasCustomDates);
        document.getElementById('editStartDate').required = !hasCustomDates;
        document.getElementById('editEndDate').required   = !hasCustomDates;
        if (hasCustomDates) {
          data.training_dates.forEach(d => buildCustomDateRow(editCustomList, d));
        }
```

- [ ] **Step 4: Wire the Edit submit handler to send `training_dates[]` when custom mode is on — modify the handler starting at line 1396, right after `const fd = new FormData(this);` (line 1407)**

```javascript
        const fd  = new FormData(this);
        // Normalise fee — strip formatting before sending to server
        fd.set('editFee', stripFee(fd.get('editFee')));

        if (document.getElementById('editCustomDatesToggle').checked) {
          const dates = getCustomDates(document.getElementById('editCustomDatesList'));
          if (dates.length === 0) {
            Swal.fire('Error', 'Add at least one training date.', 'error');
            submitBtn.disabled = false;
            submitBtn.classList.remove('loading');
            submitBtn.innerHTML = '<i class="bi bi-save"></i><span class="save-label"> Save Changes</span>';
            return;
          }
          fd.delete('editStartDate');
          fd.delete('editEndDate');
          dates.forEach(d => fd.append('training_dates[]', d));
        }

        const res = await postJSON('api/update_training.php', fd);
```

Note the field names sent to the server for the range inputs are `editStartDate`/`editEndDate` (per `update_training.php:29-30`), not `start_date`/`end_date` — only `fd.delete()` the ones actually present in the form (`editStartDate`, `editEndDate`); `training_dates[]` is a new field name shared with `add_training.php`.

- [ ] **Step 5: Verify syntax**

Run: `php -l otrs/hrd/index_manage_trainings.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Manual browser test**

- Open Edit on a legacy (range-only) training → confirm toggle is off, plain Start/End show current values. Toggle custom on → confirm the date list pre-populates with every date in the current range. Remove the gap day (e.g. Aug 26), save → confirm success, and re-opening Edit shows the toggle on with exactly the 2 remaining dates.
- Open Edit on that same training again, toggle custom off, save → confirm it reverts to a plain range and `otrs_training_dates` rows are gone (verify via DB or by re-opening Edit and seeing the toggle off again).
- Open Edit on an untouched legacy training and save without touching the toggle → confirm zero behavior change from before this feature existed.

- [ ] **Step 7: Commit**

```bash
git add otrs/hrd/index_manage_trainings.php
git commit -m "feat(otrs): Edit Training modal supports custom non-consecutive dates"
```

---

## Task 8: Refactor `fetch_attendance.php` to use the shared helper

**Files:**
- Modify: `otrs/hrd/api/fetch_attendance.php`

**Interfaces:**
- Consumes: `getTrainingDateStrings(PDO $conn, int $trainingId): array` from Task 2.
- Produces: unchanged JSON response shape (`total_days`, `training_dates`, `eval_days`, `participants`) — only the internal date-computation source changes.

- [ ] **Step 1: Require the shared helper — add after line 33 (`require_once __DIR__ . '/../../../includes/bootstrap.php';`)**

```php
require_once __DIR__ . '/../inc/training_dates.php';
```

- [ ] **Step 2: Replace the training-dates lookup — change lines 51-62**

Replace:
```php
    // ── Training dates ─────────────────────────────────────────────
    $stmtT = $conn->prepare("SELECT start_date, end_date FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$training) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Training not found.']);
        exit;
    }

    $trainingDates = getTrainingDates($conn, $trainingId, $training['start_date'], $training['end_date']);
    $totalDays     = count($trainingDates);
```
With:
```php
    // ── Training dates ─────────────────────────────────────────────
    $stmtT = $conn->prepare("SELECT id FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->execute([':id' => $trainingId]);
    if (!$stmtT->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Training not found.']);
        exit;
    }

    $trainingDates = getTrainingDateStrings($conn, $trainingId);
    $totalDays     = count($trainingDates);
```

- [ ] **Step 3: Delete the now-unused local `getTrainingDates()` function definition at the bottom of the file (lines 183-207)**

Delete the entire block:
```php
/**
 * Returns Y-m-d strings for each training day (excluding suspensions).
 */
function getTrainingDates(PDO $conn, int $trainingId, string $startDate, string $endDate): array
{
    ...
}
```

- [ ] **Step 4: Verify syntax**

Run: `php -l otrs/hrd/api/fetch_attendance.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual functional test**

Call `fetch_attendance.php?training_id=N` for a legacy training with known dates → confirm `total_days` and `training_dates` are byte-identical to what it returned before this change (same day count, same date list). Call it for the custom-mode training created in Task 3/6 → confirm `total_days: 2` and `training_dates` contains only the 2 selected dates, and the attendance grid in `hrd/participants.php` shows exactly 2 day columns.

- [ ] **Step 6: Commit**

```bash
git add otrs/hrd/api/fetch_attendance.php
git commit -m "refactor(otrs): fetch_attendance.php uses shared getTrainingDates helper"
```

---

## Task 9: Refactor `export_attendance.php` to use the shared helper

**Files:**
- Modify: `otrs/hrd/api/export_attendance.php`

**Interfaces:**
- Consumes: `getTrainingDateStrings(PDO $conn, int $trainingId): array` from Task 2.
- Produces: unchanged CSV output shape — only the internal date-computation source changes.

- [ ] **Step 1: Require the shared helper — add after line 9 (`require_once __DIR__ . '/../../../includes/bootstrap.php';`)**

```php
require_once __DIR__ . '/../inc/training_dates.php';
```

- [ ] **Step 2: Replace the training-dates lookup — change lines 23-33**

Replace:
```php
    // ── Training info ──────────────────────────────────────────────
    $stmtT = $conn->prepare("SELECT name, start_date, end_date FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$training) {
        http_response_code(404);
        exit('Training not found.');
    }

    $trainingDates = getAttExportDates($conn, $trainingId, $training['start_date'], $training['end_date']);
    $totalDays     = count($trainingDates);
```
With:
```php
    // ── Training info ──────────────────────────────────────────────
    $stmtT = $conn->prepare("SELECT name FROM otrs_trainings WHERE id = :id LIMIT 1");
    $stmtT->execute([':id' => $trainingId]);
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$training) {
        http_response_code(404);
        exit('Training not found.');
    }

    $trainingDates = getTrainingDateStrings($conn, $trainingId);
    $totalDays     = count($trainingDates);
```

- [ ] **Step 3: Delete the now-unused local `getAttExportDates()` function at the bottom of the file (lines 129-148)**

- [ ] **Step 4: Verify syntax**

Run: `php -l otrs/hrd/api/export_attendance.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual functional test**

Download the CSV export for a legacy training → confirm identical column headers/day count as before. Download for the custom-mode training → confirm only 2 "Day N (date)" columns for the 2 selected dates.

- [ ] **Step 6: Commit**

```bash
git add otrs/hrd/api/export_attendance.php
git commit -m "refactor(otrs): export_attendance.php uses shared getTrainingDates helper"
```

---

## Task 10: Refactor `eval_get_smes.php` to use the shared helper

**Files:**
- Modify: `otrs/api/eval_get_smes.php`

**Interfaces:**
- Consumes: `getTrainingDateStrings(PDO $conn, int $trainingId): array` from Task 2.
- Produces: unchanged JSON response shape — only the internal date-computation source changes.

- [ ] **Step 1: Require the shared helper — add after line 12 (`require_once __DIR__ . '/../../includes/connect.php';`)**

```php
require_once __DIR__ . '/../hrd/inc/training_dates.php';
```

- [ ] **Step 2: Replace the inline range-loop — change lines 64-79**

Replace:
```php
    // Build training dates
    $stmtSusp = $conn->prepare("SELECT suspended_date FROM otrs_training_suspensions WHERE training_id = :tid");
    $stmtSusp->execute([':tid' => $trainingId]);
    $suspended = array_column($stmtSusp->fetchAll(PDO::FETCH_ASSOC), 'suspended_date');

    $trainingDates = [];
    if (!empty($reg['start_date']) && $reg['start_date'] !== '0000-00-00') {
        $s   = new DateTime($reg['start_date']);
        $eRaw = (!empty($reg['end_date']) && $reg['end_date'] !== '0000-00-00') ? $reg['end_date'] : $reg['start_date'];
        $e   = new DateTime($eRaw);
        while ($s <= $e) {
            $ds = $s->format('Y-m-d');
            if (!in_array($ds, $suspended, true)) $trainingDates[] = $ds;
            $s->modify('+1 day');
        }
    }
    $totalDays = count($trainingDates) ?: 1;
```
With:
```php
    // Build training dates
    $trainingDates = getTrainingDateStrings($conn, $trainingId);
    $totalDays     = count($trainingDates) ?: 1;
```

- [ ] **Step 3: Verify syntax**

Run: `php -l otrs/api/eval_get_smes.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Manual functional test**

Using a valid `reg_id`/`sig`/`day`/`eval_day` combination (grab one from browser devtools on the f2f/free/online portal evaluation flow for an existing registration), call `eval_get_smes.php` for a legacy training → confirm the SME list/eval_day matches pre-change behavior. Repeat against the custom-mode training created in Task 3/6 → confirm SME day-eligibility filtering now correctly reflects only the 2 real training dates (SMEs assigned to "day 2" resolve to Aug 27, not a non-existent Aug 26).

- [ ] **Step 5: Commit**

```bash
git add otrs/api/eval_get_smes.php
git commit -m "refactor(otrs): eval_get_smes.php uses shared getTrainingDates helper"
```

---

## Task 11: Refactor date-range display formatters (`submission_review.php`, `cert_issue_ajax.php`) to source from the shared helper

**Files:**
- Modify: `otrs/hrd/submission_review.php`
- Modify: `otrs/hrd/api/cert_issue_ajax.php`

**Interfaces:**
- Consumes: `getTrainingDateStrings(PDO $conn, int $trainingId): array` from Task 2.
- Produces: `fmtSegmentedDate` and `buildTrainingDateDisplay` keep their existing return type (a human-readable segmented date string) but take the resolved date list instead of recomputing range-minus-suspensions themselves — this makes both correctly group genuinely non-consecutive custom dates into segments, not just range-with-gaps.

- [ ] **Step 1: In `submission_review.php`, change `fmtSegmentedDate`'s signature and body (lines 122-169) to accept a plain date array instead of start/end/suspensions**

Replace:
```php
/** Segmented date display — splits around suspended/holiday days (mirrors cert_issue_ajax.php). */
function fmtSegmentedDate(string $startDate, string $endDate, array $suspensions): string {
    if (empty($startDate) || $startDate === '0000-00-00') return '—';
    $start = new DateTime($startDate);
    $end   = new DateTime(!empty($endDate) && $endDate !== '0000-00-00' ? $endDate : $startDate);

    if (empty($suspensions)) return fmtDateRange($startDate, $endDate);

    $suspSet = array_flip(array_column($suspensions, 'suspended_date'));
    $days = [];
    $cur  = clone $start;
    while ($cur <= $end) {
        if (!isset($suspSet[$cur->format('Y-m-d')])) $days[] = clone $cur;
        $cur->modify('+1 day');
    }
    if (empty($days)) return fmtDateRange($startDate, $endDate);
```
With:
```php
/** Segmented date display — splits around gaps in the training's actual dates. */
function fmtSegmentedDate(array $trainingDateStrings, string $fallbackStart, string $fallbackEnd): string {
    if (empty($trainingDateStrings)) return fmtDateRange($fallbackStart, $fallbackEnd);

    $days = array_map(fn($d) => new DateTime($d), $trainingDateStrings);
```

Then find every call site of `fmtSegmentedDate(...)` in this file (`grep -n "fmtSegmentedDate(" otrs/hrd/submission_review.php`) and update each call from `fmtSegmentedDate($startDate, $endDate, $suspensions)` to `fmtSegmentedDate(getTrainingDateStrings($conn, $trainingId), $startDate, $endDate)` — the exact variable names at each call site depend on what's in scope there; use `grep -n "fmtSegmentedDate(" otrs/hrd/submission_review.php` to find them and pass the training's actual `$trainingId` variable in scope at each site.

- [ ] **Step 2: Require the shared helper in `submission_review.php`**

Add near the top of the file, alongside its other `require_once` statements:
```php
require_once __DIR__ . '/inc/training_dates.php';
```

- [ ] **Step 3: In `cert_issue_ajax.php`, change `buildTrainingDateDisplay`'s signature and body similarly (lines 79-129+)**

Replace the function signature and the "build list of actual training days" block:
```php
if (!function_exists('buildTrainingDateDisplay')):
function buildTrainingDateDisplay(string $startDate, string $endDate, array $suspendedDates): string
{
    if (empty($startDate) || $startDate === '0000-00-00') return '—';

    $start = new DateTime($startDate);
    $end   = new DateTime(!empty($endDate) && $endDate !== '0000-00-00' ? $endDate : $startDate);

    // ── No suspensions: use existing range format ──────────────────────────
    if (empty($suspendedDates)) {
        return _formatSegment($start, $end, true);
    }

    // ── Build list of actual training days (remove suspended dates) ────────
    $suspSet = array_flip($suspendedDates);
    $days    = [];
    $cur     = clone $start;
    while ($cur <= $end) {
        if (!isset($suspSet[$cur->format('Y-m-d')])) {
            $days[] = clone $cur;
        }
        $cur->modify('+1 day');
    }

    if (empty($days)) return '—';
```
With:
```php
if (!function_exists('buildTrainingDateDisplay')):
function buildTrainingDateDisplay(array $trainingDateStrings, string $fallbackStart, string $fallbackEnd): string
{
    if (empty($trainingDateStrings)) {
        if (empty($fallbackStart) || $fallbackStart === '0000-00-00') return '—';
        $s = new DateTime($fallbackStart);
        $e = new DateTime(!empty($fallbackEnd) && $fallbackEnd !== '0000-00-00' ? $fallbackEnd : $fallbackStart);
        return _formatSegment($s, $e, true);
    }

    $days = array_map(fn($d) => new DateTime($d), $trainingDateStrings);
```

Then find every call site (`grep -n "buildTrainingDateDisplay(" otrs/hrd/api/cert_issue_ajax.php`) and update the call from `buildTrainingDateDisplay($startDate, $endDate, $suspendedDates)` to `buildTrainingDateDisplay(getTrainingDateStrings($conn, $trainingId), $startDate, $endDate)`, requiring the shared helper file near the top of `cert_issue_ajax.php`:
```php
require_once __DIR__ . '/../inc/training_dates.php';
```

- [ ] **Step 4: Verify syntax**

Run: `php -l otrs/hrd/submission_review.php && php -l otrs/hrd/api/cert_issue_ajax.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Manual functional test**

Open `submission_review.php` for a legacy training with existing suspensions → confirm the displayed segmented date text is unchanged from before. Open it for the custom-mode training (Aug 25 + Aug 27) → confirm it now shows two separate date segments (e.g. "August 25 - August 27, 2026" rendered as two parts, not one solid range). Issue a certificate for a participant in the custom-mode training → confirm the certificate's date text correctly shows the non-consecutive dates as two segments, not a 3-day span.

- [ ] **Step 6: Commit**

```bash
git add otrs/hrd/submission_review.php otrs/hrd/api/cert_issue_ajax.php
git commit -m "refactor(otrs): segmented date formatters source dates from shared helper"
```

---

## Task 12: Credit-hours (`no_hrs`) recalculation uses the shared helper

**Files:**
- Modify: `otrs/hrd/api/recalc_hrs.php`
- Modify: `otrs/hrd/api/add_suspension.php`
- Modify: `otrs/hrd/api/delete_suspension.php`

**Interfaces:**
- Consumes: `getTrainingDateStrings(PDO $conn, int $trainingId): array` from Task 2.
- Produces: `no_hrs` computed as `count(getTrainingDateStrings(...)) * 8` for both legacy and custom-mode trainings, replacing the SQL `DATEDIFF(...)` formula, which is wrong once custom dates exist.

- [ ] **Step 1: In `recalc_hrs.php`, replace the single SQL `UPDATE` (lines 33-40) with a PHP-computed value**

Replace:
```php
    $conn->prepare("
        UPDATE otrs_trainings
        SET no_hrs = GREATEST(0,
            (DATEDIFF(end_date, start_date) + 1 -
             (SELECT COUNT(*) FROM otrs_training_suspensions WHERE training_id = :id2)) * 8
        )
        WHERE id = :id
    ")->execute([':id2' => $trainingId, ':id' => $trainingId]);

    $q = $conn->prepare("SELECT no_hrs FROM otrs_trainings WHERE id = :id");
    $q->execute([':id' => $trainingId]);
    echo json_encode(['success' => true, 'new_no_hrs' => (int)$q->fetchColumn()]);
```
With:
```php
    $newHrs = count(getTrainingDateStrings($conn, $trainingId)) * 8;
    $conn->prepare("UPDATE otrs_trainings SET no_hrs = :hrs WHERE id = :id")
         ->execute([':hrs' => $newHrs, ':id' => $trainingId]);

    echo json_encode(['success' => true, 'new_no_hrs' => $newHrs]);
```

Add the require near the top, after `require_once __DIR__ . '/../../../includes/bootstrap.php';` (line 5):
```php
require_once __DIR__ . '/../inc/training_dates.php';
```

- [ ] **Step 2: Apply the identical change to `add_suspension.php` (lines 52-64) and `delete_suspension.php` (lines 42-54)**

In `add_suspension.php`, replace:
```php
    // Recalculate no_hrs = (calendar_days - suspended_count) * 8
    $conn->prepare("
        UPDATE otrs_trainings
        SET no_hrs = GREATEST(0,
            (DATEDIFF(end_date, start_date) + 1 -
             (SELECT COUNT(*) FROM otrs_training_suspensions WHERE training_id = :id2)) * 8
        )
        WHERE id = :id
    ")->execute([':id2' => $trainingId, ':id' => $trainingId]);

    $hrsQ = $conn->prepare("SELECT no_hrs FROM otrs_trainings WHERE id = :id");
    $hrsQ->execute([':id' => $trainingId]);
    $newHrs = (int)$hrsQ->fetchColumn();

    echo json_encode(['success' => true, 'id' => $newId, 'new_no_hrs' => $newHrs]);
```
With:
```php
    // Recalculate no_hrs = actual training days (post-suspension) * 8
    $newHrs = count(getTrainingDateStrings($conn, $trainingId)) * 8;
    $conn->prepare("UPDATE otrs_trainings SET no_hrs = :hrs WHERE id = :id")
         ->execute([':hrs' => $newHrs, ':id' => $trainingId]);

    echo json_encode(['success' => true, 'id' => $newId, 'new_no_hrs' => $newHrs]);
```
Add `require_once __DIR__ . '/../inc/training_dates.php';` after its bootstrap require (line 5).

In `delete_suspension.php`, replace the analogous block (lines 42-54):
```php
    if ($trainingId > 0) {
        // Recalculate no_hrs = (calendar_days - suspended_count) * 8
        $conn->prepare("
            UPDATE otrs_trainings
            SET no_hrs = GREATEST(0,
                (DATEDIFF(end_date, start_date) + 1 -
                 (SELECT COUNT(*) FROM otrs_training_suspensions WHERE training_id = :id2)) * 8
            )
            WHERE id = :id
        ")->execute([':id2' => $trainingId, ':id' => $trainingId]);

        $hrsQ = $conn->prepare("SELECT no_hrs FROM otrs_trainings WHERE id = :id");
        $hrsQ->execute([':id' => $trainingId]);
        $newHrs = (int)$hrsQ->fetchColumn();
        echo json_encode(['success' => true, 'new_no_hrs' => $newHrs]);
    } else {
        echo json_encode(['success' => true]);
    }
```
With:
```php
    if ($trainingId > 0) {
        $newHrs = count(getTrainingDateStrings($conn, $trainingId)) * 8;
        $conn->prepare("UPDATE otrs_trainings SET no_hrs = :hrs WHERE id = :id")
             ->execute([':hrs' => $newHrs, ':id' => $trainingId]);
        echo json_encode(['success' => true, 'new_no_hrs' => $newHrs]);
    } else {
        echo json_encode(['success' => true]);
    }
```
Add `require_once __DIR__ . '/../inc/training_dates.php';` after its bootstrap require (line 5).

- [ ] **Step 3: Verify syntax**

Run: `php -l otrs/hrd/api/recalc_hrs.php && php -l otrs/hrd/api/add_suspension.php && php -l otrs/hrd/api/delete_suspension.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 4: Manual functional test**

On a legacy training with a known day count and 1 existing suspension, trigger "Recalc Hours" → confirm `new_no_hrs` is identical to before this change (`(days - suspensions) * 8`). On the custom-mode training (2 dates, Aug 25 + Aug 27), trigger recalc → confirm `new_no_hrs = 16` (2 days × 8), not `24` (which the old DATEDIFF formula would have produced for a 3-day span). Suspend Aug 25 on that training → confirm `new_no_hrs` drops to `8`. Delete that suspension → confirm it goes back to `16`.

- [ ] **Step 5: Commit**

```bash
git add otrs/hrd/api/recalc_hrs.php otrs/hrd/api/add_suspension.php otrs/hrd/api/delete_suspension.php
git commit -m "fix(otrs): credit-hours calc uses shared getTrainingDates helper, correct for custom dates"
```

---

## Task 13: `participants.php` and `evaluation_results.php` day-count fix

**Files:**
- Modify: `otrs/hrd/participants.php`
- Modify: `otrs/hrd/evaluation_results.php`

**Interfaces:**
- Consumes: `getTrainingDateStrings(PDO $conn, int $trainingId): array` from Task 2.
- Produces: `$totalTrDays` becomes `count(getTrainingDateStrings(...))` instead of `DateTime::diff()->days + 1`, fixing the pre-existing bug where these two files (unlike `fetch_attendance.php`) never subtracted suspensions, and now also correctly handling custom dates.

- [ ] **Step 1: In `participants.php`, replace lines 133-136**

Replace:
```php
        // Get total training days
        $sd = new DateTime($tRow['start_date']);
        $ed = (!empty($tRow['end_date']) && $tRow['end_date'] !== '0000-00-00') ? new DateTime($tRow['end_date']) : clone $sd;
        $totalTrDays = (int)$ed->diff($sd)->days + 1;
```
With:
```php
        // Get total training days (custom dates if set, else range minus suspensions)
        $totalTrDays = count(getTrainingDateStrings($conn, (int)$trainingId));
```

Add `require_once __DIR__ . '/inc/training_dates.php';` near the top of the file, alongside its other requires (check `grep -n "require_once" otrs/hrd/participants.php` for the existing require block to place it next to).

- [ ] **Step 2: In `evaluation_results.php`, replace lines 76-79**

Replace:
```php
            $sd = new DateTime($training['start_date']);
            $ed = (!empty($training['end_date']) && $training['end_date'] !== '0000-00-00')
                ? new DateTime($training['end_date']) : clone $sd;
            $totalTrDays = (int)$ed->diff($sd)->days + 1;
```
With:
```php
            $totalTrDays = count(getTrainingDateStrings($conn, (int)$trainingId));
```

Add `require_once __DIR__ . '/inc/training_dates.php';` near the top of the file, alongside its other requires.

- [ ] **Step 3: Verify syntax**

Run: `php -l otrs/hrd/participants.php && php -l otrs/hrd/evaluation_results.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manual functional test**

Open `participants.php` for a training that has a logged suspension → confirm the SME tab's "last day" / eval-day computation now correctly excludes the suspended day (previously it did not — this is a genuine bug fix, verify the day count dropped by the suspension count compared to before). Open `evaluation_results.php` for the same training → confirm the eval-day filter dropdown options match. Open both pages for the custom-mode training (2 dates) → confirm day options only show "Day 1" / "Day 2", not "Day 3".

- [ ] **Step 5: Commit**

```bash
git add otrs/hrd/participants.php otrs/hrd/evaluation_results.php
git commit -m "fix(otrs): participants.php and evaluation_results.php day count now excludes suspensions and supports custom dates"
```

---

## Task 14: HRD Calendar — one event per date for custom-mode trainings

**Files:**
- Modify: `otrs/hrd/api/fetch_hrd_calendar.php`

**Interfaces:**
- Consumes: `getTrainingDateStrings(PDO $conn, int $trainingId): array` from Task 2.
- Produces: `buildTrainingEvent()` returns either a single event (legacy, unchanged) or an array of single-day events (custom mode) — caller updated to `array_push` however many events come back.

- [ ] **Step 1: Require the shared helper — add after line 11 (`require_once __DIR__ . '/../../../includes/bootstrap.php';`)**

```php
require_once __DIR__ . '/../inc/training_dates.php';
```

- [ ] **Step 2: Change the training-loop caller (lines 82-87) to spread multiple events**

Replace:
```php
    foreach ($trainings as $t) {
        $event = buildTrainingEvent($t);
        if (!empty($event)) {
            $events[] = $event;
        }
    }
```
With:
```php
    foreach ($trainings as $t) {
        foreach (buildTrainingEvents($t, $conn) as $event) {
            $events[] = $event;
        }
    }
```

- [ ] **Step 3: Rename `buildTrainingEvent` to `buildTrainingEvents` and change its return to an array, splitting on custom dates — replace the function (lines 204-270)**

Replace:
```php
/**
 * Build a FullCalendar event object for an otrs_trainings row.
 * Training events are READ-ONLY on the calendar (cannot be edited here).
 */
function buildTrainingEvent(array $t): array
{
    $startRaw = (!empty($t['start_date']) && $t['start_date'] !== '0000-00-00')
                ? $t['start_date']
                : null;

    if (!$startRaw) {
        return [];
    }

    // Add +1 day to the end date so FullCalendar spans the block correctly
    $endRaw = (!empty($t['end_date']) && $t['end_date'] !== '0000-00-00')
              ? date('Y-m-d', strtotime($t['end_date'] . ' +1 day'))
              : $startRaw;

    // Color-code by training status
    $statusLower = strtolower(trim($t['training_status'] ?? ''));
    $statusColorMap = [
        'ongoing'   => '#1565C0',
        'completed' => '#546E7A',
        'cancelled' => '#E53935',
    ];
    $statusColor = isset($statusColorMap[$statusLower]) ? $statusColorMap[$statusLower] : '#2E7D32';

    // For cancelled trainings at row status level
    if (strtolower($t['status'] ?? '') === 'cancelled') {
        $statusColor = '#E53935';
    }

    // Build display date for tooltip
    $displayDate = buildDisplayDate($t['start_date'], $t['end_date']);

    // Fee label
    $feeLabel = ((float)$t['fee'] === 0.0) ? 'Free' : '₱' . number_format((float)$t['fee'], 2);

    // Slots info
    $enrolled = (int)($t['enrolled'] ?? 0);
    $slots    = (int)($t['slots']    ?? 0);
    $slotInfo = $slots > 0 ? "{$enrolled}/{$slots} enrolled" : 'Open';

    return [
        'id'       => 'training_' . $t['id'],
        'title'    => $t['name'],
        'start'    => $startRaw,
        'end'      => $endRaw,
        'allDay'   => true,
        'color'    => $statusColor,
        'textColor'=> '#ffffff',
        'extendedProps' => [
            'source'        => 'training',
            'activity_type' => 'training',
            'training_id'   => (int)$t['id'],
            'display_date'  => $displayDate,
            'location'      => $t['location'],
            'mode'          => $t['mode'],
            'fee'           => $feeLabel,
            'slots'         => $slotInfo,
            'status_trng'   => $t['training_status'],
            'training_type' => $t['training_type'],
            'no_hrs'        => $t['no_hrs'],
        ],
    ];
}
```
With:
```php
/**
 * Build one or more FullCalendar event objects for an otrs_trainings row.
 * Training events are READ-ONLY on the calendar (cannot be edited here).
 * Returns one continuous-block event for legacy (range) trainings, or one
 * single-day event per date for custom (non-consecutive) trainings.
 */
function buildTrainingEvents(array $t, PDO $conn): array
{
    if (empty($t['start_date']) || $t['start_date'] === '0000-00-00') {
        return [];
    }

    $trainingId    = (int)$t['id'];
    $customDates   = getTrainingDateStrings($conn, $trainingId);
    $isCustomMode  = count($customDates) > 0
        && ($customDates !== [$t['start_date']] || $t['start_date'] !== ($t['end_date'] ?? $t['start_date']));

    // A single-date custom training and a single-date legacy training render
    // identically, so only branch into multi-event mode when there's more
    // than one non-consecutive date to actually split on.
    $useCustomEvents = count($customDates) > 1
        && (strtotime($customDates[count($customDates) - 1]) - strtotime($customDates[0]))
           !== (count($customDates) - 1) * 86400;

    $statusLower = strtolower(trim($t['training_status'] ?? ''));
    $statusColorMap = [
        'ongoing'   => '#1565C0',
        'completed' => '#546E7A',
        'cancelled' => '#E53935',
    ];
    $statusColor = isset($statusColorMap[$statusLower]) ? $statusColorMap[$statusLower] : '#2E7D32';
    if (strtolower($t['status'] ?? '') === 'cancelled') {
        $statusColor = '#E53935';
    }

    $displayDate = buildDisplayDate($t['start_date'], $t['end_date']);
    $feeLabel    = ((float)$t['fee'] === 0.0) ? 'Free' : '₱' . number_format((float)$t['fee'], 2);
    $enrolled    = (int)($t['enrolled'] ?? 0);
    $slots       = (int)($t['slots']    ?? 0);
    $slotInfo    = $slots > 0 ? "{$enrolled}/{$slots} enrolled" : 'Open';

    $baseProps = [
        'source'        => 'training',
        'activity_type' => 'training',
        'training_id'   => $trainingId,
        'display_date'  => $displayDate,
        'location'      => $t['location'],
        'mode'          => $t['mode'],
        'fee'           => $feeLabel,
        'slots'         => $slotInfo,
        'status_trng'   => $t['training_status'],
        'training_type' => $t['training_type'],
        'no_hrs'        => $t['no_hrs'],
    ];

    if (!$useCustomEvents) {
        $endRaw = (!empty($t['end_date']) && $t['end_date'] !== '0000-00-00')
                  ? date('Y-m-d', strtotime($t['end_date'] . ' +1 day'))
                  : $t['start_date'];

        return [[
            'id'            => 'training_' . $trainingId,
            'title'         => $t['name'],
            'start'         => $t['start_date'],
            'end'           => $endRaw,
            'allDay'        => true,
            'color'         => $statusColor,
            'textColor'     => '#ffffff',
            'extendedProps' => $baseProps,
        ]];
    }

    $events = [];
    foreach ($customDates as $i => $date) {
        $events[] = [
            'id'            => 'training_' . $trainingId . '_' . ($i + 1),
            'title'         => $t['name'],
            'start'         => $date,
            'end'           => date('Y-m-d', strtotime($date . ' +1 day')),
            'allDay'        => true,
            'color'         => $statusColor,
            'textColor'     => '#ffffff',
            'extendedProps' => $baseProps,
        ];
    }
    return $events;
}
```

- [ ] **Step 4: Verify syntax**

Run: `php -l otrs/hrd/api/fetch_hrd_calendar.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual functional test**

Open the HRD calendar (`hrd/hrd_calendar.php`) for a month containing a legacy training → confirm it still renders as one continuous block, unchanged. Navigate to the month containing the custom-mode training (Aug 25 + Aug 27) → confirm it now renders as two separate single-day blocks with nothing shown on Aug 26.

- [ ] **Step 6: Commit**

```bash
git add otrs/hrd/api/fetch_hrd_calendar.php
git commit -m "feat(otrs): HRD calendar renders custom-date trainings as separate day events"
```

---

## Self-Review Notes

- **Spec coverage:** All spec sections have a task — DB (1), shared helper (2), Add/Edit endpoints (3, 4, 5), Add/Edit UI (6, 7), attendance/export/eval-SME/submission/cert (8-11), credit hours (12), participants/evaluation day-count fix (13), calendar (14).
- **Deviation from the spec's implementation note:** the spec's design doc mentioned flatpickr as an illustrative example; this plan uses a dependency-free native `<input type="date">` list instead, per the Global Constraints rationale (no existing vendoring precedent, avoids introducing a new library into a no-build-step project, avoids a download step this plan can't verify succeeds in an offline execution environment). The user-facing behavior (pick specific dates, add/remove them) is unchanged from the design's intent.
- **Type/name consistency:** `getTrainingDates()` and `getTrainingDateStrings()` (Task 2) are used with identical names/signatures in every consuming task (3-14). The Add/Edit modal JS helper names (`buildCustomDateRow`, `getCustomDates`, `wireCustomDatesToggle`) are defined once in Task 6 and reused as-is in Task 7 since both live in the same `index_manage_trainings.php` file/JS scope.
