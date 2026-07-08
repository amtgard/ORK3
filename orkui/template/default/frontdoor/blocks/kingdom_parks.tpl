<?php
/**
 * Partial: kingdom_parks.tpl — DYNAMIC block (org-scoped).
 *
 * Shows the ACTIVE parks for the site's owning kingdom as cards — optional
 * heraldry crest, park name, rank/title badge, and city/state — deep-linked to
 * each park's public profile. Sortable by park name, city, or state.
 *
 * Self-sourcing like blog_feed.tpl: reads parks itself via the Kingdom lib
 * (new APIModel('Kingdom') → Kingdom::GetParks). GetParks returns ALL parks incl.
 * retired, so this partial filters to Active === 'Active' (case-insensitive: the
 * shared local DB may hold lowercase status keys).
 *
 * Scope: derives kingdom_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId, set by Controller_Site::_bootShell). Renders NOTHING outside a
 * kingdom scope (global front door / park / unit sites) — never errors, never fatals.
 *
 * Receives: $blockFields { kicker?, heading?, sort?, show_heraldry?, limit?,
 * more_href? }, UIR, $SiteNavScope*.
 */
$kpScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$kpScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$kpKingdomId = ($kpScopeType === 'kingdom') ? $kpScopeId : 0;

// Dropped on a non-kingdom / global page → no single kingdom to source. Render
// nothing at all rather than a broken or misleading empty box.
if ($kpKingdomId <= 0) {
    return;
}

$kpKicker   = isset($blockFields['kicker']) ? trim((string) $blockFields['kicker']) : '';
$kpHeading  = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'Our Parks';
$kpLimit    = isset($blockFields['limit']) ? (int) $blockFields['limit'] : 24;
if ($kpLimit < 1) {
    $kpLimit = 24;
}
if ($kpLimit > 60) {
    $kpLimit = 60;
}
$kpSort = isset($blockFields['sort']) ? (string) $blockFields['sort'] : 'name';
if (!in_array($kpSort, array('name', 'city', 'state'), true)) {
    $kpSort = 'name';
}
$kpShowHeraldry = !empty($blockFields['show_heraldry'])
    && (string) $blockFields['show_heraldry'] !== '0'
    && (string) $blockFields['show_heraldry'] !== 'false';
$kpMoreHref = isset($blockFields['more_href']) ? trim((string) $blockFields['more_href']) : '';
if ($kpMoreHref === '#') {
    // Blank URL fields are rewritten to '#' by the save sanitizer — treat as unset.
    $kpMoreHref = '';
}

$kpRows = [];
if (class_exists('APIModel')) {
    try {
        $kpModel  = new APIModel('Kingdom');
        $kpResult = $kpModel->GetParks(['KingdomId' => $kpKingdomId]);
        if (is_array($kpResult) && isset($kpResult['Parks']) && is_array($kpResult['Parks'])) {
            foreach ($kpResult['Parks'] as $kpPark) {
                // GetParks does NOT filter status — keep only active parks.
                if (strcasecmp(trim((string) ($kpPark['Active'] ?? '')), 'Active') === 0) {
                    $kpRows[] = $kpPark;
                }
            }
        }
    } catch (\Throwable $e) {
        $kpRows = [];
    }
}

// Sort per the chosen mode. 'city' falls back to name; 'state' falls back to
// city then name. Case-insensitive; empty keys sort last within their tier.
$kpCmp = static function ($x) {
    $x = strtolower(trim((string) $x));
    // Empty values sort after non-empty ones.
    return $x === '' ? "\xff" . $x : $x;
};
usort($kpRows, function ($a, $b) use ($kpSort, $kpCmp) {
    $an = $kpCmp($a['Name'] ?? '');
    $bn = $kpCmp($b['Name'] ?? '');
    if ($kpSort === 'state') {
        $ap = $kpCmp($a['Province'] ?? '');
        $bp = $kpCmp($b['Province'] ?? '');
        if ($ap !== $bp) {
            return strcmp($ap, $bp);
        }
        $ac = $kpCmp($a['City'] ?? '');
        $bc = $kpCmp($b['City'] ?? '');
        if ($ac !== $bc) {
            return strcmp($ac, $bc);
        }
        return strcmp($an, $bn);
    }
    if ($kpSort === 'city') {
        $ac = $kpCmp($a['City'] ?? '');
        $bc = $kpCmp($b['City'] ?? '');
        if ($ac !== $bc) {
            return strcmp($ac, $bc);
        }
        return strcmp($an, $bn);
    }
    return strcmp($an, $bn);
});
$kpRows = array_slice($kpRows, 0, $kpLimit);

