<?php
	require_once(DIR_LIB . 'Parsedown.php');
	/* -----------------------------------------------
	   Pre-process template data
	   ----------------------------------------------- */
	$parkList         = is_array($park_summary['KingdomParkAveragesSummary']) ? $park_summary['KingdomParkAveragesSummary'] : array();
	$parkCounts       = []; // loaded via AJAX (park_averages_json)
	$eventList        = is_array($event_summary) ? $event_summary : array();
	// [TOURNAMENTS HIDDEN] $tournamentList = [];
	$principalityList = is_array($principalities['Principalities']) ? $principalities['Principalities'] : array();
	$officerList      = is_array($kingdom_officers['Officers']) ? $kingdom_officers['Officers'] : array();

	// Aggregate attendance — weekly loaded now, monthly loaded via AJAX after page load
	$totalAtt = 0;
	foreach ($parkList as $p) {
		$totalAtt += (int)$p['AttendanceCount'];
	}

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

	// Players loaded via AJAX (players_json) — not available at render time
	$knAllPlayers    = [];
	$knPlayerPeriods = [];

	// Pre-compute map location data (server-side; embedded as JSON for lazy map init)
	if (!function_exists('kn_map_markdown')) {
		function kn_map_markdown(string $text): string {
			$clean = str_replace(['<br />', '<br/>', '<br>'], "\n", $text);
			$html  = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($clean);
			return preg_replace('/<img[^>]*>/i', '', $html);
		}
	}
	$knMapLocations = [];
	foreach ((array)$map_parks as $p) {
		$loc = @json_decode(stripslashes((string)$p['Location']));
		if (!$loc) continue;
		$latlng = isset($loc->location) ? $loc->location : (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
		if (!$latlng || !is_numeric($latlng->lat) || !is_numeric($latlng->lng)) continue;
		$knMapLocations[] = [
			'name'     => ucwords($p['Name']),
			'lat'      => (float)$latlng->lat,
			'lng'      => (float)$latlng->lng,
			'id'       => (int)$p['ParkId'],
			'city'     => htmlspecialchars(trim($p['City'] ?? '')),
			'province' => htmlspecialchars(trim($p['Province'] ?? '')),
			'heraldry' => $p['HasHeraldry'] ? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf('%05d', $p['ParkId'])) : '',
			'dir'      => kn_map_markdown($p['Directions'] ?? ''),
			'desc'     => kn_map_markdown($p['Description'] ?? ''),
		];
	}
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<!-- =============================================
     ZONE 1: Hero Header
     ============================================= -->
