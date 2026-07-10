<?php

$server->Register([
    'Weather/GetDailySummary',
    ['WeatherService', 'GetDailySummary'],
    [
        ['date', 'request', false, 'string', true],
    ],
]);

$server->Register([
    'Weather/GetPlayForDate',
    ['WeatherService', 'GetPlayForDate'],
    [
        ['date', 'request', false, 'string', true],
    ],
]);

$server->Register([
    'Weather/GetUpcomingEventsWithForecast',
    ['WeatherService', 'GetUpcomingEventsWithForecast'],
    [
        ['days', 'request', true, 'numeric', true],
    ],
]);

$server->Register([
    'Weather/GetFreshnessPhrase',
    ['WeatherService', 'GetFreshnessPhrase'],
    [],
]);

$server->Register([
    'Weather/GetStripSeverities',
    ['WeatherService', 'GetStripSeverities'],
    [
        ['dates', 'request', false, 'json', true],
    ],
]);

$server->Register([
    'Weather/GetArchiveForPark',
    ['WeatherService', 'GetArchiveForPark'],
    [
        ['parkId', 'request', false, 'numeric', true],
        ['date', 'request', false, 'string', true],
    ],
]);
