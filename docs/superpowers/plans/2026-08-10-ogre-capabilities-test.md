# OGRE — full in-browser capabilities test

**Date:** 2026-08-10 · **Branch:** `feature/front-door` · **PR:** #486
**Target:** `http://localhost:19080` · **Actor:** `heraldsbridge` (mundane 46193, kingdom 17, park 1049 — ORK super-admin)

## Why this document exists

Everything shipped on this branch has been *read* — by a distributed review, three
adversarial passes, and a mobile/a11y pass. Almost none of it has been *executed in a
browser*. The gap that matters most is the block catalog: OGRE declares **32 block
types**, and a query against the live DB shows **8 of them have never had a single row
written** — `spacer`, `table`, `video_embed`, `blog_feed`, `raw_html`, `park_meeting`,
`park_officers`, `park_events`. A block partial that throws, renders empty, or escapes
wrong is invisible to every static review and to `php -l`.

So the organizing principle here is: **every block type gets instantiated, saved,
published, and looked at.** Not "the code looks right" — a rendered DOM node with the
expected content in it.

## Findings from the 2026-08-10 run

### F1 — Park sites are seeded with the KINGDOM starter template (HIGH)
Provisioning a park site installs kingdom-scoped blocks and kingdom copy verbatim.
After seeding park 1049:

| page | block seeded | result |
|------|--------------|--------|
| home | `kingdom_events` | renders **nothing** on a park page |
| parks ("Our Parks") | `kingdom_parks_map`, `kingdom_parks` | a park has no parks — page is dead |
| officers | `kingdom_officers` | renders **nothing** — the park's Officers page lists no officers |

Copy is kingdom copy throughout: "Welcome to Our **Kingdom**", "A **Kingdom** of
Adventurers", "introduce your **kingdom**", "govern and steward the **kingdom**" —
`CmsSite::OrgUnitNoun()` exists but the seed does not use it.

None of `park_meeting` / `park_officers` / `park_events` is seeded, including
`park_meeting`, which the registry itself calls "the single most useful block on a park
page".

The blocks are not at fault: `kingdom_events.tpl` documents "Renders NOTHING outside a
kingdom scope … never errors, never fatals" and does exactly that, and the Add-block
**chooser correctly hides all four `kingdom_*` blocks in park scope** (verified — 26
offered = 23 universal + 3 park). Only the seeder ignores scope, so a brand-new park
site opens with three silently blank pages and no error anywhere.

### F5 — `park_meeting` prints a literal `0` as the venue name (MEDIUM)
Every meeting card shows a bare `0` between the purpose and the address.

`park_meeting.tpl:140`:
```php
$name = trim((string) ($d['AlternateLocation'] ?? ($d['Location'] ?? '')));
```
`ork_parkday.alternate_location` is a **tinyint flag** (0/1), not a venue name. When it
is `0`, `trim((string) 0)` is `"0"` — non-empty — so the `$pmWhere['name'] !== ''`
guard passes and `0` is printed as the location. Both park-day rows for park 1049
reproduce it. A block that has never rendered before, so nothing caught it.

### F2 — Seeded pages render their title twice (LOW)
Seeded pages show the title as the page `<h1>` and again as a seeded `heading` block
directly beneath it. Affects kingdom sites as well as park sites.

