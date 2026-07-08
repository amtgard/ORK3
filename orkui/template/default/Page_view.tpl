<?php
/*
 * CMS page view — renders a published page's blocks through the shared
 * frontdoor/render_blocks.tpl partial, so CMS pages inherit front-door styling.
 * $FrontDoor = ordered enabled blocks (from Controller_Page::view).
 * $Message   = set (e.g. "Page not found.") when there is no page to render.
 */
$fdDir       = DIR_TEMPLATE . 'default/frontdoor/';
$fdAssetBase = HTTP_TEMPLATE . 'default/frontdoor/';
$fdBlocks    = isset( $FrontDoor ) && is_array( $FrontDoor ) ? $FrontDoor : [];
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/frontdoor.css?v=<?= @filemtime( $fdDir . 'css/frontdoor.css' ) ?>">

<?php if ( ! empty( $Message ) && empty( $fdBlocks ) ) : ?>
<div class="fd-page">
<?php include $fdDir . 'site_header.tpl'; ?>
	<p style="padding:2rem;text-align:center;"><?= htmlspecialchars( (string) $Message ) ?></p>
</div>
<?php else : ?>
<div class="fd-page">
<?php include $fdDir . 'site_header.tpl'; ?>
<?php
// Wayfinding breadcrumbs: Home › ancestors › current (current is plain text).
$fdCrumbAncestors = (isset($PageAncestors) && is_array($PageAncestors)) ? $PageAncestors : [];
$fdCrumbCurrent   = (isset($CurrentPage) && is_array($CurrentPage)) ? (string) ($CurrentPage['title'] ?? '') : '';
if ($fdCrumbCurrent !== ''):
?>
<nav class="fd-breadcrumbs" aria-label="Breadcrumb">
	<a href="/orkui/index.php">Home</a>
	<?php foreach ($fdCrumbAncestors as $fdAnc): ?>
		<?php if (!is_array($fdAnc)) { continue; } ?>
		<span class="fd-crumb-sep" aria-hidden="true">&rsaquo;</span>
		<a href="<?= htmlspecialchars(UIR . 'Page/view/' . rawurlencode((string) ($fdAnc['slug'] ?? '')), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($fdAnc['title'] ?? ''), ENT_QUOTES) ?></a>
	<?php endforeach; ?>
	<span class="fd-crumb-sep" aria-hidden="true">&rsaquo;</span>
	<span class="fd-crumb-current" aria-current="page"><?= htmlspecialchars($fdCrumbCurrent, ENT_QUOTES) ?></span>
</nav>
<?php endif; ?>
<?php include $fdDir . 'render_blocks.tpl'; ?>
</div>
<?php endif; ?>
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
