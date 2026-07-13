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
        return $this->Event->SetEventStatus([
            'Token' => $token,
            'EventId' => $eventId,
            'Status' => $status,
        ]);
    }

    public function get_preview(int $eventId, int $detailId, int $mundaneId = 0): array
    {
        return $this->Event->GetEventPreview([
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
            'MundaneId' => $mundaneId,
        ]);
    }

    public function add_staff(array $request): array
    {
        return $this->Event->AddEventStaff($request);
    }

    public function remove_staff(array $request): array
    {
        return $this->Event->RemoveEventStaff($request);
    }

    public function add_schedule(array $request): array
    {
        return $this->Event->AddEventSchedule($request);
    }

    public function update_schedule(array $request): array
    {
        return $this->Event->UpdateEventSchedule($request);
    }

    public function remove_schedule(string $token, int $eventId, int $detailId, int $scheduleId): array
    {
        return $this->Event->RemoveEventSchedule([
            'Token' => $token,
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
            'ScheduleId' => $scheduleId,
        ]);
    }

    public function copy_source_list(int $kingdomId, int $parkId, string $query, int $excludeEventId = 0): array
    {
        return $this->Event->ListCopySourceEvents([
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'Query' => $query,
            'ExcludeEventId' => $excludeEventId,
        ]);
    }

    public function create_with_copy(array $request): array
    {
        return $this->Event->CreateEventWithCopy($request);
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

    public function remove_rsvp(int $detailId, int $mundaneId): void
    {
        $this->Event->RemoveRsvp([
            'EventCalendarDetailId' => $detailId,
            'TargetMundaneId' => $mundaneId,
            'AuthorizedByController' => true,
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
        $status = (int)($response['Status'] ?? $response['Status']['Status'] ?? 1);
        if (is_array($response['Status'] ?? null)) {
            $status = (int)$response['Status']['Status'];
        }

        if ($status === ServiceErrorIds::Success) {
            return;
        }

        $detail = (string)($response['Detail'] ?? '');
        if (is_array($response['Status'] ?? null)) {
            $detail = (string)($response['Status']['Detail'] ?? $detail);
        }
        $error = $detail !== '' ? $detail : (string)($response['Error'] ?? 'Error');
        $jsonStatus = match ($status) {
            ServiceErrorIds::NoAuthorization => $authDeniedStatus,
            ServiceErrorIds::SecureTokenFailure => 5,
            default => 1,
        };

        echo json_encode(['status' => $jsonStatus, 'error' => $error]);
        exit;
    }
}
