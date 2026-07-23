<?php

/**
 * Public event-schedule embed endpoint. No auth — read-only, PUBLISHED events
 * only. CORS *, so any third-party kingdom/park website can fetch it from the
 * browser (see the drop-in widget at orkui/template/embed/ork-schedule.js).
 *
 *   GET /index.php?Route=EventEmbed/schedule/{event_id}/{detail_id}
 *     → { ok, event_id, detail_id, name, park_name, date_label,
 *          detail_url, schedule_url, grid_url,
 *          days: [ { date, day_label, items: [
 *              { id, title, start, end, time_label, location, category, leads[] }
 *          ] } ] }
 *   {detail_id} is optional — omit it to get the next upcoming occurrence.
 *
 * Drafts return 404 so nothing that isn't already public can leak. The data
 * here is the same schedule shown on the public Event/detail page.
 */
class Controller_EventEmbed extends Controller
{
    public function index($p = null)
    {
        $this->schedule($p);
    }

    public function schedule($p = null)
    {
        $parts    = explode('/', (string)$p);
        $eventId  = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $detailId = (int)preg_replace('/[^0-9]/', '', $parts[1] ?? '');
        if ($eventId <= 0) {
            $this->json(['ok' => false, 'error' => 'Invalid event id'], 400);
        }

        $this->load_model('Event');
        $embed = $this->Event->get_published_schedule_embed($eventId, $detailId);
        if (empty($embed['ok'])) {
            $this->json(
                ['ok' => false, 'error' => (string) ($embed['error'] ?? 'Event not found')],
                (int) ($embed['http'] ?? 404)
            );
        }

        $detailId  = (int) $embed['detail_id'];
        $startTs   = strtotime((string) $embed['event_start']);
        $dateLabel = $startTs ? date('l, F j, Y', $startTs) : '';

        // Group by the item's start-time calendar day for a tidy per-day render.
        $days = [];
        foreach ($embed['schedule'] as $it) {
            $ts  = strtotime($it['StartTime']);
            $key = $ts ? date('Y-m-d', $ts) : 'tbd';
            if (!isset($days[$key])) {
                $days[$key] = [
                    'date'      => $ts ? date('Y-m-d', $ts) : '',
                    'day_label' => $ts ? date('l, M j', $ts) : 'Time TBD',
                    'items'     => [],
                ];
            }
            $endTs = strtotime($it['EndTime']);
            $timeLabel = $ts ? date('g:i A', $ts) : '';
            if ($endTs && $endTs > $ts) {
                $timeLabel .= ' – ' . date('g:i A', $endTs);
            }
            $days[$key]['items'][] = [
                'id'         => (int)$it['EventScheduleId'],
                'title'      => $it['Title'],
                'start'      => $it['StartTime'],
                'end'        => $it['EndTime'],
                'time_label' => $timeLabel,
                'location'   => $it['Location'],
                'category'   => $it['Category'],
                'leads'      => array_values(array_map(function ($l) {
                    return $l['Persona'];
                }, $it['Leads'] ?? [])),
            ];
        }

        $detailUrl = UIR . 'Event/detail/' . $eventId . '/' . $detailId;
        $this->json([
            'ok'           => true,
            'event_id'     => $eventId,
            'detail_id'    => $detailId,
            'name'         => $embed['name'],
            'park_name'    => $embed['park_name'],
            'date_label'   => $dateLabel,
            'detail_url'   => $detailUrl,
            'schedule_url' => $detailUrl . '#ev-tab-schedule',
            'grid_url'     => $detailUrl . '#ev-tab-schedule?view=grid',
            'days'         => array_values($days),
        ]);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        // NOTE: nginx already adds `Access-Control-Allow-Origin: *` site-wide (see
        // nginx.ork3.config), so we must NOT set it here — a second, duplicate ACAO
        // header makes browsers reject the response ("multiple values"), even though
        // curl accepts it. The server-level header is what makes this endpoint
        // cross-origin fetchable from third-party kingdom/park websites.
        header('Cache-Control: public, max-age=300');
        echo json_encode($payload);
        exit;
    }
}
