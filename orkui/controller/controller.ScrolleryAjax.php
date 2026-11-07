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

    /**
     * The print log's write — called by Scrollery_render.tpl's print beacon when a run
     * is actually sent to the printer (beforeprint, the gated Print, auto-print), at
     * most once per page load. See Controller_Scrollery::print_log_config().
     *
     * POST: kind = awards | court | compose, csrf (or the X-CSRF-Token header), and
     * p[...] — the run's identity as the page rendered it.
     *
     * THE GATE IS RE-RUN HERE, the same one the page ran: what the beacon names is a
     * claim, never a grant. awards re-decides every id through
     * scrolls_for_awards_ids(); court re-checks Model_Court::can_manage(); compose
     * re-checks may_issue_for() and re-runs the composition. Only what passes is
     * written, deduped by ScrollPrintLog::RecordPrints().
     *
     * Identity is the session, behind a per-session CSRF token and a same-origin
     * check — or, for an award run only, a valid print token for exactly the id set
     * (Scroll::VerifyPrintToken()), which is itself an unguessable credential.
     */
    public function log_print()
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, no-store, max-age=0');

        $fail = static function (int $http, int $status, string $error): void {
            http_response_code($http);
            echo json_encode(['Status' => ['Status' => $status, 'Error' => $error]]);
            exit;
        };

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $fail(405, 1, 'POST required');
        }
        if (!$this->same_origin($_SERVER)) {
            $fail(403, 1, 'Cross-site request refused.');
        }

        $kind   = (string) ($_POST['kind'] ?? '');
        $params = is_array($_POST['p'] ?? null) ? $_POST['p'] : [];
        $str    = static fn (string $k): string => is_scalar($params[$k] ?? null) ? (string) $params[$k] : '';

        $this->load_model('Scrollery');

        // A print token identifies the viewer for an award run, in place of the session,
        // exactly as Controller_Scrollery::print_viewer() does for the page itself.
        $viewerId  = 0;
        $awardsIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $str('awards'))))));
        $token     = trim($str('token'));
        if ($kind === 'awards' && $token !== '' && $awardsIds !== []) {
            $verdict = $this->Scrollery->verify_print_token($token, $awardsIds);
            if (!empty($verdict['Valid'])) {
                $viewerId = (int) $verdict['ViewerId'];
            }
        }

        if ($viewerId <= 0) {
            $viewerId = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
            if ($viewerId <= 0) {
                $fail(401, 5, 'Not logged in');
            }
            $expected = (string) ($this->session->scrollery_print_csrf ?? '');
            $sent     = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
            if (strlen($expected) !== 64 || !hash_equals($expected, $sent)) {
                $fail(403, 9, 'Invalid or expired request token.');
            }
        }

        // The log is all that is left to do; release the session lock first.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $heraldry = $this->Scrollery->normalise_run_heraldry($str('heraldry'));
        $logged   = 0;

        if ($kind === 'awards') {
            $variant = preg_replace('/[^a-z]/', '', strtolower($str('variant')));
            $logged  = $this->Scrollery->log_award_prints($awardsIds, $viewerId, $variant, $heraldry);
        } elseif ($kind === 'court') {
            $courtId = (int) $str('court');
            $this->load_model('Court');
            $court = $courtId > 0 ? $this->Court->get_court_detail($courtId) : null;
            if (!is_array($court) || !$this->Court->can_manage($viewerId, $court['KingdomId'], $court['ParkId'])) {
                $fail(403, 5, 'Not authorised for that court');
            }
            $logged = $this->Scrollery->log_court_prints(
                $courtId,
                Scroll::CourtLineIds(['courtLines' => $str('courtLines')]),
                $viewerId,
                $heraldry
            );
        } elseif ($kind === 'compose') {
            $scopeType = $str('scopeType') === 'kingdom' ? 'kingdom' : 'park';
            $scopeId   = (int) $str('scopeId');
            if (!$this->Scrollery->may_issue_for($viewerId, $scopeType, $scopeId)) {
                $fail(403, 5, 'Not authorised for that park or kingdom');
            }
            $composeParams = [];
            foreach ($params as $k => $v) {
                if (is_string($k) && is_scalar($v)) {
                    $composeParams[$k] = (string) $v;
                }
            }
            $logged = $this->Scrollery->log_compose_print($scopeType, $scopeId, $composeParams, $viewerId);
        } else {
            $fail(400, 1, 'Unknown print kind');
        }

        echo json_encode(['Status' => ['Status' => 0], 'Logged' => $logged]);
        exit;
    }

    /**
     * Refuse a browser request that says it is cross-site (Sec-Fetch-Site) or whose
     * Origin host differs from Host. Requests that send neither header are allowed;
     * the CSRF token is the real check. Same rule as Reports' Population Explorer.
     */
    private function same_origin(array $server): bool
    {
        if (strtolower(trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? ''))) === 'cross-site') {
            return false;
        }
        $origin = trim((string) ($server['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $port = parse_url($origin, PHP_URL_PORT);

        return strtolower($host . ($port ? ':' . $port : '')) === strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
    }
}
