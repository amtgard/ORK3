# Image Pipeline — High-Res Masters + Rendition Set

**Date:** 2026-07-07
**Status:** Approved design, ready for implementation plan
**Scope:** Player profile pictures, player/mundane heraldry, park heraldry, kingdom heraldry (and, by shared code path, unit + event heraldry)

## Problem

Uploaded images are destroyed at upload time. A client-side canvas step (`resizeImageToLimit`, `orkui/template/revised-frontend/script/revised.js`) downscales and recompresses any file over ~340 KB (`348836` bytes) — JPEG q0.85 with a white-flattened background for opaque images, iterated up to 5× — before it ever reaches the server. The server (GD, `class.Heraldry::store_heraldry`, `class.Player::set_image`) then re-encodes the already-shrunk bitmap with no further scaling. The stored file is served byte-for-byte as a static asset.

The result is user-visible quality loss (the driving complaint) precisely where these images are shown largest: redesigned profile heroes on retina displays, click-to-enlarge / lightbox views, and heraldry consumed by the Scroll generator / print output. There is exactly one stored file per entity and no high-resolution original is retained.

## Goal

Deliver visibly higher quality where images are shown large, while keeping storage and delivery bandwidth reasonable. Resolve the quality-vs-cost tension by **decoupling the master we store from the bytes we deliver**: store one high-quality master, derive small format-optimized renditions for delivery.

Storage is explicitly the *lower* concern (heraldry is flat-color and compresses small; masters + renditions are a few hundred KB each). The recurring cost is bandwidth, which the rendition set drives *down* for the ~90% of views that are small.

## Current-State Constraints (verified)

- **GD only** — no ImageMagick/Imagick anywhere; containers load `php8.1-gd`. GD resamples well (`imagecopyresampled`) and supports WebP (to confirm `imagewebp` at build time; JPEG fallback if absent).
- **Transport is the real ceiling, not storage.** nginx has no `client_max_body_size` override (effective default **1 MB**); PHP uses stock `upload_max_filesize 2M` / `post_max_size 8M`. `memory_limit` is already 512M. Anything over ~1 MB dies at nginx today. Raising these is a hard prerequisite.
- **Serving is static and cheap.** nginx serves `assets/…` directly (`nginx.ork3.config`), 300 s cache header, no PHP in the path. Derived renditions are just more static files — no resize-on-read infrastructure needed.
- **URL construction is centralized** — `Heraldry::resolve_heraldry_url` (`system/lib/ork3/class.Heraldry.php:48`) and `Player::resolve_player_image_url` (`system/lib/ork3/class.Player.php:1876`). Making URLs rendition-aware is a contained change at these two chokepoints.
- **Cache-busting** is `?v=filemtime()` at URL-build time; files overwrite in place at id-based names (`sprintf("%06d", id)` etc.). Extension (`.png` vs `.jpg`) is chosen by transparency/mime and probed on read via `file_exists`.
- **Precedent for keeping an original:** the banner path already stores an untouched source via `move_uploaded_file` (`controller.ParkAjax.php`, `controller.KingdomAjax.php`).
- **Byte caps to remove/relax:** client `348836` (multiple sites in `revised.js`); server `465000` (`class.Heraldry.php:112,150`) and `1365334` (`class.Player.php:790,1833,1845`); legacy admin-form `$_FILES` checks (`controller.Admin.php:1112,1147`).

## Design

### The master (what we store)

- **Downscale only if the longest edge exceeds 3000 px** (beyond a 300-DPI ~3–4″ scroll heraldry; generous for retina lightbox). Never upscale.
- **Format/quality:** preserve transparency semantics — PNG (lossless) when the source has alpha, otherwise JPEG **q0.92**. No forced recompression of an already-small file.
- **Hard ceiling ~6 MB** after downscale, as an abuse guard. Over-ceiling uploads are **rejected with a clear message**, never silently shrunk.
- **Client-side keeps only a gentle guard:** a canvas step that engages solely above ~3000 px / very large files, so a 12 MP phone photo isn't pushed whole over the wire. The aggressive 340 KB target is removed. Server-side GD is authoritative and re-clamps regardless of what the client sent.

