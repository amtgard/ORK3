<?php
/*
 * Shared content-block renderer.
 * Expects $fdBlocks in scope: an ordered list of enabled content blocks.
 * Includes one "dumb" partial per block type from frontdoor/blocks/{type}.tpl.
 * Each partial renders $blockFields (+ shared $data + $blockMeta) and fetches nothing.
 *
 * Callers (home page, CMS pages, blog) set $fdBlocks then `include` this file.
 */
$fdBlocks    = isset( $fdBlocks ) && is_array( $fdBlocks ) ? $fdBlocks : [];
$fdBlockDir  = DIR_TEMPLATE . 'default/frontdoor/blocks/';
foreach ( $fdBlocks as $block ) {
	if ( empty( $block['enabled'] ) ) { continue; }
	$type = preg_replace( '/[^a-z_]/', '', (string) $block['type'] );
	$partial = $fdBlockDir . $type . '.tpl';
	if ( ! file_exists( $partial ) ) { continue; }
	$blockFields = isset( $block['fields'] ) && is_array( $block['fields'] ) ? $block['fields'] : [];
	$blockMeta   = $block;
	// Contain a broken block: a fatal in one partial must not blank the whole
	// page — skip it and render the rest.
	try {
		include $partial;
	} catch ( \Throwable $e ) {
		// Intentionally swallow; one bad block shouldn't take down the page.
	}
}
