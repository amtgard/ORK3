# Hero Banner Image — Implementation Handoff

This document describes the **event hero banner image** feature as it ships in `feature/event-planning-expansion` (file: `orkui/template/revised-frontend/Eventnew_index.tpl`), so the same pattern can be ported to another profile hero (player / park / kingdom / unit / etc.) on a different branch.

Read it top-to-bottom — the design choices build on each other. Sections marked **DO NOT SKIP** capture decisions that look optional but were made for non-obvious reasons.

---

## 1. What ships

- A full-bleed image (recommended **1800 × 240**, 7.5:1) renders inside the hero as a positioned background, behind the existing hero text/logo.
- The host (anyone with edit scope) can upload, re-frame, or remove the banner from a single modal launched by a hover-revealed pill at the top-center of the hero.
- Two display toggles persist per record: **Show Logo** and **Apply Vignette**.
- Framing is stored as **two percentages (X/Y, 0–100)**, not as a cropped image — re-framing later doesn't require a re-upload.
- A drag-to-position step (HTML canvas with an SVG overlay of safe zones) handles re-framing visually.

---

## 2. Schema (DO NOT SKIP)

Five columns on the host record. In ORK3 these went on `ork_event`; for a player/park/kingdom port, add them to that table instead.

```sql
ALTER TABLE ork_<entity>
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)         NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)         NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)         NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED   NOT NULL DEFAULT 50,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED   NOT NULL DEFAULT 50;
```

Why these exact defaults:
- `has_banner = 0`: hero falls back to whatever it rendered before (heraldry, blank, etc.).
- `banner_show_logo = 1`, `banner_vignette = 1`: vignette + logo are the "good" defaults — turning them off is the unusual case.
- `banner_offset_x = 50, banner_offset_y = 50`: 50/50 = centered (matches CSS `background-position: 50% 50%`). Existing pre-resize-aware banners that were stored cropped will display unchanged at this default.

**One project-specific quirk that paid off:** read these columns via raw `$DB->DataSet()` in your model getter (e.g. `class.Event.php::GetEvent`) instead of the Yapo ORM, because columns added late can be missing from a cached schema. See `system/lib/ork3/class.Event.php:109-127`.

---

## 3. Storage layout

Two constants in `config.dev.php` and `config.dist.php`, defined next to the existing heraldry constants:

```php
define('HTTP_<ENTITY>_BANNER', HTTP_HERALDRY . '<entity>-banner/');   // public URL
define('DIR_<ENTITY>_BANNER',  DIR_HERALDRY  . '<entity>-banner/');   // filesystem dir
```

File naming: zero-padded ID, `.jpg` or `.png` (no other formats supported):

```
DIR_<ENTITY>_BANNER/01234.jpg
```

Resolution uses the existing `Common::resolve_image_ext($dir, $name)` helper (returns `name.png` if it exists, otherwise `name.jpg`). The upload controller deletes both extensions before writing the new one — never leave the old file behind when a host switches formats.

Cache-busting: append `?v=` + `filemtime($fs)` to the URL. Without it, browsers happily serve the old image after an upload.

---

## 4. Backend AJAX controller

Single endpoint `EventAjax::banner()` dispatches three subactions via the URL tail: `/banner/{id}/update`, `/banner/{id}/config`, `/banner/{id}/remove`. Full reference: `orkui/controller/controller.EventAjax.php:977-1095`.

```
POST  EventAjax/banner/{id}/update    multipart  → save new image + config
POST  EventAjax/banner/{id}/config    form-urlenc → save config only (no image change)
POST  EventAjax/banner/{id}/remove    (no body)  → delete image + reset config
```

Request shape for `update`:
- `Banner`     — file (image/jpeg or image/png; reject anything else)
- `ShowLogo`   — '1' or '0'
- `Vignette`   — '1' or '0'
- `OffsetX`    — 0..100
- `OffsetY`    — 0..100

