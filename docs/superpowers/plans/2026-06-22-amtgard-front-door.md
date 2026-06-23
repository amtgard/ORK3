# Amtgard Front Door Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a working v1 prototype of a cinematic, photo-rich, newcomer-first "front door" landing page for the ORK, rendered from a CMS-ready content-block model, and relocate the existing Kingdoms Directory to its own route.

**Architecture:** The home route (base `Controller::index()`) exposes a structured `$FrontDoor` block list from a provider (`Model_FrontDoor::GetContent()`, returning hardcoded v1 defaults). A new generic renderer template `_index.tpl` iterates the blocks and `include`s one dumb partial per block `type` from `frontdoor/blocks/`. Dynamic blocks (events, kingdoms) are filled from data the controller already loads. The current directory page moves to a new `Controller_Directory` + `Directory_index.tpl`; `default.tpl` becomes a neutral fallback.

**Tech Stack:** PHP 8 (no Smarty — `.tpl` are plain PHP via `extract()`+`include`), vanilla JS/CSS, Docker (`ork3-php8-app`, app at `http://localhost:19080/orkui/`). No test framework — verification = `php -l` lint + curl smoke tests + browser checks.

---

## Decisions taken (chosen by me; revisit after prototype review)

1. **Home template filename = `_index.tpl`.** The View resolver builds `{controller}_{request}.tpl`; the base `Controller` has an *empty* controller string, so the home page uniquely resolves to `_index.tpl` before the `default.tpl` fallback. This is surgical (only the home route matches) and leaves `default.tpl` free to become a neutral fallback. *Alternative considered:* overwrite `default.tpl` — rejected because it's the catch-all fallback for any template-less route and would leak the marketing page onto them.
2. **Provider is model-only in v1 (no lib, no DB).** `Model_FrontDoor::GetContent()` returns hardcoded defaults. The CMS (v2) will move sourcing behind the same `GetContent()` seam into a `system/lib/ork3/class.FrontDoor.php` lib reading a content store. *Rationale:* v1 has no DB work, so the architecture's "DB in lib only" rule isn't triggered yet; the stable seam is the method, not the layer.
3. **Marketing nav + ORK header both shown on home** (front-door-only nav). Nav is the first block, rendered at the top of `#contents`. A `fd-home` body class is added so the theme/CSS can adjust spacing. *Not* suppressing the ORK header in v1 (lower risk). Revisit if it feels cluttered.
4. **v1 blocks shipped:** `marketing_nav`, `member_bar`, `hero_carousel`, `richtext` (What is Amtgard), `card_grid` (Find Your Path), `steps` (Your First Day), `events_feed`, `photo_mosaic`, `kingdoms_teaser`, `cta_band`. Reserved-but-not-emitted: `recap_highlight`, `tournaments_feed`, `blog_feed` (schema documented; partials deferred to keep the prototype focused on the approved mockup).
5. **Visual source of truth:** the approved mockup at `.superpowers/brainstorm/58642-1782178067/content/fullpage-newcomer.html`. Partials reproduce that markup, parameterized from `$fields`, using ORK theme variables and dark-mode overrides.

## File structure

**Create:**
- `orkui/model/model.FrontDoor.php` — `Model_FrontDoor`: `GetContent($ctx)` → ordered block array (v1 defaults).
- `orkui/controller/controller.Directory.php` — `Controller_Directory`: `index()` loads directory data + renders `Directory_index.tpl`.
- `orkui/template/default/_index.tpl` — front-door generic block renderer.
- `orkui/template/default/Directory_index.tpl` — relocated Kingdoms Directory (current `default.tpl` body).
- `orkui/template/default/frontdoor/css/frontdoor.css` — all `fd-` scoped styles + dark mode + responsive.
- `orkui/template/default/frontdoor/js/frontdoor.js` — hero carousel + nav mobile toggle.
- `orkui/template/default/frontdoor/blocks/{marketing_nav,member_bar,hero_carousel,richtext,card_grid,steps,events_feed,photo_mosaic,kingdoms_teaser,cta_band}.tpl` — one dumb partial per block type.
- `orkui/template/default/img/frontdoor/` — `hero-1.jpg … hero-8.jpg`, `amtgard-logo.avif`, `amtgard-logo.png`.

**Modify:**
- `system/lib/system/class.Controller.php` — `index()`: expose `$FrontDoor`, member-bar viewer identity, `IsFrontDoor` flag; keep existing dynamic data loads.
- `orkui/template/default/default.tpl` — replace directory body with a minimal neutral fallback.
- `orkui/template/default/default.theme` — add `fd-home` body class when `IsFrontDoor`; add a "Kingdoms" link to `Directory/index` near existing nav (low-touch).

