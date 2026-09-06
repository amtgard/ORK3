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
    /**
     * Why a print was refused. Returned by print_decision() so the controller can
     * render a real message instead of a silent redirect to the landing page.
     *
     * Fail-closed by construction: DENIED_AUTH is the default, and every branch that
     * returns a different reason has already decided the answer is no. A new reason
     * added here can never widen access — only relabel a refusal.
     */
    public const PRINT_ALLOWED = '';
    public const DENIED_MISSING = 'missing';
    public const DENIED_REVOKED = 'revoked';
    public const DENIED_UNATTRIBUTED = 'unattributed';
    public const DENIED_NO_ART = 'no-art';
    public const DENIED_EXCLUDED = 'excluded-class';
    public const DENIED_AUTH = 'not-authorised';

    /**
     * The officer-facing sentence for each refusal. Kept beside the reasons rather
     * than in a template so every surface — the render route, the Ajax preview, the
     * post-grant link — says the same thing.
     *
     * @var array<string, string>
     */
    private const DENIAL_MESSAGES = [
        self::DENIED_MISSING => 'That award record could not be found. It may have been deleted.',
        self::DENIED_REVOKED => 'That award has been revoked, so its certificate can no longer be printed.',
        self::DENIED_UNATTRIBUTED => 'That award is not attributed to a park or kingdom, so there is no issuing body '
            . 'to print it under. An officer can set "where given" on the award record, after which it will print.',
        self::DENIED_NO_ART => 'That award has no certificate artwork, so there is nothing to print.',
        self::DENIED_EXCLUDED => 'Scrollery prints certificates for the ladder orders — Order of the Rose, the '
            . 'Lion, the Warrior and the rest — and for a kingdom\'s own Custom Award and Custom Title. Titles, '
            . 'masterhoods, paragons, knighthoods and officer positions are awarded by other means.',
        self::DENIED_AUTH => 'You do not have permission to print that certificate. Printing is open to the '
            . 'recipient, to an officer with edit rights over the park or kingdom that granted it, and to an ORK '
            . 'administrator.',
    ];

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

        if (!$this->print_permission($row, $viewerId)['Allowed']) {
            return null;
        }

        // GetScrollRecipient(), not GetPlayer(): a certificate needs a persona and a
        // pronoun, and GetPlayer() would additionally run two GetDues() passes, a
        // heraldry build, a design-row find, an authorization check and (for recent
        // joiners) an attendance aggregate — per certificate, up to 200 times a run.
        $player = Ork3::$Lib->player->GetScrollRecipient((int) $row['MundaneId']);

        return Scroll::BuildScroll($row, [
            'player'      => $player['Persona'],
            'pronoun'     => $this->possessive_pronoun($player),
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
            // Revoked filtering happens HERE rather than in AwardsForPlayer(), whose
            // default behaviour four other callers depend on. Revocation normally
            // nulls mundane_id, so most revoked rows never reach this list at all —
            // but 59 still carry a live mundane_id and would otherwise let the
            // recipient reprint an award that was stripped from them.
            if ((int) ($row['Revoked'] ?? 0) === 1 || !Scroll::HasArt($row)) {
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
     * The scopes this viewer may actually issue a certificate for — the officer
     * doors on the landing page.
     *
     * Two halves, and both matter. Candidates come from the viewer's own
     * AUTHORIZATION rows (Player::GetIssuableScopes()), not from their home
     * park/kingdom: an officer can hold a kingdom office while living in another
     * kingdom's park — mundane 54527 holds kingdom-4 authority from home park 32 in
     * kingdom 3 — and a home-derived lookup hands them no doors at all. Each
     * candidate is then confirmed through the SAME HasAuthority() call the print gate
     * uses, so the door list is provably a subset of what the gate will allow rather
     * than a second opinion that can drift open.
     *
     * HasAuthority() returns NULL on denial, not false, so it is cast.
     *
     * NOT CACHED, for the same reason print_permission() is not: an authority
     * decision keyed on anything less than the viewer is a cross-tenant disclosure.
     *
     * Known residual: a parent-kingdom officer holding authority over a principality
     * gets their own kingdom's door but not the child kingdom's, because the
     * candidate list is what the authorization table literally says. Closing that
     * means walking ork_kingdom.parent_kingdom_id here the way
     * Authorization::HasAuthority() does — flagged rather than guessed at.
     *
     * @return list<array{Type: string, Id: int, Name: string}>
     */
    public function issuable_scopes_for(int $mundaneId): array
    {
        if ($mundaneId <= 0) {
            return [];
        }

        $auth   = Ork3::$Lib->authorization;
        $scopes = [];

        foreach (Ork3::$Lib->player->GetIssuableScopes($mundaneId) as $scope) {
            $type = $scope['Type'] === 'kingdom' ? AUTH_KINGDOM : AUTH_PARK;
            if ((bool) $auth->HasAuthority($mundaneId, $type, (int) $scope['Id'], AUTH_EDIT)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
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
        $officerNames    = Model_Award::officerNamesFromForTest($awards);
        $pseudoLadderIds = Award::pseudoLadderKingdomAwardIds();

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
                // Which section of the composer's picker this belongs in. Award's own
                // rule, not a second opinion — the grant and recommend dropdowns bucket
                // the identical row shape through the identical call, so a catalog entry
                // that reads as a Masterhood there cannot read as Other here.
                'Group'          => Award::OptionGroupLabelFor($row, $pseudoLadderIds),
            ];
        }

        // Section order first, then alphabetical inside each section, so the template
        // can emit optgroups by walking the list once. 'Custom' sorts last and is
        // rendered as bare options rather than a section, exactly as the grant picker
        // does — awInitPicker() reads standalone <option>s as .aw-pick-standalone.
        $rank = array_flip([...Award::OptionGroupOrder(), 'Custom']);
        usort($choices, static function (array $a, array $b) use ($rank): int {
            return ($rank[$a['Group']] <=> $rank[$b['Group']])
                ?: strcasecmp($a['Name'], $b['Name']);
        });

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
     * The sort key, as SQL expressions, in order.
     *
     * m.persona and a.name are both nullable — a recipient row can be missing and a
     * dangling kingdomaward has no base award — and a NULL anywhere inside a
     * row-value comparison makes the whole comparison NULL, which silently drops the
     * row from a keyset page. COALESCE keeps both the ORDER BY and the cursor
     * predicate total.
     *
     * awards.awards_id is the tiebreaker and is what makes the sort a TOTAL order:
     * m.persona is not unique (a player has many awards, and two players can share a
     * persona), so without it a keyset cursor would either skip rows or loop.
     */
    private const BULK_SORT = [
        "coalesce(m.persona, '')",
        "coalesce(a.name, ka.name, '')",
        'awards.rank',
        'awards.awards_id',
    ];

    /**
     * The FROM and WHERE shared by the page query and the count query, so the total
     * can never be a count of a different population than the page.
     *
     * $officerNames is the granting kingdom's own set of resolved officer-position
     * names, from Model_Award::officer_names_for_kingdom(). It exists to push the
     * dangling-award correction into SQL: a kingdomaward whose award_id resolves to
     * nothing is either a real archery grant (must print) or an officer position
     * whose base award has gone missing (must not), and the only thing telling them
     * apart is whether a resolved sibling in the same catalog carries the same name.
     * Passing [] leaves that correction to the PHP pass, which still runs.
     *
     * The surrounding code interpolates rather than binds, and this codebase's
     * mysql_real_escape_string() is a no-op shim, so what protects this is the (int)
     * cast on every id, the ISO-date regex, and sqlString() on the two places a real
     * string reaches the statement — not escaping.
     *
     * @param array<string, true>|list<string> $officerNames
     */
    private static function bulkFromWhere(
        string $type,
        string $id,
        string $scopeType,
        string $scopeId,
        string $from,
        string $to,
        array $officerNames,
        string $cursor
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

        // The printable filter, in SQL rather than after the LIMIT. This is the whole
        // point of the rewrite: the cap used to be applied to RAW rows and the
        // printable/excluded split happened in PHP afterwards, so an officer asking
        // for event 12250 in kingdom 4 (495 rows, every one printable) got the
        // alphabetically-first 200 and could not reach the other 295 by any
        // narrowing — the date filter's finest grain is one day and that event is one
        // day. 24 event/kingdom pairs exceed the cap; the largest is 741.
        $printable = "\n                   and awards.revoked = 0"
            // A revoked grant normally has its mundane_id nulled, which would print a
            // blank recipient; a bulk run must not offer those even before the
            // revoked filter above catches them.
            . "\n                   and awards.mundane_id > 0"
            // Scroll::IsOfficerPosition(): a resolved officer_role that is neither
            // NULL nor 'none' is a job someone held, not an honour. NULL stays open —
            // that is the dangling-archery shape.
            . "\n                   and (a.officer_role is null or a.officer_role = 'none')"
            // Scroll::OrderForAwardRow()'s ALLOWLIST, in SQL. It has to be here rather
            // than in the PHP pass below because it runs BEFORE the LIMIT: filtering
            // afterwards is what made a 495-row court silently become its
            // alphabetically-first 200, and it would make Total a lie again.
            //
            // Three branches, matching the three returns in OrderForAwardRow():
            // a real art-backed order or a custom entry by id; archery by
            // kingdomaward name, confined (as the PHP is) to rows with no resolvable
            // base award; and the alias, which re-points the grant at a different
            // catalogue entry and must itself be admitted — all 11 of the ORK's
            // alias rows sit on Custom Title and point at a real title.
            . "\n                   and (coalesce(a.award_id, 0) in (" . implode(',', Scroll::PrintableAwardIds()) . ')'
            . "\n                        or (a.award_id is null and lower(trim(coalesce(ka.name, ''))) in ("
                . self::sqlStringList(Scroll::ARCHERY_NAMES) . ')))'
            . "\n                   and coalesce(awards.alias_award_id, 0) in (0,"
                . implode(',', Scroll::PrintableAwardIds()) . ')';

        $names = self::officerNameList($officerNames);
        if ($names !== '') {
            $printable .= "\n                   and (a.award_id is not null or coalesce(ka.name, '') not in (" . $names . '))';
        }

        $keyset = '';
        $after  = self::decodeBulkCursorForTest($cursor);
        if ($after !== null) {
            $keyset = "\n                   and (" . implode(', ', self::BULK_SORT) . ') > ('
                . self::sqlString($after['Persona']) . ', '
                . self::sqlString($after['Name']) . ', '
                . (int) $after['Rank'] . ', '
                . (int) $after['AwardsId'] . ')';
        }

        return 'from ' . DB_PREFIX . 'awards awards
                  left join ' . DB_PREFIX . 'kingdomaward ka on ka.kingdomaward_id = awards.kingdomaward_id
                  left join ' . DB_PREFIX . 'award a on a.award_id = ka.award_id
                  left join ' . DB_PREFIX . 'award alias on alias.award_id = awards.alias_award_id
                  left join ' . DB_PREFIX . 'mundane m on m.mundane_id = awards.mundane_id
                  left join ' . DB_PREFIX . 'mundane gb on gb.mundane_id = awards.given_by_id
                  left join ' . DB_PREFIX . 'park p on p.park_id = awards.at_park_id
                  left join ' . DB_PREFIX . 'kingdom k on k.kingdom_id = awards.at_kingdom_id
                 where ' . $scope . $range . $printable . $keyset;
    }

    /**
     * The bulk selection — the module's ONE new query. Everything else reuses
     * Player::AwardsForPlayer().
     *
     * Public and named ...ForTest so ScrolleryBulkQueryTest can assert on the
     * generated statement without reflection and without a database. It builds a
     * string and touches nothing.
     *
     * @param array<string, true>|list<string> $officerNames see bulkFromWhere()
     * @param string $cursor an opaque keyset cursor from bulkCursorForTest()
     */
    public function bulkSqlForTest(
        string $type,
        string $id,
        string $scopeType,
        string $scopeId,
        string $from = '',
        string $to = '',
        array $officerNames = [],
        string $cursor = '',
        int $limit = 0
    ): string {
        $limit = $limit > 0 ? min($limit, Scroll::MAX_PRINT_RUN) : Scroll::MAX_PRINT_RUN;

        // The alias join exists for the exclusion predicate above; alias.award_id is
        // selected as well so the PHP-side Scroll::IsExcludedFromScrollery() check on
        // the shaped row sees the same thing the WHERE did. It is deliberately NOT
        // coalesced into officer_role / is_ladder / the title — that inconsistency
        // with AwardsForPlayer() predates this change and is not fixed here.
        return 'select awards.awards_id, awards.mundane_id, awards.rank, awards.date, awards.note,
                       awards.custom_name, awards.at_park_id, awards.at_kingdom_id, awards.at_event_id,
                       a.award_id, a.name, a.officer_role, a.is_ladder,
                       alias.award_id as alias_award_id,
                       ka.name as kingdom_awardname,
                       m.persona as recipient,
                       gb.persona as given_by,
                       p.name as park_name, k.name as kingdom_name
                  ' . self::bulkFromWhere($type, $id, $scopeType, $scopeId, $from, $to, $officerNames, $cursor) . '
                 order by ' . implode(', ', self::BULK_SORT) . '
                 limit ' . ($limit + 1);
    }

    /**
     * The TRUE number of printable rows the filter matches, so the page can say
     * "200 of 495" rather than quietly hand back 200 and call it the answer.
     *
     * Same predicate as the page query with the cursor dropped — a count of the whole
     * filter, not of the current page.
     *
     * @param array<string, true>|list<string> $officerNames see bulkFromWhere()
     */
    public function bulkCountSqlForTest(
        string $type,
        string $id,
        string $scopeType,
        string $scopeId,
        string $from = '',
        string $to = '',
        array $officerNames = []
    ): string {
        return 'select count(*) as total
                  ' . self::bulkFromWhere($type, $id, $scopeType, $scopeId, $from, $to, $officerNames, '');
    }

    /**
     * The cursor for "everything after this row", built from a shaped bulk row.
     *
     * Opaque and base64'd so nothing downstream is tempted to parse or hand-edit it,
     * but its contents are re-validated on decode rather than trusted: the two string
     * members go through sqlString() and the two ids through (int).
     *
     * @param array<string, mixed> $row a row from awards_for_bulk()
     */
    public static function bulkCursorForTest(array $row): string
    {
        return base64_encode((string) json_encode([
            'p' => (string) ($row['Recipient'] ?? ''),
            'n' => (string) ($row['SortName'] ?? ''),
            'r' => (int) ($row['Rank'] ?? 0),
            'a' => (int) ($row['AwardsId'] ?? 0),
        ]));
    }

    /**
     * @return array{Persona: string, Name: string, Rank: int, AwardsId: int}|null
     */
    public static function decodeBulkCursorForTest(string $cursor): ?array
    {
        if (trim($cursor) === '') {
            return null;
        }

        $json = base64_decode(strtr($cursor, ' ', '+'), true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || (int) ($data['a'] ?? 0) <= 0) {
            return null;
        }

        return [
            'Persona'  => (string) ($data['p'] ?? ''),
            'Name'     => (string) ($data['n'] ?? ''),
            'Rank'     => (int) ($data['r'] ?? 0),
            'AwardsId' => (int) ($data['a'] ?? 0),
        ];
    }

    /**
     * A quoted, comma-separated SQL list of officer-position names, or ''.
     *
     * Accepts either officer_names_for_kingdom()'s name => true map or a plain list.
     *
     * @param array<string, true>|list<string> $officerNames
     */
    private static function officerNameList(array $officerNames): string
    {
        $quoted = [];
        foreach ($officerNames as $key => $value) {
            $name = is_string($key) ? $key : (string) $value;
            $name = trim($name);
            if ($name !== '') {
                $quoted[self::sqlString($name)] = true;
            }
        }

        return implode(', ', array_keys($quoted));
    }

    /**
     * A single-quoted SQL string literal.
     *
     * mysql_real_escape_string() is a NO-OP SHIM in this codebase, so it is not used.
     * Doubling the quote is standard SQL and holds whether or not NO_BACKSLASH_ESCAPES
     * is set; the backslash and control characters are removed outright rather than
     * escaped, because nothing that legitimately reaches here — a persona, an award
     * name — contains them.
     */
    private static function sqlString(string $value): string
    {
        $value = str_replace(["\\", "\0", "\n", "\r", "\x1a"], '', $value);

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * A comma-separated list of SQL string literals, for an IN () clause.
     *
     * @param list<string> $values
     */
    private static function sqlStringList(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => self::sqlString($v), $values));
    }

    /**
     * Award rows for a bulk print, shaped like the reprint list.
     *
     * Returns both the printable rows and the ones excluded for having no artwork,
     * so the officer is told rather than silently handed fewer scrolls than awards.
     *
     * Failed says the query itself errored — distinct from "no awards matched", which
     * an officer would otherwise read as "there is nothing to print".
     *
     * Total is the TRUE number of printable rows the filter matches, counted by the
     * same predicate on the server. Truncated now means "Total exceeds this page",
     * not "we silently threw the rest away": NextCursor carries the keyset for the
     * next page, so every one of a 495-award court's certificates is reachable. Pass
     * that value straight back in as $cursor.
     *
     * Scroll::MAX_PRINT_RUN stays the hard server-side guard on ONE render run.
     * Scroll::PRINT_CHUNK is the separate, smaller size a browser print job should
     * use — front end's decision, exposed as a domain constant so the two agree.
     *
     * @return array{Printable: array<int, array>, Excluded: array<int, array>, Failed: bool,
     *               Truncated: bool, Total: int, Returned: int, Cursor: string, NextCursor: string}
     */
    public function awards_for_bulk(
        string $type,
        int $id,
        string $scopeType,
        int $scopeId,
        string $from = '',
        string $to = '',
        string $cursor = '',
        int $limit = 0
    ): array {
        $printable = [];
        $excluded  = [];
        $empty     = [
            'Printable'  => $printable,
            'Excluded'   => $excluded,
            'Failed'     => false,
            'Truncated'  => false,
            'Total'      => 0,
            'Returned'   => 0,
            'Cursor'     => $cursor,
            'NextCursor' => '',
        ];

        if ($id <= 0 || $scopeId <= 0) {
            return $empty;
        }

        // A park's scope is the park's own kingdom, not at_kingdom_id (blank for a
        // park grant) — see Model_Award::kingdom_for_grant_row()'s doc for the same
        // split. Resolved BEFORE the query now, because the officer-name set is part
        // of the WHERE rather than a correction applied afterwards.
        $kingdomId    = $scopeType === 'kingdom' ? $scopeId : (int) ($this->park_details($scopeId)['KingdomId'] ?? 0);
        $officerNames = $this->award()->officer_names_for_kingdom($kingdomId);

        $pageSize = $limit > 0 ? min($limit, Scroll::MAX_PRINT_RUN) : Scroll::MAX_PRINT_RUN;

        $rs = $this->db_query(
            $this->bulkSqlForTest(
                $type,
                (string) $id,
                $scopeType,
                (string) $scopeId,
                $from,
                $to,
                $officerNames,
                $cursor,
                $pageSize
            )
        );
        if ($rs === null) {
            return array_merge($empty, ['Failed' => true]);
        }

        $total = $this->count_query(
            $this->bulkCountSqlForTest($type, (string) $id, $scopeType, (string) $scopeId, $from, $to, $officerNames)
        );

        // The query asks for one row more than the page purely to detect that another
        // page exists.
        $hasMore = count($rs) > $pageSize;
        if ($hasMore) {
            $rs = array_slice($rs, 0, $pageSize);
        }

        foreach ($rs as $row) {
            // Shaped to the keys Scroll::HasArt() / OrderForAwardRow() and
            // ResolveTitle() expect. OfficerRole matters here: without it every
            // officer-position row in a bulk selection would look custom-eligible
            // (IsCustomEligible() defaults an absent key to 'none').
            //
            // IsLadder is the same shape of dependency for BuildScroll()'s `ranked`:
            // without it a bulk run prints all 292 of Walker in the Middle's rank-0
            // grants with no blank to write the level into. Both read the BASE award
            // (a.*) rather than the alias-coalesced value AwardsForPlayer() uses —
            // the alias join added for the exclusion predicate is deliberately not
            // folded into these, which is the same inconsistency officer_role already
            // lives with.
            $shaped = [
                'AwardsId'         => (int) $row['awards_id'],
                'AwardId'          => (int) $row['award_id'],
                'AliasAwardId'     => (int) ($row['alias_award_id'] ?? 0),
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
                // Not revoked by construction — the WHERE says so — but carried on the
                // row anyway so a template never has to guess, and so the key means the
                // same thing here as on an AwardsForPlayer() row.
                'Revoked'          => 0,
                // The second ORDER BY term, verbatim, so bulkCursorForTest() can build
                // a keyset from a shaped row without re-deriving the SQL's COALESCE.
                'SortName'         => (string) ($row['name'] ?? '') !== ''
                    ? (string) $row['name']
                    : (string) ($row['kingdom_awardname'] ?? ''),
            ];

            // Same dangling-award_id shape choicesForAwardListForTest() closes for
            // the create dropdown: this query's LEFT JOIN to `award` misses for
            // archery's real grants (base award_id 0/94/131, no matching ork_award
            // row) — that must stay open — but also for an officer position whose
            // base award has simply gone missing, which must not. Told apart by
            // Model_Award::isDanglingRowAnOfficer() against the granting kingdom's
            // own catalog.
            //
            // The WHERE now applies the same rule via $officerNames, so this is a
            // belt-and-braces second pass rather than the only gate — kept because it
            // is the shared, tested implementation of the rule and because passing an
            // empty name set (a kingdom whose catalog could not be read) must still
            // fail closed.
            if ($row['award_id'] === null
                && Model_Award::isDanglingRowAnOfficer($shaped['KingdomAwardName'], $officerNames)
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

        $last = end($rs);

        return [
            'Printable'  => $printable,
            'Excluded'   => $excluded,
            'Failed'     => false,
            'Truncated'  => $hasMore,
            'Total'      => $total,
            'Returned'   => count($rs),
            'Cursor'     => $cursor,
            // Built from the LAST ROW OF THE PAGE, not from the last printable one:
            // an art-less row still consumed a slot in the sort, so skipping it would
            // make the next page start before it and repeat rows.
            'NextCursor' => $hasMore && is_array($last)
                ? self::bulkCursorForTest([
                    'Recipient' => (string) ($last['recipient'] ?? ''),
                    'SortName'  => (string) ($last['name'] ?? '') !== ''
                        ? (string) $last['name']
                        : (string) ($last['kingdom_awardname'] ?? ''),
                    'Rank'      => (int) ($last['rank'] ?? 0),
                    'AwardsId'  => (int) ($last['awards_id'] ?? 0),
                ])
                : '',
        ];
    }

    /**
     * A single scalar `total` from a count query, or 0.
     *
     * A sibling to db_query()/event_rows() for the same reason those are siblings of
     * each other: the field reads are hardcoded per query shape, so a shared reader
     * would silently return null rather than fail.
     */
    private function count_query(string $sql): int
    {
        $db = Ork3::$Lib->player->db;
        $db->Clear();

        try {
            $rs = $db->query($sql);
        } catch (Throwable $e) {
            return 0;
        }

        if (!is_object($rs) || $this->result_failed($rs) || $rs->size() < 1 || !$rs->next()) {
            return 0;
        }

        return (int) $rs->total;
    }

    /**
     * The event list for the bulk-print picker — every event that has at least one
     * award the bulk query above could actually return in this scope.
     *
     * Reuses the SAME scope predicate bulkSqlForTest()'s 'kingdom'/'park' cases
     * apply (see eventsWithPrintableAwardsSqlForTest() below), so the picker can
     * never offer an event whose awards the bulk query would then refuse: an event
     * only appears here because at least one award row at it already matches that
     * exact WHERE.
     *
     * @return array<int, array{EventId:int, Label:string}>
     */
    public function events_with_printable_awards(string $scopeType, int $scopeId): array
    {
        if ($scopeId <= 0) {
            return [];
        }

        $rows = $this->event_rows($this->eventsWithPrintableAwardsSqlForTest($scopeType, (string) $scopeId));
        if ($rows === null) {
            return [];
        }

        $events = [];
        foreach ($rows as $row) {
            $eventId = (int) $row['event_id'];
            $count   = (int) $row['award_count'];
            $date    = (string) $row['last_date'];
            $name    = trim((string) $row['event_name']);

            $events[] = [
                'EventId' => $eventId,
                'Label'   => ($name !== '' ? $name : ('Event ' . $eventId))
                    . ' — ' . $date . ' (' . $count . ' award' . ($count === 1 ? '' : 's') . ')',
            ];
        }

        return $events;
    }

    /**
     * Public and named ...SqlForTest() for the same reason bulkSqlForTest() is:
     * ScrolleryEventsQueryTest can assert on the generated statement with no
     * database. The surrounding code interpolates rather than binds, and this
     * codebase's mysql_real_escape_string() is a no-op shim, so what protects this
     * is the (int) cast on the scope id — never interpolate it as a string.
     *
     * ork_event carries no date of its own (see ork.sql) — only `event_id`,
     * `kingdom_id`, `park_id`, `mundane_id`, `unit_id`, `name`, `has_heraldry` —
     * dates live one join away on ork_event_calendardetail, which is one-to-many
     * per event (multiple dated occurrences, gated by a `current` flag) and would
     * turn this group-by into a fan-out. max(awards.date) avoids that entirely and
     * is the date this scope's officer actually granted things on.
     */
    public static function eventsWithPrintableAwardsSqlForTest(string $scopeType, string $scopeId): string
    {
        $scopeId = (int) $scopeId;

        $scope = ($scopeId > 0 && $scopeType === 'kingdom')
            ? 'awards.at_kingdom_id = ' . $scopeId
            : (($scopeId > 0 && $scopeType === 'park')
                ? 'awards.at_park_id = ' . $scopeId
                // Never widen to "everything" for an unrecognised scope type or an
                // empty/zero scope id — mirrors bulkSqlForTest()'s default: case.
                // at_kingdom_id and at_park_id each sit at 0 on a huge share of real
                // award rows (a park grant never sets at_kingdom_id; a kingdom grant
                // never sets at_park_id), so a bare "column = 0" built from an
                // untrusted or absent scope id would hand back one whole class of
                // body's awards rather than none. -1 can never match a real row.
                : 'awards.at_park_id = -1');

        return 'select awards.at_event_id as event_id,
                       count(*) as award_count,
                       max(awards.date) as last_date,
                       e.name as event_name
                  from ' . DB_PREFIX . 'awards awards
                  left join ' . DB_PREFIX . 'event e on e.event_id = awards.at_event_id
                 where awards.at_event_id > 0
                   and ' . $scope . '
                   -- Same two guards the bulk query applies. Without them an event
                   -- whose only awards were revoked still appears in the picker with
                   -- a count, and choosing it returns nothing — 2,287 revoked rows
                   -- carry a park/kingdom scope, so this is not hypothetical.
                   and awards.revoked = 0
                   and awards.mundane_id > 0
                 group by awards.at_event_id
                 order by last_date desc
                 limit 200';
    }

    /**
     * Rows for events_with_printable_awards(), or null on a query failure.
     *
     * A sibling to db_query() above rather than a reuse of it: db_query()'s field
     * reads are hardcoded to the bulk row's own column set (awards_id, mundane_id,
     * ...), so feeding it this query's different columns (event_id, award_count,
     * ...) would silently read null for every field rather than fail loudly. The
     * $DB->Clear()-before-query and manual-Next()-per-row shape is identical.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function event_rows(string $sql): ?array
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
                    'event_id'    => $rs->event_id,
                    'award_count' => $rs->award_count,
                    'last_date'   => $rs->last_date,
                    'event_name'  => $rs->event_name,
                ];
            }
        }

        return $rows;
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
                    'alias_award_id'    => $rs->alias_award_id,
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

    /**
     * Whether one award row may be printed by one viewer, AND WHY NOT.
     *
     * Order matters and is fail-closed: revocation is checked before identity, so a
     * recipient cannot reprint an award that was stripped from them, and neither can
     * an ORK administrator. 2,856 rows are revoked; 59 of them still carry a live
     * mundane_id, which is exactly the "reprint your own stripped award" hole.
     *
     * Then attribution. 120,284 rows (30.74% of 391,296, 6,831 of them since 2023)
     * carry neither at_park_id nor at_kingdom_id, so there is no issuing body for the
     * certificate to name and no scope for an officer's authority to attach to. Those
     * used to be printable by the recipient alone — silently, with no explanation.
     * They now say so, and an ORK admin can print them.
     *
     * DELIBERATELY NOT WIDENED: an unattributed row could fall back to the broader
     * awards.park_id / awards.kingdom_id columns (the recipient's home park at grant
     * time) or to the kingdomaward's own kingdom. That is an authorisation widening
     * the maintainer has not ruled on — it would hand a park officer print rights
     * over awards their park never granted — so it is not done here. Doing it later
     * means: select those columns in award_row()'s query shape, add a
     * FallbackParkId/FallbackKingdomId pair to the row, and add a fourth branch below
     * gated on an explicit config flag.
     *
     * NEVER CACHED. An authority decision keyed on anything less than
     * (viewer, award) is a cross-tenant disclosure waiting to happen, and this one is
     * cheap enough not to need it.
     *
     * @return array{Allowed: bool, Reason: string, Message: string}
     */
    private function print_permission(array $row, int $viewerId): array
    {
        if ((int) ($row['Revoked'] ?? 0) === 1) {
            return self::denial(self::DENIED_REVOKED);
        }

        $parkId    = (int) ($row['ParkId'] ?? 0);
        $kingdomId = (int) ($row['KingdomId'] ?? 0);
        $auth      = Ork3::$Lib->authorization;

        // The global-admin branch. HasAuthority() short-circuits on a TRUE global
        // grant (role=admin with park_id/kingdom_id/unit_id/event_id all zero) before
        // its own `0 == $id` bail-out, so AUTH_ADMIN with id 0 is the shape the rest
        // of the CRM already uses — see controller.Admin.php. Checked before the
        // attribution gate so an admin can reach the 120,284 unattributed rows.
        //
        // HasAuthority() returns NULL on denial, not false, so it is cast rather than
        // returned through a bool-typed expression.
        if ($viewerId > 0 && (bool) $auth->HasAuthority($viewerId, AUTH_ADMIN, 0, AUTH_ADMIN)) {
            return self::allowed();
        }

        if ((int) ($row['MundaneId'] ?? 0) === $viewerId && $viewerId > 0) {
            return self::allowed();
        }

        if ($parkId <= 0 && $kingdomId <= 0) {
            return self::denial(self::DENIED_UNATTRIBUTED);
        }

        if ($parkId > 0 && (bool) $auth->HasAuthority($viewerId, AUTH_PARK, $parkId, AUTH_EDIT)) {
            return self::allowed();
        }
        if ($kingdomId > 0 && (bool) $auth->HasAuthority($viewerId, AUTH_KINGDOM, $kingdomId, AUTH_EDIT)) {
            return self::allowed();
        }

        return self::denial(self::DENIED_AUTH);
    }

    /**
     * The public form of print_permission(), by award id — what a controller should
     * call when it needs to TELL the officer why nothing printed.
     *
     * Runs the whole gate in the same order scroll_for_awards_id() does, so the two
     * can never disagree about a given id.
     *
     * @return array{Allowed: bool, Reason: string, Message: string}
     */
    public function print_decision(int $awardsId, int $viewerId): array
    {
        if ($awardsId <= 0 || $viewerId <= 0) {
            return self::denial(self::DENIED_AUTH);
        }

        $row = $this->award_row($awardsId);
        if ($row === null) {
            return self::denial(self::DENIED_MISSING);
        }

        $permission = $this->print_permission($row, $viewerId);
        if (!$permission['Allowed']) {
            return $permission;
        }

        // Art last: an unauthorised viewer must not learn from the message whether a
        // given award id even has a certificate.
        if (Scroll::HasArt($row)) {
            return self::allowed();
        }

        // Always the policy answer, now that the rule is an ALLOWLIST. Under the old
        // denylist "nothing to print" and "we deliberately do not print this" were
        // genuinely different states and DENIED_NO_ART was reachable — an award that
        // nothing excluded but nothing covered either. There is no such state left:
        // an award prints because it resolves to real artwork, so failing to resolve
        // IS the policy refusing it. DENIED_NO_ART survives as a reason code because
        // Scroll::Render() still degrades on a missing partial file, which is a
        // packaging fault a future caller may want to report distinctly.
        return self::denial(self::DENIED_EXCLUDED);
    }

    /** @return array{Allowed: bool, Reason: string, Message: string} */
    private static function allowed(): array
    {
        return ['Allowed' => true, 'Reason' => self::PRINT_ALLOWED, 'Message' => ''];
    }

    /** @return array{Allowed: bool, Reason: string, Message: string} */
    private static function denial(string $reason): array
    {
        return [
            'Allowed' => false,
            'Reason'  => $reason,
            'Message' => self::DENIAL_MESSAGES[$reason] ?? self::DENIAL_MESSAGES[self::DENIED_AUTH],
        ];
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

        return $this->possessive_pronoun(Ork3::$Lib->player->GetScrollRecipient($mundaneId));
    }

    /**
     * A per-print pronoun override, resolved rather than trusted verbatim — see
     * Scroll::ResolvePronounOverride(). The create tool's override control is
     * free text, not a closed vocabulary, so "he/him" typed into the box is
     * exactly as reachable as it is from a stored profile, and needs the same
     * subject/object -> possessive resolution or the certificate prints it raw.
     */
    public function resolve_pronoun_override(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $list = Ork3::$Lib->pronoun->GetPronounList();

        return Scroll::ResolvePronounOverride($raw, $list['Pronouns'] ?? []);
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

        // pronoun_freetext is populated by another branch; read it only if the key is
        // actually present, so this works either side of that merge.
        $freetext = trim((string) ($player['PronounFreetext'] ?? ''));
        if ($freetext !== '') {
            $list       = Ork3::$Lib->pronoun->GetPronounList();
            $possessive = Scroll::PossessiveFromFreetext($freetext, $list['Pronouns'] ?? []);
            if ($possessive !== '') {
                return $possessive;
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

        return $row !== null && (int) ($row['Revoked'] ?? 0) !== 1 && Scroll::HasArt($row);
    }
}
