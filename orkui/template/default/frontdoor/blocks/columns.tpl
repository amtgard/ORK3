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
?>
<?php if ($fdbCount > 0): ?>
<style>
/* scoped: fdb-columns */
.fdb-columns {
    display: grid;
    grid-template-columns: repeat(<?= (int) $fdbCount ?>, 1fr);
    gap: 22px;
    align-items: start;
}
.fdb-columns > .fdb-columns-col { min-width: 0; }
@media (max-width: 760px) {
    .fdb-columns { grid-template-columns: 1fr; gap: 14px; }
}
</style>
<div class="fd-pad fdb-columns">
    <?php foreach ($fdbColumns as $col): ?>
        <div class="fdb-columns-col">
            <?php
            // Recurse into the shared renderer for this column's blocks.
            // render_blocks.tpl reads $fdBlocks; restore the outer value after.
            $fdbOuterBlocks = isset($fdBlocks) ? $fdBlocks : null;
            $fdBlocks = is_array($col) ? $col : [];
            include __DIR__ . '/../render_blocks.tpl';
            $fdBlocks = $fdbOuterBlocks;
            ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
