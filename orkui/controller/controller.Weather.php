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

    // PUBLIC (opened 2026-08-23, Ken's call): weather is a decide-before-you-
    // drive utility. Every read below hits only the cron-warmed
    // ork_park_weather cache / ghettocache — no Open-Meteo call is reachable
    // from a page view, so anonymous/bot traffic can't inflate API usage.
    // The token-gated fetch-capable endpoints (GetForecastForPark,
    // GetArchiveForPark) remain gated for app use.
    public function index($action = null)
    {
        $this->template = '../revised-frontend/Weather_index.tpl';
        $this->data['page_title'] = 'Weather Forecast';
        $today = EraPhoenice::todayDateString();
        $token = (string) ($this->session->token ?? '');
        $this->data['SelectedDate']    = $today;
        $this->data['Rundown']         = $this->Weather->daily_summary($today, $token);
        $this->data['PlayToday']       = $this->Weather->play_for_date($today, $token);
        $this->data['UpcomingEvents']  = $this->Weather->upcoming_events_with_forecast(7, $token);
        $this->data['FreshnessPhrase'] = $this->Weather->freshness_phrase($token);

        // Link-preview card from the same rundown the page shows: how many
        // parks play today and whether weather is a factor anywhere.
        $_ogR = is_array($this->data['Rundown']) ? $this->data['Rundown'] : array();
        $_ogParks = (int)($_ogR['total_parks'] ?? 0);
        $_ogBadges = is_array($_ogR['badge_counts'] ?? null) ? $_ogR['badge_counts'] : array();
        arsort($_ogBadges);
        $_ogWarn = array();
        foreach (array_slice($_ogBadges, 0, 2, true) as $_ogLabel => $_ogN) {
            if ((int)$_ogN > 0) {
                $_ogWarn[] = $_ogN . ' park' . ((int)$_ogN === 1 ? '' : 's') . ' under ' . strtolower($_ogLabel);
            }
        }
        $_ogDesc = !empty($_ogR['no_play_today']) || $_ogParks === 0
            ? 'Forecasts for every Amtgard park and event — no scheduled park play today.'
            : 'Forecasts where Amtgard plays today: ' . $_ogParks . ' park' . ($_ogParks === 1 ? '' : 's') . ' on the calendar'
                . ($_ogWarn !== array() ? ' — ' . implode(', ', $_ogWarn) : ', all looking clear') . '.';
        $this->data['og'] = array(
            'title'       => 'Amtgard Weather',
            'url'         => UIR . 'Weather',
            'description' => $_ogDesc,
        );
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
        $severities = $this->Weather->strip_severities(array_column($strip, 'date'), $token);
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
        $date = trim($p ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(array('status' => 1, 'error' => 'Invalid date'));
            exit;
        }
        $token = (string) ($this->session->token ?? '');
        echo json_encode(array(
            'status'   => 0,
            'date'     => $date,
            'rundown'  => $this->Weather->daily_summary($date, $token),
            'play'     => $this->Weather->play_for_date($date, $token),
        ));
        exit;
    }
}
