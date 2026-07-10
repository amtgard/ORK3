<?php

$server->Register([
    'Live/GetStats',
    ['LiveService', 'GetStats'],
    [],
]);

$server->Register([
    'Live/GetRecent',
    ['LiveService', 'GetRecent'],
    [],
]);
