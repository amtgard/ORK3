<?php
/*
 * CMS page view — renders a published page's blocks through the shared
 * frontdoor/render_blocks.tpl partial, so CMS pages inherit front-door styling.
 * $FrontDoor = ordered enabled blocks (from Controller_Page::view).
 * $Message   = set (e.g. "Page not found.") when there is no page to render.
 */
$fdDir       = DIR_TEMPLATE . 'default/frontdoor/';
$fdAssetBase = HTTP_TEMPLATE . 'default/frontdoor/';
$fdBlocks    = isset( $FrontDoor ) && is_array( $FrontDoor ) ? $FrontDoor : [];
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/frontdoor.css?v=<?= @filemtime( $fdDir . 'css/frontdoor.css' ) ?>">

<?php if ( ! empty( $Message ) && empty( $fdBlocks ) ) : ?>
<div class="fd-page">
	<p style="padding:2rem;text-align:center;"><?= htmlspecialchars( (string) $Message ) ?></p>
</div>
<?php else : ?>
<div class="fd-page">
<?php include $fdDir . 'render_blocks.tpl'; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
<?php endif; ?>
