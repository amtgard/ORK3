<?php
/**
 * Cms_deny.tpl — bare-chrome "you don't have permission" page.
 *
 * PLAIN PHP (extract()+include), NOT Smarty. Deliberately self-contained: a
 * denied viewer holds no CMS scope to build the CMS shell from, so this page
 * ships its own inline styles (light + dark) and does NOT go through the themed
 * View pipeline. Dark mode honours the explicit ork_theme choice via the inline
 * bootstrap (same one as default.theme) and falls back to prefers-color-scheme
 * when JS/localStorage is unavailable, so the page still renders dark with no JS. Controller_Cms::_denyPermission() sets the 403 + X-Robots-Tag
 * headers and includes this file directly, then exits.
 *
 * Receives: $HomeUrl (string) — the "Return to ORK" link target.
 *           $MissingCapability (string) — the single named capability the
 *           user lacked, or '' when unknown (scope failure / any-of gate), in
 *           which case the page shows only the generic copy.
 */
$denyHome = htmlspecialchars((string)($HomeUrl ?? ''), ENT_QUOTES, 'UTF-8');
$denyCap = htmlspecialchars((string)($MissingCapability ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Permission needed — OGRE</title>
<script>
(function(){var t=localStorage.getItem('ork_theme');if(t==='dark'){document.documentElement.setAttribute('data-theme','dark');}else if(t==='light'){document.documentElement.setAttribute('data-theme','light');}else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches){document.documentElement.setAttribute('data-theme','dark');}})();
</script>
<style>
:root { color-scheme: light dark; }
html[data-theme="dark"] { color-scheme: dark; }
html[data-theme="light"] { color-scheme: light; }
body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: #f4f6fa; color: #1b2333; padding: 24px; box-sizing: border-box;
}
.deny-card {
    max-width: 520px; width: 100%; background: #fff; border: 1px solid #e2e7f0; border-radius: 14px;
    box-shadow: 0 10px 30px rgba(20, 32, 60, .08); padding: 36px 34px; text-align: center; box-sizing: border-box;
}
.deny-badge {
    width: 60px; height: 60px; border-radius: 50%; background: #eef2fb; color: #1f3a6e;
    display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 0 auto 18px;
}
.deny-card h1 { font-size: 21px; margin: 0 0 12px; color: #12213f; background: none; border: 0; padding: 0; border-radius: 0; }
.deny-card p { font-size: 15px; line-height: 1.55; margin: 0 0 14px; color: #41506b; }
.deny-card .deny-actions { margin-top: 22px; }
.deny-cap {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px;
    background: #eef2fb; color: #1f3a6e; border-radius: 5px; padding: 2px 6px;
}
.deny-btn {
    display: inline-block; padding: 11px 20px; border-radius: 9px; background: #1f3a6e; color: #fff;
    text-decoration: none; font-weight: 600; font-size: 14px;
}
.deny-btn:hover { background: #264a8c; }
html[data-theme="dark"] body { background: #0e1420; color: #e7ecf5; }
html[data-theme="dark"] .deny-card { background: #161d2b; border-color: #27324a; box-shadow: 0 10px 30px rgba(0, 0, 0, .4); }
html[data-theme="dark"] .deny-badge { background: #1d2740; color: #8fb2ff; }
html[data-theme="dark"] .deny-card h1 { color: #f1f5ff; }
html[data-theme="dark"] .deny-card p { color: #aab6cf; }
html[data-theme="dark"] .deny-cap { background: #1d2740; color: #8fb2ff; }
html[data-theme="dark"] .deny-btn { background: #2d5bb8; }
html[data-theme="dark"] .deny-btn:hover { background: #3a6dd6; }
/* No-JS / blocked-localStorage fallback: the inline bootstrap above cannot set
   data-theme, so honour the OS. Scoped with :not([data-theme="light"]) so it can
   never override an explicit light choice. */
@media (prefers-color-scheme: dark) {
    html:not([data-theme="light"]) body { background: #0e1420; color: #e7ecf5; }
    html:not([data-theme="light"]) .deny-card { background: #161d2b; border-color: #27324a; box-shadow: 0 10px 30px rgba(0, 0, 0, .4); }
    html:not([data-theme="light"]) .deny-badge { background: #1d2740; color: #8fb2ff; }
    html:not([data-theme="light"]) .deny-card h1 { color: #f1f5ff; }
    html:not([data-theme="light"]) .deny-card p { color: #aab6cf; }
    html:not([data-theme="light"]) .deny-cap { background: #1d2740; color: #8fb2ff; }
    html:not([data-theme="light"]) .deny-btn { background: #2d5bb8; }
    html:not([data-theme="light"]) .deny-btn:hover { background: #3a6dd6; }
}
</style>
</head>
<body>
<main class="deny-card">
    <div class="deny-badge">&#128274;</div>
    <h1>You don&rsquo;t have permission to manage this site</h1>
    <p>You&rsquo;re signed in, but your role doesn&rsquo;t include access to
        OGRE &mdash; the Online Gallery and Resource Engine &mdash; for this site.</p>
<?php if ($denyCap !== '') : ?>
    <p>This page needs the <span class="deny-cap"><?= $denyCap ?></span> permission.</p>
<?php endif; ?>
    <p>Ask your monarch or regent (a kingdom administrator) to grant you OGRE access,
        then reload this page.</p>
    <div class="deny-actions"><a class="deny-btn" href="<?= $denyHome ?>">Return to ORK</a></div>
</main>
</body>
</html>
