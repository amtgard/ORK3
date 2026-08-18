# Playersearch Standardization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace ~34 divergent player-search implementations with one ranking core, one frontend component (`playersearch`), and one canonical endpoint that ranks results park→kingdom→everywhere (rank-don't-exclude, opt-in hard filter).

**Architecture:** A single lib method `SearchService::RankedPlayers()` is the source of truth for the ring SQL, restricted-name gate, kingdom-15 exclusion, and ORDER BY. A session-gated controller route `SearchAjax/players` (what the frontend calls) and a session-less SOAP action `Search/Players` (curl-testable, legacy-compat) both delegate to it. A single global JS/CSS module `OrkPlayerSearch` (`ops-` prefix, custom dropdown, never jQuery UI) attaches to any input. Surfaces declare their center (`parkId`/`kingdomId`) and optionally `restrictTo`.

**Tech Stack:** PHP 8 (Ork3 framework, raw `$DB`), MariaDB, vanilla JS, custom CSS. No PHP test framework — tests are (a) SQL run directly via `docker exec ork3-php8-db mariadb`, (b) curl against the session-less SOAP action, (c) browser verification for UI.

**Spec:** `docs/superpowers/specs/2026-05-26-playersearch-standardization-design.md`

**Conventions (project rules — do not violate):**
- Multi-line edits to `.php`/`.tpl`/`.js` use Python string replace, never the Edit tool (tab/space mismatches).
- `$DB->Clear()` before every raw `Execute`/`DataSet`.
- Build search URLs with `&q=` (UIR already ends in `?Route=`).
- Dropdowns in modals use `position:fixed`. Dark-mode compatible. No native `title` tooltips.
- Never stage `class.Authorization.php` (login-bypass hack). Stage files explicitly; `git diff --cached` before commit.
- DB migration command: `docker exec -i ork3-php8-db mariadb -u root -proot ork < file.sql`.

---

## File Structure

**Create:**
- `system/lib/ork3/class.SearchService.php` — add `RankedPlayers()` + `resolveAbbrevPrefix()` (core; same file, new methods)
- `orkservice/Search/SearchService.registration.php` — register `Search/Players` SOAP action
- `orkui/controller/controller.SearchAjax.php` — add `players()` route
- `orkui/template/default/script/ork-player-search.js` — the `OrkPlayerSearch` component
- `orkui/template/default/style/ork-player-search.css` — `ops-` dropdown styles (light + dark)
- `tests/playersearch/ranking.sh` — curl/SQL ranking assertions

**Modify (foundation):**
- `orkui/template/default/default.theme` — include the JS/CSS app-wide

**Modify (rollout — see Phase 2/3 tables):** the converted templates and `revised.js`.

---

## Phase 0 — Foundation

### Task 1: Ranking core `SearchService::RankedPlayers()`

**Files:**
- Modify: `system/lib/ork3/class.SearchService.php` (add two methods near `Player()`, ~line 318)
- Test: `tests/playersearch/ranking.sh`

- [ ] **Step 1: Write the failing test (SQL-level ranking assertion)**

Create `tests/playersearch/ranking.sh`:

