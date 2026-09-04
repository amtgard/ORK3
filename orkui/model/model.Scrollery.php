<?php

// There is no autoloader for orkui/model/*.php — Controller::load_model() only
// require_once's a model when a controller explicitly asks for it, and nothing
// here guarantees Scrollery's controllers ever load Award. Model_Award is a
// hard dependency below (the shared dangling-officer-row correction), so it has
// to be required directly rather than assumed loaded.
require_once DIR_MODEL . 'model.Award.php';

/**
 * Award rows -> scroll data.
 *
 * The permission decision lives here so every route shares one copy of it. The
 * render route is a view, never the authority: it re-runs this against its own
 * URL parameters.
 */
class Model_Scrollery extends Model
{
    private ?Model_Award $award = null;

    /**
     * The dangling-award_id / officer-position correction (see
     * Model_Award::isDanglingRowAnOfficer()) is shared with Model_Player, which
     * has the identical exposure on the profile Awards tab — reached through a
     * neutral model neither Scrollery nor Player feature code owns, rather than
     * one reaching into the other's model.
     */
    private function award(): Model_Award
    {
        return $this->award ??= new Model_Award();
    }

    /**
     * Returns the scroll data for one awards row, or null when the viewer may not
     * print it or the award has no certificate art.
     */
    public function scroll_for_awards_id(int $awardsId, int $viewerId, string $variant = ''): ?array
    {
        if ($awardsId <= 0 || $viewerId <= 0) {
            return null;
        }

        $row = $this->award_row($awardsId);
        if ($row === null || !Scroll::HasArt($row)) {
            return null;
        }

        if (!$this->viewer_may_print($row, $viewerId)) {
            return null;
        }

        $player = Ork3::$Lib->player->GetPlayer(['MundaneId' => (int) $row['MundaneId']]);

        return Scroll::BuildScroll($row, [
            'player'      => $player['Player']['Persona'] ?? '',
            'pronoun'     => $this->possessive_pronoun($player['Player'] ?? []),
            'heraldry'    => $this->heraldry_url($row),
            'issuerTitle' => $this->issuer_title($row),
            'variant'     => $variant,
        ]);
    }

    /**
     * The player's own awards that can actually be printed.
     *
     * Filtered to those with certificate art — phase 1 shows a Print control only
     * where one exists, and nothing elsewhere. No dead buttons.
     *
     * @return array<int, array>
     */
    public function printable_awards_for_player(int $mundaneId): array
    {
        if ($mundaneId <= 0) {
            return [];
        }

        $response  = Ork3::$Lib->player->AwardsForPlayer(['MundaneId' => $mundaneId, 'AwardsId' => 0]);
        $printable = [];

        foreach ($response['Awards'] ?? [] as $row) {
            $row = $this->award()->correct_dangling_officer_role($row);
            if (!Scroll::HasArt($row)) {
                continue;
            }

            $row['Title']       = Scroll::ResolveTitle($row);
            $row['IssuerTitle'] = $this->issuer_title($row);
            $row['IssuerName']  = trim((string) ($row['ParkName'] ?? '')) !== ''
                ? (string) $row['ParkName']
                : (string) ($row['KingdomName'] ?? '');
            $row['PrettyDate']  = Scroll::FormatDate((string) ($row['Date'] ?? ''));
            $printable[]        = $row;
        }

        return $printable;
    }

