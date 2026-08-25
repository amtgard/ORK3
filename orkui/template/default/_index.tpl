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
<?php include $fdDir . '_assets_public.tpl'; ?>
<?php include $fdDir . '_assets_inshell.tpl'; ?>
<?php
// The front door suppresses the ORK application top bar (#newmenu) so
// anonymous visitors do not see two stacked navbars. That rule names an ORK
// shell selector, so it lives in frontdoor/css/orkshell-interop.css (linked by
// _assets_inshell.tpl above), the one file allowed to name one.
?>

<div class="fd-page">
<?php include $fdDir . 'render_blocks.tpl'; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js?v=<?= @filemtime($fdDir . 'js/frontdoor.js') ?>"></script>
