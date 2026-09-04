<?php
/**
 * Shown when a logged-in viewer asks for a park or kingdom they cannot issue for.
 * Controller::no_authorization() has already put the message in $Error and, for a
 * genuinely logged-out visitor, redirected to the login page before reaching here.
 */
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<div class="adm-wrap" style="max-width:720px;margin:0 auto">
    <div class="adm-page-header"><h1>Scrollery</h1></div>
    <div class="adm-card" style="padding:22px">
        <div class="adm-alert adm-alert-error">
            <?= htmlspecialchars((string) ($Error ?? 'You do not have permission to print scrolls for that park or kingdom.'), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <p style="margin-top:16px"><a class="adm-btn adm-btn-ghost" href="<?= UIR ?>Scrollery">Back to Scrollery</a></p>
    </div>
</div>