    /**
     * Name, kingdom and heraldry for the park or kingdom a scroll is being issued by.
     *
     * @return array{Type:string,Id:int,Name:string,Title:string,KingdomId:int,Heraldry:string,UseThe:bool,UseOf:bool}
     */
    public function scope_context(string $scopeType, int $scopeId): array
    {
        if ($scopeType === 'kingdom') {
            $k    = Ork3::$Lib->kingdom->GetKingdomShortInfo(['KingdomId' => $scopeId]);
            $info = $k['KingdomInfo'] ?? [];

            return [
                'Type'      => 'kingdom',
                'Id'        => $scopeId,
                // Kingdom names already carry their own article and rank word —
                // "The Kingdom of the Wetlands" — so the certificate adds neither.
                'Name'      => (string) ($info['KingdomName'] ?? ''),
                'Title'     => '',
                'UseThe'    => false,
                'UseOf'     => false,
                'KingdomId' => $scopeId,
                'Heraldry'  => (int) ($info['HasHeraldry'] ?? 0) === 1
                    ? (string) Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Kingdom', 'Id' => $scopeId])['Url']
                    : '',
            ];
        }

        $park = $this->park_details($scopeId);

        return [
            'Type'      => 'park',
            'Id'        => $scopeId,
            'Name'      => (string) ($park['ParkName'] ?? ''),
            'Title'     => trim((string) ($park['ParkTitle'] ?? '')) ?: 'Barony',
            'UseThe'    => true,
            'UseOf'     => true,
            'KingdomId' => (int) ($park['KingdomId'] ?? 0),
            'Heraldry'  => (int) ($park['HasHeraldry'] ?? 0) === 1
                ? (string) Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Park', 'Id' => $scopeId])['Url']
                : '',
        ];
    }

    /**
     * The kingdom's own award rows that have certificate art.
     *
     * Goes through the kingdomaward list the ORK already uses, so a kingdom holding
     * more than one name for a base award offers both by name and nothing has to
     * special-case the archery spellings.
     *
     * @return array<int, array{KingdomAwardId:int, AwardId:int, Name:string, Order:string, MaxRank:int, Variants:array}>
     */
    public function printable_award_choices(int $kingdomId): array
    {
        if ($kingdomId <= 0) {
            return [];
        }

        $response = Ork3::$Lib->kingdom->GetAwardList([
            'IsLadder'  => 'Both',
            'IsTitle'   => 'Both',
            'KingdomId' => $kingdomId,
        ]);

        return self::choicesForAwardListForTest($response['Awards'] ?? []);
    }

    /**
     * The per-row body of printable_award_choices(), split out DB-free so a
     * hand-built GetAwardList()-shaped fixture can exercise it directly (see
     * ScrolleryChoicesForAwardListTest) — the same convention as bulkSqlForTest()
     * and matchingChoiceForTest() below.
     *
     * GetAwardList()'s LEFT JOIN from ork_kingdomaward to ork_award misses whenever
     * a kingdomaward's award_id points at nothing (archery's real grants live here,
     * under base award_id 0/94/131 — no ork_award row exists for those ids at all),
     * leaving BOTH AwardId and OfficerRole NULL on the row. Scroll::OrderForAwardRow()
     * correctly stays open for that shape, or archery could never print — but that
     * also means it cannot tell an actual archery row apart from an unrelated
     * officer position whose base award has simply gone missing (kingdom 28 holds
     * two "Sheriff" kingdomaward rows: kingdomaward_id 4935 resolves to a real
     * award_id 70 with officer_role='park' and is correctly excluded; kingdomaward_id
     * 5028 points at award_id 163, for which no ork_award row exists, so it dangles
     * exactly like archery and — before this fix — was offered as a printable
     * custom scroll).
     *
     * The two rows sharing the SAME NAME is what tells them apart: any dangling row
     * whose name exactly matches a resolved sibling's is that same position, wearing
     * its cover. Checked against kingdom 28's real data, this rule catches all 23
     * officer positions there (including bare "Sheriff", which
     * Kingdom::awardNameLooksLikeOfficer()'s prefix-anchored regex misses), keeps
     * Warlord and Defender (whose resolved siblings carry officer_role='none' and
     * are real awards), and touches no archery row anywhere — no archery name has a
     * resolved officer sibling.
     *
     * @param array<int, array<string, mixed>> $awards GetAwardList()'s response rows.
     * @return array<int, array{KingdomAwardId:int, AwardId:int, Name:string, Order:string, MaxRank:int, Variants:array}>
     */
    public static function choicesForAwardListForTest(array $awards): array
    {
        $officerNames = Model_Award::officerNamesFromForTest($awards);

        $choices = [];
        foreach ($awards as $row) {
            $name     = (string) ($row['KingdomAwardName'] ?? $row['kingdom_awardname'] ?? '');
            $dangling = ($row['AwardId'] ?? $row['award_id'] ?? null) === null;
            if ($dangling && Model_Award::isDanglingRowAnOfficer($name, $officerNames)) {
                continue;
            }

            // OrderForAwardRow(), not OrderForAwardId(): GetAwardList() already returns
            // OfficerRole and KingdomAwardName on every row, so this list can apply the
            // same officer-exclusion / archery-by-name / custom-eligibility precedence
            // as every other surface. OrderForAwardId() alone would drop every
            // custom-eligible catalog award (e.g. Master Rose) from the create dropdown,
            // since custom's own award_id is the 0 sentinel that function always misses.
            $order = Scroll::OrderForAwardRow($row);
            if ($order === null) {
                continue;
            }

            $choices[] = [
                'KingdomAwardId' => (int) ($row['KingdomAwardId'] ?? $row['kingdomaward_id'] ?? 0),
                'AwardId'        => (int) ($row['AwardId'] ?? $row['award_id'] ?? 0),
                'Name'           => (string) ($row['KingdomAwardName'] ?? $row['kingdom_awardname'] ?? ''),
                'Order'          => (string) $order['id'],
                'MinRank'        => (int) $order['ranks']['from'],
                'MaxRank'        => (int) $order['ranks']['to'],
                'Variants'       => array_values($order['variants']),
            ];
        }

        usort($choices, static fn ($a, $b) => strcasecmp($a['Name'], $b['Name']));

        return $choices;
    }

