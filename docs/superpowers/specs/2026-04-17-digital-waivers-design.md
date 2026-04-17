# Digital Waivers — Design Spec

**Date:** 2026-04-17
**Branch:** `feature/digital-waivers`
**Author:** autonomous brainstorm (decisions pre-made per user instruction; no questions asked)

## 1. Goal

Replace paper / scanned-PDF waivers with a first-class online waiver workflow:

1. **Waiver Builder** — Kingdom admins design a Kingdom-level waiver (used at events) and/or a Park-level waiver (used at park days). Builder is block-oriented: Header, Player Info Header, Waiver Details (markdown), Signature, Minor Representative, Footer.
2. **Player Submission** — Any logged-in player can fill and sign the applicable waiver. Signature supports drawn (finger / mouse / stylus) OR typed (full name in cursive font).
3. **Officer Verification** — Park / Kingdom officers see a queue of pending-signed waivers and can verify or reject each; verification itself is a signed acknowledgement (printed name, signature, persona, office title, date).

## 2. Out of scope for v1

- Server-side PDF generation (users can print to PDF via the browser from the `Waiver_print` view).
- Email notifications (none — players check Player profile "Waivers" card; officers check Kingdom/Park queue).
- Waiver expiration / annual renewal. A signed waiver is good until the Kingdom admin publishes a new version, at which point prior signatures are flagged "stale" in the officer queue.
- Multi-kingdom admin or super-admin editing. Only Kingdom admins edit their own Kingdom's waivers. (`AUTH_ADMIN` role may override — handled by existing `HasAuthority` calls.)
- Mass import of the legacy `ork_mundane.waivered` flag. That flag remains as-is; we do NOT touch it.
- Multiple concurrent active waivers per Kingdom per scope. One active Kingdom-level waiver, one active Park-level waiver per Kingdom. Parks do NOT get individually customised waivers.

## 3. Architecture

Follows existing ORK3 three-layer pattern:

| Layer | New file |
|-------|----------|
| DB / domain | `system/lib/ork3/class.Waiver.php` |
| orkui model (pass-through) | `orkui/model/model.Waiver.php` |
| Page controller | `orkui/controller/controller.Waiver.php` |
| AJAX controller | `orkui/controller/controller.WaiverAjax.php` |
| Templates | `orkui/template/revised-frontend/Waiver_builder.tpl`, `Waiver_sign.tpl`, `Waiver_queue.tpl`, `Waiver_review.tpl`, `Waiver_print.tpl` |

CSS prefix: `wv-`. All CSS + JS inlined in templates per house convention (Playernew/Kingdomnew/Parknew precedent). No new external deps — signature canvas is hand-rolled vanilla JS + SVG; markdown uses existing `system/lib/Parsedown.php` with `setSafeMode(true)->setBreaksEnabled(true)`.

### 3.1 Routes

| URL | Who | Purpose |
|-----|-----|---------|
| `/Waiver/builder/{kingdom_id}` | Kingdom admin | Edit both Kingdom-level and Park-level waivers for that kingdom. Single-page editor with a tab strip: **Kingdom Waiver** / **Park Waiver**. |
| `/Waiver/sign/{scope}/{entity_id}` | Any logged-in player | Fill + sign a waiver. `scope` = `kingdom` or `park`. |
| `/Waiver/queue/{scope}/{entity_id}` | Officer of that park/kingdom | Paginated list of pending (and optionally verified / rejected) signatures for that scope. |
| `/Waiver/review/{signature_id}` | Officer | Single signed waiver, render as printable page + officer verification form below. |
| `/Waiver/print/{signature_id}` | Officer or signer | Plain printable version (no chrome) for browser print-to-PDF. |
| **AJAX endpoints** | | |
| `/WaiverAjax/saveTemplate` | Kingdom admin | Save / publish a new waiver version. |
| `/WaiverAjax/previewMarkdown` | Authenticated | Return rendered HTML for live preview (debounced client call). |
| `/WaiverAjax/submitSignature` | Any logged-in player | Submit a filled waiver. |
| `/WaiverAjax/verifySignature` | Officer | Mark verified or rejected. |

