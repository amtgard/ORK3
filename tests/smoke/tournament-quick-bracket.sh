#!/usr/bin/env bash
# Endpoint smoke test for Tournament Quick Bracket (spec 2026-10-02).
# Runs against the local dev stack (localhost:19080 + ork3-php8-db). The dev DB is disposable.
# Usage: tests/smoke/tournament-quick-bracket.sh [tournament_id]
set -u
BASE="http://localhost:19080/orkui/index.php?Route="
JAR="$(mktemp)"
DB() { docker exec ork3-php8-db mariadb -uroot -proot ork -N -e "$1" 2>/dev/null; }
TID="${1:-$(DB "SELECT tournament_id FROM ork_tournament ORDER BY tournament_id DESC LIMIT 1")}"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL $1 :: $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected [$3] got [$2]"; fi; }
rej()  { case "$2" in ""|0|null) bad "$1" "expected a rejection (non-zero status) got [$2]";; *) ok "$1";; esac; }
post() { curl -s -b "$JAR" -H "Origin: http://localhost" "${BASE}$1" "${@:2}"; }
J()    { php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ $d=is_array($d)&&array_key_exists($k,$d)?$d[$k]:null; } echo is_bool($d)?($d?"true":"false"):(is_null($d)?"null":(is_array($d)?json_encode($d):$d));' "$1"; }

curl -s -c "$JAR" "${BASE}Login/login" --data "username=admin&password=x" -o /dev/null
echo "Tournament $TID"
check "session warm" "$(post "TournamentAjax/tournament/$TID/reeves" | J status)" "0"

echo "Task 1: DrawSize exposed"
FIRST_DS=$(post "TournamentAjax/tournament/$TID/brackets" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $b=$d["brackets"][0]??null; echo $b===null?"nobracket":(array_key_exists("DrawSize",$b)?"present":"missing");')
if [ "$FIRST_DS" = "nobracket" ]; then echo "  (no brackets yet — DrawSize key checked in Task 2)"; else check "DrawSize key present" "$FIRST_DS" "present"; fi
COL=$(DB "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='ork' AND TABLE_NAME='ork_bracket' AND COLUMN_NAME='draw_size'")
check "draw_size column exists" "$COL" "1"

# --- Task 2 and Task 3 sections are appended below this line ---

echo "Task 2: create / place / start"
read -r M1 M2 M3 <<<"$(DB "SELECT GROUP_CONCAT(mundane_id SEPARATOR ' ') FROM (SELECT mundane_id FROM ork_mundane WHERE persona <> '' AND active = 1 AND park_id > 0 ORDER BY mundane_id LIMIT 3) x")"
QB=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=8")
BID=$(echo "$QB" | J bracketId)
check "quickbracket status" "$(echo "$QB" | J status)" "0"
check "bracket defaults" "$(DB "SELECT CONCAT_WS('|',style,method,participants,seeding,rings,best_of,status,draw_size) FROM ork_bracket WHERE bracket_id=$BID")" "Open Weapons|single|individual|manual|1|3|setup|8"
rej "bad size rejected" "$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=7" | J status)"
rej "swiss rejected" "$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=swiss&DrawSize=8" | J status)"

P1=$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=1&MundaneId=$M1")
check "place persona" "$(echo "$P1" | J status)" "0"
PID1=$(echo "$P1" | J participantId)
check "persona entrant seed+link" "$(DB "SELECT CONCAT_WS('|',p.seed,pm.mundane_id,p.participant_number>0) FROM ork_participant p JOIN ork_participant_mundane pm ON pm.participant_id=p.participant_id WHERE p.participant_id=$PID1")" "1|$M1|1"
check "persona registration row" "$(DB "SELECT COUNT(*) FROM ork_participant p JOIN ork_participant_mundane pm ON pm.participant_id=p.participant_id WHERE p.tournament_id=$TID AND p.bracket_id IS NULL AND pm.mundane_id=$M1")" "1"
check "persona home scope" "$(DB "SELECT (p.park_id=m.park_id AND p.kingdom_id=m.kingdom_id) FROM ork_participant p JOIN ork_mundane m ON m.mundane_id=$M1 WHERE p.participant_id=$PID1")" "1"
check "persona alias = persona" "$(DB "SELECT p.alias = m.persona FROM ork_participant p JOIN ork_mundane m ON m.mundane_id=$M1 WHERE p.participant_id=$PID1")" "1"

