# Scrollery — asset pipeline and delivery

**Date:** 2026-09-06 · batch 2 items **36** (WebP renditions) and **37** (asset caching).
Decisions of record: `docs/scrollery-panel-decisions.md`. Panel evidence:
`docs/scrollery-panel/08-print-pipeline-perf.md`.

Everything below was measured on this corpus, on this branch, with ImageMagick
7.1.2-23 and against the running `ork3-php8-app` container. Where a number here
disagrees with the panel report, this file is the one that was executed.

---

## 1. What the build emits

`bin/build-scroll-renditions.sh` walks every PNG/JPG under `assets/scrolls/` and emits,
**beside each master**:

| | filename | size | encoder |
|---|---|---|---|
| **print** | `<name>.webp` | full | PNG → lossless · JPEG → q90 lossy |
| **display** | `<name>_display.webp` | 33.3333% linear (a 2550×3300 page → **850×1100**) | q85 lossy, alpha lossless |

So `common/parchment.jpg` produces `common/parchment.webp` and
`common/parchment_display.webp`; `lion/border.png` produces `lion/border.webp` and
`lion/border_display.webp`.

The `_display` suffix is not invented here — it is the naming the heraldry
masters/renditions pipeline on `feature/high-res-images` already established
(`000123_display.webp`).

A machine-readable index lives at **`assets/scrolls/renditions.json`**:

```json
"lion/border.png": {
  "sha": "1f3a...",              // first 16 hex of the MASTER's sha256
  "bytes": 1892536,
  "print":   { "file": "lion/border.webp",         "bytes": 1268842 },
  "display": { "file": "lion/border_display.webp", "bytes": 144528, "dimensions": "850x1100" }
}
```

### ⚠️ `print` can be `null`, and the consumer must handle it

**17 of the 244 masters are smaller than any WebP that reproduces them**, so no print
rendition is emitted for them and the manifest records `"print": null`. That is not an
error state — it means *the master is the best answer for that file*. The full list:

```
battle/arcane/wash.jpg   153,313 → 154,296 webp
common/parchment.jpg   1,690,225 → 1,697,572
flame/bg.jpg             803,187 → 1,402,190   (+75%)
garber/linen.jpg       1,406,912 → 3,083,394   (+119%)
jovious/bg.jpg         3,426,835 → 3,546,822
warrior/bg_10.jpg      1,019,847 → 1,315,330
warrior/border_1..9.jpg  (all nine, +7% to +21%)
zodiac/bg_generic.jpg    915,438 → 1,243,508   (+36%)
zodiac/bg_rank.jpg       916,378 → 1,244,662   (+36%)
```

`garber/linen.jpg` is still **+42% at q75**. There is no quality at which WebP wins on
it. Any consumer that assumes "there is always a `.webp`" will serve a *larger* file for
these, so the manifest — not `file_exists()`, and not an nginx `try_files … .webp` map —
is the contract.

---

## 2. The PHP contract — `Scroll::ArtUrl()`

`Scroll::ArtBase()` returns a **base URL** (`HTTP_ASSETS . 'scrolls'`), and callers
append the relative path. A `$size` argument *on the base alone* cannot express this
naming, because the size lives in the **filename**, not the directory — an
`ArtBase('display')` returning a `/display` directory would be a path nothing writes to.

**Resolved, and already landed on this branch.** `class.Scroll.php` keeps `ArtBase()`
size-free and adds the resolver beside it:

```php
Scroll::ArtUrl(string $relative, string $size = Scroll::ART_MASTER): string
```

- `ART_MASTER` → the manifest's `print.file` when one exists, else the master
- `ART_DISPLAY` → `display.file`, falling back to `print.file`, then the master

It reads `assets/scrolls/renditions.json` once per process and treats a **missing
manifest as "masters only"**, so the PHP change is safe to land before the generated
files do, and vice versa. Verified compatible with the manifest this build emits: same
`files` map, same `print`/`display` sub-objects, `null` handled.

⚠️ One comment on `ArtUrl()` overstates the case: it describes the print rendition as
"same pixels, fewer bytes". That is exact for **PNG** masters (lossless — §3) but not
for the JPEG ones, which are re-encoded at q90. The behaviour is right; the sentence is
half right.

**Where each size belongs**, from the panel's own reading of the surfaces:

| surface | size |
|---|---|
| `Scrollery/render` (the print surface) | `print` |
| `Scrollery/compose` (the committed certificate) | `print` |
| the create page's live preview stage | `display` |
| any bulk thumbnail | `display` |

