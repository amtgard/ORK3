# Court Herald Script Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the Court Planner's existing "Court Script" print feature into a herald script with a per-court density toggle (Compact checklist ↔ Citation blocks), using public comment + artisans as the citation, available in any court status.

**Architecture:** All work is in one file, `orkui/template/default/Court_detail.tpl`. The planner already emits the full `getCourtAwards()` result to the client as `window.courtAwards` (line ~1365), so there is **no backend change** — no controller, query, route, template, or schema change. We enhance the existing `cpOpenScript()` / `#cp-script-overlay` in place: convert the print-only overlay into an on-screen preview modal with a density toggle, rewrite the renderer to build Compact and Citation layouts from `window.courtAwards`, and update the print CSS to show only the rendered script.

**Tech Stack:** PHP `.tpl` (plain PHP via `extract()`+`include`, **not** Smarty), inline CSS + vanilla JS, `cp-` class prefix (existing planner convention), `esc()` helper (already defined in the planner JS).

**Verification model:** This codebase has no PHP/JS unit-test framework for templates; verification is `php -l` (run in the `ork3-php8-app` container) after each structural edit, plus a browser print + dark-mode walkthrough on an environment where the court schema exists (the local `ork` DB does **not** have the court tables, so the route cannot be exercised locally). Each task ends with a lint check; Task 5 is the manual walkthrough + commit.

---

## File Structure

- **Modify only:** `orkui/template/default/Court_detail.tpl`
  - The Court Script button (~line 916): remove the `published`/`complete`-only gate.
  - The CSS print block + old script-table styles (~lines 247–259): replace with new modal + compact + citation + dark-mode + print CSS.
  - The `#cp-script-overlay` HTML (~lines 2362–2370): replace with the preview-modal structure.
  - The `cpOpenScript()` JS (~lines 2309–2359): replace with the new open/close/density/render functions.

No other files change. The earlier bug-fix work (`set_award_status`, `CourtAwardId` in the court map) is unrelated and already in the working tree.

---

### Task 1: Un-gate the Court Script button (render in all statuses)

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl` (~line 915–919)

- [ ] **Step 1: Remove the published/complete-only wrapper around the button**

Replace this exact block:

```php
        <?php if (in_array($courtSt, ['published', 'complete'])): ?>
        <button class="cp-btn cp-btn-outline" id="cp-script-btn" onclick="cpOpenScript()">
            <i class="fas fa-scroll"></i> Court Script
        </button>
        <?php endif; ?>
```

with (gate removed; button always renders inside the already-`canManage`-gated planner):

```php
        <button class="cp-btn cp-btn-outline" id="cp-script-btn" onclick="cpOpenScript()">
            <i class="fas fa-scroll"></i> Court Script
        </button>
```

- [ ] **Step 2: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/default/Court_detail.tpl`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court Herald Script: show Court Script button in all court statuses"
```

---

### Task 2: Replace the script CSS (modal, compact, citation, dark, print)

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl` (~lines 247–259)

- [ ] **Step 1: Replace the existing print block + old script-table styles**

Replace this exact block (the `@media print` rule, the `#cp-script-overlay { display:none }`, and the old `.cp-script-header` / `.cp-script-table` / `.cp-script-td-*` rules):

```css
@media print {
    body > *:not(#cp-script-overlay) { display: none !important; }
    #cp-script-overlay { display: block !important; font-family: Georgia, serif; padding: 16px 24px; }
}
#cp-script-overlay { display: none; }
.cp-script-header { text-align: center; margin-bottom: 14px; border-bottom: 2px solid #333; padding-bottom: 10px; }
.cp-script-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.cp-script-table td { padding: 4px 8px 4px 0; vertical-align: top; border-bottom: 1px solid #eee; line-height: 1.35; }
.cp-script-table td:first-child { color: #aaa; font-size: 10px; width: 24px; white-space: nowrap; padding-right: 6px; }
.cp-script-td-name { font-weight: 700; font-size: 13px; white-space: nowrap; width: 18%; }
.cp-script-td-award { color: #2d3748; width: 20%; }
.cp-script-td-rec { color: #4a5568; font-style: italic; }
.cp-script-td-rec strong { font-style: normal; color: #2d3748; }
```

