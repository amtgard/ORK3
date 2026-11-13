# Cross-Kingdom Event Sharing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a kingdom officer mark an externally-owned, published event as "Share with My Kingdom" so it also appears (display-only, badged) in that kingdom's Events tab.

**Architecture:** A new `ork_event_kingdom_share` junction records additional kingdoms an event surfaces in, without changing `ork_event.kingdom_id` ownership. DB logic lives in `class.Event.php` (per project layering); AJAX share/unshare lives in `controller.EventAjax.php`; a toggle control on the event-detail page (`Eventnew_index.tpl`) drives it; and the two Kingdom Events-tab queries OR-in shared events with a "Shared · hosted by {Kingdom}" badge.

**Tech Stack:** PHP 8 / MariaDB, custom MVC (`orkui/`), `yapo`/raw-`$DB` data access, GhettoCache, inline-in-template JS/CSS. No PHPUnit at this layer — verification is curl-authed-session + DB inspection + dark-mode browser walk, per project convention.

---

## Conventions every task must honor (from project memory)

- **`.tpl` files are PLAIN PHP** rendered via `extract()`+`include`. Use `<?php ?>`/`<?= ?>`, never Smarty `{$var}`.
- **Editing PHP/.tpl/.js multi-line blocks: use Python `str.replace`, not the Edit tool** (tab-vs-space byte mismatches). Pattern: `python3 -c "import pathlib; p=pathlib.Path('FILE'); t=p.read_text(); print('found:', 'NEEDLE' in t); p.write_text(t.replace(OLD, NEW, 1))"`.
- **Always `$DB->Clear()` before raw `Execute`/`DataSet`.**
- **Never stage `system/lib/ork3/class.Authorization.php`** (it carries a local login-bypass hack). Stage files explicitly; never `git add -A`/`git add .`. Run `git diff --cached` before each commit.
- **Dark-mode compatible proactively** — any new button/badge/dropdown/modal. Reset global `h1–h6` box styles if a heading is used in a custom surface.
- **No native `confirm()`/`alert()`/`title=` tooltips** — use `tnConfirm()` and `data-tip`.
- **Debug output → browser console / `die(json_encode(...))`**, never `error_log`.
- **Migrations** run via: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/FILE.sql` (MariaDB — `mariadb`, not `mysql`).
- App container is `ork3-php8-app` (`/var/www/ork.amtgard.com`); HTTP 500s show in `docker logs ork3-php8-app`.

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `db-migrations/2026-06-04-add-event-kingdom-share.sql` | Create the junction table | Create |
| `system/lib/ork3/class.Event.php` | `ShareEventToKingdom`, `UnshareEventFromKingdom`, `GetSharedKingdomsForEvent` (DB layer, auth-gated) | Modify |
| `orkui/model/model.Event.php` | Thin pass-throughs `share_event_to_kingdom`, `unshare_event_from_kingdom`, `get_shared_kingdoms_for_event` | Modify |
| `orkui/controller/controller.EventAjax.php` | New `share($p)` action dispatcher (`share`/`unshare` sub-actions), JSON | Modify |
| `orkui/controller/controller.Event.php` | In `detail()`: compute `$this->data['ShareableKingdoms']` | Modify |
| `orkui/template/revised-frontend/Eventnew_index.tpl` | "Share with My Kingdom" toggle/dropdown UI + JS + CSS | Modify |
| `orkui/controller/controller.Kingdom.php` | `profile()` `$evtSql` + `events_more()` `$evtSql`/HasMore: OR-in shared events, select `is_shared` + owning kingdom name | Modify |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl` | Server-render shared badge (event row) + JS `knBuildEventRow` shared badge + badge CSS | Modify |

**Data contract (used across tasks — names are fixed):**
- Table `ork_event_kingdom_share(event_kingdom_share_id, event_id, kingdom_id, shared_by_mundane_id, created)`.
- AJAX route: `EventAjax/share/{eventId}/{subaction}` where subaction ∈ `share|unshare`, POST body `KingdomId`.
- AJAX JSON: success `{status:0, shared:[kingdomId,...]}`; failure `{status:N, error:"..."}`.
- Controller→template key: `$this->data['ShareableKingdoms']` = array of `['KingdomId'=>int,'KingdomName'=>string,'IsShared'=>bool]`.
- Kingdom event-row fields: `IsShared` (bool) and `OwningKingdomName` (string) on each event in `$eventSummary` (server render) and in the `events_more` JSON `Events[]` (`IsShared`, `OwningKingdomName`).

---

## Task 1: Create the junction table migration

**Files:**
- Create: `db-migrations/2026-06-04-add-event-kingdom-share.sql`

- [ ] **Step 1: Write the migration SQL**

Create `db-migrations/2026-06-04-add-event-kingdom-share.sql` with exactly:

```sql
-- Cross-kingdom event sharing: records additional kingdoms an event surfaces in.
-- Ownership stays in ork_event.kingdom_id; this table only adds display visibility
-- on the owning-kingdom-OTHER kingdoms' Events tab.
CREATE TABLE IF NOT EXISTS ork_event_kingdom_share (
  event_kingdom_share_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id               INT NOT NULL,
  kingdom_id             INT NOT NULL,
  shared_by_mundane_id   INT NOT NULL,
  created                DATETIME NOT NULL,
  UNIQUE KEY uq_event_kingdom (event_id, kingdom_id),
  KEY idx_kingdom (kingdom_id),
  KEY idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

- [ ] **Step 2: Apply the migration**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-04-add-event-kingdom-share.sql
```
Expected: no output (success).

- [ ] **Step 3: Verify the table exists with the right shape**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW CREATE TABLE ork_event_kingdom_share\G"
```
Expected: shows the table with `uq_event_kingdom` UNIQUE on `(event_id, kingdom_id)` and the two secondary keys.

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-06-04-add-event-kingdom-share.sql
git diff --cached --stat   # confirm ONLY this file is staged
git commit -m "Add ork_event_kingdom_share table for cross-kingdom event sharing"
```

---

## Task 2: DB-layer methods in `class.Event.php`

**Files:**
- Modify: `system/lib/ork3/class.Event.php` (add 3 public methods; insert before the final closing `}` of the class — locate with the grep in Step 1)

- [ ] **Step 1: Locate the insertion point and confirm helpers**

