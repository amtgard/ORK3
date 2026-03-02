<?php

class Controller_KingdomAjax extends Controller {

	public function kingdom($p = null) {
		header('Content-Type: application/json');
		$parts      = explode('/', $p ?? '');
		$kingdom_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
		$action     = $parts[1] ?? '';

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		if (!valid_id($kingdom_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid kingdom ID']);
			exit;
		}

		if ($action === 'setofficers') {
			$this->load_model('Kingdom');

			// Collect officer assignments: any POST key ending in "Id" with a valid int value
			$officers = [];
			foreach ($_POST as $key => $val) {
				if (preg_match('/^(.+)Id$/', $key, $m) && valid_id((int)$val)) {
					$role = str_replace('_', ' ', $m[1]);
					$officers[$role] = ['MundaneId' => (int)$val, 'Role' => $role];
				}
			}

			if (empty($officers)) {
				echo json_encode(['status' => 1, 'error' => 'No officer assignments provided.']);
				exit;
			}

			$results = $this->Kingdom->set_officers($this->session->token, $kingdom_id, $officers);
			$errors  = [];
			foreach ($results as $r) {
				if (isset($r['Status']) && $r['Status'] != 0) {
					$errors[] = ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '');
				}
			}

			if ($errors) {
				echo json_encode(['status' => 1, 'error' => implode('; ', $errors)]);
			} else {
				echo json_encode(['status' => 0]);
			}

		} elseif ($action === 'vacateofficer') {
			$this->load_model('Kingdom');
			$role = trim($_POST['Role'] ?? '');

			if (!strlen($role)) {
				echo json_encode(['status' => 1, 'error' => 'Role is required.']);
				exit;
			}

			$r = $this->Kingdom->vacate_officer($kingdom_id, $role, $this->session->token);
			if (!isset($r['Status']) || $r['Status'] == 0) {
				echo json_encode(['status' => 0]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}

		} elseif ($action === 'setdetails') {
			$this->load_model('Kingdom');
			$name = trim($_POST['Name'] ?? '');
			$abbr = preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['Abbreviation'] ?? ''));

			if (!strlen($name)) {
				echo json_encode(['status' => 1, 'error' => 'Kingdom name is required.']);
				exit;
			}
			if (!strlen($abbr)) {
				echo json_encode(['status' => 1, 'error' => 'Abbreviation is required.']);
				exit;
			}

			$request = [
				'Token'        => $this->session->token,
				'KingdomId'    => $kingdom_id,
				'Name'         => $name,
				'Abbreviation' => $abbr,
			];

			if (!empty($_FILES['Heraldry']['tmp_name']) && is_uploaded_file($_FILES['Heraldry']['tmp_name'])) {
				$allowed = ['image/png', 'image/jpeg', 'image/gif'];
				if (in_array($_FILES['Heraldry']['type'], $allowed)) {
					$request['Heraldry']         = base64_encode(file_get_contents($_FILES['Heraldry']['tmp_name']));
					$request['HeraldryMimeType'] = $_FILES['Heraldry']['type'];
				}
			}

			$r = $this->Kingdom->set_kingdom_details($request);
			echo $r['Status'] == 0
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'setconfig') {
			$this->load_model('Kingdom');
			$configs = $_POST['Config'] ?? [];

			if (!is_array($configs) || empty($configs)) {
				echo json_encode(['status' => 1, 'error' => 'No configuration data provided.']);
				exit;
			}

			$configList = [];
			foreach ($configs as $configId => $value) {
				$configList[] = [
					'Action'          => CFG_EDIT,
					'ConfigurationId' => (int)$configId,
					'Key'             => null,
					'Value'           => $value,
				];
			}

			$r = $this->Kingdom->set_kingdom_details([
				'Token'                => $this->session->token,
				'KingdomId'            => $kingdom_id,
				'KingdomConfiguration' => $configList,
			]);
			echo $r['Status'] == 0
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'setparktitles') {
			$this->load_model('Kingdom');
			$titles  = $_POST['Title']             ?? [];
			$classes = $_POST['Class']             ?? [];
			$minAtts = $_POST['MinimumAttendance'] ?? [];
			$minCuts = $_POST['MinimumCutoff']     ?? [];
			$periods = $_POST['Period']            ?? [];
			$lengths = $_POST['Length']            ?? [];

			$edits = [];
			foreach ($titles as $id => $title) {
				$title = trim($title);
				if ($id === 'New' && !strlen($title)) continue;
				$edits[] = [
					'Action'            => ($id === 'New') ? CFG_ADD : CFG_EDIT,
					'ParkTitleId'       => ($id === 'New') ? 0 : (int)$id,
					'Title'             => $title,
					'Class'             => (int)($classes[$id] ?? 0),
					'MinimumAttendance' => (int)($minAtts[$id] ?? 0),
					'MinimumCutoff'     => (int)($minCuts[$id] ?? 0),
					'Period'            => $periods[$id]         ?? 'month',
					'PeriodLength'      => (int)($lengths[$id]  ?? 1),
				];
			}

			if (empty($edits)) {
				echo json_encode(['status' => 1, 'error' => 'No park title data provided.']);
				exit;
			}

			$r = $this->Kingdom->set_kingdom_parktitles([
				'Token'      => $this->session->token,
				'KingdomId'  => $kingdom_id,
				'ParkTitles' => $edits,
			]);
			echo $r['Status'] == 0
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'deletetitle') {
			$this->load_model('Kingdom');
			$titleId = (int)($_POST['ParkTitleId'] ?? 0);

			if (!valid_id($titleId)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid park title ID.']);
				exit;
			}

			$r = $this->Kingdom->set_kingdom_parktitles([
				'Token'      => $this->session->token,
				'KingdomId'  => $kingdom_id,
				'ParkTitles' => [['Action' => CFG_REMOVE, 'ParkTitleId' => $titleId]],
			]);
			echo $r['Status'] == 0
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'setaward') {
			$this->load_model('Kingdom');
			$kawId   = (int)($_POST['KingdomAwardId']  ?? 0);
			$name    = trim($_POST['KingdomAwardName'] ?? '');
			$reign   = (int)($_POST['ReignLimit']      ?? 0);
			$month   = (int)($_POST['MonthLimit']      ?? 0);
			$isTitle = (int)($_POST['IsTitle']         ?? 0);
			$tClass  = (int)($_POST['TitleClass']      ?? 0);

			if (!strlen($name)) {
				echo json_encode(['status' => 1, 'error' => 'Award name is required.']);
				exit;
			}

			if ($kawId > 0) {
				$r = $this->Kingdom->EditAward([
					'Token'          => $this->session->token,
					'KingdomId'      => $kingdom_id,
					'KingdomAwardId' => $kawId,
					'Name'           => $name,
					'ReignLimit'     => $reign,
					'MonthLimit'     => $month,
					'IsTitle'        => $isTitle,
					'TitleClass'     => $tClass,
				]);
			} else {
				$awardId = (int)($_POST['AwardId'] ?? 0);
				if (!valid_id($awardId)) {
					echo json_encode(['status' => 1, 'error' => 'Canonical Award ID is required for new awards.']);
					exit;
				}
				$r = $this->Kingdom->CreateAward([
					'Token'      => $this->session->token,
					'KingdomId'  => $kingdom_id,
					'AwardId'    => $awardId,
					'Name'       => $name,
					'ReignLimit' => $reign,
					'MonthLimit' => $month,
					'IsTitle'    => $isTitle,
					'TitleClass' => $tClass,
				]);
			}

			echo (!isset($r['Status']) || $r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'deleteaward') {
			$this->load_model('Kingdom');
			$kawId = (int)($_POST['KingdomAwardId'] ?? 0);

			if (!valid_id($kawId)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
				exit;
			}

			$this->Kingdom->RemoveAward([
				'Token'          => $this->session->token,
				'KingdomId'      => $kingdom_id,
				'KingdomAwardId' => $kawId,
			]);
			echo json_encode(['status' => 0]);

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}

	public function calendar($p = null) {
		header('Content-Type: application/json');
		$kingdom_id = (int)preg_replace('/[^0-9]/', '', $p ?? '');

		if (!valid_id($kingdom_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid kingdom ID']);
			exit;
		}

		$start = preg_replace('/[^0-9\-]/', '', substr($_GET['start'] ?? '', 0, 10));
		$end   = preg_replace('/[^0-9\-]/', '', substr($_GET['end']   ?? '', 0, 10));

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid date range']);
			exit;
		}

		$kid    = (int)$kingdom_id;
		global $DB;
		$events = [];

		// Events in range (all calendar-detail occurrences within window)
		$evtSql = "
			SELECT e.event_id, e.name, e.park_id, p.abbreviation AS park_abbr,
			       cd.event_start, cd.event_end, cd.event_calendardetail_id AS detail_id
			FROM ork_event e
			LEFT JOIN ork_park p ON p.park_id = e.park_id
			INNER JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
			WHERE e.kingdom_id = {$kid}
			  AND cd.event_start >= '{$start}'
			  AND cd.event_start < '{$end}'
			ORDER BY cd.event_start";
		$evtResult = $DB->DataSet($evtSql);
		if ($evtResult && $evtResult->Size() > 0) {
			while ($evtResult->Next()) {
				$isPark = (int)$evtResult->park_id > 0;
				$abbr   = ($isPark && $evtResult->park_abbr) ? $evtResult->park_abbr . ': ' : '';
				$eid    = (int)$evtResult->event_id;
				$did    = (int)$evtResult->detail_id;
				$ev = [
					'title' => $abbr . $evtResult->name,
					'start' => $evtResult->event_start,
					'url'   => UIR . ($did ? "Event/detail/{$eid}/{$did}" : "Event/template/{$eid}"),
					'color' => $isPark ? '#6b46c1' : '#0891b2',
					'type'  => $isPark ? 'park-event' : 'kingdom-event',
				];
				$endRaw = $evtResult->event_end ?? '';
				if ($endRaw && substr($endRaw, 0, 10) > substr($evtResult->event_start, 0, 10)) {
					$endDt = new DateTime(substr($endRaw, 0, 10));
					$endDt->modify('+1 day');
					$ev['end'] = $endDt->format('Y-m-d');
				}
				$events[] = $ev;
			}
		}

		// Park day recurrences expanded for the requested range
		$pdSql = "
			SELECT pd.park_id, pd.recurrence, pd.week_day, pd.week_of_month,
			       pd.month_day, pd.time, pd.purpose, p.abbreviation AS park_abbr
			FROM ork_parkday pd
			JOIN ork_park p ON p.park_id = pd.park_id
			WHERE p.kingdom_id = {$kid} AND p.active = 'Active'";
		$pdResult = $DB->DataSet($pdSql);
		if ($pdResult && $pdResult->Size() > 0) {
			$dayNames   = ['Sunday'=>0,'Monday'=>1,'Tuesday'=>2,'Wednesday'=>3,'Thursday'=>4,'Friday'=>5,'Saturday'=>6];
			$rangeStart = new DateTime($start);
			$rangeEnd   = new DateTime($end);
			while ($pdResult->Next()) {
				switch ($pdResult->purpose) {
					case 'fighter-practice': $purposeLabel = 'Fighter Practice'; break;
					case 'arts-day':         $purposeLabel = 'A&S Day'; break;
					case 'park-day':         $purposeLabel = 'Park Day'; break;
					default:                 $purposeLabel = ucwords(str_replace('-', ' ', $pdResult->purpose));
				}
				$abbr    = $pdResult->park_abbr ? $pdResult->park_abbr . ': ' : '';
				$title   = $abbr . $purposeLabel;
				$url     = UIR . 'Park/profile/' . (int)$pdResult->park_id;
				$timeStr = ($pdResult->time && $pdResult->time !== '00:00:00') ? 'T' . $pdResult->time : '';
				$rec     = $pdResult->recurrence;

				if ($rec === 'weekly') {
					$targetWd = $dayNames[$pdResult->week_day] ?? -1;
					if ($targetWd < 0) continue;
					$cur = clone $rangeStart;
					while ((int)$cur->format('w') !== $targetWd) { $cur->modify('+1 day'); }
					while ($cur < $rangeEnd) {
						$events[] = ['title'=>$title,'start'=>$cur->format('Y-m-d').$timeStr,'url'=>$url,'color'=>'#b7791f','type'=>'park-day'];
						$cur->modify('+7 days');
					}
				} elseif ($rec === 'week-of-month') {
					$targetWd = $dayNames[$pdResult->week_day] ?? -1;
					$nth = (int)$pdResult->week_of_month;
					if ($targetWd < 0 || $nth < 1) continue;
					$curMonth = clone $rangeStart;
					$curMonth->modify('first day of this month');
					while ($curMonth < $rangeEnd) {
						$cnt = 0; $cur = clone $curMonth;
						$mn  = (int)$curMonth->format('n');
						while ((int)$cur->format('n') === $mn) {
							if ((int)$cur->format('w') === $targetWd && ++$cnt === $nth) {
								if ($cur >= $rangeStart && $cur < $rangeEnd) {
									$events[] = ['title'=>$title,'start'=>$cur->format('Y-m-d').$timeStr,'url'=>$url,'color'=>'#b7791f','type'=>'park-day'];
								}
								break;
							}
							$cur->modify('+1 day');
						}
						$curMonth->modify('first day of next month');
					}
				} elseif ($rec === 'monthly') {
					$dayNum = (int)$pdResult->month_day;
					if ($dayNum < 1) continue;
					$curMonth = clone $rangeStart;
					$curMonth->modify('first day of this month');
					while ($curMonth < $rangeEnd) {
						$mEnd = clone $curMonth; $mEnd->modify('last day of this month');
						$d    = min($dayNum, (int)$mEnd->format('d'));
						$cur  = new DateTime($curMonth->format('Y-m-') . sprintf('%02d', $d));
						if ($cur >= $rangeStart && $cur < $rangeEnd) {
							$events[] = ['title'=>$title,'start'=>$cur->format('Y-m-d').$timeStr,'url'=>$url,'color'=>'#b7791f','type'=>'park-day'];
						}
						$curMonth->modify('first day of next month');
					}
				}
			}
		}

		echo json_encode(['status' => 0, 'events' => $events]);
		exit;
	}

	public function playersearch($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode([]);
			exit;
		}

		$kingdom_id = (int)preg_replace('/[^0-9]/', '', $p ?? '');
		if (!valid_id($kingdom_id)) {
			echo json_encode([]);
			exit;
		}

		$q = trim($_GET['q'] ?? '');
		if (strlen($q) < 2) {
			echo json_encode([]);
			exit;
		}

		global $DB;
		$kid  = $kingdom_id;
		$term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $q);