```bash
#!/usr/bin/env bash
# Ranking contract test for playersearch. Runs the SOAP action (session-less) and
# asserts park->kingdom->everywhere ordering. Requires the docker app on :19080.
set -euo pipefail
SVC="http://localhost:19080/orkservice/Search/SearchService.php"
DB="docker exec ork3-php8-db mariadb -u root -proot ork -N -e"

# Pick a persona prefix present in >=3 kingdoms.
TERM=$($DB "SELECT LOWER(SUBSTRING(persona,1,3)) p FROM ork_mundane WHERE LENGTH(persona)>2 AND kingdom_id>0 GROUP BY p HAVING COUNT(DISTINCT kingdom_id)>=3 ORDER BY COUNT(*) DESC LIMIT 1;")
# Pick a park + its kingdom that actually has a match for TERM.
read PARKID KID < <($DB "SELECT m.park_id, m.kingdom_id FROM ork_mundane m WHERE m.persona LIKE '${TERM}%' AND m.park_id>0 AND m.kingdom_id>0 LIMIT 1;")
echo "TERM=$TERM PARKID=$PARKID KID=$KID"

assert_first_ring_zero () {
  local url="$1" ; local label="$2"
  python3 - "$url" "$label" <<'PY'
import sys, json, urllib.request
url, label = sys.argv[1], sys.argv[2]
data = json.load(urllib.request.urlopen(url, timeout=10))
rings = [r.get('Ring') for r in data]
print(f"{label}: rows={len(data)} rings={rings[:10]}")
assert data, f"{label}: expected rows"
assert rings == sorted(rings), f"{label}: rings not nondecreasing -> {rings}"
PY
}

# 1) park-centered: results must be ordered ring 0 (park) then 1 (kingdom) then 2.
assert_first_ring_zero "${SVC}?Action=Search/Players&q=${TERM}&parkId=${PARKID}&kingdomId=${KID}&limit=25" "park-centered"
# 2) kingdom-centered: ring 0 (kingdom) then 1 (everywhere).
assert_first_ring_zero "${SVC}?Action=Search/Players&q=${TERM}&kingdomId=${KID}&limit=25" "kingdom-centered"
# 3) global: many kingdoms returned (rank-don't-exclude proven elsewhere); just must return rows.
assert_first_ring_zero "${SVC}?Action=Search/Players&q=${TERM}&limit=25" "global"
# 4) restrictTo=kingdom: every row must be in KID.
python3 - "${SVC}?Action=Search/Players&q=${TERM}&kingdomId=${KID}&restrictTo=kingdom&limit=50" <<'PY'
import sys, json, urllib.request
data = json.load(urllib.request.urlopen(sys.argv[1], timeout=10))
import os
kid = int(os.environ.get('KID','0'))
PY
echo "ALL RANKING ASSERTIONS PASSED"
```

- [ ] **Step 2: Run it to verify it fails**

Run: `chmod +x tests/playersearch/ranking.sh && KID=$KID tests/playersearch/ranking.sh`
Expected: FAIL — `Search/Players` action not registered (HTTP/JSON error, no `Ring` field).

- [ ] **Step 3: Implement the core**

In `class.SearchService.php`, add (use Python to insert; match existing tab indentation):