Run:
```bash
grep -n "public function SetEvent\|IsAuthorized\b\|AUTH_KINGDOM" system/lib/ork3/class.Event.php | head
tail -n 8 system/lib/ork3/class.Event.php
```
Expected: confirms `SetEvent` exists (insert the new methods right after it) and that the file ends with the class `}` then possibly `?>`. Note the exact line of `public function SetEvent` to insert after its closing brace. `Ork3::$Lib->authorization->IsAuthorized($token)` and `HasAuthority($uid, AUTH_KINGDOM, $id, AUTH_EDIT)` are the auth primitives.

- [ ] **Step 2: Add the three methods**

Insert this block immediately after the `SetEvent()` method's closing brace (use Python to append before the final class `}` — see Step 3 for the exact mechanism). The code:

```php
	// ---- Cross-kingdom event sharing -------------------------------------
	// Sharing is a kingdom prerogative: requires AUTH_KINGDOM/edit over the
	// TARGET kingdom (the one being shared INTO), not the event's owner. Park
	// officers cannot share. Only published events are shareable, never the
	// event's own owning kingdom (no-op). Display-only — never affects
	// ownership, attendance, or reporting.

	public function ShareEventToKingdom($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
		$event_id   = (int)($request['EventId'] ?? 0);
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		if ($mundane_id <= 0) return NoAuthorization();
		if (!valid_id($event_id) || !valid_id($kingdom_id)) return InvalidParameter();

		// Load the event: must exist, be published, and not already own this kingdom.
		global $DB;
		$DB->Clear();
		$row = $DB->DataSet("SELECT kingdom_id, COALESCE(status,'published') AS status FROM " . DB_PREFIX . "event WHERE event_id = " . $event_id . " LIMIT 1");
		if (!$row || !$row->Next()) return InvalidParameter('Event not found.');
		$owning_kingdom = (int)$row->kingdom_id;
		$status         = (string)$row->status;
		if ($status !== 'published') return InvalidParameter('Only published events can be shared.');
		if ($owning_kingdom === $kingdom_id) return InvalidParameter('Event already belongs to this kingdom.');

		// Kingdom prerogative: AUTH_KINGDOM/edit over the TARGET kingdom.
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT))
			return NoAuthorization();

		// Idempotent insert.
		$DB->Clear();
		$DB->Execute("INSERT IGNORE INTO " . DB_PREFIX . "event_kingdom_share (event_id, kingdom_id, shared_by_mundane_id, created) VALUES (" . $event_id . ", " . $kingdom_id . ", " . (int)$mundane_id . ", NOW())");
		return Success();
	}

	public function UnshareEventFromKingdom($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
		$event_id   = (int)($request['EventId'] ?? 0);
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		if ($mundane_id <= 0) return NoAuthorization();
		if (!valid_id($event_id) || !valid_id($kingdom_id)) return InvalidParameter();
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT))
			return NoAuthorization();
		global $DB;
		$DB->Clear();
		$DB->Execute("DELETE FROM " . DB_PREFIX . "event_kingdom_share WHERE event_id = " . $event_id . " AND kingdom_id = " . $kingdom_id);
		return Success();
	}

	public function GetSharedKingdomsForEvent($request) {
		$event_id = (int)($request['EventId'] ?? 0);
		if (!valid_id($event_id)) return ['Status' => InvalidParameter(), 'KingdomIds' => []];
		global $DB;
		$DB->Clear();
		$rs = $DB->DataSet("SELECT kingdom_id FROM " . DB_PREFIX . "event_kingdom_share WHERE event_id = " . $event_id);
		$ids = [];
		if ($rs) { while ($rs->Next()) { $ids[] = (int)$rs->kingdom_id; } }
		return ['Status' => Success(), 'KingdomIds' => $ids];
	}
```

- [ ] **Step 3: Apply the edit with Python (append before final class brace)**

Save the block above to a temp file and splice it. Run:
```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Event.php')
t = p.read_text()
anchor = "\tpublic function GetSharedKingdomsForEvent"
print('already present:', anchor in t)
PY
```
Expected: `already present: False`. Then perform the insertion by reading the file, finding the LAST `}` that closes the class (the final non-`?>` `}`), and inserting the block before it. Use this Python (paste the method block into the `BLOCK` heredoc exactly as in Step 2):

```bash
python3 - <<'PY'
import pathlib, re
p = pathlib.Path('system/lib/ork3/class.Event.php')
t = p.read_text()
BLOCK = r'''<PASTE THE ENTIRE STEP 2 CODE BLOCK HERE, including the leading tab/comment>'''
# Insert before the final closing brace of the class.
idx = t.rstrip().rfind('}')
assert idx != -1, "no closing brace found"
new = t[:idx] + BLOCK + "\n" + t[idx:]
p.write_text(new)
print('inserted:', 'GetSharedKingdomsForEvent' in p.read_text())
PY
```
Expected: `inserted: True`.

- [ ] **Step 4: Lint the file**

Run:
```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/ork3/class.Event.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Event.php
git diff --cached --stat   # confirm ONLY class.Event.php staged (NOT class.Authorization.php)
git commit -m "Event: ShareEventToKingdom/UnshareEventFromKingdom/GetSharedKingdomsForEvent (DB layer)"
```

---

## Task 3: Model pass-throughs in `model.Event.php`

**Files:**
- Modify: `orkui/model/model.Event.php` (add 3 thin methods; `$this->Event` is `new APIModel('Event')` from the constructor and forwards to `class.Event.php`)

- [ ] **Step 1: Confirm the APIModel forwarder exists**

Run:
```bash
grep -n "\$this->Event = new APIModel('Event')\|function create_event" orkui/model/model.Event.php
```
Expected: both lines present (constructor sets `$this->Event`; `create_event` shows the pass-through style).

- [ ] **Step 2: Add pass-throughs**

Insert after the `create_event()` method (use Python `str.replace` anchored on the end of `create_event`). The methods:

```php
	function share_event_to_kingdom($token, $event_id, $kingdom_id) {
		return $this->Event->ShareEventToKingdom(array('Token'=>$token, 'EventId'=>$event_id, 'KingdomId'=>$kingdom_id));
	}

	function unshare_event_from_kingdom($token, $event_id, $kingdom_id) {
		return $this->Event->UnshareEventFromKingdom(array('Token'=>$token, 'EventId'=>$event_id, 'KingdomId'=>$kingdom_id));
	}

	function get_shared_kingdoms_for_event($event_id) {
		$r = $this->Event->GetSharedKingdomsForEvent(array('EventId'=>$event_id));
		return $r['KingdomIds'] ?? array();
	}
```