Response: `{ status: 0 }` on success, `{ status: 1, error: "…" }` otherwise.

Three things in the controller that are worth copying:

1. **Auth check first, do nothing else before:** check that the caller has edit scope on this entity. We additionally fall back to a per-staff override for events; adapt to the entity's permission model.
2. **Verify-after-write rollback** (saves you from `sql_mode=STRICT` silent failures):
   ```php
   $DB->Execute('UPDATE ... SET has_banner = 1, ... WHERE id = ' . $id);
   $DB->Clear();
   $verify = $DB->DataSet('SELECT has_banner FROM ... WHERE id = ' . $id);
   if (!$verify || !$verify->Next() || (int)$verify->has_banner !== 1) {
       @unlink($base . '.' . $ext);   // roll back the file
       echo json_encode(['status' => 1, 'error' => '…']); exit;
   }
   ```
3. **`remove` resets framing too** — when a host removes a banner, also reset `banner_offset_x/y` to 50 so a later upload starts from a clean slate instead of inheriting the removed banner's framing. The same goes for `show_logo` and `vignette`.

The `config` action refuses silently with a meaningful error if `has_banner = 0` ("upload a banner first") — don't let "save settings only" succeed against a missing image.

---

## 5. Template wiring

### 5a. Top-of-file PHP resolves banner state

In the template, right after heraldry resolution, derive everything the rest of the file needs:

```php
$hasBanner       = !empty($info['HasBanner']);
$bannerShowLogo  = !isset($info['BannerShowLogo']) || (int)$info['BannerShowLogo'] !== 0;
$bannerVignette  = !isset($info['BannerVignette']) || (int)$info['BannerVignette'] !== 0;
$bannerOffsetX   = isset($info['BannerOffsetX']) ? max(0, min(100, (int)$info['BannerOffsetX'])) : 50;
$bannerOffsetY   = isset($info['BannerOffsetY']) ? max(0, min(100, (int)$info['BannerOffsetY'])) : 50;
$bannerUrl       = '';
if ($hasBanner) {
    $bannerFile = Common::resolve_image_ext(DIR_<ENTITY>_BANNER, sprintf('%05d', $entityId));
    $bannerFs   = DIR_<ENTITY>_BANNER . $bannerFile;
    if (file_exists($bannerFs)) {
        $bannerUrl = HTTP_<ENTITY>_BANNER . $bannerFile . '?v=' . filemtime($bannerFs);
    }
}
```

The default-truthy guards on `BannerShowLogo`/`BannerVignette` mean a missing field renders the "good" default, never accidentally disables both toggles.

### 5b. Hero element assembly

Build the hero's class list from the banner state, then render a positioned background div + the existing hero content:

```php
<?php
    $_heroBgUrl    = $bannerUrl ?: $heraldryUrl;
    $_heroClasses  = '<entity>-hero';
    if ($bannerUrl)                       $_heroClasses .= ' <entity>-hero-has-banner';
    if ($bannerUrl && $bannerVignette)    $_heroClasses .= ' <entity>-hero-vignette';
    if ($canManage)                       $_heroClasses .= ' <entity>-hero-editable';
    $_showLogo = !$bannerUrl || $bannerShowLogo;

    $_bgStyle = '';
    if ($_heroBgUrl) {
        $_bgStyle = 'background-image: url(\'' . htmlspecialchars($_heroBgUrl) . '\');';
        if ($bannerUrl) {
            $_bgStyle .= ' background-position: ' . $bannerOffsetX . '% ' . $bannerOffsetY . '%;';
        }
    }
?>
<div class="<?= $_heroClasses ?>" id="<entity>-hero">
    <div class="<entity>-hero-bg"<?php if ($_bgStyle): ?> style="<?= $_bgStyle ?>"<?php endif; ?>></div>
    <?php if ($canManage): ?>
    <button type="button" class="<entity>-banner-edit-btn"
            onclick="<entity>OpenBannerModal()"
            aria-label="<?= $bannerUrl ? 'Update Banner Image' : 'Add Banner Image' ?>">
        <i class="fas fa-image"></i>
        <span class="<entity>-banner-edit-label"> <?= $bannerUrl ? 'Update Banner Image' : 'Add Banner Image' ?></span>
        <i class="fas fa-pencil-alt <entity>-banner-edit-pencil" aria-hidden="true"></i>
    </button>
    <?php endif; ?>
    <!-- rest of existing hero markup -->
</div>
```

