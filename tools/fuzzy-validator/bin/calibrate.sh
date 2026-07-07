#!/usr/bin/env bash
# Fuzzy Validator — pixel/DOM calibration (capture + fuzz discovery)
set -euo pipefail

TOOL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$TOOL_ROOT/../.." && pwd)"
PYTHON_DIR="$TOOL_ROOT/python"

usage() {
  cat <<'EOF'
Usage:
  calibrate.sh --page PAGE_ID [--phase visual|dom|all]
  calibrate.sh --pages id1,id2 [--phase visual|dom|all]
  calibrate.sh --all [--phase visual|dom|all]

Runs Playwright capture (×N) then discovery for each page.
Default phase: visual (pixel fuzz + asset stability).
EOF
}

PAGE=""
PAGES=""
ALL=false
PHASE="visual"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --page)
      PAGE="$2"
      shift 2
      ;;
    --pages)
      PAGES="$2"
      shift 2
      ;;
    --all)
      ALL=true
      shift
      ;;
    --phase)
      PHASE="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "calibrate.sh: unknown argument '$1'" >&2
      usage
      exit 2
      ;;
  esac
done

if [[ "$PHASE" != "visual" && "$PHASE" != "dom" && "$PHASE" != "all" ]]; then
  echo "calibrate.sh: unsupported phase '$PHASE'" >&2
  exit 2
fi

if [[ "$ALL" == true ]]; then
  TARGETS=()
  export PYTHONPATH="${PYTHONPATH:-}:$PYTHON_DIR"
  while IFS= read -r line; do
    [[ -n "$line" ]] && TARGETS+=("$line")
  done < <(python3 -c "
import sys
sys.path.insert(0, '$PYTHON_DIR')
from lib.page_registry import load_pages_registry, active_page_ids, estimated_calibrate_seconds
registry = load_pages_registry('$TOOL_ROOT/manifests/pages.json5')
seconds = estimated_calibrate_seconds(registry)
ids = active_page_ids(registry)
print(f'calibrate.sh: --all will process {len(ids)} pages (~{seconds // 60} min)', file=sys.stderr)
for page_id in ids:
    print(page_id)
")
elif [[ -n "$PAGE" ]]; then
  TARGETS=("$PAGE")
elif [[ -n "$PAGES" ]]; then
  IFS=',' read -r -a TARGETS <<< "$PAGES"
else
  echo "calibrate.sh: specify --page, --pages, or --all" >&2
  exit 2
fi

cd "$REPO_ROOT"
export PYTHONPATH="${PYTHONPATH:-}:$PYTHON_DIR"

for target in "${TARGETS[@]}"; do
  trimmed="${target// /}"
  [[ -z "$trimmed" ]] && continue
  echo "calibrate.sh: capturing $trimmed"
  bin/fuzzy-validator record --page "$trimmed"

  cal_dir="$TOOL_ROOT/calibrations/$trimmed"

  if [[ "$PHASE" == "visual" || "$PHASE" == "all" ]]; then
    manifest_out="$TOOL_ROOT/manifests/${trimmed}.fuzz.json"
    overlay_out="$TOOL_ROOT/reports/${trimmed}-calibration-overlay.png"
    baseline_out="$TOOL_ROOT/baselines/${trimmed}.png"

    python3 "$PYTHON_DIR/discover_fuzz.py" \
      --page-id "$trimmed" \
      --calibration-dir "$cal_dir" \
      --out "$manifest_out" \
      --overlay "$overlay_out" \
      --baseline-out "$baseline_out"
  fi

  echo "calibrate.sh: asserting asset stability for $trimmed"
  python3 "$PYTHON_DIR/calibrate_assets.py" \
    --page-id "$trimmed" \
    --calibration-dir "$cal_dir"

  if [[ "$PHASE" == "dom" || "$PHASE" == "all" ]]; then
    dom_manifest_out="$TOOL_ROOT/manifests/${trimmed}.dom-fuzz.json"
    dom_debug_out="$TOOL_ROOT/reports/${trimmed}-dom-fuzz.txt"
    dom_baseline_out="$TOOL_ROOT/baselines/${trimmed}.dom.json"

    python3 "$PYTHON_DIR/discover_dom_fuzz.py" \
      --page-id "$trimmed" \
      --calibration-dir "$cal_dir" \
      --out "$dom_manifest_out" \
      --debug-out "$dom_debug_out" \
      --baseline-out "$dom_baseline_out"
  fi
done
