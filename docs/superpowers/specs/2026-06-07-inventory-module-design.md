# Inventory Module — Design Spec

**Date:** 2026-06-07
**Status:** Approved for implementation
**Scope:** v1 — durable-goods register for Kingdom and Park officers

## Overview

A register for tracking an organization's physical possessions: equipment, regalia, tentage,
and other durable goods — with quantities, condition, estimated value, storage location, and
optional custody (who currently holds it). Each Kingdom and each Park keeps its own independent
inventory. Inventory is a standalone officer-only tool, reached from a link in the org's
**Admin Tasks tab** (next to Treasury) — it is NOT shown on the public-facing profile.

This is the second of two planned modules. **Treasury is the pattern-setter** — Inventory mirrors
its auth scoping, three-layer architecture, AJAX CRUD shape, dark-mode UI conventions, and
Admin-tab integration. Where Treasury tracks money flow, Inventory tracks owned goods.

## Agreed Decisions

| Decision | Choice |
|---|---|
| Ownership | Independent register per org (each Kingdom, each Park). No roll-up, no shared goods. |
| Item model | **Quantity stack** — each row is a line-item of like goods with a `quantity` field. One condition / unit-value / location / custody per stack. |
| Categories | Fixed built-in list (canonical keys + PHP display labels), mirroring `Treasury::$CATEGORIES`, with an "Other" escape hatch. |
| Custody | Single optional **held-by** field per item: free-text holder name OR a scoped-playersearch player (mirrors Treasury's counterparty pattern). No check-out/check-in loan log. |
| Valuation | Estimated **unit value** per item; total value = `quantity × unit_value`. No depreciation. |
| Removal (disposal) | **"Remove from Inventory"** pathway: marks an item as no longer owned with a required reason (enum) + optional note. Row kept, excluded from active totals/charts, shown in a "Removed" view, restorable, audit-logged. Distinct from soft-delete. |
| Correction (mistake) | Separate quiet **soft-delete** ("Delete entry") for genuine mis-entries — removed from all views, tamper-evident audit row. |
| Visibility | Officers only. Non-officers never see the tool or its link. |
| Editing | Any org officer with authority (`HasAuthority(AUTH_KINGDOM/AUTH_PARK, …, AUTH_EDIT)`). No new dedicated role. |
| Audit trail | Tamper-evident change log (create/edit/remove/restore/delete with before/after JSON), mirroring `ork_treasury_audit`. |
| Reconciliation | **Count sheet + verification.** A printable count sheet (active items sorted by location) supports a physical count; officers then mark items verified (`last_verified_at`/`last_verified_by`, one `verify` audit row per item), by selection or by the current filters. A "stale" filter surfaces items not verified in 365 days. |
| Disposal details | Removal records disposal date, proceeds (required for a sale), and who it went to. A priced sale can optionally post a Treasury income entry in the same transaction (linked via `treasury_entry_id`); restoring the item clears the details but does **not** reverse the Treasury entry — the UI warns. |
| Search / filters | Keyword search matches name, location, holder, and notes; exact-match filters by location and holder (linked player or free-text name). |
| Attachments | None in v1. Stable `item_id` reserves a clean seam for a future `ork_inventory_attachment` table. |
| Currency | Single currency (USD). No symbol config. |
| Reporting | Summary cards (total value, total units, line items, # needs repair), two charts (value-by-category, count-by-condition), CSV export. |
| Placement | Standalone tool (`Inventory/kingdom/{id}`, `Inventory/park/{id}`), linked from the org **Admin Tasks tab** next to Treasury. |

## Item Fields

**Core (required):**
- Name — free text (e.g. "Boffer longsword")
- Category — from the fixed list
- Quantity — integer ≥ 1

**Optional:**
- Condition — enum: New / Good / Fair / Poor / Needs Repair
- Estimated unit value — `decimal(12,2)`; total = `quantity × unit_value`
- Location / stored-at — free-text storage place (e.g. "Kingdom shed")
- Held by — free-text holder name **or** a scoped-playersearch player (custody)
- Acquired date — date the org obtained the item
- Notes — free-text catch-all

## Fixed Category List

Stored as canonical keys; display labels live in PHP so reports stay stable if a label is reworded.
(Final wording confirmed during implementation; keys are authoritative.)

- `weapons` → Weapons
- `loaner_weapons` → Loaner Weapons
- `armor` → Armor
- `shields` → Shields
- `garb` → Garb / Loaner Garb
- `regalia` → Regalia (Crowns, Thrones, Chains)
- `banners` → Banners / Heraldry
- `tentage` → Pavilions / Tentage
- `archery_siege` → Archery / Siege
- `event_equipment` → Event Equipment
- `feast_kitchen` → Feast / Kitchen
- `awards_supplies` → Award & Scroll Supplies
- `furniture` → Furniture
- `electronics` → Electronics / AV
- `inventory_other` → Other

## Removal Reasons

Canonical keys; labels in PHP. Required when removing an item from inventory.

- `sold` → Sold
- `donated` → Donated / Gifted
- `unrepairable` → Damaged Beyond Repair
- `lost` → Lost / Stolen
- `consumed` → Consumed / Used Up
- `transferred` → Transferred to Another Org
- `removal_other` → Other

A free-text removal note is always optional (and the natural place to explain an "Other" removal).

## Data Model

Two tables, prefix `ork_`, InnoDB / utf8mb4 / utf8mb4_unicode_ci. Migration file:
`db-migrations/2026-06-07-inventory.sql`.

### `ork_inventory_item` — the register
| column | type | notes |
|---|---|---|
| `id` | int(11) PK auto | stable identity (future attachment FK) |
| `owner_type` | enum('kingdom','park') | which org dimension |
| `owner_id` | int(11) | kingdom_id or park_id |
| `name` | varchar(255) | required |
| `category` | varchar(64) | canonical key from fixed list |
| `quantity` | int(11) NOT NULL DEFAULT 1 | ≥ 1 |
| `condition` | enum('new','good','fair','poor','needs_repair') NOT NULL DEFAULT 'good' | |
| `unit_value` | decimal(12,2) NOT NULL DEFAULT 0.00 | per-unit estimated value |
| `location` | varchar(255) NOT NULL DEFAULT '' | free-text storage place |
| `held_by` | varchar(255) NOT NULL DEFAULT '' | free-text holder name |
| `held_by_player_id` | int(11) NOT NULL DEFAULT 0 | scoped-playersearch player; 0 = none (yapo-null sentinel) |
| `acquired_date` | date NULL | optional |
| `notes` | varchar(500) NOT NULL DEFAULT '' | optional |
| `removed_at` | datetime NULL | set when removed from inventory (disposal) |
| `removal_reason` | varchar(32) NOT NULL DEFAULT '' | canonical key from removal list; '' while active |
| `removal_note` | varchar(500) NOT NULL DEFAULT '' | optional free-text on removal |
| `deleted_at` | datetime NULL | soft-delete (mis-entry correction) |
| `created_by` | int(11) | mundane_id |
| `created_at` | datetime | |
| `updated_at` | datetime NULL | |

Indexes: PK(`id`); KEY(`owner_type`,`owner_id`,`category`); KEY(`deleted_at`); KEY(`removed_at`).

`condition` is a reserved word in some SQL dialects — quote it (`` `condition` ``) everywhere.

`NOT NULL DEFAULT` on clearable columns (`location`, `held_by`, `notes`, `held_by_player_id`,
`removal_reason`, `removal_note`) honors the yapo-drops-null gotcha: clear by assigning `''`/`0`,
never `null`.

### `ork_inventory_audit` — change log
Identical shape to `ork_treasury_audit`, keyed to inventory items.
| column | type | notes |
|---|---|---|
| `id` | int(11) PK auto | |
| `item_id` | int(11) | FK → ork_inventory_item.id |
| `action` | enum('create','edit','remove','restore','delete') | |
| `changed_by` | int(11) | mundane_id |
| `changed_at` | datetime | |
| `before_json` | text NULL | row state before (null on create) |
| `after_json` | text NULL | row state after (null on delete) |

Indexes: PK(`id`); KEY(`item_id`).

### (future) `ork_inventory_attachment` — NOT built in v1
Seam reserved: would FK on `item_id`. No migration in v1.

## Item Lifecycle & Semantics

- **Active item:** `deleted_at IS NULL AND removed_at IS NULL`. Only active items appear in the
  default ledger, count toward summary cards, and feed the charts.
- **Total value:** `Σ(quantity × unit_value)` over active items. Integer-cent-safe; `decimal(12,2)`.
- **Remove from inventory (disposal):** sets `removed_at = now`, `removal_reason` (required, from the
  enum), and optional `removal_note`; writes a `remove` audit row. The item is excluded from active
  totals/charts but visible under a "Removed" filter and **restorable** (`restore` clears `removed_at`/
  reason/note and audit-logs it). Removal requires a reason; UI blocks submit without one.
- **Delete (correction):** soft-delete for genuine mis-entries — sets `deleted_at`, writes a `delete`
  audit row, removed from all views. A quieter, secondary action than Remove.
- **Edit:** last-write-wins; full before/after captured in the audit log.
- All authority is enforced in the lib layer on every call (page guard is not the only gate).

## Architecture (three-layer convention — mirrors Treasury)

### DB layer — `system/lib/ork3/class.Inventory.php` (new)
YAPO wrappers for the two tables. All authorization checks live here. Methods:
- `GetItems($owner_type, $owner_id, $filters)` — paged/filtered items (active by default; `status`
  filter for `removed`); each row includes computed `TotalValue` and resolved held-by display name.
- `GetItem($id)`
- `SaveItem($data)` — create/edit; validates; writes an audit row.
- `RemoveItem($id, $reason, $note)` — disposal; validates reason; writes `remove` audit row.
- `RestoreItem($id)` — un-remove; writes `restore` audit row.
- `DeleteItem($id)` — soft-delete (correction) + `delete` audit row.
- `GetSummary($owner_type, $owner_id, $filters)` — total value, total units, line-item count,
  # needs repair, value-by-category, count-by-condition.
- `GetRevision($owner_type, $owner_id)` — cheap COUNT/MAX change-signal for the auto-refresh poll.
- `GetOwnerName($owner_type, $owner_id)` — org display name + `KingdomId` (for park playersearch scope).

Auth helper `authFor($token, $owner_type, $owner_id)` resolves `mundane_id` via
`IsAuthorized` then `HasAuthority($mundane_id, AUTH_KINGDOM/AUTH_PARK, $owner_id, AUTH_EDIT)`;
returns `0` on denial. `static $CATEGORIES` and `static $REMOVAL_REASONS` maps live here.

### Model layer — `orkui/model/model.Inventory.php` (new, thin)
`APIModel('Inventory')` proxy; pass-through methods only. No logic.

### Controller layer
- **`controller.Inventory.php`** (new) — `kingdom($id)`/`park($id)` gate with
  `HasAuthority(AUTH_KINGDOM/AUTH_PARK, $id, AUTH_EDIT)`, redirecting unauthorized viewers to
  Login. Loads summary, first items page, the category + removal-reason maps, org name + kingdom_id,
  and renders the template.
- **`controller.InventoryAjax.php`** (new) — JSON endpoints mirroring `controller.TreasuryAjax.php`:
  `items` (paged/filtered fetch), `summary`, `rev`, `getitem`, `additem`, `edititem`, `removeitem`,
  `restoreitem`, `deleteitem`, `export` (CSV). Session-presence check in the controller; authority
  enforced in the lib on every call.

### Player search (held-by)
Scoped, mirroring Treasury's counterparty: `KingdomAjax/playersearch/{kingdomId}&scope=own&include_inactive=1&q=…`,
custom `kn-ac-results` dropdown (never jQuery UI), `&q=` not `?q=`, 2-char minimum,
`tnFixedAcPosition()` for the in-modal dropdown. For a park, pass the park's `kingdom_id`
(resolved via `GetOwnerName`). Held-by stores both `held_by` (display/free-text) and
`held_by_player_id` (sentinel 0 = none).

### Admin integration
An **"Inventory"** link in the Admin Tasks tab "Park"/"Kingdom" group of
`Parknew_index.tpl` / `Kingdomnew_index.tpl`, immediately after the existing "Treasury" link,
shown only to authorized officers (same `<?php if … ?>` guard as the Treasury link).

### Routing
- Pages: `index.php?Route=Inventory/kingdom/{id}`, `index.php?Route=Inventory/park/{id}`.
- AJAX: `index.php?Route=InventoryAjax/handle/{owner_type}/{owner_id}/{action}` (+ POST body for writes).
  **All query params appended with `&` — the UIR already ends in `?Route=`; a second `?` folds params
  into the Route and breaks the action.**

## UI (`Inventory_index.tpl`, new)

Standalone page, all JS/CSS inlined, `inv-` prefixed, dark-mode-compatible from the start.

- **Org header** — heraldry + name so the officer knows whose register they're in.
- **Summary cards** — Total Estimated Value (large) · Total Units (Σ qty) · Line Items · # Needs Repair.
- **Charts** — value-by-category (donut) + count-by-condition (column), Highcharts, dark-mode tooltip,
  `backgroundColor:'transparent'`, `credits:{enabled:false}`. Re-render after any CRUD.
- **Table** — Name · Category · Qty · Condition · Unit Value · Total Value · Location · Held By · actions.
  Sortable columns, pagination (mirror Treasury, 25/page default). Acquired date + notes live in the
  modal + CSV, not the table.
- **Filters** — category, condition, keyword (name) search, and a status toggle (Active / Removed).
- **Add/Edit modal** — in-product modal (no native dialogs): name, category (grouped select), quantity,
  condition segmented control, unit value, location, held-by (free-text OR scoped playersearch),
  acquired-date (flatpickr `altInput` + human-readable `altFormat`), notes.
- **Remove from Inventory** — per-row action → modal: removal-reason `<select>` (required) + optional
  note → POST `removeitem`. Removed rows surface under the "Removed" status filter with reason/date and
  a **Restore** action.
- **Delete entry** — quieter secondary action (correction); `tnConfirm()` confirm → POST `deleteitem`.
- **First-run / empty state** — friendly "No items yet — add your first item" panel instead of an empty table.
- **Auto-refresh** — cheap `rev` heartbeat polled ~25s + on focus; pause on modal-open/hidden; refetch
  only on change. Mirrors Treasury.
- **Export** — "Export CSV" button → AJAX export action; respects active filters.

UI conventions to honor (per project memory): no native `title` tooltips (use `data-tip`), no native
`confirm()/alert()` (use `tnConfirm()`), flatpickr human-readable display, global h1–h6 gray-box reset
on any custom heading, dark-mode walk before "done".

## Edge Cases & Integrity

- **Quantity < 1** → rejected (`InvalidParameter`); quantity is required and ≥ 1.
- **Negative / zero unit value** → allowed (value optional; 0 = unvalued). Total value never blocked.
- **Remove without reason** → rejected in lib + blocked in UI.
- **Removed item excluded from value/units/charts** → only `deleted_at IS NULL AND removed_at IS NULL`
  counts; "Removed" view shows disposed items with their reason for the historical record.
- **Restore** → re-includes the item; clears removal fields; audit-logged.
- **`condition` reserved word** → backtick-quote in all SQL.
- **Concurrent officers** → soft-delete + audit log; last-write-wins on edits; change log shows who did what.
- **Held-by scoping (park)** → resolve the park's `kingdom_id` and scope playersearch to that kingdom;
  curl-test it returns rows before wiring the UI.
- **Decimal/rounding** → `decimal(12,2)`, integer-cent-safe PHP sums; no floats.
- **Auth bypass attempts** → every AJAX action re-checks authority in the lib; `owner_type`/`owner_id`
  validated against the authenticated officer's authority on every call.

## Testing

- **Unit (lib):** total-value computation (active only, removed/deleted excluded), summary aggregates
  (by-category value, by-condition count, # needs repair), removal requires reason, restore round-trip,
  audit-row emission on create/edit/remove/restore/delete, authority enforcement returns proper denials.
- **Integration (AJAX):** each endpoint with authorized vs unauthorized session; remove/restore flow;
  held-by playersearch returns rows; CSV export contents respect filters.
- **Local verification:** curl-auth session pattern against the Docker app (`ork3-php8-app`); seed
  synthetic rows to exercise the register (brand-new tables — no migration-lag risk).
- **UI walk (real browser):** filter / pagination / edit / remove / restore / export exercised in-browser
  (the `?`-vs-`&` AJAX-URL bug hides from curl). Dark-mode + conventions (modal headers, segmented
  control, flatpickr alt-format, `tnConfirm`, no native dialogs/tooltips) before "done".

## Out of Scope (v1)

- Check-out / check-in loan log (custody is a single held-by snapshot).
- Photos / receipt attachments (seam reserved via stable `item_id`).
- Physical-audit / reconciliation sessions.
- Depreciation / valuation history.
- Per-unit custody splitting (one held-by per stack).
- Partial removal of a stack (remove retires the whole line-item; edit quantity down for partial sales).
- Cross-org roll-up or aggregation.
- Org-managed custom categories.
- Multi-currency.