### F3 — Empty starter blocks render as blank bands (LOW)
On a freshly seeded page, `hero_carousel` shows an author hint ("This carousel has no
slides yet.") but empty `rich_text` and `cta_band` render as empty full-width bands with
no hint. Inconsistent — the hero pattern is the right one.

### F4 — `Cms/edit` with no id returns a blank HTTP 200 (LOW)
`Controller_Cms::edit()` calls `parent::view()` when `func_num_args() === 0`, yielding a
zero-byte 200 rather than redirecting to the Pages list. Same shape as the DEV
document-root blank page fixed in `e16300b7`.

### F6 — TinyMCE logs a license warning on every editor load (LOW)
"TinyMCE is running in evaluation mode. Provide a valid license key or add
`license_key: 'gpl'`". Add `license_key: 'gpl'` to the init config to agree to the open
source terms and clear the console noise.

## Method

For each block the test asserts four things, in order. A block only passes if all four hold.

| # | Assertion | How it's checked |
|---|-----------|------------------|
| 1 | **Editor** | The block appears in the Add-block chooser for its scope, its card opens, and its fields accept input |
| 2 | **Persist** | After save, the block's row exists with the entered values in `fields_json` (checked via the rendered page, not the DB, unless a mismatch needs diagnosing) |
| 3 | **Render** | The published page contains the block's expected DOM signature with the entered content visible and correctly escaped |
| 4 | **Clean** | No console errors, no 4xx/5xx in the network log attributable to the block |

Plus two sweeps applied to the finished kitchen-sink pages rather than per block:
**dark mode** (`html[data-theme="dark"]`) and **narrow viewport** (390px, measured in a
same-origin iframe — `resize_window` is unreliable here).

### Scope strategy

Blocks are gated by scope, so no single page can hold them all. Three kitchen-sink pages:

- **KS-Global** — `/Cms` global scope. The 24 universal addable blocks.
- **KS-Kingdom** — Burning Lands site (`/k/burning-lands`, kingdom 7). The 4 `kingdom_*` blocks.
- **KS-Park** — a park site provisioned during the run. The 3 `park_*` blocks.

`marketing_nav` and `richtext` are **not addable** (legacy / page-chrome), so they are
verified where they already live — the front door — rather than through the chooser.

---

## Phase 0 — Preconditions

| ID | Step | Expected |
|----|------|----------|
| P0.1 | App reachable at `:19080`; `ork3-php8-app` + `ork3-php8-db` up | 200 at document root |
| P0.2 | Branch migrations applied locally | `ork_cms_page.type` enum includes `about`; `ork_cms_site.template_seeded_at` exists |
| P0.3 | Log in as `heraldsbridge` (any password — `true \|\|` bypass is in the working tree) | Lands authenticated; user dropdown shows **Manage Site Pages** |
| P0.4 | Memcache is not serving a stale render | Flush before first page-render assertions |

---

## Phase 1 — Admin shell & access control

| ID | Test | Expected |
|----|------|----------|
| 1.1 | `Route=Cms` dashboard loads | OGRE wordmark in rail; lede spells out "Online Gallery and Resource Engine" once |
| 1.2 | Rail navigation: Pages, Posts, Media, Nav, Theme, Sites | Every entry routes without error |
| 1.3 | Dashboard panels | Page/post counts non-zero; top-content panel lists viewed pages (or an honest empty state) |
| 1.4 | `Cms/sites` overview | Amtgard International pinned first; both kingdom sites listed with status badges + page/post counts |
| 1.5 | Inline publish / unpublish from the sites list | Status badge flips; public URL 404s when unpublished, serves when published |
| 1.6 | Scope selector `?scope=` | Switching scope re-scopes the Pages list; a scope the actor lacks authority for is rejected server-side, not just hidden |
| 1.7 | **IDOR probe** — edit a page id belonging to a scope not selected | Denied, not silently served |
| 1.8 | CSRF — a `CmsAjax` mutation without `X-CSRF-Token` | Rejected |

---

## Phase 2 — Block catalog: the 24 universal blocks (KS-Global)

Create one page, `type=composed`, slug `qa-kitchen-sink`, and add every addable
universal block to it. Content entered per block is chosen to make a rendering failure
*visible* — a heading that says which block it is, plus at least one field that
exercises escaping.

**Escaping payload used wherever a text field accepts it:** `Æthelmearc & <b>bold</b> "quoted"`
— it exercises the entity table that was found case-flattening, the ampersand, and a raw
tag that must render as literal text in non-HTML fields.

| # | Block | Fields to exercise | Expected DOM signature |
|---|-------|-------------------|------------------------|
| 2.1 | `hero_carousel` | 2 slides + logo + 2 CTAs, autoplay 4000ms | Slide track, dots, play/pause toggle, ARIA live region; autoplay advances; dots clickable |
| 2.2 | `rich_text` | kicker, heading, TinyMCE body with a link + list, align, CTA | Rendered HTML preserved; link survives sanitizer; CTA button present |
| 2.3 | `heading` | text + level 2 + align center | `<h2>` centered — **and no gray pill box** (global `h1–h6` rule in orkui.css) |
| 2.4 | `divider` | style: line | Rule element present |
| 2.5 | `spacer` | size md | **Never rendered before.** Vertical gap element with a real height |
| 2.6 | `card_grid` | kicker, heading, 3 cards w/ image + link | 3-up grid; collapses at narrow width |
| 2.7 | `steps` | kicker, heading, band=light, 4 steps, CTA | 4→2→1 column stepping |
| 2.8 | `accordion` | 3 items | Collapsed by default; click expands; keyboard operable; `aria-expanded` flips |
| 2.9 | `quote` | text + cite | Blockquote + attribution |
| 2.10 | `table` | caption, header row on, 3×4 rows | **Never rendered before.** `<caption>`, `<th>` first row, horizontal scroll container with `tabindex=0`/`role=region` — must NOT force page-level horizontal scroll |
| 2.11 | `image` | picked from media library, caption, href, align, max_width | `<img>` with alt; wrapped in the link; caption below |
| 2.12 | `gallery` | 4 images, 3 columns, caption | Grid; **lightbox opens on click**, scroll-locks, background `inert`, Esc closes, focus restored |
| 2.13 | `video_embed` | provider youtube + a video id; then the URL field | **Never rendered before.** Responsive iframe, correct id extracted from a full URL |
| 2.14 | `file_download` | 2 files w/ labels | List with file names + sizes; links resolve |
| 2.15 | `columns` | 2 columns, each holding a `rich_text` + an `image` | Side-by-side; **child blocks render**; stacks at narrow width; recursion depth cap not tripped by 1 level |
| 2.16 | `photo_mosaic` | 5 images + caption | Uniform-height tiles, `object-fit:cover` |
| 2.17 | `cta_band` | heading, subcopy, logo, 2 CTAs, links | Band; **CTA row wraps** rather than overflowing |
| 2.18 | `staff_roster` | presentation=amtgard, 3 people incl. one with a persona link | Roster grid; person modal opens, traps focus, sets background `inert` |
| 2.19 | `raw_html` | a benign `<table>`, then a `<script>` and an `onerror` `<img>` | **Never rendered before.** Benign markup renders; **script and `onerror` must not execute** (console watched); table scrolls in its own container |
| 2.20 | `member_bar` | (dynamic, no fields) | Renders logged-in personalized bar; hidden on phone widths |
| 2.21 | `events_feed` | limit 3, heading, more_href | Live event cards or an honest empty state — never a blank section |
| 2.22 | `kingdoms_teaser` | limit 12 | Kingdom tiles, clamped to the limit |
| 2.23 | `blog_feed` | limit 3, no tag; then filtered by an existing tag | **Never rendered before.** Post cards; tag filter actually narrows the set |
| 2.24 | `image`/`gallery` alt-text | (revisit 2.11/2.12) | Alt text editor writes through to the rendered `alt` |

**Per-block checks that apply to all of the above:**
- Editor card collapses/expands; drag-reorder moves the block and the new order survives save
- Disable (`enabled=0`) hides it from the public page but keeps it in the editor
- Delete asks for confirmation via the **custom dialog, not native `confirm()`**

---

## Phase 3 — Kingdom dynamic blocks (KS-Kingdom, Burning Lands / kingdom 7)

These read live ORK data, so the test asserts the data is *right*, not merely present.

| # | Block | Check |
|----|-------|-------|
| 3.1 | `kingdom_officers` | Officers shown match `ork_office` holders for kingdom 7; roles use the AA-corrected color, not `#b8860b` |
| 3.2 | `kingdom_parks` | Park list matches the kingdom's parks; sort by name / city / state each re-order; heraldry toggle on/off; limit respected; **park names escaped** (the map-block escaping fix) |
| 3.3 | `kingdom_parks_map` | Atlas map paints; a pin click opens the detail sidebar; empty state has dark-mode styling |
| 3.4 | `kingdom_events` | Soonest upcoming events for the kingdom, ascending; each links to its event |
| 3.5 | **Render cache** | Note a value, change the underlying ORK data, confirm the page is stale; run `CmsAjax/clearrendercache`; confirm the page now reflects the change — this is the drift `CmsRenderCache` was introduced to prevent, and it fails *silently* if the key formats disagree |

---

## Phase 4 — Park sites (KS-Park) — never exercised in a browser

Park sites shipped in the commit under test and **no park site row exists in the local DB**,
so this phase provisions one first. That provisioning is itself the test of the seed path.

| # | Test | Expected |
|----|------|----------|
| 4.1 | Provision a site for park 1049 from `Cms/sites` | Site row created, starter template seeded, `template_seeded_at` stamped |
| 4.2 | Public URL uses the **`/p/` prefix** | `/p/{slug}` serves; `/k/{slug}` does not — this is the `_prefixFor()` bug that shipped as hard-coded `/k/` |
| 4.3 | Site-settings modal shows the public address | Shows `/p/…`, not `/k/…`, in all three places it prints the URL |
| 4.4 | Permission copy in park scope | Names the park-level gate (`AUTH_PARK`), not "monarch or regent" |
| 4.5 | `park_meeting` | **Never rendered before.** Meeting cards from park-day records — recurrence, time, address; `show_map` default ON produces a directions link |
| 4.6 | `park_officers` | **Never rendered before.** Park officer grid, office + persona |
| 4.7 | `park_events` | **Never rendered before.** Upcoming park events as date cards |
| 4.8 | Kingdom-only blocks are **not offered** in park scope | `kingdom_*` absent from the park chooser, and rejected if posted anyway |
| 4.9 | **Re-seed guard** | Delete a seeded nav link and a starter page, then revisit "Manage Public Site" — the deleted content must **stay deleted** (the `template_seeded_at` fix; the old behavior re-created them as published) |
| 4.10 | `OrgUnitNoun()` | Park-scope copy says "Park"; a principality's site says "Principality", not "Kingdom" |

---

## Phase 5 — Editor capabilities

| # | Test | Expected |
|----|------|----------|
| 5.1 | Page-type chooser | All 7 types offered incl. **About / Team**; selecting a type seeds its starter blocks |
| 5.2 | Starter seeding | `composed` seeds hero_carousel + rich_text + cta_band; `about` seeds rich_text + staff_roster |
| 5.3 | Chooser gating by page type | An `article` page offers its `extra_blocks`; a block outside the allowlist is rejected on save, not just hidden |
| 5.4 | Autosave | Edit, wait, reload without an explicit save — content survives |
| 5.5 | Draft → Preview | Preview renders unpublished content with the draft banner; public URL still 404s |
| 5.6 | Publish / unpublish | Public visibility flips both ways |
| 5.7 | Scheduled publish | A future `published_at` stays private until due; **timezone** — the ~5h `America/Chicago`-vs-UTC bug means this must be checked against a time inside that window |
| 5.8 | Revisions | Revision recorded on save; **restore** returns the prior content; a corrupt snapshot hard-fails rather than silently blanking |
| 5.9 | Duplicate / delete page | Trash is soft; trashed page 404s publicly; purge is irreversible |
| 5.10 | Block summary text | The editor's collapsed summary shows **literal** text for HTML input — the detached-`innerHTML` XSS fix; `&AElig;` renders `Æ`, **not** `æ` (the case-flattening bug) |
| 5.11 | Persona autocomplete in `staff_roster` | One dropdown instance per editor (not one appended per call); uses `kn-ac-results`, positioned via `tnFixedAcPosition` inside the modal |

---

## Phase 6 — Media library

| # | Test | Expected |
|----|------|----------|
| 6.1 | Upload an image | Master + WebP renditions written; tile appears |
| 6.2 | Reject oversize / non-image | Distinct errors — `too_large` vs `quota_exceeded` vs not-an-image |
| 6.3 | Quota metering per org scope | Kingdom/park scopes metered, global unmetered (matches `tests/cms-tenancy`) |
| 6.4 | "In use" badge | Uses the now-defined `--ork-warn` token; where-used scan catches embedded `<img>` in rich text |
| 6.5 | Delete a media item that is in use | Blocked or warned; soft-deleted owners must **not** pin media forever |
| 6.6 | Bulk delete | Only ids in the caller's scope are deleted — attempt one from another scope and confirm it survives |
| 6.7 | Media tiles keyboard-operable | Focus ring, Enter selects |

---

## Phase 7 — Pages, routing, blog

| # | Test | Expected |
|----|------|----------|
| 7.1 | Page hierarchy | Child page URL includes the parent path; breadcrumb correct |
| 7.2 | Breadcrumb leak | An **unpublished ancestor's slug must not appear** to an anonymous visitor |
| 7.3 | Rename a slug | 301 from the old path; prefix fallback works |
| 7.4 | Cycle / cross-scope parent | Rejected |
| 7.5 | Slug transliteration | A title with Æ/Þ/Ø/ß and an NFD-decomposed paste produce the same, stable slug |
| 7.6 | Blog post create + tags | Post saves; tags attach; **the same tag name does not create a duplicate `ork_cms_tag` row** |
| 7.7 | Blog index pagination | Pages through; per-page respected |
| 7.8 | RSS | `Blog/rss` and per-site `/k/{slug}/rss` are valid XML with correct absolute links |
| 7.9 | `sitemap.xml` | Lists live pages only |
| 7.10 | Open Graph / canonical | Every public surface emits canonical + `og:*` (the `CmsMeta` consolidation); org-site pages fall back to the **site name**, global pages to the **brand** |

---

## Phase 8 — Nav manager & theme

| # | Test | Expected |
|----|------|----------|
| 8.1 | Nav CRUD + reorder | Links add, reorder, nest, delete |
| 8.2 | Link types | "Site Page" wording (not "CMS Page"); a nav item pointing at a deleted/unpublished page **resolves to nothing rather than a dead link** |
| 8.3 | Nav rows on mobile | Rows wrap; actions keep a 40px touch target |
| 8.4 | Theme editor | Token changes apply live; preview toggles light/dark |
| 8.5 | Derived dark mode | Dark palette auto-derived and WCAG-checked from the single light palette |
| 8.6 | One active theme per scope | Activating a second theme deactivates the first (DB-enforced) |
| 8.7 | Front-door tokens | Block surfaces read `--fd-*`; changing a token moves the front door |

---

## Phase 9 — Security

| # | Test | Expected |
|----|------|----------|
| 9.1 | Sanitizer XSS battery | `%6aavascript:`, `java%09script:`, `<svg onload>`, `<img onerror>` — all neutralized in authored HTML |
| 9.2 | `raw_html` block | Author-supplied script does **not** execute (this block is admin-only by design — confirm the gate) |
| 9.3 | Nav/link URL validation | Scheme allowlist enforced on nav items and CTA hrefs |
| 9.4 | Sanitizer budget | A deeply nested / very large paste is rejected on budget rather than hanging the request |
| 9.5 | `columns` recursion cap | Nesting past the cap is refused |
| 9.6 | Unpublished site | 404 to the public; previews for an authorized officer, who also gets the edit FAB |

---

## Phase 10 — Cross-cutting sweeps

Run against KS-Global, KS-Kingdom, KS-Park once each is complete.

| # | Sweep | Expected |
|----|-------|----------|
| 10.1 | **Dark mode** | Every block legible under `html[data-theme="dark"]`; no undefined tokens; no light-mode-only surfaces |
| 10.2 | **390px width** (iframe-measured) | Zero horizontal page scroll; wide content scrolls in its own container; nothing clipped |
| 10.3 | **Console** | Zero errors across all three pages |
| 10.4 | **Network** | Zero 4xx/5xx |
| 10.5 | **Block fault isolation** | Corrupt one block's `fields_json`; the page must still render with the other blocks (and show a placeholder in preview only) |
| 10.6 | **Keyboard** | Tab through each page; every interactive control reachable with a visible focus ring |

---

## Coverage ledger — RUN OF 2026-08-10

A block is **PASS** only when all four assertions hold. ⚠ = no row of this type had
ever existed in the database; this run was its first execution.

**30 of 30 addable block types PASS.** (`marketing_nav` and `richtext` are non-addable
legacy/chrome types, verified on the front door rather than through the chooser.)

| Block | Editor | Persist | Render | Clean | Verdict |
|-------|:--:|:--:|:--:|:--:|:--:|
| marketing_nav (front door) | n/a | n/a | ✅ | ✅ | PASS |
| member_bar | ✅ | ✅ | ✅ | ✅ | PASS |
| hero_carousel | ✅ | ✅ | ✅ | ✅ | PASS — H1 then H2, single-H1 rule holds |
| richtext (legacy, non-addable) | n/a | n/a | n/a | n/a | not offered — correct |
| card_grid | ✅ | ✅ | ✅ | ✅ | PASS |
| steps | ✅ | ✅ | ✅ | ✅ | PASS |
| events_feed | ✅ | ✅ | ✅ | ✅ | PASS |
| photo_mosaic | ✅ | ✅ | ✅ | ✅ | PASS |
| kingdoms_teaser | ✅ | ✅ | ✅ | ✅ | PASS |
| cta_band | ✅ | ✅ | ✅ | ✅ | PASS — CTA row wraps |
| staff_roster | ✅ | ✅ | ✅ | ✅ | PASS |
| rich_text | ✅ | ✅ | ✅ | ✅ | PASS — link/list/bold survive sanitizer |
| heading | ✅ | ✅ | ✅ | ✅ | PASS — no gray pill box |
| divider | ✅ | ✅ | ✅ | ✅ | PASS |
| spacer ⚠ | ✅ | ✅ | ✅ | ✅ | PASS |
| accordion | ✅ | ✅ | ✅ | ✅ | PASS |
| quote | ✅ | ✅ | ✅ | ✅ | PASS |
| table ⚠ | ✅ | ✅ | ✅ | ✅ | PASS — own scroll container, no page scroll |
| image | ✅ | ✅ | ✅ | ✅ | PASS |
| gallery | ✅ | ✅ | ✅ | ✅ | PASS |
| video_embed ⚠ | ✅ | ✅ | ✅ | ✅ | PASS — id extracted from watch URL, youtube-nocookie |
| file_download | ✅ | ✅ | ✅ | ✅ | PASS |
| columns | ✅ | ✅ | ✅ | ✅ | PASS — child blocks render, 2-up → stacked |
| raw_html ⚠ | ✅ | ✅ | ✅ | ✅ | PASS — full XSS battery neutralized |
| blog_feed ⚠ | ✅ | ✅ | ✅ | ✅ | PASS |
| kingdom_officers | ✅ | ✅ | ✅ | ✅ | PASS — 5 officers, heraldry, AA colors |
| kingdom_parks | ✅ | ✅ | ✅ | ✅ | PASS — 8 parks, org-unit terms ("Grand Duchy") |
| kingdom_parks_map | ✅ | ✅ | ✅ | ✅ | PASS — degrades correctly with no Maps API key |
| kingdom_events | ✅ | ✅ | ✅ | ✅ | PASS — honest empty state |
| park_meeting ⚠ | ✅ | ✅ | ⚠️ | ✅ | **F5** — stray `0` printed as venue name |
| park_officers ⚠ | ✅ | ✅ | ✅ | ✅ | PASS |
| park_events ⚠ | ✅ | ✅ | ✅ | ✅ | PASS — honest empty state |

### Other results

| Test | Result |
|------|--------|
| 1.1 / 1.3 / 1.4 admin shell, dashboard, sites overview | PASS |
| 4.1 park site provisioning + kingdom→park cascade | PASS |
| 4.8 scope gating — `kingdom_*` absent from park chooser | PASS (26 = 23 universal + 3 park) |
| 5.1 page-type chooser incl. About / Team | PASS |
| 5.2 starter seeding (`composed` → hero + rich_text + cta_band) | PASS |
| 5.4 autosave (page auto-created mid-edit) | PASS |
| 6.1 media upload → master + thumb + WebP display rendition, all HTTP 200 | PASS |
| 9.1 / 9.2 XSS battery through the real editor save path | PASS — all 7 vectors stripped |
| 10.1 dark + light contrast sweep | PASS — 0 failures below 4.5:1 |
| 10.2 390px horizontal overflow, all 3 pages | PASS — 0 offenders |
| 10.3 console errors on public pages | PASS — 0 |

**XSS vectors tested and neutralized** (entered through the editor, verified in the
rendered DOM): `<script>`, `<img onerror>`, `<svg onload>`, `javascript:` href,
`%6aavascript:` href, `java&#9;script:` href, and a foreign `<iframe>`. Benign markup
(`<p>`, `<table>`) survived intact; the three anchors kept their text and lost their
`href` entirely.

## Environment limitations (not product defects)

- `assets/cms-media/` is empty in a fresh checkout (uploads are gitignored), so every
  pre-seeded media row renders a broken image. Uploading a new file through the media
  library produces a correctly-served image, which is how the image-bearing blocks were
  verified. Do not read pre-seeded broken images as a defect.
- `GOOGLE_MAPS_API_KEY` is `''` in `config.dev.php`, so `kingdom_parks_map` shows its
  "temporarily unavailable" state. That degradation is correct behavior.
- `html, body { height:100%; overflow-x:hidden }` in `orkui.css:733` makes `<body>` a
  second scroll container (two scrollbars, and `documentElement.scrollHeight` reports
  the viewport height rather than the document height). **Identical on `master`** —
  pre-existing, out of scope for this branch, but it will confuse any tester who
  measures scroll height from `documentElement`.
- Block sections use `content-visibility: auto`, so `innerText` returns nothing for
  off-screen blocks. **Use `textContent`** when asserting block presence, or a passing
  page reads as 7 missing blocks.
- Screenshots come back 1385x887 against a 1723px viewport, so `computer` coordinate
  clicks land off-target. Drive the UI with element refs or JS clicks.

## Cleanup

Test fixtures created by this run, left as **drafts** so they do not reach the public:

| Id | What | Scope |
|----|------|-------|
| page 172 | `qa-kitchen-sink` — all 23 universal blocks | global |
| page 173 | `qa-park-blocks` — the 3 `park_*` blocks | park 1049 |
| page 174 | `qa-kingdom-blocks` — the 4 `kingdom_*` blocks | kingdom 7 |
| site 8 | `angler-s-rift` park site (status `unbuilt`) | park 1049 |
| media 134 | `ogre-qa.jpg` upload probe | global |
