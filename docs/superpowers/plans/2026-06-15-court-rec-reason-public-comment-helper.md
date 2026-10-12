# Court Rec-Reason Public-Comment Helper — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On `Court/detail/{id}`, when an award came from a recommendation that had a reason, show that reason as gray helper text inside the empty Public Comment box with **(Start from Rec)** and **(Clear)** buttons — never counting the reason as the public comment until the user affirmatively engages.

**Architecture:** Pure front-end behavior in `Court_detail.tpl` plus one small AJAX-response addition in `controller.CourtAjax.php`. A gray overlay (`pointer-events:none`) sits over an empty textarea; inline `onfocus`/button handlers fill or clear the real value and hide the overlay. Because the textarea value stays empty until engagement, all downstream consumers (save → `public_comment`, Court Report, Grant) already treat untouched rows as having no comment.

**Tech Stack:** PHP 8 (`.tpl` = plain PHP via extract+include — NOT Smarty), vanilla JS, inline CSS in the template `<style>` block, MariaDB (no schema change — branch migrations already applied).

**Verification model:** This codebase has no JS/template unit-test harness. Verify via `php -l` lint, `grep` for emitted artifacts, a curl-authed page fetch, and a final Chrome check of the live interaction.

---

### Task 1: Add `RecReason` to the `add_award` AJAX response

So awards added live via the rec-picker modal (rendered by `cpAppendAwardRow`) can show the helper. Server-rendered rows already have `$aw['RecReason']` from `getCourtAwards`.

**Files:**
- Modify: `orkui/controller/controller.CourtAjax.php` — `add_award` method (response array ~line 170-189; `$rec_id` is set ~line 113).

- [ ] **Step 1: Normalize-first check (PHP file)**

Run: `awk '/^\t/{c++} END{print c+0}' orkui/controller/controller.CourtAjax.php`
- `0` → file is space-clean, use the Edit tool.
- non-zero → run `php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php orkui/controller/controller.CourtAjax.php` first, then Edit.

- [ ] **Step 2: Fetch the rec reason before building the response**

Immediately before the `$this->jsonOut(['status' => 0, 'award' => [` line (~170), insert:

```php
        $rec_reason = '';
        if ($rec_id) {
            $DB->Clear();
            $rr = $DB->DataSet('SELECT reason FROM ' . DB_PREFIX . 'recommendations WHERE recommendations_id = ' . (int)$rec_id . ' LIMIT 1');
            if ($rr && $rr->Next()) $rec_reason = $rr->reason ?? '';
        }
```

Note: `global $DB;` is already in scope in this method (used earlier for the insert). If a local `php -l` or read shows `$DB` is NOT already globalized in `add_award`, add `global $DB;` on the line above the snippet.

- [ ] **Step 3: Add `RecReason` to the response array**

In the `'award' => [ ... ]` array, after the `'PublicComment'     => $public_comment,` line, add:

```php
            'RecReason'         => $rec_reason,
```

- [ ] **Step 4: Lint**

Run: `php -l orkui/controller/controller.CourtAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Confirm `$DB` is in scope in `add_award`**

Run: `awk '/function add_award/,/^    }/' orkui/controller/controller.CourtAjax.php | grep -nE "global \$DB|\\\$DB->"`
Expected: at least one `$DB->` usage and a `global $DB;` somewhere in the method (the insert path). If `global $DB;` is absent, you added it in Step 2.

- [ ] **Step 6: Commit**

```bash
git add orkui/controller/controller.CourtAjax.php
git commit -m "Court planner: include RecReason in add_award response"
```

---

### Task 2: Helper UI + behavior in the Court detail template

Add CSS, replace both Public Comment render paths (PHP + JS) with the helper markup, and add the global JS handlers. All in one file, so one task.

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`
  - CSS `<style>` block (anchor near `.cp-notes-area` at ~line 115 and dark variants ~line 758)
  - PHP render path ~line 1079-1081
  - JS render path `cpAppendAwardRow` ~line 2177
  - JS handlers — add near other `window.*` award handlers (e.g. after `cpSaveAward`, ~line 1820)

