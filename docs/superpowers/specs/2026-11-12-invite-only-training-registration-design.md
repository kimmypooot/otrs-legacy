# Invite-Only Training Registration

Date: 2026-11-12

## Intent

HRD occasionally runs a Public-Offered training that is not meant to be
publicly browsable — it's arranged for a specific group and only the
people who are handed a link should be able to find it or register for
it. Today every active, non-expired training in `otrs_trainings`
automatically shows up on the public registration page
(`otrs/index.php`, fed by `otrs/api/fetch_trainings_list.php` and
friends) and in the three self-report portals (`otrs/f2f`, `otrs/free`,
`otrs/online`). There's no way to publish a training without it being
visible to everyone, and no concept of a registration-specific access
link anywhere in the codebase (the existing `otrs_claim_tokens` table is
for post-completion certificate claiming — unrelated).

This feature adds an "invite-only" mode to training creation: HRD checks
a box when publishing a Public-Offered training, the system generates a
unique link, and from that point the training is completely absent from
every public listing. The only way to reach it — to see it at all, let
alone register — is that link. Anyone holding the link can use it; it
stays valid for as long as the training itself is open. Registration
through the link follows the same self-service flow every other
participant uses: fill in the form, land `registration_status =
'Confirmed'` with `payment_status = 'pending'` exactly like any other
self-service registrant, then upload proof of payment the normal way.

## Goals

- HRD can mark a training invite-only **only at creation time** (not
  retrofittable onto an existing training), and only for
  `training_type = 'Public-Offered'`.
- An invite-only training never appears in any public listing,
  polling, or self-report-portal endpoint — not degraded/locked, just
  absent, identical to it not existing.
- The HRD-side Manage Trainings list still shows it (badged), and HRD
  can copy its invite link at creation time (shown once in a
  confirmation dialog) and at any later time (a persistent button on
  its row).
- The link is a single durable token per training: no expiry, no
  usage cap, valid until the training itself closes (completes/
  cancelled/past deadline per existing auto-archive logic).
- A visitor who opens the link lands directly on that training's
  registration form (no need to find it in a grid, because it isn't
  in one) and completes the exact same registration → pending →
  proof-of-payment flow as a normal walk-in registrant.
- **Invite-only is a real access control, not cosmetic hiding.** Every
  endpoint that can be reached with a bare training ID (detail, slot
  check, duplicate check, register, proof upload) must independently
  refuse to serve an invite-only training unless the correct token is
  presented. Hiding it from the grid while leaving `?id=47` guessable
  would not meet the stated goal.

## Non-goals / anti-goals

- No per-recipient tokens, no usage limits, no expiry independent of
  the training's own lifecycle.
- No retrofitting invite-only onto an already-published training, and
  no UI to turn it off once set — if HRD gets it wrong, they cancel/
  recreate the training. (This matches "this is a training that is
  not usually being conducted" — a rare, deliberate setup, not a
  toggle meant to be flipped casually.)
- No new self-service registration *mechanism* — it must reuse
  `otrs/api/register_participant.php`, `submit_proof_payment.php`,
  `training-detail.php`, `check_slots.php`, `check_duplication.php`
  as-is wherever possible. Building a parallel registration pipeline
  for invite-only trainings would be a maintenance trap (two systems
  to keep in sync for one concept).
- Not applicable to `Free` or `Congress-Conducted` trainings in this
  pass.
- Failure mode to avoid: a training that's reachable by guessing/
  incrementing its numeric ID even though it's "invite-only." That
  defeats the entire point and must not ship.

## Constraints / context

- `otrs_trainings` lives in `imis/otrs/db/otrs_db_revised.sql`;
  training creation is `imis/otrs/hrd/api/add_training.php`, invoked
  from the `#addTrainingModal` form in
  `imis/otrs/hrd/index_manage_trainings.php`. This is the IMIS-side,
  logged-in HRD area (bootstrap via `includes/bootstrap.php`, CSRF
  required on all mutating calls).
- The actual public registration surface is **not** inside
  `imis/otrs/` — it's the sibling tree at `public_html/otrs/`, with its
  own `connect.php` (`Database::getInstance()`), its own
  `api/includes/bootstrap.php` + `Security` class (CORS, security
  headers, IP/fingerprint helpers), its own `RateLimit` (backed by
  `otrs_rate_limits`), and its own `CSRF` class. No IMIS session, no
  login — this is where anyone with the link must land.
