<?php

class Tournament extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->Bracket     = new yapo($this->db, DB_PREFIX . 'bracket');
        $this->Glicko2     = new yapo($this->db, DB_PREFIX . 'glicko2');
        $this->Match       = new yapo($this->db, DB_PREFIX . 'match');
        $this->Participant = new yapo($this->db, DB_PREFIX . 'participant');
        $this->Player      = new yapo($this->db, DB_PREFIX . 'participant_mundane');
        $this->Tournament  = new yapo($this->db, DB_PREFIX . 'tournament');
    }

    public function CreateTournament($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            return NoAuthorization();
        }

        // Authorize AND save these exact int values: '7.5' is valid_id() yet casts to 7
        // here while MariaDB (non-strict) would round the raw string to 8 on save.
        $kingdom_id = (int)($request['KingdomId'] ?? 0);
        $park_id    = (int)($request['ParkId'] ?? 0);
        $ecd_id     = (int)($request['EventCalendarDetailId'] ?? 0);

        // A park attached alongside a kingdom must belong to that kingdom, so authority
        // over one scope can't be used to file the tournament under a foreign one.
        // Park-scoped tournaments always store the park's own kingdom (derived), so every
        // create path files the same park tournament in the same shape.
        if (valid_id($park_id)) {
            $park = new yapo($this->db, DB_PREFIX . 'park');
            $park->park_id = $park_id;
            if (!$park->find() || (valid_id($kingdom_id) && (int)$park->kingdom_id !== $kingdom_id)) {
                return InvalidParameter();
            }
            $kingdom_id = (int)$park->kingdom_id;
        }

        $event_id = 0;
        if (valid_id($ecd_id)) {
            $detail = new yapo($this->db, DB_PREFIX . 'event_calendardetail');
            $detail->event_calendardetail_id = $ecd_id;
            if (!$detail->find()) {
                return InvalidParameter();
            }
            $event_id = (int)$detail->event_id;
        }

        // Grant when the kingdom OR the park authorizes. Event authority is deliberately not
        // a create path: personal events need no authority to make, so it would let any
        // logged-in user create tournaments. (check_auth still honors it for management.)
        if (!$this->has_scope_edit($mundane_id, $kingdom_id, $park_id, 0)) {
            return NoAuthorization();
        }

        $name = trim(strip_tags((string)($request['Name'] ?? '')));
        if ($name === '') {
            return InvalidParameter('Tournament name is required.');
        }

        $this->Tournament->clear();
        $this->Tournament->kingdom_id             = $kingdom_id;
        $this->Tournament->park_id                = $park_id;
        $this->Tournament->event_calendardetail_id = $ecd_id;
        $this->Tournament->event_id = $event_id;
        $this->Tournament->name        = $name;
        $this->Tournament->description = strip_tags($request['Description'], "<p><br><ul><li><b><i>");
        $this->Tournament->url         = $request['Url'];
        $this->Tournament->date_time   = $request['When'];
        $this->Tournament->save();
        $this->bustTournamentReportCache();

        return Success($this->Tournament->tournament_id);
    }


    public function UpdateTournament($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        // check_auth loaded the tournament into $this->Tournament and only authorized
        // the CURRENT scope. Capture the stored scope before overwriting so that any
        // change of park/kingdom/event requires authority over the DESTINATION too.
        $mundane_id  = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $cur_park    = (int)$this->Tournament->park_id;
        $cur_kingdom = (int)$this->Tournament->kingdom_id;
        $cur_ecd     = (int)$this->Tournament->event_calendardetail_id;

        // Capture pre-mutation scalar values so the audit event can summarize what changed.
        $old_name = (string)$this->Tournament->name;
        $old_desc = (string)$this->Tournament->description;
        $old_url  = (string)$this->Tournament->url;
        $old_when = (string)$this->Tournament->date_time;

        $new_park    = (int)($request['ParkId'] ?? 0);
        $new_kingdom = (int)($request['KingdomId'] ?? 0);
        $new_ecd     = (int)($request['EventCalendarDetailId'] ?? 0);

        // Park-scoped tournaments store the park's own kingdom (derived, as CreateTournament
        // does). Legacy rows may still hold kingdom_id=0 for a park tournament, so compare
        // against the stored park's kingdom when deciding whether the kingdom really moves.
        $eff_cur_kingdom = $cur_kingdom;
        if (valid_id($new_park)) {
            $park = new yapo($this->db, DB_PREFIX . 'park');
            $park->park_id = $new_park;
            if (!$park->find()) {
                return InvalidParameter('Park not found');
            }
            $new_kingdom = (int)$park->kingdom_id;
            if (!valid_id($cur_kingdom) && valid_id($cur_park)) {
                if ($cur_park === $new_park) {
                    $eff_cur_kingdom = $new_kingdom;
                } else {
                    $cpark = new yapo($this->db, DB_PREFIX . 'park');
                    $cpark->park_id = $cur_park;
                    if ($cpark->find()) {
                        $eff_cur_kingdom = (int)$cpark->kingdom_id;
                    }
                }
            }
        }

        // Changing or clearing a kingdom/park requires authority over BOTH the scope being
        // left (so a holder of another scope can't strip its owners out) and the destination.
        // A kingdom derived from the (new or unchanged) park that matches the effective
        // current kingdom is not a move: the park checks below govern that edit.
        if ($new_kingdom !== $eff_cur_kingdom) {
            foreach ([$eff_cur_kingdom, $new_kingdom] as $k) {
                if (valid_id($k) && !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $k, AUTH_EDIT)) {
                    return NoAuthorization();
                }
            }
        } elseif (valid_id($cur_park) && !valid_id($new_park) && valid_id($new_kingdom)
            && !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $new_kingdom, AUTH_EDIT)) {
            // Dropping the park re-files the tournament under its (derived) kingdom alone,
            // which still takes kingdom authority, as it did when park rows stored kingdom 0.
            return NoAuthorization();
        }
        if ($new_park !== $cur_park) {
            foreach ([$cur_park, $new_park] as $p) {
                if (valid_id($p) && !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $p, AUTH_EDIT)) {
                    return NoAuthorization();
                }
            }
        }

        $name = trim(strip_tags((string)($request['Name'] ?? '')));
        if ($name === '') {
            return InvalidParameter('Tournament name is required.');
        }
        // A bare Y-m-d (the edit modal's date-only picker) keeps the stored time of day
        // instead of silently resetting it to midnight.
        $when = (string)($request['When'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $when)) {
            $when .= ' ' . (preg_match('/\d{2}:\d{2}:\d{2}$/', $old_when, $tm) ? $tm[0] : '00:00:00');
        }

        $this->Tournament->name        = $name;
        $this->Tournament->description = strip_tags($request['Description'], '<p><br><ul><li><b><i>');
        $this->Tournament->url         = $request['Url'];
        $this->Tournament->date_time   = $when;
        $this->Tournament->park_id     = $new_park;
        $this->Tournament->kingdom_id  = $new_kingdom;

        $this->Tournament->event_calendardetail_id = $new_ecd;
        $this->Tournament->event_id = 0;
        if (valid_id($new_ecd)) {
            $detail = new yapo($this->db, DB_PREFIX . 'event_calendardetail');
            $detail->event_calendardetail_id = $new_ecd;
            if ($detail->find()) {
                $this->Tournament->event_id = $detail->event_id;
                // Moving to a different event requires authority over the destination event.
                if ($new_ecd !== $cur_ecd
                    && !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, (int)$detail->event_id, AUTH_EDIT)) {
                    return NoAuthorization();
                }
            } else {
                return InvalidParameter('Event not found');
            }
        }

        // Summarize which fields actually changed for the change-log payload.
        $changed = [];
        if ($old_name !== (string)$this->Tournament->name) {
            $changed[] = 'name';
        }
        if ($old_desc !== (string)$this->Tournament->description) {
            $changed[] = 'description';
        }
        if ($old_url  !== (string)$this->Tournament->url) {
            $changed[] = 'url';
        }
        if ($old_when !== (string)$this->Tournament->date_time) {
            $changed[] = 'when';
        }
        if ($cur_park    !== $new_park) {
            $changed[] = 'park';
        }
        if ($cur_kingdom !== $new_kingdom) {
            $changed[] = 'kingdom';
        }
        if ($cur_ecd     !== $new_ecd) {
            $changed[] = 'event';
        }

        $tournament_id = (int)$this->Tournament->tournament_id;
        $actor_id      = (int)$mundane_id;
        $action_id     = substr(trim($request['ActionId'] ?? ''), 0, 36);

        // tnEmitEvent's increment-then-read of the seq cursor must run inside a
        // transaction so the cursor bump commits atomically with the save.
        $this->db->query('START TRANSACTION');
        try {
            $this->Tournament->save();
            $seq = $this->tnEmitEvent($tournament_id, 0, 'tournament_updated', [
                'tournament_id' => $tournament_id,
                'changed'       => $changed,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tournament_id, $seq);
        return Success($this->Tournament->tournament_id);
    }

    /**
     * Builds a WHERE clause fragment filtering by TournamentId and/or BracketId.
     * @param string $alias Table alias prefix (e.g. 'p' → p.tournament_id)
     */
    private function buildFilterWhere(array $request, string $alias): string
    {
        $w = '';
        if (valid_id($request['TournamentId'] ?? 0)) {
            $w .= " AND {$alias}.tournament_id = " . (int)$request['TournamentId'];
        }
        if (valid_id($request['BracketId']    ?? 0)) {
            $w .= " AND {$alias}.bracket_id = " . (int)$request['BracketId'];
        } elseif (valid_id($request['TournamentId'] ?? 0)) {
            // Exclude tournament-level registration rows (bracket_id IS NULL) from
            // participant/match queries. Use GetRegistrants() for the roster of
            // registered-but-unassigned participants.
            $w .= " AND {$alias}.bracket_id IS NOT NULL";
        }
        return $w;
    }

    /**
     * Returns [winner_id, loser_id] from a match result string.
     * For ties, both are 0.
     */
    private function resolveWinnerLoser(string $result, int $p1_id, int $p2_id): array
    {
        // Participant 1 wins.
        if ($result === '1-wins' || $result === '2-forfeits' || $result === '2-is-disqualified') {
            return [$p1_id, $p2_id];
        }
        // Participant 2 wins. Legacy 'forfeit'/'disqualified' (no side) mean participant 1
        // forfeited / was disqualified, so participant 2 takes the win.
        if ($result === '2-wins' || $result === 'forfeit' || $result === 'disqualified'
            || $result === '1-forfeits' || $result === '1-is-disqualified') {
            return [$p2_id, $p1_id];
        }
        return [0, 0]; // tie or unknown
    }

    public function CheckAuth($request)
    {
        return $this->check_auth($request) ? Success(null) : NoAuthorization();
    }

    private function check_auth(array $request)
    {
        $Token        = $request['Token'] ?? '';
        $TournamentId = $request['TournamentId'] ?? null;
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($Token);
        if (!valid_id($mundane_id)) {
            return false;
        }

        $this->Tournament->clear();
        $this->Tournament->tournament_id = $TournamentId;
        if (!$this->Tournament->find()) {
            return false;
        }

        if ($this->has_scope_edit($mundane_id, $this->Tournament->kingdom_id, $this->Tournament->park_id, $this->Tournament->event_id)) {
            return true;
        }

        // Organizer reeves get full manage rights, scoped to this tournament only.
        return $this->get_reeve_role($mundane_id, (int)$this->Tournament->tournament_id) === 'organizer';
    }

    /**
     * True when the mundane holds AUTH_EDIT over ANY of the given scopes
     * (kingdom OR park OR event). Invalid ids are skipped. Event authority (which
     * includes a personal event's owner) only counts when every kingdom/park the
     * tournament is filed under is the event's own, so attaching an event never
     * hands its holder a kingdom's or park's tournament.
     */
    private function has_scope_edit($mundane_id, $kingdom_id, $park_id, $event_id)
    {
        $auth       = Ork3::$Lib->authorization;
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;
        $event_id   = (int)$event_id;
        if (valid_id($kingdom_id) && $auth->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
            return true;
        }
        if (valid_id($park_id) && $auth->HasAuthority($mundane_id, AUTH_PARK, $park_id, AUTH_EDIT)) {
            return true;
        }
        if (!valid_id($event_id)) {
            return false;
        }
        if (valid_id($kingdom_id) || valid_id($park_id)) {
            $event = new yapo($this->db, DB_PREFIX . 'event');
            $event->event_id = $event_id;
            if (!$event->find()
                || (valid_id($kingdom_id) && (int)$event->kingdom_id !== $kingdom_id)
                || (valid_id($park_id) && (int)$event->park_id !== $park_id)) {
                return false;
            }
        }
        return $auth->HasAuthority($mundane_id, AUTH_EVENT, $event_id, AUTH_EDIT);
    }

    /**
     * Confirms a bracket belongs to the (already-authed) tournament. Guards bracket-id-only
     * mutators against cross-tournament IDOR, since check_auth only authorizes the request
     * TournamentId — not the tournament the bracket row actually belongs to.
     */
    private function bracketBelongsTo(int $bid, int $tid): bool
    {
        if ($bid <= 0 || $tid <= 0) {
            return false;
        }
        $r = $this->db->query("SELECT tournament_id FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bid LIMIT 1");
        return $r && $r->next() && (int)$r->tournament_id === $tid;
    }

    /**
     * Returns the reeve role ('organizer' | 'bracket_runner') the given mundane holds
     * for the given tournament, or null if they are not a reeve.
     */
    private function get_reeve_role($mundane_id, $tournament_id)
    {
        $mundane_id    = (int)$mundane_id;
        $tournament_id = (int)$tournament_id;
        if ($mundane_id <= 0 || $tournament_id <= 0) {
            return null;
        }

        $r = $this->db->query(
            "SELECT role FROM " . DB_PREFIX . "tournament_reeve WHERE tournament_id = :tid AND mundane_id = :mid LIMIT 1",
            [':tid' => $tournament_id, ':mid' => $mundane_id]
        );
        if ($r && $r->size() > 0 && $r->next()) {
            return $r->role;
        }
        return null;
    }

    /**
     * Result-entry auth gate. True when check_auth() passes (edit auth OR organizer
     * reeve) OR the resolved mundane is a 'bracket_runner' reeve for this tournament.
     * Used by PostMatchResult / ResetMatch / RecordIronmanWin / SavePointScore / AddPointsRound only —
     * bracket runners may record results (including appending an empty points round, which is
     * non-destructive live scoring) but cannot edit brackets, participants, or reeves.
     */
    private function can_run_brackets(array $request)
    {
        if ($this->check_auth($request)) {
            return true;
        }

        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if (!valid_id($mundane_id)) {
            return false;
        }

        // Resolve the tournament id: prefer the request, else derive from the match.
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($tournament_id) && valid_id($request['MatchId'] ?? 0)) {
            $mid = (int)$request['MatchId'];
            $mr  = $this->db->query("SELECT tournament_id FROM " . DB_PREFIX . "match WHERE match_id = $mid LIMIT 1");
            if ($mr && $mr->size() > 0 && $mr->next()) {
                $tournament_id = (int)$mr->tournament_id;
            }
        }
        if (!valid_id($tournament_id)) {
            return false;
        }

        return $this->get_reeve_role($mundane_id, $tournament_id) === 'bracket_runner';
    }


    private function bustTournamentReportCache()
    {
        // Report::TournamentReport folds this generation into every cache key, so rotating it
        // invalidates every request shape at once (legacy Tournament/create list, Event page,
        // Kingdom/Principality/Park profiles, single-tournament profile) regardless of which
        // kingdom/park/event the tournament belongs to — no key shapes to keep in sync.
        Ork3::$Lib->ghettocache->counterSet('tournaments.gen', uniqid(), 2592000);
    }

    public function AddBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        if (valid_id($request['CopyOfId'])) {
            $copy_id       = (int)$request['CopyOfId'];
            $tournament_id = (int)($request['TournamentId'] ?? 0);
            if (!valid_id($tournament_id)) {
                return InvalidParameter('TournamentId required');
            }
            // Suffix the note with ', copy' (the UI already wraps notes in parens) so the copy is distinguishable from its source;
            // LEFT(…, 249) keeps the suffix inside style_note's varchar(255); an existing copy isn't re-suffixed.
            $sql = "INSERT INTO " . DB_PREFIX . "bracket (tournament_id, style, style_note, method, rings, participants, seeding, duration_minutes, best_of, point_rounds, point_mode, point_scale)
						SELECT tournament_id, style, CASE WHEN TRIM(style_note) = '' THEN 'copy' WHEN TRIM(style_note) = 'copy' OR TRIM(style_note) LIKE '%, copy' THEN TRIM(style_note) ELSE CONCAT(LEFT(TRIM(style_note), 249), ', copy') END, method, rings, participants, seeding, duration_minutes, best_of, point_rounds, point_mode, point_scale
						FROM " . DB_PREFIX . "bracket WHERE bracket_id = $copy_id AND tournament_id = $tournament_id";
            $this->db->query($sql);
            $bracket_id = $this->db->GetLastInsertId();
            if (!valid_id($bracket_id)) {
                return InvalidParameter('Source bracket not found in this tournament');
            }

            // Fetch old participant IDs in order (before copy)
            $old_pids = [];
            $opr = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE bracket_id = $copy_id ORDER BY participant_id ASC");
            if ($opr) {
                while ($opr->next()) {
                    $old_pids[] = (int)$opr->participant_id;
                }
            }

            $this->db->query('START TRANSACTION');
            try {
                $sql = "INSERT INTO " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, unit_id, park_id, kingdom_id, participant_number, seed)
							SELECT tournament_id, $bracket_id, alias, unit_id, park_id, kingdom_id, participant_number, seed
							FROM " . DB_PREFIX . "participant WHERE bracket_id = $copy_id ORDER BY participant_id ASC";
                $this->db->query($sql);

                // Fetch new participant IDs in order (same insertion order as old)
                $new_pids = [];
                $npr = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE bracket_id = $bracket_id ORDER BY participant_id ASC");
                if ($npr) {
                    while ($npr->next()) {
                        $new_pids[] = (int)$npr->participant_id;
                    }
                }

                // Build explicit old→new participant_id mapping (safe for duplicate aliases)
                $pid_map = [];
                for ($i = 0; $i < count($old_pids) && $i < count($new_pids); $i++) {
                    $pid_map[$old_pids[$i]] = $new_pids[$i];
                }

                // Copy participant_mundane links using explicit mapping
                foreach ($pid_map as $old_pid => $new_pid) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "participant_mundane (participant_id, mundane_id, tournament_id, bracket_id)
						SELECT $new_pid, mundane_id, tournament_id, $bracket_id
						FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $old_pid");
                }

                // Copy team records using explicit mapping
                foreach ($pid_map as $old_pid => $new_pid) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "participant_teams (tournament_id, bracket_id, participant_id, name)
						SELECT tournament_id, $bracket_id, $new_pid, name
						FROM " . DB_PREFIX . "participant_teams WHERE participant_id = $old_pid AND bracket_id = $copy_id");
                }

                // Copy team members: map old team_ids to new team_ids via participant_id
                // (positional zipping is fragile; join on the mapped participant_id instead).
                $old_teams = $this->db->query("SELECT team_id, participant_id FROM " . DB_PREFIX . "participant_teams WHERE bracket_id = $copy_id ORDER BY team_id");
                $new_teams = $this->db->query("SELECT team_id, participant_id FROM " . DB_PREFIX . "participant_teams WHERE bracket_id = $bracket_id ORDER BY team_id");
                $team_map = [];
                if ($old_teams && $new_teams) {
                    $oldTeamByPid = [];
                    while ($old_teams->next()) {
                        $oldTeamByPid[(int)$old_teams->participant_id] = (int)$old_teams->team_id;
                    }
                    $newTeamByPid = [];
                    while ($new_teams->next()) {
                        $newTeamByPid[(int)$new_teams->participant_id] = (int)$new_teams->team_id;
                    }
                    foreach ($oldTeamByPid as $old_participant_id => $old_team_id) {
                        $mapped_pid = $pid_map[$old_participant_id] ?? 0;
                        if (isset($newTeamByPid[$mapped_pid])) {
                            $team_map[$old_team_id] = $newTeamByPid[$mapped_pid];
                        }
                    }
                }
                foreach ($team_map as $old_tid => $new_tid) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "participant_team_members (team_id, mundane_id, tournament_id)
						SELECT $new_tid, mundane_id, tournament_id
						FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $old_tid");
                }
                $this->db->query('COMMIT');
            } catch (\Throwable $e) {
                $this->db->query('ROLLBACK');
                throw $e;
            }

            $this->bustTournamentReportCache();
            return Success($bracket_id);
        } else {
            $bad = self::invalid_bracket_enum($request, true);
            if ($bad !== null) {
                return InvalidParameter(null, "Invalid $bad.");
            }
            // Gate: Ironman brackets do not support team participants.
            if (($request['Method'] ?? '') === 'ironman' && ($request['Participants'] ?? '') === 'team') {
                return InvalidParameter(null, 'Team mode is not supported for Ironman brackets.');
            }
            if (($request['Method'] ?? '') === 'points') {
                $err = $this->validate_points_config($request, true);
                if ($err !== null) {
                    return InvalidParameter(null, $err);
                }
            }
            $this->Bracket->clear();
            $this->Bracket->tournament_id = $request['TournamentId'];
            $this->Bracket->style         = $request['Style'];
            $this->Bracket->style_note    = $request['StyleNote'];
            $this->Bracket->method        = $request['Method'];
            $this->Bracket->rings         = (int)$request['Rings'];
            $this->Bracket->participants  = $request['Participants'];
            $this->Bracket->seeding          = $request['Seeding'];
            $this->Bracket->duration_minutes = max(0, (int)($request['DurationMinutes'] ?? 0));
            $this->Bracket->best_of          = self::normalize_best_of($request['BestOf'] ?? 1);
            if (($request['Method'] ?? '') === 'points') {
                $this->Bracket->point_rounds = (int)$request['PointRounds'];
                $this->Bracket->point_mode   = $request['PointMode'];
                $this->Bracket->point_scale  = ($request['PointMode'] === 'fixed')
                    ? $this->normalize_point_scale($request['PointScale'] ?? '')
                    : null;
            }
            $this->Bracket->save();
            $this->bustTournamentReportCache();
            return Success($this->Bracket->bracket_id);
        }
    }

    /** Allowed values for the ork_bracket ENUM columns (non-strict sql_mode stores '' otherwise). */
    private const BRACKET_ENUMS = [
        'Style'        => ['Single Sword', 'Florentine', 'Sword and Shield', 'Great Weapon', 'Missile',
                           'Other', 'Jugging', 'Battlegame', 'Quest', 'Open Weapons'],
        'Method'       => ['single', 'double', 'swiss', 'round-robin', 'ironman', 'points'],
        'Participants' => ['individual', 'team'],
        'Seeding'      => ['manual', 'glicko2', 'random', 'glicko2-manual', 'random-manual', 'warrior'],
    ];

    /**
     * Returns the first ENUM field in $request whose value is not allowed, or null.
     * With $require, missing fields are invalid too; otherwise only present ones are checked.
     */
    private static function invalid_bracket_enum(array $request, bool $require)
    {
        foreach (self::BRACKET_ENUMS as $field => $allowed) {
            if (!$require && !isset($request[$field])) {
                continue;
            }
            if (!in_array($request[$field] ?? null, $allowed, true)) {
                return $field;
            }
        }
        return null;
    }

    /** Clamp best_of to a valid odd value in {1,3,5,7,9}. */
    private static function normalize_best_of($v)
    {
        $n = (int)$v;
        $allowed = [1, 3, 5, 7, 9];
        return in_array($n, $allowed, true) ? $n : 1;
    }

    /**
     * Validate Points-bracket config. Returns null on success, an error string on failure.
     * @param array $r Request payload (PointRounds, PointMode, PointScale).
     * @param bool $allowScaleAndMode If false, callers (mid-run edits with scoring already
     *                                started) skip mode/scale validation since those fields
     *                                are locked.
     */
    private function validate_points_config($r, $allowScaleAndMode = true)
    {
        $rounds = (int)($r['PointRounds'] ?? 0);
        if ($rounds < 1 || $rounds > 32) {
            return 'PointRounds must be 1-32.';
        }
        if ($allowScaleAndMode) {
            $mode = $r['PointMode'] ?? '';
            if ($mode !== 'fixed' && $mode !== 'open') {
                return 'PointMode must be fixed or open.';
            }
            if ($mode === 'fixed') {
                $raw = trim((string)($r['PointScale'] ?? ''));
                if ($raw === '') {
                    return 'PointScale CSV required for fixed mode.';
                }
                $parts = array_map('trim', explode(',', $raw));
                if (count($parts) < 1 || count($parts) > 16) {
                    return 'PointScale must have 1-16 values.';
                }
                $seen = [];
                foreach ($parts as $p) {
                    if (!preg_match('/^\\d+(\\.\\d{1,2})?$/', $p)) {
                        return "PointScale value \"$p\" invalid (non-neg decimal, <=2 dp).";
                    }
                    $f = (float)$p;
                    if ($f < 0 || $f > 999.99) {
                        return "PointScale value \"$p\" out of range (0-999.99).";
                    }
                    $key = number_format($f, 2, '.', '');
                    if (isset($seen[$key])) {
                        return "PointScale has duplicate value \"$p\".";
                    }
                    $seen[$key] = true;
                }
            }
        }
        return null;
    }

    /**
     * Normalize a PointScale CSV for storage: comma-joined, trimmed, no spaces.
     */
    private function normalize_point_scale($raw)
    {
        $parts = array_map('trim', explode(',', (string)$raw));
        return implode(',', $parts);
    }

    public function UpdateBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!$this->bracketBelongsTo($bracket_id, (int)$this->Tournament->tournament_id)) {
            return InvalidParameter('Bracket does not belong to tournament');
        }
        $bad = self::invalid_bracket_enum($request, false);
        if ($bad !== null) {
            return InvalidParameter(null, "Invalid $bad.");
        }
        // Gate: Ironman brackets do not support team participants.
        if (($request['Method'] ?? '') === 'ironman' && ($request['Participants'] ?? '') === 'team') {
            return InvalidParameter(null, 'Team mode is not supported for Ironman brackets.');
        }

        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        if (!$this->Bracket->find()) {
            return InvalidParameter('Bracket not found');
        }

        // Snapshot pre-mutation values so the audit event can summarize what changed.
        // Int keys are compared as ints; everything else as strings.
        $old = [
            'style'            => (string)$this->Bracket->style,
            'style_note'       => (string)$this->Bracket->style_note,
            'duration_minutes' => (int)$this->Bracket->duration_minutes,
            'best_of'          => (string)$this->Bracket->best_of,
            'first_round_mode' => (string)$this->Bracket->first_round_mode,
            'method'           => (string)$this->Bracket->method,
            'rings'            => (int)$this->Bracket->rings,
            'participants'     => (string)$this->Bracket->participants,
            'seeding'          => (string)$this->Bracket->seeding,
            'point_rounds'     => (int)($this->Bracket->point_rounds ?? 0),
            'point_mode'       => (string)$this->Bracket->point_mode,
            'point_scale'      => (string)$this->Bracket->point_scale,
        ];

        $is_setup = ($this->Bracket->status === 'setup' || $this->Bracket->status === '');

        // Style/StyleNote/DurationMinutes/BestOf are cosmetic — always editable
        if (isset($request['Style'])) {
            $this->Bracket->style            = $request['Style'];
        }
        if (isset($request['StyleNote'])) {
            $this->Bracket->style_note       = $request['StyleNote'];
        }
        if (isset($request['DurationMinutes'])) {
            $this->Bracket->duration_minutes = max(0, (int)$request['DurationMinutes']);
        }
        if (isset($request['BestOf'])) {
            $this->Bracket->best_of          = self::normalize_best_of($request['BestOf']);
        }
        if (isset($request['FirstRoundMode'])) {
            $this->Bracket->first_round_mode = (in_array($request['FirstRoundMode'], ['byes','play-in'], true) ? $request['FirstRoundMode'] : 'byes');
        }

        // Structural fields — only editable while bracket is still in setup
        if ($is_setup) {
            if (isset($request['Method'])) {
                $this->Bracket->method       = $request['Method'];
            }
            if (isset($request['Rings'])) {
                $this->Bracket->rings        = (int)$request['Rings'];
            }
            if (isset($request['Participants'])) {
                $this->Bracket->participants = $request['Participants'];
            }
            if (isset($request['Seeding'])) {
                $this->Bracket->seeding      = $request['Seeding'];
            }
        }

        if (($request['Method'] ?? $this->Bracket->method) === 'points') {
            // Determine if any cells are already scored — locks mode + scale.
            $hasScores = false;
            $r = $this->db->query("SELECT COUNT(*) AS n FROM " . DB_PREFIX . "point_score WHERE bracket_id = $bracket_id AND points IS NOT NULL");
            if ($r && $r->next()) {
                $hasScores = ((int)$r->n > 0);
            }

            $err = $this->validate_points_config($request, !$hasScores);
            if ($err !== null) {
                return InvalidParameter(null, $err);
            }

            $newRounds = (int)$request['PointRounds'];
            $curRounds = (int)($this->Bracket->point_rounds ?? 0);
            if ($this->Bracket->status === 'active' && $newRounds < $curRounds) {
                return InvalidParameter(null, 'Cannot reduce rounds after bracket is active. Reset bracket first.');
            }
            $this->Bracket->point_rounds = $newRounds;
            if (!$hasScores) {
                $this->Bracket->point_mode  = $request['PointMode'];
                $this->Bracket->point_scale = ($request['PointMode'] === 'fixed')
                    ? $this->normalize_point_scale($request['PointScale'] ?? '')
                    : '';
            }
        }

        // Summarize which fields actually changed for the change-log payload.
        $changed = [];
        foreach ($old as $k => $ov) {
            $nv = is_int($ov) ? (int)$this->Bracket->$k : (string)$this->Bracket->$k;
            if ($ov !== $nv) {
                $changed[] = $k;
            }
        }

        $tournament_id = (int)$this->Tournament->tournament_id;
        $actor_id      = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $action_id     = substr(trim($request['ActionId'] ?? ''), 0, 36);

        // tnEmitEvent's increment-then-read of the seq cursor must run inside a
        // transaction so the cursor bump commits atomically with the save.
        $this->db->query('START TRANSACTION');
        try {
            $this->Bracket->save();
            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'bracket_updated', [
                'bracket_id' => $bracket_id,
                'changed'    => $changed,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tournament_id, $seq);
        return Success($bracket_id);
    }

    public function GetBrackets($request)
    {
        if (!valid_id($request['TournamentId'])) {
            return InvalidParameter();
        }
        $tournament_id = (int)$request['TournamentId'];

        $sql = "SELECT * FROM " . DB_PREFIX . "bracket
				WHERE tournament_id = $tournament_id
				ORDER BY bracket_id";
        $r = $this->db->query($sql);
        $brackets = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $brackets[] = [
                    'BracketId'    => (int)$r->bracket_id,
                    'TournamentId' => (int)$r->tournament_id,
                    'Style'        => $r->style,
                    'StyleNote'    => $r->style_note,
                    'Method'       => $r->method,
                    'Rings'        => (int)$r->rings,
                    'Participants' => $r->participants,
                    'Seeding'      => $r->seeding,
                    'Status'          => $r->status,
                    'DurationMinutes' => (int)$r->duration_minutes,
                    'BestOf'          => (int)$r->best_of,
                    'TiebreakerDeclined' => (int)$r->tiebreaker_declined,
                    'FirstRoundMode' => $r->first_round_mode,
                    'PointRounds'    => (int)$r->point_rounds,
                    'PointMode'      => (string)$r->point_mode,
                    'PointScale'     => (string)$r->point_scale,
                    'DrawSize'       => ($r->draw_size === null || $r->draw_size === '') ? null : (int)$r->draw_size,
                ];
            }
        }
        return Success($brackets);
    }

    /**
     * Fill a missing (0) park/kingdom from the player's home park when a valid MundaneId
     * is known, so participant rows for real players don't store park_id/kingdom_id = 0.
     * The kingdom is taken from the effective park. Returns [park_id, kingdom_id].
     */
    private function resolveHomeScope(int $mundane_id, int $park_id, int $kingdom_id): array
    {
        if (!valid_id($mundane_id) || (valid_id($park_id) && valid_id($kingdom_id))) {
            return [$park_id, $kingdom_id];
        }
        if (!valid_id($park_id)) {
            // Only adopt the home park when it is consistent with a caller-supplied kingdom.
            $m = $this->db->query(
                "SELECT m.park_id, p.kingdom_id FROM " . DB_PREFIX . "mundane m
				 LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
				 WHERE m.mundane_id = :mid LIMIT 1",
                [':mid' => $mundane_id]
            );
            if ($m && $m->next() && (!valid_id($kingdom_id) || (int)$m->kingdom_id === $kingdom_id)) {
                $park_id = (int)$m->park_id;
            }
        }
        if (!valid_id($kingdom_id) && valid_id($park_id)) {
            $k = $this->db->query(
                "SELECT kingdom_id FROM " . DB_PREFIX . "park WHERE park_id = :pid LIMIT 1",
                [':pid' => $park_id]
            );
            if ($k && $k->next()) {
                $kingdom_id = (int)$k->kingdom_id;
            }
        }
        return [$park_id, $kingdom_id];
    }

    /**
     * Shared core of a per-bracket add — the long-way AddParticipant and QuickPlace both run it,
     * so a quick-placed fighter can't drift from a long-way one: auto-register at the tournament
     * level (ensureRegistrant), insert the bracket entrant sharing that participant_number, and for
     * a player link them and snapshot warrior/griffon levels. $person: ['MundaneId'=>int,
     * 'Alias'=>string, 'UnitId'=>int, 'ParkId'=>int, 'KingdomId'=>int] with ParkId/KingdomId already
     * resolved via resolveHomeScope. Caller owns the transaction.
     * Returns ['ParticipantId'=>int, 'ParticipantNumber'=>int]; ParticipantId 0 = save failed.
     */
    private function insertBracketEntrant(int $tournament_id, int $bracket_id, array $person): array
    {
        $mid  = (int)($person['MundaneId'] ?? 0);
        $reg  = $this->ensureRegistrant($tournament_id, $person);
        $pnum = (int)$reg['ParticipantNumber'];

        $this->Participant->clear();
        $this->Participant->tournament_id      = $tournament_id;
        $this->Participant->bracket_id         = $bracket_id;
        $this->Participant->alias              = $person['Alias'] ?? '';
        $this->Participant->unit_id            = (int)($person['UnitId'] ?? 0);
        $this->Participant->park_id            = (int)($person['ParkId'] ?? 0);
        $this->Participant->kingdom_id         = (int)($person['KingdomId'] ?? 0);
        $this->Participant->participant_number = $pnum;
        $this->Participant->save();
        $pid = (int)$this->Participant->participant_id;
        if (!valid_id($pid)) {
            return ['ParticipantId' => 0, 'ParticipantNumber' => $pnum];
        }

        if (valid_id($mid)) {
            $this->Player->clear();
            $this->Player->participant_id = $pid;
            $this->Player->mundane_id     = $mid;
            $this->Player->tournament_id  = $tournament_id;
            $this->Player->bracket_id     = $bracket_id;
            $this->Player->save();
            // Snapshot Order-of-the-Warrior level (0-12) at time of competition.
            $awards_map = $this->fetchAwardsForMundanes([$mid]);
            $lvl  = isset($awards_map[$mid]) ? $this->warriorLevelFromAwards($awards_map[$mid]) : 0;
            $glvl = isset($awards_map[$mid]) ? $this->griffonLevelFromAwards($awards_map[$mid]) : 0;
            $this->db->query(
                "UPDATE " . DB_PREFIX . "participant SET warrior_level = :lvl, griffon_level = :glvl WHERE participant_id = :pid",
                [':lvl' => (int)$lvl, ':glvl' => (int)$glvl, ':pid' => $pid]
            );
        }
        return ['ParticipantId' => $pid, 'ParticipantNumber' => $pnum];
    }

    /**
     * Find-or-create the tournament-level registration row (bracket_id IS NULL)
     * for a person, keyed by the tournament-stable participant_number. Shared by
     * AddParticipant (per-bracket auto-register) and RegisterParticipant.
     * $person: ['MundaneId'=>int, 'Alias'=>string, 'UnitId'=>int, 'ParkId'=>int, 'KingdomId'=>int]
     * Returns ['ParticipantNumber'=>int, 'RegistrationId'=>int, 'AlreadyRegistered'=>bool].
     * Caller wraps in a transaction.
     */
    private function ensureRegistrant(int $tournament_id, array $person, ?array $awardsMap = null): array
    {
        $mid = (int)($person['MundaneId'] ?? 0);
        $pnum = 0;
        if (valid_id($mid)) {
            $ex = $this->db->query(
                "SELECT p.participant_number FROM " . DB_PREFIX . "participant p
				 JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
				 WHERE p.tournament_id = $tournament_id AND pm.mundane_id = $mid AND p.participant_number > 0 LIMIT 1"
            );
            if ($ex && $ex->next()) {
                $pnum = (int)$ex->participant_number;
            }
        } else {
            $alias = trim($person['Alias'] ?? '');
            if ($alias !== '') {
                $exa = $this->db->query(
                    "SELECT p.participant_number FROM " . DB_PREFIX . "participant p
					 LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
					 WHERE p.tournament_id = $tournament_id AND pm.mundane_id IS NULL AND p.participant_number > 0 AND p.alias = :a LIMIT 1",
                    [':a' => $alias]
                );
                if ($exa && $exa->next()) {
                    $pnum = (int)$exa->participant_number;
                }
            }
        }
        if (!$pnum) {
            // Locking read: serialize concurrent registrations so two callers can't both
            // grab the same MAX+1. Callers wrap this in a transaction, so FOR UPDATE holds
            // the lock until commit; UNIQUE(tournament_id, participant_number) is the backstop.
            $max = $this->db->query("SELECT MAX(participant_number) AS m FROM " . DB_PREFIX . "participant WHERE tournament_id = $tournament_id FOR UPDATE");
            $pnum = ($max && $max->next() && $max->m > 0) ? (int)$max->m + 1 : 1;
        }

        // Already have a registration row (bracket_id IS NULL) for this number?
        $reg = $this->db->query(
            "SELECT participant_id FROM " . DB_PREFIX . "participant
			 WHERE tournament_id = $tournament_id AND participant_number = $pnum AND bracket_id IS NULL LIMIT 1"
        );
        if ($reg && $reg->next() && valid_id($reg->participant_id)) {
            return ['ParticipantNumber' => $pnum, 'RegistrationId' => (int)$reg->participant_id, 'AlreadyRegistered' => true];
        }

        // Create the registration row (bracket_id NULL).
        [$person['ParkId'], $person['KingdomId']] = $this->resolveHomeScope(
            $mid,
            (int)($person['ParkId'] ?? 0),
            (int)($person['KingdomId'] ?? 0)
        );
        $this->Participant->clear();
        $this->Participant->tournament_id      = $tournament_id;
        $this->Participant->alias              = $person['Alias'] ?? '';
        $this->Participant->unit_id            = (int)($person['UnitId'] ?? 0);
        $this->Participant->park_id            = (int)($person['ParkId'] ?? 0);
        $this->Participant->kingdom_id         = (int)($person['KingdomId'] ?? 0);
        $this->Participant->participant_number = $pnum;
        $this->Participant->save();
        $reg_id = (int)$this->Participant->participant_id;
        // GetLastInsertId (which yapo's save() trusts for participant_id) is unreliable after a
        // dup-key no-op INSERT — it returns the stale prior id. Read the row back by its unique
        // (tournament_id, participant_number, bracket_id IS NULL) identity instead. Under PDO
        // WARNING mode a dup INSERT doesn't throw, so the read-back is the reliable signal.
        $rb = $this->db->query(
            "SELECT participant_id FROM " . DB_PREFIX . "participant
			 WHERE tournament_id = $tournament_id AND participant_number = $pnum AND bracket_id IS NULL
			 ORDER BY participant_id ASC LIMIT 1"
        );
        if ($rb && $rb->next() && valid_id($rb->participant_id)) {
            $reg_id = (int)$rb->participant_id;
        }
        if (!valid_id($reg_id)) {
            throw new \RuntimeException('Registration row save failed — check sql_mode/constraints');
        }
        // yapo drops null fields; force bracket_id NULL explicitly.
        $this->db->query("UPDATE " . DB_PREFIX . "participant SET bracket_id = NULL WHERE participant_id = $reg_id");
        if (valid_id($mid)) {
            $this->Player->clear();
            $this->Player->participant_id = $reg_id;
            $this->Player->mundane_id     = $mid;
            $this->Player->tournament_id  = $tournament_id;
            $this->Player->save();
            $this->db->query("UPDATE " . DB_PREFIX . "participant_mundane SET bracket_id = NULL WHERE participant_id = $reg_id");
            // Reuse a caller-supplied awards map (e.g. ensureTeam prefetches once for the
            // whole roster) to avoid one single-mundane award query per member.
            $awards_map = ($awardsMap !== null) ? $awardsMap : $this->fetchAwardsForMundanes([$mid]);
            $lvl  = isset($awards_map[$mid]) ? $this->warriorLevelFromAwards($awards_map[$mid]) : 0;
            $glvl = isset($awards_map[$mid]) ? $this->griffonLevelFromAwards($awards_map[$mid]) : 0;
            $this->db->query(
                "UPDATE " . DB_PREFIX . "participant SET warrior_level = :lvl, griffon_level = :glvl WHERE participant_id = :pid",
                [':lvl' => (int)$lvl, ':glvl' => (int)$glvl, ':pid' => $reg_id]
            );
        }
        return ['ParticipantNumber' => $pnum, 'RegistrationId' => $reg_id, 'AlreadyRegistered' => false];
    }

    /**
     * Find-or-create the tournament-level registration rows for a team:
     *  - a registration ork_participant row (the team identity, bracket_id IS NULL, alias=name,
     *    participant_number 0 so it is NOT counted as an individual registrant)
     *  - an ork_participant_teams row (bracket_id IS NULL, participant_id -> that row, team_number)
     *  - ork_participant_team_members rows for each member (and ensureRegistrant per member).
     * Keyed by the tournament-stable team_number. Reuses an existing registration team when
     * $team['TeamNumber'] is supplied (edit) or a registration row already exists.
     * $team: ['Name'=>string, 'Members'=>[['MundaneId'=>int],...], 'TeamNumber'=>int(optional)].
     * Returns ['TeamNumber'=>int, 'TeamId'=>int, 'ParticipantId'=>int]. Caller wraps in a transaction.
     */
    private function ensureTeam(int $tournament_id, array $team): array
    {
        $name    = trim($team['Name'] ?? '');
        $members = is_array($team['Members'] ?? null) ? $team['Members'] : [];
        $tnum    = (int)($team['TeamNumber'] ?? 0);

        // Resolve team_number: explicit (edit), else MAX+1.
        if ($tnum <= 0) {
            // Locking read: serialize concurrent team registrations (see ensureRegistrant).
            // UNIQUE(tournament_id, team_number) is the backstop for the race.
            $max = $this->db->query("SELECT MAX(team_number) AS m FROM " . DB_PREFIX . "participant_teams WHERE tournament_id = $tournament_id FOR UPDATE");
            $tnum = ($max && $max->next() && $max->m > 0) ? (int)$max->m + 1 : 1;
        }

        // Existing registration team (bracket_id IS NULL) for this number?
        $reg = $this->db->query(
            "SELECT team_id, participant_id FROM " . DB_PREFIX . "participant_teams
			 WHERE tournament_id = $tournament_id AND team_number = $tnum AND bracket_id IS NULL LIMIT 1"
        );
        if ($reg && $reg->next() && valid_id($reg->team_id)) {
            $team_id = (int)$reg->team_id;
            $pid     = (int)$reg->participant_id;
            $this->db->query("UPDATE " . DB_PREFIX . "participant SET alias = :a WHERE participant_id = :p", [':a' => $name, ':p' => $pid]);
            $this->db->query("UPDATE " . DB_PREFIX . "participant_teams SET name = :a WHERE team_id = :t", [':a' => $name, ':t' => $team_id]);
        } else {
            // Create the registration participant identity row (bracket_id NULL, participant_number 0).
            $this->Participant->clear();
            $this->Participant->tournament_id      = $tournament_id;
            $this->Participant->alias              = $name;
            $this->Participant->participant_number = 0;
            $this->Participant->save();
            $pid = (int)$this->Participant->participant_id;
            if (!valid_id($pid)) {
                throw new \RuntimeException('Team identity row save failed');
            }
            $this->db->query("UPDATE " . DB_PREFIX . "participant SET bracket_id = NULL WHERE participant_id = $pid");
            // Create the registration team row (bracket_id NULL).
            $this->db->query(
                "INSERT INTO " . DB_PREFIX . "participant_teams (tournament_id, bracket_id, participant_id, name, team_number)
				 VALUES (:tid, NULL, :pid, :name, :tnum)",
                [':tid' => $tournament_id, ':pid' => $pid, ':name' => $name, ':tnum' => $tnum]
            );
            // GetLastInsertId is unreliable after a dup-key no-op INSERT (returns the stale prior
            // id), so read the team back by its unique (tournament_id, team_number, bracket_id IS
            // NULL) identity before writing dependent rows against it.
            $team_id = (int)$this->db->GetLastInsertId();
            $trb = $this->db->query(
                "SELECT team_id FROM " . DB_PREFIX . "participant_teams
				 WHERE tournament_id = $tournament_id AND team_number = $tnum AND bracket_id IS NULL
				 ORDER BY team_id ASC LIMIT 1"
            );
            if ($trb && $trb->next() && valid_id($trb->team_id)) {
                $team_id = (int)$trb->team_id;
            }
            if (!valid_id($team_id)) {
                throw new \RuntimeException('Team registration row save failed');
            }
        }

        // One player, one team: a mundane may not sit on two different registration
        // teams in the same tournament. Scope strictly to registration teams
        // (pt.bracket_id IS NULL) — bracket-assigned rows legitimately clone the same
        // mundane across a team's multiple bracket assignments and must NOT count.
        // Exclude this team's own number so re-saving an existing roster is allowed.
        foreach ($members as $m) {
            $mid = (int)($m['MundaneId'] ?? 0);
            if (!valid_id($mid)) {
                continue;
            }
            $dupe = $this->db->query(
                "SELECT mn.persona FROM " . DB_PREFIX . "participant_team_members ptm
				 JOIN " . DB_PREFIX . "participant_teams pt ON pt.team_id = ptm.team_id
				 LEFT JOIN " . DB_PREFIX . "mundane mn ON mn.mundane_id = ptm.mundane_id
				 WHERE pt.tournament_id = $tournament_id AND pt.bracket_id IS NULL
				   AND ptm.mundane_id = $mid AND pt.team_number <> $tnum
				 LIMIT 1"
            );
            if ($dupe && $dupe->next()) {
                $who = trim((string)$dupe->persona);
                // Abort the whole ensureTeam: roll back the open transaction (same as the
                // callers' catch) and surface the error unchanged to RegisterTeam/UpdateTeam.
                $this->db->query('ROLLBACK');
                return InvalidParameter(($who !== '' ? $who : 'A player') . ' is already on another team');
            }
        }

        // Replace the member roster for this registration team.
        $this->db->query("DELETE FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $team_id");
        // Pre-fetch awards once for the whole roster so ensureRegistrant (per member) and
        // the team-level snapshot below both reuse it instead of one query per member.
        $memberMids = [];
        foreach ($members as $m) {
            $mid = (int)($m['MundaneId'] ?? 0);
            if (valid_id($mid)) {
                $memberMids[] = $mid;
            }
        }
        $awards = !empty($memberMids) ? $this->fetchAwardsForMundanes($memberMids) : [];
        foreach ($members as $m) {
            $mid = (int)($m['MundaneId'] ?? 0);
            if (!valid_id($mid)) {
                continue;
            }
            $this->db->query(
                "INSERT IGNORE INTO " . DB_PREFIX . "participant_team_members (team_id, mundane_id, tournament_id) VALUES (:t, :m, :tid)",
                [':t' => $team_id, ':m' => $mid, ':tid' => $tournament_id]
            );
            // Ensure each member is a registered individual too (reuse prefetched awards).
            $this->ensureRegistrant($tournament_id, ['MundaneId' => $mid, 'Alias' => ''], $awards);
        }

        // Snapshot summed warrior/griffon level on the team identity row.
        $teamWL = 0;
        $teamGL = 0;
        foreach ($memberMids as $mid) {
            $teamWL += isset($awards[$mid]) ? $this->warriorLevelFromAwards($awards[$mid]) : 0;
            $teamGL += isset($awards[$mid]) ? $this->griffonLevelFromAwards($awards[$mid]) : 0;
        }
        $this->db->query(
            "UPDATE " . DB_PREFIX . "participant SET warrior_level = :wl, griffon_level = :gl WHERE participant_id = :p",
            [':wl' => (int)$teamWL, ':gl' => (int)$teamGL, ':p' => $pid]
        );

        return ['TeamNumber' => $tnum, 'TeamId' => $team_id, 'ParticipantId' => $pid];
    }

    public function AddParticipant($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        // IDOR guard: the target bracket must belong to the claimed tournament.
        $_bidChk = (int)($request['BracketId'] ?? 0);
        $_tidChk = (int)($request['TournamentId'] ?? 0);
        if (valid_id($_bidChk)) {
            $_bchk = $this->db->query(
                "SELECT tournament_id, status FROM " . DB_PREFIX . "bracket WHERE bracket_id = :bid LIMIT 1",
                [':bid' => $_bidChk]
            );
            if (!$_bchk || !$_bchk->next() || (int)$_bchk->tournament_id !== $_tidChk) {
                return InvalidParameter(null, 'Bracket does not belong to this tournament.');
            }
            // Same rule as RemoveParticipant/AssignToBracket: entrants (copied or new) may only
            // join a bracket still in setup, so a complete/finalized result can't be altered.
            if ($_bchk->status !== 'setup' && $_bchk->status !== '') {
                return InvalidParameter('Participants can only be added while the bracket is in setup.');
            }
        }

        if (valid_id($request['ParticipantId'])) {
            // Copy an existing participant into a new bracket.
            // NOTE: copies only the participant row; participant_mundane/team rows are
            // NOT copied. Not currently reached for team brackets (no caller passes
            // ParticipantId for teams). Extend with id-mapped participant_mundane +
            // participant_teams/participant_team_members copies if a caller needs full copy.
            $bid           = (int)$request['BracketId'];
            $pid           = (int)$request['ParticipantId'];
            $tournament_id = (int)($request['TournamentId'] ?? 0);
            if (!valid_id($tournament_id)) {
                return InvalidParameter('TournamentId required');
            }
            $sql = "INSERT INTO " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level, griffon_level)
						SELECT tournament_id, $bid, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level, griffon_level
						FROM " . DB_PREFIX . "participant WHERE participant_id = $pid AND tournament_id = $tournament_id";
            $this->db->query($sql);
            $new_id = $this->db->GetLastInsertId();
            if (!valid_id($new_id)) {
                return InvalidParameter('Source participant not found in this tournament');
            }
            $this->bustTournamentReportCache();
            return Success($new_id);
        } else {
            $hasAlias   = strlen(trim($request['Alias']   ?? '')) > 0;
            $hasMundane = valid_id($request['MundaneId']  ?? 0);
            $hasMembers = !empty($request['Members']);
            if (!$hasAlias && !$hasMundane && !$hasMembers) {
                return InvalidParameter('Participant requires an Alias, MundaneId, or Members');
            }
            // Compute participant_number BEFORE the insert and serialize the
            // SELECT MAX + INSERT inside a transaction so two concurrent registrations
            // can't both grab the same MAX+1 and a crash between save() and the number
            // assignment can't leave a row stuck at 0.
            $_tid  = (int)$request['TournamentId'];
            $_mid  = (int)($request['MundaneId'] ?? 0);
            [$_park, $_kingdom] = $this->resolveHomeScope(
                $_mid,
                (int)($request['ParkId'] ?? 0),
                (int)($request['KingdomId'] ?? 0)
            );
            $this->db->query('START TRANSACTION');
            try {
                $ent = $this->insertBracketEntrant($_tid, (int)$request['BracketId'], [
                    'MundaneId' => $_mid,
                    'Alias'     => $request['Alias'] ?? '',
                    'UnitId'    => (int)($request['UnitId'] ?? 0),
                    'ParkId'    => $_park,
                    'KingdomId' => $_kingdom,
                ]);
                $_pnum = $ent['ParticipantNumber'];
                if (!valid_id($ent['ParticipantId'])) {
                    $this->db->query('ROLLBACK');
                    return InvalidParameter('Participant save failed — check DB sql_mode and table constraints');
                }
                $_pid = $ent['ParticipantId'];

                if (!valid_id($_mid) && !empty($request['Members'])) {
                    // Team participant — create durable team record then link members
                    $_tid2  = (int)$this->Participant->tournament_id;
                    $_bid2  = (int)$this->Participant->bracket_id;
                    $_pid2  = (int)$this->Participant->participant_id;
                    // Auto-register the team at the tournament level (find-or-create the
                    // registration team) and reuse its tournament-stable team_number so the
                    // per-bracket "Add Team" flow coexists with tournament-level team management.
                    $_treg = $this->ensureTeam($_tid2, ['Name' => $this->Participant->alias, 'Members' => $request['Members']]);
                    // ensureTeam returns an error response (and has already rolled back) on a
                    // one-player-one-team violation. Without this guard the later INSERTs would run
                    // outside any transaction with team_number=0 and the caller would still get
                    // Success. Mirror RegisterTeam/UpdateTeam: a present non-zero Status is the error.
                    if (isset($_treg['Status']) && (int)$_treg['Status'] !== 0) {
                        return $_treg;
                    }
                    $_tnum2 = (int)$_treg['TeamNumber'];
                    $this->db->query(
                        "INSERT INTO " . DB_PREFIX . "participant_teams (tournament_id, bracket_id, participant_id, name, team_number)"
                        . " VALUES (:tid2, :bid2, :pid2, :tname, :tnum2)",
                        [':tid2' => $_tid2, ':bid2' => $_bid2, ':pid2' => $_pid2, ':tname' => $this->Participant->alias, ':tnum2' => $_tnum2]
                    );
                    $_team_id = (int)$this->db->GetLastInsertId();
                    if (!valid_id($_team_id)) {
                        $this->db->query('ROLLBACK');
                        return InvalidParameter(null, 'Team record could not be created.');
                    }
                    foreach ($request['Members'] as $member) {
                        $_mid2 = (int)$member['MundaneId'];
                        if (!valid_id($_mid2)) {
                            continue;
                        }
                        // Roster row in new team tables
                        $this->db->query(
                            "INSERT IGNORE INTO " . DB_PREFIX . "participant_team_members (team_id, mundane_id, tournament_id)"
                            . " VALUES (:team_id, :mid2, :tid2)",
                            [":team_id" => $_team_id, ":mid2" => $_mid2, ":tid2" => $_tid2]
                        );
                        // Also keep ork_participant_mundane populated for backwards-compat queries
                        $this->Player->clear();
                        $this->Player->participant_id = $_pid2;
                        $this->Player->mundane_id     = $_mid2;
                        $this->Player->tournament_id  = $_tid2;
                        $this->Player->bracket_id     = $_bid2;
                        $this->Player->save();
                    }
                    // Snapshot team warrior_level / griffon_level as the sum of member
                    // levels at time of registration.
                    $memberMids = array_map(fn ($m) => (int)$m['MundaneId'], $request['Members']);
                    $memberAwards = $this->fetchAwardsForMundanes($memberMids);
                    $teamWL = 0;
                    $teamGL = 0;
                    foreach ($memberMids as $wmid) {
                        $teamWL += isset($memberAwards[$wmid]) ? $this->warriorLevelFromAwards($memberAwards[$wmid]) : 0;
                        $teamGL += isset($memberAwards[$wmid]) ? $this->griffonLevelFromAwards($memberAwards[$wmid]) : 0;
                    }
                    $this->db->query(
                        "UPDATE " . DB_PREFIX . "participant SET warrior_level = :lvl, griffon_level = :glvl WHERE participant_id = :pid",
                        [':lvl' => (int)$teamWL, ':glvl' => (int)$teamGL, ':pid' => (int)$_pid2]
                    );
                }
                $this->db->query('COMMIT');
            } catch (\Throwable $e) {
                $this->db->query('ROLLBACK');
                throw $e;
            }
            $this->bustTournamentReportCache();
            return Success(['ParticipantId' => (int)$_pid, 'ParticipantNumber' => (int)$_pnum]);
        }
    }

    /**
     * Quick Bracket final seed order. Seeded entrants keep their seat (a duplicate seed goes to
     * the lower participant id); seed-0 entrants — added the long way — fill the lowest empty
     * seats by participant id; then gaps close. $rows: list of ['ParticipantId'=>int,'Seed'=>int].
     * Returns participant ids, top seed first. Mirrored by seatEntrants() in
     * script/tournament-quickbracket.js — keep the two in step. Pure (no DB, no token), hence
     * the underscore name: the JSON service refuses '_' methods.
     */
    public static function quick_seed_order(array $rows): array
    {
        usort($rows, fn ($a, $b) => (int)$a['ParticipantId'] <=> (int)$b['ParticipantId']);
        $seats    = [];
        $unseeded = [];
        foreach ($rows as $row) {
            $s = (int)$row['Seed'];
            if ($s > 0 && !isset($seats[$s])) {
                $seats[$s] = (int)$row['ParticipantId'];
            } else {
                $unseeded[] = (int)$row['ParticipantId'];
            }
        }
        $s = 1;
        foreach ($unseeded as $pid) {
            while (isset($seats[$s])) {
                $s++;
            }
            $seats[$s] = $pid;
        }
        ksort($seats);
        return array_values($seats);
    }

    /** Quick Bracket starting sizes (spec 2026-10-02). */
    private const QUICK_DRAW_SIZES = [4, 8, 12, 16, 24, 32];

    /**
     * Quick Bracket: an individual, manually seeded single/double-elim bracket with the defaults
     * below plus draw_size, which makes the UI render it as an empty seeded draft draw.
     * Request: Token, TournamentId, Method (single|double), DrawSize (4|8|12|16|24|32).
     */
    public function CreateQuickBracket($request)
    {
        $method    = (string)($request['Method'] ?? '');
        $draw_size = (int)($request['DrawSize'] ?? 0);
        if (!in_array($method, ['single', 'double'], true)) {
            return InvalidParameter(null, 'Quick brackets are single or double elimination.');
        }
        if (!in_array($draw_size, self::QUICK_DRAW_SIZES, true)) {
            return InvalidParameter(null, 'Invalid starting size.');
        }
        $r = $this->AddBracket([
            'Token'           => $request['Token'] ?? '',
            'TournamentId'    => (int)($request['TournamentId'] ?? 0),
            'Style'           => 'Open Weapons',
            'StyleNote'       => '',
            'Method'          => $method,
            'Rings'           => 1,
            'Participants'    => 'individual',
            'Seeding'         => 'manual',
            'DurationMinutes' => 0,
            'BestOf'          => 3,
        ]);
        if ($r['Status'] != 0) {
            return $r;
        }
        $bracket_id = (int)$r['Detail'];
        // db->query never returns false: read the SQLSTATE. A missing draw_size column (migration
        // not run) leaves a plain setup bracket behind; nothing is deleted.
        $u = @$this->db->query(
            "UPDATE " . DB_PREFIX . "bracket SET draw_size = :ds WHERE bracket_id = :bid",
            [':ds' => $draw_size, ':bid' => $bracket_id]
        );
        if ((string)($u->__ERROR[0][1] ?? '') !== '00000') {
            return InvalidParameter(null, "Quick Bracket isn't available yet — run the draw_size migration.");
        }
        return Success($bracket_id);
    }

    /**
     * Quick Bracket: place one fighter into one seat of a draft draw. Runs the same entrant core
     * as the long-way AddParticipant (registration + entrant + player link + level snapshots),
     * then sets the seed. Request: Token, TournamentId, BracketId, Seed, MundaneId and/or Alias,
     * ActionId. A player with no Alias gets their persona.
     */
    public function QuickPlace($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid   = (int)($request['TournamentId'] ?? 0);
        $bid   = (int)($request['BracketId'] ?? 0);
        $seed  = (int)($request['Seed'] ?? 0);
        $mid   = (int)($request['MundaneId'] ?? 0);
        $alias = trim((string)($request['Alias'] ?? ''));
        if (!$this->bracketBelongsTo($bid, $tid)) {
            return InvalidParameter(null, 'Bracket does not belong to this tournament.');
        }
        if (!valid_id($mid) && $alias === '') {
            return InvalidParameter(null, 'Pick a player or type a name.');
        }
        if (valid_id($mid) && $alias === '') {
            // Persona only — never the player's legal name.
            $pr = $this->db->query(
                "SELECT persona FROM " . DB_PREFIX . "mundane WHERE mundane_id = :m LIMIT 1",
                [':m' => $mid]
            );
            $alias = ($pr && $pr->next()) ? trim((string)$pr->persona) : '';
            if ($alias === '') {
                return InvalidParameter(null, 'Player has no persona — type a name instead.');
            }
        }
        $alias = mb_substr($alias, 0, 100);
        [$park, $kingdom] = $this->resolveHomeScope($mid, 0, 0);

        $this->db->query('START TRANSACTION');
        try {
            // Lock the bracket row: serializes concurrent placements into the same draft.
            $b = $this->db->query(
                "SELECT status, draw_size, participants FROM " . DB_PREFIX . "bracket WHERE bracket_id = :bid FOR UPDATE",
                [':bid' => $bid]
            );
            if (!$b || !$b->next()) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'Bracket not found.');
            }
            if (!in_array((string)$b->status, ['setup', ''], true)) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'This bracket has already started.');
            }
            $draw_size = (int)$b->draw_size;
            if ($draw_size <= 0) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'Not a quick bracket.');
            }
            if ((string)$b->participants !== 'individual') {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'Quick placement is for individual brackets.');
            }
            $cnt = $this->db->query("SELECT COUNT(*) AS n FROM " . DB_PREFIX . "participant WHERE bracket_id = :bid", [':bid' => $bid]);
            $entrants = ($cnt && $cnt->next()) ? (int)$cnt->n : 0;
            if ($seed < 1 || $seed > max($draw_size, $entrants)) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'Invalid seed.');
            }
            $taken = $this->db->query(
                "SELECT participant_id FROM " . DB_PREFIX . "participant WHERE bracket_id = :bid AND seed = :s LIMIT 1",
                [':bid' => $bid, ':s' => $seed]
            );
            if ($taken && $taken->next()) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, 'Seed ' . $seed . ' was just filled.');
            }
            if (valid_id($mid)) {
                $dup = $this->db->query(
                    "SELECT participant_id FROM " . DB_PREFIX . "participant_mundane WHERE bracket_id = :bid AND mundane_id = :m LIMIT 1",
                    [':bid' => $bid, ':m' => $mid]
                );
            } else {
                $dup = $this->db->query(
                    "SELECT p.participant_id FROM " . DB_PREFIX . "participant p
					 LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
					 WHERE p.bracket_id = :bid AND pm.mundane_id IS NULL AND p.alias = :a LIMIT 1",
                    [':bid' => $bid, ':a' => $alias]
                );
            }
            if ($dup && $dup->next()) {
                $this->db->query('ROLLBACK');
                return InvalidParameter(null, $alias . ' is already in this bracket.');
            }

            $ent = $this->insertBracketEntrant($tid, $bid, [
                'MundaneId' => $mid,
                'Alias'     => $alias,
                'UnitId'    => 0,
                'ParkId'    => $park,
                'KingdomId' => $kingdom,
            ]);
            if (!valid_id($ent['ParticipantId'])) {
                $this->db->query('ROLLBACK');
                return InvalidParameter('Participant save failed — check DB sql_mode and table constraints');
            }
            $this->db->query(
                "UPDATE " . DB_PREFIX . "participant SET seed = :s WHERE participant_id = :pid",
                [':s' => $seed, ':pid' => $ent['ParticipantId']]
            );

            $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
            $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
            $seq = $this->tnEmitEvent($tid, $bid, 'participant_placed', [
                'bracket_id' => $bid,
                'seed'       => $seed,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tid, $seq);
        return Success(['ParticipantId' => $ent['ParticipantId'], 'ParticipantNumber' => $ent['ParticipantNumber'], 'Seq' => $seq]);
    }

    /**
     * Quick Bracket Start: normalize seeds with quick_seed_order (seed-0 long-way adds take the
     * lowest empty seats, gaps close) and run the normal generator, which sizes the draw to the
     * fighters placed and gives byes to the top seeds. Request: Token, TournamentId, BracketId, ActionId.
     */
    public function StartQuickBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid = (int)($request['TournamentId'] ?? 0);
        $bid = (int)($request['BracketId'] ?? 0);
        if (!$this->bracketBelongsTo($bid, $tid)) {
            return InvalidParameter(null, 'Bracket does not belong to this tournament.');
        }
        $rows = [];
        $r = $this->db->query(
            "SELECT participant_id, seed FROM " . DB_PREFIX . "participant WHERE bracket_id = :bid",
            [':bid' => $bid]
        );
        if ($r) {
            while ($r->next()) {
                $rows[] = ['ParticipantId' => (int)$r->participant_id, 'Seed' => (int)$r->seed];
            }
        }
        // Pre-validate what GenerateMatches would reject, so a refused Start never renumbers seats.
        $br = $this->db->query(
            "SELECT status, method FROM " . DB_PREFIX . "bracket WHERE bracket_id = :bid LIMIT 1",
            [':bid' => $bid]
        );
        if (!$br || !$br->next()) {
            return InvalidParameter('Bracket not found');
        }
        if (!in_array((string)$br->status, ['setup', ''], true)) {
            return InvalidParameter(null, 'This bracket has already started.');
        }
        if (count($rows) < 2) {
            return InvalidParameter('Need at least 2 participant(s)');
        }
        if ((string)$br->method === 'double' && count($rows) < 3) {
            return InvalidParameter('Double elimination requires at least 3 participants');
        }
        $order = self::quick_seed_order($rows);
        if (count($order) > 0) {
            $ro = $this->ReorderSeeds([
                'Token'        => $request['Token'] ?? '',
                'TournamentId' => $tid,
                'BracketId'    => $bid,
                'Order'        => $order,
            ]);
            if ($ro['Status'] != 0) {
                return $ro;
            }
        }
        return $this->GenerateMatches([
            'Token'        => $request['Token'] ?? '',
            'TournamentId' => $tid,
            'BracketId'    => $bid,
            'ActionId'     => $request['ActionId'] ?? '',
        ]);
    }

    public function GetParticipants($request)
    {
        $where = $this->buildFilterWhere($request, 'p');

        // Determine bracket type (team vs individual) so we can collapse rows correctly.
        // Callers that already know the type can pass BracketType to skip this lookup.
        $bracketParticipants = 'individual';
        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!empty($request['BracketType'])) {
            $bracketParticipants = $request['BracketType'];
        } elseif (valid_id($bracket_id)) {
            $br = $this->db->query(
                "SELECT participants FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id LIMIT 1"
            );
            if ($br && $br->size() > 0 && $br->next()) {
                $bracketParticipants = $br->participants;
            }
        }

        // Main row query — no correlated award subqueries; awards batched below.
        // p.* includes warrior_level (used for seeding on team brackets too).
        $sql = "SELECT p.*, m.persona, pm.mundane_id, k.name AS kingdom_name,
					COALESCE(park.name, mpark.name) AS park_name,
					u.name AS unit_name
				FROM " . DB_PREFIX . "participant p
					LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
						LEFT JOIN " . DB_PREFIX . "mundane m ON pm.mundane_id = m.mundane_id
							LEFT JOIN " . DB_PREFIX . "park mpark ON mpark.park_id = m.park_id
					LEFT JOIN " . DB_PREFIX . "unit u ON p.unit_id = u.unit_id
					LEFT JOIN " . DB_PREFIX . "park park ON p.park_id = park.park_id
					LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id
				WHERE 1 $where
				ORDER BY p.participant_id";
        $r = $this->db->query($sql);
        $participants = [];
        $mids = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $mid = (int)$r->mundane_id;
                if ($mid > 0) {
                    $mids[$mid] = true;
                }
                $participants[] = [
                    'ParticipantId' => (int)$r->participant_id,
                    'TournamentId'  => (int)$r->tournament_id,
                    'BracketId'     => (int)$r->bracket_id,
                    'Alias'         => $r->alias,
                    'UnitId'        => (int)$r->unit_id,
                    'ParkId'        => (int)$r->park_id,
                    'KingdomId'     => (int)$r->kingdom_id,
                    'Persona'       => $r->persona,
                    'MundaneId'     => $mid,
                    'KingdomName'   => $r->kingdom_name,
                    'ParkName'      => $r->park_name,
                    'UnitName'      => $r->unit_name,
                    'WarriorCount'  => 0,
                    'WarriorRank'   => 0,
                    'IsWarlord'     => false,
                    'IsKnightSword' => false,
                    'Seed'          => (int)$r->seed,
                    'ParticipantNumber' => (int)$r->participant_number,
                    'Eliminated'    => (int)$r->eliminated,
                    'BracketSide'   => $r->bracket_side,
                    'WarriorLevel'  => (int)$r->warrior_level,
                    'GriffonLevel'  => (int)$r->griffon_level,
                    'Status'        => $r->status,
                ];
            }
        }

        // Batched award lookup: one query for all mundane_ids on the page,
        // joined back into the participant rows in PHP.
        // Skipped for team brackets: those rows are collapsed below and use the
        // team's warrior_level snapshot / per-member teamRoster levels, never these
        // individual award fields (all tnParticipantPills() reads are guarded by !IsTeam).
        if ($bracketParticipants !== 'team' && !empty($mids)) {
            $awards_map = $this->fetchAwardsForMundanes(array_keys($mids));
            foreach ($participants as &$participant) {
                $mid = (int)$participant['MundaneId'];
                if ($mid > 0 && isset($awards_map[$mid])) {
                    $participant['WarriorCount']  = $awards_map[$mid]['warrior_count'];
                    $participant['WarriorRank']   = $awards_map[$mid]['warrior_rank'];
                    $participant['IsWarlord']     = $awards_map[$mid]['is_warlord'];
                    $participant['IsKnightSword'] = $awards_map[$mid]['is_knight_sword'];
                }
            }
            unset($participant);
        }

        // Team brackets: collapse N duplicate rows (one per member from the LEFT JOIN
        // on ork_participant_mundane) into one row per participant, then attach a
        // Members[] roster via the shared teamRoster() helper.
        if ($bracketParticipants === 'team') {
            $byPid = [];
            foreach ($participants as $row) {
                $pid = (int)$row['ParticipantId'];
                if (!isset($byPid[$pid])) {
                    $row['IsTeam']    = true;
                    $row['MundaneId'] = 0;
                    $row['Members']   = [];
                    $byPid[$pid] = $row;
                }
            }
            // Populate Members[] from the shared teamRoster() helper.
            $roster = $this->teamRoster($bracket_id);
            foreach ($byPid as $pid => &$teamRow) {
                $teamRow['Members'] = $roster[$pid] ?? [];
            }
            unset($teamRow);
            $participants = array_values($byPid);
        }

        return Success($participants);
    }

    /**
     * GetRegistrants($request)
     * Returns the tournament roster: one row per registration (ork_participant
     * rows with bracket_id IS NULL), decorated with award/warrior pills and a
     * Brackets[] list of which brackets each registrant is assigned to (matched
     * by the tournament-stable participant_number).
     */
    public function GetRegistrants($request)
    {
        $tid = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($tid)) {
            return InvalidParameter('TournamentId required');
        }

        $sql = "SELECT p.*, m.persona, pm.mundane_id, k.name AS kingdom_name,
					COALESCE(park.name, mpark.name) AS park_name, u.name AS unit_name
				FROM " . DB_PREFIX . "participant p
					LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
					LEFT JOIN " . DB_PREFIX . "mundane m ON pm.mundane_id = m.mundane_id
					LEFT JOIN " . DB_PREFIX . "park mpark ON mpark.park_id = m.park_id
					LEFT JOIN " . DB_PREFIX . "unit u ON p.unit_id = u.unit_id
					LEFT JOIN " . DB_PREFIX . "park park ON p.park_id = park.park_id
					LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id
				WHERE p.tournament_id = $tid AND p.bracket_id IS NULL AND p.participant_number > 0
				ORDER BY p.participant_number";
        $r = $this->db->query($sql);
        $regs = [];
        $byNum = [];
        $mids = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $mid = (int)$r->mundane_id;
                if ($mid > 0) {
                    $mids[$mid] = true;
                }
                $row = [
                    'ParticipantId'     => (int)$r->participant_id,
                    'TournamentId'      => (int)$r->tournament_id,
                    'ParticipantNumber' => (int)$r->participant_number,
                    'Alias'             => $r->alias,
                    'UnitId'            => (int)$r->unit_id,
                    'ParkId'            => (int)$r->park_id,
                    'KingdomId'         => (int)$r->kingdom_id,
                    'Persona'           => $r->persona,
                    'MundaneId'         => $mid,
                    'KingdomName'       => $r->kingdom_name,
                    'ParkName'          => $r->park_name,
                    'UnitName'          => $r->unit_name,
                    'WarriorLevel'      => (int)$r->warrior_level,
                    'GriffonLevel'      => (int)$r->griffon_level,
                    'WarriorCount'      => 0, 'WarriorRank' => 0,
                    'IsWarlord'         => false, 'IsKnightSword' => false,
                    'Status'            => $r->status,
                    'Brackets'          => [],
                ];
                $regs[] = $row;
                $byNum[(int)$r->participant_number] = count($regs) - 1;
            }
        }
        if (!empty($mids)) {
            $awards_map = $this->fetchAwardsForMundanes(array_keys($mids));
            foreach ($regs as &$rg) {
                $mid = (int)$rg['MundaneId'];
                if ($mid > 0 && isset($awards_map[$mid])) {
                    $rg['WarriorCount']  = $awards_map[$mid]['warrior_count'];
                    $rg['WarriorRank']   = $awards_map[$mid]['warrior_rank'];
                    $rg['IsWarlord']     = $awards_map[$mid]['is_warlord'];
                    $rg['IsKnightSword'] = $awards_map[$mid]['is_knight_sword'];
                }
            }
            unset($rg);
        }
        if (!empty($byNum)) {
            $br = $this->db->query(
                "SELECT p.participant_number AS num, b.bracket_id AS bid, b.style AS style
				 FROM " . DB_PREFIX . "participant p
				 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = p.bracket_id
				 WHERE p.tournament_id = $tid AND p.bracket_id IS NOT NULL"
            );
            if ($br && $br->size() > 0) {
                while ($br->next()) {
                    $num = (int)$br->num;
                    if (isset($byNum[$num])) {
                        $regs[$byNum[$num]]['Brackets'][] = ['BracketId' => (int)$br->bid, 'BracketStyle' => $br->style];
                    }
                }
            }
        }
        return Success($regs);
    }

    /**
     * teamRoster($bracketId)
     * Returns a map of participant_id => Members[] for all teams in a bracket.
     * Members[] contains MundaneId, Persona, ParkName, WarriorLevel.
     * Shared by GetParticipants and GetStandings to avoid duplicating the join.
     */
    private function teamRoster(int $bracketId): array
    {
        $r = $this->db->query(
            "SELECT pt.participant_id AS participant_id, ptm.mundane_id AS mundane_id,
			        mn.persona AS persona, mpark.name AS park_name
			 FROM " . DB_PREFIX . "participant_teams pt
			 JOIN " . DB_PREFIX . "participant_team_members ptm ON ptm.team_id = pt.team_id
			 LEFT JOIN " . DB_PREFIX . "mundane mn ON mn.mundane_id = ptm.mundane_id
			 LEFT JOIN " . DB_PREFIX . "park mpark ON mpark.park_id = mn.park_id
			 WHERE pt.bracket_id = :bid",
            [':bid' => $bracketId]
        );
        $byPid = [];
        if ($r && $r->size() > 0) {
            while ($r->next()) {
                $pid = (int)$r->participant_id;
                $byPid[$pid][] = [
                    'MundaneId'    => (int)$r->mundane_id,
                    'Persona'      => $r->persona ?? '',
                    'ParkName'     => $r->park_name ?? '',
                    'WarriorLevel' => 0, // placeholder; filled below
                ];
            }
        }
        // Decorate each member with their individual WarriorLevel.
        $allMids = [];
        foreach ($byPid as $members) {
            foreach ($members as $m) {
                $allMids[] = $m['MundaneId'];
            }
        }
        $allMids = array_values(array_unique($allMids));
        $rosterAwards = !empty($allMids) ? $this->fetchAwardsForMundanes($allMids) : [];
        foreach ($byPid as $pid => &$members) {
            foreach ($members as &$m) {
                $rmid = $m['MundaneId'];
                $m['WarriorLevel'] = isset($rosterAwards[$rmid])
                    ? $this->warriorLevelFromAwards($rosterAwards[$rmid]) : 0;
            }
            unset($m);
        }
        unset($members);
        return $byPid;
    }

    /**
     * Maps a single award-row (from fetchAwardsForMundanes) to the 0–12
     * warrior level used for seeding. Extracted to avoid repeating the
     * mapping in AddParticipant (individual), AddParticipant (team), and
     * teamRoster().
     */
    private function warriorLevelFromAwards(array $a): int
    {
        if (!empty($a['is_knight_sword'])) {
            return 12;
        }
        if (!empty($a['is_warlord'])) {
            return 11;
        }
        return min(10, (int)($a['warrior_rank'] ?? 0));
    }

    /**
     * Maps a single award-row (from fetchAwardsForMundanes) to the 0–11
     * griffon level. Mirrors warriorLevelFromAwards but the Griffin ladder
     * has no Knight-of-the-Sword equivalent, so it tops out at Master Griffin (11).
     */
    private function griffonLevelFromAwards(array $a): int
    {
        if (!empty($a['is_master_griffin'])) {
            return 11;
        }
        return min(10, (int)($a['griffon_rank'] ?? 0));
    }

    /**
     * Batched award decoration: returns a map keyed by mundane_id with
     * warrior_count, warrior_rank, is_warlord, is_knight_sword. Replaces
     * the per-row correlated subqueries that GetParticipants/GetStandings
     * used to fire (4 subqueries × N rows).
     */
    private function fetchAwardsForMundanes(array $mundane_ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $mundane_ids)));
        $ids = array_filter($ids, fn ($x) => $x > 0);
        $out = [];
        if (empty($ids)) {
            return $out;
        }
        $id_list = implode(',', $ids);
        // Warrior ladder: 27 = Order of the Warrior (rank/count), 12 = Warlord, 20 = Sword Knight.
        // Griffin ladder: 33 = Order of the Griffin (rank/count), 11 = Master Griffin.
        $r = $this->db->query(
            "SELECT mundane_id, award_id, IFNULL(MAX(`rank`), 0) AS rnk, COUNT(*) AS cnt
			 FROM " . DB_PREFIX . "awards
			 WHERE mundane_id IN ($id_list)
			   AND award_id IN (11, 12, 20, 27, 33)
			   AND revoked = 0
			 GROUP BY mundane_id, award_id"
        );
        if ($r && $r->size() > 0) {
            while ($r->next()) {
                $mid = (int)$r->mundane_id;
                if (!isset($out[$mid])) {
                    $out[$mid] = [
                        'warrior_count' => 0, 'warrior_rank' => 0, 'is_warlord' => false, 'is_knight_sword' => false,
                        'griffon_count' => 0, 'griffon_rank' => 0, 'is_master_griffin' => false,
                    ];
                }
                $aid = (int)$r->award_id;
                $cnt = (int)$r->cnt;
                $rnk = (int)$r->rnk;
                if ($aid === 27) {
                    $out[$mid]['warrior_count'] = $cnt;
                    $out[$mid]['warrior_rank']  = $rnk;
                } elseif ($aid === 12) {
                    $out[$mid]['is_warlord'] = $cnt > 0;
                } elseif ($aid === 20) {
                    $out[$mid]['is_knight_sword'] = $cnt > 0;
                } elseif ($aid === 33) {
                    $out[$mid]['griffon_count'] = $cnt;
                    $out[$mid]['griffon_rank']  = $rnk;
                } elseif ($aid === 11) {
                    $out[$mid]['is_master_griffin'] = $cnt > 0;
                }
            }
        }
        return $out;
    }

    /**
     * deleteTeamRows($whereColumn, $id)
     * Removes participant_team_members (via join) and participant_teams rows for a
     * given owning column. $whereColumn is a fixed internal literal; $id is an int.
     */
    private function deleteTeamRows(string $whereColumn, int $id): void
    {
        $this->db->query('DELETE ptm FROM ' . DB_PREFIX . 'participant_team_members ptm'
            . ' INNER JOIN ' . DB_PREFIX . 'participant_teams pt ON ptm.team_id = pt.team_id'
            . ' WHERE pt.' . $whereColumn . ' = ' . $id);
        $this->db->query('DELETE FROM ' . DB_PREFIX . 'participant_teams WHERE ' . $whereColumn . ' = ' . $id);
    }

    public function RemoveParticipant($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $participant_id = (int)($request['ParticipantId'] ?? 0);
        $tournament_id  = (int)($request['TournamentId']  ?? 0);
        if (!valid_id($participant_id)) {
            return InvalidParameter('ParticipantId required');
        }
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }

        // Verify participant belongs to the authorized tournament before deleting
        $check = $this->db->query('SELECT participant_id, bracket_id FROM ' . DB_PREFIX . 'participant WHERE participant_id = ' . $participant_id . ' AND tournament_id = ' . $tournament_id);
        if (!$check || !$check->next()) {
            return InvalidParameter('Participant not found in this tournament');
        }

        // If this participant is assigned to a bracket, that bracket must still be in setup —
        // same guard UnassignFromBracket enforces. Deleting an entrant from a bracket that has
        // begun play would orphan match references. Registration rows (bracket_id NULL) are exempt.
        $p_bid = ($check->bracket_id !== null) ? (int)$check->bracket_id : 0;
        if ($p_bid > 0) {
            $pb = $this->db->query("SELECT status FROM " . DB_PREFIX . "bracket WHERE bracket_id = $p_bid LIMIT 1");
            if ($pb && $pb->next() && $pb->status !== 'setup' && $pb->status !== '') {
                return InvalidParameter('Participants can only be removed while the bracket is in setup.');
            }
        }

        $this->db->query('START TRANSACTION');
        try {
            $this->deleteTeamRows('participant_id', $participant_id);
            $this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $participant_id");
            $this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $participant_id AND tournament_id = $tournament_id");
            $seq = 0;
            if ($p_bid > 0) {
                $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
                $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
                $seq = $this->tnEmitEvent($tournament_id, $p_bid, 'participant_removed', [
                    'bracket_id'     => $p_bid,
                    'participant_id' => $participant_id,
                ], $actor_id, $action_id !== '' ? $action_id : null);
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        if ($seq > 0) {
            $this->tnPublishSeq($tournament_id, $seq);
        }
        return Success($participant_id);
    }

    /**
     * Bulk-assign tournament registrants to a bracket. Each registrant is identified
     * by its tournament-stable participant_number; for each one not already in the
     * bracket, clone the registration row into a per-bracket entrant row (carrying the
     * warrior_level snapshot) and copy its individual player link. Allowed only while
     * the bracket is in 'setup'. Teams are not handled here (team brackets use their
     * own per-bracket roster flow).
     */
    public function AssignToBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid  = (int)($request['TournamentId'] ?? 0);
        $bid  = (int)($request['BracketId'] ?? 0);
        $nums = $request['ParticipantNumbers'] ?? [];
        if (!valid_id($tid) || !valid_id($bid)) {
            return InvalidParameter('TournamentId and BracketId required');
        }
        if (!is_array($nums) || empty($nums)) {
            return InvalidParameter('ParticipantNumbers required');
        }

        $b = $this->db->query("SELECT tournament_id, status, participants FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bid LIMIT 1");
        if (!$b || !$b->next() || (int)$b->tournament_id !== $tid) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }
        if ($b->participants !== 'individual') {
            return InvalidParameter('This bracket is not an individual bracket.');
        }
        if ($b->status !== 'setup') {
            return InvalidParameter('Participants can only be assigned while the bracket is in setup.');
        }

        // Normalize the request to unique positive numbers, preserving first-seen order
        // (dedup is equivalent to the old per-row flow: a repeated number assigns once,
        // then hits the already-in-bracket skip).
        $order = [];
        $seen  = [];
        foreach ($nums as $num) {
            $num = (int)$num;
            if ($num <= 0 || isset($seen[$num])) {
                continue;
            }
            $seen[$num] = true;
            $order[] = $num;
        }

        $assigned = [];
        $this->db->query('START TRANSACTION');
        try {
            if (!empty($order)) {
                $inList = implode(',', $order);
                // Numbers already in this bracket are skipped (set-based existence check).
                $existing = [];
                $ex = $this->db->query("SELECT participant_number FROM " . DB_PREFIX . "participant WHERE tournament_id = $tid AND bracket_id = $bid AND participant_number IN ($inList)");
                if ($ex) {
                    while ($ex->next()) {
                        $existing[(int)$ex->participant_number] = true;
                    }
                }
                $todo = array_values(array_filter($order, fn ($n) => !isset($existing[$n])));

                if (!empty($todo)) {
                    $todoList = implode(',', $todo);
                    // Clone the registration rows (bracket_id IS NULL) into this bracket in one
                    // INSERT...SELECT, carrying the warrior_level + griffon_level snapshots.
                    // INSERT IGNORE + UNIQUE(tournament_id, bracket_id, participant_number) makes
                    // this atomic vs. a concurrent request: a collision inserts 0 rows (benign skip).
                    $this->db->query(
                        "INSERT IGNORE INTO " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level, griffon_level)
						 SELECT tournament_id, $bid, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level, griffon_level
						 FROM " . DB_PREFIX . "participant
						 WHERE tournament_id = $tid AND bracket_id IS NULL AND participant_number IN ($todoList)"
                    );
                    // Copy the individual player links (mundane) for the just-cloned rows. The new
                    // entrant id is mapped from its source by the shared participant_number: the
                    // source is the unique registration row (bracket_id IS NULL) and the clone is
                    // unique per (tournament_id, bracket_id, participant_number).
                    $this->db->query(
                        "INSERT INTO " . DB_PREFIX . "participant_mundane (participant_id, mundane_id, tournament_id, bracket_id)
						 SELECT newp.participant_id, pm.mundane_id, $tid, $bid
						 FROM " . DB_PREFIX . "participant newp
						 JOIN " . DB_PREFIX . "participant srcp
						   ON srcp.tournament_id = $tid AND srcp.bracket_id IS NULL AND srcp.participant_number = newp.participant_number
						 JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = srcp.participant_id
						 WHERE newp.tournament_id = $tid AND newp.bracket_id = $bid AND newp.participant_number IN ($todoList)"
                    );
                    // Read back the assigned ids for the response, reported in request order.
                    $map = [];
                    $rr = $this->db->query("SELECT participant_id, participant_number FROM " . DB_PREFIX . "participant WHERE tournament_id = $tid AND bracket_id = $bid AND participant_number IN ($todoList)");
                    if ($rr) {
                        while ($rr->next()) {
                            $map[(int)$rr->participant_number] = (int)$rr->participant_id;
                        }
                    }
                    foreach ($order as $n) {
                        if (!isset($existing[$n]) && isset($map[$n])) {
                            $assigned[] = ['ParticipantNumber' => $n, 'ParticipantId' => $map[$n]];
                        }
                    }
                }
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        return Success(['Assigned' => $assigned]);
    }

    /**
     * Bulk-remove registrants from a bracket by participant_number. Deletes the
     * per-bracket entrant rows (and their player links / team rows) but leaves the
     * tournament-level registration row intact. Allowed only while the bracket is
     * in 'setup'.
     */
    public function UnassignFromBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid  = (int)($request['TournamentId'] ?? 0);
        $bid  = (int)($request['BracketId'] ?? 0);
        $nums = $request['ParticipantNumbers'] ?? [];
        if (!valid_id($tid) || !valid_id($bid)) {
            return InvalidParameter('TournamentId and BracketId required');
        }
        if (!is_array($nums) || empty($nums)) {
            return InvalidParameter('ParticipantNumbers required');
        }

        $b = $this->db->query("SELECT tournament_id, status FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bid LIMIT 1");
        if (!$b || !$b->next() || (int)$b->tournament_id !== $tid) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }
        if ($b->status !== 'setup') {
            return InvalidParameter('Participants can only be removed while the bracket is in setup.');
        }

        $this->db->query('START TRANSACTION');
        try {
            foreach ($nums as $num) {
                $num = (int)$num;
                if ($num <= 0) {
                    continue;
                }
                $pids = [];
                $rows = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE tournament_id = $tid AND bracket_id = $bid AND participant_number = $num");
                if ($rows && $rows->size() > 0) {
                    while ($rows->next()) {
                        $pids[] = (int)$rows->participant_id;
                    }
                }
                foreach ($pids as $pid) {
                    if (!valid_id($pid)) {
                        continue;
                    }
                    $this->deleteTeamRows('participant_id', $pid);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
                    $this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $pid AND tournament_id = $tid");
                }
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        return Success(true);
    }

    public function RegisterTeam($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid  = (int)($request['TournamentId'] ?? 0);
        $name = trim($request['Name'] ?? '');
        $members = is_array($request['Members'] ?? null) ? $request['Members'] : [];
        if (!valid_id($tid)) {
            return InvalidParameter('TournamentId required');
        }
        if ($name === '') {
            return InvalidParameter('Team name required');
        }
        $hasMember = false;
        foreach ($members as $m) {
            if (valid_id($m['MundaneId'] ?? 0)) {
                $hasMember = true;
                break;
            }
        }
        if (!$hasMember) {
            return InvalidParameter('A team needs at least one member');
        }
        $this->db->query('START TRANSACTION');
        try {
            $res = $this->ensureTeam($tid, ['Name' => $name, 'Members' => $members]);
            // ensureTeam returns an error response (and has already rolled back) on a
            // one-player-one-team violation — surface it unchanged. Success returns a bare
            // ['TeamNumber'=>..] array with no Status key; the error path is InvalidParameter()
            // (Status=4), so a present, non-zero Status is the reliable error discriminator.
            if (isset($res['Status']) && (int)$res['Status'] !== 0) {
                return $res;
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        return Success($res);
    }

    public function GetRegisteredTeams($request)
    {
        $tid = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($tid)) {
            return InvalidParameter('TournamentId required');
        }

        // Registration teams (bracket_id IS NULL), with their identity participant row.
        // INNER JOIN through the live identity row (same tournament) so orphaned team rows
        // whose participant no longer exists never surface as phantom teams.
        $r = $this->db->query(
            "SELECT pt.team_id, pt.team_number, pt.participant_id, pt.name,
			        p.warrior_level, p.griffon_level
			 FROM " . DB_PREFIX . "participant_teams pt
			 JOIN " . DB_PREFIX . "participant p ON p.participant_id = pt.participant_id AND p.tournament_id = pt.tournament_id
			 WHERE pt.tournament_id = $tid AND pt.bracket_id IS NULL
			 ORDER BY pt.team_number"
        );
        $teams = [];
        $byNum = [];
        $teamIdToIdx = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $row = [
                    'TeamId'        => (int)$r->team_id,
                    'TeamNumber'    => (int)$r->team_number,
                    'ParticipantId' => (int)$r->participant_id,
                    'Name'          => $r->name,
                    'WarriorLevel'  => (int)$r->warrior_level,
                    'GriffonLevel'  => (int)$r->griffon_level,
                    'Members'       => [],
                    'Brackets'      => [],
                ];
                $teams[] = $row;
                $byNum[(int)$r->team_number] = count($teams) - 1;
                $teamIdToIdx[(int)$r->team_id] = count($teams) - 1;
            }
        }
        if (empty($teams)) {
            return Success([]);
        }

        // Members per registration team.
        $teamIds = array_map(fn ($t) => $t['TeamId'], $teams);
        $inIds   = implode(',', array_map('intval', $teamIds));
        $mids = [];
        $rosterByTeam = [];
        $mr = $this->db->query(
            "SELECT ptm.team_id, ptm.mundane_id, mn.persona, mpark.name AS park_name
			 FROM " . DB_PREFIX . "participant_team_members ptm
			 LEFT JOIN " . DB_PREFIX . "mundane mn ON mn.mundane_id = ptm.mundane_id
			 LEFT JOIN " . DB_PREFIX . "park mpark ON mpark.park_id = mn.park_id
			 WHERE ptm.team_id IN ($inIds)"
        );
        if ($mr && $mr->size() > 0) {
            while ($mr->next()) {
                $mid = (int)$mr->mundane_id;
                if ($mid > 0) {
                    $mids[$mid] = true;
                }
                $rosterByTeam[(int)$mr->team_id][] = [
                    'MundaneId' => $mid, 'Persona' => $mr->persona ?? '',
                    'ParkName' => $mr->park_name ?? '', 'WarriorLevel' => 0,
                ];
            }
        }
        $awards = !empty($mids) ? $this->fetchAwardsForMundanes(array_keys($mids)) : [];
        foreach ($rosterByTeam as $teamId => $mList) {
            foreach ($mList as $idx => $m) {
                $mid = $m['MundaneId'];
                $mList[$idx]['WarriorLevel'] = isset($awards[$mid]) ? $this->warriorLevelFromAwards($awards[$mid]) : 0;
            }
            if (isset($teamIdToIdx[$teamId])) {
                $teams[$teamIdToIdx[$teamId]]['Members'] = $mList;
            }
        }

        // Bracket assignments per team_number (non-null bracket team rows joined to bracket).
        $br = $this->db->query(
            "SELECT pt.team_number AS num, b.bracket_id AS bid, b.style AS style
			 FROM " . DB_PREFIX . "participant_teams pt
			 JOIN " . DB_PREFIX . "participant p ON p.participant_id = pt.participant_id AND p.tournament_id = pt.tournament_id
			 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = pt.bracket_id AND b.tournament_id = pt.tournament_id
			 WHERE pt.tournament_id = $tid AND pt.bracket_id IS NOT NULL"
        );
        if ($br && $br->size() > 0) {
            while ($br->next()) {
                $num = (int)$br->num;
                if (isset($byNum[$num])) {
                    $teams[$byNum[$num]]['Brackets'][] = ['BracketId' => (int)$br->bid, 'BracketStyle' => $br->style];
                }
            }
        }
        return Success($teams);
    }

    public function AssignTeamToBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid  = (int)($request['TournamentId'] ?? 0);
        $bid  = (int)($request['BracketId'] ?? 0);
        $nums = $request['TeamNumbers'] ?? [];
        if (!valid_id($tid) || !valid_id($bid)) {
            return InvalidParameter('TournamentId and BracketId required');
        }
        if (!is_array($nums) || empty($nums)) {
            return InvalidParameter('TeamNumbers required');
        }

        $b = $this->db->query("SELECT tournament_id, status, participants FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bid LIMIT 1");
        if (!$b || !$b->next() || (int)$b->tournament_id !== $tid) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }
        if ($b->participants !== 'team') {
            return InvalidParameter('This bracket is not a team bracket.');
        }
        if ($b->status !== 'setup') {
            return InvalidParameter('Teams can only be assigned while the bracket is in setup.');
        }

        $assigned = [];
        $this->db->query('START TRANSACTION');
        try {
            foreach ($nums as $num) {
                $num = (int)$num;
                if ($num <= 0) {
                    continue;
                }
                $ex = $this->db->query("SELECT team_id FROM " . DB_PREFIX . "participant_teams WHERE tournament_id=$tid AND bracket_id=$bid AND team_number=$num LIMIT 1");
                if ($ex && $ex->next() && valid_id($ex->team_id)) {
                    continue;
                }
                $src = $this->db->query("SELECT team_id, participant_id, name FROM " . DB_PREFIX . "participant_teams WHERE tournament_id=$tid AND team_number=$num AND bracket_id IS NULL LIMIT 1");
                if (!$src || !$src->next() || !valid_id($src->team_id)) {
                    continue;
                }
                $srcTeamId = (int)$src->team_id;
                $srcPid    = (int)$src->participant_id;
                $teamName  = $src->name;
                $this->db->query(
                    "INSERT INTO " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level, griffon_level)
					 SELECT tournament_id, $bid, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level, griffon_level
					 FROM " . DB_PREFIX . "participant WHERE participant_id = $srcPid"
                );
                $newPid = (int)$this->db->GetLastInsertId();
                if (!valid_id($newPid)) {
                    $this->db->query('ROLLBACK');
                    return InvalidParameter('Team assignment failed.');
                }
                // INSERT IGNORE + UNIQUE(tournament_id, bracket_id, team_number) makes the
                // check-then-insert atomic: if a concurrent request already assigned this
                // team, the collision inserts 0 rows — drop the orphan participant clone
                // created just above and skip cleanly.
                $tins = $this->db->query(
                    "INSERT IGNORE INTO " . DB_PREFIX . "participant_teams (tournament_id, bracket_id, participant_id, name, team_number)
					 VALUES (:tid, :bid, :pid, :name, :num)",
                    [':tid' => $tid, ':bid' => $bid, ':pid' => $newPid, ':name' => $teamName, ':num' => $num]
                );
                if (!$tins || (int)$tins->Size() < 1) {
                    $this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $newPid");
                    continue;
                }
                $newTeamId = (int)$this->db->GetLastInsertId();
                if (!valid_id($newTeamId)) {
                    $this->db->query('ROLLBACK');
                    return InvalidParameter('Team assignment failed.');
                }
                $rows = $this->db->query("SELECT mundane_id FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $srcTeamId");
                if ($rows && $rows->size() > 0) {
                    $mids = [];
                    while ($rows->next()) {
                        $mids[] = (int)$rows->mundane_id;
                    }
                    foreach ($mids as $mid) {
                        if (!valid_id($mid)) {
                            continue;
                        }
                        $this->db->query(
                            "INSERT IGNORE INTO " . DB_PREFIX . "participant_team_members (team_id, mundane_id, tournament_id) VALUES (:t, :m, :tid)",
                            [':t' => $newTeamId, ':m' => $mid, ':tid' => $tid]
                        );
                        $this->Player->clear();
                        $this->Player->participant_id = $newPid;
                        $this->Player->mundane_id     = $mid;
                        $this->Player->tournament_id  = $tid;
                        $this->Player->bracket_id     = $bid;
                        $this->Player->save();
                    }
                }
                $assigned[] = ['TeamNumber' => $num, 'TeamId' => $newTeamId, 'ParticipantId' => $newPid];
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        return Success(['Assigned' => $assigned]);
    }

    public function UnassignTeamFromBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid  = (int)($request['TournamentId'] ?? 0);
        $bid  = (int)($request['BracketId'] ?? 0);
        $nums = $request['TeamNumbers'] ?? [];
        if (!valid_id($tid) || !valid_id($bid)) {
            return InvalidParameter('TournamentId and BracketId required');
        }
        if (!is_array($nums) || empty($nums)) {
            return InvalidParameter('TeamNumbers required');
        }
        $b = $this->db->query("SELECT tournament_id, status FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bid LIMIT 1");
        if (!$b || !$b->next() || (int)$b->tournament_id !== $tid) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }
        if ($b->status !== 'setup') {
            return InvalidParameter('Teams can only be removed while the bracket is in setup.');
        }
        $this->db->query('START TRANSACTION');
        try {
            foreach ($nums as $num) {
                $num = (int)$num;
                if ($num <= 0) {
                    continue;
                }
                $rows = $this->db->query("SELECT team_id, participant_id FROM " . DB_PREFIX . "participant_teams WHERE tournament_id=$tid AND bracket_id=$bid AND team_number=$num");
                if ($rows && $rows->size() > 0) {
                    $pairs = [];
                    while ($rows->next()) {
                        $pairs[] = [(int)$rows->team_id, (int)$rows->participant_id];
                    }
                    foreach ($pairs as $pr) {
                        list($teamId, $pid) = $pr;
                        $this->db->query("DELETE FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $teamId");
                        $this->db->query("DELETE FROM " . DB_PREFIX . "participant_teams WHERE team_id = $teamId");
                        if (valid_id($pid)) {
                            $this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
                            $this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $pid AND tournament_id = $tid");
                        }
                    }
                }
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        return Success(true);
    }

    public function UpdateTeam($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid  = (int)($request['TournamentId'] ?? 0);
        $num  = (int)($request['TeamNumber'] ?? 0);
        $name = trim($request['Name'] ?? '');
        $members = is_array($request['Members'] ?? null) ? $request['Members'] : [];
        if (!valid_id($tid) || $num <= 0) {
            return InvalidParameter('TournamentId and TeamNumber required');
        }
        if ($name === '') {
            return InvalidParameter('Team name required');
        }
        // Roster edits blocked when the team is in a non-setup bracket; rename always allowed.
        $lock = $this->db->query(
            "SELECT COUNT(*) AS c FROM " . DB_PREFIX . "participant_teams pt
			 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = pt.bracket_id
			 WHERE pt.tournament_id = $tid AND pt.team_number = $num AND b.status <> 'setup'"
        );
        $locked = ($lock && $lock->next() && (int)$lock->c > 0);
        $this->db->query('START TRANSACTION');
        try {
            if ($locked) {
                $this->db->query("UPDATE " . DB_PREFIX . "participant_teams SET name = :n WHERE tournament_id = :t AND team_number = :num", [':n' => $name, ':t' => $tid, ':num' => $num]);
                $this->db->query(
                    "UPDATE " . DB_PREFIX . "participant p
					 JOIN " . DB_PREFIX . "participant_teams pt ON pt.participant_id = p.participant_id
					 SET p.alias = :n WHERE pt.tournament_id = :t AND pt.team_number = :num",
                    [':n' => $name, ':t' => $tid, ':num' => $num]
                );
                $this->db->query('COMMIT');
                $this->bustTournamentReportCache();
                return Success(['TeamNumber' => $num, 'RosterLocked' => true]);
            }
            // Not locked: re-run ensureTeam (updates name + replaces registration roster).
            $res = $this->ensureTeam($tid, ['Name' => $name, 'Members' => $members, 'TeamNumber' => $num]);
            // One-player-one-team violation: ensureTeam already rolled back — surface unchanged.
            // (Success is a bare array with no Status key; the error path is InvalidParameter()
            // with Status=4, so a present, non-zero Status is the reliable error discriminator.)
            if (isset($res['Status']) && (int)$res['Status'] !== 0) {
                return $res;
            }
            // Propagate name + roster to setup-bracket entrant team rows for this number.
            $setupRows = $this->db->query(
                "SELECT pt.team_id, pt.participant_id, pt.bracket_id FROM " . DB_PREFIX . "participant_teams pt
				 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = pt.bracket_id
				 WHERE pt.tournament_id = $tid AND pt.team_number = $num AND b.status = 'setup'"
            );
            $targets = [];
            if ($setupRows && $setupRows->size() > 0) {
                while ($setupRows->next()) {
                    $targets[] = [(int)$setupRows->team_id, (int)$setupRows->participant_id, (int)$setupRows->bracket_id];
                }
            }
            $srcMids = [];
            foreach ($members as $m) {
                $mid = (int)($m['MundaneId'] ?? 0);
                if (valid_id($mid)) {
                    $srcMids[] = $mid;
                }
            }
            foreach ($targets as $tg) {
                list($teamId, $pid, $bid) = $tg;
                $this->db->query("UPDATE " . DB_PREFIX . "participant SET alias = :n WHERE participant_id = :p", [':n' => $name, ':p' => $pid]);
                $this->db->query("UPDATE " . DB_PREFIX . "participant_teams SET name = :n WHERE team_id = :t", [':n' => $name, ':t' => $teamId]);
                $this->db->query("DELETE FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $teamId");
                $this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
                foreach ($srcMids as $mid) {
                    $this->db->query("INSERT IGNORE INTO " . DB_PREFIX . "participant_team_members (team_id, mundane_id, tournament_id) VALUES (:t,:m,:tid)", [':t' => $teamId, ':m' => $mid, ':tid' => $tid]);
                    $this->Player->clear();
                    $this->Player->participant_id = $pid;
                    $this->Player->mundane_id = $mid;
                    $this->Player->tournament_id = $tid;
                    $this->Player->bracket_id = $bid;
                    $this->Player->save();
                }
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        return Success(['TeamNumber' => $num, 'RosterLocked' => false]);
    }

    public function RemoveRegisteredTeam($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid = (int)($request['TournamentId'] ?? 0);
        $num = (int)($request['TeamNumber'] ?? 0);
        if (!valid_id($tid) || $num <= 0) {
            return InvalidParameter('TournamentId and TeamNumber required');
        }
        // Block if in any non-setup bracket.
        $lock = $this->db->query(
            "SELECT COUNT(*) AS c FROM " . DB_PREFIX . "participant_teams pt
			 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = pt.bracket_id
			 WHERE pt.tournament_id = $tid AND pt.team_number = $num AND b.status <> 'setup'"
        );
        if ($lock && $lock->next() && (int)$lock->c > 0) {
            return InvalidParameter('This team is in a bracket that has started. Remove it from that bracket first.');
        }
        $this->db->query('START TRANSACTION');
        try {
            $rows = $this->db->query("SELECT team_id, participant_id FROM " . DB_PREFIX . "participant_teams WHERE tournament_id=$tid AND team_number=$num");
            $pairs = [];
            if ($rows && $rows->size() > 0) {
                while ($rows->next()) {
                    $pairs[] = [(int)$rows->team_id, (int)$rows->participant_id];
                }
            }
            foreach ($pairs as $pr) {
                list($teamId, $pid) = $pr;
                $this->db->query("DELETE FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $teamId");
                $this->db->query("DELETE FROM " . DB_PREFIX . "participant_teams WHERE team_id = $teamId");
                if (valid_id($pid)) {
                    $this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
                    $this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $pid AND tournament_id = $tid");
                }
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        return Success(true);
    }

    /**
     * Tournament-level registration (individual). Creates the registration row
     * (bracket_id IS NULL) via ensureRegistrant; re-registering the same person
     * reuses the existing registration rather than creating a duplicate.
     */
    public function RegisterParticipant($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($tid)) {
            return InvalidParameter('TournamentId required');
        }
        $hasAlias   = strlen(trim($request['Alias'] ?? '')) > 0;
        $hasMundane = valid_id($request['MundaneId'] ?? 0);
        if (!$hasAlias && !$hasMundane) {
            return InvalidParameter('Registration requires an Alias or MundaneId');
        }
        $this->db->query('START TRANSACTION');
        try {
            $reg = $this->ensureRegistrant($tid, [
                'MundaneId' => (int)($request['MundaneId'] ?? 0),
                'Alias'     => $request['Alias'] ?? '',
                'UnitId'    => (int)($request['UnitId'] ?? 0),
                'ParkId'    => (int)($request['ParkId'] ?? 0),
                'KingdomId' => (int)($request['KingdomId'] ?? 0),
            ]);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        return Success($reg);
    }

    /**
     * Set a registrant's status to 'active' or 'withdrawn'. Updates every
     * participant row (registration + any bracket rows) sharing the number.
     */
    public function UpdateRegistrationStatus($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid    = (int)($request['TournamentId'] ?? 0);
        $num    = (int)($request['ParticipantNumber'] ?? 0);
        $status = $request['Status'] ?? '';
        if (!valid_id($tid) || $num <= 0) {
            return InvalidParameter('TournamentId and ParticipantNumber required');
        }
        if (!in_array($status, ['active', 'withdrawn'], true)) {
            return InvalidParameter('Invalid status');
        }
        $this->db->query(
            "UPDATE " . DB_PREFIX . "participant SET status = :s WHERE tournament_id = :t AND participant_number = :n",
            [':s' => $status, ':t' => $tid, ':n' => $num]
        );
        $this->bustTournamentReportCache();
        return Success(true);
    }

    /**
     * Remove a registrant entirely (registration row plus any bracket rows
     * sharing the participant_number). Blocked if the person is in any bracket
     * that has left 'setup' (active/complete/finalized) -- they must be removed
     * from that bracket first. Mirrors RemoveParticipant's multi-table cleanup
     * (deleteTeamRows + participant_mundane + participant) for each row.
     */
    public function RemoveRegistrant($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }
        $tid = (int)($request['TournamentId'] ?? 0);
        $num = (int)($request['ParticipantNumber'] ?? 0);
        if (!valid_id($tid) || $num <= 0) {
            return InvalidParameter('TournamentId and ParticipantNumber required');
        }

        // Block if the person is in any non-setup bracket.
        $lock = $this->db->query(
            "SELECT COUNT(*) AS c FROM " . DB_PREFIX . "participant p
			 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = p.bracket_id
			 WHERE p.tournament_id = $tid AND p.participant_number = $num AND b.status <> 'setup'"
        );
        if ($lock && $lock->next() && (int)$lock->c > 0) {
            return InvalidParameter('This participant is in a bracket that has started. Remove them from that bracket first.');
        }

        $this->db->query('START TRANSACTION');
        try {
            $pids = [];
            $rows = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE tournament_id = $tid AND participant_number = $num");
            if ($rows && $rows->size() > 0) {
                while ($rows->next()) {
                    $pids[] = (int)$rows->participant_id;
                }
            }
            foreach ($pids as $pid) {
                if (!valid_id($pid)) {
                    continue;
                }
                // Mirror RemoveParticipant cleanup for each row sharing this number.
                $this->deleteTeamRows('participant_id', $pid);
                $this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
                $this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $pid AND tournament_id = $tid");
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->bustTournamentReportCache();
        return Success(true);
    }


    public function DeleteTournament($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            return NoAuthorization();
        }

        $tournament_id = (int)$request['TournamentId'];
        $this->Tournament->clear();
        $this->Tournament->tournament_id = $tournament_id;
        if (!$this->Tournament->find()) {
            return InvalidParameter('Tournament not found.');
        }

        if (!$this->has_scope_edit($mundane_id, $this->Tournament->kingdom_id, $this->Tournament->park_id, $this->Tournament->event_id)) {
            return NoAuthorization();
        }

        // Cascade-delete child rows before removing the tournament itself
        $tid = (int)$tournament_id;
        $this->db->query('START TRANSACTION');
        try {
            $this->deleteTeamRows('tournament_id', $tid);
            // Bracket-scoped child rows (keyed by bracket_id, no tournament_id column) must
            // be removed while the parent bracket rows still exist to resolve the subquery.
            $bracketSub = '(SELECT bracket_id FROM ' . DB_PREFIX . 'bracket WHERE tournament_id = ' . $tid . ')';
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'point_score        WHERE bracket_id IN ' . $bracketSub);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'seed               WHERE bracket_id IN ' . $bracketSub);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'bracket_officiant  WHERE bracket_id IN ' . $bracketSub);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'match               WHERE tournament_id = ' . $tid);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'participant_mundane WHERE tournament_id = ' . $tid);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'participant         WHERE tournament_id = ' . $tid);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'bracket             WHERE tournament_id = ' . $tid);
            // Tournament-scoped live-collab + roster rows.
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'tournament_reeve    WHERE tournament_id = ' . $tid);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'tournament_event    WHERE tournament_id = ' . $tid);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'tournament_seq      WHERE tournament_id = ' . $tid);
            $this->Tournament->delete();
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();

        return Success($tournament_id);
    }

    public function GetMatches($request)
    {
        $where = $this->buildFilterWhere($request, 'm');

        // Participants linked to a player (a mundane) carry an empty participant.alias —
        // their real name lives in mundane.persona (mirrors GetParticipants' pm/m join).
        // COALESCE alias with the linked persona so match tables never show blanks.
        $sql = "SELECT m.*,
					COALESCE(NULLIF(p1.alias,''), mnd1.persona) AS participant1_alias,
					COALESCE(NULLIF(p2.alias,''), mnd2.persona) AS participant2_alias
				FROM " . DB_PREFIX . "match m
					LEFT JOIN " . DB_PREFIX . "participant p1 ON p1.participant_id = m.participant_1_id
						LEFT JOIN " . DB_PREFIX . "participant_mundane pm1 ON pm1.participant_id = p1.participant_id
							LEFT JOIN " . DB_PREFIX . "mundane mnd1 ON mnd1.mundane_id = pm1.mundane_id
					LEFT JOIN " . DB_PREFIX . "participant p2 ON p2.participant_id = m.participant_2_id
						LEFT JOIN " . DB_PREFIX . "participant_mundane pm2 ON pm2.participant_id = p2.participant_id
							LEFT JOIN " . DB_PREFIX . "mundane mnd2 ON mnd2.mundane_id = pm2.mundane_id
				WHERE 1 $where
				ORDER BY m.round, m.`order`";
        $r = $this->db->query($sql);
        $matches = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $matches[] = [
                    'MatchId'           => (int)$r->match_id,
                    'TournamentId'      => (int)$r->tournament_id,
                    'BracketId'         => (int)$r->bracket_id,
                    'Round'             => $r->round,
                    'Match'             => $r->match,
                    'Order'             => (int)$r->order,
                    'Participant1Id'    => (int)$r->participant_1_id,
                    'Participant2Id'    => (int)$r->participant_2_id,
                    'Participant1Alias' => $r->participant1_alias,
                    'Participant2Alias' => $r->participant2_alias,
                    'Result'            => $r->result,
                    'Score'             => $r->score,
                    'Bouts'             => $r->bouts,
                    'BracketSide'       => $r->bracket_side,
                    'RingNumber'        => (int)$r->ring_number,
                    'Voided'            => (int)$r->voided,
                ];
            }
        }
        return Success($matches);
    }

    // =========================================================================
    // Phase 3 — Bracket Generation & Match Result
    // =========================================================================

    /**
     * GenerateMatches($request)
     * Auth-checks, loads bracket+participants, dispatches to the appropriate
     * private algorithm, and marks the bracket status = 'active'.
     *
     * Request: Token, TournamentId, BracketId
     */
    public function GenerateMatches($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id    = (int)$request['BracketId'];
        $tournament_id = (int)$request['TournamentId'];
        if (!$this->bracketBelongsTo($bracket_id, $tournament_id)) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        // Load bracket
        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        if (!$this->Bracket->find()) {
            return InvalidParameter('Bracket not found');
        }

        // Load participants
        $pr = $this->GetParticipants(['BracketId' => $bracket_id, 'TournamentId' => $tournament_id]);
        if ($pr['Status'] != 0) {
            return $pr;
        }
        $participants = $pr['Detail'];
        $min_participants = ($this->Bracket->method === 'points') ? 1 : 2;
        if (count($participants) < $min_participants) {
            return InvalidParameter('Need at least ' . $min_participants . ' participant(s)');
        }
        if ($this->Bracket->method === 'double' && count($participants) < 3) {
            return InvalidParameter('Double elimination requires at least 3 participants');
        }

        // Guard: for team brackets, every team must have at least one member
        if ($this->Bracket->participants === 'team') {
            foreach ($participants as $tp) {
                if (empty($tp['Members'])) {
                    return InvalidParameter(null, 'Every team must have at least one member before generating.');
                }
            }
        }

        // Block re-generation if bracket is already active/complete to prevent data loss
        if (!in_array($this->Bracket->status, ['setup', '', null])) {
            return InvalidParameter('Cannot regenerate matches for an active bracket');
        }

        // Seeding
        $seeding = $this->Bracket->seeding;
        if ($seeding === 'manual' || $seeding === 'glicko2-manual') {
            usort($participants, function ($a, $b) {
                return (int)$a['Seed'] - (int)$b['Seed'];
            });
        } elseif ($seeding === 'warrior') {
            // Order of the Warrior seeding.
            // Teams: sorted by cumulative WarriorLevel descending (higher = seed 1).
            // Individuals: sorted by warrior_seed_rank() descending (12=Sword Knight, 0=unranked).
            if ($this->Bracket->participants === 'team') {
                usort($participants, fn ($a, $b) => (int)$b['WarriorLevel'] <=> (int)$a['WarriorLevel']);
            } else {
                usort($participants, function ($a, $b) {
                    return $this->warrior_seed_rank($b) - $this->warrior_seed_rank($a);
                });
            }
        } elseif ($seeding === 'glicko2') {
            // Performance Score seeding: order by the Glicko-2 rating snapshot, highest
            // rating = top seed. Glicko-2 ratings are stored per org unit (ork_glicko2 is
            // keyed by park/kingdom/unit/team, NOT per player), so individuals are seeded by
            // their park's Performance Score as the best available proxy. Any participant
            // without a rating falls back to a DETERMINISTIC seed order — never a shuffle —
            // so this mode can no longer silently randomize.
            $this->seedByPerformanceScore($participants);
        } else {
            // random, random-manual, and any unknown seeding mode: randomize
            shuffle($participants);
        }

        // Wrap the destructive DELETE + regeneration + status flip in a transaction so
        // a PHP fatal or concurrent call cannot leave the bracket destroyed mid-rebuild.
        $this->db->query('START TRANSACTION');
        try {
            // Re-check status under a row lock INSIDE the transaction: the setup check above
            // runs before the tx, so two concurrent Generates could both pass it and both
            // delete+rebuild. FOR UPDATE serializes them — the second blocks here until the
            // first commits, then sees status='active' and bails.
            $lockRow    = $this->db->query("SELECT status FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id FOR UPDATE");
            $lockStatus = ($lockRow && $lockRow->next()) ? (string)$lockRow->status : null;
            if (!in_array($lockStatus, ['setup', ''], true)) {
                $this->db->query('ROLLBACK');
                return InvalidParameter('Cannot regenerate matches for an active bracket');
            }

            // Delete any previously generated matches for this bracket
            $this->db->query("DELETE FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id");

            // #96: Re-freeze each individual entrant's warrior/griffon snapshot from current
            // awards at the moment seeding locks, using the same source as the rest of the
            // module (fetchAwardsForMundanes + warriorLevelFromAwards/griffonLevelFromAwards),
            // so standings/reports read an authoritative lock-time value rather than a stale
            // registration-time one. Teams keep their assign-time summed snapshot. This runs
            // after the in-memory seeding sort above, so it never alters seed order.
            if ($this->Bracket->participants !== 'team') {
                $genMids = [];
                foreach ($participants as $gp) {
                    $gmid = (int)($gp['MundaneId'] ?? 0);
                    if ($gmid > 0) {
                        $genMids[$gmid] = true;
                    }
                }
                if (!empty($genMids)) {
                    $genAwards = $this->fetchAwardsForMundanes(array_keys($genMids));
                    // Collect the frozen snapshot per participant, then flush it as a single
                    // CASE-based bulk UPDATE (chunked) rather than one round trip per entrant —
                    // a large field otherwise costs 128-256 sequential UPDATEs inside this tx.
                    $snap = [];
                    foreach ($participants as $gp) {
                        $gmid = (int)($gp['MundaneId'] ?? 0);
                        $gpid = (int)($gp['ParticipantId'] ?? 0);
                        if ($gmid <= 0 || $gpid <= 0) {
                            continue;
                        }
                        $snap[$gpid] = [
                            'wl' => isset($genAwards[$gmid]) ? (int)$this->warriorLevelFromAwards($genAwards[$gmid]) : 0,
                            'gl' => isset($genAwards[$gmid]) ? (int)$this->griffonLevelFromAwards($genAwards[$gmid]) : 0,
                        ];
                    }
                    // participant_id keys and level values are all (int)-cast above, so inlining
                    // them here is injection-safe (mysql_real_escape_string is a no-op shim).
                    foreach (array_chunk($snap, 200, true) as $chunk) {
                        $wlCase = '';
                        $glCase = '';
                        $ids    = [];
                        foreach ($chunk as $pid => $lv) {
                            $pid     = (int)$pid;
                            $wlCase .= " WHEN $pid THEN " . (int)$lv['wl'];
                            $glCase .= " WHEN $pid THEN " . (int)$lv['gl'];
                            $ids[]   = $pid;
                        }
                        $this->db->query(
                            "UPDATE " . DB_PREFIX . "participant
							 SET warrior_level = CASE participant_id$wlCase ELSE warrior_level END,
							     griffon_level = CASE participant_id$glCase ELSE griffon_level END
							 WHERE participant_id IN (" . implode(',', $ids) . ")"
                        );
                    }
                }
            }

            // Dispatch on method (bracket format: single, double, swiss, round-robin, ironman)
            $method = $this->Bracket->method;
            $rings  = max(1, (int)$this->Bracket->rings);

            if ($method === 'single') {
                $this->generate_single_elim($bracket_id, $tournament_id, $participants);
            } elseif ($method === 'double') {
                $this->generate_double_elim($bracket_id, $tournament_id, $participants);
            } elseif ($method === 'swiss') {
                $this->generate_swiss($bracket_id, $tournament_id, $participants, $rings);
            } elseif ($method === 'round-robin') {
                $this->generate_round_robin($bracket_id, $tournament_id, $participants);
            } elseif ($method === 'ironman') {
                $this->generate_ironman($bracket_id, $tournament_id, $participants, $rings);
            } elseif ($method === 'points') {
                $this->generate_points($bracket_id, $tournament_id, $participants);
            } else {
                // unknown: single elim as fallback
                $this->generate_single_elim($bracket_id, $tournament_id, $participants);
            }

            // Mark bracket active — conditional on it still being in setup. 0 affected rows means
            // a concurrent writer moved it out of setup after our lock check (belt-and-suspenders
            // against the FOR UPDATE above); treat that as a conflict and roll back.
            $flip = $this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'active' WHERE bracket_id = $bracket_id AND status IN ('setup','')");
            if (!$flip || $flip->size() === 0) {
                $this->db->query('ROLLBACK');
                return InvalidParameter('Cannot regenerate matches for an active bracket');
            }

            $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
            $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'matches_generated', [
                'bracket_id' => $bracket_id,
            ], $actor_id, $action_id !== '' ? $action_id : null);

            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tournament_id, $seq);
        return Success($bracket_id);
    }

    /**
     * PostMatchResult($request)
     * Records a match result, advances winner to next round slot, routes loser
     * in double-elim, marks participants eliminated, and checks bracket completion.
     *
     * Request: Token, TournamentId, MatchId, Score, and Result — one of:
     *   1-wins | 2-wins | tie | forfeit | disqualified |
     *   1-forfeits | 2-forfeits | 1-is-disqualified | 2-is-disqualified
     * (directional forfeit/DQ forms are resolved by resolveWinnerLoser).
     */
    public function PostMatchResult($request)
    {
        if (!$this->can_run_brackets($request)) {
            return NoAuthorization();
        }

        $match_id      = (int)$request['MatchId'];
        $tournament_id = (int)$request['TournamentId'];
        $result        = trim($request['Result'] ?? '');
        $score         = substr(trim($request['Score']  ?? ''), 0, 64);
        $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
        $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');

        if (!in_array($result, ['1-wins', '2-wins', 'tie', 'forfeit', 'disqualified', '1-forfeits', '2-forfeits', '1-is-disqualified', '2-is-disqualified'], true)) {
            return InvalidParameter('Invalid result value');
        }

        // Load match
        $sql = "SELECT * FROM " . DB_PREFIX . "match WHERE match_id = $match_id AND tournament_id = $tournament_id";
        $r = $this->db->query($sql);
        if (!$r || $r->size() == 0) {
            return InvalidParameter('Match not found');
        }
        $r->next();
        $bracket_id    = (int)$r->bracket_id;

        // Block results on finalized brackets; capture method for the tie guard below.
        $bstat = $this->db->query("SELECT status, method FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id");
        $bracket_method = '';
        if ($bstat && $bstat->next()) {
            if ($bstat->status === 'finalized') {
                return InvalidParameter('Cannot record results on a finalized bracket');
            }
            $bracket_method = (string)$bstat->method;
        }

        // A tie leaves no winner to advance, so it is only valid where advancement does not
        // depend on a winner (round-robin/swiss/points). Reject it in single/double/ironman
        // elimination — a backstop for any UI path that still offers Tie.
        if ($result === 'tie' && !in_array($bracket_method, ['round-robin', 'swiss', 'points'], true)) {
            return InvalidParameter('A tie cannot be recorded in an elimination bracket — it would leave no winner to advance.');
        }

        $p1_id         = (int)$r->participant_1_id;
        $p2_id         = (int)$r->participant_2_id;
        $round         = (int)$r->round;
        $match_num     = (int)$r->match;
        $order         = (int)$r->order;
        $bracket_side  = $r->bracket_side;

        if ($p1_id > 0 && $p2_id > 0 && $p1_id === $p2_id) {
            return InvalidParameter('Invalid match: same participant on both sides');
        }

        // Verify neither participant is withdrawn or disqualified
        $active_pids = array_filter([$p1_id, $p2_id], fn ($x) => $x > 0);
        if (!empty($active_pids)) {
            $pid_list = implode(',', $active_pids);
            $status_r = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE participant_id IN ($pid_list) AND status NOT IN ('active', '')");
            if ($status_r && $status_r->next()) {
                return InvalidParameter('Cannot record result: a participant is withdrawn or disqualified');
            }
        }

        // Determine winner/loser
        [$winner_id, $loser_id] = $this->resolveWinnerLoser($result, $p1_id, $p2_id);
        // Ties produce [0,0] — advancement logic below is guarded by $winner_id > 0 / $loser_id > 0,
        // so no participants will be advanced or eliminated for a tie result.

        // Sanitize and store bout series
        $bouts_raw = trim($request['Bouts'] ?? '');
        $bouts_arr = json_decode($bouts_raw, true);
        if (!is_array($bouts_arr)) {
            $bouts_arr = [];
        }
        $bouts_count_pre = count($bouts_arr);
        $bouts_arr = array_values(array_filter(array_map(function ($b) {
            return ($b === '1' || $b === '2') ? $b : null;
        }, $bouts_arr)));
        // Reject input that contained bout entries but none were valid '1'/'2' values.
        // An explicitly empty array ([]) is still accepted — it just records no bouts.
        if ($bouts_count_pre > 0 && count($bouts_arr) === 0) {
            return InvalidParameter('Bouts data contained no valid entries');
        }

        // Concurrency guard: only apply the update when no result is set yet.
        // rowCount() (exposed via size()) returns affected rows for UPDATE statements,
        // so a 0 return means another request already recorded a result for this match.
        $this->db->query('START TRANSACTION');
        try {
            $upd = $this->db->query(
                "UPDATE " . DB_PREFIX . "match SET result = :result, score = :score, bouts = :bouts WHERE match_id = :match_id AND (result IS NULL OR result = '')",
                [':result' => $result, ':score' => $score, ':bouts' => json_encode($bouts_arr) ?: '[]', ':match_id' => $match_id]
            );
            if (!$upd || (int)$upd->size() === 0) {
                $this->db->query('ROLLBACK');
                return InvalidParameter('Match result has already been recorded');
            }

            // Run the shared bracket-advancement engine for the recorded result.
            $advErr = $this->applyAdvancement($bracket_id, $tournament_id, $match_id);
            if ($advErr !== null) {
                $this->db->query('ROLLBACK');
                return InvalidParameter($advErr);
            }

            // Elimination/Swiss: cascade walkovers when an advancement lands a player
            // opposite a withdrawn participant.
            $bm      = $this->db->query("SELECT method FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id LIMIT 1");
            $bmethod = ($bm && $bm->next()) ? (string)$bm->method : '';
            if (in_array($bmethod, ['single', 'double', 'swiss'], true)) {
                $wErr = $this->resolveEliminationWalkovers($bracket_id, $tournament_id);
                if ($wErr !== null) {
                    $this->db->query('ROLLBACK');
                    return InvalidParameter($wErr);
                }
            }

            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'match_result', [
                'match_id' => $match_id,
                'result'   => $result,
                'score'    => $score,
            ], $actor_id, $action_id !== '' ? $action_id : null);

            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->tnPublishSeq($tournament_id, $seq);
        return Success(['MatchId' => $match_id, 'Seq' => $seq]);
    }

    /**
     * applyAdvancement($bracket_id, $tournament_id, $match_id)
     * Runs winner advancement, loser routing/elimination, bracket completion, and
     * Swiss next-round population for a match that ALREADY has a result recorded.
     * Shared by PostMatchResult and resolveEliminationWalkovers so the bracket engine
     * lives in one place. Returns null on success, or an error-message string on a
     * double-elimination routing failure (caller should ROLLBACK and surface it).
     * Assumes it runs inside a transaction managed by the caller.
     */
    private function applyAdvancement($bracket_id, $tournament_id, $match_id)
    {
        $mr = $this->db->query("SELECT participant_1_id, participant_2_id, round, `match`, bracket_side, result
			FROM " . DB_PREFIX . "match WHERE match_id = $match_id LIMIT 1");
        if (!$mr || !$mr->next()) {
            return null;
        }
        $p1_id        = (int)$mr->participant_1_id;
        $p2_id        = (int)$mr->participant_2_id;
        $round        = (int)$mr->round;
        $match_num    = (int)$mr->match;
        $bracket_side = (string)$mr->bracket_side;
        $result       = (string)$mr->result;

        [$winner_id, $loser_id] = $this->resolveWinnerLoser($result, $p1_id, $p2_id);

        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        $this->Bracket->find();
        $method = $this->Bracket->method;

        $wr1_r     = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id AND bracket_side = 'winners' AND round = 1");
        $wr1_count = ($wr1_r && $wr1_r->next()) ? (int)$wr1_r->cnt : 1;
        $wr_rounds = (int)round(log($wr1_count * 2, 2)); // slots = wr1_count*2

        // -- Winners bracket advancement --
        if ($winner_id > 0 && ($method === 'single' || $method === 'double') && $bracket_side === 'winners') {
            $max_wr_r   = $this->db->query("SELECT MAX(round) AS r FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id AND bracket_side = 'winners'");
            $max_wr_rnd = ($max_wr_r && $max_wr_r->next()) ? (int)$max_wr_r->r : 0;
            if ($round < $max_wr_rnd) {
                $next_round = $round + 1;
                $next_match = (int)ceil($match_num / 2);
                $next_slot  = ($match_num % 2 === 1) ? 'participant_1_id' : 'participant_2_id';
                $this->db->query("UPDATE " . DB_PREFIX . "match
					SET $next_slot = $winner_id
					WHERE bracket_id = $bracket_id AND round = $next_round AND `match` = $next_match AND bracket_side = 'winners'");
            } elseif ($method === 'double') {
                $this->db->query("UPDATE " . DB_PREFIX . "match
					SET participant_1_id = $winner_id
					WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final'");
            }
        }

        // -- WR loser â Losers bracket --
        if ($method === 'double' && $loser_id > 0 && $bracket_side === 'winners') {
            if ($round === 1) {
                $half = (int)($wr1_count / 2);
                if ($match_num <= $half) {
                    $lr_match = $match_num;
                    $lr_slot  = 'participant_1_id';
                } else {
                    $lr_match = $wr1_count - $match_num + 1;
                    $lr_slot  = 'participant_2_id';
                }
                $slot_chk_1 = $this->db->query("SELECT match_id, result FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id AND round = 1 AND `match` = $lr_match AND bracket_side = 'losers'");
                if (!$slot_chk_1 || !$slot_chk_1->next()) {
                    return "Double-elimination routing error: no losers bracket slot found for round 1 match $lr_match";
                }
                // Never overwrite an already-resolved LB match: re-recording a reset winners
                // match would otherwise change a played LB match's entrant and dangle its prior
                // occupant. Block with an explicit routing-conflict error instead.
                if ($slot_chk_1->result !== null && $slot_chk_1->result !== '') {
                    return "Double-elimination routing conflict: losers bracket round 1 match $lr_match already has a recorded result. Reset that match before re-recording this one.";
                }
                $this->db->query("UPDATE " . DB_PREFIX . "match
					SET $lr_slot = $loser_id
					WHERE bracket_id = $bracket_id AND round = 1 AND `match` = $lr_match AND bracket_side = 'losers' AND (result IS NULL OR result = '')");
            } else {
                $lb_round         = ($round - 1) * 2;
                $lb_round_matches = max(1, (int)($wr1_count / pow(2, $round - 1)));
                $lr_match         = max(1, $lb_round_matches - $match_num + 1);
                $slot_chk_2 = $this->db->query("SELECT match_id, result FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id AND round = $lb_round AND `match` = $lr_match AND bracket_side = 'losers'");
                if (!$slot_chk_2 || !$slot_chk_2->next()) {
                    return "Double-elimination routing error: no losers bracket slot found for round $lb_round match $lr_match";
                }
                // Never overwrite an already-resolved LB match (see round-1 branch above).
                if ($slot_chk_2->result !== null && $slot_chk_2->result !== '') {
                    return "Double-elimination routing conflict: losers bracket round $lb_round match $lr_match already has a recorded result. Reset that match before re-recording this one.";
                }
                $this->db->query("UPDATE " . DB_PREFIX . "match
					SET participant_2_id = $loser_id
					WHERE bracket_id = $bracket_id AND round = $lb_round AND `match` = $lr_match AND bracket_side = 'losers' AND (result IS NULL OR result = '')");
            }
        }

        // -- LB winner advancement --
        if ($method === 'double' && $winner_id > 0 && $bracket_side === 'losers') {
            $lb_total_rounds = ($wr_rounds - 1) * 2;
            if ($round < $lb_total_rounds) {
                if ($round % 2 === 1) {
                    $this->db->query("UPDATE " . DB_PREFIX . "match
						SET participant_1_id = $winner_id
						WHERE bracket_id = $bracket_id AND round = " . ($round + 1) . " AND `match` = $match_num AND bracket_side = 'losers'");
                } else {
                    $next_match = (int)ceil($match_num / 2);
                    $next_slot  = ($match_num % 2 === 1) ? 'participant_1_id' : 'participant_2_id';
                    $this->db->query("UPDATE " . DB_PREFIX . "match
						SET $next_slot = $winner_id
						WHERE bracket_id = $bracket_id AND round = " . ($round + 1) . " AND `match` = $next_match AND bracket_side = 'losers'");
                }
            } else {
                $this->db->query("UPDATE " . DB_PREFIX . "match
					SET participant_2_id = $winner_id
					WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final'");
            }
        }

        // -- Grand Final: Second-Chance (LB champ) wins GF1 → mandatory bracket reset --
        // The Grand Final is stored as round 1 with participant_1 = winners-bracket champ,
        // participant_2 = losers-bracket champ. If the LB champ wins GF1 the WB champ has
        // only taken their FIRST loss, so the tournament is NOT over: auto-create the reset
        // match (GF2, round 2, same participants) and clear the WB champ's premature
        // elimination flag. Finalization happens only when GF2 (the true final) is decided.
        $gf_reset = false;
        if ($method === 'double' && $bracket_side === 'grand-final' && $round === 1
            && $loser_id > 0 && $loser_id === $p1_id) {
            $gf_reset = true;
            $exists = $this->db->query("SELECT match_id FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final' AND round = 2 LIMIT 1");
            if (!$exists || !$exists->next()) {
                $maxOrd = $this->db->query("SELECT MAX(`order`) AS m FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id");
                $next_order = ($maxOrd && $maxOrd->next() && $maxOrd->m !== null) ? (int)$maxOrd->m + 1 : 1;
                $this->insert_match($bracket_id, $tournament_id, 2, 1, $next_order, $p1_id, $p2_id, 'grand-final');
            }
            // WB champ is still alive for GF2 — undo any prior/premature elimination.
            $this->db->query("UPDATE " . DB_PREFIX . "participant SET eliminated = 0 WHERE participant_id = $p1_id");
        }

        // -- Eliminations --
        $shouldEliminate = !$gf_reset && $loser_id > 0 && (
            $method === 'single' ||
            ($method === 'double' && in_array($bracket_side, ['losers', 'grand-final']))
        );
        if ($shouldEliminate) {
            $this->db->query("UPDATE " . DB_PREFIX . "participant SET eliminated = 1 WHERE participant_id = $loser_id");
        }

        // Check if all matches resolved â mark bracket complete
        // For a DOUBLE elimination the "both participants > 0" guard below is dangerous: when the
        // winners-bracket final is recorded the grand final and remaining losers-bracket matches are
        // still feeder-pending (a slot = 0), so they'd be skipped and the bracket would complete
        // before the losers bracket + grand final are ever played. Gate double-elim completion on
        // the grand final instead — done only when a grand-final match exists and none is pending.
        $markComplete = false;
        if ($method === 'double') {
            $gfPending = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final' AND (result IS NULL OR result = '') AND voided = 0");
            $gfTotal = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final' AND voided = 0");
            $noGfPending = ($gfPending && $gfPending->next() && (int)$gfPending->cnt === 0);
            $hasGf = ($gfTotal && $gfTotal->next() && (int)$gfTotal->cnt > 0);
            $markComplete = $hasGf && $noGfPending;
        } else {
            $unresolved = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0 AND voided = 0");
            $markComplete = ($unresolved && $unresolved->next() && (int)$unresolved->cnt === 0);
        }
        if ($markComplete) {
            $this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'complete' WHERE bracket_id = $bracket_id AND status != 'finalized' AND method != 'swiss'");
        }

        // Swiss: when all real matches in this round are done, populate the next round's pairings
        if ($method === 'swiss') {
            $unresolved_cur = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND round = $round
				  AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0");
            if ($unresolved_cur && $unresolved_cur->next() && (int)$unresolved_cur->cnt === 0) {
                // The last configured round is the highest generated round (generate_swiss
                // creates every round up front: rings, or ceil(log2 N) when rings <= 1).
                // Once it is fully resolved the swiss bracket is complete.
                $maxR = $this->db->query("SELECT MAX(round) AS mr FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id");
                $lastRound = ($maxR && $maxR->next()) ? (int)$maxR->mr : 0;
                // A freshly paired round can hold no real match (withdrawals leave only the
                // bye's auto-win); no result entry would ever re-trigger this check, so keep
                // advancing until a round needs play or the last round is resolved.
                $cur = $round;
                while (true) {
                    if ($cur >= $lastRound) {
                        $this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'complete' WHERE bracket_id = $bracket_id AND status != 'finalized'");
                        break;
                    }
                    $cur++;
                    $this->populate_swiss_round($bracket_id, $tournament_id, $cur);
                    $nr = $this->db->query("SELECT
						SUM(CASE WHEN participant_1_id > 0 OR participant_2_id > 0 THEN 1 ELSE 0 END) AS filled,
						SUM(CASE WHEN (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0 THEN 1 ELSE 0 END) AS open
						FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id AND round = $cur");
                    if (!($nr && $nr->next()) || (int)$nr->filled === 0 || (int)$nr->open > 0) {
                        break;
                    }
                }
            }
        }

        return null;
    }

    /**
     * resolveEliminationWalkovers($bracket_id, $tournament_id)
     * For single/double-elim and Swiss: repeatedly finds a pending match where exactly
     * one participant is non-active (withdrawn/disqualified) and the other is active,
     * and auto-resolves it as a forfeit win for the active opponent (auto_resolved=1),
     * running the shared advancement engine. The loop re-scans after each resolution so
     * walkovers cascade forward (e.g. when an advancing player lands opposite a withdrawn
     * participant in a later round). Returns null on success or an error string.
     * Assumes it runs inside a transaction managed by the caller.
     */
    private function resolveEliminationWalkovers($bracket_id, $tournament_id)
    {
        $bm      = $this->db->query("SELECT method FROM " . DB_PREFIX . "match m JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = m.bracket_id WHERE m.bracket_id = $bracket_id LIMIT 1");
        $method  = ($bm && $bm->next()) ? (string)$bm->method : '';
        $isElim  = in_array($method, ['single', 'double'], true);
        $guard = 0;
        while ($guard++ < 500) {
            // Wave scan: collect EVERY currently-resolvable walkover (a pending match with
            // both participants known where exactly one is non-active) in a single pass, then
            // resolve + advance them all before rescanning. These matches never feed each other
            // within a wave (a match with both slots filled cannot receive a winner advanced
            // from another match this wave), so a single scan per wave — rather than one scan
            // per resolution — is safe and cuts the scan count from O(matches) to O(rounds).
            $r = $this->db->query("SELECT m.match_id, p1.status AS s1, p2.status AS s2
				FROM " . DB_PREFIX . "match m
				LEFT JOIN " . DB_PREFIX . "participant p1 ON p1.participant_id = m.participant_1_id
				LEFT JOIN " . DB_PREFIX . "participant p2 ON p2.participant_id = m.participant_2_id
				WHERE m.bracket_id = $bracket_id
				  AND (m.result IS NULL OR m.result = '')
				  AND m.voided = 0
				  AND m.participant_1_id > 0 AND m.participant_2_id > 0
				ORDER BY m.`order` ASC
				LIMIT 300");
            $targets = [];
            if ($r) {
                while ($r->next()) {
                    $s1 = (string)$r->s1;
                    $s2 = (string)$r->s2;
                    $w1 = !($s1 === 'active' || $s1 === '');
                    $w2 = !($s2 === 'active' || $s2 === '');
                    if ($w1 xor $w2) {
                        $targets[] = ['mid' => (int)$r->match_id, 'side' => $w1 ? 1 : 2];
                    }
                }
            }
            if (!empty($targets)) {
                foreach ($targets as $t) {
                    // Opponent of the withdrawn participant wins by forfeit.
                    $res = ($t['side'] === 1) ? '2-wins' : '1-wins';
                    $this->db->query("UPDATE " . DB_PREFIX . "match SET result = '$res', auto_resolved = 1
						WHERE match_id = " . $t['mid'] . " AND (result IS NULL OR result = '')");
                    $advErr = $this->applyAdvancement($bracket_id, $tournament_id, $t['mid']);
                    if ($advErr !== null) {
                        return $advErr;
                    }
                }
                continue;
            }

            // Bye cascade (single/double-elim only): resolve/void byes that surface during
            // play — e.g. a winners-bracket loser routed into a losers slot whose opposite
            // slot was fed by a bye. We only ever touch the SMALLEST-`order` unresolved match:
            // since every feeder has a smaller order, a still-fillable empty slot is never at
            // the frontier, so this can never prematurely resolve a genuinely pending match.
            if ($isElim) {
                $fr = $this->db->query("SELECT match_id, participant_1_id, participant_2_id FROM " . DB_PREFIX . "match
					WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND voided = 0
					ORDER BY `order` ASC LIMIT 1");
                if ($fr && $fr->next()) {
                    $fmid = (int)$fr->match_id;
                    $fp1  = (int)$fr->participant_1_id;
                    $fp2  = (int)$fr->participant_2_id;
                    if ($fp1 > 0 xor $fp2 > 0) {
                        // One real participant + a permanent bye: walkover the real side forward.
                        $res = ($fp1 > 0) ? '1-wins' : '2-wins';
                        $this->db->query("UPDATE " . DB_PREFIX . "match SET result = '$res', auto_resolved = 1
							WHERE match_id = $fmid AND (result IS NULL OR result = '')");
                        $advErr = $this->applyAdvancement($bracket_id, $tournament_id, $fmid);
                        if ($advErr !== null) {
                            return $advErr;
                        }
                        continue;
                    }
                    if ($fp1 === 0 && $fp2 === 0) {
                        // Phantom match: both feeders were byes, so it can never be contested. Void
                        // it so it neither blocks completion nor advances a nonexistent winner.
                        $this->db->query("UPDATE " . DB_PREFIX . "match SET voided = 1
							WHERE match_id = $fmid AND (result IS NULL OR result = '')");
                        continue;
                    }
                }
            }
            break;
        }
        return null;
    }

    /**
     * reverseEliminationWithdrawal($bracket_id, $tournament_id, $participant_id, $request)
     * Single-level undo for an elimination/Swiss reactivation: resets each auto-resolved
     * walkover this participant forfeited (reversing the opponent's advancement via
     * ResetMatch). Returns null on success, or an error string if a downstream match has
     * already been played (organizer must Reset those first). Runs in the caller's transaction.
     */
    private function reverseEliminationWithdrawal($bracket_id, $tournament_id, $participant_id, $request)
    {
        $r = $this->db->query("SELECT match_id FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id AND auto_resolved = 1
			  AND (result IS NOT NULL AND result != '')
			  AND (participant_1_id = $participant_id OR participant_2_id = $participant_id)
			ORDER BY round DESC, `match` DESC");
        $mids = [];
        if ($r) {
            while ($r->next()) {
                $mids[] = (int)$r->match_id;
            }
        }
        foreach ($mids as $mid) {
            // Call the internal reset directly: the actor was already authorized by the
            // UpdateParticipantStatus caller, so re-running the full auth chain per match
            // (via the public ResetMatch) is pure overhead here.
            $resp = $this->resetMatchInternal($mid, (int)$tournament_id, [
                'Token'        => $request['Token'] ?? '',
                'TournamentId' => $tournament_id,
            ]);
            if (!is_array($resp) || ($resp['Status'] ?? 1) != 0) {
                return 'Cannot reactivate: a match this participant forfeited has downstream results. Reset those matches first, then reactivate.';
            }
            $this->db->query("UPDATE " . DB_PREFIX . "match SET auto_resolved = 0 WHERE match_id = $mid");
        }
        return null;
    }

    /**
     * ResetMatch($request)
     * Clears a match result and reverses all downstream effects (winner advancement,
     * loser elimination, bracket completion). Blocked if any downstream match has
     * already been played.
     *
     * Request: Token, TournamentId, MatchId
     */
    public function ResetMatch($request)
    {
        if (!$this->can_run_brackets($request)) {
            return NoAuthorization();
        }

        $match_id      = (int)($request['MatchId']      ?? 0);
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($match_id) || !valid_id($tournament_id)) {
            return InvalidParameter('MatchId and TournamentId required');
        }

        // Delegate the actual reset. reverseEliminationWithdrawal calls resetMatchInternal
        // directly (bypassing this method), so the single change-log emit below fires only
        // for a user-initiated reset — the walkover-reversal loop never double-emits here.
        $resp = $this->resetMatchInternal($match_id, $tournament_id, $request);
        if (!is_array($resp) || (int)($resp['Status'] ?? 1) !== 0) {
            return $resp;
        }

        $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
        $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');

        $br = $this->db->query("SELECT bracket_id FROM " . DB_PREFIX . "match WHERE match_id = $match_id AND tournament_id = $tournament_id");
        $bracket_id = ($br && $br->next()) ? (int)$br->bracket_id : 0;

        // tnEmitEvent's increment-then-read of the seq cursor must run inside a transaction,
        // or two concurrent callers can read the same seq (see SaveMatchResult).
        $this->db->query('START TRANSACTION');
        try {
            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'match_reset', [
                'match_id' => $match_id,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->tnPublishSeq($tournament_id, $seq);
        return Success(['MatchId' => $match_id, 'Seq' => $seq]);
    }

    /**
     * Core match-reset logic WITHOUT the can_run_brackets auth gate. Callable from
     * ResetMatch (after auth) and from already-authorized internal flows such as
     * reverseEliminationWithdrawal, which resets many walkover matches in a loop and
     * would otherwise re-run the full auth chain once per match. The optional $request
     * only carries the Token used by the privileged finalized-bracket re-open path.
     */
    private function resetMatchInternal(int $match_id, int $tournament_id, array $request = [])
    {
        // Load match
        $sql = "SELECT * FROM " . DB_PREFIX . "match WHERE match_id = $match_id AND tournament_id = $tournament_id";
        $r = $this->db->query($sql);
        if (!$r || $r->size() == 0) {
            return InvalidParameter('Match not found');
        }
        $r->next();

        $bracket_id   = (int)$r->bracket_id;
        $round        = (int)$r->round;
        $match_num    = (int)$r->match;
        $bracket_side = $r->bracket_side;
        $_allowed_sides = ['winners','losers','grand-final','tiebreaker','tiebreaker-3rd',''];
        if (!in_array($bracket_side, $_allowed_sides, true)) {
            $bracket_side = 'winners';
        }
        $result       = $r->result;
        $p1_id        = (int)$r->participant_1_id;
        $p2_id        = (int)$r->participant_2_id;

        if ($result === null || $result === '') {
            return InvalidParameter('Match has no result to reset');
        }

        // Determine winner/loser from current result
        [$winner_id, $loser_id] = $this->resolveWinnerLoser($result, $p1_id, $p2_id);
        // Ties produce [0,0] — reversal logic below is guarded by $winner_id > 0 / $loser_id > 0,
        // so no advancement or elimination is reversed for a tie result.

        // Load bracket method
        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        $this->Bracket->find();
        if (!$this->Bracket->bracket_id) {
            return InvalidParameter('Bracket not found');
        }
        $method = $this->Bracket->method;

        // A *finalized* bracket is only re-opened by a reset through the stronger
        // organizer-level gate (finalize is an explicit, privileged step). The
        // run-brackets gate at the top is not enough on its own — block the reset
        // unless the caller also holds edit authority, and audit the re-open below.
        $wasFinalized = ((string)$this->Bracket->status === 'finalized');
        if ($wasFinalized && !$this->check_auth($request)) {
            return NoAuthorization();
        }

        // Check: no later-round match on the same bracket_side involving either participant
        // may already be resolved. Scoping to the same bracket_side ensures (e.g.) a WR2
        // reset isn't falsely blocked by an LB2 result — the LB-loser-slot clearing below
        // guards LB matches that the routed loser has not yet played.
        if ($p1_id > 0 || $p2_id > 0) {
            $ids = implode(',', array_filter([$p1_id, $p2_id]));
            $check = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND round > $round AND bracket_side = '$bracket_side'
				  AND (result IS NOT NULL AND result != '')
				  AND (participant_1_id IN ($ids) OR participant_2_id IN ($ids))");
            if ($check && $check->next() && (int)$check->cnt > 0) {
                return InvalidParameter('Cannot reset: a downstream match has already been played');
            }
        }

        // Grand Final guard (double-elim): the same-bracket_side check above never sees the
        // cross-side Grand Final that a WR/LB participant was routed into. Resetting an early
        // WR/LB match after either participant already PLAYED the Grand Final would orphan a
        // GF slot. Block the reset unless the reset match IS the Grand Final (handled by the
        // same-side round>round check) so the organizer resets the Grand Final first.
        if ($method === 'double' && $bracket_side !== 'grand-final' && ($p1_id > 0 || $p2_id > 0)) {
            $gfIds = implode(',', array_filter([$p1_id, $p2_id]));
            $gf_chk = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final'
				  AND (result IS NOT NULL AND result != '')
				  AND (participant_1_id IN ($gfIds) OR participant_2_id IN ($gfIds))");
            if ($gf_chk && $gf_chk->next() && (int)$gf_chk->cnt > 0) {
                return InvalidParameter('Cannot reset: the Grand Final involving this participant has already been played. Reset the Grand Final first.');
            }
        }

        // Block reset of a regular (winners) match once a tiebreaker round has been played —
        // the tiebreaker must be reset first or its results would be orphaned.
        if ($bracket_side === 'winners') {
            $tb_chk = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND bracket_side = 'tiebreaker' AND (result IS NOT NULL AND result != '')");
            if ($tb_chk && $tb_chk->next() && (int)$tb_chk->cnt > 0) {
                return InvalidParameter('Cannot reset — a tiebreaker round has already been played; reset the tiebreaker first.');
            }
        }

        // Clear match result
        $this->db->query("UPDATE " . DB_PREFIX . "match SET result = NULL, score = NULL, bouts = ''  WHERE match_id = $match_id");

        // Reverse advancement/elimination for elim brackets
        // Bracket size from WR round 1 match count
        $wr1_r     = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id AND bracket_side = 'winners' AND round = 1");
        $wr1_count = ($wr1_r && $wr1_r->next()) ? (int)$wr1_r->cnt : 1;
        $wr_rounds = (int)round(log($wr1_count * 2, 2));

        // ── Reverse WR winner advancement ────────────────────────────────────────
        if ($winner_id > 0 && ($method === 'single' || $method === 'double') && $bracket_side === 'winners') {
            $max_wr_r   = $this->db->query("SELECT MAX(round) AS r FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id AND bracket_side = 'winners'");
            $max_wr_rnd = ($max_wr_r && $max_wr_r->next()) ? (int)$max_wr_r->r : 0;
            if ($round < $max_wr_rnd) {
                $next_round = $round + 1;
                $next_match = (int)ceil($match_num / 2);
                $next_slot  = ($match_num % 2 === 1) ? 'participant_1_id' : 'participant_2_id';
                $this->db->query("UPDATE " . DB_PREFIX . "match SET $next_slot = 0
					WHERE bracket_id = $bracket_id AND round = $next_round AND `match` = $next_match
					  AND bracket_side = 'winners' AND $next_slot = $winner_id");
            } elseif ($method === 'double') {
                // Only clear the GF slot if the routed participant is still sitting there
                // unplayed; never wipe a Grand Final that already produced a result.
                $this->db->query("UPDATE " . DB_PREFIX . "match SET participant_1_id = 0
					WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final' AND participant_1_id = $winner_id
					  AND (result IS NULL OR result = '')");
            }
        }

        // ── Reverse WR loser routing ─────────────────────────────────────────────
        // Only clear the LB slot if the routed loser is still sitting unplayed in it.
        // If the LB match has already produced a result, leave it alone — the
        // downstream-check guard above prevents reaching here in that case for the
        // involved participant.
        if ($method === 'double' && $loser_id > 0 && $bracket_side === 'winners') {
            if ($round === 1) {
                $half = (int)($wr1_count / 2);
                if ($match_num <= $half) {
                    $lr_match = $match_num;
                    $lr_slot  = 'participant_1_id';
                } else {
                    $lr_match = $wr1_count - $match_num + 1;
                    $lr_slot  = 'participant_2_id';
                }
                $this->db->query("UPDATE " . DB_PREFIX . "match SET $lr_slot = 0
					WHERE bracket_id = $bracket_id AND round = 1 AND `match` = $lr_match
					  AND bracket_side = 'losers' AND $lr_slot = $loser_id
					  AND (result IS NULL OR result = '')");
            } else {
                $lb_round         = ($round - 1) * 2;
                $lb_round_matches = max(1, (int)($wr1_count / pow(2, $round - 1)));
                $lr_match         = max(1, $lb_round_matches - $match_num + 1);
                $this->db->query("UPDATE " . DB_PREFIX . "match SET participant_2_id = 0
					WHERE bracket_id = $bracket_id AND round = $lb_round AND `match` = $lr_match
					  AND bracket_side = 'losers' AND participant_2_id = $loser_id
					  AND (result IS NULL OR result = '')");
            }
        }

        // ── Reverse LB winner advancement ────────────────────────────────────────
        if ($method === 'double' && $winner_id > 0 && $bracket_side === 'losers') {
            $lb_total_rounds = ($wr_rounds - 1) * 2;
            if ($round < $lb_total_rounds) {
                if ($round % 2 === 1) {
                    $this->db->query("UPDATE " . DB_PREFIX . "match SET participant_1_id = 0
						WHERE bracket_id = $bracket_id AND round = " . ($round + 1) . " AND `match` = $match_num
						  AND bracket_side = 'losers' AND participant_1_id = $winner_id");
                } else {
                    $next_match = (int)ceil($match_num / 2);
                    $next_slot  = ($match_num % 2 === 1) ? 'participant_1_id' : 'participant_2_id';
                    $this->db->query("UPDATE " . DB_PREFIX . "match SET $next_slot = 0
						WHERE bracket_id = $bracket_id AND round = " . ($round + 1) . " AND `match` = $next_match
						  AND bracket_side = 'losers' AND $next_slot = $winner_id");
                }
            } else {
                // Only clear the GF slot if the routed participant is still sitting there
                // unplayed; never wipe a Grand Final that already produced a result.
                $this->db->query("UPDATE " . DB_PREFIX . "match SET participant_2_id = 0
					WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final' AND participant_2_id = $winner_id
					  AND (result IS NULL OR result = '')");
            }
        }

        // ── Reverse eliminations ─────────────────────────────────────────────────
        if ($method === 'double' && $loser_id > 0 && ($bracket_side === 'losers' || $bracket_side === 'grand-final')) {
            $this->db->query("UPDATE " . DB_PREFIX . "participant SET eliminated = 0 WHERE participant_id = $loser_id");
        }

        if ($method === 'single' && $loser_id > 0) {
            $this->db->query("UPDATE " . DB_PREFIX . "participant SET eliminated = 0 WHERE participant_id = $loser_id");
        }

        // Reopen bracket if it was marked complete/finalized. A finalized re-open is a
        // privileged, audited action (auth already enforced above); a plain 'complete'
        // bracket reopens silently under the run-brackets gate.
        if ($wasFinalized) {
            $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
            // Derived id: ResetMatch emits match_reset with the request's own ActionId
            // right after this, and the (tournament_id, action_id) unique key would
            // otherwise drop that second row while its seq was already consumed. The
            // client treats '<id>:reopen' as its own action (tnIsOwnAction).
            $action_id = substr(trim($request['ActionId'] ?? ''), 0, 29);
            $this->db->query('START TRANSACTION');
            try {
                $this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'active' WHERE bracket_id = $bracket_id AND status = 'finalized'");
                $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'bracket_reopened', [
                    'bracket_id' => $bracket_id,
                    'match_id'   => $match_id,
                ], $actor_id, $action_id !== '' ? $action_id . ':reopen' : null);
                $this->db->query('COMMIT');
            } catch (\Throwable $e) {
                $this->db->query('ROLLBACK');
                throw $e;
            }
            $this->tnPublishSeq($tournament_id, $seq);
        } else {
            $this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'active' WHERE bracket_id = $bracket_id AND status = 'complete'");
        }

        return Success($match_id);
    }

    /**
     * GetStandings($request)
     * Aggregates wins/losses/byes/points per participant from ork_match.
     * Request: BracketId (required)
     */
    public function GetStandings($request)
    {
        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }

        // Look up bracket type (method, participants) to branch standings logic.
        $bpRow = $this->db->query("SELECT participants, method FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id LIMIT 1");
        $bpRow && $bpRow->next();
        $bracketParticipants = $bpRow ? (string)$bpRow->participants : 'individual';
        $bracketMethodGs     = $bpRow ? (string)$bpRow->method : '';

        if ($bracketMethodGs === 'points') {
            // Points rows carry Place (null for non-active); expose it as Rank, with
            // non-active participants numbered after the active ones, so standings
            // consumers (leaderboard place points) can rank points brackets too.
            $ps = $this->GetPointStandings(['BracketId' => $bracket_id]);
            if (($ps['Status'] ?? 1) == 0 && is_array($ps['Detail'] ?? null)) {
                // Identity fields match-standings rows carry (leaderboard keys people by
                // MundaneId; profile link / park column). Team brackets have no single mundane.
                $ident = [];
                $isTeamGs = ($bracketParticipants === 'team');
                $ir = $this->db->query("SELECT p.participant_id, p.park_id,
						" . ($isTeamGs ? "pk.name" : "COALESCE(pk.name, MIN(mpark.name))") . " AS park_name,
						" . ($isTeamGs ? "0" : "COALESCE(MIN(pm.mundane_id), 0)") . " AS mundane_id
					FROM " . DB_PREFIX . "participant p
						" . ($isTeamGs ? "" : "LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
						LEFT JOIN " . DB_PREFIX . "mundane mn ON mn.mundane_id = pm.mundane_id
						LEFT JOIN " . DB_PREFIX . "park mpark ON mpark.park_id = mn.park_id") . "
						LEFT JOIN " . DB_PREFIX . "park pk ON pk.park_id = p.park_id
					WHERE p.bracket_id = $bracket_id
					GROUP BY p.participant_id, p.park_id, pk.name");
                if ($ir) {
                    while ($ir->next()) {
                        $ident[(int)$ir->participant_id] = [(int)$ir->park_id, $ir->park_name, (int)$ir->mundane_id];
                    }
                }
                foreach ($ps['Detail'] as $i => &$row) {
                    $row['Rank'] = ($row['Place'] !== null) ? (int)$row['Place'] : $i + 1;
                    [$row['ParkId'], $row['ParkName'], $row['MundaneId']] = $ident[(int)$row['ParticipantId']] ?? [0, null, 0];
                    $row['IsTeam'] = $isTeamGs;
                    // Match-standings keys the placement list groups on (no W/L/T/B here).
                    $row['Points'] = (float)$row['Total'];
                    $row['Wins']   = 0;
                    $row['Losses'] = 0;
                    $row['Ties']   = 0;
                    $row['Byes']   = 0;
                }
                unset($row);
            }
            return $ps;
        }

        // Win/loss result codes come from the single shared mapping (directional
        // forfeit/DQ + legacy codes), identical to ResolveWinnerId.
        $p1Wins = TournamentReport::sqlP1Wins();
        $p2Wins = TournamentReport::sqlP2Wins();

        if ($bracketParticipants === 'team') {
            // Team brackets: group only by participant (not by mundane_id) so each team
            // yields exactly one standings row. No per-member award decoration.
            $sql = "SELECT
					p.participant_id,
					p.participant_number,
					p.alias,
					p.park_id,
					pk.name AS park_name,
					p.warrior_level,
					COUNT(CASE WHEN (m.participant_1_id = p.participant_id AND m.result IN ($p1Wins)) OR (m.participant_2_id = p.participant_id AND m.result IN ($p2Wins)) THEN 1 END) AS wins,
					COUNT(CASE WHEN (m.participant_1_id = p.participant_id AND m.result IN ($p2Wins)) OR (m.participant_2_id = p.participant_id AND m.result IN ($p1Wins)) THEN 1 END) AS losses,
					COUNT(CASE WHEN (m.participant_1_id = p.participant_id OR m.participant_2_id = p.participant_id) AND m.result = 'tie' THEN 1 END) AS ties,
					COUNT(CASE WHEN m.participant_1_id = p.participant_id AND m.participant_2_id = 0 THEN 1
					            WHEN m.participant_2_id = p.participant_id AND m.participant_1_id = 0 THEN 1 END) AS byes,
					p.im_wins, p.im_current_streak, p.im_max_streak,
					p.status, p.withdraw_mode
				FROM " . DB_PREFIX . "participant p
					LEFT JOIN " . DB_PREFIX . "match m ON (m.participant_1_id = p.participant_id OR m.participant_2_id = p.participant_id) AND m.bracket_id = $bracket_id AND m.voided = 0
					LEFT JOIN " . DB_PREFIX . "park pk ON pk.park_id = p.park_id
				WHERE p.bracket_id = $bracket_id
				GROUP BY p.participant_id, p.participant_number, p.alias, p.park_id, p.warrior_level, pk.name, p.status, p.withdraw_mode
				ORDER BY wins DESC, losses ASC";

            $r = $this->db->query($sql);
            $standings = [];
            $roster = $this->teamRoster($bracket_id);
            if ($r !== false && $r->size() > 0) {
                while ($r->next()) {
                    $wins   = (int)$r->wins;
                    $losses = (int)$r->losses;
                    $ties   = (int)$r->ties;
                    $pid    = (int)$r->participant_id;
                    $standings[] = [
                        'ParticipantId'    => $pid,
                        'ParticipantNumber' => (int)$r->participant_number,
                        'Alias'            => $r->alias,
                        'ParkId'           => (int)$r->park_id,
                        'ParkName'         => $r->park_name,
                        'MundaneId'        => 0,
                        'IsTeam'           => true,
                        'TeamWarriorLevel' => (int)$r->warrior_level,
                        'Members'          => $roster[$pid] ?? [],
                        'Wins'             => $wins,
                        'Losses'           => $losses,
                        'Ties'             => $ties,
                        'Byes'             => (int)$r->byes,
                        'Points'           => ($wins * 3) + ($ties * 1),
                        'ImWins'           => (int)$r->im_wins,
                        'ImCurStreak'      => (int)$r->im_current_streak,
                        'ImMaxStreak'      => (int)$r->im_max_streak,
                        'Status'           => (string)$r->status,
                        'WithdrawMode'     => (string)$r->withdraw_mode,
                    ];
                }
            }
        } else {
            // Individual bracket path (unchanged)
            $sql = "SELECT
					p.participant_id,
					p.participant_number,
					p.alias,
					p.park_id,
					COALESCE(pk.name, mpark.name) AS park_name,
					pm.mundane_id,
					COUNT(CASE WHEN (m.participant_1_id = p.participant_id AND m.result IN ($p1Wins)) OR (m.participant_2_id = p.participant_id AND m.result IN ($p2Wins)) THEN 1 END) AS wins,
					COUNT(CASE WHEN (m.participant_1_id = p.participant_id AND m.result IN ($p2Wins)) OR (m.participant_2_id = p.participant_id AND m.result IN ($p1Wins)) THEN 1 END) AS losses,
					COUNT(CASE WHEN (m.participant_1_id = p.participant_id OR m.participant_2_id = p.participant_id) AND m.result = 'tie' THEN 1 END) AS ties,
					COUNT(CASE WHEN m.participant_1_id = p.participant_id AND m.participant_2_id = 0 THEN 1
					            WHEN m.participant_2_id = p.participant_id AND m.participant_1_id = 0 THEN 1 END) AS byes,
					p.im_wins, p.im_current_streak, p.im_max_streak,
					p.status, p.withdraw_mode
				FROM " . DB_PREFIX . "participant p
					LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
						LEFT JOIN " . DB_PREFIX . "mundane mn ON mn.mundane_id = pm.mundane_id
							LEFT JOIN " . DB_PREFIX . "park mpark ON mpark.park_id = mn.park_id
					LEFT JOIN " . DB_PREFIX . "match m ON (m.participant_1_id = p.participant_id OR m.participant_2_id = p.participant_id) AND m.bracket_id = $bracket_id AND m.voided = 0
					LEFT JOIN " . DB_PREFIX . "park pk ON pk.park_id = p.park_id
				WHERE p.bracket_id = $bracket_id
				GROUP BY p.participant_id, p.participant_number, p.alias, p.park_id, pm.mundane_id, park_name, p.status, p.withdraw_mode
				ORDER BY wins DESC, losses ASC";

            $r = $this->db->query($sql);
            $standings = [];
            $std_mids = [];
            if ($r !== false && $r->size() > 0) {
                while ($r->next()) {
                    $wins   = (int)$r->wins;
                    $losses = (int)$r->losses;
                    $ties   = (int)$r->ties;
                    $mid = (int)$r->mundane_id;
                    if ($mid > 0) {
                        $std_mids[$mid] = true;
                    }
                    $standings[] = [
                        'ParticipantId' => (int)$r->participant_id,
                        'ParticipantNumber' => (int)$r->participant_number,
                        'Alias'         => $r->alias,
                        'ParkId'        => (int)$r->park_id,
                        'ParkName'      => $r->park_name,
                        'MundaneId'     => $mid,
                        'WarriorCount'  => 0,
                        'WarriorRank'   => 0,
                        'IsWarlord'     => false,
                        'IsKnightSword' => false,
                        'Wins'          => $wins,
                        'Losses'        => $losses,
                        'Ties'          => $ties,
                        'Byes'          => (int)$r->byes,
                        'Points'        => ($wins * 3) + ($ties * 1),
                        'ImWins'        => (int)$r->im_wins,
                        'ImCurStreak'   => (int)$r->im_current_streak,
                        'ImMaxStreak'   => (int)$r->im_max_streak,
                        'Status'        => (string)$r->status,
                        'WithdrawMode'  => (string)$r->withdraw_mode,
                    ];
                }
            }

            // Batched award decoration (replaces 4 correlated subqueries per row)
            if (!empty($std_mids)) {
                $awards_map = $this->fetchAwardsForMundanes(array_keys($std_mids));
                foreach ($standings as &$s) {
                    $mid = (int)$s['MundaneId'];
                    if ($mid > 0 && isset($awards_map[$mid])) {
                        $s['WarriorCount']  = $awards_map[$mid]['warrior_count'];
                        $s['WarriorRank']   = $awards_map[$mid]['warrior_rank'];
                        $s['IsWarlord']     = $awards_map[$mid]['is_warlord'];
                        $s['IsKnightSword'] = $awards_map[$mid]['is_knight_sword'];
                    }
                }
                unset($s);
            }
        }

        // Assign competition ranking: tied participants share a rank, next rank skips
        // Prefer the already-loaded $this->Bracket->method when it matches the requested
        // bracket_id (mirrors getRoundRobinTopTied) — falls back to a query otherwise.
        // Look up the bracket method by id. $this->Bracket may have no active record
        // in this call path (e.g. standings for a tournament with no participants),
        // and reading its fields would throw "no active record set".
        // Reuse the bracket method already loaded at the top of GetStandings
        // ($bracketMethodGs) instead of re-querying the same bracket row.
        $bracketMethod = $bracketMethodGs;

        if ($bracketMethod === 'ironman') {
            // Wins and streaks are denormalized onto ork_participant (maintained incrementally
            // by RecordIronmanWin) and are GLOBAL across rings. Defensively rebuild them from
            // ork_match here so standings self-heal from any drift left by the incremental
            // maintenance (whose transaction is a no-op on MyISAM). Only drifted rows are written.
            $imStats = $this->RecomputeIronmanStats($bracket_id);
            foreach ($standings as &$s) {
                $pid = (int)$s['ParticipantId'];
                $st  = $imStats[$pid] ?? ['wins' => (int)$s['ImWins'], 'current' => (int)$s['ImCurStreak'], 'max' => (int)$s['ImMaxStreak']];
                $s['Wins']          = $st['wins'];
                $s['Points']        = $st['wins'] * 3;
                $s['MaxStreak']     = $st['max'];
                $s['CurrentStreak'] = $st['current'];
            }
            unset($s);
            // Re-sort and re-rank by ironman criteria: Wins DESC, MaxStreak DESC,
            // CurrentStreak DESC (the reigning king edges out a fighter already dethroned),
            // then fewest Losses (only counted where the loser was recorded on the match).
            usort($standings, function ($a, $b) {
                if ($b['Wins'] !== $a['Wins']) {
                    return $b['Wins'] - $a['Wins'];
                }
                if (($b['MaxStreak'] ?? 0) !== ($a['MaxStreak'] ?? 0)) {
                    return ($b['MaxStreak'] ?? 0) - ($a['MaxStreak'] ?? 0);
                }
                if (($b['CurrentStreak'] ?? 0) !== ($a['CurrentStreak'] ?? 0)) {
                    return ($b['CurrentStreak'] ?? 0) - ($a['CurrentStreak'] ?? 0);
                }
                return ($a['Losses'] ?? 0) - ($b['Losses'] ?? 0);
            });
            $rank = 1;
            $count = count($standings);
            for ($i = 0; $i < $count;) {
                $j = $i;
                while ($j < $count
                    && $standings[$j]['Wins'] === $standings[$i]['Wins']
                    && ($standings[$j]['MaxStreak'] ?? 0) === ($standings[$i]['MaxStreak'] ?? 0)
                    && ($standings[$j]['CurrentStreak'] ?? 0) === ($standings[$i]['CurrentStreak'] ?? 0)
                    && ($standings[$j]['Losses'] ?? 0) === ($standings[$i]['Losses'] ?? 0)) {
                    $j++;
                }
                for ($k = $i; $k < $j; $k++) {
                    $standings[$k]['Rank'] = $rank;
                }
                $rank += ($j - $i);
                $i = $j;
            }
        } elseif ($bracketMethod === 'single' || $bracketMethod === 'double') {
            // Elimination placement is derived from BRACKET DEPTH (how far each competitor
            // advanced before being eliminated), not a raw win-count proxy: champion (never
            // eliminated) = 1st, the final's loser = 2nd, semifinal losers tie for 3rd, etc.
            // Byes count as advancement (they record a win, never an elimination). Win count
            // survives only as a display/secondary tiebreak inside a shared depth.
            $this->assignEliminationRanks($standings, $bracket_id, $bracketMethod);
        } else {
            // Order by points then fewest losses so the competition-ranking loop
            // below (which groups consecutive equal Points+Losses) sees the array
            // in the order it assumes. The SQL ORDER BY (wins DESC) diverges from
            // points order when ties exist, so re-sort here.
            usort($standings, function ($a, $b) {
                if ($b['Points'] !== $a['Points']) {
                    return $b['Points'] - $a['Points'];
                }
                return $a['Losses'] - $b['Losses'];
            });
            $rank = 1;
            $count = count($standings);
            for ($i = 0; $i < $count;) {
                $j = $i;
                while ($j < $count && $standings[$j]['Points'] === $standings[$i]['Points'] && $standings[$j]['Losses'] === $standings[$i]['Losses']) {
                    $j++;
                }
                for ($k = $i; $k < $j; $k++) {
                    $standings[$k]['Rank'] = $rank;
                }
                $rank += ($j - $i);
                $i = $j;
            }
        }

        // Non-active participants (withdrawn / disqualified) place after every active one,
        // and annulled withdrawals (all their matches voided) after those. Relative order
        // within each tier is kept; ranks are renumbered so ties inside a tier still share.
        $tiers = [[], [], []];
        foreach ($standings as $s) {
            $st = (string)($s['Status'] ?? '');
            $t  = ($st === '' || $st === 'active') ? 0 : ((($s['WithdrawMode'] ?? '') === 'annul') ? 2 : 1);
            $tiers[$t][] = $s;
        }
        if (!empty($tiers[1]) || !empty($tiers[2])) {
            $standings = [];
            $pos = 0;
            foreach ($tiers as $group) {
                $prevOrig = null;
                $rank = 0;
                foreach ($group as $s) {
                    $pos++;
                    $orig = $s['Rank'] ?? null;
                    if ($rank === 0 || $orig === null || $orig !== $prevOrig) {
                        $rank = $pos;
                    }
                    $prevOrig = $orig;
                    $s['Rank'] = $rank;
                    $standings[] = $s;
                }
            }
        }
        return Success($standings);
    }

    /**
     * assignEliminationRanks(&$standings, $bracket_id, $method)
     * Sorts and ranks single/double-elimination standings by bracket depth: the round in
     * which each competitor was finally eliminated. Deeper = better place; equal depth =
     * tied place (both semifinal losers share 3rd). Never-eliminated competitors (the
     * champion, or everyone still alive mid-bracket) rank first. For double-elim only a
     * losers-bracket or grand-final loss eliminates a competitor (a winners-bracket loss
     * just drops them to the losers bracket). A resolved tiebreaker-3rd match splits the
     * semifinal losers: its winner takes 3rd, its loser 4th — depths are scaled ×2 so the
     * winner can sit half a round above the shared semifinal depth without colliding with
     * the finals loser. Mutates $standings, assigning each a 'Rank'.
     */
    private function assignEliminationRanks(array &$standings, $bracket_id, $method)
    {
        if (empty($standings)) {
            return;
        }
        $bracket_id = (int)$bracket_id;

        // Load resolved, non-voided matches; track the deepest losers-bracket round so the
        // grand final scores just below it (its loser is the runner-up).
        $rows = [];
        $maxLbRound = 0;
        $maxGfRound = 0;
        $mr = $this->db->query("SELECT participant_1_id, participant_2_id, round, bracket_side, result
			FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id AND result IS NOT NULL AND result != '' AND voided = 0");
        if ($mr) {
            while ($mr->next()) {
                $side = (string)$mr->bracket_side;
                if ($method === 'double' && $side === 'losers') {
                    $maxLbRound = max($maxLbRound, (int)$mr->round);
                }
                if ($method === 'double' && $side === 'grand-final') {
                    $maxGfRound = max($maxGfRound, (int)$mr->round);
                }
                $rows[] = [(int)$mr->participant_1_id, (int)$mr->participant_2_id, (int)$mr->round, $side, (string)$mr->result];
            }
        }

        // depth[participant_id] = round at which they were finally eliminated (deeper = further).
        // Depths are round × 2 so the tiebreaker-3rd winner can be bumped +1 (half a round)
        // above the shared semifinal-loss depth while staying below the finals loser.
        $depth = [];
        $tbWinner = 0;
        $tbLoser  = 0;
        foreach ($rows as [$p1, $p2, $rnd, $side, $result]) {
            [$w, $l] = $this->resolveWinnerLoser($result, $p1, $p2);
            if ($l <= 0) {
                continue;
            } // tie or bye — no elimination
            if ($side === 'tiebreaker-3rd') {
                // The 3rd-place playoff is not an elimination — it only orders the two
                // semifinal losers (winner=3rd, loser=4th), applied after the loop.
                $tbWinner = $w;
                $tbLoser  = $l;
                continue;
            }
            if ($method === 'double') {
                if ($side === 'losers') {
                    $d = $rnd * 2;
                } elseif ($side === 'grand-final') {
                    // After a Grand-Final reset there are two grand-final rows (GF1 round 1,
                    // GF2 round 2). Only the highest-round resolved GF decides eliminations —
                    // GF1's loser (the eventual champion who won the reset) must NOT be
                    // eliminated by the superseded GF1 row, or champion and runner-up tie 1st.
                    if ($rnd < $maxGfRound) {
                        continue;
                    }
                    $d = ($maxLbRound + 1) * 2;
                } else {
                    continue;
                } // winners-bracket loss only drops to LB
            } else {
                $d = $rnd * 2; // single-elim: the round of the (only) loss
            }
            if (!isset($depth[$l]) || $d > $depth[$l]) {
                $depth[$l] = $d;
            }
        }
        if ($tbWinner > 0) {
            $depth[$tbWinner] = ($depth[$tbWinner] ?? 0) + 1;
        }

        $INF = PHP_INT_MAX; // never eliminated → champion / still alive
        foreach ($standings as &$s) {
            $s['_elimDepth'] = $depth[(int)$s['ParticipantId']] ?? $INF;
        }
        unset($s);

        // Deepest first; win count / fewest losses only orders WITHIN an equal depth (display).
        usort($standings, function ($a, $b) {
            if ($a['_elimDepth'] !== $b['_elimDepth']) {
                return $b['_elimDepth'] <=> $a['_elimDepth'];
            }
            if ($b['Points'] !== $a['Points']) {
                return $b['Points'] - $a['Points'];
            }
            return $a['Losses'] - $b['Losses'];
        });

        // Competition ranks group strictly by elimination depth so tied depths share a place.
        $rank = 1;
        $count = count($standings);
        for ($i = 0; $i < $count;) {
            $j = $i;
            while ($j < $count && $standings[$j]['_elimDepth'] === $standings[$i]['_elimDepth']) {
                $j++;
            }
            for ($k = $i; $k < $j; $k++) {
                $standings[$k]['Rank'] = $rank;
            }
            $rank += ($j - $i);
            $i = $j;
        }
        foreach ($standings as &$s) {
            unset($s['_elimDepth']);
        }
        unset($s);
    }

    /**
     * GetPlayerHistory($request)
     * A player's tournament history (sourced from the Report lib) decorated with each
     * entry's Placement. Standings are resolved ONCE per distinct bracket (batched
     * via a memo) rather than once per row — keeping this placement orchestration in the
     * lib layer instead of the thin model. Request: MundaneId.
     * Placement is final only when the bracket is complete/finalized; for any other
     * bracket it is the current live rank and 'Provisional' is true.
     * Returns Success([...rows, each with an added integer|null 'Placement' and bool 'Provisional'...]).
     */
    public function GetPlayerHistory($request)
    {
        $mundane_id = (int)($request['MundaneId'] ?? 0);
        if (!valid_id($mundane_id)) {
            return Success([]);
        }

        $report = Ork3::$Lib->report->GetPlayerTournamentHistory(['MundaneId' => $mundane_id]);
        $rows = (isset($report['Detail']) && is_array($report['Detail'])) ? $report['Detail'] : [];
        if (empty($rows)) {
            return Success([]);
        }

        // Live bracket statuses, fetched fresh in one query (the Report history rows are
        // cached for up to 30 minutes, so a status carried there could be stale).
        $bracketIds = array_unique(array_map(function ($r) {
            return (int)$r['BracketId'];
        }, $rows));
        $bracketStatus = [];
        $bs = $this->db->query("SELECT bracket_id, status FROM " . DB_PREFIX . "bracket WHERE bracket_id IN (" . implode(',', $bracketIds) . ")");
        if ($bs !== false) {
            while ($bs->next()) {
                $bracketStatus[(int)$bs->bracket_id] = (string)$bs->status;
            }
        }

        // Memoize standings per BracketId so each bracket's standings are computed at most
        // once, then map each history row's participant to its competition Rank.
        $bracketStandings = [];
        foreach ($rows as &$row) {
            $bid = (int)$row['BracketId'];
            if (!array_key_exists($bid, $bracketStandings)) {
                $s = $this->GetStandings(['BracketId' => $bid]);
                $bracketStandings[$bid] = (isset($s['Detail']) && is_array($s['Detail'])) ? $s['Detail'] : [];
            }
            $row['Placement'] = null;
            $row['Provisional'] = !in_array($bracketStatus[$bid] ?? '', ['complete', 'finalized'], true);
            foreach ($bracketStandings[$bid] as $st) {
                if ((int)$st['ParticipantId'] === (int)$row['ParticipantId']) {
                    // Annulled withdrawals (all matches voided) hold no placement.
                    if (($st['WithdrawMode'] ?? '') !== 'annul' || in_array((string)($st['Status'] ?? ''), ['', 'active'], true)) {
                        $row['Placement'] = (int)$st['Rank'];
                    }
                    break;
                }
            }
        }
        unset($row);
        return Success($rows);
    }

    /**
     * DeleteBracket($request)
     * Deletes a bracket along with its participants and matches, provided no match
     * results have been recorded yet.
     *
     * Request: Token, TournamentId, BracketId
     */
    public function DeleteBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id    = (int)($request['BracketId']    ?? 0);
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }

        $chk = $this->db->query("SELECT bracket_id FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id AND tournament_id = $tournament_id");
        if (!$chk || !$chk->next()) {
            return InvalidParameter('Bracket not found in this tournament');
        }

        // Delete all related data in dependency order
        $this->db->query('START TRANSACTION');
        try {
            $this->deleteTeamRows('bracket_id', $bracket_id);
            $this->db->query('DELETE pm FROM ' . DB_PREFIX . 'participant_mundane pm'
                . ' INNER JOIN ' . DB_PREFIX . 'participant p ON pm.participant_id = p.participant_id'
                . ' WHERE p.bracket_id = ' . $bracket_id);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'match              WHERE bracket_id = ' . $bracket_id);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'participant        WHERE bracket_id = ' . $bracket_id);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'bracket_officiant  WHERE bracket_id = ' . $bracket_id);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'seed               WHERE bracket_id = ' . $bracket_id);
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'bracket            WHERE bracket_id = ' . $bracket_id);
            $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
            $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'bracket_deleted', [
                'bracket_id' => $bracket_id,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tournament_id, $seq);
        return Success($bracket_id);
    }

    public function ClearBracketMatches($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id    = (int)($request['BracketId']    ?? 0);
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }

        $chk = $this->db->query("SELECT bracket_id FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id AND tournament_id = $tournament_id");
        if (!$chk || !$chk->next()) {
            return InvalidParameter('Bracket not found in this tournament');
        }

        $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);

        $this->db->query('START TRANSACTION');
        try {
            $this->db->query('DELETE FROM ' . DB_PREFIX . 'match WHERE bracket_id = ' . $bracket_id . ' AND tournament_id = ' . $tournament_id);
            // Return the bracket to setup so it can be re-generated. Ironman is
            // unaffected: RecordIronmanWin re-flips status to 'active' when the next
            // fight is recorded (see the guarded UPDATE there).
            $this->db->query('UPDATE ' . DB_PREFIX . 'bracket SET status = \'setup\' WHERE bracket_id = ' . $bracket_id);
            // Ironman denormalized stats are derived from matches — reset them too.
            $this->db->query('UPDATE ' . DB_PREFIX . 'participant SET im_wins = 0, im_current_streak = 0, im_max_streak = 0 WHERE bracket_id = ' . $bracket_id);
            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'matches_cleared', [
                'bracket_id' => $bracket_id,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tournament_id, $seq);
        return Success($bracket_id);
    }

    // -------------------------------------------------------------------------
    // Private generation algorithms
    // -------------------------------------------------------------------------

    /**
     * populate_swiss_round($bracket_id, $tournament_id, $target_round)
     * Fills placeholder (0,0) matches for a Swiss round by pairing participants
     * ranked by current standings (wins DESC, losses ASC, seed ASC).
     * If participant count is odd, the bottom-ranked player receives an auto-win bye.
     */
    private function populate_swiss_round($bracket_id, $tournament_id, $target_round)
    {
        // Fetch placeholder match IDs for this round
        $ph = $this->db->query(
            "SELECT match_id FROM " . DB_PREFIX . "match
			 WHERE bracket_id = $bracket_id AND round = $target_round
			   AND participant_1_id = 0 AND participant_2_id = 0
			 ORDER BY `match` ASC"
        );
        if (!$ph || $ph->size() === 0) {
            return;
        }
        $placeholders = [];
        while ($ph->next()) {
            $placeholders[] = (int)$ph->match_id;
        }

        // Rank all participants by wins DESC, losses ASC, seed ASC. Win/loss result codes
        // come from the single shared mapping (directional forfeit/DQ + legacy codes).
        $p1Wins = TournamentReport::sqlP1Wins();
        $p2Wins = TournamentReport::sqlP2Wins();
        $ranked_r = $this->db->query(
            "SELECT p.participant_id,
			    COALESCE(SUM(
			        CASE WHEN (m.participant_1_id = p.participant_id AND m.result IN ($p1Wins))
			              OR  (m.participant_2_id = p.participant_id AND m.result IN ($p2Wins)) THEN 1 ELSE 0 END
			    ), 0) AS wins,
			    COALESCE(SUM(
			        CASE WHEN (m.participant_1_id = p.participant_id AND m.result IN ($p2Wins))
			              OR  (m.participant_2_id = p.participant_id AND m.result IN ($p1Wins)) THEN 1 ELSE 0 END
			    ), 0) AS losses
			 FROM " . DB_PREFIX . "participant p
			 LEFT JOIN " . DB_PREFIX . "match m
			     ON m.bracket_id = p.bracket_id
			    AND (m.participant_1_id = p.participant_id OR m.participant_2_id = p.participant_id)
			    AND m.result IS NOT NULL AND m.result != ''
			 WHERE p.bracket_id = $bracket_id
			   AND p.status IN ('active', '')
			 GROUP BY p.participant_id, p.seed
			 ORDER BY wins DESC, losses ASC, p.seed ASC"
        );
        if (!$ranked_r || $ranked_r->size() === 0) {
            return;
        }
        $ranked = [];
        while ($ranked_r->next()) {
            $ranked[] = (int)$ranked_r->participant_id;
        }

        // Match history: which pairs have already met, and who has already had a bye,
        // so we avoid rematches and repeated byes (Swiss requirements).
        $played = [];
        $hist = $this->db->query("SELECT participant_1_id, participant_2_id FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id AND participant_1_id > 0 AND participant_2_id > 0");
        if ($hist) {
            while ($hist->next()) {
                $a = (int)$hist->participant_1_id;
                $b = (int)$hist->participant_2_id;
                $played[$a][$b] = true;
                $played[$b][$a] = true;
            }
        }
        $byeHistory = [];
        $bh = $this->db->query("SELECT participant_1_id, participant_2_id FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id
			  AND ((participant_1_id > 0 AND participant_2_id = 0) OR (participant_1_id = 0 AND participant_2_id > 0))");
        if ($bh) {
            while ($bh->next()) {
                $p = ((int)$bh->participant_1_id > 0) ? (int)$bh->participant_1_id : (int)$bh->participant_2_id;
                if ($p > 0) {
                    $byeHistory[$p] = true;
                }
            }
        }

        // If odd count, assign the bye to the lowest-ranked player who has NOT already had
        // one (walking up from the bottom); fall back to the lowest-ranked if all have.
        $bye_pid = null;
        if (count($ranked) % 2 !== 0) {
            $byeCandidates = [];
            for ($i = count($ranked) - 1; $i >= 0; $i--) {
                if (!isset($byeHistory[$ranked[$i]])) {
                    $byeCandidates[] = $ranked[$i];
                }
            }
            // Then anyone (already-byed), still lowest-ranked first, so a repeat is a last resort.
            for ($i = count($ranked) - 1; $i >= 0; $i--) {
                if (isset($byeHistory[$ranked[$i]])) {
                    $byeCandidates[] = $ranked[$i];
                }
            }
            // Pick the first bye candidate for which the remaining field can be paired
            // without a rematch; otherwise keep the top preference and let the fallback pair.
            foreach ($byeCandidates as $cand) {
                $rest = array_values(array_filter($ranked, fn ($x) => $x !== $cand));
                $budget = 200000;
                if ($this->swissBacktrackPair($rest, $played, $budget) !== null) {
                    $bye_pid = $cand;
                    break;
                }
            }
            if ($bye_pid === null) {
                $bye_pid = $byeCandidates[0] ?? array_pop($ranked);
            }
            $ranked = array_values(array_filter($ranked, fn ($x) => $x !== $bye_pid));
        }

        // Greedy pairing with backtracking to skip would-be rematches. If no rematch-free
        // perfect pairing exists, fall back to strict rank order (a repeat is unavoidable).
        $budget = 200000;
        $pairs = $this->swissBacktrackPair($ranked, $played, $budget);
        if ($pairs === null) {
            $pairs = [];
            for ($i = 0; $i + 1 < count($ranked); $i += 2) {
                $pairs[] = [$ranked[$i], $ranked[$i + 1]];
            }
        }

        $slot = 0;
        foreach ($pairs as $pair) {
            if ($slot >= count($placeholders)) {
                break;
            }
            $p1 = (int)$pair[0];
            $p2 = (int)$pair[1];
            $mid = $placeholders[$slot++];
            $this->db->query("UPDATE " . DB_PREFIX . "match SET participant_1_id = $p1, participant_2_id = $p2 WHERE match_id = $mid");
        }

        // Auto-win for the bye participant
        if ($bye_pid !== null && $slot < count($placeholders)) {
            $mid = $placeholders[$slot];
            $this->db->query("UPDATE " . DB_PREFIX . "match SET participant_1_id = $bye_pid, participant_2_id = 0, result = '1-wins' WHERE match_id = $mid");
        }
    }

    /**
     * swissBacktrackPair($ranked, $played, &$budget)
     * Returns a rematch-free perfect pairing of the (even-length) $ranked list as an array
     * of [p1, p2] pairs, preferring rank-adjacent opponents, or null if none exists within
     * the step budget. $played[a][b] marks a prior meeting. The step budget bounds the
     * backtracking so a pathological field can never hang result entry.
     */
    private function swissBacktrackPair(array $ranked, array $played, &$budget)
    {
        if (empty($ranked)) {
            return [];
        }
        if ($budget-- <= 0) {
            return null;
        }
        $first = $ranked[0];
        $rest  = array_slice($ranked, 1);
        for ($i = 0; $i < count($rest); $i++) {
            $cand = $rest[$i];
            if (isset($played[$first][$cand])) {
                continue;
            } // avoid a rematch
            $remaining = $rest;
            array_splice($remaining, $i, 1);
            $sub = $this->swissBacktrackPair($remaining, $played, $budget);
            if ($sub !== null) {
                array_unshift($sub, [$first, $cand]);
                return $sub;
            }
            if ($budget <= 0) {
                return null;
            }
        }
        return null;
    }

    private function insert_match($bracket_id, $tournament_id, $round, $match_num, $order, $p1_id, $p2_id, $bracket_side = 'winners')
    {
        $p1  = (int)$p1_id;
        $p2  = (int)$p2_id;
        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "match
			(tournament_id, bracket_id, round, `match`, `order`, participant_1_id, participant_2_id, bracket_side, created)
			VALUES (:tid, :bid, :round, :match_num, :order, :p1, :p2, :bside, NOW())",
            [':tid' => (int)$tournament_id, ':bid' => (int)$bracket_id, ':round' => (int)$round, ':match_num' => (int)$match_num, ':order' => (int)$order, ':p1' => $p1, ':p2' => $p2, ':bside' => $bracket_side]
        );
    }

    /**
     * Bulk-insert match rows via chunked multi-row INSERTs (one round-trip per ~200
     * rows) instead of one INSERT per match. Column set and per-value casting mirror
     * insert_match() exactly, so the rows written are byte-for-byte identical.
     * Each $row is an ordered tuple matching insert_match()'s argument order:
     * [$bracket_id, $tournament_id, $round, $match_num, $order, $p1_id, $p2_id, $bracket_side].
     */
    private function insert_matches_batch(array $rows)
    {
        if (empty($rows)) {
            return;
        }
        $cols = "(tournament_id, bracket_id, round, `match`, `order`, participant_1_id, participant_2_id, bracket_side, created)";
        foreach (array_chunk($rows, 200) as $chunk) {
            $placeholders = [];
            $params = [];
            $i = 0;
            foreach ($chunk as $r) {
                $placeholders[] = "(:tid$i, :bid$i, :round$i, :mnum$i, :order$i, :p1$i, :p2$i, :bside$i, NOW())";
                $params[":tid$i"]   = (int)$r[1];
                $params[":bid$i"]   = (int)$r[0];
                $params[":round$i"] = (int)$r[2];
                $params[":mnum$i"]  = (int)$r[3];
                $params[":order$i"] = (int)$r[4];
                $params[":p1$i"]    = (int)$r[5];
                $params[":p2$i"]    = (int)$r[6];
                $params[":bside$i"] = $r[7];
                $i++;
            }
            $this->db->query(
                "INSERT INTO " . DB_PREFIX . "match $cols VALUES " . implode(', ', $placeholders),
                $params
            );
        }
    }

    /**
     * Returns the warrior seeding rank (0-12) for a participant.
     * 12 = Sword Knight (strongest), 11 = Warlord, 1-10 = OotW rank, 0 = unranked.
     */
    private function warrior_seed_rank(array $p): int
    {
        if (!empty($p['IsKnightSword'])) {
            return 12;
        }
        if (!empty($p['IsWarlord'])) {
            return 11;
        }
        return min(10, max(0, (int)($p['WarriorRank'] ?? 0)));
    }

    /**
     * seedByPerformanceScore(&$participants)
     * Sorts $participants in place by their Glicko-2 (Performance Score) rating, highest
     * first. ork_glicko2 is keyed by org unit (no per-player row), so each participant is
     * scored by their park's overall (non-style-specific) rating. Participants with no
     * rating fall back to a deterministic Seed order, so this seeding never randomizes.
     */
    private function seedByPerformanceScore(array &$participants): void
    {
        $parkIds = [];
        foreach ($participants as $p) {
            $pid = (int)($p['ParkId'] ?? 0);
            if ($pid > 0) {
                $parkIds[$pid] = true;
            }
        }
        $ratings = [];
        if (!empty($parkIds)) {
            $idlist = implode(',', array_map('intval', array_keys($parkIds)));
            $rr = $this->db->query("SELECT park_id, MAX(mu) AS mu FROM " . DB_PREFIX . "glicko2
				WHERE park_id IN ($idlist) AND style_specific = 0 GROUP BY park_id");
            if ($rr) {
                while ($rr->next()) {
                    $ratings[(int)$rr->park_id] = (float)$rr->mu;
                }
            }
        }
        usort($participants, function ($a, $b) use ($ratings) {
            $ra = $ratings[(int)($a['ParkId'] ?? 0)] ?? -INF; // unrated sinks to the bottom
            $rb = $ratings[(int)($b['ParkId'] ?? 0)] ?? -INF;
            if ($ra != $rb) {
                return $rb <=> $ra;
            }               // higher rating = higher seed
            return (int)($a['Seed'] ?? 0) <=> (int)($b['Seed'] ?? 0); // deterministic fallback
        });
    }

    /**
     * Single-elimination bracket generator.
     * Pads participant list to next power-of-2 with byes (0).
     * Seeds 1 vs N, 2 vs N-1, ... pairing style.
     */
    private function generate_single_elim($bracket_id, $tournament_id, $participants)
    {
        $n     = count($participants);
        $slots = $this->next_power_of_two($n);

        // Pad with byes (byes land on the lowest seeds, opposite the top seeds).
        $pids = array_map(fn ($p) => (int)$p['ParticipantId'], $participants);
        while (count($pids) < $slots) {
            $pids[] = 0;
        }

        // Round 1 via the standard recursive bracket-seed ordering so that adjacent
        // match-pairs converge correctly (seeds 1 and 2 cannot meet before the final).
        $round1_pairs = $this->build_round1_pairs($pids, $slots);

        $total_rounds = (int)round(log($slots, 2));
        $order = 1;
        $rows = [];
        for ($m = 0; $m < count($round1_pairs); $m++) {
            $rows[] = [$bracket_id, $tournament_id, 1, $m + 1, $order++,
                $round1_pairs[$m][0], $round1_pairs[$m][1], 'winners'];
        }

        // Placeholder matches for rounds 2+
        $matches_in_round = $slots / 2;
        for ($round = 2; $round <= $total_rounds; $round++) {
            $matches_in_round = $matches_in_round / 2;
            for ($m = 1; $m <= $matches_in_round; $m++) {
                $rows[] = [$bracket_id, $tournament_id, $round, $m, $order++, 0, 0, 'winners'];
            }
        }
        $this->insert_matches_batch($rows);

        // Auto-resolve first-round byes so a non-power-of-two field advances and completes.
        $this->autoResolveElimByes($bracket_id, $tournament_id);
    }

    /**
     * Double-elimination bracket generator.
     * Winners bracket: same as single-elim.
     * Losers bracket: LR1 has same # matches as WR1, subsequent rounds halve then halve.
     * Grand Final: 1 match between winners bracket winner and losers bracket winner.
     */
    private function generate_double_elim($bracket_id, $tournament_id, $participants)
    {
        $n     = count($participants);
        $slots = $this->next_power_of_two($n);
        $pids  = array_map(fn ($p) => (int)$p['ParticipantId'], $participants);
        while (count($pids) < $slots) {
            $pids[] = 0;
        }

        // Winners bracket round 1 via the standard recursive bracket-seed ordering so
        // adjacent match-pairs converge correctly (seeds 1 and 2 cannot meet early).
        $round1_pairs = $this->build_round1_pairs($pids, $slots);

        $wr_rounds  = (int)round(log($slots, 2));
        $order = 1;
        $wr1_count = count($round1_pairs);
        $rows = [];

        for ($m = 0; $m < $wr1_count; $m++) {
            $rows[] = [$bracket_id, $tournament_id, 1, $m + 1, $order++,
                $round1_pairs[$m][0], $round1_pairs[$m][1], 'winners'];
        }

        // Winners bracket rounds 2+
        $mpr = $wr1_count;
        for ($round = 2; $round <= $wr_rounds; $round++) {
            $mpr = $mpr / 2;
            for ($m = 1; $m <= $mpr; $m++) {
                $rows[] = [$bracket_id, $tournament_id, $round, $m, $order++, 0, 0, 'winners'];
            }
        }

        // Losers bracket:
        // LBR1:  wr1_count/2 matches  — WR1 losers play each other (cross-seeded)
        // LBR2:  wr1_count/2 matches  — LBR1 winners vs WR2 losers
        // LBR3+: halves every even round until 1 match (LB Final)
        $lr_matches = (int)($wr1_count / 2);
        for ($lr_round = 1; $lr_round <= ($wr_rounds - 1) * 2; $lr_round++) {
            for ($m = 1; $m <= $lr_matches; $m++) {
                $rows[] = [$bracket_id, $tournament_id, $lr_round, $m, $order++, 0, 0, 'losers'];
            }
            if ($lr_round % 2 === 0) {
                $lr_matches = max(1, $lr_matches / 2);
            }
        }

        // Grand final
        $rows[] = [$bracket_id, $tournament_id, 1, 1, $order, 0, 0, 'grand-final'];
        $this->insert_matches_batch($rows);

        // Auto-resolve first-round byes so a non-power-of-two field advances and completes.
        // (Byes route no loser into the losers bracket, so this only advances the present seed.)
        $this->autoResolveElimByes($bracket_id, $tournament_id);
    }

    /**
     * Swiss-system bracket generator.
     * Round 1: random pairings. Subsequent rounds: pair by score proximity.
     * Number of rounds = $rings (or ceil(log2(N)) if rings = 1).
     */
    private function generate_swiss($bracket_id, $tournament_id, $participants, $rounds)
    {
        $n = count($participants);
        if ($rounds <= 1) {
            $rounds = (int)ceil(log($n, 2));
        }

        $pids = array_map(fn ($p) => (int)$p['ParticipantId'], $participants);
        $bye = ($n % 2 !== 0); // need a bye if odd
        if ($bye) {
            $pids[] = 0;
        }

        $order = 1;
        $rows = [];
        $has_bye_match = false;
        // Round 1: random (already shuffled by caller)
        $pairs = array_chunk($pids, 2);
        $match_num = 1;
        foreach ($pairs as $pair) {
            $p1 = $pair[0] ?? 0;
            $p2 = $pair[1] ?? 0;
            $rows[] = [$bracket_id, $tournament_id, 1, $match_num++, $order++, $p1, $p2, 'winners'];
            if ($p1 > 0 && $p2 === 0) {
                $has_bye_match = true;
            }
        }

        // Rounds 2+ are placeholder matches (pairings computed dynamically on result entry)
        for ($round = 2; $round <= $rounds; $round++) {
            $match_num = 1;
            $per_round = (int)floor(($bye ? count($pids) : $n) / 2);
            for ($m = 0; $m < $per_round; $m++) {
                $rows[] = [$bracket_id, $tournament_id, $round, $match_num++, $order++, 0, 0, 'winners'];
            }
        }
        $this->insert_matches_batch($rows);

        // Auto-complete round-1 bye matches so they count in standings from round 1.
        // Round-1 byes are the only matches with participant_1_id > 0 AND participant_2_id = 0
        // (rounds 2+ are all 0-vs-0 placeholders), so this set-based update targets exactly
        // the rows the former per-row GetLastInsertId UPDATE did.
        if ($has_bye_match) {
            $bid = (int)$bracket_id;
            $this->db->query(
                "UPDATE " . DB_PREFIX . "match SET result = '1-wins'
				 WHERE bracket_id = $bid AND round = 1 AND participant_1_id > 0 AND participant_2_id = 0"
            );
        }
    }

    /**
     * Round-robin generator using the circle method.
     * Produces N*(N-1)/2 matches distributed across rounds.
     */
    private function generate_round_robin($bracket_id, $tournament_id, $participants)
    {
        $n    = count($participants);
        $pids = array_map(fn ($p) => (int)$p['ParticipantId'], $participants);
        if ($n % 2 !== 0) {
            $pids[] = 0;
        } // bye

        $cnt   = count($pids);
        $fixed = $pids[0];
        $rot   = array_slice($pids, 1);
        $order = 1;
        $rows  = [];

        for ($round = 1; $round < $cnt; $round++) {
            $current = array_merge([$fixed], $rot);
            $match_num = 1;
            for ($i = 0; $i < $cnt / 2; $i++) {
                $p1 = $current[$i];
                $p2 = $current[$cnt - 1 - $i];
                if ($p1 === 0 || $p2 === 0) {
                    // Odd field: write a real bye match (participant_2_id = 0) so the Byes
                    // column in GetStandings is populated. Left unresolved so it does NOT
                    // count as a win (round-robin byes are not wins); the completion check
                    // ignores it since it requires both participants > 0.
                    $real = ($p1 !== 0) ? $p1 : $p2;
                    $rows[] = [$bracket_id, $tournament_id, $round, $match_num++, $order++, $real, 0, 'winners'];
                    continue;
                }
                $rows[] = [$bracket_id, $tournament_id, $round, $match_num++, $order++, $p1, $p2, 'winners'];
            }
            // Rotate: move last element of $rot to front
            array_unshift($rot, array_pop($rot));
        }
        $this->insert_matches_batch($rows);
    }

    /**
     * Ironman / King of the Hill generator.
     * No pre-generated matches — the bracket activates immediately and fights are
     * recorded live one by one via RecordIronmanWin().
     */
    private function generate_ironman($bracket_id, $tournament_id, $participants, $rings)
    {
        // Ironman fights are recorded live via RecordIronmanWin — no pre-generated matches.
    }

    /**
     * Points bracket: no matches are written. The scoring grid is built from
     * ork_point_score rows keyed (bracket_id, participant_id, round). The bracket
     * just needs to exist with status=active so the grid renders. The caller
     * (GenerateMatches) flips status to 'active' after this returns.
     */
    private function generate_points($bracket_id, $tournament_id, $participants)
    {
        // Intentionally empty. ork_match stays empty for Points brackets.
    }

    // Ironman bracket advancement is driven by the front-end (per-fight POST via RecordIronmanWin).

    private function next_power_of_two($n)
    {
        $p = 1;
        while ($p < $n) {
            $p *= 2;
        }
        return $p;
    }

    /**
     * Standard recursive bracket-seed ordering for a field of $slots (a power of two).
     * Returns the 1-indexed seed positions laid out so that adjacent match-pairs
     * converge correctly — i.e. the top two seeds cannot meet before the final and
     * byes (padded onto the lowest seeds) fall opposite the highest seeds. For 8
     * slots this yields [1,8,5,4,3,6,7,2] ... (seedOrder), consumed two-at-a-time as
     * round-1 match pairings.
     */
    private function bracket_seed_order($slots)
    {
        $rounds = (int)round(log(max(1, $slots), 2));
        $seeds = [1];
        for ($r = 0; $r < $rounds; $r++) {
            $next = [];
            $sum = count($seeds) * 2 + 1;
            foreach ($seeds as $s) {
                $next[] = $s;
                $next[] = $sum - $s;
            }
            $seeds = $next;
        }
        return $seeds; // 1-indexed seed positions, length $slots
    }

    /**
     * Builds round-1 [p1, p2] pairings from a seed-ordered, bye-padded participant id
     * list using the standard bracket-seed ordering (see bracket_seed_order). $pids is
     * indexed by seed-1 (index 0 = the top seed), padded to a power of two with 0 (byes).
     */
    private function build_round1_pairs(array $pids, $slots)
    {
        $order = $this->bracket_seed_order($slots);
        $pairs = [];
        for ($i = 0; $i < count($order); $i += 2) {
            $a = $order[$i] - 1;      // seed position → 0-based index into $pids
            $b = $order[$i + 1] - 1;
            $pairs[] = [$pids[$a] ?? 0, $pids[$b] ?? 0];
        }
        return $pairs;
    }

    /**
     * autoResolveElimByes($bracket_id, $tournament_id)
     * Auto-resolves every first-round elimination bye (a winners-round-1 match with
     * exactly one real participant and a 0/bye on the other side): records the win for
     * the present participant and runs the shared advancement engine so the byed seed
     * moves into round 2. Mirrors the Swiss bye auto-win so non-power-of-two single/
     * double-elim fields can complete. Standard bracket seeding guarantees byes never
     * meet each other in round 1, so a single round-1 pass is sufficient (every later
     * slot is fed by a match that produces a real winner). Runs inside the generation
     * transaction managed by GenerateMatches.
     */
    private function autoResolveElimByes($bracket_id, $tournament_id)
    {
        $r = $this->db->query("SELECT match_id, participant_1_id, participant_2_id
			FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id AND bracket_side = 'winners' AND round = 1
			  AND (result IS NULL OR result = '')
			  AND ((participant_1_id > 0 AND participant_2_id = 0)
			    OR (participant_1_id = 0 AND participant_2_id > 0))");
        $byes = [];
        if ($r && $r->size() > 0) {
            while ($r->next()) {
                $byes[] = [(int)$r->match_id, (int)$r->participant_1_id, (int)$r->participant_2_id];
            }
        }
        foreach ($byes as [$mid, $p1, $p2]) {
            $res = ($p1 > 0) ? '1-wins' : '2-wins';
            $this->db->query("UPDATE " . DB_PREFIX . "match SET result = '$res', auto_resolved = 1
				WHERE match_id = $mid AND (result IS NULL OR result = '')");
            // loser_id is 0 for a bye, so applyAdvancement only advances the present seed
            // (no losers-bracket routing, no elimination).
            $this->applyAdvancement($bracket_id, $tournament_id, $mid);
        }
    }

    /**
     * CreateConfirmationMatch($request)
     * In double-elimination, when the Second Chance (LB) winner wins the Grand Final
     * (result = '2-wins'), creates a second Grand Final match so the WB champion
     * has an opportunity to lose twice before being eliminated.
     *
     * Request: Token, TournamentId, BracketId
     */
    public function CreateConfirmationMatch($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!$this->bracketBelongsTo($bracket_id, (int)($request['TournamentId'] ?? 0))) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        if (!$this->Bracket->find()) {
            return InvalidParameter('Bracket not found');
        }
        if ($this->Bracket->method !== 'double') {
            return InvalidParameter('Not a double-elimination bracket');
        }

        $tournament_id = (int)($request['TournamentId'] ?? 0);

        // Check an existing confirmation match does not already exist
        $existing = $this->db->query('SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'match WHERE bracket_id = ' . $bracket_id . ' AND bracket_side = \'grand-final\' AND round > 1');
        if ($existing && $existing->next() && (int)$existing->cnt > 0) {
            return InvalidParameter('Confirmation match already exists');
        }

        // Load the original Grand Final match (round 1)
        $gfr = $this->db->query('SELECT * FROM ' . DB_PREFIX . 'match WHERE bracket_id = ' . $bracket_id . ' AND bracket_side = \'grand-final\' AND round = 1 LIMIT 1');
        if (!$gfr || $gfr->size() == 0) {
            return InvalidParameter('Grand Final match not found');
        }
        $gfr->next();
        $gf_result = $gfr->result;
        $gf_p1     = (int)$gfr->participant_1_id;
        $gf_p2     = (int)$gfr->participant_2_id;

        if ($gf_result !== '2-wins') {
            return InvalidParameter('Grand Final result is not 2-wins');
        }
        if (!$gf_p1 || !$gf_p2) {
            return InvalidParameter('Grand Final participants are not fully resolved');
        }

        // Insert confirmation match: same participants, round 2
        // Use max(order)+1 so the confirmation match sorts after all existing matches
        $maxOrd = $this->db->query('SELECT MAX(`order`) AS m FROM ' . DB_PREFIX . 'match WHERE bracket_id = ' . $bracket_id);
        $next_order = ($maxOrd && $maxOrd->next() && $maxOrd->m !== null) ? (int)$maxOrd->m + 1 : 1;
        // Clear stale PDO bindings left by the yapo find() above before the raw insert/update.
        $this->db->Clear();
        $this->insert_match($bracket_id, $tournament_id, 2, 1, $next_order, $gf_p1, $gf_p2, 'grand-final');
        $new_id = (int)$this->db->GetLastInsertId();
        if (!valid_id($new_id)) {
            return InvalidParameter('Failed to create match record');
        }

        // Reopen bracket for play
        $this->db->query('UPDATE ' . DB_PREFIX . 'bracket SET status = \'active\' WHERE bracket_id = ' . $bracket_id);

        return Success($new_id);
    }

    /**
     * RecordIronmanWin($request)
     * Records a single ironman/king-of-the-hill fight directly.
     * No pre-generated matches; each fight is appended live.
     *
     * Request: Token, TournamentId, BracketId, WinnerId, [LoserId optional], [RingNumber]
     */
    public function RecordIronmanWin($request)
    {
        if (!$this->can_run_brackets($request)) {
            return NoAuthorization();
        }

        $bracket_id    = (int)($request['BracketId']    ?? 0);
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        $winner_id     = (int)($request['WinnerId']     ?? 0);
        // LoserId is optional: the king-of-the-hill quick-entry UI records only the winner
        // (the loser is implicit). When a caller does supply a LoserId we store it so the
        // fight is fully recorded; otherwise participant_2_id stays 0 as before.
        $loser_id      = (int)($request['LoserId']      ?? 0);
        // Do NOT clamp the ring here: clamping (min(8,…)) silently mis-attributes rings 9-20
        // to ring 8. Validate against the bracket's ACTUAL configured ring count below instead.
        $ring_number   = max(1, (int)($request['RingNumber']  ?? 1));

        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }
        if (!valid_id($winner_id)) {
            return InvalidParameter('WinnerId required');
        }
        if (!$this->bracketBelongsTo($bracket_id, $tournament_id)) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        // Validate winner_id is actually a participant in this bracket
        $vp = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE participant_id = $winner_id AND bracket_id = $bracket_id");
        if (!$vp || !$vp->next()) {
            return InvalidParameter('WinnerId is not a participant in this bracket');
        }

        // Validate loser_id only when supplied; an invalid non-zero value is rejected,
        // a zero/absent value falls through with participant_2_id = 0.
        if ($loser_id > 0) {
            $vl = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE participant_id = $loser_id AND bracket_id = $bracket_id");
            if (!$vl || !$vl->next()) {
                return InvalidParameter('LoserId must be a participant in this bracket');
            }
        }

        // Validate ring_number is within the bracket's configured ring count
        $br = $this->db->query("SELECT rings FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id");
        $maxRings = ($br && $br->next()) ? max(1, (int)$br->rings) : 1;
        if ($ring_number > $maxRings) {
            return InvalidParameter('RingNumber exceeds bracket ring count');
        }

        $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
        $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');

        // Fight number = MAX(order)+1. We deliberately do NOT range-lock the bracket
        // (the old `FOR UPDATE` gap-locked every match row, serializing all rings and
        // killing multi-ring KITH throughput). `order` carries no unique constraint and
        // nothing but display sort depends on it, so a rare same-instant collision across
        // two rings is cosmetic; the AUTO_INCREMENT match_id is the authoritative
        // monotonic tiebreak (used below and in the current-king lookup). The denormalized
        // stat updates still run in the transaction so wins/streaks stay consistent, and
        // participant rows are updated in ascending id order so two recorders can't deadlock.
        $this->db->query('START TRANSACTION');
        try {
            $cnt_r     = $this->db->query("SELECT COALESCE(MAX(`order`),0)+1 AS next_ord FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id");
            $fight_num = ($cnt_r && $cnt_r->next()) ? (int)$cnt_r->next_ord : 1;

            // Previous king of this ring = last recorded winner in it. A different winner
            // now dethrones them, resetting that fighter's current streak. Order by match_id
            // as well so the "latest" fight is deterministic even if two share an order value.
            $pk = $this->db->query("SELECT participant_1_id FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND ring_number = $ring_number AND result IS NOT NULL AND result != ''
				ORDER BY `order` DESC, match_id DESC LIMIT 1");
            $prev_king = ($pk && $pk->next()) ? (int)$pk->participant_1_id : 0;
            $dethroned = ($prev_king > 0 && $prev_king !== $winner_id) ? $prev_king : 0;

            $this->db->query("INSERT INTO " . DB_PREFIX . "match
				(tournament_id, bracket_id, round, `match`, `order`, participant_1_id, participant_2_id, bracket_side, result, ring_number, resolution_order, created)
				VALUES ($tournament_id, $bracket_id, 1, $fight_num, $fight_num, $winner_id, $loser_id, 'winners', '1-wins', $ring_number, $fight_num, NOW())");

            $winnerSql = "UPDATE " . DB_PREFIX . "participant
				SET im_wins = im_wins + 1, im_current_streak = im_current_streak + 1,
				    im_max_streak = GREATEST(im_max_streak, im_current_streak + 1)
				WHERE participant_id = $winner_id";
            $dethroneSql = $dethroned > 0
                ? "UPDATE " . DB_PREFIX . "participant SET im_current_streak = 0 WHERE participant_id = $dethroned"
                : null;
            // Update in ascending participant_id order to avoid deadlocks.
            if ($dethroneSql !== null && $dethroned < $winner_id) {
                $this->db->query($dethroneSql);
                $this->db->query($winnerSql);
            } else {
                $this->db->query($winnerSql);
                if ($dethroneSql !== null) {
                    $this->db->query($dethroneSql);
                }
            }

            $ws = $this->db->query("SELECT im_wins, im_current_streak FROM " . DB_PREFIX . "participant WHERE participant_id = $winner_id");
            $win_total = 0;
            $win_streak = 0;
            if ($ws && $ws->next()) {
                $win_total = (int)$ws->im_wins;
                $win_streak = (int)$ws->im_current_streak;
            }

            // A fight has now been recorded, so the bracket is under way — flip it out of
            // setup. Guarded so a bracket already 'active'/'complete'/'paused' is untouched.
            $this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'active' WHERE bracket_id = $bracket_id AND status IN ('setup','')");

            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'ironman_win', [
                'winner_id' => $winner_id,
                'loser_id'  => $loser_id,
                'fight_num' => $fight_num,
                'ring'      => $ring_number,
            ], $actor_id, $action_id !== '' ? $action_id : null);

            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->tnPublishSeq($tournament_id, $seq);
        return Success([
            'FightNum'     => $fight_num,
            'WinnerId'     => $winner_id,
            'WinnerWins'   => $win_total,
            'WinnerStreak' => $win_streak,
            'DethronedId'  => $dethroned,
            'KingChanged'  => ($prev_king !== $winner_id) ? 1 : 0,
            'RingNumber'   => $ring_number,
            'Seq'          => $seq,
        ]);
    }

    /**
     * RecomputeIronmanStats($bracket_id)
     * Authoritatively rebuilds the denormalized im_wins / im_current_streak / im_max_streak
     * columns for every participant in an Ironman bracket by replaying ork_match. The
     * incremental maintenance in RecordIronmanWin runs inside a transaction that is a no-op
     * on MyISAM tables (and can drift after a partial failure), so this is the self-heal /
     * admin-recalc path. Replays fights in global chronological order (order, match_id) while
     * tracking the reigning king PER RING, mirroring RecordIronmanWin's dethrone logic exactly.
     * Only rows whose stored values differ are written. Returns
     * [participant_id => ['wins'=>int, 'current'=>int, 'max'=>int]].
     */
    private function RecomputeIronmanStats($bracket_id)
    {
        $bracket_id = (int)$bracket_id;
        $stats = []; // pid => ['wins','current','max']
        $ringKing = []; // ring_number => last winning participant_id

        $mr = $this->db->query("SELECT participant_1_id, ring_number FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id AND bracket_side = 'winners' AND result = '1-wins' AND voided = 0
			ORDER BY `order` ASC, match_id ASC");
        if ($mr) {
            while ($mr->next()) {
                $winner = (int)$mr->participant_1_id;
                $ring   = (int)$mr->ring_number;
                if ($winner <= 0) {
                    continue;
                }
                if (!isset($stats[$winner])) {
                    $stats[$winner] = ['wins' => 0, 'current' => 0, 'max' => 0];
                }
                $stats[$winner]['wins']++;
                $stats[$winner]['current']++;
                if ($stats[$winner]['current'] > $stats[$winner]['max']) {
                    $stats[$winner]['max'] = $stats[$winner]['current'];
                }
                $prevKing = $ringKing[$ring] ?? 0;
                if ($prevKing > 0 && $prevKing !== $winner && isset($stats[$prevKing])) {
                    $stats[$prevKing]['current'] = 0;
                }
                $ringKing[$ring] = $winner;
            }
        }

        // Persist, self-healing only rows that drifted. Load current stored values first so we
        // touch the minimum number of rows (and never zero-out a participant with no fights unless
        // they actually carry stale non-zero stats).
        $cur = $this->db->query("SELECT participant_id, im_wins, im_current_streak, im_max_streak
			FROM " . DB_PREFIX . "participant WHERE bracket_id = $bracket_id");
        if ($cur) {
            while ($cur->next()) {
                $pid = (int)$cur->participant_id;
                $want = $stats[$pid] ?? ['wins' => 0, 'current' => 0, 'max' => 0];
                $stats[$pid] = $want; // ensure the returned map covers every participant
                if ((int)$cur->im_wins === $want['wins']
                    && (int)$cur->im_current_streak === $want['current']
                    && (int)$cur->im_max_streak === $want['max']) {
                    continue;
                }
                $this->db->query("UPDATE " . DB_PREFIX . "participant
					SET im_wins = " . (int)$want['wins'] . ", im_current_streak = " . (int)$want['current'] . ", im_max_streak = " . (int)$want['max'] . "
					WHERE participant_id = $pid");
            }
        }
        return $stats;
    }


    /**
     * PoolsToBracket($request)
     * Create a new single/double-elimination "playoff" bracket from the top X of an
     * Ironman bracket's standings. Reuses AddBracket (create) and GenerateMatches
     * (seed + build) rather than re-implementing them.
     *
     * Request: Token, TournamentId, BracketId (source ironman), Method (single|double),
     *          TopX (int >= 2), SeedMethod (standing|warrior|glicko2|random)
     */
    public function PoolsToBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $tid        = (int)($request['TournamentId'] ?? 0);
        $src        = (int)($request['BracketId']    ?? 0);
        $method     = (string)($request['Method']    ?? '');
        $topX       = (int)($request['TopX']         ?? 0);
        $seedMethod = (string)($request['SeedMethod'] ?? 'standing');

        if (!valid_id($tid)) {
            return InvalidParameter('TournamentId required');
        }
        if (!valid_id($src)) {
            return InvalidParameter('Source bracket required');
        }
        if (!in_array($method, ['single', 'double'], true)) {
            return InvalidParameter('Choose Single or Double Elimination');
        }
        if ($topX < 2) {
            return InvalidParameter('Top X must be at least 2');
        }

        // Source must be a ranked-pool bracket (ironman, round-robin, or swiss) in this
        // tournament — every one of these produces a ranked GetStandings order we can seed from.
        $poolMethods = ['ironman', 'round-robin', 'swiss'];
        $sb = $this->db->query("SELECT style, style_note, method FROM " . DB_PREFIX . "bracket WHERE bracket_id = $src AND tournament_id = $tid");
        if (!$sb || !$sb->next()) {
            return InvalidParameter('Source bracket not found');
        }
        $srcMethod = (string)$sb->method;
        if (!in_array($srcMethod, $poolMethods, true)) {
            return InvalidParameter('Pools to Brackets is only available for Ironman, Round Robin, or Swiss brackets');
        }
        $srcStyle = $sb->style;
        $srcLabel = ['ironman' => 'Ironman', 'round-robin' => 'Round Robin', 'swiss' => 'Swiss'][$srcMethod] ?? 'Pool';
        // Full source label (method + the pool's own note) so sibling playoffs from
        // different pools stay distinguishable, e.g. 'Top 2 from Ironman (Pool A)'.
        $srcNote = trim((string)$sb->style_note);
        if ($srcNote !== '') {
            $srcLabel .= ' (' . $srcNote . ')';
        }

        // Ranked pool standings. GetStandings already ranks each supported source method
        // (ironman by Wins, Max Streak, Current Streak, then fewest Losses; round-robin/swiss by
        // Points then fewest Losses).
        $st = $this->GetStandings(['BracketId' => $src, 'TournamentId' => $tid]);
        if ($st['Status'] != 0) {
            return $st;
        }
        $rows = $st['Detail'];
        usort($rows, function ($a, $b) {
            return ((int)($a['Rank'] ?? 9999)) - ((int)($b['Rank'] ?? 9999));
        });
        $pids  = [];
        $ranks = [];
        foreach ($rows as $r) {
            if ((int)$r['ParticipantId'] > 0) {
                $pids[]  = (int)$r['ParticipantId'];
                $ranks[] = (int)($r['Rank'] ?? 9999);
            }
        }
        if (count($pids) < 2) {
            return InvalidParameter('Not enough participants in the pool');
        }
        // Refuse to cut through a tie: if the last promoted slot shares its Rank with the
        // first excluded one, row order alone would decide who advances.
        if ($topX < count($pids) && $ranks[$topX - 1] === $ranks[$topX]) {
            return InvalidParameter('Tie at the cut line: resolve the tie for place ' . $topX . ' (record a tiebreak fight, or choose a different Top X) before promoting.');
        }
        $pids = array_slice($pids, 0, min($topX, count($pids)));
        if ($method === 'double' && count($pids) < 3) {
            return InvalidParameter('Double elimination needs at least 3 players');
        }

        $seedingMap = ['standing' => 'manual', 'warrior' => 'warrior', 'glicko2' => 'glicko2', 'random' => 'random'];
        $seeding    = $seedingMap[$seedMethod] ?? 'manual';

        // Create the new (empty) bracket via the existing path.
        $br = $this->AddBracket([
            'Token'           => $request['Token'] ?? '',
            'TournamentId'    => $tid,
            'Style'           => $srcStyle,
            'StyleNote'       => mb_substr('Top ' . count($pids) . ' from ' . $srcLabel, 0, 255),
            'Method'          => $method,
            'Participants'    => 'individual',
            'Rings'           => 1,
            'Seeding'         => $seeding,
            'DurationMinutes' => 0,
            'BestOf'          => 1,
        ]);
        if ($br['Status'] != 0) {
            return $br;
        }
        $new_bid = (int)$br['Detail'];

        // Copy the selected participants (+ mundane links) into the new bracket.
        $this->db->query('START TRANSACTION');
        try {
            $i = 0;
            foreach ($pids as $pid) {
                $i++;
                $seedVal = ($seeding === 'manual') ? $i : 0;
                $this->db->query("INSERT INTO " . DB_PREFIX . "participant
					(tournament_id, bracket_id, alias, unit_id, park_id, kingdom_id, participant_number, seed, warrior_level, griffon_level)
					SELECT tournament_id, $new_bid, alias, unit_id, park_id, kingdom_id, participant_number, $seedVal, warrior_level, griffon_level
					FROM " . DB_PREFIX . "participant WHERE participant_id = $pid AND tournament_id = $tid");
                $new_pid = (int)$this->db->GetLastInsertId();
                if ($new_pid > 0) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "participant_mundane (participant_id, mundane_id, tournament_id, bracket_id)
						SELECT $new_pid, mundane_id, tournament_id, $new_bid
						FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
                }
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        // Generate the elimination matches (applies the seeding sort).
        $g = $this->GenerateMatches(['Token' => $request['Token'] ?? '', 'TournamentId' => $tid, 'BracketId' => $new_bid]);
        if ($g['Status'] != 0) {
            return $g;
        }

        return Success($new_bid);
    }


    /**
     * CreateTiebreakerMatch($request)
     * In single-elimination, when the bracket is complete, creates a 3rd-place
     * tiebreaker match between the two semifinal losers.
     *
     * Request: Token, TournamentId, BracketId
     */
    public function CreateTiebreakerMatch($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!$this->bracketBelongsTo($bracket_id, (int)($request['TournamentId'] ?? 0))) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        $tournament_id = (int)($request['TournamentId'] ?? 0);

        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        if (!$this->Bracket->find()) {
            return InvalidParameter('Bracket not found');
        }
        if ($this->Bracket->method !== 'single') {
            return InvalidParameter('Not a single-elimination bracket');
        }

        // Check no tiebreaker match exists yet
        $existing = $this->db->query('SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'match WHERE bracket_id = ' . $bracket_id . ' AND bracket_side = \'tiebreaker-3rd\'');
        if ($existing && $existing->next() && (int)$existing->cnt > 0) {
            return InvalidParameter('Tiebreaker match already exists');
        }

        // Find the final round (max winners round)
        $maxRndR = $this->db->query('SELECT MAX(round) AS r FROM ' . DB_PREFIX . 'match WHERE bracket_id = ' . $bracket_id . ' AND bracket_side = \'winners\'');
        if (!$maxRndR || !$maxRndR->next()) {
            return InvalidParameter('No matches found');
        }
        $max_round = (int)$maxRndR->r;
        if ($max_round < 2) {
            return InvalidParameter('Not enough rounds for a 3rd place match');
        }

        $semi_round = $max_round - 1;

        // Get the two semifinal matches and extract losers
        $sfR = $this->db->query(
            'SELECT participant_1_id, participant_2_id, result FROM ' . DB_PREFIX . 'match
			WHERE bracket_id = ' . $bracket_id . ' AND bracket_side = \'winners\' AND round = ' . $semi_round . '
			ORDER BY `match` LIMIT 2'
        );
        if (!$sfR || $sfR->size() < 2) {
            return InvalidParameter('Could not find two semifinal matches');
        }

        $losers = [];
        while ($sfR->next()) {
            $result = $sfR->result;
            $p1     = (int)$sfR->participant_1_id;
            $p2     = (int)$sfR->participant_2_id;
            if (!$result || !$p1 || !$p2) {
                return InvalidParameter('Semifinal matches are not fully resolved');
            }
            [$winner, $loser] = $this->resolveWinnerLoser($result, $p1, $p2);
            if (!valid_id($loser)) {
                return InvalidParameter('Could not determine a semifinal loser');
            }
            $losers[] = $loser;
        }
        if (count($losers) !== 2) {
            return InvalidParameter('Expected exactly two semifinal losers');
        }

        // Insert tiebreaker match
        $maxOrd = $this->db->query('SELECT MAX(`order`) AS m FROM ' . DB_PREFIX . 'match WHERE bracket_id = ' . $bracket_id);
        $next_order = ($maxOrd && $maxOrd->next() && $maxOrd->m !== null) ? (int)$maxOrd->m + 1 : 1;
        // Clear stale PDO bindings left by the yapo find() above before the raw insert/update.
        $this->db->Clear();
        $this->insert_match($bracket_id, $tournament_id, $max_round, 1, $next_order, $losers[0], $losers[1], 'tiebreaker-3rd');
        $new_id = (int)$this->db->GetLastInsertId();
        if (!valid_id($new_id)) {
            return InvalidParameter('Failed to create match record');
        }

        // Reopen bracket for play
        $this->db->query('UPDATE ' . DB_PREFIX . 'bracket SET status = \'active\' WHERE bracket_id = ' . $bracket_id);

        return Success($new_id);
    }

    /**
 * CompleteBracket($request)
     * Marks a bracket as finalized — used when the organizer waives the confirmation
     * match and declares the tournament over.
     *
     * Request: Token, TournamentId, BracketId
     */
    public function CompleteBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!$this->bracketBelongsTo($bracket_id, $tournament_id)) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        $waive = !empty($request['WaiveReset']);

        $unresolved = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0 AND voided = 0");
        $unresolvedCnt = ($unresolved && $unresolved->next()) ? (int)$unresolved->cnt : 0;

        // When the LB champ wins Grand-Final 1, applyAdvancement auto-creates the reset match
        // (GF2, round 2, grand-final). Normally that must be played. But the organizer may WAIVE
        // the reset and declare the tournament over on the GF1 result — the only path by which a
        // double-elim bracket in that state can ever finalize. Delete the GF2 row and re-mark the
        // GF1 loser (whose elimination the GF2 auto-creation had reversed) as eliminated so the
        // GF1 result stands, then fall through to finalize.
        $didWaive = false;
        if ($unresolvedCnt > 0) {
            $blocked = true;
            if ($waive) {
                $nonReset = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
					WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0 AND voided = 0
					  AND NOT (bracket_side = 'grand-final' AND round = 2)");
                $nonResetCnt = ($nonReset && $nonReset->next()) ? (int)$nonReset->cnt : $unresolvedCnt;
                if ($nonResetCnt === 0) {
                    $blocked = false;
                    $didWaive = true;
                }
            }
            if ($blocked) {
                return InvalidParameter('Cannot finalize bracket with unresolved matches (' . $unresolvedCnt . ' remaining)');
            }
        }

        $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);

        $this->db->query('START TRANSACTION');
        try {
            if ($didWaive) {
                $gf1 = $this->db->query("SELECT participant_1_id, participant_2_id, result FROM " . DB_PREFIX . "match
					WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final' AND round = 1 AND result IS NOT NULL AND result != '' LIMIT 1");
                if ($gf1 && $gf1->next()) {
                    [, $gf1Loser] = $this->resolveWinnerLoser((string)$gf1->result, (int)$gf1->participant_1_id, (int)$gf1->participant_2_id);
                    if ($gf1Loser > 0) {
                        $this->db->query("UPDATE " . DB_PREFIX . "participant SET eliminated = 1 WHERE participant_id = $gf1Loser");
                    }
                }
                $this->db->query("DELETE FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id AND bracket_side = 'grand-final' AND round = 2");
            }
            $this->db->query('UPDATE ' . DB_PREFIX . 'bracket SET status = \'finalized\' WHERE bracket_id = ' . $bracket_id);
            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'bracket_finalized', [
                'bracket_id' => $bracket_id,
                'waived'     => $didWaive,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tournament_id, $seq);
        return Success($bracket_id);
    }


    /**
     * Returns the participant IDs currently tied at rank 1 in a Round Robin
     * bracket, considering every resolved match in the bracket (regular +
     * any prior tiebreaker rounds). Used by the RR tiebreaker flow to
     * decide whether to surface the banner and which players to pair up.
     *
     * Returns [] if fewer than 2 players are tied at the top, or if the
     * bracket isn't a round robin.
     */
    private function getRoundRobinTopTied($bracket_id)
    {
        $bracket_id = (int)$bracket_id;
        // Look up by id; $this->Bracket may have no active record in this path.
        $mr = $this->db->query("SELECT method, tournament_id FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id");
        if (!$mr || !$mr->next() || $mr->method !== 'round-robin') {
            return [];
        }
        $tid = (int)$mr->tournament_id;

        $resp = $this->GetStandings(['BracketId' => $bracket_id, 'TournamentId' => $tid]);
        if ($resp['Status'] != 0) {
            return [];
        }
        $standings = $resp['Detail'];
        if (count($standings) < 2) {
            return [];
        }

        // GetStandings already sorts by Points DESC, Losses ASC and assigns competition Rank
        $tied = [];
        foreach ($standings as $s) {
            if ((int)($s['Rank'] ?? 0) === 1) {
                $tied[] = (int)$s['ParticipantId'];
            } else {
                break;
            }
        }
        return count($tied) >= 2 ? $tied : [];
    }

    /**
     * CreateRoundRobinTiebreaker($request)
     * For a Round Robin bracket where 2+ players are tied at rank 1, generates
     * a mini round-robin among the tied players. Each pair plays one match
     * inheriting the parent bracket's Best-of-N. Tiebreaker matches use
     * bracket_side='tiebreaker' and round = max(round)+1, so cascading
     * tiebreakers stack without any extra schema.
     *
     * Request: Token, TournamentId, BracketId
     */
    public function CreateRoundRobinTiebreaker($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!$this->bracketBelongsTo($bracket_id, (int)($request['TournamentId'] ?? 0))) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        $tournament_id = (int)($request['TournamentId'] ?? 0);

        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        if (!$this->Bracket->find()) {
            return InvalidParameter('Bracket not found');
        }
        if ($this->Bracket->method !== 'round-robin') {
            return InvalidParameter('Not a round-robin bracket');
        }
        if ((int)$this->Bracket->tiebreaker_declined === 1) {
            return InvalidParameter('Tiebreaker has already been declined for this bracket');
        }

        // All matches must be resolved (no in-progress matches anywhere in the bracket)
        $unresolved = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0 AND voided = 0");
        if ($unresolved && $unresolved->next() && (int)$unresolved->cnt > 0) {
            return InvalidParameter('Cannot start tiebreaker — ' . (int)$unresolved->cnt . ' match(es) still unresolved');
        }

        $tied = $this->getRoundRobinTopTied($bracket_id);
        if (count($tied) < 2) {
            return InvalidParameter('No first-place tie to break');
        }

        // Compute next round number and order
        $maxRR = $this->db->query("SELECT MAX(round) AS r, MAX(`order`) AS o FROM " . DB_PREFIX . "match WHERE bracket_id = $bracket_id");
        if (!$maxRR || !$maxRR->next()) {
            return InvalidParameter('No matches found in bracket');
        }
        $next_round = (int)$maxRR->r + 1;
        $next_order = (int)$maxRR->o + 1;

        // Snapshot Best-of-N from the parent bracket. This is set per-match implicitly
        // because the match table doesn't carry a best_of column — the bracket-level
        // best_of applies at result-recording time. We don't need to copy it here.

        // Insert one match per pair of tied players. Clear stale PDO bindings left by
        // the yapo find() above before the raw inserts.
        $this->db->Clear();
        $match_num = 1;
        $count = count($tied);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $this->insert_match($bracket_id, $tournament_id, $next_round, $match_num, $next_order, $tied[$i], $tied[$j], 'tiebreaker');
                $match_num++;
                $next_order++;
            }
        }

        // Reopen the bracket — status returns to 'active' so result recording can proceed
        $this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'active' WHERE bracket_id = $bracket_id");

        return Success(['Round' => $next_round, 'TiedCount' => $count, 'MatchesCreated' => ($count * ($count - 1)) / 2]);
    }

    /**
     * DeclineRoundRobinTiebreaker($request)
     * Organizer accepts joint winners. Sets a sticky flag so the banner
     * doesn't re-surface on reload, and finalizes the bracket. The standings
     * function already produces shared rank-1 with next rank skipping, so
     * no rank rewrite is needed here.
     *
     * Request: Token, TournamentId, BracketId
     */
    public function DeclineRoundRobinTiebreaker($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!$this->bracketBelongsTo($bracket_id, (int)($request['TournamentId'] ?? 0))) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        if (!$this->Bracket->find()) {
            return InvalidParameter('Bracket not found');
        }
        if ($this->Bracket->method !== 'round-robin') {
            return InvalidParameter('Not a round-robin bracket');
        }

        // All matches must be resolved before declining
        $unresolved = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0 AND voided = 0");
        if ($unresolved && $unresolved->next() && (int)$unresolved->cnt > 0) {
            return InvalidParameter('Cannot decline tiebreaker with unresolved matches (' . (int)$unresolved->cnt . ' remaining)');
        }

        // Clear stale PDO bindings left by the yapo find() above before the raw update.
        $this->db->Clear();
        $this->db->query("UPDATE " . DB_PREFIX . "bracket SET tiebreaker_declined = 1, status = 'finalized' WHERE bracket_id = $bracket_id");

        return Success($bracket_id);
    }

    // =========================================================================
    // Tournament-detail / management helpers (relocated from controllers)
    // =========================================================================

    /**
     * SaveStandingsPoints($request)
     * Persists the standings-points scoring array on the tournament record.
     * Request: Token, TournamentId, Points (array of position scores, 1-16 long)
     */
    public function SaveStandingsPoints($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }

        $points = $request['Points'] ?? null;
        if (!is_array($points) || count($points) < 1 || count($points) > 16) {
            return InvalidParameter('Invalid points data (must be 1-16 positions).');
        }
        // Round to the nearest half point; keep whole values as ints so the stored JSON stays clean.
        $points_clean = array_map(function ($v) {
            $n = max(0, round(((float)$v) * 2) / 2);
            return ($n == (int)$n) ? (int)$n : $n;
        }, $points);

        $this->db->query(
            "UPDATE " . DB_PREFIX . "tournament SET standings_points = :points WHERE tournament_id = :tid",
            [':points' => json_encode($points_clean), ':tid' => $tournament_id]
        );

        return Success($points_clean);
    }

    /**
     * ReorderSeeds($request)
     * Reassigns seed order for the participants of a bracket. Blocked when the
     * bracket is active/complete/finalized. Per-row updates run in a transaction.
     * Request: Token, TournamentId, BracketId, Order (array seedIndex => participantId)
     */
    public function ReorderSeeds($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!$this->bracketBelongsTo($bracket_id, (int)($request['TournamentId'] ?? 0))) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        $order = $request['Order'] ?? null;
        if (!is_array($order)) {
            return InvalidParameter('Invalid order data.');
        }

        // Block reordering on brackets that are already active, complete, or finalized
        $bstatus_r = $this->db->query(
            "SELECT status FROM " . DB_PREFIX . "bracket WHERE bracket_id = :bid",
            [':bid' => $bracket_id]
        );
        if (!$bstatus_r || !$bstatus_r->next()) {
            return InvalidParameter('Bracket not found.');
        }
        $bstatus = $bstatus_r->status ?? '';
        if (in_array($bstatus, ['active', 'complete', 'finalized'], true)) {
            return InvalidParameter('Cannot reorder seeds on an active or completed bracket.');
        }

        // Resolve the valid participant IDs that belong to this bracket
        $validPids = [];
        $pRows = $this->db->query(
            "SELECT participant_id FROM " . DB_PREFIX . "participant WHERE bracket_id = :bid",
            [':bid' => $bracket_id]
        );
        if ($pRows) {
            while ($pRows->next()) {
                $validPids[(int)$pRows->participant_id] = true;
            }
        }

        $this->db->query('START TRANSACTION');
        try {
            foreach ($order as $seed => $participant_id) {
                $pid = (int)$participant_id;
                $s   = (int)$seed + 1;
                if (valid_id($pid) && isset($validPids[$pid])) {
                    $this->db->query(
                        "UPDATE " . DB_PREFIX . "participant SET seed = :s WHERE participant_id = :pid AND bracket_id = :bid",
                        [':s' => $s, ':pid' => $pid, ':bid' => $bracket_id]
                    );
                }
            }
            $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
            $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
            $seq = $this->tnEmitEvent((int)$request['TournamentId'], $bracket_id, 'seeds_reordered', [
                'bracket_id' => $bracket_id,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            return ProcessingError('Failed to reorder seeds.');
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq((int)$request['TournamentId'], $seq);
        return Success($bracket_id);
    }

    /**
     * UpdateParticipantStatus($request)
     * Sets a participant's status (active/withdrawn/disqualified), validating that
     * the participant belongs to the supplied bracket.
     * Request: Token, TournamentId, BracketId, ParticipantId, Status
     */
    public function UpdateParticipantStatus($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $bracket_id     = (int)($request['BracketId'] ?? 0);
        $participant_id = (int)($request['ParticipantId'] ?? 0);
        $tournament_id  = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!valid_id($participant_id)) {
            return InvalidParameter('ParticipantId required');
        }

        $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
        $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');

        $exists = $this->db->query(
            "SELECT participant_id FROM " . DB_PREFIX . "participant WHERE participant_id = :pid AND bracket_id = :bid",
            [':pid' => $participant_id, ':bid' => $bracket_id]
        );
        if (!$exists || !$exists->next()) {
            return InvalidParameter('Participant not found in this bracket.');
        }

        $status  = trim($request['Status'] ?? '');
        $allowed = ['active', 'withdrawn', 'disqualified'];
        if (!in_array($status, $allowed, true)) {
            return InvalidParameter('Invalid status. Allowed: ' . implode(', ', $allowed));
        }

        // Withdrawal resolution mode (round-robin only): 'forfeit' | 'annul'.
        $mode = trim($request['Mode'] ?? '');
        if (!in_array($mode, ['forfeit', 'annul'], true)) {
            $mode = '';
        }

        // Look up the bracket method for resolution dispatch.
        $brow = $this->db->query("SELECT tournament_id, method FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id LIMIT 1");
        $brow && $brow->next();
        $b_tid  = $brow ? (int)$brow->tournament_id : $tournament_id;
        $method = $brow ? (string)$brow->method : '';

        // Guard against cross-tournament IDOR: check_auth authorized against the request
        // TournamentId, so the bracket that actually owns this participant must match it.
        if ($b_tid !== $tournament_id) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        $this->db->query('START TRANSACTION');
        try {
            if ($status === 'active') {
                // Reactivate: clear withdrawn state and un-eliminate.
                $this->db->query(
                    "UPDATE " . DB_PREFIX . "participant SET status = 'active', withdraw_mode = NULL, eliminated = 0 WHERE participant_id = $participant_id AND bracket_id = $bracket_id"
                );
                if ($method === 'round-robin') {
                    $this->resolveRoundRobinWithdrawals($bracket_id, $b_tid);
                } elseif (in_array($method, ['single', 'double', 'swiss'], true)) {
                    $revErr = $this->reverseEliminationWithdrawal($bracket_id, $b_tid, $participant_id, $request);
                    if ($revErr !== null) {
                        $this->db->query('ROLLBACK');
                        return InvalidParameter($revErr);
                    }
                }
            } else {
                $mode_sql = ($method === 'round-robin' && $mode !== '') ? "'" . $mode . "'" : 'NULL';
                $this->db->query(
                    "UPDATE " . DB_PREFIX . "participant SET status = '" . $status . "', withdraw_mode = $mode_sql WHERE participant_id = $participant_id AND bracket_id = $bracket_id"
                );
                if ($method === 'round-robin') {
                    // Recompute match voiding/forfeits so the bracket can complete in place.
                    $this->resolveRoundRobinWithdrawals($bracket_id, $b_tid);
                } elseif (in_array($method, ['single', 'double', 'swiss'], true)) {
                    // Elimination/Swiss: the withdrawn player is out; their pending match
                    // becomes a walkover for the opponent, cascading forward.
                    $this->db->query("UPDATE " . DB_PREFIX . "participant SET eliminated = 1 WHERE participant_id = $participant_id AND bracket_id = $bracket_id");
                    $wErr = $this->resolveEliminationWalkovers($bracket_id, $b_tid);
                    if ($wErr !== null) {
                        $this->db->query('ROLLBACK');
                        return InvalidParameter($wErr);
                    }
                }
                // Points: standings exclude non-active participants (annul). Ironman: status only.
            }

            $seq = $this->tnEmitEvent($b_tid, $bracket_id, 'participant_status', [
                'participant_id' => $participant_id,
                'status'         => $status,
                'mode'           => $mode,
            ], $actor_id, $action_id !== '' ? $action_id : null);

            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq($b_tid, $seq);
        return Success(['ParticipantId' => $participant_id, 'Status' => $status, 'Mode' => $mode, 'Seq' => $seq]);
    }

    /**
     * Recompute round-robin match resolution from the current withdrawn set.
     * Idempotent: derives every match's state from participant status/withdraw_mode,
     * so it is safe to call on each status change (multiple withdrawals, re-activation).
     *
     *  - annul-withdrawn participant: ALL their matches voided=1 (excluded everywhere).
     *  - forfeit-withdrawn participant: their UNPLAYED matches vs an ACTIVE opponent get
     *    the opponent-win result written (auto_resolved=1); already-played matches stay.
     *  - a match between two non-active participants is voided (no rightful winner).
     *  - re-activation: prior auto-written forfeits revert to unplayed; voids fall away
     *    because everything is recomputed from scratch each call.
     */
    private function resolveRoundRobinWithdrawals(int $bracket_id, int $tournament_id)
    {
        // 1) Reset derived state: clear all voids; revert ONLY auto-written results.
        $this->db->query("UPDATE " . DB_PREFIX . "match SET voided = 0 WHERE bracket_id = $bracket_id");
        $this->db->query("UPDATE " . DB_PREFIX . "match SET result = NULL, score = NULL, auto_resolved = 0 WHERE bracket_id = $bracket_id AND auto_resolved = 1");

        // 2) Current non-active participants by mode.
        $pr = $this->db->query("SELECT participant_id, status, withdraw_mode FROM " . DB_PREFIX . "participant WHERE bracket_id = $bracket_id AND status NOT IN ('active','')");
        $annul = [];
        $forfeit = [];
        if ($pr) {
            while ($pr->next()) {
                $pid = (int)$pr->participant_id;
                $wm  = (string)$pr->withdraw_mode;
                if ($wm === 'annul') {
                    $annul[] = $pid;
                } else {
                    $forfeit[] = $pid;
                } // default (incl. disqualified / no mode) = forfeit
            }
        }

        // 3) Annul: void every match touching an annulled participant (played + unplayed).
        if (!empty($annul)) {
            $list = implode(',', array_map('intval', $annul));
            $this->db->query("UPDATE " . DB_PREFIX . "match SET voided = 1
				WHERE bracket_id = $bracket_id AND (participant_1_id IN ($list) OR participant_2_id IN ($list))");
        }

        // 4) Void UNPLAYED matches between two non-active participants (no rightful winner).
        //    MUST run before the forfeit-win pass so those matches are excluded from it.
        $nonActive = array_merge($annul, $forfeit);
        if (!empty($nonActive)) {
            $na = implode(',', array_map('intval', $nonActive));
            $this->db->query("UPDATE " . DB_PREFIX . "match SET voided = 1
				WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '')
				  AND participant_1_id IN ($na) AND participant_2_id IN ($na)");
        }

        // 5) Forfeit: write opponent-win on each forfeited participant's UNPLAYED,
        //    non-voided matches (opponent is now guaranteed active).
        foreach ($forfeit as $pid) {
            $ms = $this->db->query("SELECT match_id, participant_1_id, participant_2_id FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND voided = 0 AND (result IS NULL OR result = '')
				  AND (participant_1_id = $pid OR participant_2_id = $pid)
				  AND participant_1_id > 0 AND participant_2_id > 0");
            $rows = [];
            if ($ms) {
                while ($ms->next()) {
                    $rows[] = [(int)$ms->match_id, (int)$ms->participant_1_id, (int)$ms->participant_2_id];
                }
            }
            foreach ($rows as $row) {
                [$mid, $p1, $p2] = $row;
                $res = ($p1 === $pid) ? '2-wins' : '1-wins'; // the OTHER side (opponent) wins
                $this->db->query("UPDATE " . DB_PREFIX . "match SET result = '$res', auto_resolved = 1
					WHERE match_id = $mid AND (result IS NULL OR result = '')");
            }
        }

        // 6) Completion / reopen based on remaining non-voided, unplayed real matches.
        $unresolved = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0 AND voided = 0");
        if ($unresolved && $unresolved->next() && (int)$unresolved->cnt === 0) {
            $this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'complete' WHERE bracket_id = $bracket_id AND status NOT IN ('finalized','setup')");
        } else {
            // Re-activation may have re-opened matches -> bring a completed bracket back to active.
            $this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'active' WHERE bracket_id = $bracket_id AND status = 'complete'");
        }
    }

    /**
     * UpdateAlias($request)
     * Renames a participant's alias. Because each bracket keeps its own copy of a
     * participant row, the rename propagates to every row sharing this person's
     * tournament-wide participant_number, so the alias stays consistent across all
     * brackets. Falls back to the single row when no stable number is assigned.
     * Request: Token, TournamentId, BracketId, ParticipantId, Alias
     */
    public function UpdateAlias($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $tournament_id  = (int)($request['TournamentId'] ?? 0);
        $bracket_id     = (int)($request['BracketId'] ?? 0);
        $participant_id = (int)($request['ParticipantId'] ?? 0);
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required');
        }
        if (!valid_id($participant_id)) {
            return InvalidParameter('ParticipantId required');
        }

        $alias = trim($request['Alias'] ?? '');
        if ($alias === '') {
            return InvalidParameter('Alias cannot be empty.');
        }
        if (mb_strlen($alias) > 100) {
            $alias = mb_substr($alias, 0, 100);
        }

        // Confirm the participant belongs to this bracket/tournament and grab its
        // tournament-wide participant_number (stable per person across brackets).
        $row = $this->db->query(
            "SELECT participant_number FROM " . DB_PREFIX . "participant
			 WHERE participant_id = :pid AND bracket_id = :bid AND tournament_id = :tid LIMIT 1",
            [':pid' => $participant_id, ':bid' => $bracket_id, ':tid' => $tournament_id]
        );
        if (!$row || !$row->next()) {
            return InvalidParameter('Participant not found in this bracket.');
        }
        $pnum = (int)$row->participant_number;

        // Rename every row sharing this participant_number in the tournament so the
        // alias stays consistent across brackets. Fall back to the single row when no
        // stable number is assigned (legacy/edge rows with participant_number = 0).
        $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);

        $this->db->query('START TRANSACTION');
        try {
            if ($pnum > 0) {
                $this->db->query(
                    "UPDATE " . DB_PREFIX . "participant SET alias = :alias
					 WHERE tournament_id = :tid AND participant_number = :pnum",
                    [':alias' => $alias, ':tid' => $tournament_id, ':pnum' => $pnum]
                );
            } else {
                $this->db->query(
                    "UPDATE " . DB_PREFIX . "participant SET alias = :alias WHERE participant_id = :pid",
                    [':alias' => $alias, ':pid' => $participant_id]
                );
            }
            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'alias_updated', [
                'participant_id' => $participant_id,
                'alias'          => $alias,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tournament_id, $seq);
        return Success(['ParticipantId' => $participant_id, 'Alias' => $alias]);
    }

    /**
     * SearchParks($query)
     * Park autocomplete: name LIKE match, joined to kingdom. Read-only.
     */
    public function SearchParks($query)
    {
        $q = trim((string)$query);
        if (strlen($q) < 2) {
            return Success([]);
        }

        $rows = $this->db->query(
            "SELECT p.park_id, p.name AS park_name, k.kingdom_id, k.name AS kingdom_name "
            . "FROM " . DB_PREFIX . "park p "
            . "LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id "
            . "WHERE p.name LIKE :q "
            . "ORDER BY p.name LIMIT 12",
            [':q' => '%' . $q . '%']
        );
        $results = [];
        if ($rows) {
            while ($rows->next()) {
                $results[] = [
                    'ParkId'      => (int)$rows->park_id,
                    'ParkName'    => $rows->park_name,
                    'KingdomId'   => (int)$rows->kingdom_id,
                    'KingdomName' => $rows->kingdom_name,
                ];
            }
        }
        return Success($results);
    }

    /**
     * SearchEvents($query, $kingdom_id = 0)
     * Event autocomplete: event name LIKE match, joined to calendar detail,
     * kingdom and park for the display abbreviation. Read-only. A kingdom_id > 0
     * limits results to that kingdom's events (directly or via one of its parks).
     */
    public function SearchEvents($query, $kingdom_id = 0)
    {
        $q = trim((string)$query);
        if (strlen($q) < 2) {
            return Success([]);
        }
        $kingdom_id = (int)$kingdom_id;
        $params     = [':q' => '%' . $q . '%'];
        $scopeSql   = '';
        if ($kingdom_id > 0) {
            // Scope to the kingdom: kingdom-level events or events hosted by one of its parks.
            $scopeSql        = "AND (e.kingdom_id = :kid OR p.kingdom_id = :kid2) ";
            $params[':kid']  = $kingdom_id;
            $params[':kid2'] = $kingdom_id;
        }

        $rows = $this->db->query(
            "SELECT cd.event_calendardetail_id, e.name AS event_name, "
            . "k.abbreviation AS kingdom_abbr, p.abbreviation AS park_abbr, "
            . "cd.event_start "
            . "FROM " . DB_PREFIX . "event_calendardetail cd "
            . "JOIN " . DB_PREFIX . "event e ON e.event_id = cd.event_id "
            . "LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = e.kingdom_id "
            . "LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = e.park_id "
            . "WHERE e.name LIKE :q "
            . $scopeSql
            . "ORDER BY cd.event_start DESC LIMIT 12",
            $params
        );
        $results = [];
        if ($rows) {
            while ($rows->next()) {
                $abbr = '';
                if ($rows->kingdom_abbr) {
                    $abbr = $rows->kingdom_abbr;
                }
                if ($rows->park_abbr) {
                    $abbr .= ($abbr ? ':' : '') . $rows->park_abbr;
                }
                $dateStr = '';
                if ($rows->event_start && substr($rows->event_start, 0, 10) !== '0000-00-00') {
                    $dateStr = date('M j, Y', strtotime($rows->event_start));
                }
                $label = $rows->event_name;
                if ($abbr) {
                    $label .= ' ' . $abbr;
                }
                if ($dateStr) {
                    $label .= ' - ' . $dateStr;
                }
                $results[] = [
                    'EcdId'     => (int)$rows->event_calendardetail_id,
                    'Label'     => $label,
                    'EventName' => $rows->event_name,
                ];
            }
        }
        return Success($results);
    }

    /**
     * GetTournamentEventLabel($tournament_id)
     * Returns a formatted "Event KABBR:PABBR - Mon D, YYYY" label for the
     * tournament's linked event calendar detail (or '' if none).
     */
    public function GetTournamentEventLabel($tournament_id)
    {
        $tournament_id = (int)$tournament_id;
        if (!valid_id($tournament_id)) {
            return Success('');
        }

        $tr = $this->db->query(
            "SELECT event_calendardetail_id, name FROM " . DB_PREFIX . "tournament WHERE tournament_id = :tid",
            [':tid' => $tournament_id]
        );
        if (!$tr || !$tr->next()) {
            return Success('');
        }
        $ecd  = (int)$tr->event_calendardetail_id;
        $name = $tr->name ?? '';
        if (!valid_id($ecd)) {
            return Success('');
        }

        $r = $this->db->query(
            "SELECT k.abbreviation AS kabbr, p.abbreviation AS pabbr, d.event_start, e.name AS event_name "
            . "FROM " . DB_PREFIX . "event_calendardetail d "
            . "LEFT JOIN " . DB_PREFIX . "event e ON e.event_id = d.event_id "
            . "LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = e.kingdom_id "
            . "LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = e.park_id "
            . "WHERE d.event_calendardetail_id = :ecd",
            [':ecd' => $ecd]
        );
        if (!$r || !$r->next()) {
            return Success($name);
        }

        $abbr = '';
        if ($r->kabbr) {
            $abbr = $r->kabbr;
        }
        if ($r->pabbr) {
            $abbr .= ($abbr ? ':' : '') . $r->pabbr;
        }
        $ds = ($r->event_start && substr($r->event_start, 0, 10) !== '0000-00-00')
            ? date('M j, Y', strtotime($r->event_start)) : '';
        $lbl = $r->event_name ?? $name;
        if ($abbr) {
            $lbl .= ' ' . $abbr;
        }
        if ($ds) {
            $lbl .= ' - ' . $ds;
        }
        return Success($lbl);
    }

    /**
     * GetStandingsPoints($tournament_id)
     * Returns the persisted standings-points array (8-long) or the default
     * [5,4,3,2,1,0,0,0] when none is set / invalid. Bypasses report cache.
     */
    public function GetStandingsPoints($tournament_id)
    {
        $tournament_id = (int)$tournament_id;
        $default = [5, 4, 3, 2, 1, 0, 0, 0];
        if (!valid_id($tournament_id)) {
            return Success($default);
        }

        $r = $this->db->query(
            "SELECT standings_points FROM " . DB_PREFIX . "tournament WHERE tournament_id = :tid",
            [':tid' => $tournament_id]
        );
        if ($r && $r->next() && !empty($r->standings_points)) {
            $parsed = json_decode($r->standings_points, true);
            if (is_array($parsed) && count($parsed) === 8) {
                return Success($parsed);
            }
        }
        return Success($default);
    }

    /**
     * List reeves for a tournament. Requires manage-level auth (check_auth).
     * Returns rows: { MundaneId, Persona, Role }.
     */
    public function GetReeves($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }

        $sql = "SELECT r.mundane_id, r.role, m.persona
				FROM " . DB_PREFIX . "tournament_reeve r
				LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = r.mundane_id
				WHERE r.tournament_id = $tournament_id
				ORDER BY r.role ASC, m.persona ASC";
        $r = $this->db->query($sql);
        $reeves = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $reeves[] = [
                    'MundaneId' => (int)$r->mundane_id,
                    'Persona'   => $r->persona,
                    'Role'      => $r->role,
                ];
            }
        }
        return Success($reeves);
    }

    /**
     * Add or update a reeve for a tournament. Organizer-level only (check_auth).
     * Upserts on (tournament_id, mundane_id) — re-adding a person updates their role.
     * Params: TournamentId, MundaneId, Role ('organizer' | 'bracket_runner').
     */
    public function AddReeve($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $tournament_id = (int)($request['TournamentId'] ?? 0);
        $mundane_id    = (int)($request['MundaneId'] ?? 0);
        $role          = trim($request['Role'] ?? '');

        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }
        if (!valid_id($mundane_id)) {
            return InvalidParameter('MundaneId required');
        }
        if (!in_array($role, ['organizer', 'bracket_runner'], true)) {
            return InvalidParameter('Invalid role');
        }

        // Verify the mundane exists.
        $mr = $this->db->query("SELECT mundane_id FROM " . DB_PREFIX . "mundane WHERE mundane_id = $mundane_id LIMIT 1");
        if (!$mr || $mr->size() === 0) {
            return InvalidParameter('Player not found');
        }

        $actor_id = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $this->db->query('START TRANSACTION');
        try {
            $this->db->query(
                "INSERT INTO " . DB_PREFIX . "tournament_reeve (tournament_id, mundane_id, role)
					VALUES (:tid, :mid, :role)
					ON DUPLICATE KEY UPDATE role = :role2",
                [':tid' => $tournament_id, ':mid' => $mundane_id, ':role' => $role, ':role2' => $role]
            );
            // Audit / live-collab change-log entry so peers see the reeve roster change.
            $seq = $this->tnEmitEvent($tournament_id, 0, 'reeve_added', [
                'mundane_id' => $mundane_id,
                'role'       => $role,
            ], $actor_id);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->tnPublishSeq($tournament_id, $seq);
        return Success(['MundaneId' => $mundane_id, 'Role' => $role]);
    }

    /**
     * Remove a reeve from a tournament. Organizer-level only (check_auth).
     * Params: TournamentId, MundaneId.
     */
    public function RemoveReeve($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        $tournament_id = (int)($request['TournamentId'] ?? 0);
        $mundane_id    = (int)($request['MundaneId'] ?? 0);
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }
        if (!valid_id($mundane_id)) {
            return InvalidParameter('MundaneId required');
        }

        $actor_id = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $this->db->query('START TRANSACTION');
        try {
            $this->db->query(
                "DELETE FROM " . DB_PREFIX . "tournament_reeve WHERE tournament_id = :tid AND mundane_id = :mid",
                [':tid' => $tournament_id, ':mid' => $mundane_id]
            );
            // Audit / live-collab change-log entry so peers see the reeve roster change.
            $seq = $this->tnEmitEvent($tournament_id, 0, 'reeve_removed', [
                'mundane_id' => $mundane_id,
            ], $actor_id);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        $this->tnPublishSeq($tournament_id, $seq);
        return Success(['MundaneId' => $mundane_id]);
    }

    /**
     * Returns the caller's own reeve role for a tournament (token-resolved).
     * No manage gate — a user may always see their own role. Returns
     * Success(['Role' => 'organizer'|'bracket_runner'|null]).
     */
    public function GetReeveRole($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($mundane_id) || !valid_id($tournament_id)) {
            return Success(['Role' => null]);
        }
        return Success(['Role' => $this->get_reeve_role($mundane_id, $tournament_id)]);
    }

    /**
     * Append a change-log event and advance the tournament's seq cursor.
     * MUST be called inside a transaction owned by the caller — the cursor bump
     * and the event insert commit atomically with the mutation they describe.
     * Returns the new seq (int). Caller should refresh the Memcache mirror AFTER
     * its COMMIT via tnPublishSeq().
     *
     * $payload is an associative array (json-encoded here). $action_id is the
     * client-supplied UUID for echo-dedup/idempotency, or null.
     */
    private function tnEmitEvent($tournament_id, $bracket_id, $type, array $payload, $actor_id = 0, $action_id = null)
    {
        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "tournament_seq (tournament_id, last_seq)
			 VALUES (:tid, 1)
			 ON DUPLICATE KEY UPDATE last_seq = last_seq + 1",
            [':tid' => $tournament_id]
        );
        $sr  = $this->db->query("SELECT last_seq FROM " . DB_PREFIX . "tournament_seq WHERE tournament_id = :tid", [':tid' => $tournament_id]);
        $seq = ($sr && $sr->next()) ? (int)$sr->last_seq : 0;

        $actor_name = $actor_id > 0 ? $this->tnActorName($actor_id) : '';

        // INSERT IGNORE: a retried request reuses its action_id; the (tournament_id,
        // action_id) unique key makes the duplicate a silent no-op. The cursor has
        // already advanced, so peers simply fetch and find nothing new for this seq.
        $this->db->query(
            "INSERT IGNORE INTO " . DB_PREFIX . "tournament_event
			   (tournament_id, bracket_id, seq, type, payload, actor_id, actor_name, action_id, created)
			 VALUES (:tid, :bid, :seq, :type, :payload, :aid, :aname, :actionid, NOW())",
            [
                ':tid'      => $tournament_id,
                ':bid'      => $bracket_id > 0 ? $bracket_id : null,
                ':seq'      => $seq,
                ':type'     => $type,
                ':payload'  => json_encode($payload) ?: '{}',
                ':aid'      => $actor_id > 0 ? $actor_id : null,
                ':aname'    => $actor_name,
                ':actionid' => $action_id,
            ]
        );
        return $seq;
    }

    /** Mirror the current cursor to Memcache (call AFTER COMMIT). Best-effort. */
    private function tnPublishSeq($tournament_id, $seq)
    {
        try {
            if (isset(Ork3::$Lib->ghettocache)) {
                Ork3::$Lib->ghettocache->counterSet('tnseq.' . (int)$tournament_id, (int)$seq, 60);
            }
        } catch (\Throwable $e) { /* cache is an accelerator; DB is source of truth */
        }
    }

    /**
     * Best-effort display name for an actor (mundane) id, for change-log toasts.
     * ork_mundane has no 'mundane' column; prefers persona, falls back to given_name + surname.
     */
    private function tnActorName($mundane_id)
    {
        $r = $this->db->query("SELECT persona, given_name, surname FROM " . DB_PREFIX . "mundane WHERE mundane_id = " . (int)$mundane_id . " LIMIT 1");
        if ($r && $r->next()) {
            $p = trim((string)$r->persona);
            if ($p !== '') {
                return $p;
            }
            $full = trim(trim((string)$r->given_name) . ' ' . trim((string)$r->surname));
            if ($full !== '') {
                return $full;
            }
        }
        return '';
    }

    /**
     * Ephemeral reeve-presence heartbeat (#36). Maintains a short-lived Memcache map of the
     * reeves currently viewing this tournament so the UI can render an "others here" badge on
     * each bracket. Purely an accelerator — the map lives only in Memcache, never the DB, and a
     * cache miss simply reports this caller alone.
     *
     * Params: TournamentId, BracketId (int, 0 if none), Token.
     * Each call prunes entries whose last heartbeat is older than 25s, upserts the caller with
     * a fresh timestamp, and rewrites the map with a 60s TTL.
     * Returns Success(['presence' => [ {ReeveId, Name, BracketId}, ... ]]).
     */
    public function ReevePresence($request)
    {
        $tid = (int)($request['TournamentId'] ?? 0);
        $bid = (int)($request['BracketId'] ?? 0);
        if (!valid_id($tid)) {
            return InvalidParameter('TournamentId required');
        }

        $actor_id = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if ($actor_id <= 0) {
            return NoAuthorization();
        }
        $name = $this->tnActorName($actor_id);

        $now = time();
        $key = 'tnpresence.' . $tid;
        $map = [];
        try {
            if (isset(Ork3::$Lib->ghettocache) && isset(Ork3::$Lib->ghettocache->memcache)) {
                $mc  = Ork3::$Lib->ghettocache->memcache;
                $raw = $mc->get($key);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $map = $decoded;
                    }
                }
                // Prune entries with no heartbeat in the last 25s.
                foreach ($map as $rid => $ent) {
                    if (!is_array($ent) || (int)($ent['ts'] ?? 0) < $now - 25) {
                        unset($map[$rid]);
                    }
                }
                // Upsert this actor and rewrite with a 60s TTL.
                $map[$actor_id] = ['name' => $name, 'bracket_id' => $bid, 'ts' => $now];
                $mc->set($key, json_encode($map) ?: '{}', 60);
            } else {
                $map[$actor_id] = ['name' => $name, 'bracket_id' => $bid, 'ts' => $now];
            }
        } catch (\Throwable $e) {
            // Cache is an accelerator only — on any failure just report this caller.
            $map = [$actor_id => ['name' => $name, 'bracket_id' => $bid, 'ts' => $now]];
        }

        $presence = [];
        foreach ($map as $rid => $ent) {
            if (!is_array($ent)) {
                continue;
            }
            $presence[] = [
                'ReeveId'   => (int)$rid,
                'Name'      => (string)($ent['name'] ?? ''),
                'BracketId' => (int)($ent['bracket_id'] ?? 0),
            ];
        }
        return Success(['presence' => $presence]);
    }

    /**
     * PUBLIC — no auth. Cheap aggregate version signature for spectator polling.
     * Changes whenever any match result/score, bracket status/set, or participant
     * roster / live state (eliminated / bracket_side / ironman streak) changes.
     * Param: TournamentId. Returns Success(['Version' => md5(...)]).
     */
    public function GetVersion($request)
    {
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }

        // The signature below scans every match + participant row for the tournament,
        // which is wasteful to repeat on each spectator poll. Cache it for a few seconds:
        // spectators already poll on that cadence, any mutation is still reflected within
        // the TTL, and a cache miss simply recomputes. Best-effort — never fatal.
        $cacheName = 'tnver.' . $tournament_id;
        try {
            if (isset(Ork3::$Lib->ghettocache)) {
                $cachedSig = Ork3::$Lib->ghettocache->counterGet($cacheName);
                if (is_string($cachedSig) && $cachedSig !== '') {
                    return Success(['Version' => $cachedSig]);
                }
            }
        } catch (\Throwable $e) { /* fall through to recompute */
        }

        // Match-state component (result/score edits, resets, new matches).
        $mc = 0;
        $msum = 0;
        $mr = $this->db->query(
            "SELECT COUNT(*) AS mc, COALESCE(SUM(CRC32(CONCAT_WS(':', match_id, result, score))),0) AS msum
				FROM " . DB_PREFIX . "match WHERE tournament_id = $tournament_id"
        );
        if ($mr && $mr->next()) {
            $mc = (int)$mr->mc;
            $msum = (string)$mr->msum;
        }

        // Bracket-state component (status changes, add/delete bracket).
        $bsig = '';
        $br = $this->db->query(
            "SELECT GROUP_CONCAT(CONCAT(bracket_id,':',status) ORDER BY bracket_id) AS bsig
				FROM " . DB_PREFIX . "bracket WHERE tournament_id = $tournament_id"
        );
        if ($br && $br->next()) {
            $bsig = (string)$br->bsig;
        }

        // Participant-set component (roster + live elimination / ironman streak changes).
        $pc = 0;
        $psum = 0;
        $pr = $this->db->query(
            "SELECT COUNT(*) AS pc, COALESCE(SUM(CRC32(CONCAT_WS(':', participant_id, eliminated, bracket_side, im_wins, im_current_streak))),0) AS psum
				FROM " . DB_PREFIX . "participant WHERE tournament_id = $tournament_id"
        );
        if ($pr && $pr->next()) {
            $pc = (int)$pr->pc;
            $psum = (string)$pr->psum;
        }

        // Point-state component (grid score entry + configured rounds). Point scoring
        // writes neither ork_match nor ork_participant, so without this a spectator's
        // version never bumps on a score/round change.
        $ptc = 0;
        $ptsum = 0;
        $prounds = '';
        $ptr = $this->db->query(
            "SELECT COUNT(*) AS ptc, COALESCE(SUM(CRC32(CONCAT_WS(':', ps.point_score_id, ps.points))),0) AS ptsum
				FROM " . DB_PREFIX . "point_score ps
				JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = ps.bracket_id
				WHERE b.tournament_id = $tournament_id"
        );
        if ($ptr && $ptr->next()) {
            $ptc = (int)$ptr->ptc;
            $ptsum = (string)$ptr->ptsum;
        }
        $prr = $this->db->query(
            "SELECT GROUP_CONCAT(CONCAT(bracket_id,':',COALESCE(point_rounds,0)) ORDER BY bracket_id) AS pr
				FROM " . DB_PREFIX . "bracket WHERE tournament_id = $tournament_id"
        );
        if ($prr && $prr->next()) {
            $prounds = (string)$prr->pr;
        }

        $sig = md5("{$mc}:{$msum}|{$bsig}|{$pc}:{$psum}|{$ptc}:{$ptsum}|{$prounds}");

        // Short TTL so bursts of spectator polls hit the cache; mutations still surface
        // within a few seconds. Best-effort — cache is an accelerator, DB is truth.
        try {
            if (isset(Ork3::$Lib->ghettocache)) {
                Ork3::$Lib->ghettocache->counterSet($cacheName, $sig, 3);
            }
        } catch (\Throwable $e) { /* ignore cache write failures */
        }

        return Success(['Version' => $sig]);
    }

    /**
     * PUBLIC — no auth. Cheap heartbeat: the current per-tournament seq cursor.
     * Reads Memcache first; on miss, reads ork_tournament_seq and repopulates.
     * Param: TournamentId. Returns Success(['Seq' => int]).
     */
    public function GetSeq($request)
    {
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }
        // The client may pass its last-known seq so we can detect a counter that has gone
        // backwards (see the resync note below).
        $known = (int)($request['Known'] ?? 0);

        $seq = Ork3::$Lib->ghettocache->counterGet('tnseq.' . $tournament_id);
        if ($seq === false || $seq === null) {
            // Cache miss — cold start, TTL expiry, OR a global Memcache flush wiped the
            // mirror. The ork_tournament_seq row is the persistent source of truth and
            // survives a flush, so reload from it and repopulate the mirror; the cursor
            // itself is never lost to a cache flush.
            $sr  = $this->db->query("SELECT last_seq FROM " . DB_PREFIX . "tournament_seq WHERE tournament_id = $tournament_id");
            $seq = ($sr && $sr->next()) ? (int)$sr->last_seq : 0;
            Ork3::$Lib->ghettocache->counterSet('tnseq.' . $tournament_id, $seq, 60);
        }
        $seq = (int)$seq;

        // Self-heal signal: if the caller is already ahead of the authoritative cursor, the
        // seq was reset out from under it (e.g. the tournament's seq row was rebuilt after a
        // flush, or a stale-low cache value). Tell the client to fall back to a full resync
        // rather than silently stalling on events it can never receive. The tpl client acts
        // on this flag; older clients that don't send Known are unaffected.
        if ($known > 0 && $seq < $known) {
            return Success(['Seq' => $seq, 'Resync' => true]);
        }
        return Success(['Seq' => $seq]);
    }

    /**
     * PUBLIC — no auth (read-only delta feed). Ordered events with seq > Since.
     * If Since is ahead of the high-water mark, or behind the oldest retained
     * event, returns ['Resync' => true] so the client falls back to a full refresh.
     * Params: TournamentId, Since. Returns Success(['Events' => [...], 'Seq' => int]).
     */
    public function GetChanges($request)
    {
        $tournament_id = (int)($request['TournamentId'] ?? 0);
        $since         = (int)($request['Since'] ?? 0);
        if (!valid_id($tournament_id)) {
            return InvalidParameter('TournamentId required');
        }

        $sr   = $this->db->query("SELECT last_seq FROM " . DB_PREFIX . "tournament_seq WHERE tournament_id = $tournament_id");
        $high = ($sr && $sr->next()) ? (int)$sr->last_seq : 0;

        if ($since > $high) {
            return Success(['Resync' => true, 'Seq' => $high]);
        }

        $mr     = $this->db->query("SELECT MIN(seq) AS m FROM " . DB_PREFIX . "tournament_event WHERE tournament_id = $tournament_id");
        $minSeq = ($mr && $mr->next() && $mr->m !== null) ? (int)$mr->m : 0;
        if ($since > 0 && $minSeq > 0 && $since < $minSeq - 1) {
            return Success(['Resync' => true, 'Seq' => $high]);
        }

        $limit = 500;
        $rows = $this->db->query(
            "SELECT seq, bracket_id, type, payload, actor_id, actor_name, action_id
			   FROM " . DB_PREFIX . "tournament_event
			  WHERE tournament_id = $tournament_id AND seq > $since
			  ORDER BY seq ASC LIMIT $limit"
        );
        $events  = [];
        $lastSeq = $since;
        if ($rows) {
            while ($rows->next()) {
                $lastSeq  = (int)$rows->seq;
                $events[] = [
                    'Seq'       => (int)$rows->seq,
                    'BracketId' => $rows->bracket_id !== null ? (int)$rows->bracket_id : null,
                    'Type'      => (string)$rows->type,
                    'Payload'   => json_decode((string)$rows->payload, true),
                    'ActorId'   => $rows->actor_id !== null ? (int)$rows->actor_id : null,
                    'ActorName' => (string)$rows->actor_name,
                    'ActionId'  => (string)$rows->action_id,
                ];
            }
        }

        // Truncation guard: if this batch filled the LIMIT and more events remain past the
        // last one returned, report the last DELIVERED seq — NOT the high-water mark — so the
        // client's next poll resumes from there and picks up the remainder. Reporting $high
        // here would make the client believe it caught up and silently skip events 501+.
        $reportSeq = (count($events) >= $limit && $lastSeq < $high) ? $lastSeq : $high;
        return Success(['Events' => $events, 'Seq' => $reportSeq]);
    }


    /**
     * Upsert a single grid cell for a Points bracket. $request['Points'] may be
     * null to clear. Returns Success({ Cell, Standings }) so the client can
     * update Total + standings ribbon in a single round trip.
     */
    public function SavePointScore($request)
    {
        if (!$this->can_run_brackets($request)) {
            return NoAuthorization();
        }

        $bracket_id     = (int)($request['BracketId'] ?? 0);
        $participant_id = (int)($request['ParticipantId'] ?? 0);
        $round          = (int)($request['Round'] ?? 0);
        $rawPoints      = $request['Points'] ?? null;

        if (!valid_id($bracket_id) || !valid_id($participant_id) || $round < 1) {
            return InvalidParameter('BracketId, ParticipantId, Round required.');
        }
        if (!$this->bracketBelongsTo($bracket_id, (int)($request['TournamentId'] ?? 0))) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        if (!$this->Bracket->find()) {
            return InvalidParameter('Bracket not found.');
        }
        if ($this->Bracket->method !== 'points') {
            return InvalidParameter('Bracket is not a Points bracket.');
        }
        if ($this->Bracket->status === 'finalized') {
            return InvalidParameter('Bracket is finalized.');
        }
        if (in_array((string)$this->Bracket->status, ['setup', ''], true)) {
            return InvalidParameter('Generate the bracket before scoring.');
        }
        $maxRound = (int)$this->Bracket->point_rounds;
        if ($round > $maxRound) {
            return InvalidParameter("Round $round exceeds configured $maxRound.");
        }

        $ok = false;
        $r = $this->db->query("SELECT 1 FROM " . DB_PREFIX . "participant WHERE participant_id = $participant_id AND bracket_id = $bracket_id LIMIT 1");
        if ($r && $r->next()) {
            $ok = true;
        }
        if (!$ok) {
            return InvalidParameter('Participant not in this bracket.');
        }

        $pointsValue = null;
        if ($rawPoints !== null && $rawPoints !== '') {
            if (!preg_match('/^\d+(\.\d{1,2})?$/', (string)$rawPoints)) {
                return InvalidParameter('Points must be a non-negative decimal with up to 2 decimal places.');
            }
            $f = (float)$rawPoints;
            if ($f < 0 || $f > 999.99) {
                return InvalidParameter('Points out of range (0-999.99).');
            }

            if ($this->Bracket->point_mode === 'fixed') {
                $scaleRaw = (string)$this->Bracket->point_scale;
                $scale = array_map('trim', explode(',', $scaleRaw));
                $allowedKeys = array_map(fn ($v) => number_format((float)$v, 2, '.', ''), $scale);
                $thisKey = number_format($f, 2, '.', '');
                if (!in_array($thisKey, $allowedKeys, true)) {
                    return InvalidParameter("Points value not in the bracket's fixed scale.");
                }
            }
            $pointsValue = number_format($f, 2, '.', '');
        }

        // Resolve the actor from the auth token — the lib layer has no session accessor.
        $scoredBy = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $scoredByClause = $scoredBy > 0 ? $scoredBy : 'NULL';
        $pointsClause = ($pointsValue === null) ? 'NULL' : "'$pointsValue'";

        $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
        $tournament_id = (int)($request['TournamentId'] ?? 0);

        // Wrap the score write + its change-log emit in one transaction: tnEmitEvent's
        // increment-then-read of the seq cursor must be atomic with the mutation, or two
        // concurrent scorers can read the same seq (see SaveMatchResult/RecordIronmanWin).
        $this->db->query('START TRANSACTION');
        try {
            $this->db->Clear();
            $sql = "INSERT INTO " . DB_PREFIX . "point_score
					(bracket_id, participant_id, round, points, scored_at, scored_by)
					VALUES ($bracket_id, $participant_id, $round, $pointsClause, NOW(), $scoredByClause)
					ON DUPLICATE KEY UPDATE
					points = $pointsClause,
					scored_at = NOW(),
					scored_by = $scoredByClause";
            $this->db->query($sql);

            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'point_score', [
                'participant_id' => $participant_id,
                'round'          => $round,
                'points'         => $pointsValue,
            ], $scoredBy, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $standings = $this->GetPointStandings(['BracketId' => $bracket_id]);
        $detail = [
            'Cell' => [
                'ParticipantId' => $participant_id,
                'Round'         => $round,
                'Points'        => $pointsValue,
            ],
            'Standings' => $standings['Detail'] ?? [],
            'Seq'       => $seq,
        ];
        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tournament_id, $seq);
        return Success($detail);
    }

    /**
     * Append a new round (increments point_rounds by 1). Capped at 32.
     */
    public function AddPointsRound($request)
    {
        if (!$this->can_run_brackets($request)) {
            return NoAuthorization();
        }
        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required.');
        }
        if (!$this->bracketBelongsTo($bracket_id, (int)($request['TournamentId'] ?? 0))) {
            return InvalidParameter('Bracket does not belong to this tournament.');
        }

        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        if (!$this->Bracket->find()) {
            return InvalidParameter('Bracket not found.');
        }
        if ($this->Bracket->method !== 'points') {
            return InvalidParameter('Not a Points bracket.');
        }
        if ($this->Bracket->status === 'finalized') {
            return InvalidParameter('Bracket is finalized.');
        }

        if ((int)$this->Bracket->point_rounds >= 32) {
            return InvalidParameter('Max 32 rounds.');
        }

        $action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
        $actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $tournament_id = (int)($request['TournamentId'] ?? 0);

        // Wrap the increment + emit in one transaction (tnEmitEvent needs the caller to
        // hold a tx). Increment atomically in SQL with the cap in the WHERE so concurrent
        // callers can't both read the same value, lose an increment, or blow past the cap.
        $this->db->query('START TRANSACTION');
        try {
            $this->db->Clear();
            $this->db->query("UPDATE " . DB_PREFIX . "bracket SET point_rounds = point_rounds + 1 WHERE bracket_id = $bracket_id AND point_rounds < 32");
            $rr = $this->db->query("SELECT point_rounds FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id");
            $new = ($rr && $rr->next()) ? (int)$rr->point_rounds : 0;

            $seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'points_round', [
                'point_rounds' => $new,
            ], $actor_id, $action_id !== '' ? $action_id : null);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $this->bustTournamentReportCache();
        $this->tnPublishSeq($tournament_id, $seq);
        return Success(['PointRounds' => $new, 'Seq' => $seq]);
    }

    /**
     * Standings for a Points bracket. Returns rows ordered by total DESC, alias ASC
     * (alias is for stable display only - not a placement tiebreaker; ties share a place).
     *
     * Detail row shape: {
     *   ParticipantId, Alias, ParticipantNumber, Status,
     *   RoundScores: [string|null, ...],
     *   Total: string (formatted "0.00"),
     *   Place: int|null,
     *   Tied: bool
     * }
     */
    public function GetPointStandings($request)
    {
        $bracket_id = (int)($request['BracketId'] ?? 0);
        if (!valid_id($bracket_id)) {
            return InvalidParameter('BracketId required.');
        }

        $this->Bracket->clear();
        $this->Bracket->bracket_id = $bracket_id;
        if (!$this->Bracket->find()) {
            return InvalidParameter('Bracket not found.');
        }
        $rounds = max(0, (int)$this->Bracket->point_rounds);

        $participants = [];
        $pr = $this->db->query("SELECT participant_id, alias, participant_number, status, seed
			FROM " . DB_PREFIX . "participant
			WHERE bracket_id = $bracket_id
			ORDER BY seed ASC, participant_id ASC");
        if ($pr) {
            while ($pr->next()) {
                $participants[(int)$pr->participant_id] = [
                    'ParticipantId'     => (int)$pr->participant_id,
                    'Alias'             => (string)$pr->alias,
                    'ParticipantNumber' => (int)$pr->participant_number,
                    'Status'            => (string)$pr->status,
                    'RoundScores'       => array_fill(0, $rounds, null),
                    'Total'             => 0.0,
                ];
            }
        }

        // Defensive dedupe: GROUP BY (participant_id, round) so a duplicate cell (should the
        // UNIQUE(bracket_id, participant_id, round) constraint be missing) can't double-count
        // into Total. MAX(points) prefers a scored value over a NULL placeholder.
        $sr = $this->db->query("SELECT participant_id, round, MAX(points) AS points
			FROM " . DB_PREFIX . "point_score
			WHERE bracket_id = $bracket_id
			GROUP BY participant_id, round");
        if ($sr) {
            while ($sr->next()) {
                $pid = (int)$sr->participant_id;
                $rnd = (int)$sr->round;
                if (!isset($participants[$pid]) || $rnd < 1 || $rnd > $rounds) {
                    continue;
                }
                $val = ($sr->points === null) ? null : (float)$sr->points;
                $participants[$pid]['RoundScores'][$rnd - 1] = ($val === null) ? null : number_format($val, 2, '.', '');
                if ($val !== null) {
                    $participants[$pid]['Total'] += $val;
                }
            }
        }

        $active   = [];
        $inactive = [];
        foreach ($participants as $row) {
            $row['Total'] = number_format($row['Total'], 2, '.', '');
            if ($row['Status'] === 'active' || $row['Status'] === '') {
                $active[] = $row;
            } else {
                $inactive[] = $row;
            }
        }
        usort($active, function ($a, $b) {
            $cmp = ((float)$b['Total']) <=> ((float)$a['Total']);
            return $cmp !== 0 ? $cmp : strcasecmp($a['Alias'], $b['Alias']);
        });

        $lastTotal = null;
        $lastPlace = 0;
        foreach ($active as $i => &$row) {
            $pos = $i + 1;
            if ($lastTotal !== null && (float)$row['Total'] === (float)$lastTotal) {
                $row['Place'] = $lastPlace;
                $row['Tied']  = true;
                $active[$i - 1]['Tied'] = true;
            } else {
                $row['Place'] = $pos;
                $row['Tied']  = false;
                $lastPlace = $pos;
            }
            $lastTotal = $row['Total'];
        }
        unset($row);

        foreach ($inactive as &$row) {
            $row['Place'] = null;
            $row['Tied'] = false;
        }
        unset($row);

        return Success(array_values(array_merge($active, $inactive)));
    }

}
