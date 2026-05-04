<?php
	// ---- Normalize data ----
	$info     = $EventInfo    ?? [];
	$details  = $EventDetails ?? [];
	$eventId  = (int)($event_id ?? 0);

	$eventName   = htmlspecialchars($details['Name'] ?? $info['Name'] ?? 'Event');
	$hasHeraldry = !empty($details['HasHeraldry']);
	$heraldryUrl = $hasHeraldry
		? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', $eventId))
		: HTTP_EVENT_HERALDRY . '00000.jpg';

	$kingdomId   = (int)($info['KingdomId'] ?? 0);
	$kingdomName = htmlspecialchars($info['KingdomName'] ?? '');
	$parkId      = (int)($info['ParkId'] ?? 0);
	$parkName    = htmlspecialchars($info['ParkName'] ?? '');
	$atParkId    = (int)($AtParkId ?? 0);
	$atParkName  = htmlspecialchars($AtParkName ?? '');
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<div class="ec-wrap">

	<?php // ---- Context banner ---- ?>
	<div class="ec-banner" id="ec-banner">
		<?php if ($heraldryUrl): ?>
		<div class="ec-banner-bg"
			style="background-image:url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
		<?php endif; ?>

		<div class="ec-banner-heraldry-col">
			<div class="ec-banner-heraldry">
				<img id="ec-heraldry-img"
					src="<?= htmlspecialchars($heraldryUrl) ?>"
					onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
					alt="<?= $eventName ?> heraldry"
					crossorigin="anonymous">
			</div>
			<?php if ($eventId > 0): ?>
			<a href="#" class="ec-add-image-link" onclick="event.preventDefault(); evOpenImgModal();">
				<i class="fas fa-camera" style="margin-right:4px"></i>Add Image
			</a>
			<?php else: ?>
			<span class="ec-add-image-link ec-add-image-link-disabled" title="Save the event first to add heraldry">
				<i class="fas fa-camera" style="margin-right:4px"></i>Add Image
			</span>
			<?php endif; ?>
		</div>

		<div class="ec-banner-info">
			<div class="ec-banner-label"><i class="fas fa-plus-circle" style="margin-right:4px"></i>New Event</div>
			<h1 class="ec-banner-name"><?= $eventName ?></h1>
			<div class="ec-banner-crumb">
				<?= $eventName ?>
				<?php if ($kingdomId): ?>
					<span>›</span>
					<a href="<?= UIR ?>Kingdom/profile/<?= $kingdomId ?>"><?= $kingdomName ?></a>
				<?php endif; ?>
				<?php if ($parkId): ?>
					<span>›</span>
					<a href="<?= UIR ?>Park/profile/<?= $parkId ?>"><?= $parkName ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php // ---- Form card ---- ?>
	<div class="ec-form-card">

		<?php if (!empty($Error)): ?>
		<div class="ec-error">
			<i class="fas fa-exclamation-triangle" style="margin-right:6px"></i><?= htmlspecialchars($Error ?? '') ?>
		</div>
		<?php endif; ?>

		<form method="post" action="<?= UIR ?>Event/create/<?= $eventId ?>" id="ec-form">

			<?php // ---- Section: Dates & Pricing ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-calendar-alt"></i> Dates &amp; Pricing
				</h4>
				<div class="ec-row">
					<div class="ec-field">
						<label>Start Date &amp; Time <span class="ec-required">*</span></label>
						<input type="text" name="StartDate" id="ec-fp-start" autocomplete="off" required<?= !empty($PresetDate) ? ' value="' . htmlspecialchars($PresetDate) . '"' : '' ?>>
					</div>
					<div class="ec-field">
						<label>End Date &amp; Time</label>
						<input type="text" name="EndDate" id="ec-fp-end" autocomplete="off"<?= !empty($PresetEndDate) ? ' value="' . htmlspecialchars($PresetEndDate) . '"' : '' ?>>
					</div>
				</div>
			</div>

			<?php // ---- Section: Admission & Fees ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-ticket-alt"></i> Admission &amp; Fees
				</h4>
				<div id="ec-fees-list" style="margin-bottom:8px"></div>
				<button type="button" onclick="evFeesAdd()" style="background:#ebf8ff;border:1px solid #90cdf4;color:#2b6cb0;border-radius:4px;padding:5px 12px;font-size:13px;cursor:pointer">
					<i class="fas fa-plus"></i> Add Fee
				</button>
				<span class="ec-field-hint" style="display:block;margin-top:6px">Leave empty for a free event.</span>
				<input type="hidden" name="Fees" id="ev-fees-json">
			</div>

			<?php // ---- Section: Event Type ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-tag"></i> Event Type
				</h4>
				<div class="ec-row">
					<div class="ec-field ec-full">
						<label>Event Type</label>
						<select name="EventType">
							<option value="">-- None --</option>
					<option value="Coronation">Coronation</option>
					<option value="Midreign">Midreign</option>
					<option value="Endreign">Endreign</option>
					<option value="Crown Qualifications">Crown Qualifications</option>
					<option value="Meeting">Meeting</option>
					<option value="Althing">Althing</option>
					<option value="Interkingdom Event">Interkingdom Event</option>
					<option value="Weaponmaster">Weaponmaster</option>
					<option value="Warmaster">Warmaster</option>
					<option value="Dragonmaster">Dragonmaster</option>
					<option value="Other">Other</option>
						</select>
						<span class="ec-field-hint">Optional. Categorize this event for display in the hero header.</span>
					</div>
				</div>
			</div>

			<?php // ---- Section: Description ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-align-left"></i> Description
				</h4>
				<div class="ec-row">
					<div class="ec-field ec-full">
						<label style="display:flex;align-items:center;gap:6px;">
							Event Description <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
							<button type="button" class="kn-md-help-btn" onclick="document.getElementById('ec-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
						</label>
						<textarea name="Description" rows="6" placeholder="Describe the event — activities, schedule, what to bring, camping info, etc."></textarea>
					</div>
				</div>
			</div>

			<?php // ---- Section: Held At ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-map-marker-alt"></i> Held At
					<span class="ec-held-at-optional">(optional)</span>
				</h4>
				<div class="ec-row">
					<div class="ec-field ec-full">
						<label>Park or Kingdom</label>
						<div class="ec-ac-wrap">
							<input type="text" id="ec-heldat-text"
								autocomplete="off"
								placeholder="Search parks and kingdoms..."
								value="<?= $atParkName ?>">
							<div class="ec-ac-results" id="ec-ac-results" style="display:none"></div>
						</div>
						<input type="hidden" name="AtParkId" id="ec-heldat-parkid" value="<?= $atParkId ?: '' ?>">
						<span class="ec-field-hint">Where this occurrence takes place. Leave blank if hosted at the kingdom level.</span>
					</div>
				</div>
			</div>

			<?php // ---- Section: Location ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-map-marker-alt"></i> Location
				</h4>
				<div class="ec-row">
					<div class="ec-field ec-full">
						<label>Street Address</label>
						<input type="text" name="Address" placeholder="123 Main St">
					</div>
				</div>
				<div class="ec-row">
					<div class="ec-field">
						<label>City</label>
						<input type="text" name="City" placeholder="Anytown">
					</div>
					<div class="ec-field ec-md">
						<label>Province / State</label>
						<input type="text" name="Province" placeholder="TX">
					</div>
					<div class="ec-field ec-sm">
						<label>Postal Code</label>
						<input type="text" name="PostalCode" placeholder="78201">
					</div>
					<div class="ec-field ec-md">
						<label>Country</label>
						<input type="text" name="Country" value="United States">
					</div>
				</div>
			</div>

			<?php // ---- Section: External Links ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-link"></i> External Links
				</h4>
				<div id="ec-links-list" style="margin-bottom:8px"></div>
				<button type="button" onclick="evLinksAdd()" style="background:#ebf8ff;border:1px solid #90cdf4;color:#2b6cb0;border-radius:4px;padding:5px 12px;font-size:13px;cursor:pointer">
					<i class="fas fa-plus"></i> Add Link
				</button>
				<span class="ec-field-hint" style="display:block;margin-top:6px">Add links to registration, social media, or other resources.</span>
				<input type="hidden" name="ExternalLinks" id="ev-links-json">
			</div>

			<?php // ---- Action bar ---- ?>
			<div class="ec-action-bar">
				<div class="ec-action-bar-left">
					<i class="fas fa-info-circle" style="margin-right:4px"></i>
					You can edit any of these details after creation.
				</div>
				<div class="ec-action-bar-right">
					<a class="ec-btn-cancel" href="<?= htmlspecialchars($_ec_return ?: UIR) ?>" id="ec-cancel-btn" onclick="ecCancelAndReturn(event, <?= $eventId ?>)">
						Cancel
					</a>
					<button type="submit" class="ec-btn-submit" id="ec-submit-btn">
						<i class="fas fa-calendar-plus"></i> Create Event
					</button>
				</div>
			</div>

		</form>
	</div><!-- /.ec-form-card -->