ALIAS="Jynx Furfighter QB$$"
P2=$(post "TournamentAjax/bracket/$BID/quickplace" --data-urlencode "Alias=$ALIAS" --data "TournamentId=$TID&Seed=2")
PID2=$(echo "$P2" | J participantId)
check "place alias" "$(echo "$P2" | J status)" "0"
check "alias has no player link" "$(DB "SELECT COUNT(*) FROM ork_participant_mundane WHERE participant_id=$PID2")" "0"
check "alias registration row" "$(DB "SELECT COUNT(*) FROM ork_participant WHERE tournament_id=$TID AND bracket_id IS NULL AND alias='$ALIAS'")" "1"

rej "duplicate seed rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=1&MundaneId=$M2" | J status)"
rej "duplicate player rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=3&MundaneId=$M1" | J status)"
rej "duplicate alias rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data-urlencode "Alias=$ALIAS" --data "TournamentId=$TID&Seed=3" | J status)"
rej "seed beyond size rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=9&MundaneId=$M2" | J status)"
rej "seed 0 rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=0&MundaneId=$M2" | J status)"

# Parity with the long way: same fighter added via addparticipant to a normal bracket.
LB=$(post "TournamentAjax/tournament/$TID/addbracket" --data "Style=Open%20Weapons&Method=single&Participants=individual&Rings=1&Seeding=manual&BestOf=1" | J bracketId)
LP=$(post "TournamentAjax/bracket/$LB/addparticipant" --data "TournamentId=$TID&MundaneId=$M1&Alias=x" | J participantId)
check "parity: same participant_number" "$(DB "SELECT COUNT(DISTINCT participant_number) FROM ork_participant WHERE participant_id IN ($PID1,$LP)")" "1"
check "parity: same scope+levels" "$(DB "SELECT COUNT(DISTINCT CONCAT_WS('|',park_id,kingdom_id,warrior_level,griffon_level)) FROM ork_participant WHERE participant_id IN ($PID1,$LP)")" "1"

P3=$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=5&MundaneId=$M2")
check "place seed 5" "$(echo "$P3" | J status)" "0"
# A long-way add (seed 0) must take the lowest empty seat (3) at Start.
P4=$(post "TournamentAjax/bracket/$BID/addparticipant" --data "TournamentId=$TID&MundaneId=$M3&Alias=y" | J participantId)
ST=$(post "TournamentAjax/bracket/$BID/quickstart" --data "TournamentId=$TID")
check "quickstart status" "$(echo "$ST" | J status)" "0"
check "bracket active" "$(DB "SELECT status FROM ork_bracket WHERE bracket_id=$BID")" "active"
check "seeds compacted 1..4" "$(DB "SELECT GROUP_CONCAT(participant_id ORDER BY seed) FROM ork_participant WHERE bracket_id=$BID")" "$PID1,$PID2,$P4,$(echo "$P3" | J participantId)"
check "4 fighters -> 4-slot draw" "$(DB "SELECT COUNT(*) FROM ork_match WHERE bracket_id=$BID AND round=1")" "2"
rej "place after start rejected" "$(post "TournamentAjax/bracket/$BID/quickplace" --data "TournamentId=$TID&Seed=6&MundaneId=$M2" | J status)"

# 6 of 8: byes to seeds 1 and 2 (auto-advanced).
B6=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=8" | J bracketId)
for s in 1 2 3 4 5 6; do post "TournamentAjax/bracket/$B6/quickplace" --data-urlencode "Alias=QB$$ six $s" --data "TournamentId=$TID&Seed=$s" >/dev/null; done
post "TournamentAjax/bracket/$B6/quickstart" --data "TournamentId=$TID" >/dev/null
check "6/8: two auto-resolved byes" "$(DB "SELECT COUNT(*) FROM ork_match WHERE bracket_id=$B6 AND round=1 AND auto_resolved=1")" "2"
check "6/8: byes are seeds 1,2" "$(DB "SELECT GROUP_CONCAT(p.seed ORDER BY p.seed) FROM ork_match m JOIN ork_participant p ON p.participant_id = IF(m.participant_1_id>0,m.participant_1_id,m.participant_2_id) WHERE m.bracket_id=$B6 AND m.round=1 AND m.auto_resolved=1")" "1,2"