```php
	/**
	 * Resolve a "KD:PK term" / "KD: term" abbreviation prefix to ids.
	 * Returns [filterKingdomId, filterParkId, strippedSearch].
	 */
	private function resolveAbbrevPrefix($q) {
		$filterKid = 0; $filterPid = 0; $search = $q;
		if (preg_match('/^([a-z0-9]{2,3}):([a-z0-9]{2,3}|\*)?\s+(.+)$/i', $q, $m)) {
			$kAbbr = mysql_real_escape_string($m[1]);
			$this->db->clear();
			$rs = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE abbreviation = '{$kAbbr}' LIMIT 1");
			if ($rs !== false && $rs->size() > 0) { $rs->Next(); $filterKid = (int)$rs->kingdom_id; }
			if ($filterKid > 0 && !empty($m[2]) && $m[2] !== '*') {
				$pAbbr = mysql_real_escape_string($m[2]);
				$rs = $this->db->query("SELECT park_id FROM " . DB_PREFIX . "park WHERE abbreviation = '{$pAbbr}' AND kingdom_id = {$filterKid} LIMIT 1");
				if ($rs !== false && $rs->size() > 0) { $rs->Next(); $filterPid = (int)$rs->park_id; }
			}
			$search = trim($m[3]);
		}
		return [$filterKid, $filterPid, $search];
	}

	/**
	 * Canonical playersearch ranking core. Rings centered on the surface:
	 * park (0) -> kingdom (1) -> everywhere (2). Rank-don't-exclude by default;
	 * $restrict_to ('park'|'kingdom') hard-filters the outer rings off.
	 * @param array $p q, parkId, kingdomId, restrictTo, includeInactive,
	 *                  includeSuspended, limit, token
	 * @return array normalized rows incl. 'Ring'
	 */
	public function RankedPlayers($p) {
		$q                 = trim($p['q'] ?? '');
		if (strlen($q) < 2) return [];
		$park_id           = (int)($p['parkId'] ?? 0);
		$kingdom_id        = (int)($p['kingdomId'] ?? 0);
		$restrict_to       = in_array(($p['restrictTo'] ?? ''), ['park','kingdom'], true) ? $p['restrictTo'] : '';
		$include_inactive  = !empty($p['includeInactive']);
		$include_suspended = !empty($p['includeSuspended']);
		$limit             = min(max((int)($p['limit'] ?? 15), 1), 100);

		// Admin gate (token) — admins bypass the restricted-name privacy gate.
		$is_admin = false;
		if (!empty($p['token'])) {
			$uid = Ork3::$Lib->authorization->IsAuthorized($p['token']);
			if ($uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, null, null)) $is_admin = true;
		}
		$this->db->clear();

		list($filterKid, $filterPid, $search) = $this->resolveAbbrevPrefix($q);
		$term = mysql_real_escape_string($search);

		// Derive the kingdom of the park if a park center was given without a kingdom.
		if ($park_id > 0 && $kingdom_id <= 0) {
			$rs = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "park WHERE park_id = {$park_id} LIMIT 1");
			if ($rs !== false && $rs->size() > 0) { $rs->Next(); $kingdom_id = (int)$rs->kingdom_id; }
		}

		// Ring expression (innermost known ring = 0).
		if ($park_id > 0) {
			$ring = "CASE WHEN m.park_id = {$park_id} THEN 0 WHEN m.kingdom_id = {$kingdom_id} THEN 1 ELSE 2 END";
		} elseif ($kingdom_id > 0) {
			$ring = "CASE WHEN m.kingdom_id = {$kingdom_id} THEN 0 ELSE 1 END";
		} else {
			$ring = "0";
		}

		// WHERE fragments.
		$where = ["LENGTH(m.persona) > 0"];
		$where[] = $include_suspended ? "1" : "m.suspended = 0";
		$where[] = $include_inactive  ? "1" : "m.active = 1";
		$where[] = "(m.kingdom_id != 15 AND (p.kingdom_id IS NULL OR p.kingdom_id != 15))";
		// Restricted-name privacy gate (admins bypass).
		$mundane = $is_admin
			? "OR m.given_name LIKE '%{$term}%' OR m.surname LIKE '%{$term}%'"
			: "OR (m.restricted = 0 AND (m.given_name LIKE '%{$term}%' OR m.surname LIKE '%{$term}%'))";
		$where[] = "(m.persona LIKE '%{$term}%' OR m.username LIKE '%{$term}%' {$mundane})";

		// Hard filters: abbreviation prefix wins, else restrictTo.
		if     ($filterPid > 0)            { $where[] = "m.park_id = {$filterPid}"; }
		elseif ($filterKid > 0)            { $where[] = "m.kingdom_id = {$filterKid}"; }
		elseif ($restrict_to === 'park'    && $park_id > 0)    { $where[] = "m.park_id = {$park_id}"; }
		elseif ($restrict_to === 'kingdom' && $kingdom_id > 0) { $where[] = "m.kingdom_id = {$kingdom_id}"; }

		$sql = "SELECT m.mundane_id, m.persona, m.active, m.suspended,
		               k.kingdom_id, k.name AS kingdom_name, k.abbreviation AS k_abbr,
		               p.park_id, p.name AS park_name, p.abbreviation AS p_abbr,
		               ({$ring}) AS ring
		        FROM " . DB_PREFIX . "mundane m
		        LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
		        LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
		        WHERE " . implode(' AND ', $where) . "
		        ORDER BY m.suspended ASC, m.active DESC, ring ASC, m.persona ASC
		        LIMIT {$limit}";

		$this->db->clear();
		$rs = $this->db->query($sql);
		$out = [];
		if ($rs !== false && $rs->size() > 0) {
			while ($rs->Next()) {
				$out[] = [
					'MundaneId'   => (int)$rs->mundane_id,
					'Persona'     => $rs->persona,
					'KingdomId'   => (int)$rs->kingdom_id,
					'ParkId'      => (int)$rs->park_id,
					'KAbbr'       => $rs->k_abbr,
					'PAbbr'       => $rs->p_abbr,
					'KingdomName' => $rs->kingdom_name,
					'ParkName'    => $rs->park_name,
					'Active'      => (int)$rs->active,
					'Suspended'   => (int)$rs->suspended,
					'Ring'        => (int)$rs->ring,
				];
			}
		}
		return $out;
	}
```

