# Tournament Quick Bracket Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Quick Bracket" button on the tournament Brackets tab creates a single/double-elim bracket with an empty seeded draw that organizers fill by typing names in place, then start with one click — every placed fighter stored exactly like a long-way participant.

**Architecture:** The bracket is a real `setup` bracket with a new `draw_size` column. Each slot pick calls a new `quickplace` endpoint that reuses the long-way add core (registration + entrant + player link + level snapshots) and sets the seed. The empty draw is drawn by the existing `renderElimTree` from client-side placeholder matches, and Start normalizes seeds and then calls the existing `GenerateMatches`. The browser code lives in a new `script/tournament-quickbracket.js` exposing `window.TnQuickBracket`, which hooks into the template's renderer at two points.

**Tech Stack:** PHP 8 (ORK3 lib/model/controller layers), MariaDB, vanilla JS, inline template CSS, PHPUnit 11 (unit), curl + MariaDB assertions (endpoint smoke), Chrome (browser verification).

**Spec:** `docs/superpowers/specs/2026-10-02-tournament-quick-bracket-design.md`

## Global Constraints

- SQL only in `system/lib/ork3/`; `orkui/model/model.Tournament.php` uses explicit passthrough methods; controllers never query.
- Never stage `system/lib/ork3/class.Authorization.php`. Never `git add -A`; stage explicit paths and run `git diff --cached --stat` before every commit. Other uncommitted files in the tree (`controller.Kingdom.php`, `controller.Park.php`, `model.Reports.php`, `class.Report.php`, `Kingdomnew_index.tpl`, `Parknew_index.tpl`) belong to separate work — do not stage them.
- Commit messages end with:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and `Claude-Session: https://claude.ai/code/session_01VGNQJ8uWsrtrs5kf1e2ECy`
- Quick Bracket defaults: Style `Open Weapons`, Participants `individual`, Seeding `manual`, Rings 1, BestOf 3, DurationMinutes 0, FirstRoundMode `byes`.
- Sizes: exactly `4, 8, 12, 16, 24, 32`. Methods: exactly `single`, `double`.
- Copy (verbatim): line-1 prompt `Type to search for player`; empty-line prompt once ≥2 placed `Bye fight or type to search`; alias row `{typed text} (add without persona match)`; double-elim note `Second Chance bracket builds automatically on Start.`
- No native `alert/confirm/prompt`; errors via `window.tnToast(msg)`. Tooltips via `data-tip`, never `title`.
- Every new CSS class gets an `html[data-theme="dark"]` counterpart; phone tap targets ≥ 44px.
- Player search: `KingdomAjax/playersearch/{TnConfig.searchKingdomId}&scope=tiered[&ParkId=…]&q=…` (`&q=`, never `?q=`), custom `kn-ac-results` dropdown positioned with the global `tnFixedAcPosition(input, dropdown)`.
- Public methods on `Tournament` are exposed by the JSON service; any token-free public method must contain `_` in its name.
- Local app: `http://localhost:19080/orkui/index.php?Route=…`. DB: `docker exec ork3-php8-db mariadb -uroot -proot ork`. Log in for curl as `admin` with any password (auth bypass is active locally) using ONE cookie jar in ONE bash block.
- PHP syntax check every touched PHP/TPL file with `php -l`.

## Review Focus

1. **Typing while a co-organizer places a fighter.** A realtime refetch re-renders the draw mid-typing; the open input, its text, and its seed must survive (or move to the next empty seed with a toast if that seed was just taken). → Task 6, Step "Re-render survival".
2. **A fighter added the long way (seed 0) to a quick bracket**, then Start from either the draw or the Brackets-tab card / mobile Generate. They must land in the lowest empty seat in the draw AND in the generated bracket (not jump to seed 1). → Task 2 unit test `testUnseededFillLowestEmptySeat`, Task 4 Step "Route card Generate", Task 7 browser check.
3. **Double-clicking Start or Enter on a slow network.** No duplicate generation / duplicate fighter: Start button disables until the response; QuickPlace rejects a second fighter in the same seed. → Task 2 smoke `duplicate seed rejected`, Task 5 Start handler.
4. **Persona with an apostrophe / HTML characters or an alias like `<b>x`.** Names render as text everywhere (draft lines, dropdown, toasts). → Task 6 uses `textContent`/escaping; Task 7 browser check with alias `O'Brien <b>`.
5. **Pressing Enter on an empty input.** No placement, no request. → Task 6 key handler.

---

### Task 1: `draw_size` column exposed on brackets

**Files:**
- Create: `db-migrations/2026-10-02-bracket-draw-size.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5` (append entry)
- Modify: `system/lib/ork3/class.Tournament.php` — `GetBrackets()` payload (~line 800-818)
- Test: `tests/smoke/tournament-quick-bracket.sh` (created here, extended in Tasks 2–3)

**Interfaces:**
- Produces: every bracket object from `GetBrackets` / `TournamentAjax/tournament/{tid}/brackets` / `TnConfig.bracketData[bid].Bracket` carries `DrawSize` (`int` or `null`).

- [ ] **Step 1: Write the failing smoke test**

Create `tests/smoke/tournament-quick-bracket.sh`:

```bash
#!/usr/bin/env bash
# Endpoint smoke test for Tournament Quick Bracket (spec 2026-10-02).
# Runs against the local dev stack (localhost:19080 + ork3-php8-db). The dev DB is disposable.
# Usage: tests/smoke/tournament-quick-bracket.sh [tournament_id]
set -u
BASE="http://localhost:19080/orkui/index.php?Route="
JAR="$(mktemp)"
DB() { docker exec ork3-php8-db mariadb -uroot -proot ork -N -e "$1" 2>/dev/null; }
TID="${1:-$(DB "SELECT tournament_id FROM ork_tournament ORDER BY tournament_id DESC LIMIT 1")}"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL $1 :: $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected [$3] got [$2]"; fi; }
rej()  { case "$2" in ""|0|null) bad "$1" "expected a rejection (non-zero status) got [$2]";; *) ok "$1";; esac; }
post() { curl -s -b "$JAR" -H "Origin: http://localhost" "${BASE}$1" "${@:2}"; }
J()    { php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ $d=is_array($d)&&array_key_exists($k,$d)?$d[$k]:null; } echo is_bool($d)?($d?"true":"false"):(is_null($d)?"null":(is_array($d)?json_encode($d):$d));' "$1"; }

curl -s -c "$JAR" "${BASE}Login/login" --data "username=admin&password=x" -o /dev/null
echo "Tournament $TID"
check "session warm" "$(post "TournamentAjax/tournament/$TID/reeves" | J status)" "0"

echo "Task 1: DrawSize exposed"
FIRST_DS=$(post "TournamentAjax/tournament/$TID/brackets" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $b=$d["brackets"][0]??null; echo $b===null?"nobracket":(array_key_exists("DrawSize",$b)?"present":"missing");')
if [ "$FIRST_DS" = "nobracket" ]; then echo "  (no brackets yet — DrawSize key checked in Task 2)"; else check "DrawSize key present" "$FIRST_DS" "present"; fi
COL=$(DB "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='ork' AND TABLE_NAME='ork_bracket' AND COLUMN_NAME='draw_size'")
check "draw_size column exists" "$COL" "1"

# --- Task 2 and Task 3 sections are appended below this line ---

rm -f "$JAR"
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
```

Make sure the tournament used has at least one bracket (any existing tournament with brackets works; pass its id as an argument if the latest has none: `DB "SELECT tournament_id FROM ork_bracket ORDER BY bracket_id DESC LIMIT 1"`).

- [ ] **Step 2: Run it to verify it fails**

Run: `chmod +x tests/smoke/tournament-quick-bracket.sh && tests/smoke/tournament-quick-bracket.sh`
Expected: `FAIL draw_size column exists :: expected [1] got [0]` (and `DrawSize key present` FAIL if a bracket exists), non-zero exit.

- [ ] **Step 3: Write the migration and apply it**

`db-migrations/2026-10-02-bracket-draw-size.sql`:

```sql
-- 2026-10-02 Tournament Quick Bracket: remember the starting draw size a quick bracket was
-- created with. NULL for every bracket created via Add Bracket. A bracket with draw_size set,
-- status 'setup', and no matches renders as the Quick Bracket draft draw.
ALTER TABLE ork_bracket ADD COLUMN IF NOT EXISTS draw_size SMALLINT UNSIGNED NULL DEFAULT NULL AFTER first_round_mode;
```

Apply: `docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/2026-10-02-bracket-draw-size.sql`

Append to `tools/ork-db/manifests/migration-classification.json5`, as the last entry inside `"migrations"` (add a comma after the current last entry):

```json5
    "2026-10-02-bracket-draw-size.sql": { "class": "S", "render": "full", "notes": "Quick Bracket starting draw size; nullable column, idempotent ADD COLUMN IF NOT EXISTS" }
```

- [ ] **Step 4: Expose `DrawSize` in `GetBrackets`**

In `Tournament::GetBrackets()`, add after the `'PointScale' => (string)$r->point_scale,` line:

```php
                    'DrawSize'       => ($r->draw_size === null || $r->draw_size === '') ? null : (int)$r->draw_size,
```

- [ ] **Step 5: Run the smoke test to verify it passes**

Run: `php -l system/lib/ork3/class.Tournament.php && tests/smoke/tournament-quick-bracket.sh`
Expected: `PASS=… FAIL=0`.

- [ ] **Step 6: Commit**

```bash
git add db-migrations/2026-10-02-bracket-draw-size.sql tools/ork-db/manifests/migration-classification.json5 system/lib/ork3/class.Tournament.php tests/smoke/tournament-quick-bracket.sh
git diff --cached --stat
git commit -m "Quick Bracket: draw_size column on brackets

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01VGNQJ8uWsrtrs5kf1e2ECy"
```

---

### Task 2: Server — create, place, start

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` — extract `insertBracketEntrant()` from `AddParticipant()` (~1099-1275); add `QUICK_DRAW_SIZES`, `CreateQuickBracket()`, `QuickPlace()`, `StartQuickBracket()`, `quick_seed_order()` (place the new public methods directly after `AddParticipant`)
- Modify: `orkui/model/model.Tournament.php` — three passthroughs
- Modify: `orkui/controller/controller.TournamentAjax.php` — `tournament()` action `quickbracket` (next to `addbracket`); `bracket()` actions `quickplace`, `quickstart` (next to `addparticipant`)
- Create: `tests/Unit/TournamentQuickSeedOrderTest.php`
- Modify: `tests/smoke/tournament-quick-bracket.sh`

**Interfaces:**
- Consumes: `DrawSize` from Task 1.
- Produces:
  - `POST TournamentAjax/tournament/{tid}/quickbracket` fields `Method` (`single|double`), `DrawSize` (int), `ActionId` → `{status:0, bracketId:int}`
  - `POST TournamentAjax/bracket/{bid}/quickplace` fields `TournamentId`, `Seed` (int ≥1), `MundaneId` (int, optional), `Alias` (string, optional; required when no MundaneId), `ActionId` → `{status:0, participantId:int, participantNumber:int, seq:int}`; errors `{status:≠0, error:string}` including `Seed N was just filled.`
  - `POST TournamentAjax/bracket/{bid}/quickstart` fields `TournamentId`, `ActionId` → `{status:0, bracketId:int}`
  - `Tournament::quick_seed_order(array $rows): array` — `$rows` = list of `['ParticipantId'=>int,'Seed'=>int]`, returns participant ids top seed first. Mirrored in JS by `seatEntrants()` (Task 5).

- [ ] **Step 1: Write the failing unit test for the seat rule**

`tests/Unit/TournamentQuickSeedOrderTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Quick Bracket seat rule (spec 2026-10-02): seeded entrants keep their seat, seed-0
 * entrants (added the long way) take the lowest empty seats by participant id, a duplicate
 * seed loses its claim to the lower participant id, then gaps close.
 */