### 5c. JS config block

Surface all the state the JS will need on `window`:

```html
<script>
var EntityConfig = {
    uir:              '<?= UIR ?>',
    canManage:        <?= $canManage ? 'true' : 'false' ?>,
    entityId:         <?= $entityId ?>,
    hasBanner:        <?= $hasBanner ? 'true' : 'false' ?>,
    bannerShowLogo:   <?= $bannerShowLogo ? 'true' : 'false' ?>,
    bannerVignette:   <?= $bannerVignette ? 'true' : 'false' ?>,
    bannerOffsetX:    <?= (int)$bannerOffsetX ?>,
    bannerOffsetY:    <?= (int)$bannerOffsetY ?>,
    bannerUrl:        <?= json_encode($bannerUrl) ?>,
};
</script>
```

---

## 6. CSS — the geometry and the vignette trick (DO NOT SKIP)

Full reference: `orkui/template/revised-frontend/style/revised.css:4014-4082`.

```css
/* Hero shell */
.<entity>-hero {
    position: relative;
    border-radius: 10px;
    overflow: hidden;
    min-height: 160px;
    background-color: #2d3748;   /* fallback when no image */
}

/* No-banner state: image bleeds 10px past the edges and gets blurred/faded.
   This is the "atmospheric backdrop" mode used when only a heraldry crest
   exists — gives the hero some color without making the crest itself
   look like an intended hero image. */
.<entity>-hero-bg {
    position: absolute;
    top: -10px; left: -10px; right: -10px; bottom: -10px;
    background-size: cover;
    background-position: center;
    opacity: 0.14;
    filter: blur(6px);
}

/* Banner state: full-bleed, no blur, full opacity. The bg div is reset to
   inset:0 so background-position works correctly. */
.<entity>-hero-has-banner .<entity>-hero-bg {
    top: 0; left: 0; right: 0; bottom: 0;
    opacity: 1;
    filter: none;
}
```

### The vignette (this is the clever part)

The vignette darkens + blurs only the **left + bottom edges** where the logo/title/badges/crumb sit, leaving the right ~60% of the banner art untouched. Two pseudo-elements layered on top of the bg:

```css
/* ::before = darkening gradient (always-on, very fast) */
.<entity>-hero-has-banner.<entity>-hero-vignette::before {
    content: '';
    position: absolute; inset: 0; z-index: 1; pointer-events: none;
    background:
        linear-gradient(to right,  rgba(0,0,0,0.62) 0%, rgba(0,0,0,0.38) 18%, rgba(0,0,0,0.05) 38%, rgba(0,0,0,0) 55%),
        linear-gradient(to top,    rgba(0,0,0,0.55) 0%, rgba(0,0,0,0.20) 30%, rgba(0,0,0,0)    60%);
}

/* ::after = soft radial backdrop-blur, masked to an ellipse on the LEFT.
   The mask is the key: blur is applied where the mask is opaque, ignored
   where it's transparent. Center + right of the image stay sharp. */
.<entity>-hero-has-banner.<entity>-hero-vignette::after {
    content: '';
    position: absolute; inset: 0; z-index: 1; pointer-events: none;
    -webkit-mask-image: radial-gradient(ellipse 50% 90% at 12% 50%, #000 45%, transparent 100%);
            mask-image: radial-gradient(ellipse 50% 90% at 12% 50%, #000 45%, transparent 100%);
    backdrop-filter:         blur(7px);
    -webkit-backdrop-filter: blur(7px);
}
```

