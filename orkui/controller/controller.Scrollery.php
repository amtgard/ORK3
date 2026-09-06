<?php

/**
 * Scrollery — award certificates printed from ORK award records.
 *
 * Artwork and layer designs originate in Arcanyst by Valory Fox — see NOTICE.
 */
class Controller_Scrollery extends Controller
{
    /** The landing page: the three doors, gated on what the viewer can actually do. */
    public function index($action = null)
    {
        $viewerId = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        if ($viewerId <= 0) {
            header('Location: ' . UIR . 'Login/login/Scrollery');
            exit;
        }

        // The doors are built from the SAME authority check that gates them, and
        // named here rather than in the template — the landing page used to read
        // $this->__session->park_id, which is Controller_Admin's scratch value for
        // "which park am I editing", not the viewer's own. It is unset for every
        // normal login, so the officer doors were drawn for nobody; and once an
        // admin had visited Admin/park it pointed at whatever park they last
        // edited. See viewer_scopes().
        $scopes = $this->viewer_scopes($viewerId);

        $this->data['MundaneId']    = $viewerId;
        $this->data['OfficerScope'] = $scopes;
        $this->data['IsOfficer']    = $scopes !== [];
        $this->data['page_title']   = 'Scrollery';
        $this->template             = '../revised-frontend/Scrollery_index.tpl';
    }

    /** A player's own award history, filtered to what can actually be printed. */
    public function mine($action = null)
    {
        $viewerId = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        if ($viewerId <= 0) {
            header('Location: ' . UIR . 'Login/login/Scrollery/mine');
            exit;
        }

        $this->data['Awards']     = $this->Scrollery->printable_awards_for_player($viewerId);
        $this->data['MundaneId']  = $viewerId;
        $this->data['page_title'] = 'My Scrolls';
        $this->template           = '../revised-frontend/Scrollery_mine.tpl';
    }

    /**
     * "Print a new scroll" — an officer composes one certificate by hand.
     *
     * Route is Scrollery/create/<park|kingdom>/<id>. Everything past the third
     * segment collapses into one string, so $action arrives as "park/123" and is
     * split here rather than in index.php.
     */
    public function create($action = null)
    {
        [$scopeType, $scopeId] = $this->parse_scope((string) $action);

        $viewerId = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        if ($viewerId <= 0) {
            header('Location: ' . UIR . 'Login/login/Scrollery/create/' . $scopeType . '/' . $scopeId);
            exit;
        }

        if ($scopeId <= 0 || !$this->may_issue_for($viewerId, $scopeType, $scopeId)) {
            $this->no_authorization('Scrollery/create/' . $scopeType . '/' . $scopeId);
            $this->template = '../revised-frontend/Scrollery_denied.tpl';
            return;
        }

        $scope = $this->Scrollery->scope_context($scopeType, $scopeId);

        $this->data['ScopeType']  = $scopeType;
        $this->data['ScopeId']    = $scopeId;
        $this->data['Scope']      = $scope;
        $this->data['Awards']     = $this->Scrollery->printable_award_choices((int) $scope['KingdomId']);
        $this->data['Orders']     = Scroll::Orders();
        // An officer who holds both a park and a kingdom should not have to edit the
        // URL to move between them; the header chip becomes a switcher when there is
        // somewhere else to switch to.
        $this->data['OtherScopes'] = $this->other_scopes($viewerId, $scopeType, $scopeId);
        $this->data['page_title'] = 'Print a new scroll';
        $this->template           = '../revised-frontend/Scrollery_create.tpl';
    }

