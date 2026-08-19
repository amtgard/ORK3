<?php

/**
 * Public Era Phoenice endpoint. No auth — pure read of in-world calendar
 * math. Intended for mORK and third-party Amtgard sites to embed.
 *
 *   GET /index.php?Route=EraPhoenice/today
 *     → { ok, date, ep:{year,month,day,formatted}, holiday }
 *
 *   GET /index.php?Route=EraPhoenice/date/YYYY-MM-DD
 *     → same shape for the requested civil date.
 *
 *   GET /index.php?Route=EraPhoenice/holidays
 *     → { ok, holidays: { "MM-DD": "Name", ... } }
 *
 * All responses send CORS *, so anyone can fetch from a browser.
 */
class Controller_EraPhoenice extends Controller
{
    public function index($p = null)
    {
        $this->today();
    }

    public function today($p = null)
    {
        $this->json($this->EraPhoenice->today());
    }

    public function date($p = null)
    {
        $parts = explode('/', (string)$p);
        $iso   = trim($parts[0] ?? '');
        $payload = $this->EraPhoenice->date($iso);
        if (empty($payload['ok'])) {
            $this->json($payload, 400);
            return;
        }
        $this->json($payload);
    }

    public function holidays($p = null)
    {
        $this->json($this->EraPhoenice->holidays());
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        // Public read-only endpoint, fetched cross-origin from third-party kingdom
        // sites and mORK. CORS is supplied by nginx site-wide (see nginx.ork3.config
        // `add_header Access-Control-Allow-Origin *`) — do NOT set it here too, or the
        // duplicate header makes browsers reject the response even though curl accepts it.
        header('Cache-Control: public, max-age=300');
        echo json_encode($payload);
        exit;
    }
}
