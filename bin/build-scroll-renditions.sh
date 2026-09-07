#!/bin/sh
#
# Build WebP renditions of the Scrollery artwork.
#
# Scrollery ships 253 files of Valory Fox's certificate art under assets/scrolls/,
# 244 of them raw PNG/JPG and 50 of those exactly 2550x3300 (a 300 dpi US Letter
# page). A single certificate pulls 3.8-4.6 MB of plates, and the composer's live
# preview renders that into a ~600px stage. This script emits, beside each master:
#
#   <name>.webp           print rendition   - full size, for render/compose
#   <name>_display.webp   display rendition - 1/3 linear (850x1100 for a page plate),
#                                             96 dpi, for the composer preview stage
#
# The `_display` suffix is deliberate: it is the naming the heraldry masters/renditions
# pipeline on feature/high-res-images already established (`000123_display.webp`).
#
# ---------------------------------------------------------------------------------
# WHY THE ENCODER SETTINGS ARE WHAT THEY ARE  (measured, not assumed - see
# docs/scrollery-asset-pipeline.md for the full table)
#
#   PNG masters  -> LOSSLESS WebP.
#     The certificate composites its layers with mix-blend-mode, through the per-tone
#     maps in Scroll::BORDER_STYLES / Scroll::BORDER_STYLE_DEFAULT. On a dark ground a
#     border is drawn `filter:invert() hue-rotate(180deg); mix-blend-mode:screen`, and
#     invert-then-screen AMPLIFIES encoder error near the black outlines instead of
#     hiding it. Measured on the composited result, not on the file:
#         lion/border.png  q88 lossy -> RMSE 0.0064 light / 0.0087 dark,
#                                       peak absolute error 0.235 (60/255),
#                                       4.4% of pixels off by >2/255 on the light path
#                                       and 7.2% on the dark path.
#         lion/border.png  lossless  -> RMSE 0, PAE 0 through BOTH blend paths.
#     Lossless is still -33% to -55% on this corpus, so the exactness is nearly free.
#
#   JPEG masters -> LOSSY WebP at $JPEG_QUALITY (default 90).
#     Lossless WebP on an already-lossy JPEG inflates it 64-232%, so it is not an
#     option. q90 holds the composited peak error at <=0.078 on the plain grounds.
#
#   Neither is emitted unconditionally. WebP LOSES to several of these masters:
#     garber/linen.jpg is +119% at q90 and still +42% at q75; flame/bg.jpg, both
#     zodiac plates and warrior/bg_10.jpg are all larger as WebP too. A rendition is
#     kept only when it beats its master by at least $MIN_GAIN_PCT; otherwise the
#     master stays the best answer and the manifest records `"print": null` so the
#     PHP side serves the master rather than a worse file.
#
#   -strip is safe here: all 217 profiled masters carry the identical
#     `sRGB IEC61966-2.1` profile and the remaining 27 are untagged (i.e. already
#     sRGB by convention). Verified with `identify -format '%[profile:icc]'` across
#     the corpus. Re-check that if new art ever lands in another colour space.
# ---------------------------------------------------------------------------------
#
# Renditions are COMMITTED, not generated at deploy. Reasons, in order:
#   1. The art is frozen. It is Valory Fox's, licensed and unchanging (see NOTICE);
#      regenerating identical bytes on every deploy buys nothing.
#   2. The runtime image has no encoder that can do the job. ork3-php8-app carries no
#      ImageMagick and no cwebp, and its GD is 2.3.0 - which has WebP support but no
#      IMG_WEBP_LOSSLESS constant, so it cannot produce the lossless border layer the
#      measurements above require. Deploy-time generation would mean adding an image
#      toolchain to the production image for artwork that never changes.
#   3. The repo already commits the 62 MB of masters, so the precedent is set and the
#      marginal cost is the delta, not the whole corpus.
# (heraldry-rendition-backfill.php is the deploy-time precedent, but it exists because
#  heraldry is USER-UPLOADED and arrives after the build. Static art is not that.)
#
# Usage:
#   sh bin/build-scroll-renditions.sh                 # build/refresh everything
#   sh bin/build-scroll-renditions.sh --check         # verify manifest is current (CI)
#   sh bin/build-scroll-renditions.sh --force         # re-encode even if unchanged
#   sh bin/build-scroll-renditions.sh --only=lion     # substring filter on the path
#   sh bin/build-scroll-renditions.sh --quality=85    # JPEG print quality
#   sh bin/build-scroll-renditions.sh --verbose
#
# Requires ImageMagick (magick, or convert on IM6) on the machine running it.
#
set -eu

cd "$(git rev-parse --show-toplevel 2>/dev/null)" 2>/dev/null || cd "$(dirname "$0")/.."

ART_DIR="assets/scrolls"
MANIFEST="$ART_DIR/renditions.json"

JPEG_QUALITY=90     # print rendition, JPEG masters
DISPLAY_QUALITY=85  # display rendition, all masters
DISPLAY_SCALE=33.3333333  # percent; 2550 -> 850, 3300 -> 1100
MIN_GAIN_PCT=3      # keep a rendition only if it is at least this much smaller

