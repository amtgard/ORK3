# Hero Banner Port — Park / Kingdom / Player / Unit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the event hero-banner feature (full-bleed image with framing, vignette, host-editable upload modal) to the Park, Kingdom, Player, and Unit profile pages, mechanically duplicating the pattern with `pk-`/`kn-`/`pn-`/`un-` selector prefixes.

**Architecture:** Schema first (one combined migration on `ork_park`/`ork_kingdom`/`ork_mundane`/`ork_unit`), then config constants, then per-entity model hydration + read-only template render to prove the display layer works, then shared CSS, then AJAX endpoints, then modal markup + JS IIFEs, then polish. Player banner is stored on `ork_mundane` (not `ork_player`) — one banner per real person, mirroring how heraldry already works.

**Tech Stack:** PHP 8 / MariaDB / vanilla JS / inline-template CSS, Yapo ORM + raw `$DB->DataSet`, custom Authorization library, Docker dev environment on port 19080.

**Reference spec:** `docs/superpowers/specs/2026-05-17-hero-banner-port-design.md`
**Reference implementation:** Event banner in `feature/event-planning-expansion` — see `Eventnew_index.tpl`, `controller.EventAjax.php:977-1095`, `revised.css:4014-4160`, `revised.js:15054-15424`.

---

## Branch & Working Directory

- [ ] **Step 0: Create branch off master**

```bash
git checkout master
git pull origin master
git checkout -b feature/hero-banner-port
```

---

## Task 1: Database Migration

**Files:**
- Create: `db-migrations/2026-05-17-add-entity-banners.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Hero banner image for Park, Kingdom, Player (via ork_mundane), and Unit.
-- Mirrors the ork_event banner pattern (2026-05-10-add-event-banner.sql +
-- 2026-05-11-add-banner-offset.sql), applied to four entities at once.
--
-- Five columns per table:
--   has_banner       0/1 — is a banner image saved?
--   banner_show_logo 0/1 — render the entity logo over the banner? (default on)
--   banner_vignette  0/1 — darken+blur the left/bottom edges? (default on)
--   banner_offset_x  0..100 — CSS background-position-x percent (default 50 = center)
--   banner_offset_y  0..100 — CSS background-position-y percent (default 50 = center)

ALTER TABLE ork_park
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0 AFTER has_heraldry,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1 AFTER has_banner,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1 AFTER banner_show_logo,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;

ALTER TABLE ork_kingdom
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0 AFTER has_heraldry,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1 AFTER has_banner,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1 AFTER banner_show_logo,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;

ALTER TABLE ork_mundane
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0 AFTER has_heraldry,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1 AFTER has_banner,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1 AFTER banner_show_logo,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;

ALTER TABLE ork_unit
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0 AFTER has_heraldry,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1 AFTER has_banner,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1 AFTER banner_show_logo,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;
```

- [ ] **Step 2: Apply the migration**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-17-add-entity-banners.sql`
Expected: no output (success). If output appears, treat any non-"Note: ..." line as an error.

- [ ] **Step 3: Verify columns landed on all four tables**

Run: `docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_park LIKE 'banner%'; SHOW COLUMNS FROM ork_kingdom LIKE 'banner%'; SHOW COLUMNS FROM ork_mundane LIKE 'banner%'; SHOW COLUMNS FROM ork_unit LIKE 'banner%';"`
Expected: each table shows `has_banner`, `banner_show_logo`, `banner_vignette`, `banner_offset_x`, `banner_offset_y` rows.

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-05-17-add-entity-banners.sql
git commit -m "Enhancement: Banner schema for park/kingdom/mundane/unit"
```

---

## Task 2: Config Constants + Storage Directories

**Files:**
- Modify: `config.dev.php:24-25, 51`
- Modify: `config.dist.php:24-25, 51`
- Create (filesystem only): `assets/heraldry/{park,kingdom,player,unit}-banner/`

- [ ] **Step 1: Add HTTP banner constants to config.dev.php**

After the existing line `define('HTTP_EVENT_BANNER',   HTTP_HERALDRY . 'event-banner/');` (line 24), add:

```php
define('HTTP_PARK_BANNER',    HTTP_HERALDRY . 'park-banner/');
define('HTTP_KINGDOM_BANNER', HTTP_HERALDRY . 'kingdom-banner/');
define('HTTP_PLAYER_BANNER',  HTTP_HERALDRY . 'player-banner/');
define('HTTP_UNIT_BANNER',    HTTP_HERALDRY . 'unit-banner/');
```

- [ ] **Step 2: Add DIR banner constants to config.dev.php**

After the existing line `define('DIR_EVENT_BANNER',   DIR_HERALDRY . "event-banner/");` (line 51), add:

```php
define('DIR_PARK_BANNER',    DIR_HERALDRY . "park-banner/");
define('DIR_KINGDOM_BANNER', DIR_HERALDRY . "kingdom-banner/");
define('DIR_PLAYER_BANNER',  DIR_HERALDRY . "player-banner/");
define('DIR_UNIT_BANNER',    DIR_HERALDRY . "unit-banner/");
```

- [ ] **Step 3: Mirror the same eight defines in config.dist.php**

Same eight `define(...)` lines in the matching positions (after `HTTP_EVENT_BANNER` for the HTTP four; after `DIR_EVENT_BANNER` for the DIR four).

- [ ] **Step 4: Create storage directories**

The AJAX controller `@mkdir`s these on first upload, but pre-create them so initial uploads succeed even if the container's www-data lacks `+w` on `DIR_HERALDRY`:

```bash
docker exec ork3-php8 sh -c 'mkdir -p /var/www/html/assets/heraldry/{park,kingdom,player,unit}-banner && chown -R www-data:www-data /var/www/html/assets/heraldry'
```

Expected: no output.

- [ ] **Step 5: Verify the constants resolve**

Run: `docker exec ork3-php8 php -r "require '/var/www/html/config.dev.php'; echo HTTP_PARK_BANNER, PHP_EOL, DIR_PARK_BANNER, PHP_EOL;"`
Expected: two lines, the URL and filesystem path for park-banner/.

- [ ] **Step 6: Commit**

```bash
git add config.dev.php config.dist.php
git commit -m "Enhancement: Banner storage constants for park/kingdom/player/unit"
```

---

## Task 3: Park Model Hydration

**Files:**
- Modify: `system/lib/ork3/class.Park.php` (the `GetParkDetails` method around line 458)

- [ ] **Step 1: Find the two HasHeraldry assignment lines in class.Park.php**

Run: `grep -n "HasHeraldry" system/lib/ork3/class.Park.php`
Expected: lines 458 (`$response['ParkInfo']['HasHeraldry']`) and 483 (`$response['HasHeraldry']`).

- [ ] **Step 2: Add banner hydration block right after the line-458 HasHeraldry assignment**

Use Python (per memory rule for multi-line PHP edits):

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Park.php')
t = p.read_text()
old = "\t\t\t$response[ 'ParkInfo' ][ 'HasHeraldry' ] = $this->park->has_heraldry;"
new = old + """
\t\t\tglobal $DB;
\t\t\t$DB->Clear();
\t\t\t$_bn = $DB->DataSet("SELECT has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM ork_park WHERE park_id = " . (int)$request['ParkId']);
\t\t\tif ($_bn && $_bn->Next()) {
\t\t\t\t$response['ParkInfo']['HasBanner']      = (int)$_bn->has_banner;
\t\t\t\t$response['ParkInfo']['BannerShowLogo'] = (int)$_bn->banner_show_logo;
\t\t\t\t$response['ParkInfo']['BannerVignette'] = (int)$_bn->banner_vignette;
\t\t\t\t$response['ParkInfo']['BannerOffsetX'] = (int)$_bn->banner_offset_x;
\t\t\t\t$response['ParkInfo']['BannerOffsetY'] = (int)$_bn->banner_offset_y;
\t\t\t}"""
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
PY
```

