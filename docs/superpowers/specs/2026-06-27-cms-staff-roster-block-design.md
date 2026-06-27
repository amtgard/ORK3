# CMS `staff_roster` Block — Design

Date: 2026-06-27
Branch: `feature/front-door`
PR context: #486 "Enhancement: Project Front Door"

## Goal

Add an authored **Staff Roster** block to the Front Door CMS so an admin can build an
"About Our Staff / Meet the Team" page: a responsive grid of people cards (headshot,
name, role, short bio). Each person can optionally be linked to their Amtgard player
record via player-search. A block-level **Presentation Style** toggle decides whether
the real (mundane) name or the Amtgard (persona) name is the forward-leaning name.

This follows the existing `card_grid` block pattern end-to-end; it is **not** a dynamic
block (no live DB read at render time).

## Decisions (locked)

- **Presentation Style is block-level**, one setting for the whole roster: `amtgard` |
  `mundane`. `amtgard` → persona name leads, mundane name is the smaller secondary line;
  `mundane` → the reverse.
- **Card links only when there is a target.** Persona-linked → `Player/profile/{mundane_id}`;
  else a manual `href` if set; otherwise the card renders as a plain (non-anchor) card.
  No `href="#"` dead links.
- **Add an "About / Team" page-type preset** (starter blocks: `rich_text` intro +
  `staff_roster`), and also add `staff_roster` to the `composed` page type's available
  blocks. The block itself is addable on any page.
- **Persona link snapshots names at author time** (not a live render-time lookup) to avoid
  an N+1 DB hit on the public page — the exact anti-pattern the recent polish pass fixed in
  `blog_feed`/`marketing_nav`. Snapshotted names are editable free text (privacy / preferred
  form, e.g. "Avery K.").
- **Player-search is global** (`scope=all`, org-wide) — an intended exception like the
  Unit-member add and Award "Given By" searches, because a site staff roster is org-wide.

## Block data shape

Stored in `ork_cms_block.fields_json` for `type = 'staff_roster'`:

```json
{
  "kicker": "",
  "heading": "Meet the Team",
  "subheading": "",
  "presentation": "amtgard",
  "people": [
    {
      "image": { "src": "", "alt": "" },
      "persona_name": "",
      "mundane_name": "",
      "role": "",
      "bio": "",
      "mundane_id": 0,
      "href": ""
    }
  ]
}
```

- `presentation` ∈ {`amtgard`, `mundane`}, default `amtgard`; clamped on render.
- `mundane_id` is an int; `> 0` means a persona is linked and drives the profile link.
- `href` is an optional manual link, used **only** when `mundane_id` is `0`/empty.
- `bio` is plain text (no HTML) — escaped on render; rich bios are out of scope.

## Components & touchpoints

All names mirror the existing `card_grid` implementation.

### 1. `system/lib/ork3/` — no new CMS lib class
The block is authored/static, so no new CMS library is required. The only DB read is the
editor-side persona name lookup (below), which sources from the **core player layer**, not
a CMS lib (mundane data is not a CMS concern).

### 2. `orkui/controller/controller.Cms.php`
- `_blockCatalog()`: add
  `'staff_roster' => array('Staff Roster', 'Content', false, 'fa-users', 'A roster of people — photo, name, role, and bio, each optionally linked to their Amtgard persona.')`.
  (`fa-users` is valid in FontAwesome 5.8.2.)
- `_starterBlock()`: add a `staff_roster` case returning the default fields
  (`heading => 'Meet the Team'`, `presentation => 'amtgard'`, `people => array()` plus one
  blank person so the editor shows a starter card).
- `_pageTypes()`: add an `about` type, label "About / Team", starter blocks
  `[rich_text, staff_roster]`. Add `staff_roster` to the `composed` available-blocks list
  and to the new `about` type's available list. Add the `about` label to the `$labels` map.

### 3. `orkui/controller/controller.CmsAjax.php`
- **New endpoint `personlookup`**: input `mundane_id` (int, GET/POST); gated by the existing
  `_begin()` CMS auth (so real names are only resolvable behind the CMS capability boundary,
  not via the shared player-search). Returns JSON
  `{ ok, persona, mundane_name, mundane_id }` where `mundane_name = trim(given_name + ' ' + surname)`.
  Sourced via the core Player/Mundane lib by id (add a minimal getter there if none exists —
  no raw `$DB` in the controller, per the architecture rule). Returns `ok:false` for unknown
  ids.
- `$URL_FIELDS` already includes `href` (added in the recent polish pass), so each person's
  `href` is validated through `CmsSanitizer::IsSafeUrl()` by the existing `_sanitizeFields()`
  recursion into nested arrays — **no change needed** there. Confirm `mundane_id` is cast to
  int on save (add to the field-coercion path if not already integer-safe).

### 4. `orkui/template/default/cms/_block_editor.tpl`
- `buildBlockBody()`: add a `staff_roster` branch (hand-built, like `card_grid`):
  - `fieldText` kicker, heading, subheading
  - `fieldSelect` `presentation` → options `[{amtgard,'Amtgard name leads'},{mundane,'Real name leads'}]`, default `amtgard`, with a one-line helper explaining the toggle
  - `repeater(block,'people','Person', { image:{}, persona_name:'', mundane_name:'', role:'', bio:'', mundane_id:0, href:'' }, fn)` where each person card renders:
    - `imageBound(person,'image','Photo')`
    - **Persona link field**: a text input + `kn-ac-results` dropdown (see §5). When a result
      is chosen: set `person.mundane_id`, prefill `person.persona_name` (from the search row's
      `Persona`) and `person.mundane_name` (from the `personlookup` fetch), and show a
      "Linked: «Persona» ✓ — Unlink" affordance that clears `mundane_id` on unlink.
    - `textBound` persona_name, mundane_name, role
    - `textareaBound` bio
    - `textBound` href, labelled "Manual link (used only if no persona is linked)"
