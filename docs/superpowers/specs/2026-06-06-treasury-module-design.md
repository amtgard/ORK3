# Treasury Module — Design Spec

**Date:** 2026-06-06
**Status:** Approved for implementation
**Scope:** v1 — financial ledger for Kingdom and Park officers

## Overview

A ledger-style financial tool for tracking an organization's funds: credits (money in),
debits (money out), a computed running balance, and manual reconciliation against the
real-world account balance. Each Kingdom and each Park keeps its own independent ledger.
Treasury is a standalone officer-only tool, reached from a link in the org's Admin area —
it is NOT shown on the public-facing profile.

This is the first of two planned modules (Treasury, then Inventory). Treasury establishes
the shared conventions — auth scoping, AJAX CRUD shape, Admin-link integration — that
Inventory will reuse.

## Agreed Decisions

| Decision | Choice |
|---|---|
| Ownership | Independent ledger per org (each Kingdom, each Park). No roll-up, no shared funds. |
| Accounts | Single running balance per org. No per-account dimension. |
| Categories | Fixed built-in list (canonical keys + display labels), with an "Other" + free-text escape hatch. |
| Reconciliation | Snapshot record: treasurer enters real balance as-of a date; system shows match/mismatch + variance; explanation required on mismatch. Informational checkpoints — they do NOT re-anchor the balance. |
| Opening balance | Established via a one-time **opening reconciliation**, suggested on first use; seeds the baseline the running math anchors to. |
| Visibility | Officers only. Non-officers never see the tool or its link. |
| Editing | Any org officer with authority (`HasAuthority(AUTH_KINGDOM/AUTH_PARK, …, AUTH_EDIT)`). Prime Minister is the typical actor. No new dedicated role. |
| Corrections | Editable + soft-delete, with a tamper-evident change log. |
| Attachments | None in v1. Schema reserves a clean seam (`entry_id` FK) for a future `ork_treasury_attachment` table. |
| Currency | Single currency (USD). No symbol config. |
| Reporting | Ledger + running balance, summary panel (current balance, period in/out, by-category), two charts (balance-over-time line, income/expense-by-category), CSV export. |
| Placement | Standalone tool (`Treasury/kingdom/{id}`, `Treasury/park/{id}`), linked from the org Admin menu/sidebar. |

## Entry Fields

**Core (required):**
- Date of transaction
- Direction — credit (in) / debit (out)
- Amount (positive; direction carries the sign)
- Category (from fixed list)
- Payment method — **required**: Cash / Check / Digital Payment
- Description / memo (free text)

**Optional:**
- Counterparty — who paid us / who we paid (free text)
- Reference # — check number or transaction id (free text)

The opening reconciliation is exempt from the payment-method requirement (it is a baseline,
not a transaction).

## Fixed Category List

Stored as canonical keys; display labels live in PHP so reports stay stable if a label is reworded.

- **Income:** `dues`, `fundraiser`, `donation`, `event_revenue`, `income_other`
- **Expense:** `supplies`, `equipment`, `site_rental`, `awards_regalia`, `reimbursement`, `expense_other`

(Final label wording confirmed during implementation; keys are authoritative.)

## Data Model

Four tables, prefix `ork_`, InnoDB / utf8mb4 / utf8mb4_unicode_ci. Migration file:
`db-migrations/2026-06-06-treasury.sql`.

### `ork_treasury_entry` — the ledger
| column | type | notes |
|---|---|---|
| `id` | int(11) PK auto | stable identity (future attachment FK) |
| `owner_type` | enum('kingdom','park') | which org dimension |
| `owner_id` | int(11) | kingdom_id or park_id |
| `entry_date` | date | transaction date |
| `direction` | enum('credit','debit') | money in / out |
| `amount` | decimal(12,2) | always positive |
| `category` | varchar(64) | canonical key from fixed list |
| `payment_method` | enum('cash','check','digital') | required |
| `description` | varchar(255) | free-text memo |
| `counterparty` | varchar(255) NULL | optional |
| `reference_no` | varchar(64) NULL | optional |
| `deleted_at` | datetime NULL | soft-delete |
| `created_by` | int(11) | mundane_id |
| `created_at` | datetime | |
| `updated_at` | datetime NULL | |

Indexes: PK(`id`); KEY(`owner_type`,`owner_id`,`entry_date`); KEY(`deleted_at`).

Note: there is no `is_opening_balance` flag — the opening baseline lives in the
reconciliation table, not as a ledger entry.

