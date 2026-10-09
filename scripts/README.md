# Dev scripts

## simulate_tournament.php — generate a test tournament

Generates a realistic, fully-played-out tournament for a kingdom or park by driving the
real tournament library API (`CreateTournament` → `AddBracket` → `AddParticipant` →
`GenerateMatches` → `PostMatchResult`), so the simulated data is identical to what the
live app produces.

What it creates:
- 8–32 random participants drawn from members who have signed in within the past year
- 3–5 brackets (always at least one single- and one double-elimination), played to completion
- A tournament dated on a random day within the past 90 days, with standings

Run it (against the Docker app container):

```bash
docker exec -i ork3-php8-app php /var/www/ork.amtgard.com/scripts/simulate_tournament.php --kingdom=17
# or scope to a park:
docker exec -i ork3-php8-app php /var/www/ork.amtgard.com/scripts/simulate_tournament.php --park=<park_id>
```

It prints the new tournament id, per-bracket standings, and a link to view the result in
the Tournament Report (`Reports/tournaments&KingdomId=<id>`).

Notes:
- Auth is handled in-process via `$_SESSION['is_authorized_mundane_id']` — no tokens are
  written and no existing rows are modified; each run only inserts a new tournament.
- To remove a simulated tournament, use `Tournament::DeleteTournament` (cascades to its
  brackets/participants/matches).
