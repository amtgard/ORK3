<?php
die();

$DONOTWEBSERVICE = true;

include_once('PlayerService.php');

global $DB;

$p = new yapo($DB, DB_PREFIX . 'mundane');

$p->given_name = 'admin';

if ($p->find()) {
	$p->mundane_id = null;
	$p->other_name = 'admin.p';
	$p->save();
}


?>