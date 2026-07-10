<?php

$server->Register([
    'EraPhoenice/GetToday',
    ['EraPhoeniceService', 'GetToday'],
    [],
]);

$server->Register([
    'EraPhoenice/GetDate',
    ['EraPhoeniceService', 'GetDate'],
    [
        ['date', 'request', false, 'string', true],
    ],
]);

$server->Register([
    'EraPhoenice/GetHolidays',
    ['EraPhoeniceService', 'GetHolidays'],
    [],
]);