Apply with Python:
```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/model/model.Event.php')
t = p.read_text()
needle = "\t\t$r = $this->Event->CreateEvent($request);\n\t\treturn $r;\n\t}"
print('found anchor:', needle in t)
block = needle + '''

	function share_event_to_kingdom($token, $event_id, $kingdom_id) {
		return $this->Event->ShareEventToKingdom(array('Token'=>$token, 'EventId'=>$event_id, 'KingdomId'=>$kingdom_id));
	}

	function unshare_event_from_kingdom($token, $event_id, $kingdom_id) {
		return $this->Event->UnshareEventFromKingdom(array('Token'=>$token, 'EventId'=>$event_id, 'KingdomId'=>$kingdom_id));
	}

	function get_shared_kingdoms_for_event($event_id) {
		$r = $this->Event->GetSharedKingdomsForEvent(array('EventId'=>$event_id));
		return $r['KingdomIds'] ?? array();
	}'''
p.write_text(t.replace(needle, block, 1))
print('done:', 'share_event_to_kingdom' in p.read_text())
PY
```
Expected: `found anchor: True` then `done: True`. (If `found anchor: False`, re-grep `create_event` in Step 1 and adjust `needle` to the exact tail of that method before retrying.)

- [ ] **Step 3: Lint**

```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/model/model.Event.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add orkui/model/model.Event.php
git diff --cached --stat
git commit -m "model.Event: pass-throughs for event-kingdom share/unshare"
```

---

## Task 4: AJAX `share` action in `controller.EventAjax.php`

**Files:**
- Modify: `orkui/controller/controller.EventAjax.php` (add a `share($p = null)` method following the `auth()` dispatch pattern at lines ~385–503)

- [ ] **Step 1: Re-read the `auth()` pattern for conventions**

Run:
```bash
grep -n "public function auth\|\$this->session->user_id\|\$this->session->token\|load_model('Event')" orkui/controller/controller.EventAjax.php | head
```
Expected: confirms `$this->session->user_id`, `$this->session->token`, and `$this->load_model('Event')` are the available primitives.

- [ ] **Step 2: Add the `share()` method**

Append this method to the class (insert before the final class `}` using the same Python "insert before last brace" mechanism as Task 2 Step 3, target file `orkui/controller/controller.EventAjax.php`). Code:

```php
	// Cross-kingdom event sharing. Route: EventAjax/share/{eventId}/{share|unshare}
	// POST body: KingdomId. Auth is enforced in the DB layer (AUTH_KINGDOM over the
	// target kingdom); this method is a thin JSON wrapper.
	public function share($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit;
		}
		$params     = explode('/', $p ?? '');
		$event_id   = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$action     = $params[1] ?? '';
		$kingdom_id = (int)($_POST['KingdomId'] ?? 0);
		if (!valid_id($event_id) || !valid_id($kingdom_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid parameters.']); exit;
		}
		$this->load_model('Event');
		$token = $this->session->token;
		if ($action === 'share') {
			$r = $this->Event->share_event_to_kingdom($token, $event_id, $kingdom_id);
		} elseif ($action === 'unshare') {
			$r = $this->Event->unshare_event_from_kingdom($token, $event_id, $kingdom_id);
		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']); exit;
		}
		$ok = (isset($r['Status']) && (int)$r['Status'] === 0);
		if ($ok) {
			$shared = $this->Event->get_shared_kingdoms_for_event($event_id);
			echo json_encode(['status' => 0, 'shared' => array_values($shared)]);
		} else {
			echo json_encode(['status' => $r['Status'] ?? 1, 'error' => ($r['Error'] ?? 'Error') . (isset($r['Detail']) ? ': ' . $r['Detail'] : '')]);
		}
		exit;
	}
```

Note: `Success()` returns a status structure where the success code is `0`; `$r['Status']` is that integer (see how `removeauth` reads `$r['Status'] == 0`). If runtime shows `$r['Status']` is itself an array, adjust the `$ok` check to `(int)($r['Status']['Status'] ?? 1) === 0` — verify in Step 4 and fix if needed.

- [ ] **Step 3: Lint**

```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.EventAjax.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Integration test (curl-authed session)**

Per project pattern: single cookie jar, login + all calls in one block (single-device session). Pick a real test event owned by kingdom A and a kingdom B the test officer has AUTH_KINGDOM/edit over (find candidates first):

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT a.mundane_id, a.kingdom_id, m.username FROM ork_authorization a JOIN ork_mundane m ON m.mundane_id=a.mundane_id WHERE a.kingdom_id>0 AND a.role IN ('create','edit','admin') LIMIT 5;"
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT event_id, kingdom_id, name FROM ork_event WHERE COALESCE(status,'published')='published' AND kingdom_id>0 ORDER BY event_id DESC LIMIT 5;"
```

Then (replace `USER`, `EVENT_ID` owned by a DIFFERENT kingdom, `KINGDOM_ID` the user controls):
```bash
J=/tmp/ckes.cookies; rm -f $J
curl -s -c $J -b $J -X POST 'http://localhost:19080/orkui/index.php?Route=Login/login' \
  --data-urlencode 'username=USER' --data-urlencode 'password=x' -o /dev/null
curl -s -c $J -b $J -X POST 'http://localhost:19080/orkui/index.php?Route=EventAjax/share/EVENT_ID/share' \
  --data 'KingdomId=KINGDOM_ID'
echo
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
  "SELECT * FROM ork_event_kingdom_share WHERE event_id=EVENT_ID AND kingdom_id=KINGDOM_ID;"
```
Expected: JSON `{"status":0,"shared":[...KINGDOM_ID...]}` and one row in the table. If 500, check `docker logs ork3-php8-app`.

- [ ] **Step 5: Test negative paths**

```bash
# self-share (sharing into the event's OWN kingdom) must be rejected:
curl -s -c $J -b $J -X POST 'http://localhost:19080/orkui/index.php?Route=EventAjax/share/EVENT_ID/share' \
  --data 'KingdomId=OWNING_KINGDOM_ID'; echo
# unshare removes the row:
curl -s -c $J -b $J -X POST 'http://localhost:19080/orkui/index.php?Route=EventAjax/share/EVENT_ID/unshare' \
  --data 'KingdomId=KINGDOM_ID'; echo
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
  "SELECT COUNT(*) AS cnt FROM ork_event_kingdom_share WHERE event_id=EVENT_ID AND kingdom_id=KINGDOM_ID;"
```
Expected: self-share returns a non-zero `status` with an error; unshare returns `{"status":0,"shared":[]}`; final count `0`.

- [ ] **Step 6: Commit**