final class TournamentQuickSeedOrderTest extends TestCase
{
    private function rows(array $pairs): array
    {
        return array_map(fn ($p) => ['ParticipantId' => $p[0], 'Seed' => $p[1]], $pairs);
    }

    public function testGapsClose(): void
    {
        $this->assertSame([10, 30, 20], Tournament::quick_seed_order($this->rows([[10, 1], [20, 5], [30, 3]])));
    }

    public function testUnseededFillLowestEmptySeat(): void
    {
        // Seeds 1 and 3 taken; unseeded 40 takes seat 2, unseeded 50 takes seat 4.
        $this->assertSame([10, 40, 30, 50], Tournament::quick_seed_order($this->rows([[50, 0], [30, 3], [40, 0], [10, 1]])));
    }

    public function testDuplicateSeedLowerIdKeepsSeat(): void
    {
        $this->assertSame([10, 20], Tournament::quick_seed_order($this->rows([[20, 1], [10, 1]])));
    }

    public function testEmpty(): void
    {
        $this->assertSame([], Tournament::quick_seed_order([]));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/TournamentQuickSeedOrderTest.php`
Expected: errors `Call to undefined method Tournament::quick_seed_order()`.

- [ ] **Step 3: Implement `quick_seed_order`**

Add to `class.Tournament.php` (after `AddParticipant`):

```php
    /**
     * Quick Bracket final seed order. Seeded entrants keep their seat (a duplicate seed goes to
     * the lower participant id); seed-0 entrants — added the long way — fill the lowest empty
     * seats by participant id; then gaps close. $rows: list of ['ParticipantId'=>int,'Seed'=>int].
     * Returns participant ids, top seed first. Mirrored by seatEntrants() in
     * script/tournament-quickbracket.js — keep the two in step. Pure (no DB, no token), hence
     * the underscore name: the JSON service refuses '_' methods.
     */
    public static function quick_seed_order(array $rows): array
    {
        usort($rows, fn ($a, $b) => (int)$a['ParticipantId'] <=> (int)$b['ParticipantId']);
        $seats    = [];
        $unseeded = [];
        foreach ($rows as $row) {
            $s = (int)$row['Seed'];
            if ($s > 0 && !isset($seats[$s])) {
                $seats[$s] = (int)$row['ParticipantId'];
            } else {
                $unseeded[] = (int)$row['ParticipantId'];
            }
        }
        $s = 1;
        foreach ($unseeded as $pid) {
            while (isset($seats[$s])) {
                $s++;
            }
            $seats[$s] = $pid;
        }
        ksort($seats);
        return array_values($seats);
    }
```

Run: `vendor/bin/phpunit tests/Unit/TournamentQuickSeedOrderTest.php` → Expected: `OK (4 tests…)` (a "No code coverage driver" warning is normal).

- [ ] **Step 4: Extend the smoke test with the Task 2 checks (failing)**

Insert into `tests/smoke/tournament-quick-bracket.sh` at the `# --- Task 2 and Task 3 sections` marker:

```bash
echo "Task 2: create / place / start"
read -r M1 M2 M3 <<<"$(DB "SELECT GROUP_CONCAT(mundane_id SEPARATOR ' ') FROM (SELECT mundane_id FROM ork_mundane WHERE persona <> '' AND active = 1 ORDER BY mundane_id LIMIT 3) x")"
QB=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=8")
BID=$(echo "$QB" | J bracketId)
check "quickbracket status" "$(echo "$QB" | J status)" "0"
check "bracket defaults" "$(DB "SELECT CONCAT_WS('|',style,method,participants,seeding,rings,best_of,status,draw_size) FROM ork_bracket WHERE bracket_id=$BID")" "Open Weapons|single|individual|manual|1|1|setup|8"
rej "bad size rejected" "$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=7" | J status)"
rej "swiss rejected" "$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=swiss&DrawSize=8" | J status)"

P1=$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=1&MundaneId=$M1")
check "place persona" "$(echo "$P1" | J status)" "0"
PID1=$(echo "$P1" | J participantId)
check "persona entrant seed+link" "$(DB "SELECT CONCAT_WS('|',p.seed,pm.mundane_id,p.participant_number>0) FROM ork_participant p JOIN ork_participant_mundane pm ON pm.participant_id=p.participant_id WHERE p.participant_id=$PID1")" "1|$M1|1"
check "persona registration row" "$(DB "SELECT COUNT(*) FROM ork_participant p JOIN ork_participant_mundane pm ON pm.participant_id=p.participant_id WHERE p.tournament_id=$TID AND p.bracket_id IS NULL AND pm.mundane_id=$M1")" "1"
check "persona home scope" "$(DB "SELECT (park_id>0 AND kingdom_id>0) FROM ork_participant WHERE participant_id=$PID1")" "1"
check "persona alias = persona" "$(DB "SELECT p.alias = m.persona FROM ork_participant p JOIN ork_mundane m ON m.mundane_id=$M1 WHERE p.participant_id=$PID1")" "1"

ALIAS="Jynx Furfighter QB$$"
P2=$(post "TournamentAjax/bracket/$BID/quickplace" --data-urlencode "Alias=$ALIAS" --data "TournamentId=$TID&Seed=2")
PID2=$(echo "$P2" | J participantId)
check "place alias" "$(echo "$P2" | J status)" "0"
check "alias has no player link" "$(DB "SELECT COUNT(*) FROM ork_participant_mundane WHERE participant_id=$PID2")" "0"
check "alias registration row" "$(DB "SELECT COUNT(*) FROM ork_participant WHERE tournament_id=$TID AND bracket_id IS NULL AND alias='$ALIAS'")" "1"

rej "duplicate seed rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=1&MundaneId=$M2" | J status)"
rej "duplicate player rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=3&MundaneId=$M1" | J status)"
rej "duplicate alias rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data-urlencode "Alias=$ALIAS" --data "TournamentId=$TID&Seed=3" | J status)"
rej "seed beyond size rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=9&MundaneId=$M2" | J status)"
rej "seed 0 rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=0&MundaneId=$M2" | J status)"

# Parity with the long way: same fighter added via addparticipant to a normal bracket.
LB=$(post "TournamentAjax/tournament/$TID/addbracket" --data "Style=Open%20Weapons&Method=single&Participants=individual&Rings=1&Seeding=manual&BestOf=1" | J bracketId)
LP=$(post "TournamentAjax/bracket/$LB/addparticipant" --data "TournamentId=$TID&MundaneId=$M1&Alias=x" | J participantId)
check "parity: same participant_number" "$(DB "SELECT COUNT(DISTINCT participant_number) FROM ork_participant WHERE participant_id IN ($PID1,$LP)")" "1"
check "parity: same scope+levels" "$(DB "SELECT COUNT(DISTINCT CONCAT_WS('|',park_id,kingdom_id,warrior_level,griffon_level)) FROM ork_participant WHERE participant_id IN ($PID1,$LP)")" "1"

P3=$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=5&MundaneId=$M2")
check "place seed 5" "$(echo "$P3" | J status)" "0"
# A long-way add (seed 0) must take the lowest empty seat (3) at Start.
P4=$(post "TournamentAjax/bracket/$BID/addparticipant" --data "TournamentId=$TID&MundaneId=$M3&Alias=y" | J participantId)
ST=$(post "TournamentAjax/bracket/$BID/quickstart" --data "TournamentId=$TID")
check "quickstart status" "$(echo "$ST" | J status)" "0"
check "bracket active" "$(DB "SELECT status FROM ork_bracket WHERE bracket_id=$BID")" "active"
check "seeds compacted 1..4" "$(DB "SELECT GROUP_CONCAT(participant_id ORDER BY seed) FROM ork_participant WHERE bracket_id=$BID")" "$PID1,$PID2,$P4,$(echo "$P3" | J participantId)"
check "4 fighters -> 4-slot draw" "$(DB "SELECT COUNT(*) FROM ork_match WHERE bracket_id=$BID AND round=1")" "2"
rej "place after start rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=6&MundaneId=$M2" | J status)"

# 6 of 8: byes to seeds 1 and 2 (auto-advanced).
B6=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=8" | J bracketId)
for s in 1 2 3 4 5 6; do post "TournamentAjax/bracket/$B6/quickplace" --data-urlencode "Alias=QB$$ six $s" --data "TournamentId=$TID&Seed=$s" >/dev/null; done
post "TournamentAjax/bracket/$B6/quickstart" --data "TournamentId=$TID" >/dev/null
check "6/8: two auto-resolved byes" "$(DB "SELECT COUNT(*) FROM ork_match WHERE bracket_id=$B6 AND round=1 AND auto_resolved=1")" "2"
check "6/8: byes are seeds 1,2" "$(DB "SELECT GROUP_CONCAT(p.seed ORDER BY p.seed) FROM ork_match m JOIN ork_participant p ON p.participant_id = IF(m.participant_1_id>0,m.participant_1_id,m.participant_2_id) WHERE m.bracket_id=$B6 AND m.round=1 AND m.auto_resolved=1")" "1,2"

# 5 placed in a 16: an 8-slot draw.
B16=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=16" | J bracketId)
for s in 1 2 3 4 5; do post "TournamentAjax/bracket/$B16/quickplace" --data-urlencode "Alias=QB$$ sixteen $s" --data "TournamentId=$TID&Seed=$s" >/dev/null; done
post "TournamentAjax/bracket/$B16/quickstart" --data "TournamentId=$TID" >/dev/null
check "5 in 16 -> 8-slot draw" "$(DB "SELECT COUNT(*) FROM ork_match WHERE bracket_id=$B16 AND round=1")" "4"

# Double: 2 rejected, 3 generates.
BD=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=double&DrawSize=4" | J bracketId)
for s in 1 2; do post "TournamentAjax/bracket/$BD/quickplace" --data-urlencode "Alias=QB$$ dbl $s" --data "TournamentId=$TID&Seed=$s" >/dev/null; done
rej "double with 2 rejected" "$(post "TournamentAjax/bracket/$BD/quickstart" --data "TournamentId=$TID" | J status)"
check "double still setup" "$(DB "SELECT status FROM ork_bracket WHERE bracket_id=$BD")" "setup"
post "TournamentAjax/bracket/$BD/quickplace" --data-urlencode "Alias=QB$$ dbl 3" --data "TournamentId=$TID&Seed=3" >/dev/null
check "double with 3 starts" "$(post "TournamentAjax/bracket/$BD/quickstart" --data "TournamentId=$TID" | J status)" "0"

# Cleanup: delete every bracket this run created (registrations stay — the dev DB is disposable).
for b in $BID $LB $B6 $B16 $BD; do post "TournamentAjax/tournament/$TID/deletebracket" --data "BracketId=$b" >/dev/null; done
```

Run: `tests/smoke/tournament-quick-bracket.sh` → Expected: FAILs starting at `quickbracket status`.

- [ ] **Step 5: Extract the shared entrant core from `AddParticipant`**

Add this private helper next to `ensureRegistrant`:

```php
    /**
     * Shared core of a per-bracket add — the long-way AddParticipant and QuickPlace both run it,
     * so a quick-placed fighter can't drift from a long-way one: auto-register at the tournament
     * level (ensureRegistrant), insert the bracket entrant sharing that participant_number, and for
     * a player link them and snapshot warrior/griffon levels. $person: ['MundaneId'=>int,
     * 'Alias'=>string, 'UnitId'=>int, 'ParkId'=>int, 'KingdomId'=>int] with ParkId/KingdomId already
     * resolved via resolveHomeScope. Caller owns the transaction.
     * Returns ['ParticipantId'=>int, 'ParticipantNumber'=>int]; ParticipantId 0 = save failed.
     */
    private function insertBracketEntrant(int $tournament_id, int $bracket_id, array $person): array
    {
        $mid  = (int)($person['MundaneId'] ?? 0);
        $reg  = $this->ensureRegistrant($tournament_id, $person);
        $pnum = (int)$reg['ParticipantNumber'];

        $this->Participant->clear();
        $this->Participant->tournament_id      = $tournament_id;
        $this->Participant->bracket_id         = $bracket_id;
        $this->Participant->alias              = $person['Alias'] ?? '';
        $this->Participant->unit_id            = (int)($person['UnitId'] ?? 0);
        $this->Participant->park_id            = (int)($person['ParkId'] ?? 0);
        $this->Participant->kingdom_id         = (int)($person['KingdomId'] ?? 0);
        $this->Participant->participant_number = $pnum;
        $this->Participant->save();
        $pid = (int)$this->Participant->participant_id;
        if (!valid_id($pid)) {
            return ['ParticipantId' => 0, 'ParticipantNumber' => $pnum];
        }

        if (valid_id($mid)) {
            $this->Player->clear();
            $this->Player->participant_id = $pid;
            $this->Player->mundane_id     = $mid;
            $this->Player->tournament_id  = $tournament_id;
            $this->Player->bracket_id     = $bracket_id;
            $this->Player->save();
            // Snapshot Order-of-the-Warrior level (0-12) at time of competition.
            $awards_map = $this->fetchAwardsForMundanes([$mid]);
            $lvl  = isset($awards_map[$mid]) ? $this->warriorLevelFromAwards($awards_map[$mid]) : 0;
            $glvl = isset($awards_map[$mid]) ? $this->griffonLevelFromAwards($awards_map[$mid]) : 0;
            $this->db->query(
                "UPDATE " . DB_PREFIX . "participant SET warrior_level = :lvl, griffon_level = :glvl WHERE participant_id = :pid",
                [':lvl' => (int)$lvl, ':glvl' => (int)$glvl, ':pid' => $pid]
            );
        }
        return ['ParticipantId' => $pid, 'ParticipantNumber' => $pnum];
    }
```

In `AddParticipant`, inside `try {` of the non-copy branch, replace everything from `// Ensure a tournament-level registration row exists` through the end of the `if (valid_id($request['MundaneId'])) { … }` block (the single-player link + level snapshot) with:

```php
                $ent = $this->insertBracketEntrant($_tid, (int)$request['BracketId'], [
                    'MundaneId' => $_mid,
                    'Alias'     => $request['Alias'] ?? '',
                    'UnitId'    => (int)($request['UnitId'] ?? 0),
                    'ParkId'    => $_park,
                    'KingdomId' => $_kingdom,
                ]);
                $_pnum = $ent['ParticipantNumber'];
                if (!valid_id($ent['ParticipantId'])) {
                    $this->db->query('ROLLBACK');
                    return InvalidParameter('Participant save failed — check DB sql_mode and table constraints');
                }
                $_pid = $ent['ParticipantId'];

                if (!valid_id($_mid) && !empty($request['Members'])) {
```

…and keep the existing team body (`// Team participant — create durable team record then link members` … through its closing `}`) unchanged as the body of that `if`. The team body reads `$this->Participant->tournament_id / bracket_id / participant_id / alias`, which still hold the entrant row because `insertBracketEntrant` only touches `$this->Player` for players (teams have no `MundaneId`). Change the final return to:

```php
            return Success(['ParticipantId' => (int)$_pid, 'ParticipantNumber' => (int)$_pnum]);
```

Run `php -l system/lib/ork3/class.Tournament.php`, then confirm the long way still works: add a participant through a bracket card in the browser (or run the parity lines of the smoke test after Step 6).

- [ ] **Step 6: Implement `CreateQuickBracket`, `QuickPlace`, `StartQuickBracket`**

Add after `AddParticipant`:

```php
    /** Quick Bracket starting sizes (spec 2026-10-02). */
    private const QUICK_DRAW_SIZES = [4, 8, 12, 16, 24, 32];

    /**
     * Quick Bracket: an individual, manually seeded single/double-elim bracket with the defaults
     * below plus draw_size, which makes the UI render it as an empty seeded draft draw.
     * Request: Token, TournamentId, Method (single|double), DrawSize (4|8|12|16|24|32).
     */
    public function CreateQuickBracket($request)
    {
        $method    = (string)($request['Method'] ?? '');
        $draw_size = (int)($request['DrawSize'] ?? 0);
        if (!in_array($method, ['single', 'double'], true)) {
            return InvalidParameter(null, 'Quick brackets are single or double elimination.');
        }
        if (!in_array($draw_size, self::QUICK_DRAW_SIZES, true)) {
            return InvalidParameter(null, 'Invalid starting size.');
        }
        $r = $this->AddBracket([
            'Token'           => $request['Token'] ?? '',
            'TournamentId'    => (int)($request['TournamentId'] ?? 0),
            'Style'           => 'Open Weapons',
            'StyleNote'       => '',
            'Method'          => $method,
            'Rings'           => 1,
            'Participants'    => 'individual',
            'Seeding'         => 'manual',
            'DurationMinutes' => 0,
            'BestOf'          => 1,
        ]);
        if ($r['Status'] != 0) {
            return $r;
        }
        $bracket_id = (int)$r['Detail'];
        $this->db->query(
            "UPDATE " . DB_PREFIX . "bracket SET draw_size = :ds WHERE bracket_id = :bid",
            [':ds' => $draw_size, ':bid' => $bracket_id]
        );
        return Success($bracket_id);
    }

    /**
     * Quick Bracket: place one fighter into one seat of a draft draw. Runs the same entrant core
     * as the long-way AddParticipant (registration + entrant + player link + level snapshots),
     * then sets the seed. Request: Token, TournamentId, BracketId, Seed, MundaneId and/or Alias,
     * ActionId. A player with no Alias gets their persona.
     */
    public function QuickPlace($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid   = (int)($request['TournamentId'] ?? 0);
        $bid   = (int)($request['BracketId'] ?? 0);
        $seed  = (int)($request['Seed'] ?? 0);
        $mid   = (int)($request['MundaneId'] ?? 0);
        $alias = trim((string)($request['Alias'] ?? ''));
        if (!$this->bracketBelongsTo($bid, $tid)) {
            return InvalidParameter(null, 'Bracket does not belong to this tournament.');
        }
        if (!valid_id($mid) && $alias === '') {
            return InvalidParameter(null, 'Pick a player or type a name.');
        }
        if (valid_id($mid) && $alias === '') {
            $alias = $this->tnActorName($mid);
            if ($alias === '') {
                return InvalidParameter(null, 'Player not found.');
            }
        }
        $alias = mb_substr($alias, 0, 100);
        [$park, $kingdom] = $this->resolveHomeScope($mid, 0, 0);

        $this->db->query('START TRANSACTION');
        try {
            // Lock the bracket row: serializes concurrent placements into the same draft.
            $b = $this->db->query(
                "SELECT status, draw_size FROM " . DB_PREFIX . "bracket WHERE bracket_id = :bid FOR UPDATE",
                [':bid' => $bid]
            );
            if (!$b || !$b->next()) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'Bracket not found.');
            }
            if (!in_array((string)$b->status, ['setup', ''], true)) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'This bracket has already started.');
            }
            $draw_size = (int)$b->draw_size;
            if ($draw_size <= 0) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'Not a quick bracket.');
            }
            $cnt = $this->db->query("SELECT COUNT(*) AS n FROM " . DB_PREFIX . "participant WHERE bracket_id = :bid", [':bid' => $bid]);
            $entrants = ($cnt && $cnt->next()) ? (int)$cnt->n : 0;
            if ($seed < 1 || $seed > max($draw_size, $entrants)) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'Invalid seed.');
            }
            $taken = $this->db->query(
                "SELECT participant_id FROM " . DB_PREFIX . "participant WHERE bracket_id = :bid AND seed = :s LIMIT 1",
                [':bid' => $bid, ':s' => $seed]
            );
            if ($taken && $taken->next()) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'Seed ' . $seed . ' was just filled.');
            }
            if (valid_id($mid)) {
                $dup = $this->db->query(
                    "SELECT participant_id FROM " . DB_PREFIX . "participant_mundane WHERE bracket_id = :bid AND mundane_id = :m LIMIT 1",
                    [':bid' => $bid, ':m' => $mid]
                );
            } else {
                $dup = $this->db->query(
                    "SELECT p.participant_id FROM " . DB_PREFIX . "participant p
					 LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
					 WHERE p.bracket_id = :bid AND pm.mundane_id IS NULL AND p.alias = :a LIMIT 1",
                    [':bid' => $bid, ':a' => $alias]
                );
            }
            if ($dup && $dup->next()) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, $alias . ' is already in this bracket.');
            }

            $ent = $this->insertBracketEntrant($tid, $bid, [
                'MundaneId' => $mid,
                'Alias'     => $alias,
                'UnitId'    => 0,
                'ParkId'    => $park,
                'KingdomId' => $kingdom,
            ]);
            if (!valid_id($ent['ParticipantId'])) {
                $this->db->query('ROLLBACK');
                return InvalidParameter('Participant save failed — check DB sql_mode and table constraints');
            }
            $this->db->query(
                "UPDATE " . DB_PREFIX . "participant SET seed = :s WHERE participant_id = :pid",
                [':s' => $seed, ':pid' => $ent['ParticipantId']]
            );

            $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
            $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
            $seq = $this->tnEmitEvent($tid, $bid, 'participant_placed', [
                'bracket_id' => $bid,
                'seed'       => $seed,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tid, $seq);
        return Success(['ParticipantId' => $ent['ParticipantId'], 'ParticipantNumber' => $ent['ParticipantNumber'], 'Seq' => $seq]);
    }

    /**
     * Quick Bracket Start: normalize seeds with quick_seed_order (seed-0 long-way adds take the
     * lowest empty seats, gaps close) and run the normal generator, which sizes the draw to the
     * fighters placed and gives byes to the top seeds. Request: Token, TournamentId, BracketId, ActionId.
     */
    public function StartQuickBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid = (int)($request['TournamentId'] ?? 0);
        $bid = (int)($request['BracketId'] ?? 0);
        if (!$this->bracketBelongsTo($bid, $tid)) {
            return InvalidParameter(null, 'Bracket does not belong to this tournament.');
        }
        $rows = [];
        $r = $this->db->query(
            "SELECT participant_id, seed FROM " . DB_PREFIX . "participant WHERE bracket_id = :bid",
            [':bid' => $bid]
        );
        if ($r) {
            while ($r->next()) {
                $rows[] = ['ParticipantId' => (int)$r->participant_id, 'Seed' => (int)$r->seed];
            }
        }
        $order = self::quick_seed_order($rows);
        if (count($order) > 0) {
            $ro = $this->ReorderSeeds([
                'Token'        => $request['Token'] ?? '',
                'TournamentId' => $tid,
                'BracketId'    => $bid,
                'Order'        => $order,
            ]);
            if ($ro['Status'] != 0) {
                return $ro;
            }
        }
        return $this->GenerateMatches([
            'Token'        => $request['Token'] ?? '',
            'TournamentId' => $tid,
            'BracketId'    => $bid,
            'ActionId'     => $request['ActionId'] ?? '',
        ]);
    }
```

- [ ] **Step 7: Model passthroughs**

In `orkui/model/model.Tournament.php`, after `add_participant`:

```php
    public function create_quick_bracket($request)
    {
        return $this->Tournament->CreateQuickBracket($request);
    }

    public function quick_place($request)
    {
        return $this->Tournament->QuickPlace($request);
    }

    public function start_quick_bracket($request)
    {
        return $this->Tournament->StartQuickBracket($request);
    }
```

- [ ] **Step 8: Controller actions**

In `controller.TournamentAjax.php` `tournament()`, add before `} elseif ($action === 'generate') {`:

```php
        } elseif ($action === 'quickbracket') {
            $r = $this->Tournament->create_quick_bracket([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'Method'       => trim($_POST['Method'] ?? ''),
                'DrawSize'     => (int)($_POST['DrawSize'] ?? 0),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => (int)($r['Detail'] ?? 0)])
                : $this->modelError($r);

```

In `bracket()`, add before `} elseif ($action === 'removeparticipant') {`:

```php
        } elseif ($action === 'quickplace') {
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $r = $this->Tournament->quick_place([
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'Seed'         => (int)($_POST['Seed'] ?? 0),
                'MundaneId'    => (int)($_POST['MundaneId'] ?? 0),
                'Alias'        => trim($_POST['Alias'] ?? ''),
                'ActionId'     => trim($_POST['ActionId'] ?? ''),
            ]);
            if ($r['Status'] == 0) {
                $d = is_array($r['Detail'] ?? null) ? $r['Detail'] : [];
                echo json_encode([
                    'status'            => 0,
                    'participantId'     => (int)($d['ParticipantId'] ?? 0),
                    'participantNumber' => (int)($d['ParticipantNumber'] ?? 0),
                    'seq'               => (int)($d['Seq'] ?? 0),
                ]);
            } else {
                echo $this->modelError($r);
            }

        } elseif ($action === 'quickstart') {
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $r = $this->Tournament->start_quick_bracket([
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'ActionId'     => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => $bracket_id])
                : $this->modelError($r);

```

Rejections come back through `modelError()` as `{status: <ServiceErrorIds code, non-zero>, error: <message>}`. `InvalidParameter(null, 'msg')` surfaces `msg` as `error`. The smoke test's `rej` helper asserts non-zero.

- [ ] **Step 9: Run unit + smoke tests**

Run: `php -l system/lib/ork3/class.Tournament.php && php -l orkui/model/model.Tournament.php && php -l orkui/controller/controller.TournamentAjax.php && vendor/bin/phpunit tests/Unit/TournamentQuickSeedOrderTest.php && tests/smoke/tournament-quick-bracket.sh`
Expected: unit OK, smoke `FAIL=0`. If an endpoint returns HTTP 500/empty, read `docker logs --tail 50 ork3-php8-app`.

- [ ] **Step 10: Commit**

```bash
git add system/lib/ork3/class.Tournament.php orkui/model/model.Tournament.php orkui/controller/controller.TournamentAjax.php tests/Unit/TournamentQuickSeedOrderTest.php tests/smoke/tournament-quick-bracket.sh
git diff --cached --stat
git commit -m "Quick Bracket: create, place and start endpoints on the long-way entrant core

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01VGNQJ8uWsrtrs5kf1e2ECy"
```

---

### Task 3: Realtime + cache on remove and reorder

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` — `RemoveParticipant()` (~1634), `ReorderSeeds()` (~5468)
- Modify: `orkui/controller/controller.TournamentAjax.php` — `removeparticipant`, `reorder` pass `ActionId`
- Modify: `tests/smoke/tournament-quick-bracket.sh`

**Interfaces:**
- Produces: `removeparticipant` and `reorder` accept `ActionId` and emit `participant_removed` / `seeds_reordered` events carrying `bracket_id`, so `/changes` lets peers refetch that bracket. `ReorderSeeds` busts the report cache.

- [ ] **Step 1: Failing smoke checks**

Append below the Task 2 section (before cleanup — move the cleanup `for b in …` line to the end of the file, just above `rm -f "$JAR"`, and add `$BR` to its list):

```bash
echo "Task 3: realtime on remove/reorder"
BR=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=4" | J bracketId)
RA=$(post "TournamentAjax/bracket/$BR/quickplace" --data-urlencode "Alias=QB$$ r1" --data "TournamentId=$TID&Seed=1" | J participantId)
RB=$(post "TournamentAjax/bracket/$BR/quickplace" --data-urlencode "Alias=QB$$ r2" --data "TournamentId=$TID&Seed=2" | J participantId)
SEQ0=$(DB "SELECT last_seq FROM ork_tournament_seq WHERE tournament_id=$TID")
post "TournamentAjax/bracket/$BR/reorder" --data-urlencode "Order=[$RB,$RA]" --data "TournamentId=$TID&ActionId=qbtest-reorder-$$" >/dev/null
check "reorder emits event" "$(DB "SELECT type FROM ork_tournament_event WHERE tournament_id=$TID AND action_id='qbtest-reorder-$$'")" "seeds_reordered"
check "reorder swapped" "$(DB "SELECT GROUP_CONCAT(participant_id ORDER BY seed) FROM ork_participant WHERE bracket_id=$BR")" "$RB,$RA"
post "TournamentAjax/bracket/$BR/removeparticipant" --data "TournamentId=$TID&ParticipantId=$RA&ActionId=qbtest-remove-$$" >/dev/null
check "remove emits event" "$(DB "SELECT CONCAT_WS('|',type,bracket_id) FROM ork_tournament_event WHERE tournament_id=$TID AND action_id='qbtest-remove-$$'")" "participant_removed|$BR"
check "removed fighter stays registered" "$(DB "SELECT COUNT(*) FROM ork_participant WHERE tournament_id=$TID AND bracket_id IS NULL AND alias='QB$$ r1'")" "1"
check "seq advanced" "$(DB "SELECT last_seq > $SEQ0 FROM ork_tournament_seq WHERE tournament_id=$TID")" "1"
```

Run → Expected: FAIL on `reorder emits event` and `remove emits event`.

- [ ] **Step 2: `RemoveParticipant` emits**

In `RemoveParticipant`, inside the `try {` after the `DELETE FROM … participant …` line and before `COMMIT`, add:

```php
            $seq = 0;
            if ($p_bid > 0) {
                $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
                $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
                $seq = $this->tnEmitEvent($tournament_id, $p_bid, 'participant_removed', [
                    'bracket_id'     => $p_bid,
                    'participant_id' => $participant_id,
                ], $actor_id, $action_id !== '' ? $action_id : null);
            }
```

After the existing `$this->bustTournamentReportCache();` add `if ($seq > 0) { $this->tnPublishSeq($tournament_id, $seq); }` (keep PSR-12 multi-line braces).

- [ ] **Step 3: `ReorderSeeds` emits and busts**

In `ReorderSeeds`, inside the `try {` after the `foreach` and before `COMMIT`, add:

```php
            $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
            $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
            $seq = $this->tnEmitEvent((int)$request['TournamentId'], $bracket_id, 'seeds_reordered', [
                'bracket_id' => $bracket_id,
            ], $actor_id, $action_id !== '' ? $action_id : null);
```

Replace the final `return Success($bracket_id);` with:

```php
        $this->bustTournamentReportCache();
        $this->tnPublishSeq((int)$request['TournamentId'], $seq);
        return Success($bracket_id);
```

- [ ] **Step 4: Controller passes `ActionId`**

In the `removeparticipant` and `reorder` arrays passed to the model, add `'ActionId' => trim($_POST['ActionId'] ?? ''),`. Do **not** pass `ActionId` from `StartQuickBracket` into its `ReorderSeeds` call. The `(tournament_id, action_id)` unique key would make the later `matches_generated` event (same id) an `INSERT IGNORE` no-op. Peers would then never see the generate.

- [ ] **Step 5: Run tests**

Run: `php -l system/lib/ork3/class.Tournament.php && php -l orkui/controller/controller.TournamentAjax.php && tests/smoke/tournament-quick-bracket.sh`
Expected: `FAIL=0`.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Tournament.php orkui/controller/controller.TournamentAjax.php tests/smoke/tournament-quick-bracket.sh
git diff --cached --stat
git commit -m "Tournament: realtime events on participant remove and seed reorder

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01VGNQJ8uWsrtrs5kf1e2ECy"
```

---

### Task 4: Quick Bracket button + modal + script scaffold

**Files:**
- Create: `orkui/template/revised-frontend/script/tournament-quickbracket.js`
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` — Brackets-tab toolbar (both `+ Add Bracket` buttons in the `$totalBrackets > 1` / `elseif ($canManage)` branches, ~2466-2484); modal markup after the Add Bracket modal (just before the `Edit Bracket Modal` comment banner, ~3259); CSS (inline `<style>`, after `html[data-theme="dark"] .tn-bv-tbd-label` ~1506); `<script src>` after the final `</script>` at the end of the file; `tnGenerateMatches` (~9520) routing

**Interfaces:**
- Consumes: `quickbracket` and `quickstart` endpoints (Task 2); `DrawSize` (Task 1).
- Produces: `window.TnQuickBracket` with `openModal()`, `start(bracketId)`, `isDraftBracket(bracketId)`; also the placeholders `isDraft(bd)`, `renderDraft(…)` and `buildDraftBox(m)` that Task 5 fills in. sessionStorage keys `tnQbFocus` (bracket id to auto-focus after Generate) and localStorage `tnQbPrefs` (`{"method":"single","size":8}`).

- [ ] **Step 1: Buttons**

In both toolbar branches, wrap the existing `+ Add Bracket` button in a stack and add Quick Bracket under it:

```php
					<div class="tn-qb-btnstack">
					<button class="tn-btn tn-btn-primary tn-btn-sm" onclick="tnOpenAddBracketModal()">
						<i class="fas fa-plus"></i> Add Bracket
					</button>
					<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="TnQuickBracket.openModal()" data-tip="Empty seeded bracket — type names and start">
						<i class="fas fa-bolt"></i> Quick Bracket
					</button>
					</div>
```

In the `$totalBrackets > 1` branch, the stack replaces the button inside `<?php if ($canManage): ?>`. In the `elseif ($canManage)` branch, it replaces the lone button inside the right-aligned flex div. Also change the empty-state copy `' Use "Add Bracket" to create one.'` to `' Use "Add Bracket" or "Quick Bracket" to create one.'`.

- [ ] **Step 2: Modal markup**

Insert before the `Edit Bracket Modal` comment banner:

```php
<?php if ($canManage): ?>
<!-- =============================================
     Quick Bracket Modal (spec 2026-10-02)
     ============================================= -->
<div class="tn-overlay" id="tn-quickbracket-overlay" role="dialog" aria-modal="true" aria-labelledby="tn-qb-title">
	<div class="tn-modal-box" style="max-width:440px">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title" id="tn-qb-title"><i class="fas fa-bolt" style="margin-right:8px;color:#b7791f"></i>Quick Bracket</h3>
			<button class="tn-modal-close" type="button" aria-label="Close" onclick="tnCloseModal('tn-quickbracket-overlay')">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div class="tn-feedback" id="tn-qb-feedback" style="display:none"></div>
			<div class="tn-qb-field">
				<span class="tn-qb-field-label" id="tn-qb-method-lbl">Format</span>
				<div class="tn-qb-chips" id="tn-qb-method" role="radiogroup" aria-labelledby="tn-qb-method-lbl">
					<button type="button" class="tn-qb-chip" role="radio" data-value="single">Single Elimination</button>
					<button type="button" class="tn-qb-chip" role="radio" data-value="double">Double Elimination</button>
				</div>
			</div>
			<div class="tn-qb-field">
				<span class="tn-qb-field-label" id="tn-qb-size-lbl">Starting Size</span>
				<div class="tn-qb-chips" id="tn-qb-size" role="radiogroup" aria-labelledby="tn-qb-size-lbl">
					<?php foreach ([4, 8, 12, 16, 24, 32] as $_qbs): ?>
					<button type="button" class="tn-qb-chip" role="radio" data-value="<?= $_qbs ?>"><?= $_qbs ?></button>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-outline" type="button" onclick="tnCloseModal('tn-quickbracket-overlay')">Cancel</button>
			<button class="tn-btn tn-btn-primary" type="button" id="tn-qb-generate"><i class="fas fa-bolt"></i> Generate</button>
		</div>
	</div>
</div>
<?php endif; ?>
```

Compare against the Add Bracket overlay (`#tn-addbracket-overlay`) and match its header/footer class names exactly if they differ from the ones above.

- [ ] **Step 3: CSS**

Insert after `html[data-theme="dark"] .tn-bv-tbd-label { color:#718096; }`:

```css
/* ── Quick Bracket (spec 2026-10-02) ── */
.tn-qb-btnstack { display:flex; flex-direction:column; align-items:stretch; gap:6px; }
.tn-qb-field + .tn-qb-field { margin-top:18px; }
.tn-qb-field-label { display:block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#4a5568; margin-bottom:8px; }
.tn-qb-chips { display:flex; flex-wrap:wrap; gap:8px; }
.tn-qb-chip { min-width:52px; min-height:44px; padding:8px 14px; border:1px solid #cbd5e0; border-radius:8px; background:#fff; color:#2d3748; font-weight:600; font-size:14px; cursor:pointer; }
.tn-qb-chip[aria-checked="true"] { background:#2b6cb0; border-color:#2b6cb0; color:#fff; }
.tn-qb-chip:focus-visible { outline:2px solid #2b6cb0; outline-offset:2px; }
.tn-qb-toolbar { display:flex; align-items:center; flex-wrap:wrap; gap:8px; padding:10px 12px; margin-bottom:10px; border:1px solid #e2e8f0; border-radius:8px; background:#f7fafc; }
.tn-qb-badge { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; color:#975a16; text-transform:uppercase; letter-spacing:.03em; }
.tn-qb-count { flex:1; min-width:140px; font-size:13px; color:#4a5568; }
.tn-qb-note { font-size:12px; color:#718096; margin:0 0 8px; }
.tn-qb-line { position:relative; }
.tn-qb-empty { cursor:pointer; }
.tn-qb-empty:hover, .tn-qb-empty:focus-visible { background:#ebf8ff; outline:none; }
.tn-qb-hint { color:#a0aec0; font-style:italic; font-size:12px; }
.tn-qb-input { flex:1; min-width:0; border:none; outline:none; background:transparent; font:inherit; font-size:13px; color:inherit; padding:0; }
.tn-qb-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tn-qb-alias { font-style:italic; }
.tn-qb-alias-tag { flex-shrink:0; font-size:10px; font-weight:700; text-transform:uppercase; color:#718096; border:1px solid #cbd5e0; border-radius:4px; padding:0 4px; }
.tn-qb-clear { flex-shrink:0; border:none; background:none; color:#a0aec0; cursor:pointer; padding:0 4px; font-size:16px; line-height:1; opacity:0; }
.tn-qb-filled:hover .tn-qb-clear, .tn-qb-clear:focus-visible { opacity:1; color:#c53030; }
.tn-qb-filled[draggable="true"] { cursor:grab; }
.tn-qb-drop { box-shadow:inset 0 0 0 2px #3182ce; }
.tn-qb-swap-src { background:#fefcbf; }
.tn-qb-ac-hdr { padding:4px 10px; font-size:11px; font-weight:700; text-transform:uppercase; color:#718096; cursor:default; }
.tn-qb-ac-alias { border-top:1px solid #e2e8f0; }
.tn-qb-ac-alias .tn-qb-ac-sub, .tn-qb-ac-sub { color:#a0aec0; font-size:11px; }
.kn-ac-item.tn-qb-ac-hi { background:#ebf8ff; }
@media (hover:none) { .tn-qb-clear { opacity:1; } }
.tn-mobile .tn-qb-line { min-height:44px; }
.tn-mobile .tn-qb-clear { opacity:1; min-width:44px; min-height:44px; }
html[data-theme="dark"] .tn-qb-field-label { color:#a0aec0; }
html[data-theme="dark"] .tn-qb-chip { background:#2d3748; border-color:#4a5568; color:#e2e8f0; }
html[data-theme="dark"] .tn-qb-chip[aria-checked="true"] { background:#3182ce; border-color:#3182ce; color:#fff; }
html[data-theme="dark"] .tn-qb-toolbar { background:#1a202c; border-color:#4a5568; }
html[data-theme="dark"] .tn-qb-badge { color:#f6e05e; }
html[data-theme="dark"] .tn-qb-count, html[data-theme="dark"] .tn-qb-note { color:#a0aec0; }
html[data-theme="dark"] .tn-qb-empty:hover, html[data-theme="dark"] .tn-qb-empty:focus-visible { background:#2a4365; }
html[data-theme="dark"] .tn-qb-hint { color:#718096; }
html[data-theme="dark"] .tn-qb-alias-tag { color:#a0aec0; border-color:#4a5568; }
html[data-theme="dark"] .tn-qb-clear { color:#718096; }
html[data-theme="dark"] .tn-qb-filled:hover .tn-qb-clear, html[data-theme="dark"] .tn-qb-clear:focus-visible { color:#fc8181; }
html[data-theme="dark"] .tn-qb-drop { box-shadow:inset 0 0 0 2px #63b3ed; }
html[data-theme="dark"] .tn-qb-swap-src { background:#5f370e; }
html[data-theme="dark"] .tn-qb-ac-hdr, html[data-theme="dark"] .tn-qb-ac-sub { color:#a0aec0; }
html[data-theme="dark"] .tn-qb-ac-alias { border-top-color:#4a5568; }
html[data-theme="dark"] .kn-ac-item.tn-qb-ac-hi { background:#2a4365; }
```

- [ ] **Step 4: Script scaffold with modal, prefs, start**

`orkui/template/revised-frontend/script/tournament-quickbracket.js`:

```js
/*
 * Tournament Quick Bracket (spec docs/superpowers/specs/2026-10-02-tournament-quick-bracket-design.md).
 * Loaded by Tournametnew_index.tpl after its inline scripts; exposes window.TnQuickBracket.
 * Page globals used: TnConfig, tnOpenAsSheet, tnCloseModal, tnShowFeedback, tnFixedAcPosition,
 * tnToast, tnNewActionId, tnRegisterAction, tnCollabNudge, tnRefreshAndRender,
 * tnRenderBracketViz, tnOpenEditBracketModal.
 */
(function () {
	'use strict';
	if (!window.TnConfig) return;

	var OVERLAY  = 'tn-quickbracket-overlay';
	var PREF_KEY = 'tnQbPrefs';

	function loadPrefs() {
		var p = { method: 'single', size: 8 };
		try {
			var s = JSON.parse(localStorage.getItem(PREF_KEY) || 'null');
			if (s && (s.method === 'single' || s.method === 'double')) p.method = s.method;
			if (s && [4, 8, 12, 16, 24, 32].indexOf(parseInt(s.size, 10)) !== -1) p.size = parseInt(s.size, 10);
		} catch (e) {}
		return p;
	}
	function savePrefs(p) { try { localStorage.setItem(PREF_KEY, JSON.stringify(p)); } catch (e) {} }

	function setChip(groupId, value) {
		var g = document.getElementById(groupId);
		if (!g) return;
		g.querySelectorAll('.tn-qb-chip').forEach(function (c) {
			var on = c.getAttribute('data-value') === String(value);
			c.setAttribute('aria-checked', on ? 'true' : 'false');
			c.tabIndex = on ? 0 : -1;
		});
	}
	function chipVal(groupId) {
		var c = document.querySelector('#' + groupId + ' .tn-qb-chip[aria-checked="true"]');
		return c ? c.getAttribute('data-value') : null;
	}
	function wireChips(groupId) {
		var g = document.getElementById(groupId);
		if (!g) return;
		g.addEventListener('click', function (e) {
			var c = e.target.closest('.tn-qb-chip');
			if (c) setChip(groupId, c.getAttribute('data-value'));
		});
		// Radio-group arrow keys.
		g.addEventListener('keydown', function (e) {
			if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) === -1) return;
			var chips = Array.prototype.slice.call(g.querySelectorAll('.tn-qb-chip'));
			var i = chips.findIndex(function (c) { return c.getAttribute('aria-checked') === 'true'; });
			i = (i + ((e.key === 'ArrowLeft' || e.key === 'ArrowUp') ? -1 : 1) + chips.length) % chips.length;
			setChip(groupId, chips[i].getAttribute('data-value'));
			chips[i].focus();
			e.preventDefault();
		});
	}

	// POST helper: own-action id (echo dedup), collab nudge, FormData body, JSON reply.
	function post(path, fields) {
		var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
		if (window.tnRegisterAction) window.tnRegisterAction(actionId);
		if (window.tnCollabNudge) window.tnCollabNudge();
		var fd = new FormData();
		Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
		fd.append('ActionId', actionId);
		return fetch(TnConfig.uir + path, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.catch(function () { return { status: 1, error: 'Network error — try again.' }; });
	}

	function openModal() {
		if (!TnConfig.canManage) return;
		var p = loadPrefs();
		setChip('tn-qb-method', p.method);
		setChip('tn-qb-size', p.size);
		var fb = document.getElementById('tn-qb-feedback');
		if (fb) fb.style.display = 'none';
		var btn = document.getElementById('tn-qb-generate');
		if (btn) btn.disabled = false;
		window.tnOpenAsSheet(OVERLAY, {});
		setTimeout(function () { if (btn) btn.focus(); }, 50);
	}

	function generate() {
		var method = chipVal('tn-qb-method');
		var size   = parseInt(chipVal('tn-qb-size'), 10);
		var btn    = document.getElementById('tn-qb-generate');
		if (!method || !size) return;
		savePrefs({ method: method, size: size });
		btn.disabled = true;
		post('TournamentAjax/tournament/' + TnConfig.tournamentId + '/quickbracket', { Method: method, DrawSize: size })
			.then(function (d) {
				if (!d || d.status !== 0) {
					btn.disabled = false;
					tnShowFeedback('tn-qb-feedback', (d && d.error) || 'Could not create the bracket.', false);
					return;
				}
				try {
					sessionStorage.setItem('tnOpenTab', 'bracketviz');
					sessionStorage.setItem('tnRunBracket_' + TnConfig.tournamentId, String(d.bracketId));
					sessionStorage.setItem('tnQbFocus', String(d.bracketId));
				} catch (e) {}
				window.location.reload();
			});
	}

	function isDraftBracket(bracketId) {
		var bd = TnConfig.bracketData && TnConfig.bracketData[bracketId];
		var b  = bd && bd.Bracket;
		return !!b && (parseInt(b.DrawSize, 10) || 0) > 0
			&& (b.Method === 'single' || b.Method === 'double')
			&& (!b.Status || b.Status === 'setup');
	}

	function start(bracketId, btn) {
		if (btn) btn.disabled = true;
		return post('TournamentAjax/bracket/' + bracketId + '/quickstart', { TournamentId: TnConfig.tournamentId })
			.then(function (d) {
				if (!d || d.status !== 0) {
					if (btn) btn.disabled = false;
					window.tnToast((d && d.error) || 'Could not start the bracket.');
					return;
				}
				window.tnRefreshAndRender(bracketId);
			});
	}

	// Filled in by the draft-draw task.
	function isDraft() { return false; }
	function renderDraft() {}
	function buildDraftBox() { return document.createElement('div'); }

	document.addEventListener('DOMContentLoaded', function () {
		wireChips('tn-qb-method');
		wireChips('tn-qb-size');
		var btn = document.getElementById('tn-qb-generate');
		if (btn) btn.addEventListener('click', generate);
	});

	window.TnQuickBracket = {
		openModal: openModal,
		start: start,
		isDraftBracket: isDraftBracket,
		isDraft: isDraft,
		renderDraft: renderDraft,
		buildDraftBox: buildDraftBox,
		_post: post
	};
})();
```

Add after the final `</script>` of `Tournametnew_index.tpl`:

```php
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/tournament-quickbracket.js?v=<?= filemtime(__DIR__ . '/script/tournament-quickbracket.js') ?>"></script>
```

If the Add Bracket modal also wires backdrop-click or Escape to close via code in its IIFE (look near `tnOpenAddBracketModal`), wire `#tn-quickbracket-overlay` the same way inside the DOMContentLoaded handler (`ov.addEventListener('click', function (e) { if (e.target === ov) tnCloseModal(OVERLAY); })`).

- [ ] **Step 5: Route the long-way Generate for quick brackets**

At the top of `window.tnGenerateMatches = function(bracketId, tournamentId, skipConfirm) {`, right after `if (!TnConfig.canManage) return;`, add:

```js
	// Quick Bracket drafts start through quickstart so long-way (seed 0) entrants take the lowest
	// empty seats instead of sorting ahead of seed 1 (spec 2026-10-02).
	if (window.TnQuickBracket && TnQuickBracket.isDraftBracket(bracketId)) { TnQuickBracket.start(bracketId); return; }
```

- [ ] **Step 6: Verify in the browser**

Run `php -l orkui/template/revised-frontend/Tournametnew_index.tpl`. In Chrome (logged in as `heraldsbridge`, any password), open a tournament profile. Then:
- On the Brackets tab, Quick Bracket sits directly under + Add Bracket.
- The modal opens with Single and 8 preselected the first time.
- Picking Double and 16, then Generate, reloads into the Run tab on the new bracket. It shows the existing "Add at least 2 participants" empty state until Task 5.
- Reopening the modal preselects Double and 16.
- The console shows no errors.

Delete the test bracket afterwards.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/script/tournament-quickbracket.js orkui/template/revised-frontend/Tournametnew_index.tpl
git diff --cached --stat
git commit -m "Quick Bracket: button, modal and script scaffold

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01VGNQJ8uWsrtrs5kf1e2ECy"
```

---

### Task 5: Draft draw rendering + toolbar

**Files:**
- Modify: `orkui/template/revised-frontend/script/tournament-quickbracket.js`
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` — `tnRenderBracketViz` (~9765, after `participants.forEach(function(p) { pMap[p.ParticipantId] = p; });`) and `buildMatchBox` (~10667, first line of the function)

**Interfaces:**
- Consumes: `TnQuickBracket.start`, `post` (Task 4); bracket payload `DrawSize`, `Participants[].Seed/ParticipantId/Alias/Persona/MundaneId`.
- Produces: `TnQuickBracket.isDraft(bd)`, `TnQuickBracket.renderDraft(container, bd, bracketId, renderTree)`, `TnQuickBracket.buildDraftBox(m)`; module state `_ctx = { bracketId, bd, seats, eff, placed, canEdit, method }`; `seatEntrants(participants)` (JS mirror of `Tournament::quick_seed_order` seat step); `openEditor(line, seed, value)` placeholder that Task 6 implements; `_focusNext` map.

- [ ] **Step 1: Hooks in the template**

In `tnRenderBracketViz`, directly after the `pMap` build, insert:

```js
		// Quick Bracket draft: an empty seeded draw filled in place (script/tournament-quickbracket.js).
		if (window.TnQuickBracket && window.TnQuickBracket.isDraft(bd)) {
			window.TnQuickBracket.renderDraft(container, bd, bracketId, function(c, ms, pm) { renderElimTree(c, ms, pm, method, bracketId); });
			return;
		}
```

As the first statement of `function buildMatchBox(m, pMap, sectionMatches) {`:

```js
		if (m._qbDraft && window.TnQuickBracket) return window.TnQuickBracket.buildDraftBox(m);
```

- [ ] **Step 2: Draft rendering in the script**

Replace the three placeholder functions (`isDraft`, `renderDraft`, `buildDraftBox`) with:

```js
	var _ctx = null;       // state of the draft currently drawn in the Run view
	var _focusNext = {};   // bracketId → true: open the lowest empty seat's editor after render
	try {
		var _qf = parseInt(sessionStorage.getItem('tnQbFocus'), 10) || 0;
		if (_qf) { _focusNext[_qf] = true; sessionStorage.removeItem('tnQbFocus'); }
	} catch (e) {}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text !== undefined) n.textContent = text;
		return n;
	}
	function nextPow2(n) { var p = 1; while (p < n) p *= 2; return p; }
	function byPid(a, b) { return (parseInt(a.ParticipantId, 10) || 0) - (parseInt(b.ParticipantId, 10) || 0); }

	// JS port of Tournament::bracket_seed_order — standard order (8 → [1,8,4,5,2,7,3,6]),
	// consumed two at a time as round-1 pairings. Must stay identical to the PHP.
	function seedOrder(slots) {
		var rounds = Math.round(Math.log(Math.max(1, slots)) / Math.LN2), seeds = [1];
		for (var r = 0; r < rounds; r++) {
			var next = [], sum = seeds.length * 2 + 1;
			seeds.forEach(function (s) { next.push(s); next.push(sum - s); });
			seeds = next;
		}
		return seeds;
	}

	// seed → participant. Mirrors Tournament::quick_seed_order (before gaps close): seeded
	// entrants keep their seat (duplicate → lower ParticipantId wins), seed-0 entrants take the
	// lowest empty seats by ParticipantId.
	function seatEntrants(participants) {
		var seats = {}, unseeded = [];
		participants.slice().sort(byPid).forEach(function (p) {
			var s = parseInt(p.Seed, 10) || 0;
			if (s > 0 && !seats[s]) seats[s] = p; else unseeded.push(p);
		});
		var s = 1;
		unseeded.forEach(function (p) { while (seats[s]) s++; seats[s] = p; });
		return seats;
	}

	function lowestEmptySeat() {
		for (var s = 1; s <= _ctx.eff; s++) if (!_ctx.seats[s]) return s;
		return 0;
	}

	function isDraft(bd) {
		var b = bd && bd.Bracket;
		return !!b && (parseInt(b.DrawSize, 10) || 0) > 0
			&& (b.Method === 'single' || b.Method === 'double')
			&& (!b.Status || b.Status === 'setup')
			&& Array.isArray(bd.Matches) && bd.Matches.length === 0;
	}

	function draftMatches(bracketId, slots) {
		var order = seedOrder(slots), ms = [], o = 1;
		var rounds = Math.round(Math.log(slots) / Math.LN2);
		for (var i = 0; i < order.length; i += 2) {
			ms.push({ MatchId: 'qb-' + bracketId + '-1-' + (i / 2 + 1), BracketId: bracketId, Round: 1, Match: i / 2 + 1, Order: o++,
				BracketSide: 'winners', Participant1Id: 0, Participant2Id: 0, Result: null, _qbDraft: true, _qbSeeds: [order[i], order[i + 1]] });
		}
		var n = slots / 2;
		for (var r = 2; r <= rounds; r++) {
			n = n / 2;
			for (var m = 1; m <= n; m++) {
				ms.push({ MatchId: 'qb-' + bracketId + '-' + r + '-' + m, BracketId: bracketId, Round: r, Match: m, Order: o++,
					BracketSide: 'winners', Participant1Id: 0, Participant2Id: 0, Result: null, _qbDraft: true, _qbSeeds: null });
			}
		}
		return ms;
	}

	function renderDraft(container, bd, bracketId, renderTree) {
		var b = bd.Bracket, parts = (bd.Participants || []).slice();
		var seats = seatEntrants(parts);
		var maxSeat = 0;
		Object.keys(seats).forEach(function (k) { maxSeat = Math.max(maxSeat, parseInt(k, 10)); });
		var eff = Math.max(parseInt(b.DrawSize, 10) || 0, parts.length, maxSeat);
		_ctx = { bracketId: bracketId, bd: bd, seats: seats, eff: eff, placed: parts.length,
			canEdit: !!TnConfig.canManage, method: b.Method };

		container.appendChild(buildToolbar(b, bracketId));
		if (b.Method === 'double') container.appendChild(el('p', 'tn-qb-note', 'Second Chance bracket builds automatically on Start.'));
		var pMap = {};
		parts.forEach(function (p) { pMap[p.ParticipantId] = p; });
		renderTree(container, draftMatches(bracketId, Math.max(4, nextPow2(eff))), pMap);
		if (_ctx.canEdit) afterRender(container, bracketId);
	}

	// Task 6 replaces this with editor restore + drag/swap wiring.
	function afterRender(container, bracketId) {
		if (_focusNext[bracketId]) {
			delete _focusNext[bracketId];
			var s = lowestEmptySeat();
			var ln = s && container.querySelector('.tn-qb-empty[data-seed="' + s + '"]');
			if (ln) openEditor(ln, s, '');
		}
	}

	function buildToolbar(b, bracketId) {
		var bar = el('div', 'tn-qb-toolbar');
		var badge = el('span', 'tn-qb-badge');
		badge.innerHTML = '<i class="fas fa-bolt"></i> Quick Bracket';
		bar.appendChild(badge);
		var method = (TnConfig.methodLabels || {})[b.Method] || b.Method;
		bar.appendChild(el('span', 'tn-qb-count', method + ' · ' + _ctx.placed + ' of ' + _ctx.eff + ' placed'));
		if (!_ctx.canEdit) return bar;

		var shuffleBtn = el('button', 'tn-btn tn-btn-outline tn-btn-sm');
		shuffleBtn.type = 'button';
		shuffleBtn.innerHTML = '<i class="fas fa-random"></i> Shuffle';
		shuffleBtn.setAttribute('data-tip', 'Randomize the seeds of everyone placed');
		shuffleBtn.disabled = _ctx.placed < 2;
		shuffleBtn.onclick = function () { shuffleSeeds(bracketId); };
		bar.appendChild(shuffleBtn);

		var editBtn = el('button', 'tn-btn tn-btn-outline tn-btn-sm');
		editBtn.type = 'button';
		editBtn.innerHTML = '<i class="fas fa-pen"></i> Edit';
		editBtn.onclick = function () {
			window.tnOpenEditBracketModal(bracketId, {
				style: b.Style, styleNote: b.StyleNote || '', method: b.Method, rings: parseInt(b.Rings, 10) || 1,
				participants: b.Participants, seeding: b.Seeding, durationMinutes: parseInt(b.DurationMinutes, 10) || 0,
				bestOf: parseInt(b.BestOf, 10) || 1, pointRounds: parseInt(b.PointRounds, 10) || 3,
				pointMode: b.PointMode || 'fixed', pointScale: b.PointScale || '5,3,1,0'
			});
		};
		bar.appendChild(editBtn);

		var min = b.Method === 'double' ? 3 : 2;
		var startBtn = el('button', 'tn-btn tn-btn-primary tn-btn-sm');
		startBtn.type = 'button';
		startBtn.innerHTML = '<i class="fas fa-play"></i> Start Bracket';
		if (_ctx.placed < min) {
			startBtn.disabled = true;
			startBtn.setAttribute('data-tip', 'Place at least ' + min + ' fighters to start');
		}
		startBtn.onclick = function () { start(bracketId, startBtn); };
		bar.appendChild(startBtn);
		return bar;
	}

	function seedChip(seed) { return el('span', 'tn-bv-seed', String(seed)); }

	function buildSeatLine(seed) {
		var c = _ctx, line = el('div', 'tn-bv-slot tn-qb-line');
		line.dataset.seed = seed;
		line.appendChild(seedChip(seed));
		if (seed > c.eff) {
			line.classList.add('tn-bv-bye', 'tn-qb-fixed-bye');
			line.appendChild(el('span', '', 'Bye'));
			return line;
		}
		var p = c.seats[seed];
		if (p) {
			line.classList.add('tn-qb-filled');
			var isAlias = !(parseInt(p.MundaneId, 10) > 0);
			line.appendChild(el('span', 'tn-qb-name' + (isAlias ? ' tn-qb-alias' : ''), p.Alias || p.Persona || '—'));
			if (isAlias) line.appendChild(el('span', 'tn-qb-alias-tag', 'alias'));
			if (p._pending) line.classList.add('tn-match-pending');
			if (c.canEdit && !p._pending) decorateFilled(line, p, seed);
			return line;
		}
		line.classList.add('tn-qb-empty');
		var hint = '';
		if (c.placed === 0 && seed === 1) hint = 'Type to search for player';
		else if (c.placed >= 2) hint = 'Bye fight or type to search';
		line.appendChild(el('span', 'tn-qb-hint', hint));
		if (c.canEdit) {
			line.tabIndex = 0;
			line.setAttribute('role', 'button');
			line.setAttribute('aria-label', 'Seed ' + seed + ': search for a player');
			line.addEventListener('click', function () { if (!line.querySelector('.tn-qb-input')) openEditor(line, seed, ''); });
			line.addEventListener('keydown', function (e) {
				if ((e.key === 'Enter' || e.key === ' ') && e.target === line) { e.preventDefault(); openEditor(line, seed, ''); }
			});
		}
		return line;
	}

	function buildDraftBox(m) {
		var box = el('div', 'tn-bv-match tn-qb-match');
		box.dataset.matchid = m.MatchId;
		var hit = el('div', 'tn-bv-hit');
		[0, 1].forEach(function (k) {
			if (m._qbSeeds) {
				hit.appendChild(buildSeatLine(m._qbSeeds[k]));
			} else {
				var s = el('div', 'tn-bv-slot');
				s.appendChild(el('span', 'tn-bv-tbd-label', 'Awaiting Rd ' + (parseInt(m.Round, 10) - 1)));
				hit.appendChild(s);
			}
		});
		box.appendChild(hit);
		return box;
	}

	// Placeholders implemented in Task 6.
	function openEditor() {}
	function decorateFilled() {}
	function shuffleSeeds() {}
```

Update the `window.TnQuickBracket` export to also expose `_seatEntrants: seatEntrants, _seedOrder: seedOrder` (for console checks).

- [ ] **Step 3: Verify in the browser**

Create a single/8 quick bracket and confirm:
- The Run tab shows the toolbar ("Quick Bracket", "Single Elimination · 0 of 8 placed", Shuffle and Start disabled, Edit enabled).
- It shows a 3-round tree with connectors. Line 1 reads "1 · Type to search for player", and round-1 seeds top to bottom are 1,8 / 4,5 / 2,7 / 3,6.
- A 12 bracket shows 16 lines, with seeds 13–16 as fixed "Bye".
- Double/4 shows the Second Chance note.
- The console check `TnQuickBracket._seedOrder(8).join()` returns `1,8,4,5,2,7,3,6`.
- Viewed as a non-manager (log out), the draft draws read-only with no toolbar buttons.

Toggle dark mode and confirm the toolbar and lines are legible.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/script/tournament-quickbracket.js orkui/template/revised-frontend/Tournametnew_index.tpl
git diff --cached --stat
git commit -m "Quick Bracket: empty seeded draft draw with toolbar

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01VGNQJ8uWsrtrs5kf1e2ECy"
```

---

### Task 6: In-line search, placing, clear, shuffle, swap

**Files:**
- Modify: `orkui/template/revised-frontend/script/tournament-quickbracket.js`

**Interfaces:**
- Consumes: `_ctx`, `_focusNext`, `lowestEmptySeat`, `post`, `el` (Task 5); endpoints `quickplace` (Task 2) and `removeparticipant`/`reorder` (Task 3); `TnConfig.registrants` (`ParticipantNumber, Alias, Persona, MundaneId, Status`); playersearch returns an array of `{Persona|Name, MundaneId|mundane_id, KAbbr, PAbbr}`.
- Produces: the finished `openEditor`, `decorateFilled`, `shuffleSeeds`, and `afterRender` (editor restore + swap wiring).

- [ ] **Step 1: Editor and dropdown**

Replace the Task 5 placeholders (`openEditor`, `decorateFilled`, `shuffleSeeds`) and `afterRender` with the code below:

```js
	var _ed = null;          // open editor: { bracketId, seed, input, dd, items, hi, timer, req }
	var _restore = null;     // { bracketId, seed, value } carried across a re-render
	var _localRoster = [];   // fighters registered by this tab since load (TnConfig.registrants is a load-time snapshot)

	function closeEditor(keepRestore) {
		if (!_ed) return;
		clearTimeout(_ed.timer);
		if (_ed.dd && _ed.dd.parentNode) _ed.dd.parentNode.removeChild(_ed.dd);
		if (!keepRestore) _restore = null;
		_ed = null;
	}

	function bracketMundanes() {
		var ids = {}, nums = {}, aliases = {};
		(_ctx.bd.Participants || []).forEach(function (p) {
			var mid = parseInt(p.MundaneId, 10) || 0;
			if (mid > 0) ids[mid] = true; else aliases[String(p.Alias || '').toLowerCase()] = true;
			if (parseInt(p.ParticipantNumber, 10) > 0) nums[parseInt(p.ParticipantNumber, 10)] = true;
		});
		return { ids: ids, nums: nums, aliases: aliases };
	}

	function rosterItems(term) {
		var inB = bracketMundanes(), t = term.toLowerCase(), seen = {}, out = [];
		(TnConfig.registrants || []).concat(_localRoster).forEach(function (r) {
			var num = parseInt(r.ParticipantNumber, 10) || 0, mid = parseInt(r.MundaneId, 10) || 0;
			var name = r.Alias || r.Persona || '';
			var key = mid > 0 ? 'm' + mid : 'a' + name.toLowerCase();
			if (!name || seen[key] || r.Status === 'withdrawn') return;
			if ((num && inB.nums[num]) || (mid && inB.ids[mid]) || (!mid && inB.aliases[name.toLowerCase()])) return;
			if (t && name.toLowerCase().indexOf(t) === -1 && String(r.Persona || '').toLowerCase().indexOf(t) === -1) return;
			seen[key] = true;
			out.push({ kind: 'roster', label: name, sub: 'On the roster', MundaneId: mid, Alias: name });
		});
		return out;
	}

	function renderItems() {
		var dd = _ed.dd;
		dd.innerHTML = '';
		var lastKind = null;
		_ed.items.forEach(function (it, i) {
			if (it.kind === 'roster' && lastKind !== 'roster') dd.appendChild(el('div', 'tn-qb-ac-hdr', 'On the roster'));
			lastKind = it.kind;
			var row = el('div', 'kn-ac-item' + (it.kind === 'alias' ? ' tn-qb-ac-alias' : '') + (i === _ed.hi ? ' tn-qb-ac-hi' : ''));
			row.setAttribute('role', 'option');
			row.setAttribute('aria-selected', i === _ed.hi ? 'true' : 'false');
			if (it.kind === 'alias') {
				row.appendChild(el('b', '', it.label));
				row.appendChild(document.createTextNode(' '));
				row.appendChild(el('span', 'tn-qb-ac-sub', '(add without persona match)'));
			} else {
				row.appendChild(document.createTextNode(it.label));
				if (it.sub && it.kind === 'player') { row.appendChild(document.createTextNode(' ')); row.appendChild(el('span', 'tn-qb-ac-sub', '(' + it.sub + ')')); }
			}
			row.addEventListener('mousedown', function (e) { e.preventDefault(); pick(it); });
			dd.appendChild(row);
		});
		if (!_ed.items.length) { dd.classList.remove('kn-ac-open'); return; }
		window.tnFixedAcPosition(_ed.input, dd);
		dd.classList.add('kn-ac-open');
	}

	function setItems(term, players) {
		var inB = bracketMundanes(), roster = rosterItems(term), rosterMids = {};
		roster.forEach(function (r) { if (r.MundaneId) rosterMids[r.MundaneId] = true; });
		var items = roster.slice();
		(players || []).forEach(function (pl) {
			var mid = parseInt(pl.MundaneId || pl.mundane_id, 10) || 0;
			if (!mid || inB.ids[mid] || rosterMids[mid]) return;
			var sub = pl.KAbbr ? pl.KAbbr + (pl.PAbbr ? ':' + pl.PAbbr : '') : '';
			items.push({ kind: 'player', label: pl.Persona || pl.Name || '', sub: sub, MundaneId: mid, Alias: pl.Persona || pl.Name || '' });
		});
		if (term) items.push({ kind: 'alias', label: term, MundaneId: 0, Alias: term });
		_ed.items = items;
		_ed.hi = 0;
		renderItems();
	}

	function search(term) {
		clearTimeout(_ed.timer);
		setItems(term, []);   // roster + alias row immediately
		if (term.length < 2 || !(TnConfig.searchKingdomId > 0)) return;
		var ed = _ed, req = ++ed.req;
		ed.timer = setTimeout(function () {
			var url = TnConfig.uir + 'KingdomAjax/playersearch/' + TnConfig.searchKingdomId
				+ '&scope=tiered' + (TnConfig.parkId > 0 ? '&ParkId=' + TnConfig.parkId : '')
				+ '&q=' + encodeURIComponent(term);
			fetch(url).then(function (r) { return r.json(); }).then(function (data) {
				if (_ed !== ed || ed.req !== req) return;   // stale response
				setItems(term, Array.isArray(data) ? data : []);
			}).catch(function () {});
		}, 280);
	}

	function openEditor(line, seed, value) {
		closeEditor();
		var hint = line.querySelector('.tn-qb-hint');
		if (hint) hint.parentNode.removeChild(hint);
		var input = el('input', 'tn-qb-input');
		input.type = 'text';
		input.maxLength = 100;
		input.autocomplete = 'off';
		input.placeholder = 'Search player or type a name';
		input.setAttribute('aria-label', 'Seed ' + seed + ' fighter');
		input.value = value || '';
		line.appendChild(input);
		var dd = el('div', 'kn-ac-results tn-qb-ac');
		dd.setAttribute('role', 'listbox');
		document.body.appendChild(dd);
		_ed = { bracketId: _ctx.bracketId, seed: seed, input: input, dd: dd, items: [], hi: 0, timer: null, req: 0 };
		// Remember the open editor so any re-render (own confirm refresh, peer change) reopens it.
		_restore = { bracketId: _ctx.bracketId, seed: seed, value: input.value };
		input.addEventListener('input', function () {
			_restore = { bracketId: _ed.bracketId, seed: seed, value: input.value };
			search(input.value.trim());
		});
		input.addEventListener('keydown', function (e) {
			if (!_ed) return;
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				if (_ed.items.length) { _ed.hi = (_ed.hi + (e.key === 'ArrowDown' ? 1 : -1) + _ed.items.length) % _ed.items.length; renderItems(); }
				e.preventDefault();
			} else if (e.key === 'Enter') {
				e.preventDefault();
				if (input.value.trim() === '' && !_ed.items.length) return;
				if (_ed.items[_ed.hi]) pick(_ed.items[_ed.hi]);
			} else if (e.key === 'Escape') {
				e.preventDefault();
				closeEditor();
				window.tnRenderBracketViz(_ctx.bracketId);
			}
		});
		input.addEventListener('blur', function () {
			setTimeout(function () {
				if (_ed && _ed.input === input && document.activeElement !== input) {
					closeEditor();
					window.tnRenderBracketViz(_ctx.bracketId);
				}
			}, 200);
		});
		input.focus();
		var len = input.value.length;
		input.setSelectionRange(len, len);
		search(input.value.trim());
	}
```

Note: Enter on an empty input with roster suggestions showing picks the first roster entry (zero typing), and Enter on an empty input with nothing listed does nothing (Review Focus 5).

- [ ] **Step 2: Placing (optimistic) and auto-advance**

```js
	function pick(item) {
		if (!_ed || !item || !item.Alias) return;
		var bid = _ed.bracketId, seed = _ed.seed, bd = _ctx.bd;
		closeEditor();
		var temp = { ParticipantId: -seed, Seed: seed, Alias: item.Alias, Persona: item.kind === 'player' ? item.label : '',
			MundaneId: item.MundaneId || 0, _pending: true };
		bd.Participants = (bd.Participants || []).concat([temp]);
		_focusNext[bid] = true;
		window.tnRenderBracketViz(bid);
		var fields = { TournamentId: TnConfig.tournamentId, Seed: seed, Alias: item.Alias };
		if (item.MundaneId > 0) fields.MundaneId = item.MundaneId;
		post('TournamentAjax/bracket/' + bid + '/quickplace', fields).then(function (d) {
			bd.Participants = (bd.Participants || []).filter(function (p) { return p !== temp; });
			if (!d || d.status !== 0) {
				window.tnToast((d && d.error) || 'Could not place that fighter.');
				window.tnRefreshAndRender(bid);
				return;
			}
			_localRoster.push({ ParticipantNumber: d.participantNumber, Alias: item.Alias, Persona: temp.Persona, MundaneId: temp.MundaneId, Status: 'active' });
			window.tnRefreshAndRender(bid);
		});
	}
```

- [ ] **Step 3: Re-render survival and focus (Review Focus 1)**

Replace `afterRender` with:

```js
	function afterRender(container, bracketId) {
		wireSwap(container, bracketId);
		// An editor that was open when the draw re-rendered (own placement, peer change, refresh).
		if (_restore && _restore.bracketId === bracketId) {
			var r = _restore;
			if (!_ctx.seats[r.seed] && r.seed <= _ctx.eff) {
				var same = container.querySelector('.tn-qb-empty[data-seed="' + r.seed + '"]');
				if (same) { openEditor(same, r.seed, r.value); return; }
			} else {
				window.tnToast('Seed ' + r.seed + ' was just filled.');
				var s2 = lowestEmptySeat(), ln2 = s2 && container.querySelector('.tn-qb-empty[data-seed="' + s2 + '"]');
				if (ln2) { openEditor(ln2, s2, r.value); return; }
			}
			_restore = null;
		}
		if (_focusNext[bracketId]) {
			delete _focusNext[bracketId];
			var s = lowestEmptySeat();
			var ln = s && container.querySelector('.tn-qb-empty[data-seed="' + s + '"]');
			if (ln) openEditor(ln, s, '');
		}
	}
```

Also, at the very top of `renderDraft`, call `if (_ed) closeEditor(true);`. The container was wiped, so drop the stale editor but keep `_restore`. In `pick`, call `closeEditor()` (no argument): `_restore` is cleared deliberately because that text was consumed.

`openEditor` sets `_restore` the moment an editor opens, so even an untouched editor reopens after the server-confirm refresh. Escape, blur and `pick` clear it via `closeEditor()`.

- [ ] **Step 4: Clear ×, shuffle, swap (mouse drag + touch long-press-then-tap)**

```js
	function decorateFilled(line, p, seed) {
		var x = el('button', 'tn-qb-clear', '×');
		x.type = 'button';
		x.setAttribute('aria-label', 'Remove ' + (p.Alias || p.Persona || 'fighter') + ' from this bracket');
		x.setAttribute('data-tip', 'Remove from bracket (stays on the roster)');
		x.addEventListener('click', function (e) { e.stopPropagation(); clearSeat(p); });
		line.appendChild(x);
		line.setAttribute('draggable', 'true');
		line.dataset.pid = p.ParticipantId;
	}

	function clearSeat(p) {
		var bid = _ctx.bracketId, bd = _ctx.bd;
		bd.Participants = (bd.Participants || []).filter(function (q) { return q !== p; });
		window.tnRenderBracketViz(bid);
		post('TournamentAjax/bracket/' + bid + '/removeparticipant', { TournamentId: TnConfig.tournamentId, ParticipantId: p.ParticipantId })
			.then(function (d) {
				if (!d || d.status !== 0) window.tnToast((d && d.error) || 'Could not remove that fighter.');
				window.tnRefreshAndRender(bid);
			});
	}

	// Persist a full seat map: Order[i] = participant id in seat i+1, 0 = empty seat.
	function commitSeats(bid, seatToPid) {
		var bd = _ctx.bd, max = 0;
		Object.keys(seatToPid).forEach(function (s) { max = Math.max(max, parseInt(s, 10)); });
		var order = [];
		for (var s = 1; s <= max; s++) order.push(seatToPid[s] || 0);
		(bd.Participants || []).forEach(function (p) {   // optimistic
			for (var k = 1; k <= max; k++) if (seatToPid[k] === p.ParticipantId) p.Seed = k;
		});
		window.tnRenderBracketViz(bid);
		post('TournamentAjax/bracket/' + bid + '/reorder', { TournamentId: TnConfig.tournamentId, Order: JSON.stringify(order) })
			.then(function (d) {
				if (!d || d.status !== 0) window.tnToast((d && d.error) || 'Could not change the seeds.');
				window.tnRefreshAndRender(bid);
			});
	}

	function hasPending() { return (_ctx.bd.Participants || []).some(function (p) { return p._pending; }); }

	function shuffleSeeds(bid) {
		if (hasPending()) return;
		var pids = (_ctx.bd.Participants || []).map(function (p) { return p.ParticipantId; });
		for (var i = pids.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = pids[i]; pids[i] = pids[j]; pids[j] = t; }
		var map = {};
		pids.forEach(function (pid, i) { map[i + 1] = pid; });
		commitSeats(bid, map);
	}

	function swapSeats(bid, fromSeed, toSeed) {
		if (fromSeed === toSeed || hasPending() || toSeed > _ctx.eff) return;
		var map = {};
		Object.keys(_ctx.seats).forEach(function (s) { map[s] = _ctx.seats[s].ParticipantId; });
		var a = map[fromSeed], b = map[toSeed];
		map[toSeed] = a;
		if (b) map[fromSeed] = b; else delete map[fromSeed];
		commitSeats(bid, map);
	}

	var _swapSrc = 0;   // touch: seat chosen by long-press, waiting for a tap on the target
	function wireSwap(container, bid) {
		var lines = container.querySelectorAll('.tn-qb-line:not(.tn-qb-fixed-bye)');
		lines.forEach(function (line) {
			var seed = parseInt(line.dataset.seed, 10);
			if (_swapSrc && seed === _swapSrc) line.classList.add('tn-qb-swap-src');
			// Mouse: HTML5 drag a filled line onto any line.
			line.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', String(seed)); e.dataTransfer.effectAllowed = 'move'; });
			line.addEventListener('dragover', function (e) { e.preventDefault(); line.classList.add('tn-qb-drop'); });
			line.addEventListener('dragleave', function () { line.classList.remove('tn-qb-drop'); });
			line.addEventListener('drop', function (e) {
				e.preventDefault();
				line.classList.remove('tn-qb-drop');
				swapSeats(bid, parseInt(e.dataTransfer.getData('text/plain'), 10) || 0, seed);
			});
			// Touch: long-press a filled line (500ms) to pick it up, then tap the target line.
			var timer = null;
			line.addEventListener('touchstart', function () {
				if (!line.classList.contains('tn-qb-filled')) return;
				timer = setTimeout(function () {
					_swapSrc = seed;
					line.classList.add('tn-qb-swap-src');
					window.tnToast('Tap another line to swap seeds');
				}, 500);
			}, { passive: true });
			['touchend', 'touchmove', 'touchcancel'].forEach(function (ev) {
				line.addEventListener(ev, function () { clearTimeout(timer); }, { passive: true });
			});
			line.addEventListener('click', function (e) {
				if (!_swapSrc) return;
				e.stopPropagation();
				e.preventDefault();
				var src = _swapSrc;
				_swapSrc = 0;
				if (src !== seed) swapSeats(bid, src, seed); else window.tnRenderBracketViz(bid);
			}, true);
		});
	}
```

The capture-phase click listener runs before the empty-line click handler, so a tap during swap mode swaps instead of opening the editor.

- [ ] **Step 5: Verify in the browser**

Single/8 quick bracket, keyboard only after Generate:
1. Line 1 opens focused. Type part of a real persona, then Enter, and the persona is placed in seed 1.
2. Focus jumps to seed 2. Type `O'Brien <b>` then Enter, and the alias row places it. The line shows the literal text with an "alias" tag.
3. Placing a third fighter shows "Bye fight or type to search" on the empty lines.
4. Clearing a fighter with ×, then focusing an empty line with an empty input, lists them under "On the roster". Enter re-places them.
5. Shuffle reorders the fighters. Dragging a filled line onto an empty one moves it, and onto a filled one swaps them.
6. Esc closes the editor.

Then check:
- A second tab on the same Run view updates within ~2 s of each placement.
- Typing in tab A while tab B places someone keeps tab A's input and text, or moves it to the next seed with a toast if that seed was taken.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/script/tournament-quickbracket.js
git diff --cached --stat
git commit -m "Quick Bracket: in-place search and placing, roster-first, clear, shuffle, swap

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01VGNQJ8uWsrtrs5kf1e2ECy"
```

---

### Task 7: End-to-end verification (browser, mobile, dark mode)

**Files:**
- Modify only to fix defects found (each fix in the file that owns it, with its own commit).

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Full smoke + unit run**

Run: `vendor/bin/phpunit tests/Unit/TournamentQuickSeedOrderTest.php && tests/smoke/tournament-quick-bracket.sh`
Expected: both green.

- [ ] **Step 2: Keyboard-only happy path (desktop, Chrome)**

1. Quick Bracket, then Generate (Single/8).
2. Type 8 names, each followed by Enter: mix real personas, roster entries and aliases.
3. Click Start Bracket. The live bracket renders and the toolbar disappears.
4. Every fighter is on the same line and seed they occupied in the draft. Seed 1 is top, and round-1 order is 1v8, 4v5, 2v7, 3v6.
5. The Participants tab roster lists all 8. The Brackets-tab card shows 8 entrants with seeds 1–8.

- [ ] **Step 3: Short field + long-way add (Review Focus 2)**

1. Single/8: place 5 fighters in seeds 1–5.
2. On the Brackets tab, add one more the long way with Add Participant.
3. Back on Run, the long-way fighter shows in seed 6.
4. Start from the Brackets-tab card's Generate button, which routes to quickstart. The result is an 8-draw with seeds 1–2 on byes (auto-advanced), and the long-way fighter is seed 6.
5. Repeat with Start on a phone width through the mobile Generate action sheet.

- [ ] **Step 4: Double-submit (Review Focus 3)**

Double-click Start: one generation, no error toast. Throttle the network (DevTools → Slow 3G), then type a name and press Enter twice fast: one fighter placed, no duplicate.

- [ ] **Step 5: 12 / 24 / double**

- **12:** a 16-line draw. Seeds 13–16 are fixed "Bye" and not clickable.
- **24:** a 32-line draw. Seeds 25–32 are fixed "Bye".
- **Double/4:** Start is disabled at 2 placed and enabled at 3. Starting shows the full double-elim tree.

- [ ] **Step 6: Mobile (iframe harness) and dark mode**

- **Phone width:** use the iframe responsive harness, since `resize_window` is a no-op. The modal is a bottom sheet, and the chips and lines are at least 44px tall. The × is always visible. The dropdown stays anchored under the input while the sheet or page scrolls. Long-press then tap swaps seeds.
- **Dark mode:** check the computed colors of the modal chips, toolbar, empty/hint lines, alias tag, dropdown highlight and swap highlight. All must be legible, with no white-on-white.

- [ ] **Step 7: Fix, re-verify, commit**

Fix each defect in the file that owns it, re-run the affected step, and commit with an explicit file list (`git diff --cached --stat` first).