- [ ] **Step 1: Normalize-first check (.tpl = plain PHP)**

Run: `awk '/^\t/{c++} END{print c+0}' orkui/template/default/Court_detail.tpl`
- `0` → use the Edit tool for all edits below.
- non-zero → use the Python `replace` fallback per project rule (the fixer targets `.php`, not `.tpl`):
  `python3 -c "import pathlib; p=pathlib.Path('orkui/template/default/Court_detail.tpl'); t=p.read_text(); print('found:', OLD in t); p.write_text(t.replace(OLD, NEW, 1))"`

- [ ] **Step 2: Add CSS for the overlay, wrapper, and buttons**

Insert after the `.cp-notes-area { ... }` rule (~line 115):

```css
.cp-pc-label-row { display: flex; align-items: center; gap: 8px; }
.cp-rec-hint-btn { background: none; border: none; padding: 0; cursor: pointer; font-size: 12px; color: #3182ce; line-height: 1; }
.cp-rec-hint-btn:hover { text-decoration: underline; }
.cp-pubcomment-wrap { position: relative; }
.cp-rec-hint { position: absolute; top: 1px; left: 1px; right: 1px; padding: 7px 10px; font-size: 13px; line-height: 1.35; color: #718096; font-style: italic; white-space: normal; overflow: hidden; pointer-events: none; box-sizing: border-box; max-height: calc(100% - 2px); }
```

Insert after the dark `.cp-notes-area::placeholder` rule (~line 759):

```css
html[data-theme="dark"] .cp-rec-hint { color: #a0aec0; }
html[data-theme="dark"] .cp-rec-hint-btn { color: #63b3ed; }
```

- [ ] **Step 3: Replace the PHP Public Comment render path**

Replace these lines (~1079-1081):

```php
                        <div class="cp-expand-label" style="margin-top:10px">Public Comment</div>
                        <textarea class="cp-notes-area" id="cp-pubcomment-<?= (int)$aw['CourtAwardId'] ?>"
                                  placeholder="Shown on the public Court Report…"><?= htmlspecialchars($aw['PublicComment'] ?? '') ?></textarea>
```

with:

```php
                        <?php
                        $pcRecReason = $aw['RecReason'] ?? '';
                        $pcSaved     = $aw['PublicComment'] ?? '';
                        $pcTriggered = $pcRecReason !== '' && $pcSaved === '';
                        $pcCaid      = (int)$aw['CourtAwardId'];
                        ?>
                        <div class="cp-pc-label-row" style="margin-top:10px">
                            <span class="cp-expand-label" style="margin-bottom:0">Public Comment</span>
                            <?php if ($pcTriggered): ?>
                            <button type="button" class="cp-rec-hint-btn" onclick="cpRecHintAction(<?= $pcCaid ?>,'start')">(Start from Rec)</button>
                            <button type="button" class="cp-rec-hint-btn" onclick="cpRecHintAction(<?= $pcCaid ?>,'clear')">(Clear)</button>
                            <?php endif; ?>
                        </div>
                        <div class="cp-pubcomment-wrap" id="cp-pcwrap-<?= $pcCaid ?>" data-rec-engaged="0">
                            <textarea class="cp-notes-area" id="cp-pubcomment-<?= $pcCaid ?>"
                                      placeholder="Shown on the public Court Report…"<?= $pcTriggered ? ' onfocus="cpRecHintFocus(' . $pcCaid . ')"' : '' ?>><?= htmlspecialchars($pcSaved) ?></textarea>
                            <?php if ($pcTriggered): ?>
                            <div class="cp-rec-hint" id="cp-rec-hint-<?= $pcCaid ?>"><?= htmlspecialchars($pcRecReason) ?></div>
                            <?php endif; ?>
                        </div>
```