```bash
git add orkui/controller/controller.EventAjax.php
git diff --cached --stat
git commit -m "EventAjax: share/unshare action for cross-kingdom event sharing"
```

---

## Task 5: Compute `ShareableKingdoms` in `controller.Event.php::detail()`

**Files:**
- Modify: `orkui/controller/controller.Event.php` (inside `detail()`, after `$_evtStatus`/`$_evtCreator` are known — around line 642)

- [ ] **Step 1: Confirm the injection anchor**

Run:
```bash
grep -n "\$this->data\['EventStatus'\]\s*=\s*\$_evtStatus;" orkui/controller/controller.Event.php
```
Expected: one match (~line 643) inside `detail()`. Insert the new block immediately AFTER that line. Owning kingdom id is `$info['KingdomId']`; signed-in user is `$uid`.

- [ ] **Step 2: Add the ShareableKingdoms computation**

The block enumerates kingdoms the viewer holds AUTH_KINGDOM/(edit|create|admin) over via `ork_authorization`, excludes the event's owning kingdom, joins kingdom names, and flags currently-shared ones. Only published events expose the control.

```php
		// Cross-kingdom sharing control: kingdoms the viewer can share this event
		// INTO. A kingdom prerogative — derived from AUTH_KINGDOM grants in
		// ork_authorization (park grants do NOT qualify). Excludes the owning
		// kingdom; only offered for published events.
		$this->data['ShareableKingdoms'] = [];
		$_owningKingdom = (int)($info['KingdomId'] ?? 0);
		if ($uid > 0 && $_evtStatus === 'published') {
			global $DB;
			$DB->Clear();
			$_shareRows = $DB->DataSet(
				"SELECT DISTINCT k.kingdom_id, k.name AS kingdom_name
				 FROM " . DB_PREFIX . "authorization a
				 JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = a.kingdom_id
				 WHERE a.mundane_id = " . (int)$uid . "
				   AND a.kingdom_id > 0
				   AND a.kingdom_id <> " . $_owningKingdom . "
				   AND a.role IN ('create','edit','admin')
				 ORDER BY k.name"
			);
			$_alreadyShared = [];
			$DB->Clear();
			$_sh = $DB->DataSet("SELECT kingdom_id FROM " . DB_PREFIX . "event_kingdom_share WHERE event_id = " . (int)$event_id);
			if ($_sh) { while ($_sh->Next()) { $_alreadyShared[(int)$_sh->kingdom_id] = true; } }
			if ($_shareRows) {
				while ($_shareRows->Next()) {
					$_kid = (int)$_shareRows->kingdom_id;
					$this->data['ShareableKingdoms'][] = [
						'KingdomId'   => $_kid,
						'KingdomName' => (string)$_shareRows->kingdom_name,
						'IsShared'    => isset($_alreadyShared[$_kid]),
					];
				}
			}
		}
```

Apply with Python anchored on the `EventStatus` line:
```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Event.php')
t = p.read_text()
anchor = "\t\t$this->data['EventStatus']        = $_evtStatus;"
print('found:', anchor in t)
# (insert the block above immediately after `anchor` using t.replace(anchor, anchor + BLOCK, 1))
PY
```
Then perform the replace (`t.replace(anchor, anchor + "\n" + BLOCK, 1)` with `BLOCK` set to the PHP above). Expected: `found: True`.

- [ ] **Step 3: Lint**

```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.Event.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verify data reaches the template**

Temporarily, confirm via the page that the var is populated for the test officer. Load the event detail page in the curl session and grep for a debug marker, OR simpler — add a one-off `die(json_encode($this->data['ShareableKingdoms']))` right after the block, hit the page, confirm JSON, then REMOVE the die:
```bash
curl -s -c $J -b $J 'http://localhost:19080/orkui/index.php?Route=Event/detail/EVENT_ID/DETAIL_ID' | head -c 400
```
Expected (with temporary die): a JSON array including the officer's controllable kingdom(s), each with `IsShared`. Remove the die after confirming.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.Event.php
git diff --cached --stat
git commit -m "Event/detail: expose ShareableKingdoms for the share control"
```

---

## Task 6: "Share with My Kingdom" UI in `Eventnew_index.tpl`

**Files:**
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (add the control near the event hero/action area; add CSS in the page's `<style>`; add JS near the other inline handlers)

- [ ] **Step 1: Find a placement anchor in the hero/action region**

Run:
```bash
grep -n "CanManageEvent\|kn-copy-link\|ev-hero\|class=\"ev-\|RSVP\|rsvp-btn\|page-actions\|ev-actions" orkui/template/revised-frontend/Eventnew_index.tpl | head -30
```
Expected: identifies the hero/action container (e.g. an `ev-actions`/hero block). Choose a visible spot in the event header action row. Record the exact surrounding markup string to anchor the Python insert.

- [ ] **Step 2: Add the control markup**

Insert this PHP/HTML at the chosen anchor. It renders nothing for users with no shareable kingdoms; a single button when there's exactly one; a dropdown when there are several. `$event_id` is in scope (`$this->data['event_id']`; in-template it's `$event_id`). Use `$ShareableKingdoms`.

```php
				<?php if (!empty($ShareableKingdoms)): ?>
					<?php if (count($ShareableKingdoms) === 1): $sk = $ShareableKingdoms[0]; ?>
						<button type="button"
							class="ev-share-btn<?= $sk['IsShared'] ? ' is-shared' : '' ?>"
							id="ev-share-single"
							data-event="<?= (int)$event_id ?>"
							data-kingdom="<?= (int)$sk['KingdomId'] ?>"
							data-shared="<?= $sk['IsShared'] ? '1' : '0' ?>"
							data-tip="Show this event on <?= htmlspecialchars($sk['KingdomName']) ?>'s events list"
							onclick="evToggleShare(this)">
							<i class="fas fa-share-nodes"></i>
							<span class="ev-share-label"><?= $sk['IsShared'] ? 'Shared with ' . htmlspecialchars($sk['KingdomName']) : 'Share with My Kingdom' ?></span>
						</button>
					<?php else: ?>
						<div class="ev-share-wrap">
							<button type="button" class="ev-share-btn" onclick="evToggleShareMenu(this)">
								<i class="fas fa-share-nodes"></i> <span class="ev-share-label">Share with my kingdom(s)</span>
								<i class="fas fa-caret-down" style="margin-left:6px"></i>
							</button>
							<div class="ev-share-menu" id="ev-share-menu" hidden>
								<?php foreach ($ShareableKingdoms as $sk): ?>
									<label class="ev-share-row">
										<input type="checkbox"
											class="ev-share-check"
											data-event="<?= (int)$event_id ?>"
											data-kingdom="<?= (int)$sk['KingdomId'] ?>"
											<?= $sk['IsShared'] ? 'checked' : '' ?>
											onchange="evToggleShare(this)">
										<span><?= htmlspecialchars($sk['KingdomName']) ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				<?php endif; ?>
```

