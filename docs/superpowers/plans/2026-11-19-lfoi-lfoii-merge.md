# LFO I / LFO II Merged Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any user logged in as LFO I (`lfoi`) or LFO II (`lfoii`) see and act on both offices' participants, with the same privileges they already have on their own office's data — every other office's access is unchanged.

**Architecture:** Add one small helper file (`otrs/inc/office_access.php`) with two pure functions: `otrsAccessibleLocations()` (session type → array of location codes it may touch) and `otrsLocationFilterClause()` (array of codes → a ready-to-interpolate `UPPER(col) IN (...)` SQL fragment + its bound params). Every place that currently scopes a query with `AND UPPER(x.location) = UPPER(:param)` swaps to this IN-clause built from the resolved location group. Display labels, breadcrumbs, and HRD's per-office drill-down (`?location=`) are untouched — only the underlying WHERE/JOIN filters change.

**Tech Stack:** Plain procedural PHP 8, PDO (prepared statements, `ATTR_EMULATE_PREPARES => false` in production), MySQL/MariaDB. No Composer, no test framework in this subtree.

**Spec:** This plan (brainstorming conversation above; no separate design doc file — the short design was approved inline in chat on 2026-11-19).

## Global Constraints

- No PHPUnit/test runner exists in `imis/otrs` — verification is `php -l` (syntax) per edited file, plus a manual logged-in check against local XAMPP (`http://localhost/ongbak/imis/otrs/`) as the acceptance test. This matches the project's existing "no test suites" convention (see `otrs/CLAUDE.md`).
- `EMULATE_PREPARES => false` means named placeholders can never repeat in one statement — generated placeholders use a per-call prefix (`loc0`, `loc1`, …) that cannot collide with a query's other params (`eid`, `tid`, `status`, …).
- Location is always derived from `$_SESSION['type']`, never trusted from request input (see `fetch_co_dropdown.php` comment) — this plan does not change that; it only changes how many values that derived location expands into.
- HRD's existing ability to drill into *one* office at a time via `?location=`/`location` POST field must keep showing exactly that one office — it must never silently become a merged LFO I+II view just because an HRD user happens to type `lfoi`.
- Do not touch display labels, breadcrumbs, URLs, or the `location` dropdown options in the update/replace modals — those still show/set a participant's *actual* single office.
- Follow existing code style: PDO prepared statements only, `htmlspecialchars()` on all output, no new dependencies.

## Review Focus

1. **HRD viewing a single office via `?location=lfoi`** must show only LFO I's rows, never merged with LFO II — tested explicitly in Task 2 and Task 6.
2. **Every non-LFO office** (`bfo`, `wlso`, `slfo`, `sfo`, `esfo`, `nsfo`, `ro6`, `ro7`, `hrd`) must be byte-for-byte unaffected — `otrsAccessibleLocations()` returns a single-element array for them, verified in Task 1.
3. **Empty/absent location** (`$location === ''`, e.g. HRD with no office selected yet) must keep behaving as "no filter" / "zero rows" exactly as today, not throw an `IN ()` SQL error — verified in Task 1 and spot-checked per call site.
4. **Placeholder collisions** — any statement that already uses a `:loc`-prefixed name, or binds other params after the location clause is built, must still execute without `HY093` errors — verified by the `php -l` + manual exercise of each modified endpoint in Tasks 2–5.
5. **Cross-office write actions** (replace, cancel, update, proof-of-payment, bulk email) must succeed for an LFO I user acting on an LFO II participant and vice versa, not just read views — exercised in Task 6.

---

## File Structure

- **Create:** `otrs/inc/office_access.php` — the two helper functions.
- **Modify (page-level, root `otrs/`):** `participants.php`, `index.php`, `unpaid_participants.php`, `proof_payment.php`.
- **Modify (`otrs/api/field-office/`):** `fetch_participant.php`, `update_participant.php`, `delete_proof_payment.php`, `download_proof_payment.php`, `view_proof_payment.php`, `update_proof_payment.php`, `request_cancellation.php`, `replace_participant.php`, `fetch_my_cancellation_requests.php`, `fetch_my_replacements.php`, `fetch_co_dropdown.php`, `send_email-ack.php`, `send_email-adv.php`, `send_email-link.php`.