### `ork_treasury_reconciliation` — reconciliation snapshots
| column | type | notes |
|---|---|---|
| `id` | int(11) PK auto | |
| `owner_type` | enum('kingdom','park') | |
| `owner_id` | int(11) | |
| `as_of_date` | date | balance is "as of" this date |
| `actual_balance` | decimal(12,2) | treasurer-entered real balance |
| `computed_balance` | decimal(12,2) | snapshot of what the ledger said at reconcile time |
| `variance` | decimal(12,2) | actual − computed (frozen) |
| `explanation` | varchar(500) NULL | required when variance ≠ 0 |
| `is_opening` | tinyint(1) NOT NULL DEFAULT 0 | the one opening reconciliation per org |
| `deleted_at` | datetime NULL | |
| `created_by` | int(11) | |
| `created_at` | datetime | |

Indexes: PK(`id`); KEY(`owner_type`,`owner_id`,`as_of_date`); KEY(`owner_type`,`owner_id`,`is_opening`).

### `ork_treasury_audit` — change log
| column | type | notes |
|---|---|---|
| `id` | int(11) PK auto | |
| `entry_id` | int(11) | FK → ork_treasury_entry.id |
| `action` | enum('create','edit','delete') | |
| `changed_by` | int(11) | mundane_id |
| `changed_at` | datetime | |
| `before_json` | text NULL | row state before (null on create) |
| `after_json` | text NULL | row state after (null on delete) |

Indexes: PK(`id`); KEY(`entry_id`).

### (future) `ork_treasury_attachment` — NOT built in v1
Seam reserved: would FK on `entry_id`. No migration in v1.

## Balance & Reconciliation Semantics

- **Opening balance:** the first reconciliation (`is_opening = 1`) seeds baseline `B0` as of date `D0`.
  Established via the first-run "Set your starting balance" prompt. Exempt from payment-method rules.
- **Running balance** (display, ledger column, current balance):
  `B0 + Σ(credit − debit)` over non-deleted entries with `entry_date >= D0`, ordered by `(entry_date, id)`.
  Before an opening balance exists, the balance is "unanchored" — computed as a pure sum from 0,
  and the UI nudges the officer to set the opening balance.
- **Ongoing reconciliation (informational):** at reconcile time the system computes
  `computed_balance = B0 + Σ(credit − debit)` for entries with `entry_date <= as_of_date`,
  records `variance = actual_balance − computed_balance`, and stores a frozen snapshot.
  Reconciliations NEVER alter the running balance. A real discrepancy is fixed by adding a
  correcting ledger entry, which then flows into the running balance naturally.
- All money math uses `decimal(12,2)` and integer-cent-safe summation in PHP — no floats.
- Negative balances are allowed and shown plainly.

## Architecture (three-layer convention)

### DB layer — `system/lib/ork3/class.Treasury.php` (new)
YAPO wrappers for the four tables. All real authorization checks live here. Methods:
- `GetLedger($owner_type, $owner_id, $filters)` — paged/filtered non-deleted entries with running balance.
- `GetEntry($id)`
- `SaveEntry($data)` — create/edit; validates; writes an `ork_treasury_audit` row.
- `DeleteEntry($id)` — soft-delete + audit row.
- `GetReconciliations($owner_type, $owner_id)` — history, newest first.
- `SaveReconciliation($data)` — computes & snapshots `computed_balance`/`variance`; enforces single opening per org.
- `GetSummary($owner_type, $owner_id, $date_range)` — current balance, period in/out, by-category totals.
- `GetBalanceSeries($owner_type, $owner_id)` — points for the balance-over-time chart.
- `HasOpeningBalance($owner_type, $owner_id)` — bool.
- `ComputeBalanceAsOf($owner_type, $owner_id, $date)` — internal helper.

Every method validates `owner_type`/`owner_id` against the caller's authority via
`Ork3::$Lib->authorization->HasAuthority(...)` and returns proper denials.

### Model layer — `orkui/model/model.Treasury.php` (new, thin)
`APIModel('Treasury')` proxy; pass-through methods; `__call` magic forwards to the lib. No logic.

### Controller layer
- **`controller.Treasury.php`** (new) — full standalone page. Actions `kingdom($id)` and `park($id)`
  gate at the top with `HasAuthority(AUTH_KINGDOM/AUTH_PARK, $id, AUTH_EDIT)`, redirecting
  unauthorized viewers to login/home (same pattern as `Admin/park`/`Admin/kingdom`). Loads summary,
  ledger first page, reconciliation history, and `HasOpeningBalance` for first-run.