</div><!-- /.ec-wrap -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<style>
.ev-fp-title { background: #2b6cb0; color: #fff; font-size: 12px; font-weight: 700; padding: 6px 12px; text-align: center; letter-spacing: .04em; }
html[data-theme="dark"] .ev-fp-title { background: #1a365d; color: #90cdf4; }
</style>
<script>
function ecFpAddTitle(label, calEl) {
	var title = document.createElement('div');
	title.className = 'ev-fp-title';
	title.textContent = label;
	calEl.insertBefore(title, calEl.firstChild);
}
var _ecFpOpts = {
	enableTime: true,
	dateFormat: 'Y-m-d\\TH:i',
	altInput: true,
	altFormat: 'F j, Y  h:i K',
	minuteIncrement: 10,
	time_24hr: false
};
var _ecFpEnd = flatpickr('#ec-fp-end', Object.assign({}, _ecFpOpts, {
	onReady: function(sel, str, fp) { ecFpAddTitle('End Date & Time', fp.calendarContainer); }
}));
var _ecPrevStartDate = null;
var _ecFpStart = flatpickr('#ec-fp-start', Object.assign({}, _ecFpOpts, {
	onReady: function(sel, str, fp) {
		ecFpAddTitle('Start Date & Time', fp.calendarContainer);
		_ecPrevStartDate = sel[0] || null;
	},
	onChange: function(sel) {
		if (!sel[0]) return;
		var endDates = _ecFpEnd.selectedDates;
		if (endDates[0] && _ecPrevStartDate) {
			var offset = endDates[0].getTime() - _ecPrevStartDate.getTime();
			_ecFpEnd.setDate(new Date(sel[0].getTime() + offset), true);
		} else if (!endDates[0]) {
			_ecFpEnd.setDate(new Date(sel[0].getTime() + 60 * 60 * 1000), true);
		}
		_ecPrevStartDate = sel[0];
	}
}));
</script>

<script>
<?php
$_ec_return = $parkId    ? UIR . 'Park/profile/'    . $parkId
				: ($kingdomId ? UIR . 'Kingdom/profile/' . $kingdomId
				: UIR);
?>
var EcConfig = {
	httpService: '<?= HTTP_SERVICE ?>',
	cancelUrl:   '<?= UIR ?>EventAjax/cancel',
	returnUrl:   '<?= htmlspecialchars($_ec_return) ?>',
	hasFees:     true,
	fees:        [],
	feesListId:  'ec-fees-list',
	hasLinks:    true,
	links:       [],
	linksListId: 'ec-links-list',
};
function ecCancelAndReturn(ev, eventId) {
	ev.preventDefault();
	var btn = document.getElementById('ec-cancel-btn');
	if (btn) { btn.textContent = 'Cancelling…'; btn.style.pointerEvents = 'none'; }
	var fd = new FormData();
	fd.append('EventId', eventId);
	fetch(EcConfig.cancelUrl, { method: 'POST', body: fd })
		.catch(function () {})
		.finally(function () { window.location.href = EcConfig.returnUrl; });
}
</script>
<!-- Markdown Help Modal -->
<div id="ec-md-help-overlay" onclick="if(event.target===this)this.classList.remove('kn-open')">
	<div class="kn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-hashtag" style="margin-right:8px;color:#2b6cb0"></i>Markdown Reference</h3>
			<button class="kn-modal-close-btn" onclick="document.getElementById('ec-md-help-overlay').classList.remove('kn-open')">&times;</button>
		</div>
		<div class="kn-modal-body" style="padding:16px 20px">
			<table class="kn-md-help-table">
				<thead><tr><th>You type</th><th>Result</th></tr></thead>
				<tbody>
					<tr><td><code>**bold**</code></td><td><strong>bold</strong></td></tr>
					<tr><td><code>*italic*</code></td><td><em>italic</em></td></tr>
					<tr><td><code>~~strikethrough~~</code></td><td><s>strikethrough</s></td></tr>
					<tr><td><code>[link](https://...)</code></td><td><a href="#">link</a></td></tr>
					<tr><td><code>`inline code`</code></td><td><code>inline code</code></td></tr>
					<tr><td><code>- item</code></td><td>• Bullet list</td></tr>
					<tr><td><code>1. item</code></td><td>1. Numbered list</td></tr>
					<tr><td><code># Heading</code></td><td><strong>Large heading</strong></td></tr>
					<tr><td><code>## Heading</code></td><td><strong>Smaller heading</strong></td></tr>
					<tr><td><code>&gt; quote</code></td><td><em>Blockquote</em></td></tr>
					<tr><td>Blank line</td><td>New paragraph</td></tr>
					<tr><td>Single newline</td><td>Line break</td></tr>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php // ---- Event Heraldry Upload Modal (ported from Eventnew_index) ---- ?>
<?php if ($eventId > 0): ?>
<style>
.ec-banner-heraldry-col {
	display: flex; flex-direction: column; align-items: center;
	flex-shrink: 0; gap: 6px;
}
.ec-add-image-link {
	display: inline-flex; align-items: center;
	font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.8);
	text-decoration: none; cursor: pointer; white-space: nowrap;
	transition: color .15s;
}
.ec-add-image-link:hover { color: #fff; text-decoration: underline; }
.ec-add-image-link-disabled { color: rgba(255,255,255,0.4); cursor: not-allowed; }
.ec-add-image-link-disabled:hover { color: rgba(255,255,255,0.4); text-decoration: none; }

.ev-img-overlay {
	display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55);
	z-index: 1500; align-items: center; justify-content: center;
}
.ev-img-overlay.ev-open { display: flex; }
.ev-img-modal {
	background: #fff; border-radius: 10px; width: min(520px, 96vw);
	box-shadow: 0 8px 32px rgba(0,0,0,.22); overflow: hidden;
}
.ev-img-modal-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 14px 18px; border-bottom: 1px solid #e2e8f0; background: #f7fafc;
}
.ev-img-modal-title { font-size: 15px; font-weight: 700; color: #2d3748; margin: 0; }
.ev-img-close-btn { background: none; border: none; font-size: 20px; color: #718096; cursor: pointer; padding: 0 4px; }
.ev-img-modal-body { padding: 20px 22px; }
.ev-upload-area {
	display: flex; flex-direction: column; align-items: center; gap: 8px;
	border: 2px dashed #cbd5e0; border-radius: 8px; padding: 28px 20px;
	cursor: pointer; color: #4a5568; font-size: 14px; text-align: center;
	transition: border-color .15s, background .15s;
}
.ev-upload-area:hover { border-color: #4299e1; background: #ebf8ff; }
.ev-upload-icon { font-size: 32px; color: #a0aec0; }
.ev-upload-area small { font-size: 12px; color: #a0aec0; }
.ev-img-step-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }
.ev-crop-wrap { overflow: auto; max-height: 360px; display: flex; justify-content: center; }
.ev-img-form-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 8px 12px; border-radius: 5px; font-size: 13px; margin-top: 8px; }
.ev-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
.ev-btn-outline { background: #fff; border-color: #cbd5e0; color: #4a5568; }
.ev-btn-outline:hover { background: #f7fafc; }
.ev-btn-white { background: #4299e1; color: #fff; }
.ev-btn-white:hover { background: #2c5282; }
</style>

<!-- EvConfig stub for revised.js (image modal needs uir + eventId + canManage) -->
<script>
var EvConfig = {
	uir:                '<?= UIR ?>',
	httpService:        '<?= HTTP_SERVICE ?>',
	canManage:          true,
	canManageSchedule:  false,
	canManageFeast:     false,
	canManageStaff:     false,
	canManageAttendance:false,
	kingdomId:          <?= (int)$kingdomId ?>,
	eventId:            <?= (int)$eventId ?>,
	detailId:           0,
	eventStart:         '',
	eventEnd:           '',
	staffList:          [],
	hasFees:            false,
	fees:               [],
	hasLinks:           false,
	links:              [],
	linksListId:        ''
};
</script>

<!-- Event Heraldry Upload Modal -->
<div class="ev-img-overlay" id="ev-img-overlay">
	<div class="ev-img-modal">
		<div class="ev-img-modal-header">
			<span class="ev-img-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#2c5282"></i>Update Event Heraldry</span>
			<button class="ev-img-close-btn" id="ev-img-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-select">
			<label class="ev-upload-area" for="ev-img-file-input">
				<i class="fas fa-cloud-upload-alt ev-upload-icon"></i>
				Click to choose an image
				<small>JPG, GIF, PNG &middot; Max 340&nbsp;KB (larger images auto-resized)</small>
			</label>
			<input type="file" id="ev-img-file-input" accept=".jpg,.jpeg,.gif,.png,image/jpeg,image/gif,image/png" style="display:none;" />
			<div id="ev-img-resize-notice" style="font-size:12px;color:#888;min-height:16px;margin-top:6px;"></div>
			<div class="ev-img-form-error" id="ev-img-error" style="display:none;"></div>
			<div style="text-align:center;margin-top:10px">
				<button class="ev-btn ev-btn-outline" id="ev-img-remove-btn" type="button" style="font-size:12px;padding:4px 14px;border-color:#feb2b2;color:#e53e3e;"><i class="fas fa-trash"></i> Remove Heraldry</button>
			</div>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-crop" style="display:none;">
			<p style="margin:0 0 10px;font-size:13px;color:#718096;">Drag inside the crop box to reposition it, or drag the corner handles to resize.</p>
			<div class="ev-crop-wrap"><canvas id="ev-img-canvas"></canvas></div>
			<div class="ev-img-step-actions">
				<button class="ev-btn ev-btn-outline" id="ev-img-back-btn"><i class="fas fa-arrow-left"></i> Choose Different</button>
				<button class="ev-btn ev-btn-white" id="ev-img-upload-btn"><i class="fas fa-upload"></i> Upload</button>
			</div>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-uploading" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:#4299e1;"></i>
			<p style="margin-top:12px;color:#718096;">Uploading&hellip;</p>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-success" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78;"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600;">Updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>
<?php else: ?>
<style>
.ec-banner-heraldry-col {
	display: flex; flex-direction: column; align-items: center;
	flex-shrink: 0; gap: 6px;
}
.ec-add-image-link {
	display: inline-flex; align-items: center;
	font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.4);
	cursor: not-allowed; white-space: nowrap;
}
</style>
<?php endif; ?>

<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>
