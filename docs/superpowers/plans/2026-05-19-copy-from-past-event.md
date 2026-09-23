# Copy From Past Event — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Copy from past event" affordance to the New Event modal on Kingdomnew and Parknew. When the user picks a prior in-scope event, the modal grows date/time pickers and a checkbox list of modules to copy (Event Details, Schedule, Staff, Feast, Banner); one POST creates a new event with everything copied and redirects to its detail page.

**Architecture:** Two new AJAX actions on `Controller_EventAjax` — `copy_source_list` (typeahead source) and `create_with_copy` (one-shot create + copy). Modal markup additions in `Kingdomnew_index.tpl` and `Parknew_index.tpl` (mirrored, prefixed `kn-cfe-*` / `pk-cfe-*`). JS wiring lives in `revised.js` as two new IIFE blocks. No new tables, no new migrations. Banned/deactivated mundanes are filtered out of copied staff and schedule leads.

**Tech Stack:** PHP 8 (existing `Controller_EventAjax` patterns), MariaDB (raw `$DB->Execute` / `$DB->DataSet` per project convention), jQuery + vanilla JS in `revised.js`, flatpickr for date pickers, project's `kn-ac-results` typeahead pattern (never jQuery UI autocomplete).

**Reference spec:** `docs/superpowers/specs/2026-05-19-copy-from-past-event-design.md`

**Conventions the implementer MUST honor** (from `MEMORY.md`):
- **PHP edits >1 line:** use Python `pathlib` + `str.replace`, NEVER the Edit tool (tab indentation causes match failures).
- **DB writes:** `$DB->Clear();` before EVERY raw `$DB->Execute()` / `$DB->DataSet()`.
- **Autocomplete:** use the project's `kn-ac-results` dropdown pattern, NEVER jQuery UI autocomplete. Inside modals, call `tnFixedAcPosition(inputEl, dropdownEl)` before EVERY `.classList.add('kn-ac-open')`, in BOTH the "no results" and "results" branches.
- **Debug output:** `console.log()` / `die(json_encode(...))` to the browser, NEVER `error_log` / `print_r`.
- **Date display:** any user-visible datetime input must use flatpickr with `altInput: true` + `altFormat: 'F j, Y  h:i K'` — never expose raw ISO.
- **Tooltips:** use the project's `data-tip` attribute, NEVER native `title=`.
- **Dark mode:** every new modal surface, label, button, input, chip, expander, and segmented control needs a `html[data-theme="dark"]` rule. Walk the modal in dark mode before declaring a task done.
- **Headings inside modals:** if you reuse an `<h1>`–`<h6>` tag, reset the global pill: `background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;`.
- **revised.js IIFE guards:** use a config flag (e.g. `if (typeof KnConfig === 'undefined' || !KnConfig.kingdomId) return;`), NEVER `document.getElementById(...)` — the script loads mid-page and modal markup may not yet be in the DOM.
- **Never stage `system/lib/ork3/class.Authorization.php`** — it has a local-only login bypass. Always `git add` specific files by name, never `git add -A` / `git add .`.
- **PR title convention:** `Enhancement: Copy From Past Event` when finalizing.

There is no automated test suite for this surface — verification is manual through `http://localhost:19080/orkui/`. Each task ends with explicit manual-verify steps and a commit.

---

## File Plan

| File | Action |
|---|---|
| `orkui/controller/controller.EventAjax.php` | **Modify** — append three methods at end of class: `copy_source_list`, `create_with_copy`, and `_isMundaneEligible` (private helper). |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl` | **Modify** — extend the existing `#kn-event-modal` body with copy section markup (lines around 1245) and append matching CSS to the existing `<style>` block. |
| `orkui/template/revised-frontend/Parknew_index.tpl` | **Modify** — extend the existing `#pk-event-modal` body with mirrored markup and CSS. |
| `orkui/template/revised-frontend/script/revised.js` | **Modify** — append two new IIFE blocks (one `knCfe*`, one `pkCfe*`) at the end of the file, after the existing calendar-item code. |

**No new files. No DB migrations. No new dependencies.**

---

## Task 1: Backend — `_isMundaneEligible` helper

**Files:**
- Modify: `orkui/controller/controller.EventAjax.php` (append before the closing `}` of the class)

This helper is reused by tasks 3 and 4 (staff filtering, lead filtering). Write it first so later tasks can call it.

- [ ] **Step 1: Locate the insertion point**

```bash
grep -n "^}" orkui/controller/controller.EventAjax.php | tail -3
```

Expected: a single `}` line at the very end of the file (the class closing brace). Note its line number.

- [ ] **Step 2: Insert the helper using Python**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.EventAjax.php')
t = p.read_text()
needle = "\tprivate function _bustEventSearchCache($event_id) {"
print('needle found:', needle in t)

helper = """
\t// Per-request cache for mundane eligibility — copy passes reference the same
\t// mundane through many schedule leads + staff rows.
\tprivate $_mundaneEligibleCache = [];

\tprivate function _isMundaneEligible($mundane_id) {
\t\t$mid = (int)$mundane_id;
\t\tif ($mid <= 0) return false;
\t\tif (array_key_exists($mid, $this->_mundaneEligibleCache)) return $this->_mundaneEligibleCache[$mid];
\t\tglobal $DB;
\t\t$DB->Clear();
\t\t$row = $DB->DataSet('SELECT active, suspended, suspended_until FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mid . ' LIMIT 1');
\t\t$ok = false;
\t\tif ($row && $row->Next()) {
\t\t\tif ((int)$row->active === 1) {
\t\t\t\tif ((int)$row->suspended !== 1) {
\t\t\t\t\t$ok = true;
\t\t\t\t} else {
\t\t\t\t\t$until = $row->suspended_until;
\t\t\t\t\t// suspended=1 with past suspended_until is no longer effective
\t\t\t\t\tif ($until && strtotime($until) !== false && strtotime($until) < strtotime(date('Y-m-d'))) {
\t\t\t\t\t\t$ok = true;
\t\t\t\t\t}
\t\t\t\t}
\t\t\t}
\t\t}
\t\t$this->_mundaneEligibleCache[$mid] = $ok;
\t\treturn $ok;
\t}

"""
# Insert immediately before _bustEventSearchCache
t = t.replace(needle, helper + needle, 1)
p.write_text(t)
print('inserted')
PY
```

- [ ] **Step 3: Lint**

Run: `php -l orkui/controller/controller.EventAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.EventAjax.php
git commit -m "Enhancement: _isMundaneEligible helper for event copy filtering

Cached per-request lookup of mundane.active + suspended + suspended_until.
Used by the upcoming create_with_copy pipeline to silently drop banned or
deactivated people from copied staff and schedule-lead rows.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Backend — `copy_source_list` endpoint

**Files:**
- Modify: `orkui/controller/controller.EventAjax.php` (append before `_bustEventSearchCache`)

Powers the typeahead dropdown. Scope-strict: kingdom-level sources for kingdom modal, park-level for park modal.

- [ ] **Step 1: Insert the endpoint**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.EventAjax.php')
t = p.read_text()
needle = "\tprivate $_mundaneEligibleCache = [];"
print('needle found:', needle in t)

method = """
\tpublic function copy_source_list($p = null) {
\t\theader('Content-Type: application/json');
\t\tif (!isset($this->session->user_id)) { echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit; }

\t\t$kingdom_id = (int)($_GET['KingdomId'] ?? 0);
\t\t$park_id    = (int)($_GET['ParkId']    ?? 0);
\t\t$query      = trim((string)($_GET['Query'] ?? ''));
\t\t$exclude    = (int)($_GET['ExcludeEventId'] ?? 0);

\t\tif (!valid_id($kingdom_id) && !valid_id($park_id)) {
\t\t\techo json_encode(['status' => 1, 'error' => 'A kingdom or park is required.']); exit;
\t\t}

\t\t$uid = (int)$this->session->user_id;
\t\t// Auth: must be able to create events in this scope. AUTH_EVENT/AUTH_CREATE is the same
\t\t// gate used by the existing create() endpoint above.
\t\tif (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, 0, AUTH_CREATE)) {
\t\t\techo json_encode(['status' => 3, 'error' => 'Not authorized.']); exit;
\t\t}

\t\t// Scope WHERE: park-level takes precedence (matches modal logic — Pk modal always sends ParkId).
\t\t// Cross-scope is explicitly excluded: kingdom-only scope means park_id IS NULL/0; park scope is exact.
\t\tif (valid_id($park_id)) {
\t\t\t$scope_where = 'e.park_id = ' . $park_id;
\t\t} else {
\t\t\t$scope_where = 'e.kingdom_id = ' . $kingdom_id . ' AND (e.park_id IS NULL OR e.park_id = 0)';
\t\t}

\t\t$name_where = '';
\t\tif ($query !== '') {
\t\t\t$safe = str_replace(['\\\\', '%', '_', \"'\"], ['\\\\\\\\', '\\\\%', '\\\\_', \"''\"], $query);
\t\t\t$name_where = \" AND e.name LIKE '%\" . $safe . \"%'\";
\t\t}

\t\t$exclude_where = $exclude > 0 ? ' AND e.event_id != ' . $exclude : '';

\t\tglobal $DB;
\t\t$DB->Clear();
\t\t$sql = 'SELECT e.event_id, e.name,
\t\t\t\t\tMAX(cd.event_start) AS last_start,
\t\t\t\t\tMAX(cd.event_end)   AS last_end,
\t\t\t\t\tCOUNT(cd.event_calendardetail_id) AS occ_count
\t\t\t\tFROM ' . DB_PREFIX . 'event e
\t\t\t\tJOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
\t\t\t\tWHERE ' . $scope_where . $exclude_where . \"
\t\t\t\t  AND e.status = 'published'\" . $name_where . '
\t\t\t\tGROUP BY e.event_id
\t\t\t\tHAVING last_start IS NOT NULL
\t\t\t\tORDER BY last_start DESC
\t\t\t\tLIMIT 25';
\t\t$rs = $DB->DataSet($sql);
\t\t$results = [];
\t\tif ($rs) {
\t\t\twhile ($rs->Next()) {
\t\t\t\t$results[] = [
\t\t\t\t\t'eventId'         => (int)$rs->event_id,
\t\t\t\t\t'name'            => (string)$rs->name,
\t\t\t\t\t'lastStart'       => (string)$rs->last_start,
\t\t\t\t\t'lastEnd'         => (string)$rs->last_end,
\t\t\t\t\t'occurrenceCount' => (int)$rs->occ_count,
\t\t\t\t];
\t\t\t}
\t\t}
\t\techo json_encode(['status' => 0, 'results' => $results]);
\t\texit;
\t}

"""
t = t.replace(needle, method + needle, 1)
p.write_text(t)
print('inserted')
PY
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.EventAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification — curl the endpoint**

```bash
# Get a known kingdom_id with events. Pick one from the DB:
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT e.kingdom_id, COUNT(*) c FROM ork_event e WHERE e.park_id IS NULL OR e.park_id = 0 GROUP BY e.kingdom_id ORDER BY c DESC LIMIT 3;"
```

You can't hit the endpoint unauthenticated. Spot-check by running the SQL by hand against the DB to confirm the query returns sensible rows. Confirm `last_start` is the most recent occurrence, `occ_count` matches, and only `status='published'` events come back.

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.EventAjax.php
git commit -m "Enhancement: copy_source_list AJAX for past-event typeahead

