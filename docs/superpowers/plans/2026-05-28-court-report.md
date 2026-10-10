# Court Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a public, read-only Court Report — reachable from the Kingdom and Park report grids — that lists courts in a date range (default past six months) and, per court, shows the awards confirmed *given* (recipient, award, public comment, attached artisans).

**Architecture:** Approach A from the design — report query methods live in the existing court domain class `system/lib/ork3/class.Court.php` (reachable as `Ork3::$Lib->court`, exactly how `controller.Court.php` calls it), surfaced through the `Reports` controller with two new standalone templates. A new public `public_comment` column on `ork_court_award` is filled in the Court Planner and shown on the report; the planner's existing `notes` field stays internal.

**Tech Stack:** PHP 8 (custom MVC: controllers in `orkui/controller/`, templates in `orkui/template/default/`, domain classes in `system/lib/ork3/`), MariaDB (container `ork3-php8-db`, prefix `ork_` via `DB_PREFIX`), flatpickr (CDN) for date inputs.

**Conventions to respect (from project memory):**
- Multi-line PHP/`.tpl`/`.js` edits: use Python `read_text()/replace()`, NOT the Edit tool (tab vs space mismatches). Single unambiguous lines may use Edit.
- `$DB->Clear()` before every raw `Execute`/`DataSet`.
- Dark-mode compatible proactively; human-readable dates (flatpickr `altInput`+`altFormat`); no native `title` tooltips on new UI; player-search dropdowns not needed here.
- Stage files explicitly (never `git add -A`/`.`); never stage `system/lib/ork3/class.Authorization.php`; verify `git diff --cached` before commit.
- Commit messages end with the `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>` trailer.

**Note on testing:** This project has no PHP unit-test harness. Per project memory, "tested" means the route is **curl-tested to return the expected rows** before being called done, plus a dark-mode browser walk-through. Verification steps below use curl against `http://localhost:19080` and DB checks, not xUnit.

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `db-migrations/2026-05-28-court-award-public-comment.sql` | Add `public_comment` column | Create |
| `system/lib/ork3/class.Court.php` | Report query methods + expose `public_comment` in planner load | Modify |
| `orkui/controller/controller.CourtAjax.php` | Persist `public_comment` on add/update award | Modify |
| `orkui/template/default/Court_detail.tpl` | Planner "Public comment" field (PHP row, JS row, ad-hoc modal, save JS) | Modify |
| `orkui/controller/controller.Reports.php` | `courts` + `court` public actions | Modify |
| `orkui/template/default/Reports_courts.tpl` | List view + date-range filter | Create |
| `orkui/template/default/Reports_court.tpl` | Per-court detail view | Create |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl` | "Court Report" link in Awards group | Modify |
| `orkui/template/revised-frontend/Parknew_index.tpl` | "Court Report" link in Awards group | Modify |

---

## Task 1: Migration — add `public_comment` column

**Files:**
- Create: `db-migrations/2026-05-28-court-award-public-comment.sql`

- [ ] **Step 1: Write the migration file**

```sql
-- Court Report: public-facing comment per court award, distinct from internal `notes`.
ALTER TABLE ork_court_award
    ADD COLUMN public_comment TEXT NULL DEFAULT NULL AFTER notes;
```

- [ ] **Step 2: Apply the migration**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-28-court-award-public-comment.sql
```
Expected: no output, exit 0.

- [ ] **Step 3: Verify the column exists**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_court_award LIKE 'public_comment';"
```
Expected: one row showing `public_comment | text | YES`.

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-05-28-court-award-public-comment.sql
git commit -m "Court Report: add public_comment column to court_award

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `class.Court.php` — expose `public_comment` + add report methods

**Files:**
- Modify: `system/lib/ork3/class.Court.php`

- [ ] **Step 1: Expose `public_comment` in `getCourtAwards()` (planner load)**

In the `getCourtAwards()` SELECT, the line currently reads:
```php
                    ca.notes, ca.status, ca.scroll_status, ca.regalia_status,
```
Use Python to replace it with (adds `ca.public_comment`):
```php
                    ca.notes, ca.public_comment, ca.status, ca.scroll_status, ca.regalia_status,
```
Then in the same method's per-row array, the line:
```php
                    'Notes'             => $rs->notes ?? '',
```
Replace with:
```php
                    'Notes'             => $rs->notes ?? '',
                    'PublicComment'     => $rs->public_comment ?? '',
```

Python pattern (run once per replacement):
```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Court.php')
t = p.read_text()
a = "                    ca.notes, ca.status, ca.scroll_status, ca.regalia_status,\n"
b = "                    ca.notes, ca.public_comment, ca.status, ca.scroll_status, ca.regalia_status,\n"
assert t.count(a) == 1, ('SELECT line', t.count(a))
t = t.replace(a, b)
c = "                    'Notes'             => $rs->notes ?? '',\n"
d = ("                    'Notes'             => $rs->notes ?? '',\n"
     "                    'PublicComment'     => $rs->public_comment ?? '',\n")
