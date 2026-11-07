<?php

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
     * Aliases of the domain's refusal reasons (Scroll::DENIED_*), kept so templates
     * and tests that read Model_Scrollery::DENIED_* keep working. The rule and its
     * messages live in Scroll::PrintPermission() / Scroll::DENIAL_MESSAGES.
     */
    public const PRINT_ALLOWED = Scroll::PRINT_ALLOWED;
    public const DENIED_MISSING = Scroll::DENIED_MISSING;
    public const DENIED_REVOKED = Scroll::DENIED_REVOKED;
    public const DENIED_UNATTRIBUTED = Scroll::DENIED_UNATTRIBUTED;
    public const DENIED_NO_ART = Scroll::DENIED_NO_ART;
    public const DENIED_EXCLUDED = Scroll::DENIED_EXCLUDED;
    public const DENIED_AUTH = Scroll::DENIED_AUTH;

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
     * ⚠️ THE PERMISSION GATE IS STILL PER (VIEWER, AWARD). Each row is decided by
     * Scroll::PrintPermission(), through memoised_print_permission(): a memo that
     * lives for THIS call only, for ONE viewer, keyed by every input the decision
     * reads — so two rows share an answer only when they would compute the same one.
     * Nothing outlives the call; caching a decision across calls or viewers is how a
     * cross-tenant disclosure happens, and this surface has leaked once already.
     *
     * Returns both halves so a caller can report honestly rather than silently
     * printing fewer sheets than were asked for:
     *   Scrolls  — awards_id => BuildScroll() output, only for ids that passed;
     *   Refused  — awards_id => a Model_Scrollery::DENIED_* reason, for ids that did not.
     * Order follows the ids as given.
     *
     * $heraldry is the print page's run-level arms choice (Scroll::NormaliseRunHeraldry()).
     * 'auto' — the default, and what every caller but the print page passes — draws
     * exactly what heraldry_url() always drew. See run_heraldry() for the rest.
     *
     * @param array<int, mixed> $awardsIds
     * @return array{Scrolls: array<int, array>, Refused: array<int, string>}
     */
    public function scrolls_for_awards_ids(
        array $awardsIds,
        int $viewerId,
        string $variant = '',
        string $heraldry = Scroll::HERALDRY_AUTO
    ): array {
        $heraldry = Scroll::NormaliseRunHeraldry($heraldry);

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

        // Asked ONCE per call: admin is constant for the viewer, and asking it per row
        // was one HasAuthority() statement per certificate on top of the rest.
        $isAdmin = Scroll::IsPrintAdmin($viewerId);
        $memo    = [];

        // Two passes. The first decides, per (viewer, award), what may print; only
        // then is the recipient set known, and only then is it worth one query.
        // Resolving recipients first would fetch personas for rows the viewer is
        // not allowed to see — cheap, but it is exactly the habit that leaks.
        //
        // PERMISSION FIRST, facts about the row second. A missing id, a revoked row and
        // an excluded/no-art row are each reported as DENIED_AUTH to a viewer with no
        // claim on that row, or the Refused map (and print_manifest(), which returns it
        // verbatim) is an oracle over which award ids exist, were revoked, or carry
        // art. A print admin may see the real reason for a missing id.
        $allowed    = [];
        $refused    = [];
        $mundaneIds = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                $refused[$id] = $isAdmin ? self::DENIED_MISSING : self::DENIED_AUTH;
                continue;
            }

            $permission = $this->memoised_print_permission($row, $viewerId, $isAdmin, $memo);
            if (!$permission['Allowed']) {
                $refused[$id] = $permission['Reason'];
                continue;
            }

            if (!Scroll::HasArt($row)) {
                $refused[$id] = self::DENIED_EXCLUDED;
                continue;
            }

            $allowed[$id] = $row;
            $mundaneId    = (int) ($row['MundaneId'] ?? 0);
            if ($mundaneId > 0) {
                $mundaneIds[$mundaneId] = true;
            }
        }

        // ScrollRecipients(), not GetPlayer(): a certificate needs a persona and a
        // pronoun, and GetPlayer() would additionally run two GetDues() passes, a
        // heraldry build, a design-row find, an authorization check and (for recent
        // joiners) an attendance aggregate — per certificate, up to 200 times a run.
        $recipients = $mundaneIds === []
            ? []
            : $this->scroll_query()->ScrollRecipients(array_keys($mundaneIds));

        $scrolls = [];
        foreach ($allowed as $id => $row) {
            $player  = $recipients[(int) ($row['MundaneId'] ?? 0)] ?? [];
            $context = [
                'player'      => (string) ($player['Persona'] ?? ''),
                'pronoun'     => $this->possessive_pronoun($player),
                'heraldry'    => '',
                'issuerTitle' => $this->issuer_title($row),
                'variant'     => $variant,
            ];

            if ($heraldry === Scroll::HERALDRY_AUTO) {
                // Untouched on purpose: Automatic is the reprint as it always printed,
                // and no design key, so the print log records it as it always did.
                $context['heraldry'] = $this->heraldry_url($row);
            } else {
                // A park-only row names no kingdom of its own; its park's kingdom is
                // the one that stands behind it.
                $parkId    = (int) ($row['ParkId'] ?? 0);
                $kingdomId = (int) ($row['KingdomId'] ?? 0);
                if ($kingdomId <= 0 && $parkId > 0) {
                    $kingdomId = (int) ($this->park_details($parkId)['KingdomId'] ?? 0);
                }
                $arms = $this->run_heraldry($heraldry, $parkId, $kingdomId, $player, ['park', 'kingdom']);
                $context['heraldry'] = $arms['Url'];
                $context['design']   = ['heraldrySource' => $arms['Source']];
            }

            $scrolls[$id] = Scroll::BuildScroll($row, $context);
        }

        return ['Scrolls' => $scrolls, 'Refused' => $refused];
    }

    /**
     * Which of a kingdom's kingdomawards Scrollery can print, as a set:
     * KingdomAwardId => true.
     *
     * The ONE definition of "printable" the Court Planner uses — the scroll-status
     * dropdown disables Scrollery Print for anything outside it, and
     * scrolls_for_court_awards() resolves every line against the same list. Built
     * from printable_award_choices(), so it carries that list's officer-position,
     * dangling-row and allowlist rules rather than a second opinion about them.
     *
     * Narrower than that list by exactly one rule: a choice whose certificate would
     * carry no award name (a Custom Award the kingdom never named) is left out,
     * because scrolls_for_court_awards() always skips such a line as
     * Scroll::COURT_SKIP_NO_NAME. Both decide it through Scroll::CourtChoicePrintsAName(),
     * so the dropdown can never offer Scrollery Print for a line that cannot print.
     *
     * @return array<int, true>
     */
    public function printable_kingdomaward_ids(int $kingdomId): array
    {
        $ids = [];
        foreach ($this->printable_award_choices($kingdomId) as $choice) {
            $kaId = (int) ($choice['KingdomAwardId'] ?? 0);
            if ($kaId > 0 && Scroll::CourtChoicePrintsAName($choice)) {
                $ids[$kaId] = true;
            }
        }

        return $ids;
    }

    /**
     * Stamps each Court Planner line with ScrolleryPrintable (bool), read from
     * printable_kingdomaward_ids() for the court's kingdom. The planner's scroll
     * dropdown disables Scrollery Print on a line where it is false. Every surface
     * that hands lines to the planner (page render, heartbeat, add) goes through
     * here, so they all carry the same flag.
     *
     * @param array<int|string, array<string, mixed>> $lines
     * @return array<int|string, array<string, mixed>>
     */
    public function flag_printable_court_lines(array $lines, int $kingdomId): array
    {
        $printable = $this->printable_kingdomaward_ids($kingdomId);
        foreach ($lines as $i => $line) {
            $lines[$i]['ScrolleryPrintable'] = isset($printable[(int) ($line['KingdomAwardId'] ?? 0)]);
        }

        return $lines;
    }

    /**
     * A COURT'S Scrollery Print lines as one print run.
     *
     * A court line is not an award row: until the court is finalized nothing has
     * been written to ork_awards, so there is no awards_id to print from and
     * scrolls_for_awards_ids() cannot serve it. The certificate is built from the
     * line itself — its recipient, kingdomaward and rank — with the COURT
     * supplying the date and the issuing body, exactly as the court will when it
     * commits the grant.
     *
     * NOT AN AUTHORITY CHECK. The caller has already decided the viewer may manage
     * this court (Model_Court::can_manage(), the print route's whole gate by
     * decision); $viewerId is passed to getCourtAwards() only so a Monarchy Only
     * rec's reason is withheld from a viewer who may not see it.
     *
     * Which lines take part, silently: Scroll::CourtRunLines() (lines on another
     * court cannot appear, because getCourtAwards() reads only this court).
     * $courtAwardIds === [] means every line of the court.
     *
     * Which lines are reported in Skipped, with an officer-facing reason:
     * Scroll::CourtLineSkipReason() — not in the kingdom's printable catalog, then
     * no name to print (a court run is an officer printing a new certificate, and
     * compose() refuses the same case for the same reason), then no player record.
     *
     * The certificate row and its citation: Scroll::CourtLineRow(). The giver is the line's own
     * when one is set, else the court's default giver — getCourtGiverOptions() is
     * read once per run, and only if some line needs it.
     *
     * Scrolls keeps the court's running order and is capped at
     * Scroll::MAX_PRINT_RUN; printable lines past the cap are counted in Dropped.
     *
     * Lines (an addition to the three keys the planner contract names) carries
     * Persona / AwardName / RowVersion for every line in Scrolls or Skipped, so the
     * print route can name a skipped line and the planner's preflight can hand back
     * each printable line's row version without reading the court a second time.
     *
     * $heraldry is the print page's run-level arms choice, as for scrolls_for_awards_ids():
     * 'auto' draws the court's own arms exactly as before; a source draws the court's
     * park, the court's kingdom or the line's recipient, with run_heraldry()'s fallback.
     *
     * @param array<int, mixed> $courtAwardIds
     * @return array{Scrolls: array<int, array>, Skipped: array<int, string>, Dropped: int,
     *               Lines: array<int, array{Persona: string, AwardName: string, RowVersion: int}>}
     */
    public function scrolls_for_court_awards(
        int $courtId,
        array $courtAwardIds,
        int $viewerId,
        string $heraldry = Scroll::HERALDRY_AUTO
    ): array {
        $heraldry = Scroll::NormaliseRunHeraldry($heraldry);
        $empty = ['Scrolls' => [], 'Skipped' => [], 'Dropped' => 0, 'Lines' => []];
        if ($courtId <= 0) {
            return $empty;
        }

        $court = Ork3::$Lib->court->getCourtDetail($courtId);
        if (!is_array($court)) {
            return $empty;
        }

        // getCourtAwards() reads WHERE court_id = this court, so an id from another
        // court can never be in this list — the foreign-id rule is the query itself.
        // It is already in running order (sort_order, court_award_id).
        $lines = Scroll::CourtRunLines(Ork3::$Lib->court->getCourtAwards($courtId, $viewerId), $courtAwardIds);
        if ($lines === []) {
            return $empty;
        }

        $kingdomId = (int) ($court['KingdomId'] ?? 0);
        // A Kingdom Court held at a park issues from the kingdom, so the run is blind
        // to its park: heading, arms and print log are all the kingdom's.
        $parkId    = empty($court['IsKingdomCourt']) ? (int) ($court['ParkId'] ?? 0) : 0;
        $scopeType = $parkId > 0 ? 'park' : 'kingdom';
        $scopeId   = $parkId > 0 ? $parkId : $kingdomId;
        // Only the run-level arms choice reads this: a park court's Kingdom arms are its
        // park's kingdom's if the court row carries none of its own.
        $armsKingdomId = $kingdomId > 0 ? $kingdomId
            : ($parkId > 0 ? (int) ($this->park_details($parkId)['KingdomId'] ?? 0) : 0);

        // The issuing body, named the way the composer names it: scope_context()'s
        // split Title/Name plus its article and "of" flags, and its master-size
        // heraldry. One call for the whole run — every line shares the court's body.
        $scope    = $this->scope_context($scopeType, $scopeId);
        $courtRow = [
            'Date'        => $court['CourtDate'] ?? null,
            'ParkId'      => $parkId,
            'KingdomId'   => $kingdomId,
            'ParkName'    => $scopeType === 'park' ? (string) $scope['Name'] : '',
            'KingdomName' => $scopeType === 'kingdom' ? (string) $scope['Name'] : '',
        ];

        $choices = [];
        foreach ($this->printable_award_choices($kingdomId) as $choice) {
            $choices[(int) $choice['KingdomAwardId']] = $choice;
        }

        // Pronouns in one read, as scrolls_for_awards_ids() does, rather than one
        // possessive_pronoun_for_player() round trip per certificate.
        $mundaneIds = [];
        foreach ($lines as $line) {
            $mundaneId = (int) ($line['MundaneId'] ?? 0);
            if ($mundaneId > 0) {
                $mundaneIds[$mundaneId] = true;
            }
        }
        $recipients = $mundaneIds === []
            ? []
            : $this->scroll_query()->ScrollRecipients(array_keys($mundaneIds));

        $defaultGiver = null;
        $scrolls      = [];
        $skipped      = [];
        $meta         = [];
        $dropped      = 0;

        foreach ($lines as $caId => $line) {
            $choice    = $choices[(int) ($line['KingdomAwardId'] ?? 0)] ?? null;
            $recipient = $recipients[(int) ($line['MundaneId'] ?? 0)] ?? null;
            $scroll    = null;
            $reason    = Scroll::CourtLineSkipReason($choice, $recipient);

            if ($reason === '') {
                if ((int) ($line['GivenByMundaneId'] ?? 0) > 0) {
                    $givenBy = (string) ($line['GivenByPersona'] ?? '');
                } else {
                    $defaultGiver ??= Ork3::$Lib->court->getCourtGiverOptions($courtId)['default'] ?? [];
                    $givenBy = (string) (is_array($defaultGiver) ? ($defaultGiver['persona'] ?? '') : '');
                }

                $context = [
                    'player'      => (string) ($recipient['Persona'] ?? ''),
                    'pronoun'     => $this->possessive_pronoun($recipient),
                    'heraldry'    => (string) $scope['Heraldry'],
                    'issuerTitle' => (string) $scope['Title'],
                    'useThe'      => (bool) $scope['UseThe'],
                    'useOf'       => (bool) $scope['UseOf'],
                    'mundaneId'   => (int) $line['MundaneId'],
                    'scopeType'   => $scopeType,
                    'scopeId'     => $scopeId,
                ];
                if ($heraldry !== Scroll::HERALDRY_AUTO) {
                    // Automatic stays the court's own arms above, with no design key.
                    $arms = $this->run_heraldry($heraldry, $parkId, $armsKingdomId, $recipient, [$scopeType]);
                    $context['heraldry'] = $arms['Url'];
                    $context['design']   = ['heraldrySource' => $arms['Source']];
                }

                try {
                    $scroll = Scroll::BuildScroll(
                        Scroll::CourtLineRow($line, $choice, $courtRow, $givenBy),
                        $context
                    );
                } catch (InvalidArgumentException $e) {
                    // The choice came from the same allowlist BuildScroll() resolves
                    // through, so this is not expected — but a refusal there is still
                    // "can't print", never a fatal on the officer's print tab.
                    $scroll = null;
                    $reason = Scroll::COURT_SKIP_UNPRINTABLE;
                }
            }

            $meta[$caId] = [
                'Persona'    => (string) ($line['Persona'] ?? ''),
                'AwardName'  => (string) ($line['AwardName'] ?? ''),
                'RowVersion' => (int) ($line['RowVersion'] ?? 0),
            ];

            if ($scroll === null) {
                $skipped[$caId] = $reason;
            } elseif (count($scrolls) < Scroll::MAX_PRINT_RUN) {
                $scrolls[$caId] = $scroll;
            } else {
                $dropped++;
                unset($meta[$caId]);
            }
        }

        return ['Scrolls' => $scrolls, 'Skipped' => $skipped, 'Dropped' => $dropped, 'Lines' => $meta];
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
                'Message'  => Scroll::DenialMessage($reason),
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
        // awards_for_ids(), underscore and all: the name keeps it off the anonymous
        // JSON service (see the method's docblock).
        $response = Ork3::$Lib->player->awards_for_ids($ids);

        $rows = [];
        foreach (($response['Awards'] ?? []) as $row) {
            $rows[(int) $row['AwardsId']] = Award::correct_dangling_officer_role($row);
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
            $row = Award::correct_dangling_officer_role($row);
            // Revoked filtering happens HERE rather than in AwardsForPlayer(), whose
            // default behaviour four other callers depend on. Revocation normally
            // nulls mundane_id, so most revoked rows never reach this list at all —
            // but 59 still carry a live mundane_id and would otherwise let the
            // recipient reprint an award that was stripped from them.
            if (!Scroll::IsPrintableRow($row)) {
                continue;
            }

            $row['Title']       = Scroll::ResolveTitle($row);
            $row['IssuerTitle'] = $this->issuer_title($row);
            // The body the certificate names, as Scroll::BuildScroll() decides it.
            $row['IssuerName']  = trim((string) ($row['ParkName'] ?? '')) !== ''
                && (trim((string) ($row['KingdomName'] ?? '')) === '' || Scroll::IssuerScopeForRow($row) !== 'kingdom')
                ? (string) $row['ParkName']
                : (string) ($row['KingdomName'] ?? '');
            $row['PrettyDate']  = Scroll::FormatDate((string) ($row['Date'] ?? ''));
            // Order, plate variants and order-art-vs-composed, so the reprint list
            // can show the design and reach every variant via &variant=.
            $row                = Scroll::ReprintDesign($row) + $row;
            $printable[]        = $row;
        }

        return $printable;
    }

    /**
     * The scopes this viewer may actually issue a certificate for — the officer
     * doors on the landing page.
     *
     * Two halves, and both matter. Candidates come from the viewer's own
     * AUTHORIZATION rows (ScrollQuery::IssuableScopes()), not from their home
     * park/kingdom: an officer can hold a kingdom office while living in another
     * kingdom's park — mundane 54527 holds kingdom-4 authority from home park 32 in
     * kingdom 3 — and a home-derived lookup hands them no doors at all. Each
     * candidate is then confirmed through the SAME HasAuthority() call the print gate
     * uses, so the door list is provably a subset of what the gate will allow rather
     * than a second opinion that can drift open.
     *
     * HasAuthority() returns NULL on denial, not false, so it is cast.
     *
     * NOT CACHED, for the same reason the print gate is not: an authority
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

        $scopes = [];

        foreach ($this->scroll_query()->IssuableScopes($mundaneId) as $scope) {
            if ($this->may_issue_for($mundaneId, $scope['Type'] === 'kingdom' ? 'kingdom' : 'park', (int) $scope['Id'])) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * May this viewer issue scrolls for this park or kingdom?
     *
     * The one copy of the issue gate: AUTH_EDIT over the issuing scope, the same gate
     * Controller_Award uses to grant an award in the first place. The landing-page
     * doors, create, bulk, compose, the live preview and the Admin "Print scrolls"
     * link all ask here, so they cannot drift apart.
     *
     * NOT CACHED: an authority decision keyed on anything less than the viewer is a
     * cross-tenant disclosure.
     */
    public function may_issue_for(int $viewerId, string $scopeType, int $scopeId): bool
    {
        if ($viewerId <= 0 || $scopeId <= 0) {
            return false;
        }

        // (bool) is load-bearing: HasAuthority() returns NULL on denial, not false,
        // so returning it straight through a : bool signature is a TypeError.
        return (bool) Ork3::$Lib->authorization->HasAuthority(
            $viewerId,
            $scopeType === 'kingdom' ? AUTH_KINGDOM : AUTH_PARK,
            $scopeId,
            AUTH_EDIT
        );
    }

    /**
     * One print-log entry for a built scroll. $extra carries AwardsId, CourtId,
     * CourtAwardId, AuthBasis and/or a MundaneId override.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function print_log_entry(array $scroll, int $viewerId, array $extra = []): array
    {
        return $extra + [
            'MundaneId'   => (int) ($scroll['mundaneId'] ?? 0),
            'PrintedById' => $viewerId,
            'ScopeType'   => $scroll['scopeType'] ?? '',
            'ScopeId'     => (int) ($scroll['scopeId'] ?? 0),
            'OrderId'     => (string) ($scroll['order'] ?? ''),
            'Rank'        => (int) ($scroll['rank'] ?? 0),
            'Variant'     => (string) ($scroll['variant'] ?? ''),
            // The drawn design rides in the same JSON, beside the custom recipe keys, and
            // only when it is not the default (see Scroll::PrintRecipe()).
            'Recipe'      => Scroll::PrintRecipe($scroll),
        ];
    }

    /** How many of the entries were written (RecordPrints() dedupes and never throws). */
    private function record_prints(array $entries): int
    {
        return count(array_filter(Ork3::$Lib->scrollprintlog->RecordPrints($entries)));
    }

    /**
     * Log an award-backed print run (Scrollery/render) that was actually sent to the
     * printer. RE-RUNS THE GATE: scrolls_for_awards_ids() decides every id against
     * this viewer exactly as the page did, so only what the viewer may print is
     * logged, whatever the beacon claimed. Returns how many rows were written.
     *
     * @param array<int, mixed> $awardsIds
     */
    public function log_award_prints(array $awardsIds, int $viewerId, string $variant, string $heraldry): int
    {
        $awardsIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $awardsIds)))), 0, Scroll::MAX_PRINT_RUN);
        if ($awardsIds === [] || $viewerId <= 0) {
            return 0;
        }

        $run = $this->scrolls_for_awards_ids($awardsIds, $viewerId, $variant, $heraldry);
        if ($run['Scrolls'] === []) {
            return 0;
        }

        // Which rung let each one through. One batched row read, and one decision per
        // distinct (recipient?, park, kingdom) shape, as the gate itself memoises.
        $rows    = $this->award_rows_by_id(array_keys($run['Scrolls']));
        $isAdmin = Scroll::IsPrintAdmin($viewerId);
        $memo    = [];
        $entries = [];
        foreach ($run['Scrolls'] as $awardsId => $scroll) {
            $row   = $rows[$awardsId] ?? [];
            $shape = ((int) ($row['MundaneId'] ?? 0) === $viewerId ? 1 : 0)
                . ':' . (int) ($row['ParkId'] ?? 0) . ':' . (int) ($row['KingdomId'] ?? 0);
            $memo[$shape] ??= Scroll::PrintAuthBasis($row, $viewerId, $isAdmin);

            $entries[] = $this->print_log_entry($scroll, $viewerId, [
                'AwardsId'  => (int) $awardsId,
                'AuthBasis' => $memo[$shape],
            ]);
        }

        return $this->record_prints($entries);
    }

    /**
     * Log a court run (Scrollery/court). The CALLER has already run
     * Model_Court::can_manage() — the court route's whole gate; this re-reads the
     * court so only its own, still-Scrollery-Print lines are logged. A finalized
     * line is logged against its award row (see ScrollPrintLog::RecordPrints()).
     *
     * @param array<int, mixed> $courtAwardIds
     */
    public function log_court_prints(int $courtId, array $courtAwardIds, int $viewerId, string $heraldry): int
    {
        if ($courtId <= 0 || $viewerId <= 0) {
            return 0;
        }

        $run     = $this->scrolls_for_court_awards($courtId, $courtAwardIds, $viewerId, $heraldry);
        $entries = [];
        foreach ($run['Scrolls'] as $caId => $scroll) {
            $entries[] = $this->print_log_entry($scroll, $viewerId, [
                'CourtId'      => $courtId,
                'CourtAwardId' => (int) $caId,
                'AuthBasis'    => 'court-manager',
            ]);
        }

        return $entries === [] ? 0 : $this->record_prints($entries);
    }

    /**
     * Log a composed certificate (Scrollery/compose). The CALLER has already run
     * may_issue_for(); the composition is re-run so a refused one logs nothing.
     *
     * The playerId parameter is client-supplied, so it is only recorded when it
     * names a real player; otherwise the row's mundane_id is 0.
     *
     * @param array<string, mixed> $params the compose page's query parameters
     */
    public function log_compose_print(string $scopeType, int $scopeId, array $params, int $viewerId): int
    {
        if ($viewerId <= 0) {
            return 0;
        }

        $composed = $this->compose_scroll($scopeType, $scopeId, $params, true);
        if ($composed['Scroll'] === null) {
            return 0;
        }

        $playerId = (int) ($params['playerId'] ?? 0);
        if ($playerId > 0 && $this->scroll_query()->ScrollRecipients([$playerId]) === []) {
            $playerId = 0;
        }

        return $this->record_prints([$this->print_log_entry($composed['Scroll'], $viewerId, [
            'MundaneId' => max(0, $playerId),
            'AuthBasis' => 'compose',
        ])]);
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
            // Double-encoded names are repaired here, as BuildScroll() repairs them, so
            // the composer's boxes open on the text that will print. The database is
            // never touched: see ScrollText::RepairMojibake().
            $kingdomName = ScrollText::RepairMojibake((string) ($info['KingdomName'] ?? ''));
            $issuer = ScrollText::IssuerLines('', $kingdomName, false, false, 'kingdom');

            return [
                'Type'      => 'kingdom',
                'Id'        => $scopeId,
                'Name'      => $issuer['name'],
                'Title'     => $issuer['title'],
                'Display'   => trim($kingdomName),
                'UseThe'    => $issuer['useThe'],
                'UseOf'     => $issuer['useOf'],
                'KingdomId' => $scopeId,
                'Heraldry'  => (int) ($info['HasHeraldry'] ?? 0) === 1
                    ? $this->scroll_heraldry_url('Kingdom', $scopeId)
                    : '',
            ];
        }

        $park = $this->park_details($scopeId);

        // ork_parktitle.title is "Inactive" on 5 parks — a status, not a rank word. The
        // text model reads it as no title, which then takes the same Barony floor an
        // untitled park always did.
        $parkTitle = ScrollText::IssuerLines(ScrollText::RepairMojibake((string) ($park['ParkTitle'] ?? '')), '', true, true, 'park')['title'];
        $parkTitle = $parkTitle !== '' ? $parkTitle : 'Barony';
        $parkName  = ScrollText::RepairMojibake((string) ($park['ParkName'] ?? ''));

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
                ? $this->scroll_heraldry_url('Park', $scopeId)
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

        return Scroll::CatalogChoices($response['Awards'] ?? []);
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
     * list the create dropdown already filtered through Scroll::CatalogChoices()
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
     * The search itself — the part with anything to get wrong — is
     * Scroll::MatchCatalogChoice(), exercised in ScrolleryAwardOrderForComposeTest
     * against a hand-built choice list.
     *
     * @return array{KingdomAwardId:int, AwardId:int, Name:string, Order:string, MinRank:int, MaxRank:int, Variants:array}|null
     */
    public function award_order_for_compose(int $kingdomId, int $awardId, string $title): ?array
    {
        if ($kingdomId <= 0) {
            return null;
        }

        return Scroll::MatchCatalogChoice($this->printable_award_choices($kingdomId), $awardId, $title);
    }

    /** compose_scroll()'s refusals. '' is success. */
    public const COMPOSE_NO_AWARD = 'no_award';
    public const COMPOSE_BLANK_TITLE = 'blank_title';

    /**
     * A hand-composed scroll — the create page's preview and the compose() print
     * surface — built from the composer's own request parameters. ONE copy of the
     * composition rules, so the preview and the print it previews cannot drift and a
     * JSON client would not have to re-derive them.
     *
     * $params carries the composer's parameter names (awardId, title, playerId,
     * pronounOverride, heraldrySource, rank, date, reason, givenBy, issuerName,
     * player, issuerTitle, useThe, useOf, customName, titlePhrase, variant, the
     * design keys and the recipe keys); only those keys are read. The caller has
     * already run may_issue_for() against $scopeType / $scopeId.
     *
     * $refuseBlankTitle is set by compose() and not by the preview: an unnamed custom
     * entry resolves to a BLANK award line, and the preview showing that blank is how
     * the officer learns the field is not optional, whereas printing it is a slip.
     *
     * @return array{Scroll:?array, Error:string, HeraldrySources:array<string,bool>, HeraldrySource:string}
     */
    public function compose_scroll(string $scopeType, int $scopeId, array $params, bool $refuseBlankTitle): array
    {
        $scopeType = $scopeType === 'kingdom' ? 'kingdom' : 'park';
        $fail      = static fn (string $error): array => [
            'Scroll'          => null,
            'Error'           => $error,
            'HeraldrySources' => [],
            'HeraldrySource'  => '',
        ];
        $str = static fn (string $key): string => (string) ($params[$key] ?? '');

        $scope = $this->scope_context($scopeType, $scopeId);
        // award_order_for_compose(), not Scroll::OrderForAwardId(): custom's own
        // award_id is the 0 sentinel OrderForAwardId() always returns null for, so a
        // custom-eligible choice from the create dropdown could never pass this
        // gate. Re-resolving against the kingdom's own catalog (rather than trusting
        // a client-supplied award id + title with no OfficerRole behind it) is what
        // still keeps an officer position from composing a certificate.
        //
        // The returned CHOICE, not just a pass/fail, is what BuildScroll() gets its
        // AwardId/KingdomAwardName from below — never the client's own strings. That
        // fixes a cosmetic drift (matching is case/whitespace-insensitive, so
        // "  sheriff  " would otherwise print with the submitted casing rather than
        // the catalogue's) and guarantees BuildScroll() sees a real OfficerRole
        // rather than an absent key, which — since Scroll::IsOfficerPosition() now
        // fails closed on a missing key — would otherwise throw.
        $choice = $this->award_order_for_compose(
            (int) ($scope['KingdomId'] ?? 0),
            (int) ($params['awardId'] ?? 0),
            $str('title')
        );
        if ($choice === null) {
            return $fail(self::COMPOSE_NO_AWARD);
        }

        $playerId = (int) ($params['playerId'] ?? 0);

        // The officer's correction wins over anything inferred. Per-print only —
        // nothing is written back to the profile. The control is free text, not
        // a closed vocabulary, so it goes through the same subject/object ->
        // possessive resolution a stored pronoun_freetext value gets — "he/him"
        // typed into the box is exactly as reachable as it is from a profile.
        // Then the recipient's own possessive (so a preview matches what a reprint
        // would say), otherwise 'their'.
        $override = trim($str('pronounOverride'));
        $pronoun  = $override !== '' ? $this->resolve_pronoun_override($override)
            : $this->possessive_pronoun_for_player($playerId);

        $sources        = $this->heraldry_sources($scopeType, $scopeId, $playerId);
        $available      = array_map(static fn (string $u): bool => $u !== '', $sources);
        $heraldrySource = Scroll::ResolveHeraldrySource($str('heraldrySource'), $scopeType, $available);

        $scroll = Scroll::BuildScroll(
            [
                'AwardId'          => $choice['AwardId'],
                'OfficerRole'      => 'none',
                'Rank'             => (int) ($params['rank'] ?? 0),
                'Date'             => $str('date'),
                'Note'             => $str('reason'),
                'GivenBy'          => $str('givenBy'),
                'KingdomAwardName' => $choice['Name'],
                'ParkName'         => $scopeType === 'park' ? $str('issuerName') : '',
                'KingdomName'      => $scopeType === 'kingdom' ? $str('issuerName') : '',
            ],
            [
                'player'      => $str('player'),
                'pronoun'     => $pronoun !== '' ? $pronoun : 'their',
                'heraldry'    => $sources[$heraldrySource],
                // Every source's arms, for a layout that draws more than one (EXPERIMENT).
                'heraldryArms' => $sources,
                // Provenance for the print log. A composed scroll has no award row, so
                // these three can only come from context — and a composed row carries no
                // awards_id either, which makes mundaneId its ONLY link to a person.
                'mundaneId'   => $playerId,
                'scopeType'   => $scopeType,
                'scopeId'     => $scopeId,
                // The printed name for a Custom Award / Custom Title, whose catalogue
                // name is the slot rather than the award. Passed through RAW for the
                // same reason the recipe below is: BuildScroll() is the one place that
                // decides whether this award may be renamed at all, and it caps and
                // sanitises the string there.
                'customName'  => $str('customName'),
                // A rank on the composer is the officer's own choice (0 = none picked),
                // so even a rank of 1 prints "with their first" on a Custom Award — which
                // a stored rank-1 grant does not. See Scroll::CustomIsRanked().
                // rankChosen: the composer sets it only when the officer picked the
                // rank by hand. A non-zero rank alone may be the held-rank prefill, which
                // for Custom Award (94) comes from unrelated custom grants.
                'rankExplicit' => (int) ($params['rank'] ?? 0) > 0 && !empty($params['rankChosen']),
                // The "With phrase" box. Passed with array_key_exists semantics all the
                // way down: an ABSENT parameter takes the per-entry default, a PRESENT
                // but empty one means "print no phrase". isset() would collapse them.
            ] + (array_key_exists('titlePhrase', $params)
                ? ['titlePhrase' => (string) $params['titlePhrase']]
                : []) + [
                'issuerTitle' => $str('issuerTitle'),
                'useThe'      => $str('useThe') === '1',
                'useOf'       => $str('useOf') === '1',
                'variant'     => preg_replace('/[^a-z]/', '', strtolower($str('variant'))),
                // Passed raw like the recipe: Scroll::NormaliseDesign() inside BuildScroll()
                // is the one allowlist. Only the heraldry source is resolved here, because
                // it needs the arms lookup that BuildScroll() must stay free of.
                'design'      => [
                    'heraldrySource' => $heraldrySource,
                    'heraldryLayout' => $str('heraldryLayout'),
                    'heraldryStamp'  => $str('heraldryStamp'),
                    'drawIn'         => $str('drawIn'),
                    'customBorder'   => $str('customBorder'),
                    'color1'         => $str('color1'),
                    'color2'         => $str('color2'),
                    'matchStyle'     => $str('matchStyle'),
                    'customRibbon'   => $str('customRibbon'),
                    'ribbonColor'    => $str('ribbonColor'),
                ],
                // Passed through raw on purpose: Scroll::NormaliseRecipe() is the one
                // allowlist for these, and it runs inside BuildScroll().
                'recipe'      => [
                    'ground'    => $str('ground'),
                    'border'    => $str('border'),
                    'primary'   => $str('primary'),
                    'secondary' => $str('secondary'),
                    'rankSet'   => $str('rankSet'),
                ],
            ]
        );

        /*
         * A HAND-COMPOSED scroll must not print with no award name on it.
         *
         * Scroll::ResolveTitle() answers '' for a Custom Award / Custom Title row that
         * nobody has named, and a blank award line is the deliberate, correct answer
         * when REPRINTING a historical grant whose name the ORK does not know. It is
         * not the right answer for an officer at the composer, which has a box to type
         * the name into. render() — the reprint surface — never asks for this.
         *
         * Tested on the RESOLVED title, so it holds however BuildScroll() applies
         * customName, including when Scroll::SanitiseCustomName() rejects it to ''.
         */
        if ($refuseBlankTitle && trim((string) $scroll['title']) === '') {
            return $fail(self::COMPOSE_BLANK_TITLE);
        }

        return [
            'Scroll'          => $scroll,
            'Error'           => '',
            'HeraldrySources' => $available,
            'HeraldrySource'  => $heraldrySource,
        ];
    }

    /** One scroll's certificate markup — see Scroll::Render(). */
    public function render_scroll(array $scroll, string $artSize = Scroll::ART_MASTER): string
    {
        return Scroll::Render($scroll, $artSize);
    }

    /** A run-level &heraldry= choice, normalised — see Scroll::NormaliseRunHeraldry(). */
    public function normalise_run_heraldry(?string $raw): string
    {
        return Scroll::NormaliseRunHeraldry($raw);
    }

    /**
     * The per-print page options a render request carries — &face=, &sig=, &lines= and
     * &heading= — each through its Scroll allowlist, plus the faces to preload for them.
     *
     * @param array<string, mixed> $query the request's query parameters
     * @return array{Face:string, Sig:string, Lines:bool, Heading:string, PreloadFonts:array}
     */
    public function print_options(array $query): array
    {
        $face = Scroll::NormaliseHand((string) ($query['face'] ?? ''));
        $sig  = Scroll::NormaliseSignatureHand(isset($query['sig']) ? (string) $query['sig'] : null);

        return [
            'Face'         => $face,
            'Sig'          => $sig,
            'Lines'        => Scroll::NormaliseShowLines($query['lines'] ?? null),
            'Heading'      => Scroll::NormaliseHeading(isset($query['heading']) ? (string) $query['heading'] : null),
            'PreloadFonts' => array_merge(Scroll::HandFonts($face), Scroll::SignatureHandFonts($sig)),
        ];
    }

    /**
     * How a run-level &heraldry= choice landed on a run: how many certificates had no
     * arms from the chosen source and fell back (their design.heraldrySource, the source
     * actually drawn, differs), and how many have no arms at all.
     *
     * @param array<int, array> $scrolls
     * @return array{Fell:int, None:int, Total:int}
     */
    public function heraldry_run_note(array $scrolls, string $heraldry): array
    {
        $fell = 0;
        $none = 0;
        foreach ($scrolls as $scroll) {
            if ((string) ($scroll['heraldry'] ?? '') === '') {
                $none++;
            } elseif ((string) ($scroll['design']['heraldrySource'] ?? '') !== $heraldry) {
                $fell++;
            }
        }

        return ['Fell' => $fell, 'None' => $none, 'Total' => count($scrolls)];
    }

    /**
     * A print token's verdict for the ids a request carries — see Scroll::VerifyPrintToken().
     *
     * @param array<int, int> $awardsIds
     * @return array{Valid:bool, ViewerId:int, Reason:string}
     */
    public function verify_print_token(string $token, array $awardsIds): array
    {
        return Scroll::VerifyPrintToken($token, $awardsIds);
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

        // A park's scope is the park's own kingdom, not at_kingdom_id (often but not
        // always set on a park grant) — see Award::kingdom_for_grant_row()'s doc for the same
        // split. Resolved BEFORE the query now, because the officer-name set is part
        // of the WHERE rather than a correction applied afterwards.
        $kingdomId    = $scopeType === 'kingdom' ? $scopeId : (int) ($this->park_details($scopeId)['KingdomId'] ?? 0);
        $officerNames = Award::officer_names_for_kingdom($kingdomId);

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
            $shaped = ScrollQuery::ShapeBulkRow($row);

            // Same dangling-award_id shape Scroll::CatalogChoices() closes for
            // the create dropdown: this query's LEFT JOIN to `award` misses for
            // archery's real grants (base award_id 0/94/131, no matching ork_award
            // row) — that must stay open — but also for an officer position whose
            // base award has simply gone missing, which must not. Told apart by
            // Award::is_dangling_row_an_officer() against the granting kingdom's
            // own catalog.
            //
            // The WHERE now applies the same rule via $officerNames, so this is a
            // belt-and-braces second pass rather than the only gate — kept because it
            // is the shared, tested implementation of the rule and because passing an
            // empty name set (a kingdom whose catalog could not be read) must still
            // fail closed.
            if ($shaped['Dangling']
                && Award::is_dangling_row_an_officer($shaped['KingdomAwardName'], $officerNames)
            ) {
                $shaped['OfficerRole'] = 'other-officer';
            }
            unset($shaped['Dangling']);

            $shaped['Title']      = Scroll::ResolveTitle($shaped);
            $shaped['PrettyDate'] = Scroll::FormatDate($shaped['Date']);

            if (Scroll::IsPrintableRow($shaped)) {
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
            // From the LAST ROW OF THE PAGE — see ScrollQuery::NextBulkCursor().
            'NextCursor' => $hasMore && is_array($last) ? ScrollQuery::NextBulkCursor($last) : '',
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
     * Whether one award row may be printed by one viewer, AND WHY NOT, through a
     * memo the CALLER owns. The rule (admin, recipient, unattributed, park, kingdom,
     * then revoked) is the domain's: see Scroll::PrintPermission().
     *
     * $memo must be a local of ONE public call for ONE viewer, with $isAdmin asked
     * once for that viewer (Scroll::IsPrintAdmin()). It is keyed by every input
     * PrintPermission() reads besides the admin flag — Revoked, is-recipient,
     * ParkId, KingdomId — so two rows share a key only when they would compute the
     * same answer. A bulk run of 200 rows is a handful of distinct keys, so this
     * turns ~4 HasAuthority() statements per row into ~4 per distinct key.
     *
     * The no-caching rule is about decisions outliving the (viewer, award) pair they
     * were made for: never store $memo on $this, never share it across viewers.
     *
     * @param array<string, array{Allowed: bool, Reason: string, Message: string}> $memo
     * @return array{Allowed: bool, Reason: string, Message: string}
     */
    private function memoised_print_permission(array $row, int $viewerId, bool $isAdmin, array &$memo): array
    {
        $key = (int) ($row['Revoked'] ?? 0)
            . ':' . ((int) ($row['MundaneId'] ?? 0) === $viewerId ? 1 : 0)
            . ':' . (int) ($row['ParkId'] ?? 0)
            . ':' . (int) ($row['KingdomId'] ?? 0);

        return $memo[$key] ??= Scroll::PrintPermission($row, $viewerId, $isAdmin);
    }

    /**
     * Narrows each AwardsForPlayer() row's CanPrintScroll to "AND this viewer may
     * print it", so the player profile only offers View/Print where the render route
     * will actually build the certificate.
     *
     * Model_Player::fetch_player_details() stamps CanPrintScroll from the row alone
     * (not revoked, has art). The profile showed it to anyone holding park CREATE at
     * the RECIPIENT'S home park — but Scroll::PrintPermission() keys on where the award was
     * GIVEN (at_park_id / at_kingdom_id) and refuses unattributed rows outright, so
     * an officer saw Print on awards given elsewhere and the render tab refused.
     *
     * A PER-CALL memo via memoised_print_permission(), shared with
     * scrolls_for_awards_ids(). A profile carries up to ~280 rows and every
     * HasAuthority() is a query, but a handful of distinct keys. Admin is constant
     * for the call and asked once.
     *
     * @param array<int|string, mixed> $rows AwardsForPlayer()-shaped rows carrying CanPrintScroll
     * @return array<int|string, mixed> the same rows, CanPrintScroll narrowed
     */
    public function flag_viewer_printable_awards(array $rows, int $viewerId): array
    {
        $isAdmin = Scroll::IsPrintAdmin($viewerId);
        $memo    = [];

        foreach ($rows as $i => $row) {
            if (!is_array($row) || empty($row['CanPrintScroll'])) {
                continue;
            }

            if ($viewerId <= 0) {
                $rows[$i]['CanPrintScroll'] = false;
                continue;
            }

            $rows[$i]['CanPrintScroll'] = $this->memoised_print_permission($row, $viewerId, $isAdmin, $memo)['Allowed'];
        }

        return $rows;
    }

    /**
     * The public form of the print gate, by award id — what a controller should
     * call when it needs to TELL the officer why nothing printed.
     *
     * Runs the whole gate in the same order scrolls_for_awards_ids() does, so the
     * two can never disagree about a given id: a missing id is DENIED_AUTH unless
     * the viewer is a print admin, and revoked/excluded are only ever reported to
     * a viewer entitled to the row.
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
            return self::denial(Scroll::IsPrintAdmin($viewerId) ? self::DENIED_MISSING : self::DENIED_AUTH);
        }

        // Permission first, art last — see Scroll::PrintDecision().
        return Scroll::PrintDecision($row, $viewerId);
    }

    /** @return array{Allowed: bool, Reason: string, Message: string} */
    private static function denial(string $reason): array
    {
        return Scroll::PrintDenial($reason);
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
                return Award::correct_dangling_officer_role($row);
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
     * scroll_heraldry_url() asks for it, and keys a black square out (B6).
     */
    private function heraldry_url(array $row): string
    {
        // The arms follow the body the certificate NAMES (Scroll::IssuerScopeForRow()),
        // so a kingdom honour given at a park court carries the kingdom's arms, never
        // the venue's. A park issuer with no arms still falls back to its kingdom's; a
        // kingdom issuer never borrows the venue park's.
        $parkId = Scroll::IssuerScopeForRow($row) === 'park' ? (int) ($row['ParkId'] ?? 0) : 0;
        if ($parkId > 0) {
            $park = $this->park_details($parkId);
            if ((int) ($park['HasHeraldry'] ?? 0) === 1) {
                return $this->scroll_heraldry_url('Park', $parkId);
            }
        }

        $kingdomId = (int) ($row['KingdomId'] ?? 0);
        if ($kingdomId > 0) {
            if ((int) ($this->kingdom_short_info($kingdomId)['HasHeraldry'] ?? 0) === 1) {
                return $this->scroll_heraldry_url('Kingdom', $kingdomId);
            }
        }

        return '';
    }

    /**
     * One certificate's arms under the print page's run-level heraldry choice.
     *
     * Availability is decided from what the run already holds — the memoised park and
     * kingdom lookups, and the recipient row ScrollQuery::ScrollRecipients() fetched for the whole
     * run (it carries HasHeraldry for exactly this) — so a 200-certificate run adds no
     * per-certificate query. Scroll::ResolveRunHeraldrySource() then picks: the chosen
     * source, else park -> kingdom -> persona without it, else none. Only the winner's
     * URL is built.
     *
     * Source is recorded on the scroll's design so the print log says which arms were
     * drawn ('' when none could be).
     *
     * @param array<string, mixed> $recipient a ScrollQuery::ScrollRecipients() row, or []
     * @param string[] $autoOrder the surface's own legacy order, for 'auto'
     * @return array{Source: string, Url: string}
     */
    private function run_heraldry(string $choice, int $parkId, int $kingdomId, array $recipient, array $autoOrder): array
    {
        $mundaneId = (int) ($recipient['MundaneId'] ?? 0);
        $available = [
            'park'    => $parkId > 0 && (int) ($this->park_details($parkId)['HasHeraldry'] ?? 0) === 1,
            'kingdom' => $kingdomId > 0 && (int) ($this->kingdom_short_info($kingdomId)['HasHeraldry'] ?? 0) === 1,
            'persona' => $mundaneId > 0 && (int) ($recipient['HasHeraldry'] ?? 0) === 1,
        ];

        $source = Scroll::ResolveRunHeraldrySource($choice, $available, $autoOrder);
        $ids    = ['park' => ['Park', $parkId], 'kingdom' => ['Kingdom', $kingdomId], 'persona' => ['Player', $mundaneId]];

        return [
            'Source' => $source,
            'Url'    => $source === '' ? '' : $this->master_heraldry_url($ids[$source][0], $ids[$source][1]),
        ];
    }

    /**
     * A run's chosen source's arms: scroll_heraldry_url(), which holds each source for
     * the request.
     */
    private function master_heraldry_url(string $type, int $id): string
    {
        return $this->scroll_heraldry_url($type, $id);
    }

    /**
     * Heraldry as a scroll draws it: the master (see heraldry_url() on why 'Size' =>
     * 'master' is asked for), with an opaque black square keyed out when the image is
     * plainly a device on a black ground (B6; ScrollHeraldry has the rules and the
     * cache). Every scroll-render path comes through here — court and reprint Automatic,
     * a run's chosen source, and the composer — so the preview matches the print. Profile
     * and every other heraldry surface keep calling GetHeraldryUrl() directly.
     *
     * Held per request and per source: reprint Automatic asks heraldry_url() once per
     * row and a court or reprint run asks master_heraldry_url() once per certificate, so
     * a 200-certificate run of one kingdom's arms stats and hashes the file once, not 200
     * times. The cached value is the scroll rendition, never the bare master, and the key
     * names that rendition so nothing else can share the slot.
     *
     * The memo lives for the process. Under FPM that is one request; a long-lived CLI
     * or a test that re-uploads arms calls reset_scroll_heraldry_urls() between runs.
     */
    private function scroll_heraldry_url(string $type, int $id): string
    {
        $key = 'scroll:' . $type . ':' . $id;
        if (!array_key_exists($key, self::$scrollHeraldryUrls)) {
            self::$scrollHeraldryUrls[$key] = ScrollHeraldry::ForUrl((string) Ork3::$Lib->heraldry->GetHeraldryUrl(
                ['Type' => $type, 'Id' => $id, 'Size' => 'master']
            )['Url']);
        }

        return self::$scrollHeraldryUrls[$key];
    }

    /** @var array<string, string> scroll_heraldry_url()'s per-process memo. */
    private static array $scrollHeraldryUrls = [];

    /** Forget every memoised scroll heraldry URL (long-lived CLI, tests). */
    public static function reset_scroll_heraldry_urls(): void
    {
        self::$scrollHeraldryUrls = [];
    }

    /**
     * Scroll heraldry URLs (scroll_heraldry_url()) for each source the Customize Design
     * card can pick, '' where there are none. Kingdom follows the park's own kingdom; park
     * is '' on a kingdom scope unless a recipient is given, in which case it is the
     * recipient's home park's arms; persona is the recipient's own arms and needs a real
     * player.
     *
     * @return array{kingdom:string,park:string,persona:string}
     */
    public function heraldry_sources(string $scopeType, int $scopeId, int $playerId): array
    {
        $out = ['kingdom' => '', 'park' => '', 'persona' => ''];

        $kingdomId = $scopeId;
        if ($scopeType === 'park') {
            $kingdomId = (int) ($this->park_details($scopeId)['KingdomId'] ?? 0);
        }
        $recipientParkId = $playerId > 0 ? (int) ($this->scroll_recipient($playerId)['ParkId'] ?? 0) : 0;
        $parkId          = Scroll::HeraldryParkId($scopeType, $scopeId, $recipientParkId);
        if ($parkId > 0 && (int) ($this->park_details($parkId)['HasHeraldry'] ?? 0) === 1) {
            $out['park'] = $this->scroll_heraldry_url('Park', $parkId);
        }
        if ($kingdomId > 0 && (int) ($this->kingdom_short_info($kingdomId)['HasHeraldry'] ?? 0) === 1) {
            $out['kingdom'] = $this->scroll_heraldry_url('Kingdom', $kingdomId);
        }
        if ($playerId > 0) {
            if ((int) ($this->scroll_recipient($playerId)['HasHeraldry'] ?? 0) === 1) {
                $out['persona'] = $this->scroll_heraldry_url('Player', $playerId);
            }
        }

        return $out;
    }

    /**
     * The issuing body's rank word — "and the Freehold of ...".
     *
     * ORK stores this per park (ork_park.parktitle_id -> ork_parktitle.title,
     * defined per kingdom). Most parks are not baronies: 349 are Freeholds and 274
     * Grand Duchies, so the Barony/Kingdom fallback in Scroll::BuildScroll() would
     * be wrong far more often than right. Returning '' lets that fallback stand.
     *
     * '' too when the certificate names the kingdom (Scroll::IssuerScopeForRow()):
     * the venue park's rank word must not prefix a kingdom's name.
     */
    private function issuer_title(array $row): string
    {
        $parkId = (int) ($row['ParkId'] ?? 0);
        if ($parkId <= 0 || Scroll::IssuerScopeForRow($row) !== 'park') {
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

    /**
     * GetScrollRecipient() held per request: a preview needs the pronoun and the
     * arms flag from the same single-row find.
     *
     * @return array<string, mixed>
     */
    private function scroll_recipient(int $mundaneId): array
    {
        static $cache = [];
        if (!array_key_exists($mundaneId, $cache)) {
            $cache[$mundaneId] = Ork3::$Lib->player->GetScrollRecipient($mundaneId);
        }

        return $cache[$mundaneId];
    }

    /** The recipient's possessive pronoun, by player id. Never blank. */
    public function possessive_pronoun_for_player(int $mundaneId): string
    {
        if ($mundaneId <= 0) {
            return '';
        }

        return $this->possessive_pronoun($this->scroll_recipient($mundaneId));
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
        return Scroll::PossessiveSuggestions($this->pronoun_rows());
    }

    /**
     * ork_pronoun, read once per request.
     *
     * Twelve rows, and every certificate in a print run needs them — the render
     * loop used to re-run GetPronounList() once per recipient (twice, for a
     * recipient with custom pronouns), which is 200-400 statements a run for a
     * table that fits in a cache line.
     *
     * The WHOLE list, never a filtered one: the per-id lookup used to pass an
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
     * The POSSESSIVE pronoun — the text layer reads "with <her> third". Never
     * blank. The precedence rule is Scroll::PossessiveForRecipient(); this only
     * supplies the per-request ork_pronoun cache.
     */
    private function possessive_pronoun(array $player): string
    {
        return Scroll::PossessiveForRecipient($player, $this->pronoun_rows());
    }

    /**
     * Whether a freshly-granted award row can actually be printed BY THIS VIEWER —
     * used by the award-grant success path to decide whether to offer a "Print this
     * certificate" link.
     *
     * It used to skip viewer authority on the reasoning that the officer had just
     * granted it. But granting needs park CREATE at the recipient's home park, while
     * printing keys on the park/kingdom the award was given AT, which the officer
     * picks in the modal and need not hold — so the link could open a refusal. It
     * now runs Scroll::PrintPermission() too, the same gate the render route applies.
     */
    public function award_is_printable(int $awardsId, int $viewerId): bool
    {
        if ($awardsId <= 0 || $viewerId <= 0) {
            return false;
        }
        $row = $this->award_row($awardsId);

        return $row !== null && Scroll::PrintDecision($row, $viewerId)['Allowed'];
    }
}
