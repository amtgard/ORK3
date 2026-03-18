<?php
// ── Pre-compute park location data server-side ──────────────────────────────
$atParks        = [];
$atKingdomIds   = [];
foreach ((array)($Parks['Parks'] ?? []) as $details) {
	$loc = @json_decode(stripslashes((string)($details['Location'] ?? '')));
	if (!$loc) continue;
	$latlng = isset($loc->location) ? $loc->location
	        : (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
	if (!$latlng || !is_numeric($latlng->lat) || !is_numeric($latlng->lng)) continue;
	if ((int)($details['KingdomId'] ?? 0) <= 0 || empty($details['KingdomName'])) continue;
	$_isTopKingdom = ((int)($details['ParentKingdomId'] ?? 0) === 0);
	$dirText  = htmlspecialchars(str_replace(['<br />', '<br/>', '<br>'], '', $details['Directions'] ?? ''));
	$descText = htmlspecialchars(str_replace(['<br />', '<br/>', '<br>'], '', $details['Description'] ?? ''));
	$city     = htmlspecialchars(trim($details['City'] ?? ''));
	$province = htmlspecialchars(trim($details['Province'] ?? ''));
	$atParks[] = [
		'name'     => ucwords($details['Name']),
		'lat'      => (float)$latlng->lat,
		'lng'      => (float)$latlng->lng,
		'id'       => (int)$details['ParkId'],
		'kid'      => (int)$details['KingdomId'],
		'color'    => ltrim($details['KingdomColor'] ?? '718096', '#'),
		'kname'    => htmlspecialchars($details['KingdomName'] ?? ''),
		'city'     => $city,
		'province' => $province,
		'heraldry' => $details['HasHeraldry']
			? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf('%05d', $details['ParkId']))
			: '',
		'dir'      => $dirText,
		'desc'     => $descText,
	];
	$atKingdomIds[(int)$details['KingdomId']] = true;
}
$atParkCount    = count($atParks);
$atKingdomCount = count($atKingdomIds);
?>

<style>
/* ── Atlas page ── all classes prefixed at- ─────────────────────────────── */

.at-page { display:flex; flex-direction:column; gap:12px; }