- [ ] **Step 4: Replace the JS Public Comment render path**

In `cpAppendAwardRow`, the Internal-Notes/Public-Comment block currently is (~line 2177):

```javascript
            '<div><div class="cp-expand-label">Internal Notes</div><textarea class="cp-notes-area" id="cp-notes-' + aw.CourtAwardId + '" placeholder="Monarchy notes…">' + esc(aw.Notes || '') + '</textarea><div class="cp-expand-label" style="margin-top:10px">Public Comment</div><textarea class="cp-notes-area" id="cp-pubcomment-' + aw.CourtAwardId + '" placeholder="Shown on the public Court Report…">' + esc(aw.PublicComment || '') + '</textarea></div>' +
```

Replace it with (Internal Notes unchanged; Public Comment delegated to the helper):

```javascript
            '<div><div class="cp-expand-label">Internal Notes</div><textarea class="cp-notes-area" id="cp-notes-' + aw.CourtAwardId + '" placeholder="Monarchy notes…">' + esc(aw.Notes || '') + '</textarea>' + cpPubCommentFieldHtml(aw.CourtAwardId, aw.RecReason, aw.PublicComment) + '</div>' +
```

- [ ] **Step 5: Add the JS helper + handlers**

Add this block immediately after the end of `window.cpSaveAward = function(...) { ... };` (~line 1820, before `cpSaveOrder` usages are fine anywhere in the IIFE scope). Place it among the other `window.*` definitions:

```javascript
    // ---- Public Comment: rec-reason helper text ----
    function cpPubCommentFieldHtml(caid, recReason, publicComment) {
        recReason     = recReason || '';
        publicComment = publicComment || '';
        var triggered = recReason !== '' && publicComment === '';
        var buttons = triggered
            ? '<button type="button" class="cp-rec-hint-btn" onclick="cpRecHintAction(' + caid + ',\'start\')">(Start from Rec)</button>' +
              '<button type="button" class="cp-rec-hint-btn" onclick="cpRecHintAction(' + caid + ',\'clear\')">(Clear)</button>'
            : '';
        var taFocus = triggered ? ' onfocus="cpRecHintFocus(' + caid + ')"' : '';
        var hint = triggered
            ? '<div class="cp-rec-hint" id="cp-rec-hint-' + caid + '">' + esc(recReason) + '</div>'
            : '';
        return '<div class="cp-pc-label-row" style="margin-top:10px"><span class="cp-expand-label" style="margin-bottom:0">Public Comment</span>' + buttons + '</div>' +
            '<div class="cp-pubcomment-wrap" id="cp-pcwrap-' + caid + '" data-rec-engaged="0">' +
            '<textarea class="cp-notes-area" id="cp-pubcomment-' + caid + '" placeholder="Shown on the public Court Report…"' + taFocus + '>' + esc(publicComment) + '</textarea>' +
            hint + '</div>';
    }

    function cpRecHintEngage(caid) {
        var wrap = gid('cp-pcwrap-' + caid);
        var hint = gid('cp-rec-hint-' + caid);
        if (wrap) wrap.dataset.recEngaged = '1';
        if (hint) hint.style.display = 'none';
    }

    window.cpRecHintFocus = function(caid) {
        var wrap = gid('cp-pcwrap-' + caid);
        if (!wrap || wrap.dataset.recEngaged === '1') return;
        var ta   = gid('cp-pubcomment-' + caid);
        var hint = gid('cp-rec-hint-' + caid);
        if (ta && hint && !ta.value) ta.value = hint.textContent;
        cpRecHintEngage(caid);
    };

    window.cpRecHintAction = function(caid, action) {
        var ta   = gid('cp-pubcomment-' + caid);
        var hint = gid('cp-rec-hint-' + caid);
        if (!ta) return;
        if (action === 'start') {
            if (hint) ta.value = hint.textContent;   // explicit adopt (force)
            cpRecHintEngage(caid);
            ta.focus();
            ta.setSelectionRange(ta.value.length, ta.value.length);
        } else if (action === 'clear') {
            cpRecHintEngage(caid);
            ta.value = '';
            ta.focus();
        }
    };
```

