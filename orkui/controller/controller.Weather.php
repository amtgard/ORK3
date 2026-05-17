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
class Controller_Weather extends Controller {

	public function __construct($call=null, $method=null) {
		parent::__construct($call, $method);
		unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
		$this->data['menu']['weather'] = array(
			'url'     => UIR . 'Weather',
			'display' => 'Weather <i class="fas fa-cloud-sun" style="font-size:11px;vertical-align:1px;"></i>',
		);
		$this->data['no_index'] = true;
	}

	public function index($action = null) {
		if (!isset($this->session->user_id)) {
			header('Location: ' . UIR . 'Login/login/Weather');
			exit;
		}
		$this->template = '../revised-frontend/Weather_index.tpl';
		$this->data['page_title'] = 'Weather Forecast';
		$this->data['Rundown']         = Ork3::$Lib->weather->daily_summary(date('Y-m-d'));
		$this->data['UpcomingEvents']  = Ork3::$Lib->weather->upcoming_events_with_forecast(7);
	}
}