with:

```css
/* ---- Court Script preview modal ---- */
.cp-script-overlay { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,.5); display: flex; align-items: flex-start; justify-content: center; padding: 30px 16px; overflow: auto; }
.cp-script-overlay[hidden] { display: none; }
.cp-script-modal { background: #fff; color: #1a202c; width: 100%; max-width: 760px; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.3); overflow: hidden; }
.cp-script-chrome { padding: 18px 22px; border-bottom: 1px solid #e2e8f0; }
.cp-script-titlebar { text-align: center; margin-bottom: 12px; }
.cp-script-h1 { font-size: 22px; font-weight: 700; margin: 0; background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; color: #1a202c; }
.cp-script-date { color: #718096; margin: 4px 0 0; font-size: 13px; }
.cp-script-controls { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.cp-script-density { display: inline-flex; border: 1px solid #cbd5e0; border-radius: 6px; overflow: hidden; }
.cp-script-density button { border: none; background: #fff; color: #4a5568; font-size: 13px; font-weight: 600; padding: 6px 14px; cursor: pointer; }
.cp-script-density button + button { border-left: 1px solid #cbd5e0; }
.cp-script-density button.active { background: #2c5282; color: #fff; }
.cp-script-actions { display: flex; gap: 8px; }
.cp-script-body { padding: 20px 24px; font-family: Georgia, serif; max-height: 60vh; overflow: auto; }
.cp-script-empty { color: #718096; text-align: center; padding: 20px; }
/* compact density */
.cp-script-compact { width: 100%; border-collapse: collapse; font-size: 13px; }
.cp-script-compact td { padding: 5px 8px 5px 0; vertical-align: top; border-bottom: 1px solid #eee; line-height: 1.35; }
.cp-script-num { color: #a0aec0; font-size: 11px; width: 26px; white-space: nowrap; font-variant-numeric: tabular-nums; }
.cp-script-check { width: 22px; font-size: 16px; line-height: 1; }
.cp-script-recip { font-weight: 700; white-space: nowrap; width: 38%; }
.cp-script-award { color: #2d3748; }
.cp-script-park { color: #718096; font-weight: 400; font-size: 11px; margin-left: 4px; }
.cp-script-ptl { color: #b7791f; font-size: 11px; font-style: italic; }
/* citation density */
.cp-script-cite { padding: 10px 0; border-bottom: 1px solid #eee; }
.cp-script-cite-head { font-size: 14px; line-height: 1.4; }
.cp-script-cite-num { color: #a0aec0; font-size: 12px; }
.cp-script-cite-recip { font-weight: 700; }
.cp-script-cite-award { color: #2d3748; }
.cp-script-cite-text { margin-top: 4px; color: #2d3748; line-height: 1.5; }
.cp-script-cite-artisans { margin-top: 4px; color: #4a5568; font-size: 13px; }
.cp-script-cite-artisans strong { color: #2d3748; }
/* dark mode (on-screen preview only) */
html[data-theme="dark"] .cp-script-modal { background: #161b22; color: #e2e8f0; }
html[data-theme="dark"] .cp-script-chrome { border-color: #2d3748; }
html[data-theme="dark"] .cp-script-h1 { color: #e2e8f0; }
html[data-theme="dark"] .cp-script-density { border-color: #2d3748; }
html[data-theme="dark"] .cp-script-density button { background: #1f2733; color: #cbd5e0; }
html[data-theme="dark"] .cp-script-density button + button { border-color: #2d3748; }
html[data-theme="dark"] .cp-script-density button.active { background: #2b6cb0; color: #fff; }
html[data-theme="dark"] .cp-script-compact td,
html[data-theme="dark"] .cp-script-cite { border-color: #2d3748; }
html[data-theme="dark"] .cp-script-recip,
html[data-theme="dark"] .cp-script-award,
html[data-theme="dark"] .cp-script-cite-recip,
html[data-theme="dark"] .cp-script-cite-award,
html[data-theme="dark"] .cp-script-cite-text { color: #e2e8f0; }
html[data-theme="dark"] .cp-script-cite-artisans { color: #a0aec0; }
/* print: show only the rendered script body, force light, avoid page breaks mid-entry */
@media print {
    body.cp-script-open > *:not(#cp-script-overlay) { display: none !important; }
    body.cp-script-open #cp-script-overlay { position: static; display: block !important; background: #fff; padding: 0; overflow: visible; }
    body.cp-script-open #cp-script-overlay .cp-script-modal { max-width: none; width: auto; margin: 0; box-shadow: none; border-radius: 0; background: #fff; color: #000; }
    body.cp-script-open .cp-script-controls { display: none !important; }
    body.cp-script-open .cp-script-chrome { border-bottom: 2px solid #333; padding: 0 0 10px; }
    body.cp-script-open .cp-script-body { max-height: none; overflow: visible; padding: 14px 0 0; color: #000; }
    body.cp-script-open .cp-script-cite,
    body.cp-script-open .cp-script-compact tr { break-inside: avoid; }
    body.cp-script-open .cp-script-h1,
    body.cp-script-open .cp-script-recip,
    body.cp-script-open .cp-script-award,
    body.cp-script-open .cp-script-cite-recip,
    body.cp-script-open .cp-script-cite-award,
    body.cp-script-open .cp-script-cite-text { color: #000; }
    @page { margin: 0.6in; }
}
```

