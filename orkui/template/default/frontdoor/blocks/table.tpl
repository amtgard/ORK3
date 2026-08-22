<?php
/**
 * Partial: table.tpl
 * Receives: $blockFields (caption?, rows[][] cells, header_first_row bool), UIR
 * Renders a responsive (overflow-x:auto) HTML table; every cell escaped.
 * CSS (light + dark) lives in frontdoor/css/blocks.css. No JS.
 */
$caption        = $blockFields['caption'] ?? '';
$rows           = $blockFields['rows'] ?? [];
$headerFirstRow = !empty($blockFields['header_first_row']);
if (!is_array($rows)) {
    $rows = [];
}
?>
<?php if (!empty($rows)): ?>
<div class="fd-pad">
    <div class="fdb-table-scroll" tabindex="0" role="region" aria-label="<?= htmlspecialchars($caption !== '' ? $caption : 'Table', ENT_QUOTES) ?>">
        <table class="fdb-table">
            <?php if ($caption !== ''): ?>
                <caption><?= htmlspecialchars($caption, ENT_QUOTES) ?></caption>
            <?php endif; ?>
            <?php
            $bodyRows = $rows;
            if ($headerFirstRow):
                $headRow  = array_shift($bodyRows);
                $headRow  = is_array($headRow) ? $headRow : [];
                ?>
                <thead>
                    <tr>
                        <?php foreach ($headRow as $cell): ?>
                            <th scope="col"><?= htmlspecialchars((string) $cell, ENT_QUOTES) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
            <?php endif; ?>
            <tbody>
                <?php foreach ($bodyRows as $row): ?>
                    <?php $row = is_array($row) ? $row : []; ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?= htmlspecialchars((string) $cell, ENT_QUOTES) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
