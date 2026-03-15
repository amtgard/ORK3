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
	$heraldryHtml = $details['HasHeraldry']
		? '<img src="' . HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf('%05d', $details['ParkId'])) . '" style="max-width:60px;display:block;margin-bottom:6px">'
		: '';
	$dirText  = htmlspecialchars(str_replace(['<br />', '<br/>', '<br>'], '', $details['Directions'] ?? ''));
	$descText = htmlspecialchars(str_replace(['<br />', '<br/>', '<br>'], '', $details['Description'] ?? ''));
	$atParks[] = [
		'name'  => ucwords($details['Name']),
		'lat'   => (float)$latlng->lat,
		'lng'   => (float)$latlng->lng,
		'id'    => (int)$details['ParkId'],
		'kid'   => (int)$details['KingdomId'],
		'color' => ltrim($details['KingdomColor'] ?? '718096', '#'),
		'info'  => $heraldryHtml
		         . ($dirText  ? '<p>' . nl2br($dirText)  . '</p>' : '')
		         . ($descText ? '<h4 style="margin:8px 0 4px">Description</h4><p>' . nl2br($descText) . '</p>' : ''),
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
	border-radius:8px; border:1px solid #e2e8f0;
}
.at-directions-wrap {
	width:260px; flex-shrink:0;
}
.at-directions-card {
	background:#fff; border:1px solid #e2e8f0; border-radius:8px;
	padding:14px; height:72vh; min-height:400px;
	overflow-y:auto; box-sizing:border-box;
}
.at-directions-title {
	font-size:14px; font-weight:700; color:#2d3748;
	margin:0 0 10px; padding-bottom:8px;
	border-bottom:1px solid #e2e8f0;
}
.at-directions-title i { color:#3182ce; margin-right:5px; }
#at-directions-content {
	font-size:13px; color:#4a5568; line-height:1.55;
}
#at-directions-content h4 {
	font-size:12px; font-weight:700; color:#2d3748;
	margin:10px 0 4px;
	background:transparent; border:none; padding:0; border-radius:0;
	text-shadow:none;
}
#at-directions-content p { margin:0 0 6px; }
#at-directions-content img { border-radius:4px; }

/* ── Empty ── */
.at-empty {
	text-align:center; color:#a0aec0; font-style:italic;
	padding:40px 0; font-size:14px;
}

/* ── Responsive ── */
@media (max-width:768px) {
	.at-map-layout { flex-direction:column; }
	.at-directions-wrap { width:100%; }
	.at-directions-card { height:auto; min-height:120px; }
	#at-map { height:55vh; }
	.at-controls { flex-wrap:wrap; gap:10px; }
}
</style>

<div class="at-page">

	<!-- Header -->
	<div class="at-header">
		<div class="at-header-icon"><i class="fas fa-map-marked-alt"></i></div>
		<div>
			<h1 class="at-header-title">Amtgard Atlas</h1>
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
			<span class="at-stat-label">Kingdoms</span>
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

	<!-- Map + Directions -->
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
				</div>
				<div class="at-directions-wrap">
					<div class="at-directions-card">
						<div class="at-directions-title" id="at-directions-title">
							<i class="fas fa-map-marker-alt"></i> Park Info
						</div>
						<div id="at-directions-content">
							<p style="color:#a0aec0;font-style:italic">Click a park pin for details.</p>
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
		zoom: 4,
		mapId: 'ORK3_MAP_ID'
	});

	// Fit bounds to all park locations
	if (atMapLocations.length > 0) {
		var bounds = new google.maps.LatLngBounds();
		atMapLocations.forEach(function(p) {
			bounds.extend(new google.maps.LatLng(p.lat, p.lng));
		});
		atMapObj.fitBounds(bounds);
		// Pull back one zoom level so pins aren't clipped
		google.maps.event.addListenerOnce(atMapObj, 'idle', function() {
			atMapObj.setZoom(atMapObj.getZoom() - 1);
		});
	}

	var infowindow = new google.maps.InfoWindow();

	atMapLocations.forEach(function(loc) {
		var color = loc.color ? '#' + loc.color : '#718096';
		var pinGlyph = new PinElement({ background: color, scale: 0.7 });
		var marker = new google.maps.marker.AdvancedMarkerElement({
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
			infowindow.setContent(
				"<b><a href='?Route=Park/profile/" + loc.id + "'>" + loc.name + "</a></b>" +
				"<div style='margin-top:8px;max-width:260px;font-size:12px'>" + loc.info + "</div>"
			);
			infowindow.open(atMapObj, marker);
			document.getElementById('at-directions-title').innerHTML =
				'<i class="fas fa-map-marker-alt"></i> ' + loc.name;
			document.getElementById('at-directions-content').innerHTML = loc.info ||
				'<p style="color:#a0aec0;font-style:italic">No details available.</p>';
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
