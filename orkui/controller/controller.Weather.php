<?php

/**
 * Weather forecast dashboard.
 *
 *   /Weather  → HTML page (auth-required)
 *
 * Pulls from the existing ork_park_weather cache (refreshed every 30 min by
 * bin/refresh-weather.php) plus an event list query. No new upstream calls
 * at render time — the page is purely a presentation layer over data the
 * cron is already keeping fresh.
 */
class Controller_Weather extends Controller
{
    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->data['menu']['weather'] = array(
            'url'     => UIR . 'Weather',
            'display' => 'Weather <i class="fas fa-cloud-sun" style="font-size:11px;vertical-align:1px;"></i>',
        );
        $this->data['no_index'] = true;
    }

    public function index($action = null)
    {
        if (!isset($this->session->user_id)) {
            header('Location: ' . UIR . 'Login/login/Weather');
            exit;
        }
        $this->template = '../revised-frontend/Weather_index.tpl';
        $this->data['page_title'] = 'Weather Forecast';
        $today = EraPhoenice::todayDateString();
        $this->data['SelectedDate']    = $today;
        $this->data['Rundown']         = $this->Weather->daily_summary($today);
        $this->data['PlayToday']       = $this->Weather->play_for_date($today);
        $this->data['UpcomingEvents']  = $this->Weather->upcoming_events_with_forecast(7);
        $this->data['FreshnessPhrase'] = $this->Weather->freshness_phrase();
        // 7-day strip of pills (today + next 6 days), anchored to clock-pinned today.
        $strip = array();
        $todayTs = strtotime($today . ' 12:00:00');
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("+$i days", $todayTs));
            $strip[] = array(
                'date'      => $d,
                'day_label' => $i === 0 ? 'Today' : date('D', strtotime($d . ' 12:00:00')),
                'date_label' => date('M j', strtotime($d . ' 12:00:00')),
                'is_today'  => $i === 0,
            );
        }
        $severities = $this->Weather->strip_severities(array_column($strip, 'date'));
        foreach ($strip as &$pill) {
            $pill['severity'] = $severities[$pill['date']] ?? 'ok';
        }
        unset($pill);
        $this->data['DateStrip'] = $strip;
    }

    /**
     * Per-day data fetched by the date-pill switcher.
     * Route: Weather/day/{YYYY-MM-DD}
     */
    public function day($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(array('status' => 5, 'error' => 'Not logged in'));
            exit;
        }
        $date = trim($p ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(array('status' => 1, 'error' => 'Invalid date'));
            exit;
        }
        echo json_encode(array(
            'status'   => 0,
            'date'     => $date,
            'rundown'  => $this->Weather->daily_summary($date),
            'play'     => $this->Weather->play_for_date($date),
        ));
        exit;
    }
}
