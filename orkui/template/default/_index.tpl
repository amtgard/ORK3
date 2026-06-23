<?php
/*
 * Front door — generic content-block renderer.
 * Iterates $FrontDoor blocks (ordered, enabled) and includes one partial per type.
 * Partials are "dumb": they render $blockFields (+ shared $data) and fetch nothing.
 */
$fdBlocks = isset( $FrontDoor ) && is_array( $FrontDoor ) ? $FrontDoor : [];
$fdDir    = DIR_TEMPLATE . 'default/frontdoor/';
$fdBlockDir = $fdDir . 'blocks/';
$fdAssetBase = HTTP_TEMPLATE . 'default/frontdoor/';
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/frontdoor.css">

<div class="fd-page">
<?php
foreach ( $fdBlocks as $block ) {
	if ( empty( $block['enabled'] ) ) { continue; }
	$type = preg_replace( '/[^a-z_]/', '', (string) $block['type'] );
	$partial = $fdBlockDir . $type . '.tpl';
	if ( ! file_exists( $partial ) ) { continue; }
	$blockFields = isset( $block['fields'] ) && is_array( $block['fields'] ) ? $block['fields'] : [];
	$blockMeta   = $block;
	include $partial;
}
?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
