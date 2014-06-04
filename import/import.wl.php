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
die();
function pre_print_r($array) {
	echo "<pre>\n\n" . print_r($array, true) . "\n\n</pre>\n";
}

include_once('../config.php');

echo "<h1>Configure Import</h1>";

$WL = new yapo_mysql(DB_HOSTNAME, 'orkrecords_wlimport', DB_USERNAME, DB_PASSWORD);

$attendance = new yapo($WL, 'attendance');
$awards = new yapo($WL, 'awards');
$awardnames = new yapo($WL, 'awardnames');
$classes = new yapo($WL, 'classes');
$mundanes = new yapo($WL, 'mundanes');
$parks = new yapo($WL, 'parks');
$personas = new yapo($WL, 'personas');
$reconciled = new yapo($WL, 'reconciled');

$clear = array( 'account', 'application', 'application_auth', 'attendance', 'authorization', 'awardlimit', 'award', 'awards', 'bracket', 'bracket_officiant', 'class', 'class_reconciliation', 'configuration', 'credential', 'event', 
'event_calendardetail', 'glicko2', 'kingdom', 'kingdomaward', 'log', 'match', 'mundane', 'officer', 'park', 'parkday', 'parktitle', 'participant', 'participant_mundane', 'seed', 'split', 'team', 'tournament', 'transaction', 
'unit', 'unit_mundane');

$Attendance = new APIModel('Attendance');


/****************

First, empty the DB

****************/

echo "<h1>Empty Tables &amp; Prep Admin User</h1>";

foreach ($clear as $dbname) {
	echo "Empty table $dbname ... ";
	$DB->query('truncate table ' . DB_PREFIX . $dbname);
}

echo "Done<p>";

echo "<h1>Create Caches &amp; Initial Setup</h1>";

echo "<h2>Create Admin</h2>";

$sql = "INSERT INTO `" . DB_PREFIX . "mundane` (`mundane_id`, `given_name`, `surname`, `other_name`, `username`, `persona`, `email`, `park_id`, `kingdom_id`, `token`, `modified`, `restricted`, `waivered`, `waiver_ext`, `has_heraldry`, `has_image`, `company_id`, `token_expires`, `password_expires`, `password_salt`, `xtoken`, `penalty_box`, `active`) VALUES (1, 'admin', 'admin', 'admin', 'admin', 'admin', 'en.gannim@gmail.com', 0, 0, '', '2013-04-24 12:55:31', 0, 0, '', 0, 0, 0, '0000-00-00 00:00:00', '2014-04-24 11:55:31', 'b1a838cc8bbbdc7d2008ac00890cb8eb', '', 0, 1)";
$DB->query($sql);

$sql = "INSERT INTO `" . DB_PREFIX . "credential` (`key`, `expiration`) VALUES ('e.I0/92KStOsJu3dq5/WAErF..MkctX2KwjhsIn7vcB1Y3cim2nemAiVsc4byiUXzuhQu0', '2014-09-29 23:08:36')";
$DB->query($sql);

$sql = "INSERT INTO `" . DB_PREFIX . "authorization` (`authorization_id`, `mundane_id`, `park_id`, `kingdom_id`, `event_id`, `unit_id`, `role`, `modified`) VALUES (1, 1, 0, 0, 0, 0, 'admin', '2013-04-24 13:28:25')";
$DB->query($sql);

$adminuser = 'admin';
$adminpassword = 'e01e44f3';

$Authorization = new APIModel('Authorization');
$T = $Authorization->Authorize(array(
	'UserName' => $adminuser,
	'Password' => $adminpassword
));

$Token = $T['Token'];

$Award = new APIModel('Award');

echo "<h2>Cache Classes &amp; Find Matches</h2>";
$class_namemap = array(
"Antipaladin" => 'Anti-Paladin',
"Archer" => 'Archer',
"Assassin" => 'Assassin',
"Barbarian" => 'Barbarian',
"Bard" => 'Bard',
"Color" => 'Color',
"Druid" => 'Druid',
"Healer" => 'Healer',
"Monk" => 'Monk',
"Monster" => 'Monster',
"Paladin" => 'Paladin',
"Peasant" => 'Peasant',
"Raider" => 'Color',
"Reeve" => 'Reeve',
"Scout" => 'Scout',
"Warrior" => 'Warrior',
"Wizard" => 'Wizard');