`?v=` cache-busting: append `?v=<sha>` from the manifest if a file ever does change.
nginx ignores the query string when serving a static file, so nothing server-side needs
to change — see §4.

---

## 3. Validation — why the encoder settings are what they are

The certificate composites its layers with `mix-blend-mode`, through
`Scroll::BORDER_STYLES` / `Scroll::BORDER_STYLE_DEFAULT`. On a **dark** ground a raster
border is drawn `filter: invert() hue-rotate(180deg); mix-blend-mode: screen`. Invert
then screen does not hide encoder error near a black outline — it **amplifies** it. So
the validation measures the error of the **composited certificate**, not of the file.

Method: composite the layer over `common/parchment.jpg` with `Multiply` (the light
path) and over `owl/cyanotype.jpg` with invert + hue-rotate 180° + `Screen` (the dark
path), for the master and for the candidate, then `compare -metric RMSE / PAE` the two
results.

### Lossy q88 on the line-art PNGs — rejected

| layer | path | RMSE | peak abs. error | pixels off by >2/255 |
|---|---|---|---|---|
| `lion/border.png` | light | 0.0064 | **0.235** (60/255) | 4.39% |
| `lion/border.png` | dark | 0.0087 | **0.239** | **7.23%** |
| `rose/border.png` | light | 0.0058 | 0.173 | 6.74% |
| `rose/border.png` | dark | 0.0058 | 0.165 | 7.97% |
| `warrior/symbol_9.png` | dark | 0.0038 | 0.153 | 6.48% |

The dark path is consistently worse than the light one, which is the amplification
predicted above, observed.

### Lossless on the same layers — accepted

Every PNG layer tested is **bit-exact through both blend paths**:

```
lion/border.png            1,892,536 → 1,268,842 (-33%)   RMSE 0 / PAE 0  light and dark
rose/border.png            2,841,162 → 1,835,012 (-35%)   RMSE 0 / PAE 0
warrior/symbol_9.png       2,504,732 → 1,505,222 (-40%)   RMSE 0 / PAE 0
battle/border_symbols.png     25,458 →    10,854 (-57%)   RMSE 0 / PAE 0
hunter/border.png          1,882,336 → 1,208,108 (-36%)   RMSE 0 / PAE 0
crown/border.png           1,448,496 →   889,204 (-39%)   RMSE 0 / PAE 0
hydra/border.png           1,846,627 → 1,141,112 (-38%)   RMSE 0 / PAE 0
mask/border.png              773,316 →   274,384 (-65%)   RMSE 0 / PAE 0
smith/border.png           1,748,334 →   959,294 (-45%)   RMSE 0 / PAE 0
```

So the panel's "consider lossless for the line-art border layer" is the right call, and
it costs less than expected: the whole border/symbol class is one third to two thirds
smaller *and* provably identical.

`webp:exact` is deliberately **off**. It preserves the RGB hiding under fully
transparent pixels, costs ~0.6%, and buys nothing — alpha 0 contributes nothing through
multiply/screen/soft-light, and the table above is measured without it.

### The one border that must be lossy

`owl/border.jpg` is the exception `Scroll::BORDER_STYLES` already documents — white line
art on an **opaque** black plate, so it is a JPEG and lossless WebP inflates it 64%. At
q90 it is 419,570 → 254,386 (−39%) with peak absolute error 0.051 and 2.8% of pixels
off by >2/255, through `invert+multiply` (light) and `screen` (dark) alike. Accepted:
it is a photographic-grade plate, not a two-tone matte.

### JPEG grounds

q90, and only when smaller. Composited peak error on the plain grounds is ≤0.078.
q85 roughly doubles the saving (`common/parchment.jpg` −28%, `owl/cyanotype.jpg` −63%)
at RMSE 0.0106 / 0.0100 — a real option, exposed as `--quality=85`, deliberately not
the default. **A print rendition is not the place to spend fidelity for bytes**; the
display rendition below is.

### Colour management

All 217 profiled masters carry the identical `sRGB IEC61966-2.1` profile and the other
27 are untagged (i.e. sRGB by convention), verified with
`identify -format '%[profile:icc]'` across the corpus. `-strip` is therefore a no-op for
rendering. Re-check that if art ever lands in another colour space.

---

## 4. What it actually saves

### Whole corpus

```
masters                 244 files    64,369,071 bytes
print (best of both)                 43,566,504 bytes   -32.3%   (227 webp + 17 masters)
display                               6,029,748 bytes   -90.6%   (244 webp)
```

### Per certificate — the real before/after

