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

	$_knInfo         = $kingdom_info['Info']['KingdomInfo'] ?? [];
	$hasBanner       = !empty($_knInfo['HasBanner']);
	$bannerShowLogo  = !isset($_knInfo['BannerShowLogo']) || (int)$_knInfo['BannerShowLogo'] !== 0;
	$bannerVignette  = !isset($_knInfo['BannerVignette']) || (int)$_knInfo['BannerVignette'] !== 0;
	$bannerOffsetX   = isset($_knInfo['BannerOffsetX']) ? max(0, min(100, (int)$_knInfo['BannerOffsetX'])) : 50;
	$bannerOffsetY   = isset($_knInfo['BannerOffsetY']) ? max(0, min(100, (int)$_knInfo['BannerOffsetY'])) : 50;
	$bannerUrl       = '';
	if ($hasBanner) {
		$bannerFile = Common::resolve_image_ext(DIR_KINGDOM_BANNER, sprintf('%04d', (int)($_knInfo['KingdomId'] ?? 0)));
		$bannerFs   = DIR_KINGDOM_BANNER . $bannerFile;
		if (file_exists($bannerFs)) {
			$bannerUrl = HTTP_KINGDOM_BANNER . $bannerFile . '?v=' . filemtime($bannerFs);
		}
	}
	$knCanManageBanner = !empty($CanManageKingdom);

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
<?php
	$_heroBgUrl    = $bannerUrl ?: $heraldryUrl;
	$_heroClasses  = 'kn-hero';
	if ($bannerUrl)                    $_heroClasses .= ' kn-hero-has-banner';
	if ($bannerUrl && $bannerVignette) $_heroClasses .= ' kn-hero-vignette';
	if ($knCanManageBanner)            $_heroClasses .= ' kn-hero-editable';
	$_knShowLogo = !$bannerUrl || $bannerShowLogo;
	$_bgStyle = '';
	if ($_heroBgUrl) {
		$_bgStyle = "background-image: url('" . htmlspecialchars($_heroBgUrl) . "');";
		if ($bannerUrl) {
			$_bgStyle .= ' background-position: ' . $bannerOffsetX . '% ' . $bannerOffsetY . '%;';
		}
	}
