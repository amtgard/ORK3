# Court Packet & Record Court (Threads 1+2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the printed court script into a three-sheet packet designed to be written on, and build the on-screen catch-up view that reads that paper back in.

**Architecture:** Thread 1 replaces the script overlay's two densities with three sheets sharing one page geometry, all rendered by JS builders already in `Court_detail.tpl`. Thread 2 adds a new route `Court/record/{id}` rendering `Court_record.tpl`, whose rows mirror Sheet 2's columns exactly and whose marks are eager server writes through endpoints that already exist. No new grant path: every mark routes to `grant_award` / `skip_award` / `unstage_award`, and Finalize & Complete stays the single commit.

**Tech Stack:** PHP 8.2, MariaDB, PHPUnit 10 (`vendor/bin/phpunit`), plain-PHP `.tpl` templates, FontAwesome 7, Flatpickr.

**Spec:** `docs/superpowers/specs/2026-09-01-court-recs-workflow-design.md` — §4 (Thread 1), §5 (Thread 2), §10 (Mobile). Read both before starting; the spec is the binding authority and this plan argues from it.

**Depends on Thread 0** (shipped, `99f9a533..f6b721f8`): `update_court` (0.1) backs the top strip; partial `update_award` (0.6) makes the citation save non-destructive; skipped-rows-on-print (0.3) is what Sheet 2 extends; `RowVersion` on `grant_award` (0.4) is what makes eager marks safe under two recorders.

## Global Constraints

- **`.tpl` files are PLAIN PHP, not Smarty.** `<?php ?>` / `<?= ?>`. `{$var}` renders literally.
- **DB work belongs in `system/lib/ork3/` only.** Controllers call models; models call the lib. `Model_Court` uses a **factory**: `return $this->_court()->method(...);`.
- **`$DB->Clear()` before a raw `Execute`/`DataSet`**; `DataSet()` needs a manual `->Next()`.
- **No native `confirm()`/`alert()`/`prompt()`** — `cpConfirm`/`cpAlert` on Court pages. **Never call `window.print()` from automation** — it blocks the browser session. Use DevTools "Emulate CSS media type: print".
- **Tooltips `data-tip`, never native `title`.**
- **Dark mode required:** `html[data-theme="dark"]`.
- **Mobile is blocking:** 390px and 768px, no horizontal page scroll, no interactive target under 44px. `Court_detail.tpl` already has `@media (max-width:768px)`, `@media (max-width:600px)` and a `@media (pointer: coarse)` block — extend those, do not invent a fourth breakpoint. The house rule is **≥44px hit area via padding, not larger glyphs** (`Court_detail.tpl:1058`).
- **`resize_window` is frequently a no-op** — measure in a same-origin iframe with `getBoundingClientRect()`, filtering to `offsetParent !== null`. Never report a number you did not measure.
- **Controller session accessor** is `$this->session->user_id`, never `$this->__session`.
- **Dates** use Flatpickr `altInput: true`, `altFormat: 'F j, Y'`.
- **Before editing an existing PHP file** run `awk '/^\t/{c++}END{print c+0}' <file>`. If `0`, edit directly. If non-zero, STOP and report — do not reformat.
- **Never stage `system/lib/ork3/class.Authorization.php`** (uncommitted local auth bypass), `CLAUDE.md`, or `agent-instructions/claude.md`. **Never `git add -A` or `git add .`.** Never `git stash`.
- **Restore any shared data you mutate**, and say so in your report.
- App base path is `/orkui/index.php`. Log in as `heraldsbridge`, any password, one cookie jar, POST `Login/login` first.
- Tests: `vendor/bin/phpunit --filter CourtPacketTest` / `--testsuite integration`. **A SKIP is not a PASS.**
- DB: `docker exec -i ork3-php8-db mariadb -u ork -psecret ork -e "<sql>"`

---

## File Structure

**Created:**
- `db-migrations/2026-09-02-court-last-printed.sql` — one column backing the printed stamp and the drift warning.
- `orkui/template/default/Court_record.tpl` — the Record Court view. Separate from `Court_detail.tpl` because that file is already 4,400+ lines and the two surfaces share data, not markup.
- `tests/Integration/CourtPacketTest.php` — lib-level tests for the new queries and the drift stamp.

**Modified:**
- `system/lib/ork3/class.Court.php` — `markCourtPrinted`, `getPrepSheetRows`, `getRecordRows`.
- `orkui/model/model.Court.php` — pass-throughs.
- `orkui/controller/controller.Court.php` — new `record()` action.
- `orkui/controller/controller.CourtAjax.php` — `mark_printed`, plus the walk-on add arm if the existing one needs a JSON shape.
- `orkui/template/default/Court_detail.tpl` — sheet selector replacing the density toggle; three sheet builders; hero button consolidation.
- `tools/ork-db/manifests/migration-classification.json5` — classify the migration.

---

## Task 1: Migration — `last_printed_at`

**Files:**
- Create: `db-migrations/2026-09-02-court-last-printed.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5`

**Interfaces:**
- Consumes: nothing.
- Produces: `ork_court.last_printed_at DATETIME NULL`, read by Tasks 2 and 11.

An unclassified migration makes `drift-check --strict` block the whole unit-test run, so the classification is part of this task.

- [ ] **Step 1: Write the migration**

```sql
-- When any sheet of the court packet was last printed. Backs the "printed <date>"
-- stamp on the paper and the "the plan changed since this was printed" warning on
-- the Record Court view. See docs/superpowers/specs/2026-09-01-court-recs-workflow-design.md §4/§5.
ALTER TABLE ork_court
  ADD COLUMN last_printed_at DATETIME NULL DEFAULT NULL AFTER recorder_mundane_id;
```

- [ ] **Step 2: Apply and verify**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-09-02-court-last-printed.sql
docker exec -i ork3-php8-db mariadb -u ork -psecret ork -e "SHOW COLUMNS FROM ork_court LIKE 'last_printed_at';"
```
Expected: one row, `datetime`, `Null: YES`, `Default: NULL`.

- [ ] **Step 3: Classify it**

In `tools/ork-db/manifests/migration-classification.json5`, after the `2026-09-01-court-recorder.sql` line (keep comma discipline; `dev-set-test-logins.php` stays last with no trailing comma):

```json5
    "2026-09-02-court-last-printed.sql": { "class": "S", "render": "full", "notes": "Timestamp of the last court-packet print, for the paper stamp and drift warning" },