---

## Task 0: Prepare & commit image assets

**Files:**
- Create: `orkui/template/default/img/frontdoor/hero-1.jpg` … `hero-8.jpg`, `amtgard-logo.avif`, `amtgard-logo.png`

- [ ] **Step 1: Create the directory and optimized assets**

```bash
cd /Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias
mkdir -p orkui/template/default/img/frontdoor
for n in 1 2 3 4 5 6 7 8; do
  sips -Z 1600 -s formatOptions 70 "$HOME/Downloads/amt-img-$n.jpg" \
    --out "orkui/template/default/img/frontdoor/hero-$n.jpg" >/dev/null
done
cp "$HOME/Downloads/amtgardlogo.avif" orkui/template/default/img/frontdoor/amtgard-logo.avif
sips -s format png "$HOME/Downloads/amtgardlogo.avif" \
  --out orkui/template/default/img/frontdoor/amtgard-logo.png >/dev/null
ls -la orkui/template/default/img/frontdoor/
```

Expected: 8 `hero-*.jpg` (each well under ~250 KB), `amtgard-logo.avif`, `amtgard-logo.png`.

- [ ] **Step 2: Commit**

```bash
git add orkui/template/default/img/frontdoor/
git commit -m "Front door: add optimized event photos + Amtgard logo assets"
```

---

## Task 1: FrontDoor content provider (`Model_FrontDoor`)

**Files:**
- Create: `orkui/model/model.FrontDoor.php`

Defines the v1 content. **Authored** blocks carry their content inline; **dynamic**
blocks carry only framing (`heading`, `limit`, `more_href`) and a `source` the
template fills from controller data. Image refs use the media-ref struct.

- [ ] **Step 1: Write the provider**

