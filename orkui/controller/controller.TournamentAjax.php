<?php

class Controller_TournamentAjax extends Controller
{
    /** Returns a compact JSON error string from a model response array. */
    private function modelError(array $r): string
    {
        $msg = $r['Error'] ?? 'Error';
        // Backend Detail can carry internal specifics; only expose it to authenticated
        // callers. Spectator (no-session) endpoints get a generic message. (#17)
        $det = isset($this->session->user_id) ? trim((string)($r['Detail'] ?? '')) : '';
        if ($det !== '' && $msg === ServiceErrorMessages::InvalidParameter) {
            // The generic "You have set a parameter incorrectly." prefix adds nothing when
            // a specific Detail explains the problem; show the Detail alone.
            return json_encode(['status' => $r['Status'], 'error' => $det]);
        }
        return json_encode(['status' => $r['Status'], 'error' => $det !== '' ? "$msg: $det" : $msg]);
    }

    /**
     * CSRF guard for state-changing (POST) tournament AJAX. (#12)
     *
     * This branch has no per-session CSRF token infrastructure (no CmsAjax _begin()
     * to mirror, and the spectator/organizer JS does not send an X-CSRF-Token header),
     * so we enforce same-origin via the Origin / Referer headers — the standard
     * token-less CSRF defense (OWASP). A browser-driven cross-site POST always carries
     * an Origin header, so this blocks the CSRF attack vector without breaking the
     * existing client. Requests with no Origin/Referer at all (e.g. curl tooling) are
     * allowed through, as they cannot be forged by a third-party site. Read-only GET
     * endpoints are unaffected. When an app-wide session CSRF token + header lands,
     * upgrade this to validate it.
     *
     * Returns true when the request may proceed; on rejection it emits JSON and
     * returns false (caller must exit).
     */
    private function csrfOk(): bool
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return true;
        }
        // Compare host WITHOUT the port: HTTP_HOST carries the port (e.g. "localhost:19080")
        // but parse_url(origin, PHP_URL_HOST) never does, so a raw compare would reject
        // every same-origin POST on any non-default port.
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
            $origin = (string)$_SERVER['HTTP_REFERER'];
        }
        // Cannot determine origin (non-browser tooling) — allow; a forging site
        // could not suppress the Origin header on a real cross-site POST.
        if ($origin === '' || $host === '') {
            return true;
        }
        $ohost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        if ($ohost !== '' && $ohost !== $host) {
            echo json_encode(['status' => 5, 'error' => 'Invalid request origin.']);
            return false;
        }
        return true;
    }

    /**
     * Ownership cross-check (#3): true when $bracket_id is one of the brackets owned
     * by $tournament_id. Prevents a caller authorized for one tournament from acting
     * on a bracket that actually belongs to another by supplying a mismatched id from
     * a different request location. The model methods re-verify ownership too — this
     * is dispatch-level defense in depth, resolved via the existing bracket list.
     */
    private function bracketBelongsTo(int $bracket_id, int $tournament_id): bool
    {
        if (!valid_id($bracket_id) || !valid_id($tournament_id)) {
            return false;
        }
        $r = $this->Tournament->get_brackets($tournament_id);
        if (($r['Status'] ?? 1) != 0) {
            return false;
        }
        foreach (($r['Detail'] ?? []) as $b) {
            if ((int)($b['BracketId'] ?? 0) === $bracket_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * GET/POST to tournament-level actions.
     * Route: TournamentAjax/tournament/{tournament_id}/{action}
     *
     * GET  brackets   — list brackets for a tournament
     * POST addbracket — add a new bracket
     */
    public function tournament($p = null)
    {
        header('Content-Type: application/json');
        if (!$this->csrfOk()) {
            exit;
        }
        $parts         = explode('/', $p ?? '');
        $tournament_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $action        = $parts[1] ?? '';

        if (!valid_id($tournament_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid tournament ID']);
            exit;
        }

        $this->load_model('Tournament');

        // ── Public, read-only actions (spectator) — no session required ──
        // These expose read-only data only; no mutation is reachable here.
        if ($action === 'version') {
            $r = $this->Tournament->get_version(['TournamentId' => $tournament_id]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'version' => $r['Detail']['Version'] ?? ''])
                : $this->modelError($r);
            exit;

        } elseif ($action === 'seq') {
            $known = (int)preg_replace('/[^0-9]/', '', $_GET['known'] ?? '0');
            $r = $this->Tournament->get_seq(['TournamentId' => $tournament_id, 'Known' => $known]);
            echo ($r['Status'] == 0)
                ? json_encode([
                    'status' => 0,
                    'seq'    => (int)($r['Detail']['Seq'] ?? 0),
                    'resync' => !empty($r['Detail']['Resync']),
                ])
                : $this->modelError($r);
            exit;

        } elseif ($action === 'changes') {
            $since = (int)preg_replace('/[^0-9]/', '', $_GET['since'] ?? '0');
            $r = $this->Tournament->get_changes(['TournamentId' => $tournament_id, 'Since' => $since]);
            if ($r['Status'] != 0) {
                echo $this->modelError($r);
                exit;
            }
            $events = $r['Detail']['Events'] ?? [];
            // Actor attribution (persona) is exposed only to logged-in callers; the
            // public spectator delta feed stays anonymous.
            if (!isset($this->session->user_id)) {
                foreach ($events as &$ev) {
                    $ev['ActorName'] = '';
                    $ev['ActorId'] = null;
                }
                unset($ev);
            }
            echo json_encode([
                'status' => 0,
                'resync' => !empty($r['Detail']['Resync']),
                'seq'    => (int)($r['Detail']['Seq'] ?? 0),
                'events' => $events,
            ]);
            exit;

        } elseif ($action === 'brackets') {
            $r = $this->Tournament->get_brackets($tournament_id);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'brackets' => $r['Detail'] ?? []])
                : $this->modelError($r);
            exit;

        } elseif ($action === 'standings') {
            // Fresh standings for every bracket that has matches (mirrors the page
            // controller's load), plus the points config and bracket meta so the
            // client can rebuild the Standings tab without a reload.
            $br = $this->Tournament->get_brackets($tournament_id);
            if ($br['Status'] != 0) {
                echo $this->modelError($br);
                exit;
            }
            $standings   = [];
            $bracketMeta = [];
            foreach (($br['Detail'] ?? []) as $b) {
                $bid = (int)($b['BracketId'] ?? 0);
                if ($bid <= 0) {
                    continue;
                }
                $bracketMeta[] = [
                    'BracketId' => $bid,
                    'Style'     => $b['Style']  ?? '',
                    'Method'    => $b['Method'] ?? '',
                    'StyleNote' => $b['StyleNote'] ?? '',
                    'Status'    => $b['Status'] ?? '',
                ];
                // Points brackets have no matches (scores live in point_score), so include
                // them once they are underway regardless of match count.
                $isLivePoints = ($b['Method'] ?? '') === 'points'
                    && in_array($b['Status'] ?? '', ['active', 'complete', 'finalized'], true);
                if (!$isLivePoints) {
                    $mr = $this->Tournament->get_matches(['BracketId' => $bid]);
                    if ($mr['Status'] != 0 || empty($mr['Detail'])) {
                        continue;
                    }
                }
                $sr = $this->Tournament->get_standings($bid);
                if ($sr['Status'] == 0) {
                    $standings[$bid] = $sr['Detail'] ?? [];
                }
            }
            $pr = $this->Tournament->get_standings_points($tournament_id);
            echo json_encode([
                'status'    => 0,
                'standings' => (object)$standings,
                'points'    => ($pr['Status'] == 0) ? ($pr['Detail'] ?? []) : [],
                'brackets'  => $bracketMeta,
            ]);
            exit;
        }

        // ── All other tournament actions require a logged-in session ──
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        // Ownership cross-check (#3): any BracketId supplied in a tournament-level POST
        // must belong to the tournament named in the route.
        $post_bid = (int)($_POST['BracketId'] ?? 0);
        if ($post_bid > 0 && !$this->bracketBelongsTo($post_bid, $tournament_id)) {
            echo json_encode(['status' => 1, 'error' => 'Bracket does not belong to this tournament.']);
            exit;
        }

        if ($action === 'reeves') {
            $r = $this->Tournament->get_reeves([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'reeves' => $r['Detail'] ?? []])
                : $this->modelError($r);

        } elseif ($action === 'addreeve') {
            $mundaneId = (int)($_POST['MundaneId'] ?? 0);
            $role      = trim($_POST['Role'] ?? '');
            if (!valid_id($mundaneId)) {
                echo json_encode(['status' => 1, 'error' => 'MundaneId required.']);
                exit;
            }
            if (!in_array($role, ['organizer', 'bracket_runner'], true)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid role.']);
                exit;
            }
            $r = $this->Tournament->add_reeve([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'MundaneId'    => $mundaneId,
                'Role'         => $role,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'mundaneId' => $mundaneId, 'role' => $role])
                : $this->modelError($r);

        } elseif ($action === 'removereeve') {
            $mundaneId = (int)($_POST['MundaneId'] ?? 0);
            if (!valid_id($mundaneId)) {
                echo json_encode(['status' => 1, 'error' => 'MundaneId required.']);
                exit;
            }
            $r = $this->Tournament->remove_reeve([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'MundaneId'    => $mundaneId,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'mundaneId' => $mundaneId])
                : $this->modelError($r);

        } elseif ($action === 'presence') {
            // Ephemeral reeve presence heartbeat (#36): records that this reeve is
            // viewing $bid and returns the roster of currently-present reeves so the
            // client can render an "others here" badge. BracketId 0 = no bracket selected.
            $bid = (int)($_POST['BracketId'] ?? 0);
            $r = $this->Tournament->reeve_presence([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bid,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'presence' => $r['Detail']['presence'] ?? []])
                : $this->modelError($r);

        } elseif ($action === 'addbracket') {
            $style = trim($_POST['Style']  ?? '');
            $method = trim($_POST['Method'] ?? '');
            if (!strlen($style) || !strlen($method)) {
                echo json_encode(['status' => 1, 'error' => 'Style and method are required.']);
                exit;
            }
            $allowed_methods = ['single','double','swiss','round-robin','ironman','points'];
            if (!in_array($method, $allowed_methods, true)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid bracket method.']);
                exit;
            }
            $r = $this->Tournament->add_bracket([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'Style'        => $style,
                'StyleNote'    => trim($_POST['StyleNote']   ?? ''),
                'Method'       => $method,
                'Rings'        => max(1, min(20, (int)($_POST['Rings'] ?? 1))),
                'Participants' => trim($_POST['Participants'] ?? 'individual'),
                'Seeding'         => trim($_POST['Seeding']         ?? 'random'),
                'DurationMinutes' => max(0, (int)($_POST['DurationMinutes'] ?? 0)),
                'BestOf'          => (int)($_POST['BestOf'] ?? 1),
                'PointRounds'    => (int)($_POST['PointRounds'] ?? 0),
                'PointMode'      => trim($_POST['PointMode'] ?? ''),
                'PointScale'     => trim($_POST['PointScale'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => (int)($r['Detail'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'quickbracket') {
            $r = $this->Tournament->create_quick_bracket([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'Method'       => trim($_POST['Method'] ?? ''),
                'DrawSize'     => (int)($_POST['DrawSize'] ?? 0),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => (int)($r['Detail'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'generate') {
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $r = $this->Tournament->generate_matches([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bracket_id,
                'ActionId'     => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => (int)($r['Detail'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'updatebracket') {
            // Manage-permission check first, so a non-manager gets NoAuthorization rather
            // than field-validation feedback. UpdateBracket re-checks it in the lib.
            $auth = $this->Tournament->auth_check([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
            ]);
            if ($auth['Status'] != 0) {
                echo $this->modelError($auth);
                exit;
            }
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $style = trim($_POST['Style'] ?? '');
            $method = trim($_POST['Method'] ?? '');
            if (!strlen($style) || !strlen($method)) {
                echo json_encode(['status' => 1, 'error' => 'Style and method are required.']);
                exit;
            }
            $allowed_methods = ['single','double','swiss','round-robin','ironman','points'];
            if (!in_array($method, $allowed_methods, true)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid bracket method.']);
                exit;
            }
            $r = $this->Tournament->update_bracket(array_merge([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bracket_id,
                'Style'        => $style,
                'StyleNote'    => trim($_POST['StyleNote']   ?? ''),
                'Method'       => $method,
                'Rings'        => max(1, min(20, (int)($_POST['Rings'] ?? 1))),
                'Participants' => trim($_POST['Participants'] ?? 'individual'),
                'Seeding'         => trim($_POST['Seeding']         ?? 'random'),
                'DurationMinutes' => max(0, (int)($_POST['DurationMinutes'] ?? 0)),
                'BestOf'          => (int)($_POST['BestOf'] ?? 1),
                'PointRounds'    => (int)($_POST['PointRounds'] ?? 0),
                'PointMode'      => trim($_POST['PointMode'] ?? ''),
                'PointScale'     => trim($_POST['PointScale'] ?? ''),
                'ActionId'       => trim($_POST['ActionId'] ?? ''),
            // Only forward FirstRoundMode when the client actually sent it, so an edit
            // that didn't offer the control leaves the stored value untouched.
            ], isset($_POST['FirstRoundMode']) ? ['FirstRoundMode' => trim($_POST['FirstRoundMode'])] : []));
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => (int)($r['Detail'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'confirmationmatch') {
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $r = $this->Tournament->create_confirmation_match([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bracket_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'matchId' => (int)($r['Detail'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'tiebreakerfor3rd') {
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $r = $this->Tournament->create_tiebreaker_match([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bracket_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'matchId' => (int)($r['Detail'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'roundrobintiebreaker') {
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $r = $this->Tournament->create_round_robin_tiebreaker([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bracket_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'detail' => $r['Detail']])
                : $this->modelError($r);

        } elseif ($action === 'roundrobindecline') {
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $r = $this->Tournament->decline_round_robin_tiebreaker([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bracket_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => $bracket_id])
                : $this->modelError($r);

        } elseif ($action === 'completebracket') {
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $r = $this->Tournament->complete_bracket([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bracket_id,
                'WaiveReset'   => (int)($_POST['WaiveReset'] ?? 0),
                'ActionId'     => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => $bracket_id])
                : $this->modelError($r);


        } elseif ($action === 'deletebracket') {
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $r = $this->Tournament->delete_bracket([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bracket_id,
                'ActionId'     => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => $bracket_id])
                : $this->modelError($r);

        } elseif ($action === 'savestandingspoints') {
            $points_raw = trim($_POST['Points'] ?? '');
            if ($points_raw === '') {
                echo json_encode(['status' => 1, 'error' => 'Points data is required.']);
                exit;
            }
            $points_arr = json_decode($points_raw, true);
            if (!is_array($points_arr)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid points data.']);
                exit;
            }
            $r = $this->Tournament->save_standings_points([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'Points'       => $points_arr,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'points' => $r['Detail'] ?? []])
                : $this->modelError($r);

        } elseif ($action === 'savepointscore') {
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $participant_id = (int)($_POST['ParticipantId'] ?? 0);
            $round = (int)($_POST['Round'] ?? 0);
            $points = (isset($_POST['Points']) && $_POST['Points'] !== '') ? trim($_POST['Points']) : null;
            $actionId = trim($_POST['ActionId'] ?? '');
            $r = $this->Tournament->save_point_score([
                'Token'         => $this->session->token,
                'TournamentId'  => $tournament_id,
                'BracketId'     => $bracket_id,
                'ParticipantId' => $participant_id,
                'Round'         => $round,
                'Points'        => $points,
                'ActionId'      => $actionId,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'detail' => $r['Detail'], 'seq' => (int)($r['Detail']['Seq'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'addpointsround') {
            $bracket_id = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($bracket_id)) {
                echo json_encode(['status' => 1, 'error' => 'BracketId required.']);
                exit;
            }
            $actionId = trim($_POST['ActionId'] ?? '');
            $r = $this->Tournament->add_points_round([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'BracketId'    => $bracket_id,
                'ActionId'     => $actionId,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'detail' => $r['Detail'], 'seq' => (int)($r['Detail']['Seq'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'updatetournament') {
            $name = trim($_POST['Name'] ?? '');
            if (!strlen($name)) {
                echo json_encode(['status' => 1, 'error' => 'Name is required.']);
                exit;
            }
            $r = $this->Tournament->update_tournament([
                'Token'                 => $this->session->token,
                'TournamentId'          => $tournament_id,
                'Name'                  => $name,
                'Description'           => trim($_POST['Description'] ?? ''),
                'Url'                   => trim($_POST['Url']         ?? ''),
                'When'                  => trim($_POST['When']        ?? ''),
                'ParkId'                => (int)($_POST['ParkId']                ?? 0),
                'KingdomId'             => (int)($_POST['KingdomId']             ?? 0),
                'EventCalendarDetailId' => (int)($_POST['EventCalendarDetailId'] ?? 0),
                'ActionId'              => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'tournamentId' => $tournament_id])
                : $this->modelError($r);

        } elseif ($action === 'copybracket') {
            $source_bid = (int)($_POST['BracketId'] ?? 0);
            if (!valid_id($source_bid)) {
                echo json_encode(['status' => 1, 'error' => 'Source BracketId required.']);
                exit;
            }
            $r = $this->Tournament->add_bracket([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'CopyOfId'     => $source_bid,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => (int)($r['Detail'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'registrants') {
            // Tournament-level roster: registered participants (bracket_id IS NULL),
            // each decorated with the brackets they are currently assigned to.
            $r = $this->Tournament->get_registrants([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'registrants' => $r['Detail'] ?? []])
                : $this->modelError($r);

        } elseif ($action === 'register') {
            // Register an individual at the tournament level (no bracket required).
            $alias     = trim($_POST['Alias']      ?? '');
            $mundaneId = (int)($_POST['MundaneId'] ?? 0);
            if (!strlen($alias) && !valid_id($mundaneId)) {
                echo json_encode(['status' => 1, 'error' => 'An Alias or player is required.']);
                exit;
            }
            $r = $this->Tournament->register_participant([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'Alias'        => $alias,
                'MundaneId'    => $mundaneId,
                'UnitId'       => (int)($_POST['UnitId']    ?? 0),
                'ParkId'       => (int)($_POST['ParkId']    ?? 0),
                'KingdomId'    => (int)($_POST['KingdomId'] ?? 0),
            ]);
            if ($r['Status'] == 0) {
                $detail = $r['Detail'];
                echo json_encode([
                    'status'            => 0,
                    'participantNumber' => is_array($detail) ? (int)($detail['ParticipantNumber'] ?? 0) : 0,
                    'registrationId'    => is_array($detail) ? (int)($detail['RegistrationId'] ?? 0) : (int)$detail,
                    'alreadyRegistered' => is_array($detail) && !empty($detail['AlreadyRegistered']),
                ]);
            } else {
                echo $this->modelError($r);
            }

        } elseif ($action === 'registrationstatus') {
            $participant_number = (int)($_POST['ParticipantNumber'] ?? 0);
            if ($participant_number <= 0) {
                echo json_encode(['status' => 1, 'error' => 'ParticipantNumber required.']);
                exit;
            }
            $r = $this->Tournament->update_registration_status([
                'Token'             => $this->session->token,
                'TournamentId'      => $tournament_id,
                'ParticipantNumber' => $participant_number,
                'Status'            => trim($_POST['Status'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'participantNumber' => $participant_number, 'newStatus' => trim($_POST['Status'] ?? '')])
                : $this->modelError($r);

        } elseif ($action === 'removeregistrant') {
            $participant_number = (int)($_POST['ParticipantNumber'] ?? 0);
            if ($participant_number <= 0) {
                echo json_encode(['status' => 1, 'error' => 'ParticipantNumber required.']);
                exit;
            }
            $r = $this->Tournament->remove_registrant([
                'Token'             => $this->session->token,
                'TournamentId'      => $tournament_id,
                'ParticipantNumber' => $participant_number,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'participantNumber' => $participant_number])
                : $this->modelError($r);

        } elseif ($action === 'registeredteams') {
            // Tournament-level team roster: registered teams (bracket_id IS NULL),
            // each with members and the brackets they are assigned to.
            $r = $this->Tournament->get_registered_teams([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'teams' => $r['Detail'] ?? []])
                : $this->modelError($r);

        } elseif ($action === 'createteam' || $action === 'updateteam') {
            $name = trim($_POST['Name'] ?? '');
            if (!strlen($name)) {
                echo json_encode(['status' => 1, 'error' => 'Team name required.']);
                exit;
            }
            $membersJson = $_POST['Members'] ?? '';
            $members = [];
            if ($membersJson !== '') {
                $decoded = json_decode($membersJson, true);
                if (is_array($decoded)) {
                    if (count($decoded) > 64) {
                        echo json_encode(['status' => 1, 'error' => 'Too many team members.']);
                        exit;
                    }
                    foreach ($decoded as $m) {
                        if (is_array($m) && valid_id($m['MundaneId'] ?? 0)) {
                            $members[] = ['MundaneId' => (int)$m['MundaneId']];
                        }
                    }
                }
            }
            $params = [
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'Name'         => $name,
                'Members'      => $members,
            ];
            if ($action === 'updateteam') {
                $tn = (int)($_POST['TeamNumber'] ?? 0);
                if ($tn <= 0) {
                    echo json_encode(['status' => 1, 'error' => 'TeamNumber required.']);
                    exit;
                }
                $params['TeamNumber'] = $tn;
                $r = $this->Tournament->update_team($params);
            } else {
                $r = $this->Tournament->register_team($params);
            }
            echo ($r['Status'] == 0)
                ? json_encode(array_merge(['status' => 0], is_array($r['Detail']) ? $r['Detail'] : []))
                : $this->modelError($r);

        } elseif ($action === 'removeteam') {
            $tn = (int)($_POST['TeamNumber'] ?? 0);
            if ($tn <= 0) {
                echo json_encode(['status' => 1, 'error' => 'TeamNumber required.']);
                exit;
            }
            $r = $this->Tournament->remove_registered_team([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'TeamNumber'   => $tn,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'teamNumber' => $tn])
                : $this->modelError($r);

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    /**
     * GET/POST to bracket-level actions.
     * Route: TournamentAjax/bracket/{bracket_id}/{action}
     *
     * GET  participants    — list participants for a bracket
     * GET  matches         — list matches for a bracket
     * POST addparticipant  — add a participant to a bracket
     */
    public function bracket($p = null)
    {
        header('Content-Type: application/json');
        if (!$this->csrfOk()) {
            exit;
        }
        $parts      = explode('/', $p ?? '');
        $bracket_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $action     = $parts[1] ?? '';

        if (!valid_id($bracket_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid bracket ID']);
            exit;
        }

        $this->load_model('Tournament');

        // ── Public, read-only actions (spectator) — no session required ──
        // These expose read-only data only; no mutation is reachable here.
        if ($action === 'participants') {
            $r = $this->Tournament->get_participants(['BracketId' => $bracket_id]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'participants' => $r['Detail'] ?? []])
                : $this->modelError($r);
            exit;

        } elseif ($action === 'matches') {
            $r = $this->Tournament->get_matches(['BracketId' => $bracket_id]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'matches' => $r['Detail'] ?? []])
                : $this->modelError($r);
            exit;

        } elseif ($action === 'pointstandings') {
            // Points-bracket per-cell scores for collab sync (same data the profile page renders publicly).
            $r = $this->Tournament->get_point_standings(['BracketId' => $bracket_id]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'standings' => $r['Detail'] ?? []])
                : $this->modelError($r);
            exit;
        }

        // ── All other bracket actions require a logged-in session ──
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        // Ownership cross-check (#3): the route bracket must belong to the tournament
        // supplied in the POST body (a missing/invalid TournamentId is rejected per-action).
        $post_tid = (int)($_POST['TournamentId'] ?? 0);
        if (valid_id($post_tid) && !$this->bracketBelongsTo($bracket_id, $post_tid)) {
            echo json_encode(['status' => 1, 'error' => 'Bracket does not belong to this tournament.']);
            exit;
        }

        if ($action === 'addparticipant') {
            $alias     = trim($_POST['Alias']        ?? '');
            $mundaneId = (int)($_POST['MundaneId']   ?? 0);
            $tid       = (int)($_POST['TournamentId'] ?? 0);

            if (!strlen($alias)) {
                echo json_encode(['status' => 1, 'error' => 'Alias is required.']);
                exit;
            }
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid tournament ID.']);
                exit;
            }

            $params = [
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'Alias'        => $alias,
                'MundaneId'    => $mundaneId,
                'UnitId'       => (int)($_POST['UnitId']    ?? 0),
                'ParkId'       => (int)($_POST['ParkId']    ?? 0),
                'KingdomId'    => (int)($_POST['KingdomId'] ?? 0),
            ];

            // Team participant: pass Members array of {MundaneId} objects
            $membersJson = $_POST['Members'] ?? '';
            if ($membersJson !== '') {
                $members = json_decode($membersJson, true);
                if (is_array($members) && count($members) > 0) {
                    // Safety cap on roster size (NOT a game rule, just an abuse bound).
                    if (count($members) > 64) {
                        echo json_encode(['status' => 1, 'error' => 'Too many team members.']);
                        exit;
                    }
                    $cleanMembers = [];
                    foreach ($members as $m) {
                        if (is_array($m) && valid_id($m['MundaneId'] ?? 0)) {
                            $cleanMembers[] = ['MundaneId' => (int)$m['MundaneId']];
                        }
                    }
                    if (count($cleanMembers) === 0) {
                        echo json_encode(['status' => 1, 'error' => 'No valid team members.']);
                        exit;
                    }
                    $params['Members'] = $cleanMembers;
                }
            }

            $r = $this->Tournament->add_participant($params);
            if ($r['Status'] == 0) {
                $detail = $r['Detail'];
                $new_pid  = is_array($detail) ? (int)($detail['ParticipantId'] ?? 0) : (int)$detail;
                $new_pnum = is_array($detail) ? (int)($detail['ParticipantNumber'] ?? 0) : 0;
                echo json_encode(['status' => 0, 'participantId' => $new_pid, 'participantNumber' => $new_pnum]);
            } else {
                echo $this->modelError($r);
            }

        } elseif ($action === 'quickplace') {
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $r = $this->Tournament->quick_place([
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'Seed'         => (int)($_POST['Seed'] ?? 0),
                'MundaneId'    => (int)($_POST['MundaneId'] ?? 0),
                'Alias'        => trim($_POST['Alias'] ?? ''),
                'ActionId'     => trim($_POST['ActionId'] ?? ''),
            ]);
            if ($r['Status'] == 0) {
                $d = is_array($r['Detail'] ?? null) ? $r['Detail'] : [];
                echo json_encode([
                    'status'            => 0,
                    'participantId'     => (int)($d['ParticipantId'] ?? 0),
                    'participantNumber' => (int)($d['ParticipantNumber'] ?? 0),
                    'seq'               => (int)($d['Seq'] ?? 0),
                ]);
            } else {
                echo $this->modelError($r);
            }

        } elseif ($action === 'quickstart') {
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $r = $this->Tournament->start_quick_bracket([
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'ActionId'     => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => $bracket_id])
                : $this->modelError($r);

        } elseif ($action === 'removeparticipant') {
            $participant_id = (int)($_POST['ParticipantId'] ?? 0);
            if (!valid_id($participant_id)) {
                echo json_encode(['status' => 1, 'error' => 'ParticipantId required.']);
                exit;
            }
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $r = $this->Tournament->remove_participant([
                'Token'         => $this->session->token,
                'TournamentId'  => $tid,
                'ParticipantId' => $participant_id,
                'ActionId'      => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'participantId' => $participant_id])
                : $this->modelError($r);

        } elseif ($action === 'clearmatches') {
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $r = $this->Tournament->clear_bracket_matches([
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'ActionId'     => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : $this->modelError($r);

        } elseif ($action === 'ironmanwin') {
            $winner_id   = (int)($_POST['WinnerId']    ?? 0);
            $tid         = (int)($_POST['TournamentId'] ?? 0);
            $ring_number = max(1, min(8, (int)($_POST['RingNumber'] ?? 1)));
            if (!valid_id($winner_id)) {
                echo json_encode(['status' => 1, 'error' => 'WinnerId required.']);
                exit;
            }
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $actionId = trim($_POST['ActionId'] ?? '');
            $r = $this->Tournament->record_ironman_win([
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'WinnerId'     => $winner_id,
                'RingNumber'   => $ring_number,
                'ActionId'     => $actionId,
            ]);
            $detail = is_array($r['Detail']) ? $r['Detail'] : ['FightNum' => (int)($r['Detail'] ?? 0)];
            echo ($r['Status'] == 0)
                ? json_encode(array_merge(['status' => 0], $detail, ['seq' => (int)($detail['Seq'] ?? 0)]))
                : $this->modelError($r);

        } elseif ($action === 'poolstobrackets') {
            // Create a single/double-elim playoff from the top X of this Ironman's pool.
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $r = $this->Tournament->pools_to_bracket([
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'Method'       => trim($_POST['Method'] ?? ''),
                'TopX'         => (int)($_POST['TopX'] ?? 0),
                'SeedMethod'   => trim($_POST['SeedMethod'] ?? 'standing'),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'bracketId' => (int)($r['Detail'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'reorder') {
            // Update seed order for participants (Phase 7 — drag-drop)
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $order_json = trim($_POST['Order'] ?? '');
            $order_arr  = json_decode($order_json, true);
            if (!is_array($order_arr)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid order data.']);
                exit;
            }
            $r = $this->Tournament->reorder_seeds([
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'Order'        => $order_arr,
                'ActionId'     => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : $this->modelError($r);

        } elseif ($action === 'updateparticipantstatus') {
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $participant_id = (int)($_POST['ParticipantId'] ?? 0);
            if (!valid_id($participant_id)) {
                echo json_encode(['status' => 1, 'error' => 'ParticipantId required.']);
                exit;
            }
            $actionId = trim($_POST['ActionId'] ?? '');
            $r = $this->Tournament->update_participant_status([
                'Token'         => $this->session->token,
                'TournamentId'  => $tid,
                'BracketId'     => $bracket_id,
                'ParticipantId' => $participant_id,
                'Status'        => trim($_POST['Status'] ?? ''),
                'Mode'          => trim($_POST['Mode'] ?? ''),
                'ActionId'      => $actionId,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'participantId' => (int)($r['Detail']['ParticipantId'] ?? $participant_id), 'newStatus' => $r['Detail']['Status'] ?? '', 'seq' => (int)($r['Detail']['Seq'] ?? 0)])
                : $this->modelError($r);

        } elseif ($action === 'updatealias') {
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $participant_id = (int)($_POST['ParticipantId'] ?? 0);
            if (!valid_id($participant_id)) {
                echo json_encode(['status' => 1, 'error' => 'ParticipantId required.']);
                exit;
            }
            $r = $this->Tournament->update_alias([
                'Token'         => $this->session->token,
                'TournamentId'  => $tid,
                'BracketId'     => $bracket_id,
                'ParticipantId' => $participant_id,
                'Alias'         => trim($_POST['Alias'] ?? ''),
                'ActionId'      => trim($_POST['ActionId'] ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'participantId' => (int)($r['Detail']['ParticipantId'] ?? $participant_id), 'alias' => $r['Detail']['Alias'] ?? ''])
                : $this->modelError($r);

        } elseif ($action === 'assign') {
            // Bulk-assign tournament registrants to this bracket (setup brackets only).
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $nums_json = trim($_POST['ParticipantNumbers'] ?? '');
            $nums_arr  = json_decode($nums_json, true);
            if (!is_array($nums_arr) || count($nums_arr) === 0) {
                echo json_encode(['status' => 1, 'error' => 'ParticipantNumbers required.']);
                exit;
            }
            if (count($nums_arr) > 500) {
                echo json_encode(['status' => 1, 'error' => 'Too many participants.']);
                exit;
            }
            $nums_arr = array_values(array_filter(array_map('intval', $nums_arr), fn ($n) => $n > 0));
            $r = $this->Tournament->assign_to_bracket([
                'Token'              => $this->session->token,
                'TournamentId'       => $tid,
                'BracketId'          => $bracket_id,
                'ParticipantNumbers' => $nums_arr,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'assigned' => $r['Detail']['Assigned'] ?? []])
                : $this->modelError($r);

        } elseif ($action === 'unassign') {
            // Bulk-remove registrants from this bracket (setup brackets only).
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $nums_json = trim($_POST['ParticipantNumbers'] ?? '');
            $nums_arr  = json_decode($nums_json, true);
            if (!is_array($nums_arr) || count($nums_arr) === 0) {
                echo json_encode(['status' => 1, 'error' => 'ParticipantNumbers required.']);
                exit;
            }
            if (count($nums_arr) > 500) {
                echo json_encode(['status' => 1, 'error' => 'Too many participants.']);
                exit;
            }
            $nums_arr = array_values(array_filter(array_map('intval', $nums_arr), fn ($n) => $n > 0));
            $r = $this->Tournament->unassign_from_bracket([
                'Token'              => $this->session->token,
                'TournamentId'       => $tid,
                'BracketId'          => $bracket_id,
                'ParticipantNumbers' => $nums_arr,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : $this->modelError($r);

        } elseif ($action === 'assignteams' || $action === 'unassignteams') {
            // Bulk-assign/remove registered teams to/from this team bracket (setup only).
            $tid = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tid)) {
                echo json_encode(['status' => 1, 'error' => 'TournamentId required.']);
                exit;
            }
            $nums_arr = json_decode(trim($_POST['TeamNumbers'] ?? ''), true);
            if (!is_array($nums_arr) || count($nums_arr) === 0) {
                echo json_encode(['status' => 1, 'error' => 'TeamNumbers required.']);
                exit;
            }
            if (count($nums_arr) > 500) {
                echo json_encode(['status' => 1, 'error' => 'Too many teams.']);
                exit;
            }
            $nums_arr = array_values(array_filter(array_map('intval', $nums_arr), fn ($n) => $n > 0));
            $payload = [
                'Token'        => $this->session->token,
                'TournamentId' => $tid,
                'BracketId'    => $bracket_id,
                'TeamNumbers'  => $nums_arr,
            ];
            if ($action === 'assignteams') {
                $r = $this->Tournament->assign_team_to_bracket($payload);
                echo ($r['Status'] == 0)
                    ? json_encode(['status' => 0, 'assigned' => $r['Detail']['Assigned'] ?? []])
                    : $this->modelError($r);
            } else {
                $r = $this->Tournament->unassign_team_from_bracket($payload);
                echo ($r['Status'] == 0)
                    ? json_encode(['status' => 0])
                    : $this->modelError($r);
            }

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    /**
     * POST match result.
     * Route: TournamentAjax/match/{match_id}/{tournament_id}
     *
     * POST result — record a match result and advance bracket
     */
    public function match($p = null)
    {
        header('Content-Type: application/json');
        if (!$this->csrfOk()) {
            exit;
        }
        $parts         = explode('/', $p ?? '');
        $match_id      = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $tournament_id = (int)preg_replace('/[^0-9]/', '', $parts[1] ?? '');
        $action        = $parts[2] ?? '';

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        if (!valid_id($match_id) || !valid_id($tournament_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid match or tournament ID']);
            exit;
        }

        $this->load_model('Tournament');

        if ($action === 'reset') {
            $actionId = trim($_POST['ActionId'] ?? '');
            $r = $this->Tournament->reset_match([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
                'MatchId'      => $match_id,
                'ActionId'     => $actionId,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'matchId' => $match_id, 'seq' => (int)(is_array($r['Detail'] ?? null) ? ($r['Detail']['Seq'] ?? 0) : 0)])
                : $this->modelError($r);
            exit;
        }

        $result = trim($_POST['Result'] ?? '');
        $score  = trim($_POST['Score']  ?? '');
        $bouts    = trim($_POST['Bouts']    ?? '[]');
        $actionId = trim($_POST['ActionId'] ?? '');

        $allowed_results = ['1-wins', '2-wins', 'tie', 'forfeit', 'disqualified', '1-forfeits', '2-forfeits', '1-is-disqualified', '2-is-disqualified'];
        if (!in_array($result, $allowed_results, true)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid result value']);
            exit;
        }

        $r = $this->Tournament->post_match_result([
            'Token'        => $this->session->token,
            'TournamentId' => $tournament_id,
            'MatchId'      => $match_id,
            'Result'       => $result,
            'Score'        => $score,
            'Bouts'        => $bouts,
            'ActionId'     => $actionId,
        ]);
        echo ($r['Status'] == 0)
            ? json_encode(['status' => 0, 'matchId' => $match_id, 'seq' => (int)($r['Detail']['Seq'] ?? 0)])
            : $this->modelError($r);
        exit;
    }

    /**
     * Park autocomplete search.
     * Route: TournamentAjax/parksearch?q={term}
     */
    public function parksearch($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode([]);
            exit;
        }
        $this->load_model('Tournament');
        $r = $this->Tournament->search_parks($q);
        echo json_encode(($r['Status'] == 0) ? ($r['Detail'] ?? []) : []);
        exit;
    }

    /**
     * Event autocomplete search.
     * Route: TournamentAjax/eventsearch&q={term}&KingdomId={kid}
     * KingdomId (optional) scopes results to that kingdom's events.
     */
    public function eventsearch($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode([]);
            exit;
        }
        $this->load_model('Tournament');
        $r = $this->Tournament->search_events($q, (int)($_GET['KingdomId'] ?? 0));
        echo json_encode(($r['Status'] == 0) ? ($r['Detail'] ?? []) : []);
        exit;
    }

}
