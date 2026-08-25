<?php
/**
 * Partial: quote.tpl
 * Receives: $blockFields (text, cite), UIR
 * Styled pull-quote with a gold accent rail. CSS (light + dark) lives in
 * frontdoor/css/blocks.css. No JS.
 */
$text = $blockFields['text'] ?? '';
$cite = $blockFields['cite'] ?? '';
?>
<?php if ($text !== ''): ?>
<div class="fd-pad fdb-quote-wrap">
    <blockquote class="fdb-quote">
        <span class="fdb-quote-text"><?= htmlspecialchars($text, ENT_QUOTES) ?></span>
        <?php if ($cite !== ''): ?>
            <cite class="fdb-quote-cite"><?= htmlspecialchars($cite, ENT_QUOTES) ?></cite>
        <?php endif; ?>
    </blockquote>
</div>
<?php endif; ?>
