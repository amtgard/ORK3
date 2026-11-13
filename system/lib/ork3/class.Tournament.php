<?php

class Tournament extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->Bracket = new yapo($this->db, DB_PREFIX . 'bracket');
        $this->Glicko2 = new yapo($this->db, DB_PREFIX . 'glicko2');
        $this->Match = new yapo($this->db, DB_PREFIX . 'match');
        $this->Participant = new yapo($this->db, DB_PREFIX . 'participant');
        $this->Player = new yapo($this->db, DB_PREFIX . 'participant_mundane');
        $this->Tournament = new yapo($this->db, DB_PREFIX . 'tournament');
    }

    public function CreateTournament($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            return NoAuthorization();
        }

        logtrace("CreateTournament() :1", $request);

        $kingdom_id = (int)($request['KingdomId'] ?? 0);
        $park_id = (int)($request['ParkId'] ?? 0);
        $ecd_id = (int)($request['EventCalendarDetailId'] ?? 0);
        $event_id = 0;

        // Resolve the owning event BEFORE authorizing: the request names a calendar
        // detail, and the event behind it is the scope this tournament will belong to.
        if (valid_id($ecd_id)) {
            $detail = new yapo($this->db, DB_PREFIX . 'event_calendardetail');
            $detail->event_calendardetail_id = $ecd_id;
            if (!$detail->find()) {
                return InvalidParameter();
            }
            $event_id = (int)$detail->event_id;
        }

        // This used to check nothing beyond "is someone logged in", so any authenticated
        // player could stamp a tournament onto any kingdom, park, or event named in the
        // request. tournament.bracket.manage could not cover it: that key authorizes
        // against a tournament's own recorded scope, which does not exist yet here.
        if (!$this->may_create_tournament($mundane_id, $kingdom_id, $park_id, $event_id)) {
            return NoAuthorization();
        }

        $this->Tournament->clear();
        $this->Tournament->kingdom_id = $kingdom_id;
        $this->Tournament->park_id = $park_id;
        $this->Tournament->event_calendardetail_id = $ecd_id;
        if ($event_id > 0) {
            $this->Tournament->event_id = $event_id;
        }
        $this->Tournament->name = $request['Name'];
        $this->Tournament->description = strip_tags($request['Description'], "<p><br><ul><li><b><i>");
        $this->Tournament->date_time = $request['When'];
        $this->Tournament->save();

        return Success($this->Tournament->tournament_id);
    }

    /**
     * May this actor create a tournament under the given org unit?
     *
     * Narrowest scope the request actually names wins -- the same order check_auth()
     * uses once the row exists, so a tournament authorizes identically before and after
     * it is saved. A request naming none of the three is refused: an unowned tournament
     * has nothing to authorize against, now or later.
     */
    private function may_create_tournament($mundane_id, $kingdom_id, $park_id, $event_id)
    {
        if (valid_id($event_id)) {
            return Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'tournament.create', 'event', $event_id, AUTH_EDIT);
        }
        if (valid_id($park_id)) {
            return Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'tournament.create', 'park', $park_id, AUTH_EDIT);
        }
        if (valid_id($kingdom_id)) {
            return Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'tournament.create', 'kingdom', $kingdom_id, AUTH_EDIT);
        }
        return false;
    }

    /**
     * @param array|string $Token         Either the whole request array, or a bare token
     *                                    with $TournamentId supplied separately.
     * @param int|null     $TournamentId  Ignored when $Token is an array.
     */
    private function check_auth($Token, $TournamentId = null)
    {
        if (is_array($Token)) {
            // Read BOTH fields before reassigning. This used to overwrite $Token with the
            // token string first and then index that string for 'TournamentId', which in
            // PHP 8 yields the token's first character -- so the lookup below never found
            // a tournament and every caller (all four pass an array) was refused outright.
            $request = $Token;
            $Token = $request['Token'] ?? '';
            $TournamentId = $request['TournamentId'] ?? null;
        }
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($Token);
        if (!valid_id($mundane_id) || !valid_id($TournamentId)) {
            return false;
        }
        $this->Tournament->clear();
        $this->Tournament->tournament_id = $TournamentId;
        if (!$this->Tournament->find()) {
            return false;
        }

        // Narrowest recorded scope wins. A park tournament is saved with BOTH kingdom_id
        // and park_id (the park console sends both), so checking kingdom first would have
        // demanded kingdom-level authority from the park officer who created it. Going
        // narrowest-first costs the kingdom nothing: a kingdom-scope grant already
        // satisfies a park- or event-scope check through the RBAC cascade, and
        // HasAuthority walks park -> kingdom for the legacy arm.
        if (valid_id($this->Tournament->event_id)) {
            return Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'tournament.bracket.manage', 'event', $this->Tournament->event_id, AUTH_EDIT);
        }
        if (valid_id($this->Tournament->park_id)) {
            return Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'tournament.bracket.manage', 'park', $this->Tournament->park_id, AUTH_EDIT);
        }
        if (valid_id($this->Tournament->kingdom_id)) {
            return Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'tournament.bracket.manage', 'kingdom', $this->Tournament->kingdom_id, AUTH_EDIT);
        }

        // A tournament attached to nothing has no scope to authorize against. Refuse
        // rather than falling off the end returning null (which read as false anyway,
        // but only by accident).
        return false;
    }

    public function AddBracket($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        if (valid_id($request['CopyOfId'])) {
            $tournament_id = (int)$request['TournamentId'];
            $copy_of_id = (int)$request['CopyOfId'];

            // check_auth() authorizes the TournamentId in the request and nothing else.
            // The INSERT below copies tournament_id from the SOURCE bracket, so a
            // CopyOfId pointing at another kingdom's bracket would create a bracket --
            // and clone its whole roster -- under that foreign tournament. Require the
            // source to belong to the tournament we were actually authorized for.
            $this->Bracket->clear();
            $this->Bracket->bracket_id = $copy_of_id;
            if (!$this->Bracket->find() || (int)$this->Bracket->tournament_id !== $tournament_id) {
                return InvalidParameter();
            }

            // find() leaves bound parameters on the connection; Execute() would try to
            // bind them to these placeholder-free statements and fail silently.
            // The column lists name only columns ork_participant actually has. They used to
            // include mundane_id and team_id, neither of which exists on that table (verified
            // against the rendered schema, the dev mirror and ork_test), so the INSERT failed
            // with "Unknown column" every time -- swallowed by PDO::ERRMODE_WARNING plus
            // YapoDb::handle_errors()'s `default: return true`. The copy branch has therefore
            // never worked; it was simply unreachable until check_auth() started resolving.
            //
            // ExecuteChecked(), not query(): query() always returns a result object, so the
            // `if (!query(...))` idiom cannot fire, and GetLastInsertId() then hands back the
            // connection's stale id -- which is how a write that never happened reported
            // Success. Cloning the roster into whatever bracket that stale id pointed at is
            // the same hazard, so the second INSERT only runs once the first is proven.
            $this->db->Clear();
            $sql = "insert into " . DB_PREFIX . "bracket (tournament_id, style, style_note, method, rings, participants, seeding)
						select tournament_id, style, style_note, method, rings, participants, seeding from " . DB_PREFIX . "bracket where bracket_id = $copy_of_id";
            if ($this->db->ExecuteChecked($sql) === false) {
                return ProcessingError(null, 'Could not copy the bracket.');
            }
            $bracket_id = (int)$this->db->GetLastInsertId();
            if (!valid_id($bracket_id)) {
                return ProcessingError(null, 'Could not copy the bracket.');
            }
            // ExecuteChecked() does not clear $this->Data, and $this->db is the shared
            // connection every yapo object binds onto, so clear again rather than relying
            // on nothing binding between the two statements.
            $this->db->Clear();
            $sql = "insert into " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, unit_id, park_id, kingdom_id)
						select tournament_id, $bracket_id, alias, unit_id, park_id, kingdom_id from " . DB_PREFIX . "participant where bracket_id = $copy_of_id";
            if ($this->db->ExecuteChecked($sql) === false) {
                return ProcessingError(null, 'The bracket was copied but its roster was not.');
            }

            // Previously fell off the end returning null. JsonServer then indexed
            // $output['Status'] on null and emitted an envelope with no Status key, which a
            // caller reading (int)$r['Status'] sees as 0 -- Success.
            return Success($bracket_id);
        } else {
            $this->Bracket->clear();
            $this->Bracket->tournament_id = $request['TournamentId'];
            $this->Bracket->style = $request['Style'];
            $this->Bracket->style_note = $request['StyleNote'];
            $this->Bracket->method = $request['Method'];
            $this->Bracket->rings = $request['Rings'];
            $this->Bracket->participants = $request['Participants'];
            $this->Bracket->seeding = $request['Seeding'];

            $this->Bracket->save();

            return Success($this->Bracket->bracket_id);
        }
    }

    public function GetBrackets($request)
    {
        $this->Bracket->clear();
        $this->Bracket->tournament_id = $request['TournamentId'];
    }

    public function AddParticipant($request)
    {

        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

        if (valid_id($request['ParticipantId'])) {
            // mysql_real_escape_string() is a no-op shim in this codebase, so both ids are
            // hard-cast instead. BracketId in particular arrived here unvalidated -- and a
            // missing one is a caller error, not a reason to insert bracket_id = 0.
            if (!valid_id($request['BracketId'] ?? null)) {
                return InvalidParameter();
            }
            $tournament_id = (int)$request['TournamentId'];
            $bracket_id = (int)$request['BracketId'];
            $participant_id = (int)$request['ParticipantId'];

            // check_auth() authorizes the request's TournamentId only. The INSERT takes
            // tournament_id from the SOURCE row and bracket_id straight from the caller,
            // so without these two ownership checks a holder of tournament.bracket.manage
            // on any tournament could copy a foreign participant into a foreign bracket.
            $this->Participant->clear();
            $this->Participant->participant_id = $participant_id;
            if (!$this->Participant->find() || (int)$this->Participant->tournament_id !== $tournament_id) {
                return InvalidParameter();
            }
            $this->Bracket->clear();
            $this->Bracket->bracket_id = $bracket_id;
            if (!$this->Bracket->find() || (int)$this->Bracket->tournament_id !== $tournament_id) {
                return InvalidParameter();
            }

            // find() leaves bound parameters on the connection; Execute() would try to
            // bind them to this placeholder-free statement and fail silently.
            // Same two non-existent columns, same swallowed "Unknown column", same false
            // Success -- see the note in AddBracket's copy branch above.
            $this->db->Clear();
            $sql = "insert into " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, unit_id, park_id, kingdom_id)
						select tournament_id, $bracket_id, alias, unit_id, park_id, kingdom_id from " . DB_PREFIX . "participant where participant_id = $participant_id";
            if ($this->db->ExecuteChecked($sql) === false) {
                return ProcessingError(null, 'Could not copy the participant.');
            }
            $new_participant_id = (int)$this->db->GetLastInsertId();
            if (!valid_id($new_participant_id)) {
                return ProcessingError(null, 'Could not copy the participant.');
            }
            return Success($new_participant_id);
        } else {
            $this->Participant->clear();
            $this->Participant->tournament_id = $request['TournamentId'];
            $this->Participant->bracket_id = $request['BracketId'];
            $this->Participant->alias = $request['Alias'];
            $this->Participant->unit_id = $request['UnitId'];
            $this->Participant->park_id = $request['ParkId'];
            $this->Participant->kingdom_id = $request['KingdomId'];
            $this->Participant->team_id = $request['TeamId'];

            $this->Participant->save();

            if (!valid_id($request['MundaneId'])) {
                foreach ($request['Members'] as $k => $member) {
                    $this->Player->clear();
                    $this->Player->participant_id = $this->Participant->participant_id;
                    $this->Player->mundane_id = $member['MundaneId'];
                    $this->Player->tournament_id = $member['TournamentId'];
                    $this->Player->bracket_id = $member['BracketId'];
                    $this->Player->save();
                }
            }
            return Success($this->Participant->participant_id);
        }
    }

    public function GetParticipants($request)
    {
        if (valid_id($request['TournamentId'])) {
            $where = " and p.tournament_id = $request[TournamentId]";
        }
        if (valid_id($request['BracketId'])) {
            $where .= " and p.bracket_id = $request[BracketId]";
        }

        $sql = "select p.*, player.*, m.persona, k.name as kingdom_name, park.name as park_name, u.name as unit_name, t.name as team_name
					from " . DB_PREFIX . "participant p
						left join " . DB_PREFIX . "participant_mundane player on player.participant_id = p.participant_id
							left join " . DB_PREFIX . "mundane m on player.mundane_id = m.mundane_id
						left join " . DB_PREFIX . "unit u on p.unit_id = u.unit_id
						left join " . DB_PREFIX . "park on p.park_id = park.park_id
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = p.kingdom_id
						left join " . DB_PREFIX . "team t on t.team_id = p.team_id
					where 1 $where
			";
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

        // Narrowest recorded scope first, matching check_auth() -- see the note there for
        // why widest-first locked park officers out of their own park's tournaments.
        $authorized = false;
        if (valid_id($this->Tournament->event_id)) {
            $authorized = Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'tournament.delete', 'event', $this->Tournament->event_id, AUTH_EDIT);
        } elseif (valid_id($this->Tournament->park_id)) {
            $authorized = Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'tournament.delete', 'park', $this->Tournament->park_id, AUTH_EDIT);
        } elseif (valid_id($this->Tournament->kingdom_id)) {
            $authorized = Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'tournament.delete', 'kingdom', $this->Tournament->kingdom_id, AUTH_EDIT);
        }
        if (!$authorized) {
            return NoAuthorization();
        }

        $this->Tournament->delete();

        // Bust TournamentReport cache for the affected kingdom/park
        $bust_request = ['KingdomId' => $this->Tournament->kingdom_id, 'ParkId' => null, 'EventId' => null, 'EventCalendarDetailId' => null, 'Limit' => null];
        Ork3::$Lib->ghettocache->bust('Report.TournamentReport', Ork3::$Lib->ghettocache->key($bust_request));
        if (valid_id($this->Tournament->park_id)) {
            $bust_request['ParkId'] = $this->Tournament->park_id;
            $bust_request['KingdomId'] = null;
            Ork3::$Lib->ghettocache->bust('Report.TournamentReport', Ork3::$Lib->ghettocache->key($bust_request));
        }

        return Success($tournament_id);
    }

    public function GetMatches($request)
    {

    }

    public function PostMatches($request)
    {
        if (!$this->check_auth($request)) {
            return NoAuthorization();
        }

    }

    private function get_single_elim_matches($bracket_id)
    {

    }

}
