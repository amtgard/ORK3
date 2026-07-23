<?php

/**
 * Public event-schedule embed domain (RB-N). Read-only; published events only.
 */
class EventEmbed extends Ork3
{
    /**
     * Published event + occurrence + schedule list for the cross-origin embed widget.
     * Explicit detail ids must belong to the event (no fall-through); otherwise the
     * next occurrence with start >= NOW() - 7 days is selected.
     */
    public function GetPublishedScheduleEmbed($request)
    {
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);

        if (!valid_id($eventId)) {
            return InvalidParameter('Invalid event id');
        }

        $this->db->Clear();
        $ev = $this->db->DataSet(
            'SELECT e.event_id, e.name, e.status, e.park_id, p.name AS park_name
             FROM ' . DB_PREFIX . 'event e
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = e.park_id
             WHERE e.event_id = ' . $eventId . ' LIMIT 1'
        );
        if (!$ev || !$ev->Next()) {
            return InvalidParameter('Event not found');
        }
        if ((string) $ev->status !== 'published') {
            return InvalidParameter('Event not available');
        }

        $cd = $this->resolveEmbedOccurrenceRow($eventId, $detailId);
        if (!$cd) {
            return InvalidParameter('Occurrence not found');
        }
        $resolvedDetailId = (int) $cd->event_calendardetail_id;

        $schedule = (new EventPlanning())->GetSchedule([
            'EventCalendarDetailId' => $resolvedDetailId,
        ]);
        $scheduleList = [];
        $scheduleStatus = is_array($schedule['Status'] ?? null)
            ? (int) ($schedule['Status']['Status'] ?? 1)
            : (int) ($schedule['Status'] ?? 1);
        if ($scheduleStatus === 0) {
            $scheduleList = $schedule['ScheduleList'] ?? [];
        }

        return [
            'Status' => Success(),
            'EventId' => $eventId,
            'EventCalendarDetailId' => $resolvedDetailId,
            'Name' => (string) $ev->name,
            'ParkName' => (string) ($ev->park_name ?? ''),
            'EventStart' => (string) $cd->event_start,
            'EventEnd' => (string) ($cd->event_end ?? ''),
            'ScheduleList' => $scheduleList,
        ];
    }

    private function resolveEmbedOccurrenceRow(int $eventId, int $detailId)
    {
        $this->db->Clear();
        if ($detailId > 0) {
            $cdRs = $this->db->DataSet(
                'SELECT event_calendardetail_id, event_start, event_end
                 FROM ' . DB_PREFIX . 'event_calendardetail
                 WHERE event_calendardetail_id = ' . $detailId . ' AND event_id = ' . $eventId . ' LIMIT 1'
            );
        } else {
            $cdRs = $this->db->DataSet(
                'SELECT event_calendardetail_id, event_start, event_end
                 FROM ' . DB_PREFIX . 'event_calendardetail
                 WHERE event_id = ' . $eventId . ' AND event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY event_start LIMIT 1'
            );
        }

        return ($cdRs && $cdRs->Next()) ? $cdRs : null;
    }
}
