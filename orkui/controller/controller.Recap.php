<?php

/**
 *   /Recap                                  → global recap, latest completed week
 *   /Recap/index/YYYY-MM-DD                 → global recap, specific week
 *   /Recap/kingdom/{id}                     → kingdom-scoped recap, latest week
 *   /Recap/kingdom/{id}/YYYY-MM-DD          → kingdom-scoped recap, specific week
 *   /Recap/json                             → JSON: global, latest week
 *   /Recap/json/YYYY-MM-DD                  → JSON: global, specific week
 *   /Recap/json_kingdom/{id}                → JSON: kingdom-scoped, latest week
 *   /Recap/json_kingdom/{id}/YYYY-MM-DD     → JSON: kingdom-scoped, specific week
 *
 * Login required (HTML redirects; JSON returns 401). Global data is read from
 * ork_weekly_recap (written by bin/compute-weekly-recap.php each Monday). The
 * kingdom-scoped view computes lazily and caches via ghettocache, keyed on the
 * global recap's computed_at so a fresh cron run naturally orphans the cache.
 */

class Controller_Recap extends Controller
{
    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        $this->load_model('Recap');

        // PUBLIC (opened 2026-08-23, Ken's call): the recap is the ORK's
        // shareable artifact — a weekly link pasted into kingdom Discords
        // must render for non-logged-in readers or it's dead to every
        // prospect who taps it. All data here is precomputed aggregates of
        // already-public facts (awards, attendance counts), read from
        // ork_weekly_recap + ghettocache, so anonymous load is bounded.
    }

    public function index($week_start = null)
    {
        $this->_render_page(0, $week_start);
    }

    public function kingdom($p = null)
    {
        list($kingdom_id, $week_start) = $this->_parse_kingdom_path($p);
        if ($kingdom_id <= 0) {
            header('Location: ' . UIR . 'Recap');
            exit;
        }
        $this->_render_page($kingdom_id, $week_start);
    }

    public function json($week_start = null)
    {
        $this->_render_json(0, $week_start);
    }

    // Trends view: the recap's headline numbers over time, plus the
    // all-history weekly active-players curve. Public, like the recap; the
    // heavy lifting is ghettocached upstream, so page loads are cheap cache
    // reads.
    public function trends()
    {
        $this->data['page_title']     = 'Amtgard Platform Trends';
        $this->data['trend_series']   = $this->Recap->trend_series();
        $this->data['players_series'] = $this->Recap->weekly_active_players();
        $this->data['signin_series']  = $this->Recap->signin_series();
        $this->data['og'] = array(
            'title'       => 'Amtgard Platform Trends',
            'url'         => UIR . 'Recap/trends',
            'description' => 'How many people use the ORK and play the game, week over week — visitors, sign-ins, and players on the field across all recorded history.',
        );
    }

    public function json_kingdom($p = null)
    {
        list($kingdom_id, $week_start) = $this->_parse_kingdom_path($p);
        if ($kingdom_id <= 0) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(array('error' => 'invalid_kingdom_id'));
            exit;
        }
        $this->_render_json($kingdom_id, $week_start);
    }

    // Shared page render. $kingdom_id = 0 means global view.
    private function _render_page($kingdom_id, $week_start)
    {
        // Force the template so kingdom() doesn't try to resolve Recap_kingdom.tpl
        // based on the method name.
        $this->template = 'Recap_index.tpl';
        $week_start  = $this->_normalize_week_start($week_start);
        $kingdom_id  = (int)$kingdom_id;
        $recap       = $kingdom_id > 0
            ? $this->Recap->get_for_kingdom($kingdom_id, $week_start)
            : $this->Recap->get($week_start);
        $weeks       = $this->Recap->recent_weeks(60);

        $idx       = array_search($week_start, $weeks, true);
        $prev_week = ($idx !== false && isset($weeks[$idx + 1])) ? $weeks[$idx + 1] : null;
        $next_week = ($idx !== false && $idx > 0) ? $weeks[$idx - 1] : null;

        $prev_recap = null;
        if ($prev_week) {
            $prev_recap = $kingdom_id > 0
                ? $this->Recap->get_for_kingdom($kingdom_id, $prev_week)
                : $this->Recap->get($prev_week);
        }

        $kingdom_name = '';
        if ($kingdom_id > 0) {
            $this->load_model('Kingdom');
            $kingdom_name = $this->Kingdom->get_kingdom_name($kingdom_id);
        }

        $this->data['page_title']    = $kingdom_id > 0
            ? 'Amtgard Week in Review (' . $kingdom_name . ') — Week of ' . ($recap['WeekStart'] ?? $week_start)
            : 'Amtgard Week in Review — Week of ' . ($recap['WeekStart'] ?? $week_start);
        $this->data['recap']         = $recap;
        $this->data['week_start']    = $week_start;
        $this->data['prev_week']     = $prev_week;
        $this->data['next_week']     = $next_week;
        $this->data['recent_weeks']  = $weeks;
        $this->data['prev_recap']    = $prev_recap;
        $this->data['scope_kingdom_id']   = $kingdom_id;
        $this->data['scope_kingdom_name'] = $kingdom_name;
        $this->data['kingdom_list']  = $this->Recap->kingdom_list();
        $this->data['og'] = $this->_og_for_recap($recap, $kingdom_id, $kingdom_name, $week_start);
    }

    // Link-preview card for a recap week: pasted into a kingdom Discord, the
    // embed should say which week and lead with the headline numbers instead
    // of the site-generic blurb. Every field is optional-safe — a missing
    // payload falls back to the generic description.
    private function _og_for_recap($recap, $kingdom_id, $kingdom_name, $week_start)
    {
        $count = function ($v) {
            if (is_array($v)) {
                return count($v);
            }
            return is_numeric($v) ? (int)$v : 0;
        };

        $ws = strtotime((string)($recap['WeekStart'] ?? $week_start));
        $we = strtotime((string)($recap['WeekEnd'] ?? ''));
        $range = $ws ? date('M j', $ws) . ($we ? '–' . date($ws && date('n', $ws) === date('n', $we) ? 'j' : 'M j', $we) : '') . ', ' . date('Y', $we ?: $ws) : '';

        $title = 'Amtgard Week in Review' . ($range !== '' ? ' — ' . $range : '');
        if ($kingdom_id > 0 && $kingdom_name !== '') {
            $title .= ' · ' . $kingdom_name;
        }

        $parts = array();
        $visitors = $count($recap['HumanUsers'] ?? null);
        if ($visitors > 0) {
            $parts[] = number_format($visitors) . ' people visited the ORK';
        }
        $newbies = $count($recap['NewPlayers'] ?? null);
        if ($newbies > 0) {
            $parts[] = $newbies . ' new player' . ($newbies === 1 ? '' : 's');
        }
        $belts = $count($recap['Knightings'] ?? null) + $count($recap['Masterhoods'] ?? null) + $count($recap['Paragons'] ?? null);
        if ($belts > 0) {
            $parts[] = $belts . ' knighting' . ($belts === 1 ? '' : 's') . ' & masterhoods';
        }

        $canonical = $kingdom_id > 0
            ? UIR . 'Recap/kingdom/' . $kingdom_id . '/' . $week_start
            : UIR . 'Recap/index/' . $week_start;
        return array(
            'title'       => $title,
            'url'         => $canonical,
            'description' => $parts !== array()
                ? implode(' · ', $parts) . ' — the week in Amtgard, every Monday.'
                : 'The week in Amtgard — attendance, awards and events, every Monday.',
        );
    }

    // Shared JSON render. $kingdom_id = 0 means global.
    private function _render_json($kingdom_id, $week_start)
    {
        $week_start = $this->_normalize_week_start($week_start);
        $recap = $kingdom_id > 0
            ? $this->Recap->get_for_kingdom($kingdom_id, $week_start)
            : $this->Recap->get($week_start);
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        echo json_encode($recap ?? array(
            'WeekStart' => $week_start,
            'KingdomId' => $kingdom_id > 0 ? (int)$kingdom_id : null,
            'Status'    => 'not_computed',
        ));
        exit;
    }

    // Parse "{id}" or "{id}/{week_start}" path tail.
    private function _parse_kingdom_path($p)
    {
        if (empty($p)) {
            return array(0, null);
        }
        $parts = explode('/', $p);
        $kid = (int)preg_replace('/[^0-9]/', '', $parts[0]);
        $ws  = isset($parts[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[1]) ? $parts[1] : null;
        return array($kid, $ws);
    }

    // Validate Y-m-d or default to last full week's Monday.
    private function _normalize_week_start($week_start)
    {
        if (!empty($week_start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) {
            return $week_start;
        }
        return date('Y-m-d', strtotime('-7 days', strtotime('monday this week')));
    }
}