    /**
     * "Print bulk scrolls" — an event's or a date range's awards, in one pass.
     *
     * Route is Scrollery/bulk/<park|kingdom>/<id>, with an optional event or date
     * range in the query string.
     */
    public function bulk($action = null)
    {
        [$scopeType, $scopeId] = $this->parse_scope((string) $action);

        $viewerId = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        if ($viewerId <= 0) {
            header('Location: ' . UIR . 'Login/login/Scrollery/bulk/' . $scopeType . '/' . $scopeId);
            exit;
        }

        if ($scopeId <= 0 || !$this->may_issue_for($viewerId, $scopeType, $scopeId)) {
            $this->no_authorization('Scrollery/bulk/' . $scopeType . '/' . $scopeId);
            $this->template = '../revised-frontend/Scrollery_denied.tpl';
            return;
        }

        // Scoped data, built only after the auth gate above so nothing here ever
        // renders on the denied path.
        $events = $this->Scrollery->events_with_printable_awards($scopeType, $scopeId);

        // The picker <select> and the free-text fallback field cannot both be
        // name="event" — a GET form sends the field twice and PHP keeps only the
        // last one, silently ignoring whichever the officer actually used. The
        // select (name="event") wins whenever it carries a value; the number field
        // (name="event_id") is read only as a fallback, for a deep link naming an
        // event outside the picker's 200-row list.
        $eventFromSelect = (int) ($_GET['event'] ?? 0);
        $eventFromNumber = (int) ($_GET['event_id'] ?? 0);
        $eventId         = $eventFromSelect > 0 ? $eventFromSelect : $eventFromNumber;
        $from            = (string) ($_GET['from'] ?? '');
        $to              = (string) ($_GET['to'] ?? '');
        // The keyset cursor for the page being asked for. Opaque here on purpose —
        // the model builds it and the model reads it; this only carries it.
        $cursor          = (string) ($_GET['cursor'] ?? '');
        $ran             = $eventId > 0 || $from !== '' || $to !== '';

        $result = [
            'Printable'  => [],
            'Excluded'   => [],
            'Failed'     => false,
            'Truncated'  => false,
            'Total'      => 0,
            'Returned'   => 0,
            'Cursor'     => $cursor,
            'NextCursor' => '',
        ];
        $error  = '';

        if ($ran) {
            // The event filter narrows WITHIN the authorised scope rather than
            // replacing it — the query itself requires the award's own at_park_id /
            // at_kingdom_id to match, so a shared event yields this body's awards and
            // nobody else's. The date range still applies on top of an event id.
            //
            // $cursor is what makes row 201 of a 488-award court reachable at all: the
            // old query was limit 200 with no offset, so an event larger than the cap
            // silently ended at the 200th name in sort order and no amount of
            // narrowing by date could reach the tail.
            $result = $eventId > 0
                ? $this->Scrollery->awards_for_bulk('event', $eventId, $scopeType, $scopeId, $from, $to, $cursor)
                : $this->Scrollery->awards_for_bulk($scopeType, $scopeId, $scopeType, $scopeId, $from, $to, $cursor);
        }

        if (!empty($result['Failed'])) {
            $error = 'The award search could not be completed — the database returned an error. '
                . 'This is not the same as finding nothing; try again, and tell an administrator if it persists.';
        }

        $this->data['Error']      = $error;
        // NOTE: Truncated now means "there is another page", not "the rest was
        // discarded". Nothing is unreachable any more; NextCursor is how you get it.
        $this->data['Truncated']  = !empty($result['Truncated']);
        $this->data['Total']      = (int) ($result['Total'] ?? 0);
        $this->data['Returned']   = (int) ($result['Returned'] ?? 0);
        $this->data['Cursor']     = (string) ($result['Cursor'] ?? '');
        $this->data['NextCursor'] = (string) ($result['NextCursor'] ?? '');
        $this->data['MaxRun']     = Scroll::MAX_PRINT_RUN;
        // The BROWSER print run chunks smaller than the server cap: a 2550x3300 plate
        // decodes to ~33.7 MB of RGBA, so a 50-certificate tab touches 500-840 MB of
        // bitmap and the print rasteriser gives up. 200 is what the server will
        // accept; 25 is what a browser survives.
        $this->data['PrintChunk'] = Scroll::PRINT_CHUNK;
        $this->data['ScopeType']  = $scopeType;
        $this->data['ScopeId']    = $scopeId;
        $this->data['Scope']      = $this->Scrollery->scope_context($scopeType, $scopeId);
        $this->data['Events']     = $events;
        $this->data['EventId']    = $eventId;
        $this->data['From']       = $from;
        $this->data['To']         = $to;
        $this->data['HasRun']     = $ran;
        $this->data['Printable']  = $result['Printable'];
        $this->data['Excluded']   = $result['Excluded'];
        // Same switcher as create() — see the note there.
        $this->data['OtherScopes'] = $this->other_scopes($viewerId, $scopeType, $scopeId);
        $this->data['page_title'] = 'Print bulk scrolls';
        $this->template           = '../revised-frontend/Scrollery_bulk.tpl';
    }

