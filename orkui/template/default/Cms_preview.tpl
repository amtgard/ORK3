<?php
/*
 * CMS draft preview — renders a page's CURRENT (draft) enabled blocks through the
 * shared frontdoor/render_blocks.tpl, with an "Unpublished — Preview" banner.
 * $FrontDoor   = ordered enabled blocks (from Controller_Cms::preview).
 * $PreviewPage = the page row (or null); $Message set when not found.
 */
$fdDir       = DIR_TEMPLATE . 'default/frontdoor/';
$fdAssetBase = HTTP_TEMPLATE . 'default/frontdoor/';
$fdBlocks    = isset($FrontDoor) && is_array($FrontDoor) ? $FrontDoor : [];
$pvTitle     = (! empty($PreviewPage) && isset($PreviewPage['title'])) ? (string) $PreviewPage['title'] : '';
$pvStatus    = (! empty($PreviewPage) && isset($PreviewPage['status'])) ? (string) $PreviewPage['status'] : 'draft';
$pvCanPublish = ! empty($CanPublish) && $pvStatus !== 'published';
$pvKind       = (isset($PreviewKind) && $PreviewKind === 'postrow') ? 'post' : 'page';
$pvId         = ($pvKind === 'post') ? (int) ($PreviewPage['post_id'] ?? 0) : (int) ($PreviewPage['page_id'] ?? 0);
$pvScopeQuery = isset($CmsScopeQuery) ? (string) $CmsScopeQuery : '';
// E2: set by CmsAjax/previewblocks, which renders the editor's UNSAVED blocks.
// The badge has to say so — "Unpublished · Preview" reads as "this is the draft
// on record", and it isn't; nothing on screen has been saved yet.
$pvLive       = ! empty($PreviewLive);
$pvPublishUrl = UIR . ($pvKind === 'post' ? 'CmsAjax/publishpost' : 'CmsAjax/publish') . $pvScopeQuery;
$pvIdField    = ($pvKind === 'post') ? 'post_id' : 'page_id';
?>
<?php include $fdDir . '_assets_public.tpl'; ?>
<?php if (! $pvLive) : // The live preview (E2) renders as a STANDALONE public
    // document ($IsOrgSite), so it loads no orkui.css and the ORK-shell interop
    // layer has nothing to interop with. The saved preview still renders inside
    // the application shell and still needs it.
    ?>
<?php include $fdDir . '_assets_inshell.tpl'; ?>
<?php endif; ?>
<?php
// .cms-preview-* styling lives in frontdoor/css/blocks.css, which the
// _assets_public.tpl include above links.
?>

<div class="cms-preview-banner">
	<span class="cms-preview-badge"><?= $pvLive ? 'Unsaved' : ($pvStatus === 'published' ? 'Published' : 'Unpublished') ?> · <?= $pvLive ? 'Live preview' : 'Preview' ?></span>
	<?php if ($pvTitle !== '') : ?><span class="cms-preview-title"><?= htmlspecialchars($pvTitle) ?></span><?php endif; ?>
	<?php if ($pvCanPublish && $pvId > 0) : ?>
		<button type="button" class="cms-preview-publish" id="cmsPreviewPublish"
			data-endpoint="<?= htmlspecialchars($pvPublishUrl, ENT_QUOTES) ?>"
			data-field="<?= htmlspecialchars($pvIdField, ENT_QUOTES) ?>"
			data-id="<?= $pvId ?>"><i class="fas fa-globe"></i> Publish</button>
	<?php endif; ?>
</div>

<?php if (! empty($Message) && empty($fdBlocks)) : ?>
<div class="fd-page">
	<p style="padding:2rem;text-align:center;"><?= htmlspecialchars((string) $Message) ?></p>
</div>
<?php else : ?>
<div class="fd-page">
<?php include $fdDir . 'render_blocks.tpl'; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js?v=<?= @filemtime($fdDir . 'js/frontdoor.js') ?>"></script>
<?php endif; ?>

<?php if ($pvCanPublish && $pvId > 0) : ?>
<script>
window.CMS_CSRF = "<?= htmlspecialchars($CmsCsrf ?? '', ENT_QUOTES) ?>";
(function () {
	var btn = document.getElementById('cmsPreviewPublish');
	if (!btn) { return; }
	btn.addEventListener('click', function () {
		var endpoint = btn.getAttribute('data-endpoint');
		var field    = btn.getAttribute('data-field');
		var id       = btn.getAttribute('data-id');
		btn.disabled = true;
		btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publishing\u2026';
		fetch(endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.CMS_CSRF || '') },
			body: field + '=' + encodeURIComponent(id)
		}).then(function (r) {
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		}).then(function (d) {
			if (d && d.ok === true) { location.reload(); }
			else { throw new Error('publish failed'); }
		}).catch(function () {
			btn.disabled = false;
			btn.innerHTML = '<i class="fas fa-globe"></i> Publish \u2014 retry';
		});
	});
})();
</script>
<?php endif; ?>
