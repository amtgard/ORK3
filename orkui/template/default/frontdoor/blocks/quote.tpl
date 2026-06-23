<?php
/**
 * Partial: quote.tpl
 * Receives: $blockFields (text, cite), UIR
 * Styled pull-quote with a gold accent rail. Self-contained scoped styles (light + dark). No JS.
 */
$text = $blockFields['text'] ?? '';
$cite = $blockFields['cite'] ?? '';
?>
<style>
.fdb-quote-wrap { display: flex; justify-content: center; }
.fdb-quote {
    position: relative;
    max-width: 720px;
    margin: 0;
    padding: 6px 0 6px 26px;
    border-left: 4px solid var(--gold, #f0b429);
    font-family: Georgia, "Times New Roman", serif;
    font-size: 24px;
    line-height: 1.45;
    font-style: italic;
    color: var(--ink, #1a2236);
}
.fdb-quote-text::before { content: "\201C"; }
.fdb-quote-text::after  { content: "\201D"; }
.fdb-quote-cite {
    display: block;
    margin-top: 12px;
    font-size: 14px;
    font-style: normal;
    font-family: inherit;
    color: #667;
    letter-spacing: .02em;
}
.fdb-quote-cite::before { content: "\2014\00A0"; }
html[data-theme="dark"] .fdb-quote { color: #eef2fb; }
html[data-theme="dark"] .fdb-quote-cite { color: #9aa7c4; }
</style>
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