    /** "park/123" -> ['park', 123]. Anything unrecognised becomes ['park', 0]. */
    private function parse_scope(string $action): array
    {
        $parts     = explode('/', trim($action, '/'));
        $scopeType = strtolower($parts[0] ?? '');
        $scopeId   = (int) ($parts[1] ?? 0);

        if ($scopeType !== 'park' && $scopeType !== 'kingdom') {
            return ['park', 0];
        }

        return [$scopeType, $scopeId];
    }

    /** The same gate Controller_Award uses to grant an award in the first place. */
    private function may_issue_for(int $viewerId, string $scopeType, int $scopeId): bool
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
     * Every park or kingdom this viewer may actually issue a certificate for.
     *
     * The list is built by running the SAME gate the doors themselves run —
     * may_issue_for() — over every scope the viewer holds, so a door can never be
     * offered that its own target will refuse. Two things were wrong before:
     *
     *  - the candidates came from $this->session->park_id / kingdom_id, which
     *    Controller_Admin sets while an admin edits a park and nothing else ever
     *    sets. Login writes only user_id / user_name / token / timeout, so for a
     *    normal officer both were unset and the officer doors were drawn for
     *    nobody at all;
     *  - the door URL then took the park unconditionally whenever a park id
     *    happened to be present, even though the gate above it passed on the
     *    KINGDOM. A Kingdom Regent, PM or Monarch who is not also a park officer
     *    was sent to Scrollery_denied.tpl on their first click.
     *
     * The candidates come from Model_Scrollery::issuable_scopes_for(), which reads
     * the offices the viewer actually holds out of ork_authorization — NOT their home
     * park and kingdom. That distinction is load-bearing: an officer whose home park
     * sits outside their own authority chain (mundane 54527 holds kingdom 4 while
     * living in a park of kingdom 3) has a real door and a home-record read cannot
     * see it.
     *
     * scope_context() is called per scope only to name it for the door; the model has
     * already run the authority check, and may_issue_for() is re-run here anyway so
     * that this controller's own gate is the last word on both sides.
     *
     * @return array<int, array{Type:string,Id:int,Name:string,Title:string,Label:string}>
     */
    private function viewer_scopes(int $viewerId): array
    {
        if ($viewerId <= 0) {
            return [];
        }

        $scopes = [];
        foreach ($this->Scrollery->issuable_scopes_for($viewerId) as $held) {
            $type = (string) ($held['Type'] ?? '');
            $id   = (int) ($held['Id'] ?? 0);
            if ($id <= 0 || !$this->may_issue_for($viewerId, $type, $id)) {
                continue;
            }

            $ctx      = $this->Scrollery->scope_context($type, $id);
            $scopes[] = [
                'Type'  => $type,
                'Id'    => $id,
                'Name'  => (string) ($ctx['Name'] ?? ''),
                'Title' => (string) ($ctx['Title'] ?? ''),
                'Label' => trim(((string) ($ctx['Title'] ?? '')) . ' ' . ((string) ($ctx['Name'] ?? ''))),
            ];
        }

        return $scopes;
    }

    /**
     * The held scopes OTHER than the one currently being viewed — the header chip's
     * switcher list. Empty for an officer who holds only one, which is the common
     * case, and the template then draws the plain chip it always did.
     *
     * @return array<int, array{Type:string,Id:int,Name:string,Title:string,Label:string}>
     */
    private function other_scopes(int $viewerId, string $scopeType, int $scopeId): array
    {
        return array_values(array_filter(
            $this->viewer_scopes($viewerId),
            static fn (array $s): bool => !($s['Type'] === $scopeType && $s['Id'] === $scopeId)
        ));
    }

