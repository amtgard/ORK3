<?php

/**
 * JSON endpoints for Legend (In Memoriam) status: the Mark as Legend and Remove
 * Legend Status modals on the player page, and the year edit.
 *
 * Envelope: {status: 0, ...} ok; {status: 1, error} bad request;
 * {status: 3, error} not authorized; {status: 5, error} not logged in.
 * Authority and every rule are in the domain (class.Legend.php); this layer only
 * reads the request and the session.
 */
class Controller_LegendAjax extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('Legend');
    }

    /** What marking this player as a Legend would clear, and the warnings to show. */
    public function preview($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->Legend->preview($uid, $this->playerId($p));
        $this->envelope($r, ['items' => $r['Items'] ?? [], 'warnings' => $r['Warnings'] ?? []]);
    }

    /** Mark a player as a Legend. Year is a form field. */
    public function convert($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->convert($uid, $this->playerId($p), $this->Legend->parse_year($_POST['Year'] ?? ''));
        $this->envelope($r, ['cleared' => $r['Cleared'] ?? []]);
    }

    /** Remove Legend status; lists what the conversion cleared, to restore by hand. */
    public function undo($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->undo($uid, $this->playerId($p));
        $this->envelope($r, ['cleared' => $r['Cleared'] ?? []]);
    }

    /** Correct a Legend's year. */
    public function year($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->set_year($uid, $this->playerId($p), $this->Legend->parse_year($_POST['Year'] ?? ''));
        $this->envelope($r, []);
    }

    /** A Legend's Memory Wall, newest first. Public: readable logged out, like the profile. */
    public function memories($p = null)
    {
        $this->jsonOut(['status' => 0, 'memories' => $this->Legend->memories($this->playerId($p))]);
    }

    /** Leave a memory. Body is a form field. */
    public function add($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->add_memory($uid, $this->playerId($p), (string) ($_POST['Body'] ?? ''));
        $this->envelope($r, ['memoryId' => $r['MemoryId'] ?? 0]);
    }

    /** Remove a memory ({memoryId} in the route). Kingdom officers and ORK admins only. */
    public function remove($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->remove_memory($uid, $this->playerId($p));
        $this->envelope($r, []);
    }

    // -----------------------------------------------------------------------

    private function requireLogin(): int
    {
        if (!isset($this->session->user_id) || (int) $this->session->user_id <= 0) {
            $this->jsonOut(['status' => 5, 'error' => 'Not logged in']);
        }
        return (int) $this->session->user_id;
    }

    /** These write, so a link or an image tag must not be able to trigger them. */
    private function requirePost(): int
    {
        $uid = $this->requireLogin();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->jsonOut(['status' => 1, 'error' => 'This request must be a POST.']);
        }
        return $uid;
    }

    /** The route's player id; anything but digits reads as 0, which the domain refuses. */
    private function playerId($p): int
    {
        return ctype_digit((string) $p) ? (int) $p : 0;
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
