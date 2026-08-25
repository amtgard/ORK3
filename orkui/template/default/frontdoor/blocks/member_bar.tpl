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
    <?php /* Only for viewers with an actual admin surface: base Controller sets
             $menu['admin'] solely for ORK admins / kingdom officers / park
             officers, already pointed at their most-specific Admin page. A
             plain member got a link to an Admin page that does nothing for
             them, so no flag -> no link. */ ?>
    <?php if (!empty($menu['admin']['url'])): ?>
        <a href="<?= htmlspecialchars($menu['admin']['url'], ENT_QUOTES) ?>"><i class="fas fa-tools"></i>Member Tools</a>
    <?php endif; ?>
</div>