assert t.count(c) == 1, ('Notes array line', t.count(c))
t = t.replace(c, d)
p.write_text(t)
print('ok')
PY
```
Expected: `ok`.

- [ ] **Step 2: Add the two report methods**

Append these methods inside the `Court` class, immediately before the final closing `}` of the class (right after `updateAwardTrackingStatus()`). Use Python to insert before the last `}`:

```php
    // -----------------------------------------------------------------------
    // Court Report (public, read-only) — see docs/superpowers/specs/2026-05-28-court-report-design.md
    // -----------------------------------------------------------------------

    /**
     * Validate a Y-m-d date string; return it if valid, else null.
     */
    private function validDate($d) {
        return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    }

    /**
     * Courts in [$from_date, $until_date] (inclusive on court_date) that have at
     * least one award with status='given'.
     *   Kingdom report ($kingdom_id set, $park_id = 0): courts in that kingdom.
     *   Park report ($park_id set): courts owned by the park OR any court holding a
     *     given award whose recipient's home park is $park_id.
     */
    public function getCourtReportList($kingdom_id, $park_id, $from_date, $until_date) {
        $from  = $this->validDate($from_date)  ?? date('Y-m-d', strtotime('-6 months'));
        $until = $this->validDate($until_date) ?? date('Y-m-d');
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;

        if ($park_id > 0) {
            $scopeJoin  = ' LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = ca.mundane_id';
            $scopeWhere = '(c.park_id = ' . $park_id . ' OR m.park_id = ' . $park_id . ')';
        } else {
            $scopeJoin  = '';
            $scopeWhere = 'c.kingdom_id = ' . $kingdom_id;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.court_id, c.name, c.court_date, c.park_id, c.kingdom_id,
                    e.name AS event_name, p.name AS park_name,
                    COUNT(DISTINCT ca.court_award_id) AS given_count
             FROM ' . DB_PREFIX . 'court c
             JOIN ' . DB_PREFIX . 'court_award ca
                    ON ca.court_id = c.court_id AND ca.status = \'given\'' . $scopeJoin . '
             LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd
                    ON cd.event_calendardetail_id = c.event_calendardetail_id
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = c.park_id
             WHERE c.court_date BETWEEN \'' . $from . '\' AND \'' . $until . '\'
               AND ' . $scopeWhere . '
             GROUP BY c.court_id
             ORDER BY c.court_date DESC, c.court_id DESC'
        );

        $list = [];
        if ($rs) {
            while ($rs->Next()) {
                $list[] = [
                    'CourtId'    => (int)$rs->court_id,
                    'Name'       => $rs->name,
                    'CourtDate'  => $rs->court_date,
                    'ParkId'     => (int)$rs->park_id,
                    'KingdomId'  => (int)$rs->kingdom_id,
                    'ParkName'   => $rs->park_name,
                    'EventName'  => $rs->event_name,
                    'GivenCount' => (int)$rs->given_count,
                ];
            }
        }
        return $list;
    }

    /**
     * One court's header plus its status='given' awards (public fields only) with
     * artisans batch-loaded. Returns null if the court does not exist.
     */
    public function getCourtReportDetail($court_id) {
        $court_id = (int)$court_id;

        $this->db->Clear();
        $hr = $this->db->DataSet(
            'SELECT c.court_id, c.kingdom_id, c.park_id, c.name, c.court_date,
                    e.name AS event_name, p.name AS park_name, k.name AS kingdom_name
             FROM ' . DB_PREFIX . 'court c
             LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd
                    ON cd.event_calendardetail_id = c.event_calendardetail_id
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = c.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = c.kingdom_id
             WHERE c.court_id = ' . $court_id . ' LIMIT 1'
        );
        if (!$hr || !$hr->Next()) return null;

        $court = [
            'CourtId'     => (int)$hr->court_id,
            'KingdomId'   => (int)$hr->kingdom_id,
            'ParkId'      => (int)$hr->park_id,
            'Name'        => $hr->name,
            'CourtDate'   => $hr->court_date,
            'EventName'   => $hr->event_name,
            'ParkName'    => $hr->park_name,
            'KingdomName' => $hr->kingdom_name,
        ];

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.court_award_id, ca.mundane_id, ca.rank, ca.public_comment,
                    m.persona, p.abbreviation AS park_abbrev,
                    IFNULL(ka.name, a.name) AS award_name, a.is_ladder,
                    sm.persona AS scroll_maker_persona, rm.persona AS regalia_maker_persona
             FROM ' . DB_PREFIX . 'court_award ca
             LEFT JOIN ' . DB_PREFIX . 'mundane m  ON m.mundane_id       = ca.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p     ON p.park_id          = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ca.kingdomaward_id
             LEFT JOIN ' . DB_PREFIX . 'award a    ON a.award_id         = ka.award_id
             LEFT JOIN ' . DB_PREFIX . 'mundane sm ON sm.mundane_id      = ca.scroll_maker_id
             LEFT JOIN ' . DB_PREFIX . 'mundane rm ON rm.mundane_id      = ca.regalia_maker_id
             WHERE ca.court_id = ' . $court_id . ' AND ca.status = \'given\'
             ORDER BY ca.sort_order, ca.court_award_id'
        );

        $awards = [];
        if ($rs) {
            while ($rs->Next()) {
                $awards[(int)$rs->court_award_id] = [
                    'CourtAwardId'        => (int)$rs->court_award_id,
                    'MundaneId'           => (int)$rs->mundane_id,
                    'Persona'             => $rs->persona,
                    'ParkAbbrev'          => $rs->park_abbrev ?? '',
                    'AwardName'           => $rs->award_name,
                    'IsLadder'            => (bool)(int)$rs->is_ladder,
                    'Rank'                => (int)$rs->rank,
                    'PublicComment'       => $rs->public_comment ?? '',
                    'ScrollMakerPersona'  => $rs->scroll_maker_persona ?? '',
                    'RegaliaMakerPersona' => $rs->regalia_maker_persona ?? '',
                    'Artisans'            => [],
                ];
            }
        }

        if (!empty($awards)) {
            $ids = implode(',', array_keys($awards));
            $this->db->Clear();
            $ars = $this->db->DataSet(
                'SELECT caa.court_award_id, caa.mundane_id, caa.contribution, m.persona
                 FROM ' . DB_PREFIX . 'court_award_artisan caa
                 LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = caa.mundane_id
                 WHERE caa.court_award_id IN (' . $ids . ')
                 ORDER BY caa.court_award_artisan_id'
            );
            if ($ars) {
                while ($ars->Next()) {
                    $cid = (int)$ars->court_award_id;
                    if (isset($awards[$cid])) {
                        $awards[$cid]['Artisans'][] = [
                            'MundaneId'    => (int)$ars->mundane_id,
                            'Persona'      => $ars->persona,
                            'Contribution' => $ars->contribution,
                        ];
                    }
                }
            }
        }

        return ['Court' => $court, 'Awards' => array_values($awards)];
    }