// Resolve a park's heraldry crest URL (only when the park flags it + the image
// helper is available). Mirrors the Atlas map's park-heraldry resolution.
$kpHeraldryUrl = static function ($parkId, $hasHeraldry) {
    if (empty($hasHeraldry) || (int) $parkId <= 0) {
        return '';
    }
    if (!defined('HTTP_PARK_HERALDRY') || !defined('DIR_PARK_HERALDRY') || !class_exists('Common')) {
        return '';
    }
    $file = Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf('%05d', (int) $parkId));
    return $file !== '' ? HTTP_PARK_HERALDRY . $file : '';
};
?>
<?php // Emit this block's static CSS at most once per request (dedupes repeats). ?>
<?php if (empty($fdStyleOnce['kingdom_parks'])) : $fdStyleOnce['kingdom_parks'] = true; ?>
<style>
.kp-block { background: var(--fd-bg); }
.kp-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 18px; gap: 12px; }
.kp-title { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0; font-size: 24px; }
.kp-more { color: #1d4ed8; font-weight: 600; font-size: 14px; text-decoration: none; white-space: nowrap; }
.kp-more:hover { text-decoration: underline; }
.kp-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.kp-card {
    display: flex; flex-direction: column; text-decoration: none; color: inherit;
    background: var(--fd-bg); border: 1px solid #e4e8f0; border-radius: 10px; overflow: hidden;
    transition: box-shadow .15s ease, transform .15s ease;
}
.kp-card:hover { box-shadow: 0 8px 22px rgba(20,30,60,.14); transform: translateY(-3px); }
.kp-card-accent { height: 6px; background: var(--gold, #d4af37); }
.kp-card-body { padding: 14px 16px 16px; display: flex; gap: 13px; align-items: flex-start; }
.kp-crest { flex: 0 0 auto; width: 48px; height: 48px; border-radius: 8px; background: #f2f4f9; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.kp-crest img { width: 100%; height: 100%; object-fit: contain; }
.kp-crest i { color: #b8860b; font-size: 20px; }
.kp-card-main { min-width: 0; flex: 1 1 auto; }
.kp-card-name { font-weight: 700; font-size: 16px; margin: 0 0 3px; color: var(--fd-text); }
.kp-badge { display: inline-block; font-size: 11px; font-weight: 600; letter-spacing: .02em; color: #7a5b12; background: #fbf3dc; border: 1px solid #eeddb0; border-radius: 999px; padding: 1px 9px; margin: 0 0 5px; }
.kp-card-loc { font-size: 13px; color: #50596e; line-height: 1.4; }
.kp-card-loc i { color: #b8860b; margin-right: 5px; }
.kp-empty { color: #8899aa; font-style: italic; text-align: center; padding: 18px; }

@media (max-width: 820px) { .kp-grid { grid-template-columns: 1fr; } }

html[data-theme="dark"] .kp-block { background: transparent; }
html[data-theme="dark"] .kp-card { background: #1b2233; border-color: #2c3650; }
html[data-theme="dark"] .kp-card-name { color: #eef2fa; }
html[data-theme="dark"] .kp-card-loc { color: #b6c0d4; }
html[data-theme="dark"] .kp-crest { background: #222c42; }
html[data-theme="dark"] .kp-badge { color: #e6cf92; background: #33291140; border-color: #5c4a1f; }
html[data-theme="dark"] .kp-card:hover { box-shadow: 0 8px 22px rgba(0,0,0,.5); }
</style>
<?php endif; ?>
<div class="fd-pad fd-section-light kp-block">
    <div class="kp-head">
        <div>
            <?php if ($kpKicker !== ''): ?>
                <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($kpKicker, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($kpHeading !== ''): ?>
                <h2 class="kp-title fd-sec-title"><?= htmlspecialchars($kpHeading, ENT_QUOTES) ?></h2>
            <?php endif; ?>
        </div>
        <?php if ($kpMoreHref !== ''): ?>
            <a class="kp-more" href="<?= htmlspecialchars($kpMoreHref, ENT_QUOTES) ?>">All parks &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (empty($kpRows)): ?>
        <div class="kp-empty">No active parks to show yet.</div>
    <?php else: ?>
        <div class="kp-grid">
            <?php foreach ($kpRows as $kpRow): ?>
                <?php
                $kpParkId = (int) ($kpRow['ParkId'] ?? 0);
                $kpName   = trim((string) ($kpRow['Name'] ?? ''));
                if ($kpParkId <= 0 || $kpName === '') {
                    continue;
                }
                $kpCity     = trim((string) ($kpRow['City'] ?? ''));
                $kpProvince = trim((string) ($kpRow['Province'] ?? ''));
                $kpTitle    = trim((string) ($kpRow['Title'] ?? ''));
                $kpLocParts = array_filter([$kpCity, $kpProvince], static fn ($v) => $v !== '');
                $kpLoc      = implode(', ', $kpLocParts);
                $kpNameOut  = htmlspecialchars(stripslashes($kpName), ENT_QUOTES);
                $kpLocOut   = htmlspecialchars(stripslashes($kpLoc), ENT_QUOTES);
                $kpTitleOut = htmlspecialchars(stripslashes($kpTitle), ENT_QUOTES);
                $kpHref     = UIR . 'Park/profile/' . $kpParkId;
                $kpCrest    = $kpShowHeraldry ? $kpHeraldryUrl($kpParkId, $kpRow['HasHeraldry'] ?? 0) : '';
                ?>
                <a class="kp-card" href="<?= htmlspecialchars($kpHref, ENT_QUOTES) ?>">
                    <div class="kp-card-accent"></div>
                    <div class="kp-card-body">
                        <?php if ($kpShowHeraldry): ?>
                            <div class="kp-crest">
                                <?php // Show the crest by default; if the image 404s, fall back to the shield icon. ?>
                                <?php if ($kpCrest !== ''): ?>
                                    <img src="<?= htmlspecialchars($kpCrest, ENT_QUOTES) ?>" alt="<?= $kpNameOut ?> heraldry"
                                         onerror="this.style.display='none';this.parentNode.querySelector('i').style.display='';">
                                    <i class="fas fa-shield-alt" style="display:none;"></i>
                                <?php else: ?>
                                    <i class="fas fa-shield-alt"></i>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="kp-card-main">
                            <div class="kp-card-name"><?= $kpNameOut ?></div>
                            <?php if ($kpTitleOut !== ''): ?>
                                <div class="kp-badge"><?= $kpTitleOut ?></div>
                            <?php endif; ?>
                            <?php if ($kpLocOut !== ''): ?>
                                <div class="kp-card-loc"><i class="fas fa-map-marker-alt"></i><?= $kpLocOut ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