- [ ] **Step 3: Add CSS (dark-mode compatible)**

Insert into the page `<style>` block (find with `grep -n "<style" orkui/template/revised-frontend/Eventnew_index.tpl`):

```css
.ev-share-btn { display:inline-flex; align-items:center; gap:7px; padding:7px 13px; border-radius:7px;
	border:1px solid #c9ced6; background:#f3f5f8; color:#2d3748; font-size:13px; font-weight:600;
	cursor:pointer; line-height:1; transition:background .15s,border-color .15s; }
.ev-share-btn:hover { background:#e8ecf1; border-color:#aab2bd; }
.ev-share-btn.is-shared { background:#e6f4ea; border-color:#9bd3ad; color:#1f6b3a; }
.ev-share-wrap { position:relative; display:inline-block; }
.ev-share-menu { position:absolute; z-index:50; top:calc(100% + 6px); left:0; min-width:220px;
	background:#fff; border:1px solid #d4d9e0; border-radius:8px; box-shadow:0 6px 22px rgba(0,0,0,.14);
	padding:6px; }
.ev-share-row { display:flex; align-items:center; gap:9px; padding:8px 9px; border-radius:6px;
	font-size:13px; color:#2d3748; cursor:pointer; }
.ev-share-row:hover { background:#f1f4f8; }
.ev-share-row input { width:15px; height:15px; cursor:pointer; }
html[data-theme="dark"] .ev-share-btn { background:#2a2f37; border-color:#444b56; color:#e3e7ee; }
html[data-theme="dark"] .ev-share-btn:hover { background:#333a44; border-color:#566070; }
html[data-theme="dark"] .ev-share-btn.is-shared { background:#1f3a29; border-color:#2f6b45; color:#9be3b4; }
html[data-theme="dark"] .ev-share-menu { background:#23272e; border-color:#3a414b; box-shadow:0 6px 22px rgba(0,0,0,.5); }
html[data-theme="dark"] .ev-share-row { color:#e3e7ee; }
html[data-theme="dark"] .ev-share-row:hover { background:#2d333c; }
```

- [ ] **Step 4: Add JS handlers**

Insert near the other inline `<script>` handlers (before the closing `</script>` of the main inline block — find with `grep -n "function evToggle\|</script>" orkui/template/revised-frontend/Eventnew_index.tpl | head`). `UIR` is emitted server-side; capture it into JS via an existing pattern on the page (grep `var uir`/`UIR` usage) or inline `'<?= UIR ?>'`.

```javascript
function evToggleShareMenu(btn) {
	var menu = document.getElementById('ev-share-menu');
	if (menu) menu.hidden = !menu.hidden;
}
document.addEventListener('click', function(e) {
	var menu = document.getElementById('ev-share-menu');
	if (menu && !menu.hidden && !e.target.closest('.ev-share-wrap')) menu.hidden = true;
});
function evToggleShare(el) {
	var eventId = el.dataset.event;
	var kingdomId = el.dataset.kingdom;
	var isCheckbox = el.tagName === 'INPUT';
	var currentlyShared = isCheckbox ? !el.checked /* state BEFORE change is opposite of new */ : el.dataset.shared === '1';
	// For checkbox, the desired action is determined by its new checked state:
	var doShare = isCheckbox ? el.checked : (el.dataset.shared !== '1');
	var action = doShare ? 'share' : 'unshare';
	var body = 'KingdomId=' + encodeURIComponent(kingdomId);
	if (isCheckbox) el.disabled = true; else el.style.pointerEvents = 'none';
	fetch('<?= UIR ?>EventAjax/share/' + encodeURIComponent(eventId) + '/' + action, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: body
	})
	.then(function(r){ return r.json(); })
	.then(function(d){
		if (d.status !== 0) { throw new Error(d.error || 'Share failed'); }
		if (isCheckbox) {
			el.disabled = false;
		} else {
			el.dataset.shared = doShare ? '1' : '0';
			el.classList.toggle('is-shared', doShare);
			var lbl = el.querySelector('.ev-share-label');
			if (lbl) lbl.textContent = doShare ? 'Shared with My Kingdom' : 'Share with My Kingdom';
		}
	})
	.catch(function(err){
		console.error('[evToggleShare]', err);
		if (isCheckbox) { el.checked = !el.checked; el.disabled = false; }
		else { el.style.pointerEvents = ''; }
		alert; // placeholder removed below
	});
}
```

Note: remove the stray `alert;` line — it is a marker. The catch must NOT use native `alert()`. Instead surface the error inline; acceptable minimal handling for v1 is `console.error` + reverting the control state (already done). If a visible message is desired, reuse any existing toast helper on the page (grep `toast`/`tnToast`); do not add a native dialog.

- [ ] **Step 5: Lint the template as PHP**

```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/revised-frontend/Eventnew_index.tpl
```
Expected: `No syntax errors detected`.

- [ ] **Step 6: Browser verification (Claude-in-Chrome) — light + dark**

Log in as the test officer; open `Event/detail/EVENT_ID/DETAIL_ID` for an event owned by ANOTHER kingdom. Confirm: the share control renders; clicking shares (button turns green / checkbox checked) and a row lands in `ork_event_kingdom_share`; toggling again unshares. Toggle dark mode and confirm the button/menu are legible (no gray-box heading leak, readable contrast). Record a short GIF named `cross-kingdom-share.gif`.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Eventnew_index.tpl
git diff --cached --stat
git commit -m "Event detail: Share with My Kingdom control (UI + JS + dark-mode CSS)"
```

---

## Task 7: Surface shared events in `controller.Kingdom.php::profile()`

**Files:**
- Modify: `orkui/controller/controller.Kingdom.php` (`$evtSql` WHERE clause + SELECT, ~lines 453–505)

- [ ] **Step 1: Confirm current WHERE/SELECT**

Run:
```bash
grep -n "WHERE e.kingdom_id = {\$kid}\|e.mundane_id AS event_creator\|_IsParkEvent" orkui/controller/controller.Kingdom.php
```
Expected: the `profile()` `$evtSql` uses `WHERE e.kingdom_id = {$kid}\n  {$kn_draftClause}`; SELECT begins `SELECT e.event_id, e.name, e.park_id, e.status, e.mundane_id AS event_creator,`.

- [ ] **Step 2: Add owning-kingdom name + is_shared to SELECT**

Replace the SELECT head and FROM/LEFT JOIN so it also yields the owning kingdom name and a shared flag. Apply with Python:
```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Kingdom.php')
t = p.read_text()
old_sel = "SELECT e.event_id, e.name, e.park_id, e.status, e.mundane_id AS event_creator,\n\t\t\t       p.name AS park_name, p.abbreviation AS park_abbr,"
new_sel = ("SELECT e.event_id, e.name, e.park_id, e.status, e.mundane_id AS event_creator,\n"
           "\t\t\t       p.name AS park_name, p.abbreviation AS park_abbr,\n"
           "\t\t\t       (e.kingdom_id <> {$kid}) AS is_shared, ok.name AS owning_kingdom_name,")
