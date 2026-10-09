# Tournament Quick Bracket

**Date:** 2026-10-02
**Branch:** `feature/tournament-module`
**Surface:** Tournament profile → Brackets tab (`orkui/template/revised-frontend/Tournametnew_index.tpl`)

## Goal

Let an organizer throw together a single- or double-elimination bracket on the fly:
click **Quick Bracket**, pick a format and size, hit **Generate**, type the fighters'
names into an empty seeded draw, and hit **Start**. No Add Bracket form, no separate
roster registration, no seeding step, no generate confirm.

Every fighter placed this way must end up with exactly the same data as a fighter added
"the long way" (tournament roster registration + bracket entrant + player link + home
scope + level snapshots + seed), so the bracket can afterwards be run, edited, reported
on, and re-seeded like any other.

## Decisions (agreed with the user)

| Topic | Decision |
|---|---|
| Unfilled slots at Start | **Close gaps, byes to top seeds.** Placed fighters keep relative seed order; the generator sizes the draw to the fighters actually placed (e.g. 6 of 8 → seeds 1–2 bye; 5 placed in a 16 → an 8-draw with 3 byes). |
| Weapon style | **Silent default: Open Weapons.** Not in the modal; changeable via Edit bracket. |
| Bouts | **Best of three** by default (user, after first build). Changeable via Edit bracket. |
| Draw layout | **Standard seed order** (8: 1v8, 4v5, 3v6, 2v7 top→bottom; built by the existing `bracket_seed_order`). The empty draw is exactly the bracket that will run. |
| Line prompts | Line 1 shows "Type to search for player" (clears on click). Once two fighters are placed, every empty line shows "Bye fight or type to search" until clicked or the bracket is started. |
| v1 click-savers | Seed-order auto-advance, Enter-to-pick, **roster-first suggestions**, **Shuffle seeds**, **Clear × and drag-swap**. Paste-a-list is **out of scope**. |

## Current State (relevant facts)

- `GenerateMatches` (`class.Tournament.php`) requires the full participant list (≥2; ≥3 for
  double), sorts by `Seed` for `manual` seeding, pads to the next power of two with byes
  (`0`), builds round 1 via `bracket_seed_order`/`build_round1_pairs`, auto-resolves byes,
  and sets the bracket `active` — after which participant adds/removes/reorders are blocked.
  Sparse seeds therefore compact naturally; seed-0 participants would sort **first**.
- Matches do not exist before generation; an empty slot in a match means **bye**.
- `AddParticipant` (setup brackets only) runs `resolveHomeScope` → `ensureRegistrant`
  (tournament-level `bracket_id IS NULL` roster row, `participant_number`) → per-bracket
  entrant row → `participant_mundane` link + warrior/griffon snapshots → cache bust.
  An alias-only fighter is a participant row with no `participant_mundane` link.
- `RemoveParticipant` and `ReorderSeeds` emit **no** realtime event; `ReorderSeeds` also
  does not bust the report cache.
- The bracket viz (`tnRenderBracketViz` → `renderElimTree` → `buildMatchBox`) early-returns an
  empty-state message when a bracket has no matches; empty round-1 slots render as Bye/TBD
  with no click target.
- Player search: custom `kn-ac-results` dropdown, `KingdomAjax/playersearch/{kid}&scope=tiered&ParkId=…&q=…`,
  positioned with `tnFixedAcPosition`.

## Design

### 1. Data

New migration `db-migrations/2026-10-02-bracket-draw-size.sql`:

```sql
ALTER TABLE ork_bracket ADD COLUMN IF NOT EXISTS draw_size SMALLINT UNSIGNED NULL DEFAULT NULL AFTER first_round_mode;
```

Classified in `tools/ork-db/manifests/migration-classification.json5`. `NULL` for every
long-way bracket. A bracket is a **Quick Bracket draft** when `draw_size IS NOT NULL`,
`status = 'setup'`, and it has no matches. After Start, `draw_size` is informational only.
`GetBrackets`/bracket payloads expose it as `DrawSize`.

### 2. Server (lib → model → `TournamentAjax`)

