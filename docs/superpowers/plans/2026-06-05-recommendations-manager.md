# Recommendations Manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone, full-width, spreadsheet-style Recommendations Manager (routes `Recommendations/manage/kingdom/{id}` and `.../park/{id}`) that consolidates all administrative recommendation actions (Grant Now, Dismiss, Snooze, Add to Court) and adds court-membership filtering and +1/notes visualization.

**Architecture:** New convention-routed controller `Controller_Recommendations::manage()` renders `Recommendations_manage.tpl`. Data comes from the existing `Report->PlayerAwardRecommendations` plus one new `class.Court::getRecommendationCourtMap()` query. All mutations reuse existing, tested AJAX endpoints (`KingdomAjax`/`ParkAjax` snooze/dismiss, `Admin/player/{id}/addaward` for instant grant, `CourtAjax/create_court` + `add_award` for court placement). The inline profile Recommendations tab keeps only community +1/second; its admin controls are removed and replaced by a "Manage Recommendations" button.

**Tech Stack:** PHP 8 (ORK3 MVC), plain-PHP `.tpl` templates (NOT Smarty), vanilla JS, MariaDB. Verification via curl-auth session + DB read-back + Claude-in-Chrome (no unit-test framework for this layer).

---

## Conventions (read before starting)

- **`.tpl` files are plain PHP**: use `<?php ?>` / `<?= ?>`, never `{$var}` / `{if}` / `{foreach}`.
- **Editing PHP/tpl — normalize-first**: before a multi-line Edit, run `awk '/^\t/{c++} END{print c+0}' <file>` (0 = clean → use Edit; nonzero → run `php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php <file>` first, then Edit).
- **Never `git add -A`/`git add .`**; stage files explicitly. Never stage `system/lib/ork3/class.Authorization.php` (login bypass hack). Run `git diff --cached` before each commit.
- **Dark mode**: every surface must be dark-mode compatible proactively (grid, header dropdowns, chips, modals, toasts, expand rows). Reset global `h1–h6` box styling (`background:transparent;border:none;padding:0;border-radius:0`) on any heading in a custom hero/card/modal.
- **No native `confirm()`/`alert()`** — use `tnConfirm({title,body,confirmLabel,danger,onConfirm})`. **No native `title=` tooltips** — use the `data-tip` CSS pattern.
- **Autocomplete in modals** uses `position:fixed` via `tnFixedAcPosition(input,dropdown)` (only relevant if a player/court search is added; the Add-to-Court court picker is a `<select>`, no autocomplete needed).
- **CSS prefix `rm-`**; all CSS + JS inlined in `Recommendations_manage.tpl`.

## Curl-auth verification recipe (used by several tasks)

All test calls must share ONE cookie jar in ONE shell block (app enforces single-device sessions). App container is `ork3-php8-app`; 500s show in `docker logs ork3-php8-app`.

```bash
cd /Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias
J=/tmp/rm_cookies.txt; rm -f "$J"
BASE='http://localhost:19080/orkui/index.php?Route='
# Log in (bypass accepts any password). Replace USERNAME with a known kingdom/park officer.
curl -s -c "$J" -b "$J" "${BASE}Login/login" \
  --data-urlencode 'username=USERNAME' --data-urlencode 'password=x' -o /dev/null
# ...then issue authenticated GET/POSTs reusing -c "$J" -b "$J" in the SAME block.
```

To find a valid officer + scope ids for testing:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT o.mundane_id, m.username, o.kingdom_id, o.park_id, o.role
 FROM ork_officer o JOIN ork_mundane m ON m.mundane_id=o.mundane_id
 WHERE o.role IN ('Monarch','Regent','Prime Minister') AND o.park_id=0 LIMIT 5;"
```

---

## File Structure

- **Create** `orkui/controller/controller.Recommendations.php` — `Controller_Recommendations::manage($context, $id)`: resolve scope, authority gate, load rows + court map + courts + parks, set `$this->data[...]`.
- **Create** `orkui/template/revised-frontend/Recommendations_manage.tpl` — full page: hero/header, filter bar, spreadsheet grid, expand rows, selection, bulk bar, Add-to-Court modal, toast; all `rm-` CSS + JS inlined.
- **Modify** `system/lib/ork3/class.Court.php` — add `getRecommendationCourtMap($kingdom_id, $park_id = 0)`.
- **Modify** `orkui/template/revised-frontend/Kingdomnew_index.tpl` — add Manage button; remove inline admin rec controls + now-orphan grant/add-court modals.
- **Modify** `orkui/template/revised-frontend/Parknew_index.tpl` — same as Kingdomnew.
- **Modify** `orkui/template/revised-frontend/script/revised.js` — remove orphaned inline admin-rec handlers for Kingdom/Park (snooze/dismiss/grant-from-rec/add-court) only if they are no longer referenced; leave +1/second handlers intact.

---

## Task 1: Controller scaffold + authority gate (smoke test the route)

**Files:**
- Create: `orkui/controller/controller.Recommendations.php`
- Create: `orkui/template/revised-frontend/Recommendations_manage.tpl` (temporary minimal body)

- [ ] **Step 1: Create the controller with scope resolution + authority gate**

Mirror `Controller_Court::list()` (orkui/controller/controller.Court.php:14-71). Create `orkui/controller/controller.Recommendations.php`:

```php
<?php

class Controller_Recommendations extends Controller {

    public function __construct($call = null, $id = null) {
        parent::__construct($call, $id);
    }

    // Route: ?Route=Recommendations/manage/kingdom/{kingdom_id}
    //        ?Route=Recommendations/manage/park/{park_id}
    public function manage($context = null, $id = null) {
        $id      = (int)preg_replace('/[^0-9]/', '', $id ?? '');
        $context = ($context === 'park') ? 'park' : 'kingdom';
        $uid     = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        $kingdom_id = 0;
        $park_id    = 0;
        if ($context === 'park') {
            $park_id = $id;
            global $DB;
            $DB->Clear();
            $pr = $DB->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $park_id . ' LIMIT 1');
            if ($pr && $pr->Next()) $kingdom_id = (int)$pr->kingdom_id;
        } else {
            $kingdom_id = $id;
        }

        if (!valid_id($kingdom_id)) { $this->data['Error'] = 'Invalid location.'; return; }

        if (!Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id)) {
            $this->data['Error'] = 'You do not have permission to manage recommendations.';
            return;
        }

        // Location name
        $locationName = '';
        global $DB;
        if ($park_id > 0) {
            $DB->Clear();
            $lr = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $park_id . ' LIMIT 1');
            if ($lr && $lr->Next()) $locationName = $lr->name;
        } else {
            $DB->Clear();
            $lr = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdom_id . ' LIMIT 1');
            if ($lr && $lr->Next()) $locationName = $lr->name;
        }

        // Data wired in Task 3 (placeholder empties keep the page renderable now).
        $this->data['Recommendations'] = [];
        $this->data['CourtMap']        = [];
        $this->data['Courts']          = [];
        $this->data['Parks']           = [];

        $this->data['KingdomId']    = $kingdom_id;
        $this->data['ParkId']       = $park_id;
        $this->data['Context']      = $context;
        $this->data['LocationName'] = $locationName;
        $this->data['Uid']          = $uid;
    }
}
```

- [ ] **Step 2: Create a temporary minimal template**

Create `orkui/template/revised-frontend/Recommendations_manage.tpl`:

```php
<?php if (!empty($Error)) { ?>
  <div class="rm-error"><?= htmlspecialchars($Error) ?></div>
<?php return; } ?>
<h1>Recommendations Manager — <?= htmlspecialchars($LocationName) ?></h1>
<p>Scope: <?= htmlspecialchars($Context) ?> (kingdom <?= (int)$KingdomId ?>, park <?= (int)$ParkId ?>)</p>
```

- [ ] **Step 3: Verify the route renders for an authorized user and blocks others**

Use the curl-auth recipe. With an authorized officer's kingdom id:
```bash
curl -s -c "$J" -b "$J" "${BASE}Recommendations/manage/kingdom/KINGDOM_ID" | grep -o 'Recommendations Manager — [^<]*'
```
Expected: prints `Recommendations Manager — <KingdomName>`.

Then hit a kingdom the user does NOT manage:
```bash
curl -s -c "$J" -b "$J" "${BASE}Recommendations/manage/kingdom/OTHER_KINGDOM_ID" | grep -o 'do not have permission'
```
Expected: prints `do not have permission`. Also confirm no 500: `docker logs --tail=20 ork3-php8-app`.

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.Recommendations.php orkui/template/revised-frontend/Recommendations_manage.tpl
git diff --cached
git commit -m "Recommendations Manager: route scaffold + authority gate"
```