```

Python insertion before the final `}`:
```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Court.php')
t = p.read_text()
methods = open('/tmp/court_report_methods.txt').read()  # paste the PHP above into this file first
idx = t.rstrip().rfind('}')
assert idx != -1
t = t[:idx] + methods + '\n}\n'
p.write_text(t)
print('inserted')
PY
```
(Write the PHP block above to `/tmp/court_report_methods.txt` first, then run.)

- [ ] **Step 3: Lint the file**

Run: `docker exec -i ork3-php8 php -l /var/www/html/system/lib/ork3/class.Court.php` (adjust container/path if different — fallback `php -l system/lib/ork3/class.Court.php`).
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Court.php
git commit -m "Court Report: report query methods + expose public_comment

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `controller.CourtAjax.php` — persist `public_comment`

**Files:**
- Modify: `orkui/controller/controller.CourtAjax.php`

- [ ] **Step 1: `update_award` — read + save `public_comment`**

In `update_award()`, after the line:
```php
        $notes            = trim($_POST['Notes']          ?? '');
```
insert:
```php
        $public_comment   = trim($_POST['PublicComment']  ?? '');
```
Then in the same method's UPDATE statement, change:
```php
            'UPDATE ' . DB_PREFIX . 'court_award SET
             notes = \'' . $this->esc($notes) . '\',
```
to:
```php
            'UPDATE ' . DB_PREFIX . 'court_award SET
             notes = \'' . $this->esc($notes) . '\',
             public_comment = \'' . $this->esc($public_comment) . '\',
```
And update the JSON response line:
```php
        $this->jsonOut(['status' => 0, 'notes' => $notes, 'pass_to_local' => $pass_to_local, 'award_status' => $status]);
```
to:
```php
        $this->jsonOut(['status' => 0, 'notes' => $notes, 'public_comment' => $public_comment, 'pass_to_local' => $pass_to_local, 'award_status' => $status]);
```

- [ ] **Step 2: `add_award` — read, insert, and return `public_comment`**

In `add_award()`, after:
```php
        $notes           = trim($_POST['Notes']               ?? '');
```
insert:
```php
        $public_comment  = trim($_POST['PublicComment']       ?? '');
```
Change the INSERT column list + values from:
```php
            'INSERT INTO ' . DB_PREFIX . 'court_award
             (court_id, mundane_id, kingdomaward_id, rank, recommendations_id,
              sort_order, pass_to_local, notes)
             VALUES (' . $court_id . ', ' . $mundane_id . ', ' . $kingdomaward_id . ', ' . $rank . ',
                     ' . $rec_val . ', ' . $sort . ', ' . $pass_to_local . ', ' . $notes_val . ')'
