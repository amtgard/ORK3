<?php
/*
 * _assets_public.tpl — the public-side CMS stylesheet set, in cascade order.
 * PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * ORDER IS LOAD-BEARING: blocks.css and blog.css were split off the end of
 * frontdoor.css and several of their rules win same-specificity ties against
 * it. Do not reorder — blog.css must stay last.
 *
 * Safe on EVERY public CMS surface, standalone org sites included — nothing
 * here names an ORK selector. The ORK-shell interop layer is a separate
 * partial (_assets_inshell.tpl) that org sites deliberately do not include.
 *
 * Expects $fdDir (filesystem) and $fdAssetBase (URL) already in scope.
 *
 * OPT-IN: set $fdWantBlog = true BEFORE including this partial to add the blog
 * layer (blog.css). Only the three surfaces that actually emit .blog-* /
 * .blogp-* markup do so — Blog_index.tpl, Blog_post.tpl, and Site_shell.tpl in
 * its 'blog'/'post' modes. Everywhere else those 28 selectors matched nothing
 * (measured 0/28 on the front door, on CMS pages and on org-site home/page), so
 * the 6,219 bytes were pure dead weight. The flag is consumed (unset) below so
 * it can never leak into a later include on the same request.
 *
 * blocks.css is deliberately NOT conditional, even though its utilization on a
 * given render can be as low as 1/198 selectors (front door, measured). Block
 * presence is AUTHORED CONTENT, not a template property: the front door and
 * every CMS page are CMS-backed and can render any block type the instant an
 * author adds one, so keying the link off the current content would silently
 * un-style the very next edit. It is also a single cacheable file shared by all
 * six public surfaces, whereas the pre-refactor equivalent was inline CSS
 * re-sent in the HTML of every single page view.
 */
$fdCssSet = array('frontdoor.css', 'blocks.css');
if (! empty($fdWantBlog)) {
    $fdCssSet[] = 'blog.css';
}
unset($fdWantBlog);
foreach ($fdCssSet as $fdCssFile) :
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/<?= $fdCssFile ?>?v=<?= @filemtime($fdDir . 'css/' . $fdCssFile) ?>">
<?php endforeach; ?>