Returns up to 25 in-scope published events with their most recent
occurrence date and total occurrence count, used to power the source
dropdown in the upcoming 'Copy from past event' modal section. Scope is
strict: kingdom modal never sees park-level events and vice versa.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Backend — `create_with_copy` endpoint (scaffold + create event/occurrence)

**Files:**
- Modify: `orkui/controller/controller.EventAjax.php` (append before `_bustEventSearchCache`)

Largest task. Will be split into 4 sub-tasks (3, 4, 5, 6) — this one creates the new event + first occurrence row with details copied; later tasks add fees/links, schedule+feast+leads, staff, banner.

- [ ] **Step 1: Insert the endpoint scaffold + auth + scope check + event/detail creation**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.EventAjax.php')
t = p.read_text()
needle = "\tprivate $_mundaneEligibleCache = [];"
print('needle found:', needle in t)

method = """
\tpublic function create_with_copy($p = null) {
\t\theader('Content-Type: application/json');
\t\tif (!isset($this->session->user_id)) { echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit; }

\t\t$uid         = (int)$this->session->user_id;
\t\t$name        = trim($_POST['Name']          ?? '');
\t\t$kingdom_id  = (int)($_POST['KingdomId']    ?? 0);
\t\t$park_id     = (int)($_POST['ParkId']       ?? 0);
\t\t$src_evt_id  = (int)($_POST['SourceEventId']?? 0);
\t\t$new_start   = trim($_POST['NewStart']      ?? '');
\t\t$new_end     = trim($_POST['NewEnd']        ?? '');
\t\t$modules_raw = trim($_POST['Modules']       ?? '{}');
\t\t$status_in   = (string)($_POST['Status']    ?? 'published');

\t\t$modules = json_decode($modules_raw, true);
\t\tif (!is_array($modules)) $modules = [];
\t\t$mod = [
\t\t\t'details'  => !empty($modules['details']),
\t\t\t'schedule' => !empty($modules['schedule']),
\t\t\t'staff'    => !empty($modules['staff']),
\t\t\t'feast'    => !empty($modules['feast']),
\t\t\t'banner'   => !empty($modules['banner']),
\t\t];

\t\tif (!strlen($name)) { echo json_encode(['status' => 1, 'error' => 'Event name is required.']); exit; }
\t\tif (!valid_id($kingdom_id) && !valid_id($park_id)) { echo json_encode(['status' => 1, 'error' => 'A kingdom or park is required.']); exit; }
\t\tif (!valid_id($src_evt_id)) { echo json_encode(['status' => 1, 'error' => 'A source event is required.']); exit; }
\t\t$ns_ts = strtotime($new_start);
\t\t$ne_ts = strtotime($new_end);
\t\tif (!$ns_ts || !$ne_ts) { echo json_encode(['status' => 1, 'error' => 'Valid start and end times are required.']); exit; }
\t\tif ($ne_ts < $ns_ts)   { echo json_encode(['status' => 1, 'error' => 'End time cannot be before start time.']); exit; }

\t\t// Auth on target scope
\t\tif (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, 0, AUTH_CREATE)) {
\t\t\techo json_encode(['status' => 3, 'error' => 'Not authorized to create events here.']); exit;
\t\t}

\t\t// Scope-validate source: strict, mirrors copy_source_list's WHERE.
\t\tglobal $DB;
\t\t$DB->Clear();
\t\t$srcRow = $DB->DataSet('SELECT event_id, name, kingdom_id, park_id, has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $src_evt_id . ' LIMIT 1');
\t\tif (!$srcRow || !$srcRow->Next()) { echo json_encode(['status' => 1, 'error' => 'Source event not found.']); exit; }
\t\t$src = $srcRow;
\t\tif (valid_id($park_id)) {
\t\t\tif ((int)$src->park_id !== $park_id) { echo json_encode(['status' => 3, 'error' => 'Source event is not available in this scope.']); exit; }
\t\t} else {
\t\t\tif ((int)$src->kingdom_id !== $kingdom_id || ((int)$src->park_id !== 0 && $src->park_id !== null)) {
\t\t\t\techo json_encode(['status' => 3, 'error' => 'Source event is not available in this scope.']); exit;
\t\t\t}
\t\t}

\t\t// Source occurrence — most recent by event_start.
\t\t$DB->Clear();
\t\t$srcDetail = $DB->DataSet('SELECT * FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $src_evt_id . ' ORDER BY event_start DESC LIMIT 1');
\t\tif (!$srcDetail || !$srcDetail->Next()) { echo json_encode(['status' => 1, 'error' => 'Selected event has no occurrence data to copy.']); exit; }
\t\t$sd = $srcDetail;
\t\t$src_detail_id = (int)$sd->event_calendardetail_id;
\t\t$src_start_ts  = strtotime((string)$sd->event_start);
\t\tif (!$src_start_ts) { echo json_encode(['status' => 1, 'error' => 'Source occurrence has an invalid start time.']); exit; }
\t\t$delta_seconds = $ns_ts - $src_start_ts;

\t\t$this->load_model('Event');
\t\t$r = $this->Event->create_event($this->session->token, $kingdom_id, $park_id, 0, 0, $name);
\t\tif ((int)$r['Status'] !== 0) {
\t\t\techo json_encode(['status' => (int)$r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]); exit;
\t\t}
\t\t$new_event_id = (int)($r['Detail'] ?? 0);
\t\tif ($new_event_id <= 0) { echo json_encode(['status' => 1, 'error' => 'Failed to create event row.']); exit; }

\t\t// Optional draft
\t\tif ($status_in === 'draft') {
\t\t\t$DB->Clear();
\t\t\t$DB->Execute('UPDATE ' . DB_PREFIX . \"event SET status = 'draft' WHERE event_id = \" . $new_event_id);
\t\t}

\t\t// From here on, on fatal failure we delete the new event row to avoid orphan.
\t\t// Track new IDs so we can clean up granularly in error paths if needed.
\t\t$rollback_event = function() use ($new_event_id) {
\t\t\tglobal $DB;
\t\t\t$DB->Clear(); $DB->Execute('DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id IN (SELECT s.event_schedule_id FROM ' . DB_PREFIX . 'event_schedule s JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id . ')');
\t\t\t$DB->Clear(); $DB->Execute('DELETE s FROM ' . DB_PREFIX . 'event_schedule s JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id);
\t\t\t$DB->Clear(); $DB->Execute('DELETE st FROM ' . DB_PREFIX . 'event_staff st JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = st.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id);
\t\t\t$DB->Clear(); $DB->Execute('DELETE fe FROM ' . DB_PREFIX . 'event_fees fe JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = fe.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id);
\t\t\t$DB->Clear(); $DB->Execute('DELETE lk FROM ' . DB_PREFIX . 'event_links lk JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = lk.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id);
\t\t\t$DB->Clear(); $DB->Execute('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $new_event_id);
\t\t\t$DB->Clear(); $DB->Execute('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $new_event_id);
\t\t\t// Banner file (best-effort)
\t\t\t$base = DIR_EVENT_BANNER . sprintf('%05d', $new_event_id);
\t\t\tif (file_exists($base . '.jpg')) @unlink($base . '.jpg');
\t\t\tif (file_exists($base . '.png')) @unlink($base . '.png');
\t\t};

\t\t// New occurrence row.
\t\t$new_start_fmt = date('Y-m-d H:i:s', $ns_ts);
\t\t$new_end_fmt   = date('Y-m-d H:i:s', $ne_ts);
\t\t$at_park_sql   = valid_id($park_id) ? (string)$park_id : 'NULL';

\t\t// Details fields — copy if module checked, else use safe defaults.
\t\t$dsc  = $mod['details'] ? (string)$sd->description : '';
\t\t$prc  = $mod['details'] ? (float)$sd->price        : 0;
\t\t$url  = $mod['details'] ? (string)$sd->url         : '';
\t\t$urln = $mod['details'] ? (string)$sd->url_name    : '';
\t\t$adr  = $mod['details'] ? (string)$sd->address     : '';
\t\t$prv  = $mod['details'] ? (string)$sd->province    : '';
\t\t$pst  = $mod['details'] ? (string)$sd->postal_code : '';
\t\t$cty  = $mod['details'] ? (string)$sd->city        : '';
\t\t$cnt  = $mod['details'] ? (string)$sd->country     : '';
\t\t$mur  = $mod['details'] ? (string)$sd->map_url     : '';
\t\t$murn = $mod['details'] ? (string)$sd->map_url_name: '';
\t\t$etp  = $mod['details'] ? (string)$sd->event_type  : '';

\t\t// Re-validate URL scheme for safety even though we're copying internal data — defense in depth.
\t\tforeach (['url' => &$url, 'mur' => &$mur] as $_k => &$_v) {
\t\t\tif ($_v !== '') {
\t\t\t\t$_sc = strtolower((string)parse_url($_v, PHP_URL_SCHEME));
\t\t\t\tif (!in_array($_sc, ['http', 'https', 'mailto'], true)) $_v = '';
\t\t\t}
\t\t}
\t\tunset($_v);

\t\t$sq = function($s) { return str_replace([\"'\", '\\\\\\\\'], [\"''\", '\\\\\\\\\\\\\\\\'], (string)$s); };
\t\t$DB->Clear();
\t\t$DB->Execute(\"UPDATE \" . DB_PREFIX . \"event_calendardetail SET current = 0 WHERE event_id = \" . $new_event_id);
\t\t$DB->Clear();
\t\t$DB->Execute('INSERT INTO ' . DB_PREFIX . \"event_calendardetail
\t\t\t(event_id, at_park_id, current, price, event_start, event_end, description, url, url_name, address, province, postal_code, city, country, map_url, map_url_name, event_type)
\t\t\tVALUES (\" . $new_event_id . ', ' . $at_park_sql . \", 1, \" . (float)$prc . \", '\" . $new_start_fmt . \"', '\" . $new_end_fmt . \"', '\" . $sq($dsc) . \"', '\" . $sq($url) . \"', '\" . $sq($urln) . \"', '\" . $sq($adr) . \"', '\" . $sq($prv) . \"', '\" . $sq($pst) . \"', '\" . $sq($cty) . \"', '\" . $sq($cnt) . \"', '\" . $sq($mur) . \"', '\" . $sq($murn) . \"', '\" . $sq($etp) . \"')\");
\t\t$DB->Clear();
\t\t$ndRow = $DB->DataSet('SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $new_event_id . ' ORDER BY event_calendardetail_id DESC LIMIT 1');
\t\t$new_detail_id = ($ndRow && $ndRow->Next()) ? (int)$ndRow->event_calendardetail_id : 0;
\t\tif ($new_detail_id <= 0) { $rollback_event(); echo json_encode(['status' => 1, 'error' => 'Failed to create event occurrence.']); exit; }

\t\t// TODO(task 4): fees + links
\t\t// TODO(task 5): schedule + feast + leads
\t\t// TODO(task 6): staff
\t\t// TODO(task 6): banner

\t\t$warnings = [];

\t\t$this->_bustEventSearchCache($new_event_id);
\t\techo json_encode([
\t\t\t'status'   => 0,
\t\t\t'eventId'  => $new_event_id,
\t\t\t'detailId' => $new_detail_id,
\t\t\t'url'      => UIR . 'Event/detail/' . $new_event_id . '/' . $new_detail_id,
\t\t\t'warnings' => $warnings,
\t\t]);
\t\texit;
\t}

"""
t = t.replace(needle, method + needle, 1)
p.write_text(t)
print('inserted')
PY
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.EventAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.EventAjax.php
git commit -m "Enhancement: create_with_copy scaffold — event + occurrence row