---

## Task 2: `getRecommendationCourtMap()` on class.Court

**Files:**
- Modify: `system/lib/ork3/class.Court.php` (add one public method near `getPendingRecommendations`, ~line 318)

- [ ] **Step 1: Normalize-check the file**

Run: `awk '/^\t/{c++} END{print c+0}' system/lib/ork3/class.Court.php`
If nonzero, run `php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php system/lib/ork3/class.Court.php` before editing.

- [ ] **Step 2: Add the method**

Returns `recommendations_id => [ {CourtId, Name, CourtDate, Status}, ... ]` for active (non-cancelled) court awards whose court is in scope. Kingdom scope includes all courts in the kingdom (park_id any); park scope restricts to that park's courts.

```php
/**
 * Map of recommendation_id => list of courts it currently sits on, scoped.
 * Used by the Recommendations Manager to show court badges and the court filter.
 */
public function getRecommendationCourtMap($kingdom_id, $park_id = 0) {
    if (!valid_id($kingdom_id)) return [];
    $scope = 'c.kingdom_id = ' . (int)$kingdom_id;
    if ($park_id > 0) $scope .= ' AND c.park_id = ' . (int)$park_id;

    $this->db->Clear();
    $rs = $this->db->DataSet(
        'SELECT ca.recommendations_id AS rid, c.court_id, c.name, c.court_date, c.status
           FROM ' . DB_PREFIX . 'court_award ca
           JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
          WHERE ca.recommendations_id > 0
            AND ca.status <> \'cancelled\'
            AND ' . $scope . '
          ORDER BY c.court_date IS NULL, c.court_date ASC, c.court_id ASC'
    );

    $map = [];
    if ($rs) {
        while ($rs->Next()) {
            $rid = (int)$rs->recommendations_id ?: (int)$rs->rid;
            $rid = (int)$rs->rid;
            $map[$rid][] = [
                'CourtId'   => (int)$rs->court_id,
                'Name'      => $rs->name,
                'CourtDate' => $rs->court_date,
                'Status'    => $rs->status,
            ];
        }
    }
    return $map;
}
```

(Note: the duplicate `$rid` lines above — keep only `$rid = (int)$rs->rid;`. Remove the first assignment when typing.)

- [ ] **Step 3: Verify against the DB directly**

Pick a rec that is on a court:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT ca.recommendations_id, c.court_id, c.name, c.status, c.kingdom_id, c.park_id
 FROM ork_court_award ca JOIN ork_court c ON c.court_id=ca.court_id
 WHERE ca.recommendations_id>0 AND ca.status<>'cancelled' LIMIT 5;"
```
Confirm those `recommendations_id`/`court_id` pairs are the ones the method would return for that `kingdom_id`. (Exercised end-to-end in Task 3.)

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Court.php
git diff --cached
git commit -m "Court: add getRecommendationCourtMap for Recommendations Manager"
```

---

## Task 3: Controller data loading (recs, court map, courts, parks)

**Files:**
- Modify: `orkui/controller/controller.Recommendations.php` (replace the placeholder data block from Task 1)

- [ ] **Step 1: Replace the placeholder data block**

Swap the four placeholder assignments in `manage()` for real loads. Insert before the `$this->data['KingdomId']` block:

```php
        // Recommendation rows (full pending set for the scope, with seconds + notes).
        $this->load_model('Reports');
        $req = ['RequestedBy' => $uid];
        if ($park_id > 0) { $req['ParkId'] = $park_id; } else { $req['KingdomId'] = $kingdom_id; }
        $recReport = $this->Reports->PlayerAwardRecommendations($req);
        $recs = $recReport['AwardRecommendations'] ?? [];

        // Court membership per rec (badges + court filter).
        $courtMap = Ork3::$Lib->court->getRecommendationCourtMap($kingdom_id, $park_id);

        // Courts in scope (Add-to-Court existing-court picker + specific-court filter).
        $courts = Ork3::$Lib->court->getCourtList($kingdom_id, $park_id);

        // Parks in the kingdom (kingdom-scope park filter + abbrev lookup).
        $parks = [];
        global $DB;
        $DB->Clear();
        $prs = $DB->DataSet('SELECT park_id, name, abbreviation FROM ' . DB_PREFIX . 'park WHERE kingdom_id = ' . (int)$kingdom_id . ' ORDER BY name ASC');
        if ($prs) { while ($prs->Next()) { $parks[(int)$prs->park_id] = ['Name' => $prs->name, 'Abbrev' => $prs->abbreviation]; } }

        $this->data['Recommendations'] = $recs;
        $this->data['CourtMap']        = $courtMap;
        $this->data['Courts']          = $courts;
        $this->data['Parks']           = $parks;
```

Delete the four `$this->data[...] = [];` placeholder lines.

- [ ] **Step 2: Verify the `park` column name**

The `ork_park` abbreviation column may be `abbreviation` or `abbrev`. Confirm:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_park LIKE 'abbrev%';"
```
If the column is `abbrev`, change `abbreviation` to `abbrev` in the query above (keep the array key `Abbrev`).

- [ ] **Step 3: Verify the controller loads data without error**

```bash
curl -s -c "$J" -b "$J" "${BASE}Recommendations/manage/kingdom/KINGDOM_ID" -o /dev/null -w '%{http_code}\n'
docker logs --tail=20 ork3-php8-app   # expect no new PHP errors
```
Expected: `200`, no errors. (Visual data appears once Task 4 renders the grid.)

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.Recommendations.php
git diff --cached
git commit -m "Recommendations Manager: load recs, court map, courts, parks"
```

---

## Task 4: Spreadsheet grid markup + base styles (render rows)

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (replace temporary body)

This task renders the dense grid with all rows; interactivity (sort/filter/select/expand/actions) lands in Tasks 5–9. Reuse the existing recommendation row data attributes pattern from `Kingdomnew_index.tpl` (rows carry `data-rec-id`, `data-snoozed`, eligibility `data-filter`).

- [ ] **Step 1: Replace the template body with the page shell + grid**

Build the file in this order: PHP header (compute per-row derived values), `<style>` (rm- CSS), HTML (hero + filter bar + grid + bulk bar + modal + toast placeholders), `<script>` (config + render helpers). For this task include only the static grid + styles; leave clearly-marked `<!-- Task N -->` anchors for later interactivity.

Page-level config + back link (hero heading resets the global h1 box):

```php
<?php if (!empty($Error)) { ?>
  <div class="rm-wrap"><div class="rm-error"><?= htmlspecialchars($Error) ?></div></div>
<?php return; }
  $backUrl = $ParkId > 0
    ? UIR . 'Park/index/' . (int)$ParkId
    : UIR . 'Kingdom/index/' . (int)$KingdomId;
?>
<div class="rm-wrap">
  <div class="rm-hero">
    <a class="rm-back" href="<?= htmlspecialchars($backUrl) ?>">&larr; Back to <?= htmlspecialchars($LocationName) ?></a>
    <h1 class="rm-title">Recommendations Manager</h1>
    <div class="rm-sub"><?= htmlspecialchars($LocationName) ?> &middot; <?= count($Recommendations) ?> pending</div>
  </div>
```

