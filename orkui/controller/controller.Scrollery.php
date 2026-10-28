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

        // 278 printable awards on one player is the measured maximum, and 1,045
        // players hold more than 60 — the page filters, sorts and reveals in
        // batches rather than dropping a wall of rows on them. All three are done
        // in the browser over the whole list, so nothing here pages the query: the
        // set is bounded and small, and a server page would put the sort behind a
        // round trip for no gain.
        $this->data['Awards']     = $this->decorate_for_reprint(
            $this->Scrollery->printable_awards_for_player($viewerId)
        );
        $this->data['MundaneId']  = $viewerId;
        // The same two caps bulk carries: the server refuses more than MAX_PRINT_RUN
        // ids in one render URL, and a browser's print rasteriser gives up well
        // before that, so a long selection opens in windows of PRINT_CHUNK.
        $this->data['MaxRun']     = Scroll::MAX_PRINT_RUN;
        $this->data['PrintChunk'] = Scroll::PRINT_CHUNK;
        $this->data['page_title'] = 'My Scrolls';
        $this->template           = '../revised-frontend/Scrollery_mine.tpl';
    }

    /**
     * Name the ORDER behind each printable row, and the variants it offers.
     *
     * Two things the reprint list could not say before. The DESIGN — whether the
     * certificate carries that order's own artwork or the generic composed scroll —
     * is the difference between the two things this module prints, and the page
     * listed them identically. And the VARIANT: Owl ships three plates
     * (parchment / cyanotype / transparency) and Battle two (arcane / martial), but
     * Scrollery_mine.tpl never set &variant=, so a reprint could only ever reach
     * variants[0] even though render() has always read the parameter.
     *
     * Scroll::OrderForAwardRow() is the module's single allowlist and the model has
     * already run it (inside Scroll::HasArt()) to decide the row belongs on this
     * list at all, so this is a second resolution of an answer that was already
     * computed. It belongs on the model row — reported to the model's owner rather
     * than written there, since Model_Scrollery is another workstream's this round.
     * Until then this keeps the read here, where at least it is one call per row on
     * a list already capped by the player's own award history.
     *
     * @param  array<int, array<string, mixed>> $awards
     * @return array<int, array<string, mixed>>
     */
    private function decorate_for_reprint(array $awards): array
    {
        $decorated = [];
        foreach ($awards as $row) {
            $order    = Scroll::OrderForAwardRow($row);
            $orderId  = (string) ($order['id'] ?? '');
            $variants = is_array($order['variants'] ?? null) ? $order['variants'] : [];

            $row['OrderId']  = $orderId;
            $row['Variants'] = array_values(array_filter(array_map('strval', $variants)));
            // 'custom' is the composed scroll — a ground, a border and a numeral
            // rather than the order's own plates. Everything else is real artwork.
            $row['HasOrderArt'] = $orderId !== '' && $orderId !== 'custom';
            $decorated[] = $row;
        }

        return $decorated;
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
        /*
         * The pronoun override's suggestions.
         *
         * Scrollery_create.tpl has always rendered list="sc-pronoun-list" and the
         * <datalist> behind it under `if ($Pronouns)`, and nothing ever set
         * $Pronouns — so the branch was false on every request and the control was a
         * bare 20-character box labelled "Pronoun (possessive)" with no vocabulary
         * at all. That is the one control on the page whose whole purpose is getting
         * a player's own word right on their keepsake.
         *
         * The list is a DATALIST, not a closed set: it is a prompt, and anything
         * typed still goes through Scroll::ResolvePronounOverride(), so an officer
         * with a word the table does not carry is no worse off than before.
         */
        $this->data['Pronouns'] = $this->Scrollery->possessive_suggestions();
        /*
         * The "Customize Design" card. The rule data is the SAME file
         * Scroll::CustomBorderRule() reads, handed to scrollery.js so the Custom Border
         * checkbox can hide itself on an incompatible award without a round trip — the
         * server still applies the rule and ignores a border it cannot honour.
         *
         * The scope's own arms are known now. The Park arms on a kingdom scope follow
         * the recipient's home park, and Persona the recipient too, so both come back
         * on every preview response instead. heraldry_sources() with no player
         * asks only the two cached scope lookups.
         */
        $this->data['CustomBorderRules'] = Scroll::CustomBorderRules();
        $this->data['MatchScreens']      = Scroll::MatchScreens();
        $this->data['DesignDefaults']    = Scroll::DEFAULT_DESIGN;
        $this->data['HeraldryAvailable'] = array_map(
            static fn (string $u): bool => $u !== '',
            $this->Scrollery->heraldry_sources($scopeType, $scopeId, 0)
        );
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
                // Display, not Title + Name: for a kingdom those are the composer's split
                // fields ("Kingdom", "the Wetlands"), which read wrongly as a label.
                'Label' => (string) ($ctx['Display'] ?? trim(((string) ($ctx['Title'] ?? '')) . ' ' . ((string) ($ctx['Name'] ?? '')))),
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
     *
     * The response carries Cache-Control: private, no-store (see render_bare).
     */
    public function render($action = null)
    {
        $awardsIds = $this->requested_awards_ids();
        $variant   = $this->requested_variant();

        // Identity, never authorisation — see print_viewer(). The per-award gate
        // below runs unchanged against whichever viewer this resolves to.
        $viewerId = $this->print_viewer($awardsIds);

        // Each id costs a page of markup and a share of the batch query, so the list
        // is capped rather than unbounded. The overflow is reported on the page.
        //
        // AFTER the token check, deliberately: the token signs the set the request
        // asks for, and the cap is this route's own rendering policy. Verifying the
        // truncated list would make a >MAX_RUN token fail as 'bad-signature' — a
        // security-shaped error for what is really "that is too many to print".
        $dropped = 0;
        if (count($awardsIds) > Scroll::MAX_PRINT_RUN) {
            $dropped   = count($awardsIds) - Scroll::MAX_PRINT_RUN;
            $awardsIds = array_slice($awardsIds, 0, Scroll::MAX_PRINT_RUN);
        }

        // One pass for the whole run, not one call per id. The old loop re-ran
        // AwardsForPlayer() — a nine-table SELECT DISTINCT over 391k rows — plus a
        // recipient lookup and one or two pronoun-table reads for every certificate;
        // at MAX_PRINT_RUN that is several hundred statements before any markup
        // exists. Measured by workstream 1 at 21.1 ms -> 6.8 ms for 50 certificates.
        //
        // The permission decision is still per (viewer, award) inside the model, and
        // still uncached. Only the READ is batched.
        // &heraldry= — whose arms every certificate in the run draws, chosen in the
        // page's options sheet. Absent or unknown is 'auto': what reprints always drew.
        $heraldry = Scroll::NormaliseRunHeraldry(isset($_GET['heraldry']) ? (string) $_GET['heraldry'] : null);
        $run      = $this->Scrollery->scrolls_for_awards_ids($awardsIds, $viewerId, $variant, $heraldry);
        $scrolls  = [];
        foreach ($run['Scrolls'] as $awardsId => $scroll) {
            $scrolls[] = $scroll;
            $this->record_print((int) $awardsId, $scroll, $viewerId);
        }

        // A redirect with an empty body is the most confusing thing this module could
        // do — the officer assumes they clicked wrong. Refused is keyed by awards id
        // with the reason already decided, so the message can name WHICH award was
        // refused rather than reporting the first failure generically. print_decision()
        // is re-run only to turn that reason into its officer-facing sentence, which
        // the model keeps private; it runs the identical gate, so the two can never
        // disagree, and it happens only on the path where nothing printed at all.
        if ($scrolls === []) {
            $refusedIds = array_map('intval', array_keys($run['Refused']));
            $firstId    = $refusedIds[0] ?? 0;
            $decision   = $this->Scrollery->print_decision($firstId, $viewerId);
            $message    = $decision['Message'];

            if ($firstId > 0) {
                $message .= ' (Award record ' . $firstId . '.'
                    . (count($refusedIds) > 1
                        ? ' The other ' . (count($refusedIds) - 1) . ' in this run were refused too.'
                        : '')
                    . ')';
            }

            $this->deny($message, 'Scrollery/render');
            return;
        }

        $this->render_bare('Scrollery_render.tpl', [
            'Scrolls'    => $scrolls,
            'AutoPrint'  => isset($_GET['print']),
            // The options sheet offers the run-level arms choice here and on court(),
            // never on compose(), whose arms were already chosen in the composer.
            'HeraldryChoice' => true,
            'Heraldry'       => $heraldry,
            'Dropped'    => $dropped,
            'MaxRun'     => Scroll::MAX_PRINT_RUN,
            'PageTitle'  => count($scrolls) === 1
                ? $scrolls[0]['title'] . ' — ' . $scrolls[0]['player']
                : count($scrolls) . ' scrolls',
        ]);
    }

    /**
     * A court's Scrollery Print lines, as one bare print run — the Court Planner's
     * "Print Scrollery scrolls" button opens this in a new tab, with &configure=1 rather
     * than &print=1: the page opens on its options sheet (the hand, signature, heading,
     * reason lines and arms for the whole run) and the officer prints from there.
     *
     * Route is Scrollery/court/{courtId}, with &courtLines=1,2,3 naming the court lines
     * the planner meant to print (absent or empty: every Scrollery Print line). Not
     * &lines=: Scrollery_render.tpl reads that as its Reason-lines on/off switch and
     * rewrites it when the box is toggled, which turned "these three lines" into
     * "every line" on reload.
     * The list is a request, never a grant: Model_Scrollery::scrolls_for_court_awards()
     * re-reads the court, drops ids that are not this court's, and drops lines a peer
     * has since moved off Scrollery Print or cancelled.
     *
     * AUTHORITY IS Model_Court::can_manage() ALONE — whoever may run the court may
     * print its scrolls. That is a deliberate decision, not an omission: Scrollery's
     * own may_issue_for() is not layered on top. Session only, no print token: the
     * planner is a signed-in surface, and a court line has no awards_id for a token
     * to sign.
     *
     * The planner's preflight (CourtAjax/scrollery_print_plan) already removed
     * unprintable lines before this tab opened, so a Skipped line here is one that
     * became unprintable in between. It is reported in a screen-only notice rather
     * than a page of its own, so it never costs a sheet of paper.
     */
    public function court($action = null)
    {
        $courtId  = (int) (explode('/', trim((string) $action, '/'))[0] ?? 0);
        $viewerId = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        if ($viewerId <= 0) {
            header('Location: ' . UIR . 'Login/login/Scrollery/court/' . $courtId);
            exit;
        }

        $this->load_model('Court');
        $court = $courtId > 0 ? $this->Court->get_court_detail($courtId) : null;
        if (!is_array($court) || !$this->Court->can_manage($viewerId, $court['KingdomId'], $court['ParkId'])) {
            // One sentence for "no such court" and "not yours": telling the two apart
            // would confirm which court ids exist to someone who cannot run them.
            $this->deny(
                'You do not have permission to print scrolls for that court. Printing a court\'s '
                . 'scrolls is open to the officers who can manage the court.',
                'Scrollery/court/' . $courtId
            );
            return;
        }

        $lineIds = Scroll::CourtLineIds($_GET);   // &courtLines=, or an old link's &lines=12,13

        // &heraldry= as on render(): 'auto' (the default) is the court's own arms.
        $heraldry = Scroll::NormaliseRunHeraldry(isset($_GET['heraldry']) ? (string) $_GET['heraldry'] : null);
        $run      = $this->Scrollery->scrolls_for_court_awards($courtId, $lineIds, $viewerId, $heraldry);
        $planner  = 'Court/detail/' . $courtId;

        if ($run['Scrolls'] === []) {
            $first = reset($run['Skipped']);
            $this->deny(
                $first !== false
                    ? 'None of these scrolls can be printed. ' . $first
                    : 'None of these court lines is set to Scrollery Print any more, so there is nothing to print.',
                'Scrollery/court/' . $courtId,
                $planner,
                'Back to the court'
            );
            return;
        }

        $scrolls = [];
        foreach ($run['Scrolls'] as $scroll) {
            $scrolls[] = $scroll;
            // AwardsId null: like a composed scroll, a court line has no award row yet.
            $this->record_print(null, $scroll, $viewerId);
        }

        // Keyed by court-award id, not by the label: two lines for the same person and
        // the same award share a label, and keying by it merged them into one entry.
        $skipped = [];
        foreach ($run['Skipped'] as $caId => $reason) {
            $line  = $run['Lines'][$caId] ?? [];
            $label = implode(' — ', array_filter([
                trim((string) ($line['Persona'] ?? '')),
                trim((string) ($line['AwardName'] ?? '')),
            ], 'strlen'));
            $skipped[(int) $caId] = [
                'Label'  => $label !== '' ? $label : 'Court line ' . (int) $caId,
                'Reason' => (string) $reason,
            ];
        }

        $this->render_bare('Scrollery_render.tpl', [
            'Scrolls'   => $scrolls,
            'Skipped'   => $skipped,
            'Dropped'   => (int) $run['Dropped'],
            'MaxRun'    => Scroll::MAX_PRINT_RUN,
            'AutoPrint' => isset($_GET['print']),
            'HeraldryChoice' => true,
            'Heraldry'       => $heraldry,
            // The print tab is opened blank and then pointed here, so it rarely has a
            // referrer; Back should still lead to the planner it came from.
            'BackUrl'   => UIR . $planner,
            'PageTitle' => count($scrolls) . (count($scrolls) === 1 ? ' scroll' : ' scrolls'),
        ]);
    }

    /**
     * The same print run as DATA — item 41's other half.
     *
     * `render` is a page, so anything that wants the FACTS about a run — a PDF
     * service, an archiver, a mail merge — has to scrape them back out of SVG. This
     * answers the same resolution as JSON: order, rank, variant, title, recipient,
     * pronoun, reason, date, issuer, heraldry and recipe, plus the refusals, keyed by
     * awards id. Model_Scrollery::print_manifest() builds it from
     * scrolls_for_awards_ids() — literally the call render() above makes — so a
     * manifest can never disclose a certificate the same viewer could not print, and
     * the two surfaces cannot drift apart about what a run contains.
     *
     * Same identity rules and the same cap as render(). Nothing is written to the
     * print log: reading the facts about a certificate is not printing one, and a
     * log that cannot tell those apart is worth less than one that can.
     */
    public function manifest($action = null)
    {
        $awardsIds = $this->requested_awards_ids();
        $variant   = $this->requested_variant();
        $viewerId  = $this->print_viewer($awardsIds);

        header('Content-Type: application/json; charset=utf-8');
        // A manifest names a person and their award history exactly as the
        // certificate does. Same rule, same reason — see render_bare().
        header('Cache-Control: private, no-store, max-age=0');

        $dropped = 0;
        if (count($awardsIds) > Scroll::MAX_PRINT_RUN) {
            $dropped   = count($awardsIds) - Scroll::MAX_PRINT_RUN;
            $awardsIds = array_slice($awardsIds, 0, Scroll::MAX_PRINT_RUN);
        }

        if ($viewerId <= 0 || $awardsIds === []) {
            http_response_code($viewerId <= 0 ? 403 : 400);
            // Deliberately one sentence for both an absent session and a token that
            // failed: Scroll::VerifyPrintToken()'s reasons are for a log, not for the
            // requester — telling an unauthenticated caller apart 'expired' from
            // 'bad-signature' is free information about the secret.
            echo json_encode([
                'Status' => [
                    'Status' => 1,
                    'Error'  => $viewerId <= 0
                        ? 'A manifest needs a signed-in session or a valid print token.'
                        : 'No award ids were given.',
                ],
            ]);
            // index.php appends the whole themed page to anything that does not stop
            // here, which would make this valid JSON followed by an HTML document.
            exit;
        }

        echo json_encode(
            ['Status' => ['Status' => 0], 'Dropped' => $dropped]
            + $this->Scrollery->print_manifest($awardsIds, $viewerId, $variant)
        );
        exit;
    }

    /** The `awards=1,2,3` list, unique and positive, in the order the URL gave them. */
    private function requested_awards_ids(): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) ($_GET['awards'] ?? '')))
        )));
    }

    /** Lower-case letters only; Scroll::BuildScroll() rejects anything not the order's own. */
    private function requested_variant(): string
    {
        return preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['variant'] ?? '')));
    }

    /**
     * WHO is asking for this print run — a signed token if there is a valid one,
     * otherwise the session.
     *
     * ⚠️ IDENTITY, NEVER AUTHORISATION. The token answers "who", and the ordinary
     * per-award gate then runs against that viewer exactly as it does for a session:
     * Model_Scrollery::scrolls_for_awards_ids() re-decides every id. Nothing here
     * says "let this through", and a token can therefore never reach a certificate
     * the viewer it names could not have printed by logging in.
     *
     * IN PLACE OF the session, not in addition to it — that is the whole point. A
     * headless fetcher (a PDF sidecar, a print worker) has no session to read an
     * identity out of; requiring one as well would make the token pointless.
     *
     * The id set is signed but not carried, so a token minted for one run cannot be
     * widened or narrowed to another, and the token itself discloses nothing about
     * which awards it covers.
     *
     * An invalid, expired or forged token FALLS BACK to the session rather than
     * refusing outright. That direction is the safe one: the session can only reach
     * what it could always reach, and a stale link in a browser tab then behaves
     * exactly as it did before tokens existed instead of becoming a denial an
     * officer cannot explain. VerifyPrintToken()'s Reason is deliberately not
     * surfaced — it is for a log, not for the requester.
     *
     * @param array<int, int> $awardsIds the ids the request actually carries
     */
    private function print_viewer(array $awardsIds): int
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token !== '') {
            $verdict = Scroll::VerifyPrintToken($token, $awardsIds);
            if (!empty($verdict['Valid'])) {
                return (int) $verdict['ViewerId'];
            }
        }

        return isset($this->session->user_id) ? (int) $this->session->user_id : 0;
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

        $sources        = $this->Scrollery->heraldry_sources($scopeType, $scopeId, $playerId);
        $heraldrySource = Scroll::ResolveHeraldrySource(
            (string) ($_GET['heraldrySource'] ?? ''),
            $scopeType,
            array_map(static fn (string $u): bool => $u !== '', $sources)
        );

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
                'heraldry'    => $sources[$heraldrySource],
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
                // Passed raw like the recipe: Scroll::NormaliseDesign() inside BuildScroll()
                // is the one allowlist. Only the heraldry source is resolved here, because
                // it needs the arms lookup that BuildScroll() must stay free of.
                'design'      => [
                    'heraldrySource' => $heraldrySource,
                    'customBorder'   => (string) ($_GET['customBorder'] ?? ''),
                    'color1'         => (string) ($_GET['color1'] ?? ''),
                    'color2'         => (string) ($_GET['color2'] ?? ''),
                    'matchStyle'     => (string) ($_GET['matchStyle'] ?? ''),
                    'customRibbon'   => (string) ($_GET['customRibbon'] ?? ''),
                    'ribbonColor'    => (string) ($_GET['ribbonColor'] ?? ''),
                ],
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
            // The drawn design rides in the same JSON, beside the custom recipe keys, and
            // only when it is not the default (see Scroll::PrintRecipe()).
            'Recipe'      => Scroll::PrintRecipe($scroll),
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
        /*
         * A certificate names a person and one line of their award history, and this
         * route is the one surface in the module that answers with nothing else — no
         * nav, no theme, just the document. The response set only a content type, so
         * a shared proxy or a browser back/forward cache was free to keep it and hand
         * it to whoever asked next.
         *
         * private, no-store rather than no-cache: no-cache still permits a stored
         * copy that is merely revalidated, and there is nothing here worth storing.
         * This is also what keeps the future signed-token seam honest — a URL that a
         * PDF service may fetch on someone's behalf must not leave a copy behind it.
         */
        header('Cache-Control: private, no-store, max-age=0');
        (static function (string $__path, array $__data): void {
            extract($__data, EXTR_SKIP);
            include $__path;
        })(DIR_TEMPLATE . 'revised-frontend/' . $template, $data);
        exit;
    }
}