# 5 placed in a 16: an 8-slot draw.
B16=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=16" | J bracketId)
for s in 1 2 3 4 5; do post "TournamentAjax/bracket/$B16/quickplace" --data-urlencode "Alias=QB$$ sixteen $s" --data "TournamentId=$TID&Seed=$s" >/dev/null; done
post "TournamentAjax/bracket/$B16/quickstart" --data "TournamentId=$TID" >/dev/null
check "5 in 16 -> 8-slot draw" "$(DB "SELECT COUNT(*) FROM ork_match WHERE bracket_id=$B16 AND round=1")" "4"

# Double: 2 rejected, 3 generates.
BD=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=double&DrawSize=4" | J bracketId)
for s in 1 2; do post "TournamentAjax/bracket/$BD/quickplace" --data-urlencode "Alias=QB$$ dbl $s" --data "TournamentId=$TID&Seed=$s" >/dev/null; done
rej "double with 2 rejected" "$(post "TournamentAjax/bracket/$BD/quickstart" --data "TournamentId=$TID" | J status)"
check "double still setup" "$(DB "SELECT status FROM ork_bracket WHERE bracket_id=$BD")" "setup"
post "TournamentAjax/bracket/$BD/quickplace" --data-urlencode "Alias=QB$$ dbl 3" --data "TournamentId=$TID&Seed=3" >/dev/null
check "double with 3 starts" "$(post "TournamentAjax/bracket/$BD/quickstart" --data "TournamentId=$TID" | J status)" "0"

# Refused Start must not renumber seats: double/4 with 2 fighters at seeds 3 and 4.
BR=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=double&DrawSize=4" | J bracketId)
for s in 3 4; do post "TournamentAjax/bracket/$BR/quickplace" --data-urlencode "Alias=QB$$ refuse $s" --data "TournamentId=$TID&Seed=$s" >/dev/null; done
rej "refused double start" "$(post "TournamentAjax/bracket/$BR/quickstart" --data "TournamentId=$TID" | J status)"
check "refused start leaves seeds 3,4" "$(DB "SELECT GROUP_CONCAT(seed ORDER BY seed) FROM ork_participant WHERE bracket_id=$BR")" "3,4"
check "refused start leaves setup" "$(DB "SELECT status FROM ork_bracket WHERE bracket_id=$BR")" "setup"

# Long-way regression: alias-only add and team add.
AB=$(post "TournamentAjax/tournament/$TID/addbracket" --data "Style=Open%20Weapons&Method=single&Participants=individual&Rings=1&Seeding=manual&BestOf=1" | J bracketId)
AA="QB$$ aliasonly"
AR=$(post "TournamentAjax/bracket/$AB/addparticipant" --data-urlencode "Alias=$AA" --data "TournamentId=$TID")
APID=$(echo "$AR" | J participantId)
check "alias-only add status" "$(echo "$AR" | J status)" "0"
check "alias-only entrant has no player link" "$(DB "SELECT COUNT(*) FROM ork_participant p LEFT JOIN ork_participant_mundane pm ON pm.participant_id=p.participant_id WHERE p.participant_id=$APID AND pm.mundane_id IS NULL")" "1"
check "alias-only registration row shares number" "$(DB "SELECT COUNT(*) FROM ork_participant e JOIN ork_participant r ON r.tournament_id=e.tournament_id AND r.bracket_id IS NULL AND r.alias=e.alias AND r.participant_number=e.participant_number WHERE e.participant_id=$APID AND e.participant_number>0")" "1"
TB=$(post "TournamentAjax/tournament/$TID/addbracket" --data "Style=Open%20Weapons&Method=single&Participants=team&Rings=1&Seeding=manual&BestOf=1" | J bracketId)
TR=$(post "TournamentAjax/bracket/$TB/addparticipant" --data-urlencode "Alias=QB$$ team" --data-urlencode "Members=[{\"MundaneId\":$M2},{\"MundaneId\":$M3}]" --data "TournamentId=$TID")
check "team add status" "$(echo "$TR" | J status)" "0"
check "team record with number" "$(DB "SELECT COUNT(*) FROM ork_participant_teams WHERE bracket_id=$TB AND team_number>0")" "1"
check "team has 2 members" "$(DB "SELECT COUNT(*) FROM ork_participant_team_members tm JOIN ork_participant_teams t ON t.team_id=tm.team_id WHERE t.bracket_id=$TB")" "2"

