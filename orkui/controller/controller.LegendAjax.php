<?php

/**
 * JSON endpoints for Legend (In Memoriam) status: the Mark as Legend and Remove
 * Legend Status modals on the player page, and the year edit.
 *
 * Envelope: {status: 0, ...} ok; {status: 1, error} bad request;
 * {status: 3, error} not authorized, or a write from another site; {status: 5,
 * error} not logged in.
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
        $this->envelope($r, ['items' => $r['Items'] ?? [], 'warnings' => $r['Warnings'] ?? [], 'player' => $r['Player'] ?? []]);
    }

    /** Mark a player as a Legend. Year is a form field. */
    public function convert($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->convert($uid, $this->playerId($p), $this->Legend->parse_year($_POST['Year'] ?? ''));
        $this->envelope($r, ['cleared' => $r['Cleared'] ?? [], 'warnings' => $r['Warnings'] ?? []]);
    }

    /** Remove Legend status; lists what the conversion cleared, to restore by hand. */
    public function undo($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->undo($uid, $this->playerId($p));
        $this->envelope($r, ['cleared' => $r['Cleared'] ?? [], 'warnings' => $r['Warnings'] ?? [], 'clearedOn' => $r['ClearedOn'] ?? '']);
    }

    /** What the conversion cleared, shown before Remove Legend Status is confirmed. Read-only. */
    public function cleared($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->Legend->cleared($uid, $this->playerId($p));
        $this->envelope($r, ['cleared' => $r['Cleared'] ?? [], 'warnings' => $r['Warnings'] ?? [], 'clearedOn' => $r['ClearedOn'] ?? '']);
    }

    /** Correct a Legend's year. */
    public function year($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->set_year($uid, $this->playerId($p), $this->Legend->parse_year($_POST['Year'] ?? ''));
        $this->envelope($r, []);
    }

    /** One page of a Legend's Memory Wall, newest first. Public: readable logged out, like the profile. */
    public function memories($p = null)
    {
        $id = $this->playerId($p);
        $this->jsonOut([
            'status'   => 0,
            'memories' => $this->Legend->memories($id, (int) ($_GET['limit'] ?? 0), (int) ($_GET['offset'] ?? 0)),
            'total'    => $this->Legend->memory_count($id),
        ]);
    }

    /** Leave a memory. Body is a form field. */
    public function add($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->add_memory($uid, $this->playerId($p), (string) ($_POST['Body'] ?? ''));
        $this->envelope($r, ['memoryId' => $r['MemoryId'] ?? 0]);
    }

    /** Remove a memory ({memoryId} in the route). Reason is a form field. Kingdom officers and ORK admins only. */
    public function remove($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->remove_memory($uid, $this->playerId($p), $this->reason());
        $this->envelope($r, []);
    }

    /** Remove all of one author's memories from this wall. AuthorId and Reason are form fields. Same authority as remove. */
    public function removeauthor($p = null)
    {
        $uid = $this->requirePost();
        $r = $this->Legend->remove_author_memories($uid, $this->playerId($p), $this->playerId($_POST['AuthorId'] ?? ''), $this->reason());
        $this->envelope($r, ['removed' => $r['Removed'] ?? 0]);
    }

    // -----------------------------------------------------------------------

    /** The Reason form field. An array (Reason[]=x) reads as blank, which the domain refuses. */
    private function reason(): string
    {
        $reason = $_POST['Reason'] ?? '';
        return is_string($reason) ? $reason : '';
    }

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
        if (!$this->sameOrigin($_SERVER)) {
            $this->jsonOut(['status' => 3, 'error' => 'This request came from another site.']);
        }
        return $uid;
    }

    /**
     * Refuse only a value that names another site. A browser always sends Origin on
     * a cross-site POST but a referrer policy can drop Referer on a same-site one,
     * and a client that sends neither (curl) has no browser session to ride on.
     */
    private function sameOrigin(array $server): bool
    {
        foreach (array_filter([$server['HTTP_ORIGIN'] ?? '', $server['HTTP_REFERER'] ?? '']) as $url) {
            $u = parse_url($url) ?: [];
            if (strcasecmp(($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : ''), $server['HTTP_HOST'] ?? '') !== 0) {
                return false;
            }
        }
        return true;
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
