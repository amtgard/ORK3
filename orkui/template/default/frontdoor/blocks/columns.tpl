<?php
/**
 * Partial: columns.tpl  (LAYOUT block)
 * Receives: $blockFields { columns: [ [block,...], [block,...] ] }, shared $data, UIR
 *
 * Each entry in `columns` is an ordered list of content blocks (same
 * {type,enabled,order,source,fields} shape the page-level list uses). We render
 * each column by re-using the shared renderer: set $fdBlocks = that column's
 * blocks and include render_blocks.tpl. (render_blocks.tpl reads $fdBlocks.)
 *
 * Missing/disabled child block types are already skipped by render_blocks.tpl.
 */
$fdbColumns = $blockFields['columns'] ?? [];
$fdbColumns = is_array($fdbColumns) ? array_values(array_filter($fdbColumns, 'is_array')) : [];
$fdbCount   = count($fdbColumns);
// The column count travels as a class (.fdb-columns-1 … .fdb-columns-6, all
// defined in frontdoor/css/blocks.css), never as an interpolated
// grid-template-columns in a per-instance <style>: that selector was global, so
// with several columns blocks on one page the last emission set the column
// count for all of them. Counts past 6 clamp and wrap onto a second row.
$fdbCols = max(1, min(6, $fdbCount));
?>
<?php if ($fdbCount > 0): ?>
<div class="fd-pad fdb-columns fdb-columns-<?= (int) $fdbCols ?>">
    <?php foreach ($fdbColumns as $col): ?>
        <div class="fdb-columns-col">
            <?php
            // Recurse into the shared renderer for this column's blocks.
            // render_blocks.tpl reads $fdBlocks; restore the outer value after.
            // Use a depth-safe stack so nested columns blocks (a columns block
            // inside a columns block) don't clobber each other's outer context.
            // `static` does NOT persist here (include-pseudo-main scope is
            // re-initialised on every re-entry), so seed the stack from the
            // shared include-chain scope instead — same pattern render_blocks.tpl
            // uses for $fdRenderDepth.
            if (! isset($fdbStack) || ! is_array($fdbStack)) {
                $fdbStack = [];
            }
            $fdbStack[] = isset($fdBlocks) ? $fdBlocks : null;
            $fdBlocks = is_array($col) ? $col : [];
            include __DIR__ . '/../render_blocks.tpl';
            $fdBlocks = array_pop($fdbStack);
            ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