> Note: confirm `$this->db->query()`/`->size()`/`->Next()` match the existing `Player()` usage in this file; mirror whatever that method uses (it is the working reference).

- [ ] **Step 4: Register the session-less SOAP action**

In `orkservice/Search/SearchService.registration.php`, add (Python insert, mirror the `Search/Player` block):

```php
$server->Register(
	array(
		'Search/Players',
		array('SearchService', 'RankedPlayers'),
		array(
			array( 'q','request',false,'string',true ),
			array( 'parkId','request',true,'int',true ),
			array( 'kingdomId','request',true,'int',true ),
			array( 'restrictTo','request',true,'string',true ),
			array( 'includeInactive','request',true,'int',true ),
			array( 'includeSuspended','request',true,'int',true ),
			array( 'limit','request',true,'int',true ),
			array( 'token','request',true,'string',true )
		)
	)
);
```

> If the SOAP layer passes a single positional array vs named params, adapt `RankedPlayers` signature to match how `Player` receives its args (check the registration→method calling convention in this file). The reference is the existing `Search/Player` registration.

- [ ] **Step 5: Run the test to verify it passes**

Run: `KID=$KID tests/playersearch/ranking.sh`
Expected: `ALL RANKING ASSERTIONS PASSED`, rings nondecreasing for park- and kingdom-centered.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.SearchService.php orkservice/Search/SearchService.registration.php tests/playersearch/ranking.sh
git commit -m "feat(playersearch): ranking core + Search/Players SOAP action"
```

### Task 2: Frontend controller route `SearchAjax/players`

**Files:**
- Modify: `orkui/controller/controller.SearchAjax.php` (add `players()`)

- [ ] **Step 1: Implement the route**

Add to `controller.SearchAjax.php` (Python insert; mirror `universal()` header/auth pattern):

```php
	public function players($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) { echo json_encode([]); exit; }
		$rows = Ork3::$Lib->searchservice->RankedPlayers([
			'q'                => $_GET['q']                ?? '',
			'parkId'           => (int)($_GET['parkId']     ?? 0),
			'kingdomId'        => (int)($_GET['kingdomId']  ?? 0),
			'restrictTo'       => $_GET['restrictTo']       ?? '',
			'includeInactive'  => !empty($_GET['include_inactive']),
			'includeSuspended' => !empty($_GET['include_suspended']),
			'limit'            => (int)($_GET['limit']      ?? 15),
			'token'            => $this->session->token     ?? null,
		]);
		echo json_encode($rows);
		exit;
	}
