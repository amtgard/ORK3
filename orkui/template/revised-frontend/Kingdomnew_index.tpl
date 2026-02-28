<?php
	/* -----------------------------------------------
	   Pre-process template data
	   ----------------------------------------------- */
	$parkList         = is_array($park_summary['KingdomParkAveragesSummary']) ? $park_summary['KingdomParkAveragesSummary'] : array();
	$parkCounts       = is_array($park_player_counts) ? $park_player_counts : [];
	$eventList        = is_array($event_summary) ? $event_summary : array();
	$tournamentList   = is_array($kingdom_tournaments['Tournaments']) ? $kingdom_tournaments['Tournaments'] : array();
	$principalityList = is_array($principalities['Principalities']) ? $principalities['Principalities'] : array();
	$officerList      = is_array($kingdom_officers['Officers']) ? $kingdom_officers['Officers'] : array();

	// Aggregate attendance across all parks
	$totalAtt = 0; $totalMonthly = 0;
	foreach ($parkList as $p) {
		$totalAtt     += (int)$p['AttendanceCount'];
		$totalMonthly += (int)$p['MonthlyCount'];
	}
	$avgWeek  = round($totalAtt / 26, 1);
	$avgMonth = round($totalMonthly / 12, 1);

	// Heraldry
	$hasHeraldry = $kingdom_info['Info']['KingdomInfo']['HasHeraldry'] == 1;
	$heraldryUrl = $hasHeraldry
		? $kingdom_info['HeraldryUrl']['Url']
		: HTTP_KINGDOM_HERALDRY . '0000.jpg';
	$entityLabel = $IsPrinz ? 'Principality' : 'Kingdom';

	// Extract Monarch & Regent for hero display
	$monarch = null; $regent = null;
	foreach ($officerList as $o) {
		if ($o['OfficerRole'] === 'Monarch') $monarch = $o;
		if ($o['OfficerRole'] === 'Regent')  $regent  = $o;
	}

	// Group kingdom players by 6-month activity period (same pattern as Parknew)
	$knAllPlayers = is_array($kingdom_players) ? $kingdom_players : [];
	$knNowTs = time();
	$knPlayerPeriods = [];
	foreach ($knAllPlayers as $p) {
		$ts = strtotime($p['LastSignin']);
		$period = max(0, (int)floor(($knNowTs - $ts) / (30.44 * 24 * 3600) / 6));
		$knPlayerPeriods[$period][] = $p;
	}
	ksort($knPlayerPeriods);

	// Pre-compute FullCalendar event data
	$knCalEvents = [];
	foreach ($eventList as $ev) {
		if (!$ev['NextDate'] || $ev['NextDate'] === '0000-00-00') continue;
		$knCalEvents[] = [
			'title'  => $ev['Name'],
			'start'  => $ev['NextDate'],
			'url'    => UIR . ($ev['NextDetailId'] ? 'Event/detail/' . $ev['EventId'] . '/' . $ev['NextDetailId'] : 'Event/template/' . $ev['EventId']),
			'isPark' => (bool)$ev['_IsParkEvent'],
			'color'  => $ev['_IsParkEvent'] ? '#718096' : '#276749',
		];
	}

	// Pre-compute map location data (server-side; embedded as JSON for lazy map init)
	$knMapLocations = [];
	foreach ((array)$map_parks as $p) {
		$loc = @json_decode(stripslashes((string)$p['Location']));
		if (!$loc) continue;
		$latlng = isset($loc->location) ? $loc->location : (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
		if (!$latlng || !is_numeric($latlng->lat) || !is_numeric($latlng->lng)) continue;
		$heraldryHtml = $p['HasHeraldry'] ? '<img src="' . HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $p['ParkId'])) . '" style="max-width:60px;display:block;margin-bottom:6px">' : '';
		$knMapLocations[] = [
			'name' => ucwords($p['Name']),
			'lat'  => (float)$latlng->lat,
			'lng'  => (float)$latlng->lng,
			'id'   => (int)$p['ParkId'],
			'info' => $heraldryHtml . '<p>' . nl2br(htmlspecialchars($p['Directions'])) . '</p><h4>Description</h4><p>' . nl2br(htmlspecialchars($p['Description'])) . '</p>',
		];
	}
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css">

<!-- =============================================
     ZONE 1: Hero Header
     ============================================= -->
<div class="kn-hero">
	<div class="kn-hero-bg" style="background-image: url('<?= $heraldryUrl ?>')"></div>
	<div class="kn-hero-content">

		<div class="kn-heraldry-frame">
			<img class="heraldry-img" src="<?= $heraldryUrl ?>" alt="<?= htmlspecialchars($kingdom_name) ?>" />
		</div>

		<div class="kn-hero-info">
			<h1 class="kn-kingdom-name"><?= htmlspecialchars($kingdom_name) ?></h1>
			<div class="kn-badges">
				<span class="kn-badge kn-badge-green">
					<i class="fas fa-shield-alt"></i> <?= $entityLabel ?>
				</span>
			</div>
			<div class="kn-officers-inline">
				<?php if ($monarch): ?>
					<i class="fas fa-crown" style="font-size:10px;opacity:0.6;margin-right:3px"></i>
					Monarch:&nbsp;
					<?php if (!empty($monarch['MundaneId']) && $monarch['MundaneId'] > 0): ?>
						<a href="<?= UIR ?>Player/index/<?= $monarch['MundaneId'] ?>"><?= htmlspecialchars($monarch['Persona']) ?></a>
					<?php else: ?>
						<span class="kn-vacant">Vacant</span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="kn-hero-actions">
			<a class="kn-btn kn-btn-white" href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">
				<i class="fas fa-search"></i> Search Players
			</a>
			<?php if ($LoggedIn): ?>
				<button class="kn-btn kn-btn-outline" onclick="knOpenAwardModal()">
					<i class="fas fa-medal"></i> Enter Awards
				</button>
			<?php endif; ?>
			<a class="kn-btn kn-btn-outline" href="#" onclick="knActivateTab('map');return false;">
				<i class="fas fa-map"></i> Atlas
			</a>
			<?php if ($LoggedIn): ?>
				<?php if ($CanManageKingdom ?? false): ?>
				<button class="kn-btn kn-btn-outline" onclick="knOpenAdminModal()">
					<i class="fas fa-cog"></i> Admin
				</button>
				<?php else: ?>
				<a class="kn-btn kn-btn-outline" href="<?= UIR ?>Admin/kingdom/<?= $kingdom_id ?>">
					<i class="fas fa-cog"></i> Admin
				</a>
				<?php endif; ?>
			<?php endif; ?>
		</div>

	</div>
</div>

<!-- =============================================
     ZONE 2: Dashboard Stats
     ============================================= -->
<div class="kn-stats-row">
	<div class="kn-stat-card kn-stat-card-link" onclick="knActivateTab('parks')">
		<div class="kn-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
		<div class="kn-stat-number"><?= count($parkList) ?></div>
		<div class="kn-stat-label">Parks</div>
	</div>
	<div class="kn-stat-card kn-stat-card-link" onclick="knActivateTab('events')">
		<div class="kn-stat-icon"><i class="fas fa-calendar-alt"></i></div>
		<div class="kn-stat-number"><?= count($eventList) ?></div>
		<div class="kn-stat-label">Events</div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-users"></i></div>
		<div class="kn-stat-number"><?= $avgWeek ?></div>
		<div class="kn-stat-label">Avg / Week</div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-chart-line"></i></div>
		<div class="kn-stat-number"><?= $avgMonth ?></div>
		<div class="kn-stat-label">Avg / Month</div>
	</div>
</div>

