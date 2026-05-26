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
	$prinzParks       = is_array($principality_parks ?? null) ? $principality_parks : [];
	$prinzMapParks    = is_array($prinz_map_parks ?? null) ? $prinz_map_parks : [];
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
		$_ck = $o['CanonicalKey'] ?? $o['OfficerRole'] ?? '';
		if ($_ck === 'monarch') $monarch = $o;
		if ($_ck === 'regent')  $regent  = $o;
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
	// Principality parks — same location objects, flagged with prinz metadata
	foreach ($prinzMapParks as $prinz) {
		$prName = (string)($prinz['Name'] ?? '');
		$prId   = (int)($prinz['KingdomId'] ?? 0);
		foreach ((array)($prinz['parks'] ?? []) as $p) {
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
				'prinz'    => true,
				'prName'   => $prName,
				'prId'     => $prId,
			];
		}
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
			<?php if ($IsPrinz && !empty($ParentKingdomId)): ?>
			<div class="kn-parent-kingdom-link">
				<a href="<?= UIR ?>Kingdom/profile/<?= (int)$ParentKingdomId ?>">
					<i class="fas fa-chess-rook"></i> <?= htmlspecialchars($ParentKingdomName) ?>
				</a>
			</div>
			<?php endif; ?>
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
			<?php if ($CanManageKingdom ?? false): ?>
				<button class="kn-btn kn-btn-outline" onclick="knOpenAwardModal()">
					<i class="fas fa-medal"></i> Enter Awards
				</button>
			<?php endif; ?>
			<a class="kn-btn kn-btn-outline" href="#" onclick="knActivateTab('map');return false;">
				<i class="fas fa-map"></i> Map
			</a>
			<?php if ($CanManageKingdom ?? false): ?>
			<a class="kn-btn kn-btn-outline" href="<?= UIR ?>Admin/kingdom/<?= (int)($kingdom_id ?? 0) ?>">
				<i class="fas fa-cog"></i> Admin
			</a>
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
		<div class="kn-stat-number"><?= $StatsParkCount ?? count($parkList) ?></div>
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
		<div class="kn-stat-label">Avg / Week <span class="pk-stat-tip"><i class="fas fa-info-circle"></i><span class="pk-stat-tip-text">Distinct players per week across all parks in this Kingdom, averaged over the past 6 months. A player attending multiple parks in one week counts once.</span></span></div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-chart-line"></i></div>
		<div class="kn-stat-number" id="kn-stat-avgmo">—</div>
		<div class="kn-stat-label">Avg / Month <span class="pk-stat-tip"><i class="fas fa-info-circle"></i><span class="pk-stat-tip-text">Distinct players per month across all parks in this Kingdom, averaged over the past 12 months. A player attending multiple parks in one month counts once.</span></span></div>
	</div>
	<?php if (!empty($IsLoggedIn) && is_array($week_recap ?? null)): ?>
	<a class="kn-stat-card kn-stat-card-link" href="<?= UIR ?>Recap/kingdom/<?= (int)$kingdom_id ?>" style="text-decoration:none;color:inherit;" title="Amtgard Week in Review — Week of <?= htmlspecialchars($week_recap['WeekStart']) ?>">
		<div class="kn-stat-icon"><i class="fas fa-trophy"></i></div>
		<div class="kn-stat-number"><?= htmlspecialchars(date('M j', strtotime($week_recap['WeekStart']))) ?></div>
		<div class="kn-stat-label">Weekly Recap <i class="fas fa-arrow-right" style="font-size:0.75em;opacity:0.7;margin-left:2px"></i></div>
	</a>
	<?php endif; ?>
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
			<h4 class="kn-bare-heading" style="display:flex;align-items:center;justify-content:space-between;">
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
			<h4 class="kn-bare-heading"><i class="fas fa-info-circle"></i> About</h4>
			<div class="kn-description-body"><?= preg_replace('/<img[^>]*>/i', '', (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($_knDescription)) ?></div>
			<?php if (!empty($kingdom_info['Info']['KingdomInfo']['Url'] ?? '')): ?>
			<a class="kn-description-url" href="<?= htmlspecialchars($kingdom_info['Info']['KingdomInfo']['Url']) ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt" style="margin-right:4px;font-size:11px"></i><?= htmlspecialchars($kingdom_info['Info']['KingdomInfo']['Url']) ?></a>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<!-- Quick Links -->
		<div class="kn-card">
			<h4 class="kn-bare-heading"><i class="fas fa-link"></i> Quick Links</h4>
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
			</ul>
		</div>

	</div>

	<!-- ========== MAIN CONTENT (Tabbed) ========== -->
	<div class="kn-main">
		<div class="kn-tabs">
			<ul class="kn-tab-nav">
				<li class="kn-tab-active" data-kntab="parks">
					<i class="fas fa-map-marker-alt"></i><span class="kn-tab-label"> Parks</span>
					<span class="kn-tab-count">(<?= $StatsParkCount ?? count($parkList) ?>)</span>
				</li>
				<li data-kntab="events">
					<i class="fas fa-calendar-alt"></i><span class="kn-tab-label"> Events</span>
					<span class="kn-tab-count">(<?= count($eventList) ?>)</span>
				</li>
				<li data-kntab="map">
					<i class="fas fa-map"></i><span class="kn-tab-label"> Map</span>
				</li>
				<li data-kntab="players" id="kn-tab-btn-players">
					<i class="fas fa-users"></i><span class="kn-tab-label"> Players</span>
					<?php $_pcN = (int)($PlayerCount ?? 0); ?>
					<span class="kn-tab-count" id="kn-players-tab-count"><?= $_pcN > 0 ? '(' . $_pcN . ')' : '' ?></span>
				</li>
				<li data-kntab="reports">
					<i class="fas fa-chart-bar"></i><span class="kn-tab-label"> Reports</span>
				</li>
				<li data-kntab="officerhistory">
					<i class="fas fa-history"></i><span class="kn-tab-label"> Officer History</span>
				</li>
				<?php if ($ShowRecsTab ?? false):
					$_recsN = (int)($AwardRecommendationsCount ?? 0);
				?>
				<li data-kntab="recommendations">
					<i class="fas fa-star"></i><span class="kn-tab-label"> Recommendations</span>
					<span class="kn-tab-count" id="kn-tab-count-recs"<?= $_recsN > 0 ? '' : ' style="display:none"' ?>><?= $_recsN > 0 ? '(' . $_recsN . ')' : '' ?></span>
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
											<div class="kn-park-tile-stat-val kn-avgwk-tile"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></div>
											<div class="kn-park-tile-stat-lbl">Avg/Wk</div>
										</div>
										<div class="kn-park-tile-stat">
											<div class="kn-park-tile-stat-val kn-avgmo-tile"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></div>
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
									<th data-sorttype="numeric" class="kn-col-numeric" title="Average distinct players per week over the past 6 months">Avg/Wk</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Average distinct players per month over the past 12 months">Avg/Mo</th>
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
										<td class="kn-col-numeric kn-avgwk-row"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></td>
										<td class="kn-col-numeric kn-avgmo-row"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></td>
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

				<?php endif; ?>

				<!-- ===== Principalities (folded into Parks tab) ===== -->
				<?php if (count($prinzParks) > 0): ?>

					<!-- Principality tile sections (tile view) -->
					<div id="kn-prinz-tile-sections">
						<?php foreach ($prinzParks as $prinz): ?>
							<?php $prId = (int)$prinz['KingdomId']; $prHeraldry = HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf("%04d", $prId)); ?>
							<section class="kn-prinz-section" data-prinz-id="<?= $prId ?>">
								<a class="kn-prinz-head" href="<?= UIR ?>Kingdom/profile/<?= $prId ?>">
									<img class="kn-prinz-heraldry" loading="lazy" src="<?= $prHeraldry ?>" onerror="this.src='<?= HTTP_KINGDOM_HERALDRY ?>0000.jpg'" alt="">
									<span class="kn-prinz-name"><?= htmlspecialchars($prinz['Name']) ?></span>
									<i class="fas fa-external-link-alt kn-prinz-extlink"></i>
								</a>
								<div class="kn-park-tiles">
									<?php foreach ((array)$prinz['parks'] as $park): ?>
										<?php $tileHeraldry = $park['HasHeraldry'] == 1
											? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId']))
											: HTTP_PARK_HERALDRY . '00000.jpg'; ?>
										<a class="kn-park-tile" href="<?= UIR ?>Park/profile/<?= $park['ParkId'] ?>" data-park-id="<?= (int)$park['ParkId'] ?>">
											<div class="kn-park-tile-img-wrap">
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
														<div class="kn-park-tile-stat-val kn-avgwk-tile"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></div>
														<div class="kn-park-tile-stat-lbl">Avg/Wk</div>
													</div>
													<div class="kn-park-tile-stat">
														<div class="kn-park-tile-stat-val kn-avgmo-tile"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></div>
														<div class="kn-park-tile-stat-lbl">Avg/Mo</div>
													</div>
												</div>
											</div>
										</a>
									<?php endforeach; ?>
								</div>
							</section>
						<?php endforeach; ?>
					</div>

					<!-- Principality tables (list view) -->
					<div id="kn-prinz-tables" style="display:none">
						<?php foreach ($prinzParks as $prinz): ?>
							<?php $prId = (int)$prinz['KingdomId']; $prHeraldry = HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf("%04d", $prId)); ?>
							<div class="kn-prinz-table-wrap" data-prinz-id="<?= $prId ?>">
								<a class="kn-prinz-head" href="<?= UIR ?>Kingdom/profile/<?= $prId ?>">
									<img class="kn-prinz-heraldry" loading="lazy" src="<?= $prHeraldry ?>" onerror="this.src='<?= HTTP_KINGDOM_HERALDRY ?>0000.jpg'" alt="">
									<span class="kn-prinz-name"><?= htmlspecialchars($prinz['Name']) ?></span>
									<i class="fas fa-external-link-alt kn-prinz-extlink"></i>
								</a>
								<table class="kn-table kn-sortable">
									<thead>
										<tr>
											<th data-sorttype="text">Park</th>
											<th data-sorttype="text">Type</th>
											<th data-sorttype="numeric" class="kn-col-numeric" title="Average distinct players per week over the past 6 months">Avg/Wk</th>
											<th data-sorttype="numeric" class="kn-col-numeric" title="Average distinct players per month over the past 12 months">Avg/Mo</th>
											<th data-sorttype="numeric" class="kn-col-numeric" title="Distinct players who signed in at this park in the past 12 months">Total Players</th>
											<th data-sorttype="numeric" class="kn-col-numeric" title="Distinct players whose home park is here who signed in at this park in the past 12 months">Total Members</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ((array)$prinz['parks'] as $park): ?>
											<tr class="kn-row-link" data-park-id="<?= (int)$park['ParkId'] ?>" onclick="window.location.href='<?= UIR ?>Park/profile/<?= $park['ParkId'] ?>'">
												<td class="kn-col-nowrap">
													<img class="kn-thumb"
														loading="lazy"
														src="<?= $park['HasHeraldry'] == 1 ? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId'])) : HTTP_PARK_HERALDRY . '00000.jpg' ?>"
														onerror="this.src='<?= HTTP_PARK_HERALDRY ?>00000.jpg'"
														alt="">
													<a href="<?= UIR ?>Park/profile/<?= $park['ParkId'] ?>"><?= htmlspecialchars($park['ParkName']) ?></a>
												</td>
												<td><?= htmlspecialchars(!empty($park['Title']) ? $park['Title'] : '') ?></td>
												<td class="kn-col-numeric kn-avgwk-row"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></td>
												<td class="kn-col-numeric kn-avgmo-row"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></td>
												<td class="kn-col-numeric kn-tp-row">—</td>
												<td class="kn-col-numeric kn-tm-row">—</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
									<tfoot>
										<tr>
											<td colspan="2"><?= htmlspecialchars($prinz['Name']) ?> Total</td>
											<td class="kn-col-numeric" id="kn-prinz-<?= $prId ?>-avgwk">—</td>
											<td class="kn-col-numeric" id="kn-prinz-<?= $prId ?>-avgmo">—</td>
											<td class="kn-col-numeric" id="kn-prinz-<?= $prId ?>-tp" title="Sum across parks (players may be counted in multiple parks)">—</td>
											<td class="kn-col-numeric" id="kn-prinz-<?= $prId ?>-tm">—</td>
										</tr>
									</tfoot>
								</table>
							</div>
						<?php endforeach; ?>
					</div>

				<?php endif; ?>

				<?php if (count($parkList) === 0 && count($prinzParks) === 0): ?>
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
			html[data-theme="dark"] .kn-sub-pop-title{color:var(--ork-text)}
			html[data-theme="dark"] .kn-sub-url-input{background:var(--ork-input-bg);border-color:var(--ork-input-border);color:var(--ork-text)}
			html[data-theme="dark"] .kn-sub-copy-btn{background:var(--ork-bg-tertiary);border-color:var(--ork-border);color:var(--ork-text-secondary)}
			html[data-theme="dark"] .kn-sub-copy-btn:hover{background:var(--ork-bg-secondary)}
			html[data-theme="dark"] .kn-sub-webcal-btn{color:var(--ork-text-muted)}
			html[data-theme="dark"] .kn-sub-webcal-btn:hover{color:var(--ork-text)}
			</style>
			<!-- Events Tab -->
			<div class="kn-tab-panel" id="kn-tab-events" style="display:none">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
					<h4 class="kn-bare-heading" style="margin:0;font-size:14px;font-weight:700;"><i class="fas fa-calendar-alt" style="margin-right:6px;color:#a0aec0"></i>Events</h4>
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
							<div class="kn-sub-pop" id="kn-sub-pop" style="display:none;position:fixed;z-index:9000;background:var(--ork-card-bg,#fff);border:1px solid var(--ork-border,#e2e8f0);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.15);padding:12px 14px;width:280px;font-size:13px;color:var(--ork-text,#2d3748)">
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
					<div id="kn-cal-loading" style="display:none;position:absolute;inset:0;background:var(--ork-overlay-light,rgba(255,255,255,0.88));z-index:10;align-items:center;justify-content:center;min-height:120px;">
						<i class="fas fa-spinner fa-spin" style="font-size:28px;color:#a0aec0"></i>
					</div>
					<div id="kn-events-cal"></div>
				</div>

				<!-- List view -->
				<div id="kn-events-list-view">
				<?php $hasParkDays = count($kingdom_park_days ?? []) > 0; ?>
				<?php $eventCount = count($eventList); ?>
				<?php $hasAnyRows = ($eventCount > 0) || $hasParkDays; ?>
				<table class="kn-table kn-sortable" id="kn-events-table"<?= $hasAnyRows ? '' : ' style="display:none"' ?>>
					<thead>
						<tr>
							<th data-sorttype="text">Event</th>
							<th data-sorttype="date">Next Date</th>
							<th data-sorttype="text">Park</th>
							<th data-sorttype="numeric">Going</th>
						<th data-sorttype="numeric">Interested</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($eventList as $event): ?>
							<tr class="kn-row-link" data-type="<?= $event['_IsParkEvent'] ? 'park-event' : 'kingdom-event' ?>"<?= $event['NextDetailId'] ? ' onclick="window.location.href=\''.UIR.'Event/detail/' . $event['EventId'] . '/' . $event['NextDetailId'] . '\'"' : '' ?>>
								<td class="kn-col-nowrap">
									<img class="kn-thumb <?= $event['_IsParkEvent'] ? 'kn-evt-park' : 'kn-evt-kingdom' ?>"
										loading="lazy"
										src="<?= $event['HasHeraldry'] == 1 ? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf("%05d", $event['EventId'])) : HTTP_EVENT_HERALDRY . '00000.jpg' ?>"
										onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
										alt="">
									<?php if ($event['NextDetailId']): ?><a href="<?= UIR ?>Event/detail/<?= $event['EventId'] ?>/<?= $event['NextDetailId'] ?>"><?= htmlspecialchars($event['Name']) ?></a><?php else: ?><?= htmlspecialchars($event['Name']) ?><?php endif; ?>
								</td>
								<td class="kn-col-nowrap">
									<?php if (0 != $event['NextDate'] && $event['NextDate'] != '0000-00-00'): ?>
										<?= date("M j, Y", strtotime($event['NextDate'])) ?>
										<?php if (strtotime($event['NextDate']) < time()): ?><span class='event-past-badge'>Past</span><?php endif; ?>
									<?php else: ?>
										<span style="color:#a0aec0">—</span>
									<?php endif; ?>
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
				<div class="kn-empty" id="kn-events-empty"<?= $hasAnyRows ? ' style="display:none"' : '' ?>>No upcoming events</div>

				<div class="kn-events-loadmore" id="kn-events-loadmore" data-next-window="1" data-loaded-event-count="<?= $eventCount ?>">
					<span class="kn-events-loadmore-msg">
						Showing <strong id="kn-events-loadmore-count"><?= $eventCount ?></strong>
						event<span id="kn-events-loadmore-plural"><?= $eventCount === 1 ? '' : 's' ?></span>
						in the next <strong id="kn-events-loadmore-months">12</strong> months.
					</span>
					<?php if (!empty($HasMoreEvents)): ?>
					<a href="#" class="kn-events-loadmore-link" id="kn-events-loadmore-link" onclick="knLoadMoreEvents(event); return false;">Load more <i class="fas fa-chevron-down" style="font-size:10px;margin-left:3px"></i></a>
					<?php endif; ?>
				</div>
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

			<!-- Reports Tab -->
			<div class="kn-tab-panel" id="kn-tab-reports" style="display:none">
				<?php if (!$IsLoggedIn): ?>
				<div style="background:var(--ork-alert-info-bg,#eaf4fb);border:1px solid var(--ork-alert-info-border,#b0d4ea);border-radius:4px;padding:8px 14px;margin-bottom:10px;font-size:0.9em;color:var(--ork-alert-info-text,#1a5276);">
					<i class="fas fa-info-circle"></i> <a href="<?= UIR ?>Login" style="color:var(--ork-alert-info-text,#1a5276);font-weight:600;">Log in</a> to see the full list of available reports.
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
							<?php if (in_array((int)$kingdom_id, [3, 4, 6, 10, 12, 14, 17, 19, 20, 24, 25, 27, 31, 36, 38])): ?><li><a href="<?= UIR ?>Reports/voting_eligible/Kingdom&id=<?= $kingdom_id ?>">Voting Eligible</a></li><?php endif; ?>
							<li><a href="<?= UIR ?>Reports/reeve/Kingdom&id=<?= $kingdom_id ?>">Reeve Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/corpora/Kingdom&id=<?= $kingdom_id ?>">Corpora Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/player_status_reconciliation/Kingdom&id=<?= $kingdom_id ?>">Player Status Reconciliation</a></li>
							<li><a href="<?= UIR ?>Reports/guilds&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?> Guilds</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/kingdom_officer_directory&KingdomId=<?= $kingdom_id ?>"><i class="fas fa-crown"></i> Park Officer Directory</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-medal"></i> Awards</h5>
						<ul>
							<?php if ($IsLoggedIn && (!empty($AwardRecsPublic) || !empty($CanManageKingdom))): ?>
							<li><a href="<?= UIR ?>Reports/player_award_recommendations&KingdomId=<?= $kingdom_id ?>">Recommendations</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/knights_and_masters&KingdomId=<?= $kingdom_id ?>">Knights &amp; Masters</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/knights_list&KingdomId=<?= $kingdom_id ?>">Knights</a></li>
							<li><a href="<?= UIR ?>Reports/masters_list&KingdomId=<?= $kingdom_id ?>">Masters</a></li>
							<li><a href="<?= UIR ?>Reports/player_awards&Ladder=8&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?>-level Awards</a></li>
							<li><a href="<?= UIR ?>Reports/class_masters&KingdomId=<?= $kingdom_id ?>">Class Masters/Paragons</a></li>
							<li><a href="<?= UIR ?>Reports/ladder_grid&KingdomId=<?= $kingdom_id ?>">Ladder Award Grid</a></li>
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

		<!-- Officer History Tab -->
		<div class="kn-tab-panel" id="kn-tab-officerhistory" style="display:none">
			<div class="kn-oh-toolbar">
				<select id="kn-oh-role-filter" class="kn-oh-filter-select" onchange="knLoadOfficerHistory()">
					<option value="">All Roles</option>
					<option value="Monarch">Monarch</option>
					<option value="Regent">Regent</option>
					<option value="Prime Minister">Prime Minister</option>
					<option value="Champion">Champion</option>
					<option value="GMR">GMR</option>
				</select>
				<?php if ($CanEditKingdom ?? false): ?>
				<button class="kn-btn kn-btn-secondary" onclick="knOpenOhBackfillModal()">
					<i class="fas fa-plus"></i> Add Historical Record
				</button>
				<?php endif; ?>
			</div>
			<div id="kn-oh-loading" style="text-align:center;padding:24px;color:#a0aec0;display:none">
				<i class="fas fa-spinner fa-spin"></i> Loading officer history...
			</div>
			<div id="kn-oh-empty" style="text-align:center;padding:32px 16px;color:#a0aec0;display:none">
				No officer history records found.
			</div>
			<table class="kn-oh-table" id="kn-oh-table" style="display:none">
				<thead>
					<tr>
						<th>Role</th>
						<th>Persona</th>
						<th>Start Date</th>
						<th>End Date</th>
						<th>Notes</th>
						<?php if ($CanEditKingdom ?? false): ?>
						<th style="width:40px"></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody id="kn-oh-tbody"></tbody>
			</table>
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
						<li><a href="<?= UIR ?>Admin/permissions/Kingdom/<?= $kingdom_id ?>">Roles &amp; Permissions</a></li>
						<li><a href="#" onclick="knOpenClaimParkModal();return false;">Claim Park</a></li>
					</ul>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- Recommendations Tab — body is lazy-loaded via Kingdom::recommendations_panel()
		     on first tab activation. Rendering the full list inline (1k-4k <tr> rows on a
		     busy kingdom) was blocking DOMContentLoaded for 1+ seconds. -->
		<?php if ($ShowRecsTab ?? false): ?>
		<div class="kn-tab-panel" id="kn-tab-recommendations" style="display:none">
			<div id="kn-recs-lazy" data-loaded="0" data-kid="<?= (int)$kingdom_id ?>">
				<div class="pk-recs-loading" style="padding:2em 0;text-align:center;color:#a0aec0">
					<i class="fas fa-spinner fa-spin"></i> Loading recommendations&hellip;
				</div>
			</div>
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
					<button class="kn-view-btn" id="kn-active-only-btn" type="button" title="Show only members with sign-ins in the past 6 months"><i class="fas fa-filter"></i> Active only</button>
					<div class="kn-view-toggle">
						<button class="kn-view-btn kn-view-active" data-knview="cards"><i class="fas fa-th-large"></i> Cards</button>
						<button class="kn-view-btn" data-knview="list"><i class="fas fa-list"></i> List</button>
					</div>
					<?php if ($CanManageKingdom ?? false): ?>
					<div class="plr-action-group">
						<button class="plr-add-btn" onclick="knOpenAddPlayerModal()"><i class="fas fa-user-plus"></i> Create Player</button>
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
			<div id="kn-players-list" style="display:none"></div>
		</div><!-- /kn-tab-players -->

		</div><!-- /kn-tabs -->
	</div><!-- /kn-main -->