echo "Final fixes: individual-only placement, persona-only alias fallback"
# m3: a team bracket carrying draw_size is still not quick-placeable.
DB "UPDATE ork_bracket SET draw_size=8 WHERE bracket_id=$TB"
TQ=$(post "TournamentAjax/bracket/$TB/quickplace" --data-urlencode "Alias=QB$$ teamq" --data "TournamentId=$TID&Seed=2")
check "team draft quickplace rejected" "$(echo "$TQ" | J error)" "Quick placement is for individual brackets."
# m4: a player with no persona and no typed name is rejected (never their legal name).
BNP=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=4" | J bracketId)
NP=$(DB "SELECT mundane_id FROM ork_mundane WHERE (persona IS NULL OR TRIM(persona)='') AND park_id > 0 ORDER BY mundane_id LIMIT 1")
if [ -n "$NP" ]; then
  NR=$(post "TournamentAjax/bracket/$BNP/quickplace" --data "TournamentId=$TID&Seed=1&MundaneId=$NP")
  check "no-persona player rejected" "$(echo "$NR" | J error)" "Player has no persona — type a name instead."
  check "no-persona: nothing entered" "$(DB "SELECT COUNT(*) FROM ork_participant WHERE bracket_id=$BNP")" "0"
else
  echo "  (no persona-less player in this DB — m4 check skipped)"
fi
P5=$(post "TournamentAjax/bracket/$BNP/quickplace" --data "TournamentId=$TID&Seed=1&MundaneId=$M1")
check "persona fallback still fills alias" "$(DB "SELECT p.alias = m.persona FROM ork_participant p JOIN ork_mundane m ON m.mundane_id=$M1 WHERE p.participant_id=$(echo "$P5" | J participantId)")" "1"

echo "Task 3: realtime on remove/reorder"
BRT=$(post "TournamentAjax/tournament/$TID/quickbracket" --data "Method=single&DrawSize=4" | J bracketId)
RA=$(post "TournamentAjax/bracket/$BRT/quickplace" --data-urlencode "Alias=QB$$ r1" --data "TournamentId=$TID&Seed=1" | J participantId)
RB=$(post "TournamentAjax/bracket/$BRT/quickplace" --data-urlencode "Alias=QB$$ r2" --data "TournamentId=$TID&Seed=2" | J participantId)
SEQ0=$(DB "SELECT last_seq FROM ork_tournament_seq WHERE tournament_id=$TID")
post "TournamentAjax/bracket/$BRT/reorder" --data-urlencode "Order=[$RB,$RA]" --data "TournamentId=$TID&ActionId=qbtest-reorder-$$" >/dev/null
check "reorder emits event" "$(DB "SELECT type FROM ork_tournament_event WHERE tournament_id=$TID AND action_id='qbtest-reorder-$$'")" "seeds_reordered"
check "reorder swapped" "$(DB "SELECT GROUP_CONCAT(participant_id ORDER BY seed) FROM ork_participant WHERE bracket_id=$BRT")" "$RB,$RA"
post "TournamentAjax/bracket/$BRT/removeparticipant" --data "TournamentId=$TID&ParticipantId=$RA&ActionId=qbtest-remove-$$" >/dev/null
check "remove emits event" "$(DB "SELECT CONCAT_WS('|',type,bracket_id) FROM ork_tournament_event WHERE tournament_id=$TID AND action_id='qbtest-remove-$$'")" "participant_removed|$BRT"
check "removed entrant row is gone" "$(DB "SELECT COUNT(*) FROM ork_participant WHERE participant_id=$RA")" "0"
check "removed fighter stays registered" "$(DB "SELECT COUNT(*) FROM ork_participant WHERE tournament_id=$TID AND bracket_id IS NULL AND alias='QB$$ r1'")" "1"
check "seq advanced" "$(DB "SELECT last_seq > $SEQ0 FROM ork_tournament_seq WHERE tournament_id=$TID")" "1"

# Cleanup: delete every bracket this run created (registrations stay — the dev DB is disposable).
for b in $BID $LB $B6 $B16 $BD $BR $AB $TB $BRT $BNP; do post "TournamentAjax/tournament/$TID/deletebracket" --data "BracketId=$b" >/dev/null; done
# Remove registered team created in this run
TEAM_NUM=$(DB "SELECT team_number FROM ork_participant_teams WHERE tournament_id=$TID AND bracket_id IS NULL AND name='QB$$ team'" 2>/dev/null)
if [ -n "$TEAM_NUM" ] && [ "$TEAM_NUM" -gt 0 ]; then
  post "TournamentAjax/tournament/$TID/removeteam" --data "TeamNumber=$TEAM_NUM" >/dev/null
fi

rm -f "$JAR"
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