<!-- =============================================
     ZONE 3: Sidebar + Main Content
     ============================================= -->
<div class="kn-layout">

	<!-- ========== SIDEBAR ========== -->
	<div class="kn-sidebar">

		<!-- Officers -->
		<?php if (count($officerList) > 0 || ($CanManageKingdom ?? false)): ?>
		<div class="kn-card">
			<h4 style="display:flex;align-items:center;justify-content:space-between;">
				<span><i class="fas fa-crown"></i> Officers</span>
				<?php if ($CanManageKingdom ?? false): ?>
				<button onclick="knOpenEditOfficersModal()" class="kn-edit-officers-btn" title="Edit officers">
					<i class="fas fa-pencil-alt"></i>
				</button>
				<?php endif; ?>
			</h4>
			<ul class="kn-officer-list">
				<?php foreach ($officerList as $o): ?>
				<li>
					<span class="kn-officer-role"><?= htmlspecialchars($o['OfficerRole']) ?></span>
					<span class="kn-officer-name">
						<?php if (!empty($o['MundaneId']) && $o['MundaneId'] > 0): ?>
							<a href="<?= UIR ?>Player/index/<?= $o['MundaneId'] ?>"><?= htmlspecialchars($o['Persona']) ?></a>
						<?php else: ?>
							<em style="color:#a0aec0">Vacant</em>
						<?php endif; ?>
					</span>
				</li>
				<?php endforeach; ?>
				<?php if (count($officerList) === 0): ?>
				<li><em style="color:#a0aec0;font-size:12px">No officers on record</em></li>
				<?php endif; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- Quick Links -->
		<div class="kn-card">
			<h4><i class="fas fa-link"></i> Quick Links</h4>
			<ul class="kn-link-list">
				<li>
					<span class="kn-link-icon"><i class="fas fa-search"></i></span>
					<a href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">Search Players</a>
				</li>
				<?php if ($LoggedIn): ?>
					<li>
						<span class="kn-link-icon"><i class="fas fa-medal"></i></span>
						<a href="<?= UIR ?>Award/kingdom/<?= $kingdom_id ?>">Enter Awards</a>
					</li>
				<?php endif; ?>
				<li>
					<span class="kn-link-icon"><i class="fas fa-map-marked-alt"></i></span>
					<a href="#" onclick="knActivateTab('map');return false;">Kingdom Atlas</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-coins"></i></span>
					<a href="<?= UIR ?>Treasury/kingdom/<?= $kingdom_id ?>">Treasury</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-users"></i></span>
					<a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Companies &amp; Households</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-calendar"></i></span>
					<a href="<?= UIR ?>Search/event&KingdomId=<?= $kingdom_id ?>">Find Events</a>
				</li>
				<?php if ($LoggedIn): ?>
					<li>
						<span class="kn-link-icon"><i class="fas fa-cog"></i></span>
						<a href="<?= UIR ?>Admin/kingdom/<?= $kingdom_id ?>">Admin Panel</a>
					</li>
				<?php endif; ?>
			</ul>
		</div>

	</div>

	<!-- ========== MAIN CONTENT (Tabbed) ========== -->
	<div class="kn-main">
		<div class="kn-tabs">
			<ul class="kn-tab-nav">
				<li class="kn-tab-active" data-kntab="parks">
					<i class="fas fa-map-marker-alt"></i> Parks
					<span class="kn-tab-count">(<?= count($parkList) ?>)</span>
				</li>
				<li data-kntab="events">
					<i class="fas fa-calendar-alt"></i> Events
					<span class="kn-tab-count">(<?= count($eventList) ?>)</span>
				</li>
				<li data-kntab="map">
					<i class="fas fa-map"></i> Map
				</li>
				<?php if (!$IsPrinz && count($principalityList) > 0): ?>
					<li data-kntab="principalities">
						<i class="fas fa-shield-alt"></i> Principalities
						<span class="kn-tab-count">(<?= count($principalityList) ?>)</span>
					</li>
				<?php endif; ?>
				<li data-kntab="players">
					<i class="fas fa-users"></i> Players
					<span class="kn-tab-count">(<?= count($knAllPlayers) ?>)</span>
				</li>
				<li data-kntab="reports">
					<i class="fas fa-chart-bar"></i> Reports
				</li>
			</ul>

			<!-- Parks Tab -->
			<div class="kn-tab-panel" id="kn-tab-parks">
				<?php
					// Pre-sort alphabetically so tiles match default list order
					usort($parkList, function($a, $b) { return strcmp($a['ParkName'], $b['ParkName']); });
				?>
				<?php if (count($parkList) > 0): ?>

					<!-- Toolbar -->
					<div class="kn-parks-toolbar">
						<button class="kn-view-btn" id="kn-view-tiles" title="Tile view">
							<i class="fas fa-th-large"></i>
						</button>
						<button class="kn-view-btn" id="kn-view-list" title="List view">
							<i class="fas fa-list"></i>
						</button>
						<?php if ($CanManageKingdom ?? false): ?>
						<button onclick="knOpenAddParkModal()" style="margin-left:auto;display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;">
							<i class="fas fa-plus"></i> Add Park
						</button>
						<?php endif; ?>
					</div>

					<!-- Tile view -->
					<div id="kn-parks-tiles" class="kn-park-tiles">
						<?php foreach ($parkList as $park): ?>
							<?php $tileHeraldry = $park['HasHeraldry'] == 1
								? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId']))
								: HTTP_PARK_HERALDRY . '00000.jpg'; ?>
							<a class="kn-park-tile" href="<?= UIR ?>Park/index/<?= $park['ParkId'] ?>">
								<div class="kn-park-tile-img-wrap">
									<img src="<?= $tileHeraldry ?>"
										onerror="this.src='<?= HTTP_PARK_HERALDRY ?>00000.jpg'"
										alt="<?= htmlspecialchars($park['ParkName']) ?>">
								</div>
								<div class="kn-park-tile-body">
									<div class="kn-park-tile-name"><?= htmlspecialchars($park['ParkName']) ?></div>
									<div class="kn-park-tile-type"><?= htmlspecialchars(!empty($park['Title']) ? $park['Title'] : 'Park') ?></div>
									<div class="kn-park-tile-stats">
										<div class="kn-park-tile-stat">
											<div class="kn-park-tile-stat-val"><?= sprintf("%.1f", $park['AttendanceCount'] / 26) ?></div>
											<div class="kn-park-tile-stat-lbl">Avg/Wk</div>
										</div>
										<div class="kn-park-tile-stat">
											<div class="kn-park-tile-stat-val"><?= sprintf("%.1f", $park['MonthlyCount'] / 12) ?></div>
											<div class="kn-park-tile-stat-lbl">Avg/Mo</div>
										</div>
									</div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>

					<!-- List view -->
					<div id="kn-parks-list-view" style="display:none">
						<table class="kn-table kn-sortable" id="kn-parks-table">
							<thead>
								<tr>
									<th data-sorttype="text">Park</th>
									<th data-sorttype="text">Type</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Average unique sign-ins per week over 26 weeks">Avg/Wk</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Average unique sign-ins per month over 12 months">Avg/Mo</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Distinct players who signed in at this park in the past 12 months">Total Players</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Distinct players whose home park is here who signed in at this park in the past 12 months">Total Members</th>
									<?php if ($CanManageKingdom ?? false): ?><th data-sorttype="none" style="width:32px"></th><?php endif; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($parkList as $park): ?>
								<?php $pc = $parkCounts[(int)$park['ParkId']] ?? ['TotalPlayers' => 0, 'TotalMembers' => 0]; ?>
									<tr class="kn-row-link" onclick="window.location.href='<?= UIR ?>Park/index/<?= $park['ParkId'] ?>'">
										<td class="kn-col-nowrap">
											<img class="kn-thumb"
												src="<?= $park['HasHeraldry'] == 1 ? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId'])) : HTTP_PARK_HERALDRY . '00000.jpg' ?>"
												onerror="this.src='<?= HTTP_PARK_HERALDRY ?>00000.jpg'"
												alt="">
											<a href="<?= UIR ?>Park/index/<?= $park['ParkId'] ?>"><?= htmlspecialchars($park['ParkName']) ?></a>
										</td>
										<td><?= htmlspecialchars(!empty($park['Title']) ? $park['Title'] : '') ?></td>
										<td class="kn-col-numeric"><?= sprintf("%.2f", $park['AttendanceCount'] / 26) ?></td>
										<td class="kn-col-numeric"><?= sprintf("%.1f", $park['MonthlyCount'] / 12) ?></td>
										<td class="kn-col-numeric" data-sortval="<?= $pc['TotalPlayers'] ?>"><?= $pc['TotalPlayers'] ?></td>
										<td class="kn-col-numeric" data-sortval="<?= $pc['TotalMembers'] ?>"><?= $pc['TotalMembers'] ?></td>
										<?php if ($CanManageKingdom ?? false): ?>
										<td class="kn-col-edit" onclick="event.stopPropagation();knOpenEditParkModal(<?= (int)$park['ParkId'] ?>)" title="Edit park">
											<i class="fas fa-pencil-alt"></i>
										</td>
										<?php endif; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr>
									<td colspan="2">Kingdom Total</td>
									<td class="kn-col-numeric"><?= sprintf("%.2f", $totalAtt / 26) ?></td>
									<td class="kn-col-numeric"><?= sprintf("%.1f", $totalMonthly / 12) ?></td>
									<td class="kn-col-numeric" title="Sum across parks (players may be counted in multiple parks)"><?= array_sum(array_column($parkCounts, 'TotalPlayers')) ?></td>
									<td class="kn-col-numeric"><?= array_sum(array_column($parkCounts, 'TotalMembers')) ?></td>
									<?php if ($CanManageKingdom ?? false): ?><td></td><?php endif; ?>
								</tr>
							</tfoot>
						</table>
					</div>

				<?php else: ?>
					<div class="kn-empty">No parks found</div>
				<?php endif; ?>
			</div>

			<!-- Events Tab -->
			<div class="kn-tab-panel" id="kn-tab-events" style="display:none">
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
					<h4 style="margin:0;font-size:14px;font-weight:700;color:#4a5568;"><i class="fas fa-calendar-alt" style="margin-right:6px;color:#a0aec0"></i>Events</h4>
					<div style="display:flex;align-items:center;gap:8px;">
						<button class="kn-view-btn kn-view-active" id="kn-ev-view-list" title="List view"><i class="fas fa-list"></i></button>
						<button class="kn-view-btn" id="kn-ev-view-cal" title="Calendar view"><i class="fas fa-calendar-alt"></i></button>
						<button id="kn-park-toggle" onclick="knToggleParkItems(this)" style="display:inline-flex;align-items:center;gap:5px;border:1px solid #cbd5e0;background:#fff;border-radius:5px;padding:5px 10px;font-size:12px;color:#718096;cursor:pointer;font-weight:500;">
							<i class="fas fa-map-marker-alt"></i> Park Events &amp; Tournaments <span id="kn-park-toggle-label" style="font-weight:700;color:#a0aec0">OFF</span>
						</button>
						<?php if ($CanManageKingdom): ?>
						<button onclick="knOpenEventModal()" style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;border:none;cursor:pointer;">
							<i class="fas fa-plus"></i> Add Event
						</button>
						<?php endif; ?>
					</div>
				</div>
				<!-- Calendar view (lazy-loaded FullCalendar) -->
				<div id="kn-events-cal" style="display:none"></div>

				<!-- List view -->
				<div id="kn-events-list-view">
				<?php if (count($eventList) > 0): ?>
					<table class="kn-table kn-sortable" id="kn-events-table">
						<thead>
							<tr>
								<th data-sorttype="date">Next Date</th>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="text">Park</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($eventList as $event): ?>
								<tr class="kn-row-link<?= $event['_IsParkEvent'] ? ' kn-park-row' : '' ?>" style="<?= $event['_IsParkEvent'] ? 'display:none' : '' ?>" onclick="window.location.href='<?= UIR ?><?= $event['NextDetailId'] ? 'Event/detail/' . $event['EventId'] . '/' . $event['NextDetailId'] : 'Event/template/' . $event['EventId'] ?>'">
									<td class="kn-col-nowrap">
										<?= (0 != $event['NextDate'] && $event['NextDate'] != '0000-00-00')
											? date("M j, Y", strtotime($event['NextDate']))
											: '<span style="color:#a0aec0">—</span>' ?>
									</td>
									<td class="kn-col-nowrap">
										<img class="kn-thumb"
											src="<?= $event['HasHeraldry'] == 1 ? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf("%05d", $event['EventId'])) : HTTP_EVENT_HERALDRY . '00000.jpg' ?>"
											onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
											alt="">
										<a href="<?= UIR ?><?= $event['NextDetailId'] ? 'Event/detail/' . $event['EventId'] . '/' . $event['NextDetailId'] : 'Event/template/' . $event['EventId'] ?>"><?= htmlspecialchars($event['Name']) ?></a>
									</td>
									<td><?= htmlspecialchars($event['ParkName']) ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="kn-empty">No upcoming events</div>
				<?php endif; ?>
				</div><!-- /kn-events-list-view -->

				<div style="display:flex;align-items:center;justify-content:space-between;margin:20px 0 10px;border-top:1px solid #e2e8f0;padding-top:16px;">
					<h4 style="margin:0;font-size:14px;font-weight:700;color:#4a5568;"><i class="fas fa-trophy" style="margin-right:6px;color:#a0aec0"></i>Tournaments</h4>
					<?php if ($CanManageKingdom): ?>
					<a href="<?= UIR ?>Tournament/create&KingdomId=<?= $kingdom_id ?>" style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;">
						<i class="fas fa-plus"></i> Add Tournament
					</a>
					<?php endif; ?>
				</div>
				<?php if (count($tournamentList) > 0): ?>
					<table class="kn-table kn-sortable" id="kn-tournaments-table">
						<thead>
							<tr>
								<th data-sorttype="date">Date</th>
								<th data-sorttype="text">Tournament</th>
								<th data-sorttype="text">Park</th>
								<th data-sorttype="text">Event</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($tournamentList as $t): ?>
								<tr class="kn-row-link<?= (int)($t['ParkId'] ?? 0) > 0 ? ' kn-park-row' : '' ?>" style="<?= (int)($t['ParkId'] ?? 0) > 0 ? 'display:none' : '' ?>" onclick="window.location.href='<?= UIR ?>Tournament/worksheet/<?= $t['TournamentId'] ?>'">
									<td class="kn-col-nowrap"><?= date("M j, Y", strtotime($t['DateTime'])) ?></td>
									<td>
										<a href="<?= UIR ?>Tournament/worksheet/<?= $t['TournamentId'] ?>"><?= htmlspecialchars($t['Name']) ?></a>
									</td>
									<td><?= htmlspecialchars($t['ParkName']) ?></td>
									<td><?= htmlspecialchars($t['EventName']) ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="kn-empty">No tournaments found</div>
				<?php endif; ?>
			</div>


			<!-- Map Tab -->
			<div class="kn-tab-panel" id="kn-tab-map" style="display:none">
				<?php if (count($knMapLocations) > 0): ?>
					<div id="kn-map-loading" class="kn-map-loading">
						<i class="fas fa-spinner fa-spin" style="font-size:22px"></i>
						Loading map&hellip;
					</div>
					<div id="kn-map-container" style="display:none">
						<div class="kn-map-layout">
							<div class="kn-map-wrap">
								<div id="kn-map"></div>
							</div>
							<div class="kn-map-directions-wrap">
								<div class="kn-card">
									<h4 id="kn-directions-title"><i class="fas fa-directions"></i> Directions</h4>
									<div id="kn-map-directions">
										<p style="color:#a0aec0;font-style:italic">Click a park pin for details.</p>
									</div>
								</div>
							</div>
						</div>
					</div>
				<?php else: ?>
					<div class="kn-empty">No park location data available</div>
				<?php endif; ?>
			</div>

			<!-- Principalities Tab (only rendered if applicable) -->
			<?php if (!$IsPrinz && count($principalityList) > 0): ?>
				<div class="kn-tab-panel" id="kn-tab-principalities" style="display:none">
					<?php foreach ($principalityList as $prinz): ?>
						<div class="kn-prinz-row">
							<img class="kn-prinz-heraldry"
								src="<?= HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf("%04d", $prinz['KingdomId'])) ?>"
								onerror="this.src='<?= HTTP_KINGDOM_HERALDRY ?>0000.jpg'"
								alt="">
							<div class="kn-prinz-name">
								<a href="<?= UIR ?>Kingdom/index/<?= $prinz['KingdomId'] ?>&kingdom_name=<?= urlencode($prinz['Name']) ?>"><?= htmlspecialchars($prinz['Name']) ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Reports Tab -->
			<div class="kn-tab-panel" id="kn-tab-reports" style="display:none">
				<div class="kn-reports-grid">

					<div class="kn-report-group">
						<h5><i class="fas fa-users"></i> Players</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/roster/Kingdom&id=<?= $kingdom_id ?>">Player Roster</a></li>
							<li><a href="<?= UIR ?>Reports/active/Kingdom&id=<?= $kingdom_id ?>">Active Players</a></li>
							<li><a href="<?= UIR ?>Reports/dues/Kingdom&id=<?= $kingdom_id ?>">Dues Paid</a></li>
							<li><a href="<?= UIR ?>Reports/waivered/Kingdom&id=<?= $kingdom_id ?>">Waivered</a></li>
							<li><a href="<?= UIR ?>Reports/unwaivered/Kingdom&id=<?= $kingdom_id ?>">Unwaivered</a></li>
							<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kingdom_id ?>">Suspended</a></li>
							<li><a href="<?= UIR ?>Reports/active_duespaid/Kingdom&id=<?= $kingdom_id ?>">Player Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/active_waivered_duespaid/Kingdom&id=<?= $kingdom_id ?>">Waivered Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/reeve&KingdomId=<?= $kingdom_id ?>">Reeve Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/corpora&KingdomId=<?= $kingdom_id ?>">Corpora Qualified</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-medal"></i> Awards</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/player_award_recommendations&KingdomId=<?= $kingdom_id ?>">Award Recommendations</a></li>
							<li><a href="<?= UIR ?>Reports/knights_and_masters&KingdomId=<?= $kingdom_id ?>">Knights &amp; Masters</a></li>
							<li><a href="<?= UIR ?>Reports/knights_list&KingdomId=<?= $kingdom_id ?>">Knights</a></li>
							<li><a href="<?= UIR ?>Reports/masters_list&KingdomId=<?= $kingdom_id ?>">Masters</a></li>
							<li><a href="<?= UIR ?>Reports/player_awards&Ladder=8&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?>-level Awards</a></li>
							<li><a href="<?= UIR ?>Reports/class_masters&KingdomId=<?= $kingdom_id ?>">Class Masters/Paragons</a></li>
							<li><a href="<?= UIR ?>Reports/guilds&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?> Guilds</a></li>
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>">Custom Awards</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-calendar-check"></i> Attendance</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Weeks/1">Past Week</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/1">Past Month</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/3">Past 3 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/6">Past 6 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/12">Past 12 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/All">All Time</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-image"></i> Heraldry</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/parkheraldry/<?= $kingdom_id ?>"><?= $entityLabel ?> Heraldry, Parks</a></li>
							<li><a href="<?= UIR ?>Reports/playerheraldry/<?= $kingdom_id ?>"><?= $entityLabel ?> Heraldry, Players</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-search"></i> Find</h5>
						<ul>
							<li><a href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">Players</a></li>
							<li><a href="<?= UIR ?>Search/unit&KingdomId=<?= $kingdom_id ?>">Companies &amp; Households</a></li>
							<li><a href="<?= UIR ?>Search/event&KingdomId=<?= $kingdom_id ?>">Events</a></li>
							<li><a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Unit List</a></li>
						</ul>
					</div>

					<?php if ($CanManageKingdom ?? false): ?>
					<div class="kn-report-group">
						<h5><i class="fas fa-cog"></i> Admin</h5>
						<ul>
							<li><a href="<?= UIR ?>Admin/kingdom/<?= $kingdom_id ?>">Admin Panel</a></li>
							<li><a href="#" onclick="knOpenAddParkModal();return false;">Create Park</a></li>
							<li><a href="<?= UIR ?>Admin/editkingdom/<?= $kingdom_id ?>">Configure Kingdom</a></li>
							<li><a href="<?= UIR ?>Admin/editparks/<?= $kingdom_id ?>">Configure Parks</a></li>
							<li><a href="<?= UIR ?>Admin/setkilofficers/kingdom/<?= $kingdom_id ?>">Set Officers</a></li>
							<li><a href="<?= UIR ?>Admin/createplayer&KingdomId=<?= $kingdom_id ?>">Create Player</a></li>
							<li><a href="<?= UIR ?>Admin/mergeplayers">Merge Players</a></li>
							<li><a href="<?= UIR ?>Admin/suspensions/kingdom/<?= $kingdom_id ?>">Suspensions</a></li>
						</ul>
					</div>
					<?php endif; ?>

				</div>
			</div>

		<!-- Players Tab -->
		<div class="kn-tab-panel" id="kn-tab-players" style="display:none">
			<?php if (count($knAllPlayers) > 0): ?>
				<div class="kn-players-toolbar">
					<span class="kn-players-toolbar-left">
						<?= count($knPlayerPeriods[0] ?? []) ?> active member<?= count($knPlayerPeriods[0] ?? []) != 1 ? 's' : '' ?> (past 6 months)<?php if (count($knAllPlayers) > count($knPlayerPeriods[0] ?? [])): ?> &middot; <?= count($knAllPlayers) ?> total<?php endif; ?>
					</span>
					<div class="kn-players-toolbar-right">
						<div class="kn-player-search-wrap">
							<i class="fas fa-search kn-player-search-icon"></i>
							<input type="text" id="kn-player-search" class="kn-player-search-input" placeholder="Search all players&hellip;" autocomplete="off">
						</div>
						<div class="kn-view-toggle">
							<button class="kn-view-btn kn-view-active" data-knview="cards">
								<i class="fas fa-th-large"></i> Cards
							</button>
							<button class="kn-view-btn" data-knview="list">
								<i class="fas fa-list"></i> List
							</button>
						</div>
						<?php if ($CanManageKingdom ?? false): ?>
						<button class="plr-add-btn" onclick="knOpenAddPlayerModal()">
							<i class="fas fa-user-plus"></i> Add Player
						</button>
						<?php endif; ?>
					</div>
				</div>

				<!-- Card view (default) -->
				<div id="kn-players-cards">
					<!-- Period 0 (0–6 months) always visible -->
					<div class="kn-players-grid">
						<?php foreach ($knPlayerPeriods[0] ?? [] as $p): ?>
						<?php
							$knInitial = htmlspecialchars(strtoupper(mb_substr($p['Persona'], 0, 1)));
							$knHeraldryBgSrc = $p['HasHeraldry']
								? HTTP_PLAYER_HERALDRY . Common::resolve_image_ext(DIR_PLAYER_HERALDRY, sprintf('%06d', $p['MundaneId']))
								: null;
							if ($p['HasImage']) {
								$knAvatarSrc = HTTP_PLAYER_IMAGE . Common::resolve_image_ext(DIR_PLAYER_IMAGE, sprintf('%06d', $p['MundaneId']));
							} elseif ($p['HasHeraldry']) {
								$knAvatarSrc = $knHeraldryBgSrc;
							} else {
								$knAvatarSrc = null;
							}
						?>
						<a class="kn-player-card<?= $knHeraldryBgSrc ? ' kn-player-card-hbg' : '' ?>"
						   <?= $knHeraldryBgSrc ? 'style="--hbg: url(\'' . htmlspecialchars($knHeraldryBgSrc) . '\')"' : '' ?>
						   href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>">
							<div class="kn-player-card-top">
								<div class="kn-player-avatar">
									<?php if ($knAvatarSrc): ?>
										<img src="<?= htmlspecialchars($knAvatarSrc) ?>"
										     alt=""
										     onerror="knAvatarFallback(this,'<?= $knInitial ?>')">
									<?php else: ?>
										<?= $knInitial ?>
									<?php endif; ?>
								</div>
								<div>
									<div class="kn-player-name"><?= htmlspecialchars($p['Persona']) ?></div>
									<?php if (!empty($p['OfficerRoles'])): ?>
										<?php foreach (explode(', ', $p['OfficerRoles']) as $knRole): ?>
											<span class="kn-officer-pill"><?= htmlspecialchars(trim($knRole)) ?></span>
										<?php endforeach; ?>
									<?php endif; ?>
								</div>
							</div>
							<div class="kn-player-stats">
								<span><i class="fas fa-map-marker-alt" style="color:#68d391;width:14px"></i> <?= htmlspecialchars($p['ParkName']) ?></span>
								<span><i class="fas fa-check-circle" style="color:#68d391;width:14px"></i> <?= $p['SigninCount'] ?> sign-in<?= $p['SigninCount'] != 1 ? 's' : '' ?></span>
								<span><i class="fas fa-calendar-check" style="color:#63b3ed;width:14px"></i> <?= date('M j', strtotime($p['LastSignin'])) ?></span>
								<?php if (!empty($p['LastClass'])): ?>
									<span><i class="fas fa-shield-alt" style="color:#b794f4;width:14px"></i> <?= htmlspecialchars($p['LastClass']) ?></span>
								<?php endif; ?>
							</div>
						</a>
						<?php endforeach; ?>
					</div>

					<!-- Period 1+ (hidden; revealed by Load More) -->
					<?php foreach (array_slice($knPlayerPeriods, 1, null, true) as $knPeriod => $knPeriodPlayers): ?>
					<div class="kn-period-block" id="kn-players-block-<?= $knPeriod ?>" style="display:none">
						<div class="kn-period-label"><?= $knPeriod * 6 ?>–<?= ($knPeriod + 1) * 6 ?> months ago</div>
						<div class="kn-players-grid">
							<?php foreach ($knPeriodPlayers as $p): ?>
							<?php
								$knInitial = htmlspecialchars(strtoupper(mb_substr($p['Persona'], 0, 1)));
								$knHeraldryBgSrc = $p['HasHeraldry']
									? HTTP_PLAYER_HERALDRY . Common::resolve_image_ext(DIR_PLAYER_HERALDRY, sprintf('%06d', $p['MundaneId']))
									: null;
								if ($p['HasImage']) {
									$knAvatarSrc = HTTP_PLAYER_IMAGE . Common::resolve_image_ext(DIR_PLAYER_IMAGE, sprintf('%06d', $p['MundaneId']));
								} elseif ($p['HasHeraldry']) {
									$knAvatarSrc = $knHeraldryBgSrc;
								} else {
									$knAvatarSrc = null;
								}
							?>
							<a class="kn-player-card<?= $knHeraldryBgSrc ? ' kn-player-card-hbg' : '' ?>"
							   <?= $knHeraldryBgSrc ? 'style="--hbg: url(\'' . htmlspecialchars($knHeraldryBgSrc) . '\')"' : '' ?>
							   href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>">
								<div class="kn-player-card-top">
									<div class="kn-player-avatar">
										<?php if ($knAvatarSrc): ?>
											<img src="<?= htmlspecialchars($knAvatarSrc) ?>"
											     alt=""
											     onerror="knAvatarFallback(this,'<?= $knInitial ?>')">
										<?php else: ?>
											<?= $knInitial ?>
										<?php endif; ?>
									</div>
									<div>
										<div class="kn-player-name"><?= htmlspecialchars($p['Persona']) ?></div>
										<?php if (!empty($p['OfficerRoles'])): ?>
											<?php foreach (explode(', ', $p['OfficerRoles']) as $knRole): ?>
												<span class="kn-officer-pill"><?= htmlspecialchars(trim($knRole)) ?></span>
											<?php endforeach; ?>
										<?php endif; ?>
									</div>
								</div>
								<div class="kn-player-stats">
									<span><i class="fas fa-map-marker-alt" style="color:#68d391;width:14px"></i> <?= htmlspecialchars($p['ParkName']) ?></span>
									<span><i class="fas fa-check-circle" style="color:#68d391;width:14px"></i> <?= $p['SigninCount'] ?> sign-in<?= $p['SigninCount'] != 1 ? 's' : '' ?></span>
									<span><i class="fas fa-calendar-check" style="color:#63b3ed;width:14px"></i> <?= date('M j', strtotime($p['LastSignin'])) ?></span>
									<?php if (!empty($p['LastClass'])): ?>
										<span><i class="fas fa-shield-alt" style="color:#b794f4;width:14px"></i> <?= htmlspecialchars($p['LastClass']) ?></span>
									<?php endif; ?>
								</div>
							</a>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endforeach; ?>

					<?php if (count($knPlayerPeriods) > 1): ?>
					<div class="kn-load-more-wrap" data-next="1" data-group="kn-players">
						<button class="kn-load-more-btn" onclick="knLoadMoreCards('kn-players', this)">
							<i class="fas fa-chevron-down"></i> Load More...
						</button>
						<span class="kn-load-more-hint">Showing <?= count($knPlayerPeriods[0] ?? []) ?> of <?= count($knAllPlayers) ?> members</span>
					</div>
					<?php endif; ?>
				</div><!-- /kn-players-cards -->

				<!-- List view (hidden by default) -->
				<div id="kn-players-list" style="display:none">
					<table class="kn-table" id="kn-players-table">
						<thead>
							<tr>
								<th data-sorttype="text">Persona</th>
								<th data-sorttype="text">Park</th>
								<th data-sorttype="numeric">Sign-ins</th>
								<th data-sorttype="date">Last Visit</th>
								<th data-sorttype="text">Last Class</th>
								<th data-sorttype="text">Role</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($knPlayerPeriods[0] ?? [] as $p): ?>
							<tr onclick='window.location.href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>"'>
								<td>
									<?= htmlspecialchars($p['Persona']) ?>
									<?php if (!empty($p['OfficerRoles'])): ?>
										<?php foreach (explode(', ', $p['OfficerRoles']) as $knRole): ?>
											<span class="kn-officer-pill"><?= htmlspecialchars(trim($knRole)) ?></span>
										<?php endforeach; ?>
									<?php endif; ?>
								</td>
								<td><?= htmlspecialchars($p['ParkName'] ?? '') ?></td>
								<td data-sortval="<?= $p['SigninCount'] ?>"><?= $p['SigninCount'] ?></td>
								<td class="kn-date-col" data-sortval="<?= $p['LastSignin'] ?>">
									<?= date('M j, Y', strtotime($p['LastSignin'])) ?>
								</td>
								<td><?= htmlspecialchars($p['LastClass'] ?? '') ?></td>
								<td><?= htmlspecialchars($p['OfficerRoles'] ?? '') ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<!-- Hidden row templates for older periods -->
					<?php foreach (array_slice($knPlayerPeriods, 1, null, true) as $knPeriod => $knPeriodPlayers): ?>
					<template id="kn-players-tmpl-<?= $knPeriod ?>">
						<?php foreach ($knPeriodPlayers as $p): ?>
						<tr onclick='window.location.href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>"'>
							<td>
								<?= htmlspecialchars($p['Persona']) ?>
								<?php if (!empty($p['OfficerRoles'])): ?>
									<?php foreach (explode(', ', $p['OfficerRoles']) as $knRole): ?>
										<span class="kn-officer-pill"><?= htmlspecialchars(trim($knRole)) ?></span>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
							<td><?= htmlspecialchars($p['ParkName'] ?? '') ?></td>
							<td data-sortval="<?= $p['SigninCount'] ?>"><?= $p['SigninCount'] ?></td>
							<td class="kn-date-col" data-sortval="<?= $p['LastSignin'] ?>">
								<?= date('M j, Y', strtotime($p['LastSignin'])) ?>
							</td>
							<td><?= htmlspecialchars($p['LastClass'] ?? '') ?></td>
							<td><?= htmlspecialchars($p['OfficerRoles'] ?? '') ?></td>
						</tr>
						<?php endforeach; ?>
					</template>
					<?php endforeach; ?>
					<?php if (count($knPlayerPeriods) > 1): ?>
					<div class="kn-load-more-wrap kn-load-more-list" data-next="1">
						<button class="kn-load-more-btn" onclick="knLoadMoreList('kn-players-table', 'kn-players-tmpl', this)">
							<i class="fas fa-chevron-down"></i> Load More...
						</button>
						<span class="kn-load-more-hint">Showing <?= count($knPlayerPeriods[0] ?? []) ?> of <?= count($knAllPlayers) ?> members</span>
					</div>
					<?php endif; ?>
					<div class="kn-pagination" id="kn-players-table-pages"></div>
				</div><!-- /kn-players-list -->
			<?php else: ?>
				<div class="kn-empty">No players found</div>
			<?php endif; ?>
		</div><!-- /kn-tab-players -->

		</div><!-- /kn-tabs -->
	</div><!-- /kn-main -->