- **`controller.TreasuryAjax.php`** (new) — JSON endpoints mirroring `controller.ParkAjax.php`:
  `addentry`, `editentry`, `deleteentry`, `addreconciliation`, `ledger` (paged/filtered fetch),
  `summary`, `series` (chart data), `export` (CSV). Session-presence check in the controller;
  authority enforced in the lib on every call.

### Admin integration
A **"Treasury"** link in the org Admin menu/sidebar — added to `Admin_index.tpl` and to the
`$this->data['menu']['admin']` menu built in `controller.Kingdom.php`/`controller.Park.php` —
shown for both kingdom and park admin contexts to authorized officers only.

### Routing
- Pages: `index.php?Route=Treasury/kingdom/{id}`, `index.php?Route=Treasury/park/{id}`.
- AJAX: `index.php?Route=TreasuryAjax/<action>/<owner_type>/<owner_id>` (+ POST body for writes).

## UI (`Treasury_index.tpl`, new)

Standalone page, all JS/CSS inlined, `tr-` prefixed, dark-mode-compatible from the start.

- **Org header** — heraldry + name so the treasurer knows whose books they're in.
- **Summary header** — current balance (large), total in / total out for the selected date range,
  category breakdown.
- **Charts** — balance-over-time line + income/expense-by-category, using the existing charting infra.
- **Ledger table** — date · category · payment method · description · counterparty · in · out ·
  running balance. Sortable columns, pagination (10/page, matching existing pattern), filters for
  date range, category, and direction.
- **Add/Edit entry modal** — in-product modal (no native dialogs). Flatpickr date with
  `altInput`/human-readable `altFormat`; mandatory payment-method segmented control; category
  dropdown; amount; optional counterparty/reference. Inline delete-confirm via `tnConfirm()`.
- **Reconciliation panel** — "Reconcile" button → modal for actual balance + as-of date; shows
  match ✓ / mismatch ✗ + variance live; explanation field required on mismatch. History list of
  past reconciliations below.
- **First-run** — when `HasOpeningBalance` is false, show a friendly "Set your opening balance"
  prompt instead of an empty ledger.
- **Export** — "Export CSV" button → AJAX export action; respects active filters; same computed
  running balance.

UI conventions to honor (per project memory): no native `title` tooltips (use `data-tip`), no
native `confirm()/alert()` (use `tnConfirm()`), flatpickr human-readable display, global h1–h6
gray-box reset on any custom heading, dark-mode walk before "done".

## Edge Cases & Integrity

- **No opening balance yet** → first-run prompt; entries allowed but balance "unanchored"; UI nudges to set it.
- **Negative balance** → allowed, shown plainly, never blocked.
- **Editing/deleting an entry predating a past reconciliation** → allowed + audit-logged; old
  reconciliation `computed_balance` is a frozen snapshot, so history stays truthful.
- **Concurrent officers** → soft-delete + audit log; last-write-wins on edits; change log shows who did what.
- **Decimal/rounding** → `decimal(12,2)`, integer-cent-safe PHP sums; no floats.
- **Opening reconciliation** → exempt from payment method; cannot be soft-deleted while entries
  depend on it (deleting reverts to unanchored with a warning).
- **CSV export** → respects active filters; same computed running balance.
- **Auth bypass attempts** → every AJAX action re-checks authority in the lib layer (not just the
  page guard); `owner_type`/`owner_id` validated against the authenticated officer's authority on every call.

## Testing

- **Unit (lib):** balance computation (with/without opening anchor, mixed credit/debit, soft-deleted
  rows excluded), reconciliation variance math, audit-row emission on create/edit/delete, authority
  enforcement returns proper denials.
- **Integration (AJAX):** each endpoint with authorized vs unauthorized session; opening-balance flow;
  CSV export contents.
- **Local verification:** curl-auth session pattern against the Docker app (`ork3-php8-app`); seed
  synthetic rows to exercise the ledger (brand-new tables — no migration-lag risk).
- **UI walk:** dark-mode + conventions (modal headers, segmented control, flatpickr alt-format,
  `tnConfirm`, no native dialogs/tooltips) before "done".

## Out of Scope (v1)

- File/receipt attachments (seam reserved).
- Multiple accounts per org / transfers.
- Cross-org roll-up or aggregation.
- Org-managed custom categories.
- Multi-currency.
- Full statement-session reconciliation (QuickBooks-style locked periods).
- Inventory module (separate spec, follows Treasury).
