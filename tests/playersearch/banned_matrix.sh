#!/usr/bin/env bash
# banned_matrix.sh — authenticated matrix test for SearchAjax/players (RankedPlayers v2).
# Validates the three-tier model (active → inactive → banned), auth-gated banned visibility,
# one-level-up banned scoping, pagination, excludeIds, scope params, and SQLi hardening.
#
# Fixtures (local ork DB):
#   q="blackwolf"  → 14 total matches (fits one page); 2 banned:
#   mundane 58269  "Sir Blackwolf Wyngarde" suspended  kingdom 1   park 5
#   mundane 18367  "Ausric Blackwolf"       suspended  kingdom 31  park 79
#   active/inactive "...Blackwolf..." in kingdoms 1,5,11,12,19,27
#   q="loki" → 150 non-banned matches (used for pagination + inactive-tier checks)
# Accounts (login bypass accepts any password):
#   admin  = global ORK admin (mundane 1)
#   Neiva  = kingdom-1 CREATE officer (mundane 119351)
#   crom   = plain logged-in user, no authority (mundane 2)

set -uo pipefail
BASE="http://localhost:19080/orkui/index.php?Route="
PASS=0; FAIL=0
pass(){ echo "PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "FAIL: $1"; FAIL=$((FAIL+1)); }

login(){ # $1=user  $2=jar
  curl -s -c "$2" -b "$2" "${BASE}Login/login" --data "username=$1&password=x" -o /dev/null
}
players(){ # $1=jar  $2=querystring → prints JSON
  curl -s -b "$1" "${BASE}SearchAjax/players&$2"
}

AJAR=/tmp/ck_admin.txt; OJAR=/tmp/ck_officer.txt; UJAR=/tmp/ck_user.txt
rm -f "$AJAR" "$OJAR" "$UJAR"
login admin  "$AJAR"
login Neiva  "$OJAR"
login crom   "$UJAR"

# JSON helpers (python over stdin)
has_id(){ python3 -c "import sys,json; d=json.load(sys.stdin); r=d.get('rows',d) if isinstance(d,dict) else d; print('Y' if any(x['MundaneId']==$1 for x in r) else 'N')"; }
tiers_sorted(){ python3 -c "import sys,json; d=json.load(sys.stdin); r=d.get('rows',d); t=[x['Tier'] for x in r]; print('Y' if t==sorted(t) else 'N:'+str(t))"; }
ring_within_tier(){ python3 -c "
import sys,json
d=json.load(sys.stdin); r=d.get('rows',d); prev_t,prev_r=-1,-1; ok=True
for x in r:
    if x['Tier']==prev_t and x['Ring']<prev_r: ok=False; break
    prev_t,prev_r=x['Tier'],x['Ring']
print('Y' if ok else 'N')"; }
count(){ python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('rows',d)))"; }
hasmore(){ python3 -c "import sys,json; d=json.load(sys.stdin); print('Y' if d.get('hasMore') else 'N')"; }
all_kingdom(){ python3 -c "import sys,json; d=json.load(sys.stdin); r=d.get('rows',d); print('Y' if all(x['KingdomId']==$1 for x in r) else 'N')"; }
no_banned(){ python3 -c "import sys,json; d=json.load(sys.stdin); r=d.get('rows',d); print('Y' if all(x['Banned']==0 for x in r) else 'N')"; }
ids(){ python3 -c "import sys,json; d=json.load(sys.stdin); r=d.get('rows',d); print(' '.join(str(x['MundaneId']) for x in r))"; }

echo "=== 1. Plain user, global q=blackwolf → NO banned ==="
R=$(players "$UJAR" "q=blackwolf&limit=100")
[ "$(echo "$R" | no_banned)" = "Y" ] && pass "plain user sees no banned" || fail "plain user saw banned: $(echo "$R" | ids | head -c 200)"

echo "=== 2. Admin, global q=blackwolf → both bans visible amtgard-wide (58269 + 18367) ==="
R=$(players "$AJAR" "q=blackwolf&limit=100")
[ "$(echo "$R" | has_id 58269)" = "Y" ] && [ "$(echo "$R" | has_id 18367)" = "Y" ] && pass "admin sees both bans globally" || fail "admin missing a global ban (58269=$(echo "$R"|has_id 58269) 18367=$(echo "$R"|has_id 18367))"

echo "=== 3. Plain user, park-centered parkId=5 q=blackwolf → still NO banned (not officer) ==="
R=$(players "$UJAR" "q=blackwolf&parkId=5&kingdomId=1&limit=100")
[ "$(echo "$R" | no_banned)" = "Y" ] && pass "plain user park-centered no banned" || fail "plain user saw banned on park surface"

echo "=== 4. Admin, PARK surface parkId=5/kingdomId=1 → ONLY kingdom-1 ban (58269 yes, 18367 NO) ==="
R=$(players "$AJAR" "q=blackwolf&parkId=5&kingdomId=1&limit=100")
[ "$(echo "$R" | has_id 58269)" = "Y" ] && [ "$(echo "$R" | has_id 18367)" = "N" ] && pass "park surface caps banned to kingdom family (one level up)" || fail "park-banned-scope wrong (58269=$(echo "$R"|has_id 58269) 18367=$(echo "$R"|has_id 18367))"

echo "=== 5. Admin, KINGDOM surface kingdomId=1 (no park) → amtgard-wide bans (58269 + 18367) ==="
R=$(players "$AJAR" "q=blackwolf&kingdomId=1&limit=100")
[ "$(echo "$R" | has_id 58269)" = "Y" ] && [ "$(echo "$R" | has_id 18367)" = "Y" ] && pass "kingdom surface shows amtgard-wide bans" || fail "kingdom-banned-scope wrong (58269=$(echo "$R"|has_id 58269) 18367=$(echo "$R"|has_id 18367))"

echo "=== 5b. Kingdom-1 officer, PARK surface parkId=5 → kingdom-1 ban only (58269 yes, 18367 no) ==="
R=$(players "$OJAR" "q=blackwolf&parkId=5&kingdomId=1&limit=100")
[ "$(echo "$R" | has_id 58269)" = "Y" ] && [ "$(echo "$R" | has_id 18367)" = "N" ] && pass "officer park surface caps banned to kingdom" || fail "officer park-banned-scope wrong (58269=$(echo "$R"|has_id 58269) 18367=$(echo "$R"|has_id 18367))"

echo "=== 6. Tiers nondecreasing + ring nondecreasing within tier (admin park surface) ==="
R=$(players "$AJAR" "q=blackwolf&parkId=5&kingdomId=1&limit=100")
[ "$(echo "$R" | tiers_sorted)" = "Y" ] && pass "tiers nondecreasing" || fail "tiers out of order: $(echo "$R" | tiers_sorted)"
[ "$(echo "$R" | ring_within_tier)" = "Y" ] && pass "ring nondecreasing within tier" || fail "ring order broken within tier"

echo "=== 7. Pagination: limit=5 hasMore, offset=5 no overlap ==="
P0=$(players "$AJAR" "q=loki&limit=5&offset=0")
P1=$(players "$AJAR" "q=loki&limit=5&offset=5")
[ "$(echo "$P0" | hasmore)" = "Y" ] && pass "page 0 hasMore=true" || fail "page 0 hasMore not set"
OVERLAP=$(python3 -c "import sys,json;a=set(json.loads('''$P0''')['rows'][i]['MundaneId'] for i in range(len(json.loads('''$P0''')['rows'])));b=set(x['MundaneId'] for x in json.loads('''$P1''')['rows']);print(len(a&b))" 2>/dev/null || echo ERR)
[ "$OVERLAP" = "0" ] && pass "no page overlap (stable tiebreak)" || fail "page overlap=$OVERLAP"

echo "=== 8. excludeIds drops a row ==="
FIRST=$(players "$AJAR" "q=loki&limit=10" | ids | awk '{print $1}')
R=$(players "$AJAR" "q=loki&limit=10&excludeIds=$FIRST")
[ "$(echo "$R" | has_id "$FIRST")" = "N" ] && pass "excludeIds=$FIRST removed" || fail "excludeIds did not remove $FIRST"

echo "=== 9. restrictTo=kingdom kingdomId=4 → all rows kingdom 4 ==="
R=$(players "$AJAR" "q=loki&kingdomId=4&restrictTo=kingdom&limit=50")
[ "$(echo "$R" | all_kingdom 4)" = "Y" ] && pass "restrictTo=kingdom honored" || fail "restrictTo leaked other kingdoms"

echo "=== 10. excludeKingdomId=1 → no kingdom-1 rows (move-INTO context) ==="
R=$(players "$AJAR" "q=loki&excludeKingdomId=1&limit=100")
NK1=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);print('Y' if all(x['KingdomId']!=1 for x in d['rows']) else 'N')")
[ "$NK1" = "Y" ] && pass "excludeKingdomId removes own-kingdom members" || fail "excludeKingdomId leaked kingdom 1"

echo "=== 11. SQLi: q with quote+OR returns valid JSON, no 500, not a full dump ==="
CODE=$(curl -s -b "$AJAR" -o /tmp/sqli.json -w "%{http_code}" "${BASE}SearchAjax/players&limit=5&q=$(python3 -c "import urllib.parse;print(urllib.parse.quote(\"loki' OR '1'='1\"))")")
VALID=$(python3 -c "import json;d=json.load(open('/tmp/sqli.json'));print('Y' if isinstance(d,dict) and 'rows' in d else 'N')" 2>/dev/null || echo N)
[ "$CODE" = "200" ] && [ "$VALID" = "Y" ] && pass "SQLi payload safely handled (http $CODE)" || fail "SQLi payload broke endpoint (http $CODE valid=$VALID)"

echo "=== 12. Inactive present as last-resort tier (plain user, q=loki) ==="
R=$(players "$UJAR" "q=loki&limit=100")
HASINACT=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);print('Y' if any(x['Tier']==1 for x in d['rows']) else 'N')")
[ "$HASINACT" = "Y" ] && pass "inactive players included as tier 1" || fail "no inactive tier present (expected some)"

echo ""
echo "RESULTS: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
