#!/usr/bin/env bash
# ranking.sh — curl+SQL ranking assertion test for Search/Players SOAP action
# Fixtures:  persona prefix "shado" appears in 25+ kingdoms
#            park_id=1067 (Mag Mell), kingdom_id=27 (Kingdom of Polaris / KoP)
#            kingdom_id=27 has shadow personas; park 1067 also has shadow personas

set -euo pipefail

BASE="http://localhost:19080/orkservice/Search/SearchService.php"
PASS=0
FAIL=0

fail() { echo "FAIL: $1"; FAIL=$((FAIL+1)); }
pass() { echo "PASS: $1"; PASS=$((PASS+1)); }

# Helper: call Search/Players and get JSON
search() {
    local extra="$*"
    curl -sf "${BASE}?Action=Search/Players&q=shado&${extra}" 2>/dev/null
}

# ── Test 1: Action not found → expect no results (pre-implementation gate) ──
# Actually for TDD step 1 this runs before implementation, so we check for the
# error response. But this script is also run after implementation, so we gate
# on the JSON shape.

echo ""
echo "=== Test 1: Global search returns rows ==="
GLOBAL=$(curl -sf "${BASE}?Action=Search/Players&q=shado" 2>/dev/null)
if echo "$GLOBAL" | python3 -c "import sys,json; d=json.load(sys.stdin); sys.exit(0 if isinstance(d,list) and len(d)>0 else 1)" 2>/dev/null; then
    pass "global search returns rows"
else
    fail "global search did not return rows (got: $(echo $GLOBAL | head -c 200))"
fi