```php
<?php

class Model_FrontDoor extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    // Single content seam. v1 returns hardcoded defaults; v2 (CMS) will read a store here.
    // $ctx: ['logged_in'=>bool, 'kingdom_id'=>int, ...] — reserved for future scoping.
    public function GetContent($ctx = [])
    {
        $img = HTTP_TEMPLATE . 'default/img/frontdoor/';
        $logo = ['key' => 'logo', 'src' => $img . 'amtgard-logo.png', 'alt' => 'Amtgard'];

        $blocks = [];

        $blocks[] = [
            'id' => 'nav', 'type' => 'marketing_nav', 'enabled' => true, 'order' => 10, 'source' => 'authored',
            'fields' => [
                'logo' => $logo,
                'items' => [
                    ['label' => 'Home', 'href' => '#'],
                    ['label' => 'About', 'href' => '#', 'children' => [
                        ['label' => 'Mission', 'href' => '#'], ['label' => 'Staff', 'href' => '#'], ['label' => 'Volunteers', 'href' => '#'],
                    ]],
                    ['label' => 'Join', 'href' => '#', 'children' => [
                        ['label' => 'Learn the Basics', 'href' => '#'], ['label' => 'Find a Chapter', 'href' => '#'], ['label' => 'Start a Chapter', 'href' => '#'],
                    ]],
                    ['label' => 'AI Programs', 'href' => '#', 'children' => [
                        ['label' => 'Food Fight', 'href' => '#'], ['label' => 'Olympiad', 'href' => '#'],
                    ]],
                    ['label' => 'Media', 'href' => '#', 'children' => [
                        ['label' => 'Galleries', 'href' => '#'], ['label' => 'Writing', 'href' => '#'],
                    ]],
                    ['label' => 'Official Resources', 'href' => '#', 'children' => [
                        ['label' => 'Documents', 'href' => '#'],
                    ]],
                    ['label' => 'Merch', 'href' => 'https://www.redbubble.com/people/amtgardmarket/shop'],
                ],
                'cta' => ['label' => 'Find a Chapter', 'href' => '#'],
                'login' => ['label' => 'Record Keeper', 'href' => '#'],
            ],
        ];

        $blocks[] = [
            'id' => 'member', 'type' => 'member_bar', 'enabled' => true, 'order' => 20, 'source' => 'dynamic',
            'fields' => [],
        ];

        $blocks[] = [
            'id' => 'hero', 'type' => 'hero_carousel', 'enabled' => true, 'order' => 30, 'source' => 'authored',
            'fields' => [
                'logo' => $logo,
                'autoplay_ms' => 4500,
                'slides' => [
                    ['image' => ['key' => 'hero-1', 'src' => $img . 'hero-1.jpg', 'alt' => ''], 'kicker' => 'Worldwide Medieval Combat · Since 1983', 'headline' => 'Take the Field.', 'subcopy' => 'Safe boffer weapons, real glory. Step into a living world of heroic combat, quests, and craft.'],
                    ['image' => ['key' => 'hero-2', 'src' => $img . 'hero-2.jpg', 'alt' => ''], 'kicker' => 'Archery · Magic · Steel', 'headline' => 'Find Your Path.', 'subcopy' => 'Warrior, archer, healer, monster, crafter — there\'s a place for every kind of hero.'],
                    ['image' => ['key' => 'hero-7', 'src' => $img . 'hero-7.jpg', 'alt' => ''], 'kicker' => 'From First-Timers to Great Wars', 'headline' => 'Answer the Call.', 'subcopy' => 'Hundreds of chapters worldwide. Your first day on the field is always free.'],
                ],
                'ctas' => [
                    ['label' => 'Find Amtgard Near You', 'href' => '#', 'style' => 'gold'],
                    ['label' => 'Watch & Learn', 'href' => '#', 'style' => 'ghost'],
                ],
            ],
        ];

        $blocks[] = [
            'id' => 'whatis', 'type' => 'richtext', 'enabled' => true, 'order' => 40, 'source' => 'authored',
            'fields' => [
                'kicker' => 'New here?', 'heading' => 'What is Amtgard?', 'align' => 'center',
                'body' => 'Amtgard is a world-wide organization dedicated to medieval and fantasy combat sports and recreation. We use padded weapons, fantasy and authentic clothing, and imagination to immerse players in a world of heroic combat, quests, crafts, and more.',
                'cta' => ['label' => 'The full story →', 'href' => '#'],
            ],
        ];

        $blocks[] = [
            'id' => 'paths', 'type' => 'card_grid', 'enabled' => true, 'order' => 50, 'source' => 'authored',
            'fields' => [
                'kicker' => 'There\'s a place for you', 'heading' => 'Find Your Path',
                'subheading' => 'However you like to play, Amtgard has a role for you.',
                'cards' => [
                    ['image' => ['key' => 'hero-1', 'src' => $img . 'hero-1.jpg', 'alt' => ''], 'icon' => '⚔', 'title' => 'The Warrior', 'blurb' => 'Sword, shield, and the front line', 'href' => '#'],
                    ['image' => ['key' => 'hero-2', 'src' => $img . 'hero-2.jpg', 'alt' => ''], 'icon' => '🏹', 'title' => 'The Archer', 'blurb' => 'Ranged skill and battlefield control', 'href' => '#'],
                    ['image' => ['key' => 'hero-5', 'src' => $img . 'hero-5.jpg', 'alt' => ''], 'icon' => '✨', 'title' => 'The Caster', 'blurb' => 'Spells, healing, and the magic classes', 'href' => '#'],
                    ['image' => ['key' => 'hero-6', 'src' => $img . 'hero-6.jpg', 'alt' => ''], 'icon' => '🎨', 'title' => 'The Artisan', 'blurb' => 'Garb, armor, and craft (A&S)', 'href' => '#'],
                    ['image' => ['key' => 'hero-3', 'src' => $img . 'hero-3.jpg', 'alt' => ''], 'icon' => '🐉', 'title' => 'The Monster', 'blurb' => 'Quests, role-play, and the wilds', 'href' => '#'],
                    ['image' => ['key' => 'hero-8', 'src' => $img . 'hero-8.jpg', 'alt' => ''], 'icon' => '👑', 'title' => 'The Leader', 'blurb' => 'Reeving, office, and running the realm', 'href' => '#'],
                ],
            ],
        ];

        $blocks[] = [
            'id' => 'firstday', 'type' => 'steps', 'enabled' => true, 'order' => 60, 'source' => 'authored',
            'fields' => [
                'kicker' => 'It\'s easier than you think', 'heading' => 'Your First Day', 'band' => 'dark',
                'steps' => [
                    ['n' => 1, 'title' => 'Find a chapter', 'body' => 'Hundreds of parks meet weekly in public spaces. Find one near you.'],
                    ['n' => 2, 'title' => 'Just show up', 'body' => 'No experience or gear needed. Wear comfy clothes and bring water.'],
                    ['n' => 3, 'title' => 'Borrow a sword', 'body' => 'Chapters have loaner weapons. Take the field — your first day is free.'],
                ],
                'cta' => ['label' => 'Find Amtgard Near You', 'href' => '#'],
            ],
        ];

        $blocks[] = [
            'id' => 'events', 'type' => 'events_feed', 'enabled' => true, 'order' => 70, 'source' => 'dynamic',
            'fields' => ['kicker' => 'Come check one out', 'heading' => 'Upcoming Events', 'limit' => 3, 'more_href' => UIR . 'Search/event'],
        ];

        $blocks[] = [
            'id' => 'mosaic', 'type' => 'photo_mosaic', 'enabled' => true, 'order' => 80, 'source' => 'authored',
            'fields' => [
                'caption' => 'This is Amtgard',
                'images' => [
                    ['key' => 'hero-7', 'src' => $img . 'hero-7.jpg', 'alt' => ''],
                    ['key' => 'hero-4', 'src' => $img . 'hero-4.jpg', 'alt' => ''],
                    ['key' => 'hero-6', 'src' => $img . 'hero-6.jpg', 'alt' => ''],
                    ['key' => 'hero-3', 'src' => $img . 'hero-3.jpg', 'alt' => ''],
                ],
            ],
        ];

        $blocks[] = [
            'id' => 'kingdoms', 'type' => 'kingdoms_teaser', 'enabled' => true, 'order' => 90, 'source' => 'dynamic',
            'fields' => ['kicker' => 'Explore the realm', 'heading' => 'Kingdoms Around the World', 'limit' => 12, 'more_href' => UIR . 'Directory/index'],
        ];

        $blocks[] = [
            'id' => 'getinvolved', 'type' => 'cta_band', 'enabled' => true, 'order' => 100, 'source' => 'authored',
            'fields' => [
                'logo' => $logo,
                'heading' => 'Ready to take up arms?',
                'subcopy' => 'There\'s a chapter near you, and your first day on the field is always free.',
                'ctas' => [
                    ['label' => 'Find Amtgard Near You', 'href' => '#', 'style' => 'gold'],
                    ['label' => 'Official Resources', 'href' => '#', 'style' => 'ghost'],
                ],
                'links' => 'amtgard.com · play.amtgard.com · Online Record Keeper',
            ],
        ];

        // Stable order; CMS will reorder via 'order' later.
        usort($blocks, function ($a, $b) { return $a['order'] <=> $b['order']; });
        return $blocks;
    }
}
```