Expected console: `found: True`.

**Why this nesting:** the Park template reads `$park_info['ParkInfo']['HasHeraldry']`, so the banner keys must sit next to it in the same array. The exact `$request[...]` key may differ from `'ParkId'` — adjust to whatever the existing method uses two lines above. Verify by reading the surrounding 10 lines.

- [ ] **Step 3: Set has_banner=1 on a test park to enable visual verification later**

Pick an existing park ID for testing — find one with: `docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT park_id, name FROM ork_park ORDER BY park_id LIMIT 1;"`
Note the park_id. For the rest of this plan, refer to it as `$TEST_PARK_ID`.

Run: `docker exec ork3-php8-db mariadb -u root -proot ork -e "UPDATE ork_park SET has_banner=1 WHERE park_id=$TEST_PARK_ID;"`

- [ ] **Step 4: Verify hydration via temporary die**

Temporarily add `die(json_encode($response['ParkInfo'], JSON_PRETTY_PRINT));` immediately after the new hydration block. Load `http://localhost:19080/orkui/Park/index/$TEST_PARK_ID` in the browser.
Expected: JSON payload includes `"HasBanner": 1, "BannerShowLogo": 1, "BannerVignette": 1, "BannerOffsetX": 50, "BannerOffsetY": 50`.

- [ ] **Step 5: Remove the debug die and commit**

```bash
git add system/lib/ork3/class.Park.php
git commit -m "Enhancement: Hydrate park banner fields"
```

---

## Task 4: Park Template — Top Resolution + Hero Assembly (read-only display)

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (top-of-file PHP block + hero element)

This task wires the template to *display* a banner. The edit pill and modal come later in Task 16 — verify display works on its own first.

- [ ] **Step 1: Locate the heraldry block at top of template**

Run: `grep -n "heraldryUrl\|hasHeraldry" orkui/template/revised-frontend/Parknew_index.tpl | head -5`
Expected: lines around 4-6 in the top PHP block.

- [ ] **Step 2: Add banner-state resolution after heraldry resolution**

Insert (via Python edit) immediately after the line `$hasHeraldry = !empty($parkInfo['HasHeraldry']);`:

```php
$hasBanner       = !empty($parkInfo['HasBanner']);
$bannerShowLogo  = !isset($parkInfo['BannerShowLogo']) || (int)$parkInfo['BannerShowLogo'] !== 0;
$bannerVignette  = !isset($parkInfo['BannerVignette']) || (int)$parkInfo['BannerVignette'] !== 0;
$bannerOffsetX   = isset($parkInfo['BannerOffsetX']) ? max(0, min(100, (int)$parkInfo['BannerOffsetX'])) : 50;
$bannerOffsetY   = isset($parkInfo['BannerOffsetY']) ? max(0, min(100, (int)$parkInfo['BannerOffsetY'])) : 50;
$bannerUrl       = '';
if ($hasBanner) {
    $bannerFile = Common::resolve_image_ext(DIR_PARK_BANNER, sprintf('%05d', (int)($parkInfo['ParkId'] ?? 0)));
    $bannerFs   = DIR_PARK_BANNER . $bannerFile;
    if (file_exists($bannerFs)) {
        $bannerUrl = HTTP_PARK_BANNER . $bannerFile . '?v=' . filemtime($bannerFs);
    }
}
$pkCanManageBanner = !empty($CanManagePark);
```

- [ ] **Step 3: Locate the hero element**

Run: `grep -n "pk-hero\b" orkui/template/revised-frontend/Parknew_index.tpl | head -10`
Find the `<div class="pk-hero">` opening and `<div class="pk-hero-bg">` line.

- [ ] **Step 4: Replace the hero opening div + bg div with banner-aware versions**

Use Python to replace the original two lines. Approximate before/after:

Before (find the exact text in the file first):
```html
<div class="pk-hero">
    <div class="pk-hero-bg" style="background-image:url('<?=htmlspecialchars($heraldryUrl)?>')"></div>
```

After:
```php
<?php
    $_heroBgUrl    = $bannerUrl ?: $heraldryUrl;
    $_heroClasses  = 'pk-hero';
    if ($bannerUrl)                            $_heroClasses .= ' pk-hero-has-banner';
    if ($bannerUrl && $bannerVignette)         $_heroClasses .= ' pk-hero-vignette';
    if ($pkCanManageBanner)                    $_heroClasses .= ' pk-hero-editable';
    $_pkShowLogo = !$bannerUrl || $bannerShowLogo;
    $_bgStyle = '';
    if ($_heroBgUrl) {
        $_bgStyle = 'background-image: url(\'' . htmlspecialchars($_heroBgUrl) . '\');';
        if ($bannerUrl) {
            $_bgStyle .= ' background-position: ' . $bannerOffsetX . '% ' . $bannerOffsetY . '%;';
        }
    }
?>
<div class="<?= $_heroClasses ?>" id="pk-hero">
    <div class="pk-hero-bg"<?php if ($_bgStyle): ?> style="<?= $_bgStyle ?>"<?php endif; ?>></div>
```

Python edit (verify exact `old` from the file first):
```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Parknew_index.tpl')
t = p.read_text()
old = """<div class="pk-hero">
\t<div class="pk-hero-bg" style="background-image:url('<?=htmlspecialchars($heraldryUrl)?>')"></div>"""
new = """<?php
\t$_heroBgUrl    = $bannerUrl ?: $heraldryUrl;
\t$_heroClasses  = 'pk-hero';
\tif ($bannerUrl)                    $_heroClasses .= ' pk-hero-has-banner';
\tif ($bannerUrl && $bannerVignette) $_heroClasses .= ' pk-hero-vignette';
\tif ($pkCanManageBanner)            $_heroClasses .= ' pk-hero-editable';
\t$_pkShowLogo = !$bannerUrl || $bannerShowLogo;
\t$_bgStyle = '';
\tif ($_heroBgUrl) {
\t\t$_bgStyle = 'background-image: url(\\'' . htmlspecialchars($_heroBgUrl) . '\\');';
\t\tif ($bannerUrl) {
\t\t\t$_bgStyle .= ' background-position: ' . $bannerOffsetX . '% ' . $bannerOffsetY . '%;';
\t\t}
\t}
?>
<div class="<?= $_heroClasses ?>" id="pk-hero">
\t<div class="pk-hero-bg"<?php if ($_bgStyle): ?> style="<?= $_bgStyle ?>"<?php endif; ?>></div>"""
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
PY
```

**Note:** the exact `old` string may have spaces vs tabs or slightly different formatting. If `found: False`, read lines around the `<div class="pk-hero">` directly and adjust the Python `old` to match byte-for-byte.

- [ ] **Step 5: Save a test banner image to the test park's filesystem location**

Pick any 1800×240 (or smaller) JPEG. Copy it into the container:
```bash
docker cp /tmp/test-banner.jpg ork3-php8:/var/www/html/assets/heraldry/park-banner/$(printf "%05d" $TEST_PARK_ID).jpg
```

Verify: `docker exec ork3-php8 ls /var/www/html/assets/heraldry/park-banner/`
Expected: see the 5-digit jpg.

- [ ] **Step 6: Verify the page renders the banner background**