</div><!-- /kn-layout -->

<!-- Officer History Backfill Modal -->
<?php if ($CanEditKingdom ?? false): ?>
<div id="kn-oh-backfill-overlay" style="display:none;position:fixed;inset:0;z-index:8000;background:rgba(0,0,0,0.45);align-items:center;justify-content:center">
	<div class="kn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-history" style="margin-right:8px;color:#2b6cb0"></i>Add Officer History Record</h3>
			<button class="kn-modal-close-btn" onclick="knCloseOhBackfillModal()">&times;</button>
		</div>
		<div class="kn-modal-body" style="overflow:visible">
			<div class="kn-form-error" id="kn-oh-bf-error"></div>
			<div id="kn-oh-bf-success" class="kn-oh-bf-success" style="display:none">
				<i class="fas fa-check-circle"></i> Record added successfully!
			</div>

			<div class="kn-acct-field" style="position:relative">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-oh-bf-player-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="kn-oh-bf-player-id" value="" />
				<div class="kn-ac-results" id="kn-oh-bf-player-results" style="position:fixed"></div>
			</div>

			<div class="kn-acct-field">
				<label>Role <span style="color:#e53e3e">*</span></label>
				<select id="kn-oh-bf-role">
					<option value="">Select role...</option>
					<option value="Monarch">Monarch</option>
					<option value="Regent">Regent</option>
					<option value="Prime Minister">Prime Minister</option>
					<option value="Champion">Champion</option>
					<option value="GMR">GMR</option>
				</select>
			</div>

			<div style="display:flex;gap:12px">
				<div class="kn-acct-field" style="flex:1">
					<label>Start Date <span style="color:#e53e3e">*</span></label>
					<input type="date" id="kn-oh-bf-start" />
				</div>
				<div class="kn-acct-field" style="flex:1">
					<label>End Date</label>
					<input type="date" id="kn-oh-bf-end" />
				</div>
			</div>

			<div class="kn-acct-field">
				<label>Notes <span style="color:#a0aec0;font-weight:400">(optional)</span></label>
				<textarea id="kn-oh-bf-notes" rows="2" maxlength="500" placeholder="e.g. Reign 42, appointed mid-term..."></textarea>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn kn-btn-secondary" onclick="knCloseOhBackfillModal()">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-oh-bf-save-btn" onclick="knSaveOhBackfill()">
				<i class="fas fa-save" style="margin-right:4px"></i> Save Record
			</button>
		</div>
	</div>