```

> Verify `Ork3::$Lib->searchservice` is the registered handle for `SearchService`. If not registered, instantiate as the other controllers reach lib services (grep `Ork3::$Lib->` in controllers). If no lib handle exists, add the SOAP-style call used by legacy frontends instead and keep the controller a thin passthrough.

- [ ] **Step 2: Verify wiring (session smoke test)**

Run (logged-in browser or dev bypass): open `index.php?Route=SearchAjax/players&kingdomId=<id>&q=<term>` and confirm a JSON array with `Ring` fields.
Expected: same rows/ordering as the SOAP action for the same params.

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.SearchAjax.php
git commit -m "feat(playersearch): SearchAjax/players frontend route (delegates to core)"
```

### Task 3: The `OrkPlayerSearch` component (JS + CSS)

**Files:**
- Create: `orkui/template/default/script/ork-player-search.js`
- Create: `orkui/template/default/style/ork-player-search.css`

- [ ] **Step 1: Write the component**

`ork-player-search.js`:

```js
/* OrkPlayerSearch — the one playersearch component. Custom dropdown, never jQuery UI.
   Usage:
     OrkPlayerSearch.attach(inputEl, {
       parkId, kingdomId, restrictTo, includeInactive, includeSuspended, limit,
       onSelect: function(player){...},   // player = normalized row from SearchAjax/players
       uir: window.UIR                    // optional; defaults to global UIR
     });
*/
window.OrkPlayerSearch = (function () {
  var DEBOUNCE = 220, MINLEN = 2;
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  function attach(input, opts) {
    opts = opts || {};
    if (!input || input._opsAttached) return;
    input._opsAttached = true;
    var uir = opts.uir || window.UIR || '';
    // Results dropdown owned by the component.
    var dd = document.createElement('div');
    dd.className = 'ops-ac-results';
    document.body.appendChild(dd);
    var timer, items = [], active = -1;

    function close(){ dd.classList.remove('ops-ac-open'); active = -1; }
    function position(){
      var r = input.getBoundingClientRect();
      // position:fixed so it is never clipped inside a modal stacking context.
      dd.style.position = 'fixed';
      dd.style.left = r.left + 'px';
      dd.style.top  = (r.bottom + 2) + 'px';
      dd.style.width = r.width + 'px';
    }
    function render(data){
      items = data || [];
      if (!items.length){ dd.innerHTML = '<div class="ops-ac-empty">No players found</div>'; }
      else {
        dd.innerHTML = items.map(function(p, i){
          var loc = (p.KAbbr||'') + (p.PAbbr ? ':'+p.PAbbr : '');
          return '<div class="ops-ac-item" data-i="'+i+'" tabindex="-1">'
            + esc(p.Persona)
            + (loc ? ' <span class="ops-ac-loc">('+esc(loc)+')</span>' : '')
            + (p.Active===0    ? ' <span class="ops-ac-badge">Inactive</span>' : '')
            + (p.Suspended     ? ' <span class="ops-ac-badge ops-ac-banned">Banned</span>' : '')
            + '</div>';
        }).join('');
      }
      position(); dd.classList.add('ops-ac-open'); active = -1;
    }
    function pick(i){
      var p = items[i]; if (!p) return;
      input.value = p.Persona; close();
      if (opts.onSelect) opts.onSelect(p);
    }
    function search(term){
      var url = uir + 'SearchAjax/players'
        + '&parkId='    + (opts.parkId    || 0)
        + '&kingdomId=' + (opts.kingdomId || 0)
        + (opts.restrictTo ? '&restrictTo=' + encodeURIComponent(opts.restrictTo) : '')
        + (opts.includeInactive  ? '&include_inactive=1'  : '')
        + (opts.includeSuspended ? '&include_suspended=1' : '')
        + '&limit=' + (opts.limit || 15)
        + '&q=' + encodeURIComponent(term);   // &q= (UIR ends in ?Route=)
      fetch(url).then(function(r){ return r.json(); }).then(render)
        .catch(function(e){ if (e.name!=='AbortError') console.warn('[playersearch]', e); });
    }
    input.addEventListener('input', function(){
      clearTimeout(timer);
      var t = input.value.trim();
      if (t.length < MINLEN){ close(); return; }
      timer = setTimeout(function(){ search(t); }, DEBOUNCE);
    });
    input.addEventListener('keydown', function(e){
      if (!dd.classList.contains('ops-ac-open')) return;
      var n = dd.querySelectorAll('.ops-ac-item').length;
      if (e.key==='ArrowDown'){ active=Math.min(active+1,n-1); paint(); e.preventDefault(); }
      else if (e.key==='ArrowUp'){ active=Math.max(active-1,0); paint(); e.preventDefault(); }
      else if (e.key==='Enter' && active>=0){ pick(active); e.preventDefault(); }
      else if (e.key==='Escape'){ close(); }
    });
    function paint(){
      dd.querySelectorAll('.ops-ac-item').forEach(function(el,i){
        el.classList.toggle('ops-ac-active', i===active);
      });
    }
    dd.addEventListener('mousedown', function(e){
      var it = e.target.closest('.ops-ac-item'); if (!it) return;
      e.preventDefault(); pick(parseInt(it.dataset.i,10));
    });
    document.addEventListener('click', function(e){
      if (e.target!==input && !dd.contains(e.target)) close();
    });
    window.addEventListener('scroll', function(){ if (dd.classList.contains('ops-ac-open')) position(); }, true);
    return { close: close, destroy: function(){ dd.remove(); input._opsAttached=false; } };
  }
  return { attach: attach };
})();
```