Load `http://localhost:19080/orkui/Park/index/$TEST_PARK_ID`. The hero now has `class="pk-hero pk-hero-has-banner pk-hero-vignette"`. Until CSS lands in Task 11, the `pk-hero-has-banner` class has no styling — but inspect the rendered HTML in DevTools and confirm the `<div class="pk-hero-bg" style="background-image: url('...park-banner/...jpg?v=...'); background-position: 50% 50%;">` is correct.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: Park hero banner display"
```

---

## Task 5: Kingdom Model Hydration

**Files:**
- Modify: `system/lib/ork3/class.Kingdom.php` (the `GetKingdom` method around line 33)

- [ ] **Step 1: Find the HasHeraldry assignment**

Run: `grep -n "KingdomInfo.*HasHeraldry" system/lib/ork3/class.Kingdom.php | head -3`
Expected: line 33 — `$response['KingdomInfo']['HasHeraldry'] = $this->kingdom->has_heraldry;`.

- [ ] **Step 2: Add banner hydration block after that line**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Kingdom.php')
t = p.read_text()
old = "$response['KingdomInfo']['HasHeraldry'] = $this->kingdom->has_heraldry;"
new = old + """
\t\t\tglobal $DB;
\t\t\t$DB->Clear();
\t\t\t$_bn = $DB->DataSet("SELECT has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM ork_kingdom WHERE kingdom_id = " . (int)$this->kingdom->kingdom_id);
\t\t\tif ($_bn && $_bn->Next()) {
\t\t\t\t$response['KingdomInfo']['HasBanner']      = (int)$_bn->has_banner;
\t\t\t\t$response['KingdomInfo']['BannerShowLogo'] = (int)$_bn->banner_show_logo;
\t\t\t\t$response['KingdomInfo']['BannerVignette'] = (int)$_bn->banner_vignette;
\t\t\t\t$response['KingdomInfo']['BannerOffsetX']  = (int)$_bn->banner_offset_x;
\t\t\t\t$response['KingdomInfo']['BannerOffsetY']  = (int)$_bn->banner_offset_y;
\t\t\t}"""
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
PY
```

- [ ] **Step 3: Enable a test kingdom**

```bash
TEST_KINGDOM_ID=$(docker exec ork3-php8-db mariadb -u root -proot ork -Nse "SELECT kingdom_id FROM ork_kingdom ORDER BY kingdom_id LIMIT 1;")
docker exec ork3-php8-db mariadb -u root -proot ork -e "UPDATE ork_kingdom SET has_banner=1 WHERE kingdom_id=$TEST_KINGDOM_ID;"
echo "Test kingdom: $TEST_KINGDOM_ID"
```

- [ ] **Step 4: Verify hydration with temporary die**

Add `die(json_encode($response['KingdomInfo'], JSON_PRETTY_PRINT));` after the new block. Load `http://localhost:19080/orkui/Kingdom/index/$TEST_KINGDOM_ID`.
Expected: includes the five Banner* keys with HasBanner=1, offsets=50, toggles=1.

- [ ] **Step 5: Remove debug die and commit**

```bash
git add system/lib/ork3/class.Kingdom.php
git commit -m "Enhancement: Hydrate kingdom banner fields"
```

---

## Task 6: Kingdom Template — Top Resolution + Hero Assembly

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl`

- [ ] **Step 1: Find heraldry resolution and hero markup**

Run: `grep -n "hasHeraldry\|kn-hero\b" orkui/template/revised-frontend/Kingdomnew_index.tpl | head -10`

- [ ] **Step 2: Add banner-state resolution after the heraldry block**

Insert (after the line `$hasHeraldry = $kingdom_info['Info']['KingdomInfo']['HasHeraldry'] == 1;` and the heraldryUrl assignment):

```php
$_knInfo         = $kingdom_info['Info']['KingdomInfo'] ?? [];
$hasBanner       = !empty($_knInfo['HasBanner']);
$bannerShowLogo  = !isset($_knInfo['BannerShowLogo']) || (int)$_knInfo['BannerShowLogo'] !== 0;
$bannerVignette  = !isset($_knInfo['BannerVignette']) || (int)$_knInfo['BannerVignette'] !== 0;
$bannerOffsetX   = isset($_knInfo['BannerOffsetX']) ? max(0, min(100, (int)$_knInfo['BannerOffsetX'])) : 50;
$bannerOffsetY   = isset($_knInfo['BannerOffsetY']) ? max(0, min(100, (int)$_knInfo['BannerOffsetY'])) : 50;
$bannerUrl       = '';
if ($hasBanner) {
    $bannerFile = Common::resolve_image_ext(DIR_KINGDOM_BANNER, sprintf('%04d', (int)($_knInfo['KingdomId'] ?? 0)));
    $bannerFs   = DIR_KINGDOM_BANNER . $bannerFile;
    if (file_exists($bannerFs)) {
        $bannerUrl = HTTP_KINGDOM_BANNER . $bannerFile . '?v=' . filemtime($bannerFs);
    }
}
$knCanManageBanner = !empty($CanManageKingdom);
```

- [ ] **Step 3: Replace the .kn-hero element header (analogous to Task 4 Step 4)**

Same Python-edit pattern, but with `kn-` prefix instead of `pk-`. The `<div class="kn-hero">` and the `kn-hero-bg` style line.

- [ ] **Step 4: Plant a test banner image**

```bash
docker cp /tmp/test-banner.jpg ork3-php8:/var/www/html/assets/heraldry/kingdom-banner/$(printf "%04d" $TEST_KINGDOM_ID).jpg
```

- [ ] **Step 5: Load page and inspect rendered HTML**

Load `http://localhost:19080/orkui/Kingdom/index/$TEST_KINGDOM_ID` and confirm the hero div carries the right classes + bg style.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl
git commit -m "Enhancement: Kingdom hero banner display"
```

---

## Task 7: Player Model Hydration (on ork_mundane)

**Files:**
- Modify: `system/lib/ork3/class.Player.php` (around the `HasHeraldry` assignment at line 329)

- [ ] **Step 1: Find the HasHeraldry assignment**

Run: `grep -n "'HasHeraldry' => \$this->mundane->has_heraldry" system/lib/ork3/class.Player.php`
Expected: line 329.

- [ ] **Step 2: Read 15 lines of context around it**

The hydration block adds to the same array literal where `'HasHeraldry' =>` sits.

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Player.php')
t = p.read_text()
old = "'HasHeraldry' => $this->mundane->has_heraldry,"
new = old + """
\t\t\t\t\t'HasBanner'      => (int)$this->mundane->has_banner,
\t\t\t\t\t'BannerShowLogo' => (int)$this->mundane->banner_show_logo,
\t\t\t\t\t'BannerVignette' => (int)$this->mundane->banner_vignette,
\t\t\t\t\t'BannerOffsetX'  => (int)$this->mundane->banner_offset_x,
\t\t\t\t\t'BannerOffsetY'  => (int)$this->mundane->banner_offset_y,"""
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
PY
```

**Why use `$this->mundane->...` here instead of raw SQL like Park/Kingdom:** the `$this->mundane` Yapo object was just `find()`-ed two methods up and has all columns. If `find()` was called before the migration ran in some upgrade scenario, the columns might be absent — but Yapo refreshes on `find()` so this is safe in practice. Fall back to raw `$DB->DataSet` (same pattern as Park) if the production rollout shows `Undefined property` warnings.

- [ ] **Step 3: Enable a test mundane**

```bash
TEST_MUNDANE_ID=$(docker exec ork3-php8-db mariadb -u root -proot ork -Nse "SELECT mundane_id FROM ork_mundane WHERE persona IS NOT NULL AND persona <> '' ORDER BY mundane_id LIMIT 1;")
docker exec ork3-php8-db mariadb -u root -proot ork -e "UPDATE ork_mundane SET has_banner=1 WHERE mundane_id=$TEST_MUNDANE_ID;"
echo "Test mundane: $TEST_MUNDANE_ID"
```

- [ ] **Step 4: Verify hydration**

Find which method the line-329 array literal belongs to (likely `GetPlayer` or `GetPlayerDetails`). Trace what calls it. Then load the Player profile page for `$TEST_MUNDANE_ID` (URL: `http://localhost:19080/orkui/Player/index/$TEST_MUNDANE_ID`) and confirm via temporary `die(json_encode($Player, JSON_PRETTY_PRINT));` near the top of `Playernew_index.tpl` that `HasBanner: 1` and the four others appear.

- [ ] **Step 5: Remove debug die and commit**