</div>
<!-- Officer History Edit Modal -->
<?php if ($CanEditKingdom ?? false): ?>
<div id="kn-oh-edit-overlay" style="display:none;position:fixed;inset:0;z-index:8000;background:rgba(0,0,0,0.45);align-items:center;justify-content:center">
	<div class="kn-modal-box" style="width:480px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-pencil-alt" style="margin-right:8px;color:#2b6cb0"></i>Edit Officer History Record</h3>
			<button class="kn-modal-close-btn" onclick="knCloseOhEditModal()">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div class="kn-form-error" id="kn-oh-ed-error"></div>
			<div id="kn-oh-ed-success" class="kn-oh-bf-success" style="display:none">
				<i class="fas fa-check-circle"></i> Record updated successfully!
			</div>
			<input type="hidden" id="kn-oh-ed-id" value="" />

			<div class="kn-acct-field">
				<label>Player</label>
				<input type="text" id="kn-oh-ed-player" disabled style="background:#f7fafc;color:#718096;cursor:not-allowed" />
			</div>

			<div class="kn-acct-field">
				<label>Role <span style="color:#e53e3e">*</span></label>
				<select id="kn-oh-ed-role">
					<option value="Monarch">Monarch</option>
					<option value="Regent">Regent</option>
					<option value="Prime Minister">Prime Minister</option>
					<option value="Champion">Champion</option>
					<option value="GMR">GMR</option>
				</select>
			</div>

			<div style="display:flex;gap:12px">
				<div class="kn-acct-field" style="flex:1">
					<label>Start Date <span style="color:#e53e3e">*</span></label>
					<input type="date" id="kn-oh-ed-start" />
				</div>
				<div class="kn-acct-field" style="flex:1">
					<label>End Date</label>
					<input type="date" id="kn-oh-ed-end" />
				</div>
			</div>

			<div class="kn-acct-field">
				<label>Notes <span style="color:#a0aec0;font-weight:400">(optional)</span></label>
				<textarea id="kn-oh-ed-notes" rows="2" maxlength="500"></textarea>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn kn-btn-secondary" onclick="knCloseOhEditModal()">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-oh-ed-save-btn" onclick="knSaveOhEdit()">
				<i class="fas fa-save" style="margin-right:4px"></i> Save Changes
			</button>
		</div>
	</div>
</div>
<?php endif; ?>

