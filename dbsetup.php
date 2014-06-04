<head>
<link href='http://fonts.googleapis.com/css?family=Open+Sans+Condensed:300' rel='stylesheet' type='text/css'>
<style type='text/css'>
	html, body, h1, h2, h3, h4, h5 {
		font-family: 'Open Sans Condensed', sans-serif;
	}
	pre {
		font-family: consolas;
		font-size: 9pt;
	}
</style>
</head>

<?php

function pre_print_r($array) {
	echo "<pre>\n\n" . print_r($array, true) . "\n\n</pre>\n";
}

/*******************************************************************************

Admin Password (Default): e01e44f3

*******************************************************************************/

include_once('config.php');

if (DO_SETUP == true) {
	echo "<h1>Setup DB</h1>";
	
	$sql = file_get_contents('ork.sql');

	$sql = str_replace('ork_', DB_PREFIX, $sql);
	
	file_put_contents('orksetup.sql', $sql);
	
	$command = "mysql -u " . DB_USERNAME . " -p" . DB_PASSWORD . " -h " . DB_HOSTNAME . " -D " . DB_DATABASE . " < orksetup.sql";
	
	print_r(array(shell_exec($command)));
	
	unlink('orksetup.sql');
	
	$clear = array( 'account', 'application', 'application_auth', 'attendance', 'authorization', 'awardlimit', 'award', 'awards', 'bracket', 'bracket_officiant', 'class_reconciliation', 'configuration', 'credential', 'event', 
	'event_calendardetail', 'glicko2', 'kingdom', 'kingdomaward', 'log', 'match', 'mundane', 'officer', 'park', 'parkday', 'parktitle', 'participant', 'participant_mundane', 'seed', 'split', 'team', 'tournament', 'transaction', 
	'unit', 'unit_mundane');

	echo "<h1>Empty Tables &amp; Prep Admin User</h1>";

	foreach ($clear as $dbname) {
		echo "Empty table $dbname ... ";
		$DB->query('truncate table orkdev_' . $dbname);
	}

	echo "Done<p>";

	$sql = "INSERT INTO `" . DB_PREFIX . "mundane` 
				(`mundane_id`, `given_name`, `surname`, `other_name`, `username`, `persona`, `email`, `park_id`, `kingdom_id`, `token`, `modified`, `restricted`, `waivered`, `waiver_ext`, `has_heraldry`, `has_image`, `company_id`, `token_expires`, `password_expires`, `password_salt`, `xtoken`, `penalty_box`, `active`) 
					VALUES (1, 'admin', 'admin', 'admin', 'admin', 'admin', '" . SETUP_ADMIN_EMAIL . "', 0, 0, '', '2013-04-24 12:55:31', 0, 0, '', 0, 0, 0, '0000-00-00 00:00:00', '2014-04-24 11:55:31', 'b1a838cc8bbbdc7d2008ac00890cb8eb', '', 0, 1)";
	$DB->query($sql);

	$sql = "INSERT INTO `" . DB_PREFIX . "credential` (`key`, `expiration`) VALUES ('e.I0/92KStOsJu3dq5/WAErF..MkctX2KwjhsIn7vcB1Y3cim2nemAiVsc4byiUXzuhQu0', '2014-09-29 23:08:36')";
	$DB->query($sql);

	$sql = "INSERT INTO `" . DB_PREFIX . "authorization` (`authorization_id`, `mundane_id`, `park_id`, `kingdom_id`, `event_id`, `unit_id`, `role`, `modified`) VALUES (1, 1, 0, 0, 0, 0, 'admin', '2013-04-24 13:28:25')";
	$DB->query($sql);

	$adminuser = 'admin';
	$adminpassword = 'e01e44f3';
}

?>