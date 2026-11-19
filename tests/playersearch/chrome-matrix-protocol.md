# OrkPlayerSearch — In-Chrome Full-Matrix Test Protocol

Manual/Claude-in-Chrome verification of the v2 player search across the full matrix:
**status** (active / inactive / banned) × **proximity** (park / kingdom / out-of-kingdom) ×
**auth** (anonymous / plain user / officer / admin). Backend equivalents are automated in
`tests/playersearch/banned_matrix.sh` (15/15 green); this doc covers the rendered UI.

App base: `http://localhost:19080/orkui/index.php?Route=`  (use `index.php?Route=...`, NOT clean URLs).
Dark mode: repeat the spot-checks marked **[dark]** with `html[data-theme="dark"]`.

---

## Fixtures (local `ork` DB — verified)

Search term **`blackwolf`** (14 total matches — fits one page; surfaces every tier):

| MundaneId | Persona                       | Status   | Kingdom | Park | Ring from Park 5 |
|-----------|-------------------------------|----------|---------|------|------------------|
| 58269     | Sir Blackwolf Wyngarde        | **Banned** (suspended) | 1 (WL) | 5 (Stormwall) | 0 (park) |
| 2193      | Blackwolf                     | Active   | 1 (WL)  | 26   | 1 (kingdom) |
| 127875    | Blackwolf                     | Active   | 27      | 233  | 2 (other) |
| 139705    | Alpha Blackwolf               | Active   | 11      | 669  | 2 (other) |
| 27706     | Blackwolf                     | Inactive | 19      | 216  | 2 (other) |
| 143676    | Blackwolf                     | Inactive | 12      | 364  | 2 (other) |
| 18367     | Ausric Blackwolf              | **Banned** (suspended) | 31 (out of kd 1) | 79 | 2 (other) |

Secondary term **`loki`** (≈150 non-banned matches) — for **Load more…** + dimmed-inactive checks.

Park **5 = Stormwall** is in Kingdom **1 (Wetlands)**.

## Accounts (login bypass accepts any password)

| Role          | Username | Authority |
|---------------|----------|-----------|
| Anonymous     | —        | not logged in |
| Plain user    | `crom`   | no authority (mundane 2, kingdom 1) |
| Kingdom officer | `Neiva` | CREATE over Kingdom 1 (mundane 119351) |
| Global admin  | `admin`  | global ORK admin (mundane 1) |

Log in: nav to `…?Route=Login/login`, enter the username + any password. (Single-device sessions —
log out / use a fresh profile when switching accounts.)

---

## The core expectation (applies to every surface)

1. **Order is always: active → inactive → banned.** Within a status block, ring order
   park(0) → kingdom(1) → elsewhere(2). Inactive rows render dimmed with a gray **Inactive**
   badge; banned rows render tinted with a red **Banned** (or amber **Suspended**) badge.
2. **Banned visibility is auth-gated.** Anonymous + plain users NEVER see banned rows.
   Officers/admin DO.
3. **Banned scope is one level up.** On a *park* surface an officer sees the *kingdom's* banned
   players only; on a *kingdom* or *global* surface, Amtgard-wide banned.
4. **Load more…** appears when more results exist; clicking appends the next page (no dupes,
   no reshuffle).

---

## Matrix A — Park surface (ring + banned-scope + auth)

**Surface:** Park page → *Add Award* modal → **Player (recipient)** field.
**URL:** `…?Route=Park/index/5` → click *Add Award* → type `blackwolf` in the recipient field.