echo ""
echo "=== Test 2: Park-centered query — Ring values nondecreasing (0→1→2) ==="
PARK=$(curl -sf "${BASE}?Action=Search/Players&q=shado&parkId=1067&kingdomId=27" 2>/dev/null)
PARK_RING_OK=$(echo "$PARK" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
if not isinstance(rows, list) or len(rows) == 0:
    print('no_rows'); sys.exit(1)
prev = -1
for r in rows:
    ring = r.get('Ring', -99)
    if ring < prev:
        print(f'not_nondecreasing: ring={ring} after ring={prev}')
        sys.exit(1)
    prev = ring
print('ok')
" 2>/dev/null)
if [ "$PARK_RING_OK" = "ok" ]; then
    pass "park-centered ring values are nondecreasing"
else
    fail "park-centered ring ordering broken: $PARK_RING_OK"
fi

echo ""
echo "=== Test 3: Park-centered query — Ring 0 rows are in park 1067 ==="
RING0_CHECK=$(echo "$PARK" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
ring0 = [r for r in rows if r.get('Ring') == 0]
if len(ring0) == 0:
    print('no_ring0_rows'); sys.exit(0)  # may be zero if park has none — OK
bad = [r for r in ring0 if r.get('ParkId') != 1067]
if bad:
    print('ring0_wrong_park:' + str([r['ParkId'] for r in bad]))
    sys.exit(1)
print('ok')
" 2>/dev/null)
if [ "$RING0_CHECK" = "ok" ]; then
    pass "ring-0 rows belong to park 1067"
else
    fail "ring-0 park membership wrong: $RING0_CHECK"
fi

echo ""
echo "=== Test 4: Kingdom-centered query (no park) — Ring values nondecreasing ==="
KD=$(curl -sf "${BASE}?Action=Search/Players&q=shado&kingdomId=27" 2>/dev/null)
KD_RING_OK=$(echo "$KD" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
if not isinstance(rows, list) or len(rows) == 0:
    print('no_rows'); sys.exit(1)
prev = -1
for r in rows:
    ring = r.get('Ring', -99)
    if ring < prev:
        print(f'not_nondecreasing: ring={ring} after ring={prev}')
        sys.exit(1)
    prev = ring
print('ok')
" 2>/dev/null)
if [ "$KD_RING_OK" = "ok" ]; then
    pass "kingdom-centered ring values are nondecreasing"
else
    fail "kingdom-centered ring ordering broken: $KD_RING_OK"
fi

echo ""
echo "=== Test 5: Kingdom-centered query — Ring 0 rows are in kingdom 27 ==="
KD_RING0=$(echo "$KD" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
ring0 = [r for r in rows if r.get('Ring') == 0]
if len(ring0) == 0:
    print('no_ring0_rows'); sys.exit(0)
bad = [r for r in ring0 if r.get('KingdomId') != 27]
if bad:
    print('ring0_wrong_kingdom:' + str([r['KingdomId'] for r in bad]))
    sys.exit(1)
print('ok')
" 2>/dev/null)
if [ "$KD_RING0" = "ok" ]; then
    pass "kingdom-centered ring-0 rows belong to kingdom 27"
else
    fail "kingdom-centered ring-0 kingdom membership wrong: $KD_RING0"
fi

echo ""
echo "=== Test 6: restrictTo=kingdom returns ONLY rows in kingdom 27 ==="
RESTRICTED=$(curl -sf "${BASE}?Action=Search/Players&q=shado&kingdomId=27&restrictTo=kingdom" 2>/dev/null)
RESTRICT_OK=$(echo "$RESTRICTED" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
if not isinstance(rows, list) or len(rows) == 0:
    print('no_rows'); sys.exit(1)
bad = [r for r in rows if r.get('KingdomId') != 27]
if bad:
    print('found_other_kingdoms:' + str(list({r['KingdomId'] for r in bad})))
    sys.exit(1)
print('ok')
" 2>/dev/null)
if [ "$RESTRICT_OK" = "ok" ]; then
    pass "restrictTo=kingdom returns only kingdom-27 rows"
else
    fail "restrictTo=kingdom leaked other kingdoms: $RESTRICT_OK"
fi

echo ""
echo "=== Test 7: Short query (<2 chars) returns empty array ==="
SHORT=$(curl -sf "${BASE}?Action=Search/Players&q=s" 2>/dev/null)
SHORT_OK=$(echo "$SHORT" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print('ok' if isinstance(d, list) and len(d) == 0 else 'not_empty')
" 2>/dev/null)
if [ "$SHORT_OK" = "ok" ]; then
    pass "short query returns empty array"
else
    fail "short query did not return empty array (got: $(echo $SHORT | head -c 100))"
fi

echo ""
echo "=== Test 8: KD:PK abbreviation prefix — KoP: prefix scopes to kingdom 27 ==="
ABBREV=$(curl -sf "${BASE}?Action=Search/Players&q=KoP%3A+shado" 2>/dev/null)
ABBREV_OK=$(echo "$ABBREV" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
if not isinstance(rows, list) or len(rows) == 0:
    print('no_rows'); sys.exit(0)  # abbrev search may return 0 — not hard-fail
bad = [r for r in rows if r.get('KingdomId') != 27]
if bad:
    print('leaked_other_kingdoms:' + str(list({r['KingdomId'] for r in bad})))
    sys.exit(1)
print('ok')
" 2>/dev/null)
if [ "$ABBREV_OK" = "ok" ]; then
    pass "KoP: abbreviation prefix scopes to kingdom 27"
else
    fail "KoP: abbreviation prefix test: $ABBREV_OK"
fi

echo ""
echo "=== Test 9: Response shape — required fields present ==="
SHAPE=$(echo "$GLOBAL" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
if not rows: print('no_rows'); sys.exit(1)
required = ['MundaneId','Persona','KingdomId','ParkId','KAbbr','PAbbr','KingdomName','ParkName','Active','Suspended','Ring']
row = rows[0]
missing = [f for f in required if f not in row]
if missing: print('missing:' + str(missing)); sys.exit(1)
print('ok')
" 2>/dev/null)
if [ "$SHAPE" = "ok" ]; then
    pass "response shape has all required fields"
else
    fail "response shape missing fields: $SHAPE"
fi

echo ""
echo "=== Test 10: limit param respected ==="
LIM3=$(curl -sf "${BASE}?Action=Search/Players&q=shado&limit=3" 2>/dev/null)
LIM_OK=$(echo "$LIM3" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
print('ok' if isinstance(rows, list) and len(rows) <= 3 else f'too_many:{len(rows)}')
" 2>/dev/null)
if [ "$LIM_OK" = "ok" ]; then
    pass "limit=3 returns at most 3 rows"
else
    fail "limit param not respected: $LIM_OK"
fi

echo ""
echo "=== Test 11: restrictTo=park hard-filters to park 1067 ==="
RP=$(curl -sf "${BASE}?Action=Search/Players&q=shado&parkId=1067&kingdomId=27&restrictTo=park&limit=50" 2>/dev/null)
RP_OK=$(echo "$RP" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
if not isinstance(rows, list) or len(rows) == 0:
    print('no_rows'); sys.exit(1)
bad = [r for r in rows if r.get('ParkId') != 1067]
print('leaked_other_parks:' + str(list({r['ParkId'] for r in bad})) if bad else 'ok')
" 2>/dev/null)
if [ "$RP_OK" = "ok" ]; then
    pass "restrictTo=park returns only park 1067 rows"
else
    fail "restrictTo=park leaked other parks: $RP_OK"
fi

echo ""
echo "=== Test 12: two-part KD:PK prefix — 'KoP:MM shado' scopes to park 1067 ==="
TWO=$(curl -sf "${BASE}?Action=Search/Players&q=KoP%3AMM+shado&limit=50" 2>/dev/null)
TWO_OK=$(echo "$TWO" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
if not isinstance(rows, list) or len(rows) == 0:
    print('no_rows'); sys.exit(1)
bad = [r for r in rows if r.get('ParkId') != 1067]
print('leaked_other_parks:' + str(list({r['ParkId'] for r in bad})) if bad else 'ok')
" 2>/dev/null)
if [ "$TWO_OK" = "ok" ]; then
    pass "two-part KoP:MM prefix scopes to park 1067"
else
    fail "two-part KoP:MM prefix test: $TWO_OK"
fi

echo ""
echo "═══════════════════════════════════════"
echo "Results: ${PASS} passed, ${FAIL} failed"
echo "═══════════════════════════════════════"

if [ $FAIL -gt 0 ]; then
    exit 1
fi

echo "ALL RANKING ASSERTIONS PASSED"
exit 0
