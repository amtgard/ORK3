<?php
/**
 * Partial: kingdom_parks.tpl — DYNAMIC block (org-scoped).
 *
 * Shows the ACTIVE parks for the site's owning kingdom — name, city/state, and a
 * deep-link to each park's public profile route.
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
 * Receives: $blockFields { kicker?, heading?, limit?, more_href? }, UIR, $SiteNavScope*.
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
$kpRows = array_slice($kpRows, 0, $kpLimit);
?>
<style>
.kp-block { background: var(--fd-bg); }
.kp-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 18px; gap: 12px; }
.kp-title { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0; font-size: 24px; }
.kp-more { color: #1d4ed8; font-weight: 600; font-size: 14px; text-decoration: none; white-space: nowrap; }
.kp-more:hover { text-decoration: underline; }
.kp-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.kp-card {
    display: block; text-decoration: none; color: inherit;
    background: var(--fd-bg); border: 1px solid #e4e8f0; border-radius: 10px; overflow: hidden;
    transition: box-shadow .15s ease, transform .15s ease;
}
.kp-card:hover { box-shadow: 0 6px 18px rgba(20,30,60,.12); transform: translateY(-2px); }
.kp-card-accent { height: 6px; background: var(--gold, #d4af37); }
.kp-card-body { padding: 14px 16px 16px; }
.kp-card-name { font-weight: 700; font-size: 16px; margin: 0 0 4px; color: var(--fd-text); }
.kp-card-loc { font-size: 13px; color: #50596e; line-height: 1.4; }
.kp-card-loc i { color: #b8860b; margin-right: 5px; }
.kp-empty { color: #8899aa; font-style: italic; text-align: center; padding: 18px; }

@media (max-width: 820px) { .kp-grid { grid-template-columns: 1fr; } }

html[data-theme="dark"] .kp-block { background: transparent; }
html[data-theme="dark"] .kp-card { background: #1b2233; border-color: #2c3650; }
html[data-theme="dark"] .kp-card-name { color: #eef2fa; }
html[data-theme="dark"] .kp-card-loc { color: #b6c0d4; }
html[data-theme="dark"] .kp-card:hover { box-shadow: 0 6px 18px rgba(0,0,0,.45); }
</style>
<div class="fd-pad fd-section-light kp-block" style="background:#fff;">
    <div class="kp-head">
        <div>
            <?php if ($kpKicker !== ''): ?>
                <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($kpKicker, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($kpHeading !== ''): ?>
                <h3 class="kp-title fd-sec-title"><?= htmlspecialchars($kpHeading, ENT_QUOTES) ?></h3>
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
                $kpLocParts = array_filter([$kpCity, $kpProvince], static fn ($v) => $v !== '');
                $kpLoc      = implode(', ', $kpLocParts);
                $kpNameOut  = htmlspecialchars(stripslashes($kpName), ENT_QUOTES);
                $kpLocOut   = htmlspecialchars(stripslashes($kpLoc), ENT_QUOTES);
                $kpHref     = UIR . 'Park/profile/' . $kpParkId;
                ?>
                <a class="kp-card" href="<?= htmlspecialchars($kpHref, ENT_QUOTES) ?>">
                    <div class="kp-card-accent"></div>
                    <div class="kp-card-body">
                        <div class="kp-card-name"><?= $kpNameOut ?></div>
                        <?php if ($kpLocOut !== ''): ?>
                            <div class="kp-card-loc"><i class="fas fa-map-marker-alt"></i><?= $kpLocOut ?></div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
