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
</style>

<div class="cms-preview-banner">
	<span class="cms-preview-badge"><?= $pvStatus === 'published' ? 'Published' : 'Unpublished' ?> · Preview</span>
	<?php if ( $pvTitle !== '' ) : ?><span class="cms-preview-title"><?= htmlspecialchars( $pvTitle ) ?></span><?php endif; ?>
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
