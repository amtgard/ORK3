<?php
// Waiver_signature_widget.inc.php
// Renders a reusable signature widget. Pass $widgetId (unique per widget on the page)
// and $fieldNamePrefix (hidden field name base, e.g. 'signature').
// After rendering, the hidden inputs {prefix}_type and {prefix}_data will contain the signature state.
function wv_render_signature_widget($widgetId, $fieldNamePrefix, $typedLabel = 'Type your full legal name') { ?>
<div class="wv-sig-widget" id="<?= htmlspecialchars($widgetId) ?>">
	<div class="wv-sig-tabs">
		<button type="button" class="wv-sig-tab wv-sig-tab-active" data-mode="draw">Draw</button>
		<button type="button" class="wv-sig-tab" data-mode="type">Type</button>
	</div>
	<div class="wv-sig-pane wv-sig-pane-draw">
		<canvas class="wv-sig-canvas" width="600" height="180"></canvas>
		<div class="wv-sig-actions">
			<button type="button" class="wv-sig-undo">Undo</button>
			<button type="button" class="wv-sig-clear">Clear</button>
		</div>
	</div>
	<div class="wv-sig-pane wv-sig-pane-type" style="display:none;">
		<label class="wv-sig-typed-label"><?= htmlspecialchars($typedLabel) ?></label>
		<input type="text" class="wv-sig-typed" maxlength="128" autocomplete="off">
		<div class="wv-sig-typed-preview" aria-hidden="true"></div>
	</div>
	<input type="hidden" name="<?= htmlspecialchars($fieldNamePrefix) ?>_type" class="wv-sig-type" value="drawn">
	<input type="hidden" name="<?= htmlspecialchars($fieldNamePrefix) ?>_data" class="wv-sig-data" value="">
</div>
<?php } ?>