If you don't have `backdrop-filter` available on the target page (older Safari, certain embedded contexts), the `::after` becomes a no-op and you still get the gradient darkening — graceful degradation.

### The edit pill

Top-center, hover-revealed on desktop, always-faintly-visible on touch:

```css
.<entity>-banner-edit-btn {
    position: absolute; top: 12px; left: 50%; transform: translateX(-50%); z-index: 3;
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(0,0,0,0.55); color: #fff;
    border: 1px solid rgba(255,255,255,0.30);
    border-radius: 999px; padding: 6px 12px;
    font-size: 12px; font-weight: 600; cursor: pointer;
    opacity: 0; transition: opacity .18s, background .15s, border-color .15s;
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
}
.<entity>-hero-editable:hover .<entity>-banner-edit-btn,
.<entity>-banner-edit-btn:focus { opacity: 1; outline: none; }
.<entity>-banner-edit-btn:hover { background: rgba(0,0,0,0.75); border-color: rgba(255,255,255,0.55); }

/* Touch devices have no hover — reveal it softly so phone hosts can find it. */
@media (hover: none), (pointer: coarse) {
    .<entity>-hero-editable .<entity>-banner-edit-btn { opacity: 0.85; }
}
```

On mobile, also collapse the pill to an icon-only square (same pattern other action buttons use):

```css
@media (max-width: 540px) {
    .<entity>-banner-edit-btn .<entity>-banner-edit-label { display: none; }
    .<entity>-banner-edit-btn { padding-left: 10px; padding-right: 10px; }
}
```

---

## 7. The modal — three sections, four steps

Full reference: `orkui/template/revised-frontend/Eventnew_index.tpl:2240-2400`.

The modal has four step panes, only one visible at a time:

```
ev-banner-step-select       ← landing: wireframes, toggles, file picker, action row
ev-banner-step-position     ← drag-to-frame canvas + overlay
ev-banner-step-uploading    ← spinner
ev-banner-step-success      ← checkmark, reloads page
```

### 7a. The two wireframes (DO NOT SKIP)

Show **both desktop and mobile** previews side-by-side using inline SVG. This is the part that most reliably teaches hosts what the safe zones are. Don't substitute with prose — they won't read it.

The two SVGs share a `viewBox="0 0 600 80"` (matches the 7.5:1 banner aspect), so a single CSS rule sizes both:

**Desktop wireframe** — single block with left-fade + bottom-fade overlay gradients showing where the logo/title/crumb land, plus a `"Safe zone for art"` text label in the right portion:

```svg
<svg viewBox="0 0 600 80" preserveAspectRatio="none">
    <rect width="600" height="80" fill="#cbd5e0"/>
    <rect x="0" y="0"  width="360" height="80" fill="url(#wfLeftFade)"   opacity="0.55"/>
    <rect x="0" y="58" width="600" height="22" fill="url(#wfBottomFade)" opacity="0.55"/>
    <!-- logo + title + badges + crumb placeholders -->
    <rect x="20" y="14" width="52" height="52" rx="3" fill="#a0aec0" stroke="#fff" stroke-width="1.2"/>
    <rect x="84" y="22" width="170" height="10" rx="1.5" fill="#fff"/>
    <text x="470" y="44" text-anchor="middle" font-size="10" fill="#2d3748" font-weight="700">Safe zone for art</text>
    <defs>
        <linearGradient id="wfLeftFade"   x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
        </linearGradient>
        <linearGradient id="wfBottomFade" x1="0" y1="1" x2="0" y2="0">
            <stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
        </linearGradient>
    </defs>
</svg>
```

**Mobile wireframe** — same `viewBox`, but shows the **middle ~32%** highlighted as the visible band, the two flanks labelled `"cropped"`, and the logo/title shifted into the middle band:

```svg
<svg viewBox="0 0 600 80" preserveAspectRatio="none">
    <rect x="0"   y="0" width="204" height="80" fill="#e2e8f0"/>   <!-- cropped left -->
    <rect x="396" y="0" width="204" height="80" fill="#e2e8f0"/>   <!-- cropped right -->
    <rect x="204" y="0" width="192" height="80" fill="#cbd5e0"/>   <!-- visible band -->
    <line x1="204" y1="0" x2="204" y2="80" stroke="#4299e1" stroke-width="1.5" stroke-dasharray="4 3"/>
    <line x1="396" y1="0" x2="396" y2="80" stroke="#4299e1" stroke-width="1.5" stroke-dasharray="4 3"/>
    <text x="100" y="46" text-anchor="middle" font-size="10" fill="#718096" font-weight="600">cropped</text>
    <text x="498" y="46" text-anchor="middle" font-size="10" fill="#718096" font-weight="600">cropped</text>
    <!-- logo + title placeholders centred in the middle band -->
</svg>
```

Add a one-line caption under each (`<i class="fas fa-desktop"></i> Desktop · 1800 × 240 px`) and an info note below both wireframes:

> ℹ️ On phones, the banner is cropped to the middle third — keep your subject centred so it survives.

### 7b. Toggles, file picker, action row

After the wireframes:
- Two `<label class="<entity>-banner-toggle">` rows with `<input type="checkbox">` + visible label + small subtext explaining what off does.
- An `<label class="<entity>-upload-area">` styled as a dashed dropzone, click-anywhere, with a hidden `<input type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png">`.
- A small action row at the bottom that conditionally shows three buttons based on `hasBanner`:
  - **If has banner:** "Adjust Image Framing", "Save settings only", "Remove Banner".
  - **If no banner:** disabled hint "Upload a banner first to unlock the display toggles."

### 7c. The position step (drag-to-frame)

This is its own pane. Two layered elements inside a 7.5:1 aspect-ratio box:

```html
<div class="<entity>-banner-position-wrap">
    <canvas id="<entity>-banner-position-canvas" width="1800" height="240"></canvas>
    <svg class="<entity>-banner-position-overlay" viewBox="0 0 1800 240" preserveAspectRatio="none">
        <!-- vignette tint mirroring the real hero -->
        <rect x="0" y="0"   width="900"  height="240" fill="url(#posLeftFade)"   opacity="0.40"/>
        <rect x="0" y="150" width="1800" height="90"  fill="url(#posBottomFade)" opacity="0.35"/>
        <!-- logo placeholder, title bar, badges row, crumb -->
        <rect x="45" y="65"  width="110" height="110" rx="8"  fill="rgba(255,255,255,0.35)" stroke="#fff" stroke-width="2.5"/>
        <text x="100" y="128" text-anchor="middle" font-size="16" fill="#fff" font-weight="700">LOGO</text>
        <rect x="180" y="78"  width="520" height="28" rx="3" fill="rgba(255,255,255,0.45)"/>
        <text x="190" y="99" font-size="20" font-weight="700" fill="#1a202c">Event Title goes here</text>
        <!-- mobile-safe band markers: dashed vertical lines at ~34%/66% -->
        <line x1="612"  y1="0" x2="612"  y2="240" stroke="#fff" stroke-width="2" stroke-dasharray="8 6" opacity="0.55"/>
        <line x1="1188" y1="0" x2="1188" y2="240" stroke="#fff" stroke-width="2" stroke-dasharray="8 6" opacity="0.55"/>
        <text x="900" y="16" text-anchor="middle" font-size="12" fill="#fff" font-weight="600">mobile shows this band</text>
        <defs>…gradients…</defs>
    </svg>
</div>
<p class="<entity>-banner-position-hint">
    <i class="fas fa-arrows-alt"></i>
    <span id="<entity>-banner-position-hint-text">Click and drag to position the image.</span>
</p>
```

