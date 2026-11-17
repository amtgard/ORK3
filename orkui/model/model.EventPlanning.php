<?php

class Model_EventPlanning extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Event = new APIModel('Event');
        $this->Heraldry = new APIModel('Heraldry');
    }

    public function create_event(string $token, int $kingdomId, int $parkId, int $mundaneId, int $unitId, string $name, string $status = 'published'): array
    {
        return $this->Event->CreateEvent([
            'Token' => $token,
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'MundaneId' => $mundaneId,
            'UnitId' => $unitId,
            'Name' => $name,
            'Status' => $status,
        ]);
    }

    public function set_status(string $token, int $eventId, string $status): array
    {
        return $this->_planning()->SetEventStatus([
            'Token' => $token,
            'EventId' => $eventId,
            'Status' => $status,
        ]);
    }

    public function get_preview(int $eventId, int $detailId, int $mundaneId = 0): array
    {
        return $this->_planning()->GetEventPreview([
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
            'MundaneId' => $mundaneId,
        ]);
    }

    public function add_staff(array $request): array
    {
        return $this->_planning()->AddEventStaff($request);
    }

    public function remove_staff(array $request): array
    {
        return $this->_planning()->RemoveEventStaff($request);
    }

    public function add_schedule(array $request): array
    {
        return $this->_planning()->AddEventSchedule($request);
    }

    public function update_schedule(array $request): array
    {
        return $this->_planning()->UpdateEventSchedule($request);
    }

    public function remove_schedule(string $token, int $eventId, int $detailId, int $scheduleId): array
    {
        return $this->_planning()->RemoveEventSchedule([
            'Token' => $token,
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
            'ScheduleId' => $scheduleId,
        ]);
    }

    /** @param array{Token: string, EventId: int, EventCalendarDetailId: int, Ops: array, BatchToken?: string} $request */
    public function schedule_batch(array $request): array
    {
        return $this->_planning()->ApplyScheduleOps($request);
    }

    public function schedule_list_for_manage(string $token, int $eventId, int $detailId): array
    {
        return $this->_planning()->GetScheduleForManage([
            'Token' => $token,
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
        ]);
    }

    public function schedule_copy_sources(string $token, int $eventId, int $detailId): array
    {
        return $this->_planning()->ListScheduleCopySources([
            'Token' => $token,
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
        ]);
    }

    /** @param array{Token: string, EventId: int, EventCalendarDetailId: int, SourceDetailId: int, RequestToken?: string} $request */
    public function schedule_copy_into(array $request): array
    {
        return $this->_planning()->CopyScheduleInto($request);
    }

    public function copy_source_list(int $kingdomId, int $parkId, string $query, int $excludeEventId = 0): array
    {
        return $this->_planning()->ListCopySourceEvents([
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'Query' => $query,
            'ExcludeEventId' => $excludeEventId,
        ]);
    }

    public function create_with_copy(array $request): array
    {
        return $this->_planning()->CreateEventWithCopy($request);
    }

    public function remove_heraldry(string $token, int $eventId): array
    {
        return $this->Heraldry->RemoveEventHeraldry([
            'Token' => $token,
            'EventId' => $eventId,
        ]);
    }

    public function delete_event(string $token, int $eventId): array
    {
        return $this->Event->DeleteEvent([
            'Token' => $token,
            'EventId' => $eventId,
        ]);
    }

    public function remove_rsvp(int $detailId, int $mundaneId, string $token): void
    {
        $this->Event->RemoveRsvp([
            'Token' => $token,
            'EventCalendarDetailId' => $detailId,
            'TargetMundaneId' => $mundaneId,
        ]);
    }

    public function get_attendance_display_row(int $attendanceId): ?array
    {
        return $this->_planning()->GetAttendanceDisplayRow($attendanceId);
    }

    public function can_add_attendance(int $mundaneId, int $eventId, int $detailId): bool
    {
        return $this->_planning()->CanAddAttendance($mundaneId, $eventId, $detailId);
    }

    public function can_remove_rsvp(int $mundaneId, int $eventId, int $detailId): bool
    {
        return $this->_planning()->CanRemoveRsvp($mundaneId, $eventId, $detailId);
    }

    public function can_manage_event_detail(int $mundaneId, int $eventId, int $detailId, string $capability): bool
    {
        return $this->_planning()->CanManageEventDetail($mundaneId, $eventId, $detailId, $capability);
    }

    public function set_event_heraldry(array $request): array
    {
        return $this->Heraldry->SetEventHeraldry($request);
    }

    private function _planning(): EventPlanning
    {
        return new EventPlanning();
    }

    public function emit_json(array $response, int $authDeniedStatus = 3): void
    {
        $error = $this->json_error($response, $authDeniedStatus);
        if ($error === null) {
            return;
        }

        echo json_encode($error);
        exit;
    }

    /**
     * The `{"status":N,"error":…}` body for a failed domain response, or null on success.
     *
     * @return array{status: int, error: string}|null
     */
    public function json_error(array $response, int $authDeniedStatus = 3): ?array
    {
        $status = (int)($response['Status'] ?? $response['Status']['Status'] ?? 1);
        if (is_array($response['Status'] ?? null)) {
            $status = (int)$response['Status']['Status'];
        }

        if ($status === ServiceErrorIds::Success) {
            return null;
        }

        $detail = (string)($response['Detail'] ?? '');
        if (is_array($response['Status'] ?? null)) {
            $detail = (string)($response['Status']['Detail'] ?? $detail);
        }
        $error = $detail !== '' ? $detail : (string)($response['Error'] ?? $response['Status']['Error'] ?? 'Error');
        $jsonStatus = match ($status) {
            ServiceErrorIds::NoAuthorization => $authDeniedStatus,
            ServiceErrorIds::SecureTokenFailure => 5,
            default => 1,
        };

        return ['status' => $jsonStatus, 'error' => $error];
    }
}
