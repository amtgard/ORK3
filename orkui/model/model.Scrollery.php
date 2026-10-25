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

    private ?ScrollQuery $scrollQuery = null;

    /**
     * Scrollery's own SQL, which lives in the domain.
     *
     * This model used to be the only one of the 33 under orkui/model/ that named
     * DB_PREFIX or reached Ork3::$Lib->player->db. The statements and their
     * fetchers are now ScrollQuery's (system/lib/ork3/class.ScrollQuery.php) and
     * this model keeps only the shaping.
     *
     * startup.php instantiates every class in system/lib/ork3/, so the container
     * copy is the one to use; the `new` is a floor for a caller that booted the
     * library differently, not an expected path.
     */
    private function scroll_query(): ScrollQuery
    {
        return $this->scrollQuery ??= (Ork3::$Lib->scrollquery ?? new ScrollQuery());
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

        // Delegated rather than duplicated: one id is the degenerate batch, and two
        // implementations of "which award rows may this viewer print" would be two
        // chances for the gate to drift.
        $run = $this->scrolls_for_awards_ids([$awardsId], $viewerId, $variant);

        return $run['Scrolls'][$awardsId] ?? null;
    }

    /**
     * A WHOLE PRINT RUN, in a bounded number of queries.
     *
     * WHAT THIS REPLACES. The render route looped scroll_for_awards_id() per id, and
     * each pass ran AwardsForPlayer() (a 9-table SELECT DISTINCT over a 391k-row
     * table) plus a per-recipient lookup, plus one or two GetPronounList() calls. At
     * Scroll::MAX_PRINT_RUN that is several hundred statements before a byte of
     * certificate exists. This is: one award-rows query, one recipients query, one
     * pronoun-table read, and then park/kingdom lookups that were already memoised.
     *
     * ⚠️ THE PERMISSION GATE IS STILL PER (VIEWER, AWARD) AND IS STILL NOT CACHED.
     * print_permission() runs once per row below, exactly as it did per call before.
     * Batching the READ is safe; batching or caching the DECISION is how a
     * cross-tenant disclosure happens, and this surface has leaked once already.
     *
     * Returns both halves so a caller can report honestly rather than silently
     * printing fewer sheets than were asked for:
     *   Scrolls  — awards_id => BuildScroll() output, only for ids that passed;
     *   Refused  — awards_id => a Model_Scrollery::DENIED_* reason, for ids that did not.
     * Order follows the ids as given.
     *
     * @param array<int, mixed> $awardsIds
     * @return array{Scrolls: array<int, array>, Refused: array<int, string>}
     */
    public function scrolls_for_awards_ids(array $awardsIds, int $viewerId, string $variant = ''): array
    {
        $ids = [];
        foreach ($awardsIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        if ($ids === [] || $viewerId <= 0) {
            return ['Scrolls' => [], 'Refused' => []];
        }

        $rows = $this->award_rows_by_id($ids);

        // Two passes. The first decides, per (viewer, award), what may print; only
        // then is the recipient set known, and only then is it worth one query.
        // Resolving recipients first would fetch personas for rows the viewer is
        // not allowed to see — cheap, but it is exactly the habit that leaks.
        $allowed    = [];
        $refused    = [];
        $mundaneIds = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                $refused[$id] = self::DENIED_MISSING;
                continue;
            }
            if (!Scroll::HasArt($row)) {
                $refused[$id] = self::DENIED_EXCLUDED;
                continue;
            }

            $permission = $this->print_permission($row, $viewerId);
            if (!$permission['Allowed']) {
                $refused[$id] = $permission['Reason'];
                continue;
            }

            $allowed[$id] = $row;
            $mundaneId    = (int) ($row['MundaneId'] ?? 0);
            if ($mundaneId > 0) {
                $mundaneIds[$mundaneId] = true;
            }
        }

        // GetScrollRecipients(), not GetPlayer(): a certificate needs a persona and a
        // pronoun, and GetPlayer() would additionally run two GetDues() passes, a
        // heraldry build, a design-row find, an authorization check and (for recent
        // joiners) an attendance aggregate — per certificate, up to 200 times a run.
        $recipients = $mundaneIds === []
            ? []
            : Ork3::$Lib->player->GetScrollRecipients(array_keys($mundaneIds));

        $scrolls = [];
        foreach ($allowed as $id => $row) {
            $player = $recipients[(int) ($row['MundaneId'] ?? 0)] ?? [];

            $scrolls[$id] = Scroll::BuildScroll($row, [
                'player'      => (string) ($player['Persona'] ?? ''),
                'pronoun'     => $this->possessive_pronoun($player),
                'heraldry'    => $this->heraldry_url($row),
                'issuerTitle' => $this->issuer_title($row),
                'variant'     => $variant,
            ]);
        }

        return ['Scrolls' => $scrolls, 'Refused' => $refused];
    }

    /**
     * A print run as DATA — everything Scrollery knows about a set of certificates,
     * with no markup anywhere in it.
     *
     * WHY. Scrollery/render is a page, so a renderer, an archiver or a mail-merge
     * that wants the facts has to scrape them back out of SVG. This is the same
     * BuildScroll() output the render surface draws from, keyed by awards id, plus
     * the refusals — so a consumer knows which of the ids it asked for did not come
     * back, and why, instead of counting the answers.
     *
     * BuildScroll()'s output is already markup-free: it is order, rank, variant,
     * title, phrase, player, pronoun, reason, date, issuer, heraldry, palette,
     * resources and recipe. Scroll::Render() is what turns that into a certificate,
     * and it is not called here.
     *
     * The gate is identical to the render route's, because it IS the render route's
     * — scrolls_for_awards_ids(), one call, one set of decisions. A manifest can
     * therefore never disclose a certificate the same viewer could not print.
     *
     * @param array<int, mixed> $awardsIds
     * @return array{Generated:string, ViewerId:int, Variant:string, Requested:int, Returned:int,
     *               Scrolls:array<int, array>, Refused:array<int, array{AwardsId:int, Reason:string, Message:string}>}
     */
    public function print_manifest(array $awardsIds, int $viewerId, string $variant = ''): array
    {
        $run = $this->scrolls_for_awards_ids($awardsIds, $viewerId, $variant);

        $refused = [];
        foreach ($run['Refused'] as $awardsId => $reason) {
            $refused[] = [
                'AwardsId' => (int) $awardsId,
                'Reason'   => $reason,
                'Message'  => self::DENIAL_MESSAGES[$reason] ?? self::DENIAL_MESSAGES[self::DENIED_AUTH],
            ];
        }

        $scrolls = [];
        foreach ($run['Scrolls'] as $awardsId => $scroll) {
            $scrolls[] = ['awardsId' => (int) $awardsId] + $scroll;
        }

        return [
            // ISO 8601 with an offset: a manifest that outlives the request has to
            // say which clock it was written by, and PHP's is five hours off the
            // database's in this deployment (see ScrollPrintLog::printedRecently()).
            'Generated' => date('c'),
            'ViewerId'  => $viewerId,
            'Variant'   => $variant,
            'Requested' => count($run['Scrolls']) + count($run['Refused']),
            'Returned'  => count($run['Scrolls']),
            'Scrolls'   => $scrolls,
            'Refused'   => $refused,
        ];
    }

    /**
     * Award rows for a list of ids, keyed by awards_id, corrected the way
     * award_row() corrects one.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function award_rows_by_id(array $ids): array
    {
        $response = Ork3::$Lib->player->AwardsForIds($ids);

        $rows = [];
        foreach (($response['Awards'] ?? []) as $row) {
            $rows[(int) $row['AwardsId']] = $this->award()->correct_dangling_officer_role($row);
        }

        return $rows;
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
     * Display is the issuer's whole name as a person reads it — "The Kingdom of the
     * Wetlands", "Barony Mordengaard" — for chips and door labels. Title and Name are
     * the composer's fields and, for a kingdom, are the split form ("Kingdom" and "the
     * Wetlands"), which reads wrongly as a label.
     *
     * @return array{Type:string,Id:int,Name:string,Title:string,Display:string,KingdomId:int,Heraldry:string,UseThe:bool,UseOf:bool}
     */
    public function scope_context(string $scopeType, int $scopeId): array
    {
        if ($scopeType === 'kingdom') {
            $info = $this->kingdom_short_info($scopeId);

            // Kingdom names already carry their own article and rank word —
            // "The Kingdom of the Wetlands" — so the stored form prints neither
            // separately. The composer's fields should show what WILL print, so hand
            // it the split form: Title "Kingdom", both boxes ticked, Name "the
            // Wetlands" (the article stays on the name; the text model lifts it out
            // again at print). A name that does not read "<rank> of <place>" comes
            // back whole with the boxes clear, exactly as stored.
            $issuer = ScrollText::IssuerLines('', (string) ($info['KingdomName'] ?? ''), false, false, 'kingdom');

            return [
                'Type'      => 'kingdom',
                'Id'        => $scopeId,
                'Name'      => $issuer['name'],
                'Title'     => $issuer['title'],
                'Display'   => trim((string) ($info['KingdomName'] ?? '')),
                'UseThe'    => $issuer['useThe'],
                'UseOf'     => $issuer['useOf'],
                'KingdomId' => $scopeId,
                'Heraldry'  => (int) ($info['HasHeraldry'] ?? 0) === 1
                    ? (string) Ork3::$Lib->heraldry->GetHeraldryUrl(
                        // See heraldry_url(): inert today, and the guard against the
                        // renditions branch silently serving a 256px thumbnail onto a
                        // 300 dpi certificate once it merges.
                        ['Type' => 'Kingdom', 'Id' => $scopeId, 'Size' => 'master']
                    )['Url']
                    : '',
            ];
        }

        $park = $this->park_details($scopeId);

        // ork_parktitle.title is "Inactive" on 5 parks — a status, not a rank word. The
        // text model reads it as no title, which then takes the same Barony floor an
        // untitled park always did.
        $parkTitle = ScrollText::IssuerLines((string) ($park['ParkTitle'] ?? ''), '', true, true, 'park')['title'];
        $parkTitle = $parkTitle !== '' ? $parkTitle : 'Barony';
        $parkName  = (string) ($park['ParkName'] ?? '');

        return [
            'Type'      => 'park',
            'Id'        => $scopeId,
            'Name'      => $parkName,
            'Title'     => $parkTitle,
            'Display'   => trim($parkTitle . ' ' . $parkName),
            'UseThe'    => true,
            'UseOf'     => true,
            'KingdomId' => (int) ($park['KingdomId'] ?? 0),
            'Heraldry'  => (int) ($park['HasHeraldry'] ?? 0) === 1
                ? (string) Ork3::$Lib->heraldry->GetHeraldryUrl(
                    ['Type' => 'Park', 'Id' => $scopeId, 'Size' => 'master']
                )['Url']
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
     * ScrolleryChoicesForAwardListTest) — the same convention as
     * ScrollQuery::bulkSqlForTest() and matchingChoiceForTest() below.
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
     * against a hand-built choice list, the same way ScrollQuery::bulkSqlForTest()
     * keeps the bulk query testable without a database.
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

        $query = $this->scroll_query();

        $rs = $query->BulkAwardRows(
            $type,
            $id,
            $scopeType,
            $scopeId,
            $from,
            $to,
            $officerNames,
            $cursor,
            $pageSize
        );
        if ($rs === null) {
            return array_merge($empty, ['Failed' => true]);
        }

        $total = $query->BulkAwardCount($type, $id, $scopeType, $scopeId, $from, $to, $officerNames);

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
                // The recipient's standing, for the bulk list to MARK — not to
                // filter on. A suspended or inactive member's past award is still
                // theirs, so these rows stay selected by default; the officer is
                // simply told before they commit 200 sheets to paper. Coalesced
                // because the mundane join is a LEFT one: a row whose recipient
                // record is gone reads as neither suspended nor inactive rather
                // than as a null the template has to guess about.
                'Suspended'        => (int) ($row['recipient_suspended'] ?? 0),
                'Active'           => (int) ($row['recipient_active'] ?? 1),
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
                // The second ORDER BY term, verbatim, so ScrollQuery::bulkCursorForTest()
                // can build a keyset from a shaped row without re-deriving the
                // SQL's COALESCE.
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
                ? ScrollQuery::bulkCursorForTest([
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
     * The event list for the bulk-print picker — every event that has at least one
     * award the bulk query above could actually return in this scope.
     *
     * Reuses the SAME scope predicate ScrollQuery::bulkSqlForTest()'s
     * 'kingdom'/'park' cases apply (see
     * ScrollQuery::eventsWithPrintableAwardsSqlForTest()), so the picker can never
     * offer an event whose awards the bulk query would then refuse: an event only
     * appears here because at least one award row at it already matches that exact
     * WHERE.
     *
     * @return array<int, array{EventId:int, Label:string}>
     */
    public function events_with_printable_awards(string $scopeType, int $scopeId): array
    {
        if ($scopeId <= 0) {
            return [];
        }

        $rows = $this->scroll_query()->EventsWithPrintableAwards($scopeType, $scopeId);
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
     *
     * `'Size' => 'master'` is INERT TODAY and correct later. GetHeraldryUrl()
     * currently ignores every key but Type and Id (class.Heraldry.php:30) — it
     * resolves one path and appends a filemtime cache-buster. The masters + WebP
     * renditions branch (feature/high-res-images) gives it renditions, and its
     * default is a 256 px thumbnail. This heraldry is drawn onto a 2550x3300
     * certificate; without the key, the day that branch merges is the day every
     * scroll silently starts printing a thumbnail, with nothing failing and nobody
     * told. Asking for the master now costs nothing and closes that.
     */
    private function heraldry_url(array $row): string
    {
        $parkId = (int) ($row['ParkId'] ?? 0);
        if ($parkId > 0) {
            $park = $this->park_details($parkId);
            if ((int) ($park['HasHeraldry'] ?? 0) === 1) {
                return (string) Ork3::$Lib->heraldry->GetHeraldryUrl(
                    ['Type' => 'Park', 'Id' => $parkId, 'Size' => 'master']
                )['Url'];
            }
        }

        $kingdomId = (int) ($row['KingdomId'] ?? 0);
        if ($kingdomId > 0) {
            if ((int) ($this->kingdom_short_info($kingdomId)['HasHeraldry'] ?? 0) === 1) {
                return (string) Ork3::$Lib->heraldry->GetHeraldryUrl(
                    ['Type' => 'Kingdom', 'Id' => $kingdomId, 'Size' => 'master']
                )['Url'];
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

    /**
     * GetKingdomShortInfo() costs a yapo find() PLUS a second banner query, and
     * heraldry_url() asks it once per certificate — so a 200-certificate run of
     * kingdom-issued awards was 400 statements for what is, at most, a handful of
     * distinct kingdoms. Held for the same reason park_details() is, and for a
     * much bigger win: 47% of ladder grants are kingdom-issued.
     *
     * @return array<string, mixed>
     */
    private function kingdom_short_info(int $kingdomId): array
    {
        static $cache = [];
        if (!array_key_exists($kingdomId, $cache)) {
            $response = Ork3::$Lib->kingdom->GetKingdomShortInfo(['KingdomId' => $kingdomId]);
            $cache[$kingdomId] = is_array($response['KingdomInfo'] ?? null) ? $response['KingdomInfo'] : [];
        }

        return $cache[$kingdomId];
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

        return Scroll::ResolvePronounOverride($raw, $this->pronoun_rows());
    }

    /**
     * The possessive forms the composer's "pronoun" datalist offers.
     *
     * THE DATALIST WAS DEAD CODE. Scrollery_create.tpl renders
     * `list="sc-pronoun-list"` only `if ($Pronouns)` and no controller ever set
     * `Pronouns` — verified, zero occurrences — so the input has advertised a
     * datalist that was never emitted. This is the missing half.
     *
     * De-duplicated and in table order (ork_pronoun.pronoun_id asc, which
     * GetPronounList() is documented as depending on): rows 4 and 12 both give
     * "her", 2 and 11 both give "his". Twelve rows collapse to
     * eir, his, pers, her, their, vis, xyr, hir, aer, faer.
     *
     * @return list<string>
     */
    public function possessive_suggestions(): array
    {
        $seen = [];
        foreach ($this->pronoun_rows() as $row) {
            $possessive = trim((string) ($row['Possessive'] ?? ''));
            if ($possessive !== '') {
                $seen[mb_strtolower($possessive)] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * ork_pronoun, read once per request.
     *
     * Twelve rows, and every certificate in a print run needs them — the render
     * loop used to re-run GetPronounList() once per recipient (twice, for a
     * recipient with custom pronouns), which is 200-400 statements a run for a
     * table that fits in a cache line.
     *
     * The WHOLE list, never a filtered one: possessive_for_ids() used to pass an
     * id filter, so the same twelve rows were fetched under a different WHERE for
     * every distinct pronoun id in the run.
     *
     * @return array<int, array{Subject:string, Possessive:string}>
     */
    private function pronoun_rows(): array
    {
        if ($this->pronounRows === null) {
            $list = Ork3::$Lib->pronoun->GetPronounList();
            $this->pronounRows = is_array($list['Pronouns'] ?? null) ? $list['Pronouns'] : [];
        }

        return $this->pronounRows;
    }

    /** @var array<int, array<string, mixed>>|null */
    private ?array $pronounRows = null;

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
        //
        // Scroll::ResolvePossessive(), NOT a bare PossessiveFromFreetext() with a
        // fall to "their". This branch used to give up the moment the subject table
        // missed, while the officer's override box — the same free text, typed by
        // someone else — got a post-slash fallback. So a recipient whose profile
        // said "ze/zir" printed "their", and an officer retyping it verbatim printed
        // "zir". One resolver now serves both doors; the cap is this door's own
        // column width rather than the override input's maxlength.
        $freetext = trim((string) ($player['PronounFreetext'] ?? ''));
        if ($freetext !== '') {
            $possessive = Scroll::ResolvePossessive(
                $freetext,
                $this->pronoun_rows(),
                Scroll::PRONOUN_FREETEXT_MAX_LENGTH
            );
            if ($possessive !== '') {
                return $possessive;
            }
        }

        return 'their';
    }

    /** @param int[] $ids */
    private function possessive_for_ids(array $ids): string
    {
        $rows = $this->pronoun_rows();
        foreach ($ids as $id) {
            $possessive = trim((string) ($rows[$id]['Possessive'] ?? ''));
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