$classes->clear();
$classes->find();
$class_map = array();
$Attendance->create_system_classes();
$orkclasses = $Attendance->GetClasses(array());

do {
	foreach ($orkclasses['Classes'] as $idx => $classinfo) {
		if ($classinfo['Name'] == $class_namemap[$classes->classname]) {
			$classid = $classinfo['ClassId'];
			break;
		}
	}
	$class_map[$classes->classpk] = $classid;
} while ($classes->next());

pre_print_r($class_map);

echo "<h2>Create System Awards</h2>";

$Award->create_system_awards();

echo "<h2>Create Kingdom</h2>";

$kingdom = new APIModel('Kingdom');

$wetlands = $kingdom->CreateKingdom(array(
	'Token' => $Token,
	'Name' => 'Kingdom of the Wetlands',
	'Abbreviation' => 'WL',
	'AveragePeriod' => 6,
	'AttendancePeriodType' => 'Month',
	'AttendanceMinimum' => 6,
	'AttendanceCreditMinimum' => 9,
	'DuesPeriod' => 6,
	'DuesPeriodType' => 'Month',
	'DuesAmount' => 6.0,
	'KingdomDuesTake' => 3.0
));

pre_print_r($wetlands);

$KingdomId = $wetlands['Detail'];

echo "<h2>Park Map</h2>";

$KingdomDetails = $kingdom->GetKingdomDetails(array('KingdomId' => $KingdomId ));

$park = new APIModel('Park');
$parks->clear();
$park_map = array();
$parks->find();
do {
	echo "<h3 style='display: inline-block; margin: auto 20px;'>{$parks->name}</h3>";
	$parktitleid = $KingdomDetails['ParkTitles'][0]['ParkTitleId'];
	foreach ($KingdomDetails['ParkTitles'] as $ParkTitle)
		if ($ParkTitle['Title'] == $parks->title)
			$parktitleid = $ParkTitle['ParkTitleId'];
	
	if ($parks->local == 1 || true) {
		$p = $park->CreatePark(array(
			'Token' => $Token,
			'Name' => $parks->name,
			'Abbreviation' => $parks->abbreviation,
			'KingdomId' => $KingdomId,
			'ParkTitleId' => $parktitleid
		));
		$park_map[$parks->parkpk] = $p['Detail'];
		
		if ($parks->retired == 1) {
			echo "Retire";
			$r = $park->RetirePark(array(
				'Token' => $Token,
				'ParkId' => $park_map[$parks->parkpk]
			));
		}
	}
} while ($parks->next());

unset($parks);

pre_print_r($park_map);

echo "<h2>Award Init &amp; Map</h2>";

/**
SELECT ifnull(concat("'", awardnames.awardname, "' => '", award.name, "'"), concat("'", awardnames.awardname, "' => ''")) FROM `orkrecords_wlimport`.`awardnames` awardnames left join `orkrecords_dev`.`orkdev_award` award on award.name like concat('%',awardnames.awardname) order by awardnames.awardnamepk

'Tsunami' => ''

**/

