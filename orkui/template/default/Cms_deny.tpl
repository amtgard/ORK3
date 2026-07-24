<?php
/**
 * Cms_deny.tpl — bare-chrome "you don't have permission" page (#109).
 *
 * PLAIN PHP (extract()+include), NOT Smarty. Deliberately self-contained: a
 * denied viewer holds no CMS scope to build the CMS shell from, so this page
 * ships its own inline styles (light + dark) and does NOT go through the themed
 * View pipeline. Controller_Cms::_denyPermission() sets the 403 + X-Robots-Tag
 * headers and includes this file directly, then exits.
 *
 * Receives: $HomeUrl (string) — the "Return to ORK" link target.
 */
$denyHome = htmlspecialchars((string)($HomeUrl ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Permission needed — Content Management</title>
<style>
:root { color-scheme: light dark; }
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
.deny-btn {
    display: inline-block; padding: 11px 20px; border-radius: 9px; background: #1f3a6e; color: #fff;
    text-decoration: none; font-weight: 600; font-size: 14px;
}
.deny-btn:hover { background: #264a8c; }
@media (prefers-color-scheme: dark) {
    body { background: #0e1420; color: #e7ecf5; }
    .deny-card { background: #161d2b; border-color: #27324a; box-shadow: 0 10px 30px rgba(0, 0, 0, .4); }
    .deny-badge { background: #1d2740; color: #8fb2ff; }
    .deny-card h1 { color: #f1f5ff; }
    .deny-card p { color: #aab6cf; }
    .deny-btn { background: #2d5bb8; }
    .deny-btn:hover { background: #3a6dd6; }
}
</style>
</head>
<body>
<main class="deny-card">
    <div class="deny-badge">&#128274;</div>
    <h1>You don&rsquo;t have permission to manage this site</h1>
    <p>You&rsquo;re signed in, but your role doesn&rsquo;t include access to the
        Content Management tools for this site.</p>
    <p>Ask your monarch or regent (a kingdom administrator) to grant you CMS access,
        then reload this page.</p>
    <div class="deny-actions"><a class="deny-btn" href="<?= $denyHome ?>">Return to ORK</a></div>
</main>
</body>
</html>
