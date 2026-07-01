<?php
/*
 * CMS draft preview — renders a page's CURRENT (draft) enabled blocks through the
 * shared frontdoor/render_blocks.tpl, with an "Unpublished — Preview" banner.
 * $FrontDoor   = ordered enabled blocks (from Controller_Cms::preview).
 * $PreviewPage = the page row (or null); $Message set when not found.
 */
$fdDir       = DIR_TEMPLATE . 'default/frontdoor/';
$fdAssetBase = HTTP_TEMPLATE . 'default/frontdoor/';
$fdBlocks    = isset( $FrontDoor ) && is_array( $FrontDoor ) ? $FrontDoor : [];
$pvTitle     = ( ! empty( $PreviewPage ) && isset( $PreviewPage['title'] ) ) ? (string) $PreviewPage['title'] : '';
$pvStatus    = ( ! empty( $PreviewPage ) && isset( $PreviewPage['status'] ) ) ? (string) $PreviewPage['status'] : 'draft';
$pvCanPublish = ! empty( $CanPublish ) && $pvStatus !== 'published';
$pvKind       = ( isset( $PreviewKind ) && $PreviewKind === 'postrow' ) ? 'post' : 'page';
$pvId         = ( $pvKind === 'post' ) ? (int) ( $PreviewPage['post_id'] ?? 0 ) : (int) ( $PreviewPage['page_id'] ?? 0 );
$pvScopeQuery = isset( $CmsScopeQuery ) ? (string) $CmsScopeQuery : '';
$pvPublishUrl = UIR . ( $pvKind === 'post' ? 'CmsAjax/publishpost' : 'CmsAjax/publish' ) . $pvScopeQuery;
$pvIdField    = ( $pvKind === 'post' ) ? 'post_id' : 'page_id';
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/frontdoor.css?v=<?= @filemtime( $fdDir . 'css/frontdoor.css' ) ?>">
<style>
.cms-preview-banner{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:12px;
  padding:10px 18px;background:#92400e;color:#fff;font-size:14px;font-weight:600;
  box-shadow:0 2px 8px rgba(0,0,0,.25);}
.cms-preview-banner .cms-preview-badge{background:rgba(0,0,0,.25);border-radius:5px;padding:2px 10px;
  text-transform:uppercase;letter-spacing:.06em;font-size:11px;}
.cms-preview-banner .cms-preview-title{opacity:.92;font-weight:500;}
html[data-theme="dark"] .cms-preview-banner{background:#78350f;}
.cms-preview-banner .cms-preview-publish{margin-left:auto;display:inline-flex;align-items:center;gap:7px;
  background:#16a34a;color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;
  cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:background .12s ease;}
.cms-preview-banner .cms-preview-publish:hover{background:#15803d;}
.cms-preview-banner .cms-preview-publish:disabled{opacity:.65;cursor:default;}
html[data-theme="dark"] .cms-preview-banner .cms-preview-publish{background:#15803d;}
html[data-theme="dark"] .cms-preview-banner .cms-preview-publish:hover{background:#166534;}
</style>

<div class="cms-preview-banner">
	<span class="cms-preview-badge"><?= $pvStatus === 'published' ? 'Published' : 'Unpublished' ?> · Preview</span>
	<?php if ( $pvTitle !== '' ) : ?><span class="cms-preview-title"><?= htmlspecialchars( $pvTitle ) ?></span><?php endif; ?>
	<?php if ( $pvCanPublish && $pvId > 0 ) : ?>
		<button type="button" class="cms-preview-publish" id="cmsPreviewPublish"
			data-endpoint="<?= htmlspecialchars( $pvPublishUrl, ENT_QUOTES ) ?>"
			data-field="<?= htmlspecialchars( $pvIdField, ENT_QUOTES ) ?>"
			data-id="<?= $pvId ?>"><i class="fas fa-globe"></i> Publish</button>
	<?php endif; ?>
</div>

<?php if ( ! empty( $Message ) && empty( $fdBlocks ) ) : ?>
<div class="fd-page">
	<p style="padding:2rem;text-align:center;"><?= htmlspecialchars( (string) $Message ) ?></p>
</div>
<?php else : ?>
<div class="fd-page">
<?php include $fdDir . 'render_blocks.tpl'; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
<?php endif; ?>

<?php if ( $pvCanPublish && $pvId > 0 ) : ?>
<script>
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