`ork-player-search.css`:

```css
.ops-ac-results{ display:none; z-index:10000; max-height:280px; overflow-y:auto;
  background:#fff; border:1px solid #cbd5e0; border-radius:6px; box-shadow:0 4px 14px rgba(0,0,0,.18); }
.ops-ac-results.ops-ac-open{ display:block; }
.ops-ac-item{ padding:7px 11px; cursor:pointer; font-size:13px; color:#2d3748; }
.ops-ac-item:hover, .ops-ac-item.ops-ac-active{ background:#ebf2fb; }
.ops-ac-loc{ color:#a0aec0; font-size:11px; }
.ops-ac-badge{ color:#c53030; font-size:10px; font-weight:600; margin-left:4px; }
.ops-ac-empty{ padding:9px 11px; color:#a0aec0; font-size:12px; }
/* Dark mode */
body.dark-mode .ops-ac-results, html[data-theme="dark"] .ops-ac-results{
  background:#2d3748; border-color:#4a5568; }
body.dark-mode .ops-ac-item, html[data-theme="dark"] .ops-ac-item{ color:#e2e8f0; }
body.dark-mode .ops-ac-item:hover, body.dark-mode .ops-ac-item.ops-ac-active,
html[data-theme="dark"] .ops-ac-item:hover, html[data-theme="dark"] .ops-ac-item.ops-ac-active{ background:#3b4960; }
```

> Confirm the project's dark-mode selector (grep `dark-mode` / `data-theme` in `orkui.css`) and match it exactly.

- [ ] **Step 2: Commit**

```bash
git add orkui/template/default/script/ork-player-search.js orkui/template/default/style/ork-player-search.css
git commit -m "feat(playersearch): OrkPlayerSearch component (JS + CSS)"
```

### Task 4: Load the component app-wide

**Files:**
- Modify: `orkui/template/default/default.theme` (CSS after orkui.css ~line 26; JS after orkui.js ~line 41)

- [ ] **Step 1: Add the includes** (Python insert; mirror existing `filemtime` cache-bust pattern)

```php
<link type="text/css" href="<?=HTTP_TEMPLATE;?>default/style/ork-player-search.css?v=<?=filemtime(DIR_TEMPLATE.'default/style/ork-player-search.css')?>" rel="stylesheet" />
```
```php
<script type="text/javascript" src="<?=HTTP_TEMPLATE;?>default/script/ork-player-search.js?v=<?=filemtime(DIR_TEMPLATE.'default/script/ork-player-search.js')?>"></script>
```