- [ ] **Step 2: Add the filter bar markup (controls wired in Task 7)**

```php
  <div class="rm-filterbar" id="rm-filterbar">
    <input type="search" id="rm-search" class="rm-search" placeholder="Search recipient…" autocomplete="off">
    <select id="rm-filter-elig" class="rm-fsel">
      <option value="all">All eligibility</option>
      <option value="below">Below recommended</option>
      <option value="ator">At/above recommended</option>
      <option value="nonladder">Non-ladder</option>
      <option value="snoozed">Snoozed</option>
    </select>
    <select id="rm-filter-court" class="rm-fsel">
      <option value="all">Any court status</option>
      <option value="none">Not on a court</option>
      <option value="any">On any court</option>
      <?php foreach ($Courts as $c) { ?>
        <option value="court:<?= (int)$c['CourtId'] ?>">On: <?= htmlspecialchars($c['Name']) ?></option>
      <?php } ?>
    </select>
    <?php if ($ParkId === 0 && count($Parks)) { ?>
    <select id="rm-filter-park" class="rm-fsel">
      <option value="all">All parks</option>
      <?php foreach ($Parks as $pid => $p) { ?>
        <option value="<?= (int)$pid ?>"><?= htmlspecialchars($p['Name']) ?></option>
      <?php } ?>
    </select>
    <?php } ?>
    <div id="rm-chips" class="rm-chips"></div>
  </div>
```

- [ ] **Step 3: Render the grid + rows**

For each rec compute: ladder vs non-ladder, eligibility class (`below`/`ator`/`nonladder`), court list from `$CourtMap`, seconds array. Use `tabular-nums` cells for date/age/rank/count. Each `<tr.rm-row>` carries data attributes for client filtering/sorting.

```php
  <div class="rm-gridwrap">
  <table class="rm-grid" id="rm-grid">
    <thead>
      <tr>
        <th class="rm-col-sel"><input type="checkbox" id="rm-selall" title=""></th>
        <th class="rm-col-recip rm-sortable" data-sort="recip">Recipient</th>
        <th class="rm-col-award rm-sortable" data-sort="award">Award</th>
        <th class="rm-col-rec rm-sortable" data-sort="date">Recommended</th>
        <th class="rm-col-reason">Reason</th>
        <th class="rm-col-supp rm-sortable" data-sort="supp">Support</th>
        <th class="rm-col-court">Court</th>
        <th class="rm-col-act">Actions</th>
      </tr>
    </thead>
    <tbody id="rm-tbody">
    <?php foreach ($Recommendations as $rec) {
        $rid    = (int)$rec['RecommendationsId'];
        $isLad  = ((int)($rec['Rank'] ?? 0)) > 0;
        $cur    = isset($rec['CurrentRank']) ? (int)$rec['CurrentRank'] : null;
        $elig   = !$isLad ? 'nonladder' : (($cur !== null && $cur < (int)$rec['Rank']) ? 'below' : 'ator');
        $snoozed = !empty($rec['IsSnoozed']) ? 1 : 0;
        $courts = $CourtMap[$rid] ?? [];
        $seconds = $rec['Seconds'] ?? [];
        $pid     = (int)($rec['ParkId'] ?? 0);
        $abbrev  = $Parks[$pid]['Abbrev'] ?? '';
        $courtJson = htmlspecialchars(json_encode($courts), ENT_QUOTES);
        // Payload for Grant Now / Add to Court (Tasks 8–9)
        $recPayload = htmlspecialchars(json_encode([
            'RecommendationsId' => $rid,
            'MundaneId'         => (int)$rec['MundaneId'],
            'Persona'           => $rec['Persona'] ?? '',
            'KingdomAwardId'    => (int)$rec['KingdomAwardId'],
            'Rank'              => (int)($rec['Rank'] ?? 0),
            'Reason'            => $rec['Reason'] ?? '',
        ]), ENT_QUOTES);
    ?>
      <tr class="rm-row" data-rec-id="<?= $rid ?>" data-elig="<?= $elig ?>" data-snoozed="<?= $snoozed ?>"
          data-park="<?= $pid ?>" data-courts='<?= $courtJson ?>'
          data-recip="<?= htmlspecialchars(strtolower($rec['Persona'] ?? ''), ENT_QUOTES) ?>"
          data-award="<?= htmlspecialchars(strtolower($rec['AwardName'] ?? ''), ENT_QUOTES) ?>"
          data-date="<?= htmlspecialchars($rec['DateRecommended'] ?? '', ENT_QUOTES) ?>"
          data-supp="<?= (int)($rec['SecondsCount'] ?? count($seconds)) ?>"
          data-rec='<?= $recPayload ?>'>
        <td class="rm-col-sel"><input type="checkbox" class="rm-rowsel"></td>
        <td class="rm-col-recip">
          <a href="<?= UIR ?>Playernew/index/<?= (int)$rec['MundaneId'] ?>"><?= htmlspecialchars($rec['Persona'] ?? '') ?></a>
          <?php if ($abbrev) { ?><span class="rm-park"><?= htmlspecialchars($abbrev) ?></span><?php } ?>
        </td>
        <td class="rm-col-award">
          <?= htmlspecialchars($rec['AwardName'] ?? '') ?>
          <?php if ($isLad) { ?><span class="rm-rank">Rank <?= (int)$rec['Rank'] ?></span><?php } else { ?><span class="rm-rank rm-nonladder">non-ladder</span><?php } ?>
          <?php if (!empty($rec['AlreadyHas'])) { ?><span class="rm-badge rm-badge-has">already has</span><?php } ?>
          <?php if ($elig === 'below') { ?><span class="rm-badge rm-badge-below">below rec.</span><?php } ?>
        </td>
        <td class="rm-col-rec">
          <span class="rm-by"><?= htmlspecialchars($rec['RecommendedByName'] ?? ($rec['IsAnonymous'] ? 'Anonymous' : '')) ?></span>
          <span class="rm-date"><?= htmlspecialchars($rec['DateRecommended'] ?? '') ?></span>
          <span class="rm-age"><?= (int)($rec['AgeDays'] ?? 0) ?>d</span>
        </td>
        <td class="rm-col-reason">
          <?php $reason = trim($rec['Reason'] ?? ''); if ($reason === '') { ?>
            <span class="rm-empty">—</span>
          <?php } else { ?>
            <span class="rm-reason-trunc"><?= htmlspecialchars($reason) ?></span>
            <button type="button" class="rm-expand-reason" data-tip="Show full reason">▸</button>
          <?php } ?>
        </td>
        <td class="rm-col-supp">
          <?php $sc = (int)($rec['SecondsCount'] ?? count($seconds)); if ($sc > 0) { ?>
            <button type="button" class="rm-supp-chip" data-tip="Show seconds">+<?= $sc ?> ▸</button>
          <?php } else { ?><span class="rm-empty">0</span><?php } ?>
        </td>
        <td class="rm-col-court">
          <?php if (count($courts)) { $c0 = $courts[0]; ?>
            <a class="rm-courtbadge" href="<?= UIR ?>Court/detail/<?= (int)$c0['CourtId'] ?>"><?= htmlspecialchars($c0['Name']) ?><?php if (count($courts) > 1) { ?> <span class="rm-courtmore">+<?= count($courts) - 1 ?></span><?php } ?></a>
          <?php } else { ?><span class="rm-empty">—</span><?php } ?>
        </td>
        <td class="rm-col-act">
          <button type="button" class="rm-act rm-act-grant"  data-tip="Grant now">⚡</button>
          <button type="button" class="rm-act rm-act-court"  data-tip="Add to court">＋</button>
          <button type="button" class="rm-act rm-act-snooze" data-tip="<?= $snoozed ? 'Unsnooze' : 'Snooze' ?>"><?= $snoozed ? '🔔' : '💤' ?></button>
          <button type="button" class="rm-act rm-act-dismiss" data-tip="Dismiss">✕</button>
        </td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
  </div>
  <div class="rm-foot"><span id="rm-count"><?= count($Recommendations) ?></span> shown · <span id="rm-selcount">0</span> selected</div>
</div>
```

