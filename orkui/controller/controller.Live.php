<?php

/**
 * Live attendance dashboard.
 *
 *   /Live              → HTML page (public)
 *   /Live/stats        → JSON: rolling-24h per-park / per-event counts
 *   /Live/recent       → JSON: last ~50 sign-ins for the ticker
 *
 * PUBLIC (opened 2026-08-23, Ken's call): the feed is park-level aggregates —
 * mundane_id is deliberately stripped before the wire (see class.Live), so no
 * player identity is exposed. Server-side GhettoCache (~30s for stats, ~10s
 * for recent) keeps origin load bounded regardless of viewer count, bots
 * included.
 */
class Controller_Live extends Controller
{
    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        // Strip standard breadcrumbs — this page is its own thing
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->data['menu']['live'] = array('url' => UIR . 'Live', 'display' => 'Live <i class="fas fa-circle" style="color:#48bb78;font-size:8px;vertical-align:1px;"></i>');
        $this->data['no_index'] = true;
    }

    public function index($action = null)
    {
        $this->template = '../revised-frontend/Live_index.tpl';
        $this->data['page_title'] = 'Live Attendance';
    }

    public function stats()
    {
        header('Content-Type: application/json');
        $data = $this->Live->stats((string) ($this->session->token ?? ''));
        echo json_encode(array('status' => 0) + $data);
        exit;
    }

    public function recent()
    {
        header('Content-Type: application/json');
        $data = $this->Live->recent((string) ($this->session->token ?? ''));
        echo json_encode(array('status' => 0) + $data);
        exit;
    }
}