- [ ] **Step 2: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/model/model.FrontDoor.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/model/model.FrontDoor.php
git commit -m "Front door: add Model_FrontDoor content provider (v1 defaults)"
```

---

## Task 2: Wire the home controller to expose `$FrontDoor`

**Files:**
- Modify: `system/lib/system/class.Controller.php` (`index()`, around lines 133–192)

Keep all existing data loads (they feed dynamic blocks). Add: the FrontDoor block
list, a viewer display name for the member bar, and an `IsFrontDoor` flag.

- [ ] **Step 1: Add provider + member identity at the end of `index()`**

Find, near the end of `index()` (after `$this->data['EventSummary'] = $eventSummary;` and before the `menu` assignments), insert:

```php
		// ---- Front door (v1 content-model landing) ----
		$this->load_model( 'FrontDoor' );
		$this->data[ 'IsFrontDoor' ] = true;
		$this->data[ 'FrontDoor' ] = $this->FrontDoor->GetContent( [
			'logged_in'  => (bool) $this->data['LoggedIn'],
			'kingdom_id' => (int) ( $this->data['UserKingdomId'] ?? 0 ),
		] );

		// Display name for the member bar (logged-in only)
		$this->data[ 'ViewerName' ] = '';
		if ( $this->data['LoggedIn'] && isset( $this->session->user_id ) ) {
			global $DB;
			$DB->Clear();
			$_vid = (int) $this->session->user_id;
			$_vn = $DB->DataSet( "SELECT persona, given_name FROM " . DB_PREFIX . "mundane WHERE mundane_id = {$_vid} LIMIT 1" );
			if ( $_vn && $_vn->Next() ) {
				$this->data[ 'ViewerName' ] = trim( (string) $_vn->persona ) !== '' ? $_vn->persona : $_vn->given_name;
			}
			$DB->Clear();
		}
```

> Column names confirmed against `ork_mundane`: display name is `persona` (fall back
> to `given_name`). Other name columns are `surname`, `other_name`, `username`.

- [ ] **Step 2: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/system/class.Controller.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add system/lib/system/class.Controller.php
git commit -m "Front door: expose FrontDoor block list + viewer name from home controller"
```