Validates inputs/scope, creates the parent ork_event row via the existing
Event service, then inserts a new ork_event_calendardetail with new dates
and (optionally) copied details. Fees/links/schedule/staff/banner copying
land in follow-up commits. Includes a rollback closure that cleans up the
new event + cascading rows if a later step fails fatally.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Backend — fees + external links copy

**Files:**
- Modify: `orkui/controller/controller.EventAjax.php` (replace the `// TODO(task 4)` marker)

- [ ] **Step 1: Insert fees + links copy after the `TODO(task 4)` marker**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.EventAjax.php')
t = p.read_text()
needle = "\t\t// TODO(task 4): fees + links"
print('needle found:', needle in t)

block = """\t\tif ($mod['details']) {
\t\t\t// Fees
\t\t\t$DB->Clear();
\t\t\t$feesRs = $DB->DataSet('SELECT admission_type, cost, sort_order FROM ' . DB_PREFIX . 'event_fees WHERE event_calendardetail_id = ' . $src_detail_id . ' ORDER BY sort_order ASC');
\t\t\tif ($feesRs) {
\t\t\t\twhile ($feesRs->Next()) {
\t\t\t\t\t$_at = $sq((string)$feesRs->admission_type);
\t\t\t\t\t$_co = round((float)$feesRs->cost, 2);
\t\t\t\t\t$_so = (int)$feesRs->sort_order;
\t\t\t\t\t$DB->Clear();
\t\t\t\t\t$DB->Execute('INSERT INTO ' . DB_PREFIX . \"event_fees (event_calendardetail_id, admission_type, cost, sort_order) VALUES (\" . $new_detail_id . \", '\" . $_at . \"', \" . $_co . \", \" . $_so . \")\");
\t\t\t\t}
\t\t\t}

\t\t\t// Links — re-validate URL scheme and icon allow-list on insert (matches controller.Event.php hardening).
\t\t\t$allowed_icons = ['fab fa-facebook','fab fa-discord','fas fa-globe','far fa-clipboard','fas fa-link','fas fa-ticket-alt'];
\t\t\t$DB->Clear();
\t\t\t$linksRs = $DB->DataSet('SELECT title, url, icon, sort_order FROM ' . DB_PREFIX . 'event_links WHERE event_calendardetail_id = ' . $src_detail_id . ' ORDER BY sort_order ASC');
\t\t\tif ($linksRs) {
\t\t\t\twhile ($linksRs->Next()) {
\t\t\t\t\t$_lt = $sq((string)$linksRs->title);
\t\t\t\t\t$_lu_raw = trim((string)$linksRs->url);
\t\t\t\t\tif ($_lu_raw !== '') {
\t\t\t\t\t\t$_sc = strtolower((string)parse_url($_lu_raw, PHP_URL_SCHEME));
\t\t\t\t\t\tif (!in_array($_sc, ['http', 'https', 'mailto'], true)) $_lu_raw = '';
\t\t\t\t\t}
\t\t\t\t\t$_lu = $sq($_lu_raw);
\t\t\t\t\t$_ic_raw = trim((string)$linksRs->icon);
\t\t\t\t\tif (!in_array($_ic_raw, $allowed_icons, true)) $_ic_raw = 'fas fa-link';
\t\t\t\t\t$_ic = $sq($_ic_raw);
\t\t\t\t\t$_so = (int)$linksRs->sort_order;
\t\t\t\t\t$DB->Clear();
\t\t\t\t\t$DB->Execute('INSERT INTO ' . DB_PREFIX . \"event_links (event_calendardetail_id, title, url, icon, sort_order) VALUES (\" . $new_detail_id . \", '\" . $_lt . \"', '\" . $_lu . \"', '\" . $_ic . \"', \" . $_so . \")\");
\t\t\t\t}
\t\t\t}
\t\t}

"""
t = t.replace(needle + '\n', block, 1)
p.write_text(t)
print('inserted')
PY
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.EventAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.EventAjax.php
git commit -m "Enhancement: create_with_copy — copy fees + external links

When Details module is checked, both event_fees and event_links rows are
duplicated against the new occurrence. URL scheme and icon allow-list are
re-validated on insert as defense in depth.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Backend — schedule + feast + leads copy

**Files:**
- Modify: `orkui/controller/controller.EventAjax.php` (replace the `// TODO(task 5)` marker)

Both Schedule and Feast checkboxes filter rows in `ork_event_schedule` by category; they share copy logic. Times shift by `$delta_seconds`. Leads are filtered by `_isMundaneEligible`.

- [ ] **Step 1: Insert the schedule + feast block**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.EventAjax.php')
t = p.read_text()
needle = "\t\t// TODO(task 5): schedule + feast + leads"
print('needle found:', needle in t)

block = """\t\tif ($mod['schedule'] || $mod['feast']) {
\t\t\t$DB->Clear();
\t\t\t$schedRs = $DB->DataSet('SELECT * FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = ' . $src_detail_id . ' ORDER BY start_time ASC');
\t\t\t$src_sched_ids = [];
\t\t\tif ($schedRs) {
\t\t\t\twhile ($schedRs->Next()) {
\t\t\t\t\t$cat    = (string)$schedRs->category;
\t\t\t\t\t$secCat = (string)$schedRs->secondary_category;
\t\t\t\t\t$is_feast = ($cat === 'Feast and Food' || $secCat === 'Feast and Food');
\t\t\t\t\t$want = $is_feast ? $mod['feast'] : $mod['schedule'];
\t\t\t\t\tif (!$want) continue;

\t\t\t\t\t$_title    = $sq((string)$schedRs->title);
\t\t\t\t\t$_loc      = $sq((string)$schedRs->location);
\t\t\t\t\t$_desc     = $sq((string)$schedRs->description);
\t\t\t\t\t$_cat      = $sq($cat);
\t\t\t\t\t$_secCat   = $sq($secCat);
\t\t\t\t\t$_st       = strtotime((string)$schedRs->start_time);
\t\t\t\t\t$_et       = strtotime((string)$schedRs->end_time);
\t\t\t\t\tif (!$_st || !$_et) continue;
\t\t\t\t\t$_st_new = date('Y-m-d H:i:s', $_st + $delta_seconds);
\t\t\t\t\t$_et_new = date('Y-m-d H:i:s', $_et + $delta_seconds);

\t\t\t\t\t$_menuV  = $schedRs->menu;
\t\t\t\t\t$_costV  = $schedRs->cost;
\t\t\t\t\t$_dietV  = $schedRs->dietary;
\t\t\t\t\t$_alleV  = $schedRs->allergens;
\t\t\t\t\t$_menu_sql = ($_menuV !== null) ? \"'\" . $sq($_menuV) . \"'\" : 'NULL';
\t\t\t\t\t$_cost_sql = ($_costV !== null && is_numeric($_costV)) ? (string)round((float)$_costV, 2) : 'NULL';
\t\t\t\t\t$_diet_sql = ($_dietV !== null) ? \"'\" . $sq($_dietV) . \"'\" : 'NULL';
\t\t\t\t\t$_alle_sql = ($_alleV !== null) ? \"'\" . $sq($_alleV) . \"'\" : 'NULL';

\t\t\t\t\t$_src_sched_id = (int)$schedRs->event_schedule_id;
\t\t\t\t\t$DB->Clear();
\t\t\t\t\t$DB->Execute('INSERT INTO ' . DB_PREFIX . \"event_schedule
\t\t\t\t\t\t(event_calendardetail_id, title, start_time, end_time, location, description, category, secondary_category, menu, cost, dietary, allergens)
\t\t\t\t\t\tVALUES (\" . $new_detail_id . \", '\" . $_title . \"', '\" . $_st_new . \"', '\" . $_et_new . \"', '\" . $_loc . \"', '\" . $_desc . \"', '\" . $_cat . \"', '\" . $_secCat . \"', \" . $_menu_sql . \", \" . $_cost_sql . \", \" . $_diet_sql . \", \" . $_alle_sql . \")\");
\t\t\t\t\t$DB->Clear();
\t\t\t\t\t$nsRow = $DB->DataSet('SELECT event_schedule_id FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = ' . $new_detail_id . ' ORDER BY event_schedule_id DESC LIMIT 1');
\t\t\t\t\t$_new_sched_id = ($nsRow && $nsRow->Next()) ? (int)$nsRow->event_schedule_id : 0;
\t\t\t\t\tif ($_new_sched_id > 0) {
\t\t\t\t\t\t$src_sched_ids[$_src_sched_id] = $_new_sched_id;
\t\t\t\t\t}
\t\t\t\t}
\t\t\t}

\t\t\t// Copy leads — filter banned/deactivated mundanes silently.
\t\t\tforeach ($src_sched_ids as $src_sid => $new_sid) {
\t\t\t\t$DB->Clear();
\t\t\t\t$leadsRs = $DB->DataSet('SELECT mundane_id FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id = ' . $src_sid);
\t\t\t\tif (!$leadsRs) continue;
\t\t\t\twhile ($leadsRs->Next()) {
\t\t\t\t\t$_mid = (int)$leadsRs->mundane_id;
\t\t\t\t\tif (!$this->_isMundaneEligible($_mid)) continue;
\t\t\t\t\t$DB->Clear();
\t\t\t\t\t$DB->Execute('INSERT IGNORE INTO ' . DB_PREFIX . 'event_schedule_lead (event_schedule_id, mundane_id) VALUES (' . $new_sid . ', ' . $_mid . ')');
\t\t\t\t}
\t\t\t}
\t\t}

"""
t = t.replace(needle + '\n', block, 1)
p.write_text(t)
print('inserted')
PY
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.EventAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.EventAjax.php
git commit -m "Enhancement: create_with_copy — schedule + feast + leads

Schedule and Feast checkboxes both source from event_schedule, split by
category. Schedule items shift by (newStart - sourceStart). Leads are
copied per schedule item, filtering out banned/deactivated mundanes
silently — the schedule item itself is preserved.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Backend — staff + banner copy + endpoint finalize

**Files:**
- Modify: `orkui/controller/controller.EventAjax.php` (replace remaining `TODO(task 6)` markers)

- [ ] **Step 1: Insert staff + banner blocks**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.EventAjax.php')
t = p.read_text()

needle_staff = "\t\t// TODO(task 6): staff"
print('staff needle found:', needle_staff in t)