```
to:
```php
            'INSERT INTO ' . DB_PREFIX . 'court_award
             (court_id, mundane_id, kingdomaward_id, rank, recommendations_id,
              sort_order, pass_to_local, notes, public_comment)
             VALUES (' . $court_id . ', ' . $mundane_id . ', ' . $kingdomaward_id . ', ' . $rank . ',
                     ' . $rec_val . ', ' . $sort . ', ' . $pass_to_local . ', ' . $notes_val . ',
                     \'' . $this->esc($public_comment) . '\')'
```
Add `'PublicComment' => $public_comment,` to the returned `award` array (next to `'Notes' => $notes,`).

Use Python `read_text()/replace()` for each (multi-line). Assert each `old` appears exactly once before replacing.

- [ ] **Step 3: Lint**

Run: `php -l orkui/controller/controller.CourtAjax.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.CourtAjax.php
git commit -m "Court Report: persist public_comment on court awards

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `Court_detail.tpl` — planner "Public comment" field

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`

Reuse the dark-mode-styled `cp-notes-area` class for the new textareas (already themed at lines 115, 698-699). Make all edits with Python `read_text()/replace()`, asserting single matches.

- [ ] **Step 1: PHP-rendered expand row — add Public Comment textarea**

Find (around line 1018):
```php
                    <div>
                        <div class="cp-expand-label">Internal Notes</div>
                        <textarea class="cp-notes-area" id="cp-notes-<?= (int)$aw['CourtAwardId'] ?>"
                                  placeholder="Monarchy notes (not public)…"><?= htmlspecialchars($aw['Notes']) ?></textarea>
                    </div>
```
Replace with (adds a Public Comment block after Internal Notes, inside the same grid cell stack):
```php
                    <div>
                        <div class="cp-expand-label">Internal Notes</div>
                        <textarea class="cp-notes-area" id="cp-notes-<?= (int)$aw['CourtAwardId'] ?>"
                                  placeholder="Monarchy notes (not public)…"><?= htmlspecialchars($aw['Notes']) ?></textarea>
                        <div class="cp-expand-label" style="margin-top:10px">Public Comment</div>
                        <textarea class="cp-notes-area" id="cp-pubcomment-<?= (int)$aw['CourtAwardId'] ?>"
                                  placeholder="Shown on the public Court Report…"><?= htmlspecialchars($aw['PublicComment'] ?? '') ?></textarea>
                    </div>
```

- [ ] **Step 2: `cpSaveAward` JS — send `PublicComment`**

Find (around line 1691):
```javascript
        var notes          = gid('cp-notes-' + caid).value;
```
Replace with:
```javascript
        var notes          = gid('cp-notes-' + caid).value;
        var pubCommentEl    = gid('cp-pubcomment-' + caid);
        var publicComment   = pubCommentEl ? pubCommentEl.value : '';
```
Find:
```javascript
        fd.append('Notes',         notes);
```
Replace with:
```javascript
        fd.append('Notes',         notes);
        fd.append('PublicComment', publicComment);
```

- [ ] **Step 3: JS row builder `cpAppendAwardRow` — add Public Comment textarea**

Find (around line 2094):
```javascript
            '<div><div class="cp-expand-label">Internal Notes</div><textarea class="cp-notes-area" id="cp-notes-' + aw.CourtAwardId + '" placeholder="Monarchy notes…">' + esc(aw.Notes || '') + '</textarea></div>' +
```
Replace with:
```javascript
            '<div><div class="cp-expand-label">Internal Notes</div><textarea class="cp-notes-area" id="cp-notes-' + aw.CourtAwardId + '" placeholder="Monarchy notes…">' + esc(aw.Notes || '') + '</textarea><div class="cp-expand-label" style="margin-top:10px">Public Comment</div><textarea class="cp-notes-area" id="cp-pubcomment-' + aw.CourtAwardId + '" placeholder="Shown on the public Court Report…">' + esc(aw.PublicComment || '') + '</textarea></div>' +
```

- [ ] **Step 4: Ad-hoc add modal — add Public Comment field**

Find (around line 1295):
```php
            <div class="cp-field">
                <label>Internal Notes</label>
                <textarea id="cp-adhoc-notes" rows="3" placeholder="Monarchy notes (not public)…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:14px;resize:vertical;box-sizing:border-box"></textarea>
            </div>
```
Replace with (append a Public Comment field after it):
```php
            <div class="cp-field">
                <label>Internal Notes</label>
                <textarea id="cp-adhoc-notes" rows="3" placeholder="Monarchy notes (not public)…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:14px;resize:vertical;box-sizing:border-box"></textarea>
            </div>
            <div class="cp-field">
                <label>Public Comment</label>
                <textarea id="cp-adhoc-pubcomment" rows="3" placeholder="Shown on the public Court Report…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:14px;resize:vertical;box-sizing:border-box"></textarea>
            </div>
```

- [ ] **Step 5: Ad-hoc modal JS — reset + submit `PublicComment`**

In `cpOpenAdhocModal`, find:
```javascript
        gid('cp-adhoc-notes').value  = '';
```
Replace with:
```javascript
        gid('cp-adhoc-notes').value  = '';
        gid('cp-adhoc-pubcomment').value = '';
```
In `cpSubmitAdhoc`, find:
```javascript
        var notes     = gid('cp-adhoc-notes').value.trim();
```
Replace with:
```javascript
        var notes     = gid('cp-adhoc-notes').value.trim();
        var pubComment = gid('cp-adhoc-pubcomment').value.trim();
```
Find (the ad-hoc FormData build):
```javascript
        fd.append('Notes',          notes);
```
Replace with:
```javascript
        fd.append('Notes',          notes);
        fd.append('PublicComment',  pubComment);
```

- [ ] **Step 6: Verify the page still loads (syntax)**

Run: `php -l orkui/template/default/Court_detail.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court Report: Public Comment field in Court Planner award editor

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `controller.Reports.php` — `courts` + `court` public actions

**Files:**
- Modify: `orkui/controller/controller.Reports.php`

- [ ] **Step 1: Register both actions as public**

Find the `$public_reports` array in `__construct` and add `'courts'` and `'court'`:
```php
		$public_reports = [
			'roster',
			'kingdom_officer_directory',
			'knights_and_masters',
			'knights_list',
			'masters_list',
			'attendance',
			'event_attendance',
			'suspended',
			'courts',
			'court',
		];
```

- [ ] **Step 2: Add the `courts` (list) action**

Insert this method into the `Controller_Reports` class (e.g. right after `player_awards()`):
```php
	public function courts($params = null) {
		$kingdom_id = isset($this->request->KingdomId) ? (int)$this->request->KingdomId : 0;
		$park_id    = isset($this->request->ParkId)    ? (int)$this->request->ParkId    : 0;

		$from  = (isset($this->request->From)  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->request->From))
			? $this->request->From  : date('Y-m-d', strtotime('-6 months'));
		$until = (isset($this->request->Until) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->request->Until))
			? $this->request->Until : date('Y-m-d');

		if (!valid_id($kingdom_id) && !valid_id($park_id)) {
			header('Location: ' . UIR);
			exit;
		}

		// Resolve a scope label + kingdom_id for park scope (for back-links / header)
		global $DB;
		$location_name = '';
		if ($park_id > 0) {
			$DB->Clear();
			$r = $DB->DataSet('SELECT name, kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $park_id . ' LIMIT 1');
			if ($r && $r->Next()) { $location_name = $r->name; if (!$kingdom_id) $kingdom_id = (int)$r->kingdom_id; }
		} else {
			$DB->Clear();
			$r = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdom_id . ' LIMIT 1');
			if ($r && $r->Next()) $location_name = $r->name;
		}

		$this->template = 'Reports_courts.tpl';
		$this->data['Courts']       = Ork3::$Lib->court->getCourtReportList($kingdom_id, $park_id, $from, $until);
		$this->data['ScopeType']    = $park_id > 0 ? 'park' : 'kingdom';
		$this->data['KingdomId']    = $kingdom_id;
		$this->data['ParkId']       = $park_id;
		$this->data['From']         = $from;
		$this->data['Until']        = $until;
		$this->data['LocationName'] = $location_name;
		$this->data['page_title']   = 'Court Report';

		if ($park_id > 0) {
			$this->data['menu']['reports']['url'] = UIR . 'Park/profile/' . $park_id . '&tab=reports';
		} else {
			$this->data['menu']['reports']['url'] = UIR . 'Kingdom/profile/' . $kingdom_id . '&tab=reports';
		}
	}
```

- [ ] **Step 3: Add the `court` (detail) action**

Insert right after `courts()`:
```php
	public function court($params = null) {
		$court_id = isset($this->request->CourtId) ? (int)$this->request->CourtId : 0;
		if (!valid_id($court_id)) {
			header('Location: ' . UIR);
			exit;
		}

		$report = Ork3::$Lib->court->getCourtReportDetail($court_id);
		if (!$report) {
			header('Location: ' . UIR);
			exit;
		}

		// Build a back-link to the list, preserving scope/date filter when provided.
		$kingdom_id = isset($this->request->KingdomId) ? (int)$this->request->KingdomId : 0;
		$park_id    = isset($this->request->ParkId)    ? (int)$this->request->ParkId    : 0;
		$from       = (isset($this->request->From)  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->request->From))  ? $this->request->From  : '';
		$until      = (isset($this->request->Until) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->request->Until)) ? $this->request->Until : '';
		if (!$kingdom_id && !$park_id) { $kingdom_id = $report['Court']['KingdomId']; $park_id = $report['Court']['ParkId']; }

		$back = UIR . 'Reports/courts&' . ($park_id > 0 ? 'ParkId=' . $park_id : 'KingdomId=' . $kingdom_id);
		if ($park_id > 0 && $kingdom_id) $back .= '&KingdomId=' . $kingdom_id;
		if ($from)  $back .= '&From='  . $from;
		if ($until) $back .= '&Until=' . $until;

		$this->template = 'Reports_court.tpl';
		$this->data['Court']      = $report['Court'];
		$this->data['Awards']     = $report['Awards'];
		$this->data['BackUrl']    = $back;
		$this->data['page_title'] = 'Court Report — ' . $report['Court']['Name'];
		$this->data['menu']['reports']['url'] = $back;
	}
```

- [ ] **Step 4: Lint**

Run: `php -l orkui/controller/controller.Reports.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.Reports.php
git commit -m "Court Report: courts/court public report actions

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: `Reports_courts.tpl` — list view + date filter

**Files:**
- Create: `orkui/template/default/Reports_courts.tpl`

- [ ] **Step 1: Create the template**

```php
<?php
/* Court Report — list of courts (with given awards) in a date range. */
$scope_id   = ($ScopeType === 'park') ? (int)$ParkId : (int)$KingdomId;
$scope_qs   = ($ScopeType === 'park') ? ('ParkId=' . (int)$ParkId . ($KingdomId ? '&KingdomId=' . (int)$KingdomId : '')) : ('KingdomId=' . (int)$KingdomId);
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>
.cr-wrap { max-width: 980px; margin: 0 auto; padding: 16px; }
.cr-head { margin-bottom: 16px; }
.cr-head h1 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; font-size: 22px; margin: 0 0 4px; }
.cr-sub { color: #718096; font-size: 13px; }
.cr-filter { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px; }
.cr-filter label { display: block; font-size: 12px; color: #4a5568; margin-bottom: 4px; }
.cr-filter input[type=text] { padding: 7px 10px; border: 1px solid #cbd5e0; border-radius: 5px; font-size: 14px; }
.cr-btn { padding: 8px 16px; border: none; border-radius: 5px; background: #4c51bf; color: #fff; font-size: 14px; cursor: pointer; }
.cr-list { list-style: none; margin: 0; padding: 0; }
.cr-court { display: block; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; text-decoration: none; color: inherit; transition: box-shadow .15s, border-color .15s; }
.cr-court:hover { border-color: #4c51bf; box-shadow: 0 2px 8px rgba(76,81,191,.12); }
.cr-court-top { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
.cr-court-name { font-weight: 600; font-size: 16px; }
.cr-court-date { color: #718096; font-size: 13px; white-space: nowrap; }
.cr-court-meta { color: #718096; font-size: 13px; margin-top: 4px; }
.cr-badge { display: inline-block; background: #ebf4ff; color: #3c4ba6; border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 600; }
.cr-empty { text-align: center; color: #718096; padding: 40px 20px; border: 1px dashed #cbd5e0; border-radius: 8px; }
html[data-theme="dark"] .cr-sub, html[data-theme="dark"] .cr-court-date, html[data-theme="dark"] .cr-court-meta { color: #a0aec0; }
html[data-theme="dark"] .cr-filter { background: #1f2733; border-color: #2d3748; }
html[data-theme="dark"] .cr-filter label { color: #cbd5e0; }
html[data-theme="dark"] .cr-filter input[type=text] { background: #2d3748; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .cr-court { border-color: #2d3748; background: #1a202c; }
html[data-theme="dark"] .cr-court:hover { border-color: #667eea; }
html[data-theme="dark"] .cr-badge { background: #2a3656; color: #b9c6ff; }
html[data-theme="dark"] .cr-empty { border-color: #2d3748; color: #a0aec0; }
</style>

<div class="cr-wrap">
	<div class="cr-head">
		<h1><i class="fas fa-gavel" style="margin-right:8px;color:#4c51bf"></i>Court Report</h1>
		<div class="cr-sub"><?= htmlspecialchars($LocationName) ?> · confirmed awards given at court</div>
	</div>

	<form class="cr-filter" method="get" action="<?= UIR ?>Reports/courts">
		<input type="hidden" name="Route" value="Reports/courts">
		<?php if ($ScopeType === 'park'): ?>
			<input type="hidden" name="ParkId" value="<?= (int)$ParkId ?>">
			<?php if ($KingdomId): ?><input type="hidden" name="KingdomId" value="<?= (int)$KingdomId ?>"><?php endif; ?>
		<?php else: ?>
			<input type="hidden" name="KingdomId" value="<?= (int)$KingdomId ?>">
		<?php endif; ?>
		<div>
			<label>From</label>
			<input type="text" id="cr-from" name="From" autocomplete="off" value="<?= htmlspecialchars($From) ?>">
		</div>
		<div>
			<label>Until</label>
			<input type="text" id="cr-until" name="Until" autocomplete="off" value="<?= htmlspecialchars($Until) ?>">
		</div>
		<button type="submit" class="cr-btn"><i class="fas fa-search" style="margin-right:5px"></i>Search</button>
	</form>

	<?php if (empty($Courts)): ?>
		<div class="cr-empty">No courts with confirmed awards in this date range.</div>
	<?php else: ?>
		<ul class="cr-list">
			<?php foreach ($Courts as $c): ?>
				<?php
					$detail = UIR . 'Reports/court&CourtId=' . (int)$c['CourtId'] . '&' . $scope_qs;
					if ($From)  $detail .= '&From='  . htmlspecialchars($From);
					if ($Until) $detail .= '&Until=' . htmlspecialchars($Until);
					$court_scope = ($c['ParkId'] > 0) ? ($c['ParkName'] ?? 'Park court') : 'Kingdom court';
				?>
				<a class="cr-court" href="<?= $detail ?>">
					<div class="cr-court-top">
						<span class="cr-court-name"><?= htmlspecialchars($c['Name']) ?></span>
						<span class="cr-court-date"><?= $c['CourtDate'] ? date('F j, Y', strtotime($c['CourtDate'])) : 'Date TBD' ?></span>
					</div>
					<div class="cr-court-meta">
						<span class="cr-badge"><?= (int)$c['GivenCount'] ?> award<?= $c['GivenCount'] == 1 ? '' : 's' ?></span>
						&nbsp; <?= htmlspecialchars($court_scope) ?>
						<?php if (!empty($c['EventName'])): ?> · <?= htmlspecialchars($c['EventName']) ?><?php endif; ?>
					</div>
				</a>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>

<script>
(function() {
	flatpickr('#cr-from',  { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' });
	flatpickr('#cr-until', { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' });
})();
</script>
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/template/default/Reports_courts.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/Reports_courts.tpl
git commit -m "Court Report: list view template

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: `Reports_court.tpl` — detail view

**Files:**
- Create: `orkui/template/default/Reports_court.tpl`

- [ ] **Step 1: Create the template**

```php
<?php
/* Court Report — one court's confirmed (given) awards. */
$c = $Court;
?>
<style>
.cr-wrap { max-width: 980px; margin: 0 auto; padding: 16px; }
.cr-back { display: inline-block; margin-bottom: 14px; color: #4c51bf; text-decoration: none; font-size: 13px; }
.cr-back:hover { text-decoration: underline; }
.cr-head h1 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; font-size: 22px; margin: 0 0 4px; }
.cr-sub { color: #718096; font-size: 13px; margin-bottom: 18px; }
.cr-table { width: 100%; border-collapse: collapse; }
.cr-table th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #718096; border-bottom: 2px solid #e2e8f0; padding: 8px 10px; }
.cr-table td { padding: 10px; border-bottom: 1px solid #edf2f7; font-size: 14px; vertical-align: top; }
.cr-recipient a { color: #2d3748; font-weight: 600; text-decoration: none; }
.cr-recipient a:hover { text-decoration: underline; }
.cr-rank { color: #718096; font-size: 12px; margin-left: 6px; }
.cr-comment { color: #4a5568; }
.cr-artisan { display: block; font-size: 13px; }
.cr-artisan-role { color: #718096; }
.cr-maker { display: block; font-size: 12px; color: #718096; }
.cr-none { color: #a0aec0; font-style: italic; }
.cr-empty { text-align: center; color: #718096; padding: 40px 20px; border: 1px dashed #cbd5e0; border-radius: 8px; }
html[data-theme="dark"] .cr-sub, html[data-theme="dark"] .cr-rank, html[data-theme="dark"] .cr-maker, html[data-theme="dark"] .cr-artisan-role { color: #a0aec0; }
html[data-theme="dark"] .cr-table th { color: #a0aec0; border-color: #2d3748; }
html[data-theme="dark"] .cr-table td { border-color: #2d3748; }
html[data-theme="dark"] .cr-recipient a { color: #e2e8f0; }
html[data-theme="dark"] .cr-comment { color: #cbd5e0; }
html[data-theme="dark"] .cr-empty { border-color: #2d3748; color: #a0aec0; }
</style>

<div class="cr-wrap">
	<a class="cr-back" href="<?= $BackUrl ?>"><i class="fas fa-arrow-left" style="margin-right:5px"></i>Back to Court Report</a>
	<div class="cr-head">
		<h1><i class="fas fa-gavel" style="margin-right:8px;color:#4c51bf"></i><?= htmlspecialchars($c['Name']) ?></h1>
	</div>
	<div class="cr-sub">
		<?= $c['CourtDate'] ? date('F j, Y', strtotime($c['CourtDate'])) : 'Date TBD' ?>
		· <?= $c['ParkId'] > 0 ? htmlspecialchars($c['ParkName'] ?? 'Park') : htmlspecialchars($c['KingdomName'] ?? 'Kingdom') ?>
		<?php if (!empty($c['EventName'])): ?> · <?= htmlspecialchars($c['EventName']) ?><?php endif; ?>
	</div>

	<?php if (empty($Awards)): ?>
		<div class="cr-empty">No confirmed awards recorded for this court.</div>
	<?php else: ?>
		<table class="cr-table">
			<thead>
				<tr><th>Recipient</th><th>Award</th><th>Comments</th><th>Artisans</th></tr>
			</thead>
			<tbody>
				<?php foreach ($Awards as $a): ?>
				<tr>
					<td class="cr-recipient">
						<a href="<?= UIR ?>Playernew/index/<?= (int)$a['MundaneId'] ?>"><?= htmlspecialchars($a['Persona']) ?></a>
						<?php if (!empty($a['ParkAbbrev'])): ?><span class="cr-rank"><?= htmlspecialchars($a['ParkAbbrev']) ?></span><?php endif; ?>
					</td>
					<td>
						<?= htmlspecialchars($a['AwardName']) ?>
						<?php if ($a['IsLadder'] && $a['Rank'] > 0): ?><span class="cr-rank">Rank <?= (int)$a['Rank'] ?></span><?php endif; ?>
					</td>
					<td class="cr-comment">
						<?= !empty($a['PublicComment']) ? nl2br(htmlspecialchars($a['PublicComment'])) : '<span class="cr-none">—</span>' ?>
					</td>
					<td>
						<?php
							$has = false;
							if (!empty($a['ScrollMakerPersona'])): $has = true; ?>
							<span class="cr-maker">Scroll: <?= htmlspecialchars($a['ScrollMakerPersona']) ?></span>
						<?php endif; ?>
						<?php if (!empty($a['RegaliaMakerPersona'])): $has = true; ?>
							<span class="cr-maker">Regalia: <?= htmlspecialchars($a['RegaliaMakerPersona']) ?></span>
						<?php endif; ?>
						<?php foreach ($a['Artisans'] as $ar): $has = true; ?>
							<span class="cr-artisan"><?= htmlspecialchars($ar['Persona']) ?><?php if (!empty($ar['Contribution'])): ?><span class="cr-artisan-role"> — <?= htmlspecialchars($ar['Contribution']) ?></span><?php endif; ?></span>
						<?php endforeach; ?>
						<?php if (!$has): ?><span class="cr-none">—</span><?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/template/default/Reports_court.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/Reports_court.tpl
git commit -m "Court Report: detail view template

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Report-grid links on Kingdom & Park profiles

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl`
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl`

- [ ] **Step 1: Kingdomnew — add Court Report link**

Find (in the Awards group, ~line 656):
```php
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>">Custom Awards</a></li>
```
Replace with (adds the Court Report link after Custom Awards):
```php
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>">Custom Awards</a></li>
							<li><a href="<?= UIR ?>Reports/courts&KingdomId=<?= $kingdom_id ?>"><i class="fas fa-gavel"></i> Court Report</a></li>
```

- [ ] **Step 2: Parknew — add Court Report link**

Find (in the Awards group, ~line 986):
```php
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Custom Awards</a></li>
```
Replace with:
```php
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Custom Awards</a></li>
							<li><a href="<?= UIR ?>Reports/courts&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>"><i class="fas fa-gavel"></i> Court Report</a></li>
```

- [ ] **Step 3: Lint both**

Run: `php -l orkui/template/revised-frontend/Kingdomnew_index.tpl && php -l orkui/template/revised-frontend/Parknew_index.tpl`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Court Report: add report-grid links on Kingdom and Park profiles

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: End-to-end verification (curl + DB + dark mode)

**Files:** none (verification only)

- [ ] **Step 1: Seed/confirm test data**

Find a court with given awards; if none exist, create one for testing.
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT ca.court_id, c.kingdom_id, c.park_id, ca.court_award_id, ca.status
 FROM ork_court_award ca JOIN ork_court c ON c.court_id = ca.court_id
 ORDER BY ca.court_award_id;"
```
If no row has `status='given'`, pick one court_award row and seed it:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"UPDATE ork_court_award SET status='given',
   public_comment='For outstanding service to the realm.'
 WHERE court_award_id = (SELECT * FROM (SELECT MIN(court_award_id) FROM ork_court_award) x);"
```
Note the resulting `court_id` and its `kingdom_id`/`park_id`.

- [ ] **Step 2: Curl the list route and confirm the court appears**

Run (substitute `<K>` with the kingdom_id from Step 1):
```bash
curl -s "http://localhost:19080/orkui/index.php?Route=Reports/courts&KingdomId=<K>" | grep -c "cr-court"
```
Expected: ≥ 1 (the court card rendered). Also confirm the page title:
```bash
curl -s "http://localhost:19080/orkui/index.php?Route=Reports/courts&KingdomId=<K>" | grep -o "Court Report" | head -1
```
Expected: `Court Report`.

- [ ] **Step 3: Curl the detail route and confirm recipient/award/comment**

Run (substitute `<C>` with the court_id):
```bash
curl -s "http://localhost:19080/orkui/index.php?Route=Reports/court&CourtId=<C>" | grep -E "cr-recipient|cr-comment|For outstanding service"
```
Expected: recipient cell + the seeded public comment text appear.

- [ ] **Step 4: Confirm the six-month default excludes old courts**

```bash
curl -s "http://localhost:19080/orkui/index.php?Route=Reports/courts&KingdomId=<K>&From=2000-01-01&Until=2000-12-31" | grep -c "No courts with confirmed awards"
```
Expected: `1` (empty-state for a range with no courts).

- [ ] **Step 5: Confirm the report-grid link is present**

```bash
curl -s "http://localhost:19080/orkui/index.php?Route=Kingdom/profile/<K>&tab=reports" | grep -c "Reports/courts&KingdomId"
```
Expected: ≥ 1.

- [ ] **Step 6: Dark-mode + planner walk-through (browser)**

Using Claude-in-Chrome (verification of the implemented feature is the allowed use):
1. Open the list and detail routes above in **dark mode** (`html[data-theme="dark"]`). Confirm: headings have no gray pill box, filter inputs/labels/cards are legible, badges readable, empty-state legible.
2. Confirm flatpickr shows human-readable dates ("May 28, 2026"), not raw ISO.
3. Open a court in the **Court Planner** (`Court/detail/<C>`), expand an award, confirm the new **Public Comment** field appears below Internal Notes, save it, reload, and confirm the value persists and shows on the Court Report detail.
4. Use the planner's **Add ad-hoc award** modal; confirm the Public Comment field is present and saves.

- [ ] **Step 7: Clean up seed data (only if you created it in Step 1)**

If you flipped a row to `given` purely for testing and it should not stay given, revert it:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"UPDATE ork_court_award SET status='planned', public_comment=NULL WHERE court_award_id = <seeded_id>;"
```
(Skip if the data was already legitimately `given`.)

---

## Self-Review (completed by plan author)

- **Spec coverage:** routes (Task 5), date filter + 6-month default (Tasks 5/6), list of courts with given awards (Task 2 `getCourtReportList`), park scope = own courts + recipient-home-park (Task 2 SQL), detail with player/award/comments/artisans (Tasks 2/7), public comment field migration + planner UI (Tasks 1/3/4), grid links (Task 8), public access (Task 5 `$public_reports`), dark mode + human-readable dates (Tasks 6/7). All covered.
- **Naming consistency:** `public_comment` (DB), `PublicComment` (PHP/JSON/JS), `getCourtReportList`/`getCourtReportDetail`, ids `cp-pubcomment-*`/`cp-adhoc-pubcomment`, templates `Reports_courts.tpl`/`Reports_court.tpl`, routes `Reports/courts`/`Reports/court` — consistent across tasks.
- **No placeholders:** every code/SQL/command step is concrete.
- **Deviation from spec noted:** controller calls `Ork3::$Lib->court` directly (matching `controller.Court.php`) rather than threading through `Model_Reports`, because `Model_Reports->Report` is the SOAP `APIModel('Report')` and does not reach the court domain class. This keeps DB work in `system/lib/ork3/` as required.
