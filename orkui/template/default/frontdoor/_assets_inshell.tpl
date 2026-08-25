<?php
/*
 * _assets_inshell.tpl — stylesheet links for CMS surfaces that render INSIDE the
 * ORK application shell (front door, CMS pages, blog index/post, CMS preview).
 * PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * Site_shell.tpl deliberately does NOT include this: a standalone org site does
 * not load orkui.css, so it needs no interop layer. bin/check-css-boundaries.sh
 * enforces that.
 *
 * Expects $fdDir (filesystem) and $fdAssetBase (URL) already in scope.
 */
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/orkshell-interop.css?v=<?= @filemtime($fdDir . 'css/orkshell-interop.css') ?>">