- [ ] **Step 2: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/default/Court_detail.tpl`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court Herald Script: modal/compact/citation/dark/print CSS"
```

---

### Task 3: Replace the overlay HTML with the preview-modal structure

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl` (~lines 2362–2370)

- [ ] **Step 1: Replace the overlay markup**

Replace this exact block:

```html
<div id="cp-script-overlay">
    <div class="cp-script-header">
        <h1 id="cp-script-title" style="background:transparent;border:none;padding:0;border-radius:0;text-shadow:none"></h1>
        <p id="cp-script-date" style="color:#718096;margin:4px 0 0"></p>
    </div>
    <table class="cp-script-table">
        <tbody id="cp-script-awards"></tbody>
    </table>
</div>
```

with:

```html
<div id="cp-script-overlay" class="cp-script-overlay" hidden onclick="if(event.target===this)cpCloseScript()">
    <div class="cp-script-modal">
        <div class="cp-script-chrome">
            <div class="cp-script-titlebar">
                <h1 id="cp-script-title" class="cp-script-h1"></h1>
                <p id="cp-script-date" class="cp-script-date"></p>
            </div>
            <div class="cp-script-controls">
                <div class="cp-script-density" role="group" aria-label="Script density">
                    <button type="button" data-density="compact" class="active" onclick="cpSetScriptDensity('compact')">Compact</button>
                    <button type="button" data-density="citation" onclick="cpSetScriptDensity('citation')">Citation</button>
                </div>
                <div class="cp-script-actions">
                    <button type="button" class="cp-btn cp-btn-outline cp-btn-sm" onclick="cpCloseScript()">Close</button>
                    <button type="button" class="cp-btn cp-btn-primary cp-btn-sm" onclick="cpPrintScript()">Print</button>
                </div>
            </div>
        </div>
        <div id="cp-script-body" class="cp-script-body"></div>
    </div>
</div>
```

- [ ] **Step 2: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/default/Court_detail.tpl`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court Herald Script: preview-modal markup with density toggle"
```

---

### Task 4: Rewrite the script JS (open/close/density/render builders)

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl` (~lines 2309–2359, the `// ---- Court Script ----` block through the end of `cpOpenScript()`)

- [ ] **Step 1: Replace the `cpOpenScript()` function block**

Replace the entire existing block, from the line:

```javascript
    // ---- Court Script ----
    function cpOpenScript() {
```

through the closing brace of `cpOpenScript()` (the line `    }` immediately before `</script>`), with:

