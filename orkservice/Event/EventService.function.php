<?php

function GetRsvpStatus($request)
{
    $E = new Event();
    return $E->GetRsvpStatus($request);
}

function SetRsvp($request)
{
    $E = new Event();
    return $E->SetRsvp($request);
}

function WithdrawRsvp($request)
{
    $E = new Event();
    return $E->WithdrawRsvp($request);
}

function RemoveRsvp($request)
{
    $E = new Event();
    return $E->RemoveRsvp($request);
}

function GetRsvpCounts($request)
{
    $E = new Event();
    return $E->GetRsvpCounts($request);
}

function GetRsvpCountsBatch($request)
{
    $E = new Event();
    return $E->GetRsvpCountsBatch($request);
}

function GetUserRsvpStatusesBatch($request)
{
    $E = new Event();
    return $E->GetUserRsvpStatusesBatch($request);
}

function GetRsvpSummaryBatch($request)
{
    $E = new Event();
    return $E->GetRsvpSummaryBatch($request);
}

function GetRsvpList($request)
{
    $E = new Event();
    return $E->GetRsvpList($request);
}

function GetUpcomingRsvps($request)
{
    $E = new Event();
    return $E->GetUpcomingRsvps($request);
}

function GetKingdomUpcomingEventsWithoutRsvp($request)
{
    $E = new Event();
    return $E->GetKingdomUpcomingEventsWithoutRsvp($request);
}

function SetEventStatus($request)
{
    $EP = new EventPlanning();
    return $EP->SetEventStatus($request);
}

function GetEventPreview($request)
{
    $EP = new EventPlanning();
    return $EP->GetEventPreview($request);
}

function AddEventStaff($request)
{
    $EP = new EventPlanning();
    return $EP->AddEventStaff($request);
}

function RemoveEventStaff($request)
{
    $EP = new EventPlanning();
    return $EP->RemoveEventStaff($request);
}

function AddEventSchedule($request)
{
    $EP = new EventPlanning();
    return $EP->AddEventSchedule($request);
}

function UpdateEventSchedule($request)
{
    $EP = new EventPlanning();
    return $EP->UpdateEventSchedule($request);
}

function RemoveEventSchedule($request)
{
    $EP = new EventPlanning();
    return $EP->RemoveEventSchedule($request);
}

function ListCopySourceEvents($request)
{
    $EP = new EventPlanning();
    return $EP->ListCopySourceEvents($request);
}

function CreateEventWithCopy($request)
{
    $EP = new EventPlanning();
    return $EP->CreateEventWithCopy($request);
}

function RemoveEventHeraldry($request)
{
    $H = new Heraldry();
    return $H->RemoveEventHeraldry($request);
}

function GetDefaultOccurrenceId($request)
{
    $EP = new EventPlanning();
    return $EP->GetDefaultOccurrenceId($request);
}

function AssertDetailBelongsToEvent($request)
{
    $EP = new EventPlanning();
    return $EP->AssertDetailBelongsToEvent($request);
}

function GetOccurrencePageData($request)
{
    $EP = new EventPlanning();
    return $EP->GetOccurrencePageData($request);
}

function GetSchedule($request)
{
    $EP = new EventPlanning();
    return $EP->GetSchedule($request);
}

function SetCalendarDetailFeesAndLinks($request)
{
    $EP = new EventPlanning();
    return $EP->SetCalendarDetailFeesAndLinks($request);
}

function SetCalendarDetailEventType($request)
{
    $EP = new EventPlanning();
    return $EP->SetCalendarDetailEventType($request);
}

function ReconcilePastAttendance($request)
{
    $EP = new EventPlanning();
    return $EP->ReconcilePastAttendance($request);
}

function GetDietarySummaryForOccurrence($request)
{
    $EP = new EventPlanning();
    return $EP->GetDietarySummaryForOccurrence($request);
}

function GetDetailDependencyCounts($request)
{
    $EP = new EventPlanning();
    return $EP->GetDetailDependencyCounts($request);
}

function GetEventRedirectScope($request)
{
    $EP = new EventPlanning();
    return $EP->GetEventRedirectScope($request);
}

function GetParkName($request)
{
    $EP = new EventPlanning();
    return $EP->GetParkName($request);
}

function IsDraftBlockedForViewer($request)
{
    $EP = new EventPlanning();
    return $EP->IsDraftBlockedForViewer($request);
}