CSS:

```css
.<entity>-banner-position-wrap {
    position: relative;
    width: 100%;
    aspect-ratio: 1800 / 240;
    background: #1a202c;
    border-radius: 6px; overflow: hidden;
    user-select: none; touch-action: none;
}
.<entity>-banner-position-canvas { position: absolute; inset: 0; width: 100%; height: 100%; cursor: grab; display: block; }
.<entity>-banner-position-canvas:active { cursor: grabbing; }
.<entity>-banner-position-overlay { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
```

Footer of the pane: a `← Back` button on the left, `Use This View ✓` button on the right.

---

## 8. JavaScript (DO NOT SKIP — the math matters)

Full reference: `orkui/template/revised-frontend/script/revised.js:15054-15424`.

One IIFE, gated on `EntityConfig.canManage`. Three URLs derived from `EntityConfig.uir` + `entityId`.

```js
(function() {
    if (typeof EntityConfig === 'undefined' || !EntityConfig.canManage) return;
    var BANNER_BYTE_LIMIT = 1024 * 1024;             // 1 MB
    var UPLOAD_URL = EntityConfig.uir + 'EntityAjax/banner/' + EntityConfig.entityId + '/update';
    var CONFIG_URL = EntityConfig.uir + 'EntityAjax/banner/' + EntityConfig.entityId + '/config';
    var REMOVE_URL = EntityConfig.uir + 'EntityAjax/banner/' + EntityConfig.entityId + '/remove';
    var TARGET_W = 1800, TARGET_H = 240;
    // … bind everything …
})();
```

### 8a. Position math (cover-fit + percentage offsets)

The banner is stored **uncropped**. The 1800×240 target is just the viewport. Cover-fit the source image into that viewport, then translate it inside the viewport by a percentage in each axis.

`pct = 0` means "show the leftmost/topmost portion of the source" (image's 0% point aligned with frame's 0% point). `pct = 100` means "show the rightmost/bottommost portion." This matches CSS `background-position` semantics exactly — that's why the display layer can use background-position directly without any transform math.

```js
var posState = {
    img: null, isPng: false,
    sourceBlob: null, fromAdjust: false,
    scale: 1, pct: { x: 50, y: 50 },
    dragging: false
};

function pctToPx() {
    var img = posState.img;
    var scaledW = img.width  * posState.scale;
    var scaledH = img.height * posState.scale;
    var overflowX = scaledW - TARGET_W;   // ≥ 0
    var overflowY = scaledH - TARGET_H;   // ≥ 0
    return {
        x: -overflowX * (posState.pct.x / 100),
        y: -overflowY * (posState.pct.y / 100),
        overflowX: overflowX, overflowY: overflowY,
        scaledW: scaledW, scaledH: scaledH
    };
}
```

Cover-fit scale, computed once on load:

```js
var targetAspect = TARGET_W / TARGET_H;
var imgAspect    = img.width / img.height;
posState.scale = (imgAspect > targetAspect)
    ? (TARGET_H / img.height)    // wider than target → scale by height
    : (TARGET_W / img.width);    // taller than target → scale by width
```

Draw is trivial: clear, then `drawImage(img, p.x, p.y, p.scaledW, p.scaledH)`.

### 8b. Drag handler — screen px → target px → percentage

Sign flip: dragging the image to the **right** means showing more of the **left** side, which is `pct.x → 0`. Easy to get backwards, so flag it clearly.

```js
function onMove(e) {
    if (!posState.dragging) return;
    e.preventDefault();
    var p = e.touches ? e.touches[0] : e;
    var info = pctToPx();
    var dxScreenToTarget = TARGET_W / rect.width;
    var dyScreenToTarget = TARGET_H / rect.height;
    var dxTarget = (p.clientX - startClient.x) * dxScreenToTarget;
    var dyTarget = (p.clientY - startClient.y) * dyScreenToTarget;
    if (info.overflowX > 0) posState.pct.x = startPct.x - (dxTarget / info.overflowX) * 100;
    if (info.overflowY > 0) posState.pct.y = startPct.y - (dyTarget / info.overflowY) * 100;
    clampPct();      // 0..100 each axis
    drawPosition();
}
```

