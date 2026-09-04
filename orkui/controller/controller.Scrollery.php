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

        $this->data['MundaneId']  = $viewerId;
        $this->data['IsOfficer']  = $this->viewer_is_officer($viewerId);
        $this->data['page_title'] = 'Scrollery';
        $this->template           = '../revised-frontend/Scrollery_index.tpl';
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
        $ran             = $eventId > 0 || $from !== '' || $to !== '';

        $result = ['Printable' => [], 'Excluded' => [], 'Failed' => false, 'Truncated' => false];
        $error  = '';

        if ($ran) {
            // The event filter narrows WITHIN the authorised scope rather than
            // replacing it — the query itself requires the award's own at_park_id /
            // at_kingdom_id to match, so a shared event yields this body's awards and
            // nobody else's. The date range still applies on top of an event id,
            // which is what makes the truncation notice's advice actionable.
            $result = $eventId > 0
                ? $this->Scrollery->awards_for_bulk('event', $eventId, $scopeType, $scopeId, $from, $to)
                : $this->Scrollery->awards_for_bulk($scopeType, $scopeId, $scopeType, $scopeId, $from, $to);
        }

        if (!empty($result['Failed'])) {
            $error = 'The award search could not be completed — the database returned an error. '
                . 'This is not the same as finding nothing; try again, and tell an administrator if it persists.';
        }

        $this->data['Error']      = $error;
        $this->data['Truncated']  = !empty($result['Truncated']);
        $this->data['MaxRun']     = Scroll::MAX_PRINT_RUN;
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
     * Whether to offer the officer doors at all. The doors re-check authority
     * against the specific park or kingdom they are given; this only decides
     * whether to draw them.
     */
    private function viewer_is_officer(int $viewerId): bool
    {
        if ($viewerId <= 0) {
            return false;
        }

        $auth = Ork3::$Lib->authorization;
        $parkId    = isset($this->session->park_id) ? (int) $this->session->park_id : 0;
        $kingdomId = isset($this->session->kingdom_id) ? (int) $this->session->kingdom_id : 0;

        return ($parkId > 0 && $auth->HasAuthority($viewerId, AUTH_PARK, $parkId, AUTH_EDIT))
            || ($kingdomId > 0 && $auth->HasAuthority($viewerId, AUTH_KINGDOM, $kingdomId, AUTH_EDIT));
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
        foreach ($awardsIds as $awardsId) {
            $scroll = $this->Scrollery->scroll_for_awards_id($awardsId, $viewerId, $variant);
            if ($scroll !== null) {
                $scrolls[] = $scroll;
            }
        }

        if ($scrolls === []) {
            header('Location: ' . UIR . 'Scrollery');
            exit;
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

        if ($viewerId <= 0 || !$this->may_issue_for($viewerId, $scopeType, $scopeId)) {
            header('Location: ' . UIR . 'Scrollery');
            exit;
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
            header('Location: ' . UIR . 'Scrollery/create/' . $scopeType . '/' . $scopeId);
            exit;
        }

        $playerId = (int) ($_GET['playerId'] ?? 0);
        $pronoun  = $playerId > 0 ? $this->Scrollery->possessive_pronoun_for_player($playerId) : '';

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
                'issuerTitle' => (string) ($_GET['issuerTitle'] ?? ''),
                'useThe'      => ($_GET['useThe'] ?? '') === '1',
                'useOf'       => ($_GET['useOf'] ?? '') === '1',
                'variant'     => preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['variant'] ?? ''))),
            ]
        );

        $this->render_bare('Scrollery_render.tpl', [
            'Scrolls'   => [$scroll],
            'AutoPrint' => isset($_GET['print']),
            'PageTitle' => $scroll['title'] . ' — ' . ($scroll['player'] !== '' ? $scroll['player'] : 'blank'),
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