```javascript
    // ---- Court Script ----
    var cpScriptDensity = 'compact';

    function cpScriptActiveAwards() {
        return (window.courtAwards || []).filter(function (a) { return a.Status !== 'cancelled'; });
    }
    function cpScriptAwardLabel(a) {
        var s = esc(a.AwardName || '');
        if (a.IsLadder && a.Rank) s += ' (Rank ' + a.Rank + ')';
        return s;
    }
    function cpScriptRecipient(a) {
        var s = esc(a.Persona || '');
        if (a.ParkAbbrev) s += ' <span class="cp-script-park">' + esc(a.ParkAbbrev) + '</span>';
        return s;
    }
    function cpScriptPtlMark(a) {
        return a.PassToLocal ? ' <span class="cp-script-ptl">(pass to local)</span>' : '';
    }
    function cpScriptArtisans(a) {
        var parts = [];
        if (a.ScrollMakerPersona)  parts.push(esc(a.ScrollMakerPersona) + ' (scroll)');
        if (a.RegaliaMakerPersona) parts.push(esc(a.RegaliaMakerPersona) + ' (regalia)');
        (a.Artisans || []).forEach(function (art) {
            var p = esc(art.Persona || '');
            if (art.Contribution) p += ' (' + esc(art.Contribution) + ')';
            if (p) parts.push(p);
        });
        return parts.join(', ');
    }
    function cpScriptCompact(awards) {
        if (!awards.length) return '<p class="cp-script-empty">No awards to present.</p>';
        var rows = awards.map(function (a, i) {
            return '<tr>' +
                '<td class="cp-script-num">' + (i + 1) + '</td>' +
                '<td class="cp-script-check">' + (a.Status === 'given' ? '☑' : '☐') + '</td>' +
                '<td class="cp-script-recip">' + cpScriptRecipient(a) + '</td>' +
                '<td class="cp-script-award">' + cpScriptAwardLabel(a) + cpScriptPtlMark(a) + '</td>' +
                '</tr>';
        }).join('');
        return '<table class="cp-script-compact"><tbody>' + rows + '</tbody></table>';
    }
    function cpScriptCitation(awards) {
        if (!awards.length) return '<p class="cp-script-empty">No awards to present.</p>';
        return awards.map(function (a, i) {
            var html = '<div class="cp-script-cite">' +
                '<div class="cp-script-cite-head">' +
                    '<span class="cp-script-cite-num">' + (i + 1) + '.</span> ' +
                    '<span class="cp-script-cite-recip">' + cpScriptRecipient(a) + '</span> ' +
                    '<span class="cp-script-cite-award">' + cpScriptAwardLabel(a) + cpScriptPtlMark(a) + '</span>' +
                '</div>';
            if (a.PublicComment) html += '<div class="cp-script-cite-text">' + esc(a.PublicComment) + '</div>';
            var art = cpScriptArtisans(a);
            if (art) html += '<div class="cp-script-cite-artisans"><strong>Artisans to thank:</strong> ' + art + '</div>';
            html += '</div>';
            return html;
        }).join('');
    }
    function cpRenderScript(density) {
        var body = document.getElementById('cp-script-body');
        if (!body) return;
        var awards = cpScriptActiveAwards();
        body.innerHTML = (density === 'citation') ? cpScriptCitation(awards) : cpScriptCompact(awards);
    }
    function cpSetScriptDensity(d) {
        cpScriptDensity = (d === 'citation') ? 'citation' : 'compact';
        document.querySelectorAll('.cp-script-density button').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-density') === cpScriptDensity);
        });
        cpRenderScript(cpScriptDensity);
    }
    function cpOpenScript() {
        var overlay = document.getElementById('cp-script-overlay');
        if (!overlay) return;
        var titleEl = document.getElementById('cp-script-title');
        var dateEl  = document.getElementById('cp-script-date');
        if (titleEl) titleEl.textContent = courtMeta.name || 'Court';
        if (dateEl) {
            if (courtMeta.date) {
                var d = new Date(courtMeta.date + 'T00:00:00');
                dateEl.textContent = d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            } else {
                dateEl.textContent = '';
            }
        }
        cpRenderScript(cpScriptDensity);
        // Move overlay to be a direct child of <body> so the print selector
        // (body.cp-script-open > *:not(#cp-script-overlay)) hides everything else.
        document.body.appendChild(overlay);
        document.body.classList.add('cp-script-open');
        overlay.hidden = false;
    }
    function cpCloseScript() {
        var overlay = document.getElementById('cp-script-overlay');
        if (overlay) overlay.hidden = true;
        document.body.classList.remove('cp-script-open');
    }
    function cpPrintScript() {
        window.print();
    }
```