		$sql = "
			SELECT m.mundane_id, m.persona, p.park_id, k.kingdom_id,
			       k.name AS kingdom_name, p.name AS park_name,
			       p.abbreviation AS p_abbr, k.abbreviation AS k_abbr,
			       m.suspended,
			       CASE WHEN m.kingdom_id = {$kid} THEN 0 ELSE 1 END AS sort_priority
			FROM ork_mundane m
			LEFT JOIN ork_kingdom k ON k.kingdom_id = m.kingdom_id
			LEFT JOIN ork_park p ON p.park_id = m.park_id
			WHERE m.suspended = 0 AND m.active = 1 AND LENGTH(m.persona) > 0
			  AND (m.persona LIKE '%{$term}%'
			    OR m.given_name LIKE '%{$term}%'
			    OR m.surname LIKE '%{$term}%'
			    OR m.username LIKE '%{$term}%')
			ORDER BY sort_priority, m.persona
			LIMIT 10";

		$rs      = $DB->DataSet($sql);
		$results = [];
		while ($rs->Next()) {
			$results[] = [
				'MundaneId'   => (int)$rs->mundane_id,
				'Persona'     => $rs->persona,
				'KingdomId'   => (int)$rs->kingdom_id,
				'ParkId'      => (int)$rs->park_id,
				'KingdomName' => $rs->kingdom_name,
				'ParkName'    => $rs->park_name,
				'KAbbr'       => $rs->k_abbr,
				'PAbbr'       => $rs->p_abbr,
				'Suspended'   => (int)$rs->suspended,
			];
		}

		echo json_encode($results);
		exit;
	}
}
