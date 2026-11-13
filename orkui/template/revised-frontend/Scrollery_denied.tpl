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
 * pill box in BOTH themes, because nothing on the page reset it. .rp-header-title
 * carries its own reset in reports.css, in both the light and the dark block, so the
 * shell fixes the heading as a side effect of being the right shell.
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
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery-list.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/scrollery-list.css') ?>">
<style>
.sc-wrap      { max-width: 720px; margin: 0 auto 60px; }
/* .sc-alert / .sc-alert-error come from scrollery-list.css, shared with Bulk. */
.sc-next      { margin-top: 18px; text-align: left; }
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
            <div class="sc-alert sc-alert-error" role="alert">
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
