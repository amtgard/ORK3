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