CHECK=0
FORCE=0
VERBOSE=0
ONLY=""

for arg in "$@"; do
    case "$arg" in
        --check)      CHECK=1 ;;
        --force)      FORCE=1 ;;
        --verbose|-v) VERBOSE=1 ;;
        --only=*)     ONLY=${arg#--only=} ;;
        --quality=*)  JPEG_QUALITY=${arg#--quality=} ;;
        --display-quality=*) DISPLAY_QUALITY=${arg#--display-quality=} ;;
        --help|-h)    sed -n '2,90p' "$0" | sed 's/^#\{1,2\} \{0,1\}//'; exit 0 ;;
        *) echo "Unknown argument: $arg" >&2; exit 2 ;;
    esac
done

# --- encoder -------------------------------------------------------------------
MAGICK=""
if [ "$CHECK" -eq 0 ]; then
    if command -v magick >/dev/null 2>&1; then
        MAGICK="magick"
    elif command -v convert >/dev/null 2>&1; then
        MAGICK="convert"
    else
        cat >&2 <<'EOF'
ImageMagick not found. This script needs `magick` (IM7) or `convert` (IM6).

  macOS:  brew install imagemagick
  Debian: apt-get install imagemagick webp

It deliberately does NOT run inside ork3-php8-app: that image has no ImageMagick,
and its GD 2.3.0 cannot emit lossless WebP, which the border layer requires.
EOF
        exit 1
    fi
fi

sha_of() { shasum -a 256 "$1" 2>/dev/null | cut -c1-16 || sha256sum "$1" | cut -c1-16; }
size_of() { wc -c < "$1" | tr -d ' '; }

