<?php

$server->register(
    'Event.CreateEvent',
    array('CreateEventRequest' => 'tns:CreateEventRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetEvent',
    array('GetEventRequest' => 'tns:GetEventRequest'),
    array('return' => 'tns:GetEventResponse'),
    $namespace
);

$server->register(
    'Event.GetEventDetail',
    array('GetEventDetailRequest' => 'tns:GetEventDetailRequest'),
    array('return' => 'tns:GetEventDetailResponse'),
    $namespace
);

$server->register(
    'Event.GetEventDetails',
    array('GetEventDetailRequest' => 'tns:GetEventDetailRequest'),
    array('return' => 'tns:GetEventDetailResponse'),
    $namespace
);

$server->register(
    'Event.CreateEventDetails',
    array('CreateEventDetailsRequest' => 'tns:CreateEventDetailsRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.SetCurrent',
    array('SetCurrentRequest' => 'tns:SetCurrentRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.DeleteEventDetail',
    array('DeleteEventDetailRequest' => 'tns:DeleteEventDetailRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.SetEventDetails',
    array('SetEventDetailsRequest' => 'tns:SetEventDetailsRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.DeleteEvent',
    array('DeleteEventRequest' => 'tns:DeleteEventRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.SetEvent',
    array('SetEventRequest' => 'tns:SetEventRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetRsvpStatus',
    array('GetRsvpStatusRequest' => 'tns:GetRsvpStatusRequest'),
    array('return' => 'tns:GetRsvpStatusResponse'),
    $namespace
);

$server->register(
    'Event.SetRsvp',
    array('SetRsvpRequest' => 'tns:SetRsvpRequest'),
    array('return' => 'tns:SetRsvpResponse'),
    $namespace
);

$server->register(
    'Event.WithdrawRsvp',
    array('WithdrawRsvpRequest' => 'tns:WithdrawRsvpRequest'),
    array('return' => 'tns:WithdrawRsvpResponse'),
    $namespace
);

$server->register(
    'Event.RemoveRsvp',
    array('RemoveRsvpRequest' => 'tns:RemoveRsvpRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetRsvpCounts',
    array('GetRsvpCountsRequest' => 'tns:GetRsvpCountsRequest'),
    array('return' => 'tns:GetRsvpCountsResponse'),
    $namespace
);

$server->register(
    'Event.GetRsvpCountsBatch',
    array('GetRsvpBatchRequest' => 'tns:GetRsvpBatchRequest'),
    array('return' => 'tns:GetRsvpBatchResponse'),
    $namespace
);

$server->register(
    'Event.GetUserRsvpStatusesBatch',
    array('GetRsvpBatchRequest' => 'tns:GetRsvpBatchRequest'),
    array('return' => 'tns:GetRsvpBatchResponse'),
    $namespace
);

$server->register(
    'Event.GetRsvpSummaryBatch',
    array('GetRsvpBatchRequest' => 'tns:GetRsvpBatchRequest'),
    array('return' => 'tns:GetRsvpBatchResponse'),
    $namespace
);

$server->register(
    'Event.GetRsvpList',
    array('GetRsvpCountsRequest' => 'tns:GetRsvpCountsRequest'),
    array('return' => 'tns:GetRsvpListResponse'),
    $namespace
);

$server->register(
    'Event.GetUpcomingRsvps',
    array('GetUpcomingRsvpsRequest' => 'tns:GetUpcomingRsvpsRequest'),
    array('return' => 'tns:GetUpcomingRsvpsResponse'),
    $namespace
);

$server->register(
    'Event.GetKingdomUpcomingEventsWithoutRsvp',
    array('GetKingdomEventsWithoutRsvpRequest' => 'tns:GetKingdomEventsWithoutRsvpRequest'),
    array('return' => 'tns:GetKingdomEventsWithoutRsvpResponse'),
    $namespace
);

$server->register(
    'Event.SetEventStatus',
    array('SetEventStatusRequest' => 'tns:SetEventStatusRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetEventPreview',
    array('GetEventPreviewRequest' => 'tns:GetEventPreviewRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.AddEventStaff',
    array('EventStaffRequest' => 'tns:EventStaffRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.RemoveEventStaff',
    array('EventStaffRequest' => 'tns:EventStaffRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.AddEventSchedule',
    array('EventScheduleRequest' => 'tns:EventScheduleRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.UpdateEventSchedule',
    array('EventScheduleRequest' => 'tns:EventScheduleRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.RemoveEventSchedule',
    array('EventScheduleRequest' => 'tns:EventScheduleRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.ListCopySourceEvents',
    array('ListCopySourceEventsRequest' => 'tns:ListCopySourceEventsRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.CreateEventWithCopy',
    array('CreateEventWithCopyRequest' => 'tns:CreateEventWithCopyRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.RemoveEventHeraldry',
    array('RemoveEventHeraldryRequest' => 'tns:RemoveEventHeraldryRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetDefaultOccurrenceId',
    array('OccurrenceScopeRequest' => 'tns:OccurrenceScopeRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.AssertDetailBelongsToEvent',
    array('OccurrenceScopeRequest' => 'tns:OccurrenceScopeRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetOccurrencePageData',
    array('OccurrencePageDataRequest' => 'tns:OccurrencePageDataRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetSchedule',
    array('OccurrenceScopeRequest' => 'tns:OccurrenceScopeRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetPublishedScheduleEmbed',
    array('OccurrenceScopeRequest' => 'tns:OccurrenceScopeRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.SetCalendarDetailFeesAndLinks',
    array('CalendarDetailFeesLinksRequest' => 'tns:CalendarDetailFeesLinksRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.SetCalendarDetailEventType',
    array('CalendarDetailEventTypeRequest' => 'tns:CalendarDetailEventTypeRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.ReconcilePastAttendance',
    array('ReconcilePastAttendanceRequest' => 'tns:ReconcilePastAttendanceRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetDietarySummaryForOccurrence',
    array('OccurrenceDetailRequest' => 'tns:OccurrenceDetailRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetDetailDependencyCounts',
    array('OccurrenceDetailRequest' => 'tns:OccurrenceDetailRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetEventRedirectScope',
    array('GetEventRequest' => 'tns:GetEventRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.GetParkName',
    array('GetParkNameRequest' => 'tns:GetParkNameRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'Event.IsDraftBlockedForViewer',
    array('DraftBlockedRequest' => 'tns:DraftBlockedRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);
