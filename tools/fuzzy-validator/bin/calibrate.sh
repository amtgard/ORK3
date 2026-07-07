#!/usr/bin/env bash
# Fuzzy Validator — pixel calibration (capture + fuzz discovery)
set -euo pipefail

TOOL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$TOOL_ROOT/../.." && pwd)"
PYTHON_DIR="$TOOL_ROOT/python"

usage() {
  cat <<'EOF'
Usage:
  calibrate.sh --page PAGE_ID
  calibrate.sh --pages id1,id2
  calibrate.sh --all

Runs Playwright capture (×N) then discover_fuzz.py for each page.
EOF
}

PAGE=""
PAGES=""
ALL=false

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
  manifest_out="$TOOL_ROOT/manifests/${trimmed}.fuzz.json"
  overlay_out="$TOOL_ROOT/reports/${trimmed}-calibration-overlay.png"
  baseline_out="$TOOL_ROOT/baselines/${trimmed}.png"

  python3 "$PYTHON_DIR/discover_fuzz.py" \
    --page-id "$trimmed" \
    --calibration-dir "$cal_dir" \
    --out "$manifest_out" \
    --overlay "$overlay_out" \
    --baseline-out "$baseline_out"
done
