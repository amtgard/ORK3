<?php
/**
 * _officer_panel.tpl — reusable officer display panel.
 *
 * Expected inputs (set by the including template BEFORE include):
 *   $officers : grouped, alias-resolved, retired-filtered array shaped
 *               ['crown' => [ row, ... ], 'supporting' => [ row, ... ]]
 *               where each row carries at minimum:
 *                 'DisplayTitle' (string, alias-resolved title to render)
 *                 'CanonicalKey' (string, stable match key — NOT displayed)
 *                 'MundaneId'    (int, occupant; 0/empty = vacant)
 *                 'Persona'      (string, occupant persona)
 *               and optionally term info:
 *                 'TermStart' / 'TermEnd' (human-readable strings, NOT raw ISO)
 *   $mode     : 'sidebar' (Crown group only) | 'about' (all groups)
 *
 * Renders DisplayTitle for every position; canonical_key is never shown.
 * Dark-mode safe (no inline color styles for state — see _officer_panel CSS
 * block in the host template / orkui.css; muted state via .kn-off-vacant class).
 * No native title attributes — uses data-tip if a tooltip is needed.
 */

if ( !isset( $officers ) || !is_array( $officers ) ) {
	$officers = [ 'crown' => [], 'supporting' => [] ];
}
if ( !isset( $officers['crown'] ) || !is_array( $officers['crown'] ) ) {
	$officers['crown'] = [];
}
if ( !isset( $officers['supporting'] ) || !is_array( $officers['supporting'] ) ) {
	$officers['supporting'] = [];
}
if ( !isset( $mode ) || ( $mode !== 'sidebar' && $mode !== 'about' ) ) {
	$mode = 'sidebar';
}

// Sidebar shows Crown only; About shows all groups.
$_panelGroups = [ 'crown' => 'Crown' ];
if ( $mode === 'about' ) {
	$_panelGroups['supporting'] = 'Supporting';
}
?>
<div class="kn-off-panel kn-off-panel--<?= htmlspecialchars( $mode ) ?>">
<?php foreach ( $_panelGroups as $_groupKey => $_groupLabel ): ?>
	<?php $_rows = isset( $officers[ $_groupKey ] ) ? $officers[ $_groupKey ] : []; ?>
	<?php if ( $mode === 'about' || count( $_rows ) > 0 ): ?>
	<div class="kn-off-group kn-off-group--<?= htmlspecialchars( $_groupKey ) ?>">
		<?php if ( $mode === 'about' ): ?>
		<h5 class="kn-off-group-title"><?= htmlspecialchars( $_groupLabel ) ?></h5>
		<?php endif; ?>
		<ul class="kn-off-list">
			<?php if ( count( $_rows ) === 0 ): ?>
			<li class="kn-off-row"><span class="kn-off-vacant">No positions on record</span></li>
			<?php else: ?>
				<?php foreach ( $_rows as $o ): ?>
					<?php
					$_title = isset( $o['DisplayTitle'] ) && $o['DisplayTitle'] !== '' ? $o['DisplayTitle'] : ( $o['OfficerRole'] ?? '' );
					$_mid   = isset( $o['MundaneId'] ) ? (int) $o['MundaneId'] : 0;
					$_persona = isset( $o['Persona'] ) ? $o['Persona'] : '';
					$_termStart = isset( $o['TermStart'] ) ? trim( (string) $o['TermStart'] ) : '';
					$_termEnd   = isset( $o['TermEnd'] ) ? trim( (string) $o['TermEnd'] ) : '';
					?>
					<li class="kn-off-row">
						<span class="kn-off-title"><?= htmlspecialchars( $_title ) ?></span>
						<span class="kn-off-occupant">
							<?php if ( $_mid > 0 && $_persona !== '' ): ?>
								<a href="<?= UIR ?>Player/profile/<?= $_mid ?>"><?= htmlspecialchars( $_persona ) ?></a>
							<?php else: ?>
								<span class="kn-off-vacant">(Vacant)</span>
							<?php endif; ?>
						</span>
						<?php if ( $_mid > 0 && $_termStart !== '' ): ?>
						<span class="kn-off-term">Term: <?= htmlspecialchars( $_termStart ) ?> &rarr; <?= $_termEnd !== '' ? htmlspecialchars( $_termEnd ) : '(current)' ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			<?php endif; ?>
		</ul>
	</div>
	<?php endif; ?>
<?php endforeach; ?>
</div>

<style>
/* _officer_panel.tpl — dark-mode-first; state via classes, no inline colors. */
.kn-off-panel { display: flex; flex-direction: column; gap: 14px; }
.kn-off-group-title {
	/* Reset the global orkui.css h1-h6 gray pill. */
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
	margin: 0 0 6px 0; font-size: 12px; font-weight: 700; text-transform: uppercase;
	letter-spacing: .04em; color: var(--ork-text-muted, #718096);
}
.kn-off-list { list-style: none; margin: 0; padding: 0; }
.kn-off-row { display: flex; flex-direction: column; gap: 1px; padding: 5px 0; border-bottom: 1px solid var(--ork-border, #edf2f7); }
.kn-off-row:last-child { border-bottom: none; }
.kn-off-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--ork-text-muted, #718096); }
.kn-off-occupant { font-size: 14px; color: var(--ork-text, #2d3748); }
.kn-off-occupant a { text-decoration: none; }
.kn-off-occupant a:hover { text-decoration: underline; }
.kn-off-vacant { font-style: italic; color: var(--ork-text-muted, #a0aec0); }
.kn-off-term { font-size: 11px; color: var(--ork-text-muted, #a0aec0); }
html[data-theme="dark"] .kn-off-row { border-bottom-color: var(--ork-border); }
html[data-theme="dark"] .kn-off-title,
html[data-theme="dark"] .kn-off-group-title,
html[data-theme="dark"] .kn-off-vacant,
html[data-theme="dark"] .kn-off-term { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-off-occupant { color: var(--ork-text); }
</style>