```bash
git add system/lib/ork3/class.Player.php
git commit -m "Enhancement: Hydrate player banner fields from ork_mundane"
```

---

## Task 8: Player Template — Top Resolution + Hero Assembly

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl`

- [ ] **Step 1: Locate the heraldry block**

Run: `grep -n "heraldryUrl\|pn-hero\b" orkui/template/revised-frontend/Playernew_index.tpl | head -10`
Expected: around line 28 (`$heraldryUrl =`) and the `<div class="pn-hero">` markup.

- [ ] **Step 2: Add banner-state resolution + permission check**

Insert after the `$heraldryUrl` and `$imageUrl` lines:

```php
$hasBanner       = !empty($Player['HasBanner']);
$bannerShowLogo  = !isset($Player['BannerShowLogo']) || (int)$Player['BannerShowLogo'] !== 0;
$bannerVignette  = !isset($Player['BannerVignette']) || (int)$Player['BannerVignette'] !== 0;
$bannerOffsetX   = isset($Player['BannerOffsetX']) ? max(0, min(100, (int)$Player['BannerOffsetX'])) : 50;
$bannerOffsetY   = isset($Player['BannerOffsetY']) ? max(0, min(100, (int)$Player['BannerOffsetY'])) : 50;
$bannerUrl       = '';
if ($hasBanner) {
    $bannerFile = Common::resolve_image_ext(DIR_PLAYER_BANNER, sprintf('%06d', (int)$Player['MundaneId']));
    $bannerFs   = DIR_PLAYER_BANNER . $bannerFile;
    if (file_exists($bannerFs)) {
        $bannerUrl = HTTP_PLAYER_BANNER . $bannerFile . '?v=' . filemtime($bannerFs);
    }
}

// Banner edit scope: self OR park officer of player's park OR kingdom officer of player's kingdom OR admin.
$_pnUid = (int)($this->__session->user_id ?? 0);
$pnCanManageBanner =
       $_pnUid > 0
    && (
           $_pnUid === (int)$Player['MundaneId']
        || (!empty($Player['ParkId'])    && Ork3::$Lib->authorization->HasAuthority($_pnUid, AUTH_PARK,    (int)$Player['ParkId'],    AUTH_EDIT))
        || (!empty($Player['KingdomId']) && Ork3::$Lib->authorization->HasAuthority($_pnUid, AUTH_KINGDOM, (int)$Player['KingdomId'], AUTH_EDIT))
        || Ork3::$Lib->authorization->HasAuthority($_pnUid, AUTH_ADMIN, null, null)
    );
```

- [ ] **Step 3: Replace the .pn-hero element header**

Same Python-edit pattern as Park (Task 4 Step 4), with `pn-` prefix.

- [ ] **Step 4: Plant a test banner image**

```bash
docker cp /tmp/test-banner.jpg ork3-php8:/var/www/html/assets/heraldry/player-banner/$(printf "%06d" $TEST_MUNDANE_ID).jpg
```

- [ ] **Step 5: Load profile and inspect rendered HTML**

Load `http://localhost:19080/orkui/Player/index/$TEST_MUNDANE_ID`. Confirm the `<div class="pn-hero pn-hero-has-banner ...">` carries the right classes.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_index.tpl
git commit -m "Enhancement: Player hero banner display"
```

---

## Task 9: Unit Model Hydration

**Files:**
- Modify: `system/lib/ork3/class.Unit.php` (around line 170 where `HasHeraldry` is assigned)

- [ ] **Step 1: Find the assignment**

Run: `grep -n "'HasHeraldry' => \$this->unit->has_heraldry" system/lib/ork3/class.Unit.php`
Expected: line 170.

- [ ] **Step 2: Add banner fields**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Unit.php')
t = p.read_text()
old = "'HasHeraldry' => $this->unit->has_heraldry,"
new = old + """
\t\t\t\t\t'HasBanner'      => (int)$this->unit->has_banner,
\t\t\t\t\t'BannerShowLogo' => (int)$this->unit->banner_show_logo,
\t\t\t\t\t'BannerVignette' => (int)$this->unit->banner_vignette,
\t\t\t\t\t'BannerOffsetX'  => (int)$this->unit->banner_offset_x,
\t\t\t\t\t'BannerOffsetY'  => (int)$this->unit->banner_offset_y,"""
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
PY
```

- [ ] **Step 3: Enable a test unit**

```bash
TEST_UNIT_ID=$(docker exec ork3-php8-db mariadb -u root -proot ork -Nse "SELECT unit_id FROM ork_unit ORDER BY unit_id LIMIT 1;")
docker exec ork3-php8-db mariadb -u root -proot ork -e "UPDATE ork_unit SET has_banner=1 WHERE unit_id=$TEST_UNIT_ID;"
echo "Test unit: $TEST_UNIT_ID"
```

- [ ] **Step 4: Verify hydration**

Load `http://localhost:19080/orkui/Unit/index/$TEST_UNIT_ID` with `die(json_encode($Unit['Details'], JSON_PRETTY_PRINT));` planted near top of `Unit_index.tpl`. Confirm five Banner* keys present.

- [ ] **Step 5: Remove debug die and commit**

```bash
git add system/lib/ork3/class.Unit.php
git commit -m "Enhancement: Hydrate unit banner fields"
```

---

## Task 10: Unit Template — Top Resolution + Hero Assembly

**Files:**
- Modify: `orkui/template/default/Unit_index.tpl`

- [ ] **Step 1: Locate the hero block**

Run: `grep -n "_hero_src\|un-hero\b" orkui/template/default/Unit_index.tpl | head -10`
Expected: line 18 (`$_hero_src`), lines 328-329 (`<div class="un-hero">` and `<div class="un-hero-bg">`).

- [ ] **Step 2: Add banner-state resolution after the heraldry block**

Insert after the line `$_hero_src = ...`:

```php
$hasBanner       = !empty($_unit['HasBanner']);
$bannerShowLogo  = !isset($_unit['BannerShowLogo']) || (int)$_unit['BannerShowLogo'] !== 0;
$bannerVignette  = !isset($_unit['BannerVignette']) || (int)$_unit['BannerVignette'] !== 0;
$bannerOffsetX   = isset($_unit['BannerOffsetX']) ? max(0, min(100, (int)$_unit['BannerOffsetX'])) : 50;
$bannerOffsetY   = isset($_unit['BannerOffsetY']) ? max(0, min(100, (int)$_unit['BannerOffsetY'])) : 50;
$bannerUrl       = '';
if ($hasBanner) {
    $bannerFile = Common::resolve_image_ext(DIR_UNIT_BANNER, sprintf('%05d', $_unit_id));
    $bannerFs   = DIR_UNIT_BANNER . $bannerFile;
    if (file_exists($bannerFs)) {
        $bannerUrl = HTTP_UNIT_BANNER . $bannerFile . '?v=' . filemtime($bannerFs);
    }
}
$unCanManageBanner = !empty($_can_edit);
```

- [ ] **Step 3: Replace .un-hero element header (analogous to Task 4 Step 4) with `un-` prefix**

- [ ] **Step 4: Plant a test banner image**

```bash
docker cp /tmp/test-banner.jpg ork3-php8:/var/www/html/assets/heraldry/unit-banner/$(printf "%05d" $TEST_UNIT_ID).jpg
```

- [ ] **Step 5: Load page and inspect rendered HTML**