$award_namemap = array(
	'Jovius' => 'Order of the Jovius',
	'Master Jovius' => 'Master Jovius',
	'Dragon' => 'Order of the Dragon',
	'Garber' => 'Order of the Garber',
	'Mask' => 'Order of the Mask',
	'Owl' => 'Order of the Owl',
	'Lion' => 'Order of the Lion',
	'Rose' => 'Order of the Rose',
	'Smith' => 'Order of the Smith',
	'Griffin' => 'Order of the Griffin',
	'Warrior' => 'Order of the Warrior',
	'Hydra' => 'Order of the Hydra',
	'Master Dragon' => 'Master Dragon',
	'Master Garber' => 'Master Garber',
	'Master Mask' => 'Master Mask',
	'Master Owl' => 'Master Owl',
	'Master Lion' => 'Master Lion',
	'Master Rose' => 'Master Rose',
	'Master Smith' => 'Master Smith',
	'Master Griffin' => 'Master Griffin',
	'Warlord' => 'Warlord',
	'Master Hydra' => 'Master Hydra',
	'Knight of the Crown' => 'Knight of the Crown',
	'Knight of the Flame' => 'Knight of the Flame',
	'Knight of the Sword' => 'Knight of the Sword',
	'Knight of the Serpent' => 'Knight of the Serpent',
	'Flame' => 'Order of the Flame',
	'Walker in the Middle' => 'Order of the Walker in the Middle',
	'Zodiac' => 'Order of the Zodiac',
	'Grand Duke' => 'Grand Duke',
	'Arch-Duke' => 'Archduke',
	'Duke' => 'Duke',
	'Marquis' => 'Marquis',
	'Count' => 'Count',
	'Viscount' => 'Viscount',
	'Baron' => 'Baron',
	'Baronet' => 'Baronet',
	'Lord' => 'Lord',
	'Defender' => 'Defender',
	'Master Antipaladin' => 'Master Anti-Paladin',
	'Master Archer' => 'Master Archer',
	'Master Assassin' => 'Master Assassin',
	'Master Barbarian' => 'Master Barbarian',
	'Master Bard' => 'Master Bard',
	'Master Druid' => 'Master Druid',
	'Master Monk' => 'Master Monk',
	'Master Healer' => 'Master Healer',
	'Master Monster' => 'Master Monster',
	'Master Paladin' => 'Master Paladin',
	'Master Peasant' => 'Master Peasant',
	'Master Scout' => 'Master Scout',
	'Master Warrior' => 'Master Warrior',
	'Master Wizard' => 'Master Wizard',
	'Lady' => 'Lady',
	'Baroness' => 'Baroness',
	'Countess' => 'Countess',
	'Viscountess' => 'Viscountess',
	'Local Sheriff' => 'Sheriff',
	'Local Baron' => 'Provincial Baron',
	'Local Duke' => 'Provincial Duke',
	'Kingdom Monarch' => 'Kingdom Monarch',
	'Local Baroness' => 'Provincial Baroness',
	'Local Duchess' => 'Provincial Duchess',
	'Local Grand Duke' => 'Provincial Grand Duke',
	'Local Grand Duchess' => 'Provincial Grand Duchess',
	'Local Ducal Regent' => 'Ducal Regent',
	'Local Grand Ducal Regent' => 'Grand Ducal Regent',
	'Local Regent' => 'Baronial Regent',
	'Local Clerk' => 'Shire Clerk',
	'Local Seneschal' => 'Baronial Seneschal',
	'Local Chancellor' => 'Ducal Chancellor',
	'Local General Minister' => 'Grand Ducal General Minister',
	'Kingdom Prime Minister' => 'Kingdom Prime Minister',
	'Kingdom Regent' => 'Kingdom Regent',
	'Local Champion' => 'Baronial Champion',
	'Local Ducal Champion' => 'Ducal Defender',
	'Local Grand Ducal Champion' => 'Grand Ducal Defender',
	'Kingdom Champion' => 'Kingdom Champion',
	'Weaponmaster' => 'Weaponmaster'
);

$kingdom->CreateAward(array(
	'Token' => $Token,
	'KingdomId' => $KingdomId,
	'AwardId' => 0,
	'Name' => 'Tsunami',
	'ReignLimit' => 1,
	'MonthLimit' => 0,
	'TitleClass' => 0,
	'IsTitle' => 0,
	'Peerage' => 'None'
));

$AwardList = $kingdom->GetAwardList(array(
	'IsLadder' => 'Either',
	'IsTitle' => 'Either',
	'KingdomId' => $KingdomId
));

$awardnames->clear();
$awardnames->find();
$award_map = array();
do {
	foreach ($AwardList['Awards'] as $idx => $awardinfo) {
		if ($awardinfo['KingdomAwardName'] == $award_namemap[$awardnames->awardname] || $awardinfo['KingdomAwardName'] == $awardnames->awardname) {
			$awardid = $awardinfo['KingdomAwardId'];
			break;
		}
	}
	$award_map[$awardnames->awardnamepk] = $awardid;
} while ($awardnames->next());

unset($AwardList);
unset($awardnames);

echo "<h1>Create Players</h1>";

/*******************************************************************************
 *
 *	CACHES & VARS
 *		$award_map[wl] => ork
 *		$park_map[wl] => ork
 *		$class_map[wl] => ork
 *		$Token
 *		$KingdomId
 *
 ******************************************************************************/


