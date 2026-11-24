#!/usr/bin/env bash
# filteroptions_caps.sh — HTTP check of the status-toggle caps surface (anonymous only; never logs in).
#   SearchAjax/filteroptions            → exactly {kingdoms, signedIn, canSeeBanned}; anon: 0 / 0
#   SearchAjax/filteroptions&forKingdom → {parks} only (no caps)
#   SearchAjax/universal ia=0&ii=0&ib=1 → anon gets players:[] (ib cannot exceed caps)

set -uo pipefail

BASE="http://localhost:19080/orkui/index.php?Route="
PASS=0
FAIL=0
fail() { echo "FAIL: $1"; FAIL=$((FAIL+1)); }
pass() { echo "PASS: $1"; PASS=$((PASS+1)); }

# Fresh, empty cookie jar per request: always anonymous.
get() { curl -sf --cookie /dev/null "${BASE}$1" 2>/dev/null; }

echo ""
echo "=== Test 1: anonymous kingdom list carries caps 0/0 and only the expected keys ==="
KL=$(get "SearchAjax/filteroptions")
R=$(echo "$KL" | python3 -c "
import sys, json
d = json.load(sys.stdin)
keys = sorted(d.keys())
if keys != ['canSeeBanned', 'kingdoms', 'signedIn']: print('keys', keys); sys.exit(1)
if d['signedIn'] != 0 or d['canSeeBanned'] != 0: print('caps', d['signedIn'], d['canSeeBanned']); sys.exit(1)
if not isinstance(d['kingdoms'], list) or not d['kingdoms']: print('no kingdoms'); sys.exit(1)
print('ok')
" 2>&1)
[ "$R" = "ok" ] && pass "anon filteroptions: keys + signedIn:0 canSeeBanned:0" || fail "anon filteroptions: $R"

echo ""
echo "=== Test 2: park list (forKingdom) carries no caps ==="
KID=$(echo "$KL" | python3 -c "import sys,json; print(json.load(sys.stdin)['kingdoms'][0]['id'])" 2>/dev/null || echo 0)
PL=$(get "SearchAjax/filteroptions&forKingdom=${KID}")
R=$(echo "$PL" | python3 -c "
import sys, json
d = json.load(sys.stdin)
keys = sorted(d.keys())
if keys != ['parks']: print('keys', keys); sys.exit(1)
if not isinstance(d['parks'], list): print('parks not list'); sys.exit(1)
print('ok')
" 2>&1)
[ "$R" = "ok" ] && pass "forKingdom=${KID}: {parks} only" || fail "forKingdom=${KID}: $R"

echo ""
echo "=== Test 3: anonymous universal with only banned on returns no players ==="
UN=$(get "SearchAjax/universal&q=ann&kid=0&pid=0&ia=0&ii=0&ib=1")
R=$(echo "$UN" | python3 -c "
import sys, json
d = json.load(sys.stdin)
p = d.get('players')
if p != []: print('players', (p or [])[:2]); sys.exit(1)
print('ok')
" 2>&1)
[ "$R" = "ok" ] && pass "anon universal ia=0&ii=0&ib=1 → players:[]" || fail "anon universal: $R"

# Control: the same query with defaults does find players (so Test 3 isn't vacuous).
UN2=$(get "SearchAjax/universal&q=ann&kid=0&pid=0")
R=$(echo "$UN2" | python3 -c "import sys,json; d=json.load(sys.stdin); print('ok' if d.get('players') else 'empty')" 2>&1)
[ "$R" = "ok" ] && pass "control: anon universal default finds players" || fail "control: $R"

echo ""
echo "Results: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