- [ ] **Step 4: Add `rm-` CSS (dark-mode aware) in a `<style>` block above the markup**

Include: `.rm-wrap` full-width container; `.rm-grid` dense table (`border-collapse:collapse; width:100%; font-size:13px`), `td/th{border:1px solid var(--rm-line); padding:4px 8px}`, zebra `.rm-row:nth-child(even)`, `tabular-nums` on `.rm-date,.rm-age,.rm-rank,.rm-supp-chip`; sticky header `thead th{position:sticky; top:0; z-index:2}`; sticky filter bar `.rm-filterbar{position:sticky; top:0}`; sticky recipient column `.rm-col-recip{position:sticky; left:0}`; badge/chip/courtbadge styles; `.rm-act` icon buttons; `data-tip` tooltip pattern. Define CSS variables for both themes:

```css
.rm-grid { --rm-line:#d8d8d8; --rm-bg:#fff; --rm-bg2:#f6f6f6; --rm-fg:#222; }
@media (prefers-color-scheme: dark) {
  .rm-grid { --rm-line:#3a3f47; --rm-bg:#1e2127; --rm-bg2:#23262d; --rm-fg:#e6e6e6; }
}
body.dark-mode .rm-grid, html.dark .rm-grid { --rm-line:#3a3f47; --rm-bg:#1e2127; --rm-bg2:#23262d; --rm-fg:#e6e6e6; }
```
(Match whatever dark-mode selector the app already uses — confirm by grepping `dark` in `revised.css`; reuse that exact selector.) Reset the hero heading box: `.rm-title{background:transparent;border:none;padding:0;border-radius:0;text-shadow:none;}`.

- [ ] **Step 5: Verify the grid renders with real rows**

```bash
curl -s -c "$J" -b "$J" "${BASE}Recommendations/manage/kingdom/KINGDOM_ID" | grep -c 'class="rm-row"'
```
Expected: count > 0 and equal to the kingdom's pending-rec count. No errors in `docker logs --tail=20 ork3-php8-app`.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git diff --cached
git commit -m "Recommendations Manager: spreadsheet grid markup + base styles"
```

---

## Task 5: Expand-in-place (Support seconds + Reason)

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (inline `<script>` + the seconds data)

The grid currently renders the `+N ▸` chip and reason `▸` but no detail. Render seconds JSON per row so JS can build the detail row on demand (avoids inflating initial HTML for collapsed rows).

- [ ] **Step 1: Emit seconds JSON per row**

In the row loop (Task 4 markup), add a hidden data attribute on the `<tr>`:
```php
data-seconds='<?= htmlspecialchars(json_encode(array_map(function($s){ return ['Name'=>$s['SupporterName'] ?? '', 'Notes'=>$s['Notes'] ?? '']; }, $seconds)), ENT_QUOTES) ?>'
```

- [ ] **Step 2: Add expand JS**

Append to the inline `<script>`:
```js
function rmInsertDetail(tr, html, cls) {
  var next = tr.nextElementSibling;
  if (next && next.classList.contains(cls)) { next.remove(); return; } // toggle off
  var dr = document.createElement('tr');
  dr.className = 'rm-detailrow ' + cls;
  dr.innerHTML = '<td></td><td colspan="7">' + html + '</td>';
  tr.parentNode.insertBefore(dr, tr.nextSibling);
}
document.getElementById('rm-tbody').addEventListener('click', function(e){
  var chip = e.target.closest('.rm-supp-chip');
  if (chip) {
    var tr = chip.closest('tr');
    var secs = [];
    try { secs = JSON.parse(tr.getAttribute('data-seconds') || '[]'); } catch(x){}
    var html = '<ul class="rm-seclist">' + secs.map(function(s){
      var note = s.Notes ? rmEsc(s.Notes) : '<em class="rm-empty">(no note)</em>';
      return '<li>↳ ' + rmEsc(s.Name) + ' — ' + note + '</li>';
    }).join('') + '</ul>';
    rmInsertDetail(tr, html, 'rm-detail-supp');
    return;
  }
  var rex = e.target.closest('.rm-expand-reason');
  if (rex) {
    var tr2 = rex.closest('tr');
    var full = tr2.querySelector('.rm-reason-trunc');
    rmInsertDetail(tr2, '<div class="rm-reason-full">' + rmEsc(full ? full.textContent : '') + '</div>', 'rm-detail-reason');
  }
});
function rmEsc(s){ var d=document.createElement('div'); d.textContent = s==null?'':String(s); return d.innerHTML; }
```

- [ ] **Step 3: Style detail rows (dark-mode aware)**: `.rm-detailrow td{background:var(--rm-bg2)}`, `.rm-seclist{margin:0;padding:4px 0 4px 8px;list-style:none}`, `.rm-reason-full{white-space:pre-wrap}`.

- [ ] **Step 4: Verify in Chrome**

Use Claude-in-Chrome (post-implementation verification per project rules): open `Recommendations/manage/kingdom/KINGDOM_ID`, click a `+N` chip → a detail row with supporters + notes appears; click again → collapses. Click a reason `▸` → full reason expands. Confirm dark mode renders legibly.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git diff --cached
git commit -m "Recommendations Manager: expandable seconds + reason detail rows"
```

---

## Task 6: Sorting, filtering, chips, footer counts

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (inline `<script>`)

- [ ] **Step 1: Add filter + sort JS**