### 3.2 Integration hooks

- **Kingdomnew profile (`controller.Kingdom.php` / `Kingdomnew_index.tpl`)** — admin panel gets a **Waivers** quick-action: "Edit Waivers" → builder, "Review Queue (N pending)" → queue.
- **Parknew profile (`controller.Park.php` / `Parknew_index.tpl`)** — park officer panel gets "Sign Park Waiver" (for logged-in players who haven't signed the current version) and "Review Queue (N pending)" (for park officers).
- **Playernew profile (`controller.Playernew.php` / `Playernew_index.tpl`)** — sidebar adds a **Digital Waivers** card listing home-kingdom + home-park waiver status with Sign / View links. Only shown when viewing one's own profile.

## 4. Data model

Two new tables. All column names snake_case, matching the existing ORM convention used by yapo models in `system/lib/ork3/`.

### 4.1 `ork_waiver_template`

Stores the markdown definition. Versioned: each save creates a new row; the latest row with `is_active = 1` is the "published" version. A signature references `waiver_template_id` so historical text is always preserved.

```sql
CREATE TABLE `ork_waiver_template` (
  `waiver_template_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kingdom_id`            INT UNSIGNED NOT NULL,
  `scope`                 ENUM('kingdom','park') NOT NULL,
  `version`               INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active`             TINYINT(1) NOT NULL DEFAULT 0,
  `is_enabled`            TINYINT(1) NOT NULL DEFAULT 0,
  `header_markdown`       TEXT NOT NULL DEFAULT '',
  `body_markdown`         MEDIUMTEXT NOT NULL DEFAULT '',
  `footer_markdown`       TEXT NOT NULL DEFAULT '',
  `minor_markdown`        TEXT NOT NULL DEFAULT '',
  `created_by_mundane_id` INT UNSIGNED NOT NULL,
  `created_at`            DATETIME NOT NULL,
  PRIMARY KEY (`waiver_template_id`),
  INDEX (`kingdom_id`, `scope`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`is_enabled` is controlled on the latest-per-scope active row. Disabling a waiver type hides the "Sign" surface without losing prior signatures.

### 4.2 `ork_waiver_signature`

```sql
CREATE TABLE `ork_waiver_signature` (
  `waiver_signature_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waiver_template_id`       INT UNSIGNED NOT NULL,
  `mundane_id`               INT UNSIGNED NOT NULL,

  -- snapshot of player-entered header fields (can differ from current mundane record)
  `mundane_first_snapshot`   VARCHAR(64) NOT NULL DEFAULT '',
  `mundane_last_snapshot`    VARCHAR(64) NOT NULL DEFAULT '',
  `persona_name_snapshot`    VARCHAR(128) NOT NULL DEFAULT '',
  `park_id_snapshot`         INT UNSIGNED NOT NULL DEFAULT 0,
  `kingdom_id_snapshot`      INT UNSIGNED NOT NULL DEFAULT 0,

  -- signature
  `signature_type`           ENUM('drawn','typed') NOT NULL,
  `signature_data`           MEDIUMTEXT NOT NULL,   -- drawn: JSON strokes [[{x,y},...],...] normalised 0..1; typed: plain string
  `signed_at`                DATETIME NOT NULL,

  -- minor representative (null / blank if signer is not minor)
  `is_minor`                 TINYINT(1) NOT NULL DEFAULT 0,
  `minor_rep_first`          VARCHAR(64) NOT NULL DEFAULT '',
  `minor_rep_last`           VARCHAR(64) NOT NULL DEFAULT '',
  `minor_rep_relationship`   VARCHAR(64) NOT NULL DEFAULT '',

  -- officer verification
  `verification_status`      ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `verified_by_mundane_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `verified_at`              DATETIME NULL DEFAULT NULL,
  `verifier_printed_name`    VARCHAR(128) NOT NULL DEFAULT '',
  `verifier_persona_name`    VARCHAR(128) NOT NULL DEFAULT '',
  `verifier_office_title`    VARCHAR(128) NOT NULL DEFAULT '',
  `verifier_signature_type`  ENUM('drawn','typed') NULL DEFAULT NULL,
  `verifier_signature_data`  MEDIUMTEXT NULL DEFAULT NULL,
  `verifier_notes`           TEXT NOT NULL DEFAULT '',

  PRIMARY KEY (`waiver_signature_id`),
  INDEX (`mundane_id`),
  INDEX (`waiver_template_id`, `verification_status`),
  INDEX (`kingdom_id_snapshot`, `verification_status`),
  INDEX (`park_id_snapshot`, `verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Migration lives at `docs/migrations/2026-04-17-digital-waivers.sql` and is run via the MariaDB docker exec pattern from project memory.

## 5. Functional detail

### 5.1 Waiver Builder (`Waiver_builder.tpl`)

- Tab strip: **Kingdom Waiver** | **Park Waiver**. Each tab has an enable/disable toggle (writes `is_enabled` on the active row).
- Per tab, five side-by-side sections with a live-preview pane on the right:
  - **Header** (markdown) — shows on every rendered/print page
  - **Player Header** — NOT editable; fixed field set (First, Last, Persona, Home Park, Home Kingdom). Builder shows a locked preview block explaining "These fields auto-fill from the logged-in player at signing time." No configuration needed.
  - **Waiver Details** (markdown body)
  - **Signature** — NOT editable; fixed (signature pad + date)
  - **Minor Representative** (markdown — instructions shown when signer marks as minor) plus fixed fields (Rep First, Rep Last, Relationship).
  - **Footer** (markdown)
- Single **Save & Publish** button at the top. Save = insert new row, flip prior active row to `is_active = 0`, set new row `is_active = 1`. Version auto-increments.
- Live preview: markdown textarea → debounced fetch to `/WaiverAjax/previewMarkdown` → HTML response rendered in preview pane. Same Parsedown call server-side.

### 5.2 Player Submission (`Waiver_sign.tpl`)

- Route `/Waiver/sign/kingdom/{kingdom_id}` or `/Waiver/sign/park/{park_id}`.
- Controller loads the current active template for that scope. 404-style "This kingdom/park has not enabled digital waivers" when `is_enabled = 0` or no active row.
- Render in order: Header → Player Header (prefilled) → Details → Minor section (collapsed behind checkbox "I am signing for a minor") → Signature → Footer.
- Player Header fields: First, Last (prefilled from `ork_mundane`, editable in case of discrepancy), Persona Name (prefilled from current player), Home Park + Home Kingdom (prefilled from logged-in player record, editable dropdowns).
- Signature widget: tab switch **Draw** / **Type**.
  - Draw: HTML canvas, pointer events (mouse / touch / pen), Clear + Undo buttons. On submit, canvas strokes are serialised to normalised JSON (`[[{x,y}, …], …]`).
  - Type: single text input, rendered live in a cursive font (`Homemade Apple` via Google Fonts, with `serif` fallback). Player MUST type their full legal name (matches First + Last on this waiver) — client-side check only; server-side just stores.
- Submit → `POST /WaiverAjax/submitSignature` with all fields. Returns JSON `{status: 'Success', signature_id: ...}`. Page redirects to a confirmation view (`/Waiver/review/{id}` in read-only mode for the signer).
- If a signer has already signed the current active version, the sign page shows their existing signature in read-only mode and a "Re-sign (start new)" button. Re-signing creates a new signature row and retires the old one as `rejected` with notes "superseded".

### 5.3 Officer Verification

#### Queue (`Waiver_queue.tpl`)

- Route `/Waiver/queue/{scope}/{entity_id}`. Auth: `HasAuthority(uid, AUTH_PARK|AUTH_KINGDOM, entity_id, AUTH_EDIT)`.
- Table: Player (persona + mundane), Signed At, Status badge, Template Version, Actions (Review). Sortable columns; 10/page pagination matching Playernew recommendations pattern.
- Filter chips: Pending (default) | Verified | Rejected | Stale (signed against a non-active template version).
- Search box: filter by player name (persona or mundane) using existing `kn-ac-results` autocomplete pattern.

#### Single review (`Waiver_review.tpl`)

- Route `/Waiver/review/{signature_id}`. Auth: must have kingdom- or park-level authority covering `kingdom_id_snapshot` / `park_id_snapshot`, OR be the signer themselves (read-only mode).
- Page: renders the complete signed waiver (Header, Player Header with snapshotted values, Details, rendered signature, Minor block if applicable, Footer). Inline printable — CSS `@media print` hides chrome.
- Below: **Officer Verification** form:
  - Printed Name (prefilled from officer mundane first+last)
  - Persona Name (prefilled)
  - Office Title (free text — autocomplete from officer's current `ork_officer` roles for this kingdom/park)
  - Date of Review (prefilled today, editable)
  - Signature (drawn / typed — same widget as player)
  - Notes (optional textarea — required if rejecting)
  - Verify / Reject buttons.
- Submit → `POST /WaiverAjax/verifySignature` with action `verified` or `rejected`.
- If already verified/rejected, show the verification block in read-only mode.

### 5.4 Print view (`Waiver_print.tpl`)

- Minimal chrome (no site nav). Wraps Header, Player Header with snapshotted values, Details, Signature image, Minor block, Footer. CSS `@page` margins + `thead` / `tfoot` pattern for repeating header/footer on browser print.
- Linked from officer queue, single review, player profile.

## 6. Security / authorisation

- Builder: `HasAuthority(uid, AUTH_KINGDOM, kingdom_id, AUTH_EDIT)` OR `AUTH_ADMIN`. Park officers cannot edit waiver templates.
- Sign: any authenticated user (`$this->session->user_id > 0`). Server stamps `mundane_id` from session — **never trust client**.
- Queue + Review: `HasAuthority(uid, AUTH_KINGDOM, kingdom_id_snapshot, AUTH_EDIT)` OR `HasAuthority(uid, AUTH_PARK, park_id_snapshot, AUTH_EDIT)` OR the signer themselves (read-only).
- Markdown rendering: always `Parsedown::setSafeMode(true)` server-side.
- Live preview endpoint: require auth (`$this->session->user_id > 0`); body length hard cap 64KB to prevent abuse.
- Signature data: capped at 256KB MEDIUMTEXT-safe size; reject larger on server side.
- CSRF: reuse existing session `Token` pattern (same as award recommendations), posted with every mutation.

## 7. Data flow (write-path example: player submits waiver)

```
[Browser]  POST /WaiverAjax/submitSignature { token, template_id, first, last, persona, park_id, kingdom_id, signature_type, signature_data, is_minor, minor_* }
  → [controller.WaiverAjax.php] validate POST, load session user_id, call model->submit_signature($request)
    → [model.Waiver.php::submit_signature] pass through to APIModel -> Waiver->SubmitSignature
      → [class.Waiver.php::SubmitSignature] authorise token, $DB->Clear(), fetch template to verify it matches scope & is_active/is_enabled, yapo insert ork_waiver_signature row, return ['Status' => Success, 'Detail' => {signature_id}]
    ← response
  ← JSON { status: 0, signature_id }
[Browser] window.location = '/Waiver/review/{signature_id}'
```

Same pattern for `saveTemplate` and `verifySignature`. `$DB->Clear()` before every raw DB call is mandatory per project memory.

## 8. Error handling

- Invalid / missing fields: server returns `{ status: <nonzero>, error: 'Field X required' }`; client surfaces in an inline error area (no native alerts).
- Auth failure: `{ status: 401, error: 'Not authorised' }`; builder / queue pages redirect to kingdom profile.
- Submit race (template went inactive between page load and submit): return `{ status: 409, error: 'Waiver version changed — please reload and re-sign' }`.
- DB save checklist enforced (project memory): `$DB->Clear()` first, validate not-null fields, trim silent Yapo failures with explicit column list.

## 9. Testing strategy

Driven by `superpowers:test-driven-development`. Tests at three levels:

1. **Unit (PHP)** — `tests/php/WaiverClassTest.php` (or whichever harness ORK3 uses — if none exists we add a minimal PHPUnit-style harness): SaveTemplate versioning, SubmitSignature with auth + without, verification state machine transitions, snapshot integrity.
2. **Controller / integration** — `tests/php/WaiverAjaxTest.php`: POST each AJAX endpoint with fabricated session, assert JSON response and DB row state.
3. **Manual QA checklist** in the plan — covers browser signature capture (Chrome mouse, Safari touch, typed fallback), markdown live preview latency, queue pagination, verification round-trip, print layout in Chrome + Firefox + Safari.

Note: if the repo has no PHP test harness today (likely), a minimal `tests/` tree will be added by the plan — one-off assertions via a CLI `docker exec` runner. Never mock the DB (project memory).

## 10. Rollout / migration

1. Apply `docs/migrations/2026-04-17-digital-waivers.sql` via docker exec mariadb CLI.
2. Seed nothing — kingdoms start with `is_enabled = 0` on both scopes.
3. PR against `master` titled **Enhancement: Digital Waivers** per PR title convention.
4. Legacy `ork_mundane.waivered` flag and existing file-upload behaviour are left untouched. A follow-up can opt to auto-flip `waivered` to 1 when a player has a verified Kingdom waiver — out of scope for this PR.

## 11. File checklist (what this spec will produce a plan for)

- [ ] `docs/migrations/2026-04-17-digital-waivers.sql`
- [ ] `system/lib/ork3/class.Waiver.php`
- [ ] `orkui/model/model.Waiver.php`
- [ ] `orkui/controller/controller.Waiver.php`
- [ ] `orkui/controller/controller.WaiverAjax.php`
- [ ] `orkui/template/revised-frontend/Waiver_builder.tpl`
- [ ] `orkui/template/revised-frontend/Waiver_sign.tpl`
- [ ] `orkui/template/revised-frontend/Waiver_queue.tpl`
- [ ] `orkui/template/revised-frontend/Waiver_review.tpl`
- [ ] `orkui/template/revised-frontend/Waiver_print.tpl`
- [ ] Hooks in `Kingdomnew_index.tpl`, `Parknew_index.tpl`, `Playernew_index.tpl`
- [ ] Test files under `tests/php/` (waiver unit + AJAX integration)

## 12. Key autonomous decisions (rationale)

| Decision | Why |
|----------|-----|
| Two new tables, no changes to `ork_mundane` | Keep scope self-contained; preserve legacy `waivered` flag untouched. |
| Snapshot player header fields | So the signed document survives player renames / park changes. |
| Versioned templates via soft version + `is_active` | Cheap, no migrations per edit, supports "stale" status automatically. |
| Signature stored as normalised JSON strokes, not PNG | Smaller; scales cleanly on any canvas; easy to re-render with SVG. |
| "Minor" detected by checkbox, not by age math | DOB is not a reliably populated column in `ork_mundane`; checkbox is simpler and avoids PII processing. |
| Builder is kingdom-only | Simplifies auth; all parks in a kingdom share the same Park-level waiver. Matches how waivers work on paper today. |
| No PDF server-side generation | Browser print-to-PDF covers 90% of use; saves a dep and implementation time. |
| Officer verification itself requires a signature | User's explicit requirement — same widget reused. |
| New controller + AJAX split | Matches existing `controller.Player.php` + `controller.PlayerAjax.php` pattern. |