Load `http://localhost:19080/orkui/Unit/index/$TEST_UNIT_ID`. Confirm `<div class="un-hero un-hero-has-banner ...">` is rendered.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/default/Unit_index.tpl
git commit -m "Enhancement: Unit hero banner display"
```

---

## Task 11: Shared CSS — Four Prefixed Banner Blocks

**Files:**
- Modify: `orkui/template/revised-frontend/style/revised.css` (append before existing event-banner block at line 4014, OR after — placement is irrelevant since selectors are entity-prefixed)

This is the largest mechanical-copy step. Source is `revised.css:4014-4160` (the event banner block, ~150 lines including hero shell, no-banner blur backdrop, banner full-bleed state, vignette ::before + ::after, edit pill, modal-step CSS, position canvas, wireframe CSS).

- [ ] **Step 1: Read the source event-banner CSS block**

```bash
sed -n '4014,4160p' orkui/template/revised-frontend/style/revised.css > /tmp/banner-template.css
wc -l /tmp/banner-template.css
```

Expected: ~147 lines.

- [ ] **Step 2: Generate four prefixed copies**

```bash
for prefix in pk kn pn un; do
    sed "s/ev-/${prefix}-/g" /tmp/banner-template.css >> /tmp/banner-all.css
    echo "" >> /tmp/banner-all.css
done
wc -l /tmp/banner-all.css
```

Expected: ~592 lines (~148 × 4).

- [ ] **Step 3: Read the generated output and append to revised.css**

```bash
python3 <<'PY'
import pathlib
src   = pathlib.Path('/tmp/banner-all.css').read_text()
dest  = pathlib.Path('orkui/template/revised-frontend/style/revised.css')
text  = dest.read_text()
marker = '/* ── Ported entity banners (park/kingdom/player/unit) ────────────────── */\n'
if marker not in text:
    dest.write_text(text + '\n\n' + marker + src + '\n')
    print('appended')
else:
    print('already appended — skipping')
PY
```

- [ ] **Step 4: Spot-check the prefixed block for a missed `ev-`**

Run: `tail -600 orkui/template/revised-frontend/style/revised.css | grep -c '\bev-'`
Expected: `0` (zero remaining `ev-` selectors in the new section). If non-zero, the sed substitution missed something — investigate.

- [ ] **Step 5: Reload the test park page and confirm the banner now renders full-bleed with vignette**

Load `http://localhost:19080/orkui/Park/index/$TEST_PARK_ID`. The hero should now show the banner image full-bleed (no blur, no opacity dimming) with darkening on the left where the logo sits and a soft backdrop-blur masked to the left third. Repeat with Kingdom, Player, Unit test pages.

- [ ] **Step 6: Dark-mode walkthrough (per memory rule)**

Toggle dark mode via the existing theme toggle. Confirm:
- The banner image itself is unchanged (it's an image, not a token).
- The edit pill — when added later in Task 16-19 — will need to be re-checked at that point.
For now the only dark-mode surface is the hero card, which already had dark-mode rules. No new dark-mode CSS needed at this step.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/style/revised.css
git commit -m "Enhancement: Banner CSS for park/kingdom/player/unit heroes"
```

---

## Task 12: ParkAjax::banner endpoint

**Files:**
- Modify: `orkui/controller/controller.ParkAjax.php` (add new public method `banner`)

The body is a near-copy of `EventAjax::banner()` (lines 977-1095). Substitute:
- `$event_id` → `$park_id`
- `AUTH_EVENT` checks → `AUTH_PARK`
- `ork_event` table → `ork_park`
- `event_id` column → `park_id`
- `HTTP_EVENT_BANNER` → `HTTP_PARK_BANNER`
- `DIR_EVENT_BANNER` → `DIR_PARK_BANNER`
- 5-digit ID padding (already `%05d` in the event source — leave as-is for park)
- Auth check: `Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $park_id, AUTH_EDIT)`

- [ ] **Step 1: Read the full source of EventAjax::banner**

```bash
sed -n '977,1095p' orkui/controller/controller.EventAjax.php > /tmp/banner-method-source.php
wc -l /tmp/banner-method-source.php
```

- [ ] **Step 2: Generate Park version**

Read the source, find the existing controller's last `}` (before the class closes), and add the new method before it. Use Python to do the substitutions:

```bash
python3 <<'PY'
import pathlib
src = pathlib.Path('/tmp/banner-method-source.php').read_text()
park = (src
    .replace('AUTH_EVENT', 'AUTH_PARK')
    .replace('$event_id', '$park_id')
    .replace('$event_id_int', '$park_id')
    .replace('ork_event', 'ork_park')
    .replace('event_id =', 'park_id =')
    .replace('event_id IN', 'park_id IN')
    .replace('HTTP_EVENT_BANNER', 'HTTP_PARK_BANNER')
    .replace('DIR_EVENT_BANNER', 'DIR_PARK_BANNER')
)
# Make sure the auth check is plain park-officer (no event-staff fallback).
# Manually inspect the result for any staff/host fallback the event version had.
pathlib.Path('/tmp/banner-park.php').write_text(park)
print('park version length:', len(park))
PY
```

- [ ] **Step 3: Manually inspect /tmp/banner-park.php**

Read it end-to-end. The Event version had a per-staff override fallback — strip it; Park auth is solely `HasAuthority(uid, AUTH_PARK, park_id, AUTH_EDIT)`.

Specifically look for any `staff_*` or `event_staff_*` references and remove those branches. The simplest Park auth block:

```php
$uid     = (int)$this->session->user_id;
$park_id = (int)$id;
$canEdit = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $park_id, AUTH_EDIT);
if (!$canEdit) {
    echo json_encode(['status' => 1, 'error' => 'Not authorized to edit this park.']);
    exit;
}
```

- [ ] **Step 4: Insert the prepared method into controller.ParkAjax.php**

Find the closing `}` of the controller class. Insert the method body right above it. Use Edit for single-line splice or Python for multi-line block insertion:

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.ParkAjax.php')
t = p.read_text()
method = pathlib.Path('/tmp/banner-park.php').read_text()
# Find last '}' before EOF
idx = t.rfind('}')
new = t[:idx] + '\n\t' + method.replace('\n', '\n\t').rstrip() + '\n\n' + t[idx:]
p.write_text(new)
print('inserted at offset', idx)
PY
```

- [ ] **Step 5: Test the remove endpoint with curl**

```bash
COOKIE_JAR=/tmp/ork-cookies.txt
# Log in first (only needed once — refresh if expired):
curl -s -c $COOKIE_JAR -b $COOKIE_JAR -X POST \
    -d "Username=admin@example.com&Password=...&Action=Sign+In" \
    http://localhost:19080/orkui/Login/login
# Then:
curl -s -b $COOKIE_JAR -X POST "http://localhost:19080/orkui/ParkAjax/banner/$TEST_PARK_ID/remove"
```
Expected: `{"status":0}` and `ork_park.has_banner` returns to 0.

- [ ] **Step 6: Test config update with curl**

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "UPDATE ork_park SET has_banner=1 WHERE park_id=$TEST_PARK_ID;"
curl -s -b $COOKIE_JAR -X POST \
    -d "ShowLogo=0&Vignette=1&OffsetX=30&OffsetY=70" \
    "http://localhost:19080/orkui/ParkAjax/banner/$TEST_PARK_ID/config"
```
Expected: `{"status":0}`. Verify with: `docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT has_banner, banner_show_logo, banner_offset_x, banner_offset_y FROM ork_park WHERE park_id=$TEST_PARK_ID;"`
Expected: 1, 0, 30, 70.

- [ ] **Step 7: Test image upload with curl**

```bash
curl -s -b $COOKIE_JAR -X POST \
    -F "Banner=@/tmp/test-banner.jpg" \
    -F "ShowLogo=1" \
    -F "Vignette=1" \
    -F "OffsetX=50" \
    -F "OffsetY=50" \
    "http://localhost:19080/orkui/ParkAjax/banner/$TEST_PARK_ID/update"
```
Expected: `{"status":0}`. File should exist at `assets/heraldry/park-banner/$(printf %05d $TEST_PARK_ID).jpg` with fresh mtime.

- [ ] **Step 8: Commit**

```bash
git add orkui/controller/controller.ParkAjax.php
git commit -m "Enhancement: ParkAjax banner endpoint"
```

---

## Task 13: KingdomAjax::banner endpoint

**Files:**
- Modify: `orkui/controller/controller.KingdomAjax.php`