staff_block = """\t\tif ($mod['staff']) {
\t\t\t$DB->Clear();
\t\t\t$staffRs = $DB->DataSet('SELECT mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $src_detail_id);
\t\t\tif ($staffRs) {
\t\t\t\twhile ($staffRs->Next()) {
\t\t\t\t\t$_mid = (int)$staffRs->mundane_id;
\t\t\t\t\tif (!$this->_isMundaneEligible($_mid)) continue;
\t\t\t\t\t$_role = $sq((string)$staffRs->role_name);
\t\t\t\t\t$_cm   = (int)$staffRs->can_manage     ? 1 : 0;
\t\t\t\t\t$_ca   = (int)$staffRs->can_attendance ? 1 : 0;
\t\t\t\t\t$_cs   = (int)$staffRs->can_schedule   ? 1 : 0;
\t\t\t\t\t$_cf   = (int)$staffRs->can_feast      ? 1 : 0;
\t\t\t\t\t$DB->Clear();
\t\t\t\t\t$DB->Execute('INSERT INTO ' . DB_PREFIX . \"event_staff
\t\t\t\t\t\t(event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
\t\t\t\t\t\tVALUES (\" . $new_detail_id . \", \" . $_mid . \", '\" . $_role . \"', \" . $_cm . \", \" . $_ca . \", \" . $_cs . \", \" . $_cf . \")
\t\t\t\t\t\tON DUPLICATE KEY UPDATE role_name = VALUES(role_name), can_manage = VALUES(can_manage), can_attendance = VALUES(can_attendance), can_schedule = VALUES(can_schedule), can_feast = VALUES(can_feast)\");
\t\t\t\t}
\t\t\t}
\t\t}

"""
t = t.replace(needle_staff + '\n', staff_block, 1)

needle_banner = "\t\t// TODO(task 6): banner"
print('banner needle found:', needle_banner in t)

banner_block = """\t\tif ($mod['banner'] && (int)$src->has_banner === 1) {
\t\t\t$src_base = DIR_EVENT_BANNER . sprintf('%05d', $src_evt_id);
\t\t\t$new_base = DIR_EVENT_BANNER . sprintf('%05d', $new_event_id);
\t\t\t$copied = false;
\t\t\t$ext = null;
\t\t\tif (file_exists($src_base . '.jpg'))      { $ext = 'jpg'; }
\t\t\telseif (file_exists($src_base . '.png'))  { $ext = 'png'; }
\t\t\tif ($ext) {
\t\t\t\tif (!is_dir(DIR_EVENT_BANNER)) { @mkdir(DIR_EVENT_BANNER, 0775, true); }
\t\t\t\tif (@copy($src_base . '.' . $ext, $new_base . '.' . $ext)) {
\t\t\t\t\t$_sl = (int)$src->banner_show_logo ? 1 : 0;
\t\t\t\t\t$_vg = (int)$src->banner_vignette  ? 1 : 0;
\t\t\t\t\t$_ox = max(0, min(100, (int)$src->banner_offset_x));
\t\t\t\t\t$_oy = max(0, min(100, (int)$src->banner_offset_y));
\t\t\t\t\t$DB->Clear();
\t\t\t\t\t$DB->Execute('UPDATE ' . DB_PREFIX . 'event SET has_banner = 1, banner_show_logo = ' . $_sl . ', banner_vignette = ' . $_vg . ', banner_offset_x = ' . $_ox . ', banner_offset_y = ' . $_oy . ' WHERE event_id = ' . $new_event_id);
\t\t\t\t\t$copied = true;
\t\t\t\t}
\t\t\t}
\t\t\tif (!$copied) {
\t\t\t\t$warnings[] = 'Banner could not be copied.';
\t\t\t}
\t\t}

"""
t = t.replace(needle_banner + '\n', banner_block, 1)

p.write_text(t)
print('staff + banner inserted')
PY
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.EventAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.EventAjax.php
git commit -m "Enhancement: create_with_copy — staff + banner copy

Staff rows are copied with all can_* flags; banned/deactivated mundanes
are silently dropped (entire row). Banner image file is copied from
DIR_EVENT_BANNER/{src} to /{new} with the source's framing config; a
failed file copy is non-fatal — surfaces as a warning in the response.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Kingdomnew modal — markup + CSS

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl`

Adds the collapsible "Copy from past event" section inside the existing `#kn-event-modal` body, plus the scoped CSS in the same `<style>` block.

- [ ] **Step 1: Find the insertion point for markup**

```bash
grep -n "kn-emod-hint\|<p class=\"kn-emod-hint kn-emod-event-only\"" orkui/template/revised-frontend/Kingdomnew_index.tpl | head -3
```

Expected: a single line in the Event mode portion of the modal (after the Host Park field, before the calendar-item-only block). Capture the line for context.

- [ ] **Step 2: Insert the copy section markup**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Kingdomnew_index.tpl')
t = p.read_text()

# Anchor: the kingdom-event-only hint paragraph that immediately precedes the calendar-item-only block.
anchor = """\t\t\t<p class=\"kn-emod-hint kn-emod-event-only\" style=\"margin-top:8px\">"""
print('anchor present:', anchor in t)

new_section = """\t\t\t<!-- Copy from past event (collapsible, event-mode only) -->
\t\t\t<div class=\"kn-cfe-wrap kn-emod-event-only\" id=\"kn-cfe-wrap\" style=\"margin-top:14px\">
\t\t\t\t<button type=\"button\" class=\"kn-cfe-toggle\" id=\"kn-cfe-toggle\" onclick=\"knCfeToggleExpander()\" aria-expanded=\"false\">
\t\t\t\t\t<i class=\"fas fa-clone\" style=\"margin-right:6px;color:#2b6cb0\"></i>
\t\t\t\t\tCopy from past event <span style=\"color:#a0aec0;font-weight:400\">(optional)</span>
\t\t\t\t\t<i class=\"fas fa-chevron-down kn-cfe-chev\" id=\"kn-cfe-chev\" style=\"margin-left:auto\"></i>
\t\t\t\t</button>
\t\t\t\t<div class=\"kn-cfe-body\" id=\"kn-cfe-body\" style=\"display:none\">
\t\t\t\t\t<!-- Source picker / chip -->
\t\t\t\t\t<div class=\"kn-cfe-field\" id=\"kn-cfe-picker-wrap\">
\t\t\t\t\t\t<label class=\"kn-emod-label\">Source event <span style=\"color:#a0aec0;font-weight:400;text-transform:none;letter-spacing:0\">(kingdom-level)</span></label>
\t\t\t\t\t\t<div class=\"kn-ac-wrap\">
\t\t\t\t\t\t\t<input type=\"text\" class=\"kn-emod-input\" id=\"kn-cfe-search\" autocomplete=\"off\" placeholder=\"Search past events…\">
\t\t\t\t\t\t\t<div class=\"kn-ac-results\" id=\"kn-cfe-results\"></div>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<input type=\"hidden\" id=\"kn-cfe-source-id\" value=\"\">
\t\t\t\t\t\t<input type=\"hidden\" id=\"kn-cfe-source-start\" value=\"\">
\t\t\t\t\t\t<input type=\"hidden\" id=\"kn-cfe-source-end\" value=\"\">
\t\t\t\t\t</div>
\t\t\t\t\t<div class=\"kn-cfe-chip\" id=\"kn-cfe-chip\" style=\"display:none\">
\t\t\t\t\t\t<i class=\"fas fa-bookmark\" style=\"margin-right:6px;color:#2b6cb0\"></i>
\t\t\t\t\t\t<span id=\"kn-cfe-chip-label\"></span>
\t\t\t\t\t\t<button type=\"button\" class=\"kn-cfe-chip-clear\" onclick=\"knCfeClear()\" aria-label=\"Clear source\">&times;</button>
\t\t\t\t\t</div>

\t\t\t\t\t<!-- Date pickers + module checkboxes shown only when a source is selected -->
\t\t\t\t\t<div class=\"kn-cfe-detail\" id=\"kn-cfe-detail\" style=\"display:none\">
\t\t\t\t\t\t<div class=\"kn-emod-row\" style=\"display:flex;gap:10px;margin-top:12px\">
\t\t\t\t\t\t\t<div class=\"kn-emod-field\" style=\"flex:1\">
\t\t\t\t\t\t\t\t<label class=\"kn-emod-label\">Start <span style=\"color:#e53e3e\">*</span></label>
\t\t\t\t\t\t\t\t<input type=\"text\" class=\"kn-emod-input\" id=\"kn-cfe-start\" autocomplete=\"off\" placeholder=\"Select start…\">
\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t\t<div class=\"kn-emod-field\" style=\"flex:1\">
\t\t\t\t\t\t\t\t<label class=\"kn-emod-label\">End <span style=\"color:#e53e3e\">*</span></label>
\t\t\t\t\t\t\t\t<input type=\"text\" class=\"kn-emod-input\" id=\"kn-cfe-end\" autocomplete=\"off\" placeholder=\"Select end…\">
\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class=\"kn-cfe-modules\" style=\"margin-top:12px\">
\t\t\t\t\t\t\t<div class=\"kn-cfe-mod-title\">What to copy</div>
\t\t\t\t\t\t\t<label class=\"kn-cfe-mod-row kn-cfe-mod-all\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" id=\"kn-cfe-mod-all\" checked onchange=\"knCfeToggleAll(this)\">
\t\t\t\t\t\t\t\t<span><strong>Select all</strong></span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"kn-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"kn-cfe-mod\" id=\"kn-cfe-mod-details\" checked onchange=\"knCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Event Details <span class=\"kn-cfe-mod-hint\">description, address, fees, links</span></span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"kn-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"kn-cfe-mod\" id=\"kn-cfe-mod-schedule\" checked onchange=\"knCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Schedule</span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"kn-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"kn-cfe-mod\" id=\"kn-cfe-mod-staff\" checked onchange=\"knCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Staff <span class=\"kn-cfe-mod-hint\">banned/deactivated people are skipped</span></span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"kn-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"kn-cfe-mod\" id=\"kn-cfe-mod-feast\" checked onchange=\"knCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Feast</span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"kn-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"kn-cfe-mod\" id=\"kn-cfe-mod-banner\" onchange=\"knCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Banner <span class=\"kn-cfe-mod-hint\">image + framing config</span></span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t</div>
\t\t\t\t\t</div>
\t\t\t\t</div>
\t\t\t</div>

"""
t = t.replace(anchor, new_section + anchor, 1)
p.write_text(t)
print('markup inserted')
PY
```

- [ ] **Step 3: Find the CSS insertion point**

```bash
grep -n "kn-emod-overlay\b" orkui/template/revised-frontend/Kingdomnew_index.tpl | head -3
```

Take note of the line range — the existing modal CSS is co-located. We'll append our scoped CSS after the existing `.kn-emod-*` rules but inside the same `<style>` block.

- [ ] **Step 4: Append scoped CSS (light + dark mode)**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Kingdomnew_index.tpl')
t = p.read_text()

# Insert immediately before the closing </style> that wraps the kn-* modal CSS — find the LAST </style> in the file
last_style = t.rfind('</style>')
print('last </style> at offset:', last_style)
assert last_style > 0

css = """
/* ---- Copy from past event (kn-cfe-*) ---- */
.kn-cfe-wrap { border: 1px solid #e2e8f0; border-radius: 6px; background: #f7fafc; overflow: hidden; }
.kn-cfe-toggle { display: flex; align-items: center; width: 100%; padding: 10px 12px; background: transparent; border: 0; cursor: pointer; font-size: 13px; color: #2d3748; text-align: left; }
.kn-cfe-toggle:hover { background: #edf2f7; }
.kn-cfe-chev { transition: transform 0.15s ease; color: #a0aec0; }
.kn-cfe-toggle[aria-expanded=\"true\"] .kn-cfe-chev { transform: rotate(180deg); }
.kn-cfe-body { padding: 12px; border-top: 1px solid #e2e8f0; background: #ffffff; }
.kn-cfe-field { position: relative; }
.kn-cfe-chip { display: inline-flex; align-items: center; padding: 6px 10px; background: #ebf8ff; border: 1px solid #90cdf4; border-radius: 999px; font-size: 13px; color: #2c5282; margin-top: 4px; max-width: 100%; }
.kn-cfe-chip-clear { background: transparent; border: 0; margin-left: 8px; font-size: 18px; line-height: 1; color: #2c5282; cursor: pointer; padding: 0 4px; }
.kn-cfe-chip-clear:hover { color: #1a365d; }
.kn-cfe-modules .kn-cfe-mod-title { font-size: 12px; font-weight: 600; color: #4a5568; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.kn-cfe-mod-row { display: flex; align-items: flex-start; gap: 8px; padding: 6px 0; cursor: pointer; font-size: 13px; color: #2d3748; }
.kn-cfe-mod-row input[type=\"checkbox\"] { margin-top: 2px; }
.kn-cfe-mod-all { border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 4px; }
.kn-cfe-mod-hint { display: block; font-size: 11px; color: #718096; margin-top: 1px; }

/* Search results in autocomplete dropdown */
#kn-cfe-results .kn-ac-row { display: block; padding: 8px 10px; border-bottom: 1px solid #edf2f7; cursor: pointer; }
#kn-cfe-results .kn-ac-row:hover, #kn-cfe-results .kn-ac-row.kn-ac-active { background: #ebf8ff; }
#kn-cfe-results .kn-ac-row:last-child { border-bottom: 0; }
#kn-cfe-results .kn-ac-row-title { font-size: 13px; color: #2d3748; font-weight: 500; }
#kn-cfe-results .kn-ac-row-meta { font-size: 11px; color: #718096; margin-top: 1px; }
#kn-cfe-results .kn-ac-empty { padding: 10px; color: #a0aec0; font-style: italic; font-size: 12px; }

/* Dark mode */
html[data-theme=\"dark\"] .kn-cfe-wrap { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme=\"dark\"] .kn-cfe-toggle { color: var(--ork-text); }
html[data-theme=\"dark\"] .kn-cfe-toggle:hover { background: var(--ork-bg-tertiary); }
html[data-theme=\"dark\"] .kn-cfe-chev { color: var(--ork-text-muted); }
html[data-theme=\"dark\"] .kn-cfe-body { background: var(--ork-card-bg); border-top-color: var(--ork-border); }
html[data-theme=\"dark\"] .kn-cfe-chip { background: #1a365d; border-color: #2c5282; color: #90cdf4; }
html[data-theme=\"dark\"] .kn-cfe-chip-clear { color: #90cdf4; }
html[data-theme=\"dark\"] .kn-cfe-chip-clear:hover { color: #ebf8ff; }
html[data-theme=\"dark\"] .kn-cfe-mod-title { color: var(--ork-text-secondary); }
html[data-theme=\"dark\"] .kn-cfe-mod-row { color: var(--ork-text); }
html[data-theme=\"dark\"] .kn-cfe-mod-hint { color: var(--ork-text-muted); }
html[data-theme=\"dark\"] .kn-cfe-mod-all { border-bottom-color: var(--ork-border); }
html[data-theme=\"dark\"] #kn-cfe-results .kn-ac-row { border-bottom-color: var(--ork-border); }
html[data-theme=\"dark\"] #kn-cfe-results .kn-ac-row:hover, html[data-theme=\"dark\"] #kn-cfe-results .kn-ac-row.kn-ac-active { background: var(--ork-bg-tertiary); }
html[data-theme=\"dark\"] #kn-cfe-results .kn-ac-row-title { color: var(--ork-text); }
html[data-theme=\"dark\"] #kn-cfe-results .kn-ac-row-meta { color: var(--ork-text-muted); }
html[data-theme=\"dark\"] #kn-cfe-results .kn-ac-empty { color: var(--ork-text-muted); }
"""

t = t[:last_style] + css + '\n' + t[last_style:]
p.write_text(t)
print('css appended')
PY
```