- [ ] **Step 6: PHP lint**

Run: `php -l orkui/template/default/Court_detail.tpl`
Expected: `No syntax errors detected`

- [ ] **Step 7: Grep for the emitted artifacts**

Run: `grep -nc "cp-rec-hint\|cpRecHintAction\|cpPubCommentFieldHtml\|cp-pubcomment-wrap" orkui/template/default/Court_detail.tpl`
Expected: a count ≥ 8 (CSS rules + PHP markup + JS helper + JS render-path call).

- [ ] **Step 8: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court planner: rec-reason helper text + Start from Rec/Clear in Public Comment"
```

---

### Task 3: Live verification

**Files:** none (verification only).

- [ ] **Step 1: Find a court whose awards include a rec with a reason**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -N -e \
"SELECT ca.court_id, ca.court_award_id, LEFT(r.reason,40) FROM ork_court_award ca JOIN ork_recommendations r ON r.recommendations_id = ca.recommendations_id WHERE r.reason <> '' AND ca.public_comment = '' LIMIT 5;"
```
Expected: at least one row. Note its `court_id`. If none exists, pick any court_id with rec awards and temporarily seed a reason on one rec for testing (then leave it; it's local-only data).

- [ ] **Step 2: Curl-fetch the page (authed) and confirm markup renders**

Per the project curl-auth pattern (single cookie jar, login + fetch in one block; bypass accepts any password). Fetch `index.php?Route=Court/detail/{court_id}` and:
```bash
grep -o "cp-rec-hint" /tmp/court_detail.html | head; grep -o "cpRecHintAction" /tmp/court_detail.html | head
```
Expected: both strings present for the rec-derived, empty-public-comment award. Also confirm `docker logs ork3-php8-app` shows no new 500/PHP errors for the request.

- [ ] **Step 3: Chrome interaction check** (per project rule, Chrome is for post-implementation verification)

Open `Court/detail/{court_id}`, expand the rec-derived award, and confirm:
  - gray reason text shows in the empty Public Comment box; **(Start from Rec)** and **(Clear)** appear beside the label;
  - clicking into the box fills it with the reason (overlay gone), editable;
  - **(Start from Rec)** fills the reason and puts the cursor at the end;
  - **(Clear)** empties the box, cursor in box, overlay does not return;
  - a fresh award added via the rec-picker modal shows the same behavior;
  - an award with **no** rec reason (and the ad-hoc add) shows a plain box, no buttons/overlay;
  - dark mode: overlay text and buttons are legible.

- [ ] **Step 4: Confirm untouched = empty is honored**

Expand a rec award, edit only **Status** (leave Public Comment untouched), Save. Then:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -N -e \
"SELECT public_comment FROM ork_court_award WHERE court_award_id = {that_caid};"
```
Expected: empty string. (Court Report would render "—".)

---

## Self-Review notes

- **Spec coverage:** helper text (Task 2 §2-3), buttons (§3-5), state model incl. click-to-adopt / Start / Clear (§5 handlers), hide-when-filled (`$pcTriggered` gate, both paths), JS-added rows (Task 1 + `cpPubCommentFieldHtml`), untouched=empty (no save change; verified Task 3 §4). ✔
- **Naming consistency:** `cpRecHintFocus`, `cpRecHintAction`, `cpRecHintEngage`, `cpPubCommentFieldHtml`, ids `cp-pcwrap-{caid}` / `cp-pubcomment-{caid}` / `cp-rec-hint-{caid}` used identically in PHP and JS. ✔
- **No schema change:** branch migrations already applied; `public_comment` defaults empty for rec awards. ✔
