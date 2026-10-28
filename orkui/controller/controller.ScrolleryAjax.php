<?php

/**
 * Live preview for the Scrollery create flow.
 *
 * Re-runs the same authority check the page did. An Ajax endpoint is not exempt
 * from it — the page decides what to draw, this decides what may be rendered.
 */
class Controller_ScrolleryAjax extends Controller
{
    /*
     * Every response exits rather than returning. Returning lets index.php go on to
     * call $C->view(), which appends the whole themed page after the JSON — the
     * majority of the ORK's Ajax controllers exit for exactly this reason
     * (PlayerAjax 73 times, KingdomAjax 60).
     */

    public function preview()
    {
        header('Content-Type: application/json');

        $viewerId = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        if ($viewerId <= 0) {
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'Not logged in']]);
            exit;
        }

        $scopeType = ($_POST['scopeType'] ?? '') === 'kingdom' ? 'kingdom' : 'park';
        $scopeId   = (int) ($_POST['scopeId'] ?? 0);

        if ($scopeId <= 0 || !Ork3::$Lib->authorization->HasAuthority(
            $viewerId,
            $scopeType === 'kingdom' ? AUTH_KINGDOM : AUTH_PARK,
            $scopeId,
            AUTH_EDIT
        )) {
            echo json_encode(['Status' => ['Status' => 5, 'Error' => 'Not authorised for that park or kingdom']]);
            exit;
        }

        $this->load_model('Scrollery');
        $scope   = $this->Scrollery->scope_context($scopeType, $scopeId);
        $awardId = (int) ($_POST['awardId'] ?? 0);
        $title   = (string) ($_POST['title'] ?? '');
        // award_order_for_compose(), not Scroll::OrderForAwardId(): custom's own
        // award_id is the 0 sentinel OrderForAwardId() always returns null for, so
        // previewing a custom-eligible choice from the create dropdown could never
        // get past this check. Re-resolving against the kingdom's own catalog
        // (rather than trusting a client-supplied award id + title with no
        // OfficerRole behind it) is what still keeps an officer position from
        // previewing a certificate.
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
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'That award has no certificate artwork']]);
            exit;
        }

        $sources        = $this->Scrollery->heraldry_sources($scopeType, $scopeId, (int) ($_POST['playerId'] ?? 0));
        $heraldrySource = Scroll::ResolveHeraldrySource(
            (string) ($_POST['heraldrySource'] ?? ''),
            $scopeType,
            array_map(static fn (string $u): bool => $u !== '', $sources)
        );

        $scroll = Scroll::BuildScroll(
            [
                'AwardId'          => $choice['AwardId'],
                'OfficerRole'      => 'none',
                'Rank'             => (int) ($_POST['rank'] ?? 0),
                'Date'             => (string) ($_POST['date'] ?? ''),
                'Note'             => (string) ($_POST['reason'] ?? ''),
                'GivenBy'          => (string) ($_POST['givenBy'] ?? ''),
                'KingdomAwardName' => $choice['Name'],
                'ParkName'         => $scopeType === 'park' ? (string) ($_POST['issuerName'] ?? '') : '',
                'KingdomName'      => $scopeType === 'kingdom' ? (string) ($_POST['issuerName'] ?? '') : '',
            ],
            [
                'player'      => (string) ($_POST['player'] ?? ''),
                'pronoun'     => $this->requested_pronoun(),
                // The resolved source's URL from heraldry_sources(), which reads the
                // cached kingdom/park lookups; $scope['Heraldry'] is no longer read.
                'heraldry'    => $sources[$heraldrySource],
                'issuerTitle' => (string) ($_POST['issuerTitle'] ?? ''),
                'useThe'      => ($_POST['useThe'] ?? '') === '1',
                'useOf'       => ($_POST['useOf'] ?? '') === '1',
                // Carried for parity with compose(), so the preview and the print it
                // previews build the same scroll. This path never reaches the print
                // log — it renders a preview and exits — but a shape that diverges
                // from compose()'s is exactly how the two drift apart later.
                'mundaneId'   => (int) ($_POST['playerId'] ?? 0),
                'scopeType'   => $scopeType,
                'scopeId'     => $scopeId,
                // The printed name for a Custom Award / Custom Title, whose catalogue
                // name is the slot rather than the award. Passed through RAW for the
                // same reason the recipe below is: BuildScroll() is the one place that
                // decides whether this award may be renamed at all, and it caps and
                // sanitises the string there. A second, weaker opinion here would let
                // nothing extra through and could only disagree about the length.
                //
                // Unlike compose(), the preview does NOT refuse an empty one: an
                // unnamed custom entry resolves to a BLANK award line, and the stage
                // showing that blank is exactly how the officer learns the field is
                // not optional. Refusing here would replace the picture with an error
                // and tell them less.
                'customName'  => (string) ($_POST['customName'] ?? ''),
                // The "With phrase" box — the interstitial line between the recipient's
                // name and the title, offered only for the two custom entries. Passed
                // with array_key_exists semantics all the way down: an ABSENT parameter
                // takes the per-entry default, a PRESENT but empty one means "print no
                // phrase", and those are different choices. isset() would collapse them.
                // Sanitising is BuildScroll()'s, for the same reason as customName above.
            ] + (array_key_exists('titlePhrase', $_POST)
                ? ['titlePhrase' => (string) $_POST['titlePhrase']]
                : []) + [
                'variant'     => preg_replace('/[^a-z]/', '', strtolower((string) ($_POST['variant'] ?? ''))),
                // Passed raw like the recipe: Scroll::NormaliseDesign() inside BuildScroll()
                // is the one allowlist. Only the heraldry source is resolved here, because
                // it needs the arms lookup that BuildScroll() must stay free of.
                'design'      => [
                    'heraldrySource' => $heraldrySource,
                    'customBorder'   => (string) ($_POST['customBorder'] ?? ''),
                    'color1'         => (string) ($_POST['color1'] ?? ''),
                    'color2'         => (string) ($_POST['color2'] ?? ''),
                    'matchStyle'     => (string) ($_POST['matchStyle'] ?? ''),
                    'customRibbon'   => (string) ($_POST['customRibbon'] ?? ''),
                    'ribbonColor'    => (string) ($_POST['ribbonColor'] ?? ''),
                ],
                // Passed through raw on purpose: Scroll::NormaliseRecipe() is the one
                // allowlist for these, and it runs inside BuildScroll(). Sanitising
                // here as well would be a second, weaker opinion about a closed
                // vocabulary — and anything it let through would still be rejected
                // there. BuildScroll() ignores the key entirely for every order but
                // custom, so a recipe on a Knighthood costs nothing.
                'recipe'      => [
                    'ground'    => (string) ($_POST['ground'] ?? ''),
                    'border'    => (string) ($_POST['border'] ?? ''),
                    'primary'   => (string) ($_POST['primary'] ?? ''),
                    'secondary' => (string) ($_POST['secondary'] ?? ''),
                    'rankSet'   => (string) ($_POST['rankSet'] ?? ''),
                ],
            ]
        );

        echo json_encode([
            'Status' => ['Status' => 0],
            // Which sources have arms for this scope and recipient, and which one drew.
            'HeraldrySources' => array_map(static fn (string $u): bool => $u !== '', $sources),
            'HeraldrySource'  => $heraldrySource,
            /*
             * ART_DISPLAY, not the master. The composer's preview stage is about
             * 600 px wide and was downloading 300 dpi print plates to fill it — a
             * lion rank-1 certificate is 3.83 MB — on every award, rank, variant and
             * recipe change. Scroll::ArtUrl() prefers a `display` rendition at this
             * size and falls back to `print`, so the preview is correct either way.
             *
             * render() and compose() stay on ART_MASTER: those are the surfaces that
             * reach paper, and a print must never be served the small plate.
             *
             * ⚠️ This is a no-op until the certificate partials move from $art to the
             * scroll_art() helper (workstream 2) — every size resolves to the master
             * until they do. Wired now because it is correct now, and because the
             * saving arrives on its own once their pass lands rather than needing a
             * second visit here.
             */
            'Html'   => Scroll::Render($scroll, Scroll::ART_DISPLAY),
            'Rank'   => $scroll['rank'],
        ]);
        exit;
    }

    /**
     * The officer's typed override first, then the recipient's own possessive pronoun
     * when a real player was picked (so the preview matches what a reprint would say),
     * otherwise 'their'.
     */
    private function requested_pronoun(): string
    {
        // The officer's correction wins over anything inferred. Per-print only —
        // nothing is written back to the profile. The control is free text, not
        // a closed vocabulary, so it goes through the same subject/object ->
        // possessive resolution a stored pronoun_freetext value gets — "he/him"
        // typed into the box is exactly as reachable as it is from a profile.
        $override = trim((string) ($_POST['pronounOverride'] ?? ''));
        if ($override !== '') {
            $this->load_model('Scrollery');

            return $this->Scrollery->resolve_pronoun_override($override);
        }

        $mundaneId = (int) ($_POST['playerId'] ?? 0);
        if ($mundaneId > 0) {
            $this->load_model('Scrollery');
            $resolved = $this->Scrollery->possessive_pronoun_for_player($mundaneId);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return 'their';
    }
}
