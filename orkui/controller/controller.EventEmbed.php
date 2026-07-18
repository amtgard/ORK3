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

        global $DB;

        // Event row — must be published; drafts never leave the building.
        $DB->Clear();
        $ev = $DB->DataSet("
            SELECT e.event_id, e.name, e.status, e.park_id, p.name AS park_name
            FROM " . DB_PREFIX . "event e
            LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = e.park_id
            WHERE e.event_id = {$eventId} LIMIT 1");
        if (!$ev || !$ev->Next()) {
            $this->json(['ok' => false, 'error' => 'Event not found'], 404);
        }
        if ((string)$ev->status !== 'published') {
            $this->json(['ok' => false, 'error' => 'Event not available'], 404);
        }

        // Resolve the occurrence: the given detail, else the next upcoming one.
        $DB->Clear();
        if ($detailId > 0) {
            $cdRs = $DB->DataSet("
                SELECT event_calendardetail_id, event_start, event_end
                FROM " . DB_PREFIX . "event_calendardetail
                WHERE event_calendardetail_id = {$detailId} AND event_id = {$eventId} LIMIT 1");
        } else {
            $cdRs = $DB->DataSet("
                SELECT event_calendardetail_id, event_start, event_end
                FROM " . DB_PREFIX . "event_calendardetail
                WHERE event_id = {$eventId} AND event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY event_start LIMIT 1");
        }
        if (!$cdRs || !$cdRs->Next()) {
            $this->json(['ok' => false, 'error' => 'Occurrence not found'], 404);
        }
        $detailId  = (int)$cdRs->event_calendardetail_id;
        $startTs   = strtotime($cdRs->event_start);
        $dateLabel = $startTs ? date('l, F j, Y', $startTs) : '';

        // Schedule items — same source as the event page (Model_Event::get_schedule).
        $this->load_model('Event');
        $items = $this->Event->get_schedule($detailId);

        // Group by the item's start-time calendar day for a tidy per-day render.
        $days = [];
        foreach ($items as $it) {
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
            'name'         => $ev->name,
            'park_name'    => $ev->park_name,
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
