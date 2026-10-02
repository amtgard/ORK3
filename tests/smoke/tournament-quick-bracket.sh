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

rm -f "$JAR"
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