- [ ] **Step 5: Lint the template**

Run: `php -l orkui/template/revised-frontend/Kingdomnew_index.tpl`
Expected: `No syntax errors detected`

- [ ] **Step 6: Manual visual check**

Open `http://localhost:19080/orkui/Kingdom/profile/{kingdom_id}` in Chrome. Open the "New Event" modal. Confirm:
- The expander "Copy from past event (optional)" appears below the Host Park field, BEFORE the existing "This event will be assigned to..." hint.
- Expander is closed by default; clicking it expands.
- When expanded, you see the source search input. Date pickers + module checkboxes are still hidden (they'll show once a source is picked — that's wired up in task 9).
- Switch dark mode on (via theme toggle). All borders, text, backgrounds adapt.
- Switch to "Calendar Item" mode — the expander disappears (it has `kn-emod-event-only` class).

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl
git commit -m "Enhancement: Kingdomnew modal — Copy From Past Event markup + CSS

Adds a collapsible 'Copy from past event' section below the Host Park
field, visible only in Event mode. Markup includes a kn-ac-results
typeahead source picker, source-selected chip, start/end date inputs,
and a 5-checkbox module list with select-all master. CSS is scoped to
kn-cfe-* and has full dark-mode coverage.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Parknew modal — markup + CSS

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl`

Mirrors Task 7 with `pk-` prefixes. The scope is the park (not the kingdom) — label text reflects that.

- [ ] **Step 1: Find the insertion point for markup**

```bash
grep -n "pk-emod-hint\|<p class=\"pk-emod-hint pk-emod-event-only\"" orkui/template/revised-frontend/Parknew_index.tpl | head -3
```

Expected: one match in the modal body. Capture it for use as the anchor.

- [ ] **Step 2: Insert the copy section markup**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Parknew_index.tpl')
t = p.read_text()

anchor = """\t\t\t<p class=\"pk-emod-hint pk-emod-event-only\" style=\"margin-top:8px\">"""
print('anchor present:', anchor in t)

new_section = """\t\t\t<!-- Copy from past event (collapsible, event-mode only) -->
\t\t\t<div class=\"pk-cfe-wrap pk-emod-event-only\" id=\"pk-cfe-wrap\" style=\"margin-top:14px\">
\t\t\t\t<button type=\"button\" class=\"pk-cfe-toggle\" id=\"pk-cfe-toggle\" onclick=\"pkCfeToggleExpander()\" aria-expanded=\"false\">
\t\t\t\t\t<i class=\"fas fa-clone\" style=\"margin-right:6px;color:#2b6cb0\"></i>
\t\t\t\t\tCopy from past event <span style=\"color:#a0aec0;font-weight:400\">(optional)</span>
\t\t\t\t\t<i class=\"fas fa-chevron-down pk-cfe-chev\" id=\"pk-cfe-chev\" style=\"margin-left:auto\"></i>
\t\t\t\t</button>
\t\t\t\t<div class=\"pk-cfe-body\" id=\"pk-cfe-body\" style=\"display:none\">
\t\t\t\t\t<div class=\"pk-cfe-field\" id=\"pk-cfe-picker-wrap\">
\t\t\t\t\t\t<label class=\"pk-emod-label\">Source event <span style=\"color:#a0aec0;font-weight:400;text-transform:none;letter-spacing:0\">(park-level)</span></label>
\t\t\t\t\t\t<div class=\"kn-ac-wrap\">
\t\t\t\t\t\t\t<input type=\"text\" class=\"pk-emod-input\" id=\"pk-cfe-search\" autocomplete=\"off\" placeholder=\"Search past events…\">
\t\t\t\t\t\t\t<div class=\"kn-ac-results\" id=\"pk-cfe-results\"></div>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<input type=\"hidden\" id=\"pk-cfe-source-id\" value=\"\">
\t\t\t\t\t\t<input type=\"hidden\" id=\"pk-cfe-source-start\" value=\"\">
\t\t\t\t\t\t<input type=\"hidden\" id=\"pk-cfe-source-end\" value=\"\">
\t\t\t\t\t</div>
\t\t\t\t\t<div class=\"pk-cfe-chip\" id=\"pk-cfe-chip\" style=\"display:none\">
\t\t\t\t\t\t<i class=\"fas fa-bookmark\" style=\"margin-right:6px;color:#2b6cb0\"></i>
\t\t\t\t\t\t<span id=\"pk-cfe-chip-label\"></span>
\t\t\t\t\t\t<button type=\"button\" class=\"pk-cfe-chip-clear\" onclick=\"pkCfeClear()\" aria-label=\"Clear source\">&times;</button>
\t\t\t\t\t</div>
\t\t\t\t\t<div class=\"pk-cfe-detail\" id=\"pk-cfe-detail\" style=\"display:none\">
\t\t\t\t\t\t<div class=\"pk-emod-row\" style=\"display:flex;gap:10px;margin-top:12px\">
\t\t\t\t\t\t\t<div class=\"pk-emod-field\" style=\"flex:1\">
\t\t\t\t\t\t\t\t<label class=\"pk-emod-label\">Start <span style=\"color:#e53e3e\">*</span></label>
\t\t\t\t\t\t\t\t<input type=\"text\" class=\"pk-emod-input\" id=\"pk-cfe-start\" autocomplete=\"off\" placeholder=\"Select start…\">
\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t\t<div class=\"pk-emod-field\" style=\"flex:1\">
\t\t\t\t\t\t\t\t<label class=\"pk-emod-label\">End <span style=\"color:#e53e3e\">*</span></label>
\t\t\t\t\t\t\t\t<input type=\"text\" class=\"pk-emod-input\" id=\"pk-cfe-end\" autocomplete=\"off\" placeholder=\"Select end…\">
\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class=\"pk-cfe-modules\" style=\"margin-top:12px\">
\t\t\t\t\t\t\t<div class=\"pk-cfe-mod-title\">What to copy</div>
\t\t\t\t\t\t\t<label class=\"pk-cfe-mod-row pk-cfe-mod-all\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" id=\"pk-cfe-mod-all\" checked onchange=\"pkCfeToggleAll(this)\">
\t\t\t\t\t\t\t\t<span><strong>Select all</strong></span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"pk-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"pk-cfe-mod\" id=\"pk-cfe-mod-details\" checked onchange=\"pkCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Event Details <span class=\"pk-cfe-mod-hint\">description, address, fees, links</span></span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"pk-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"pk-cfe-mod\" id=\"pk-cfe-mod-schedule\" checked onchange=\"pkCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Schedule</span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"pk-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"pk-cfe-mod\" id=\"pk-cfe-mod-staff\" checked onchange=\"pkCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Staff <span class=\"pk-cfe-mod-hint\">banned/deactivated people are skipped</span></span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"pk-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"pk-cfe-mod\" id=\"pk-cfe-mod-feast\" checked onchange=\"pkCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Feast</span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t\t<label class=\"pk-cfe-mod-row\">
\t\t\t\t\t\t\t\t<input type=\"checkbox\" class=\"pk-cfe-mod\" id=\"pk-cfe-mod-banner\" onchange=\"pkCfeSyncAll()\">
\t\t\t\t\t\t\t\t<span>Banner <span class=\"pk-cfe-mod-hint\">image + framing config</span></span>
\t\t\t\t\t\t\t</label>
\t\t\t\t\t\t</div>
\t\t\t\t\t</div>
\t\t\t\t</div>
\t\t\t</div>

"""
t = t.replace(anchor, new_section + anchor, 1)
p.write_text(t)
print('markup inserted')
PY
```