```js
var RM = { rows: function(){ return Array.from(document.querySelectorAll('#rm-tbody .rm-row')); } };

function rmApplyFilters() {
  var q     = (document.getElementById('rm-search').value || '').trim().toLowerCase();
  var elig  = document.getElementById('rm-filter-elig').value;
  var court = document.getElementById('rm-filter-court').value;
  var parkSel = document.getElementById('rm-filter-park');
  var park  = parkSel ? parkSel.value : 'all';
  var shown = 0;
  RM.rows().forEach(function(tr){
    var ok = true;
    if (q && tr.getAttribute('data-recip').indexOf(q) === -1) ok = false;
    if (ok && elig !== 'all') {
      if (elig === 'snoozed') ok = tr.getAttribute('data-snoozed') === '1';
      else ok = tr.getAttribute('data-elig') === elig;
    }
    if (ok && court !== 'all') {
      var courts = []; try { courts = JSON.parse(tr.getAttribute('data-courts') || '[]'); } catch(x){}
      if (court === 'none') ok = courts.length === 0;
      else if (court === 'any') ok = courts.length > 0;
      else if (court.indexOf('court:') === 0) {
        var cid = parseInt(court.slice(6), 10);
        ok = courts.some(function(c){ return c.CourtId === cid; });
      }
    }
    if (ok && park !== 'all') ok = tr.getAttribute('data-park') === park;
    // hide any open detail row belonging to a now-hidden parent
    tr.style.display = ok ? '' : 'none';
    var dr = tr.nextElementSibling;
    if (dr && dr.classList.contains('rm-detailrow')) dr.style.display = ok ? '' : 'none';
    if (ok) shown++;
  });
  document.getElementById('rm-count').textContent = shown;
  rmRenderChips(q, elig, court, park);
  rmUpdateSelCount();
}

function rmRenderChips(q, elig, court, park){
  var chips = [];
  if (q) chips.push(['search', '“' + q + '”']);
  if (elig !== 'all') chips.push(['elig', document.getElementById('rm-filter-elig').selectedOptions[0].text]);
  if (court !== 'all') chips.push(['court', document.getElementById('rm-filter-court').selectedOptions[0].text]);
  var ps = document.getElementById('rm-filter-park');
  if (ps && park !== 'all') chips.push(['park', ps.selectedOptions[0].text]);
  document.getElementById('rm-chips').innerHTML = chips.map(function(c){
    return '<span class="rm-chip" data-clear="'+c[0]+'">'+rmEsc(c[1])+' ✕</span>';
  }).join('');
}

['rm-search','rm-filter-elig','rm-filter-court','rm-filter-park'].forEach(function(idv){
  var el = document.getElementById(idv); if (el) el.addEventListener('input', rmApplyFilters);
});
document.getElementById('rm-chips').addEventListener('click', function(e){
  var chip = e.target.closest('.rm-chip'); if (!chip) return;
  var k = chip.getAttribute('data-clear');
  if (k === 'search') document.getElementById('rm-search').value = '';
  if (k === 'elig')   document.getElementById('rm-filter-elig').value = 'all';
  if (k === 'court')  document.getElementById('rm-filter-court').value = 'all';
  if (k === 'park' && document.getElementById('rm-filter-park')) document.getElementById('rm-filter-park').value = 'all';
  rmApplyFilters();
});

var rmSortState = { key:'date', dir:1 };
function rmSort(key){
  rmSortState.dir = (rmSortState.key === key) ? -rmSortState.dir : 1;
  rmSortState.key = key;
  var tbody = document.getElementById('rm-tbody');
  var rows = RM.rows();
  rows.sort(function(a,b){
    var va, vb;
    if (key === 'supp') { va = +a.getAttribute('data-supp'); vb = +b.getAttribute('data-supp'); }
    else if (key === 'date') { va = a.getAttribute('data-date'); vb = b.getAttribute('data-date'); }
    else { va = a.getAttribute('data-' + key); vb = b.getAttribute('data-' + key); }
    if (va < vb) return -1 * rmSortState.dir;
    if (va > vb) return  1 * rmSortState.dir;
    return 0;
  });
  rows.forEach(function(tr){
    var dr = tr.nextElementSibling && tr.nextElementSibling.classList.contains('rm-detailrow') ? tr.nextElementSibling : null;
    tbody.appendChild(tr); if (dr) tbody.appendChild(dr);
  });
  document.querySelectorAll('.rm-sortable').forEach(function(th){ th.classList.remove('rm-sort-asc','rm-sort-desc'); });
  var thEl = document.querySelector('.rm-sortable[data-sort="'+key+'"]');
  if (thEl) thEl.classList.add(rmSortState.dir === 1 ? 'rm-sort-asc' : 'rm-sort-desc');
}
document.querySelectorAll('.rm-sortable').forEach(function(th){
  th.addEventListener('click', function(){ rmSort(th.getAttribute('data-sort')); });
});
// Default: oldest first (date ascending already; flip to show oldest at top)
rmSort('date');
```

- [ ] **Step 2: Style chips + sort carets (dark-mode aware)**: `.rm-chip{cursor:pointer}`, `.rm-sortable{cursor:pointer}`, `.rm-sort-asc::after{content:" ▲"}`, `.rm-sort-desc::after{content:" ▼"}`.

- [ ] **Step 3: Verify in Chrome**: each filter narrows rows + updates the `shown` count + adds a removable chip; clicking a chip clears that filter; clicking each sortable header reorders (caret flips on second click). Confirm with a court-specific filter that only recs on that court remain.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git diff --cached
git commit -m "Recommendations Manager: sorting, filtering, chips, counts"
```

---

## Task 7: Selection (checkbox, shift-range, select-all) + bulk bar

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (markup + inline `<script>`)

- [ ] **Step 1: Add the bulk bar markup** (after `.rm-foot`, hidden by default):

```php
  <div class="rm-bulkbar" id="rm-bulkbar" hidden>
    <span id="rm-bulklabel">0 selected</span>
    <button type="button" class="rm-bulk rm-bulk-court">Add to Court</button>
    <button type="button" class="rm-bulk rm-bulk-snooze">Snooze</button>
    <button type="button" class="rm-bulk rm-bulk-dismiss">Dismiss</button>
    <button type="button" class="rm-bulk rm-bulk-clear">Clear</button>
  </div>
```

- [ ] **Step 2: Add selection JS** (shift-click range over currently-visible rows):

```js
var rmLastIdx = null;
function rmVisibleRows(){ return RM.rows().filter(function(tr){ return tr.style.display !== 'none'; }); }
function rmSelected(){ return RM.rows().filter(function(tr){ return tr.querySelector('.rm-rowsel').checked; }); }
function rmUpdateSelCount(){
  var n = rmSelected().length;
  document.getElementById('rm-selcount').textContent = n;
  var bar = document.getElementById('rm-bulkbar');
  bar.hidden = n === 0;
  document.getElementById('rm-bulklabel').textContent = n + ' selected';
}
document.getElementById('rm-tbody').addEventListener('click', function(e){
  var cb = e.target.closest('.rm-rowsel'); if (!cb) return;
  var vis = rmVisibleRows();
  var idx = vis.indexOf(cb.closest('tr'));
  if (e.shiftKey && rmLastIdx !== null) {
    var lo = Math.min(idx, rmLastIdx), hi = Math.max(idx, rmLastIdx);
    for (var i = lo; i <= hi; i++) vis[i].querySelector('.rm-rowsel').checked = cb.checked;
  }
  rmLastIdx = idx;
  rmUpdateSelCount();
});
document.getElementById('rm-selall').addEventListener('change', function(){
  rmVisibleRows().forEach(function(tr){ tr.querySelector('.rm-rowsel').checked = this.checked; }, this);
  rmUpdateSelCount();
});
document.querySelector('.rm-bulk-clear').addEventListener('click', function(){
  RM.rows().forEach(function(tr){ tr.querySelector('.rm-rowsel').checked = false; });
  document.getElementById('rm-selall').checked = false;
  rmUpdateSelCount();
});
```

- [ ] **Step 3: Style the bulk bar (dark-mode aware), sticky to viewport bottom**: `.rm-bulkbar{position:sticky;bottom:0;display:flex;gap:8px;align-items:center;padding:8px}`.

- [ ] **Step 4: Verify in Chrome**: check a box → bulk bar appears with count; shift-click selects a range; "select all" checks all visible rows (and respects active filters); Clear resets. (Bulk action buttons are wired in Tasks 8–9.)

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git diff --cached
git commit -m "Recommendations Manager: row selection + bulk bar shell"
```

---

## Task 8: Per-row + bulk Snooze/Dismiss, toast util, config

**Files:**
- Modify: `orkui/controller/controller.Recommendations.php` (emit JS config block)
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (inline `<script>`)

- [ ] **Step 1: Emit a config object the JS can read**

In the template, before the main `<script>`, add:
```php
<script>
window.RmConfig = {
  uir: '<?= UIR ?>',
  kingdomId: <?= (int)$KingdomId ?>,
  parkId: <?= (int)$ParkId ?>,
  context: '<?= $Context === 'park' ? 'park' : 'kingdom' ?>',
  userId: <?= (int)$Uid ?>
};
</script>
```

- [ ] **Step 2: Add a toast util + the ajax base**