- [ ] **Step 1: Generate Kingdom version from event source**

```bash
python3 <<'PY'
import pathlib
src = pathlib.Path('/tmp/banner-method-source.php').read_text()
kingdom = (src
    .replace('AUTH_EVENT', 'AUTH_KINGDOM')
    .replace('$event_id', '$kingdom_id')
    .replace('$event_id_int', '$kingdom_id')
    .replace('ork_event', 'ork_kingdom')
    .replace('event_id =', 'kingdom_id =')
    .replace('event_id IN', 'kingdom_id IN')
    .replace('HTTP_EVENT_BANNER', 'HTTP_KINGDOM_BANNER')
    .replace('DIR_EVENT_BANNER', 'DIR_KINGDOM_BANNER')
    .replace("sprintf('%05d'", "sprintf('%04d'")   # kingdom is 4-digit
)
pathlib.Path('/tmp/banner-kingdom.php').write_text(kingdom)
print('done')
PY
```

- [ ] **Step 2: Manually scrub staff/host fallback (same as Park Step 3)**

Auth becomes plain `HasAuthority(uid, AUTH_KINGDOM, kingdom_id, AUTH_EDIT)`.

- [ ] **Step 3: Insert method into controller.KingdomAjax.php**

Same Python `rfind('}')` pattern as Park Step 4.

- [ ] **Step 4: curl-test all three endpoints against `$TEST_KINGDOM_ID`**

`/remove`, `/config`, `/update` — same shape as Task 12 Steps 5-7. Verify DB state and file presence.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.KingdomAjax.php
git commit -m "Enhancement: KingdomAjax banner endpoint"
```

---

## Task 14: PlayerAjax::banner endpoint

**Files:**
- Modify: `orkui/controller/controller.PlayerAjax.php`

- [ ] **Step 1: Generate Player version from event source**

```bash
python3 <<'PY'
import pathlib
src = pathlib.Path('/tmp/banner-method-source.php').read_text()
player = (src
    .replace('$event_id', '$mundane_id_target')
    .replace('$event_id_int', '$mundane_id_target')
    .replace('ork_event', 'ork_mundane')
    .replace('event_id =', 'mundane_id =')
    .replace('event_id IN', 'mundane_id IN')
    .replace('HTTP_EVENT_BANNER', 'HTTP_PLAYER_BANNER')
    .replace('DIR_EVENT_BANNER',  'DIR_PLAYER_BANNER')
    .replace("sprintf('%05d'",    "sprintf('%06d'")
)
pathlib.Path('/tmp/banner-player.php').write_text(player)
print('done')
PY
```

- [ ] **Step 2: Replace the auth check block manually**

Open `/tmp/banner-player.php`. Replace the event auth check with:

```php
$uid              = (int)$this->session->user_id;
$mundane_id_target = (int)$id;

// Load player's park/kingdom for officer auth lookup.
$DB->Clear();
$_pInfo = $DB->DataSet("SELECT park_id, kingdom_id FROM ork_mundane WHERE mundane_id = " . $mundane_id_target);
if (!$_pInfo || !$_pInfo->Next()) {
    echo json_encode(['status' => 1, 'error' => 'Player not found.']);
    exit;
}
$_parkId    = (int)$_pInfo->park_id;
$_kingdomId = (int)$_pInfo->kingdom_id;

$canEdit = $uid > 0 && (
       $uid === $mundane_id_target
    || ($_parkId    && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK,    $_parkId,    AUTH_EDIT))
    || ($_kingdomId && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $_kingdomId, AUTH_EDIT))
    || Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, null, null)
);
if (!$canEdit) {
    echo json_encode(['status' => 1, 'error' => 'Not authorized to edit this player.']);
    exit;
}
```

- [ ] **Step 3: Insert into controller.PlayerAjax.php**

Same Python `rfind('}')` pattern.

- [ ] **Step 4: curl-test all three endpoints against `$TEST_MUNDANE_ID`**

```bash
# Self-edit case: log in as the test mundane (or as admin) then exercise /remove, /config, /update.
```

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.PlayerAjax.php
git commit -m "Enhancement: PlayerAjax banner endpoint (self/park/kingdom/admin auth)"
```

---

## Task 15: Create controller.UnitAjax.php + banner endpoint

**Files:**
- Create: `orkui/controller/controller.UnitAjax.php`

- [ ] **Step 1: Stub the controller class**

```php
<?php

class Controller_UnitAjax extends Controller {

    public function __construct($call = null, $id = null) {
        parent::__construct();
    }

    // Banner endpoint inserted below — see Step 2.

}
```

- [ ] **Step 2: Generate Unit version of banner method from event source**

```bash
python3 <<'PY'
import pathlib
src = pathlib.Path('/tmp/banner-method-source.php').read_text()
unit = (src
    .replace('AUTH_EVENT', 'AUTH_UNIT')
    .replace('$event_id', '$unit_id')
    .replace('$event_id_int', '$unit_id')
    .replace('ork_event', 'ork_unit')
    .replace('event_id =', 'unit_id =')
    .replace('event_id IN', 'unit_id IN')
    .replace('HTTP_EVENT_BANNER', 'HTTP_UNIT_BANNER')
    .replace('DIR_EVENT_BANNER',  'DIR_UNIT_BANNER')
    # %05d is right for Unit, no change
)
pathlib.Path('/tmp/banner-unit.php').write_text(unit)
print('done')
PY
```

- [ ] **Step 3: Replace auth check with plain AUTH_UNIT/AUTH_EDIT**

```php
$uid     = (int)$this->session->user_id;
$unit_id = (int)$id;
$canEdit = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_UNIT, $unit_id, AUTH_EDIT);
if (!$canEdit) {
    echo json_encode(['status' => 1, 'error' => 'Not authorized to edit this unit.']);
    exit;
}
```

- [ ] **Step 4: Combine stub + method body into the new file**

Open `/tmp/banner-unit.php` and paste the method (renamed `public function banner($p = null)`) into the stub class body. Save to `orkui/controller/controller.UnitAjax.php`.

- [ ] **Step 5: Test all three endpoints against `$TEST_UNIT_ID`**

Same curl pattern as Task 12.

- [ ] **Step 6: Commit**

```bash
git add orkui/controller/controller.UnitAjax.php
git commit -m "Enhancement: UnitAjax controller with banner endpoint"
```

---

## Task 16: Park Template — Edit Pill, JS Config, Modal Markup

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl`

- [ ] **Step 1: Add the edit pill inside the .pk-hero element**

Locate the `<div class="pk-hero pk-hero-...">` opening (from Task 4) and the matching `<div class="pk-hero-bg">` immediately after. Insert the edit pill *after* the bg div:

```php
<?php if ($pkCanManageBanner): ?>
<button type="button" class="pk-banner-edit-btn"
        onclick="pkOpenBannerModal()"
        aria-label="<?= $bannerUrl ? 'Update Banner Image' : 'Add Banner Image' ?>">
    <i class="fas fa-image"></i>
    <span class="pk-banner-edit-label"> <?= $bannerUrl ? 'Update Banner Image' : 'Add Banner Image' ?></span>
    <i class="fas fa-pencil-alt pk-banner-edit-pencil" aria-hidden="true"></i>