```

- [ ] **Step 4: Confirm the unit gate still runs**

```bash
vendor/bin/phpunit --testsuite unit 2>&1 | tail -5
```
Expected: the suite completes with no drift-check abort naming the new migration.

- [ ] **Step 5: Commit**

```bash
git add db-migrations/2026-09-02-court-last-printed.sql tools/ork-db/manifests/migration-classification.json5
git commit -m "Court: add last_printed_at to ork_court"
```

---

## Task 2: Sheet infrastructure — selector, shared chrome, page geometry

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`, `system/lib/ork3/class.Court.php`, `orkui/model/model.Court.php`, `orkui/controller/controller.CourtAjax.php`
- Create: `tests/Integration/CourtPacketTest.php`

**Interfaces:**
- Consumes: `ork_court.last_printed_at` (Task 1).
- Produces, relied on by Tasks 3–5 and 11:
  - `Court::markCourtPrinted(int $court_id): bool` — stamps `last_printed_at = NOW()`.
  - `Model_Court->mark_court_printed()`, endpoint `CourtAjax/mark_printed`.
  - JS `cpSetSheet(name)` where name ∈ `order` | `record` | `prep`, and `cpRenderSheet()` dispatching to the three builders.
  - `cpSheetChrome()` returning the shared header/footer HTML each sheet embeds.

**One page geometry for all three sheets.** Named `@page` rules with differing sizes have patchy support; keep the existing single reparented overlay and its one `@page { margin: 0.6in }` (`Court_detail.tpl` print block), and swap the body content instead.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/CourtPacketTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Court packet (Thread 1) + Record Court (Thread 2) — see
 * docs/superpowers/specs/2026-09-01-court-recs-workflow-design.md §4 and §5.
 */
final class CourtPacketTest extends TestCase
{
    private CourtFixture $fixture;
    private Court $court;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fixture = CourtFixture::create();
        $this->court = new Court();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testMarkCourtPrintedStampsTheCourt(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);

        $this->assertNull($this->fixture->fetchCourt($courtId)['last_printed_at']);
        $this->assertTrue($this->court->markCourtPrinted($courtId));

