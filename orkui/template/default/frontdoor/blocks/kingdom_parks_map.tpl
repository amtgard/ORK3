<?php
/**
 * Partial: kingdom_parks_map.tpl — DYNAMIC block (org-scoped).
 *
 * An interactive Google map of the kingdom's ACTIVE parks with a click-to-load
 * sidebar (heraldry + name + city/state + directions + description + profile
 * link) — the Amtgard Atlas map/sidebar pattern, scoped to one kingdom.
 *
 * Self-sourcing: reads park locations via new APIModel('Map')->GetParkLocations
 * (['KingdomId' => $kid]) — the same source the Atlas page uses. Park geo comes
 * from each park's Location JSON blob; parks without coordinates are skipped.
 * Directions/Description run through Parsedown safe-mode (mirrors the Atlas), so
 * authored HTML can't inject script. name/city/province are htmlspecialchars'd;
 * the whole locations array is json_encode'd with JSON_HEX_TAG|JSON_HEX_AMP for
 * safe <script> embedding.
 *
 * Scope: derives kingdom_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId). Renders NOTHING outside a kingdom scope, and nothing when no
 * park has usable coordinates — never a broken/empty map box.
 *
 * Instance-safe: a unique id per block + a one-time Maps loader with a callback
 * queue, so more than one map block on a page won't clash or double-load the API.
 *
 * Receives: $blockFields { kicker?, heading? }, UIR, $SiteNavScope*.
 */
$kpmScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$kpmScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$kpmKingdomId = ($kpmScopeType === 'kingdom') ? $kpmScopeId : 0;
if ($kpmKingdomId <= 0) {
    return;
}

$kpmKicker  = isset($blockFields['kicker']) ? trim((string) $blockFields['kicker']) : '';
$kpmHeading = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'Park Map';

// Markdown helper (safe mode) — identical treatment to the Atlas page.
if (!function_exists('kpm_map_markdown') && is_file(DIR_LIB . 'Parsedown.php')) {
    require_once DIR_LIB . 'Parsedown.php';
    function kpm_map_markdown($text)
    {
        $clean = str_replace(['<br />', '<br/>', '<br>'], "\n", (string) $text);
        $html  = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($clean);
        return preg_replace('/<img[^>]*>/i', '', $html);
    }
}

$kpmParks = [];
if (class_exists('APIModel')) {
    try {
        $kpmModel  = new APIModel('Map');
        $kpmResult = $kpmModel->GetParkLocations(['KingdomId' => $kpmKingdomId]);
        foreach ((array) ($kpmResult['Parks'] ?? []) as $details) {
            $loc = @json_decode(stripslashes((string) ($details['Location'] ?? '')));
            if (!$loc) {
                continue;
            }
            $latlng = isset($loc->location) ? $loc->location
                : (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
            if (!$latlng || !isset($latlng->lat, $latlng->lng)
                || !is_numeric($latlng->lat) || !is_numeric($latlng->lng)) {
                continue;
            }
            $parkId   = (int) ($details['ParkId'] ?? 0);
            if ($parkId <= 0) {
                continue;
            }
            $heraldry = '';
            if (!empty($details['HasHeraldry']) && defined('HTTP_PARK_HERALDRY')
                && defined('DIR_PARK_HERALDRY') && class_exists('Common')) {
                $file = Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf('%05d', $parkId));
                $heraldry = $file !== '' ? HTTP_PARK_HERALDRY . $file : '';
            }
            $kpmParks[] = [
                'name'     => htmlspecialchars(ucwords((string) ($details['Name'] ?? '')), ENT_QUOTES),
                'lat'      => (float) $latlng->lat,
                'lng'      => (float) $latlng->lng,
                'id'       => $parkId,
                'color'    => ltrim((string) ($details['KingdomColor'] ?? '718096'), '#'),
                'city'     => htmlspecialchars(trim((string) ($details['City'] ?? ''))),
                'province' => htmlspecialchars(trim((string) ($details['Province'] ?? ''))),
                'heraldry' => $heraldry,
                'dir'      => function_exists('kpm_map_markdown') ? kpm_map_markdown($details['Directions'] ?? '') : '',
                'desc'     => function_exists('kpm_map_markdown') ? kpm_map_markdown($details['Description'] ?? '') : '',
            ];
        }
    } catch (\Throwable $e) {
        $kpmParks = [];
    }
}