print('sel found:', old_sel in t)
t = t.replace(old_sel, new_sel, 1)
# Add the owning-kingdom join right after the park LEFT JOIN in this query.
old_join = "FROM ork_event e\n\t\t\tLEFT JOIN ork_park p ON p.park_id = e.park_id\n\t\t\tJOIN ork_event_calendardetail cd ON cd.event_id = e.event_id\n\t\t\t    AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
new_join = "FROM ork_event e\n\t\t\tLEFT JOIN ork_park p ON p.park_id = e.park_id\n\t\t\tLEFT JOIN ork_kingdom ok ON ok.kingdom_id = e.kingdom_id\n\t\t\tJOIN ork_event_calendardetail cd ON cd.event_id = e.event_id\n\t\t\t    AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
print('join found:', old_join in t)
t = t.replace(old_join, new_join, 1)
p.write_text(t)
PY
```
Expected: `sel found: True` and `join found: True`.

- [ ] **Step 3: Replace the WHERE clause to OR-in shared events**

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Kingdom.php')
t = p.read_text()
old_where = "WHERE e.kingdom_id = {$kid}\n\t\t\t  {$kn_draftClause}\n\t\t\tORDER BY cd.event_start, p.name, e.name"
new_where = ("WHERE (\n"
             "\t\t\t        (e.kingdom_id = {$kid} {$kn_draftClause})\n"
             "\t\t\t        OR (e.kingdom_id <> {$kid}\n"
             "\t\t\t            AND e.event_id IN (SELECT eks.event_id FROM ork_event_kingdom_share eks WHERE eks.kingdom_id = {$kid})\n"
             "\t\t\t            AND COALESCE(e.status,'published') = 'published')\n"
             "\t\t\t      )\n"
             "\t\t\tORDER BY cd.event_start, p.name, e.name")
print('where found:', old_where in t)
p.write_text(t.replace(old_where, new_where, 1))
PY
```
Expected: `where found: True`. (Draft gate stays scoped to native rows; shared rows are published-only.)

- [ ] **Step 4: Add IsShared + OwningKingdomName to the `$eventSummary` row**

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Kingdom.php')
t = p.read_text()
needle = "\t\t\t\t\t\t'_IsParkEvent' => (int)$evtResult->park_id > 0,\n\t\t\t\t\t];"
add = ("\t\t\t\t\t\t'_IsParkEvent' => (int)$evtResult->park_id > 0,\n"
       "\t\t\t\t\t\t'IsShared'     => (int)$evtResult->is_shared === 1,\n"
       "\t\t\t\t\t\t'OwningKingdomName' => (string)($evtResult->owning_kingdom_name ?? ''),\n"
       "\t\t\t\t\t];")
print('row found:', needle in t)
p.write_text(t.replace(needle, add, 1))
PY
```
Expected: `row found: True`.

- [ ] **Step 5: Lint**

```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.Kingdom.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 6: Verify the query returns shared events**

Re-create a share row for the test event into kingdom B (Task 4 curl), then load kingdom B's profile and confirm the foreign event appears:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
  "INSERT IGNORE INTO ork_event_kingdom_share (event_id,kingdom_id,shared_by_mundane_id,created) VALUES (EVENT_ID, KINGDOM_B_ID, 1, NOW());"
curl -s 'http://localhost:19080/orkui/index.php?Route=Kingdom/profile/KINGDOM_B_ID' | grep -c "Event/detail/EVENT_ID/"
```
Expected: count ≥ 1 (the shared event links appear in kingdom B's rendered page). If 500, check `docker logs ork3-php8-app`.

- [ ] **Step 7: Commit**

```bash
git add orkui/controller/controller.Kingdom.php
git diff --cached --stat
git commit -m "Kingdom/profile: surface shared events in the Events tab (is_shared + owner name)"
```

---

## Task 8: Shared badge in the Events-tab server render (`Kingdomnew_index.tpl`)

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (event-name cell ~line 587; badge CSS)

- [ ] **Step 1: Confirm the event-name cell anchor**

Run:
```bash
grep -n "kn-copy-link\|kn-draft-pill\|kn-royal-badge" orkui/template/revised-frontend/Kingdomnew_index.tpl | head
```
Expected: the event-name `<td>` region (~585–596) where pills/badges are rendered.

- [ ] **Step 2: Add the shared badge after the event name link**

Insert immediately after the event-name `<a>...</a>`/name output (the line containing `knCopyEventLink` is right after it; place the badge BEFORE the copy-link span). Apply with Python anchored on the copy-link line:
```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Kingdomnew_index.tpl')
t = p.read_text()
anchor = "\t\t\t\t\t\t\t\t\t\t\t\t<?php if ($event['NextDetailId']): ?>\n\t\t\t\t\t\t\t\t\t\t\t\t\t<span class=\"kn-copy-link\""
badge = ("\t\t\t\t\t\t\t\t\t\t\t\t<?php if (!empty($event['IsShared'])): ?><span class=\"kn-shared-pill\" data-tip=\"Hosted by <?= htmlspecialchars($event['OwningKingdomName']) ?> — shared with this kingdom\"><i class=\"fas fa-share-nodes\"></i> Shared<?php if (!empty($event['OwningKingdomName'])): ?> · <?= htmlspecialchars($event['OwningKingdomName']) ?><?php endif; ?></span><?php endif; ?>\n"
         + anchor)
