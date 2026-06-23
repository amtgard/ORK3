<?php
/*
 * Front door — generic content-block renderer.
 * Iterates $FrontDoor blocks (ordered, enabled) and includes one partial per type
 * via the shared frontdoor/render_blocks.tpl. Partials are "dumb": they render
 * $blockFields (+ shared $data) and fetch nothing.
 */
$fdBlocks    = isset( $FrontDoor ) && is_array( $FrontDoor ) ? $FrontDoor : [];
$fdDir       = DIR_TEMPLATE . 'default/frontdoor/';
$fdAssetBase = HTTP_TEMPLATE . 'default/frontdoor/';
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/frontdoor.css?v=<?= @filemtime( $fdDir . 'css/frontdoor.css' ) ?>">

<div class="fd-page">
<?php include $fdDir . 'render_blocks.tpl'; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
