<?php
/**
 * Hall of Legends (In Memoriam): the roll of Legends, grouped by the year they passed, latest first.
 * Expects $Legends (Legend::roll()); with $LegendKingdomId / $LegendKingdomName it is one kingdom's roll. The colours are the profile's In Memoriam card's
 * (revised.css .pn-legend-card), repeated here because this page does not load it.
 * Plain divs, not h4/p: orkui.css styles those globally (justified paragraphs,
 * boxed headings).
 */
$_lgrKingdomId = (int)($LegendKingdomId ?? 0);
$_lgrKingdom = $_lgrKingdomId > 0 ? trim(stripslashes((string)($LegendKingdomName ?? ''))) : '';
$_lgrByYear = array();
foreach ((is_array($Legends ?? null) ? $Legends : array()) as $_lgr) {
	$_lgrByYear[(int)$_lgr['Year']][] = $_lgr;
}
?>
<style>
.lgr-root { max-width: 1000px; margin: 16px auto 0; }
.lgr-card {
	margin: 0 0 20px; padding: 18px 26px 20px; text-align: center;
	background: #f7efe0; border: 1px solid #dcc9a4; border-radius: 8px; color: #5a4220;
}
.lgr-hall { margin: 0 0 10px; font: 700 26px/1.2 Georgia, 'Times New Roman', serif; overflow-wrap: anywhere; }
.lgr-title { margin: 0 0 8px; color: #8a6a33; font: 700 11px/1 system-ui, sans-serif; letter-spacing: .2em; text-transform: uppercase; }
.lgr-kingdom { margin: 0 0 10px; font: 18px/1.3 Georgia, 'Times New Roman', serif; overflow-wrap: anywhere; }
.lgr-all { margin: 10px 0 0; text-align: center; font-size: 13px; }
.lgr-all a { display: inline-block; padding: 12px 8px; }
.lgr-rule { width: 64px; height: 1px; margin: 0 auto 10px; background: #b08d4f; }
.lgr-words { font: italic 16px/1.55 Georgia, 'Times New Roman', serif; overflow-wrap: anywhere; text-wrap: balance; }
.lgr-year {
	display: flex; align-items: center; gap: 12px; margin: 22px 0 10px;
	color: #8a6a33; font: 700 13px/1 system-ui, sans-serif; letter-spacing: .12em;
}
.lgr-year::after { content: ""; flex: 1; height: 1px; background: #dcc9a4; }
.lgr-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; }
a.lgr-entry, a.lgr-entry:visited {
	display: block; padding: 12px 15px; min-height: 44px; box-sizing: border-box;
	background: #fdfaf3; border: 1px solid #e6dcc8; border-radius: 8px; text-decoration: none;
}
a.lgr-entry:hover, a.lgr-entry:focus-visible { border-color: #b08d4f; }
.lgr-name { color: #3f3423; font: 16px/1.4 Georgia, 'Times New Roman', serif; overflow-wrap: anywhere; }
.lgr-home, .lgr-memories { margin-top: 3px; color: #7a6a50; font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
.lgr-memories i { font-size: 11px; }
.lgr-empty { color: var(--ork-text-muted); font-size: 14px; text-align: center; }
html[data-theme="dark"] .lgr-card { background: #322819; border-color: #5e4a2c; color: #e8dbc2; }
html[data-theme="dark"] .lgr-title, html[data-theme="dark"] .lgr-year { color: #d8b878; }
html[data-theme="dark"] .lgr-year::after { background: #5e4a2c; }
html[data-theme="dark"] a.lgr-entry { background: #2a2216; border-color: #4d3e27; }
html[data-theme="dark"] a.lgr-entry:hover, html[data-theme="dark"] a.lgr-entry:focus-visible { border-color: #d8b878; }
html[data-theme="dark"] .lgr-name { color: #e8dbc2; }
html[data-theme="dark"] .lgr-home, html[data-theme="dark"] .lgr-memories { color: #b8a684; }
@media (max-width: 600px) {
	.lgr-card { padding: 14px 16px 15px; }
	.lgr-words { font-size: 15px; }
	.lgr-hall { font-size: 22px; }
}
</style>

<div class="lgr-root">
	<div class="lgr-card">
		<div class="lgr-hall" role="heading" aria-level="1">Hall of Legends</div>
<?php if ($_lgrKingdom !== ''): ?>
		<div class="lgr-kingdom"><?= htmlspecialchars($_lgrKingdom, ENT_QUOTES) ?></div>
<?php endif; ?>
		<div class="lgr-title">In Memoriam</div>
		<div class="lgr-rule"></div>
		<div class="lgr-words">These players have passed beyond this realm. Their spirits live on as legends of Amtgard and they will be missed.</div>
	</div>

<?php if (!$_lgrByYear): ?>
	<div class="lgr-empty">No Legends have been recorded<?= $_lgrKingdom !== '' ? ' for ' . htmlspecialchars($_lgrKingdom, ENT_QUOTES) : '' ?>.</div>
<?php endif; ?>
<?php foreach ($_lgrByYear as $_lgrYear => $_lgrGroup): ?>
	<div class="lgr-year" role="heading" aria-level="2"><?= (int)$_lgrYear ?></div>
	<div class="lgr-list">
<?php   foreach ($_lgrGroup as $_lgr):
		$_lgrHome = implode(', ', array_filter(array(trim(stripslashes($_lgr['ParkName'])), $_lgrKingdomId > 0 ? '' : trim(stripslashes($_lgr['KingdomName']))), 'strlen'));
		$_lgrCount = (int)$_lgr['Memories'];
?>
		<a class="lgr-entry" href="<?= UIR ?>Player/profile/<?= (int)$_lgr['MundaneId'] ?>">
			<div class="lgr-name"><?= htmlspecialchars($_lgr['Persona'], ENT_QUOTES) ?></div>
<?php     if ($_lgrHome !== ''): ?>
			<div class="lgr-home"><?= htmlspecialchars($_lgrHome, ENT_QUOTES) ?></div>
<?php     endif; ?>
<?php     if ($_lgrCount > 0): ?>
			<div class="lgr-memories"><i class="fas fa-feather-alt" aria-hidden="true"></i> <?= $_lgrCount ?> <?= $_lgrCount === 1 ? 'memory' : 'memories' ?> on the Memory Wall</div>
<?php     endif; ?>
		</a>
<?php   endforeach; ?>
	</div>
<?php endforeach; ?>
<?php if ($_lgrKingdomId > 0): ?>
	<div class="lgr-all"><a href="<?= UIR ?>Legend">View the Hall of Legends for all of Amtgard</a></div>
<?php endif; ?>
</div>
