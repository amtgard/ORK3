<?php
/**
 * Partial: member_bar.tpl
 * Receives: $blockFields (empty), $LoggedIn, $ViewerName, $UserKingdomId, UIR
 * Renders NOTHING unless the user is logged in.
 *
 * CSS (light + dark) lives in frontdoor/css/blocks.css's sibling sheet
 * frontdoor/css/frontdoor.css, under .fd-member-bar — it has to sit there rather
 * than in blocks.css because the phone-width override lives in frontdoor.css and
 * would otherwise lose the same-specificity tie to the later-loaded sheet.
 */
if (empty($LoggedIn)) {
    return;
}
?>
<div class="fd-member-bar">
    <span class="fd-member-bar-greeting">
        Welcome back,
        <?php if (!empty($ViewerName)): ?>
            <b class="fd-serif"><?= htmlspecialchars($ViewerName, ENT_QUOTES) ?></b>
        <?php endif; ?>
    </span>
    <span class="fd-member-bar-spacer"></span>
    <?php if ((int)($UserKingdomId ?? 0) > 0): ?>
        <a href="<?= htmlspecialchars(UIR . 'Kingdom/profile/' . (int)$UserKingdomId, ENT_QUOTES) ?>"><i class="fas fa-crown"></i>My Kingdom</a>
    <?php endif; ?>
    <a href="<?= htmlspecialchars(UIR . 'Live', ENT_QUOTES) ?>"><i class="fas fa-broadcast-tower"></i>Live Attendance</a>
    <?php /* True ORK admins only — the Admin landing page itself is gated on
             ORK-admin privileges, so kingdom/park officers (who match the
             broader $menu['admin'] flag) would click through to a page they
             cannot use. NavIsOrkAdmin is base Controller's
             AUTH_ADMIN/0/AUTH_ADMIN probe. */ ?>
    <?php if (!empty($NavIsOrkAdmin)): ?>
        <a href="<?= htmlspecialchars(UIR . 'Admin', ENT_QUOTES) ?>"><i class="fas fa-tools"></i>Member Tools</a>
    <?php endif; ?>
</div>