| certificate | master | print | | display | |
|---|---:|---:|---:|---:|---:|
| lion rank 1 | 3,831,978 | 3,110,835 | −18.8% | **269,072** | −93.0% |
| warrior rank 9 | 4,603,939 | 3,604,429 | −21.7% | **242,538** | −94.7% |
| jovious rank 5 | 4,132,040 | 3,844,957 | −6.9% | **406,720** | −90.2% |
| custom · parchment/knot/common rank 5 | 1,710,145 | 1,705,157 | −0.3% | **115,402** | −93.3% |
| custom · cyanotype/owl/common rank 5 | 2,232,773 | 1,315,154 | −41.1% | **112,954** | −94.9% |

### ⚠️ Correction to the panel

The panel projected "**62 MB → ~33 MB, and 4 MB/cert → ~1.6 MB**". At genuine print
fidelity that is not achievable on this corpus, and the gap is not a tuning question:

- The panel's −51% was q85 lossy applied to everything, **without checking whether the
  result was smaller than the master** (it is not, for 17 files, one of them by +119%)
  and **without validating against the blend modes** (q88 on the line art puts 7% of
  the composited dark-ground pixels off by more than 2/255).
- The single largest file in most certificates, `common/parchment.jpg` at 1.69 MB, is
  simply not beatable by WebP at print quality. It alone is 44% of a lion rank-1
  certificate.

**The win is real but it is in the display rendition, not the print one.** The panel's
own framing said why: *"the create page's live preview renders into a ~600px stage but
downloads 300 dpi plates; every order change costs ~4 MB for a postcard-sized picture."*
That specific cost drops **93–95%**, from 3.8 MB to 269 KB for a lion rank 1.

Caveat on the display size: 850 CSS px into a ~600 px stage is 1.4× oversampled on a
1× display and *under*-sampled on a 2× one. 850×1100 is the size the brief specified and
what is shipped; a `@2x` tier is a later decision, not a defect.

---

## 5. Committed, not generated at deploy

**Decision: the renditions are committed.** 471 files, 33.8 MB, on top of the 61 MB of
masters the repo already carries.

1. **The art is frozen.** It is Valory Fox's, licensed, path-addressed and never edited
   in place (see `NOTICE`). Regenerating byte-identical output on every deploy buys
   nothing.
2. **The runtime image cannot do the job.** `ork3-php8-app` has **no ImageMagick and no
   cwebp** (`which cwebp convert magick` → nothing), and its GD is **2.3.0**: WebP
   support is `true`, but `IMG_WEBP_LOSSLESS` is undefined, so it **cannot produce the
   lossless border layer** §3 requires. Deploy-time generation means adding an image
   toolchain to the production image for artwork that never changes.
3. **The precedent cuts this way.** `heraldry-rendition-backfill.php` generates at
   deploy because heraldry is **user-uploaded** and arrives *after* the build. Static
   art is not that. The repo already commits the masters.

The cost of being wrong is bounded and cheap: `sh bin/build-scroll-renditions.sh`
rebuilds everything deterministically, and `--check` says whether it needs to.

---

## 6. Delivery — the nginx changes

All verified with live requests against `ork3-php8-app` after
`docker cp` + `nginx -t` + `nginx -s reload`.

### New: `location ^~ /assets/scrolls/`

```
Cache-Control: public, max-age=31536000, immutable
Access-Control-Allow-Origin: *
```

Was `public, max-age=300, must-revalidate`. `^~` beats every regex location, so the
300-second image rule and the `.php` handler can never claim a path under here.

| request | before | after |
|---|---|---|
| `assets/scrolls/common/parchment.jpg` | `max-age=300, must-revalidate` | `max-age=31536000, immutable` |
| `assets/scrolls/rose/burn.svg` | **no `Cache-Control` at all** | `max-age=31536000, immutable`, `Content-Encoding: gzip` |
| `assets/scrolls/lion/border.webp` | *(extension not covered)* | `image/webp`, `max-age=31536000, immutable` |
| `assets/scrolls/lion/border_display.webp` | — | `image/webp`, `max-age=31536000, immutable` |
| `…/parchment.jpg?v=deadbeef` | — | 200, same bytes, same immutable header |

### `gzip_types image/svg+xml`

`gzip on` is set in `/etc/nginx/nginx.conf`, but `gzip_types` there is **commented
out**, so nginx fell back to its built-in default of `text/html` only — the nine SVG
line-art files a certificate loads were being sent raw. Now compressed, with
`gzip_vary on` and `gzip_min_length 512`. Measured over the wire, all nine scroll SVGs:
**201,360 → 90,839 bytes (−55%)**; `rose/burn.svg` alone 153,257 → 71,064.
Deliberately narrow: widening this to CSS/JS/JSON is a site-wide change with its own
blast radius.

