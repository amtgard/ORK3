<?php
/**
 * Shown when a print cannot happen — and it now says WHY.
 *
 * Every dead end in the module used to be `header('Location: …Scrollery'); exit;`
 * with an empty body. Controller_Scrollery::deny() routes all of them here instead,
 * with $Error carrying the real sentence: for a render refusal that comes straight
 * from Model_Scrollery::print_decision(), whose Message is never empty on a denial,
 * so it is rendered as-is rather than second-guessed.
 *
 * $NextUrl / $NextLabel are the recovery. They default to the landing page, which is
 * right for a plain permission refusal; compose()'s "that award has no certificate"
 * branch sends the officer back to the composer instead.
 *
 * The page wears the shared .rp-* tool-page shell from default/style/reports.css,
 * like its three siblings. It used to be the only page in the module that did not —
 * a bare .adm-wrap whose <h1> therefore picked up the orkui.css global h1..h6 grey
 * pill box in BOTH themes, because the .adm-page-header h1 reset lives in
 * scrollery.css and this page never loaded it. .rp-header-title carries its own
 * reset in reports.css, in both the light and the dark block, so the shell fixes the
 * heading as a side effect of being the right shell.
 */
$error     = trim((string) ($Error ?? ''));
$nextUrl   = (string) ($NextUrl ?? 'Scrollery');
$nextLabel = (string) ($NextLabel ?? 'Back to Scrollery');

if ($error === '') {
    $error = 'You do not have permission to print scrolls for that park or kingdom.';
}
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<style>
.sc-wrap      { max-width: 720px; margin: 0 auto 60px; }
/* reports.css carries no alert primitive; this is the one tone this page needs, and
   it is the same shape Scrollery_bulk.tpl uses so the module reads as one module. */
.sc-alert     { border: 1px solid var(--rp-border); border-left: 4px solid #c53030;
                background: var(--rp-bg-light); border-radius: 6px; padding: 12px 16px;
                font-size: 13px; line-height: 1.6; color: var(--rp-text-body);
                text-align: left; }
.sc-next      { margin-top: 18px; text-align: left; }
/* The dark border-left has to be restated: the shorthand below would otherwise reset
   all four sides and the alert would lose the red edge that makes it an alert. */
html[data-theme="dark"] .sc-alert { background: #2d3748; border-color: #4a5568;
                                    border-left-color: #fc8181; color: #e2e8f0; }
</style>

<div class="rp-root sc-wrap">
    <div class="rp-header">
        <div class="rp-header-left">
            <div class="rp-header-icon-title">
                <i class="fa-solid fa-scroll rp-header-icon"></i>
                <h1 class="rp-header-title">Scrollery</h1>
            </div>
        </div>
        <div class="rp-header-actions">
            <a class="rp-btn-ghost" href="<?= UIR ?>Scrollery">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="rp-body">
        <div class="rp-table-area">
            <div class="sc-alert" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <p class="sc-next">
                <a class="adm-btn adm-btn-primary" href="<?= UIR . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($nextLabel, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </p>
        </div>
    </div>
</div>