---

## Task 3: Relocate the Kingdoms Directory to `/Directory`

**Files:**
- Create: `orkui/controller/controller.Directory.php`
- Create: `orkui/template/default/Directory_index.tpl`
- Modify: `orkui/template/default/default.tpl` (replace body with neutral fallback)

- [ ] **Step 1: Create the Directory controller**

```php
<?php

class Controller_Directory extends Controller
{
    // The Kingdoms Directory — formerly the home page. Reuses the base
    // Controller::index() data loads (kingdom summary, events, recap, home-kingdom
    // pinning), then renders Directory_index.tpl.
    public function index( $action = null )
    {
        parent::index( $action );
        $this->data[ 'page_title' ] = 'Kingdoms Directory';
        // We do not need the front-door payload here.
        $this->data[ 'IsFrontDoor' ] = false;
    }
}
```

- [ ] **Step 2: Move the directory markup into `Directory_index.tpl`**

Copy the *entire current contents* of `orkui/template/default/default.tpl` into the new
`orkui/template/default/Directory_index.tpl` verbatim, then change the welcome
title text:

In `Directory_index.tpl`, change:
```php
	<h1 class="hm-welcome-title">Welcome to the Amtgard Online Record Keeper</h1>
```
to:
```php
	<h1 class="hm-welcome-title">Kingdoms Directory</h1>
```

- [ ] **Step 3: Replace `default.tpl` with a neutral fallback**

Overwrite `orkui/template/default/default.tpl` with:
```php
<?php
/*
 * Generic fallback template. The home/front-door page renders via _index.tpl
 * and the Kingdoms Directory via Directory_index.tpl. This file only renders
 * when a controller/request has no specific template — keep it neutral.
 */
?>
<?php if ( ! empty( $Message ) ): ?>
	<div class="hm-infobox" style="margin:16px"><?= htmlspecialchars( $Message ) ?></div>
<?php endif; ?>
```

- [ ] **Step 4: Lint both PHP files**

Run:
```
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.Directory.php
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/default/Directory_index.tpl
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Smoke-test the Directory route**

Run:
```
curl -s "http://localhost:19080/orkui/index.php?Route=Directory/index" | grep -c "hm-kingdom-card\|Kingdoms Directory"
```
Expected: a non-zero count (directory grid rendered).

- [ ] **Step 6: Commit**

```bash
git add orkui/controller/controller.Directory.php orkui/template/default/Directory_index.tpl orkui/template/default/default.tpl
git commit -m "Front door: relocate Kingdoms Directory to /Directory; neutral default.tpl fallback"
```

---

## Task 4: Front-door renderer, CSS, and JS

**Files:**
- Create: `orkui/template/default/_index.tpl`
- Create: `orkui/template/default/frontdoor/css/frontdoor.css`
- Create: `orkui/template/default/frontdoor/js/frontdoor.js`

- [ ] **Step 1: Write the generic renderer `_index.tpl`**

```php
<?php
/*
 * Front door — generic content-block renderer.
 * Iterates $FrontDoor blocks (ordered, enabled) and includes one partial per type.
 * Partials are "dumb": they render $blockFields (+ shared $data) and fetch nothing.
 */
$fdBlocks = isset( $FrontDoor ) && is_array( $FrontDoor ) ? $FrontDoor : [];
$fdDir    = DIR_TEMPLATE . $this->settings->theme . '/frontdoor/';
$fdBlockDir = $fdDir . 'blocks/';
$fdAssetBase = HTTP_TEMPLATE . $this->settings->theme . '/frontdoor/';
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=MedievalSharp&display=swap">
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/frontdoor.css">