### Generic image rule: extension coverage fixed

`location ~* \.(jpg|gif|png)` → `location ~* \.(jpe?g|gif|png|webp|svg|ico)$`.
The old pattern had no `$` anchor and omitted `jpeg`, `svg` and `webp`, so those got no
`Cache-Control` header anywhere on the site. It keeps `max-age=300, must-revalidate` on
purpose: heraldry and player photos **are** user-uploaded and mutable.

### ⚠️ Item 37's woff2 half is based on a stale premise

> *"There is no woff2 MIME mapping in this stack (deferred item §3.8) — every
> certificate loads five font files served as `application/octet-stream`."*

**Not true on this branch.** Deferred item 3.8 was decided BUILD on 2026-09-04 and
shipped the same day in **`364c8580`** ("Bugfix: WnAjax exits after JSON, Scrollery
drops model-built URL, woff2 MIME"). Live, before any change of mine:

```
$ curl -sI .../style/fonts/BlackChancery.woff2
Content-Type: font/woff2
Cache-Control: public, max-age=2592000, immutable
Access-Control-Allow-Origin: *
```

The base `/etc/nginx/mime.types` genuinely has only `application/font-woff woff` and no
woff2 at all — which is *why* the `location ~* \.(woff2?)$` block with its local `types`
override exists. Any face workstream 2 adds is covered by the extension match; nothing
per-font needs maintaining. The block now carries a comment saying so, so item 3.8 is
not re-opened a third time.

### Known, deliberately not fixed here

`add_header` in a location **replaces** the server-level set rather than merging with
it. The generic image location has always had this bug, so every `jpg/gif/png` on the
site has silently been served **without** `Access-Control-Allow-Origin`. The new scrolls
block re-asserts it (as the woff2 block already did). Fixing it for the generic rule is
a site-wide change, not a Scrollery one.

`nginx.ork3.config` is `COPY`ed at image build time by all three Dockerfiles — it is not
bind-mounted — so a container rebuild is required for this to take effect in dev. The
live verification above was done with `docker cp` + `nginx -s reload`.

---

## 7. Commands

```sh
sh bin/build-scroll-renditions.sh                # build / refresh
sh bin/build-scroll-renditions.sh --check        # verify (no encoding, no PHP)
sh bin/build-scroll-renditions.sh --only=lion/ -v
sh bin/build-scroll-renditions.sh --quality=85   # the smaller-but-lossier print tier

sh bin/check-scroll-assets.sh                    # the full guard (see below)
```

Requires ImageMagick (`brew install imagemagick` / `apt-get install imagemagick webp`)
on the machine running the build. Nothing at runtime requires it.

### The layout artefacts, and when to regenerate them

The typeset engine places text against two committed data files. Neither is part of the
rendition build above, and neither regenerates itself.

| file | holds | regenerate when | command |
| --- | --- | --- | --- |
| `assets/scrolls/ink-extents.json` | each flourish's ink box, plus a 32×32 `grid` of cells for every flourish | any flourish art changes | `php bin/build-scroll-ink-extents.php` |
| `assets/scrolls/free-space.json` | the free space each plate config leaves for type | any plate changes, or `system/lib/ork3/data/scroll-layout-underlays.php` changes | `npm run profile:scrolls` |

The ink-extents build needs ImageMagick and GD on the host that runs it. `profile:scrolls`
renders every plate config through the fixture renderer (`bin/scroll-render-fixtures.php`,
run inside the app container), so the Docker app must be up.

Both files are folded into `Scroll::renderCacheKey()` through their mtimes, so a
regenerated file reaches the browser at once instead of as each cached certificate
expires. That is also why they must be regenerated by the command and not edited by hand:
the stamp is the file's mtime and nothing else.

`tests/Unit/ScrollFreeSpaceCoverageTest.php` fails when a plate config has no entry in
`free-space.json`. A new order, rank band or underlay rule will trip it until
`npm run profile:scrolls` has been run and the result committed.

---

## 8. The guards (item 45)

- **Ground contrast** — `tests/Unit/ScrollGroundContrastTest.php`, backed by
  `tests/fixtures/scroll-ground-luminance.json`. See the file's own header for the
  method and the regeneration command.
- **Asset paths** — `bin/check-scroll-assets.sh`, run by `.githooks/pre-push` and by
  `.github/workflows/scroll-guards.yml`.
- **Upstreaming** — `docs/scrollery-upstreaming-gate.md`.