All new actions use the same `check_auth` (AUTH_EDIT on kingdom/park/event or organizer
reeve), session requirement, `csrfOk()`, and `bracketBelongsTo` checks as Add Bracket /
addparticipant. Each accepts `ActionId` and returns `seq`.

1. **`CreateQuickBracket`** — `POST TournamentAjax/tournament/{tid}/quickbracket`
   (`Method` ∈ {single, double}, `DrawSize` ∈ {4, 8, 12, 16, 24, 32}).
   Calls `AddBracket` with: Style `Open Weapons`, Participants `individual`, Seeding
   `manual`, Rings 1, BestOf 3, DurationMinutes 0 (FirstRoundMode stays at its `byes`
   default); then sets `draw_size`. Like Add Bracket, it emits no realtime event (bracket
   creation is a lifecycle change the creator's page reloads for). Returns `bracketId`.
2. **`QuickPlace`** — `POST TournamentAjax/bracket/{bid}/quickplace`
   (`Seed`, and `MundaneId` or `Alias`; `Alias` defaults to the persona name when a player
   is chosen). In one transaction:
   - lock the bracket row (`FOR UPDATE`), require status `setup` and `draw_size` set;
   - require `1 ≤ Seed ≤ draw_size` and that no entrant in the bracket holds that seed
     (else `InvalidParameter('Seed N was just filled')`);
   - reject a `MundaneId` already entered in this bracket;
   - run the `AddParticipant` body (home scope, `ensureRegistrant`, entrant, mundane link,
     level snapshots) — refactor its transactional core into a private helper both callers
     share, so the quick path cannot drift from the long way;
   - set the new entrant's `seed`;
   - `tnEmitEvent('participant_placed')`, commit, `tnPublishSeq`, cache bust.
   Returns `participantId`, `participantNumber`, `seq`.
   A roster-first pick sends the registrant's `MundaneId`/`Alias`; `ensureRegistrant` finds
   the existing roster row (no duplicate).
3. **`StartQuickBracket`** — `POST TournamentAjax/bracket/{bid}/quickstart`.
   Normalizes seeds (entrants with `seed > 0` ascending, then `seed = 0` entrants by
   participant id) via the `ReorderSeeds` write path, then calls `GenerateMatches`
   (which enforces the ≥2 / ≥3 minimums and emits `matches_generated`).

Existing paths reused, with realtime added:

- **Clear ×** → existing `removeparticipant` (entrant removed; roster registration kept).
- **Drag-swap / Shuffle** → existing `reorder` with the new full order.
- `RemoveParticipant` and `ReorderSeeds` gain `ActionId` passthrough, `tnEmitEvent`
  (`participant_removed` / `seeds_reordered`) inside their transaction, `tnPublishSeq`, and
  (`ReorderSeeds`) a cache bust. This also benefits long-way collaboration.

### 3. UI

**Entry point.** A **Quick Bracket** button (`fa-bolt`, secondary style) directly under
**+ Add Bracket** in both Brackets-tab toolbar branches, `$canManage` only.

**Modal** (`#tn-quickbracket-overlay`, `tnOpenAsSheet` → bottom sheet on mobile):
- Format: segmented chips **Single Elimination | Double Elimination**.
- Starting Size: chips **4 · 8 · 12 · 16 · 24 · 32**.
- Both preselect the last choice (`localStorage`, try/catch-wrapped), first-run default
  Single / 8. **Generate** posts `quickbracket`, then shows the new bracket's draft draw
  (sessionStorage `tnOpenTab`/`tnScrollBracket` + reload, matching Add Bracket) with line 1's
  input focused.

**Draft draw.** `tnRenderBracketViz` detects a draft (`DrawSize` set, status setup, no
matches) and, instead of the empty-state message, synthesizes match objects client-side:
`slots = nextPow2(DrawSize)`, round 1 from a JS port of `bracket_seed_order(slots)`,
rounds 2+ as empty placeholders, each slot carrying its seed and the entrant (if any)
holding that seed. These are passed to `renderElimTree` with a `draft` flag so the draw
looks exactly like the live bracket (columns, connectors, zoom viewport). Double-elim
drafts show the winners side plus the note "Second Chance bracket builds automatically
on Start."

Entrants with `Seed = 0` (added the long way) are displayed in the lowest empty seeds. If
entrants exceed `DrawSize`, the draw grows to the next power of two.

**Line states** (round-1 slots, `buildMatchBox` in draft mode):

| State | Rendering |
|---|---|
| Seed > DrawSize (e.g. 13–16 in a 12) | Fixed "Bye", not clickable |
| Line 1, nothing placed | Seed chip + placeholder "Type to search for player" |
| Empty, fewer than two placed | Seed chip only, clickable |
| Empty, two or more placed | Seed chip + "Bye fight or type to search" |
| Filled | Seed chip + persona (alias italic with an "alias" tag) + × (hover on desktop, always on touch) |

Seed chip sits to the left of each line. Rounds 2+ render as plain "Awaiting" boxes.

**Search & pick.** Clicking an empty line swaps in an inline input with a
`kn-ac-results` dropdown positioned by `tnFixedAcPosition`:
- Empty input → "On the roster": tournament registrants (`TnConfig.registrants`) not in
  this bracket.
- 2+ chars → tiered player search (debounced 280 ms), excluding fighters already placed
  in this bracket; registrant matches listed first.
- Last row always: **"{typed text} (add without persona match)"** → alias placement.
- ↑/↓ move, **Enter** picks the highlighted row (top match by default; the alias row when
  there are no matches), **Esc** cancels.
- On pick: optimistic fill with pending pulse; POST `quickplace`; on success focus jumps to
  the **lowest empty seed** with its input open; on failure roll back + `tnToast`, refetch
  if the seed was taken.

**Draft toolbar** (above the draw, `canManage` only): **Start Bracket** (primary; disabled
until 2 placed, 3 for double; one click, no confirm) · **Shuffle** · **Edit** (existing
Edit bracket modal) · live count "6 of 8 placed".

**Drag-swap.** Drag a filled line onto another line (filled → swap; empty → move) with
mouse or touch long-press; commits the full order via `reorder`.

**Realtime.** Every place/clear/swap/shuffle/start uses `tnNewActionId` +
`tnRegisterAction` + `tnCollabNudge`; other open tabs refetch the bracket via the existing
`applyDeltas` path and redraw the draft.

**Styling.** All new classes (`tn-qb-*`) defined in the template's inline CSS with
`html[data-theme="dark"]` counterparts; tap targets ≥44px on phones; no native dialogs;
tooltips via `data-tip`.

## Edge Cases

- **Concurrent placement in the same seed:** row lock + seed check; the loser gets a toast
  and refetches.
- **Duplicate fighter:** excluded from search; server rejects a duplicate `MundaneId` in the
  bracket; a duplicate alias resolves to the same roster registration.
- **Clear ×** removes the bracket entrant only; the fighter stays on the roster and appears
  under "On the roster."
- **Start below minimum** (or any server error): toast, bracket stays in setup and editable.
- **Method change via Edit before Start:** allowed. Size is not editable (it only shapes
  the draft; Start closes gaps).
- **Delete:** existing Delete bracket, unchanged.

## Out of Scope

- Paste-a-list bulk entry.
- Team quick brackets; swiss / round-robin / ironman / points quick brackets.
- Quick Bracket from Kingdom/Park pages (a tournament must exist).
- Rendering the full double-elim losers tree in the draft.

## Testing

**Server (curl, logged-in session):** for each format × size, create a quick bracket;
place a matched persona, an alias, and a roster-first pick; assert the registration row,
entrant row, `participant_mundane` link, home scope, level snapshots, and seed match a
long-way `addparticipant` of the same fighter (row diff); reject duplicate seed and
duplicate player; start with 6/8 → seeds 1–2 auto-advanced; 5 placed in 16 → 8-slot draw;
double with 3 → generates; double with 2 → rejected.

**Browser (Chrome):** keyboard-only flow (8 names, Enter ×8, Start) → live bracket
renders; alias row; roster-first suggestions; Shuffle; drag-swap; Clear ×; 12 and 24
fixed-bye display; line-prompt states; mobile sheet via the iframe harness; dark mode
computed-style checks; second tab sees placements within ~1s.