Bind to **both** mouse and touch events (`mousedown`/`mousemove`/`mouseup` + `touchstart`/`touchmove`/`touchend`). `touchstart` and `touchmove` need `{ passive: false }` so `preventDefault()` works for the drag.

The hint text adapts to which axis actually has overflow:

```js
if (p.overflowX < 1 && p.overflowY < 1) hint = 'Image already fits — nothing to re-frame.';
else if (p.overflowX > p.overflowY)      hint = 'Drag left or right to choose what shows.';
else                                     hint = 'Drag up or down to choose what shows.';
```

### 8c. The "Adjust Framing" round-trip

When the host already has a banner and clicks **Adjust Image Framing**, the JS:

1. `fetch(EntityConfig.bannerUrl, { cache: 'no-store' })` → `Blob`.
2. Determines whether it's PNG (from `Blob.type` or URL extension).
3. Calls `loadIntoPositionStep(blob, isPng, { fromAdjust: true, startPct: { x, y } })` with the current persisted offsets so the canvas opens already framed how the page currently displays.
4. On `Use This View ✓`, posts **only offsets + toggles** to `/config` — no image bytes go over the wire.

This is what makes re-framing fast and free for hosts.

### 8d. Resize-before-upload

If `sourceBlob.size > 1 MB`, run through the existing `resizeImageToLimit(blob, maxBytes, onSuccess, onError, preservePng)` helper (defined in `orkui/template/default/script/orkui.js:14026`) before posting. It iterates `canvas.toBlob` up to 5 times scaling down by `sqrt(target / current) * 0.9` each pass until under the limit.

For PNGs, pass `preservePng = true` so it stays PNG (no JPEG conversion, no white fill, no quality param). For everything else, it converts to JPEG at quality 0.85 with a white background fill (because JPEG has no alpha).

### 8e. Step state machine

```js
function showStep(active) {
    [stepSelect, stepPosition, stepUploading, stepSuccess].forEach(function(el) {
        if (el) el.style.display = (el === active) ? '' : 'none';
    });
}
```

Transitions:
- File chosen → `loadIntoPositionStep()` → `stepPosition`.
- Position confirm (fresh): `stepPosition` → `stepUploading` → upload → `stepSuccess` → `setTimeout(reload, 1200)`.
- Position confirm (adjust): `stepPosition` → `stepUploading` → config-only POST → `stepSuccess` → reload.
- Save-settings-only / Remove: directly `stepSelect` → action → `stepSuccess` → reload.

Don't try to swap the image in place — a full reload makes the cache-bust trivial and avoids stale-state bugs.

---

## 9. Order of work when porting

1. **Schema first.** Add the 5 columns, plus the `HTTP_<ENTITY>_BANNER` / `DIR_<ENTITY>_BANNER` constants, plus `mkdir -p` the storage dir. Sanity-check with a manual `UPDATE … SET has_banner=1`.
2. **Model getter.** Make sure the existing "get this entity" call hydrates the five new fields (use raw SQL if your ORM caches schema). Verify in a debug `die(json_encode(...))` that the template sees the right values.
3. **Read-only template render.** Skip the modal + JS entirely. Get a banner showing through your hero with the right vignette + offsets when you flip `has_banner` manually in the DB. The CSS is the most cross-cutting piece — verify it works before adding the editing UI.
4. **Backend AJAX.** Port `EventAjax::banner()` to your entity. Test all three actions with `curl` before touching the frontend.
5. **Edit pill + Select step + toggles.** No position step yet — the modal opens, shows wireframes, lets you pick a file, and POSTs straight to `/update` with offsets defaulted to 50/50. End-to-end one path.
6. **Position step + Adjust flow.** Now layer in the canvas drag and the adjust round-trip.
7. **Polish:** mobile breakpoint (icon-only pill, possibly stack the wireframes vertically), dark-mode rules (project ships dark-mode-aware tokens — match the existing `html[data-theme="dark"] .X` patterns), keyboard close (`Esc`), overlay-click close, body scroll-lock while modal open.