- [ ] **Step 3: Append scoped CSS — pk- prefixed equivalent of Task 7's CSS**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Parknew_index.tpl')
t = p.read_text()
last_style = t.rfind('</style>')
print('last </style> at offset:', last_style)
assert last_style > 0

css = """
/* ---- Copy from past event (pk-cfe-*) ---- */
.pk-cfe-wrap { border: 1px solid #e2e8f0; border-radius: 6px; background: #f7fafc; overflow: hidden; }
.pk-cfe-toggle { display: flex; align-items: center; width: 100%; padding: 10px 12px; background: transparent; border: 0; cursor: pointer; font-size: 13px; color: #2d3748; text-align: left; }
.pk-cfe-toggle:hover { background: #edf2f7; }
.pk-cfe-chev { transition: transform 0.15s ease; color: #a0aec0; }
.pk-cfe-toggle[aria-expanded=\"true\"] .pk-cfe-chev { transform: rotate(180deg); }
.pk-cfe-body { padding: 12px; border-top: 1px solid #e2e8f0; background: #ffffff; }
.pk-cfe-field { position: relative; }
.pk-cfe-chip { display: inline-flex; align-items: center; padding: 6px 10px; background: #ebf8ff; border: 1px solid #90cdf4; border-radius: 999px; font-size: 13px; color: #2c5282; margin-top: 4px; max-width: 100%; }
.pk-cfe-chip-clear { background: transparent; border: 0; margin-left: 8px; font-size: 18px; line-height: 1; color: #2c5282; cursor: pointer; padding: 0 4px; }
.pk-cfe-chip-clear:hover { color: #1a365d; }
.pk-cfe-modules .pk-cfe-mod-title { font-size: 12px; font-weight: 600; color: #4a5568; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.pk-cfe-mod-row { display: flex; align-items: flex-start; gap: 8px; padding: 6px 0; cursor: pointer; font-size: 13px; color: #2d3748; }
.pk-cfe-mod-row input[type=\"checkbox\"] { margin-top: 2px; }
.pk-cfe-mod-all { border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 4px; }
.pk-cfe-mod-hint { display: block; font-size: 11px; color: #718096; margin-top: 1px; }

#pk-cfe-results .kn-ac-row { display: block; padding: 8px 10px; border-bottom: 1px solid #edf2f7; cursor: pointer; }
#pk-cfe-results .kn-ac-row:hover, #pk-cfe-results .kn-ac-row.kn-ac-active { background: #ebf8ff; }
#pk-cfe-results .kn-ac-row:last-child { border-bottom: 0; }
#pk-cfe-results .kn-ac-row-title { font-size: 13px; color: #2d3748; font-weight: 500; }
#pk-cfe-results .kn-ac-row-meta { font-size: 11px; color: #718096; margin-top: 1px; }
#pk-cfe-results .kn-ac-empty { padding: 10px; color: #a0aec0; font-style: italic; font-size: 12px; }

html[data-theme=\"dark\"] .pk-cfe-wrap { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme=\"dark\"] .pk-cfe-toggle { color: var(--ork-text); }
html[data-theme=\"dark\"] .pk-cfe-toggle:hover { background: var(--ork-bg-tertiary); }
html[data-theme=\"dark\"] .pk-cfe-chev { color: var(--ork-text-muted); }
html[data-theme=\"dark\"] .pk-cfe-body { background: var(--ork-card-bg); border-top-color: var(--ork-border); }
html[data-theme=\"dark\"] .pk-cfe-chip { background: #1a365d; border-color: #2c5282; color: #90cdf4; }
html[data-theme=\"dark\"] .pk-cfe-chip-clear { color: #90cdf4; }
html[data-theme=\"dark\"] .pk-cfe-chip-clear:hover { color: #ebf8ff; }
html[data-theme=\"dark\"] .pk-cfe-mod-title { color: var(--ork-text-secondary); }
html[data-theme=\"dark\"] .pk-cfe-mod-row { color: var(--ork-text); }
html[data-theme=\"dark\"] .pk-cfe-mod-hint { color: var(--ork-text-muted); }
html[data-theme=\"dark\"] .pk-cfe-mod-all { border-bottom-color: var(--ork-border); }
html[data-theme=\"dark\"] #pk-cfe-results .kn-ac-row { border-bottom-color: var(--ork-border); }
html[data-theme=\"dark\"] #pk-cfe-results .kn-ac-row:hover, html[data-theme=\"dark\"] #pk-cfe-results .kn-ac-row.kn-ac-active { background: var(--ork-bg-tertiary); }
html[data-theme=\"dark\"] #pk-cfe-results .kn-ac-row-title { color: var(--ork-text); }
html[data-theme=\"dark\"] #pk-cfe-results .kn-ac-row-meta { color: var(--ork-text-muted); }
html[data-theme=\"dark\"] #pk-cfe-results .kn-ac-empty { color: var(--ork-text-muted); }
"""

t = t[:last_style] + css + '\n' + t[last_style:]
p.write_text(t)
print('css appended')
PY
```

- [ ] **Step 4: Lint**

Run: `php -l orkui/template/revised-frontend/Parknew_index.tpl`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual visual check**

Open `http://localhost:19080/orkui/Park/profile/{park_id}` in Chrome. Open "New Event" modal. Same expectations as Task 7: expander shows below the existing park-assignment hint, opens cleanly, hidden in calendar-item mode, dark mode looks right.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: Parknew modal — Copy From Past Event markup + CSS

Park-modal mirror of Task 7 with pk-cfe-* prefixes. Source scope label
says 'park-level' instead of 'kingdom-level' but the markup and CSS are
otherwise identical, including dark-mode rules.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Kingdomnew — JS wiring in revised.js

**Files:**
- Modify: `orkui/template/revised-frontend/script/revised.js` (append a new IIFE block at the end)

Implements: expander toggle, source typeahead, source pick → chip + reveal detail + name prefill + end pre-fill, clear, module select-all sync, submit override that calls `create_with_copy` instead of `create`.

- [ ] **Step 1: Append the IIFE block to revised.js**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/script/revised.js')
t = p.read_text()

block = """
/* ============================================================================
   Kingdomnew — Copy From Past Event (kn-cfe-*)
   Wires the collapsible section inside #kn-event-modal that lets the host
   pick a prior in-scope event, dates, and modules to copy. On submit it
   bypasses the stub-create + redirect path and instead POSTs everything to
   EventAjax/create_with_copy, landing the user directly on the new event's
   detail page.
   IIFE guarded by KnConfig — never by getElementById (modal markup may not
   yet be in the DOM when this script first loads).
   ============================================================================ */
(function() {
    if (typeof KnConfig === 'undefined' || !KnConfig.kingdomId) return;

    var SRC_URL = KnConfig.uir + 'EventAjax/copy_source_list';
    var GO_URL  = KnConfig.uir + 'EventAjax/create_with_copy';
    var CFE_DEBOUNCE_MS = 200;
    var DELTA_MS_DEFAULT = 0;
    var pickerStart = null;
    var pickerEnd   = null;
    var debounceTimer = null;
    var lastQuery = '';
    var nameAutoFilled = false; // whether we, not the user, last filled the Name field

    function $(id) { return document.getElementById(id); }

    window.knCfeToggleExpander = function() {
        var body = $('kn-cfe-body');
        var btn  = $('kn-cfe-toggle');
        if (!body || !btn) return;
        var open = body.style.display !== 'none';
        body.style.display = open ? 'none' : '';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        if (!open) {
            setTimeout(function() { var s = $('kn-cfe-search'); if (s) s.focus(); }, 50);
        }
    };

    function fmtDate(s) {
        if (!s) return '';
        // s like '2025-04-05 12:30:00' or '2025-04-05'
        var d = new Date(s.replace(' ', 'T'));
        if (isNaN(d.getTime())) return s;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function renderResults(rows) {
        var box = $('kn-cfe-results');
        var input = $('kn-cfe-search');
        if (!box || !input) return;
        box.innerHTML = '';
        if (!rows || rows.length === 0) {
            box.innerHTML = '<div class=\"kn-ac-empty\">No matching past events</div>';
            if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, box);
            box.classList.add('kn-ac-open');
            return;
        }
        rows.forEach(function(r) {
            var row = document.createElement('div');
            row.className = 'kn-ac-row';
            var occ = r.occurrenceCount > 1 ? (' · ' + r.occurrenceCount + ' occurrences') : '';
            row.innerHTML = '<div class=\"kn-ac-row-title\"></div><div class=\"kn-ac-row-meta\"></div>';
            row.querySelector('.kn-ac-row-title').textContent = r.name;
            row.querySelector('.kn-ac-row-meta').textContent  = fmtDate(r.lastStart) + occ;
            row.addEventListener('mousedown', function(e) { e.preventDefault(); knCfePick(r); });
            box.appendChild(row);
        });
        if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, box);
        box.classList.add('kn-ac-open');
    }

    function runSearch(q) {
        var params = 'KingdomId=' + encodeURIComponent(KnConfig.kingdomId) + '&Query=' + encodeURIComponent(q);
        fetch(SRC_URL + '?' + params, { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) {
                if (!d || d.status !== 0) { renderResults([]); return; }
                renderResults(d.results || []);
            })
            .catch(function() { renderResults([]); });
    }

    function onSearchInput() {
        var input = $('kn-cfe-search');
        var q = input ? input.value.trim() : '';
        if (debounceTimer) clearTimeout(debounceTimer);
        if (q === lastQuery) return;
        lastQuery = q;
        debounceTimer = setTimeout(function() { runSearch(q); }, CFE_DEBOUNCE_MS);
    }

    window.knCfePick = function(srcRow) {
        $('kn-cfe-source-id').value    = srcRow.eventId;
        $('kn-cfe-source-start').value = srcRow.lastStart || '';
        $('kn-cfe-source-end').value   = srcRow.lastEnd   || '';
        $('kn-cfe-chip-label').textContent = srcRow.name + ' · ' + fmtDate(srcRow.lastStart);
        $('kn-cfe-chip').style.display = '';
        $('kn-cfe-picker-wrap').style.display = 'none';
        $('kn-cfe-detail').style.display = '';
        $('kn-cfe-results').classList.remove('kn-ac-open');

        // Name prefill (only if user hasn't typed anything)
        var nameEl = $('kn-event-name');
        if (nameEl && nameEl.value.trim() === '') {
            var yr = new Date().getFullYear();
            nameEl.value = srcRow.name + ' ' + yr;
            nameAutoFilled = true;
            if (typeof knUpdateGoBtn === 'function') knUpdateGoBtn();
        }

        // Compute delta from source occurrence and reset pickers.
        var sStart = srcRow.lastStart ? new Date(srcRow.lastStart.replace(' ', 'T')) : null;
        var sEnd   = srcRow.lastEnd   ? new Date(srcRow.lastEnd.replace(' ', 'T'))   : null;
        DELTA_MS_DEFAULT = (sStart && sEnd && !isNaN(sStart) && !isNaN(sEnd)) ? (sEnd.getTime() - sStart.getTime()) : 0;
        initPickers();
    };

    window.knCfeClear = function() {
        $('kn-cfe-source-id').value    = '';
        $('kn-cfe-source-start').value = '';
        $('kn-cfe-source-end').value   = '';
        $('kn-cfe-chip').style.display = 'none';
        $('kn-cfe-picker-wrap').style.display = '';
        $('kn-cfe-detail').style.display = 'none';
        var s = $('kn-cfe-search'); if (s) { s.value = ''; lastQuery = ''; }
        // Note: do NOT undo the name prefill — user may have edited it.
        nameAutoFilled = false;
    };

    function initPickers() {
        if (typeof flatpickr !== 'function') return;
        var startEl = $('kn-cfe-start');
        var endEl   = $('kn-cfe-end');
        if (!startEl || !endEl) return;
        // Destroy prior instances if re-picking.
        if (startEl._flatpickr) startEl._flatpickr.destroy();
        if (endEl._flatpickr)   endEl._flatpickr.destroy();
        var opts = {
            enableTime: true, dateFormat: 'Y-m-d H:i',
            altInput: true,  altFormat: 'F j, Y  h:i K',
            minuteIncrement: 5, time_24hr: false, allowInput: false
        };
        pickerEnd = flatpickr(endEl, opts);
        pickerStart = flatpickr(startEl, Object.assign({}, opts, {
            onChange: function(selDates) {
                if (!selDates[0]) return;
                var d = new Date(selDates[0].getTime() + DELTA_MS_DEFAULT);
                pickerEnd.setDate(d, true);
            }
        }));
    }

    window.knCfeToggleAll = function(masterCb) {
        document.querySelectorAll('.kn-cfe-mod').forEach(function(cb) { cb.checked = masterCb.checked; });
    };
    window.knCfeSyncAll = function() {
        var all = $('kn-cfe-mod-all');
        if (!all) return;
        var boxes = Array.from(document.querySelectorAll('.kn-cfe-mod'));
        var checked = boxes.filter(function(cb) { return cb.checked; }).length;
        all.checked = (checked === boxes.length);
        all.indeterminate = (checked > 0 && checked < boxes.length);
    };

    // Submit override — replaces the default knCreateEvent path WHEN a source is selected.
    var _origKnCreateEvent = window.knCreateEvent;
    window.knCreateEvent = function(statusOverride) {
        var srcId = parseInt(($('kn-cfe-source-id') || {}).value || '0', 10);
        if (!srcId) { if (_origKnCreateEvent) return _origKnCreateEvent(statusOverride); return; }

        var name   = $('kn-event-name').value.trim();
        var parkId = parseInt($('kn-event-park-id').value || '0', 10);
        var start  = $('kn-cfe-start').value;
        var end    = $('kn-cfe-end').value;
        if (!name) { knEvFeedback && knEvFeedback('Event name is required.'); return; }
        if (!start || !end) { knEvFeedback && knEvFeedback('Start and end times are required.'); return; }

        var btn  = $('kn-emod-go-btn');
        var dbtn = $('kn-emod-draft-btn');
        if (btn)  btn.disabled  = true;
        if (dbtn) dbtn.disabled = true;

        var status = (statusOverride === 'draft') ? 'draft' : 'published';
        var modules = {
            details:  $('kn-cfe-mod-details').checked,
            schedule: $('kn-cfe-mod-schedule').checked,
            staff:    $('kn-cfe-mod-staff').checked,
            feast:    $('kn-cfe-mod-feast').checked,
            banner:   $('kn-cfe-mod-banner').checked,
        };

        $.post(GO_URL, {
            Name: name, KingdomId: KnConfig.kingdomId, ParkId: parkId,
            SourceEventId: srcId, NewStart: start, NewEnd: end,
            Modules: JSON.stringify(modules), Status: status
        }, function(r) {
            if (r && r.status === 0) {
                if (r.warnings && r.warnings.length) {
                    // Surface non-fatal warnings via console for the host to see if needed.
                    try { console.log('Copy completed with warnings:', r.warnings); } catch(e) {}
                }
                window.location.href = r.url;
            } else {
                knEvFeedback && knEvFeedback((r && r.error) ? r.error : 'Failed to copy event.');
                if (btn)  btn.disabled  = false;
                if (dbtn) dbtn.disabled = false;
            }
        }, 'json').fail(function() {
            knEvFeedback && knEvFeedback('Request failed. Please try again.');
            if (btn)  btn.disabled  = false;
            if (dbtn) dbtn.disabled = false;
        });
    };

    // Wire input handlers — bind once. Modal markup is present when this IIFE runs
    // because revised.js is loaded AFTER the modal markup in both Kn and Pk templates
    // (banner modal fix already enforced that ordering for the parent profile).
    document.addEventListener('DOMContentLoaded', function() {
        var s = $('kn-cfe-search');
        if (s) {
            s.addEventListener('input', onSearchInput);
            s.addEventListener('focus', function() { if (!lastQuery) runSearch(''); });
            s.addEventListener('blur',  function() { setTimeout(function() { var b = $('kn-cfe-results'); if (b) b.classList.remove('kn-ac-open'); }, 150); });
        }
    });
})();
"""