<div class="kn-hero">
	<div class="kn-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="kn-hero-content">

		<div class="kn-heraldry-wrap">
			<div class="kn-heraldry-frame<?= !empty($CanManageKingdom) ? ' kn-heraldry-editable' : '' ?>">
				<img class="heraldry-img" src="<?= htmlspecialchars($heraldryUrl) ?>"
				     alt="<?= htmlspecialchars($kingdom_name) ?>"
				     crossorigin="anonymous"
				     onload="typeof knApplyHeroColor==='function'&&knApplyHeroColor(this)">
			</div>
			<?php if (!empty($CanManageKingdom)): ?>
			<button class="kn-heraldry-edit-btn" onclick="knOpenHeraldryModal()" title="Change heraldry">
				<i class="fas fa-camera"></i>
			</button>
			<?php endif; ?>
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
						<a href="<?= UIR ?>Player/profile/<?= $monarch['MundaneId'] ?>"><?= htmlspecialchars($monarch['Persona']) ?></a>
					<?php else: ?>
						<span class="kn-vacant">Vacant</span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="kn-hero-actions">
			<?php if ($CanEditKingdom ?? false): ?>
				<a class="kn-btn kn-btn-outline" href="<?= UIR ?>Attendance/kingdom/<?= (int)($kingdom_id ?? 0) ?>">
					<i class="fas fa-clipboard-list"></i> Enter Attendance
				</a>
			<?php endif; ?>
			<?php if ($CanManageKingdom ?? false): ?>
				<button class="kn-btn kn-btn-outline" onclick="knOpenAwardModal()">
					<i class="fas fa-medal"></i> Enter Awards
				</button>
			<?php endif; ?>
			<a class="kn-btn kn-btn-outline" href="#" onclick="knActivateTab('map');return false;">
				<i class="fas fa-map"></i> Map
			</a>
			<?php if ($CanManageKingdom ?? false): ?>
			<button class="kn-btn kn-btn-outline" onclick="knOpenAdminModal()">
				<i class="fas fa-cog"></i> Admin
			</button>
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
		<div class="kn-stat-number" id="kn-stat-avgwk">—</div>
		<div class="kn-stat-label">Avg / Week</div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-chart-line"></i></div>
		<div class="kn-stat-number" id="kn-stat-avgmo">—</div>
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
			<h4 style="display:flex;align-items:center;justify-content:space-between;background:transparent;border:none;padding:0;border-radius:0;">
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
							<a href="<?= UIR ?>Player/profile/<?= $o['MundaneId'] ?>"><?= htmlspecialchars($o['Persona']) ?></a>
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

		<?php
			$_knDescription = $kingdom_info['Info']['KingdomInfo']['Description'] ?? '';
		?>
		<?php if (!empty($_knDescription)): ?>
		<div class="kn-card kn-description-card">
			<h4 style="background:transparent;border:none;padding:0;border-radius:0;"><i class="fas fa-info-circle"></i> About</h4>
			<div class="kn-description-body"><?= preg_replace('/<img[^>]*>/i', '', (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($_knDescription)) ?></div>
			<?php if (!empty($kingdom_info['Info']['KingdomInfo']['Url'] ?? '')): ?>
			<a class="kn-description-url" href="<?= htmlspecialchars($kingdom_info['Info']['KingdomInfo']['Url']) ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt" style="margin-right:4px;font-size:11px"></i><?= htmlspecialchars($kingdom_info['Info']['KingdomInfo']['Url']) ?></a>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<!-- Quick Links -->
		<div class="kn-card">
			<h4 style="background:transparent;border:none;padding:0;border-radius:0;"><i class="fas fa-link"></i> Quick Links</h4>
			<ul class="kn-link-list">
				<li>
					<span class="kn-link-icon"><i class="fas fa-search"></i></span>
					<a href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">Search Players</a>
				</li>
				<?php if ($IsLoggedIn): ?>
					<li>
						<span class="kn-link-icon"><i class="fas fa-medal"></i></span>
						<a href="<?= UIR ?>Award/kingdom/<?= $kingdom_id ?>">Enter Awards</a>
					</li>
				<?php endif; ?>
				<li>
					<span class="kn-link-icon"><i class="fas fa-map-marked-alt"></i></span>
					<a href="#" onclick="knActivateTab('map');return false;">Kingdom Map</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-users"></i></span>
					<a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Companies &amp; Households</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-calendar"></i></span>
					<a href="<?= UIR ?>Search/event&KingdomId=<?= $kingdom_id ?>">Find Events</a>
				</li>
				<?php if ($CanManageKingdom ?? false): ?>
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
					<i class="fas fa-map-marker-alt"></i><span class="kn-tab-label"> Parks</span>
					<span class="kn-tab-count">(<?= count($parkList) ?>)</span>
				</li>
				<li data-kntab="events">
					<i class="fas fa-calendar-alt"></i><span class="kn-tab-label"> Events</span>
					<span class="kn-tab-count">(<?= count($eventList) ?>)</span>
				</li>
				<li data-kntab="map">
					<i class="fas fa-map"></i><span class="kn-tab-label"> Map</span>
				</li>
				<?php if (!$IsPrinz && count($principalityList) > 0): ?>
					<li data-kntab="principalities">
						<i class="fas fa-shield-alt"></i><span class="kn-tab-label"> Principalities</span>
						<span class="kn-tab-count">(<?= count($principalityList) ?>)</span>
					</li>
				<?php endif; ?>
				<li data-kntab="players" id="kn-tab-btn-players">
					<i class="fas fa-users"></i><span class="kn-tab-label"> Players</span>
					<span class="kn-tab-count" id="kn-players-tab-count"></span>
				</li>
				<li data-kntab="reports">
					<i class="fas fa-chart-bar"></i><span class="kn-tab-label"> Reports</span>
				</li>
				<?php if ($ShowRecsTab ?? false): ?>
				<li data-kntab="recommendations">
					<i class="fas fa-star"></i><span class="kn-tab-label"> Recommendations</span>
					<?php if (!empty($AwardRecommendations)): ?>
					<span class="kn-tab-count">(<?= count($AwardRecommendations) ?>)</span>
					<?php endif; ?>
				</li>
				<?php endif; ?>
				<?php if ($CanManageKingdom ?? false): ?>
				<li data-kntab="admin">
					<i class="fas fa-cog"></i><span class="kn-tab-label"> Admin Tasks</span>
				</li>
				<?php endif; ?>
			</ul>
			<div class="kn-active-tab-label" id="kn-active-tab-label">Parks</div>

			<!-- Parks Tab -->
			<div class="kn-tab-panel" id="kn-tab-parks">
				<?php
					// Pre-sort alphabetically so tiles match default list order
					usort($parkList, function($a, $b) { return strcmp($a['ParkName'], $b['ParkName']); });
					// Pin the logged-in user's home park to the first slot
					$_upid = isset($UserParkId) ? (int)$UserParkId : 0;
					if ($_upid > 0) {
						$_pinIdx = array_search($_upid, array_column($parkList, 'ParkId'));
						if ($_pinIdx !== false) {
							$_pinned = array_splice($parkList, $_pinIdx, 1);
							$_pinned[0]['_pinned'] = true;
							array_unshift($parkList, $_pinned[0]);
						}
					}
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
						<button class="kn-view-btn" title="Map view" onclick="knActivateTab('map');return false;">
							<i class="fas fa-map"></i>
						</button>
						<?php if ($CanAddPark ?? false): ?>
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
							<a class="kn-park-tile<?= !empty($park['_pinned']) ? ' kn-pinned' : '' ?>" href="<?= UIR ?>Park/profile/<?= $park['ParkId'] ?>" data-park-id="<?= (int)$park['ParkId'] ?>">
								<div class="kn-park-tile-img-wrap">
									<?php if (!empty($park['_pinned'])): ?><span class="kn-park-pin-badge">Your Park</span><?php endif; ?>
									<img src="<?= $tileHeraldry ?>"
										loading="lazy"
										onerror="this.src='<?= HTTP_PARK_HERALDRY ?>00000.jpg'"
										alt="<?= htmlspecialchars($park['ParkName']) ?>">
								</div>
								<div class="kn-park-tile-body">
									<div class="kn-park-tile-name"><?= htmlspecialchars($park['ParkName']) ?></div>
									<div class="kn-park-tile-type"><?= htmlspecialchars(!empty($park['Title']) ? $park['Title'] : 'Park') ?></div>
									<div class="kn-park-tile-stats">
										<div class="kn-park-tile-stat">
											<div class="kn-park-tile-stat-val kn-avgwk-tile">—</div>
											<div class="kn-park-tile-stat-lbl">Avg/Wk</div>
										</div>
										<div class="kn-park-tile-stat">
											<div class="kn-park-tile-stat-val kn-avgmo-tile">—</div>
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
									<tr class="kn-row-link<?= !empty($park['_pinned']) ? ' kn-pinned-row' : '' ?>" data-park-id="<?= (int)$park['ParkId'] ?>" onclick="window.location.href='<?= UIR ?>Park/profile/<?= $park['ParkId'] ?>'">
										<td class="kn-col-nowrap">
											<img class="kn-thumb"
												loading="lazy"
												src="<?= $park['HasHeraldry'] == 1 ? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId'])) : HTTP_PARK_HERALDRY . '00000.jpg' ?>"
												onerror="this.src='<?= HTTP_PARK_HERALDRY ?>00000.jpg'"
												alt="">
											<a href="<?= UIR ?>Park/profile/<?= $park['ParkId'] ?>"><?= htmlspecialchars($park['ParkName']) ?></a>
											<?php if (!empty($park['_pinned'])): ?><span class="kn-park-pin-badge" style="position:static;margin-left:6px">Your Park</span><?php endif; ?>
										</td>
										<td><?= htmlspecialchars(!empty($park['Title']) ? $park['Title'] : '') ?></td>
										<td class="kn-col-numeric kn-avgwk-row">—</td>
										<td class="kn-col-numeric kn-avgmo-row">—</td>
										<td class="kn-col-numeric kn-tp-row">—</td>
										<td class="kn-col-numeric kn-tm-row">—</td>
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
									<td class="kn-col-numeric" id="kn-total-avgwk">—</td>
									<td class="kn-col-numeric" id="kn-total-avgmo">—</td>
									<td class="kn-col-numeric" id="kn-total-tp" title="Sum across parks (players may be counted in multiple parks)">—</td>
									<td class="kn-col-numeric" id="kn-total-tm">—</td>
									<?php if ($CanManageKingdom ?? false): ?><td></td><?php endif; ?>
								</tr>
							</tfoot>
						</table>
					</div>

				<?php else: ?>
					<div class="kn-empty">No parks found</div>
				<?php endif; ?>
			</div>

			<style>
			.kn-sub-pop-title{font-weight:700;color:#2d3748;margin-bottom:8px;font-size:12px;text-transform:uppercase;letter-spacing:.05em}
			.kn-sub-pop-row{display:flex;gap:4px;margin-bottom:8px}
			.kn-sub-url-input{flex:1;font-size:11px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;color:#4a5568;background:#f7fafc;min-width:0}
			.kn-sub-copy-btn{padding:4px 8px;border:1px solid #e2e8f0;border-radius:4px;background:#edf2f7;cursor:pointer;color:#4a5568;font-size:12px}
			.kn-sub-copy-btn:hover{background:#e2e8f0}
			.kn-sub-gcal-btn{display:block;text-align:center;background:#4285f4;color:#fff;border-radius:5px;padding:7px 10px;font-size:12px;font-weight:600;text-decoration:none;margin-bottom:2px}
			.kn-sub-gcal-btn:hover{background:#3367d6;color:#fff}
			.kn-sub-webcal-btn{display:block;margin-top:6px;font-size:11px;color:#718096;text-align:center;text-decoration:none}
			.kn-sub-webcal-btn:hover{color:#4a5568}
			</style>
			<!-- Events Tab -->
			<div class="kn-tab-panel" id="kn-tab-events" style="display:none">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
					<h4 style="margin:0;font-size:14px;font-weight:700;color:#4a5568;background:transparent;border:none;padding:0;border-radius:0;"><i class="fas fa-calendar-alt" style="margin-right:6px;color:#a0aec0"></i>Events</h4>
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<button class="kn-view-btn kn-view-active" id="kn-ev-view-list" title="List view"><i class="fas fa-list"></i></button>
						<button class="kn-view-btn" id="kn-ev-view-cal" title="Calendar view"><i class="fas fa-calendar-alt"></i></button>
						<div id="kn-ev-filter-bar" style="display:flex;align-items:center;gap:5px;">
							<span style="font-size:11px;font-weight:700;color:#a0aec0;text-transform:uppercase;letter-spacing:.05em;margin-right:2px;">Show:</span>
							<button class="kn-filter-toggle kn-filter-on" data-filter="kingdom-event">Kingdom Events</button>
							<button class="kn-filter-toggle kn-filter-on" data-filter="park-event">Park Events</button>
							<button class="kn-filter-toggle" data-filter="park-day">Park Days</button>
						</div>
						<div class="kn-sub-wrap" id="kn-sub-wrap" style="position:relative">
							<button class="kn-view-btn" id="kn-sub-btn" title="Subscribe to calendar"
								onclick="(function(btn){var p=document.getElementById('kn-sub-pop');var r=btn.getBoundingClientRect();p.style.top=(r.bottom+6)+'px';p.style.right=(window.innerWidth-r.right)+'px';var show=p.style.display==='none';p.style.setProperty('display',show?'block':'none','important');event.stopPropagation();})(this)">
								<i class="fas fa-rss"></i>
							</button>
							<div class="kn-sub-pop" id="kn-sub-pop" style="display:none;position:fixed;z-index:9000;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.15);padding:12px 14px;width:280px;font-size:13px">
								<div class="kn-sub-pop-title"><i class="fas fa-calendar-check" style="margin-right:5px"></i>Subscribe to Events</div>
								<div class="kn-sub-pop-row">
									<input class="kn-sub-url-input" id="kn-sub-url-input" type="text"
										value="<?= htmlspecialchars($IcsUrl) ?>" readonly>
									<button class="kn-sub-copy-btn" onclick="knCopyIcsUrl()" title="Copy URL">
										<i class="fas fa-copy"></i>
									</button>
								</div>
								<a class="kn-sub-gcal-btn"
									href="https://calendar.google.com/calendar/r/settings/addbyurl?url=<?= urlencode($IcsUrl) ?>"
									target="_blank" rel="noopener">
									<i class="fab fa-google" style="margin-right:6px"></i>Add to Google Calendar
								</a>
								<a class="kn-sub-webcal-btn"
									href="webcal://<?= htmlspecialchars(preg_replace('#^https?://#', '', $IcsUrl)) ?>">
									<i class="fas fa-link" style="margin-right:4px"></i>webcal:// (direct app)
								</a>
							</div>
						</div>
						<?php if ($CanManageKingdom): ?>
						<button onclick="knOpenEventModal()" style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;border:none;cursor:pointer;">
							<i class="fas fa-plus"></i> Add Event
						</button>
						<?php endif; ?>
					</div>
				</div>
				<!-- Calendar view (lazy-loaded FullCalendar) -->
				<div id="kn-events-cal-wrap" style="position:relative;display:none">
					<div id="kn-cal-loading" style="display:none;position:absolute;inset:0;background:rgba(255,255,255,0.88);z-index:10;align-items:center;justify-content:center;min-height:120px;">
						<i class="fas fa-spinner fa-spin" style="font-size:28px;color:#a0aec0"></i>
					</div>
					<div id="kn-events-cal"></div>
				</div>

				<!-- List view -->
				<div id="kn-events-list-view">
				<?php $hasParkDays = count($kingdom_park_days ?? []) > 0; ?>
				<?php if (count($eventList) > 0 || $hasParkDays): ?>
					<table class="kn-table kn-sortable" id="kn-events-table">
						<thead>
							<tr>
								<th data-sorttype="date">Next Date</th>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="text">Park</th>
								<th data-sorttype="numeric">Going</th>
							<th data-sorttype="numeric">Interested</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($eventList as $event): ?>
								<tr class="kn-row-link" data-type="<?= $event['_IsParkEvent'] ? 'park-event' : 'kingdom-event' ?>"<?= $event['NextDetailId'] ? ' onclick="window.location.href=\''.UIR.'Event/detail/' . $event['EventId'] . '/' . $event['NextDetailId'] . '\'"' : '' ?>>
									<td class="kn-col-nowrap">
										<?php if (0 != $event['NextDate'] && $event['NextDate'] != '0000-00-00'): ?>
											<?= date("M j, Y", strtotime($event['NextDate'])) ?>
											<?php if (!empty($event['TzAbbr'])): ?><span class="kn-tz-badge"><?= htmlspecialchars($event['TzAbbr']) ?></span><?php endif; ?>
										<?php else: ?>
											<span style="color:#a0aec0">—</span>
										<?php endif; ?>
									</td>
									<td class="kn-col-nowrap">
										<img class="kn-thumb <?= $event['_IsParkEvent'] ? 'kn-evt-park' : 'kn-evt-kingdom' ?>"
											loading="lazy"
											src="<?= $event['HasHeraldry'] == 1 ? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf("%05d", $event['EventId'])) : HTTP_EVENT_HERALDRY . '00000.jpg' ?>"
											onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
											alt="">
										<?php if ($event['NextDetailId']): ?><a href="<?= UIR ?>Event/detail/<?= $event['EventId'] ?>/<?= $event['NextDetailId'] ?>"><?= htmlspecialchars($event['Name']) ?></a><?php else: ?><?= htmlspecialchars($event['Name']) ?><?php endif; ?>
									</td>
									<td><?= htmlspecialchars($event['ParkName']) ?></td>
									<td style="text-align:center"><?= (int)($event['RsvpGoing'] ?? 0) ?: '—' ?></td>
								<td style="text-align:center"><?= (int)($event['RsvpInterested'] ?? 0) ?: '—' ?></td>
								</tr>
							<?php endforeach; ?>
						<?php foreach ($kingdom_park_days ?? [] as $day): ?>
							<tr class="kn-row-link" data-type="park-day" style="display:none" onclick="window.location.href='<?= UIR ?>Park/profile/<?= $day['ParkId'] ?>'">
								<td class="kn-col-nowrap" style="color:#718096;font-style:italic"><?= htmlspecialchars($day['Schedule']) ?></td>
								<td class="kn-col-nowrap">
									<i class="fas fa-calendar" style="margin-right:6px;color:#a0aec0"></i>
									<?php if (!empty($day['ParkAbbr'])): ?><strong style="color:#4a5568;margin-right:3px"><?= htmlspecialchars($day['ParkAbbr']) ?>:</strong><?php endif; ?>
									<?= htmlspecialchars($day['Purpose']) ?> — <?= (!empty($day['Time'])) ? date('g:i A', strtotime($day['Time'])) : '' ?>
								</td>
								<td><?= htmlspecialchars($day['ParkName']) ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="kn-empty">No upcoming events</div>
				<?php endif; ?>
				</div><!-- /kn-events-list-view -->

				<?php /* [TOURNAMENTS HIDDEN] */ ?>
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
							<div class="kn-map-sidebar-wrap">
								<div class="kn-map-sidebar-card" id="kn-map-sidebar-card">
									<div class="kn-map-sidebar-empty" id="kn-map-sidebar-empty">
										<div class="kn-map-sidebar-empty-icon"><i class="fas fa-map-marker-alt"></i></div>
										<p>Click any park pin to see details.</p>
									</div>
									<div id="kn-map-sidebar-park" style="display:none; flex-direction:column; flex:1;">
										<div class="kn-park-hero" id="kn-park-hero"></div>
										<div class="kn-park-body" id="kn-park-body"></div>
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
								loading="lazy"
								src="<?= HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf("%04d", $prinz['KingdomId'])) ?>"
								onerror="this.src='<?= HTTP_KINGDOM_HERALDRY ?>0000.jpg'"
								alt="">
							<div class="kn-prinz-name">
								<a href="<?= UIR ?>Kingdom/profile/<?= $prinz['KingdomId'] ?>"><?= htmlspecialchars($prinz['Name']) ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Reports Tab -->
			<div class="kn-tab-panel" id="kn-tab-reports" style="display:none">
				<?php if (!$IsLoggedIn): ?>
				<div style="background:#eaf4fb;border:1px solid #b0d4ea;border-radius:4px;padding:8px 14px;margin-bottom:10px;font-size:0.9em;color:#1a5276;">
					<i class="fas fa-info-circle"></i> <a href="<?= UIR ?>Login" style="color:#1a5276;font-weight:600;">Log in</a> to see the full list of available reports.
				</div>
				<?php endif; ?>
				<div class="pk-reports-mobile-notice">
					<i class="fas fa-info-circle"></i>
					<span>Some reports may not display as expected on mobile. For best results, view reports on a full screen device.</span>
				</div>
				<div class="kn-reports-grid">

					<div class="kn-report-group">
						<h5><i class="fas fa-users"></i> Players</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/roster/Kingdom&id=<?= $kingdom_id ?>">Player Roster</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/active/Kingdom&id=<?= $kingdom_id ?>">Active Players</a></li>
							<li><a href="<?= UIR ?>Reports/dues/Kingdom&id=<?= $kingdom_id ?>">Dues Paid</a></li>
							<li><a href="<?= UIR ?>Reports/waivered/Kingdom&id=<?= $kingdom_id ?>">Waivered</a></li>
							<li><a href="<?= UIR ?>Reports/unwaivered/Kingdom&id=<?= $kingdom_id ?>">Unwaivered</a></li>
							<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kingdom_id ?>">Suspended</a></li>
							<li><a href="<?= UIR ?>Reports/active_duespaid/Kingdom&id=<?= $kingdom_id ?>">Player Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/active_waivered_duespaid/Kingdom&id=<?= $kingdom_id ?>">Waivered Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/reeve/Kingdom&id=<?= $kingdom_id ?>">Reeve Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/corpora/Kingdom&id=<?= $kingdom_id ?>">Corpora Qualified</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/kingdom_officer_directory&KingdomId=<?= $kingdom_id ?>"><i class="fas fa-crown"></i> Park Officer Directory</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-medal"></i> Awards</h5>
						<ul>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/player_award_recommendations&KingdomId=<?= $kingdom_id ?>">Award Recommendations</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/knights_and_masters&KingdomId=<?= $kingdom_id ?>">Knights &amp; Masters</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/knights_list&KingdomId=<?= $kingdom_id ?>">Knights</a></li>
							<li><a href="<?= UIR ?>Reports/masters_list&KingdomId=<?= $kingdom_id ?>">Masters</a></li>
							<li><a href="<?= UIR ?>Reports/player_awards&Ladder=8&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?>-level Awards</a></li>
							<li><a href="<?= UIR ?>Reports/class_masters&KingdomId=<?= $kingdom_id ?>">Class Masters/Paragons</a></li>
							<li><a href="<?= UIR ?>Reports/guilds&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?> Guilds</a></li>
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>">Custom Awards</a></li>
							<li><a href="<?= UIR ?>Reports/beltline_explorer&KingdomId=<?= $kingdom_id ?>"><i class="fas fa-sitemap"></i> Beltline Explorer</a></li>
							<?php endif; ?>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-calendar-check"></i> Attendance</h5>
						<ul>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Weeks/1">Past Week</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/1">Past Month</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/3">Past 3 Months</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/6">Past 6 Months</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/12">Past 12 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/All">All Time</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/event_attendance/Kingdom/<?= $kingdom_id ?>"><i class="fas fa-calendar-alt"></i> Event Attendance</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/park_attendance_explorer"><i class="fas fa-chart-bar"></i> Park Attendance Explorer</a></li>
							<li><a href="<?= UIR ?>Reports/new_player_attendance"><i class="fas fa-user-plus"></i> New Player Attendance</a></li>
							<?php endif; ?>
						</ul>
					</div>

					<?php if ($IsLoggedIn): ?>
					<div class="kn-report-group">
						<h5><i class="fas fa-ellipsis-h"></i> Other</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/parkheraldry/<?= $kingdom_id ?>"><?= $entityLabel ?> Heraldry, Parks</a></li>
							<li><a href="<?= UIR ?>Reports/playerheraldry/<?= $kingdom_id ?>"><?= $entityLabel ?> Heraldry, Players</a></li>
							<li><a href="<?= UIR ?>Reports/park_distance_matrix&KingdomId=<?= $kingdom_id ?>"><i class="fas fa-th"></i> Park Distance Matrix</a></li>
						</ul>
					</div>
					<?php endif; ?>

					<div class="kn-report-group">
						<h5><i class="fas fa-search"></i> Find</h5>
						<ul>
							<li><a href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">Players</a></li>
							<li><a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Companies &amp; Households</a></li>
							<li><a href="<?= UIR ?>Search/event&KingdomId=<?= $kingdom_id ?>">Events</a></li>
							<li><a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Unit List</a></li>
						</ul>
					</div>



				</div>
			</div>

		<!-- Admin Tab -->
		<?php if ($CanManageKingdom ?? false): ?>
		<div class="kn-tab-panel" id="kn-tab-admin" style="display:none">
			<div class="kn-report-cols">
				<div class="kn-report-group">
					<h5><i class="fas fa-users-cog"></i> Players</h5>
					<ul>
						<li><a href="#" onclick="knOpenAddPlayerModal();return false;">Create Player</a></li>
						<li><a href="#" onclick="knOpenMovePlayerModal();return false;">Move Player</a></li>
						<li><a href="#" onclick="knOpenMergePlayerModal();return false;">Merge Players</a></li>
						<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kingdom_id ?>">Suspensions</a></li>
					</ul>
				</div>
				<div class="kn-report-group">
					<h5><i class="fas fa-cog"></i> Kingdom</h5>
					<ul>
						<li><a href="<?= UIR ?>Admin/kingdom/<?= $kingdom_id ?>">Admin Panel</a></li>
						<li><a href="<?= UIR ?>Admin/permissions/Kingdom/<?= $kingdom_id ?>">Roles &amp; Permissions</a></li>
						<li><a href="#" onclick="knOpenClaimParkModal();return false;">Claim Park</a></li>
					</ul>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- Recommendations Tab -->
		<?php if ($ShowRecsTab ?? false): ?>
		<div class="kn-tab-panel" id="kn-tab-recommendations" style="display:none">
			<?php if ($IsLoggedIn): ?>
			<div class="pk-tab-toolbar">
				<button class="kn-btn kn-btn-secondary" onclick="knOpenRecModal()">
					<i class="fas fa-star"></i> Recommend an Award
				</button>
			</div>
			<?php endif; ?>
			<?php if (empty($AwardRecommendations)): ?>
			<div class="pk-recs-empty">There are no open award recommendations for <?= htmlspecialchars($kingdom_name) ?>.</div>
			<?php else: ?>
			<div class="kn-rec-filter-bar">
				<button class="kn-rec-filter-btn kn-rec-filter-active" data-filter="all">All</button>
				<button class="kn-rec-filter-btn" data-filter="below">Below Recommended</button>
				<button class="kn-rec-filter-btn" data-filter="already">At or Above Recommended</button>
				<button class="kn-rec-filter-btn" data-filter="nonladder">Non-Ladder</button>
			</div>
				<div class="pk-recs-table-wrap">
				<table id="kn-rec-table" class="pk-recs-table display">
					<thead>
						<tr>
							<th>Player</th>
							<th>Award</th>
							<th>Rank</th>
							<th data-short="Rec. By">Recommended By</th>
							<th>Date</th>
							<th>Notes</th>
							<?php if ($CanManageKingdom ?? false): ?><th></th><?php endif; ?>
						</tr>
					</thead>
					<tbody id="kn-recs-tbody">
					<?php foreach ($AwardRecommendations as $rec): ?>
					<tr class="pk-rec-row"
						data-rec-id="<?= (int)$rec['RecommendationsId'] ?>"
						data-filter="<?= !empty($rec['AlreadyHas']) ? 'already' : ((int)$rec['Rank'] > 0 ? 'below' : 'nonladder') ?>">
						<td><a href="<?= UIR ?>Player/profile/<?= (int)$rec['MundaneId'] ?>"><?= htmlspecialchars($rec['Persona']) ?></a></td>
						<td><?= htmlspecialchars($rec['AwardName']) ?></td>
						<td style="white-space:nowrap">
							<?= (int)$rec['Rank'] > 0 ? (int)$rec['Rank'] : '&mdash;' ?>
							<?php if (!empty($rec['AlreadyHas'])): ?>
							<span class="pk-rec-has-tip"
								title="<?= (int)$rec['Rank'] > 0 ? 'Player is currently at rank ' . (int)$rec['CurrentRank'] . ' as of ' . htmlspecialchars($rec['CurrentRankDate'] ?? '') : 'Player already has this award (granted ' . htmlspecialchars($rec['CurrentRankDate'] ?? 'unknown date') . ')' ?>">
								<i class="fas fa-info-circle"></i>
							</span>
							<?php endif; ?>
						</td>
						<td><?php if (!empty($rec['RecommendedById'])): ?><a href="<?= UIR ?>Player/profile/<?= (int)$rec['RecommendedById'] ?>"><?= htmlspecialchars($rec['RecommendedByName']) ?></a><?php else: ?>&mdash;<?php endif; ?></td>
						<td><?= htmlspecialchars($rec['DateRecommended']) ?></td>
						<td class="pk-rec-notes"><?php if (!empty($rec['Reason'])): ?><span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($rec['Reason'], 0, 50)) ?><?php if (mb_strlen($rec['Reason']) > 50): ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($rec['Reason'], 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span><?php endif; ?></span><?php else: ?>&mdash;<?php endif; ?></td>
						<?php if ($CanManageKingdom ?? false): ?>
						<td class="pk-rec-actions">
							<button class="pk-btn pk-btn-primary pk-rec-grant-btn"
								data-rec="<?= htmlspecialchars(json_encode(['MundaneId'=>(int)$rec['MundaneId'],'Persona'=>$rec['Persona'],'KingdomAwardId'=>(int)$rec['KingdomAwardId'],'Rank'=>(int)$rec['Rank'],'Reason'=>$rec['Reason']??''])) ?>">
								<i class="fas fa-medal"></i> Grant
							</button>
							<button class="pk-rec-dismiss-btn"
								data-rec-id="<?= (int)$rec['RecommendationsId'] ?>">
								<i class="fas fa-times"></i> Dismiss
							</button>
						</td>
						<?php endif; ?>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<!-- Players Tab -->
		<div class="kn-tab-panel" id="kn-tab-players" style="display:none">
			<div class="kn-players-toolbar">
				<span class="kn-players-toolbar-left" id="kn-players-summary">&hellip;</span>
				<div class="kn-players-toolbar-right">
					<div class="kn-player-search-wrap">
						<i class="fas fa-search kn-player-search-icon"></i>
						<input type="text" id="kn-player-search" class="kn-player-search-input" placeholder="Search all players&hellip;" autocomplete="off">
					</div>
					<div class="kn-view-toggle">
						<button class="kn-view-btn kn-view-active" data-knview="cards"><i class="fas fa-th-large"></i> Cards</button>
						<button class="kn-view-btn" data-knview="list"><i class="fas fa-list"></i> List</button>
					</div>
					<?php if ($CanManageKingdom ?? false): ?>
					<div class="plr-action-group">
						<button class="plr-add-btn" onclick="knOpenAddPlayerModal()"><i class="fas fa-user-plus"></i> Add Player</button>
						<div class="plr-gear-wrap">
							<button class="plr-gear-btn" id="kn-plr-gear-btn" aria-label="Player actions" aria-expanded="false" onclick="var m=this.nextElementSibling;var o=m.classList.toggle('open');this.setAttribute('aria-expanded',o)"><i class="fas fa-cog"></i></button>
							<div class="plr-gear-menu" id="kn-plr-gear-menu">
								<button class="plr-gear-item" onclick="knOpenMovePlayerModal();document.getElementById('kn-plr-gear-menu').classList.remove('open')"><i class="fas fa-people-arrows"></i> Move Player</button>
								<button class="plr-gear-item" onclick="knOpenMergePlayerModal();document.getElementById('kn-plr-gear-menu').classList.remove('open')"><i class="fas fa-compress-alt"></i> Merge Players</button>
							</div>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<div id="kn-players-loading" style="text-align:center;padding:32px;color:#a0aec0"><i class="fas fa-spinner fa-spin"></i> Loading players&hellip;</div>
			<div id="kn-players-cards" style="display:none"></div>
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
					<tbody id="kn-players-tbody"></tbody>
				</table>
				<div id="kn-players-list-more" style="display:none">
					<div class="kn-load-more-wrap kn-load-more-list" data-next="1">
						<button class="kn-load-more-btn" onclick="knLoadMoreList('kn-players-table', 'kn-players-tmpl', this)"><i class="fas fa-chevron-down"></i> Load More...</button>
						<span class="kn-load-more-hint" id="kn-players-list-hint"></span>
					</div>
				</div>
				<div class="kn-pagination" id="kn-players-table-pages"></div>
			</div>
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
	canEdit:          <?= !empty($CanEditKingdom)   ? 'true' : 'false' ?>,
	canManage:        <?= !empty($CanManageKingdom) ? 'true' : 'false' ?>,
	canAddPark:       <?= !empty($CanAddPark) ? 'true' : 'false' ?>,
	loggedIn:         <?= !empty($IsLoggedIn) ? 'true' : 'false' ?>,
	parkTitleOptions: <?= json_encode($ParkTitleId_options ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	parkEditLookup:   <?= json_encode($CanManageKingdom ? array_values($park_edit_lookup ?? []) : [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	officerList:      <?= json_encode($CanManageKingdom ? array_map(function($o) { return ['OfficerRole' => $o['OfficerRole'], 'MundaneId' => (int)$o['MundaneId'], 'Persona' => $o['Persona']]; }, $officerList) : [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	mapLocations:     <?= json_encode(array_values($knMapLocations ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	preloadOfficers:  <?= json_encode($PreloadOfficers ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	isOrkAdmin:      <?= !empty($IsOrkAdmin) ? 'true' : 'false' ?>,
	adminInfo:       <?= json_encode($AdminInfo       ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminConfig:     <?= json_encode($AdminConfig     ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminParkTitles: <?= json_encode($AdminParkTitles ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminAwards:     <?= json_encode($AdminAwards     ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminRecsPublic: <?= !empty($AwardRecsPublic) ? 'true' : 'false' ?>,
	kingdomTimezone: <?= json_encode($KingdomTimezone ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>,
};
</script>
<?php if ($IsLoggedIn): ?>
<div id="kn-award-overlay">
	<div class="kn-modal-box">
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
					<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>Same Player
				</button>
				<button class="kn-btn kn-btn-primary" id="kn-award-save-new" disabled>
					<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>New Player
				</button>
			</div>
		</div>
	</div>
</div>

<!-- Recommend Award Modal -->
<div id="kn-rec-overlay">
	<div class="kn-modal-box">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-star" style="margin-right:8px;color:#d69e2e"></i>Make a Recommendation</h3>
			<button class="kn-modal-close-btn" id="kn-rec-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div class="pk-form-error" id="kn-rec-error" style="display:none"></div>
			<div class="pk-award-success" id="kn-rec-success" style="display:none">
				<i class="fas fa-check-circle"></i> Recommendation submitted!
			</div>
			<div class="pk-acct-field">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-rec-player-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="kn-rec-player-id" value="" />
				<div class="pk-ac-results" id="kn-rec-player-results"></div>
			</div>
			<div class="pk-acct-field">
				<label for="kn-rec-award-select">Award <span style="color:#e53e3e">*</span></label>
				<select id="kn-rec-award-select">
					<option value="">Select award...</option>
					<?= $AwardOptions ?>
				</select>
			</div>
			<div class="pk-acct-field" id="kn-rec-rank-row" style="display:none">
				<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<div class="pk-rank-pills-wrap" id="kn-rec-rank-pills"></div>
				<input type="hidden" id="kn-rec-rank-val" value="" />
			</div>
			<div class="pk-acct-field">
				<label for="kn-rec-reason">Reason <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-rec-reason" maxlength="400" placeholder="Why should this player receive this award?" />
				<span class="pk-char-count" id="kn-rec-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="pk-btn-ghost" id="kn-rec-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-rec-submit" disabled>
				<i class="fas fa-paper-plane"></i> Submit Recommendation
			</button>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($CanManageKingdom ?? false): ?>

<div class="kn-emod-overlay" id="kn-event-modal">
	<div class="kn-emod-box">
		<div class="kn-emod-header">
			<h3><i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Create New Event</h3>
			<button class="kn-emod-close" onclick="knCloseEventModal()">&times;</button>
		</div>
		<div class="kn-emod-body">
			<div class="kn-emod-field">
				<label class="kn-emod-label">Event Name <span style="color:#e53e3e">*</span></label>
				<input type="text" class="kn-emod-input" id="kn-event-name" autocomplete="off" placeholder="e.g. Summer Midreign">
			</div>
			<div id="kn-emod-date-row" style="display:none;font-size:12px;color:#2b6cb0;margin-top:8px;padding:5px 8px;background:#ebf8ff;border-radius:5px;border-left:3px solid #90cdf4">
				<i class="fas fa-calendar-alt" style="margin-right:5px"></i><span id="kn-emod-date-text"></span>
			</div>
			<div class="kn-emod-field" style="margin-top:12px">
				<label class="kn-emod-label">Host Park <span style="color:#a0aec0;font-weight:400;text-transform:none;letter-spacing:0">(optional — leave blank for a kingdom-level event)</span></label>
				<input type="text" class="kn-emod-input" id="kn-event-park-name" autocomplete="off" placeholder="Search parks…">
				<input type="hidden" id="kn-event-park-id">
			</div>
			<div class="kn-emod-feedback" id="kn-emod-feedback" style="display:none"></div>
		</div>
		<div class="kn-emod-footer">
			<button class="kn-emod-btn-cancel" onclick="knCloseEventModal()">Cancel</button>
			<button class="kn-emod-btn-go" id="kn-emod-go-btn" onclick="knCreateEvent()" disabled>
				Create Event <i class="fas fa-arrow-right"></i>
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
				<div id="kn-addpark-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
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
				<div id="kn-editpark-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
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

<!-- Heraldry Modal -->
<?php if (!empty($CanManageKingdom)): ?>
<div id="kn-heraldry-overlay">
	<div class="pn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-camera" style="margin-right:8px;color:#4a5568"></i>Change Heraldry</h3>
			<button class="pn-modal-close-btn" id="kn-heraldry-close-btn">&times;</button>
		</div>
		<div class="pn-modal-body" id="kn-heraldry-step-select">
			<label class="pn-upload-area" for="kn-heraldry-file-input" style="cursor:pointer;display:block;border:2px dashed #cbd5e0;border-radius:8px;padding:28px 20px;text-align:center;color:#718096">
				<i class="fas fa-image" style="font-size:28px;margin-bottom:8px;display:block"></i>
				Click to select an image<br><small style="color:#a0aec0">PNG, JPG, or GIF</small>
			</label>
			<input type="file" id="kn-heraldry-file-input" accept="image/png,image/jpeg,image/gif" style="display:none">
			<?php if ($hasHeraldry): ?>
			<div style="text-align:center;margin-top:14px">
				<button type="button" id="kn-heraldry-remove-btn" class="pn-btn pn-btn-ghost" style="color:#e53e3e;border-color:#feb2b2;font-size:12px;padding:4px 14px">
					<i class="fas fa-trash"></i> Remove Heraldry
				</button>
				<div id="kn-heraldry-remove-confirm" style="display:none;margin-top:10px;padding:10px;background:#fff5f5;border:1px solid #fed7d7;border-radius:6px;font-size:13px;color:#c53030;text-align:left">
					Remove this kingdom's heraldry image?
					<div style="margin-top:8px;display:flex;gap:8px">
						<button type="button" class="pn-btn pn-btn-ghost pn-btn-sm" onclick="document.getElementById('kn-heraldry-remove-confirm').style.display='none'">Cancel</button>
						<button type="button" class="pn-btn pn-btn-sm" style="background:#e53e3e;color:#fff" onclick="knDoRemoveHeraldry()">Yes, Remove</button>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<div class="pn-modal-body" id="kn-heraldry-step-uploading" style="display:none;text-align:center;padding:32px 20px">
			<i class="fas fa-spinner fa-spin" style="font-size:28px;color:#718096;margin-bottom:10px;display:block"></i>
			<div style="color:#718096;font-size:14px">Uploading…</div>
		</div>
		<div class="pn-modal-body" id="kn-heraldry-step-done" style="display:none;text-align:center;padding:32px 20px">
			<i class="fas fa-check-circle" style="font-size:28px;color:#38a169;margin-bottom:10px;display:block"></i>
			<div style="color:#38a169;font-size:14px;font-weight:600">Done!</div>
		</div>
	</div>
</div>
<?php endif; ?>

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
						<input type="text" id="kn-admin-name" value="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>">
					</div>
					<div class="kn-admin-field">
						<label for="kn-admin-abbr">Abbreviation <span class="kn-admin-hint-inline">(letters &amp; numbers only)</span></label>
						<input type="text" id="kn-admin-abbr" value="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" maxlength="8">
						<div id="kn-admin-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
					</div>
					<div class="kn-admin-field">
						<label for="kn-admin-description" style="display:flex;align-items:center;gap:6px;">
						Description <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
						<button type="button" class="kn-md-help-btn" onclick="document.getElementById('kn-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
					</label>
						<textarea id="kn-admin-description" rows="4" style="resize:vertical" data-original="<?= htmlspecialchars($AdminInfo['Description'] ?? '') ?>"><?= htmlspecialchars($AdminInfo['Description'] ?? '') ?></textarea>
					</div>
					<div class="kn-admin-field">
						<label for="kn-admin-url">Website URL <span class="kn-admin-hint-inline">(optional)</span></label>
						<input type="url" id="kn-admin-url" value="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" placeholder="https://">
					</div>
					<div class="kn-admin-field">
						<label for="kn-admin-timezone">Timezone <span class="kn-admin-hint-inline">(applies to all parks &amp; events unless overridden)</span></label>
						<select id="kn-admin-timezone" data-original="<?= htmlspecialchars($AdminInfo['Timezone'] ?? '') ?>">
							<option value="">— Not set (UTC) —</option>
							<?php foreach ($TimezoneOptions ?? [] as $tzOpt): ?>
							<option value="<?= htmlspecialchars($tzOpt['value']) ?>"<?= ($AdminInfo['Timezone'] ?? '') === $tzOpt['value'] ? ' selected' : '' ?>><?= htmlspecialchars($tzOpt['label']) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<button class="kn-admin-save-btn" id="kn-admin-details-save"<?= (empty($AdminInfo['Name']) || empty($AdminInfo['Abbreviation'])) ? ' disabled' : '' ?>>
						<i class="fas fa-save"></i> Save Details
					</button>
				</div>
			</div>

			<!-- ── Panel: Principality (ORK Admins only, shown only when this entity is a principality) ── -->
		<?php if (!empty($IsOrkAdmin) && !empty($AdminInfo['IsPrincipality'])): ?>
		<div class="kn-admin-panel" id="kn-admin-panel-prinz">
			<button class="kn-admin-panel-hdr" id="kn-admin-hdr-prinz" aria-expanded="false">
				<span><i class="fas fa-crown" style="margin-right:6px;color:#a0aec0"></i>Principality Status</span>
				<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-prinz"></i>
			</button>
			<div class="kn-admin-panel-body" id="kn-admin-body-prinz" style="display:none">
				<div id="kn-admin-prinz-feedback" class="kn-admin-feedback" style="display:none"></div>
				<p style="margin:0 0 12px;font-size:13px;color:#4a5568">
					This is a <strong>Principality</strong> sponsored by
					<strong><?= htmlspecialchars($AdminInfo['ParentKingdomName']) ?></strong>.
				</p>
				<div class="kn-admin-field cp-field-ac" id="kn-admin-prinz-sponsor-row">
					<label>Change Sponsor Kingdom</label>
					<input type="text" id="kn-admin-prinz-parent-name" autocomplete="off"
						placeholder="Search kingdoms…"
						value="<?= htmlspecialchars($AdminInfo['ParentKingdomName']) ?>">
					<input type="hidden" id="kn-admin-prinz-parent-id"
						value="<?= (int)($AdminInfo['ParentKingdomId'] ?? 0) ?>">
					<div class="kn-ac-results" id="kn-admin-prinz-parent-results"></div>
				</div>
				<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
					<button class="kn-admin-save-btn" id="kn-admin-prinz-sponsor-save">
						<i class="fas fa-save"></i> Save Sponsor
					</button>
					<button class="kn-admin-save-btn" id="kn-admin-prinz-promote"
						style="background:#c05621;border-color:#c05621">
						<i class="fas fa-crown"></i> Convert to Kingdom
					</button>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- ── Panel: Configuration ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-config" aria-expanded="false">
					<span><i class="fas fa-sliders-h" style="margin-right:6px;color:#a0aec0"></i>Configuration</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-config"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-config" style="display:none">
					<div id="kn-admin-config-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-field kn-admin-recs-visibility-row" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid #e2e8f0;margin-bottom:12px">
						<div>
							<div style="font-size:13px;font-weight:600;color:#2d3748">Recommendation Visibility</div>
							<div style="font-size:12px;color:#718096;margin-top:3px">When Private, besides the monarchy, only the submitter can see their own recommendations.</div>
						</div>
						<select id="kn-admin-recs-public" style="font-size:13px;border:1.5px solid #e2e8f0;border-radius:6px;padding:5px 8px;flex-shrink:0">
							<option value="1" <?= !empty($AwardRecsPublic) ? 'selected' : '' ?>>Public</option>
							<option value="0" <?= empty($AwardRecsPublic) ? 'selected' : '' ?>>Private (monarchy and submitters only)</option>
						</select>
					</div>
					<div id="kn-admin-recs-feedback" class="kn-admin-feedback" style="display:none"></div>
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

			<!-- ── Panel: Edit Parks ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-parks" aria-expanded="false">
					<span><i class="fas fa-map-marker-alt" style="margin-right:6px;color:#a0aec0"></i>Edit Parks</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-parks"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-parks" style="display:none">
					<div id="kn-admin-parks-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-table-wrap">
						<table class="kn-admin-table kn-admin-parks-table">
							<thead>
								<tr>
									<th>Park Name</th>
									<th>Title</th>
									<th>Abbr</th>
									<th style="text-align:center">Active</th>
									<th></th>
								</tr>
							</thead>
							<tbody id="kn-admin-parks-tbody">
								<!-- Built by JS -->
							</tbody>
						</table>
					</div>
					<button class="kn-admin-save-btn" id="kn-admin-parks-save">
						<i class="fas fa-save"></i> Save Parks
					</button>
				</div>
			</div>

			<?php if (!empty($CanAddPark)): ?>
			<!-- ── Panel: Operations ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-ops" aria-expanded="false">
					<span><i class="fas fa-tools" style="margin-right:6px;color:#a0aec0"></i>Operations</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-ops"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-ops" style="display:none">
					<div id="kn-admin-ops-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-ops-row">
						<div class="kn-admin-ops-info">
							<strong>Reset Waivers</strong>
							<p>Clears all waiver records for this <?= $IsPrinz ? 'principality' : 'kingdom' ?>. This action cannot be undone.</p>
						</div>
						<button class="kn-admin-ops-btn kn-admin-ops-btn-danger" id="kn-admin-reset-waivers-btn">
							<i class="fas fa-eraser"></i> Reset Waivers
						</button>
					</div>
					<?php if (!empty($IsOrkAdmin)):
						$isActive = ($AdminInfo['Active'] ?? 'Active') === 'Active'; ?>
					<div class="kn-admin-ops-row">
						<div class="kn-admin-ops-info">
							<strong>Active Status</strong>
							<p>This <?= $IsPrinz ? 'principality' : 'kingdom' ?> is currently <strong id="kn-admin-status-label"><?= $isActive ? 'Active' : 'Inactive' ?></strong>.</p>
						</div>
						<button class="kn-admin-ops-btn<?= $isActive ? ' kn-admin-ops-btn-danger' : '' ?>"
							id="kn-admin-status-toggle" data-active="<?= $isActive ? '1' : '0' ?>">
							<?php if ($isActive): ?>
								<i class="fas fa-ban"></i> Mark Inactive
							<?php else: ?>
								<i class="fas fa-check-circle"></i> Restore to Active
							<?php endif; ?>
						</button>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

		</div><!-- /.kn-modal-body -->

		<div class="kn-modal-footer" style="justify-content:flex-end">
			<button class="kn-btn-ghost" id="kn-admin-done-btn">Done</button>
		</div>

	</div>
</div>

<?php endif; ?>

<!-- Markdown Help Modal -->
<div id="kn-md-help-overlay" onclick="if(event.target===this)this.classList.remove('kn-open')">
	<div class="kn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-markdown" style="margin-right:8px;color:#2b6cb0"></i>Markdown Reference</h3>
			<button class="kn-modal-close-btn" onclick="document.getElementById('kn-md-help-overlay').classList.remove('kn-open')">&times;</button>
		</div>
		<div class="kn-modal-body" style="padding:16px 20px">
			<table class="kn-md-help-table">
				<thead><tr><th>You type</th><th>Result</th></tr></thead>
				<tbody>
					<tr><td><code>**bold**</code></td><td><strong>bold</strong></td></tr>
					<tr><td><code>*italic*</code></td><td><em>italic</em></td></tr>
					<tr><td><code>~~strikethrough~~</code></td><td><s>strikethrough</s></td></tr>
					<tr><td><code>[link](https://...)</code></td><td><a href="#">link</a></td></tr>
					<tr><td><code>`inline code`</code></td><td><code>inline code</code></td></tr>
					<tr><td><code>- item</code></td><td>• Bullet list</td></tr>
					<tr><td><code>1. item</code></td><td>1. Numbered list</td></tr>
					<tr><td><code># Heading</code></td><td><strong>Large heading</strong></td></tr>
					<tr><td><code>## Heading</code></td><td><strong>Smaller heading</strong></td></tr>
					<tr><td><code>&gt; quote</code></td><td><em>Blockquote</em></td></tr>
					<tr><td>Blank line</td><td>New paragraph</td></tr>
					<tr><td>Single newline</td><td>Line break</td></tr>
				</tbody>
			</table>
		</div>
	</div>
</div>

<!-- Confirmation Modal (shared) -->
<div id="kn-confirm-overlay">
	<div class="kn-modal-box kn-confirm-box">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title" id="kn-confirm-title"><i class="fas fa-exclamation-triangle" style="margin-right:8px;color:#e53e3e"></i>Confirm</h3>
			<button class="kn-modal-close-btn" id="kn-confirm-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<p id="kn-confirm-message" style="margin:0;font-size:14px;color:#2d3748;line-height:1.6"></p>
		</div>
		<div class="kn-modal-footer" style="justify-content:flex-end;gap:10px">
			<button class="kn-btn-ghost" id="kn-confirm-cancel-btn">Cancel</button>
			<button class="kn-admin-save-btn kn-confirm-ok-btn" id="kn-confirm-ok-btn">Confirm</button>
		</div>
	</div>
</div>

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

<!-- Move Player Modal -->
<style>
.kn-mp-toggle { display:flex; background:#edf2f7; border-radius:6px; padding:3px; gap:3px; margin-bottom:14px; }
.kn-mp-toggle-btn {
	flex:1; padding:6px 8px; border:none; border-radius:4px; font-size:11px; font-weight:600;
	cursor:pointer; background:transparent; color:#718096; transition:background 0.15s,color 0.15s; white-space:nowrap;
}
.kn-mp-toggle-btn.kn-mp-active { background:#fff; color:#2b6cb0; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
#kn-moveplayer-overlay .kn-modal-body { overflow:visible; }
#kn-moveplayer-overlay .kn-acct-field { position:relative; }
#kn-moveplayer-overlay .kn-ac-results { position:absolute; left:0; right:0; z-index:9999; }
/* Subscribe popover */
.kn-sub-wrap { position:relative; }
.kn-sub-pop {
	display:none !important; position:fixed; z-index:9000;
	background:#fff; border:1px solid #e2e8f0; border-radius:8px;
	box-shadow:0 4px 16px rgba(0,0,0,0.12); padding:12px 14px; width:280px; font-size:13px;
}
.kn-sub-pop.kn-sub-open { display:block !important; }
.kn-sub-pop-title {
	font-weight:700; color:#2d3748; margin-bottom:8px; font-size:12px;
	text-transform:uppercase; letter-spacing:.05em;
}
.kn-sub-pop-row { display:flex; gap:4px; margin-bottom:8px; }
.kn-sub-url-input {
	flex:1; font-size:11px; padding:4px 6px; border:1px solid #e2e8f0;
	border-radius:4px; color:#4a5568; background:#f7fafc; min-width:0;
}
.kn-sub-copy-btn {
	padding:4px 8px; border:1px solid #e2e8f0; border-radius:4px;
	background:#edf2f7; cursor:pointer; color:#4a5568; font-size:12px;
}
.kn-sub-copy-btn:hover { background:#e2e8f0; }
.kn-sub-gcal-btn {
	display:block; text-align:center; background:#4285f4; color:#fff;
	border-radius:5px; padding:7px 10px; font-size:12px; font-weight:600; text-decoration:none;
}
.kn-sub-gcal-btn:hover { background:#3367d6; color:#fff; }
.kn-sub-webcal-btn {
	display:block; margin-top:6px; font-size:11px; color:#718096; text-align:center; text-decoration:none;
}
.kn-sub-webcal-btn:hover { color:#4a5568; }
</style>
<div id="kn-moveplayer-overlay">
	<div class="kn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-people-arrows" style="margin-right:8px;color:#2b6cb0"></i>Move Player</h3>
			<button class="kn-modal-close-btn" id="kn-moveplayer-close-btn">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-moveplayer-feedback" style="display:none"></div>
			<!-- Mode toggle -->
			<div class="kn-mp-toggle">
				<button class="kn-mp-toggle-btn kn-mp-active" id="kn-mp-btn-in" type="button">
					<i class="fas fa-arrow-right" style="margin-right:3px"></i>Transfer Into Kingdom
				</button>
				<button class="kn-mp-toggle-btn" id="kn-mp-btn-within" type="button">
					<i class="fas fa-arrows-alt-h" style="margin-right:3px"></i>Transfer Within Kingdom
				</button>
				<button class="kn-mp-toggle-btn" id="kn-mp-btn-out" type="button">
					<i class="fas fa-arrow-left" style="margin-right:3px"></i>Transfer Out of Kingdom
				</button>
			</div>
			<div class="kn-acct-field">
				<label id="kn-moveplayer-player-label">Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-moveplayer-player-name" autocomplete="off" placeholder="Search players outside this kingdom&hellip;">
				<input type="hidden" id="kn-moveplayer-player-id">
				<div class="kn-ac-results" id="kn-moveplayer-player-results"></div>
			</div>
			<div class="kn-acct-field" style="margin-top:10px">
				<label id="kn-moveplayer-park-label">New Home Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-moveplayer-park-name" autocomplete="off" placeholder="Search parks in this kingdom&hellip;">
				<input type="hidden" id="kn-moveplayer-park-id">
				<div class="kn-ac-results" id="kn-moveplayer-park-results"></div>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-moveplayer-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-moveplayer-submit" disabled><i class="fas fa-arrow-right"></i> Move Player</button>
		</div>
	</div>
</div>

<!-- Merge Players Modal (Kingdom) -->
<div id="kn-mergeplayer-overlay">
	<div class="kn-modal-box" style="width:540px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-compress-alt" style="margin-right:8px;color:#c53030"></i>Merge Players</h3>
			<button class="kn-modal-close-btn" id="kn-mergeplayer-close-btn">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-mergeplayer-feedback" style="display:none"></div>
			<div class="plr-merge-warning">
				<i class="fas fa-exclamation-triangle"></i>
				<div>
					<strong>This action is permanent and cannot be undone.</strong><br>
					The <em>Remove</em> player&rsquo;s account will be permanently deleted. All their awards, attendance, officer history, unit memberships, and notes will be transferred to the <em>Keep</em> player. Any attendance on the same date as an existing record will be dropped.
				</div>
			</div>
			<div class="kn-acct-field">
				<label>Player to Keep <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-merge-keep-name" placeholder="Search for player to keep&hellip;" autocomplete="off">
				<input type="hidden" id="kn-merge-keep-id">
				<div class="kn-ac-results" id="kn-merge-keep-results"></div>
			</div>
			<div class="kn-acct-field" style="margin-top:12px">
				<label>Player to Remove &mdash; <span style="color:#c53030;font-size:12px"><i class="fas fa-skull-crossbones"></i> this account will be permanently deleted</span> <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-merge-remove-name" placeholder="Search for player to remove&hellip;" autocomplete="off">
				<input type="hidden" id="kn-merge-remove-id">
				<div class="kn-ac-results" id="kn-merge-remove-results"></div>
			</div>
			<div class="plr-merge-summary" id="kn-merge-summary" style="display:none">
				<strong>What will happen when you click Merge:</strong>
				<ul>
					<li>All attendance &rarr; transferred to <strong id="kn-merge-keep-display"></strong> (duplicate dates dropped)</li>
					<li>All awards &amp; award history &rarr; transferred</li>
					<li>All officer roles &rarr; transferred</li>
					<li>All unit memberships &rarr; transferred</li>
					<li>Notes &rarr; transferred</li>
					<li style="color:#c53030"><strong id="kn-merge-remove-display"></strong>&rsquo;s account record is permanently deleted</li>
				</ul>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-mergeplayer-cancel">Cancel</button>
			<button class="kn-btn" id="kn-mergeplayer-submit" disabled style="background:#c53030;color:#fff;border-color:#c53030"><i class="fas fa-compress-alt"></i> Merge Players</button>
		</div>
	</div>
</div>

<!-- Claim Park Modal -->
<div id="kn-claimpark-overlay">
	<div class="kn-modal-box" style="width:480px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-flag" style="margin-right:8px;color:#276749"></i>Claim Park</h3>
			<button class="kn-modal-close-btn" id="kn-claimpark-close-btn">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-claimpark-feedback" style="display:none"></div>
			<!-- Step 1: Search -->
			<div id="kn-claimpark-search-panel">
				<p style="font-size:13px;color:#718096;margin:0 0 14px">Transfer a park from another kingdom into <strong><?= htmlspecialchars($kingdom_name) ?></strong>.</p>
				<div class="kn-acct-field" style="position:relative">
					<label>Park to Claim <span style="color:#e53e3e">*</span></label>
					<input type="text" id="kn-claimpark-park-name" autocomplete="off" placeholder="Search all parks&hellip;">
					<input type="hidden" id="kn-claimpark-park-id">
					<input type="hidden" id="kn-claimpark-source-kingdom">
					<div class="kn-ac-results" id="kn-claimpark-park-results"></div>
				</div>
			</div>
			<!-- Step 2: Confirm -->
			<div id="kn-claimpark-confirm-panel" style="display:none">
				<p style="font-size:14px;color:#2d3748;margin:0 0 16px">Confirm the following transfer:</p>
				<table style="width:100%;font-size:13px;border-collapse:collapse">
					<tr>
						<td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">Park</td>
						<td style="padding:6px 0;font-weight:600" id="kn-claimpark-confirm-park"></td>
					</tr>
					<tr>
						<td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">From</td>
						<td style="padding:6px 0" id="kn-claimpark-confirm-from"></td>
					</tr>
					<tr>
						<td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">To</td>
						<td style="padding:6px 0"><strong><?= htmlspecialchars($kingdom_name) ?></strong></td>
					</tr>
					<tr>
						<td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">Abbreviation</td>
						<td style="padding:6px 0;font-family:monospace" id="kn-claimpark-confirm-abbr"></td>
					</tr>
				</table>
				<div id="kn-claimpark-abbr-warning" style="display:none;margin-top:12px;padding:10px 12px;background:#fff5f5;border:1px solid #feb2b2;border-radius:6px;font-size:13px;color:#c53030;gap:8px;align-items:flex-start">
					<i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0"></i>
					<div>The abbreviation <strong id="kn-claimpark-abbr-conflict-abbr"></strong> is already used by <strong id="kn-claimpark-abbr-conflict-name"></strong> in this kingdom. Enter a new abbreviation for this park.</div>
				</div>
				<div id="kn-claimpark-abbr-field" style="display:none;margin-top:12px">
					<label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px">New Abbreviation <span style="color:#e53e3e">*</span></label>
					<input type="text" id="kn-claimpark-new-abbr" maxlength="3" autocomplete="off" style="width:80px;padding:6px 8px;border:1px solid #cbd5e0;border-radius:4px;font-size:13px;text-transform:uppercase" placeholder="e.g. ABC">
				</div>
				<p style="font-size:12px;color:#e53e3e;margin:16px 0 0">This will move all players in the park to the new kingdom.</p>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-claimpark-cancel">Cancel</button>
			<button class="kn-btn-ghost" id="kn-claimpark-back" style="display:none"><i class="fas fa-arrow-left"></i> Back</button>
			<button class="kn-btn kn-btn-primary" id="kn-claimpark-submit"><i class="fas fa-arrow-right"></i> Review Transfer</button>
		</div>
	</div>
</div>


<!-- [TOURNAMENTS HIDDEN] add-tournament modal -->
<?php endif; ?>
<script>
(function() {
	var kingdomId = <?= (int)($kingdom_id ?? 0) ?>;
	if (!kingdomId) return;

	// ---- Park averages + player counts (AJAX) ----
	fetch('<?= UIR ?>Kingdom/park_averages_json/' + kingdomId)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			var totalAtt = 0, totalMo = 0, totalTp = 0, totalTm = 0;
			var kingdomAtt = (data._kingdom && data._kingdom.att) ? data._kingdom.att : null;
			function knTrend(cur, prev, decimals) {
				if (prev === undefined) return '';
				if (cur > prev) return ' <span class="kn-trend kn-trend-up" title="Up from ' + prev.toFixed(decimals) + ' (prev period)">&#9650;</span>';
				if (cur < prev) return ' <span class="kn-trend kn-trend-dn" title="Down from ' + prev.toFixed(decimals) + ' (prev period)">&#9660;</span>';
				return '';
			}
			for (var parkId in data) {
				if (parkId === '_kingdom') continue;
				var att = data[parkId].att || 0, mo = data[parkId].mo || 0;
				var tp  = data[parkId].tp  || 0, tm = data[parkId].tm  || 0;
				var prevAtt = data[parkId].prev_att, prevMo = data[parkId].prev_mo;
				totalAtt += att; totalMo += mo; totalTp += tp; totalTm += tm;
				// Tile view
				var tile = document.querySelector('.kn-park-tile[data-park-id="' + parkId + '"]');
				if (tile) {
					var wkEl = tile.querySelector('.kn-avgwk-tile');
					var moEl = tile.querySelector('.kn-avgmo-tile');
					if (wkEl) wkEl.innerHTML = (att / 26).toFixed(1) + knTrend(att / 26, prevAtt !== undefined ? prevAtt / 26 : undefined, 1);
					if (moEl) moEl.innerHTML = (mo / 12).toFixed(1)  + knTrend(mo / 12,  prevMo  !== undefined ? prevMo  / 12 : undefined, 1);
				}
				// List view row
				var row = document.querySelector('tr[data-park-id="' + parkId + '"]');
				if (row) {
					var wkTd = row.querySelector('.kn-avgwk-row');
					var moTd = row.querySelector('.kn-avgmo-row');
					var tpTd = row.querySelector('.kn-tp-row');
					var tmTd = row.querySelector('.kn-tm-row');
					if (wkTd) { wkTd.innerHTML = (att / 26).toFixed(2) + knTrend(att / 26, prevAtt !== undefined ? prevAtt / 26 : undefined, 2); wkTd.setAttribute('data-sortval', att / 26); }
					if (moTd) { moTd.innerHTML = (mo / 12).toFixed(1)  + knTrend(mo / 12,  prevMo  !== undefined ? prevMo  / 12 : undefined, 1);  moTd.setAttribute('data-sortval', mo / 12); }
					if (tpTd) { tpTd.textContent = tp;  tpTd.setAttribute('data-sortval', tp); }
					if (tmTd) { tmTd.textContent = tm;  tmTd.setAttribute('data-sortval', tm); }
				}
			}
			// Stat cards — use kingdom-level deduped total for weekly (avoids double-counting multi-park players)
			var wkBase = kingdomAtt !== null ? kingdomAtt : totalAtt;
			var statWk = document.getElementById('kn-stat-avgwk');
			var statMo = document.getElementById('kn-stat-avgmo');
			if (statWk) statWk.textContent = (wkBase / 26).toFixed(1);
			if (statMo) statMo.textContent = (totalMo / 12).toFixed(1);
			// Footer totals
			var footWk = document.getElementById('kn-total-avgwk');
			var footMo = document.getElementById('kn-total-avgmo');
			var footTp = document.getElementById('kn-total-tp');
			var footTm = document.getElementById('kn-total-tm');
			if (footWk) footWk.textContent = (wkBase / 26).toFixed(2);
			if (footMo) footMo.textContent = (totalMo / 12).toFixed(1);
			if (footTp) footTp.textContent = totalTp;
			if (footTm) footTm.textContent = totalTm;
		})
		.catch(function(err) { console.error('Kingdom park_averages_json failed:', err); });

	// ---- Players tab: lazy-load on first click ----
	function knHtmlEsc(s) {
		return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}
	function knFmtDate(s, long) {
		if (!s || s === '1970-01-01') return '—';
		var d = new Date(s + 'T00:00:00');
		return long
			? d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'})
			: d.toLocaleDateString('en-US', {month:'short', day:'numeric'});
	}
	function knPlayerCardHtml(p, uir) {
		var initial = (p.persona || '?').charAt(0).toUpperCase();
		var avatarHtml = p.avatarUrl
			? '<img src="' + knHtmlEsc(p.avatarUrl) + '" loading="lazy" alt="" onerror="knAvatarFallback(this,\'' + initial + '\')">'
			: initial;
		var hbgAttr  = p.heraldryUrl ? ' style="--hbg:url(\'' + knHtmlEsc(p.heraldryUrl) + '\')"' : '';
		var hbgClass = p.heraldryUrl ? ' kn-player-card-hbg' : '';
		var pills = (p.officerRoles || '').split(', ').filter(Boolean).map(function(r) {
			return '<span class="kn-officer-pill">' + knHtmlEsc(r.trim()) + '</span>';
		}).join('');
		var classSpan = p.lastClass ? '<span><i class="fas fa-shield-alt" style="color:#b794f4;width:14px"></i> ' + knHtmlEsc(p.lastClass) + '</span>' : '';
		return '<a class="kn-player-card' + hbgClass + '"' + hbgAttr + ' href="' + uir + 'Player/profile/' + p.id + '">'
			+ '<div class="kn-player-card-top"><div class="kn-player-avatar">' + avatarHtml + '</div>'
			+ '<div><div class="kn-player-name">' + knHtmlEsc(p.persona) + '</div>' + pills + '</div></div>'
			+ '<div class="kn-player-stats">'
			+ '<span><i class="fas fa-map-marker-alt" style="color:#68d391;width:14px"></i> ' + knHtmlEsc(p.parkName) + '</span>'
			+ '<span><i class="fas fa-check-circle" style="color:#68d391;width:14px"></i> ' + p.signinCount + ' sign-in' + (p.signinCount !== 1 ? 's' : '') + '</span>'
			+ '<span><i class="fas fa-calendar-check" style="color:#63b3ed;width:14px"></i> ' + knFmtDate(p.lastSignin) + '</span>'
			+ classSpan + '</div></a>';
	}
	function knPlayerRowHtml(p, uir) {
		var pills = (p.officerRoles || '').split(', ').filter(Boolean).map(function(r) {
			return '<span class="kn-officer-pill">' + knHtmlEsc(r.trim()) + '</span>';
		}).join('');
		return '<tr onclick=\'window.location.href="' + uir + 'Player/profile/' + p.id + '"\'>'
			+ '<td>' + knHtmlEsc(p.persona) + pills + '</td>'
			+ '<td>' + knHtmlEsc(p.parkName || '') + '</td>'
			+ '<td data-sortval="' + p.signinCount + '">' + p.signinCount + '</td>'
			+ '<td class="kn-date-col" data-sortval="' + knHtmlEsc(p.lastSignin) + '">' + knFmtDate(p.lastSignin, true) + '</td>'
			+ '<td>' + knHtmlEsc(p.lastClass || '') + '</td>'
			+ '<td>' + knHtmlEsc(p.officerRoles || '') + '</td>'
			+ '</tr>';
	}

	var knPlayersLoaded = false;
	function knLoadPlayers() {
		if (knPlayersLoaded) return;
		var uir = '<?= UIR ?>';
		fetch(uir + 'Kingdom/players_json/' + kingdomId)
			.then(function(r) { return r.json(); })
			.then(function(data) {
				knPlayersLoaded = true;
				var players  = data.players || [];
				var nowTs    = Math.floor(Date.now() / 1000);
				var periods  = {};
				players.forEach(function(p) {
					var ts     = new Date((p.lastSignin || '1970-01-01') + 'T00:00:00').getTime() / 1000;
					var period = Math.max(0, Math.floor((nowTs - ts) / (30.44 * 24 * 3600 * 6)));
					if (!periods[period]) periods[period] = [];
					periods[period].push(p);
				});
				var periodKeys = Object.keys(periods).map(Number).sort(function(a,b){return a-b;});
				var active = (periods[0] || []).length, total = players.length;

				// Update tab count
				var tabCount = document.getElementById('kn-players-tab-count');
				if (tabCount) tabCount.textContent = '(' + total + ')';
				// Update summary line
				var summEl = document.getElementById('kn-players-summary');
				if (summEl) summEl.textContent = active + ' active member' + (active!==1?'s':'') + ' (past 6 months)' + (total > active ? ' · ' + total + ' total' : '');

				// Build cards HTML
				var cardsEl = document.getElementById('kn-players-cards');
				if (cardsEl) {
					var html = '<div class="kn-players-grid">' + (periods[0]||[]).map(function(p){return knPlayerCardHtml(p,uir);}).join('') + '</div>';
					periodKeys.slice(1).forEach(function(period) {
						html += '<div class="kn-period-block" id="kn-players-block-' + period + '" style="display:none">'
							+ '<div class="kn-period-label">' + (period*6) + '–' + ((period+1)*6) + ' months ago</div>'
							+ '<div class="kn-players-grid">' + periods[period].map(function(p){return knPlayerCardHtml(p,uir);}).join('') + '</div>'
							+ '</div>';
					});
					if (periodKeys.length > 1) {
						html += '<div class="kn-load-more-wrap" data-next="1" data-group="kn-players">'
							+ '<button class="kn-load-more-btn" onclick="knLoadMoreCards(\'kn-players\',this)"><i class="fas fa-chevron-down"></i> Load More...</button>'
							+ '<span class="kn-load-more-hint">Showing ' + active + ' of ' + total + ' members</span>'
							+ '</div>';
					}
					cardsEl.innerHTML = html;
					cardsEl.style.display = '';
				}

				// Build list tbody + templates
				var tbody = document.getElementById('kn-players-tbody');
				if (tbody) {
					tbody.innerHTML = (periods[0]||[]).map(function(p){return knPlayerRowHtml(p,uir);}).join('');
					var table = document.getElementById('kn-players-table');
					if (table) {
						periodKeys.slice(1).forEach(function(period) {
							var tmpl = document.createElement('template');
							tmpl.id = 'kn-players-tmpl-' + period;
							tmpl.innerHTML = periods[period].map(function(p){return knPlayerRowHtml(p,uir);}).join('');
							table.parentNode.insertBefore(tmpl, table.nextSibling);
						});
					}
					if (periodKeys.length > 1) {
						var moreWrap = document.getElementById('kn-players-list-more');
						if (moreWrap) {
							moreWrap.style.display = '';
							var hint = document.getElementById('kn-players-list-hint');
							if (hint) hint.textContent = 'Showing ' + active + ' of ' + total + ' members';
						}
					}
				}

				// Hide spinner
				var loadEl = document.getElementById('kn-players-loading');
				if (loadEl) loadEl.style.display = 'none';
			})
			.catch(function() {
				knPlayersLoaded = false;
				var loadEl = document.getElementById('kn-players-loading');
				if (loadEl) loadEl.innerHTML = '<span style="color:#e53e3e">Failed to load players.</span>';
			});
	}

	// Trigger on first Players tab click; also extend search to cover list rows
	document.addEventListener('DOMContentLoaded', function() {
		var btn = document.querySelector('[data-kntab="players"]');
		if (btn) btn.addEventListener('click', knLoadPlayers, {once: true});

		var searchInput = document.getElementById('kn-player-search');
		if (searchInput) {
			searchInput.addEventListener('input', function() {
				var q = this.value.trim().toLowerCase();
				var tbody = document.getElementById('kn-players-tbody');
				if (!tbody) return;
				tbody.querySelectorAll('tr').forEach(function(row) {
					var name = row.cells[0] ? row.cells[0].textContent.toLowerCase() : '';
					row.style.display = (!q || name.indexOf(q) !== -1) ? '' : 'none';
				});
			});
		}
	});

	window.knCopyIcsUrl = function() {
		var inp = document.getElementById('kn-sub-url-input');
		if (!inp) return;
		inp.select();
		inp.setSelectionRange(0, 99999);
		try {
			navigator.clipboard.writeText(inp.value).then(function() {
				var btn = document.querySelector('.kn-sub-copy-btn');
				if (btn) { btn.innerHTML = '<i class="fas fa-check"></i>'; setTimeout(function(){ btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 1500); }
			});
		} catch(e) { document.execCommand('copy'); }
	}

	// Close subscribe popover on outside click
	document.addEventListener('click', function(e) {
		var wrap = document.getElementById('kn-sub-wrap');
		if (wrap && !wrap.contains(e.target)) {
			var pop = document.getElementById('kn-sub-pop');
			if (pop) pop.style.setProperty('display', 'none', 'important');
		}
	});

})();
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(function() {
	if ($('#kn-rec-table').length) {
		$('#kn-rec-table').DataTable({
			order: [[4, 'desc']],
			columnDefs: [
				{ targets: [4], type: 'date' },
				<?php if ($CanManageKingdom ?? false): ?>
				{ targets: [-1], orderable: false, searchable: false },
				<?php endif; ?>
			],
			pageLength: 25
		});
	}
});
</script>