---

## 10. Gotchas already discovered

- **`HasBanner` field can be missing from cached schema.** Use raw `$DB->DataSet` in your getter.
- **`$DB->Execute` returns void.** Verify the write landed by re-reading; on mismatch, delete the file you just saved. Saves you from `sql_mode=STRICT` and Yapo silent-failure pitfalls (this is a documented ORK3 gotcha — see `agent-instructions/claude.md`).
- **Always `$DB->Clear()` before `Execute`/`DataSet`** if the model called anything else first. PDO bindings linger between calls and have caused silent INSERT/UPDATE failures elsewhere in the codebase.
- **Delete both `.jpg` and `.png` before writing.** If the host swaps PNG ↔ JPEG, leaving the old file orphaned means `resolve_image_ext` will keep returning the stale extension.
- **Cache-bust with `?v=filemtime()`** in the URL, otherwise browsers serve the old image after an upload.
- **Don't use native `confirm()` if your project has a custom modal.** ORK3 prefers `pnConfirm`/`evConfirm`-style modals; the banner remove uses a basic `confirm()` only because the destructive scope is minimal — port to your project's convention.
- **Never use `title=""` for tooltips.** Use the in-product `data-tip` CSS tooltip pattern that the project already provides (browser tooltips are slow + style-inconsistent).
- **Touch devices have no hover.** Reveal the edit pill at `opacity: 0.85` on `@media (hover: none)` so phone hosts can find it.
- **`backdrop-filter` browser support is uneven.** Don't make the vignette depend on it being supported — the gradient `::before` should carry most of the legibility weight, with the blur `::after` as enhancement.
- **`touch-action: none` on the drag container** and `{ passive: false }` on `touchstart`/`touchmove` listeners. Without these, the browser scrolls the page instead of dragging the banner on mobile.

---

## 11. Files to copy from / reference

| Concern | File | Lines |
|---|---|---|
| Schema | `db-migrations/2026-05-10-add-event-banner.sql` | all |
| Schema (framing) | `db-migrations/2026-05-11-add-banner-offset.sql` | all |
| Storage constants | `config.dev.php`, `config.dist.php` | 24, 51 |
| Model hydration | `system/lib/ork3/class.Event.php` | 109–127 |
| Backend AJAX | `orkui/controller/controller.EventAjax.php` | 977–1095 |
| Template — top resolution | `orkui/template/revised-frontend/Eventnew_index.tpl` | 22–34 |
| Template — hero assembly | `orkui/template/revised-frontend/Eventnew_index.tpl` | 617–644 |
| Template — EvConfig | `orkui/template/revised-frontend/Eventnew_index.tpl` | 2100–2130 |
| Template — modal markup | `orkui/template/revised-frontend/Eventnew_index.tpl` | 2240–2400 |
| CSS — hero + vignette | `orkui/template/revised-frontend/style/revised.css` | 4014–4082 |
| CSS — modal wireframes + position | `orkui/template/revised-frontend/style/revised.css` | 4084–4160 |
| JS — banner IIFE | `orkui/template/revised-frontend/script/revised.js` | 15054–15424 |
| JS — `resizeImageToLimit` helper | `orkui/template/default/script/orkui.js` | 14026–14066 |
| JS — `Common::resolve_image_ext` helper | `system/lib/ork3/common.php` | 338–344 |

That's the whole feature. Total: ~550 LOC of new code (modal markup is the bulk; the math is small).