t = t.rstrip() + '\n' + block + '\n'
p.write_text(t)
print('appended')
PY
```

- [ ] **Step 2: Lint (syntax-only)**

```bash
node -c orkui/template/revised-frontend/script/revised.js 2>&1 | tail -5
```

Expected: no output (success). If `node` is not available locally, do a quick visual scan for unmatched braces.

- [ ] **Step 3: Manual verification**

In Chrome, open a Kingdom profile, open New Event modal:
1. Click the expander → it opens.
2. Type 2-3 letters into the source input → dropdown should populate with past events; entries show name + date.
3. Click an entry → chip appears with "{name} · {date}"; picker section appears; Name field auto-fills with `{srcName} {year}`.
4. Pick a Start datetime → End auto-fills to start + source delta. Override End → user value stays.
5. Toggle "Select all" → all 4 default-on checkboxes flip; Banner stays in sync.
6. Click the chip's ✕ → returns to search input, picker section hides; Name stays as typed.
7. Submit with all checked → request goes to `EventAjax/create_with_copy`, browser redirects to `/Event/detail/{id}/{detail}` showing the copied event.
8. Open browser DevTools console and verify NO ReferenceError or jQuery autocomplete errors.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/script/revised.js
git commit -m "Enhancement: knCfe — Copy From Past Event wiring

IIFE inside revised.js that powers the new Kn copy section: typeahead
source search via copy_source_list, source-pick → chip + reveal +
flatpickr pair with delta-based end pre-fill + Name auto-fill,
select-all master, and a submit override that POSTs to create_with_copy
and redirects to the new event's detail page. IIFE guarded by KnConfig
per the project's revised.js guard rule.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Parknew — JS wiring in revised.js

**Files:**
- Modify: `orkui/template/revised-frontend/script/revised.js` (append a second IIFE block)

Mirrors Task 9 with `pkCfe*` names and reads `PkConfig.parkId` instead of `KnConfig.kingdomId`. The POST sends `ParkId` only (no KingdomId) — the backend's scope-check handles park-scope sources.

- [ ] **Step 1: Append the Pk IIFE**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/script/revised.js')
t = p.read_text()

block = """
/* ============================================================================
   Parknew — Copy From Past Event (pk-cfe-*)
   Park-scope mirror of the Kingdom block above. Sources are scoped to
   PkConfig.parkId; the request omits KingdomId so the backend uses pure
   park scope.
   ============================================================================ */
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.parkId) return;

    var SRC_URL = PkConfig.uir + 'EventAjax/copy_source_list';
    var GO_URL  = PkConfig.uir + 'EventAjax/create_with_copy';
    var CFE_DEBOUNCE_MS = 200;
    var DELTA_MS_DEFAULT = 0;
    var pickerStart = null;
    var pickerEnd   = null;
    var debounceTimer = null;
    var lastQuery = '';

    function $(id) { return document.getElementById(id); }

    window.pkCfeToggleExpander = function() {
        var body = $('pk-cfe-body');
        var btn  = $('pk-cfe-toggle');
        if (!body || !btn) return;
        var open = body.style.display !== 'none';
        body.style.display = open ? 'none' : '';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        if (!open) {
            setTimeout(function() { var s = $('pk-cfe-search'); if (s) s.focus(); }, 50);
        }
    };

    function fmtDate(s) {
        if (!s) return '';
        var d = new Date(s.replace(' ', 'T'));
        if (isNaN(d.getTime())) return s;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function renderResults(rows) {
        var box = $('pk-cfe-results');
        var input = $('pk-cfe-search');
        if (!box || !input) return;
        box.innerHTML = '';
        if (!rows || rows.length === 0) {
            box.innerHTML = '<div class=\"kn-ac-empty\">No matching past events</div>';
            if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, box);
            box.classList.add('kn-ac-open');
            return;
        }
        rows.forEach(function(r) {
            var row = document.createElement('div');
            row.className = 'kn-ac-row';
            var occ = r.occurrenceCount > 1 ? (' · ' + r.occurrenceCount + ' occurrences') : '';
            row.innerHTML = '<div class=\"kn-ac-row-title\"></div><div class=\"kn-ac-row-meta\"></div>';
            row.querySelector('.kn-ac-row-title').textContent = r.name;
            row.querySelector('.kn-ac-row-meta').textContent  = fmtDate(r.lastStart) + occ;
            row.addEventListener('mousedown', function(e) { e.preventDefault(); pkCfePick(r); });
            box.appendChild(row);
        });
        if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, box);
        box.classList.add('kn-ac-open');
    }

    function runSearch(q) {
        var params = 'ParkId=' + encodeURIComponent(PkConfig.parkId) + '&Query=' + encodeURIComponent(q);
        fetch(SRC_URL + '?' + params, { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) {
                if (!d || d.status !== 0) { renderResults([]); return; }
                renderResults(d.results || []);
            })
            .catch(function() { renderResults([]); });
    }

    function onSearchInput() {
        var input = $('pk-cfe-search');
        var q = input ? input.value.trim() : '';
        if (debounceTimer) clearTimeout(debounceTimer);
        if (q === lastQuery) return;
        lastQuery = q;
        debounceTimer = setTimeout(function() { runSearch(q); }, CFE_DEBOUNCE_MS);
    }

    window.pkCfePick = function(srcRow) {
        $('pk-cfe-source-id').value    = srcRow.eventId;
        $('pk-cfe-source-start').value = srcRow.lastStart || '';
        $('pk-cfe-source-end').value   = srcRow.lastEnd   || '';
        $('pk-cfe-chip-label').textContent = srcRow.name + ' · ' + fmtDate(srcRow.lastStart);
        $('pk-cfe-chip').style.display = '';
        $('pk-cfe-picker-wrap').style.display = 'none';
        $('pk-cfe-detail').style.display = '';
        $('pk-cfe-results').classList.remove('kn-ac-open');

        var nameEl = $('pk-event-name');
        if (nameEl && nameEl.value.trim() === '') {
            var yr = new Date().getFullYear();
            nameEl.value = srcRow.name + ' ' + yr;
            if (typeof pkUpdateGoBtn === 'function') pkUpdateGoBtn();
        }

        var sStart = srcRow.lastStart ? new Date(srcRow.lastStart.replace(' ', 'T')) : null;
        var sEnd   = srcRow.lastEnd   ? new Date(srcRow.lastEnd.replace(' ', 'T'))   : null;
        DELTA_MS_DEFAULT = (sStart && sEnd && !isNaN(sStart) && !isNaN(sEnd)) ? (sEnd.getTime() - sStart.getTime()) : 0;
        initPickers();
    };

    window.pkCfeClear = function() {
        $('pk-cfe-source-id').value    = '';
        $('pk-cfe-source-start').value = '';
        $('pk-cfe-source-end').value   = '';
        $('pk-cfe-chip').style.display = 'none';
        $('pk-cfe-picker-wrap').style.display = '';
        $('pk-cfe-detail').style.display = 'none';
        var s = $('pk-cfe-search'); if (s) { s.value = ''; lastQuery = ''; }
    };

    function initPickers() {
        if (typeof flatpickr !== 'function') return;
        var startEl = $('pk-cfe-start');
        var endEl   = $('pk-cfe-end');
        if (!startEl || !endEl) return;
        if (startEl._flatpickr) startEl._flatpickr.destroy();
        if (endEl._flatpickr)   endEl._flatpickr.destroy();
        var opts = {
            enableTime: true, dateFormat: 'Y-m-d H:i',
            altInput: true,  altFormat: 'F j, Y  h:i K',
            minuteIncrement: 5, time_24hr: false, allowInput: false
        };
        pickerEnd = flatpickr(endEl, opts);
        pickerStart = flatpickr(startEl, Object.assign({}, opts, {
            onChange: function(selDates) {
                if (!selDates[0]) return;
                var d = new Date(selDates[0].getTime() + DELTA_MS_DEFAULT);
                pickerEnd.setDate(d, true);
            }
        }));
    }

    window.pkCfeToggleAll = function(masterCb) {
        document.querySelectorAll('.pk-cfe-mod').forEach(function(cb) { cb.checked = masterCb.checked; });
    };
    window.pkCfeSyncAll = function() {
        var all = $('pk-cfe-mod-all');
        if (!all) return;
        var boxes = Array.from(document.querySelectorAll('.pk-cfe-mod'));
        var checked = boxes.filter(function(cb) { return cb.checked; }).length;
        all.checked = (checked === boxes.length);
        all.indeterminate = (checked > 0 && checked < boxes.length);
    };

    var _origPkCreateEvent = window.pkCreateEvent;
    window.pkCreateEvent = function(statusOverride) {
        var srcId = parseInt(($('pk-cfe-source-id') || {}).value || '0', 10);
        if (!srcId) { if (_origPkCreateEvent) return _origPkCreateEvent(statusOverride); return; }

        var name  = $('pk-event-name').value.trim();
        var start = $('pk-cfe-start').value;
        var end   = $('pk-cfe-end').value;
        if (!name) { pkEvFeedback && pkEvFeedback('Event name is required.'); return; }
        if (!start || !end) { pkEvFeedback && pkEvFeedback('Start and end times are required.'); return; }

        var btn  = $('pk-emod-go-btn');
        var dbtn = $('pk-emod-draft-btn');
        if (btn)  btn.disabled  = true;
        if (dbtn) dbtn.disabled = true;

        var status = (statusOverride === 'draft') ? 'draft' : 'published';
        var modules = {
            details:  $('pk-cfe-mod-details').checked,
            schedule: $('pk-cfe-mod-schedule').checked,
            staff:    $('pk-cfe-mod-staff').checked,
            feast:    $('pk-cfe-mod-feast').checked,
            banner:   $('pk-cfe-mod-banner').checked,
        };

        $.post(GO_URL, {
            Name: name, ParkId: PkConfig.parkId,
            SourceEventId: srcId, NewStart: start, NewEnd: end,
            Modules: JSON.stringify(modules), Status: status
        }, function(r) {
            if (r && r.status === 0) {
                if (r.warnings && r.warnings.length) { try { console.log('Copy completed with warnings:', r.warnings); } catch(e) {} }
                window.location.href = r.url;
            } else {
                pkEvFeedback && pkEvFeedback((r && r.error) ? r.error : 'Failed to copy event.');
                if (btn)  btn.disabled  = false;
                if (dbtn) dbtn.disabled = false;
            }
        }, 'json').fail(function() {
            pkEvFeedback && pkEvFeedback('Request failed. Please try again.');
            if (btn)  btn.disabled  = false;
            if (dbtn) dbtn.disabled = false;
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        var s = $('pk-cfe-search');
        if (s) {
            s.addEventListener('input', onSearchInput);
            s.addEventListener('focus', function() { if (!lastQuery) runSearch(''); });
            s.addEventListener('blur',  function() { setTimeout(function() { var b = $('pk-cfe-results'); if (b) b.classList.remove('kn-ac-open'); }, 150); });
        }
    });
})();
"""

t = t.rstrip() + '\n' + block + '\n'
p.write_text(t)
print('appended')
PY
```

