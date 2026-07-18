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
        $this->emit(new DateTimeImmutable('today'));
    }

    public function date($p = null)
    {
        // Expect a YYYY-MM-DD slug as the first path segment.
        $parts = explode('/', (string)$p);
        $iso   = trim($parts[0] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            $this->json([
                'ok'    => false,
                'error' => 'date must be YYYY-MM-DD',
            ], 400);
            return;
        }
        try {
            $d = new DateTimeImmutable($iso);
        } catch (Exception $e) {
            $this->json([
                'ok'    => false,
                'error' => 'invalid calendar date',
            ], 400);
            return;
        }
        $this->emit($d);
    }

    public function holidays($p = null)
    {
        $this->json([
            'ok'       => true,
            'holidays' => EraPhoenice::HOLIDAYS,
        ]);
    }

    private function emit(DateTimeImmutable $d): void
    {
        $ep   = EraPhoenice::fromDate($d);
        $im   = EraPhoenice::imperiumFromDate($d);
        $last = EraPhoenice::lastHoliday($d);
        $next = EraPhoenice::nextHoliday($d);
        $this->json([
            'ok'   => true,
            'date' => $d->format('Y-m-d'),
            'ep'   => [
                'year'      => $ep['year'],
                'month'     => $ep['month'],
                'day'       => $ep['day'],
                'formatted' => EraPhoenice::format($d),
            ],
            'imperium' => [
                'year'      => $im['year'],
                'month'     => $im['month'],
                'day'       => $im['day'],
                'formatted' => EraPhoenice::imperiumFormat($d),
            ],
            'holiday'      => EraPhoenice::holiday($d),
            'last_holiday' => $last ? [
                'name'     => $last['name'],
                'date'     => $last['date']->format('Y-m-d'),
                'civil'    => EraPhoenice::longCivil($last['date']),
            ] : null,
            'next_holiday' => $next ? [
                'name'     => $next['name'],
                'date'     => $next['date']->format('Y-m-d'),
                'civil'    => EraPhoenice::longCivil($next['date']),
            ] : null,
        ]);
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
