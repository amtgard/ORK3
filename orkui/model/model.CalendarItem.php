<?php

class Model_CalendarItem extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->CalendarItem = new APIModel('CalendarItem');
    }

    /**
     * May $uid edit or delete a calendar item in this kingdom/park? The write gate
     * Update/DeleteCalendarItem use, so the UI's Edit control cannot disagree with it.
     */
    public function can_manage(int $uid, int $kingdom_id, int $park_id): bool
    {
        return $uid > 0 && CalendarItem::can_manage($uid, $kingdom_id, $park_id);
    }

    public function create_calendar_item($token, $kingdom_id, $park_id, $name, $description, $all_day, $event_start, $event_end, $is_officer_only = 0, $is_locals_only = 0, $color = '')
    {
        return $this->CalendarItem->CreateCalendarItem([
            'Token'         => $token,
            'KingdomId'     => $kingdom_id,
            'ParkId'        => $park_id,
            'Name'          => $name,
            'Description'   => $description,
            'AllDay'        => $all_day,
            'EventStart'    => $event_start,
            'EventEnd'      => $event_end,
            'IsOfficerOnly' => $is_officer_only,
            'IsLocalsOnly'  => $is_locals_only,
            'Color'         => $color,
        ]);
    }

    public function update_calendar_item($token, $id, $name, $description, $all_day, $event_start, $event_end, $is_officer_only = 0, $is_locals_only = 0, $color = '')
    {
        return $this->CalendarItem->UpdateCalendarItem([
            'Token'          => $token,
            'CalendarItemId' => $id,
            'Name'           => $name,
            'Description'    => $description,
            'AllDay'         => $all_day,
            'EventStart'     => $event_start,
            'EventEnd'       => $event_end,
            'IsOfficerOnly'  => $is_officer_only,
            'IsLocalsOnly'   => $is_locals_only,
            'Color'          => $color,
        ]);
    }

    public function delete_calendar_item($token, $id)
    {
        return $this->CalendarItem->DeleteCalendarItem(['Token' => $token, 'CalendarItemId' => $id]);
    }

    public function get_calendar_item($id)
    {
        return $this->CalendarItem->GetCalendarItem(['CalendarItemId' => $id]);
    }
}