- Relevant existing public endpoints, none of which currently know
  about any "visibility" concept beyond `status='active'` /
  `training_status`:
  - `otrs/api/fetch_trainings_list.php` — lightweight polling list
    feeding the grid on `otrs/index.php`.
  - `otrs/api/fetch_trainings.php`, `fetch_trainings_list.php` style
    queries also exist per-portal: `otrs/f2f/api/get_trainings.php`,
    and presumably equivalent ones in `otrs/free/`, `otrs/online/`
    (same `LOWER(t.type) = 'public-offered'` shape — verify both
    during implementation).
  - `otrs/api/training-detail.php` — `GET ?id=` single-training detail
    for the registration form; no visibility filter today beyond
    `status='active'`.
  - `otrs/api/check_slots.php`, `otrs/api/check_duplication.php` —
    take a raw `trainingId` from the client.
  - `otrs/api/register_participant.php` — the actual registration
    write; takes `trainingId` from the submitted form.
  - `otrs/api/submit_proof_payment.php` — takes `participantId` +
    training name/date (resolves `training_id` server-side by name +
    start_date match, not by ID — needs checking against the
    invite-only training specifically).
- `otrs/index.php` currently has **no deep-link support at all** — a
  training is only ever selected by clicking a card in the rendered
  grid. This needs to be added for invite-only trainings to be
  reachable.
- Token convention already established for a similar purpose:
  `otrs/claim/services/TokenService.php` generates
  `bin2hex(random_bytes(32))` (64 hex chars) for `otrs_claim_tokens`.
  The new invite token should follow the same generation convention,
  stored on `otrs_trainings` instead of a separate token table (there
  is exactly one token per training, not one per use, so a column is
  simpler than a table here).
- Verified directly in `otrs/api/register_participant.php`: every
  self-service registration hardcodes `registration_status =
  'Confirmed'` on insert (no review step), while `payment_status` is
  set to `'pending'` (or `'free'` for free trainings) — that's the
  field the proof-of-payment step actually keys off. Invite-only
  registrants get this exact same treatment, no special case.
- Verified the public-visibility predicate used by
  `otrs/api/fetch_trainings_list.php`:
  `status = 'active' AND training_status NOT IN ('Rescheduled',
  'Cancelled', 'Completed') AND (force_display = 1 OR end_date IS
  NULL OR end_date >= CURDATE())`. This is the authoritative
  "still open" check — the invite-token gate on the direct-link path
  and on every ID-based endpoint should reuse this exact predicate
  (plus `is_invite_only = 1` and token match), not a separately
  invented rule, so "valid until the training closes" always means
  the same thing as "would still appear in the normal list if it
  weren't invite-only."

## Decisions made so far (by the user, in conversation)

- Who registers: the invitee self-registers (not staff on their
  behalf) — this is what makes it a public, no-login flow.
- Applies to `Public-Offered` only, and only settable at creation.
- Invite-only trainings are completely invisible in every public
  list, not merely locked/greyed.
- The link has no expiry and no usage cap; it's valid until the
  training closes.
- Payment handling is identical to the normal flow: register → land
  pending → upload proof of payment afterward via the existing
  upload step.
- Registration status on self-registering via the link: identical to
  the normal self-service flow — `registration_status = 'Confirmed'`
  immediately, `payment_status = 'pending'` driving the payment step.
  (Earlier in the conversation this was framed as "Pending, same as
  the normal flow" based on a wrong assumption about what the normal
  flow does; corrected after checking the actual code and confirmed
  with the user — no special review step for invite-only
  registrants.)
- HRD gets the link twice: once in a one-time confirmation dialog
  right after creating the training, and persistently via a
  "copy link" action on that training's row afterward.

## What's left to the builder

- **Schema**: add two columns to `otrs_trainings` —
  `is_invite_only TINYINT(1) NOT NULL DEFAULT 0` and
  `invite_token VARCHAR(64) DEFAULT NULL` (unique index on
  `invite_token` where not null). New migration file in
  `imis/otrs/db/`, following the existing `add_*.sql` naming/format
  (see `add_waitlist.sql` for the most recent example of style).