Note: `esc()` and `courtMeta` are already defined in the surrounding planner JS scope and are reused unchanged. The old `#cp-script-awards` tbody is gone; rendering now targets `#cp-script-body`. The reason/notes (`RecReason` / `RecByPersona` / `Notes`) rendering is intentionally removed.

- [ ] **Step 2: Verify no dangling references to the removed ids/classes**

Run: `grep -n "cp-script-awards\|cp-script-table\|cp-script-header\|cp-script-td-\|RecReason\|RecByPersona" orkui/template/default/Court_detail.tpl`
Expected: **no matches** (every reference to the old script structure is gone).

- [ ] **Step 3: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/default/Court_detail.tpl`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court Herald Script: density-aware renderer (public_comment + artisans)"
```

---

### Task 5: Verification walkthrough

**Files:** none (manual verification).

> Cannot be exercised on the local `ork` DB (no court tables). Run this on an environment where the court schema exists, or defer to QA. If unavailable, record that backend-dependent steps are unverified rather than claiming success.

- [ ] **Step 1: Open the planner for a court with awards** — `Court/detail/{court_id}` as a user with `canManage`. Confirm the **Court Script** button shows even when the court is in **draft**.

- [ ] **Step 2: Open the script** — click Court Script. The preview modal appears with the court name + human-readable date and a **Compact / Citation** toggle, defaulting to Compact.

- [ ] **Step 3: Compact contents** — verify each row: number, a checkbox (☑ for awards whose `Status === 'given'`, else ☐), recipient persona + park abbrev, award name + `(Rank N)` only for ladder awards, and `(pass to local)` on flagged awards.

- [ ] **Step 4: Citation contents** — toggle to Citation. Each block shows recipient, award + rank, the **public comment** as the citation (absent when empty), and an **"Artisans to thank:"** line assembled from scroll maker / regalia maker / contributors (absent when there are none). Confirm **no** recommendation reason or internal notes appears anywhere.

- [ ] **Step 5: Print** — click Print (or browser print with the modal open). Use print preview: only the rendered script prints (no toolbar, no planner chrome), white background / black text even in dark mode, and entries don't split across page breaks.

- [ ] **Step 6: Dark mode** — toggle the app to dark mode and re-open the modal. Header, density toggle, compact table, and citation blocks are all readable; print preview still forces light.

- [ ] **Step 7: Empty court** — on a court with no non-cancelled awards, the modal body shows "No awards to present."

- [ ] **Step 8: Final commit (if any fixes were needed during verification)**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court Herald Script: verification fixes"
```

---

## Self-Review

**Spec coverage:**
- Density toggle (compact/citation) → Tasks 2–4. ✓
- Citation = public comment + artisans, no reason/notes → Task 4 (`cpScriptCitation`, removal verified in Task 4 Step 2). ✓
- Compact checklist with tick-as-given + pass-to-local marker → Task 4 (`cpScriptCompact`). ✓
- Available in all statuses → Task 1. ✓
- Print clean to 8.5×11, force light, no mid-entry breaks → Task 2 print CSS. ✓
- On-screen dark-mode preview → Task 2 dark rules. ✓
- No backend change → confirmed; plan touches only `Court_detail.tpl`. ✓
- Empty state → Task 4 builders + Task 5 Step 7. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code. ✓

**Type/name consistency:** Renderer ids/classes (`cp-script-body`, `cp-script-overlay`, `cp-script-density`, `.active`, `cp-script-compact`, `cp-script-cite*`) match across the CSS (Task 2), HTML (Task 3), and JS (Task 4). Function names (`cpOpenScript`, `cpCloseScript`, `cpSetScriptDensity`, `cpPrintScript`, `cpRenderScript`) match their `onclick` handlers in the Task 3 markup. ✓