---

### Task 1: Office-access helper

**Files:**
- Create: `otrs/inc/office_access.php`

**Interfaces:**
- Produces: `otrsAccessibleLocations(string $sessionType): array` — returns `['lfoi','lfoii']` when `$sessionType` (case-insensitive) is `lfoi` or `lfoii`; otherwise returns `[strtolower($sessionType)]`.
- Produces: `otrsLocationFilterClause(string $column, array $locations, string $paramPrefix = 'loc'): array` — returns `['sql' => string, 'params' => array<string,string>]`. `sql` is `"UPPER($column) IN (UPPER(:loc0),UPPER(:loc1),...)"`; `params` maps each generated placeholder to the corresponding location value. Caller must ensure `$locations` is non-empty (undefined/invalid SQL otherwise — by design, since every call site already guards on a non-empty location).

- [ ] **Step 1: Create the helper file**

```php
<?php
function otrsAccessibleLocations(string $sessionType): array
{
    static $groups = [
        'lfoi'  => ['lfoi', 'lfoii'],
        'lfoii' => ['lfoi', 'lfoii'],
    ];
    $sessionType = strtolower($sessionType);
    return $groups[$sessionType] ?? [$sessionType];
}

function otrsLocationFilterClause(string $column, array $locations, string $paramPrefix = 'loc'): array
{
    $placeholders = [];
    $params = [];
    foreach (array_values($locations) as $i => $loc) {
        $key = ":{$paramPrefix}{$i}";
        $placeholders[] = "UPPER($key)";
        $params[$key] = $loc;
    }
    return [
        'sql'    => "UPPER($column) IN (" . implode(',', $placeholders) . ')',
        'params' => $params,
    ];
}
```

- [ ] **Step 2: Lint**

Run: `php -l otrs/inc/office_access.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify behavior with a throwaway CLI check**

Run:
```bash
php -r '
require "otrs/inc/office_access.php";
$assert = function ($actual, $expected, $label) {
    if ($actual !== $expected) { fwrite(STDERR, "FAIL $label: " . var_export($actual, true) . "\n"); exit(1); }
};
$assert(otrsAccessibleLocations("lfoi"),  ["lfoi","lfoii"], "lfoi");
$assert(otrsAccessibleLocations("LFOII"), ["lfoi","lfoii"], "LFOII uppercase");
$assert(otrsAccessibleLocations("bfo"),   ["bfo"], "bfo unaffected");
$assert(otrsAccessibleLocations(""),      [""], "empty passthrough");
$clause = otrsLocationFilterClause("p.location", ["lfoi","lfoii"]);
$assert($clause["sql"], "UPPER(p.location) IN (UPPER(:loc0),UPPER(:loc1))", "clause sql");
$assert($clause["params"], [":loc0"=>"lfoi", ":loc1"=>"lfoii"], "clause params");
echo "OK\n";
'
```
Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add otrs/inc/office_access.php
git commit -m "feat(otrs): add office-access helper for LFO I/II shared visibility"
```

---

### Task 2: Field-office list/detail pages

**Files:**
- Modify: `otrs/participants.php:1-2` (add require), `:88-91`, `:131-134`, `:157-160`, `:192-195`
- Modify: `otrs/index.php:1-2` (add require), `:73-91`, `:112-129`, `:146-149`
- Modify: `otrs/unpaid_participants.php:1-2` (add require), `:107-110`
- Modify: `otrs/proof_payment.php:1-2` (add require), `:88-91`

**Interfaces:**
- Consumes: `otrsAccessibleLocations()`, `otrsLocationFilterClause()` from Task 1.

- [ ] **Step 1: Require the helper in all four files**

Right after `require_once __DIR__ . '/../includes/bootstrap.php';` in each of the four files, add:
```php
require_once __DIR__ . '/inc/office_access.php';
```

- [ ] **Step 2: Resolve `$accessibleLocations` once per file, right after `$location` is computed**