- **Admin UI** (`hrd/index_manage_trainings.php` +
  `hrd/api/add_training.php`):
  - Add an "Invite-only" checkbox to `#addTrainingModal`, visible/
    enabled only when the `Public-Offered` radio is selected (JS
    toggle, mirroring how the existing type radios already drive
    conditional UI elsewhere on that form).
  - `add_training.php`: when the flag is set and type is
    `Public-Offered`, generate `bin2hex(random_bytes(32))`, store both
    columns on insert, and return the full invite URL in the JSON
    response (`status: 'success'`) alongside the existing `id`.
  - On success, show the link in a one-time confirmation (copy-to-
    clipboard) instead of (or in addition to) the existing success
    toast.
  - In `renderTrainingRow()`, add a badge (e.g. a lock icon next to
    the type badge) when `is_invite_only`, and a "Copy Invite Link"
    row-action button alongside the existing edit/suspend/reschedule/
    delete buttons — needs a tiny new endpoint or can embed the token
    in the row's `data-*` attributes since HRD is already
    authenticated and this isn't sensitive to the page's own viewers.
  - Decide exactly what the public URL looks like, e.g.
    `https://otrs.cscro8.com/invite.php?token=<64-hex>` or
    `index.php?invite=<64-hex>` — pick based on whichever is less
    invasive to retrofit into `index.php`'s existing structure.
- **Public listing exclusion** — add `AND is_invite_only = 0` (or
  equivalent) to every query that lists multiple trainings for public
  consumption: `otrs/api/fetch_trainings_list.php`,
  `otrs/f2f/api/get_trainings.php`, and the equivalent fetch-trainings
  endpoints in `otrs/free/` and `otrs/online/` (confirm exact file
  names during implementation — same pattern, same fix).
- **Direct-link access path** — since `otrs/index.php` has no
  deep-linking today, add handling for the invite token: on page
  load, if a valid token is present in the URL and matches an
  invite-only training that's still open, skip the grid and open that
  one training directly in the registration form (reusing the
  existing form/JS, just pre-populated instead of card-click-
  populated). If the token is missing/invalid/expired-by-closure,
  behave exactly as if the parameter weren't there (don't leak whether
  a token was close-but-wrong).
- **Token enforcement on every ID-based endpoint** — this is the part
  that makes "invite-only" actually mean something, not just hide a
  card:
  - `training-detail.php`, `check_slots.php`, `check_duplication.php`,
    `register_participant.php`: when the looked-up training has
    `is_invite_only = 1`, require a matching `token` parameter in the
    same request; if absent or wrong, respond exactly as if the
    training doesn't exist (404-shaped, not 403 — don't confirm
    existence to a guesser).
  - `submit_proof_payment.php` resolves `training_id` by name + date,
    not by ID, so it's naturally less guessable, but double check: if
    a participant has already registered (their `participant_id` /
    registration row exists), proof upload should just work without
    needing the token re-supplied — the token's job is gating
    *discovery and registration*, not every subsequent step by an
    already-registered person.
  - Plumb the token through the front-end JS in `index.php` so these
    calls carry it once a token-based session has been opened (e.g.
    store it in a hidden field alongside `selectedTrainingId`, same
    pattern already used there).
- **Auto-close behavior**: invite-only trainings participate in the
  existing auto-archive (`registration_deadline < CURDATE()` →
  `status = 'inactive'`) and auto-complete (`cron/
  auto_complete_trainings.php`, `end_date < CURDATE()` → `Completed`)
  logic identically to normal trainings — no new timer. The token
  gate's "still open" check is the same predicate quoted above under
  Constraints, so closure is automatic and consistent with the public
  list, not a separate thing to keep in sync.
- Rate limiting on the new token-gated entry path: apply the existing
  `RateLimit` class (same as `check_slots`/`submit_proof_payment`) to
  prevent token brute-forcing via the public endpoints — tokens are
  64 hex chars so brute force is already infeasible, but the endpoint
  should not become a new unthrottled enumeration surface.
- Follow existing PDO/CSRF/response-shape conventions already present
  in each file touched (admin side: `{status: 'success'|'error'}`;
  public side: `{success: true|false, ...}`) — do not mix them.