    /**
     * The bare print surface. Never the authority — it re-runs the permission
     * check against its own parameters, so the URL stays shareable and a future
     * PDF service can simply fetch it.
     *
     * Rendered and exited here rather than through $this->template. View() wraps
     * every template in the controller template and then the site theme — nav,
     * GTM, footer — and this page must contain nothing but certificates.
     */
    public function render($action = null)
    {
        $viewerId  = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        $awardsIds = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) ($_GET['awards'] ?? '')))
        )));

        $variant = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['variant'] ?? '')));

        // Each id costs several domain queries and a full page of markup, so the list
        // is capped rather than unbounded. The overflow is reported on the page.
        $dropped = 0;
        if (count($awardsIds) > Scroll::MAX_PRINT_RUN) {
            $dropped   = count($awardsIds) - Scroll::MAX_PRINT_RUN;
            $awardsIds = array_slice($awardsIds, 0, Scroll::MAX_PRINT_RUN);
        }

        $scrolls = [];
        $refused = 0;
        foreach ($awardsIds as $awardsId) {
            $scroll = $this->Scrollery->scroll_for_awards_id($awardsId, $viewerId, $variant);
            if ($scroll === null) {
                // Remember the FIRST refusal only. With a bulk list the officer needs
                // one honest reason, not one per id, and the first is the one they
                // will recognise.
                if ($refused === 0) {
                    $refusedId = $awardsId;
                }
                $refused++;
                continue;
            }

            $scrolls[] = $scroll;
            $this->record_print($awardsId, $scroll, $viewerId);
        }

        // A redirect with an empty body is the most confusing thing this module could
        // do — the officer assumes they clicked wrong. print_decision() runs the same
        // gate scroll_for_awards_id() just ran, so it can name the actual reason.
        if ($scrolls === []) {
            $decision = $this->Scrollery->print_decision($refusedId ?? 0, $viewerId);
            $this->deny($decision['Message'], 'Scrollery/render');
            return;
        }

        $this->render_bare('Scrollery_render.tpl', [
            'Scrolls'    => $scrolls,
            'AutoPrint'  => isset($_GET['print']),
            'Dropped'    => $dropped,
            'MaxRun'     => Scroll::MAX_PRINT_RUN,
            'PageTitle'  => count($scrolls) === 1
                ? $scrolls[0]['title'] . ' — ' . $scrolls[0]['player']
                : count($scrolls) . ' scrolls',
        ]);
    }

    /**
     * The bare print surface for a scroll COMPOSED by hand, rather than one backed
     * by an award row. Same surface as render(), same authority check as the create
     * page and its preview endpoint — parameters arrive in the query string, so the
     * check runs against them here too.
     */
    public function compose($action = null)
    {
        $viewerId  = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        $scopeType = ($_GET['scopeType'] ?? '') === 'kingdom' ? 'kingdom' : 'park';
        $scopeId   = (int) ($_GET['scopeId'] ?? 0);

        if ($viewerId <= 0) {
            header('Location: ' . UIR . 'Login/login/Scrollery');
            exit;
        }

        if (!$this->may_issue_for($viewerId, $scopeType, $scopeId)) {
            $this->deny(
                'You do not have permission to print certificates for that park or kingdom. '
                . 'Printing a new scroll is open to an officer with edit rights over the issuing body.',
                'Scrollery/compose'
            );
            return;
        }

        $awardId = (int) ($_GET['awardId'] ?? 0);
        $title   = (string) ($_GET['title'] ?? '');
        $scope   = $this->Scrollery->scope_context($scopeType, $scopeId);
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
        $choice = $this->Scrollery->award_order_for_compose((int) ($scope['KingdomId'] ?? 0), $awardId, $title);
        if ($choice === null) {
            // Reached by a stale or hand-edited URL, and by an officer position or an
            // excluded class that never had a certificate to compose. Bouncing back to
            // the composer with no message reads as the form having eaten the click.
            $this->deny(
                'That award cannot be composed into a certificate for this kingdom — it is either '
                . 'not in the kingdom\'s catalogue, is an officer position, or is one of the classes '
                . 'Scrollery deliberately does not print.',
                'Scrollery/compose',
                'Scrollery/create/' . $scopeType . '/' . $scopeId,
                'Back to the composer'
            );
            return;
        }

        $playerId = (int) ($_GET['playerId'] ?? 0);

        // The officer's correction wins over anything inferred. Per-print only —
        // nothing is written back to the profile. The control is free text, not
        // a closed vocabulary, so it goes through the same subject/object ->
        // possessive resolution a stored pronoun_freetext value gets — "he/him"
        // typed into the box is exactly as reachable as it is from a profile.
        $override = trim((string) ($_GET['pronounOverride'] ?? ''));
        $pronoun  = $override !== '' ? $this->Scrollery->resolve_pronoun_override($override)
            : ($playerId > 0 ? $this->Scrollery->possessive_pronoun_for_player($playerId) : '');

        $scroll = Scroll::BuildScroll(
            [
                'AwardId'          => $choice['AwardId'],
                'OfficerRole'      => 'none',
                'Rank'             => (int) ($_GET['rank'] ?? 0),
                'Date'             => (string) ($_GET['date'] ?? ''),
                'Note'             => (string) ($_GET['reason'] ?? ''),
                'GivenBy'          => (string) ($_GET['givenBy'] ?? ''),
                'KingdomAwardName' => $choice['Name'],
                'ParkName'         => $scopeType === 'park' ? (string) ($_GET['issuerName'] ?? '') : '',
                'KingdomName'      => $scopeType === 'kingdom' ? (string) ($_GET['issuerName'] ?? '') : '',
            ],
            [
                'player'      => (string) ($_GET['player'] ?? ''),
                'pronoun'     => $pronoun !== '' ? $pronoun : 'their',
                'heraldry'    => (string) ($scope['Heraldry'] ?? ''),
                // Provenance for the print log. A composed scroll has no award row, so
                // these three can only come from context — and a composed row carries no
                // awards_id either, which makes mundaneId its ONLY link to a person.
                // $scopeType is already normalised to park|kingdom at :369.
                'mundaneId'   => $playerId,
                'scopeType'   => $scopeType,
                'scopeId'     => $scopeId,
                // The printed name for a Custom Award / Custom Title, whose catalogue
                // name is the slot rather than the award. Passed through RAW for the
                // same reason the recipe below is: BuildScroll() is the one place that
                // decides whether this award may be renamed at all, and it caps and
                // sanitises the string there. A second, weaker opinion here would let
                // nothing extra through and could only disagree about the length.
                'customName'  => (string) ($_GET['customName'] ?? ''),
                // The "With phrase" box — the interstitial line between the recipient's
                // name and the title, offered only for the two custom entries. Passed
                // with array_key_exists semantics all the way down: an ABSENT parameter
                // takes the per-entry default, a PRESENT but empty one means "print no
                // phrase", and those are different choices. isset() would collapse them.
                // Sanitising is BuildScroll()'s, for the same reason as customName above.
            ] + (array_key_exists('titlePhrase', $_GET)
                ? ['titlePhrase' => (string) $_GET['titlePhrase']]
                : []) + [
                'issuerTitle' => (string) ($_GET['issuerTitle'] ?? ''),
                'useThe'      => ($_GET['useThe'] ?? '') === '1',
                'useOf'       => ($_GET['useOf'] ?? '') === '1',
                'variant'     => preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['variant'] ?? ''))),
                // Passed through raw on purpose: Scroll::NormaliseRecipe() is the one
                // allowlist for these, and it runs inside BuildScroll(). Sanitising
                // here as well would be a second, weaker opinion about a closed
                // vocabulary — and anything it let through would still be rejected
                // there. BuildScroll() ignores the key entirely for every order but
                // custom, so a recipe on a Knighthood costs nothing.
                'recipe'      => [
                    'ground'    => (string) ($_GET['ground'] ?? ''),
                    'border'    => (string) ($_GET['border'] ?? ''),
                    'primary'   => (string) ($_GET['primary'] ?? ''),
                    'secondary' => (string) ($_GET['secondary'] ?? ''),
                    'rankSet'   => (string) ($_GET['rankSet'] ?? ''),
                ],
            ]
        );

        /*
         * A HAND-COMPOSED scroll must not print with no award name on it.
         *
         * Scroll::ResolveTitle() answers '' for a Custom Award / Custom Title row that
         * nobody has named — it will not letter the ORK's own placeholder onto a
         * certificate — and a blank award line is the deliberate, correct answer when
         * REPRINTING one of the 5,867 historical grants whose name the ORK genuinely
         * does not know. It is not the right answer here. compose() is an officer
         * standing at the composer with the award in front of them, and the composer
         * now has a box to type its name into; printing a nameless certificate from
         * that surface is a slip, not a data limitation.
         *
         * So this refuses only on the composed path. render() — the reprint surface —
         * is untouched and still prints the blank line to complete by hand.
         *
         * Tested on the RESOLVED title rather than on the request, deliberately:
         * $scroll['title'] is the string that is about to be drawn, so this holds
         * however BuildScroll() decides to apply $context['customName'], including
         * when Scroll::SanitiseCustomName() rejects a value this controller forwarded
         * (an all-control-character string, or invalid UTF-8, both of which come back
         * as ''). It also cannot fire for a kingdom that NAMED its award-94 row —
         * kingdom 1 holds seven of those — because such a row resolves to its own
         * title and never to ''.
         *
         * Before record_print(), because a print that was refused did not happen.
         */
        if (trim((string) $scroll['title']) === '') {
            $this->deny(
                'That certificate would print with no award name on it. Custom Award and Custom '
                . 'Title are the ORK\'s own placeholders rather than names, so a certificate '
                . 'composed from one has to be told what the award is actually called. Go back to '
                . 'the composer and type the name that should be printed.',
                'Scrollery/compose',
                'Scrollery/create/' . $scopeType . '/' . $scopeId,
                'Back to the composer'
            );
            return;
        }

        // AwardsId null: a composed certificate has no award row behind it, so the log
        // records the print without pretending it does.
        $this->record_print(null, $scroll, $viewerId);

        $this->render_bare('Scrollery_render.tpl', [
            'Scrolls'   => [$scroll],
            'AutoPrint' => isset($_GET['print']),
            'PageTitle' => $scroll['title'] . ' — ' . ($scroll['player'] !== '' ? $scroll['player'] : 'blank'),
        ]);
    }

    /**
     * Every dead end in this module used to be `header('Location: …Scrollery'); exit;`
     * with an empty body — the single most confusing thing a page can do, because the
     * user concludes they clicked the wrong link. This routes them all to the one
     * denial page with the real reason on it and somewhere to go next.
     *
     * $next/$nextLabel default to the landing page, which is right for a refusal that
     * has no more specific recovery.
     */
    private function deny(
        string $message,
        string $loginRoute,
        string $next = 'Scrollery',
        string $nextLabel = 'Back to Scrollery'
    ): void {
        // Still the standard gate for a logged-OUT visitor: no_authorization()
        // redirects them to the login page before anything below renders.
        $this->no_authorization($loginRoute);

        // no_authorization() puts its own generic sentence in $Error. The specific one
        // is what the officer actually needs, so it wins where there is one.
        if ($message !== '') {
            $this->data['Error'] = $message;
        }
        $this->data['NextUrl']   = $next;
        $this->data['NextLabel'] = $nextLabel;
        $this->data['page_title'] = 'Scrollery';
        $this->template          = '../revised-frontend/Scrollery_denied.tpl';
    }

    /**
     * Record that a certificate reached the print surface.
     *
     * Never guarded by a try/catch here on purpose: RecordPrint() is documented to
     * never throw and to return 0 on every failure path, so a second layer of
     * swallowing would only hide a real bug. It also dedupes internally, so a refresh
     * or a cancelled print dialog does not log twice.
     *
     * $awardsId is null for compose(), which prints without an award row.
     */
    private function record_print(?int $awardsId, array $scroll, int $viewerId): void
    {
        Ork3::$Lib->scrollprintlog->RecordPrint([
            'AwardsId'    => $awardsId,
            'MundaneId'   => (int) ($scroll['mundaneId'] ?? 0),
            'PrintedById' => $viewerId,
            'ScopeType'   => $scroll['scopeType'] ?? '',
            'ScopeId'     => (int) ($scroll['scopeId'] ?? 0),
            'OrderId'     => (string) ($scroll['order'] ?? ''),
            'Rank'        => (int) ($scroll['rank'] ?? 0),
            'Variant'     => (string) ($scroll['variant'] ?? ''),
            'Recipe'      => $scroll['recipe'] ?? null,
        ]);
    }

    /**
     * Render one revised-frontend template with no chrome around it, and stop.
     *
     * $data becomes local variables in the template's scope, so the template reads
     * $Scrolls rather than $this->__data['Scrolls'] — there is no View here to
     * carry that property.
     */
    private function render_bare(string $template, array $data): void
    {
        header('Content-Type: text/html; charset=utf-8');
        (static function (string $__path, array $__data): void {
            extract($__data, EXTR_SKIP);
            include $__path;
        })(DIR_TEMPLATE . 'revised-frontend/' . $template, $data);
        exit;
    }
}
