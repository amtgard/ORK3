<?php

declare(strict_types=1);

/**
 * Runs one Smart Court controller action against the test database and prints its
 * result. A child process, because CourtAjax's jsonOut() exits.
 *
 * argv[1] is JSON: {action: 'smart_grid_state'|'ladder_grid', uid, token, post|get}.
 * uid 0 means logged out. ladder_grid prints the SmartCourt template value as JSON.
 */

$in = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);

require __DIR__ . '/../bootstrap.php';

if (!defined('UIR')) {
    define('UIR', HTTP_UI_REMOTE . 'index.php?Route=');
}

$_POST = $in['post'] ?? [];
$_GET = $in['get'] ?? [];
$_REQUEST = $_POST + $_GET;

$DONOTWEBSERVICE = true;
$Settings = new Settings();
$Session = new Session();
$Request = new Request();
if ((int) $in['uid'] > 0) {
    $Session->user_id = (int) $in['uid'];
    $Session->token = (string) $in['token'];
}

if ($in['action'] === 'smart_grid_state') {
    require_once DIR_CONTROLLER . 'controller.CourtAjax.php';
    (new Controller_CourtAjax('smart_grid_state'))->smart_grid_state();
} else {
    require_once DIR_CONTROLLER . 'controller.Reports.php';
    $controller = new Controller_Reports('ladder_grid');
    $controller->ladder_grid();
    echo json_encode($controller->data['SmartCourt']);
}