- `summarize()`: add `staff_roster` → `"Staff Roster — N people"`.

### 5. Player-search wiring (in `_block_editor.tpl`)
- Hit `KingdomAjax/playersearch/0?scope=all&include_inactive=1&q=<term>` — note `0` kingdom
  id with `scope=all` (the endpoint explicitly allows kingdom_id=0 for global), and the query
  **must** be appended with `&q=` (the UIR already ends in `?Route=`; a second `?` empties
  `$_GET['q']`). Min 2 chars, debounce, abort the in-flight request on new input.
- Render results with the canonical `kn-ac-results` custom dropdown — **never** jQuery UI
  autocomplete. Each row shows `Persona` plus `KAbbr:PAbbr`/kingdom for disambiguation
  (the endpoint returns `MundaneId, Persona, KingdomName, ParkName, KAbbr, PAbbr, ...`).
- **Define** `tnFixedAcPosition(inputEl, dropdownEl)` locally (it is not defined anywhere in
  the codebase today — recurring bug) and call it before `classList.add('kn-ac-open')` in both
  the results and the no-results branches, using `position:fixed` so the dropdown is not
  clipped by the editor's stacking context.
- Guard the IIFE with a `PnConfig`/config flag, **not** `getElementById` (modal/editor HTML
  may load after the external `revised.js`-style script — the documented IIFE-guard rule).

### 6. `orkui/template/default/frontdoor/blocks/staff_roster.tpl` (new)
- Header: kicker / `<h3 class="fd-sec-title">` heading / subheading — same structure as
  `card_grid.tpl`, with the global `h1–h6` gray-box reset already provided by `fd-sec-title`.
- Per person, compute:
  - `$primary = ($presentation === 'mundane') ? $mundane_name : $persona_name;`
  - `$secondary = ($presentation === 'mundane') ? $persona_name : $mundane_name;`
  - If `$primary === ''`, promote `$secondary` to primary and suppress the secondary line
    (handles people with only one name filled).
  - Link target: `mundane_id > 0` → `UIR . 'Player/profile/' . (int)$mundane_id`; else if
    `href` non-empty and `CmsSanitizer::IsSafeUrl($href)` → `href`; else no `<a>` wrapper.
- Card layout: **headshot on top, text below** (distinct from `card_grid`'s overlay-scrim
  tile): rounded photo, prominent `$primary` (serif), muted small `$secondary`, gold/eyebrow
  `role`, clamped `bio`. All output `htmlspecialchars(..., ENT_QUOTES)`. Skip the photo `<img>`
  when `image.src` is empty. Skip a person entirely only if it has no name at all.

### 7. `orkui/template/default/frontdoor/css/frontdoor.css`
- Add `.fd-roster`, `.fd-roster-grid` (responsive: `repeat(auto-fill, minmax(220px, 1fr))`),
  `.fd-roster-card`, `.fd-roster-photo`, `.fd-roster-name`, `.fd-roster-secondary`,
  `.fd-roster-role`, `.fd-roster-bio`.
- **Dark-mode** overrides under `html[data-theme="dark"]` (card surface, borders, text,
  secondary/muted colors). Walk the block in dark mode before "done".
- Admin-editor styling for the persona-link field/linked-state chip in `cms-admin.css` as
  needed (dark-mode safe).

## Security & validation

- Per-person `href` validated via `CmsSanitizer::IsSafeUrl()` (existing `$URL_FIELDS` path).
- `mundane_id` cast to int on save and render.
- `presentation` clamped to the enum on render (`amtgard` fallback).
- `personlookup` gated by CMS auth (`_begin()`); real names not exposed via the public
  search endpoint.
- All render output HTML-escaped; `bio` is plain text (no HTML sanitizer surface).
- Profile link uses `(int)$mundane_id` so it cannot inject.

## Out of scope (YAGNI)

- Per-person presentation override (block-level only).
- Rich-text/HTML bios.
- Pulling the player's avatar automatically (photo is author-supplied via media library).
- Live/dynamic roster sourced from officer records (this block is authored; a dynamic
  variant could be a future block).
- Reordering people by drag in render (editor repeater reorder is inherited from the
  existing repeater component if present; otherwise add/remove only).

## Testing / verification

- `php -l` on every changed/new PHP/.tpl file.
- Curl-test `KingdomAjax/playersearch/0?scope=all&q=<2+ chars>` (authed session) returns rows.
- Curl-test `CmsAjax/personlookup?mundane_id=<known>` returns persona + mundane_name.
- Author a page with a `staff_roster` block: add 2–3 people, link one persona, set
  presentation to each value, save, publish, view the public page — confirm name ordering,
  conditional links, and dark mode.
- Confirm the editor autocomplete dropdown positions correctly (defined `tnFixedAcPosition`)
  and returns rows (global scope, `&q=`).