print('anchor found:', anchor in t)
p.write_text(t.replace(anchor, badge, 1))
PY
```
Expected: `anchor found: True`. (If False, open lines 585–592 and re-derive the exact indentation of the `kn-copy-link` line, then retry.)

- [ ] **Step 3: Add badge CSS (dark-mode compatible)**

Find the pill CSS cluster (`grep -n "kn-draft-pill\s*{" orkui/template/revised-frontend/Kingdomnew_index.tpl`) and insert near it:

```css
.kn-shared-pill { display:inline-block; font-size:10px; font-weight:700; letter-spacing:.02em;
	padding:2px 7px; border-radius:10px; margin-left:5px; vertical-align:middle;
	background:#e6f0fb; color:#1f5fa8; border:1px solid #b9d4f0; white-space:nowrap; }
html[data-theme="dark"] .kn-shared-pill { background:#1c2e44; color:#7fb6ee; border-color:#2f5277; }
```

- [ ] **Step 4: Lint the template as PHP**

```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/revised-frontend/Kingdomnew_index.tpl
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Browser verification — light + dark**

Open kingdom B's profile → Events tab with the shared event present. Confirm the row shows a "Shared · {Owning Kingdom}" pill, sorts inline by date, and links to the event. Toggle dark mode; confirm pill contrast. Confirm a NATIVE (owned) event shows no pill.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl
git diff --cached --stat
git commit -m "Kingdom Events tab: Shared badge on externally-owned events"
```

---

## Task 9: Shared events in `events_more` (load-more) + JS badge

**Files:**
- Modify: `orkui/controller/controller.Kingdom.php` (`events_more()` `$evtSql`, the `$events[]` row, and the HasMore subquery, ~lines 210–262)
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (`knBuildEventRow` JS, ~line 2879)

- [ ] **Step 1: Update `events_more` SELECT + WHERE**

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Kingdom.php')
t = p.read_text()
old = ("SELECT e.event_id, e.name, e.park_id, p.name AS park_name, p.abbreviation AS park_abbr,\n"
       "\t\t\t       cd.event_start, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,\n"
       "\t\t\t       (SELECT COUNT(*) FROM ork_event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = 'going') AS rsvp_going,\n"
       "\t\t\t       (SELECT COUNT(*) FROM ork_event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = 'interested') AS rsvp_interested\n"
       "\t\t\tFROM ork_event e\n"
       "\t\t\tLEFT JOIN ork_park p ON p.park_id = e.park_id\n"
       "\t\t\tJOIN ork_event_calendardetail cd ON cd.event_id = e.event_id\n"
       "\t\t\t    AND cd.event_start >  DATE_ADD(NOW(), INTERVAL {$startMonths} MONTH)\n"
       "\t\t\t    AND cd.event_start <= DATE_ADD(NOW(), INTERVAL {$endMonths} MONTH)\n"
       "\t\t\tWHERE e.kingdom_id = {$kid}\n"
       "\t\t\tORDER BY cd.event_start, p.name, e.name")
new = ("SELECT e.event_id, e.name, e.park_id, p.name AS park_name, p.abbreviation AS park_abbr,\n"
       "\t\t\t       cd.event_start, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,\n"
       "\t\t\t       (e.kingdom_id <> {$kid}) AS is_shared, ok.name AS owning_kingdom_name,\n"
       "\t\t\t       (SELECT COUNT(*) FROM ork_event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = 'going') AS rsvp_going,\n"
       "\t\t\t       (SELECT COUNT(*) FROM ork_event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = 'interested') AS rsvp_interested\n"
       "\t\t\tFROM ork_event e\n"
       "\t\t\tLEFT JOIN ork_park p ON p.park_id = e.park_id\n"
       "\t\t\tLEFT JOIN ork_kingdom ok ON ok.kingdom_id = e.kingdom_id\n"
       "\t\t\tJOIN ork_event_calendardetail cd ON cd.event_id = e.event_id\n"
       "\t\t\t    AND cd.event_start >  DATE_ADD(NOW(), INTERVAL {$startMonths} MONTH)\n"
       "\t\t\t    AND cd.event_start <= DATE_ADD(NOW(), INTERVAL {$endMonths} MONTH)\n"
       "\t\t\tWHERE (\n"
       "\t\t\t        e.kingdom_id = {$kid}\n"
       "\t\t\t        OR (e.kingdom_id <> {$kid}\n"
       "\t\t\t            AND e.event_id IN (SELECT eks.event_id FROM ork_event_kingdom_share eks WHERE eks.kingdom_id = {$kid})\n"
       "\t\t\t            AND COALESCE(e.status,'published') = 'published')\n"
       "\t\t\t      )\n"
       "\t\t\tORDER BY cd.event_start, p.name, e.name")
print('emore sql found:', old in t)
p.write_text(t.replace(old, new, 1))
PY
```
Expected: `emore sql found: True`.

- [ ] **Step 2: Add IsShared + OwningKingdomName to the `events_more` JSON row**

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Kingdom.php')
t = p.read_text()
needle = "\t\t\t\t'IsParkEvent'    => (int)$evtResult->park_id > 0,\n\t\t\t];"
add = ("\t\t\t\t'IsParkEvent'    => (int)$evtResult->park_id > 0,\n"
       "\t\t\t\t'IsShared'       => (int)$evtResult->is_shared === 1,\n"
       "\t\t\t\t'OwningKingdomName' => (string)($evtResult->owning_kingdom_name ?? ''),\n"
       "\t\t\t];")
print('emore row found:', needle in t)
p.write_text(t.replace(needle, add, 1))
PY
```
Expected: `emore row found: True`.

- [ ] **Step 3: Include shared events in the HasMore subquery**

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Kingdom.php')
t = p.read_text()
old = ("\"SELECT 1 FROM ork_event_calendardetail cd\n"
       "\t\t\t\t JOIN ork_event e ON e.event_id = cd.event_id\n"
       "\t\t\t\t WHERE e.kingdom_id = {$kid}\n"
       "\t\t\t\t   AND cd.event_start >  DATE_ADD(NOW(), INTERVAL {$_nextStart} MONTH)")
new = ("\"SELECT 1 FROM ork_event_calendardetail cd\n"
       "\t\t\t\t JOIN ork_event e ON e.event_id = cd.event_id\n"
       "\t\t\t\t WHERE (e.kingdom_id = {$kid}\n"
       "\t\t\t\t        OR e.event_id IN (SELECT eks.event_id FROM ork_event_kingdom_share eks WHERE eks.kingdom_id = {$kid}))\n"
       "\t\t\t\t   AND cd.event_start >  DATE_ADD(NOW(), INTERVAL {$_nextStart} MONTH)")
print('hasmore found:', old in t)
p.write_text(t.replace(old, new, 1))
PY
```
Expected: `hasmore found: True`.

- [ ] **Step 4: Lint controller**

```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.Kingdom.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Add the shared badge to `knBuildEventRow` JS**

In `Kingdomnew_index.tpl`, the JS builds the name cell as `nameHtml`. Add a shared pill. Apply with Python:
```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Kingdomnew_index.tpl')
t = p.read_text()
needle = "\tvar nameHtml = detailHref\n\t\t? '<a href=\"' + detailHref + '\">' + knEscape(e.Name) + '</a>'\n\t\t: knEscape(e.Name);"
add = (needle +
       "\n\tif (e.IsShared) {\n"
       "\t\tvar owner = e.OwningKingdomName ? (' \\u00b7 ' + knEscape(e.OwningKingdomName)) : '';\n"
       "\t\tnameHtml += ' <span class=\"kn-shared-pill\"><i class=\"fas fa-share-nodes\"></i> Shared' + owner + '</span>';\n"
       "\t}")
print('js anchor found:', needle in t)
p.write_text(t.replace(needle, add, 1))
PY
```
Expected: `js anchor found: True`.

- [ ] **Step 6: Lint template**

```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/revised-frontend/Kingdomnew_index.tpl
```
Expected: `No syntax errors detected`.

- [ ] **Step 7: Verify load-more JSON carries shared events**

```bash
curl -s 'http://localhost:19080/orkui/index.php?Route=Kingdom/events_more/KINGDOM_B_ID&window=1' | python3 -m json.tool | grep -A2 -i '"IsShared"' | head
```
Expected: at least one event object with `"IsShared": true` and the owning kingdom name (if a shared event falls in the window). If none in window 1, this just confirms the field is emitted — verify shape with any event present.

- [ ] **Step 8: Browser verification**

On kingdom B's Events tab, click "Load more" if present; confirm appended rows render the shared pill identically to server-rendered rows. Light + dark.

- [ ] **Step 9: Commit**

```bash
git add orkui/controller/controller.Kingdom.php orkui/template/revised-frontend/Kingdomnew_index.tpl
git diff --cached --stat
git commit -m "Kingdom events load-more: include + badge shared events"
```

---

## Task 10: Negative/scope verification + cleanup

**Files:** none (verification only)

- [ ] **Step 1: Park officer cannot share (scope gate)**

Find a mundane with ONLY a park grant (no kingdom grant), log in via curl, attempt to share into that park's kingdom:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
 "SELECT a.mundane_id, m.username FROM ork_authorization a JOIN ork_mundane m ON m.mundane_id=a.mundane_id WHERE a.park_id>0 AND a.role IN ('create','edit') AND a.mundane_id NOT IN (SELECT mundane_id FROM ork_authorization WHERE kingdom_id>0 AND role IN('create','edit','admin')) LIMIT 3;"
```
Log in as that user and POST `EventAjax/share/EVENT_ID/share` with the park's kingdom id. Expected: non-zero `status` (NoAuthorization). Confirm no row inserted.

- [ ] **Step 2: Confirm shared events do NOT leak into out-of-scope surfaces**

Per spec, sharing affects ONLY the Kingdom Events tab. Spot-check that an event-attendance report / search for kingdom B does not now list the foreign event:
```bash
curl -s 'http://localhost:19080/orkui/index.php?Route=Reports/eventattendance/KINGDOM_B_ID' | grep -c "Event/detail/EVENT_ID/" || true
```
Expected: `0` (the share is display-only on the Events tab; reports are untouched because they were not modified). If a report DOES show it, that surface independently queries owned events — confirm it was not accidentally modified.

- [ ] **Step 3: Idempotency + delete-cleanup behavior**

```bash
# Re-share twice → still one row (INSERT IGNORE + unique key):
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
 "INSERT IGNORE INTO ork_event_kingdom_share (event_id,kingdom_id,shared_by_mundane_id,created) VALUES (EVENT_ID,KINGDOM_B_ID,1,NOW()),(EVENT_ID,KINGDOM_B_ID,1,NOW()); SELECT COUNT(*) FROM ork_event_kingdom_share WHERE event_id=EVENT_ID AND kingdom_id=KINGDOM_B_ID;"
```
Expected: count `1`.

- [ ] **Step 4: Remove any leftover test rows used only for verification**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
 "DELETE FROM ork_event_kingdom_share WHERE shared_by_mundane_id=1 AND event_id=EVENT_ID AND kingdom_id=KINGDOM_B_ID;"
```
(Leave legitimately-created shares from the curl/browser officer tests if you want them for demo; otherwise clear.) No commit — DB-only cleanup.

---

## Self-Review (completed during planning)

- **Spec coverage:** data model → Task 1; permissions/rules (AUTH_KINGDOM, published-only, no self-share, unshare) → Tasks 2,5,10; backend methods → Task 2; AJAX → Task 4; event-page entry point (single + multi-kingdom) → Tasks 5,6; Events-tab rendering (both profile query + load-more, inline + badge) → Tasks 7,8,9; out-of-scope surfaces untouched → verified Task 10 Step 2.
- **Type/name consistency:** `ork_event_kingdom_share` columns, `ShareEventToKingdom`/`UnshareEventFromKingdom`/`GetSharedKingdomsForEvent`, model `share_event_to_kingdom`/`unshare_event_from_kingdom`/`get_shared_kingdoms_for_event`, AJAX route `EventAjax/share/{id}/{share|unshare}`, template keys `ShareableKingdoms` and per-event `IsShared`/`OwningKingdomName` (+ SQL aliases `is_shared`/`owning_kingdom_name`) are used identically across all tasks.
- **Placeholders:** the only intentional `<PASTE…>` markers are mechanical (re-using a code block already shown verbatim in the same task) — every code step shows real code. The stray `alert;` line in Task 6 Step 4 is explicitly called out for removal.

## Execution notes / risk flags

- **`$r['Status']` shape (Task 4 Step 2):** `Success()`/`InvalidParameter()` return-shape must be confirmed at runtime; the fallback check is documented inline.
- **Insertion-anchor drift:** several edits anchor on exact tab-indented strings; each task's Step-1 grep confirms the anchor before editing, and each Python snippet prints a `found:`/`True` guard so a drifted anchor fails loudly instead of silently no-op'ing.
- **`Eventnew_index.tpl` placement (Task 6 Step 1):** the exact hero/action container is chosen at implementation time from the grep — the only genuinely layout-dependent decision; the markup/CSS/JS themselves are fixed.
