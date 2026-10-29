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

        $this->load_model('Scrollery');
        if (!$this->Scrollery->may_issue_for($viewerId, $scopeType, $scopeId)) {
            echo json_encode(['Status' => ['Status' => 5, 'Error' => 'Not authorised for that park or kingdom']]);
            exit;
        }

        // The whole composition (catalogue re-resolve, pronoun, heraldry source,
        // BuildScroll()) is the model's, shared with Scrollery::compose() so the
        // preview and the print it previews build the same scroll. Unlike compose(),
        // the preview does NOT refuse a blank custom title: the stage showing that
        // blank is how the officer learns the field is not optional.
        $composed = $this->Scrollery->compose_scroll($scopeType, $scopeId, $_POST, false);
        if ($composed['Scroll'] === null) {
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'That award has no certificate artwork']]);
            exit;
        }
        $scroll = $composed['Scroll'];

        echo json_encode([
            'Status' => ['Status' => 0],
            // Which sources have arms for this scope and recipient, and which one drew.
            'HeraldrySources' => $composed['HeraldrySources'],
            'HeraldrySource'  => $composed['HeraldrySource'],
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
            'Html'   => $this->Scrollery->render_scroll($scroll, Scroll::ART_DISPLAY),
            'Rank'   => $scroll['rank'],
            // True when match-style was asked for but drawn exact, because a colour is
            // too dark to survive a screen-blended pass (Scroll::BuildScroll decides).
            'MatchFallback' => (bool) ($scroll['customBorder']['matchFallback'] ?? false),
            // What the experiment's layout and stamp actually drew, after their fallbacks.
            'HeraldryLayout' => (string) $scroll['design']['heraldryLayout'],
            'HeraldryStamp'  => (bool) $scroll['design']['heraldryStamp'],
        ]);
        exit;
    }
}