<div class="fd-page">
<?php
foreach ( $fdBlocks as $block ) {
	if ( empty( $block['enabled'] ) ) { continue; }
	$type = preg_replace( '/[^a-z_]/', '', (string) $block['type'] );
	$partial = $fdBlockDir . $type . '.tpl';
	if ( ! file_exists( $partial ) ) { continue; }
	$blockFields = isset( $block['fields'] ) && is_array( $block['fields'] ) ? $block['fields'] : [];
	$blockMeta   = $block;
	include $partial;
}
?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
```

> NOTE: `extract()` in View already put `$FrontDoor`, `$LoggedIn`, `$EventSummary`,
> `$ActiveKingdomSummary`, `$ViewerName`, etc. into scope. Partials read those plus
> the local `$blockFields`. `$this` is the controller (so `$this->settings->theme`
> works) since the template is `include`d within `View::view()` — **verify**: if
> `$this` is not available in template scope, replace with the global theme path
> `DIR_TEMPLATE . 'default/frontdoor/'` and `HTTP_TEMPLATE . 'default/frontdoor/'`.

- [ ] **Step 2: Verify `$this`/theme availability assumption**

Run:
```
curl -s "http://localhost:19080/orkui/index.php?Route=" | grep -c "fd-page\|frontdoor/css"
```
Expected: non-zero (renderer emitted the wrapper + stylesheet link). If zero, check
`docker logs ork3-php8-app` for a fatal about `$this` and switch to the global-path
fallback noted above.

- [ ] **Step 3: Write `frontdoor.css`**

Adapt the `<style>` block from the approved mockup
`.superpowers/brainstorm/58642-1782178067/content/fullpage-newcomer.html` into a
standalone stylesheet. Requirements:
- Prefix every class `fd-` (already done in mockup). Define the palette vars on `.fd-page` (`--navy:#0b1120; --navy2:#121a30; --gold:#f0b429; --ink:#1a2236;`).
- Bring over: `.fd-serif, .fd-kicker(.fd-kicker-d), .fd-sec-title, .fd-pad, .fd-btn-gold, .fd-btn-ghost, .fd-link, .fd-card, .fd-carousel/.fd-slide/.fd-slide-*/.fd-dots/.fd-dot, .fd-path*, .fd-nav/.fd-navlinks/.fd-navitem/.fd-dropdown/.fd-nav-cta/.fd-nav-login`.
- **Heading reset:** any `.fd-page h1,h2,h3,h4,h5,h6` used as display must reset the global orkui.css gray box: `background:transparent;border:none;padding:0;border-radius:0;text-shadow:none;`.
- **Dark mode** (`html[data-theme="dark"] .fd-page ...`): light bands (`#fff`, `#f7f8fb`), `.fd-card`, `.fd-nav`, `.fd-sec-title`, body copy colors get dark equivalents. Navy bands already dark — leave them. Use the existing `--ork-*` variables where it makes the section blend with app chrome.
- **Responsive:** `@media (max-width:900px)` collapse `card_grid`/events/kingdoms grids to 2 cols and the mosaic to a stack; `@media (max-width:680px)` carousel height shrinks (e.g. 380px), hero headline to ~36px, nav links hide behind a `.fd-nav-toggle` button (mobile menu), member bar wraps.

- [ ] **Step 4: Write `frontdoor.js`**

```js
(function () {
  // Hero carousel: auto-advance, clickable dots, pause on interaction.
  document.querySelectorAll('.fd-carousel').forEach(function (car) {
    var slides = car.querySelectorAll('.fd-slide');
    var dots = car.querySelectorAll('.fd-dot');
    if (slides.length < 2) return;
    var i = 0, ms = parseInt(car.getAttribute('data-autoplay') || '4500', 10), t;
    function go(n) {
      slides[i].classList.remove('is-active'); if (dots[i]) dots[i].classList.remove('on');
      i = (n + slides.length) % slides.length;
      slides[i].classList.add('is-active'); if (dots[i]) dots[i].classList.add('on');
    }
    function start() { t = setInterval(function () { go(i + 1); }, ms); }
    dots.forEach(function (d, idx) { d.addEventListener('click', function () { clearInterval(t); go(idx); start(); }); });
    start();
  });
  // Mobile nav toggle
  var nav = document.querySelector('.fd-nav');
  var toggle = document.querySelector('.fd-nav-toggle');
  if (nav && toggle) {
    toggle.addEventListener('click', function () { nav.classList.toggle('fd-nav-open'); });
  }
})();
```

- [ ] **Step 5: Commit (renderer + assets; partials land in Task 5)**

```bash
git add orkui/template/default/_index.tpl orkui/template/default/frontdoor/css/frontdoor.css orkui/template/default/frontdoor/js/frontdoor.js
git commit -m "Front door: generic block renderer + frontdoor.css + carousel JS"
```

---

## Task 5: Block partials

**Files (create each):**
- `orkui/template/default/frontdoor/blocks/marketing_nav.tpl`
- `orkui/template/default/frontdoor/blocks/member_bar.tpl`
- `orkui/template/default/frontdoor/blocks/hero_carousel.tpl`
- `orkui/template/default/frontdoor/blocks/richtext.tpl`
- `orkui/template/default/frontdoor/blocks/card_grid.tpl`
- `orkui/template/default/frontdoor/blocks/steps.tpl`
- `orkui/template/default/frontdoor/blocks/events_feed.tpl`
- `orkui/template/default/frontdoor/blocks/photo_mosaic.tpl`
- `orkui/template/default/frontdoor/blocks/kingdoms_teaser.tpl`
- `orkui/template/default/frontdoor/blocks/cta_band.tpl`

