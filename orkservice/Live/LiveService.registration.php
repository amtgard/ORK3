<?php

$server->Register([
    'Live/GetStats',
    ['LiveService', 'GetStats'],
    [
        ['Token', 'request', false, 'string', true],
    ],
]);

$server->Register([
    'Live/GetRecent',
    ['LiveService', 'GetRecent'],
    [
        ['Token', 'request', false, 'string', true],
    ],
]);
