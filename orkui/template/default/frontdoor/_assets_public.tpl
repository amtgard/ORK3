<?php
/*
 * _assets_public.tpl — the public-side CMS stylesheet set, in cascade order.
 * PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * ORDER IS LOAD-BEARING: blocks.css and blog.css were split off the end of
 * frontdoor.css and several of their rules win same-specificity ties against
 * it. Do not reorder.
 *
 * Safe on EVERY public CMS surface, standalone org sites included — nothing
 * here names an ORK selector. The ORK-shell interop layer is a separate
 * partial (_assets_inshell.tpl) that org sites deliberately do not include.
 *
 * Expects $fdDir (filesystem) and $fdAssetBase (URL) already in scope.
 */
$fdCssSet = array('frontdoor.css', 'blocks.css', 'blog.css');
foreach ($fdCssSet as $fdCssFile) :
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/<?= $fdCssFile ?>?v=<?= @filemtime($fdDir . 'css/' . $fdCssFile) ?>">
<?php endforeach; ?>