</div><!-- /kn-layout -->

<!-- =============================================
     JavaScript
     ============================================= -->
<script>
var KnConfig = {
	uir:              '<?= UIR ?>',
	httpService:      '<?= HTTP_SERVICE ?>',
	kingdomId:        <?= (int)($kingdom_id ?? 0) ?>,
	kingdomName:      <?= json_encode($kingdom_name ?? '') ?>,
	canManage:        <?= !empty($CanManageKingdom) ? 'true' : 'false' ?>,
	parkTitleOptions: <?= json_encode($ParkTitleId_options ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	parkEditLookup:   <?= json_encode($CanManageKingdom ? array_values($park_edit_lookup ?? []) : [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	officerList:      <?= json_encode($CanManageKingdom ? array_map(function($o) { return ['OfficerRole' => $o['OfficerRole'], 'MundaneId' => (int)$o['MundaneId'], 'Persona' => $o['Persona']]; }, $officerList) : [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	mapLocations:     <?= json_encode(array_values($knMapLocations ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	calEvents:        <?= json_encode(array_values($knCalEvents ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	preloadOfficers:  <?= json_encode($PreloadOfficers ?? []) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? '')) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? '')) ?>,
	adminInfo:       <?= json_encode($AdminInfo       ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminConfig:     <?= json_encode($AdminConfig     ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminParkTitles: <?= json_encode($AdminParkTitles ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminAwards:     <?= json_encode($AdminAwards     ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
};
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js"></script>
<?php if ($LoggedIn): ?>
<div id="kn-award-overlay">
	<div class="kn-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title" id="kn-award-modal-title"><i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award</h3>
			<button class="kn-modal-close-btn" id="kn-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body" id="kn-award-modal-body">
			<div class="kn-award-success" id="kn-award-success" style="display:none">
				<i class="fas fa-check-circle"></i> Award saved!
			</div>
			<div class="kn-form-error" id="kn-award-error"></div>

			<!-- Award Type Toggle -->
			<div class="kn-award-type-row">
				<button type="button" class="kn-award-type-btn kn-active" id="kn-award-type-awards">
					<i class="fas fa-medal" style="margin-right:5px"></i>Awards
				</button>
				<button type="button" class="kn-award-type-btn" id="kn-award-type-officers">
					<i class="fas fa-crown" style="margin-right:5px"></i>Officer Titles
				</button>
			</div>

			<!-- Player search -->
			<div class="kn-acct-field">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-award-player-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="kn-award-player-id" value="" />
				<div class="kn-ac-results" id="kn-award-player-results"></div>
			</div>

			<!-- Award Select -->
			<div class="kn-acct-field">
				<label for="kn-award-select">Award <span style="color:#e53e3e">*</span></label>
				<select id="kn-award-select" name="KingdomAwardId">
					<option value="">Select award...</option>
					<?= $AwardOptions ?>
				</select>
				<div class="kn-award-info-line" id="kn-award-info-line"></div>
			</div>

			<!-- Custom Award Name -->
			<div class="kn-acct-field" id="kn-award-custom-row" style="display:none">
				<label for="kn-award-custom-name">Custom Award Name</label>
				<input type="text" id="kn-award-custom-name" maxlength="64" placeholder="Enter custom award name..." />
			</div>

			<!-- Rank Picker -->
			<div class="kn-acct-field" id="kn-award-rank-row" style="display:none">
				<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; blue = already held, green border = suggested next</span></label>
				<div class="kn-rank-pills-wrap" id="kn-rank-pills"></div>
				<input type="hidden" id="kn-award-rank-val" value="" />
			</div>

			<!-- Date -->
			<div class="kn-acct-field">
				<label for="kn-award-date">Date <span style="color:#e53e3e">*</span></label>
				<input type="date" id="kn-award-date" />
			</div>

			<!-- Given By -->
			<div class="kn-acct-field">
				<label>Given By <span style="color:#e53e3e">*</span></label>
				<?php if (!empty($PreloadOfficers)): ?>
				<div class="kn-officer-chips" id="kn-award-officer-chips">
					<?php foreach ($PreloadOfficers as $officer): ?>
					<button type="button" class="kn-officer-chip"
					        data-id="<?= (int)$officer['MundaneId'] ?>"
					        data-name="<?= htmlspecialchars($officer['Persona']) ?>">
						<?= htmlspecialchars($officer['Persona']) ?> <span>(<?= htmlspecialchars($officer['Role']) ?>)</span>
					</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<input type="text" id="kn-award-givenby-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="kn-award-givenby-id" value="" />
				<div class="kn-ac-results" id="kn-award-givenby-results"></div>
			</div>

			<!-- Given At -->
			<div class="kn-acct-field">
				<label>Given At <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<input type="text" id="kn-award-givenat-text"
				       placeholder="Search park, kingdom, or event..."
				       autocomplete="off"
				       value="<?= htmlspecialchars($kingdom_name ?? '') ?>" />
				<div class="kn-ac-results" id="kn-award-givenat-results"></div>
				<input type="hidden" id="kn-award-park-id" value="0" />
				<input type="hidden" id="kn-award-kingdom-id" value="<?= (int)$kingdom_id ?>" />
				<input type="hidden" id="kn-award-event-id" value="0" />
			</div>

			<!-- Note -->
			<div class="kn-acct-field">
				<label for="kn-award-note">Note <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<textarea id="kn-award-note" rows="3" maxlength="400" placeholder="What was this award given for?"></textarea>
				<span class="kn-char-count" id="kn-award-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-award-cancel">Close</button>
			<div style="display:flex;gap:8px">
				<button class="kn-btn kn-btn-secondary" id="kn-award-save-same" disabled>
					<i class="fas fa-plus"></i> Add + Same Player
				</button>
				<button class="kn-btn kn-btn-primary" id="kn-award-save-new" disabled>
					<i class="fas fa-plus"></i> Add + New Player
				</button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($CanManageKingdom ?? false): ?>

<div class="kn-emod-overlay" id="kn-event-modal">
	<div class="kn-emod-box">
		<div class="kn-emod-header">
			<h3><i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Add New Occurrence</h3>
			<button class="kn-emod-close" onclick="knCloseEventModal()">&times;</button>
		</div>
		<div class="kn-emod-body">
			<p class="kn-emod-hint">Select a template to get started. You'll configure the dates, location, and details on the next page.</p>
			<label class="kn-emod-label">Event Template</label>
			<select class="kn-emod-select" id="kn-template-select">
				<option value="">Loading templates…</option>
			</select>
		</div>
		<div class="kn-emod-footer">
			<button class="kn-emod-btn-cancel" onclick="knCloseEventModal()">Cancel</button>
			<button class="kn-emod-btn-go" id="kn-emod-go-btn" onclick="knGoToEventCreate()" disabled>
				Continue <i class="fas fa-arrow-right"></i>
			</button>
		</div>
	</div>
</div>

<!-- Add Park Modal -->
<div id="kn-addpark-overlay">
	<div class="kn-modal-box" style="width:460px;max-width:calc(100vw - 40px);">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-plus-circle" style="margin-right:8px;color:#276749"></i>Add Park</h3>
			<button class="kn-modal-close-btn" id="kn-addpark-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-addpark-feedback" style="display:none"></div>
			<div class="kn-acct-field">
				<label for="kn-addpark-name">Park Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-addpark-name" placeholder="e.g. Eternal Darkness" maxlength="128" />
			</div>
			<div class="kn-acct-field">
				<label for="kn-addpark-abbr">Abbreviation <span style="color:#e53e3e">*</span> <span style="color:#a0aec0;font-size:11px;text-transform:none;letter-spacing:0">(up to 4 alphanumeric characters)</span></label>
				<input type="text" id="kn-addpark-abbr" placeholder="e.g. ED" maxlength="4" />
			</div>
			<div class="kn-acct-field">
				<label for="kn-addpark-title">Park Type <span style="color:#e53e3e">*</span></label>
				<select id="kn-addpark-title">
					<option value="">— select type —</option>
					<?php foreach ($ParkTitleId_options ?? [] as $ptId => $ptTitle): ?>
					<option value="<?= (int)$ptId ?>"><?= htmlspecialchars($ptTitle) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-addpark-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-addpark-submit">
				<i class="fas fa-plus"></i> Create Park
			</button>
		</div>
	</div>
</div>

<!-- Edit Park Modal -->
<div id="kn-editpark-overlay">
	<div class="kn-modal-box" style="width:460px;max-width:calc(100vw - 40px);">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-pencil-alt" style="margin-right:8px;color:#276749"></i>Edit Park</h3>
			<button class="kn-modal-close-btn" id="kn-editpark-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-editpark-feedback" style="display:none"></div>
			<input type="hidden" id="kn-editpark-id" />
			<div class="kn-acct-field">
				<label for="kn-editpark-name">Park Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-editpark-name" maxlength="128" />
			</div>
			<div class="kn-acct-field">
				<label for="kn-editpark-abbr">Abbreviation <span style="color:#e53e3e">*</span> <span style="color:#a0aec0;font-size:11px;text-transform:none;letter-spacing:0">(up to 4 alphanumeric characters)</span></label>
				<input type="text" id="kn-editpark-abbr" maxlength="4" />
			</div>
			<div class="kn-acct-field">
				<label for="kn-editpark-title">Park Type <span style="color:#e53e3e">*</span></label>
				<select id="kn-editpark-title">
					<option value="">— select type —</option>
					<?php foreach ($ParkTitleId_options ?? [] as $ptId => $ptTitle): ?>
					<option value="<?= (int)$ptId ?>"><?= htmlspecialchars($ptTitle) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="kn-acct-field">
				<label style="display:flex;align-items:center;gap:10px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13px;font-weight:600;color:#4a5568;">
					<input type="checkbox" id="kn-editpark-active" style="width:16px;height:16px;cursor:pointer;" />
					Active (uncheck to mark Retired)
				</label>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-editpark-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-editpark-submit">
				<i class="fas fa-save"></i> Save Changes
			</button>
		</div>
	</div>
</div>

<!-- Edit Officers Modal -->
<div id="kn-editoff-overlay">
	<div class="kn-modal-box" style="width:520px;max-width:calc(100vw - 40px);">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-crown" style="margin-right:8px;color:#744210"></i>Edit Officers</h3>
			<button class="kn-modal-close-btn" id="kn-editoff-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-editoff-feedback" style="display:none"></div>
			<p class="kn-editoff-hint">Search and select a player for each role. Leave a field empty to skip that role. Use <strong>Vacate</strong> to remove the current holder.</p>
			<div id="kn-editoff-rows">
				<!-- Built by JS from KnConfig.officerList -->
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-editoff-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-editoff-submit">
				<i class="fas fa-save"></i> Save Officers
			</button>
		</div>
	</div>
</div>

<!-- Kingdom Admin Overlay -->
<div id="kn-admin-overlay">
	<div class="kn-modal-box" style="width:700px;max-width:calc(100vw - 40px);">

		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-cog" style="margin-right:8px;color:#2b6cb0"></i>Kingdom Administration</h3>
			<button class="kn-modal-close-btn" id="kn-admin-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="kn-modal-body" id="kn-admin-body">

			<!-- ── Panel: Kingdom Details ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-details" aria-expanded="true">
					<span><i class="fas fa-edit" style="margin-right:6px;color:#a0aec0"></i>Kingdom Details</span>
					<i class="fas fa-chevron-down kn-admin-chevron kn-admin-chevron-open" id="kn-admin-chev-details"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-details">
					<div id="kn-admin-details-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-field">
						<label for="kn-admin-name">Kingdom Name</label>
						<input type="text" id="kn-admin-name" value="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>">
					</div>
					<div class="kn-admin-field">
						<label for="kn-admin-abbr">Abbreviation <span class="kn-admin-hint-inline">(letters &amp; numbers only)</span></label>
						<input type="text" id="kn-admin-abbr" value="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" maxlength="8">
					</div>
					<div class="kn-admin-field">
						<label for="kn-admin-heraldry">Heraldry Image <span class="kn-admin-hint-inline">(PNG, JPG, or GIF)</span></label>
						<input type="file" id="kn-admin-heraldry" accept="image/png,image/jpeg,image/gif">
					</div>
					<button class="kn-admin-save-btn" id="kn-admin-details-save">
						<i class="fas fa-save"></i> Save Details
					</button>
				</div>
			</div>

			<!-- ── Panel: Configuration ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-config" aria-expanded="false">
					<span><i class="fas fa-sliders-h" style="margin-right:6px;color:#a0aec0"></i>Configuration</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-config"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-config" style="display:none">
					<div id="kn-admin-config-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div id="kn-admin-config-rows">
						<!-- Built by JS from KnConfig.adminConfig -->
					</div>
					<button class="kn-admin-save-btn" id="kn-admin-config-save">
						<i class="fas fa-save"></i> Save Configuration
					</button>
				</div>
			</div>

			<!-- ── Panel: Park Titles ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-titles" aria-expanded="false">
					<span><i class="fas fa-flag" style="margin-right:6px;color:#a0aec0"></i>Park Titles</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-titles"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-titles" style="display:none">
					<div id="kn-admin-titles-feedback" class="kn-admin-feedback" style="display:none"></div>
					<table class="kn-admin-table" id="kn-admin-titles-table">
						<thead>
							<tr>
								<th>Title</th>
								<th>Class</th>
								<th>Min Att.</th>
								<th>Cutoff</th>
								<th>Period</th>
								<th>Len.</th>
								<th></th>
							</tr>
						</thead>
						<tbody id="kn-admin-titles-tbody">
							<!-- Built by JS -->
						</tbody>
						<tfoot>
							<tr class="kn-admin-titles-newrow">
								<td><input type="text"   class="kn-admin-tinput"   data-field="Title"             placeholder="New title…"></td>
								<td><input type="number" class="kn-admin-tnumeric" data-field="Class"             value="0" min="0"></td>
								<td><input type="number" class="kn-admin-tnumeric" data-field="MinimumAttendance" value="0" min="0"></td>
								<td><input type="number" class="kn-admin-tnumeric" data-field="MinimumCutoff"     value="0" min="0"></td>
								<td>
									<select class="kn-admin-tselect" data-field="Period">
										<option value="month">Month</option>
										<option value="week">Week</option>
									</select>
								</td>
								<td><input type="number" class="kn-admin-tnumeric" data-field="Length"            value="1" min="1"></td>
								<td></td>
							</tr>
						</tfoot>
					</table>
					<button class="kn-admin-save-btn" id="kn-admin-titles-save">
						<i class="fas fa-save"></i> Save Park Titles
					</button>
				</div>
			</div>

			<!-- ── Panel: Awards ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-awards" aria-expanded="false">
					<span><i class="fas fa-award" style="margin-right:6px;color:#a0aec0"></i>Awards</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-awards"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-awards" style="display:none">
					<div id="kn-admin-awards-feedback" class="kn-admin-feedback" style="display:none"></div>
					<table class="kn-admin-table kn-admin-awards-table">
						<thead>
							<tr>
								<th>Award Name</th>
								<th>Reign</th>
								<th>Month</th>
								<th>Title?</th>
								<th>Class</th>
								<th></th>
							</tr>
						</thead>
						<tbody id="kn-admin-awards-tbody">
							<!-- Built by JS -->
						</tbody>
					</table>
					<div class="kn-admin-add-award-wrap" id="kn-admin-add-award-wrap" style="display:none">
						<div class="kn-admin-add-award-title">New Award</div>
						<div class="kn-admin-field">
							<label>Canonical Award ID <span class="kn-admin-hint-inline">(from ork_award table)</span></label>
							<input type="number" id="kn-admin-new-award-id" min="1" placeholder="e.g. 7">
						</div>
						<div class="kn-admin-award-row-fields">
							<div class="kn-admin-field kn-admin-field-grow">
								<label>Award Name</label>
								<input type="text" id="kn-admin-new-award-name" placeholder="e.g. Order of the Warrior">
							</div>
							<div class="kn-admin-field">
								<label>Reign Limit</label>
								<input type="number" id="kn-admin-new-reign" min="0" value="0" style="width:64px">
							</div>
							<div class="kn-admin-field">
								<label>Month Limit</label>
								<input type="number" id="kn-admin-new-month" min="0" value="0" style="width:64px">
							</div>
							<div class="kn-admin-field kn-admin-field-center">
								<label>Title?</label>
								<input type="checkbox" id="kn-admin-new-istitle">
							</div>
							<div class="kn-admin-field">
								<label>Title Class</label>
								<input type="number" id="kn-admin-new-tclass" min="0" value="0" style="width:64px" disabled>
							</div>
						</div>
						<div style="display:flex;gap:8px;margin-top:10px">
							<button class="kn-admin-save-btn" id="kn-admin-new-award-save">
								<i class="fas fa-plus"></i> Add Award
							</button>
							<button class="kn-btn-ghost" id="kn-admin-new-award-cancel" style="font-size:13px">Cancel</button>
						</div>
					</div>
					<button class="kn-admin-add-btn" id="kn-admin-awards-add-btn">
						<i class="fas fa-plus"></i> Add Award
					</button>
				</div>
			</div>

		</div><!-- /.kn-modal-body -->

		<div class="kn-modal-footer" style="justify-content:flex-end">
			<button class="kn-btn-ghost" id="kn-admin-done-btn">Done</button>
		</div>

	</div>
</div>

<?php endif; ?>

<?php if ($CanManageKingdom ?? false): ?>
<!-- Add Player Modal -->
<div id="kn-addplayer-overlay">
	<div class="kn-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Add Player</h3>
			<button class="kn-modal-close-btn" id="kn-addplayer-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-addplayer-feedback" class="plr-feedback" style="display:none"></div>
			<div class="plr-field-row">
				<div class="plr-field plr-field-grow">
					<label>Park <span class="plr-req">*</span></label>
					<select id="kn-addplayer-park">
						<option value="">— select park —</option>
					</select>
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field plr-field-grow">
					<label>Persona <span class="plr-req">*</span></label>
					<input type="text" id="kn-addplayer-persona" placeholder="In-game name">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>First Name</label>
					<input type="text" id="kn-addplayer-given" placeholder="Given name">
				</div>
				<div class="plr-field">
					<label>Last Name</label>
					<input type="text" id="kn-addplayer-surname" placeholder="Surname">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field plr-field-grow">
					<label>Email</label>
					<input type="email" id="kn-addplayer-email" placeholder="email@example.com">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>Username <span class="plr-req">*</span></label>
					<input type="text" id="kn-addplayer-username" placeholder="min. 4 characters" autocomplete="new-password">
				</div>
				<div class="plr-field">
					<label>Password <span class="plr-req">*</span></label>
					<input type="password" id="kn-addplayer-password" placeholder="password" autocomplete="new-password">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>Restricted</label>
					<div class="plr-radio-row">
						<label class="plr-radio"><input type="radio" name="kn-addplayer-restricted" value="0" checked> No</label>
						<label class="plr-radio"><input type="radio" name="kn-addplayer-restricted" value="1"> Yes</label>
					</div>
				</div>
				<div class="plr-field">
					<label>Waivered</label>
					<div class="plr-radio-row">
						<label class="plr-radio"><input type="radio" name="kn-addplayer-waivered" value="0" checked> No</label>
						<label class="plr-radio"><input type="radio" name="kn-addplayer-waivered" value="1"> Yes</label>
					</div>
				</div>
			</div>
			<div class="plr-field-row" id="kn-addplayer-waiver-row" style="display:none">
				<div class="plr-field plr-field-grow">
					<label>Waiver File <span class="plr-hint">(PDF, PNG, JPG, or GIF)</span></label>
					<input type="file" id="kn-addplayer-waiver" accept=".pdf,image/png,image/jpeg,image/gif">
				</div>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-addplayer-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-addplayer-submit">
				<i class="fas fa-user-plus"></i> Create Player
			</button>
		</div>
	</div>
</div>
<?php endif; ?>