```js
function rmToast(msg, isErr){
  var t = document.createElement('div');
  t.className = 'rm-toast' + (isErr ? ' rm-toast-err' : '');
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function(){ t.classList.add('rm-toast-out'); setTimeout(function(){ t.remove(); }, 400); }, 2600);
}
// Build the snooze/dismiss endpoint base for the current scope.
function rmRecAjaxBase(action){
  if (RmConfig.context === 'park')
    return RmConfig.uir + 'ParkAjax/park/' + RmConfig.parkId + '/' + action;
  return RmConfig.uir + 'KingdomAjax/kingdom/' + RmConfig.kingdomId + '/' + action;
}
function rmPost(url, fd){
  return fetch(url, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); });
}
```

- [ ] **Step 3: Wire per-row Snooze + Dismiss**

```js
document.getElementById('rm-tbody').addEventListener('click', function(e){
  var sn = e.target.closest('.rm-act-snooze');
  if (sn) {
    var tr = sn.closest('tr');
    var snoozed = tr.getAttribute('data-snoozed') === '1';
    var action = snoozed ? 'unsnoozerecommendation' : 'snoozerecommendation';
    var fd = new FormData(); fd.append('RecommendationsId', tr.getAttribute('data-rec-id'));
    rmPost(rmRecAjaxBase(action), fd).then(function(j){
      if (j.status === 0) {
        tr.setAttribute('data-snoozed', snoozed ? '0' : '1');
        sn.textContent = snoozed ? '💤' : '🔔';
        sn.setAttribute('data-tip', snoozed ? 'Snooze' : 'Unsnooze');
        rmApplyFilters();
        rmToast(snoozed ? 'Unsnoozed.' : 'Snoozed.');
      } else rmToast(j.error || 'Failed.', true);
    });
    return;
  }
  var ds = e.target.closest('.rm-act-dismiss');
  if (ds) {
    var tr2 = ds.closest('tr');
    tnConfirm({ title:'Dismiss recommendation?', body:'This removes the recommendation from the pending list.', confirmLabel:'Dismiss', danger:true, onConfirm:function(){
      var fd2 = new FormData(); fd2.append('RecommendationsId', tr2.getAttribute('data-rec-id'));
      rmPost(rmRecAjaxBase('dismissrecommendation'), fd2).then(function(j){
        if (j.status === 0) { rmRemoveRow(tr2); rmToast('Dismissed.'); }
        else rmToast(j.error || 'Failed.', true);
      });
    }});
  }
});
function rmRemoveRow(tr){
  var dr = tr.nextElementSibling;
  if (dr && dr.classList.contains('rm-detailrow')) dr.remove();
  tr.remove();
  rmApplyFilters();
}
```

- [ ] **Step 4: Wire bulk Snooze + Dismiss** (sequential loop over selected, with a tally):

```js
function rmBulkSequential(rows, fn, doneMsg){
  var ok = 0, fail = 0, i = 0;
  (function next(){
    if (i >= rows.length) { rmToast(doneMsg(ok, fail), fail > 0); rmApplyFilters(); return; }
    var tr = rows[i++];
    fn(tr).then(function(good){ good ? ok++ : fail++; next(); });
  })();
}
document.querySelector('.rm-bulk-snooze').addEventListener('click', function(){
  var rows = rmSelected().filter(function(tr){ return tr.getAttribute('data-snoozed') !== '1'; });
  rmBulkSequential(rows, function(tr){
    var fd = new FormData(); fd.append('RecommendationsId', tr.getAttribute('data-rec-id'));
    return rmPost(rmRecAjaxBase('snoozerecommendation'), fd).then(function(j){
      if (j.status === 0) { tr.setAttribute('data-snoozed','1'); tr.querySelector('.rm-act-snooze').textContent='🔔'; tr.querySelector('.rm-rowsel').checked=false; return true; }
      return false;
    });
  }, function(ok, fail){ return 'Snoozed ' + ok + (fail ? ', ' + fail + ' failed' : '') + '.'; });
  rmUpdateSelCount();
});
document.querySelector('.rm-bulk-dismiss').addEventListener('click', function(){
  var rows = rmSelected();
  tnConfirm({ title:'Dismiss ' + rows.length + ' recommendation(s)?', body:'They will be removed from the pending list.', confirmLabel:'Dismiss all', danger:true, onConfirm:function(){
    rmBulkSequential(rows, function(tr){
      var fd = new FormData(); fd.append('RecommendationsId', tr.getAttribute('data-rec-id'));
      return rmPost(rmRecAjaxBase('dismissrecommendation'), fd).then(function(j){
        if (j.status === 0) { rmRemoveRow(tr); return true; } return false;
      });
    }, function(ok, fail){ return 'Dismissed ' + ok + (fail ? ', ' + fail + ' failed' : '') + '.'; });
  }});
});
```

- [ ] **Step 5: Style toast (dark-mode aware)**: fixed bottom-right, `.rm-toast-err` red accent, `.rm-toast-out{opacity:0;transition:opacity .4s}`.

- [ ] **Step 6: Verify (curl + Chrome + DB read-back)**

Curl the snooze endpoint directly to confirm wiring/scope:
```bash
curl -s -c "$J" -b "$J" "${BASE}KingdomAjax/kingdom/KINGDOM_ID/snoozerecommendation" --data 'RecommendationsId=REC_ID'
```
Expected: `{"status":0}`. Confirm in DB the snooze column was set; then unsnooze. In Chrome: per-row snooze toggles bell/zzz + toast; dismiss shows `tnConfirm` then removes row; select 2 rows → Bulk Dismiss removes both with a tally toast.

- [ ] **Step 7: Commit**

```bash
git add orkui/controller/controller.Recommendations.php orkui/template/revised-frontend/Recommendations_manage.tpl
git diff --cached
git commit -m "Recommendations Manager: snooze/dismiss (row + bulk) + toast"
```

---

## Task 9: Grant Now (instant)

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (inline `<script>`)

`Grant Now` posts to `Admin/player/{MundaneId}/addaward` (the same save URL the inline modal uses), then dismisses the rec to match court-grant behavior.

- [ ] **Step 1: Confirm the date format `add_player_award` expects**

```bash
grep -n "kn-award-date" orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/script/revised.js | head
```
Inspect the date input/flatpickr config to confirm the submitted `Date` format (expected `Y-m-d`). Use that exact format in Step 2.

- [ ] **Step 2: Wire Grant Now**

```js
function rmTodayYMD(){
  var d = new Date(); // grant uses today's date
  function p(n){ return (n<10?'0':'')+n; }
  return d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate());
}
document.getElementById('rm-tbody').addEventListener('click', function(e){
  var g = e.target.closest('.rm-act-grant'); if (!g) return;
  var tr = g.closest('tr');
  var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch(x){}
  tnConfirm({ title:'Grant now?', body:'Grant “' + (rec.Persona||'') + '” the award immediately and remove it from pending.', confirmLabel:'Grant Now', onConfirm:function(){
    var fd = new FormData();
    fd.append('KingdomAwardId', rec.KingdomAwardId);
    fd.append('GivenById', RmConfig.userId);
    fd.append('Date', rmTodayYMD());
    fd.append('ParkId', RmConfig.parkId || '0');
    fd.append('KingdomId', RmConfig.kingdomId || '0');
    fd.append('EventId', '0');
    fd.append('Note', rec.Reason || '');
    if (rec.Rank) fd.append('Rank', rec.Rank);
    rmPost(RmConfig.uir + 'Admin/player/' + rec.MundaneId + '/addaward', fd).then(function(){
      // addaward returns HTML/redirect, not our JSON; treat reachable as success, then soft-delete the rec.
      var fd2 = new FormData(); fd2.append('RecommendationsId', rec.RecommendationsId);
      return rmPost(rmRecAjaxBase('dismissrecommendation'), fd2);
    }).then(function(){ rmRemoveRow(tr); rmToast('Granted.'); })
      .catch(function(){ rmToast('Grant failed.', true); });
  }});
});
```

