<?php
/**
 * Partial: accordion.tpl
 * Receives: $blockFields (items[] each {q, a}), UIR
 * Collapsible Q&A using native <details>/<summary> — no JS required.
 * Self-contained scoped styles (light + dark).
 */
$items = $blockFields['items'] ?? [];
if (!is_array($items)) {
    $items = [];
}
?>
<style>
.fdb-accordion {
    max-width: 760px;
    margin: 0 auto;
}
.fdb-accordion-item {
    border: 1px solid #e2e6ef;
    border-radius: 8px;
    margin-bottom: 10px;
    background: #fff;
    overflow: hidden;
}
.fdb-accordion-item > summary {
    cursor: pointer;
    list-style: none;
    padding: 14px 18px;
    font-weight: 600;
    font-size: 16px;
    color: var(--ink, #1a2236);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.fdb-accordion-item > summary::-webkit-details-marker { display: none; }
.fdb-accordion-item > summary::after {
    content: "+";
    font-size: 20px;
    line-height: 1;
    color: var(--gold, #f0b429);
    transition: transform .15s ease;
}
.fdb-accordion-item[open] > summary::after {
    content: "\2212"; /* minus */
}
.fdb-accordion-answer {
    padding: 0 18px 16px;
    font-size: 15px;
    line-height: 1.6;
    color: #444c5e;
}
html[data-theme="dark"] .fdb-accordion-item {
    background: #161d2e;
    border-color: #2a3450;
}
html[data-theme="dark"] .fdb-accordion-item > summary {
    color: #eef2fb;
}
html[data-theme="dark"] .fdb-accordion-answer {
    color: #c8d3ea;
}
</style>
<?php if (!empty($items)): ?>
<div class="fd-pad">
    <div class="fdb-accordion">
        <?php foreach ($items as $item): ?>
            <?php
            $q = $item['q'] ?? '';
            $a = $item['a'] ?? '';
            if ($q === '' && $a === '') {
                continue;
            }
            ?>
            <details class="fdb-accordion-item">
                <summary><?= htmlspecialchars($q, ENT_QUOTES) ?></summary>
                <div class="fdb-accordion-answer"><?= htmlspecialchars($a, ENT_QUOTES) ?></div>
            </details>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