</button>
<?php endif; ?>
```

- [ ] **Step 2: Add PkBannerConfig JS block**

Locate the existing `var ParknewConfig` / `pkConfig` script (around line 1230s — find with `grep -n "ParknewConfig\|var pkConfig\|canManage" orkui/template/revised-frontend/Parknew_index.tpl`). Add a new sibling `<script>` block immediately above or below:

```html
<script>
var PkBannerConfig = {
    uir:            '<?= UIR ?>',
    canManage:      <?= $pkCanManageBanner ? 'true' : 'false' ?>,
    entityId:       <?= (int)$parkInfo['ParkId'] ?>,
    hasBanner:      <?= $hasBanner ? 'true' : 'false' ?>,
    bannerShowLogo: <?= $bannerShowLogo ? 'true' : 'false' ?>,
    bannerVignette: <?= $bannerVignette ? 'true' : 'false' ?>,
    bannerOffsetX:  <?= (int)$bannerOffsetX ?>,
    bannerOffsetY:  <?= (int)$bannerOffsetY ?>,
    bannerUrl:      <?= json_encode($bannerUrl) ?>,
};
</script>
```

- [ ] **Step 3: Append modal markup at end of body (before `</body>` / template close)**

Source: `Eventnew_index.tpl:2240-2400` (entire `<div class="ev-img-overlay ev-banner-modal">…</div>` block).

```bash
sed -n '2240,2400p' orkui/template/revised-frontend/Eventnew_index.tpl > /tmp/banner-modal.html
# Substitute ev- → pk-
sed -i.bak 's/ev-/pk-/g' /tmp/banner-modal.html
# Substitute `evOpenBannerModal` → `pkOpenBannerModal` (already covered by ev- → pk- since the function is also entity-prefixed only by 'ev'… check)
grep -E 'evOpen|EvConfig' /tmp/banner-modal.html
```

If grep finds any `evOpen*` or `EvConfig` references, sed those too:
```bash
sed -i.bak 's/evOpenBannerModal/pkOpenBannerModal/g; s/EvConfig/PkBannerConfig/g' /tmp/banner-modal.html
```

Append `/tmp/banner-modal.html` to the end of `Parknew_index.tpl` before its closing tags. Find the appropriate insertion point with: `grep -n "^</body\|^</html\|^<?php\s*$" orkui/template/revised-frontend/Parknew_index.tpl | tail -5`.

- [ ] **Step 4: Reload the page (logged in as park officer) and click the edit pill**

Load `http://localhost:19080/orkui/Park/index/$TEST_PARK_ID`. Hover the hero — edit pill fades in at top-center. Click it — modal opens (will be non-functional until Task 20's JS lands; this verifies the markup renders).

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: Park banner edit pill + modal markup"
```

---

## Task 17: Kingdom Template — Edit Pill, JS Config, Modal Markup

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl`

Same three steps as Task 16 with `kn-` prefix and `KnBannerConfig`. Auth flag is `$knCanManageBanner`; entityId is the kingdom_id.

- [ ] **Step 1: Insert edit pill after `<div class="kn-hero-bg">`** (with `kn-` prefix substitutions of the markup in Task 16 Step 1)
- [ ] **Step 2: Insert `KnBannerConfig` script** (mirrors Task 16 Step 2)
- [ ] **Step 3: Append `kn-banner-modal` markup at end of file** (use sed pipeline with `ev- → kn-`, `evOpenBannerModal → knOpenBannerModal`, `EvConfig → KnBannerConfig`)
- [ ] **Step 4: Reload `Kingdom/index/$TEST_KINGDOM_ID`** and confirm pill + modal-open work.
- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl
git commit -m "Enhancement: Kingdom banner edit pill + modal markup"
```

---

## Task 18: Player Template — Edit Pill, JS Config, Modal Markup

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl`

Same three steps as Task 16 with `pn-` prefix and `PnBannerConfig`. Auth flag is `$pnCanManageBanner`; entityId is `(int)$Player['MundaneId']`.

- [ ] **Step 1: Insert edit pill after `<div class="pn-hero-bg">`**
- [ ] **Step 2: Insert `PnBannerConfig` script**
- [ ] **Step 3: Append `pn-banner-modal` markup at end of file**
- [ ] **Step 4: Reload `Player/index/$TEST_MUNDANE_ID`** and confirm pill + modal-open work, both as self and as a non-owner park officer.
- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_index.tpl
git commit -m "Enhancement: Player banner edit pill + modal markup"
```

---

## Task 19: Unit Template — Edit Pill, JS Config, Modal Markup

**Files:**
- Modify: `orkui/template/default/Unit_index.tpl`

Same three steps as Task 16 with `un-` prefix and `UnBannerConfig`. Auth flag is `$unCanManageBanner`; entityId is `$_unit_id`.

- [ ] **Step 1: Insert edit pill after `<div class="un-hero-bg">`** (line 329)
- [ ] **Step 2: Insert `UnBannerConfig` script** (place near the other inline `<script>` blocks)
- [ ] **Step 3: Append `un-banner-modal` markup at end of file**
- [ ] **Step 4: Reload `Unit/index/$TEST_UNIT_ID`** and confirm pill + modal-open.
- [ ] **Step 5: Commit**

```bash
git add orkui/template/default/Unit_index.tpl
git commit -m "Enhancement: Unit banner edit pill + modal markup"
```

---

## Task 20: Shared JS — Four Prefixed Banner IIFEs

**Files:**
- Modify: `orkui/template/revised-frontend/script/revised.js` (append after line 15424 — end of event IIFE — OR at end of file)

- [ ] **Step 1: Extract the event banner IIFE source**

```bash
sed -n '15054,15424p' orkui/template/revised-frontend/script/revised.js > /tmp/banner-iife.js
wc -l /tmp/banner-iife.js
```

Expected: ~371 lines.

- [ ] **Step 2: Generate four prefixed copies**

The IIFE references: `EventConfig` (rename → `*BannerConfig`), `evOpenBannerModal` (rename → `*OpenBannerModal`), URLs `EventAjax/banner/...` (rename → `*Ajax/banner/...`), and CSS-class selectors like `ev-banner-...` (rename via prefix swap).

```bash
python3 <<'PY'
import pathlib
src = pathlib.Path('/tmp/banner-iife.js').read_text()

mappings = [
    ('pk', 'Pk', 'Park'),
    ('kn', 'Kn', 'Kingdom'),
    ('pn', 'Pn', 'Player'),
    ('un', 'Un', 'Unit'),
]
out = []
for short, capshort, longname in mappings:
    code = (src
        .replace('EventConfig',         f'{capshort}BannerConfig')
        .replace('evOpenBannerModal',   f'{short}OpenBannerModal')
        .replace("'EventAjax/banner/",  f"'{longname}Ajax/banner/")
        .replace('ev-banner-',          f'{short}-banner-')
        .replace('ev-img-',             f'{short}-img-')
    )
    out.append(f'/* ── {longname} banner ─────────────────────────────────── */\n' + code)

pathlib.Path('/tmp/banner-iifes-all.js').write_text('\n\n'.join(out) + '\n')
print('lines:', len(open('/tmp/banner-iifes-all.js').read().splitlines()))
PY
```

Expected output: ~1500 lines.

- [ ] **Step 3: Spot-check for missed substitutions**

```bash
grep -cE '\bEvConfig|\bEventConfig|evOpenBannerModal|EventAjax/banner|\bev-banner-|\bev-img-' /tmp/banner-iifes-all.js
```
Expected: `0`. If non-zero, inspect and add the missing rename to the Python.

- [ ] **Step 4: Append to revised.js**

```bash
python3 <<'PY'
import pathlib
src   = pathlib.Path('/tmp/banner-iifes-all.js').read_text()
dest  = pathlib.Path('orkui/template/revised-frontend/script/revised.js')
text  = dest.read_text()
marker = '// ── Ported entity banner IIFEs (park/kingdom/player/unit) ──────────────\n'
if marker not in text:
    dest.write_text(text + '\n\n' + marker + src + '\n')
    print('appended')
else:
    print('already appended — skipping')
