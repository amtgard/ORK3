<?php
/**
 * Partial: member_bar.tpl
 * Receives: $blockFields (empty), $LoggedIn, $ViewerName, $UserKingdomId, UIR
 * Renders NOTHING unless the user is logged in.
 */
if (empty($LoggedIn)) {
    return;
}
?>
<div class="fd-member-bar"
     style="background:var(--navy2);color:#fff;padding:9px 24px;display:flex;align-items:center;gap:18px;font-size:13px;">
    <span style="opacity:.85;">
        Welcome back,
        <?php if (!empty($ViewerName)): ?>
            <b class="fd-serif" style="font-weight:400;font-size:15px;"><?= htmlspecialchars($ViewerName, ENT_QUOTES) ?></b>
        <?php endif; ?>
    </span>
    <span style="flex:1;"></span>
    <?php if ((int)$UserKingdomId > 0): ?>
        <a href="<?= htmlspecialchars(UIR . 'Kingdom/profile/' . (int)$UserKingdomId, ENT_QUOTES) ?>"
           style="color:#cdd7ee;text-decoration:none;">&#9876; My Kingdom</a>
    <?php endif; ?>
    <a href="<?= htmlspecialchars(UIR . 'Live', ENT_QUOTES) ?>"
       style="color:#cdd7ee;text-decoration:none;">&#128225; Live Attendance</a>
    <a href="<?= htmlspecialchars(UIR . 'Admin', ENT_QUOTES) ?>"
       style="color:#cdd7ee;text-decoration:none;">&#128295; Member Tools</a>
</div>
