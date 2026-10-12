<?php
/**
 * Partial: accordion.tpl
 * Receives: $blockFields (items[] each {q, a}), UIR
 * Collapsible Q&A using native <details>/<summary> — no JS required.
 * CSS (light + dark) lives in frontdoor/css/blocks.css.
 */
$items = $blockFields['items'] ?? [];
if (!is_array($items)) {
    $items = [];
}
?>
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