# --- collect masters -----------------------------------------------------------
MASTERS=$(find "$ART_DIR" -type f \( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' \) | LC_ALL=C sort)
if [ -n "$ONLY" ]; then
    MASTERS=$(printf '%s\n' "$MASTERS" | grep -- "$ONLY" || true)
fi
if [ -z "$MASTERS" ]; then
    echo "No master art found under $ART_DIR (filter: '${ONLY:-none}')." >&2
    exit 1
fi

# --------------------------------------------------------------------------------
# --check: cheap verification, no encoding. This is the CI/pre-push guard. It fails
# when a master was added, removed or edited without re-running the build, which is
# exactly how a rebase against upstream silently orphans a rendition.
# --------------------------------------------------------------------------------
if [ "$CHECK" -eq 1 ]; then
    if [ ! -f "$MANIFEST" ]; then
        echo "FAIL: $MANIFEST is missing. Run: sh bin/build-scroll-renditions.sh" >&2
        exit 1
    fi
    fails=0
    for m in $MASTERS; do
        rel=${m#"$ART_DIR"/}
        want=$(sha_of "$m")
        got=$(grep -A2 -F "    \"$rel\": {" "$MANIFEST" | grep '"sha"' | head -1 | sed 's/.*"sha": *"\([^"]*\)".*/\1/')
        if [ -z "$got" ]; then
            echo "FAIL: $rel is not in $MANIFEST (new art needs a rendition build)" >&2
            fails=$((fails + 1))
        elif [ "$got" != "$want" ]; then
            echo "FAIL: $rel changed since the renditions were built ($got -> $want)" >&2
            fails=$((fails + 1))
        fi
    done
    # Every rendition the manifest promises must exist on disk.
    for f in $(grep '"file":' "$MANIFEST" | sed 's/.*"file": *"\([^"]*\)".*/\1/'); do
        [ -f "$ART_DIR/$f" ] || { echo "FAIL: manifest promises $f, which does not exist" >&2; fails=$((fails + 1)); }
    done
    # And every manifest key must still be a real master (catches a deleted master).
    for rel in $(grep -E '^    "[^"]+\.(png|jpg|jpeg)": \{' "$MANIFEST" | sed 's/.*"\(.*\)": {.*/\1/'); do
        [ -f "$ART_DIR/$rel" ] || { echo "FAIL: manifest lists $rel, which no longer exists" >&2; fails=$((fails + 1)); }
    done
    if [ "$fails" -gt 0 ]; then
        echo "" >&2
        echo "$fails problem(s). Re-run: sh bin/build-scroll-renditions.sh" >&2
        exit 1
    fi
    echo "OK: scroll renditions are current ($(printf '%s\n' "$MASTERS" | wc -l | tr -d ' ') masters)."
    exit 0
fi

# --- build ---------------------------------------------------------------------
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT INT TERM

ENTRIES="$TMP/entries"
: > "$ENTRIES"

tot_master=0; tot_print=0; tot_display=0
n_print=0; n_print_skipped=0; n_display=0; n_display_skipped=0; n=0

for m in $MASTERS; do
    n=$((n + 1))
    rel=${m#"$ART_DIR"/}
    base=${m%.*}
    ext=$(printf '%s' "${m##*.}" | tr '[:upper:]' '[:lower:]')
    print_file="${base}.webp"
    disp_file="${base}_display.webp"
    print_rel="${rel%.*}.webp"
    disp_rel="${rel%.*}_display.webp"

    msize=$(size_of "$m")
    msha=$(sha_of "$m")
    tot_master=$((tot_master + msize))

    # -- print rendition
    if [ "$ext" = "png" ]; then
        # webp:exact is deliberately left OFF. It preserves the RGB hiding under
        # fully-transparent pixels, which costs ~0.6% and buys nothing: alpha 0
        # contributes nothing through multiply/screen/soft-light, and the composited
        # blend check below measures RMSE 0 / PAE 0 without it.
        $MAGICK "$m" -strip -define webp:lossless=true -define webp:method=6 "$TMP/print.webp"
    else
        $MAGICK "$m" -strip -define webp:method=6 -quality "$JPEG_QUALITY" "$TMP/print.webp"
    fi
    psize=$(size_of "$TMP/print.webp")
    if [ "$((psize * 100))" -lt "$((msize * (100 - MIN_GAIN_PCT)))" ]; then
        mv "$TMP/print.webp" "$print_file"
        tot_print=$((tot_print + psize))
        n_print=$((n_print + 1))
        print_json="{ \"file\": \"$print_rel\", \"bytes\": $psize }"
        [ "$VERBOSE" -eq 1 ] && printf '  print   %-44s %9s -> %9s\n' "$rel" "$msize" "$psize"
    else
        rm -f "$TMP/print.webp"
        # A previously-emitted rendition that no longer wins must go, or the manifest
        # and the disk disagree and --check starts lying.
        rm -f "$print_file"
        tot_print=$((tot_print + msize))
        n_print_skipped=$((n_print_skipped + 1))
        print_json="null"
        [ "$VERBOSE" -eq 1 ] && printf '  print   %-44s %9s -> master wins (%s)\n' "$rel" "$msize" "$psize"
    fi

    # -- display rendition
    $MAGICK "$m" -strip -resize "${DISPLAY_SCALE}%" -define webp:method=6 \
        -define webp:alpha-quality=100 -quality "$DISPLAY_QUALITY" "$TMP/disp.webp"
    dsize=$(size_of "$TMP/disp.webp")
    if [ "$((dsize * 100))" -lt "$((msize * (100 - MIN_GAIN_PCT)))" ]; then
        mv "$TMP/disp.webp" "$disp_file"
        dims=$($MAGICK identify -format '%wx%h' "$disp_file")
        tot_display=$((tot_display + dsize))
        n_display=$((n_display + 1))
        disp_json="{ \"file\": \"$disp_rel\", \"bytes\": $dsize, \"dimensions\": \"$dims\" }"
        [ "$VERBOSE" -eq 1 ] && printf '  display %-44s %9s -> %9s (%s)\n' "$rel" "$msize" "$dsize" "$dims"
    else
        rm -f "$TMP/disp.webp" "$disp_file"
        tot_display=$((tot_display + msize))
        n_display_skipped=$((n_display_skipped + 1))
        disp_json="null"
        [ "$VERBOSE" -eq 1 ] && printf '  display %-44s %9s -> master wins (%s)\n' "$rel" "$msize" "$dsize"
    fi

    cat >> "$ENTRIES" <<EOF
    "$rel": {
      "sha": "$msha",
      "bytes": $msize,
      "print": $print_json,
      "display": $disp_json
    },
EOF
done

# --- manifest -------------------------------------------------------------------
# Deliberately carries NO build timestamp: the manifest must be byte-identical for
# identical input, or every run dirties the working tree and --check cannot mean
# anything. `sha` doubles as the ?v= cache-busting token for the immutable nginx
# block - if a file ever does change, its token changes with it.
{
    echo '{'
    echo '  "_comment": "Generated by bin/build-scroll-renditions.sh. Do not edit by hand.",'
    echo "  \"printQuality\": { \"png\": \"lossless\", \"jpeg\": $JPEG_QUALITY },"
    echo "  \"displayQuality\": $DISPLAY_QUALITY,"
    echo "  \"displayScale\": \"${DISPLAY_SCALE}%\","
    echo '  "files": {'
    sed '$ s/,$//' "$ENTRIES"
    echo '  }'
    echo '}'
} > "$MANIFEST"

pct() { [ "$2" -eq 0 ] && { echo "0.0"; return; }; echo "scale=1; ($1 - $2) * 100 / $2" | bc; }

cat <<EOF

Scroll renditions built.

  masters                 $n files   $(printf '%12s' "$tot_master") bytes
  print (best of both)              $(printf '%12s' "$tot_print") bytes  ($(pct "$tot_print" "$tot_master")%)
      webp kept           $n_print
      master kept         $n_print_skipped   (WebP was not smaller)
  display                           $(printf '%12s' "$tot_display") bytes  ($(pct "$tot_display" "$tot_master")%)
      webp kept           $n_display
      master kept         $n_display_skipped

  manifest: $MANIFEST
EOF