**Shared rules for every partial:**
- Plain PHP (`<?php ?>`/`<?= ?>`). Read `$blockFields` (+ shared `$data` vars like `$LoggedIn`, `$EventSummary`, `$ActiveKingdomSummary`, `$ViewerName`, `UIR`).
- Escape every authored string with `htmlspecialchars()`. The only HTML-passthrough field is `richtext.body` (documented rich-text) — still run through a minimal allowlist is overkill for v1 placeholder copy, so emit as-is but add a `// rich-text passthrough` comment.
- Markup mirrors the matching section of the approved mockup `fullpage-newcomer.html`; classes already match `frontdoor.css`.

Each partial is independent — these can be implemented in parallel.

- [ ] **Step 1: `marketing_nav.tpl`** — render `$blockFields['logo']` (img), `items[]` with hover `.fd-dropdown` for any item having `children[]`, right-side `login` link + `cta` button, and a `.fd-nav-toggle` button for mobile. Mirror the `.fd-nav` markup from the mockup.

- [ ] **Step 2: `member_bar.tpl`** — render only `if (!empty($LoggedIn))`. Show "Welcome back, {ViewerName}" (escaped; fall back to "Welcome back" if empty) + links: Your Park (`UIR.'Park/profile/'...` — use `$UserKingdomId`? park id unknown here, so link to `UIR.'Live'` and `UIR.'Search/index'`), Live Attendance (`UIR.'Live'`), Member Tools (`UIR.'Admin'`). Mirror `.fd-nav`/member-bar markup (navy strip). *Decision:* member bar links are a fixed minimal set in v1 (Your Kingdom via `$UserKingdomId`, Live Attendance, Admin). Revisit later.