?>
<div class="<?= $_heroClasses ?>" id="kn-hero">
	<div class="kn-hero-bg"<?php if ($_bgStyle): ?> style="<?= $_bgStyle ?>"<?php endif; ?>></div>
	<?php if ($knCanManageBanner): ?>
	<button type="button" class="kn-banner-edit-btn"
			onclick="knOpenBannerModal()"
			aria-label="<?= $bannerUrl ? 'Update Banner Image' : 'Add Banner Image' ?>">
		<i class="fas fa-image"></i>
		<span class="kn-banner-edit-label"> <?= $bannerUrl ? 'Update Banner Image' : 'Add Banner Image' ?></span>
		<i class="fas fa-pencil-alt kn-banner-edit-pencil" aria-hidden="true"></i>
	</button>
	<?php endif; ?>
	<div class="kn-hero-content">

		<?php if ($_knShowLogo): ?>
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
		<?php endif; ?>

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
		<div class="kn-stat-label">Avg / Week <span class="pk-stat-tip"><i class="fas fa-info-circle"></i><span class="pk-stat-tip-text">Distinct players per week across all parks in this Kingdom, averaged over the past 6 months. A player attending multiple parks in one week counts once.</span></span></div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-chart-line"></i></div>
		<div class="kn-stat-number" id="kn-stat-avgmo">—</div>
		<div class="kn-stat-label">Avg / Month <span class="pk-stat-tip"><i class="fas fa-info-circle"></i><span class="pk-stat-tip-text">Distinct players per month across all parks in this Kingdom, averaged over the past 12 months. A player attending multiple parks in one month counts once.</span></span></div>
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

				<?php else: ?>
					<div class="kn-empty">No parks found</div>
				<?php endif; ?>
			</div>

			<style>
			.kn-sub-pop-title{font-weight:700;color:#2d3748;margin-bottom:8px;font-size:12px;text-transform:uppercase;letter-spacing:.05em}
			.kn-sub-pop-row{display:flex;gap:4px;margin-bottom:8px}
			.kn-sub-url-input{flex:1;font-size:11px;padding:4px 6px;border:1px solid var(--ork-border);border-radius:4px;color:var(--ork-text-body);background:var(--ork-surface-light);min-width:0}
			.kn-sub-copy-btn{padding:4px 8px;border:1px solid var(--ork-border);border-radius:4px;background:var(--ork-surface-hover);cursor:pointer;color:var(--ork-text-body);font-size:12px}
			.kn-sub-copy-btn:hover{background:var(--ork-border)}
			.kn-sub-gcal-btn{display:block;text-align:center;background:#4285f4;color:#fff;border-radius:5px;padding:7px 10px;font-size:12px;font-weight:600;text-decoration:none;margin-bottom:2px}
			.kn-sub-gcal-btn:hover{background:#3367d6;color:#fff}
			.kn-sub-webcal-btn{display:block;margin-top:6px;font-size:11px;color:var(--ork-text-muted);text-align:center;text-decoration:none}
			.kn-sub-webcal-btn:hover{color:var(--ork-text-body)}
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
							<button class="kn-view-btn" id="kn-ev-view-map" title="Map view"><i class="fas fa-map-marked-alt"></i></button>
						<div id="kn-ev-filter-bar" style="display:flex;align-items:center;gap:5px;">
							<span style="font-size:11px;font-weight:700;color:#a0aec0;text-transform:uppercase;letter-spacing:.05em;margin-right:2px;">Show:</span>
							<button class="kn-filter-toggle kn-filter-on" data-filter="kingdom-event">Kingdom Events</button>
							<button class="kn-filter-toggle kn-filter-on" data-filter="park-event">Park Events</button>
							<button class="kn-filter-toggle kn-filter-on" data-filter="calendar-item">Calendar Items</button>
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

				<!-- Map view (lazy-loaded Google Maps) -->
				<div id="kn-events-map-wrap" style="position:relative;display:none">
					<div id="kn-events-map" style="width:100%;height:480px;border-radius:8px;border:1px solid #e2e8f0;"></div>
					<div id="kn-events-map-footer" style="margin-top:8px;font-size:12px;color:#718096;display:none"></div>
				</div>

				<!-- List view -->
				<div id="kn-events-list-view">
				<?php $hasParkDays = count($kingdom_park_days ?? []) > 0; ?>
				<?php $eventCount = count($eventList); ?>
				<?php $hasAnyRows = ($eventCount > 0) || $hasParkDays; ?>
					<table class="kn-table kn-sortable" id="kn-events-table"<?= $hasAnyRows ? '' : ' style="display:none"' ?>>
						<thead>
							<tr>
								<th data-sorttype="date">Next Date</th>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="text">Park</th>
								<th colspan="2" style="text-align:center;">RSVP</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($eventList as $event): ?>
								<?php if (!empty($event['_IsCalendarItem'])): ?>
									<?php $ciOff = !empty($event['IsOfficerOnly']); $ciLoc = !empty($event['IsLocalsOnly']); ?>
									<tr class="kn-row-link <?= $ciOff ? 'kn-officer-only' : '' ?> <?= $ciLoc ? 'kn-locals-only' : '' ?>" data-type="calendar-item" onclick="knShowCalendarItemOverlay(<?= (int)$event['CalendarItemId'] ?>)">
										<td class="kn-col-nowrap">
											<?= ($event['NextDate'] && $event['NextDate'] != '0000-00-00')
												? date("M j, Y", strtotime($event['NextDate']))
												: '<span style="color:#a0aec0">—</span>' ?>
										</td>
										<td class="kn-col-nowrap">
											<span class="kn-ci-pill"><i class="fas fa-calendar-day"></i> Calendar Item</span>
											<?php if ($ciOff): ?><span class="kn-officer-pill" data-tip="Officer-only — hidden from non-officers"><i class="fas fa-shield-alt"></i></span><?php endif; ?><?php if ($ciLoc): ?><span class="kn-locals-pill" data-tip="Locals-only — hidden from out-of-area players"><i class="fas fa-map-marker-alt"></i></span><?php endif; ?>
											<?= htmlspecialchars($event['Name']) ?>
										</td>
										<td><?= htmlspecialchars($event['ParkName'] ?? '') ?></td>
										<td style="text-align:center;color:#a0aec0">—</td>
										<td style="text-align:center;color:#a0aec0">—</td>
									</tr>
								<?php else: ?>
									<?php $isDraft = (($event['Status'] ?? 'published') === 'draft'); ?>
									<tr class="kn-row-link <?= $isDraft ? 'kn-row-draft' : '' ?>" data-type="<?= $event['_IsParkEvent'] ? 'park-event' : 'kingdom-event' ?>"<?= $event['NextDetailId'] ? ' onclick="if(event.target.closest(\'.kn-rsvp-wrap\'))return; window.location.href=\''.UIR.'Event/detail/' . $event['EventId'] . '/' . $event['NextDetailId'] . '\'"' : '' ?>>
										<td class="kn-col-nowrap">
											<?php if (0 != $event['NextDate'] && $event['NextDate'] != '0000-00-00'): ?>
												<?= date("M j, Y", strtotime($event['NextDate'])) ?>
												<?php if (strtotime($event['NextDate']) < time()): ?><span class='event-past-badge'>Past</span><?php endif; ?>
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
											<?php if ($isDraft): ?><span class="kn-draft-pill" data-tip="Draft — hidden from members. Publish to make visible.">DRAFT</span><?php endif; ?>
											<?php if ($event['NextDetailId']): ?><a href="<?= UIR ?>Event/detail/<?= $event['EventId'] ?>/<?= $event['NextDetailId'] ?>"><?= htmlspecialchars($event['Name']) ?></a><?php else: ?><?= htmlspecialchars($event['Name']) ?><?php endif; ?>
											<?php if ($event['NextDetailId']): ?>
												<span class="kn-copy-link" data-url="<?= HTTP_UI ?>Event/detail/<?= $event['EventId'] ?>/<?= $event['NextDetailId'] ?>" onclick="event.stopPropagation(); knCopyEventLink(this)" data-tip="Copy the event link and share to boost RSVPs!"><i class="fas fa-link"></i></span>
											<?php endif; ?>
											<?php if (!empty($event['MonarchRsvp'])): ?>
												<span class="kn-royal-badge kn-royal-monarch" data-tip="Monarch in Attendance"><i class="fas fa-crown"></i></span>
											<?php endif; ?>
											<?php if (!empty($event['RegentRsvp'])): ?>
												<span class="kn-royal-badge kn-royal-regent" data-tip="Regent in Attendance"><i class="fas fa-crown"></i></span>
											<?php endif; ?>
										</td>
										<td><?= htmlspecialchars($event['ParkName']) ?></td>
										<td colspan="2" style="text-align:center;padding:6px 8px;">
											<?php if ((int)$event['NextDetailId'] > 0): ?>
												<span class="kn-rsvp-wrap" data-detail="<?= (int)$event['NextDetailId'] ?>" data-going="<?= (int)($event['RsvpGoing'] ?? 0) ?>" data-interested="<?= (int)($event['RsvpInterested'] ?? 0) ?>" data-mine="<?= htmlspecialchars($event['MyRsvp'] ?? '') ?>"></span>
											<?php else: ?>
												<span style="color:#a0aec0">—</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endif; ?>
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
			<?php if ($CanManageKingdom ?? false): ?>
			<div class="kn-rec-filter-bar">
				<button class="kn-rec-filter-btn kn-rec-filter-active" data-filter="open">Open Recs</button>
				<button class="kn-rec-filter-btn" data-filter="below">Below Recommended</button>
				<button class="kn-rec-filter-btn" data-filter="nonladder">Non-Ladder</button>
				<button class="kn-rec-filter-btn" data-filter="already">At or Above Recommended</button>
				<button class="kn-rec-filter-btn" data-filter="all">All</button>
				<span class="kn-rec-filter-info">
					<button class="kn-rec-filter-info-btn" type="button" aria-label="Filter help"><i class="fas fa-question-circle"></i></button>
					<div class="kn-rec-filter-popover">
						<h4>About These Filters</h4>
						<dl>
							<dt>Open Recs <small style="font-weight:400;color:#718096">(default)</small></dt>
							<dd>All pending recommendations &mdash; both rank-based and flat awards. Hides recs that have already been fulfilled.</dd>
							<dt>Below Recommended</dt>
							<dd>Players who haven&rsquo;t yet reached the recommended rank. The core action list &mdash; Grant these.</dd>
							<dt>Non-Ladder</dt>
							<dd>Includes titles such as Master, Noble, or Knight, custom awards, and other non-ranked options. Grant or Delete as appropriate.</dd>
							<dt>At or Above Recommended</dt>
							<dd>Players who already hold this award at or above the recommended rank. The rec has been fulfilled &mdash; Delete these to keep the list tidy.</dd>
							<dt>All</dt>
							<dd>Every recommendation regardless of status. Use for a full audit.</dd>
						</dl>
					</div>
				</span>
				<span class="kn-rec-export-btns">
					<button class="kn-rec-export-btn" type="button" onclick="knRecPrint()"><i class="fas fa-print"></i> Print</button>
					<button class="kn-rec-export-btn" type="button" onclick="knRecCsv()"><i class="fas fa-download"></i> CSV</button>
				</span>
			</div>
			<?php endif; ?>
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
							<?php if (!empty($IsLoggedIn)): ?><th style="width:1%;white-space:nowrap"></th><?php endif; ?>
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
						<td class="pk-rec-notes"><?php if (!empty($rec['Reason'])): ?><span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($rec['Reason'], 0, 50)) ?><?php if (mb_strlen($rec['Reason']) > 50): ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($rec['Reason'], 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span><?php endif; ?></span><?php else: ?>&mdash;<?php endif; ?>
							<?php if (!empty($rec['ViewerCanEditReason'])): ?>
							<button class="rs-edit-reason-btn" data-rec="<?= (int)$rec['RecommendationsId'] ?>" data-reason="<?= htmlspecialchars($rec['Reason'] ?? '', ENT_QUOTES) ?>" data-award="<?= htmlspecialchars($rec['AwardName'] ?? '', ENT_QUOTES) ?>" data-rstip="Edit your reason"><i class="fas fa-pen"></i></button>
							<?php endif; ?>
							<?php if (!empty($rec['Seconds']) && is_array($rec['Seconds'])): ?>
							<div class="rs-seconds">
								<?php foreach ($rec['Seconds'] as $sec): ?>
								<div class="rs-second"><i class="fas fa-thumbs-up" style="color:#48bb78;font-size:10px"></i><a class="rs-supporter" href="<?= UIR ?>Player/profile/<?= (int)$sec['SupporterMundaneId'] ?>"><?= htmlspecialchars($sec['SupporterName'] ?? '') ?></a><?php if (!empty($sec['Notes'])): $_sn = $sec['Notes']; ?><span class="rs-notes">&mdash; "<?php if (mb_strlen($_sn) > 50): ?><span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($_sn, 0, 50)) ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($_sn, 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span></span><?php else: ?><?= htmlspecialchars($_sn) ?><?php endif; ?>"</span><?php else: ?><span class="rs-notes-empty">&mdash; (no comment)</span><?php endif; ?><?php $_canWithdrawSec = !empty($sec['IsMine']) || ($CanManageKingdom ?? false); if (!empty($sec['IsMine']) || $_canWithdrawSec): ?> <span class="rs-second-actions"><?php if (!empty($sec['IsMine'])): ?><button class="rs-second-edit" data-sid="<?= (int)$sec['RecommendationSecondsId'] ?>" data-notes="<?= htmlspecialchars($sec['Notes'] ?? '', ENT_QUOTES) ?>" data-rstip="Edit your notes"><i class="fas fa-pen"></i></button><?php endif; ?><?php if ($_canWithdrawSec): ?><button class="rs-second-withdraw" data-sid="<?= (int)$sec['RecommendationSecondsId'] ?>" data-supporter="<?= htmlspecialchars($sec['SupporterName'] ?? '', ENT_QUOTES) ?>" data-rstip="<?= !empty($sec['IsMine']) ? 'Withdraw your second' : 'Remove this second' ?>"><i class="fas fa-times"></i></button><?php endif; ?></span><?php endif; ?></div>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>
						</td>
						<?php if (!empty($IsLoggedIn)): ?>
						<td class="pk-rec-actions rs-tip-right" style="white-space:nowrap;text-align:right;width:1%">
							<?php if (!empty($rec['SecondsCount'])): $_sc = (int)$rec['SecondsCount']; ?>
							<span class="rs-seconds-badge" data-rstip="<?= $_sc ?> supporting <?= $_sc === 1 ? 'second' : 'seconds' ?>"><i class="fas fa-thumbs-up"></i><?= $_sc ?></span>
							<?php endif; ?>
							<?php if (!empty($rec['ViewerCanSecond'])): ?>
							<button class="rs-action-btn" data-rec="<?= (int)$rec['RecommendationsId'] ?>" data-award="<?= htmlspecialchars($rec['AwardName'] ?? '', ENT_QUOTES) ?>" data-recipient="<?= htmlspecialchars($rec['Persona'] ?? '', ENT_QUOTES) ?>" data-rstip="Second this recommendation and add your feedback."><i class="fas fa-plus"></i></button>
							<?php endif; ?>
							<?php if ($CanManageKingdom ?? false): ?>
							<button class="pk-btn pk-btn-primary pk-rec-grant-btn"
								data-rec="<?= htmlspecialchars(json_encode(['RecommendationsId'=>(int)$rec['RecommendationsId'],'MundaneId'=>(int)$rec['MundaneId'],'Persona'=>$rec['Persona'],'KingdomAwardId'=>(int)$rec['KingdomAwardId'],'Rank'=>(int)$rec['Rank'],'Reason'=>$rec['Reason']??''])) ?>">
								<i class="fas fa-medal"></i> Grant
							</button>
							<button class="pk-rec-dismiss-btn"
								data-rec-id="<?= (int)$rec['RecommendationsId'] ?>">
								<i class="fas fa-times"></i> Delete
							</button>
							<?php endif; ?>
						</td>
						<?php endif; ?>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<?php if ($CanManageKingdom ?? false): ?>
			<div class="pk-deleted-recs" id="kn-deleted-recs" data-loaded="0">
				<button type="button" class="pk-deleted-recs-toggle" id="kn-deleted-recs-toggle" aria-expanded="false">
					<span class="pk-deleted-recs-caret">&#9654;</span>
					<span class="pk-deleted-recs-toggle-label">Show Deleted Recommendations</span>
					<span class="pk-deleted-recs-count" id="kn-deleted-recs-count" style="display:none">0</span>
				</button>
				<div class="pk-deleted-recs-body" id="kn-deleted-recs-body" style="display:none">
					<div class="pk-deleted-recs-loading" id="kn-deleted-recs-loading">Loading&hellip;</div>
					<div class="pk-deleted-recs-empty" id="kn-deleted-recs-empty" style="display:none">No deleted recommendations.</div>
					<div class="pk-deleted-recs-search-wrap" style="display:none">
						<i class="fas fa-search"></i>
						<input type="text" class="pk-deleted-recs-search" placeholder="Search player, award, notes, or actor&hellip;" autocomplete="off">
					</div>
					<div class="pk-deleted-recs-no-match" style="display:none">No deleted recommendations match your search.</div>
					<div class="pk-deleted-recs-table-wrap" id="kn-deleted-recs-table-wrap" style="display:none">
						<table class="pk-deleted-recs-table">
							<thead>
								<tr>
									<th>Player</th>
									<th>Award</th>
									<th>Rank</th>
									<th>Notes</th>
									<th>Date Rec.</th>
									<th>Recommended By</th>
									<th>Deleted At</th>
									<th>Deleted By</th>
									<th></th>
								</tr>
							</thead>
							<tbody id="kn-deleted-recs-tbody"></tbody>
						</table>
					</div>
				</div>
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
	systemAwards:    <?= json_encode($SystemAwards    ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminRecsPublic: <?= !empty($AwardRecsPublic) ? 'true' : 'false' ?>,
};
window.knEventMapLocations  = <?= json_encode(array_values($knEventMapLocations ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.knEventMapNoLocCount = <?= (int)($knEventMapNoLocCount ?? 0) ?>;

var KnBannerConfig = {
	uir:            '<?= UIR ?>',
	canManage:      <?= $knCanManageBanner ? 'true' : 'false' ?>,
	entityId:       <?= (int)($_knInfo['KingdomId'] ?? 0) ?>,
	hasBanner:      <?= $hasBanner ? 'true' : 'false' ?>,
	bannerShowLogo: <?= $bannerShowLogo ? 'true' : 'false' ?>,
	bannerVignette: <?= $bannerVignette ? 'true' : 'false' ?>,
	bannerOffsetX:  <?= (int)$bannerOffsetX ?>,
	bannerOffsetY:  <?= (int)$bannerOffsetY ?>,
	bannerUrl:      <?= json_encode($bannerUrl) ?>,
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
				<label for="kn-award-custom-name">Custom Award Name</label>
				<input type="text" id="kn-award-custom-name" maxlength="64" placeholder="Enter custom award name..." />
			</div>

			<!-- Rank Picker -->
			<div class="kn-acct-field" id="kn-award-rank-row" style="display:none">
				<label>Rank <span id="kn-rank-hint" style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; blue = already held, green border = suggested next</span></label>
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<div class="kn-emod-overlay" id="kn-event-modal">
	<div class="kn-emod-box">
		<div class="kn-emod-header">
			<h3 id="kn-emod-title"><i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Create New Event</h3>
			<button class="kn-emod-close" onclick="knCloseEventModal()">&times;</button>
		</div>
		<div class="kn-emod-body">

			<!-- Type selector -->
			<div class="kn-emod-typesel">
				<label class="kn-emod-typeopt">
					<input type="radio" name="kn-emod-type" value="event" checked>
					<span><i class="fas fa-flag"></i> Amtgard Event</span>
				</label>
				<label class="kn-emod-typeopt">
					<input type="radio" name="kn-emod-type" value="calendar-item">
					<span><i class="fas fa-calendar-day"></i> Calendar Item</span>
				</label>
			</div>

			<!-- Shared: Name -->
			<div class="kn-emod-field">
				<label class="kn-emod-label">Name <span style="color:#e53e3e">*</span></label>
				<input type="text" class="kn-emod-input" id="kn-event-name" autocomplete="off" placeholder="e.g. Summer Midreign">
			</div>
			<div id="kn-emod-date-row" style="display:none;font-size:12px;color:var(--ork-alert-info-text,#2b6cb0);margin-top:8px;padding:5px 8px;background:var(--ork-alert-info-bg,#ebf8ff);border-radius:5px;border-left:3px solid var(--ork-alert-info-border,#90cdf4)">
				<i class="fas fa-calendar-alt" style="margin-right:5px"></i><span id="kn-emod-date-text"></span>
			</div>

			<!-- Event-only: Host Park -->
			<div class="kn-emod-field kn-emod-event-only" style="margin-top:12px">
				<label class="kn-emod-label">Host Park <span style="color:#a0aec0;font-weight:400;text-transform:none;letter-spacing:0">(optional — leave blank for a kingdom-level event)</span></label>
				<input type="text" class="kn-emod-input" id="kn-event-park-name" autocomplete="off" placeholder="Search parks…">
				<input type="hidden" id="kn-event-park-id">
			</div>

			<!-- Calendar-item-only fields -->
			<div class="kn-emod-ci-only" style="display:none">
				<div class="kn-emod-field" style="margin-top:12px">
					<label class="kn-emod-label">Host Park <span style="color:#a0aec0;font-weight:400;text-transform:none;letter-spacing:0">(optional — leave blank for a kingdom-level item)</span></label>
					<input type="text" class="kn-emod-input" id="kn-ci-park-name" autocomplete="off" placeholder="Search parks…">
					<input type="hidden" id="kn-ci-park-id">
				</div>
				<div class="kn-emod-field" style="margin-top:12px">
					<label class="kn-emod-check-label">
						<input type="checkbox" id="kn-ci-allday"> All day
					</label>
				</div>
				<div class="kn-emod-field" style="margin-top:6px">
					<label class="kn-emod-check-label" data-tip="Officer-only items are visible only to ORK admins and people serving as Monarch / Regent / PM / Champion of this kingdom or park.">
						<input type="checkbox" id="kn-ci-officer-only"> <i class="fas fa-shield-alt" style="margin:0 4px 0 2px;color:#805ad5"></i>Only Display to Officers
					</label>
				</div>
				<div class="kn-emod-field" style="margin-top:6px">
					<label class="kn-emod-check-label" data-tip="Locals-only items are visible only to ORK admins and to logged-in players whose home park (or kingdom, for kingdom-level items) matches.">
						<input type="checkbox" id="kn-ci-locals-only"> <i class="fas fa-map-marker-alt" style="margin:0 4px 0 2px;color:#0d9488"></i>Only Display to Local Park/Kingdom Players
					</label>
				</div>
				<div class="kn-emod-row" style="display:flex;gap:10px;margin-top:8px">
					<div class="kn-emod-field" style="flex:1">
						<label class="kn-emod-label">Start <span style="color:#e53e3e">*</span></label>
						<input type="text" class="kn-emod-input" id="kn-ci-start" autocomplete="off" placeholder="Select start…">
					</div>
					<div class="kn-emod-field" style="flex:1">
						<label class="kn-emod-label">End <span style="color:#e53e3e">*</span></label>
						<input type="text" class="kn-emod-input" id="kn-ci-end" autocomplete="off" placeholder="Select end…">
					</div>
				</div>
				<div class="kn-emod-field" style="margin-top:10px">
					<label class="kn-emod-label">Description</label>
					<textarea class="kn-emod-input" id="kn-ci-description" rows="3" placeholder="Optional details…"></textarea>
				</div>
				<div class="kn-emod-ci-note">
					<i class="fas fa-info-circle" style="margin-right:6px"></i>
					Calendar Items are lightweight. They do <strong>not</strong> support RSVPs, sign-ins, schedules, attendance, heraldry, pricing, or event authorization lists. Use an Amtgard Event for those.
				</div>
			</div>

			<div class="kn-emod-feedback" id="kn-emod-feedback" style="display:none"></div>
		</div>
		<div class="kn-emod-footer">
			<button class="kn-emod-btn-cancel" onclick="knCloseEventModal()">Cancel</button>
			<button class="kn-emod-btn-cancel kn-emod-draft-btn" id="kn-emod-draft-btn" onclick="knCreateEvent('draft')" disabled style="display:none;font-size:12px;">
				<i class="fas fa-eye-slash"></i> Save as Draft
			</button>
			<button class="kn-emod-btn-go" id="kn-emod-go-btn" onclick="knCreateEvent()" disabled>
				<span id="kn-emod-go-label">Create Event</span> <i class="fas fa-arrow-right"></i>
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
			<?php if ($CanEditKingdom ?? false): ?>
			<div class="kn-admin-panel" id="kn-admin-panel-signinlink">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-signinlink" aria-expanded="false">
					<span><i class="fas fa-link" style="margin-right:6px;color:#a0aec0"></i>Sign-in Link</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-signinlink"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-signinlink" style="display:none">
					<div class="kn-form-error" id="kn-signinlink-error" style="display:none"></div>
					<!-- Park selector (optional) -->
					<div class="kn-admin-field" style="margin-bottom:12px;position:relative">
						<label>Park <span style="font-weight:400;color:#a0aec0">(optional — leave blank for kingdom-wide)</span></label>
						<input type="text" id="kn-signinlink-park-name" autocomplete="off"
							placeholder="Search parks in this kingdom&hellip;"
							style="width:100%;box-sizing:border-box;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;color:#2d3748">
						<input type="hidden" id="kn-signinlink-park-id" value="">
						<div class="kn-ac-results" id="kn-signinlink-park-results"></div>
					</div>
					<div class="pk-att-search-row" style="margin-bottom:12px">
						<div class="pk-att-field pk-att-field-sm">
							<label>Duration (hrs)</label>
							<input type="number" id="kn-signinlink-hours" min="1" max="96" step="1" value="3">
						</div>
						<div class="pk-att-field pk-att-field-sm">
							<label>Credits</label>
							<input type="number" id="kn-signinlink-credits" min="0.5" max="10" step="0.5" value="1">
						</div>
						<div class="pk-att-field pk-att-field-btn">
							<label>&nbsp;</label>
							<button class="kn-btn kn-btn-primary" id="kn-signinlink-gen-btn">
								<i class="fas fa-link"></i> Generate
							</button>
						</div>
					</div>
					<div id="kn-signinlink-result" style="display:none;margin-bottom:12px">
						<div class="pk-att-link-url-row" style="display:flex;gap:8px;align-items:center">
							<input type="text" id="kn-signinlink-url" readonly
								style="flex:1;min-width:0;font-size:12px;padding:7px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#f7fafc">
							<button class="kn-btn kn-btn-secondary" id="kn-signinlink-copy-btn" style="white-space:nowrap">
								<i class="fas fa-copy"></i> Copy
							</button>
							<button class="kn-btn kn-btn-secondary" id="kn-signinlink-qr-btn" style="white-space:nowrap">
								<i class="fas fa-qrcode"></i> QR
							</button>
						</div>
						<div id="kn-signinlink-expires" style="margin-top:6px;font-size:11px;color:#718096"></div>
					</div>
					<p style="margin:0 0 12px;font-size:12px;color:#718096">
						<i class="fas fa-info-circle"></i> Players log in and select their class to record attendance.
					</p>
					<!-- Active links collapsible -->
					<div id="kn-signinlink-links-wrap" style="border-top:1px solid #e2e8f0;padding-top:10px">
						<button type="button" id="kn-signinlink-links-toggle" style="background:none;border:none;padding:0;cursor:pointer;font-size:12px;color:#4a5568;display:flex;align-items:center;gap:6px">
							<i class="fas fa-chevron-right" id="kn-signinlink-links-chevron" style="font-size:10px;transition:transform 0.15s"></i>
							<span>Active Links</span> <span id="kn-signinlink-links-count" style="color:#a0aec0"></span>
						</button>
						<div id="kn-signinlink-links-body" style="display:none;margin-top:8px">
							<div id="kn-signinlink-links-loading" style="font-size:12px;color:#a0aec0">Loading&hellip;</div>
							<div id="kn-signinlink-links-empty" style="display:none;font-size:12px;color:#a0aec0">No active links.</div>
							<table id="kn-signinlink-links-table" style="display:none;width:100%;border-collapse:collapse;font-size:12px">
								<thead><tr style="color:#718096;text-align:left">
									<th style="padding:4px 6px;font-weight:600">Scope</th>
									<th style="padding:4px 6px;font-weight:600">Expires</th>
									<th style="padding:4px 6px;font-weight:600">Cr.</th>
									<th style="padding:4px 6px"></th>
								</tr></thead>
								<tbody id="kn-signinlink-links-tbody"></tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>

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

<!-- Event Preview Overlay (calendar quick-look) -->
<div class="evpv-overlay" id="evpv-overlay">
	<div class="evpv-box">
		<div class="evpv-header">
			<div class="evpv-header-meta">
				<span class="evpv-kind-pill" id="evpv-kind-pill"><i class="fas fa-flag"></i> <span id="evpv-kind-label">Amtgard Event</span></span>
				<span class="kn-draft-pill" id="evpv-draft-pill" style="display:none">DRAFT</span>
			</div>
			<button class="evpv-close" onclick="evpvClose()" aria-label="Close">&times;</button>
		</div>
		<div class="evpv-body">
			<div class="evpv-hero">
				<img class="evpv-heraldry" id="evpv-heraldry" alt="" loading="lazy">
				<div class="evpv-hero-text">
					<a class="evpv-name" id="evpv-name" href="#"></a>
					<div class="evpv-meta-row">
						<span class="evpv-meta-date"><i class="far fa-calendar-alt"></i> <span id="evpv-date"></span></span>
					</div>
					<div class="evpv-meta-row">
						<span class="evpv-meta-time" id="evpv-time-row"><i class="far fa-clock"></i> <span id="evpv-time"></span></span>
						<span class="evpv-meta-park" id="evpv-park-row" style="display:none"><i class="fas fa-tree"></i> <span id="evpv-park"></span></span>
					</div>
				</div>
			</div>
			<div class="evpv-description" id="evpv-description" style="display:none"></div>
			<div class="evpv-rsvp-row">
				<span class="kn-rsvp-wrap" id="evpv-rsvp"></span>
			</div>
		</div>
		<div class="evpv-footer">
			<button class="kn-emod-btn-cancel" onclick="evpvClose()">Close</button>
			<a class="evpv-cta" id="evpv-cta" href="#"><i class="fas fa-arrow-right"></i> See Full Details</a>
		</div>
	</div>
</div>

<!-- Calendar Item Detail Overlay (read/edit/delete) — available to all viewers -->
<div class="kn-ci-overlay" id="kn-ci-overlay">
	<div class="kn-ci-box">
		<div class="kn-ci-header">
			<h3 id="kn-ci-view-title"><i class="fas fa-calendar-day" style="margin-right:8px;color:#64748b"></i>Calendar Item</h3>
			<button class="kn-emod-close" onclick="knCloseCalendarItemOverlay()">&times;</button>
		</div>
		<div class="kn-ci-body">
			<div class="kn-ci-name" id="kn-ci-view-name"></div>
			<div class="kn-ci-meta">
				<i class="fas fa-clock" style="margin-right:6px;color:#a0aec0"></i>
				<span id="kn-ci-view-when"></span>
			</div>
			<div class="kn-ci-scope" id="kn-ci-view-scope"></div>
			<div class="kn-ci-description" id="kn-ci-view-desc"></div>
		</div>
		<div class="kn-ci-footer">
			<button class="kn-emod-btn-cancel" onclick="knCloseCalendarItemOverlay()">Close</button>
			<button class="kn-emod-btn-cancel" id="kn-ci-edit-btn" style="display:none" onclick="knEditCalendarItem()">
				<i class="fas fa-pencil-alt"></i> Edit
			</button>
			<button class="kn-emod-btn-cancel" id="kn-ci-delete-btn" style="display:none;color:#c53030;border-color:#fc8181" onclick="knDeleteCalendarItem()">
				<i class="fas fa-trash"></i> Delete
			</button>
		</div>
	</div>
</div>

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

<style>
/* ---- Instant tooltip (data-tip) ---- */
[data-tip] { position: relative; }
[data-tip]::before, [data-tip]::after {
	position: absolute; left: 50%; bottom: 100%; pointer-events: none;
	opacity: 0; transition: opacity 0.08s;
}
[data-tip]::after {
	content: attr(data-tip); transform: translateX(-50%) translateY(-4px);
	background: #2d3748; color: #fff; font-size: 11px; font-weight: 500;
	padding: 4px 9px; border-radius: 4px; white-space: nowrap; z-index: 900;
}
[data-tip]::before {
	content: ''; transform: translateX(-50%); margin-bottom: -4px;
	border: 5px solid transparent; border-top-color: #2d3748; z-index: 901;
}
[data-tip]:hover::before, [data-tip]:hover::after { opacity: 1; }

/* ---- Royal Progress crowns ---- */
.kn-royal-badge {
	display: inline-flex; align-items: center;
	margin-left: 4px; font-size: 11px; cursor: default;
	position: relative; top: -1px;
}
.kn-royal-monarch { color: #b7791f; }
.kn-royal-regent  { color: #718096; }

/* ---- Copy-link icon ---- */
.kn-copy-link {
	display: inline-flex; align-items: center; justify-content: center;
	margin-left: 5px; font-size: 11px; color: #a0aec0;
	cursor: pointer; opacity: 0; transition: opacity 0.15s;
	position: relative;
}
tr:hover .kn-copy-link { opacity: 1; }
.kn-copy-link:hover { color: #4299e1; }
.kn-copy-link.kn-copied::after {
	content: 'Copied!' !important; position: absolute; bottom: 100%; left: 50%;
	transform: translateX(-50%); background: #2d3748; color: #fff;
	font-size: 11px; padding: 3px 8px; border-radius: 4px; white-space: nowrap;
	pointer-events: none; opacity: 1; animation: knCopiedFade 1.4s forwards;
}
@keyframes knCopiedFade {
	0%,70% { opacity: 1; } 100% { opacity: 0; }
}
</style>
<!-- Move Player Modal -->
<style>
.kn-mp-toggle { display:flex; background:var(--ork-surface-hover); border-radius:6px; padding:3px; gap:3px; margin-bottom:14px; }
.kn-mp-toggle-btn {
	flex:1; padding:6px 8px; border:none; border-radius:4px; font-size:11px; font-weight:600;
	cursor:pointer; background:transparent; color:var(--ork-text-muted); transition:background 0.15s,color 0.15s; white-space:nowrap;
}
.kn-mp-toggle-btn.kn-mp-active { background:#fff; color:#2b6cb0; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
#kn-moveplayer-overlay .kn-modal-body { overflow:visible; }
#kn-moveplayer-overlay .kn-acct-field { position:relative; }
#kn-moveplayer-overlay .kn-ac-results { position:absolute; left:0; right:0; z-index:9999; }
/* Subscribe popover */
.kn-sub-wrap { position:relative; }
.kn-sub-pop {
	display:none !important; position:fixed; z-index:9000;
	background:var(--ork-card-bg); border:1px solid var(--ork-border); border-radius:8px;
	box-shadow:0 4px 16px rgba(0,0,0,0.12); padding:12px 14px; width:280px; font-size:13px;
}
.kn-sub-pop.kn-sub-open { display:block !important; }
.kn-sub-pop-title {
	font-weight:700; color:#2d3748; margin-bottom:8px; font-size:12px;
	text-transform:uppercase; letter-spacing:.05em;
}
.kn-sub-pop-row { display:flex; gap:4px; margin-bottom:8px; }
.kn-sub-url-input {
	flex:1; font-size:11px; padding:4px 6px; border:1px solid var(--ork-border);
	border-radius:4px; color:var(--ork-text-body); background:var(--ork-surface-light); min-width:0;
}
.kn-sub-copy-btn {
	padding:4px 8px; border:1px solid var(--ork-border); border-radius:4px;
	background:var(--ork-surface-hover); cursor:pointer; color:var(--ork-text-body); font-size:12px;
}
.kn-sub-copy-btn:hover { background:var(--ork-border); }
.kn-sub-gcal-btn {
	display:block; text-align:center; background:#4285f4; color:#fff;
	border-radius:5px; padding:7px 10px; font-size:12px; font-weight:600; text-decoration:none;
}
.kn-sub-gcal-btn:hover { background:#3367d6; color:#fff; }
.kn-sub-webcal-btn {
	display:block; margin-top:6px; font-size:11px; color:var(--ork-text-muted); text-align:center; text-decoration:none;
}
.kn-sub-webcal-btn:hover { color:var(--ork-text-body); }

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
html[data-theme="dark"] .kn-mp-toggle { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .kn-mp-toggle-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-mp-toggle-btn.kn-mp-active { background: var(--ork-card-bg); color: var(--ork-link); }
html[data-theme="dark"] #theme_container .kn-reports-grid a { color: var(--ork-link); }
html[data-theme="dark"] #theme_container .kn-reports-grid a:hover { color: var(--ork-link-bright); }
html[data-theme="dark"] .kn-map-sidebar-card { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text); }
html[data-theme="dark"] .kn-filter-toggle { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-filter-toggle.kn-filter-off { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-sidebar { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
/* Inline danger buttons */
.kn-btn-danger { background: #c53030; color: #fff; border-color: #c53030; }
html[data-theme="dark"] .kn-btn-danger { background: #fc8181; color: #1a202c; border-color: #fc8181; }

/* Royal crowns / copy-link / data-tip — dark mode */
html[data-theme="dark"] [data-tip]::after { background: #1a202c; color: #f7fafc; box-shadow: 0 0 0 1px var(--ork-border); }
html[data-theme="dark"] [data-tip]::before { border-top-color: #1a202c; }
html[data-theme="dark"] .kn-royal-monarch { color: #f6ad55; }
html[data-theme="dark"] .kn-royal-regent  { color: #cbd5e0; }
html[data-theme="dark"] .kn-copy-link { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-copy-link:hover { color: #63b3ed; }
html[data-theme="dark"] .kn-copy-link.kn-copied::after { background: #1a202c; color: #f7fafc; box-shadow: 0 0 0 1px var(--ork-border); }

/* ============================================================
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

<!-- QR Code Modal -->
<div id="kn-qr-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9100" onclick="if(event.target===this)knCloseQrModal()">
	<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:12px;padding:28px 28px 20px;box-shadow:0 8px 32px rgba(0,0,0,0.22);max-width:320px;width:calc(100vw - 40px);text-align:center">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
			<span style="font-weight:700;font-size:15px;color:#2d3748"><i class="fas fa-qrcode" style="margin-right:8px;color:#2b6cb0"></i>Scan to Sign In</span>
			<button onclick="knCloseQrModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#a0aec0;line-height:1">&times;</button>
		</div>
		<img id="kn-qr-img" src="" alt="QR Code" style="width:220px;height:220px;border:1px solid #e2e8f0;border-radius:6px;display:block;margin:0 auto 14px">
		<div id="kn-qr-expires" style="font-size:11px;color:#718096;margin-bottom:14px"></div>
		<a id="kn-qr-download" href="" download="signin-qr.png" class="kn-btn kn-btn-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:13px">
			<i class="fas fa-download"></i> Download PNG
		</a>
	</div>
</div>

<?php endif; ?>

<script>
(function() {
	var kingdomId = <?= (int)($kingdom_id ?? 0) ?>;
	if (!kingdomId) return;

	// ---- Park averages + player counts (AJAX) ----
	fetch('<?= UIR ?>Kingdom/park_averages_json/' + kingdomId)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			var totalAtt = 0, totalTp = 0, totalTm = 0;
			var wkCount = (data._kingdom && data._kingdom.wk_count) ? data._kingdom.wk_count : 26;
			var kingdomAtt = (data._kingdom && data._kingdom.att) ? data._kingdom.att : null;
			function knCopyEventLink(el) {
				var url = el.getAttribute('data-url');
				navigator.clipboard.writeText(url).then(function() {
					el.classList.add('kn-copied');
					setTimeout(function() { el.classList.remove('kn-copied'); }, 1500);
				});
			}

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
				var tile = document.querySelector('.kn-park-tile[data-park-id="' + parkId + '"]');
				if (tile) {
					var wkEl = tile.querySelector('.kn-avgwk-tile');
					var moEl = tile.querySelector('.kn-avgmo-tile');
					if (wkEl) wkEl.innerHTML = (att / wkCount).toFixed(1) + knTrend(att / wkCount, prevAtt !== undefined ? prevAtt / wkCount : undefined, 1);
					if (moEl) moEl.innerHTML = mo.toFixed(1) + knTrend(mo, prevMo !== undefined ? prevMo : undefined, 1);
				}
				// List view row
				var row = document.querySelector('tr[data-park-id="' + parkId + '"]');
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
			var statWk = document.getElementById('kn-stat-avgwk');
			var statMo = document.getElementById('kn-stat-avgmo');
			if (statWk) statWk.textContent = (wkBase / wkCount).toFixed(1);
			if (statMo) statMo.textContent = moBase.toFixed(1);
			// Footer totals
			var footWk = document.getElementById('kn-total-avgwk');
			var footMo = document.getElementById('kn-total-avgmo');
			var footTp = document.getElementById('kn-total-tp');
			var footTm = document.getElementById('kn-total-tm');
			if (footWk) footWk.textContent = (wkBase / wkCount).toFixed(2);
			if (footMo) footMo.textContent = moBase.toFixed(1);
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
	return rowFilter === filter;
});
$(function() {
	if ($('#kn-rec-table').length) {
		window.knRecDT = $('#kn-rec-table').DataTable({
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
window.knRecPrint = function() { if (window.knRecDT) window.recsExportPrint(window.knRecDT, 'Award Recommendations \u2014 <?= htmlspecialchars(addslashes($kingdom_name)) ?>'); };
window.knRecCsv   = function() { if (window.knRecDT) window.recsExportCsv(window.knRecDT, 'recs-<?= preg_replace('/[^a-z0-9]+/i', '-', $kingdom_name) ?>.csv'); };
initEmailSpellCheck('kn-addplayer-email', 'kn-addplayer-email-suggestion');
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

<!-- kn-banner-modal (ported from event) -->
<div class="kn-img-overlay kn-banner-modal" id="kn-banner-overlay">
	<div class="kn-img-modal" style="width:min(680px, 96vw)">
		<div class="kn-img-modal-header">
			<span class="kn-img-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#2c5282"></i>Update Banner Image</span>
			<button class="kn-img-close-btn" id="kn-banner-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="kn-img-modal-body" id="kn-banner-step-select">
			<p style="margin:0 0 12px;font-size:13px;color:#4a5568;line-height:1.5">
				Banners are full-bleed across the event header. Recommended size <strong>1800 &times; 240&nbsp;px</strong> (7.5:1). The shaded zones below are reserved for the logo, title, badges, and crumb — keep important art on the right side so it isn't covered by overlays.
			</p>

			<div class="kn-banner-wireframes">
				<figure class="kn-banner-wireframe kn-banner-wf-desktop">
					<figcaption><i class="fas fa-desktop"></i> Desktop &middot; 1800 &times; 240 px</figcaption>
					<svg viewBox="0 0 600 80" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" aria-hidden="true" focusable="false">
						<rect x="0" y="0" width="600" height="80" fill="#cbd5e0"/>
						<rect x="0" y="0" width="360" height="80" fill="url(#wfLeftFade)" opacity="0.55"/>
						<rect x="0" y="58" width="600" height="22" fill="url(#wfBottomFade)" opacity="0.55"/>
						<rect x="20" y="14" width="52" height="52" rx="3" fill="#a0aec0" stroke="#fff" stroke-width="1.2"/>
						<rect x="84" y="22" width="170" height="10" rx="1.5" fill="#fff"/>
						<rect x="84" y="38" width="52" height="7" rx="1.5" fill="#fff" opacity="0.85"/>
						<rect x="142" y="38" width="46" height="7" rx="1.5" fill="#fff" opacity="0.85"/>
						<rect x="84" y="62" width="120" height="5" rx="1" fill="#fff" opacity="0.7"/>
						<text x="470" y="44" text-anchor="middle" font-size="10" fill="#2d3748" font-weight="700">Safe zone for art</text>
						<text x="596" y="11" text-anchor="end" font-size="7" fill="#2d3748" opacity="0.55">1800px wide</text>
						<text x="4"   y="78" text-anchor="start" font-size="7" fill="#2d3748" opacity="0.55">240px tall</text>
						<defs>
							<linearGradient id="wfLeftFade" x1="0" y1="0" x2="1" y2="0">
								<stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
							</linearGradient>
							<linearGradient id="wfBottomFade" x1="0" y1="1" x2="0" y2="0">
								<stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
							</linearGradient>
						</defs>
					</svg>
				</figure>

				<figure class="kn-banner-wireframe kn-banner-wf-mobile">
					<figcaption><i class="fas fa-mobile-alt"></i> Mobile &middot; middle ~32%</figcaption>
					<svg viewBox="0 0 600 80" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" aria-hidden="true" focusable="false">
						<!-- Saved banner (1800 × 240) drawn at 7.5:1 to match the desktop wireframe -->
						<rect x="0"   y="0" width="204" height="80" fill="#e2e8f0"/>
						<rect x="396" y="0" width="204" height="80" fill="#e2e8f0"/>
						<rect x="204" y="0" width="192" height="80" fill="#cbd5e0"/>
						<rect x="204" y="0" width="192" height="80" fill="url(#wfMobileFade)" opacity="0.40"/>
						<!-- Tiny logo + title inside the middle band -->
						<rect x="216" y="22" width="36" height="36" rx="3" fill="#a0aec0" stroke="#fff" stroke-width="1.2"/>
						<rect x="262" y="30" width="120" height="9" rx="1.5" fill="#fff"/>
						<rect x="262" y="46" width="80"  height="6" rx="1.5" fill="#fff" opacity="0.85"/>
						<!-- Cropped labels on each flank -->
						<text x="100" y="46" text-anchor="middle" font-size="10" fill="#718096" font-weight="600">cropped</text>
						<text x="498" y="46" text-anchor="middle" font-size="10" fill="#718096" font-weight="600">cropped</text>
						<!-- Mobile-safe band markers -->
						<line x1="204" y1="0" x2="204" y2="80" stroke="#4299e1" stroke-width="1.5" stroke-dasharray="4 3" opacity="0.65"/>
						<line x1="396" y1="0" x2="396" y2="80" stroke="#4299e1" stroke-width="1.5" stroke-dasharray="4 3" opacity="0.65"/>
						<text x="596" y="11" text-anchor="end" font-size="7" fill="#2d3748" opacity="0.55">1800px wide</text>
						<text x="4"   y="78" text-anchor="start" font-size="7" fill="#2d3748" opacity="0.55">240px tall</text>
						<defs>
							<linearGradient id="wfMobileFade" x1="0" y1="0" x2="0" y2="1">
								<stop offset="0" stop-color="#000" stop-opacity="0"/>
								<stop offset="1" stop-color="#000" stop-opacity="0.5"/>
							</linearGradient>
						</defs>
					</svg>
				</figure>
			</div>
			<p class="kn-banner-wf-hint">
				<i class="fas fa-info-circle"></i> On phones, the banner is cropped to the middle third — keep your subject centred so it survives.
			</p>

			<div class="kn-banner-config">
				<label class="kn-banner-toggle">
					<input type="checkbox" id="kn-banner-show-logo" checked>
					<span>Show Kingdom Heraldry on Left</span>
					<small>When off, the logo is hidden and the title/crumb shifts left.</small>
				</label>
				<label class="kn-banner-toggle">
					<input type="checkbox" id="kn-banner-vignette" checked>
					<span>Apply Vignette Effect</span>
					<small>Adds a soft radial blur and darkening only over the safe zones, so overlay text and pills stay legible.</small>
				</label>
			</div>

			<label class="kn-upload-area" for="kn-banner-file-input" style="margin-top:14px">
				<i class="fas fa-cloud-upload-alt kn-upload-icon"></i>
				Click to choose a banner image
				<small>JPG, PNG &middot; Max 1&nbsp;MB (larger images auto-resized)</small>
			</label>
			<input type="file" id="kn-banner-file-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png" style="display:none;" />
			<div id="kn-banner-resize-notice" style="font-size:12px;color:#888;min-height:16px;margin-top:6px;"></div>
			<div class="kn-img-form-error" id="kn-banner-error" style="display:none;"></div>

			<div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;gap:12px;flex-wrap:wrap">
				<?php if ($hasBanner): ?>
				<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
					<button class="kn-btn kn-btn-outline" id="kn-banner-adjust-btn" type="button" style="font-size:12px;padding:5px 14px"><i class="fas fa-arrows-alt"></i> Adjust Image Framing</button>
					<button class="kn-btn kn-btn-outline" id="kn-banner-save-config-btn" type="button" style="font-size:12px;padding:5px 14px"><i class="fas fa-save"></i> Save settings only</button>
				</div>
				<button class="kn-btn kn-btn-outline" id="kn-banner-remove-btn" type="button" style="font-size:12px;padding:5px 14px;border-color:#feb2b2;color:#e53e3e;"><i class="fas fa-trash"></i> Remove Banner</button>
				<?php else: ?>
				<span class="ec-field-hint">Upload a banner first to unlock the display toggles.</span>
				<?php endif; ?>
			</div>
		</div>

		<div class="kn-img-modal-body" id="kn-banner-step-position" style="display:none;">
			<p style="margin:0 0 10px;font-size:13px;color:#4a5568;line-height:1.5">
				Drag your image to set what shows through. The translucent shapes on top are where the logo, title, badges, and crumb will land — anything behind them will be partly covered.
			</p>
			<div class="kn-banner-position-wrap">
				<canvas id="kn-banner-position-canvas" class="kn-banner-position-canvas" width="1800" height="240"></canvas>
				<svg class="kn-banner-position-overlay" viewBox="0 0 1800 240" preserveAspectRatio="none" aria-hidden="true" focusable="false">
					<!-- Faint vignette tint for safe zones (matches the real .kn-hero-vignette) -->
					<rect x="0" y="0" width="900" height="240" fill="url(#posLeftFade)" opacity="0.40"/>
					<rect x="0" y="150" width="1800" height="90" fill="url(#posBottomFade)" opacity="0.35"/>
					<!-- Logo placeholder (~110px tall in real layout, vertically centered) -->
					<rect x="45" y="65" width="110" height="110" rx="8" fill="rgba(255,255,255,0.35)" stroke="#fff" stroke-width="2.5"/>
					<text x="100" y="128" text-anchor="middle" font-size="16" fill="#fff" font-weight="700" opacity="0.85">LOGO</text>
					<!-- Title bar -->
					<rect x="180" y="78" width="520" height="28" rx="3" fill="rgba(255,255,255,0.45)"/>
					<text x="190" y="99" font-size="20" font-weight="700" fill="#1a202c" opacity="0.78">Event Title goes here</text>
					<!-- Badges row -->
					<rect x="180" y="118" width="100" height="20" rx="10" fill="rgba(72,187,120,0.55)"/>
					<rect x="290" y="118" width="115" height="20" rx="10" fill="rgba(66,153,225,0.55)"/>
					<rect x="415" y="118" width="90"  height="20" rx="10" fill="rgba(159,122,234,0.55)"/>
					<!-- Crumb -->
					<rect x="180" y="150" width="260" height="12" rx="2" fill="rgba(255,255,255,0.40)"/>
					<!-- Mobile-safe band markers: middle ~32% of width -->
					<line x1="612"  y1="0" x2="612"  y2="240" stroke="#fff" stroke-width="2" stroke-dasharray="8 6" opacity="0.55"/>
					<line x1="1188" y1="0" x2="1188" y2="240" stroke="#fff" stroke-width="2" stroke-dasharray="8 6" opacity="0.55"/>
					<text x="900" y="16" text-anchor="middle" font-size="12" fill="#fff" font-weight="600" opacity="0.75">mobile shows this band</text>
					<defs>
						<linearGradient id="posLeftFade" x1="0" y1="0" x2="1" y2="0">
							<stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
						</linearGradient>
						<linearGradient id="posBottomFade" x1="0" y1="1" x2="0" y2="0">
							<stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
						</linearGradient>
					</defs>
				</svg>
			</div>
			<p class="kn-banner-position-hint">
				<i class="fas fa-arrows-alt"></i>
				<span id="kn-banner-position-hint-text">Click and drag to position the image.</span>
			</p>
			<div class="kn-img-form-error" id="kn-banner-position-error" style="display:none;"></div>
			<div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;gap:12px">
				<button class="kn-btn kn-btn-outline" id="kn-banner-position-back-btn" type="button" style="font-size:12px;padding:5px 14px"><i class="fas fa-arrow-left"></i> Back</button>
				<button class="kn-btn kn-btn-white" id="kn-banner-position-confirm-btn" type="button" style="font-size:13px;padding:7px 18px">Use This View <i class="fas fa-check"></i></button>
			</div>
		</div>

		<div class="kn-img-modal-body" id="kn-banner-step-uploading" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:#4299e1;"></i>
			<p style="margin-top:12px;color:#4a5568;">Uploading…</p>
		</div>
		<div class="kn-img-modal-body" id="kn-banner-step-success" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78;"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600;">Updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>

