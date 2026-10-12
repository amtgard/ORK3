<?php
/*
 * Generic fallback template. The home/front-door page renders via _index.tpl
 * and the Kingdoms Directory via Directory_index.tpl. This file only renders
 * when a controller/request has no specific template — keep it neutral.
 */
?>
<?php if ( ! empty( $Message ) ): ?>
	<div class="hm-infobox" style="margin:16px"><?= htmlspecialchars( $Message ) ?></div>
<?php endif; ?>
