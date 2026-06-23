<?php
/**
 * Partial: table.tpl
 * Receives: $blockFields (caption?, rows[][] cells, header_first_row bool), UIR
 * Renders a responsive (overflow-x:auto) HTML table; every cell escaped.
 * Self-contained scoped styles (light + dark). No JS.
 */
$caption        = $blockFields['caption'] ?? '';
$rows           = $blockFields['rows'] ?? [];
$headerFirstRow = !empty($blockFields['header_first_row']);
if (!is_array($rows)) {
    $rows = [];
}
?>
<style>
.fdb-table-scroll {
    max-width: 920px;
    margin: 0 auto;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.fdb-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 15px;
    color: var(--ink, #1a2236);
    background: transparent;
}
.fdb-table caption {
    caption-side: top;
    text-align: left;
    font-size: 13px;
    color: #667;
    padding-bottom: 8px;
    font-style: italic;
}
.fdb-table th,
.fdb-table td {
    border: 1px solid #e2e6ef;
    padding: 9px 13px;
    text-align: left;
    vertical-align: top;
}
.fdb-table thead th {
    background: #f7f8fb;
    font-weight: 600;
    border-bottom: 2px solid #d6dceb;
}
.fdb-table tbody tr:nth-child(even) td {
    background: #fafbfd;
}
html[data-theme="dark"] .fdb-table { color: #eef2fb; }
html[data-theme="dark"] .fdb-table caption { color: #9aa7c4; }
html[data-theme="dark"] .fdb-table th,
html[data-theme="dark"] .fdb-table td {
    border-color: #2a3450;
}
html[data-theme="dark"] .fdb-table thead th {
    background: #1b2236;
    border-bottom-color: #3a4566;
}
html[data-theme="dark"] .fdb-table tbody tr:nth-child(even) td {
    background: #131a29;
}
</style>
<?php if (!empty($rows)): ?>
<div class="fd-pad">
    <div class="fdb-table-scroll">
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
