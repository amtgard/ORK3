# Court "Send to Local Park" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On Kingdom/Principality court planning, replace the per-award "Pass to Local" checkbox with a "Send to Local Park" button that marks the recipient's recommendation `passed_to_local` and removes the award from this court; also convert the From-Recommendation flag's native tooltip to `data-tip`.

**Architecture:** New `CourtAjax/pass_award_to_local` endpoint reuses the existing `set_recommendation_passed_to_local` pipe then deletes the court award (mirroring `remove_award`). Front-end (in `Court_detail.tpl`) shows the button only on kingdom-scoped courts for rec-backed awards, confirms via guarded `tnConfirm`, and removes the row on success.

**Tech Stack:** PHP 8 (`.tpl` = plain PHP via extract+include, NOT Smarty), vanilla JS, inline CSS in the template `<style>`, MariaDB (no schema change).

**Verification model:** No JS/template unit harness — verify via `php -l`, `grep`, curl-authed fetch, DB assertions, and a Chrome interaction check (post-implementation).

**Concurrency note:** A separate effort is editing the park-side flag in this same file. Stage `Court_detail.tpl` EXPLICITLY, run `git diff --cached` before committing, and `git add -p` if foreign hunks appear. Never `git add -A`. Never stage `class.Authorization.php`.

---

### Task 1: Backend endpoint `pass_award_to_local`

**Files:**
- Modify: `orkui/controller/controller.CourtAjax.php` — add a method after `remove_award` (~line 225).

- [ ] **Step 1: Normalize-first check**

Run: `awk '/^\t/{c++} END{print c+0}' orkui/controller/controller.CourtAjax.php`
- `0` → use Edit tool. Non-zero → `php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php orkui/controller/controller.CourtAjax.php` first, then Edit.

- [ ] **Step 2: Add the endpoint**

Insert immediately after the closing `}` of `remove_award` (after the `$this->jsonOut(['status' => 0]);` that ends `remove_award`, ~line 225):

```php

    // -----------------------------------------------------------------------
    // pass_award_to_local
    // POST: CourtAwardId
    // Kingdom/principality-side action: hand a rec-backed award down to the
    // recipient's local park (sets recommendations.passed_to_local via the shared
    // pipe) and remove it from this court. Pipe runs first; delete only on success.
    // -----------------------------------------------------------------------
    public function pass_award_to_local($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }

        global $DB;
        $DB->Clear();
        $r = $DB->DataSet('SELECT court_id, recommendations_id, mundane_id
                            FROM ' . DB_PREFIX . 'court_award
                            WHERE court_award_id = ' . $court_award_id . ' LIMIT 1');
        if (!$r || !$r->Next()) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }
        $court_id = (int)$r->court_id;
        $rec_id   = (int)$r->recommendations_id;

        $this->requireCourtAuth($court_id);

        if (!$rec_id) {
            $this->jsonOut(['status' => 1, 'error' => 'This award is not from a recommendation, so it cannot be passed to local.']);
        }

        $this->load_model('Player');
        $res = $this->Player->set_recommendation_passed_to_local([
            'Token'             => $this->session->token,
            'RecommendationsId' => $rec_id,
            'Passed'            => 1,
        ]);
        if (($res['Status'] ?? 1) != 0) {
            $this->jsonOut(['status' => 3, 'error' => 'Could not pass this award to local: ' . ($res['Error'] ?? 'Not authorized.')]);
        }

        $DB->Clear();
        $DB->Execute('DELETE FROM ' . DB_PREFIX . 'court_award_artisan WHERE court_award_id = ' . $court_award_id);
        $DB->Clear();
        $DB->Execute('DELETE FROM ' . DB_PREFIX . 'court_award WHERE court_award_id = ' . $court_award_id);

        $this->jsonOut(['status' => 0]);
    }
```

- [ ] **Step 3: Verify `requireCourtAuth`, `session->token`, and the model method exist**

Run:
```bash
grep -n "function requireCourtAuth" orkui/controller/controller.CourtAjax.php
grep -n "session->token" orkui/controller/controller.CourtAjax.php
grep -n "function set_recommendation_passed_to_local" orkui/model/model.Player.php
```
Expected: all three present (the first two are already used by `grant_award`/other endpoints; the third is the model wrapper).

- [ ] **Step 4: Lint**