    /**
     * Which catalog choice prints a hand-composed scroll for one award id + title
     * pair, or null when this kingdom may not compose one for it.
     *
     * compose() and the Ajax preview build a scroll from client-supplied fields —
     * an award id and a hand-typed title, nothing that carries OfficerRole. Passing
     * that bare pair straight to Scroll::OrderForAwardRow() would default the
     * missing OfficerRole to "none" and wrongly treat every officer position as
     * custom-eligible. Recomputing against this kingdom's own catalog — the same
     * list the create dropdown already filtered through choicesForAwardListForTest()
     * — is what keeps the officer-position exclusion in force for a hand-edited
     * request, exactly as scroll_for_awards_id() re-resolves a hand-edited id list
     * rather than trusting it.
     *
     * The title has to be part of the match, not just the id: archery's real grants
     * carry no base award of their own, so several distinct catalog entries — some
     * archery, some not — all share award_id 0 (also 94, 131) and are told apart
     * only by name. Matching on id alone would let a request name one of them and
     * silently print whichever same-id entry the search happened to reach first.
     *
     * Returns the matched CHOICE, not the resolved order: the caller builds its
     * BuildScroll() row from the choice's own canonical AwardId / Name — never from
     * the client's strings — which both fixes a cosmetic drift (matching is
     * case/whitespace-insensitive, so a hand-typed "  SHERIFF  " would otherwise
     * print with the submitted casing rather than the catalogue's) and guarantees
     * that row always carries a real OfficerRole ('none', by construction of this
     * list) rather than an absent key.
     *
     * Split from the DB-touching lookup above so the search itself — the part with
     * anything to get wrong — is exercised in ScrolleryAwardOrderForComposeTest
     * against a hand-built choice list, the same way bulkSqlForTest() keeps the
     * bulk query testable without a database.
     *
     * @return array{KingdomAwardId:int, AwardId:int, Name:string, Order:string, MinRank:int, MaxRank:int, Variants:array}|null
     */
    public function award_order_for_compose(int $kingdomId, int $awardId, string $title): ?array
    {
        if ($kingdomId <= 0) {
            return null;
        }

        return self::matchingChoiceForTest($this->printable_award_choices($kingdomId), $awardId, $title);
    }

