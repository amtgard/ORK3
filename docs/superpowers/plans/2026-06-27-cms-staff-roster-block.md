# CMS staff_roster Block — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use `- [ ]`.

**Goal:** Add an authored "Staff Roster" CMS block — a responsive grid of people cards (photo, name, role, bio), each optionally linked to an Amtgard persona, with a block-level Mundane/Amtgard presentation toggle, plus an About/Team page-type preset.

**Architecture:** Mirrors the existing `card_grid` block end-to-end (catalog entry + starter + render partial + hand-built editor branch). Names are snapshotted at author time via an editor-only `CmsAjax/personlookup` call backed by `Ork3::$Lib->player->player_info()`; render does no DB work (no N+1). Player-search reuses `KingdomAjax/playersearch` in global (`scope=all`) mode.

**Tech Stack:** PHP 8 (plain `.tpl` = PHP via extract+include), vanilla JS editor, CSS with `html[data-theme="dark"]` dark mode.

## Global Constraints
- `.tpl` files are PLAIN PHP — `<?php ?>`/`<?= ?>`, never Smarty.
- All render output `htmlspecialchars(..., ENT_QUOTES)`. URLs via `CmsSanitizer::IsSafeUrl()`.
- FontAwesome 5.8.2 icon names only (`fa-users`, `fa-user` are valid).
- Dark-mode required: selector `html[data-theme="dark"]`.
- Player-search URL built with `&q=` (UIR already ends in `?Route=`); global search = `KingdomAjax/playersearch/0&scope=all`.
- Define `tnFixedAcPosition(input, dropdown)` locally (it exists nowhere); guard the editor IIFE with a config flag, not `getElementById`.
- No unit-test harness in this repo — each task verifies via `php -l` + curl + browser render.
- Field contract (every task uses these exact keys): block fields `kicker, heading, subheading, presentation('amtgard'|'mundane'), people[]`; person `image{src,alt}, persona_name, mundane_name, role, bio, mundane_id(int), href`.

---

### Task 1: Render partial + catalog + starter + CSS  (file group: controller.Cms.php is Task 3 — this task owns the partial + CSS + catalog/starter ONLY via separate edits)

**Files:**
- Create: `orkui/template/default/frontdoor/blocks/staff_roster.tpl`
- Modify: `orkui/template/default/frontdoor/css/frontdoor.css` (append roster styles)

> NOTE: catalog (`_blockCatalog`) + starter (`_starter`) live in `controller.Cms.php`, which is owned by **Task 3** to avoid two agents editing the same file. Task 1 = render partial + CSS only.

**Interfaces:**
- Produces: `staff_roster.tpl` consuming the field contract above; CSS classes `fd-roster, fd-roster-grid, fd-roster-card, fd-roster-photo, fd-roster-photo-empty, fd-roster-name, fd-roster-secondary, fd-roster-role, fd-roster-bio`.

- [ ] **Step 1: Create the render partial** `staff_roster.tpl`:

```php
<?php
/**
 * Partial: staff_roster.tpl
 * Receives: $blockFields (kicker, heading, subheading, presentation, people[]), UIR
 * people[] each: image['src','alt'], persona_name, mundane_name, role, bio, mundane_id, href
 */
$kicker       = $blockFields['kicker']       ?? '';
$heading      = $blockFields['heading']      ?? '';
$subheading   = $blockFields['subheading']   ?? '';
$presentation = (($blockFields['presentation'] ?? 'amtgard') === 'mundane') ? 'mundane' : 'amtgard';
$people       = $blockFields['people']       ?? [];
?>
<div class="fd-pad fd-roster">
    <div style="text-align:center;margin-bottom:22px;">
        <?php if (!empty($kicker)): ?>
            <div class="fd-kicker fd-kicker-d" style="margin-bottom:8px;"><?= htmlspecialchars($kicker, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if (!empty($heading)): ?>
            <h3 class="fd-sec-title"><?= htmlspecialchars($heading, ENT_QUOTES) ?></h3>
        <?php endif; ?>
        <?php if (!empty($subheading)): ?>
            <p style="color:#667;margin:6px 0 0;font-size:15px;"><?= htmlspecialchars($subheading, ENT_QUOTES) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($people) && is_array($people)): ?>
        <div class="fd-roster-grid">
            <?php foreach ($people as $person): ?>
                <?php
                if (!is_array($person)) { continue; }
                $img     = (isset($person['image']) && is_array($person['image'])) ? $person['image'] : [];
                $persona = trim((string)($person['persona_name'] ?? ''));
                $mundane = trim((string)($person['mundane_name'] ?? ''));
                $role    = trim((string)($person['role'] ?? ''));
                $bio     = trim((string)($person['bio'] ?? ''));
                $mid     = (int)($person['mundane_id'] ?? 0);
                $href    = trim((string)($person['href'] ?? ''));

                if ($presentation === 'mundane') {
                    $primary   = ($mundane !== '') ? $mundane : $persona;
                    $secondary = ($mundane !== '') ? $persona : '';
                } else {
                    $primary   = ($persona !== '') ? $persona : $mundane;
                    $secondary = ($persona !== '') ? $mundane : '';
                }
                if ($primary === '') { continue; }

                $link = '';
                if ($mid > 0) {
                    $link = UIR . 'Player/profile/' . $mid;
                } elseif ($href !== '' && CmsSanitizer::IsSafeUrl($href)) {
                    $link = $href;
                }
                $open  = ($link !== '') ? '<a class="fd-roster-card" href="' . htmlspecialchars($link, ENT_QUOTES) . '">' : '<div class="fd-roster-card">';
                $close = ($link !== '') ? '</a>' : '</div>';
                ?>
                <?= $open ?>
                    <?php if (!empty($img['src'])): ?>
                        <img class="fd-roster-photo" src="<?= htmlspecialchars($img['src'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars(($img['alt'] ?? '') !== '' ? $img['alt'] : $primary, ENT_QUOTES) ?>">
                    <?php else: ?>
                        <div class="fd-roster-photo fd-roster-photo-empty"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="fd-roster-name fd-serif"><?= htmlspecialchars($primary, ENT_QUOTES) ?></div>
                    <?php if ($secondary !== ''): ?>
                        <div class="fd-roster-secondary"><?= htmlspecialchars($secondary, ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if ($role !== ''): ?>
                        <div class="fd-roster-role"><?= htmlspecialchars($role, ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if ($bio !== ''): ?>
                        <div class="fd-roster-bio"><?= nl2br(htmlspecialchars($bio, ENT_QUOTES)) ?></div>
                    <?php endif; ?>
                <?= $close ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
```

- [ ] **Step 2: Append CSS** to `frontdoor.css`:

```css
/* ---- staff_roster block ---- */
.fd-roster-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px;max-width:1100px;margin:0 auto;}
.fd-roster-card{display:flex;flex-direction:column;align-items:center;text-align:center;background:#fff;border:1px solid #e6e8ef;border-radius:12px;padding:20px 16px;text-decoration:none;color:inherit;transition:box-shadow .15s ease,transform .15s ease;}
a.fd-roster-card:hover{box-shadow:0 6px 18px rgba(20,30,60,.12);transform:translateY(-2px);}
.fd-roster-photo{width:96px;height:96px;border-radius:50%;object-fit:cover;margin-bottom:12px;background:#eef0f6;}
.fd-roster-photo-empty{display:flex;align-items:center;justify-content:center;color:#9aa3b8;font-size:34px;}
.fd-roster-name{font-size:19px;font-weight:600;color:#1b2a4a;}
.fd-roster-secondary{font-size:13px;color:#6b7488;margin-top:2px;}
.fd-roster-role{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--gold);margin-top:6px;font-weight:600;}
.fd-roster-bio{font-size:14px;color:#4a5168;margin-top:10px;line-height:1.5;}
html[data-theme="dark"] .fd-roster-card{background:#1c2333;border-color:#2c3650;}
html[data-theme="dark"] .fd-roster-name{color:#eef1f8;}
html[data-theme="dark"] .fd-roster-secondary{color:#9aa3b8;}
html[data-theme="dark"] .fd-roster-bio{color:#c3c9d8;}
html[data-theme="dark"] .fd-roster-photo{background:#2c3650;}
```

- [ ] **Step 3: Verify** `php -l orkui/template/default/frontdoor/blocks/staff_roster.tpl` → "No syntax errors". Confirm `var(--gold)` and `.fd-serif`/`.fd-sec-title` already exist in frontdoor.css (`grep -n "\-\-gold\|fd-sec-title\|fd-serif" frontdoor.css`).
- [ ] **Step 4: Commit** `git add` the two files; `git commit`.

---

### Task 2: `CmsAjax/personlookup` endpoint

**Files:**
- Modify: `orkui/controller/controller.CmsAjax.php` (add public method `personlookup`)

**Interfaces:**
- Produces: GET/POST `CmsAjax/personlookup?mundane_id=<int>` → JSON `{ ok:bool, mundane_id:int, persona:string, mundane_name:string }`. Used by Task 4's search wiring.