### Renditions & delivery

On each upload, after the master is written, GD generates a fixed set of downscaled renditions. A rendition is never upscaled: if the master is smaller than a target, that rendition is the master.

| Rendition | Longest edge | Serves |
|---|---|---|
| `thumb` | 256 px | list rows, nav, small avatars (~90% of views) |
| `display` | 1024 px | profile hero + card layouts |
| *(master)* | ≤ 3000 px | lightbox / enlarge, print / scroll |

- **Delivery format: WebP for derived renditions** (`thumb`, `display`) — universal support in 2026, ~25–35% smaller than JPEG/PNG at equal quality. The **master keeps its original format** (PNG/JPEG) for maximally-compatible, lossless-where-it-matters print/scroll/download. If `imagewebp` is unavailable at build time, renditions fall back to JPEG with no other design change.
- **Naming** extends the existing id-based scheme with suffixes: master `000123.png`, `000123_display.webp`, `000123_thumb.webp`. Keeps "one entity, overwrite in place."
- **URL delivery:** the two centralized helpers gain a `Size` argument, e.g. `GetHeraldryUrl(['Type'=>…,'Id'=>…,'Size'=>'thumb'])`. Each surface passes the size it needs. Default (no `Size`) resolves to master-or-display so untouched call sites keep working. Per-rendition `?v=filemtime` cache-busting is retained. If a requested rendition file is missing, the helper falls back to the master.
- **Serving is unchanged** — static files from nginx, no PHP passthrough, no runtime resize cost.

## Phasing

### Phase 1 — Quality now (ships first)

- Raise transport limits: nginx `client_max_body_size` → ~8 MB; PHP `upload_max_filesize` / `post_max_size` to match.
- Replace the client 340 KB squeeze with the gentle ≤3000 px guard.
- Server-side master clamp: 3000 px / q0.92 via GD `imagecopyresampled`; ~6 MB reject ceiling; relax the old `465000` / `1365334` byte caps accordingly.
- URL helpers unchanged; every surface serves the single, now high-quality master.
- **Outcome:** hero + lightbox quality complaints resolved. Thumbnails temporarily serve the full master — a brief, acceptable bandwidth regression erased by Phase 2.

### Phase 2 — Efficiency (rendition set)

- On upload, generate `thumb` + `display` WebP renditions alongside the master.
- Teach both URL helpers the `Size` argument; update each display surface to request the right size (thumb in lists/nav, display in heroes, master in lightbox/scroll).
- **Backfill script** (mirroring `heraldry-trim-backfill.php`) to generate renditions for existing stored images.

## Old-images handling (decided)

Images uploaded before Phase 1 were already shrunk to ~340 KB; their high-res masters are **gone and unrecoverable**. The Phase 2 backfill can only derive renditions from what's on disk, so old images stay soft — only re-uploads restore true quality.

**Decision: natural re-upload over time.** No re-upload nudge, no forced migration. Old images upgrade organically as owners re-upload. (A "low-resolution — re-upload for better quality" hint on manage screens was considered and explicitly declined; it remains an easy future addition if desired.)

## Testing

- **Rendition generation (unit-level, GD):** correct output dimensions per tier; format (WebP renditions, original-format master); **no upscaling** when master < target; transparency preserved for PNG masters; opaque JPEGs not white-fringed incorrectly.
- **Transport limits:** an upload between the old ~1 MB ceiling and the new ~8 MB limit succeeds end-to-end; an over-ceiling (~>6 MB post-downscale) upload is rejected with a clear message.
- **URL helpers:** `Size` argument resolves to the right file; missing-rendition falls back to master; default (no `Size`) preserves existing behavior; `?v=filemtime` cache-bust present per rendition.
- **Visual pass:** hero, lightbox, and list/nav at retina — confirm sharpness up and thumbnail bytes down.

## Out of Scope

- On-the-fly / CDN image service (Approach C) — over-engineered for this app; static serving stays.
- AVIF (GD on these containers lacks it).
- Banner pipeline (separate code path; already retains an uncropped source).
- The re-upload nudge for legacy low-res images.