        $stamped = $this->fixture->fetchCourt($courtId)['last_printed_at'];
        $this->assertNotNull($stamped, 'Printing must record when it happened.');
        $this->assertGreaterThan(0, strtotime((string)$stamped));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter CourtPacketTest`
Expected: FAIL, `Call to undefined method Court::markCourtPrinted()`.

- [ ] **Step 3: Implement the lib method and its plumbing**

In `class.Court.php`:

```php
    /**
     * Stamp when the court packet was last printed (spec §4). Backs the paper's
     * "printed <date>" line and the Record Court drift warning.
     */
    public function markCourtPrinted($court_id)
    {
        $court_id = (int)$court_id;
        if (!valid_id($court_id)) {
            return false;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court SET last_printed_at = NOW() WHERE court_id = ' . $court_id
        );

        return $rs && $rs->Size() >= 1;
    }
```

Model pass-through:

```php
    public function mark_court_printed($court_id)
    {
        return $this->_court()->markCourtPrinted($court_id);
    }
```

Endpoint in `controller.CourtAjax.php`, following the shape of the existing actions:

```php
    public function mark_printed($p = null)
    {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        if (!valid_id($court_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid court.']);
        }

        $this->requireCourtAuth($court_id);

        if (!$this->Court->mark_court_printed($court_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Could not record the print.']);
        }

        $this->jsonOut(['status' => 0]);
    }
```

Also widen `Court::getCourtDetail`'s projection to return `last_printed_at` as `LastPrintedAt` — Task 11 reads it.

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit --filter CourtPacketTest`
Expected: PASS.

- [ ] **Step 5: Replace the density toggle with a sheet selector**

In the script overlay's `.cp-script-controls`, replace the two density buttons with three sheet buttons, keeping the existing `.cp-script-density` styling (it is a segmented control and already has dark-mode and 44px rules):

```php
                <div class="cp-script-density" role="group" aria-label="Sheet">
                    <button type="button" data-sheet="order"  class="active" onclick="cpSetSheet('order')">Order of Court</button>
                    <button type="button" data-sheet="record"           onclick="cpSetSheet('record')">Court Record</button>
                    <button type="button" data-sheet="prep"             onclick="cpSetSheet('prep')">Prep Sheet</button>
                </div>
```

Keep the existing Close and Print buttons. Density becomes a property of Sheet 1 only and is handled inside Task 3.

- [ ] **Step 6: Add the dispatcher and shared chrome**

```javascript
    var cpSheet = 'order';

    // Shared header/footer every sheet embeds. The URL is what lets whoever is
    // holding the paper reach the right screen without hunting (spec 0.7).
    function cpSheetChrome() {
        var printed = new Date().toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: 'numeric', minute: '2-digit'
        });
        // courtId is IIFE-scoped at :2151 and NOT exported today. Export it
        // (`window.courtId = courtId;`) beside the existing window.courtAwards /
        // window.courtMeta exports — these builders live OUTSIDE that IIFE.
        var url = window.location.origin + '<?= UIR ?>' + 'Court/record/' + window.courtId;
        return {
            head: '<div class="cp-sheet-stamp">printed ' + esc(printed) + '</div>',
            foot: '<div class="cp-sheet-foot">Record this court at: ' + esc(url) + '</div>'
        };
    }

    function cpSetSheet(name) {
        cpSheet = (name === 'record' || name === 'prep') ? name : 'order';
        document.querySelectorAll('.cp-script-density button').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-sheet') === cpSheet);
        });
        cpRenderSheet();
    }

    function cpRenderSheet() {
        var body = document.getElementById('cp-script-body');
        if (!body) return;
        var awards = cpScriptActiveAwards();
        var chrome = cpSheetChrome();
        var inner = cpSheet === 'record' ? cpSheetRecord(awards)
                  : cpSheet === 'prep'   ? cpSheetPrep(awards)
                  :                        cpSheetOrder(awards);
        body.innerHTML = chrome.head + inner + chrome.foot;
    }
```

Point the existing render entry point at `cpRenderSheet`, and have `cpPrintScript()` POST `CourtAjax/mark_printed` (fire-and-forget, non-blocking — a failed stamp must never block printing) before invoking print.

Stub `cpSheetOrder`, `cpSheetRecord`, `cpSheetPrep` to return `''` for now; Tasks 3–5 fill them.

- [ ] **Step 7: Style the shared chrome for screen and paper**

```css
.cp-sheet-stamp { text-align: right; font-size: 11px; color: #718096; margin-bottom: 6px; }
.cp-sheet-foot { margin-top: 14px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #718096; word-break: break-all; }
html[data-theme="dark"] .cp-sheet-stamp,
html[data-theme="dark"] .cp-sheet-foot { color: #97a3b4; }
html[data-theme="dark"] .cp-sheet-foot { border-top-color: #2d3748; }
```

Inside the existing `@media print` block:

```css
    body.cp-script-open .cp-sheet-stamp,
    body.cp-script-open .cp-sheet-foot { color: #000; }
    /* Multi-page records must keep their column labels. */
    body.cp-script-open thead { display: table-header-group; }
    body.cp-script-open tr { break-inside: avoid; }
```

- [ ] **Step 8: Verify**

Open a court, open the script overlay. Confirm three buttons render, switching sets `active` correctly, the printed stamp and URL footer appear on every sheet, and the overlay still closes on Escape. Confirm `last_printed_at` is written after a print (check the DB; trigger the print path via the button, then read the column — do **not** trigger a native print dialog from automation).

- [ ] **Step 9: Commit**

```bash
git add tests/Integration/CourtPacketTest.php system/lib/ork3/class.Court.php orkui/model/model.Court.php orkui/controller/controller.CourtAjax.php orkui/template/default/Court_detail.tpl
git commit -m "Court packet: sheet selector, shared chrome, printed stamp (spec 4)"
```

---

## Task 3: Sheet 1 — Order of Court

**Files:** Modify `orkui/template/default/Court_detail.tpl`

**Interfaces:**
- Consumes: `cpSheetChrome()`, `cpScriptActiveAwards()`, `cpScriptRecipient()`, `cpScriptAwardLabel()`, `cpScriptPtlMark()`, `cpScriptArtisans()` (all existing).
- Produces: `cpSheetOrder(awards)`.

This is what a herald reads aloud, standing, at arm's length, in bad light. It is the existing citation density plus the checkbox it currently drops.

- [ ] **Step 1: Build the sheet**

```javascript
    function cpSheetOrder(awards) {
        if (!awards.length) return '<p class="cp-script-empty">No awards to present.</p>';
        return '<div class="cp-sheet-order">' + awards.map(function (a, i) {
            var skipped = a.Status === 'cancelled';
            var html = '<div class="cp-script-cite' + (skipped ? ' cp-script-skipped' : '') + '">' +
                '<div class="cp-script-cite-head">' +
                    '<span class="cp-script-cite-num">' + (i + 1) + '.</span> ' +
                    '<span class="cp-sheet-box">' + (a.Status === 'given' || a.Status === 'staged' ? '&#9745;' : '&#9744;') + '</span> ' +
                    '<span class="cp-script-cite-recip">' + cpScriptRecipient(a) + '</span> ' +
                    '<span class="cp-script-cite-award">' + cpScriptAwardLabel(a) + cpScriptPtlMark(a) + '</span>' +
                    (skipped ? ' <span class="cp-script-skipmark">(skipped)</span>' : '') +
                '</div>';
            if (a.PublicComment) html += '<div class="cp-script-cite-text">' + esc(a.PublicComment) + '</div>';
            var art = cpScriptArtisans(a);
            if (art) html += '<div class="cp-script-cite-artisans"><strong>Artisans to thank:</strong> ' + esc(art) + '</div>';
            return html + '</div>';
        }).join('') + '</div>';
    }
```

- [ ] **Step 2: Set it for reading, not scanning**

```css
/* Sheet 1 is read aloud at arm's length — larger than the screen default. */
.cp-sheet-order { font-family: Georgia, 'Times New Roman', serif; }
.cp-sheet-order .cp-script-cite-head { font-size: 16px; line-height: 1.5; }
.cp-sheet-order .cp-script-cite-text { font-size: 14px; line-height: 1.55; margin-top: 4px; }
.cp-sheet-box { font-size: 15px; }
```

In the `@media print` block, bump it once more — paper is read further away than a screen:

```css
    body.cp-script-open .cp-sheet-order .cp-script-cite-head { font-size: 13pt; }
    body.cp-script-open .cp-sheet-order .cp-script-cite-text { font-size: 11pt; }
```

- [ ] **Step 3: Verify**

On a court with citations, artisans, a pass-to-local award and at least one skipped award: confirm the checkbox renders on every row (it did not before), skipped rows are struck through and marked, citations and "Artisans to thank" appear, and the whole sheet is legible in dark mode. Check print-media emulation for size and page breaks.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court packet: Sheet 1, Order of Court (spec 4)"
```

---

## Task 4: Sheet 2 — Court Record

**Files:** Modify `orkui/template/default/Court_detail.tpl`

**Interfaces:**
- Consumes: `cpSheetChrome()`, `cpScriptActiveAwards()`, and **`window.cpGiverOptions`**, which already exists (`Court_detail.tpl:2191`) with `.default` and `.pills`, populated from `get_court_giver_options` and already consumed by the grant modal at `:3067`/`:3118`. **Do not invent a new global for the giver.**
- Produces: `cpSheetRecord(awards)`. **Task 6's Record Court row is this sheet's column set — they must not drift.**

This is the instrument the recorder writes on. Every column exists because something has to be typed back in afterwards.

- [ ] **Step 1: Build the sheet**

```javascript
    function cpSheetRecord(awards) {
        // Reuse the existing cpGiverOptions payload — do not add a new global.
        var giver = (window.cpGiverOptions && window.cpGiverOptions.default
                     && window.cpGiverOptions.default.persona) || '';
        var rows = awards.map(function (a, i) {
            var skipped = a.Status === 'cancelled';
            var given   = a.Status === 'given' || a.Status === 'staged';
            return '<tr' + (skipped ? ' class="cp-script-skipped"' : '') + '>' +
                '<td class="cp-rec-num">' + (i + 1) + '</td>' +
                '<td class="cp-rec-box">' + (given ? '&#9745;' : '&#9744;') + '</td>' +
                '<td class="cp-rec-box">' + (skipped ? '&#9745;' : '&#9744;') + '</td>' +
                '<td class="cp-rec-recip">' + cpScriptRecipient(a) + '</td>' +
                '<td class="cp-rec-award">' + esc(a.AwardName || '') +
                    (skipped ? ' <span class="cp-script-skipmark">(skipped)</span>' : '') + '</td>' +
                '<td class="cp-rec-rank">' + (a.IsLadder && a.Rank ? a.Rank : '&mdash;') + '</td>' +
                '<td class="cp-rec-giver">' + esc(giver) + '</td>' +
                '<td class="cp-rec-box">' + (a.PassToLocal ? '&#9745;' : '&#9744;') + '</td>' +
                '</tr>';
        }).join('');

        // Blank numbered rows for awards added spontaneously at court. A walk-on has
        // no recommendation and no planned citation, so if it is not written here it
        // gets invented at a keyboard days later — hence the citation rule.
        var walkons = '';
        for (var w = 0; w < 6; w++) {
            var n = awards.length + w + 1;
            walkons +=
                '<tr class="cp-rec-walkon">' +
                '<td class="cp-rec-num">' + n + '</td>' +
                '<td class="cp-rec-box">&#9744;</td>' +
                '<td class="cp-rec-box">&#9744;</td>' +
                '<td class="cp-rec-recip"><span class="cp-rule"></span></td>' +
                '<td class="cp-rec-award"><span class="cp-rule"></span></td>' +
                '<td class="cp-rec-rank"><span class="cp-rule cp-rule-sm"></span></td>' +
                '<td class="cp-rec-giver"><span class="cp-rule"></span></td>' +
                '<td class="cp-rec-box">&#9744;</td>' +
                '</tr>' +
                '<tr class="cp-rec-walkon-cite"><td></td><td colspan="7">' +
                '<span class="cp-rec-citelabel">citation</span> <span class="cp-rule cp-rule-full"></span>' +
                '</td></tr>';
        }

        return '<table class="cp-sheet-record">' +
            '<thead><tr>' +
            '<th class="cp-rec-num">#</th><th class="cp-rec-box">&#10003;</th><th class="cp-rec-box">&#10007;</th>' +
            '<th>Recipient</th><th>Award</th><th class="cp-rec-rank">Rank</th><th>Given by</th><th class="cp-rec-box">PTL</th>' +
            '</tr></thead><tbody>' + rows +
            '<tr class="cp-rec-sep"><td colspan="8">WALK-ONS</td></tr>' + walkons +
            '</tbody></table>';
    }
```

- [ ] **Step 2: Style it as paper to write on**

```css
.cp-sheet-record { width: 100%; border-collapse: collapse; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 12px; font-variant-numeric: tabular-nums; }
.cp-sheet-record th { text-align: left; font-size: 10px; letter-spacing: .06em; text-transform: uppercase; color: #4a5568; border-bottom: 1.5px solid #333; padding: 4px 6px; }
.cp-sheet-record td { padding: 6px; border-bottom: 1px solid #e2e8f0; height: 1.6em; vertical-align: bottom; }
.cp-rec-num { width: 26px; color: #a0aec0; }
.cp-rec-box { width: 22px; text-align: center; font-size: 14px; }
.cp-rec-rank { width: 46px; }
.cp-rec-recip { font-weight: 700; }
/* The giver is PRE-PRINTED FAINTLY so the common case is a tick, not a write-in.
   The faintness IS the design — solid black would read as fixed rather than default. */
.cp-rec-giver { color: rgba(0,0,0,.6); }
.cp-rule { display: inline-block; width: 100%; border-bottom: 1px solid #999; height: 1.1em; }
.cp-rule-sm { width: 40px; }
.cp-rule-full { width: 88%; }
.cp-rec-sep td { font-size: 10px; letter-spacing: .08em; font-weight: 700; color: #4a5568; border-bottom: 1.5px solid #333; padding-top: 14px; }
.cp-rec-citelabel { font-size: 9px; text-transform: uppercase; letter-spacing: .06em; color: #a0aec0; }
.cp-rec-walkon td, .cp-rec-walkon-cite td { border-bottom: none; height: 1.9em; }
html[data-theme="dark"] .cp-sheet-record th { color: #cbd5e0; border-bottom-color: #cbd5e0; }
html[data-theme="dark"] .cp-sheet-record td { border-bottom-color: #2d3748; }
html[data-theme="dark"] .cp-rec-giver { color: rgba(226,232,240,.6); }
html[data-theme="dark"] .cp-rule { border-bottom-color: #4a5568; }
```

Print overrides inside the existing `@media print` block:

```css
    body.cp-script-open .cp-sheet-record { font-size: 10pt; }
    body.cp-script-open .cp-sheet-record td { border-bottom-color: #999; }
    body.cp-script-open .cp-rec-giver { color: #666; }   /* faint, but legible on paper */
    body.cp-script-open .cp-rule { border-bottom-color: #000; }
```

- [ ] **Step 3: Verify against the spec's mock**

Compare the rendered sheet to the ASCII mock in spec §4. Confirm: two separate boxes (✓ and ✕) so skipped and unprocessed differ; a Rank column that can be struck and rewritten; a PTL box; the giver pre-printed and visibly lighter than the recipient name; six walk-on rows each with a citation rule; numbering continuing from the plan; skipped rows struck through. Check print emulation for row height (pen room) and that `thead` repeats on a court long enough to span pages — seed extra awards if needed and remove them afterwards.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court packet: Sheet 2, Court Record (spec 4)"
```

---

## Task 5: Sheet 3 — Prep Sheet

**Files:** Modify `orkui/template/default/Court_detail.tpl`, `system/lib/ork3/class.Court.php`, `orkui/model/model.Court.php`, `tests/Integration/CourtPacketTest.php`

**Interfaces:**
- Consumes: `cpSheetChrome()`, `window.courtAwards`, and `cpTrackLabel`.
- Produces: `cpSheetPrep(awards)`. Grouping is client-side from data already on the page — no new query is needed, and adding one would duplicate `getCourtAwards`.

**`cpTrackLabel` is not reachable here as written.** It is defined at `Court_detail.tpl:2697`, inside the IIFE that closes at `:4438`; these builders live at `:4476+`, outside it. Export it — `window.cpTrackLabel = cpTrackLabel;` — beside the existing `window.esc = esc;` at `:2219`, and call it as `window.cpTrackLabel(...)`. Do **not** duplicate the status-label mapping into this sheet; two copies would drift.

This gives `cpTogglePrintingList` an actual print output. Today it reorders the on-screen list and stops.

- [ ] **Step 1: Build the sheet**

```javascript
    // Grouped BY MAKER, because the scribe who made eight scrolls is one person
    // and wants one list — not eight rows scattered through the order of court.
    function cpSheetPrep(awards) {
        function group(kind) {
            var by = {};
            awards.forEach(function (a) {
                if (a.Status === 'cancelled') return;
                var who = (kind === 'scroll' ? a.ScrollMakerPersona : a.RegaliaMakerPersona) || '(unassigned)';
                (by[who] = by[who] || []).push(a);
            });
            var names = Object.keys(by).sort(function (x, y) {
                if (x === '(unassigned)') return 1;
                if (y === '(unassigned)') return -1;
                return x.localeCompare(y);
            });
            if (!names.length) return '';
            return names.map(function (who) {
                var items = by[who].map(function (a) {
                    var st = kind === 'scroll' ? a.ScrollStatus : a.RegaliaStatus;
                    return '<tr>' +
                        '<td class="cp-rec-box">' + (st === 2 ? '&#9745;' : '&#9744;') + '</td>' +
                        '<td>' + cpScriptRecipient(a) + '</td>' +
                        '<td>' + cpScriptAwardLabel(a) + '</td>' +
                        '<td class="cp-prep-status">' + window.cpTrackLabel(kind, st) + '</td>' +
                        '</tr>';
                }).join('');
                return '<div class="cp-prep-group"><h4>' + esc(who) + ' <span>(' + by[who].length + ')</span></h4>' +
                    '<table class="cp-sheet-record"><tbody>' + items + '</tbody></table></div>';
            }).join('');
        }

        var scroll  = group('scroll');
        var regalia = group('regalia');
        if (!scroll && !regalia) return '<p class="cp-script-empty">No scroll or regalia work tracked for this court.</p>';

        return (scroll  ? '<h3 class="cp-prep-head">Scrolls</h3>' + scroll   : '') +
               (regalia ? '<h3 class="cp-prep-head">Regalia</h3>' + regalia : '');
    }
```

- [ ] **Step 2: Style it**

```css
.cp-prep-head { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: #4a5568; margin: 16px 0 6px; padding-bottom: 4px; border-bottom: 1.5px solid #333; background: none; border-radius: 0; }
.cp-prep-group { margin-bottom: 12px; break-inside: avoid; }
.cp-prep-group h4 { font-size: 13px; margin: 8px 0 2px; background: none; border: none; padding: 0; border-radius: 0; }
.cp-prep-group h4 span { color: #a0aec0; font-weight: 400; }
.cp-prep-status { color: #718096; font-size: 11px; }
html[data-theme="dark"] .cp-prep-head { color: #cbd5e0; border-bottom-color: #cbd5e0; }
html[data-theme="dark"] .cp-prep-group h4 span,
html[data-theme="dark"] .cp-prep-status { color: #97a3b4; }
```

> **Note the `background: none; border: none; padding: 0; border-radius: 0` on the headings.** Global `h1`–`h6` rules in `orkui.css` give headings a grey pill box; every heading in a card, modal or sheet has to reset it or the sheet prints a grey slab.

- [ ] **Step 3: Verify**

On a court with at least two distinct scroll makers and one regalia maker: confirm grouping by person, `(unassigned)` sorted last, counts correct, cancelled awards excluded, status labels matching the on-screen tracker, and `break-inside: avoid` keeping a maker's block together in print emulation. Dark mode walk.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court packet: Sheet 3, Prep Sheet grouped by maker (spec 4)"
```

---

## Task 6: Record Court — route, shell, top strip, hero consolidation

**Files:**
- Create: `orkui/template/default/Court_record.tpl`
- Modify: `orkui/controller/controller.Court.php`, `orkui/template/default/Court_detail.tpl`, `system/lib/ork3/class.Court.php`, `orkui/model/model.Court.php`, `tests/Integration/CourtPacketTest.php`

**Interfaces:**
- Consumes: `get_court_detail` (returns `RecorderMundaneId`, `RecorderPersona`, `LastPrintedAt`), `get_court_awards`, `get_court_giver_options`, `get_upcoming_events`.
- Produces: route `Court/record/{court_id}`; `Court_record.tpl`; JS globals `courtId`, `courtAwards`, `courtMeta` mirroring `Court_detail.tpl`'s so Tasks 7–10 can reuse the same idioms.

**Hero consolidation (spec §5).** The published-plan hero already renders Complete Court, Record All Grants and Return to Planning. Adding a fourth button named *Record Court* would put two near-homophones side by side. So: **Record Court becomes the primary published action and absorbs Record All Grants**, which moves inside the new view as "Mark all remaining Given" (Task 7). The hero loses a button rather than gaining one.

- [ ] **Step 1: Write the failing test**

Append to `CourtPacketTest`:

```php
    public function testRecordViewIsRefusedToNonManagers(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid, 'status' => 'published']);
        $stranger = $this->fixture->createPlayer('stranger', $kid);

        $this->assertFalse(
            $this->court->canManage($stranger['mundane_id'], $kid, 0),
            'A player with no officer role and no edit authority must not manage this court.'
        );
    }
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit --filter CourtPacketTest`
Expected: PASS (it pins the authority the controller will rely on). If it fails, stop — the auth model is not what this task assumes.

- [ ] **Step 3: Add the controller action**

In `controller.Court.php`, mirroring `detail()`'s guards exactly (invalid id → error, not found → error, `can_manage` → error):

```php
    // -----------------------------------------------------------------------
    // Record Court — the catch-up pass (spec §5)
    // Route: ?Route=Court/record/{court_id}
    // -----------------------------------------------------------------------
    public function record($court_id = null)
    {
        $court_id = (int)preg_replace('/[^0-9]/', '', $court_id ?? '');
        $uid      = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        if (!valid_id($court_id)) {
            $this->data['Error'] = 'Invalid court.';
            return;
        }

        $court = $this->Court->get_court_detail($court_id);
        if (!$court) {
            $this->data['Error'] = 'Court not found.';
            return;
        }

        if (!$this->Court->can_manage($uid, $court['KingdomId'], $court['ParkId'])) {
            $this->data['Error'] = 'You do not have permission to record this court.';
            return;
        }

        $this->data['Court']          = $court;
        $this->data['CourtAwards']    = $this->Court->get_court_awards($court_id);
        $this->data['GiverOptions']   = $this->Court->get_court_giver_options($court_id);
        $this->data['UpcomingEvents'] = $this->Court->get_upcoming_events($court['KingdomId']);
        $this->data['Uid']            = $uid;
        $this->template               = 'Court_record.tpl';
    }
```

Keep the court's own linked event selectable even when it has aged out, exactly as `detail()` does — copy that block rather than reinventing it.

- [ ] **Step 4: Build the view shell and top strip**

Create `orkui/template/default/Court_record.tpl`. Reuse `Court_detail.tpl`'s `cp-` visual language so the two surfaces read as one tool. The shell carries:

- a hero line: court name, date, mode badge, and a link back to the planner;
- the **top strip** — court date, event, and default giver — each editable, posting to `CourtAjax/update_court` (date via Flatpickr `altInput`/`altFormat: 'F j, Y'`). This strip edits the court record itself, which is what Thread 0's 0.1 made possible;
- an empty `<div id="cp-rec-rows">` that Task 7 fills;
- the existing staged-count indicator markup, so the running total is visible while marking;
- a **Finalize & Complete** button wired to the existing complete-court modal flow.

Emit the same JS globals `Court_detail.tpl` emits (`courtId`, `courtAwards`, `courtMeta`) so Tasks 7–10 reuse its idioms rather than inventing new ones.

- [ ] **Step 5: Consolidate the hero buttons**

In `Court_detail.tpl`, replace the `published && plan` **Record All Grants** button with a link to the new view, and make it the primary published action:

```php
            <?php if ($courtSt === 'published'): ?>
            <a class="cp-btn-primary" style="margin-top:5px" href="<?= UIR ?>Court/record/<?= (int)$court['CourtId'] ?>">
                <i class="fas fa-clipboard-check"></i> Record Court
            </a>
            <?php endif; ?>
```

`cpBulkRecord` stays in the file for now — Task 7 moves its call site into the new view.

- [ ] **Step 6: Verify**

Visit `Court/record/<id>` on a published court as an authorized officer: shell renders, top strip shows the real date/event/giver, editing the date persists (check the DB) and the flatpickr shows a human-readable format. Visit as a non-manager: refused with the error, not a blank page. Confirm the planner hero now shows **Record Court** and no longer shows Record All Grants. Check 390px and dark mode.

- [ ] **Step 7: Commit**

```bash
git add orkui/controller/controller.Court.php orkui/template/default/Court_record.tpl orkui/template/default/Court_detail.tpl tests/Integration/CourtPacketTest.php
git commit -m "Record Court: route, shell, top strip, hero consolidation (spec 5)"
```

---

## Task 7: Record Court — per-row marks and "Mark all remaining Given"

**Files:** Modify `orkui/template/default/Court_record.tpl`

**Interfaces:**
- Consumes: existing endpoints `CourtAjax/grant_award` (stages), `CourtAjax/skip_award`, `CourtAjax/unstage_award`, `CourtAjax/bulk_record_grants`.
- Produces: the row list and its mark handlers, which Tasks 8–10 extend.

**Every mark is a real server write the moment it is made.** Staging writes nothing permanent, so eager persistence costs no safety, keeps pre-finalize undo free, and — the point — means a recorder who closes the laptop halfway through loses nothing.

**There is no "Stage all marked" button.** `bulkStagePlanned` already writes `staged` server-side, so such a button would have nothing to do for most rows and would mean something different for walk-ons than for planned rows. Rows stage as they are marked; **Finalize & Complete is the single gate.**

- [ ] **Step 1: Render the rows**

One row per award, in `sort_order`, with the **same columns as Sheet 2** so the paper and the screen read alike: `#`, recipient (+park), award, rank, three-state control, given-by, PTL. Give each row `data-caid` and `data-rowversion`.

The three-state control is a segmented button group — **Given · Skipped · —** — with the active state reflecting `Status` (`given`/`staged` → Given, `cancelled` → Skipped, else `—`). Each button ≥44px on touch via padding.

- [ ] **Step 2: Wire the marks**

```javascript
// Given -> stage, Skipped -> skip, — -> unstage. All three already exist and all
// three are pre-finalize, so any of them can be undone by pressing another.
function cpRecMark(caid, state) {
    var row = document.querySelector('.cp-rec-row[data-caid="' + caid + '"]');
    if (!row) return;
    var url = state === 'given'   ? 'CourtAjax/grant_award'
            : state === 'skipped' ? 'CourtAjax/skip_award'
            :                       'CourtAjax/unstage_award';
    var fd = new FormData();
    fd.append('CourtAwardId', caid);
    fd.append('RowVersion', row.getAttribute('data-rowversion') || '');
    if (state === 'given') {
        fd.append('GivenById', cpRecGiverFor(caid));
        fd.append('PublicComment', cpRecCitationFor(caid));
        fd.append('Rank', cpRecRankFor(caid));
    }
    cpRecPost(url, fd, row, state);
}
```

`cpRecPost` must handle `status === 9` by showing the non-destructive "this row changed — reload" notice and leaving the row alone — that is Thread 0's 0.4 doing its job under two recorders. On success, patch the row's state and bump its `data-rowversion`.

- [ ] **Step 3: Add "Mark all remaining Given"**

At the top of the row list, a single primary button posting `CourtAjax/bulk_record_grants` with `CourtId`. This **is** the old Record All Grants, relabelled. It stays live — clicking again after adding walk-ons catches them. Guard it with `cpConfirm` (never `confirm()`), then reload so the row states come back from the server rather than being guessed.

**Rows are never silently defaulted to Given.** The bulk action is explicit and visible; defaulting would trade a click for a chance of recording an award nobody gave.

- [ ] **Step 4: Verify the cost model the spec claims**

On a published court with ~10 planned awards, walk the spec's scenario and **count your interactions**: mark two Skipped, change two givers, add three walk-ons (Task 10 — skip this part for now and note it), click Mark all remaining Given, then Complete. Confirm the marks persisted by reloading mid-way and checking the DB — that is the interruption-survival property. Report your count.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/default/Court_record.tpl
git commit -m "Record Court: eager per-row marks and mark-all-remaining (spec 5)"
```

---

## Task 8: Record Court — giver chip, apply-to-rest, inline rank

**Files:** Modify `orkui/template/default/Court_record.tpl`

**Interfaces:**
- Consumes: `GiverOptions` from the controller; `CourtAjax/grant_award`'s `GivenById`.
- Produces: `cpRecGiverFor(caid)` and `cpRecRankFor(caid)`, both consumed by Task 7's `cpRecMark`.

- [ ] **Step 1: Render the giver cell**

Pre-fill each row's giver with the court default as a **chip**. Clicking it opens the officer quick-picks from `GiverOptions` plus a scoped player search. The search must follow house rules: a custom results dropdown, **never jQuery UI**; `&q=` not `?q=`; and if it opens inside any overlay, position it fixed before showing.

- [ ] **Step 2: Add "apply to the rest below"**

When a row's giver changes, offer *"apply to the rest below"* — the real pattern at court is "the Regent gave the next six." Applying updates the in-page giver for every subsequent row **without** marking them; a row's giver is only written when that row is marked Given.

- [ ] **Step 3: Inline rank pills**

Ladder awards get rank pills in the row (the same control the grant modal and the ad-hoc modal use, showing which ranks are already held). Non-ladder rows show `—`. The plan may say Rank 3 and the Crown may give Rank 2 — the paper has a write-in column for exactly this, and the screen must accept it.

- [ ] **Step 4: Verify**

Change one row's giver, apply to the rest, mark several Given, and confirm via DB that each staged row carries the giver you chose — not the court default. Change a rank and confirm it persists. Confirm the player search returns scoped results and its dropdown is not clipped.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/default/Court_record.tpl
git commit -m "Record Court: giver chip, apply-to-rest, inline rank (spec 5)"
```

---

## Task 9: Record Court — inline citation

**Files:** Modify `orkui/template/default/Court_record.tpl`

**Interfaces:**
- Consumes: `CourtAjax/update_award` — **partial** since Thread 0's 0.6, which is what makes a citation-only save safe. Before that change this same save would have erased internal notes, pass-to-local, and both maker credits.
- Produces: `cpRecCitationFor(caid)`, consumed by Task 7.

This field becomes `ork_awards.note` — **public, permanent, and hand-editable only by an award admin.** It cannot be the one field the catch-up view omits.

- [ ] **Step 1: Render the preview**

One truncated line per row showing the current `PublicComment`. Clicking expands it to a textarea. **Zero clicks in the common case** — it was filled during planning — and one click on the exception.

- [ ] **Step 2: Save it partially**

On blur or explicit save, POST **only** `CourtAwardId`, `PublicComment` and `RowVersion` to `CourtAjax/update_award`. Do not send the other four fields: omitting a key now means "leave alone", and sending a blank would clear a column this view does not show.

- [ ] **Step 3: Make it mandatory-visible on walk-ons**

A walk-on has no recommendation and no planned citation, so its citation exists nowhere. Walk-on rows (Task 10) render the citation field expanded by default rather than as a truncated preview.

- [ ] **Step 4: Verify the non-destructive save**

Seed an award with internal notes, pass-to-local and a scroll maker. Edit only its citation from this view. Assert by SQL that `notes`, `pass_to_local`, `scroll_maker_id` and `regalia_maker_id` are **unchanged** and only `public_comment` moved. This is the regression check for the whole partial-write chain.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/default/Court_record.tpl
git commit -m "Record Court: inline citation with partial save (spec 5)"
```

---

## Task 10: Record Court — inline walk-on row

**Files:** Modify `orkui/template/default/Court_record.tpl`

**Interfaces:**
- Consumes: `CourtAjax/add_award` (already permits adds while published — "QW#6").
- Produces: the permanent blank row at the foot of the list.

This is a **faster path to an existing capability**, not a new one. To avoid a third way to do one thing, the inline row **replaces** the Add Award / Add Title buttons *within this view*; they remain on the planner page.

- [ ] **Step 1: Build the row**

A permanent blank row carrying: recipient search, award search (which already covers titles — do not add a separate title entry point), rank pills, a **Pass-to-Local checkbox** (it matches the PTL column on the paper, and a walk-on the Crown passed to the local park must have somewhere to land), and the expanded citation field from Task 9. Internal Notes are deliberately omitted — they stay editable on the planner page.

- [ ] **Step 2: Commit on Enter**

Enter commits the row via `add_award` and opens a fresh blank row with focus in the recipient search. Walk-ons land at the end because published courts cannot be reordered — which is exactly why the printed sheet numbers them after the plan.

- [ ] **Step 3: Keep the bulk button honest**

After a walk-on is added, "Mark all remaining Given" must still catch it — `bulkStagePlanned` is set-based over `status = 'planned'`, so it does. Verify rather than assume.

- [ ] **Step 4: Verify**

Add three walk-ons in sequence without touching the mouse between them. Confirm each persists with its rank, PTL flag and citation, that numbering continues from the plan, and that the bulk button then stages them.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/default/Court_record.tpl
git commit -m "Record Court: inline walk-on row (spec 5)"
```

---

## Task 11: Drift warning

**Files:** Modify `orkui/template/default/Court_record.tpl`, `system/lib/ork3/class.Court.php`, `tests/Integration/CourtPacketTest.php`

**Interfaces:**
- Consumes: `ork_court.last_printed_at` (Task 1), `ork_court_award.modified`.
- Produces: `Court::courtChangedSincePrint(int $court_id): bool`.

Once a sheet is printed, adding a walk-on renumbers the screen and the paper diverges. The recorder is holding the old numbering.

- [ ] **Step 1: Write the failing test**

```php
    public function testCourtChangedSincePrintDetectsLaterEdits(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('drift', $kid);
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid, 'status' => 'published']);

        $this->assertFalse($this->court->courtChangedSincePrint($courtId), 'Never printed is not drift.');

        $this->court->markCourtPrinted($courtId);
        $this->assertFalse($this->court->courtChangedSincePrint($courtId), 'Nothing changed since the print.');

        sleep(1);
        $this->fixture->createAward($courtId, $player['mundane_id']);
        $this->assertTrue($this->court->courtChangedSincePrint($courtId), 'A walk-on added after printing is drift.');
    }
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit --filter CourtPacketTest`
Expected: FAIL, `Call to undefined method Court::courtChangedSincePrint()`.

- [ ] **Step 3: Implement**

```php
    /**
     * True when a court award was added or edited after the packet was last
     * printed (spec §5). A walk-on added post-print renumbers the screen, so the
     * paper in the recorder's hand no longer matches.
     *
     * Never printed => false: there is no paper to diverge from.
     */
    public function courtChangedSincePrint($court_id)
    {
        $court_id = (int)$court_id;
        if (!valid_id($court_id)) {
            return false;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.last_printed_at,
                    (SELECT MAX(ca.modified) FROM ' . DB_PREFIX . 'court_award ca
                      WHERE ca.court_id = c.court_id) AS last_change
               FROM ' . DB_PREFIX . 'court c
              WHERE c.court_id = ' . $court_id
        );

        if (!$rs || !$rs->Next() || empty($rs->last_printed_at) || empty($rs->last_change)) {
            return false;
        }

        return strtotime((string)$rs->last_change) > strtotime((string)$rs->last_printed_at);
    }
```

Add the model pass-through and surface it in `record()`.

- [ ] **Step 4: Show it**

A dismissible banner at the top of the Record Court view: **"The plan changed since this was printed on &lt;date&gt; — the paper you are holding may not match the numbering below."** Reuse the existing `cp-prev-banner` styling rather than inventing a fourth banner treatment. Dark mode and 390px.

- [ ] **Step 5: Run the test and verify in the browser**

Run: `vendor/bin/phpunit --filter CourtPacketTest` — expected PASS.
Then: print a court packet, confirm no banner; add a walk-on; reload Record Court; confirm the banner appears with the right date.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Court.php orkui/model/model.Court.php orkui/controller/controller.Court.php orkui/template/default/Court_record.tpl tests/Integration/CourtPacketTest.php
git commit -m "Record Court: warn when the plan changed since the last print (spec 5)"
```

---

## Task 12: Verification sweep

**Files:** none created; this task gates the threads.

- [ ] **Step 1: Full suites**

```bash
vendor/bin/phpunit --testsuite integration 2>&1 | tail -20
vendor/bin/phpunit --testsuite unit 2>&1 | tail -20
```
Expected: no new failures. Known unrelated skips: `AuthorizationAddTest`, `VotingRulesTest`.

- [ ] **Step 2: Lint**

```bash
for f in system/lib/ork3/class.Court.php orkui/model/model.Court.php orkui/controller/controller.Court.php orkui/controller/controller.CourtAjax.php tests/Integration/CourtPacketTest.php; do php -l "$f"; done
```

- [ ] **Step 3: Forbidden files**

```bash
git log <thread-base>..HEAD --name-only | grep -c "class.Authorization.php\|CLAUDE.md"
git diff --cached --name-only | grep -c "class.Authorization.php"
```
Expected: `0` for both.

- [ ] **Step 4: The three sheets on paper**

Print-emulate each sheet at real page size on a court long enough to span pages. Confirm: `thead` repeats on Sheet 2; a maker's block never splits on Sheet 3; skipped rows are struck through and legible; the giver is faint but readable; the printed stamp and court URL appear on all three; no sheet relies on colour to carry meaning.

- [ ] **Step 5: Mobile and dark mode**

390px and 768px, light and dark, of the Record Court view: rows, three-state control, giver chip, rank pills, citation expand, walk-on row, top strip, drift banner. No horizontal page scroll; no interactive target under 44px; autocomplete dropdowns not clipped. Measure in an iframe, do not trust `resize_window`.

- [ ] **Step 6: The interruption property**

Mark half a court, hard-reload mid-pass, and confirm every mark survived and the staged count matches. This is the property eager writes exist to deliver.

- [ ] **Step 7: Spec §15 walk**

Confirm each Thread 1 and Thread 2 acceptance criterion in the spec has a passing check, including the citation round-trip (a walk-on citation entered here lands in `ork_awards.note` after finalize) and the two-recorder conflict path.

---

## Self-Review

**Spec coverage.** Thread 1: three sheets (Tasks 3, 4, 5), one page geometry + shared chrome + printed stamp + URL footer (Task 2), skipped rows struck through (inherited from Thread 0's 0.3, exercised in Tasks 3–4). Thread 2: eager marks and mark-all (Task 7), giver chip and apply-to-rest and inline rank (Task 8), citation (Task 9), walk-on row (Task 10), top strip and hero consolidation (Task 6), drift warning (Task 11), concurrency via Thread 0's `RowVersion` (exercised in Task 7 and swept in Task 12). The spec's "no Stage all marked" and "never default rows to Given" decisions are carried explicitly in Task 7.

**Placeholders.** Tasks 6–10 describe markup structurally rather than shipping full template source, because `Court_record.tpl` is a new 600+ line file and pasting it whole would be less reliable than naming its columns, its endpoints, its data attributes and its house-rule constraints. Every endpoint, payload key, CSS class family and behavioural rule is named exactly. No step says "handle errors appropriately" or defers a decision.

**Type consistency.** `markCourtPrinted(int): bool` and `courtChangedSincePrint(int): bool` are defined in Tasks 2 and 11 and used only there. `cpSheetOrder`/`cpSheetRecord`/`cpSheetPrep` are stubbed in Task 2 and filled in Tasks 3–5 with the same signature `(awards)`. `cpRecGiverFor`/`cpRecRankFor`/`cpRecCitationFor` are consumed in Task 7 and defined in Tasks 8–9 — Task 7 must therefore stub them returning the court default, the row's planned rank, and the row's existing `PublicComment` respectively, which its Step 2 code assumes; that stubbing is called out here so the ordering is not a surprise.

**Ordering.** Task 1 precedes Tasks 2 and 11 (column). Task 2 precedes Tasks 3–5 (dispatcher and chrome). Task 4 precedes Task 6 in spirit — Sheet 2's columns are the Record Court row's specification — so Task 6 must not invent a different column set. Tasks 7–10 are strictly sequential on the same new file. Task 11 depends on Tasks 1 and 6.
