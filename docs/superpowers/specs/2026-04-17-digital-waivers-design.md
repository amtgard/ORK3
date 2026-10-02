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

---

# Amendment 1 — 2026-04-18 — Real-World Waiver Coverage

## A1.1 Motivation

Survey of six real kingdom waivers (Amtgard general CA/HI/NV, Winter's Edge TN, Capitol Games MD, Blackspire OR, Emerald Hills TX, Northern Empire ON) showed the v1 builder cannot express common structure:

- Demographic capture beyond First/Last/Persona: DOB, preferred name, address, phone, email, gender
- Emergency-contact trio (name, relationship, phone) on four of six waivers
- Multiple minors in one signing event (up to four on Blackspire; two extras on Amtgard general)
- Standalone witness signature separate from officer verification (Winter's Edge, Northern Empire, Blackspire guardian-witness)
- Custom checkboxes / radios / initials / acknowledgements (Youth Policy, "not on sex-offender registry", Joining/Transferring/Updating)
- Admin intake metadata overlaid onto the existing officer verification row (ID type + number, Age bracket 18+/14+/<14, Scanned-paper flag)

## A1.2 Design mechanism — hybrid, not pure JSON

**First-class columns** for data kingdoms want to search, export, or report on (DOB, emergency contact, address/phone/email snapshots, minor roster, witness signature).
**JSON custom-fields block** for per-kingdom boilerplate (checkboxes, radios, initials, free-text overlays) that varies too much across waivers to model discretely.

Rationale: a pure-JSON answer kills reporting value. Pure first-class columns ossify one-off fields like "Joining/Transferring/Updating" into the schema forever. Hybrid keeps the common-important fields queryable while still letting a kingdom admin add a novel checkbox without another migration.

## A1.3 Schema additions (additive-only migration `db-migrations/2026-04-18-digital-waivers-amendment.sql`)

### A1.3.1 `ork_waiver_template` — 10 new columns

```sql
ALTER TABLE `ork_waiver_template`
  ADD COLUMN `requires_dob`               TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_address`           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_phone`             TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_email`             TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_preferred_name`    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_gender`            TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_emergency_contact` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_witness`           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `max_minors`                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN `custom_fields_json`         MEDIUMTEXT NOT NULL;
```

Default 0 keeps existing behaviour identical — any waiver saved before this migration renders exactly as before. `max_minors` defaults to 1 to match current one-rep-one-minor UX; range 1–6.

### A1.3.2 `ork_waiver_signature` — 10 new columns

```sql
ALTER TABLE `ork_waiver_signature`
  ADD COLUMN `preferred_name_snapshot`      VARCHAR(64)  NOT NULL DEFAULT '',
  ADD COLUMN `dob_snapshot`                 DATE NULL DEFAULT NULL,
  ADD COLUMN `gender_snapshot`              VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `address_snapshot`             VARCHAR(255) NOT NULL DEFAULT '',
  ADD COLUMN `phone_snapshot`               VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `email_snapshot`               VARCHAR(128) NOT NULL DEFAULT '',
  ADD COLUMN `emergency_contact_name`       VARCHAR(128) NOT NULL DEFAULT '',
  ADD COLUMN `emergency_contact_phone`      VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `emergency_contact_relationship` VARCHAR(64) NOT NULL DEFAULT '',
  ADD COLUMN `witness_printed_name`         VARCHAR(128) NOT NULL DEFAULT '',
  ADD COLUMN `witness_signature_type`       ENUM('drawn','typed') NULL DEFAULT NULL,
  ADD COLUMN `witness_signature_data`       MEDIUMTEXT NULL DEFAULT NULL,
  ADD COLUMN `custom_responses_json`        MEDIUMTEXT NOT NULL;
```

Also: extend officer-verifier intake:

```sql
ALTER TABLE `ork_waiver_signature`
  ADD COLUMN `verifier_id_type`             VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `verifier_id_number_last4`     VARCHAR(8)   NOT NULL DEFAULT '',
  ADD COLUMN `verifier_age_bracket`         ENUM('', '18+', '14+', 'under14') NOT NULL DEFAULT '',
  ADD COLUMN `verifier_scanned_paper`       TINYINT(1) NOT NULL DEFAULT 0;
```

We store only ID **type** (Driver's License, Passport, State ID, Other) and the **last 4 digits** of the ID number — never the full number. Matches what officers actually need to cross-reference without turning the table into a PII honeypot.

### A1.3.3 New child table `ork_waiver_signature_minor`

```sql
CREATE TABLE IF NOT EXISTS `ork_waiver_signature_minor` (
  `waiver_signature_minor_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waiver_signature_id`       INT UNSIGNED NOT NULL,
  `seq`                       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `legal_first`               VARCHAR(64)  NOT NULL DEFAULT '',
  `legal_last`                VARCHAR(64)  NOT NULL DEFAULT '',
  `preferred_name`            VARCHAR(64)  NOT NULL DEFAULT '',
  `persona_name`              VARCHAR(128) NOT NULL DEFAULT '',
  `dob`                       DATE NULL DEFAULT NULL,
  PRIMARY KEY (`waiver_signature_minor_id`),
  KEY `idx_signature` (`waiver_signature_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Replaces the single `minor_rep_*` fields as the representation of the minor(s) **covered by** the signing adult. The existing `minor_rep_first/last/relationship` columns retain their role as the **guardian/representative** signing on behalf of those minors. Distinct entities, distinct columns.

## A1.4 `custom_fields_json` schema

Array of field definitions, validated on save:

```json
[
  {
    "id": "yp_ack",
    "type": "checkbox",
    "label": "I have read the Amtgard Youth Policy",
    "required": true
  },
  {
    "id": "sex_offender_initial",
    "type": "initial",
    "label": "I certify that I am not on a sex offender registry",
    "required": true
  },
  {
    "id": "visit_type",
    "type": "radio",
    "label": "Reason for completing this waiver",
    "options": ["Joining", "Transferring from another park", "Updating waiver"],
    "required": true
  },
  {
    "id": "additional_notes",
    "type": "text",
    "label": "Additional notes",
    "required": false
  }
]
```

**Supported field types:** `text`, `textarea`, `checkbox`, `initial` (3-char uppercase input), `radio`, `select`, `date`.

**`id`** — auto-generated slug (`[a-z0-9_]{1,32}`); unique within the template. Never renames once assigned (responses would orphan).
**Server-side validation on save:** reject if duplicate ids, if `options` missing for radio/select, if >50 fields.
**Server-side validation on submit:** every `required:true` field must have a non-empty value in `custom_responses_json`; extra keys are dropped.

`custom_responses_json`: `{ "yp_ack": true, "visit_type": "Joining", "sex_offender_initial": "JDS" }`.

## A1.5 Builder UX additions

Builder gains a new **"Fields & Demographics"** pane under each scope's tab, **above** the current side-by-side markdown/preview grid:

- **Demographics toggles** — eight checkboxes (one per `requires_*` flag). Each toggle shows a short one-line description of what it means for the signer ("Signer will be required to enter a phone number").
- **Max minors** — numeric input (1–6), default 1. Hint text: "How many minor children can be listed on a single signing. Set to 1 for single-child waivers, 4 for family waivers."
- **Custom fields editor** — list view with add / reorder (drag handles) / delete. Each row is label + type dropdown + required toggle + options (shown only for radio/select). Options entered as comma-separated or one-per-line textarea. Client-side slug generator from label on blur.

Save-and-publish button behaviour unchanged (still inserts a new version row). All new fields persist on the same row.

**Live preview panel updates** — when a demographic toggle is on, the preview shows a stub block labelled "[Demographics: DOB, Address, …]" so the admin sees placement. Same idea for custom fields — preview shows each label + type tag in the order they'll render.

## A1.6 Sign-page UX additions

Render order inside the form, conditional on template flags:

1. Header markdown
2. **Player Information** block (existing First/Last/Persona), extended with `Preferred Name` when `requires_preferred_name`, `Gender` text input when `requires_gender`, `DOB` date picker when `requires_dob`, `Address` single line when `requires_address`, `Phone` + `Email` when toggled. All demographic fields prefilled from `ork_mundane` where available (address, phone, email) or left blank; editable.
3. **Emergency Contact** block (name / relationship / phone), appears when `requires_emergency_contact`.
4. Body markdown.
5. **Custom Fields** block — renders each `custom_fields_json` entry with its type. Checkboxes and initial rows get inline markdown label rendering (so admins can format "I certify…" with emphasis).
6. **Minor Block** — existing minor-rep fields, plus a **Minors Covered** repeater showing up to `max_minors` rows, each with Legal First / Legal Last / Preferred / Persona / DOB. Only visible when `is_minor` checkbox is ticked and `max_minors > 1`; if `max_minors = 1` the single-row block reuses the current look.
7. Signer signature widget.
8. **Witness** block when `requires_witness = 1` — printed name + signature widget. Separate from officer verification.
9. Footer markdown.

Client-side validation: block submit if any required field empty; surface a single inline red error strip identifying the first missing field.

## A1.7 Review / Print UX additions

- `Waiver_review.tpl` — adds a "Signer Demographics" section between the header and the rendered body. Emergency contact shown in a labelled card. Minors table rendered as a simple 2-column grid. Custom responses listed below the body in the order defined by the template. Witness block renders as a second signature card beside the signer's.
- Officer verification form gains four new fields: ID Type dropdown, ID Last 4 (numeric only, 4 chars), Age Bracket radios, "Paper copy scanned & filed" checkbox. Submits with the existing `verifySignature` action.
- `Waiver_print.tpl` mirrors the review-page render (minus officer form). Page-break-inside: avoid on section cards.

## A1.8 Data flow deltas

**saveTemplate** — client posts new keys `RequiresDob`, `RequiresAddress`, `RequiresPhone`, `RequiresEmail`, `RequiresPreferredName`, `RequiresGender`, `RequiresEmergencyContact`, `RequiresWitness`, `MaxMinors`, `CustomFieldsJson`. `class.Waiver.php::SaveTemplate` validates the JSON shape and clamps `MaxMinors` to [1,6].

**submitSignature** — accepts `PreferredName`, `Dob`, `Gender`, `Address`, `Phone`, `Email`, `EmergencyContactName`, `EmergencyContactPhone`, `EmergencyContactRelationship`, `WitnessPrintedName`, `WitnessSignatureType`, `WitnessSignatureData`, `CustomResponsesJson`, `Minors` (array of `{LegalFirst, LegalLast, PreferredName, PersonaName, Dob}`). Server loads the template, validates each provided field against the template's `requires_*` flags and custom-field `required` rules; ignores fields the template doesn't ask for; snapshots everything it does. Minors persisted as child rows in `ork_waiver_signature_minor`, replacing prior rows for that signature on resubmit.

**verifySignature** — adds `IdType`, `IdLast4` (4 digits), `AgeBracket` (`''`, `18+`, `14+`, `under14`), `ScannedPaper` (0/1). Blank still permitted — these fields are informational.

**GetSignature** — returned payload gains `Demographics`, `EmergencyContact`, `Witness`, `Minors` (array), `CustomResponses` (decoded map), and verifier intake fields.

## A1.9 Security notes

- Custom-field `id` values are slugified on server save — rejecting anything outside `[a-z0-9_]{1,32}` prevents prototype-pollution / key-collision shenanigans in the JSON map.
- Labels and radio/select options are rendered with `htmlspecialchars` on review/print. Never injected into script context.
- ID number stored as last-4 only. Officer inputs full number in the UI; client-side JS trims to last 4 before POST. Server also trims.
- DOB stored as DATE; no time component. Client-side browsers' `<input type="date">` handles locale.

## A1.10 Coverage check (each row = one of the six waivers; columns match A1.3/A1.4)

| Requirement | Where it lives post-amendment |
|---|---|
| CA/HI/NV preferred name | `requires_preferred_name` → `preferred_name_snapshot` |
| CA/HI/NV additional child rows (up to 2 extra) | `max_minors = 3`; child rows in `ork_waiver_signature_minor` |
| CA/HI/NV Joining/Transferring/Updating radio | custom radio field |
| CA/HI/NV "not sex offender" initial | custom initial field |
| CA/HI/NV age bracket + ID + Scanned | `verifier_age_bracket`, `verifier_id_type`, `verifier_id_number_last4`, `verifier_scanned_paper` |
| Winter's Edge DOB, Form of ID, Phone, Witness | `requires_dob` + `requires_phone` + `verifier_id_type` + `requires_witness` |
| Capitol Games Legal/Game/Kingdom + officer verifier | already in v1 (persona + kingdom/park snapshots + verifier block) |
| Blackspire Persona/Legal/Address/Phone/Email/Emergency/4 minors/Youth Policy ack | full set of demographic flags + `requires_emergency_contact` + `max_minors = 4` + custom checkbox |
| Emerald Hills Mundane name, address, phones, email, emergency | demographic flags + emergency contact |
| Northern Empire birth date, gender, address, phone, email, emergency, witness | all demographic flags + witness |

Every row is covered without further schema changes.

## A1.11 Out of scope for this amendment (deferred)

- Per-field conditional visibility ("show this checkbox only if ticked") — engineering cost exceeds the one-kingdom use case we'd be serving.
- Import of legacy scanned PDFs into the new system — still handled as a paper attachment; `verifier_scanned_paper` flag is the bridge.
- Signer photograph / ID upload — privacy review required; out of scope until we have a secure blob store solution.
- Two-witness workflow (Winter's Edge) — second witness handled as a custom signature-text field until demand is clearer.