<?php endif; ?>

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
	principalityIds:  <?= json_encode(array_map(function($p){ return (int)$p['KingdomId']; }, $prinzParks)) ?>,
	preloadOfficers:  <?= json_encode($PreloadOfficers ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	isOrkAdmin:      <?= !empty($IsOrkAdmin) ? 'true' : 'false' ?>,
	adminInfo:       <?= json_encode($AdminInfo       ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminConfig:     <?= json_encode($AdminConfig     ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminParkTitles: <?= json_encode($AdminParkTitles ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminAwards:     <?= json_encode($AdminAwards     ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	systemAwards:    <?= json_encode($SystemAwards    ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminRecsPublic: <?= !empty($AwardRecsPublic) ? 'true' : 'false' ?>,
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
				<button type="button" class="kn-award-type-btn" id="kn-award-type-achievements">
					<i class="fas fa-star" style="margin-right:5px"></i>Achievement Titles
				</button>
				<button type="button" class="kn-award-type-btn" id="kn-award-type-associations">
					<i class="fas fa-handshake" style="margin-right:5px"></i>Associations
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
				<label for="kn-award-select" id="kn-award-select-label">Award <span style="color:#e53e3e">*</span></label>
				<select id="kn-award-select" name="KingdomAwardId">
					<option value="">Select award...</option>
					<?= $AwardOptions ?>
				</select>
				<div class="kn-award-info-line" id="kn-award-info-line"></div>
			</div>

			<!-- Custom Award Name -->
			<div class="kn-acct-field" id="kn-award-custom-row" style="display:none">
				<label for="kn-award-custom-name" id="kn-award-custom-label">Custom Award Name</label>
				<input type="text" id="kn-award-custom-name" maxlength="64" placeholder="Enter custom award name..." />
			</div>

			<!-- Alias dropdown (shown only for "Custom Title") -->
			<div class="kn-acct-field" id="kn-award-alias-row" style="display:none">
				<label for="kn-award-alias">Alias of <span style="color:var(--ork-text-lighter);font-weight:400;font-size:11px">(optional)</span></label>
				<select name="AliasAwardId" id="kn-award-alias">
					<option value="0">— None —</option>
					<?php if (!empty($CustomTitleAliasOptions['Peerage'])): ?>
					<optgroup label="Peerage Ladder">
						<?php foreach ($CustomTitleAliasOptions['Peerage'] as $_opt): ?>
						<option value="<?= (int)$_opt['AwardId'] ?>"><?= htmlspecialchars($_opt['Name']) ?> (<?= htmlspecialchars($_opt['Peerage']) ?>)</option>
						<?php endforeach; ?>
					</optgroup>
					<?php endif; ?>
					<?php if (!empty($CustomTitleAliasOptions['Titles'])): ?>
					<optgroup label="Other Titles">
						<?php foreach ($CustomTitleAliasOptions['Titles'] as $_opt): ?>
						<option value="<?= (int)$_opt['AwardId'] ?>"><?= htmlspecialchars($_opt['Name']) ?></option>
						<?php endforeach; ?>
					</optgroup>
					<?php endif; ?>
				</select>
				<div style="font-size:11px;color:var(--ork-text-muted);margin-top:4px">Aliasing makes this title count as the selected core award for belt relationships and reports.</div>
			</div>

			<!-- Rank Picker -->
			<div class="kn-acct-field" id="kn-award-rank-row" style="display:none">
				<label>Rank <span id="kn-rank-hint" style="color:#a0aec0;font-weight:400;font-size:11px">— Select a rank of the award to recommend. Green ranks have already been awarded. You can suggest a rank higher than their next if you believe they have achieved it.</span></label>
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
				<div id="kn-award-givenby-note" style="display:none;margin-top:6px;padding:8px 12px;background:#ebf8ff;border:1px solid #bee3f8;border-radius:6px;color:#2b6cb0;font-size:12px;line-height:1.5;"><i class="fas fa-info-circle" style="margin-right:5px"></i>This should reflect the person granting the association. For example, if a Knight is taking a Squire, enter the Knight's name here.</div>
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
				<div id="kn-rec-award-desc" class="pn-rec-award-desc" style="display:none"></div>
			</div>
			<div class="pk-acct-field" id="kn-rec-rank-row" style="display:none">
				<label>Rank <span id="kn-rec-rank-hint" style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<div class="kn-rank-pills-wrap" id="kn-rec-rank-pills"></div>
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
			<div id="kn-emod-date-row" style="display:none;font-size:12px;color:var(--ork-alert-info-text,#2b6cb0);margin-top:8px;padding:5px 8px;background:var(--ork-alert-info-bg,#ebf8ff);border-radius:5px;border-left:3px solid var(--ork-alert-info-border,#90cdf4)">
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
				<div id="kn-heraldry-remove-confirm" style="display:none;margin-top:10px;padding:10px;background:var(--ork-alert-danger-bg,#fff5f5);border:1px solid var(--ork-alert-danger-border,#fed7d7);border-radius:6px;font-size:13px;color:var(--ork-alert-danger-text,#c53030);text-align:left">
					Remove this kingdom's heraldry image?
					<div style="margin-top:8px;display:flex;gap:8px">
						<button type="button" class="pn-btn pn-btn-ghost pn-btn-sm" onclick="document.getElementById('kn-heraldry-remove-confirm').style.display='none'">Cancel</button>
						<button type="button" class="pn-btn pn-btn-sm kn-btn-danger" onclick="knDoRemoveHeraldry()">Yes, Remove</button>
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
					<div class="kn-admin-field kn-admin-recs-visibility-row" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid var(--ork-border,#e2e8f0);margin-bottom:12px">
						<div>
							<div style="font-size:13px;font-weight:600;color:var(--ork-text,#2d3748)">Recommendation Visibility</div>
							<div style="font-size:12px;color:var(--ork-text-muted,#718096);margin-top:3px">When Private, besides the monarchy, only the submitter can see their own recommendations.</div>
						</div>
						<select id="kn-admin-recs-public" style="font-size:13px;border:1.5px solid var(--ork-border,#e2e8f0);border-radius:6px;padding:5px 8px;flex-shrink:0">
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
								<th><span class="kn-admin-th-tip" title="Title Class determines rank precedence. Higher values = higher rank (e.g. 20=Knight, 30=Lord, 50=Baron, 90=Duke).">Class <i class="fas fa-question-circle" style="font-size:9px;color:#a0aec0;cursor:help"></i></span></th>
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
					<div class="kn-admin-award-search-wrap">
						<i class="fas fa-search kn-admin-award-search-icon"></i>
						<input type="text" id="kn-admin-award-search" class="kn-admin-award-search-input"
							placeholder="Filter awards by name or class&hellip;" autocomplete="off">
						<button type="button" id="kn-admin-award-search-clear" class="kn-admin-award-search-clear" title="Clear" style="display:none">&times;</button>
					</div>
					<div class="kn-admin-award-search-empty" id="kn-admin-award-search-empty" style="display:none">
						No awards match this filter.
					</div>
					<div class="kn-admin-table-wrap"><table class="kn-admin-table kn-admin-awards-table">
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
					</table></div>
					<div class="kn-admin-add-award-wrap" id="kn-admin-add-award-wrap" style="display:none">
						<div class="kn-admin-add-award-title">Add Award Alias</div>
						<p class="kn-admin-form-hint">An award alias lets you create additional variations on existing system awards and titles. For example, the default system title of “Man-at-Arms” would have variations such as “Woman-at-Arms” or “Person-at-Arms.” You can add as many aliases as you would like.</p>
						<div class="kn-admin-field">
							<label>System Award</label>
							<div class="kn-admin-alias-picker-wrap" style="position:relative">
								<input type="hidden" id="kn-admin-new-award-id">
								<button type="button" class="kn-admin-alias-trigger" id="kn-admin-alias-trigger">
									<span class="kn-admin-alias-label">Select a system award&hellip;</span>
									<i class="fas fa-chevron-down" style="font-size:11px;opacity:.5"></i>
								</button>
								<div class="kn-admin-alias-dropdown" id="kn-admin-alias-dropdown" style="display:none">
									<input type="text" class="kn-admin-alias-search" id="kn-admin-alias-search" placeholder="Search awards&hellip;" autocomplete="off">
									<div class="kn-admin-alias-list" id="kn-admin-alias-list"></div>
								</div>
							</div>
						</div>
						<div class="kn-admin-award-row-fields">
							<div class="kn-admin-field kn-admin-field-grow">
								<label>Kingdom Name <span class="kn-admin-hint-inline">(your kingdom&rsquo;s name for this award)</span></label>
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
								<label>Title Class <i class="fas fa-question-circle" title="Title Class determines rank precedence. Higher values = higher rank (e.g. 20=Knight, 30=Lord, 50=Baron, 90=Duke)." style="font-size:10px;color:#a0aec0;cursor:help"></i></label>
								<input type="number" id="kn-admin-new-tclass" min="0" value="0" style="width:64px" disabled>
							</div>
						</div>
						<div style="display:flex;gap:8px;margin-top:10px">
							<button class="kn-admin-save-btn" id="kn-admin-new-award-save">
								<i class="fas fa-plus"></i> Add Award Alias
							</button>
							<button class="kn-btn-ghost" id="kn-admin-new-award-cancel" style="font-size:13px">Cancel</button>
						</div>
					</div>
					<div class="kn-admin-add-award-wrap" id="kn-admin-add-custom-wrap" style="display:none">
						<div class="kn-admin-add-award-title">Add Kingdom-Specific Award</div>
						<p class="kn-admin-form-hint">A kingdom-specific award allows you to add awards only given out in your kingdom. For example, if your kingdom awards a custom award called “Order of the Key,” you can add it here so it can be marked in award records.</p>
						<div class="kn-admin-award-row-fields">
							<div class="kn-admin-field kn-admin-field-grow">
								<label>Award Name</label>
								<input type="text" id="kn-admin-custom-name" placeholder="e.g. Kingdom Spotlight">
							</div>
							<div class="kn-admin-field">
								<label>Reign Limit</label>
								<input type="number" id="kn-admin-custom-reign" min="0" value="0" style="width:64px">
							</div>
							<div class="kn-admin-field">
								<label>Month Limit</label>
								<input type="number" id="kn-admin-custom-month" min="0" value="0" style="width:64px">
							</div>
							<div class="kn-admin-field kn-admin-field-center">
								<label>Title?</label>
								<input type="checkbox" id="kn-admin-custom-istitle">
							</div>
							<div class="kn-admin-field">
								<label>Title Class <i class="fas fa-question-circle" title="Title Class determines rank precedence. Higher values = higher rank (e.g. 20=Knight, 30=Lord, 50=Baron, 90=Duke)." style="font-size:10px;color:#a0aec0;cursor:help"></i></label>
								<input type="number" id="kn-admin-custom-tclass" min="0" value="0" style="width:64px" disabled>
							</div>
						</div>
						<div style="display:flex;gap:8px;margin-top:10px">
							<button class="kn-admin-save-btn" id="kn-admin-custom-save">
								<i class="fas fa-plus"></i> Add Award
							</button>
							<button class="kn-btn-ghost" id="kn-admin-custom-cancel" style="font-size:13px">Cancel</button>
						</div>
					</div>
					<div class="kn-admin-award-add-btns">
						<button class="kn-admin-add-btn" id="kn-admin-awards-add-btn">
							<i class="fas fa-plus"></i> Add Award Alias
						</button>
						<button class="kn-admin-add-btn" id="kn-admin-custom-add-btn">
							<i class="fas fa-plus"></i> Add Kingdom-Specific Award
						</button>
					</div>
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
						</table></div>
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
			<p id="kn-confirm-message" style="margin:0;font-size:14px;color:var(--ork-text,#2d3748);line-height:1.6"></p>
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
			<h3 class="kn-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Create Player</h3>
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
					<div id="kn-addplayer-email-suggestion" class="esc-suggestion" role="alert">
						<i class="fas fa-magic"></i>
						<span class="esc-suggestion-text">Did you mean <strong></strong>?</span>
						<button type="button" class="esc-suggestion-use">Use it</button>
						<button type="button" class="esc-suggestion-dismiss" aria-label="Dismiss">&times;</button>
					</div>
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>Username <span class="plr-req">*</span></label>
					<input type="text" id="kn-addplayer-username" placeholder="min. 4 characters" autocomplete="new-password">
				</div>
				<div class="plr-field">
					<label>Password</label>
					<input type="password" id="kn-addplayer-password" placeholder="optional" autocomplete="new-password">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>Restrict Mundane Name Visibility</label>
					<div class="plr-radio-row">
						<label class="plr-radio"><input type="radio" name="kn-addplayer-restricted" value="0" checked> No</label>
						<label class="plr-radio"><input type="radio" name="kn-addplayer-restricted" value="1"> Yes</label>
					</div>
					<small style="display:block;color:var(--ork-text-muted);margin-top:4px">Hides the player's real name from searches and public displays. Use for members who prefer their mundane identity kept private.</small>
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
.kn-mp-toggle { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px; }
.kn-mp-toggle-btn {
	flex:1 1 auto; min-width:130px; padding:7px 10px; border:1px solid #cbd5e0; border-radius:6px; font-size:12px; font-weight:600;
	cursor:pointer; background:#fff; color:#4a5568; transition:background 0.15s,color 0.15s,border-color 0.15s; white-space:nowrap;
}
.kn-mp-toggle-btn:hover { border-color:#a0aec0; }
.kn-mp-toggle-btn.kn-mp-active { background:#2b6cb0; color:#fff; border-color:#2b6cb0; box-shadow:0 1px 3px rgba(0,0,0,0.15); }
/* Cascade filter dropdowns (Move Player) */
.kn-mp-cascade { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px; }
.kn-mp-cascade-sel { flex:1 1 140px; min-width:0; font-size:12px; padding:6px 8px; border:1px solid #cbd5e0; border-radius:6px; background:#fff; color:#4a5568; }
.kn-mp-cascade-sel:disabled { background:#edf2f7; color:#718096; cursor:not-allowed; }
#kn-moveplayer-overlay .kn-modal-body { overflow:visible; }
#kn-moveplayer-overlay .kn-acct-field { position:relative; }
#kn-moveplayer-overlay .kn-ac-results { position:absolute; left:0; right:0; z-index:9999; }
/* Subscribe popover */
.kn-sub-wrap { position:relative; }
.kn-sub-pop {
	display:none !important; position:fixed; z-index:9000;
	background:var(--ork-card-bg); border:1px solid #e2e8f0; border-radius:8px;
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

/* ===================================================================
   DARK MODE OVERRIDES — Kingdomnew profile
   Activated by: html[data-theme="dark"]
   =================================================================== */
html[data-theme="dark"] .kn-stat-number { color: hsl(var(--kn-hue), var(--kn-sat), var(--ork-accent-lightness, 65%)); }
html[data-theme="dark"] .kn-stat-icon { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-stat-label { color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-card { background: var(--ork-card-bg, #2d3748) !important; border-color: var(--ork-border, #4a5568) !important; color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .kn-card-header { color: var(--ork-text); border-color: var(--ork-border); background: transparent; text-shadow: none; }
html[data-theme="dark"] .kn-officer-item { border-color: var(--ork-border); }
html[data-theme="dark"] .kn-officer-label { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-officer-name { color: var(--ork-text); }
html[data-theme="dark"] #theme_container .kn-officer-name a { color: hsl(calc(var(--kn-hue) + 35), 65%, var(--ork-accent-mid-lightness, 58%)); }
html[data-theme="dark"] #theme_container .kn-link-list a { color: hsl(calc(var(--kn-hue) + 35), 65%, var(--ork-accent-mid-lightness, 58%)); }
html[data-theme="dark"] .kn-tab-nav { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-tab-nav li { color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-tab-nav li.kn-tab-active { background: var(--ork-card-bg); color: hsl(var(--kn-hue), var(--kn-sat), var(--ork-accent-lightness, 65%)); border-color: var(--ork-border); border-bottom-color: hsl(var(--kn-hue), var(--kn-sat), var(--ork-accent-lightness, 65%)); }
html[data-theme="dark"] .kn-tab-nav li:hover:not(.kn-tab-active) { background: var(--ork-bg-tertiary); color: var(--ork-text); }
html[data-theme="dark"] .kn-tab-count { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-table { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-table th { background: var(--ork-bg-secondary); color: var(--ork-text-secondary); border-color: var(--ork-border); text-shadow: none; }
html[data-theme="dark"] .kn-table td { color: var(--ork-text-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-row-link:hover { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .kn-sub-pop { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-sub-pop-title { color: var(--ork-text); }
html[data-theme="dark"] .kn-sub-url-input { background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text); }
html[data-theme="dark"] .kn-sub-copy-btn { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-sub-copy-btn:hover { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .kn-sub-webcal-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-sub-webcal-btn:hover { color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-modal-box { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text); }
html[data-theme="dark"] .kn-modal-header { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
html[data-theme="dark"] .kn-modal-title { color: var(--ork-text); }
html[data-theme="dark"] .kn-modal-body { background: var(--ork-card-bg); color: var(--ork-text); }
html[data-theme="dark"] .kn-modal-footer { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-modal-close-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-modal-close-btn:hover { color: var(--ork-text); background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .kn-acct-field label { color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-acct-field input[type="text"],
html[data-theme="dark"] .kn-acct-field input[type="date"],
html[data-theme="dark"] .kn-acct-field input[type="number"],
html[data-theme="dark"] .kn-acct-field select,
html[data-theme="dark"] .kn-acct-field textarea { background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text); }
html[data-theme="dark"] .kn-mp-toggle-btn { background: var(--ork-bg-secondary); color: var(--ork-text-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-mp-toggle-btn:hover { border-color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-mp-toggle-btn.kn-mp-active { background: var(--ork-link); color: #fff; border-color: var(--ork-link); }
html[data-theme="dark"] .kn-mp-cascade-sel { background: var(--ork-input-bg); color: var(--ork-text); border-color: var(--ork-input-border); }
html[data-theme="dark"] .kn-mp-cascade-sel:disabled { background: var(--ork-bg-tertiary); color: var(--ork-text-muted); }
html[data-theme="dark"] #theme_container .kn-reports-grid a { color: var(--ork-link); }
html[data-theme="dark"] #theme_container .kn-reports-grid a:hover { color: var(--ork-link-bright); }
html[data-theme="dark"] .kn-map-sidebar-card { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text); }
html[data-theme="dark"] .kn-filter-toggle { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-filter-toggle.kn-filter-off { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-sidebar { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
/* Inline danger buttons */
.kn-btn-danger { background: #c53030; color: #fff; border-color: #c53030; }
html[data-theme="dark"] .kn-btn-danger { background: #fc8181; color: #1a202c; border-color: #fc8181; }

/* Officer History Tab */
.kn-oh-toolbar {
	display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap;
}
.kn-oh-filter-select {
	padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px;
	color:#4a5568; background:#fff; cursor:pointer;
}
.kn-oh-table {
	width:100%; border-collapse:collapse; font-size:13px;
}
.kn-oh-table thead th {
	background:#f7fafc; border-bottom:2px solid #e2e8f0; padding:8px 10px;
	text-align:left; font-weight:600; color:#4a5568; font-size:12px;
	text-transform:uppercase; letter-spacing:.03em;
}
.kn-oh-table tbody tr { border-bottom:1px solid #edf2f7; }
.kn-oh-table tbody tr:hover { background:#f7fafc; }
.kn-oh-table tbody td { padding:8px 10px; color:#2d3748; vertical-align:middle; }
.kn-oh-table .kn-oh-role-badge {
	display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px;
	font-weight:600; background:#ebf4ff; color:#2b6cb0;
}
.kn-oh-table .kn-oh-current { background:#c6f6d5; color:#276749; }
.kn-oh-del-btn {
	background:none; border:none; color:#e53e3e; cursor:pointer; font-size:14px;
	padding:4px; border-radius:4px; opacity:0.6; transition:opacity 0.15s;
}
.kn-oh-del-btn:hover { opacity:1; background:#fed7d7; }
.kn-oh-edit-btn {
	background:none; border:none; color:#3182ce; cursor:pointer; font-size:14px;
	padding:4px; border-radius:4px; opacity:0.6; transition:opacity 0.15s; margin-right:2px;
}
.kn-oh-edit-btn:hover { opacity:1; background:#ebf8ff; }
.kn-oh-notes-text { font-size:11px; color:#718096; font-style:italic; max-width:200px; }
.kn-oh-bf-success {
	background:#c6f6d5; color:#276749; padding:10px 14px; border-radius:6px;
	font-size:13px; margin-bottom:12px; text-align:center;
}
/* Officer History Backfill Modal */
#kn-oh-backfill-overlay .kn-modal-box {
	background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3);
	max-height:90vh; display:flex; flex-direction:column;
}
#kn-oh-backfill-overlay .kn-modal-header {
	display:flex; align-items:center; justify-content:space-between;
	padding:16px 20px; border-bottom:1px solid #e2e8f0; flex-shrink:0;
}
#kn-oh-backfill-overlay .kn-modal-title {
	font-size:16px; font-weight:700; color:#2d3748; margin:0;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:none;
}
#kn-oh-backfill-overlay .kn-modal-close-btn {
	background:none; border:none; font-size:22px; color:#a0aec0; cursor:pointer; padding:0 4px;
}
#kn-oh-backfill-overlay .kn-modal-close-btn:hover { color:#4a5568; }
#kn-oh-backfill-overlay .kn-modal-body {
	padding:20px; overflow-y:auto; flex:1;
}
#kn-oh-backfill-overlay .kn-modal-footer {
	padding:14px 20px; border-top:1px solid #e2e8f0;
	display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-shrink:0;
}
#kn-oh-backfill-overlay .kn-acct-field { position:relative; margin-bottom:14px; }
#kn-oh-backfill-overlay .kn-acct-field label {
	display:block; font-size:12px; font-weight:600; color:#4a5568; margin-bottom:4px;
}
#kn-oh-backfill-overlay .kn-acct-field input[type=text],
#kn-oh-backfill-overlay .kn-acct-field input[type=date],
#kn-oh-backfill-overlay .kn-acct-field select,
#kn-oh-backfill-overlay .kn-acct-field textarea {
	width:100%; padding:8px 10px; border:1px solid #e2e8f0; border-radius:6px;
	font-size:14px; color:#2d3748; background:#fff; box-sizing:border-box;
}
#kn-oh-backfill-overlay .kn-acct-field input:focus,
#kn-oh-backfill-overlay .kn-acct-field select:focus,
#kn-oh-backfill-overlay .kn-acct-field textarea:focus {
	outline:none; border-color:#3182ce; box-shadow:0 0 0 2px rgba(49,130,206,0.12);
}
#kn-oh-edit-overlay .kn-modal-box {
	background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3);
	max-height:90vh; display:flex; flex-direction:column;
}
#kn-oh-edit-overlay .kn-modal-header {
	display:flex; align-items:center; justify-content:space-between;
	padding:16px 20px; border-bottom:1px solid #e2e8f0; flex-shrink:0;
}
#kn-oh-edit-overlay .kn-modal-title {
	font-size:16px; font-weight:700; color:#2d3748; margin:0;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:none;
}
#kn-oh-edit-overlay .kn-modal-close-btn {
	background:none; border:none; font-size:22px; color:#a0aec0; cursor:pointer; padding:0 4px;
}
#kn-oh-edit-overlay .kn-modal-close-btn:hover { color:#4a5568; }
#kn-oh-edit-overlay .kn-modal-body {
	padding:20px; overflow-y:auto; flex:1;
}
#kn-oh-edit-overlay .kn-modal-footer {
	padding:14px 20px; border-top:1px solid #e2e8f0;
	display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-shrink:0;
}
#kn-oh-edit-overlay .kn-acct-field { position:relative; margin-bottom:14px; }
#kn-oh-edit-overlay .kn-acct-field label {
	display:block; font-size:12px; font-weight:600; color:#4a5568; margin-bottom:4px;
}
#kn-oh-edit-overlay .kn-acct-field input[type=text],
#kn-oh-edit-overlay .kn-acct-field input[type=date],
#kn-oh-edit-overlay .kn-acct-field select,
#kn-oh-edit-overlay .kn-acct-field textarea {
	width:100%; padding:8px 10px; border:1px solid #e2e8f0; border-radius:6px;
	font-size:14px; color:#2d3748; background:#fff; box-sizing:border-box;
}
#kn-oh-edit-overlay .kn-acct-field input:focus,
#kn-oh-edit-overlay .kn-acct-field select:focus,
#kn-oh-edit-overlay .kn-acct-field textarea:focus {
	outline:none; border-color:#3182ce; box-shadow:0 0 0 2px rgba(49,130,206,0.12);
}
#kn-oh-edit-overlay .kn-form-error {
	display:none; background:#fff5f5; border:1px solid #fed7d7; border-radius:6px;
	padding:8px 12px; margin-bottom:12px; color:#c53030; font-size:13px;
}
#kn-oh-backfill-overlay .kn-form-error {
	display:none; background:#fff5f5; border:1px solid #fed7d7; border-radius:6px;
	padding:8px 12px; margin-bottom:12px; color:#c53030; font-size:13px;
}
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
				<div class="kn-mp-cascade">
					<select class="kn-mp-cascade-sel" id="kn-mp-pfilter-kingdom" aria-label="Filter players by kingdom"></select>
					<select class="kn-mp-cascade-sel" id="kn-mp-pfilter-park" aria-label="Filter players by park" style="display:none"></select>
				</div>
				<input type="text" id="kn-moveplayer-player-name" autocomplete="off" placeholder="Search players outside this kingdom&hellip;">
				<input type="hidden" id="kn-moveplayer-player-id">
				<div class="kn-ac-results" id="kn-moveplayer-player-results"></div>
			</div>
			<div class="kn-acct-field" style="margin-top:10px">
				<label id="kn-moveplayer-park-label">New Home Park <span style="color:#e53e3e">*</span></label>
				<div class="kn-mp-cascade">
					<select class="kn-mp-cascade-sel" id="kn-mp-dfilter-kingdom" aria-label="Destination kingdom"></select>
					<select class="kn-mp-cascade-sel" id="kn-mp-dfilter-park" aria-label="Destination park" style="display:none"></select>
				</div>
				<input type="hidden" id="kn-moveplayer-park-id">
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
			<button class="kn-btn kn-btn-danger" id="kn-mergeplayer-submit" disabled><i class="fas fa-compress-alt"></i> Merge Players</button>
		</div>
	</div>
</div>

<!-- Claim Park Modal -->
<div id="kn-claimpark-overlay">
	<div class="kn-modal-box" style="width:460px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-flag" style="margin-right:8px;color:#276749"></i>Claim Park</h3>
			<button class="kn-modal-close-btn" id="kn-claimpark-close-btn">&times;</button>
		</div>
		<div class="kn-modal-body" style="padding:20px">
			<p style="font-size:14px;color:var(--ork-text);margin:0 0 10px">To claim a park, please submit documentation, including Althing results if possible, authorizing the move to:</p>
			<p style="font-size:15px;font-weight:600;margin:0 0 14px">
				<a href="mailto:Contracts@amtgard.com?subject=<?= rawurlencode('Park Claim Request — ' . ($kingdom_name ?? '')) ?>&body=<?= rawurlencode("Kingdom: " . ($kingdom_name ?? '') . "\nPark Name: \nAlthing Results: \nReason for Claim: ") ?>">Contracts@amtgard.com</a>
			</p>
			<p style="font-size:12px;color:var(--ork-text-muted);margin:0">Include the park name, your kingdom, and any supporting documentation.</p>
		</div>
		<div class="kn-modal-footer" style="justify-content:flex-end">
			<button class="kn-btn-ghost" id="kn-claimpark-cancel">Close</button>
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
	// Fill tile/row averages for a given park_averages_json payload.
	// opts = { scope: Element|null (default document),
	//          footer: {avgwk, avgmo, tp, tm} element-ids | null,
	//          statCards: bool }
	function knFillAverages(data, opts) {
		opts = opts || {};
		var scope = opts.scope || document;
		var totalAtt = 0, totalTp = 0, totalTm = 0;
		var wkCount = (data._kingdom && data._kingdom.wk_count) ? data._kingdom.wk_count : 26;
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
			totalAtt += att; totalTp += tp; totalTm += tm;
			// Tile view
			var tile = scope.querySelector('.kn-park-tile[data-park-id="' + parkId + '"]');
			if (tile) {
				var wkEl = tile.querySelector('.kn-avgwk-tile');
				var moEl = tile.querySelector('.kn-avgmo-tile');
				if (wkEl) wkEl.innerHTML = (att / wkCount).toFixed(1) + knTrend(att / wkCount, prevAtt !== undefined ? prevAtt / wkCount : undefined, 1);
				if (moEl) moEl.innerHTML = mo.toFixed(1) + knTrend(mo, prevMo !== undefined ? prevMo : undefined, 1);
			}
			// List view row
			var row = scope.querySelector('tr[data-park-id="' + parkId + '"]');
			if (row) {
				var wkTd = row.querySelector('.kn-avgwk-row');
				var moTd = row.querySelector('.kn-avgmo-row');
				var tpTd = row.querySelector('.kn-tp-row');
				var tmTd = row.querySelector('.kn-tm-row');
				if (wkTd) { wkTd.innerHTML = (att / wkCount).toFixed(2) + knTrend(att / wkCount, prevAtt !== undefined ? prevAtt / wkCount : undefined, 2); wkTd.setAttribute('data-sortval', att / wkCount); }
				if (moTd) { moTd.innerHTML = mo.toFixed(1) + knTrend(mo, prevMo !== undefined ? prevMo : undefined, 1); moTd.setAttribute('data-sortval', mo); }
				if (tpTd) { tpTd.textContent = tp;  tpTd.setAttribute('data-sortval', tp); }
				if (tmTd) { tmTd.textContent = tm;  tmTd.setAttribute('data-sortval', tm); }
			}
		}
		// Stat cards — use kingdom-level deduped values (avoids double-counting multi-park players)
		var wkBase = kingdomAtt !== null ? kingdomAtt : totalAtt;
		var moBase = (data._kingdom && data._kingdom.mo) ? data._kingdom.mo : 0;
		if (opts.statCards) {
			var statWk = document.getElementById('kn-stat-avgwk');
			var statMo = document.getElementById('kn-stat-avgmo');
			if (statWk) statWk.textContent = (wkBase / wkCount).toFixed(1);
			if (statMo) statMo.textContent = moBase.toFixed(1);
		}
		// Footer totals
		if (opts.footer) {
			var footWk = document.getElementById(opts.footer.avgwk);
			var footMo = document.getElementById(opts.footer.avgmo);
			var footTp = document.getElementById(opts.footer.tp);
			var footTm = document.getElementById(opts.footer.tm);
			if (footWk) footWk.textContent = (wkBase / wkCount).toFixed(2);
			if (footMo) footMo.textContent = moBase.toFixed(1);
			if (footTp) footTp.textContent = totalTp;
			if (footTm) footTm.textContent = totalTm;
		}
	}

	// Kingdom's own parks (stat cards + kingdom footer)
	fetch('<?= UIR ?>Kingdom/park_averages_json/' + kingdomId)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			knFillAverages(data, {
				scope: document,
				footer: { avgwk: 'kn-total-avgwk', avgmo: 'kn-total-avgmo', tp: 'kn-total-tp', tm: 'kn-total-tm' },
				statCards: true
			});
		})
		.catch(function(err) { console.error('Kingdom park_averages_json failed:', err); });

	// Principality parks — one fetch per principality id; park ids are globally unique so
	// document-scoped fills land on the right tiles/rows, and per-section footer ids keep totals separate.
	var knPrinzIds = (KnConfig.principalityIds || []);
	knPrinzIds.forEach(function(prId) {
		fetch('<?= UIR ?>Kingdom/park_averages_json/' + prId)
			.then(function(r) { return r.json(); })
			.then(function(data) {
				knFillAverages(data, {
					scope: document,
					footer: { avgwk: 'kn-prinz-' + prId + '-avgwk', avgmo: 'kn-prinz-' + prId + '-avgmo', tp: 'kn-prinz-' + prId + '-tp', tm: 'kn-prinz-' + prId + '-tm' },
					statCards: false
				});
			})
			.catch(function(err) { console.error('Principality park_averages_json failed (' + prId + '):', err); });
	});

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
		var mnAttr = p.mundaneName ? ' data-mundane-name="' + knHtmlEsc(p.mundaneName.toLowerCase()) + '"' : '';
		return '<a class="kn-player-card' + hbgClass + '"' + hbgAttr + mnAttr + ' data-signin-count="' + p.signinCount + '" href="' + uir + 'Player/profile/' + p.id + '">'
			+ '<div class="kn-player-card-top"><div class="kn-player-avatar">' + avatarHtml + '</div>'
			+ '<div><div class="kn-player-name">' + knHtmlEsc(p.persona) + '</div>' + pills + '</div></div>'
			+ '<div class="kn-player-stats">'
			+ '<span><i class="fas fa-map-marker-alt" style="color:#68d391;width:14px"></i> ' + knHtmlEsc(p.parkName) + '</span>'
			+ '<span><i class="fas fa-check-circle" style="color:#68d391;width:14px"></i> ' + p.signinCount + ' six month sign-in' + (p.signinCount !== 1 ? 's' : '') + '</span>'
			+ '<span><i class="fas fa-calendar-check" style="color:#63b3ed;width:14px"></i> ' + knFmtDate(p.lastSignin) + '</span>'
			+ classSpan + '</div></a>';
	}
	function knPlayerRowHtml(p, uir) {
		var pills = (p.officerRoles || '').split(', ').filter(Boolean).map(function(r) {
			return '<span class="kn-officer-pill">' + knHtmlEsc(r.trim()) + '</span>';
		}).join('');
		var mnAttr = p.mundaneName ? ' data-mundane-name="' + knHtmlEsc(p.mundaneName.toLowerCase()) + '"' : '';
		return '<tr' + mnAttr + ' data-signin-count="' + p.signinCount + '" onclick=\'window.location.href="' + uir + 'Player/profile/' + p.id + '"\'>'
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
				var players = data.players || [];
				var total   = players.length;

				// Bucket by year of last sign-in. Use a sentinel "Inactive" bucket for
				// players whose last_signin is the 1970 default (never attended).
				var byYear = {};
				var nowYear = new Date().getFullYear();
				var sixMoCutoff = Date.now() - 6 * 30.44 * 24 * 3600 * 1000;
				var activeRecent = 0;
				players.forEach(function(p) {
					var raw = p.lastSignin || '1970-01-01';
					var key;
					if (raw === '1970-01-01' || raw.indexOf('1970') === 0) {
						key = 'never';
					} else {
						key = raw.slice(0, 4); // YYYY
					}
					(byYear[key] = byYear[key] || []).push(p);
					var ts = new Date(raw + 'T00:00:00').getTime();
					if (ts >= sixMoCutoff) activeRecent++;
				});

				// Sort keys: real years descending (newest first), 'never' last.
				var yearKeys = Object.keys(byYear).filter(function(k){ return k !== 'never'; })
					.sort(function(a,b){ return b.localeCompare(a); });
				if (byYear.never) yearKeys.push('never');

				// Update tab count + summary line
				var tabCount = document.getElementById('kn-players-tab-count');
				if (tabCount) tabCount.textContent = '(' + total + ')';
				var summEl = document.getElementById('kn-players-summary');
				if (summEl) {
					summEl.textContent = activeRecent + ' active member' + (activeRecent!==1?'s':'')
						+ ' (past 6 months)' + (total > activeRecent ? ' · ' + total + ' total' : '');
				}

				// Build year-bucketed cards + list sections in one pass.
				var cardsEl = document.getElementById('kn-players-cards');
				var listEl  = document.getElementById('kn-players-list');
				var cardsHtml = [];
				var listHtml  = [];
				yearKeys.forEach(function(yk, idx) {
					var bucket = byYear[yk];
					var label  = (yk === 'never') ? 'No recorded sign-ins' : yk;
					if (yk !== 'never' && yk == nowYear) label = yk + ' (current)';
					var openAttr = idx === 0 ? ' open' : '';
					var count    = bucket.length;
					var summary  =
						'<summary class="kn-year-summary">'
						+ '<span class="kn-year-label">' + label + '</span>'
						+ '<span class="kn-year-count">' + count + ' member' + (count!==1?'s':'') + '</span>'
						+ '</summary>';

					cardsHtml.push(
						'<details class="kn-year-section"' + openAttr + ' data-year="' + yk + '">'
						+ summary
						+ '<div class="kn-players-grid">'
						+ bucket.map(function(p){ return knPlayerCardHtml(p, uir); }).join('')
						+ '</div></details>'
					);
					listHtml.push(
						'<details class="kn-year-section"' + openAttr + ' data-year="' + yk + '">'
						+ summary
						+ '<table class="kn-table kn-year-table"><thead><tr>'
						+ '<th data-sorttype="text">Persona</th>'
						+ '<th data-sorttype="text">Park</th>'
						+ '<th data-sorttype="numeric">6mo Sign-ins</th>'
						+ '<th data-sorttype="date">Last Visit</th>'
						+ '<th data-sorttype="text">Last Class</th>'
						+ '<th data-sorttype="text">Role</th>'
						+ '</tr></thead><tbody>'
						+ bucket.map(function(p){ return knPlayerRowHtml(p, uir); }).join('')
						+ '</tbody></table></details>'
					);
				});

				if (cardsEl) { cardsEl.innerHTML = cardsHtml.join(''); cardsEl.style.display = ''; }
				if (listEl)  { listEl.innerHTML  = listHtml.join('');  /* keeps display:none until view toggle */ }

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

		function knApplyPlayerFilters() {
			var qInput = document.getElementById('kn-player-search');
			var q = (qInput ? qInput.value : '').trim().toLowerCase();
			var aoBtn = document.getElementById('kn-active-only-btn');
			var activeOnly = aoBtn && aoBtn.classList.contains('kn-view-active');
			var roots = [
				document.getElementById('kn-players-cards'),
				document.getElementById('kn-players-list')
			];
			roots.forEach(function(root) {
				if (!root) return;
				root.querySelectorAll('.kn-player-card').forEach(function(card) {
					var nameEl = card.querySelector('.kn-player-name');
					var pName  = nameEl ? nameEl.textContent.toLowerCase() : '';
					var mn     = (card.dataset.mundaneName || '').toLowerCase();
					var sc     = parseInt(card.dataset.signinCount || '0', 10);
					var match  = (!q || pName.indexOf(q) !== -1 || mn.indexOf(q) !== -1) && (!activeOnly || sc > 0);
					card.style.display = match ? '' : 'none';
				});
				root.querySelectorAll('.kn-year-table tbody tr').forEach(function(row) {
					var persona = row.cells[0] ? row.cells[0].textContent.toLowerCase() : '';
					var mn      = (row.dataset.mundaneName || '').toLowerCase();
					var sc      = parseInt(row.dataset.signinCount || '0', 10);
					var match   = (!q || persona.indexOf(q) !== -1 || mn.indexOf(q) !== -1) && (!activeOnly || sc > 0);
					row.style.display = match ? '' : 'none';
				});
				var filtering = q || activeOnly;
				root.querySelectorAll('.kn-year-section').forEach(function(sec) {
					if (!filtering) { sec.style.display = ''; return; }
					var hasMatch = sec.querySelector('.kn-player-card:not([style*="display: none"]), .kn-year-table tbody tr:not([style*="display: none"])');
					sec.style.display = hasMatch ? '' : 'none';
					if (hasMatch) sec.open = true;
				});
			});
		}

		var searchInput = document.getElementById('kn-player-search');
		if (searchInput) searchInput.addEventListener('input', knApplyPlayerFilters);
		var aoBtn = document.getElementById('kn-active-only-btn');
		if (aoBtn) aoBtn.addEventListener('click', function() {
			this.classList.toggle('kn-view-active');
			knApplyPlayerFilters();
		});
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
<script>
// ---- Events: Load-more (next 12-month window) ----
function knLoadMoreEvents(ev) {
	if (ev) ev.preventDefault();
	var wrap = document.getElementById('kn-events-loadmore');
	var link = document.getElementById('kn-events-loadmore-link');
	if (!wrap || !link) return;
	var nextWindow = parseInt(wrap.dataset.nextWindow || '1', 10);
	var kingdomId = (window.KnConfig && KnConfig.kingdomId) || 0;
	var uir       = (window.KnConfig && KnConfig.uir)       || '<?= UIR ?>';
	if (!kingdomId) return;

	var origHtml = link.innerHTML;
	link.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
	link.style.pointerEvents = 'none';
	link.setAttribute('aria-busy', 'true');

	fetch(uir + 'Kingdom/events_more/' + kingdomId + '?window=' + nextWindow, { credentials: 'same-origin' })
		.then(function(r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		})
		.then(function(data) {
			var table = document.getElementById('kn-events-table');
			var tbody = table ? table.querySelector('tbody') : null;
			if (!tbody) return;

			// Reveal the table if it was hidden (empty-state page)
			if (table.style.display === 'none') table.style.display = '';
			var emptyEl = document.getElementById('kn-events-empty');
			if (emptyEl) emptyEl.style.display = 'none';

			// Append the new rows
			var appended = 0;
			(data.Events || []).forEach(function(e) {
				// Skip duplicates (shouldn't happen with non-overlapping windows, but defensive)
				if (document.querySelector('#kn-events-table tr[data-event-id="' + e.EventId + '"]')) return;
				tbody.appendChild(knBuildEventRow(e, data.FallbackHeraldry, data.Uir || uir));
				appended++;
			});

			// Update count + months in footer
			var countEl  = document.getElementById('kn-events-loadmore-count');
			var pluralEl = document.getElementById('kn-events-loadmore-plural');
			var monthsEl = document.getElementById('kn-events-loadmore-months');
			var prevLoaded = parseInt(wrap.dataset.loadedEventCount || '0', 10);
			var total = prevLoaded + appended;
			wrap.dataset.loadedEventCount = String(total);
			if (countEl)  countEl.textContent  = String(total);
			if (pluralEl) pluralEl.textContent = (total === 1 ? '' : 's');
			if (monthsEl) monthsEl.textContent = String(data.EndMonths);

			wrap.dataset.nextWindow = String(nextWindow + 1);

			// Respect current filter toggles for newly-appended rows
			try {
				if (typeof knFilters === 'object' && knFilters) {
					Object.keys(knFilters).forEach(function(type) {
						if (!knFilters[type]) {
							var rows = tbody.querySelectorAll('tr[data-type="' + type + '"]');
							rows.forEach(function(tr) { tr.style.display = 'none'; });
						}
					});
				}
			} catch (e) { console.warn('[knLoadMoreEvents] filter reapply failed', e); }

			// Re-run pagination if present
			try { if (typeof knPaginate === 'function' && window.jQuery) knPaginate(window.jQuery('#kn-events-table'), 1); } catch(e) {}

			// Calendar view: invalidate so next open re-fetches
			try { if (window.knCalendar) knCalendar.refetchEvents(); } catch(e) {}

			if (!data.HasMore) {
				link.remove();
			} else {
				link.innerHTML = 'Load more <i class="fas fa-chevron-down" style="font-size:10px;margin-left:3px"></i>';
				link.style.pointerEvents = '';
				link.removeAttribute('aria-busy');
			}
		})
		.catch(function(err) {
			console.error('[knLoadMoreEvents]', err);
			link.innerHTML = origHtml;
			link.style.pointerEvents = '';
			link.removeAttribute('aria-busy');
		});
}

function knBuildEventRow(e, fallbackHeraldry, uir) {
	var tr = document.createElement('tr');
	tr.className = 'kn-row-link';
	tr.dataset.type    = e.IsParkEvent ? 'park-event' : 'kingdom-event';
	tr.dataset.eventId = String(e.EventId);
	var detailHref = e.NextDetailId ? (uir + 'Event/detail/' + e.EventId + '/' + e.NextDetailId) : '';
	if (detailHref) tr.setAttribute('onclick', "window.location.href='" + detailHref + "'");

	var nameHtml = detailHref
		? '<a href="' + detailHref + '">' + knEscape(e.Name) + '</a>'
		: knEscape(e.Name);
	var dateHtml = e.NextDateText ? knEscape(e.NextDateText) : '<span style="color:#a0aec0">&mdash;</span>';
	var heraldry = e.HeraldryUrl || fallbackHeraldry;

	tr.innerHTML =
		'<td class="kn-col-nowrap">' + dateHtml + '</td>' +
		'<td class="kn-col-nowrap">' +
			'<img class="kn-thumb ' + (e.IsParkEvent ? 'kn-evt-park' : 'kn-evt-kingdom') +
				'" loading="lazy" src="' + knEscapeAttr(heraldry) +
				'" onerror="this.src=\'' + knEscapeAttr(fallbackHeraldry) + '\'" alt="">' +
			nameHtml +
		'</td>' +
		'<td>' + knEscape(e.ParkName || '') + '</td>' +
		'<td style="text-align:center">' + (e.RsvpGoing > 0 ? e.RsvpGoing : '&mdash;') + '</td>' +
		'<td style="text-align:center">' + (e.RsvpInterested > 0 ? e.RsvpInterested : '&mdash;') + '</td>';
	return tr;
}

function knEscape(s) {
	var d = document.createElement('div');
	d.textContent = (s == null ? '' : String(s));
	return d.innerHTML;
}
function knEscapeAttr(s) {
	return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/email-spell-checker.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
window.knRecActiveFilter = 'open';
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
	if (settings.nTable.id !== 'kn-rec-table') return true;
	var filter = window.knRecActiveFilter || 'all';
	if (filter === 'all') return true;
	var row = settings.aoData[dataIndex].nTr;
	var rowFilter = row ? row.getAttribute('data-filter') : '';
	if (filter === 'open') return rowFilter !== 'already';
	if (filter === 'mycircles') {
		if (rowFilter === 'already') return false;
		var aid = parseInt(row.getAttribute('data-award-id') || '0', 10);
		return (window.knRecCircleAwardIds || []).indexOf(aid) !== -1;
	}
	return rowFilter === filter;
});
// Initialise the rec-table DataTable + filter bar. Idempotent so the lazy-loader
// can call it again after injecting the rec-tab HTML on first tab activation.
window.knInitRecsTab = function() {
	var $tbl = $('#kn-rec-table');
	if (!$tbl.length) return;
	window.knRecCircleAwardIds = (function() { try { return JSON.parse($tbl.attr('data-circle-ids') || '[]'); } catch (e) { return []; } })();
	if ($.fn.dataTable.isDataTable('#kn-rec-table')) {
		$tbl.DataTable().destroy();
	}
	window.knRecDT = $tbl.DataTable({
		// Columns: 0 Player · 1 Park · 2 Award · 3 Rank · 4 Rec By · 5 Date · 6 Notes · (-1 actions)
		order: [[5, 'desc']],
		columnDefs: [
			{ targets: [5], type: 'date' },
			<?php if ($CanManageKingdom ?? false): ?>
			{ targets: [-1], orderable: false, searchable: false },
			<?php endif; ?>
		],
		pageLength: 25,
		scrollX: true
	});
	// Filter bar: re-bind in case the HTML is freshly injected.
	var bar = document.querySelector('#kn-tab-recommendations .kn-rec-filter-bar');
	if (bar && !bar.__knFilterBound) {
		bar.__knFilterBound = true;
		bar.addEventListener('click', function(e) {
			var btn = e.target.closest('.kn-rec-filter-btn');
			if (!btn) return;
			var filter = btn.dataset.filter;
			bar.querySelectorAll('.kn-rec-filter-btn').forEach(function(b) {
				b.classList.toggle('kn-rec-filter-active', b.dataset.filter === filter);
			});
			window.knRecActiveFilter = filter;
			if (window.knRecDT) window.knRecDT.draw();
		});
	}
};
$(function() { window.knInitRecsTab(); });
window.knRecPrint = function() { if (window.knRecDT) window.recsExportPrint(window.knRecDT, 'Award Recommendations \u2014 <?= htmlspecialchars(addslashes($kingdom_name)) ?>'); };
window.knRecCsv   = function() { if (window.knRecDT) window.recsExportCsv(window.knRecDT, 'recs-<?= preg_replace('/[^a-z0-9]+/i', '-', $kingdom_name) ?>.csv'); };
initEmailSpellCheck('kn-addplayer-email', 'kn-addplayer-email-suggestion');

// =============================================
// Officer History Tab
// =============================================
var knOhLoaded = false;
var knOhData   = [];

function knLoadOfficerHistory() {
    var role = document.getElementById('kn-oh-role-filter').value;
    var url  = KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/officerhistory';
    if (role) url += '?Role=' + encodeURIComponent(role);

    document.getElementById('kn-oh-loading').style.display = '';
    document.getElementById('kn-oh-table').style.display = 'none';
    document.getElementById('kn-oh-empty').style.display = 'none';

    $.getJSON(url, function(resp) {
        document.getElementById('kn-oh-loading').style.display = 'none';
        if (resp.status !== 0) { return; }
        knOhData = resp.history || [];
        knRenderOhTable(knOhData);
    }).fail(function() {
        document.getElementById('kn-oh-loading').style.display = 'none';
        document.getElementById('kn-oh-empty').style.display = '';
        document.getElementById('kn-oh-empty').textContent = 'Failed to load officer history.';
    });
}

function knRenderOhTable(data) {
    var tbody = document.getElementById('kn-oh-tbody');
    var table = document.getElementById('kn-oh-table');
    var empty = document.getElementById('kn-oh-empty');
    tbody.innerHTML = '';

    if (!data || data.length === 0) {
        table.style.display = 'none';
        empty.style.display = '';
        empty.textContent = 'No officer history records found.';
        return;
    }

    table.style.display = '';
    empty.style.display = 'none';
    var canEdit = KnConfig.canEdit;

    for (var i = 0; i < data.length; i++) {
        var h = data[i];
        var tr = document.createElement('tr');
        var isCurrent = !h.EndDate;
        var roleBadge = '<span class="kn-oh-role-badge' + (isCurrent ? ' kn-oh-current' : '') + '">' +
                        knHtmlEsc(h.Role) + (isCurrent ? ' (current)' : '') + '</span>';

        var persona = h.MundaneId > 0
            ? (isCurrent ? '<i class="fas fa-crown" style="color:#d69e2e;margin-right:4px" title="Current officer"></i>' : '') +
              '<a href="' + KnConfig.uir + 'Player/profile/' + h.MundaneId + '">' + knHtmlEsc(h.Persona || 'Unknown') + '</a>'
            : '<em style="color:#a0aec0">Vacant</em>';

        var startStr = h.StartDate ? knFormatDate(h.StartDate) : '';
        var endStr   = h.EndDate   ? knFormatDate(h.EndDate)   : (isCurrent ? '<em style="color:#38a169">Present</em>' : '');
        var notes    = h.Notes ? '<span class="kn-oh-notes-text">' + knHtmlEsc(h.Notes) + '</span>' : '';

        var delCell = '';
        if (canEdit) {
            delCell = '<td style="white-space:nowrap">' +
                '<button class="kn-oh-edit-btn" onclick="knOpenOhEditModal(' + i + ')" title="Edit record"><i class="fas fa-pencil-alt"></i></button>' +
                '<button class="kn-oh-del-btn" onclick="knDeleteOhRecord(' + h.OfficerHistoryId + ')" title="Delete record"><i class="fas fa-trash-alt"></i></button>' +
                '</td>';
        }

        tr.innerHTML = '<td>' + roleBadge + '</td>' +
                        '<td>' + persona + '</td>' +
                        '<td>' + startStr + '</td>' +
                        '<td>' + endStr + '</td>' +
                        '<td>' + notes + '</td>' +
                        delCell;
        tbody.appendChild(tr);
    }
}

function knFormatDate(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr + 'T00:00:00');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
}

function knHtmlEsc(s) {
    if (!s) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(s));
    return div.innerHTML;
}

function knDeleteOhRecord(ohid) {
    if (!confirm('Delete this officer history record?')) return;
    $.post(KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/deleteofficerhistory',
        { OfficerHistoryId: ohid },
        function(resp) {
            if (resp.status === 0) {
                knLoadOfficerHistory();
            } else {
                alert(resp.error || 'Failed to delete record.');
            }
        }, 'json'
    ).fail(function() { alert('Network error.'); });
}

// Backfill modal
function knOpenOhBackfillModal() {
    var overlay = document.getElementById('kn-oh-backfill-overlay');
    overlay.style.display = 'flex';
    document.getElementById('kn-oh-bf-error').textContent = '';
    document.getElementById('kn-oh-bf-error').style.display = 'none';
    document.getElementById('kn-oh-bf-success').style.display = 'none';
    document.getElementById('kn-oh-bf-player-text').value = '';
    document.getElementById('kn-oh-bf-player-id').value = '';
    document.getElementById('kn-oh-bf-role').value = '';
    document.getElementById('kn-oh-bf-start').value = '';
    document.getElementById('kn-oh-bf-end').value = '';
    document.getElementById('kn-oh-bf-notes').value = '';
}

function knCloseOhBackfillModal() {
    document.getElementById('kn-oh-backfill-overlay').style.display = 'none';
    var results = document.getElementById('kn-oh-bf-player-results');
    results.innerHTML = '';
    results.classList.remove('kn-ac-open');
}

function knSaveOhBackfill() {
    var mid   = document.getElementById('kn-oh-bf-player-id').value;
    var role  = document.getElementById('kn-oh-bf-role').value;
    var start = document.getElementById('kn-oh-bf-start').value;
    var end   = document.getElementById('kn-oh-bf-end').value;
    var notes = document.getElementById('kn-oh-bf-notes').value;
    var errEl = document.getElementById('kn-oh-bf-error');

    if (!mid)   { errEl.textContent = 'Please select a player.';  errEl.style.display = ''; return; }
    if (!role)  { errEl.textContent = 'Role is required.';         errEl.style.display = ''; return; }
    if (!start) { errEl.textContent = 'Start date is required.';   errEl.style.display = ''; return; }
    errEl.style.display = 'none';

    var btn = document.getElementById('kn-oh-bf-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    $.post(KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/addofficerhistory',
        { MundaneId: mid, Role: role, StartDate: start, EndDate: end, Notes: notes },
        function(resp) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Save Record';
            if (resp.status === 0) {
                document.getElementById('kn-oh-bf-success').style.display = '';
                setTimeout(function() {
                    knCloseOhBackfillModal();
                    knLoadOfficerHistory();
                }, 800);
            } else {
                errEl.textContent = resp.error || 'Failed to save record.';
                errEl.style.display = '';
            }
        }, 'json'
    ).fail(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Save Record';
        errEl.textContent = 'Network error.';
        errEl.style.display = '';
    });
}

// Edit modal
function knOpenOhEditModal(idx) {
    var h = knOhData[idx];
    if (!h) return;
    var overlay = document.getElementById('kn-oh-edit-overlay');
    overlay.style.display = 'flex';
    document.getElementById('kn-oh-ed-error').textContent = '';
    document.getElementById('kn-oh-ed-error').style.display = 'none';
    document.getElementById('kn-oh-ed-success').style.display = 'none';
    document.getElementById('kn-oh-ed-id').value = h.OfficerHistoryId;
    document.getElementById('kn-oh-ed-player').value = h.Persona || h.UserName || '(unknown)';
    document.getElementById('kn-oh-ed-role').value = h.Role;
    document.getElementById('kn-oh-ed-start').value = h.StartDate || '';
    document.getElementById('kn-oh-ed-end').value = h.EndDate || '';
    document.getElementById('kn-oh-ed-notes').value = h.Notes || '';
}

function knCloseOhEditModal() {
    document.getElementById('kn-oh-edit-overlay').style.display = 'none';
}

function knSaveOhEdit() {
    var ohid  = document.getElementById('kn-oh-ed-id').value;
    var role  = document.getElementById('kn-oh-ed-role').value;
    var start = document.getElementById('kn-oh-ed-start').value;
    var end   = document.getElementById('kn-oh-ed-end').value;
    var notes = document.getElementById('kn-oh-ed-notes').value;
    var errEl = document.getElementById('kn-oh-ed-error');

    if (!role)  { errEl.textContent = 'Role is required.';       errEl.style.display = ''; return; }
    if (!start) { errEl.textContent = 'Start date is required.'; errEl.style.display = ''; return; }
    errEl.style.display = 'none';

    var btn = document.getElementById('kn-oh-ed-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    $.post(KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/editofficerhistory',
        { OfficerHistoryId: ohid, Role: role, StartDate: start, EndDate: end, Notes: notes },
        function(resp) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Save Changes';
            if (resp.status === 0) {
                document.getElementById('kn-oh-ed-success').style.display = '';
                setTimeout(function() {
                    knCloseOhEditModal();
                    knLoadOfficerHistory();
                }, 800);
            } else {
                errEl.textContent = resp.error || 'Failed to save changes.';
                errEl.style.display = '';
            }
        }, 'json'
    ).fail(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Save Changes';
        errEl.textContent = 'Network error.';
        errEl.style.display = '';
    });
}

// Player autocomplete for backfill modal
(function() {
    var input   = document.getElementById('kn-oh-bf-player-text');
    var hidden  = document.getElementById('kn-oh-bf-player-id');
    var results = document.getElementById('kn-oh-bf-player-results');
    if (!input) return;
    var debounce;

    input.addEventListener('input', function() {
        clearTimeout(debounce);
        hidden.value = '';
        var q = input.value.trim();
        if (q.length < 2) { results.innerHTML = ''; results.classList.remove('kn-ac-open'); return; }
        debounce = setTimeout(function() {
            var url = KnConfig.uir + 'KingdomAjax/playersearch/' + KnConfig.kingdomId + '?q=' + encodeURIComponent(q) + '&scope=all&include_inactive=1';
            $.getJSON(url, function(data) {
                results.innerHTML = '';
                if (!data || data.length === 0) {
                    results.innerHTML = '<div class="kn-ac-item kn-ac-empty">No results</div>';
                    if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
                    results.classList.add('kn-ac-open');
                    return;
                }
                for (var i = 0; i < data.length; i++) {
                    var d = data[i];
                    var el = document.createElement('div');
                    el.className = 'kn-ac-item';
                    el.setAttribute('data-id', d.MundaneId);
                    el.innerHTML = '<span class="kn-ac-persona">' + knHtmlEsc(d.Persona) + '</span>' +
                                   '<span class="kn-ac-park">' + knHtmlEsc((d.KAbbr||'') + ':' + (d.PAbbr||'')) + '</span>';
                    el.addEventListener('click', (function(dd) {
                        return function() {
                            input.value = dd.Persona;
                            hidden.value = dd.MundaneId;
                            results.innerHTML = '';
                            results.classList.remove('kn-ac-open');
                        };
                    })(d));
                    results.appendChild(el);
                }
                if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
                results.classList.add('kn-ac-open');
            });
        }, 250);
    });

    document.addEventListener('click', function(e) {
        if (!results.contains(e.target) && e.target !== input) {
            results.innerHTML = '';
            results.classList.remove('kn-ac-open');
        }
    });
})();

// Hook into tab activation to lazy-load officer history
var _origKnActivateTab = typeof knActivateTab === 'function' ? knActivateTab : null;
// We can't override knActivateTab before revised.js loads, so use a MutationObserver or just hook via the tab click
$(document).on('click', '.kn-tab-nav li[data-kntab="officerhistory"]', function() {
    if (!knOhLoaded) {
        knOhLoaded = true;
        knLoadOfficerHistory();
    }
});
</script>

<?php if (!empty($IsLoggedIn)): ?>
<script>
window.OrkRsCfg = {
	url:         null,  /* unused */
	uir:         '<?= UIR ?>',
	userId:      <?= (int)$this->__session->user_id ?>,
	userPersona: <?= json_encode($this->__session->persona ?? '') ?>,
	reload:      function() { location.reload(); }
};
</script>
<?php include __DIR__ . '/_recommendation_seconds_assets.tpl'; ?>
<?php endif; ?>