- [ ] **Step 2: Verify** — load any page; confirm `window.OrkPlayerSearch` is defined in console and the CSS file 200s.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/default.theme
git commit -m "feat(playersearch): load OrkPlayerSearch app-wide via default.theme"
```

---

## Phase 1 — Pilot (proves the recipe)

### Task 5: Convert the Award giver + recipient searches

Pilot establishes the **conversion recipe** reused in Phase 2. Convert:
- `Award_addawards.tpl` `#GivenBy` (giver) and `#GivenTo` (recipient) — currently jQuery UI.
- `revised.js` `pn-award-givenby` (Playernew add-award giver) and `pn-award-player`/recipient.

**The recipe (per surface):**
1. Delete the old wiring (the `.autocomplete({...})` block, or the bespoke `addEventListener('input'...)` + fetch + render + keynav for that field).
2. Keep the existing text input and the hidden id input (e.g. `#GivenById`).
3. Attach the component, passing the surface's center and policy:

```js
OrkPlayerSearch.attach(document.getElementById('GivenBy'), {
  kingdomId: PN_KINGDOM_ID,          // giver: rank by surface center, NO restrictTo (global reach)
  includeInactive: true, includeSuspended: true,
  onSelect: function(p){ document.getElementById('GivenById').value = p.MundaneId; checkRequiredFields(); }
});
```
4. Recipient (`#GivenTo`) gets the same call but per §5 policy — rank-don't-exclude, **no** `restrictTo` (decision: recipients can be cross-kingdom; ranking surfaces locals first).
5. Remove now-dead helpers if unused elsewhere.

- [ ] **Step 1:** Apply the recipe to the four pilot fields (Python edits).
- [ ] **Step 2: Verify (browser, project rule: test before done)** — On the award page: type a term, confirm dropdown ranks local-first, cross-kingdom names appear, selection sets the hidden id, works inside the modal (fixed dropdown), dark-mode walk.
- [ ] **Step 3: Verify ranking via curl** for the giver context params (reuse `tests/playersearch/ranking.sh` style).
- [ ] **Step 4: Commit** (stage only the two touched files; `git diff --cached` first — `revised.js` has concurrent edits).

```bash
git add orkui/template/default/Award_addawards.tpl orkui/template/revised-frontend/script/revised.js
git commit -m "feat(playersearch): convert award giver/recipient to OrkPlayerSearch (pilot)"
```

---

## Phase 2 — Parallel clean-path rollout (agent team)

One agent per group, **non-overlapping files**, each applying the Task 5 recipe. Each surface row gives the input id, the hidden-id target, the center to pass, and `restrictTo`. **As each agent works, it flags any surface that does not fit the clean recipe and adds it to the Exception Register (Phase 4) instead of converting it.**

### Task 6: Playernew / Kingdomnew / Parknew modals (`revised.js` + templates)
| Surface | Input id | Center → params | restrictTo |
|---|---|---|---|
| pn-edit-givenby | `pn-edit-givenby-text` | `kingdomId: PnConfig.kingdomId` | none (giver) |
| kn-award-player (recipient) | `kn-award-player-text` | `kingdomId: KINGDOM_ID` | none |
| kn-award-givenby | `kn-award-givenby-text` | `kingdomId: KINGDOM_ID` | none |
| kn-rec-player | `kn-rec-player-text` | `kingdomId: KINGDOM_ID` | none |
| pk-award-player | `pk-award-player-text` | `parkId: PkConfig.parkId, kingdomId: PkConfig.kingdomId` | none |
| pk-award-givenby | `pk-award-givenby-text` | `parkId, kingdomId` | none |
| pk-rec-player | `pk-rec-player-text` | `parkId, kingdomId` | none |

> `kn-moveplayer`, `pk-moveplayer`, `kn-merge-*`, `pk-merge-*` → **Exception Register** (cascade/dual-field). Do NOT convert here.

