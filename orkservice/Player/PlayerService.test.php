<?php
/**
 * @deprecated Manual dev script — superseded by PHPUnit (see docs/megiddo/refactor/06-test-framework.md).
 * Kept for reference; die() prevents accidental execution against a live database.
 */
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