- [ ] **Step 2: Lint**

```bash
node -c orkui/template/revised-frontend/script/revised.js 2>&1 | tail -5
```

Expected: no output.

- [ ] **Step 3: Verify `PkConfig.parkId` and `PkConfig.uir` are emitted**

```bash
grep -n "PkConfig\s*=\|PkConfig.parkId\|PkConfig.uir" orkui/template/revised-frontend/Parknew_index.tpl | head -10
```

Expected: a global `PkConfig` object exists with `parkId` and `uir` already populated. If `uir` isn't there, add it. If `PkConfig` doesn't exist, add a small inline script tag that creates it — but verify first; the Kn template pattern always emits `KnConfig`, and `Parknew_index.tpl` should mirror it.

- [ ] **Step 4: Manual verification** — same as Task 9 but on a Park profile page.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/script/revised.js
git commit -m "Enhancement: pkCfe — Park-scope Copy From Past Event wiring

Park-modal mirror of knCfe. POSTs ParkId only (no KingdomId) so the
backend uses pure park scope when validating the source event.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: End-to-end QA + edge cases

**Files:** none (verification only)

This task validates the full feature against the spec's test checklist, plus walks any new dark-mode surfaces.

- [ ] **Step 1: Set up source data**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
SELECT e.event_id, e.name, e.kingdom_id, e.park_id, COUNT(cd.event_calendardetail_id) occ
FROM ork_event e
JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
WHERE e.status = 'published'
GROUP BY e.event_id
HAVING occ > 0
ORDER BY MAX(cd.event_start) DESC LIMIT 10;"
```

Pick one kingdom-level event (park_id null/0) and one park-level event with rich data (staff, schedule, feast items). Note the IDs.

- [ ] **Step 2: Run all 10 spec test cases**

For each, verify per the spec section "Testing":

1. Kn modal, no host park, copy a kingdom event with all 5 modules — verify new event detail page shows everything; schedule times shifted correctly.
2. Pk modal copy with banner — verify banner image present on new event hero.
3. Copy a source whose schedule has a banned lead and a deactivated staff — verify schedule item kept (lead gone), staff row dropped. To produce: `UPDATE ork_mundane SET active=0 WHERE mundane_id=…` for a staff/lead, then run the copy. **REMEMBER to revert the test data after.**
4. POST `create_with_copy` with a SourceEventId from a different kingdom — expect `status:3`. Use curl/dev-tools to forge the request.
5. Copy with only Feast checked — verify only feast-category rows landed in `ork_event_schedule` for the new occurrence.
6. Copy with no modules checked — should still create the new event + occurrence with new dates and copied Name, nothing else.
7. Override pre-filled End — verify user value wins (check `event_end` in DB).
8. Override pre-filled Name — verify user value wins.
9. Dark mode walkthrough of the modal in both Kn and Pk surfaces (expander, dropdown rows, chip, checkboxes, date pickers, hint text).
10. Mid-flight DB failure: hard to simulate cleanly. Skip unless time allows; rely on rollback closure's correctness review.

- [ ] **Step 3: Verify no regression to plain "Create Event" flow**

Without picking a source: clicking Create should still POST to `EventAjax/create` and redirect to `/Event/create/{id}` exactly as before.

- [ ] **Step 4: Verify the existing untracked auth bypass is NOT staged**

```bash
git status --short
```

Expected: `M system/lib/ork3/class.Authorization.php` is unstaged; `?? .claude/` is unstaged. No `class.Authorization.php` change should appear in the commit log (`git log --oneline -15` should only show our new commits + the merge).

- [ ] **Step 5: Final commit (only if QA found something to patch)**

If issues surface, fix them and commit. Otherwise no commit needed.

- [ ] **Step 6: Push**

```bash
git push origin feature/event-planning-expansion
```

---

## Self-Review

**Spec coverage:**
- Modal placement (Kn + Pk, event-mode only): Tasks 7, 8.
- Source typeahead via kn-ac-results pattern with position:fixed: Task 7 markup uses `.kn-ac-wrap`/`.kn-ac-results`, Task 9 JS calls `tnFixedAcPosition` in both branches.
- Scope strictness (kingdom-only vs park-only, no cross-host): Task 2 SQL + Task 3 scope re-check.
- Date pickers with flatpickr altInput format: Tasks 9, 10 use `altInput: true` + `'F j, Y  h:i K'`.
- Module checkboxes (5) with select-all master: Tasks 7, 8 markup; Tasks 9, 10 toggle/sync.
- Name prefill `{srcName} {year}`, user-typed wins: Tasks 9, 10 `nameEl.value.trim() === ''` guard.
- End pre-fill from source delta, user override wins: Tasks 9, 10 `pickerEnd.setDate` on Start change only.
- Single-shot create + copy + redirect: Task 3 endpoint, returns `url`.
- Atomic with rollback: Task 3 `$rollback_event` closure, granular cleanup.
- Banned/deactivated filter for staff and leads: Task 1 `_isMundaneEligible`, used in Tasks 5, 6.
- Schedule + Feast split by category, time-shifted: Task 5.
- Banner copy (file + columns) with non-fatal failure: Task 6.
- Dark mode coverage: Tasks 7, 8 each include full `html[data-theme="dark"]` rule blocks.
- QA test plan execution: Task 11.
- PR title convention: noted in plan header.
- Never stage Authorization.php: Task 11 step 4 + header reminder.
- DB->Clear before every raw write: all backend tasks use it; explicitly called out.
- No new migrations: stated in file plan; consistent with spec.

**Placeholder scan:** None present; every step has runnable code, exact paths, exact commit messages.

**Type/symbol consistency:**
- `_isMundaneEligible` defined Task 1, used Tasks 5 (`$this->_isMundaneEligible($_mid)`) and 6 (same call).
- `create_with_copy` markers `TODO(task 4/5/6)` introduced Task 3, consumed by Tasks 4, 5, 6 (`needle + '\n'` replace pattern preserves them as one-line anchors).
- `kn-cfe-*` and `pk-cfe-*` IDs used in markup match the IDs referenced in JS (`kn-cfe-source-id`, `kn-cfe-mod-details`, etc.).
- `KnConfig.kingdomId` / `KnConfig.uir` used in Task 9 — already standard in `Kingdomnew_index.tpl`. `PkConfig.parkId` / `PkConfig.uir` verified in Task 10 Step 3 before relying on them.
- Backend POST keys (`Name`, `KingdomId`, `ParkId`, `SourceEventId`, `NewStart`, `NewEnd`, `Modules`, `Status`) match exactly between Task 3 endpoint and Tasks 9/10 frontend.

No gaps.