- [ ] **Step 1: Add the method** (place near the other read endpoints; gate via the controller's existing `_begin()` auth pattern — copy how a sibling method calls it). Use `Ork3::$Lib->player->player_info()` which returns `Persona, GivenName, Surname` (or `false`):

```php
    /**
     * Editor-only: resolve a linked Amtgard persona to its display names so the
     * roster editor can snapshot them. Gated by CMS auth; real names are only
     * resolvable behind the CMS capability boundary, never via public search.
     */
    public function personlookup()
    {
        $this->_begin();                       // match sibling endpoints' auth/bootstrap
        header('Content-Type: application/json');

        $mundaneId = (int)($_GET['mundane_id'] ?? $_POST['mundane_id'] ?? 0);
        if ($mundaneId <= 0) {
            echo json_encode(array('ok' => false));
            exit;
        }

        $info = Ork3::$Lib->player->player_info($mundaneId);
        if (!$info || empty($info['Persona'])) {
            echo json_encode(array('ok' => false));
            exit;
        }

        $mundaneName = trim(($info['GivenName'] ?? '') . ' ' . ($info['Surname'] ?? ''));
        echo json_encode(array(
            'ok'           => true,
            'mundane_id'   => $mundaneId,
            'persona'      => (string)$info['Persona'],
            'mundane_name' => $mundaneName,
        ));
        exit;
    }
```

> If `_begin()` is not the right bootstrap name, open `controller.CmsAjax.php`, read an existing read endpoint (e.g. one that returns JSON), and replicate its exact auth/preamble. Do NOT add raw `$DB`.

- [ ] **Step 2: Verify** `php -l orkui/controller/controller.CmsAjax.php`. Then curl with an authed cookie jar (per the local curl-auth pattern): `GET 'index.php?Route=CmsAjax/personlookup&mundane_id=<known id>'` → expect `{"ok":true,...,"persona":"...","mundane_name":"..."}`; `&mundane_id=0` → `{"ok":false}`.
- [ ] **Step 3: Commit.**

---

### Task 3: Block catalog + starter + About/Team page type  (controller.Cms.php)

**Files:**
- Modify: `orkui/controller/controller.Cms.php` — `_blockCatalog()`, `_starter()` `$defaults`, `_pageTypes()`, `_blockAllow()` `$extra`, `_typeLabels()`.

**Interfaces:**
- Produces: catalog type `staff_roster`; page type `about`. Render partial (Task 1) must exist for `available=true`.

- [ ] **Step 1: Catalog** — in `_blockCatalog()` `$known`, after the `'cta_band'` line add:

```php
            'staff_roster'    => array('Staff Roster',       'Content',  false, 'fa-users',         'A roster of people — photo, name, role, and bio, each optionally linked to their Amtgard persona.'),
```

- [ ] **Step 2: Starter defaults** — in `_starter()` `$defaults`, add:

```php
            'staff_roster'    => array('kicker' => '', 'heading' => 'Meet the Team', 'subheading' => '', 'presentation' => 'amtgard', 'people' => array()),
```

- [ ] **Step 3: Page type label** — in `_typeLabels()` return array add:

```php
            'about'      => 'About / Team',
```

- [ ] **Step 4: Page-type preset** — in `_pageTypes()` return array, add a new entry (after `media`):

```php
            array(
                'type'   => 'about',
                'label'  => $labels['about'],
                'blocks' => array(
                    $this->_starter('rich_text'),
                    $this->_starter('staff_roster'),
                ),
            ),
```

- [ ] **Step 5: Add-block allowlist** — in `_blockAllow()` `$extra`, add an `about` key and add `staff_roster` to nothing else (composed is auto-computed from the catalog, so it already includes staff_roster):

```php
            'about'      => array('staff_roster', 'card_grid', 'cta_band', 'gallery'),
```

- [ ] **Step 6: Verify** `php -l orkui/controller/controller.Cms.php`. Curl the editor page or a list endpoint that emits PageTypes/BlockCatalog and grep for `staff_roster` and `About / Team`. Confirm `available:true` for staff_roster (partial from Task 1 exists).
- [ ] **Step 7: Commit.**

---

### Task 4: Editor form + persona-search wiring  (_block_editor.tpl + cms-admin.css)

**Files:**
- Modify: `orkui/template/default/cms/_block_editor.tpl` (add `staff_roster` branch in `buildBlockBody()`, a `personaLinkField()` helper + search JS + `tnFixedAcPosition()`, and a `summarize()` case).
- Modify: `orkui/template/default/style/cms-admin.css` (persona-link field + `.cms-help` + dark mode).

**Interfaces:**
- Consumes: `CmsAjax/personlookup` (Task 2); `KingdomAjax/playersearch` (existing); editor helpers `fieldText, fieldSelect, textBound, textBoundArea, imageBound, repeater, el, esc, markDirty`.
- Produces: the `staff_roster` authoring UI writing the field contract.

- [ ] **Step 1: `buildBlockBody` branch** — add alongside the `card_grid` branch:

```js
        if (t === 'staff_roster') {
            body.appendChild(fieldText(block, 'kicker', 'Kicker'));
            body.appendChild(fieldText(block, 'heading', 'Heading'));
            body.appendChild(fieldText(block, 'subheading', 'Subheading'));
            body.appendChild(fieldSelect(block, 'presentation', 'Presentation style',
                [{ value: 'amtgard', label: 'Amtgard name leads' },
                 { value: 'mundane', label: 'Real name leads' }], 'amtgard'));
            body.appendChild(el('div', 'cms-help', 'Choose which name leads on every card. Link a persona to auto-fill names; you can still edit them.'));
            body.appendChild(el('div', 'cms-label', 'People'));
            body.appendChild(repeater(block, 'people', 'Person',
                { image: {}, persona_name: '', mundane_name: '', role: '', bio: '', mundane_id: 0, href: '' },
                function (person) {
                    var box = el('div', null);
                    box.appendChild(imageBound(person, 'image', 'Photo'));
                    box.appendChild(personaLinkField(person));
                    box.appendChild(textBound(person, 'persona_name', 'Amtgard name'));
                    box.appendChild(textBound(person, 'mundane_name', 'Real name'));
                    box.appendChild(textBound(person, 'role', 'Role / title'));
                    box.appendChild(textBoundArea(person, 'bio', 'Bio'));
                    box.appendChild(textBound(person, 'href', 'Manual link (used only if no persona is linked)'));
                    return box;
                }));
            return body;
        }
```

- [ ] **Step 2: `personaLinkField` + search helpers** — add these functions in the same IIFE (after the other field helpers). They implement the canonical `kn-ac-results` dropdown, global search, snapshot-on-select, and a linked chip:

```js
    function tnFixedAcPosition(input, dropdown) {
        var r = input.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.left = r.left + 'px';
        dropdown.style.top = (r.bottom + 2) + 'px';
        dropdown.style.width = r.width + 'px';
        dropdown.style.zIndex = '99999';
    }

    function personaLinkField(person) {
        var wrap = el('div', 'cms-field'); wrap.style.marginBottom = '8px';
        wrap.appendChild(el('label', 'cms-label', 'Link Amtgard persona (optional)'));

        var chip = el('div', 'cms-persona-chip');
        function renderChip() {
            chip.innerHTML = '';
            if (person.mundane_id && person.mundane_id > 0) {
                chip.appendChild(el('span', null, esc('Linked: ' + (person.persona_name || ('#' + person.mundane_id)))));
                var unlink = el('button', 'cms-link-btn'); unlink.type = 'button'; unlink.textContent = 'Unlink';
                unlink.addEventListener('click', function () { person.mundane_id = 0; markDirty(); renderChip(); });
                chip.appendChild(unlink);
                chip.style.display = '';
            } else {
                chip.style.display = 'none';
            }
        }

        var input = el('input', 'cms-input'); input.type = 'text';
        input.placeholder = 'Search by persona or name…';
        var dd = el('div', 'kn-ac-results'); dd.style.display = 'none';
        document.body.appendChild(dd);

        var timer = null, ctrl = null;
        function closeDd() { dd.classList.remove('kn-ac-open'); dd.style.display = 'none'; }
        function showDd() { tnFixedAcPosition(input, dd); dd.style.display = 'block'; dd.classList.add('kn-ac-open'); }

        function pick(row) {
            person.mundane_id = parseInt(row.MundaneId, 10) || 0;
            person.persona_name = row.Persona || person.persona_name;
            input.value = ''; closeDd(); markDirty(); renderChip();
            // Snapshot the real name behind the CMS auth boundary.
            fetch(UIR + 'CmsAjax/personlookup&mundane_id=' + person.mundane_id)
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (d) {
                    if (d && d.ok) {
                        if (d.persona) { person.persona_name = d.persona; }
                        if (d.mundane_name) { person.mundane_name = d.mundane_name; }
                        markDirty();
                        // refresh the visible name inputs for this card
                        rerender();
                    }
                })
                .catch(function () { /* names stay as typed; non-fatal */ });
        }

        function search(term) {
            if (ctrl) { ctrl.abort(); }
            ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var url = UIR + 'KingdomAjax/playersearch/0&scope=all&include_inactive=1&q=' + encodeURIComponent(term);
            fetch(url, ctrl ? { signal: ctrl.signal } : undefined)
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (rows) {
                    dd.innerHTML = '';
                    if (!rows || !rows.length) {
                        var none = el('div', 'kn-ac-item kn-ac-none', 'No matches'); dd.appendChild(none); showDd(); return;
                    }
                    rows.forEach(function (row) {
                        var loc = [row.KAbbr, row.PAbbr].filter(Boolean).join(':');
                        var item = el('div', 'kn-ac-item',
                            esc(row.Persona) + (loc ? ' <span class="kn-ac-meta">' + esc(loc) + '</span>' : ''));
                        item.addEventListener('mousedown', function (e) { e.preventDefault(); pick(row); });
                        dd.appendChild(item);
                    });
                    showDd();
                })
                .catch(function () { /* ignore aborted/failed search */ });
        }

        input.addEventListener('input', function () {
            var term = input.value.trim();
            if (timer) { clearTimeout(timer); }
            if (term.length < 2) { closeDd(); return; }
            timer = setTimeout(function () { search(term); }, 200);
        });
        input.addEventListener('blur', function () { setTimeout(closeDd, 150); });

        wrap.appendChild(chip);
        wrap.appendChild(input);
        renderChip();
        return wrap;
    }
```

> `rerender()` = the editor's existing function that rebuilds the current block body (find the name used when other fields mutate the model, e.g. the function `renderList`/`renderBlock` already called elsewhere; reuse it). If no single-card rerender exists, call the same function the repeater uses after add/remove. Confirm the exact name when implementing.

- [ ] **Step 3: `summarize()` case** — add: `if (b.type === 'staff_roster') { return 'Staff Roster — ' + ((b.fields.people || []).length) + ' people'; }` (match the file's existing summarize style/return shape).

- [ ] **Step 4: CSS** — append to `cms-admin.css`:

```css
.cms-help{font-size:12px;color:#6b7280;margin:2px 0 10px;}
.cms-persona-chip{display:flex;align-items:center;gap:8px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;padding:4px 8px;margin-bottom:6px;font-size:13px;}
.cms-link-btn{background:none;border:none;color:#2563eb;cursor:pointer;font-size:12px;text-decoration:underline;padding:0;}
.kn-ac-meta{color:#9aa3b8;font-size:12px;margin-left:6px;}
html[data-theme="dark"] .cms-help{color:#9aa3b8;}
html[data-theme="dark"] .cms-persona-chip{background:#1e293b;border-color:#334155;color:#e2e8f0;}
html[data-theme="dark"] .cms-link-btn{color:#60a5fa;}
```

> If `.kn-ac-results`/`.kn-ac-item`/`.kn-ac-open` are styled globally already, do not redefine them — only add the roster-specific `.cms-*` and `.kn-ac-meta` rules. Verify dark mode of the dropdown.

- [ ] **Step 5: Verify** `php -l orkui/template/default/cms/_block_editor.tpl`. In the browser: edit a page, add a Staff Roster block, confirm the form renders, typing 2+ chars shows the dropdown positioned under the input, selecting a persona fills both names + shows the linked chip, Unlink clears it. Toggle dark mode.
- [ ] **Step 6: Commit.**

---

## Self-Review

- **Spec coverage:** block fields ✓ (T1 render, T4 editor), persona link via global playersearch ✓ (T4), snapshot via personlookup ✓ (T2), presentation toggle ✓ (T1+T4), conditional card link ✓ (T1), About/Team preset + catalog ✓ (T3), security (IsSafeUrl on href via existing `$URL_FIELDS`, escaped output, int mundane_id) ✓, dark mode ✓ (T1+T4 CSS).
- **Placeholders:** none — all code is concrete. Two flagged confirmations (`_begin` auth name in T2; `rerender` name in T4) are "read the sibling and match" instructions, not blanks.
- **Type consistency:** field keys identical across T1/T3/T4; endpoint JSON keys (`ok, persona, mundane_name, mundane_id`) consumed exactly in T4; playersearch row keys (`MundaneId, Persona, KAbbr, PAbbr`) match the endpoint's real output.

## Parallelization
T1, T2, T3, T4 touch **disjoint files** (T1: partial+frontdoor.css · T2: CmsAjax · T3: Cms · T4: _block_editor+cms-admin.css) and share only the documented contract → all four run in parallel, then integrate + verify together.