Run: `php -l orkui/controller/controller.CourtAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.CourtAjax.php
git commit -m "Court planner: add pass_award_to_local endpoint (kingdom-side send-to-local)"
```

---

### Task 2: Front-end button, handler, tooltip (Court_detail.tpl)

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`
  - CSS `<style>` (near existing `[data-tip]` rule ~line 182)
  - JS court-scope flag (near `var courtId` ~line 1445)
  - PHP expand-panel control (~lines 1108-1116)
  - JS render control in `cpAppendAwardRow` (~line 2260)
  - `cpSaveAward` PTL guard (~line 1784)
  - From-Recommendation flag tooltip (PHP ~1062, JS ~2229)
  - New JS handlers (near `cpRemoveAward`, ~line 1700-1720 region — place after `cpSaveAward`)

- [ ] **Step 1: Normalize-first check**

Run: `awk '/^\t/{c++} END{print c+0}' orkui/template/default/Court_detail.tpl`
- `0` → use Edit tool. Non-zero → use the Python `replace` fallback (do NOT run php-cs-fixer on `.tpl`).

- [ ] **Step 2: Add CSS** (after the existing `.cp-rm-trash[data-tip]:hover::after` rule, ~line 183)

```css
.cp-flag-rec[data-tip] { position: relative; }
.cp-flag-rec[data-tip]:hover::after { content: attr(data-tip); position: absolute; top: 100%; right: 0; margin-top: 4px; width: max-content; max-width: 200px; white-space: normal; background: #2d3748; color: #fff; padding: 6px 8px; border-radius: 4px; font-size: 11px; line-height: 1.35; text-align: left; box-shadow: 0 2px 6px rgba(0,0,0,0.25); z-index: 50; pointer-events: none; }
html[data-theme="dark"] .cp-flag-rec[data-tip]:hover::after { background: #000; }
.cp-send-local-btn { position: relative; }
.cp-send-local-btn[data-tip]:hover::after { content: attr(data-tip); position: absolute; top: 100%; left: 0; margin-top: 4px; width: max-content; max-width: 240px; white-space: normal; background: #2d3748; color: #fff; padding: 6px 8px; border-radius: 4px; font-size: 11px; line-height: 1.35; text-align: left; box-shadow: 0 2px 6px rgba(0,0,0,0.25); z-index: 50; pointer-events: none; }
html[data-theme="dark"] .cp-send-local-btn[data-tip]:hover::after { background: #000; }
```

- [ ] **Step 3: Add the JS court-scope flag** (immediately after the `var courtId = ...;` line, ~1445)

```javascript
    var courtIsKingdom = <?= ($court['ParkId'] ?? 0) == 0 ? 'true' : 'false' ?>;
```

- [ ] **Step 4: Swap the PHP expand-panel control**

Replace exactly these lines (~1109-1116):

```php
                        <div class="cp-expand-label">Pass to Local</div>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:4px">
                            <input type="checkbox" id="cp-ptl-<?= (int)$aw['CourtAwardId'] ?>"
                                   <?= $aw['PassToLocal'] ? 'checked' : '' ?>
                                   style="width:auto">
                            <span style="font-size:13px;color:#4a5568">Kingdom approves — Park to give</span>
                        </label>
```

with:

```php
                        <?php if (($court['ParkId'] ?? 0) == 0): ?>
                            <?php if ($aw['RecommendationsId']): ?>
                            <div class="cp-expand-label">Pass to Local</div>
                            <button type="button" class="cp-btn-sm cp-btn-outline cp-send-local-btn" style="margin-top:4px"
                                    data-tip="Would you rather this award be given by their local park? Click here to remove from this Court and send to the local monarchy."
                                    onclick="cpSendToLocal(<?= (int)$aw['CourtAwardId'] ?>)"><i class="fas fa-arrow-down"></i> Send to Local Park</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="cp-expand-label">Pass to Local</div>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:4px">
                                <input type="checkbox" id="cp-ptl-<?= (int)$aw['CourtAwardId'] ?>"
                                       <?= $aw['PassToLocal'] ? 'checked' : '' ?>
                                       style="width:auto">
                                <span style="font-size:13px;color:#4a5568">Kingdom approves — Park to give</span>
                            </label>
                        <?php endif; ?>
```

- [ ] **Step 5: Swap the JS render control** in `cpAppendAwardRow` (~line 2260)

Find the line beginning `'<div><div class="cp-expand-label">Pass to Local</div><label ...` and replace ONLY the `<div class="cp-expand-label">Pass to Local</div><label ...></label>` portion (keep the surrounding `'<div>'` and the trailing `'<div style="margin-top:14px">...Status...'`). The full current line is:

```javascript
            '<div><div class="cp-expand-label">Pass to Local</div><label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:4px"><input type="checkbox" id="cp-ptl-' + aw.CourtAwardId + '" style="width:auto"' + (aw.PassToLocal ? ' checked' : '') + '><span style="font-size:13px;color:#4a5568">Kingdom approves — Park to give</span></label>' +
```

Replace it with:

```javascript
            '<div>' + cpPtlControlHtml(aw.CourtAwardId, aw.RecommendationsId, aw.PassToLocal) +
```

- [ ] **Step 6: Add the `cpPtlControlHtml` helper + `cpSendToLocal` + `cpRemoveAwardRow`**

Insert this block immediately after the end of `window.cpSaveAward = function(...) { ... };` (the same region used for the earlier rec-hint helpers):

```javascript
    // ---- Pass to Local control + Send-to-Local action (kingdom/principality courts) ----
    function cpPtlControlHtml(caid, recId, passToLocal) {
        if (courtIsKingdom) {
            if (!recId) return '';   // ad-hoc award on a kingdom court: no pass-to-local control
            return '<div class="cp-expand-label">Pass to Local</div>' +
                '<button type="button" class="cp-btn-sm cp-btn-outline cp-send-local-btn" style="margin-top:4px" ' +
                'data-tip="Would you rather this award be given by their local park? Click here to remove from this Court and send to the local monarchy." ' +
                'onclick="cpSendToLocal(' + caid + ')"><i class="fas fa-arrow-down"></i> Send to Local Park</button>';
        }
        return '<div class="cp-expand-label">Pass to Local</div>' +
            '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:4px">' +
            '<input type="checkbox" id="cp-ptl-' + caid + '" style="width:auto"' + (passToLocal ? ' checked' : '') + '>' +
            '<span style="font-size:13px;color:#4a5568">Kingdom approves — Park to give</span></label>';
    }

    function cpRemoveAwardRow(caid) {
        var row = gid('cp-aw-' + caid);
        if (row) row.remove();
        var remaining = document.querySelectorAll('#cp-award-list .cp-award-row').length;
        if (remaining === 0 && !gid('cp-award-empty')) {
            var list = gid('cp-award-list');
            var empty = document.createElement('div');
            empty.className = 'cp-award-empty';
            empty.id = 'cp-award-empty';
            empty.innerHTML = '<i class="fas fa-award" style="font-size:28px;opacity:.3;margin-bottom:10px;display:block"></i>No awards planned yet.';
            list.appendChild(empty);
        }
        var cnt = gid('cp-award-count');
        if (cnt) cnt.textContent = '(' + remaining + ')';
        if (typeof cpRenumberRows === 'function') cpRenumberRows();
    }

    window.cpSendToLocal = function(caid) {
        var body = 'Would you rather this award be given by their local park? This removes it from this Court and sends it to the local monarchy.';
        function doSend() {
            var fd = new FormData();
            fd.append('CourtAwardId', caid);
            post('CourtAjax/pass_award_to_local', fd).then(function(d) {
                if (d.status === 0) {
                    cpRemoveAwardRow(caid);
                } else {
                    var msg = d.error || 'Could not send to local.';
                    if (typeof tnConfirm === 'function') tnConfirm({ title: 'Could not send to local', body: msg, confirmLabel: 'OK' });
                    else alert(msg);
                }
            });
        }
        if (typeof tnConfirm === 'function') {
            tnConfirm({ title: 'Send to local park?', body: body, confirmLabel: 'Send to Local', danger: true, onConfirm: doSend });
        } else {
            doSend();
        }
    };
```

- [ ] **Step 7: Guard `cpSaveAward`'s PTL read** (~line 1784)

Replace:

```javascript
        var ptl            = gid('cp-ptl-' + caid).checked ? 1 : 0;
```

with:

```javascript
        var ptlEl          = gid('cp-ptl-' + caid);
        var ptl            = ptlEl ? (ptlEl.checked ? 1 : 0) : 0;
```

- [ ] **Step 8: Convert the From-Recommendation tooltip** (both render paths)

PHP (~line 1062): replace `title="From Recommendation"` with `data-tip="Added from a recommendation."` in the `cp-flag-rec` span.

JS (~line 2229): in the `recBadge` assignment, replace `title="From Recommendation"` with `data-tip="Added from a recommendation."`.

Leave the `cp-flag-local` (down-arrow, `title="Pass to Local"`) badge untouched — it belongs to the park-side effort.

- [ ] **Step 9: Lint + grep**

Run:
```bash
php -l orkui/template/default/Court_detail.tpl
grep -nc "cpSendToLocal\|cpPtlControlHtml\|cp-send-local-btn\|courtIsKingdom" orkui/template/default/Court_detail.tpl
grep -n 'cp-flag-rec[^>]*data-tip' orkui/template/default/Court_detail.tpl
```
Expected: lint clean; first grep count ≥ 6; second grep shows the `data-tip` on both `cp-flag-rec` occurrences.

- [ ] **Step 10: Verify staged diff has no foreign hunks, then commit**

```bash
git add orkui/template/default/Court_detail.tpl
git diff --cached --stat
git diff --cached | grep -nE "cp-flag-local|Approved by Kingdom|park-side" || echo "no park-side flag hunks (good)"
git commit -m "Court planner: Send to Local Park button (kingdom-side) + From-Rec data-tip tooltip"
```
If the `grep` shows park-side flag changes you didn't make, a concurrent edit was swept in — unstage and use `git add -p` to stage only your hunks.

---

### Task 3: Live verification

**Files:** none.

- [ ] **Step 1: Identify a kingdom court and a park court**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT court_id, kingdom_id, park_id, IF(park_id=0,'KINGDOM','PARK') side, status FROM ork_court;"
```
Note a KINGDOM court id (park_id=0). If none has rec-backed awards, pick the kingdom court and check:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT court_award_id, recommendations_id FROM ork_court_award WHERE court_id={KINGDOM_COURT_ID};"
```

- [ ] **Step 2: Curl-authed render check (kingdom court)**

Login + fetch in one cookie-jar block (bypass accepts any password) for the kingdom court id. Confirm:
```bash
grep -c "Send to Local Park" /tmp/kc.html      # >=1 for rec-backed awards
grep -c "cpSendToLocal" /tmp/kc.html           # >=1
grep -oc 'cp-flag-rec[^>]*data-tip' /tmp/kc.html  # From-Rec tooltip converted
```
Confirm `docker logs ork3-php8-app` shows no new errors.

- [ ] **Step 3: Curl-authed render check (park court 2)**

Fetch `Court/detail/2`. Confirm the checkbox is still present and the button is NOT:
```bash
grep -c "Kingdom approves — Park to give" /tmp/pc.html   # >=1 (checkbox kept)
grep -c "Send to Local Park" /tmp/pc.html                # 0
```

- [ ] **Step 4: Exercise the action (synthetic, kingdom court)**

In Chrome (post-implementation), or via authed curl POST to `CourtAjax/pass_award_to_local` with a rec-backed `CourtAwardId` on the kingdom court, then assert:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT passed_to_local FROM ork_recommendations WHERE recommendations_id={REC_ID};"   # expect 1
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT COUNT(*) FROM ork_court_award WHERE court_award_id={CA_ID};"                    # expect 0
```
(Use a throwaway/last award, or re-add afterward, since this mutates local data.)

- [ ] **Step 5: Chrome interaction check**

On the kingdom court: expand a rec-backed award → "Send to Local Park" button with the tooltip on hover; click → `tnConfirm` modal → confirm → row disappears, count decrements. Ad-hoc award → no button. Hover the From-Recommendation star → `data-tip` tooltip (no native title). Dark mode legible.

---

## Self-Review notes

- **Spec coverage:** button kingdom-only + rec-backed (Task 2 §4-6), reuse pipe + remove (Task 1), tnConfirm guarded (Task 2 §6), cpSaveAward guard (§7), From-Rec data-tip (§8), park-side flag left alone (§8 note; Task 1 untouched). ✔
- **Naming consistency:** `cpSendToLocal`, `cpPtlControlHtml(caid, recId, passToLocal)`, `cpRemoveAwardRow`, `courtIsKingdom`, endpoint `pass_award_to_local`, model `set_recommendation_passed_to_local` — consistent across tasks. ✔
- **No schema change.** Reuses `recommendations.passed_to_local` + `court_award` delete. ✔