| # | Logged in as | Expected dropdown for `blackwolf` |
|---|--------------|-----------------------------------|
| A1 | admin   | Active (2193 ring1, then 127875/139705 ring2) → Inactive (27706, 143676, dimmed) → **Banned: Sir Blackwolf Wyngarde (58269)** with red badge. **18367 ABSENT** (kingdom 31, outside park-5's kingdom family). |
| A2 | Neiva (kd-1 officer) | Same as A1 — officer over kingdom 1 sees the kingdom-1 ban (58269); 18367 absent. |
| A3 | crom (plain) | Active + Inactive only. **No banned rows at all** (58269 and 18367 both absent). |
| A4 | anonymous | Modal not reachable (award UI gated); if the search renders, **no banned**. |

**Pass criteria:** tier ordering correct; 58269 shows for A1/A2 with a **Banned** badge and is
**absent** for A3; 18367 never shows on this park surface (proves one-level-up scoping caps at the
kingdom, not Amtgard-wide). **[dark]** repeat A1 — badges legible, dimmed/tinted rows readable.

## Matrix B — Kingdom surface (Amtgard-wide banned)

**Surface:** Kingdom page → *Add Award* modal → **Player (recipient)**.
**URL:** `…?Route=Kingdom/index/1` → *Add Award* → type `blackwolf`.

| # | Logged in as | Expected |
|---|--------------|----------|
| B1 | admin | Active → Inactive → **Banned: both 58269 AND 18367** (kingdom surface = Amtgard-wide bans). |
| B2 | Neiva | Same as B1 — both bans visible (spec: kingdom search includes Amtgard-wide bans). |
| B3 | crom | No banned rows. |

**Pass criteria:** B1/B2 show BOTH banned (contrast with A1/A2 which show only 58269) — this is the
park-vs-kingdom scope difference. B3 none.

## Matrix C — Out-of-kingdom / global (universal header search)

**Surface:** Global header search box (`#UniversalSearch`), present on every page.

| # | Logged in as | Expected for `blackwolf` |
|---|--------------|--------------------------|
| C1 | admin | Players section: active first, then (within budget) banned visible; 18367/58269 appear if within the small header budget — confirm a **Banned** indicator renders. |
| C2 | crom | Players section: no banned. |

(The header search shows a small fixed number of players; for a thorough banned check prefer the
dedicated player surfaces above. C verifies the header path is auth-gated and badge-aware.)

## Matrix D — Pagination (Load more…)

**Surface:** any modal player field; **URL:** Kingdom 1 *Add Award* → type `loki`.

| # | Step | Expected |
|---|------|----------|
| D1 | type `loki` | 15 rows + a **Load more…** row at the bottom. |
| D2 | click *Load more…* | next 15 appended below (no duplicates, order preserved). |
| D3 | keyboard: ArrowDown to the **Load more…** row, press Enter | same as clicking it. |
| D4 | inactive `loki` rows | render dimmed with gray **Inactive** badge, sorted after all active. |

## Matrix E — Surface-context surfaces (exclude / restrict)

| # | Surface | URL / action | Expected |
|---|---------|--------------|----------|
| E1 | **Move INTO kingdom** | Kingdom 1 → Move Player → mode *into* → search `blackwolf` | Kingdom-1 members (2193, 58269) **excluded**; only outside-kingdom-1 players shown. |
| E2 | **Move WITHIN park** | Park 5 → Move Player → *within/out* → search | only park-5 members. |
| E3 | **Merge (kingdom)** | Kingdom 1 → Merge Players → pick A in "keep", search "remove" | the player chosen in "keep" is **excluded** from the other field; only kingdom-1 players selectable (`restrictTo:'kingdom'`). |
| E4 | **Unit add member** | Unit profile → Add Member | GLOBAL (no proximity restriction) — documented exception; current members excluded. |
| E5 | **Award Given By** | any Add Award → Given By field | GLOBAL (cross-kingdom giver allowed) — documented exception; own-kingdom officers ranked first. |
| E6 | **Suspend → Restore** | Admin → Suspend Player → "Restore (Unsuspend)" | a currently-suspended player **is findable** (banned tier surfaces for the admin). |

## Matrix F — Robustness / a11y spot-checks

| # | Check | Expected |
|---|-------|----------|
| F1 | type 1 char | "Type at least 2 characters" hint (no silent empty). |
| F2 | type a non-matching string | "No players found". |
| F3 | inside a modal: open dropdown, press **Escape** once | dropdown closes; **modal stays open** (Escape does not bubble). |
| F4 | open dropdown, scroll/resize the window | dropdown stays glued to the input (reposition on scroll AND resize). |
| F5 | open dropdown near viewport bottom | flips up / clamps height — last rows reachable. |
| F6 | screen reader (VoiceOver) | input announces as combobox; arrowing announces the active option; result count announced. |
| F7 | select a player, then edit the text | hidden MundaneId clears (onClear) — no stale id submitted. |
| F8 | **[dark]** every state | badges, dimmed/tinted rows, "Load more…", loading/error/hint rows all legible. |

---

## Recording

Use `gif_creator` to capture A1 (park banned-scope), B1 (kingdom Amtgard-wide), and D1→D2
(Load more), capturing extra frames before/after each action.

## Known-deferred surfaces (NOT yet on OrkPlayerSearch — expect legacy behavior)

Parknew *Enter Attendance* modal (`#pk-att-player-name`), Kingdom/Park *Move Player* hand-rolled
dropdowns, Admin Control-Panel Move Player, Unit add-member/manager (jQuery-UI/hand-rolled). These
hit the corrected backend (so scope/exclude/ban behavior is right) but do not yet use the shared
component UI. Legacy default-template admin pages (Set Officers, Authorization, Event, Audit Log,
Search page) are out of the 3.5.x scope.