- [ ] **Step 3: Verify the addaward response shape**

Curl the endpoint to see exactly what it returns (it may be JSON, a redirect, or HTML — adjust the `.then` success detection accordingly; if it returns JSON with a status field, branch on it instead of assuming success):
```bash
curl -s -c "$J" -b "$J" "${BASE}Admin/player/MUNDANE_ID/addaward" \
  --data 'KingdomAwardId=KAID&GivenById=UID&Date=2026-06-05&ParkId=0&KingdomId=KINGDOM_ID&EventId=0&Note=test'
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
  "SELECT * FROM ork_awards WHERE mundane_id=MUNDANE_ID ORDER BY award_id DESC LIMIT 1;"
```
Confirm the award row was written. If the response is JSON with `status`, update Step 2 to check it before dismissing. (Clean up the test award if needed.)

- [ ] **Step 4: Verify in Chrome**: click ⚡ on a row → `tnConfirm` → on confirm the row disappears with a "Granted." toast; reload and confirm the rec is gone and the award shows on the player.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git diff --cached
git commit -m "Recommendations Manager: instant Grant Now"
```

---

## Task 10: Add to Court modal (single + bulk)

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (modal markup + inline `<script>`)

- [ ] **Step 1: Add the modal markup**

```php
  <div class="rm-modal-overlay" id="rm-court-overlay" hidden>
    <div class="rm-modal">
      <h2 class="rm-modal-title">Add to Court</h2>
      <div class="rm-modal-sub" id="rm-court-sub"></div>
      <div class="rm-modal-modes">
        <label><input type="radio" name="rm-court-mode" value="existing" checked> Existing court</label>
        <label><input type="radio" name="rm-court-mode" value="new"> Create new court</label>
      </div>
      <div id="rm-court-existing">
        <select id="rm-court-select" class="rm-fsel">
          <?php foreach ($Courts as $c) { ?>
            <option value="<?= (int)$c['CourtId'] ?>"><?= htmlspecialchars($c['Name']) ?><?= $c['CourtDate'] ? ' — ' . htmlspecialchars($c['CourtDate']) : '' ?> (<?= htmlspecialchars($c['Status']) ?>)</option>
          <?php } ?>
        </select>
        <?php if (!count($Courts)) { ?><div class="rm-empty">No courts yet — create one.</div><?php } ?>
      </div>
      <div id="rm-court-new" hidden>
        <input type="text" id="rm-court-name" class="rm-input" placeholder="Court name" maxlength="100">
        <input type="text" id="rm-court-date" class="rm-input" placeholder="Court date (optional)">
      </div>
      <div class="rm-modal-actions">
        <button type="button" class="rm-btn rm-btn-ghost" id="rm-court-cancel">Cancel</button>
        <button type="button" class="rm-btn rm-btn-primary" id="rm-court-submit">Add</button>
      </div>
    </div>
  </div>
```

Initialize the date field with flatpickr human-readable display (`altInput:true, altFormat:'F j, Y'`) if flatpickr is loaded on the page; otherwise leave as a plain text `Y-m-d` field and note it.

- [ ] **Step 2: Mode toggle + open/close JS**

```js
var rmCourtTargets = []; // array of rec payloads to add
function rmOpenCourtModal(targets){
  rmCourtTargets = targets;
  document.getElementById('rm-court-sub').textContent = targets.length === 1
    ? 'Adding 1 recommendation.' : 'Adding ' + targets.length + ' recommendations.';
  document.getElementById('rm-court-overlay').hidden = false;
}
function rmCloseCourtModal(){ document.getElementById('rm-court-overlay').hidden = true; }
document.getElementById('rm-court-cancel').addEventListener('click', rmCloseCourtModal);
document.querySelectorAll('input[name="rm-court-mode"]').forEach(function(r){
  r.addEventListener('change', function(){
    var isNew = document.querySelector('input[name="rm-court-mode"]:checked').value === 'new';
    document.getElementById('rm-court-new').hidden = !isNew;
    document.getElementById('rm-court-existing').hidden = isNew;
  });
});
```

- [ ] **Step 3: Per-row + bulk openers**

```js
document.getElementById('rm-tbody').addEventListener('click', function(e){
  var c = e.target.closest('.rm-act-court'); if (!c) return;
  var tr = c.closest('tr'); var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec')||'{}'); } catch(x){}
  rec._tr = tr; rmOpenCourtModal([rec]);
});
document.querySelector('.rm-bulk-court').addEventListener('click', function(){
  var targets = rmSelected().map(function(tr){
    var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec')||'{}'); } catch(x){} rec._tr = tr; return rec;
  });
  if (targets.length) rmOpenCourtModal(targets);
});
```

- [ ] **Step 4: Submit (create court if needed, then add_award per target)**

```js
document.getElementById('rm-court-submit').addEventListener('click', function(){
  var mode = document.querySelector('input[name="rm-court-mode"]:checked').value;
  var btn = this; btn.disabled = true;
  function withCourtId(cb){
    if (mode === 'new') {
      var fd = new FormData();
      fd.append('KingdomId', RmConfig.kingdomId);
      fd.append('ParkId', RmConfig.parkId || '0');
      fd.append('Name', (document.getElementById('rm-court-name').value || '').trim());
      fd.append('CourtDate', (document.getElementById('rm-court-date').value || '').trim());
      fd.append('EventCalendarDetailId', '0');
      rmPost(RmConfig.uir + 'CourtAjax/create_court', fd).then(function(j){
        if (j.status === 0 && j.court_id) cb(j.court_id, j.name);
        else { rmToast(j.error || 'Could not create court.', true); btn.disabled = false; }
      });
    } else {
      var sel = document.getElementById('rm-court-select');
      if (!sel || !sel.value) { rmToast('Pick a court.', true); btn.disabled = false; return; }
      cb(parseInt(sel.value, 10), sel.selectedOptions[0].text);
    }
  }
  withCourtId(function(courtId, courtName){
    var ok = 0, skip = 0, fail = 0, i = 0;
    (function next(){
      if (i >= rmCourtTargets.length) {
        rmToast('Added ' + ok + (skip ? ', ' + skip + ' already on court' : '') + (fail ? ', ' + fail + ' failed' : '') + '.', fail > 0);
        btn.disabled = false; rmCloseCourtModal(); rmApplyFilters(); return;
      }
      var rec = rmCourtTargets[i++];
      // skip if already on this court
      var existing = []; try { existing = JSON.parse(rec._tr.getAttribute('data-courts')||'[]'); } catch(x){}
      if (existing.some(function(cc){ return cc.CourtId === courtId; })) { skip++; next(); return; }
      var fd = new FormData();
      fd.append('CourtId', courtId);
      fd.append('MundaneId', rec.MundaneId);
      fd.append('KingdomAwardId', rec.KingdomAwardId);
      fd.append('Rank', rec.Rank || 0);
      fd.append('RecommendationsId', rec.RecommendationsId);
      rmPost(RmConfig.uir + 'CourtAjax/add_award', fd).then(function(j){
        if (j.status === 0) {
          ok++;
          existing.push({ CourtId: courtId, Name: courtName, CourtDate: '', Status: 'planned' });
          rec._tr.setAttribute('data-courts', JSON.stringify(existing));
          rmUpdateCourtBadge(rec._tr, existing);
          rec._tr.querySelector('.rm-rowsel').checked = false;
        } else fail++;
        next();
      }).catch(function(){ fail++; next(); });
    })();
  });
});
function rmUpdateCourtBadge(tr, courts){
  var td = tr.querySelector('.rm-col-court');
  if (!courts.length) { td.innerHTML = '<span class="rm-empty">—</span>'; return; }
  var more = courts.length > 1 ? ' <span class="rm-courtmore">+' + (courts.length-1) + '</span>' : '';
  td.innerHTML = '<a class="rm-courtbadge" href="' + RmConfig.uir + 'Court/detail/' + courts[0].CourtId + '">' + rmEsc(courts[0].Name) + more + '</a>';
}
```

- [ ] **Step 5: Style the modal (dark-mode aware)**: overlay dim, centered card, reset `.rm-modal-title` heading box, ghost vs primary buttons with sufficient dark-mode contrast.

- [ ] **Step 6: Verify (curl + Chrome + DB)**

Curl add_award directly to confirm the contract:
```bash
curl -s -c "$J" -b "$J" "${BASE}CourtAjax/add_award" \
  --data 'CourtId=COURT_ID&MundaneId=MID&KingdomAwardId=KAID&Rank=0&RecommendationsId=REC_ID'
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
 "SELECT court_award_id, court_id, recommendations_id FROM ork_court_award WHERE recommendations_id=REC_ID;"