In `participants.php`, `unpaid_participants.php`, `proof_payment.php` (all three derive `$location` the same way, lines 9-12), and in `index.php` (lines 6-9), insert immediately after that block:
```php
$accessibleLocations = ($sessionType === 'hrd')
    ? ($location !== '' ? [$location] : [])
    : otrsAccessibleLocations($location);
```
This keeps HRD's single-office drill-down exactly as-is (Review Focus #1) while FO sessions (including `lfoi`/`lfoii`) get the resolved group.

- [ ] **Step 3: `participants.php` — convert all four location-filtered queries**

For each of the four `if ($location !== '') { ... }` blocks (proof-of-payment count at `:88-91`, main participant query at `:131-134`, payment-status counts at `:157-160`, waitlist at `:192-195`), replace the guard and body with:
```php
if (!empty($accessibleLocations)) {
    $locClause = otrsLocationFilterClause('p.location', $accessibleLocations);
    $<sqlVar>  .= ' AND ' . $locClause['sql'];
    $<paramsVar> += $locClause['params'];
}
```
substituting the block's actual SQL/params variable names (`$popSql`/`$popParams`, `$sql`/`$params`, `$sqlCounts`/`$countParams`, `$wlSql`/`$wlParams`).

- [ ] **Step 4: `unpaid_participants.php` and `proof_payment.php` — same single-site conversion**

Each has exactly one `if ($location !== '') { $sql .= " AND UPPER(p.location) = UPPER(:location)"; $params[':location'] = $location; }` block (`unpaid_participants.php:107-110`, `proof_payment.php:88-91`). Apply the same transform as Step 3.

- [ ] **Step 5: `index.php` — office summary query (`:73-91`)**

Replace:
```php
if ($location && $sessionType !== 'hrd') {
    try {
        $sStmt = $conn->prepare("
            ...
            WHERE  UPPER(p.location) = UPPER(:loc)
              AND  t.training_status NOT IN (...)
              AND  t.status = 'active'
        ");
        $sStmt->execute([':loc' => $location]);
        ...
```
with:
```php
if (!empty($accessibleLocations) && $sessionType !== 'hrd') {
    try {
        $locClause = otrsLocationFilterClause('p.location', $accessibleLocations);
        $sStmt = $conn->prepare("
            ...
            WHERE  {$locClause['sql']}
              AND  t.training_status NOT IN (...)
              AND  t.status = 'active'
        ");
        $sStmt->execute($locClause['params']);
        ...
```
(keep the full SELECT list and the rest of the try/catch unchanged — only the `WHERE` line and the two marked lines move).

- [ ] **Step 6: `index.php` — `renderUserTrainingRows()` (`:107-150`) and its call site (`:559`)**