    /**
     * @param array<int, array{AwardId:int, Name:string, Order:string}> $choices
     */
    public static function matchingChoiceForTest(array $choices, int $awardId, string $title): ?array
    {
        $title = trim($title);
        foreach ($choices as $choice) {
            if ($choice['AwardId'] === $awardId && strcasecmp(trim($choice['Name']), $title) === 0) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * The bulk selection — the module's ONE new query. Everything else reuses
     * Player::AwardsForPlayer().
     *
     * Public and named ...ForTest so ScrolleryBulkQueryTest can assert on the
     * generated statement without reflection and without a database. It builds a
     * string and touches nothing.
     *
     * The surrounding code interpolates rather than binds, and this codebase's
     * mysql_real_escape_string() is a no-op shim, so what protects this is the (int)
     * cast on every id and the ISO-date regex — not escaping.
     */
    public function bulkSqlForTest(
        string $type,
        string $id,
        string $scopeType,
        string $scopeId,
        string $from = '',
        string $to = ''
    ): string {
        $id      = (int) $id;
        $scopeId = (int) $scopeId;

        switch ($type) {
            case 'event':
                // The scope gate belongs on the AWARD row, not on the event. An event
                // does not reliably carry the granting body: 974 award rows sit at an
                // event whose kingdom_id differs from the award's own at_kingdom_id,
                // and single events (8462, 7, 15975, 14761, 11193) carry awards from
                // three kingdoms at once. Filtering on awards.at_park_id /
                // at_kingdom_id both closes the cross-body disclosure — the other
                // kingdoms' rows at a shared event are no longer returned — and keeps
                // the legitimate case working, where this body's own awards were
                // granted at somebody else's event.
                $scope = 'awards.at_event_id = ' . $id
                       . ($scopeType === 'kingdom'
                           ? ' and awards.at_kingdom_id = ' . $scopeId
                           : ' and awards.at_park_id = ' . $scopeId);
                break;
            case 'kingdom':
                $scope = 'awards.at_kingdom_id = ' . $id;
                break;
            case 'park':
                $scope = 'awards.at_park_id = ' . $id;
                break;
            default:
                // Never widen to "everything" on an unrecognised scope.
                $scope = 'awards.at_park_id = 0';
                break;
        }

        $range = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $range .= " and awards.date >= '" . $from . "'";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $range .= " and awards.date <= '" . $to . "'";
        }

        return 'select awards.awards_id, awards.mundane_id, awards.rank, awards.date, awards.note,
                       awards.custom_name, awards.at_park_id, awards.at_kingdom_id, awards.at_event_id,
                       a.award_id, a.name, a.officer_role, a.is_ladder,
                       ka.name as kingdom_awardname,
                       m.persona as recipient,
                       gb.persona as given_by,
                       p.name as park_name, k.name as kingdom_name
                  from ' . DB_PREFIX . 'awards awards
                  left join ' . DB_PREFIX . 'kingdomaward ka on ka.kingdomaward_id = awards.kingdomaward_id
                  left join ' . DB_PREFIX . 'award a on a.award_id = ka.award_id
                  left join ' . DB_PREFIX . 'mundane m on m.mundane_id = awards.mundane_id
                  left join ' . DB_PREFIX . 'mundane gb on gb.mundane_id = awards.given_by_id
                  left join ' . DB_PREFIX . 'park p on p.park_id = awards.at_park_id
                  left join ' . DB_PREFIX . 'kingdom k on k.kingdom_id = awards.at_kingdom_id
                 where ' . $scope . $range . '
                 order by m.persona, a.name, awards.rank
                 limit ' . (Scroll::MAX_PRINT_RUN + 1);
    }

    /**
     * Award rows for a bulk print, shaped like the reprint list.
     *
     * Returns both the printable rows and the ones excluded for having no artwork,
     * so the officer is told rather than silently handed fewer scrolls than awards.
     *
     * Failed says the query itself errored — distinct from "no awards matched", which
     * an officer would otherwise read as "there is nothing to print". Truncated says
     * the selection hit Scroll::MAX_PRINT_RUN and is not the whole answer.
     *
     * @return array{Printable: array<int, array>, Excluded: array<int, array>, Failed: bool, Truncated: bool}
     */
    public function awards_for_bulk(
        string $type,
        int $id,
        string $scopeType,
        int $scopeId,
        string $from = '',
        string $to = ''
    ): array {
        $printable = [];
        $excluded  = [];

        if ($id <= 0 || $scopeId <= 0) {
            return ['Printable' => $printable, 'Excluded' => $excluded, 'Failed' => false, 'Truncated' => false];
        }

        $rs = $this->db_query(
            $this->bulkSqlForTest($type, (string) $id, $scopeType, (string) $scopeId, $from, $to)
        );
        if ($rs === null) {
            return ['Printable' => $printable, 'Excluded' => $excluded, 'Failed' => true, 'Truncated' => false];
        }

        // The query asks for one row more than the cap purely to detect the overflow.
        $truncated = count($rs) > Scroll::MAX_PRINT_RUN;
        if ($truncated) {
            $rs = array_slice($rs, 0, Scroll::MAX_PRINT_RUN);
        }

        // A park's scope is the park's own kingdom, not at_kingdom_id (blank for a
        // park grant) — see Model_Award::kingdom_for_grant_row()'s doc for the same split.
        $kingdomId = $scopeType === 'kingdom' ? $scopeId : (int) ($this->park_details($scopeId)['KingdomId'] ?? 0);

        foreach ($rs as $row) {
            // Shaped to the keys Scroll::HasArt() / OrderForAwardRow() and
            // ResolveTitle() expect. OfficerRole matters here: without it every
            // officer-position row in a bulk selection would look custom-eligible
            // (IsCustomEligible() defaults an absent key to 'none').
            //
            // IsLadder is the same shape of dependency for BuildScroll()'s `ranked`:
            // without it a bulk run prints all 292 of Walker in the Middle's rank-0
            // grants with no blank to write the level into. Both read the BASE award
            // (a.*) rather than the alias-coalesced value AwardsForPlayer() uses,
            // because this query has no `award alias` join — the same inconsistency
            // officer_role already lives with, deliberately not fixed here.
            $shaped = [
                'AwardsId'         => (int) $row['awards_id'],
                'AwardId'          => (int) $row['award_id'],
                'MundaneId'        => (int) $row['mundane_id'],
                'Rank'             => (int) $row['rank'],
                'Date'             => (string) $row['date'],
                'Recipient'        => (string) $row['recipient'],
                'GivenBy'          => (string) $row['given_by'],
                'Name'             => (string) $row['name'],
                'KingdomAwardName' => (string) $row['kingdom_awardname'],
                'CustomAwardName'  => (string) $row['custom_name'],
                'AliasAwardName'   => '',
                'OfficerRole'      => (string) ($row['officer_role'] ?? ''),
                'IsLadder'         => (int) ($row['is_ladder'] ?? 0),
                'ParkName'         => (string) $row['park_name'],
                'KingdomName'      => (string) $row['kingdom_name'],
            ];

            // Same dangling-award_id shape choicesForAwardListForTest() closes for
            // the create dropdown: this query's LEFT JOIN to `award` misses for
            // archery's real grants (base award_id 0/94/131, no matching ork_award
            // row) — that must stay open — but also for an officer position whose
            // base award has simply gone missing, which must not. Told apart by
            // Model_Award::isDanglingRowAnOfficer() against the granting kingdom's
            // own catalog.
            if ($row['award_id'] === null
                && Model_Award::isDanglingRowAnOfficer(
                    $shaped['KingdomAwardName'],
                    $this->award()->officer_names_for_kingdom($kingdomId)
                )
            ) {
                $shaped['OfficerRole'] = 'other-officer';
            }

            $shaped['Title']      = Scroll::ResolveTitle($shaped);
            $shaped['PrettyDate'] = Scroll::FormatDate($shaped['Date']);

            if (Scroll::HasArt($shaped)) {
                $printable[] = $shaped;
            } else {
                $excluded[] = $shaped;
            }
        }

        return [
            'Printable' => $printable,
            'Excluded'  => $excluded,
            'Failed'    => false,
            'Truncated' => $truncated,
        ];
    }

    /**
     * Rows, or null when the query itself failed.
     *
     * Null rather than [] on purpose: an empty array here used to mean both "this
     * event had no awards" and "the database errored", and the officer read the
     * second as the first.
     *
     * A false return is NOT the failure signal — YapoDb::Query() delegates to
     * DataSet(), which always hands back a YapoResultSet, and PDO runs in
     * ERRMODE_WARNING so a bad statement neither returns false nor throws (the
     * comment above YapoMysql::ExecuteChecked() says as much). The real signals are
     * the SQLSTATE the result set captures at construction, and — when prepare()
     * itself returns false — the Error raised inside DataSet() by calling
     * execute() on a bool.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function db_query(string $sql): ?array
    {
        $db = Ork3::$Lib->player->db;
        $db->Clear();

        try {
            $rs = $db->query($sql);
        } catch (Throwable $e) {
            return null;
        }

        if (!is_object($rs) || $this->result_failed($rs)) {
            return null;
        }

        $rows = [];
        if ($rs->size() > 0) {
            while ($rs->next()) {
                $rows[] = [
                    'awards_id'         => $rs->awards_id,
                    'award_id'          => $rs->award_id,
                    'mundane_id'        => $rs->mundane_id,
                    'rank'              => $rs->rank,
                    'date'              => $rs->date,
                    'note'              => $rs->note,
                    'custom_name'       => $rs->custom_name,
                    'recipient'         => $rs->recipient,
                    'given_by'          => $rs->given_by,
                    'name'              => $rs->name,
                    'officer_role'      => $rs->officer_role,
                    'is_ladder'         => $rs->is_ladder,
                    'kingdom_awardname' => $rs->kingdom_awardname,
                    'park_name'         => $rs->park_name,
                    'kingdom_name'      => $rs->kingdom_name,
                ];
            }
        }

        return $rows;
    }

    /**
     * Whether the statement behind a result set actually failed.
     *
     * YapoResultSet records the statement's SQLSTATE and errorInfo in __ERROR the
     * moment it is built. '00000' is success; anything else is a real error that
     * would otherwise read as "no awards matched".
     *
     * @param object $rs
     */
    private function result_failed($rs): bool
    {
        $state = $rs->__ERROR[0][1] ?? null;

        return $state !== null && $state !== '' && $state !== '00000';
    }

    /** Own award, or an officer with edit rights over the granting park/kingdom. */
    private function viewer_may_print(array $row, int $viewerId): bool
    {
        if ((int) $row['MundaneId'] === $viewerId) {
            return true;
        }

        $auth = Ork3::$Lib->authorization;
        if ((int) $row['ParkId'] > 0
            && $auth->HasAuthority($viewerId, AUTH_PARK, (int) $row['ParkId'], AUTH_EDIT)) {
            return true;
        }
        if ((int) $row['KingdomId'] > 0
            && $auth->HasAuthority($viewerId, AUTH_KINGDOM, (int) $row['KingdomId'], AUTH_EDIT)) {
            return true;
        }

        return false;
    }

    /**
     * One awards row, via the query the Awards tab already uses.
     *
     * AwardsForPlayer() ORs its AwardsId onto the mundane_id filter, so a
     * MundaneId of 0 asks it for exactly this row. Reusing it keeps the alias,
     * kingdomaward, park, kingdom, event and giver joins in one place rather than
     * in a parallel query that can drift.
     */
    private function award_row(int $awardsId): ?array
    {
        $response = Ork3::$Lib->player->AwardsForPlayer([
            'MundaneId' => 0,
            'AwardsId'  => $awardsId,
        ]);

        foreach (($response['Awards'] ?? []) as $row) {
            if ((int) $row['AwardsId'] === $awardsId) {
                return $this->award()->correct_dangling_officer_role($row);
            }
        }

        return null;
    }

    /**
     * The issuing body's heraldry, or '' — on which _heraldry.tpl omits the layer.
     *
     * GetHeraldryUrl() never returns an empty string: it falls through to a .jpg
     * URL whether or not a file exists, so an issuer with no heraldry would render
     * a broken image. has_heraldry is what actually answers the question.
     */
    private function heraldry_url(array $row): string
    {
        $parkId = (int) ($row['ParkId'] ?? 0);
        if ($parkId > 0) {
            $park = $this->park_details($parkId);
            if ((int) ($park['HasHeraldry'] ?? 0) === 1) {
                return (string) Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Park', 'Id' => $parkId])['Url'];
            }
        }

        $kingdomId = (int) ($row['KingdomId'] ?? 0);
        if ($kingdomId > 0) {
            $kingdom = Ork3::$Lib->kingdom->GetKingdomShortInfo(['KingdomId' => $kingdomId]);
            if ((int) ($kingdom['KingdomInfo']['HasHeraldry'] ?? 0) === 1) {
                return (string) Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Kingdom', 'Id' => $kingdomId])['Url'];
            }
        }

        return '';
    }

    /**
     * The issuing body's rank word — "and the Freehold of ...".
     *
     * ORK stores this per park (ork_park.parktitle_id -> ork_parktitle.title,
     * defined per kingdom). Most parks are not baronies: 349 are Freeholds and 274
     * Grand Duchies, so the Barony/Kingdom fallback in Scroll::BuildScroll() would
     * be wrong far more often than right. Returning '' lets that fallback stand.
     */
    private function issuer_title(array $row): string
    {
        $parkId = (int) ($row['ParkId'] ?? 0);
        if ($parkId <= 0) {
            return '';
        }

        return trim((string) ($this->park_details($parkId)['ParkTitle'] ?? ''));
    }

    /**
     * GetParkDetails() returns a flat array and is called twice per scroll (title
     * and heraldry), so hold onto it.
     *
     * @return array<string, mixed>
     */
    private function park_details(int $parkId): array
    {
        static $cache = [];
        if (!array_key_exists($parkId, $cache)) {
            $cache[$parkId] = Ork3::$Lib->park->GetParkDetails(['ParkId' => $parkId]);
        }

        return is_array($cache[$parkId]) ? $cache[$parkId] : [];
    }

    /** The recipient's possessive pronoun, by player id. Never blank. */
    public function possessive_pronoun_for_player(int $mundaneId): string
    {
        if ($mundaneId <= 0) {
            return '';
        }

        $player = Ork3::$Lib->player->GetPlayer(['MundaneId' => $mundaneId]);

        return $this->possessive_pronoun($player['Player'] ?? []);
    }

    /**
     * The POSSESSIVE pronoun — the text layer reads "with <her> third".
     *
     * Never blank: an empty value makes the sentence read "with third".
     *
     * This is an outright improvement on Arcanyst, where the pronoun was typed in
     * per recipient and defaulted to "their".
     */
    private function possessive_pronoun(array $player): string
    {
        $pronounId = (int) ($player['PronounId'] ?? 0);
        if ($pronounId > 0) {
            $possessive = $this->possessive_for_ids([$pronounId]);
            if ($possessive !== '') {
                return $possessive;
            }
        }

        // Custom pronouns are stored as {"s":[ids],"o":[ids],"p":[ids],...} — "p"
        // being the possessive set. Rare (66 players), but cheap to honour.
        $custom = (string) ($player['PronounCustom'] ?? '');
        if ($custom !== '') {
            $decoded = json_decode($custom, true);
            if (is_array($decoded) && !empty($decoded['p']) && is_array($decoded['p'])) {
                $ids = array_values(array_filter(array_map('intval', $decoded['p'])));
                if ($ids !== []) {
                    $possessive = $this->possessive_for_ids($ids);
                    if ($possessive !== '') {
                        return $possessive;
                    }
                }
            }
        }

        return 'their';
    }

    /** @param int[] $ids */
    private function possessive_for_ids(array $ids): string
    {
        $list = Ork3::$Lib->pronoun->GetPronounList($ids);
        foreach ($ids as $id) {
            $possessive = trim((string) ($list['Pronouns'][$id]['Possessive'] ?? ''));
            if ($possessive !== '') {
                return $possessive;
            }
        }

        return '';
    }

    /**
     * Whether a freshly-granted award row can actually be printed — used by the
     * award-grant success path to decide whether to offer a "Print this
     * certificate" link. Deliberately does not check viewer authority: it is
     * called immediately after the officer's own grant succeeds.
     */
    public function award_is_printable(int $awardsId): bool
    {
        if ($awardsId <= 0) {
            return false;
        }
        $row = $this->award_row($awardsId);
        return $row !== null && Scroll::HasArt($row);
    }
}
