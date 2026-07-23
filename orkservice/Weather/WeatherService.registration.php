<?php

$server->Register([
    'Weather/GetDailySummary',
    ['WeatherService', 'GetDailySummary'],
    [
        ['Token', 'request', false, 'string', true],
        ['date', 'request', false, 'string', true],
    ],
]);

$server->Register([
    'Weather/GetPlayForDate',
    ['WeatherService', 'GetPlayForDate'],
    [
        ['Token', 'request', false, 'string', true],
        ['date', 'request', false, 'string', true],
    ],
]);

$server->Register([
    'Weather/GetUpcomingEventsWithForecast',
    ['WeatherService', 'GetUpcomingEventsWithForecast'],
    [
        ['Token', 'request', false, 'string', true],
        ['days', 'request', true, 'numeric', true],
    ],
]);

$server->Register([
    'Weather/GetFreshnessPhrase',
    ['WeatherService', 'GetFreshnessPhrase'],
    [
        ['Token', 'request', false, 'string', true],
    ],
]);

$server->Register([
    'Weather/GetStripSeverities',
    ['WeatherService', 'GetStripSeverities'],
    [
        ['Token', 'request', false, 'string', true],
        ['dates', 'request', false, 'json', true],
    ],
]);

$server->Register([
    'Weather/GetArchiveForPark',
    ['WeatherService', 'GetArchiveForPark'],
    [
        ['Token', 'request', false, 'string', true],
        ['parkId', 'request', false, 'numeric', true],
        ['date', 'request', false, 'string', true],
    ],
]);