Change the function to take an array of already-resolved locations instead of re-deriving them, so it stays consistent with the page-level gating from Step 2 (HRD's single-office selection must not expand into a group here either):

1. Change the signature on line 107 from `function renderUserTrainingRows(string $statusFilter, string $locationFilter): void` to `function renderUserTrainingRows(string $statusFilter, array $locationFilters): void`.
2. Immediately before the existing `if (!empty($locationFilter)) { ... } else { ... }`, insert:
```php
$locClause = !empty($locationFilters)
    ? otrsLocationFilterClause('p.location', $locationFilters)
    : null;
```
3. In the `if (!empty($locationFilter))` branch (now gated on `$locClause` instead), replace the join condition `LEFT JOIN otrs_participants AS p ON p.id=r.participant_id AND UPPER(p.location)=UPPER(:location)` with `LEFT JOIN otrs_participants AS p ON p.id=r.participant_id AND {$locClause['sql']}`. This function has two such guards — the one choosing which `$sql` to build (this one) and the one in sub-step 4 below, around the `bindParam` calls — rewrite both from `if (!empty($locationFilter))` to `if ($locClause)`. Leave the `else` branch (no-location case) untouched.
4. Replace:
```php
$stmt->bindParam(':status', $statusFilter);
if (!empty($locationFilter)) $stmt->bindParam(':location', $locationFilter);
$stmt->execute();
```
with:
```php
$stmt->bindParam(':status', $statusFilter);
if ($locClause) {
    foreach ($locClause['params'] as $key => $val) {
        $stmt->bindValue($key, $val);
    }
}
$stmt->execute();
```
5. Update the call site on line 559 from `renderUserTrainingRows($tab['status'], $location);` to `renderUserTrainingRows($tab['status'], $accessibleLocations);` — this passes the already-gated array from Step 2, so HRD's single-office selection stays single and FO sessions (including `lfoi`/`lfoii`) get the resolved group, with no second gating decision inside the function.

- [ ] **Step 7: Lint all four files**

Run: `php -l otrs/participants.php && php -l otrs/index.php && php -l otrs/unpaid_participants.php && php -l otrs/proof_payment.php`
Expected: `No syntax errors detected` ×4

- [ ] **Step 8: Manual check (requires local XAMPP + a training with participants in both LFO I and LFO II)**

Log in as an `lfoi` account, open `index.php` and `participants.php?id=<id>` for a training with participants from both offices — confirm rows from LFO II now appear alongside LFO I's own. Then log in as `hrd` and visit `index.php?location=lfoi` — confirm it still shows LFO I only (Review Focus #1).

- [ ] **Step 9: Commit**

```bash
git add otrs/participants.php otrs/index.php otrs/unpaid_participants.php otrs/proof_payment.php
git commit -m "feat(otrs): merge LFO I/II visibility on training and participant list pages"
```

---

### Task 3: Participant profile & payment-status endpoints

**Files:**
- Modify: `otrs/api/field-office/fetch_participant.php:6` (require), `:27-64`
- Modify: `otrs/api/field-office/update_participant.php:6` (require), `:83-97`
- Modify: `otrs/api/field-office/delete_proof_payment.php:6` (require), `:34-48`
- Modify: `otrs/api/field-office/download_proof_payment.php:4` (require), `:12-23`
- Modify: `otrs/api/field-office/view_proof_payment.php:4` (require), `:12-23`
- Modify: `otrs/api/field-office/update_proof_payment.php:6` (require), `:46-60`

**Interfaces:**
- Consumes: `otrsAccessibleLocations()`, `otrsLocationFilterClause()` from Task 1.

None of these six files has an HRD bypass — each derives `$userLocation = strtolower($_SESSION['type'] ?? '')` directly and scopes a single query. All six get the same transform.

- [ ] **Step 1: Require the helper**

Add `require_once __DIR__ . '/../../inc/office_access.php';` right after each file's `bootstrap.php` require.

- [ ] **Step 2: `fetch_participant.php` (`:27-64`)**

After `$userLocation = strtolower($_SESSION['type'] ?? '');` add `$locClause = otrsLocationFilterClause('p.location', otrsAccessibleLocations($userLocation));`. In the SQL, replace `AND UPPER(p.location) = UPPER(:user_loc)` with `AND {$locClause['sql']}`. Replace:
```php
$stmt->bindValue(':enrollment_id', $enrollmentId, PDO::PARAM_INT);
$stmt->bindValue(':user_loc',      $userLocation);
$stmt->execute();
```
with:
```php
$stmt->bindValue(':enrollment_id', $enrollmentId, PDO::PARAM_INT);
foreach ($locClause['params'] as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
```

- [ ] **Step 3: `update_participant.php` (`:83-97`, the `$chk` query only — leave the later UPDATE/INSERT statements untouched)**

Same transform as Step 2, applied to the `$chk` prepare/bind/execute block. The rest of the file (the actual UPDATE of `otrs_participants`/`otrs_registrations`, audit logging) is unaffected — it already operates on the specific `$participantId`/`$enrollmentId` once the access check passes.

- [ ] **Step 4: `delete_proof_payment.php` (`:34-48`) and `update_proof_payment.php` (`:46-60`)**

Same transform as Step 2, applied to each file's single `bindValue(':user_loc', ...)` query (`$stmt` in `delete_proof_payment.php`, `$fetchStmt` in `update_proof_payment.php`).

- [ ] **Step 5: `download_proof_payment.php` and `view_proof_payment.php` (`:12-23` each, identical shape)**

After `$userLocation = strtolower($_SESSION['type'] ?? '');` add `$locClause = otrsLocationFilterClause('p.location', otrsAccessibleLocations($userLocation));`. Replace `AND UPPER(p.location) = UPPER(:loc)` with `AND {$locClause['sql']}`, and replace `$stmt->execute([':eid' => $enrollmentId, ':loc' => $userLocation]);` with `$stmt->execute([':eid' => $enrollmentId] + $locClause['params']);`.

- [ ] **Step 6: Lint all six files**

Run: `php -l otrs/api/field-office/fetch_participant.php otrs/api/field-office/update_participant.php otrs/api/field-office/delete_proof_payment.php otrs/api/field-office/download_proof_payment.php otrs/api/field-office/view_proof_payment.php otrs/api/field-office/update_proof_payment.php`
Expected: `No syntax errors detected` ×6 (run individually or confirm each line in the combined output)

- [ ] **Step 7: Manual check**

As an `lfoi` user, open an LFO II participant's record via `participants.php` for a shared training (now visible per Task 2), use the update modal to change their payment status, and upload/view/delete a proof-of-payment file for them. All should succeed exactly as they would for an LFO I participant.

- [ ] **Step 8: Commit**

```bash
git add otrs/api/field-office/fetch_participant.php otrs/api/field-office/update_participant.php otrs/api/field-office/delete_proof_payment.php otrs/api/field-office/download_proof_payment.php otrs/api/field-office/view_proof_payment.php otrs/api/field-office/update_proof_payment.php
git commit -m "feat(otrs): extend LFO I/II merge to participant profile and payment endpoints"
```

---

### Task 4: Cancellation & replacement endpoints

**Files:**
- Modify: `otrs/api/field-office/request_cancellation.php:5` (require), `:50-64`
- Modify: `otrs/api/field-office/replace_participant.php:5` (require), `:80-91`
- Modify: `otrs/api/field-office/fetch_my_cancellation_requests.php:5` (require), `:15-45`
- Modify: `otrs/api/field-office/fetch_my_replacements.php:5` (require), `:15-47`

**Interfaces:**
- Consumes: `otrsAccessibleLocations()`, `otrsLocationFilterClause()` from Task 1.

- [ ] **Step 1: Require the helper**

Add `require_once __DIR__ . '/../../inc/office_access.php';` (note: `replace_participant.php` is one directory shallower — confirm its existing bootstrap require path and mirror the same relative depth, i.e. `'/../../inc/office_access.php'` since it already requires bootstrap via `/../../includes/bootstrap.php'`) after each bootstrap require.

- [ ] **Step 2: `request_cancellation.php` (`:50-64`)**

After `$isHrd = ($sessionType === 'hrd');` add `$locClause = otrsLocationFilterClause('p.location', otrsAccessibleLocations($sessionType));`. Replace the query's `(:is_hrd = 1 OR UPPER(p.location) = UPPER(:user_loc))` with `(:is_hrd = 1 OR {$locClause['sql']})`, and replace:
```php
$chk->execute([
    ':eid'      => $enrollmentId,
    ':pid'      => $participantId,
    ':is_hrd'   => $isHrd ? 1 : 0,
    ':user_loc' => $sessionType,
]);
```
with:
```php
$chk->execute([
    ':eid'      => $enrollmentId,
    ':pid'      => $participantId,
    ':is_hrd'   => $isHrd ? 1 : 0,
] + $locClause['params']);
```
Leave `$requestedByLoc` (lines 72-74) and the INSERT into `otrs_cancellation_requests`/`otrs_cancellation_logs` untouched — the audit trail must keep recording the filer's *actual* office, not the group.

- [ ] **Step 3: `replace_participant.php` (`:80-91`)**

After `$replacedByLocation = strtolower($_SESSION['type'] ?? '');` add `$locClause = otrsLocationFilterClause('p.location', otrsAccessibleLocations($replacedByLocation));`. Replace `AND UPPER(p.location) = UPPER(:user_loc)` with `AND {$locClause['sql']}`, and replace:
```php
$check->execute([':eid' => $enrollmentId, ':pid' => $originalParticipantId, ':tid' => $trainingId, ':user_loc' => $replacedByLocation]);
```
with:
```php
$check->execute([':eid' => $enrollmentId, ':pid' => $originalParticipantId, ':tid' => $trainingId] + $locClause['params']);
```
Leave the rest of the file (new-participant INSERT, registration swap, `otrs_replacement_requests` log using `$replacedByLocation`) untouched — same audit-trail reasoning as Step 2.

- [ ] **Step 4: `fetch_my_cancellation_requests.php` (`:15-45`) and `fetch_my_replacements.php` (`:15-47`)**

Both derive `$location` with the same HRD-POST-aware ternary (`:15-18`). Immediately after it, add:
```php
$accessibleLocations = ($sessionType === 'hrd') ? [$location] : otrsAccessibleLocations($location);
```
(Using `[$location]` — not the empty-check used in Task 2 — because these two endpoints already bind `$location` unconditionally today; wrapping even an empty string in a one-element array preserves the exact current "0 rows when HRD hasn't picked an office" behavior without a special case.)

In `fetch_my_cancellation_requests.php`, build `$locClause = otrsLocationFilterClause('cr.requested_by_location', $accessibleLocations);`, replace `WHERE  LOWER(cr.requested_by_location) = :location` with `WHERE  {$locClause['sql']}`, and replace `$stmt->execute([':location' => $location]);` with `$stmt->execute($locClause['params']);`.

In `fetch_my_replacements.php`, build `$locClause = otrsLocationFilterClause('rr.replaced_by_location', $accessibleLocations);`, replace `WHERE  rr.replaced_by_location = :loc` with `WHERE  {$locClause['sql']}`, and replace `$stmt->execute([':loc' => $location]);` with `$stmt->execute($locClause['params']);`.

- [ ] **Step 5: Lint all four files**

Run: `php -l otrs/api/field-office/request_cancellation.php otrs/api/field-office/replace_participant.php otrs/api/field-office/fetch_my_cancellation_requests.php otrs/api/field-office/fetch_my_replacements.php`
Expected: `No syntax errors detected` ×4

- [ ] **Step 6: Manual check**

As an `lfoi` user: submit a cancellation request and a replacement for an LFO II participant (both should now succeed instead of "access denied"); then open `cancellation_requests.php` / `replacement_requests.php` and confirm requests filed by either office now both appear. As `hrd`, confirm selecting a single office still returns only that office's requests (Review Focus #1).

- [ ] **Step 7: Commit**

```bash
git add otrs/api/field-office/request_cancellation.php otrs/api/field-office/replace_participant.php otrs/api/field-office/fetch_my_cancellation_requests.php otrs/api/field-office/fetch_my_replacements.php
git commit -m "feat(otrs): extend LFO I/II merge to cancellation and replacement workflows"
```

---

### Task 5: Collecting-officer dropdown & bulk email senders

**Files:**
- Modify: `otrs/api/field-office/fetch_co_dropdown.php:6` (require), `:22-32`
- Modify: `otrs/api/field-office/send_email-ack.php:6` (require), `:102-121`
- Modify: `otrs/api/field-office/send_email-adv.php:6` (require), `:104-121` (confirm exact line offsets — file mirrors `send_email-ack.php`'s structure)
- Modify: `otrs/api/field-office/send_email-link.php:6` (require), `:102-121`

**Interfaces:**
- Consumes: `otrsAccessibleLocations()`, `otrsLocationFilterClause()` from Task 1.

- [ ] **Step 1: Require the helper**

Add `require_once __DIR__ . '/../../inc/office_access.php';` after each file's bootstrap require.

- [ ] **Step 2: `fetch_co_dropdown.php` (`:22-32`)**

Note this file compares against `otrs_collecting_officer.location`, which is stored uppercase (`$location = strtoupper($_SESSION['type'] ?? '');`), unlike every other file in this plan which lowercases. Keep that: compute `$accessibleLocations = otrsAccessibleLocations(strtolower($_SESSION['type'] ?? ''));` (helper always expects/returns lowercase codes), then `$locClause = otrsLocationFilterClause('location', $accessibleLocations);`. Replace `WHERE  UPPER(location) = UPPER(:location)` with `WHERE  {$locClause['sql']}`, and replace `$stmt->execute([':location' => $location]);` with `$stmt->execute($locClause['params']);`. Delete the now-unused `$location = strtoupper(...)` line.

- [ ] **Step 3: `send_email-ack.php`, `send_email-adv.php`, `send_email-link.php` (each `~:102-121`, identical shape)**

After `$userLocation = strtolower($_SESSION['type'] ?? '');` add `$locClause = otrsLocationFilterClause('p.location', otrsAccessibleLocations($userLocation));`. Inside the per-recipient loop's query, replace `AND  UPPER(p.location) = UPPER(:user_loc)` with `AND  {$locClause['sql']}`, and replace:
```php
$stmtP->bindValue(':eid',      $enrollmentId, PDO::PARAM_INT);
$stmtP->bindValue(':tid',      $trainingId,   PDO::PARAM_INT);
$stmtP->bindValue(':user_loc', $userLocation);
$stmtP->execute();
```
with:
```php
$stmtP->bindValue(':eid', $enrollmentId, PDO::PARAM_INT);
$stmtP->bindValue(':tid', $trainingId,   PDO::PARAM_INT);
foreach ($locClause['params'] as $key => $val) {
    $stmtP->bindValue($key, $val);
}
$stmtP->execute();
```
Build `$locClause` once before the loop (it doesn't change per-recipient) — don't recompute it inside the `foreach`.

- [ ] **Step 4: Lint all four files**

Run: `php -l otrs/api/field-office/fetch_co_dropdown.php otrs/api/field-office/send_email-ack.php otrs/api/field-office/send_email-adv.php otrs/api/field-office/send_email-link.php`
Expected: `No syntax errors detected` ×4

- [ ] **Step 5: Manual check**

As an `lfoi` user: open the update-participant modal for an LFO II participant and confirm the Collecting Officer dropdown now includes officers from both offices; trigger "Ack Letter" / "Zoom Link" / "Advisory" for a shared training's participant list (if the toolbar is visible for your test office) and confirm an LFO II participant receives the email.

- [ ] **Step 6: Commit**

```bash
git add otrs/api/field-office/fetch_co_dropdown.php otrs/api/field-office/send_email-ack.php otrs/api/field-office/send_email-adv.php otrs/api/field-office/send_email-link.php
git commit -m "feat(otrs): extend LFO I/II merge to collecting-officer lookup and bulk email"
```

---

### Task 6: End-to-end verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: the complete behavior from Tasks 1–5.

- [ ] **Step 1: Full regression pass as a non-LFO office**

Log in as any one non-LFO field office (e.g. `bfo`). Walk through: training list → participant list → update a participant → proof-of-payment upload/view/delete → submit a cancellation request → submit a replacement. Confirm every screen and action behaves exactly as before this change (no LFO-style merging, no errors).

- [ ] **Step 2: Full regression pass as HRD**

Log in as `hrd`. Visit `index.php?location=lfoi`, then `index.php?location=lfoii` — confirm each shows only that single office's data, never both. Open `cancellation_requests.php`/`replacement_requests.php` for each office via the location selector and confirm the same single-office scoping (Review Focus #1).

- [ ] **Step 3: Full merged pass as LFO I and LFO II**

Log in as the `lfoi` test account: confirm the training list's office-stat strip, participant lists, cancellation/replacement history, and proof-of-payment screens all show combined LFO I + LFO II data, and that every write action (update, replace, cancel, proof-of-payment, bulk email) succeeds on an LFO II participant. Repeat logged in as the `lfoii` test account, confirming the same in reverse.

- [ ] **Step 4: Record sign-off**

No commit needed for this task — it's manual verification. If any step fails, open a new task against the specific file/line before considering the branch done.