// Nothing to plot → render nothing (the parks LIST block still stands on its own).
if (empty($kpmParks)) {
    return;
}

$kpmId  = 'kpm-' . substr(md5(uniqid('', true)), 0, 10);
// Per-env Maps key only — NO hardcoded fallback. Shipping a literal key in public
// page source exposes a shared credential to quota abuse across every kingdom site.
// When the key is missing/empty we render a graceful "map unavailable" fallback
// rather than injecting a broken Maps <script> that 403s in the browser.
$kpmKey = (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== '')
    ? (string) GOOGLE_MAPS_API_KEY
    : '';
?>
<style>
.kpm-block { background: #fff; }
html[data-theme="dark"] .kpm-block { background: transparent; }
.kpm-head { margin-bottom: 16px; }
.kpm-title { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0; font-size: 24px; }
.kpm-layout { display: grid; grid-template-columns: 1.9fr 1fr; gap: 16px; align-items: stretch; }
.kpm-map { width: 100%; height: 62vh; min-height: 380px; border-radius: 12px; overflow: hidden; border: 1px solid #e4e8f0; }
.kpm-loading { display: flex; align-items: center; gap: 10px; justify-content: center; height: 62vh; min-height: 380px; color: #718096; border: 1px dashed #cbd5e0; border-radius: 12px; }
.kpm-unavailable { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; text-align: center; height: 62vh; min-height: 380px; color: #8a97ad; border: 1px dashed #cbd5e0; border-radius: 12px; padding: 24px; }
.kpm-unavailable-icon { font-size: 30px; color: #c3ccdb; }
html[data-theme="dark"] .kpm-unavailable { border-color: #2c3650; }
.kpm-sidebar { border: 1px solid #e4e8f0; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; min-height: 380px; background: #fff; }
.kpm-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; text-align: center; color: #8a97ad; padding: 28px; flex: 1; }
.kpm-empty-icon { font-size: 30px; color: #c3ccdb; }
.kpm-park { display: none; flex-direction: column; flex: 1; }
.kpm-hero { padding: 20px 18px; color: #fff; }
.kpm-crest { width: 62px; height: 62px; border-radius: 10px; background: rgba(255,255,255,.9); display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 10px; }
.kpm-crest img { width: 100%; height: 100%; object-fit: contain; }
.kpm-crest-ph { font-size: 26px; }
.kpm-hero-name { font-size: 20px; font-weight: 700; line-height: 1.15; }
.kpm-hero-loc { font-size: 13px; margin-top: 4px; display: flex; align-items: center; gap: 5px; }
.kpm-body { padding: 16px 18px; overflow-y: auto; }
.kpm-section-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #7a8699; margin-bottom: 4px; }
.kpm-section-text { font-size: 14px; line-height: 1.5; color: #37414f; margin-bottom: 14px; }
.kpm-section-text a { color: #1d4ed8; }
.kpm-profile-btn { display: inline-flex; align-items: center; gap: 7px; background: #1b3a6b; color: #fff; text-decoration: none; font-weight: 600; font-size: 14px; padding: 9px 16px; border-radius: 8px; }
.kpm-profile-btn:hover { background: #16305a; color: #fff; }
.kpm-hint { font-size: 12px; color: #8a97ad; margin-top: 7px; }
@media (max-width: 820px) { .kpm-layout { grid-template-columns: 1fr; } .kpm-map, .kpm-loading { height: 46vh; min-height: 300px; } .kpm-sidebar { min-height: 0; } }
html[data-theme="dark"] .kpm-map, html[data-theme="dark"] .kpm-sidebar { border-color: #2c3650; }
html[data-theme="dark"] .kpm-sidebar { background: #1b2233; }
html[data-theme="dark"] .kpm-crest { background: rgba(255,255,255,.92); }
html[data-theme="dark"] .kpm-section-text { color: #cad3e2; }
html[data-theme="dark"] .kpm-section-text a { color: #7ba7f2; }
html[data-theme="dark"] .kpm-title { color: #eef2fa; }
</style>
<div class="fd-pad fd-section-light kpm-block" id="<?= $kpmId ?>">
    <?php if ($kpmKicker !== '' || $kpmHeading !== ''): ?>
        <div class="kpm-head">
            <?php if ($kpmKicker !== ''): ?>
                <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($kpmKicker, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($kpmHeading !== ''): ?>
                <h3 class="kpm-title fd-sec-title"><?= htmlspecialchars($kpmHeading, ENT_QUOTES) ?></h3>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="kpm-layout">
        <div>
            <?php if ($kpmKey === ''): ?>
                <div class="kpm-unavailable">
                    <div class="kpm-unavailable-icon"><i class="fas fa-map-marked-alt"></i></div>
                    <p>The interactive map is temporarily unavailable.</p>
                </div>
            <?php else: ?>
                <div class="kpm-loading" data-kpm-loading><i class="fas fa-spinner fa-spin"></i> Loading map&hellip;</div>
                <div class="kpm-map" data-kpm-map style="display:none;"></div>
                <div class="kpm-hint"><i class="fas fa-hand-point-up"></i> Click a park pin for details.</div>
            <?php endif; ?>
        </div>
        <div class="kpm-sidebar">
            <div class="kpm-empty" data-kpm-empty>
                <div class="kpm-empty-icon"><i class="fas fa-map-marker-alt"></i></div>
                <?php if ($kpmKey === ''): ?>
                    <p>Park details are temporarily unavailable.</p>
                <?php else: ?>
                    <p>Click any park pin on the map to see its details here.</p>
                <?php endif; ?>
            </div>
            <div class="kpm-park" data-kpm-park>
                <div class="kpm-hero" data-kpm-hero></div>
                <div class="kpm-body" data-kpm-body></div>
            </div>
        </div>
    </div>
</div>
<?php if ($kpmKey !== ''): ?>
<script>
(function () {
    var ROOT = document.getElementById('<?= $kpmId ?>');
    if (!ROOT) { return; }
    var LOCS = <?= json_encode(array_values($kpmParks), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var UIR  = <?= json_encode(UIR, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    function isLight(hex) {
        var r = parseInt(hex.substring(0, 2), 16), g = parseInt(hex.substring(2, 4), 16), b = parseInt(hex.substring(4, 6), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.55;
    }
    function renderSidebar(loc) {
        var hex = (loc.color && loc.color.length === 6) ? loc.color : '718096';
        var light = isLight(hex);
        var txt = light ? '#1a202c' : '#ffffff';
        var muted = light ? 'rgba(0,0,0,0.6)' : 'rgba(255,255,255,0.85)';
        var locLine = [loc.city, loc.province].filter(Boolean).join(', ');
        var crest = loc.heraldry
            ? '<div class="kpm-crest"><img src="' + loc.heraldry + '" alt="' + loc.name + ' heraldry" onerror="this.style.display=\'none\'"></div>'
            : '<div class="kpm-crest"><span class="kpm-crest-ph" style="color:' + hex + '"><i class="fas fa-shield-alt"></i></span></div>';
        var hero = crest
            + '<div class="kpm-hero-name" style="color:' + txt + '">' + loc.name + '</div>'
            + (locLine ? '<div class="kpm-hero-loc" style="color:' + muted + '"><i class="fas fa-map-marker-alt" style="font-size:10px"></i>' + locLine + '</div>' : '');
        var body = '';
        if (loc.dir) { body += '<div class="kpm-section-label">Directions</div><div class="kpm-section-text">' + loc.dir + '</div>'; }
        if (loc.desc) { body += '<div class="kpm-section-label">About</div><div class="kpm-section-text">' + loc.desc + '</div>'; }
        body += '<a class="kpm-profile-btn" href="' + UIR + 'Park/profile/' + loc.id + '"><i class="fas fa-external-link-alt"></i> View Park Profile</a>';
        var heroEl = ROOT.querySelector('[data-kpm-hero]');
        heroEl.innerHTML = hero;
        heroEl.style.background = 'linear-gradient(135deg, #' + hex + 'dd, #' + hex + '99)';
        ROOT.querySelector('[data-kpm-body]').innerHTML = body;
        ROOT.querySelector('[data-kpm-empty]').style.display = 'none';
        ROOT.querySelector('[data-kpm-park]').style.display = 'flex';
    }
    async function init() {
        var loadingEl = ROOT.querySelector('[data-kpm-loading]');
        var mapEl = ROOT.querySelector('[data-kpm-map]');
        if (!mapEl) { return; }
        if (loadingEl) { loadingEl.style.display = 'none'; }
        mapEl.style.display = 'block';
        await google.maps.importLibrary('maps');
        var markerLib = await google.maps.importLibrary('marker');
        var map = new google.maps.Map(mapEl, { center: { lat: 39, lng: -98 }, zoom: 4, mapId: 'ORK3_MAP_ID' });
        var info = new google.maps.InfoWindow();
        var bounds = new google.maps.LatLngBounds();
        LOCS.forEach(function (loc) {
            var color = loc.color ? '#' + loc.color : '#718096';
            var pos = new google.maps.LatLng(loc.lat, loc.lng);
            var pin = new markerLib.PinElement({ background: color, scale: 0.8 });
            var marker = new markerLib.AdvancedMarkerElement({ position: pos, map: map, title: loc.name, content: pin.element });
            bounds.extend(pos);
            google.maps.event.addListener(marker, 'click', function () {
                var locLine = [loc.city, loc.province].filter(Boolean).join(', ');
                info.setContent('<b><a href="' + UIR + 'Park/profile/' + loc.id + '" style="color:#2b6cb0">' + loc.name + '</a></b>'
                    + (locLine ? '<div style="font-size:12px;color:#718096;margin-top:3px">' + locLine + '</div>' : ''));
                info.open(map, marker);
                renderSidebar(loc);
            });
        });
        if (LOCS.length === 1) { map.setCenter(bounds.getCenter()); map.setZoom(11); }
        else { map.fitBounds(bounds, 48); }
    }
    // One-time Maps loader shared by every kpm block on the page. The Maps CDN
    // <script> is only injected once the map scrolls into view (IntersectionObserver),
    // so an off-screen map never blocks the page on a third-party request.
    function loadMaps() {
        if (window.google && window.google.maps && window.google.maps.importLibrary) {
            init();
            return;
        }
        (window.__kpmQueue = window.__kpmQueue || []).push(init);
        if (!window.__kpmLoading) {
            window.__kpmLoading = true;
            window.__kpmReady = function () { (window.__kpmQueue || []).forEach(function (f) { try { f(); } catch (e) {} }); window.__kpmQueue = []; };
            var s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($kpmKey, ENT_QUOTES) ?>&callback=__kpmReady&v=weekly&libraries=marker';
            s.async = true;
            document.head.appendChild(s);
        }
    }
    // Lazy-load: defer the Maps CDN until this block is near the viewport.
    var observeTarget = ROOT.querySelector('[data-kpm-map]') || ROOT;
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries, obs) {
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].isIntersecting) { obs.disconnect(); loadMaps(); return; }
            }
        }, { rootMargin: '200px' });
        io.observe(observeTarget);
    } else {
        loadMaps();
    }
})();
</script>
<?php endif; ?>