- [ ] **Step 3: `hero_carousel.tpl`** — render `slides[]` as `.fd-slide` (first gets `is-active`), each with `<img class=fd-slide-img>`, scrim, kicker/headline/subcopy, shared `ctas[]` buttons (`style` → `.fd-btn-gold`/`.fd-btn-ghost`). Logo top-left. Dots = one `.fd-dot` per slide (first `on`). Set `data-autoplay="<?= (int)$blockFields['autoplay_ms'] ?>"` on `.fd-carousel`. Append the **stat ticker** strip computed from `$ActiveKingdomSummary` (kingdom count, park total, players/week — reuse the math from `Directory_index.tpl`'s `$hmKingdoms`/`$hmTotalParks`/`$hmWeeklyAvg`) + events/month from `count($EventSummary)`. Guard all with `?? 0`.

- [ ] **Step 4: `richtext.tpl`** — kicker, heading, body (rich-text passthrough), optional `cta`; honor `align`. Centered band on white.

- [ ] **Step 5: `card_grid.tpl`** — kicker/heading/subheading + `.fd-path` cards from `cards[]` (image, icon+title overlay, blurb), each linking `href`. Light band.

- [ ] **Step 6: `steps.tpl`** — kicker/heading + numbered `steps[]` (gold circle, title, body) + centered `cta`; `band==='dark'` → navy background.

- [ ] **Step 7: `events_feed.tpl`** — heading + up to `limit` cards from `$EventSummary`. Each card: date badge, event name, kingdom name, optional RSVP count (`RsvpCount`). Use the same fields `Directory_index.tpl` uses for events. `more_href` link. If `$EventSummary` empty, render a `.fd-empty` "No upcoming events" note.

- [ ] **Step 8: `photo_mosaic.tpl`** — CSS-grid mosaic from `images[]` (first image spans 2 rows) + a navy caption tile with `caption`. Mirror mockup mosaic.

- [ ] **Step 9: `kingdoms_teaser.tpl`** — heading + up to `limit` kingdom tiles from `$ActiveKingdomSummary['ActiveKingdomsSummaryList']` (parent kingdoms only, `ParentKingdomId==0`), each = heraldry img (`HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(...)` exactly as `Directory_index.tpl` does) + name, linking `UIR.'Kingdom/profile/'.id`. Final tile = "+N more →" linking `more_href` (`Directory/index`).

- [ ] **Step 10: `cta_band.tpl`** — navy band: logo, heading, subcopy, `ctas[]` buttons, `links` line.

- [ ] **Step 11: Lint all partials**

Run:
```
for f in orkui/template/default/frontdoor/blocks/*.tpl; do docker exec ork3-php8-app php -l "/var/www/ork.amtgard.com/$f" || echo "FAIL $f"; done
```
Expected: `No syntax errors detected` for all; no `FAIL` lines.

- [ ] **Step 12: Commit**

```bash
git add orkui/template/default/frontdoor/blocks/
git commit -m "Front door: block partials (nav, hero, paths, steps, events, mosaic, kingdoms, CTA)"
```

---

## Task 6: Theme flag + Directory nav link

**Files:**
- Modify: `orkui/template/default/default.theme`

- [ ] **Step 1: Add `fd-home` body class on the front door**

In `default.theme`, find the `<body ...>` tag (around line 124) and add the class when `$IsFrontDoor` is set:

```php
	<body class="<?= !empty($ViewerDyslexiaFonts) ? 'ork-dyslexia-font ' : '' ?><?= !empty($IsFrontDoor) ? 'fd-home' : '' ?>">
```
(Adjust to preserve the existing dyslexia-font logic exactly — merge, don't replace.)

- [ ] **Step 2: Add a Kingdoms Directory link to the resources dropdown**

In `default.theme`, find the resources dropdown (`id='nav-resources-wrap'`, around line 265) and add, alongside the existing items:

```php
					<a href='<?=UIR?>Directory/index'><i class='fas fa-crown'></i> Kingdoms Directory</a>
```

- [ ] **Step 3: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/default/default.theme`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add orkui/template/default/default.theme
git commit -m "Front door: fd-home body flag + Kingdoms Directory nav link"
```

---

## Task 7: End-to-end verification

- [ ] **Step 1: Anonymous front door renders**

Run:
```
curl -s "http://localhost:19080/orkui/index.php?Route=" > /tmp/fd.html
grep -c "fd-carousel" /tmp/fd.html        # hero present
grep -c "What is Amtgard" /tmp/fd.html    # education present
grep -c "fd-nav" /tmp/fd.html             # marketing nav present
grep -c "Welcome back" /tmp/fd.html       # should be 0 (anonymous)
```
Expected: carousel/education/nav ≥1; "Welcome back" = 0.

- [ ] **Step 2: No PHP errors**

Run: `docker logs --since 2m ork3-php8-app 2>&1 | grep -iE "fatal|parse error|undefined" | head`
Expected: no front-door-related fatals.

- [ ] **Step 3: Logged-in member bar appears**

Using the curl-auth session pattern (login via `Login/login` with a known dev user, single cookie jar, one block), fetch `Route=` and confirm `Welcome back` count ≥1. (See memory: reference_local_curl_auth_session.)

- [ ] **Step 4: Directory still works**

Run: `curl -s "http://localhost:19080/orkui/index.php?Route=Directory/index" | grep -c "Kingdoms Directory"`
Expected: ≥1.

- [ ] **Step 5: Browser verification (Claude-in-Chrome)**

Open `http://localhost:19080/orkui/index.php?Route=` and verify: carousel auto-advances and dots work; nav dropdowns open on hover; photos load; sections styled per mockup. Toggle dark mode and walk every section. Resize to mobile width and confirm grids collapse + nav toggles. (Per memory: Chrome is for post-implementation verification.)

- [ ] **Step 6: Final commit if any fixes were needed**

```bash
git add -p
git commit -m "Front door: verification fixes"
```

---

## Self-review notes (coverage vs spec)

- Content-model/CMS seam → Tasks 1 (provider), 4 (renderer), 5 (dumb partials). ✅
- Block schema (id/type/enabled/order/source/fields, media refs) → Task 1. ✅
- Directory relocation + neutral fallback → Task 3. ✅
- Marketing nav (front-door-only) + dropdowns → Tasks 1, 5(nav), 6(flag). ✅
- Adaptive member bar → Tasks 2 (ViewerName), 5(member_bar). ✅
- Hero carousel + stat ticker, education (what-is/paths/first-day), events, mosaic, kingdoms teaser, CTA → Tasks 1/5. ✅
- Dark mode, responsive, heading reset, escaping → Task 4 (CSS) + Task 5 (shared rules). ✅
- Reserved future block types (recap/tournaments/blog) → documented, partials deferred (Decision 4). ✅
- Verification (anonymous, logged-in, directory, browser, dark, mobile) → Task 7. ✅
