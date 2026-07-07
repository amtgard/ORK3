#!/usr/bin/env bash
# Evidence suite — pixel + DOM fuzz discovery and pass/fail proof (FU-13).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
EVIDENCE="$ROOT/tools/fuzzy-validator/evidence"
PYTHON_DIR="$ROOT/tools/fuzzy-validator/python"
SCRIPTS="$EVIDENCE/scripts"
PROFILE="test"

export PYTHONPATH="${PYTHONPATH:-}:$PYTHON_DIR"
export ORK3_E2E_BASE_URL="${ORK3_E2E_BASE_URL:-http://localhost:19080/orkui/}"

die() { echo "evidence-suite: $*" >&2; exit 1; }

require_file() {
  [[ -f "$1" ]] || die "missing required file: $1"
}

# --- Preconditions (FU-12 virgin baselines) ---
require_file "$EVIDENCE/baselines/$PROFILE/player-profile.png"
require_file "$EVIDENCE/baselines/$PROFILE/home-authenticated.png"
require_file "$EVIDENCE/baselines/$PROFILE/home-authenticated.dom.html"

run_discover() {
  local page_id="$1"
  local phase="$2"
  if [[ "$phase" == "visual" ]]; then
    python3 "$PYTHON_DIR/discover_fuzz.py" \
      --page-id "$page_id" \
      --calibration-dir "$EVIDENCE/calibrations/$page_id" \
      --defaults "$EVIDENCE/manifests/defaults.json5" \
      --out "$EVIDENCE/manifests/$PROFILE/${page_id}.fuzz.json" \
      --overlay "$EVIDENCE/reports/pixel-proof/${page_id}-calibration-overlay.png" \
      --baseline-out "$EVIDENCE/baselines/$PROFILE/${page_id}.png"
  else
    python3 "$PYTHON_DIR/discover_dom_fuzz.py" \
      --page-id "$page_id" \
      --calibration-dir "$EVIDENCE/calibrations/$page_id" \
      --defaults "$EVIDENCE/manifests/defaults.json5" \
      --out "$EVIDENCE/manifests/$PROFILE/${page_id}.dom-fuzz.json" \
      --debug-out "$EVIDENCE/reports/dom-proof/${page_id}-dom-fuzz.txt" \
      --baseline-out "$EVIDENCE/baselines/$PROFILE/${page_id}.dom.json"
  fi
}

run_validate() {
  local page_id="$1"
  local phase="$2"
  local run_id="$3"
  local expect="$4"
  set +e
  python3 -m fuzzy_validator.cli validate \
    --tool-root "$EVIDENCE" \
    --profile "$PROFILE" \
    --page "$page_id" \
    --phase "$phase" \
    --skip-capture \
    --run-id "$run_id"
  local code=$?
  set -e
  [[ "$code" -eq "$expect" ]] || die "$phase validate $page_id expected exit $expect got $code (run-id=$run_id)"
}

copy_report() {
  local run_id="$1"
  local dest="$2"
  local src="$EVIDENCE/reports/run-$run_id"
  [[ -d "$src" ]] || die "missing report dir $src"
  rm -rf "$dest"
  mkdir -p "$(dirname "$dest")"
  cp -R "$src" "$dest"
  cp "$src/summary.json" "$dest/../summary-${run_id}.json" 2>/dev/null || cp "$src/summary.json" "$dest/summary.json"
}

mkdir -p "$EVIDENCE/reports/pixel-proof" "$EVIDENCE/reports/dom-proof"

echo "=== Pixel: discover fuzz from heraldry mutation ==="
python3 "$SCRIPTS/evidence_mutations.py" pixel-discover
run_discover "player-profile" visual
require_file "$EVIDENCE/manifests/$PROFILE/player-profile.fuzz.json"
python3 - <<'PY' "$EVIDENCE/manifests/$PROFILE/player-profile.fuzz.json"
import json, sys
manifest = json.load(open(sys.argv[1]))
zones = manifest.get("fuzzZones", [])
assert zones, "player-profile.fuzz.json must have non-empty fuzzZones"
print(f"pixel discover: {len(zones)} fuzz zone(s)")
PY

echo "=== Pixel: in-zone validate (expect pass) ==="
python3 "$SCRIPTS/evidence_mutations.py" pixel-inzone
run_validate "player-profile" visual "pixel-inzone" 0
copy_report "pixel-inzone" "$EVIDENCE/reports/pixel-proof/inzone"

echo "=== Pixel: out-of-zone validate (expect fail) ==="
python3 "$SCRIPTS/evidence_mutations.py" pixel-outzone
run_validate "player-profile" visual "pixel-outzone" 1
copy_report "pixel-outzone" "$EVIDENCE/reports/pixel-proof/outzone"
# Promote in-zone report as primary index for reviewers
cp -R "$EVIDENCE/reports/pixel-proof/inzone" "$EVIDENCE/reports/pixel-proof/index.bundle"
cat > "$EVIDENCE/reports/pixel-proof/README.txt" <<'EOF'
Open inzone/index.html (PASS) and outzone/index.html (FAIL).
Calibration overlay: player-profile-calibration-overlay.png
EOF

echo "=== DOM: discover fuzz from session token drift ==="
python3 "$SCRIPTS/evidence_mutations.py" dom-discover
run_discover "home-authenticated" dom
require_file "$EVIDENCE/manifests/$PROFILE/home-authenticated.dom-fuzz.json"
require_file "$EVIDENCE/reports/dom-proof/home-authenticated-dom-fuzz.txt"
python3 - <<'PY' "$EVIDENCE/manifests/$PROFILE/home-authenticated.dom-fuzz.json"
import json, sys
manifest = json.load(open(sys.argv[1]))
nodes = manifest.get("fuzzNodes", [])
assert nodes, "home-authenticated.dom-fuzz.json must have non-empty fuzzNodes"
print(f"dom discover: {len(nodes)} fuzz node(s)")
PY

echo "=== DOM: in-zone validate (expect pass) ==="
python3 "$SCRIPTS/evidence_mutations.py" dom-inzone
run_validate "home-authenticated" dom "dom-inzone" 0
copy_report "dom-inzone" "$EVIDENCE/reports/dom-proof/inzone"

echo "=== DOM: out-of-zone validate (expect fail) ==="
python3 "$SCRIPTS/evidence_mutations.py" dom-outzone
run_validate "home-authenticated" dom "dom-outzone" 1
copy_report "dom-outzone" "$EVIDENCE/reports/dom-proof/outzone"
cat > "$EVIDENCE/reports/dom-proof/README.txt" <<'EOF'
Open inzone/index.html (PASS) and outzone/index.html (FAIL).
Debug: home-authenticated-dom-fuzz.txt
EOF

# Use inzone report as index.html entry point with links noted in README
cp "$EVIDENCE/reports/pixel-proof/inzone/index.html" "$EVIDENCE/reports/pixel-proof/index.html" 2>/dev/null || true
cp "$EVIDENCE/reports/pixel-proof/inzone/summary.json" "$EVIDENCE/reports/pixel-proof/summary.json" 2>/dev/null || true
cp "$EVIDENCE/reports/dom-proof/inzone/index.html" "$EVIDENCE/reports/dom-proof/index.html" 2>/dev/null || true
cp "$EVIDENCE/reports/dom-proof/inzone/summary.json" "$EVIDENCE/reports/dom-proof/summary.json" 2>/dev/null || true

echo "evidence-suite: PASS (pixel + dom discover, in-zone pass, out-of-zone fail)"
