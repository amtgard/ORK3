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
                // Reads the $scope already resolved above rather than calling
                // heraldry_for(), which used to re-run scope_context() — a second
                // GetKingdomShortInfo/park_details plus GetHeraldryUrl round trip
                // per request — exactly as compose() already reads $scope['Heraldry'].
                'heraldry'    => (string) ($scope['Heraldry'] ?? ''),
                'issuerTitle' => (string) ($_POST['issuerTitle'] ?? ''),
                'useThe'      => ($_POST['useThe'] ?? '') === '1',
                'useOf'       => ($_POST['useOf'] ?? '') === '1',
                'variant'     => preg_replace('/[^a-z]/', '', strtolower((string) ($_POST['variant'] ?? ''))),
            ]
        );

        echo json_encode([
            'Status' => ['Status' => 0],
            'Html'   => Scroll::Render($scroll),
            'Rank'   => $scroll['rank'],
        ]);
        exit;
    }

    /**
     * The recipient's own possessive pronoun when a real player was picked, so the
     * preview matches what a reprint would say; otherwise whatever was typed.
     */
    private function requested_pronoun(): string
    {
        $mundaneId = (int) ($_POST['playerId'] ?? 0);
        if ($mundaneId > 0) {
            $this->load_model('Scrollery');
            $resolved = $this->Scrollery->possessive_pronoun_for_player($mundaneId);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $typed = trim((string) ($_POST['pronoun'] ?? ''));

        return $typed !== '' ? $typed : 'their';
    }
}
