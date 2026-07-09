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
