<?php
/**
 * In Memoriam card, between a Legend's hero and the stats row.
 * Uses the plain persona, without prefix or suffix. Expects $Player, $LegendYear.
 */
?>
<div class="pn-legend-card">
	<h4>In Memoriam</h4>
	<div class="pn-legend-rule"></div>
	<p><?= htmlspecialchars($Player['Persona'] ?? '', ENT_QUOTES) ?> passed beyond this realm in <?= (int)$LegendYear ?>. Their spirit lives on as a legend of Amtgard and they will be missed.</p>
</div>
