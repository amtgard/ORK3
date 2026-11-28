<?php

/**
 * JSON endpoints behind the Circle of Knights Manager page.
 *
 * GET : roster, board, candidate, admins
 * POST: candidate_add, candidate_move, note_add, note_delete, config_save,
 *       gmok_set, deputy_add, admin_remove
 *
 * Every action takes the kingdom as the route segment (KnightCircleAjax/roster/{kid})
 * or a KingdomId parameter; every parameter is optional so a bare request answers
 * with an error envelope rather than a 500.
 *
 * Envelope: {status: 0, ...} ok; {status: 1, error} bad request;
 * {status: 3, error} not authorized; {status: 5, error} not logged in.
 * Authority is decided in the domain; this layer only reads the request and session.
 */
class Controller_KnightCircleAjax extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('KnightCircle');
    }

    // --- reads (GET) ---------------------------------------------------------

    public function roster($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->KnightCircle->roster($uid, $this->kingdomId($p), (int) ($_GET['OrderAwardId'] ?? 0));
        $this->envelope($r, [
            'knights' => $r['Knights'] ?? [], 'summary' => $r['Summary'] ?? new stdClass(),
            'config' => $r['Config'] ?? new stdClass(), 'windowStart' => $r['WindowStart'] ?? '',
        ]);
    }

    /** The pipeline. The domain reconciles first, so this is also the refresh button. */
    public function board($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->KnightCircle->board($uid, $this->kingdomId($p));
        $this->envelope($r, [
            'stages' => $r['Stages'] ?? new stdClass(), 'suggestions' => $r['Suggestions'] ?? [],
            'reconciled' => $r['Reconciled'] ?? null,
        ]);
    }

    public function candidate($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->KnightCircle->candidate($uid, (int) ($_GET['CandidateId'] ?? $p ?? 0));
        $this->envelope($r, [
            'candidate' => $r['Candidate'] ?? new stdClass(), 'history' => $r['History'] ?? [],
            'notes' => $r['Notes'] ?? [], 'facts' => $r['Facts'] ?? new stdClass(),
        ]);
    }

    public function admins($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->KnightCircle->admins($uid, $this->kingdomId($p));
        $this->envelope($r, ['admins' => $r['Admins'] ?? []]);
    }

    // --- writes (POST only) --------------------------------------------------

    public function candidate_add($p = null)
    {
        $uid = $this->requireLogin();
        $this->requirePost();
        $stage = (string) ($_POST['Stage'] ?? 'watchlist');
        $stage = in_array($stage, ['watchlist', 'dismissed'], true) ? $stage : 'watchlist';
        $source = (string) ($_POST['Source'] ?? 'manual');
        $source = in_array($source, ['manual', 'suggested'], true) ? $source : 'manual';
        $r = $this->KnightCircle->add_candidate(
            $uid, $this->kingdomId($p), (int) ($_POST['MundaneId'] ?? 0), (int) ($_POST['OrderAwardId'] ?? 0),
            (string) ($_POST['Comment'] ?? ''), $stage, $source
        );
        $this->envelope($r, ['candidateId' => (int) ($r['CandidateId'] ?? 0)]);
    }

    public function candidate_move($p = null)
    {
        $uid = $this->requireLogin();
        $this->requirePost();
        $votes = null;
        if (isset($_POST['VotesFor']) || isset($_POST['VotesAgainst']) || isset($_POST['VotesAbstain'])) {
            $votes = [
                'For'     => max(0, (int) ($_POST['VotesFor'] ?? 0)),
                'Against' => max(0, (int) ($_POST['VotesAgainst'] ?? 0)),
                'Abstain' => max(0, (int) ($_POST['VotesAbstain'] ?? 0)),
            ];
        }
        $r = $this->KnightCircle->move_candidate(
            $uid, (int) ($_POST['CandidateId'] ?? 0), (string) ($_POST['ToStage'] ?? ''), (string) ($_POST['Comment'] ?? ''), $votes
        );
        $this->envelope($r, []);
    }

    public function note_add($p = null)
    {
        $uid = $this->requireLogin();
        $this->requirePost();
        $r = $this->KnightCircle->add_note($uid, (int) ($_POST['CandidateId'] ?? 0), (string) ($_POST['Body'] ?? ''));
        $this->envelope($r, ['noteId' => (int) ($r['NoteId'] ?? 0)]);
    }

    public function note_delete($p = null)
    {
        $uid = $this->requireLogin();
        $this->requirePost();
        $r = $this->KnightCircle->delete_note($uid, (int) ($_POST['NoteId'] ?? 0));
        $this->envelope($r, []);
    }

    /** Save the Circle's rules. Only the known keys are forwarded; the domain validates them. */
    public function config_save($p = null)
    {
        $uid = $this->requireLogin();
        $this->requirePost();
        $fields = array_intersect_key($_POST, KnightCircle::DEFAULTS);
        $r = $this->KnightCircle->save_config($uid, $this->kingdomId($p), $fields);
        $this->envelope($r, ['config' => $r['Config'] ?? new stdClass()]);
    }

    public function gmok_set($p = null)
    {
        $uid = $this->requireLogin();
        $this->requirePost();
        $kid = $this->kingdomId($p);
        $r = $this->KnightCircle->set_gmok($uid, $kid, (int) ($_POST['MundaneId'] ?? 0));
        $this->adminsEnvelope($r, $uid, $kid);
    }

    public function deputy_add($p = null)
    {
        $uid = $this->requireLogin();
        $this->requirePost();
        $kid = $this->kingdomId($p);
        $r = $this->KnightCircle->add_deputy($uid, $kid, (int) ($_POST['MundaneId'] ?? 0));
        $this->adminsEnvelope($r, $uid, $kid);
    }

    public function admin_remove($p = null)
    {
        $uid = $this->requireLogin();
        $this->requirePost();
        $kid = $this->kingdomId($p);
        $r = $this->KnightCircle->remove_admin($uid, $kid, (int) ($_POST['CircleAdminId'] ?? 0));
        $this->adminsEnvelope($r, $uid, $kid);
    }

    // -----------------------------------------------------------------------

    private function adminsEnvelope(array $r, int $uid, int $kid): void
    {
        if ((int) ($r['Status'] ?? 1) !== 0) {
            $this->envelope($r, []);
        }
        $a = $this->KnightCircle->admins($uid, $kid);
        $this->jsonOut(['status' => 0, 'admins' => $a['Admins'] ?? []]);
    }

    private function kingdomId($p): int
    {
        $raw = $_POST['KingdomId'] ?? $_GET['KingdomId'] ?? $p ?? 0;
        return (int) preg_replace('/[^0-9].*$/', '', (string) $raw);
    }

    private function requireLogin(): int
    {
        if (!isset($this->session->user_id) || (int) $this->session->user_id <= 0) {
            $this->jsonOut(['status' => 5, 'error' => 'Not logged in']);
        }
        return (int) $this->session->user_id;
    }

    private function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            $this->jsonOut(['status' => 1, 'error' => 'This action must be sent as a POST.']);
        }
    }

    private function envelope(array $r, array $extra): void
    {
        if ((int) ($r['Status'] ?? 1) !== 0) {
            $this->jsonOut(['status' => (int) ($r['Status'] ?? 1), 'error' => (string) ($r['Error'] ?? 'Request failed.')]);
        }
        $this->jsonOut(['status' => 0] + $extra);
    }

    private function jsonOut(array $payload): void
    {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