PY
```

- [ ] **Step 5: Hard refresh each test page and exercise the full happy path on Park**

Steps:
1. Load `Park/index/$TEST_PARK_ID` logged in as a park officer.
2. Click edit pill → modal opens at step-select.
3. Click "Choose Image" → pick a JPEG.
4. Step-position appears → drag the image left/right.
5. Click "Use This View ✓" → step-uploading → step-success → page reloads with new banner at new framing.
6. Re-open modal → click "Adjust Image Framing" → drag again → save → reload.
7. Re-open modal → toggle "Apply Vignette" off → click "Save settings only" → reload, vignette gone.
8. Re-open modal → click "Remove Banner" → confirm → reload, banner gone, hero back to heraldry-backdrop mode.

If any step fails, debug via browser console (`PkBannerConfig` should be in `window`, the IIFE should run, network tab shows the POST). Per memory rule: console.log / die-json, never error_log.

- [ ] **Step 6: Repeat the full happy path on Kingdom, Player, Unit**

Same eight-substep walkthrough, three times.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/script/revised.js
git commit -m "Enhancement: Banner IIFEs for park/kingdom/player/unit"
```

---

## Task 21: Dark Mode Walkthrough

**Files:**
- Possibly modify: `orkui/template/revised-frontend/style/revised.css` (only if issues found)

- [ ] **Step 1: Toggle dark mode via the existing theme toggle**

For each of the four test pages, walk every new surface:
1. Hero with banner — image unchanged (no token leakage).
2. Edit pill on hover — text white on translucent dark background; should be legible.
3. Modal — body / header / wireframes / toggle row / file picker / action row.
4. Step-position — canvas + overlay legibility.

Memory checklist applies (per `feedback_dark_mode_checklist.md`):
- [ ] Modal headers — does the global h1-h6 pill leak through? If yes, add `background:transparent; border:none; padding:0; border-radius:0;` reset on the modal heading.
- [ ] Ghost/cancel buttons — text muted to unreadable? Bump contrast.
- [ ] Inline `style="color:#xxx"` — anywhere a fixed light-mode color got hardcoded.
- [ ] Form labels and placeholders — still readable on dark backgrounds.
- [ ] Segmented toggles — both states distinguishable.
- [ ] Info boxes — adequate contrast.

- [ ] **Step 2: Fix any issues found**

For each issue, add a `html[data-theme="dark"] .X-banner-... { ... }` override in `revised.css`, near the entity's banner CSS block.

- [ ] **Step 3: Verify in light mode that the dark-mode overrides didn't regress anything**

Toggle back to light and re-walk.

- [ ] **Step 4: Commit (only if changes were needed)**

```bash
git add orkui/template/revised-frontend/style/revised.css
git commit -m "Enhancement: Dark-mode tweaks for banner modal"
```

---

## Task 22: Mobile Breakpoint Check

**Files:**
- Possibly modify: `orkui/template/revised-frontend/style/revised.css`

- [ ] **Step 1: Resize to ~480px wide (Chrome DevTools device mode → iPhone SE)**

For each of the four pages:
1. Edit pill collapses to icon-only square (existing `@media (max-width: 540px)` rule should already handle this — it was copied verbatim with the rest of the CSS in Task 11).
2. Banner crops to middle third — verify your test image's subject survives.
3. Modal renders within viewport without horizontal scroll.
4. Wireframes legible at narrow width.
5. Position-step canvas usable with touch drag.

- [ ] **Step 2: Fix any layout breaks**

If wireframes overflow, consider stacking vertically at the narrowest breakpoint:
```css
@media (max-width: 480px) {
    .X-banner-wireframes { flex-direction: column; }
}
```
…added once per prefix.

- [ ] **Step 3: Touch-drag verification**

In DevTools device mode (touch-enabled), open position step and drag. Should pan smoothly without scrolling the page (the `touch-action: none` on the canvas wrap + `{passive: false}` listeners take care of this — verify both survived the copy).

- [ ] **Step 4: Commit (only if changes were needed)**

```bash
git add orkui/template/revised-frontend/style/revised.css
git commit -m "Enhancement: Mobile breakpoint polish for banner modal"
```

---

## Task 23: End-to-End QA Per Acceptance Criteria

For each of `{Park, Kingdom, Player, Unit}` × `{light, dark}` mode × `{desktop, mobile}` viewport, verify spec acceptance items 1-9 from `docs/superpowers/specs/2026-05-17-hero-banner-port-design.md`. Spec acceptance criteria summarized:

1. Edit pill visibility — only with edit scope.
2. Modal opens correctly.
3. Fresh upload happy path.
4. Adjust-framing round-trip (no re-upload).
5. Save-settings-only.
6. Remove-banner resets to defaults.
7. Dark mode renders correctly.
8. Mobile renders correctly.
9. Vignette legibility holds.

- [ ] **Step 1: Build a spreadsheet/checklist of 4 × 9 = 36 cells**

Track which entities pass which acceptance criteria. Note any regressions and create follow-up tasks.

- [ ] **Step 2: Verify no regressions on `Event/index/{any}` profile**

The event banner code is untouched but shares `revised.css` and `revised.js`. Confirm the existing event banner still works.

- [ ] **Step 3: Verify no permission leak — log in as a regular member (no officer scope) and confirm edit pills do NOT appear on any entity profile**

- [ ] **Step 4: Final commit + cleanup**

If any small fixes were applied: commit. Otherwise: nothing to commit, proceed to PR.

```bash
# Make sure no debug die()s, no console.log leftovers, no temporary test files committed.
git status
git log --oneline master..HEAD   # confirm the commit log is clean
```

- [ ] **Step 5: Open the PR**

```bash
git push -u origin feature/hero-banner-port
gh pr create --title "Enhancement: Hero Banner — Park/Kingdom/Player/Unit" --body "$(cat <<'EOF'
## Summary
- Port event hero-banner feature to Park, Kingdom, Player, and Unit profiles
- Player banner lives on `ork_mundane` — one banner per real person, mirroring how heraldry already works
- Mechanically duplicated CSS/JS with `pk-`/`kn-`/`pn-`/`un-` prefixes per project convention
- New `controller.UnitAjax.php`; Park/Kingdom/Player extend existing AJAX controllers

## Test plan
- [ ] Upload + adjust + remove on each of Park, Kingdom, Player, Unit
- [ ] Dark mode + mobile breakpoint on each
- [ ] Permission check — non-officer sees no edit pill
- [ ] No regression on existing Event banner

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Plan Self-Review Notes

**Spec coverage check:** every section of `docs/superpowers/specs/2026-05-17-hero-banner-port-design.md` has at least one task:
- Schema → Task 1.
- Storage constants + dirs → Task 2.
- Model hydration → Tasks 3, 5, 7, 9.
- Backend AJAX → Tasks 12, 13, 14, 15.
- Template wiring (top resolution + hero) → Tasks 4, 6, 8, 10.
- Template wiring (config + modal) → Tasks 16, 17, 18, 19.
- Shared CSS → Task 11.
- Shared JS → Task 20.
- Dark mode + mobile → Tasks 21, 22.
- Acceptance criteria → Task 23.

**Type consistency check:** all JS config blocks named `*BannerConfig` (not `*Config`). All entity-prefixed `*OpenBannerModal` functions match the entity prefix consistently. CSS class prefixes match: pk-/kn-/pn-/un-.

**Known weak points (deliberate, called out):**
- Task 7 uses `$this->mundane->has_banner` (Yapo property) rather than raw `$DB->DataSet` like Tasks 3 and 5. Trade-off documented in the task body — fall back if production rollout shows undefined-property warnings.
- Task 23 manual QA is broad (36 cells). For an agentic worker this is the part most likely to be done sloppily. Recommend a real human walks through it before merging.

---

## Risk / Rollback

- All four schema columns use `ADD COLUMN IF NOT EXISTS` and have safe defaults. If the migration runs on a system that already has these columns (e.g. partial re-apply), it's idempotent.
- The pre-resize-aware banner files (none exist for these entities since this is fresh) won't collide with anything.
- Rollback: revert the branch; the only DB state left behind is `has_banner=1` on whichever rows hosts have populated, which the application will simply ignore once the model code is gone.
- Worst case during rollout: a hero stops rendering. Set `has_banner=0` on the affected row to restore.