/* ── Header ── */
.at-header {
	display:flex; align-items:center; gap:12px;
	padding: 14px 0 6px;
}
.at-header-icon {
	width:42px; height:42px; border-radius:50%;
	background:#2b6cb0; color:#fff;
	display:flex; align-items:center; justify-content:center;
	font-size:18px; flex-shrink:0;
}
.at-header-title {
	font-size:22px; font-weight:700; color:#2d3748; margin:0;
	background:transparent; border:none; padding:0; border-radius:0;
	text-shadow:none;
}
.at-header-sub { font-size:13px; color:#718096; margin-top:1px; }

/* ── Stats bar ── */
.at-stats-bar {
	display:flex; gap:0;
	background:#fff; border:1px solid #e2e8f0; border-radius:8px;
	overflow:hidden; margin-bottom:4px;
}
.at-stat {
	flex:1; display:flex; flex-direction:column;
	align-items:center; justify-content:center;
	padding:10px 12px; gap:2px;
	border-right:1px solid #e2e8f0;
}
.at-stat:last-child { border-right:none; }
.at-stat-value { font-size:22px; font-weight:700; color:#2d3748; line-height:1; }
.at-stat-label { font-size:11px; color:#718096; text-transform:uppercase; letter-spacing:.06em; }

/* ── Controls bar ── */
.at-controls {
	display:flex; align-items:center; gap:16px;
	background:#fff; border:1px solid #e2e8f0; border-radius:8px;
	padding:10px 16px;
}
.at-toggle-wrap {
	display:flex; align-items:center; gap:8px;
	cursor:pointer; user-select:none;
}
.at-toggle-input { display:none; }
.at-toggle-track {
	width:36px; height:20px; border-radius:10px;
	background:#cbd5e0; position:relative;
	transition:background .2s; flex-shrink:0;
}
.at-toggle-thumb {
	position:absolute; top:3px; left:3px;
	width:14px; height:14px; border-radius:50%;
	background:#fff; transition:left .2s;
	box-shadow:0 1px 3px rgba(0,0,0,.2);
}
.at-toggle-input:checked + .at-toggle-track { background:#3182ce; }
.at-toggle-input:checked + .at-toggle-track .at-toggle-thumb { left:19px; }
.at-toggle-label { font-size:13px; color:#4a5568; font-weight:500; }

/* ── Map layout ── */
.at-map-outer {
	display:flex; flex-direction:column; gap:10px;
}
.at-map-loading {
	display:flex; align-items:center; justify-content:center;
	gap:10px; padding:60px 0; color:#718096; font-size:14px;
}
.at-map-layout {
	display:flex; gap:14px; align-items:flex-start;
}
.at-map-wrap {
	flex:1 1 0; min-width:0;
}
#at-map {
	width:100%; height:72vh; min-height:400px;
	border-radius:4px; border:3px solid #8B6914;
	box-shadow:0 2px 8px rgba(100,60,0,0.25);
	filter:sepia(0.35) saturate(0.85);
}
.at-sidebar-wrap {
	width:270px; flex-shrink:0;
}
.at-sidebar-card {
	background:#fff; border:1px solid #e2e8f0; border-radius:8px;
	height:72vh; min-height:400px;
	overflow-y:auto; box-sizing:border-box;
	display:flex; flex-direction:column;
}

/* ── Sidebar: empty state ── */
.at-sidebar-empty {
	flex:1; display:flex; flex-direction:column;
	align-items:center; justify-content:center;
	gap:10px; padding:30px 20px; text-align:center;
}
.at-sidebar-empty-icon {
	width:52px; height:52px; border-radius:50%;
	background:#ebf4ff; color:#3182ce;
	display:flex; align-items:center; justify-content:center;
	font-size:20px;
}
.at-sidebar-empty p {
	font-size:13px; color:#a0aec0; font-style:italic; margin:0;
}

/* ── Sidebar: park hero ── */
.at-park-hero {
	border-radius:8px 8px 0 0;
	padding:24px 16px 16px;
	display:flex; flex-direction:column; align-items:center;
	gap:10px; text-align:center; flex-shrink:0;
}
.at-park-heraldry {
	width:84px; height:84px; border-radius:8px;
	object-fit:contain;
	background:#fff; border:2px solid rgba(255,255,255,.5);
	box-shadow:0 2px 8px rgba(0,0,0,.18);
}
.at-park-heraldry-placeholder {
	width:84px; height:84px; border-radius:8px;
	background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.4);
	display:flex; align-items:center; justify-content:center;
	font-size:28px; color:rgba(255,255,255,.7);
}
.at-park-hero-name {
	font-size:16px; font-weight:700; color:#fff; margin:0;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:0 1px 3px rgba(0,0,0,.3);
	line-height:1.3;
}
.at-park-hero-location {
	font-size:12px; color:rgba(255,255,255,.85);
	display:flex; align-items:center; gap:4px; margin:0;
}
.at-park-kingdom-badge {
	display:inline-flex; align-items:center; gap:5px;
	background:rgba(255,255,255,.2); color:#fff;
	border:1px solid rgba(255,255,255,.35);
	font-size:11px; font-weight:600; padding:3px 9px;
	border-radius:20px; margin-top:2px;
}

/* ── Sidebar: park body ── */
.at-park-body {
	padding:14px 16px; flex:1; display:flex; flex-direction:column; gap:12px;
}
.at-park-section {}
.at-park-section-label {
	font-size:10px; font-weight:700; text-transform:uppercase;
	letter-spacing:.08em; color:#a0aec0; margin-bottom:4px;
}
.at-park-section-text {
	font-size:13px; color:#4a5568; line-height:1.55; margin:0;
}
.at-park-divider {
	border:none; border-top:1px solid #e2e8f0; margin:0;
}
.at-park-profile-btn {
	display:block; text-align:center;
	background:#3182ce; color:#fff; font-size:13px; font-weight:600;
	padding:9px 16px; border-radius:6px; text-decoration:none;
	transition:background .15s; margin-top:auto;
}
.at-park-profile-btn:hover { background:#2b6cb0; color:#fff; text-decoration:none; }
.at-park-profile-btn i { margin-right:6px; }

/* ── Empty ── */
.at-empty {
	text-align:center; color:#a0aec0; font-style:italic;
	padding:40px 0; font-size:14px;
}

/* ── Mobile map hint ── */
.at-map-hint {
	display:none;
}
@media (max-width:768px) {
	.at-map-hint {
		display:flex; align-items:center; gap:8px;
		background:#ebf4ff; border:1px solid #bee3f8; border-radius:6px;
		padding:8px 12px; font-size:12px; color:#2b6cb0;
	}
	.at-map-hint i { font-size:14px; flex-shrink:0; }
}

/* ── Responsive ── */
@media (max-width:768px) {
	.at-map-layout { flex-direction:column; width:100%; }
	.at-map-wrap { width:100%; min-width:0; }
	.at-sidebar-wrap { width:100%; }
	.at-sidebar-card { height:auto; min-height:120px; }
	#at-map { width:100%; height:55vw; min-height:260px; max-height:60vh; }
	.at-controls { flex-wrap:wrap; gap:10px; }
	.at-page { overflow-x:hidden; }
}
</style>

<div class="at-page">

	<!-- Header -->
	<div class="at-header">
		<div class="at-header-icon"><i class="fas fa-map-marked-alt"></i></div>
		<div>
			<h1 class="at-header-title">Amtgard Map</h1>
			<div class="at-header-sub">All active park locations across the Amtgard world</div>
		</div>
	</div>

	<!-- Stats -->
	<div class="at-stats-bar">
		<div class="at-stat">
			<span class="at-stat-value"><?= number_format($atParkCount) ?></span>
			<span class="at-stat-label">Parks</span>
		</div>
		<div class="at-stat">
			<span class="at-stat-value"><?= number_format($atKingdomCount) ?></span>
			<span class="at-stat-label">Kingdoms &amp; Principalities</span>
		</div>
	</div>

	<!-- Controls -->
	<div class="at-controls">
		<label class="at-toggle-wrap" for="at-radius-toggle">
			<input type="checkbox" id="at-radius-toggle" class="at-toggle-input">
			<span class="at-toggle-track"><span class="at-toggle-thumb"></span></span>
			<span class="at-toggle-label"><i class="fas fa-circle-notch" style="margin-right:4px;color:#3182ce"></i>Show 25-Mile Park Radius</span>
		</label>
	</div>

	<!-- Map + Sidebar -->
	<div class="at-map-outer">
		<?php if ($atParkCount > 0): ?>
		<div id="at-map-loading" class="at-map-loading">
			<i class="fas fa-spinner fa-spin" style="font-size:20px"></i>
			Loading map&hellip;
		</div>
		<div id="at-map-container" style="display:none">
			<div class="at-map-layout">
				<div class="at-map-wrap">
					<div id="at-map"></div>
					<div class="at-map-hint">
						<i class="fas fa-hand-point-up"></i>
						Pinch to zoom in and out.
					</div>
				</div>
				<div class="at-sidebar-wrap">
					<div class="at-sidebar-card" id="at-sidebar-card">
						<div class="at-sidebar-empty" id="at-sidebar-empty">
							<div class="at-sidebar-empty-icon"><i class="fas fa-map-marker-alt"></i></div>
							<p>Click any park pin on the map to see details here.</p>
						</div>
						<div id="at-sidebar-park" style="display:none; flex-direction:column; flex:1;">
							<div class="at-park-hero" id="at-park-hero"></div>
							<div class="at-park-body" id="at-park-body"></div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php else: ?>
		<div class="at-empty">No park location data available.</div>
		<?php endif; ?>
	</div>

</div><!-- /.at-page -->

<script>
var atMapLocations = <?= json_encode(array_values($atParks), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var atAllCircles   = [];
var atMapObj       = null;

document.getElementById('at-radius-toggle').addEventListener('change', function() {
	var target = this.checked ? atMapObj : null;
	atAllCircles.forEach(function(c) { c.setMap(target); });
});

function atColorIsLight(hex) {
	// Returns true if the hex color (without #) is perceptually light
	var r = parseInt(hex.substring(0, 2), 16);
	var g = parseInt(hex.substring(2, 4), 16);
	var b = parseInt(hex.substring(4, 6), 16);
	// Relative luminance (WCAG formula)
	var luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
	return luminance > 0.55;
}

function atRenderSidebar(loc) {
	var hex     = (loc.color && loc.color.length === 6) ? loc.color : '718096';
	var color   = '#' + hex;
	var isLight = atColorIsLight(hex);
	var textColor      = isLight ? '#1a202c' : '#ffffff';
	var textColorMuted = isLight ? 'rgba(0,0,0,0.6)' : 'rgba(255,255,255,0.85)';
	var badgeBg        = isLight ? 'rgba(0,0,0,0.12)' : 'rgba(255,255,255,0.2)';
	var badgeBorder    = isLight ? 'rgba(0,0,0,0.2)'  : 'rgba(255,255,255,0.35)';
	var placeholderColor = isLight ? 'rgba(0,0,0,0.4)' : 'rgba(255,255,255,0.7)';
	var locLine = [loc.city, loc.province].filter(Boolean).join(', ');

	// Hero
	var heraldryHtml = loc.heraldry
		? '<img src="' + loc.heraldry + '" class="at-park-heraldry" alt="' + loc.name + ' heraldry">'
		: '<div class="at-park-heraldry-placeholder" style="color:' + placeholderColor + '"><i class="fas fa-shield-alt"></i></div>';
	var heroHtml = heraldryHtml
		+ '<div class="at-park-hero-name" style="color:' + textColor + ';text-shadow:' + (isLight ? 'none' : '0 1px 3px rgba(0,0,0,.3)') + '">' + loc.name + '</div>'
		+ (locLine ? '<div class="at-park-hero-location" style="color:' + textColorMuted + '"><i class="fas fa-map-marker-alt" style="font-size:10px"></i>' + locLine + '</div>' : '')
		+ (loc.kname ? '<div class="at-park-kingdom-badge" style="background:' + badgeBg + ';border-color:' + badgeBorder + ';color:' + textColor + '"><i class="fas fa-crown" style="font-size:9px"></i>' + loc.kname + '</div>' : '');

	// Body sections
	var bodyHtml = '';
	if (loc.dir) {
		bodyHtml += '<div class="at-park-section">'
			+ '<div class="at-park-section-label"><i class="fas fa-directions" style="margin-right:4px"></i>Directions</div>'
			+ '<p class="at-park-section-text">' + loc.dir.replace(/\n/g, '<br>') + '</p>'
			+ '</div>';
	}
	if (loc.desc) {
		if (bodyHtml) bodyHtml += '<hr class="at-park-divider">';
		bodyHtml += '<div class="at-park-section">'
			+ '<div class="at-park-section-label"><i class="fas fa-info-circle" style="margin-right:4px"></i>About</div>'
			+ '<p class="at-park-section-text">' + loc.desc.replace(/\n/g, '<br>') + '</p>'
			+ '</div>';
	}
	bodyHtml += '<a href="?Route=Park/profile/' + loc.id + '" class="at-park-profile-btn">'
		+ '<i class="fas fa-external-link-alt"></i>View Park Profile</a>';

	var heroEl = document.getElementById('at-park-hero');
	heroEl.innerHTML = heroHtml;
	heroEl.style.background = 'linear-gradient(135deg, #' + hex + 'dd, #' + hex + '99)';
	document.getElementById('at-park-body').innerHTML = bodyHtml;

	document.getElementById('at-sidebar-empty').style.display = 'none';
	var parkEl = document.getElementById('at-sidebar-park');
	parkEl.style.display = 'flex';
}

window.atInitMap = async function() {
	var loadingEl   = document.getElementById('at-map-loading');
	var containerEl = document.getElementById('at-map-container');
	if (!loadingEl || !containerEl) return;
	loadingEl.style.display   = 'none';
	containerEl.style.display = 'block';

	const { Map } = await google.maps.importLibrary("maps");
	const { AdvancedMarkerElement, PinElement } = await google.maps.importLibrary("marker");

	atMapObj = new google.maps.Map(document.getElementById('at-map'), {
		center: { lat: 39, lng: -98 },
		zoom: 3,
		mapId: 'ORK3_MAP_ID'
	});

	// Trigger resize after container becomes visible (fixes mobile blank map)
	google.maps.event.trigger(atMapObj, 'resize');
	atMapObj.setCenter({ lat: 39, lng: -98 });

	var infowindow = new google.maps.InfoWindow();

	atMapLocations.forEach(function(loc) {
		var color    = loc.color ? '#' + loc.color : '#718096';
		var locLine  = [loc.city, loc.province].filter(Boolean).join(', ');
		var pinGlyph = new PinElement({ background: color, borderColor: '#B8860B', glyphColor: '#FFD700', scale: 0.7 });
		var marker   = new google.maps.marker.AdvancedMarkerElement({
			position: new google.maps.LatLng(loc.lat, loc.lng),
			map: atMapObj,
			title: loc.name,
			content: pinGlyph.element
		});

		var circle = new google.maps.Circle({
			fillOpacity: 0,
			strokeColor: color,
			strokeOpacity: 0.7,
			strokeWeight: 1,
			center: marker.position,
			radius: 40233.6   // 25 miles in metres
		});
		atAllCircles.push(circle);

		google.maps.event.addListener(marker, 'click', function() {
			// Tooltip: name + city/state only
			var tipHtml = '<b><a href="?Route=Park/profile/' + loc.id + '" style="color:#2b6cb0">' + loc.name + '</a></b>'
				+ (locLine ? '<div style="font-size:12px;color:#718096;margin-top:3px">'
					+ '<i class="fas fa-map-marker-alt" style="font-size:10px;margin-right:3px"></i>' + locLine + '</div>' : '');
			infowindow.setContent(tipHtml);
			infowindow.open(atMapObj, marker);
			atRenderSidebar(loc);
		});
	});
};

// Lazy-load Google Maps on DOMContentLoaded
(function() {
	var s = document.createElement('script');
	s.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyB_hIughnMCuRdutIvw_M_uwQUCREhHuI8&callback=atInitMap&v=weekly&libraries=marker';
	s.async = true;
	document.head.appendChild(s);
})();
</script>
