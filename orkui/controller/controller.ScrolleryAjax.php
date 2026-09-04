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

        $awardId = (int) ($_POST['awardId'] ?? 0);
        $order   = Scroll::OrderForAwardId($awardId);
        if ($order === null) {
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'That award has no certificate artwork']]);
            exit;
        }

        $scroll = Scroll::BuildScroll(
            [
                'AwardId'          => $awardId,
                'Rank'             => (int) ($_POST['rank'] ?? 0),
                'Date'             => (string) ($_POST['date'] ?? ''),
                'Note'             => (string) ($_POST['reason'] ?? ''),
                'GivenBy'          => (string) ($_POST['givenBy'] ?? ''),
                'KingdomAwardName' => (string) ($_POST['title'] ?? ''),
                'ParkName'         => $scopeType === 'park' ? (string) ($_POST['issuerName'] ?? '') : '',
                'KingdomName'      => $scopeType === 'kingdom' ? (string) ($_POST['issuerName'] ?? '') : '',
            ],
            [
                'player'      => (string) ($_POST['player'] ?? ''),
                'pronoun'     => $this->requested_pronoun(),
                'heraldry'    => $this->heraldry_for($scopeType, $scopeId),
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

    private function heraldry_for(string $scopeType, int $scopeId): string
    {
        $this->load_model('Scrollery');
        $scope = $this->Scrollery->scope_context($scopeType, $scopeId);

        return (string) ($scope['Heraldry'] ?? '');
    }
}
