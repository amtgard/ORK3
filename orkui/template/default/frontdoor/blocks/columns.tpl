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
<?php // THE ONE BLOCK THAT KEEPS AN INLINE <style>. Every other block's CSS was
      // lifted into frontdoor/css/blocks.css; this one cannot be, because it
      // interpolates $fdbCount into grid-template-columns. .fdb-columns is a
      // single global selector, so with several columns blocks on one page the
      // LAST emission wins and sets the column count for all of them — and its
      // @media partner has to stay in this same <style> element, after the base
      // rule, or a stylesheet copy loaded earlier would lose the order tie and
      // the phone breakpoint would stop collapsing to one column.
      //
      // Deliberately NOT deduped: deduping by block type would drop later
      // emissions and silently re-flow earlier blocks; deduping by count would
      // reorder which emission is last. Both change rendering. Fix properly
      // (a per-count class, or an inline style on the wrapper) first. ?>
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
            // Use a depth-safe stack so nested columns blocks (a columns block
            // inside a columns block) don't clobber each other's outer context.
            static $fdbStack = [];
            $fdbStack[] = isset($fdBlocks) ? $fdBlocks : null;
            $fdBlocks = is_array($col) ? $col : [];
            include __DIR__ . '/../render_blocks.tpl';
            $fdBlocks = array_pop($fdbStack);
            ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