$sql = "
	select m.*, p.persona, max(a.date) lastattendance, count(a.date) as attendancecount 
		from mundanes m 
			left join personas p on m.mundanepk = p.mundanefk
			left join attendance a on a.mundanefk = m.mundanepk
		group by m.mundanepk";
$count = 0;
$players = $WL->query($sql);
$player_map = array();
$Player = new APIModel('Player');

do {
	if ($players->attendancecount > 1) {
		$player = $Player->CreatePlayer(array(
			'Token' => $Token,
			'GivenName' => $players->first,
			'Surname' => $players->last,
			'OtherName' => '',
			'UserName' => $players->username,
			'Password' => $players->password,
			'Persona' => $players->persona,
			'Email' => $players->email,
			'ParkId' => $park_map[$players->parkfk],
			'KingdomId' => $KingdomId,
			'Restricted' => $players->restricted,
			'IsActive' => ($players->attendancecount > 2 && strtotime($players->lastattendance) > strtotime("-6 month"))?1:0,
			'Waivered' => 0,
			'Waiver' => '',
			'WaiverExt' => '',
			'HasHeraldry' => 0,
			'Heraldry' => '',
			'HasImage' => 0,
			'Image' => ''
		));
		$reconciled->clear();
		$reconciled->mundanefk = $players->mundanepk;
		$reconcile = array('Token' => $Token, 'ParkId' => $park_map[$players->parkfk], 'MundaneId' => $player['Detail'], 'Reconcile' => array());
		$limit = 25;
		if ($reconciled->find()) do {
			$reconcile['Reconcile'][] = array(
				'ClassId' => $class_map[$reconciled->classfk],
				'Quantity' => $reconciled->credits
			);
		} while ($reconciled->next() && $limit--);
		$Player->SetPlayerReconciledCredits($reconcile);
		$count++;
		if ($count % 50 == 0) {
			gc_enable();
			gc_collect_cycles();
			set_time_limit(10);
			echo "$count records processed ... " . memory_get_usage() . " ...";
			//break;
		}
		$player_map[$players->mundanepk] = $player['Detail'];
	}
} while ($players->next());

unset($players);
unset($reconciled);
unset($reconcile);
gc_enable();
gc_collect_cycles();
echo memory_get_usage();

set_time_limit(30);

echo "<h2>Player Awards</h2>";

$awards->clear();
$awards->find();
$awardcount = 0;
do {
	if (isset($player_map[$awards->mundanefk])) {
		$Player->AddAward(array(
			'Token' => $Token,
			'RecipientId' => $player_map[$awards->mundanefk],
			'ParkId' => 0,
			'KingdomId' => 0,
			'EventId' => 0,
			'KingdomAwardId' => $award_map[$awards->awardnamefk],
			'Rank' => $awards->rank,
			'Date' => $awards->date,
			'GivenById' => 0,
			'Note' => $awards->givenby
		));
		$awardcount++;
	}
	if ($awardcount % 250 == 0) {
		set_time_limit(10);
		gc_enable();
		gc_collect_cycles();
		echo "$awardcount records processed ... " . memory_get_usage() . " ...";
	}
} while ($awards->next());

unset($awards);
unset($Player);
unset($mundanes);
unset($parks);
unset($personas);
gc_enable();
gc_collect_cycles();
echo memory_get_usage();

echo "$awardcount Awards entered ...";

echo "<h2>Player Attendance</h2>";

$Attendance = new APIModel('Attendance');
$attendance->clear();
$attendance->find();
$attendancecount = 0;
do {
	if (isset($player_map[$attendance->mundanefk])) {
		$Attendance->AddAttendance(array(
			'Token' => $Token,
			'ClassId' => $class_map[$attendance->classfk],
			'MundaneId' => $player_map[$attendance->mundanefk],
			'Date' => $attendance->date,
			'Credits' => max($attendance->credits, $attendance->eventcredits),
			'ParkId' => $attendance->event?0:$park_map[$attendance->park],
			'KingdomId' => $KingdomId,
			'EventCalendarDetailId' => 0 
		));
		$attendancecount++;
	}
	if ($attendancecount % 250 == 0) {
		set_time_limit(10);
		gc_enable();
		gc_collect_cycles();
		echo "$attendancecount records processed ... " . memory_get_usage() . " ...";;
	}
} while ($attendance->next());

echo "$attendancecount Attendance records entered ...";

?>