<?php
// Render only when: logged in, no IDP link yet, dismiss cookie unset/expired.
if (empty($LoggedIn) || !empty($IdpLinked) || !empty($IdpNudgeDismissed)) {
    return;
}
?>
<style>
    .idp-nudge { background:#f6efe2; border:1px solid #d8cba4; border-radius:8px; padding:18px 20px; margin:16px 0; display:flex; flex-direction:column; gap:10px; }
    .idp-nudge h3 { background:transparent; border:none; padding:0; border-radius:0; text-shadow:none; font-size:1.05rem; font-weight:600; margin:0; color:#3a2e10; }
    .idp-nudge p { margin:0; color:#5b4a1f; font-size:.92rem; line-height:1.4; }
    .idp-nudge-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .idp-nudge form { margin:0; }
    .idp-nudge .btn-primary { background:#7a5b1c; color:#fff; padding:8px 14px; border-radius:4px; text-decoration:none; font-weight:600; border:none; cursor:pointer; font-size:.92rem; }
    .idp-nudge .btn-primary:hover { background:#5d4615; }
    .idp-nudge .btn-ghost { background:transparent; color:#5b4a1f; padding:8px 12px; border-radius:4px; text-decoration:none; font-weight:500; border:1px solid transparent; cursor:pointer; font-size:.92rem; }
    .idp-nudge .btn-ghost:hover { border-color:#d8cba4; }
    html[data-theme="dark"] .idp-nudge { background:#3a2e10; border-color:#5d4615; }
    html[data-theme="dark"] .idp-nudge h3 { color:#f6efe2; }
    html[data-theme="dark"] .idp-nudge p { color:#d8cba4; }
    html[data-theme="dark"] .idp-nudge .btn-ghost { color:#d8cba4; }
    html[data-theme="dark"] .idp-nudge .btn-ghost:hover { border-color:#7a5b1c; }
</style>
<div class="idp-nudge" role="region" aria-label="Set up Amtgard sign-in">
    <h3>Speed up next time &mdash; set up your Amtgard sign-in</h3>
    <p>Sign in faster on your next visit by connecting your ORK profile to your Amtgard sign-in. You'll be able to use Google, Discord, or a password &mdash; and we'll remember you.</p>
    <div class="idp-nudge-actions">
        <form method="POST" action="<?= UIR ?>Login/start_idp_connect">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CsrfToken ?? '', ENT_QUOTES) ?>">
            <button type="submit" class="btn-primary">Set it up now</button>
        </form>
        <form method="POST" action="<?= UIR ?>Login/nudge_dismiss">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CsrfToken ?? '', ENT_QUOTES) ?>">
            <button type="submit" class="btn-ghost">Not now</button>
        </form>
    </div>
</div>