```
Expected: `{"status":0,...}` and a `court_award` row linking the rec. In Chrome: per-row ＋ opens modal → pick existing court → Add → court badge appears on the row; create-new path creates a court then adds; select 3 rows → Bulk Add to Court → all get badges with an "Added 3" toast; re-adding an already-on-court rec is skipped.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git diff --cached
git commit -m "Recommendations Manager: Add to Court modal (single + bulk)"
```

---

## Task 11: Inline profile tab — add Manage button, remove admin actions

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl`
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl`
- Modify: `orkui/template/revised-frontend/script/revised.js` (remove now-orphaned Kingdom/Park admin-rec handlers only)

- [ ] **Step 1: Normalize-check both templates** (`awk '/^\t/{c++} END{print c+0}' <file>`; fix with php-cs-fixer if nonzero before editing).

- [ ] **Step 2: Add the Manage Recommendations button (authority-gated) to the Kingdom recs tab header**

The controller already computes management authority for the profile (the inline grant/snooze/dismiss controls were authority-gated). Find that existing flag in `controller.Kingdom.php::profile()` (search for where the recs admin buttons' visibility is decided) and reuse it. In `Kingdomnew_index.tpl`, within the recommendations tab header (around line 741), add:

```php
<?php if (!empty($CanManageRecs)) { ?>
  <a class="kn-btn kn-btn-primary kn-manage-recs" href="<?= UIR ?>Recommendations/manage/kingdom/<?= (int)$kingdom_id ?>">Manage Recommendations</a>
<?php } ?>
```
If the existing authority variable has a different name, use that exact name instead of `CanManageRecs` (confirm by grepping the template for the current grant button's surrounding `if`).

- [ ] **Step 3: Remove inline admin controls from the Kingdom recs rows**

In `Kingdomnew_index.tpl`, delete the per-row admin buttons: `.pk-rec-grant-btn` (data-rec grant), `.pk-rec-addcourt-btn`, `.pk-rec-snooze-btn`, `.pk-rec-dismiss-btn`, and the hidden `#kn-bulk-actions` row. **Keep** the +1/second controls (`rs-action-btn`, edit/withdraw, edit reason) and read-only reason/seconds display.

- [ ] **Step 4: Remove now-orphan modals** (only after confirming no other caller)

```bash
grep -n "kn-award-overlay\|kn-addcourt-overlay\|knGiveFromRec\|knOpenAwardModal" orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/script/revised.js
```
If the only references are the inline recs controls you just removed, delete the `#kn-award-overlay` and `#kn-addcourt-overlay` modal markup from the template and the corresponding handlers from `revised.js`. If anything else references them (e.g. an "award a player" button elsewhere on the profile), **leave them**.

- [ ] **Step 5: Repeat Steps 2–4 for the Park profile** (`Parknew_index.tpl`, `pk-` prefixes, `pkGiveFromRec`, `pk-award-overlay`, `pk-addcourt-overlay`, route `Recommendations/manage/park/{park_id}`).

- [ ] **Step 6: Verify in Chrome**

Kingdom + Park profiles: Recommendations tab shows the "Manage Recommendations" button (for an authorized user) and the rows still allow +1/second + reading notes but have no grant/snooze/dismiss/add-court buttons. The button navigates to the manager. Confirm no JS console errors (removed handlers don't leave dangling references). Confirm an unauthorized user sees neither the button nor any admin control.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl orkui/template/revised-frontend/script/revised.js
git diff --cached
git commit -m "Recommendations: move admin rec actions to the Manager, add entry button"
```

---

## Task 12: Dark-mode + conventions pass, final verification

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (any fixes found)

- [ ] **Step 1: Dark-mode pre-flight walk (per checklist)**

In Chrome with dark mode on, walk every surface: grid lines/zebra/sticky header contrast, filter selects + search placeholder, chips, badges, court badge, action icons + `data-tip` tooltips, expand detail rows, selection + bulk bar, Add-to-Court modal (header pill leak, ghost button muting, inputs/labels), toasts. Fix any low-contrast or global-`h1–h6`-box leakage inline.

- [ ] **Step 2: Conventions sweep**

Confirm: no `{$...}`/`{if}` Smarty in the tpl; no native `confirm`/`alert`/`title=`; `tnConfirm` used for grant/dismiss; dates shown human-readable; no `error_log`/`print_r` debug left behind; CSS all `rm-` prefixed; JS/CSS inlined.
```bash
grep -n "console.log\|alert(\|confirm(\| title=\|{if\|{foreach\|{\$" orkui/template/revised-frontend/Recommendations_manage.tpl
```
Expected: no problematic matches (any remaining `console.log` removed).

- [ ] **Step 3: End-to-end smoke (Kingdom + Park)**

For both a kingdom scope and a park scope, with an authorized officer: load the manager, exercise one of each action (filter, sort, expand, snooze, dismiss, grant, add-to-court single, bulk add-to-court), and confirm DB state via read-back. Confirm `docker logs --tail=40 ork3-php8-app` shows no errors across the session.

- [ ] **Step 4: Commit any fixes**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git diff --cached
git commit -m "Recommendations Manager: dark-mode + conventions polish"
```

---

## Self-Review (completed during planning)

- **Spec coverage:** standalone route (Task 1), spreadsheet grid (Task 4), sticky chrome/sort/filter/chips (Tasks 4,6), expand seconds+reason (Task 5), selection+bulk (Task 7), snooze/dismiss row+bulk (Task 8), Grant Now instant (Task 9), Add to Court existing/new single+bulk (Task 10), court badge + court filter via new `getRecommendationCourtMap` (Tasks 2,3,6,10), inline tab keeps +1 only + Manage button (Task 11), authority gate via `Court::canManage` (Task 1), dark-mode + conventions (Task 12). All spec sections map to a task.
- **Open risks carried from spec:** post-Grant-Now soft-delete handled by chaining `dismissrecommendation` (Task 9); multi-court rec shows first court + `+n` (Task 4); `addaward` response shape verified before trusting success (Task 9 Step 3); park abbrev column name verified (Task 3 Step 2); existing profile authority var name + orphan-modal callers verified before edits (Task 11).
- **Type consistency:** endpoint field names match the extracted contracts (`RecommendationsId`, `KingdomAwardId`, `MundaneId`, `Rank`, `CourtId`, `Name`, `CourtDate`, `EventCalendarDetailId`, `GivenById`, `Date`, `Note`); JS helpers (`rmApplyFilters`, `rmUpdateSelCount`, `rmRemoveRow`, `rmRecAjaxBase`, `rmPost`, `rmToast`, `rmEsc`) are defined once and reused consistently.