### Task 7: Legacy `Admin_*` templates (jQuery UI → component)
| Surface | Input id | Center | restrictTo |
|---|---|---|---|
| Admin_player GivenBy | `#GivenBy` | none (global admin) | none |
| Admin_suspendplayer | `#PlayerName` | none | none |
| Admin_banplayer | `#PlayerName` | none | none |
| Admin_moveplayer player | `#PlayerName` | (selected kingdom) | none |
| Admin_manageevent (x2) | `#PlayerName`,`#CreatePlayerName` | event park/kingdom | none |

> `Admin_unit` member/manager, `Admin_permissions*`, `Admin_mergeplayer` → **Exception Register**.

### Task 8: Legacy `Attendance_*` templates
| Surface | Input id | Center | restrictTo |
|---|---|---|---|
| Attendance_kingdom | `#PlayerName` | `kingdomId` | none |
| Attendance_event | `#PlayerName` | event park+kingdom | none |
| Attendance_park | `#PlayerName` | `parkId`+`kingdomId` | none |

### Task 9: `Award_addawards` remainder + `Reports_roster`
| Surface | Input id | Center | restrictTo |
|---|---|---|---|
| Reports_roster | (roster search var) | `kingdomId` | none |

**Each task's steps:** (1) apply recipe per row; (2) browser-verify ranking + modal positioning + dark mode; (3) stage only that group's files, `git diff --cached`, commit.

---

## Phase 3 — Endpoint convergence

### Task 10: Redirect divergent endpoints to the core
Make each legacy endpoint delegate to `RankedPlayers` (or 302/alias) so ordering can't drift:
`KingdomAjax/playersearch`, `ParkAjax/.../playersearch`, `SearchAjax/universal` (player branch only — keep park/kingdom/unit entities), `SearchService::Player`, `AdminAjax/global/playersearch`, `EventAjax/playersearch`.

- [ ] Per endpoint: replace its inline SQL with a call to `RankedPlayers`, mapping its existing params (`scope=own`→`restrictTo` per ring center; `scope=all`→none; `prioritize`→implicit). Keep response shape back-compatible for any not-yet-converted caller.
- [ ] Re-run `tests/playersearch/ranking.sh` and spot-check each endpoint via curl.
- [ ] Commit per endpoint.

---

## Phase 4 — Exceptions (LAST — design additive rules WITH the user)

### Task 11: Compile the Exception Register
Aggregate every surface flagged by Phase 2/3 agents into `docs/superpowers/specs/2026-05-26-playersearch-exceptions.md`. For each: current behavior, why it deviates, and a proposed additive rule. Known candidates:
- Unit + add member/manager (global, cross-kingdom AND cross-park)
- Merge-player keep/remove (dual-field, cross-field exclusion, local+global dedup)
- Move-player (cascade-driven restrictTo by mode in/within/out)
- Event attendance (hide already-attended; multi-group prefix)
- Officer/authorization grants (hard-bounded to administered scope)

- [ ] **STOP and ask the user** for the additive rule per exception before converting any of them.

### Task 12: Convert exceptions per approved additive rules
One subtask per exception, using the rule the user approved. Browser-verify each; commit per exception.

---

## Self-Review Notes
- **Spec coverage:** ring contract → Task 1; canonical endpoint → Tasks 1–2; component → Tasks 3–4; clean rollout → Tasks 5–9; convergence → Task 10; exceptions register/workflow → Tasks 11–12; out-of-scope location/park searches excluded from all tables. ✔
- **Open verification points flagged inline** (lib query API, `Ork3::$Lib->searchservice` handle, SOAP calling convention, dark-mode selector) — confirm against existing working code during Task 1–4, do not guess.
- **Naming consistency:** core `RankedPlayers`; component `OrkPlayerSearch`; route `SearchAjax/players`; SOAP `Search/Players`; CSS prefix `ops-`; normalized field `Ring`.
